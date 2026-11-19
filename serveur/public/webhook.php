<?php
/*
 * Webhook IZI Spot (l'URL est enregistrée par IZI Spot : https://VOTRE-DOMAINE/webhook.php).
 *
 * Signature : en-tête « xkey » = HMAC-SHA256 hexadécimal de « x-timestamp + "." + corps brut »,
 * avec WEBHOOK_SIGNING_SECRET. Un message de plus de 5 minutes est refusé (rejeu).
 * Le contenu n'est pas cru sur parole : le paiement est relu auprès d'IZI Spot (order_process).
 * On répond vite (2xx) : le ticket est créé ensuite par le portail ou par le worker (≤ 15 s).
 * Événements : payment.succeeded, payment.failed, payment.expired, payout.succeeded, payout.failed.
 */

declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';

const MAX_SKEW_SECONDS = 300;

$raw = (string)file_get_contents('php://input', false, null, 0, 65536);
$secret = cfg()['webhook_secret'];
if ($secret === '') { http_response_code(503); exit('webhook non configuré'); }

$ts = (string)($_SERVER['HTTP_X_TIMESTAMP'] ?? '');
$sig = (string)($_SERVER['HTTP_XKEY'] ?? '');
if (!ctype_digit($ts) || abs(time() - (int)$ts) > MAX_SKEW_SECONDS) { http_response_code(401); exit('horodatage invalide'); }
if (!hash_equals(hash_hmac('sha256', $ts . '.' . $raw, $secret), $sig)) { http_response_code(401); exit('signature invalide'); }

$event = json_decode($raw, true);
$type = (string)($event['type'] ?? '');
$data = is_array($event['data'] ?? null) ? $event['data'] : [];

try {
    if (str_starts_with($type, 'payment.')) {
        $ref = val('SELECT reference FROM orders WHERE reference = ? OR provider_id = ? LIMIT 1',
                   [(string)($data['reference'] ?? ''), (string)($data['id'] ?? '')]);
        if ($ref) { order_process((string)$ref, true, false); }
    } elseif (str_starts_with($type, 'payout.')) {
        // Retraits faits depuis IZI Spot : simple trace pour la comptabilité.
        audit('izispot', $type, json_encode(['id' => $data['id'] ?? null, 'amount' => $data['amount'] ?? null, 'status' => $data['status'] ?? null]));
    }
} catch (Throwable $e) {
    logline('webhook.php : ' . $e->getMessage());
    http_response_code(500); exit('erreur');   // IZI Spot réessaiera (30 s, 2 min, 10 min…)
}
http_response_code(200);
echo 'ok';
