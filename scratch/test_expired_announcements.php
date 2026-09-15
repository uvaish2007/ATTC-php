<?php
require_once 'c:/Users/ELCOT/Downloads/ATTC-php-main-20260912T093104Z-1-001/ATTC-php-main/php-app/inc/db.php';
require_once 'c:/Users/ELCOT/Downloads/ATTC-php-main-20260912T093104Z-1-001/ATTC-php-main/php-app/inc/auth.php';
require_once 'c:/Users/ELCOT/Downloads/ATTC-php-main-20260912T093104Z-1-001/ATTC-php-main/php-app/models/Announcement.php';

echo "=== 1. TEST AUTO-SYNC & DATABASE TRANSITION FOR EXPIRED ANNOUNCEMENTS ===\n";
// Run sync
$updatedCount = announcement_sync_expired();
echo "Updated expired rows in database: {$updatedCount}\n";

$rows = db()->query("SELECT id, title, status, expires_at FROM announcements WHERE expires_at < NOW()")->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) {
    echo "ID {$r['id']}: Status='{$r['status']}' | Expires='{$r['expires_at']}'\n";
    assert($r['status'] === 'Expired', "Status in database must be Expired for past date notice");
}
echo "PASS: Expired notices are stored with status='Expired' in the database.\n";

echo "\n=== 2. TEST ANNOUNCEMENT STATS WITH EXPIRED COUNT ===\n";
$adminUser = ['id' => 1, 'name' => 'Admin', 'role' => 'Admin', 'department' => null];
$stats = announcement_stats($adminUser);
print_r($stats);
assert(isset($stats['expired']), "Stats must include 'expired'");
assert($stats['expired'] >= 2, "Expired count should be >= 2");
echo "PASS: Stats accurately count past expired notices safely stored in DB.\n";

echo "\n=== 3. TEST ANNOUNCEMENT LIST FILTERING FOR EXPIRED SCOPE ===\n";
$expiredList = announcements_list($adminUser, ['scope' => 'expired']);
echo "Total expired notices returned for Admin: {$expiredList['total']}\n";
assert($expiredList['total'] >= 2, "Expired list must have at least 2 notices");
foreach ($expiredList['rows'] as $row) {
    assert($row['_state'] === 'Expired', "State must be Expired");
}
echo "PASS: Admin can retrieve all past expired notices using scope=expired.\n";

echo "\n=== 4. TEST THAT REGULAR FACULTY DOES NOT SEE EXPIRED NOTICES IN ACTIVE SCOPE ===\n";
$facultyUser = ['id' => 6, 'name' => 'Faculty', 'role' => 'Faculty', 'department' => 'Computer Science'];
$activeList = announcements_list($facultyUser, ['scope' => 'all']);
echo "Total active notices for Faculty: {$activeList['total']}\n";
foreach ($activeList['rows'] as $row) {
    assert($row['_state'] !== 'Expired', "Faculty must not see expired notice in active list");
}
echo "PASS: Faculty active list properly excludes expired notices.\n";

echo "\n=== 5. TEST HTML RENDERING OF EXPIRED ANNOUNCEMENTS IN ADMIN VIEW ===\n";
auth_boot();
$_SESSION['user'] = $adminUser;
$_GET['scope'] = 'expired';

ob_start();
require 'c:/Users/ELCOT/Downloads/ATTC-php-main-20260912T093104Z-1-001/ATTC-php-main/php-app/announcements.php';
$html = ob_get_clean();

echo "HTML length: " . strlen($html) . "\n";
assert(strpos($html, 'Past Expired Announcements Database Archive') !== false, "Must render archive banner");
assert(strpos($html, 'Expired &middot; Stored in DB') !== false, "Must render Expired Stored in DB badge");
assert(strpos($html, 'Completed') !== false, "Must render Completed date");
echo "PASS: HTML output displays archive banner and past expired status correctly!\n";

echo "\nALL EXPIRED ANNOUNCEMENT TESTS PASSED SUCCESSFULLY!\n";
