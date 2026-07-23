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
require_once __DIR__ . '/models/Record.php';
require_once __DIR__ . '/models/Department.php';
require_once __DIR__ . '/models/Target.php';
require_once __DIR__ . '/models/Setting.php';

$user = require_login();

$role       = $user['role'];
$isAdmin    = $role === 'Admin';
$isHod      = $role === 'HoD';
$isDirector = $role === 'Director';
$canFilter  = $isAdmin || $isHod;     // Director never narrows; Faculty/Coord keep basic filters

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
$department = $isAdmin ? (trim((string) input('department')) ?: null) : null;
$status     = !$isDirector ? (trim((string) input('status')) ?: null) : null;
$type       = !$isDirector ? (trim((string) input('type'))   ?: null) : null;
$year       = $canFilter   ? (trim((string) input('year'))   ?: null) : null;
$from       = $canFilter   ? (trim((string) input('from'))   ?: null) : null;
$to         = $canFilter   ? (trim((string) input('to'))     ?: null) : null;

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
} elseif ($isDirector) {
    $scopeName = 'All departments';
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

<div class="page-head">
  <div>
    <h1>Reports</h1>
    <div class="sub">
      <?= (int) $total ?> records &middot; <?= e($scopeName) ?>
      <?php if ($isDirector): ?>&middot; institution-wide (view &amp; export)<?php endif; ?>
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
          <input class="input" type="date" name="from" value="<?= e((string) $from) ?>" onchange="this.form.submit()" style="width:150px">
          <span>–</span>
          <input class="input" type="date" name="to" value="<?= e((string) $to) ?>" onchange="this.form.submit()" style="width:150px">
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


<!-- The reports you can generate -->
<div class="mt-5 report-cards">

  <?php if ($role !== 'Coordinator' && $role !== 'Faculty'): ?>
    <div class="card">
      <div class="card-head">
        <div>
          <div class="card-title"><?= icon('target', 16) ?> Executive Meeting Report</div>
          <div class="card-sub">Fixed vs Achieved targets &middot; <?= e($scopeName) ?> &middot; <?= e(ucfirst($template)) ?> format</div>
        </div>
      </div>
      <div class="card-body flex gap-2 items-center">
        <a class="btn btn-primary btn-sm" href="<?= $link('meeting-report.php', $meetingQ, 'word') ?>"><?= icon('download') ?> Word</a>
        <a class="btn btn-outline btn-sm" href="<?= $link('meeting-report.php', $meetingQ, 'excel') ?>">Excel</a>
        <a class="btn btn-outline btn-sm" href="<?= $link('meeting-report.php', $meetingQ, 'pdf') ?>" target="_blank" rel="noopener">PDF</a>
      </div>
    </div>
  <?php endif; ?>

  <div class="card">
    <div class="card-head">
      <div>
        <div class="card-title"><?= icon('file-text', 16) ?> Academic Records Report</div>
        <div class="card-sub">Every uploaded record &middot; <?= (int) $total ?> in scope</div>
      </div>
    </div>
    <div class="card-body flex gap-2 items-center">
      <a class="btn btn-primary btn-sm" href="<?= $link('export.php', $recordsQ, 'excel') ?>"><?= icon('download') ?> Excel</a>
      <a class="btn btn-outline btn-sm" href="<?= $link('export.php', $recordsQ, 'word') ?>">Word</a>
      <a class="btn btn-outline btn-sm" href="<?= $link('export.php', $recordsQ, 'csv') ?>">CSV</a>
    </div>
  </div>

  <?php if ($role !== 'Coordinator' && $role !== 'Faculty'): ?>
    <div class="card">
      <div class="card-head">
        <div>
          <div class="card-title"><?= icon('bar-chart', 16) ?> Metrics Report</div>
          <div class="card-sub">Per-metric counts by status &middot; <?= e($scopeName) ?></div>
        </div>
      </div>
      <div class="card-body flex gap-2 items-center">
        <a class="btn btn-primary btn-sm" href="<?= $link('metrics-report.php', $metricsQ, 'word') ?>"><?= icon('download') ?> Word</a>
        <a class="btn btn-outline btn-sm" href="<?= $link('metrics-report.php', $metricsQ, 'excel') ?>">Excel</a>
        <a class="btn btn-outline btn-sm" href="<?= $link('metrics-report.php', $metricsQ, 'pdf') ?>" target="_blank" rel="noopener">PDF</a>
      </div>
    </div>

    <!-- The Admin-designed custom template -->
    <div class="card">
      <div class="card-head">
        <div>
          <div class="card-title"><?= icon('reports', 16) ?> Custom Template Report</div>
          <div class="card-sub">
            The template the Admin designed
            <?php if ($isAdmin): ?>&middot; <a href="<?= e(url('report-template.php')) ?>">edit</a><?php endif; ?>
          </div>
        </div>
      </div>
      <div class="card-body flex gap-2 items-center">
        <a class="btn btn-primary btn-sm" href="<?= $link('template-report.php', array_filter(['department' => $department]), 'word') ?>"><?= icon('download') ?> Word</a>
        <a class="btn btn-outline btn-sm" href="<?= $link('template-report.php', array_filter(['department' => $department]), 'excel') ?>">Excel</a>
        <a class="btn btn-outline btn-sm" href="<?= $link('template-report.php', array_filter(['department' => $department]), 'pdf') ?>" target="_blank" rel="noopener">PDF</a>
      </div>
    </div>
  <?php endif; ?>

</div>


<!-- The records themselves (preview) -->
<div class="mt-5 card">
  <div class="card-head">
    <div>
      <div class="card-title">Records</div>
      <div class="card-sub">
        <?php if ($total > count($visible)): ?>
          Showing the first <?= count($visible) ?> of <?= (int) $total ?>
        <?php else: ?>
          <?= (int) $total ?> record<?= $total === 1 ? '' : 's' ?>
        <?php endif; ?>
      </div>
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
      <div class="table-wrap">
        <table class="data wide">
          <thead>
            <tr>
              <th>Record</th><th>Type</th><th>Department</th><th>Status</th><th>Date</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($visible as $record): ?>
              <tr>
                <td>
                  <div class="fw-500 truncate" style="max-width:350px"><?= e($record['_title']) ?></div>
                  <?php if ($record['_person'] !== ''): ?>
                    <div class="card-sub"><?= e($record['_person']) ?></div>
                  <?php endif; ?>
                </td>
                <td><span class="badge badge-neutral"><?= e($record['_type_label']) ?></span></td>
                <td class="faint"><?= e($record['department'] ?? '—') ?></td>
                <td><span class="badge badge-<?= status_class($record['status']) ?>"><?= e($record['status']) ?></span></td>
                <td class="card-sub"><?= e(time_ago($record['created_at'])) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/inc/footer.php'; ?>
