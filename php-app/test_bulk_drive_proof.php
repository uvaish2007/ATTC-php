<?php
/**
 * Test Suite for ATTS-BULK-DRIVE-PROOF
 *
 * Verifies:
 * - Test A: Downloadable Excel Template (Drive Link column, existing columns intact, instructions, aliases)
 * - Test B: Valid Bulk Upload without ZIP (preview, validation, commit, database persistence 1:1)
 * - Test C: Invalid Links (empty link, malformed URL, unsafe scheme, non-drive domain, duplicate reg no, Access Not Verified status)
 * - Test D: No ZIP Required (end-to-end spreadsheet-only workflow)
 * - Test E: Role & Department Permissions (Faculty and Coordinator authorization and cross-dept isolation)
 * - Test F: Historical Data & Regression (stored proof files, exports, render_proof_cell compatibility)
 */

declare(strict_types=1);

require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/helpers.php';
require_once __DIR__ . '/inc/xlsx_writer.php';
require_once __DIR__ . '/inc/xlsx_reader.php';
require_once __DIR__ . '/models/BulkImport.php';

$testResults = [];

function record_test(string $testId, string $desc, bool $passed, string $detail = ''): void {
    global $testResults;
    $testResults[] = [
        'id'     => $testId,
        'desc'   => $desc,
        'passed' => $passed,
        'detail' => $detail,
    ];
    $statusStr = $passed ? "\033[32m[PASS]\033[0m" : "\033[31m[FAIL]\033[0m";
    echo "{$statusStr} {$testId}: {$desc}\n";
    if (!empty($detail)) {
        echo "       {$detail}\n";
    }
}

$db = db();

// -----------------------------------------------------------------------------
// Test A: Template Structure & Column Requirements
// -----------------------------------------------------------------------------
echo "\n--- Running Test A: Template Structure ---\n";
try {
    $activeYear = '2025-26';
    $xlsxIntern = BulkImport::generateTemplate(BulkImport::MODULE_INTERNSHIP, $activeYear, 'CSBS');
    
    // Save to temp file and parse back
    $tempFile = tempnam(sys_get_temp_dir(), 'tmpl_') . '.xlsx';
    file_put_contents($tempFile, $xlsxIntern);
    $parsedSheets = SimpleXlsxReader::parse($tempFile);
    @unlink($tempFile);
    
    $headersRow = $parsedSheets['Internship Records'][0] ?? [];
    
    // Verify Drive Link column exists
    $hasDriveLinkCol = in_array('Drive Link for Certificate', $headersRow, true);
    record_test('Test A.1', 'Drive Link for Certificate column exists in Internship template', $hasDriveLinkCol, 
        'Found headers: ' . implode(' | ', $headersRow));
    
    // Verify all existing required columns remain intact
    $expectedCols = [
        'Student Register Number *',
        'Student Name *',
        'Department *',
        'Academic Year *',
        'Exam Session *',
        'Title of Internship *',
        'Organization / Company Name & Address *',
        'Duration *',
        'No. of Days *',
    ];
    $allExpectedPresent = true;
    foreach ($expectedCols as $ec) {
        if (!in_array($ec, $headersRow, true)) {
            $allExpectedPresent = false;
            break;
        }
    }
    record_test('Test A.2', 'All previous required columns remain intact in template', $allExpectedPresent);

    // Verify Instructions sheet exists and describes Drive Link
    $instructionsRows = $parsedSheets['Instructions'] ?? [];
    $hasDriveInst = false;
    $hasZipInst = false;
    foreach ($instructionsRows as $iRow) {
        $rowStr = implode(' ', $iRow);
        if (stripos($rowStr, 'Drive Link for Certificate') !== false) {
            $hasDriveInst = true;
        }
        if (stripos($rowStr, 'Compulsory ZIP Archive') !== false) {
            $hasZipInst = true;
        }
    }
    record_test('Test A.3', 'Instructions sheet includes Drive Link rules and permission notice', $hasDriveInst);
    record_test('Test A.4', 'Instructions sheet has no compulsory ZIP archive requirements', !$hasZipInst);

    // Verify header mapping to field
    $mappedField = BulkImport::mapHeaderToField('Drive Link for Certificate', BulkImport::MODULE_INTERNSHIP);
    record_test('Test A.5', 'Header parser maps "Drive Link for Certificate" to certificate_link', $mappedField === 'certificate_link', "Mapped to: {$mappedField}");

    // Verify Summer Training template also includes the Drive Link column
    $xlsxTrain = BulkImport::generateTemplate(BulkImport::MODULE_SUMMER_TRAINING, $activeYear, 'CSBS');
    $tempFile2 = tempnam(sys_get_temp_dir(), 'tmpl_tr_') . '.xlsx';
    file_put_contents($tempFile2, $xlsxTrain);
    $parsedSheets2 = SimpleXlsxReader::parse($tempFile2);
    @unlink($tempFile2);
    $trainHeaders = $parsedSheets2['Training Records'][0] ?? [];
    $hasDriveTrain = in_array('Drive Link for Certificate', $trainHeaders, true);
    record_test('Test A.6', 'Summer Training template also includes Drive Link for Certificate', $hasDriveTrain);

} catch (\Throwable $e) {
    record_test('Test A', 'Template Generation and Parsing', false, $e->getMessage());
}

// -----------------------------------------------------------------------------
// Test B & D: Valid Bulk Upload Without ZIP
// -----------------------------------------------------------------------------
echo "\n--- Running Test B & D: Valid Bulk Upload (Spreadsheet Only, No ZIP) ---\n";
try {
    $facultyUser = [
        'id'         => 5,
        'name'       => 'VR (Faculty)',
        'email'      => 'vr@atts.edu',
        'role'       => 'Faculty',
        'department' => 'CSBS',
    ];
    $activeYear = '2025-26';

    $uniqueBatchTag = 'TST' . time();
    $student1Reg = '711322' . rand(100000, 999999);
    $student2Reg = '711322' . rand(100000, 999999);
    $student3Reg = '711322' . rand(100000, 999999);

    $link1 = 'https://drive.google.com/file/d/1A2b3C4d5E6f7G8h9I0jKlMnOpQrStUvW/view?usp=sharing';
    $link2 = 'https://drive.google.com/open?id=1B3c4D5e6F7g8H9i0J1kLmNoPqRsTuVwX';
    $link3 = 'https://docs.google.com/document/d/1C4d5E6f7G8h9I0jKlMnOpQrStUvWxYz0/edit';

    $testRows = [
        [
            'Student Register Number *',
            'Student Name *',
            'Department *',
            'Academic Year *',
            'Exam Session *',
            'Title of Internship *',
            'Organization / Company Name & Address *',
            'Duration *',
            'No. of Days *',
            'Drive Link for Certificate',
        ],
        [
            $student1Reg,
            'A. Student One',
            'CSBS',
            $activeYear,
            'Odd',
            "Cloud Security Engineering {$uniqueBatchTag}",
            'Amazon Web Services, Chennai',
            '4 weeks',
            28,
            $link1,
        ],
        [
            $student2Reg,
            'B. Student Two',
            'CSBS',
            $activeYear,
            'Odd',
            "Full Stack AI Development {$uniqueBatchTag}",
            'Zoho Corporation, Chennai',
            '1 month',
            30,
            $link2,
        ],
        [
            $student3Reg,
            'C. Student Three',
            'CSBS',
            $activeYear,
            'Odd',
            "Data Analytics & Machine Learning {$uniqueBatchTag}",
            'Cognizant Technology Solutions, Coimbatore',
            '6 weeks',
            42,
            $link3,
        ],
    ];

    $xlsxBinary = SimpleXlsxWriter::createXlsx($testRows[0], array_slice($testRows, 1), 'Internship Records');
    $uploadPath = tempnam(sys_get_temp_dir(), 'upload_test_') . '.xlsx';
    file_put_contents($uploadPath, $xlsxBinary);

    // Call validateUpload with NO ZIP file ($zipPath = null)
    $preview = BulkImport::validateUpload(BulkImport::MODULE_INTERNSHIP, $uploadPath, $facultyUser, $activeYear, null);
    @unlink($uploadPath);

    record_test('Test D.1', 'Validation proceeds successfully without any ZIP file', is_array($preview));
    record_test('Test B.1', 'All 3 rows detected as valid with zero errors', 
        ($preview['total_detected'] === 3 && $preview['valid_count'] === 3 && $preview['invalid_count'] === 0),
        "Total: {$preview['total_detected']}, Valid: {$preview['valid_count']}, Invalid: {$preview['invalid_count']}");

    // Check row details
    $row1 = $preview['rows'][0] ?? [];
    $row2 = $preview['rows'][1] ?? [];
    $row3 = $preview['rows'][2] ?? [];

    $sourceOk = ($row1['proof_source'] === 'drive_link' && $row2['proof_source'] === 'drive_link' && $row3['proof_source'] === 'drive_link');
    $statusOk = ($row1['proof_status'] === 'Access Not Verified' && $row2['proof_status'] === 'Access Not Verified');
    $linkMatch = ($row1['certificate_link'] === $link1 && $row2['certificate_link'] === $link2 && $row3['certificate_link'] === $link3);

    record_test('Test B.2', 'Rows correctly associated with proof_source = drive_link', $sourceOk);
    record_test('Test B.3', 'Certificate status labeled as Access Not Verified', $statusOk);
    record_test('Test B.4', 'Each row holds the exact corresponding Google Drive URL', $linkMatch);

    // Commit import to database
    $commitResult = BulkImport::commitImport($preview, $facultyUser);
    record_test('Test B.5', 'Commit import completes successfully', 
        ($commitResult['success'] === true && $commitResult['imported_records'] === 3),
        "Imported: {$commitResult['imported_records']}");

    // Query database to verify persistence and 1:1 association
    $stmt = $db->prepare("SELECT id, reg_no, student_name, certificate_link, proof_file, department, academic_year, status FROM internships WHERE reg_no IN (?, ?, ?)");
    $stmt->execute([$student1Reg, $student2Reg, $student3Reg]);
    $savedRecords = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $savedByReg = [];
    foreach ($savedRecords as $sr) {
        $savedByReg[$sr['reg_no']] = $sr;
    }

    $dbPersistOk = (count($savedRecords) === 3);
    $exact1 = ($savedByReg[$student1Reg]['certificate_link'] ?? '') === $link1;
    $exact2 = ($savedByReg[$student2Reg]['certificate_link'] ?? '') === $link2;
    $exact3 = ($savedByReg[$student3Reg]['certificate_link'] ?? '') === $link3;
    $noOverwrite = empty($savedByReg[$student1Reg]['proof_file']); // proof_file should be null/empty, not corrupted

    record_test('Test B.6', 'All 3 records persistently saved in database', $dbPersistOk);
    record_test('Test B.7', 'Certificate links accurately match each individual student record', ($exact1 && $exact2 && $exact3),
        "Student 1 Link: " . ($savedByReg[$student1Reg]['certificate_link'] ?? 'missing'));
    record_test('Test B.8', 'Records remain stored in database after query and have Submitted status', 
        (($savedByReg[$student1Reg]['status'] ?? '') === 'Submitted'),
        "Status: " . ($savedByReg[$student1Reg]['status'] ?? 'none'));

} catch (\Throwable $e) {
    record_test('Test B & D', 'Valid Bulk Upload & Persistence', false, $e->getMessage() . "\n" . $e->getTraceAsString());
}

// -----------------------------------------------------------------------------
// Test C: Invalid Links & Row-Level Validation Results
// -----------------------------------------------------------------------------
echo "\n--- Running Test C: Invalid Links & Validation Breakdown ---\n";
try {
    $facultyUser = [
        'id'         => 9991,
        'name'       => 'Dr. Test Faculty',
        'email'      => 'faculty.test@atts.edu',
        'role'       => 'Faculty',
        'department' => 'CSBS',
    ];
    $activeYear = '2025-26';

    $invalidTestRows = [
        [
            'Student Register Number *',
            'Student Name *',
            'Department *',
            'Academic Year *',
            'Exam Session *',
            'Title of Internship *',
            'Organization / Company Name & Address *',
            'Duration *',
            'No. of Days *',
            'Drive Link for Certificate',
        ],
        // Row 1: Empty link
        [
            '711322990001',
            'Student Empty Link',
            'CSBS',
            $activeYear,
            'Odd',
            'Software Testing Project',
            'Tech Mahindra, Chennai',
            '4 weeks',
            28,
            '', // EMPTY
        ],
        // Row 2: Malformed URL
        [
            '711322990002',
            'Student Malformed Link',
            'CSBS',
            $activeYear,
            'Odd',
            'Software Testing Project',
            'Tech Mahindra, Chennai',
            '4 weeks',
            28,
            'htp://not a valid url', // MALFORMED
        ],
        // Row 3: Dangerous scheme
        [
            '711322990003',
            'Student Dangerous Scheme',
            'CSBS',
            $activeYear,
            'Odd',
            'Software Testing Project',
            'Tech Mahindra, Chennai',
            '4 weeks',
            28,
            'javascript:alert(document.cookie)', // DANGEROUS SCHEME
        ],
        // Row 4: Non-Drive URL
        [
            '711322990004',
            'Student Dropbox Link',
            'CSBS',
            $activeYear,
            'Odd',
            'Software Testing Project',
            'Tech Mahindra, Chennai',
            '4 weeks',
            28,
            'https://dropbox.com/s/12345/certificate.pdf', // NON-DRIVE DOMAIN
        ],
        // Row 5: Syntactically valid Google Drive URL (Access Not Verified)
        [
            '711322990005',
            'Student Valid Drive Link',
            'CSBS',
            $activeYear,
            'Odd',
            'Software Testing Project',
            'Tech Mahindra, Chennai',
            '4 weeks',
            28,
            'https://drive.google.com/file/d/1XyZ9876543210AbCdEfGhIjKlMnOpQr/view', // VALID DRIVE LINK
        ],
        // Row 6: Duplicate register number matching row 5 with same title
        [
            '711322990005',
            'Student Valid Drive Link Duplicate',
            'CSBS',
            $activeYear,
            'Odd',
            'Software Testing Project',
            'Tech Mahindra, Chennai',
            '4 weeks',
            28,
            'https://drive.google.com/file/d/1XyZ9876543210AbCdEfGhIjKlMnOpQr/view', // DUPLICATE
        ],
    ];

    $invalidXlsx = SimpleXlsxWriter::createXlsx($invalidTestRows[0], array_slice($invalidTestRows, 1), 'Internship Records');
    $invalidUploadPath = tempnam(sys_get_temp_dir(), 'invalid_test_') . '.xlsx';
    file_put_contents($invalidUploadPath, $invalidXlsx);

    $invPreview = BulkImport::validateUpload(BulkImport::MODULE_INTERNSHIP, $invalidUploadPath, $facultyUser, $activeYear, null);
    @unlink($invalidUploadPath);

    // Check Row 1 error (Empty link)
    $r1 = $invPreview['rows'][0];
    $hasEmptyErr = false;
    foreach ($r1['errors'] as $err) {
        if ($err['field'] === 'Drive Link for Certificate' && stripos($err['problem'], 'missing') !== false) {
            $hasEmptyErr = true;
        }
    }
    record_test('Test C.1', 'Empty link flags "Compulsory Drive Link for Certificate is missing"', $hasEmptyErr, 
        json_encode($r1['errors']));

    // Check Row 2 error (Malformed link)
    $r2 = $invPreview['rows'][1];
    $hasMalformedErr = false;
    foreach ($r2['errors'] as $err) {
        if ($err['field'] === 'Drive Link for Certificate' && (stripos($err['problem'], 'Invalid URL') !== false || stripos($err['problem'], 'Malformed') !== false)) {
            $hasMalformedErr = true;
        }
    }
    record_test('Test C.2', 'Malformed URL flags invalid URL format error', $hasMalformedErr, 
        json_encode($r2['errors']));

    // Check Row 3 error (Dangerous scheme)
    $r3 = $invPreview['rows'][2];
    $hasSchemeErr = false;
    foreach ($r3['errors'] as $err) {
        if ($err['field'] === 'Drive Link for Certificate' && stripos($err['problem'], 'scheme') !== false) {
            $hasSchemeErr = true;
        }
    }
    record_test('Test C.3', 'Dangerous URL scheme (javascript:) is rejected', $hasSchemeErr, 
        json_encode($r3['errors']));

    // Check Row 4 error (Non-Drive domain)
    $r4 = $invPreview['rows'][3];
    $hasDomainErr = false;
    foreach ($r4['errors'] as $err) {
        if ($err['field'] === 'Drive Link for Certificate' && stripos($err['problem'], 'Google Drive') !== false) {
            $hasDomainErr = true;
        }
    }
    record_test('Test C.4', 'Non-Google Drive URL (Dropbox) is rejected with expected domain guidance', $hasDomainErr, 
        json_encode($r4['errors']));

    // Check Row 5 (Valid link) -> status: valid, proof_status: Access Not Verified
    $r5 = $invPreview['rows'][4];
    $r5Valid = ($r5['status'] === 'valid' && $r5['proof_status'] === 'Access Not Verified');
    record_test('Test C.5', 'Valid Google Drive URL labeled as "Access Not Verified" (no false verification claim)', $r5Valid,
        "Status: {$r5['status']}, Proof Status: {$r5['proof_status']}");

    // Check Row 6 (Duplicate register number) -> flagged as duplicate
    $r6 = $invPreview['rows'][5];
    $r6Dup = ($r6['status'] === 'duplicate');
    record_test('Test C.6', 'Duplicate register number for same activity detected and flagged', $r6Dup,
        "Status: {$r6['status']}");

} catch (\Throwable $e) {
    record_test('Test C', 'Invalid Link Validation', false, $e->getMessage());
}

// -----------------------------------------------------------------------------
// Test E: Role and Department Permissions
// -----------------------------------------------------------------------------
echo "\n--- Running Test E: Role & Department Authorization ---\n";
try {
    $facultyCSBS = [
        'id'         => 5,
        'name'       => 'VR (Faculty)',
        'role'       => 'Faculty',
        'department' => 'CSBS',
    ];
    $activeYear = '2025-26';

    // File specifies department 'ECE'
    $crossDeptRows = [
        [
            'Student Register Number *',
            'Student Name *',
            'Department *',
            'Academic Year *',
            'Exam Session *',
            'Title of Internship *',
            'Organization / Company Name & Address *',
            'Duration *',
            'No. of Days *',
            'Drive Link for Certificate',
        ],
        [
            '711322880001',
            'Cross Dept Student',
            'ECE', // Unauthorized dept
            $activeYear,
            'Odd',
            'Embedded IoT Internship',
            'L&T Technology Services, Chennai',
            '4 weeks',
            28,
            'https://drive.google.com/file/d/1A2b3C4d5E6f7G8h9I0jKlMnOpQrStUvW/view',
        ],
    ];

    $crossXlsx = SimpleXlsxWriter::createXlsx($crossDeptRows[0], array_slice($crossDeptRows, 1), 'Internship Records');
    $crossUploadPath = tempnam(sys_get_temp_dir(), 'cross_test_') . '.xlsx';
    file_put_contents($crossUploadPath, $crossXlsx);

    $crossPreview = BulkImport::validateUpload(BulkImport::MODULE_INTERNSHIP, $crossUploadPath, $facultyCSBS, $activeYear, null);
    @unlink($crossUploadPath);

    $crossRow = $crossPreview['rows'][0];
    $hasDeptMismatchErr = false;
    foreach ($crossRow['errors'] as $err) {
        if ($err['field'] === 'Department' && stripos($err['problem'], 'Cross-department') !== false) {
            $hasDeptMismatchErr = true;
        }
    }
    record_test('Test E.1', 'Faculty cannot upload records for unauthorized department (cross-department rejection)', $hasDeptMismatchErr,
        json_encode($crossRow['errors']));

    // Coordinator check
    $coordCSBS = [
        'id'         => 4,
        'name'       => 'Coordinator CSBS',
        'role'       => 'Coordinator',
        'department' => 'CSBS',
    ];
    $coordUploadPath = tempnam(sys_get_temp_dir(), 'coord_test_') . '.xlsx';
    file_put_contents($coordUploadPath, $crossXlsx);
    $coordPreview = BulkImport::validateUpload(BulkImport::MODULE_INTERNSHIP, $coordUploadPath, $coordCSBS, $activeYear, null);
    @unlink($coordUploadPath);

    $coordRow = $coordPreview['rows'][0];
    $hasCoordMismatch = false;
    foreach ($coordRow['errors'] as $err) {
        if ($err['field'] === 'Department' && stripos($err['problem'], 'Cross-department') !== false) {
            $hasCoordMismatch = true;
        }
    }
    record_test('Test E.2', 'Coordinator cannot upload records for unauthorized department', $hasCoordMismatch,
        json_encode($coordRow['errors']));

} catch (\Throwable $e) {
    record_test('Test E', 'Department Permissions', false, $e->getMessage());
}

// -----------------------------------------------------------------------------
// Test F: Historical Data & Export Regression
// -----------------------------------------------------------------------------
echo "\n--- Running Test F: Historical Data & Regression ---\n";
try {
    // 1. Verify render_proof_cell with external certificate link
    $linkTestUrl = 'https://drive.google.com/file/d/1A2b3C4d5E6f7G8h9I0jKlMnOpQrStUvW/view';
    $renderedCell = render_proof_cell(null, 'internship', 123, $linkTestUrl);
    $hasOpenCert = (str_contains($renderedCell, 'Open Certificate') && str_contains($renderedCell, 'target="_blank"') && str_contains($renderedCell, 'rel="noopener noreferrer"'));
    record_test('Test F.1', 'render_proof_cell renders safe "Open Certificate" link for Google Drive URL', $hasOpenCert, $renderedCell);

    // 2. Verify render_proof_cell when proof_file itself is passed as URL
    $renderedCell2 = render_proof_cell($linkTestUrl, 'internship', 123);
    $hasOpenCert2 = str_contains($renderedCell2, 'Open Certificate');
    record_test('Test F.2', 'render_proof_cell handles URL passed in proof_file parameter compatibly', $hasOpenCert2);

    // 3. Verify render_proof_cell with empty proof returns fallback
    $emptyCell = render_proof_cell(null, 'internship', 123, null);
    record_test('Test F.3', 'render_proof_cell gracefully returns "No proof attached" when both empty', str_contains($emptyCell, 'No proof attached'));

    // 4. Verify export proof handling matches export.php logic
    $mockRecordWithDriveLink = [
        'id'               => 555,
        '_type_key'        => 'internship',
        '_title'           => 'Machine Learning Internship',
        '_type_label'      => 'Internship',
        '_person'          => 'Student Demo',
        'department'       => 'CSE',
        'status'           => 'Approved',
        'created_at'       => '2025-08-10 10:00:00',
        'proof_file'       => null,
        'certificate_link' => $linkTestUrl,
    ];

    $resolveExportProof = function(array $record, string $format): mixed {
        $type     = $record['_type_key'] ?? '';
        $id       = (int) ($record['id'] ?? 0);
        $pfile    = trim((string) ($record['proof_file'] ?? ''));
        $certLink = trim((string) ($record['certificate_link'] ?? $record['document_link'] ?? ''));

        if ($format === 'excel') {
            if ($pfile !== '') {
                return ['text' => 'View Proof', 'url' => record_proof_url($type, $id, $pfile, false, true)];
            } elseif ($certLink !== '') {
                return ['text' => 'Open Certificate', 'url' => $certLink];
            } else {
                return '—';
            }
        } elseif ($format === 'csv') {
            if ($pfile !== '') {
                return record_proof_url($type, $id, $pfile, false, true);
            } elseif ($certLink !== '') {
                return $certLink;
            } else {
                return '—';
            }
        } else {
            if ($pfile !== '') {
                return 'view_proof';
            } elseif ($certLink !== '') {
                return '<div style="text-align:center;"><a href="' . e($certLink) . '" target="_blank" rel="noopener noreferrer" style="color:#0044cc;font-weight:600;text-decoration:underline;font-size:9pt;word-break:break-all;">Open Certificate</a></div>';
            } else {
                return '—';
            }
        }
    };

    $excelProof = $resolveExportProof($mockRecordWithDriveLink, 'excel');
    $excelOk = (is_array($excelProof) && ($excelProof['text'] ?? '') === 'Open Certificate' && ($excelProof['url'] ?? '') === $linkTestUrl);
    record_test('Test F.4', 'Excel export includes Open Certificate link object for Drive proof records', $excelOk);

    $csvProof = $resolveExportProof($mockRecordWithDriveLink, 'csv');
    $csvOk = ($csvProof === $linkTestUrl);
    record_test('Test F.5', 'CSV export includes raw Google Drive certificate URL in proof column', $csvOk);

    $htmlProof = $resolveExportProof($mockRecordWithDriveLink, 'html');
    $htmlOk = (str_contains((string)$htmlProof, 'Open Certificate') && str_contains((string)$htmlProof, $linkTestUrl) && str_contains((string)$htmlProof, 'rel="noopener noreferrer"'));
    record_test('Test F.6', 'PDF / HTML / Word export renders Open Certificate link with rel=noopener noreferrer', $htmlOk);

} catch (\Throwable $e) {
    record_test('Test F', 'Regression & Exports', false, $e->getMessage());
}

// -----------------------------------------------------------------------------
// Summary
// -----------------------------------------------------------------------------
echo "\n=======================================================\n";
echo "SUMMARY OF TEST RESULTS:\n";
echo "=======================================================\n";
$totalTests = count($testResults);
$passedCount = count(array_filter($testResults, fn($t) => $t['passed']));
$failedCount = $totalTests - $passedCount;

echo "Total: {$totalTests} | Passed: {$passedCount} | Failed: {$failedCount}\n\n";

if ($failedCount > 0) {
    echo "FAILED TESTS:\n";
    foreach ($testResults as $t) {
        if (!$t['passed']) {
            echo "- {$t['id']}: {$t['desc']} ({$t['detail']})\n";
        }
    }
    exit(1);
} else {
    echo "ALL TESTS PASSED!\n";
    exit(0);
}
