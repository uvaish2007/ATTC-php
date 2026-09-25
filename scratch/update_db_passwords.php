<?php
require __DIR__ . '/../php-app/inc/db.php';

$updates = [
    'mohameduvaish132@gmail.com' => 'uvaish123',
    'director@atts.edu'          => 'director123',
    'dean@atts.edu'              => 'dean1234',
    'hod@atts.edu'               => 'hod12345',
    'coordinator@atts.edu'       => 'coord1234',
    'faculty@atts.edu'           => 'faculty12',
];

echo "Updating database passwords in users table:\n";

foreach ($updates as $email => $plainPw) {
    $hash = password_hash($plainPw, PASSWORD_BCRYPT, ['cost' => 10]);
    $stmt = db()->prepare("UPDATE users SET password = ? WHERE email = ?");
    $stmt->execute([$hash, $email]);
    $affected = $stmt->rowCount();
    echo "- Updated {$email}: {$plainPw} (affected: {$affected})\n";
}

echo "Done updating database passwords.\n";
