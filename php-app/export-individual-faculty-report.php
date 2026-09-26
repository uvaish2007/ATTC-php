<?php
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/report_layout.php';
require_once __DIR__ . '/inc/xlsx_writer.php';
require_once __DIR__ . '/models/FacultyAchievement.php';

$user = require_login();

$targetFacultyId = (int) input('id', 0);
if (!$targetFacultyId) {
    $targetFacultyId = (int) $user['id'];
}

if (!can_user_view_faculty_report($user, $targetFacultyId)) {
    http_response_code(403);
    require __DIR__ . '/denied.php';
    exit;
}

require_once __DIR__ . '/models/ExecutiveMeeting.php';

$rawYear      = input('academic_year') ?: input('year');
$emCtx        = em_resolve_filter_context($rawYear, input('em'));
$academicYear = $emCtx['year'];
$em           = $emCtx['em'];
$emWindow     = $emCtx['window'];
$category     = trim((string) input('category', '')) ?: null;
$format       = strtolower(trim((string) input('format', 'excel')));

if (!in_array($format, ['excel', 'word', 'csv', 'pdf'], true)) {
    $format = 'excel';
}

$data = faculty_achievement_details($targetFacultyId, $academicYear, $category, $emWindow);
$faculty = $data['faculty'];
$records = $data['records'];
$byCategory = $data['by_category'];
$summary = $data['summary'];

if (!$faculty) {
    http_response_code(404);
    echo "Faculty record not found.";
    exit;
}

$today    = date('d.m.Y');
$fileStem = 'individual-faculty-achievement-report-' . preg_replace('/[^a-z0-9]/i', '-', $faculty['name']) . '-' . date('Y-m-d');
$title    = 'INDIVIDUAL FACULTY ACHIEVEMENT REPORT';

if ($format === 'excel') {
    $headers = ['S.No', 'Category', 'Title / Paper / Activity', 'Department', 'Status', 'Academic Year', 'Submission Date'];
    
    $rows = [];
    $sno = 1;
    foreach ($records as $r) {
        $rows[] = [
            $sno++,
            $r['category'],
            $r['title'],
            department_full_name($r['department']),
            $r['status'],
            $r['year'],
            date('d/m/Y', strtotime($r['created_at'])),
        ];
    }

<<<<<<< HEAD
    $sigCols = ['FACULTY MEMBER', 'HOD / ' . strtoupper(department_full_name($faculty['department'])), 'DEAN / ACADEMICS', 'PRINCIPAL'];
    foreach (report_signoff_excel_rows($sigCols, count($headers)) as $sRow) {
        $rows[] = $sRow;
    }
=======
    $rows = array_merge($rows, report_signoff_rows(
        report_signoff_columns(department_full_name($faculty['department'] ?? null)), count($headers)));
>>>>>>> ac1da4e95ff4ae97513194a6ace61514656c41a6

    $xlsxData = SimpleXlsxWriter::createXlsx($headers, $rows, 'Individual Report', [
        REPORT_INSTITUTION . ' - Internal Quality Assurance Cell (IQAC)',
        $title,
        'Faculty Name: ' . $faculty['name'] . ' (' . $faculty['employee_id'] . ')',
        'Designation: ' . $faculty['designation'],
        'Department: ' . department_full_name($faculty['department']),
        'Academic Year: ' . ($academicYear ?: 'All Years'),
        'Report Date: ' . $today,
    ]);

    if ($xlsxData !== '') {
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $fileStem . '.xlsx"');
        header('Content-Length: ' . strlen($xlsxData));
        echo $xlsxData;
        exit;
    }

    // Fallback to HTML table .xls if XLSX writer is unavailable or fails
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $fileStem . '.xls"');
}

if ($format === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $fileStem . '.csv"');

    $out = fopen('php://output', 'w');
    fwrite($out, chr(0xEF) . chr(0xBB) . chr(0xBF)); 

    fputcsv($out, [REPORT_INSTITUTION . ' - Internal Quality Assurance Cell (IQAC)']);
    fputcsv($out, [$title]);
    fputcsv($out, ['Faculty Name: ' . $faculty['name']]);
    fputcsv($out, ['Employee ID: ' . $faculty['employee_id']]);
    fputcsv($out, ['Designation: ' . $faculty['designation']]);
    fputcsv($out, ['Department: ' . department_full_name($faculty['department'])]);
    fputcsv($out, ['Academic Year: ' . ($academicYear ?: 'All Years')]);
    fputcsv($out, ['Report Date: ' . $today]);
    fputcsv($out, []);

    fputcsv($out, ['S.No', 'Category', 'Title / Description', 'Department', 'Status', 'Academic Year', 'Date']);

    $sno = 1;
    foreach ($records as $r) {
        fputcsv($out, [
            $sno++,
            $r['category'],
            $r['title'],
            department_full_name($r['department']),
            $r['status'],
            $r['year'],
            date('d/m/Y', strtotime($r['created_at'])),
        ]);
    }

    fputcsv($out, []);
    fputcsv($out, []);
    fputcsv($out, ['FACULTY MEMBER', 'HOD / ' . strtoupper(department_full_name($faculty['department'])), 'DEAN / ACADEMICS', 'PRINCIPAL']);

    fclose($out);
    exit;
}

if ($format === 'word') {
    header('Content-Type: application/msword');
    header('Content-Disposition: attachment; filename="' . $fileStem . '.doc"');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title><?= e($title) ?> — <?= e($faculty['name']) ?></title>
  <style>
    body { font-family: "Calibri", "Segoe UI", Arial, sans-serif; font-size: 13px; color: #131D3B; margin: 20px; line-height: 1.4; }
    .hdr-table { width: 100%; border-collapse: collapse; margin-bottom: 16px; border-bottom: 2px solid #131D3B; }
    .hdr-banner { text-align:center; margin-bottom:8px; }
    .hdr-logo { font-size: 20px; font-weight: 800; color: #FF4F01; letter-spacing: -.02em; }
    .hdr-sub { font-size: 12px; color: #5A6785; font-weight: 600; text-transform: uppercase; }
    .title-box { background: #F4F6FA; border: 1px solid #E4E9F2; border-radius: 8px; padding: 14px 18px; margin-bottom: 20px; }
    .title-box h1 { margin: 0 0 6px; font-size: 18px; color: #131D3B; }
    .fac-info-table { width: 100%; border-collapse: collapse; margin-top: 8px; }
    .fac-info-table td { padding: 4px 8px; font-size: 12.5px; }
    .fac-info-label { font-weight: 700; color: #5A6785; width: 130px; }
    table.data-tbl { width: 100%; border-collapse: collapse; margin-bottom: 20px; font-size: 12px; }
    table.data-tbl th { background: #131D3B; color: #ffffff; font-weight: 700; text-align: left; padding: 8px 10px; border: 1px solid #131D3B; }
    table.data-tbl td { padding: 8px 10px; border: 1px solid #E6EAF2; }
    table.data-tbl tr:nth-child(even) { background: #F8FAFC; }
    table.data-tbl td.num, table.data-tbl th.num { text-align: right; }
    .fw-bold { font-weight: 700; }
    .sec-head { font-size: 14px; font-weight: 700; color: #131D3B; margin: 24px 0 8px; border-bottom: 1px solid #E4E9F2; padding-bottom: 4px; }
    .footer { margin-top: 30px; font-size: 11px; color: #8B96AE; text-align: center; border-top: 1px solid #E6EAF2; padding-top: 10px; }
    @media print {
      body { margin: 0; padding: 10mm; }
      .no-print { display: none !important; }
    }
  </style>
</head>
<body>

<<<<<<< HEAD
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
=======
<?php if ($format === 'pdf'): ?>
  <?php report_pdf_bar($title ?? 'Individual Faculty Report',
      [$faculty['name'] ?? '', $faculty['department'] ?? '', 'AY ' . $academicYear]); ?>
<?php endif; ?>
>>>>>>> ac1da4e95ff4ae97513194a6ace61514656c41a6

  <?php $bannerImg = report_banner_img(680); ?>
  <?php if ($bannerImg !== ''): ?>
    <div class="hdr-banner"><?= $bannerImg ?></div>
  <?php endif; ?>

  <table class="hdr-table">
    <tr>
      <td>
<<<<<<< HEAD
        <div class="hdr-logo"><?= e(REPORT_INSTITUTION) ?></div>
        <div class="hdr-sub">Internal Quality Assurance Cell (IQAC) &middot; Academic Target Tracking System</div>
=======
        <?php if ($bannerImg === ''): ?>
          <div class="hdr-logo"><?= e(REPORT_INSTITUTION) ?></div>
        <?php endif; ?>
        <div class="hdr-sub">Internal Quality Assurance Cell (IQAC) &middot; Faculty Profile</div>
>>>>>>> ac1da4e95ff4ae97513194a6ace61514656c41a6
      </td>
      <td style="text-align: right; font-size: 12px; color: #5A6785;">
        <div><strong>Report Date:</strong> <?= e($today) ?></div>
        <div><strong>Academic Year:</strong> <?= e($academicYear ?: 'All Years') ?></div>
      </td>
    </tr>
  </table>

  <div class="title-box">
    <h1><?= e($title) ?></h1>
    <table class="fac-info-table">
      <tr>
        <td class="fac-info-label">Faculty Name:</td>
        <td class="fw-bold"><?= e($faculty['name']) ?></td>
        <td class="fac-info-label">Employee ID:</td>
        <td><?= e($faculty['employee_id']) ?></td>
      </tr>
      <tr>
        <td class="fac-info-label">Designation:</td>
        <td><?= e($faculty['designation']) ?></td>
        <td class="fac-info-label">Department:</td>
        <td><?= e(department_full_name($faculty['department'])) ?></td>
      </tr>
    </table>
  </div>

  <div class="sec-head">Achievement Summary</div>
  <table class="data-tbl">
    <thead>
      <tr>
        <th>Category</th>
        <th class="num">Total Submissions</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($summary)): ?>
        <tr><td colspan="2">No achievements submitted for this academic year.</td></tr>
      <?php else: ?>
        <?php foreach ($summary as $catLabel => $count): ?>
          <tr>
            <td class="fw-bold"><?= e($catLabel) ?></td>
            <td class="num fw-bold"><?= (int) $count ?></td>
          </tr>
        <?php endforeach; ?>
        <tr style="background:#E7EBF3;">
          <td class="fw-bold">Total Achievements</td>
          <td class="num fw-bold" style="color:#FF4F01;"><?= array_sum($summary) ?></td>
        </tr>
      <?php endif; ?>
    </tbody>
  </table>

  <div class="sec-head">Detailed Achievement Records</div>
  <?php if (empty($byCategory)): ?>
    <p>No detailed achievement records available.</p>
  <?php else: ?>
    <?php foreach ($byCategory as $catName => $catItems): ?>
      <h4 style="margin:16px 0 6px; color:#FF4F01;"><?= e($catName) ?> (<?= count($catItems) ?>)</h4>
      <table class="data-tbl">
        <thead>
          <tr>
            <th>#</th>
            <th>Title / Name</th>
            <th>Status</th>
            <th>Academic Year</th>
            <th>Submission Date</th>
          </tr>
        </thead>
        <tbody>
          <?php $idx = 1; foreach ($catItems as $item): ?>
            <tr>
              <td><?= $idx++ ?></td>
              <td class="fw-bold"><?= e($item['title']) ?></td>
              <td><?= e($item['status']) ?></td>
              <td><?= e($item['year']) ?></td>
              <td><?= date('d/m/Y', strtotime($item['created_at'])) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endforeach; ?>
  <?php endif; ?>

  <table class="rpt-sign" style="width:100%; margin-top:40px; margin-bottom:24px; border-collapse:collapse;">
    <tr>
      <td style="text-align:center; font-weight:700; font-size:11px; width:25%;">FACULTY MEMBER</td>
      <td style="text-align:center; font-weight:700; font-size:11px; width:25%;">HOD / <?= e(strtoupper(department_full_name($faculty['department']))) ?></td>
      <td style="text-align:center; font-weight:700; font-size:11px; width:25%;">DEAN / ACADEMICS</td>
      <td style="text-align:center; font-weight:700; font-size:11px; width:25%;">PRINCIPAL</td>
    </tr>
  </table>

  <div class="footer">
    Official Individual Faculty Achievement Document &middot; <?= e(REPORT_INSTITUTION) ?> &middot; Generated <?= e($today) ?>
  </div>

</body>
</html>
