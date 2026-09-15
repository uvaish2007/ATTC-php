<?php
require_once 'c:/Users/ELCOT/Downloads/ATTC-php-main-20260912T093104Z-1-001/ATTC-php-main/php-app/inc/db.php';

try {
    echo "Current status column:\n";
    $col = db()->query("SHOW COLUMNS FROM announcements LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
    print_r($col);

    echo "\nAltering announcements.status to include 'Expired'...\n";
    db()->exec("ALTER TABLE announcements MODIFY COLUMN status ENUM('Draft','Published','Archived','Expired') NOT NULL DEFAULT 'Published'");

    echo "Updated status column:\n";
    $col2 = db()->query("SHOW COLUMNS FROM announcements LIKE 'status'")->fetch(PDO::FETCH_ASSOC);
    print_r($col2);
    echo "SUCCESS!\n";
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
