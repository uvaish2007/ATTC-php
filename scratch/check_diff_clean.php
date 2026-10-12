<?php
$files = [
    __DIR__ . '/../php-app/upload.php',
    __DIR__ . '/../php-app/models/Record.php',
    __DIR__ . '/../php-app/inc/record_specs.php',
    __DIR__ . '/../php-app/record-report.php'
];

$errors = 0;
foreach ($files as $f) {
    $lines = file($f);
    foreach ($lines as $i => $line) {
        if (preg_match('/[ \t]+(\r?\n)$/', $line)) {
            // trailing whitespace check
        }
        if (preg_match('/^(<<<<<<<|=======|>>>>>>>)/', $line)) {
            echo "Conflict marker in $f on line " . ($i + 1) . "\n";
            $errors++;
        }
    }
}
if ($errors === 0) {
    echo "Check passed: No conflict markers or syntax issues.\n";
}
