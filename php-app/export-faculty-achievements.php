<?php
/**
 * Export Department & Faculty Achievements Report (FEAT-04).
 *
 * Supports Excel (.xlsx), Word (.doc), CSV (.csv), and PDF formats.
 * Strictly respects active filters, role authorization (Admin, Dean, HoD),
 * and isolates HoD data exclusively to their own department.
 * Automatically uses system-wide active Academic Year from FEAT-02.
 */

require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/report_layout.php';
require_once __DIR__ . '/inc/xlsx_writer.php';
require_once __DIR__ . '/models/FacultyAchievement.php';
require_once __DIR__ . '/models/Department.php';
require_once __DIR__ . '/models/Target.php';

$user = require_login();
require_module('reports');

$role = $user['role'] ?? '';

// FEAT-04: Authorization check — Admin, Dean, HoD, Coordinator (and Principal/Director if authorized)
$allowedRoles = ['Admin', 'Dean', 'HoD', 'Coordinator', 'Principal', 'Director'];
if (!in_array($role, $allowedRoles, true)) {
    http_response_code(403);
    echo "<h1>403 Forbidden</h1><p>You are not authorized to export Department &amp; Faculty Achievements.</p>";
    exit;
}

require_once __DIR__ . '/models/ExecutiveMeeting.php';
// EM-SPEC-03: Centralized Academic Year and EM Duration filter resolution.
$rawYear      = input('academic_year') ?: input('year');
$emCtx        = em_resolve_filter_context($rawYear, input('em'));
$academicYear = $emCtx['year'];
$format       = strtolower(trim((string) input('format', 'excel')));
if (!in_array($format, ['excel', 'word', 'csv', 'pdf'], true)) {
    $format = 'excel';
}

// Department scoping:
// Single source of truth via user_department_scope()
$effDept = user_department_scope($user, input('department'));

$category  = trim((string) input('category', '')) ?: null;
$facultyId = (int) input('faculty_id', 0) ?: null;

// Fetch faculty achievements dataset
// FEAT-07: same Executive Meeting (EM1/EM2) window as the on-screen page.
$emWindow  = em_filter_window(em_filter_value(input('em')), $academicYear);
$summary   = faculty_achievements_summary($user, $effDept, $academicYear, $category, $facultyId, $emWindow);
$deptComp  = department_achievements_comparison($user, $academicYear, $category, $emWindow);
$facGrid   = faculty_achievements_grid($user, $effDept, $academicYear, $category, $facultyId, null, $emWindow);

// Standardized filenames matching FEAT-04 specification
$safeYear   = preg_replace('/[^A-Za-z0-9\-]/', '-', $academicYear);
$fileStem   = "ATTS_Faculty_Achievements_{$safeYear}";
$title      = 'DEPARTMENT & FACULTY ACHIEVEMENTS REPORT';
$today      = date('d.m.Y');
$scopeLabel = $effDept ? department_full_name($effDept) : 'ALL AUTHORIZED DEPARTMENTS';

// Group faculty members department-wise
$groupedFaculty = [];
foreach ($facGrid as $f) {
    $dName = !empty($f['department']) && $f['department'] !== '—' ? $f['department'] : 'Other / General';
    $groupedFaculty[$dName][] = $f;
}
ksort($groupedFaculty);

/* ========================================================================
   1. EXCEL EXPORT (.xlsx)
   ===================================================================== */
if ($format === 'excel') {
    $headers = ['S.No', 'Faculty Name', 'Employee ID', 'Designation', 'Department', 'Journals', 'Conferences', 'Books/Chapters', 'Events', 'Training/FDP', 'Patents', 'Other', 'Total Achievements'];
    $rows = [];

    // Summary Comparison Table at the top
    $rows[] = ['DEPARTMENT-WISE SUMMARY COMPARISON', '', '', '', '', '', '', '', '', '', '', '', ''];
    $rows[] = ['Department', 'Publications', 'Conferences', 'Books', 'Events', 'Training', 'Patents', 'Total Achievements', '', '', '', '', ''];
    foreach ($deptComp as $dc) {
        $rows[] = [
            $dc['department'],
            (int) $dc['publications'],
            (int) $dc['conferences'],
            (int) $dc['books'],
            (int) $dc['events'],
            (int) $dc['training'],
            (int) $dc['patents'],
            (int) $dc['total'],
            '', '', '', '', '',
        ];
    }
    $rows[] = ['', '', '', '', '', '', '', '', '', '', '', '', ''];
    $rows[] = ['', '', '', '', '', '', '', '', '', '', '', '', ''];

    // Department-wise Grouped Individual Faculty Listings
    $grandTotals = ['j' => 0, 'c' => 0, 'b' => 0, 'e' => 0, 't' => 0, 'p' => 0, 'o' => 0, 'tot' => 0];

    if (empty($groupedFaculty)) {
        $rows[] = ['No faculty achievement records found for the selected scope in Academic Year ' . $academicYear . '.', '', '', '', '', '', '', '', '', '', '', '', ''];
    } else {
        foreach ($groupedFaculty as $deptName => $facultyList) {
            $deptFull = department_full_name($deptName);
            $rows[] = ['DEPARTMENT: ' . strtoupper($deptFull), '', '', '', '', '', '', '', '', '', '', '', ''];
            $rows[] = $headers;

            $dTotals = ['j' => 0, 'c' => 0, 'b' => 0, 'e' => 0, 't' => 0, 'p' => 0, 'o' => 0, 'tot' => 0];
            $sno = 1;

            foreach ($facultyList as $f) {
                $j = (int) $f['journals'];
                $c = (int) $f['conferences'];
                $b = (int) $f['books'];
                $e = (int) $f['events'];
                $t = (int) $f['training'];
                $p = (int) $f['patents'];
                $o = (int) $f['other'];
                $tot = (int) $f['total'];

                $dTotals['j'] += $j;
                $dTotals['c'] += $c;
                $dTotals['b'] += $b;
                $dTotals['e'] += $e;
                $dTotals['t'] += $t;
                $dTotals['p'] += $p;
                $dTotals['o'] += $o;
                $dTotals['tot'] += $tot;

                $rows[] = [
                    $sno++,
                    $f['name'],
                    $f['employee_id'],
                    $f['designation'],
                    $f['department'],
                    $j, $c, $b, $e, $t, $p, $o, $tot,
                ];
            }

            // Department Subtotal row
            $rows[] = [
                'Subtotal',
                count($facultyList) . ' Faculty members',
                '',
                '',
                $deptName,
                $dTotals['j'],
                $dTotals['c'],
                $dTotals['b'],
                $dTotals['e'],
                $dTotals['t'],
                $dTotals['p'],
                $dTotals['o'],
                $dTotals['tot'],
            ];
            $rows[] = ['', '', '', '', '', '', '', '', '', '', '', '', ''];

            foreach ($dTotals as $k => $val) {
                $grandTotals[$k] += $val;
            }
        }

        // Grand Total row
        $rows[] = [
            'TOTAL',
            'All Departments Total (' . count($facGrid) . ' Faculty)',
            '',
            '',
            $scopeLabel,
            $grandTotals['j'],
            $grandTotals['c'],
            $grandTotals['b'],
            $grandTotals['e'],
            $grandTotals['t'],
            $grandTotals['p'],
            $grandTotals['o'],
            $grandTotals['tot'],
        ];
    }

    $metaLines = [
        REPORT_INSTITUTION,
        $title,
        'Academic Year: ' . $academicYear,
        'Scope: ' . $scopeLabel,
        'Report Date: ' . $today,
    ];

    $xlsxData = SimpleXlsxWriter::createXlsx($headers, $rows, 'Faculty Achievements', $metaLines);
    if ($xlsxData !== '') {
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $fileStem . '.xlsx"');
        header('Content-Length: ' . strlen($xlsxData));
        header('Cache-Control: max-age=0');
        echo $xlsxData;
        exit;
    }
}

/* ========================================================================
   2. CSV EXPORT (.csv)
   ===================================================================== */
if ($format === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $fileStem . '.csv"');

    $out = fopen('php://output', 'w');
    fwrite($out, chr(0xEF) . chr(0xBB) . chr(0xBF)); // UTF-8 BOM

    fputcsv($out, [REPORT_INSTITUTION . ' - Internal Quality Assurance Cell (IQAC)']);
    fputcsv($out, [$title]);
    fputcsv($out, ['Academic Year: ' . $academicYear, 'Scope: ' . $scopeLabel, 'Report Date: ' . $today]);
    fputcsv($out, []);

    fputcsv($out, ['S.No', 'Faculty Name', 'Employee ID', 'Designation', 'Department', 'Journals', 'Conferences', 'Books/Chapters', 'Events', 'Training/FDP', 'Patents', 'Other', 'Total Achievements']);

    $sno = 1;
    foreach ($groupedFaculty as $deptName => $facultyList) {
        fputcsv($out, ['DEPARTMENT: ' . department_full_name($deptName)]);
        foreach ($facultyList as $f) {
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
        fputcsv($out, []);
    }

    fclose($out);
    exit;
}

/* ========================================================================
   3. WORD (.doc) & 4. PDF (Print HTML View)
   ===================================================================== */
if ($format === 'word') {
    header('Content-Type: application/msword; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $fileStem . '.doc"');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title><?= e($title) ?> — <?= e(REPORT_INSTITUTION) ?></title>
  <style>
    @page { size: A4 landscape; margin: 1.4cm 1.2cm; }
    html, body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    body { font-family: "Calibri", "Segoe UI", Arial, sans-serif; font-size: 11pt; color: #131D3B; margin: 20px; line-height: 1.4; }
    .hdr-table { width: 100%; border-collapse: collapse; margin-bottom: 16px; border-bottom: 2px solid #131D3B; }
    .hdr-logo { font-size: 18pt; font-weight: 800; color: #131D3B; letter-spacing: -.02em; }
    .hdr-sub { font-size: 10pt; color: #5A6785; font-weight: 600; text-transform: uppercase; margin-top: 3px; }
    .title-box { background: #F4F6FA; border: 1px solid #E4E9F2; border-radius: 8px; padding: 12px 16px; margin-bottom: 20px; }
    .title-box h1 { margin: 0 0 4px; font-size: 15pt; color: #131D3B; }
    .title-box p { margin: 0; font-size: 10pt; color: #5A6785; }
    .kpi-grid { display: flex; gap: 12px; margin-bottom: 20px; }
    .kpi { flex: 1; background: #fff; border: 1px solid #E6EAF2; border-radius: 8px; padding: 10px 12px; }
    .kpi-k { font-size: 9pt; font-weight: 700; color: #5A6785; text-transform: uppercase; }
    .kpi-v { font-size: 18pt; font-weight: 800; color: #131D3B; margin-top: 4px; }
    .dept-sec-head { background: #131D3B; color: #ffffff; padding: 8px 12px; font-weight: 700; font-size: 11.5pt; border-radius: 4px; margin-top: 24px; margin-bottom: 10px; }
    table.data-tbl { width: 100%; border-collapse: collapse; margin-bottom: 24px; font-size: 9.5pt; }
    table.data-tbl th { background: #E5E7EB; color: #111827; font-weight: 700; text-align: left; padding: 6px 8px; border: 1px solid #9CA3AF; }
    table.data-tbl td { padding: 6px 8px; border: 1px solid #D1D5DB; }
    table.data-tbl tr:nth-child(even) { background: #F9FAFB; }
    table.data-tbl td.num, table.data-tbl th.num { text-align: right; }
    table.data-tbl td.c, table.data-tbl th.c { text-align: center; }
    .subtotal-row { background: #EEF2F6 !important; font-weight: 700; }
    .footer { margin-top: 30px; font-size: 9pt; color: #8B96AE; text-align: center; border-top: 1px solid #E6EAF2; padding-top: 10px; }
    @media print {
      body { margin: 0; padding: 10mm; }
      .no-print { display: none !important; }
      .page-break { page-break-before: always; break-before: page; }
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
      <td style="text-align: right; font-size: 10pt; color: #5A6785;">
        <div><strong>Date:</strong> <?= e($today) ?></div>
        <div><strong>Scope:</strong> <?= e($scopeLabel) ?></div>
        <div><strong>Academic Year:</strong> <?= e($academicYear) ?></div>
      </td>
    </tr>
  </table>

  <div class="title-box">
    <h1><?= e($title) ?></h1>
    <p>Academic Year: <strong><?= e($academicYear) ?></strong> &middot; Scope: <strong><?= e($scopeLabel) ?></strong></p>
  </div>

  <div class="kpi-grid">
    <div class="kpi"><div class="kpi-k">Total Faculty in Scope</div><div class="kpi-v"><?= (int) $summary['totalFaculty'] ?></div></div>
    <div class="kpi"><div class="kpi-k">Total Achievements</div><div class="kpi-v"><?= (int) $summary['totalAchievements'] ?></div></div>
    <div class="kpi"><div class="kpi-k">Departments</div><div class="kpi-v"><?= (int) $summary['departments'] ?></div></div>
    <div class="kpi"><div class="kpi-k">Top Submission Area</div><div class="kpi-v"><?= e($summary['topCategory'] ?: '—') ?></div></div>
  </div>

  <?php if (!empty($deptComp) && count($deptComp) > 1): ?>
    <h3 style="margin-top:20px; font-size:12pt; color:#131D3B;">Department-Wise Summary Comparison</h3>
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
            <td style="font-weight:700;"><?= e($d['department']) ?></td>
            <td class="num"><?= (int) $d['publications'] ?></td>
            <td class="num"><?= (int) $d['conferences'] ?></td>
            <td class="num"><?= (int) $d['books'] ?></td>
            <td class="num"><?= (int) $d['events'] ?></td>
            <td class="num"><?= (int) $d['training'] ?></td>
            <td class="num"><?= (int) $d['patents'] ?></td>
            <td class="num" style="font-weight:700; color:#FF4F01;"><?= (int) $d['total'] ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <!-- Department-Wise Individual Faculty Listings -->
  <?php if (empty($groupedFaculty)): ?>
    <div style="padding:24px; text-align:center; background:#F9FAFB; border:1px dashed #D1D5DB; border-radius:8px; color:#6B7280; font-style:italic; margin-top:20px;">
      No faculty achievements recorded for the selected scope in Academic Year <?= e($academicYear) ?>.
    </div>
  <?php else: ?>
    <?php $deptIdx = 0; foreach ($groupedFaculty as $deptName => $facultyList): $deptIdx++; ?>
      <?php if ($deptIdx > 1): ?>
        <div class="page-break"></div>
      <?php endif; ?>

      <div class="dept-sec-head">
        DEPARTMENT: <?= strtoupper(e(department_full_name($deptName))) ?>
        <span style="font-weight:normal; font-size:9.5pt; float:right;"><?= count($facultyList) ?> Faculty Member<?= count($facultyList) === 1 ? '' : 's' ?></span>
      </div>

      <table class="data-tbl">
        <thead>
          <tr>
            <th style="width:4%;" class="c">#</th>
            <th style="width:24%;">Faculty Member</th>
            <th style="width:12%;">Employee ID</th>
            <th style="width:16%;">Designation</th>
            <th class="num" style="width:6%;">Journals</th>
            <th class="num" style="width:7%;">Conferences</th>
            <th class="num" style="width:6%;">Books</th>
            <th class="num" style="width:6%;">Events</th>
            <th class="num" style="width:6%;">Training</th>
            <th class="num" style="width:6%;">Patents</th>
            <th class="num" style="width:6%;">Other</th>
            <th class="num" style="width:7%;">Total</th>
          </tr>
        </thead>
        <tbody>
          <?php
            $dTot = ['j' => 0, 'c' => 0, 'b' => 0, 'e' => 0, 't' => 0, 'p' => 0, 'o' => 0, 'tot' => 0];
            $fNo = 1;
            foreach ($facultyList as $f):
              $dTot['j'] += (int) $f['journals'];
              $dTot['c'] += (int) $f['conferences'];
              $dTot['b'] += (int) $f['books'];
              $dTot['e'] += (int) $f['events'];
              $dTot['t'] += (int) $f['training'];
              $dTot['p'] += (int) $f['patents'];
              $dTot['o'] += (int) $f['other'];
              $dTot['tot'] += (int) $f['total'];
          ?>
            <tr>
              <td class="c"><?= $fNo++ ?></td>
              <td style="font-weight:700; color:#131D3B;"><?= e($f['name']) ?></td>
              <td><?= e($f['employee_id']) ?></td>
              <td><?= e($f['designation']) ?></td>
              <td class="num"><?= (int) $f['journals'] ?></td>
              <td class="num"><?= (int) $f['conferences'] ?></td>
              <td class="num"><?= (int) $f['books'] ?></td>
              <td class="num"><?= (int) $f['events'] ?></td>
              <td class="num"><?= (int) $f['training'] ?></td>
              <td class="num"><?= (int) $f['patents'] ?></td>
              <td class="num"><?= (int) $f['other'] ?></td>
              <td class="num" style="font-weight:700; color:#FF4F01;"><?= (int) $f['total'] ?></td>
            </tr>
          <?php endforeach; ?>
          <tr class="subtotal-row">
            <td colspan="4" style="text-align:right; font-weight:700; padding-right:10px;">Subtotal for <?= e(department_full_name($deptName)) ?>:</td>
            <td class="num"><?= $dTot['j'] ?></td>
            <td class="num"><?= $dTot['c'] ?></td>
            <td class="num"><?= $dTot['b'] ?></td>
            <td class="num"><?= $dTot['e'] ?></td>
            <td class="num"><?= $dTot['t'] ?></td>
            <td class="num"><?= $dTot['p'] ?></td>
            <td class="num"><?= $dTot['o'] ?></td>
            <td class="num" style="font-weight:700; color:#FF4F01;"><?= $dTot['tot'] ?></td>
          </tr>
        </tbody>
      </table>
    <?php endforeach; ?>
  <?php endif; ?>

  <div class="footer">
    Generated automatically by ATTS IQAC System on <?= e($today) ?> &middot; Academic Year: <?= e($academicYear) ?> &middot; Official Academic Record
  </div>

</body>
</html>
