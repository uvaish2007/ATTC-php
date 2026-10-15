<?php
require_once __DIR__ . '/../php-app/inc/config.php';
require_once __DIR__ . '/../php-app/inc/db.php';
require_once __DIR__ . '/../php-app/inc/upload_sections.php';
require_once __DIR__ . '/../php-app/models/Record.php';
require_once __DIR__ . '/../php-app/models/Target.php';
require_once __DIR__ . '/../php-app/models/TargetPresentation.php';

// Let's test department dataset builder logic
$userCSBS = ['id' => 3, 'role' => 'HoD', 'department' => 'CSBS', 'name' => 'HoD - CSBS'];
$userECE  = ['id' => 60023, 'role' => 'HoD', 'department' => 'ECE', 'name' => 'HoD - ECE'];

echo "Checking department dataset logic...\n";
