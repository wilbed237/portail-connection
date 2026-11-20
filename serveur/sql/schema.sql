-- Schéma GANDAL (PostgreSQL 16). Idempotent : bin/migrate.php le rejoue à chaque démarrage.

CREATE TABLE IF NOT EXISTS plans (
  id        text PRIMARY KEY,
  label     text NOT NULL,
  type      text NOT NULL,
  price     integer NOT NULL,
  validity  text NOT NULL,
  data_gb   integer NOT NULL,
  rate_mbps integer NOT NULL
);

-- Une commande = un paiement Mobile Money
CREATE TABLE IF NOT EXISTS orders (
  reference          text PRIMARY KEY,
  provider_id        text,      -- identifiant du paiement chez IZI Spot
  token_hash         text NOT NULL,
  plan_id            text NOT NULL REFERENCES plans(id),
  amount             integer NOT NULL CHECK (amount > 0),
  fee                integer NOT NULL DEFAULT 0,  -- frais IZI Spot réels (7 %), figés à la création
  currency           text NOT NULL DEFAULT 'XAF',
  phone              text NOT NULL,
  operator           text NOT NULL CHECK (operator IN ('mtn', 'orange')),
  -- pending : en attente du client ; paid : payé (IZI Spot : succeeded), ticket en cours de création ;
  -- complete : ticket remis ; paid_no_ticket : payé mais ticket impossible à créer (alerte) ;
  -- failed / canceled / expired : non payé
  status             text NOT NULL DEFAULT 'pending',
  created_at         timestamptz NOT NULL DEFAULT now(),
  checked_at         timestamptz,
  paid_at            timestamptz,
  delivered_at       timestamptz,
  ticket_username    text,
  ticket_source      text CHECK (ticket_source IN ('router', 'stock')),
  pending_username   text,
  pending_password   text,
  provision_attempts integer NOT NULL DEFAULT 0,
  provision_next_at  timestamptz,
  provision_error    text,
  recover_failures   integer NOT NULL DEFAULT 0,
  note               text
);
CREATE INDEX IF NOT EXISTS orders_status_idx  ON orders (status);
CREATE INDEX IF NOT EXISTS orders_created_idx ON orders (created_at);
CREATE UNIQUE INDEX IF NOT EXISTS orders_provider_idx ON orders (provider_id) WHERE provider_id IS NOT NULL;

-- Tickets (utilisateurs User Manager). order_reference UNIQUE : un paiement ne donne qu'un ticket.
CREATE TABLE IF NOT EXISTS tickets (
  username        text PRIMARY KEY,
  password        text NOT NULL,
  plan_id         text NOT NULL REFERENCES plans(id),
  source          text NOT NULL CHECK (source IN ('router', 'stock')),
  order_reference text UNIQUE REFERENCES orders(reference),
  created_at      timestamptz NOT NULL DEFAULT now(),
  assigned_at     timestamptz,
  first_login_at  timestamptz,
  expires_at      timestamptz,
  state           text,
  updated_at      timestamptz
);
-- Illimités avec volume inclus : date de passage au débit réduit (NULL = débit plein)
ALTER TABLE tickets ADD COLUMN IF NOT EXISTS throttled_at timestamptz;
ALTER TABLE plans ADD COLUMN IF NOT EXISTS rate_after_mbps integer NOT NULL DEFAULT 0;
CREATE INDEX IF NOT EXISTS tickets_stock_idx ON tickets (plan_id, created_at) WHERE order_reference IS NULL;

-- Sessions relevées sur le routeur (compteurs cumulés)
CREATE TABLE IF NOT EXISTS usage_sessions (
  id          text PRIMARY KEY,
  username    text NOT NULL,
  started_at  timestamptz,
  ended_at    timestamptz,
  uptime_s    bigint NOT NULL DEFAULT 0,
  bytes_down  bigint NOT NULL DEFAULT 0,
  bytes_up    bigint NOT NULL DEFAULT 0,
  mac         text,
  ip          text,
  active      boolean NOT NULL DEFAULT false,
  updated_at  timestamptz NOT NULL DEFAULT now()
);
CREATE INDEX IF NOT EXISTS usage_sessions_user_idx ON usage_sessions (username);

-- Consommation par tranche de 5 minutes (différences de compteurs) : base des statistiques
CREATE TABLE IF NOT EXISTS usage_samples (
  id         bigserial PRIMARY KEY,
  ts         timestamptz NOT NULL,
  username   text NOT NULL,
  plan_id    text,
  bytes_down bigint NOT NULL,
  bytes_up   bigint NOT NULL
);
CREATE INDEX IF NOT EXISTS usage_samples_ts_idx ON usage_samples (ts);

-- État du routeur, relevé toutes les 5 minutes
CREATE TABLE IF NOT EXISTS router_health (
  ts            timestamptz PRIMARY KEY DEFAULT now(),
  reachable     boolean NOT NULL,
  cpu_load      integer,
  free_memory   bigint,
  total_memory  bigint,
  uptime        text,
  active_users  integer,
  error         text
);

CREATE TABLE IF NOT EXISTS alerts (
  key          text PRIMARY KEY,
  active       boolean NOT NULL DEFAULT false,
  first_at     timestamptz,
  last_sent_at timestamptz,
  message      text
);

CREATE TABLE IF NOT EXISTS admins (
  username       text PRIMARY KEY,
  password_hash  text NOT NULL,
  role           text NOT NULL CHECK (role IN ('admin', 'comptable')),
  totp_secret    text NOT NULL,
  failed_logins  integer NOT NULL DEFAULT 0,
  locked_until   timestamptz,
  created_at     timestamptz NOT NULL DEFAULT now(),
  last_login_at  timestamptz
);

CREATE TABLE IF NOT EXISTS audit_log (
  id     bigserial PRIMARY KEY,
  ts     timestamptz NOT NULL DEFAULT now(),
  actor  text NOT NULL,
  action text NOT NULL,
  detail text
);

CREATE TABLE IF NOT EXISTS rate_limits (
  key          text PRIMARY KEY,
  window_start timestamptz NOT NULL,
  count        integer NOT NULL
);

CREATE TABLE IF NOT EXISTS kv (
  key   text PRIMARY KEY,
  value text
);

-- Vues pour Grafana : pas de numéro de téléphone, pas de jeton, pas de mot de passe.
CREATE OR REPLACE VIEW v_ventes AS
  SELECT o.reference, o.plan_id, p.label AS forfait, p.type, o.amount, o.fee,
         o.amount - o.fee AS net, o.operator, o.status, o.ticket_source,
         o.created_at, o.paid_at, o.delivered_at
  FROM orders o JOIN plans p ON p.id = o.plan_id;

CREATE OR REPLACE VIEW v_tickets AS
  SELECT t.username, t.plan_id, p.label AS forfait, t.source, t.order_reference,
         t.created_at, t.assigned_at, t.first_login_at, t.expires_at, t.state, t.throttled_at
  FROM tickets t JOIN plans p ON p.id = t.plan_id;

CREATE OR REPLACE VIEW v_conso AS
  SELECT s.ts, s.username, s.plan_id, p.label AS forfait, p.type, s.bytes_down, s.bytes_up,
         s.bytes_down + s.bytes_up AS bytes_total
  FROM usage_samples s LEFT JOIN plans p ON p.id = s.plan_id;

CREATE OR REPLACE VIEW v_sessions AS
  SELECT s.id, s.username, t.plan_id, s.started_at, s.ended_at, s.uptime_s, s.bytes_down, s.bytes_up, s.active
  FROM usage_sessions s LEFT JOIN tickets t ON t.username = s.username;
