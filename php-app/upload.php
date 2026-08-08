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
function save_upload_proof(?array $file): array
{
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return [null, null];   // no file chosen — proof is optional
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
        return [null, 'The proof must be a PDF file.'];
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

    // A member may have nothing to add for this metric (no journal, but a
    // patent, say). The identifying field — the title — is what marks a real
    // entry; if it is blank, save nothing and simply move on. This is what lets
    // Next and Submit *skip* a metric instead of forcing it.
    $titleCol = $types[$type]['title_col'] ?? '';
    $hasEntry = $titleCol !== '' && trim((string) ($_POST[$titleCol] ?? '')) !== '';

    if (!$hasEntry) {
        if ($nav === 'next') {
            $idx = array_search($type, $typeKeys, true);
            redirect('/upload.php?type=' . ($typeKeys[$idx + 1] ?? $type));
        }
        if ($nav === 'add') {
            flash('error', 'Enter the details first, then add another.');
        } else {   // submit on the last panel with nothing here
            flash('success', 'Done — everything you added is in for review.');
        }
        redirect('/upload.php?type=' . $type);
    }

    $table = $types[$type]['table'];
    $pdo   = db();

    // Columns the submitter is NEVER allowed to set from the form (server-owned).
    $protected = ['id', 'created_by', 'status', 'approved_by', 'review_remark', 'created_at', 'updated_at'];
    $tableColumns = $pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_COLUMN);
    $allowed      = array_diff($tableColumns, $protected);

    // The uploaded proof (optional).
    [$proofStored, $proofError] = save_upload_proof($_FILES['proof'] ?? null);
    if ($proofError !== null) {
        flash('error', $proofError);
        redirect('/upload.php?type=' . $type);
    }

    $fields = [];
    $values = [];
    $placeholders = [];

    $fields[] = 'created_by'; $values[] = $user['id']; $placeholders[] = '?';
    $fields[] = 'status';     $values[] = 'Submitted'; $placeholders[] = '?';

    foreach ($_POST as $k => $v) {
        if (!in_array($k, $allowed, true) || $v === '') continue;
        $fields[] = $k;
        $values[] = $v;
        $placeholders[] = '?';
    }

    // Attach the proof if one was uploaded and this table can hold it.
    if ($proofStored !== null && in_array('proof_file', $tableColumns, true)) {
        $fields[] = 'proof_file'; $values[] = $proofStored; $placeholders[] = '?';
    }

    try {
        $sql = "INSERT INTO `$table` (" . implode(',', $fields) . ") VALUES (" . implode(',', $placeholders) . ")";
        $pdo->prepare($sql)->execute($values);
        flash('success', $types[$type]['label'] . ' submitted for review.');
    } catch (\PDOException $e) {
        error_log('upload.php insert failed: ' . $e->getMessage());
        flash('error', 'Sorry, that record could not be saved. Please check the fields and try again.');
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

$pageTitle = 'Upload Data'; $breadcrumb = 'Upload Data';
require __DIR__ . '/inc/header.php';
?>

<div class="page-head">
  <div><h1>Upload Data</h1><div class="sub">Submit academic records for review</div></div>
</div>

<!-- Type selector tabs -->
<div class="card" style="margin-bottom:16px">
  <div class="card-body" style="padding:8px 16px; overflow-x:auto; white-space:nowrap">
    <?php foreach ($types as $key => $t): ?>
      <a href="<?= e(url('upload.php?type=' . $key)) ?>"
         class="btn btn-sm <?= $selectedType === $key ? 'btn-primary' : 'btn-ghost' ?>"
         style="margin:4px 2px; height:32px; font-size:12px"><?= e($t['label']) ?></a>
    <?php endforeach; ?>
  </div>
</div>

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
        <div class="field"><label>Department <span class="req">*</span></label>
          <select class="select" name="department" required>
            <?php foreach($departments as $d):?><option value="<?=e($d['name'])?>" <?=$user['department']===$d['name']?'selected':''?>><?=e($d['name'])?></option><?php endforeach;?>
          </select></div>
        <div class="field"><label>Academic Year <span class="req">*</span></label>
          <select class="select" name="academic_year">
            <?php foreach($years as $y):?><option><?=e($y)?></option><?php endforeach;?>
          </select></div>
      <?php endif; ?>

      <?php if ($selectedType === 'journal'): ?>
        <?php /* Fields match the IQAC "Journal Publications" report template, in order. */ ?>
        <div class="field"><label>Author Type</label><select class="select" name="author_type"><option>Author-1</option><option>Co-Author</option></select></div>
        <div class="field"><label>Names of Co-Authors at MSEC</label><input class="input" name="co_authors" placeholder="Comma-separated names"></div>
        <div class="field" style="grid-column:span 2"><label>Title of the Paper <span class="req">*</span></label><input class="input" name="paper_title" required></div>
        <div class="field"><label>Journal Name</label><input class="input" name="journal_name"></div>
        <div class="field"><label>Journal Type</label>
          <select class="select js-other" name="journal_type" data-other="journal_type_other"><option>UGC Care</option><option>Scopus</option><option>SCI</option><option>Springer</option><option>Others</option></select>
          <input class="input js-other-text" name="journal_type_other" placeholder="Specify the journal type" style="margin-top:8px;display:none"></div>
        <div class="field"><label>ISSN Number</label><input class="input" name="issn"></div>
        <div class="field"><label>Volume &amp; Issue No</label><input class="input" name="volume_issue"></div>
        <div class="field"><label>Month &amp; Year of Publication <span class="card-sub">(mm/yyyy)</span></label><input class="input" name="publication_month" placeholder="e.g. 12/2025"></div>
        <div class="field"><label>Link to the Article / DOI</label><input class="input" name="doi"></div>
        <div class="field"><label>Link to Journal Website</label><input class="input" name="journal_link" type="url"></div>
        <div class="field"><label>Document Link</label><input class="input" name="document_link" type="url"></div>

      <?php elseif ($selectedType === 'book'): ?>
        <div class="field"><label>Book / Book Chapter</label><select class="select" name="publication_category"><option>Book</option><option>Book Chapter</option></select></div>
        <div class="field" style="grid-column:span 2"><label>Title of the Book / Book Chapter <span class="req">*</span></label><input class="input" name="title" required></div>
        <div class="field"><label>Publisher Name</label><input class="input" name="publisher_name"></div>
        <div class="field"><label>ISSN / ISBN Number</label><input class="input" name="isbn"></div>
        <div class="field"><label>Month &amp; Year of Publication <span class="card-sub">(mm/yyyy)</span></label><input class="input" name="publication_month" placeholder="e.g. 01/2026"></div>
        <div class="field"><label>Document Link</label><input class="input" name="document_link" type="url"></div>

      <?php elseif ($selectedType === 'conference'): ?>
        <div class="field"><label>Author Type</label><select class="select" name="author_type"><option>Author-1</option><option>Co-Author</option></select></div>
        <div class="field" style="grid-column:span 2"><label>Paper Title <span class="req">*</span></label><input class="input" name="paper_title" required></div>
        <div class="field"><label>Conference Name</label><input class="input" name="conference_name"></div>
        <div class="field"><label>Type</label><select class="select" name="conference_type"><option>National</option><option>International</option></select></div>
        <div class="field"><label>Venue</label><input class="input" name="venue"></div>
        <div class="field"><label>Conference Date</label><input class="input" name="conference_date" type="date"></div>

      <?php elseif ($selectedType === 'patent'): ?>
        <div class="field"><label>Patent / Copyright</label><select class="select" name="category"><option>Patent</option><option>Copyright</option></select></div>
        <div class="field" style="grid-column:span 2"><label>Title of the Patent / Copyright <span class="req">*</span></label><input class="input" name="title" required></div>
        <div class="field"><label>Patent / Copyright Number</label><input class="input" name="patent_number"></div>
        <div class="field"><label>Date of Publication <span class="card-sub">(dd/mm/yyyy)</span></label><input class="input" name="publication_date" type="date"></div>
        <div class="field"><label>Document Link</label><input class="input" name="document_link" type="url"></div>

      <?php elseif ($selectedType === 'fdp'): ?>
        <div class="field"><label>Duration</label><input class="input" name="duration" placeholder="e.g. 5 days / 1 week"></div>
        <div class="field"><label>Event Type</label><select class="select" name="event_type"><option>FDP</option><option>Workshop</option><option>Seminar</option><option>STTP</option><option>Conference</option></select></div>
        <div class="field" style="grid-column:span 2"><label>Name of the FDP / Seminar / Workshop <span class="req">*</span></label><input class="input" name="title" required></div>
        <div class="field"><label>Mode</label><select class="select" name="mode"><option>Online</option><option>Offline</option><option>Hybrid</option></select></div>
        <div class="field"><label>Organized By <span class="card-sub">(Institution / Agency)</span></label><input class="input" name="organized_by"></div>
        <div class="field"><label>From Date <span class="card-sub">(dd/mm/yyyy)</span></label><input class="input" name="from_date" type="date"></div>
        <div class="field"><label>To Date <span class="card-sub">(dd/mm/yyyy)</span></label><input class="input" name="to_date" type="date"></div>
        <div class="field"><label>Certificate Link</label><input class="input" name="certificate_link" type="url"></div>

      <?php elseif ($selectedType === 'mou'): ?>
        <div class="field"><label>Department</label><select class="select" name="department">
          <?php foreach($departments as $d):?><option value="<?=e($d['name'])?>" <?=$user['department']===$d['name']?'selected':''?>><?=e($d['name'])?></option><?php endforeach;?></select></div>
        <div class="field"><label>Signed Date <span class="card-sub">(dd/mm/yyyy)</span></label><input class="input" name="signed_date" type="date"></div>
        <div class="field" style="grid-column:span 2"><label>Name &amp; Address of the Collaborating Body <span class="req">*</span> <span class="card-sub">(Industry / Institution / Agency)</span></label><input class="input" name="organization" required></div>
        <div class="field"><label>Valid upto <span class="card-sub">(dd/mm/yyyy)</span></label><input class="input" name="valid_upto" type="date"></div>
        <div class="field" style="grid-column:span 2"><label>Purpose of Collaboration</label><input class="input" name="purpose"></div>
        <div class="field"><label>Document Link</label><input class="input" name="document_link" type="url"></div>

      <?php elseif ($selectedType === 'event'): ?>
        <div class="field"><label>Department</label><select class="select" name="department">
          <?php foreach($departments as $d):?><option value="<?=e($d['name'])?>" <?=$user['department']===$d['name']?'selected':''?>><?=e($d['name'])?></option><?php endforeach;?></select></div>
        <div class="field"><label>Date <span class="card-sub">(dd/mm/yyyy)</span></label><input class="input" name="event_date" type="date"></div>
        <div class="field" style="grid-column:span 2"><label>Event Title <span class="req">*</span></label><input class="input" name="event_title" required></div>
        <div class="field"><label>Event Type</label>
          <select class="select js-other" name="event_type" data-other="event_type_other"><option>Seminar</option><option>Workshop</option><option>Webinar</option><option>FDP</option><option>Conference</option><option>Symposium</option><option>Guest Lecture</option><option>Others</option></select>
          <input class="input js-other-text" name="event_type_other" placeholder="Specify the event type" style="margin-top:8px;display:none"></div>
        <div class="field"><label>Mode</label><select class="select" name="mode"><option>Online</option><option>Offline</option><option>Hybrid</option></select></div>
        <div class="field" style="grid-column:span 2"><label>Chief Guest / Resource Person <span class="card-sub">— Name &amp; Designation (with Contact Details)</span></label><input class="input" name="resource_person"></div>
        <div class="field"><label>No. of Participants</label><input class="input" name="participants" type="number" min="0"></div>
        <div class="field"><label>Sponsorship <span class="card-sub">(if any)</span></label><input class="input" name="sponsorship"></div>
        <div class="field" style="grid-column:span 2"><label>Web Link to Event Report</label><input class="input" name="report_link" type="url"></div>

      <?php elseif ($selectedType === 'nptel'): ?>
        <div class="field"><label>Department</label><select class="select" name="department">
          <?php foreach($departments as $d):?><option value="<?=e($d['name'])?>" <?=$user['department']===$d['name']?'selected':''?>><?=e($d['name'])?></option><?php endforeach;?></select></div>
        <div class="field"><label>Candidate Name <span class="req">*</span></label><input class="input" name="candidate_name" required></div>
        <div class="field"><label>Category</label>
          <select class="select js-other" name="category" data-other="category_other"><option>Faculty</option><option>Student</option><option>Others</option></select>
          <input class="input js-other-text" name="category_other" placeholder="Specify the category" style="margin-top:8px;display:none"></div>
        <div class="field" style="grid-column:span 2"><label>Course Title <span class="req">*</span></label><input class="input" name="course_title" required></div>
        <div class="field"><label>Session</label><input class="input" name="session" placeholder="e.g. Jul 2025 - Dec 2025"></div>
        <div class="field"><label>Grade</label><input class="input" name="grade"></div>
        <div class="field"><label>Certificate Link</label><input class="input" name="certificate_link" type="url"></div>

      <?php elseif ($selectedType === 'internship'): ?>
        <div class="field"><label>Reg. No</label><input class="input" name="reg_no"></div>
        <div class="field"><label>Name of the student <span class="req">*</span></label><input class="input" name="student_name" required></div>
        <div class="field"><label>Dept / Branch</label><select class="select" name="department">
          <?php foreach($departments as $d):?><option value="<?=e($d['name'])?>" <?=$user['department']===$d['name']?'selected':''?>><?=e($d['name'])?></option><?php endforeach;?></select></div>
        <div class="field" style="grid-column:span 2"><label>Title of Internship <span class="req">*</span></label><input class="input" name="title" required></div>
        <div class="field" style="grid-column:span 2"><label>Industry/Institution Name &amp; Address</label><input class="input" name="industry"></div>
        <div class="field"><label>Duration</label><input class="input" name="duration" placeholder="e.g. 1 month"></div>
        <div class="field"><label>No. of Days</label><input class="input" name="days" type="number" min="0"></div>
        <div class="field" style="grid-column:span 2"><label>Link to the Certificate / Document</label><input class="input" name="certificate_link" type="url"></div>

      <?php elseif ($selectedType === 'placement'): ?>
        <div class="field"><label>Reg. No</label><input class="input" name="reg_no"></div>
        <div class="field"><label>Student Name <span class="req">*</span></label><input class="input" name="student_name" required></div>
        <div class="field"><label>Dept / Branch</label><select class="select" name="department">
          <?php foreach($departments as $d):?><option value="<?=e($d['name'])?>" <?=$user['department']===$d['name']?'selected':''?>><?=e($d['name'])?></option><?php endforeach;?></select></div>
        <div class="field"><label>Job Name</label><input class="input" name="job_title"></div>
        <div class="field"><label>Mode <span class="card-sub">(On Campus / Off Campus)</span></label><select class="select" name="mode"><option>On Campus</option><option>Off Campus</option></select></div>
        <div class="field" style="grid-column:span 2"><label>Company Name &amp; Address <span class="req">*</span> <span class="card-sub">(with Contact Details)</span></label><input class="input" name="company" required></div>
        <div class="field"><label>Pay Scale</label><input class="input" name="pay_scale"></div>
        <div class="field" style="grid-column:span 2"><label>Web Link to Appointment Order</label><input class="input" name="appointment_order_link" type="url"></div>

      <?php elseif ($selectedType === 'nss'): ?>
        <div class="field"><label>Department</label><select class="select" name="department">
          <?php foreach($departments as $d):?><option value="<?=e($d['name'])?>" <?=$user['department']===$d['name']?'selected':''?>><?=e($d['name'])?></option><?php endforeach;?></select></div>
        <div class="field"><label>Academic Year</label><select class="select" name="academic_year"><?php foreach($years as $y):?><option><?=e($y)?></option><?php endforeach;?></select></div>
        <div class="field"><label>Date <span class="card-sub">(dd/mm/yyyy)</span></label><input class="input" name="activity_date" type="date"></div>
        <div class="field"><label>Activity Type</label><select class="select" name="activity_type"><option>NSS</option><option>YRC</option><option>RRC</option></select></div>
        <div class="field" style="grid-column:span 2"><label>Name of the Activity <span class="req">*</span></label><input class="input" name="activity_name" required></div>
        <div class="field"><label>Venue</label><input class="input" name="venue"></div>
        <div class="field"><label>No. of Students Participated</label><input class="input" name="participants" type="number" min="0"></div>
        <div class="field" style="grid-column:span 2"><label>Name of External Agency / Member Involved <span class="card-sub">— Name &amp; Designation (with Contact Details)</span></label><input class="input" name="external_agency"></div>
        <div class="field" style="grid-column:span 2"><label>Web Link to Event Report</label><input class="input" name="report_link" type="url"></div>

      <?php elseif ($selectedType === 'online_course'): ?>
        <div class="field"><label>Department</label><select class="select" name="department">
          <?php foreach($departments as $d):?><option value="<?=e($d['name'])?>" <?=$user['department']===$d['name']?'selected':''?>><?=e($d['name'])?></option><?php endforeach;?></select></div>
        <div class="field"><label>Academic Year</label><select class="select" name="academic_year"><?php foreach($years as $y):?><option><?=e($y)?></option><?php endforeach;?></select></div>
        <div class="field"><label>Candidate Name <span class="req">*</span></label><input class="input" name="candidate_name" required></div>
        <div class="field"><label>Category</label><select class="select" name="category"><option>Faculty</option><option>Student</option></select></div>
        <div class="field" style="grid-column:span 2"><label>Course Title <span class="req">*</span></label><input class="input" name="course_title" required></div>
        <div class="field"><label>Provider <span class="card-sub">(Coursera / NPTEL / Udemy / …)</span></label><input class="input" name="provider"></div>
        <div class="field"><label>Duration</label><input class="input" name="duration" placeholder="e.g. 8 weeks"></div>
        <div class="field"><label>Month &amp; Year <span class="card-sub">(mm/yyyy)</span></label><input class="input" name="month_year" placeholder="e.g. 03/2026"></div>
        <div class="field"><label>Certificate Link</label><input class="input" name="certificate_link" type="url"></div>

      <?php elseif ($selectedType === 'student_achievement' || $selectedType === 'student_participation'): ?>
        <div class="field"><label>Dept / Branch</label><select class="select" name="department">
          <?php foreach($departments as $d):?><option value="<?=e($d['name'])?>" <?=$user['department']===$d['name']?'selected':''?>><?=e($d['name'])?></option><?php endforeach;?></select></div>
        <div class="field"><label>Academic Year</label><select class="select" name="academic_year"><?php foreach($years as $y):?><option><?=e($y)?></option><?php endforeach;?></select></div>
        <?php if ($selectedType === 'student_participation'): ?>
        <div class="field"><label>Activity Category</label><select class="select" name="activity_category"><option>Co-curricular</option><option>Extra-curricular</option></select></div>
        <?php endif; ?>
        <div class="field"><label>Reg. No</label><input class="input" name="reg_no"></div>
        <div class="field"><label>Name of the student <span class="req">*</span></label><input class="input" name="student_name" required></div>
        <div class="field"><label>Event Type</label><input class="input" name="event_type" placeholder="e.g. Technical / Sports / Cultural"></div>
        <div class="field"><label>Name of the Event</label><input class="input" name="event_name"></div>
        <div class="field" style="grid-column:span 2"><label>Name of the Function / Programme</label><input class="input" name="function_name"></div>
        <div class="field"><label>Date of the Event <span class="card-sub">(dd/mm/yyyy)</span></label><input class="input" name="event_date" type="date"></div>
        <div class="field"><label>Team / Individual</label><select class="select" name="team_individual"><option>Individual</option><option>Team</option></select></div>
        <div class="field"><label>Level</label><select class="select" name="level_secured"><option>University</option><option>State</option><option>National</option><option>International</option></select></div>
        <div class="field"><label>Position Secured</label><input class="input" name="position_secured" placeholder="e.g. First / Winner / Participant"></div>
        <div class="field" style="grid-column:span 2"><label>Name of the Organising Institution</label><input class="input" name="organising_institution"></div>
        <div class="field" style="grid-column:span 2"><label>Link to the Certificate / Document</label><input class="input" name="certificate_link" type="url"></div>

      <?php elseif ($selectedType === 'summer_training'): ?>
        <div class="field"><label>Dept / Branch</label><select class="select" name="department">
          <?php foreach($departments as $d):?><option value="<?=e($d['name'])?>" <?=$user['department']===$d['name']?'selected':''?>><?=e($d['name'])?></option><?php endforeach;?></select></div>
        <div class="field"><label>Academic Year</label><select class="select" name="academic_year"><?php foreach($years as $y):?><option><?=e($y)?></option><?php endforeach;?></select></div>
        <div class="field"><label>Reg. No</label><input class="input" name="reg_no"></div>
        <div class="field"><label>Name of the student <span class="req">*</span></label><input class="input" name="student_name" required></div>
        <div class="field" style="grid-column:span 2"><label>Title of Training <span class="req">*</span></label><input class="input" name="title" required></div>
        <div class="field" style="grid-column:span 2"><label>Industry/Institution Name &amp; Address</label><input class="input" name="industry"></div>
        <div class="field"><label>Duration</label><input class="input" name="duration" placeholder="e.g. 1 month"></div>
        <div class="field"><label>No. of Days</label><input class="input" name="days" type="number" min="0"></div>
        <div class="field" style="grid-column:span 2"><label>Link to the Certificate / Document</label><input class="input" name="certificate_link" type="url"></div>

      <?php elseif ($selectedType === 'value_added'): ?>
        <div class="field"><label>Department</label><select class="select" name="department">
          <?php foreach($departments as $d):?><option value="<?=e($d['name'])?>" <?=$user['department']===$d['name']?'selected':''?>><?=e($d['name'])?></option><?php endforeach;?></select></div>
        <div class="field"><label>Academic Year</label><select class="select" name="academic_year"><?php foreach($years as $y):?><option><?=e($y)?></option><?php endforeach;?></select></div>
        <div class="field"><label>From Date <span class="card-sub">(dd/mm/yyyy)</span></label><input class="input" name="from_date" type="date"></div>
        <div class="field"><label>To Date <span class="card-sub">(dd/mm/yyyy)</span></label><input class="input" name="to_date" type="date"></div>
        <div class="field" style="grid-column:span 2"><label>Course Title <span class="req">*</span></label><input class="input" name="course_title" required></div>
        <div class="field"><label>Mode</label><select class="select" name="mode"><option>Online</option><option>Offline</option><option>Hybrid</option></select></div>
        <div class="field"><label>No. of Participants</label><input class="input" name="participants" type="number" min="0"></div>
        <div class="field" style="grid-column:span 2"><label>Resource Person <span class="card-sub">— Name &amp; Designation (with Contact Details)</span></label><input class="input" name="resource_person"></div>
        <div class="field" style="grid-column:span 2"><label>Web Link to Event Report</label><input class="input" name="report_link" type="url"></div>

      <?php elseif ($selectedType === 'training'): ?>
        <div class="field"><label>Department</label><select class="select" name="department">
          <?php foreach($departments as $d):?><option value="<?=e($d['name'])?>" <?=$user['department']===$d['name']?'selected':''?>><?=e($d['name'])?></option><?php endforeach;?></select></div>
        <div class="field"><label>Academic Year</label><select class="select" name="academic_year"><?php foreach($years as $y):?><option><?=e($y)?></option><?php endforeach;?></select></div>
        <div class="field"><label>Date <span class="card-sub">(dd/mm/yyyy)</span></label><input class="input" name="event_date" type="date"></div>
        <div class="field" style="grid-column:span 2"><label>Event Title <span class="req">*</span></label><input class="input" name="event_title" required></div>
        <div class="field"><label>Event Type</label><select class="select" name="event_type"><option>Career Guidance</option><option>Counselling</option><option>ICT</option><option>Life Skills</option><option>Soft Skills</option></select></div>
        <div class="field"><label>Mode</label><select class="select" name="mode"><option>Online</option><option>Offline</option><option>Hybrid</option></select></div>
        <div class="field"><label>No. of Participants</label><input class="input" name="participants" type="number" min="0"></div>
        <div class="field"><label>Sponsorship <span class="card-sub">(if any)</span></label><input class="input" name="sponsorship"></div>
        <div class="field" style="grid-column:span 2"><label>Chief Guest / Resource Person <span class="card-sub">— Name &amp; Designation (with Contact Details)</span></label><input class="input" name="resource_person"></div>
        <div class="field" style="grid-column:span 2"><label>Web Link to Event Report</label><input class="input" name="report_link" type="url"></div>
      <?php endif; ?>

        <!-- Proof / attachment (optional) — carried onto the report -->
        <div class="field" style="grid-column:span 2">
          <label>Proof / Attachment <span class="card-sub">— PDF only, up to 2 MB</span></label>
          <input class="input" type="file" name="proof" accept="application/pdf,.pdf">
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
          <!-- formnovalidate so you can move on even with nothing entered here -->
          <button type="submit" name="nav" value="next" class="btn btn-primary" formnovalidate>
            Next: <?= e($types[$nextType]['label']) ?> <?= icon('arrow-right') ?>
          </button>
        <?php else: ?>
          <button type="submit" name="nav" value="submit" class="btn btn-primary" formnovalidate><?= icon('check') ?> Submit for Review</button>
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
    </script>
  </div>
</div>

<!-- My recent records -->
<?php
  // Filters for the submissions list (kept separate from the form's ?type= tab).
  $mType   = (string) input('mtype');
  if (!isset($types[$mType])) { $mType = ''; }
  $mStatus = (string) input('mstatus');
  if (!in_array($mStatus, ['Draft', 'Submitted', 'Approved', 'Rejected'], true)) { $mStatus = ''; }
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
              <?php foreach (['Approved', 'Submitted', 'Draft', 'Rejected'] as $o): ?>
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
        $statusBadge = ['Draft'=>'neutral','Submitted'=>'info','Approved'=>'success','Rejected'=>'danger'];
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
