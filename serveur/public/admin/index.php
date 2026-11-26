<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';
$me = require_admin();

// ---- Chiffres du jour, comparés à hier à la même heure
$scope = portal_scope();
$PC = portal_cond('plan_id');   // périmètre : mutualisé, portail GI ou Académie
$sales = fn(string $from, string $to, string $pc = '') => one("SELECT count(*) AS n, COALESCE(sum(amount),0) AS ca FROM orders
    WHERE status IN " . PAID_STATUSES . " AND paid_at >= $from AND paid_at < $to AND " . ($pc ?: $PC));
$today = $sales("date_trunc('day', now())", 'now()');
$yest = $sales("date_trunc('day', now()) - interval '1 day'", "now() - interval '1 day'");
$conso = fn(string $from, string $to, string $pc = '') => (float)val("SELECT COALESCE(sum(bytes_down + bytes_up),0) FROM usage_samples WHERE ts >= $from AND ts < $to AND " . ($pc ?: $PC));
$gbToday = $conso("date_trunc('day', now())", 'now()');
$gbYest = $conso("date_trunc('day', now()) - interval '1 day'", "now() - interval '1 day'");

// ---- Recettes des 30 derniers jours
$days = days_between(date('Y-m-d', strtotime('-29 days')), date('Y-m-d'));
$rev = [];
foreach (all("SELECT paid_at::date AS d, count(*) AS n, sum(amount) AS ca, sum(amount) FILTER (WHERE " . portal_cond('plan_id', 'academie') . ") AS acad FROM orders
              WHERE status IN " . PAID_STATUSES . " AND paid_at >= current_date - 29 AND $PC GROUP BY 1") as $r) { $rev[$r['d']] = $r; }
$revItems = array_map(fn($d) => ['x' => date('d/m', strtotime($d)), 'v' => (float)($rev[$d]['ca'] ?? 0),
    'tip' => strftime_fr($d) . ' · ' . (int)($rev[$d]['n'] ?? 0) . ' ventes', 'hl' => $d === date('Y-m-d'), 'href' => 'ventes.php?du=' . $d . '&au=' . $d], $days);
$rev30 = array_sum(array_column($revItems, 'v'));
$sparkRev = array_slice(array_column($revItems, 'v'), -14);

// ---- Forfaits les plus vendus : aujourd'hui, cette semaine, ce mois
$top = in_array($_GET['top'] ?? '', ['jour', 'semaine', 'mois'], true) ? $_GET['top'] : 'semaine';
$since = ['jour' => "date_trunc('day', now())", 'semaine' => "date_trunc('week', now())", 'mois' => "date_trunc('month', now())"][$top];
$topRows = all("SELECT p.label, count(*) AS n, sum(o.amount) AS ca FROM orders o JOIN plans p ON p.id = o.plan_id
                WHERE o.status IN " . PAID_STATUSES . " AND o.paid_at >= $since AND " . portal_cond('o.plan_id') . " GROUP BY p.label ORDER BY 2 DESC, 3 DESC");
$topN = array_sum(array_column($topRows, 'n'));

// ---- Consommation d'aujourd'hui heure par heure
$byHour = [];
foreach (all("SELECT extract(hour FROM ts)::int AS h, sum(bytes_down + bytes_up) AS b FROM usage_samples
              WHERE ts >= date_trunc('day', now()) AND $PC GROUP BY 1") as $r) { $byHour[(int)$r['h']] = (float)$r['b']; }
$nowH = (int)date('G');
$hourItems = [];
for ($h = 0; $h < 24; $h++) {
    $hourItems[] = ['x' => $h . 'h', 'v' => $h <= $nowH ? gb($byHour[$h] ?? 0) : 0, 'tip' => sprintf('%d h – %d h', $h, $h + 1), 'hl' => $h === $nowH];
}
$peak = $byHour ? array_search(max($byHour), $byHour) : null;

// ---- Réseau et tickets
$health = one('SELECT * FROM router_health ORDER BY ts DESC LIMIT 1');
$users24 = array_map('floatval', array_column(all("SELECT date_trunc('hour', ts) AS h, round(avg(active_users)) AS u FROM router_health
              WHERE ts > now() - interval '24 hours' AND reachable GROUP BY 1 ORDER BY 1"), 'u'));
$running = running_tickets(null, 8);
$nRunning = (int)val("SELECT count(*) FROM tickets WHERE state LIKE 'running%' AND (expires_at IS NULL OR expires_at > now()) AND $PC");
$nReduced = (int)val("SELECT count(*) FROM tickets WHERE state LIKE 'running%' AND throttled_at IS NOT NULL AND (expires_at IS NULL OR expires_at > now()) AND $PC");
$stock = (int)val("SELECT count(*) FROM tickets WHERE source = 'stock' AND order_reference IS NULL AND $PC");
$stuck = (int)val("SELECT count(*) FROM orders WHERE status IN ('paid','paid_no_ticket') AND $PC");
// Connectés : le routeur compte tous les portails ; par portail, ce sont les sessions ouvertes au dernier relevé
$online = fn(string $pc) => (int)val("SELECT count(DISTINCT s.username) FROM usage_sessions s LEFT JOIN tickets t ON t.username = s.username
    WHERE s.active AND s.updated_at > now() - interval '15 minutes' AND " . str_replace('plan_id', 't.plan_id', $pc));
$alerts = all('SELECT key, message, first_at FROM alerts WHERE active ORDER BY first_at');
$last = all('SELECT o.reference, p.label, o.amount, o.operator, o.status, o.created_at, o.ticket_source
             FROM orders o JOIN plans p ON p.id = o.plan_id WHERE ' . portal_cond('o.plan_id') . ' ORDER BY o.created_at DESC LIMIT 8');

function strftime_fr(string $d): string {
    $j = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'];
    return ucfirst($j[(int)date('w', strtotime($d))]) . ' ' . date('d/m', strtotime($d));
}

$routerNote = !$health ? 'aucun relevé' : ($health['reachable']
    ? '<span class="tag ok">routeur joignable</span>'
    : '<span class="tag ko">routeur injoignable</span> depuis ' . h(date('H:i', strtotime($health['ts']))));

page_start($scope === 'tous' ? 'Tableau de bord' : PORTALS[$scope][1], $me, 'index.php', h(strftime_fr(date('Y-m-d'))) . ', ' . date('H:i') . ' · '
    . ($scope === 'tous' ? 'statistiques mutualisées des deux portails' : 'statistiques de ce portail seulement') . ' · comparaisons avec hier à la même heure');

foreach ($alerts as $a) {
    echo '<div class="alert" role="alert"><b>Alerte</b><span>' . h($a['message']) . ' <span class="muted">depuis ' . h(date('d/m H:i', strtotime($a['first_at']))) . '</span></span></div>';
}
if ($stuck) { echo '<div class="alert" role="alert"><b>' . $stuck . ' client(s) payé(s) sans ticket</b><span><a href="tickets.php">Voir et relancer</a></span></div>'; }
?>
<div class="tiles">
  <?= stat_tile('Recettes du jour', fcfa($today['ca']), [pct_change((float)$today['ca'], (float)$yest['ca']), 'hier'], $sparkRev) ?>
  <?= stat_tile('Ventes du jour', (string)(int)$today['n'], [pct_change((float)$today['n'], (float)$yest['n']), 'hier'], [],
       'panier moyen ' . h(fcfa($today['n'] ? $today['ca'] / $today['n'] : 0))) ?>
  <?= stat_tile('Données consommées', gb_h($gbToday), [pct_change($gbToday, $gbYest), 'hier'], [],
       $peak !== null ? 'pic à ' . $peak . ' h' : '', true) ?>
  <?= $scope === 'tous'
      ? stat_tile('Connectés maintenant', $health ? (string)(int)$health['active_users'] : '–', null, $users24, $routerNote)
      : stat_tile('Connectés maintenant', (string)$online($PC), null, [], 'au dernier relevé · ' . $routerNote) ?>
  <?= stat_tile('Tickets en cours', (string)$nRunning, null, [],
       $nReduced . ' en débit réduit · stock de secours : ' . $stock) ?>
</div>

<?php if ($scope === 'tous'):
  // ---- Comparaison des deux portails (vue mutualisée)
  $cmp = '';
  foreach (['gi', 'academie'] as $pk) {
      $pc = portal_cond('plan_id', $pk);
      $d = $sales("date_trunc('day', now())", 'now()', $pc);
      $m = $sales("current_date - 29", 'now()', $pc);
      $cmp .= '<tr><td><a href="?portail=' . $pk . '"><b>' . h(PORTALS[$pk][1]) . '</b></a></td><td class="n">' . fcfa($d['ca']) . '</td><td class="n">' . (int)$d['n'] . '</td>'
            . '<td class="n">' . fcfa($m['ca']) . '</td><td class="n">' . h(gb_h($conso("current_date - 29", 'now()', $pc))) . '</td>'
            . '<td class="n">' . (int)val("SELECT count(*) FROM tickets WHERE state LIKE 'running%' AND (expires_at IS NULL OR expires_at > now()) AND $pc") . '</td>'
            . '<td class="n">' . $online($pc) . '</td></tr>';
  }
  echo '<div class="grid">' . card('Par portail', '<div class="scroll"><table class="cards"><thead><tr><th>Portail</th><th class="n">Recettes du jour</th><th class="n">Ventes du jour</th><th class="n">Recettes 30 j</th><th class="n">Données 30 j</th><th class="n">Tickets en cours</th><th class="n">Connectés</th></tr></thead><tbody>'
      . $cmp . '</tbody></table></div>', 'Touchez un portail pour ouvrir sa page', 'c12') . '</div>';
endif; ?>

<div class="grid">
  <?= card('Recettes des 30 derniers jours', $scope === 'tous'
        ? chart_stacked(array_map(fn($d) => ['x' => date('d/m', strtotime($d)), 'vals' => ['gi' => (float)($rev[$d]['ca'] ?? 0) - (float)($rev[$d]['acad'] ?? 0), 'acad' => (float)($rev[$d]['acad'] ?? 0)],
            'tip' => strftime_fr($d) . ' · ' . (int)($rev[$d]['n'] ?? 0) . ' ventes', 'hl' => $d === date('Y-m-d'), 'href' => 'ventes.php?du=' . $d . '&au=' . $d], $days),
            ['gi' => ['name' => 'Portail GI', 'color' => 'var(--s1)'], 'acad' => ['name' => 'Académie Cisco', 'color' => 'var(--s2)']],
            ['fmt' => fn($v) => fcfa($v), 'axis' => fn($v) => num_short($v), 'every' => 5, 'hint' => 'Cliquez sur un jour pour voir le détail de ses ventes.'])
        : chart_columns($revItems, ['fmt' => fn($v) => fcfa($v), 'axis' => fn($v) => num_short($v), 'every' => 5, 'hint' => 'Cliquez sur un jour pour voir le détail de ses ventes.'])
        . data_table(['Jour', 'Ventes', 'Recettes (FCFA)'], array_map(fn($d) => [date('d/m/Y', strtotime($d)), (int)($rev[$d]['n'] ?? 0), (int)($rev[$d]['ca'] ?? 0)], $days)),
        'Total <b>' . h(fcfa($rev30)) . '</b> · moyenne <b>' . h(fcfa($rev30 / 30)) . '</b> / jour', 'c8') ?>
  <?= card('Forfaits les plus vendus',
        seg('top', ['jour' => "Aujourd'hui", 'semaine' => 'Semaine', 'mois' => 'Mois'], $top)
        . '<div style="margin-top:14px">' . chart_hbars(array_map(fn($r) => ['label' => $r['label'], 'v' => (float)$r['n'],
            'value' => $r['n'] . ' vente' . ($r['n'] > 1 ? 's' : ''), 'sub' => fcfa($r['ca']) . ' · ' . round($r['n'] * 100 / max(1, $topN)) . ' %'], $topRows),
            ['empty' => 'Aucune vente sur cette période.']) . '</div>', '', 'c4') ?>
</div>

<div class="grid">
  <?= card("Consommation d'aujourd'hui, heure par heure", chart_columns($hourItems, ['fmt' => fn($v) => go_h($v), 'axis' => fn($v) => num_short($v) . ' Go', 'every' => 3, 'height' => 180]),
        'Total <b>' . h(gb_h($gbToday)) . '</b> · <a href="conso.php?p=auj">détail</a>', 'c7') ?>
  <?php
  $rows = '';
  foreach ($running as $t) {
      $p = plans()[$t['plan_id']];
      $rows .= '<tr><td><span class="dot' . ($t['online'] ? '' : ' off') . '" title="' . ($t['online'] ? 'connecté' : 'hors ligne') . '"></span><a class="mono" href="tickets.php?q=' . h(urlencode($t['username'])) . '">' . h($t['username']) . '</a>'
            . '<div class="note">' . h($t['label']) . ' · aujourd\'hui ' . h(gb_h((float)$t['today'])) . '</div></td><td style="min-width:160px">' . meter((float)$t['used'], $p['data_gb'] * 1073741824, (bool)$t['throttled_at']) . '</td>'
            . '<td class="n">' . h(remaining($t['expires_at'])) . ($t['throttled_at'] ? '<div><span class="tag reduit">2 Mb/s</span></div>' : '') . '</td></tr>';
  }
  echo card('Tickets en cours', $running
      ? '<div class="scroll"><table class="cards"><thead><tr><th>Ticket</th><th>Volume utilisé</th><th class="n">Reste</th></tr></thead><tbody>' . $rows . '</tbody></table></div>'
        . '<p class="note" style="margin:10px 0 0"><a href="tickets.php#en-cours">Voir les ' . $nRunning . ' tickets en cours</a></p>'
      : '<div class="chart-empty" style="height:160px">Aucun ticket en cours.</div>', '', 'c5');
  ?>
</div>

<?php
$rows = '';
foreach ($last as $o) {
    $rows .= '<tr><td>' . h(date('d/m H:i', strtotime($o['created_at']))) . '</td><td class="mono">' . h($o['reference']) . '</td><td>' . h($o['label']) . '</td>'
          . '<td class="n">' . fcfa($o['amount']) . '</td><td>' . ($o['operator'] === 'mtn' ? 'MTN' : 'Orange') . '</td><td>' . tag($o['status'])
          . ($o['ticket_source'] === 'stock' ? ' <span class="note">stock</span>' : '') . '</td></tr>';
}
echo '<div class="grid">' . card('Dernières commandes', $rows
    ? '<div class="scroll"><table class="cards"><thead><tr><th>Date</th><th>Référence</th><th>Forfait</th><th class="n">Montant</th><th>Opérateur</th><th>État</th></tr></thead><tbody>' . $rows . '</tbody></table></div>'
    : '<p class="muted">Aucune commande pour l\'instant.</p>', '<a href="ventes.php">Toutes les ventes</a>', 'c12') . '</div>';
page_end();
