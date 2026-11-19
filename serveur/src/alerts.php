<?php
/* Alertes envoyées sur Telegram, sans répétition : une alerte au début, un rappel par heure, un message à la fin. */

declare(strict_types=1);

function alert(string $key, bool $on, string $message, int $repeatMinutes = 60): void {
    $a = one('SELECT active, last_sent_at FROM alerts WHERE key = ?', [$key]);
    if ($on) {
        $isNew = !$a || !$a['active'];
        $due = $a && $a['last_sent_at'] && strtotime($a['last_sent_at']) < time() - $repeatMinutes * 60;
        if ($isNew || $due) {
            telegram(($isNew ? '⚠️ ' : '⏰ Toujours en cours : ') . $message);
            q('INSERT INTO alerts (key, active, first_at, last_sent_at, message) VALUES (?, true, now(), now(), ?)
               ON CONFLICT (key) DO UPDATE SET active = true, last_sent_at = now(), message = EXCLUDED.message,
                 first_at = CASE WHEN alerts.active THEN alerts.first_at ELSE now() END', [$key, $message]);
        }
    } elseif ($a && $a['active']) {
        telegram('✅ Résolu : ' . ($a['message'] ?? $key));
        q('UPDATE alerts SET active = false WHERE key = ?', [$key]);
    }
}

function run_alerts(): void {
    // Routeur injoignable aux deux derniers relevés
    $last = all('SELECT reachable, error FROM router_health ORDER BY ts DESC LIMIT 2');
    $down = count($last) === 2 && !$last[0]['reachable'] && !$last[1]['reachable'];
    alert('router_down', $down, 'GANDAL : le routeur MikroTik ne répond plus depuis 10 minutes (' . ($last[0]['error'] ?? '') . ').');

    // Clients payés sans ticket
    $stuck = (int)val("SELECT count(*) FROM orders WHERE (status = 'paid' AND paid_at < now() - interval '5 minutes') OR status = 'paid_no_ticket'");
    alert('paid_without_ticket', $stuck > 0, "GANDAL : $stuck client(s) ont payé mais n'ont pas encore de ticket. Voir l'espace admin, onglet Tickets.", 30);

    // Le routeur a refusé de créer des tickets (le stock de secours a pris le relais)
    $fallback = all("SELECT provision_error FROM orders WHERE ticket_source = 'stock' AND provision_error IS NOT NULL
                     AND delivered_at > now() - interval '1 hour'");
    alert('router_refuse', count($fallback) > 0, 'GANDAL : ' . count($fallback) . " ticket(s) pris dans le stock de secours dans l'heure. Erreur du routeur : " . ($fallback[0]['provision_error'] ?? ''));

    // Paiements qui échouent en série
    $failed = (int)val("SELECT count(*) FROM orders WHERE status = 'failed' AND created_at > now() - interval '1 hour'");
    alert('payment_failures', $failed >= 5, "GANDAL : $failed paiements refusés dans la dernière heure. Vérifiez le compte IZI Spot.");

    // Stock de secours bas
    $min = cfg()['stock_min'];
    if ($min > 0) {
        $low = [];
        foreach (plans() as $id => $p) {
            $n = (int)val("SELECT count(*) FROM tickets WHERE source = 'stock' AND order_reference IS NULL AND plan_id = ?", [$id]);
            if ($n < $min) { $low[] = "$id ($n)"; }
        }
        alert('stock_low', count($low) > 0, 'GANDAL : stock de secours bas pour ' . implode(', ', $low) . '. Commande : php bin/stock.php <forfait> <nombre>', 24 * 60);
    }

    daily_report();
}

/* Récapitulatif quotidien à l'heure DAILY_REPORT_HOUR */
function daily_report(): void {
    $h = cfg()['daily_report_hour'];
    if ($h === '' || (int)date('G') !== (int)$h) { return; }
    $today = date('Y-m-d');
    if (val("SELECT value FROM kv WHERE key = 'daily_report'") === $today) { return; }
    $v = one("SELECT count(*) AS n, COALESCE(sum(amount), 0) AS ca, COALESCE(sum(amount - fee), 0) AS net
              FROM orders WHERE status = 'complete' AND paid_at >= date_trunc('day', now())");
    $c = one("SELECT COALESCE(sum(bytes_down + bytes_up), 0) AS b, count(DISTINCT username) AS u
              FROM usage_samples WHERE ts >= date_trunc('day', now())");
    telegram(sprintf("📊 GANDAL, %s\nVentes : %d\nChiffre d'affaires : %s (net %s)\nConsommation : %s par %d utilisateurs",
        date('d/m/Y'), $v['n'], fcfa($v['ca']), fcfa($v['net']), bytes_h($c['b']), $c['u']));
    q("INSERT INTO kv (key, value) VALUES ('daily_report', ?) ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value", [$today]);
}
