<?php
/*
 * Rapports PDF de l'espace admin. Chaque rapport rappelle sa période, ses filtres, qui l'a généré et quand,
 * compare avec la période précédente de même durée, puis détaille toutes les valeurs (totaux en bas des tableaux).
 *   report_ventes()  : ventes d'une période (synthèse, opérateurs, forfaits, états, tranches, heures, liste des commandes)
 *   report_conso()   : consommation d'une période (synthèse, tranches, forfaits, heures, jours, tickets)
 *   report_compta()  : comptabilité d'un mois (synthèse, forfaits, opérateurs, jours, 12 derniers mois, solde IZI Spot)
 */

declare(strict_types=1);

function r_n(float|int|string|null $n): string { return number_format((float)$n, 0, ',', ' '); }
function r_f(float|int|string|null $n): string { return r_n($n) . ' FCFA'; }
function r_pct(float $part, float $total): string { return $total > 0 ? number_format($part * 100 / $total, 1, ',', ' ') . ' %' : '-'; }
function r_delta(float $cur, float $prev): string {
    if ($prev == 0.0) { return $cur == 0.0 ? '=' : 'nouveau'; }
    $d = ($cur - $prev) * 100 / $prev;
    return ($d > 0 ? '+' : '') . number_format($d, 1, ',', ' ') . ' %';
}
function r_d(?string $ts, string $fmt = 'd/m/Y H:i'): string { return $ts ? date($fmt, strtotime($ts)) : ''; }
function r_period(string $from, string $to): string {
    return $from === $to ? 'le ' . date('d/m/Y', strtotime($from)) : 'du ' . date('d/m/Y', strtotime($from)) . ' au ' . date('d/m/Y', strtotime($to));
}
function r_meta(array $me, array $extra = []): array {
    return array_merge($extra, ['Généré le ' . date('d/m/Y à H:i') . ' par ' . $me['user'] . ' (' . $me['role'] . ') · montants en francs CFA (FCFA), heure du Cameroun']);
}
const R_STATUS = ['pending' => 'En attente', 'paid' => 'Payé, ticket en cours', 'complete' => 'Livré', 'paid_no_ticket' => 'Payé sans ticket',
                  'failed' => 'Refusé', 'canceled' => 'Annulé', 'expired' => 'Expiré'];
const R_OP = ['mtn' => 'MTN Mobile Money', 'orange' => 'Orange Money'];

/* ======================= Ventes ======================= */

function report_ventes(string $from, string $to, string $w, array $args, string $filters, array $me, callable $phone): never {
    $paidIn = ' AND o.status IN ' . PAID_STATUSES;
    $len = (int)round((strtotime($to) - strtotime($from)) / 86400) + 1;
    $pf = date('Y-m-d', strtotime("$from -$len days")); $pt = date('Y-m-d', strtotime("$from -1 day"));
    $agg = "count(*) FILTER (WHERE o.status IN " . PAID_STATUSES . ") AS n, COALESCE(sum(o.amount) FILTER (WHERE o.status IN " . PAID_STATUSES . "),0) AS ca,
            COALESCE(sum(o.fee) FILTER (WHERE o.status IN " . PAID_STATUSES . "),0) AS frais, count(*) FILTER (WHERE o.status <> 'pending') AS tries,
            count(*) FILTER (WHERE o.status IN ('failed','expired','canceled')) AS ko, count(*) FILTER (WHERE o.status = 'pending') AS attente, count(*) AS total";
    $c = one("SELECT $agg FROM orders o WHERE $w", $args);
    $p = one("SELECT $agg FROM orders o WHERE $w", array_merge([$pf, $pt], array_slice($args, 2)));
    $rate = fn($r) => $r['tries'] ? $r['n'] * 100 / $r['tries'] : 0;
    $basket = fn($r) => $r['n'] ? $r['ca'] / $r['n'] : 0;

    $pdf = new Pdf('Rapport des ventes', r_period($from, $to), r_meta($me, ["Filtres : $filters", 'Comparaison : période précédente de même durée, ' . r_period($pf, $pt) . '.']));
    $pdf->h2('Synthèse');
    $pdf->kpis([
        ["Chiffre d'affaires", r_f($c['ca']), r_delta((float)$c['ca'], (float)$p['ca']) . ' vs période précédente'],
        ['Frais IZI Spot', r_f($c['frais']), r_pct((float)$c['frais'], (float)$c['ca']) . " du chiffre d'affaires"],
        ['Net crédité', r_f($c['ca'] - $c['frais']), r_delta($c['ca'] - $c['frais'], $p['ca'] - $p['frais']) . ' vs période précédente'],
        ['Ventes encaissées', r_n($c['n']), r_delta((float)$c['n'], (float)$p['n']) . ' vs période précédente'],
        ['Panier moyen', r_f($basket($c)), r_delta($basket($c), $basket($p)) . ' vs période précédente'],
        ['Paiements réussis', number_format($rate($c), 1, ',', ' ') . ' %', r_n($c['ko']) . ' refusés, annulés ou expirés · ' . r_n($c['attente']) . ' en attente'],
    ]);
    $pdf->table([['Indicateur', 3], [r_period($from, $to), 2, 'R'], [r_period($pf, $pt), 2, 'R'], ['Évolution', 1.2, 'R']], [
        ["Chiffre d'affaires", r_f($c['ca']), r_f($p['ca']), r_delta((float)$c['ca'], (float)$p['ca'])],
        ['Frais IZI Spot', r_f($c['frais']), r_f($p['frais']), r_delta((float)$c['frais'], (float)$p['frais'])],
        ['Net crédité', r_f($c['ca'] - $c['frais']), r_f($p['ca'] - $p['frais']), r_delta($c['ca'] - $c['frais'], $p['ca'] - $p['frais'])],
        ['Ventes encaissées', r_n($c['n']), r_n($p['n']), r_delta((float)$c['n'], (float)$p['n'])],
        ['Panier moyen', r_f($basket($c)), r_f($basket($p)), r_delta($basket($c), $basket($p))],
        ['Commandes (tous états)', r_n($c['total']), r_n($p['total']), r_delta((float)$c['total'], (float)$p['total'])],
        ['Taux de paiements réussis', number_format($rate($c), 1, ',', ' ') . ' %', number_format($rate($p), 1, ',', ' ') . ' %', ''],
    ]);

    $pdf->h2('Par opérateur', 'Ventes encaissées uniquement.');
    $ops = all("SELECT o.operator, count(*) AS n, sum(o.amount) AS ca, sum(o.fee) AS frais FROM orders o WHERE $w$paidIn GROUP BY 1 ORDER BY 3 DESC", $args);
    $pdf->table([['Opérateur', 3], ['Ventes', 1.2, 'R'], ["Chiffre d'affaires", 2, 'R'], ['Frais', 1.6, 'R'], ['Net', 2, 'R'], ['Part', 1.1, 'R']],
        array_map(fn($r) => [R_OP[$r['operator']] ?? $r['operator'], r_n($r['n']), r_f($r['ca']), r_f($r['frais']), r_f($r['ca'] - $r['frais']), r_pct((float)$r['ca'], (float)$c['ca'])], $ops),
        ['total' => ['Total', r_n($c['n']), r_f($c['ca']), r_f($c['frais']), r_f($c['ca'] - $c['frais']), $c['ca'] ? '100 %' : '-']]);

    $pdf->h2('Par forfait', 'Ventes encaissées uniquement.');
    $plans = all("SELECT p.label, p.price, count(*) AS n, sum(o.amount) AS ca, sum(o.fee) AS frais FROM orders o JOIN plans p ON p.id = o.plan_id
                  WHERE $w$paidIn GROUP BY 1, 2 ORDER BY 4 DESC", $args);
    $pdf->table([['Forfait', 3.4], ['Prix', 1.4, 'R'], ['Ventes', 1, 'R'], ["Chiffre d'affaires", 2, 'R'], ['Frais', 1.5, 'R'], ['Net', 1.9, 'R'], ['Part', 1, 'R']],
        array_map(fn($r) => [$r['label'], r_f($r['price']), r_n($r['n']), r_f($r['ca']), r_f($r['frais']), r_f($r['ca'] - $r['frais']), r_pct((float)$r['ca'], (float)$c['ca'])], $plans),
        ['total' => ['Total', '', r_n($c['n']), r_f($c['ca']), r_f($c['frais']), r_f($c['ca'] - $c['frais']), $c['ca'] ? '100 %' : '-']]);

    $pdf->h2('Commandes par état', 'Toutes les commandes de la période, payées ou non.');
    $st = all("SELECT o.status, count(*) AS n, COALESCE(sum(o.amount),0) AS m FROM orders o WHERE $w GROUP BY 1 ORDER BY 2 DESC", $args);
    $pdf->table([['État', 3], ['Commandes', 1.5, 'R'], ['Montant', 2, 'R'], ['Part des commandes', 1.6, 'R']],
        array_map(fn($r) => [R_STATUS[$r['status']] ?? $r['status'], r_n($r['n']), r_f($r['m']), r_pct((float)$r['n'], (float)$c['total'])], $st),
        ['total' => ['Total', r_n($c['total']), r_f(array_sum(array_column($st, 'm'))), $c['total'] ? '100 %' : '-']]);

    $grain = zoom_grain($from, $to);
    $title = ['hour' => 'Recettes heure par heure', 'day' => 'Recettes jour par jour', 'month' => 'Recettes mois par mois', 'year' => 'Recettes année par année'][$grain];
    $pdf->h2($title, 'Ventes encaissées, datées du paiement.');
    $keys = [];
    foreach (zoom_keys($grain, $from, $to) as $k) { $keys[$k] = ['mtn' => 0, 'orange' => 0, 'n' => 0, 'frais' => 0]; }
    foreach (all("SELECT " . zoom_sql($grain, 'COALESCE(o.paid_at, o.created_at)') . " AS k, o.operator, count(*) AS n, sum(o.amount) AS ca, sum(o.fee) AS frais
                  FROM orders o WHERE $w$paidIn GROUP BY 1, 2", $args) as $r) {
        $k = $grain === 'hour' ? (int)$r['k'] : $r['k'];
        if (!isset($keys[$k][$r['operator']])) { continue; }
        $keys[$k][$r['operator']] += (float)$r['ca']; $keys[$k]['n'] += (int)$r['n']; $keys[$k]['frais'] += (float)$r['frais'];
    }
    $sum = ['mtn' => 0, 'orange' => 0, 'n' => 0, 'frais' => 0];
    $rows = [];
    foreach ($keys as $k => $v) {
        foreach ($sum as $kk => $_) { $sum[$kk] += $v[$kk]; }
        $rows[] = [zoom_label($grain, $k, true), r_n($v['n']), r_f($v['mtn']), r_f($v['orange']), r_f($v['mtn'] + $v['orange']), r_f($v['mtn'] + $v['orange'] - $v['frais'])];
    }
    $pdf->table([[['hour' => 'Heure', 'day' => 'Jour', 'month' => 'Mois', 'year' => 'Année'][$grain], 2.6], ['Ventes', 1, 'R'], ['MTN', 1.9, 'R'], ['Orange', 1.9, 'R'], ['Total', 2, 'R'], ['Net', 2, 'R']],
        $rows, ['total' => ['Total', r_n($sum['n']), r_f($sum['mtn']), r_f($sum['orange']), r_f($sum['mtn'] + $sum['orange']), r_f($sum['mtn'] + $sum['orange'] - $sum['frais'])]]);

    $pdf->h2("Heures d'achat", 'Nombre de ventes encaissées selon l\'heure du paiement, toute la période confondue.');
    $hours = array_fill(0, 24, ['n' => 0, 'ca' => 0]);
    foreach (all("SELECT extract(hour FROM COALESCE(o.paid_at, o.created_at))::int AS h, count(*) AS n, sum(o.amount) AS ca FROM orders o WHERE $w$paidIn GROUP BY 1", $args) as $r) {
        $hours[(int)$r['h']] = ['n' => (int)$r['n'], 'ca' => (float)$r['ca']];
    }
    $pdf->table([['Heure', 2], ['Ventes', 1, 'R'], ["Chiffre d'affaires", 2, 'R'], ['Part des ventes', 1.4, 'R']],
        array_map(fn($h) => [sprintf('%02d h – %02d h', $h, $h + 1), r_n($hours[$h]['n']), r_f($hours[$h]['ca']), r_pct($hours[$h]['n'], (float)$c['n'])], range(0, 23)),
        ['total' => ['Total', r_n($c['n']), r_f($c['ca']), $c['n'] ? '100 %' : '-'], 'size' => 8]);

    // Liste complète des commandes, en paysage
    $list = all("SELECT o.created_at, o.reference, p.label, o.amount, o.fee, o.operator, o.phone, o.status, o.paid_at, o.ticket_username, o.ticket_source
                 FROM orders o JOIN plans p ON p.id = o.plan_id WHERE $w ORDER BY o.created_at LIMIT 20000", $args);
    $pdf->addPage(true);
    $pdf->h2('Détail des commandes', r_n(count($list)) . ' commandes de la période, de la plus ancienne à la plus récente'
        . (count($list) >= 20000 ? ' (limité aux 20 000 premières : réduisez la période pour tout voir)' : '') . ($me['role'] === 'admin' ? '.' : ' · numéros masqués pour le rôle comptable.'));
    $pdf->table([['Créée le', 1.45], ['Référence', 1.75], ['Forfait', 2.2], ['Montant', 1.15, 'R'], ['Frais', 0.9, 'R'], ['Net', 1.15, 'R'],
                 ['Opérateur', 0.85], ['Téléphone', 1.2], ['État', 1.5], ['Payée le', 1.45], ['Ticket', 1.1], ['Source', 0.7]],
        array_map(fn($r) => [r_d($r['created_at']), $r['reference'], $r['label'], r_n($r['amount']), r_n($r['fee']), in_array($r['status'], ['paid', 'complete', 'paid_no_ticket'], true) ? r_n($r['amount'] - $r['fee']) : '',
            $r['operator'] === 'mtn' ? 'MTN' : 'Orange', $phone($r['phone']), R_STATUS[$r['status']] ?? $r['status'], r_d($r['paid_at']), (string)$r['ticket_username'],
            $r['ticket_source'] === 'stock' ? 'stock' : ($r['ticket_source'] ? 'routeur' : '')], $list),
        ['size' => 7.5, 'empty' => 'Aucune commande sur cette période.']);

    audit($me['user'], 'export ventes PDF', "$from → $to");
    $pdf->send("gandal-ventes-$from-au-$to.pdf");
}

/* ======================= Consommation ======================= */

function report_conso(string $from, string $to, string $grain, array $me): never {
    $range = "ts >= ?::date AND ts < ?::date + interval '1 day'";
    $args = [$from, $to];
    $len = (int)round((strtotime($to) - strtotime($from)) / 86400) + 1;
    $pf = date('Y-m-d', strtotime("$from -$len days")); $pt = date('Y-m-d', strtotime("$from -1 day"));
    $sumq = "SELECT COALESCE(sum(bytes_down),0) AS d, COALESCE(sum(bytes_up),0) AS u, count(DISTINCT username) AS users FROM usage_samples WHERE $range";
    $c = one($sumq, $args); $p = one($sumq, [$pf, $pt]);
    $tot = $c['d'] + $c['u']; $ptot = $p['d'] + $p['u'];
    $days = max(1, min($len, (int)ceil((time() - strtotime($from)) / 86400)));
    $grainLabel = ['hour' => 'Heure', 'day' => 'Jour', 'week' => 'Semaine', 'month' => 'Mois', 'year' => 'Année'][$grain];

    $pdf = new Pdf('Rapport de consommation', r_period($from, $to), r_meta($me, [
        'Regroupement : par ' . mb_strtolower($grainLabel) . ' · comparaison avec la période ' . r_period($pf, $pt) . ' · 1 Go = 1 024 Mo.']));
    $pdf->h2('Synthèse');
    $pdf->kpis([
        ['Données consommées', gb_h($tot, 2), r_delta((float)$tot, (float)$ptot) . ' vs période précédente'],
        ['Réception (téléchargement)', gb_h((float)$c['d'], 2), r_pct((float)$c['d'], (float)$tot) . ' du total'],
        ['Envoi', gb_h((float)$c['u'], 2), r_pct((float)$c['u'], (float)$tot) . ' du total'],
        ['Moyenne par jour', gb_h($tot / $days, 2), 'sur ' . $days . ' jour' . ($days > 1 ? 's' : '') . ' écoulé' . ($days > 1 ? 's' : '')],
        ['Tickets actifs', r_n($c['users']), r_delta((float)$c['users'], (float)$p['users']) . ' vs période précédente'],
        ['Moyenne par ticket', gb_h($c['users'] ? $tot / $c['users'] : 0, 2), ''],
    ]);
    $pdf->table([['Indicateur', 3], [r_period($from, $to), 2, 'R'], [r_period($pf, $pt), 2, 'R'], ['Évolution', 1.2, 'R']], [
        ['Données consommées', gb_h($tot, 2), gb_h($ptot, 2), r_delta((float)$tot, (float)$ptot)],
        ['Réception', gb_h((float)$c['d'], 2), gb_h((float)$p['d'], 2), r_delta((float)$c['d'], (float)$p['d'])],
        ['Envoi', gb_h((float)$c['u'], 2), gb_h((float)$p['u'], 2), r_delta((float)$c['u'], (float)$p['u'])],
        ['Tickets actifs', r_n($c['users']), r_n($p['users']), r_delta((float)$c['users'], (float)$p['users'])],
        ['Moyenne par ticket', gb_h($c['users'] ? $tot / $c['users'] : 0, 2), gb_h($p['users'] ? $ptot / $p['users'] : 0, 2), ''],
    ]);

    $pdf->h2('Consommation par ' . mb_strtolower($grainLabel));
    $series = all("SELECT date_trunc('$grain', ts) AS periode, sum(bytes_down) AS d, sum(bytes_up) AS u, count(DISTINCT username) AS users
                   FROM usage_samples WHERE $range GROUP BY 1 ORDER BY 1", $args);
    $fmt = fn($ts) => match ($grain) { 'hour' => date('d/m/Y H\h', strtotime($ts)), 'day' => zoom_label('day', date('Y-m-d', strtotime($ts)), true),
        'week' => 'Semaine du ' . date('d/m/Y', strtotime($ts)), 'month' => zoom_label('month', date('Y-m-d', strtotime($ts)), true), default => date('Y', strtotime($ts)) };
    $pdf->table([[$grainLabel, 3], ['Réception', 2, 'R'], ['Envoi', 2, 'R'], ['Total', 2, 'R'], ['Tickets actifs', 1.4, 'R'], ['Part', 1.1, 'R']],
        array_map(fn($r) => [$fmt($r['periode']), gb_h((float)$r['d'], 2), gb_h((float)$r['u'], 2), gb_h($r['d'] + $r['u'], 2), r_n($r['users']), r_pct($r['d'] + $r['u'], (float)$tot)], $series),
        ['total' => ['Total', gb_h((float)$c['d'], 2), gb_h((float)$c['u'], 2), gb_h($tot, 2), r_n($c['users']), $tot ? '100 %' : '-']]);

    $pdf->h2('Par forfait');
    $plans = all("SELECT COALESCE(p.label, 'Autres tickets (hors GANDAL)') AS label, sum(s.bytes_down) AS d, sum(s.bytes_up) AS u, count(DISTINCT s.username) AS users
                  FROM usage_samples s LEFT JOIN plans p ON p.id = s.plan_id WHERE $range GROUP BY 1 ORDER BY sum(s.bytes_down + s.bytes_up) DESC", $args);
    $pdf->table([['Forfait', 3.2], ['Tickets', 1, 'R'], ['Réception', 1.8, 'R'], ['Envoi', 1.6, 'R'], ['Total', 1.8, 'R'], ['Par ticket', 1.6, 'R'], ['Part', 1, 'R']],
        array_map(fn($r) => [$r['label'], r_n($r['users']), gb_h((float)$r['d'], 2), gb_h((float)$r['u'], 2), gb_h($r['d'] + $r['u'], 2),
            gb_h($r['users'] ? ($r['d'] + $r['u']) / $r['users'] : 0, 2), r_pct($r['d'] + $r['u'], (float)$tot)], $plans),
        ['total' => ['Total', r_n($c['users']), gb_h((float)$c['d'], 2), gb_h((float)$c['u'], 2), gb_h($tot, 2), gb_h($c['users'] ? $tot / $c['users'] : 0, 2), $tot ? '100 %' : '-']]);

    $pdf->h2('Heures de la journée', 'Consommation cumulée sur toute la période, selon l\'heure.');
    $hours = array_fill(0, 24, ['b' => 0, 'users' => 0]);
    foreach (all("SELECT extract(hour FROM ts)::int AS h, sum(bytes_down + bytes_up) AS b, count(DISTINCT username) AS users FROM usage_samples WHERE $range GROUP BY 1", $args) as $r) {
        $hours[(int)$r['h']] = ['b' => (float)$r['b'], 'users' => (int)$r['users']];
    }
    $peak = array_search(max(array_column($hours, 'b')), array_column($hours, 'b'), true);
    $pdf->table([['Heure', 2], ['Consommation', 2, 'R'], ['Tickets actifs', 1.4, 'R'], ['Part', 1.2, 'R']],
        array_map(fn($h) => [sprintf('%02d h – %02d h', $h, $h + 1) . ($tot && $h === $peak ? '  (pic)' : ''), gb_h($hours[$h]['b'], 2), r_n($hours[$h]['users']), r_pct($hours[$h]['b'], (float)$tot)], range(0, 23)),
        ['total' => ['Total', gb_h($tot, 2), '', $tot ? '100 %' : '-'], 'size' => 8]);

    $pdf->h2('Jours de la semaine', 'Consommation cumulée selon le jour de la semaine.');
    $jours = [1 => 'Lundi', 2 => 'Mardi', 3 => 'Mercredi', 4 => 'Jeudi', 5 => 'Vendredi', 6 => 'Samedi', 7 => 'Dimanche'];
    $dow = array_fill(1, 7, 0.0);
    foreach (all("SELECT extract(isodow FROM ts)::int AS d, sum(bytes_down + bytes_up) AS b FROM usage_samples WHERE $range GROUP BY 1", $args) as $r) { $dow[(int)$r['d']] = (float)$r['b']; }
    $pdf->table([['Jour', 2], ['Consommation', 2, 'R'], ['Part', 1.2, 'R']],
        array_map(fn($d) => [$jours[$d], gb_h($dow[$d], 2), r_pct($dow[$d], (float)$tot)], range(1, 7)), ['total' => ['Total', gb_h($tot, 2), $tot ? '100 %' : '-']]);

    $pdf->h2('Tickets qui consomment le plus', '50 premiers tickets de la période.');
    $top = all("SELECT s.username, COALESCE(p.label, '') AS label, t.state, t.first_login_at, sum(s.bytes_down) AS d, sum(s.bytes_up) AS u
                FROM usage_samples s LEFT JOIN plans p ON p.id = s.plan_id LEFT JOIN tickets t ON t.username = s.username
                WHERE $range GROUP BY 1, 2, 3, 4 ORDER BY sum(s.bytes_down + s.bytes_up) DESC LIMIT 50", $args);
    $pdf->table([['#', 0.5, 'R'], ['Ticket', 1.5], ['Forfait', 3], ['1re connexion', 1.8], ['Réception', 1.5, 'R'], ['Envoi', 1.3, 'R'], ['Total', 1.5, 'R']],
        array_map(fn($r, $i) => [$i + 1, $r['username'], $r['label'], r_d($r['first_login_at']), gb_h((float)$r['d'], 2), gb_h((float)$r['u'], 2), gb_h($r['d'] + $r['u'], 2)], $top, array_keys($top)),
        ['size' => 8]);

    audit($me['user'], 'export consommation PDF', "$from → $to ($grain)");
    $pdf->send("gandal-consommation-$from-au-$to.pdf");
}

/* ======================= Comptabilité ======================= */

function report_compta(string $month, ?array $balance, array $me): never {
    $start = "$month-01";
    $end = date('Y-m-t', strtotime($start));
    $shownEnd = min($end, date('Y-m-d'));
    $mLong = zoom_label('month', $start, true);
    $prevStart = date('Y-m-01', strtotime("$start -1 month"));
    $base = "FROM orders o JOIN plans p ON p.id = o.plan_id WHERE o.status IN " . PAID_STATUSES . " AND o.paid_at >= ?::date AND o.paid_at < ?::date + interval '1 month'";
    $tq = "SELECT count(*) AS n, COALESCE(sum(o.amount),0) AS ca, COALESCE(sum(o.fee),0) AS frais $base";
    $c = one($tq, [$start, $start]); $p = one($tq, [$prevStart, $prevStart]);
    $net = fn($r) => $r['ca'] - $r['frais'];
    $basket = fn($r) => $r['n'] ? $r['ca'] / $r['n'] : 0;
    $pLong = zoom_label('month', $prevStart, true);

    $pdf = new Pdf('Comptabilité de ' . mb_strtolower($mLong), r_period($start, $end), r_meta($me, [
        'Ventes encaissées, datées du paiement' . ($shownEnd < $end ? ' · mois en cours, arrêté au ' . date('d/m/Y à H:i') : '') . '.',
        'Frais IZI Spot : 7 % prélevés sur chaque paiement. Net = montant crédité sur le compte marchand.']));
    $pdf->h2('Synthèse du mois');
    $pdf->kpis([
        ["Chiffre d'affaires", r_f($c['ca']), r_delta((float)$c['ca'], (float)$p['ca']) . ' vs ' . mb_strtolower($pLong)],
        ['Frais IZI Spot', r_f($c['frais']), r_pct((float)$c['frais'], (float)$c['ca']) . " du chiffre d'affaires"],
        ['Net crédité', r_f($net($c)), r_delta($net($c), $net($p)) . ' vs ' . mb_strtolower($pLong)],
        ['Ventes', r_n($c['n']), r_delta((float)$c['n'], (float)$p['n']) . ' vs ' . mb_strtolower($pLong)],
        ['Panier moyen', r_f($basket($c)), r_delta($basket($c), $basket($p)) . ' vs ' . mb_strtolower($pLong)],
        ['Moyenne par jour', r_f($c['ca'] / max(1, (int)date('j', strtotime($shownEnd)))), 'sur ' . (int)date('j', strtotime($shownEnd)) . ' jours'],
    ]);
    $pdf->table([['Indicateur', 3], [$mLong, 2, 'R'], [$pLong, 2, 'R'], ['Évolution', 1.2, 'R']], [
        ["Chiffre d'affaires", r_f($c['ca']), r_f($p['ca']), r_delta((float)$c['ca'], (float)$p['ca'])],
        ['Frais IZI Spot', r_f($c['frais']), r_f($p['frais']), r_delta((float)$c['frais'], (float)$p['frais'])],
        ['Net crédité', r_f($net($c)), r_f($net($p)), r_delta($net($c), $net($p))],
        ['Ventes', r_n($c['n']), r_n($p['n']), r_delta((float)$c['n'], (float)$p['n'])],
        ['Panier moyen', r_f($basket($c)), r_f($basket($p)), r_delta($basket($c), $basket($p))],
    ]);

    if ($balance) {
        $pdf->h2('Solde du compte IZI Spot', 'Lu en direct chez IZI Spot le ' . date('d/m/Y à H:i') . '.');
        $pdf->table([['Poste', 3], ['Montant', 2, 'R']], [
            ['Disponible au retrait', r_f($balance['available'] ?? 0)], ['Retraits en cours', r_f($balance['pending'] ?? 0)],
            ['Total encaissé depuis l\'ouverture (net)', r_f($balance['totalEarned'] ?? 0)], ['Total retiré depuis l\'ouverture', r_f($balance['totalWithdrawn'] ?? 0)],
        ]);
    }

    $line = fn($label, $r) => [$label, r_n($r['n']), r_f($r['ca']), r_f($r['frais']), r_f($r['ca'] - $r['frais']), r_pct((float)$r['ca'], (float)$c['ca'])];
    $cols = fn($first) => [[$first, 3], ['Ventes', 1, 'R'], ["Chiffre d'affaires", 2, 'R'], ['Frais', 1.6, 'R'], ['Net', 2, 'R'], ['Part', 1, 'R']];
    $total = ['Total', r_n($c['n']), r_f($c['ca']), r_f($c['frais']), r_f($net($c)), $c['ca'] ? '100 %' : '-'];

    $pdf->h2('Par forfait');
    $pdf->table($cols('Forfait'), array_map(fn($r) => $line($r['label'], $r),
        all("SELECT p.label, count(*) AS n, sum(o.amount) AS ca, sum(o.fee) AS frais $base GROUP BY p.label, p.price ORDER BY p.price", [$start, $start])), ['total' => $total]);

    $pdf->h2('Par opérateur');
    $pdf->table($cols('Opérateur'), array_map(fn($r) => $line(R_OP[$r['operator']] ?? $r['operator'], $r),
        all("SELECT o.operator, count(*) AS n, sum(o.amount) AS ca, sum(o.fee) AS frais $base GROUP BY 1 ORDER BY 1", [$start, $start])), ['total' => $total]);

    $pdf->h2('Jour par jour', 'Tous les jours du mois' . ($shownEnd < $end ? ' jusqu\'à aujourd\'hui' : '') . ', y compris ceux sans vente.');
    $byDay = [];
    foreach (all("SELECT date_trunc('day', o.paid_at)::date AS jour, count(*) AS n, sum(o.amount) AS ca, sum(o.fee) AS frais,
                         count(*) FILTER (WHERE o.operator = 'mtn') AS mtn, count(*) FILTER (WHERE o.operator = 'orange') AS orange
                  $base GROUP BY 1", [$start, $start]) as $r) { $byDay[$r['jour']] = $r; }
    $zero = ['n' => 0, 'ca' => 0, 'frais' => 0, 'mtn' => 0, 'orange' => 0];
    $pdf->table([['Jour', 2.6], ['Ventes', 0.9, 'R'], ['dont MTN', 0.9, 'R'], ['dont Orange', 1, 'R'], ["Chiffre d'affaires", 1.9, 'R'], ['Frais', 1.5, 'R'], ['Net', 1.9, 'R']],
        $start <= $shownEnd ? array_map(function ($d) use ($byDay, $zero) { $r = $byDay[$d] ?? $zero;
            return [zoom_label('day', $d, true), r_n($r['n']), r_n($r['mtn']), r_n($r['orange']), r_f($r['ca']), r_f($r['frais']), r_f($r['ca'] - $r['frais'])]; }, days_between($start, $shownEnd)) : [],
        ['total' => ['Total du mois', r_n($c['n']), r_n(array_sum(array_column($byDay, 'mtn'))), r_n(array_sum(array_column($byDay, 'orange'))), r_f($c['ca']), r_f($c['frais']), r_f($net($c))], 'size' => 8]);

    $pdf->h2('12 derniers mois', 'Jusqu\'à ' . mb_strtolower($mLong) . ' inclus.');
    $months = [];
    for ($i = 11; $i >= 0; $i--) { $months[date('Y-m', strtotime("$start -$i month"))] = $zero; }
    foreach (all("SELECT to_char(paid_at, 'YYYY-MM') AS m, count(*) AS n, sum(amount) AS ca, sum(fee) AS frais FROM orders
                  WHERE status IN " . PAID_STATUSES . " AND paid_at >= ?::date - interval '11 months' AND paid_at < ?::date + interval '1 month' GROUP BY 1", [$start, $start]) as $r) {
        if (isset($months[$r['m']])) { $months[$r['m']] = $r + $zero; }
    }
    $y = ['n' => 0, 'ca' => 0, 'frais' => 0];
    foreach ($months as $v) { foreach ($y as $k => $_) { $y[$k] += $v[$k]; } }
    $pdf->table([['Mois', 2.6], ['Ventes', 1, 'R'], ["Chiffre d'affaires", 2, 'R'], ['Frais', 1.6, 'R'], ['Net', 2, 'R'], ['Panier moyen', 1.6, 'R']],
        array_map(fn($m, $v) => [zoom_label('month', "$m-01", true), r_n($v['n']), r_f($v['ca']), r_f($v['frais']), r_f($v['ca'] - $v['frais']), r_f($v['n'] ? $v['ca'] / $v['n'] : 0)], array_keys($months), $months),
        ['total' => ['Total 12 mois', r_n($y['n']), r_f($y['ca']), r_f($y['frais']), r_f($y['ca'] - $y['frais']), r_f($y['n'] ? $y['ca'] / $y['n'] : 0)]]);

    audit($me['user'], 'export comptabilité PDF', $month);
    $pdf->send("gandal-comptabilite-$month.pdf");
}
