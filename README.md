# portail-connection

Portail captif du réseau Wi-Fi **GANDAL** (Département du GI), pour hotspot MikroTik.

## Contenu

| Élément | Rôle |
|---|---|
| `login.html` | Page de connexion du hotspot : connexion, forfaits, publicité de l'Académie Cisco ENSPY, aide |
| `alogin.html` | Après la connexion : « Connexion réussie », puis redirection vers le site demandé |
| `status.html` | Client connecté qui ouvre l'adresse du hotspot : ticket, durée, données, volume restant, boutons « Changer de forfait » et « Se déconnecter » |
| `logout.html` | Après la déconnexion : bilan de la session, « Acheter un nouveau forfait » ou « Me connecter avec un autre ticket » |
| `gandal.css` | Styles communs de `alogin.html`, `status.html` et `logout.html` |
| `logo.jpeg` | Logo GANDAL |
| `img/` | Affiches optimisées de l'Académie Cisco ENSPY |
| `img/logos/` | Logos des moyens de paiement et de contact (voir ci-dessous) |
| `serveur/` | Paiement Mobile Money (IZI Spot), création des tickets sur le MikroTik au moment du paiement, suivi des ventes, comptabilité et consommation (PostgreSQL, espace admin, Grafana). Voir [`serveur/README.md`](serveur/README.md) |

## Forfaits

| Forfait | Prix | Débit |
|---|---|---|
| 1 Go / 1 jour | 100 F | 5 Mb/s |
| 4 Go / 3 jours | 300 F | 5 Mb/s |
| 8 Go / 5 jours | 500 F | 5 Mb/s |
| 20 Go Haut Débit / 1 semaine | 1 000 F | 5 Mb/s |
| Illimité / 1 jour | 250 F | 2 Mb/s |
| Illimité / 1 semaine (25 Go) | 1 500 F | 5 Mb/s jusqu'à 25 Go, puis 2 Mb/s |
| Illimité / 2 semaines (30 Go) | 2 500 F | 5 Mb/s jusqu'à 30 Go, puis 2 Mb/s |
| Illimité / 1 mois (40 Go) | 5 000 F | 5 Mb/s jusqu'à 40 Go, puis 2 Mb/s |

Les forfaits sont définis à deux endroits qui doivent rester identiques :
`PLANS` dans `login.html` (affichage) et `serveur/src/config.php` (prix facturé, profils User Manager).

## Logos

| Fichier | Logo | Source | Utilisé dans la page |
|---|---|---|---|
| `img/logos/mtn.svg` | MTN (logo 2022, ovale noir sur fond jaune) | [Wikimedia Commons](https://commons.wikimedia.org/wiki/File:MTN_2022_logo.svg) | Choix de l'opérateur, bas de page |
| `img/logos/orange.svg` | Orange, logo principal (carré orange, mot « orange ») | [orange.cm](https://www.orange.cm/) (`master-logo.svg`) | Choix de l'opérateur, bas de page |
| `img/logos/whatsapp.svg` | WhatsApp (icône verte et blanche) | [Wikimedia Commons](https://commons.wikimedia.org/wiki/File:WhatsApp.svg) | Boutons « WhatsApp » |

Les fichiers sont des SVG de 0,8 à 2,5 Ko, sans script ni lien externe : ils se chargent depuis le routeur, avant la connexion.
Le logo MTN est posé sur le jaune MTN (`#FFCB05`) par la page ; le carré Orange garde ses angles droits.

Ce sont des marques déposées de leurs propriétaires. Elles indiquent seulement les moyens de paiement acceptés :
ne les modifiez pas et ne laissez pas croire à un partenariat avec MTN, Orange ou WhatsApp.

## Installation

1. Copier `login.html`, `alogin.html`, `status.html`, `logout.html`, `gandal.css`, `logo.jpeg` et le dossier `img/` (avec `img/logos/`)
   dans le dossier `hotspot/` du routeur MikroTik (remplacer les pages par défaut du même nom).
   La déconnexion efface le cookie du hotspot (`erase-cookie=on`) : sans cela, l'appareil serait reconnecté
   automatiquement avec l'ancien ticket (connexion par cookie et par MAC) et ne pourrait pas changer de forfait.
2. Déployer `serveur/` sur un VPS avec Docker en suivant [`serveur/README.md`](serveur/README.md) :
   tunnel WireGuard, User Manager sur le routeur, clés IZI Spot, comptes admin, walled-garden.
3. Dans `login.html`, passer `PAYMENT_MODE` de `'off'` à `'live'` (`'demo'` simule un paiement : jamais sur le vrai routeur) et renseigner `PAYMENT_API_URL`.

Ne jamais publier de clé API dans ce dépôt : les secrets vont uniquement dans `serveur/.env` sur le serveur
(fichier ignoré par Git, modèle dans `serveur/.env.example`).
