<?php

function test_render_donut_chart($slices, $totalCount, $centerSub = 'Total Publications') {
    $svgW = 240;
    $svgH = 240;
    $cx = 120;
    $cy = 120;
    $rOut = 95;
    $rIn = 58;

    if ($totalCount <= 0 || empty($slices)) {
        return "<svg viewBox='0 0 {$svgW} {$svgH}' width='100%' height='100%'>
            <circle cx='{$cx}' cy='{$cy}' r='{$rOut}' fill='#F1F5F9'/>
            <circle cx='{$cx}' cy='{$cy}' r='{$rIn}' fill='#FFFFFF'/>
            <text x='{$cx}' y='{$cy}' font-size='18' font-weight='800' fill='#0B2D59' text-anchor='middle' dominant-baseline='central'>0</text>
            <text x='{$cx}' y='" . ($cy + 18) . "' font-size='10' font-weight='600' fill='#64748B' text-anchor='middle'>{$centerSub}</text>
        </svg>";
    }

    $paths = '';
    $currentAngle = -90; // Start at top (12 o'clock)

    foreach ($slices as $slice) {
        $pct = $slice['percentage'] ?? 0;
        if ($pct <= 0) continue;

        // Angle sweep in degrees
        $sweep = ($pct / 100) * 360;
        // Avoid full 360 circle SVG arc glitch by capping slightly below 360 if 100%
        if ($sweep >= 359.99) $sweep = 359.99;

        $startRad = deg2rad($currentAngle);
        $endRad = deg2rad($currentAngle + $sweep);

        $x1Out = $cx + $rOut * cos($startRad);
        $y1Out = $cy + $rOut * sin($startRad);
        $x2Out = $cx + $rOut * cos($endRad);
        $y2Out = $cy + $rOut * sin($endRad);

        $x1In = $cx + $rIn * cos($endRad);
        $y1In = $cy + $rIn * sin($endRad);
        $x2In = $cx + $rIn * cos($startRad);
        $y2In = $cy + $rIn * sin($startRad);

        $largeArc = ($sweep > 180) ? 1 : 0;

        $d = "M {$x1Out} {$y1Out} " .
             "A {$rOut} {$rOut} 0 {$largeArc} 1 {$x2Out} {$y2Out} " .
             "L {$x1In} {$y1In} " .
             "A {$rIn} {$rIn} 0 {$largeArc} 0 {$x2In} {$y2In} Z";

        $color = $slice['color'] ?? '#1B65C5';
        $paths .= "<path d='{$d}' fill='{$color}' stroke='#FFFFFF' stroke-width='2'/>";

        $midRad = deg2rad($currentAngle + ($sweep / 2));
        $lblR = ($rOut + $rIn) / 2;
        $lblX = $cx + $lblR * cos($midRad);
        $lblY = $cy + $lblR * sin($midRad);
        if ($pct >= 7) {
            $paths .= "<text x='{$lblX}' y='{$lblY}' font-size='10' font-weight='800' fill='#FFFFFF' text-anchor='middle' dominant-baseline='central'>{$pct}%</text>";
        }

        $currentAngle += $sweep;
    }

    $center = "<circle cx='{$cx}' cy='{$cy}' r='{$rIn}' fill='#FFFFFF'/>" .
              "<text x='{$cx}' y='" . ($cy - 5) . "' font-size='22' font-weight='900' fill='#0B2D59' text-anchor='middle'>{$totalCount}</text>" .
              "<text x='{$cx}' y='" . ($cy + 13) . "' font-size='9.5' font-weight='700' fill='#64748B' text-anchor='middle'>{$centerSub}</text>";

    return "<svg viewBox='0 0 {$svgW} {$svgH}' width='100%' height='100%' preserveAspectRatio='xMidYMid meet'>{$paths}{$center}</svg>";
}

$sampleSlices = [
    ['label' => 'CSE', 'count' => 22, 'percentage' => 32, 'color' => '#1B65C5'],
    ['label' => 'EEE', 'count' => 14, 'percentage' => 20, 'color' => '#EA580C'],
    ['label' => 'MECH', 'count' => 10, 'percentage' => 15, 'color' => '#168A53'],
    ['label' => 'Other Depts.', 'count' => 22, 'percentage' => 33, 'color' => '#94A3B8'],
];

$donut = test_render_donut_chart($sampleSlices, 68);
echo "Donut SVG length: " . strlen($donut) . "\n";
