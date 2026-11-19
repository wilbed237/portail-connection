#!/bin/sh
# Exécuté une seule fois, à la création de la base : rôle en lecture seule pour Grafana.
set -e
psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" \
  -v pw="$GRAFANA_DB_PASSWORD" <<'SQL'
CREATE ROLE grafana_ro LOGIN PASSWORD :'pw';
SQL
