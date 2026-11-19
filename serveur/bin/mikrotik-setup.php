<?php
/*
 * Génère le script RouterOS (étape 2) qui prépare le routeur :
 *   1. User Manager + RADIUS pour le hotspot ;
 *   2. un profil User Manager par forfait (validité dès la 1re connexion, volume, débit) ;
 *   3. un compte API restreint au tunnel WireGuard, et l'API REST en HTTPS.
 *
 *   php bin/mikrotik-setup.php --wg-serveur=10.66.66.1 [--sortie=gandal-mikrotik.rsc]
 *
 * Le fichier contient des secrets : ne le publiez pas (*.rsc est ignoré par Git),
 * supprimez-le du routeur après l'import. Nécessite RouterOS 7.10 ou plus récent.
 */

declare(strict_types=1);
require dirname(__DIR__) . '/src/config.php';
require dirname(__DIR__) . '/src/util.php';
if (PHP_SAPI !== 'cli') { exit; }

$opt = getopt('', ['wg-serveur:', 'sortie:']);
$wg = $opt['wg-serveur'] ?? '';
if (!filter_var($wg, FILTER_VALIDATE_IP)) {
    fwrite(STDERR, "Usage : php bin/mikrotik-setup.php --wg-serveur=<adresse WireGuard du serveur, ex. 10.66.66.1> [--sortie=fichier.rsc]\n"); exit(1);
}
$out = $opt['sortie'] ?? 'gandal-mikrotik.rsc';

function rs(string $s): string { return '"' . str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $s) . '"'; }
function secret(int $n): string { $a = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789'; $s = ''; for ($i = 0; $i < $n; $i++) { $s .= $a[random_int(0, strlen($a) - 1)]; } return $s; }

$radius = secret(32);
$apiPass = secret(24);

$r = "# GANDAL : configuration User Manager, forfaits et accès API (généré le " . date('Y-m-d H:i') . ")\n";
$r .= "# Importer : /import gandal-mikrotik.rsc   puis supprimer le fichier du routeur.\n\n";

$r .= "# ---- 1. User Manager et RADIUS (le hotspot authentifie via User Manager, sur le routeur lui-même)\n";
$r .= "/user-manager set enabled=yes\n";
$r .= ":if ([:len [/user-manager router find where name=\"gandal-hotspot\"]] = 0) do={ /user-manager router add name=\"gandal-hotspot\" address=127.0.0.1 shared-secret=" . rs($radius) . " } else={ /user-manager router set [find where name=\"gandal-hotspot\"] address=127.0.0.1 shared-secret=" . rs($radius) . " }\n";
$r .= ":if ([:len [/radius find where comment=\"gandal\"]] = 0) do={ /radius add service=hotspot address=127.0.0.1 secret=" . rs($radius) . " timeout=3s comment=\"gandal\" } else={ /radius set [find where comment=\"gandal\"] service=hotspot address=127.0.0.1 secret=" . rs($radius) . " }\n";
$r .= "/ip hotspot profile set [find] use-radius=yes radius-accounting=yes radius-interim-update=5m\n\n";

$r .= "# ---- 2. Un profil par forfait\n";
foreach (plans() as $id => $p) {
    $name = um_profile($id);
    $bytes = $p['data_gb'] > 0 ? $p['data_gb'] * 1073741824 : 0;
    $rate = $p['rate'] . 'M';
    $r .= "# {$p['label']}\n";
    $r .= ":if ([:len [/user-manager limitation find where name=" . rs($name) . "]] = 0) do={ /user-manager limitation add name=" . rs($name) . " }\n";
    $r .= "/user-manager limitation set [find where name=" . rs($name) . "] transfer-limit=$bytes rate-limit-rx=$rate rate-limit-tx=$rate\n";
    $r .= ":if ([:len [/user-manager profile find where name=" . rs($name) . "]] = 0) do={ /user-manager profile add name=" . rs($name) . " }\n";
    $r .= "/user-manager profile set [find where name=" . rs($name) . "] name-for-users=" . rs($p['label']) . " validity={$p['validity']} starts-when=first-auth price={$p['price']}\n";
    $r .= ":if ([:len [/user-manager profile-limitation find where profile=" . rs($name) . " and limitation=" . rs($name) . "]] = 0) do={ /user-manager profile-limitation add profile=" . rs($name) . " limitation=" . rs($name) . " }\n\n";
}

$r .= "# ---- 3. Compte API minimal, joignable uniquement depuis le serveur par le tunnel WireGuard\n";
$r .= ":if ([:len [/user group find where name=\"gandal-api\"]] = 0) do={ /user group add name=\"gandal-api\" policy=read,write,api,rest-api }\n";
$r .= "/user group set [find where name=\"gandal-api\"] policy=read,write,api,rest-api\n";
$r .= ":if ([:len [/user find where name=\"gandal-api\"]] = 0) do={ /user add name=\"gandal-api\" group=\"gandal-api\" password=" . rs($apiPass) . " address=$wg/32 } else={ /user set [find where name=\"gandal-api\"] group=\"gandal-api\" password=" . rs($apiPass) . " address=$wg/32 }\n";
$r .= "# Certificat auto-signé pour l'API REST en HTTPS (le serveur épingle sa clé publique)\n";
$r .= ":if ([:len [/certificate find where name=\"gandal-rest\"]] = 0) do={ /certificate add name=\"gandal-rest\" common-name=\"gandal-router\" days-valid=3650 key-usage=digital-signature,key-encipherment,tls-server; /certificate sign \"gandal-rest\"; :delay 10s }\n";
$r .= "/ip service set www-ssl certificate=\"gandal-rest\" disabled=no address=$wg/32\n";
$r .= "/ip service set api disabled=yes\n";

file_put_contents($out, $r);
chmod($out, 0600);

fwrite(STDERR, "Script écrit : $out (contient des secrets, ne le publiez pas).\n\n");
fwrite(STDERR, "À mettre dans le fichier .env du serveur :\n");
fwrite(STDERR, "  ROUTER_URL=https://<adresse WireGuard du routeur>\n  ROUTER_USER=gandal-api\n  ROUTER_PASSWORD=$apiPass\n\n");
fwrite(STDERR, "Après l'import, relevez l'empreinte du certificat depuis le serveur (voir README, ROUTER_TLS_PIN).\n");
