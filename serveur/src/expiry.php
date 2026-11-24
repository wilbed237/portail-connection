<?php
/*
 * Forfaits « 1 jour » (midnight dans plans()) : le ticket se termine à 23:59:59 le jour de sa première
 * connexion, pas 24 h après. User Manager ne sait compter qu'une durée depuis la première connexion,
 * c'est donc le serveur qui coupe :
 *   - day_end_expiry()  (après chaque collecte) : date d'expiration affichée = 23:59:59 du jour de la première connexion ;
 *   - day_end_check()   (toutes les 15 s) : passé minuit, désactive le ticket sur le routeur et ferme sa session.
 * La première connexion est connue par la collecte (toutes les 5 min) : un ticket activé à 23:58 est donc
 * coupé au premier relevé après minuit, au plus tard vers 00:05.
 * Seuls les tickets encore en cours sur le routeur sont coupés (les anciens, déjà expirés, sont ignorés).
 */

declare(strict_types=1);

/* Expression SQL : date locale (fuseau du serveur) d'un horodatage */
function local_date_sql(string $col): string {
    return "($col AT TIME ZONE '" . date_default_timezone_get() . "')::date";
}

function day_end_expiry(): void {
    $plans = midnight_plans();
    if (!$plans) { return; }
    $tz = date_default_timezone_get();
    $in = implode(',', array_fill(0, count($plans), '?'));
    q("UPDATE tickets SET expires_at = ((" . local_date_sql('first_login_at') . " + 1)::timestamp AT TIME ZONE '$tz') - interval '1 second'
       WHERE plan_id IN ($in) AND first_login_at IS NOT NULL", $plans);
}

function day_end_check(): void {
    $plans = midnight_plans();
    if (!$plans) { return; }
    $in = implode(',', array_fill(0, count($plans), '?'));
    $due = all("SELECT username, plan_id, first_login_at FROM tickets
                WHERE plan_id IN ($in) AND first_login_at IS NOT NULL AND ended_at IS NULL AND state LIKE 'running%'
                  AND " . local_date_sql('first_login_at') . " < " . local_date_sql('now()'), $plans);
    foreach ($due as $t) {
        $err = um_end($t['username']);
        if ($err !== '') { logline("fin de journée {$t['username']} : $err"); continue; }   // réessayé au passage suivant
        q('UPDATE tickets SET ended_at = now(), updated_at = now() WHERE username = ?', [$t['username']]);
        logline("fin de journée {$t['username']} ({$t['plan_id']}, première connexion " . date('d/m H:i', strtotime($t['first_login_at'])) . ") : ticket désactivé");
    }
}

/* Désactive l'utilisateur User Manager et ferme ses sessions hotspot. Renvoie '' si tout s'est bien passé. */
function um_end(string $username): string {
    $u = ros('GET', '/user-manager/user?name=' . rawurlencode($username));
    if (!ros_ok($u)) { return ros_error($u); }
    $id = (string)($u['data'][0]['.id'] ?? '');
    if (!ros_id($id)) { return 'utilisateur absent de User Manager'; }
    $r = ros('PATCH', '/user-manager/user/' . $id, ['disabled' => 'yes']);
    if (!ros_ok($r)) { return ros_error($r); }
    return hotspot_kick($username);
}
