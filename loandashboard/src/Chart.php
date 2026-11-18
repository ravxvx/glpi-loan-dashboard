<?php

namespace GlpiPlugin\Loandashboard;

/**
 * Small server-side SVG charts (no JavaScript library needed, works offline and prints well).
 */
class Chart
{
    public const COLORS = [
        'loaned'    => '#206bc4',
        'available' => '#2fb344',
        'damaged'   => '#d63939',
        'other'     => '#868e96',
    ];

    private const PALETTE = ['#206bc4', '#2fb344', '#f59f00', '#ae3ec9', '#17a2b8', '#d63939', '#74b816', '#f76707', '#4263eb', '#868e96'];

    private static function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }

    public static function color(int $i): string
    {
        return self::PALETTE[$i % count(self::PALETTE)];
    }

    /**
     * Donut chart. $items = [[label, value, color], ...]
     */
    public static function donut(array $items, string $center = '', string $sub = ''): string
    {
        $total = array_sum(array_map(static fn($i) => $i[1], $items));
        $r = 70;
        $c = 2 * M_PI * $r;
        $svg = '<svg viewBox="0 0 200 200" width="190" height="190" role="img" aria-label="' . self::e($sub) . '">'
            . '<circle cx="100" cy="100" r="' . $r . '" fill="none" stroke="var(--tblr-border-color, #e6e7e9)" stroke-width="26"/>';
        $offset = 0.0;
        if ($total > 0) {
            foreach ($items as [$label, $value, $color]) {
                if ($value <= 0) {
                    continue;
                }
                $len = $c * $value / $total;
                $svg .= '<circle cx="100" cy="100" r="' . $r . '" fill="none" stroke="' . self::e($color) . '" stroke-width="26"'
                    . ' stroke-dasharray="' . round($len, 2) . ' ' . round($c - $len, 2) . '"'
                    . ' stroke-dashoffset="' . round(-$offset, 2) . '" transform="rotate(-90 100 100)">'
                    . '<title>' . self::e($label . ': ' . $value) . '</title></circle>';
                $offset += $len;
            }
        }
        $svg .= '<text x="100" y="100" text-anchor="middle" font-size="30" font-weight="700" fill="currentColor">' . self::e($center) . '</text>'
            . '<text x="100" y="124" text-anchor="middle" font-size="12" fill="currentColor" opacity=".65">' . self::e($sub) . '</text>'
            . '</svg>';

        $legend = '<div class="ld-legend">';
        foreach ($items as [$label, $value, $color]) {
            $pct = $total > 0 ? round($value * 100 / $total) : 0;
            $legend .= '<div><span class="ld-dot" style="background:' . self::e($color) . '"></span>'
                . self::e($label) . ' <b>' . (int) $value . '</b> <span class="text-muted">(' . $pct . '%)</span></div>';
        }
        $legend .= '</div>';

        return '<div class="ld-donut">' . $svg . $legend . '</div>';
    }

    /**
     * Horizontal bars. $counts = [label => value]
     */
    public static function hbars(array $counts, int $max_rows = 10): string
    {
        if ($counts === []) {
            return '<div class="text-muted p-3">No data</div>';
        }
        $counts = array_slice($counts, 0, $max_rows, true);
        $max = max($counts) ?: 1;
        $html = '<div class="ld-hbars">';
        $i = 0;
        foreach ($counts as $label => $value) {
            $w = max(2, round($value * 100 / $max));
            $html .= '<div class="ld-hbar"><div class="ld-hbar-label" title="' . self::e((string) $label) . '">' . self::e((string) $label) . '</div>'
                . '<div class="ld-hbar-track"><div class="ld-hbar-fill" style="width:' . $w . '%;background:' . self::color($i++) . '"></div></div>'
                . '<div class="ld-hbar-value">' . (int) $value . '</div></div>';
        }
        return $html . '</div>';
    }

    /**
     * Grouped vertical bars per month. $series = [[name, color, values[]], ...]
     */
    public static function monthly(array $labels, array $series): string
    {
        $n = count($labels);
        if ($n === 0) {
            return '';
        }
        $max = 1;
        foreach ($series as $s) {
            $max = max($max, ...array_map('intval', $s[2] ?: [0]));
        }
        $steps = $max <= 5 ? $max : 5;
        $top = (int) (ceil($max / $steps) * $steps);

        $W = 900; $H = 260; $padL = 34; $padB = 34; $padT = 10;
        $plotW = $W - $padL - 10; $plotH = $H - $padB - $padT;
        $slot = $plotW / $n;
        $bw = min(18, ($slot - 8) / max(1, count($series)));

        $svg = '<svg viewBox="0 0 ' . $W . ' ' . $H . '" width="100%" preserveAspectRatio="xMidYMid meet" role="img" aria-label="Loans per month">';
        for ($g = 0; $g <= $steps; $g++) {
            $val = (int) round($top * $g / $steps);
            $y = $padT + $plotH - $plotH * $g / $steps;
            $svg .= '<line x1="' . $padL . '" x2="' . ($W - 10) . '" y1="' . round($y, 1) . '" y2="' . round($y, 1) . '" stroke="currentColor" stroke-opacity=".12"/>'
                . '<text x="' . ($padL - 6) . '" y="' . round($y + 4, 1) . '" text-anchor="end" font-size="11" fill="currentColor" opacity=".6">' . $val . '</text>';
        }
        foreach ($labels as $i => $label) {
            $x0 = $padL + $slot * $i + ($slot - $bw * count($series)) / 2;
            foreach ($series as $k => [$name, $color, $values]) {
                $v = (int) ($values[$i] ?? 0);
                $h = $top > 0 ? $plotH * $v / $top : 0;
                $x = $x0 + $k * $bw;
                $y = $padT + $plotH - $h;
                $svg .= '<rect x="' . round($x, 1) . '" y="' . round($y, 1) . '" width="' . round($bw - 2, 1) . '" height="' . round($h, 1) . '" rx="2" fill="' . self::e($color) . '">'
                    . '<title>' . self::e($label . ' - ' . $name . ': ' . $v) . '</title></rect>';
                if ($v > 0 && $bw >= 12) {
                    $svg .= '<text x="' . round($x + ($bw - 2) / 2, 1) . '" y="' . round($y - 3, 1) . '" text-anchor="middle" font-size="10" fill="currentColor" opacity=".75">' . $v . '</text>';
                }
            }
            $svg .= '<text x="' . round($padL + $slot * $i + $slot / 2, 1) . '" y="' . ($H - 12) . '" text-anchor="middle" font-size="11" fill="currentColor" opacity=".7">' . self::e($label) . '</text>';
        }
        $svg .= '</svg>';

        $legend = '<div class="ld-legend ld-legend-row">';
        foreach ($series as [$name, $color, $values]) {
            $legend .= '<div><span class="ld-dot" style="background:' . self::e($color) . '"></span>' . self::e($name)
                . ' <b>' . array_sum(array_map('intval', $values)) . '</b></div>';
        }
        return $svg . $legend . '</div>';
    }
}
