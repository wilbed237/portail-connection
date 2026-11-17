# portail-connection

Portail captif du réseau Wi-Fi **GANDAL** (Département du GI), pour hotspot MikroTik.

## Contenu

| Élément | Rôle |
|---|---|
| `login.html` | Page de connexion du hotspot : connexion, forfaits, publicité de l'Académie Cisco ENSPY, aide |
| `logo.jpeg`, `img/` | Logo et affiches optimisées de l'académie |
| `paiement/` | Serveur PHP de paiement Mobile Money (MTN MoMo, Orange Money) via NotchPay |

## Forfaits

| Forfait | Prix | Débit |
|---|---|---|
| 1 Go / 1 jour | 100 F | 5 Mb/s |
| 4 Go / 3 jours | 300 F | 5 Mb/s |
| 8 Go / 5 jours | 500 F | 5 Mb/s |
| 20 Go Haut Débit / 1 semaine | 1 000 F | 5 Mb/s |
| Illimité / 1 jour | 250 F | 2 Mb/s |
| Illimité / 1 semaine (25 Go) | 1 500 F | 2 Mb/s |
| Illimité / 2 semaines (30 Go) | 2 500 F | 2 Mb/s |
| Illimité / 1 mois (40 Go) | 5 000 F | 2 Mb/s |

Les forfaits sont définis à deux endroits qui doivent rester identiques :
`PLANS` dans `login.html` (affichage) et `paiement/config.php` (prix facturé et limites MikroTik).

## Installation

1. Copier `login.html`, `logo.jpeg` et `img/` dans le dossier `hotspot/` du routeur MikroTik.
2. Mettre `paiement/` sur un hébergement PHP avec HTTPS, puis suivre [`paiement/README.md`](paiement/README.md) :
   clé API NotchPay, profils MikroTik, génération des tickets, walled-garden.
3. Dans `login.html`, passer `PAYMENT_MODE` de `'demo'` à `'live'` et renseigner `PAYMENT_API_URL`.

Ne jamais publier de clé API dans ce dépôt : `paiement/config.php` doit garder ses clés vides ici
et n'être rempli que sur le serveur.
