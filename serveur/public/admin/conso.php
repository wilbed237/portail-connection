<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';
$me = require_admin();

[$from, $to] = period_from_query();
$grain = in_array($_GET['par'] ?? '', ['day', 'week', 'month'], true) ? $_GET['par'] : 'day';
$grainLabel = ['day' => 'Jour', 'week' => 'Semaine', 'month' => 'Mois'][$grain];
$range = "ts >= ?::date AND ts < ?::date + interval '1 day'";
$args = [$from, $to];

$series = all("SELECT date_trunc('$grain', ts) AS periode, sum(bytes_down) AS down, sum(bytes_up) AS up, count(DISTINCT username) AS users
               FROM usage_samples WHERE $range GROUP BY 1 ORDER BY 1", $args);
$byPlan = all("SELECT COALESCE(p.label, 'Autres tickets (hors GANDAL)') AS label, sum(s.bytes_down + s.bytes_up) AS total, count(DISTINCT s.username) AS users
               FROM usage_samples s LEFT JOIN plans p ON p.id = s.plan_id WHERE $range GROUP BY 1 ORDER BY 2 DESC", $args);
$heat = [];
foreach (all("SELECT extract(isodow FROM ts)::int AS d, extract(hour FROM ts)::int AS h, sum(bytes_down + bytes_up) AS b
              FROM usage_samples WHERE $range GROUP BY 1, 2", $args) as $r) { $heat[(int)$r['d']][(int)$r['h']] = (float)$r['b']; }
$top = all("SELECT s.username, COALESCE(p.label, '') AS label, sum(s.bytes_down + s.bytes_up) AS total, t.state
            FROM usage_samples s LEFT JOIN plans p ON p.id = s.plan_id LEFT JOIN tickets t ON t.username = s.username
            WHERE $range GROUP BY 1, 2, 4 ORDER BY 3 DESC LIMIT 10", $args);

$fmtPeriod = function (string $ts) use ($grain): string {
    $t = strtotime($ts);
    return match ($grain) { 'day' => date('d/m/Y', $t), 'week' => 'Semaine du ' . date('d/m/Y', $t), 'month' => date('m/Y', $t) };
};

if (($_GET['export'] ?? '') === 'csv') {
    audit($me['user'], 'export consommation', "$from → $to ($grain)");
    csv_out("consommation-$grain-$from-$to.csv", [$grainLabel, 'Réception (octets)', 'Envoi (octets)', 'Total (Go)', 'Utilisateurs'],
        array_map(fn($r) => [$fmtPeriod($r['periode']), $r['down'], $r['up'], round(($r['down'] + $r['up']) / 1073741824, 3), $r['users']], $series));
}

// Période précédente de même durée, pour les comparaisons
$len = (strtotime($to) - strtotime($from)) / 86400 + 1;
$prevArgs = [date('Y-m-d', strtotime($from) - $len * 86400), date('Y-m-d', strtotime($from) - 86400)];
$prev = one("SELECT COALESCE(sum(bytes_down + bytes_up),0) AS b, count(DISTINCT username) AS u FROM usage_samples WHERE $range", $prevArgs);
$total = array_sum(array_map(fn($r) => $r['down'] + $r['up'], $series));
$users = (int)val("SELECT count(DISTINCT username) FROM usage_samples WHERE $range", $args);
$down = array_sum(array_column($series, 'down'));
$days = max(1, min($len, (time() - strtotime($from)) / 86400));

// Série complète (jours sans consommation compris) pour le graphique
$byKey = [];
foreach ($series as $r) { $byKey[date('Y-m-d', strtotime($r['periode']))] = $r; }
$items = [];
if ($grain === 'day') {
    foreach (days_between($from, $to) as $d) { $r = $byKey[$d] ?? ['down' => 0, 'up' => 0, 'users' => 0];
        $items[] = ['x' => date('d/m', strtotime($d)), 'vals' => [gb((float)$r['down']), gb((float)$r['up'])], 'tip' => date('d/m/Y', strtotime($d)) . ' · ' . (int)$r['users'] . ' utilisateurs']; }
} else {
    foreach ($series as $r) { $items[] = ['x' => $grain === 'week' ? date('d/m', strtotime($r['periode'])) : date('m/Y', strtotime($r['periode'])),
        'vals' => [gb((float)$r['down']), gb((float)$r['up'])], 'tip' => $fmtPeriod($r['periode']) . ' · ' . (int)$r['users'] . ' utilisateurs']; }
}
$peakDay = null; $peakHour = null; $pv = 0;
foreach ($heat as $d => $hs) { foreach ($hs as $hh => $v) { if ($v > $pv) { $pv = $v; $peakDay = $d; $peakHour = $hh; } } }
$jours = [1 => 'lundi', 2 => 'mardi', 3 => 'mercredi', 4 => 'jeudi', 5 => 'vendredi', 6 => 'samedi', 7 => 'dimanche'];

$keep = ['par' => $grain];
$extra = '<label>Regrouper par<select name="par">';
foreach (['day' => 'Jour', 'week' => 'Semaine', 'month' => 'Mois'] as $k => $l) { $extra .= '<option value="' . $k . '"' . ($grain === $k ? ' selected' : '') . '>' . $l . '</option>'; }
$extra .= '</select></label>';

page_start('Consommation', $me, 'conso.php', 'Données consommées ' . h(period_label($from, $to)));
echo period_bar($from, $to, $extra, $keep);
?>
<div class="tiles">
  <?= stat_tile('Données consommées', gb_h($total), [pct_change($total, (float)$prev['b']), 'période précédente']) ?>
  <?= stat_tile('Moyenne par jour', gb_h($total / $days), null, array_map(fn($i) => array_sum($i['vals']), array_slice($items, -20))) ?>
  <?= stat_tile('Tickets actifs', (string)$users, [pct_change($users, (float)$prev['u']), 'période précédente']) ?>
  <?= stat_tile('Moyenne par ticket', gb_h($users ? $total / $users : 0), null, [], round($total ? $down * 100 / $total : 0) . ' % en réception') ?>
</div>

<div class="grid">
  <?= card('Consommation par ' . strtolower($grainLabel), chart_stacked($items, [['name' => 'Réception', 'color' => 'var(--s1)'], ['name' => 'Envoi', 'color' => 'var(--s2)']],
        ['fmt' => fn($v) => go_h($v), 'axis' => fn($v) => num_short($v) . ' Go', 'every' => max(1, (int)ceil(count($items) / 10))])
        . data_table([$grainLabel, 'Réception', 'Envoi', 'Total', 'Utilisateurs'], array_map(fn($r) => [$fmtPeriod($r['periode']), gb_h((float)$r['down']), gb_h((float)$r['up']), gb_h($r['down'] + $r['up']), (int)$r['users']], $series)),
        '<a class="btn secondary" href="?' . h(http_build_query(array_merge($_GET, ['export' => 'csv']))) . '">Exporter en CSV</a>', 'c12') ?>
</div>

<div class="grid">
  <?= card('Quand le réseau est le plus utilisé', chart_heatmap($heat, fn($v) => gb_h($v)),
        $peakDay ? 'Pic : <b>' . $jours[$peakDay] . ' vers ' . $peakHour . ' h</b>' : '', 'c7') ?>
  <?= card('Par forfait', chart_hbars(array_map(fn($r) => ['label' => $r['label'], 'v' => (float)$r['total'], 'value' => gb_h((float)$r['total']),
        'sub' => (int)$r['users'] . ' tickets · ' . gb_h($r['users'] ? $r['total'] / $r['users'] : 0) . ' en moyenne'], $byPlan)), '', 'c5') ?>
</div>

<?php
$rows = '';
foreach ($top as $i => $r) {
    $rows .= '<tr><td class="n muted">' . ($i + 1) . '</td><td class="mono"><a href="tickets.php?q=' . h(urlencode($r['username'])) . '">' . h($r['username']) . '</a></td><td>' . h($r['label'] ?: 'hors GANDAL') . '</td>'
           . '<td>' . (str_starts_with((string)$r['state'], 'running') ? '<span class="tag ok">en cours</span>' : '<span class="tag">terminé</span>') . '</td>'
           . '<td class="n"><b>' . h(gb_h((float)$r['total'])) . '</b></td><td class="n">' . round($r['total'] * 100 / max(1, $total), 1) . ' %</td></tr>';
}
echo '<div class="grid">' . card('Les 10 tickets qui consomment le plus', $rows
    ? '<div class="scroll"><table><thead><tr><th class="n">#</th><th>Ticket</th><th>Forfait</th><th>État</th><th class="n">Consommé</th><th class="n">Part du total</th></tr></thead><tbody>' . $rows . '</tbody></table></div>'
    : '<p class="muted">Aucune consommation relevée sur cette période.</p>', '', 'c12') . '</div>';
page_end();
