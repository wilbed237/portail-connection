<?php
/*
 * Webhook NotchPay (facultatif mais recommandé).
 * À déclarer sur business.notchpay.co > Paramètres > Webhooks avec l'adresse :
 *   https://VOTRE-DOMAINE.com/paiement/webhook.php
 * et la clé de signature (hsk_...) copiée dans config.php > notchpay_hash_key.
 *
 * Il sert à attribuer le ticket même si le client a fermé la page avant la fin.
 * Par sécurité, on ne croit pas le contenu du webhook : on revérifie le
 * paiement directement auprès de NotchPay (order_refresh).
 */

declare(strict_types=1);
require __DIR__ . '/lib.php';

$raw = (string)file_get_contents('php://input');
$hashKey = cfg()['notchpay_hash_key'];
if ($hashKey === '') { http_response_code(503); exit('webhook non configure'); }

$sig = $_SERVER['HTTP_X_NOTCH_SIGNATURE'] ?? '';
if (!hash_equals(hash_hmac('sha256', $raw, $hashKey), $sig)) { http_response_code(403); exit('signature invalide'); }

$event = json_decode($raw, true);
$data = is_array($event['data'] ?? null) ? $event['data'] : [];
// Notre référence est envoyée comme « reference » à l'initialisation ; NotchPay la renvoie souvent en « trxref ».
$candidates = array_filter([(string)($data['trxref'] ?? ''), (string)($data['merchant_reference'] ?? ''), (string)($data['reference'] ?? '')]);

with_lock(function () use ($candidates) {
    $orders = orders_load();
    foreach ($candidates as $c) {
        $ref = null;
        if (isset($orders[$c])) { $ref = $c; }
        else { foreach ($orders as $k => $o) { if (($o['np_reference'] ?? '') === $c) { $ref = $k; break; } } }
        if ($ref !== null) { order_refresh($orders, $ref); orders_save($orders); return; }
    }
});

http_response_code(200);
echo 'ok';
