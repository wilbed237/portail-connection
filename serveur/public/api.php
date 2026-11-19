<?php
/*
 * API appelée par le portail captif (login.html).
 *   POST api.php?action=init    {plan, phone, operator}            -> {ok, reference, token}
 *   POST api.php?action=status  {reference, token} | {reference, phone}
 *                               -> {ok, status, plan, username?, password?}
 * Statuts renvoyés : pending, complete, paid_no_stock, failed, canceled, expired
 */

declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';

header('Access-Control-Allow-Origin: ' . cfg()['allowed_origin']);
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('X-Content-Type-Options: nosniff');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { json_out(['ok' => false, 'error' => 'Méthode non autorisée.'], 405); }

$raw = (string)file_get_contents('php://input', false, null, 0, 4096);
$in = json_decode($raw, true);
if (!is_array($in)) { json_out(['ok' => false, 'error' => 'Requête invalide.'], 400); }
$action = $_GET['action'] ?? '';
$ip = client_ip();

try {
    /* ===================== Lancer un paiement ===================== */
    if ($action === 'init') {
        if (!rate_limit('init:' . $ip, 60, 600)) {
            json_out(['ok' => false, 'error' => 'Trop de demandes. Réessayez dans quelques minutes.'], 429);
        }
        $plans = plans();
        $planId = (string)($in['plan'] ?? '');
        $phone = normalize_phone((string)($in['phone'] ?? ''));
        $operator = (string)($in['operator'] ?? '');
        if (!isset($plans[$planId])) { json_out(['ok' => false, 'error' => 'Forfait inconnu.'], 400); }
        if ($phone === null) { json_out(['ok' => false, 'error' => 'Numéro invalide : 9 chiffres commençant par 6.'], 400); }
        if (!in_array($operator, ['mtn', 'orange'], true)) { json_out(['ok' => false, 'error' => 'Opérateur invalide.'], 400); }

        // On ne vend pas un forfait qu'on ne pourrait pas livrer : routeur injoignable ET stock vide.
        $h = one('SELECT reachable FROM router_health WHERE ts > now() - interval \'15 minutes\' ORDER BY ts DESC LIMIT 1');
        $routerDown = $h !== null && !$h['reachable'];
        $stock = (int)val("SELECT count(*) FROM tickets WHERE source = 'stock' AND order_reference IS NULL AND plan_id = ?", [$planId]);
        if ($routerDown && $stock === 0) {
            json_out(['ok' => false, 'error' => 'Ce forfait est momentanément indisponible. Réessayez plus tard ou contactez un agent.'], 503);
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
                'rate_limited'       => 'Beaucoup de paiements en ce moment. Réessayez dans une minute.',
                'payment_init_failed' => "L'opérateur n'a pas pu envoyer la demande de paiement. Vérifiez le numéro et réessayez.",
                'validation_error'   => 'Numéro ou forfait refusé par le service de paiement. Vérifiez le numéro.',
                default              => 'Le service de paiement ne répond pas. Réessayez dans un instant ou commandez sur WhatsApp.',
            };
            json_out(['ok' => false, 'error' => $msg], 502);
        }

        q('INSERT INTO orders (reference, provider_id, token_hash, plan_id, amount, fee, phone, operator)
           VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
          [$ref, (string)$p['id'], hash('sha256', $token), $planId, $plan['price'],
           isset($p['fee']) ? (int)$p['fee'] : pay_fee($plan['price']), $phone, $operator]);

        json_out(['ok' => true, 'reference' => $ref, 'token' => $token, 'status' => 'pending']);
    }

    /* ===================== Suivre un paiement ===================== */
    if ($action === 'status') {
        $ref = strtoupper(trim((string)($in['reference'] ?? '')));
        $token = (string)($in['token'] ?? '');
        $phone = normalize_phone((string)($in['phone'] ?? ''));
        if (!preg_match('/^[A-Z0-9-]{6,40}$/', $ref)) { json_out(['ok' => false, 'error' => 'Référence invalide.'], 400); }

        $o = one('SELECT reference, token_hash, phone, recover_failures FROM orders WHERE reference = ?', [$ref]);
        $byToken = $o && $token !== '' && hash_equals($o['token_hash'], hash('sha256', $token));

        if (!$byToken) {
            // Recherche par numéro (« Retrouver un ticket ») : protégée contre les essais en série.
            if (!rate_limit('recover:' . $ip, 30, 900)) {
                json_out(['ok' => false, 'error' => 'Trop de recherches. Réessayez dans 15 minutes.'], 429);
            }
            if (!$o || $phone === null || (int)$o['recover_failures'] >= 5 || !hash_equals($o['phone'], $phone)) {
                if ($o) { q('UPDATE orders SET recover_failures = recover_failures + 1 WHERE reference = ?', [$ref]); }
                json_out(['ok' => false, 'error' => 'Aucun paiement trouvé pour cette référence et ce numéro.'], 404);
            }
        }

        $o = order_process($ref);
        json_out(order_public($o));
    }

    json_out(['ok' => false, 'error' => 'Action inconnue.'], 400);
} catch (Throwable $e) {
    logline('api.php : ' . $e->getMessage());
    json_out(['ok' => false, 'error' => 'Erreur du serveur. Réessayez dans un instant.'], 500);
}
