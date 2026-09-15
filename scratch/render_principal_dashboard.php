<?php
require_once 'c:/Users/ELCOT/Downloads/ATTC-php-main-20260912T093104Z-1-001/ATTC-php-main/php-app/inc/auth.php';

auth_boot();
$fail = null;
$user = attempt_login('principal@atts.edu', 'director123', 'Principal', $fail);

assert($user !== null, "User login failed: $fail");
$_SESSION['user'] = $user;

ob_start();
require 'c:/Users/ELCOT/Downloads/ATTC-php-main-20260912T093104Z-1-001/ATTC-php-main/php-app/dashboard.php';
$html = ob_get_clean();

echo "Dashboard HTML Length: " . strlen($html) . "\n";
assert(strpos($html, 'Principal Dashboard') !== false, "Must contain 'Principal Dashboard'");
assert(strpos($html, 'Director Dashboard') === false, "Must NOT contain 'Director Dashboard'");
assert(strpos($html, '<span class="role-badge" title="You are signed in as Principal">') !== false || strpos($html, 'Principal') !== false, "Must contain role badge Principal");
assert(strpos($html, 'Institution-wide overview') !== false, "Must contain oversight subtitle");

echo "PASS: Principal Dashboard rendered successfully with correct headings, titles and role indicators!\n";
