<?php
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/record_specs.php';
require_once __DIR__ . '/models/Record.php';
require_once __DIR__ . '/models/Department.php';
require_once __DIR__ . '/models/Target.php';   
require_once __DIR__ . '/models/ExecutiveMeeting.php';   
require_once __DIR__ . '/models/UploadFlow.php';         
require_once __DIR__ . '/inc/compression.php';
require_once __DIR__ . '/inc/inst_upload_handler.php'; // Institutional achievement handler

$user = require_role(['Admin', 'HoD', 'Coordinator', 'Faculty']);
require_module('upload');
$types       = record_types();
$departments = departments_all();
$years       = academic_years();
$typeKeys    = array_keys($types);
$activeYear  = active_academic_year();

if (!defined('PROOF_MAX_BYTES')) {
    define('PROOF_MAX_BYTES', 2 * 1024 * 1024);   
}

if (!function_exists('save_upload_proof')) {function save_upload_proof(?array $file, bool $required = false): array
{
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        if ($required) {
            return [null, 'Proof / Attachment is required. Please upload a PDF file (2 MB or less).'];
        }
        return [null, null];
    }

    $errorCode = $file['error'] ?? UPLOAD_ERR_OK;
    if ($errorCode !== UPLOAD_ERR_OK) {
        return match ($errorCode) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => [null, 'The uploaded proof exceeds the 2 MB size limit. Please upload a smaller file.'],
            UPLOAD_ERR_PARTIAL   => [null, 'The file was only partially uploaded. Please try again.'],
            UPLOAD_ERR_NO_TMP_DIR => [null, 'Server configuration error: missing temporary folder.'],
            UPLOAD_ERR_CANT_WRITE => [null, 'Server error: failed to write file to disk.'],
            UPLOAD_ERR_EXTENSION  => [null, 'A server extension stopped the file upload.'],
            default               => [null, 'File upload failed. Please try again.'],
        };
    }

    if (!is_uploaded_file($file['tmp_name'])) {
        return [null, 'The proof could not be uploaded (invalid temporary file).'];
    }

    // PDF only, by extension and by actual content.
    $ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
    if ($ext !== 'pdf') {
        return [null, 'The proof must be a PDF file (.pdf).'];
    }

    if ($file['size'] > PROOF_MAX_BYTES) {
        return [null, 'The PDF is larger than 2 MB. Please upload a smaller one.'];
    }

    // Validate MIME type and binary header magic bytes (%PDF-)
    $finfo = @finfo_open(FILEINFO_MIME_TYPE);
    $mime = $finfo ? (string) @finfo_file($finfo, $file['tmp_name']) : (function_exists('mime_content_type') ? (string) @mime_content_type($file['tmp_name']) : '');
    if ($finfo && PHP_VERSION_ID < 80500) {
        @finfo_close($finfo);    }
    if ($mime !== '' && stripos($mime, 'pdf') === false && stripos($mime, 'octet-stream') === false) {
        return [null, 'That file is not a valid PDF document.'];
    }

    $handle = @fopen($file['tmp_name'], 'rb');
    $header = $handle ? fread($handle, 4) : '';
    if ($handle) {
        fclose($handle);
    }
    if ($header !== '%PDF') {
        return [null, 'That file is not a valid PDF document.'];
    }

    $baseFolder = rtrim(UPLOAD_DIR, '/\\');
    if (!is_dir($baseFolder)) {
        @mkdir($baseFolder, 0775, true);
    }
    $proofsFolder = $baseFolder . '/proofs';
    if (!is_dir($proofsFolder)) {
        @mkdir($proofsFolder, 0775, true);
    }

    $uniqueId = bin2hex(random_bytes(8));
    $timestamp = time();
    $stored = "record_{$uniqueId}_{$timestamp}.pdf";

    $destPath = $baseFolder . '/' . $stored;

    // FEAT-13: Server-side Automatic File Storage Compression
    $compressResult = compress_uploaded_proof($file['tmp_name'], $ext);
    $finalSource    = $compressResult['path'];

    $saved = false;
    if (!empty($compressResult['is_temp']) && is_file($finalSource)) {
        $saved = @copy($finalSource, $destPath);
        compression_cleanup($compressResult);
    } else {
        $saved = move_uploaded_file($file['tmp_name'], $destPath);
    }

    if (!$saved || !file_exists($destPath)) {
        return [null, 'The proof could not be saved to the upload directory.'];
    }

    @copy($destPath, $proofsFolder . '/' . $stored);

    return [$stored, null];
}
}

$uploadFlow = null;   
if (upload_flow_applies($user)) {
    $flowEditId   = (int) ($_GET['edit_id'] ?? $_POST['edit_id'] ?? 0);
    $flowEditType = (string) ($_GET['type'] ?? $_POST['record_type'] ?? '');
    if ($flowEditId > 0 && $flowEditType !== '') {
        require_once __DIR__ . '/models/EditRequest.php';
        $flowEditRec = edit_request_original_record($flowEditType, $flowEditId);
        if ($flowEditRec) {
            $recYear = $flowEditRec['academic_year'] ?? active_academic_year();
            $recDt   = upload_flow_data_type_of($flowEditType);
            upload_flow_store($user, ['year' => $recYear, 'data_type' => $recDt]);
        }
    }

    if (isset($_GET['reset']) || isset($_GET['new'])) {
        upload_flow_store($user, ['year' => null, 'data_type' => null]);
    } elseif (($_GET['step'] ?? '') === 'year') {
        upload_flow_store($user, ['data_type' => null]);
    }

    $isPost        = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
    $isFacultyHome = upload_flow_is_faculty($user);
    if ($isPost && isset($_POST['upload_flow_step'])) {
        csrf_check();
    }
    $flowState = upload_flow_state($user);

    if ($isPost && isset($_POST['upload_flow_step'])) {
        if ($_POST['upload_flow_step'] === 'year') {
            [$ok, $error] = upload_flow_choose_year($user, $_POST['academic_year'] ?? '');
            if (!$ok) {
                flash('error', $error);
                redirect('/upload.php');
            }
            $backType = (string) ($_POST['return_type'] ?? '');
            if ($backType !== '' && isset($types[$backType])) {
                redirect('/upload.php?type=' . urlencode($backType));
            }
            if ($isFacultyHome) {
                redirect('/upload.php?type=' . urlencode(array_keys($types)[0] ?? 'journal'));
            }
            // Academic year and data type are chosen on the same page.
            [$ok, $error] = upload_flow_choose_data_type($user, $_POST['data_type'] ?? 'student');
            if (!$ok) {
                flash('error', $error);
                redirect('/upload.php');
            }
            $chosen = upload_flow_data_types()[upload_flow_state($user)['data_type']];
            redirect('/upload.php?type=' . urlencode($chosen['types'][0]));
        }
        if ($_POST['upload_flow_step'] === 'data_type') {
            [$ok, $error] = upload_flow_choose_data_type($user, $_POST['data_type'] ?? '');
            if (!$ok) {
                flash('error', $error);
                redirect($flowState['year'] === null ? '/upload.php' : '/upload.php?step=data-type');
            }
            $chosen = upload_flow_data_types()[upload_flow_state($user)['data_type']];
            redirect('/upload.php?type=' . urlencode($chosen['types'][0]));
        }
        redirect('/upload.php');
    }

    if (!$isPost && (!isset($_GET['type']) || isset($_GET['reset']) || isset($_GET['step']))) {
        // Faculty and Coordinators go straight to the upload form; the academic
        // year is picked from the selector inside the form page.
        if ($flowState['year'] === null) {
            if ($flowState['stale_year'] !== null) {
                flash('error', "Academic year {$flowState['stale_year']} is no longer open for uploads. Switched to the current academic year.");
            }
            $homeYear = in_array($activeYear, upload_flow_years(), true) ? $activeYear : (upload_flow_years()[0] ?? $activeYear);
            upload_flow_store($user, ['year' => $homeYear, 'data_type' => null]);
        }
        if ($isFacultyHome) {
            $firstType = array_keys($types)[0] ?? 'journal';
            redirect('/upload.php?type=' . urlencode($firstType));
        }
        upload_flow_remember_return($user);
        upload_flow_store($user, ['data_type' => 'student']);
        $studentTypes = upload_flow_data_types()['student']['types'];
        redirect('/upload.php?type=' . urlencode($studentTypes[0] ?? 'internship'));
    }

    $directType = (string) ($isPost ? ($_POST['record_type'] ?? '') : ($_GET['type'] ?? ''));
    if ($directType !== '' && isset($types[$directType])) {
        if ($flowState['year'] === null) {
            $flowState['year'] = $activeYear;
            upload_flow_store($user, ['year' => $activeYear]);
        }
        $dType = upload_flow_data_type_of($directType);
        if ($dType !== null && $flowState['data_type'] === null) {
            $flowState['data_type'] = $dType;
            upload_flow_store($user, ['data_type' => $dType]);
        }
    }

    if ($flowState['year'] === null) {
        $flowState['year'] = $activeYear;
        upload_flow_store($user, ['year' => $activeYear]);
    }

    $flowDefs = upload_flow_data_types();
    if ($flowState['data_type'] !== null && isset($flowDefs[$flowState['data_type']])) {
        $uploadFlow = $flowState + [
            'label' => $flowDefs[$flowState['data_type']]['label'],
            'icon'  => $flowDefs[$flowState['data_type']]['icon'],
        ];
        $typeKeys = $flowDefs[$flowState['data_type']]['types'];
    } else {
        $uploadFlow = [
            'year'      => $flowState['year'],
            'data_type' => null,
            'label'     => null,
            'icon'      => 'folder',
            'return_to' => $flowState['return_to'] ?? null,
        ];
        $typeKeys = array_keys($types);
    }

    $askedType = (string) ($isPost ? ($_POST['record_type'] ?? '') : ($_GET['type'] ?? ''));
    if ($askedType !== '' && isset($types[$askedType])) {
        if (!in_array($askedType, $typeKeys, true)) {
            $newDType = upload_flow_data_type_of($askedType);
            upload_flow_store($user, ['data_type' => $newDType]);
            $flowState['data_type'] = $newDType;
            if ($newDType !== null && isset($flowDefs[$newDType])) {
                $uploadFlow['data_type'] = $newDType;
                $uploadFlow['label']     = $flowDefs[$newDType]['label'];
                $uploadFlow['icon']      = $flowDefs[$newDType]['icon'];
                $typeKeys                = $flowDefs[$newDType]['types'];
            } else {
                $uploadFlow['data_type'] = null;
                $uploadFlow['label']     = null;
                $uploadFlow['icon']      = 'folder';
                $typeKeys                = array_keys($types);
            }
        }
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {    csrf_check();
    $type = (string) input('record_type');
    $nav  = (string) input('nav', 'add');   

    $targetYear = trim((string) input('academic_year', $activeYear));
    if ($user['role'] !== 'Admin' && (academic_year_is_locked($activeYear) || academic_year_is_locked($targetYear))) {
        flash('error', "Academic year {$targetYear} cycle is currently locked by the Administrator. New record submissions for {$targetYear} are frozen.");
        redirect('/upload.php' . ($type ? '?type=' . urlencode($type) : ''));
    }

    $meetingParam = trim((string) input('meeting', input('em', '')));
    if ($emBlock = em_submission_block_reason($user['role'], $targetYear, null, $meetingParam ?: null)) {
        flash('error', $emBlock);
        redirect('/upload.php' . ($type ? '?type=' . urlencode($type) : ''));
    }

    if (!isset($types[$type])) {
        flash('error', 'Invalid record type.');
        redirect('/upload.php');
    }

    foreach ($_POST as $k => $v) {
        if (substr($k, -6) === '_other') {
            $base = substr($k, 0, -6);
            if (($_POST[$base] ?? '') === 'Others' && trim((string) $v) !== '') {
                $_POST[$base] = trim((string) $v);
            }
        }
    }

    // Server-Side Validation: Every user-editable field is mandatory
    $requiredMap = [
        // Authors, title, journal, type, ISSN, volume, date and DOI are
        // checked by journal_prepare_submission(); the links here.
        'journal' => [
            'journal_link' => 'Link to Journal Website',
            'document_link' => 'Document Link',
        ],
        // Authors, category, title, publisher, ISBN, DOI and date are checked
        // by book_prepare_submission(); the link here.
        'book' => [
            'document_link' => 'Document Link',
        ],
        'conference' => [
            'faculty_name' => 'Faculty Name',
            'department' => 'Department',
            'academic_year' => 'Academic Year',
            'author_type' => 'Author Type',
            'paper_title' => 'Paper Title',
            'conference_name' => 'Conference Name',
            'conference_type' => 'Type',
            'venue' => 'Venue',
            'conference_date' => 'Conference Date',
        ],
        'patent' => [
            'faculty_name' => 'Faculty Name',
            'department' => 'Department',
            'academic_year' => 'Academic Year',
            'category' => 'Patent / Copyright',
            'title' => 'Title of the Patent / Copyright',
            'patent_number' => 'Patent / Copyright Number',
            'publication_date' => 'Date of Publication',
            'document_link' => 'Document Link',
        ],
        'fdp' => [
            'faculty_name' => 'Faculty Name',
            'department' => 'Department',
            'academic_year' => 'Academic Year',
            'duration' => 'Duration',
            'event_type' => 'Event Type',
            'title' => 'Name of the FDP / Seminar / Workshop',
            'mode' => 'Mode',
            'organized_by' => 'Organized By',
            'from_date' => 'From Date',
            'to_date' => 'To Date',
            'certificate_link' => 'Certificate Link',
        ],
        'mou' => [
            'department' => 'Department',
            'signed_date' => 'Signed Date',
            'organization' => 'Name & Address of Collaborating Body',
            'valid_upto' => 'Valid upto',
            'purpose' => 'Purpose of Collaboration',
            'document_link' => 'Document Link',
        ],
        'event' => [
            'department' => 'Department',
            'event_date' => 'Date',
            'event_title' => 'Event Title',
            'event_type' => 'Event Type',
            'resource_person' => 'Chief Guest / Resource Person',
            'participants' => 'No. of Participants',
            'sponsorship' => 'Sponsorship',
            'report_link' => 'Web Link to Event Report',
        ],
        // Participant, department, grade and topper are checked by nptel_prepare_submission().
        'nptel' => [
            'category' => 'Participant Type',
            'candidate_name' => 'Candidate Name',
            'department' => 'Department',
            'course_title' => 'Course Title',
            'session' => 'Session',
            'grade' => 'Grade',
            'is_topper' => 'NPTEL Topper',
            'certificate_link' => 'Certificate Link',
        ],
        'internship' => [
            'reg_no' => 'Reg. No',
            'student_name' => 'Name of the Student',
            'department' => 'Dept / Branch',
            'title' => 'Title of Internship',
            'industry' => 'Industry/Institution Name & Address',
            'duration' => 'Duration',
            'days' => 'No. of Days',
            'certificate_link' => 'Link to Certificate / Document',
        ],
        'placement' => [
            'reg_no' => 'Reg. No',
            'student_name' => 'Student Name',
            'department' => 'Dept / Branch',
            'job_title' => 'Job Name',
            'mode' => 'Mode',
            'company' => 'Company Name & Address',
            'pay_scale' => 'Pay Scale',
            'appointment_order_link' => 'Web Link to Appointment Order',
        ],
        'nss' => [
            'department' => 'Department',
            'academic_year' => 'Academic Year',
            'activity_date' => 'Date',
            'activity_type' => 'Activity Type',
            'activity_name' => 'Name of the Activity',
            'venue' => 'Venue',
            'participants' => 'No. of Students Participated',
            'external_agency' => 'Name of External Agency / Member Involved',
            'report_link' => 'Web Link to Event Report',
        ],
        'online_course' => [
            'department' => 'Department',
            'academic_year' => 'Academic Year',
            'candidate_name' => 'Candidate Name',
            'category' => 'Category',
            'course_title' => 'Course Title',
            'provider' => 'Provider',
            'duration' => 'Duration',
            'month_year' => 'Month & Year',
            'certificate_link' => 'Certificate Link',
        ],
        'student_achievement' => [
            'department' => 'Dept / Branch',
            'academic_year' => 'Academic Year',
            'reg_no' => 'Reg. No',
            'student_name' => 'Name of the Student',
            'event_type' => 'Event Type',
            'event_name' => 'Name of the Event',
            'function_name' => 'Name of the Function / Programme',
            'event_date' => 'Date of the Event',
            'team_individual' => 'Team / Individual',
            'level_secured' => 'Level',
            'position_secured' => 'Position Secured',
            'organising_institution' => 'Name of Organising Institution',
            'certificate_link' => 'Link to Certificate / Document',
        ],
        'student_participation' => [
            'department' => 'Dept / Branch',
            'academic_year' => 'Academic Year',
            'activity_category' => 'Activity Category',
            'reg_no' => 'Reg. No',
            'student_name' => 'Name of the Student',
            'event_type' => 'Event Type',
            'event_name' => 'Name of the Event',
            'function_name' => 'Name of the Function / Programme',
            'event_date' => 'Date of the Event',
            'team_individual' => 'Team / Individual',
            'level_secured' => 'Level',
            'position_secured' => 'Position Secured',
            'organising_institution' => 'Name of Organising Institution',
            'certificate_link' => 'Link to Certificate / Document',
        ],
        'summer_training' => [
            'department' => 'Dept / Branch',
            'academic_year' => 'Academic Year',
            'reg_no' => 'Reg. No',
            'student_name' => 'Name of the Student',
            'title' => 'Title of Training',
            'industry' => 'Industry/Institution Name & Address',
            'duration' => 'Duration',
            'days' => 'No. of Days',
            'certificate_link' => 'Link to Certificate / Document',
        ],
        'value_added' => [
            'department' => 'Department',
            'academic_year' => 'Academic Year',
            'from_date' => 'From Date',
            'to_date' => 'To Date',
            'course_title' => 'Course Title',
            'mode' => 'Mode',
            'participants' => 'No. of Participants',
            'resource_person' => 'Resource Person',
            'report_link' => 'Web Link to Event Report',
        ],
        'training' => [
            'department' => 'Department',
            'academic_year' => 'Academic Year',
            'event_date' => 'Date',
            'event_title' => 'Event Title',
            'event_type' => 'Event Type',
            'participants' => 'No. of Participants',
            'sponsorship' => 'Sponsorship',
            'resource_person' => 'Chief Guest / Resource Person',
            'report_link' => 'Web Link to Event Report',
        ],
    ];

    // NPTEL, Journal and Book take their department from the participant /
    // Main Author (checked below), never the uploader.
    if (!in_array($type, ['nptel', 'journal', 'book'], true) && !user_can_choose_department($user) && !empty($user['department'])) {
        $_POST['department'] = $user['department'];
    }
    $validationErrors = [];
    $expectedFields = $requiredMap[$type] ?? [];

    if ($type === 'nptel') {
        [$nptelClean, $nptelErrors] = nptel_prepare_submission($user, $_POST, $departments);
        $validationErrors = $nptelErrors;
        foreach ($nptelClean as $k => $v) {
            $_POST[$k] = $v === null ? '' : (string) $v;
        }
        if ($nptelErrors) {
            $expectedFields = [];   // the participant errors already say what is wrong
        }
    }

    // Event Mode and the venue / online details it needs; fields the mode does
    // not use come back empty so they are not stored.
    if (is_event_type($type)) {
        [$modeClean, $modeErrors] = event_mode_prepare_submission($_POST);
        $validationErrors = array_merge($validationErrors, $modeErrors);
        foreach ($modeClean as $k => $v) {
            $_POST[$k] = $v === null ? '' : (string) $v;
        }
    }

    // Journal and Book / Book Chapter: authors picked from the faculty list,
    // controlled fields, and the duplicate-publication check.
    $pubAuthors = [];
    if (in_array($type, ['journal', 'book'], true)) {
        [$pClean, $pubAuthors, $pErrors] = $type === 'journal'
            ? journal_prepare_submission($user, $_POST)
            : book_prepare_submission($user, $_POST);
        $validationErrors = array_merge($validationErrors, $pErrors);
        foreach ($pClean as $k => $v) {
            $_POST[$k] = $v === null ? '' : (string) $v;
        }
        unset($_POST['exam_session']);   // no Exam Session on publications
        if (!$pErrors) {
            // Held until the request ends, so two authors saving the same
            // publication at the same moment cannot both pass the check.
            db()->query("SELECT GET_LOCK('atts_{$type}_publication', 10)");
            $editOf = (int) input('edit_id') ?: null;
            if ($type === 'journal') {
                $dup = journal_find_duplicate($pClean, $editOf);
                $dupMessage = $dup !== null ? journal_duplicate_message($dup) : null;
            } else {
                $dup = book_find_duplicate($pClean, $editOf);
                $dupMessage = $dup !== null ? book_duplicate_message($dup) : null;
            }
            if ($dupMessage !== null) {
                $validationErrors[] = $dupMessage;
            }
        }
    }

    foreach ($expectedFields as $fKey => $fLabel) {
        if ($fKey === 'academic_year') {
            continue; 
        }
        $val = trim((string) ($_POST[$fKey] ?? ''));
        if ($val === '') {
            $validationErrors[] = "{$fLabel} is required.";
            continue;
        }

        if (in_array($fKey, ['doi', 'journal_link', 'document_link', 'certificate_link', 'report_link', 'appointment_order_link'], true)) {
            if (!preg_match('/^https?:\/\/.+/i', $val)) {
                $validationErrors[] = "{$fLabel} must be a valid URL starting with http:// or https://.";
            }
        }

        if (in_array($fKey, ['conference_date', 'publication_date', 'from_date', 'to_date', 'signed_date', 'valid_upto', 'event_date', 'activity_date'], true)) {
            if (strtotime($val) === false) {
                $validationErrors[] = "{$fLabel} must be a valid date.";
            }
        }

        if (in_array($fKey, ['participants', 'days'], true)) {
            if (!is_numeric($val) || (int)$val < 1) {
                $validationErrors[] = "{$fLabel} must be a number greater than 0.";
            }
        }
    }

    // ── Institutional types bypass the exam_session check and generic handler ──
    if (str_starts_with($type, 'inst_')) {
        inst_handle_post($user, $types, $activeYear);
        // inst_handle_post always redirects; this line is a safety fallback:
        redirect('/upload.php?type=' . $type);
    }

    if (!in_array($type, ['journal', 'book'], true) && !in_array((string) ($_POST['exam_session'] ?? ''), exam_sessions(), true)) {
        $validationErrors[] = 'Academic Session is required: choose ' . implode(' or ', exam_sessions()) . '.';
    }

    // Proof is mandatory, except when editing a record that already has one on file.
    $proofRequired = true;
    $proofEditId   = (int) input('edit_id');
    if ($proofEditId > 0) {
        try {
            $stmt = db()->prepare("SELECT * FROM `{$types[$type]['table']}` WHERE id = ?");
            $stmt->execute([$proofEditId]);
            $proofRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $proofRequired = empty($proofRow['proof_file']) && empty($proofRow['proofs']);
        } catch (Throwable $e) {
            // fall back to requiring a proof
        }
    }
    [$proofStored, $proofError] = save_upload_proof($_FILES['proof'] ?? null, $proofRequired);
    if ($proofError !== null) {
        $validationErrors[] = $proofError;
    }

    if (!empty($validationErrors)) {
        flash('error', implode('<br>', $validationErrors));
        redirect('/upload.php?type=' . $type);
    }

    $table = $types[$type]['table'];
    $pdo   = db();

    try {
        $protected = ['id', 'created_by', 'status', 'approved_by', 'review_remark', 'created_at', 'updated_at', 'academic_year'];
        $tableColumns = $pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_COLUMN);
        $allowed      = array_diff($tableColumns, $protected);

    $fields = [];
    $values = [];
    $placeholders = [];

    $editId = (int) input('edit_id');
    if ($editId > 0) {
        [$canEdit, $errMsg, $existingRec] = can_edit_record($type, $editId, $user);
        if (!$canEdit) {
            flash('error', $errMsg);
            redirect('/upload.php?type=' . $type . '&edit_id=' . $editId);
        }
        if (em_record_is_locked($user['role'], $existingRec['created_at'] ?? null, $existingRec['academic_year'] ?? $activeYear)) {
            flash('error', 'Executive Meeting 1 has ended and is no longer editable.');
            redirect('/upload.php?type=' . $type . '&edit_id=' . $editId);
        }

        $setPairs = [];
        $updateValues = [];
        $oldValues = [];
        $newValues = [];

        // A book keeps the Academic Session it was recorded in, as it keeps its year.
        if ($type === 'book' && !empty($existingRec['academic_session'])) {
            unset($_POST['academic_session']);
        }

        foreach ($_POST as $k => $v) {
            if (!in_array($k, $allowed, true) || $v === '') continue;
            if (($existingRec[$k] ?? null) != $v) {
                $oldValues[$k] = $existingRec[$k] ?? null;
                $newValues[$k] = $v;
            }
            $setPairs[] = "`$k` = ?";
            $updateValues[] = $v;
        }
        // Switching an NPTEL between Faculty and Student clears the other side's reference.
        if ($type === 'nptel') {
            foreach (['participant_user_id', 'reg_no'] as $col) {
                if (in_array($col, $allowed, true) && ($_POST[$col] ?? '') === '') {
                    $setPairs[] = "`$col` = NULL";
                }
            }
        }
        // Changing an event's mode clears the location fields it no longer uses.
        if (is_event_type($type)) {
            foreach (array_keys(event_mode_location_fields()) as $col) {
                if (in_array($col, $allowed, true) && ($_POST[$col] ?? '') === '') {
                    $setPairs[] = "`$col` = NULL";
                    if (($existingRec[$col] ?? null) !== null) {
                        $oldValues[$col] = $existingRec[$col];
                        $newValues[$col] = null;
                    }
                }
            }
        }
        // A journal or book edited down to no DOI, ISBN key or co-authors drops the old ones.
        if (in_array($type, ['journal', 'book'], true)) {
            foreach (['doi', 'doi_key', 'isbn_key', 'co_authors'] as $col) {
                if (in_array($col, $allowed, true) && ($_POST[$col] ?? '') === '') {
                    $setPairs[] = "`$col` = NULL";
                    if (($existingRec[$col] ?? null) !== null) {
                        $oldValues[$col] = $existingRec[$col];
                        $newValues[$col] = null;
                    }
                }
            }
        }
        if ($proofStored !== null && in_array('proof_file', $tableColumns, true)) {
            $setPairs[] = "`proof_file` = ?";
            $updateValues[] = $proofStored;
            $newValues['proof_file'] = $proofStored;
        }
        $setPairs[] = "`status` = ?";
        $updateValues[] = 'Resubmitted';
        $setPairs[] = "`review_remark` = ?";
        $updateValues[] = 'Corrected and resubmitted by Coordinator ' . ($user['name'] ?? '');
        $setPairs[] = "`updated_at` = NOW()";

        try {
            $sql = "UPDATE `$table` SET " . implode(', ', $setPairs) . " WHERE id = ?";
            $updateValues[] = $editId;
            $pdo->beginTransaction();
            $pdo->prepare($sql)->execute($updateValues);
            if (publication_authors_ready($type)) {
                publication_save_authors($type, $editId, $pubAuthors);
            }
            $pdo->commit();

            edit_request_complete($editId, $type, (int)$user['id'], $oldValues, $newValues);

            record_workflow_audit(
                $type,
                $editId,
                'COORDINATOR_RESUBMITTED',
                $user,
                $existingRec['status'] ?? 'Unlocked for Edit',
                'Resubmitted',
                'Coordinator resubmitted corrected record',
                ['old_values' => $oldValues, 'new_values' => $newValues],
                $existingRec['department'] ?? ($user['department'] ?? null),
                $existingRec['academic_year'] ?? $activeYear
            );

            require_once __DIR__ . '/models/Target.php';
            sync_target_achieved_for_type($type);

            flash('success', $types[$type]['label'] . ' corrected and resubmitted for HoD review.');
            redirect('/approvals.php');
        } catch (\PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('upload.php update failed: ' . $e->getMessage());
            flash('error', 'Failed to update record.');
            redirect('/upload.php?type=' . $type . '&edit_id=' . $editId);
        }
    }

    if (!record_requires_approval($type)) {
        $initialStatus = 'Submitted';
    } elseif (in_array($user['role'], ['Coordinator', 'HoD', 'Admin'], true)) {
        $initialStatus = 'Approved';
    } else {
        $initialStatus = 'Submitted';
    }
    $fields[] = 'created_by'; $values[] = $user['id']; $placeholders[] = '?';
    $fields[] = 'status';     $values[] = $initialStatus; $placeholders[] = '?';
    if (in_array('academic_year', $tableColumns, true)) {
        $fields[] = 'academic_year'; $values[] = $activeYear; $placeholders[] = '?';
    }
    if (in_array('created_at', $tableColumns, true)) {
        $fields[] = 'created_at';
        $values[] = date('Y-m-d H:i:s');
        $placeholders[] = '?';
    }

        if (!in_array($type, ['nptel', 'journal', 'book'], true) && !user_can_choose_department($user) && !empty($user['department'])) {
            $_POST['department'] = $user['department'];
        }

    foreach ($_POST as $k => $v) {
        if (!in_array($k, $allowed, true) || $v === '') continue;
        $fields[] = $k;
        $values[] = $v;
        $placeholders[] = '?';
    }

        if (in_array('department', $allowed, true) && !in_array('department', $fields, true) && !empty($user['department'])) {
            $fields[] = 'department';
            $values[] = $user['department'];
            $placeholders[] = '?';
        }

        if ($proofStored !== null && in_array('proof_file', $tableColumns, true)) {
            $fields[] = 'proof_file'; $values[] = $proofStored; $placeholders[] = '?';
        }
        if ($proofStored !== null && in_array('proofs', $tableColumns, true) && !in_array('proofs', $fields, true)) {
            $fields[] = 'proofs'; $values[] = json_encode([$proofStored]); $placeholders[] = '?';
        }
        $quotedFields = array_map(fn($f) => '`' . str_replace('`', '``', $f) . '`', $fields);
        $sql = "INSERT INTO `$table` (" . implode(', ', $quotedFields) . ") VALUES (" . implode(', ', $placeholders) . ")";
        $pdo->beginTransaction();
        $pdo->prepare($sql)->execute($values);
        $newRecordId = (int)$pdo->lastInsertId();
        // One publication row; each of its faculty authors linked to it.
        if (publication_authors_ready($type)) {
            publication_save_authors($type, $newRecordId, $pubAuthors);
        }
        $pdo->commit();

        if ($initialStatus === 'Submitted') {
            record_workflow_audit(
                $type,
                $newRecordId,
                'FACULTY_SUBMITTED',
                $user,
                null,
                'Submitted',
                'Faculty submitted record for Coordinator verification',
                null,
                $user['department'] ?? null,
                $activeYear
            );
        }

        // An upload that lands Approved refreshes any target it feeds.
        if ($initialStatus === 'Approved' || !record_requires_approval($type)) {
            require_once __DIR__ . '/models/Target.php';
            sync_target_achieved_for_type($type);
        }

        $where = $initialStatus === 'Approved' ? 'recorded' : 'submitted for review';
        flash('success', $types[$type]['label'] . ' ' . $where . '.');
        $_SESSION['submitted_draft_type'] = $type;
    } catch (\PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($proofStored !== null) {
            @unlink(rtrim(UPLOAD_DIR, '/\\') . '/' . $proofStored);
            @unlink(rtrim(UPLOAD_DIR, '/\\') . '/proofs/' . $proofStored);
        }
        error_log('upload.php insert failed: ' . $e->getMessage());
        $err = 'Sorry, that record could not be saved. Please check the fields and try again.';
        if (defined('APP_DEBUG') && APP_DEBUG) {
            $err .= ' [' . $e->getMessage() . ']';
        }
        flash('error', $err);
        redirect('/upload.php?type=' . $type);
    }

    if ($nav === 'next') {
        $idx  = array_search($type, $typeKeys, true);
        $dest = $typeKeys[$idx + 1] ?? $type;
        redirect('/upload.php?type=' . $dest);
    }
    redirect('/upload.php?type=' . $type);   
}

$effectiveYear = $uploadFlow ? $uploadFlow['year'] : $activeYear;
$myRecords = my_records($user['id']);
$submittedDraftType = $_SESSION['submitted_draft_type'] ?? null;
unset($_SESSION['submitted_draft_type']);
$selectedType = trim((string)($_GET['type'] ?? 'journal'));
if (!isset($types[$selectedType])) $selectedType = 'journal';

$editId = (int) input('edit_id', (int)($_GET['edit_id'] ?? 0));
$editRecord = null;
$activeEditRequest = null;
if ($editId > 0 && isset($types[$selectedType])) {
    if (function_exists('can_edit_record')) {
        [$canEdit, $errMsg, $editRecord] = can_edit_record($selectedType, $editId, $user);
        if (!$canEdit) {
            flash('error', $errMsg);
            redirect('/approvals.php');
        }
        $stmt = db()->prepare("SELECT * FROM edit_requests WHERE record_id = ? AND record_type = ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$editId, $selectedType]);
        $activeEditRequest = $stmt->fetch(PDO::FETCH_ASSOC);
    } else {
        $table = $types[$selectedType]['table'];
        $stmt = db()->prepare("SELECT * FROM `{$table}` WHERE id = ?");
        $stmt->execute([$editId]);
        $editRecord = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    if ($editRecord && !empty($editRecord['academic_year'])) {
        $effectiveYear = $editRecord['academic_year'];
    }
    // Older NPTEL rows spell grades loosely; match them to the dropdown.
    if ($editRecord && $selectedType === 'nptel') {
        $editRecord['grade'] = nptel_grade_normalize($editRecord['grade'] ?? '') ?? '';
    }
    // Journal / book authors and publication date back into their selects.
    if ($editRecord && in_array($selectedType, ['journal', 'book'], true)) {
        $editRecord += publication_edit_values($selectedType, $editRecord);
    }
}

$selIdx    = array_search($selectedType, $typeKeys, true);
$isLast    = $selIdx === count($typeKeys) - 1;
$nextType  = $typeKeys[$selIdx + 1] ?? null;
$prevType  = $selIdx > 0 ? $typeKeys[$selIdx - 1] : null;

/**
 * Render department input: locked readonly for Faculty (preserving their department),
 * and selectable for other authorized roles (Admin, etc.).
 */
if (!function_exists('render_dept_field')) {
function render_dept_field(array $user, array $departments, string $label = 'Department', bool $required = false): void
{
    $req = $required ? ' <span class="req">*</span>' : '';
    echo '<div class="field"><label>' . e($label) . $req . '</label>';
    if ($user['role'] === 'Faculty' && !empty($user['department'])) {
        echo '<input class="input" type="text" value="' . e($user['department']) . '" readonly style="background:var(--bg-subtle, #f3f4f6); cursor:not-allowed;">';
        echo '<input type="hidden" name="department" value="' . e($user['department']) . '">';
    } else {
        echo '<select class="select" name="department"' . ($required ? ' required' : '') . '>';
        foreach ($departments as $d) {
            $sel = ($user['department'] === $d['name']) ? ' selected' : '';
            echo '<option value="' . e($d['name']) . '"' . $sel . '>' . e($d['name']) . '</option>';
        }
        echo '</select>';
    }
    echo '</div>';
}
}

function render_exam_session_field(string $academicYear): void
{
    echo '<div class="field"><label>Academic Session <span class="req">*</span></label>';
    echo '<select class="select" name="exam_session" required>';
    foreach (exam_sessions() as $s) {
        $sel = $s === exam_session_current() ? ' selected' : '';
        echo '<option value="' . e($s) . '"' . $sel . '>' . e(exam_session_label($s, $academicYear)) . '</option>';
    }
    echo '</select></div>';
}

/**
 * Journal / Book author fields: Main Author, their department, Academic Year,
 * $afterYear (Book: its Academic Session), Number of Co-Authors, Main Author
 * Position and a name + position pair per co-author. Every author is picked
 * from the faculty list; the script below shows the pairs in use.
 */
function render_publication_author_fields(array $user, string $activeYear, string $noun, string $afterYear = ''): void
{
    $main  = publication_main_author_options($user);
    $co    = publication_coauthor_options();
    $maxCo = publication_max_coauthors();
    // Every position up to the largest team; the script disables the ones
    // beyond the chosen number of authors.
    $positions = function (string $name, bool $required = false) use ($maxCo): string {
        $html = '<select class="select js-pub-pos" name="' . e($name) . '"' . ($required ? ' required' : '') . '><option value="" disabled selected>Select position</option>';
        for ($p = 1; $p <= $maxCo + 1; $p++) {
            $html .= '<option value="' . $p . '">' . e(publication_position_label($p)) . '</option>';
        }
        return $html . '</select>';
    };
    ?>
        <div class="field"><label>Main Author <span class="req">*</span> <span class="card-sub">— from the faculty list</span></label>
          <select class="select js-pub-author" name="main_author_id" id="pubMainAuthor" required>
            <?php if (count($main) !== 1): ?><option value="" disabled selected>Select faculty</option><?php endif; ?>
            <?php foreach ($main as $f): ?>
              <option value="<?= (int) $f['id'] ?>" data-dept="<?= e($f['department'] ?? '') ?>"><?= e($f['name'] . ' — ' . ($f['department'] ?: 'No dept')) ?></option>
            <?php endforeach; ?>
          </select></div>
        <div class="field"><label>Department <span class="card-sub">— the Main Author's</span></label>
          <input class="input" id="pubMainDept" readonly tabindex="-1" style="background:var(--bg-subtle, #f3f4f6); cursor:not-allowed;"></div>
        <div class="field"><label>Academic Year</label>
          <input class="input" value="<?= e($activeYear) ?>" disabled title="Records are always submitted in the active academic year."></div>
        <?= $afterYear ?>
        <div class="field"><label>Number of Co-Authors <span class="req">*</span> <span class="card-sub">— MSEC faculty besides the Main Author</span></label>
          <select class="select" name="co_author_count" id="pubCoCount" required>
            <?php for ($n = 0; $n <= $maxCo; $n++): ?><option value="<?= $n ?>"><?= $n ?></option><?php endfor; ?>
          </select></div>
        <div class="field"><label>Main Author Position <span class="req">*</span> <span class="card-sub">— order on the <?= e($noun) ?></span></label>
          <?= $positions('main_author_position', true) ?></div>
        <div class="field" style="align-self:end"><div class="card-sub" style="padding-bottom:10px">Total authors: <strong id="pubTotalAuthors">1</strong></div></div>
        <?php for ($i = 1; $i <= $maxCo; $i++): ?>
          <div class="field" data-co-row="<?= $i ?>" style="display:none"><label>Co-Author Name <?= $i ?> <span class="req">*</span></label>
            <select class="select js-pub-author" name="co_author_id_<?= $i ?>">
              <option value="" disabled selected>Select faculty</option>
              <?php foreach ($co as $f): ?>
                <option value="<?= (int) $f['id'] ?>"><?= e($f['name'] . ' — ' . ($f['department'] ?: 'No dept')) ?></option>
              <?php endforeach; ?>
            </select></div>
          <div class="field" data-co-row="<?= $i ?>" style="display:none"><label>Co-Author Position <?= $i ?> <span class="req">*</span></label>
            <?= $positions('co_author_pos_' . $i) ?></div>
        <?php endfor; ?>
        <div id="pubAuthorMsg" role="alert" style="grid-column:span 2; display:none; color:var(--danger, #DC2626); font-size:12.5px; margin:-4px 0 12px"></div>
    <?php
}

// Month and Year of Publication selects (pub_month / pub_year), stored as MM/YYYY.
function render_publication_date_fields(): void
{
    $months = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
    ?>
        <div class="field"><label>Month of Publication <span class="req">*</span></label>
          <select class="select" name="pub_month" required>
            <option value="" disabled selected>Select month</option>
            <?php foreach ($months as $mi => $mn): ?><option value="<?= $mi + 1 ?>"><?= e($mn) ?></option><?php endforeach; ?>
          </select></div>
        <div class="field"><label>Year of Publication <span class="req">*</span></label>
          <select class="select" name="pub_year" required>
            <option value="" disabled selected>Select year</option>
            <?php foreach (publication_years() as $y): ?><option value="<?= $y ?>"><?= $y ?></option><?php endforeach; ?>
          </select></div>
    <?php
}

function render_proof_field(?array $editRecord = null, bool $required = true): void
{
    // Required on every new entry; a record being edited that already has a proof keeps it.
    $hasProof = $editRecord && (!empty($editRecord['proof_file']) || !empty($editRecord['proofs']));
    $needed   = $required && !$hasProof;
    echo '<div class="field" style="grid-column:span 2">';
    echo '<label>Proof / Attachment' . ($needed ? ' <span class="req">*</span>' : '') . ' <span class="card-sub">— PDF only, strictly 2 MB or less'
        . ($hasProof ? ' (leave empty to keep the current file)' : '') . '</span></label>';
    echo '<input class="input" type="file" name="proof" id="proofInput" accept="application/pdf,.pdf"' . ($needed ? ' required' : '') . '>';
    echo '<div id="proofSizeError" style="color:var(--danger, #ef4444); font-size:12px; margin-top:4px; display:none;"></div>';
    echo '</div>';
}

// Event Mode plus the location fields each mode needs. The fields are only
// hidden when the mode changes, never cleared, so switching back keeps them.
function render_event_mode_fields(): void
{
    echo '<div class="field"><label>Event Mode <span class="req">*</span></label>';
    echo '<select class="select js-event-mode" name="mode" required>';
    echo '<option value="" disabled selected>Select Online, Offline or Hybrid</option>';
    foreach (event_modes() as $m) {
        echo '<option value="' . e($m) . '">' . e($m) . '</option>';
    }
    echo '</select></div>';
    echo '<div class="field" data-event-mode-for="Offline Hybrid" style="display:none"><label>Venue <span class="req">*</span> <span class="card-sub">— physical location</span></label>'
        . '<input class="input" name="venue" maxlength="255" data-mode-required="1" placeholder="e.g. Seminar Hall, Block A"></div>';
    echo '<div class="field" data-event-mode-for="Online Hybrid" style="display:none"><label>Online Platform <span class="req">*</span> <span class="card-sub">— meeting platform</span></label>'
        . '<input class="input" name="online_platform" maxlength="150" data-mode-required="1" placeholder="e.g. Google Meet, Zoom, MS Teams"></div>';
    echo '<div class="field" data-event-mode-for="Online Hybrid" style="display:none"><label>Meeting Link <span class="card-sub">(if available)</span></label>'
        . '<input class="input" name="meeting_link" type="url" maxlength="500" placeholder="https://"></div>';
}

$pageTitle = 'Upload Data'; $breadcrumb = 'Upload Data';
require __DIR__ . '/inc/header.php';
?>

<div class="page-head">
  <div><h1>Upload Data</h1><div class="sub">Submit academic records for review</div></div>
</div>

<?php if ($uploadFlow): ?>
  <div class="card" style="margin-bottom:16px;background:linear-gradient(135deg, rgba(37,99,235,0.04), rgba(79,70,229,0.08));border-color:var(--border, #e2e8f0)">
    <div class="card-body" style="padding:12px 18px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px">
      <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
        <span class="badge badge-primary" style="font-size:13px;padding:6px 12px;display:inline-flex;align-items:center;gap:6px">
          <?= icon('calendar', 14) ?> Academic Year: <strong><?= e($uploadFlow['year']) ?></strong>
        </span>
        <?php if (!empty($uploadFlow['label'])): ?>
          <span class="badge badge-secondary" style="font-size:13px;padding:6px 12px;display:inline-flex;align-items:center;gap:6px">
            <?= icon($uploadFlow['icon'] ?? 'folder', 14) ?> Scope: <strong><?= e($uploadFlow['label']) ?></strong>
          </span>
        <?php endif; ?>
      </div>
      <div style="display:flex;align-items:center;gap:8px">
        <form method="post" action="<?= e(url('upload.php')) ?>" style="display:flex;align-items:center;gap:8px;margin:0">
          <?= csrf_field() ?>
          <input type="hidden" name="upload_flow_step" value="year">
          <input type="hidden" name="return_type" value="<?= e($selectedType) ?>">
          <label for="flowYear" style="font-size:12px;font-weight:600;color:var(--ink-muted,#64748b)">Academic Year</label>
          <select class="select" id="flowYear" name="academic_year" onchange="this.form.submit()" style="height:32px;font-size:12px;padding:0 28px 0 10px;border-radius:8px;width:auto">
            <?php foreach (upload_flow_years() as $y): ?>
              <option value="<?= e($y) ?>" <?= $uploadFlow['year'] === $y ? 'selected' : '' ?>>
                <?= e($y . ($y === $activeYear ? ' (Active)' : '') . (academic_year_is_locked($y) ? ' — Locked' : '')) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </form>
      </div>
    </div>
  </div>
<?php endif; ?>
<!-- Type selector tabs -->
<div class="card" style="margin-bottom:16px">
  <div class="card-body js-category-nav-container" style="padding:8px 16px 14px; overflow-x:auto; white-space:nowrap; position:relative; scrollbar-width:thin;">
    <?php foreach ($types as $key => $t): ?>
      <a href="<?= e(url('upload.php?type=' . $key)) ?>"
         class="btn btn-sm js-category-tab <?= $selectedType === $key ? 'btn-primary active' : 'btn-ghost' ?>"
         data-type="<?= e($key) ?>"
         style="margin:4px 3px; height:32px; font-size:12px; position:relative; z-index:1; flex-shrink:0"><?= e($t['label']) ?></a>
    <?php endforeach; ?>
    <!-- Sliding active-tab indicator line -->
    <div class="js-category-indicator" id="categoryNavIndicator"
         style="position:absolute; bottom:5px; height:3px; background:var(--orange-500, #FF4F01); border-radius:3px; z-index:2; pointer-events:none; left:0; width:0; transition:transform 0.28s cubic-bezier(0.32, 0.72, 0, 1), width 0.28s cubic-bezier(0.32, 0.72, 0, 1); will-change:transform, width;"></div>
  </div>
</div>

<script>
(function () {
  var container = document.querySelector('.js-category-nav-container');
  var indicator = document.getElementById('categoryNavIndicator');
  if (!container || !indicator) return;

  function getActiveTab() {
    return container.querySelector('.js-category-tab.active') ||
           container.querySelector('.js-category-tab.btn-primary') ||
           container.querySelector('.js-category-tab');
  }

  function updateIndicator(activeElement, smooth) {
    if (!activeElement || !container || !indicator) return;

    var containerRect = container.getBoundingClientRect();
    var tabRect = activeElement.getBoundingClientRect();
    var currentScroll = container.scrollLeft;

    var targetLeft = (tabRect.left - containerRect.left - (container.clientLeft || 0)) + currentScroll;
    var targetWidth = tabRect.width || activeElement.offsetWidth;

    if (smooth) {
      indicator.style.transition = 'transform 0.28s cubic-bezier(0.32, 0.72, 0, 1), width 0.28s cubic-bezier(0.32, 0.72, 0, 1)';
    } else {
      indicator.style.transition = 'none';
    }

    indicator.style.width = Math.round(targetWidth) + 'px';
    indicator.style.transform = 'translateX(' + Math.round(targetLeft) + 'px)';
  }

  function ensureActiveCategoryVisible(activeElement, smooth) {
    if (!activeElement || !container) return;

    var containerRect = container.getBoundingClientRect();
    var itemRect = activeElement.getBoundingClientRect();
    var padding = 24;

    var itemLeft = itemRect.left - containerRect.left;
    var itemRight = itemRect.right - containerRect.left;

    var currentScroll = container.scrollLeft;
    var targetScroll = currentScroll;

    if (itemLeft < padding || itemRight > (container.clientWidth - padding)) {
      var itemCenter = activeElement.offsetLeft + (activeElement.offsetWidth / 2);
      targetScroll = itemCenter - (container.clientWidth / 2);
    } else {
      return;
    }

    var maxScroll = Math.max(0, container.scrollWidth - container.clientWidth);
    targetScroll = Math.max(0, Math.min(targetScroll, maxScroll));

    if (Math.abs(container.scrollLeft - targetScroll) > 1) {
      if (smooth && typeof container.scrollTo === 'function') {
        container.scrollTo({ left: targetScroll, behavior: 'smooth' });
      } else {
        container.scrollLeft = targetScroll;
      }
    }
  }

  var tabs = container.querySelectorAll('.js-category-tab');
  tabs.forEach(function (tab) {
    tab.addEventListener('click', function (e) {
      if (e.metaKey || e.ctrlKey || e.shiftKey || e.button !== 0) return;

      tabs.forEach(function (t) {
        t.classList.remove('btn-primary', 'active');
        t.classList.add('btn-ghost');
      });
      this.classList.remove('btn-ghost');
      this.classList.add('btn-primary', 'active');

      updateIndicator(this, true);
      ensureActiveCategoryVisible(this, true);
    });
  });

  container.addEventListener('scroll', function () {
    var activeTab = getActiveTab();
    if (activeTab) {
      updateIndicator(activeTab, false);
    }
  }, { passive: true });

  function initNav() {
    var activeTab = getActiveTab();
    if (activeTab) {
      updateIndicator(activeTab, false);
      ensureActiveCategoryVisible(activeTab, false);
      setTimeout(function () {
        updateIndicator(activeTab, false);
        ensureActiveCategoryVisible(activeTab, true);
      }, 50);
      setTimeout(function () {
        updateIndicator(activeTab, false);
      }, 200);
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initNav);
  } else {
    initNav();
  }

  window.addEventListener('resize', function () {
    var activeTab = getActiveTab();
    if (activeTab) {
      updateIndicator(activeTab, false);
      ensureActiveCategoryVisible(activeTab, false);
    }
  });
})();
</script>

<?php
  $isUploadLocked   = academic_year_is_locked($effectiveYear);
  $emStatus         = em_status($effectiveYear);
  $emBlockReason    = em_submission_block_reason($user['role'], $effectiveYear);
  $isRecordEmLocked = !empty($editRecord) && function_exists('em_record_is_locked') && em_record_is_locked($user['role'], $editRecord['created_at'] ?? null, $editRecord['academic_year'] ?? $effectiveYear);
  $isEmLocked       = ($user['role'] !== 'Admin') && ($isRecordEmLocked || ($editRecord ? false : ($emBlockReason !== null)));
  $isFormDisabled   = ($isUploadLocked || $isEmLocked) && ($user['role'] !== 'Admin');
?>
<?php if ($isUploadLocked): ?>
  <div style="background:#FEF2F2;border:1px solid #FECACA;border-left:4px solid #DC2626;color:#991B1B;padding:14px 18px;border-radius:10px;margin-bottom:20px;display:flex;align-items:center;gap:12px">
    <div style="width:36px;height:36px;border-radius:8px;background:#FEE2E2;display:flex;align-items:center;justify-content:center;flex-shrink:0;color:#DC2626">
      <?= icon('lock', 20) ?>
    </div>
    <div style="flex:1">
      <div style="font-weight:700;font-size:13px">Academic Year <?= e($activeYear) ?> Cycle is Locked</div>
      <div style="font-size:12px;color:#B91C1C;margin-top:2px">The Administrator has frozen submissions for this academic year cycle following an Executive Meeting. <?= $user['role'] === 'Admin' ? 'As an Admin, you retain upload authority.' : 'New submissions are frozen across all roles until unlocked by an Administrator.' ?></div>
    </div>
  </div>
<?php endif; ?>

<?php if ($isRecordEmLocked): ?>
  <div class="em-lock-banner" style="background:#FEF2F2;border:1px solid #FECACA;border-left:4px solid #DC2626;color:#991B1B;padding:14px 18px;border-radius:10px;margin-bottom:20px;display:flex;align-items:center;gap:12px">
    <div style="width:36px;height:36px;border-radius:8px;background:#FEE2E2;display:flex;align-items:center;justify-content:center;flex-shrink:0;color:#DC2626">
      <?= icon('lock', 20) ?>
    </div>
    <div style="flex:1">
      <div style="font-weight:700;font-size:13px;display:flex;align-items:center;gap:8px">
        Executive Meeting 1 Closed
        <span class="ay-pill locked" style="font-size:11px;padding:2px 8px;">EM1 Closed</span>
      </div>
      <div style="font-size:12px;color:#B91C1C;margin-top:2px">
        This record was submitted during Executive Meeting 1, which has ended and is now locked/read-only. Further edits are no longer permitted.
      </div>
    </div>
  </div>
<?php elseif ($emBlockReason && !$editRecord && $user['role'] !== 'Admin'): ?>
  <div class="em-lock-banner" style="background:#FEF2F2;border:1px solid #FECACA;border-left:4px solid #DC2626;color:#991B1B;padding:14px 18px;border-radius:10px;margin-bottom:20px;display:flex;align-items:center;gap:12px">
    <div style="width:36px;height:36px;border-radius:8px;background:#FEE2E2;display:flex;align-items:center;justify-content:center;flex-shrink:0;color:#DC2626">
      <?= icon('lock', 20) ?>
    </div>
    <div style="flex:1">
      <div style="font-weight:700;font-size:13px;display:flex;align-items:center;gap:8px">
        Executive Meeting 1 Closed
        <span class="ay-pill locked" style="font-size:11px;padding:2px 8px;">EM1 Closed</span>
        <?php if ($emStatus['em2_upcoming']): ?>
          <span class="ay-pill newer" style="font-size:11px;padding:2px 8px;">EM2 Upcoming</span>
        <?php endif; ?>
      </div>
      <div style="font-size:12px;color:#B91C1C;margin-top:2px">
        <?= e($emBlockReason) ?>
      </div>
    </div>
  </div>
<?php elseif ($emStatus['em2_active'] && !$editRecord): ?>
  <div class="em-active-banner" style="background:#F0FDF4;border:1px solid #BBF7D0;border-left:4px solid #16A34A;color:#166534;padding:12px 18px;border-radius:10px;margin-bottom:20px;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">
    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
      <span class="ay-pill locked" style="font-size:11px;padding:2px 8px;">EM1 Closed</span>
      <span class="ay-pill active" style="font-size:11px;padding:2px 8px;">EM2 Active</span>
      <span style="font-weight:600;font-size:13px">Executive Meeting 1 has ended. Executive Meeting 2 is now active.</span>
    </div>
    <div style="font-size:12px;color:#15803D">
      EM2 in session until <?= date('d M Y', strtotime($emStatus['schedule']['em2_end'])) ?>
    </div>
  </div>
<?php endif; ?>

<!-- Upload form -->
<div class="card" style="margin-bottom:20px">
  <div class="card-head">
    <div>
      <div class="card-title">
        <?= $editRecord ? 'Edit ' . e($types[$selectedType]['label']) . ' #' . (int)($editRecord['id'] ?? $editId) : 'New ' . e($types[$selectedType]['label']) ?>
        <?php if ($editRecord): ?>
          <span class="badge badge-neutral js-form-status-badge" style="font-size:11px; margin-left:8px; vertical-align:middle; text-transform:none;"><?= e($editRecord['status'] ?? 'Draft') ?></span>
        <?php endif; ?>
        <span class="card-sub js-draft-status" style="font-size:11px; font-weight:normal; margin-left:8px; opacity:0; transition:opacity 0.25s;"></span>
      </div>
      <div class="card-sub"><?= $editRecord ? 'Make corrections and save to update this record' : 'Fill in the details and submit for review' ?></div>
    </div>
    <?php if (record_report_spec($selectedType) !== null): ?>
      <a class="btn btn-secondary btn-sm" href="<?= e(url('record-report.php?type=' . $selectedType . '&format=word')) ?>"><?= icon('download') ?> Download this report</a>
    <?php endif; ?>
  </div>
  <div class="card-body">
    <form method="post" enctype="multipart/form-data">
      <fieldset <?= $isFormDisabled ? 'disabled' : '' ?> style="border:none;padding:0;margin:0;display:contents;">
      <?= csrf_field() ?>
      <input type="hidden" name="record_type" value="<?= e($selectedType) ?>">
      <?php if ($editRecord || $editId > 0): ?>
        <input type="hidden" name="edit_id" value="<?= $editId > 0 ? $editId : (int)$editRecord['id'] ?>">
        <?php if (!empty($_GET['source'])): ?>
          <input type="hidden" name="source" value="<?= e($_GET['source']) ?>">
        <?php endif; ?>
      <?php endif; ?>

      <div style="display:grid; grid-template-columns:1fr 1fr; gap:0 16px;">
      <?php if (!empty($editRecord)): ?>
        <div style="grid-column:span 2; background:#EFF6FF; border:1px solid #BFDBFE; border-left:4px solid #1D4ED8; border-radius:10px; padding:16px 20px; margin-bottom:16px">
          <div style="display:flex; align-items:center; gap:10px; margin-bottom:8px">
            <span class="badge badge-primary" style="font-size:12px; font-weight:700">Authorized Correction</span>
            <span style="font-weight:700; color:#1E3A8A; font-size:14px">Record #<?= (int)$editId ?>: <?= e($editRecord['_title'] ?? '') ?></span>
          </div>
          <div style="font-size:12.5px; color:#1E293B; line-height:1.6">
            <?php if (!empty($activeEditRequest)): ?>
              <div><strong>HoD Reason:</strong> <?= e($activeEditRequest['reason']) ?></div>
              <?php if (!empty($activeEditRequest['specific_field'])): ?>
                <div style="margin-top:4px">
                  <strong>Authorized Field to Correct:</strong> <span style="font-family:monospace; background:#DBEAFE; color:#1E40AF; padding:2px 8px; border-radius:4px; font-weight:700"><?= e($activeEditRequest['specific_field']) ?></span>
                  <?php if (!empty($activeEditRequest['current_value'])): ?> (Current: <span style="color:#64748B"><?= e($activeEditRequest['current_value']) ?></span>)<?php endif; ?>
                  <?php if (!empty($activeEditRequest['requested_value'])): ?> &rarr; Requested: <span style="color:#047857; font-weight:700"><?= e($activeEditRequest['requested_value']) ?></span><?php endif; ?>
                </div>
              <?php endif; ?>
              <?php $authNote = $activeEditRequest['decision_comment'] ?? $activeEditRequest['admin_comments'] ?? ''; ?>
              <?php if (!empty($authNote)): ?>
                <div style="margin-top:4px; color:#1E40AF"><strong>Dean Authorization Note:</strong> <?= e($authNote) ?></div>
              <?php endif; ?>
            <?php else: ?>
              <div><?= e($editRecord['review_remark'] ?: 'Dean has authorized editing for this record.') ?></div>
            <?php endif; ?>
          </div>
        </div>
      <?php endif; ?>
      <?php if (in_array($selectedType, ['conference','patent','fdp'])): ?>
        <div class="field"><label>Faculty Name <span class="req">*</span></label>
          <input class="input" name="faculty_name" value="<?= e($user['name']) ?>" required></div>
        <?php render_dept_field($user, $departments, 'Department', true); ?>
        <div class="field"><label>Academic Year</label>
          <input class="input" value="<?= e($activeYear) ?>" disabled title="Records are always submitted in the active academic year.">
        </div>
        <?php render_exam_session_field($effectiveYear); ?>
      <?php endif; ?>

      <?php if ($selectedType === 'journal'): ?>
        <?php /* Fields follow the IQAC "Journal Publications" report template. Every
                 author is picked from the faculty list; title fields are capitals only. */ ?>
        <?php render_publication_author_fields($user, $activeYear, 'paper'); ?>

        <div class="field" style="grid-column:span 2"><label>Title of the Paper <span class="req">*</span> <span class="card-sub">— CAPITAL LETTERS, digits and spaces only</span></label>
          <input class="input js-caps" name="paper_title" maxlength="500" pattern="[A-Z0-9 ]+" title="Use CAPITAL LETTERS (A-Z), digits and spaces only — no punctuation." autocomplete="off" required></div>
        <div class="field"><label>Journal Name <span class="req">*</span> <span class="card-sub">— CAPITAL LETTERS, digits and spaces only</span></label>
          <input class="input js-caps" name="journal_name" maxlength="255" pattern="[A-Z0-9 ]+" title="Use CAPITAL LETTERS (A-Z), digits and spaces only — no punctuation." autocomplete="off" required></div>
        <div class="field"><label>Journal Type <span class="req">*</span></label>
          <select class="select" name="journal_type" required>
            <option value="" disabled selected>Select journal type</option>
            <?php foreach (journal_types() as $jt): ?><option value="<?= e($jt) ?>"><?= e($jt) ?></option><?php endforeach; ?>
          </select></div>
        <div class="field"><label>ISSN Number <span class="req">*</span></label>
          <input class="input js-upper" name="issn" maxlength="9" pattern="\d{4}-?\d{3}[\dXx]" placeholder="e.g. 1234-5678" title="8 characters, e.g. 1234-5678 (the last may be X)" required></div>
        <div class="field"><label>Volume &amp; Issue No <span class="req">*</span></label>
          <input class="input js-upper" name="volume_issue" maxlength="150" pattern="[A-Za-z0-9 .,:\/()\-]+" placeholder="e.g. VOL 10, ISSUE 2" title="Letters, digits, spaces and . , : / ( ) - only" required></div>
        <?php render_publication_date_fields(); ?>
        <div class="field"><label>DOI <span class="card-sub">(if the article has one — e.g. 10.1234/ABC123)</span></label>
          <input class="input" name="doi" maxlength="255" placeholder="10.1234/ABC123 or https://doi.org/10.1234/ABC123" autocomplete="off"></div>
        <?php render_proof_field($editRecord); ?>
        <div class="field"><label>Link to Journal Website <span class="req">*</span></label><input class="input" name="journal_link" type="url" required></div>
        <div class="field"><label>Document Link <span class="req">*</span></label><input class="input" name="document_link" type="url" required></div>

      <?php elseif ($selectedType === 'book'): ?>
        <?php
          /* Fields follow the IQAC "Book / Book Chapter Publications" report
             template. Every author is picked from the faculty list. The Academic
             Session is set by the server from today's date in India (IST). */
          $bookSession = (string) (($editRecord['academic_session'] ?? '') ?: academic_session_current());
          $bookSessionField = '<div class="field"><label>Academic Session <span class="card-sub">— set automatically (IST)</span></label>'
              . '<input class="input" id="bookSession" value="' . e($bookSession) . '" readonly tabindex="-1" style="background:var(--bg-subtle, #f3f4f6); cursor:not-allowed;"'
              . ' title="January - June or July - December, from the date the record is submitted."></div>';
        ?>
        <?php render_publication_author_fields($user, $activeYear, 'book / chapter', $bookSessionField); ?>

        <div class="field"><label>Book / Book Chapter <span class="req">*</span></label>
          <select class="select" name="publication_category" required>
            <option value="" disabled selected>Select</option>
            <?php foreach (book_categories() as $bc): ?><option value="<?= e($bc) ?>"><?= e($bc) ?></option><?php endforeach; ?>
          </select></div>
        <div class="field" style="grid-column:span 2"><label>Title of the Book / Book Chapter <span class="req">*</span></label>
          <input class="input js-trim" name="title" maxlength="500" autocomplete="off" required></div>
        <div class="field"><label>Publisher Name <span class="req">*</span></label>
          <input class="input js-trim" name="publisher_name" maxlength="255" required></div>
        <div class="field"><label>ISSN / ISBN Number <span class="req">*</span></label>
          <input class="input js-upper" name="isbn" maxlength="30" pattern="[0-9Xx\- ]{8,20}" placeholder="e.g. 978-93-5019-123-4 or 1234-5678" title="ISBN-13, ISBN-10 or ISSN — digits, hyphens and a final X only" autocomplete="off" required></div>
        <div class="field"><label>DOI <span class="card-sub">(if it has one — e.g. 10.1007/978-3-031-45678-1_12)</span></label>
          <input class="input" name="doi" maxlength="255" placeholder="10.1234/ABC123 or https://doi.org/10.1234/ABC123" autocomplete="off"></div>
        <?php render_publication_date_fields(); ?>
        <div class="field"><label>Document Link <span class="req">*</span></label><input class="input" name="document_link" type="url" required></div>

      <?php elseif ($selectedType === 'conference'): ?>
        <div class="field"><label>Author Type <span class="req">*</span></label><select class="select" name="author_type" required><option>Author-1</option><option>Co-Author</option></select></div>
        <div class="field" style="grid-column:span 2"><label>Paper Title <span class="req">*</span></label><input class="input" name="paper_title" required></div>
        <div class="field"><label>Conference Name <span class="req">*</span></label><input class="input" name="conference_name" required></div>
        <div class="field"><label>Type <span class="req">*</span></label><select class="select" name="conference_type" required><option>National</option><option>International</option></select></div>
        <div class="field"><label>Venue <span class="req">*</span></label><input class="input" name="venue" required></div>
        <div class="field"><label>Conference Date <span class="req">*</span></label><input class="input" name="conference_date" type="date" required></div>

      <?php elseif ($selectedType === 'patent'): ?>
        <div class="field"><label>Patent / Copyright <span class="req">*</span></label><select class="select" name="category" required><option>Patent</option><option>Copyright</option></select></div>
        <div class="field" style="grid-column:span 2"><label>Title of the Patent / Copyright <span class="req">*</span></label><input class="input" name="title" required></div>
        <div class="field"><label>Patent / Copyright Number <span class="req">*</span></label><input class="input" name="patent_number" required></div>
        <div class="field"><label>Date of Publication <span class="req">*</span> <span class="card-sub">(dd/mm/yyyy)</span></label><input class="input" name="publication_date" type="date" required></div>
        <div class="field"><label>Document Link <span class="req">*</span></label><input class="input" name="document_link" type="url" required></div>

      <?php elseif ($selectedType === 'fdp'): ?>
        <div class="field"><label>Duration <span class="req">*</span></label><input class="input" name="duration" placeholder="e.g. 5 days / 1 week" required></div>
        <div class="field"><label>Event Type <span class="req">*</span></label><select class="select" name="event_type" required><option>FDP</option><option>Workshop</option><option>Seminar</option><option>STTP</option><option>Conference</option></select></div>
        <div class="field" style="grid-column:span 2"><label>Name of the FDP / Seminar / Workshop <span class="req">*</span></label><input class="input" name="title" required></div>
        <div class="field"><label>Mode <span class="req">*</span></label><select class="select" name="mode" required><option>Online</option><option>Offline</option><option>Hybrid</option></select></div>
        <div class="field"><label>Organized By <span class="req">*</span> <span class="card-sub">(Institution / Agency)</span></label><input class="input" name="organized_by" required></div>
        <div class="field"><label>From Date <span class="req">*</span> <span class="card-sub">(dd/mm/yyyy)</span></label><input class="input" name="from_date" type="date" required></div>
        <div class="field"><label>To Date <span class="req">*</span> <span class="card-sub">(dd/mm/yyyy)</span></label><input class="input" name="to_date" type="date" required></div>
        <div class="field"><label>Certificate Link <span class="req">*</span></label><input class="input" name="certificate_link" type="url" required></div>

      <?php elseif ($selectedType === 'mou'): ?>
        <?php render_dept_field($user, $departments, 'Department', true); ?>
        <?php render_exam_session_field($effectiveYear); ?>
        <div class="field"><label>Signed Date <span class="req">*</span> <span class="card-sub">(dd/mm/yyyy)</span></label><input class="input" name="signed_date" type="date" required></div>
        <div class="field" style="grid-column:span 2"><label>Name &amp; Address of the Collaborating Body <span class="req">*</span> <span class="card-sub">(Industry / Institution / Agency)</span></label><input class="input" name="organization" required></div>
        <div class="field"><label>Valid upto <span class="req">*</span> <span class="card-sub">(dd/mm/yyyy)</span></label><input class="input" name="valid_upto" type="date" required></div>
        <div class="field" style="grid-column:span 2"><label>Purpose of Collaboration <span class="req">*</span></label><input class="input" name="purpose" required></div>
        <div class="field"><label>Document Link <span class="req">*</span></label><input class="input" name="document_link" type="url" required></div>

      <?php elseif ($selectedType === 'event'): ?>
        <?php render_dept_field($user, $departments, 'Department', true); ?>
        <?php render_exam_session_field($effectiveYear); ?>
        <div class="field"><label>Date <span class="req">*</span> <span class="card-sub">(dd/mm/yyyy)</span></label><input class="input" name="event_date" type="date" required></div>
        <div class="field" style="grid-column:span 2"><label>Event Title <span class="req">*</span></label><input class="input" name="event_title" required></div>
        <div class="field"><label>Event Type <span class="req">*</span></label>
          <select class="select js-other" name="event_type" data-other="event_type_other" required><option>Seminar</option><option>Workshop</option><option>Webinar</option><option>FDP</option><option>Conference</option><option>Symposium</option><option>Guest Lecture</option><option>Others</option></select>
          <input class="input js-other-text" name="event_type_other" placeholder="Specify the event type" style="margin-top:8px;display:none"></div>
        <?php render_event_mode_fields(); ?>
        <div class="field" style="grid-column:span 2"><label>Chief Guest / Resource Person <span class="req">*</span> <span class="card-sub">— Name &amp; Designation (with Contact Details)</span></label><input class="input" name="resource_person" required></div>
        <div class="field"><label>No. of Participants <span class="req">*</span></label><input class="input" name="participants" type="number" min="1" required></div>
        <div class="field"><label>Sponsorship <span class="req">*</span> <span class="card-sub">(if any, write N/A if none)</span></label><input class="input" name="sponsorship" required></div>
        <div class="field" style="grid-column:span 2"><label>Web Link to Event Report <span class="req">*</span></label><input class="input" name="report_link" type="url" required></div>

      <?php elseif ($selectedType === 'nptel'): ?>
        <?php
          // Classification follows the participant, never the uploader.
          $nptelFaculty = nptel_participant_faculty($user);
          $nptelDepts   = nptel_student_departments($user, $departments);
          $nptelOwnDept = null;
          foreach ($nptelDepts as $nd) {
              if (department_names_match($nd, $user['department'] ?? '')) { $nptelOwnDept = $nd; break; }
          }
        ?>
        <div class="field"><label>Participant Type (Category) <span class="req">*</span> <span class="card-sub">— who earned the certificate</span></label>
          <select class="select" name="category" id="nptelType" required>
            <option value="" disabled selected>Select Faculty or Student</option>
            <?php foreach (nptel_participant_types() as $pt): ?>
              <option value="<?= e($pt) ?>"><?= e($pt) ?></option>
            <?php endforeach; ?>
          </select></div>
        <div class="field"><label>Academic Year</label><input class="input" value="<?= e($editRecord['academic_year'] ?? $activeYear) ?>" readonly style="background:var(--bg-subtle, #f3f4f6); cursor:not-allowed;" title="Records are submitted in the active academic year."></div>

        <div class="field js-nptel-part" data-for="Faculty" hidden><label>Faculty Participant <span class="req">*</span></label>
          <select class="select" name="participant_user_id" id="nptelFaculty">
            <?php if (count($nptelFaculty) !== 1): ?><option value="">Select faculty member</option><?php endif; ?>
            <?php foreach ($nptelFaculty as $nf): ?>
              <option value="<?= (int) $nf['id'] ?>" data-dept="<?= e($nf['department'] ?? '') ?>"><?= e($nf['name'] . ' — ' . ($nf['department'] ?: 'No dept') . ' (' . $nf['role'] . ')') ?></option>
            <?php endforeach; ?>
          </select></div>
        <div class="field js-nptel-part" data-for="Faculty" hidden><label>Department <span class="card-sub">— the faculty member's</span></label>
          <input class="input" id="nptelFacultyDept" readonly style="background:var(--bg-subtle, #f3f4f6); cursor:not-allowed;"></div>

        <div class="field js-nptel-part" data-for="Student" hidden><label>Student Register No. <span class="req">*</span></label>
          <input class="input" name="reg_no" maxlength="30" placeholder="e.g. 311522104001"></div>
        <div class="field js-nptel-part" data-for="Student" hidden><label>Student Name <span class="req">*</span></label>
          <input class="input" name="candidate_name"></div>
        <div class="field js-nptel-part" data-for="Student" hidden><label>Student's Department <span class="req">*</span></label>
          <select class="select" name="department">
            <?php foreach ($nptelDepts as $nd): ?>
              <option value="<?= e($nd) ?>" <?= $nd === $nptelOwnDept ? 'selected' : '' ?>><?= e($nd) ?></option>
            <?php endforeach; ?>
          </select></div>

        <div class="field" style="grid-column:span 2"><label>Course Title <span class="req">*</span></label><input class="input" name="course_title" required></div>
        <div class="field"><label>Session <span class="req">*</span></label><input class="input" name="session" placeholder="e.g. Jul 2025 - Dec 2025" required></div>
        <?php render_exam_session_field($effectiveYear); ?>
        <div class="field"><label>Grade <span class="req">*</span></label>
          <select class="select" name="grade" required>
            <option value="" disabled selected>Select grade</option>
            <?php foreach (nptel_grades() as $g): ?>
              <option value="<?= e($g) ?>"><?= e($g) ?></option>
            <?php endforeach; ?>
          </select></div>
        <div class="field"><label>NPTEL Topper <span class="req">*</span></label>
          <select class="select" name="is_topper" required>
            <option value="" disabled selected>Select Yes or No</option>
            <option value="1">Yes</option>
            <option value="0">No</option>
          </select></div>
        <div class="field" style="grid-column:span 2"><label>Certificate Link <span class="req">*</span></label><input class="input" name="certificate_link" type="url" required></div>

        <script>
        (function () {
          var type = document.getElementById('nptelType');
          var fac  = document.getElementById('nptelFaculty');
          var dept = document.getElementById('nptelFacultyDept');
          if (!type) return;
          // Only the chosen participant's fields are shown, required and submitted.
          function sync() {
            document.querySelectorAll('.js-nptel-part').forEach(function (box) {
              var on = box.getAttribute('data-for') === type.value;
              box.hidden = !on;
              box.querySelectorAll('input, select').forEach(function (el) {
                el.disabled = !on;
                if (el.name) el.required = on;
              });
            });
            if (fac && dept) {
              var opt = fac.options[fac.selectedIndex];
              dept.value = opt ? (opt.getAttribute('data-dept') || '') : '';
            }
          }
          type.addEventListener('change', sync);
          if (fac) fac.addEventListener('change', sync);
          sync();
          // Run again once an edit or saved draft has filled the form in.
          document.addEventListener('DOMContentLoaded', function () { setTimeout(sync, 0); });
        })();
        </script>

      <?php elseif ($selectedType === 'internship'): ?>
        <div class="field"><label>Reg. No <span class="req">*</span></label><input class="input" name="reg_no" required></div>
        <div class="field"><label>Name of the student <span class="req">*</span></label><input class="input" name="student_name" required></div>
        <?php render_dept_field($user, $departments, 'Dept / Branch', true); ?>
        <?php render_exam_session_field($effectiveYear); ?>
        <div class="field" style="grid-column:span 2"><label>Title of Internship <span class="req">*</span></label><input class="input" name="title" required></div>
        <div class="field" style="grid-column:span 2"><label>Industry/Institution Name &amp; Address <span class="req">*</span></label><input class="input" name="industry" required></div>
        <div class="field"><label>Duration <span class="req">*</span></label><input class="input" name="duration" placeholder="e.g. 1 month" required></div>
        <div class="field"><label>No. of Days <span class="req">*</span></label><input class="input" name="days" type="number" min="1" required></div>
        <div class="field" style="grid-column:span 2"><label>Link to the Certificate / Document <span class="req">*</span></label><input class="input" name="certificate_link" type="url" required></div>

      <?php elseif ($selectedType === 'placement'): ?>
        <div class="field"><label>Reg. No <span class="req">*</span></label><input class="input" name="reg_no" required></div>
        <div class="field"><label>Student Name <span class="req">*</span></label><input class="input" name="student_name" required></div>
        <?php render_dept_field($user, $departments, 'Dept / Branch', true); ?>
        <?php render_exam_session_field($effectiveYear); ?>
        <div class="field"><label>Job Name <span class="req">*</span></label><input class="input" name="job_title" required></div>
        <div class="field"><label>Mode <span class="req">*</span> <span class="card-sub">(On Campus / Off Campus)</span></label><select class="select" name="mode" required><option>On Campus</option><option>Off Campus</option></select></div>
        <div class="field" style="grid-column:span 2"><label>Company Name &amp; Address <span class="req">*</span> <span class="card-sub">(with Contact Details)</span></label><input class="input" name="company" required></div>
        <div class="field"><label>Pay Scale <span class="req">*</span></label><input class="input" name="pay_scale" required></div>
        <div class="field" style="grid-column:span 2"><label>Web Link to Appointment Order <span class="req">*</span></label><input class="input" name="appointment_order_link" type="url" required></div>

      <?php elseif ($selectedType === 'nss'): ?>
        <?php render_dept_field($user, $departments, 'Department', true); ?>
        <div class="field"><label>Academic Year</label><input class="input" value="<?= e($effectiveYear) ?>" readonly style="background:var(--bg-subtle, #f3f4f6); cursor:not-allowed;" title="Records are submitted for academic year <?= e($effectiveYear) ?>."></div>
        <?php render_exam_session_field($effectiveYear); ?>
        <div class="field"><label>Date <span class="req">*</span> <span class="card-sub">(dd/mm/yyyy)</span></label><input class="input" name="activity_date" type="date" required></div>
        <div class="field"><label>Activity Type <span class="req">*</span></label><select class="select" name="activity_type" required><option>NSS</option><option>YRC</option><option>RRC</option></select></div>
        <div class="field" style="grid-column:span 2"><label>Name of the Activity <span class="req">*</span></label><input class="input" name="activity_name" required></div>
        <div class="field"><label>Venue <span class="req">*</span></label><input class="input" name="venue" required></div>
        <div class="field"><label>No. of Students Participated <span class="req">*</span></label><input class="input" name="participants" type="number" min="1" required></div>
        <div class="field" style="grid-column:span 2"><label>Name of External Agency / Member Involved <span class="req">*</span> <span class="card-sub">— Name &amp; Designation (with Contact Details)</span></label><input class="input" name="external_agency" required></div>
        <div class="field" style="grid-column:span 2"><label>Web Link to Event Report <span class="req">*</span></label><input class="input" name="report_link" type="url" required></div>

      <?php elseif ($selectedType === 'online_course'): ?>
        <?php render_dept_field($user, $departments, 'Department', true); ?>
        <div class="field"><label>Academic Year</label><input class="input" value="<?= e($effectiveYear) ?>" readonly style="background:var(--bg-subtle, #f3f4f6); cursor:not-allowed;" title="Records are submitted for academic year <?= e($effectiveYear) ?>."></div>
        <?php render_exam_session_field($effectiveYear); ?>
        <div class="field"><label>Candidate Name <span class="req">*</span></label><input class="input" name="candidate_name" required></div>
        <div class="field"><label>Category <span class="req">*</span></label><select class="select" name="category" required><option>Faculty</option><option>Student</option></select></div>
        <div class="field" style="grid-column:span 2"><label>Course Title <span class="req">*</span></label><input class="input" name="course_title" required></div>
        <div class="field"><label>Provider <span class="req">*</span> <span class="card-sub">(Coursera / NPTEL / Udemy / …)</span></label><input class="input" name="provider" required></div>
        <div class="field"><label>Duration <span class="req">*</span></label><input class="input" name="duration" placeholder="e.g. 8 weeks" required></div>
        <div class="field"><label>Month &amp; Year <span class="req">*</span> <span class="card-sub">(mm/yyyy)</span></label><input class="input" name="month_year" placeholder="e.g. 03/2026" required></div>
        <div class="field"><label>Certificate Link <span class="req">*</span></label><input class="input" name="certificate_link" type="url" required></div>

      <?php elseif ($selectedType === 'student_achievement' || $selectedType === 'student_participation'): ?>
        <?php render_dept_field($user, $departments, 'Dept / Branch', true); ?>
        <div class="field"><label>Academic Year</label><input class="input" value="<?= e($effectiveYear) ?>" readonly style="background:var(--bg-subtle, #f3f4f6); cursor:not-allowed;" title="Records are submitted for academic year <?= e($effectiveYear) ?>."></div>
        <?php render_exam_session_field($effectiveYear); ?>
        <?php if ($selectedType === 'student_participation'): ?>
        <div class="field"><label>Activity Category <span class="req">*</span></label><select class="select" name="activity_category" required><option>Co-curricular</option><option>Extra-curricular</option></select></div>
        <?php endif; ?>
        <div class="field"><label>Reg. No <span class="req">*</span></label><input class="input" name="reg_no" required></div>
        <div class="field"><label>Name of the student <span class="req">*</span></label><input class="input" name="student_name" required></div>
        <div class="field"><label>Event Type <span class="req">*</span></label><input class="input" name="event_type" placeholder="e.g. Technical / Sports / Cultural" required></div>
        <div class="field"><label>Name of the Event <span class="req">*</span></label><input class="input" name="event_name" required></div>
        <div class="field" style="grid-column:span 2"><label>Name of the Function / Programme <span class="req">*</span></label><input class="input" name="function_name" required></div>
        <div class="field"><label>Date of the Event <span class="req">*</span> <span class="card-sub">(dd/mm/yyyy)</span></label><input class="input" name="event_date" type="date" required></div>
        <div class="field"><label>Team / Individual <span class="req">*</span></label><select class="select" name="team_individual" required><option>Individual</option><option>Team</option></select></div>
        <div class="field"><label>Level <span class="req">*</span></label><select class="select" name="level_secured" required><option>University</option><option>State</option><option>National</option><option>International</option></select></div>
        <div class="field"><label>Position Secured <span class="req">*</span></label><input class="input" name="position_secured" placeholder="e.g. First / Winner / Participant" required></div>
        <div class="field" style="grid-column:span 2"><label>Name of the Organising Institution <span class="req">*</span></label><input class="input" name="organising_institution" required></div>
        <div class="field" style="grid-column:span 2"><label>Link to the Certificate / Document <span class="req">*</span></label><input class="input" name="certificate_link" type="url" required></div>

      <?php elseif ($selectedType === 'summer_training'): ?>
        <?php render_dept_field($user, $departments, 'Dept / Branch', true); ?>
        <div class="field"><label>Academic Year</label><input class="input" value="<?= e($effectiveYear) ?>" readonly style="background:var(--bg-subtle, #f3f4f6); cursor:not-allowed;" title="Records are submitted for academic year <?= e($effectiveYear) ?>."></div>
        <?php render_exam_session_field($effectiveYear); ?>
        <div class="field"><label>Reg. No <span class="req">*</span></label><input class="input" name="reg_no" required></div>
        <div class="field"><label>Name of the student <span class="req">*</span></label><input class="input" name="student_name" required></div>
        <div class="field" style="grid-column:span 2"><label>Title of Training <span class="req">*</span></label><input class="input" name="title" required></div>
        <div class="field" style="grid-column:span 2"><label>Industry/Institution Name &amp; Address <span class="req">*</span></label><input class="input" name="industry" required></div>
        <div class="field"><label>Duration <span class="req">*</span></label><input class="input" name="duration" placeholder="e.g. 1 month" required></div>
        <div class="field"><label>No. of Days <span class="req">*</span></label><input class="input" name="days" type="number" min="1" required></div>
        <div class="field" style="grid-column:span 2"><label>Link to the Certificate / Document <span class="req">*</span></label><input class="input" name="certificate_link" type="url" required></div>

      <?php elseif ($selectedType === 'value_added'): ?>
        <?php render_dept_field($user, $departments, 'Department', true); ?>
        <div class="field"><label>Academic Year</label><input class="input" value="<?= e($effectiveYear) ?>" readonly style="background:var(--bg-subtle, #f3f4f6); cursor:not-allowed;" title="Records are submitted for academic year <?= e($effectiveYear) ?>."></div>
        <?php render_exam_session_field($effectiveYear); ?>
        <div class="field"><label>From Date <span class="req">*</span> <span class="card-sub">(dd/mm/yyyy)</span></label><input class="input" name="from_date" type="date" required></div>
        <div class="field"><label>To Date <span class="req">*</span> <span class="card-sub">(dd/mm/yyyy)</span></label><input class="input" name="to_date" type="date" required></div>
        <div class="field" style="grid-column:span 2"><label>Course Title <span class="req">*</span></label><input class="input" name="course_title" required></div>
        <div class="field"><label>Mode <span class="req">*</span></label><select class="select" name="mode" required><option>Online</option><option>Offline</option><option>Hybrid</option></select></div>
        <div class="field"><label>No. of Participants <span class="req">*</span></label><input class="input" name="participants" type="number" min="1" required></div>
        <div class="field" style="grid-column:span 2"><label>Resource Person <span class="req">*</span> <span class="card-sub">— Name &amp; Designation (with Contact Details)</span></label><input class="input" name="resource_person" required></div>
        <div class="field" style="grid-column:span 2"><label>Web Link to Event Report <span class="req">*</span></label><input class="input" name="report_link" type="url" required></div>

      <?php elseif ($selectedType === 'training'): ?>
        <?php render_dept_field($user, $departments, 'Department', true); ?>
        <div class="field"><label>Academic Year</label><input class="input" value="<?= e($effectiveYear) ?>" readonly style="background:var(--bg-subtle, #f3f4f6); cursor:not-allowed;" title="Records are submitted for academic year <?= e($effectiveYear) ?>."></div>
        <?php render_exam_session_field($effectiveYear); ?>
        <div class="field"><label>Date <span class="req">*</span> <span class="card-sub">(dd/mm/yyyy)</span></label><input class="input" name="event_date" type="date" required></div>
        <div class="field" style="grid-column:span 2"><label>Event Title <span class="req">*</span></label><input class="input" name="event_title" required></div>
        <div class="field"><label>Event Type <span class="req">*</span></label><select class="select" name="event_type" required><option>Career Guidance</option><option>Counselling</option><option>ICT</option><option>Life Skills</option><option>Soft Skills</option></select></div>
        <?php render_event_mode_fields(); ?>
        <div class="field"><label>No. of Participants <span class="req">*</span></label><input class="input" name="participants" type="number" min="1" required></div>
        <div class="field"><label>Sponsorship <span class="req">*</span> <span class="card-sub">(if any, write N/A if none)</span></label><input class="input" name="sponsorship" required></div>
        <div class="field" style="grid-column:span 2"><label>Chief Guest / Resource Person <span class="req">*</span> <span class="card-sub">— Name &amp; Designation (with Contact Details)</span></label><input class="input" name="resource_person" required></div>
        <div class="field" style="grid-column:span 2"><label>Web Link to Event Report <span class="req">*</span></label><input class="input" name="report_link" type="url" required></div>
      <?php endif; ?>

      <?php require __DIR__ . '/inc/inst_upload_forms.php'; ?>

        <!-- Proof / attachment — carried onto the report (Journal places it above its link fields).
             Institutional forms keep it optional, matching their handler. -->
        <?php if ($selectedType !== 'journal') render_proof_field($editRecord, !str_starts_with($selectedType, 'inst_')); ?>
      </div>
      </fieldset>

      <!-- Save this entry and add another of the same type, move on to the next
           metric, or finish on the last one. -->
      <div class="upload-actions">
        <?php if ($isFormDisabled): ?>
          <button type="button" class="btn btn-secondary" disabled style="cursor:not-allowed; opacity:0.85; font-weight:600; display:inline-flex; align-items:center; gap:6px;">
            <?= icon('lock', 16) ?> <?= $isRecordEmLocked ? 'EM1 Closed — Record Locked' : ($isEmLocked ? 'EM1 Closed — Submissions Locked' : 'Academic Year Locked') ?>
          </button>
          <div class="spacer"></div>
          <?php if ($editRecord): ?>
            <a class="btn btn-ghost" href="<?= e(url('approvals.php')) ?>"><?= icon('arrow-left') ?> Back to Approvals</a>
          <?php else: ?>
            <?php if ($prevType): ?>
              <a class="btn btn-ghost" href="<?= e(url('upload.php?type=' . $prevType)) ?>"><?= icon('arrow-left') ?> Back</a>
            <?php endif; ?>
            <?php if (!$isLast): ?>
              <a href="<?= e(url('upload.php?type=' . $nextType)) ?>" class="btn btn-outline">
                Next: <?= e($types[$nextType]['label']) ?> <?= icon('arrow-right') ?>
              </a>
            <?php endif; ?>
          <?php endif; ?>
        <?php elseif ($editId > 0 || !empty($editRecord)): ?>
          <button type="submit" name="nav" value="submit" class="btn btn-primary" style="background:#1D4ED8;border-color:#1D4ED8;font-weight:600;display:inline-flex;align-items:center;gap:6px">
            <?= icon('check') ?> Save &amp; Resubmit Record
          </button>
          <div class="spacer"></div>
          <a class="btn btn-ghost" href="<?= e(url('approvals.php')) ?>"><?= icon('arrow-left') ?> Cancel &amp; Back to Approvals</a>
        <?php else: ?>
          <button type="submit" name="nav" value="add" class="btn btn-outline"><?= icon('plus') ?> Save &amp; add another</button>
          <div class="spacer"></div>
          <?php if ($prevType): ?>
            <a class="btn btn-ghost" href="<?= e(url('upload.php?type=' . $prevType)) ?>"><?= icon('arrow-left') ?> Back</a>
          <?php endif; ?>
          <?php if (!$isLast): ?>
            <button type="submit" name="nav" value="next" class="btn btn-primary">
              Next: <?= e($types[$nextType]['label']) ?> <?= icon('arrow-right') ?>
            </button>
          <?php else: ?>
            <button type="submit" name="nav" value="submit" class="btn btn-primary"><?= icon('check') ?> Submit for Review</button>
          <?php endif; ?>

        <?php endif; ?>
      </div>

      <?php if (!empty($editRecord)): ?>
        <script>
        document.addEventListener('DOMContentLoaded', function() {
          const editData = <?= json_encode($editRecord) ?>;
          if (!editData) return;
          for (const [key, val] of Object.entries(editData)) {
            if (val === null || val === undefined || key === 'id' || key === 'proof_file') continue;
            const el = document.querySelector(`[name="${key}"]`);
            if (el) {
              if (el.tagName === 'SELECT') {
                el.value = val;
                if (el.classList.contains('js-other')) {
                  el.dispatchEvent(new Event('change'));
                }
              } else if (el.type !== 'file' && el.type !== 'hidden') {
                el.value = val;
              }
            }
          }
          <?php if (!empty($activeEditRequest['specific_field'])): ?>
            const targetField = <?= json_encode($activeEditRequest['specific_field']) ?>;
            const targetEl = document.querySelector(`[name="${targetField}"]`) || document.querySelector(`[name*="${targetField.toLowerCase()}"]`);
            if (targetEl) {
              targetEl.style.border = '2px solid #2563EB';
              targetEl.style.boxShadow = '0 0 0 4px rgba(37, 99, 235, 0.15)';
              targetEl.style.background = '#F0FDF4';
              targetEl.focus();
            }
          <?php endif; ?>
        });
        </script>
      <?php endif; ?>
    </form>

    <script>
      document.querySelectorAll('.js-other').forEach(function (sel) {
        var box = sel.parentElement.querySelector('.js-other-text');
        if (!box) return;
        function sync() {
          var on = sel.value === 'Others';
          box.style.display = on ? '' : 'none';
          box.required = on;
          if (!on) box.value = '';
        }
        sel.addEventListener('change', sync);
        sync();
      });

      // Event Mode: show the venue and / or online fields the chosen mode needs.
      // Hidden fields keep their values so switching back loses nothing; the
      // server drops whatever the final mode does not use.
      (function () {
        var modeSel = document.querySelector('.js-event-mode');
        if (!modeSel) return;
        var blocks = document.querySelectorAll('[data-event-mode-for]');
        function syncMode() {
          var mode = modeSel.value;
          blocks.forEach(function (b) {
            var on = mode !== '' && b.getAttribute('data-event-mode-for').split(' ').indexOf(mode) !== -1;
            b.style.display = on ? '' : 'none';
            b.querySelectorAll('[data-mode-required]').forEach(function (input) { input.required = on; });
          });
        }
        modeSel.addEventListener('change', syncMode);
        syncMode();
        window.addEventListener('load', syncMode);   // after edit / draft values are filled in
      })();

      // Journal / Book authors: show one name + position pair per co-author, offer
      // positions 1..total only, and flag a repeated faculty member or
      // position before the form is sent (the server checks all of it again).
      // Hidden rows keep their values, so lowering the count loses nothing.
      (function () {
        var countSel = document.getElementById('pubCoCount');
        if (!countSel) return;
        var mainSel  = document.getElementById('pubMainAuthor');
        var deptBox  = document.getElementById('pubMainDept');
        var totalBox = document.getElementById('pubTotalAuthors');
        var msgBox   = document.getElementById('pubAuthorMsg');
        var mainPos  = document.querySelector('[name="main_author_position"]');

        function ordinal(n) {
          var s = ['th', 'st', 'nd', 'rd'], v = n % 100;
          return n + (s[(v - 20) % 10] || s[v] || s[0]) + ' Author';
        }

        function sync() {
          var count = parseInt(countSel.value, 10) || 0;
          var total = count + 1;
          if (totalBox) totalBox.textContent = total;
          if (deptBox && mainSel) {
            var opt = mainSel.options[mainSel.selectedIndex];
            deptBox.value = opt ? (opt.getAttribute('data-dept') || '') : '';
          }

          var authors = [{ label: 'Main Author', who: mainSel, pos: mainPos }];
          document.querySelectorAll('[data-co-row]').forEach(function (box) {
            var i = parseInt(box.getAttribute('data-co-row'), 10);
            var on = i <= count;
            box.style.display = on ? '' : 'none';
            box.querySelectorAll('select').forEach(function (s) { s.required = on; s.setCustomValidity(''); });
          });
          for (var i = 1; i <= count; i++) {
            authors.push({
              label: 'Co-Author ' + i,
              who: document.querySelector('[name="co_author_id_' + i + '"]'),
              pos: document.querySelector('[name="co_author_pos_' + i + '"]')
            });
          }

          document.querySelectorAll('.js-pub-pos').forEach(function (s) {
            Array.prototype.forEach.call(s.options, function (o) {
              if (o.value === '') return;
              var off = parseInt(o.value, 10) > total;
              o.disabled = off;
              o.hidden = off;
            });
          });

          var problems = [], seenWho = {}, seenPos = {};
          authors.forEach(function (a) {
            if (a.who) a.who.setCustomValidity('');
            if (a.pos) a.pos.setCustomValidity('');
          });
          authors.forEach(function (a) {
            if (a.who && a.who.value) {
              if (seenWho[a.who.value]) {
                var m = a.label + ' is the same faculty member as ' + seenWho[a.who.value] + '.';
                a.who.setCustomValidity(m); problems.push(m);
              } else {
                seenWho[a.who.value] = a.label;
              }
            }
            if (a.pos && a.pos.value) {
              var p = parseInt(a.pos.value, 10);
              if (p > total) {
                var m2 = a.label + ': choose a position from 1st Author to ' + ordinal(total) + '.';
                a.pos.setCustomValidity(m2); problems.push(m2);
              } else if (seenPos[p]) {
                var m3 = a.label + ' and ' + seenPos[p] + ' are both ' + ordinal(p) + '. Every author needs a different position.';
                a.pos.setCustomValidity(m3); problems.push(m3);
              } else {
                seenPos[p] = a.label;
              }
            }
          });
          if (msgBox) {
            msgBox.textContent = problems.join(' ');
            msgBox.style.display = problems.length ? '' : 'none';
          }
        }

        document.querySelectorAll('#pubCoCount, .js-pub-author, .js-pub-pos').forEach(function (s) {
          s.addEventListener('change', sync);
        });
        sync();
        window.addEventListener('load', sync);   // after edit / draft values are filled in

        // Title fields: capitals as you type; spaces trimmed and collapsed on leaving.
        document.querySelectorAll('.js-caps, .js-upper').forEach(function (el) {
          el.addEventListener('input', function () {
            var at = el.selectionStart, up = el.value.toUpperCase();
            if (up !== el.value) { el.value = up; try { el.setSelectionRange(at, at); } catch (e) {} }
          });
          el.addEventListener('blur', function () {
            el.value = el.value.replace(/\s+/g, ' ').trim();
          });
        });
        // Book title / publisher: case and punctuation kept as typed; only
        // the spaces are trimmed and collapsed on leaving.
        document.querySelectorAll('.js-trim').forEach(function (el) {
          el.addEventListener('blur', function () {
            el.value = el.value.replace(/\s+/g, ' ').trim();
          });
        });
      })();

      var proofInput = document.getElementById('proofInput');
      if (proofInput) {
        var MAX_PROOF_BYTES = 2 * 1024 * 1024; // 2 MB
        var proofErr = document.getElementById('proofSizeError');

        proofInput.addEventListener('change', function () {
          var file = this.files && this.files[0];
          if (!file) return;

          if (file.size > MAX_PROOF_BYTES) {
            var sizeMb = (file.size / (1024 * 1024)).toFixed(2);
            alert('File size exceeds the 2 MB limit (' + sizeMb + ' MB). Only attachments of 2 MB or less can be selected.');
            this.value = ''; // Immediately clear selection
            if (proofErr) {
              proofErr.textContent = 'Selected file (' + sizeMb + ' MB) exceeds 2 MB limit. Selection cleared. Please choose a file ≤ 2 MB.';
              proofErr.style.display = 'block';
            }
            return false;
          }

          var name = file.name.toLowerCase();
          if (!name.endsWith('.pdf')) {
            alert('Only PDF files (.pdf) are allowed as proof attachments.');
            this.value = '';
            if (proofErr) {
              proofErr.textContent = 'Only PDF files are supported. Selection cleared.';
              proofErr.style.display = 'block';
            }
            return false;
          }

          if (proofErr) {
            proofErr.style.display = 'none';
          }
        });
      }

      (function () {
        var form = document.querySelector('.card-body form');
        if (!form) return;
        var isEditing = <?= !empty($editRecord) ? 'true' : 'false' ?>;
        if (isEditing) return; // Do not overwrite active record edit with unrelated draft!
        var activeYear = <?= json_encode($activeYear) ?>;
        var recordType = <?= json_encode($selectedType) ?>;
        var storageKey = 'atts_upload_draft_' + activeYear + '_' + recordType;
        var submittedDraftType = <?= json_encode($submittedDraftType) ?>;
        if (submittedDraftType) {
          try {
            sessionStorage.removeItem('atts_upload_draft_' + activeYear + '_' + submittedDraftType);
            sessionStorage.removeItem('faculty_upload_draft_' + <?= json_encode((int)$user['id']) ?> + '_' + submittedDraftType);
          } catch (e) {}
        }

        function showDraftStatus(text) {
          var statusEl = document.querySelector('.js-draft-status');
          if (!statusEl) return;
          statusEl.textContent = text;
          statusEl.style.opacity = '1';
          setTimeout(function () {
            statusEl.style.opacity = '0';
          }, 2000);
        }

        function getDraftData() {
          var draft = {};
          var elements = form.querySelectorAll('input, select, textarea');
          elements.forEach(function (el) {
            var name = el.name;
            if (!name || name === 'csrf' || name === 'record_type' || name === 'proof' || name === 'nav') return;
            if (el.type === 'file' || el.type === 'password' || el.type === 'hidden') return;

            if (el.type === 'checkbox') {
              draft[name] = el.checked;
            } else if (el.type === 'radio') {
              if (el.checked) {
                draft[name] = el.value;
              }
            } else {
              draft[name] = el.value;
            }
          });
          return draft;
        }

        function hasDraftData(draft) {
          if (!draft || typeof draft !== 'object') return false;
          var keys = Object.keys(draft);
          for (var i = 0; i < keys.length; i++) {
            var k = keys[i];
            if (k === 'faculty_name' || k === 'department' || k === 'academic_year') continue;
            var val = draft[k];
            if (typeof val === 'string' && val.trim() !== '') return true;
            if (typeof val === 'boolean' && val) return true;
          }
          return false;
        }

        function saveDraft() {
          try {
            var draft = getDraftData();
            if (hasDraftData(draft)) {
              sessionStorage.setItem(storageKey, JSON.stringify(draft));
              showDraftStatus('Draft saved');
            } else {
              sessionStorage.removeItem(storageKey);
            }
          } catch (e) {
            console.warn('Unable to save form draft to sessionStorage:', e);
          }
        }

        var saveTimeout = null;
        function debouncedSaveDraft() {
          clearTimeout(saveTimeout);
          saveTimeout = setTimeout(saveDraft, 200);
        }

        var isRestoring = false;
        function restoreDraft() {
          try {
            var raw = sessionStorage.getItem(storageKey);
            if (!raw) return;
            var draft = JSON.parse(raw);
            if (!draft || typeof draft !== 'object') return;

            isRestoring = true;
            var restoredAny = false;

            Object.keys(draft).forEach(function (name) {
              var el = form.elements[name];
              if (!el) return;
              if (el.tagName === 'SELECT') {
                el.value = draft[name];
                el.dispatchEvent(new Event('change', { bubbles: true }));
                restoredAny = true;
              }
            });

            Object.keys(draft).forEach(function (name) {
              var el = form.elements[name];
              if (!el) return;
              if (el.tagName === 'SELECT') return;

              var nodes = (el instanceof NodeList || el instanceof HTMLCollection) ? Array.prototype.slice.call(el) : [el];

              nodes.forEach(function (input) {
                if (!input || input.type === 'file' || input.type === 'password' || input.type === 'hidden') return;

                if (input.type === 'checkbox') {
                  input.checked = !!draft[name];
                  restoredAny = true;
                } else if (input.type === 'radio') {
                  input.checked = (input.value === draft[name]);
                  restoredAny = true;
                } else {
                  input.value = draft[name];
                  restoredAny = true;
                }
              });
            });

            document.querySelectorAll('.js-other').forEach(function (sel) {
              var otherName = sel.getAttribute('data-other');
              var box = otherName ? form.elements[otherName] : null;
              if (box) {
                var on = sel.value === 'Others';
                box.style.display = on ? '' : 'none';
                box.required = on;
                if (on && draft[otherName] !== undefined) {
                  box.value = draft[otherName];
                }
              }
            });

            if (restoredAny) {
              showDraftStatus('Draft restored');
            }
          } catch (e) {
            console.warn('Unable to restore form draft from sessionStorage:', e);
          } finally {
            isRestoring = false;
          }
        }

        form.addEventListener('input', function () {
          if (isRestoring) return;
          debouncedSaveDraft();
        });
        form.addEventListener('change', function () {
          if (isRestoring) return;
          saveDraft();
        });

        document.querySelectorAll('.js-category-tab').forEach(function (tab) {
          tab.addEventListener('click', function () {
            clearTimeout(saveTimeout);
            saveDraft();
          });
        });

        window.addEventListener('beforeunload', function () {
          clearTimeout(saveTimeout);
          saveDraft();
        });
        window.addEventListener('pagehide', function () {
          clearTimeout(saveTimeout);
          saveDraft();
        });
        var serverDraft = <?= json_encode($editRecord ?? null) ?>;
        function restoreServerDraft() {
          if (!serverDraft || typeof serverDraft !== 'object') return false;
          try {
            isRestoring = true;
            Object.keys(serverDraft).forEach(function (name) {
              var el = form.elements[name];
              if (!el) return;
              if (el.tagName === 'SELECT') {
                el.value = serverDraft[name];
                el.dispatchEvent(new Event('change', { bubbles: true }));
              }
            });
            Object.keys(serverDraft).forEach(function (name) {
              var el = form.elements[name];
              if (!el) return;
              if (el.type === 'file' || el.type === 'hidden') return;
              if (el.tagName !== 'SELECT') {
                el.value = serverDraft[name];
              }
            });
            isRestoring = false;
            return true;
          } catch (e) {
            isRestoring = false;
            return false;
          }
        }

        function initDraftLifecycle() {
          if (serverDraft) {
            restoreServerDraft();
          } else {
            restoreDraft();
          }
          updateDraftUI(getDraftData());
        }

        if (document.readyState === 'loading') {
          document.addEventListener('DOMContentLoaded', initDraftLifecycle);
        } else {
          initDraftLifecycle();
        }
      })();
    </script>
  </div>
</div>

<!-- My recent records -->
<?php
  $mTypeRaw = isset($_GET['mtype']) ? (string) $_GET['mtype'] : null;
  if ($mTypeRaw === null) {
      $mType = $selectedType;
  } elseif ($mTypeRaw === '' || $mTypeRaw === 'all') {
      $mType = '';
  } elseif (isset($types[$mTypeRaw])) {
      $mType = $mTypeRaw;
  } else {
      $mType = $selectedType;
  }
  $mStatus = (string) input('mstatus');
  if (!in_array($mStatus, ['Draft', 'HOD Pending', 'Dean Pending', 'Submitted', 'Approved', 'Rejected'], true)) { $mStatus = ''; }
  $mQ      = trim((string) input('mq'));
  // Event Mode filter: offered while the list can hold events (all types or an event type).
  $mModeOn = $mType === '' || is_event_type($mType);
  $mMode   = $mModeOn ? event_mode_normalize(input('mmode')) : null;

  $shown = $myRecords;
  if ($mType   !== '') { $shown = array_filter($shown, fn($r) => $r['_type_key'] === $mType); }
  if ($mMode   !== null) { $shown = array_filter($shown, fn($r) => is_event_type($r['_type_key'] ?? '') && event_mode_normalize($r['mode'] ?? '') === $mMode); }
  if ($mStatus !== '') { $shown = array_filter($shown, fn($r) => ($r['status'] ?? '') === $mStatus); }
  if ($mQ      !== '') {
    $needle = mb_strtolower($mQ);
    $shown = array_filter($shown, fn($r) => mb_strpos(mb_strtolower((string) ($r['_title'] ?? '')), $needle) !== false);
  }
  $shown = array_values($shown);
  $mFilter = $mType !== '' || $mStatus !== '' || $mQ !== '' || $mMode !== null;
?>
<?php $mActive = ($mType !== '' ? 1 : 0) + ($mStatus !== '' ? 1 : 0) + ($mQ !== '' ? 1 : 0) + ($mMode !== null ? 1 : 0); ?>
<div class="card">
  <div class="card-head">
    <div><div class="card-title">My Submissions</div>
      <div class="card-sub"><?= $mFilter ? count($shown) . ' of ' . count($myRecords) : count($myRecords) . ' total' ?> records</div></div>
  </div>
  <form method="get" class="fbar fbar-flush">
    <input type="hidden" name="type" value="<?= e($selectedType) ?>">

    <label class="fb-field"><span class="fb-k">Record type</span>
      <select name="mtype" onchange="this.form.submit()">
        <option value="">All</option>
        <?php foreach ($types as $key => $t): ?>
          <option value="<?= e($key) ?>" <?= $mType === $key ? 'selected' : '' ?>><?= e($t['label']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>

    <?php if ($mModeOn): ?>
      <label class="fb-field" title="Online / Offline / Hybrid — applies to events"><span class="fb-k">Event Mode</span>
        <select name="mmode" onchange="this.form.submit()">
          <option value="">All Modes</option>
          <?php foreach (event_modes() as $m): ?>
            <option value="<?= e($m) ?>" <?= $mMode === $m ? 'selected' : '' ?>><?= e($m) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
    <?php endif; ?>

    <label class="fb-field"><span class="fb-k">Status</span>
      <select name="mstatus" onchange="this.form.submit()">
        <option value="">All</option>
        <?php foreach (['Approved', 'Dean Pending', 'HOD Pending', 'Submitted', 'Draft', 'Rejected'] as $o): ?>
          <option value="<?= $o ?>" <?= $mStatus === $o ? 'selected' : '' ?>><?= $o ?></option>
        <?php endforeach; ?>
      </select>
    </label>

    <label class="fb-field fb-search">
      <?= icon('search', 15) ?>
      <input type="search" name="mq" value="<?= e($mQ) ?>" placeholder="Search title…" aria-label="Search your submissions">
      <button type="submit" class="fb-go" title="Search" aria-label="Search"><?= icon('arrow-right', 14) ?></button>
    </label>

    <span class="fbar-end">
      <?php if ($mActive): ?>
        <span class="fbar-count"><?= (int) ($mActive) ?> active</span>
        <a class="fbar-clear" href="<?= e(url('upload.php?type=' . $selectedType)) ?>"><?= icon('x', 13) ?> Clear all</a>
      <?php else: ?>
        <span class="fbar-note">All your submissions</span>
      <?php endif; ?>
    </span>
  </form>
  <div class="card-body" style="padding:0">
    <?php if (empty($shown)): ?>
      <div class="empty"><div class="ic"><?= icon($mFilter ? 'filter' : 'upload', 20) ?></div><p><?= $mFilter ? 'No submissions match these filters' : 'No submissions yet' ?></p></div>
    <?php else: ?>
      <div class="table-wrap"><table class="data"><thead><tr>
        <th style="padding-left:24px">Record</th><th>Type</th><th>Proof</th><th>Status</th><th>Submitted</th>
      </tr></thead><tbody>
      <?php foreach (array_slice($shown, 0, 50) as $r):
        $statusBadge = ['Draft'=>'neutral','Submitted'=>'info','HOD Pending'=>'info','Dean Pending'=>'warning','Approved'=>'success','Rejected'=>'danger'];
      ?>
        <tr>
          <td style="padding-left:24px"><div style="font-weight:500;max-width:350px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= e($r['_title']) ?></div></td>
          <td><span class="badge badge-neutral"><?= e($r['_type_label']) ?></span><?php if (is_event_type($r['_type_key'] ?? '')): ?> <?= event_mode_badge($r['mode'] ?? null) ?><?php endif; ?></td>
          <td>
            <?= render_proof_cell($r['proof_file'] ?? null, $r['_type_key'] ?? null, (int)($r['id'] ?? 0)) ?>
          </td>
          <td>
            <span class="badge badge-<?= $statusBadge[$r['status']] ?? 'neutral' ?>"><?= e($r['status']) ?></span>
            <?php if (($r['status'] ?? '') === 'Draft'): ?>
              <a href="<?= e(url('upload.php?type=' . urlencode($r['_type_key']) . '&edit_id=' . (int)$r['id'])) ?>" class="btn btn-ghost btn-sm" style="margin-left:6px; padding:2px 8px; font-size:11px;" title="<?= record_requires_approval($r['_type_key']) ? 'Submit and Review this draft' : 'Submit this draft' ?>"><?= icon('check', 12) ?> <?= record_requires_approval($r['_type_key']) ? 'Submit and Review' : 'Submit' ?></a>
            <?php endif; ?>
          </td>
          <td class="card-sub" title="<?= e(date('d M Y, h:i A', strtotime($r['created_at']))) ?>"><?= e(time_ago($r['created_at'])) ?></td>
        </tr>
      <?php endforeach; ?></tbody></table></div>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/inc/footer.php'; ?>
