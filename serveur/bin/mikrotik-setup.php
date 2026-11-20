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
$r .= "# Si un RADIUS local existe déjà (User Manager déjà utilisé), il est conservé tel quel avec son secret.\n";
$r .= "/user-manager set enabled=yes\n";
$r .= ":if ([:len [/user-manager router find where address=127.0.0.1]] = 0 and [:len [/radius find where address=127.0.0.1]] = 0) do={ /user-manager router add name=\"gandal-hotspot\" address=127.0.0.1 shared-secret=" . rs($radius) . "; /radius add service=hotspot address=127.0.0.1 secret=" . rs($radius) . " timeout=3s comment=\"gandal\" } else={ :if ([:len [/user-manager router find where address=127.0.0.1]] = 0 or [:len [/radius find where address=127.0.0.1]] = 0) do={ :error \"RADIUS local incomplet : verifier /radius et /user-manager router\" } else={ :put \"RADIUS local existant conserve\" } }\n";
$r .= "/ip hotspot profile set [find] use-radius=yes radius-accounting=yes radius-interim-update=5m\n\n";

$r .= "# ---- 2. Un profil par forfait\n";
foreach (plans() as $id => $p) {
    $name = um_profile($id);
    $bytes = $p['data_gb'] > 0 ? $p['data_gb'] * 1073741824 : 0;
    $rate = $p['rate'] . 'M';
    $r .= "# {$p['label']}\n";
    if (plan_throttles($p)) {
        // Illimité avec volume inclus : pas de coupure au volume, et le débit vient de l'attribut
        // Mikrotik-Rate-Limit posé sur chaque utilisateur ({$p['rate']}M, puis {$p['rate_after']}M posé par le serveur au-delà du volume).
        $r .= "# Debit porte par l'utilisateur : {$p['rate']} Mb/s jusqu'a {$p['data_gb']} Go, puis {$p['rate_after']} Mb/s (bascule faite par le serveur)\n";
        $bytes = 0; $rate = '0';
    }
    $r .= ":if ([:len [/user-manager limitation find where name=" . rs($name) . "]] = 0) do={ /user-manager limitation add name=" . rs($name) . " }\n";
    $r .= "/user-manager limitation set [find where name=" . rs($name) . "] transfer-limit=$bytes rate-limit-rx=$rate rate-limit-tx=$rate\n";
    // validity est obligatoire à la création (RouterOS 7.25 la demanderait sinon dans le terminal)
    $prof = "name-for-users=" . rs($p['label']) . " validity={$p['validity']} starts-when=first-auth price={$p['price']}";
    $r .= ":if ([:len [/user-manager profile find where name=" . rs($name) . "]] = 0) do={ /user-manager profile add name=" . rs($name) . " $prof } else={ /user-manager profile set [find where name=" . rs($name) . "] $prof }\n";
    $r .= ":if ([:len [/user-manager profile-limitation find where profile=" . rs($name) . " and limitation=" . rs($name) . "]] = 0) do={ /user-manager profile-limitation add profile=" . rs($name) . " limitation=" . rs($name) . " }\n\n";
}

$r .= "# ---- 3. Compte API minimal, joignable uniquement depuis le serveur par le tunnel WireGuard\n";
$r .= ":if ([:len [/user group find where name=\"gandal-api\"]] = 0) do={ /user group add name=\"gandal-api\" policy=read,write,api,rest-api }\n";
$r .= "/user group set [find where name=\"gandal-api\"] policy=read,write,api,rest-api\n";
$r .= ":if ([:len [/user find where name=\"gandal-api\"]] = 0) do={ /user add name=\"gandal-api\" group=\"gandal-api\" password=" . rs($apiPass) . " address=$wg/32 } else={ /user set [find where name=\"gandal-api\"] group=\"gandal-api\" password=" . rs($apiPass) . " address=$wg/32 }\n";
$r .= "# Certificat auto-signé pour l'API REST en HTTPS (le serveur épingle sa clé publique)\n";
$r .= "# Certificat auto-signe : key-cert-sign est requis pour qu'il se signe lui-meme (sinon \"CA not found\"). Un certificat non signe est recree.\n";
$r .= ":if ([:len [/certificate find where name=\"gandal-rest\" and private-key]] = 0) do={ /certificate remove [find where name=\"gandal-rest\"]; /certificate add name=\"gandal-rest\" common-name=\"gandal-router\" days-valid=3650 key-usage=key-cert-sign,crl-sign,digital-signature,key-encipherment,tls-server; /certificate sign \"gandal-rest\"; :delay 10s }\n";
$r .= "# Port 8443 : le 443 est pris par le service reverse-proxy de RouterOS 7.2x\n";
$r .= "/ip service set www-ssl certificate=\"gandal-rest\" port=8443 disabled=no address=$wg/32\n";
$r .= "/ip service set api,api-ssl disabled=yes\n";
$r .= "# Pare-feu : accès à l'API depuis le serveur seulement, placé avant la règle de refus finale si elle existe\n";
$r .= ":if ([:len [/ip firewall filter find where comment=\"GANDAL API\"]] = 0) do={ :if ([:len [/ip firewall filter find where comment=\"GANDAL refus\"]] > 0) do={ /ip firewall filter add chain=input in-interface=wg-gandal src-address=$wg protocol=tcp dst-port=8443 action=accept comment=\"GANDAL API\" place-before=[find where comment=\"GANDAL refus\"] } else={ /ip firewall filter add chain=input in-interface=wg-gandal src-address=$wg protocol=tcp dst-port=8443 action=accept comment=\"GANDAL API\" } }\n";

// RouterOS affiche mal l'UTF-8 dans le terminal et les noms : le script est écrit en ASCII
$r = strtr($r, ['é'=>'e','è'=>'e','ê'=>'e','ë'=>'e','à'=>'a','â'=>'a','î'=>'i','ï'=>'i','ô'=>'o','û'=>'u','ù'=>'u','ç'=>'c',
    'É'=>'E','È'=>'E','Ê'=>'E','À'=>'A','Î'=>'I','Ô'=>'O','Ç'=>'C','’'=>"'",'«'=>'"','»'=>'"']);
file_put_contents($out, $r);
chmod($out, 0600);

fwrite(STDERR, "Script écrit : $out (contient des secrets, ne le publiez pas).\n\n");
fwrite(STDERR, "À mettre dans le fichier .env du serveur :\n");
fwrite(STDERR, "  ROUTER_URL=https://<adresse WireGuard du routeur>:8443\n  ROUTER_USER=gandal-api\n  ROUTER_PASSWORD=$apiPass\n\n");
fwrite(STDERR, "Après l'import, relevez l'empreinte du certificat depuis le serveur (voir README, ROUTER_TLS_PIN).\n");
