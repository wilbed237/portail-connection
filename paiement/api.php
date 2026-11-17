<?php
/*
 * API appelée par login.html
 *   POST api.php?action=init    {plan, phone, operator}        -> {ok, reference, token}
 *   POST api.php?action=status  {reference, token} ou {reference, phone}
 *                               -> {ok, status, plan, username?, password?}
 * Statuts renvoyés : pending, complete, paid_no_stock, failed, canceled, expired
 */

declare(strict_types=1);
require __DIR__ . '/lib.php';

header('Access-Control-Allow-Origin: ' . cfg()['allowed_origin']);
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { json_out(['ok' => false, 'error' => 'Méthode non autorisée'], 405); }

$in = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($in)) { $in = $_POST; }
$action = $_GET['action'] ?? '';

/* ===================== Lancer un paiement ===================== */
if ($action === 'init') {
    $plans = cfg()['plans'];
    $planId = (string)($in['plan'] ?? '');
    $phone = normalize_phone((string)($in['phone'] ?? ''));
    $operator = (string)($in['operator'] ?? '');

    if (!isset($plans[$planId])) { json_out(['ok' => false, 'error' => 'Forfait inconnu.'], 400); }
    if ($phone === null) { json_out(['ok' => false, 'error' => 'Numéro invalide : 9 chiffres commençant par 6.'], 400); }
    if (!in_array($operator, ['mtn', 'orange'], true)) { json_out(['ok' => false, 'error' => 'Opérateur invalide.'], 400); }
    if (voucher_count($planId) === 0) {
        json_out(['ok' => false, 'error' => 'Ce forfait est momentanément épuisé. Choisissez-en un autre ou contactez un agent.'], 409);
    }

    $plan = $plans[$planId];
    $ref = cfg()['reference_prefix'] . '-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
    $token = bin2hex(random_bytes(16));
    $channel = $operator === 'mtn' ? 'cm.mtn' : 'cm.orange';

    // 1) Création du paiement
    $body = [
        'amount'      => $plan['price'],
        'currency'    => 'XAF',
        'phone'       => '+237' . $phone,
        'reference'   => $ref,
        'description' => 'GANDAL - ' . $plan['label'],
        'locked_channel' => $channel,
        'locked_country' => 'CM',
    ];
    if (cfg()['callback_url'] !== '') { $body['callback'] = cfg()['callback_url']; }
    $init = notchpay('POST', '/payments', $body);
    $tx = np_tx($init);
    $npRef = (string)($tx['reference'] ?? '');
    if ($init['_http'] < 200 || $init['_http'] >= 300 || $npRef === '') {
        error_log('NotchPay init: ' . json_encode($init));
        json_out(['ok' => false, 'error' => 'Le service de paiement est indisponible. Réessayez dans un instant ou commandez sur WhatsApp.'], 502);
    }

    // 2) Débit direct : l'abonné reçoit la demande de validation sur son téléphone
    $charge = notchpay('POST', '/payments/' . rawurlencode($npRef), [
        'channel' => $channel,
        'data'    => ['phone' => '+237' . $phone, 'account_number' => '+237' . $phone],
    ]);
    $redirect = null;
    if ($charge['_http'] < 200 || $charge['_http'] >= 300) {
        // Repli : page de paiement hébergée NotchPay (à autoriser dans le walled-garden)
        error_log('NotchPay charge: ' . json_encode($charge));
        $redirect = $init['authorization_url'] ?? null;
        if (!$redirect) {
            json_out(['ok' => false, 'error' => $charge['message'] ?? 'La demande de paiement n\'a pas pu être envoyée.'], 502);
        }
    }

    with_lock(function () use ($ref, $token, $planId, $plan, $phone, $operator, $npRef) {
        $orders = orders_load();
        $orders[$ref] = [
            'reference' => $ref, 'np_reference' => $npRef, 'token' => $token,
            'plan' => $planId, 'amount' => $plan['price'], 'phone' => $phone, 'operator' => $operator,
            'status' => 'pending', 'created_at' => date('c'),
        ];
        orders_save($orders);
    });

    $out = ['ok' => true, 'reference' => $ref, 'token' => $token, 'status' => 'pending'];
    if ($redirect) { $out['redirect_url'] = $redirect; }
    json_out($out);
}

/* ===================== Suivre un paiement ===================== */
if ($action === 'status') {
    $ref = strtoupper(trim((string)($in['reference'] ?? '')));
    $token = (string)($in['token'] ?? '');
    $phone = normalize_phone((string)($in['phone'] ?? ''));

    $res = with_lock(function () use ($ref, $token, $phone) {
        $orders = orders_load();
        if (!isset($orders[$ref])) { return null; }
        $o = $orders[$ref];
        $authorized = ($token !== '' && hash_equals($o['token'], $token))
                   || ($phone !== null && hash_equals($o['phone'], $phone));
        if (!$authorized) { return null; }
        order_refresh($orders, $ref);
        orders_save($orders);
        return $orders[$ref];
    });

    if ($res === null) { json_out(['ok' => false, 'error' => 'Aucun paiement trouvé pour cette référence et ce numéro.'], 404); }

    $out = ['ok' => true, 'status' => $res['status'], 'plan' => $res['plan']];
    if ($res['status'] === 'complete') {
        $out['username'] = $res['username'];
        $out['password'] = $res['password'];
    }
    json_out($out);
}

json_out(['ok' => false, 'error' => 'Action inconnue.'], 400);
