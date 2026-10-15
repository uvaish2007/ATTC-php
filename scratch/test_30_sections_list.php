<?php
require_once __DIR__ . '/../php-app/inc/config.php';
require_once __DIR__ . '/../php-app/inc/db.php';
require_once __DIR__ . '/../php-app/inc/upload_sections.php';
require_once __DIR__ . '/../php-app/models/Record.php';
require_once __DIR__ . '/../php-app/models/Target.php';

$sections = upload_approved_sections();
echo "Total approved sections: " . count($sections) . "\n";

// Check record counts per section in 2026-27
$recTypes = record_types();
foreach ($sections as $sId => $sec) {
    echo "{$sec['order']}. [{$sec['id']}] {$sec['title']}\n";
}
