<?php
/**
 * Consolidated Faculty Achievements — Enterprise reporting and detailed performance analysis.
 * Supports Admin, Principal, Director, Dean, HoD, Coordinator, and Faculty roles with strict DB authorization.
 * Features Data View vs Analytics View toggles across Department Performance Analysis & Category Metrics.
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

// ---- Filters & Scope -------------------------------------------------------------
$role        = $user['role'];
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
$departments   = departments_all();
$years         = academic_years();
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
$summary         = faculty_achievements_summary($user, $department, $academicYear, $category, $facultyId, $emWindow ?? null);
$deptComp        = department_achievements_comparison($user, $academicYear, $category, $emWindow ?? null);
$facGrid         = faculty_achievements_grid($user, $department, $academicYear, $category, $facultyId, $searchQuery, $emWindow ?? null);
$topContributors = top_faculty_contributors($user, $department, $academicYear, $category, 5, $emWindow ?? null);

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

<style>
  /* Toggle View Pill Group */
  .pill-toggle-group {
    display: inline-flex;
    align-items: center;
    background: var(--navy-50, #F4F6FA);
    border: 1px solid var(--hairline, #E4E9F2);
    border-radius: 999px;
    padding: 3px;
    gap: 2px;
  }
  .btn-pill {
    border-radius: 999px;
    padding: 5px 16px;
    font-size: 12px;
    font-weight: 700;
    border: 0;
    cursor: pointer;
    background: transparent;
    color: var(--muted, #5A6785);
    transition: all 0.2s ease;
  }
  .btn-pill.active {
    background: var(--navy, #131D3B);
    color: #ffffff;
    box-shadow: 0 2px 4px rgba(19,29,59,0.15);
  }
  .btn-pill:hover:not(.active) {
    color: var(--ink, #131D3B);
    background: rgba(0,0,0,0.03);
  }

  /* Category Cards Grid */
  .cat-cards-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 16px;
  }
  .cat-card {
    background: #ffffff;
    border: 1px solid var(--hairline, #E4E9F2);
    border-radius: 12px;
    padding: 16px;
    display: flex;
    align-items: center;
    gap: 14px;
    transition: all 0.2s ease;
  }
  .cat-card:hover {
    border-color: var(--brand, #FF4F01);
    transform: translateY(-2px);
    box-shadow: var(--shadow-sm, 0 4px 12px rgba(0,0,0,0.05));
  }
  .cat-card-ic {
    width: 44px;
    height: 44px;
    border-radius: 10px;
    display: grid;
    place-items: center;
    flex-shrink: 0;
  }
  .cat-card-ic.blue { background: #EBF5FF; color: #0066CC; }
  .cat-card-ic.green { background: #ECFDF5; color: #059669; }
  .cat-card-ic.orange { background: #FFF3EC; color: #FF4F01; }
  .cat-card-ic.purple { background: #F3E8FF; color: #7E22CE; }
  .cat-card-ic.red { background: #FEF2F2; color: #DC2626; }
  .cat-card-ic.teal { background: #CCFBF1; color: #0D9488; }
  .cat-card-ic.gray { background: #F1F5F9; color: #475569; }

  .cat-card-title { font-size: 12.5px; font-weight: 700; color: var(--muted, #5A6785); }
  .cat-card-val { font-size: 24px; font-weight: 800; color: var(--ink, #131D3B); margin-top: 2px; }
</style>

<!-- Page Header -->
<div class="page-head">
  <div>
    <h1>Consolidated Faculty Achievements</h1>
    <div class="sub">
      Institution-wide overview &middot; <?= $department ? e($department) : ($isHod ? e($user['department']) : 'All departments') ?>
      &middot; Academic Year: <?= e($academicYear ?: 'All Years') ?>
    </div>
  </div>

  <div class="actions flex gap-2 items-center" style="flex-wrap:wrap;">
    <!-- Export Dropdown -->
    <div class="dropdown-wrap" style="position:relative;">
      <button type="button" class="btn btn-secondary btn-sm" onclick="toggleExportMenu(event)">
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

    <!-- View Reports Primary Button -->
    <a href="<?= e(url('reports.php')) ?>" class="btn btn-primary btn-sm" style="background:#FF4F01; border-color:#FF4F01; padding:6px 16px; font-weight:700;">
      <?= icon('reports', 14) ?> View Reports
    </a>
  </div>
</div>

<!-- 6 KPI Stat Cards Row -->
<div class="stat-grid grid-6 mt-4" style="display:grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap:14px;">
  <!-- 1. Total Records -->
  <div class="stat">
    <div class="stat-top">
      <div class="stat-label">Total Records</div>
      <div class="stat-ic brand"><?= icon('file-stack', 16) ?></div>
    </div>
    <div class="stat-value tabular"><?= number_format($summary['totalAchievements']) ?></div>
    <div class="stat-desc"><?= number_format($summary['approvedRecords']) ?> approved &middot; <?= number_format($summary['pendingRecords']) ?> pending</div>
  </div>

  <!-- 2. Approval Rate -->
  <div class="stat">
    <div class="stat-top">
      <div class="stat-label">Approval Rate</div>
      <div class="stat-ic navy"><?= icon('check', 16) ?></div>
    </div>
    <div class="stat-value tabular"><?= (int) $summary['approvalRate'] ?>%</div>
    <div class="stat-desc"><?= number_format($summary['approvedRecords']) ?> of <?= number_format($summary['totalAchievements']) ?> approved</div>
  </div>

  <!-- 3. Pending Review -->
  <div class="stat">
    <div class="stat-top">
      <div class="stat-label">Pending Review</div>
      <div class="stat-ic brand"><?= icon('clock', 16) ?></div>
    </div>
    <div class="stat-value tabular"><?= number_format($summary['pendingRecords']) ?></div>
    <div class="stat-desc">Awaiting review</div>
  </div>

  <!-- 4. Target Attainment -->
  <div class="stat">
    <div class="stat-top">
      <div class="stat-label">Target Attainment</div>
      <div class="stat-ic navy"><?= icon('target', 16) ?></div>
    </div>
    <div class="stat-value tabular"><?= (int) $summary['targetAttainment'] ?>%</div>
    <div class="stat-desc"><?= number_format($summary['targetsMet']) ?> of <?= number_format($summary['totalTargets']) ?> targets met</div>
  </div>

  <!-- 5. Departments -->
  <div class="stat">
    <div class="stat-top">
      <div class="stat-label">Departments</div>
      <div class="stat-ic brand"><?= icon('building', 16) ?></div>
    </div>
    <div class="stat-value tabular"><?= (int) $summary['departments'] ?></div>
    <div class="stat-desc">Active departments</div>
  </div>

  <!-- 6. Team -->
  <div class="stat">
    <div class="stat-top">
      <div class="stat-label">Team</div>
      <div class="stat-ic navy"><?= icon('users', 16) ?></div>
    </div>
    <div class="stat-value tabular"><?= (int) $summary['registeredAccounts'] ?></div>
    <div class="stat-desc">Registered accounts</div>
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

<!-- Section 1: Department Performance Analysis (With Data View / Analytics View Toggle) -->
<div class="mt-5 card">
  <div class="card-head" style="display:flex; align-items:center; justify-content:space-between;">
    <div>
      <div class="card-title"><?= icon('bar-chart', 16) ?> Department Performance Analysis</div>
      <div class="card-sub">Comparison of faculty achievements across departments</div>
    </div>

    <!-- Toggle Buttons: Data View | Analytics View -->
    <div class="pill-toggle-group">
      <button type="button" class="btn-pill" id="btnDeptData" onclick="switchDeptView('data')">Data View</button>
      <button type="button" class="btn-pill active" id="btnDeptAnalytics" onclick="switchDeptView('analytics')">Analytics View</button>
    </div>
  </div>

  <div class="card-body">
    <!-- 1A. Analytics View: Chart.js Bar Chart -->
    <div id="deptAnalyticsView">
      <?php if (empty($deptComp) || array_sum(array_column($deptComp, 'total')) === 0): ?>
        <div class="empty">
          <div class="ic"><?= icon('bar-chart', 20) ?></div>
          <p>No achievements found for the selected filters</p>
          <div class="note">Try adjusting the department, academic year, or category filters.</div>
        </div>
      <?php else: ?>
        <div style="height:300px; position:relative;">
          <canvas id="deptCompChart"></canvas>
        </div>
      <?php endif; ?>
    </div>

    <!-- 1B. Data View: Detailed Table -->
    <div id="deptDataView" style="display:none;">
      <?php if (empty($deptComp)): ?>
        <div class="empty"><p>No department comparison data available.</p></div>
      <?php else: ?>
        <div class="table-wrap">
          <table class="data wide">
            <thead>
              <tr>
                <th>Department</th>
                <th class="num">Publications</th>
                <th class="num">Conferences</th>
                <th class="num">Books</th>
                <th class="num">Events</th>
                <th class="num">Training</th>
                <th class="num">Patents</th>
                <th class="num">Total Achievements</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($deptComp as $dc): ?>
                <tr>
                  <td class="fw-600" style="color:var(--ink,#131D3B);"><?= e($dc['department']) ?></td>
                  <td class="num tabular"><?= (int) $dc['publications'] ?></td>
                  <td class="num tabular"><?= (int) $dc['conferences'] ?></td>
                  <td class="num tabular"><?= (int) $dc['books'] ?></td>
                  <td class="num tabular"><?= (int) $dc['events'] ?></td>
                  <td class="num tabular"><?= (int) $dc['training'] ?></td>
                  <td class="num tabular"><?= (int) $dc['patents'] ?></td>
                  <td class="num tabular fw-700" style="color:var(--brand,#FF4F01); font-size:14px;"><?= (int) $dc['total'] ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- Section 2: Records by Category (With Data View / Analytics View Toggle & View Reports button) -->
<div class="mt-5 card">
  <div class="card-head" style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px;">
    <div>
      <div class="card-title"><?= icon('layers', 16) ?> Records by Category</div>
      <div class="card-sub">Every metric, grouped by what it measures &middot; <?= number_format($summary['totalAchievements']) ?> total</div>
    </div>

    <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
      <!-- Toggle Buttons: Data View | Analytics View -->
      <div class="pill-toggle-group">
        <button type="button" class="btn-pill" id="btnCatData" onclick="switchCatView('data')">Data View</button>
        <button type="button" class="btn-pill active" id="btnCatAnalytics" onclick="switchCatView('analytics')">Analytics View</button>
      </div>

      <a href="<?= e(url('reports.php')) ?>" class="btn btn-primary btn-sm" style="background:#FF4F01; border-color:#FF4F01; border-radius:8px; padding:6px 14px; font-weight:700;">
        <?= icon('file-text', 13) ?> View Reports
      </a>
    </div>
  </div>

  <div class="card-body">
    <?php
      $catMap = $summary['categoryCounts'] ?? [];
      $catIcons = [
          'Journal Publication'     => ['ic' => 'book-open',  'cls' => 'blue'],
          'Conference Publication'  => ['ic' => 'users',      'cls' => 'green'],
          'Book / Book Chapter'     => ['ic' => 'file-text',  'cls' => 'orange'],
          'Events Organized'        => ['ic' => 'calendar',   'cls' => 'red'],
          'FDP / Workshop / Seminar'=> ['ic' => 'graduation', 'cls' => 'purple'],
          'Training Programmes'     => ['ic' => 'briefcase',  'cls' => 'purple'],
          'Patents & Copyrights'    => ['ic' => 'shield',     'cls' => 'teal'],
          'SWAYAM-NPTEL Courses'    => ['ic' => 'award',      'cls' => 'blue'],
          'Online Courses'          => ['ic' => 'graduation', 'cls' => 'green'],
          'MoUs Signed'             => ['ic' => 'link',       'cls' => 'gray'],
      ];
    ?>

    <!-- 2A. Analytics View: Category Grid Cards -->
    <div id="catAnalyticsView">
      <div class="cat-cards-grid">
        <?php foreach ($allCategories as $catKey => $meta): ?>
          <?php
            $label = $meta['label'];
            $cnt   = (int) ($catMap[$label] ?? 0);
            $style = $catIcons[$label] ?? ['ic' => 'layers', 'cls' => 'gray'];
          ?>
          <div class="cat-card">
            <div class="cat-card-ic <?= $style['cls'] ?>">
              <?= icon($style['ic'], 20) ?>
            </div>
            <div>
              <div class="cat-card-title"><?= e($label) ?></div>
              <div class="cat-card-val"><?= number_format($cnt) ?></div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- 2B. Data View: Category Table -->
    <div id="catDataView" style="display:none;">
      <div class="table-wrap">
        <table class="data wide">
          <thead>
            <tr>
              <th>Achievement Metric Category</th>
              <th>Group</th>
              <th>Persisted Database Table</th>
              <th class="num">Record Count</th>
              <th class="num">% of Total</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($allCategories as $catKey => $meta): ?>
              <?php
                $label = $meta['label'];
                $cnt   = (int) ($catMap[$label] ?? 0);
                $pct   = $summary['totalAchievements'] > 0 ? round(($cnt / $summary['totalAchievements']) * 100, 1) : 0;
              ?>
              <tr>
                <td class="fw-600" style="color:var(--ink,#131D3B);"><?= e($label) ?></td>
                <td><span class="badge badge-neutral"><?= e($meta['group']) ?></span></td>
                <td><code style="font-size:12px; color:var(--muted);"><?= e($meta['table']) ?></code></td>
                <td class="num tabular fw-700" style="font-size:14px; color:var(--brand,#FF4F01);"><?= number_format($cnt) ?></td>
                <td class="num tabular"><?= $pct ?>%</td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- Section 3: Highest Achievement Contributors & Main Performance Matrix -->
<div class="mt-5 grid-2-1 gap-5" style="display:grid; grid-template-columns: 2fr 1fr; gap:20px;">
  <!-- Main Faculty Achievement Table Card -->
  <div class="card" style="grid-column: span 2;">
    <div class="card-head" style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px;">
      <div>
        <div class="card-title"><?= icon('users', 16) ?> Faculty Achievement Performance Matrix</div>
        <div class="card-sub"><?= count($facGrid) ?> faculty members &middot; Click any row to view full detailed records</div>
      </div>

      <!-- Quick Search Input -->
      <form method="get" class="fbar fbar-bare" style="margin:0;">
        <input type="hidden" name="academic_year" value="<?= e($academicYear) ?>">
        <input type="hidden" name="department" value="<?= e($department) ?>">
        <input type="hidden" name="category" value="<?= e($category) ?>">
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
// View Switcher for Department Performance Analysis (Data View vs Analytics View)
function switchDeptView(mode) {
  const btnData = document.getElementById('btnDeptData');
  const btnAnalytics = document.getElementById('btnDeptAnalytics');
  const viewData = document.getElementById('deptDataView');
  const viewAnalytics = document.getElementById('deptAnalyticsView');

  if (mode === 'data') {
    btnData.classList.add('active');
    btnAnalytics.classList.remove('active');
    viewData.style.display = 'block';
    viewAnalytics.style.display = 'none';
  } else {
    btnAnalytics.classList.add('active');
    btnData.classList.remove('active');
    viewAnalytics.style.display = 'block';
    viewData.style.display = 'none';
  }
}

// View Switcher for Records by Category (Data View vs Analytics View)
function switchCatView(mode) {
  const btnData = document.getElementById('btnCatData');
  const btnAnalytics = document.getElementById('btnCatAnalytics');
  const viewData = document.getElementById('catDataView');
  const viewAnalytics = document.getElementById('catAnalyticsView');

  if (mode === 'data') {
    btnData.classList.add('active');
    btnAnalytics.classList.remove('active');
    viewData.style.display = 'block';
    viewAnalytics.style.display = 'none';
  } else {
    btnAnalytics.classList.add('active');
    btnData.classList.remove('active');
    viewAnalytics.style.display = 'block';
    viewData.style.display = 'none';
  }
}

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
