<?php
/*
 * Bascule de débit des illimités avec volume inclus (lancée après chaque collecte, toutes les 5 min).
 * Quand la consommation relevée d'un ticket dépasse le volume de son forfait, le serveur :
 *   1. remplace l'attribut Mikrotik-Rate-Limit de l'utilisateur User Manager par le débit réduit ;
 *   2. ferme sa session hotspot pour que le routeur applique le nouveau débit à la reconnexion.
 * Le ticket reste valable jusqu'à la fin de sa durée. Comme le relevé a lieu toutes les 5 minutes,
 * le client peut dépasser son volume d'environ 190 Mo (5 min à 5 Mb/s) avant la bascule.
 */

declare(strict_types=1);

function throttle_check(): void {
    foreach (plans() as $planId => $p) {
        if (!plan_throttles($p)) { continue; }
        $over = all('SELECT t.username, sum(s.bytes_down + s.bytes_up) AS used
                     FROM tickets t JOIN usage_sessions s ON s.username = t.username
                     WHERE t.plan_id = ? AND t.throttled_at IS NULL
                     GROUP BY t.username HAVING sum(s.bytes_down + s.bytes_up) >= ?',
                    [$planId, $p['data_gb'] * 1073741824]);
        foreach ($over as $t) {
            $err = um_throttle($t['username'], (int)$p['rate_after']);
            if ($err !== '') { logline("débit réduit {$t['username']} : $err"); continue; }   // réessayé au prochain relevé
            q('UPDATE tickets SET throttled_at = now(), updated_at = now() WHERE username = ?', [$t['username']]);
            logline("débit réduit {$t['username']} ({$p['label']}) : " . round($t['used'] / 1073741824, 1) . " Go consommés, passage à {$p['rate_after']} Mb/s");
        }
    }
}

/* Identifiant interne RouterOS (*1A2B) : placé tel quel dans l'URL, l'astérisque ne doit pas être encodé */
function ros_id(string $id): bool { return (bool)preg_match('/^\*[0-9A-Fa-f]+$/', $id); }

/* Pose le débit réduit sur l'utilisateur et ferme ses sessions hotspot. Renvoie '' si tout s'est bien passé. */
function um_throttle(string $username, int $mbps): string {
    $u = ros('GET', '/user-manager/user?name=' . rawurlencode($username));
    if (!ros_ok($u)) { return ros_error($u); }
    $id = (string)($u['data'][0]['.id'] ?? '');
    if (!ros_id($id)) { return 'utilisateur absent de User Manager'; }
    $r = ros('PATCH', '/user-manager/user/' . $id, ['attributes' => rate_attr($mbps)]);
    if (!ros_ok($r)) { return ros_error($r); }

    $active = ros('GET', '/ip/hotspot/active?user=' . rawurlencode($username));
    if (!ros_ok($active)) { return ros_error($active); }
    foreach ($active['data'] ?? [] as $a) {
        if (($a['user'] ?? '') !== $username || !ros_id((string)($a['.id'] ?? ''))) { continue; }
        $d = ros('DELETE', '/ip/hotspot/active/' . $a['.id']);
        if (!ros_ok($d)) { return ros_error($d); }
    }
    return '';
}
