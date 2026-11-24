#!/usr/bin/env bash
# Tests de bout en bout sur la pile Docker de test (tests/docker-compose.test.yml).
# Usage : tests/e2e.sh        (depuis le dossier serveur/)
set -u
cd "$(dirname "$0")/.."
DC="docker compose -f tests/docker-compose.test.yml -p gandaltest"
API=http://127.0.0.1:8088/api.php
PASS=0; FAIL=0
ok()   { PASS=$((PASS+1)); echo "  OK   $1"; }
ko()   { FAIL=$((FAIL+1)); echo "  ÉCHEC $1"; [ -n "${2:-}" ] && echo "       $2"; }
check(){ if [ "$2" = "$3" ]; then ok "$1"; else ko "$1" "attendu « $3 », obtenu « $2 »"; fi; }
j()    { python3 -c "import sys,json; d=json.load(sys.stdin); print(d.get('$1',''))"; }
sql()  { $DC exec -T db psql -U gandal -d gandal -tAc "$1"; }
mock() { $DC exec -T mock php -r "file_get_contents('http://localhost:8080$1');"; }
post() { curl -s -X POST "$API?action=$1" -H 'Content-Type: application/json' -d "$2"; }
worker(){ $DC exec -T worker php bin/worker.php --once 2>&1 | grep -v "^\[" ; true; }

echo "== Démarrage"
$DC up -d --build --quiet-pull >/dev/null 2>&1 || { echo "docker compose a échoué"; exit 1; }
for i in $(seq 1 40); do $DC exec -T db pg_isready -U gandal >/dev/null 2>&1 && break; sleep 1; done
sleep 2; worker
check "schéma créé, 8 forfaits" "$(sql 'SELECT count(*) FROM plans')" "8"

echo "== Paiement et création du ticket sur le routeur"
check "forfait inconnu refusé" "$(post init '{"plan":"gratuit","phone":"677123456","operator":"mtn"}' | j error)" "Forfait inconnu."
check "numéro invalide refusé" "$(post init '{"plan":"illim-7j","phone":"12345","operator":"mtn"}' | j ok)" "False"
R=$(post init '{"plan":"illim-7j","phone":"+237 677 12 34 56","operator":"mtn","price":1}')
REF=$(echo "$R" | j reference); TOK=$(echo "$R" | j token)
[ -n "$REF" ] && ok "commande créée ($REF)" || ko "commande créée" "$R"
check "prix appliqué = prix du serveur, pas celui du navigateur" "$(sql "SELECT amount FROM orders WHERE reference='$REF'")" "1500"
check "frais IZI Spot réels enregistrés (7 % arrondi au-dessus : 105)" "$(sql "SELECT fee FROM orders WHERE reference='$REF'")" "105"
check "jeton stocké haché, jamais en clair" "$(sql "SELECT token_hash = encode(sha256('$TOK'::bytea),'hex') FROM orders WHERE reference='$REF'")" "t"
check "en attente avant paiement" "$(post status "{\"reference\":\"$REF\",\"token\":\"$TOK\"}" | j status)" "pending"
mock "/ctl/pay?ref=$REF"
sleep 5   # l'état IZI Spot n'est relu qu'une fois toutes les 4 s par commande (quota de 120 lectures/min)
S=$(post status "{\"reference\":\"$REF\",\"token\":\"$TOK\"}")
check "ticket remis dès le paiement confirmé" "$(echo "$S" | j status)" "complete"
U=$(echo "$S" | j username); P=$(echo "$S" | j password)
check "ticket créé sur le routeur (User Manager)" "$(sql "SELECT source FROM tickets WHERE username='$U'")" "router"
STATE=$($DC exec -T mock php -r 'echo file_get_contents("http://localhost:8080/ctl/state");')
check "utilisateur présent dans User Manager avec le bon profil" "$(echo "$STATE" | python3 -c "import sys,json; d=json.load(sys.stdin); print(any(p['user']=='$U' and p['profile']=='gd-illim-7j' for p in d['profiles']))")" "True"
check "même ticket aux appels suivants" "$(post status "{\"reference\":\"$REF\",\"token\":\"$TOK\"}" | j username)" "$U"
check "mot de passe supprimé de la commande une fois livré" "$(sql "SELECT pending_password IS NULL FROM orders WHERE reference='$REF'")" "t"

echo "== Appels simultanés : un seul ticket"
R2=$(post init '{"plan":"4go-3j","phone":"699000001","operator":"orange"}'); REF2=$(echo "$R2" | j reference); TOK2=$(echo "$R2" | j token)
mock "/ctl/pay?ref=$REF2"
for i in $(seq 1 12); do post status "{\"reference\":\"$REF2\",\"token\":\"$TOK2\"}" > /tmp/gd_par_$i.json & done; wait
check "12 appels parallèles → 1 seul ticket en base" "$(sql "SELECT count(*) FROM tickets WHERE order_reference='$REF2'")" "1"
check "12 appels parallèles → tous reçoivent le même identifiant" "$(cat /tmp/gd_par_*.json | python3 -c "import sys,json; print(len({json.loads(l)['username'] for l in sys.stdin.read().replace('}{','}\n{').splitlines()}))")" "1"
rm -f /tmp/gd_par_*.json

echo "== Sécurité de la récupération"
check "mauvais jeton refusé" "$(post status "{\"reference\":\"$REF\",\"token\":\"faux\"}" | j ok)" "False"
check "récupération par numéro" "$(post status "{\"reference\":\"$REF\",\"phone\":\"677123456\"}" | j username)" "$U"
for i in 1 2 3 4 5; do post status "{\"reference\":\"$REF\",\"phone\":\"670000000\"}" >/dev/null; done
check "bloqué après 5 mauvais numéros, même avec le bon" "$(post status "{\"reference\":\"$REF\",\"phone\":\"677123456\"}" | j ok)" "False"
check "le jeton reste valable" "$(post status "{\"reference\":\"$REF\",\"token\":\"$TOK\"}" | j username)" "$U"

echo "== Webhook IZI Spot"
WH=http://127.0.0.1:8088/webhook.php
check "signature fausse refusée" "$(curl -s -o /dev/null -w '%{http_code}' -X POST $WH -H "x-timestamp: $(date +%s)" -H 'xkey: abc' -d '{}')" "401"
R3=$(post init '{"plan":"1go-1j","phone":"677000003","operator":"mtn"}'); REF3=$(echo "$R3" | j reference)
mock "/ctl/pay?ref=$REF3"
BODY="{\"id\":\"evt_1\",\"type\":\"payment.succeeded\",\"data\":{\"reference\":\"$REF3\",\"status\":\"succeeded\"}}"
OLD=$(( $(date +%s) - 600 ))
SIGOLD=$(printf '%s' "$OLD.$BODY" | openssl dgst -sha256 -hmac whsec_test | awk '{print $2}')
check "message de plus de 5 min refusé (rejeu)" "$(curl -s -o /dev/null -w '%{http_code}' -X POST $WH -H "x-timestamp: $OLD" -H "xkey: $SIGOLD" -d "$BODY")" "401"
TS=$(date +%s); SIG=$(printf '%s' "$TS.$BODY" | openssl dgst -sha256 -hmac whsec_test | awk '{print $2}')
check "webhook signé accepté" "$(curl -s -o /dev/null -w '%{http_code}' -X POST $WH -H "x-timestamp: $TS" -H "xkey: $SIG" -d "$BODY")" "200"
check "webhook → paiement confirmé auprès d'IZI Spot" "$(sql "SELECT status FROM orders WHERE reference='$REF3'")" "paid"
worker
check "client parti : le worker crée le ticket" "$(sql "SELECT status FROM orders WHERE reference='$REF3'")" "complete"

echo "== Paiement refusé"
R4=$(post init '{"plan":"1go-1j","phone":"677000004","operator":"mtn"}'); REF4=$(echo "$R4" | j reference); TOK4=$(echo "$R4" | j token)
mock "/ctl/pay?ref=$REF4&status=failed"
check "paiement refusé → pas de ticket" "$(post status "{\"reference\":\"$REF4\",\"token\":\"$TOK4\"}" | j status)" "failed"

echo "== Routeur en panne : stock de secours"
$DC exec -T worker php bin/stock.php 8go-5j 2 >/dev/null 2>&1
check "2 tickets de secours créés sur le routeur" "$(sql "SELECT count(*) FROM tickets WHERE source='stock' AND plan_id='8go-5j'")" "2"
mock "/ctl/router?down=1"
R5=$(post init '{"plan":"8go-5j","phone":"677000005","operator":"mtn"}'); REF5=$(echo "$R5" | j reference); TOK5=$(echo "$R5" | j token)
mock "/ctl/pay?ref=$REF5"
S5=$(post status "{\"reference\":\"$REF5\",\"token\":\"$TOK5\"}")
check "routeur muet → ticket du stock remis au client" "$(echo "$S5" | j status)" "complete"
check "source enregistrée : stock" "$(sql "SELECT ticket_source FROM orders WHERE reference='$REF5'")" "stock"

echo "== Routeur en panne et pas de stock : nouvelle tentative"
R6=$(post init '{"plan":"illim-30j","phone":"677000006","operator":"mtn"}'); REF6=$(echo "$R6" | j reference); TOK6=$(echo "$R6" | j token)
mock "/ctl/pay?ref=$REF6"
check "client payé, ticket en attente (reste « pending » côté portail)" "$(post status "{\"reference\":\"$REF6\",\"token\":\"$TOK6\"}" | j status)" "pending"
check "commande marquée payée" "$(sql "SELECT status FROM orders WHERE reference='$REF6'")" "paid"
mock "/ctl/router?down=0"
sql "UPDATE orders SET provision_next_at = now() WHERE reference='$REF6'" >/dev/null
worker
check "routeur revenu → le worker crée le ticket" "$(sql "SELECT status || '/' || ticket_source FROM orders WHERE reference='$REF6'")" "complete/router"

echo "== Collecte de la consommation"
worker
N1=$(sql "SELECT COALESCE(sum(bytes_down),0) FROM usage_samples")
worker
N2=$(sql "SELECT COALESCE(sum(bytes_down),0) FROM usage_samples")
[ "$N2" -gt "$N1" ] && ok "consommation relevée par tranches ($N1 → $N2 octets)" || ko "consommation relevée" "$N1 → $N2"
check "deltas, pas de double comptage (50 Mo par relevé et par ticket)" "$(sql "SELECT bool_and(bytes_down = 52428800) FROM usage_samples WHERE ts > now() - interval '1 minute' AND bytes_down > 0 AND ts = (SELECT max(ts) FROM usage_samples)")" "t"
check "session historique datée de sa fin, pas comptée aujourd'hui" "$(sql "SELECT count(*) FROM usage_samples WHERE bytes_down = 104857601 AND ts = '2026-01-05 10:00:00+01'")" "1"
check "rien d'aujourd'hui ne vient de l'historique" "$(sql "SELECT count(*) FROM usage_samples WHERE ts >= date_trunc('day', now()) AND bytes_down = 104857601")" "0"
B1=$(sql "SELECT sum(bytes_down) FROM usage_samples WHERE ts < now() - interval '1 day'")
mock "/ctl/renum"; worker
check "redémarrage du routeur (.id changés) : pas de double comptage" "$(sql "SELECT sum(bytes_down) FROM usage_samples WHERE ts < now() - interval '1 day'")" "$B1"
check "état du routeur enregistré" "$(sql "SELECT reachable FROM router_health ORDER BY ts DESC LIMIT 1")" "t"
check "date d'expiration des tickets relevée" "$(sql "SELECT count(*) > 0 FROM tickets WHERE expires_at IS NOT NULL")" "t"

echo "== Illimité avec volume : 5 Mb/s puis 2 Mb/s, sans coupure"
ustate(){ $DC exec -T mock php -r 'echo file_get_contents("http://localhost:8080/ctl/state");' | python3 -c "import sys,json; d=json.load(sys.stdin); $1"; }
U2=$(sql "SELECT username FROM tickets WHERE order_reference='$REF2'")
U6=$(sql "SELECT username FROM tickets WHERE order_reference='$REF6'")
check "illimité 1 semaine créé à 5 Mb/s" "$(ustate "print(d['users']['$U'].get('attributes',''))")" "Mikrotik-Rate-Limit:5M/5M"
check "forfait en Go : débit du profil, pas d'attribut" "$(ustate "print(d['users']['$U2'].get('attributes','aucun'))")" "aucun"
mock "/ctl/usage?user=$U&bytes=26843545600"   # 25 Go de plus
worker
check "25 Go dépassés → ticket marqué en débit réduit" "$(sql "SELECT throttled_at IS NOT NULL FROM tickets WHERE username='$U'")" "t"
check "attribut passé à 2 Mb/s sur le routeur" "$(ustate "print(d['users']['$U'].get('attributes',''))")" "Mikrotik-Rate-Limit:2M/2M"
check "session hotspot fermée pour appliquer le débit" "$(ustate "print('$U' in d['kicked'])")" "True"
check "le ticket n'est pas supprimé du routeur" "$(ustate "print('$U' in d['users'])")" "True"
check "illimité 1 mois sous son volume : toujours à 5 Mb/s" "$(sql "SELECT throttled_at IS NULL FROM tickets WHERE username='$U6'")" "t"
T1=$(sql "SELECT throttled_at FROM tickets WHERE username='$U'"); worker
check "pas de seconde bascule au relevé suivant" "$(sql "SELECT throttled_at FROM tickets WHERE username='$U'")" "$T1"

echo "== Forfaits 1 jour : fin à 23:59:59 du jour de la première connexion"
U3=$(sql "SELECT username FROM tickets WHERE order_reference='$REF3'")
check "1 jour activé aujourd'hui : expire ce soir à 23:59:59" "$(sql "SELECT to_char(expires_at AT TIME ZONE 'Africa/Douala', 'YYYY-MM-DD HH24:MI:SS') FROM tickets WHERE username='$U3'")" "$(TZ=Africa/Douala date +%F) 23:59:59"
worker
check "1 jour activé aujourd'hui : pas coupé" "$(ustate "print(d['users']['$U3'].get('disabled','no'))")" "no"
# Première connexion hier soir à 23:50 : le ticket aurait dû se terminer à minuit
sql "UPDATE tickets SET first_login_at = (current_date - 1 + time '23:50') AT TIME ZONE 'Africa/Douala' WHERE username IN ('$U3', '$U2')" >/dev/null
worker
check "1 jour activé hier : désactivé sur le routeur" "$(ustate "print(d['users']['$U3'].get('disabled','no'))")" "yes"
check "1 jour activé hier : session hotspot fermée" "$(ustate "print('$U3' in d['kicked'])")" "True"
check "1 jour activé hier : fin enregistrée, expiré à 23:59:59 hier" "$(sql "SELECT ended_at IS NOT NULL AND to_char(expires_at AT TIME ZONE 'Africa/Douala', 'HH24:MI:SS') = '23:59:59' AND expires_at < now() FROM tickets WHERE username='$U3'")" "t"
check "forfait de 3 jours activé hier : pas coupé à minuit" "$(ustate "print(d['users']['$U2'].get('disabled','no'))")" "no"
E1=$(sql "SELECT ended_at FROM tickets WHERE username='$U3'"); worker
check "pas de seconde coupure au passage suivant" "$(sql "SELECT ended_at FROM tickets WHERE username='$U3'")" "$E1"

echo "== Espace d'administration"
OUT=$($DC exec -T worker php bin/admin.php creer compta1 comptable 2>&1)
APW=$(echo "$OUT" | sed -n 's/^Mot de passe : //p'); SEC=$(echo "$OUT" | sed -n 's/^Clé 2FA : //p')
CODE=$($DC exec -T worker php -r "require 'src/bootstrap.php'; require 'src/auth.php'; echo totp('$SEC', time());")
JAR=$(mktemp)
CSRF=$(curl -s -c "$JAR" http://127.0.0.1:8088/admin/login.php | sed -n 's/.*name="csrf" value="\([0-9a-f]*\)".*/\1/p')
check "page protégée sans connexion → redirection" "$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:8088/admin/index.php)" "302"
check "mauvais code 2FA refusé" "$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{http_code}' -d "csrf=$CSRF&username=compta1&password=$APW&code=000000" http://127.0.0.1:8088/admin/login.php)" "200"
check "connexion mot de passe + 2FA" "$(curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{redirect_url}' -d "csrf=$CSRF&username=compta1&password=$APW&code=$CODE" http://127.0.0.1:8088/admin/login.php | sed 's#.*/##')" "index.php"
for p in index ventes compta conso tickets; do
  check "page $p.php" "$(curl -s -b "$JAR" -o /dev/null -w '%{http_code}' "http://127.0.0.1:8088/admin/$p.php")" "200"
done
curl -s -b "$JAR" "http://127.0.0.1:8088/admin/ventes.php" | grep -q "677 •• •• 56" && ok "numéros masqués pour le comptable" || ko "numéros masqués pour le comptable"
curl -s -b "$JAR" "http://127.0.0.1:8088/admin/compta.php" | grep -q "Disponible au retrait" && ok "solde IZI Spot affiché en comptabilité" || ko "solde IZI Spot affiché en comptabilité"
check "export CSV comptable" "$(curl -s -b "$JAR" "http://127.0.0.1:8088/admin/compta.php?export=csv" | head -1 | tr -d '\r\357\273\277' | cut -d';' -f1)" "Jour"
check "relance d'un ticket interdite au comptable" "$(curl -s -b "$JAR" -o /dev/null -w '%{http_code}' -d "csrf=$(curl -s -b "$JAR" http://127.0.0.1:8088/admin/login.php | sed -n 's/.*name="csrf" value="\([0-9a-f]*\)".*/\1/p' | head -1)&reference=$REF" http://127.0.0.1:8088/admin/tickets.php)" "403"
check "POST sans jeton CSRF refusé" "$(curl -s -b "$JAR" -o /dev/null -w '%{http_code}' -d "reference=$REF" http://127.0.0.1:8088/admin/tickets.php)" "400"
check "journal d'audit" "$(sql "SELECT count(*) > 0 FROM audit_log WHERE actor='compta1' AND action='connexion'")" "t"
rm -f "$JAR"

echo "== Grafana : requêtes du tableau de bord avec le rôle en lecture seule"
python3 - <<'PY' > /tmp/gd_queries.sql
import json
d = json.load(open('grafana/dashboards/gandal.json'))
for p in d['panels']:
    s = p['targets'][0]['rawSql']
    import re
    s = re.sub(r"\$__timeFilter\((\w+)\)", r"\1 > now() - interval '30 days'", s).replace('$granularite', 'day')
    print(s + ';')
PY
NQ=$(grep -c ';$' /tmp/gd_queries.sql)
ERR=$($DC exec -T -e PGPASSWORD=grafanatest db psql -h localhost -U grafana_ro -d gandal -v ON_ERROR_STOP=1 -q -f - < /tmp/gd_queries.sql 2>&1 >/dev/null)
[ -z "$ERR" ] && ok "$NQ requêtes Grafana exécutées sans erreur" || ko "requêtes Grafana" "$ERR"
check "Grafana ne peut pas lire les numéros de téléphone" "$($DC exec -T -e PGPASSWORD=grafanatest db psql -h localhost -U grafana_ro -d gandal -tAc 'SELECT phone FROM orders LIMIT 1' 2>&1 | grep -c 'permission denied')" "1"
check "Grafana ne peut pas lire les mots de passe des tickets" "$($DC exec -T -e PGPASSWORD=grafanatest db psql -h localhost -U grafana_ro -d gandal -tAc 'SELECT password FROM tickets LIMIT 1' 2>&1 | grep -c 'permission denied')" "1"
rm -f /tmp/gd_queries.sql
for i in $(seq 1 30); do curl -s -u admin:admintest http://127.0.0.1:3088/api/health >/dev/null 2>&1 && break; sleep 2; done
check "Grafana : tableau de bord chargé" "$(curl -s -u admin:admintest http://127.0.0.1:3088/api/dashboards/uid/gandal-ventes | python3 -c 'import sys,json; print(len(json.load(sys.stdin)["dashboard"]["panels"]))')" "12"
check "Grafana : source de données connectée" "$(curl -s -u admin:admintest http://127.0.0.1:3088/api/datasources/uid/gandal-pg/health | python3 -c 'import sys,json; print(json.load(sys.stdin).get("status"))')" "OK"

echo
echo "Résultat : $PASS réussis, $FAIL échoués"
[ "$FAIL" -eq 0 ]
