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
    try {
        $fStmt = $pdo->prepare("SELECT name, department FROM users WHERE role IN ('Faculty', 'HoD', 'Coordinator') AND (department = ? OR department IS NOT NULL) AND status = 'Active' ORDER BY name ASC");
        $fStmt->execute([$dept]);
        $allFac = $fStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($allFac as $f) {
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
        'subjects'          => $knownSubjects,
        'current_user_name' => $user['name'] ?? '',
        'current_user_dept' => $user['department'] ?? $dept,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
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

    // ── Generic institutional handler ────────────────────────────────────────
    $requiredMap = inst_required_fields();
    $expected    = $requiredMap[$type] ?? [];

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
            if ($val !== '' && strtotime($val) === false) {
                $errors[] = "{$fLabel} must be a valid date.";
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

    // inst_student_cgpa: validate CGPA > 7.5 matches eligibility flag
    if ($type === 'inst_student_cgpa') {
        $cgpa = (float) trim((string) ($_POST['cgpa'] ?? '0'));
        $elig = trim((string) ($_POST['eligibility'] ?? ''));
        if ($cgpa > 0) {
            if ($cgpa > 7.5 && str_contains($elig, 'Not')) {
                $errors[] = 'CGPA is above 7.5 but Eligibility is set to Not Eligible — contradiction.';
            }
            if ($cgpa <= 7.5 && !str_contains($elig, 'Not')) {
                $errors[] = 'CGPA is 7.5 or below but Eligibility is set to Eligible — contradiction.';
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

    // Handle proof upload
    [$proofStored, $proofError] = save_upload_proof($_FILES['proof'] ?? null, false);
    if ($proofError !== null) {
        $errors[] = $proofError;
    }

    if (!empty($errors)) {
        flash('error', implode('<br>', $errors));
        redirect('/upload.php?type=' . $type);
    }

    // ── INSERT ────────────────────────────────────────────────────────────────
    $table = $types[$type]['table'];
    try {
        $protected    = ['id', 'created_by', 'status', 'approved_by', 'review_remark', 'created_at', 'updated_at', 'academic_year'];
        $tableColumns = $pdo->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_COLUMN);
        $allowed      = array_diff($tableColumns, $protected);

        $fields = ['created_by', 'status', 'academic_year'];
        $values = [(int) $user['id'], 'Submitted', $activeYear];
        if (in_array('created_at', $tableColumns, true)) {
            $fields[] = 'created_at';
            $values[] = date('Y-m-d H:i:s');
        }

        if ($type === 'inst_awards_recognitions') {
            if (!empty($_POST['title']) && empty($_POST['title_award'])) {
                $_POST['title_award'] = $_POST['title'];
            }
            if (!empty($_POST['other_details']) && empty($_POST['remarks'])) {
                $_POST['remarks'] = $_POST['other_details'];
            }
        }

        foreach ($_POST as $k => $v) {
            if (!in_array($k, $allowed, true) || trim((string) $v) === '') continue;
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

        // Workflow audit
        if (function_exists('record_workflow_audit')) {
            record_workflow_audit($type, $newId, 'FACULTY_SUBMITTED', $user, null, 'Submitted',
                'Institutional record submitted', null,
                $_POST['department'] ?? ($user['department'] ?? null), $activeYear);
        }

        flash('success', $types[$type]['label'] . ' submitted for review.');
        $_SESSION['submitted_draft_type'] = $type;

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

    // Semester validation
    $semester = trim((string) ($_POST['semester'] ?? ''));
    if ($semester === '') {
        $errors[] = 'Semester is required.';
    } else {
        preg_match('/(\d+)/', $semester, $sm);
        $semNum = (int) ($sm[1] ?? 0);
        if ($semNum < 1 || $semNum > $maxSemesters) {
            $errors[] = "Invalid semester for {$dept}. Architecture supports semesters 1–10; other departments support semesters 1–8.";
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

        // Numeric Registered count validation
        $rawTotal = $row['total_members'] ?? $row['reg'] ?? null;
        $total = 0;
        if ($rawTotal === null || $rawTotal === '' || !is_numeric($rawTotal)) {
            $errors[] = "Row {$sno} ({$yr}): Registered count must be a non-negative integer.";
        } else {
            $total = (int) $rawTotal;
            if ($total < 0) {
                $errors[] = "Row {$sno} ({$yr}): Registered count must be a non-negative integer.";
            }
        }

        // Numeric Passed count validation
        $rawPassed = $row['passed_members'] ?? $row['passed'] ?? null;
        $passed = 0;
        if ($rawPassed === null || $rawPassed === '' || !is_numeric($rawPassed)) {
            $errors[] = "Row {$sno} ({$yr}): Passed count must be a non-negative integer.";
        } else {
            $passed = (int) $rawPassed;
            if ($passed < 0) {
                $errors[] = "Row {$sno} ({$yr}): Passed count must be a non-negative integer.";
            }
        }

        // Passed cannot exceed Registered
        if ($total >= 0 && $passed >= 0 && $passed > $total) {
            $errors[] = "Row {$sno} ({$yr}): Passed count ({$passed}) cannot exceed registered count ({$total}).";
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

    // Optional proof attachment
    [$proofStored, $proofError] = save_upload_proof($_FILES['proof'] ?? null, false);
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
        'inst_student_cgpa'        => ['reg_no' => 'Register Number', 'student_name' => 'Student Name', 'class_year' => 'Class / Year', 'semester' => 'Semester', 'cgpa' => 'CGPA'],
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
        'inst_inter_inst_within'   => ['student_name' => 'Student Name', 'reg_no' => 'Register Number', 'event_name' => 'Event Name', 'host_institution' => 'Host Institution'],
        'inst_inter_inst_outside'  => ['student_name' => 'Student Name', 'reg_no' => 'Register Number', 'event_name' => 'Event Name', 'host_institution' => 'Host Institution', 'state' => 'State'],
        'inst_inter_inst_awards'   => ['student_name' => 'Student Name', 'reg_no' => 'Register Number', 'event_name' => 'Event Name', 'award' => 'Award / Medal'],
        'inst_value_added_courses' => ['course_name' => 'Course Name', 'organizer' => 'Organizer'],
        'inst_sports_state'        => ['student_name' => 'Student Name', 'reg_no' => 'Register Number', 'sport' => 'Sport', 'event_name' => 'Event Name'],
        'inst_sports_national'     => ['student_name' => 'Student Name', 'reg_no' => 'Register Number', 'sport' => 'Sport', 'event_name' => 'Event Name'],
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
