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

if (!function_exists('inst_handle_post')) {

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
        redirect('/upload.php?type=' . $type);
    }

    $typeKeys = array_keys($types);
    $nav = (string) input('nav', 'add');
    if ($nav === 'add') {
        redirect('/upload.php?type=' . $type);
    }
    redirect('/upload.php?type=' . $type);
}

// ─────────────────────────────────────────────────────────────────────────────
// inst_pass_percentage: multi-row handler
// ─────────────────────────────────────────────────────────────────────────────
function inst_handle_pass_percentage(array $user, string $activeYear, \PDO $pdo, string $nav): bool
{
    $type = 'inst_pass_percentage';

    // Force dept for Faculty
    if ($user['role'] === 'Faculty' && !empty($user['department'])) {
        $_POST['department'] = $user['department'];
    }

    $programme = trim((string) ($_POST['programme'] ?? ''));
    $classYear = trim((string) ($_POST['class_year'] ?? ''));
    $semester  = trim((string) ($_POST['semester'] ?? ''));
    $dept      = trim((string) ($_POST['department'] ?? ''));
    $errors    = [];

    if ($dept === '')      $errors[] = 'Department is required.';
    if ($programme === '') $errors[] = 'Programme / Course is required.';
    if ($classYear === '') $errors[] = 'Class / Year is required.';
    if ($semester === '')  $errors[] = 'Semester is required.';

    // Rows come from JSON-encoded hidden field
    $rowsJson = trim((string) ($_POST['pass_percentage_rows'] ?? '[]'));
    $rows = [];
    try {
        $decoded = json_decode($rowsJson, true, 10, JSON_THROW_ON_ERROR);
        if (is_array($decoded)) $rows = $decoded;
    } catch (\Throwable $e) {
        $errors[] = 'Row data is malformed. Please add rows again.';
    }

    if (empty($rows)) {
        $errors[] = 'At least one subject row is required.';
    }

    // Validate & calculate each row
    $validRows = [];
    foreach ($rows as $i => $row) {
        $sno      = $i + 1;
        $subjCode = trim((string) ($row['subject_code'] ?? ''));
        $subjName = trim((string) ($row['subject_name'] ?? ''));
        $total    = (int) ($row['total_members'] ?? 0);
        $passed   = (int) ($row['passed_members'] ?? 0);
        if ($subjCode === '') { $errors[] = "Row {$sno}: Subject Code required."; continue; }
        if ($subjName === '') { $errors[] = "Row {$sno}: Subject Name required."; continue; }
        if ($total < 1) { $errors[] = "Row {$sno}: Total Members must be ≥ 1."; continue; }
        if ($passed < 0 || $passed > $total) { $errors[] = "Row {$sno}: Passed Members must be 0–{$total}."; continue; }
        $pct = round(($passed / $total) * 100, 2);
        $validRows[] = [
            'class_year'     => $classYear,
            'semester'       => $semester,
            'subject_code'   => $subjCode,
            'subject_name'   => $subjName,
            'reg_no'         => trim((string) ($row['reg_no'] ?? '')),
            'student_name'   => trim((string) ($row['student_name'] ?? '')),
            'pass_status'    => trim((string) ($row['pass_status'] ?? 'Pass')),
            'total_members'  => $total,
            'passed_members' => $passed,
            'pass_percentage'=> $pct,
            'sort_order'     => $i,
        ];
    }

    [$proofStored, $proofError] = save_upload_proof($_FILES['proof'] ?? null, false);
    if ($proofError) $errors[] = $proofError;

    if (!empty($errors)) {
        flash('error', implode('<br>', $errors));
        redirect('/upload.php?type=' . $type);
    }

    // Summary
    $totalSubjects = count($validRows);
    $totalStudents = $totalPassed = 0;
    foreach ($validRows as $r) {
        $totalStudents += $r['total_members'];
        $totalPassed   += $r['passed_members'];
    }
    $overallPct = $totalStudents > 0 ? round(($totalPassed / $totalStudents) * 100, 2) : 0;

    try {
        $pdo->beginTransaction();

        // Parent row
        $parentSql = "INSERT INTO inst_pass_percentage
            (academic_year, department, programme, class_year, semester,
             total_subjects, total_students, total_passed, overall_pass_percentage,
             proof_file, status, created_by, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Submitted', ?, NOW())";
        $pdo->prepare($parentSql)->execute([
            $activeYear, $dept, $programme, $classYear, $semester,
            $totalSubjects, $totalStudents, $totalPassed, $overallPct,
            $proofStored, (int) $user['id'],
        ]);
        $parentId = (int) $pdo->lastInsertId();

        // Child rows
        $childSql = "INSERT INTO inst_pass_percentage_rows
            (pass_percentage_id, class_year, semester, subject_code, subject_name,
             reg_no, student_name, pass_status, total_members, passed_members, pass_percentage, sort_order)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $childStmt = $pdo->prepare($childSql);
        foreach ($validRows as $r) {
            $childStmt->execute([
                $parentId,
                $r['class_year'], $r['semester'], $r['subject_code'], $r['subject_name'],
                $r['reg_no'], $r['student_name'], $r['pass_status'],
                $r['total_members'], $r['passed_members'], $r['pass_percentage'], $r['sort_order'],
            ]);
        }

        $pdo->commit();

        if (function_exists('record_workflow_audit')) {
            record_workflow_audit($type, $parentId, 'FACULTY_SUBMITTED', $user, null, 'Submitted',
                'University Pass Percentage submitted', null, $dept, $activeYear);
        }

        flash('success', 'University Pass Percentage submitted — ' . $totalSubjects . ' subject rows saved.');
        $_SESSION['submitted_draft_type'] = $type;
    } catch (\PDOException $e) {
        $pdo->rollBack();
        if ($proofStored) @unlink(rtrim(UPLOAD_DIR, '/\\') . '/' . $proofStored);
        error_log('inst_pass_percentage insert failed: ' . $e->getMessage());
        flash('error', 'Save failed: ' . (defined('APP_DEBUG') && APP_DEBUG ? $e->getMessage() : 'database error.'));
        redirect('/upload.php?type=' . $type);
    }

    redirect('/upload.php?type=' . $type);
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
