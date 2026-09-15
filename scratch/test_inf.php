<?php
require_once dirname(__DIR__) . '/php-app/inc/db.php';
try {
    $pdo = db();
    echo "SUCCESS: Connected to " . DB_HOST . " database " . DB_NAME;
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage();
}
