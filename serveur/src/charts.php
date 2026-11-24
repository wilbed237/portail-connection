<?php
/*
 * Graphiques de l'espace admin, rendus côté serveur en HTML/CSS (aucune bibliothèque externe,
 * compatibles avec la politique de sécurité stricte des pages). L'infobulle (survol et clavier)
 * est ajoutée par public/admin/assets/admin.js à partir des attributs data-tip.
 * Couleurs : rôles CSS (--s1 bleu, --s2 orange…, --mtn jaune, --orange orange, --seq-*) définis dans admin_css().
 */

declare(strict_types=1);

/* Nombres courts : 12 500 → « 12,5 k », 1 200 000 → « 1,2 M » */
function num_short(float $n): string {
    $a = abs($n);
    if ($a >= 1e6) { return rtrim(rtrim(number_format($n / 1e6, 1, ',', ' '), '0'), ',') . ' M'; }
    if ($a >= 1e4) { return rtrim(rtrim(number_format($n / 1e3, 1, ',', ' '), '0'), ',') . ' k'; }
    if ($a < 10 && floor($n) != $n) { return rtrim(rtrim(number_format($n, 1, ',', ' '), '0'), ','); }
    return number_format($n, 0, ',', ' ');
}
function fcfa_short(float $n): string { return num_short($n) . ' F'; }
function gb(float $bytes): float { return $bytes / 1073741824; }
/* Valeur déjà en Go → texte (les graphiques reçoivent des Go pour avoir des graduations rondes) */
function go_h(float $go): string { return gb_h($go * 1073741824); }
function gb_h(float $bytes, int $dec = 1): string {
    $g = gb($bytes);
    return $g >= 1 || $bytes == 0 ? number_format($g, $g >= 100 ? 0 : $dec, ',', ' ') . ' Go' : number_format($bytes / 1048576, 0, ',', ' ') . ' Mo';
}

/* Échelle « ronde » de 0 au maximum : 4 à 5 graduations (1, 2, 2,5 ou 5 × 10^n) */
function nice_scale(float $max, int $ticks = 4): array {
    if ($max <= 0) { return [1, [0, 1]]; }
    $raw = $max / $ticks;
    $p = 10 ** floor(log10($raw));
    foreach ([1, 2, 2.5, 5, 10] as $m) { if ($m * $p >= $raw) { $step = $m * $p; break; } }
    $top = ceil($max / $step) * $step;
    $list = [];
    for ($v = 0; $v <= $top + $step / 2; $v += $step) { $list[] = $v; }
    return [$top, $list];
}

/*
 * Étiquettes de l'axe horizontal : une sur $every. Sur téléphone (classe .mh masquée en CSS),
 * on n'en garde qu'environ 6, choisies parmi celles déjà affichées.
 */
function xaxis_labels(array $items, int $every): string {
    $n = count($items);
    $everyM = $every * (int)max(1, ceil(ceil($n / 6) / $every));
    $out = '';
    foreach (array_values($items) as $k => $i) {
        $shown = $k % $every === 0;
        $out .= '<span' . ($shown && $k % $everyM !== 0 ? ' class="mh"' : '') . '>' . ($shown ? h($i['x']) : '') . '</span>';
    }
    return $out;
}

function tip_attrs(string $value, string $label): string {
    return ' data-tip="' . h($value) . '" data-tip-label="' . h($label) . '" tabindex="0"';
}

/*
 * Colonnes (une série) : $items = [['x' => libellé court, 'v' => valeur, 'tip' => libellé infobulle, 'hl' => bool, 'href' => lien du zoom]]
 * $o : fmt (valeur → texte), axis (valeur → texte court), every (1 libellé sur n), height, empty (message)
 */
function chart_columns(array $items, array $o = []): string {
    $fmt = $o['fmt'] ?? fn($v) => num_short((float)$v);
    $axis = $o['axis'] ?? fn($v) => num_short((float)$v);
    $h = $o['height'] ?? 220;
    if (!$items || max(array_map(fn($i) => (float)$i['v'], $items)) <= 0) {
        return '<div class="chart-empty" style="height:' . $h . 'px">' . h($o['empty'] ?? 'Pas encore de données sur cette période.') . '</div>';
    }
    [$top, $ticks] = nice_scale(max(array_map(fn($i) => (float)$i['v'], $items)));
    $every = $o['every'] ?? max(1, (int)ceil(count($items) / 12));
    $out = '<div class="chart cols" style="--h:' . $h . 'px"><div class="yaxis">';
    foreach (array_reverse($ticks) as $t) { $out .= '<span style="bottom:' . round($t / $top * 100, 3) . '%">' . h($axis($t)) . '</span>'; }
    $out .= '</div><div class="plot">';
    foreach ($ticks as $t) { $out .= '<i class="gl" style="bottom:' . round($t / $top * 100, 3) . '%"></i>'; }
    $out .= '<div class="bars" style="--n:' . count($items) . '">';
    foreach ($items as $k => $i) {
        $pct = max(0, (float)$i['v']) / $top * 100;
        $cls = !empty($i['hl']) ? ' hl' : '';
        $tag = isset($i['href']) ? 'a' : 'div';
        $out .= '<' . $tag . ' class="col' . $cls . '"' . (isset($i['href']) ? ' href="' . h($i['href']) . '"' : '')
              . tip_attrs($fmt($i['v']), $i['tip'] ?? $i['x']) . '><b style="height:' . round($pct, 3) . '%"></b></' . $tag . '>';
    }
    $out .= '</div></div><div class="xaxis" style="--n:' . count($items) . '">';
    $out .= xaxis_labels($items, $every);
    $hint = array_filter($items, fn($i) => isset($i['href'])) ? '<p class="chart-hint">' . h($o['hint'] ?? 'Cliquez sur une barre pour zoomer.') . '</p>' : '';
    return $out . '</div></div>' . $hint;
}

/*
 * Colonnes empilées : $series = [['name' => …, 'color' => 'var(--s1)']], $items = [['x', 'vals' => [v1, v2…], 'tip', 'href']]
 * Avec 'href', la colonne devient un lien (clic ou Entrée).
 * Légende toujours affichée (au moins deux séries).
 */
function chart_stacked(array $items, array $series, array $o = []): string {
    $fmt = $o['fmt'] ?? fn($v) => num_short((float)$v);
    $axis = $o['axis'] ?? fn($v) => num_short((float)$v);
    $h = $o['height'] ?? 220;
    $legend = '<div class="legend">';
    foreach ($series as $s) { $legend .= '<span><i style="background:' . $s['color'] . '"></i>' . h($s['name']) . '</span>'; }
    $legend .= '</div>';
    $tot = array_map(fn($i) => array_sum($i['vals']), $items);
    if (!$items || max($tot) <= 0) {
        return $legend . '<div class="chart-empty" style="height:' . $h . 'px">' . h($o['empty'] ?? 'Pas encore de données sur cette période.') . '</div>';
    }
    [$top, $ticks] = nice_scale(max($tot));
    $every = $o['every'] ?? max(1, (int)ceil(count($items) / 12));
    $out = $legend . '<div class="chart cols" style="--h:' . $h . 'px"><div class="yaxis">';
    foreach (array_reverse($ticks) as $t) { $out .= '<span style="bottom:' . round($t / $top * 100, 3) . '%">' . h($axis($t)) . '</span>'; }
    $out .= '</div><div class="plot">';
    foreach ($ticks as $t) { $out .= '<i class="gl" style="bottom:' . round($t / $top * 100, 3) . '%"></i>'; }
    $out .= '<div class="bars" style="--n:' . count($items) . '">';
    foreach ($items as $k => $i) {
        $tip = [];
        foreach ($series as $j => $s) { $tip[] = $s['name'] . ' : ' . $fmt($i['vals'][$j] ?? 0); }
        $tag = isset($i['href']) ? 'a' : 'div';
        $out .= '<' . $tag . ' class="col stack"' . (isset($i['href']) ? ' href="' . h($i['href']) . '"' : '')
              . tip_attrs($fmt($tot[$k]), ($i['tip'] ?? $i['x']) . "\n" . implode("\n", $tip)) . '><div class="st" style="height:' . round($tot[$k] / $top * 100, 3) . '%">';
        foreach (array_reverse($series, true) as $j => $s) {
            $v = (float)($i['vals'][$j] ?? 0);
            if ($v > 0) { $out .= '<b style="flex-grow:' . round($v, 2) . ';background:' . $s['color'] . '"></b>'; }
        }
        $out .= '</div></' . $tag . '>';
    }
    $out .= '</div></div><div class="xaxis" style="--n:' . count($items) . '">';
    $out .= xaxis_labels($items, $every);
    $hint = array_filter($items, fn($i) => isset($i['href'])) ? '<p class="chart-hint">' . h($o['hint'] ?? 'Cliquez sur une barre pour zoomer.') . '</p>' : '';
    return $out . '</div></div>' . $hint;
}

/*
 * Barres horizontales classées : $rows = [['label', 'v', 'value' => texte affiché, 'sub' => texte secondaire, 'href']]
 */
function chart_hbars(array $rows, array $o = []): string {
    if (!$rows) { return '<div class="chart-empty" style="height:120px">' . h($o['empty'] ?? 'Aucune donnée sur cette période.') . '</div>'; }
    $max = max(array_map(fn($r) => (float)$r['v'], $rows)) ?: 1;
    $out = '<div class="hbars">';
    foreach ($rows as $r) {
        $label = isset($r['href']) ? '<a href="' . h($r['href']) . '">' . h($r['label']) . '</a>' : h($r['label']);
        $out .= '<div class="hb"' . tip_attrs($r['value'], $r['label'] . (isset($r['sub']) ? ' · ' . $r['sub'] : '')) . '>'
              . '<div class="hb-l">' . $label . (isset($r['sub']) ? '<small>' . h($r['sub']) . '</small>' : '') . '</div>'
              . '<div class="hb-t"><b style="width:' . round(max(0, (float)$r['v']) / $max * 100, 2) . '%' . (isset($r['color']) ? ';background:' . $r['color'] : '') . '"></b></div>'
              . '<div class="hb-v">' . h($r['value']) . '</div></div>';
    }
    return $out . '</div>';
}

/*
 * Carte de chaleur jour × heure (rampe séquentielle bleue, 6 marches) : $grid[jour 1..7][heure 0..23] = valeur
 */
function chart_heatmap(array $grid, callable $fmt): string {
    $days = [1 => 'Lun', 2 => 'Mar', 3 => 'Mer', 4 => 'Jeu', 5 => 'Ven', 6 => 'Sam', 7 => 'Dim'];
    $full = [1 => 'lundi', 2 => 'mardi', 3 => 'mercredi', 4 => 'jeudi', 5 => 'vendredi', 6 => 'samedi', 7 => 'dimanche'];
    $max = 0;
    foreach ($grid as $row) { foreach ($row as $v) { $max = max($max, (float)$v); } }
    if ($max <= 0) { return '<div class="chart-empty" style="height:180px">Pas encore de données sur cette période.</div>'; }
    $out = '<div class="heat"><span></span>';
    for ($hh = 0; $hh < 24; $hh++) { $out .= '<span class="hx">' . ($hh % 3 === 0 ? $hh . 'h' : '') . '</span>'; }
    foreach ($days as $d => $name) {
        $out .= '<span class="hy">' . $name . '</span>';
        for ($hh = 0; $hh < 24; $hh++) {
            $v = (float)($grid[$d][$hh] ?? 0);
            $step = $v <= 0 ? 0 : min(6, 1 + (int)floor($v / $max * 6));
            $out .= '<i class="q' . $step . '"' . tip_attrs($fmt($v), ucfirst($full[$d]) . ', ' . $hh . ' h – ' . ($hh + 1) . ' h') . '></i>';
        }
    }
    $out .= '</div><div class="heat-key"><span>moins</span>';
    for ($i = 1; $i <= 6; $i++) { $out .= '<i class="q' . $i . '"></i>'; }
    return $out . '<span>plus</span></div>';
}

/* Courbe minimale pour une tuile (12 à 30 points), dernier point accentué */
function sparkline(array $values): string {
    $n = count($values);
    if ($n < 2) { return ''; }
    $max = max($values) ?: 1;
    $pts = [];
    foreach (array_values($values) as $i => $v) { $pts[] = round($i / ($n - 1) * 100, 2) . ',' . round(28 - $v / $max * 26, 2); }
    $last = explode(',', end($pts));
    return '<svg class="spark" viewBox="0 0 100 30" preserveAspectRatio="none" aria-hidden="true"><polyline points="' . implode(' ', $pts)
         . '" vector-effect="non-scaling-stroke"/><circle cx="' . $last[0] . '" cy="' . $last[1] . '" r="2.2" vector-effect="non-scaling-stroke"/></svg>';
}

/*
 * Tuile de chiffre clé : libellé, valeur, variation (signée, par rapport à une période nommée), courbe
 * $delta = [pourcentage|null, 'hier'], $upGood : une hausse est-elle une bonne nouvelle ?
 */
function stat_tile(string $label, string $value, ?array $delta = null, array $spark = [], string $note = '', bool $upGood = true): string {
    $d = '';
    if ($delta && $delta[0] !== null) {
        $pct = $delta[0];
        $dir = $pct > 0.5 ? 'up' : ($pct < -0.5 ? 'down' : 'flat');
        $good = $dir === 'flat' ? 'flat' : (($dir === 'up') === $upGood ? 'good' : 'bad');
        $arrow = ['up' => '▲', 'down' => '▼', 'flat' => '■'][$dir];
        $d = '<span class="delta ' . $good . '">' . $arrow . ' ' . ($pct > 0 ? '+' : '') . number_format($pct, 0, ',', ' ') . ' %</span> <span class="vs">vs ' . h($delta[1]) . '</span>';
    } elseif ($delta) {
        $d = '<span class="vs">rien ' . h($delta[1]) . '</span>';
    }
    return '<div class="tile"><div class="t-l">' . h($label) . '</div><div class="t-v">' . h($value) . '</div>'
         . ($d ? '<div class="t-d">' . $d . '</div>' : '') . ($note ? '<div class="t-n">' . $note . '</div>' : '')
         . ($spark ? sparkline($spark) : '') . '</div>';
}

function pct_change(float $now, float $before): ?float { return $before > 0 ? ($now - $before) / $before * 100 : null; }

/* Jauge de consommation d'un ticket : remplissage = part du volume, couleur = état */
function meter(float $used, float $quota, bool $throttled = false): string {
    if ($quota <= 0) { return '<span class="note">sans limite de volume</span>'; }
    $pct = min(100, $used / $quota * 100);
    $state = $throttled ? 'reduit' : ($pct >= 90 ? 'crit' : ($pct >= 70 ? 'warn' : 'ok'));
    return '<div class="meter ' . $state . '" role="img" aria-label="' . h(round($pct) . ' % du volume utilisé') . '"><b style="width:' . round($pct, 1) . '%"></b></div>'
         . '<div class="meter-l"><span>' . h(gb_h($used)) . ' / ' . h(gb_h($quota, 0)) . '</span><span>' . round($pct) . ' %</span></div>';
}

/* Tableau de données d'un graphique (accessibilité, et valeurs exactes sans survol) */
function data_table(array $head, array $rows, string $summary = 'Voir les données'): string {
    $out = '<details class="dt"><summary>' . h($summary) . '</summary><div class="scroll"><table><thead><tr>';
    foreach ($head as $i => $c) { $out .= '<th' . ($i ? ' class="n"' : '') . '>' . h($c) . '</th>'; }
    $out .= '</tr></thead><tbody>';
    foreach ($rows as $r) {
        $out .= '<tr>';
        foreach (array_values($r) as $i => $c) { $out .= '<td' . ($i ? ' class="n"' : '') . '>' . h((string)$c) . '</td>'; }
        $out .= '</tr>';
    }
    return $out . '</tbody></table></div></details>';
}
