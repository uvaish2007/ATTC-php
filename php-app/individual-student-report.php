<?php
/**
 * Individual Student Achievement Report — Consolidated student report and inspection view.
 * Displays student details, achievement summary, category breakdown chart, and category-wise record tables.
 * Backend authorization strictly enforced via can_user_view_student_report().
 */

require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/icons.php';
require_once __DIR__ . '/models/StudentAchievement.php';

$user = require_login();

// Read query parameters
$studentKey   = trim((string) input('key', ''));
$regNoHint    = trim((string) input('reg_no', ''));
$nameHint     = trim((string) input('name', ''));
$deptHint     = trim((string) input('dept', ''));
require_once __DIR__ . '/models/ExecutiveMeeting.php';
// EM-SPEC-03: Centralized Academic Year and EM Duration filter resolution.
$rawYear      = input('academic_year') ?: input('year');
$emCtx        = em_resolve_filter_context($rawYear, input('em'));
$academicYear = $emCtx['year'];
$em           = $emCtx['em'];
$emWindow     = $emCtx['window'];
$category     = trim((string) input('category', '')) ?: null;

// If key is empty but we have reg_no or name+dept, compute key
if ($studentKey === '') {
    if ($regNoHint !== '' && $regNoHint !== '—') {
        $studentKey = student_make_key($regNoHint, $nameHint, $deptHint);
    } elseif ($nameHint !== '') {
        $studentKey = student_make_key(null, $nameHint, $deptHint);
    }
}

if ($studentKey === '') {
    http_response_code(400);
    $pageTitle = 'Student Not Specified';
    require __DIR__ . '/inc/header.php';
    echo '<div class="alert alert-danger mt-4">Invalid request: No student identifier specified.</div>';
    echo '<div class="mt-3"><a class="btn btn-secondary" href="' . e(url('student-achievements.php')) . '">' . icon('arrow-left', 14) . ' Back to Student Achievements</a></div>';
    require __DIR__ . '/inc/footer.php';
    exit;
}

// Fetch detailed student data
$data    = student_achievement_details($studentKey, $academicYear, $category, $emWindow, $regNoHint, $nameHint, $deptHint);
$student = $data['student'];
$records = $data['records'];
$byCat   = $data['by_category'];
$summary = $data['summary'];

if (!$student) {
    http_response_code(404);
    $pageTitle = 'Student Not Found';
    require __DIR__ . '/inc/header.php';
    echo '<div class="alert alert-danger mt-4">Student record not found for the specified identifier.</div>';
    echo '<div class="mt-3"><a class="btn btn-secondary" href="' . e(url('student-achievements.php')) . '">' . icon('arrow-left', 14) . ' Back to Student Achievements</a></div>';
    require __DIR__ . '/inc/footer.php';
    exit;
}

// ---- STRICT BACKEND AUTHORIZATION CHECK ----
if (!can_user_view_student_report($user, $student['department'])) {
    http_response_code(403);
    require __DIR__ . '/denied.php';
    exit;
}

$years = academic_years();
$activeYear = active_academic_year();

// Build query string for navigation links
$navParams = [
    'key'           => $student['key'],
    'reg_no'        => $student['reg_no'],
    'name'          => $student['name'],
    'dept'          => $student['department'],
    'academic_year' => $academicYear,
];
$presentUrl = url('present-student-report.php') . '?' . http_build_query($navParams);
$refreshUrl = url('individual-student-report.php') . '?' . http_build_query($navParams);

// Summary counts per category for the table & chart
$catSummary = [];
foreach ($byCat as $cLabel => $cRecords) {
    $catSummary[$cLabel] = count($cRecords);
}

$pageTitle  = 'Individual Student Achievement Report';
$breadcrumb = 'Student Achievement Report';
require __DIR__ . '/inc/header.php';
?>

<!-- Header -->
<div class="page-head">
  <div>
    <h1>Individual Student Achievement Report</h1>
    <div class="sub">
      <?= e($student['name']) ?> &middot; Reg No: <?= e($student['reg_no']) ?> &middot; Department of <?= e($student['department']) ?> &middot; Academic Year: <?= e($academicYear ?: 'All Years') ?>
    </div>
  </div>

  <div class="actions flex gap-2 items-center" style="flex-wrap:wrap;">
    <a class="btn btn-secondary btn-sm" href="<?= e(url('student-achievements.php')) ?>" title="Back to Student Performance Matrix">
      <?= icon('arrow-left', 14) ?> Performance Matrix
    </a>

    <a class="btn btn-secondary btn-sm" href="<?= e($refreshUrl) ?>" title="Refresh page">
      <?= icon('refresh', 14) ?> Refresh
    </a>

    <a class="btn btn-secondary btn-sm" href="<?= e($presentUrl) ?>" style="background:#131D3B; color:#ffffff; border-color:#131D3B;" title="Launch Student Presentation Review Mode">
      <?= icon('play-circle', 14) ?> Present Report
    </a>
  </div>
</div>

<!-- Student Information Card -->
<div class="card mt-4">
  <div class="card-body" style="padding:20px 24px;">
    <div style="display:flex; align-items:center; gap:16px; flex-wrap:wrap;">
      <div class="avatar-dark" style="width:54px; height:54px; border-radius:50%; background:#131D3B; color:#fff; display:grid; place-items:center; font-weight:700; font-size:18px;">
        <?= e(initials($student['name'])) ?>
      </div>
      <div style="flex:1; min-width:220px;">
        <div style="font-size:20px; font-weight:700; color:var(--ink,#131D3B);"><?= e($student['name']) ?></div>
        <div style="font-size:13px; color:var(--ink-muted,#5A6785); margin-top:2px;">
          Department of <?= e($student['department']) ?> &middot; Academic Year <?= e($academicYear) ?>
        </div>
      </div>
      <div style="display:flex; gap:20px; flex-wrap:wrap; background:var(--navy-50,#F4F6FA); padding:10px 16px; border-radius:10px; border:1px solid var(--hairline,#E4E9F2);">
        <div>
          <div style="font-size:11px; font-weight:700; color:var(--ink-muted,#5A6785); text-transform:uppercase;">Register Number</div>
          <div style="font-weight:700; color:var(--ink,#131D3B); font-family:monospace;"><?= e($student['reg_no']) ?></div>
        </div>
        <div>
          <div style="font-size:11px; font-weight:700; color:var(--ink-muted,#5A6785); text-transform:uppercase;">Department</div>
          <div style="font-weight:600; color:var(--ink,#131D3B);"><?= e($student['department']) ?></div>
        </div>
        <div>
          <div style="font-size:11px; font-weight:700; color:var(--ink-muted,#5A6785); text-transform:uppercase;">Total Achievements</div>
          <div style="font-weight:700; color:var(--brand,#FF4F01); font-size:15px;"><?= $summary['total'] ?></div>
        </div>
        <div>
          <div style="font-size:11px; font-weight:700; color:var(--ink-muted,#5A6785); text-transform:uppercase;">Status</div>
          <div style="font-weight:600;">
            <span class="badge badge-success"><?= $summary['approved'] ?> Approved</span>
            <?php if ($summary['pending'] > 0): ?>
              <span class="badge badge-neutral" style="margin-left:4px;"><?= $summary['pending'] ?> Pending</span>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Academic Year & Category Filter Bar -->
<form method="get" class="fbar mt-4" id="indivStudentFilterForm">
  <input type="hidden" name="key" value="<?= e($student['key']) ?>">
  <input type="hidden" name="reg_no" value="<?= e($student['reg_no']) ?>">
  <input type="hidden" name="name" value="<?= e($student['name']) ?>">
  <input type="hidden" name="dept" value="<?= e($student['department']) ?>">
  <span class="fbar-title"><?= icon('filter', 14) ?> Filter Report</span>

  <label class="fb-field" title="Filter by Academic Year">
    <span class="fb-k">Academic Year</span>
    <select name="academic_year" onchange="this.form.submit()">
      <?php foreach ($years as $y): ?>
        <option value="<?= e($y) ?>" <?= $academicYear === $y ? 'selected' : '' ?>>
          <?= e($y) ?><?= $y === active_academic_year() ? ' (Active)' : '' ?>
        </option>
      <?php endforeach; ?>
    </select>
  </label>

  <label class="fb-field" title="Executive Meeting duration — filter records by EM1 or EM2 period">
    <span class="fb-k">EM Duration</span>
    <select name="em" onchange="this.form.submit()">
      <option value="all" <?= $em === 'all' ? 'selected' : '' ?>>All</option>
      <?php foreach (array_keys(EM_MEETINGS) as $emKey): ?>
        <option value="<?= e($emKey) ?>" <?= $em === $emKey ? 'selected' : '' ?>>
          <?= e(em_filter_label($emKey, $academicYear)) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </label>

  <label class="fb-field">
    <span class="fb-k">Category</span>
    <select name="category" onchange="this.form.submit()">
      <option value="">All Student Categories</option>
      <option value="internship" <?= $category === 'internship' ? 'selected' : '' ?>>Internships</option>
      <option value="placement" <?= $category === 'placement' ? 'selected' : '' ?>>Placements</option>
      <option value="student_achievement" <?= $category === 'student_achievement' ? 'selected' : '' ?>>Student Achievements</option>
      <option value="student_participation" <?= $category === 'student_participation' ? 'selected' : '' ?>>Student Participations</option>
      <option value="summer_training" <?= $category === 'summer_training' ? 'selected' : '' ?>>Summer / Winter Training</option>
      <option value="nptel" <?= $category === 'nptel' ? 'selected' : '' ?>>SWAYAM-NPTEL (Students)</option>
      <option value="online_course" <?= $category === 'online_course' ? 'selected' : '' ?>>Online Courses (Students)</option>
    </select>
  </label>

  <span class="fbar-end">
    <a class="fbar-clear" href="<?= e(url('individual-student-report.php')) ?>?key=<?= urlencode($student['key']) ?>&reg_no=<?= urlencode($student['reg_no']) ?>&name=<?= urlencode($student['name']) ?>&dept=<?= urlencode($student['department']) ?>"><?= icon('x', 13) ?> Reset Filter</a>
  </span>
</form>

<!-- KPI Summary & Distribution Chart -->
<div class="mt-4" style="display:grid; grid-template-columns: 1fr 1fr; gap:20px;">
  <!-- Summary Table -->
  <div class="card">
    <div class="card-head">
      <div>
        <div class="card-title"><?= icon('award', 16) ?> Achievement Summary</div>
        <div class="card-sub">Category submission breakdown for <?= e($academicYear ?: 'all years') ?></div>
      </div>
    </div>
    <div class="card-body pt-0">
      <?php if (empty($catSummary)): ?>
        <div class="empty">
          <div class="ic"><?= icon('award', 20) ?></div>
          <p>No achievements recorded for this academic year</p>
        </div>
      <?php else: ?>
        <table class="data wide" style="margin-top:10px;">
          <thead>
            <tr>
              <th>Achievement Category</th>
              <th class="num">Submissions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($catSummary as $catName => $cnt): ?>
              <tr>
                <td class="fw-500"><?= e($catName) ?></td>
                <td class="num tabular fw-600"><?= (int) $cnt ?></td>
              </tr>
            <?php endforeach; ?>
            <tr style="background:var(--navy-50,#F4F6FA);">
              <td class="fw-700">Total Achievements</td>
              <td class="num tabular fw-700" style="color:var(--brand,#FF4F01); font-size:15px;"><?= array_sum($catSummary) ?></td>
            </tr>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </div>

  <!-- Distribution Chart -->
  <div class="card">
    <div class="card-head">
      <div>
        <div class="card-title"><?= icon('pie', 16) ?> Achievement Distribution</div>
        <div class="card-sub">Visual distribution across student categories</div>
      </div>
    </div>
    <div class="card-body">
      <?php if (empty($catSummary)): ?>
        <div class="empty">
          <p>No data to chart for the selected filters</p>
        </div>
      <?php else: ?>
        <div style="height:220px; position:relative;">
          <canvas id="studentDistChart"></canvas>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- Detailed Records Category-Wise -->
<div class="mt-5 card">
  <div class="card-head">
    <div>
      <div class="card-title">Category-Wise Detailed Achievements</div>
      <div class="card-sub"><?= count($records) ?> total verified student achievement records</div>
    </div>
  </div>

  <div class="card-body pt-0">
    <?php if (empty($byCat)): ?>
      <div class="empty">
        <div class="ic"><?= icon('reports', 20) ?></div>
        <p>No achievements found</p>
        <div class="note">No student achievement records have been submitted for the selected academic year.</div>
      </div>
    <?php else: ?>
      <?php foreach ($byCat as $catLabel => $items): ?>
        <div style="margin-top:24px; margin-bottom:12px; display:flex; align-items:center; justify-content:space-between; border-bottom:2px solid var(--hairline,#E4E9F2); padding-bottom:8px;">
          <div style="font-size:15px; font-weight:700; color:var(--ink,#131D3B); display:flex; align-items:center; gap:8px;">
            <?= icon('file-text', 15) ?> <?= e($catLabel) ?>
            <span class="badge badge-neutral"><?= count($items) ?></span>
          </div>
        </div>

        <div class="table-wrap mb-4">
          <table class="data wide">
            <thead>
              <tr>
                <th style="width:40px;">#</th>
                <th>Title / Description</th>
                <th>Category Details</th>
                <th>Department</th>
                <th>Status</th>
                <th>Academic Year</th>
                <th>Submission Date</th>
                <th style="width:100px;">Proof</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($items as $idx => $item): ?>
                <tr>
                  <td class="faint tabular"><?= $idx + 1 ?></td>
                  <td class="fw-500" style="max-width:350px;">
                    <div style="color:var(--ink,#131D3B); font-weight:600;"><?= e($item['title']) ?></div>
                  </td>
                  <td class="faint" style="font-size:12.5px;">
                    <?php if (!empty($item['raw']['company_name'])): ?>
                      <div>Company: <strong><?= e($item['raw']['company_name']) ?></strong></div>
                    <?php endif; ?>
                    <?php if (!empty($item['raw']['event_name'])): ?>
                      <div>Event: <strong><?= e($item['raw']['event_name']) ?></strong></div>
                    <?php endif; ?>
                    <?php if (!empty($item['raw']['award_name'])): ?>
                      <div>Award: <strong><?= e($item['raw']['award_name']) ?></strong></div>
                    <?php endif; ?>
                    <?php if (!empty($item['raw']['organization'])): ?>
                      <div>Org: <strong><?= e($item['raw']['organization']) ?></strong></div>
                    <?php endif; ?>
                    <?php if (!empty($item['raw']['portal'])): ?>
                      <div>Portal: <strong><?= e($item['raw']['portal']) ?></strong></div>
                    <?php endif; ?>
                    <?php if (!empty($item['raw']['stipend'])): ?>
                      <div>Stipend: <?= e($item['raw']['stipend']) ?></div>
                    <?php endif; ?>
                    <?php if (!empty($item['raw']['package'])): ?>
                      <div>Package: <?= e($item['raw']['package']) ?> LPA</div>
                    <?php endif; ?>
                  </td>
                  <td class="faint"><?= e($student['department']) ?></td>
                  <td>
                    <?php
                      $st = $item['status'] ?? 'Submitted';
                      $stClass = (strcasecmp($st, 'Approved') === 0) ? 'success' : ((strcasecmp($st, 'Rejected') === 0) ? 'danger' : 'neutral');
                    ?>
                    <span class="badge badge-<?= $stClass ?>"><?= e($st) ?></span>
                  </td>
                  <td class="card-sub"><?= e($item['raw']['academic_year'] ?? $academicYear) ?></td>
                  <td class="card-sub"><?= !empty($item['date']) && $item['date'] !== '—' ? date('d/m/Y', strtotime($item['date'])) : '—' ?></td>
                  <td class="c">
                    <?php
                      $pfile = trim((string)($item['proof_file'] ?? ''));
                      $pType = $item['type_key'] ?? '';
                      $pId   = (int)($item['id'] ?? 0);
                      $meta  = ($pfile !== '') ? record_proof_meta($pType, $pId, $pfile) : null;
                    ?>
                    <?php if ($meta): ?>
                      <a class="btn btn-ghost btn-sm" href="<?= e($meta['view_url']) ?>" target="_blank" rel="noopener">
                        <?= icon('paperclip', 13) ?> View Proof
                      </a>
                    <?php elseif ($pfile !== ''): ?>
                      <a class="btn btn-ghost btn-sm" href="<?= e(url('uploads/' . $pfile)) ?>" target="_blank" rel="noopener">
                        <?= icon('paperclip', 13) ?> View Proof
                      </a>
                    <?php else: ?>
                      <span class="faint">—</span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
<script src="<?= e(url('assets/js/charts.js')) ?>"></script>
<script>
(function () {
  const summary = <?= json_encode($summary) ?>;
  if (!summary || !Object.keys(summary).length) return;

  ATTS.charts.donut('studentDistChart', {
    labels:      Object.keys(summary),
    data:        Object.values(summary),
    centreLabel: 'records',
    empty:       'Nothing recorded yet'
  });
})();
</script>

<?php require __DIR__ . '/inc/footer.php'; ?>
