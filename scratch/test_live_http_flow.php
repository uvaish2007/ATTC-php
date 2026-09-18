<?php
/**
 * Live HTTP End-to-End Workflow Verification via Localhost:8000
 * Tests actual HTTP requests, sessions, cookies, CSRF tokens, and role interactions.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/../php-app/inc/db.php';
require_once __DIR__ . '/../php-app/models/Target.php';

$pdo = db();
$activeYear = active_academic_year();
$baseUrl = 'http://localhost:8000/php-app';

echo "=======================================================\n";
echo "LIVE HTTP END-TO-END WORKFLOW VERIFICATION\n";
echo "Base URL: {$baseUrl}\n";
echo "=======================================================\n\n";

$pass = 0;
$fail = 0;
function test_assert(string $name, bool $condition, string $detail = ''): void {
    global $pass, $fail;
    if ($condition) {
        $pass++;
        echo "[PASS] {$name}\n";
        if ($detail) echo "       -> {$detail}\n";
    } else {
        $fail++;
        echo "[FAIL] {$name}\n";
        if ($detail) echo "       -> {$detail}\n";
    }
}

class HttpSession {
    private string $cookieFile;
    public string $lastUrl = '';
    public int $lastHttpCode = 0;

    public function __construct() {
        $this->cookieFile = tempnam(sys_get_temp_dir(), 'attc_cookie_');
    }

    public function __destruct() {
        if (file_exists($this->cookieFile)) {
            @unlink($this->cookieFile);
        }
    }

    public function request(string $url, string $method = 'GET', array $data = [], array $headers = []): string {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_COOKIEJAR, $this->cookieFile);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $this->cookieFile);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        }

        if ($headers) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        $res = curl_exec($ch);
        $this->lastHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $this->lastUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        curl_close($ch);

        return $res ?: '';
    }

    public function extractCsrf(string $html): string {
        if (preg_match('/name=["\']csrf["\']\s+value=["\']([a-f0-9]+)["\']/i', $html, $m)) {
            return $m[1];
        }
        return '';
    }

    public function login(string $role, string $email, string $password): bool {
        global $baseUrl;
        $html = $this->request("{$baseUrl}/login.php");
        $csrf = $this->extractCsrf($html);

        $postData = [
            'csrf' => $csrf,
            'role' => $role,
            'email' => $email,
            'password' => $password,
        ];

        $res = $this->request("{$baseUrl}/login.php", 'POST', $postData);
        return strpos($this->lastUrl, 'dashboard.php') !== false || strpos($res, 'Dashboard') !== false;
    }
}

// 0. Setup a clean submitted record in journal_publications
$stmt = $pdo->prepare("INSERT INTO journal_publications 
    (faculty_name, department, academic_year, author_type, co_authors, paper_title, journal_name, journal_type, issn, volume_issue, publication_month, doi, journal_link, document_link, status, created_by, created_at)
    VALUES (?, ?, ?, 'Author-1', 'Co-Authors', 'Live HTTP Workflow Paper', 'Springer Nature', 'SCI', '5555-6666', 'Vol 9', '02/2026', 'https://doi.org/10.1234/live', 'https://example.com/live', 'https://example.com/doc', 'Submitted', 5, NOW())");
$stmt->execute(['Dr. Live Faculty', 'CSBS', $activeYear]);
$recId = (int) $pdo->lastInsertId();
echo "Inserted test record #{$recId} in CSBS.\n\n";

// 1. Test HoD Session
$hodSession = new HttpSession();
$hodLoggedIn = $hodSession->login('HoD', 'hod@atts.edu', 'hod123');
test_assert("1. HoD can log in successfully via HTTP", $hodLoggedIn);

$approvalsHtml = $hodSession->request("{$baseUrl}/approvals.php");
$hasRequestEditBtn = strpos($approvalsHtml, 'Request Edit to Dean/Admin') !== false;
$hasHodModal = strpos($approvalsHtml, 'id="hodEditModal"') !== false;
$hasDirectApprove = preg_match('/name=["\']review_action["\']\s+value=["\']approve["\']/i', $approvalsHtml);
$hasDirectReject  = preg_match('/name=["\']review_action["\']\s+value=["\']reject["\']/i', $approvalsHtml);

test_assert("2. HoD approvals.php has 'Request Edit to Dean/Admin' button and modal", $hasRequestEditBtn && $hasHodModal);
test_assert("3. HoD approvals.php has NO direct Approve or Reject action buttons", !$hasDirectApprove && !$hasDirectReject);

// 2. Submit structured edit request as HoD
$csrf = $hodSession->extractCsrf($approvalsHtml);
$reqPost = [
    'csrf' => $csrf,
    'review_action' => 'request_edit',
    'record_type' => 'journal',
    'record_id' => $recId,
    'reason' => 'Author affiliation is listed incorrectly on page 1.',
    'correction' => 'Change department affiliation to CSBS and re-upload proof.',
    'hod_comments' => 'Verified with faculty member.',
];
$respHtml = $hodSession->request("{$baseUrl}/approvals.php", 'POST', $reqPost);
test_assert("4. HoD submit Edit Request POST succeeds and redirects with flash message", 
    strpos($respHtml, 'submitted to Dean/Admin successfully') !== false);

// Check HoD edit requests tracking view
$hodTicketsHtml = $hodSession->request("{$baseUrl}/approvals.php?tab=edit_requests");
$hasTicketInHodView = strpos($hodTicketsHtml, "Live HTTP Workflow Paper") !== false;
$hasAllowBtnInHodView = (bool) preg_match('/<button[^>]*onclick=["\']openProcessModal/i', $hodTicketsHtml);
test_assert("5. HoD can track ticket status in edit-requests tab", $hasTicketInHodView);
test_assert("6. HoD does NOT have processing actions (Allow/Reject) on edit-requests tab", !$hasAllowBtnInHodView);

// 3. Test Dean Session
$deanSession = new HttpSession();
$deanLoggedIn = $deanSession->login('Dean', 'dean@atts.edu', 'dean123');
test_assert("7. Dean can log in successfully via HTTP", $deanLoggedIn);

$deanTicketsHtml = $deanSession->request("{$baseUrl}/approvals.php?tab=edit_requests");
$hasTicketInDeanView = strpos($deanTicketsHtml, "Live HTTP Workflow Paper") !== false;
$hasAllowBtnInDeanView = strpos($deanTicketsHtml, 'openProcessModal') !== false && strpos($deanTicketsHtml, 'Allow') !== false;
$hasRejectBtnInDeanView = strpos($deanTicketsHtml, 'openProcessModal') !== false && strpos($deanTicketsHtml, 'Reject') !== false;
test_assert("8. Dean receives ticket in Edit Requests with Allow and Reject action buttons", 
    $hasTicketInDeanView && $hasAllowBtnInDeanView && $hasRejectBtnInDeanView);

// Extract Ticket ID from database for this record
$stmt = $pdo->prepare("SELECT id FROM edit_requests WHERE record_type = 'journal' AND record_id = ? ORDER BY id DESC LIMIT 1");
$stmt->execute([$recId]);
$ticketId = (int) $stmt->fetchColumn();

// Dean processes ticket: Approve / Unlock
$deanCsrf = $deanSession->extractCsrf($deanTicketsHtml);
$deanProcPost = [
    'csrf' => $deanCsrf,
    'process_action' => 'approve',
    'ticket_id' => $ticketId,
    'admin_comments' => 'Approved by Dean. Department coordinator may unlock and update.',
];
$deanProcResp = $deanSession->request("{$baseUrl}/approvals.php?tab=edit_requests", 'POST', $deanProcPost);
test_assert("9. Dean approves edit request ticket via HTTP POST", 
    strpos($deanProcResp, 'approved. Record unlocked') !== false);

// Check record status in DB
$stmt = $pdo->prepare("SELECT status FROM journal_publications WHERE id = ?");
$stmt->execute([$recId]);
$recStatus = $stmt->fetchColumn();
test_assert("10. Underlying record is now 'Unlocked for Edit'", $recStatus === 'Unlocked for Edit');

// 4. Test Coordinator Session: Edit and Resubmit Unlocked Record
$coordSession = new HttpSession();
$coordLoggedIn = $coordSession->login('Coordinator', 'coordinator@atts.edu', 'coordinator123');
test_assert("11. Coordinator can log in successfully via HTTP", $coordLoggedIn);

$coordApprovalsHtml = $coordSession->request("{$baseUrl}/approvals.php");
$hasEditAndResubmitBtn = strpos($coordApprovalsHtml, "upload.php?type=journal&amp;edit_id={$recId}") !== false
    || strpos($coordApprovalsHtml, "upload.php?type=journal&edit_id={$recId}") !== false;
test_assert("12. Coordinator sees 'Edit & Resubmit' button on approvals.php", $hasEditAndResubmitBtn);

// Visit upload.php with edit_id
$coordEditPageHtml = $coordSession->request("{$baseUrl}/upload.php?type=journal&edit_id={$recId}");
$hasUnlockedBanner = strpos($coordEditPageHtml, 'Editing Unlocked Record') !== false;
$hasResubmitBtn = strpos($coordEditPageHtml, 'Save &amp; Resubmit Record') !== false 
    || strpos($coordEditPageHtml, 'Save & Resubmit Record') !== false;
test_assert("13. upload.php renders with unlocked editing banner and resubmit button", 
    $hasUnlockedBanner && $hasResubmitBtn);

// Coordinator submits form update
$coordCsrf = $coordSession->extractCsrf($coordEditPageHtml);
$updateData = [
    'csrf' => $coordCsrf,
    'record_type' => 'journal',
    'edit_id' => $recId,
    'author_type' => 'Author-1',
    'faculty_name' => 'Dr. Live Faculty Corrected',
    'department' => 'CSBS',
    'co_authors' => 'Co-Authors Updated',
    'paper_title' => 'Live HTTP Workflow Paper (Revised)',
    'journal_name' => 'Springer Nature CS',
    'journal_type' => 'SCI',
    'issn' => '5555-6666',
    'volume_issue' => 'Vol 9, Issue 2',
    'publication_month' => '02/2026',
    'doi' => 'https://doi.org/10.1234/live.revised',
    'journal_link' => 'https://example.com/live',
    'document_link' => 'https://example.com/doc',
    'nav' => 'add',
];
$updateResp = $coordSession->request("{$baseUrl}/upload.php", 'POST', $updateData);
test_assert("14. Coordinator submits edit and record is updated", 
    strpos($updateResp, 'updated and saved to database') !== false);

// Verify record status is 'Approved' and ticket status is 'Completed'
$stmt->execute([$recId]);
$finalRecStatus = $stmt->fetchColumn();

$stmtTicket = $pdo->prepare("SELECT status FROM edit_requests WHERE id = ?");
$stmtTicket->execute([$ticketId]);
$finalTicketStatus = $stmtTicket->fetchColumn();

test_assert("15. Final state: Record status is 'Approved' and Ticket status is 'Completed'", 
    $finalRecStatus === 'Approved' && $finalTicketStatus === 'Completed',
    "Record: {$finalRecStatus}, Ticket: {$finalTicketStatus}");

// 5. Test Unauthorized Access
$facSession = new HttpSession();
$facSession->login('Faculty', 'faculty@atts.edu', 'faculty123');
$facResp = $facSession->request("{$baseUrl}/edit-requests.php");
test_assert("16. Unauthorized role (Faculty) is blocked from edit-requests.php", 
    $facSession->lastHttpCode === 403 || strpos($facSession->lastUrl, 'dashboard.php') !== false || strpos($facResp, 'Access Denied') !== false || strpos($facResp, 'not authorized') !== false);

// 6. Test Sidebar and Approval Page Integration
$adminSession = new HttpSession();
$adminSession->login('Admin', 'admin@atts.edu', 'admin123');
$adminApprovalsHtml = $adminSession->request("{$baseUrl}/approvals.php");

// Verify Admin sidebar: Approvals present, Edit Requests NOT in sidebar nav-label
$hasApprovalsInAdminSidebar = preg_match('/<span class="nav-label">\s*Approvals\s*<\/span>/', $adminApprovalsHtml);
$hasEditRequestsInAdminSidebar = preg_match('/<span class="nav-label">\s*Edit Requests\s*<\/span>/', $adminApprovalsHtml);
test_assert("17. Admin sidebar has Approvals and NO separate Edit Requests item", 
    $hasApprovalsInAdminSidebar && !$hasEditRequestsInAdminSidebar);

// Verify Approvals page has Edit Requests tab for Admin & Dean
$hasEditRequestsTabForAdmin = strpos($adminApprovalsHtml, 'approvals.php?tab=edit_requests') !== false;
$hasEditRequestsTabForDean  = strpos($deanTicketsHtml, 'approvals.php?tab=edit_requests') !== false;
test_assert("18. Admin and Dean have Edit Requests tab inside Approvals page", 
    $hasEditRequestsTabForAdmin && $hasEditRequestsTabForDean);

// Verify HoD sidebar has Review Records and NO separate Edit Requests item
$hasReviewRecordsInHodSidebar = preg_match('/<span class="nav-label">\s*Review Records\s*<\/span>/', $approvalsHtml);
$hasEditRequestsInHodSidebar = preg_match('/<span class="nav-label">\s*Edit Requests\s*<\/span>/', $approvalsHtml);
test_assert("19. HoD sidebar has Review Records and NO separate Edit Requests item", 
    $hasReviewRecordsInHodSidebar && !$hasEditRequestsInHodSidebar);

// Clean up test data
$pdo->prepare("DELETE FROM edit_requests WHERE id = ?")->execute([$ticketId]);
$pdo->prepare("DELETE FROM journal_publications WHERE id = ?")->execute([$recId]);

echo "\nCleaned up live HTTP test rows.\n\n";
echo "=======================================================\n";
echo "LIVE HTTP TEST RESULTS: {$pass} PASSED, {$fail} FAILED\n";
echo "=======================================================\n";

if ($fail > 0) exit(1);
exit(0);
