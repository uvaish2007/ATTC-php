<?php
/**
 * Application configuration.
 *
 * Credentials and settings come from php-app/.env — edit THAT file to change
 * the database, not this one. The defaults here are only used if a key is
 * missing from .env, so the app still boots.
 */

require_once __DIR__ . '/env.php';

load_env(dirname(__DIR__) . '/.env');

// --- Database (MySQL / MariaDB) ---
define('DB_HOST', env('DB_HOST', '127.0.0.1'));
define('DB_PORT', env('DB_PORT', '3306'));
define('DB_NAME', env('DB_NAME', 'atts_main'));
define('DB_USER', env('DB_USER', 'root'));
define('DB_PASS', (string) env('DB_PASS', ''));

// --- Application ---
// The URL path the app is served from. "/php-app" for XAMPP htdocs; "" if you
// serve the folder itself at the root (e.g. `php -S localhost:8000`).
define('BASE_URL', rtrim((string) env('BASE_URL', '/php-app'), '/'));

define('UPLOAD_DIR', dirname(__DIR__) . '/uploads');
define('UPLOAD_URL', BASE_URL . '/uploads');

define('SESSION_NAME', (string) env('SESSION_NAME', 'atts_session'));

// Debug shows detailed errors. Keep FALSE in production (set in .env).
define('APP_DEBUG', env('APP_DEBUG', false) === true);

if (APP_DEBUG) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(0);
    ini_set('display_errors', '0');
}

date_default_timezone_set((string) env('APP_TIMEZONE', 'Asia/Kolkata'));

/**
 * Baseline security response headers. Sent once, before any output.
 */
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
