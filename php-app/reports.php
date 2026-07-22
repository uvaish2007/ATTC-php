<?php
/**
 * Reports page.
 *
 * Shows every record the user is allowed to see, with filters for
 * department, status and record type, and buttons to download the list
 * as Excel, Word or CSV (see export.php).
 */

require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/models/Record.php';
require_once __DIR__ . '/models/Department.php';

$user = require_login();

// Admin and Director can pick any department; everyone else is fixed to theirs.
$isOversight = in_array($user['role'], ['Admin', 'Director'], true);

// ---- Filters chosen by the user -----------------------------------------
$department = trim((string) input('department', '')) ?: null;
$status     = trim((string) input('status', ''))     ?: null;
$type       = trim((string) input('type', ''))       ?: null;

// ---- Fetch the records (the model applies the role's scope) --------------
$records = report_records($user, $department, $status, $type);

$types       = record_types();
$departments = departments_all();

// ---- Count how many are in each status ----------------------------------
$counts = ['Draft' => 0, 'Submitted' => 0, 'Approved' => 0, 'Rejected' => 0];

foreach ($records as $record) {
    if (isset($counts[$record['status']])) {
        $counts[$record['status']]++;
    }
}

$total = count($records);

// Keep the current filters when linking to a download.
$exportQuery = http_build_query(array_filter([
    'department' => $isOversight ? $department : null,
    'status'     => $status,
    'type'       => $type,
]));

// Only the first 50 are listed on screen; downloads include everything.
$visible   = array_slice($records, 0, 50);
$scopeName = $isOversight ? ($department ?: 'All departments') : ($user['department'] ?: 'All departments');

$pageTitle  = 'Reports';
$breadcrumb = 'Reports';
require __DIR__ . '/inc/header.php';
?>

<div class="page-head">
  <div>
    <h1>Reports</h1>
    <div class="sub"><?= (int) $total ?> records &middot; <?= e($scopeName) ?></div>
  </div>

  <!-- Changing a dropdown reloads the page with that filter -->
  <div class="actions">
    <form method="get" class="flex gap-2 items-center">

      <?php if ($isOversight): ?>
        <select class="select" name="department" onchange="this.form.submit()">
          <option value="">All departments</option>
          <?php foreach ($departments as $dept): ?>
            <option value="<?= e($dept['name']) ?>" <?= $department === $dept['name'] ? 'selected' : '' ?>>
              <?= e($dept['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      <?php endif; ?>

      <select class="select" name="status" onchange="this.form.submit()">
        <option value="">All statuses</option>
        <?php foreach (['Approved', 'Submitted', 'Draft', 'Rejected'] as $option): ?>
          <option value="<?= $option ?>" <?= $status === $option ? 'selected' : '' ?>>
            <?= $option ?>
          </option>
        <?php endforeach; ?>
      </select>

      <select class="select" name="type" onchange="this.form.submit()">
        <option value="">All types</option>
        <?php foreach ($types as $key => $t): ?>
          <option value="<?= e($key) ?>" <?= $type === $key ? 'selected' : '' ?>>
            <?= e($t['label']) ?>
          </option>
        <?php endforeach; ?>
      </select>

    </form>
  </div>
</div>


<!-- Counters -->
<div class="stat-grid grid-4">

  <?php
    $cards = [
        ['Total',    $total,                'layers', 'brand'],
        ['Approved', $counts['Approved'],   'check',  'navy'],
        ['Pending',  $counts['Submitted'],  'clock',  $counts['Submitted'] ? 'brand' : 'navy'],
        ['Rejected', $counts['Rejected'],   'x',      'navy'],
    ];
  ?>

  <?php foreach ($cards as [$label, $value, $iconName, $tone]): ?>
    <div class="stat">
      <div class="stat-top">
        <div class="stat-label"><?= e($label) ?></div>
        <div class="stat-ic <?= $tone ?>"><?= icon($iconName) ?></div>
      </div>
      <div class="stat-value tabular"><?= (int) $value ?></div>
    </div>
  <?php endforeach; ?>

</div>


<!-- Download buttons: they carry the filters shown above -->
<div class="mt-5 card">
  <div class="card-head">
    <div>
      <div class="card-title">Download this report</div>
      <div class="card-sub">Includes all <?= (int) $total ?> records, in the IQAC format</div>
    </div>
  </div>

  <div class="card-body flex gap-2 items-center">
    <a class="btn btn-primary"  href="export.php?format=excel&amp;<?= e($exportQuery) ?>"><?= icon('reports') ?> Excel</a>
    <a class="btn btn-outline"  href="export.php?format=word&amp;<?= e($exportQuery) ?>"><?= icon('reports') ?> Word</a>
    <a class="btn btn-outline"  href="export.php?format=csv&amp;<?= e($exportQuery) ?>"><?= icon('reports') ?> CSV</a>
    <button type="button" class="btn btn-outline" onclick="window.print()"><?= icon('reports') ?> Print / PDF</button>
  </div>
</div>


<!-- The records themselves -->
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
              <th>Record</th>
              <th>Type</th>
              <th>Department</th>
              <th>Status</th>
              <th>Date</th>
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
                <td>
                  <span class="badge badge-<?= status_class($record['status']) ?>">
                    <?= e($record['status']) ?>
                  </span>
                </td>
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
