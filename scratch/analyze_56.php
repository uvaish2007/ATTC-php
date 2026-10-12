<?php
require_once __DIR__ . '/../php-app/inc/record_specs.php';
require_once __DIR__ . '/../php-app/models/Record.php';
require_once __DIR__ . '/../php-app/models/Target.php';

$specs = record_report_specs();
$types = record_types();
$cats = record_categories();

echo "Total specs: " . count($specs) . "\n";
$i = 1;
foreach ($specs as $k => $s) {
    $tbl = $types[$k]['table'] ?? 'unknown';
    $cat = 'other';
    foreach ($cats as $ck => $cv) {
        if (in_array($k, $cv['types'], true)) {
            $cat = $ck;
            break;
        }
    }
    // Also check target mapping
    $sugType = target_suggested_type($s['title']);
    echo sprintf("%2d. %-28s | cat:%-8s | tbl:%-25s | sug:%-10s | %s\n",
        $i++, $k, $cat, $tbl, ($sugType ?? '-'), substr($s['title'], 0, 45));
}
