<?php
/**
 * Consolidated Faculty Achievements — Enterprise reporting and detailed performance analysis.
 * Supports Admin, Principal, Director, Dean, HoD, Coordinator, and Faculty roles with strict DB authorization.
 * Features Data View vs Analytics View toggles across Department Performance Analysis & Category Metrics.
 */

require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/models/FacultyAchievement.php';
require_once __DIR__ . '/models/StudentAchievement.php';
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

    // Verify department authorization for HoD and Coordinator
    if (in_array($user['role'], ['HoD', 'Coordinator'], true)) {
        $targetUser = user_find($facId);
        if (!$targetUser || !department_names_match($targetUser['department'] ?? '', $user['department'] ?? '')) {
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
$rawDept       = trim((string) input('department', ''));
$department    = resolve_faculty_achievement_scope($user, $rawDept);
$academicYear  = trim((string) input('academic_year', '')) ?: active_academic_year();
$category      = trim((string) input('category', '')) ?: null;
$facultyId     = (int) input('faculty_id', 0) ?: null;
$searchQuery   = trim((string) input('q', ''));
$studentSearch = trim((string) input('student_search', '')) ?: trim((string) input('q_student', ''));

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
$facSql = "SELECT id, name, department FROM users WHERE role = 'Faculty'";
if ($department) {
    $deptVars = department_variants($department);
    if (!empty($deptVars)) {
        $inPh = implode(',', array_fill(0, count($deptVars), '?'));
        $facSql .= " AND department IN ($inPh)";
        $facParams = array_merge($facParams, $deptVars);
    } else {
        $facSql .= " AND department = ?";
        $facParams[] = $department;
    }
}
$facSql .= " ORDER BY name ASC";
$facStmt = db()->prepare($facSql);
$facStmt->execute($facParams);
$facultyList = $facStmt->fetchAll();

// ---- Fetch Data ---------------------------------------------------------
$summary         = faculty_achievements_summary($user, $department, $academicYear, $category, $facultyId, $emWindow);
$deptComp        = department_achievements_comparison($user, $academicYear, $category, $emWindow);
$facGrid         = faculty_achievements_grid($user, $department, $academicYear, $category, $facultyId, $searchQuery, $emWindow);
$topContributors = top_faculty_contributors($user, $department, $academicYear, $category, 5, $emWindow);

// Student Achievements Data
$studGrid    = student_achievements_grid($user, $department, $academicYear, null, $studentSearch, $emWindow);
$studSummary = student_achievements_summary($user, $department, $academicYear, null, $studentSearch, $emWindow);

// Compute student analytics aggregations (100% consistent with Data View)
$studDeptCounts = [];
foreach ($studGrid as $s) {
    $d = $s['department'] ?: 'Other Department';
    $studDeptCounts[$d] = ($studDeptCounts[$d] ?? 0) + (int) $s['total'];
}
arsort($studDeptCounts);

$studCatBreakdown = [
    'NPTEL' => [
        'label' => 'SWAYAM-NPTEL Online Courses',
        'count' => (int) ($studSummary['categoryCounts']['SWAYAM-NPTEL'] ?? 0),
        'table' => 'nptels',
        'color' => '#0066CC',
    ],
    'Internships' => [
        'label' => 'Industrial Internships',
        'count' => (int) ($studSummary['categoryCounts']['Internships'] ?? 0),
        'table' => 'internships',
        'color' => '#059669',
    ],
    'Placements' => [
        'label' => 'Campus & Off-Campus Placements',
        'count' => (int) ($studSummary['categoryCounts']['Placements'] ?? 0),
        'table' => 'placements',
        'color' => '#FF4F01',
    ],
    'Online Courses' => [
        'label' => 'Online Courses & Certifications',
        'count' => (int) ($studSummary['categoryCounts']['Online Courses'] ?? 0),
        'table' => 'online_courses',
        'color' => '#7E22CE',
    ],
    'Student Achievements' => [
        'label' => 'Student Achievements (Awards/Prizes)',
        'count' => (int) ($studSummary['categoryCounts']['Student Achievements'] ?? 0),
        'table' => 'student_achievements',
        'color' => '#0D9488',
    ],
    'Student Participation' => [
        'label' => 'Symposiums & Event Participations',
        'count' => (int) ($studSummary['categoryCounts']['Student Participations'] ?? 0),
        'table' => 'student_participations',
        'color' => '#DC2626',
    ],
    'Training' => [
        'label' => 'Summer & Winter Training Programmes',
        'count' => (int) ($studSummary['categoryCounts']['Summer / Winter Training'] ?? 0),
        'table' => 'training_programmes',
        'color' => '#D97706',
    ],
    'Other' => [
        'label' => 'Other Student Achievements',
        'count' => (int) ($studSummary['categoryCounts']['Other'] ?? 0),
        'table' => 'various',
        'color' => '#64748B',
    ],
];

$topCatName = 'None';
$topCatVal = 0;
foreach ($studCatBreakdown as $k => $c) {
    if ($c['count'] > $topCatVal) {
        $topCatVal = $c['count'];
        $topCatName = $k;
    }
}
$activeDeptsCount = count($studDeptCounts);

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

  /* Student Summary Cards Responsive Grid */
  .stud-summary-grid {
    display: grid;
    grid-template-columns: repeat(9, minmax(0, 1fr));
    gap: 10px;
    width: 100%;
  }
  @media (max-width: 1200px) {
    .stud-summary-grid {
      grid-template-columns: repeat(5, minmax(0, 1fr));
    }
  }
  @media (max-width: 900px) {
    .stud-summary-grid {
      grid-template-columns: repeat(3, minmax(0, 1fr));
    }
  }
  @media (max-width: 580px) {
    .stud-summary-grid {
      grid-template-columns: repeat(2, minmax(0, 1fr));
    }
  }
  @media (max-width: 380px) {
    .stud-summary-grid {
      grid-template-columns: 1fr;
    }
  }

  .stud-stat-card {
    background: #ffffff;
    padding: 12px 14px;
    border-radius: 10px;
    border: 1px solid var(--hairline, #E4E9F2);
    display: flex;
    flex-direction: column;
    justify-content: center;
    min-height: 72px;
    transition: border-color 0.15s ease, transform 0.15s ease, box-shadow 0.15s ease;
  }
  .stud-stat-card:hover {
    border-color: var(--brand, #FF4F01);
    transform: translateY(-1px);
    box-shadow: 0 3px 8px rgba(19, 29, 59, 0.05);
  }
  .stud-stat-label {
    font-size: 10.5px;
    font-weight: 700;
    color: var(--muted, #5A6785);
    text-transform: uppercase;
    letter-spacing: 0.4px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }
  .stud-stat-value {
    font-size: 20px;
    font-weight: 800;
    color: var(--ink, #131D3B);
    margin-top: 3px;
    line-height: 1.1;
  }
  .stud-stat-value.brand {
    color: var(--brand, #FF4F01);
  }
</style>

<!-- Page Header -->
<div class="page-head">
  <div>
    <h1>Consolidated Faculty Achievements</h1>
    <div class="sub">
      Institution-wide overview &middot; <?= $department ? e($department) : ($isHod ? e($user['department']) : 'All departments') ?>
      &middot; Academic Year: <?= e($academicYear ?: 'All Years') ?>
      <span class="badge badge-neutral" style="margin-left:8px; font-size:11px;"><?= icon('lock', 11) ?> Locked by Admin</span>
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
  <?php if (!user_can_choose_department($user)): ?>
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
        <input type="hidden" name="em" value="<?= e($em) ?>">
        <input type="hidden" name="student_search" value="<?= e($studentSearch) ?>">
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
        <div class="table-wrap" style="max-height: 520px; overflow-y: auto; overflow-x: auto; -webkit-overflow-scrolling: touch;">
          <table class="data wide sortable" id="facTable" style="min-width: 1050px; width: 100%;">
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
                    $rawDept = $f['department'] ?: 'Other Department';
                    $matchedKey = null;
                    if ($department && department_names_match($rawDept, $department)) {
                        $matchedKey = $department;
                    } else {
                        foreach (array_keys($groupedGrid) as $existing) {
                            if (department_names_match($existing, $rawDept)) {
                                $matchedKey = $existing;
                                break;
                            }
                        }
                    }
                    $groupName = $matchedKey ?: $rawDept;
                    $groupedGrid[$groupName][] = $f;
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
                        <!-- Faculty Details: the A4 document of everything ATTS holds on this
                             person. Same id-based authorization as the report links above. -->
                        <a href="<?= e(url('faculty-details-report.php')) ?>?id=<?= (int) $f['id'] ?><?= !empty($academicYear) ? '&academic_year=' . urlencode($academicYear) : '' ?><?= $em !== 'all' ? '&em=' . urlencode($em) : '' ?>" target="_blank" rel="noopener" class="btn btn-outline btn-sm" style="border-radius:999px; padding:4px 10px; font-size:11.5px; display:inline-flex; align-items:center; gap:4px;" title="Open <?= e($f['name']) ?>'s Faculty Details as an A4 document">
                          <?= icon('user', 13) ?> Details PDF
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

<!-- Section 4: Student Achievement Performance Matrix -->
<div class="mt-5 card">
  <div class="card-head" style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px;">
    <div>
      <div class="card-title"><?= icon('graduation', 16) ?> Student Achievement Performance Matrix</div>
      <div class="card-sub"><?= count($studGrid) ?> student profiles &middot; Consolidated student achievements across active categories</div>
    </div>

    <!-- Controls: View Mode Toggle & Student Search -->
    <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
      <!-- Toggle Buttons: Data View | Analytics View -->
      <div class="pill-toggle-group">
        <button type="button" class="btn-pill active" id="btnStudData" onclick="switchStudView('data')">Data View</button>
        <button type="button" class="btn-pill" id="btnStudAnalytics" onclick="switchStudView('analytics')">Analytics View</button>
      </div>

      <!-- Student Quick Search Input -->
      <form method="get" class="fbar fbar-bare" style="margin:0;">
        <input type="hidden" name="academic_year" value="<?= e($academicYear) ?>">
        <input type="hidden" name="department" value="<?= e($department) ?>">
        <input type="hidden" name="category" value="<?= e($category) ?>">
        <input type="hidden" name="em" value="<?= e($em) ?>">
        <input type="hidden" name="q" value="<?= e($searchQuery) ?>">
        <label class="fb-field fb-search" style="min-width:230px;">
          <?= icon('search', 14) ?>
          <input type="search" name="student_search" value="<?= e($studentSearch) ?>" placeholder="Search student name..." onchange="this.form.submit()">
        </label>
      </form>
    </div>
  </div>

  <!-- 4A. DATA VIEW: Summary Cards + Matrix Table -->
  <div id="studDataView">
    <!-- Student Category Summary Cards inside Section -->
    <div class="card-body" style="padding:14px 20px; border-bottom:1px solid var(--hairline,#E4E9F2); background:var(--navy-50,#F4F6FA);">
      <div class="stud-summary-grid">
        <div class="stud-stat-card">
          <div class="stud-stat-label">Total Students</div>
          <div class="stud-stat-value tabular"><?= number_format((int) $studSummary['totalStudents']) ?></div>
        </div>
        <div class="stud-stat-card">
          <div class="stud-stat-label" style="color:var(--brand,#FF4F01);">Total Records</div>
          <div class="stud-stat-value tabular brand"><?= number_format((int) $studSummary['totalAchievements']) ?></div>
        </div>
        <div class="stud-stat-card">
          <div class="stud-stat-label">NPTEL</div>
          <div class="stud-stat-value tabular"><?= number_format((int) ($studSummary['categoryCounts']['SWAYAM-NPTEL'] ?? 0)) ?></div>
        </div>
        <div class="stud-stat-card">
          <div class="stud-stat-label">Internships</div>
          <div class="stud-stat-value tabular"><?= number_format((int) ($studSummary['categoryCounts']['Internships'] ?? 0)) ?></div>
        </div>
        <div class="stud-stat-card">
          <div class="stud-stat-label">Placements</div>
          <div class="stud-stat-value tabular"><?= number_format((int) ($studSummary['categoryCounts']['Placements'] ?? 0)) ?></div>
        </div>
        <div class="stud-stat-card">
          <div class="stud-stat-label">Online Courses</div>
          <div class="stud-stat-value tabular"><?= number_format((int) ($studSummary['categoryCounts']['Online Courses'] ?? 0)) ?></div>
        </div>
        <div class="stud-stat-card">
          <div class="stud-stat-label">Achievements</div>
          <div class="stud-stat-value tabular"><?= number_format((int) ($studSummary['categoryCounts']['Student Achievements'] ?? 0)) ?></div>
        </div>
        <div class="stud-stat-card">
          <div class="stud-stat-label">Participation</div>
          <div class="stud-stat-value tabular"><?= number_format((int) ($studSummary['categoryCounts']['Student Participations'] ?? 0)) ?></div>
        </div>
        <div class="stud-stat-card">
          <div class="stud-stat-label">Training</div>
          <div class="stud-stat-value tabular"><?= number_format((int) ($studSummary['categoryCounts']['Summer / Winter Training'] ?? 0)) ?></div>
        </div>
      </div>
    </div>

    <!-- Student Performance Matrix Table -->
    <div class="card-body pt-0">
      <?php if (empty($studGrid)): ?>
        <div class="empty" style="padding:40px 20px;">
          <div class="ic"><?= icon('graduation', 20) ?></div>
          <p>No student achievements found</p>
          <div class="note">Try adjusting the department, academic year, or search filters.</div>
        </div>
      <?php else: ?>
        <div class="table-wrap" style="max-height: 540px; overflow-y: auto; overflow-x: auto; margin-top:14px; -webkit-overflow-scrolling: touch;">
          <table class="data wide sortable" id="studTable" style="min-width: 1150px; width: 100%;">
            <thead style="position:sticky; top:0; z-index:10; background:var(--surface,#fff);">
              <tr>
                <th style="width: 40px;">#</th>
                <th style="min-width: 150px;">STUDENT</th>
                <th style="min-width: 120px;">REGISTER NO</th>
                <th style="min-width: 100px;">DEPARTMENT</th>
                <th class="num">NPTEL</th>
                <th class="num">INTERNSHIPS</th>
                <th class="num">PLACEMENTS</th>
                <th class="num">ONLINE COURSES</th>
                <th class="num">ACHIEVEMENTS</th>
                <th class="num">PARTICIPATION</th>
                <th class="num">TRAINING</th>
                <th class="num">OTHER</th>
                <th class="num" style="min-width: 70px;">TOTAL</th>
                <th style="text-align:center; min-width: 195px; width: 195px;">ACTIONS</th>
              </tr>
            </thead>
            <tbody>
              <?php
                $groupedStudGrid = [];
                foreach ($studGrid as $s) {
                    $deptName = $s['department'] ?: 'Other Department';
                    $groupedStudGrid[$deptName][] = $s;
                }
                $studSno = 1;
              ?>
              <?php foreach ($groupedStudGrid as $deptName => $deptStudents): ?>
                <tr style="background:var(--navy-50,#F4F6FA); border-top:2px solid var(--hairline,#E4E9F2); border-bottom:1px solid var(--hairline,#E4E9F2);">
                  <td colspan="14" style="padding:10px 14px; font-weight:700; color:var(--ink,#131D3B); font-size:13.5px;">
                    <?= icon('building', 14) ?> Department: <?= e($deptName) ?>
                    <span class="badge badge-neutral" style="margin-left:6px; font-size:11px;"><?= count($deptStudents) ?> <?= count($deptStudents) === 1 ? 'student' : 'students' ?></span>
                  </td>
                </tr>
                <?php foreach ($deptStudents as $s): ?>
                  <?php
                    $studReportUrl = url('individual-student-report.php') . '?' . http_build_query([
                        'key'           => $s['key'],
                        'reg_no'        => $s['reg_no'],
                        'name'          => $s['student_name'],
                        'dept'          => $s['department'],
                        'academic_year' => $academicYear,
                    ]);
                    $studPresentUrl = url('present-student-report.php') . '?' . http_build_query([
                        'key'           => $s['key'],
                        'reg_no'        => $s['reg_no'],
                        'name'          => $s['student_name'],
                        'dept'          => $s['department'],
                        'academic_year' => $academicYear,
                    ]);
                  ?>
                  <tr class="drill-row" style="cursor:pointer;" title="Click to view <?= e($s['student_name']) ?>'s Individual Student Achievement Report" onclick="location.href='<?= e($studReportUrl) ?>'">
                    <td class="faint tabular"><?= $studSno++ ?></td>
                    <td>
                      <div class="fw-500 truncate" style="max-width:180px; font-weight:600; color:var(--ink,#131D3B);" title="<?= e($s['student_name']) ?>"><?= e($s['student_name']) ?></div>
                    </td>
                    <td class="faint tabular" style="font-family:monospace; font-size:12px;"><?= e($s['reg_no']) ?></td>
                    <td class="faint"><?= e($s['department']) ?></td>
                    <td class="num tabular"><?= (int) $s['nptel'] ?></td>
                    <td class="num tabular"><?= (int) $s['internships'] ?></td>
                    <td class="num tabular"><?= (int) $s['placements'] ?></td>
                    <td class="num tabular"><?= (int) $s['online_courses'] ?></td>
                    <td class="num tabular"><?= (int) $s['achievements'] ?></td>
                    <td class="num tabular"><?= (int) $s['participation'] ?></td>
                    <td class="num tabular"><?= (int) $s['training'] ?></td>
                    <td class="num tabular"><?= (int) $s['other'] ?></td>
                    <td class="num tabular fw-600" style="font-size:14px; color:var(--brand,#FF4F01);"><?= (int) $s['total'] ?></td>
                    <td style="text-align:center; white-space:nowrap; min-width: 195px; width: 195px;" onclick="event.stopPropagation();">
                      <div style="display:inline-flex; align-items:center; gap:6px; flex-wrap:nowrap;">
                        <a href="<?= e($studReportUrl) ?>" class="btn btn-primary btn-sm" style="border-radius:999px; padding:4px 10px; font-size:11.5px; display:inline-flex; align-items:center; gap:4px; white-space:nowrap;">
                          <?= icon('file-text', 13) ?> View Report
                        </a>
                        <a href="<?= e($studPresentUrl) ?>" class="btn btn-secondary btn-sm" style="border-radius:999px; padding:4px 10px; font-size:11.5px; display:inline-flex; align-items:center; gap:4px; background:#131D3B; color:#ffffff; border:1px solid #131D3B; white-space:nowrap;">
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

  <!-- 4B. ANALYTICS VIEW: KPI Summary Cards + Charts + Category Breakdown Table -->
  <div id="studAnalyticsView" style="display:none; padding:20px;">
    <?php if (empty($studGrid) || (int)$studSummary['totalAchievements'] === 0): ?>
      <div class="empty" style="padding:40px 20px;">
        <div class="ic"><?= icon('bar-chart', 20) ?></div>
        <p>No student achievements available for analytics with current filters</p>
        <div class="note">Try adjusting the department, academic year, or search filters.</div>
      </div>
    <?php else: ?>
      <!-- Top Analytics KPI Stat Cards -->
      <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:14px; margin-bottom:24px;">
        <div class="stat" style="background:#fff; padding:14px 18px; border-radius:12px; border:1px solid var(--hairline,#E4E9F2);">
          <div style="font-size:11.5px; font-weight:700; color:var(--muted,#5A6785); text-transform:uppercase;">Total Students</div>
          <div style="font-size:24px; font-weight:800; color:var(--ink,#131D3B); margin-top:4px;" class="tabular"><?= number_format((int) $studSummary['totalStudents']) ?></div>
          <div class="card-sub" style="font-size:11px; margin-top:2px;">Distinct student profiles</div>
        </div>
        <div class="stat" style="background:#fff; padding:14px 18px; border-radius:12px; border:1px solid var(--hairline,#E4E9F2);">
          <div style="font-size:11.5px; font-weight:700; color:var(--brand,#FF4F01); text-transform:uppercase;">Total Records</div>
          <div style="font-size:24px; font-weight:800; color:var(--brand,#FF4F01); margin-top:4px;" class="tabular"><?= number_format((int) $studSummary['totalAchievements']) ?></div>
          <div class="card-sub" style="font-size:11px; margin-top:2px;">Consolidated verified entries</div>
        </div>
        <div class="stat" style="background:#fff; padding:14px 18px; border-radius:12px; border:1px solid var(--hairline,#E4E9F2);">
          <div style="font-size:11.5px; font-weight:700; color:var(--muted,#5A6785); text-transform:uppercase;">Top Category</div>
          <div style="font-size:20px; font-weight:800; color:var(--ink,#131D3B); margin-top:4px;" class="truncate" title="<?= e($topCatName) ?>"><?= e($topCatName) ?></div>
          <div class="card-sub" style="font-size:11px; margin-top:2px;"><?= number_format($topCatVal) ?> records recorded</div>
        </div>
        <div class="stat" style="background:#fff; padding:14px 18px; border-radius:12px; border:1px solid var(--hairline,#E4E9F2);">
          <div style="font-size:11.5px; font-weight:700; color:var(--muted,#5A6785); text-transform:uppercase;">Active Departments</div>
          <div style="font-size:24px; font-weight:800; color:var(--navy,#131D3B); margin-top:4px;" class="tabular"><?= (int) $activeDeptsCount ?></div>
          <div class="card-sub" style="font-size:11px; margin-top:2px;">Departments with achievements</div>
        </div>
      </div>

      <!-- Charts Grid -->
      <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(360px, 1fr)); gap:20px; margin-bottom:24px;">
        <!-- Chart 1: Student Achievements by Category -->
        <div style="background:#fff; border:1px solid var(--hairline,#E4E9F2); border-radius:12px; padding:18px;">
          <div style="font-size:13.5px; font-weight:700; color:var(--ink,#131D3B); margin-bottom:4px; display:flex; align-items:center; gap:6px;">
            <?= icon('pie-chart', 15) ?> Student Achievements by Category
          </div>
          <div class="card-sub" style="font-size:11px; margin-bottom:14px;">Distribution across all active student categories</div>
          <div style="height:260px; position:relative;">
            <canvas id="studCatChart"></canvas>
          </div>
        </div>

        <!-- Chart 2: Department-wise Student Achievements -->
        <div style="background:#fff; border:1px solid var(--hairline,#E4E9F2); border-radius:12px; padding:18px;">
          <div style="font-size:13.5px; font-weight:700; color:var(--ink,#131D3B); margin-bottom:4px; display:flex; align-items:center; gap:6px;">
            <?= icon('bar-chart', 15) ?> Department-wise Student Achievements
          </div>
          <div class="card-sub" style="font-size:11px; margin-bottom:14px;">Comparison of verified student achievements by department</div>
          <div style="height:260px; position:relative;">
            <canvas id="studDeptChart"></canvas>
          </div>
        </div>
      </div>

      <!-- Detailed Category Breakdown Table -->
      <div style="background:#fff; border:1px solid var(--hairline,#E4E9F2); border-radius:12px; overflow:hidden;">
        <div style="padding:14px 18px; border-bottom:1px solid var(--hairline,#E4E9F2); font-weight:700; font-size:13.5px; color:var(--ink,#131D3B); display:flex; align-items:center; justify-content:space-between;">
          <span><?= icon('layers', 14) ?> Category Performance Details</span>
          <span class="badge badge-neutral" style="font-size:11px;"><?= count($studCatBreakdown) ?> Active Categories</span>
        </div>
        <div class="table-wrap" style="overflow-x:auto;">
          <table class="data wide">
            <thead>
              <tr>
                <th>Category</th>
                <th>Official Classification</th>
                <th>Underlying Table</th>
                <th class="num">Record Count</th>
                <th class="num">% of Student Total</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($studCatBreakdown as $cKey => $meta): ?>
                <?php
                  $cnt = $meta['count'];
                  $pct = $studSummary['totalAchievements'] > 0 ? round(($cnt / $studSummary['totalAchievements']) * 100, 1) : 0;
                ?>
                <tr>
                  <td class="fw-600" style="color:var(--ink,#131D3B); display:flex; align-items:center; gap:8px;">
                    <span style="display:inline-block; width:10px; height:10px; border-radius:50%; background:<?= $meta['color'] ?>;"></span>
                    <?= e($cKey) ?>
                  </td>
                  <td><span class="card-sub" style="font-size:12px;"><?= e($meta['label']) ?></span></td>
                  <td><code style="font-size:11.5px; color:var(--muted);"><?= e($meta['table']) ?></code></td>
                  <td class="num tabular fw-700" style="font-size:13.5px; color:var(--brand,#FF4F01);"><?= number_format($cnt) ?></td>
                  <td class="num tabular fw-600"><?= $pct ?>%</td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
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

// Student Matrix View Switcher (Data View vs Analytics View)
function switchStudView(mode) {
  const btnData = document.getElementById('btnStudData');
  const btnAnalytics = document.getElementById('btnStudAnalytics');
  const viewData = document.getElementById('studDataView');
  const viewAnalytics = document.getElementById('studAnalyticsView');

  if (!btnData || !btnAnalytics || !viewData || !viewAnalytics) return;

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
    initStudentCharts();
  }
}

let studChartsInitialized = false;
function initStudentCharts() {
  if (studChartsInitialized) return;
  studChartsInitialized = true;

  // Chart 1: Category Distribution Bar Chart
  const catCanvas = document.getElementById('studCatChart');
  if (catCanvas) {
    const catLabels = <?= json_encode(array_keys($studCatBreakdown)) ?>;
    const catData = <?= json_encode(array_values(array_map(fn($c) => $c['count'], $studCatBreakdown))) ?>;
    const catColors = <?= json_encode(array_values(array_map(fn($c) => $c['color'], $studCatBreakdown))) ?>;

    new Chart(catCanvas, {
      type: 'bar',
      data: {
        labels: catLabels,
        datasets: [{
          data: catData,
          backgroundColor: catColors,
          borderRadius: 6,
          maxBarThickness: 36,
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { display: false },
          tooltip: {
            callbacks: {
              label: (ctx) => ` Records: ${ctx.raw}`
            }
          }
        },
        scales: {
          x: {
            grid: { display: false },
            ticks: { font: { size: 11 } }
          },
          y: {
            beginAtZero: true,
            grid: { color: '#E7EBF3' },
            ticks: { stepSize: 1 }
          }
        }
      }
    });
  }

  // Chart 2: Department-wise Achievements Bar Chart
  const deptCanvas = document.getElementById('studDeptChart');
  if (deptCanvas) {
    const deptLabels = <?= json_encode(array_keys($studDeptCounts)) ?>;
    const deptData = <?= json_encode(array_values($studDeptCounts)) ?>;

    new Chart(deptCanvas, {
      type: 'bar',
      data: {
        labels: deptLabels.length > 0 ? deptLabels : ['No Departments'],
        datasets: [{
          data: deptData.length > 0 ? deptData : [0],
          backgroundColor: '#131D3B',
          borderRadius: 6,
          maxBarThickness: 36,
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { display: false },
          tooltip: {
            callbacks: {
              label: (ctx) => ` Total Achievements: ${ctx.raw}`
            }
          }
        },
        scales: {
          x: {
            grid: { display: false },
            ticks: { font: { size: 11 } }
          },
          y: {
            beginAtZero: true,
            grid: { color: '#E7EBF3' },
            ticks: { stepSize: 1 }
          }
        }
      }
    });
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
