<?php
/*
 * Configuration du serveur GANDAL.
 *
 * Les secrets (clés IZI Spot, mot de passe du routeur, base de données…) viennent
 * des variables d'environnement, définies dans le fichier .env sur le serveur.
 * Ce fichier-ci ne contient aucun secret et peut être publié.
 */

declare(strict_types=1);

function env(string $key, ?string $default = null): string {
    $v = getenv($key);
    if ($v === false || $v === '') { return $default ?? ''; }
    return $v;
}

function cfg(): array {
    static $c = null;
    if ($c !== null) { return $c; }
    $c = [
        'app_url'          => rtrim(env('APP_URL', 'http://localhost'), '/'),
        'allowed_origin'   => env('ALLOWED_ORIGIN', '*'),
        'reference_prefix' => env('REFERENCE_PREFIX', 'GD'),

        // Base PostgreSQL
        'db_dsn'      => env('DB_DSN', 'pgsql:host=db;dbname=gandal'),
        'db_user'     => env('DB_USER', 'gandal'),
        'db_password' => env('DB_PASSWORD'),

        // API de paiement IZI Spot (Mobile Money Cameroun, via PayUnit) — noms de variables imposés par IZI Spot
        'pay_api'         => rtrim(env('PAYMENT_API_BASE', 'https://payment-api.izispot.co'), '/'),
        'pay_public_key'  => env('PAYMENT_PUBLISHABLE_KEY'),
        'pay_secret_key'  => env('PAYMENT_SECRET_KEY'),
        'webhook_secret'  => env('WEBHOOK_SIGNING_SECRET'),

        // Routeur MikroTik (API REST de RouterOS 7, joignable par le tunnel WireGuard)
        'router_url'      => rtrim(env('ROUTER_URL', 'https://10.66.66.2:8443'), '/'),
        'router_user'     => env('ROUTER_USER', 'gandal-api'),
        'router_password' => env('ROUTER_PASSWORD'),
        // Empreinte de la clé publique du certificat du routeur : sha256//xxxxx (voir README)
        'router_tls_pin'  => env('ROUTER_TLS_PIN'),
        'router_timeout'  => (int)env('ROUTER_TIMEOUT', '8'),

        // Alertes Telegram (facultatif)
        'telegram_token'   => env('TELEGRAM_BOT_TOKEN'),
        'telegram_chat_id' => env('TELEGRAM_CHAT_ID'),
        // Seuil d'alerte du stock de secours, par forfait
        'stock_min'        => (int)env('STOCK_MIN', '5'),
        // Heure du récapitulatif quotidien envoyé sur Telegram (0-23, vide = désactivé)
        'daily_report_hour' => env('DAILY_REPORT_HOUR', '21'),

        'grafana_url' => env('GRAFANA_URL', '/grafana/'),
    ];
    return $c;
}

/*
 * Forfaits vendus. L'id doit correspondre à PLANS dans login.html.
 * C'est ce prix qui est facturé, quoi qu'envoie le navigateur.
 *   type      : data (forfait en Go) ou illim (illimité)
 *   validity  : durée à partir de la première connexion (format RouterOS)
 *   midnight  : forfait « 1 jour » : il se termine à 23:59:59 le jour de la première connexion,
 *               quelle que soit l'heure de connexion. Le serveur le coupe à minuit (voir expiry.php) ;
 *               validity reste en filet de sécurité sur le routeur.
 *   data_gb   : volume maximal en Go (0 = sans limite)
 *   rate      : débit maximal en Mb/s, envoi et réception
 *   rate_after: (illimités) débit après épuisement de data_gb, jusqu'à la fin de la durée.
 *               Le ticket n'est alors pas coupé : le serveur le bascule à ce débit (voir throttle.php).
 */
function plans(): array {
    return [
        '1go-1j'    => ['label' => '1 Go / 1 jour',                'type' => 'data',  'price' => 100,  'validity' => '1d',  'data_gb' => 1,  'rate' => 5, 'midnight' => true],
        '4go-3j'    => ['label' => '4 Go / 3 jours',               'type' => 'data',  'price' => 300,  'validity' => '3d',  'data_gb' => 4,  'rate' => 5],
        '8go-5j'    => ['label' => '8 Go / 5 jours',               'type' => 'data',  'price' => 500,  'validity' => '5d',  'data_gb' => 8,  'rate' => 5],
        '20go-7j'   => ['label' => '20 Go Haut Débit / 1 semaine', 'type' => 'data',  'price' => 1000, 'validity' => '7d',  'data_gb' => 20, 'rate' => 5],
        'illim-1j'  => ['label' => 'Illimité / 1 jour',            'type' => 'illim', 'price' => 250,  'validity' => '1d',  'data_gb' => 0,  'rate' => 2, 'midnight' => true],
        'illim-7j'  => ['label' => 'Illimité / 1 semaine (25 Go)', 'type' => 'illim', 'price' => 1500, 'validity' => '7d',  'data_gb' => 25, 'rate' => 5, 'rate_after' => 2],
        'illim-14j' => ['label' => 'Illimité / 2 semaines (30 Go)','type' => 'illim', 'price' => 2500, 'validity' => '14d', 'data_gb' => 30, 'rate' => 5, 'rate_after' => 2],
        'illim-30j' => ['label' => 'Illimité / 1 mois (40 Go)',    'type' => 'illim', 'price' => 5000, 'validity' => '30d', 'data_gb' => 40, 'rate' => 5, 'rate_after' => 2],
    ];
}

/* Nom du profil User Manager d'un forfait */
function um_profile(string $planId): string { return 'gd-' . $planId; }

/* Attribut RADIUS de débit (réception/envoi vus du routeur), posé sur l'utilisateur User Manager */
function rate_attr(int $mbps): string { return "Mikrotik-Rate-Limit:{$mbps}M/{$mbps}M"; }

/* Forfaits qui se terminent à minuit le jour de la première connexion */
function midnight_plans(): array { return array_keys(array_filter(plans(), fn($p) => !empty($p['midnight']))); }

/* Forfait à débit réduit après son volume (illimités avec volume inclus) */
function plan_throttles(array $p): bool { return ($p['rate_after'] ?? 0) > 0 && $p['data_gb'] > 0; }

date_default_timezone_set('Africa/Douala');
