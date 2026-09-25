<?php
require_once __DIR__ . '/env.php';

load_env(dirname(__DIR__) . '/.env');
load_env(dirname(dirname(__DIR__)) . '/.env');

define('DB_HOST', env('DB_HOST', 'localhost'));
define('DB_PORT', env('DB_PORT', '3306'));
define('DB_NAME', env('DB_NAME', 'atts_main'));
define('DB_USER', env('DB_USER', 'root'));
define('DB_PASS', (string) env('DB_PASS', ''));
$defaultSslCa = '';
if (strpos((string) env('DB_HOST', ''), 'tidbcloud.com') !== false) {
    $defaultSslCa = dirname(__DIR__) . '/certs/isrgrootx1.pem';
}
define('DB_SSL_CA', (string) env('DB_SSL_CA', $defaultSslCa));

$defaultBaseUrl = (isset($_SERVER['SCRIPT_NAME']) && strpos($_SERVER['SCRIPT_NAME'], '/php-app') === 0) ? '/php-app' : '';
$envBaseUrl     = env('BASE_URL');
$resolvedBase   = ($envBaseUrl !== null && $envBaseUrl !== '') ? $envBaseUrl : $defaultBaseUrl;
define('BASE_URL', rtrim((string) $resolvedBase, '/'));

define('UPLOAD_DIR', dirname(__DIR__) . '/uploads');
define('UPLOAD_URL', BASE_URL . '/uploads');

define('SESSION_NAME', (string) env('SESSION_NAME', 'atts_session'));

error_reporting(E_ALL);
ini_set('display_errors', '1');
define('APP_DEBUG', true);

define('APP_TIMEZONE', (string) env('APP_TIMEZONE', 'Asia/Kolkata'));
date_default_timezone_set(APP_TIMEZONE);

function send_security_headers(): void
{
    if (headers_sent()) {
        return;
    }
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: same-origin');
    header('X-XSS-Protection: 0');
    // Don't leak the PHP version.
    header_remove('X-Powered-By');
}

send_security_headers();