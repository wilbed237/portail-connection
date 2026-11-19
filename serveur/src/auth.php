<?php
/*
 * Authentification de l'espace d'administration :
 * mot de passe (bcrypt/argon2 via password_hash) + code à 6 chiffres d'une application
 * d'authentification (TOTP, RFC 6238), blocage après 5 échecs, sessions courtes, jeton CSRF.
 * Rôles : admin (tout) et comptable (lecture seule, numéros masqués).
 */

declare(strict_types=1);

function b32_encode(string $bin): string {
    $alpha = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; $bits = ''; $out = '';
    foreach (str_split($bin) as $c) { $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT); }
    foreach (str_split($bits, 5) as $chunk) { $out .= $alpha[bindec(str_pad($chunk, 5, '0'))]; }
    return $out;
}
function b32_decode(string $s): string {
    $alpha = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; $bits = ''; $out = '';
    foreach (str_split(strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $s))) as $c) {
        $bits .= str_pad(decbin(strpos($alpha, $c)), 5, '0', STR_PAD_LEFT);
    }
    foreach (str_split($bits, 8) as $byte) { if (strlen($byte) === 8) { $out .= chr(bindec($byte)); } }
    return $out;
}
function totp(string $secretB32, int $t): string {
    $h = hash_hmac('sha1', pack('J', intdiv($t, 30)), b32_decode($secretB32), true);
    $o = ord($h[19]) & 0x0f;
    $n = ((ord($h[$o]) & 0x7f) << 24 | ord($h[$o + 1]) << 16 | ord($h[$o + 2]) << 8 | ord($h[$o + 3])) % 1000000;
    return str_pad((string)$n, 6, '0', STR_PAD_LEFT);
}
function totp_check(string $secretB32, string $code): bool {
    $code = preg_replace('/\D/', '', $code) ?? '';
    if (strlen($code) !== 6) { return false; }
    foreach ([-30, 0, 30] as $d) { if (hash_equals(totp($secretB32, time() + $d), $code)) { return true; } }
    return false;
}

function admin_session_start(): void {
    if (session_status() === PHP_SESSION_ACTIVE) { return; }
    $https = ($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    ini_set('session.use_strict_mode', '1');
    session_name('gandal_admin');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/admin/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Strict']);
    session_start();
    if (isset($_SESSION['user']) && ($_SESSION['seen'] ?? 0) < time() - 1800) { $_SESSION = []; session_regenerate_id(true); }
    $_SESSION['seen'] = time();
    if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(16)); }
}

/* Vérifie identifiant, mot de passe et code. Renvoie un message d'erreur, ou '' en cas de succès. */
function admin_login(string $username, string $password, string $code): string {
    $generic = 'Identifiant, mot de passe ou code incorrect.';
    if (!rate_limit('login:' . client_ip(), 20, 900)) { return 'Trop de tentatives. Réessayez dans 15 minutes.'; }
    $a = one('SELECT * FROM admins WHERE username = ?', [$username]);
    if (!$a) { password_verify($password, password_hash('leurre', PASSWORD_DEFAULT)); return $generic; } // même durée qu'un vrai compte
    if ($a['locked_until'] && strtotime($a['locked_until']) > time()) { return 'Compte bloqué 15 minutes après plusieurs échecs.'; }
    if (!password_verify($password, $a['password_hash']) || !totp_check($a['totp_secret'], $code)) {
        $fails = (int)$a['failed_logins'] + 1;
        q("UPDATE admins SET failed_logins = ?, locked_until = CASE WHEN ? >= 5 THEN now() + interval '15 minutes' ELSE NULL END WHERE username = ?",
          [$fails >= 5 ? 0 : $fails, $fails, $username]);
        audit($username, 'connexion refusée', client_ip());
        return $generic;
    }
    q('UPDATE admins SET failed_logins = 0, locked_until = NULL, last_login_at = now() WHERE username = ?', [$username]);
    session_regenerate_id(true);
    $_SESSION['user'] = $a['username'];
    $_SESSION['role'] = $a['role'];
    audit($username, 'connexion', client_ip());
    return '';
}

function require_admin(?string $role = null): array {
    admin_session_start();
    if (empty($_SESSION['user'])) { header('Location: login.php'); exit; }
    if ($role !== null && $_SESSION['role'] !== $role) { http_response_code(403); exit('Accès réservé au rôle ' . $role . '.'); }
    return ['user' => $_SESSION['user'], 'role' => $_SESSION['role']];
}

function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . h($_SESSION['csrf']) . '">'; }
function csrf_check(): void {
    if (!hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? ''))) { http_response_code(400); exit('Jeton de sécurité invalide. Rechargez la page.'); }
}

function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
