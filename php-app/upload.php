<?php
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/record_specs.php';
require_once __DIR__ . '/models/Record.php';
require_once __DIR__ . '/models/Department.php';
require_once __DIR__ . '/models/Target.php';   // academic_years()

$user = require_role(['Admin', 'HoD', 'Coordinator', 'Faculty']);
require_module('upload');

$types       = record_types();
$departments = departments_all();
$years       = academic_years();
$typeKeys    = array_keys($types);

/** The most a stored proof may weigh. */
const PROOF_MAX_BYTES = 2 * 1024 * 1024;   // 2 MB

/**
 * Save one uploaded proof file. Only a PDF (up to 2 MB) is accepted. The name on
 * disk is random with a checked extension, so nothing executable can be written
 * and the uploads folder can never be escaped. Returns [storedName|null, error|null].
 */
function save_upload_proof(?array $file, bool $required = true): array
{
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        if ($required) {
            return [null, 'Proof / Attachment is required. Please upload a PDF file (up to 2 MB).'];
        }
        return [null, null];
    }
    if (in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
        return [null, 'That PDF is too large. Please keep it under 2 MB.'];
    }
    if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        return [null, 'The proof could not be uploaded.'];
    }

    // PDF only, by extension and by actual content.
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if ($ext !== 'pdf') {
        return [null, 'The proof must be a PDF file (.pdf).'];
    }
    $mime = function_exists('mime_content_type') ? (string) @mime_content_type($file['tmp_name']) : '';
    if ($mime !== '' && stripos($mime, 'pdf') === false) {
        return [null, 'That file is not a valid PDF.'];
    }
    if ($file['size'] > PROOF_MAX_BYTES) {
        return [null, 'The PDF is larger than 2 MB. Please upload a smaller one.'];
    }

    $folder = UPLOAD_DIR . '/proofs';
    if (!is_dir($folder)) {
        @mkdir($folder, 0775, true);
    }
    $stored = bin2hex(random_bytes(16)) . '.pdf';
    if (!move_uploaded_file($file['tmp_name'], $folder . '/' . $stored)) {
        return [null, 'The proof could not be saved.'];
    }
    return [$stored, null];
}

// Handle form submissions for new records
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $type = (string) input('record_type');
    $nav  = (string) input('nav', 'add');   // add | next | submit

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
            if (($_POST[$base] ?? '') === 'Others' && trim((string) $v) !== '') {
                $_POST[$base] = trim((string) $v);
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

    $validationErrors = [];
    $expectedFields = $requiredMap[$type] ?? [];

    foreach ($expectedFields as $fKey => $fLabel) {
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

    // Check Proof file upload (mandatory)
    [$proofStored, $proofError] = save_upload_proof($_FILES['proof'] ?? null, true);
    if ($proofError !== null) {
        $validationErrors[] = $proofError;
    }

    if (!empty($validationErrors)) {
        flash('error', implode('<br>', $validationErrors));
        redirect('/upload.php?type=' . $type);
    }

    $table = $types[$type]['table'];
    $pdo   = db();

    // Columns the submitter is NEVER allowed to set from the form (server-owned).
    $protected = ['id', 'created_by', 'status', 'approved_by', 'review_remark', 'created_at', 'updated_at'];
    $tableColumns = $pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_COLUMN);
    $allowed      = array_diff($tableColumns, $protected);

    $fields = [];
    $values = [];
    $placeholders = [];

    // Review chain: Faculty -> Coordinator -> HoD. A Coordinator's own upload
    // skips the Coordinator step; a HoD's or Admin's upload is already final.
    if (in_array($user['role'], ['HoD', 'Admin'], true)) {
        $initialStatus = 'Approved';
    } elseif ($user['role'] === 'Coordinator') {
        $initialStatus = 'HOD Pending';
    } else {
        $initialStatus = 'Submitted';
    }
    $fields[] = 'created_by'; $values[] = $user['id']; $placeholders[] = '?';
    $fields[] = 'status';     $values[] = $initialStatus; $placeholders[] = '?';

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

    try {
        $sql = "INSERT INTO `$table` (" . implode(',', $fields) . ") VALUES (" . implode(',', $placeholders) . ")";
        $pdo->prepare($sql)->execute($values);

        // A HoD/Admin upload lands Approved, so refresh any target it feeds.
        if ($initialStatus === 'Approved') {
            require_once __DIR__ . '/models/Target.php';
            sync_target_achieved_for_type($type);
        }

        $where = $initialStatus === 'Approved' ? 'recorded' : 'submitted for review';
        flash('success', $types[$type]['label'] . ' ' . $where . '.');
    } catch (\PDOException $e) {
        error_log('upload.php insert failed: ' . $e->getMessage());
        $err = 'Sorry, that record could not be saved. Please check the fields and try again.';
        if (defined('APP_DEBUG') && APP_DEBUG) {
            $err .= ' [' . $e->getMessage() . ']';
        }
        flash('error', $err);
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
$myRecords = my_records($user['id']);
$selectedType = trim((string)($_GET['type'] ?? 'journal'));
if (!isset($types[$selectedType])) $selectedType = 'journal';

$selIdx    = array_search($selectedType, $typeKeys, true);
$isLast    = $selIdx === count($typeKeys) - 1;
$nextType  = $typeKeys[$selIdx + 1] ?? null;
$prevType  = $selIdx > 0 ? $typeKeys[$selIdx - 1] : null;

/**
 * Render department input: locked readonly for Faculty (preserving their department),
 * and selectable for other authorized roles (Admin, etc.).
 */
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

$pageTitle = 'Upload Data'; $breadcrumb = 'Upload Data';
require __DIR__ . '/inc/header.php';
?>

<div class="page-head">
  <div><h1>Upload Data</h1><div class="sub">Submit academic records for review</div></div>
</div>

<!-- Type selector tabs -->
<div class="card" style="margin-bottom:16px">
  <div class="card-body js-category-nav-container" style="padding:8px 16px; overflow-x:auto; white-space:nowrap">
    <?php foreach ($types as $key => $t): ?>
      <a href="<?= e(url('upload.php?type=' . $key)) ?>"
         class="btn btn-sm js-category-tab <?= $selectedType === $key ? 'btn-primary active' : 'btn-ghost' ?>"
         style="margin:4px 2px; height:32px; font-size:12px"><?= e($t['label']) ?></a>
    <?php endforeach; ?>
  </div>
</div>

<script>
(function () {
  function ensureActiveCategoryVisible(activeElement, smooth) {
    if (!activeElement) return;
    var container = activeElement.closest('.js-category-nav-container') || activeElement.parentElement;
    if (!container) return;

    var containerRect = container.getBoundingClientRect();
    var itemRect = activeElement.getBoundingClientRect();

    var itemLeft = itemRect.left - containerRect.left;
    var itemRight = itemRect.right - containerRect.left;
    var padding = 16;

    var currentScroll = container.scrollLeft;
    var targetScroll = currentScroll;

    if (itemLeft < padding) {
      targetScroll += (itemLeft - padding);
    } else if (itemRight > (container.clientWidth - padding)) {
      targetScroll += (itemRight - container.clientWidth + padding);
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

  function initCategoryNavScroll() {
    var activeTab = document.querySelector('.js-category-nav-container .btn-primary, .js-category-nav-container .active');
    if (activeTab) {
      ensureActiveCategoryVisible(activeTab, false);
      setTimeout(function () {
        ensureActiveCategoryVisible(activeTab, true);
      }, 50);
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initCategoryNavScroll);
  } else {
    initCategoryNavScroll();
  }

  window.addEventListener('resize', function () {
    var activeTab = document.querySelector('.js-category-nav-container .btn-primary, .js-category-nav-container .active');
    if (activeTab) {
      ensureActiveCategoryVisible(activeTab, false);
    }
  });
})();
</script>

<!-- Upload form -->
<div class="card" style="margin-bottom:20px">
  <div class="card-head">
    <div><div class="card-title">New <?= e($types[$selectedType]['label']) ?></div><div class="card-sub">Fill in the details and submit for review</div></div>
    <?php if (record_report_spec($selectedType) !== null): ?>
      <a class="btn btn-secondary btn-sm" href="<?= e(url('record-report.php?type=' . $selectedType . '&format=word')) ?>"><?= icon('download') ?> Download this report</a>
    <?php endif; ?>
  </div>
  <div class="card-body">
    <form method="post" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <input type="hidden" name="record_type" value="<?= e($selectedType) ?>">

      <div style="display:grid; grid-template-columns:1fr 1fr; gap:0 16px;">
      <?php if (in_array($selectedType, ['journal','book','conference','patent','fdp'])): ?>
        <div class="field"><label>Faculty Name <span class="req">*</span></label>
          <input class="input" name="faculty_name" value="<?= e($user['name']) ?>" required></div>
        <?php render_dept_field($user, $departments, 'Department', true); ?>
        <div class="field"><label>Academic Year <span class="req">*</span></label>
          <select class="select" name="academic_year" required>
            <?php foreach($years as $y):?><option><?=e($y)?></option><?php endforeach;?>
          </select></div>
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
        <div class="field"><label>Academic Year <span class="req">*</span></label><select class="select" name="academic_year" required><?php foreach($years as $y):?><option><?=e($y)?></option><?php endforeach;?></select></div>
        <div class="field"><label>Date <span class="req">*</span> <span class="card-sub">(dd/mm/yyyy)</span></label><input class="input" name="activity_date" type="date" required></div>
        <div class="field"><label>Activity Type <span class="req">*</span></label><select class="select" name="activity_type" required><option>NSS</option><option>YRC</option><option>RRC</option></select></div>
        <div class="field" style="grid-column:span 2"><label>Name of the Activity <span class="req">*</span></label><input class="input" name="activity_name" required></div>
        <div class="field"><label>Venue <span class="req">*</span></label><input class="input" name="venue" required></div>
        <div class="field"><label>No. of Students Participated <span class="req">*</span></label><input class="input" name="participants" type="number" min="1" required></div>
        <div class="field" style="grid-column:span 2"><label>Name of External Agency / Member Involved <span class="req">*</span> <span class="card-sub">— Name &amp; Designation (with Contact Details)</span></label><input class="input" name="external_agency" required></div>
        <div class="field" style="grid-column:span 2"><label>Web Link to Event Report <span class="req">*</span></label><input class="input" name="report_link" type="url" required></div>

      <?php elseif ($selectedType === 'online_course'): ?>
        <?php render_dept_field($user, $departments, 'Department', true); ?>
        <div class="field"><label>Academic Year <span class="req">*</span></label><select class="select" name="academic_year" required><?php foreach($years as $y):?><option><?=e($y)?></option><?php endforeach;?></select></div>
        <div class="field"><label>Candidate Name <span class="req">*</span></label><input class="input" name="candidate_name" required></div>
        <div class="field"><label>Category <span class="req">*</span></label><select class="select" name="category" required><option>Faculty</option><option>Student</option></select></div>
        <div class="field" style="grid-column:span 2"><label>Course Title <span class="req">*</span></label><input class="input" name="course_title" required></div>
        <div class="field"><label>Provider <span class="req">*</span> <span class="card-sub">(Coursera / NPTEL / Udemy / …)</span></label><input class="input" name="provider" required></div>
        <div class="field"><label>Duration <span class="req">*</span></label><input class="input" name="duration" placeholder="e.g. 8 weeks" required></div>
        <div class="field"><label>Month &amp; Year <span class="req">*</span> <span class="card-sub">(mm/yyyy)</span></label><input class="input" name="month_year" placeholder="e.g. 03/2026" required></div>
        <div class="field"><label>Certificate Link <span class="req">*</span></label><input class="input" name="certificate_link" type="url" required></div>

      <?php elseif ($selectedType === 'student_achievement' || $selectedType === 'student_participation'): ?>
        <?php render_dept_field($user, $departments, 'Dept / Branch', true); ?>
        <div class="field"><label>Academic Year <span class="req">*</span></label><select class="select" name="academic_year" required><?php foreach($years as $y):?><option><?=e($y)?></option><?php endforeach;?></select></div>
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
        <div class="field"><label>Academic Year <span class="req">*</span></label><select class="select" name="academic_year" required><?php foreach($years as $y):?><option><?=e($y)?></option><?php endforeach;?></select></div>
        <div class="field"><label>Reg. No <span class="req">*</span></label><input class="input" name="reg_no" required></div>
        <div class="field"><label>Name of the student <span class="req">*</span></label><input class="input" name="student_name" required></div>
        <div class="field" style="grid-column:span 2"><label>Title of Training <span class="req">*</span></label><input class="input" name="title" required></div>
        <div class="field" style="grid-column:span 2"><label>Industry/Institution Name &amp; Address <span class="req">*</span></label><input class="input" name="industry" required></div>
        <div class="field"><label>Duration <span class="req">*</span></label><input class="input" name="duration" placeholder="e.g. 1 month" required></div>
        <div class="field"><label>No. of Days <span class="req">*</span></label><input class="input" name="days" type="number" min="1" required></div>
        <div class="field" style="grid-column:span 2"><label>Link to the Certificate / Document <span class="req">*</span></label><input class="input" name="certificate_link" type="url" required></div>

      <?php elseif ($selectedType === 'value_added'): ?>
        <?php render_dept_field($user, $departments, 'Department', true); ?>
        <div class="field"><label>Academic Year <span class="req">*</span></label><select class="select" name="academic_year" required><?php foreach($years as $y):?><option><?=e($y)?></option><?php endforeach;?></select></div>
        <div class="field"><label>From Date <span class="req">*</span> <span class="card-sub">(dd/mm/yyyy)</span></label><input class="input" name="from_date" type="date" required></div>
        <div class="field"><label>To Date <span class="req">*</span> <span class="card-sub">(dd/mm/yyyy)</span></label><input class="input" name="to_date" type="date" required></div>
        <div class="field" style="grid-column:span 2"><label>Course Title <span class="req">*</span></label><input class="input" name="course_title" required></div>
        <div class="field"><label>Mode <span class="req">*</span></label><select class="select" name="mode" required><option>Online</option><option>Offline</option><option>Hybrid</option></select></div>
        <div class="field"><label>No. of Participants <span class="req">*</span></label><input class="input" name="participants" type="number" min="1" required></div>
        <div class="field" style="grid-column:span 2"><label>Resource Person <span class="req">*</span> <span class="card-sub">— Name &amp; Designation (with Contact Details)</span></label><input class="input" name="resource_person" required></div>
        <div class="field" style="grid-column:span 2"><label>Web Link to Event Report <span class="req">*</span></label><input class="input" name="report_link" type="url" required></div>

      <?php elseif ($selectedType === 'training'): ?>
        <?php render_dept_field($user, $departments, 'Department', true); ?>
        <div class="field"><label>Academic Year <span class="req">*</span></label><select class="select" name="academic_year" required><?php foreach($years as $y):?><option><?=e($y)?></option><?php endforeach;?></select></div>
        <div class="field"><label>Date <span class="req">*</span> <span class="card-sub">(dd/mm/yyyy)</span></label><input class="input" name="event_date" type="date" required></div>
        <div class="field" style="grid-column:span 2"><label>Event Title <span class="req">*</span></label><input class="input" name="event_title" required></div>
        <div class="field"><label>Event Type <span class="req">*</span></label><select class="select" name="event_type" required><option>Career Guidance</option><option>Counselling</option><option>ICT</option><option>Life Skills</option><option>Soft Skills</option></select></div>
        <div class="field"><label>Mode <span class="req">*</span></label><select class="select" name="mode" required><option>Online</option><option>Offline</option><option>Hybrid</option></select></div>
        <div class="field"><label>No. of Participants <span class="req">*</span></label><input class="input" name="participants" type="number" min="1" required></div>
        <div class="field"><label>Sponsorship <span class="req">*</span> <span class="card-sub">(if any, write N/A if none)</span></label><input class="input" name="sponsorship" required></div>
        <div class="field" style="grid-column:span 2"><label>Chief Guest / Resource Person <span class="req">*</span> <span class="card-sub">— Name &amp; Designation (with Contact Details)</span></label><input class="input" name="resource_person" required></div>
        <div class="field" style="grid-column:span 2"><label>Web Link to Event Report <span class="req">*</span></label><input class="input" name="report_link" type="url" required></div>
      <?php endif; ?>

        <!-- Proof / attachment (mandatory) — carried onto the report -->
        <div class="field" style="grid-column:span 2">
          <label>Proof / Attachment <span class="req">*</span> <span class="card-sub">— PDF only, strictly 2 MB or less</span></label>
          <input class="input" type="file" name="proof" id="proofInput" accept="application/pdf,.pdf" required>
          <div id="proofSizeError" style="color:var(--danger, #ef4444); font-size:12px; margin-top:4px; display:none;"></div>
        </div>
      </div>

      <!-- Save this entry and add another of the same type, move on to the next
           metric, or finish on the last one. -->
      <div class="upload-actions">
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
      </div>
    </form>

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

        var userId = <?= json_encode((int)$user['id']) ?>;
        var recordType = <?= json_encode($selectedType) ?>;
        var storageKey = 'faculty_upload_draft_' + userId + '_' + recordType;

        // Clear draft for current category if form was successfully submitted
        var alertSuccess = document.querySelector('.alert-success');
        if (alertSuccess && alertSuccess.textContent.indexOf('submitted for review') !== -1) {
          try {
            sessionStorage.removeItem(storageKey);
          } catch (e) {}
        }

        function saveDraft() {
          try {
            var draft = {};
            var elements = form.querySelectorAll('input, select, textarea');
            elements.forEach(function (el) {
              var name = el.name;
              if (!name || name === 'csrf' || name === 'record_type' || name === 'proof' || name === 'nav') return;
              if (el.type === 'file' || el.type === 'password' || el.type === 'hidden') return;

              if (el.type === 'checkbox' || el.type === 'radio') {
                if (el.checked) {
                  draft[name] = el.value || true;
                }
              } else {
                draft[name] = el.value;
              }
            });
            sessionStorage.setItem(storageKey, JSON.stringify(draft));
          } catch (e) {
            console.warn('Unable to save form draft to sessionStorage:', e);
          }
        }

        function restoreDraft() {
          try {
            var raw = sessionStorage.getItem(storageKey);
            if (!raw) return;
            var draft = JSON.parse(raw);
            if (!draft || typeof draft !== 'object') return;

            Object.keys(draft).forEach(function (name) {
              if (name === 'csrf' || name === 'record_type' || name === 'proof' || name === 'nav') return;

              var item = form.elements[name];
              if (!item) return;

              var nodes = (item instanceof NodeList || item instanceof HTMLCollection) ? Array.prototype.slice.call(item) : [item];

              nodes.forEach(function (el) {
                if (!el || el.type === 'file' || el.type === 'password' || el.type === 'hidden') return;

                if (el.type === 'checkbox' || el.type === 'radio') {
                  el.checked = (el.value === draft[name] || draft[name] === true);
                } else if (el.tagName === 'SELECT') {
                  el.value = draft[name];
                  el.dispatchEvent(new Event('change', { bubbles: true }));
                } else {
                  el.value = draft[name];
                }
              });
            });
          } catch (e) {
            console.warn('Unable to restore form draft from sessionStorage:', e);
          }
        }

        form.addEventListener('input', saveDraft);
        form.addEventListener('change', saveDraft);

        if (document.readyState === 'loading') {
          document.addEventListener('DOMContentLoaded', restoreDraft);
        } else {
          restoreDraft();
        }
      })();
    </script>
  </div>
</div>

<!-- My recent records -->
<?php
  // Filters for the submissions list (kept separate from the form's ?type= tab).
  $mType   = (string) input('mtype');
  if (!isset($types[$mType])) { $mType = ''; }
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
  $mFilter = $mType !== '' || $mStatus !== '' || $mQ !== '';
?>
<?php $mActive = ($mType !== '' ? 1 : 0) + ($mStatus !== '' ? 1 : 0) + ($mQ !== '' ? 1 : 0); ?>
<div class="card">
  <div class="card-head">
    <div><div class="card-title">My Submissions</div>
      <div class="card-sub"><?= $mFilter ? count($shown) . ' of ' . count($myRecords) : count($myRecords) . ' total' ?> records</div></div>
    <details class="filter-funnel">
      <summary class="btn btn-outline btn-sm">
        <?= icon('filter', 15) ?> Filters<?php if ($mActive): ?> <span class="ff-dot"><?= $mActive ?></span><?php endif; ?>
      </summary>
      <span class="filter-backdrop" onclick="this.closest('details').removeAttribute('open')"></span>
      <div class="filter-pop">
        <form method="get">
          <input type="hidden" name="type" value="<?= e($selectedType) ?>">
          <div class="ff-head">
            <span>Filter submissions</span>
            <?php if ($mFilter): ?><a class="ff-clear" href="<?= e(url('upload.php?type=' . $selectedType)) ?>">Clear all</a><?php endif; ?>
          </div>
          <div class="ff-field"><label class="ff-label">Record Type</label>
            <select class="select" name="mtype" onchange="this.form.submit()">
              <option value="">All types</option>
              <?php foreach ($types as $key => $t): ?>
                <option value="<?= e($key) ?>" <?= $mType === $key ? 'selected' : '' ?>><?= e($t['label']) ?></option>
              <?php endforeach; ?>
            </select></div>
          <div class="ff-field"><label class="ff-label">Status</label>
            <select class="select" name="mstatus" onchange="this.form.submit()">
              <option value="">All statuses</option>
              <?php foreach (['Approved', 'Dean Pending', 'HOD Pending', 'Submitted', 'Draft', 'Rejected'] as $o): ?>
                <option value="<?= $o ?>" <?= $mStatus === $o ? 'selected' : '' ?>><?= $o ?></option>
              <?php endforeach; ?>
            </select></div>
          <div class="ff-field"><label class="ff-label">Search</label>
            <input class="input" type="search" name="mq" value="<?= e($mQ) ?>" placeholder="Search title…"></div>
          <div class="ff-actions">
            <button class="btn btn-primary btn-sm" type="submit"><?= icon('search', 14) ?> Apply filters</button>
          </div>
        </form>
      </div>
    </details>
  </div>
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
          <td><span class="badge badge-neutral"><?= e($r['_type_label']) ?></span></td>
          <td>
            <?php if (!empty($r['proof_file'])): ?>
              <a class="btn btn-ghost btn-sm" href="<?= e(UPLOAD_URL . '/proofs/' . rawurlencode($r['proof_file'])) ?>" target="_blank" rel="noopener"><?= icon('paperclip', 14) ?> View</a>
            <?php else: ?>
              <span class="card-sub">—</span>
            <?php endif; ?>
          </td>
          <td><span class="badge badge-<?= $statusBadge[$r['status']] ?? 'neutral' ?>"><?= e($r['status']) ?></span></td>
          <td class="card-sub"><?= e(time_ago($r['created_at'])) ?></td>
        </tr>
      <?php endforeach; ?></tbody></table></div>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/inc/footer.php'; ?>
