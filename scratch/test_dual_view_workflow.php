<?php
/**
 * Automated Verification Script for BUG-WF-12:
 * Governance - Admin / Dean View: Dual view of entries with request-for-edit governance (no direct silent override).
 */

require_once __DIR__ . '/../php-app/inc/db.php';
require_once __DIR__ . '/../php-app/inc/helpers.php';
require_once __DIR__ . '/../php-app/models/Record.php';

$pdo = db();
$baseUrl = 'http://localhost:8000/php-app';

echo "========================================================================\n";
echo "TESTING BUG-WF-12: DUAL VIEW & REQUEST-FOR-EDIT GOVERNANCE\n";
echo "========================================================================\n\n";

class HttpSession {
    public $cookieJar = '';
    public $lastUrl = '';
    public $lastHttpCode = 0;

    public function __construct() {
        $this->cookieJar = tempnam(sys_get_temp_dir(), 'atts_cookie_');
    }

    public function request(string $url, string $method = 'GET', array $data = []): string {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_COOKIEJAR, $this->cookieJar);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $this->cookieJar);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        }

        $resp = curl_exec($ch);
        $this->lastHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $this->lastUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);

        return $resp ?: '';
    }

    public function extractCsrf(string $html): string {
        if (preg_match('/name="csrf"\s+value="([^"]+)"/', $html, $m)) {
            return $m[1];
        }
        if (preg_match('/value="([^"]+)"\s+name="csrf"/', $html, $m)) {
            return $m[1];
        }
        return '';
    }

    public function login(string $email, string $password, string $role): bool {
        $html = $this->request('http://localhost:8000/php-app/login.php');
        $csrf = $this->extractCsrf($html);
        $post = [
            'csrf' => $csrf,
            'email' => $email,
            'password' => $password,
            'role' => $role,
        ];
        $resp = $this->request('http://localhost:8000/php-app/login.php', 'POST', $post);
        return strpos($this->lastUrl, 'dashboard.php') !== false || strpos($resp, 'logout.php') !== false;
    }
}

function test_assert(string $desc, bool $condition, string $detail = '') {
    if ($condition) {
        echo "[PASS] {$desc}\n";
    } else {
        echo "[FAIL] {$desc}" . ($detail ? " -- {$detail}" : '') . "\n";
        exit(1);
    }
}

// 1. Setup Test Record
$testRecId = 90060;
$pdo->prepare("DELETE FROM journal_publications WHERE id = ?")->execute([$testRecId]);
$pdo->prepare("DELETE FROM edit_requests WHERE record_id = ? AND record_type = 'journal'")->execute([$testRecId]);

$proofFilename = 'test_proof_wf12_' . time() . '.pdf';
$insertStmt = $pdo->prepare(
    "INSERT INTO journal_publications (
        id, academic_year, department, faculty_name, author_type, paper_title,
        journal_name, journal_type, issn, volume_issue, publication_month,
        doi, journal_link, document_link, proof_file, status, created_by, created_at
    ) VALUES (
        ?, '2025-26', 'AI & DS', 'Dr. Dual View Tester', 'Author-1', 'Deep Learning Governance Architecture',
        'IEEE Transactions on AI', 'SCI', '1234-5678', 'Vol 10, Issue 1', '01/2026',
        '10.1109/TAI.2026.001', 'https://ieeexplore.ieee.org', 'https://example.com/doc', ?, 'Approved', 1, NOW()
    )"
);
$insertStmt->execute([$testRecId, $proofFilename]);
test_assert("1. Created approved test record #{$testRecId} in database", true);

// 2. Test Dean Session: Open Entry Details & Verify Dual View
$dean = new HttpSession();
$loggedIn = $dean->login('dean.test@atts.local', 'pass123', 'Dean');
test_assert("2. Dean logged in via HTTP session", $loggedIn);

// Open Approvals page and check Dual View button
$approvalsHtml = $dean->request("{$baseUrl}/approvals.php?tab=records");
$hasDualViewLink = strpos($approvalsHtml, "entry-details.php?type=journal&amp;id={$testRecId}") !== false
    || strpos($approvalsHtml, "entry-details.php?type=journal&id={$testRecId}") !== false;
test_assert("3. Dean sees 'Dual View' button on approvals.php table row", $hasDualViewLink);

// Open Entry Details directly
$entryDetailsHtml = $dean->request("{$baseUrl}/entry-details.php?type=journal&id={$testRecId}");
test_assert("4. Dean opens entry-details.php successfully (HTTP 200)", $dean->lastHttpCode === 200);

// Verify Dual View Left Pane (Metadata Attributes)
$hasMetadataCard = strpos($entryDetailsHtml, 'Submitted Entry Attributes') !== false;
$hasPaperTitle   = strpos($entryDetailsHtml, 'Deep Learning Governance Architecture') !== false;
$hasJournalName  = strpos($entryDetailsHtml, 'IEEE Transactions on AI') !== false;
$hasDoiLink      = strpos($entryDetailsHtml, '10.1109/TAI.2026.001') !== false;
test_assert("5. Dual View Left Pane displays submitted metadata attributes & DOI link", 
    $hasMetadataCard && $hasPaperTitle && $hasJournalName && $hasDoiLink);

// Verify Dual View Right Pane (Proof Document Viewer)
$hasProofViewer  = strpos($entryDetailsHtml, 'Proof Document Attachment') !== false
    || strpos($entryDetailsHtml, $proofFilename) !== false;
$hasProofIframe  = strpos($entryDetailsHtml, 'proof.php?file=' . $proofFilename) !== false;
$hasOpenTabBtn   = strpos($entryDetailsHtml, 'Open Proof in Tab') !== false || strpos($entryDetailsHtml, 'Open Tab') !== false;
test_assert("6. Dual View Right Pane displays live proof viewer with iframe and Open Tab button", 
    $hasProofViewer && $hasProofIframe && $hasOpenTabBtn);

// Verify Dual View Governance Panel with 'Request Edit' option
$hasGovPanel     = strpos($entryDetailsHtml, 'Governance Actions (Admin &amp; Dean)') !== false
    || strpos($entryDetailsHtml, 'Governance Actions (Admin & Dean)') !== false;
$hasRequestEdit  = strpos($entryDetailsHtml, 'Request Edit (Return to Coordinator)') !== false
    || strpos($entryDetailsHtml, 'Submit Request for Edit') !== false;
$hasNoSilentOver = strpos($entryDetailsHtml, 'No Silent Override') !== false;
test_assert("7. Dual View includes structured 'Request Edit' workflow without forcing direct approval", 
    $hasGovPanel && $hasRequestEdit && $hasNoSilentOver);

// 3. Dean Submits 'Request Edit' via POST
$csrf = $dean->extractCsrf($entryDetailsHtml);
$reqEditPost = [
    'csrf' => $csrf,
    'gov_action' => 'request_edit',
    'specific_field' => 'doi',
    'current_value' => '10.1109/TAI.2026.001',
    'requested_value' => '10.1109/TAI.2026.999',
    'reason' => 'Publisher notified DOI prefix mismatch. Please verify with official certificate and resubmit.',
];
$respReqEdit = $dean->request("{$baseUrl}/entry-details.php?type=journal&id={$testRecId}", 'POST', $reqEditPost);

// Check Record status in database
$checkStmt = $pdo->prepare("SELECT status, review_remark FROM journal_publications WHERE id = ?");
$checkStmt->execute([$testRecId]);
$recRow = $checkStmt->fetch(PDO::FETCH_ASSOC);

test_assert("8. Record status transitioned to 'Unlocked for Edit'", 
    $recRow['status'] === 'Unlocked for Edit', "Status: " . $recRow['status']);
test_assert("9. Record review remark records Dean instructions", 
    strpos($recRow['review_remark'], 'DOI prefix mismatch') !== false);

// Check Edit Request entry in database
$erStmt = $pdo->prepare("SELECT * FROM edit_requests WHERE record_id = ? AND record_type = 'journal' ORDER BY id DESC LIMIT 1");
$erStmt->execute([$testRecId]);
$erRow = $erStmt->fetch(PDO::FETCH_ASSOC);

test_assert("10. Structured edit_requests ticket created with status 'Approved' by Dean", 
    $erRow && $erRow['status'] === 'Approved');
test_assert("11. Edit Request contains target field and reason", 
    $erRow['specific_field'] === 'doi' && strpos($erRow['reason'], 'DOI prefix mismatch') !== false);

// Check Workflow Audit Log
$auditStmt = $pdo->prepare("SELECT action, old_status, new_status FROM workflow_audit_logs WHERE record_id = ? AND record_type = 'journal' ORDER BY id DESC LIMIT 1");
$auditStmt->execute([$testRecId]);
$auditRow = $auditStmt->fetch(PDO::FETCH_ASSOC);

test_assert("12. Audit log recorded 'DEAN_REQUESTED_CORRECTION' (No silent override)", 
    $auditRow && $auditRow['action'] === 'DEAN_REQUESTED_CORRECTION' && $auditRow['new_status'] === 'Unlocked for Edit');

// 4. Test Admin Session: Dual View and Governance Options
$admin = new HttpSession();
$adminLoggedIn = $admin->login('admin.test@atts.local', 'pass123', 'Admin');
test_assert("13. Admin logged in via HTTP session", $adminLoggedIn);

$adminEntryHtml = $admin->request("{$baseUrl}/entry-details.php?type=journal&id={$testRecId}");
$hasUnlockedNotice = strpos($adminEntryHtml, 'Approved by Dean &middot; Unlocked for Edit') !== false
    || strpos($adminEntryHtml, 'Record Unlocked') !== false;
test_assert("14. Admin dual view clearly reflects Dean unlocked state", $hasUnlockedNotice);

// 5. Test Coordinator Visibility & Correction Workflow
$coord = new HttpSession();
$coordLoggedIn = $coord->login('coordinator.test@atts.local', 'pass123', 'Coordinator');
test_assert("15. Coordinator logged in via HTTP session", $coordLoggedIn);

$coordCorrHtml = $coord->request("{$baseUrl}/approvals.php?tab=corrections");
$coordSeesRecord = strpos($coordCorrHtml, "upload.php?type=journal&amp;edit_id={$testRecId}") !== false
    || strpos($coordCorrHtml, "upload.php?type=journal&edit_id={$testRecId}") !== false;
test_assert("16. Coordinator sees Dean-unlocked record in Corrections tab with 'Edit & Resubmit' button", $coordSeesRecord);

// Clean up test data
$pdo->prepare("DELETE FROM edit_requests WHERE record_id = ? AND record_type = 'journal'")->execute([$testRecId]);
$pdo->prepare("DELETE FROM journal_publications WHERE id = ?")->execute([$testRecId]);

echo "\nCleaned up test record #{$testRecId}.\n\n";
echo "========================================================================\n";
echo "BUG-WF-12 DUAL VIEW & REQUEST-FOR-EDIT VERIFICATION: ALL 16 TESTS PASSED!\n";
echo "========================================================================\n";
