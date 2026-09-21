<?php
require_once __DIR__ . '/../php-app/inc/db.php';
require_once __DIR__ . '/../php-app/inc/helpers.php';

echo "Helpers loaded.\n";
if (function_exists('department_variants')) {
    $vars = department_variants('CSE');
    print_r($vars);
} else {
    echo "department_variants does not exist yet.\n";
}
