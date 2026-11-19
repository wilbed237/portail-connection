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

$rows = all('SELECT o.created_at, o.reference, p.label, o.amount, o.fee, o.operator, o.phone, o.status,
                    o.ticket_username, o.ticket_source, o.paid_at
             FROM orders o JOIN plans p ON p.id = o.plan_id WHERE ' . implode(' AND ', $where) . ' ORDER BY o.created_at DESC LIMIT 5000', $args);
$isAdmin = $me['role'] === 'admin';
$phone = fn($p) => $isAdmin ? $p : mask_phone($p);

if (($_GET['export'] ?? '') === 'csv') {
    audit($me['user'], 'export ventes', "$from → $to");
    csv_out("ventes-$from-$to.csv",
        ['Date', 'Référence', 'Forfait', 'Montant', 'Frais IZI Spot', 'Net', 'Opérateur', 'Téléphone', 'État', 'Ticket', 'Source du ticket', 'Payé le'],
        array_map(fn($r) => [$r['created_at'], $r['reference'], $r['label'], $r['amount'], $r['fee'], $r['amount'] - $r['fee'],
            $r['operator'], $phone($r['phone']), $r['status'], $r['ticket_username'], $r['ticket_source'], $r['paid_at']], $rows));
}

$paid = array_filter($rows, fn($r) => in_array($r['status'], ['paid', 'complete', 'paid_no_ticket'], true));
page_start('Ventes', $me, 'ventes.php');
?>
<form class="filters" method="get">
  <label>Du<input type="date" name="du" value="<?= h($from) ?>"></label>
  <label>Au<input type="date" name="au" value="<?= h($to) ?>"></label>
  <label>État<select name="statut">
    <option value="">Tous</option>
    <?php foreach (['encaisse' => 'Encaissés', 'complete' => 'Livrés', 'paid_no_ticket' => 'Payés sans ticket', 'pending' => 'En attente', 'failed' => 'Refusés', 'expired' => 'Expirés'] as $k => $l): ?>
      <option value="<?= $k ?>"<?= $status === $k ? ' selected' : '' ?>><?= $l ?></option>
    <?php endforeach; ?></select></label>
  <label>Forfait<select name="forfait"><option value="">Tous</option>
    <?php foreach (plans() as $id => $p): ?><option value="<?= h($id) ?>"<?= $plan === $id ? ' selected' : '' ?>><?= h($p['label']) ?></option><?php endforeach; ?></select></label>
  <label>Opérateur<select name="operateur"><option value="">Tous</option>
    <option value="mtn"<?= $op === 'mtn' ? ' selected' : '' ?>>MTN</option><option value="orange"<?= $op === 'orange' ? ' selected' : '' ?>>Orange</option></select></label>
  <button type="submit">Filtrer</button>
  <a class="btn secondary" href="?<?= h(http_build_query(array_merge($_GET, ['export' => 'csv']))) ?>">Exporter en CSV</a>
</form>

<p><?= count($rows) ?> commandes, dont <?= count($paid) ?> encaissées pour <strong><?= fcfa(array_sum(array_column($paid, 'amount'))) ?></strong>.
<?= $isAdmin ? '' : '<span class="note">Numéros masqués pour le rôle comptable.</span>' ?></p>

<div class="scroll"><table>
<thead><tr><th>Date</th><th>Référence</th><th>Forfait</th><th class="n">Montant</th><th>Opérateur</th><th>Téléphone</th><th>État</th><th>Ticket</th></tr></thead><tbody>
<?php foreach (array_slice($rows, 0, 500) as $r): ?>
<tr><td><?= h(date('d/m/Y H:i', strtotime($r['created_at']))) ?></td><td><?= h($r['reference']) ?></td><td><?= h($r['label']) ?></td>
<td class="n"><?= fcfa($r['amount']) ?></td><td><?= $r['operator'] === 'mtn' ? 'MTN' : 'Orange' ?></td><td><?= h($phone($r['phone'])) ?></td>
<td><?= tag($r['status']) ?></td><td><?= h($r['ticket_username'] ?? '') ?><?= $r['ticket_source'] === 'stock' ? ' <span class="note">(stock)</span>' : '' ?></td></tr>
<?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="8" class="muted">Aucune commande sur cette période.</td></tr><?php endif; ?>
</tbody></table></div>
<?php if (count($rows) > 500): ?><p class="note">Seules les 500 premières lignes sont affichées. L'export CSV contient tout.</p><?php endif; ?>
<?php page_end();
