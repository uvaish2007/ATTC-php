<?php
/**
 * FEAT-06 — Executive Meeting Report (filters + preview + Present).
 *
 * The report-style front end for the Executive Meeting presentation: pick the
 * filters here, see what they select, then Present opens the same filtered
 * dataset as a full-screen deck.
 *
 * Open to every signed-in role, exactly like reports.php. What each role may
 * actually see is decided server-side by em_resolve_filters() and
 * report_records(), never by the dropdowns.
 */

require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/models/ExecutiveMeetingReport.php';

$user = require_login();

// Every filter is validated against this user's own scope.
$filters = em_resolve_filters($user, [
    'department'     => input('department'),
    'academic_year'  => input('academic_year'),
    'faculty_id'     => input('faculty_id'),
    'student_reg'    => input('student_reg'),
    'meeting_number' => input('meeting_number'),
    'em'             => input('em'),   // FEAT-07 EM1 / EM2 / All
]);

$dataset = em_dataset($user, $filters);
$slides  = em_slides($dataset);
$summary = $dataset['summary'];

// Options for the dropdowns, in the same scope the filters were resolved in.
$departments   = departments_all();
$years         = academic_years();
$facultyList   = em_faculty_options($user, $filters['department']);
$studentList   = em_student_options($user, $filters['department'], $filters['year']);
$canPickDept   = em_can_pick_department($user);
$presentUrl    = url('present-executive-meeting.php') . '?' . http_build_query(em_filter_query($filters));

$pageTitle  = 'Executive Meeting Report';
$breadcrumb = 'Executive Meeting Report';
require __DIR__ . '/inc/header.php';
?>

<div class="page-head">
  <div>
    <h1>Executive Meeting Report</h1>
    <div class="sub">
      Filter the meeting report, then present it full screen
      &middot; <?= e($summary['Department']) ?>
      &middot; Academic Year: <?= e($filters['year']) ?>
    </div>
  </div>

  <div class="actions flex gap-2 items-center" style="flex-wrap:wrap;">
    <a class="btn btn-secondary btn-sm" href="<?= e(url('reports.php')) ?>" title="Back to the Reports hub">
      <?= icon('arrow-left', 14) ?> Back to Reports
    </a>
  </div>
</div>

<!-- Filters -->
<form method="get" class="fbar mt-4" id="emFilterForm">
  <span class="fbar-title"><?= icon('filter', 14) ?> Report Filters</span>

  <!-- Department -->
  <?php if ($canPickDept): ?>
    <label class="fb-field">
      <span class="fb-k">Department</span>
      <select name="department">
        <option value="">All Departments</option>
        <?php foreach ($departments as $d): ?>
          <option value="<?= e($d['name']) ?>" <?= $filters['department'] === $d['name'] ? 'selected' : '' ?>>
            <?= e($d['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>
  <?php else: ?>
    <label class="fb-field" title="Your role reports on a fixed scope">
      <span class="fb-k">Department</span>
      <select disabled><option selected><?= e($summary['Department']) ?></option></select>
    </label>
  <?php endif; ?>

  <!-- Academic Year (FEAT-02 active year is the default) -->
  <label class="fb-field">
    <span class="fb-k">Academic Year</span>
    <select name="academic_year">
      <?php foreach ($years as $y): ?>
        <option value="<?= e($y) ?>" <?= $filters['year'] === $y ? 'selected' : '' ?>>
          <?= e($y) ?><?= $y === $filters['active_year'] ? ' (Active)' : '' ?>
        </option>
      <?php endforeach; ?>
    </select>
  </label>

  <!-- Faculty -->
  <label class="fb-field">
    <span class="fb-k">Faculty</span>
    <select name="faculty_id">
      <option value="">All Faculty</option>
      <?php foreach ($facultyList as $fac): ?>
        <option value="<?= (int) $fac['id'] ?>" <?= $filters['faculty_id'] === (int) $fac['id'] ? 'selected' : '' ?>>
          <?= e($fac['name']) ?><?= !empty($fac['department']) ? ' (' . e($fac['department']) . ')' : '' ?>
        </option>
      <?php endforeach; ?>
    </select>
  </label>

  <!-- Student -->
  <label class="fb-field">
    <span class="fb-k">Student</span>
    <select name="student_reg">
      <option value="">All Students</option>
      <?php foreach ($studentList as $st): ?>
        <option value="<?= e($st['reg_no']) ?>" <?= $filters['student_reg'] === $st['reg_no'] ? 'selected' : '' ?>>
          <?= e($st['student_name']) ?> (<?= e($st['reg_no']) ?>)
        </option>
      <?php endforeach; ?>
    </select>
  </label>

  <!-- Executive Meeting period (FEAT-07): EM1 / EM2 from the configured schedule -->
  <label class="fb-field" title="Records submitted during EM1 or EM2, per the Executive Meeting schedule">
    <span class="fb-k">Executive Meeting</span>
    <select name="em">
      <option value="all" <?= $filters['em'] === 'all' ? 'selected' : '' ?>>All (EM1 &amp; EM2)</option>
      <?php foreach (EM_MEETINGS as $emKey => $emName): ?>
        <option value="<?= e($emKey) ?>" <?= $filters['em'] === $emKey ? 'selected' : '' ?>><?= e(em_filter_label($emKey, $filters['year'])) ?></option>
      <?php endforeach; ?>
    </select>
  </label>

  <!-- Recorded meeting: the meetings actually recorded for this year (FEAT-06) -->
  <label class="fb-field">
    <span class="fb-k">Recorded Meeting</span>
    <select name="meeting_number">
      <option value="">All Recorded Meetings</option>
      <?php foreach ($filters['meeting_options'] as $m): ?>
        <option value="<?= e($m['meeting_number']) ?>" <?= $filters['meeting_number'] === (string) $m['meeting_number'] ? 'selected' : '' ?>>
          Meeting #<?= e($m['meeting_number']) ?> &middot; <?= e(date('d M Y', strtotime((string) $m['meeting_date']))) ?>
        </option>
      <?php endforeach; ?>
    </select>
  </label>

  <span class="fbar-end">
    <button type="submit" class="btn btn-primary btn-sm"><?= icon('filter', 13) ?> Apply Filters</button>
    <a class="fbar-clear" href="<?= e(url('executive-meeting-report.php')) ?>"><?= icon('x', 13) ?> Reset</a>
  </span>
</form>

<?php if ($filters['em'] !== 'all' && !em_schedule_for_year($filters['year'])): ?>
  <?php // FEAT-07: an EM filter without a schedule selects nothing, never "everything". ?>
  <div class="alert alert-info mt-4">
    <?= $filters['is_active_year']
          ? e(EM_NOT_CONFIGURED_MESSAGE)
          : 'Executive Meeting schedule is not configured for ' . e($filters['year']) . '.' ?>
    No records can be matched to <?= e(EM_MEETINGS[$filters['em']]) ?> until it is.
  </div>
<?php elseif ($filters['em'] !== 'all' && !empty($dataset['rollup']['unlinked'])): ?>
  <div class="alert alert-info mt-4">
    Target vs Achieved counts only what was achieved during <?= e(EM_MEETINGS[$filters['em']]) ?>.
    <?= (int) $dataset['rollup']['unlinked'] ?> target<?= $dataset['rollup']['unlinked'] === 1 ? ' has' : 's have' ?>
    no dated records to count (for example pass percentage or CGPA) and <?= $dataset['rollup']['unlinked'] === 1 ? 'is' : 'are' ?>
    shown as not linked.
  </div>
<?php endif; ?>

<?php if (empty($filters['meetings'])): ?>
  <div class="alert alert-info mt-4">
    No Executive Meetings have been recorded for <?= e($filters['year']) ?> yet, so the meeting filter offers
    the whole year. The report still presents every other section.
  </div>
<?php endif; ?>

<!-- What the applied filters select -->
<div class="card mt-4" style="border-left:4px solid var(--brand,#FF4F01);">
  <div class="card-head">
    <div>
      <div class="card-title"><?= icon('presentation', 16) ?> Presentation Ready</div>
      <div class="card-sub">
        <?= count($slides) ?> slide<?= count($slides) === 1 ? '' : 's' ?> built from
        <?= (int) $dataset['total'] ?> record<?= $dataset['total'] === 1 ? '' : 's' ?>
        and <?= (int) $dataset['rollup']['count'] ?> target<?= $dataset['rollup']['count'] === 1 ? '' : 's' ?>
        in this scope
      </div>
    </div>
    <a class="btn btn-primary em-present-btn" href="<?= e($presentUrl) ?>" title="Open the filtered report as a full-screen presentation">
      <?= icon('play-circle', 17) ?> PRESENT
    </a>
  </div>

  <div class="card-body pt-0">
    <!-- Applied filter summary -->
    <div class="em-chips">
      <?php foreach ($summary as $label => $value): ?>
        <span class="em-chip"><span class="em-chip-k"><?= e($label) ?></span><?= e($value) ?></span>
      <?php endforeach; ?>
    </div>

    <!-- Counts feeding the deck -->
    <div class="stat-grid grid-4 mt-4">
      <div class="stat">
        <div class="stat-top"><div class="stat-label">Faculty Achievements</div><div class="stat-ic brand"><?= icon('award') ?></div></div>
        <div class="stat-value tabular"><?= count($dataset['faculty']) ?></div>
        <div class="stat-desc">Records in scope</div>
      </div>
      <div class="stat">
        <div class="stat-top"><div class="stat-label">Student Records</div><div class="stat-ic navy"><?= icon('users') ?></div></div>
        <div class="stat-value tabular"><?= count($dataset['student']) ?></div>
        <div class="stat-desc">Achievements &amp; placements</div>
      </div>
      <div class="stat">
        <div class="stat-top"><div class="stat-label">Activities</div><div class="stat-ic navy"><?= icon('calendar') ?></div></div>
        <div class="stat-value tabular"><?= count($dataset['activity']) ?></div>
        <div class="stat-desc">Events &amp; outreach</div>
      </div>
      <div class="stat">
        <div class="stat-top"><div class="stat-label">Target Achieved</div><div class="stat-ic brand"><?= icon('target') ?></div></div>
        <div class="stat-value tabular">
          <?= $dataset['rollup']['percentage'] !== null ? e($dataset['rollup']['percentage']) . '%' : '—' ?>
        </div>
        <div class="stat-desc">
          <?= (int) $dataset['rollup']['achieved'] ?> of <?= (int) $dataset['rollup']['target'] ?>
        </div>
      </div>
    </div>

    <!-- Slide running order -->
    <div class="rh-label mt-4" style="font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:var(--ink-muted,#64748b);">
      Slide Running Order
    </div>
    <div class="table-wrap mt-2">
      <table class="data wide">
        <thead>
          <tr><th style="width:70px;">Slide</th><th>Section</th><th style="width:220px;">Contents</th></tr>
        </thead>
        <tbody>
          <?php foreach ($slides as $i => $s): ?>
            <tr>
              <td class="faint tabular"><?= $i + 1 ?></td>
              <td class="fw-500"><?= e($s['title']) ?></td>
              <td class="card-sub">
                <?php if ($s['type'] === 'empty'): ?>
                  <span class="badge badge-neutral">No data</span>
                <?php elseif (!empty($s['rows'])): ?>
                  <?= count($s['rows']) ?> row<?= count($s['rows']) === 1 ? '' : 's' ?>
                  <?php if (!empty($s['total_pages']) && $s['total_pages'] > 1): ?>
                    &middot; page <?= (int) $s['page'] ?> of <?= (int) $s['total_pages'] ?>
                  <?php endif; ?>
                <?php else: ?>
                  Overview
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<style>
  .em-present-btn { display:inline-flex; align-items:center; gap:8px; font-weight:700; letter-spacing:.03em;
      border-radius:999px; padding:0 22px; white-space:nowrap; flex-shrink:0; }
  .em-chips { display:flex; flex-wrap:wrap; gap:8px; margin-top:14px; }
  .em-chip { display:inline-flex; align-items:center; gap:6px; background:var(--navy-50,#F4F6FA);
      border:1px solid var(--hairline,#E4E9F2); border-radius:999px; padding:5px 12px; font-size:12.5px;
      font-weight:600; color:var(--ink,#131D3B); }
  .em-chip-k { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.03em;
      color:var(--ink-muted,#64748b); }
  #emFilterForm .fb-field select { min-width:150px; }
</style>

<?php require __DIR__ . '/inc/footer.php'; ?>
