<?php
/* Gabarit commun des pages d'administration. */

declare(strict_types=1);

function page_start(string $title, array $me, string $active): void {
    header('X-Frame-Options: DENY');
    header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; form-action 'self'; frame-ancestors 'none'");
    header('Referrer-Policy: same-origin');
    header('Cache-Control: no-store');
    $nav = ['index.php' => 'Tableau de bord', 'ventes.php' => 'Ventes', 'compta.php' => 'Comptabilité',
            'conso.php' => 'Consommation', 'tickets.php' => 'Tickets'];
    echo '<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . h($title) . ' · GANDAL admin</title><style>' . admin_css() . '</style></head><body>';
    echo '<header class="top"><strong>GANDAL</strong><nav>';
    foreach ($nav as $href => $label) {
        echo '<a href="' . $href . '"' . ($href === $active ? ' aria-current="page"' : '') . '>' . $label . '</a>';
    }
    echo '<a href="' . h(cfg()['grafana_url']) . '">Graphiques</a></nav>';
    echo '<span class="me">' . h($me['user']) . ' (' . h($me['role']) . ') · <a href="logout.php">Déconnexion</a></span></header>';
    echo '<main><h1>' . h($title) . '</h1>';
}

function page_end(): void { echo '</main></body></html>'; }

function admin_css(): string {
    return <<<CSS
:root{--bg:#F3F5F8;--card:#fff;--line:#D9DFE7;--text:#14213A;--muted:#5A6577;--primary:#1E3A5F;--ok:#1D7044;--err:#B42318;--warn:#8A5A00}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font:15px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}
.top{display:flex;flex-wrap:wrap;gap:12px 20px;align-items:center;background:var(--primary);color:#fff;padding:12px max(16px,env(safe-area-inset-left))}
.top a{color:#DCE7F2;text-decoration:none}.top a[aria-current]{color:#fff;font-weight:600;text-decoration:underline}
.top nav{display:flex;flex-wrap:wrap;gap:14px;flex:1}.me{font-size:13px;color:#DCE7F2}
main{max-width:1100px;margin:0 auto;padding:20px 16px 48px}h1{font-size:22px;margin:0 0 16px}h2{font-size:17px;margin:28px 0 10px}
.kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:10px}
.kpi{background:var(--card);border:1px solid var(--line);border-radius:10px;padding:12px 14px}
.kpi span{display:block;font-size:13px;color:var(--muted)}.kpi b{font-size:20px;font-variant-numeric:tabular-nums}
.scroll{overflow-x:auto;background:var(--card);border:1px solid var(--line);border-radius:10px}
table{border-collapse:collapse;width:100%;font-size:14px}th,td{padding:8px 10px;border-bottom:1px solid var(--line);text-align:left;white-space:nowrap}
th{font-size:12px;text-transform:uppercase;letter-spacing:.04em;color:var(--muted);background:#F7F9FB}td.n,th.n{text-align:right;font-variant-numeric:tabular-nums}
form.filters{display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;margin-bottom:14px}
label{display:flex;flex-direction:column;font-size:13px;color:var(--muted);gap:4px}
input,select,button{font:inherit;padding:8px 10px;border:1px solid var(--line);border-radius:8px;background:#fff;color:var(--text);min-height:40px}
button,.btn{background:var(--primary);color:#fff;border-color:var(--primary);cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;padding:8px 14px;border-radius:8px}
.btn.secondary{background:#fff;color:var(--primary)}
.tag{display:inline-block;padding:1px 8px;border-radius:999px;font-size:12px;font-weight:600;background:#EEF2F6}
.tag.complete,.tag.ok{background:#E3F4EA;color:var(--ok)}.tag.failed,.tag.paid_no_ticket,.tag.ko{background:#FDECEA;color:var(--err)}
.tag.pending,.tag.paid{background:#FFF4DB;color:var(--warn)}
.muted{color:var(--muted)}.note{font-size:13px;color:var(--muted)}.err{color:var(--err)}
:focus-visible{outline:3px solid #2F6FB3;outline-offset:2px}
CSS;
}

function tag(string $status): string {
    $labels = ['pending' => 'en attente', 'paid' => 'payé, ticket en cours', 'complete' => 'livré', 'paid_no_ticket' => 'payé sans ticket',
               'failed' => 'refusé', 'canceled' => 'annulé', 'expired' => 'expiré'];
    return '<span class="tag ' . h($status) . '">' . h($labels[$status] ?? $status) . '</span>';
}

/* Période choisie dans un formulaire (du / au), par défaut les 30 derniers jours */
function period_from_query(): array {
    $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['du'] ?? '') ? $_GET['du'] : date('Y-m-d', strtotime('-29 days'));
    $to = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['au'] ?? '') ? $_GET['au'] : date('Y-m-d');
    return [$from, $to];
}

function csv_out(string $filename, array $header, array $rows): never {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $f = fopen('php://output', 'w');
    fwrite($f, "\xEF\xBB\xBF"); // BOM : Excel lit correctement les accents
    fputcsv($f, $header, ';');
    foreach ($rows as $r) {
        // Neutralise les formules (=, +, -, @) pour éviter l'injection dans Excel
        fputcsv($f, array_map(fn($v) => is_string($v) && preg_match('/^[=+\-@]/', $v) ? "'" . $v : $v, array_values($r)), ';');
    }
    exit;
}
