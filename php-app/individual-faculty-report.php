<?php
/**
 * Individual Faculty Achievement Report — Staff-only self-service report & oversight inspection view.
 * Displays faculty details, achievement summary, category breakdown chart, and category-wise record tables.
 * Backend authorization strictly enforced via can_user_view_faculty_report().
 */

require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/models/FacultyAchievement.php';
require_once __DIR__ . '/models/User.php';

$user = require_login();

// Target faculty ID defaults to logged-in user if not supplied
$targetFacultyId = (int) input('id', 0);
if (!$targetFacultyId) {
    $targetFacultyId = (int) $user['id'];
}

// ---- STRICT BACKEND AUTHORIZATION CHECK ----
// Verifies self-access or authorized oversight (Admin/Principal/Director/Dean/HoD of department)
if (!can_user_view_faculty_report($user, $targetFacultyId)) {
    http_response_code(403);
    require __DIR__ . '/denied.php';
    exit;
}

// Filters
$academicYear = trim((string) input('academic_year', '')) ?: active_academic_year();
$category     = trim((string) input('category', '')) ?: null;

// FEAT-02 global active year, kept separate from the filter above: the Dean
// Report (FEAT-05) always covers the active year, whatever this page is
// currently filtered to.
$activeYear = active_academic_year();

// Fetch data
$data    = faculty_achievement_details($targetFacultyId, $academicYear, $category);
$faculty = $data['faculty'];
$records = $data['records'];
$byCat   = $data['by_category'];
$summary = $data['summary'];

if (!$faculty) {
    http_response_code(404);
    $pageTitle = 'Faculty Not Found';
    require __DIR__ . '/inc/header.php';
    echo '<div class="alert alert-danger mt-4">Faculty member record not found.</div>';
    require __DIR__ . '/inc/footer.php';
    exit;
}

$years = academic_years();
$isSelf = (int) $user['id'] === $targetFacultyId;

// Export query links
$exportQ = array_filter([
    'id'            => $targetFacultyId,
    'academic_year' => $academicYear,
    'category'      => $category,
]);
$exportLink = fn(string $fmt) => e(url('export-individual-faculty-report.php')) . '?' . http_build_query($exportQ + ['format' => $fmt]);

$pageTitle  = 'Individual Faculty Achievement Report';
$breadcrumb = $isSelf ? 'My Achievement Report' : 'Faculty Achievement Report';
require __DIR__ . '/inc/header.php';
?>

<!-- Header -->
<div class="page-head">
  <div>
    <h1>Individual Faculty Achievement Report</h1>
    <div class="sub">
      <?= e($faculty['name']) ?> &middot; <?= e($faculty['department']) ?> &middot; Academic Year: <?= e($academicYear ?: 'All Years') ?>
    </div>
  </div>

  <div class="actions flex gap-2 items-center" style="flex-wrap:wrap;">
    <?php if (!$isSelf): ?>
      <a class="btn btn-secondary btn-sm" href="<?= e(url('reports.php')) ?>" title="Return to Reports Hub">
        <?= icon('arrow-left', 14) ?> Back to Reports
      </a>
      <a class="btn btn-secondary btn-sm" href="<?= e(url('faculty-achievements.php')) ?>" title="Go to Performance Matrix">
        <?= icon('award', 14) ?> Performance Matrix
      </a>
    <?php endif; ?>

    <a class="btn btn-secondary btn-sm" href="<?= e(url('individual-faculty-report.php')) ?>?id=<?= $targetFacultyId ?>&academic_year=<?= e($academicYear) ?>" title="Refresh page">
      <?= icon('refresh', 14) ?> Refresh
    </a>

    <a class="btn btn-secondary btn-sm" href="<?= e(url('present-faculty-report.php')) ?>?id=<?= $targetFacultyId ?>&academic_year=<?= e($academicYear) ?>" style="background:#131D3B; color:#ffffff; border-color:#131D3B;" title="Launch Academic Review Presentation Mode">
      <?= icon('play-circle', 14) ?> Present Report
    </a>

    <!-- Export Dropdown -->
    <div class="dropdown-wrap" style="position:relative;">
      <button type="button" class="btn btn-primary btn-sm" onclick="toggleExportMenu(event)">
        <?= icon('download', 14) ?> Export Report <?= icon('chevron-down', 13) ?>
      </button>
      <div id="exportMenu" class="dropdown-menu" style="display:none; position:absolute; right:0; top:100%; margin-top:6px; background:#fff; border:1px solid var(--hairline,#E6EAF2); border-radius:10px; box-shadow:var(--shadow-pop); z-index:999; min-width:180px; padding:6px 0;">
        <a class="dropdown-item" href="<?= $exportLink('excel') ?>" style="display:flex; align-items:center; gap:8px; padding:8px 16px; font-weight:500;">
          <?= icon('download', 14) ?> Export Excel (.xlsx)
        </a>
        <a class="dropdown-item" href="<?= $exportLink('pdf') ?>" target="_blank" rel="noopener" style="display:flex; align-items:center; gap:8px; padding:8px 16px; font-weight:500;">
          <?= icon('file-text', 14) ?> Export PDF
        </a>
        <a class="dropdown-item" href="<?= $exportLink('word') ?>" style="display:flex; align-items:center; gap:8px; padding:8px 16px; font-weight:500;">
          <?= icon('file-text', 14) ?> Export Word (.doc)
        </a>
        <a class="dropdown-item" href="<?= $exportLink('csv') ?>" style="display:flex; align-items:center; gap:8px; padding:8px 16px; font-weight:500;">
          <?= icon('download', 14) ?> Export CSV
        </a>
        <!-- Faculty Details: the A4 document (personal details, achievements,
             document status, declaration, signature) for this same faculty id. -->
        <a class="dropdown-item" href="<?= e(url('faculty-details-report.php')) ?>?id=<?= $targetFacultyId ?>&academic_year=<?= e($academicYear) ?>" target="_blank" rel="noopener" style="display:flex; align-items:center; gap:8px; padding:8px 16px; font-weight:500; border-top:1px solid var(--hairline,#E6EAF2);">
          <?= icon('user', 14) ?> Faculty Details (A4 PDF)
        </a>
      </div>
    </div>
  </div>
</div>

<!-- Faculty Information Card -->
<div class="card mt-4">
  <div class="card-body" style="padding:20px 24px;">
    <div style="display:flex; align-items:center; gap:16px; flex-wrap:wrap;">
      <div class="avatar-dark" style="width:54px; height:54px; border-radius:50%; background:#131D3B; color:#fff; display:grid; place-items:center; font-weight:700; font-size:18px;">
        <?= e(initials($faculty['name'])) ?>
      </div>
      <div style="flex:1; min-width:220px;">
        <div style="font-size:20px; font-weight:700; color:var(--ink,#131D3B);"><?= e($faculty['name']) ?></div>
        <div style="font-size:13px; color:var(--ink-muted,#5A6785); margin-top:2px;">
          <?= e($faculty['designation']) ?> &middot; Department of <?= e($faculty['department']) ?>
        </div>
      </div>
      <div style="display:flex; gap:20px; flex-wrap:wrap; background:var(--navy-50,#F4F6FA); padding:10px 16px; border-radius:10px; border:1px solid var(--hairline,#E4E9F2);">
        <div>
          <div style="font-size:11px; font-weight:700; color:var(--ink-muted,#5A6785); text-transform:uppercase;">Employee ID</div>
          <div style="font-weight:700; color:var(--ink,#131D3B);"><?= e($faculty['employee_id']) ?></div>
        </div>
        <div>
          <div style="font-size:11px; font-weight:700; color:var(--ink-muted,#5A6785); text-transform:uppercase;">Email</div>
          <div style="font-weight:600; color:var(--ink,#131D3B);"><?= e($faculty['email']) ?></div>
        </div>
        <div>
          <div style="font-size:11px; font-weight:700; color:var(--ink-muted,#5A6785); text-transform:uppercase;">Role Scope</div>
          <div style="font-weight:600;"><span class="badge badge-neutral"><?= e($faculty['role']) ?></span></div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Academic Year & Category Filter Bar -->
<form method="get" class="fbar mt-4" id="indivFilterForm">
  <input type="hidden" name="id" value="<?= $targetFacultyId ?>">
  <span class="fbar-title"><?= icon('filter', 14) ?> Filter Report</span>

  <label class="fb-field">
    <span class="fb-k">Academic Year</span>
    <select name="academic_year" onchange="this.form.submit()">
      <option value="">All Academic Years</option>
      <?php foreach ($years as $y): ?>
        <option value="<?= e($y) ?>" <?= $academicYear === $y ? 'selected' : '' ?>><?= e($y) ?></option>
      <?php endforeach; ?>
    </select>
  </label>

  <label class="fb-field">
    <span class="fb-k">Category</span>
    <select name="category" onchange="this.form.submit()">
      <option value="">All Achievement Categories</option>
      <option value="Publications" <?= $category === 'Publications' ? 'selected' : '' ?>>Publications (Journals)</option>
      <option value="Conferences" <?= $category === 'Conferences' ? 'selected' : '' ?>>Conferences</option>
      <option value="Books" <?= $category === 'Books' ? 'selected' : '' ?>>Books / Chapters</option>
      <option value="Events" <?= $category === 'Events' ? 'selected' : '' ?>>Events</option>
      <option value="Training" <?= $category === 'Training' ? 'selected' : '' ?>>Training / FDPs</option>
      <option value="Patents" <?= $category === 'Patents' ? 'selected' : '' ?>>Patents</option>
      <option value="Other" <?= $category === 'Other' ? 'selected' : '' ?>>Other Achievements</option>
    </select>
  </label>

  <span class="fbar-end">
    <a class="fbar-clear" href="<?= e(url('individual-faculty-report.php')) ?>?id=<?= $targetFacultyId ?>"><?= icon('x', 13) ?> Reset Filter</a>
  </span>
</form>

<!-- KPI Summary & Achievement Distribution Chart -->
<div class="mt-4 grid-2-1 gap-5" style="display:grid; grid-template-columns: 1fr 1fr; gap:20px;">
  <!-- Achievement Summary Cards Table -->
  <div class="card">
    <div class="card-head">
      <div>
        <div class="card-title"><?= icon('award', 16) ?> Achievement Summary</div>
        <div class="card-sub">Category submission breakdown for <?= e($academicYear ?: 'all years') ?></div>
      </div>
    </div>
    <div class="card-body pt-0">
      <?php if (empty($summary)): ?>
        <div class="empty">
          <div class="ic"><?= icon('award', 20) ?></div>
          <p>No achievements recorded for this academic year</p>
        </div>
      <?php else: ?>
        <table class="data wide" style="margin-top:10px;">
          <thead>
            <tr>
              <th>Achievement Category</th>
              <th class="num">Submission Count</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($summary as $catName => $cnt): ?>
              <tr>
                <td class="fw-500"><?= e($catName) ?></td>
                <td class="num tabular fw-600"><?= (int) $cnt ?></td>
              </tr>
            <?php endforeach; ?>
            <tr style="background:var(--navy-50,#F4F6FA);">
              <td class="fw-700">Total Achievements</td>
              <td class="num tabular fw-700" style="color:var(--brand,#FF4F01); font-size:15px;"><?= array_sum($summary) ?></td>
            </tr>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </div>

  <!-- Achievement Distribution Chart -->
  <div class="card">
    <div class="card-head">
      <div>
        <div class="card-title"><?= icon('pie', 16) ?> Achievement Distribution</div>
        <div class="card-sub">Visual percentage breakdown by category</div>
      </div>
    </div>
    <div class="card-body">
      <?php if (empty($summary)): ?>
        <div class="empty">
          <p>No data to chart for the selected filters</p>
        </div>
      <?php else: ?>
        <div style="height:220px; position:relative;">
          <canvas id="indivDistChart"></canvas>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- Detailed Achievement Records Category-Wise -->
<div class="mt-5 card">
  <div class="card-head">
    <div>
      <div class="card-title">Category-Wise Detailed Achievements</div>
      <div class="card-sub"><?= count($records) ?> total persisted achievement records</div>
    </div>
  </div>

  <div class="card-body pt-0">
    <?php if (empty($byCat)): ?>
      <div class="empty">
        <div class="ic"><?= icon('reports', 20) ?></div>
        <p>No achievements found</p>
        <div class="note">No achievement records have been submitted for the selected academic year.</div>
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
                  <td class="fw-500" style="max-width:450px;">
                    <div style="color:var(--ink,#131D3B); font-weight:600;"><?= e($item['title']) ?></div>
                    <?php if (!empty($item['raw']['journal_name'])): ?>
                      <div class="card-sub">Journal: <?= e($item['raw']['journal_name']) ?></div>
                    <?php elseif (!empty($item['raw']['publisher_name'])): ?>
                      <div class="card-sub">Publisher: <?= e($item['raw']['publisher_name']) ?></div>
                    <?php elseif (!empty($item['raw']['conference_name'])): ?>
                      <div class="card-sub">Conference: <?= e($item['raw']['conference_name']) ?></div>
                    <?php elseif (!empty($item['raw']['event_title'])): ?>
                      <div class="card-sub">Event: <?= e($item['raw']['event_title']) ?></div>
                    <?php endif; ?>
                  </td>
                  <td class="faint"><?= e($item['department']) ?></td>
                  <td><span class="badge badge-<?= status_class($item['status']) ?>"><?= e($item['status']) ?></span></td>
                  <td class="card-sub"><?= e($item['year']) ?></td>
                  <td class="card-sub"><?= date('d/m/Y', strtotime($item['created_at'])) ?></td>
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

<?php if ($isSelf): ?>
  <!-- FEAT-05: Dean Report — opens the signed-in faculty member's own
       single-page report for the active academic year. Shown only on your own
       report; an oversight viewer looking at someone else's page would get
       their own data, which would be misleading. -->
  <div class="card mt-4 feat5-dean-card">
    <div class="card-body">
      <div class="feat5-dean-row">
        <div>
          <div style="font-weight:600; font-size:14px; color:var(--ink,#131D3B);">Dean Report</div>
          <div class="card-sub" style="margin-top:2px;">
            Open your achievements as a single-page report for the active academic year
            (<?= e($activeYear) ?>), ready to present to the Dean.
          </div>
        </div>
        <a class="btn btn-primary" href="<?= e(url('dean-report.php')) ?>" title="Open my Dean Report">
          <?= icon('file-text', 16) ?> Dean Report
        </a>
      </div>
    </div>
  </div>

  <style>
    .feat5-dean-card { border-left:4px solid var(--brand,#FF4F01); }
    .feat5-dean-row { display:flex; align-items:center; justify-content:space-between; gap:16px 20px; flex-wrap:wrap; }
    .feat5-dean-row > div { flex:1 1 300px; min-width:0; max-width:560px; }
    .feat5-dean-row .btn { flex-shrink:0; white-space:nowrap; }
    @media (max-width:640px){
      .feat5-dean-row { align-items:stretch; }
      .feat5-dean-row > div { max-width:none; }
    }
  </style>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
<script>
function toggleExportMenu(evt) {
  evt.stopPropagation();
  const m = document.getElementById('exportMenu');
  if (m) m.style.display = (m.style.display === 'none' || !m.style.display) ? 'block' : 'none';
}
document.addEventListener('click', () => {
  const m = document.getElementById('exportMenu');
  if (m) m.style.display = 'none';
});

(function() {
  const summaryData = <?= json_encode($summary) ?>;
  const ctx = document.getElementById('indivDistChart');
  if (!ctx || !summaryData || Object.keys(summaryData).length === 0) return;

  const labels = Object.keys(summaryData);
  const dataVals = Object.values(summaryData);
  const colors = ['#FF4F01', '#2563EB', '#059669', '#F59E0B', '#6B7FA8', '#DC2626', '#33456B', '#FF9970'];

  new Chart(ctx, {
    type: 'doughnut',
    data: {
      labels: labels,
      datasets: [{
        data: dataVals,
        backgroundColor: colors.slice(0, labels.length),
        borderWidth: 2,
        borderColor: '#ffffff'
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { position: 'right', labels: { boxWidth: 12, font: { size: 11 } } }
      }
    }
  });
})();
</script>

<?php require __DIR__ . '/inc/footer.php'; ?>
