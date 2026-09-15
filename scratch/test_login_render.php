<?php
ob_start();
require 'c:/Users/ELCOT/Downloads/ATTC-php-main-20260912T093104Z-1-001/ATTC-php-main/php-app/login.php';
$h = ob_get_clean();

echo "Contains Principal: " . (strpos($h, 'Principal') !== false ? 'YES (PASS)' : 'NO (FAIL)') . "\n";
echo "Contains Director: " . (strpos($h, 'Director') !== false ? 'YES (FAIL)' : 'NO (PASS)') . "\n";
assert(strpos($h, 'Principal') !== false);
assert(strpos($h, 'Director') === false);
echo "login.php rendering test completely passed!\n";
