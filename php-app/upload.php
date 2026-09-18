<?php
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/record_specs.php';
require_once __DIR__ . '/models/Record.php';
require_once __DIR__ . '/models/Department.php';
require_once __DIR__ . '/models/Target.php';   // academic_years()
require_once __DIR__ . '/models/ExecutiveMeeting.php';   // FEAT-07 EM1 lock
require_once __DIR__ . '/models/UploadFlow.php';         // Academic Year → Data Type entry flow

$user = require_role(['Admin', 'HoD', 'Coordinator', 'Faculty']);
require_module('upload');

$types       = record_types();
$departments = departments_all();
$years       = academic_years();
$typeKeys    = array_keys($types);
$activeYear  = active_academic_year();
journal_process_approval_expiry();

if (!defined('PROOF_MAX_BYTES')) {
    define('PROOF_MAX_BYTES', 2 * 1024 * 1024);   // 2 MB
}

/**
 * Save one uploaded proof file. Only a PDF (up to 2 MB) is accepted.
 * Files are stored safely in UPLOAD_DIR with pattern record_<unique-id>_<timestamp>.pdf.
 * Returns [storedName|null, error|null].
 */
if (!function_exists('save_upload_proof')) {
function save_upload_proof(?array $file, bool $required = false): array
{
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        if ($required) {
            return [null, 'Proof / Attachment is required. Please upload a PDF file (up to 2 MB).'];
        }
        return [null, null];
    }

    $errorCode = $file['error'] ?? UPLOAD_ERR_OK;
    if ($errorCode !== UPLOAD_ERR_OK) {
        return match ($errorCode) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => [null, 'The uploaded PDF exceeds the 2 MB size limit. Please upload a smaller file.'],
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

    // Supported proof formats: PDF documents and images (JPG, JPEG, PNG)
    $ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
    $allowedExts = ['pdf', 'jpg', 'jpeg', 'png'];
    if (!in_array($ext, $allowedExts, true)) {
        return [null, 'The proof must be a PDF document (.pdf) or an image (.jpg, .jpeg, .png).'];
    }

    if ($file['size'] > PROOF_MAX_BYTES) {
        return [null, 'The proof attachment is larger than 2 MB. Please upload a smaller one.'];
    }

    // Validate MIME type and binary header magic bytes
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = $finfo ? (string) finfo_file($finfo, $file['tmp_name']) : (function_exists('mime_content_type') ? (string) @mime_content_type($file['tmp_name']) : '');
    if ($finfo) {
        finfo_close($finfo);
    }

    $handle = @fopen($file['tmp_name'], 'rb');
    $header4 = $handle ? fread($handle, 4) : '';
    if ($handle) {
        fclose($handle);
    }

    if ($ext === 'pdf') {
        if ($mime !== '' && stripos($mime, 'pdf') === false && stripos($mime, 'octet-stream') === false) {
            return [null, 'That file is not a valid PDF document.'];
        }
        if ($header4 !== '%PDF') {
            return [null, 'That file is not a valid PDF document.'];
        }
    } elseif (in_array($ext, ['jpg', 'jpeg'], true)) {
        if ($mime !== '' && stripos($mime, 'jpeg') === false && stripos($mime, 'jpg') === false && stripos($mime, 'octet-stream') === false) {
            return [null, 'That file is not a valid JPEG image.'];
        }
        if (substr($header4, 0, 3) !== "\xFF\xD8\xFF") {
            return [null, 'That file is not a valid JPEG image.'];
        }
    } elseif ($ext === 'png') {
        if ($mime !== '' && stripos($mime, 'png') === false && stripos($mime, 'octet-stream') === false) {
            return [null, 'That file is not a valid PNG image.'];
        }
        if ($header4 !== "\x89PNG") {
            return [null, 'That file is not a valid PNG image.'];
        }
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
    $stored = "record_{$uniqueId}_{$timestamp}.{$ext}";

    $destPath = $baseFolder . '/' . $stored;
    if (!move_uploaded_file($file['tmp_name'], $destPath) || !file_exists($destPath)) {
        return [null, 'The proof could not be saved to the upload directory.'];
    }

    // Keep mirrored copy in proofs/ for backward compatibility with older links
    @copy($destPath, $proofsFolder . '/' . $stored);

    return [$stored, null];
}
<<<<<<< HEAD

// ---- Upload Data entry flow: Academic Year → Data Type → this form ---------
// Faculty and Coordinator pick both before the form opens (models/UploadFlow.php).
// The choice is kept in the session and re-checked on every request here, so
// neither screen can be skipped by editing the URL or posting directly. HoD
// and Admin skip this block and use the page exactly as before.
$uploadFlow = null;   // the validated choice once the form may open
if (upload_flow_applies($user)) {
    // If opening or submitting an existing record for revision (edit_id), automatically
    // initialize flow state from the record's existing academic year and category.
    $flowEditId   = (int) ($_GET['edit_id'] ?? $_POST['edit_id'] ?? 0);
    $flowEditType = (string) ($_GET['type'] ?? $_POST['record_type'] ?? '');
    if ($flowEditId > 0 && $flowEditType !== '') {
        require_once __DIR__ . '/models/EditRequest.php';
        $flowEditRec = edit_request_original_record($flowEditType, $flowEditId);
        if ($flowEditRec) {
            $recYear = $flowEditRec['academic_year'] ?? active_academic_year();
            $recDt   = upload_flow_data_type_of($flowEditType) ?? 'faculty';
            upload_flow_store($user, ['year' => $recYear, 'data_type' => $recDt]);
        }
    }

    if (isset($_GET['reset']) || isset($_GET['new'])) {
        upload_flow_store($user, ['year' => null, 'data_type' => null]);
    } elseif (($_GET['step'] ?? '') === 'year') {
        upload_flow_store($user, ['data_type' => null]);
    }

    $isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
    if ($isPost) {
        csrf_check();
    }
    $flowState = upload_flow_state($user);

    // The two selection screens post back here.
    if ($isPost && isset($_POST['upload_flow_step'])) {
        if ($_POST['upload_flow_step'] === 'year') {
            [$ok, $error] = upload_flow_choose_year($user, $_POST['academic_year'] ?? '');
            if (!$ok) {
                flash('error', $error);
                redirect('/upload.php');
            }
            redirect('/upload.php?step=data-type');
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

    // Show selection screen if visiting entry point, requested step, or no type asked
    if (!$isPost && (!isset($_GET['type']) || isset($_GET['reset']) || isset($_GET['step']))) {
        if (($_GET['step'] ?? '') === 'data-type' && $flowState['year'] !== null) {
            $uploadFlowStep = 'data_type';
        } else {
            upload_flow_remember_return($user);
            $flowState      = upload_flow_state($user);
            $uploadFlowStep = 'year';
        }
        $pageTitle = 'Upload Data'; $breadcrumb = 'Upload Data';
        require __DIR__ . '/inc/header.php';
        require __DIR__ . '/views/upload_flow.php';
        require __DIR__ . '/inc/footer.php';
        exit;
    }

    // The form, or a record submitted from it: both choices must be in place.
    if ($flowState['year'] === null) {
        flash('error', $flowState['stale_year'] !== null
            ? "Academic year {$flowState['stale_year']} is no longer open for uploads. Please select the academic year again."
            : 'Please select an Academic Year to continue.');
        redirect('/upload.php');
    }
    if ($flowState['data_type'] === null) {
        flash('error', 'Please choose Faculty Data or Student Data before uploading data.');
        redirect('/upload.php?step=data-type');
    }

    $flowDefs   = upload_flow_data_types();
    $uploadFlow = $flowState + ['label' => $flowDefs[$flowState['data_type']]['label'],
                                'icon'  => $flowDefs[$flowState['data_type']]['icon']];

    // Only the chosen data type's record types can be opened or submitted.
    $typeKeys  = $flowDefs[$flowState['data_type']]['types'];
    $askedType = (string) ($isPost ? ($_POST['record_type'] ?? '') : ($_GET['type'] ?? ''));
    if (!in_array($askedType, $typeKeys, true)) {
        $belongsTo = upload_flow_data_type_of($askedType);
        flash('error', $belongsTo !== null
            ? $types[$askedType]['label'] . ' is part of ' . $flowDefs[$belongsTo]['label'] . '. You are uploading '
              . $uploadFlow['label'] . ' for ' . $uploadFlow['year'] . '. Use "Back to Data Type" to switch.'
            : 'Invalid record type.');
        redirect('/upload.php?type=' . urlencode($typeKeys[0]));
    }
=======
>>>>>>> e54d139685a6a021eee864a5b65697b107b56e46
}

// Handle form submissions for new records
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_check();
    $type = (string) input('record_type');
    $nav  = (string) input('nav', 'add');   // add | next | submit

    $targetYear = $uploadFlow ? $uploadFlow['year'] : trim((string) input('academic_year', $activeYear));
    if ($user['role'] !== 'Admin' && academic_year_is_locked($targetYear)) {
        flash('error', "Academic year {$targetYear} cycle is currently locked by the Administrator. New record submissions for {$targetYear} are frozen.");
        redirect('/upload.php' . ($type ? '?type=' . urlencode($type) : ''));
    }

    // FEAT-07: once EM1 has closed and before EM2 opens, a new record could only
    // be a late EM1 submission, and EM1 is locked. Checked here, before any file
    // is stored or row inserted, so a direct POST cannot get past it.
    if ($emBlock = em_submission_block_reason($user['role'], $targetYear)) {
        flash('error', $emBlock);
        redirect('/upload.php' . ($type ? '?type=' . urlencode($type) : ''));
    }

    if (!isset($types[$type])) {
        flash('error', 'Invalid record type.');
        redirect('/upload.php');
    }

    // A dropdown set to "Others" is replaced by the text the user typed in the
    // matching "<field>_other" box, so the real value is stored, not the word
    // "Others". Works for journal type, event type, category — any such pair.
    foreach ($_POST as $k => $v) {
        if (substr($k, -6) === '_other') {
            $base = substr($k, 0, -6);
            if (($_POST[$base] ?? '') === 'Others') {
                if (trim((string) $v) !== '') {
                    $_POST[$base] = trim((string) $v);
                } else {
                    $_POST[$base] = ''; // Missing "Others" specification must fail validation
                }
            }
        }
    }

    // Server-Side Validation: Every user-editable field is mandatory
    $requiredMap = [
        'journal' => [
            'faculty_name' => 'Faculty Name',
            'department' => 'Department',
            'academic_year' => 'Academic Year',
            'author_type' => 'Author Type',
            'co_authors' => 'Names of Co-Authors at MSEC',
            'paper_title' => 'Title of the Paper',
            'journal_name' => 'Journal Name',
            'journal_type' => 'Journal Type',
            'issn' => 'ISSN Number',
            'volume_issue' => 'Volume & Issue No',
            'publication_month' => 'Month & Year of Publication',
            'doi' => 'Link to the Article / DOI',
            'journal_link' => 'Link to Journal Website',
            'document_link' => 'Document Link',
        ],
        'book' => [
            'faculty_name' => 'Faculty Name',
            'department' => 'Department',
            'academic_year' => 'Academic Year',
            'publication_category' => 'Book / Book Chapter',
            'title' => 'Title of the Book / Book Chapter',
            'publisher_name' => 'Publisher Name',
            'isbn' => 'ISSN / ISBN Number',
            'publication_month' => 'Month & Year of Publication',
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
            'mode' => 'Mode',
            'resource_person' => 'Chief Guest / Resource Person',
            'participants' => 'No. of Participants',
            'sponsorship' => 'Sponsorship',
            'report_link' => 'Web Link to Event Report',
        ],
        'nptel' => [
            'department' => 'Department',
            'candidate_name' => 'Candidate Name',
            'category' => 'Category',
            'course_title' => 'Course Title',
            'session' => 'Session',
            'grade' => 'Grade',
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
            'mode' => 'Mode',
            'participants' => 'No. of Participants',
            'sponsorship' => 'Sponsorship',
            'resource_person' => 'Chief Guest / Resource Person',
            'report_link' => 'Web Link to Event Report',
        ],
    ];

    // Faculty members always submit records for their assigned department
    if ($user['role'] === 'Faculty' && !empty($user['department'])) {
        $_POST['department'] = $user['department'];
    }

    $validationErrors = [];
    $expectedFields = $requiredMap[$type] ?? [];

    foreach ($expectedFields as $fKey => $fLabel) {
        if ($fKey === 'academic_year') {
            continue; // Server-owned; automatically populated with $activeYear
        }
        $val = trim((string) ($_POST[$fKey] ?? ''));
        if ($val === '') {
            $validationErrors[] = "{$fLabel} is required.";
            continue;
        }

        // Validate URL fields
        if (in_array($fKey, ['doi', 'journal_link', 'document_link', 'certificate_link', 'report_link', 'appointment_order_link'], true)) {
            if (!preg_match('/^https?:\/\/.+/i', $val)) {
                $validationErrors[] = "{$fLabel} must be a valid URL starting with http:// or https://.";
            }
        }
        // Validate Date fields
        if (in_array($fKey, ['conference_date', 'publication_date', 'from_date', 'to_date', 'signed_date', 'valid_upto', 'event_date', 'activity_date'], true)) {
            if (strtotime($val) === false) {
                $validationErrors[] = "{$fLabel} must be a valid date.";
            }
        }
        // Validate Numeric fields
        if (in_array($fKey, ['participants', 'days'], true)) {
            if (!is_numeric($val) || (int)$val < 1) {
                $validationErrors[] = "{$fLabel} must be a number greater than 0.";
            }
        }
    }

    // Check Proof file upload (optional)
    [$proofStored, $proofError] = save_upload_proof($_FILES['proof'] ?? null, false);
    if ($proofError !== null) {
        $validationErrors[] = $proofError;
    }

    if (!empty($validationErrors)) {
        if ($proofStored !== null) {
            @unlink(rtrim(UPLOAD_DIR, '/\\') . '/' . $proofStored);
            @unlink(rtrim(UPLOAD_DIR, '/\\') . '/proofs/' . $proofStored);
        }
        flash('error', implode('<br>', $validationErrors));
        redirect('/upload.php?type=' . $type);
    }

    $table = $types[$type]['table'];
    $pdo   = db();

    try {
        // Columns the submitter is NEVER allowed to set from the form (server-owned).
        // academic_year is server-owned too: every record is stamped with the
        // system's active academic year, never whatever a visitor picked in the
        // form (section 12 — never trust a client-supplied year).
        $protected = ['id', 'created_by', 'status', 'approved_by', 'review_remark', 'created_at', 'updated_at', 'academic_year'];
        $tableColumns = $pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_COLUMN);
        $allowed      = array_diff($tableColumns, $protected);

        $fields = [];
        $values = [];
        $placeholders = [];

        $editId = (int) input('edit_id', input('record_id', 0));
        if ($editId > 0) {
            [$ok, $msg] = record_submit_for_review($type, $editId, $user, $_POST, $proofStored);
            if ($ok) {
                flash('success', record_requires_approval($type) ? 'Record submitted for review successfully.' : 'Record submitted successfully.');
                $_SESSION['submitted_draft_type'] = $type;
                if ($user['role'] === 'Coordinator' && input('source') === 'approvals') {
                    redirect('/approvals.php');
                } else {
                    redirect('/upload.php' . ($type ? '?type=' . urlencode($type) : ''));
                }
            } else {
                if ($proofStored !== null) {
                    @unlink(rtrim(UPLOAD_DIR, '/\\') . '/' . $proofStored);
                    @unlink(rtrim(UPLOAD_DIR, '/\\') . '/proofs/' . $proofStored);
                }
                flash('error', $msg);
                redirect('/upload.php?type=' . $type . '&edit_id=' . $editId);
            }
        }
<<<<<<< HEAD
        if ($proofStored !== null && in_array('proof_file', $tableColumns, true)) {
            $setPairs[] = "`proof_file` = ?";
            $updateValues[] = $proofStored;
        }
        $setPairs[] = "`status` = ?";
        $updateValues[] = 'Approved';
        $setPairs[] = "`updated_at` = NOW()";

        try {
            $sql = "UPDATE `$table` SET " . implode(', ', $setPairs) . " WHERE id = ?";
            $updateValues[] = $editId;
            $pdo->prepare($sql)->execute($updateValues);

            require_once __DIR__ . '/models/Target.php';
            sync_target_achieved_for_type($type);

            require_once __DIR__ . '/models/EditRequest.php';
            edit_request_mark_completed_for_record($type, $editId);

            flash('success', $types[$type]['label'] . ' updated and saved to database.');
            redirect('/approvals.php');
        } catch (\PDOException $e) {
            error_log('upload.php update failed: ' . $e->getMessage());
            flash('error', 'Failed to update record.');
            redirect('/upload.php?type=' . $type . '&edit_id=' . $editId);
        }
    }
=======
>>>>>>> e54d139685a6a021eee864a5b65697b107b56e46

    // Review chain: Faculty -> Coordinator -> HoD. A Coordinator's own upload
    // skips the Coordinator step; a HoD's or Admin's upload is already final.
    // If the record type does NOT require approval (e.g. Book / Chapter), status is always Submitted.
    if (!record_requires_approval($type)) {
        $initialStatus = 'Submitted';
    } elseif (in_array($user['role'], ['HoD', 'Admin'], true)) {
        $initialStatus = 'Approved';
    } elseif ($user['role'] === 'Coordinator') {
        $initialStatus = 'HOD Pending';
    } else {
        $initialStatus = 'Submitted';
    }
    $fields[] = 'created_by'; $values[] = $user['id']; $placeholders[] = '?';
    $fields[] = 'status';     $values[] = $initialStatus; $placeholders[] = '?';
    if ($initialStatus === 'Approved') {
        if (in_array('approved_at', $tableColumns, true)) {
            $fields[] = 'approved_at'; $values[] = date('Y-m-d H:i:s'); $placeholders[] = '?';
        }
        if (in_array('approved_by', $tableColumns, true)) {
            $fields[] = 'approved_by'; $values[] = (int)$user['id']; $placeholders[] = '?';
        }
    }
    if (in_array('academic_year', $tableColumns, true)) {
        $fields[] = 'academic_year'; $values[] = $targetYear; $placeholders[] = '?';
    }
    if (in_array('created_at', $tableColumns, true)) {
        $fields[] = 'created_at';
        $values[] = date('Y-m-d H:i:s');
        $placeholders[] = '?';
    }

        // Faculty members always submit records for their assigned department
        if ($user['role'] === 'Faculty' && !empty($user['department'])) {
            $_POST['department'] = $user['department'];
        }

        foreach ($_POST as $k => $v) {
            if (!in_array($k, $allowed, true) || $v === '') continue;
            $fields[] = $k;
            $values[] = $v;
            $placeholders[] = '?';
        }

        // Ensure department is populated if the table has the column
        if (in_array('department', $allowed, true) && !in_array('department', $fields, true) && !empty($user['department'])) {
            $fields[] = 'department';
            $values[] = $user['department'];
            $placeholders[] = '?';
        }

        // Attach the proof if one was uploaded and this table can hold it.
        if ($proofStored !== null && in_array('proof_file', $tableColumns, true)) {
            $fields[] = 'proof_file'; $values[] = $proofStored; $placeholders[] = '?';
        }
        if ($proofStored !== null && in_array('proofs', $tableColumns, true) && !in_array('proofs', $fields, true)) {
            $fields[] = 'proofs'; $values[] = json_encode([$proofStored]); $placeholders[] = '?';
        }

        // Final strict safeguard against partial database inserts:
        // Verify that every canonical mandatory field is present and non-empty in $fields.
        $missingFields = [];
        foreach ($expectedFields as $fKey => $fLabel) {
            if ($fKey === 'academic_year') continue;
            $valIdx = array_search($fKey, $fields, true);
            if ($valIdx === false || trim((string)($values[$valIdx] ?? '')) === '') {
                $missingFields[] = "{$fLabel} is required.";
            }
        }

        if (!empty($missingFields)) {
            if ($proofStored !== null) {
                @unlink(rtrim(UPLOAD_DIR, '/\\') . '/' . $proofStored);
                @unlink(rtrim(UPLOAD_DIR, '/\\') . '/proofs/' . $proofStored);
            }
            flash('error', implode('<br>', $missingFields));
            redirect('/upload.php?type=' . $type);
        }

        $pdo->beginTransaction();
        $quotedFields = array_map(fn($f) => '`' . str_replace('`', '``', $f) . '`', $fields);
        $sql = "INSERT INTO `$table` (" . implode(', ', $quotedFields) . ") VALUES (" . implode(', ', $placeholders) . ")";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($values);

        if ($stmt->rowCount() === 0) {
            $pdo->rollBack();
            throw new \Exception("Database insertion failed: 0 rows affected.");
        }
        $pdo->commit();

        // A HoD/Admin upload lands Approved, so refresh any target it feeds.
        // For types where approval is not required, a Submitted record also feeds targets.
        if ($initialStatus === 'Approved' || !record_requires_approval($type)) {
            require_once __DIR__ . '/models/Target.php';
            sync_target_achieved_for_type($type);
        }

        if (!record_requires_approval($type)) {
            $where = 'submitted';
        } elseif ($initialStatus === 'Approved') {
            $where = 'recorded';
        } else {
            $where = 'submitted for review';
        }
        flash('success', 'Record ' . $where . ' successfully.');
        $_SESSION['submitted_draft_type'] = $type;
    } catch (\PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($proofStored !== null) {
            @unlink(rtrim(UPLOAD_DIR, '/\\') . '/' . $proofStored);
            @unlink(rtrim(UPLOAD_DIR, '/\\') . '/proofs/' . $proofStored);
        }
        error_log('upload.php database operation failed: ' . $e->getMessage());
        flash('error', 'Sorry, that record could not be saved due to a database error. Please check the fields and try again.');
        redirect('/upload.php?type=' . $type);
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($proofStored !== null) {
            @unlink(rtrim(UPLOAD_DIR, '/\\') . '/' . $proofStored);
            @unlink(rtrim(UPLOAD_DIR, '/\\') . '/proofs/' . $proofStored);
        }
        error_log('upload.php operation failed: ' . $e->getMessage());
        flash('error', 'Sorry, an error occurred while processing the record. Please try again.');
        redirect('/upload.php?type=' . $type);
    }

    // Where to land next: add another of the same type, move to the next metric,
    // or finish on the last one.
    if ($nav === 'next') {
        $idx  = array_search($type, $typeKeys, true);
        $dest = $typeKeys[$idx + 1] ?? $type;
        redirect('/upload.php?type=' . $dest);
    }
    redirect('/upload.php?type=' . $type);   // add / submit both return here
}

// Current user's records
$effectiveYear = $uploadFlow ? $uploadFlow['year'] : $activeYear;
$myRecords = my_records($user['id']);
$submittedDraftType = $_SESSION['submitted_draft_type'] ?? null;
unset($_SESSION['submitted_draft_type']);

$tabsToShow = $uploadFlow ? array_intersect_key($types, array_flip($typeKeys)) : $types;
$defaultType = $typeKeys[0] ?? 'journal';
$selectedType = trim((string)($_GET['type'] ?? $defaultType));
if (!isset($types[$selectedType]) || !in_array($selectedType, $typeKeys, true)) {
    $selectedType = $defaultType;
}

$selIdx    = array_search($selectedType, $typeKeys, true);
$isLast    = $selIdx === count($typeKeys) - 1;
$nextType  = $typeKeys[$selIdx + 1] ?? null;
$prevType  = $selIdx > 0 ? $typeKeys[$selIdx - 1] : null;

$editId = (int) input('edit_id');
$editRecord = null;
if ($editId > 0 && isset($types[$selectedType])) {
    require_once __DIR__ . '/models/EditRequest.php';
    $editRecord = edit_request_original_record($selectedType, $editId);
}

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
        <span class="badge badge-secondary" style="font-size:13px;padding:6px 12px;display:inline-flex;align-items:center;gap:6px">
          <?= icon($uploadFlow['icon'] ?? 'folder', 14) ?> Scope: <strong><?= e($uploadFlow['label']) ?></strong>
        </span>
      </div>
      <div style="display:flex;align-items:center;gap:8px">
        <a href="<?= e(url('upload.php?step=data-type')) ?>" class="btn btn-ghost btn-sm" style="font-size:12px">
          <?= icon('arrow-left', 14) ?> Change Data Type
        </a>
        <a href="<?= e(url('upload.php?reset=1')) ?>" class="btn btn-ghost btn-sm" style="font-size:12px">
          Change Academic Year
        </a>
      </div>
    </div>
  </div>
<?php endif; ?>

<!-- Type selector tabs -->
<div class="card" style="margin-bottom:16px">
  <div class="card-body js-category-nav-container" style="padding:8px 16px 14px; overflow-x:auto; white-space:nowrap; position:relative; scrollbar-width:thin;">
    <?php foreach ($tabsToShow as $key => $t): ?>
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

  /**
   * Dynamically position the sliding indicator directly underneath the active tab.
   * Uses DOM measurements: active tab position, container position, and current scrollLeft.
   */
  function updateIndicator(activeElement, smooth) {
    if (!activeElement || !container || !indicator) return;

    var containerRect = container.getBoundingClientRect();
    var tabRect = activeElement.getBoundingClientRect();
    var currentScroll = container.scrollLeft;

    // Dynamic calculation based on active tab position, container position, and current scrollLeft
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

  /**
   * Automatically scroll the tab container so that the active tab is comfortably in view.
   */
  function ensureActiveCategoryVisible(activeElement, smooth) {
    if (!activeElement || !container) return;

    var containerRect = container.getBoundingClientRect();
    var itemRect = activeElement.getBoundingClientRect();
    var padding = 24;

    var itemLeft = itemRect.left - containerRect.left;
    var itemRight = itemRect.right - containerRect.left;

    var currentScroll = container.scrollLeft;
    var targetScroll = currentScroll;

    // Center the active tab in view if it is near or beyond visible edges
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

  // Handle tab clicks: smoothly slide indicator and scroll to clicked tab
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

  // Keep indicator aligned during horizontal scrolling
  container.addEventListener('scroll', function () {
    var activeTab = getActiveTab();
    if (activeTab) {
      updateIndicator(activeTab, false);
    }
  }, { passive: true });

  // Initialise on load and after initial rendering
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

  // Window resize handler
  window.addEventListener('resize', function () {
    var activeTab = getActiveTab();
    if (activeTab) {
      updateIndicator(activeTab, false);
      ensureActiveCategoryVisible(activeTab, false);
    }
  });
})();
</script>

<?php $isUploadLocked = academic_year_is_locked($effectiveYear); ?>
<?php if ($isUploadLocked): ?>
  <div style="background:#FEF2F2;border:1px solid #FECACA;border-left:4px solid #DC2626;color:#991B1B;padding:14px 18px;border-radius:10px;margin-bottom:20px;display:flex;align-items:center;gap:12px">
    <div style="width:36px;height:36px;border-radius:8px;background:#FEE2E2;display:flex;align-items:center;justify-content:center;flex-shrink:0;color:#DC2626">
      <?= icon('lock', 20) ?>
    </div>
    <div style="flex:1">
      <div style="font-weight:700;font-size:13px">Academic Year <?= e($effectiveYear) ?> Cycle is Locked</div>
      <div style="font-size:12px;color:#B91C1C;margin-top:2px">The Administrator has frozen submissions for this academic year cycle following an Executive Meeting. <?= $user['role'] === 'Admin' ? 'As an Admin, you retain upload authority.' : 'New submissions are frozen across all roles until unlocked by an Administrator.' ?></div>
    </div>
  </div>
<?php endif; ?>

<!-- Upload form -->
<div class="card" style="margin-bottom:20px">
  <div class="card-head">
<<<<<<< HEAD
    <div>
      <div class="card-title">
        <?= $editId > 0 ? 'Edit ' . e($types[$selectedType]['label']) . ' #' . $editId : 'New ' . e($types[$selectedType]['label']) ?>
        <span class="card-sub js-draft-status" style="font-size:11px; font-weight:normal; margin-left:8px; opacity:0; transition:opacity 0.25s;"></span>
      </div>
      <div class="card-sub"><?= $editId > 0 ? 'Make corrections and save to update this record' : 'Fill in the details and submit for review' ?></div>
    </div>
=======
    <div><div class="card-title"><?= $editRecord ? 'Edit ' : 'New ' ?><?= e($types[$selectedType]['label']) ?> <span class="badge badge-neutral js-form-status-badge" style="font-size:11px; margin-left:8px; vertical-align:middle; text-transform:none;"><?= e($editRecord['status'] ?? 'Draft') ?></span> <span class="card-sub js-draft-status" style="font-size:11px; font-weight:normal; margin-left:8px; opacity:0; transition:opacity 0.25s;"></span></div><div class="card-sub">Fill in the details and submit for review</div></div>
>>>>>>> e54d139685a6a021eee864a5b65697b107b56e46
    <?php if (record_report_spec($selectedType) !== null): ?>
      <a class="btn btn-secondary btn-sm" href="<?= e(url('record-report.php?type=' . $selectedType . '&format=word')) ?>"><?= icon('download') ?> Download this report</a>
    <?php endif; ?>
  </div>
  <div class="card-body">
    <form method="post" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <input type="hidden" name="record_type" value="<?= e($selectedType) ?>">
<<<<<<< HEAD
      <?php if ($editId > 0): ?>
        <input type="hidden" name="edit_id" value="<?= $editId ?>">
      <?php endif; ?>

      <?php if ($editRecord && $editRecord['status'] === 'Unlocked for Edit'): ?>
        <div style="background:#EFF6FF;border:1px solid #BFDBFE;border-left:4px solid #1D4ED8;color:#1E40AF;padding:12px 16px;border-radius:8px;margin-bottom:18px;font-size:13px">
          <strong>Editing Unlocked Record #<?= $editId ?>:</strong> An edit request was approved by Dean/Admin. Please make the required corrections and click "Save & Resubmit Record".
          <?php if (!empty($editRecord['review_remark'])): ?>
            <div style="margin-top:4px;font-size:12px;color:#1D4ED8"><strong>Instructions:</strong> <?= e($editRecord['review_remark']) ?></div>
          <?php endif; ?>
        </div>
=======
      <?php if ($editRecord): ?>
        <input type="hidden" name="edit_id" value="<?= (int)$editRecord['id'] ?>">
        <?php if (!empty($_GET['source'])): ?>
          <input type="hidden" name="source" value="<?= e($_GET['source']) ?>">
        <?php endif; ?>
>>>>>>> e54d139685a6a021eee864a5b65697b107b56e46
      <?php endif; ?>

      <div style="display:grid; grid-template-columns:1fr 1fr; gap:0 16px;">
      <?php if (in_array($selectedType, ['journal','book','conference','patent','fdp'])): ?>
        <div class="field"><label>Faculty Name <span class="req">*</span></label>
          <input class="input" name="faculty_name" value="<?= e($user['name']) ?>" required></div>
        <?php render_dept_field($user, $departments, 'Department', true); ?>
        <div class="field"><label>Academic Year</label>
          <input class="input" value="<?= e($effectiveYear) ?>" readonly style="background:var(--bg-subtle, #f3f4f6); cursor:not-allowed;" title="Records are submitted for academic year <?= e($effectiveYear) ?>.">
        </div>
      <?php endif; ?>

      <?php if ($selectedType === 'journal'): ?>
        <?php /* Fields match the IQAC "Journal Publications" report template, in order. */ ?>
        <div class="field"><label>Author Type <span class="req">*</span></label><select class="select" name="author_type" required><option>Author-1</option><option>Co-Author</option></select></div>
        <div class="field"><label>Names of Co-Authors at MSEC <span class="req">*</span></label><input class="input" name="co_authors" placeholder="Comma-separated names" required></div>
        <div class="field" style="grid-column:span 2"><label>Title of the Paper <span class="req">*</span></label><input class="input" name="paper_title" required></div>
        <div class="field"><label>Journal Name <span class="req">*</span></label><input class="input" name="journal_name" required></div>
        <div class="field"><label>Journal Type <span class="req">*</span></label>
          <select class="select js-other" name="journal_type" data-other="journal_type_other" required><option>UGC Care</option><option>Scopus</option><option>SCI</option><option>Springer</option><option>Others</option></select>
          <input class="input js-other-text" name="journal_type_other" placeholder="Specify the journal type" style="margin-top:8px;display:none"></div>
        <div class="field"><label>ISSN Number <span class="req">*</span></label><input class="input" name="issn" required></div>
        <div class="field"><label>Volume &amp; Issue No <span class="req">*</span></label><input class="input" name="volume_issue" required></div>
        <div class="field"><label>Month &amp; Year of Publication <span class="req">*</span> <span class="card-sub">(mm/yyyy)</span></label><input class="input" name="publication_month" placeholder="e.g. 12/2025" required></div>
        <div class="field"><label>Link to the Article / DOI <span class="req">*</span></label><input class="input" name="doi" required></div>
        <div class="field"><label>Link to Journal Website <span class="req">*</span></label><input class="input" name="journal_link" type="url" required></div>
        <div class="field"><label>Document Link <span class="req">*</span></label><input class="input" name="document_link" type="url" required></div>

      <?php elseif ($selectedType === 'book'): ?>
        <div class="field"><label>Book / Book Chapter <span class="req">*</span></label><select class="select" name="publication_category" required><option>Book</option><option>Book Chapter</option></select></div>
        <div class="field" style="grid-column:span 2"><label>Title of the Book / Book Chapter <span class="req">*</span></label><input class="input" name="title" required></div>
        <div class="field"><label>Publisher Name <span class="req">*</span></label><input class="input" name="publisher_name" required></div>
        <div class="field"><label>ISSN / ISBN Number <span class="req">*</span></label><input class="input" name="isbn" required></div>
        <div class="field"><label>Month &amp; Year of Publication <span class="req">*</span> <span class="card-sub">(mm/yyyy)</span></label><input class="input" name="publication_month" placeholder="e.g. 01/2026" required></div>
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
        <div class="field"><label>Signed Date <span class="req">*</span> <span class="card-sub">(dd/mm/yyyy)</span></label><input class="input" name="signed_date" type="date" required></div>
        <div class="field" style="grid-column:span 2"><label>Name &amp; Address of the Collaborating Body <span class="req">*</span> <span class="card-sub">(Industry / Institution / Agency)</span></label><input class="input" name="organization" required></div>
        <div class="field"><label>Valid upto <span class="req">*</span> <span class="card-sub">(dd/mm/yyyy)</span></label><input class="input" name="valid_upto" type="date" required></div>
        <div class="field" style="grid-column:span 2"><label>Purpose of Collaboration <span class="req">*</span></label><input class="input" name="purpose" required></div>
        <div class="field"><label>Document Link <span class="req">*</span></label><input class="input" name="document_link" type="url" required></div>

      <?php elseif ($selectedType === 'event'): ?>
        <?php render_dept_field($user, $departments, 'Department', true); ?>
        <div class="field"><label>Date <span class="req">*</span> <span class="card-sub">(dd/mm/yyyy)</span></label><input class="input" name="event_date" type="date" required></div>
        <div class="field" style="grid-column:span 2"><label>Event Title <span class="req">*</span></label><input class="input" name="event_title" required></div>
        <div class="field"><label>Event Type <span class="req">*</span></label>
          <select class="select js-other" name="event_type" data-other="event_type_other" required><option>Seminar</option><option>Workshop</option><option>Webinar</option><option>FDP</option><option>Conference</option><option>Symposium</option><option>Guest Lecture</option><option>Others</option></select>
          <input class="input js-other-text" name="event_type_other" placeholder="Specify the event type" style="margin-top:8px;display:none"></div>
        <div class="field"><label>Mode <span class="req">*</span></label><select class="select" name="mode" required><option>Online</option><option>Offline</option><option>Hybrid</option></select></div>
        <div class="field" style="grid-column:span 2"><label>Chief Guest / Resource Person <span class="req">*</span> <span class="card-sub">— Name &amp; Designation (with Contact Details)</span></label><input class="input" name="resource_person" required></div>
        <div class="field"><label>No. of Participants <span class="req">*</span></label><input class="input" name="participants" type="number" min="1" required></div>
        <div class="field"><label>Sponsorship <span class="req">*</span> <span class="card-sub">(if any, write N/A if none)</span></label><input class="input" name="sponsorship" required></div>
        <div class="field" style="grid-column:span 2"><label>Web Link to Event Report <span class="req">*</span></label><input class="input" name="report_link" type="url" required></div>

      <?php elseif ($selectedType === 'nptel'): ?>
        <?php render_dept_field($user, $departments, 'Department', true); ?>
        <div class="field"><label>Candidate Name <span class="req">*</span></label><input class="input" name="candidate_name" required></div>
        <div class="field"><label>Category <span class="req">*</span></label>
          <select class="select js-other" name="category" data-other="category_other" required><option>Faculty</option><option>Student</option><option>Others</option></select>
          <input class="input js-other-text" name="category_other" placeholder="Specify the category" style="margin-top:8px;display:none"></div>
        <div class="field" style="grid-column:span 2"><label>Course Title <span class="req">*</span></label><input class="input" name="course_title" required></div>
        <div class="field"><label>Session <span class="req">*</span></label><input class="input" name="session" placeholder="e.g. Jul 2025 - Dec 2025" required></div>
        <div class="field"><label>Grade <span class="req">*</span></label><input class="input" name="grade" required></div>
        <div class="field"><label>Certificate Link <span class="req">*</span></label><input class="input" name="certificate_link" type="url" required></div>

      <?php elseif ($selectedType === 'internship'): ?>
        <div class="field"><label>Reg. No <span class="req">*</span></label><input class="input" name="reg_no" required></div>
        <div class="field"><label>Name of the student <span class="req">*</span></label><input class="input" name="student_name" required></div>
        <?php render_dept_field($user, $departments, 'Dept / Branch', true); ?>
        <div class="field" style="grid-column:span 2"><label>Title of Internship <span class="req">*</span></label><input class="input" name="title" required></div>
        <div class="field" style="grid-column:span 2"><label>Industry/Institution Name &amp; Address <span class="req">*</span></label><input class="input" name="industry" required></div>
        <div class="field"><label>Duration <span class="req">*</span></label><input class="input" name="duration" placeholder="e.g. 1 month" required></div>
        <div class="field"><label>No. of Days <span class="req">*</span></label><input class="input" name="days" type="number" min="1" required></div>
        <div class="field" style="grid-column:span 2"><label>Link to the Certificate / Document <span class="req">*</span></label><input class="input" name="certificate_link" type="url" required></div>

      <?php elseif ($selectedType === 'placement'): ?>
        <div class="field"><label>Reg. No <span class="req">*</span></label><input class="input" name="reg_no" required></div>
        <div class="field"><label>Student Name <span class="req">*</span></label><input class="input" name="student_name" required></div>
        <?php render_dept_field($user, $departments, 'Dept / Branch', true); ?>
        <div class="field"><label>Job Name <span class="req">*</span></label><input class="input" name="job_title" required></div>
        <div class="field"><label>Mode <span class="req">*</span> <span class="card-sub">(On Campus / Off Campus)</span></label><select class="select" name="mode" required><option>On Campus</option><option>Off Campus</option></select></div>
        <div class="field" style="grid-column:span 2"><label>Company Name &amp; Address <span class="req">*</span> <span class="card-sub">(with Contact Details)</span></label><input class="input" name="company" required></div>
        <div class="field"><label>Pay Scale <span class="req">*</span></label><input class="input" name="pay_scale" required></div>
        <div class="field" style="grid-column:span 2"><label>Web Link to Appointment Order <span class="req">*</span></label><input class="input" name="appointment_order_link" type="url" required></div>

      <?php elseif ($selectedType === 'nss'): ?>
        <?php render_dept_field($user, $departments, 'Department', true); ?>
        <div class="field"><label>Academic Year</label><input class="input" value="<?= e($effectiveYear) ?>" readonly style="background:var(--bg-subtle, #f3f4f6); cursor:not-allowed;" title="Records are submitted for academic year <?= e($effectiveYear) ?>."></div>
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
        <div class="field"><label>Date <span class="req">*</span> <span class="card-sub">(dd/mm/yyyy)</span></label><input class="input" name="event_date" type="date" required></div>
        <div class="field" style="grid-column:span 2"><label>Event Title <span class="req">*</span></label><input class="input" name="event_title" required></div>
        <div class="field"><label>Event Type <span class="req">*</span></label><select class="select" name="event_type" required><option>Career Guidance</option><option>Counselling</option><option>ICT</option><option>Life Skills</option><option>Soft Skills</option></select></div>
        <div class="field"><label>Mode <span class="req">*</span></label><select class="select" name="mode" required><option>Online</option><option>Offline</option><option>Hybrid</option></select></div>
        <div class="field"><label>No. of Participants <span class="req">*</span></label><input class="input" name="participants" type="number" min="1" required></div>
        <div class="field"><label>Sponsorship <span class="req">*</span> <span class="card-sub">(if any, write N/A if none)</span></label><input class="input" name="sponsorship" required></div>
        <div class="field" style="grid-column:span 2"><label>Chief Guest / Resource Person <span class="req">*</span> <span class="card-sub">— Name &amp; Designation (with Contact Details)</span></label><input class="input" name="resource_person" required></div>
        <div class="field" style="grid-column:span 2"><label>Web Link to Event Report <span class="req">*</span></label><input class="input" name="report_link" type="url" required></div>
      <?php endif; ?>

        <!-- Proof / attachment (optional) — carried onto the report -->
        <div class="field" style="grid-column:span 2">
          <label>Proof / Attachment <span class="card-sub">— PDF only, strictly 2 MB or less</span></label>
          <input class="input" type="file" name="proof" id="proofInput" accept="application/pdf,.pdf">
          <div id="proofSizeError" style="color:var(--danger, #ef4444); font-size:12px; margin-top:4px; display:none;"></div>
        </div>
      </div>

      <!-- Save this entry and add another of the same type, move on to the next
           metric, or finish on the last one. -->
      <div class="upload-actions">
        <?php if ($editId > 0): ?>
          <button type="submit" name="nav" value="add" class="btn btn-primary" style="background:#1D4ED8;border-color:#1D4ED8;font-weight:600;display:inline-flex;align-items:center;gap:6px">
            <?= icon('check') ?> Save &amp; Resubmit Record
          </button>
          <div class="spacer"></div>
          <a class="btn btn-ghost" href="<?= e(url('approvals.php')) ?>"><?= icon('arrow-left') ?> Cancel &amp; Back to Approvals</a>
        <?php elseif (!$isUploadLocked || $user['role'] === 'Admin'): ?>
          <button type="submit" name="nav" value="add" class="btn btn-outline"><?= icon('plus') ?> Save &amp; add another</button>
          <div class="spacer"></div>
          <?php if ($prevType): ?>
            <a class="btn btn-ghost" href="<?= e(url('upload.php?type=' . $prevType)) ?>"><?= icon('arrow-left') ?> Back</a>
          <?php endif; ?>
          <button type="submit" name="nav" value="submit" id="btnSubmitReview" class="btn btn-primary"><?= icon('check') ?> Submit and Review</button>
          <?php if (!$isLast): ?>
            <button type="submit" name="nav" value="next" class="btn btn-outline">
              Next: <?= e($types[$nextType]['label']) ?> <?= icon('arrow-right') ?>
            </button>
          <?php endif; ?>
        <?php else: ?>
          <div style="display:flex;align-items:center;gap:8px;color:#991B1B;font-size:13px;font-weight:700">
            <?= icon('lock', 16) ?> Submissions are disabled because Academic Year <?= e($effectiveYear) ?> is locked.
          </div>
          <div class="spacer"></div>
          <?php if ($prevType): ?>
            <a class="btn btn-ghost" href="<?= e(url('upload.php?type=' . $prevType)) ?>"><?= icon('arrow-left') ?> Back</a>
          <?php endif; ?>
          <?php if (!$isLast): ?>
            <a href="<?= e(url('upload.php?type=' . $nextType)) ?>" class="btn btn-primary">
              Next: <?= e($types[$nextType]['label']) ?> <?= icon('arrow-right') ?>
            </a>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </form>

    <?php if ($editRecord): ?>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
      var record = <?= json_encode($editRecord) ?>;
      var form = document.querySelector('form[enctype="multipart/form-data"]');
      if (!form || !record) return;
      for (var key in record) {
        if (!record.hasOwnProperty(key)) continue;
        if (key.indexOf('_') === 0 || key === 'id' || key === 'status' || key === 'proof_file' || key === 'created_at' || key === 'updated_at') continue;
        var val = record[key];
        if (val === null || val === undefined) continue;
        var el = form.elements[key];
        if (el) {
          if (el.type === 'select-one' || el.tagName === 'SELECT') {
            var matched = false;
            for (var i = 0; i < el.options.length; i++) {
              if (el.options[i].value == val || el.options[i].text == val) {
                el.selectedIndex = i;
                matched = true;
                break;
              }
            }
            if (!matched && el.classList.contains('js-other')) {
              el.value = 'Others';
              var otherBox = form.elements[key + '_other'];
              if (otherBox) {
                otherBox.value = val;
                otherBox.style.display = '';
              }
            }
          } else if (el.type !== 'file' && el.type !== 'hidden') {
            el.value = val;
          }
        }
      }
    });
    </script>
    <?php endif; ?>

    <script>
      /* A dropdown with an "Others" option reveals a text box to type the real
         value; the box is required only while "Others" is the choice. */
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

      /* Immediate strict 2MB check: file cannot even be selected if > 2 MB */
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

          // PDF format check
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

      /* Per-category Draft State Persistence */
      (function () {
        var form = document.querySelector('.card-body form');
        if (!form) return;

        var activeYear = <?= json_encode($activeYear) ?>;
        var recordType = <?= json_encode($selectedType) ?>;
        var storageKey = 'atts_upload_draft_' + activeYear + '_' + recordType;

        // Clear submitted category draft if server indicated successful insert
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

            // Phase 1: Restore selects first and trigger change event so dynamic boxes ("Others") reveal
            Object.keys(draft).forEach(function (name) {
              var el = form.elements[name];
              if (!el) return;
              if (el.tagName === 'SELECT') {
                el.value = draft[name];
                el.dispatchEvent(new Event('change', { bubbles: true }));
                restoredAny = true;
              }
            });

            // Phase 2: Restore text, number, date, URL, textarea, checkbox, radio
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

            // Phase 3: Resync any .js-other boxes specifically
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

        function updateDraftUI(draft) {
          var badge = document.querySelector('.js-form-status-badge');
          var draftRow = document.getElementById('js-draft-table-row');
          var draftTitle = document.getElementById('js-draft-table-title');
          var emptyRow = document.getElementById('js-empty-table-row');
          var hasData = hasDraftData(draft);

          if (badge) {
            var badgeText = serverDraft ? (serverDraft.status || 'Draft') : 'Draft';
            badge.textContent = badgeText;
            badge.className = 'badge badge-neutral js-form-status-badge';
          }

          if (draftRow) {
            if (hasData) {
              var title = draft ? (draft.paper_title || draft.title || draft.event_title || draft.course_title || draft.activity_name || draft.student_name || draft.candidate_name || '(Draft in progress)') : '(Draft in progress)';
              if (draftTitle) draftTitle.textContent = title;
              draftRow.style.display = '';
              if (emptyRow) emptyRow.style.display = 'none';
            } else {
              draftRow.style.display = 'none';
              if (emptyRow) emptyRow.style.display = '';
            }
          }
        }

        form.addEventListener('input', function () {
          if (isRestoring) return;
          debouncedSaveDraft();
          updateDraftUI(getDraftData());
        });
        form.addEventListener('change', function () {
          if (isRestoring) return;
          saveDraft();
          updateDraftUI(getDraftData());
        });

        // Ensure browser constraint validation triggers on submit and highlights missing fields
        // Includes duplicate click protection against repeated submissions
        form.addEventListener('submit', function (e) {
          if (!form.checkValidity()) {
            e.preventDefault();
            form.reportValidity();
            return;
          }
          if (form.dataset.submitting === '1') {
            e.preventDefault();
            return;
          }
          form.dataset.submitting = '1';
        });

        // Immediately save draft upon clicking any category tab before navigation unloads the DOM
        document.querySelectorAll('.js-category-tab').forEach(function (tab) {
          tab.addEventListener('click', function () {
            clearTimeout(saveTimeout);
            saveDraft();
          });
        });

        // Flush on beforeunload and pagehide
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
  // Filters for the submissions list: default to active category tab unless explicitly specified.
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

  $shown = $myRecords;
  if ($mType   !== '') { $shown = array_filter($shown, fn($r) => $r['_type_key'] === $mType); }
  if ($mStatus !== '') { $shown = array_filter($shown, fn($r) => ($r['status'] ?? '') === $mStatus); }
  if ($mQ      !== '') {
    $needle = mb_strtolower($mQ);
    $shown = array_filter($shown, fn($r) => mb_strpos(mb_strtolower((string) ($r['_title'] ?? '')), $needle) !== false);
  }
  $shown = array_values($shown);
  $mFilter = $mType !== $selectedType || $mStatus !== '' || $mQ !== '';
?>
<?php $mActive = ($mType !== $selectedType ? 1 : 0) + ($mStatus !== '' ? 1 : 0) + ($mQ !== '' ? 1 : 0); ?>
<div class="card">
  <div class="card-head">
    <div><div class="card-title">My Submissions</div>
      <div class="card-sub"><?= $mFilter ? count($shown) . ' of ' . count($myRecords) : count($shown) . ' ' . e($types[$selectedType]['label'] ?? '') . ' records' ?></div></div>
  </div>
  <form method="get" class="fbar fbar-flush">
    <input type="hidden" name="type" value="<?= e($selectedType) ?>">

    <label class="fb-field"><span class="fb-k">Record type</span>
      <select name="mtype" onchange="this.form.submit()">
        <option value="" <?= $mType === '' ? 'selected' : '' ?>>All Categories</option>
        <?php foreach ($types as $key => $t): ?>
          <option value="<?= e($key) ?>" <?= $mType === $key ? 'selected' : '' ?>><?= e($t['label']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>

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
        <a class="fbar-clear" href="<?= e(url('upload.php?type=' . $selectedType)) ?>"><?= icon('x', 13) ?> Reset to Category</a>
      <?php else: ?>
        <span class="fbar-note">Showing <?= e($types[$selectedType]['label'] ?? 'this category') ?></span>
      <?php endif; ?>
    </span>
  </form>
  <div class="card-body" style="padding:0">
    <div class="table-wrap"><table class="data"><thead><tr>
      <th style="padding-left:24px">Record</th><th>Type</th><th>Proof</th><th>Status</th><th>Submitted</th>
    </tr></thead><tbody>
    <tr id="js-draft-table-row" style="display:none; background:rgba(241,245,249,0.7); border-left:3px solid var(--muted, #94A3B8);">
      <td style="padding-left:24px"><div id="js-draft-table-title" style="font-weight:500; font-style:italic; color:var(--muted, #64748B); max-width:350px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">(Unsaved Draft)</div></td>
      <td><span class="badge badge-neutral"><?= e($types[$selectedType]['label']) ?></span></td>
      <td><span class="card-sub">—</span></td>
      <td><span class="badge badge-neutral">Draft</span></td>
      <td class="card-sub" id="js-draft-table-time"><em>In progress (Draft)</em></td>
    </tr>
    <?php if (empty($shown)): ?>
      <tr id="js-empty-table-row"><td colspan="5" style="text-align:center; padding:32px; color:var(--muted, #64748B);">
        <div style="font-size:13px; font-weight:500;"><?= $mFilter ? 'No submissions match these filters' : ($mType !== '' ? 'No submissions yet for ' . e($types[$mType]['label'] ?? $types[$selectedType]['label']) : 'No submissions yet') ?></div>
      </td></tr>
    <?php else: ?>
      <?php foreach (array_slice($shown, 0, 50) as $r):
        $statusBadge = ['Draft'=>'neutral','Submitted'=>'info','HOD Pending'=>'info','Dean Pending'=>'warning','Approved'=>'success','Rejected'=>'danger'];
      ?>
        <tr>
          <td style="padding-left:24px"><div style="font-weight:500;max-width:350px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= e($r['_title']) ?></div></td>
          <td><span class="badge badge-neutral"><?= e($r['_type_label']) ?></span></td>
          <td>
<<<<<<< HEAD
            <?php if (!empty($r['proof_file'])): ?>
              <a class="btn btn-ghost btn-sm" href="<?= e(record_proof_url($r['_type_key'] ?? $selectedType, (int)$r['id'], $r['proof_file'])) ?>" target="_blank" rel="noopener"><?= icon('paperclip', 14) ?> View</a>
            <?php else: ?>
              <span class="card-sub">—</span>
=======
            <?= render_proof_cell($r['proof_file'] ?? null, $r['_type_key'] ?? null, (int)($r['id'] ?? 0)) ?>
          </td>
          <td>
            <span class="badge badge-<?= $statusBadge[$r['status']] ?? 'neutral' ?>"><?= e($r['status']) ?></span>
            <?php if (($r['status'] ?? '') === 'Draft'): ?>
              <a href="<?= e(url('upload.php?type=' . urlencode($r['_type_key']) . '&edit_id=' . (int)$r['id'])) ?>" class="btn btn-ghost btn-sm" style="margin-left:6px; padding:2px 8px; font-size:11px;" title="<?= record_requires_approval($r['_type_key']) ? 'Submit and Review this draft' : 'Submit this draft' ?>"><?= icon('check', 12) ?> <?= record_requires_approval($r['_type_key']) ? 'Submit and Review' : 'Submit' ?></a>
>>>>>>> e54d139685a6a021eee864a5b65697b107b56e46
            <?php endif; ?>
          </td>
          <td class="card-sub" title="<?= e(date('d M Y, h:i A', strtotime($r['created_at']))) ?>"><?= e(time_ago($r['created_at'])) ?></td>
        </tr>
      <?php endforeach; ?>
    <?php endif; ?>
    </tbody></table></div>
  </div>
</div>

<?php require __DIR__ . '/inc/footer.php'; ?>
