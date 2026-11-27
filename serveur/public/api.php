<?php
/*
 * API appelée par le portail captif (login.html).
 *   POST api.php?action=init    {plan, phone, operator, mac?}      -> {ok, reference, token}
 *   POST api.php?action=status  {reference, token} | {reference, phone}
 *                               -> {ok, status, plan, username?, password?}
 *   POST api.php?action=tickets {mac, portal}                       -> {ok, tickets:[{ref, plan_id, plan, user, pass, date}]}
 * Statuts renvoyés : pending, complete, paid_no_stock, failed, canceled, expired
 */

declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';

header('Access-Control-Allow-Origin: ' . cfg()['allowed_origin']);
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('X-Content-Type-Options: nosniff');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { json_out(['ok' => false, 'error' => 'Cette adresse ne s\'ouvre pas directement.'], 405); }

$raw = (string)file_get_contents('php://input', false, null, 0, 4096);
$in = json_decode($raw, true);
if (!is_array($in)) { json_out(['ok' => false, 'error' => 'La demande est incomplète. Rechargez la page et réessayez.'], 400); }
$action = $_GET['action'] ?? '';
$ip = client_ip();

try {
    /* ===================== Lancer un paiement ===================== */
    if ($action === 'init') {
        if (!rate_limit('init:' . $ip, 60, 600)) {
            json_out(['ok' => false, 'error' => 'Beaucoup d\'achats en même temps. Patientez quelques minutes, puis réessayez.'], 429);
        }
        $plans = plans();
        $planId = (string)($in['plan'] ?? '');
        $phone = normalize_phone((string)($in['phone'] ?? ''));
        $operator = (string)($in['operator'] ?? '');
        if (!isset($plans[$planId])) { json_out(['ok' => false, 'error' => 'Ce forfait n\'existe plus. Rechargez la page et choisissez-en un autre.'], 400); }
        if ($phone === null) { json_out(['ok' => false, 'error' => 'Ce numéro ne semble pas correct : il doit avoir 9 chiffres et commencer par 6, par exemple 677 12 34 56.'], 400); }
        if (!in_array($operator, ['mtn', 'orange'], true)) { json_out(['ok' => false, 'error' => 'Choisissez votre opérateur : MTN MoMo ou Orange Money.'], 400); }
        $mac = normalize_mac((string)($in['mac'] ?? ''));   // facultatif : absent hors du hotspot

        // On ne vend pas un forfait qu'on ne pourrait pas livrer : routeur injoignable ET stock vide.
        $h = one('SELECT reachable FROM router_health WHERE ts > now() - interval \'15 minutes\' ORDER BY ts DESC LIMIT 1');
        $routerDown = $h !== null && !$h['reachable'];
        $stock = (int)val("SELECT count(*) FROM tickets WHERE source = 'stock' AND order_reference IS NULL AND plan_id = ?", [$planId]);
        if ($routerDown && $stock === 0) {
            json_out(['ok' => false, 'error' => 'Ce forfait n\'est pas disponible pour le moment. Réessayez un peu plus tard, ou écrivez-nous sur WhatsApp.'], 503);
        }

        $plan = $plans[$planId];
        // Référence unique, aussi utilisée comme clé d'idempotence (8 à 80 caractères [A-Za-z0-9_-]) :
        // si la requête est rejouée, IZI Spot renvoie le même paiement au lieu d'en créer un second.
        $ref = cfg()['reference_prefix'] . '-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
        $token = bin2hex(random_bytes(16));

        $body = [
            'amount'        => $plan['price'],
            'currency'      => 'XAF',
            'paymentMethod' => $operator === 'mtn' ? 'MTN_MOMO' : 'ORANGE_MONEY',
            'phoneNumber'   => $phone,
            'reference'     => $ref,
            'description'   => mb_substr('GANDAL ' . $plan['label'], 0, 180),
            'metadata'      => ['plan' => $planId],
        ];
        $res = pay_api('POST', '/v1/payments', $body, $ref);
        // Coupure réseau : on renvoie la même requête avec la même clé, sans risque de double débit.
        if ($res['code'] === 0) { $res = pay_api('POST', '/v1/payments', $body, $ref); }

        // 202 : paiement « pending », la demande de validation est partie sur le téléphone du client.
        $p = is_array($res['data']) ? $res['data'] : [];
        if (!in_array($res['code'], [200, 202], true) || empty($p['id'])) {
            logline('création paiement : ' . pay_error($res));
            $msg = match ($p['error'] ?? '') {
                'rate_limited'       => 'Le service de paiement est très sollicité. Réessayez dans une minute.',
                'payment_init_failed' => "Votre opérateur n'a pas pu envoyer la demande de paiement. Vérifiez le numéro et l'opérateur choisi, puis réessayez.",
                'validation_error'   => 'Le numéro n\'a pas été accepté. Vérifiez qu\'il correspond bien à l\'opérateur choisi.',
                default              => 'Le paiement ne répond pas pour le moment. Réessayez dans un instant, ou achetez votre ticket sur WhatsApp.',
            };
            json_out(['ok' => false, 'error' => $msg], 502);
        }

        q('INSERT INTO orders (reference, provider_id, token_hash, plan_id, amount, fee, phone, operator, device_mac)
           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
          [$ref, (string)$p['id'], hash('sha256', $token), $planId, $plan['price'],
           isset($p['fee']) ? (int)$p['fee'] : pay_fee($plan['price']), $phone, $operator, $mac]);

        json_out(['ok' => true, 'reference' => $ref, 'token' => $token, 'status' => 'pending']);
    }

    /* ===================== Suivre un paiement ===================== */
    if ($action === 'status') {
        $ref = strtoupper(trim((string)($in['reference'] ?? '')));
        $token = (string)($in['token'] ?? '');
        $phone = normalize_phone((string)($in['phone'] ?? ''));
        if (!preg_match('/^[A-Z0-9-]{6,40}$/', $ref)) { json_out(['ok' => false, 'error' => 'Cette référence n\'est pas valide. Elle ressemble à GD-261127-A1B2C3.'], 400); }

        $o = one('SELECT reference, token_hash, phone, recover_failures FROM orders WHERE reference = ?', [$ref]);
        $byToken = $o && $token !== '' && hash_equals($o['token_hash'], hash('sha256', $token));

        if (!$byToken) {
            // Recherche par numéro (« Retrouver un ticket ») : protégée contre les essais en série.
            if (!rate_limit('recover:' . $ip, 30, 900)) {
                json_out(['ok' => false, 'error' => 'Trop d\'essais. Patientez 15 minutes avant de réessayer.'], 429);
            }
            if (!$o || $phone === null || (int)$o['recover_failures'] >= 5 || !hash_equals($o['phone'], $phone)) {
                if ($o) { q('UPDATE orders SET recover_failures = recover_failures + 1 WHERE reference = ?', [$ref]); }
                json_out(['ok' => false, 'error' => 'Aucun achat ne correspond à cette référence et à ce numéro. Vérifiez les deux.'], 404);
            }
        }

        $o = order_process($ref);
        json_out(order_public($o));
    }

    /* ===================== Derniers tickets de l'appareil ===================== */
    // Seulement les tickets encore utilisables, achetés il y a moins de 35 jours, sur ce portail :
    // limite ce qu'exposerait une adresse MAC usurpée sur le même réseau.
    if ($action === 'tickets') {
        // Tous les clients d'un portail sortent par la même adresse publique : limite large
        if (!rate_limit('tickets:' . $ip, 600, 600)) {
            json_out(['ok' => false, 'error' => 'Trop de demandes. Patientez quelques minutes.'], 429);
        }
        $mac = normalize_mac((string)($in['mac'] ?? ''));
        $portal = (string)($in['portal'] ?? 'gi');
        if ($mac === null) { json_out(['ok' => false, 'error' => 'Appareil non reconnu.'], 400); }
        if (!in_array($portal, ['gi', 'academie'], true)) { json_out(['ok' => false, 'error' => 'Portail non reconnu.'], 400); }
        $ids = array_keys(portal_plans($portal));
        if (!$ids) { json_out(['ok' => true, 'tickets' => []]); }
        $in_ = implode(',', array_fill(0, count($ids), '?'));
        $rows = all("SELECT o.reference, o.plan_id, o.delivered_at, t.username, t.password
                     FROM orders o JOIN tickets t ON t.username = o.ticket_username
                     WHERE o.device_mac = ? AND o.status = 'complete' AND o.plan_id IN ($in_)
                       AND o.delivered_at > now() - interval '35 days'
                       AND t.ended_at IS NULL AND COALESCE(t.state, '') <> 'used'
                       AND (t.expires_at IS NULL OR t.expires_at > now())
                     ORDER BY o.delivered_at DESC LIMIT 3", array_merge([$mac], $ids));
        $plans = plans();
        json_out(['ok' => true, 'tickets' => array_map(fn($r) => [
            'ref' => $r['reference'], 'plan_id' => $r['plan_id'], 'plan' => $plans[$r['plan_id']]['label'] ?? $r['plan_id'],
            'user' => $r['username'], 'pass' => $r['password'], 'date' => date('c', strtotime($r['delivered_at'])),
        ], $rows)]);
    }

    json_out(['ok' => false, 'error' => 'Demande non reconnue.'], 400);
} catch (Throwable $e) {
    logline('api.php : ' . $e->getMessage());
    json_out(['ok' => false, 'error' => 'Un problème est survenu de notre côté. Réessayez dans un instant.'], 500);
}
