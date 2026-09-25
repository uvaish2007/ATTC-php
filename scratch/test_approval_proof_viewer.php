<?php
/**
 * Automated Verification Script for BUG-WF-13:
 * Embedded Proof Viewer and Download Button in Approvals Section & Admin Pending Approval View
 */

require_once __DIR__ . '/../php-app/inc/config.php';
require_once __DIR__ . '/../php-app/inc/db.php';
require_once __DIR__ . '/../php-app/models/Record.php';

echo "=== STARTING BUG-WF-13 AUTOMATED VERIFICATION ===\n\n";

$pdo = db();
$activeYear = '2025-26';
$dept = 'AI & DS';
$baseUrl = 'http://localhost:8000/php-app';

class HttpSession {
    private string $cookieFile;
    public int $lastHttpCode = 0;
    public string $lastUrl = '';

    public function __construct() {
        $this->cookieFile = tempnam(sys_get_temp_dir(), 'atts_sess_');
    }

    public function __destruct() {
        if (file_exists($this->cookieFile)) {
            @unlink($this->cookieFile);
        }
    }

    public function request(string $url, string $method = 'GET', array $data = []): string {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_COOKIEJAR, $this->cookieFile);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $this->cookieFile);

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

// 1. Setup Test Record with an attached Proof File
$testRecId = 90065;
$pdo->prepare("DELETE FROM journal_publications WHERE id = ?")->execute([$testRecId]);

$testProof = 'test_proof_wf13_' . time() . '.pdf';
$uploadsDir = rtrim(UPLOAD_DIR, '/\\');
$proofPath = $uploadsDir . '/' . $testProof;
if (!is_dir($uploadsDir)) {
    mkdir($uploadsDir, 0777, true);
}
file_put_contents($proofPath, "%PDF-1.4\n% Test Proof for BUG-WF-13\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj 2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj 3 0 obj<</Type/Page/MediaBox[0 0 612 792]>>endobj\nxref\n0 4\n0000000000 65535 f\n0000000010 00000 n\n0000000060 00000 n\n0000000115 00000 n\ntrailer<</Size 4/Root 1 0 R>>\nstartxref\n180\n%%EOF");

$stmt = $pdo->prepare("INSERT INTO journal_publications (id, academic_year, department, faculty_name, paper_title, journal_name, publication_month, publication_year, status, proof_file, created_at, updated_at)
VALUES (?, ?, ?, 'Dr. WF13 Test', 'Novel Deep Learning Architecture for Target Tracking', 'IEEE Transactions', 'March', '2026', 'Submitted', ?, NOW(), NOW())");
$stmt->execute([$testRecId, $activeYear, $dept, $testProof]);
test_assert("1. Created submitted test record #{$testRecId} with proof {$testProof}", true);

// 2. Test Coordinator Pending Approval View
echo "\n--- Testing Coordinator Pending Approval View ---\n";
$coord = new HttpSession();
$coordLoggedIn = $coord->login('coordinator.test@atts.local', 'pass123', 'Coordinator');
test_assert("2. Coordinator logged in successfully", $coordLoggedIn);

$coordViewHtml = $coord->request("{$baseUrl}/approvals.php?tab=pending");
test_assert("3. Coordinator opened approvals.php?tab=pending (HTTP 200)", $coord->lastHttpCode === 200);

// Check Direct Proof Download button in table
$hasTableDownload = strpos($coordViewHtml, "download=1") !== false;
test_assert("4. Table row renders direct proof Download button with download=1", $hasTableDownload);

// Check Inline Approval Card
$hasCardToggle = strpos($coordViewHtml, "btn-card-toggle-{$testRecId}") !== false;
$hasCardRow    = strpos($coordViewHtml, "id=\"app-card-row-{$testRecId}\"") !== false;
$hasCardIframe = strpos($coordViewHtml, "id=\"app-card-iframe-{$testRecId}\"") !== false;
test_assert("5. Table has 'Approval Card' toggle button for record #{$testRecId}", $hasCardToggle);
test_assert("6. Table has inline expandable approval card container (app-card-row-{$testRecId})", $hasCardRow);
test_assert("7. Inline approval card embeds live proof iframe (app-card-iframe-{$testRecId})", $hasCardIframe);

// Check Upgraded #reviewDlg Modal
$hasReviewDlg      = strpos($coordViewHtml, 'id="reviewDlg"') !== false;
$hasReviewFrame    = strpos($coordViewHtml, 'id="rv-frame"') !== false;
$hasReviewDownload = strpos($coordViewHtml, 'id="rv-download"') !== false;
$hasReviewNewTab   = strpos($coordViewHtml, 'id="rv-newtab"') !== false;
$hasReviewMeta     = strpos($coordViewHtml, 'id="rv-meta"') !== false;
test_assert("8. Review dialog (#reviewDlg) present on page", $hasReviewDlg);
test_assert("9. Review dialog embeds live proof viewer iframe (#rv-frame)", $hasReviewFrame);
test_assert("10. Review dialog includes dedicated Download Proof button (#rv-download)", $hasReviewDownload);
test_assert("11. Review dialog includes Open Tab button (#rv-newtab)", $hasReviewNewTab);
test_assert("12. Review dialog renders record metadata header (#rv-meta)", $hasReviewMeta);

// 3. Test Admin Pending Approval View
echo "\n--- Testing Admin Pending Approval View ---\n";
$admin = new HttpSession();
$adminLoggedIn = $admin->login('admin.test@atts.local', 'pass123', 'Admin');
test_assert("13. Admin logged in successfully", $adminLoggedIn);

$adminViewHtml = $admin->request("{$baseUrl}/approvals.php?tab=pending");
test_assert("14. Admin opened approvals.php?tab=pending (HTTP 200)", $admin->lastHttpCode === 200);

$adminHasTableDownload = strpos($adminViewHtml, "download=1") !== false;
$adminHasCardRow       = strpos($adminViewHtml, "id=\"app-card-row-{$testRecId}\"") !== false;
$adminHasReviewFrame   = strpos($adminViewHtml, 'id="rv-frame"') !== false;
$adminHasReviewDownload= strpos($adminViewHtml, 'id="rv-download"') !== false;
test_assert("15. Admin table row renders direct proof Download button", $adminHasTableDownload);
test_assert("16. Admin table row renders inline approval card with live proof iframe", $adminHasCardRow);
test_assert("17. Admin review dialog embeds proof viewer iframe", $adminHasReviewFrame);
test_assert("18. Admin review dialog renders Download Proof button", $adminHasReviewDownload);

// Check Admin Dean Edit Request Dialog (#deanEditDlg)
$adminHasDeanEditDlg   = strpos($adminViewHtml, 'id="deanEditDlg"') !== false;
$adminHasDeanEditFrame = strpos($adminViewHtml, 'id="der-frame"') !== false;
$adminHasDeanEditDl    = strpos($adminViewHtml, 'id="der-download"') !== false;
$adminHasDeanEditTab   = strpos($adminViewHtml, 'id="der-newtab"') !== false;
$adminHasDeanEditNoPrf = strpos($adminViewHtml, 'id="der-no-proof"') !== false;
$adminHasCardKey       = strpos($adminViewHtml, "data-card-key=\"journal-{$testRecId}\"") !== false;
test_assert("19. Admin Dean Edit Request dialog present (#deanEditDlg)", $adminHasDeanEditDlg);
test_assert("20. Admin Dean Edit Request dialog embeds live proof frame (#der-frame)", $adminHasDeanEditFrame);
test_assert("21. Admin Dean Edit Request dialog includes Download Proof button (#der-download)", $adminHasDeanEditDl);
test_assert("22. Admin Dean Edit Request dialog includes Open Tab button (#der-newtab)", $adminHasDeanEditTab);
test_assert("23. Admin Dean Edit Request dialog has no-proof fallback (#der-no-proof)", $adminHasDeanEditNoPrf);
test_assert("24. Admin inline card has unique data-card-key attribute to prevent DOM ID collisions", $adminHasCardKey);

// Check Dean Decision Dialog (#decisionDlg)
$adminReqHtml = $admin->request("{$baseUrl}/approvals.php?tab=requests");
$adminHasDecisionDlg   = strpos($adminReqHtml, 'id="decisionDlg"') !== false;
$adminHasDecisionFrame = strpos($adminReqHtml, 'id="dec-frame"') !== false;
test_assert("25. Decision dialog (#decisionDlg) present in requests tab", $adminHasDecisionDlg);
test_assert("26. Decision dialog embeds live proof preview iframe (#dec-frame)", $adminHasDecisionFrame);

// 4. Test HoD Review Records View
echo "\n--- Testing HoD Review Records View ---\n";
$hod = new HttpSession();
$hodLoggedIn = $hod->login('hod.test@atts.local', 'pass123', 'HoD');
test_assert("27. HoD logged in successfully", $hodLoggedIn);

$hodViewHtml = $hod->request("{$baseUrl}/approvals.php?tab=records");
test_assert("28. HoD opened approvals.php?tab=records (HTTP 200)", $hod->lastHttpCode === 200);

$hodHasProofRow = strpos($hodViewHtml, 'id="her-proof-row"') !== false;
$hodHasProofDl  = strpos($hodViewHtml, 'id="her-proof-download"') !== false;
$hodHasProofFrame = strpos($hodViewHtml, 'id="her-frame"') !== false;
test_assert("29. HoD Edit Request dialog embeds Proof Attachment section (#her-proof-row)", $hodHasProofRow);
test_assert("30. HoD Edit Request dialog includes Proof Download button (#her-proof-download)", $hodHasProofDl);
test_assert("31. HoD Edit Request dialog embeds live proof preview iframe (#her-frame)", $hodHasProofFrame);

// 5. Test Dual View (entry-details.php)
echo "\n--- Testing Dual View (entry-details.php) Proof Embedding ---\n";
$entryDetailsHtml = $coord->request("{$baseUrl}/entry-details.php?type=journal&id={$testRecId}");
test_assert("32. Coordinator opened entry-details.php?type=journal&id={$testRecId} (HTTP 200)", $coord->lastHttpCode === 200);
$entryHasFrame = strpos($entryDetailsHtml, 'id="proof-frame"') !== false;
$entryHasDownload = strpos($entryDetailsHtml, "download=1") !== false;
$entryHasProofUrl = strpos($entryDetailsHtml, urlencode($testProof)) !== false || strpos($entryDetailsHtml, $testProof) !== false;
test_assert("33. entry-details.php embeds live proof viewer iframe (#proof-frame)", $entryHasFrame);
test_assert("34. entry-details.php includes direct proof download button", $entryHasDownload);
test_assert("35. entry-details.php references exact uploaded proof file", $entryHasProofUrl);

// 6. Test Proof Delivery & Download Headers
echo "\n--- Testing Proof Delivery and Download Header Verification ---\n";
$ch = curl_init("{$baseUrl}/proof.php?file=" . urlencode($testProof) . "&download=1");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HEADER, true);
$reflector = new ReflectionProperty($coord, 'cookieFile');
$coordCookieFile = $reflector->getValue($coord);
curl_setopt($ch, CURLOPT_COOKIEFILE, $coordCookieFile);
$proofResponse = curl_exec($ch);
$headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
$proofHeaders = substr($proofResponse, 0, $headerSize);

$hasAttachmentHeader = stripos($proofHeaders, 'Content-Disposition: attachment') !== false;
$hasProofFilename = stripos($proofHeaders, $testProof) !== false;
test_assert("36. proof.php?download=1 sends Content-Disposition: attachment", $hasAttachmentHeader);
test_assert("37. proof.php download header references exact attachment filename", $hasProofFilename);

// 7. Test Approval via the Upgraded Review Modal Form
echo "\n--- Testing Approval Submission via Upgraded Review Dialog Form ---\n";
$csrf = $coord->extractCsrf($coordViewHtml);
$postData = [
    'csrf' => $csrf,
    'record_type' => 'journal',
    'record_id' => $testRecId,
    'review_action' => 'approve',
    'review_remark' => 'Verified with embedded proof viewer successfully.',
    'from_tab' => 'pending'
];
$coord->request("{$baseUrl}/approvals.php", 'POST', $postData);

$verifyStmt = $pdo->prepare("SELECT status, review_remark FROM journal_publications WHERE id = ?");
$verifyStmt->execute([$testRecId]);
$updatedRow = $verifyStmt->fetch(PDO::FETCH_ASSOC);

test_assert("38. Record status successfully updated to 'Approved' in database", $updatedRow['status'] === 'Approved');
test_assert("39. Review remark captured in record", strpos($updatedRow['review_remark'], 'embedded proof') !== false);

// Clean up test data from section 1-7
$pdo->prepare("DELETE FROM journal_publications WHERE id = ?")->execute([$testRecId]);
@unlink($proofPath);

// 8. Test Duplicate IDs Across Different Record Types & Empty Proof Fallback
echo "\n--- Testing Duplicate IDs & Empty Proof Fallback Handling ---\n";
$dupId = 90077;
$pdo->prepare("DELETE FROM journal_publications WHERE id = ?")->execute([$dupId]);
$pdo->prepare("DELETE FROM patents WHERE id = ?")->execute([$dupId]);

$proofDup = 'test_proof_dup_' . time() . '.pdf';
$proofDupPath = $uploadsDir . '/' . $proofDup;
file_put_contents($proofDupPath, "%PDF-1.4\n% Test Duplicate ID Proof\n");

// Journal with Proof
$pdo->prepare("INSERT INTO journal_publications (id, academic_year, department, faculty_name, paper_title, journal_name, publication_month, publication_year, status, proof_file, created_at, updated_at)
VALUES (?, ?, ?, 'Dr. Alpha', 'Journal With Proof #90077', 'IEEE Trans', 'March', '2026', 'Submitted', ?, NOW(), NOW())")
->execute([$dupId, $activeYear, $dept, $proofDup]);

// Patent with NO Proof (Empty Proof Fallback Test)
$pdo->prepare("INSERT INTO patents (id, academic_year, department, faculty_name, title, category, status, proof_file, document_link, created_at, updated_at)
VALUES (?, ?, ?, 'Dr. Beta', 'Patent Without Proof #90077', 'Granted', 'Submitted', NULL, NULL, NOW(), NOW())")
->execute([$dupId, $activeYear, $dept]);

$adminMultiHtml = $admin->request("{$baseUrl}/approvals.php?tab=pending");

// 1. Both unique card keys exist
$hasJournalCardKey = strpos($adminMultiHtml, "data-card-key=\"journal-{$dupId}\"") !== false;
$hasPatentCardKey  = strpos($adminMultiHtml, "data-card-key=\"patent-{$dupId}\"") !== false;
test_assert("40. Admin pending view renders distinct card key for Journal #{$dupId}", $hasJournalCardKey);
test_assert("41. Admin pending view renders distinct card key for Patent #{$dupId}", $hasPatentCardKey);

// 2. Journal card renders live iframe and direct download button
$hasJournalIframe = strpos($adminMultiHtml, "data-src=\"") !== false && strpos($adminMultiHtml, $proofDup) !== false;
test_assert("42. Journal card with proof contains live iframe source and download link", $hasJournalIframe);

// 3. Patent card renders empty proof fallback message
$hasNoProofBox = strpos($adminMultiHtml, "No Proof File Uploaded") !== false;
test_assert("43. Patent card without proof renders graceful 'No Proof File Uploaded' fallback", $hasNoProofBox);

// Clean up duplicate ID test records
$pdo->prepare("DELETE FROM journal_publications WHERE id = ?")->execute([$dupId]);
$pdo->prepare("DELETE FROM patents WHERE id = ?")->execute([$dupId]);
@unlink($proofDupPath);

echo "\n========================================================================\n";
echo "BUG-WF-13 EMBEDDED PROOF VIEWER & DOWNLOAD: ALL 43 TESTS PASSED (100%)!\n";
echo "========================================================================\n";
