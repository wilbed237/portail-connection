<?php
/*
 * Portail de l'Académie Cisco (port ether4, serveur hotspot « hotspot2 », profil sans RADIUS).
 * Ses tickets ne passent pas par User Manager : ce sont des utilisateurs du hotspot limités à
 * hotspot2, ce qui les sépare de ceux du portail GI (un ticket ne marche que sur son portail).
 *
 *   - volume : limit-bytes-total posé sur l'utilisateur, appliqué par le routeur ;
 *   - 2 appareils : profil d'utilisateur (shared-users=2) ;
 *   - débit PAR TICKET, partagé entre ses appareils : le profil donne le débit (portail2 = 10 Mb/s,
 *     portail2-5m = 5 Mb/s) et le script gandal-portail2-debit du routeur crée une file par ticket ;
 *   - durée : comptée depuis la première connexion et coupée par le serveur (expiry.php) :
 *     fin de journée à 23:59:59 pour les forfaits « midnight », sinon première connexion + validity.
 *     limit-uptime (temps de connexion) reste en filet de sécurité sur le routeur.
 */

declare(strict_types=1);

const HS2_SERVER = 'hotspot2';

/* Profil d'utilisateur hotspot2 selon le débit du forfait */
function hs2_profile(array $plan): string { return (int)$plan['rate'] === 10 ? 'portail2' : 'portail2-' . (int)$plan['rate'] . 'm'; }

/* Crée un ticket, sur le bon portail. Renvoie '' en cas de succès, sinon le message d'erreur. */
function create_ticket(string $username, string $password, string $planId): string {
    $plan = plans()[$planId] ?? null;
    if ($plan && plan_portal($plan) === 'academie') { return hs2_create_ticket($username, $password, $planId); }
    return um_create_ticket($username, $password, $planId);
}

/* Termine un ticket (désactivé sur le routeur, sessions fermées), sur le bon portail. */
function ticket_end(string $username, string $planId): string {
    $plan = plans()[$planId] ?? null;
    if ($plan && plan_portal($plan) === 'academie') { return hs2_end($username); }
    return um_end($username);
}

/* Utilisateur hotspot2. Rejouable : s'il existe déjà (réponse du routeur perdue), on continue. */
function hs2_create_ticket(string $username, string $password, string $planId): string {
    $plan = plans()[$planId];
    $user = ['name' => $username, 'password' => $password, 'server' => HS2_SERVER, 'profile' => hs2_profile($plan),
             'limit-uptime' => $plan['validity'], 'comment' => 'GANDAL ' . $planId];
    if ($plan['data_gb'] > 0) { $user['limit-bytes-total'] = (string)($plan['data_gb'] * 1073741824); }
    $r = ros('PUT', '/ip/hotspot/user', $user);
    if (ros_ok($r)) { return ''; }
    $exists = ros('GET', '/ip/hotspot/user?name=' . rawurlencode($username));
    if (ros_ok($exists) && !empty($exists['data'])) { return ''; }
    return ros_error($r);
}

function hs2_end(string $username): string {
    $u = ros('GET', '/ip/hotspot/user?name=' . rawurlencode($username));
    if (!ros_ok($u)) { return ros_error($u); }
    $id = (string)($u['data'][0]['.id'] ?? '');
    if (!ros_id($id)) { return 'utilisateur absent du hotspot'; }
    $r = ros('PATCH', '/ip/hotspot/user/' . $id, ['disabled' => 'yes']);
    if (!ros_ok($r)) { return ros_error($r); }
    return hotspot_kick($username);
}

/*
 * Relevé des tickets hotspot2 (appelé par collect()) : les compteurs cumulés de chaque utilisateur
 * (bytes-out = reçu par le client, bytes-in = envoyé) deviennent des tranches de consommation,
 * comme les sessions User Manager. Une « session » par ticket : hs2|<ticket>.
 * $active : liste /ip/hotspot/active déjà lue par collect().
 */
function hs2_collect(array $active, array $planOf): void {
    $users = ros('GET', '/ip/hotspot/user');
    if (!ros_ok($users)) { logline('collecte hotspot2 : ' . ros_error($users)); return; }
    $online = [];
    foreach ($active as $a) {
        if (($a['server'] ?? '') === HS2_SERVER) { $online[(string)($a['user'] ?? '')] = max($online[(string)($a['user'] ?? '')] ?? 0, ros_duration((string)($a['uptime'] ?? ''))); }
    }
    $n = 0;
    foreach ($users['data'] ?? [] as $u) {
        $name = (string)($u['name'] ?? '');
        if (($u['server'] ?? '') !== HS2_SERVER || !isset($planOf[$name])) { continue; }
        $down = (int)($u['bytes-out'] ?? 0);
        $up = (int)($u['bytes-in'] ?? 0);
        $uptime = ros_duration((string)($u['uptime'] ?? ''));
        $isActive = isset($online[$name]);
        $limit = (int)($u['limit-bytes-total'] ?? 0);
        $state = in_array($u['disabled'] ?? 'false', ['true', 'yes'], true) || ($limit > 0 && $down + $up >= $limit) ? 'used'
               : ($uptime > 0 || $isActive ? ($isActive ? 'running-active' : 'running') : 'waiting');
        $id = 'hs2|' . $name;
        tx(function () use ($id, $name, $down, $up, $uptime, $isActive, $online, $planOf, $state, &$n) {
            $prev = one('SELECT bytes_down, bytes_up FROM usage_sessions WHERE id = ? FOR UPDATE', [$id]);
            // Compteurs remis à zéro sur le routeur (réinitialisation) : la nouvelle valeur est tout entière nouvelle
            $dd = $prev ? ($down >= (int)$prev['bytes_down'] ? $down - (int)$prev['bytes_down'] : $down) : $down;
            $du = $prev ? ($up >= (int)$prev['bytes_up'] ? $up - (int)$prev['bytes_up'] : $up) : $up;
            if ($prev || $uptime > 0 || $isActive) {
                // Première connexion : début de la session en cours si elle est ouverte, sinon l'heure du relevé
                $started = date('c', time() - ($online[$name] ?? 0));
                q('INSERT INTO usage_sessions (id, username, started_at, uptime_s, bytes_down, bytes_up, active, updated_at)
                   VALUES (?, ?, ?, ?, ?, ?, ?, now())
                   ON CONFLICT (id) DO UPDATE SET uptime_s = EXCLUDED.uptime_s, bytes_down = EXCLUDED.bytes_down,
                     bytes_up = EXCLUDED.bytes_up, active = EXCLUDED.active, updated_at = now()',
                  [$id, $name, $started, $uptime, $down, $up, $isActive ? 'true' : 'false']);
            }
            if ($dd + $du > 0) {
                q('INSERT INTO usage_samples (ts, username, plan_id, bytes_down, bytes_up) VALUES (now(), ?, ?, ?, ?)', [$name, $planOf[$name], $dd, $du]);
                $n++;
            }
            q('UPDATE tickets SET state = ?, updated_at = now() WHERE username = ?', [$state, $name]);
        });
    }
    if ($n) { logline("collecte hotspot2 : $n tickets avec consommation"); }
}

/* Date de fin des forfaits Académie qui ne finissent pas à minuit : première connexion + durée du forfait */
function hs2_expiry(): void {
    foreach (portal_plans('academie') as $id => $p) {
        if (!empty($p['midnight'])) { continue; }   // fin de journée : day_end_expiry()
        q('UPDATE tickets SET expires_at = first_login_at + make_interval(secs => ?) WHERE plan_id = ? AND first_login_at IS NOT NULL',
          [ros_duration($p['validity']), $id]);
    }
}

/* Coupe les tickets Académie arrivés au bout de leur durée (forfaits semaine, mois) */
function hs2_end_check(): void {
    $ids = array_keys(array_filter(portal_plans('academie'), fn($p) => empty($p['midnight'])));
    if (!$ids) { return; }
    $in = implode(',', array_fill(0, count($ids), '?'));
    $due = all("SELECT username, plan_id, expires_at FROM tickets
                WHERE plan_id IN ($in) AND expires_at <= now() AND ended_at IS NULL AND state LIKE 'running%'", $ids);
    foreach ($due as $t) {
        $err = hs2_end($t['username']);
        if ($err !== '') { logline("fin de forfait {$t['username']} : $err"); continue; }
        q('UPDATE tickets SET ended_at = now(), state = ?, updated_at = now() WHERE username = ?', ['used', $t['username']]);
        logline("fin de forfait {$t['username']} ({$t['plan_id']}, échu le " . date('d/m H:i', strtotime($t['expires_at'])) . ') : ticket désactivé');
    }
}
