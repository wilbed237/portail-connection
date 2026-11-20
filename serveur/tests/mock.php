<?php
/*
 * Simulateur pour les tests : API de paiement IZI Spot (/izi/…) et API REST RouterOS 7 (/ros/rest/…).
 * Pilotage : /ctl/pay?ref=…  /ctl/router?down=1|0  /ctl/usage?user=…&bytes=…  /ctl/state
 * Lancement : php -S 0.0.0.0:8080 tests/mock.php
 */

declare(strict_types=1);

$file = sys_get_temp_dir() . '/gandal-mock.json';
$fh = fopen($file . '.lock', 'c'); flock($fh, LOCK_EX);
$S = is_file($file) ? json_decode((string)file_get_contents($file), true) : [];
$S += ['payments' => [], 'idem' => [], 'users' => [], 'profiles' => [], 'down' => false, 'n' => 0, 'kicked' => []];
$save = function () use (&$S, $file) { file_put_contents($file, json_encode($S)); };

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
parse_str((string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY), $query);
$m = $_SERVER['REQUEST_METHOD'];
$body = json_decode((string)file_get_contents('php://input'), true) ?: [];
header('Content-Type: application/json');
function out($code, $data) { http_response_code($code); echo json_encode($data); exit; }

/* ---------- pilotage ---------- */
if ($path === '/ctl/pay') {   // ?ref=<notre référence>&status=succeeded|failed|expired
    foreach ($S['payments'] as $k => $p) {
        if ($p['reference'] === ($query['ref'] ?? '')) {
            $S['payments'][$k]['status'] = $query['status'] ?? 'succeeded';
            $S['payments'][$k]['paidAt'] = $S['payments'][$k]['status'] === 'succeeded' ? gmdate('Y-m-d\TH:i:s.000\Z') : null;
        }
    }
    $save(); out(200, ['ok' => true]);
}
if ($path === '/ctl/router') { $S['down'] = ($query['down'] ?? '0') === '1'; $save(); out(200, ['down' => $S['down']]); }
if ($path === '/ctl/usage') { $S['users'][$query['user']]['extra'] = (int)$query['bytes']; $save(); out(200, ['ok' => true]); }   // consommation ajoutée
if ($path === '/ctl/state') { out(200, $S); }

/* ---------- IZI Spot (d'après INTEGRATION.md) ---------- */
if (str_starts_with($path, '/izi/')) {
    $err = fn($code, $e, $msg = '') => out($code, ['error' => $e, 'message' => $msg]);
    if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Bearer sk_test_gandal' || ($_SERVER['HTTP_X_PUBLISHABLE_KEY'] ?? '') !== 'pk_test_gandal') {
        $err(401, 'invalid_api_key', 'Clés absentes, différentes ou révoquées');
    }
    $r = substr($path, strlen('/izi'));
    if ($m === 'POST' && $r === '/v1/payments') {
        $key = $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '';
        if (!preg_match('/^[A-Za-z0-9_-]{8,80}$/', $key)) { $err(400, 'idempotency_key_required'); }
        $raw = (string)file_get_contents('php://input');
        if (isset($S['idem'][$key])) {
            if ($S['idem'][$key]['body'] !== $raw) { $err(409, 'idempotency_conflict'); }
            out(202, $S['payments'][$S['idem'][$key]['id']]);
        }
        $ok = is_int($body['amount'] ?? null) && $body['amount'] >= 100 && $body['amount'] <= 2000000
           && ($body['currency'] ?? '') === 'XAF' && in_array($body['paymentMethod'] ?? '', ['MTN_MOMO', 'ORANGE_MONEY'], true)
           && preg_match('/^(\+?237)?6\d{8}$/', (string)($body['phoneNumber'] ?? ''))
           && preg_match('/^[A-Za-z0-9_-]{1,64}$/', (string)($body['reference'] ?? ''))
           && mb_strlen((string)($body['description'] ?? '')) <= 180;
        if (!$ok) { $err(400, 'validation_error', json_encode($body)); }
        foreach ($S['payments'] as $p) { if ($p['reference'] === $body['reference']) { $err(409, 'reference_conflict'); } }
        $id = sprintf('%08x-0000-4000-8000-%012x', random_int(0, 0xffffffff), random_int(0, 0xffffffffffff));
        $fee = (int)ceil($body['amount'] * 7 / 100);
        $S['payments'][$id] = ['id' => $id, 'reference' => $body['reference'], 'status' => 'pending', 'amount' => $body['amount'],
            'fee' => $fee, 'net' => $body['amount'] - $fee, 'currency' => 'XAF', 'paymentMethod' => $body['paymentMethod'],
            'phoneNumber' => $body['phoneNumber'], 'description' => $body['description'] ?? null, 'metadata' => $body['metadata'] ?? (object)[],
            'createdAt' => gmdate('Y-m-d\TH:i:s.000\Z'), 'paidAt' => null];
        $S['idem'][$key] = ['body' => $raw, 'id' => $id];
        $save(); out(202, $S['payments'][$id]);
    }
    if ($m === 'GET' && preg_match('#^/v1/payments/([0-9a-f-]+)$#', $r, $x)) {
        if (!isset($S['payments'][$x[1]])) { $err(404, 'not_found'); }
        out(200, $S['payments'][$x[1]]);
    }
    if ($m === 'GET' && $r === '/v1/balance') {
        $earned = array_sum(array_map(fn($p) => $p['status'] === 'succeeded' ? $p['net'] : 0, $S['payments']));
        out(200, ['currency' => 'XAF', 'available' => $earned, 'pending' => 0, 'totalEarned' => $earned, 'totalWithdrawn' => 0]);
    }
    $err(404, 'not_found');
}

/* ---------- RouterOS REST ---------- */
if (str_starts_with($path, '/ros/rest/')) {
    if ($S['down']) { flock($fh, LOCK_UN); sleep(4); out(503, []); }   // provoque le délai dépassé côté client
    if (($_SERVER['PHP_AUTH_USER'] ?? '') !== 'gandal-api' || ($_SERVER['PHP_AUTH_PW'] ?? '') !== 'test') { out(401, ['error' => 401, 'message' => 'Unauthorized']); }
    $r = substr($path, strlen('/ros/rest'));
    if ($r === '/system/resource') { out(200, ['cpu-load' => '7', 'free-memory' => '180000000', 'total-memory' => '268435456', 'uptime' => '3d4h']); }
    if ($r === '/ip/hotspot/active') {
        $list = array_map(fn($u) => ['.id' => '*A' . strtoupper(substr(md5($u), 0, 8)), 'user' => $u], array_values(array_filter(array_keys($S['users']), fn($u) => !in_array($u, $S['kicked'], true))));
        if (isset($query['user'])) { $list = array_values(array_filter($list, fn($a) => $a['user'] === $query['user'])); }
        out(200, $list);
    }
    if ($m === 'DELETE' && preg_match('#^/ip/hotspot/active/(.+)$#', $r, $x)) {
        foreach (array_keys($S['users']) as $u) { if ('*A' . strtoupper(substr(md5($u), 0, 8)) === $x[1]) { $S['kicked'][] = $u; $save(); out(204, null); } }
        out(404, ['error' => 404, 'message' => 'no such item']);
    }
    if ($m === 'PATCH' && preg_match('#^/user-manager/user/(.+)$#', $r, $x)) {
        foreach ($S['users'] as $name => $u) { if ($u['.id'] === $x[1]) { $S['users'][$name] = array_merge($u, $body); $save(); out(200, $S['users'][$name]); } }
        out(404, ['error' => 404, 'message' => 'no such item']);
    }
    if ($r === '/user-manager/user') {
        if ($m === 'PUT') {
            if (isset($S['users'][$body['name']])) { out(400, ['error' => 400, 'message' => 'Bad Request', 'detail' => 'failure: user with the same name already exists']); }
            $S['users'][$body['name']] = $body + ['.id' => '*' . (count($S['users']) + 1), 'started' => date('Y-m-d H:i:s')]; $save(); out(201, $S['users'][$body['name']]);
        }
        $list = array_values(array_filter($S['users'], fn($u) => !isset($query['name']) || $u['name'] === $query['name']));
        out(200, $list);
    }
    if ($r === '/user-manager/user-profile') {
        if ($m === 'PUT') {
            if (!isset($S['users'][$body['user']])) { out(400, ['error' => 400, 'detail' => 'no such user']); }
            if (!str_starts_with($body['profile'], 'gd-')) { out(400, ['error' => 400, 'detail' => 'no such profile']); }
            $S['profiles'][] = $body + ['state' => 'waiting'];
            $save(); out(201, $body);
        }
        $list = array_values(array_filter($S['profiles'], fn($p) => !isset($query['user']) || $p['user'] === $query['user']));
        foreach ($list as &$p) { $p['state'] = 'running-active'; $p['end-time'] = date('Y-m-d H:i:s', time() + 7 * 86400); }
        out(200, $list);
    }
    if ($r === '/user-manager/session') {
        // Chaque utilisateur a une session dont les compteurs grossissent à chaque relevé (50 Mo / 5 Mo).
        $S['n']++; $save();
        $out = []; $i = 0;
        foreach ($S['users'] as $name => $u) {
            $i++;
            $out[] = ['.id' => '*' . $i, 'user' => $name, 'started' => $u['started'] ?? '2026-01-01 00:00:00', 'ended' => '', 'active' => 'true',
                      'uptime' => '1h', 'download' => (string)(52428800 * $S['n'] + ($u['extra'] ?? 0)), 'upload' => (string)(5242880 * $S['n']),
                      'calling-station-id' => 'AA:BB:CC:00:00:0' . ($i % 10), 'user-address' => '10.5.50.' . (10 + $i)];
        }
        out(200, $out);
    }
    out(404, ['error' => 404, 'message' => 'no such command']);
}
out(404, []);
