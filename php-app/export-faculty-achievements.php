<?php
/**
 * Export Consolidated Faculty Achievements Report.
 * Supports Excel (.xlsx), Word (.doc), CSV (.csv), and PDF formats.
 * Strictly respects active filters (department, academic year, category, faculty).
 */

require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/report_layout.php';
require_once __DIR__ . '/inc/xlsx_writer.php';
require_once __DIR__ . '/models/FacultyAchievement.php';
require_once __DIR__ . '/models/Department.php';

$user = require_login();
require_module('reports');

if (strcasecmp((string)($user['role'] ?? ''), 'Faculty') === 0) {
    http_response_code(403);
    echo "<h1>403 Forbidden</h1><p>Faculty members are not authorized to export consolidated institutional reports.</p>";
    exit;
}

// Read filters
$format       = strtolower(trim((string) input('format', 'excel')));
$department   = trim((string) input('department', '')) ?: null;
$academicYear = trim((string) input('academic_year', '')) ?: active_academic_year();
$category     = trim((string) input('category', '')) ?: null;
$facultyId    = (int) input('faculty_id', 0) ?: null;

// Enforce role scope
$effDept = resolve_faculty_achievement_scope($user, $department);

if (!in_array($format, ['excel', 'word', 'csv', 'pdf'], true)) {
    $format = 'excel';
}

// Fetch report data
$summary   = faculty_achievements_summary($user, $effDept, $academicYear, $category, $facultyId);
$deptComp  = department_achievements_comparison($user, $academicYear, $category);
$facGrid   = faculty_achievements_grid($user, $effDept, $academicYear, $category, $facultyId);

// Filter labels for header
$filterBits = [];
$filterBits[] = 'Academic Year: ' . ($academicYear ?: 'All Years');
$filterBits[] = 'Department: ' . ($effDept ?: 'All Departments');
if ($category) {
    $filterBits[] = 'Category: ' . $category;
}
if ($facultyId && !empty($facGrid)) {
    $filterBits[] = 'Faculty: ' . $facGrid[0]['name'];
}

$today    = date('d.m.Y');
$fileStem = 'consolidated-faculty-achievements-' . date('Y-m-d');
$title    = 'CONSOLIDATED FACULTY ACHIEVEMENTS REPORT';
$scopeLabel = $effDept ?: 'ALL DEPARTMENTS';

/* ========================================================================
   1. EXCEL (.xlsx)
   ===================================================================== */
if ($format === 'excel') {
    $headers = ['S.No', 'Faculty Name', 'Employee ID', 'Designation', 'Department', 'Journals', 'Conferences', 'Books/Chapters', 'Events', 'Training/FDP', 'Patents', 'Other', 'Total Achievements'];
    
    $rows = [];
    $sno = 1;
    foreach ($facGrid as $f) {
        $rows[] = [
            $sno++,
            $f['name'],
            $f['employee_id'],
            $f['designation'],
            $f['department'],
            (int) $f['journals'],
            (int) $f['conferences'],
            (int) $f['books'],
            (int) $f['events'],
            (int) $f['training'],
            (int) $f['patents'],
            (int) $f['other'],
            (int) $f['total'],
        ];
    }

    $xlsxData = SimpleXlsxWriter::createXlsx($headers, $rows, 'Faculty Achievements', [
        'title'       => $title,
        'department'  => $scopeLabel,
        'year'        => $academicYear,
        'generated'   => $today,
        'institution' => REPORT_INSTITUTION,
    ]);

    if ($xlsxData !== '') {
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $fileStem . '.xlsx"');
        header('Content-Length: ' . strlen($xlsxData));
        echo $xlsxData;
        exit;
    }
}

/* ========================================================================
   2. CSV (.csv)
   ===================================================================== */
if ($format === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $fileStem . '.csv"');

    $out = fopen('php://output', 'w');
    fwrite($out, chr(0xEF) . chr(0xBB) . chr(0xBF)); // UTF-8 BOM

    fputcsv($out, [REPORT_INSTITUTION . ' - Internal Quality Assurance Cell (IQAC)']);
    fputcsv($out, [$title]);
    fputcsv($out, ['Filters Applied: ' . implode(' | ', $filterBits)]);
    fputcsv($out, ['Report Date: ' . $today]);
    fputcsv($out, []);

    fputcsv($out, ['S.No', 'Faculty Name', 'Employee ID', 'Designation', 'Department', 'Journals', 'Conferences', 'Books/Chapters', 'Events', 'Training/FDP', 'Patents', 'Other', 'Total Achievements']);

    $sno = 1;
    foreach ($facGrid as $f) {
        fputcsv($out, [
            $sno++,
            $f['name'],
            $f['employee_id'],
            $f['designation'],
            $f['department'],
            $f['journals'],
            $f['conferences'],
            $f['books'],
            $f['events'],
            $f['training'],
            $f['patents'],
            $f['other'],
            $f['total'],
        ]);
    }

    fclose($out);
    exit;
}

/* ========================================================================
   3. WORD (.doc) & 4. PDF (Print HTML View)
   ===================================================================== */
if ($format === 'word') {
    header('Content-Type: application/msword');
    header('Content-Disposition: attachment; filename="' . $fileStem . '.doc"');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title><?= e($title) ?> — <?= e(REPORT_INSTITUTION) ?></title>
  <style>
    body { font-family: "Calibri", "Segoe UI", Arial, sans-serif; font-size: 13px; color: #131D3B; margin: 20px; line-height: 1.4; }
    .hdr-table { width: 100%; border-collapse: collapse; margin-bottom: 16px; border-bottom: 2px solid #131D3B; }
    .hdr-logo { font-size: 20px; font-weight: 800; color: #FF4F01; letter-spacing: -.02em; }
    .hdr-sub { font-size: 12px; color: #5A6785; font-weight: 600; text-transform: uppercase; }
    .title-box { background: #F4F6FA; border: 1px solid #E4E9F2; border-radius: 8px; padding: 12px 16px; margin-bottom: 20px; }
    .title-box h1 { margin: 0 0 4px; font-size: 18px; color: #131D3B; }
    .title-box p { margin: 0; font-size: 12px; color: #5A6785; }
    .kpi-grid { display: flex; gap: 12px; margin-bottom: 20px; }
    .kpi { flex: 1; background: #fff; border: 1px solid #E6EAF2; border-radius: 8px; padding: 10px 12px; }
    .kpi-k { font-size: 11px; font-weight: 700; color: #5A6785; text-transform: uppercase; }
    .kpi-v { font-size: 20px; font-weight: 800; color: #131D3B; margin-top: 4px; }
    table.data-tbl { width: 100%; border-collapse: collapse; margin-bottom: 24px; font-size: 12px; }
    table.data-tbl th { background: #131D3B; color: #ffffff; font-weight: 700; text-align: left; padding: 8px 10px; border: 1px solid #131D3B; }
    table.data-tbl td { padding: 8px 10px; border: 1px solid #E6EAF2; }
    table.data-tbl tr:nth-child(even) { background: #F8FAFC; }
    table.data-tbl td.num, table.data-tbl th.num { text-align: right; }
    .fw-bold { font-weight: 700; }
    .footer { margin-top: 30px; font-size: 11px; color: #8B96AE; text-align: center; border-top: 1px solid #E6EAF2; padding-top: 10px; }
    @media print {
      body { margin: 0; padding: 10mm; }
      .no-print { display: none !important; }
    }
  </style>
</head>
<body>

  <?php if ($format === 'pdf'): ?>
    <div class="no-print" style="margin-bottom: 16px; display: flex; justify-content: flex-end; gap: 10px;">
      <button onclick="window.print()" style="background: #FF4F01; color: white; border: 0; padding: 8px 16px; border-radius: 6px; font-weight: bold; cursor: pointer;">
        Print / Save as PDF
      </button>
      <button onclick="window.close()" style="background: #E4E9F2; color: #131D3B; border: 0; padding: 8px 16px; border-radius: 6px; font-weight: bold; cursor: pointer;">
        Close Window
      </button>
    </div>
  <?php endif; ?>

  <table class="hdr-table">
    <tr>
      <td>
        <div class="hdr-logo"><?= e(REPORT_INSTITUTION) ?></div>
        <div class="hdr-sub">Internal Quality Assurance Cell (IQAC) &middot; Academic Target Tracking System</div>
      </td>
      <td style="text-align: right; font-size: 12px; color: #5A6785;">
        <div><strong>Date:</strong> <?= e($today) ?></div>
        <div><strong>Scope:</strong> <?= e($scopeLabel) ?></div>
      </td>
    </tr>
  </table>

  <div class="title-box">
    <h1><?= e($title) ?></h1>
    <p><strong>Filters Applied:</strong> <?= e(implode(' · ', $filterBits)) ?></p>
  </div>

  <div class="kpi-grid">
    <div class="kpi"><div class="kpi-k">Total Faculty</div><div class="kpi-v"><?= (int) $summary['totalFaculty'] ?></div></div>
    <div class="kpi"><div class="kpi-k">Total Achievements</div><div class="kpi-v"><?= (int) $summary['totalAchievements'] ?></div></div>
    <div class="kpi"><div class="kpi-k">Departments</div><div class="kpi-v"><?= (int) $summary['departments'] ?></div></div>
    <div class="kpi"><div class="kpi-k">Top Category</div><div class="kpi-v"><?= e($summary['topCategory']) ?></div></div>
  </div>

  <h3>Faculty Achievement Performance Matrix</h3>
  <table class="data-tbl">
    <thead>
      <tr>
        <th>#</th>
        <th>Faculty Member</th>
        <th>Employee ID</th>
        <th>Department</th>
        <th class="num">Journals</th>
        <th class="num">Conferences</th>
        <th class="num">Books</th>
        <th class="num">Events</th>
        <th class="num">Training</th>
        <th class="num">Patents</th>
        <th class="num">Other</th>
        <th class="num">Total</th>
      </tr>
    </thead>
    <tbody>
      <?php $i = 1; foreach ($facGrid as $f): ?>
        <tr>
          <td><?= $i++ ?></td>
          <td class="fw-bold"><?= e($f['name']) ?></td>
          <td><?= e($f['employee_id']) ?></td>
          <td><?= e($f['department']) ?></td>
          <td class="num"><?= (int) $f['journals'] ?></td>
          <td class="num"><?= (int) $f['conferences'] ?></td>
          <td class="num"><?= (int) $f['books'] ?></td>
          <td class="num"><?= (int) $f['events'] ?></td>
          <td class="num"><?= (int) $f['training'] ?></td>
          <td class="num"><?= (int) $f['patents'] ?></td>
          <td class="num"><?= (int) $f['other'] ?></td>
          <td class="num fw-bold"><?= (int) $f['total'] ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <h3>Department-Wise Summary Comparison</h3>
  <table class="data-tbl">
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
      <?php foreach ($deptComp as $d): ?>
        <tr>
          <td class="fw-bold"><?= e($d['department']) ?></td>
          <td class="num"><?= (int) $d['publications'] ?></td>
          <td class="num"><?= (int) $d['conferences'] ?></td>
          <td class="num"><?= (int) $d['books'] ?></td>
          <td class="num"><?= (int) $d['events'] ?></td>
          <td class="num"><?= (int) $d['training'] ?></td>
          <td class="num"><?= (int) $d['patents'] ?></td>
          <td class="num fw-bold"><?= (int) $d['total'] ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <div class="footer">
    Generated automatically by ATTS IQAC System on <?= e($today) ?>. Official Academic Report Document.
  </div>

</body>
</html>
