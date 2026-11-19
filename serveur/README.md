# GANDAL : serveur de paiement, de tickets et de suivi

Ce dossier remplace l'ancien `paiement/`. Il fait trois choses :

1. **Paiement** : le portail captif appelle `api.php`, le client paie par MTN MoMo ou Orange Money via l'API **IZI Spot** (PayUnit).
2. **Ticket créé au paiement** : dès qu'IZI Spot confirme, le serveur crée l'utilisateur dans
   **User Manager** sur le MikroTik (API REST de RouterOS 7, par un tunnel WireGuard) et le remet au client.
3. **Suivi** : ventes, comptabilité, consommation jour / semaine / mois et état du routeur sont gardés
   dans PostgreSQL, visibles dans un espace admin (`/admin/`) et dans Grafana (`/grafana/`), avec alertes Telegram.

```
Client ── portail (routeur) ──HTTPS──> Caddy ─┬─> app (api.php, webhook.php, /admin)
                                              └─> Grafana (lecture seule)
IZI Spot ──webhook signé (xkey)──> webhook.php
worker ──toutes les 15 s──> commandes et tickets à créer
       ──toutes les 5 min──> collecte sur le routeur, alertes
app / worker ──tunnel WireGuard──> MikroTik : User Manager (tickets, sessions, consommation)
                 PostgreSQL : commandes, tickets, consommation, état du routeur, comptes admin
```

## Ce qui se passe lors d'un paiement

| Étape | Détail |
|---|---|
| 1 | Le portail envoie forfait, numéro, opérateur. Le prix vient du serveur (`src/config.php`), jamais du navigateur. |
| 2 | Le serveur crée le paiement chez IZI Spot (`POST /v1/payments`, avec clé d'idempotence) : la demande de validation part sur le téléphone du client. |
| 3 | Le paiement est confirmé par le webhook signé, par le portail qui interroge l'état toutes les 4 s, ou par le worker. Dans tous les cas, le serveur relit le paiement chez IZI Spot (`GET /v1/payments/{id}`) et vérifie montant, devise et référence. |
| 4 | Le serveur crée l'utilisateur et lui affecte le profil du forfait dans User Manager. La durée démarre à la première connexion. |
| 5 | Le client voit son identifiant et son mot de passe, et se connecte d'un bouton. |

Un paiement ne peut donner qu'un ticket : la commande est verrouillée pendant le traitement,
et la base refuse un deuxième ticket pour la même commande (testé avec 12 appels simultanés).

**Si le routeur ne répond pas** au moment du paiement, le client reçoit un ticket du **stock de secours**
(créé à l'avance). S'il n'y a pas de stock, le worker réessaie toutes les 30 secondes, une alerte part
au bout de 5 minutes, et le client retrouve son ticket avec « Retrouver un ticket payé » dès qu'il est créé.

## Installation

### Ce qu'il faut

- Un VPS Linux (Ubuntu 24.04, 1 Go de RAM suffit) avec Docker, un nom de domaine qui pointe dessus.
- Le routeur MikroTik en **RouterOS 7.10 ou plus récent**.
- Un compte marchand IZI Spot : clé publique, clé secrète et secret de signature des webhooks.

### 1. Tunnel WireGuard entre le serveur et le routeur

Le routeur est le plus souvent derrière le NAT du fournisseur d'accès : c'est lui qui ouvre le tunnel vers le serveur.

Sur le serveur :
```bash
sudo apt install wireguard
wg genkey | sudo tee /etc/wireguard/serveur.key | wg pubkey   # affiche la clé publique du serveur
sudo nano /etc/wireguard/wg0.conf
```
```ini
[Interface]
Address = 10.66.66.1/24
ListenPort = 51820
PrivateKey = <contenu de /etc/wireguard/serveur.key>

[Peer]   # le routeur
PublicKey = <clé publique du routeur, affichée à l'étape suivante>
AllowedIPs = 10.66.66.2/32
```
```bash
sudo chmod 600 /etc/wireguard/*; sudo systemctl enable --now wg-quick@wg0
sudo ufw allow 22/tcp; sudo ufw allow 80,443/tcp; sudo ufw allow 51820/udp; sudo ufw enable
```

Sur le routeur (terminal WinBox) :
```
/interface wireguard add name=wg-gandal
/interface wireguard print   ;# noter la clé publique (public-key) et la mettre dans wg0.conf du serveur
/interface wireguard peers add interface=wg-gandal public-key="<clé publique du serveur>" endpoint-address=<IP du VPS> endpoint-port=51820 allowed-address=10.66.66.1/32 persistent-keepalive=25s
/ip address add address=10.66.66.2/24 interface=wg-gandal
/ip firewall filter add chain=input in-interface=wg-gandal src-address=10.66.66.1 protocol=tcp dst-port=443 action=accept comment="GANDAL API" place-before=0
```
Vérifier depuis le serveur : `ping 10.66.66.2`.

### 2. Préparer le routeur (User Manager, forfaits, accès API)

```bash
php bin/mikrotik-setup.php --wg-serveur=10.66.66.1
```
Le script écrit `gandal-mikrotik.rsc` et affiche le mot de passe du compte API à mettre dans `.env`.
Glissez le fichier dans *Files* via WinBox, puis dans le terminal : `/import gandal-mikrotik.rsc`, et supprimez le fichier.

Il configure :
- User Manager comme serveur RADIUS du hotspot (`use-radius=yes`, comptabilité toutes les 5 min) ;
- un profil `gd-<forfait>` par forfait : validité à partir de la première connexion, volume maximal, débit (5 Mb/s pour les forfaits en Go, 2 Mb/s pour les illimités) ;
- un compte `gandal-api` limité aux droits `read, write, api, rest-api` et à l'adresse du serveur dans le tunnel ;
- l'API REST en HTTPS avec un certificat auto-signé, joignable seulement depuis `10.66.66.1`, et l'API en clair désactivée.

Relevez ensuite l'empreinte du certificat du routeur, depuis le serveur :
```bash
openssl s_client -connect 10.66.66.2:443 </dev/null 2>/dev/null | openssl x509 -pubkey -noout \
  | openssl pkey -pubin -outform der | openssl dgst -sha256 -binary | base64
```
et mettez `ROUTER_TLS_PIN=sha256//<résultat>` dans `.env`. Le serveur refusera tout autre certificat.

### 3. Lancer le serveur

```bash
git clone https://github.com/wilbed237/portail-connection.git && cd portail-connection/serveur
cp .env.example .env && chmod 600 .env && nano .env      # remplir toutes les valeurs
docker compose up -d --build
docker compose exec worker php bin/admin.php creer <votre-identifiant> admin
docker compose exec worker php bin/stock.php illim-7j 10  # stock de secours, pour chaque forfait vendu
```
`bin/admin.php` affiche un mot de passe et une clé 2FA à ajouter dans une application d'authentification
(Google Authenticator, Aegis, FreeOTP). Créez un compte `comptable` pour la personne qui tient les comptes :
elle voit tout en lecture seule, numéros masqués.

### 4. Brancher le portail et IZI Spot

- `login.html` : `PAYMENT_MODE = 'live'` et `PAYMENT_API_URL = 'https://<votre domaine>/api.php'`.
- Walled-garden du hotspot (le client doit joindre le serveur avant d'être connecté) :
  ```
  /ip hotspot walled-garden add dst-host=<votre domaine>
  /ip hotspot walled-garden ip add dst-host=<votre domaine> action=accept
  ```
- Clés IZI Spot dans `.env` : `PAYMENT_PUBLISHABLE_KEY`, `PAYMENT_SECRET_KEY`, `WEBHOOK_SIGNING_SECRET`.
- Webhook : l'adresse est enregistrée **par IZI Spot**. Donnez-leur `https://<votre domaine>/webhook.php`.
- Premier essai : un vrai paiement de 100 FCFA (forfait 1 Go), puis vérifiez le ticket, l'espace admin et le solde dans la page Comptabilité.

Frais IZI Spot : 7 % arrondis au-dessus (100 FCFA payés = 93 crédités). Ils sont enregistrés tels que l'API les renvoie,
et la page Comptabilité affiche le solde disponible au retrait (retraits depuis l'espace IZI Spot, minimum 1 000 FCFA).
L'API limite les lectures à 120 par minute pour tout le compte : le serveur relit chaque paiement au plus toutes les 4 s
et s'arrête à 100 lectures par minute.

## Utilisation au quotidien

| Besoin | Où |
|---|---|
| Ventes du jour, de la semaine, du mois, état du routeur, alertes | `/admin/` |
| Journal des ventes filtrable, export CSV | `/admin/ventes.php` |
| Comptabilité mensuelle : chiffre d'affaires, frais estimés, net, par forfait, opérateur et jour | `/admin/compta.php` |
| Consommation par jour, semaine ou mois, par forfait, heures de pointe | `/admin/conso.php` |
| Retrouver un ticket, ses sessions, relancer un client payé sans ticket, stock de secours | `/admin/tickets.php` |
| Graphiques (période libre, regroupement jour / semaine / mois) | `/grafana/` |

Alertes Telegram (si configuré) : routeur injoignable, client payé sans ticket, routeur qui refuse de créer des tickets,
paiements refusés en série, stock de secours bas, et un récapitulatif chaque soir à 21 h.

## Sécurité

| Risque | Protection |
|---|---|
| Vol des clés | Clés IZI Spot uniquement dans `.env` sur le serveur (`chmod 600`, ignoré par Git), jamais dans le portail. |
| Faux paiement, prix modifié | Webhook signé (HMAC SHA-256 sur horodatage + corps, refusé après 5 min), paiement relu chez IZI Spot, montant, devise et référence contrôlés, prix du serveur. |
| Double ticket | Commande verrouillée en base, un seul ticket par commande (contrainte d'unicité). |
| Vol d'un ticket par sa référence | Jeton secret par commande (stocké haché). Recherche par numéro : 5 essais par commande, 30 par 15 min. |
| Accès au routeur | Tunnel WireGuard, compte API aux droits minimaux limité à l'IP du serveur, HTTPS avec certificat épinglé, API en clair coupée. |
| Espace admin | Réservé aux IP autorisées (Caddy), mot de passe + code 2FA, blocage après 5 échecs, session de 30 min, CSRF, journal d'audit, rôle comptable en lecture seule. |
| Grafana | Réservé aux IP autorisées, compte en lecture seule sur des vues sans téléphone, jeton ni mot de passe. |
| Données personnelles | Numéros visibles seulement par le rôle admin, masqués ailleurs et dans les exports du comptable. |
| Interception | HTTPS partout (certificat Let's Encrypt automatique via Caddy, HSTS). |

À faire de votre côté : changer le compte `admin` du routeur, désactiver telnet, FTP et l'interface web en HTTP,
mettre RouterOS à jour, sauvegarder la base chaque jour hors du serveur :
```bash
docker compose exec -T db pg_dump -U gandal gandal | gzip | gpg -c > gandal-$(date +%F).sql.gz.gpg
```
Pour le portail, activer la connexion CHAP du hotspot (mot de passe haché) ou le HTTPS, pour que les identifiants
ne passent pas en clair sur le Wi-Fi.

## Tests

`tests/e2e.sh` lance une pile Docker de test avec un simulateur de l'API IZI Spot et du routeur, et vérifie
le parcours complet : paiement, ticket créé sur le routeur, appels simultanés, sécurité de la récupération,
webhook, paiement refusé, routeur en panne (stock de secours puis nouvelle tentative), collecte de la consommation,
espace admin (2FA, rôles, CSRF, exports) et requêtes Grafana avec le rôle en lecture seule.

```bash
tests/e2e.sh
docker compose -f tests/docker-compose.test.yml -p gandaltest down -v   # nettoyage
```

Le simulateur imite l'API REST de RouterOS d'après sa documentation : faites un premier essai réel avec
un routeur de test et un petit paiement réel de 100 FCFA avant la mise en service.
