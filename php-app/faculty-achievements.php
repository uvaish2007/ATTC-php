<?php
/**
 * Consolidated Faculty Achievements — Enterprise reporting and detailed performance analysis.
 * Supports Admin, Principal, Director, Dean, HoD, Coordinator, and Faculty roles with strict DB authorization.
 */

require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/models/FacultyAchievement.php';
require_once __DIR__ . '/models/Department.php';
require_once __DIR__ . '/models/User.php';
require_once __DIR__ . '/models/ExecutiveMeeting.php';   // FEAT-07 EM filter

$user = require_login();

// Handle AJAX detail request for drill-down modal
if (input('ajax') === 'faculty_detail') {
    header('Content-Type: application/json');
    $facId = (int) input('faculty_id');
    $year  = trim((string) input('year')) ?: null;
    $cat   = trim((string) input('category')) ?: null;

    // Verify HoD authorization
    if ($user['role'] === 'HoD') {
        $targetUser = user_find_by_id($facId);
        if (!$targetUser || $targetUser['department'] !== $user['department']) {
            echo json_encode(['error' => 'Unauthorized access to faculty outside your department']);
            exit;
        }
    }

    // FEAT-07: the drill-down honours the same EM1/EM2 filter as the page.
    $data = faculty_achievement_details($facId, $year, $cat, em_filter_window(em_filter_value(input('em')), $year));
    echo json_encode($data);
    exit;
}

// ---- Filters -------------------------------------------------------------
$role        = $user['role'];

// Faculty/Staff role sees only their own individual achievement report
if ($role === 'Faculty') {
    redirect('/individual-faculty-report.php');
}

$isAdmin     = $role === 'Admin';
$isHod       = $role === 'HoD';
$isDirector  = in_array($role, ['Director', 'Principal'], true);
$isDean      = $role === 'Dean';
$isOversight = $isAdmin || $isDirector || $isDean;

// Resolve effective department filter (HoD is locked to their own department)
$rawDept      = trim((string) input('department', ''));
$department   = resolve_faculty_achievement_scope($user, $rawDept);
$academicYear = trim((string) input('academic_year', '')) ?: active_academic_year();
$category     = trim((string) input('category', '')) ?: null;
$facultyId    = (int) input('faculty_id', 0) ?: null;
$searchQuery  = trim((string) input('q', ''));

// FEAT-07: Executive Meeting filter. The EM engine turns it into a date window
// that every query below applies in SQL; "all" leaves them unchanged.
$em       = em_filter_value(input('em'));
$emWindow = em_filter_window($em, $academicYear ?: null);

// Fetch departments and years
$departments = departments_all();
$years       = academic_years();
$allCategories = faculty_achievement_categories();

// Fetch faculty list for filter dropdown (scoped to chosen department if any)
$facParams = [];
$facSql = "SELECT id, name, department FROM users WHERE role IN ('Faculty', 'Coordinator', 'HoD')";
if ($department) {
    $facSql .= " AND department = ?";
    $facParams[] = $department;
}
$facSql .= " ORDER BY name ASC";
$facStmt = db()->prepare($facSql);
$facStmt->execute($facParams);
$facultyList = $facStmt->fetchAll();

// ---- Fetch Data ---------------------------------------------------------
$summary       = faculty_achievements_summary($user, $department, $academicYear, $category, $facultyId, $emWindow);
$deptComp      = department_achievements_comparison($user, $academicYear, $category, $emWindow);
$facGrid       = faculty_achievements_grid($user, $department, $academicYear, $category, $facultyId, $searchQuery, $emWindow);
$topContributors = top_faculty_contributors($user, $department, $academicYear, $category, 5, $emWindow);

// Query string for exports
$exportQ = array_filter([
    'department'    => $department,
    'academic_year' => $academicYear,
    'category'      => $category,
    'faculty_id'    => $facultyId,
    'em'            => $em !== 'all' ? $em : null,   // FEAT-07
]);
$exportLink = fn(string $fmt) => e(url('export-faculty-achievements.php') . '?' . http_build_query($exportQ + ['format' => $fmt]));

$pageTitle  = 'Consolidated Faculty Achievements';
$breadcrumb = 'Consolidated Faculty Achievements';
require __DIR__ . '/inc/header.php';
?>

<!-- Header -->
<div class="page-head">
  <div>
    <h1>Consolidated Faculty Achievements</h1>
    <div class="sub">
      Institution-wide faculty achievement overview and detailed performance analysis
      &middot; <?= $department ? e($department) : ($isHod ? e($user['department']) : 'All Departments') ?>
      &middot; Academic Year: <?= e($academicYear ?: 'All Years') ?>
    </div>
  </div>

  <div class="actions flex gap-2 items-center" style="flex-wrap:wrap;">
    <a class="btn btn-secondary btn-sm" href="<?= e(url('faculty-achievements.php')) ?>" title="Refresh data">
      <?= icon('refresh', 14) ?> Refresh
    </a>

    <!-- Export Dropdown -->
    <div class="dropdown-wrap" style="position:relative;">
      <button type="button" class="btn btn-primary btn-sm" onclick="toggleExportMenu(event)">
        <?= icon('download', 14) ?> Export Report <?= icon('chevron-down', 13) ?>
      </button>
      <div id="exportMenu" class="dropdown-menu" style="display:none; position:absolute; right:0; top:100%; margin-top:6px; background:#fff; border:1px solid var(--hairline,#E6EAF2); border-radius:10px; box-shadow:var(--shadow-pop); z-index:999; min-width:180px; padding:6px 0;">
        <a class="dropdown-item" href="<?= $exportLink('excel') ?>" style="display:flex; align-items:center; gap:8px; padding:8px 16px; font-weight:500;">
          <?= icon('download', 14) ?> Excel (.xlsx)
        </a>
        <a class="dropdown-item" href="<?= $exportLink('pdf') ?>" target="_blank" rel="noopener" style="display:flex; align-items:center; gap:8px; padding:8px 16px; font-weight:500;">
          <?= icon('file-text', 14) ?> PDF Report
        </a>
        <a class="dropdown-item" href="<?= $exportLink('word') ?>" style="display:flex; align-items:center; gap:8px; padding:8px 16px; font-weight:500;">
          <?= icon('file-text', 14) ?> Word (.doc)
        </a>
        <a class="dropdown-item" href="<?= $exportLink('csv') ?>" style="display:flex; align-items:center; gap:8px; padding:8px 16px; font-weight:500;">
          <?= icon('download', 14) ?> CSV File
        </a>
      </div>
    </div>
  </div>
</div>

<!-- KPI Summary Cards -->
<div class="stat-grid grid-4 mt-4">
  <div class="stat">
    <div class="stat-top">
      <div class="stat-label">Total Faculty</div>
      <div class="stat-ic navy"><?= icon('users') ?></div>
    </div>
    <div class="stat-value tabular"><?= number_format($summary['totalFaculty']) ?></div>
    <div class="stat-desc">Faculty in scope</div>
  </div>

  <div class="stat">
    <div class="stat-top">
      <div class="stat-label">Total Achievements</div>
      <div class="stat-ic brand"><?= icon('award') ?></div>
    </div>
    <div class="stat-value tabular"><?= number_format($summary['totalAchievements']) ?></div>
    <div class="stat-desc">Persisted achievement records</div>
  </div>

  <div class="stat">
    <div class="stat-top">
      <div class="stat-label">Departments</div>
      <div class="stat-ic navy"><?= icon('building') ?></div>
    </div>
    <div class="stat-value tabular"><?= (int) $summary['departments'] ?></div>
    <div class="stat-desc">Active academic departments</div>
  </div>

  <div class="stat">
    <div class="stat-top">
      <div class="stat-label">Top Category</div>
      <div class="stat-ic brand"><?= icon('layers') ?></div>
    </div>
    <div class="stat-value truncate" style="font-size:18px;" title="<?= e($summary['topCategory']) ?>">
      <?= e($summary['topCategory']) ?>
    </div>
    <div class="stat-desc">Highest submission volume</div>
  </div>
</div>

<!-- Professional Filter Toolbar -->
<form method="get" class="fbar mt-5" id="filterForm">
  <span class="fbar-title"><?= icon('filter', 14) ?> Filters</span>

  <!-- Academic Year -->
  <label class="fb-field">
    <span class="fb-k">Academic Year</span>
    <select name="academic_year" onchange="this.form.submit()">
      <option value="">All Years</option>
      <?php foreach ($years as $y): ?>
        <option value="<?= e($y) ?>" <?= $academicYear === $y ? 'selected' : '' ?>><?= e($y) ?></option>
      <?php endforeach; ?>
    </select>
  </label>

  <!-- Department -->
  <?php if ($isHod): ?>
    <label class="fb-field" title="Locked to your assigned department">
      <span class="fb-k">Department</span>
      <select disabled><option selected><?= e($user['department']) ?></option></select>
      <input type="hidden" name="department" value="<?= e($user['department']) ?>">
    </label>
  <?php else: ?>
    <label class="fb-field">
      <span class="fb-k">Department</span>
      <select name="department" onchange="this.form.submit()">
        <option value="">All Departments</option>
        <?php foreach ($departments as $d): ?>
          <option value="<?= e($d['name']) ?>" <?= $department === $d['name'] ? 'selected' : '' ?>><?= e($d['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
  <?php endif; ?>

  <!-- Faculty -->
  <label class="fb-field">
    <span class="fb-k">Faculty</span>
    <select name="faculty_id" onchange="this.form.submit()">
      <option value="">All Faculty</option>
      <?php foreach ($facultyList as $f): ?>
        <option value="<?= (int) $f['id'] ?>" <?= $facultyId === (int) $f['id'] ? 'selected' : '' ?>>
          <?= e($f['name']) ?> (<?= e($f['department']) ?>)
        </option>
      <?php endforeach; ?>
    </select>
  </label>

  <!-- Achievement Category -->
  <label class="fb-field">
    <span class="fb-k">Category</span>
    <select name="category" onchange="this.form.submit()">
      <option value="">All Categories</option>
      <option value="Publications" <?= $category === 'Publications' ? 'selected' : '' ?>>Publications (Journals)</option>
      <option value="Conferences" <?= $category === 'Conferences' ? 'selected' : '' ?>>Conferences</option>
      <option value="Books" <?= $category === 'Books' ? 'selected' : '' ?>>Books / Chapters</option>
      <option value="Events" <?= $category === 'Events' ? 'selected' : '' ?>>Events</option>
      <option value="Training" <?= $category === 'Training' ? 'selected' : '' ?>>Training / FDPs</option>
      <option value="Patents" <?= $category === 'Patents' ? 'selected' : '' ?>>Patents</option>
      <option value="Other" <?= $category === 'Other' ? 'selected' : '' ?>>Other Achievements</option>
    </select>
  </label>

  <!-- Executive Meeting (FEAT-07) -->
  <label class="fb-field" title="Achievements submitted during EM1 or EM2">
    <span class="fb-k">Meeting</span>
    <select name="em" onchange="this.form.submit()">
      <option value="all" <?= $em === 'all' ? 'selected' : '' ?>>All</option>
      <?php foreach (EM_MEETINGS as $emKey => $emName): ?>
        <option value="<?= e($emKey) ?>" <?= $em === $emKey ? 'selected' : '' ?>><?= e(em_filter_label($emKey, $academicYear ?: null)) ?></option>
      <?php endforeach; ?>
    </select>
  </label>

  <span class="fbar-end">
    <a class="btn btn-primary btn-sm" href="javascript:void(0)" onclick="document.getElementById('filterForm').submit()">Apply</a>
    <a class="fbar-clear" href="<?= e(url('faculty-achievements.php')) ?>"><?= icon('x', 13) ?> Reset</a>
  </span>
</form>

<!-- Analytics & Leaderboard Section (Admin, Principal, Dean, HoD) -->
<div class="mt-5 grid-2-1 gap-5" style="display:grid; grid-template-columns: 2fr 1fr; gap:20px;">
  <!-- Department-wise Faculty Achievements Comparison -->
  <div class="card">
    <div class="card-head">
      <div>
        <div class="card-title"><?= icon('bar-chart', 16) ?> Department Performance Analysis</div>
        <div class="card-sub">Comparison of faculty achievements across departments</div>
      </div>
    </div>
    <div class="card-body">
      <?php if (empty($deptComp) || array_sum(array_column($deptComp, 'total')) === 0): ?>
        <div class="empty">
          <div class="ic"><?= icon('bar-chart', 20) ?></div>
          <p>No achievements found for the selected filters</p>
          <div class="note">Try adjusting the department, academic year, or category filters.</div>
        </div>
      <?php else: ?>
        <div style="height:280px; position:relative;">
          <canvas id="deptCompChart"></canvas>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Highest Achievement Contributors (Leaderboard) -->
  <div class="card">
    <div class="card-head">
      <div>
        <div class="card-title"><?= icon('award', 16) ?> Highest Achievement Counts</div>
        <div class="card-sub">Top faculty contributors in selected scope</div>
      </div>
    </div>
    <div class="card-body pt-0">
      <?php if (empty($topContributors) || $topContributors[0]['total'] === 0): ?>
        <div class="empty">
          <p>No contributors found</p>
        </div>
      <?php else: ?>
        <div style="display:flex; flex-direction:column; gap:12px; margin-top:10px;">
          <?php foreach ($topContributors as $idx => $top): ?>
            <div style="display:flex; align-items:center; justify-content:space-between; padding:10px 12px; background:var(--navy-50,#F4F6FA); border:1px solid var(--hairline,#E4E9F2); border-radius:10px; cursor:pointer;" onclick="openFacultyModal(<?= $top['id'] ?>)">
              <div style="display:flex; align-items:center; gap:10px; min-width:0;">
                <div style="width:26px; height:26px; border-radius:50%; background:<?= $idx === 0 ? '#FF4F01' : '#131D3B' ?>; color:#fff; display:grid; place-items:center; font-weight:700; font-size:12px; flex-shrink:0;">
                  <?= $idx + 1 ?>
                </div>
                <div style="min-width:0;">
                  <div class="fw-500 truncate" style="font-size:13px;"><?= e($top['name']) ?></div>
                  <div class="card-sub truncate" style="font-size:11px;"><?= e($top['department']) ?></div>
                </div>
              </div>
              <div class="badge badge-neutral" style="font-size:12px; font-weight:700; background:#fff; border:1px solid var(--hairline,#E4E9F2);">
                <?= (int) $top['total'] ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- Main Faculty Achievement Table Card -->
<div class="mt-5 card">
  <div class="card-head">
    <div>
      <div class="card-title">Faculty Achievement Performance Matrix</div>
      <div class="card-sub"><?= count($facGrid) ?> faculty members &middot; Click any row to view full detailed records</div>
    </div>

    <!-- Quick Search Input -->
    <form method="get" class="fbar fbar-bare" style="margin:0;">
      <input type="hidden" name="academic_year" value="<?= e($academicYear) ?>">
      <input type="hidden" name="department" value="<?= e($department) ?>">
      <input type="hidden" name="category" value="<?= e($category) ?>">
      <input type="hidden" name="em" value="<?= e($em) ?>">
      <label class="fb-field fb-search" style="min-width:240px;">
        <?= icon('search', 14) ?>
        <input type="search" name="q" value="<?= e($searchQuery) ?>" placeholder="Search faculty name…" onchange="this.form.submit()">
      </label>
    </form>
  </div>

  <div class="card-body pt-0">
    <?php if (empty($facGrid)): ?>
      <div class="empty">
        <div class="ic"><?= icon('users', 20) ?></div>
        <p>No faculty achievements found</p>
        <div class="note">Try changing the selected department, academic year, faculty, or category filters.</div>
      </div>
    <?php else: ?>
      <div class="table-wrap" style="max-height: 520px; overflow-y: auto;">
        <table class="data wide sortable" id="facTable">
          <thead style="position:sticky; top:0; z-index:10; background:var(--surface,#fff);">
            <tr>
              <th>#</th>
              <th>Faculty Member</th>
              <th>Department</th>
              <th class="num">Journals</th>
              <th class="num">Conferences</th>
              <th class="num">Books</th>
              <th class="num">Events</th>
              <th class="num">Training</th>
              <th class="num">Patents</th>
              <th class="num">Other</th>
              <th class="num">Total</th>
              <th style="text-align:center;">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php
              $groupedGrid = [];
              foreach ($facGrid as $f) {
                  $deptName = $f['department'] ?: 'Other Department';
                  $groupedGrid[$deptName][] = $f;
              }
              $sno = 1;
            ?>
            <?php foreach ($groupedGrid as $deptName => $deptFaculty): ?>
              <tr style="background:var(--navy-50,#F4F6FA); border-top:2px solid var(--hairline,#E4E9F2); border-bottom:1px solid var(--hairline,#E4E9F2);">
                <td colspan="12" style="padding:10px 14px; font-weight:700; color:var(--ink,#131D3B); font-size:13.5px;">
                  <?= icon('building', 14) ?> Department: <?= e($deptName) ?>
                  <span class="badge badge-neutral" style="margin-left:6px; font-size:11px;"><?= count($deptFaculty) ?> faculty</span>
                </td>
              </tr>
              <?php foreach ($deptFaculty as $f): ?>
                <tr class="drill-row" onclick="location.href='individual-faculty-report.php?id=<?= (int) $f['id'] ?>'" style="cursor:pointer;" title="Click to view <?= e($f['name']) ?>'s Individual Faculty Achievement Report">
                  <td class="faint tabular"><?= $sno++ ?></td>
                  <td>
                    <div class="fw-500 truncate" style="max-width:220px; font-weight:600; color:var(--ink,#131D3B);"><?= e($f['name']) ?></div>
                    <div class="card-sub truncate" style="font-size:11px;"><?= e($f['designation']) ?> &middot; <?= e($f['employee_id']) ?></div>
                  </td>
                  <td class="faint"><?= e($f['department']) ?></td>
                  <td class="num tabular"><?= (int) $f['journals'] ?></td>
                  <td class="num tabular"><?= (int) $f['conferences'] ?></td>
                  <td class="num tabular"><?= (int) $f['books'] ?></td>
                  <td class="num tabular"><?= (int) $f['events'] ?></td>
                  <td class="num tabular"><?= (int) $f['training'] ?></td>
                  <td class="num tabular"><?= (int) $f['patents'] ?></td>
                  <td class="num tabular"><?= (int) $f['other'] ?></td>
                  <td class="num tabular fw-600" style="font-size:14px; color:var(--brand,#FF4F01);"><?= (int) $f['total'] ?></td>
                  <td style="text-align:center; white-space:nowrap;" onclick="event.stopPropagation();">
                    <div style="display:inline-flex; align-items:center; gap:6px;">
                      <a href="<?= e(url('individual-faculty-report.php')) ?>?id=<?= (int) $f['id'] ?>" class="btn btn-primary btn-sm" style="border-radius:999px; padding:4px 10px; font-size:11.5px; display:inline-flex; align-items:center; gap:4px;">
                        <?= icon('file-text', 13) ?> View Report
                      </a>
                      <a href="<?= e(url('present-faculty-report.php')) ?>?id=<?= (int) $f['id'] ?><?= !empty($academicYear) ? '&academic_year=' . urlencode($academicYear) : '' ?>" class="btn btn-secondary btn-sm" style="border-radius:999px; padding:4px 10px; font-size:11.5px; display:inline-flex; align-items:center; gap:4px; background:#131D3B; color:#ffffff; border:1px solid #131D3B;">
                        <?= icon('play-circle', 13) ?> Present
                      </a>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- Faculty Detail Drill-Down Modal -->
<div id="facultyModal" class="modal-backdrop" style="display:none; position:fixed; inset:0; background:rgba(19,29,59,0.5); z-index:99999; backdrop-filter:blur(3px); align-items:center; justify-content:center; padding:20px;">
  <div class="modal-card" style="background:#fff; border-radius:16px; max-width:850px; width:100%; max-height:85vh; display:flex; flex-direction:column; box-shadow:var(--shadow-pop); border:1px solid var(--hairline,#E6EAF2); animation:pop-in .2s var(--ease-out);">
    <!-- Modal Header -->
    <div style="padding:16px 24px; border-bottom:1px solid var(--hairline,#E6EAF2); display:flex; align-items:center; justify-content:space-between; background:var(--navy-50,#F4F6FA); border-radius:16px 16px 0 0;">
      <div style="display:flex; align-items:center; gap:12px;">
        <div class="avatar-dark" id="modalAvatar" style="width:40px; height:40px; border-radius:50%; background:#131D3B; color:#fff; display:grid; place-items:center; font-weight:700;">--</div>
        <div>
          <div id="modalName" style="font-size:17px; font-weight:700; color:#131D3B;">Faculty Name</div>
          <div id="modalSub" style="font-size:12px; color:#5A6785;">Department &middot; Designation</div>
        </div>
      </div>
      <button type="button" onclick="closeFacultyModal()" style="border:0; background:none; cursor:pointer; padding:6px; color:#5A6785;" title="Close dialog">
        <?= icon('x', 20) ?>
      </button>
    </div>

    <!-- Modal Content Body -->
    <div style="padding:20px 24px; overflow-y:auto; flex:1;" id="modalBody">
      <div class="empty" id="modalLoading">
        <div class="ic"><?= icon('refresh', 20) ?></div>
        <p>Loading faculty achievements...</p>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
<script>
// Toggle export menu dropdown
function toggleExportMenu(evt) {
  evt.stopPropagation();
  const m = document.getElementById('exportMenu');
  if (m) m.style.display = (m.style.display === 'none' || !m.style.display) ? 'block' : 'none';
}
document.addEventListener('click', () => {
  const m = document.getElementById('exportMenu');
  if (m) m.style.display = 'none';
});

// Render Department Comparison Bar Chart
(function() {
  const deptData = <?= json_encode($deptComp) ?>;
  const ctx = document.getElementById('deptCompChart');
  if (!ctx || !deptData || deptData.length === 0) return;

  const labels = deptData.map(d => d.department);
  const totals = deptData.map(d => d.total);

  new Chart(ctx, {
    type: 'bar',
    data: {
      labels: labels,
      datasets: [{
        label: 'Total Achievements',
        data: totals,
        backgroundColor: '#FF4F01',
        borderRadius: 6,
        maxBarThickness: 40,
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { display: false },
        tooltip: {
          callbacks: {
            label: (ctx) => ` Achievements: ${ctx.raw}`
          }
        }
      },
      scales: {
        x: { grid: { display: false } },
        y: { beginAtZero: true, grid: { color: '#E7EBF3' }, ticks: { stepSize: 1 } }
      }
    }
  });
})();

// Faculty Detail Modal Logic
function openFacultyModal(facId) {
  const modal = document.getElementById('facultyModal');
  const body = document.getElementById('modalBody');
  const nameEl = document.getElementById('modalName');
  const subEl = document.getElementById('modalSub');
  const avatarEl = document.getElementById('modalAvatar');

  if (!modal || !body) return;
  modal.style.display = 'flex';
  body.innerHTML = '<div class="empty"><div class="ic"><?= icon('refresh', 20) ?></div><p>Loading faculty achievements...</p></div>';

  fetch('<?= e(url('faculty-achievements.php')) ?>?ajax=faculty_detail&faculty_id=' + facId + '&year=<?= e($academicYear) ?>&em=<?= e($em) ?>')
    .then(res => res.json())
    .then(data => {
      if (!data || !data.faculty) {
        body.innerHTML = '<div class="empty"><p>Unable to load details.</p></div>';
        return;
      }
      const f = data.faculty;
      nameEl.textContent = f.name;
      subEl.textContent = `${f.department} · ${f.designation} (${f.employee_id})`;
      avatarEl.textContent = f.name.split(' ').map(n=>n[0]).join('').substring(0,2).toUpperCase();

      let html = '<div style="display:flex; flex-wrap:wrap; gap:8px; margin-bottom:20px;">';
      if (Object.keys(data.summary).length === 0) {
        html += '<span class="badge badge-neutral">No achievement records found</span>';
      } else {
        for (const [k, v] of Object.entries(data.summary)) {
          html += `<span class="badge badge-neutral" style="font-size:12px; padding:4px 10px;"><strong>${k}:</strong> ${v}</span>`;
        }
      }
      html += '</div>';

      if (!data.records || data.records.length === 0) {
        html += '<div class="empty"><p>No detailed achievement records found for this faculty member.</p></div>';
      } else {
        html += `
          <table class="data wide" style="font-size:12.5px;">
            <thead>
              <tr>
                <th>Category</th>
                <th>Title / Description</th>
                <th>Status</th>
                <th>Date</th>
              </tr>
            </thead>
            <tbody>
        `;
        data.records.forEach(r => {
          html += `
            <tr>
              <td><span class="badge badge-neutral" style="font-size:11px;">${r.category}</span></td>
              <td class="fw-500">${r.title}</td>
              <td><span class="badge badge-success">${r.status}</span></td>
              <td class="card-sub">${r.year}</td>
            </tr>
          `;
        });
        html += '</tbody></table>';
      }
      body.innerHTML = html;
    })
    .catch(err => {
      body.innerHTML = '<div class="empty"><p>Error loading faculty records. Please try again.</p></div>';
    });
}

function closeFacultyModal() {
  const modal = document.getElementById('facultyModal');
  if (modal) modal.style.display = 'none';
}
</script>

<?php require __DIR__ . '/inc/footer.php'; ?>
