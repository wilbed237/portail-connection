<?php
declare(strict_types=1);

function normalize_phone(string $p): ?string {
    $p = preg_replace('/\D/', '', $p) ?? '';
    if (strlen($p) === 12 && strpos($p, '237') === 0) { $p = substr($p, 3); }
    return preg_match('/^6\d{8}$/', $p) ? $p : null;
}

/* Adresse MAC envoyée par le portail (variable $(mac) du hotspot) -> AA:BB:CC:DD:EE:FF, ou null */
function normalize_mac(string $m): ?string {
    $m = strtoupper(trim($m));
    return preg_match('/^[0-9A-F]{2}([:-][0-9A-F]{2}){5}$/', $m) ? str_replace('-', ':', $m) : null;
}

function mask_phone(string $p): string { return strlen($p) === 9 ? substr($p, 0, 3) . ' •• •• ' . substr($p, 7) : '•••'; }

/* Identifiant lisible sans caractères ambigus (0/o, 1/l/i) et mot de passe à 6 chiffres. */
function new_credentials(): array {
    $alpha = 'abcdefghjkmnpqrstuvwxyz23456789';
    $u = 'gd';
    for ($i = 0; $i < 7; $i++) { $u .= $alpha[random_int(0, strlen($alpha) - 1)]; }
    return [$u, (string)random_int(100000, 999999)];
}

function json_out(array $data, int $code = 200): never {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/* Derrière Caddy : la dernière entrée de X-Forwarded-For est celle ajoutée par Caddy (les précédentes peuvent être falsifiées). */
function client_ip(): string {
    $xff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    if ($xff !== '') { $parts = explode(',', $xff); return trim(end($parts)); }
    return $_SERVER['REMOTE_ADDR'] ?? '';
}

/*
 * Limiteur simple à fenêtre fixe. Renvoie false si la limite est dépassée.
 * Attention : derrière le hotspot, tous les clients partagent l'adresse IP publique du routeur.
 */
function rate_limit(string $key, int $max, int $windowSeconds): bool {
    return tx(function () use ($key, $max, $windowSeconds) {
        $r = one('SELECT window_start, count FROM rate_limits WHERE key = ? FOR UPDATE', [$key]);
        if (!$r || strtotime($r['window_start']) < time() - $windowSeconds) {
            q('INSERT INTO rate_limits (key, window_start, count) VALUES (?, now(), 1)
               ON CONFLICT (key) DO UPDATE SET window_start = now(), count = 1', [$key]);
            return true;
        }
        if ($r['count'] >= $max) { return false; }
        q('UPDATE rate_limits SET count = count + 1 WHERE key = ?', [$key]);
        return true;
    });
}

function telegram(string $text): bool {
    $c = cfg();
    if ($c['telegram_token'] === '' || $c['telegram_chat_id'] === '') { logline('ALERTE (Telegram non configuré) : ' . $text); return false; }
    $r = http_json('POST', 'https://api.telegram.org/bot' . $c['telegram_token'] . '/sendMessage', [],
        ['chat_id' => $c['telegram_chat_id'], 'text' => $text, 'disable_web_page_preview' => true]);
    return $r['code'] === 200;
}

// PostgreSQL renvoie les grandes sommes (numeric) sous forme de texte : on accepte les deux.
function fcfa(int|float|string|null $n): string { return number_format((float)$n, 0, ',', ' ') . ' FCFA'; }

function bytes_h(int|float|string|null $b): string {
    $u = ['o', 'Ko', 'Mo', 'Go', 'To']; $i = 0; $b = (float)$b;
    while ($b >= 1024 && $i < 4) { $b /= 1024; $i++; }
    return number_format($b, $i >= 3 ? 2 : 0, ',', ' ') . ' ' . $u[$i];
}

/* Durées RouterOS (« 1w2d3h4m5s », « 3d », « 00:05:00 ») en secondes */
function ros_duration(string $s): int {
    $s = trim($s);
    if ($s === '' ) { return 0; }
    if (preg_match('/^(\d+):(\d{2}):(\d{2})$/', $s, $m)) { return $m[1] * 3600 + $m[2] * 60 + (int)$m[3]; }
    $mult = ['w' => 604800, 'd' => 86400, 'h' => 3600, 'm' => 60, 's' => 1];
    $t = 0;
    if (preg_match_all('/(\d+)(ms|w|d|h|m|s)/', $s, $mm, PREG_SET_ORDER)) {
        foreach ($mm as $x) { if ($x[2] !== 'ms') { $t += (int)$x[1] * $mult[$x[2]]; } }
    }
    return $t;
}

/* Dates RouterOS : « 2026-11-18 10:12:00 » (7.10+) ou « nov/18/2026 10:12:00 » (anciennes versions) */
function ros_date(?string $s): ?string {
    if ($s === null || $s === '' || $s === 'unlimited') { return null; }
    if (preg_match('#^([a-z]{3})/(\d{2})/(\d{4})\s+(\S+)$#i', $s, $m)) {
        $months = ['jan'=>1,'feb'=>2,'mar'=>3,'apr'=>4,'may'=>5,'jun'=>6,'jul'=>7,'aug'=>8,'sep'=>9,'oct'=>10,'nov'=>11,'dec'=>12];
        $s = sprintf('%s-%02d-%s %s', $m[3], $months[strtolower($m[1])] ?? 1, $m[2], $m[4]);
    }
    $t = strtotime($s);
    return $t === false ? null : date('c', $t);
}
