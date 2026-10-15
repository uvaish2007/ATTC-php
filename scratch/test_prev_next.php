<?php
require_once __DIR__ . '/../php-app/inc/upload_sections.php';

$s1 = upload_section_prev('upercent');
$n1 = upload_section_next('upercent');
echo "upercent (Metric 1):\n  prev: " . ($s1['title'] ?? 'NONE') . "\n  next: " . ($n1['title'] ?? 'NONE') . "\n";

$s4 = upload_section_prev('publications');
$n4 = upload_section_next('publications');
echo "publications (Metric 4):\n  prev: " . ($s4['title'] ?? 'NONE') . "\n  next: " . ($n4['title'] ?? 'NONE') . "\n";

$s30 = upload_section_prev('nss_activities');
$n30 = upload_section_next('nss_activities');
echo "nss_activities (Metric 30):\n  prev: " . ($s30['title'] ?? 'NONE') . "\n  next: " . ($n30['title'] ?? 'NONE') . "\n";
