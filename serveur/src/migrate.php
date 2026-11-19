<?php
/* Crée ou met à jour le schéma, recopie les forfaits, et donne à Grafana un accès en lecture aux seules vues. */

declare(strict_types=1);

function migrate(): void {
    db()->exec((string)file_get_contents(dirname(__DIR__) . '/sql/schema.sql'));
    foreach (plans() as $id => $p) {
        q('INSERT INTO plans (id, label, type, price, validity, data_gb, rate_mbps) VALUES (?, ?, ?, ?, ?, ?, ?)
           ON CONFLICT (id) DO UPDATE SET label = EXCLUDED.label, type = EXCLUDED.type, price = EXCLUDED.price,
             validity = EXCLUDED.validity, data_gb = EXCLUDED.data_gb, rate_mbps = EXCLUDED.rate_mbps',
          [$id, $p['label'], $p['type'], $p['price'], $p['validity'], $p['data_gb'], $p['rate']]);
    }
    // Rôle en lecture seule pour Grafana (créé au premier démarrage de PostgreSQL, voir docker/postgres-init.sh)
    if (val("SELECT 1 FROM pg_roles WHERE rolname = 'grafana_ro'")) {
        db()->exec('GRANT USAGE ON SCHEMA public TO grafana_ro;
                    REVOKE ALL ON ALL TABLES IN SCHEMA public FROM grafana_ro;
                    GRANT SELECT ON v_ventes, v_tickets, v_conso, v_sessions, plans, router_health TO grafana_ro;');
    }
}
