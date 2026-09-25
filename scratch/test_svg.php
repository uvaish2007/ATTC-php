<?php
// Test SVG generation logic

function test_render_grouped_bar_chart($top3) {
    $maxVal = 1;
    foreach ($top3 as $item) {
        $maxVal = max($maxVal, (int)$item['target'], (int)$item['achieved'], (int)$item['in_progress']);
    }
    // Round maxVal up to multiple of 4
    $yMax = max(10, (int)(ceil($maxVal / 4) * 4));
    
    $svgW = 520;
    $svgH = 260;
    $plotTop = 35;
    $plotBottom = 220;
    $plotHeight = $plotBottom - $plotTop;
    $plotLeft = 45;
    $plotRight = 500;
    $plotWidth = $plotRight - $plotLeft;

    $ticks = 4;
    $gridLines = '';
    for ($i = 0; $i <= $ticks; $i++) {
        $val = round(($yMax / $ticks) * $i);
        $y = $plotBottom - (($val / $yMax) * $plotHeight);
        $gridLines .= "<line x1='{$plotLeft}' y1='{$y}' x2='{$plotRight}' y2='{$y}' stroke='#E2E8F0' stroke-width='1' />";
        $gridLines .= "<text x='" . ($plotLeft - 8) . "' y='" . ($y + 4) . "' font-size='11' font-weight='600' fill='#64748B' text-anchor='end'>{$val}</text>";
    }

    $groupCount = max(1, count($top3));
    $groupSlot = $plotWidth / $groupCount;
    $barW = 20;
    $barGap = 3;
    $groupBarsW = ($barW * 3) + ($barGap * 2);

    $barsSvg = '';
    foreach ($top3 as $idx => $item) {
        $centerX = $plotLeft + ($idx * $groupSlot) + ($groupSlot / 2);
        $startX = $centerX - ($groupBarsW / 2);

        $bars = [
            ['val' => (int)$item['target'], 'color' => '#168A53'],
            ['val' => (int)$item['achieved'], 'color' => '#1B65C5'],
            ['val' => (int)$item['in_progress'], 'color' => '#F59E0B'],
        ];

        foreach ($bars as $bIdx => $bar) {
            $bx = $startX + ($bIdx * ($barW + $barGap));
            $h = ($bar['val'] / $yMax) * $plotHeight;
            $h = max(2, $h);
            $by = $plotBottom - $h;
            $barsSvg .= "<rect x='{$bx}' y='{$by}' width='{$barW}' height='{$h}' rx='2' ry='2' fill='{$bar['color']}' />";
            if ($bar['val'] > 0) {
                $barsSvg .= "<text x='" . ($bx + $barW / 2) . "' y='" . ($by - 5) . "' font-size='11' font-weight='700' fill='#1E293B' text-anchor='middle'>{$bar['val']}</text>";
            }
        }

        $code = htmlspecialchars($item['code']);
        $barsSvg .= "<text x='{$centerX}' y='245' font-size='13' font-weight='800' fill='#0B2D59' text-anchor='middle'>{$code}</text>";
    }

    return "<svg viewBox='0 0 {$svgW} {$svgH}' width='100%' height='100%' preserveAspectRatio='xMidYMid meet'>{$gridLines}{$barsSvg}</svg>";
}

$sampleTop3 = [
    ['code' => 'CSE', 'target' => 15, 'achieved' => 12, 'in_progress' => 3],
    ['code' => 'EEE', 'target' => 12, 'achieved' => 9, 'in_progress' => 2],
    ['code' => 'MECH', 'target' => 10, 'achieved' => 7, 'in_progress' => 2],
];

$svg = test_render_grouped_bar_chart($sampleTop3);
echo "SVG Bar Chart Length: " . strlen($svg) . "\n";
echo "SVG Preview:\n" . substr($svg, 0, 300) . "...\n";
