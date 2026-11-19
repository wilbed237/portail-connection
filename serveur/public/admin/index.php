<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';
$me = require_admin();

function sales_since(string $sqlStart): array {
    return one("SELECT count(*) AS n, COALESCE(sum(amount),0) AS ca, COALESCE(sum(amount - fee),0) AS net
                FROM orders WHERE status IN " . PAID_STATUSES . " AND paid_at >= $sqlStart");
}
$today = sales_since("date_trunc('day', now())");
$week  = sales_since("date_trunc('week', now())");
$month = sales_since("date_trunc('month', now())");
$gbToday = (float)val("SELECT COALESCE(sum(bytes_down + bytes_up),0) FROM usage_samples WHERE ts >= date_trunc('day', now())");
$health = one('SELECT * FROM router_health ORDER BY ts DESC LIMIT 1');
$stock = (int)val("SELECT count(*) FROM tickets WHERE source = 'stock' AND order_reference IS NULL");
$stuck = (int)val("SELECT count(*) FROM orders WHERE status IN ('paid','paid_no_ticket')");
$alerts = all('SELECT key, message, first_at FROM alerts WHERE active ORDER BY first_at');
$last = all('SELECT o.reference, p.label, o.amount, o.operator, o.status, o.created_at, o.ticket_source
             FROM orders o JOIN plans p ON p.id = o.plan_id ORDER BY o.created_at DESC LIMIT 10');

page_start('Tableau de bord', $me, 'index.php');
?>
<div class="kpis">
  <div class="kpi"><span>Aujourd'hui</span><b><?= fcfa($today['ca']) ?></b><div class="note"><?= (int)$today['n'] ?> ventes</div></div>
  <div class="kpi"><span>Cette semaine</span><b><?= fcfa($week['ca']) ?></b><div class="note"><?= (int)$week['n'] ?> ventes</div></div>
  <div class="kpi"><span>Ce mois</span><b><?= fcfa($month['ca']) ?></b><div class="note">net après frais <?= fcfa($month['net']) ?></div></div>
  <div class="kpi"><span>Consommé aujourd'hui</span><b><?= bytes_h($gbToday) ?></b></div>
  <div class="kpi"><span>Routeur</span>
    <?php if (!$health): ?><b class="muted">aucun relevé</b>
    <?php elseif ($health['reachable']): ?><b><span class="tag ok">joignable</span></b><div class="note"><?= (int)$health['active_users'] ?> connectés · CPU <?= (int)$health['cpu_load'] ?> %</div>
    <?php else: ?><b><span class="tag ko">injoignable</span></b><div class="note"><?= h(date('d/m H:i', strtotime($health['ts']))) ?></div><?php endif; ?>
  </div>
  <div class="kpi"><span>Stock de secours</span><b><?= $stock ?> tickets</b><div class="note"><?= $stuck ?> client(s) payé(s) sans ticket</div></div>
</div>

<?php if ($alerts): ?>
<h2>Alertes en cours</h2>
<div class="scroll"><table><tbody>
<?php foreach ($alerts as $a): ?><tr><td><span class="tag ko">alerte</span></td><td style="white-space:normal"><?= h($a['message']) ?></td><td class="muted">depuis <?= h(date('d/m H:i', strtotime($a['first_at']))) ?></td></tr><?php endforeach; ?>
</tbody></table></div>
<?php endif; ?>

<h2>Dernières commandes</h2>
<div class="scroll"><table>
<thead><tr><th>Date</th><th>Référence</th><th>Forfait</th><th class="n">Montant</th><th>Opérateur</th><th>État</th><th>Ticket</th></tr></thead><tbody>
<?php foreach ($last as $o): ?>
<tr><td><?= h(date('d/m H:i', strtotime($o['created_at']))) ?></td><td><?= h($o['reference']) ?></td><td><?= h($o['label']) ?></td>
<td class="n"><?= fcfa($o['amount']) ?></td><td><?= $o['operator'] === 'mtn' ? 'MTN' : 'Orange' ?></td><td><?= tag($o['status']) ?></td>
<td class="muted"><?= h($o['ticket_source'] === 'stock' ? 'stock de secours' : ($o['ticket_source'] ? 'routeur' : '')) ?></td></tr>
<?php endforeach; ?>
<?php if (!$last): ?><tr><td colspan="7" class="muted">Aucune commande pour l'instant.</td></tr><?php endif; ?>
</tbody></table></div>
<?php page_end();
