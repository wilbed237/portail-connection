<?php
/*
 * Stock de secours : tickets créés à l'avance sur le routeur, remis automatiquement
 * si le routeur ne répond pas au moment d'un paiement.
 *   php bin/stock.php etat
 *   php bin/stock.php <forfait> <nombre>      ex. php bin/stock.php illim-7j 10
 * Dans Docker : docker compose exec worker php bin/stock.php etat
 */

declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
if (PHP_SAPI !== 'cli') { exit; }

$plans = plans();
$cmd = $argv[1] ?? '';

if ($cmd === 'etat') {
    foreach ($plans as $id => $p) {
        $n = (int)val("SELECT count(*) FROM tickets WHERE source = 'stock' AND order_reference IS NULL AND plan_id = ?", [$id]);
        printf("%-10s %-32s %4d tickets\n", $id, $p['label'], $n);
    }
    exit(0);
}

if (isset($plans[$cmd])) {
    $n = (int)($argv[2] ?? 0);
    if ($n < 1 || $n > 500) { fwrite(STDERR, "Nombre entre 1 et 500.\n"); exit(1); }
    $ok = 0;
    for ($i = 0; $i < $n; $i++) {
        [$u, $p] = new_credentials();
        $err = create_ticket($u, $p, $cmd);
        if ($err !== '') { fwrite(STDERR, "Arrêt : le routeur a refusé le ticket $u : $err\n"); break; }
        q("INSERT INTO tickets (username, password, plan_id, source) VALUES (?, ?, ?, 'stock')", [$u, $p, $cmd]);
        $ok++;
    }
    echo "$ok ticket(s) ajouté(s) au stock « $cmd ».\n";
    exit($ok === $n ? 0 : 1);
}

echo "Usage :\n  php bin/stock.php etat\n  php bin/stock.php <forfait> <nombre>\nForfaits : " . implode(', ', array_keys($plans)) . "\n";
exit(1);
