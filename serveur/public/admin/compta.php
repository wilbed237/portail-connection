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
$byPlan = all("SELECT p.label, count(*) AS n, sum(o.amount) AS ca, sum(o.fee) AS frais $base GROUP BY p.label, p.price ORDER BY p.price", $args);
$byOp = all("SELECT o.operator, count(*) AS n, sum(o.amount) AS ca $base GROUP BY o.operator ORDER BY o.operator", $args);
$byDay = all("SELECT date_trunc('day', o.paid_at)::date AS jour, count(*) AS n, sum(o.amount) AS ca, sum(o.fee) AS frais
              $base GROUP BY 1 ORDER BY 1", $args);

if (($_GET['export'] ?? '') === 'csv') {
    audit($me['user'], 'export comptabilité', $month);
    csv_out("comptabilite-$month.csv", ['Jour', 'Ventes', 'Chiffre d\'affaires (FCFA)', 'Frais IZI Spot (FCFA)', 'Net (FCFA)'],
        array_map(fn($d) => [$d['jour'], $d['n'], $d['ca'], $d['frais'], $d['ca'] - $d['frais']], $byDay));
}

// Solde du compte marchand, lu en direct (lecture limitée : 120 par minute chez IZI Spot)
$bal = pay_api('GET', '/v1/balance');
$balance = $bal['code'] === 200 && is_array($bal['data']) ? $bal['data'] : null;

page_start('Comptabilité', $me, 'compta.php');
?>
<form class="filters" method="get">
  <label>Mois<input type="month" name="mois" value="<?= h($month) ?>"></label>
  <button type="submit">Afficher</button>
  <a class="btn secondary" href="?mois=<?= h($month) ?>&amp;export=csv">Exporter le mois en CSV</a>
</form>

<div class="kpis">
  <div class="kpi"><span>Chiffre d'affaires</span><b><?= fcfa($tot['ca']) ?></b></div>
  <div class="kpi"><span>Frais IZI Spot (7 %)</span><b><?= fcfa($tot['frais']) ?></b></div>
  <div class="kpi"><span>Net crédité</span><b><?= fcfa($tot['ca'] - $tot['frais']) ?></b></div>
  <div class="kpi"><span>Ventes</span><b><?= (int)$tot['n'] ?></b><div class="note">panier moyen <?= fcfa($tot['n'] ? $tot['ca'] / $tot['n'] : 0) ?></div></div>
</div>
<?php if ($balance): ?>
<h2>Solde IZI Spot</h2>
<div class="kpis">
  <div class="kpi"><span>Disponible au retrait</span><b><?= fcfa($balance['available'] ?? 0) ?></b></div>
  <div class="kpi"><span>Retraits en cours</span><b><?= fcfa($balance['pending'] ?? 0) ?></b></div>
  <div class="kpi"><span>Total encaissé (net)</span><b><?= fcfa($balance['totalEarned'] ?? 0) ?></b></div>
  <div class="kpi"><span>Total retiré</span><b><?= fcfa($balance['totalWithdrawn'] ?? 0) ?></b></div>
</div>
<p class="note">Solde lu en direct chez IZI Spot. Les retraits (minimum 1 000 FCFA) se font depuis votre espace IZI Spot.</p>
<?php else: ?>
<p class="note">Solde IZI Spot indisponible pour le moment.</p>
<?php endif; ?>

<h2>Par forfait</h2>
<div class="scroll"><table><thead><tr><th>Forfait</th><th class="n">Ventes</th><th class="n">Chiffre d'affaires</th><th class="n">Part</th></tr></thead><tbody>
<?php foreach ($byPlan as $r): ?><tr><td><?= h($r['label']) ?></td><td class="n"><?= (int)$r['n'] ?></td><td class="n"><?= fcfa($r['ca']) ?></td>
<td class="n"><?= $tot['ca'] ? round($r['ca'] * 100 / $tot['ca']) : 0 ?> %</td></tr><?php endforeach; ?>
<?php if (!$byPlan): ?><tr><td colspan="4" class="muted">Aucune vente ce mois-ci.</td></tr><?php endif; ?>
</tbody></table></div>

<h2>Par opérateur</h2>
<div class="scroll"><table><thead><tr><th>Opérateur</th><th class="n">Ventes</th><th class="n">Chiffre d'affaires</th></tr></thead><tbody>
<?php foreach ($byOp as $r): ?><tr><td><?= $r['operator'] === 'mtn' ? 'MTN Mobile Money' : 'Orange Money' ?></td><td class="n"><?= (int)$r['n'] ?></td><td class="n"><?= fcfa($r['ca']) ?></td></tr><?php endforeach; ?>
</tbody></table></div>

<h2>Par jour</h2>
<div class="scroll"><table><thead><tr><th>Jour</th><th class="n">Ventes</th><th class="n">Chiffre d'affaires</th><th class="n">Frais</th><th class="n">Net</th></tr></thead><tbody>
<?php foreach ($byDay as $d): ?><tr><td><?= h(date('d/m/Y', strtotime($d['jour']))) ?></td><td class="n"><?= (int)$d['n'] ?></td>
<td class="n"><?= fcfa($d['ca']) ?></td><td class="n"><?= fcfa($d['frais']) ?></td><td class="n"><?= fcfa($d['ca'] - $d['frais']) ?></td></tr><?php endforeach; ?>
</tbody></table></div>
<?php page_end();
