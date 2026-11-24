<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';
$me = require_admin();

$month = preg_match('/^\d{4}-\d{2}$/', $_GET['mois'] ?? '') ? $_GET['mois'] : date('Y-m');
$start = $month . '-01';
$base = "FROM orders o JOIN plans p ON p.id = o.plan_id
         WHERE o.status IN " . PAID_STATUSES . " AND o.paid_at >= ?::date AND o.paid_at < ?::date + interval '1 month'";
$args = [$start, $start];

$tot = one("SELECT count(*) AS n, COALESCE(sum(o.amount),0) AS ca, COALESCE(sum(o.fee),0) AS frais $base", $args);
$prevStart = date('Y-m-01', strtotime($start . ' -1 month'));
$prev = one("SELECT count(*) AS n, COALESCE(sum(o.amount),0) AS ca, COALESCE(sum(o.fee),0) AS frais $base", [$prevStart, $prevStart]);
$byPlan = all("SELECT p.label, count(*) AS n, sum(o.amount) AS ca, sum(o.fee) AS frais $base GROUP BY p.label, p.price ORDER BY 3 DESC", $args);
$byOp = all("SELECT o.operator, count(*) AS n, sum(o.amount) AS ca $base GROUP BY o.operator ORDER BY o.operator", $args);
$byDay = all("SELECT date_trunc('day', o.paid_at)::date AS jour, count(*) AS n, sum(o.amount) AS ca, sum(o.fee) AS frais
              $base GROUP BY 1 ORDER BY 1", $args);

if (($_GET['export'] ?? '') === 'csv') {
    audit($me['user'], 'export comptabilité', $month);
    csv_out("comptabilite-$month.csv", ['Jour', 'Ventes', 'Chiffre d\'affaires (FCFA)', 'Frais IZI Spot (FCFA)', 'Net (FCFA)'],
        array_map(fn($d) => [$d['jour'], $d['n'], $d['ca'], $d['frais'], $d['ca'] - $d['frais']], $byDay));
}

// 12 derniers mois : net crédité et frais (le total des deux = chiffre d'affaires)
$months = [];
for ($i = 11; $i >= 0; $i--) { $months[date('Y-m', strtotime($start . " -$i month"))] = ['ca' => 0, 'frais' => 0, 'n' => 0]; }
foreach (all("SELECT to_char(paid_at, 'YYYY-MM') AS m, count(*) AS n, sum(amount) AS ca, sum(fee) AS frais FROM orders
              WHERE status IN " . PAID_STATUSES . " AND paid_at >= ?::date - interval '11 months' AND paid_at < ?::date + interval '1 month' GROUP BY 1", $args) as $r) {
    if (isset($months[$r['m']])) { $months[$r['m']] = ['ca' => (float)$r['ca'], 'frais' => (float)$r['frais'], 'n' => (int)$r['n']]; }
}
$moisFr = ['01' => 'janv.', '02' => 'févr.', '03' => 'mars', '04' => 'avr.', '05' => 'mai', '06' => 'juin', '07' => 'juil.', '08' => 'août', '09' => 'sept.', '10' => 'oct.', '11' => 'nov.', '12' => 'déc.'];
$mLabel = fn(string $m) => $moisFr[substr($m, 5, 2)] . ' ' . substr($m, 0, 4);
$mItems = array_map(fn($m, $v) => ['x' => $moisFr[substr($m, 5, 2)], 'vals' => [$v['ca'] - $v['frais'], $v['frais']], 'tip' => $mLabel($m) . ' · ' . $v['n'] . ' ventes', 'href' => '?mois=' . $m],
    array_keys($months), $months);

// Jours du mois choisi
$perDay = [];
foreach ($byDay as $d) { $perDay[$d['jour']] = $d; }
$end = min(date('Y-m-t', strtotime($start)), date('Y-m-d'));
$dItems = $start <= date('Y-m-d') ? array_map(fn($d) => ['x' => date('d', strtotime($d)), 'v' => (float)($perDay[$d]['ca'] ?? 0),
    'tip' => date('d/m/Y', strtotime($d)) . ' · ' . (int)($perDay[$d]['n'] ?? 0) . ' ventes', 'href' => 'ventes.php?du=' . $d . '&au=' . $d], days_between($start, $end)) : [];

// Solde du compte marchand, lu en direct (lecture limitée : 120 par minute chez IZI Spot)
$bal = pay_api('GET', '/v1/balance');
$balance = $bal['code'] === 200 && is_array($bal['data']) ? $bal['data'] : null;
if (($_GET['export'] ?? '') === 'pdf') { report_compta($month, $balance, $me); }

page_start('Comptabilité', $me, 'compta.php', 'Mois de ' . h($mLabel($month)) . ' · frais IZI Spot de 7 % déduits à chaque paiement', export_buttons(['mois' => $month]));
?>
<form class="filters" method="get">
  <?= seg('mois', [date('Y-m', strtotime($start . ' -1 month')) => '← ' . $mLabel(date('Y-m', strtotime($start . ' -1 month'))), date('Y-m') => 'Mois en cours'], $month) ?>
  <label>Mois<input type="month" name="mois" value="<?= h($month) ?>" max="<?= date('Y-m') ?>"></label>
  <button type="submit">Afficher</button>
</form>

<div class="tiles">
  <?= stat_tile("Chiffre d'affaires", fcfa($tot['ca']), [pct_change((float)$tot['ca'], (float)$prev['ca']), 'mois précédent']) ?>
  <?= stat_tile('Frais IZI Spot', fcfa($tot['frais']), null, [], $tot['ca'] ? round($tot['frais'] * 100 / $tot['ca'], 1) . ' % du chiffre d\'affaires' : '') ?>
  <?= stat_tile('Net crédité', fcfa($tot['ca'] - $tot['frais']), [pct_change($tot['ca'] - $tot['frais'], $prev['ca'] - $prev['frais']), 'mois précédent']) ?>
  <?= stat_tile('Ventes', (string)(int)$tot['n'], [pct_change((float)$tot['n'], (float)$prev['n']), 'mois précédent'], [], 'panier moyen ' . h(fcfa($tot['n'] ? $tot['ca'] / $tot['n'] : 0))) ?>
</div>

<div class="grid">
  <?= card('Net et frais, 12 derniers mois', chart_stacked($mItems, [['name' => 'Net crédité', 'color' => 'var(--s1)'], ['name' => 'Frais IZI Spot', 'color' => 'var(--s2)']],
        ['fmt' => fn($v) => fcfa($v), 'every' => 1, 'hint' => 'Cliquez sur un mois pour afficher sa comptabilité.'])
        . data_table(['Mois', "Chiffre d'affaires", 'Frais', 'Net', 'Ventes'], array_map(fn($m, $v) => [$mLabel($m), fcfa($v['ca']), fcfa($v['frais']), fcfa($v['ca'] - $v['frais']), $v['n']], array_keys($months), $months)),
        '', 'c7') ?>
  <?php
  if ($balance) {
      $b = '<div class="tiles" style="grid-template-columns:repeat(2,minmax(0,1fr));margin:0">'
         . stat_tile('Disponible au retrait', fcfa($balance['available'] ?? 0)) . stat_tile('Retraits en cours', fcfa($balance['pending'] ?? 0))
         . stat_tile('Total encaissé (net)', fcfa($balance['totalEarned'] ?? 0)) . stat_tile('Total retiré', fcfa($balance['totalWithdrawn'] ?? 0))
         . '</div><p class="note" style="margin:12px 0 0">Lu en direct chez IZI Spot. Les retraits (minimum 1 000 FCFA) se font depuis votre espace IZI Spot.</p>';
  } else {
      $b = '<p class="muted">Solde IZI Spot indisponible pour le moment.</p>';
  }
  echo card('Solde IZI Spot', $b, '', 'c5');
  ?>
</div>

<div class="grid">
  <?= card("Chiffre d'affaires par jour", chart_columns($dItems, ['fmt' => fn($v) => fcfa($v), 'every' => 3, 'height' => 180, 'empty' => 'Aucune vente ce mois-ci.', 'hint' => 'Cliquez sur un jour pour voir le détail de ses ventes.'])
        . data_table(['Jour', 'Ventes', "Chiffre d'affaires", 'Frais', 'Net'], array_map(fn($d) => [date('d/m/Y', strtotime($d['jour'])), (int)$d['n'], fcfa($d['ca']), fcfa($d['frais']), fcfa($d['ca'] - $d['frais'])], $byDay)),
        '', 'c12') ?>
</div>

<div class="grid">
  <?= card('Par forfait', chart_hbars(array_map(fn($r) => ['label' => $r['label'], 'v' => (float)$r['ca'], 'value' => fcfa_short((float)$r['ca']),
        'sub' => (int)$r['n'] . ' ventes · ' . ($tot['ca'] ? round($r['ca'] * 100 / $tot['ca']) : 0) . ' % · net ' . fcfa_short($r['ca'] - $r['frais'])], $byPlan), ['empty' => 'Aucune vente ce mois-ci.']), '', 'c7') ?>
  <?= card('Par opérateur', chart_hbars(array_map(fn($r) => ['label' => $r['operator'] === 'mtn' ? 'MTN Mobile Money' : 'Orange Money', 'color' => $r['operator'] === 'mtn' ? 'var(--mtn)' : 'var(--orange)', 'v' => (float)$r['ca'],
        'value' => fcfa_short((float)$r['ca']), 'sub' => (int)$r['n'] . ' ventes · ' . ($tot['ca'] ? round($r['ca'] * 100 / $tot['ca']) : 0) . ' %'], $byOp), ['empty' => 'Aucune vente ce mois-ci.']), '', 'c5') ?>
</div>
<?php page_end();
