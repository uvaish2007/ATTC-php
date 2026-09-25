<?php
/**
 * Test page rendering and output HTML for:
 * - executive-meeting-report.php
 * - present-executive-meeting.php
 * Across Admin, Principal, Dean, HoD, Coordinator, Faculty
 */
error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/../php-app/inc/auth.php';
require_once __DIR__ . '/../php-app/inc/db.php';

function render_page(array $user, string $file, array $get = []): array {
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION = [];
    } else {
        session_start();
    }
    $_SESSION['user'] = $user;
    $_SESSION['active_academic_year'] = '2026-27';
    $_SESSION['csrf'] = 'dummy_csrf';
    $_GET = $get;
    $_POST = [];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['SCRIPT_NAME'] = '/' . basename($file);
    $_SERVER['REQUEST_URI'] = '/' . basename($file) . ($get ? '?' . http_build_query($get) : '');

    ob_start();
    try {
        require $file;
        $content = ob_get_clean();
        return ['status' => 'ok', 'html' => $content];
    } catch (\Throwable $e) {
        $content = ob_get_clean();
        return ['status' => 'error', 'error' => $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine(), 'html' => $content];
    }
}

$pass = 0; $fail = 0;
function test(string $desc, bool $cond) {
    global $pass, $fail;
    if ($cond) { echo " [PASS] $desc\n"; $pass++; }
    else { echo " [FAIL] $desc\n"; $fail++; }
}

echo "=======================================================\n";
echo "PAGE RENDERING & SECURITY VERIFICATION\n";
echo "=======================================================\n\n";

$admin = db()->query("SELECT * FROM users WHERE role = 'Admin' LIMIT 1")->fetch();
$dean = db()->query("SELECT * FROM users WHERE role = 'Dean' LIMIT 1")->fetch();
$principal = db()->query("SELECT * FROM users WHERE role IN ('Principal', 'Director') LIMIT 1")->fetch();
$cseHod = db()->query("SELECT * FROM users WHERE role = 'HoD' AND department = 'CSE' LIMIT 1")->fetch();
$cseCoord = db()->query("SELECT * FROM users WHERE role = 'Coordinator' AND department = 'CSE' LIMIT 1")->fetch();
$cseFac = db()->query("SELECT * FROM users WHERE role = 'Faculty' AND department = 'CSE' LIMIT 1")->fetch();
$eceHod = db()->query("SELECT * FROM users WHERE role = 'HoD' AND department = 'ECE' LIMIT 1")->fetch();
$eeeFac = db()->query("SELECT * FROM users WHERE role = 'Faculty' AND department = 'EEE' LIMIT 1")->fetch();

$reportFile = __DIR__ . '/../php-app/executive-meeting-report.php';
$presentFile = __DIR__ . '/../php-app/present-executive-meeting.php';

echo "--- 1. executive-meeting-report.php UI Rendering ---\n";

// Admin UI
$resAdmin = render_page($admin, $reportFile);
test("Admin report renders successfully", $resAdmin['status'] === 'ok');
test("Admin sees editable department dropdown", strpos($resAdmin['html'], '<select name="department">') !== false);

// Dean UI
$resDean = render_page($dean, $reportFile);
test("Dean report renders successfully", $resDean['status'] === 'ok');
test("Dean sees editable department dropdown", strpos($resDean['html'], '<select name="department">') !== false);

// CSE HoD UI
$resCseHod = render_page($cseHod, $reportFile);
test("CSE HoD report renders successfully", $resCseHod['status'] === 'ok');
test("CSE HoD does NOT see department dropdown", strpos($resCseHod['html'], '<select name="department">') === false);
test("CSE HoD sees read-only Department indicator", strpos($resCseHod['html'], 'Department automatically determined from your login') !== false);
test("CSE HoD read-only indicator displays Computer Science and Engineering", strpos($resCseHod['html'], 'Computer Science and Engineering') !== false);

// CSE Coordinator UI
$resCseCoord = render_page($cseCoord, $reportFile);
test("CSE Coordinator report renders successfully", $resCseCoord['status'] === 'ok');
test("CSE Coordinator does NOT see department dropdown", strpos($resCseCoord['html'], '<select name="department">') === false);
test("CSE Coordinator sees read-only Department indicator", strpos($resCseCoord['html'], 'Department automatically determined from your login') !== false);

// CSE Faculty UI
$resCseFac = render_page($cseFac, $reportFile);
test("CSE Faculty report renders successfully", $resCseFac['status'] === 'ok');
test("CSE Faculty does NOT see department dropdown", strpos($resCseFac['html'], '<select name="department">') === false);
test("CSE Faculty sees read-only Department indicator", strpos($resCseFac['html'], 'Department automatically determined from your login') !== false);

echo "\n--- 2. present-executive-meeting.php Presentation Deck Rendering ---\n";

// CSE HoD Presentation Deck
$presCseHod = render_page($cseHod, $presentFile);
test("CSE HoD presentation renders successfully", $presCseHod['status'] === 'ok');
test("Presentation contains 10-second timer constant", strpos($presCseHod['html'], 'const AUTO_ADVANCE_MS = 10000;') !== false);
test("Presentation contains Auto Mode & Manual Mode controls", strpos($presCseHod['html'], 'id="btnAuto"') !== false && strpos($presCseHod['html'], 'id="btnManual"') !== false);
test("Presentation contains Arrow key keyboard navigation", strpos($presCseHod['html'], "case 'ArrowRight':") !== false && strpos($presCseHod['html'], "case 'ArrowLeft':") !== false);

// Parse JSON slides from CSE HoD presentation
preg_match('/const slides = (\[.*?\]);/s', $presCseHod['html'], $mSlides);
$cseHodSlides = !empty($mSlides[1]) ? json_decode($mSlides[1], true) : [];
test("CSE HoD slides parsed from JSON", !empty($cseHodSlides));
test("CSE HoD Slide 1 Department is Computer Science and Engineering", ($cseHodSlides[0]['summary']['Department'] ?? '') === 'Computer Science and Engineering');
test("CSE HoD Slide 2 title is Computer Science and Engineering Development", ($cseHodSlides[1]['title'] ?? '') === 'Computer Science and Engineering Development');

// Check that no ECE data is inside CSE HoD slides
$cseJson = json_encode($cseHodSlides);
test("No ECE records exist in CSE HoD presentation deck", strpos($cseJson, 'ECE Signal Processing') === false);

echo "\n--- 3. URL Parameter Tampering Defense (IDOR Protection) ---\n";

// CSE HoD attempts ?department=ECE
$tamperedCseHod = render_page($cseHod, $presentFile, ['department' => 'ECE']);
test("Tampered request renders successfully without error", $tamperedCseHod['status'] === 'ok');

preg_match('/const slides = (\[.*?\]);/s', $tamperedCseHod['html'], $mTampered);
$tamperedSlides = !empty($mTampered[1]) ? json_decode($mTampered[1], true) : [];
test("Tampered request STILL outputs Computer Science and Engineering", ($tamperedSlides[0]['summary']['Department'] ?? '') === 'Computer Science and Engineering');
test("Tampered request STILL outputs CSE Development title on Slide 2", ($tamperedSlides[1]['title'] ?? '') === 'Computer Science and Engineering Development');
test("Tampered request DOES NOT contain ECE data", strpos(json_encode($tamperedSlides), 'ECE Signal Processing') === false);

// CSE Coordinator attempts ?department=EEE
$tamperedCoord = render_page($cseCoord, $presentFile, ['department' => 'EEE']);
preg_match('/const slides = (\[.*?\]);/s', $tamperedCoord['html'], $mTamperedCoord);
$tamperedCoordSlides = !empty($mTamperedCoord[1]) ? json_decode($mTamperedCoord[1], true) : [];
test("CSE Coordinator tampered request STILL outputs Computer Science and Engineering", ($tamperedCoordSlides[0]['summary']['Department'] ?? '') === 'Computer Science and Engineering');

// CSE Faculty attempts ?department=Mechanical
$tamperedFac = render_page($cseFac, $presentFile, ['department' => 'Mechanical']);
preg_match('/const slides = (\[.*?\]);/s', $tamperedFac['html'], $mTamperedFac);
$tamperedFacSlides = !empty($mTamperedFac[1]) ? json_decode($mTamperedFac[1], true) : [];
test("CSE Faculty tampered request STILL outputs Computer Science and Engineering", ($tamperedFacSlides[0]['summary']['Department'] ?? '') === 'Computer Science and Engineering');

echo "\n--- 4. ECE HoD & EEE Faculty Presentation Verification ---\n";

$presEceHod = render_page($eceHod, $presentFile);
preg_match('/const slides = (\[.*?\]);/s', $presEceHod['html'], $mEce);
$eceSlides = !empty($mEce[1]) ? json_decode($mEce[1], true) : [];
test("ECE HoD Slide 1 Department is Electronics and Communication Engineering", ($eceSlides[0]['summary']['Department'] ?? '') === 'Electronics and Communication Engineering');
test("ECE HoD Slide 2 title is Electronics and Communication Engineering Development", ($eceSlides[1]['title'] ?? '') === 'Electronics and Communication Engineering Development');
test("ECE HoD deck contains ECE data", strpos(json_encode($eceSlides), 'ECE Signal Processing') !== false);
test("ECE HoD deck DOES NOT contain CSE data", strpos(json_encode($eceSlides), 'CSE Deep Learning') === false);

$presEeeFac = render_page($eeeFac, $presentFile);
preg_match('/const slides = (\[.*?\]);/s', $presEeeFac['html'], $mEee);
$eeeSlides = !empty($mEee[1]) ? json_decode($mEee[1], true) : [];
test("EEE Faculty Slide 1 Department is Electrical and Electronics Engineering", ($eeeSlides[0]['summary']['Department'] ?? '') === 'Electrical and Electronics Engineering');
test("EEE Faculty deck contains EEE data", strpos(json_encode($eeeSlides), 'EEE Smart Grid') !== false);
test("EEE Faculty deck DOES NOT contain CSE data", strpos(json_encode($eeeSlides), 'CSE Deep Learning') === false);

echo "\n--- 5. Empty Department Presentation Deck Handling ---\n";

$aeroUser = ['id' => 9999, 'role' => 'HoD', 'name' => 'Aero HOD', 'department' => 'Aero'];
$presEmpty = render_page($aeroUser, $presentFile);
preg_match('/const slides = (\[.*?\]);/s', $presEmpty['html'], $mEmpty);
$emptySlides = !empty($mEmpty[1]) ? json_decode($mEmpty[1], true) : [];
test("Empty department produces exactly 1 slide", count($emptySlides) === 1);
test("Empty slide has type 'empty'", ($emptySlides[0]['type'] ?? '') === 'empty');
test("Empty slide informs user without falling back to college data",
    strpos($emptySlides[0]['message'] ?? '', 'No presentation data available for') !== false &&
    strpos($emptySlides[0]['message'] ?? '', 'Aero') !== false);

echo "\n=======================================================\n";
echo "PAGE RENDERING TESTS: Passed: $pass, Failed: $fail\n";
echo "=======================================================\n";
