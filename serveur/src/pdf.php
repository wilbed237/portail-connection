<?php
/*
 * Générateur PDF minimal, sans dépendance : rapports de l'espace admin (ventes, consommation, comptabilité).
 * Polices standard Helvetica (encodage WinAnsi : accents français, €, •), logo JPEG, tableaux paginés
 * avec en-tête répété, numéros de page « x / y ». Pages A4 en portrait ou en paysage.
 *
 *   $pdf = new Pdf('Rapport des ventes', 'du 01/11/2026 au 24/11/2026', ['Filtres : …', 'Généré le … par …']);
 *   $pdf->h2('Synthèse'); $pdf->kpis([['Recettes', '357 150 FCFA', '+36 % vs période précédente'], …]);
 *   $pdf->table([['Forfait', 3], ['Ventes', 1, 'R']], $rows, ['total' => [...]]);
 *   $pdf->send('ventes.pdf');
 */

declare(strict_types=1);

final class Pdf {
    private const M = 36;                       // marge (pt)
    private const NAVY = [0.086, 0.141, 0.227];
    private const INK2 = [0.32, 0.32, 0.31];
    private const LINE = [0.86, 0.86, 0.84];
    private const ZEBRA = [0.965, 0.965, 0.955];
    private const ACCENT = [0.165, 0.47, 0.84];

    private array $pages = [];                  // [['w', 'h', 'content']]
    private int $p = -1;
    private float $w = 0, $h = 0, $y = 0;
    private bool $landscape = false;
    private ?Closure $repeatHeader = null;        // en-tête de tableau à redessiner après un saut de page
    private static ?array $widths = null;

    public function __construct(private string $title, private string $period, private array $meta = []) {}

    /* ---------- Mise en page ---------- */

    public function addPage(?bool $landscape = null): void {
        if ($landscape !== null) { $this->landscape = $landscape; }
        [$this->w, $this->h] = $this->landscape ? [841.89, 595.28] : [595.28, 841.89];
        $this->pages[] = ['w' => $this->w, 'h' => $this->h, 'c' => ''];
        $this->p = count($this->pages) - 1;
        $first = $this->p === 0;
        // Bandeau : logo, nom, titre du rapport et période
        $this->rect(0, 0, $this->w, $first ? 92 : 46, self::NAVY);
        $lh = $first ? 56 : 30;
        $this->rect(self::M - 2, ($first ? 18 : 8) - 2, $lh * 300 / 282 + 4, $lh + 4, [1, 1, 1]);
        $this->out(sprintf('q %.2F 0 0 %.2F %.2F %.2F cm /Im1 Do Q', $lh * 300 / 282, $lh, self::M, $this->h - ($first ? 18 : 8) - $lh));
        $x = self::M + $lh * 300 / 282 + 14;
        if ($first) {
            $this->text($x, 38, $this->title, 17, true, [1, 1, 1]);
            $this->text($x, 58, 'Période : ' . $this->period, 10.5, false, [0.86, 0.9, 0.96]);
            $this->text($x, 74, 'GANDAL · Département du GI · espace admin', 8.5, false, [0.7, 0.75, 0.83]);
            $this->y = 108;
            foreach ($this->meta as $m) { $this->text(self::M, $this->y, $m, 8.5, false, self::INK2); $this->y += 12; }
            $this->y += 6;
        } else {
            $this->text($x, 22, $this->title, 11, true, [1, 1, 1]);
            $this->text($x, 36, $this->period, 8.5, false, [0.86, 0.9, 0.96]);
            $this->y = 64;
        }
    }

    /* Saut de page si la place restante est insuffisante ; redessine l'en-tête du tableau en cours */
    private function need(float $h): void {
        if ($this->p < 0) { $this->addPage(); }
        if ($this->y + $h > $this->h - 40) {
            $this->addPage();
            if ($this->repeatHeader) { ($this->repeatHeader)(); }
        }
    }

    public function h2(string $s, ?string $note = null): void {
        $this->need(60);
        $this->y += 8;
        $this->text(self::M, $this->y + 10, $s, 12.5, true, self::NAVY);
        $this->rect(self::M, $this->y + 15, 28, 2, self::ACCENT);
        $this->y += 24;
        if ($note) { $this->para($note, 8.5, self::INK2); }
    }

    public function para(string $s, float $size = 9, array $color = [0.15, 0.15, 0.15]): void {
        foreach ($this->wrap($s, $this->w - 2 * self::M, $size, false) as $line) {
            $this->need($size + 4);
            $this->text(self::M, $this->y + $size, $line, $size, false, $color);
            $this->y += $size + 3.5;
        }
        $this->y += 4;
    }

    /* Indicateurs en cases : [[libellé, valeur, précision], …] */
    public function kpis(array $items, int $perRow = 3): void {
        $gap = 8; $bw = ($this->w - 2 * self::M - ($perRow - 1) * $gap) / $perRow; $bh = 50;
        foreach (array_chunk($items, $perRow) as $row) {
            $this->need($bh + $gap);
            foreach ($row as $i => [$label, $value, $sub]) {
                $x = self::M + $i * ($bw + $gap);
                $this->rect($x, $this->y, $bw, $bh, [0.97, 0.975, 0.985]);
                $this->rect($x, $this->y, 2.5, $bh, self::ACCENT);
                $this->text($x + 10, $this->y + 13, $label, 8, false, self::INK2);
                $this->text($x + 10, $this->y + 30, $this->fit($value, $bw - 16, 14, true), 14, true, [0.05, 0.05, 0.05]);
                if ($sub !== null && $sub !== '') { $this->text($x + 10, $this->y + 43, $this->fit($sub, $bw - 16, 7.5, false), 7.5, false, self::INK2); }
            }
            $this->y += $bh + $gap;
        }
        $this->y += 4;
    }

    /*
     * Tableau : $cols = [[titre, poids de largeur, 'L'|'R'], …] ; $rows = [[cellules], …]
     * $o : total (ligne de total en gras), size (taille du texte), empty (message si aucune ligne)
     */
    public function table(array $cols, array $rows, array $o = []): void {
        $size = $o['size'] ?? 8.5; $rh = $size + 7.5;
        $tw = $this->w - 2 * self::M;
        $sum = array_sum(array_column($cols, 1));
        $ws = array_map(fn($c) => $tw * $c[1] / $sum, $cols);
        $header = function () use ($cols, $ws, $size, $rh) {
            $this->rect(self::M, $this->y, array_sum($ws), $rh + 2, self::NAVY);
            $x = self::M;
            foreach ($cols as $i => $c) { $this->cell($x, $this->y + 1, $ws[$i], $rh, $c[0], $size - 0.5, true, [1, 1, 1], $c[2] ?? 'L'); $x += $ws[$i]; }
            $this->y += $rh + 2;
        };
        $this->need($rh * 3);
        $header();
        $this->repeatHeader = $header;
        if (!$rows) {
            $this->need($rh);
            $this->cell(self::M, $this->y, $tw, $rh, $o['empty'] ?? 'Aucune donnée sur cette période.', $size, false, self::INK2, 'L');
            $this->y += $rh;
        }
        foreach (array_values($rows) as $k => $r) {
            $this->need($rh);
            if ($k % 2) { $this->rect(self::M, $this->y, $tw, $rh, self::ZEBRA); }
            $x = self::M;
            foreach ($cols as $i => $c) { $this->cell($x, $this->y, $ws[$i], $rh, (string)($r[$i] ?? ''), $size, false, [0.1, 0.1, 0.1], $c[2] ?? 'L'); $x += $ws[$i]; }
            $this->y += $rh;
        }
        if (!empty($o['total'])) {
            $this->need($rh + 2);
            $this->rect(self::M, $this->y, $tw, 1, self::NAVY);
            $this->rect(self::M, $this->y + 1, $tw, $rh, [0.92, 0.94, 0.97]);
            $x = self::M;
            foreach ($cols as $i => $c) { $this->cell($x, $this->y + 1, $ws[$i], $rh, (string)($o['total'][$i] ?? ''), $size, true, [0.05, 0.05, 0.05], $c[2] ?? 'L'); $x += $ws[$i]; }
            $this->y += $rh + 1;
        } else {
            $this->rect(self::M, $this->y, $tw, 0.6, self::LINE);
        }
        $this->repeatHeader = null;
        $this->y += 12;
    }

    /* ---------- Sortie ---------- */

    public function send(string $filename): never {
        $data = $this->render();
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($data));
        header('Cache-Control: no-store');
        echo $data;
        exit;
    }

    public function render(): string {
        if ($this->p < 0) { $this->addPage(); }
        $n = count($this->pages);
        // Pied de page sur chaque page
        foreach ($this->pages as $i => $pg) {
            $this->p = $i; $this->w = $pg['w']; $this->h = $pg['h'];
            $this->rect(self::M, $pg['h'] - 28, $pg['w'] - 2 * self::M, 0.5, self::LINE);
            $this->text(self::M, $pg['h'] - 17, 'GANDAL · ' . $this->title . ' · ' . $this->period, 7.5, false, self::INK2);
            $this->text($pg['w'] - self::M, $pg['h'] - 17, 'Page ' . ($i + 1) . ' / ' . $n, 7.5, false, self::INK2, 'R');
        }
        $objs = [];
        $objs[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objs[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objs[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        $jpg = (string)file_get_contents(__DIR__ . '/logo-pdf.jpg');
        $objs[5] = "<< /Type /XObject /Subtype /Image /Width 300 /Height 282 /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length " . strlen($jpg) . " >>\nstream\n" . $jpg . "\nendstream";
        $kids = [];
        $id = 6;
        foreach ($this->pages as $pg) {
            $c = gzcompress($pg['c']);
            $objs[$id] = "<< /Length " . strlen($c) . " /Filter /FlateDecode >>\nstream\n" . $c . "\nendstream";
            $objs[$id + 1] = sprintf('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> /XObject << /Im1 5 0 R >> >> /Contents %d 0 R >>', $pg['w'], $pg['h'], $id);
            $kids[] = ($id + 1) . ' 0 R';
            $id += 2;
        }
        $objs[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . $n . ' >>';
        $objs[$id] = '<< /Title ' . $this->str('GANDAL - ' . $this->title . ' - ' . $this->period) . ' /Producer (GANDAL) /CreationDate (D:' . date('YmdHis') . ') >>';
        ksort($objs);
        $out = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objs as $k => $v) { $offsets[$k] = strlen($out); $out .= "$k 0 obj\n$v\nendobj\n"; }
        $xref = strlen($out);
        $out .= "xref\n0 " . ($id + 1) . "\n0000000000 65535 f \n";
        for ($k = 1; $k <= $id; $k++) { $out .= sprintf("%010d 00000 n \n", $offsets[$k]); }
        $out .= "trailer\n<< /Size " . ($id + 1) . " /Root 1 0 R /Info $id 0 R >>\nstartxref\n$xref\n%%EOF\n";
        return $out;
    }

    /* ---------- Primitives (y compté depuis le haut de la page) ---------- */

    private function out(string $s): void { $this->pages[$this->p]['c'] .= $s . "\n"; }

    private function rect(float $x, float $y, float $w, float $h, array $rgb): void {
        $this->out(sprintf('%.3F %.3F %.3F rg %.2F %.2F %.2F %.2F re f', $rgb[0], $rgb[1], $rgb[2], $x, $this->h - $y - $h, $w, $h));
    }

    private function text(float $x, float $y, string $s, float $size, bool $bold, array $rgb, string $align = 'L'): void {
        $t = self::enc($s);
        if ($align === 'R') { $x -= self::width($t, $size, $bold); }
        $this->out(sprintf('BT /%s %.2F Tf %.3F %.3F %.3F rg %.2F %.2F Td %s Tj ET', $bold ? 'F2' : 'F1', $size, $rgb[0], $rgb[1], $rgb[2], $x, $this->h - $y, $this->strRaw($t)));
    }

    /* Cellule de tableau : texte raccourci (…) s'il dépasse la largeur */
    private function cell(float $x, float $y, float $w, float $h, string $s, float $size, bool $bold, array $rgb, string $align): void {
        $s = $this->fit($s, $w - 8, $size, $bold);
        $ty = $y + $h / 2 + $size * 0.35;
        if ($align === 'R') { $this->text($x + $w - 4, $ty, $s, $size, $bold, $rgb, 'R'); }
        else { $this->text($x + 4, $ty, $s, $size, $bold, $rgb); }
    }

    private function fit(string $s, float $max, float $size, bool $bold): string {
        if (self::width(self::enc($s), $size, $bold) <= $max) { return $s; }
        while (mb_strlen($s) > 1 && self::width(self::enc($s . '…'), $size, $bold) > $max) { $s = mb_substr($s, 0, -1); }
        return rtrim($s) . '…';
    }

    private function wrap(string $s, float $max, float $size, bool $bold): array {
        $lines = []; $cur = '';
        foreach (preg_split('/\s+/u', trim($s)) as $word) {
            $try = $cur === '' ? $word : "$cur $word";
            if ($cur !== '' && self::width(self::enc($try), $size, $bold) > $max) { $lines[] = $cur; $cur = $word; } else { $cur = $try; }
        }
        if ($cur !== '') { $lines[] = $cur; }
        return $lines;
    }

    private function str(string $s): string { return $this->strRaw(self::enc($s)); }
    private function strRaw(string $t): string { return '(' . strtr($t, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)', "\r" => '', "\n" => ' ']) . ')'; }

    /* UTF-8 → WinAnsi (CP1252) ; les espaces fines et insécables deviennent des espaces */
    private static function enc(string $s): string {
        $s = str_replace(["\u{202F}", "\u{00A0}", '→'], [' ', ' ', '->'], $s);
        $t = @iconv('UTF-8', 'CP1252//TRANSLIT', $s);
        return $t === false ? (string)preg_replace('/[^\x20-\x7E]/', '?', $s) : $t;
    }

    /* Largeur d'un texte CP1252 (métriques Helvetica, en millièmes de corps) */
    private static function width(string $t, float $size, bool $bold): float {
        self::$widths ??= self::metrics();
        $w = 0;
        $tab = self::$widths[$bold ? 1 : 0];
        for ($i = 0, $n = strlen($t); $i < $n; $i++) { $w += $tab[ord($t[$i])] ?? 556; }
        return $w * $size / 1000;
    }

    private static function metrics(): array {
        $reg = [278,278,355,556,556,889,667,191,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,278,278,584,584,584,556,
                1015,667,667,722,722,667,611,778,722,278,500,667,556,833,722,778,667,778,722,667,611,722,667,944,667,667,611,278,278,278,469,556,
                333,556,556,500,556,556,278,556,556,222,222,500,222,833,556,556,556,556,333,500,278,556,500,722,500,500,500,334,260,334,584];
        $bold = [278,333,474,556,556,889,722,238,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,333,333,584,584,584,611,
                 975,722,722,722,722,667,611,778,722,278,556,722,611,833,722,778,667,778,722,667,611,722,667,944,667,667,611,333,278,333,584,556,
                 333,556,611,556,611,556,333,611,611,278,278,556,278,889,611,611,611,611,389,556,333,611,556,778,556,556,500,389,280,389,584];
        $out = [[], []];
        foreach ([$reg, $bold] as $k => $tab) {
            foreach ($tab as $i => $v) { $out[$k][32 + $i] = $v; }
            // Caractères accentués et signes : largeur de la lettre ou du signe ASCII équivalent
            for ($c = 128; $c < 256; $c++) {
                $a = @iconv('CP1252', 'ASCII//TRANSLIT', chr($c));
                $out[$k][$c] = ($a !== false && strlen($a) === 1 && isset($out[$k][ord($a)])) ? $out[$k][ord($a)] : 556;
            }
            $out[$k][0x85] = $k ? 1000 : 1000;   // …
            $out[$k][0x95] = 350;                // •
            $out[$k][0x96] = 556;                // –
            $out[$k][0x80] = 556;                // €
            $out[$k][0xA0] = 278;                // espace insécable
        }
        return $out;
    }
}
