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
<<<<<<< HEAD
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
=======
        // For local connections (127.0.0.1 / localhost), connect directly with standard options
        $isLocal = in_array(DB_HOST, ['127.0.0.1', 'localhost', '::1'], true);

        if (!$isLocal) {
            $caFile = is_file(__DIR__ . '/isrgrootx1.pem')
                ? __DIR__ . '/isrgrootx1.pem'
                : __DIR__ . '/../certs/isrgrootx1.pem';

            if (is_file($caFile)) {
                $sslOptions = $options + [
                    $sslCaAttr     => $caFile,
                    $sslVerifyAttr => true,
                ];
                try {
                    $pdo = new PDO($dsn, DB_USER, DB_PASS, $sslOptions);
                } catch (PDOException $e) {
                    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
                }
            } else {
                $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
            }
        } else {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        }
>>>>>>> 0c6079ce558eb483b73493026363c194d8b6f634
    } catch (PDOException $e) {
        http_response_code(500);
        if (APP_DEBUG) {
            die('Database connection failed: ' . htmlspecialchars($e->getMessage())
                . '<br><br>Check inc/config.php and that MySQL is running and the "' . htmlspecialchars(DB_NAME) . '" database exists.');
        }
        die('Database connection failed.');
    }

    return $pdo;
}
