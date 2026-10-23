<?php
require_once __DIR__ . '/../php-app/inc/auth.php';

echo "--- conference_publications columns ---\n";
try {
    $cols = db()->query('DESCRIBE conference_publications')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $col) {
        echo "{$col['Field']} - {$col['Type']}\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

echo "\n--- Sample conference row ---\n";
$conf = db()->query('SELECT id, faculty_name, department, paper_title, document_link, proofs, proof_file FROM conference_publications LIMIT 3')->fetchAll(PDO::FETCH_ASSOC);
print_r($conf);

echo "\n--- Users ---\n";
$users = db()->query('SELECT id, name, email, role, department FROM users WHERE status = 1 ORDER BY role, id')->fetchAll(PDO::FETCH_ASSOC);
foreach ($users as $u) {
    echo "{$u['id']} | {$u['name']} | {$u['email']} | {$u['role']} | {$u['department']}\n";
}


