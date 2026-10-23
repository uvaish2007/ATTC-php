<?php
/**
 * Institutional / Department Achievement Upload Handler
 * Handles POST and GET for all inst_ record types.
 * Included by upload.php BEFORE the generic POST handler runs.
 *
 * Returns true if the type was handled (upload.php should exit/redirect).
 * Returns false if this type should fall through to the generic handler.
 *
 * Variables available from upload.php context:
 *   $user, $types, $activeYear, $departments, $type (POST), $nav
 */

require_once __DIR__ . '/compression.php';
require_once __DIR__ . '/upload_sections.php';
require_once __DIR__ . '/xlsx_reader.php';
require_once __DIR__ . '/xlsx_writer.php';

function inst_redirect(string $path): void
{
    if (PHP_SAPI === 'cli' && defined('TEST_MODE')) {
        return;
    }
    redirect($path);
}

if (!function_exists('inst_handle_post')) {

/**
 * Live AJAX Data Fetching for University Pass Percentage (ATTS-UPERCENT)
 */
function inst_handle_ajax_upercent(array $user, string $activeYear): void
{
    $action = input('action');
    if ($action !== 'fetch_upercent') {
        return;
    }

    header('Content-Type: application/json; charset=utf-8');

    // Resolve dept based on permissions
    $requestedDept = trim((string) input('department', ''));
    if (in_array($user['role'], ['Faculty', 'Coordinator'], true) && !empty($user['department'])) {
        $dept = $user['department'];
    } else {
        $dept = $requestedDept ?: ($user['department'] ?? 'CSE');
    }

    $ay = trim((string) input('academic_year', $activeYear)) ?: $activeYear;
    $rawSession = trim((string) input('exam_session', ''));
    $sessionNorm = in_array($rawSession, ['Apr/May', 'Apr-May'], true) ? 'Apr/May' : (in_array($rawSession, ['Nov/Dec', 'Nov-Dec'], true) ? 'Nov/Dec' : $rawSession);
    $semester = trim((string) input('semester', ''));

    $pdo = db();
    $isArch = is_architecture_dept($dept);
    $maxYears = $isArch ? 5 : 4;
    $maxSemesters = $isArch ? 10 : 8;

    // Fetch existing parent record if combination exists
    $existingRecord = null;
    $childRows = [];
    if ($dept !== '' && $ay !== '' && $sessionNorm !== '' && $semester !== '') {
        $stmt = $pdo->prepare("SELECT * FROM inst_pass_percentage WHERE department = ? AND academic_year = ? AND exam_session = ? AND semester = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$dept, $ay, $sessionNorm, $semester]);
        $existingRecord = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existingRecord) {
            $cStmt = $pdo->prepare("SELECT * FROM inst_pass_percentage_rows WHERE pass_percentage_id = ? ORDER BY sort_order ASC, id ASC");
            $cStmt->execute([(int) $existingRecord['id']]);
            $childRows = $cStmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }

    // Fetch department faculty list
    $facultyList = [];
    $allFaculty = [];
    try {
        $fStmt = $pdo->query("SELECT id, name, department, role FROM users WHERE role IN ('Faculty', 'HoD', 'Coordinator') AND (status = 1 OR status = '1' OR status = 'Active') ORDER BY name ASC");
        $rawFac = $fStmt ? $fStmt->fetchAll(PDO::FETCH_ASSOC) : [];
        foreach ($rawFac as $f) {
            $allFaculty[] = [
                'id' => (int)$f['id'],
                'name' => $f['name'],
                'department' => $f['department'] ?? '',
                'role' => $f['role']
            ];
            if (department_names_match($f['department'] ?? '', $dept)) {
                $facultyList[] = $f['name'];
            }
        }
        $facultyList = array_values(array_unique($facultyList));
    } catch (\Throwable $e) {}

    // Fetch known department subjects for datalist
    $knownSubjects = [];
    try {
        $sStmt = $pdo->prepare("SELECT DISTINCT subject_code, subject_name FROM inst_pass_percentage_rows r JOIN inst_pass_percentage p ON r.pass_percentage_id = p.id WHERE p.department = ? ORDER BY subject_code ASC LIMIT 100");
        $sStmt->execute([$dept]);
        $knownSubjects = $sStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {}

    echo json_encode([
        'success'           => true,
        'found'             => !empty($existingRecord),
        'record_id'         => $existingRecord ? (int) $existingRecord['id'] : 0,
        'status'            => $existingRecord['status'] ?? 'Draft',
        'overall_pct'       => $existingRecord['overall_pass_percentage'] ?? '0.00',
        'proof_file'        => $existingRecord['proof_file'] ?? '',
        'rows'              => $childRows,
        'department'        => $dept,
        'is_arch'           => $isArch,
        'max_years'         => $maxYears,
        'max_semesters'     => $maxSemesters,
        'faculty'           => $facultyList,
        'all_faculty'       => $allFaculty,
        'subjects'          => $knownSubjects,
        'current_user_name' => $user['name'] ?? '',
        'current_user_dept' => $user['department'] ?? $dept,
        'current_user_role' => $user['role'] ?? '',
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Calculate Year-Wise Student Achievement Summary for a department and academic year.
 * Preserves legacy row-by-row data while correctly tallying new aggregate submissions.
 */
function get_student_cgpa_summary(string $department, string $academicYear): array
{
    $pdo = db();
    $yearBuckets = [
        'I Year'   => ['above_7_5' => 0, 'above_8_5' => 0, 'records' => 0],
        'II Year'  => ['above_7_5' => 0, 'above_8_5' => 0, 'records' => 0],
        'III Year' => ['above_7_5' => 0, 'above_8_5' => 0, 'records' => 0],
        'IV Year'  => ['above_7_5' => 0, 'above_8_5' => 0, 'records' => 0],
    ];

    try {
        $stmt = $pdo->prepare("
            SELECT id, class_year, semester, students_above_7_5, students_above_8_5, student_name, reg_no, cgpa, status
            FROM inst_student_cgpa
            WHERE department = ? AND academic_year = ? AND status != 'Rejected'
            ORDER BY id ASC
        ");
        $stmt->execute([$department, $academicYear]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $r) {
            $cy = trim((string)($r['class_year'] ?? ''));
            $normYear = match(true) {
                stripos($cy, 'IV') !== false || stripos($cy, '4') !== false => 'IV Year',
                stripos($cy, 'III') !== false || stripos($cy, '3') !== false => 'III Year',
                stripos($cy, 'II') !== false || stripos($cy, '2') !== false => 'II Year',
                stripos($cy, 'I') !== false || stripos($cy, '1') !== false => 'I Year',
                default => $cy
            };

            if (!isset($yearBuckets[$normYear])) {
                $normYear = 'I Year';
            }

            $count85 = (int)($r['students_above_8_5'] ?? 0);
            $count75 = (int)($r['students_above_7_5'] ?? 0);

            // Backward compatibility for legacy individual student rows
            if ($count85 === 0 && $count75 === 0 && !empty($r['student_name']) && isset($r['cgpa'])) {
                $cgpaVal = (float)$r['cgpa'];
                if ($cgpaVal > 8.5) {
                    $count85 = 1;
                }
                if ($cgpaVal >= 7.5) {
                    $count75 = 1;
                }
            } else {
                if ($count75 < $count85) {
                    $count75 = $count85;
                }
            }

            $yearBuckets[$normYear]['above_7_5'] += $count75;
            $yearBuckets[$normYear]['above_8_5'] += $count85;
            $yearBuckets[$normYear]['records']   += 1;
        }
    } catch (\Throwable $e) {
        error_log('get_student_cgpa_summary failed: ' . $e->getMessage());
    }

    $total75 = 0;
    $total85 = 0;
    $totalRecs = 0;
    foreach ($yearBuckets as $yb) {
        $total75 += $yb['above_7_5'];
        $total85 += $yb['above_8_5'];
        $totalRecs += $yb['records'];
    }

    return [
        'department'    => $department,
        'academic_year' => $academicYear,
        'years'         => $yearBuckets,
        'total'         => [
            'above_7_5' => $total75,
            'above_8_5' => $total85,
            'records'   => $totalRecs,
        ],
    ];
}

/**
 * Validate and save an Excel list of students who secured above 8.5 CGPA.
 * Enforces exact column sequence, non-empty fields, valid Year/Semester,
 * CGPA > 8.5, duplicate prevention, and count matching.
 *
 * @return array [?string $storedFileName, ?string $errorMessage, array $validStudents]
 */
function validate_and_save_student_cgpa_excel(
    ?array $file,
    bool $required,
    int $expectedCount,
    string $expectedClassYear,
    string $expectedSemester
): array {
    $errCode = $file['error'] ?? UPLOAD_ERR_NO_FILE;

    if ($errCode === UPLOAD_ERR_NO_FILE || empty($file['tmp_name'])) {
        if ($required) {
            return [null, 'Attachment – List of Students Secured Above 8.5 CGPA (.xlsx) is required.', []];
        }
        return [null, null, []];
    }

    if ($errCode !== UPLOAD_ERR_OK) {
        return match ($errCode) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => [null, 'The uploaded Excel file exceeds the maximum allowed size (2 MB).', []],
            UPLOAD_ERR_PARTIAL   => [null, 'The file was only partially uploaded. Please try again.', []],
            default               => [null, 'File upload failed with error code: ' . $errCode, []],
        };
    }

    if (!file_exists($file['tmp_name'])) {
        return [null, 'The uploaded temporary file does not exist or has already been processed.', []];
    }

    if (!is_uploaded_file($file['tmp_name']) && PHP_SAPI !== 'cli') {
        return [null, 'Security error: invalid temporary uploaded file.', []];
    }

    $clientName = (string)($file['name'] ?? '');
    $ext = strtolower(pathinfo($clientName, PATHINFO_EXTENSION));
    if ($ext !== 'xlsx') {
        return [null, 'Invalid file type. Please upload an Excel (.xlsx) file.', []];
    }

    if (($file['size'] ?? 0) > 2 * 1024 * 1024) {
        return [null, 'The Excel file exceeds the 2 MB limit. Please upload a smaller file.', []];
    }

    // Verify ZIP archive integrity (OpenXML format)
    $zip = new ZipArchive();
    $res = $zip->open($file['tmp_name']);
    if ($res !== true) {
        return [null, 'The uploaded file is not a valid Excel (.xlsx) workbook.', []];
    }
    $hasContentTypes = ($zip->locateName('[Content_Types].xml') !== false);
    $zip->close();

    if (!$hasContentTypes) {
        return [null, 'Corrupted Excel file: missing OpenXML [Content_Types].xml specification.', []];
    }

    // Parse workbook
    try {
        $sheets = SimpleXlsxReader::parse($file['tmp_name']);
    } catch (\Throwable $e) {
        return [null, 'Failed to parse Excel workbook: ' . $e->getMessage(), []];
    }

    if (empty($sheets)) {
        return [null, 'The uploaded Excel workbook contains no readable sheets.', []];
    }

    // Use first sheet
    $rows = reset($sheets);
    $nonEmptyRows = [];
    foreach ($rows as $r) {
        $hasVal = false;
        foreach ($r as $c) {
            if (trim((string)$c) !== '') {
                $hasVal = true;
                break;
            }
        }
        if ($hasVal) {
            $nonEmptyRows[] = $r;
        }
    }

    if (empty($nonEmptyRows)) {
        return [null, 'The uploaded Excel sheet is empty. Please provide headers and student data.', []];
    }

    // Validate headers (Row 1)
    $headerRow = array_map(fn($v) => trim((string)$v), $nonEmptyRows[0]);
    if (count($headerRow) < 6) {
        return [null, 'The Excel file must contain these columns in this exact order: S.No, Register Number, Name, Year, Semester, CGPA.', []];
    }

    $col0 = strtolower($headerRow[0]);
    $col1 = strtolower($headerRow[1]);
    $col2 = strtolower($headerRow[2]);
    $col3 = strtolower($headerRow[3]);
    $col4 = strtolower($headerRow[4]);
    $col5 = strtolower($headerRow[5]);

    $validCols = (
        (str_contains($col0, 's.no') || str_contains($col0, 'sno') || str_contains($col0, 'sl') || $col0 === '#') &&
        (str_contains($col1, 'reg') || str_contains($col1, 'register')) &&
        (str_contains($col2, 'name') || str_contains($col2, 'student')) &&
        (str_contains($col3, 'year')) &&
        (str_contains($col4, 'sem')) &&
        (str_contains($col5, 'cgpa'))
    );

    if (!$validCols) {
        return [null, 'The Excel file must contain these columns in this exact order: S.No, Register Number, Name, Year, Semester, CGPA (found: ' . implode(', ', array_slice($headerRow, 0, 6)) . ').', []];
    }

    $dataRows = array_slice($nonEmptyRows, 1);
    $validStudents = [];
    $seenRegNos = [];

    $yearSemMap = [
        'I Year'   => ['Sem 1', 'Sem 2', '1', '2'],
        'II Year'  => ['Sem 3', 'Sem 4', '3', '4'],
        'III Year' => ['Sem 5', 'Sem 6', '5', '6'],
        'IV Year'  => ['Sem 7', 'Sem 8', '7', '8'],
    ];

    $normFormYear = match(true) {
        stripos($expectedClassYear, 'IV') !== false || stripos($expectedClassYear, '4') !== false => 'IV Year',
        stripos($expectedClassYear, 'III') !== false || stripos($expectedClassYear, '3') !== false => 'III Year',
        stripos($expectedClassYear, 'II') !== false || stripos($expectedClassYear, '2') !== false => 'II Year',
        stripos($expectedClassYear, 'I') !== false || stripos($expectedClassYear, '1') !== false => 'I Year',
        default => $expectedClassYear
    };

    $normFormSem = trim($expectedSemester);

    foreach ($dataRows as $idx => $r) {
        $excelLine = $idx + 2; // Row number in spreadsheet (1-based, after header)
        $sNo    = trim((string)($r[0] ?? ''));
        $regNo  = trim((string)($r[1] ?? ''));
        $name   = trim((string)($r[2] ?? ''));
        $year   = trim((string)($r[3] ?? ''));
        $sem    = trim((string)($r[4] ?? ''));
        $cgpaStr= trim((string)($r[5] ?? ''));

        if ($regNo === '') {
            return [null, "Row {$excelLine}: Register Number cannot be empty.", []];
        }
        if ($name === '') {
            return [null, "Row {$excelLine}: Name cannot be empty.", []];
        }

        // Year normalization
        $normYear = match(true) {
            stripos($year, 'IV') !== false || stripos($year, '4') !== false => 'IV Year',
            stripos($year, 'III') !== false || stripos($year, '3') !== false => 'III Year',
            stripos($year, 'II') !== false || stripos($year, '2') !== false => 'II Year',
            stripos($year, 'I') !== false || stripos($year, '1') !== false => 'I Year',
            default => ''
        };

        if ($normYear === '') {
            return [null, "Row {$excelLine}: Invalid Year '{$year}'. Must be I Year, II Year, III Year, or IV Year.", []];
        }

        if ($normFormYear !== '' && $normYear !== $normFormYear) {
            return [null, "Row {$excelLine}: Year '{$year}' does not match the selected Class / Year '{$expectedClassYear}'.", []];
        }

        // Semester normalization & validation for Year
        $semClean = preg_replace('/[^0-9]/', '', $sem);
        $normSem = $semClean ? 'Sem ' . $semClean : $sem;

        $allowedSems = $yearSemMap[$normYear] ?? [];
        $isSemValid = false;
        foreach ($allowedSems as $as) {
            if (strcasecmp($normSem, $as) === 0 || strcasecmp($sem, $as) === 0 || $semClean === $as) {
                $isSemValid = true;
                break;
            }
        }
        if (!$isSemValid) {
            return [null, "Row {$excelLine}: Semester '{$sem}' is not valid for {$normYear}.", []];
        }

        if ($normFormSem !== '' && $semClean !== '' && preg_replace('/[^0-9]/', '', $normFormSem) !== $semClean) {
            return [null, "Row {$excelLine}: Semester '{$sem}' does not match the selected form Semester '{$expectedSemester}'.", []];
        }

        // CGPA validation
        if (!is_numeric($cgpaStr)) {
            return [null, "Row {$excelLine}: CGPA '{$cgpaStr}' is not numeric.", []];
        }
        $cgpaVal = (float)$cgpaStr;
        if ($cgpaVal < 0 || $cgpaVal > 10.0) {
            return [null, "Row {$excelLine}: CGPA ({$cgpaVal}) must be between 0.00 and 10.00.", []];
        }
        if ($cgpaVal <= 8.5) {
            return [null, "Row {$excelLine}: Student '{$name}' (Reg: {$regNo}) has CGPA {$cgpaStr}, which is not above 8.5. Only students secured above 8.5 CGPA may be included.", []];
        }

        // Duplicate Register Number check
        $regKey = strtolower($regNo);
        if (isset($seenRegNos[$regKey])) {
            return [null, "Row {$excelLine}: Duplicate Register Number '{$regNo}' found (previously listed on row {$seenRegNos[$regKey]}).", []];
        }
        $seenRegNos[$regKey] = $excelLine;

        $validStudents[] = [
            'sno'      => $sNo,
            'reg_no'   => $regNo,
            'name'     => $name,
            'year'     => $normYear,
            'semester' => $normSem,
            'cgpa'     => $cgpaVal,
        ];
    }

    $studentCount = count($validStudents);
    if ($studentCount === 0) {
        return [
            null,
            'The uploaded Excel file contains no valid student records. At least one qualifying student record is required.',
            []
        ];
    }
    if ($studentCount !== $expectedCount) {
        return [
            null,
            "The number of student rows in the uploaded Excel list ({$studentCount}) does not match the entered count of students above 8.5 CGPA ({$expectedCount}).",
            []
        ];
    }

    // Save file to uploads/proofs/
    $uploadDir = defined('UPLOAD_DIR') ? UPLOAD_DIR : (__DIR__ . '/../uploads');
    $proofsDir = rtrim($uploadDir, '/\\') . '/proofs';
    if (!is_dir($proofsDir)) {
        @mkdir($proofsDir, 0755, true);
    }
    $targetDir = is_dir($proofsDir) ? $proofsDir : $uploadDir;
    $storedName = 'cgpa_students_' . md5(uniqid((string)mt_rand(), true)) . '.xlsx';
    $destPath = rtrim($targetDir, '/\\') . '/' . $storedName;

    if (!@move_uploaded_file($file['tmp_name'], $destPath)) {
        if (!@copy($file['tmp_name'], $destPath)) {
            return [null, 'Failed to save the uploaded Excel file to disk.', []];
        }
        @unlink($file['tmp_name']);
    }

    return [$storedName, null, $validStudents];
}

/**
 * Live AJAX Data Fetching and Template Download for Student CGPA Redesign
 */
function inst_handle_ajax_student_cgpa(array $user, string $activeYear): void
{
    $action = input('action');
    if ($action === 'download_cgpa_template') {
        $headers = ['S.No', 'Register Number', 'Name', 'Year', 'Semester', 'CGPA'];
        $sampleRows = [
            [1, '714022104001', 'Arun Kumar S', 'II Year', 'Sem 3', '8.85'],
            [2, '714022104002', 'Bhavani R', 'II Year', 'Sem 3', '9.12'],
            [3, '714022104003', 'Chandran M', 'II Year', 'Sem 3', '8.65'],
        ];
        $xlsxData = SimpleXlsxWriter::createXlsx($headers, $sampleRows, 'Students_Above_8.5_CGPA', [
            'Template: List of Students Secured Above 8.5 CGPA',
            'All rows must have CGPA > 8.5. Valid Years: I Year, II Year, III Year, IV Year.',
        ]);

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="Students_Above_8_5_CGPA_Template.xlsx"');
        header('Content-Length: ' . strlen($xlsxData));
        header('Cache-Control: max-age=0');
        echo $xlsxData;
        exit;
    }

    if ($action === 'fetch_cgpa_summary') {
        header('Content-Type: application/json; charset=utf-8');
        $requestedDept = trim((string) input('department', ''));
        if (in_array($user['role'], ['Faculty', 'Coordinator'], true) && !empty($user['department'])) {
            $dept = $user['department'];
        } else {
            $dept = $requestedDept ?: ($user['department'] ?? 'CSE');
        }
        $ay = trim((string) input('academic_year', $activeYear)) ?: $activeYear;

        $summary = get_student_cgpa_summary($dept, $ay);
        echo json_encode([
            'success' => true,
            'summary' => $summary,
            'current_user_role' => $user['role'] ?? '',
            'current_user_dept' => $user['department'] ?? $dept,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
}

/**
 * Live AJAX Data Fetching for Unified Sports Module (Import from Participation)
 */
function inst_handle_ajax_sports(array $user, string $activeYear): void
{
    $action = input('action');
    if ($action !== 'fetch_sports_participations' && $action !== 'get_sports_participation') {
        return;
    }

    header('Content-Type: application/json; charset=utf-8');
    $pdo = db();

    // 1. Fetch list of participations with duplicate status
    if ($action === 'fetch_sports_participations') {
        $requestedDept = trim((string) input('department', ''));
        if (in_array($user['role'], ['Faculty', 'Coordinator'], true) && !empty($user['department'])) {
            $dept = $user['department'];
        } else {
            $dept = $requestedDept;
        }

        $ay = trim((string) input('academic_year', $activeYear)) ?: $activeYear;
        $search = trim((string) input('search', ''));

        // Gather already imported participation_ids across both state and national tables
        $importedMap = [];
        try {
            $impStmt = $pdo->query("
                SELECT participation_id, 'State' AS level_secured, id FROM inst_sports_state WHERE participation_id IS NOT NULL
                UNION ALL
                SELECT participation_id, 'National' AS level_secured, id FROM inst_sports_national WHERE participation_id IS NOT NULL
            ");
            foreach ($impStmt->fetchAll(PDO::FETCH_ASSOC) as $imp) {
                $importedMap[(int)$imp['participation_id']] = $imp;
            }
        } catch (\PDOException $e) {
            // Ignore if column not ready
        }

        $where = ["1=1"];
        $params = [];
        if ($dept !== '') {
            $where[] = "sp.department = ?";
            $params[] = $dept;
        }
        if ($ay !== '' && $ay !== 'all') {
            $where[] = "sp.academic_year = ?";
            $params[] = $ay;
        }
        if ($search !== '') {
            $where[] = "(sp.student_name LIKE ? OR sp.reg_no LIKE ? OR sp.event_name LIKE ? OR sp.function_name LIKE ? OR sp.activity_category LIKE ?)";
            $term = "%{$search}%";
            $params[] = $term; $params[] = $term; $params[] = $term; $params[] = $term; $params[] = $term;
        }

        $sql = "SELECT sp.id, sp.reg_no, sp.student_name, sp.department, sp.academic_year, sp.activity_category, 
                       sp.event_type, sp.event_name, sp.function_name, sp.event_date, sp.team_individual, 
                       sp.level_secured, sp.position_secured, sp.organising_institution, sp.certificate_link, 
                       sp.proof_file, sp.status 
                FROM student_participations sp 
                WHERE " . implode(' AND ', $where) . " 
                ORDER BY sp.id DESC LIMIT 100";
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $records = [];
            foreach ($rows as $r) {
                $pid = (int) $r['id'];
                $isImported = isset($importedMap[$pid]);
                $records[] = [
                    'id'                     => $pid,
                    'reg_no'                 => (string) ($r['reg_no'] ?? ''),
                    'student_name'           => (string) ($r['student_name'] ?? ''),
                    'department'             => (string) ($r['department'] ?? ''),
                    'academic_year'          => (string) ($r['academic_year'] ?? ''),
                    'activity_category'      => (string) ($r['activity_category'] ?? ''),
                    'event_type'             => (string) ($r['event_type'] ?? ''),
                    'event_name'             => (string) ($r['event_name'] ?? ''),
                    'function_name'          => (string) ($r['function_name'] ?? ''),
                    'event_date'             => (string) ($r['event_date'] ?? ''),
                    'level_secured'          => (string) ($r['level_secured'] ?? ''),
                    'position_secured'       => (string) ($r['position_secured'] ?? ''),
                    'organising_institution' => (string) ($r['organising_institution'] ?? ''),
                    'proof_file'             => (string) ($r['proof_file'] ?? ''),
                    'status'                 => (string) ($r['status'] ?? ''),
                    'already_imported'       => $isImported,
                    'imported_info'          => $isImported ? $importedMap[$pid] : null,
                ];
            }
            echo json_encode(['success' => true, 'records' => $records], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (\PDOException $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    // 2. Get specific participation details
    if ($action === 'get_sports_participation') {
        $pid = (int) input('id');
        if ($pid <= 0) {
            echo json_encode(['success' => false, 'error' => 'Invalid participation ID.']);
            exit;
        }

        try {
            $stmt = $pdo->prepare("SELECT * FROM student_participations WHERE id = ?");
            $stmt->execute([$pid]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                echo json_encode(['success' => false, 'error' => 'Participation record not found.']);
                exit;
            }

            // Department authorization
            if (in_array($user['role'], ['Faculty', 'Coordinator'], true) && !empty($user['department'])) {
                if (!department_names_match($row['department'], $user['department'])) {
                    echo json_encode(['success' => false, 'error' => 'You are not authorized to access participation records for this department.']);
                    exit;
                }
            }

            // Check if already imported
            $chkState = $pdo->prepare("SELECT id FROM inst_sports_state WHERE participation_id = ?");
            $chkState->execute([$pid]);
            $isState = $chkState->fetch(PDO::FETCH_ASSOC);

            $chkNat = $pdo->prepare("SELECT id FROM inst_sports_national WHERE participation_id = ?");
            $chkNat->execute([$pid]);
            $isNat = $chkNat->fetch(PDO::FETCH_ASSOC);

            if ($isState || $isNat) {
                $level = $isNat ? 'National' : 'State';
                $recId = $isNat ? $isNat['id'] : $isState['id'];
                echo json_encode([
                    'success'          => false,
                    'already_imported' => true,
                    'error'            => "This participation record has already been imported into {$level}-level sports achievements (Record #{$recId}).",
                ]);
                exit;
            }

            // Normalize sport name and competition level
            $rawLevel = trim((string)($row['level_secured'] ?? ''));
            $compLevel = '';
            if (strcasecmp($rawLevel, 'National') === 0 || stripos($rawLevel, 'National') !== false) {
                $compLevel = 'National';
            } elseif (strcasecmp($rawLevel, 'State') === 0 || stripos($rawLevel, 'State') !== false) {
                $compLevel = 'State';
            }

            // Deduce sport / game name
            $sport = '';
            if (!empty($row['event_type']) && stripos($row['activity_category'] ?? '', 'Sport') !== false) {
                $sport = $row['event_type'];
            } elseif (!empty($row['function_name']) && stripos($row['function_name'], 'Symposium') === false) {
                $sport = $row['function_name'];
            } else {
                $sport = $row['event_name'] ?? '';
            }

            echo json_encode([
                'success' => true,
                'record'  => [
                    'id'                     => (int)$row['id'],
                    'student_name'           => (string)($row['student_name'] ?? ''),
                    'reg_no'                 => (string)($row['reg_no'] ?? ''),
                    'department'             => (string)($row['department'] ?? ''),
                    'academic_year'          => (string)($row['academic_year'] ?? ''),
                    'sport'                  => $sport,
                    'event_name'             => (string)($row['event_name'] ?? ''),
                    'competition_level'      => $compLevel,
                    'event_date'             => (string)($row['event_date'] ?? ''),
                    'venue'                  => (string)($row['organising_institution'] ?? ''),
                    'proof_file'             => (string)($row['proof_file'] ?? ''),
                ],
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (\PDOException $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }
}


/**
 * Handle submission and editing of inst_student_cgpa records
 */
function inst_handle_student_cgpa(array $user, string $activeYear, \PDO $pdo, string $nav): bool
{
    $type = 'inst_student_cgpa';

    // Role-based Department Authorization:
    if (in_array($user['role'], ['Faculty', 'Coordinator'], true) && !empty($user['department'])) {
        $submittedDept = trim((string) ($_POST['department'] ?? ''));
        if ($submittedDept !== '' && !department_names_match($submittedDept, $user['department'])) {
            flash('error', 'You are not authorized to submit data for this department.');
            inst_redirect('/upload.php?section=student_cgpa&type=' . $type);
            return false;
        }
        $_POST['department'] = $user['department'];
    }

    $dept = trim((string) ($_POST['department'] ?? ''));
    if ($dept === '') {
        flash('error', 'Department is required.');
        inst_redirect('/upload.php?section=student_cgpa&type=' . $type);
        return false;
    }

    // Validate Class / Year
    $classYear = trim((string) ($_POST['class_year'] ?? ''));
    $validYears = ['I Year', 'II Year', 'III Year', 'IV Year'];
    if (!in_array($classYear, $validYears, true)) {
        flash('error', 'Class / Year is required and must be one of: ' . implode(', ', $validYears));
        inst_redirect('/upload.php?section=student_cgpa&type=' . $type);
        return false;
    }

    // Validate Semester
    $semester = trim((string) ($_POST['semester'] ?? ''));
    $semMap = [
        'I Year'   => ['Sem 1', 'Sem 2'],
        'II Year'  => ['Sem 3', 'Sem 4'],
        'III Year' => ['Sem 5', 'Sem 6'],
        'IV Year'  => ['Sem 7', 'Sem 8'],
    ];
    $allowedSems = $semMap[$classYear] ?? [];
    if (!in_array($semester, $allowedSems, true)) {
        flash('error', "Selected Semester ({$semester}) is not valid for {$classYear}. Allowed semesters: " . implode(', ', $allowedSems));
        inst_redirect('/upload.php?section=student_cgpa&type=' . $type);
        return false;
    }

    // Validate Count of Students Above 8.5 CGPA
    $rawCount85 = trim((string) ($_POST['students_above_8_5'] ?? ''));
    if ($rawCount85 === '' || !preg_match('/^\d+$/', $rawCount85)) {
        flash('error', 'Number of Students Secured Above 8.5 CGPA is required and must be a non-negative whole number (0, 1, 2, ...).');
        inst_redirect('/upload.php?section=student_cgpa&type=' . $type);
        return false;
    }
    $count85 = (int)$rawCount85;
    $count75 = $count85;

    $programme = trim((string) ($_POST['programme'] ?? ''));
    $editId = (int) input('edit_id');
    $existingRec = null;

    if ($editId > 0) {
        [$canEdit, $errMsg, $existingRec] = can_edit_record($type, $editId, $user);
        if (!$canEdit) {
            flash('error', $errMsg);
            inst_redirect('/upload.php?section=student_cgpa&type=' . $type . '&edit_id=' . $editId);
            return false;
        }
    }

    // Attachment validation & upload
    $hasNewFile = isset($_FILES['proof']) && ($_FILES['proof']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
    $attachmentRequired = ($editId === 0 || empty($existingRec['proof_file']));

    $proofStored = null;
    if ($hasNewFile || $attachmentRequired) {
        [$savedFile, $fileErr, $validStudents] = validate_and_save_student_cgpa_excel(
            $_FILES['proof'] ?? null,
            $attachmentRequired,
            $count85,
            $classYear,
            $semester
        );
        if ($fileErr !== null) {
            flash('error', $fileErr);
            $redirectUrl = '/upload.php?section=student_cgpa&type=' . $type . ($editId > 0 ? '&edit_id=' . $editId : '');
            inst_redirect($redirectUrl);
            return false;
        }
        $proofStored = $savedFile;
    } else {
        $proofStored = $existingRec['proof_file'] ?? null;
    }

    try {
        if ($editId > 0) {
            $oldProof = $existingRec['proof_file'] ?? null;
            $stmt = $pdo->prepare("
                UPDATE inst_student_cgpa
                SET department = ?, class_year = ?, semester = ?, programme = ?,
                    students_above_8_5 = ?, students_above_7_5 = ?, proof_file = ?,
                    status = 'Submitted', review_remark = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $remark = 'Updated by ' . ($user['name'] ?? 'User');
            $stmt->execute([
                $dept, $classYear, $semester, $programme,
                $count85, $count75, $proofStored, $remark, $editId
            ]);

            if ($hasNewFile && $oldProof && $oldProof !== $proofStored) {
                @unlink(rtrim(defined('UPLOAD_DIR') ? UPLOAD_DIR : (__DIR__ . '/../uploads'), '/\\') . '/proofs/' . $oldProof);
                @unlink(rtrim(defined('UPLOAD_DIR') ? UPLOAD_DIR : (__DIR__ . '/../uploads'), '/\\') . '/' . $oldProof);
            }

            if (function_exists('record_workflow_audit')) {
                record_workflow_audit($type, $editId, 'FACULTY_RESUBMITTED', $user, null, 'Resubmitted',
                    'Student CGPA achievement record updated', null, $dept, $activeYear);
            }

            flash('success', 'Student CGPA achievement record updated successfully.');
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO inst_student_cgpa
                (created_by, status, academic_year, department, class_year, semester, programme, students_above_8_5, students_above_7_5, proof_file, created_at, updated_at)
                VALUES (?, 'Submitted', ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ");
            $stmt->execute([
                (int)$user['id'], $activeYear, $dept, $classYear, $semester, $programme,
                $count85, $count75, $proofStored
            ]);
            $newId = (int)$pdo->lastInsertId();

            if (function_exists('record_workflow_audit')) {
                record_workflow_audit($type, $newId, 'FACULTY_SUBMITTED', $user, null, 'Submitted',
                    'Student CGPA achievement record submitted', null, $dept, $activeYear);
            }

            flash('success', 'Student CGPA achievement record submitted for review.');
            $_SESSION['submitted_draft_type'] = $type;
        }
    } catch (\PDOException $e) {
        if ($hasNewFile && $proofStored) {
            @unlink(rtrim(defined('UPLOAD_DIR') ? UPLOAD_DIR : (__DIR__ . '/../uploads'), '/\\') . '/proofs/' . $proofStored);
        }
        error_log('inst_student_cgpa save failed: ' . $e->getMessage());
        flash('error', 'Save failed: ' . (defined('APP_DEBUG') && APP_DEBUG ? $e->getMessage() : 'database error.'));
        inst_redirect('/upload.php?section=student_cgpa&type=' . $type);
        return false;
    }

    if ($nav === 'next') {
        $nextSec = upload_section_next('student_cgpa');
        if ($nextSec) {
            inst_redirect('/upload.php?section=' . $nextSec['id'] . '&type=' . $nextSec['default_type']);
            return true;
        }
    }

    inst_redirect('/upload.php?section=student_cgpa&type=' . $type);
    return true;
}

/**
 * Validate and save an institutional record.
 * Returns true and redirects on success/fail.
 * Returns false if $type is not an inst_ type.
 */
function inst_handle_post(array $user, array $types, string $activeYear): bool
{
    $type = (string) input('record_type');
    if (!str_starts_with($type, 'inst_')) {
        return false;
    }

    if (!isset($types[$type])) {
        flash('error', 'Invalid institutional record type.');
        redirect('/upload.php?type=' . $type);
    }

    // Force department for Faculty
    if ($user['role'] === 'Faculty' && !empty($user['department'])) {
        $_POST['department'] = $user['department'];
    }

    $dept = trim((string) ($_POST['department'] ?? ''));
    if ($dept === '') {
        flash('error', 'Department is required.');
        redirect('/upload.php?type=' . $type);
    }

    // Validate the user is allowed to post for this department
    if ($user['role'] === 'Faculty' && !empty($user['department']) && $user['department'] !== $dept) {
        flash('error', 'You are not authorized to submit data for this department.');
        redirect('/upload.php?type=' . $type);
    }

    $nav  = (string) input('nav', 'add');
    $pdo  = db();

    // ── inst_pass_percentage: special multi-row handler ──────────────────────
    if ($type === 'inst_pass_percentage') {
        return inst_handle_pass_percentage($user, $activeYear, $pdo, $nav);
    }

    // ── inst_student_cgpa: specialized aggregate & Excel handler ─────────────
    if ($type === 'inst_student_cgpa') {
        return inst_handle_student_cgpa($user, $activeYear, $pdo, $nav);
    }

    // ── Generic institutional handler ────────────────────────────────────────
    $requiredMap = inst_required_fields();
    $expected    = $requiredMap[$type] ?? [];

    // Dynamic field requirements for inst_society_memberships based on membership_category
    if ($type === 'inst_society_memberships') {
        $cat = trim((string)($_POST['membership_category'] ?? 'Faculty'));
        if (!in_array($cat, ['Faculty', 'Student'], true)) {
            $cat = 'Faculty';
        }
        $_POST['membership_category'] = $cat;

        if ($cat === 'Student') {
            $expected = [
                'reg_no'            => 'Student Register Number',
                'student_name'      => 'Student Name',
                'society_name'      => 'Professional Society',
                'membership_number' => 'Membership Number',
            ];
            // Clear faculty fields
            $_POST['faculty_name'] = null;
        } else {
            $expected = [
                'faculty_name'      => 'Faculty Name',
                'society_name'      => 'Professional Society',
                'membership_number' => 'Membership Number',
            ];
            // Clear student fields
            $_POST['reg_no'] = null;
            $_POST['student_name'] = null;
            $_POST['class_year'] = null;
        }
    }

    $errors = [];
    foreach ($expected as $fKey => $fLabel) {
        $val = trim((string) ($_POST[$fKey] ?? ''));
        if ($val === '') {
            $errors[] = "{$fLabel} is required.";
            continue;
        }
        // URL validation
        if (in_array($fKey, ['youtube_url', 'url', 'website_url', 'mou_url'], true)) {
            if (!preg_match('/^https?:\/\/.+/i', $val)) {
                $errors[] = "{$fLabel} must be a valid URL (http:// or https://).";
            }
        }
        // Numeric validation
        if (in_array($fKey, ['participants', 'member_count', 'review_count', 'google_rating', 'project_amount', 'views'], true)) {
            if (!is_numeric($val)) {
                $errors[] = "{$fLabel} must be numeric.";
            }
        }
        // Google rating range
        if ($fKey === 'google_rating') {
            $r = (float) $val;
            if ($r < 0 || $r > 5) {
                $errors[] = "Google Rating must be between 0.0 and 5.0.";
            }
        }
        // Date fields
        if (in_array($fKey, ['from_date', 'to_date', 'start_date', 'end_date', 'event_date', 'date',
                              'completion_date', 'grant_date', 'registration_date', 'formation_date',
                              'measurement_date', 'application_date', 'publication_date', 'achievement_date',
                              'recognition_date', 'update_date', 'placement_date'], true)) {
            if ($val !== '') {
                if (strtotime($val) === false) {
                    $errors[] = "{$fLabel} must be a valid date.";
                } elseif (function_exists('validate_submission_date')) {
                    $dateErr = validate_submission_date($fKey, $val, $fLabel);
                    if ($dateErr !== null) {
                        $errors[] = $dateErr;
                    }
                }
            }
        }
    }

    // inst_internships: validate duration >= 4 weeks
    if ($type === 'inst_internships') {
        $startD = trim((string) ($_POST['start_date'] ?? ''));
        $endD   = trim((string) ($_POST['end_date'] ?? ''));
        if ($startD !== '' && $endD !== '' && strtotime($startD) !== false && strtotime($endD) !== false) {
            $diffDays = (int) ceil((strtotime($endD) - strtotime($startD)) / 86400);
            $diffWeeks = $diffDays / 7;
            if ($diffWeeks < 4) {
                $errors[] = 'Internship duration must be 4 weeks or more (dates indicate ' . round($diffWeeks, 1) . ' weeks).';
            }
            // Auto-fill duration_weeks
            if (!isset($_POST['duration_weeks']) || trim((string) $_POST['duration_weeks']) === '') {
                $_POST['duration_weeks'] = (string) round($diffWeeks, 1);
            }
        }
    }

    // inst_summer_trainings: validate duration < 4 weeks
    if ($type === 'inst_summer_trainings') {
        $startD = trim((string) ($_POST['start_date'] ?? ''));
        $endD   = trim((string) ($_POST['end_date'] ?? ''));
        if ($startD !== '' && $endD !== '' && strtotime($startD) !== false && strtotime($endD) !== false) {
            $diffDays  = (int) ceil((strtotime($endD) - strtotime($startD)) / 86400);
            $diffWeeks = $diffDays / 7;
            if ($diffWeeks >= 4) {
                $errors[] = 'Summer Training duration must be LESS than 4 weeks (dates indicate ' . round($diffWeeks, 1) . ' weeks). Use "Student Industry Internship" for 4+ weeks.';
            }
            if (!isset($_POST['duration_weeks']) || trim((string) $_POST['duration_weeks']) === '') {
                $_POST['duration_weeks'] = (string) round($diffWeeks, 1);
            }
        }
    }


    $editId = (int) input('edit_id');
    $isSports = ($type === 'inst_sports_state' || $type === 'inst_sports_national');

    if ($isSports) {
        // Enforce Competition Level
        $level = trim((string) ($_POST['level_secured'] ?? $_POST['competition_level'] ?? $_POST['level'] ?? ''));
        if (!in_array($level, ['State', 'National'], true)) {
            $errors[] = 'Competition Level is required and must be either State or National.';
        } else {
            $_POST['level_secured'] = $level;
            $_POST['level']         = $level;
            $type = ($level === 'National') ? 'inst_sports_national' : 'inst_sports_state';
        }

        // Enforce Medal dropdown
        $medal = trim((string) ($_POST['award'] ?? $_POST['medal'] ?? ''));
        $medalMap = [
            'Gold'   => 'Winner',
            'Silver' => 'Runner-up',
            'Bronze' => 'Third Place',
        ];
        if (!isset($medalMap[$medal])) {
            $errors[] = 'Medal is required and must be Gold, Silver, or Bronze.';
        } else {
            $_POST['award'] = $medal;
            $expectedResult = $medalMap[$medal];
            $submittedResult = trim((string) ($_POST['participation_position'] ?? ''));
            if ($submittedResult !== '' && $submittedResult !== $expectedResult) {
                $errors[] = "Inconsistent Medal and Result: {$medal} must correspond to Result '{$expectedResult}'.";
            }
            $_POST['participation_position'] = $expectedResult;
        }

        // Validate participation_id and prevent duplicate imports
        $partId = !empty($_POST['participation_id']) ? (int) $_POST['participation_id'] : null;
        if ($partId !== null && $partId > 0) {
            $pStmt = $pdo->prepare("SELECT * FROM student_participations WHERE id = ?");
            $pStmt->execute([$partId]);
            $pRow = $pStmt->fetch(PDO::FETCH_ASSOC);
            if (!$pRow) {
                $errors[] = 'Selected participation record does not exist.';
            } else {
                if (in_array($user['role'], ['Faculty', 'Coordinator'], true) && !empty($user['department']) && !department_names_match($pRow['department'], $user['department'])) {
                    $errors[] = 'You are not authorized to import participation records from another department.';
                }
                $origType = (string) input('orig_type', input('record_type'));
                $chkState = $pdo->prepare("SELECT id FROM inst_sports_state WHERE participation_id = ?" . ($editId > 0 && $origType === 'inst_sports_state' ? " AND id != {$editId}" : ""));
                $chkState->execute([$partId]);
                $chkNat = $pdo->prepare("SELECT id FROM inst_sports_national WHERE participation_id = ?" . ($editId > 0 && $origType === 'inst_sports_national' ? " AND id != {$editId}" : ""));
                $chkNat->execute([$partId]);
                if ($chkState->fetch() || $chkNat->fetch()) {
                    $errors[] = 'This participation record has already been imported into sports achievements.';
                }
            }
        }

        // Duplicate sports achievement check (student reg_no + sport + event_name + event_date + academic_year)
        $regNo = trim((string) ($_POST['reg_no'] ?? ''));
        $sport = trim((string) ($_POST['sport'] ?? ''));
        $eventName = trim((string) ($_POST['event_name'] ?? ''));
        $eventDate = trim((string) ($_POST['event_date'] ?? ''));
        if ($regNo !== '' && $sport !== '' && $eventName !== '') {
            $origType = (string) input('orig_type', input('record_type'));
            $tableToCheck = ($type === 'inst_sports_national') ? 'inst_sports_national' : 'inst_sports_state';
            $dupSql = "SELECT id FROM `{$tableToCheck}` WHERE reg_no = ? AND sport = ? AND event_name = ? AND event_date = ? AND academic_year = ?"
                    . ($editId > 0 && $origType === $type ? " AND id != {$editId}" : "");
            $dupStmt = $pdo->prepare($dupSql);
            $dupStmt->execute([$regNo, $sport, $eventName, $eventDate, $activeYear]);
            if ($dupStmt->fetch()) {
                $errors[] = "A sports achievement record for this student and event already exists at the {$level} level.";
            }
        }
    }

    // inst_sports_national: enforce level = National
    if ($type === 'inst_sports_national') {
        $_POST['level'] = 'National';
    }
    // inst_sports_state: enforce level = State
    if ($type === 'inst_sports_state') {
        $_POST['level'] = 'State';
    }

    // inst_inter_inst_within: auto-derive achievement_type if not explicitly set
    if ($type === 'inst_inter_inst_within') {
        $pt = trim((string) ($_POST['participation_type'] ?? ''));
        if (empty($_POST['achievement_type'])) {
            $_POST['achievement_type'] = in_array($pt, ['Winner / First Place', 'Second Runner', 'Third Runner'], true)
                ? 'Medal'
                : 'Participation';
        }
    }

    // Handle proof / certificate upload
    $editId = (int) input('edit_id');
    $proofLabel = $isSports ? 'Certificate' : match ($type) {
        'inst_research_centre' => 'Recognition Letter',
        'inst_mou_interactions' => 'Report',
        default => 'Proof / Attachment',
    };
    $isProofRequired = false;
    if ($isSports) {
        $isProofRequired = ($editId <= 0);
    } elseif (($type === 'inst_inter_inst_within' || $type === 'inst_society_memberships') && $editId <= 0) {
        $isProofRequired = empty($_POST['certificate_link']);
    } elseif (in_array($type, ['inst_research_centre', 'inst_mou_interactions'], true)) {
        $isProofRequired = true;
    }
    [$proofStored, $proofError] = save_upload_proof($_FILES['proof'] ?? null, $isProofRequired, $proofLabel);
    if ($proofError !== null) {
        $errors[] = $proofError;
    }

    if (!empty($errors)) {
        flash('error', implode('<br>', $errors));
        $secRes = resolve_upload_section(null, $type);
        redirect('/upload.php?section=' . $secRes['section']['id'] . '&type=' . $type . ($editId > 0 ? '&edit_id=' . $editId : ''));
    }

    // ── INSERT or UPDATE ──────────────────────────────────────────────────────
    $table = $types[$type]['table'];
    try {
        $protected    = ['id', 'created_by', 'status', 'approved_by', 'review_remark', 'created_at', 'updated_at', 'academic_year'];
        $tableColumns = $pdo->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_COLUMN);
        $allowed      = array_diff($tableColumns, $protected);

        $submittedAy = trim((string)($_POST['academic_year'] ?? ''));
        if ($submittedAy === '' || !is_valid_academic_year($submittedAy)) {
            $submittedAy = $activeYear;
        }
        if (is_future_academic_year($submittedAy)) {
            flash('error', "Academic year {$submittedAy} has not started yet. Data for future academic years cannot be submitted.");
            redirect('/upload.php?type=' . $type);
            return false;
        }
        if ($user['role'] !== 'Admin' && academic_year_is_locked($submittedAy)) {
            flash('error', "Academic year {$submittedAy} cycle is currently locked. New record submissions for {$submittedAy} are frozen.");
            redirect('/upload.php?type=' . $type);
            return false;
        }

        if ($type === 'inst_awards_recognitions') {
            if (!empty($_POST['title']) && empty($_POST['title_award'])) {
                $_POST['title_award'] = $_POST['title'];
            }
            if (!empty($_POST['other_details']) && empty($_POST['remarks'])) {
                $_POST['remarks'] = $_POST['other_details'];
            }
        }

        if ($editId > 0) {
            $origType = (string) input('orig_type', input('record_type'));
            $origTable = $types[$origType]['table'] ?? $table;

            $existingStmt = $pdo->prepare("SELECT * FROM `{$origTable}` WHERE id = ?");
            $existingStmt->execute([$editId]);
            $existingRec = $existingStmt->fetch(PDO::FETCH_ASSOC);

            if (!$existingRec) {
                flash('error', 'Record not found for update.');
                $resolved = resolve_upload_section(null, $type);
                redirect('/upload.php?section=' . $resolved['section']['id'] . '&type=' . $type);
            }

            // If switching table (e.g. State <-> National)
            if ($origTable !== $table) {
                $fields = ['created_by', 'status', 'academic_year', 'created_at'];
                $values = [(int) ($existingRec['created_by'] ?? $user['id']), 'Submitted', $existingRec['academic_year'] ?? $submittedAy, $existingRec['created_at'] ?? date('Y-m-d H:i:s')];

                foreach ($_POST as $k => $v) {
                    if (!in_array($k, $allowed, true) || trim((string) $v) === '' || $k === 'academic_year') continue;
                    $fields[] = $k;
                    $values[] = trim((string) $v);
                }
                if (in_array('department', $allowed, true) && !in_array('department', $fields, true) && !empty($user['department'])) {
                    $fields[] = 'department';
                    $values[] = $user['department'];
                }
                $proofToUse = $proofStored ?? ($existingRec['proof_file'] ?? null);
                if ($proofToUse !== null && in_array('proof_file', $tableColumns, true)) {
                    $fields[] = 'proof_file';
                    $values[] = $proofToUse;
                }

                $quotedFields = array_map(fn($f) => '`' . str_replace('`', '``', $f) . '`', $fields);
                $sql = "INSERT INTO `{$table}` (" . implode(', ', $quotedFields) . ") VALUES (" . implode(', ', array_fill(0, count($values), '?')) . ")";
                $pdo->prepare($sql)->execute($values);
                $newId = (int) $pdo->lastInsertId();

                $pdo->prepare("DELETE FROM `{$origTable}` WHERE id = ?")->execute([$editId]);

                if (function_exists('record_workflow_audit')) {
                    record_workflow_audit($type, $newId, 'COORDINATOR_RESUBMITTED', $user,
                        $existingRec['status'] ?? 'Unlocked for Edit', 'Submitted',
                        'Sports record level changed and moved to ' . $table, null,
                        $_POST['department'] ?? ($user['department'] ?? null), $submittedAy);
                }

                flash('success', $types[$type]['label'] . ' updated successfully.');
            } else {
                // Update in place
                $setPairs = [];
                $updateVals = [];
                foreach ($_POST as $k => $v) {
                    if (!in_array($k, $allowed, true) || $k === 'academic_year') continue;
                    $setPairs[] = "`{$k}` = ?";
                    $updateVals[] = trim((string) $v);
                }
                if ($type === 'inst_society_memberships') {
                    $cat = trim((string) ($_POST['category'] ?? ''));
                    if ($cat === 'Faculty') {
                        $setPairs[] = "`reg_no` = NULL";
                        $setPairs[] = "`student_name` = NULL";
                        $setPairs[] = "`class_year` = NULL";
                    } else {
                        $setPairs[] = "`faculty_name` = NULL";
                    }
                }
                if (in_array('academic_year', $tableColumns, true)) {
                    $setPairs[] = "`academic_year` = ?";
                    $updateVals[] = $submittedAy;
                }
                if ($proofStored !== null && in_array('proof_file', $tableColumns, true)) {
                    $setPairs[] = "`proof_file` = ?";
                    $updateVals[] = $proofStored;
                }
                $setPairs[] = "`status` = ?";
                $updateVals[] = 'Resubmitted';
                $setPairs[] = "`review_remark` = ?";
                $updateVals[] = 'Corrected and resubmitted by ' . ($user['name'] ?? '');
                $setPairs[] = "`updated_at` = NOW()";

                $sql = "UPDATE `{$table}` SET " . implode(', ', $setPairs) . " WHERE id = ?";
                $updateVals[] = $editId;
                $pdo->prepare($sql)->execute($updateVals);

                if (function_exists('record_workflow_audit')) {
                    record_workflow_audit($type, $editId, 'COORDINATOR_RESUBMITTED', $user,
                        $existingRec['status'] ?? 'Unlocked for Edit', 'Resubmitted',
                        'Record updated and resubmitted', null,
                        $_POST['department'] ?? ($user['department'] ?? null), $submittedAy);
                }

                flash('success', $types[$type]['label'] . ' updated successfully.');
            }
        } else {
            // New entry insertion
            $fields = ['created_by', 'status'];
            $values = [(int) $user['id'], 'Submitted'];
            if (in_array('academic_year', $tableColumns, true)) {
                $fields[] = 'academic_year';
                $values[] = $submittedAy;
            }
            if (in_array('created_at', $tableColumns, true)) {
                $fields[] = 'created_at';
                $values[] = date('Y-m-d H:i:s');
            }

            foreach ($_POST as $k => $v) {
                if (!in_array($k, $allowed, true) || trim((string) $v) === '' || $k === 'academic_year') continue;
                $fields[] = $k;
                $values[] = trim((string) $v);
            }

            // Ensure department is saved
            if (in_array('department', $allowed, true) && !in_array('department', $fields, true) && !empty($user['department'])) {
                $fields[] = 'department';
                $values[] = $user['department'];
            }

            if ($proofStored !== null && in_array('proof_file', $tableColumns, true) && !in_array('proof_file', $fields, true)) {
                $fields[] = 'proof_file';
                $values[] = $proofStored;
            }

            $quotedFields = array_map(fn($f) => '`' . str_replace('`', '``', $f) . '`', $fields);
            $sql = "INSERT INTO `{$table}` (" . implode(', ', $quotedFields) . ") VALUES (" . implode(', ', array_fill(0, count($values), '?')) . ")";
            $pdo->prepare($sql)->execute($values);

            $newId = (int) $pdo->lastInsertId();

            if (function_exists('record_workflow_audit')) {
                record_workflow_audit($type, $newId, 'FACULTY_SUBMITTED', $user, null, 'Submitted',
                    'Institutional record submitted', null,
                    $_POST['department'] ?? ($user['department'] ?? null), $submittedAy);
            }

            flash('success', $types[$type]['label'] . ' submitted for review.');
            $_SESSION['submitted_draft_type'] = $type;
        }

    } catch (\PDOException $e) {
        if ($proofStored !== null) {
            @unlink(rtrim(UPLOAD_DIR, '/\\') . '/' . $proofStored);
        }
        error_log('inst upload failed: ' . $e->getMessage());
        flash('error', 'Save failed: ' . (defined('APP_DEBUG') && APP_DEBUG ? $e->getMessage() : 'database error.'));
        $resolved = resolve_upload_section(null, $type);
        redirect('/upload.php?section=' . $resolved['section']['id'] . '&type=' . $type);
    }

    $nav = (string) input('nav', 'add');
    $resolved = resolve_upload_section(null, $type);
    $secId = $resolved['section']['id'];
    if ($nav === 'next') {
        $nextSec = upload_section_next($secId);
        if ($nextSec) {
            redirect('/upload.php?section=' . $nextSec['id'] . '&type=' . $nextSec['default_type']);
        }
    }
    redirect('/upload.php?section=' . $secId . '&type=' . $type);
}


// ─────────────────────────────────────────────────────────────────────────────
// Result Copy (Attachment) Handler for University Pass Percentage (ATTS-UPERCENT)
// Supports PDF (.pdf) and PNG (.png) up to 4 MB
// ─────────────────────────────────────────────────────────────────────────────
if (!function_exists('save_result_copy_proof')) {
function save_result_copy_proof(?array $file, bool $required = false): array
{
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        if ($required) {
            return [null, 'Result Copy is required. Please upload the university result copy or approved result document (PDF/PNG, maximum 4 MB).'];
        }
        return [null, null];
    }

    $errorCode = $file['error'] ?? UPLOAD_ERR_OK;
    if ($errorCode !== UPLOAD_ERR_OK) {
        return match ($errorCode) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => [null, 'The uploaded Result Copy exceeds the 4 MB size limit. Please upload a smaller file.'],
            UPLOAD_ERR_PARTIAL   => [null, 'The Result Copy file was only partially uploaded. Please try again.'],
            UPLOAD_ERR_NO_TMP_DIR => [null, 'Server configuration error: missing temporary folder.'],
            UPLOAD_ERR_CANT_WRITE => [null, 'Server error: failed to write Result Copy to disk.'],
            UPLOAD_ERR_EXTENSION  => [null, 'A server extension stopped the Result Copy file upload.'],
            default               => [null, 'Result Copy upload failed. Please try again.'],
        };
    }

    if (!is_uploaded_file($file['tmp_name']) && !is_file($file['tmp_name'])) {
        return [null, 'The Result Copy could not be uploaded (invalid temporary file).'];
    }

    $maxBytes = 4 * 1024 * 1024; // Strictly 4 MB
    if ($file['size'] > $maxBytes) {
        return [null, 'The uploaded Result Copy exceeds the 4 MB size limit. Please upload a smaller file.'];
    }

    $ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
    $allowedExts = ['pdf', 'png'];
    if (!in_array($ext, $allowedExts, true)) {
        return [null, 'The Result Copy must be a PDF document (.pdf) or PNG image (.png).'];
    }

    // Verify MIME and binary magic numbers
    $finfo = @finfo_open(FILEINFO_MIME_TYPE);
    $mime = $finfo ? (string) @finfo_file($finfo, $file['tmp_name']) : (function_exists('mime_content_type') ? (string) @mime_content_type($file['tmp_name']) : '');
    if ($finfo && PHP_VERSION_ID < 80500) {
        @finfo_close($finfo);
    }

    $handle = @fopen($file['tmp_name'], 'rb');
    $header8 = $handle ? fread($handle, 8) : '';
    if ($handle) {
        fclose($handle);
    }

    if ($ext === 'pdf') {
        if ($mime !== '' && stripos($mime, 'pdf') === false && stripos($mime, 'octet-stream') === false) {
            return [null, 'That file is not a valid PDF document.'];
        }
        if (substr($header8, 0, 4) !== '%PDF') {
            return [null, 'That file is not a valid PDF document.'];
        }
    } elseif ($ext === 'png') {
        if ($mime !== '' && stripos($mime, 'png') === false && stripos($mime, 'octet-stream') === false) {
            return [null, 'That file is not a valid PNG image.'];
        }
        if (substr($header8, 0, 4) !== "\x89PNG") {
            return [null, 'That file is not a valid PNG image.'];
        }
    }

    $uploadDir = defined('UPLOAD_DIR') ? UPLOAD_DIR : (__DIR__ . '/../uploads');
    $proofsDir = rtrim($uploadDir, '/\\') . '/proofs';
    if (!is_dir($proofsDir)) {
        @mkdir($proofsDir, 0755, true);
    }
    $targetDir = is_dir($proofsDir) ? $proofsDir : $uploadDir;

    $fileName = 'result_copy_' . md5(uniqid((string)mt_rand(), true)) . '.' . $ext;
    $destPath = rtrim($targetDir, '/\\') . '/' . $fileName;

    if (!@move_uploaded_file($file['tmp_name'], $destPath)) {
        if (!@copy($file['tmp_name'], $destPath)) {
            return [null, 'Failed to save the uploaded Result Copy to disk.'];
        }
        @unlink($file['tmp_name']);
    }

    return [$fileName, null];
}
}

// ─────────────────────────────────────────────────────────────────────────────
// inst_pass_percentage: multi-row handler (ATTS-UPERCENT)
// ─────────────────────────────────────────────────────────────────────────────
function inst_handle_pass_percentage(array $user, string $activeYear, \PDO $pdo, string $nav): bool
{
    $type = 'inst_pass_percentage';

    // Role-based Department Authorization:
    // Faculty and Coordinator are strictly locked to their authorized department.
    if (in_array($user['role'], ['Faculty', 'Coordinator'], true) && !empty($user['department'])) {
        $submittedDept = trim((string) ($_POST['department'] ?? ''));
        if ($submittedDept !== '' && !department_names_match($submittedDept, $user['department'])) {
            flash('error', 'You are not authorized to submit data for this department.');
            inst_redirect('/upload.php?type=' . $type);
            return false;
        }
        $_POST['department'] = $user['department'];
    }

    $dept = trim((string) ($_POST['department'] ?? ''));
    if ($dept === '') {
        flash('error', 'Department is required.');
        inst_redirect('/upload.php?type=' . $type);
        return false;
    }

    $errors = [];

    // Department-Specific Duration & Architecture rules:
    // Architecture has 5 academic years and semesters 1–10.
    // All other departments have 4 academic years and semesters 1–8.
    $isArch = is_architecture_dept($dept);
    $maxYears = $isArch ? 5 : 4;
    $maxSemesters = $isArch ? 10 : 8;

    // Examination Session Dropdown: exactly Apr/May or Nov/Dec
    $rawSession = trim((string) ($_POST['exam_session'] ?? ''));
    $sessionNorm = null;
    if (in_array($rawSession, ['Apr/May', 'Apr-May'], true)) {
        $sessionNorm = 'Apr/May';
    } elseif (in_array($rawSession, ['Nov/Dec', 'Nov-Dec'], true)) {
        $sessionNorm = 'Nov/Dec';
    }
    if ($sessionNorm === null) {
        $errors[] = 'Exam Session is required: choose Apr/May or Nov/Dec.';
    }

    // Academic Year
    $ay = trim((string) ($_POST['academic_year'] ?? $activeYear)) ?: $activeYear;
    if (!is_valid_academic_year($ay)) {
        $errors[] = 'Please select a valid academic year.';
    } elseif (is_future_academic_year($ay)) {
        $errors[] = "Academic year {$ay} has not started yet. Data for future academic years cannot be submitted.";
    } elseif ($user['role'] !== 'Admin' && academic_year_is_locked($ay)) {
        $errors[] = "Academic year {$ay} cycle is currently locked. Submissions are frozen.";
    }

    // Semester validation and session synchronization
    $semester = trim((string) ($_POST['semester'] ?? ''));
    if ($semester === '') {
        $errors[] = 'Semester is required.';
    } else {
        preg_match('/(\d+)/', $semester, $sm);
        $semNum = (int) ($sm[1] ?? 0);
        if ($semNum < 1 || $semNum > $maxSemesters) {
            $errors[] = "Invalid semester for {$dept}. Architecture supports semesters 1–10; other departments support semesters 1–8.";
        } elseif ($sessionNorm === 'Nov/Dec' && ($semNum % 2 !== 1)) {
            $errors[] = "Nov/Dec exam session only accepts odd semesters (Sem 1, Sem 3, Sem 5, Sem 7, Sem 9).";
        } elseif ($sessionNorm === 'Apr/May' && ($semNum % 2 !== 0)) {
            $errors[] = "Apr/May exam session only accepts even semesters (Sem 2, Sem 4, Sem 6, Sem 8, Sem 10).";
        }
    }

    // Decode Rows (Year-Wise University Results Table)
    $rowsJson = trim((string) ($_POST['pass_percentage_rows'] ?? '[]'));
    $rawRows = [];
    try {
        $decoded = json_decode($rowsJson, true, 10, JSON_THROW_ON_ERROR);
        if (is_array($decoded)) $rawRows = $decoded;
    } catch (\Throwable $e) {
        $errors[] = 'Row data is malformed. Please verify the table entries.';
    }

    if (empty($rawRows)) {
        $errors[] = 'At least one year result row is required.';
    }

    $validRows = [];
    foreach ($rawRows as $i => $row) {
        $sno = $i + 1;
        $yr = trim((string) ($row['class_year'] ?? $row['yr'] ?? ''));
        if ($yr === '') {
            $yr = ($i === 4 ? '5th Year' : (($i + 1) . (['st', 'nd', 'rd', 'th'][$i] ?? 'th') . ' Year'));
        }

        // Architecture check: 5th Year only allowed for Architecture
        if (!$isArch && (str_contains(strtolower($yr), '5') || str_contains(strtolower($yr), 'v year'))) {
            $errors[] = "Row {$sno} ({$yr}): 5th Year is only applicable for Architecture department.";
            continue;
        }

        $subCode = trim((string) ($row['subject_code'] ?? ''));
        $subName = trim((string) ($row['subject_name'] ?? ''));
        $facName = trim((string) ($row['faculty_name'] ?? ''));
        $facDept = trim((string) ($row['faculty_dept'] ?? $dept));

        if ($subCode === '') {
            $errors[] = "Row {$sno} ({$yr}): Subject Code is required.";
        }
        if ($subName === '') {
            $errors[] = "Row {$sno} ({$yr}): Subject Name is required.";
        }

        // Department and Faculty validation
        if ($facName !== '') {
            $fStmt = $pdo->prepare("SELECT id, name, department, role FROM users WHERE name = ? AND role IN ('Faculty', 'HoD', 'Coordinator') AND (status = 1 OR status = '1' OR status = 'Active') LIMIT 1");
            $fStmt->execute([$facName]);
            $foundFaculty = $fStmt->fetch(PDO::FETCH_ASSOC);
            if ($foundFaculty) {
                if (!department_names_match($foundFaculty['department'] ?? '', $facDept)) {
                    $errors[] = "Row {$sno} ({$yr}): Faculty member '{$facName}' is registered under department '{$foundFaculty['department']}', not '{$facDept}'.";
                }
            }
        }
        if (in_array($user['role'], ['Faculty', 'Coordinator'], true) && !empty($user['department'])) {
            if (!department_names_match($facDept, $user['department'])) {
                $errors[] = "Row {$sno} ({$yr}): You cannot assign faculty from another department ('{$facDept}').";
            }
        }

        // Numeric Registered count validation (text input)
        $rawTotal = trim((string) ($row['total_members'] ?? $row['reg'] ?? ''));
        $total = 0;
        if ($rawTotal === '' || !ctype_digit($rawTotal)) {
            $errors[] = "Row {$sno} ({$yr}): Registered count must be a non-negative integer.";
        } else {
            $total = (int) $rawTotal;
        }

        // Numeric Passed count validation (text input)
        $rawPassed = trim((string) ($row['passed_members'] ?? $row['passed'] ?? ''));
        $passed = 0;
        if ($rawPassed === '' || !ctype_digit($rawPassed)) {
            $errors[] = "Row {$sno} ({$yr}): Passed count must be a non-negative integer.";
        } else {
            $passed = (int) $rawPassed;
        }

        // Passed cannot exceed Registered
        if ($rawTotal !== '' && ctype_digit($rawTotal) && $rawPassed !== '' && ctype_digit($rawPassed)) {
            if ($passed > $total) {
                $errors[] = "Row {$sno} ({$yr}): Passed count ({$passed}) cannot exceed registered count ({$total}).";
            }
        }

        // Automatic Calculation: Failed = Registered - Passed (never negative)
        $failed = ($total >= 0 && $passed >= 0) ? max(0, $total - $passed) : 0;

        // Automatic Calculation: Pass Percentage = (Passed / Registered) * 100
        $pct = ($total > 0 && $passed >= 0) ? round(($passed / $total) * 100, 2) : 0.00;

        $validRows[] = [
            'class_year'      => $yr,
            'semester'        => $semester,
            'subject_code'    => $subCode,
            'subject_name'    => $subName,
            'faculty_name'    => $facName,
            'faculty_dept'    => $facDept,
            'total_members'   => max(0, $total),
            'passed_members'  => max(0, $passed),
            'failed_members'  => $failed,
            'pass_percentage' => $pct,
            'sort_order'      => $sno,
        ];
    }

    // Result Copy proof attachment (supports PDF/PNG, max 4 MB)
    [$proofStored, $proofError] = save_result_copy_proof($_FILES['proof'] ?? null, false);
    if ($proofError) {
        $errors[] = $proofError;
    }

    if (!empty($errors)) {
        flash('error', implode('<br>', $errors));
        inst_redirect('/upload.php?type=' . $type);
        return false;
    }

    // Summary calculations
    $totalSubjects = count($validRows);
    $totalStudents = array_sum(array_column($validRows, 'total_members'));
    $totalPassed   = array_sum(array_column($validRows, 'passed_members'));
    $overallPct    = $totalStudents > 0 ? round(($totalPassed / $totalStudents) * 100, 2) : 0.00;
    $programmeLabel = $dept . ' - ' . $semester . ' (' . $sessionNorm . ')';

    try {
        $pdo->beginTransaction();

        // Check if an existing record is being edited or already exists for this unique combination
        $editId = (int) input('edit_id');
        if ($editId <= 0) {
            $chkStmt = $pdo->prepare("SELECT id, proof_file FROM inst_pass_percentage WHERE department = ? AND academic_year = ? AND exam_session = ? AND semester = ? LIMIT 1");
            $chkStmt->execute([$dept, $ay, $sessionNorm, $semester]);
            $existing = $chkStmt->fetch(PDO::FETCH_ASSOC);
            if ($existing) {
                $editId = (int) $existing['id'];
                if (!$proofStored && !empty($existing['proof_file'])) {
                    $proofStored = $existing['proof_file'];
                }
            }
        }

        if ($editId > 0) {
            // Update parent row
            $updateSql = "UPDATE inst_pass_percentage SET
                academic_year = ?, department = ?, programme = ?, semester = ?, exam_session = ?,
                total_subjects = ?, total_students = ?, total_passed = ?, overall_pass_percentage = ?,
                proof_file = COALESCE(?, proof_file), status = 'Submitted', updated_at = NOW()
                WHERE id = ?";
            $pdo->prepare($updateSql)->execute([
                $ay, $dept, $programmeLabel, $semester, $sessionNorm,
                $totalSubjects, $totalStudents, $totalPassed, $overallPct,
                $proofStored, $editId,
            ]);
            $parentId = $editId;

            // Delete old child rows
            $pdo->prepare("DELETE FROM inst_pass_percentage_rows WHERE pass_percentage_id = ?")->execute([$parentId]);
        } else {
            // Insert parent row
            $parentSql = "INSERT INTO inst_pass_percentage
                (academic_year, department, programme, class_year, semester, exam_session,
                 total_subjects, total_students, total_passed, overall_pass_percentage,
                 proof_file, status, created_by, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Submitted', ?, NOW())";
            $pdo->prepare($parentSql)->execute([
                $ay, $dept, $programmeLabel, 'All Years', $semester, $sessionNorm,
                $totalSubjects, $totalStudents, $totalPassed, $overallPct,
                $proofStored, (int) $user['id'],
            ]);
            $parentId = (int) $pdo->lastInsertId();
        }

        // Insert Child Rows
        $childSql = "INSERT INTO inst_pass_percentage_rows
            (pass_percentage_id, class_year, semester, subject_code, subject_name,
             faculty_name, faculty_dept, total_members, passed_members, failed_members, pass_percentage, sort_order)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $childStmt = $pdo->prepare($childSql);
        foreach ($validRows as $r) {
            $childStmt->execute([
                $parentId,
                $r['class_year'], $r['semester'], $r['subject_code'], $r['subject_name'],
                $r['faculty_name'], $r['faculty_dept'],
                $r['total_members'], $r['passed_members'], $r['failed_members'], $r['pass_percentage'],
                $r['sort_order'],
            ]);
        }

        $pdo->commit();

        if (function_exists('record_workflow_audit')) {
            $action = $editId > 0 ? 'FACULTY_UPDATED' : 'FACULTY_SUBMITTED';
            record_workflow_audit($type, $parentId, $action, $user, null, 'Submitted',
                "University Pass Percentage submitted for {$dept} {$semester} ({$sessionNorm})", null, $dept, $ay);
        }

        flash('success', "University Pass Percentage submitted successfully — {$totalSubjects} year rows saved (Overall Pass %: {$overallPct}%).");
        $_SESSION['submitted_draft_type'] = $type;
        if ($nav === 'next') {
            $nextSec = upload_section_next('upercent');
            if ($nextSec) {
                inst_redirect('/upload.php?section=' . $nextSec['id'] . '&type=' . $nextSec['default_type']);
                return true;
            }
        }
        inst_redirect('/upload.php?section=upercent&type=' . $type);
        return true;
    } catch (\PDOException $e) {
        $pdo->rollBack();
        if ($proofStored) @unlink(rtrim(UPLOAD_DIR, '/\\') . '/' . $proofStored);
        error_log('inst_pass_percentage insert failed: ' . $e->getMessage());
        flash('error', 'Save failed: ' . (defined('APP_DEBUG') && APP_DEBUG ? $e->getMessage() : 'database error.'));
        inst_redirect('/upload.php?section=upercent&type=' . $type);
        return false;
    }

    inst_redirect('/upload.php?type=' . $type);
    return true;
}

// ─────────────────────────────────────────────────────────────────────────────
// Required fields map for institutional types
// ─────────────────────────────────────────────────────────────────────────────
function inst_required_fields(): array
{
    return [
        'inst_college_rank'        => ['student_name' => 'Student Name', 'reg_no' => 'Register Number', 'rank_val' => 'Rank'],
        'inst_student_rank'        => ['student_name' => 'Student Name', 'reg_no' => 'Register Number', 'university_rank' => 'University Rank'],
        'inst_student_cgpa'        => ['class_year' => 'Class / Year', 'semester' => 'Semester', 'students_above_8_5' => 'Number of Students Secured Above 8.5 CGPA'],
        'inst_placement_mnc'       => ['reg_no' => 'Register Number', 'student_name' => 'Student Name', 'company_name' => 'Company Name', 'placement_status' => 'Placement Status'],
        'inst_publications'        => ['faculty_name' => 'Faculty Name', 'title' => 'Title', 'journal_name' => 'Journal / Publication Name', 'publication_type' => 'Publication Type'],
        'inst_books'               => ['faculty_name' => 'Faculty Name', 'book_title' => 'Book Title', 'publisher' => 'Publisher', 'isbn' => 'ISBN'],
        'inst_book_chapters'       => ['faculty_name' => 'Faculty Name', 'chapter_title' => 'Chapter Title', 'book_title' => 'Book Title', 'publisher' => 'Publisher', 'isbn' => 'ISBN'],
        'inst_patents_published'   => ['faculty_name' => 'Faculty Name', 'title' => 'Title', 'patent_number' => 'Patent / Design Number', 'patent_type' => 'Type'],
        'inst_patents_granted'     => ['faculty_name' => 'Faculty Name', 'title' => 'Title', 'patent_number' => 'Patent Number', 'grant_date' => 'Grant Date', 'granting_authority' => 'Granting Authority'],
        'inst_copyrights'          => ['faculty_name' => 'Faculty Name', 'title' => 'Title / Work', 'copyright_number' => 'Copyright Number', 'registration_date' => 'Registration Date'],
        'inst_sponsored_research'  => ['faculty_name' => 'Faculty Name / PI', 'project_title' => 'Project Title', 'funding_agency' => 'Funding Agency', 'project_amount' => 'Project Amount (Lakhs)'],
        'inst_consultancy'         => ['faculty_name' => 'Faculty Name', 'project_title' => 'Project Title', 'client_org' => 'Client / Organization', 'project_amount' => 'Project Amount (Lakhs)'],
        'inst_research_centre'     => ['recognition_name' => 'Recognition Name', 'recognizing_authority' => 'Recognizing Authority'],
        'inst_ipr_programmes'      => ['programme_type' => 'Programme Type', 'programme_title' => 'Programme Title'],
        'inst_faculty_certifications' => ['faculty_name' => 'Faculty Name', 'course_name' => 'Course Name', 'platform' => 'Platform / Provider'],
        'inst_mou_interactions'    => ['industry_name' => 'Industry Name', 'interaction_type' => 'Type'],
        'inst_internships'         => ['student_name' => 'Student Name', 'reg_no' => 'Register Number', 'company' => 'Company / Industry', 'start_date' => 'Internship Start Date', 'end_date' => 'Internship End Date'],
        'inst_summer_trainings'    => ['student_name' => 'Student Name', 'reg_no' => 'Register Number', 'organization' => 'Organization', 'start_date' => 'Start Date', 'end_date' => 'End Date'],
        'inst_student_projects'    => ['student_name' => 'Student Name', 'reg_no' => 'Register Number', 'project_title' => 'Project Title', 'youtube_url' => 'YouTube URL'],
        'inst_faculty_participations' => ['faculty_name' => 'Faculty Name', 'programme_type' => 'Programme Type', 'programme_title' => 'Programme Title'],
        'inst_society_memberships' => ['faculty_name' => 'Faculty Name', 'society_name' => 'Professional Society', 'membership_number' => 'Membership Number'],
        'inst_newsletters'         => ['title' => 'Newsletter Title', 'volume' => 'Volume', 'issue' => 'Issue'],
        'inst_student_certifications' => ['student_name' => 'Student Name', 'reg_no' => 'Register Number', 'course_name' => 'Course Name'],
        'inst_nss_events'          => ['event_name' => 'Event Name', 'event_date' => 'Date', 'venue' => 'Venue'],
        'inst_inter_inst_within'   => [
            'student_name'       => 'Student Name',
            'reg_no'             => 'Register Number',
            'event_name'         => 'Event Name',
            'host_institution'   => 'Host Institution',
            'event_level'        => 'Event Level',
            'location'           => 'Location / City',
            'event_date'         => 'Event Date',
            'participation_type' => 'Participation Type',
        ],
        'inst_inter_inst_outside'  => ['student_name' => 'Student Name', 'reg_no' => 'Register Number', 'event_name' => 'Event Name', 'host_institution' => 'Host Institution', 'state' => 'State'],
        'inst_inter_inst_awards'   => ['student_name' => 'Student Name', 'reg_no' => 'Register Number', 'event_name' => 'Event Name', 'award' => 'Award / Medal'],
        'inst_value_added_courses' => ['course_name' => 'Course Name', 'organizer' => 'Organizer'],
        'inst_sports_state'        => ['student_name' => 'Student Name', 'reg_no' => 'Register Number', 'sport' => 'Sport', 'event_name' => 'Event Name', 'level_secured' => 'Competition Level', 'award' => 'Medal', 'event_date' => 'Event Date', 'venue' => 'Venue'],
        'inst_sports_national'     => ['student_name' => 'Student Name', 'reg_no' => 'Register Number', 'sport' => 'Sport', 'event_name' => 'Event Name', 'level_secured' => 'Competition Level', 'award' => 'Medal', 'event_date' => 'Event Date', 'venue' => 'Venue'],

        'inst_innovation_events'   => ['event_name' => 'Event Name', 'event_date' => 'Date', 'coordinator' => 'Coordinator'],
        'inst_iic_activities'      => ['activity_name' => 'Activity Name', 'activity_date' => 'Date'],
        'inst_website_updations'   => ['update_title' => 'Update Title', 'page_section' => 'Page / Section Updated', 'updated_by' => 'Updated By', 'update_date' => 'Update Date'],
        'inst_google_ratings'      => ['google_rating' => 'Google Rating (0-5)', 'measurement_date' => 'Measurement Date'],
        'inst_startups'            => ['startup_name' => 'Startup Name', 'founders' => 'Founder(s)'],
        'inst_alumni_chapters'     => ['chapter_name' => 'Chapter Name', 'coordinator' => 'Coordinator'],
        'inst_awards_recognitions' => ['person_name' => 'Person Name', 'recognition_type' => 'Recognition Type', 'title' => 'Title / Award'],
        'inst_spoken_tutorials'    => ['student_name' => 'Student Name', 'reg_no' => 'Register Number', 'course_name' => 'Course Name'],
    ];
}

} // end if (!function_exists)
