<?php
/**
 * PDO database connection (singleton).
 *
 * Every query in the app goes through prepared statements on this handle, so
 * user input can never be concatenated into SQL.
 */

require_once __DIR__ . '/config.php';

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        DB_HOST,
        DB_PORT,
        DB_NAME
    );

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        if (APP_DEBUG) {
            die('Database connection failed: ' . htmlspecialchars($e->getMessage())
                . '<br><br>Check inc/config.php and that MySQL is running and the "atts" database exists.');
        }
        die('Database connection failed.');
    }

    return $pdo;
}
