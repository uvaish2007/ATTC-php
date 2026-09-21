<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$_SESSION['user'] = ['id' => 1, 'role' => 'Admin', 'name' => 'Dr. Administrator', 'department' => 'Administration'];
$_SESSION['user_id'] = 1;
$_SESSION['role'] = 'Admin';
$_GET = ['academic_year' => '2026-27'];

ob_start();
include __DIR__ . '/../php-app/present-executive-meeting.php';
$html = ob_get_clean();

file_put_contents(__DIR__ . '/executive_meeting_demo.html', $html);
echo "Wrote " . strlen($html) . " bytes to executive_meeting_demo.html\n";
