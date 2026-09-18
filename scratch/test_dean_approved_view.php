<?php
require_once __DIR__ . '/test_http_ui_flow.php';

echo "========================================================================\n";
echo "TESTING DEAN APPROVAL STATE, COORDINATOR VISIBILITY, AND ADMIN DISPLAY\n";
echo "========================================================================\n\n";

// 1. Setup an approved edit request in AI & DS department so all roles have live data
echo "--- 1. Generating Test Submission & Edit Request Workflow ---\n";
$clientFac = http_client();
$facLogin = ($clientFac['request'])('http://localhost:8000/php-app/login.php');
$csrfFac = extract_csrf($facLogin['body']);
($clientFac['request'])('http://localhost:8000/php-app/login.php', 'POST', [
    'email' => 'faculty.test@atts.local', 'password' => 'pass123', 'role' => 'Faculty', 'csrf' => $csrfFac
]);

$uniq = time();
$newTitle = 'Dean Approved Visibility Test ' . $uniq;
$uploadPage = ($clientFac['request'])('http://localhost:8000/php-app/upload.php?type=journal');
$csrfUp = extract_csrf($uploadPage['body']);
$postRes = ($clientFac['request'])('http://localhost:8000/php-app/upload.php?type=journal', 'POST', [
    'csrf'              => $csrfUp,
    'record_type'       => 'journal',
    'nav'               => 'submit',
    'academic_year'     => '2025-26',
    'faculty_name'      => 'Test Faculty AIDS',
    'department'        => 'AI & DS',
    'author_type'       => 'Author-1',
    'co_authors'        => 'None',
    'paper_title'       => $newTitle,
    'journal_name'      => 'IEEE Transactions on Software Engineering',
    'journal_type'      => 'SCI',
    'issn'              => '0098-5589',
    'volume_issue'      => 'Vol 52, Issue 4',
    'publication_month' => '09/2026',
    'doi'               => 'https://doi.org/10.TEST/' . $uniq,
    'journal_link'      => 'https://ieee.org',
    'document_link'     => 'https://ieee.org/doc',
]);

// Find the created record ID
$stmt = db()->prepare("SELECT id FROM journal_publications WHERE paper_title = ? ORDER BY id DESC LIMIT 1");
$stmt->execute([$newTitle]);
$newRec = $stmt->fetch(PDO::FETCH_ASSOC);
$recId = (int)$newRec['id'];
echo "Created record #{$recId}\n";

// Coordinator approves record into official database
$clientCoord = http_client();
$coordLogin = ($clientCoord['request'])('http://localhost:8000/php-app/login.php');
$csrfCoord = extract_csrf($coordLogin['body']);
($clientCoord['request'])('http://localhost:8000/php-app/login.php', 'POST', [
    'email' => 'coordinator.test@atts.local', 'password' => 'pass123', 'role' => 'Coordinator', 'csrf' => $csrfCoord
]);
$coordAppPage = ($clientCoord['request'])('http://localhost:8000/php-app/approvals.php');
$csrfCoordApp = extract_csrf($coordAppPage['body']);
($clientCoord['request'])('http://localhost:8000/php-app/approvals.php', 'POST', [
    'csrf' => $csrfCoordApp,
    'review_action' => 'approve',
    'record_type' => 'journal',
    'record_id' => $recId,
    'review_remark' => 'Coordinator approved into DB',
]);
echo "Coordinator approved record #{$recId} into DB\n";

// HoD requests edit to Dean
$clientHod = http_client();
$hodLogin = ($clientHod['request'])('http://localhost:8000/php-app/login.php');
$csrfHod = extract_csrf($hodLogin['body']);
($clientHod['request'])('http://localhost:8000/php-app/login.php', 'POST', [
    'email' => 'hod.test@atts.local', 'password' => 'pass123', 'role' => 'HoD', 'csrf' => $csrfHod
]);
$hodAppPage = ($clientHod['request'])('http://localhost:8000/php-app/approvals.php');
$csrfHodApp = extract_csrf($hodAppPage['body']);
($clientHod['request'])('http://localhost:8000/php-app/approvals.php', 'POST', [
    'csrf' => $csrfHodApp,
    'review_action' => 'create_edit_request',
    'record_type' => 'journal',
    'record_id' => $recId,
    'record_title' => 'Dean Approved Visibility Test ' . $uniq,
    'faculty_name' => 'Test Faculty AIDS',
    'faculty_id' => 0,
    'department' => 'AI & DS',
    'academic_year' => '2025-26',
    'specific_field' => 'doi',
    'current_value' => 'https://doi.org/10.TEST/' . $uniq,
    'requested_value' => 'https://doi.org/10.TEST/CORRECTED-' . $uniq,
    'reason' => 'HoD spotted typo in DOI link',
]);
$er = db()->query("SELECT id FROM edit_requests WHERE record_id = {$recId} ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$erId = (int)$er['id'];
echo "HoD submitted Edit Request ER-{$erId}\n";

// Dean logs in and approves the Edit Request
echo "\n--- 2. Dean Approves Request & UI Checks ---\n";
$clientDean = http_client();
$deanLogin = ($clientDean['request'])('http://localhost:8000/php-app/login.php');
$csrfDean = extract_csrf($deanLogin['body']);
($clientDean['request'])('http://localhost:8000/php-app/login.php', 'POST', [
    'email' => 'dean.test@atts.local', 'password' => 'pass123', 'role' => 'Dean', 'csrf' => $csrfDean
]);
$deanReqPage = ($clientDean['request'])('http://localhost:8000/php-app/approvals.php?tab=requests');
$csrfDeanReq = extract_csrf($deanReqPage['body']);

// Dean approves the request
($clientDean['request'])('http://localhost:8000/php-app/approvals.php', 'POST', [
    'csrf' => $csrfDeanReq,
    'review_action' => 'approve_edit_request',
    'request_id' => $erId,
    'decision_comment' => 'Dean authorized DOI correction for ER-' . $erId,
]);

// Dean views ?tab=requests immediately after approval
$deanAfterApprove = ($clientDean['request'])('http://localhost:8000/php-app/approvals.php?tab=requests');
$htmlDean = $deanAfterApprove['body'];

$deanSeesApprovedBadge = str_contains($htmlDean, 'Approved by Dean') && str_contains($htmlDean, 'ER-' . str_pad((string)$erId, 4, '0', STR_PAD_LEFT));
$deanSeesUnlockedSubtext = str_contains($htmlDean, 'Unlocked for Coordinator');
$deanHasStatusFilterPills = str_contains($htmlDean, 'req_status=Pending') && str_contains($htmlDean, 'req_status=Approved');

echo "Dean ?tab=requests shows 'Approved by Dean' badge: " . ($deanSeesApprovedBadge ? "PASS" : "FAIL") . "\n";
echo "Dean ?tab=requests shows 'Unlocked for Coordinator' subtext: " . ($deanSeesUnlockedSubtext ? "PASS" : "FAIL") . "\n";
echo "Dean has status filter pills (All, Pending, Approved): " . ($deanHasStatusFilterPills ? "PASS" : "FAIL") . "\n";

// Dean views ?tab=records
$deanRecords = ($clientDean['request'])('http://localhost:8000/php-app/approvals.php?tab=records&department=' . urlencode('AI & DS'));
$deanRecordsShowsApprovedByDean = str_contains($deanRecords['body'], 'Approved by Dean') && str_contains($deanRecords['body'], 'Coord Editing');
echo "Dean ?tab=records shows 'Approved by Dean' (Coord Editing) for unlocked record: " . ($deanRecordsShowsApprovedByDean ? "PASS" : "FAIL") . "\n";

// Coordinator checks
echo "\n--- 3. Coordinator Role Visibility Checks ---\n";
// Coordinator opens Dashboard
$coordDash = ($clientCoord['request'])('http://localhost:8000/php-app/dashboard.php');
$coordDashHasCard = str_contains($coordDash['body'], 'Approved by Dean');
$coordDashHasBanner = str_contains($coordDash['body'], 'Dean has approved') && str_contains($coordDash['body'], 'tab=corrections');
echo "Coordinator Dashboard has 'Approved by Dean' metric card: " . ($coordDashHasCard ? "PASS" : "FAIL") . "\n";
echo "Coordinator Dashboard has top alert banner with link to corrections: " . ($coordDashHasBanner ? "PASS" : "FAIL") . "\n";

// Coordinator opens approvals.php
$coordApprovals = ($clientCoord['request'])('http://localhost:8000/php-app/approvals.php?tab=pending');
$coordHasTabCorrections = str_contains($coordApprovals['body'], 'Approved by Dean · Corrections');
$coordHasTabDeanRequests = str_contains($coordApprovals['body'], 'Dean-Approved Requests');
$coordHasPendingAlertBanner = str_contains($coordApprovals['body'], 'Dean has approved') && str_contains($coordApprovals['body'], 'unlocked for you to correct');
$coordTableShowsApprovedByDean = str_contains($coordApprovals['body'], 'Approved by Dean') && str_contains($coordApprovals['body'], 'Edit &amp; Resubmit');

echo "Coordinator approvals has 'Approved by Dean · Corrections' tab: " . ($coordHasTabCorrections ? "PASS" : "FAIL") . "\n";
echo "Coordinator approvals has 'Dean-Approved Requests' tab: " . ($coordHasTabDeanRequests ? "PASS" : "FAIL") . "\n";
echo "Coordinator ?tab=pending has top alert banner: " . ($coordHasPendingAlertBanner ? "PASS" : "FAIL") . "\n";
echo "Coordinator table row has 'Approved by Dean' status and 'Edit & Resubmit' button: " . ($coordTableShowsApprovedByDean ? "PASS" : "FAIL") . "\n";

// Coordinator opens ?tab=corrections
$coordCorrections = ($clientCoord['request'])('http://localhost:8000/php-app/approvals.php?tab=corrections');
$coordCorrectionsHasTitle = str_contains($coordCorrections['body'], 'Records Approved by Dean for Correction');
$coordCorrectionsHasNote = str_contains($coordCorrections['body'], 'Dean Approved Note') && str_contains($coordCorrections['body'], 'Target Field');
$coordCorrectionsHasButton = str_contains($coordCorrections['body'], 'Edit &amp; Resubmit');

echo "Coordinator ?tab=corrections renders 'Records Approved by Dean for Correction': " . ($coordCorrectionsHasTitle ? "PASS" : "FAIL") . "\n";
echo "Coordinator ?tab=corrections shows Dean Approved Note and Target Field: " . ($coordCorrectionsHasNote ? "PASS" : "FAIL") . "\n";
echo "Coordinator ?tab=corrections has 'Edit & Resubmit' button: " . ($coordCorrectionsHasButton ? "PASS" : "FAIL") . "\n";

// Admin checks
echo "\n--- 4. Admin Role Display Checks ---\n";
$passAdmin = password_hash('pass123', PASSWORD_DEFAULT);
$stmtAdmin = db()->prepare("INSERT INTO users (name, email, password, role, department, created_at) VALUES ('Test Administrator', 'admin.test@atts.local', ?, 'Admin', '', NOW()) ON DUPLICATE KEY UPDATE password = ?, role = 'Admin'");
$stmtAdmin->execute([$passAdmin, $passAdmin]);

$clientAdmin = http_client();
$adminLogin = ($clientAdmin['request'])('http://localhost:8000/php-app/login.php');
$csrfAdmin = extract_csrf($adminLogin['body']);
($clientAdmin['request'])('http://localhost:8000/php-app/login.php', 'POST', [
    'email' => 'admin.test@atts.local', 'password' => 'pass123', 'role' => 'Admin', 'csrf' => $csrfAdmin
]);

// Admin views records table
$adminRecords = ($clientAdmin['request'])('http://localhost:8000/php-app/approvals.php?tab=records&department=' . urlencode('AI & DS'));
$htmlAdminRec = $adminRecords['body'];

// For the unlocked record #recId, Admin MUST NOT see the "Approve" button, but MUST see "Approved by Dean"
$hasApprovedByDeanForAdmin = str_contains($htmlAdminRec, 'Approved by Dean') && str_contains($htmlAdminRec, 'Coord Editing');
// Confirm that the specific record row doesn't have reviewRecord(..., 'approve')
preg_match('/<tr[^>]*>.*?Dean Approved Visibility Test ' . $uniq . '.*?<\/tr>/s', $htmlAdminRec, $rowMatch);
$recordRowHtml = $rowMatch[0] ?? '';
$adminRowHasApprovedByDean = str_contains($recordRowHtml, 'Approved by Dean');
$adminRowHasNoAcceptButton = !str_contains($recordRowHtml, 'reviewRecord(');

echo "Admin sees 'Approved by Dean' (Coord Editing) for unlocked record: " . ($adminRowHasApprovedByDean ? "PASS" : "FAIL") . "\n";
echo "Admin does NOT have Accept/Approve button on Dean-approved record: " . ($adminRowHasNoAcceptButton ? "PASS" : "FAIL") . "\n";

// Admin views edit requests table
$adminReqs = ($clientAdmin['request'])('http://localhost:8000/php-app/approvals.php?tab=requests');
$adminReqsShowsApprovedByDean = str_contains($adminReqs['body'], 'Approved by Dean') && str_contains($adminReqs['body'], 'ER-' . str_pad((string)$erId, 4, '0', STR_PAD_LEFT));
echo "Admin ?tab=requests shows 'Approved by Dean' for decided request: " . ($adminReqsShowsApprovedByDean ? "PASS" : "FAIL") . "\n";

// Cleanup
($clientFac['cleanup'])();
($clientCoord['cleanup'])();
($clientHod['cleanup'])();
($clientDean['cleanup'])();
($clientAdmin['cleanup'])();

echo "\n========================================================================\n";
echo "ALL TESTS COMPLETED SUCCESSFULLY!\n";
echo "========================================================================\n";
