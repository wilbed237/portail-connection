<?php
/* Gabarit commun des pages d'administration : barre latérale, styles (clair et sombre), filtres de période. */

declare(strict_types=1);

require_once __DIR__ . '/charts.php';

const ADMIN_NAV = [
    'index.php'   => ['Tableau de bord', '<path d="M3 13h8V3H3zm10 8h8V11h-8zM3 21h8v-6H3zm10-18v6h8V3z"/>'],
    'ventes.php'  => ['Ventes', '<path d="M3 3v18h18"/><path d="M7 15l4-4 3 3 6-7"/>'],
    'conso.php'   => ['Consommation', '<path d="M5 12.5a10 10 0 0 1 14 0M8.5 16a5 5 0 0 1 7 0M2 9a15 15 0 0 1 20 0"/><circle cx="12" cy="19.5" r="1"/>'],
    'tickets.php' => ['Tickets', '<path d="M3 8a2 2 0 0 0 0 4v5h18v-5a2 2 0 0 1 0-4V3H3z"/><path d="M13 3v14" stroke-dasharray="2 2"/>'],
    'compta.php'  => ['Comptabilité', '<rect x="4" y="2" width="16" height="20" rx="2"/><path d="M8 6h8M8 10h8M8 14h3M8 18h3M14 14h2M14 18h2"/>'],
];

function page_start(string $title, array $me, string $active, string $subtitle = ''): void {
    header('X-Frame-Options: DENY');
    header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; form-action 'self'; frame-ancestors 'none'");
    header('Referrer-Policy: same-origin');
    header('Cache-Control: no-store');
    echo '<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . h($title) . ' · GANDAL admin</title><link rel="icon" href="assets/logo-emblem.png"><style>' . admin_css() . '</style>';
    echo '<script src="assets/admin.js" defer></script></head><body><div class="shell"><aside class="side"><div class="brand"><img class="logo" src="assets/logo-emblem.png" alt="" width="32" height="32">GANDAL<small>admin</small></div><nav>';
    foreach (ADMIN_NAV as $href => [$label, $icon]) {
        echo '<a href="' . $href . '"' . ($href === $active ? ' aria-current="page"' : '') . '><svg viewBox="0 0 24 24" aria-hidden="true">' . $icon . '</svg><span>' . $label . '</span></a>';
    }
    echo '<a href="' . h(cfg()['grafana_url']) . '"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 19V9M10 19V5M16 19v-7M22 19H2"/></svg><span>Grafana</span></a>';
    echo '</nav><div class="me"><span class="avatar">' . h(strtoupper(substr($me['user'], 0, 1))) . '</span><span><b>' . h($me['user']) . '</b><small>' . h($me['role']) . '</small></span><a href="logout.php" title="Déconnexion" aria-label="Déconnexion"><svg viewBox="0 0 24 24"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4M10 17l5-5-5-5M15 12H3"/></svg></a></div></aside>';
    echo '<main><header class="ph"><div><h1>' . h($title) . '</h1>' . ($subtitle ? '<p class="sub">' . $subtitle . '</p>' : '') . '</div></header>';
}

function page_end(): void { echo '<div id="tip" role="tooltip" hidden></div></main></div></body></html>'; }

/* Carte de contenu */
function card(string $title, string $body, string $aside = '', string $class = ''): string {
    return '<section class="card ' . $class . '"><header><h2>' . h($title) . '</h2>' . ($aside ? '<div class="card-aside">' . $aside . '</div>' : '') . '</header>' . $body . '</section>';
}

/* Sélecteur à onglets (liens), ex. Aujourd'hui / Cette semaine / Ce mois */
function seg(string $param, array $options, string $current, array $keep = []): string {
    $out = '<nav class="seg" aria-label="Période">';
    foreach ($options as $k => $l) {
        $q = http_build_query(array_merge($keep, [$param => $k]));
        $out .= '<a href="?' . h($q) . '"' . ($k === $current ? ' aria-current="true"' : '') . '>' . h($l) . '</a>';
    }
    return $out . '</nav>';
}

function admin_css(): string {
    return <<<CSS
:root{color-scheme:light;
--page:#f4f4f1;--card:#fcfcfb;--ink:#0b0b0b;--ink2:#52514e;--muted:#6f6d68;--line:rgba(11,11,11,.10);--grid:#e1e0d9;--axis:#c3c2b7;--hover:rgba(11,11,11,.04);
--brand:#16243a;--brand-ink:#e9eef5;--accent:#2a78d6;--accent-ink:#fff;
--s1:#2a78d6;--s2:#eb6834;--s3:#1baf7a;
--mtn:#f5c400;--orange:#ff7900;
--q1:#cde2fb;--q2:#9ec5f4;--q3:#6da7ec;--q4:#3987e5;--q5:#256abf;--q6:#184f95;--track:#e6effb;
--good:#0ca30c;--good-ink:#006300;--warn:#fab219;--serious:#ec835a;--crit:#d03b3b;--crit-ink:#b42318;--warn-ink:#8a5a00}
@media (prefers-color-scheme:dark){:root:where(:not([data-theme="light"])){color-scheme:dark;
--page:#0d0d0d;--card:#1a1a19;--ink:#f0efec;--ink2:#c3c2b7;--muted:#9a988f;--line:rgba(255,255,255,.10);--grid:#2c2c2a;--axis:#383835;--hover:rgba(255,255,255,.05);
--brand:#121212;--brand-ink:#d7d6d0;--accent:#3987e5;
--s1:#3987e5;--s2:#d95926;--s3:#199e70;
--mtn:#ffcc00;--orange:#ff7900;
--q1:#123a6e;--q2:#184f95;--q3:#256abf;--q4:#3987e5;--q5:#6da7ec;--q6:#9ec5f4;--track:#14284a;
--good-ink:#0ca30c;--crit-ink:#e66767;--warn-ink:#fab219}}
*{box-sizing:border-box}html,body{margin:0}
body{background:var(--page);color:var(--ink);font:14px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;-webkit-font-smoothing:antialiased}
a{color:var(--accent)}
.shell{display:grid;grid-template-columns:232px minmax(0,1fr);min-height:100vh}
.side{background:var(--brand);color:var(--brand-ink);display:flex;flex-direction:column;position:sticky;top:0;height:100vh;padding:18px 12px}
.brand{display:flex;align-items:center;gap:10px;font-weight:700;letter-spacing:.06em;padding:0 8px 18px;font-size:15px}
.brand small{font-weight:500;letter-spacing:0;opacity:.6;font-size:12px}
.logo{width:32px;height:32px;border-radius:8px;background:#fff;padding:2px;object-fit:contain;flex-shrink:0;display:block}
.side nav{display:flex;flex-direction:column;gap:2px;flex:1}
.side nav a{display:flex;align-items:center;gap:12px;padding:9px 10px;border-radius:8px;color:var(--brand-ink);text-decoration:none;opacity:.78}
.side nav a:hover{background:rgba(255,255,255,.06);opacity:1}
.side nav a[aria-current]{background:rgba(255,255,255,.11);opacity:1;font-weight:600}
.side svg{width:18px;height:18px;fill:none;stroke:currentColor;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round;flex:none}
.me{display:flex;align-items:center;gap:10px;padding:12px 8px 0;border-top:1px solid rgba(255,255,255,.1)}
.me span b{display:block;font-size:13px}.me small{opacity:.6;font-size:12px}.me>span:nth-child(2){flex:1;line-height:1.25}
.me a{color:var(--brand-ink);opacity:.7;display:grid;place-items:center;width:32px;height:32px;border-radius:8px}.me a:hover{opacity:1;background:rgba(255,255,255,.08)}
.avatar{width:32px;height:32px;border-radius:50%;background:rgba(255,255,255,.14);display:grid;place-items:center;font-weight:700}
main{padding:24px 28px 56px;max-width:1360px;width:100%}
.ph{display:flex;justify-content:space-between;align-items:flex-end;gap:16px;margin-bottom:18px;flex-wrap:wrap}
h1{font-size:22px;margin:0;letter-spacing:-.01em}.sub{margin:2px 0 0;color:var(--ink2)}
h2{font-size:15px;margin:0;font-weight:600}
.grid{display:grid;gap:16px;grid-template-columns:repeat(12,minmax(0,1fr));margin-bottom:16px;align-items:start}
.c12{grid-column:span 12}.c8{grid-column:span 8}.c7{grid-column:span 7}.c6{grid-column:span 6}.c5{grid-column:span 5}.c4{grid-column:span 4}
.card{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:16px 18px;min-width:0}
.card>header{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:14px;flex-wrap:wrap}
.card-aside{color:var(--ink2);font-size:13px}.card-aside b{color:var(--ink)}
.tiles{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:16px;margin-bottom:16px}
.tile{background:var(--card);border:1px solid var(--line);border-radius:12px;padding:14px 16px 12px;position:relative;min-height:118px;display:flex;flex-direction:column}
.t-l{color:var(--ink2);font-size:13px}.t-v{font-size:26px;font-weight:600;letter-spacing:-.02em;margin:2px 0 2px;line-height:1.2}
.t-d{font-size:12.5px}.t-n{font-size:12.5px;color:var(--ink2)}
.delta{font-weight:600}.delta.good{color:var(--good-ink)}.delta.bad{color:var(--crit-ink)}.delta.flat{color:var(--ink2)}.vs{color:var(--muted)}
.spark{margin-top:auto;width:100%;height:30px;display:block;overflow:visible}
.spark polyline{fill:none;stroke:var(--axis);stroke-width:2;stroke-linejoin:round;stroke-linecap:round}.spark circle{fill:var(--s1);stroke:var(--card);stroke-width:2}
/* Colonnes */
.chart{--h:220px;display:grid;grid-template-columns:auto minmax(0,1fr);grid-template-rows:var(--h) auto;column-gap:8px}
.yaxis{position:relative;min-width:34px}
.yaxis span{position:absolute;right:0;transform:translateY(50%);font-size:11.5px;color:var(--muted);font-variant-numeric:tabular-nums;white-space:nowrap}
.plot{position:relative;border-bottom:1px solid var(--axis)}
.plot .gl{position:absolute;left:0;right:0;height:0;border-top:1px solid var(--grid)}
.bars{position:absolute;inset:0;display:grid;grid-template-columns:repeat(var(--n),minmax(0,1fr));align-items:end}
.col{height:100%;display:flex;align-items:flex-end;justify-content:center;padding:0 1px;cursor:default;outline:none}
.col>b{display:block;width:100%;max-width:24px;background:var(--s1);border-radius:4px 4px 0 0;min-height:0}
.col.hl>b{background:var(--s1);box-shadow:inset 0 0 0 999px rgba(255,255,255,.0)}
.col:not(.hl)>b{opacity:.92}
.col:hover>b,.col:focus-visible>b,.col:hover .st,.col:focus-visible .st{filter:brightness(1.12)}
.col:focus-visible{background:var(--hover)}
a.col{cursor:pointer;text-decoration:none}a.col:hover{background:var(--hover)}
.zoomnav{display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:8px 16px;margin:-6px 0 16px;font-size:14px}
.zn-crumbs{display:flex;flex-wrap:wrap;align-items:center;gap:4px 8px;color:var(--ink2)}.zn-crumbs b{color:var(--ink)}
.zn-sep{color:var(--muted)}.zoomnav a{color:var(--accent);text-decoration:none}.zoomnav a:hover{text-decoration:underline}
.zn-arrows{display:flex;gap:6px}.zn-arrow{padding:4px 10px;border:1px solid var(--line);border-radius:7px;background:var(--card);font-size:13px}
.zn-arrow[aria-disabled]{color:var(--muted);opacity:.6}
.chart-hint{font-size:12px;color:var(--muted);margin:6px 0 0}
.col.stack .st{width:100%;max-width:24px;display:flex;flex-direction:column;gap:2px;border-radius:4px 4px 0 0;overflow:hidden}
.col.stack .st b{display:block;min-height:1px;flex-basis:0}
.xaxis{grid-column:2;display:grid;grid-template-columns:repeat(var(--n),minmax(0,1fr));padding-top:6px}
.xaxis span{font-size:11.5px;color:var(--muted);text-align:center;white-space:nowrap;overflow:visible;font-variant-numeric:tabular-nums}
.legend{display:flex;gap:16px;flex-wrap:wrap;font-size:12.5px;color:var(--ink2);margin:-4px 0 12px}
.legend i{display:inline-block;width:10px;height:10px;border-radius:3px;margin-right:6px;vertical-align:-1px}
.chart-empty{display:grid;place-items:center;color:var(--muted);border:1px dashed var(--grid);border-radius:10px}
/* Barres horizontales */
.hbars{display:flex;flex-direction:column;gap:4px}
.hb{display:grid;grid-template-columns:minmax(120px,38%) minmax(0,1fr) auto;align-items:center;gap:12px;padding:6px 6px;border-radius:8px;outline:none}
.hb:hover,.hb:focus-visible{background:var(--hover)}
.hb-l{line-height:1.25;min-width:0}.hb-l a{color:inherit;text-decoration:none}.hb-l small{display:block;color:var(--muted);font-size:12px}
.hb-t{height:12px}.hb-t b{display:block;height:100%;background:var(--s1);border-radius:0 4px 4px 0;min-width:2px}
.hb-v{font-variant-numeric:tabular-nums;font-weight:600;text-align:right;white-space:nowrap}
/* Carte de chaleur */
.heat{display:grid;grid-template-columns:34px repeat(24,minmax(0,1fr));gap:3px;align-items:center}
.heat i{display:block;aspect-ratio:1.5;border-radius:3px;background:var(--grid);outline:none}
.heat i:hover,.heat i:focus-visible{box-shadow:0 0 0 2px var(--ink)}
.heat .hx{font-size:11px;color:var(--muted);text-align:left}.heat .hy{font-size:11.5px;color:var(--ink2)}
.q0{background:var(--grid)}.q1{background:var(--q1)!important}.q2{background:var(--q2)!important}.q3{background:var(--q3)!important}.q4{background:var(--q4)!important}.q5{background:var(--q5)!important}.q6{background:var(--q6)!important}
.heat-key{display:flex;align-items:center;gap:3px;justify-content:flex-end;margin-top:10px;font-size:11.5px;color:var(--muted)}
.heat-key i{width:18px;height:10px;border-radius:2px;display:inline-block}.heat-key span{margin:0 4px}
/* Jauges */
.meter{height:8px;border-radius:4px;background:var(--track);overflow:hidden;min-width:110px}
.meter b{display:block;height:100%;border-radius:4px;background:var(--s1)}
.meter.warn b{background:var(--warn)}.meter.crit b{background:var(--crit)}.meter.reduit b{background:var(--serious)}
.meter-l{display:flex;justify-content:space-between;gap:10px;font-size:12px;color:var(--ink2);margin-top:3px;font-variant-numeric:tabular-nums}
/* Tableaux */
.scroll{overflow-x:auto}
table{border-collapse:collapse;width:100%;font-size:13.5px}
th,td{padding:9px 10px;border-bottom:1px solid var(--grid);text-align:left;white-space:nowrap;vertical-align:middle}
th{font-size:11.5px;text-transform:uppercase;letter-spacing:.05em;color:var(--muted);font-weight:600}
tbody tr:hover{background:var(--hover)}td.n,th.n{text-align:right;font-variant-numeric:tabular-nums}
tbody tr:last-child td{border-bottom:0}
details.dt{margin-top:12px}details.dt summary{cursor:pointer;color:var(--ink2);font-size:12.5px}details.dt summary:hover{color:var(--ink)}
/* Formulaires et filtres */
.filters{display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;margin-bottom:16px}
label{display:flex;flex-direction:column;font-size:12.5px;color:var(--ink2);gap:4px}
input,select,button{font:inherit;padding:7px 10px;border:1px solid var(--line);border-radius:8px;background:var(--card);color:var(--ink);min-height:38px}
button,.btn{background:var(--accent);color:var(--accent-ink);border-color:transparent;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;gap:6px;padding:7px 14px;border-radius:8px;font-weight:500;min-height:38px}
.btn.secondary{background:var(--card);color:var(--ink);border:1px solid var(--line)}
.seg{display:inline-flex;background:var(--hover);border:1px solid var(--line);border-radius:9px;padding:3px;gap:2px}
.seg a{padding:5px 12px;border-radius:7px;color:var(--ink2);text-decoration:none;font-size:13px;white-space:nowrap}
.seg a:hover{color:var(--ink)}.seg a[aria-current]{background:var(--card);color:var(--ink);font-weight:600;box-shadow:0 1px 2px rgba(0,0,0,.08)}
/* États */
.tag{display:inline-flex;align-items:center;gap:5px;padding:1px 9px;border-radius:999px;font-size:12px;font-weight:600;background:var(--hover);color:var(--ink2);white-space:nowrap}
.tag::before{content:"";width:6px;height:6px;border-radius:50%;background:currentColor}
.tag.complete,.tag.ok{color:var(--good-ink);background:color-mix(in srgb,var(--good) 12%,transparent)}
.tag.failed,.tag.paid_no_ticket,.tag.ko{color:var(--crit-ink);background:color-mix(in srgb,var(--crit) 12%,transparent)}
.tag.pending,.tag.paid,.tag.warn{color:var(--warn-ink);background:color-mix(in srgb,var(--warn) 16%,transparent)}
.tag.reduit{color:var(--ink2);background:color-mix(in srgb,var(--serious) 18%,transparent)}
.dot{display:inline-block;width:8px;height:8px;border-radius:50%;background:var(--good);margin-right:6px}
.dot.off{background:var(--axis)}
.muted{color:var(--muted)}.note{font-size:12.5px;color:var(--ink2)}.err{color:var(--crit-ink)}
.alert{display:flex;gap:10px;align-items:flex-start;background:color-mix(in srgb,var(--crit) 8%,var(--card));border:1px solid color-mix(in srgb,var(--crit) 30%,transparent);border-radius:10px;padding:10px 14px;margin-bottom:10px}
.alert b{color:var(--crit-ink)}
.mono{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12.5px}
:focus-visible{outline:2px solid var(--accent);outline-offset:2px}
#tip{position:fixed;z-index:50;pointer-events:none;background:var(--ink);color:var(--card);border-radius:8px;padding:7px 10px;font-size:12.5px;line-height:1.35;max-width:260px;box-shadow:0 6px 20px rgba(0,0,0,.18)}
#tip strong{display:block;font-size:14px;font-variant-numeric:tabular-nums}#tip span{display:block;opacity:.75;white-space:pre-line}
@media (max-width:1100px){.c8,.c7,.c6,.c5,.c4{grid-column:span 12}}
@media (max-width:860px){
 .shell{grid-template-columns:1fr}
 .side{position:sticky;top:0;z-index:20;height:auto;flex-direction:row;align-items:center;padding:8px 12px;gap:8px}
 .brand{padding:0 6px 0 0}.brand small{display:none}
 .side nav{flex-direction:row;overflow-x:auto;gap:2px;scrollbar-width:none}.side nav a{padding:8px 10px;white-space:nowrap}.side nav a span{font-size:13px}
 .side nav a svg{display:none}.me{border:0;padding:0}.me>span:nth-child(2),.avatar{display:none}
 main{padding:16px 16px 48px}.t-v{font-size:22px}
 .hb{grid-template-columns:minmax(90px,40%) minmax(0,1fr) auto;gap:8px}
}
@media (forced-colors:active){.col>b,.hb-t b,.meter b{background:CanvasText}}
CSS;
}

function tag(string $status): string {
    $labels = ['pending' => 'en attente', 'paid' => 'payé, ticket en cours', 'complete' => 'livré', 'paid_no_ticket' => 'payé sans ticket',
               'failed' => 'refusé', 'canceled' => 'annulé', 'expired' => 'expiré'];
    return '<span class="tag ' . h($status) . '">' . h($labels[$status] ?? $status) . '</span>';
}

/* Périodes prédéfinies (?p=) ou personnalisée (?du=&au=), par défaut les 30 derniers jours */
const PERIODS = ['auj' => "Aujourd'hui", '7j' => '7 jours', '30j' => '30 jours', '90j' => '90 jours', 'mois' => 'Ce mois', 'an' => 'Cette année'];
function period_from_query(): array {
    $p = $_GET['p'] ?? '';
    $today = date('Y-m-d');
    if (isset(PERIODS[$p])) {
        return match ($p) {
            'auj' => [$today, $today], '7j' => [date('Y-m-d', strtotime('-6 days')), $today],
            '90j' => [date('Y-m-d', strtotime('-89 days')), $today], 'mois' => [date('Y-m-01'), $today], 'an' => [date('Y-01-01'), $today],
            default => [date('Y-m-d', strtotime('-29 days')), $today],
        };
    }
    $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['du'] ?? '') ? $_GET['du'] : date('Y-m-d', strtotime('-29 days'));
    $to = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['au'] ?? '') ? $_GET['au'] : $today;
    if ($from > $to) { [$from, $to] = [$to, $from]; }
    return [$from, $to];
}
function period_key(): string {
    $p = $_GET['p'] ?? '';
    return isset(PERIODS[$p]) ? $p : (isset($_GET['du']) ? 'perso' : '30j');
}

/* Barre de filtres : périodes prédéfinies, période libre, champs supplémentaires */
function period_bar(string $from, string $to, string $extra = '', array $keep = []): string {
    $cur = period_key();
    $out = '<form class="filters" method="get">' . seg('p', PERIODS, $cur, $keep);
    $out .= '<label>Du<input type="date" name="du" value="' . h($from) . '"></label><label>Au<input type="date" name="au" value="' . h($to) . '"></label>';
    foreach ($keep as $k => $v) { if (!in_array($k, ['p', 'du', 'au'], true)) { $out .= '<input type="hidden" name="' . h($k) . '" value="' . h((string)$v) . '">'; } }
    return $out . $extra . '<button type="submit">Appliquer</button></form>';
}

/* Libellé lisible d'une période */
function period_label(string $from, string $to): string {
    $f = fn($d) => date('d/m/Y', strtotime($d));
    return $from === $to ? 'le ' . $f($from) : 'du ' . $f($from) . ' au ' . $f($to);
}

/*
 * Zoom des graphiques temporels. Le grain dépend de la longueur de la période :
 * 1 jour → heures, jusqu'à 62 jours → jours, jusqu'à 2 ans → mois, au-delà → années.
 * Clés des tranches : 0..23 (heure), 'Y-m-d' (jour), 'Y-m-01' (mois), 'Y-01-01' (année).
 * Un clic sur une tranche ouvre la période qu'elle couvre (zoom_href), zoom_nav permet de remonter.
 */
const MOIS_FR = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
const MOIS_COURT = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
const JOURS_FR = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'];

function zoom_grain(string $from, string $to): string {
    $len = (strtotime($to) - strtotime($from)) / 86400 + 1;
    return $len <= 1 ? 'hour' : ($len <= 62 ? 'day' : ($len <= 731 ? 'month' : 'year'));
}
/* Expression SQL donnant la clé de tranche d'une colonne horodatée */
function zoom_sql(string $grain, string $col): string {
    return match ($grain) {
        'hour' => "extract(hour FROM $col)::int",
        'day' => "to_char($col, 'YYYY-MM-DD')",
        'month' => "to_char($col, 'YYYY-MM-01')",
        default => "to_char($col, 'YYYY-01-01')",
    };
}
/* Toutes les tranches de la période, pour des séries sans trou */
function zoom_keys(string $grain, string $from, string $to): array {
    if ($grain === 'hour') { return range(0, 23); }
    if ($grain === 'day') { return days_between($from, $to); }
    $out = [];
    $step = $grain === 'month' ? '+1 month' : '+1 year';
    $t = strtotime($grain === 'month' ? substr($from, 0, 7) . '-01' : substr($from, 0, 4) . '-01-01');
    for (; $t <= strtotime($to); $t = strtotime($step, $t)) { $out[] = date('Y-m-d', $t); }
    return $out;
}
/* Libellés d'une tranche : court (axe) et complet (infobulle) */
function zoom_label(string $grain, int|string $k, bool $long = false, bool $withYear = false): string {
    if ($grain === 'hour') { return $long ? sprintf('%d h – %d h', $k, $k + 1) : $k . 'h'; }
    $t = strtotime((string)$k);
    return match ($grain) {
        'day' => $long ? ucfirst(JOURS_FR[(int)date('w', $t)]) . ' ' . date('d/m/Y', $t) : date('d/m', $t),
        'month' => $long ? ucfirst(MOIS_FR[(int)date('n', $t) - 1]) . ' ' . date('Y', $t)
                         : MOIS_COURT[(int)date('n', $t) - 1] . ($withYear ? ' ' . date('y', $t) : ''),
        default => date('Y', $t),
    };
}
/* Période couverte par une tranche (bornée à la période affichée), null pour une heure */
function zoom_range(string $grain, int|string $k, string $from, string $to): ?array {
    if ($grain === 'hour') { return null; }
    $end = match ($grain) { 'day' => (string)$k, 'month' => date('Y-m-t', strtotime((string)$k)), default => substr((string)$k, 0, 4) . '-12-31' };
    return [max((string)$k, $from), min($end, $to)];
}
function zoom_href(string $grain, int|string $k, string $from, string $to, array $keep = [], string $page = ''): ?string {
    $r = zoom_range($grain, $k, $from, $to);
    return $r ? $page . '?' . http_build_query(['du' => $r[0], 'au' => $r[1]] + $keep) : null;
}

/*
 * Navigation du zoom : période précédente / suivante et remontée (jour → mois → année → toutes les années).
 * $since : première date ayant des données, pour « Toutes les années ».
 */
function zoom_nav(string $from, string $to, array $keep = [], ?string $since = null): string {
    $today = date('Y-m-d');
    $link = fn(string $f, string $t, string $label, string $cls = '') => '<a' . ($cls ? ' class="' . $cls . '"' : '') . ' href="?' . h(http_build_query(['du' => $f, 'au' => min($t, $today)] + $keep)) . '">' . $label . '</a>';
    $tf = strtotime($from);
    $isDay = $from === $to;
    $isMonth = !$isDay && $from === date('Y-m-01', $tf) && ($to === date('Y-m-t', $tf) || $to === $today && substr($to, 0, 7) === substr($from, 0, 7));
    $isYear = !$isDay && !$isMonth && $from === date('Y-01-01', $tf) && ($to === date('Y-12-31', $tf) || $to === $today && substr($to, 0, 4) === substr($from, 0, 4));
    $y = date('Y', strtotime($to)); $m = date('Y-m-01', strtotime($to));
    $crumbs = [];
    if ($since) { $crumbs[] = $link(substr($since, 0, 4) . '-01-01', $today, 'Toutes les années'); }
    if ($isYear) { $crumbs[] = '<b>' . $y . '</b>'; }
    elseif ($isMonth || $isDay) {
        $crumbs[] = $link("$y-01-01", "$y-12-31", $y);
        $crumbs[] = $isMonth ? '<b>' . h(MOIS_FR[(int)date('n', $tf) - 1]) . '</b>' : $link($m, date('Y-m-t', strtotime($m)), h(MOIS_FR[(int)date('n', $tf) - 1]));
        if ($isDay) { $crumbs[] = '<b>' . h(JOURS_FR[(int)date('w', $tf)] . ' ' . date('j', $tf)) . '</b>'; }
    } else {
        $crumbs[] = $link("$y-01-01", "$y-12-31", $y);
        $crumbs[] = $link($m, date('Y-m-t', strtotime($m)), h(MOIS_FR[(int)date('n', strtotime($m)) - 1]));
    }
    $arrows = '';
    if ($isDay || $isMonth || $isYear) {
        $unit = $isDay ? 'day' : ($isMonth ? 'month' : 'year');
        $shift = function (int $n) use ($unit, $tf) {
            $t = strtotime(($n > 0 ? '+' : '') . $n . ' ' . $unit, $tf);
            return match ($unit) { 'day' => [date('Y-m-d', $t), date('Y-m-d', $t)], 'month' => [date('Y-m-01', $t), date('Y-m-t', $t)], default => [date('Y-01-01', $t), date('Y-12-31', $t)] };
        };
        $names = ['day' => ['Jour précédent', 'Jour suivant'], 'month' => ['Mois précédent', 'Mois suivant'], 'year' => ['Année précédente', 'Année suivante']][$unit];
        [$pf, $pt] = $shift(-1); [$nf, $nt] = $shift(1);
        $arrows = $link($pf, $pt, '‹ ' . $names[0], 'zn-arrow')
                . ($nf <= $today ? $link($nf, $nt, $names[1] . ' ›', 'zn-arrow') : '<span class="zn-arrow" aria-disabled="true">' . $names[1] . ' ›</span>');
    }
    return '<nav class="zoomnav" aria-label="Zoom sur la période"><span class="zn-crumbs">' . implode('<span class="zn-sep">›</span>', $crumbs) . '</span>'
         . ($arrows ? '<span class="zn-arrows">' . $arrows . '</span>' : '') . '</nav>';
}

/* Jours d'une période, pour des séries sans trou */
function days_between(string $from, string $to): array {
    $out = [];
    for ($t = strtotime($from); $t <= strtotime($to); $t += 86400) { $out[] = date('Y-m-d', $t); }
    return $out;
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

/* Tickets en cours (état « running ») avec leur consommation, débit et connexion */
function running_tickets(?string $plan = null, int $limit = 500): array {
    $args = [];
    $wherePlan = '';
    if ($plan !== null && isset(plans()[$plan])) { $wherePlan = ' AND t.plan_id = ?'; $args[] = $plan; }
    $args[] = $limit;
    return all("SELECT t.username, t.plan_id, p.label, p.data_gb, t.first_login_at, t.expires_at, t.throttled_at,
                       COALESCE(u.used, 0) AS used, COALESCE(u.today, 0) AS today, s.active IS TRUE AS online
                FROM tickets t JOIN plans p ON p.id = t.plan_id
                LEFT JOIN (SELECT username, sum(bytes_down + bytes_up) AS used,
                                  sum(bytes_down + bytes_up) FILTER (WHERE ts >= date_trunc('day', now())) AS today
                           FROM usage_samples GROUP BY 1) u ON u.username = t.username
                LEFT JOIN (SELECT DISTINCT username, true AS active FROM usage_sessions WHERE active) s ON s.username = t.username
                WHERE t.state LIKE 'running%' AND (t.expires_at IS NULL OR t.expires_at > now())$wherePlan
                ORDER BY COALESCE(u.today, 0) DESC, COALESCE(u.used, 0) DESC LIMIT ?", $args);
}

/* « dans 2 j 4 h », « dans 35 min » */
function remaining(?string $ts): string {
    if (!$ts) { return '-'; }
    $s = strtotime($ts) - time();
    if ($s <= 0) { return 'expiré'; }
    if ($s >= 86400) { return intdiv($s, 86400) . ' j ' . intdiv($s % 86400, 3600) . ' h'; }
    if ($s >= 3600) { return intdiv($s, 3600) . ' h ' . intdiv($s % 3600, 60) . ' min'; }
    return max(1, intdiv($s, 60)) . ' min';
}
