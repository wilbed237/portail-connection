<?php
/*
 * Cycle de vie d'une commande :
 *   pending ──(IZI Spot confirme)──> paid ──(ticket créé sur le routeur)──> complete
 *                                     │
 *                                     ├─ routeur en panne : ticket du stock de secours ──> complete
 *                                     └─ ni routeur ni stock : nouvelles tentatives, puis paid_no_ticket (alerte)
 *
 * Toutes les fonctions qui modifient une commande s'exécutent dans une transaction,
 * la ligne de la commande verrouillée (SELECT … FOR UPDATE). Le client qui interroge l'état,
 * le webhook IZI Spot et le worker peuvent donc arriver en même temps sans créer deux tickets.
 */

declare(strict_types=1);

const MAX_FAST_ATTEMPTS = 20;   // au-delà (~10 min), la commande passe en paid_no_ticket

/* Vérifie, et si possible termine, une commande. Renvoie la commande à jour. */
/* $provision = false : on confirme seulement le paiement (webhook, qui doit répondre vite) ;
   le ticket est alors créé par le portail qui interroge l'état, ou par le worker dans les 15 s. */
function order_process(string $ref, bool $force = false, bool $provision = true): ?array {
    return tx(function () use ($ref, $force, $provision) {
        $o = one('SELECT * FROM orders WHERE reference = ? FOR UPDATE', [$ref]);
        if (!$o) { return null; }
        if ($o['status'] === 'pending') { payment_check($o, $force); }
        if ($provision && ($o['status'] === 'paid' || $o['status'] === 'paid_no_ticket')) { provision($o, $force); }
        return $o;
    });
}

/*
 * Demande l'état du paiement à IZI Spot : GET /v1/payments/{id}, qui interroge lui-même PayUnit
 * tant que le paiement est « pending ». On ne croit jamais le navigateur ni le webhook sur parole.
 * Statuts IZI Spot : pending, succeeded, failed, expired.
 */
function payment_check(array &$o, bool $force): void {
    if (!$o['provider_id']) { return; }
    if (!$force && $o['checked_at'] && strtotime($o['checked_at']) > time() - 4) { return; }
    // IZI Spot limite les lectures à 120 par minute pour tout le compte : on se garde une marge.
    if (!rate_limit('pay_api_read', 100, 60)) { return; }

    $r = pay_api('GET', '/v1/payments/' . rawurlencode($o['provider_id']));
    q('UPDATE orders SET checked_at = now() WHERE reference = ?', [$o['reference']]);
    $o['checked_at'] = date('c');
    if ($r['code'] !== 200 || !is_array($r['data'])) {
        logline('lecture paiement ' . $o['reference'] . ' : ' . pay_error($r));
        return;   // on réessaiera (429, panne réseau…)
    }
    $p = $r['data'];
    $st = (string)($p['status'] ?? '');

    if ($st === 'succeeded') {
        $amount = (int)($p['amount'] ?? 0);
        $currency = (string)($p['currency'] ?? '');
        if ($amount !== (int)$o['amount'] || $currency !== 'XAF' || ($p['reference'] ?? '') !== $o['reference']) {
            set_status($o, 'failed', "Paiement incohérent : $amount $currency, référence " . ($p['reference'] ?? '?'));
            return;
        }
        q("UPDATE orders SET status = 'paid', paid_at = COALESCE(?::timestamptz, now()), fee = COALESCE(?, fee) WHERE reference = ?",
          [$p['paidAt'] ?? null, isset($p['fee']) ? (int)$p['fee'] : null, $o['reference']]);
        $o['status'] = 'paid';
        $o['paid_at'] = date('c');
        return;
    }
    if ($st === 'failed') { set_status($o, 'failed'); return; }
    if ($st === 'expired') { set_status($o, 'expired'); }
}

function set_status(array &$o, string $status, ?string $note = null): void {
    q('UPDATE orders SET status = ?, note = COALESCE(?, note) WHERE reference = ?', [$status, $note, $o['reference']]);
    $o['status'] = $status;
}

/* Crée le ticket du client sur le routeur, ou à défaut le prend dans le stock de secours. */
function provision(array &$o, bool $force): void {
    $ref = $o['reference'];

    // Ticket déjà attribué (traitement précédent interrompu) : on termine simplement.
    $t = one('SELECT username, source FROM tickets WHERE order_reference = ?', [$ref]);
    if ($t) { complete($o, $t['username'], $t['source']); return; }

    if (!$force && $o['provision_next_at'] && strtotime($o['provision_next_at']) > time()) { return; }

    // Identifiants choisis AVANT l'appel au routeur et enregistrés : une nouvelle tentative
    // réutilise les mêmes, ce qui évite les doublons si la réponse du routeur s'est perdue.
    if (!$o['pending_username']) {
        [$u, $p] = new_credentials();
        q('UPDATE orders SET pending_username = ?, pending_password = ? WHERE reference = ?', [$u, $p, $ref]);
        $o['pending_username'] = $u; $o['pending_password'] = $p;
    }

    $err = create_ticket($o['pending_username'], $o['pending_password'], $o['plan_id']);   // User Manager ou hotspot2 selon le portail
    if ($err === '') {
        q("INSERT INTO tickets (username, password, plan_id, source, order_reference, assigned_at)
           VALUES (?, ?, ?, 'router', ?, now())",
          [$o['pending_username'], $o['pending_password'], $o['plan_id'], $ref]);
        complete($o, $o['pending_username'], 'router');
        return;
    }

    // Le routeur n'a pas pu créer le ticket : on prend un ticket du stock de secours.
    $s = one("SELECT username FROM tickets
              WHERE source = 'stock' AND order_reference IS NULL AND plan_id = ?
              ORDER BY created_at LIMIT 1 FOR UPDATE SKIP LOCKED", [$o['plan_id']]);
    if ($s) {
        q('UPDATE tickets SET order_reference = ?, assigned_at = now() WHERE username = ?', [$ref, $s['username']]);
        q('UPDATE orders SET provision_error = ? WHERE reference = ?', [$err, $ref]);
        complete($o, $s['username'], 'stock');
        return;
    }

    // Ni routeur ni stock : nouvelle tentative plus tard (le worker s'en charge).
    $attempts = (int)$o['provision_attempts'] + 1;
    $delay = $attempts < MAX_FAST_ATTEMPTS ? 30 : 300;
    $status = $attempts < MAX_FAST_ATTEMPTS ? 'paid' : 'paid_no_ticket';
    q("UPDATE orders SET provision_attempts = ?, provision_next_at = now() + make_interval(secs => ?),
              provision_error = ?, status = ? WHERE reference = ?", [$attempts, $delay, $err, $status, $ref]);
    $o['provision_attempts'] = $attempts; $o['status'] = $status; $o['provision_error'] = $err;
}

function complete(array &$o, string $username, string $source): void {
    q("UPDATE orders SET status = 'complete', ticket_username = ?, ticket_source = ?, delivered_at = COALESCE(delivered_at, now()),
              pending_password = NULL WHERE reference = ?", [$username, $source, $o['reference']]);
    $o['status'] = 'complete'; $o['ticket_username'] = $username; $o['ticket_source'] = $source;
}

/* Réponse publique (portail) : jamais de données internes. */
function order_public(array $o): array {
    $map = ['paid' => 'pending', 'paid_no_ticket' => 'paid_no_stock'];
    $out = ['ok' => true, 'status' => $map[$o['status']] ?? $o['status'], 'plan' => $o['plan_id']];
    if ($o['status'] === 'complete') {
        $t = one('SELECT username, password FROM tickets WHERE username = ?', [$o['ticket_username']]);
        if ($t) { $out['username'] = $t['username']; $out['password'] = $t['password']; }
    }
    return $out;
}
