<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';
$me = require_admin();

[$from, $to] = period_from_query();
// Regroupement : automatique (zoom selon la longueur de la période) ou imposé par ?par=
$par = in_array($_GET['par'] ?? '', ['hour', 'day', 'week', 'month', 'year'], true) ? $_GET['par'] : '';
$grain = $par ?: zoom_grain($from, $to);
$grainLabel = ['hour' => 'Heure', 'day' => 'Jour', 'week' => 'Semaine', 'month' => 'Mois', 'year' => 'Année'][$grain];
$range = "ts >= ?::date AND ts < ?::date + interval '1 day' AND " . portal_cond('plan_id');
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
            WHERE " . "ts >= ?::date AND ts < ?::date + interval '1 day' AND " . portal_cond('s.plan_id') . " GROUP BY 1, 2, 4 ORDER BY 3 DESC LIMIT 10", $args);

$fmtPeriod = function (string $ts) use ($grain): string {
    $t = strtotime($ts);
    return match ($grain) { 'hour' => date('d/m/Y H\\h', $t), 'day' => date('d/m/Y', $t), 'week' => 'Semaine du ' . date('d/m/Y', $t), 'month' => date('m/Y', $t), 'year' => date('Y', $t) };
};

if (($_GET['export'] ?? '') === 'pdf') { report_conso($from, $to, $grain, $me); }
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

// Série complète (tranches sans consommation comprises) pour le graphique ; un clic zoome sur la tranche
$keep = array_filter(['par' => $par]);
$items = [];
if ($grain === 'week') {
    foreach ($series as $r) { $d = date('Y-m-d', strtotime($r['periode']));
        $items[] = ['x' => date('d/m', strtotime($d)), 'vals' => [gb((float)$r['down']), gb((float)$r['up'])], 'tip' => $fmtPeriod($r['periode']) . ' · ' . (int)$r['users'] . ' utilisateurs',
                    'href' => '?' . http_build_query(['du' => max($d, $from), 'au' => min(date('Y-m-d', strtotime("$d +6 days")), $to)])]; }
} elseif ($grain === 'hour' && $from !== $to) {
    // Heures imposées sur plusieurs jours : pas de série complète, une colonne par heure ayant des relevés
    foreach ($series as $r) { $items[] = ['x' => date('d/m H\h', strtotime($r['periode'])), 'vals' => [gb((float)$r['down']), gb((float)$r['up'])], 'tip' => $fmtPeriod($r['periode']) . ' · ' . (int)$r['users'] . ' utilisateurs']; }
} else {
    $byKey = [];
    foreach ($series as $r) { $byKey[$grain === 'hour' ? (int)date('G', strtotime($r['periode'])) : date('Y-m-d', strtotime($r['periode']))] = $r; }
    $multiYear = substr($from, 0, 4) !== substr($to, 0, 4);
    foreach (zoom_keys($grain, $from, $to) as $k) {
        $r = $byKey[$k] ?? ['down' => 0, 'up' => 0, 'users' => 0];
        $items[] = array_filter(['x' => zoom_label($grain, $k, false, $multiYear), 'vals' => [gb((float)$r['down']), gb((float)$r['up'])],
                    'tip' => zoom_label($grain, $k, true) . ' · ' . (int)$r['users'] . ' utilisateurs', 'href' => zoom_href($grain, $k, $from, $to)], fn($v) => $v !== null);
    }
}
$since = val("SELECT min(ts)::date FROM usage_samples WHERE " . portal_cond('plan_id'));
$peakDay = null; $peakHour = null; $pv = 0;
foreach ($heat as $d => $hs) { foreach ($hs as $hh => $v) { if ($v > $pv) { $pv = $v; $peakDay = $d; $peakHour = $hh; } } }
$jours = [1 => 'lundi', 2 => 'mardi', 3 => 'mercredi', 4 => 'jeudi', 5 => 'vendredi', 6 => 'samedi', 7 => 'dimanche'];

$extra = '<label>Regrouper par<select name="par">';
foreach (['' => 'Automatique', 'hour' => 'Heure', 'day' => 'Jour', 'week' => 'Semaine', 'month' => 'Mois', 'year' => 'Année'] as $k => $l) { $extra .= '<option value="' . $k . '"' . ($par === $k ? ' selected' : '') . '>' . $l . '</option>'; }
$extra .= '</select></label>';

page_start('Consommation', $me, 'conso.php', 'Données consommées ' . h(period_label($from, $to)), export_buttons());
echo period_bar($from, $to, $extra, $keep);
echo zoom_nav($from, $to, $keep, $since ?: null);
?>
<div class="tiles">
  <?= stat_tile('Données consommées', gb_h($total), [pct_change($total, (float)$prev['b']), 'période précédente']) ?>
  <?= stat_tile('Moyenne par jour', gb_h($total / $days), null, array_map(fn($i) => array_sum($i['vals']), array_slice($items, -20))) ?>
  <?= stat_tile('Tickets actifs', (string)$users, [pct_change($users, (float)$prev['u']), 'période précédente']) ?>
  <?= stat_tile('Moyenne par ticket', gb_h($users ? $total / $users : 0), null, [], round($total ? $down * 100 / $total : 0) . ' % en réception') ?>
</div>

<div class="grid">
  <?= card('Consommation par ' . strtolower($grainLabel), chart_stacked($items, [['name' => 'Réception', 'color' => 'var(--s1)'], ['name' => 'Envoi', 'color' => 'var(--s2)']],
        ['fmt' => fn($v) => go_h($v), 'axis' => fn($v) => num_short($v) . ' Go', 'every' => max(1, (int)ceil(count($items) / 12))])
        . data_table([$grainLabel, 'Réception', 'Envoi', 'Total', 'Utilisateurs'], array_map(fn($r) => [$fmtPeriod($r['periode']), gb_h((float)$r['down']), gb_h((float)$r['up']), gb_h($r['down'] + $r['up']), (int)$r['users']], $series)),
        '', 'c12') ?>
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
