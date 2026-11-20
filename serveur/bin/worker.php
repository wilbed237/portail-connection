<?php
/*
 * Tâches de fond (conteneur « worker ») :
 *  - toutes les 15 s : commandes en attente (au cas où le client a fermé la page et que le webhook
 *    n'arrive pas) et tickets à créer pour les clients payés ;
 *  - toutes les 5 min : collecte sur le routeur, débit réduit des illimités au-delà de leur volume, alertes, nettoyage.
 * Usage : php bin/worker.php           (boucle infinie)
 *         php bin/worker.php --once    (un seul passage, pour les tests)
 */

declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';

if (PHP_SAPI !== 'cli') { exit; }
$once = in_array('--once', $argv, true);

for ($i = 0; $i < 30; $i++) {   // attend PostgreSQL au démarrage
    try { migrate(); break; } catch (Throwable $e) { logline('base pas prête : ' . $e->getMessage()); sleep(2); }
}
logline('worker démarré');

function tick(): void {
    $refs = all("SELECT reference FROM orders
                 WHERE (status = 'pending' AND created_at < now() - interval '45 seconds' AND created_at > now() - interval '3 hours'
                        AND (checked_at IS NULL OR checked_at < now() - interval '60 seconds'))
                    OR (status IN ('paid', 'paid_no_ticket') AND (provision_next_at IS NULL OR provision_next_at <= now()))
                 ORDER BY created_at LIMIT 50");
    foreach ($refs as $r) {
        try { order_process($r['reference']); } catch (Throwable $e) { logline('commande ' . $r['reference'] . ' : ' . $e->getMessage()); }
    }
    // Commandes jamais payées depuis plus de 3 heures
    q("UPDATE orders SET status = 'expired' WHERE status = 'pending' AND created_at < now() - interval '3 hours'");
}

function every5min(): void {
    try { collect(); } catch (Throwable $e) { logline('collecte : ' . $e->getMessage()); }
    try { throttle_check(); } catch (Throwable $e) { logline('débit réduit : ' . $e->getMessage()); }
    try { run_alerts(); } catch (Throwable $e) { logline('alertes : ' . $e->getMessage()); }
    q("DELETE FROM rate_limits WHERE window_start < now() - interval '1 day'");
}

$last = 0;
do {
    try { tick(); } catch (Throwable $e) { logline('tick : ' . $e->getMessage()); }
    if (time() - $last >= 300 || $once) { every5min(); $last = time(); }
    if (!$once) { sleep(15); }
} while (!$once);
