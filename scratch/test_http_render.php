<?php
ob_start();
require_once __DIR__ . '/../php-app/inc/auth.php';
require_once __DIR__ . '/../php-app/inc/db.php';

$user = db()->query("SELECT * FROM users WHERE role = 'Faculty' AND department = 'CSBS' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$_SESSION['user'] = [
    'id' => (int)$user['id'],
    'name' => $user['name'],
    'email' => $user['email'],
    'role' => 'Faculty',
    'department' => 'CSBS',
    'status' => 1,
];
$_SESSION['active_cycle'] = '2025-26';

$_GET['section'] = 'student_certifications';
$_GET['type'] = 'inst_student_certifications';
$_GET['mode'] = 'individual';
include __DIR__ . '/../php-app/upload.php';
$html1 = ob_get_clean();

echo "Page 1 Rendered: " . strlen($html1) . " bytes\n";
echo "Has 'Individual Entry': " . (str_contains($html1, 'Individual Entry') ? "YES" : "NO") . "\n";
echo "Has 'Bulk Upload': " . (str_contains($html1, 'Bulk Upload') ? "YES" : "NO") . "\n";
echo "Has 'Download Student Online Certification Template': " . (str_contains($html1, 'Download Student Online Certification Template') ? "YES" : "NO") . "\n";
echo "Has 'certBulkDropzone': " . (str_contains($html1, 'certBulkDropzone') ? "YES" : "NO") . "\n";
echo "Has 'certPreviewTable': " . (str_contains($html1, 'certPreviewTable') ? "YES" : "NO") . "\n";

echo "\n--- Testing IIT-Bombay Spoken Tutorial page rendering ---\n";
$_GET['section'] = 'spoken_tutorials';
$_GET['type'] = 'inst_spoken_tutorials';
$_GET['mode'] = 'individual';
ob_start();
include __DIR__ . '/../php-app/upload.php';
$html2 = ob_get_clean();

echo "Page 2 Rendered: " . strlen($html2) . " bytes\n";
echo "Has 'Individual Entry': " . (str_contains($html2, 'Individual Entry') ? "YES" : "NO") . "\n";
echo "Has 'Bulk Upload': " . (str_contains($html2, 'Bulk Upload') ? "YES" : "NO") . "\n";
echo "Has 'Download IIT-Bombay Spoken Tutorial Template': " . (str_contains($html2, 'Download IIT-Bombay Spoken Tutorial Template') ? "YES" : "NO") . "\n";
echo "Has 'tutBulkDropzone': " . (str_contains($html2, 'tutBulkDropzone') ? "YES" : "NO") . "\n";
echo "Has 'tutPreviewTable': " . (str_contains($html2, 'tutPreviewTable') ? "YES" : "NO") . "\n";
