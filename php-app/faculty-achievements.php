<?php
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/models/FacultyAchievement.php';
require_once __DIR__ . '/models/StudentAchievement.php';
require_once __DIR__ . '/models/Department.php';
require_once __DIR__ . '/models/User.php';
require_once __DIR__ . '/models/ExecutiveMeeting.php';   
$user = require_login();

if (input('ajax') === 'faculty_detail') {
    header('Content-Type: application/json');
    $facId = (int) input('faculty_id');
    $year  = trim((string) input('year')) ?: null;
    $cat   = trim((string) input('category')) ?: null;

    if (in_array($user['role'], ['HoD', 'Coordinator'], true)) {
        $targetUser = user_find($facId);
        if (!$targetUser || !department_names_match($targetUser['department'] ?? '', $user['department'] ?? '')) {
            echo json_encode(['error' => 'Unauthorized access to faculty outside your department']);
            exit;
        }
    }

    $data = faculty_achievement_details($facId, $year, $cat, em_filter_window(em_filter_value(input('em')), $year));
    echo json_encode($data);
    exit;
}

$role        = $user['role'];
$isAdmin     = $role === 'Admin';
$isHod       = $role === 'HoD';
$isDirector  = in_array($role, ['Director', 'Principal'], true);
$isDean      = $role === 'Dean';
$isOversight = $isAdmin || $isDirector || $isDean;

$rawDept       = trim((string) input('department', ''));
$department    = resolve_faculty_achievement_scope($user, $rawDept);
$academicYear  = trim((string) input('academic_year', '')) ?: active_academic_year();
$category      = trim((string) input('category', '')) ?: null;
$facultyId     = (int) input('faculty_id', 0) ?: null;
$searchQuery   = trim((string) input('q', ''));
$studentSearch = trim((string) input('student_search', '')) ?: trim((string) input('q_student', ''));

$em       = em_filter_value(input('em'));
$emWindow = em_filter_window($em, $academicYear ?: null);

$departments   = departments_all();
$years         = academic_years();
$allCategories = faculty_achievement_categories();

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

$summary         = faculty_achievements_summary($user, $department, $academicYear, $category, $facultyId, $emWindow ?? null);
$deptComp        = department_achievements_comparison($user, $academicYear, $category, $emWindow ?? null);

// Sort department comparison descending by total achievements
$sortedDeptComp = $deptComp;
usort($sortedDeptComp, fn($a, $b) => (int)$b['total'] <=> (int)$a['total']);
$activeDeptComp = array_values(array_filter($sortedDeptComp, fn($d) => (int)$d['total'] > 0));
$topDept = (!empty($sortedDeptComp) && (int)$sortedDeptComp[0]['total'] > 0) ? $sortedDeptComp[0] : null;
$totalDeptRecords = (int) array_sum(array_column($deptComp, 'total'));
$activeDeptsCount = count($activeDeptComp);
$totalDeptsCount = count($deptComp);

$catMap = $summary['categoryCounts'] ?? [];
$catIcons = [
    'Journal Publication'     => ['ic' => 'book-open',  'cls' => 'blue',   'color' => '#2563EB'],
    'Conference Publication'  => ['ic' => 'users',      'cls' => 'green',  'color' => '#059669'],
    'Book / Book Chapter'     => ['ic' => 'file-text',  'cls' => 'orange', 'color' => '#FF4F01'],
    'Events Organized'        => ['ic' => 'calendar',   'cls' => 'red',    'color' => '#DC2626'],
    'FDP / Workshop / Seminar'=> ['ic' => 'graduation', 'cls' => 'purple', 'color' => '#7C3AED'],
    'Training Programmes'     => ['ic' => 'briefcase',  'cls' => 'purple', 'color' => '#9333EA'],
    'Patents & Copyrights'    => ['ic' => 'shield',     'cls' => 'teal',   'color' => '#0D9488'],
    'SWAYAM-NPTEL Courses'    => ['ic' => 'award',      'cls' => 'blue',   'color' => '#0284C7'],
    'Online Courses'          => ['ic' => 'graduation', 'cls' => 'green',  'color' => '#10B981'],
    'MoUs Signed'             => ['ic' => 'link',       'cls' => 'gray',   'color' => '#4F46E5'],
];

$categoryBreakdown = [];
foreach ($allCategories as $catKey => $meta) {
    $cLabel = $meta['label'];
    $cnt = (int) ($catMap[$cLabel] ?? 0);
    $cStyle = $catIcons[$cLabel] ?? ['ic' => 'layers', 'cls' => 'gray', 'color' => '#64748B'];
    $categoryBreakdown[] = [
        'key'   => $catKey,
        'label' => $cLabel,
        'group' => $meta['group'],
        'table' => $meta['table'],
        'count' => $cnt,
        'color' => $cStyle['color'],
        'icon'  => $cStyle['ic'],
        'cls'   => $cStyle['cls'],
    ];
}
$sortedCatBreakdown = $categoryBreakdown;
usort($sortedCatBreakdown, fn($a, $b) => $b['count'] <=> $a['count']);
$activeCatBreakdown = array_values(array_filter($sortedCatBreakdown, fn($c) => $c['count'] > 0));

$facGrid         = faculty_achievements_grid($user, $department, $academicYear, $category, $facultyId, $searchQuery, $emWindow ?? null);
$topContributors = top_faculty_contributors($user, $department, $academicYear, $category, 5, $emWindow ?? null);

$studGrid    = student_achievements_grid($user, $department, $academicYear, null, $studentSearch, $emWindow);
$studSummary = student_achievements_summary($user, $department, $academicYear, null, $studentSearch, $emWindow);

$studDeptCounts = [];
foreach ($studGrid as $s) {
    $d = $s['department'] ?: 'Other Department';
    $studDeptCounts[$d] = ($studDeptCounts[$d] ?? 0) + (int) $s['total'];
}
arsort($studDeptCounts);

$facTotalProfiles  = count($facGrid);
// A journal or book with several faculty authors is on each author's row, but
// the totals below count the publication once.
$facTotalRecords   = (int) array_sum(array_column($facGrid, 'total')) - faculty_grid_shared_overlap($facGrid);
$facTopContributor = null;
$facTopRecords     = 0;
$facDeptTotals     = [];
$facDeptRows       = [];
$facCatTotals      = [
    'Journals'    => ['count' => faculty_grid_distinct($facGrid, 'journal'),              'color' => '#0066CC'],
    'Conferences' => ['count' => (int) array_sum(array_column($facGrid, 'conferences')), 'color' => '#059669'],
    'Books'       => ['count' => faculty_grid_distinct($facGrid, 'book'),                 'color' => '#FF4F01'],
    'Events'      => ['count' => (int) array_sum(array_column($facGrid, 'events')),      'color' => '#DC2626'],
    'Training'    => ['count' => (int) array_sum(array_column($facGrid, 'training')),    'color' => '#7E22CE'],
    'Patents'     => ['count' => (int) array_sum(array_column($facGrid, 'patents')),     'color' => '#0D9488'],
    'Other'       => ['count' => (int) array_sum(array_column($facGrid, 'other')),       'color' => '#64748B'],
];
foreach ($facGrid as $f) {
    if ((int)$f['total'] > $facTopRecords) {
        $facTopRecords = (int)$f['total'];
        $facTopContributor = $f;
    }
    $d = $f['department'] ?: 'Other';
    $facDeptTotals[$d] = ($facDeptTotals[$d] ?? 0) + (int)$f['total'];
    $facDeptRows[$d][] = $f;
}
foreach ($facDeptRows as $d => $dRows) {
    $facDeptTotals[$d] -= faculty_grid_shared_overlap($dRows);
}
arsort($facDeptTotals);
$facRanked = $facGrid;
usort($facRanked, fn($a, $b) => (int)$b['total'] <=> (int)$a['total']);
$facTop5 = array_slice($facRanked, 0, 5);

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

$exportQ = array_filter([
    'department'    => $department,
    'academic_year' => $academicYear,
    'category'      => $category,
    'faculty_id'    => $facultyId,
    'em'            => $em !== 'all' ? $em : null,   ]);
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
    gap: 3px;
  }
  .btn-pill {
    border-radius: 999px;
    padding: 5px 14px;
    font-size: 11.5px;
    font-weight: 700;
    border: 0;
    cursor: pointer;
    background: transparent;
    color: var(--muted, #5A6785);
    display: inline-flex;
    align-items: center;
    gap: 5px;
    transition: all 0.2s ease;
  }
  .btn-pill.active {
    background: var(--navy, #131D3B);
    color: #ffffff;
    box-shadow: 0 2px 4px rgba(19,29,59,0.15);
  }
  .btn-pill:hover:not(.active) {
    color: var(--ink, #131D3B);
    background: rgba(0,0,0,0.04);
  }

  /* Analytics / Graph Mode Styling */
  .analytics-view-wrap {
    padding: 18px 20px;
  }
  .analytics-kpi-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 14px;
    margin-bottom: 20px;
  }
  @media (max-width: 1100px) {
    .analytics-kpi-grid {
      grid-template-columns: repeat(2, minmax(0, 1fr));
    }
  }
  @media (max-width: 600px) {
    .analytics-kpi-grid {
      grid-template-columns: 1fr;
    }
  }
  .analytics-kpi-card {
    background: #ffffff;
    border: 1px solid var(--hairline, #E2E8F0);
    border-radius: 10px;
    padding: 14px 16px;
    display: flex;
    flex-direction: column;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.03);
    transition: transform 0.15s ease, box-shadow 0.15s ease, border-color 0.15s ease;
  }
  .analytics-kpi-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(19, 29, 59, 0.06);
    border-color: #CBD5E1;
  }
  .analytics-kpi-top {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 6px;
  }
  .analytics-kpi-label {
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: var(--muted, #5A6785);
  }
  .analytics-kpi-icon {
    width: 28px;
    height: 28px;
    border-radius: 8px;
    display: grid;
    place-items: center;
    flex-shrink: 0;
  }
  .analytics-kpi-icon.blue   { background: #EFF6FF; color: #2563EB; }
  .analytics-kpi-icon.orange { background: #FFF1EB; color: #FF4F01; }
  .analytics-kpi-icon.amber  { background: #FEF3C7; color: #D97706; }
  .analytics-kpi-icon.purple { background: #F3E8FF; color: #7C3AED; }
  .analytics-kpi-icon.teal   { background: #CCFBF1; color: #0D9488; }
  .analytics-kpi-icon.navy   { background: #F0F4F8; color: #131D3B; }
  .analytics-kpi-val {
    font-size: 22px;
    font-weight: 800;
    color: var(--ink, #131D3B);
    line-height: 1.15;
    font-variant-numeric: tabular-nums;
  }
  .analytics-kpi-val.brand {
    color: var(--brand, #FF4F01);
  }
  .analytics-kpi-sub {
    font-size: 11px;
    color: var(--ink-faint, #8B96AE);
    margin-top: 4px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }
  .analytics-charts-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 16px;
    margin-bottom: 20px;
  }
  @media (max-width: 900px) {
    .analytics-charts-grid {
      grid-template-columns: 1fr;
    }
  }
  .analytics-chart-card {
    background: #ffffff;
    border: 1px solid var(--hairline, #E2E8F0);
    border-radius: 10px;
    padding: 16px 18px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.03);
  }
  .analytics-chart-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 12px;
    padding-bottom: 8px;
    border-bottom: 1px solid #F1F5F9;
  }
  .analytics-chart-title {
    font-size: 13px;
    font-weight: 700;
    color: var(--ink, #131D3B);
    display: flex;
    align-items: center;
    gap: 6px;
  }
  .analytics-chart-sub {
    font-size: 11px;
    color: var(--muted, #5A6785);
    margin-top: 2px;
  }
  .analytics-chart-body {
    height: 270px;
    position: relative;
  }
  .analytics-table-card {
    background: #ffffff;
    border: 1px solid var(--hairline, #E2E8F0);
    border-radius: 10px;
    overflow: hidden;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.03);
  }
  .analytics-table-head {
    padding: 12px 18px;
    background: #F8FAFC;
    border-bottom: 1px solid #E2E8F0;
    font-weight: 700;
    font-size: 13px;
    color: var(--ink, #131D3B);
    display: flex;
    align-items: center;
    justify-content: space-between;
  }
  table.analytics-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 12px;
  }
  table.analytics-table thead th {
    background: #ffffff;
    font-size: 10.5px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.03em;
    color: #64748B;
    padding: 8px 12px;
    border-bottom: 1px solid #E2E8F0;
    text-align: left;
  }
  table.analytics-table tbody td {
    padding: 8px 12px;
    vertical-align: middle;
    border-bottom: 1px solid #F1F5F9;
    font-size: 12px;
  }
  table.analytics-table tbody tr:hover {
    background: #F8FAFC;
  }
  .share-bar-container {
    display: flex;
    align-items: center;
    gap: 8px;
  }
  .share-bar-track {
    flex: 1;
    height: 6px;
    background: #F1F5F9;
    border-radius: 999px;
    overflow: hidden;
    min-width: 60px;
    max-width: 140px;
  }
  .share-bar-fill {
    height: 100%;
    border-radius: 999px;
    transition: width 0.3s ease;
  }
  .rank-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 22px;
    height: 22px;
    border-radius: 50%;
    font-size: 11px;
    font-weight: 800;
  }
  .rank-badge.rank-1 { background: #FEF3C7; color: #B45309; border: 1px solid #FCD34D; }
  .rank-badge.rank-2 { background: #F1F5F9; color: #475569; border: 1px solid #CBD5E1; }
  .rank-badge.rank-3 { background: #FFEDD5; color: #C2410C; border: 1px solid #FDBA74; }
  .rank-badge.rank-other { background: #F8FAFC; color: #94A3B8; }

  /* Master Page-level View Bar */
  .page-view-bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 12px;
    margin-bottom: 22px;
    padding: 12px 20px;
    background: #ffffff;
    border: 1px solid var(--hairline, #E2E8F0);
    border-radius: 12px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.03);
  }

  /* Department Chart Styling & Controls */
  .dept-chart-controls {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
  }
  .dept-dist-card {
    padding: 18px 20px 20px;
    border-radius: 14px;
    box-shadow: 0 1px 2px rgba(19, 29, 59, 0.04), 0 4px 16px rgba(19, 29, 59, 0.04);
  }
  .dept-dist-head {
    flex-wrap: wrap;
    gap: 12px;
    margin-bottom: 14px;
    padding-bottom: 14px;
  }
  .dept-dist-heading {
    display: flex;
    align-items: center;
    gap: 12px;
  }
  .dept-dist-icon {
    width: 36px;
    height: 36px;
    border-radius: 10px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: #FFF1EA;
    color: #FF4F01;
    flex-shrink: 0;
  }
  .dept-dist-card .analytics-chart-title { font-size: 14px; }
  .dept-chart-frame {
    padding: 14px 16px 8px 6px;
    background: linear-gradient(180deg, #FBFCFE 0%, #FFFFFF 100%);
    border: 1px solid #EEF1F6;
    border-radius: 12px;
  }
  .dept-chart-legend {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 8px;
    margin-bottom: 14px;
  }
  .dept-legend-item {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 5px 10px 5px 9px;
    background: #fff;
    border: 1px solid #E6EAF2;
    border-radius: 999px;
    font: inherit;
    font-size: 11.5px;
    font-weight: 600;
    color: var(--ink, #131D3B);
    cursor: pointer;
    transition: background 0.15s ease, border-color 0.15s ease, opacity 0.15s ease;
  }
  .dept-legend-item:hover { background: #F8FAFC; border-color: #CBD5E1; }
  .dept-legend-item:focus-visible { outline: 2px solid #FF4F01; outline-offset: 2px; }
  .dept-legend-item.is-off { opacity: 0.45; }
  .dept-legend-item.is-off .dept-legend-dot { background: #CBD5E1 !important; }
  .dept-legend-item.is-off .dept-legend-name { text-decoration: line-through; }
  .dept-legend-dot {
    width: 9px;
    height: 9px;
    border-radius: 50%;
    flex-shrink: 0;
  }
  .dept-legend-count {
    font-size: 10.5px;
    font-weight: 700;
    color: var(--ink-muted, #5A6785);
    background: #F1F5F9;
    padding: 1px 7px;
    border-radius: 999px;
  }
  .dept-leaderboard-bar {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 14px;
  }
  .dept-leaderboard-label {
    font-size: 10.5px;
    font-weight: 700;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    color: var(--muted, #5A6785);
    margin-right: 4px;
  }
  .dept-chip {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 5px 12px;
    background: #F8FAFC;
    border: 1px solid #E2E8F0;
    border-radius: 8px;
    font-size: 11.5px;
    color: var(--ink, #131D3B);
    transition: all 0.15s ease;
  }
  .dept-chip:hover {
    background: #EFF6FF;
    border-color: #BFDBFE;
    color: #1D4ED8;
    transform: translateY(-1px);
  }
  .dept-chip-rank {
    font-weight: 800;
    font-size: 10.5px;
    padding: 1px 6px;
    border-radius: 4px;
    background: #E2E8F0;
    color: #475569;
  }
  .dept-chip.rank-1 .dept-chip-rank { background: #FEF3C7; color: #B45309; }
  .dept-chip.rank-2 .dept-chip-rank { background: #F1F5F9; color: #475569; }
  .dept-chip.rank-3 .dept-chip-rank { background: #FFEDD5; color: #C2410C; }

  /* Section 2: Category Analytics Layout */
  .cat-analytics-layout {
    display: grid;
    grid-template-columns: 1.15fr 1fr;
    gap: 18px;
    margin-bottom: 22px;
  }
  @media (max-width: 980px) {
    .cat-analytics-layout {
      grid-template-columns: 1fr;
    }
  }
  .cat-rank-list {
    display: flex;
    flex-direction: column;
    gap: 8px;
    margin-top: 10px;
    max-height: 290px;
    overflow-y: auto;
    padding-right: 4px;
  }
  .cat-rank-row {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 7px 10px;
    border-radius: 8px;
    background: #F8FAFC;
    border: 1px solid #F1F5F9;
    font-size: 12px;
    transition: all 0.15s ease;
  }
  .cat-rank-row:hover {
    background: #ffffff;
    border-color: #CBD5E1;
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.04);
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
    /* These captions are two words at most; wrapping reads better than
       clipping "Total Students" to "Total Stud…". */
    white-space: normal;
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

  /* Performance Matrix (faculty + student) — compact, no horizontal scroll */
  .matrix-card {
    border-radius: 14px;
    box-shadow: 0 1px 2px rgba(19, 29, 59, 0.04), 0 6px 18px rgba(19, 29, 59, 0.05);
  }
  .matrix-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 12px;
  }
  .matrix-heading {
    display: flex;
    align-items: center;
    gap: 12px;
    min-width: 0;
  }
  .matrix-head-icon {
    flex: 0 0 auto;
    width: 36px;
    height: 36px;
    border-radius: 10px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: #FFF1EA;
    color: #FF4F01;
  }
  .matrix-head-icon.is-navy {
    background: #EEF2FF;
    color: #3B4BC8;
  }
  .matrix-heading .card-title {
    display: flex;
    align-items: center;
    gap: 8px;
  }
  .matrix-count {
    display: inline-flex;
    align-items: center;
    height: 20px;
    padding: 0 8px;
    border-radius: 999px;
    background: #F1F4F9;
    color: #475569;
    font-size: 11px;
    font-weight: 700;
    font-variant-numeric: tabular-nums;
  }

  .matrix-wrap {
    max-height: 520px;
    overflow-y: auto !important;
    overflow-x: hidden !important;
    border: 1px solid #EEF1F6;
    border-radius: 12px;
    background: #ffffff;
  }
  .matrix-wrap::-webkit-scrollbar {
    width: 6px;
    height: 0px !important;
    display: block;
  }
  .matrix-wrap::-webkit-scrollbar-track {
    background: transparent;
  }
  .matrix-wrap::-webkit-scrollbar-thumb {
    background: #D5DCE6;
    border-radius: 6px;
  }
  .matrix-wrap::-webkit-scrollbar-thumb:hover {
    background: #94A3B8;
  }
  table.matrix-table {
    width: 100% !important;
    min-width: 0 !important;
    max-width: 100% !important;
    table-layout: fixed !important;
    border-collapse: separate !important;
    border-spacing: 0 !important;
    font-size: 11.5px !important;
    margin: 0 !important;
  }
  table.matrix-table thead th {
    position: sticky;
    top: 0;
    z-index: 10;
    background: #FAFBFD !important;
    font-size: 9.5px !important;
    font-weight: 700 !important;
    text-transform: uppercase !important;
    letter-spacing: 0.06em !important;
    color: #64748B !important;
    padding: 10px 2px 9px !important;
    line-height: 14px !important;
    border-bottom: 1px solid #E6EAF2 !important;
    white-space: nowrap !important;
    text-align: center !important;
    overflow: hidden !important;
    text-overflow: ellipsis !important;
  }
  /* Category columns carry a thin colour key under their heading */
  table.matrix-table thead th.mx-cat {
    box-shadow: inset 0 -2px 0 var(--c, #CBD5E1);
  }
  table.matrix-table thead th.text-start {
    text-align: left !important;
    padding-left: 10px !important;
  }
  table.matrix-table tbody td {
    padding: 7px 2px !important;
    font-size: 11.5px !important;
    vertical-align: middle !important;
    border-bottom: 1px solid #F2F4F8 !important;
    overflow: hidden !important;
    text-overflow: ellipsis !important;
    white-space: nowrap !important;
    transition: background 0.12s ease;
  }
  table.matrix-table tbody tr.drill-row:hover td {
    background: #FAFBFF !important;
  }
  table.matrix-table tbody tr.drill-row:hover td:first-child {
    box-shadow: inset 3px 0 0 #FF4F01;
  }
  table.matrix-table tbody tr.drill-row:hover .mx-name {
    color: #FF4F01;
  }

  /* Department group rows */
  table.matrix-table tbody tr.mx-group td {
    position: sticky;
    top: 33px;
    z-index: 5;
    padding: 0 !important;
    background: #F6F8FB !important;
    border-top: 1px solid #E6EAF2 !important;
    border-bottom: 1px solid #E6EAF2 !important;
  }
  .mx-group-inner {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 7px 12px;
    box-shadow: inset 3px 0 0 #131D3B;
    font-size: 11.5px;
    font-weight: 700;
    color: #1E293B;
    min-width: 0;
  }
  .mx-group-inner svg {
    color: #64748B;
    flex: 0 0 auto;
  }
  .mx-group-name {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    min-width: 0;
  }
  .mx-group-chip {
    flex: 0 0 auto;
    display: inline-flex;
    align-items: center;
    height: 18px;
    padding: 0 7px;
    border-radius: 999px;
    background: #ffffff;
    border: 1px solid #E2E8F0;
    color: #64748B;
    font-size: 10.5px;
    font-weight: 600;
  }
  .mx-group-total {
    margin-left: auto;
    flex: 0 0 auto;
    font-size: 10.5px;
    font-weight: 600;
    color: #64748B;
  }
  .mx-group-total b {
    color: #E04400;
    font-weight: 800;
    font-variant-numeric: tabular-nums;
  }

  /* Person cell: initials avatar + name + meta line */
  .mx-person {
    display: flex;
    align-items: center;
    gap: 9px;
    min-width: 0;
    padding-left: 8px;
  }
  .mx-avatar {
    flex: 0 0 auto;
    width: 28px;
    height: 28px;
    border-radius: 50%;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 10.5px;
    font-weight: 800;
    letter-spacing: 0.02em;
    color: var(--c, #475569);
    background: #F1F4F9;
    background: color-mix(in srgb, var(--c, #475569) 13%, #ffffff);
  }
  .mx-person-text {
    min-width: 0;
    line-height: 1.25;
  }
  .mx-name {
    font-weight: 650;
    color: var(--ink, #131D3B);
    font-size: 12px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    transition: color 0.12s ease;
  }
  .mx-meta {
    font-size: 10px;
    color: #8A94A8;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
  .mx-muted {
    font-size: 11px;
    color: #64748B;
    padding-left: 8px !important;
  }
  .mx-mono {
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
    font-size: 10.5px;
    color: #475569;
    padding-left: 8px !important;
  }

  /* Count cells */
  .matrix-cell-num {
    text-align: center !important;
    font-variant-numeric: tabular-nums !important;
  }
  .matrix-zero {
    color: #D5DCE6 !important;
    font-weight: 500 !important;
    font-size: 11px !important;
  }
  .matrix-val {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 22px;
    height: 20px;
    padding: 0 5px;
    border-radius: 6px;
    font-weight: 700 !important;
    font-size: 11.5px !important;
    color: var(--c, #0F172A) !important;
    background: #F1F4F9;
    background: color-mix(in srgb, var(--c, #0F172A) 11%, #ffffff);
  }
  .matrix-total {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 26px;
    height: 22px;
    padding: 0 6px;
    border-radius: 999px;
    font-size: 11.5px;
    font-weight: 800;
    font-variant-numeric: tabular-nums;
    background: #F4F6FA;
    color: #A0AABB;
  }
  .matrix-total.is-active {
    background: #FF4F01 !important;
    color: #ffffff !important;
    box-shadow: 0 2px 6px rgba(255, 79, 1, 0.25);
  }

  /* Row actions */
  .matrix-actions {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 4px;
    width: 100%;
    white-space: nowrap;
  }
  .matrix-btn {
    height: 24px !important;
    line-height: 24px !important;
    padding: 0 8px !important;
    font-size: 10.5px !important;
    font-weight: 700 !important;
    border-radius: 7px !important;
    display: inline-flex !important;
    align-items: center !important;
    gap: 4px !important;
    white-space: nowrap !important;
    border: 1px solid transparent !important;
    text-decoration: none !important;
    cursor: pointer !important;
    transition: background 0.12s ease, color 0.12s ease, border-color 0.12s ease, transform 0.12s ease;
  }
  .matrix-btn:hover {
    transform: translateY(-1px);
  }
  .matrix-btn-primary {
    background: #FFF1EA !important;
    color: #E04400 !important;
    border-color: #FFD9C6 !important;
  }
  .matrix-btn-primary:hover {
    background: #FF4F01 !important;
    border-color: #FF4F01 !important;
    color: #ffffff !important;
  }
  .matrix-btn-navy {
    background: #131D3B !important;
    color: #ffffff !important;
  }
  .matrix-btn-navy:hover {
    background: #26345F !important;
    color: #ffffff !important;
  }
  .matrix-btn-outline {
    background: #ffffff !important;
    color: #475569 !important;
    border-color: #E2E8F0 !important;
  }
  .matrix-btn-outline:hover {
    background: #F1F5F9 !important;
    color: #0F172A !important;
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
    <span class="fb-k">EM Duration</span>
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

<!-- Global Display Mode Switcher (Sync all sections) -->
<div class="page-view-bar mt-4">
  <div style="display:flex; align-items:center; gap:10px;">
    <div style="width:34px; height:34px; border-radius:8px; background:var(--navy-50,#F4F6FA); display:grid; place-items:center; color:var(--brand,#FF4F01);">
      <?= icon('eye', 16) ?>
    </div>
    <div>
      <div style="font-size:13px; font-weight:700; color:var(--ink,#131D3B);">Global Display Mode</div>
      <div style="font-size:11px; color:var(--muted,#5A6785);">Synchronize all sections between analytical data and executive analytics views</div>
    </div>
  </div>
  <div class="pill-toggle-group">
    <button type="button" class="btn-pill" id="btnGlobalTable" onclick="setGlobalView('data')"><?= icon('table', 13) ?> All Data View</button>
    <button type="button" class="btn-pill active" id="btnGlobalGraph" onclick="setGlobalView('analytics')"><?= icon('bar-chart', 13) ?> All Analytics View</button>
  </div>
</div>

<!-- Section 1: Department Performance Analysis -->
<div class="mt-4 card">
  <div class="card-head">
    <div>
      <div class="card-title"><?= icon('bar-chart', 16) ?> Department Performance Analysis</div>
      <div class="card-sub">Comparative metrics of faculty contributions across academic departments</div>
    </div>
  </div>

  <div class="card-body">
    <?php if (empty($deptComp) || $totalDeptRecords === 0): ?>
      <div class="empty">
        <div class="ic"><?= icon('bar-chart', 20) ?></div>
        <p>No achievements found for the selected filters</p>
        <div class="note">Try adjusting the department, academic year, or category filters.</div>
      </div>
    <?php else: ?>
      <?php
        $topDeptShare = $totalDeptRecords > 0 && $topDept ? round(($topDept['total'] / $totalDeptRecords) * 100, 1) : 0;
        $avgPerActiveDept = $activeDeptsCount > 0 ? round($totalDeptRecords / $activeDeptsCount, 1) : 0;
      ?>
      <div class="analytics-kpi-grid" style="grid-template-columns: repeat(4, minmax(0, 1fr)); margin-bottom:18px;">
        <div class="analytics-kpi-card">
          <div class="analytics-kpi-top">
            <span class="analytics-kpi-label">Top Department</span>
            <span class="analytics-kpi-icon orange"><?= icon('award', 14) ?></span>
          </div>
          <div class="analytics-kpi-val truncate" title="<?= e($topDept['department'] ?? 'None') ?>"><?= e($topDept['department'] ?? 'None') ?></div>
          <div class="analytics-kpi-sub"><?= number_format((int)($topDept['total'] ?? 0)) ?> verified achievements &middot; <?= $topDeptShare ?>%</div>
        </div>
        <div class="analytics-kpi-card">
          <div class="analytics-kpi-top">
            <span class="analytics-kpi-label">Total Verified Records</span>
            <span class="analytics-kpi-icon blue"><?= icon('layers', 14) ?></span>
          </div>
          <div class="analytics-kpi-val brand"><?= number_format($totalDeptRecords) ?></div>
          <div class="analytics-kpi-sub">Across <?= $totalDeptsCount ?> academic departments</div>
        </div>
        <div class="analytics-kpi-card">
          <div class="analytics-kpi-top">
            <span class="analytics-kpi-label">Active Departments</span>
            <span class="analytics-kpi-icon teal"><?= icon('building', 14) ?></span>
          </div>
          <div class="analytics-kpi-val"><?= (int) $activeDeptsCount ?> <span style="font-size:13px; font-weight:500; color:var(--muted);">/ <?= $totalDeptsCount ?></span></div>
          <div class="analytics-kpi-sub">Departments with recorded entries</div>
        </div>
        <div class="analytics-kpi-card">
          <div class="analytics-kpi-top">
            <span class="analytics-kpi-label">Avg. Output / Active</span>
            <span class="analytics-kpi-icon purple"><?= icon('trending-up', 14) ?></span>
          </div>
          <div class="analytics-kpi-val"><?= $avgPerActiveDept ?></div>
          <div class="analytics-kpi-sub">Achievements per active dept</div>
        </div>
      </div>

      <div class="analytics-chart-card dept-dist-card">
        <div class="analytics-chart-head dept-dist-head">
          <div class="dept-dist-heading">
            <span class="dept-dist-icon"><?= icon('bar-chart', 16) ?></span>
            <div>
              <div class="analytics-chart-title">Department Achievement Distribution</div>
              <div class="analytics-chart-sub">Ranked faculty contributions by department &amp; category breakdown</div>
            </div>
          </div>
          <div class="dept-chart-controls">
            <!-- View: Category mix vs Total -->
            <div class="pill-toggle-group">
              <button type="button" class="btn-pill active" id="btnDeptTypeStacked" onclick="setDeptChartType('stacked')">Category Mix</button>
              <button type="button" class="btn-pill" id="btnDeptTypeTotal" onclick="setDeptChartType('total')">Total</button>
            </div>
            <!-- Filter: Active vs All -->
            <div class="pill-toggle-group">
              <button type="button" class="btn-pill active" id="btnDeptFilterActive" onclick="setDeptChartFilter('active')">Active (<?= $activeDeptsCount ?>)</button>
              <button type="button" class="btn-pill" id="btnDeptFilterAll" onclick="setDeptChartFilter('all')">All (<?= $totalDeptsCount ?>)</button>
            </div>
          </div>
        </div>

        <!-- 1A. Analytic View: Chart.js Bar Chart -->
        <div id="deptAnalyticsView">
          <!-- Stacked View: interactive category legend (built in JS) -->
          <div id="deptLegendBar" class="dept-chart-legend" role="group" aria-label="Toggle categories"></div>

          <div class="dept-chart-frame">
            <div class="analytics-chart-body" id="deptChartContainer" style="height:280px; position:relative;">
              <canvas id="deptCompChart"></canvas>
            </div>
          </div>

          <!-- Total View: Leaderboard Chips -->
          <div id="deptLeaderboardBar" class="dept-leaderboard-bar" style="display:none;">
            <span class="dept-leaderboard-label">Department Ranking</span>
            <?php foreach ($activeDeptComp as $idx => $dc): ?>
              <?php $pct = $totalDeptRecords > 0 ? round(($dc['total'] / $totalDeptRecords) * 100, 1) : 0; ?>
              <div class="dept-chip <?= $idx < 3 ? 'rank-' . ($idx + 1) : '' ?>" title="<?= e($dc['department']) ?>: <?= (int)$dc['total'] ?> verified achievements (<?= $pct ?>%)">
                <span class="dept-chip-rank"><?= $idx + 1 ?></span>
                <strong><?= e($dc['department']) ?></strong>
                <span style="color:var(--brand,#FF4F01); font-weight:700;"><?= (int)$dc['total'] ?></span>
                <span style="font-size:10.5px; color:var(--ink-faint,#8B96AE);">(<?= $pct ?>%)</span>
              </div>
            <?php endforeach; ?>
          </div>
        </div>



      </div>
    <?php endif; ?>
  </div>
</div>

<!-- Section 2: Records by Category (With Data View / Analytics View Toggle & View Reports button) -->
<div class="mt-5 card">
  <div class="card-head" style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px;">
    <div>
      <div class="card-title"><?= icon('layers', 16) ?> Records by Category</div>
      <div class="card-sub">Every metric, grouped by what it measures &middot; <?= number_format($summary['totalAchievements']) ?> total verified records</div>
    </div>

    <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
      <!-- Toggle Buttons: Data View | Analytics View -->
      <div class="pill-toggle-group">
        <button type="button" class="btn-pill" id="btnCatData" onclick="switchCatView('data')"><?= icon('table', 13) ?> Data View</button>
        <button type="button" class="btn-pill active" id="btnCatAnalytics" onclick="switchCatView('analytics')"><?= icon('bar-chart', 13) ?> Analytics View</button>
      </div>

      <a href="<?= e(url('reports.php')) ?>" class="btn btn-primary btn-sm" style="background:#FF4F01; border-color:#FF4F01; border-radius:8px; padding:6px 14px; font-weight:700;">
        <?= icon('file-text', 13) ?> View Reports
      </a>
    </div>
  </div>

  <div class="card-body">
    <!-- 2A. Analytics View: Visual Chart + Ranked Share List + Category Quick Cards -->
    <div id="catAnalyticsView">
      <?php if (empty($activeCatBreakdown) || $summary['totalAchievements'] === 0): ?>
        <div class="empty">
          <div class="ic"><?= icon('layers', 20) ?></div>
          <p>No verified category records found for current filters</p>
        </div>
      <?php else: ?>
        <div class="cat-analytics-layout">
          <!-- Left Column: Interactive Chart Card -->
          <div class="analytics-chart-card">
            <div class="analytics-chart-head">
              <div>
                <div class="analytics-chart-title"><?= icon('pie-chart', 14) ?> Category Distribution Share</div>
                <div class="analytics-chart-sub">Proportional breakdown of institution achievements</div>
              </div>
              <div class="pill-toggle-group">
                <button type="button" class="btn-pill active" id="btnCatChartDonut" onclick="setCatChartType('donut')"><?= icon('pie-chart', 12) ?> Donut Chart</button>
                <button type="button" class="btn-pill" id="btnCatChartBar" onclick="setCatChartType('bar')"><?= icon('bar-chart', 12) ?> Ranked Bar</button>
              </div>
            </div>
            <div class="analytics-chart-body" id="catChartContainer" style="height:300px; position:relative;">
              <canvas id="catDonutChart"></canvas>
              <canvas id="catBarChart" style="display:none;"></canvas>
            </div>
          </div>

          <!-- Right Column: Category Performance Ranking -->
          <div class="analytics-chart-card">
            <div class="analytics-chart-head">
              <div>
                <div class="analytics-chart-title"><?= icon('award', 14) ?> Category Performance Ranking</div>
                <div class="analytics-chart-sub">Top categories sorted by volume &amp; share of total</div>
              </div>
              <span class="badge badge-neutral" style="font-size:11px;"><?= count($activeCatBreakdown) ?> Active Categories</span>
            </div>
            <div class="cat-rank-list">
              <?php foreach ($activeCatBreakdown as $cIdx => $cat): ?>
                <?php
                  $cShare = $summary['totalAchievements'] > 0 ? round(($cat['count'] / $summary['totalAchievements']) * 100, 1) : 0;
                ?>
                <div class="cat-rank-row">
                  <span class="rank-badge <?= $cIdx < 3 ? 'rank-' . ($cIdx + 1) : 'rank-other' ?>" style="width:20px; height:20px; font-size:10px;">
                    <?= $cIdx + 1 ?>
                  </span>
                  <div style="flex:1; min-width:0;">
                    <div style="display:flex; align-items:center; justify-content:space-between; gap:6px;">
                      <div class="truncate" style="font-weight:700; color:var(--ink,#131D3B); font-size:12px;" title="<?= e($cat['label']) ?>">
                        <span style="display:inline-block; width:8px; height:8px; border-radius:50%; background:<?= $cat['color'] ?>; margin-right:4px;"></span>
                        <?= e($cat['label']) ?>
                      </div>
                      <div style="font-weight:800; color:var(--brand,#FF4F01); font-size:12.5px;"><?= number_format($cat['count']) ?></div>
                    </div>
                    <div style="display:flex; align-items:center; gap:8px; margin-top:4px;">
                      <div class="share-bar-track" style="height:5px; flex:1;">
                        <div class="share-bar-fill" style="width:<?= $cShare ?>%; background:<?= $cat['color'] ?>;"></div>
                      </div>
                      <span style="font-size:10.5px; font-weight:600; color:var(--muted,#5A6785); min-width:34px; text-align:right;"><?= $cShare ?>%</span>
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
      <?php endif; ?>

      <!-- Quick Cards Grid for all categories -->
      <div style="font-size:11.5px; font-weight:700; text-transform:uppercase; letter-spacing:0.04em; color:var(--muted,#5A6785); margin-bottom:12px; display:flex; align-items:center; gap:6px;">
        <?= icon('layers', 13) ?> All Category Metrics Overview
      </div>
      <div class="cat-cards-grid">
        <?php foreach ($categoryBreakdown as $cMeta): ?>
          <div class="cat-card">
            <div class="cat-card-ic <?= $cMeta['cls'] ?>">
              <?= icon($cMeta['icon'], 20) ?>
            </div>
            <div style="min-width:0; flex:1;">
              <div class="cat-card-title truncate" title="<?= e($cMeta['label']) ?>"><?= e($cMeta['label']) ?></div>
              <div class="cat-card-val"><?= number_format($cMeta['count']) ?></div>
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
              <th style="width:40px; text-align:center;">#</th>
              <th>Achievement Metric Category</th>
              <th>Classification Group</th>
              <th class="num">Record Count</th>
              <th style="width:200px;">Share of Total</th>
              <th style="text-align:center; width:120px;">Action</th>
            </tr>
          </thead>
          <tbody>
            <?php 
              $sNoCat = 1;
              foreach ($categoryBreakdown as $cat): 
                $cnt = (int) $cat['count'];
                $pct = $summary['totalAchievements'] > 0 ? round(($cnt / $summary['totalAchievements']) * 100, 1) : 0;
            ?>
              <tr>
                <td style="text-align:center; color:var(--ink-faint,#8B96AE); font-size:11px;"><?= $sNoCat++ ?></td>
                <td class="fw-600" style="color:var(--ink,#131D3B);">
                  <div style="display:flex; align-items:center; gap:8px;">
                    <span style="display:inline-block; width:10px; height:10px; border-radius:50%; background:<?= $cat['color'] ?>; flex-shrink:0;"></span>
                    <span class="truncate" title="<?= e($cat['label']) ?>"><?= e($cat['label']) ?></span>
                  </div>
                </td>
                <td><span class="badge badge-neutral"><?= e($cat['group']) ?></span></td>
                <td class="num tabular fw-700" style="font-size:14px; color:var(--brand,#FF4F01);"><?= number_format($cnt) ?></td>
                <td>
                  <div class="share-bar-container" style="display:flex; align-items:center; gap:8px;">
                    <div class="share-bar-track" style="flex:1; height:6px; background:#F1F5F9; border-radius:3px; overflow:hidden;">
                      <div class="share-bar-fill" style="width:<?= $pct ?>%; height:100%; background:<?= $cat['color'] ?>; border-radius:3px;"></div>
                    </div>
                    <span class="num tabular" style="font-size:11.5px; font-weight:700; color:var(--ink-muted,#5A6785); min-width:38px; text-align:right;"><?= $pct ?>%</span>
                  </div>
                </td>
                <td style="text-align:center;">
                  <a href="<?= e(url('faculty-achievements.php')) ?>?category=<?= urlencode((string)$cat['key']) ?>&academic_year=<?= urlencode((string)$academicYear) ?>&department=<?= urlencode((string)$department) ?>" class="matrix-btn matrix-btn-outline" style="font-size:11px; padding:3px 10px;" title="Filter by <?= e($cat['label']) ?>">
                    <?= icon('filter', 11) ?> Filter
                  </a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<?php
// Shared cell renderers for the faculty and student matrices.
$mxCell = static function ($value, string $color): string {
    $value = (int) $value;
    return $value > 0
        ? '<span class="matrix-val" style="--c:' . $color . ';">' . $value . '</span>'
        : '<span class="matrix-zero">&ndash;</span>';
};
$mxAvatar = static function (string $name): string {
    $palette = ['#2563EB', '#10B981', '#FF4F01', '#E11D48', '#7C3AED', '#0891B2', '#D97706', '#131D3B'];
    $clean   = trim(preg_replace('/^(prof|dr|mr|mrs|ms|miss)\.?\s+/i', '', trim($name)));
    $words   = preg_split('/[^\p{L}\p{N}]+/u', $clean, -1, PREG_SPLIT_NO_EMPTY) ?: ['?'];
    $initials = mb_strtoupper(mb_substr($words[0], 0, 1) . (isset($words[1]) ? mb_substr($words[1], 0, 1) : ''));
    $color   = $palette[abs(crc32($clean)) % count($palette)];
    return '<span class="mx-avatar" style="--c:' . $color . ';" aria-hidden="true">' . e($initials) . '</span>';
};
?>

<!-- Section 3: Highest Achievement Contributors & Main Performance Matrix -->
<div class="mt-5 grid-2-1 gap-5" style="display:grid; grid-template-columns: 2fr 1fr; gap:20px;">
  <!-- Main Faculty Achievement Table Card -->
  <div class="card matrix-card" style="grid-column: span 2;">
    <div class="card-head matrix-head">
      <div class="matrix-heading">
        <span class="matrix-head-icon"><?= icon('users', 16) ?></span>
        <div>
          <div class="card-title">Faculty Achievement Performance Matrix <span class="matrix-count"><?= count($facGrid) ?></span></div>
          <div class="card-sub">Faculty grouped by department &middot; Click any row to open the full record</div>
        </div>
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

    <!-- 3A. DATA VIEW: Matrix Table -->
    <div id="facDataView" class="card-body pt-0">
      <?php if (empty($facGrid)): ?>
        <div class="empty">
          <div class="ic"><?= icon('users', 20) ?></div>
          <p>No faculty achievements found</p>
          <div class="note">Try changing the selected department, academic year, faculty, or category filters.</div>
        </div>
      <?php else: ?>
        <div class="matrix-wrap">
          <table class="data wide sortable matrix-table" id="facTable">
            <?php
              // Same palette as the Department Achievement Distribution chart.
              $facCols = [
                  'journals'    => ['Jour.',   'Journals',                '#2563EB'],
                  'conferences' => ['Conf.',   'Conferences',             '#10B981'],
                  'books'       => ['Books',   'Books / Chapters',        '#FF4F01'],
                  'events'      => ['Events',  'Events / Workshops',      '#E11D48'],
                  'training'    => ['Train.',  'FDP / Training Programs', '#7C3AED'],
                  'patents'     => ['Patents', 'Patents Filed / Granted', '#0891B2'],
                  'other'       => ['Other',   'Other Achievements',      '#64748B'],
              ];
            ?>
            <colgroup>
              <col style="width: 3.5%;">
              <col style="width: 22%;">
              <col style="width: 11%;">
              <?php foreach ($facCols as $_): ?><col style="width: 5%;"><?php endforeach; ?>
              <col style="width: 5.5%;">
              <col style="width: 23%;">
            </colgroup>
            <thead>
              <tr>
                <th style="text-align:center;">#</th>
                <th class="text-start">Faculty Member</th>
                <th class="text-start">Department</th>
                <?php foreach ($facCols as [$short, $long, $color]): ?>
                  <th class="num mx-cat" style="--c:<?= $color ?>;" title="<?= e($long) ?>"><?= e($short) ?></th>
                <?php endforeach; ?>
                <th class="num" title="Total Achievements">Total</th>
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
                <tr class="mx-group">
                  <td colspan="12">
                    <div class="mx-group-inner">
                      <?= icon('building', 13) ?>
                      <span class="mx-group-name" title="<?= e($deptName) ?>"><?= e($deptName) ?></span>
                      <span class="mx-group-chip"><?= count($deptFaculty) ?> faculty</span>
                      <span class="mx-group-total"><b><?= array_sum(array_map(static fn($r) => (int) $r['total'], $deptFaculty)) ?></b> records</span>
                    </div>
                  </td>
                </tr>
                <?php foreach ($deptFaculty as $f): ?>
                  <tr class="drill-row" onclick="location.href='individual-faculty-report.php?id=<?= (int) $f['id'] ?>'" style="cursor:pointer;" title="Click to view <?= e($f['name']) ?>'s Individual Faculty Achievement Report">
                    <td class="faint matrix-cell-num"><?= $sno++ ?></td>
                    <td class="text-start">
                      <div class="mx-person">
                        <?= $mxAvatar((string) $f['name']) ?>
                        <div class="mx-person-text">
                          <div class="mx-name" title="<?= e($f['name']) ?>"><?= e($f['name']) ?></div>
                          <div class="mx-meta"><?= e($f['designation']) ?> &middot; <?= e($f['employee_id']) ?></div>
                        </div>
                      </div>
                    </td>
                    <td class="text-start mx-muted" title="<?= e($f['department']) ?>">
                      <div class="truncate"><?= e($f['department']) ?></div>
                    </td>
                    <?php foreach ($facCols as $key => [, , $color]): ?>
                      <td class="matrix-cell-num"><?= $mxCell($f[$key], $color) ?></td>
                    <?php endforeach; ?>
                    <td class="matrix-cell-num">
                      <span class="matrix-total <?= (int) $f['total'] > 0 ? 'is-active' : '' ?>"><?= (int) $f['total'] ?></span>
                    </td>
                    <td style="text-align:center;" onclick="event.stopPropagation();">
                      <div class="matrix-actions">
                        <a href="<?= e(url('individual-faculty-report.php')) ?>?id=<?= (int) $f['id'] ?>" class="matrix-btn matrix-btn-primary" title="View Individual Faculty Report">
                          <?= icon('file-text', 11) ?> Report
                        </a>
                        <a href="<?= e(url('faculty-details-report.php')) ?>?id=<?= (int) $f['id'] ?><?= !empty($academicYear) ? '&academic_year=' . urlencode($academicYear) : '' ?><?= $em !== 'all' ? '&em=' . urlencode($em) : '' ?>" target="_blank" rel="noopener" class="matrix-btn matrix-btn-outline" title="Open <?= e($f['name']) ?>'s Faculty Details as an A4 document">
                          <?= icon('download', 11) ?> PDF
                        </a>
                        <?php
                          $facPresQ = ['id' => (int) $f['id'], 'from' => 'faculty-achievements'];
                          if (!empty($academicYear)) $facPresQ['academic_year'] = $academicYear;
                          if (!empty($department))   $facPresQ['return_dept']   = $department;
                          if (!empty($category))     $facPresQ['return_cat']    = $category;
                          $facPresUrl = url('present-faculty-report.php') . '?' . http_build_query($facPresQ);
                        ?>
                        <a href="<?= e($facPresUrl) ?>" class="matrix-btn matrix-btn-navy" title="Present Faculty Report">
                          <?= icon('play-circle', 11) ?> Present
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

<!-- Section 4: Student Achievement Performance Matrix (Dedicated Data Matrix Box) -->
<div class="mt-5 card matrix-card">
  <div class="card-head matrix-head">
    <div class="matrix-heading">
      <span class="matrix-head-icon is-navy"><?= icon('graduation', 16) ?></span>
      <div>
        <div class="card-title">Student Achievement Performance Matrix <span class="matrix-count"><?= count($studGrid) ?></span></div>
        <div class="card-sub">Students grouped by department &middot; Click any row to open the full record</div>
      </div>
    </div>

    <!-- Student Quick Search Input -->
    <form method="get" class="fbar fbar-bare" style="margin:0;">
      <input type="hidden" name="academic_year" value="<?= e($academicYear) ?>">
      <input type="hidden" name="department" value="<?= e($department) ?>">
      <input type="hidden" name="category" value="<?= e($category) ?>">
      <input type="hidden" name="em" value="<?= e($em) ?>">
      <input type="hidden" name="q" value="<?= e($searchQuery) ?>">
      <label class="fb-field fb-search" style="min-width:240px;">
        <?= icon('search', 14) ?>
        <input type="search" name="student_search" value="<?= e($studentSearch) ?>" placeholder="Search student name..." onchange="this.form.submit()">
      </label>
    </form>
  </div>

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
        <div class="matrix-wrap" style="margin-top:14px;">
          <table class="data wide sortable matrix-table" id="studTable">
            <?php
              $studCols = [
                  'nptel'          => ['NPTEL',   'NPTEL Certifications',     '#2563EB'],
                  'internships'    => ['Intern',  'Internships',              '#10B981'],
                  'placements'     => ['Place',   'Placements',               '#FF4F01'],
                  'online_courses' => ['Course',  'Online Courses',           '#0891B2'],
                  'achievements'   => ['Achieve', 'Student Achievements',     '#D97706'],
                  'participation'  => ['Partic',  'Student Participations',   '#E11D48'],
                  'training'       => ['Train',   'Summer / Winter Training', '#7C3AED'],
                  'other'          => ['Other',   'Other Achievements',       '#64748B'],
              ];
            ?>
            <colgroup>
              <col style="width: 3.5%;">
              <col style="width: 18%;">
              <col style="width: 10.5%;">
              <col style="width: 9%;">
              <?php foreach ($studCols as $_): ?><col style="width: 5%;"><?php endforeach; ?>
              <col style="width: 5.5%;">
              <col style="width: 13.5%;">
            </colgroup>
            <thead>
              <tr>
                <th style="text-align:center;">#</th>
                <th class="text-start">Student</th>
                <th class="text-start">Register No</th>
                <th class="text-start">Department</th>
                <?php foreach ($studCols as [$short, $long, $color]): ?>
                  <th class="num mx-cat" style="--c:<?= $color ?>;" title="<?= e($long) ?>"><?= e($short) ?></th>
                <?php endforeach; ?>
                <th class="num" title="Total Achievements">Total</th>
                <th style="text-align:center;">Actions</th>
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
                <tr class="mx-group">
                  <td colspan="14">
                    <div class="mx-group-inner">
                      <?= icon('building', 13) ?>
                      <span class="mx-group-name" title="<?= e($deptName) ?>"><?= e($deptName) ?></span>
                      <span class="mx-group-chip"><?= count($deptStudents) ?> <?= count($deptStudents) === 1 ? 'student' : 'students' ?></span>
                      <span class="mx-group-total"><b><?= array_sum(array_map(static fn($r) => (int) $r['total'], $deptStudents)) ?></b> records</span>
                    </div>
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
                        'from'          => 'faculty-achievements',
                    ]);
                  ?>
                  <tr class="drill-row" style="cursor:pointer;" title="Click to view <?= e($s['student_name']) ?>'s Individual Student Achievement Report" onclick="location.href='<?= e($studReportUrl) ?>'">
                    <td class="faint matrix-cell-num"><?= $studSno++ ?></td>
                    <td class="text-start">
                      <div class="mx-person">
                        <?= $mxAvatar((string) $s['student_name']) ?>
                        <div class="mx-person-text">
                          <div class="mx-name" title="<?= e($s['student_name']) ?>"><?= e($s['student_name']) ?></div>
                        </div>
                      </div>
                    </td>
                    <td class="text-start mx-mono" title="<?= e($s['reg_no']) ?>">
                      <div class="truncate"><?= e($s['reg_no']) ?></div>
                    </td>
                    <td class="text-start mx-muted" title="<?= e($s['department']) ?>">
                      <div class="truncate"><?= e($s['department']) ?></div>
                    </td>
                    <?php foreach ($studCols as $key => [, , $color]): ?>
                      <td class="matrix-cell-num"><?= $mxCell($s[$key], $color) ?></td>
                    <?php endforeach; ?>
                    <td class="matrix-cell-num">
                      <span class="matrix-total <?= (int) $s['total'] > 0 ? 'is-active' : '' ?>"><?= (int) $s['total'] ?></span>
                    </td>
                    <td style="text-align:center;" onclick="event.stopPropagation();">
                      <div class="matrix-actions">
                        <a href="<?= e($studReportUrl) ?>" class="matrix-btn matrix-btn-primary" title="View Individual Student Report">
                          <?= icon('file-text', 11) ?> Report
                        </a>
                        <a href="<?= e($studPresentUrl) ?>" class="matrix-btn matrix-btn-navy" title="Present Student Report">
                          <?= icon('play-circle', 11) ?> Present
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


<?php
$facReportQs = ['academic_year' => $academicYear];
if ($em !== 'all') { $facReportQs['em'] = $em; }
$facReportUrl = url('individual-faculty-report.php') . '?' . http_build_query($facReportQs);
?>
<div class="mt-5 card fac-deanband">
  <div class="card-body fac-deanband-row">
    <div class="fac-deanband-copy">
      <div class="fac-deanband-k"><?= icon('reports', 13) ?> Dean &middot; Academics</div>
      <div class="fac-deanband-t">Individual Report &amp; Achievements</div>
      <div class="card-sub fac-deanband-s">
        Opens the dedicated single-page view &mdash; profile, achievement summary, category
        breakdown and every record behind the figures above, on one page ready to sign.
      </div>
    </div>
    <div class="fac-deanband-actions">
      <a class="btn btn-primary" href="<?= e($facReportUrl) ?>">
        <?= icon('reports', 16) ?> Open Single-Page Report
      </a>
      <a class="btn btn-secondary" href="<?= e(url('present-faculty-report.php') . '?' . http_build_query($facReportQs)) ?>">
        <?= icon('presentation', 16) ?> Present
      </a>
    </div>
  </div>
</div>

<style>
  .fac-deanband { border-color: var(--navy-200, #C9D2E4); }
  .fac-deanband-row { display:flex; align-items:center; justify-content:space-between;
      gap:16px 28px; flex-wrap:wrap; padding:20px 24px; }
  .fac-deanband-copy { flex:1 1 320px; min-width:0; }
  .fac-deanband-k { display:flex; align-items:center; gap:6px; font-size:11px; font-weight:700;
      text-transform:uppercase; letter-spacing:.06em; color:var(--brand, #FF4F01); }
  .fac-deanband-t { font-size:17px; font-weight:700; color:var(--ink, #131D3B); margin-top:5px; }
  .fac-deanband-s { font-size:12.5px; margin-top:3px; max-width:78ch; }
  .fac-deanband-actions { display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
  @media (max-width: 640px) {
    .fac-deanband-row { flex-direction:column; align-items:stretch; }
    .fac-deanband-actions > .btn { flex:1 1 auto; justify-content:center; }
  }
</style>

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
<script src="<?= e(url('assets/js/charts.js')) ?>"></script>
<script>
// Global view mode switcher
function setGlobalView(mode) {
  const btnTable = document.getElementById('btnGlobalTable');
  const btnGraph = document.getElementById('btnGlobalGraph');
  if (btnTable && btnGraph) {
    btnTable.classList.toggle('active', mode === 'data');
    btnGraph.classList.toggle('active', mode === 'analytics');
  }

  switchCatView(mode);
}

function updateGlobalSwitcher() {
  const btnTable = document.getElementById('btnGlobalTable');
  const btnGraph = document.getElementById('btnGlobalGraph');
  if (!btnTable || !btnGraph) return;

  const catData = document.getElementById('catDataView')?.style.display !== 'none';

  btnTable.classList.toggle('active', catData);
  btnGraph.classList.toggle('active', !catData);
}

function switchCatView(mode) {
  const btnData = document.getElementById('btnCatData');
  const btnAnalytics = document.getElementById('btnCatAnalytics');
  const viewData = document.getElementById('catDataView');
  const viewAnalytics = document.getElementById('catAnalyticsView');

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
    requestAnimationFrame(() => {
      renderCatChart();
    });
  }
  updateGlobalSwitcher();
}



function toggleExportMenu(evt) {
  evt.stopPropagation();
  const m = document.getElementById('exportMenu');
  if (m) m.style.display = (m.style.display === 'none' || !m.style.display) ? 'block' : 'none';
}
document.addEventListener('click', () => {
  const m = document.getElementById('exportMenu');
  if (m) m.style.display = 'none';
});

/* =========================================================
   Section 1: Department Chart Controller
   ========================================================= */
const deptDataAll = <?= json_encode($sortedDeptComp ?? []) ?>;
const deptDataActive = <?= json_encode($activeDeptComp ?? []) ?>;
let deptFilter = 'active';
let deptType = 'stacked';
const deptHiddenCats = new Set();

const DEPT_CATEGORIES = [
  { key: 'publications', label: 'Publications', color: '#2563EB' },
  { key: 'conferences',  label: 'Conferences',  color: '#10B981' },
  { key: 'books',        label: 'Books',        color: '#FF4F01' },
  { key: 'events',       label: 'Events',       color: '#E11D48' },
  { key: 'training',     label: 'Training',     color: '#7C3AED' },
  { key: 'patents',      label: 'Patents',      color: '#0891B2' },
  { key: 'other',        label: 'Other',        color: '#94A3B8' }
];

function renderDeptLegend(list) {
  const bar = document.getElementById('deptLegendBar');
  if (!bar) return;
  bar.innerHTML = '';
  DEPT_CATEGORIES.forEach(cat => {
    const total = list.reduce((sum, d) => sum + (Number(d[cat.key]) || 0), 0);
    if (!total) return;
    const off = deptHiddenCats.has(cat.key);
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'dept-legend-item' + (off ? ' is-off' : '');
    btn.setAttribute('aria-pressed', off ? 'false' : 'true');
    btn.title = (off ? 'Show ' : 'Hide ') + cat.label;
    btn.innerHTML = '<span class="dept-legend-dot" style="background:' + cat.color + '"></span>'
      + '<span class="dept-legend-name">' + cat.label + '</span>'
      + '<span class="dept-legend-count">' + total + '</span>';
    btn.addEventListener('click', () => {
      if (deptHiddenCats.has(cat.key)) deptHiddenCats.delete(cat.key);
      else deptHiddenCats.add(cat.key);
      renderDeptChart();
    });
    bar.appendChild(btn);
  });
}

function setDeptChartFilter(filter) {
  deptFilter = filter;
  const btnAct = document.getElementById('btnDeptFilterActive');
  const btnAll = document.getElementById('btnDeptFilterAll');
  if (btnAct && btnAll) {
    btnAct.classList.toggle('active', filter === 'active');
    btnAll.classList.toggle('active', filter === 'all');
  }

  renderDeptChart();
}

function setDeptChartType(type) {
  deptType = type;
  const btnTot = document.getElementById('btnDeptTypeTotal');
  const btnStk = document.getElementById('btnDeptTypeStacked');
  const lbBar = document.getElementById('deptLeaderboardBar');
  const lgBar = document.getElementById('deptLegendBar');

  if (btnTot && btnStk) {
    btnTot.classList.toggle('active', type === 'total');
    btnStk.classList.toggle('active', type === 'stacked');
  }
  if (lbBar) lbBar.style.display = (type === 'total') ? 'flex' : 'none';
  if (lgBar) lgBar.style.display = (type === 'stacked') ? 'flex' : 'none';
  renderDeptChart();
}

function renderDeptChart() {
  const list = deptFilter === 'active' ? deptDataActive : deptDataAll;
  const container = document.getElementById('deptChartContainer');
  if (!container || !list || !list.length) return;

  const isActive = deptFilter === 'active';
  const h = isActive ? Math.max(240, list.length * 52 + 40) : Math.max(420, list.length * 30 + 40);
  container.style.height = h + 'px';

  const common = {
    labels: list.map(d => d.department),
    horizontal: true,
    rounded: true,
    track: true,
    wrapLabels: isActive,
    labelMaxLength: 20,
    paddingLeft: 4,
    valueAxisTitle: 'Verified records',
    unit: 'records',
    empty: 'No department achievements found'
  };

  if (deptType === 'total') {
    ATTS.charts.bar('deptCompChart', Object.assign({}, common, {
      data: list.map(d => d.total),
      // Top three departments in full brand orange, the rest softened.
      colors: list.map((d, i) => i < 3 ? '#FF4F01' : '#FFA27A'),
      maxBarThickness: isActive ? 26 : 16
    }));
  } else {
    renderDeptLegend(list);
    const datasets = DEPT_CATEGORIES.map(cat => ({
      label: cat.label,
      data: list.map(d => Number(d[cat.key]) || 0),
      backgroundColor: cat.color,
      hoverBackgroundColor: cat.color,
      maxBarThickness: isActive ? 26 : 16,
      hidden: deptHiddenCats.has(cat.key)
    }));
    ATTS.charts.bar('deptCompChart', Object.assign({}, common, {
      stacked: true,
      datasets: datasets,
      legend: false,
      showTotals: true,
      tooltipAll: true
    }));
  }
}

/* =========================================================
   Section 2: Category Chart Controller
   ========================================================= */
const activeCategoriesData = <?= json_encode($activeCatBreakdown ?? []) ?>;
let catChartType = 'donut';

function setCatChartType(type) {
  catChartType = type;
  const btnDonut = document.getElementById('btnCatChartDonut');
  const btnBar = document.getElementById('btnCatChartBar');
  const canvasDonut = document.getElementById('catDonutChart');
  const canvasBar = document.getElementById('catBarChart');

  if (btnDonut && btnBar) {
    btnDonut.classList.toggle('active', type === 'donut');
    btnBar.classList.toggle('active', type === 'bar');
  }

  if (type === 'donut') {
    if (canvasDonut) canvasDonut.style.display = 'block';
    if (canvasBar) canvasBar.style.display = 'none';
  } else {
    if (canvasBar) canvasBar.style.display = 'block';
    if (canvasDonut) canvasDonut.style.display = 'none';
  }
  renderCatChart();
}

function renderCatChart() {
  if (!activeCategoriesData || !activeCategoriesData.length) return;
  if (catChartType === 'donut') {
    ATTS.charts.donut('catDonutChart', {
      labels: activeCategoriesData.map(c => c.label),
      data: activeCategoriesData.map(c => c.count),
      colors: activeCategoriesData.map(c => c.color),
      centre: true,
      centreValue: '<?= (int)$summary['totalAchievements'] ?>',
      centreLabel: 'verified',
      legend: true
    });
  } else {
    ATTS.charts.bar('catBarChart', {
      labels: activeCategoriesData.map(c => c.label),
      data: activeCategoriesData.map(c => c.count),
      colors: activeCategoriesData.map(c => c.color),
      horizontal: true,
      maxBarThickness: 22,
      unit: 'records'
    });
  }
}

// Initialize charts and view states on DOM ready
document.addEventListener('DOMContentLoaded', () => {
  renderDeptChart();
  renderCatChart();
  updateGlobalSwitcher();
});
if (document.readyState !== 'loading') {
  renderDeptChart();
  renderCatChart();
  updateGlobalSwitcher();
}

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
}</script>

<?php require __DIR__ . '/inc/footer.php'; ?>
