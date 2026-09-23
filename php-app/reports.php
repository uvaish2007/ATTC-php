<?php
/**
 * Reports — the hub for every downloadable report, role-aware.
 *
 *   Admin     sets the report template all departments follow, and may filter
 *             by department, year, period and type across every report.
 *   HoD       generates their own department's reports (proforma + metrics),
 *             filterable by year, period and type.
 *   Director  sees the whole institution only — the full academic report,
 *             view and export, with no narrowing.
 *   Faculty/Coordinator keep the plain records list (their own scope).
 *
 * Three reports feed off the same filters:
 *   Executive Meeting Report  meeting-report.php   (Fixed vs Achieved proforma)
 *   Academic Records Report   export.php           (every uploaded record)
 *   Metrics Report            metrics-report.php   (per-metric counts)
 */

require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/record_specs.php';
require_once __DIR__ . '/inc/report_layout.php';
require_once __DIR__ . '/models/Record.php';
require_once __DIR__ . '/models/Department.php';
require_once __DIR__ . '/models/Target.php';
require_once __DIR__ . '/models/Setting.php';
require_once __DIR__ . '/models/FacultyAchievement.php';
require_once __DIR__ . '/models/ExecutiveMeeting.php';   // FEAT-07 EM filter

$user = require_login();
require_module('reports');

$role       = $user['role'];
$isAdmin    = $role === 'Admin';
$isHod      = $role === 'HoD';
$isDirector = in_array($role, ['Director', 'Principal'], true);
$isDean     = $role === 'Dean';
$isOversight = $isAdmin || $isDirector || $isDean;
$canFilter  = $isAdmin || $isHod || $isDean;     // Director never narrows; Dean/Admin/HoD filter

// ---- Admin sets the template every department must use --------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isAdmin) {
    csrf_check();
    if ((string) input('action') === 'set_template') {
        $tpl = (string) input('report_template');
        if (array_key_exists($tpl, report_templates())) {
            setting_set('report_template', $tpl, (int) $user['id']);
            flash('success', 'Report format updated — every department now uses it.');
        }
    }
    redirect('/reports.php');
}

// ---- Filters -------------------------------------------------------------
// Director cannot narrow anything; a HoD/Coordinator/Faculty cannot choose a department;
// Oversight roles can choose a department.
$department = user_department_scope($user, input('department'));
$status     = !$isDirector ? (trim((string) input('status')) ?: null) : null;
$type       = !$isDirector ? (trim((string) input('type'))   ?: null) : null;
// Category narrows to one of the three groups the dashboard charts — the
// "View reports" buttons on the dashboard's Records by Category card link here.
$categories = record_categories();
$category   = !$isDirector ? (trim((string) input('category')) ?: null) : null;
if ($category !== null && !isset($categories[$category])) {
    $category = null;
}
// EM-SPEC-03: Centralized Academic Year and EM Duration filter resolution.
$rawYear     = input('academic_year') ?: input('year');
$emCtx       = em_resolve_filter_context($rawYear, input('em'));
$year        = $emCtx['year'];
$em          = $emCtx['em'];
$rawFrom     = $canFilter   ? trim((string) input('from'))    : '';
$rawTo       = $canFilter   ? trim((string) input('to'))      : '';

$fromIso     = parse_date_input($rawFrom);
$toIso       = parse_date_input($rawTo);
$fromDisplay = format_date_display($rawFrom);
$toDisplay   = format_date_display($rawTo);
$from        = $fromDisplay;
$to          = $toDisplay;

$rangeError = null;
if ($fromIso && $toIso && $fromIso > $toIso) {
    $rangeError = 'From Date cannot be later than To Date.';
}

[$recFrom, $recTo] = em_intersect_period($em, $rangeError ? null : $fromIso, $rangeError ? null : $toIso, $year);

$records = report_records($user, $department, $status, $type, $recFrom, $recTo, $year);

// A category keeps only the types in that group; a chosen type still wins,
// so picking both never shows a record outside the type.
if ($category !== null) {
    $catTypes = record_category_types($category);
    $records  = array_values(array_filter($records, fn($r) => in_array($r['_type_key'], $catTypes, true)));
}

$types       = record_types();
$departments = departments_all();
$years       = academic_years();
$template    = active_report_template();

$counts = ['Draft' => 0, 'HOD Pending' => 0, 'Dean Pending' => 0, 'Submitted' => 0, 'Approved' => 0, 'Rejected' => 0];
foreach ($records as $r) {
    if (isset($counts[$r['status']])) {
        $counts[$r['status']]++;
    }
}
$total = count($records);

// The scope label reflects what the role can actually see.
if ($isHod) {
    $scopeName = $user['department'] ?: 'All departments';
} else {
    $scopeName = $department ?: 'All departments';
}

// ---- Query strings each report link carries ------------------------------
$emQ      = $em !== 'all' ? $em : null;   // carried so every download matches the screen
$recordsQ = array_filter([
    'department'    => $department,
    'academic_year' => $year,
    'status'        => $status,
    'type'          => $type,
    'category'      => $category,
    'from'          => $fromDisplay,
    'to'            => $toDisplay,
    'em'            => $emQ,
]);
$meetingQ = array_filter(['department' => $department, 'academic_year' => $year, 'year' => $year, 'em' => $emQ]);
$metricsQ = array_filter(['department' => $department, 'academic_year' => $year, 'year' => $year, 'from' => $fromDisplay, 'to' => $toDisplay, 'em' => $emQ]);

// EM-SPEC-04: Carry active Academic Year, EM Duration, and Department to the Executive Meeting Report.
$emReportParams = array_filter([
    'academic_year' => $year,
    'em'            => ($em !== 'all' ? $em : null),
    'department'    => $department,
], fn($v) => $v !== null && $v !== '');
$emReportUrl = url('executive-meeting-report.php') . ($emReportParams ? '?' . http_build_query($emReportParams) : '');


$link = fn(string $file, array $q, string $fmt) =>
    e(url($file) . '?' . http_build_query($q + ['format' => $fmt]));

$visible = array_slice($records, 0, 50);

$pageTitle  = 'Reports';
$breadcrumb = 'Reports';
require __DIR__ . '/inc/header.php';
?>

<?php if (!empty($rangeError)): ?>
  <div class="alert alert-error" style="background:#fee2e2; border:1px solid #f87171; color:#991b1b; padding:12px 16px; border-radius:8px; display:flex; align-items:center; gap:10px; margin-bottom:16px;">
    <span><strong>Invalid Date Range:</strong> <?= e($rangeError) ?></span>
  </div>
<?php endif; ?>

<?php // $year isn't counted — it's always the active system year, not a chosen filter.
  $activeCount = count(array_filter([$department, $type, $status, $category])) + (($fromDisplay || $toDisplay) ? 1 : 0) + ($em !== 'all' ? 1 : 0); ?>
<div class="page-head">
  <div>
    <h1><?= $category ? e($categories[$category]['label']) : 'Reports' ?></h1>
    <div class="sub">
      <?= (int) $total ?> records &middot; <?= e($scopeName) ?>
      <?php if ($category): ?>&middot; <a href="<?= e(url('reports.php')) ?>">All categories</a><?php endif; ?>
    </div>
  </div>

  <?php // EM-SPEC-04: the Executive Meeting Report is reached from the page
        // header, above the filter bar — not from a control inside it. ?>
  <div class="actions">
    <a id="header_em_report_btn" class="btn btn-primary btn-sm" href="<?= e($emReportUrl) ?>"
       title="Filter and present the Executive Meeting Report">
      <?= icon('presentation', 15) ?> Executive Meeting Report
    </a>
  </div>
</div>


<?php if ($isAdmin): ?>
  <!-- Admin: the format every department's report follows -->
  <div class="card">
    <div class="card-head">
      <div>
        <div class="card-title">Report format</div>
        <div class="card-sub">The template every department's report is generated in</div>
      </div>
      <a class="btn btn-secondary btn-sm" href="<?= e(url('report-template.php')) ?>"><?= icon('settings') ?> Design template</a>
    </div>
    <div class="card-body">
      <p class="card-sub" style="margin:0 0 12px">
        Build your own columns and rows in the <strong>template designer</strong> — that becomes the
        standard report. Or pick a ready-made layout for the target proforma below.
      </p>
      <form method="post" class="flex gap-2 items-center" style="flex-wrap:wrap">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="set_template">
        <select class="select" name="report_template" style="min-width:420px">
          <?php foreach (report_templates() as $key => $label): ?>
            <option value="<?= e($key) ?>" <?= $template === $key ? 'selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
        <button class="btn btn-primary btn-sm" type="submit"><?= icon('save') ?> Apply to all departments</button>
      </form>
    </div>
  </div>
<?php endif; ?>


<?php if (!$isDirector): ?>
  <!-- One filter bar for everything below it: the counters, the report hub
       and every download follow these filters. A pill only appears for a role
       whose filter the server actually applies (see "Filters" at the top). -->
  <form method="get" class="fbar mt-5" onsubmit="return validatePeriodRange()">
    <span class="fbar-title"><?= icon('filter', 14) ?> Filters</span>

    <?php if (user_can_choose_department($user)): ?>
      <label class="fb-field"><span class="fb-k">Department</span>
        <select name="department" onchange="this.form.submit()">
          <option value="">All</option>
          <?php foreach ($departments as $d): ?>
            <option value="<?= e($d['name']) ?>" <?= $department === $d['name'] ? 'selected' : '' ?>><?= e($d['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
    <?php endif; ?>

    <label class="fb-field"><span class="fb-k">Category</span>
      <select name="category" onchange="this.form.submit()">
        <option value="">All</option>
        <?php foreach ($categories as $ckey => $cat): ?>
          <option value="<?= e($ckey) ?>" <?= $category === $ckey ? 'selected' : '' ?>><?= e($cat['label']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>

    <label class="fb-field"><span class="fb-k">Type</span>
      <select name="type" onchange="this.form.submit()">
        <option value="">All</option>
        <?php foreach ($types as $key => $t): ?>
          <option value="<?= e($key) ?>" <?= $type === $key ? 'selected' : '' ?>><?= e($t['label']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>

    <label class="fb-field"><span class="fb-k">Status</span>
      <select name="status" onchange="this.form.submit()">
        <option value="">All</option>
        <?php foreach (['Approved', 'Dean Pending', 'HOD Pending', 'Submitted', 'Draft', 'Rejected'] as $o): ?>
          <option value="<?= $o ?>" <?= $status === $o ? 'selected' : '' ?>><?= $o ?></option>
        <?php endforeach; ?>
      </select>
    </label>

    <label class="fb-field" title="Filter by Academic Year">
      <?= icon('calendar', 14) ?><span class="fb-k">Academic Year</span>
      <select name="academic_year" onchange="this.form.submit()">
        <?php foreach ($years as $y): ?>
          <option value="<?= e($y) ?>" <?= $year === $y ? 'selected' : '' ?>>
            <?= e($y) ?><?= $y === active_academic_year() ? ' (Active)' : '' ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>

    <?php // EM-SPEC-03: EM Duration filter ?>
    <label class="fb-field" title="Executive Meeting duration — filter records by EM1 or EM2 period">
      <span class="fb-k">EM Duration</span>
      <select name="em" onchange="this.form.submit()">
        <option value="all" <?= $em === 'all' ? 'selected' : '' ?>>All</option>
        <?php foreach (EM_MEETINGS as $emKey => $emName): ?>
          <option value="<?= e($emKey) ?>" <?= $em === $emKey ? 'selected' : '' ?>><?= e(em_filter_label($emKey, $year)) ?></option>
        <?php endforeach; ?>
      </select>
    </label>

    <?php if ($canFilter): ?>
      <div class="fb-field fb-range" title="Submission period, as DD-MM-YYYY">
        <?= icon('calendar', 14) ?><span class="fb-k">Period</span>
        <span class="fb-date date-picker-wrap">
          <input type="text" name="from" id="from_date_input" placeholder="dd-mm-yyyy"
                 value="<?= e($fromDisplay) ?>" maxlength="10" autocomplete="off"
                 aria-label="From date, DD-MM-YYYY" onchange="periodChanged()">
          <button type="button" class="date-picker-btn" onclick="openCustomCalendar('from_date_input', event)"
                  title="Pick the start date" aria-label="Pick the start date"><?= icon('calendar', 13) ?></button>
        </span>
        <span class="fb-to">→</span>
        <span class="fb-date date-picker-wrap">
          <input type="text" name="to" id="to_date_input" placeholder="dd-mm-yyyy"
                 value="<?= e($toDisplay) ?>" maxlength="10" autocomplete="off"
                 aria-label="To date, DD-MM-YYYY" onchange="periodChanged()">
          <button type="button" class="date-picker-btn" onclick="openCustomCalendar('to_date_input', event)"
                  title="Pick the end date" aria-label="Pick the end date"><?= icon('calendar', 13) ?></button>
        </span>
      </div>
    <?php endif; ?>

    <span class="fbar-end">
      <?php if ($activeCount): ?>
        <span class="fbar-count"><?= (int) $activeCount ?> active</span>
        <a class="fbar-clear" href="<?= e(url('reports.php')) ?>"><?= icon('x', 13) ?> Clear all</a>
      <?php else: ?>
        <span class="fbar-note">Showing everything in scope</span>
      <?php endif; ?>
    </span>
    <p class="fbar-err" id="period_range_error"><?= !empty($rangeError) ? e($rangeError) : '' ?></p>
  </form>
<?php else: ?>
  <?php // FEAT-07: Director/Principal keep no scope filters, but may view a
        // single Executive Meeting — that narrows, it never widens. ?>
  <form method="get" class="fbar mt-5">
    <span class="fbar-title"><?= icon('filter', 14) ?> Filters</span>
    <label class="fb-field" title="Filter by Academic Year">
      <?= icon('calendar', 14) ?><span class="fb-k">Academic Year</span>
      <select name="academic_year" onchange="this.form.submit()">
        <?php foreach ($years as $y): ?>
          <option value="<?= e($y) ?>" <?= $year === $y ? 'selected' : '' ?>>
            <?= e($y) ?><?= $y === active_academic_year() ? ' (Active)' : '' ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>
    <label class="fb-field" title="Executive Meeting duration — filter records by EM1 or EM2 period">
      <span class="fb-k">EM Duration</span>
      <select name="em" onchange="this.form.submit()">
        <option value="all" <?= $em === 'all' ? 'selected' : '' ?>>All</option>
        <?php foreach (EM_MEETINGS as $emKey => $emName): ?>
          <option value="<?= e($emKey) ?>" <?= $em === $emKey ? 'selected' : '' ?>><?= e(em_filter_label($emKey, $year)) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <span class="fbar-end">
      <?php if ($em !== 'all'): ?>
        <a class="fbar-clear" href="<?= e(url('reports.php')) ?>"><?= icon('x', 13) ?> Clear</a>
      <?php else: ?>
        <span class="fbar-note">Showing everything in scope</span>
      <?php endif; ?>
    </span>
  </form>
<?php endif; ?>


<!-- Counters -->
<div class="mt-5 stat-grid grid-4">
  <?php
    $statCards = [
        ['Total',    $total,               'layers', 'brand'],
        ['Approved', $counts['Approved'],  'check',  'navy'],
        ['Pending',  $counts['Submitted'], 'clock',  $counts['Submitted'] ? 'brand' : 'navy'],
        ['Rejected', $counts['Rejected'],  'x',      'navy'],
    ];
  ?>
  <?php foreach ($statCards as [$label, $value, $iconName, $tone]): ?>
    <div class="stat">
      <div class="stat-top">
        <div class="stat-label"><?= e($label) ?></div>
        <div class="stat-ic <?= $tone ?>"><?= icon($iconName) ?></div>
      </div>
      <div class="stat-value tabular"><?= (int) $value ?></div>
    </div>
  <?php endforeach; ?>
</div>


<!-- ==========================================================================
     Report Hub — one place to download every report, from live data
     ======================================================================= -->
<?php
  // The per-metric template reports, narrowed by the "type" filter.
  $allSpecs   = record_report_specs();
  if ($type !== null && isset($allSpecs[$type])) {
      $shownSpecs = [$type => $allSpecs[$type]];
  } elseif ($category !== null) {
      $shownSpecs = array_intersect_key($allSpecs, array_flip(record_category_types($category)));
  } else {
      $shownSpecs = $allSpecs;
  }
  $reportScopeQ = array_filter([
      'department' => $department, 'year' => $year, 'status' => $status,
      'from' => $fromIso, 'to' => $toIso, 'em' => $emQ,
  ]);

  // Live figures: records in scope per type (dept/status/period + year), so
  // every row shows how many records the download will contain. $records is
  // already scoped to the active year at the database level (report_records()).
  $scoped = $records;
  $typeCounts = [];
  foreach ($scoped as $r) { $typeCounts[$r['_type_key']] = ($typeCounts[$r['_type_key']] ?? 0) + 1; }
  $totalScoped = count($scoped);

  // The target proforma + metrics summary scope to the effective department.
  $effDept = $department;
  $tStmt   = db()->prepare('SELECT COUNT(*) FROM targets' . ($effDept ? ' WHERE department = ?' : ''));
  $tStmt->execute($effDept ? [$effDept] : []);
  $targetCount = (int) $tStmt->fetchColumn();

  $canSummary = $role !== 'Coordinator' && $role !== 'Faculty';   // meeting + metrics
  $meetingQ2  = array_filter(['department' => $department, 'year' => $year]);

  // Active-filter chips under the heading.
  $activeBits = array_filter([
      $department ? 'Dept: ' . $department : null,
      $category   ? 'Category: ' . $categories[$category]['label'] : null,
      $year       ? 'Year: ' . $year       : null,
      $status     ? 'Status: ' . $status   : null,
      ($fromIso || $toIso) ? 'Period set' : null,
      $em !== 'all' ? 'Meeting: ' . em_filter_label($em, $year) : null,
  ]);

  $canConsolidated = in_array($role, ['Admin', 'Dean', 'Principal', 'Director'], true);
  $canFacultyAchievementsBox = in_array($role, ['Admin', 'Dean', 'HoD', 'Principal', 'Director'], true);
?>

<?php if ($canConsolidated): ?>
  <!-- ========================================================================
       CONSOLIDATED REPORT (FEAT-03)
       ===================================================================== -->
  <div class="mt-5 card">
    <div class="card-head">
      <div>
        <div class="card-title" style="display:flex; align-items:center; gap:8px;">
          <?= icon('reports', 18) ?> Consolidated Report
        </div>
        <div class="card-sub">
          College-wide consolidated institutional report &middot; All departments grouped department-wise &middot; Academic Year: <?= e($year) ?>
        </div>
      </div>
    </div>
    <div class="card-body">
      <div class="hero-card-row">
        <div style="max-width:550px;">
          <div style="font-weight:600; font-size:14px; color:var(--ink,#131D3B);">Consolidated All-Department Academic Report</div>
          <div class="card-sub" style="margin-top:2px;">
            Single consolidated report retrieving data from all college departments, grouped department-wise with institutional summaries and target achievements.
          </div>
        </div>
        <div class="hero-card-actions">
          <a class="btn btn-primary btn-sm" href="<?= e(url('consolidated-report.php?format=excel')) ?>">
            <?= icon('download') ?> Download Excel
          </a>
          <a class="btn btn-outline btn-sm" href="<?= e(url('consolidated-report.php?format=pdf')) ?>" target="_blank" rel="noopener">
            <?= icon('file-text', 14) ?> Download PDF
          </a>
          <a class="btn btn-outline btn-sm" href="<?= e(url('consolidated-report.php?format=word')) ?>">
            <?= icon('file-text', 14) ?> Download Word
          </a>
        </div>
      </div>
    </div>
  </div>
<?php endif; ?>

<?php if ($canFacultyAchievementsBox): ?>
  <?php
    // FEAT-04: Department & Faculty Achievements Report data
    // HoD/Coordinator/Faculty is strictly locked server-side to their own assigned department.
    // Admin, Principal, Director, Dean can view all departments or filter by the selected department.
    $effDeptForFaculty = $department;
    $feat4FacultyGrid  = faculty_achievements_grid($user, $effDeptForFaculty, $year, null, null, null, em_filter_window($em, $year));   // FEAT-07 Meeting filter

    // Group faculty members under their respective departments
    $feat4Grouped = [];
    foreach ($feat4FacultyGrid as $f) {
        $deptName = !empty($f['department']) && $f['department'] !== '—' ? $f['department'] : 'Other Department';
        $feat4Grouped[$deptName][] = $f;
    }

    // Determine the list of departments to display
    if ($effDeptForFaculty) {
        $feat4Depts = [$effDeptForFaculty];
    } else {
        // Collect all official departments
        $feat4Depts = [];
        foreach ($departments as $d) {
            $feat4Depts[] = $d['name'];
        }
        // In case any faculty member belongs to a department not in $departments list
        foreach (array_keys($feat4Grouped) as $dName) {
            if (!in_array($dName, $feat4Depts, true)) {
                $feat4Depts[] = $dName;
            }
        }
    }

    // Prioritize departments with faculty so they appear first when viewed
    $activeDepts = [];
    $emptyDepts  = [];
    foreach ($feat4Depts as $dName) {
        if (!empty($feat4Grouped[$dName])) {
            $activeDepts[] = $dName;
        } else {
            $emptyDepts[] = $dName;
        }
    }
    sort($activeDepts);
    sort($emptyDepts);
    $feat4DeptsOrdered = array_merge($activeDepts, $emptyDepts);

    // Summary counts for the card badge
    $feat4TotalFaculty = count($feat4FacultyGrid);
    $feat4TotalAchievements = array_sum(array_column($feat4FacultyGrid, 'total'));
  ?>
  <!-- ========================================================================
       DEPARTMENT & FACULTY ACHIEVEMENTS REPORT (FEAT-04)
       ===================================================================== -->
  <div class="mt-5 card" style="border-left: 4px solid var(--brand, #FF4F01);">
    <div class="card-head">
      <div>
        <div class="card-title" style="display:flex; align-items:center; gap:8px;">
          <?= icon('award', 18) ?> Department &amp; Faculty Achievements Report
        </div>
        <div class="card-sub">
          Detailed staff achievement listing grouped department-wise &middot; Academic Year: <?= e($year) ?>
          <?php if ($isHod): ?>
            &middot; <strong style="color:var(--brand,#FF4F01);"><?= e(department_full_name($user['department'])) ?> only</strong>
          <?php endif; ?>
        </div>
      </div>
      <div class="hero-card-actions hero-card-actions--pill">
        <span class="badge badge-neutral" style="font-size:12px; font-weight:600;">
          <?= $feat4TotalFaculty ?> Faculty &middot; <?= $feat4TotalAchievements ?> Achievement<?= $feat4TotalAchievements === 1 ? '' : 's' ?>
        </span>
        <button type="button" class="btn btn-primary btn-sm" id="btnToggleFacultyReportHdr" onclick="toggleFacultyAchievementsReport()">
          <?= icon('eye', 14) ?> <span id="toggleFacultyReportHdrTxt">View Report</span>
        </button>
        <a href="<?= e(url('faculty-achievements.php')) ?>" class="btn btn-secondary btn-sm">
          <?= icon('award', 14) ?> Performance Matrix
        </a>
      </div>
    </div>
    <div class="card-body">
      <div class="hero-card-row" style="padding-bottom:16px; border-bottom:1px solid var(--hairline,#E4E9F2);">
        <div style="max-width:550px;">
          <div style="font-weight:600; font-size:14px; color:var(--ink,#131D3B);">
            <?= $isHod ? (e(department_full_name($user['department'])) . ' Faculty Achievements') : 'College-Wide Faculty Achievements' ?>
          </div>
          <div class="card-sub" style="margin-top:2px;">
            Individual faculty member visibility. Click <strong>View Report</strong> to view faculty achievement details grouped department-wise.
          </div>
        </div>
        <div class="hero-card-actions">
          <button type="button" class="btn btn-primary btn-sm" id="btnToggleFacultyReport" onclick="toggleFacultyAchievementsReport()" style="font-weight:600;">
            <?= icon('eye', 14) ?> <span id="toggleFacultyReportTxt">View Report</span> <span id="toggleFacultyReportIcon" style="display:inline-flex;"><?= icon('chevron-down', 13) ?></span>
          </button>
          <a class="btn btn-outline btn-sm" href="<?= e(url('export-faculty-achievements.php?format=excel' . ($emQ ? '&em=' . $emQ : ''))) ?>" title="Download Excel Spreadsheet">
            <?= icon('download', 14) ?> Download Excel
          </a>
          <a class="btn btn-outline btn-sm" href="<?= e(url('export-faculty-achievements.php?format=pdf' . ($emQ ? '&em=' . $emQ : ''))) ?>" target="_blank" rel="noopener" title="Download PDF Document">
            <?= icon('file-text', 14) ?> Download PDF
          </a>
          <a class="btn btn-outline btn-sm" href="<?= e(url('export-faculty-achievements.php?format=word' . ($emQ ? '&em=' . $emQ : ''))) ?>" title="Download Word Document">
            <?= icon('file-text', 14) ?> Download Word
          </a>
        </div>
      </div>

      <!-- Department-wise Grouped Faculty Listing (Toggleable via View Report button) -->
      <div id="feat4FacultyReportContainer" style="display:none; margin-top:20px;">
        <?php if (empty($feat4DeptsOrdered)): ?>
          <div class="empty" style="padding:24px 0;">
            <div class="ic"><?= icon('building', 20) ?></div>
            <p>No departments found in scope</p>
          </div>
        <?php else: ?>
          <div class="feat4-dept-list">
            <?php foreach ($feat4DeptsOrdered as $deptName): ?>
              <?php
                $facultyList = $feat4Grouped[$deptName] ?? [];
                $deptAchievementTotal = array_sum(array_column($facultyList, 'total'));
              ?>
              <div class="feat4-dept-group" style="margin-bottom:24px;">
                <div style="background:var(--navy-50,#F4F6FA); border:1px solid var(--hairline,#E4E9F2); border-radius:10px; padding:10px 16px; display:flex; align-items:center; justify-content:space-between; margin-bottom:10px; flex-wrap:wrap; gap:8px;">
                  <div style="display:flex; align-items:center; gap:8px; font-weight:700; font-size:14px; color:var(--ink,#131D3B);">
                    <?= icon('building', 15) ?> Department: <?= e(department_full_name($deptName)) ?>
                  </div>
                  <div style="display:flex; align-items:center; gap:8px;">
                    <span class="badge badge-neutral" style="font-size:11.5px; font-weight:600;">
                      <?= count($facultyList) ?> faculty member<?= count($facultyList) === 1 ? '' : 's' ?>
                    </span>
                    <span class="badge badge-neutral" style="font-size:11.5px; font-weight:700; color:var(--brand,#FF4F01);">
                      <?= $deptAchievementTotal ?> achievement<?= $deptAchievementTotal === 1 ? '' : 's' ?>
                    </span>
                  </div>
                </div>

                <?php if (empty($facultyList)): ?>
                  <div style="padding:14px 16px; background:#fff; border:1px dashed var(--hairline,#E4E9F2); border-radius:8px; color:var(--ink-muted,#64748b); font-size:13px; margin-bottom:12px;">
                    No faculty achievement records available for this department in Academic Year <?= e($year) ?>.
                  </div>
                <?php else: ?>
                  <div class="table-wrap mb-3" style="border:1px solid var(--hairline,#E4E9F2); border-radius:10px; overflow:hidden;">
                    <table class="data wide" style="margin:0;">
                      <thead style="background:var(--navy-50,#F4F6FA);">
                        <tr>
                          <th style="width:40px;">#</th>
                          <th style="width:28%;">Faculty Member</th>
                          <th style="width:20%;">Designation &amp; ID</th>
                          <th>Achievement Information</th>
                          <th style="text-align:center; width:130px;">Action</th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php foreach ($facultyList as $idx => $f): ?>
                          <?php
                            $tot = (int) $f['total'];
                            $breakdownParts = [];
                            if ($f['journals'] > 0)    $breakdownParts[] = (int) $f['journals'] . ' Journal' . ($f['journals'] > 1 ? 's' : '');
                            if ($f['conferences'] > 0) $breakdownParts[] = (int) $f['conferences'] . ' Conf' . ($f['conferences'] > 1 ? 's' : '');
                            if ($f['books'] > 0)       $breakdownParts[] = (int) $f['books'] . ' Book' . ($f['books'] > 1 ? 's' : '');
                            if ($f['events'] > 0)      $breakdownParts[] = (int) $f['events'] . ' Event' . ($f['events'] > 1 ? 's' : '');
                            if ($f['training'] > 0)    $breakdownParts[] = (int) $f['training'] . ' Training' . ($f['training'] > 1 ? 's' : '');
                            if ($f['patents'] > 0)     $breakdownParts[] = (int) $f['patents'] . ' Patent' . ($f['patents'] > 1 ? 's' : '');
                            if ($f['other'] > 0)       $breakdownParts[] = (int) $f['other'] . ' Other';
                            $breakdownStr = !empty($breakdownParts) ? implode(' · ', $breakdownParts) : '';
                          ?>
                          <tr>
                            <td class="faint tabular"><?= $idx + 1 ?></td>
                            <td>
                              <div class="fw-500" style="font-weight:600; color:var(--ink,#131D3B); font-size:13.5px;"><?= e($f['name']) ?></div>
                              <div class="card-sub truncate" style="font-size:11.5px;"><?= e($f['email']) ?></div>
                            </td>
                            <td>
                              <div style="font-size:12.5px; font-weight:500; color:var(--ink,#131D3B);"><?= e($f['designation']) ?></div>
                              <div class="card-sub" style="font-size:11px;"><?= e($f['employee_id']) ?></div>
                            </td>
                            <td>
                              <?php if ($tot > 0): ?>
                                <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                                  <span class="badge badge-brand" style="font-size:12px; font-weight:700; background:var(--orange-50,#FFF3EC); color:var(--brand,#FF4F01); border:1px solid var(--orange-200,#FFC3A8);">
                                    <?= $tot ?> Achievement<?= $tot === 1 ? '' : 's' ?>
                                  </span>
                                  <?php if ($breakdownStr !== ''): ?>
                                    <span class="card-sub" style="font-size:11.5px; color:var(--ink-muted,#64748b);">
                                      <?= e($breakdownStr) ?>
                                    </span>
                                  <?php endif; ?>
                                </div>
                              <?php else: ?>
                                <span class="badge badge-neutral" style="font-size:11.5px; color:var(--ink-muted,#64748b);">
                                  No achievements
                                </span>
                              <?php endif; ?>
                            </td>
                            <td style="text-align:center; white-space:nowrap;">
                              <a href="<?= e(url('individual-faculty-report.php')) ?>?id=<?= (int) $f['id'] ?>" class="btn btn-primary btn-sm" style="border-radius:999px; padding:4px 12px; font-size:12px; display:inline-flex; align-items:center; gap:5px;" title="View detailed staff profile report for <?= e($f['name']) ?>">
                                <?= icon('file-text', 13) ?> View Report
                              </a>
                            </td>
                          </tr>
                        <?php endforeach; ?>
                      </tbody>
                    </table>
                  </div>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
<?php endif; ?>

<?php // EM-SPEC-04: Obsolete duplicate hero card removed. The primary Executive Meeting Report action is in the top header. ?>

<div class="mt-5 card">
  <div class="card-head">
    <div>
      <div class="card-title"><?= icon('reports', 16) ?> Report Hub</div>
      <div class="card-sub">
        Download any report, built live from the database &middot; <?= e($scopeName) ?>
        <?php if ($activeBits): ?>&middot; <?= e(implode(' · ', $activeBits)) ?><?php endif; ?>
      </div>
    </div>
  </div>

  <div class="card-body">
    <!-- Institution-wide summaries -->
    <div class="rh-label">Summary Reports</div>
    <div class="tmpl-report-grid">

      <?php if ($canSummary): ?>
        <?php $mq = $meetingQ2; ?>
        <div class="tmpl-report-row">
          <div class="tmpl-report-info">
            <div class="tmpl-report-name"><?= icon('target', 15) ?> Executive Meeting Report</div>
            <div class="tmpl-report-sub">Fixed vs Achieved targets &middot; <?= (int) $targetCount ?> target<?= $targetCount === 1 ? '' : 's' ?> in scope</div>
          </div>
          <div class="tmpl-report-links">
            <a class="btn btn-primary btn-sm" href="<?= $link('template-report.php', $mq, 'excel') ?>"><?= icon('download') ?> Excel</a>
            <a class="btn btn-outline btn-sm" href="<?= $link('template-report.php', $mq, 'word') ?>">Word</a>
            <a class="btn btn-outline btn-sm" href="<?= $link('template-report.php', $mq, 'pdf') ?>" target="_blank" rel="noopener">PDF</a>
          </div>
        </div>
      <?php endif; ?>

      <div class="tmpl-report-row">
        <div class="tmpl-report-info">
          <div class="tmpl-report-name"><?= icon('file-text', 15) ?> Academic Records</div>
          <div class="tmpl-report-sub">Every uploaded record &middot; <?= (int) $totalScoped ?> in scope</div>
        </div>
        <div class="tmpl-report-links">
          <a class="btn btn-primary btn-sm" href="<?= $link('export.php', $recordsQ, 'excel') ?>"><?= icon('download') ?> Excel</a>
          <a class="btn btn-outline btn-sm" href="<?= $link('export.php', $recordsQ, 'word') ?>">Word</a>
          <a class="btn btn-outline btn-sm" href="<?= $link('export.php', $recordsQ, 'pdf') ?>" target="_blank" rel="noopener">PDF</a>
        </div>
      </div>

      <?php if ($canSummary): ?>
        <div class="tmpl-report-row">
          <div class="tmpl-report-info">
            <div class="tmpl-report-name"><?= icon('bar-chart', 15) ?> Metrics Summary</div>
            <div class="tmpl-report-sub"><?= count($allSpecs) ?> metrics &middot; counts by review status</div>
          </div>
          <div class="tmpl-report-links">
            <a class="btn btn-primary btn-sm" href="<?= $link('metrics-report.php', $metricsQ, 'excel') ?>"><?= icon('download') ?> Excel</a>
            <a class="btn btn-outline btn-sm" href="<?= $link('metrics-report.php', $metricsQ, 'word') ?>">Word</a>
            <a class="btn btn-outline btn-sm" href="<?= $link('metrics-report.php', $metricsQ, 'pdf') ?>" target="_blank" rel="noopener">PDF</a>
          </div>
        </div>
      <?php endif; ?>
    </div>

    <!-- Per-metric official proformas -->
    <div class="rh-label-wrap" style="margin-top:24px; margin-bottom:8px; display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap;">
      <div class="rh-label" style="margin:0">
        Template Reports <span class="card-sub" style="text-transform:none;font-weight:400">— each metric in its exact IQAC proforma</span>
      </div>
      <?php if (count($shownSpecs) > 4): ?>
        <button type="button" class="btn btn-secondary btn-sm js-toggle-tmpl-reports" id="toggleTmplBtn" data-open="0"
                style="border-radius:999px; padding:4px 12px; font-size:12px; display:inline-flex; align-items:center; gap:6px;">
          <?= icon('eye', 14) ?> <span id="toggleTmplTxt">Show all <?= count($shownSpecs) ?> template reports</span>
        </button>
      <?php endif; ?>
    </div>
    <div class="tmpl-report-grid" id="tmplReportGrid">
      <?php $specIdx = 0; foreach ($shownSpecs as $key => $spec): $specIdx++; ?>
        <?php $q = ['type' => $key] + $reportScopeQ; $n = (int) ($typeCounts[$key] ?? 0); ?>
        <div class="tmpl-report-row <?= $specIdx > 4 ? 'tmpl-report-extra' : '' ?>" <?= $specIdx > 4 ? 'hidden' : '' ?>>
          <div class="tmpl-report-info">
            <div class="tmpl-report-name"><?= e($spec['title']) ?></div>
            <div class="tmpl-report-sub"><?= $n ?> record<?= $n === 1 ? '' : 's' ?> in scope</div>
          </div>
          <div class="tmpl-report-links">
            <a class="btn btn-primary btn-sm" href="<?= $link('record-report.php', $q, 'excel') ?>"><?= icon('download') ?> Excel</a>
            <a class="btn btn-outline btn-sm" href="<?= $link('record-report.php', $q, 'word') ?>">Word</a>
            <a class="btn btn-outline btn-sm" href="<?= $link('record-report.php', $q, 'pdf') ?>" target="_blank" rel="noopener">PDF</a>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<style>
  .rh-filter { border-bottom:1px solid var(--hairline,#e6e8ef); }
  .rh-label { font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:.04em;
      color:var(--ink-muted,#64748b); margin:0 0 4px; }
  .tmpl-report-grid { display:flex; flex-direction:column; }
  .tmpl-report-row { display:flex; align-items:center; justify-content:space-between; gap:16px;
      padding:12px 0; border-bottom:1px solid var(--line,#e6e8ef); transition:all .2s ease; }
  .tmpl-report-row:last-child { border-bottom:0; }
  .tmpl-report-info { min-width:0; flex:1; }
  .tmpl-report-name { font-weight:600; font-size:14px; display:flex; align-items:center; gap:7px; }
  .tmpl-report-sub { font-size:12px; color:var(--ink-muted,#64748b); margin-top:2px; }
  .tmpl-report-links { display:grid; grid-template-columns:repeat(3, 82px); gap:8px; flex-shrink:0; }
  .tmpl-report-links .btn { min-width:0; width:100%; justify-content:center; text-align:center; box-sizing:border-box; padding:6px 0; }
  .tmpl-report-extra[hidden], .rec-cat-body[hidden] { display:none !important; }

  /* Hero cards (Consolidated / Faculty Achievements) — a plain wrapping row of
     buttons. Kept separate from .tmpl-report-links, whose fixed 3x82px grid
     stretches every button to full width and stacks them one per line. */
  .hero-card-row { display:flex; align-items:center; justify-content:space-between; gap:16px 20px; flex-wrap:wrap; }
  .hero-card-row > div:first-child { flex:1 1 300px; min-width:0; }
  .hero-card-actions { display:flex; align-items:center; justify-content:flex-end; gap:8px; flex-wrap:wrap; flex-shrink:0; }
  .hero-card-actions .btn { width:auto; display:inline-flex; align-items:center; justify-content:center;
      gap:6px; white-space:nowrap; }
  .hero-card-actions--pill .btn { border-radius:999px; }
  @media (max-width:860px){
    .hero-card-row { flex-direction:column; align-items:stretch; gap:12px; }
    .hero-card-row > div:first-child { max-width:none !important; }
    .hero-card-actions { justify-content:flex-start; }
  }
  @media (max-width:640px){
    .tmpl-report-row { flex-direction:column; align-items:flex-start; gap:10px; }
    .tmpl-report-links { width:100%; grid-template-columns:repeat(3, 1fr); }
    .hero-card-actions .btn { flex:1 1 auto; }
  }
</style>


<!-- The records themselves, grouped by category -->
<?php
  // Group every in-scope record under its type, keeping the record_types order.
  $byType = [];
  foreach ($records as $r) { $byType[$r['_type_key']][] = $r; }
  // Every record is rendered; the ones past $perGroup start hidden and the
  // "Show all" button reveals them, so the full list is one click away without
  // a round trip. $maxInline caps a single group so a type with thousands of
  // records can't bloat the page — the download still holds everything.
  $perGroup  = 6;
  $maxInline = 400;

  // Lay the types out under their category, in the order record_types() lists
  // them. Anything a category doesn't claim (a newly added type) still shows,
  // under "Other records", so nothing silently disappears from this page.
  $shownCats = $category !== null ? [$category => $categories[$category]] : $categories;
  $catTypeMap = [];
  foreach ($shownCats as $ckey => $cat) {
      $catTypeMap[$ckey] = array_intersect(array_keys($types), $cat['types']);
  }
  if ($category === null) {
      $claimed = array_merge(...array_values(array_column($categories, 'types')));
      $orphans = array_diff(array_keys($types), $claimed);
      if ($orphans) {
          $shownCats['other']  = ['label' => 'Other records', 'icon' => 'layers', 'types' => $orphans];
          $catTypeMap['other'] = $orphans;
      }
  }
?>
<div class="mt-5 card">
  <div class="card-head">
    <div>
      <div class="card-title">Records by Category</div>
      <div class="card-sub"><?= (int) $total ?> record<?= $total === 1 ? '' : 's' ?> in scope &middot; grouped by type</div>
    </div>
    <div class="flex gap-2 items-center" style="flex-wrap:wrap;">
      <?php if ($total > 0): ?>
        <button type="button" class="btn btn-secondary btn-sm" id="expandAllBtn"
                data-on="0" title="Show every record in every category" style="border-radius:999px;">
          <?= icon('eye', 14) ?> <span>Show all <?= (int) $total ?> records</span>
        </button>
        <button type="button" class="btn btn-outline btn-sm js-toggle-cats-all" id="toggleCatsAllBtn" data-open="0"
                title="Expand or collapse all category sections" style="border-radius:999px;">
          <?= icon('chevron-down', 14) ?> <span id="toggleCatsAllTxt">Expand categories</span>
        </button>
      <?php endif; ?>
    </div>
  </div>

  <div class="card-body pt-0">
    <?php if (empty($records)): ?>
      <div class="empty">
        <div class="ic"><?= icon('reports', 20) ?></div>
        <p>No records found</p>
        <div class="note">Try changing the filters above.</div>
      </div>
    <?php else: ?>
      <?php foreach ($shownCats as $ckey => $cat): ?>
      <?php
        $catKeys  = array_filter($catTypeMap[$ckey], fn($k) => !empty($byType[$k]));
        $catCount = array_sum(array_map(fn($k) => count($byType[$k]), $catKeys));
      ?>
      <?php if (empty($catKeys)) continue; ?>
      <section class="rec-cat" id="cat-<?= e($ckey) ?>">
        <div class="rec-cat-head js-toggle-cat" data-cat="<?= e($ckey) ?>" style="cursor:pointer;" title="Click to expand/collapse <?= e($cat['label']) ?>">
          <span class="rec-cat-name"><?= icon($cat['icon'], 15) ?> <?= e($cat['label']) ?></span>
          <span class="badge badge-neutral"><?= (int) $catCount ?></span>
          <div class="rec-cat-actions" style="margin-left:auto; display:flex; align-items:center; gap:8px;">
            <?php if ($category === null && $ckey !== 'other'): ?>
              <a class="rec-cat-view btn btn-outline btn-sm" onclick="event.stopPropagation();"
                 href="<?= e(url('reports.php') . '?' . http_build_query(array_filter(['category' => $ckey] + $recordsQ))) ?>">
                <?= icon('eye', 13) ?> View only
              </a>
            <?php endif; ?>
          </div>
        </div>
        <div class="rec-cat-body" id="cat-body-<?= e($ckey) ?>" hidden>
          <?php foreach ($catKeys as $key): ?>
            <?php
              $t      = $types[$key];
              $group  = $byType[$key];
              $shown  = array_slice($group, 0, $maxInline);
              $hidden = max(0, count($shown) - $perGroup);   // rendered, but collapsed
              $tq     = ['type' => $key] + $reportScopeQ;
            ?>
            <div class="rec-group" data-group="<?= e($key) ?>">
              <div class="rec-group-head">
                <span class="rec-group-name"><?= e($t['label']) ?></span>
                <span class="badge badge-neutral"><?= count($group) ?></span>
                <div class="rec-group-actions">
                  <?php if ($hidden > 0): ?>
                    <button type="button" class="rec-group-btn js-show-all" data-on="0"
                            title="Show all <?= count($shown) ?> <?= e($t['label']) ?> records">
                      <?= icon('eye', 13) ?> <span>Show all <?= count($shown) ?></span>
                    </button>
                  <?php endif; ?>
                  <a class="rec-group-btn" href="<?= $link('record-report.php', $tq, 'pdf') ?>"
                     target="_blank" rel="noopener" title="Open the full <?= e($t['label']) ?> report in its IQAC proforma">
                    <?= icon('file-text', 13) ?> Full report
                  </a>
                  <a class="rec-group-btn" href="<?= $link('record-report.php', $tq, 'word') ?>"
                     title="Download the <?= e($t['label']) ?> report">
                    <?= icon('download', 13) ?> Download
                  </a>
                </div>
              </div>
              <div class="table-wrap">
                <table class="data wide">
                  <thead><tr><th>Record</th><th>Department</th><th>Status</th><th>Date</th></tr></thead>
                  <tbody>
                    <?php foreach ($shown as $i => $record): ?>
                      <tr <?= $i >= $perGroup ? 'class="rec-extra" hidden' : '' ?>>
                        <td>
                          <div class="fw-500 truncate" style="max-width:380px"><?= e($record['_title']) ?></div>
                          <?php if ($record['_person'] !== ''): ?>
                            <div class="card-sub"><?= e($record['_person']) ?></div>
                          <?php endif; ?>
                        </td>
                        <td class="faint"><?= e($record['department'] ?? '—') ?></td>
                        <td><span class="badge badge-<?= status_class($record['status']) ?>"><?= e($record['status']) ?></span></td>
                        <td class="card-sub"><?= e(time_ago($record['created_at'])) ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
              <?php if (count($group) > count($shown)): ?>
                <div class="rec-more card-sub">
                  Showing the newest <?= count($shown) ?> of <?= count($group) ?> &middot;
                  open <strong>Full report</strong> above for every record
                </div>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      </section>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>
<style>
  .rec-cat { margin-bottom:24px; }
  .rec-cat:last-child { margin-bottom:0; }
  .rec-cat-head { display:flex; align-items:center; gap:10px; margin:0 0 14px;
      padding:10px 14px; border-radius:10px; background:var(--navy-50,#F4F6FA); border:1px solid var(--hairline,#E4E9F2);
      transition:all .15s ease; user-select:none; }
  .rec-cat-head:hover { background:var(--orange-50,#FFF3EC); border-color:var(--orange-200,#FFC3A8); }
  .rec-cat-name { display:flex; align-items:center; gap:7px; font-size:15px; font-weight:700; color:var(--ink,#131D3B); }
  .rec-cat-view { margin-left:0; }
  .rec-cat-body { padding-top:4px; }
  .rec-group { margin-bottom:24px; }
  .rec-group:last-child { margin-bottom:0; }
  .rec-group-head { display:flex; align-items:center; gap:10px; margin:0 0 8px; flex-wrap:wrap; }
  .rec-group-name { font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:var(--ink-muted,#64748b); }
  .rec-group-actions { margin-left:auto; display:flex; align-items:center; gap:6px; flex-shrink:0; }
  /* One pill per action: show every row, open the proforma, download it. */
  .rec-group-btn { display:inline-flex; align-items:center; gap:5px; font:inherit; font-size:11.5px;
      font-weight:600; line-height:1; text-decoration:none; cursor:pointer; white-space:nowrap;
      padding:5px 10px; border-radius:999px; color:var(--ink-muted,#64748b);
      background:var(--navy-50,#F4F6FA); border:1px solid var(--hairline,#E4E9F2);
      transition:background .12s ease, color .12s ease, border-color .12s ease; }
  .rec-group-btn:hover { background:var(--orange-50,#FFF3EC); color:var(--brand,#FF4F01); border-color:var(--brand,#FF4F01); }
  .rec-group-btn:focus-visible { outline:2px solid var(--orange-500,#FF4F01); outline-offset:1px; }
  .rec-group-btn[data-on="1"] { background:var(--ink,#131D3B); color:#fff; border-color:var(--ink,#131D3B); }
  .rec-more { padding:8px 0 0; }
  @media (max-width:640px){
    .rec-group-actions { margin-left:0; width:100%; flex-wrap:wrap; }
    .rec-cat-view { margin-left:0; }
  }
</style>

<script>
/* Interactive controls for Template Reports and Records by Category */
(function () {
  const label = (btn, text) => { const s = btn.querySelector('span'); if (s) s.textContent = text; };

  document.addEventListener('click', function (e) {
    // 1. Template Reports toggle in Report Hub
    const tmplBtn = e.target.closest('#toggleTmplBtn');
    if (tmplBtn) {
      const open = tmplBtn.dataset.open === '1';
      const rows = document.querySelectorAll('.tmpl-report-extra');
      rows.forEach(r => r.hidden = open);
      tmplBtn.dataset.open = open ? '0' : '1';
      const txt = document.getElementById('toggleTmplTxt');
      if (txt) txt.textContent = open ? 'Show all <?= count($shownSpecs) ?> template reports' : 'Show less';
      return;
    }

    // 2. Individual Category collapse/expand
    const catHead = e.target.closest('.js-toggle-cat');
    if (catHead && !e.target.closest('a')) {
      const ckey = catHead.dataset.cat;
      const body = document.getElementById('cat-body-' + ckey);
      if (body) {
        const isHidden = body.hidden;
        body.hidden = !isHidden;
        const anyOpen = Array.from(document.querySelectorAll('.rec-cat-body')).some(b => !b.hidden);
        const allCatsBtn = document.getElementById('toggleCatsAllBtn');
        if (allCatsBtn) {
          allCatsBtn.dataset.open = anyOpen ? '1' : '0';
          const txt = document.getElementById('toggleCatsAllTxt');
          if (txt) txt.textContent = anyOpen ? 'Collapse categories' : 'Expand categories';
          const svg = allCatsBtn.querySelector('svg');
          if (svg) svg.outerHTML = anyOpen ? '<?= icon('chevron-up', 14) ?>' : '<?= icon('chevron-down', 14) ?>';
        }
      }
      return;
    }

    // 3. Collapse/Expand ALL Categories
    const allCatsBtn = e.target.closest('#toggleCatsAllBtn');
    if (allCatsBtn) {
      const open = allCatsBtn.dataset.open === '1'; // currently open, so collapse
      document.querySelectorAll('.rec-cat-body').forEach(b => b.hidden = open);
      allCatsBtn.dataset.open = open ? '0' : '1';
      const txt = document.getElementById('toggleCatsAllTxt');
      if (txt) txt.textContent = open ? 'Expand categories' : 'Collapse categories';
      allCatsBtn.querySelector('svg').outerHTML = open ? '<?= icon('chevron-down', 14) ?>' : '<?= icon('chevron-up', 14) ?>';
      return;
    }

    // 4. Show all records in group
    const btn = e.target.closest('.js-show-all');
    if (btn) {
      const group = btn.closest('.rec-group');
      if (group) setGroup(group, btn.dataset.on !== '1');
      return;
    }

    // 5. Expand all records
    const all = e.target.closest('#expandAllBtn');
    if (!all) return;
    const open = all.dataset.on !== '1';
    if (open) {
      document.querySelectorAll('.rec-cat-body').forEach(b => b.hidden = false);
      const allCatsBtn = document.getElementById('toggleCatsAllBtn');
      if (allCatsBtn) {
        allCatsBtn.dataset.open = '1';
        const txt = document.getElementById('toggleCatsAllTxt');
        if (txt) txt.textContent = 'Collapse categories';
        const svg = allCatsBtn.querySelector('svg');
        if (svg) svg.outerHTML = '<?= icon('chevron-up', 14) ?>';
      }
    }
    document.querySelectorAll('.rec-group').forEach(g => setGroup(g, open));
    all.dataset.on = open ? '1' : '0';
    label(all, open ? 'Collapse all' : 'Show all <?= (int) $total ?> records');
  });

  function setGroup(group, open) {
    group.querySelectorAll('tr.rec-extra').forEach(tr => { tr.hidden = !open; });
    const btn = group.querySelector('.js-show-all');
    if (!btn) return;
    const n = group.querySelectorAll('tbody tr').length;
    btn.dataset.on = open ? '1' : '0';
    label(btn, open ? 'Show less' : 'Show all ' + n);
  }
})();

function toggleFacultyAchievementsReport() {
  const c = document.getElementById('feat4FacultyReportContainer');
  const txt = document.getElementById('toggleFacultyReportTxt');
  const hdrTxt = document.getElementById('toggleFacultyReportHdrTxt');
  const iconSpan = document.getElementById('toggleFacultyReportIcon');
  const btn = document.getElementById('btnToggleFacultyReport');
  if (!c) return;
  const isHidden = (c.style.display === 'none' || c.hidden);
  if (isHidden) {
    c.style.display = 'block';
    c.hidden = false;
    if (txt) txt.textContent = 'Hide Report';
    if (hdrTxt) hdrTxt.textContent = 'Hide Report';
    if (iconSpan) iconSpan.innerHTML = '<?= icon('chevron-up', 13) ?>';
    if (btn) btn.classList.add('active');
    c.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  } else {
    c.style.display = 'none';
    c.hidden = true;
    if (txt) txt.textContent = 'View Report';
    if (hdrTxt) hdrTxt.textContent = 'View Report';
    if (iconSpan) iconSpan.innerHTML = '<?= icon('chevron-down', 13) ?>';
    if (btn) btn.classList.remove('active');
  }
}

(function() {
  if (window.location.hash === '#faculty-achievements-report' || window.location.search.includes('view=faculty_report')) {
    toggleFacultyAchievementsReport();
  }
})();
</script>

<style>
/* Date picker used by the Period pill. Brand palette; selection in navy, the
   hover in orange, matching the filter pills. */
.custom-calendar-popover {
  position:absolute; top:100%; left:-6px; z-index:99999; margin-top:9px;
  width:268px; padding:12px;
  background:var(--surface); border:1px solid var(--hairline); border-radius:var(--r-lg);
  box-shadow:var(--shadow-pop);
  font-family:inherit; font-size:13px; color:var(--ink); cursor:default;
  animation:pop-in .18s var(--ease-out);
}
.cal-head { display:flex; align-items:center; justify-content:space-between; gap:4px; margin-bottom:10px; }
.cal-head select {
  height:30px; padding:0 6px; border-radius:var(--r-sm); border:1px solid var(--hairline);
  font:inherit; font-size:12.5px; font-weight:600; background:var(--surface); color:var(--ink); cursor:pointer;
}
.cal-btn {
  width:30px; height:30px; display:grid; place-items:center; padding:0;
  background:var(--surface); border:1px solid var(--hairline); border-radius:var(--r-sm);
  cursor:pointer; font-weight:700; color:var(--ink-muted);
  transition:background var(--dur) var(--ease), color var(--dur) var(--ease);
}
.cal-btn:hover { background:var(--navy-50); color:var(--ink); }
.cal-grid { display:grid; grid-template-columns:repeat(7, 1fr); gap:2px; text-align:center; }
.cal-day-hdr { font-weight:600; color:var(--ink-faint); font-size:10.5px; padding:4px 0; letter-spacing:.04em; }
.cal-day {
  padding:6px 0; border-radius:var(--r-sm); cursor:pointer; font-variant-numeric:tabular-nums;
  transition:background .12s var(--ease), color .12s var(--ease);
}
.cal-day:hover { background:var(--orange-50); color:var(--orange-700); }
.cal-day.selected { background:var(--navy-900); color:#fff; font-weight:700; }
.cal-day.empty { cursor:default; background:transparent; }
</style>

<script>
let activeCalendarInput = null;
let currentCalDate = new Date();

function openCustomCalendar(inputId, evt) {
  if (evt) evt.stopPropagation();
  const input = document.getElementById(inputId);
  if (!input) return;
  
  closeCustomCalendar();
  activeCalendarInput = input;
  
  const val = input.value.trim();
  const m = val.match(/^(\d{1,2})[-|\/](\d{1,2})[-|\/](\d{4})$/);
  if (m) {
    currentCalDate = new Date(parseInt(m[3], 10), parseInt(m[2], 10) - 1, parseInt(m[1], 10));
  } else {
    currentCalDate = new Date();
  }
  
  const popover = document.createElement('div');
  popover.id = 'custom-calendar-popover';
  popover.className = 'custom-calendar-popover';
  popover.onclick = (e) => e.stopPropagation();
  
  renderCalendarContent(popover);
  
  const wrap = input.closest('.date-picker-wrap') || input.parentElement;
  wrap.style.position = 'relative';
  wrap.appendChild(popover);
}

function renderCalendarContent(popover) {
  const year = currentCalDate.getFullYear();
  const month = currentCalDate.getMonth();
  
  const monthNames = ["January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December"];
  
  let monthOptions = '';
  monthNames.forEach((name, i) => {
    monthOptions += `<option value="${i}" ${i === month ? 'selected' : ''}>${name}</option>`;
  });
  
  let yearOptions = '';
  const currYear = new Date().getFullYear();
  for (let y = currYear - 10; y <= currYear + 5; y++) {
    yearOptions += `<option value="${y}" ${y === year ? 'selected' : ''}>${y}</option>`;
  }
  
  let html = `
    <div class="cal-head">
      <button type="button" class="cal-btn" onclick="prevCalMonth()">&lt;</button>
      <select onchange="changeCalMonth(this.value)">${monthOptions}</select>
      <select onchange="changeCalYear(this.value)">${yearOptions}</select>
      <button type="button" class="cal-btn" onclick="nextCalMonth()">&gt;</button>
    </div>
    <div class="cal-grid">
      <div class="cal-day-hdr">Su</div>
      <div class="cal-day-hdr">Mo</div>
      <div class="cal-day-hdr">Tu</div>
      <div class="cal-day-hdr">We</div>
      <div class="cal-day-hdr">Th</div>
      <div class="cal-day-hdr">Fr</div>
      <div class="cal-day-hdr">Sa</div>
  `;
  
  const firstDay = new Date(year, month, 1).getDay();
  const daysInMonth = new Date(year, month + 1, 0).getDate();
  
  for (let i = 0; i < firstDay; i++) {
    html += `<div class="cal-day empty"></div>`;
  }
  
  let selDay = -1, selMonth = -1, selYear = -1;
  if (activeCalendarInput && activeCalendarInput.value) {
    const parts = activeCalendarInput.value.split(/[-|\/]/);
    if (parts.length === 3) {
      selDay = parseInt(parts[0], 10);
      selMonth = parseInt(parts[1], 10) - 1;
      selYear = parseInt(parts[2], 10);
    }
  }
  
  for (let d = 1; d <= daysInMonth; d++) {
    const isSel = (d === selDay && month === selMonth && year === selYear);
    html += `<div class="cal-day ${isSel ? 'selected' : ''}" onclick="selectCalDay(${d})">${d}</div>`;
  }
  
  html += `</div>`;
  popover.innerHTML = html;
}

function prevCalMonth() {
  currentCalDate.setMonth(currentCalDate.getMonth() - 1);
  const pop = document.getElementById('custom-calendar-popover');
  if (pop) renderCalendarContent(pop);
}

function nextCalMonth() {
  currentCalDate.setMonth(currentCalDate.getMonth() + 1);
  const pop = document.getElementById('custom-calendar-popover');
  if (pop) renderCalendarContent(pop);
}

function changeCalMonth(m) {
  currentCalDate.setMonth(parseInt(m, 10));
  const pop = document.getElementById('custom-calendar-popover');
  if (pop) renderCalendarContent(pop);
}

function changeCalYear(y) {
  currentCalDate.setFullYear(parseInt(y, 10));
  const pop = document.getElementById('custom-calendar-popover');
  if (pop) renderCalendarContent(pop);
}

function selectCalDay(d) {
  if (!activeCalendarInput) return;
  const dayStr = String(d).padStart(2, '0');
  const monthStr = String(currentCalDate.getMonth() + 1).padStart(2, '0');
  const yearStr = currentCalDate.getFullYear();
  
  activeCalendarInput.value = `${dayStr}-${monthStr}-${yearStr}`;
  activeCalendarInput.dispatchEvent(new Event('input', { bubbles: true }));   // pill turns "set"
  closeCustomCalendar();

  periodChanged();
}

/* Apply the period as soon as it is complete and valid — the other pills
   apply on change too. A half-typed date just waits. */
function periodChanged() {
  const f = document.getElementById('from_date_input');
  const t = document.getElementById('to_date_input');
  if (!f || !t) return;
  const full = /^\d{1,2}[-\/]\d{1,2}[-\/]\d{4}$/;
  const complete = [f, t].every(el => !el.value.trim() || full.test(el.value.trim()));
  if (complete && validatePeriodRange()) f.form.submit();
}

function closeCustomCalendar() {
  const pop = document.getElementById('custom-calendar-popover');
  if (pop) pop.remove();
}

document.addEventListener('click', function(e) {
  if (!e.target.closest('#custom-calendar-popover') && !e.target.closest('.date-picker-btn')) {
    closeCustomCalendar();
  }
});

function validatePeriodRange() {
  const fromEl = document.getElementById('from_date_input');
  const toEl = document.getElementById('to_date_input');
  const errEl = document.getElementById('period_range_error');
  
  if (!fromEl || !toEl) return true;
  
  const parseDate = (str) => {
    if (!str) return null;
    const p = str.trim().split(/[-|\/]/);
    if (p.length === 3 && p[0].length >= 1 && p[1].length >= 1 && p[2].length === 4) {
      return new Date(parseInt(p[2], 10), parseInt(p[1], 10) - 1, parseInt(p[0], 10));
    }
    return null;
  };
  
  const fDate = parseDate(fromEl.value);
  const tDate = parseDate(toEl.value);
  
  if (fDate && tDate && fDate > tDate) {
    if (errEl) {
      errEl.textContent = 'From Date cannot be later than To Date.';
      errEl.style.display = 'block';
    }
    const pill = fromEl.closest('.fb-range');
    if (pill) pill.classList.add('has-error');
    return false;
  } else {
    if (errEl) {
      errEl.textContent = '';
      errEl.style.display = 'none';
    }
    const pill = fromEl.closest('.fb-range');
    if (pill) pill.classList.remove('has-error');
    return true;
  }
}

// EM-SPEC-04: Keep the top-header Executive Meeting Report link in sync with any client-side filter changes.
(function() {
  function syncEmReportLink() {
    var btn = document.getElementById('header_em_report_btn');
    if (!btn) return;
    var form = document.querySelector('form.fbar');
    if (!form) return;
    var params = new URLSearchParams();
    var yearEl = form.querySelector('select[name="academic_year"]');
    if (yearEl && yearEl.value) params.set('academic_year', yearEl.value);
    var emEl = form.querySelector('select[name="em"]');
    if (emEl && emEl.value && emEl.value !== 'all') params.set('em', emEl.value);
    var deptEl = form.querySelector('select[name="department"]');
    if (deptEl && deptEl.value) params.set('department', deptEl.value);
    var qs = params.toString();
    var baseUrl = btn.href.split('?')[0];
    btn.href = qs ? baseUrl + '?' + qs : baseUrl;
  }
  document.addEventListener('DOMContentLoaded', function() {
    var form = document.querySelector('form.fbar');
    if (!form) return;
    form.querySelectorAll('select').forEach(function(sel) {
      sel.addEventListener('change', syncEmReportLink);
    });
  });
})();
</script>

<?php require __DIR__ . '/inc/footer.php'; ?>
