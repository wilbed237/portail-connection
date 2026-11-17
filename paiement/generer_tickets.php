<?php
/*
 * Générateur de tickets GANDAL (à lancer en ligne de commande).
 *
 *   php generer_tickets.php setup
 *       -> data/mikrotik-setup.rsc : profils hotspot de chaque forfait + expiration automatique.
 *          À importer UNE fois sur le routeur (System > Scripts, ou /import).
 *
 *   php generer_tickets.php <forfait> <nombre>
 *       ex. php generer_tickets.php illim-7j 50
 *       -> ajoute les tickets au stock data/vouchers/<forfait>.txt (vendus en ligne)
 *       -> écrit data/import-<forfait>-<date>.rsc à importer sur le routeur
 *          pour créer ces mêmes utilisateurs dans /ip hotspot user.
 *
 *   php generer_tickets.php stock
 *       -> affiche le nombre de tickets disponibles par forfait.
 *
 * Nécessite RouterOS v7.10 ou plus récent (commande :timestamp).
 */

declare(strict_types=1);
require __DIR__ . '/lib.php';

if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

$plans = cfg()['plans'];
$cmd = $argv[1] ?? '';

function rsc_str(string $s): string {
    return '"' . str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $s) . '"';
}
function profile_name(string $planId): string { return 'gd-' . $planId; }

if ($cmd === 'stock') {
    foreach ($plans as $id => $p) { printf("%-10s %-42s %5d tickets\n", $id, $p['label'], voucher_count($id)); }
    exit(0);
}

if ($cmd === 'setup') {
    $onLogin = ':local id [/ip hotspot user find where name=$user]; '
             . ':local c [/ip hotspot user get $id comment]; '
             . ':if ([:pick $c 0 4] = "new:") do={ '
             . ':local v [:totime [:pick $c 4 [:len $c]]]; '
             . '/ip hotspot user set $id comment=("exp:" . ([:timestamp] + $v)) }';

    $expire = ":local now [:timestamp]\n"
            . ":foreach id in=[/ip hotspot user find where comment~\"^exp:\"] do={\n"
            . "  :local c [/ip hotspot user get \$id comment]\n"
            . "  :local e [:totime [:pick \$c 4 [:len \$c]]]\n"
            . "  :if (\$e < \$now) do={\n"
            . "    :local n [/ip hotspot user get \$id name]\n"
            . "    /ip hotspot active remove [find where user=\$n]\n"
            . "    /ip hotspot user remove \$id\n"
            . "  }\n"
            . "}";

    $out = "# GANDAL : profils hotspot et expiration des tickets\n"
         . "# Généré le " . date('Y-m-d H:i') . ". Nécessite RouterOS 7.10+.\n\n";
    foreach ($plans as $id => $p) {
        $name = profile_name($id);
        $out .= "# {$p['label']}\n";
        $out .= ":if ([:len [/ip hotspot user profile find where name=" . rsc_str($name) . "]] = 0) do={ /ip hotspot user profile add name=" . rsc_str($name) . " }\n";
        $set = "/ip hotspot user profile set [find where name=" . rsc_str($name) . "] shared-users=" . (int)$p['devices']
             . " on-login=" . rsc_str($onLogin);
        if ($p['rate_limit'] !== '') { $set .= ' rate-limit=' . rsc_str($p['rate_limit']); }
        $out .= $set . "\n\n";
    }
    $out .= "# Suppression des tickets expirés, toutes les 5 minutes\n";
    $out .= "/system script remove [find where name=\"gd-expire\"]\n";
    $out .= "/system script add name=\"gd-expire\" policy=read,write,test source=" . rsc_str($expire) . "\n";
    $out .= "/system scheduler remove [find where name=\"gd-expire\"]\n";
    $out .= "/system scheduler add name=\"gd-expire\" interval=5m on-event=\"/system script run gd-expire\"\n";

    $f = data_dir() . '/mikrotik-setup.rsc';
    file_put_contents($f, $out);
    echo "Écrit : $f\n";
    exit(0);
}

if (isset($plans[$cmd])) {
    $n = (int)($argv[2] ?? 0);
    if ($n < 1 || $n > 2000) { fwrite(STDERR, "Nombre de tickets entre 1 et 2000.\n"); exit(1); }
    $p = $plans[$cmd];

    // Identifiants déjà utilisés (stock + vendus), pour éviter les doublons.
    $existing = [];
    foreach (glob(data_dir() . '/vouchers/*') as $f) {
        foreach (file($f, FILE_IGNORE_NEW_LINES) as $l) {
            if (preg_match('/^([a-z0-9]+);/', trim($l), $m)) { $existing[$m[1]] = true; }
            elseif (preg_match('/;[^;]*;[^;]*;([a-z0-9]+)$/', trim($l), $m)) { $existing[$m[1]] = true; }
        }
    }

    $alpha = 'abcdefghjkmnpqrstuvwxyz23456789'; // sans 0/o, 1/l/i
    $stock = ''; $rsc = "# GANDAL : $n tickets « {$p['label']} » générés le " . date('Y-m-d H:i') . "\n";
    $bytes = $p['data_gb'] > 0 ? (int)($p['data_gb'] * 1024 * 1024 * 1024) : 0;
    for ($i = 0; $i < $n; $i++) {
        do {
            $u = 'gd';
            for ($k = 0; $k < 6; $k++) { $u .= $alpha[random_int(0, strlen($alpha) - 1)]; }
        } while (isset($existing[$u]));
        $existing[$u] = true;
        $pw = (string)random_int(100000, 999999);
        $stock .= "$u;$pw\n";
        $rsc .= "/ip hotspot user add name=\"$u\" password=\"$pw\" profile=\"" . profile_name($cmd) . "\""
              . ($bytes ? " limit-bytes-total=$bytes" : '') . " comment=\"new:{$p['validity']}\"\n";
    }
    file_put_contents(data_dir() . "/vouchers/$cmd.txt", $stock, FILE_APPEND | LOCK_EX);
    $f = data_dir() . "/import-$cmd-" . date('Ymd-His') . '.rsc';
    file_put_contents($f, $rsc);
    echo "$n tickets ajoutés au stock « $cmd » (" . voucher_count($cmd) . " disponibles).\n";
    echo "À importer sur le routeur : $f\n";
    exit(0);
}

echo "Usage :\n  php generer_tickets.php setup\n  php generer_tickets.php <forfait> <nombre>\n  php generer_tickets.php stock\n\nForfaits : " . implode(', ', array_keys($plans)) . "\n";
exit(1);
