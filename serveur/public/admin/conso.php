<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';
$me = require_admin();

[$from, $to] = period_from_query();
$grain = in_array($_GET['par'] ?? '', ['day', 'week', 'month'], true) ? $_GET['par'] : 'day';
$grainLabel = ['day' => 'Jour', 'week' => 'Semaine', 'month' => 'Mois'][$grain];
$range = "ts >= ?::date AND ts < ?::date + interval '1 day'";
$args = [$from, $to];

$series = all("SELECT date_trunc('$grain', ts) AS periode, sum(bytes_down) AS down, sum(bytes_up) AS up,
                      count(DISTINCT username) AS users
               FROM usage_samples WHERE $range GROUP BY 1 ORDER BY 1", $args);
$byPlan = all("SELECT COALESCE(p.label, 'Inconnu') AS label, sum(s.bytes_down + s.bytes_up) AS total, count(DISTINCT s.username) AS users
               FROM usage_samples s LEFT JOIN plans p ON p.id = s.plan_id WHERE $range GROUP BY 1 ORDER BY 2 DESC", $args);
$byHour = all("SELECT extract(hour FROM ts)::int AS h, sum(bytes_down + bytes_up) AS total
               FROM usage_samples WHERE $range GROUP BY 1 ORDER BY 1", $args);
$top = all("SELECT s.username, COALESCE(p.label, '') AS label, sum(s.bytes_down + s.bytes_up) AS total
            FROM usage_samples s LEFT JOIN plans p ON p.id = s.plan_id WHERE $range GROUP BY 1, 2 ORDER BY 3 DESC LIMIT 10", $args);

$fmtPeriod = function (string $ts) use ($grain): string {
    $t = strtotime($ts);
    return match ($grain) { 'day' => date('d/m/Y', $t), 'week' => 'Semaine du ' . date('d/m/Y', $t), 'month' => date('m/Y', $t) };
};

if (($_GET['export'] ?? '') === 'csv') {
    audit($me['user'], 'export consommation', "$from → $to ($grain)");
    csv_out("consommation-$grain-$from-$to.csv", [$grainLabel, 'Réception (octets)', 'Envoi (octets)', 'Total (Go)', 'Utilisateurs'],
        array_map(fn($r) => [$fmtPeriod($r['periode']), $r['down'], $r['up'], round(($r['down'] + $r['up']) / 1073741824, 3), $r['users']], $series));
}

$total = array_sum(array_map(fn($r) => $r['down'] + $r['up'], $series));
$maxHour = max(array_merge([1], array_map(fn($r) => (float)$r['total'], $byHour)));
page_start('Consommation', $me, 'conso.php');
?>
<form class="filters" method="get">
  <label>Du<input type="date" name="du" value="<?= h($from) ?>"></label>
  <label>Au<input type="date" name="au" value="<?= h($to) ?>"></label>
  <label>Regrouper par<select name="par">
    <?php foreach (['day' => 'Jour', 'week' => 'Semaine', 'month' => 'Mois'] as $k => $l): ?><option value="<?= $k ?>"<?= $grain === $k ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?>
  </select></label>
  <button type="submit">Afficher</button>
  <a class="btn secondary" href="?<?= h(http_build_query(array_merge($_GET, ['export' => 'csv']))) ?>">Exporter en CSV</a>
</form>
<p>Total consommé sur la période : <strong><?= bytes_h($total) ?></strong>.</p>

<div class="scroll"><table><thead><tr><th><?= $grainLabel ?></th><th class="n">Réception</th><th class="n">Envoi</th><th class="n">Total</th><th class="n">Utilisateurs</th></tr></thead><tbody>
<?php foreach ($series as $r): ?><tr><td><?= h($fmtPeriod($r['periode'])) ?></td><td class="n"><?= bytes_h($r['down']) ?></td><td class="n"><?= bytes_h($r['up']) ?></td>
<td class="n"><strong><?= bytes_h($r['down'] + $r['up']) ?></strong></td><td class="n"><?= (int)$r['users'] ?></td></tr><?php endforeach; ?>
<?php if (!$series): ?><tr><td colspan="5" class="muted">Aucune consommation relevée sur cette période.</td></tr><?php endif; ?>
</tbody></table></div>

<h2>Par forfait</h2>
<div class="scroll"><table><thead><tr><th>Forfait</th><th class="n">Consommé</th><th class="n">Utilisateurs</th><th class="n">Moyenne par utilisateur</th></tr></thead><tbody>
<?php foreach ($byPlan as $r): ?><tr><td><?= h($r['label']) ?></td><td class="n"><?= bytes_h($r['total']) ?></td><td class="n"><?= (int)$r['users'] ?></td>
<td class="n"><?= bytes_h($r['users'] ? $r['total'] / $r['users'] : 0) ?></td></tr><?php endforeach; ?>
</tbody></table></div>

<h2>Heures de pointe</h2>
<div class="scroll"><table><thead><tr><th>Heure</th><th class="n">Consommé</th><th style="width:50%"></th></tr></thead><tbody>
<?php foreach ($byHour as $r): ?><tr><td><?= sprintf('%02d h', $r['h']) ?></td><td class="n"><?= bytes_h($r['total']) ?></td>
<td><div style="height:10px;border-radius:3px;background:#1E3A5F;width:<?= round($r['total'] * 100 / $maxHour) ?>%"></div></td></tr><?php endforeach; ?>
</tbody></table></div>

<h2>Les 10 tickets qui consomment le plus</h2>
<div class="scroll"><table><thead><tr><th>Ticket</th><th>Forfait</th><th class="n">Consommé</th></tr></thead><tbody>
<?php foreach ($top as $r): ?><tr><td><a href="tickets.php?q=<?= h(urlencode($r['username'])) ?>"><?= h($r['username']) ?></a></td><td><?= h($r['label']) ?></td><td class="n"><?= bytes_h($r['total']) ?></td></tr><?php endforeach; ?>
</tbody></table></div>
<?php page_end();
