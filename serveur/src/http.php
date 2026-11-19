<?php
/* Appels HTTP sortants : API de paiement IZI Spot et API REST du routeur MikroTik. */

declare(strict_types=1);

/**
 * Requête HTTP JSON. Renvoie ['code' => int, 'data' => array|null, 'error' => string]
 * code = 0 quand la connexion elle-même a échoué (hôte injoignable, délai, TLS refusé).
 */
function http_json(string $method, string $url, array $headers, ?array $body, array $opts = []): array {
    $ch = curl_init($url);
    $h = array_merge(['Accept: application/json'], $headers);
    if ($body !== null) {
        $h[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
    }
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $h,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => $opts['timeout'] ?? 20,
        CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS | CURLPROTO_HTTP,
    ]);
    if (!empty($opts['basic'])) { curl_setopt($ch, CURLOPT_USERPWD, $opts['basic']); }
    if (!empty($opts['pin'])) {
        // Certificat auto-signé du routeur : on n'accepte que SA clé publique (épinglage).
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_PINNEDPUBLICKEY, $opts['pin']);
    }
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = $raw === false ? curl_error($ch) : '';
    curl_close($ch);
    $data = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
    if ($raw === false) { $code = 0; }
    return ['code' => $code, 'data' => is_array($data) ? $data : null, 'error' => $err];
}

/* ---------------- API de paiement IZI Spot ---------------- */

/*
 * Chaque appel porte les deux clés du marchand (la clé publique seule est refusée).
 * $idempotencyKey : obligatoire pour POST /v1/payments ; même clé + même corps = même paiement.
 */
function pay_api(string $method, string $path, ?array $body = null, ?string $idempotencyKey = null): array {
    $c = cfg();
    if ($c['pay_secret_key'] === '' || $c['pay_public_key'] === '') {
        return ['code' => 0, 'data' => null, 'error' => 'PAYMENT_SECRET_KEY ou PAYMENT_PUBLISHABLE_KEY manquante'];
    }
    $h = ['Authorization: Bearer ' . $c['pay_secret_key'], 'X-Publishable-Key: ' . $c['pay_public_key']];
    if ($idempotencyKey !== null) { $h[] = 'Idempotency-Key: ' . $idempotencyKey; }
    return http_json($method, $c['pay_api'] . $path, $h, $body, ['timeout' => 30]);
}

/* Frais IZI Spot : ceil(montant × 7 / 100), figés à la création (utilisé si la réponse ne les donne pas). */
function pay_fee(int $amount): int { return (int)ceil($amount * 7 / 100); }

/* Message d'erreur lisible depuis la réponse { "error": "code", "message": "texte" } */
function pay_error(array $r): string {
    if ($r['code'] === 0) { return 'API de paiement injoignable : ' . $r['error']; }
    return 'HTTP ' . $r['code'] . ' ' . ($r['data']['error'] ?? '') . ' ' . ($r['data']['message'] ?? '');
}

/* ---------------- Routeur MikroTik (RouterOS 7, API REST) ---------------- */

function ros(string $method, string $path, ?array $body = null): array {
    $c = cfg();
    if ($c['router_password'] === '') { return ['code' => 0, 'data' => null, 'error' => 'ROUTER_PASSWORD manquant']; }
    return http_json($method, $c['router_url'] . '/rest' . $path, [], $body, [
        'basic'   => $c['router_user'] . ':' . $c['router_password'],
        'pin'     => $c['router_tls_pin'],
        'timeout' => $c['router_timeout'],
    ]);
}

function ros_ok(array $r): bool { return $r['code'] >= 200 && $r['code'] < 300; }

function ros_error(array $r): string {
    if ($r['code'] === 0) { return 'routeur injoignable : ' . $r['error']; }
    $d = $r['data'] ?? [];
    return 'HTTP ' . $r['code'] . ' ' . trim(($d['message'] ?? '') . ' ' . ($d['detail'] ?? ''));
}

/*
 * Crée un ticket dans User Manager : l'utilisateur, puis son forfait (profil).
 * Rejouable sans risque : si l'utilisateur ou l'affectation existent déjà, on continue.
 * Renvoie '' en cas de succès, sinon le message d'erreur.
 */
function um_create_ticket(string $username, string $password, string $planId): string {
    $r = ros('PUT', '/user-manager/user', [
        'name' => $username, 'password' => $password, 'group' => 'default', 'shared-users' => '1',
    ]);
    if (!ros_ok($r)) {
        $exists = ros('GET', '/user-manager/user?name=' . rawurlencode($username));
        if (!ros_ok($exists) || empty($exists['data'])) { return ros_error($r); }
    }
    $has = ros('GET', '/user-manager/user-profile?user=' . rawurlencode($username));
    if (!ros_ok($has)) { return ros_error($has); }
    if (empty($has['data'])) {
        $p = ros('PUT', '/user-manager/user-profile', ['user' => $username, 'profile' => um_profile($planId)]);
        if (!ros_ok($p)) { return ros_error($p); }
    }
    return '';
}
