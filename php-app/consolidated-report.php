<?php
/**
 * Consolidated All-Department Academic Report (FEAT-03).
 *
 * Generates a single institutional report encompassing data from ALL departments,
 * grouped department-wise, locked to the system-wide active Academic Year (FEAT-02).
 *
 * Formats:
 *   ?format=pdf   -> Print-ready HTML view with PDF print bar & page breaks
 *   ?format=excel -> Native OpenXML spreadsheet (.xlsx) via SimpleXlsxWriter
 *   ?format=word  -> Formatted Microsoft Word document (.doc)
 *
 * Authorization:
 *   Admin, Dean, Principal, Director only. Server-side gated.
 */

require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/report_layout.php';
require_once __DIR__ . '/inc/xlsx_writer.php';
require_once __DIR__ . '/models/Department.php';
require_once __DIR__ . '/models/Record.php';
require_once __DIR__ . '/models/Target.php';

$user = require_login();
require_module('reports');

$role = $user['role'] ?? '';
$isAuthorized = in_array($role, ['Admin', 'Dean', 'Principal', 'Director'], true);

if (!$isAuthorized) {
    http_response_code(403);
    echo "<h1>403 Forbidden</h1><p>You are not authorized to access the Consolidated Institutional Report.</p>";
    exit;
}

// Global active academic year from FEAT-02 (no manual client override)
$academicYear = active_academic_year();
$format       = strtolower(trim((string) input('format', 'pdf')));
if (!in_array($format, ['pdf', 'excel', 'word'], true)) {
    $format = 'pdf';
}

$today    = date('d.m.Y');
$safeYear = preg_replace('/[^A-Za-z0-9\-]/', '-', $academicYear);
$fileStem = "ATTS_Consolidated_Report_{$safeYear}";
$title    = 'CONSOLIDATED ALL-DEPARTMENT REPORT';

// 1. Fetch all departments registered in the system
$departments = departments_all();

// 2. Fetch all academic records for the active academic year (institution-wide)
$adminScopeUser = ['id' => (int)$user['id'], 'role' => 'Admin', 'name' => 'Consolidated', 'department' => null];
$allRecords     = report_records($adminScopeUser, null, null, null, null, null, $academicYear);

// 3. Fetch targets summary per department for the active academic year
$targetStats = [];
try {
    $tStmt = db()->prepare(
        "SELECT department,
                COUNT(*) as total_targets,
                SUM(CASE WHEN status = 'Approved' THEN 1 ELSE 0 END) as approved_targets,
                SUM(target_value) as sum_targets,
                SUM(achieved_value) as sum_achieved
         FROM targets
         WHERE academic_year = ?
         GROUP BY department"
    );
    $tStmt->execute([$academicYear]);
    foreach ($tStmt->fetchAll() as $tRow) {
        $dKey = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)$tRow['department']));
        $targetStats[$dKey] = $tRow;
    }
} catch (\PDOException $e) {
    // Fail-soft if targets table not ready
}

// 4. Map records department-wise
$deptData = [];
$normKey = fn($s) => strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)$s));

foreach ($departments as $d) {
    $dCodeKey = $normKey($d['code']);
    $dNameKey = $normKey($d['name']);
    $dFullKey = $normKey(department_full_name($d['name']));
    $fullName = department_full_name($d['name']);

    $deptData[$dCodeKey] = [
        'info'       => $d,
        'full_name'  => $fullName,
        'records'    => [],
        'approved'   => 0,
        'pending'    => 0,
        'rejected'   => 0,
        'draft'      => 0,
        'keys'       => array_filter([$dCodeKey, $dNameKey, $dFullKey]),
        'targets'    => $targetStats[$dCodeKey] ?? $targetStats[$dNameKey] ?? $targetStats[$dFullKey] ?? null,
    ];
}

// Group records under their matching department
$unmatchedRecords = [];
foreach ($allRecords as $r) {
    $rDeptKey = $normKey($r['department'] ?? '');
    $matched  = false;

    foreach ($deptData as $dCodeKey => &$dGroup) {
        if (in_array($rDeptKey, $dGroup['keys'], true)) {
            $dGroup['records'][] = $r;
            $status = $r['status'] ?? 'Draft';
            if ($status === 'Approved') {
                $dGroup['approved']++;
            } elseif (in_array($status, ['Submitted', 'HOD Pending', 'Dean Pending'], true)) {
                $dGroup['pending']++;
            } elseif ($status === 'Rejected') {
                $dGroup['rejected']++;
            } else {
                $dGroup['draft']++;
            }
            $matched = true;
            break;
        }
    }
    unset($dGroup);

    if (!$matched) {
        $unmatchedRecords[] = $r;
    }
}

// College-wide totals
$totalRecordsCollege  = count($allRecords);
$totalApprovedCollege = 0;
$totalPendingCollege  = 0;
foreach ($deptData as $dGroup) {
    $totalApprovedCollege += $dGroup['approved'];
    $totalPendingCollege  += $dGroup['pending'];
}

/* ========================================================================
   1. EXCEL EXPORT (.xlsx)
   ===================================================================== */
if ($format === 'excel') {
    $headers = ['S.No', 'Record Details / Title', 'Type', 'Faculty / Student Name', 'Department', 'Status', 'Date'];
    $rows = [];

    // Part A: Institutional Summary Block
    $rows[] = ['INSTITUTIONAL SUMMARY BY DEPARTMENT', '', '', '', '', '', ''];
    $rows[] = ['S.No', 'Department Name', 'Code', 'Total Records', 'Approved', 'Pending / Under Review', 'Targets Fixed / Achieved'];
    
    $sIdx = 1;
    foreach ($deptData as $dGroup) {
        $tInfo = $dGroup['targets'];
        $tStr  = $tInfo ? ((int)$tInfo['total_targets'] . ' targets (' . (int)$tInfo['sum_achieved'] . ' achieved)') : '—';
        $rows[] = [
            $sIdx++,
            $dGroup['full_name'],
            $dGroup['info']['code'],
            count($dGroup['records']),
            $dGroup['approved'],
            $dGroup['pending'],
            $tStr,
        ];
    }
    $rows[] = ['TOTAL', 'College Total across All Departments', count($departments) . ' Depts', $totalRecordsCollege, $totalApprovedCollege, $totalPendingCollege, ''];
    $rows[] = ['', '', '', '', '', '', ''];
    $rows[] = ['', '', '', '', '', '', ''];

    // Part B: Department-wise Grouped Records
    foreach ($deptData as $dGroup) {
        $rows[] = ['DEPARTMENT: ' . strtoupper($dGroup['full_name']) . ' (' . $dGroup['info']['code'] . ')', '', '', '', '', '', ''];
        
        if (empty($dGroup['records'])) {
            $rows[] = ['—', 'No records available for the selected Academic Year (' . $academicYear . ').', '', '', $dGroup['info']['code'], '—', '—'];
        } else {
            $rows[] = ['S.No', 'Record Details / Title', 'Type', 'Faculty / Student Name', 'Department', 'Status', 'Date'];
            $rNo = 1;
            foreach ($dGroup['records'] as $r) {
                $rows[] = [
                    $rNo++,
                    $r['_title'],
                    $r['_type_label'],
                    $r['_person'],
                    $dGroup['info']['code'],
                    $r['status'],
                    date('d/m/Y', strtotime($r['created_at'])),
                ];
            }
            $rows[] = ['Subtotal', count($dGroup['records']) . ' records', '', '', $dGroup['info']['code'], $dGroup['approved'] . ' Approved', ''];
        }
        $rows[] = ['', '', '', '', '', '', ''];
    }

    $metaLines = [
        REPORT_INSTITUTION,
        $title,
        'Academic Year: ' . $academicYear,
        'Scope: All Departments (' . count($departments) . ' registered departments)',
        'Report Date: ' . $today,
    ];

    $xlsxData = SimpleXlsxWriter::createXlsx($headers, $rows, 'Consolidated Report', $metaLines);
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
   2. WORD (.doc) & 3. PDF (Print-to-PDF View)
   ===================================================================== */
if ($format === 'word') {
    header('Content-Type: application/msword; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $fileStem . '.doc"');
}

$meta = [
    ['Academic Year', $academicYear],
    ['Scope', 'All Departments (' . count($departments) . ')'],
    ['Total Records', (string)$totalRecordsCollege],
    ['Report Date', $today],
];

report_document_head($title . ' — AY ' . $academicYear, 'landscape');
?>

<?php if ($format === 'pdf'): ?>
  <div class="pdf-bar" style="position:sticky;top:0;background:#1A2547;color:#fff;padding:10px 16px;
       display:flex;align-items:center;justify-content:space-between;font-family:Arial,sans-serif;margin:-1.4cm -1.2cm 16px">
    <span style="font-size:13px">Use your browser's print dialog and choose <strong>Save as PDF</strong>.</span>
    <button onclick="window.print()" style="background:#FF4F01;color:#fff;border:0;border-radius:6px;
       padding:8px 16px;font-size:13px;font-weight:600;cursor:pointer">Print / Save as PDF</button>
  </div>
  <style>@media print { .pdf-bar { display:none !important; } }</style>
<?php endif; ?>

<?php
report_letterhead($title, $meta, ['ACADEMIC YEAR: ' . $academicYear], false);
?>

  <!-- Executive Institutional Summary -->
  <div style="margin-top:20px; margin-bottom:10px; font-weight:bold; font-size:11pt; border-bottom:1.5px solid #000; padding-bottom:3px;">
    INSTITUTIONAL SUMMARY ACROSS ALL DEPARTMENTS
  </div>
  <table class="grid" style="margin-bottom:24px;">
    <thead>
      <tr>
        <th style="width:5%;">S.No</th>
        <th style="width:40%; text-align:left;">Department Name</th>
        <th style="width:10%;">Code</th>
        <th style="width:12%;">Total Records</th>
        <th style="width:10%;">Approved</th>
        <th style="width:10%;">Pending</th>
        <th style="width:13%;">Targets Status</th>
      </tr>
    </thead>
    <tbody>
      <?php $sIndex = 1; foreach ($deptData as $dGroup): ?>
        <tr>
          <td class="c"><?= $sIndex++ ?></td>
          <td><strong><?= e($dGroup['full_name']) ?></strong></td>
          <td class="c"><?= e($dGroup['info']['code']) ?></td>
          <td class="c"><?= count($dGroup['records']) ?></td>
          <td class="c" style="color:#1E7E34; font-weight:600;"><?= $dGroup['approved'] ?></td>
          <td class="c" style="color:#B45309; font-weight:600;"><?= $dGroup['pending'] ?></td>
          <td class="c"><?= $dGroup['targets'] ? ((int)$dGroup['targets']['total_targets'] . ' targets') : '—' ?></td>
        </tr>
      <?php endforeach; ?>
      <tr style="background:#E5E7EB; font-weight:bold;">
        <td class="c" colspan="3" style="text-align:right; padding-right:12px;">TOTAL COLLEGE FIGURES</td>
        <td class="c"><?= $totalRecordsCollege ?></td>
        <td class="c" style="color:#1E7E34;"><?= $totalApprovedCollege ?></td>
        <td class="c" style="color:#B45309;"><?= $totalPendingCollege ?></td>
        <td class="c">—</td>
      </tr>
    </tbody>
  </table>

  <!-- Department-Wise Detailed Sections -->
  <?php $secIdx = 0; foreach ($deptData as $dGroup): $secIdx++; ?>
    <div style="page-break-before:always; margin-top:24px;"></div>
    
    <div style="background:#F3F4F6; border:1px solid #000; padding:8px 12px; margin-bottom:10px;">
      <div style="font-size:12pt; font-weight:bold; color:#000;">
        <?= $secIdx ?>. DEPARTMENT: <?= strtoupper(e($dGroup['full_name'])) ?> (<?= e($dGroup['info']['code']) ?>)
      </div>
      <div style="font-size:9.5pt; color:#374151; margin-top:2px;">
        Total Records: <strong><?= count($dGroup['records']) ?></strong> &middot;
        Approved: <strong style="color:#1E7E34;"><?= $dGroup['approved'] ?></strong> &middot;
        Pending Review: <strong style="color:#B45309;"><?= $dGroup['pending'] ?></strong> &middot;
        Academic Year: <strong><?= e($academicYear) ?></strong>
      </div>
    </div>

    <?php if (empty($dGroup['records'])): ?>
      <div style="padding:16px; border:1px dashed #9CA3AF; text-align:center; color:#4B5563; font-style:italic; margin-bottom:20px;">
        No records available for the selected Academic Year (<?= e($academicYear) ?>).
      </div>
    <?php else: ?>
      <table class="grid" style="margin-bottom:20px;">
        <thead>
          <tr>
            <th style="width:5%;">S.No</th>
            <th style="width:36%; text-align:left;">Record Details / Title</th>
            <th style="width:16%;">Type</th>
            <th style="width:18%;">Faculty / Student</th>
            <th style="width:12%;">Status</th>
            <th style="width:13%;">Date</th>
          </tr>
        </thead>
        <tbody>
          <?php $rIdx = 1; foreach ($dGroup['records'] as $r): ?>
            <tr>
              <td class="c"><?= $rIdx++ ?></td>
              <td><?= e($r['_title']) ?></td>
              <td><?= e($r['_type_label']) ?></td>
              <td><?= e($r['_person'] ?: '—') ?></td>
              <td class="c">
                <span style="font-weight:<?= $r['status'] === 'Approved' ? 'bold' : 'normal' ?>; color:<?= $r['status'] === 'Approved' ? '#1E7E34' : ($r['status'] === 'Rejected' ? '#B91C1C' : '#1F2937') ?>;">
                  <?= e($r['status']) ?>
                </span>
              </td>
              <td class="c"><?= date('d/m/Y', strtotime($r['created_at'])) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  <?php endforeach; ?>

  <div style="margin-top:30px;">
    <?php report_signoff(['HOD', 'DEAN', 'IQAC COORDINATOR', 'PRINCIPAL']); ?>
  </div>

<?php
report_document_foot();
