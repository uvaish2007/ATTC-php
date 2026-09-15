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
require_once __DIR__ . '/models/Record.php';
require_once __DIR__ . '/models/Department.php';
require_once __DIR__ . '/models/Target.php';
require_once __DIR__ . '/models/Setting.php';

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
// Director cannot narrow anything; a HoD cannot choose a department; Faculty
// and Coordinator keep the basic status/type filters within their own scope.
$department = ($isAdmin || $isDean) ? (trim((string) input('department')) ?: null) : null;
$status     = !$isDirector ? (trim((string) input('status')) ?: null) : null;
$type       = !$isDirector ? (trim((string) input('type'))   ?: null) : null;
// Reports show the system's ONE active academic year — never a client-chosen
// year, and never mixed with any other year's figures (section 6).
$year       = active_academic_year();
$rawFrom    = $canFilter   ? trim((string) input('from'))    : '';
$rawTo      = $canFilter   ? trim((string) input('to'))      : '';

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

$records = report_records($user, $department, $status, $type, $rangeError ? null : $fromIso, $rangeError ? null : $toIso, $year);

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
$recordsQ = array_filter([
    'department' => $department, 'status' => $status, 'type' => $type,
    'from' => $fromDisplay, 'to' => $toDisplay,
]);
$meetingQ = array_filter(['department' => $department, 'year' => $year]);
$metricsQ = array_filter(['department' => $department, 'from' => $fromDisplay, 'to' => $toDisplay]);

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
  $activeCount = count(array_filter([$department, $type, $status])) + (($fromDisplay || $toDisplay) ? 1 : 0); ?>
<div class="page-head">
  <div>
    <h1>Reports</h1>
    <div class="sub">
      <?= (int) $total ?> records &middot; <?= e($scopeName) ?>
    </div>
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

    <?php if ($isAdmin || $isDean): ?>
      <label class="fb-field"><span class="fb-k">Department</span>
        <select name="department" onchange="this.form.submit()">
          <option value="">All</option>
          <?php foreach ($departments as $d): ?>
            <option value="<?= e($d['name']) ?>" <?= $department === $d['name'] ? 'selected' : '' ?>><?= e($d['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
    <?php endif; ?>

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
  $shownSpecs = ($type !== null && isset($allSpecs[$type])) ? [$type => $allSpecs[$type]] : $allSpecs;
  $reportScopeQ = array_filter([
      'department' => $department, 'year' => $year, 'status' => $status,
      'from' => $fromIso, 'to' => $toIso,
  ]);

  // Live figures: records in scope per type (dept/status/period + year), so
  // every row shows how many records the download will contain. $records is
  // already scoped to the active year at the database level (report_records()).
  $scoped = $records;
  $typeCounts = [];
  foreach ($scoped as $r) { $typeCounts[$r['_type_key']] = ($typeCounts[$r['_type_key']] ?? 0) + 1; }
  $totalScoped = count($scoped);

  // The target proforma + metrics summary scope to the effective department.
  $effDept = $isOversight ? $department : ($isHod ? ($user['department'] ?: null) : null);
  $tStmt   = db()->prepare('SELECT COUNT(*) FROM targets' . ($effDept ? ' WHERE department = ?' : ''));
  $tStmt->execute($effDept ? [$effDept] : []);
  $targetCount = (int) $tStmt->fetchColumn();

  $canSummary = $role !== 'Coordinator' && $role !== 'Faculty';   // meeting + metrics
  $meetingQ2  = array_filter(['department' => $department, 'year' => $year]);

  // Active-filter chips under the heading.
  $activeBits = array_filter([
      $department ? 'Dept: ' . $department : null,
      $year       ? 'Year: ' . $year       : null,
      $status     ? 'Status: ' . $status   : null,
      ($fromIso || $toIso) ? 'Period set' : null,
  ]);
?>
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
    <div class="rh-label" style="margin-top:22px">
      Template Reports <span class="card-sub" style="text-transform:none;font-weight:400">— each metric in its exact IQAC proforma</span>
    </div>
    <div class="tmpl-report-grid">
      <?php foreach ($shownSpecs as $key => $spec): ?>
        <?php $q = ['type' => $key] + $reportScopeQ; $n = (int) ($typeCounts[$key] ?? 0); ?>
        <div class="tmpl-report-row">
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
      padding:12px 0; border-bottom:1px solid var(--line,#e6e8ef); }
  .tmpl-report-row:last-child { border-bottom:0; }
  .tmpl-report-info { min-width:0; flex:1; }
  .tmpl-report-name { font-weight:600; font-size:14px; display:flex; align-items:center; gap:7px; }
  .tmpl-report-sub { font-size:12px; color:var(--ink-muted,#64748b); margin-top:2px; }
  .tmpl-report-links { display:grid; grid-template-columns:repeat(3, 82px); gap:8px; flex-shrink:0; }
  .tmpl-report-links .btn { min-width:0; width:100%; justify-content:center; text-align:center; box-sizing:border-box; padding:6px 0; }
  @media (max-width:640px){
    .tmpl-report-row { flex-direction:column; align-items:flex-start; gap:10px; }
    .tmpl-report-links { width:100%; grid-template-columns:repeat(3, 1fr); }
  }
</style>


<!-- The records themselves, grouped by category -->
<?php
  // Group every in-scope record under its type, keeping the record_types order.
  $byType = [];
  foreach ($records as $r) { $byType[$r['_type_key']][] = $r; }
  $perGroup = 6;   // preview a few per category; the full set is in the download
?>
<div class="mt-5 card">
  <div class="card-head">
    <div>
      <div class="card-title">Records by Category</div>
      <div class="card-sub"><?= (int) $total ?> record<?= $total === 1 ? '' : 's' ?> in scope &middot; grouped by type</div>
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
      <?php foreach ($types as $key => $t): ?>
        <?php if (empty($byType[$key])) continue; ?>
        <?php $group = $byType[$key]; $shown = array_slice($group, 0, $perGroup); ?>
        <div class="rec-group">
          <div class="rec-group-head">
            <span class="rec-group-name"><?= e($t['label']) ?></span>
            <span class="badge badge-neutral"><?= count($group) ?></span>
            <?php $tq = ['type' => $key] + $reportScopeQ; ?>
            <a class="rec-group-dl" href="<?= $link('record-report.php', $tq, 'word') ?>"><?= icon('download', 13) ?> Report</a>
          </div>
          <div class="table-wrap">
            <table class="data wide">
              <thead><tr><th>Record</th><th>Department</th><th>Status</th><th>Date</th></tr></thead>
              <tbody>
                <?php foreach ($shown as $record): ?>
                  <tr>
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
          <?php if (count($group) > $perGroup): ?>
            <div class="rec-more card-sub">+ <?= count($group) - $perGroup ?> more &middot; download the report above for the full list</div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>
<style>
  .rec-group { margin-bottom:24px; }
  .rec-group:last-child { margin-bottom:0; }
  .rec-group-head { display:flex; align-items:center; gap:10px; margin:0 0 8px; }
  .rec-group-name { font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:var(--ink-muted,#64748b); }
  .rec-group-dl { margin-left:auto; font-size:12px; font-weight:600; display:inline-flex; align-items:center; gap:5px; }
  .rec-more { padding:8px 0 0; }
</style>

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
</script>

<?php require __DIR__ . '/inc/footer.php'; ?>
