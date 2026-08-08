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

$role        = $user['role'];
$isAdmin     = $role === 'Admin';
$isHod       = $role === 'HoD';
$isDirector  = $role === 'Director';
$isOversight = $isAdmin || $isDirector;   // may choose any department (or all)
$canFilter   = $isAdmin || $isHod || $isDirector;   // year + period narrowing

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
// Admin and Director may choose any department, year and period; a HoD is
// pinned to their own department; Faculty/Coordinator keep type + status only.
$department = $isOversight ? (trim((string) input('department')) ?: null) : null;
$status     = trim((string) input('status')) ?: null;
$type       = trim((string) input('type'))   ?: null;
$year       = $canFilter ? (trim((string) input('year')) ?: null) : null;
$from       = $canFilter ? (trim((string) input('from')) ?: null) : null;
$to         = $canFilter ? (trim((string) input('to'))   ?: null) : null;

$records = report_records($user, $department, $status, $type, $from, $to);

$types       = record_types();
$departments = departments_all();
$years       = academic_years();
$template    = active_report_template();

$counts = ['Draft' => 0, 'Submitted' => 0, 'Approved' => 0, 'Rejected' => 0];
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
    'from' => $from, 'to' => $to,
]);
$meetingQ = array_filter(['department' => $department, 'year' => $year]);
$metricsQ = array_filter(['department' => $department, 'from' => $from, 'to' => $to]);

$link = fn(string $file, array $q, string $fmt) =>
    e(url($file) . '?' . http_build_query($q + ['format' => $fmt]));

$visible = array_slice($records, 0, 50);

$pageTitle  = 'Reports';
$breadcrumb = 'Reports';
require __DIR__ . '/inc/header.php';
?>

<?php $activeCount = count(array_filter([$department, $year, $type, $status])) + (($from || $to) ? 1 : 0); ?>
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
          <form method="get">
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
                <?php foreach (['Approved', 'Submitted', 'Draft', 'Rejected'] as $o): ?>
                  <option value="<?= $o ?>" <?= $status === $o ? 'selected' : '' ?>><?= $o ?></option>
                <?php endforeach; ?>
              </select></div>

            <?php if ($canFilter): ?>
              <div class="ff-field"><label class="ff-label">Submission Period</label>
                <div class="ff-period">
                  <input class="input" type="date" name="from" value="<?= e((string) $from) ?>" onchange="this.form.submit()">
                  <span>–</span>
                  <input class="input" type="date" name="to" value="<?= e((string) $to) ?>" onchange="this.form.submit()">
                </div></div>
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
            <a class="btn btn-primary btn-sm" href="<?= $link('template-report.php', $mq, 'word') ?>"><?= icon('download') ?> Word</a>
            <a class="btn btn-outline btn-sm" href="<?= $link('template-report.php', $mq, 'excel') ?>">Excel</a>
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
          <a class="btn btn-outline btn-sm" href="<?= $link('export.php', $recordsQ, 'csv') ?>">CSV</a>
        </div>
      </div>

      <?php if ($canSummary): ?>
        <div class="tmpl-report-row">
          <div class="tmpl-report-info">
            <div class="tmpl-report-name"><?= icon('bar-chart', 15) ?> Metrics Summary</div>
            <div class="tmpl-report-sub"><?= count($allSpecs) ?> metrics &middot; counts by review status</div>
          </div>
          <div class="tmpl-report-links">
            <a class="btn btn-primary btn-sm" href="<?= $link('metrics-report.php', $metricsQ, 'word') ?>"><?= icon('download') ?> Word</a>
            <a class="btn btn-outline btn-sm" href="<?= $link('metrics-report.php', $metricsQ, 'excel') ?>">Excel</a>
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
            <a class="btn btn-primary btn-sm" href="<?= $link('record-report.php', $q, 'word') ?>"><?= icon('download') ?> Word</a>
            <a class="btn btn-outline btn-sm" href="<?= $link('record-report.php', $q, 'excel') ?>">Excel</a>
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
  .tmpl-report-info { min-width:0; }
  .tmpl-report-name { font-weight:600; font-size:14px; display:flex; align-items:center; gap:7px; }
  .tmpl-report-sub { font-size:12px; color:var(--ink-muted,#64748b); margin-top:2px; }
  .tmpl-report-links { display:flex; gap:8px; flex-shrink:0; }
  @media (max-width:640px){ .tmpl-report-row{ flex-direction:column; align-items:flex-start; } }
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

<?php require __DIR__ . '/inc/footer.php'; ?>
