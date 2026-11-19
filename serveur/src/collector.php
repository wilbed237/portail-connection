<?php
/*
 * Collecteur, lancé toutes les 5 minutes par le worker :
 *  - état du routeur (joignable, CPU, mémoire, utilisateurs connectés) ;
 *  - sessions User Manager : les compteurs cumulés sont transformés en consommation
 *    par tranche de 5 minutes (usage_samples), base des statistiques jour / semaine / mois ;
 *  - état de chaque ticket (en attente, en cours, épuisé, date d'expiration).
 */

declare(strict_types=1);

/* Premier champ présent parmi plusieurs noms possibles (les noms varient selon la version de RouterOS). */
function pick(array $row, array $names, $default = null) {
    foreach ($names as $n) { if (isset($row[$n]) && $row[$n] !== '') { return $row[$n]; } }
    return $default;
}

function collect(): void {
    $res = ros('GET', '/system/resource');
    if (!ros_ok($res)) {
        q('INSERT INTO router_health (ts, reachable, error) VALUES (now(), false, ?) ON CONFLICT (ts) DO NOTHING', [ros_error($res)]);
        logline('collecte : ' . ros_error($res));
        return;
    }
    $r = $res['data'] ?? [];
    $active = ros('GET', '/ip/hotspot/active');
    q('INSERT INTO router_health (ts, reachable, cpu_load, free_memory, total_memory, uptime, active_users)
       VALUES (now(), true, ?, ?, ?, ?, ?) ON CONFLICT (ts) DO NOTHING', [
        (int)pick($r, ['cpu-load'], 0), (int)pick($r, ['free-memory'], 0), (int)pick($r, ['total-memory'], 0),
        (string)pick($r, ['uptime'], ''), ros_ok($active) ? count($active['data'] ?? []) : null,
    ]);

    // Forfait de chaque ticket, pour ventiler la consommation par forfait
    $planOf = [];
    foreach (all('SELECT username, plan_id FROM tickets') as $t) { $planOf[$t['username']] = $t['plan_id']; }

    $sessions = ros('GET', '/user-manager/session');
    if (ros_ok($sessions)) {
        $n = 0;
        foreach ($sessions['data'] ?? [] as $s) {
            $user = (string)pick($s, ['user', 'username'], '');
            if ($user === '') { continue; }
            $id = pick($s, ['.id'], '') . '|' . pick($s, ['acct-session-id', 'started'], '');
            $down = (int)pick($s, ['download', 'bytes-out'], 0);
            $up = (int)pick($s, ['upload', 'bytes-in'], 0);
            $ended = ros_date((string)pick($s, ['ended'], ''));
            $isActive = ($s['active'] ?? null) === 'true' || ($ended === null && ($s['status'] ?? '') !== 'stop');

            tx(function () use ($id, $user, $s, $down, $up, $ended, $isActive, $planOf, &$n) {
                $prev = one('SELECT bytes_down, bytes_up FROM usage_sessions WHERE id = ? FOR UPDATE', [$id]);
                $dd = $prev ? max(0, $down - (int)$prev['bytes_down']) : $down;
                $du = $prev ? max(0, $up - (int)$prev['bytes_up']) : $up;
                q('INSERT INTO usage_sessions (id, username, started_at, ended_at, uptime_s, bytes_down, bytes_up, mac, ip, active, updated_at)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, now())
                   ON CONFLICT (id) DO UPDATE SET ended_at = EXCLUDED.ended_at, uptime_s = EXCLUDED.uptime_s,
                     bytes_down = EXCLUDED.bytes_down, bytes_up = EXCLUDED.bytes_up, active = EXCLUDED.active, updated_at = now()', [
                    $id, $user, ros_date((string)pick($s, ['started'], '')), $ended, ros_duration((string)pick($s, ['uptime'], '')),
                    $down, $up, (string)pick($s, ['calling-station-id'], ''), (string)pick($s, ['user-address', 'framed-ip-address'], ''),
                    $isActive ? 'true' : 'false',
                ]);
                if ($dd + $du > 0) {
                    q('INSERT INTO usage_samples (ts, username, plan_id, bytes_down, bytes_up) VALUES (now(), ?, ?, ?, ?)',
                      [$user, $planOf[$user] ?? null, $dd, $du]);
                    $n++;
                }
            });
        }
        logline("collecte : $n sessions avec consommation");
    } else {
        logline('collecte sessions : ' . ros_error($sessions));
    }

    // État des tickets (en attente de première connexion, en cours, épuisé…) et date d'expiration
    $ups = ros('GET', '/user-manager/user-profile');
    if (ros_ok($ups)) {
        foreach ($ups['data'] ?? [] as $u) {
            $user = (string)pick($u, ['user'], '');
            if ($user === '') { continue; }
            q('UPDATE tickets SET state = ?, expires_at = COALESCE(?, expires_at), updated_at = now() WHERE username = ?',
              [(string)pick($u, ['state'], ''), ros_date((string)pick($u, ['end-time'], '')), $user]);
        }
    }
    q('UPDATE tickets t SET first_login_at = s.first FROM
         (SELECT username, min(started_at) AS first FROM usage_sessions GROUP BY username) s
       WHERE t.username = s.username AND t.first_login_at IS NULL AND s.first IS NOT NULL');
}
