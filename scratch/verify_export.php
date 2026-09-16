<?php
// Test export.php logic directly
require_once __DIR__ . '/../php-app/inc/db.php';
require_once __DIR__ . '/../php-app/inc/helpers.php';
require_once __DIR__ . '/../php-app/inc/report_layout.php';
require_once __DIR__ . '/../php-app/inc/xlsx_writer.php';

echo "1. Testing ZipArchive availability in PHP:\n";
echo "   ZipArchive exists: " . (class_exists('ZipArchive') ? "YES" : "NO") . "\n";

echo "2. Testing SimpleXlsxWriter generation:\n";
$data = SimpleXlsxWriter::createXlsx(
    ['Col1', 'Col2'],
    [['A', 'B']],
    'Test',
    ['Institution Name', 'Subtitle']
);
echo "   Generated XLSX byte size: " . strlen($data) . "\n";

echo "3. Testing report_letterhead HTML rendering when format=excel:\n";
$_GET['format'] = 'excel';
ob_start();
report_letterhead('TEST EXPORT', [['Dept', 'CSE']], ['Sub line']);
$html = ob_get_clean();

echo "   Does HTML contain '<img' when format=excel? " . (strpos($html, '<img') !== false ? "YES (BUG!)" : "NO (FIXED!)") . "\n";
echo "   Does HTML contain institution text? " . (strpos($html, REPORT_INSTITUTION) !== false ? "YES" : "NO") . "\n";

echo "4. Testing report_letterhead HTML rendering when format=word:\n";
$_GET['format'] = 'word';
ob_start();
report_letterhead('TEST EXPORT', [['Dept', 'CSE']], ['Sub line']);
$htmlWord = ob_get_clean();
echo "   Does HTML contain '<img' when format=word? " . (strpos($htmlWord, '<img') !== false ? "YES (CORRECT)" : "NO") . "\n";

echo "\nALL CHECKS COMPLETED.\n";
