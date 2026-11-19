<?php
/* Accès à PostgreSQL (PDO, requêtes préparées uniquement). */

declare(strict_types=1);

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $c = cfg();
        $pdo = new PDO($c['db_dsn'], $c['db_user'], $c['db_password'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        $pdo->exec("SET TIME ZONE 'Africa/Douala'");
    }
    return $pdo;
}

function q(string $sql, array $params = []): PDOStatement {
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}
function one(string $sql, array $params = []): ?array {
    $r = q($sql, $params)->fetch();
    return $r === false ? null : $r;
}
function all(string $sql, array $params = []): array { return q($sql, $params)->fetchAll(); }
function val(string $sql, array $params = []) { $r = q($sql, $params)->fetchColumn(); return $r === false ? null : $r; }

/* Exécute $fn dans une transaction ; annule tout en cas d'erreur.
   Appelée alors qu'une transaction est déjà ouverte, elle s'y intègre simplement. */
function tx(callable $fn) {
    $pdo = db();
    if ($pdo->inTransaction()) { return $fn(); }
    $pdo->beginTransaction();
    try { $r = $fn(); $pdo->commit(); return $r; }
    catch (Throwable $e) { if ($pdo->inTransaction()) { $pdo->rollBack(); } throw $e; }
}

function audit(string $actor, string $action, string $detail = ''): void {
    q('INSERT INTO audit_log (actor, action, detail) VALUES (?, ?, ?)', [$actor, $action, $detail]);
}

/* Journal : sortie d'erreur en ligne de commande (worker), journal d'Apache sinon (docker compose logs). */
function logline(string $msg): void {
    if (PHP_SAPI === 'cli') { fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n"); }
    else { error_log('gandal: ' . $msg); }
}
