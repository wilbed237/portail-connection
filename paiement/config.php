<?php
/*
 * ============================================================
 *  GANDAL : configuration du paiement en ligne (NotchPay)
 * ============================================================
 *  Les clés se trouvent sur https://business.notchpay.co
 *  (Paramètres > Clés API). Commencez avec les clés « Sandbox »
 *  (pk_test_..., hsk_test_...) puis passez aux clés « Live ».
 *
 *  Ce fichier ne doit JAMAIS être copié sur le routeur MikroTik
 *  ni dans login.html : il reste uniquement sur le serveur.
 */

return [

    // ---- À REMPLIR -------------------------------------------------------
    'notchpay_public_key' => '',      // ex. pk_test_xxxxxxxx ou pk.xxxxxxxx (live)
    'notchpay_hash_key'   => '',      // clé de signature des webhooks (hsk_...), facultative
    // ---------------------------------------------------------------------

    // Adresse publique de ce dossier (sert d'adresse de retour après une page de paiement NotchPay).
    // Laisser '' pour ne pas en envoyer.
    'callback_url' => '',

    // Origine autorisée à appeler l'API (CORS). '*' convient : chaque commande
    // est protégée par un jeton secret propre au client.
    'allowed_origin' => '*',

    // Préfixe des références de commande.
    'reference_prefix' => 'GD',

    // Forfaits vendus. L'id doit correspondre à PLANS dans login.html.
    // C'est CE prix qui est facturé, quel que soit ce qu'envoie le navigateur.
    //   validity   : durée de validité à partir de la 1re connexion (format RouterOS)
    //   data_gb    : volume de données inclus (0 = pas de limite)
    //   devices    : appareils connectés en même temps
    //   rate_limit : limite de débit RouterOS « upload/download », '' = aucune
    'plans' => [
        // Forfaits en Go : 5 Mb/s
        '1go-1j'    => ['label' => '1 Go / 1 jour (5 Mb/s)',                  'price' => 100,  'validity' => '1d',  'data_gb' => 1,  'devices' => 1, 'rate_limit' => '5M/5M'],
        '4go-3j'    => ['label' => '4 Go / 3 jours (5 Mb/s)',                 'price' => 300,  'validity' => '3d',  'data_gb' => 4,  'devices' => 1, 'rate_limit' => '5M/5M'],
        '8go-5j'    => ['label' => '8 Go / 5 jours (5 Mb/s)',                 'price' => 500,  'validity' => '5d',  'data_gb' => 8,  'devices' => 1, 'rate_limit' => '5M/5M'],
        '20go-7j'   => ['label' => '20 Go Haut Débit / 1 semaine (5 Mb/s)',   'price' => 1000, 'validity' => '7d',  'data_gb' => 20, 'devices' => 1, 'rate_limit' => '5M/5M'],
        // Forfaits illimités : 2 Mb/s
        'illim-1j'  => ['label' => 'Illimité / 1 jour (2 Mb/s)',              'price' => 250,  'validity' => '1d',  'data_gb' => 0,  'devices' => 1, 'rate_limit' => '2M/2M'],
        'illim-7j'  => ['label' => 'Illimité / 1 semaine, 25 Go (2 Mb/s)',    'price' => 1500, 'validity' => '7d',  'data_gb' => 25, 'devices' => 1, 'rate_limit' => '2M/2M'],
        'illim-14j' => ['label' => 'Illimité / 2 semaines, 30 Go (2 Mb/s)',   'price' => 2500, 'validity' => '14d', 'data_gb' => 30, 'devices' => 1, 'rate_limit' => '2M/2M'],
        'illim-30j' => ['label' => 'Illimité / 1 mois, 40 Go (2 Mb/s)',       'price' => 5000, 'validity' => '30d', 'data_gb' => 40, 'devices' => 1, 'rate_limit' => '2M/2M'],
    ],
];
