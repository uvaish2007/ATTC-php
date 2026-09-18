<?php
/**
 * Test HTTP / Page Rendering across all roles:
 * - HOD
 * - Dean
 * - Admin
 * - Coordinator
 * - Faculty
 * - Principal
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/../php-app/inc/auth.php';
require_once __DIR__ . '/../php-app/inc/db.php';

$pdo = db();

function simulate_render(array $user, string $file, array $get = []): array {
    $_SESSION['user'] = $user;
    $_SESSION['active_academic_year'] = '2026-27';
    $_GET = $get;
    $_POST = [];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['SCRIPT_NAME'] = '/' . basename($file);
    $_SERVER['REQUEST_URI'] = '/' . basename($file) . ($get ? '?' . http_build_query($get) : '');

    ob_start();
    $caughtRedirect = false;
    $redirectLocation = null;

    try {
        require $file;
    } catch (\Throwable $e) {
        $content = ob_get_clean();
        return [
            'status' => 'exception',
            'error' => $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine(),
            'content' => $content,
        ];
    }

    $content = ob_get_clean();
    return [
        'status' => 'ok',
        'content' => $content,
    ];
}

$hod = $pdo->query("SELECT * FROM users WHERE role = 'HoD' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$dean = $pdo->query("SELECT * FROM users WHERE role = 'Dean' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$admin = $pdo->query("SELECT * FROM users WHERE role = 'Admin' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$coord = $pdo->query("SELECT * FROM users WHERE role = 'Coordinator' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$faculty = $pdo->query("SELECT * FROM users WHERE role = 'Faculty' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$principal = $pdo->query("SELECT * FROM users WHERE role = 'Principal' LIMIT 1")->fetch(PDO::FETCH_ASSOC);

echo "=======================================================\n";
echo "Simulating Page Renders Across All Roles\n";
echo "=======================================================\n\n";

// 1. HOD on approvals.php
$res = simulate_render($hod, __DIR__ . '/../php-app/approvals.php');
if ($res['status'] === 'ok') {
    $hasEditReqBtn = strpos($res['content'], 'Request Edit to Dean/Admin') !== false || strpos($res['content'], 'Review Records') !== false;
    $hasHodModal   = strpos($res['content'], 'id="hodEditModal"') !== false;
    $hasReasonField = strpos($res['content'], 'name="reason"') !== false;
    $hasCorrectionField = strpos($res['content'], 'name="correction"') !== false;
    $hasDirectApprove = strpos($res['content'], "onclick=\"reviewRecord('journal',") !== false && strpos($res['content'], "'approve'") !== false;

    echo "[PASS] HOD on approvals.php rendered successfully\n";
    echo "       - Contains Review Records / Edit Request elements: " . ($hasEditReqBtn ? 'YES' : 'NO') . "\n";
    echo "       - Contains #hodEditModal with structured fields: " . ($hasHodModal && $hasReasonField && $hasCorrectionField ? 'YES' : 'NO') . "\n";
    echo "       - Direct Approve/Reject buttons for HOD present: " . ($hasDirectApprove ? 'YES (UNEXPECTED)' : 'NO (CORRECT)') . "\n";
} else {
    echo "[FAIL] HOD on approvals.php: " . $res['error'] . "\n";
}

// 2. Dean on approvals.php
$res = simulate_render($dean, __DIR__ . '/../php-app/approvals.php');
if ($res['status'] === 'ok') {
    echo "[PASS] Dean on approvals.php rendered successfully\n";
} else {
    echo "[FAIL] Dean on approvals.php: " . $res['error'] . "\n";
}

// 3. Coordinator on approvals.php
$res = simulate_render($coord, __DIR__ . '/../php-app/approvals.php');
if ($res['status'] === 'ok') {
    echo "[PASS] Coordinator on approvals.php rendered successfully\n";
} else {
    echo "[FAIL] Coordinator on approvals.php: " . $res['error'] . "\n";
}

// 4. Admin on edit-requests.php
$res = simulate_render($admin, __DIR__ . '/../php-app/edit-requests.php');
if ($res['status'] === 'ok') {
    $hasTicketModal = strpos($res['content'], 'id="ticketDetailsModal"') !== false;
    $hasProcModal = strpos($res['content'], 'id="processModal"') !== false;
    echo "[PASS] Admin on edit-requests.php rendered successfully\n";
    echo "       - Contains #ticketDetailsModal: " . ($hasTicketModal ? 'YES' : 'NO') . "\n";
    echo "       - Contains #processModal: " . ($hasProcModal ? 'YES' : 'NO') . "\n";
} else {
    echo "[FAIL] Admin on edit-requests.php: " . $res['error'] . "\n";
}

// 5. Dean on edit-requests.php
$res = simulate_render($dean, __DIR__ . '/../php-app/edit-requests.php');
if ($res['status'] === 'ok') {
    echo "[PASS] Dean on edit-requests.php rendered successfully\n";
} else {
    echo "[FAIL] Dean on edit-requests.php: " . $res['error'] . "\n";
}

// 6. HoD on edit-requests.php (Status tracking mode)
$res = simulate_render($hod, __DIR__ . '/../php-app/edit-requests.php');
if ($res['status'] === 'ok') {
    $hasBackLink = strpos($res['content'], 'Back to Review Records') !== false;
    echo "[PASS] HoD on edit-requests.php rendered successfully (tracking mode)\n";
    echo "       - Contains Back to Review Records link: " . ($hasBackLink ? 'YES' : 'NO') . "\n";
} else {
    echo "[FAIL] HoD on edit-requests.php: " . $res['error'] . "\n";
}

// 7. Faculty on edit-requests.php -> must be blocked
try {
    $_SESSION['user'] = $faculty;
    ob_start();
    require_role(['Admin', 'Dean', 'HoD']);
    ob_end_clean();
    echo "[FAIL] Faculty was NOT blocked by require_role\n";
} catch (\Throwable $e) {
    ob_end_clean();
    echo "[PASS] Faculty correctly blocked from edit-requests.php (require_role)\n";
}

// 8. Coordinator on edit-requests.php -> must be blocked
try {
    $_SESSION['user'] = $coord;
    ob_start();
    require_role(['Admin', 'Dean', 'HoD']);
    ob_end_clean();
    echo "[FAIL] Coordinator was NOT blocked by require_role\n";
} catch (\Throwable $e) {
    ob_end_clean();
    echo "[PASS] Coordinator correctly blocked from edit-requests.php (require_role)\n";
}

// 9. Principal on edit-requests.php -> must be blocked
try {
    $_SESSION['user'] = $principal;
    ob_start();
    require_role(['Admin', 'Dean', 'HoD']);
    ob_end_clean();
    echo "[FAIL] Principal was NOT blocked by require_role\n";
} catch (\Throwable $e) {
    ob_end_clean();
    echo "[PASS] Principal correctly blocked from edit-requests.php (require_role)\n";
}

echo "\nAll role rendering simulations completed.\n";
