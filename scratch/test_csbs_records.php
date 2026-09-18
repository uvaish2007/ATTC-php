<?php
require_once __DIR__ . '/../php-app/inc/auth.php';
require_once __DIR__ . '/../php-app/models/Record.php';

$types = record_types();
$activeYear = active_academic_year();

$total = 0;
foreach ($types as $key => $t) {
    $recs = records_list($key, 'CSBS', null, null, null, null, $activeYear);
    echo "$key: " . count($recs) . " records\n";
    $total += count($recs);
}
echo "TOTAL records found for CSBS in $activeYear: $total\n";
