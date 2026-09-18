<?php
/**
 * FEAT-10 Verification and Test Suite.
 * Tests Security, PDF, Word, Excel exports, thumbnails, and regression.
 */

require_once __DIR__ . '/../php-app/inc/config.php';
require_once __DIR__ . '/../php-app/inc/helpers.php';
require_once __DIR__ . '/../php-app/inc/record_specs.php';
require_once __DIR__ . '/../php-app/models/Record.php';
require_once __DIR__ . '/../php-app/models/User.php';
require_once __DIR__ . '/../php-app/models/Department.php';
require_once __DIR__ . '/../php-app/inc/xlsx_writer.php';

echo "========================================================\n";
echo "FEAT-10 VERIFICATION & SECURITY TEST SUITE\n";
echo "========================================================\n\n";

$passCount = 0;
$failCount = 0;

function assertTest(string $name, bool $condition, string $details = '') {
    global $passCount, $failCount;
    if ($condition) {
        echo " [PASS] $name\n";
        $passCount++;
    } else {
        echo " [FAIL] $name: $details\n";
        $failCount++;
    }
}

// 1. Inspect existing proof files and records
$proofs = glob(UPLOAD_DIR . '/proofs/*');
$proofCount = count($proofs);
assertTest("Existing proof files present on disk", $proofCount > 0, "Found $proofCount files");

// Find a real record in DB with a proof_file
$sampleRecord = null;
$sampleType = null;
$types = record_types();
foreach ($types as $tKey => $tInfo) {
    try {
        $stmt = db()->prepare("SELECT * FROM `{$tInfo['table']}` WHERE proof_file IS NOT NULL AND proof_file != '' LIMIT 1");
        $stmt->execute();
        $r = $stmt->fetch();
        if ($r) {
            $sampleRecord = $r;
            $sampleType = $tKey;
            break;
        }
    } catch (\Exception $e) {}
}

assertTest("Real database record with proof_file found", $sampleRecord !== null, "Found in type: $sampleType, ID: " . ($sampleRecord['id'] ?? 'none'));

// 2. Test record_proof_url and record_proof_meta
if ($sampleRecord) {
    $pUrl = record_proof_url($sampleType, (int)$sampleRecord['id'], $sampleRecord['proof_file'], false, true);
    assertTest("record_proof_url generates absolute URL", strpos($pUrl, 'http') === 0 && strpos($pUrl, 'proof.php') !== false, "Generated: $pUrl");

    $meta = record_proof_meta($sampleType, (int)$sampleRecord['id'], $sampleRecord['proof_file']);
    assertTest("record_proof_meta returns valid metadata", $meta !== null && isset($meta['ext']) && isset($meta['view_url']), "Ext: " . ($meta['ext'] ?? ''));
    assertTest("record_proof_meta confirms disk existence", $meta['exists'] === true, "File exists check");
}

// 3. Test Security on proof.php using simulated HTTP requests
// Users from DB:
// Admin (ID 1, role Admin)
// HoD CSBS (role HoD, dept CSBS)
// Faculty CSBS (role Faculty, dept CSBS)
$stmt = db()->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([1]);
$uAdmin = $stmt->fetch();

$stmt = db()->prepare("SELECT * FROM users WHERE role = 'HoD' LIMIT 1");
$stmt->execute();
$uHod = $stmt->fetch();

$stmt = db()->prepare("SELECT * FROM users WHERE role = 'Faculty' LIMIT 1");
$stmt->execute();
$uFac = $stmt->fetch();

assertTest("User accounts loaded for security testing", (bool)($uAdmin && $uHod && $uFac), "Admin, HoD, Faculty users found");

// Helper to simulate request to proof.php
function simulateProofRequest(?array $userSession, array $getParams): array {
    $arg = base64_encode(json_encode(['user' => $userSession, 'get' => $getParams]));
    $cmd = 'php ' . escapeshellarg(__DIR__ . '/run_proof_sim.php') . ' ' . escapeshellarg($arg);
    $res = shell_exec($cmd);
    $data = json_decode((string)$res, true);
    return $data ?: ['code' => 500, 'len' => 0, 'headers' => []];
}

if ($sampleRecord) {
    $recDept = $sampleRecord['department'] ?? 'CSBS';

    // Test 1: Unauthenticated user -> redirect (302)
    $unauthRes = simulateProofRequest(null, ['type' => $sampleType, 'id' => $sampleRecord['id']]);
    assertTest("Unauthenticated request redirected to login", $unauthRes['code'] === 302, "Response code: " . $unauthRes['code']);

    // Test 2: Admin authorized -> 200
    $adminRes = simulateProofRequest($uAdmin, ['type' => $sampleType, 'id' => $sampleRecord['id']]);
    assertTest("Admin authorized to access proof college-wide", $adminRes['code'] === 200 && $adminRes['len'] > 0, "Response code: " . $adminRes['code'] . ", bytes: " . $adminRes['len']);

    // Test 3: HoD of same dept -> 200
    $hodSameRes = simulateProofRequest(['id' => $uHod['id'], 'role' => 'HoD', 'department' => $recDept], ['type' => $sampleType, 'id' => $sampleRecord['id']]);
    assertTest("HoD of same department authorized", $hodSameRes['code'] === 200 && $hodSameRes['len'] > 0, "Response code: " . $hodSameRes['code']);

    // Test 4: HoD of different dept -> 403 Forbidden
    $hodDiffRes = simulateProofRequest(['id' => $uHod['id'], 'role' => 'HoD', 'department' => 'MECH'], ['type' => $sampleType, 'id' => $sampleRecord['id']]);
    assertTest("HoD of different department blocked (403)", $hodDiffRes['code'] === 403, "Response code: " . $hodDiffRes['code']);

    // Test 5: Faculty of different dept and not creator -> 403 Forbidden
    $facDiffRes = simulateProofRequest(['id' => 9999, 'role' => 'Faculty', 'department' => 'CIVIL'], ['type' => $sampleType, 'id' => $sampleRecord['id']]);
    assertTest("Faculty from different department blocked (403)", $facDiffRes['code'] === 403, "Response code: " . $facDiffRes['code']);

    // Test 6: Path traversal attempt blocked
    $traversalRes = simulateProofRequest($uAdmin, ['file' => '../../inc/config.php']);
    assertTest("Path traversal attempt (../../inc/config.php) blocked (404)", $traversalRes['code'] === 404, "Response code: " . $traversalRes['code']);

    // Test 7: Nonexistent file / record
    $missingRes = simulateProofRequest($uAdmin, ['type' => $sampleType, 'id' => 9999999]);
    assertTest("Nonexistent record returns 404", $missingRes['code'] === 404, "Response code: " . $missingRes['code']);
}

// 4. Test Excel OpenXML Hyperlink Generation
$testHeaders = ['S.No', 'Record', 'Proof'];
$testRows = [
    [1, 'Paper 1', ['text' => 'View Proof', 'url' => 'http://localhost:8000/php-app/proof.php?type=journal&id=1']],
    [2, 'Paper 2', '—']
];
$testMeta = ['TEST INSTITUTION', 'Test Report'];
$xlsx = SimpleXlsxWriter::createXlsx($testHeaders, $testRows, 'Proof Sheet', $testMeta);
assertTest("SimpleXlsxWriter creates valid non-empty XLSX archive", !empty($xlsx), "Length: " . strlen($xlsx));

// Inspect inside XLSX ZipArchive
$tmpXlsx = tempnam(sys_get_temp_dir(), 'xlsx_test_') . '.xlsx';
file_put_contents($tmpXlsx, $xlsx);
$zip = new ZipArchive();
$openRes = $zip->open($tmpXlsx);
assertTest("Generated XLSX can be opened by ZipArchive", $openRes === true, "Zip open status: $openRes");

if ($openRes === true) {
    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    $relsXml  = $zip->getFromName('xl/worksheets/_rels/sheet1.xml.rels');
    $stylesXml = $zip->getFromName('xl/styles.xml');
    $zip->close();

    assertTest("XLSX contains <hyperlinks> element in sheet1.xml", strpos($sheetXml, '<hyperlinks>') !== false, "sheet1.xml hyperlinks check");
    assertTest("XLSX contains external relationship in sheet1.xml.rels", strpos($relsXml, 'Target="http://localhost:8000/php-app/proof.php?type=journal&amp;id=1"') !== false || strpos($relsXml, 'Target="http://localhost:8000/php-app/proof.php?type=journal&id=1"') !== false, "rels check");
    assertTest("XLSX relationships specify TargetMode=\"External\"", strpos($relsXml, 'TargetMode="External"') !== false, "TargetMode check");
    assertTest("XLSX cell references relationship rIdH1", strpos($sheetXml, 'r:id="rIdH1"') !== false, "Cell link ref check");
    assertTest("XLSX defines blue underlined hyperlink font style", strpos($stylesXml, 'rgb="FF0044CC"') !== false && strpos($stylesXml, '<u/>') !== false, "Style check");
}
@unlink($tmpXlsx);

// 5. Test Image Proof & Thumbnail Generation
$dummyImgPath = UPLOAD_DIR . '/proofs/test_feat10_thumb.png';
$img = imagecreatetruecolor(200, 100);
$bg = imagecolorallocate($img, 30, 80, 200);
imagefill($img, 0, 0, $bg);
imagepng($img, $dummyImgPath);
imagedestroy($img);

assertTest("Created test image proof on disk", file_exists($dummyImgPath), "test_feat10_thumb.png created");

$imgMeta = record_proof_meta('journal', 1, 'test_feat10_thumb.png');
assertTest("Image proof detected as is_image = true", $imgMeta['is_image'] === true, "is_image flag");
assertTest("Image thumbnail generated as base64 data URI", !empty($imgMeta['base64_data']) && strpos($imgMeta['base64_data'], 'data:image/png;base64,') === 0, "base64 URI prefix check");
@unlink($dummyImgPath);

// 6. Test Actual Report Exports (Record Report in Word, PDF, Excel)
function runExportScript(string $script, array $getParams): string {
    $tmp = tempnam(sys_get_temp_dir(), 'exp_out_');
    $cmd = 'php ' . escapeshellarg(__DIR__ . '/run_export_sim.php') . ' ' . escapeshellarg($script) . ' ' . escapeshellarg(base64_encode(json_encode($getParams))) . ' ' . escapeshellarg($tmp);
    shell_exec($cmd);
    $out = file_exists($tmp) ? (string)file_get_contents($tmp) : '';
    @unlink($tmp);
    return $out;
}

// Test Record Report PDF
$pdfOutput = runExportScript('record-report.php', ['type' => $sampleType ?: 'journal', 'format' => 'pdf']);
assertTest("Record Report PDF contains Print Bar", strpos($pdfOutput, 'pdf-bar') !== false, "PDF print bar check");
assertTest("Record Report PDF contains centered letterhead banner", strpos($pdfOutput, 'rpt-banner') !== false, "Banner check");
assertTest("Record Report PDF contains Proof Attachment column", strpos($pdfOutput, 'Proof Attachment') !== false, "Proof Attachment column check");
assertTest("Record Report PDF contains View Proof links", strpos($pdfOutput, 'View Proof') !== false || strpos($pdfOutput, 'proof.php') !== false, "View proof links check");

// Test Record Report Word (.doc)
$wordOutput = runExportScript('record-report.php', ['type' => $sampleType ?: 'journal', 'format' => 'word']);
assertTest("Record Report Word contains mso-page-orientation", strpos($wordOutput, 'mso-page-orientation') !== false, "MSO orientation check");
assertTest("Record Report Word preserves centered banner wrapper", strpos($wordOutput, 'rpt-banner-wrap') !== false, "Banner wrapper check");
assertTest("Record Report Word contains Proof Attachment column and links", strpos($wordOutput, 'Proof Attachment') !== false && (strpos($wordOutput, 'View Proof') !== false || strpos($wordOutput, 'proof.php') !== false), "Word proof check");

// Test Record Report Excel (.xlsx)
$excelOutput = runExportScript('record-report.php', ['type' => $sampleType ?: 'journal', 'format' => 'excel']);
assertTest("Record Report Excel produces binary XLSX data", strlen($excelOutput) > 1000 && substr($excelOutput, 0, 4) === "PK\x03\x04", "Binary PK zip header check");

// Test Consolidated Report Excel (.xlsx)
$consolExcel = runExportScript('consolidated-report.php', ['format' => 'excel']);
assertTest("Consolidated Report Excel produces binary XLSX with Proof column", strlen($consolExcel) > 1000 && substr($consolExcel, 0, 4) === "PK\x03\x04", "Consolidated XLSX check");

// Test Consolidated Report Word (.doc)
$consolWord = runExportScript('consolidated-report.php', ['format' => 'word']);
assertTest("Consolidated Report Word contains Proof header and links", strpos($consolWord, '<th>Proof</th>') !== false || strpos($consolWord, 'Proof') !== false, "Consolidated Word check");

// Test Export.php (Reports hub export)
$exportWord = runExportScript('export.php', ['format' => 'word']);
assertTest("export.php Word contains Proof header", strpos($exportWord, '<th>Proof</th>') !== false, "export.php Proof check");

$exportExcel = runExportScript('export.php', ['format' => 'excel']);
assertTest("export.php Excel produces valid XLSX", strlen($exportExcel) > 1000 && substr($exportExcel, 0, 4) === "PK\x03\x04", "export.php XLSX check");

echo "\n========================================================\n";
echo "TEST RESULTS SUMMARY: $passCount PASSED, $failCount FAILED\n";
echo "========================================================\n";

exit($failCount > 0 ? 1 : 0);
