<?php
declare(strict_types=1);
require __DIR__ . '/_init.php';
$me = require_admin();
$isAdmin = $me['role'] === 'admin';
$msg = '';

// Relancer la création d'un ticket pour un client payé (réservé au rôle admin)
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();
    if (!$isAdmin) { http_response_code(403); exit('Action réservée au rôle admin.'); }
    $ref = (string)($_POST['reference'] ?? '');
    $o = order_process($ref, true);
    audit($me['user'], 'relance ticket', $ref . ' → ' . ($o['status'] ?? 'introuvable'));
    $msg = $o ? "Commande $ref : " . ($o['status'] === 'complete' ? 'ticket ' . $o['ticket_username'] . ' remis.' : 'toujours sans ticket (' . ($o['provision_error'] ?? '') . ').') : 'Commande introuvable.';
}

$stuck = all("SELECT o.reference, p.label, o.amount, o.phone, o.paid_at, o.provision_attempts, o.provision_error, o.status
              FROM orders o JOIN plans p ON p.id = o.plan_id WHERE o.status IN ('paid','paid_no_ticket') ORDER BY o.paid_at");
$stock = all("SELECT p.id, p.label, count(t.username) FILTER (WHERE t.order_reference IS NULL AND t.source = 'stock') AS libres
              FROM plans p LEFT JOIN tickets t ON t.plan_id = p.id GROUP BY p.id, p.label, p.price ORDER BY p.price");
$states = all("SELECT COALESCE(state, 'inconnu') AS state, count(*) AS n FROM tickets WHERE order_reference IS NOT NULL GROUP BY 1 ORDER BY 2 DESC");

$q = trim((string)($_GET['q'] ?? ''));
$found = null; $sessions = [];
if ($q !== '') {
    $found = one('SELECT t.*, p.label, o.reference, o.amount, o.phone, o.paid_at, o.operator
                  FROM tickets t JOIN plans p ON p.id = t.plan_id LEFT JOIN orders o ON o.reference = t.order_reference
                  WHERE t.username = ? OR t.order_reference = ?', [$q, strtoupper($q)]);
    if ($found) {
        $sessions = all('SELECT started_at, ended_at, uptime_s, bytes_down, bytes_up, active FROM usage_sessions WHERE username = ? ORDER BY started_at DESC LIMIT 50', [$found['username']]);
    }
}

page_start('Tickets', $me, 'tickets.php');
if ($msg) { echo '<p role="status"><strong>' . h($msg) . '</strong></p>'; }
?>
<form class="filters" method="get">
  <label>Ticket ou référence de paiement<input name="q" value="<?= h($q) ?>" placeholder="gdab12cde ou GD-261118-A1B2C3"></label>
  <button type="submit">Rechercher</button>
</form>

<?php if ($q !== '' && !$found): ?><p class="muted">Aucun ticket ne correspond à « <?= h($q) ?> ».</p><?php endif; ?>
<?php if ($found): ?>
<h2>Ticket <?= h($found['username']) ?></h2>
<div class="kpis">
  <div class="kpi"><span>Forfait</span><b style="font-size:16px"><?= h($found['label']) ?></b></div>
  <div class="kpi"><span>État sur le routeur</span><b style="font-size:16px"><?= h($found['state'] ?? 'inconnu') ?></b></div>
  <div class="kpi"><span>Première connexion</span><b style="font-size:16px"><?= $found['first_login_at'] ? h(date('d/m/Y H:i', strtotime($found['first_login_at']))) : 'pas encore' ?></b></div>
  <div class="kpi"><span>Expire le</span><b style="font-size:16px"><?= $found['expires_at'] ? h(date('d/m/Y H:i', strtotime($found['expires_at']))) : '-' ?></b></div>
  <div class="kpi"><span>Paiement</span><b style="font-size:16px"><?= h($found['reference'] ?? 'stock non vendu') ?></b>
    <?php if ($found['reference']): ?><div class="note"><?= fcfa($found['amount']) ?> · <?= h($isAdmin ? $found['phone'] : mask_phone($found['phone'])) ?></div><?php endif; ?></div>
</div>
<h2>Sessions</h2>
<div class="scroll"><table><thead><tr><th>Début</th><th>Fin</th><th class="n">Durée</th><th class="n">Réception</th><th class="n">Envoi</th></tr></thead><tbody>
<?php foreach ($sessions as $s): ?><tr><td><?= $s['started_at'] ? h(date('d/m H:i', strtotime($s['started_at']))) : '' ?></td>
<td><?= $s['active'] ? '<span class="tag ok">en cours</span>' : ($s['ended_at'] ? h(date('d/m H:i', strtotime($s['ended_at']))) : '') ?></td>
<td class="n"><?= gmdate('H:i', (int)$s['uptime_s']) ?></td><td class="n"><?= bytes_h($s['bytes_down']) ?></td><td class="n"><?= bytes_h($s['bytes_up']) ?></td></tr><?php endforeach; ?>
<?php if (!$sessions): ?><tr><td colspan="5" class="muted">Aucune session relevée.</td></tr><?php endif; ?>
</tbody></table></div>
<?php endif; ?>

<h2>Clients payés en attente de ticket</h2>
<div class="scroll"><table><thead><tr><th>Payé le</th><th>Référence</th><th>Forfait</th><th>Téléphone</th><th class="n">Essais</th><th>Dernière erreur</th><th></th></tr></thead><tbody>
<?php foreach ($stuck as $o): ?><tr><td><?= h(date('d/m H:i', strtotime((string)$o['paid_at']))) ?></td><td><?= h($o['reference']) ?></td><td><?= h($o['label']) ?></td>
<td><?= h($isAdmin ? $o['phone'] : mask_phone($o['phone'])) ?></td><td class="n"><?= (int)$o['provision_attempts'] ?></td><td style="white-space:normal" class="err"><?= h($o['provision_error']) ?></td>
<td><?php if ($isAdmin): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="reference" value="<?= h($o['reference']) ?>"><button type="submit">Réessayer</button></form><?php endif; ?></td></tr><?php endforeach; ?>
<?php if (!$stuck): ?><tr><td colspan="7" class="muted">Aucun. Tous les clients payés ont leur ticket.</td></tr><?php endif; ?>
</tbody></table></div>

<h2>Stock de secours</h2>
<p class="note">Tickets créés à l'avance, remis automatiquement si le routeur ne répond pas au moment d'un paiement. Pour en ajouter : <code>php bin/stock.php &lt;forfait&gt; &lt;nombre&gt;</code>.</p>
<div class="scroll"><table><thead><tr><th>Forfait</th><th class="n">Tickets disponibles</th></tr></thead><tbody>
<?php foreach ($stock as $s): ?><tr><td><?= h($s['label']) ?></td><td class="n"><?= (int)$s['libres'] < cfg()['stock_min'] ? '<span class="tag ko">' . (int)$s['libres'] . '</span>' : (int)$s['libres'] ?></td></tr><?php endforeach; ?>
</tbody></table></div>

<h2>Tickets vendus par état</h2>
<div class="scroll"><table><thead><tr><th>État sur le routeur</th><th class="n">Tickets</th></tr></thead><tbody>
<?php foreach ($states as $s): ?><tr><td><?= h($s['state']) ?></td><td class="n"><?= (int)$s['n'] ?></td></tr><?php endforeach; ?>
</tbody></table></div>
<?php page_end();
