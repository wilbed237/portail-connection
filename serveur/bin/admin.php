<?php
/*
 * Comptes de l'espace d'administration.
 *   php bin/admin.php creer <identifiant> <admin|comptable>
 *   php bin/admin.php liste
 *   php bin/admin.php nouveau-mot-de-passe <identifiant>
 *   php bin/admin.php nouveau-2fa <identifiant>
 *   php bin/admin.php supprimer <identifiant>
 * Le mot de passe et la clé 2FA ne s'affichent qu'une fois.
 */

declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/auth.php';
if (PHP_SAPI !== 'cli') { exit; }

function new_password(): string {
    $a = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789'; $p = '';
    for ($i = 0; $i < 16; $i++) { $p .= $a[random_int(0, strlen($a) - 1)]; }
    return $p;
}
function show_2fa(string $user, string $secret): void {
    echo "Clé 2FA : $secret\n";
    echo "Ajoutez-la dans Google Authenticator, Aegis ou FreeOTP (saisie manuelle), ou générez un QR code de :\n";
    echo 'otpauth://totp/' . rawurlencode("GANDAL:$user") . "?secret=$secret&issuer=GANDAL\n";
}

[$cmd, $user, $role] = [$argv[1] ?? '', $argv[2] ?? '', $argv[3] ?? ''];
migrate();

switch ($cmd) {
    case 'creer':
        if (!preg_match('/^[a-z0-9._-]{3,32}$/', $user) || !in_array($role, ['admin', 'comptable'], true)) {
            fwrite(STDERR, "Usage : php bin/admin.php creer <identifiant> <admin|comptable>\n"); exit(1);
        }
        $pw = new_password(); $secret = b32_encode(random_bytes(20));
        q('INSERT INTO admins (username, password_hash, role, totp_secret) VALUES (?, ?, ?, ?)',
          [$user, password_hash($pw, PASSWORD_DEFAULT), $role, $secret]);
        audit('cli', 'création compte', "$user ($role)");
        echo "Compte « $user » créé, rôle $role.\nMot de passe : $pw\n"; show_2fa($user, $secret);
        break;
    case 'liste':
        foreach (all('SELECT username, role, last_login_at, locked_until FROM admins ORDER BY username') as $a) {
            printf("%-20s %-10s dernière connexion : %s%s\n", $a['username'], $a['role'], $a['last_login_at'] ?? 'jamais',
                $a['locked_until'] && strtotime($a['locked_until']) > time() ? ' (bloqué)' : '');
        }
        break;
    case 'nouveau-mot-de-passe':
        $pw = new_password();
        if (q('UPDATE admins SET password_hash = ?, failed_logins = 0, locked_until = NULL WHERE username = ?', [password_hash($pw, PASSWORD_DEFAULT), $user])->rowCount() === 0) { fwrite(STDERR, "Compte inconnu.\n"); exit(1); }
        audit('cli', 'nouveau mot de passe', $user);
        echo "Nouveau mot de passe de « $user » : $pw\n";
        break;
    case 'nouveau-2fa':
        $secret = b32_encode(random_bytes(20));
        if (q('UPDATE admins SET totp_secret = ? WHERE username = ?', [$secret, $user])->rowCount() === 0) { fwrite(STDERR, "Compte inconnu.\n"); exit(1); }
        audit('cli', 'nouvelle clé 2FA', $user);
        show_2fa($user, $secret);
        break;
    case 'supprimer':
        q('DELETE FROM admins WHERE username = ?', [$user]);
        audit('cli', 'suppression compte', $user);
        echo "Compte « $user » supprimé.\n";
        break;
    default:
        echo "Usage : php bin/admin.php creer|liste|nouveau-mot-de-passe|nouveau-2fa|supprimer …\n"; exit(1);
}
