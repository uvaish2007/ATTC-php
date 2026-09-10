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
$isDirector = $role === 'Director';
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
$year       = $canFilter   ? (trim((string) input('year'))   ?: null) : null;
$rawFrom    = $canFilter   ? trim((string) input('from'))    : '';
$rawTo      = $canFilter   ? trim((string) input('to'))      : '';

$fromIso     = parse_date_input($rawFrom);
$toIso       = parse_date_input($rawTo);
$fromDisplay = format_date_display($rawFrom);
$toDisplay   = format_date_display($rawTo);
$from        = $fromDisplay !== '' ? $fromDisplay : null;
$to          = $toDisplay !== '' ? $toDisplay : null;

$rangeError = null;
if ($fromIso && $toIso && $fromIso > $toIso) {
    $rangeError = 'From Date cannot be later than To Date.';
}

$records = report_records($user, $department, $status, $type, $rangeError ? null : $fromIso, $rangeError ? null : $toIso);

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

<?php $activeCount = count(array_filter([$department, $year, $type, $status])) + (($fromDisplay || $toDisplay) ? 1 : 0); ?>
<div class="page-head">
  <div>
    <h1>Reports</h1>
    <div class="sub">
      <?= (int) $total ?> records &middot; <?= e($scopeName) ?>
    </div>
  </div>

  <div class="actions">
      <details class="filter-funnel">
        <summary class="btn btn-outline btn-sm">
          <?= icon('filter', 15) ?> Filters<?php if ($activeCount): ?> <span class="ff-dot"><?= $activeCount ?></span><?php endif; ?>
        </summary>
        <span class="filter-backdrop" onclick="this.closest('details').removeAttribute('open')"></span>
        <div class="filter-pop">
          <form method="get" onsubmit="return validatePeriodRange()">
            <div class="ff-head">
              <span>Filter reports</span>
              <?php if ($activeCount): ?><a class="ff-clear" href="<?= e(url('reports.php')) ?>">Clear all</a><?php endif; ?>
            </div>
            <?php if ($isOversight): ?>
              <div class="ff-field"><label class="ff-label">Department</label>
                <select class="select" name="department" onchange="this.form.submit()">
                  <option value="">All departments</option>
                  <?php foreach ($departments as $d): ?>
                    <option value="<?= e($d['name']) ?>" <?= $department === $d['name'] ? 'selected' : '' ?>><?= e($d['name']) ?></option>
                  <?php endforeach; ?>
                </select></div>
            <?php endif; ?>

            <?php if ($canFilter): ?>
              <div class="ff-field"><label class="ff-label">Academic Year</label>
                <select class="select" name="year" onchange="this.form.submit()">
                  <option value="">All years</option>
                  <?php foreach ($years as $y): ?>
                    <option value="<?= e($y) ?>" <?= $year === $y ? 'selected' : '' ?>><?= e($y) ?></option>
                  <?php endforeach; ?>
                </select></div>
            <?php endif; ?>

            <div class="ff-field"><label class="ff-label">Metric / Type</label>
              <select class="select" name="type" onchange="this.form.submit()">
                <option value="">All types</option>
                <?php foreach ($types as $key => $t): ?>
                  <option value="<?= e($key) ?>" <?= $type === $key ? 'selected' : '' ?>><?= e($t['label']) ?></option>
                <?php endforeach; ?>
              </select></div>

            <div class="ff-field"><label class="ff-label">Review Status</label>
              <select class="select" name="status" onchange="this.form.submit()">
                <option value="">All statuses</option>
                <?php foreach (['Approved', 'Dean Pending', 'HOD Pending', 'Submitted', 'Draft', 'Rejected'] as $o): ?>
                  <option value="<?= $o ?>" <?= $status === $o ? 'selected' : '' ?>><?= $o ?></option>
                <?php endforeach; ?>
              </select></div>

            <?php if ($canFilter): ?>
              <div class="ff-field">
                <label class="ff-label">Submission Period</label>
                <div class="ff-period" style="display:flex; align-items:center; gap:8px;">
                  <div class="date-picker-wrap" style="position:relative; flex:1;">
                    <input class="input date-input" type="text" name="from" id="from_date_input"
                           placeholder="DD-MM-YYYY" value="<?= e($fromDisplay) ?>"
                           maxlength="10" autocomplete="off" style="padding-right:28px;" onchange="validatePeriodRange()">
                    <button type="button" class="date-picker-btn" onclick="openCustomCalendar('from_date_input', event)"
                            title="Open calendar" style="position:absolute; right:6px; top:50%; transform:translateY(-50%); background:none; border:none; cursor:pointer; color:#6b7280;">
                      <?= icon('calendar', 15) ?>
                    </button>
                  </div>
                  <span style="color:#6b7280; font-weight:600;">–</span>
                  <div class="date-picker-wrap" style="position:relative; flex:1;">
                    <input class="input date-input" type="text" name="to" id="to_date_input"
                           placeholder="DD-MM-YYYY" value="<?= e($toDisplay) ?>"
                           maxlength="10" autocomplete="off" style="padding-right:28px;" onchange="validatePeriodRange()">
                    <button type="button" class="date-picker-btn" onclick="openCustomCalendar('to_date_input', event)"
                            title="Open calendar" style="position:absolute; right:6px; top:50%; transform:translateY(-50%); background:none; border:none; cursor:pointer; color:#6b7280;">
                      <?= icon('calendar', 15) ?>
                    </button>
                  </div>
                </div>
                <div id="period_range_error" style="display:none; color:#dc2626; font-size:12px; margin-top:4px; font-weight:600;"></div>
              </div>
            <?php endif; ?>

            <div class="ff-actions">
              <button class="btn btn-primary btn-sm" type="submit"><?= icon('filter', 14) ?> Apply filters</button>
            </div>
          </form>
        </div>
      </details>
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


<?php if ($canFilter): ?>
  <!-- Filter bar: one set of filters shared by all three reports below -->
  <div class="mt-5 card">
    <div class="card-head">
      <div>
        <div class="card-title">Filters</div>
        <div class="card-sub">Applied to the reports and downloads below</div>
      </div>
      <?php if ($recordsQ || $year): ?>
        <a class="btn btn-ghost btn-sm" href="<?= e(url('reports.php')) ?>">Clear</a>
      <?php endif; ?>
    </div>
    <div class="card-body">
      <form method="get" class="flex gap-2 items-center" style="flex-wrap:wrap">
        <?php if ($isAdmin): ?>
          <select class="select" name="department" onchange="this.form.submit()">
            <option value="">All departments</option>
            <?php foreach ($departments as $d): ?>
              <option value="<?= e($d['name']) ?>" <?= $department === $d['name'] ? 'selected' : '' ?>><?= e($d['name']) ?></option>
            <?php endforeach; ?>
          </select>
        <?php endif; ?>

        <select class="select" name="year" onchange="this.form.submit()">
          <option value="">All years</option>
          <?php foreach ($years as $y): ?>
            <option value="<?= e($y) ?>" <?= $year === $y ? 'selected' : '' ?>><?= e($y) ?></option>
          <?php endforeach; ?>
        </select>

        <select class="select" name="type" onchange="this.form.submit()">
          <option value="">All types</option>
          <?php foreach ($types as $key => $t): ?>
            <option value="<?= e($key) ?>" <?= $type === $key ? 'selected' : '' ?>><?= e($t['label']) ?></option>
          <?php endforeach; ?>
        </select>

        <select class="select" name="status" onchange="this.form.submit()">
          <option value="">All statuses</option>
          <?php foreach (['Approved', 'Submitted', 'Draft', 'Rejected'] as $o): ?>
            <option value="<?= $o ?>" <?= $status === $o ? 'selected' : '' ?>><?= $o ?></option>
          <?php endforeach; ?>
        </select>

        <label class="card-sub" style="display:flex;align-items:center;gap:6px">Period
          <input class="input" type="date" name="from" value="<?= e((string) $fromIso) ?>" onchange="this.form.submit()" style="width:150px">
          <span>–</span>
          <input class="input" type="date" name="to" value="<?= e((string) $toIso) ?>" onchange="this.form.submit()" style="width:150px">
        </label>
      </form>
    </div>
  </div>
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
      'from' => $from, 'to' => $to,
  ]);

  // Live figures: records in scope per type (dept/status/period + year), so
  // every row shows how many records the download will contain.
  $scoped = $records;
  if ($year) {
      $scoped = array_filter($scoped, fn($r) => empty($r['academic_year']) || $r['academic_year'] === $year);
  }
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
      ($from || $to) ? 'Period set' : null,
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
.custom-calendar-popover {
  position: absolute;
  top: 100%;
  left: 0;
  z-index: 99999;
  margin-top: 4px;
  background: #ffffff;
  border: 1px solid #cbd5e1;
  border-radius: 8px;
  box-shadow: 0 10px 25px -5px rgba(0,0,0,0.15), 0 8px 10px -6px rgba(0,0,0,0.1);
  padding: 12px;
  width: 270px;
  font-family: inherit;
  font-size: 13px;
  color: #1e293b;
}
.cal-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 4px;
  margin-bottom: 10px;
}
.cal-head select {
  padding: 4px 6px;
  border-radius: 6px;
  border: 1px solid #cbd5e1;
  font-size: 12px;
  background: #fff;
  color: #0f172a;
}
.cal-btn {
  background: #f1f5f9;
  border: 1px solid #cbd5e1;
  border-radius: 6px;
  padding: 4px 8px;
  cursor: pointer;
  font-weight: bold;
  color: #334155;
}
.cal-btn:hover {
  background: #e2e8f0;
}
.cal-grid {
  display: grid;
  grid-template-columns: repeat(7, 1fr);
  gap: 2px;
  text-align: center;
}
.cal-day-hdr {
  font-weight: 600;
  color: #64748b;
  font-size: 11px;
  padding: 4px 0;
}
.cal-day {
  padding: 6px 0;
  border-radius: 6px;
  cursor: pointer;
  transition: background 0.15s;
}
.cal-day:hover {
  background: #eff6ff;
  color: #2563eb;
}
.cal-day.selected {
  background: #2563eb;
  color: #ffffff;
  font-weight: bold;
}
.cal-day.empty {
  cursor: default;
  background: transparent;
}
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
  closeCustomCalendar();
  
  validatePeriodRange();
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
    fromEl.style.borderColor = '#ef4444';
    toEl.style.borderColor = '#ef4444';
    return false;
  } else {
    if (errEl) {
      errEl.textContent = '';
      errEl.style.display = 'none';
    }
    fromEl.style.borderColor = '';
    toEl.style.borderColor = '';
    return true;
  }
}
</script>

<?php require __DIR__ . '/inc/footer.php'; ?>
