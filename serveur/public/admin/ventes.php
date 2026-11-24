<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';
$me = require_admin();

[$from, $to] = period_from_query();
$status = (string)($_GET['statut'] ?? '');
$plan = (string)($_GET['forfait'] ?? '');
$op = (string)($_GET['operateur'] ?? '');

$where = ['o.created_at >= ?::date', "o.created_at < ?::date + interval '1 day'"];
$args = [$from, $to];
if ($status === 'encaisse') { $where[] = 'o.status IN ' . PAID_STATUSES; }
elseif ($status !== '') { $where[] = 'o.status = ?'; $args[] = $status; }
if ($plan !== '' && isset(plans()[$plan])) { $where[] = 'o.plan_id = ?'; $args[] = $plan; }
if (in_array($op, ['mtn', 'orange'], true)) { $where[] = 'o.operator = ?'; $args[] = $op; }
$w = implode(' AND ', $where);

$rows = all("SELECT o.created_at, o.reference, p.label, o.amount, o.fee, o.operator, o.phone, o.status,
                    o.ticket_username, o.ticket_source, o.paid_at
             FROM orders o JOIN plans p ON p.id = o.plan_id WHERE $w ORDER BY o.created_at DESC LIMIT 5000", $args);
$isAdmin = $me['role'] === 'admin';
$phone = fn($p) => $isAdmin ? $p : mask_phone($p);

if (($_GET['export'] ?? '') === 'csv') {
    audit($me['user'], 'export ventes', "$from → $to");
    csv_out("ventes-$from-$to.csv",
        ['Date', 'Référence', 'Forfait', 'Montant', 'Frais IZI Spot', 'Net', 'Opérateur', 'Téléphone', 'État', 'Ticket', 'Source du ticket', 'Payé le'],
        array_map(fn($r) => [$r['created_at'], $r['reference'], $r['label'], $r['amount'], $r['fee'], $r['amount'] - $r['fee'],
            $r['operator'], $phone($r['phone']), $r['status'], $r['ticket_username'], $r['ticket_source'], $r['paid_at']], $rows));
}

// ---- Indicateurs de la période, et de la période précédente de même durée
$isPaid = fn($r) => in_array($r['status'], ['paid', 'complete', 'paid_no_ticket'], true);
$paid = array_values(array_filter($rows, $isPaid));
$ca = array_sum(array_column($paid, 'amount'));
$len = (strtotime($to) - strtotime($from)) / 86400 + 1;
$prevFrom = date('Y-m-d', strtotime($from) - $len * 86400); $prevTo = date('Y-m-d', strtotime($from) - 86400);
$prev = one("SELECT count(*) FILTER (WHERE o.status IN " . PAID_STATUSES . ") AS n, COALESCE(sum(o.amount) FILTER (WHERE o.status IN " . PAID_STATUSES . "),0) AS ca,
                    count(*) FILTER (WHERE o.status NOT IN ('pending')) AS tries
             FROM orders o WHERE $w", array_merge([$prevFrom, $prevTo], array_slice($args, 2)));
$tries = count(array_filter($rows, fn($r) => $r['status'] !== 'pending'));
$success = $tries ? count($paid) / $tries * 100 : null;
$prevSuccess = $prev['tries'] ? $prev['n'] / $prev['tries'] * 100 : null;
$prevLabel = 'période précédente';

// ---- Recettes par jour (ou par semaine au-delà de 62 jours), réparties par opérateur
$weekly = $len > 62;
$bucket = fn($d) => $weekly ? date('Y-m-d', strtotime('monday this week', strtotime($d))) : date('Y-m-d', strtotime($d));
$keys = [];
foreach (days_between($from, $to) as $d) { $keys[$bucket($d)] = ['mtn' => 0, 'orange' => 0, 'n' => 0]; }
foreach ($paid as $r) { $k = $bucket($r['paid_at'] ?? $r['created_at']); if (isset($keys[$k])) { $keys[$k][$r['operator']] += $r['amount']; $keys[$k]['n']++; } }
$items = [];
foreach ($keys as $k => $v) {
    $items[] = ['x' => date('d/m', strtotime($k)), 'vals' => [$v['mtn'], $v['orange']],
                'tip' => ($weekly ? 'Semaine du ' : '') . date('d/m/Y', strtotime($k)) . ' · ' . $v['n'] . ' ventes'];
}
$byOp = ['mtn' => 0, 'orange' => 0];
foreach ($paid as $r) { $byOp[$r['operator']] += $r['amount']; }

// ---- Par forfait et par heure de la journée
$byPlan = [];
foreach ($paid as $r) { $byPlan[$r['label']] ??= ['n' => 0, 'ca' => 0]; $byPlan[$r['label']]['n']++; $byPlan[$r['label']]['ca'] += $r['amount']; }
uasort($byPlan, fn($a, $b) => $b['ca'] <=> $a['ca']);
$byHour = array_fill(0, 24, 0);
foreach ($paid as $r) { $byHour[(int)date('G', strtotime($r['paid_at'] ?? $r['created_at']))]++; }

$keep = array_filter(['statut' => $status, 'forfait' => $plan, 'operateur' => $op]);
$extra = '<label>État<select name="statut"><option value="">Tous</option>';
foreach (['encaisse' => 'Encaissés', 'complete' => 'Livrés', 'paid_no_ticket' => 'Payés sans ticket', 'pending' => 'En attente', 'failed' => 'Refusés', 'expired' => 'Expirés'] as $k => $l) {
    $extra .= '<option value="' . $k . '"' . ($status === $k ? ' selected' : '') . '>' . $l . '</option>';
}
$extra .= '</select></label><label>Forfait<select name="forfait"><option value="">Tous</option>';
foreach (plans() as $id => $p) { $extra .= '<option value="' . h($id) . '"' . ($plan === $id ? ' selected' : '') . '>' . h($p['label']) . '</option>'; }
$extra .= '</select></label><label>Opérateur<select name="operateur"><option value="">Tous</option><option value="mtn"' . ($op === 'mtn' ? ' selected' : '')
        . '>MTN</option><option value="orange"' . ($op === 'orange' ? ' selected' : '') . '>Orange</option></select></label>';

page_start('Ventes', $me, 'ventes.php', 'Ventes ' . h(period_label($from, $to)) . ($isAdmin ? '' : ' · numéros masqués pour le rôle comptable'));
echo period_bar($from, $to, $extra, $keep);
?>
<div class="tiles">
  <?= stat_tile('Recettes', fcfa($ca), [pct_change($ca, (float)$prev['ca']), $prevLabel]) ?>
  <?= stat_tile('Ventes encaissées', (string)count($paid), [pct_change(count($paid), (float)$prev['n']), $prevLabel]) ?>
  <?= stat_tile('Panier moyen', fcfa($paid ? $ca / count($paid) : 0), [pct_change($paid ? $ca / count($paid) : 0, $prev['n'] ? $prev['ca'] / $prev['n'] : 0), $prevLabel]) ?>
  <?= stat_tile('Paiements réussis', $success === null ? '–' : round($success) . ' %', $success !== null && $prevSuccess !== null ? [$success - $prevSuccess, $prevLabel] : null,
       [], count($rows) - count($paid) - count(array_filter($rows, fn($r) => $r['status'] === 'pending')) . ' refusés ou expirés') ?>
</div>

<div class="grid">
  <?= card($weekly ? 'Recettes par semaine' : 'Recettes par jour', chart_stacked($items, [['name' => 'MTN Mobile Money', 'color' => 'var(--s1)'], ['name' => 'Orange Money', 'color' => 'var(--s2)']],
        ['fmt' => fn($v) => fcfa($v), 'every' => max(1, (int)ceil(count($items) / 10))])
        . data_table([$weekly ? 'Semaine du' : 'Jour', 'MTN (FCFA)', 'Orange (FCFA)', 'Ventes'], array_map(fn($k, $v) => [date('d/m/Y', strtotime($k)), $v['mtn'], $v['orange'], $v['n']], array_keys($keys), $keys)),
        'MTN <b>' . h(fcfa($byOp['mtn'])) . '</b> · Orange <b>' . h(fcfa($byOp['orange'])) . '</b>', 'c8') ?>
  <?= card('Recettes par forfait', chart_hbars(array_map(fn($l, $v) => ['label' => $l, 'v' => $v['ca'], 'value' => fcfa_short($v['ca']),
        'sub' => $v['n'] . ' vente' . ($v['n'] > 1 ? 's' : '') . ' · ' . round($v['ca'] * 100 / max(1, $ca)) . ' %'], array_keys($byPlan), $byPlan)), '', 'c4') ?>
</div>

<div class="grid">
  <?= card('Heures d\'achat', chart_columns(array_map(fn($h) => ['x' => $h . 'h', 'v' => $byHour[$h], 'tip' => sprintf('%d h – %d h', $h, $h + 1)], range(0, 23)),
        ['fmt' => fn($v) => $v . ' vente' . ($v > 1 ? 's' : ''), 'every' => 3, 'height' => 160]),
        $paid ? 'Heure la plus active : <b>' . array_search(max($byHour), $byHour) . ' h</b>' : '', 'c12') ?>
</div>

<?php
$list = '';
foreach (array_slice($rows, 0, 500) as $r) {
    $list .= '<tr><td>' . h(date('d/m/Y H:i', strtotime($r['created_at']))) . '</td><td class="mono">' . h($r['reference']) . '</td><td>' . h($r['label']) . '</td>'
           . '<td class="n">' . fcfa($r['amount']) . '</td><td>' . ($r['operator'] === 'mtn' ? 'MTN' : 'Orange') . '</td><td>' . h($phone($r['phone'])) . '</td>'
           . '<td>' . tag($r['status']) . '</td><td class="mono">' . ($r['ticket_username'] ? '<a href="tickets.php?q=' . h(urlencode($r['ticket_username'])) . '">' . h($r['ticket_username']) . '</a>' : '')
           . ($r['ticket_source'] === 'stock' ? ' <span class="note">stock</span>' : '') . '</td></tr>';
}
echo '<div class="grid">' . card(count($rows) . ' commandes', '<div class="scroll"><table><thead><tr><th>Date</th><th>Référence</th><th>Forfait</th><th class="n">Montant</th><th>Opérateur</th><th>Téléphone</th><th>État</th><th>Ticket</th></tr></thead><tbody>'
    . ($list ?: '<tr><td colspan="8" class="muted">Aucune commande sur cette période.</td></tr>') . '</tbody></table></div>'
    . (count($rows) > 500 ? '<p class="note">Seules les 500 premières lignes sont affichées. L\'export CSV contient tout.</p>' : ''),
    '<a class="btn secondary" href="?' . h(http_build_query(array_merge($_GET, ['export' => 'csv']))) . '">Exporter en CSV</a>', 'c12') . '</div>';
page_end();
