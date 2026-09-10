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

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    try {
        // For local connections (127.0.0.1 / localhost), connect directly with standard options
        $isLocal = in_array(DB_HOST, ['127.0.0.1', 'localhost', '::1'], true);

        if (!$isLocal) {
            $caFile = is_file(__DIR__ . '/isrgrootx1.pem')
                ? __DIR__ . '/isrgrootx1.pem'
                : __DIR__ . '/../certs/isrgrootx1.pem';

            if (is_file($caFile)) {
                $sslCaAttr = defined('Pdo\Mysql::ATTR_SSL_CA')
                    ? Pdo\Mysql::ATTR_SSL_CA
                    : (defined('PDO::MYSQL_ATTR_SSL_CA') ? PDO::MYSQL_ATTR_SSL_CA : 1008);

                $sslVerifyAttr = defined('Pdo\Mysql::ATTR_SSL_VERIFY_SERVER_CERT')
                    ? Pdo\Mysql::ATTR_SSL_VERIFY_SERVER_CERT
                    : (defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT') ? PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT : 1013);

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
    } catch (PDOException $e) {
        http_response_code(500);
        if (APP_DEBUG || !is_file(dirname(__DIR__) . '/.env')) {
            die('<div style="font-family:sans-serif;max-width:600px;margin:40px auto;padding:24px;border:1px solid #fee2e2;border-radius:12px;background:#fef2f2;color:#991b1b">'
                . '<h2 style="margin-top:0;font-size:18px">Database Connection Notice</h2>'
                . '<p style="font-size:14px;line-height:1.5">Could not connect to the database <strong>' . htmlspecialchars(DB_NAME) . '</strong> on <strong>' . htmlspecialchars(DB_HOST) . '</strong>.</p>'
                . '<p style="font-size:13px;background:#fff;padding:10px;border-radius:6px;border:1px solid #fecaca;word-break:break-all"><strong>Error:</strong> ' . htmlspecialchars($e->getMessage()) . '</p>'
                . '<hr style="border:none;border-top:1px solid #fecaca;margin:16px 0">'
                . '<p style="font-size:13px;color:#7f1d1d;line-height:1.6"><strong>How to resolve on InfinityFree:</strong><br>'
                . '1. Log into your InfinityFree Control Panel &rarr; <strong>MySQL Databases</strong>.<br>'
                . '2. Copy the <strong>MySQL Hostname</strong> (e.g. <code>sqlXXX.infinityfree.com</code>), <strong>MySQL Username</strong>, and <strong>Database Name</strong>.<br>'
                . '3. In InfinityFree File Manager, create/edit <code>htdocs/php-app/.env</code> with those credentials.<br>'
                . '4. Open <strong>phpMyAdmin</strong> in InfinityFree and import <code>php-app/sql/schema.sql</code> and <code>php-app/sql/seed.sql</code>.</p>'
                . '</div>');
        }
        die('Database connection failed.');
    }

    // --- Auto-migrate: ensure all tables & columns exist on connection ------
    static $migrated = false;
    if (!$migrated) {
        $migrated = true;

        // 1. Ensure template tables exist
        $check = $pdo->query("SHOW TABLES LIKE 'online_courses'");
        if ($check->rowCount() === 0) {
            $sqlFile = dirname(__DIR__) . '/sql/template_new_types.sql';
            if (is_file($sqlFile)) {
                $sql = file_get_contents($sqlFile);
                $sql = preg_replace('/^\s*SET\s+.*?;\s*$/mi', '', $sql);
                $sql = preg_replace('/--.*$/m', '', $sql);
                $statements = array_filter(array_map('trim', explode(';', $sql)));
                foreach ($statements as $stmt) {
                    if ($stmt !== '') {
                        try { $pdo->exec($stmt); } catch (Throwable $e) {}
                    }
                }
            }
        }

        // 2. Ensure workflow hierarchy status column (VARCHAR 50) and department / proof_file columns exist
        $metricTables = [
            'journal_publications', 'book_publications', 'conference_publications', 'patents',
            'fdp', 'mou', 'nptel', 'online_courses', 'events', 'nss', 'value_added_courses',
            'training', 'internships', 'placements', 'summer_training', 'student_achievements',
            'student_participations'
        ];

        foreach ($metricTables as $tbl) {
            $tCheck = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($tbl));
            if ($tCheck && $tCheck->rowCount() > 0) {
                // Modify status column to VARCHAR(50) for multi-level review workflow
                try {
                    $pdo->exec("ALTER TABLE `$tbl` MODIFY COLUMN status VARCHAR(50) NOT NULL DEFAULT 'Draft'");
                } catch (Throwable $e) {}

                // Check department column
                $cCheck = $pdo->query("SHOW COLUMNS FROM `$tbl` LIKE 'department'");
                if ($cCheck && $cCheck->rowCount() === 0) {
                    try {
                        $pdo->exec("ALTER TABLE `$tbl` ADD COLUMN department VARCHAR(150) NULL");
                    } catch (Throwable $e) {}
                }
                // Check proof_file column
                $pCheck = $pdo->query("SHOW COLUMNS FROM `$tbl` LIKE 'proof_file'");
                if ($pCheck && $pCheck->rowCount() === 0) {
                    try {
                        $pdo->exec("ALTER TABLE `$tbl` ADD COLUMN proof_file VARCHAR(255) NULL");
                    } catch (Throwable $e) {}
                }
            }
        }

        // 3. Table specific column checks
        $fdpCheck = $pdo->query("SHOW COLUMNS FROM `fdp` LIKE 'duration'");
        if ($fdpCheck && $fdpCheck->rowCount() === 0) {
            try { $pdo->exec("ALTER TABLE `fdp` ADD COLUMN duration VARCHAR(60) NULL"); } catch (Throwable $e) {}
        }

        // Ensure users.role ENUM includes 'Dean'
        try {
            $pdo->exec("ALTER TABLE users MODIFY COLUMN role ENUM('Admin','Director','Dean','HoD','Coordinator','Faculty') NOT NULL DEFAULT 'Faculty'");
        } catch (Throwable $e) {}

        // Ensure Dean user exists in users table
        try {
            $dCheck = $pdo->query("SELECT id FROM users WHERE email = 'dean@atts.edu'");
            if ($dCheck && $dCheck->rowCount() === 0) {
                $hash = password_hash('dean1234', PASSWORD_BCRYPT);
                $stmt = $pdo->prepare("INSERT INTO users (name, email, password, role, department) VALUES ('Dean', 'dean@atts.edu', ?, 'Dean', NULL)");
                $stmt->execute([$hash]);
            }
        } catch (Throwable $e) {}
    }

    return $pdo;
}
