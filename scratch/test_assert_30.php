<?php
require_once __DIR__ . '/../php-app/inc/config.php';
require_once __DIR__ . '/../php-app/inc/db.php';
require_once __DIR__ . '/../php-app/inc/upload_sections.php';
require_once __DIR__ . '/../php-app/models/Record.php';
require_once __DIR__ . '/../php-app/models/Target.php';

$secApproved = upload_approved_sections();
echo "Sections count: " . count($secApproved) . "\n";
assert(count($secApproved) === 30);
echo "Assertion passed: exactly 30 sections.\n";
