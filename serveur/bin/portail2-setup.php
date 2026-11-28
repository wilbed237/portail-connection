<?php
/*
 * Génère le script RouterOS du 2e portail captif, sur le port ether4 :
 *   1. ether4 sort de bridge1 (portail GI) et reçoit son propre réseau, DHCP et DNS ;
 *   2. serveur hotspot « hotspot2 », pages dans le dossier hotspot2/ ;
 *   3. tickets SÉPARÉS : utilisateurs hotspot locaux limités à hotspot2, sans RADIUS
 *      (un ticket GI ne marche pas sur ce portail, et inversement) ;
 *   4. 1 appareil par ticket et 10 Mb/s PAR TICKET :
 *      une file « hs2-<ticket> » porte l'adresse de sa session ;
 *   5. pare-feu : DNS/DHCP acceptés sur ether4, NAT vers Internet, pas d'accès aux autres réseaux locaux.
 *
 *   php bin/portail2-setup.php [--nom-dns=invite.wifi] [--reseau=10.20.0.0/22] [--debit=10] [--sortie=gandal-portail2.rsc]
 *
 * Rejouable : chaque élément n'est créé que s'il n'existe pas. Pas de secret dans le fichier.
 * Les forfaits du portail 2 ne sont pas créés ici (ils seront définis plus tard).
 */

declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit; }

$opt = getopt('', ['nom-dns:', 'reseau:', 'debit:', 'sortie:']);
$dns = $opt['nom-dns'] ?? 'invite.wifi';
$net = $opt['reseau'] ?? '10.20.0.0/22';
$rate = (int)($opt['debit'] ?? 10);
$out = $opt['sortie'] ?? 'gandal-portail2.rsc';

if (!preg_match('/^[a-z0-9-]+(\.[a-z0-9-]+)+$/', $dns)) { fwrite(STDERR, "--nom-dns invalide (ex. invite.wifi)\n"); exit(1); }
if (!preg_match('#^(\d+\.\d+\.\d+\.\d+)/(\d+)$#', $net, $m) || ip2long($m[1]) === false || $m[2] < 16 || $m[2] > 28) {
    fwrite(STDERR, "--reseau invalide (ex. 10.20.0.0/22, préfixe entre /16 et /28)\n"); exit(1);
}
if ($rate < 1 || $rate > 1000) { fwrite(STDERR, "--debit invalide (Mb/s)\n"); exit(1); }
$prefix = (int)$m[2];
$base = ip2long($m[1]) & (-1 << (32 - $prefix));
$last = $base | ((1 << (32 - $prefix)) - 1);
$network = long2ip($base) . "/$prefix";
$gw = long2ip($base + 1);
$pool = long2ip($base + 10) . '-' . long2ip($last - 1);

// Chaîne RouterOS entre guillemets, sur une ligne : \ " $ échappés, retours à la ligne en \r\n (comme /export)
function rs(string $s): string { return '"' . str_replace(['\\', '"', '$', "\n"], ['\\\\', '\\"', '\\$', '\\r\\n'], $s) . '"'; }

/*
 * Script de débit par ticket, lancé à chaque connexion et toutes les 30 s :
 * pour chaque ticket connecté sur hotspot2, une file simple « hs2-<ticket> » dont la cible
 * est l'adresse de sa session (1 appareil par ticket).
 * Les files des tickets déconnectés sont supprimées.
 */
$sync = <<<ROS
:local srv "hotspot2"
:local rates {"portail2"="{$rate}000000/{$rate}000000";"portail2-5m"="5000000/5000000"}
:local tgt [:toarray ""]
:local cmp [:toarray ""]
:foreach a in=[/ip hotspot active find where server=\$srv] do={
  :local u [/ip hotspot active get \$a user]
  :local ip [:tostr [/ip hotspot active get \$a address]]
  :if ([:typeof (\$tgt->\$u)] = "nothing") do={
    :set (\$tgt->\$u) \$ip
    :set (\$cmp->\$u) (\$ip . "/32")
  } else={
    :set (\$tgt->\$u) ((\$tgt->\$u) . "," . \$ip)
    :set (\$cmp->\$u) ((\$cmp->\$u) . ";" . \$ip . "/32")
  }
}
:foreach u,t in=\$tgt do={
  :local qn ("hs2-" . \$u)
  :local rate (\$rates->"portail2")
  :local hu [/ip hotspot user find where name=\$u and server=\$srv]
  :if ([:len \$hu] > 0) do={ :local pr [/ip hotspot user get [:pick \$hu 0] profile]; :if ([:typeof (\$rates->\$pr)] != "nothing") do={ :set rate (\$rates->\$pr) } }
  :local q [/queue simple find where name=\$qn]
  :if ([:len \$q] = 0) do={
    /queue simple add name=\$qn target=\$t max-limit=\$rate queue=pcq-upload-default/pcq-download-default comment="GANDAL portail2"
  } else={
    :if ([:tostr [/queue simple get \$q target]] != (\$cmp->\$u)) do={ /queue simple set \$q target=\$t }
    :if ([:tostr [/queue simple get \$q max-limit]] != \$rate) do={ /queue simple set \$q max-limit=\$rate }
  }
}
:foreach q in=[/queue simple find where comment="GANDAL portail2"] do={
  :local n [/queue simple get \$q name]
  :if ([:typeof (\$tgt->[:pick \$n 4 [:len \$n]])] = "nothing") do={ /queue simple remove \$q }
}
ROS;

$others = ['172.128.0.0/20', '192.168.1.0/24', '192.168.50.0/24', '192.168.89.0/24', '192.168.216.0/24', '10.66.66.0/24', '10.240.194.0/24'];

$r = "# GANDAL : 2e portail captif sur ether4 (genere le " . date('Y-m-d H:i') . ")\n";
$r .= "# Reseau $network, passerelle $gw, adresse du portail http://$dns, {$rate} Mb/s par ticket, 1 appareil par ticket.\n";
$r .= "# AVANT l'import : creer le dossier hotspot2 dans Files (copie du dossier hotspot pour commencer).\n";
$r .= "# Importer : /import file-name=$out verbose=yes\n\n";

$r .= "# ---- 1. ether4 quitte le portail GI et recoit son propre reseau\n";
$r .= "/interface bridge port remove [find where interface=ether4]\n";
$r .= ":if ([:len [/ip address find where interface=ether4]] = 0) do={ /ip address add address=$gw/$prefix interface=ether4 comment=\"GANDAL portail2\" }\n";
$r .= ":if ([:len [/ip pool find where name=\"pool-portail2\"]] = 0) do={ /ip pool add name=\"pool-portail2\" ranges=$pool }\n";
$r .= ":if ([:len [/ip dhcp-server find where name=\"dhcp-portail2\"]] = 0) do={ /ip dhcp-server add name=\"dhcp-portail2\" interface=ether4 address-pool=\"pool-portail2\" lease-time=1h }\n";
$r .= ":if ([:len [/ip dhcp-server network find where address=\"$network\"]] = 0) do={ /ip dhcp-server network add address=$network gateway=$gw dns-server=$gw comment=\"GANDAL portail2\" }\n";
$r .= "# DNS = routeur seul : sinon l'adresse http://$dns ne se resout pas sur les telephones\n";
$r .= "/ip dns set allow-remote-requests=yes\n\n";

$r .= "# ---- 2. Profil d'utilisateur : 1 appareil par ticket, debit gere par les files hs2-<ticket>\n";
$r .= ":if ([:len [/system script find where name=\"gandal-portail2-debit\"]] = 0) do={ /system script add name=\"gandal-portail2-debit\" policy=read,write source=\"\" }\n";
$r .= "/system script set [find where name=\"gandal-portail2-debit\"] policy=read,write source=" . rs($sync) . "\n";
$prof = "shared-users=1 add-mac-cookie=yes mac-cookie-timeout=1d keepalive-timeout=2m idle-timeout=none status-autorefresh=1m"
      . " on-login=\"/system script run gandal-portail2-debit\" on-logout=\"/system script run gandal-portail2-debit\"";
foreach (['portail2', 'portail2-5m'] as $pn) {   // portail2 : forfaits à {$rate} Mb/s ; portail2-5m : forfaits à 5 Mb/s (le débit est lu par le script)
    $r .= ":if ([:len [/ip hotspot user profile find where name=\"$pn\"]] = 0) do={ /ip hotspot user profile add name=\"$pn\" $prof } else={ /ip hotspot user profile set [find where name=\"$pn\"] $prof }\n";
}
$r .= "# Filet de securite : resynchronise les files toutes les 30 s (deconnexions par expiration, coupures)\n";
$r .= ":if ([:len [/system scheduler find where name=\"gandal-portail2-debit\"]] = 0) do={ /system scheduler add name=\"gandal-portail2-debit\" interval=30s start-time=startup policy=read,write on-event=\"/system script run gandal-portail2-debit\" }\n\n";

$r .= "# ---- 3. Serveur hotspot : sans RADIUS, il n'accepte que ses propres utilisateurs (tickets separes)\n";
$hp = "hotspot-address=$gw dns-name=\"$dns\" html-directory=hotspot2 login-by=cookie,http-chap,http-pap,mac-cookie http-cookie-lifetime=1d use-radius=no";
$r .= ":if ([:len [/ip hotspot profile find where name=\"hsprof2\"]] = 0) do={ /ip hotspot profile add name=\"hsprof2\" $hp } else={ /ip hotspot profile set [find where name=\"hsprof2\"] $hp }\n";
$r .= ":if ([:len [/ip hotspot find where name=\"hotspot2\"]] = 0) do={ /ip hotspot add name=\"hotspot2\" interface=ether4 address-pool=\"pool-portail2\" profile=\"hsprof2\" disabled=no }\n\n";

$r .= "# ---- 4. Pare-feu et NAT\n";
$r .= "# DNS et DHCP des clients d'ether4, avant la regle de refus finale\n";
foreach ([['udp', '53,67', 'GANDAL portail2 DNS DHCP'], ['tcp', '53', 'GANDAL portail2 DNS']] as [$proto, $ports, $c]) {
    $rule = "chain=input in-interface=ether4 protocol=$proto dst-port=$ports action=accept comment=\"$c\"";
    $r .= ":if ([:len [/ip firewall filter find where comment=\"$c\"]] = 0) do={ :if ([:len [/ip firewall filter find where comment=\"GANDAL refus\"]] > 0) do={ /ip firewall filter add $rule place-before=[find where comment=\"GANDAL refus\"] } else={ /ip firewall filter add $rule } }\n";
}
$r .= "# Les clients du portail 2 ne joignent ni le reseau GI ni les autres reseaux locaux (box, VPN, ether5)\n";
$r .= "/ip firewall address-list remove [find where list=\"gandal-reseaux-prives\"]\n";
foreach ($others as $o) { $r .= "/ip firewall address-list add list=\"gandal-reseaux-prives\" address=$o\n"; }
$r .= ":if ([:len [/ip firewall filter find where comment=\"GANDAL portail2 isolement\"]] = 0) do={ /ip firewall filter add chain=forward in-interface=ether4 dst-address-list=\"gandal-reseaux-prives\" action=drop comment=\"GANDAL portail2 isolement\" }\n";
$r .= ":if ([:len [/ip firewall filter find where comment=\"GANDAL portail2 isolement retour\"]] = 0) do={ /ip firewall filter add chain=forward out-interface=ether4 connection-state=new action=drop comment=\"GANDAL portail2 isolement retour\" }\n";
$r .= ":if ([:len [/ip firewall nat find where comment=\"masquerade portail2\"]] = 0) do={ /ip firewall nat add chain=srcnat src-address=$network out-interface=ether1 action=masquerade comment=\"masquerade portail2\" }\n";
$r .= "\n:put \"Portail 2 pret : branchez ether4, puis ouvrez http://$dns depuis un appareil connecte.\"\n";

file_put_contents($out, $r);
fwrite(STDERR, "Script écrit : $out\n");
