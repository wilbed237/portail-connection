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
              FROM orders o JOIN plans p ON p.id = o.plan_id WHERE o.status IN ('paid','paid_no_ticket') AND " . portal_cond('o.plan_id') . " ORDER BY o.paid_at");
$stock = all("SELECT p.id, p.label, count(t.username) FILTER (WHERE t.order_reference IS NULL AND t.source = 'stock') AS libres
              FROM plans p LEFT JOIN tickets t ON t.plan_id = p.id WHERE " . portal_cond('p.id') . " GROUP BY p.id, p.label, p.price ORDER BY p.price");
$stateLabels = ['running-active' => 'En cours', 'running' => 'En cours', 'waiting' => 'Pas encore utilisé', 'used' => 'Terminé (durée ou volume)', 'inconnu' => 'Inconnu'];
$states = all("SELECT COALESCE(state, 'inconnu') AS state, count(*) AS n FROM tickets WHERE order_reference IS NOT NULL AND " . portal_cond('plan_id') . " GROUP BY 1 ORDER BY 2 DESC");

// ---- Recherche d'un ticket
$q = trim((string)($_GET['q'] ?? ''));
$found = null; $sessions = []; $used = 0; $daily = [];
if ($q !== '') {
    $found = one('SELECT t.*, p.label, p.data_gb, o.reference, o.amount, o.phone, o.paid_at, o.operator
                  FROM tickets t JOIN plans p ON p.id = t.plan_id LEFT JOIN orders o ON o.reference = t.order_reference
                  WHERE t.username = ? OR t.order_reference = ?', [$q, strtoupper($q)]);
    if ($found) {
        $sessions = all('SELECT started_at, ended_at, uptime_s, bytes_down, bytes_up, active, mac, ip FROM usage_sessions WHERE username = ? ORDER BY started_at DESC LIMIT 50', [$found['username']]);
        $used = (float)val('SELECT COALESCE(sum(bytes_down + bytes_up),0) FROM usage_samples WHERE username = ?', [$found['username']]);
        $start = $found['first_login_at'] ? date('Y-m-d', strtotime($found['first_login_at'])) : null;
        if ($start) {
            $end = min(date('Y-m-d'), date('Y-m-d', strtotime($found['expires_at'] ?? 'now')));
            $per = [];
            foreach (all("SELECT ts::date AS d, sum(bytes_down + bytes_up) AS b FROM usage_samples WHERE username = ? GROUP BY 1", [$found['username']]) as $r) { $per[$r['d']] = (float)$r['b']; }
            foreach (days_between($start, max($start, $end)) as $d) { $daily[] = ['x' => date('d/m', strtotime($d)), 'v' => gb($per[$d] ?? 0), 'tip' => date('d/m/Y', strtotime($d))]; }
        }
    }
}

// ---- Tickets en cours
$planFilter = isset(scope_plans()[$_GET['forfait'] ?? '']) ? $_GET['forfait'] : null;
$running = running_tickets($planFilter);

page_start('Tickets', $me, 'tickets.php', 'Tickets en cours, recherche, stock de secours et clients à relancer');
if ($msg) { echo '<div class="alert" role="status"><b>Relance</b><span>' . h($msg) . '</span></div>'; }
?>
<form class="filters" method="get">
  <label style="flex:1;max-width:420px">Rechercher un ticket ou une référence de paiement<input name="q" value="<?= h($q) ?>" placeholder="gdab12cde ou GD-261118-A1B2C3"></label>
  <button type="submit">Rechercher</button>
</form>

<?php if ($q !== '' && !$found): ?><div class="alert"><b>Introuvable</b><span>Aucun ticket ne correspond à « <?= h($q) ?> ».</span></div><?php endif; ?>
<?php if ($found):
    $p = plans()[$found['plan_id']] ?? null;
    $quota = (int)$found['data_gb'] * 1073741824;
    $rate = $p ? ($found['throttled_at'] ? (int)$p['rate_after'] : (int)$p['rate']) . ' Mb/s' : '–';
?>
<div class="grid">
<?php
$info = '<div class="tiles" style="margin:0 0 14px">'
    . stat_tile('Forfait', $found['label'], null, [], h($rate) . ($found['throttled_at'] ? ' · réduit depuis le ' . h(date('d/m H:i', strtotime($found['throttled_at']))) : ''))
    . stat_tile('État', $stateLabels[$found['state'] ?? 'inconnu'] ?? (string)$found['state'], null, [], $found['first_login_at'] ? '1re connexion le ' . h(date('d/m/Y H:i', strtotime($found['first_login_at']))) : 'jamais connecté')
    . stat_tile('Expire', $found['expires_at'] ? remaining($found['expires_at']) : '–', null, [], $found['expires_at'] ? 'le ' . h(date('d/m/Y H:i', strtotime($found['expires_at']))) : '')
    . stat_tile('Paiement', $found['reference'] ? fcfa($found['amount']) : 'stock', null, [], $found['reference'] ? '<span class="mono">' . h($found['reference']) . '</span> · ' . h($isAdmin ? $found['phone'] : mask_phone($found['phone'])) : 'ticket de secours non vendu')
    . '</div><div style="max-width:520px">' . meter($used, $quota, (bool)$found['throttled_at']) . '</div>';
if ($daily) { $info .= '<h2 style="margin:20px 0 10px">Consommation par jour</h2>' . chart_columns($daily, ['fmt' => fn($v) => go_h($v), 'axis' => fn($v) => num_short($v) . ' Go', 'height' => 150]); }
$srows = '';
foreach ($sessions as $s) {
    $srows .= '<tr><td>' . ($s['started_at'] ? h(date('d/m H:i', strtotime($s['started_at']))) : '') . '</td><td>' . ($s['active'] ? '<span class="tag ok">en cours</span>' : ($s['ended_at'] ? h(date('d/m H:i', strtotime($s['ended_at']))) : ''))
            . '</td><td class="n">' . gmdate('H:i', (int)$s['uptime_s']) . '</td><td class="n">' . gb_h((float)$s['bytes_down']) . '</td><td class="n">' . gb_h((float)$s['bytes_up']) . '</td><td class="mono">' . h((string)$s['mac']) . '</td></tr>';
}
$info .= '<h2 style="margin:20px 0 6px">Sessions</h2><div class="scroll"><table class="cards"><thead><tr><th>Début</th><th>Fin</th><th class="n">Durée</th><th class="n">Réception</th><th class="n">Envoi</th><th>Appareil</th></tr></thead><tbody>'
       . ($srows ?: '<tr><td colspan="6" class="muted">Aucune session relevée.</td></tr>') . '</tbody></table></div>';
echo card('Ticket ' . $found['username'], $info, '<a href="tickets.php">Fermer</a>', 'c12');
?>
</div>
<?php endif; ?>

<?php
$rows = '';
foreach ($running as $t) {
    $p = plans()[$t['plan_id']];
    $rows .= '<tr><td><span class="dot' . ($t['online'] ? '' : ' off') . '"></span><a class="mono" href="?q=' . h(urlencode($t['username'])) . '">' . h($t['username']) . '</a></td>'
           . '<td>' . h($t['label']) . '</td><td style="min-width:200px">' . meter((float)$t['used'], $p['data_gb'] * 1073741824, (bool)$t['throttled_at']) . '</td>'
           . '<td class="n">' . h(gb_h((float)$t['today'])) . '</td><td>' . ($t['throttled_at'] ? '<span class="tag reduit">' . (int)$p['rate_after'] . ' Mb/s, réduit</span>' : (int)$p['rate'] . ' Mb/s') . '</td>'
           . '<td class="n">' . h(remaining($t['expires_at'])) . '</td></tr>';
}
$online = count(array_filter($running, fn($t) => $t['online']));
$opts = ['' => 'Tous les forfaits'] + array_map(fn($p) => $p['label'], scope_plans());
$filter = '<form method="get" class="filters" style="margin:0"><label>Forfait<select name="forfait" data-autosubmit>';
foreach ($opts as $k => $l) { $filter .= '<option value="' . h($k) . '"' . (($planFilter ?? '') === $k ? ' selected' : '') . '>' . h($l) . '</option>'; }
$filter .= '</select></label><noscript><button type="submit">Filtrer</button></noscript></form>';
echo '<div class="grid" id="en-cours">' . card(count($running) . ' tickets en cours · ' . $online . ' connectés', $filter
    . '<div class="scroll" style="margin-top:12px"><table class="cards"><thead><tr><th>Ticket</th><th>Forfait</th><th>Volume utilisé</th><th class="n">Aujourd\'hui</th><th>Débit</th><th class="n">Reste</th></tr></thead><tbody>'
    . ($rows ?: '<tr><td colspan="6" class="muted">Aucun ticket en cours.</td></tr>') . '</tbody></table></div>'
    . '<p class="note" style="margin:10px 0 0"><span class="dot"></span>connecté en ce moment · tri par consommation du jour</p>', '', 'c12') . '</div>';

// ---- Clients à relancer, stock, états
$srows = '';
foreach ($stuck as $o) {
    $srows .= '<tr><td>' . h(date('d/m H:i', strtotime((string)$o['paid_at']))) . '</td><td class="mono">' . h($o['reference']) . '</td><td>' . h($o['label']) . '</td><td>' . h($isAdmin ? $o['phone'] : mask_phone($o['phone']))
            . '</td><td class="n">' . (int)$o['provision_attempts'] . '</td><td style="white-space:normal" class="err">' . h((string)$o['provision_error']) . '</td><td>'
            . ($isAdmin ? '<form method="post">' . csrf_field() . '<input type="hidden" name="reference" value="' . h($o['reference']) . '"><button type="submit">Réessayer</button></form>' : '') . '</td></tr>';
}
$stockMin = cfg()['stock_min'];
echo '<div class="grid">'
   . card('Clients payés en attente de ticket', $srows
        ? '<div class="scroll"><table class="cards"><thead><tr><th>Payé le</th><th>Référence</th><th>Forfait</th><th>Téléphone</th><th class="n">Essais</th><th>Dernière erreur</th><th></th></tr></thead><tbody>' . $srows . '</tbody></table></div>'
        : '<p class="muted" style="margin:0"><span class="tag ok">aucun</span> Tous les clients payés ont leur ticket.</p>', '', 'c12')
   . card('Stock de secours', chart_hbars(array_map(fn($s) => ['label' => $s['label'], 'v' => (float)$s['libres'],
        'value' => (int)$s['libres'] . ((int)$s['libres'] < $stockMin ? ' · bas' : ''), 'sub' => (int)$s['libres'] < $stockMin ? 'sous le seuil de ' . $stockMin : null], $stock))
        . '<p class="note">Tickets créés à l\'avance, remis si le routeur ne répond pas au moment d\'un paiement. Pour en ajouter : <code>php bin/stock.php &lt;forfait&gt; &lt;nombre&gt;</code></p>', '', 'c6')
   . card('Tickets vendus par état', chart_hbars(array_map(fn($s) => ['label' => $stateLabels[$s['state']] ?? $s['state'], 'v' => (float)$s['n'], 'value' => (string)(int)$s['n']], $states)), '', 'c6')
   . '</div>';
page_end();
