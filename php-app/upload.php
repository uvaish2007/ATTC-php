<?php
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/models/Record.php';
require_once __DIR__ . '/models/Department.php';

$user = require_role(['Admin', 'HoD', 'Coordinator', 'Faculty']);

$types = record_types();
$departments = departments_all();

// Handle form submissions for new records
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $type = (string) input('record_type');

    if (!isset($types[$type])) {
        flash('error', 'Invalid record type.');
        redirect('/upload.php');
    }

    $table = $types[$type]['table'];
    $pdo = db();

    // Columns the submitter is NEVER allowed to set from the form. These are
    // owned by the server: who created the record, its review state, and the
    // audit timestamps. Without this guard a crafted POST could, for example,
    // set approved_by or back-date created_at (mass-assignment).
    $protected = ['id', 'created_by', 'status', 'approved_by', 'review_remark', 'created_at', 'updated_at'];

    // Only real columns of THIS table are accepted, so a POST field can neither
    // hit another type's column nor invent one.
    $tableColumns = $pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_COLUMN);
    $allowed      = array_diff($tableColumns, $protected);

    // Build insert dynamically based on type
    $fields = [];
    $values = [];
    $placeholders = [];

    // Common, server-set columns.
    $fields[] = 'created_by';  $values[] = $user['id']; $placeholders[] = '?';
    $fields[] = 'status';      $values[] = 'Submitted'; $placeholders[] = '?';

    // Accept only whitelisted, non-empty form fields.
    foreach ($_POST as $k => $v) {
        if (!in_array($k, $allowed, true) || $v === '') continue;
        $fields[] = $k;
        $values[] = $v;
        $placeholders[] = '?';
    }

    try {
        $sql = "INSERT INTO `$table` (" . implode(',', $fields) . ") VALUES (" . implode(',', $placeholders) . ")";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($values);
        flash('success', $types[$type]['label'] . ' submitted successfully.');
    } catch (\PDOException $e) {
        // Don't surface the raw SQL error to the user; log it instead.
        error_log('upload.php insert failed: ' . $e->getMessage());
        flash('error', 'Sorry, that record could not be saved. Please check the fields and try again.');
    }
    redirect('/upload.php');
}

// Current user's records
$myRecords = my_records($user['id']);
$selectedType = trim((string)($_GET['type'] ?? 'journal'));
if (!isset($types[$selectedType])) $selectedType = 'journal';

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
  <div class="card-head"><div><div class="card-title">New <?= e($types[$selectedType]['label']) ?></div><div class="card-sub">Fill in the details and submit for review</div></div></div>
  <div class="card-body">
    <form method="post">
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
          <select class="select" name="academic_year"><option>2025-26</option><option>2024-25</option><option>2023-24</option></select></div>
      <?php endif; ?>

      <?php if ($selectedType === 'journal'): ?>
        <div class="field"><label>Author Type</label><select class="select" name="author_type"><option>Author-1</option><option>Co-Author</option></select></div>
        <div class="field" style="grid-column:span 2"><label>Paper Title <span class="req">*</span></label><input class="input" name="paper_title" required></div>
        <div class="field"><label>Journal Name</label><input class="input" name="journal_name"></div>
        <div class="field"><label>Journal Type</label><select class="select" name="journal_type"><option>UGC Care</option><option>Scopus</option><option>SCI</option><option>Others</option></select></div>
        <div class="field"><label>ISSN</label><input class="input" name="issn"></div>
        <div class="field"><label>Volume / Issue</label><input class="input" name="volume_issue"></div>
        <div class="field"><label>DOI</label><input class="input" name="doi"></div>
        <div class="field"><label>Journal Link</label><input class="input" name="journal_link" type="url"></div>

      <?php elseif ($selectedType === 'book'): ?>
        <div class="field"><label>Category</label><select class="select" name="publication_category"><option>Book</option><option>Book Chapter</option></select></div>
        <div class="field" style="grid-column:span 2"><label>Title <span class="req">*</span></label><input class="input" name="title" required></div>
        <div class="field"><label>Publisher</label><input class="input" name="publisher_name"></div>
        <div class="field"><label>ISBN</label><input class="input" name="isbn"></div>

      <?php elseif ($selectedType === 'conference'): ?>
        <div class="field"><label>Author Type</label><select class="select" name="author_type"><option>Author-1</option><option>Co-Author</option></select></div>
        <div class="field" style="grid-column:span 2"><label>Paper Title <span class="req">*</span></label><input class="input" name="paper_title" required></div>
        <div class="field"><label>Conference Name</label><input class="input" name="conference_name"></div>
        <div class="field"><label>Type</label><select class="select" name="conference_type"><option>National</option><option>International</option></select></div>
        <div class="field"><label>Venue</label><input class="input" name="venue"></div>
        <div class="field"><label>Conference Date</label><input class="input" name="conference_date" type="date"></div>

      <?php elseif ($selectedType === 'patent'): ?>
        <div class="field"><label>Category</label><select class="select" name="category"><option>Patent</option><option>Copyright</option></select></div>
        <div class="field" style="grid-column:span 2"><label>Title <span class="req">*</span></label><input class="input" name="title" required></div>
        <div class="field"><label>Patent Number</label><input class="input" name="patent_number"></div>
        <div class="field"><label>Publication Date</label><input class="input" name="publication_date" type="date"></div>

      <?php elseif ($selectedType === 'fdp'): ?>
        <div class="field"><label>Event Type</label><select class="select" name="event_type"><option>FDP</option><option>Workshop</option><option>Seminar</option></select></div>
        <div class="field" style="grid-column:span 2"><label>Title <span class="req">*</span></label><input class="input" name="title" required></div>
        <div class="field"><label>From Date</label><input class="input" name="from_date" type="date"></div>
        <div class="field"><label>To Date</label><input class="input" name="to_date" type="date"></div>
        <div class="field"><label>Mode</label><select class="select" name="mode"><option>Online</option><option>Offline</option><option>Hybrid</option></select></div>
        <div class="field"><label>Organized By</label><input class="input" name="organized_by"></div>

      <?php elseif ($selectedType === 'mou'): ?>
        <div class="field"><label>Department</label><select class="select" name="department">
          <?php foreach($departments as $d):?><option value="<?=e($d['name'])?>" <?=$user['department']===$d['name']?'selected':''?>><?=e($d['name'])?></option><?php endforeach;?></select></div>
        <div class="field"><label>Organization <span class="req">*</span></label><input class="input" name="organization" required></div>
        <div class="field"><label>Signed Date</label><input class="input" name="signed_date" type="date"></div>
        <div class="field"><label>Valid Upto</label><input class="input" name="valid_upto" type="date"></div>
        <div class="field" style="grid-column:span 2"><label>Purpose</label><input class="input" name="purpose"></div>

      <?php elseif ($selectedType === 'event'): ?>
        <div class="field"><label>Department</label><select class="select" name="department">
          <?php foreach($departments as $d):?><option value="<?=e($d['name'])?>" <?=$user['department']===$d['name']?'selected':''?>><?=e($d['name'])?></option><?php endforeach;?></select></div>
        <div class="field"><label>Event Title <span class="req">*</span></label><input class="input" name="event_title" required></div>
        <div class="field"><label>Event Type</label><select class="select" name="event_type"><option>Workshop</option><option>Seminar</option><option>Hackathon</option><option>Guest Lecture</option><option>Others</option></select></div>
        <div class="field"><label>Event Date</label><input class="input" name="event_date" type="date"></div>
        <div class="field"><label>Mode</label><select class="select" name="mode"><option>Online</option><option>Offline</option><option>Hybrid</option></select></div>
        <div class="field"><label>Resource Person</label><input class="input" name="resource_person"></div>

      <?php elseif ($selectedType === 'nptel'): ?>
        <div class="field"><label>Department</label><select class="select" name="department">
          <?php foreach($departments as $d):?><option value="<?=e($d['name'])?>" <?=$user['department']===$d['name']?'selected':''?>><?=e($d['name'])?></option><?php endforeach;?></select></div>
        <div class="field"><label>Candidate Name <span class="req">*</span></label><input class="input" name="candidate_name" required></div>
        <div class="field"><label>Course Title <span class="req">*</span></label><input class="input" name="course_title" required></div>
        <div class="field"><label>Category</label><select class="select" name="category"><option>NPTEL</option><option>Coursera</option><option>Others</option></select></div>
        <div class="field"><label>Session</label><input class="input" name="session" placeholder="e.g. Jan-Apr 2026"></div>
        <div class="field"><label>Grade</label><input class="input" name="grade"></div>

      <?php elseif ($selectedType === 'internship'): ?>
        <div class="field"><label>Reg No</label><input class="input" name="reg_no"></div>
        <div class="field"><label>Student Name <span class="req">*</span></label><input class="input" name="student_name" required></div>
        <div class="field" style="grid-column:span 2"><label>Title <span class="req">*</span></label><input class="input" name="title" required></div>
        <div class="field"><label>Industry</label><input class="input" name="industry"></div>
        <div class="field"><label>Duration</label><input class="input" name="duration"></div>

      <?php elseif ($selectedType === 'placement'): ?>
        <div class="field"><label>Reg No</label><input class="input" name="reg_no"></div>
        <div class="field"><label>Student Name <span class="req">*</span></label><input class="input" name="student_name" required></div>
        <div class="field"><label>Job Title</label><input class="input" name="job_title"></div>
        <div class="field"><label>Company <span class="req">*</span></label><input class="input" name="company" required></div>
        <div class="field"><label>Mode</label><select class="select" name="mode"><option>On-Campus</option><option>Off-Campus</option></select></div>
        <div class="field"><label>Pay Scale</label><input class="input" name="pay_scale"></div>
      <?php endif; ?>
      </div>

      <button type="submit" class="btn btn-primary" style="margin-top:8px"><?= icon('upload') ?> Submit for Review</button>
    </form>
  </div>
</div>

<!-- My recent records -->
<div class="card">
  <div class="card-head"><div><div class="card-title">My Submissions</div><div class="card-sub"><?= count($myRecords) ?> total records</div></div></div>
  <div class="card-body" style="padding:0">
    <?php if (empty($myRecords)): ?>
      <div class="empty"><div class="ic"><?= icon('upload', 20) ?></div><p>No submissions yet</p></div>
    <?php else: ?>
      <div class="table-wrap"><table class="data"><thead><tr>
        <th style="padding-left:24px">Record</th><th>Type</th><th>Status</th><th>Submitted</th>
      </tr></thead><tbody>
      <?php foreach (array_slice($myRecords, 0, 20) as $r):
        $statusBadge = ['Draft'=>'neutral','Submitted'=>'info','Approved'=>'success','Rejected'=>'danger'];
      ?>
        <tr>
          <td style="padding-left:24px"><div style="font-weight:500;max-width:350px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= e($r['_title']) ?></div></td>
          <td><span class="badge badge-neutral"><?= e($r['_type_label']) ?></span></td>
          <td><span class="badge badge-<?= $statusBadge[$r['status']] ?? 'neutral' ?>"><?= e($r['status']) ?></span></td>
          <td class="card-sub"><?= e(time_ago($r['created_at'])) ?></td>
        </tr>
      <?php endforeach; ?></tbody></table></div>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/inc/footer.php'; ?>
