# GANDAL : mise en service du paiement en ligne

Le paiement passe par **NotchPay** (MTN MoMo et Orange Money au Cameroun).
Le client paie depuis le portail, le serveur vérifie le paiement auprès de NotchPay
puis lui remet un ticket (identifiant + mot de passe) pris dans un stock généré à l'avance.

```
Téléphone ──> login.html (routeur) ──> paiement/api.php (hébergement) ──> NotchPay
                                              │
                                   data/vouchers/<forfait>.txt  (stock de tickets)
```

La clé API ne va **jamais** dans `login.html` : n'importe qui pourrait la lire.
Elle reste sur le serveur, dans `config.php`.

## 1. Mettre le dossier `paiement/` en ligne

Tout hébergement PHP 7.4+ avec HTTPS convient (mutualisé, VPS…).
Copiez le dossier entier, par exemple vers `https://votre-domaine.com/paiement/`.
Le dossier `data/` doit être accessible en écriture par PHP. Il est protégé
par un `.htaccess` (Apache). Sur Nginx, ajoutez `location /paiement/data { deny all; }`.

## 2. Ajouter la clé API (la seule chose à remplir)

1. Créez un compte sur https://business.notchpay.co et vérifiez-le.
2. *Paramètres > Clés API* : copiez la **clé publique**.
3. Collez-la dans `config.php` → `notchpay_public_key`.
4. (Recommandé) *Paramètres > Webhooks* : ajoutez `https://votre-domaine.com/paiement/webhook.php`,
   cochez les événements `payment.*`, et copiez la clé de signature (`hsk_…`) dans `notchpay_hash_key`.

Commencez avec les clés **Sandbox** (`pk_test_…`). Numéros de test NotchPay :
MTN `670000000`, Orange `690000000` (paiement réussi).
Passez ensuite aux clés **Live**.

## 3. Brancher la page du portail

Dans `login.html`, section CONFIGURATION :

```js
var PAYMENT_MODE = 'live';
var PAYMENT_API_URL = 'https://votre-domaine.com/paiement/api.php';
```

En mode `'demo'` (réglage actuel), la page simule un paiement réussi, sans rien encaisser,
pour que vous puissiez la tester. Ne laissez pas ce mode en production.

## 4. Préparer le routeur MikroTik (RouterOS 7.10 ou plus)

**a. Profils des forfaits et expiration automatique**, une seule fois :

```bash
php generer_tickets.php setup
```

Importez `data/mikrotik-setup.rsc` sur le routeur (glisser-déposer dans *Files* via WinBox,
puis `/import mikrotik-setup.rsc` dans le terminal). Cela crée un profil par forfait
(`gd-illim-7j`…) avec le nombre d'appareils et la limite de débit, et un script qui
supprime les tickets expirés toutes les 5 minutes. La durée commence à la première connexion.

**b. Générer des tickets à vendre**, autant que nécessaire :

```bash
php generer_tickets.php illim-7j 50     # 50 tickets « Illimité / 1 semaine »
php generer_tickets.php stock           # voir le stock restant par forfait
```

Chaque commande ajoute les tickets au stock du serveur **et** produit un fichier
`data/import-<forfait>-<date>.rsc` à importer sur le routeur pour créer les mêmes
utilisateurs. Les deux doivent rester synchronisés : importez toujours le `.rsc` généré.
Si un forfait n'a plus de stock, la page refuse le paiement de ce forfait.
Si le stock s'épuise pendant un paiement, le client est invité à contacter un agent
avec sa référence. Dès que vous remettez du stock, « Retrouver un ticket » lui donne ses identifiants.

**c. Walled-garden** : autoriser le serveur de paiement avant connexion (remplacez le domaine) :

```
/ip hotspot walled-garden add dst-host=votre-domaine.com comment="GANDAL paiement"
/ip hotspot walled-garden ip add dst-host=votre-domaine.com action=accept comment="GANDAL paiement"
/ip hotspot walled-garden add dst-host=pay.notchpay.co comment="Page de paiement NotchPay (repli)"
/ip hotspot walled-garden ip add dst-host=pay.notchpay.co action=accept
/ip hotspot walled-garden add dst-host=fonts.googleapis.com
/ip hotspot walled-garden add dst-host=fonts.gstatic.com
```

**d. Fichiers à copier dans le dossier `hotspot/` du routeur** :
`login.html`, `logo.jpeg`, et le dossier `img/` (4 images, 880 Ko).
Ne copiez **pas** le dossier `paiement/` sur le routeur.

## Fichiers

| Fichier | Rôle |
|---|---|
| `config.php` | Clés NotchPay, prix et caractéristiques des forfaits |
| `api.php` | Lance un paiement et en donne l'état à la page |
| `webhook.php` | Reçoit les confirmations NotchPay (signature vérifiée) |
| `lib.php` | Fonctions communes |
| `generer_tickets.php` | Profils MikroTik, génération des tickets, état du stock |
| `data/orders.json` | Historique des commandes (créé automatiquement) |
| `data/vouchers/vendus.log` | Journal des tickets vendus |

## Sécurité

- Le prix facturé vient de `config.php`, jamais du navigateur.
- Chaque commande a un jeton secret. Sans lui, il faut la référence **et** le numéro qui a payé.
- Le paiement est toujours revérifié auprès de NotchPay, y compris quand le webhook le signale.
- Un ticket n'est attribué qu'une fois, et seulement si le montant reçu correspond.
