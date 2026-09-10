<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');

// The server's document root is the project folder, but the app lives in php-app/.
// Route direct file requests (e.g. /login.php -> /php-app/login.php) or fallback to /php-app/index.php.
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$targetFile = basename($path);

if ($targetFile !== '' && $targetFile !== 'index.php' && is_file(__DIR__ . '/php-app/' . $targetFile)) {
    $queryString = !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '';
    header('Location: /php-app/' . $targetFile . $queryString, true, 302);
    exit;
}

header('Location: /php-app/index.php', true, 302);
exit;

