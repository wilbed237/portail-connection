<?php
/*
 * Données de démonstration pour l'aperçu local de l'espace admin (jamais sur le serveur réel).
 * 90 jours de ventes, de tickets et de consommation, avec des tickets en cours.
 *   docker compose -f tests/docker-compose.test.yml -f tests/docker-compose.preview.yml -p gandalpreview \
 *     exec worker php tests/seed.php
 */

declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
if (PHP_SAPI !== 'cli') { exit; }
if (!str_contains(cfg()['router_url'], 'mock')) { fwrite(STDERR, "Refusé : réservé à la pile de test (routeur simulé).\n"); exit(1); }

migrate();
mt_srand(42);
db()->exec('TRUNCATE usage_samples, usage_sessions, router_health, alerts, audit_log, rate_limits RESTART IDENTITY');
db()->exec('DELETE FROM tickets; DELETE FROM orders;');

$plans = plans();
$weights = ['1go-1j' => 30, 'illim-1j' => 24, '4go-3j' => 15, '8go-5j' => 10, '20go-7j' => 8, 'illim-7j' => 7, 'illim-14j' => 4, 'illim-30j' => 2];
$hourW = [1, 0.6, 0.3, 0.2, 0.2, 0.3, 0.8, 1.5, 2.2, 2.6, 2.8, 3, 3.4, 3.2, 3, 3, 3.3, 3.8, 4.6, 5.4, 5.8, 5.2, 3.8, 2.2];
$GB = 1073741824;

function pick_w(array $w) { $r = mt_rand(1, (int)array_sum($w)); foreach ($w as $k => $v) { if (($r -= $v) <= 0) return $k; } return array_key_first($w); }
function rnd_name(): string { $a = 'abcdefghjkmnpqrstuvwxyz23456789'; $s = 'gd'; for ($i = 0; $i < 7; $i++) $s .= $a[mt_rand(0, 30)]; return $s; }
function validity_s(string $v): int { return (int)$v * 86400; }

$now = time();
$orders = []; $tickets = []; $samples = [];
$hourKeys = array_combine(range(0, 23), array_map(fn($x) => (int)round($x * 10), $hourW));

for ($d = 89; $d >= 0; $d--) {
    $day = strtotime("today -$d days");
    $dow = (int)date('N', $day);
    $base = 4 + (89 - $d) * 0.22;                       // croissance progressive
    $n = (int)round($base * ($dow >= 5 ? 1.35 : 1) * (0.75 + mt_rand(0, 50) / 100));
    for ($i = 0; $i < $n; $i++) {
        $h = pick_w($hourKeys);
        $t = $day + $h * 3600 + mt_rand(0, 3599);
        if ($t > $now) { continue; }
        $plan = pick_w($weights);
        $p = $plans[$plan];
        $r = mt_rand(1, 100);
        $status = $r <= 88 ? 'complete' : ($r <= 95 ? 'failed' : 'expired');
        $ref = 'GD-' . date('ymd', $t) . '-' . strtoupper(substr(md5((string)mt_rand()), 0, 6));
        $op = mt_rand(1, 100) <= 62 ? 'mtn' : 'orange';
        $phone = ($op === 'mtn' ? '67' : '69') . str_pad((string)mt_rand(0, 9999999), 7, '0', STR_PAD_LEFT);
        $paid = $status === 'complete' ? $t + mt_rand(20, 90) : null;
        $user = $status === 'complete' ? rnd_name() : null;
        $src = $status === 'complete' ? (mt_rand(1, 100) <= 4 ? 'stock' : 'router') : null;
        $orders[] = [$ref, hash('sha256', $ref), $plan, $p['price'], (int)ceil($p['price'] * 0.07), $phone, $op, $status,
                     date('c', $t), $paid ? date('c', $paid) : null, $paid ? date('c', $paid + 5) : null, $user, $src];
        if (!$user) { continue; }

        // Le ticket : première connexion peu après l'achat, consommation répartie heure par heure
        $first = $paid + mt_rand(60, 900);
        if ($first > $now) { $first = $now - 30; }
        $end = $first + validity_s($p['validity']);
        $quota = $p['data_gb'] * $GB;
        $perDay = match (true) {
            $p['type'] === 'data' => $quota * (0.55 + mt_rand(0, 45) / 100) / max(1, (int)$p['validity']),
            $plan === 'illim-1j' => mt_rand(8, 40) / 10 * $GB,
            default => mt_rand(25, 70) / 10 * $GB,
        };
        $cap = $p['type'] === 'data' ? $quota : PHP_INT_MAX;
        $used = 0; $throttled = null;
        for ($ts = $first - ($first % 3600); $ts < min($end, $now); $ts += 3600) {
            $hh = (int)date('G', $ts);
            $b = (int)($perDay * $hourW[$hh] / array_sum($hourW) * (0.4 + mt_rand(0, 120) / 100));
            if ($used + $b > $cap) { $b = $cap - $used; }
            if ($b <= 0) { break; }
            if (plan_throttles($p) && !$throttled && $used + $b >= $quota) { $throttled = $ts; }
            if (plan_throttles($p) && $throttled) { $b = (int)($b * 0.45); }
            $used += $b;
            $samples[] = [date('c', $ts + mt_rand(0, 3599)), $user, $plan, (int)($b * 0.88), (int)($b * 0.12)];
        }
        $state = $end <= $now ? 'used' : (($p['type'] === 'data' && $used >= $quota) ? 'used' : 'running-active');
        $tickets[] = [$user, 'demo', $plan, $src, $ref, date('c', $t), date('c', $paid), date('c', $first), date('c', $end), $state,
                      $throttled ? date('c', $throttled) : null];
    }
}

// Quelques commandes du jour en attente ou bloquées, pour voir les états
for ($i = 0; $i < 2; $i++) {
    $ref = 'GD-' . date('ymd') . '-PEND0' . $i; $t = $now - mt_rand(60, 1500);
    $orders[] = [$ref, hash('sha256', $ref), '1go-1j', 100, 7, '67700000' . $i, 'mtn', 'pending', date('c', $t), null, null, null, null];
}

/* Insertion en masse par COPY (des milliers de fois plus rapide que des INSERT) */
function bulk(string $table, array $cols, array $rows): void {
    $lines = array_map(fn($r) => implode("\t", array_map(fn($v) => $v === null ? 'NULL' : str_replace(["\\", "\t", "\n"], ['\\\\', '\t', '\n'], (string)$v), $r)), $rows);
    if ($lines && !db()->pgsqlCopyFromArray($table, $lines, "\t", 'NULL', implode(',', $cols))) { throw new RuntimeException("COPY $table"); }
}
bulk('orders', ['reference', 'token_hash', 'plan_id', 'amount', 'fee', 'phone', 'operator', 'status', 'created_at', 'paid_at', 'delivered_at', 'ticket_username', 'ticket_source'], $orders);
bulk('tickets', ['username', 'password', 'plan_id', 'source', 'order_reference', 'created_at', 'assigned_at', 'first_login_at', 'expires_at', 'state', 'throttled_at'], $tickets);
bulk('usage_samples', ['ts', 'username', 'plan_id', 'bytes_down', 'bytes_up'], $samples);

// Stock de secours : 3 tickets libres par forfait
$stock = [];
foreach (array_keys($plans) as $plan) { for ($i = 0; $i < 3; $i++) { $stock[] = [rnd_name(), 'demo', $plan, 'stock', date('c', $now - 86400), 'waiting']; } }
bulk('tickets', ['username', 'password', 'plan_id', 'source', 'created_at', 'state'], $stock);

// Sessions ouvertes pour une partie des tickets en cours, et état du routeur sur 2 jours
$running = all("SELECT username FROM tickets WHERE state = 'running-active'");
$sess = [];
foreach ($running as $i => $r) {
    if (mt_rand(1, 100) > 45) { continue; }
    $sess[] = [$r['username'] . '|demo' . $i . '|x', $r['username'], date('c', $now - mt_rand(300, 7200)), mt_rand(300, 7200), 'true',
               sprintf('AA:BB:CC:%02X:%02X:%02X', mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255)), '172.128.' . mt_rand(1, 15) . '.' . mt_rand(2, 254)];
}
if ($sess) { bulk('usage_sessions', ['id', 'username', 'started_at', 'uptime_s', 'active', 'mac', 'ip'], $sess); }
$health = [];
for ($t = $now - 2 * 86400; $t <= $now; $t += 300) {
    $hh = (int)date('G', $t);
    $health[] = [date('c', $t - $t % 300), 'true', mt_rand(5, 35), 160000000 + mt_rand(0, 40000000), 1073741824, '3d4h',
                 (int)round(count($sess) * $hourW[$hh] / 5.8 * (0.8 + mt_rand(0, 40) / 100))];
}
bulk('router_health', ['ts', 'reachable', 'cpu_load', 'free_memory', 'total_memory', 'uptime', 'active_users'], $health);

printf("Démo : %d commandes, %d tickets, %d relevés de consommation, %d sessions ouvertes.\n",
    count($orders), count($tickets) + count($stock), count($samples), count($sess));
