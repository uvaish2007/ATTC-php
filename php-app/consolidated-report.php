<?php
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

// Active academic year or requested year filter
$academicYear = trim((string) input('year', '')) ?: active_academic_year();
$format       = strtolower(trim((string) input('format', 'pdf')));
if (!in_array($format, ['pdf', 'excel', 'word'], true)) {
    $format = 'pdf';
}

$today    = date('d.m.Y');
$safeYear = preg_replace('/[^A-Za-z0-9\-]/', '-', $academicYear);
$fileStem = "ATTS_Consolidated_All_Department_Report_{$safeYear}";
$title    = 'CONSOLIDATED ALL-DEPARTMENT REPORT';

$departments = departments_all();

require_once __DIR__ . '/inc/patent_handler.php';

$adminScopeUser = ['id' => (int)$user['id'], 'role' => 'Admin', 'name' => 'Consolidated', 'department' => null];
$rawRecords     = report_records($adminScopeUser, null, null, null, null, null, $academicYear);
$allRecords     = patent_consolidate_records($rawRecords);

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
}

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

$totalRecordsCollege  = count($allRecords);
$totalApprovedCollege = 0;
$totalPendingCollege  = 0;
foreach ($deptData as $dGroup) {
    $totalApprovedCollege += $dGroup['approved'];
    $totalPendingCollege  += $dGroup['pending'];
}

/* ========================================================================
   1. EXCEL EXPORT (.xlsx) — Multi-Tab Consolidated Workbook
   ===================================================================== */
if ($format === 'excel') {
    $sheets = [];
    $sigCols = ['HOD', 'DEAN / ACADEMICS', 'IQAC COORDINATOR', 'PRINCIPAL'];

    // Part A: Sheet 1 — Institutional Overview
    $overviewHeaders = ['S.No', 'Department Name', 'Code', 'Total Records', 'Approved', 'Pending / Under Review', 'Targets Fixed / Achieved'];
    $overviewRows = [];

    $sIdx = 1;
    foreach ($deptData as $dGroup) {
        $tInfo = $dGroup['targets'];
        $tStr  = $tInfo ? ((int)$tInfo['total_targets'] . ' targets (' . (int)$tInfo['sum_achieved'] . ' achieved)') : '—';
        $overviewRows[] = [
            $sIdx++,
            $dGroup['full_name'],
            $dGroup['info']['code'],
            count($dGroup['records']),
            $dGroup['approved'],
            $dGroup['pending'],
            $tStr,
        ];
    }
    $overviewRows[] = ['TOTAL', 'College Total across All Departments', count($departments) . ' Depts', $totalRecordsCollege, $totalApprovedCollege, $totalPendingCollege, ''];

    foreach (report_signoff_excel_rows($sigCols, count($overviewHeaders)) as $sRow) {
        $overviewRows[] = $sRow;
    }

    $overviewMeta = [
        REPORT_INSTITUTION,
        $title . ' — INSTITUTIONAL OVERVIEW',
        'Academic Year: ' . $academicYear,
        'Scope: All Departments (' . count($departments) . ' registered departments)',
        'Report Date: ' . $today,
    ];

    $sheets[] = [
        'title'   => 'College Overview',
        'headers' => $overviewHeaders,
        'rows'    => $overviewRows,
        'meta'    => $overviewMeta,
    ];

    // Part A.2: Sheet — Patents & Copyrights Consolidated Register
    $patentRecords = array_values(array_filter($allRecords, fn($r) => in_array($r['_type_key'] ?? '', ['patent', 'inst_patents_published', 'inst_patents_granted', 'inst_copyrights'], true)));
    if (!empty($patentRecords)) {
        $patHeaders = ['S.No', 'Patent / Registration No', 'Title of Work', 'Category', 'Academic Session', 'Faculty & Co-Authors', 'Department(s)', 'Date of Issue', 'Document Link', 'Attachments', 'Status', 'Submissions'];
        $patRows = [];
        $pIdx = 1;
        foreach ($patentRecords as $pr) {
            $regDisplay = $pr['_display_reg_no'] ?? ($pr['patent_number'] ?? '—');
            if (!empty($pr['_needs_review'])) {
                $regDisplay .= ' [NEEDS REVIEW: Missing No.]';
            }
            $patRows[] = [
                $pIdx++,
                $regDisplay,
                $pr['_title'],
                $pr['category'] ?? 'Patent',
                $pr['academic_session'] ?? $pr['exam_session'] ?? $academicYear,
                $pr['_author_summary'] ?: ($pr['_person'] ?: '—'),
                $pr['_department'] ?: ($pr['department'] ?? '—'),
                !empty($pr['publication_date']) ? date('d/m/Y', strtotime($pr['publication_date'])) : '—',
                $pr['_document_link'] ?: ($pr['document_link'] ?? '—'),
                $pr['_attachment_summary'] ?? (!empty($pr['proof_file']) ? '1 Attachment' : 'None'),
                $pr['status'],
                (int)($pr['_submissions_count'] ?? 1),
            ];
        }
        foreach (report_signoff_excel_rows($sigCols, count($patHeaders)) as $sRow) {
            $patRows[] = $sRow;
        }
        $patMeta = [
            REPORT_INSTITUTION,
            $title . ' — PATENTS & COPYRIGHTS REGISTER',
            'Academic Year: ' . $academicYear,
            'Scope: Deduplicated by Registration Number (' . count($patentRecords) . ' unique records)',
            'Report Date: ' . $today,
        ];
        $sheets[] = [
            'title'   => 'Patents & Copyrights',
            'headers' => $patHeaders,
            'rows'    => $patRows,
            'meta'    => $patMeta,
        ];
    }

    // Part B: Sheets 2..N — Department-wise Grouped Records Tabs
    $deptHeaders = ['S.No', 'Record Details / Title', 'Type', 'Faculty / Student Name', 'Department', 'Status', 'Date'];

    foreach ($deptData as $dGroup) {
        $deptRows = [];

        if (empty($dGroup['records'])) {
            $deptRows[] = ['—', 'No records available for the selected Academic Year (' . $academicYear . ').', '', '', $dGroup['full_name'], '—', '—'];
        } else {
            $rNo = 1;
            foreach ($dGroup['records'] as $r) {
                $isPat = in_array($r['_type_key'] ?? '', ['patent', 'inst_patents_published', 'inst_patents_granted', 'inst_copyrights'], true);
                $titleText = $r['_title'];
                if ($isPat && !empty($r['_display_reg_no'])) {
                    $titleText = '[' . $r['_display_reg_no'] . '] ' . $titleText;
                }
                $personText = $isPat && !empty($r['_author_summary']) ? $r['_author_summary'] : $r['_person'];
                $statusText = $r['status'];
                if (!empty($r['_is_consolidated'])) {
                    $statusText .= ' (Consolidated: ' . $r['_submissions_count'] . ' submissions)';
                }
                if (!empty($r['_needs_review'])) {
                    $statusText .= ' [NEEDS REVIEW: Missing No.]';
                }

                $deptRows[] = [
                    $rNo++,
                    $titleText,
                    $r['_type_label'],
                    $personText,
                    $dGroup['full_name'],
                    $statusText,
                    date('d/m/Y', strtotime($r['created_at'])),
                ];
            }
            $deptRows[] = ['Subtotal', count($dGroup['records']) . ' records', '', '', $dGroup['full_name'], $dGroup['approved'] . ' Approved, ' . $dGroup['pending'] . ' Pending', ''];
        }

        foreach (report_signoff_excel_rows($sigCols, count($deptHeaders)) as $sRow) {
            $deptRows[] = $sRow;
        }

        $deptMeta = [
            REPORT_INSTITUTION,
            'DEPARTMENT: ' . mb_strtoupper($dGroup['full_name']) . ' (' . $dGroup['info']['code'] . ')',
            'Academic Year: ' . $academicYear,
            'Department Records: ' . count($dGroup['records']) . ' total (' . $dGroup['approved'] . ' approved, ' . $dGroup['pending'] . ' pending)',
            'Report Date: ' . $today,
        ];

        // Clean sheet title (Department Code or Name, max 31 characters, no invalid chars)
        $sheetTitle = trim((string)($dGroup['info']['code'] ?: $dGroup['info']['name']));
        $sheetTitle = preg_replace('/[\\\\\\/\?\*\:\[\]]/', '', $sheetTitle);
        $sheetTitle = mb_substr(trim($sheetTitle) ?: 'Dept', 0, 31);

        $sheets[] = [
            'title'   => $sheetTitle,
            'headers' => $deptHeaders,
            'rows'    => $deptRows,
            'meta'    => $deptMeta,
        ];
    }

    $xlsxData = SimpleXlsxWriter::createMultiSheetXlsx($sheets);
    if ($xlsxData !== '') {
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $fileStem . '.xlsx"');
        header('Content-Length: ' . strlen($xlsxData));
        header('Cache-Control: max-age=0');
        echo $xlsxData;
        exit;
    }

    // Fallback to HTML table .xls if XLSX writer is unavailable or fails
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $fileStem . '.xls"');
}

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
  <?php report_pdf_bar($title, ['All departments', 'AY ' . $academicYear,
      number_format((int) $totalRecordsCollege) . ' records', count($departments) . ' departments']); ?>
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

  <!-- Executive Patents & Copyrights Consolidated Register (Deduplicated by Registration Number) -->
  <?php if (!empty($patentRecords)): ?>
    <div style="margin-top:28px; margin-bottom:10px; font-weight:bold; font-size:11pt; border-bottom:1.5px solid #000; padding-bottom:3px; display:flex; justify-content:space-between; align-items:center;">
      <span>PATENTS &amp; COPYRIGHTS REGISTER (STANDARDIZED &amp; DEDUPLICATED BY REGISTRATION NO.)</span>
      <span style="font-size:9.5pt; font-weight:normal; color:#4B5563;"><?= count($patentRecords) ?> Unique Intellectual Property Items</span>
    </div>
    <table class="grid" style="margin-bottom:24px;">
      <thead>
        <tr>
          <th style="width:4%;">#</th>
          <th style="width:17%; text-align:left;">Registration / Patent No.</th>
          <th style="width:26%; text-align:left;">Title of Work</th>
          <th style="width:10%;">Category</th>
          <th style="width:18%; text-align:left;">Authors &amp; Co-Authors</th>
          <th style="width:9%;">Dept(s)</th>
          <th style="width:8%;">Date</th>
          <th style="width:8%;">Document</th>
        </tr>
      </thead>
      <tbody>
        <?php $pNo = 1; foreach ($patentRecords as $pr): ?>
          <tr>
            <td class="c"><?= $pNo++ ?></td>
            <td style="font-family:monospace; font-weight:700; color:#1E3A8A;">
              <?= e($pr['_display_reg_no'] ?? ($pr['patent_number'] ?? '—')) ?>
              <?php if (!empty($pr['_needs_review'])): ?>
                <div style="color:#DC2626; font-size:9.5px; font-weight:normal; font-family:sans-serif;">⚠ Review: Missing No.</div>
              <?php endif; ?>
            </td>
            <td>
              <strong><?= e($pr['_title']) ?></strong>
              <?php if (!empty($pr['_is_consolidated'])): ?>
                <div style="font-size:10px; color:#4F46E5; margin-top:2px;">(Consolidated: <?= (int)$pr['_submissions_count'] ?> submissions)</div>
              <?php endif; ?>
            </td>
            <td class="c"><?= e($pr['category'] ?? 'Patent') ?></td>
            <td style="font-size:10.5px;"><?= e($pr['_author_summary'] ?: ($pr['_person'] ?: '—')) ?></td>
            <td class="c" style="font-size:10.5px;"><?= e($pr['_department'] ?: ($pr['department'] ?? '—')) ?></td>
            <td class="c" style="font-size:10.5px;"><?= !empty($pr['publication_date']) ? date('d/m/Y', strtotime($pr['publication_date'])) : '—' ?></td>
            <td class="c" style="font-size:11px;">
              <?php $dUrl = $pr['_document_link'] ?: ($pr['document_link'] ?? ''); ?>
              <?php if (!empty($dUrl) && preg_match('/^https?:\/\//i', $dUrl)): ?>
                <a href="<?= e($dUrl) ?>" target="_blank" rel="noopener noreferrer" style="color:#2563EB; text-decoration:underline; font-weight:600;">View Doc</a>
              <?php else: ?>
                —
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <!-- Department-Wise Detailed Sections -->
  <?php $secIdx = 0; foreach ($deptData as $dGroup): $secIdx++; ?>
    <div style="page-break-before:always; margin-top:24px;"></div>
    
    <div style="background:#F3F4F6; border:1px solid #000; padding:8px 12px; margin-bottom:10px;">
      <div style="font-size:12pt; font-weight:bold; color:#000;">
        <?= $secIdx ?>. DEPARTMENT OF <?= strtoupper(e($dGroup['full_name'])) ?>
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
            <?php
              $isPat = in_array($r['_type_key'] ?? '', ['patent', 'inst_patents_published', 'inst_patents_granted', 'inst_copyrights'], true);
            ?>
            <tr>
              <td class="c"><?= $rIdx++ ?></td>
              <td>
                <?php if ($isPat && !empty($r['_display_reg_no'])): ?>
                  <div style="font-size:11px; font-weight:700; color:#1D4ED8; font-family:monospace; margin-bottom:2px;">
                    Reg No: <?= e($r['_display_reg_no']) ?>
                  </div>
                <?php endif; ?>
                <div><?= e($r['_title']) ?></div>
                <?php if (!empty($r['_needs_review'])): ?>
                  <div style="display:inline-block; margin-top:3px; padding:1px 6px; background:#FEE2E2; color:#DC2626; border-radius:3px; font-size:10px; font-weight:700;">⚠ Review Required: Missing No.</div>
                <?php elseif (!empty($r['_is_consolidated'])): ?>
                  <div style="display:inline-block; margin-top:3px; padding:1px 6px; background:#EEF2FF; color:#4338CA; border-radius:3px; font-size:10px; font-weight:600;">Consolidated across <?= (int)$r['_submissions_count'] ?> submissions</div>
                <?php endif; ?>
                <?php if (!empty($r['document_link']) && preg_match('/^https?:\/\//i', $r['document_link'])): ?>
                  <div style="margin-top:2px; font-size:11px;"><a href="<?= e($r['document_link']) ?>" target="_blank" rel="noopener noreferrer" style="color:#2563EB; text-decoration:underline;">View Document &rarr;</a></div>
                <?php endif; ?>
              </td>
              <td><?= e($r['_type_label']) ?></td>
              <td><?= e(!empty($r['_author_summary']) ? $r['_author_summary'] : ($r['_person'] ?: '—')) ?></td>
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
    <?php report_signoff(report_signoff_columns()); ?>
  </div>

<?php
report_document_foot();
