<?php
/**
 * Real HTTP Session & UI Verification Test
 * Tests login, session persistence, HTML content, role isolation, and UI elements via http://localhost:8000
 */

function http_client() {
    $cookieFile = sys_get_temp_dir() . '/cookie_' . uniqid() . '.txt';
    return [
        'cookieFile' => $cookieFile,
        'request' => function(string $url, string $method = 'GET', $data = null) use ($cookieFile) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
            curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
            curl_setopt($ch, CURLOPT_HEADER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);

            if ($method === 'POST') {
                curl_setopt($ch, CURLOPT_POST, true);
                if (is_array($data)) {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
                } else {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
                }
            }

            $response = curl_exec($ch);
            $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $effectiveUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
            unset($ch);

            $header = substr($response, 0, $headerSize);
            $body = substr($response, $headerSize);

            return [
                'code' => $httpCode,
                'header' => $header,
                'body' => $body,
                'url' => $effectiveUrl,
            ];
        },
        'cleanup' => function() use ($cookieFile) {
            @unlink($cookieFile);
        }
    ];
}

function extract_csrf(string $html): string {
    if (preg_match('/name=["\']csrf["\']\s+value=["\']([^"\']+)["\']/i', $html, $m)) {
        return $m[1];
    }
    return '';
}

echo "========================================================================\n";
echo "HTTP REAL-BROWSER/SESSION INTEGRATION TESTS on http://localhost:8000\n";
echo "========================================================================\n\n";

// 1. HoD Login & UI Inspection
echo "--- 1. Testing HoD Login & Review Records UI ---\n";
$clientHod = http_client();
$loginPage = ($clientHod['request'])('http://localhost:8000/php-app/login.php');
$csrf = extract_csrf($loginPage['body']);

// Submit login
$loginRes = ($clientHod['request'])(
    'http://localhost:8000/php-app/login.php',
    'POST',
    ['email' => 'hod.test@atts.local', 'password' => 'pass123', 'role' => 'HoD', 'csrf' => $csrf]
);

$approvalsPage = ($clientHod['request'])('http://localhost:8000/php-app/approvals.php');
$bodyHod = $approvalsPage['body'];

$hasReviewRecordsNav = str_contains($bodyHod, 'Review Records');
$hasNoDirectApprove = !preg_match('/<button[^>]*>Approve<\/button>/i', $bodyHod);
$hasNoDirectReject  = !preg_match('/<button[^>]*>Reject<\/button>/i', $bodyHod);
$hasRequestedBadge  = str_contains($bodyHod, 'Requested to Dean') || str_contains($bodyHod, 'Request Edit to Dean');
$hasEditModal       = str_contains($bodyHod, 'Submit Edit Request to Dean');

echo "HoD Review Records nav present: " . ($hasReviewRecordsNav ? "PASS" : "FAIL") . "\n";
echo "HoD has NO direct Approve button: " . ($hasNoDirectApprove ? "PASS" : "FAIL") . "\n";
echo "HoD has NO direct Reject button: " . ($hasNoDirectReject ? "PASS" : "FAIL") . "\n";
echo "HoD Edit Request button/badge present: " . ($hasRequestedBadge ? "PASS" : "FAIL") . "\n";
echo "HoD Edit Request Modal dialog present: " . ($hasEditModal ? "PASS" : "FAIL") . "\n";

// 2. Announcements HoD Scoping UI Inspection
echo "\n--- 2. Testing Announcements Department HoD Scoping UI ---\n";
$clientFac = http_client();
$facLogin = ($clientFac['request'])('http://localhost:8000/php-app/login.php');
$csrfFac = extract_csrf($facLogin['body']);
($clientFac['request'])(
    'http://localhost:8000/php-app/login.php',
    'POST',
    ['email' => 'faculty.test@atts.local', 'password' => 'pass123', 'role' => 'Faculty', 'csrf' => $csrfFac]
);

$annPage = ($clientFac['request'])('http://localhost:8000/php-app/announcements.php');
$bodyAnn = $annPage['body'];

$hasContactHodBtn = str_contains($bodyAnn, 'Contact Department HoD');
$hasContactHodDlg = str_contains($bodyAnn, 'contactHodDlg');
$hasAutoDept      = str_contains($bodyAnn, 'AI &amp; DS') || str_contains($bodyAnn, 'AI & DS');

echo "Faculty sees 'Contact Department HoD' button: " . ($hasContactHodBtn ? "PASS" : "FAIL") . "\n";
echo "Contact HoD Modal dialog present: " . ($hasContactHodDlg ? "PASS" : "FAIL") . "\n";
echo "Department auto-populated strictly: " . ($hasAutoDept ? "PASS" : "FAIL") . "\n";

// 3. Dean UI Inspection
echo "\n--- 3. Testing Dean UI ---\n";
$clientDean = http_client();
$deanLogin = ($clientDean['request'])('http://localhost:8000/php-app/login.php');
$csrfDean = extract_csrf($deanLogin['body']);
($clientDean['request'])(
    'http://localhost:8000/php-app/login.php',
    'POST',
    ['email' => 'dean.test@atts.local', 'password' => 'pass123', 'role' => 'Dean', 'csrf' => $csrfDean]
);

$deanApprovals = ($clientDean['request'])('http://localhost:8000/php-app/approvals.php?tab=requests');
$bodyDean = $deanApprovals['body'];

$hasDeanReqTitle = str_contains($bodyDean, 'Edit Requests') || str_contains($bodyDean, 'Decision History');
$hasDeanAction = str_contains($bodyDean, 'Approve Request') || str_contains($bodyDean, 'Reject') || str_contains($bodyDean, 'No edit requests found') || str_contains($bodyDean, 'No pending edit requests');

echo "Dean Edit Requests tab loaded: " . ($hasDeanReqTitle ? "PASS" : "FAIL") . "\n";
echo "Dean decision controls present: " . ($hasDeanAction ? "PASS" : "FAIL") . "\n";

// 4. Coordinator UI Inspection
echo "\n--- 4. Testing Coordinator UI ---\n";
$clientCoord = http_client();
$coordLogin = ($clientCoord['request'])('http://localhost:8000/php-app/login.php');
$csrfCoord = extract_csrf($coordLogin['body']);
($clientCoord['request'])(
    'http://localhost:8000/php-app/login.php',
    'POST',
    ['email' => 'coordinator.test@atts.local', 'password' => 'pass123', 'role' => 'Coordinator', 'csrf' => $csrfCoord]
);

$coordApprovals = ($clientCoord['request'])('http://localhost:8000/php-app/approvals.php?tab=corrections');
$bodyCoord = $coordApprovals['body'];

$hasAuthCorrectionsTab = str_contains($bodyCoord, 'Records Approved by Dean for Correction') || str_contains($bodyCoord, 'Dean-Authorized Corrections') || str_contains($bodyCoord, 'Authorized Corrections');

echo "Coordinator Authorized Corrections tab loaded: " . ($hasAuthCorrectionsTab ? "PASS" : "FAIL") . "\n";

// 5. Complete Live HTTP Round-Trip Workflow
echo "\n--- 5. Testing Complete Live HTTP Round-Trip (All Roles) ---\n";

// Step 5.1: Faculty uploads record via HTTP POST
echo "Step 5.1: Faculty HTTP upload...\n";
$facUploadPage = ($clientFac['request'])('http://localhost:8000/php-app/upload.php?type=journal');
$csrfFacUp = extract_csrf($facUploadPage['body']);

$newTitle = 'Full Live HTTP Round-Trip Test ' . time();
$uploadData = [
    'csrf'              => $csrfFacUp,
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
    'doi'               => 'https://doi.org/10.HTTP/LIVE-OLD',
    'journal_link'      => 'https://ieee.org',
    'document_link'     => 'https://ieee.org/doc',
];
$uploadRes = ($clientFac['request'])('http://localhost:8000/php-app/upload.php', 'POST', $uploadData);

// Fetch record ID from DB
require_once __DIR__ . '/../php-app/inc/auth.php';
require_once __DIR__ . '/../php-app/models/Record.php';
$pdo = db();
$stmt = $pdo->prepare("SELECT id, status FROM journal_publications WHERE paper_title = ? ORDER BY id DESC LIMIT 1");
$stmt->execute([$newTitle]);
$createdRow = $stmt->fetch(PDO::FETCH_ASSOC);
$httpRecId = (int)($createdRow['id'] ?? 0);
echo "Faculty uploaded Record #$httpRecId (Status: {$createdRow['status']})\n";

// Step 5.2: Coordinator approves via HTTP POST
echo "Step 5.2: Coordinator HTTP approve...\n";
$coordPendingPage = ($clientCoord['request'])('http://localhost:8000/php-app/approvals.php?tab=pending');
$csrfCoordApp = extract_csrf($coordPendingPage['body']);
($clientCoord['request'])(
    'http://localhost:8000/php-app/approvals.php',
    'POST',
    [
        'csrf'          => $csrfCoordApp,
        'record_type'   => 'journal',
        'record_id'     => $httpRecId,
        'review_action' => 'approve',
        'review_remark' => 'HTTP Coordinator initial approval',
    ]
);
$recAfterApp = record_find('journal', $httpRecId);
echo "Coordinator approved: Record #$httpRecId status is now '{$recAfterApp['status']}'\n";

// Step 5.3: HoD creates Edit Request via HTTP POST
echo "Step 5.3: HoD HTTP Edit Request...\n";
$hodRecordsPage = ($clientHod['request'])('http://localhost:8000/php-app/approvals.php?tab=records');
$csrfHodER = extract_csrf($hodRecordsPage['body']);
($clientHod['request'])(
    'http://localhost:8000/php-app/approvals.php',
    'POST',
    [
        'csrf'            => $csrfHodER,
        'review_action'   => 'create_edit_request',
        'record_type'     => 'journal',
        'record_id'       => $httpRecId,
        'from_tab'        => 'records',
        'reason'          => 'DOI requires update per publisher correction notice.',
        'specific_field'  => 'doi',
        'current_value'   => 'https://doi.org/10.HTTP/LIVE-OLD',
        'requested_value' => 'https://doi.org/10.HTTP/LIVE-NEW',
    ]
);
$recAfterER = record_find('journal', $httpRecId);
$erHttpRow = $pdo->query("SELECT id, status FROM edit_requests WHERE record_id = $httpRecId AND record_type = 'journal' ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$httpERId = (int)$erHttpRow['id'];
echo "HoD submitted Edit Request ER-#$httpERId: Record status is now '{$recAfterER['status']}'\n";

// Verify HoD UI shows "Requested to Dean" badge
$hodPageAfterER = ($clientHod['request'])('http://localhost:8000/php-app/approvals.php?tab=records');
$badgeRendered = str_contains($hodPageAfterER['body'], 'Requested to Dean');
echo "HoD sees 'Requested to Dean' badge in table: " . ($badgeRendered ? "PASS" : "FAIL") . "\n";

// Step 5.4: Dean approves Edit Request via HTTP POST
echo "Step 5.4: Dean HTTP Approve Edit Request...\n";
$deanReqPage = ($clientDean['request'])('http://localhost:8000/php-app/approvals.php?tab=requests');
$csrfDeanApp = extract_csrf($deanReqPage['body']);
($clientDean['request'])(
    'http://localhost:8000/php-app/approvals.php',
    'POST',
    [
        'csrf'             => $csrfDeanApp,
        'review_action'    => 'approve_edit_request',
        'request_id'       => $httpERId,
        'decision_comment' => 'Approved for correction by Coordinator.',
    ]
);
$recAfterDeanApp = record_find('journal', $httpRecId);
echo "Dean approved: Record status is now '{$recAfterDeanApp['status']}'\n";

// Step 5.5: Coordinator updates DOI via HTTP POST to upload.php
echo "Step 5.5: Coordinator HTTP Resubmit Correction...\n";
$coordEditPage = ($clientCoord['request'])("http://localhost:8000/php-app/upload.php?type=journal&edit_id=$httpRecId");
$csrfCoordEdit = extract_csrf($coordEditPage['body']);

$correctData = [
    'csrf'              => $csrfCoordEdit,
    'record_type'       => 'journal',
    'edit_id'           => $httpRecId,
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
    'doi'               => 'https://doi.org/10.HTTP/LIVE-NEW',
    'journal_link'      => 'https://ieee.org',
    'document_link'     => 'https://ieee.org/doc',
];
($clientCoord['request'])('http://localhost:8000/php-app/upload.php', 'POST', $correctData);
$recAfterResubmit = record_find('journal', $httpRecId);
echo "Coordinator corrected: New DOI is '{$recAfterResubmit['doi']}', Status is '{$recAfterResubmit['status']}'\n";

// Step 5.6: HoD Acknowledges Review via HTTP POST
echo "Step 5.6: HoD HTTP Acknowledge Review...\n";
$hodReviewPage = ($clientHod['request'])('http://localhost:8000/php-app/approvals.php?tab=records');
$csrfHodAck = extract_csrf($hodReviewPage['body']);
($clientHod['request'])(
    'http://localhost:8000/php-app/approvals.php',
    'POST',
    [
        'csrf'          => $csrfHodAck,
        'review_action' => 'acknowledge_review',
        'record_type'   => 'journal',
        'record_id'     => $httpRecId,
    ]
);
$recFinal = record_find('journal', $httpRecId);
echo "HoD acknowledged: Final status is '{$recFinal['status']}', Remark: '{$recFinal['review_remark']}'\n";

$step5Pass = (
    $httpRecId > 0 &&
    $recAfterApp['status'] === 'Approved' &&
    $recAfterER['status'] === 'Edit Requested' &&
    $badgeRendered &&
    $recAfterDeanApp['status'] === 'Unlocked for Edit' &&
    $recAfterResubmit['doi'] === 'https://doi.org/10.HTTP/LIVE-NEW' &&
    $recAfterResubmit['status'] === 'Resubmitted' &&
    $recFinal['status'] === 'Approved'
);
echo "\nLIVE HTTP END-TO-END WORKFLOW RESULT: " . ($step5Pass ? "ALL PASS" : "FAIL") . "\n";

($clientHod['cleanup'])();
($clientFac['cleanup'])();
($clientDean['cleanup'])();
($clientCoord['cleanup'])();

echo "\nHTTP Integration Tests Finished.\n";
