<?php
/*
 * Fonctions communes : stockage des commandes, stock de tickets, appels NotchPay.
 * Rien à modifier ici : tout se règle dans config.php.
 */

declare(strict_types=1);

const NOTCHPAY_API = 'https://api.notchpay.co';
date_default_timezone_set('Africa/Douala');

function cfg(): array {
    static $c = null;
    if ($c === null) { $c = require __DIR__ . '/config.php'; }
    return $c;
}

function data_dir(): string {
    $d = __DIR__ . '/data';
    if (!is_dir($d . '/vouchers')) { mkdir($d . '/vouchers', 0750, true); }
    return $d;
}

/* ---------- Verrou global : une seule écriture à la fois ---------- */
function with_lock(callable $fn) {
    $fh = fopen(data_dir() . '/.lock', 'c');
    flock($fh, LOCK_EX);
    try { return $fn(); }
    finally { flock($fh, LOCK_UN); fclose($fh); }
}

/* ---------- Commandes (data/orders.json) ---------- */
function orders_load(): array {
    $f = data_dir() . '/orders.json';
    if (!is_file($f)) { return []; }
    $j = json_decode((string)file_get_contents($f), true);
    return is_array($j) ? $j : [];
}
function orders_save(array $orders): void {
    $f = data_dir() . '/orders.json';
    file_put_contents($f . '.tmp', json_encode($orders, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    rename($f . '.tmp', $f);
}

/* ---------- Stock de tickets (data/vouchers/<plan>.txt, une ligne « identifiant;motdepasse ») ---------- */
function voucher_take(string $planId, string $reference): ?array {
    $file = data_dir() . '/vouchers/' . $planId . '.txt';
    if (!is_file($file)) { return null; }
    $lines = file($file, FILE_IGNORE_NEW_LINES);
    $taken = null; $rest = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') { continue; }
        if ($taken === null && strpos($line, ';') !== false) {
            [$u, $p] = array_map('trim', explode(';', $line, 2));
            $taken = ['username' => $u, 'password' => $p];
            continue;
        }
        $rest[] = $line;
    }
    if ($taken === null) { return null; }
    file_put_contents($file, $rest ? implode("\n", $rest) . "\n" : '');
    file_put_contents(data_dir() . '/vouchers/vendus.log',
        date('c') . ";$planId;$reference;{$taken['username']}\n", FILE_APPEND);
    return $taken;
}
function voucher_count(string $planId): int {
    $file = data_dir() . '/vouchers/' . $planId . '.txt';
    if (!is_file($file)) { return 0; }
    $n = 0;
    foreach (file($file, FILE_IGNORE_NEW_LINES) as $l) { $l = trim($l); if ($l !== '' && $l[0] !== '#' && strpos($l, ';') !== false) { $n++; } }
    return $n;
}

/* ---------- NotchPay ---------- */
function notchpay(string $method, string $path, ?array $body = null): array {
    $key = cfg()['notchpay_public_key'];
    if ($key === '') { return ['_http' => 0, '_error' => 'Clé API NotchPay absente dans config.php']; }
    $headers = ['Authorization: ' . $key, 'Accept: application/json'];
    $payload = null;
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
        $payload = json_encode($body);
    }
    $err = '';
    if (function_exists('curl_init')) {
        $ch = curl_init(NOTCHPAY_API . $path);
        if ($payload !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, $payload); }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
        ]);
        $raw = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
    } else {
        // Hébergement sans l'extension curl : flux HTTP natifs de PHP.
        $ctx = stream_context_create(['http' => [
            'method' => $method, 'header' => implode("\r\n", $headers),
            'content' => $payload ?? '', 'timeout' => 30, 'ignore_errors' => true,
        ]]);
        $raw = @file_get_contents(NOTCHPAY_API . $path, false, $ctx);
        $http = 0;
        foreach ($http_response_header ?? [] as $h) { if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) { $http = (int)$m[1]; } }
        if ($raw === false) { $err = 'Connexion impossible à NotchPay'; }
    }
    $j = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($j)) { $j = []; }
    $j['_http'] = $http;
    if ($err) { $j['_error'] = $err; }
    return $j;
}

/* Lit le bloc transaction quelle que soit la forme de la réponse (la doc NotchPay varie). */
function np_tx(array $r): array {
    foreach (['transaction', 'payment', 'data'] as $k) {
        if (isset($r[$k]) && is_array($r[$k])) { return $r[$k]; }
    }
    return [];
}

/*
 * Vérifie une commande auprès de NotchPay et, si le paiement est complet,
 * lui attribue un ticket. À appeler sous with_lock().
 */
function order_refresh(array &$orders, string $ref): void {
    if (!isset($orders[$ref])) { return; }
    $o = &$orders[$ref];
    if (in_array($o['status'], ['complete', 'failed', 'canceled', 'expired'], true)) { return; }

    if ($o['status'] !== 'paid_no_stock') {
        $np = notchpay('GET', '/payments/' . rawurlencode($o['np_reference'] ?: $ref));
        $tx = np_tx($np);
        $st = strtolower((string)($tx['status'] ?? ''));
        $o['checked_at'] = date('c');
        if ($st === 'complete' || $st === 'completed' || $st === 'success') {
            $amount = (int)round((float)($tx['amount'] ?? 0));
            $currency = strtoupper((string)($tx['currency'] ?? 'XAF'));
            if ($amount < $o['amount'] || $currency !== 'XAF') {
                $o['status'] = 'failed';
                $o['note'] = "Montant reçu incohérent ($amount $currency)";
                return;
            }
            $o['status'] = 'paid_no_stock';
            $o['paid_at'] = date('c');
        } elseif (in_array($st, ['failed', 'canceled', 'cancelled', 'expired', 'rejected'], true)) {
            $o['status'] = $st === 'cancelled' ? 'canceled' : ($st === 'rejected' ? 'failed' : $st);
            return;
        } else {
            return; // pending / processing : on attend
        }
    }

    // Paiement confirmé : attribution d'un ticket du stock.
    $v = voucher_take($o['plan'], $ref);
    if ($v) {
        $o['status'] = 'complete';
        $o['username'] = $v['username'];
        $o['password'] = $v['password'];
        $o['delivered_at'] = date('c');
    }
}

function normalize_phone(string $p): ?string {
    $p = preg_replace('/\D/', '', $p);
    if (strlen($p) === 12 && strpos($p, '237') === 0) { $p = substr($p, 3); }
    return preg_match('/^6\d{8}$/', $p) ? $p : null;
}

function json_out(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
