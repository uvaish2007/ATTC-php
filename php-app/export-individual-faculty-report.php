<?php
/**
 * Multi-Format Exporter for Individual Faculty Achievement Report.
 * Exports PDF, Excel (.xlsx), Word (.doc), and CSV formats.
 * Strictly enforces backend permission checking via can_user_view_faculty_report().
 */

require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/report_layout.php';
require_once __DIR__ . '/inc/xlsx_writer.php';
require_once __DIR__ . '/models/FacultyAchievement.php';

$user = require_login();

// Target faculty ID defaults to current logged-in user if unspecified
$targetFacultyId = (int) input('id', 0);
if (!$targetFacultyId) {
    $targetFacultyId = (int) $user['id'];
}

// Backend Authorization Verification
if (!can_user_view_faculty_report($user, $targetFacultyId)) {
    http_response_code(403);
    require __DIR__ . '/denied.php';
    exit;
}

$academicYear = trim((string) input('academic_year', '')) ?: active_academic_year();
$format       = strtolower(trim((string) input('format', 'excel')));

if (!in_array($format, ['excel', 'word', 'csv', 'pdf'], true)) {
    $format = 'excel';
}

// Fetch faculty details and records
$data = faculty_achievement_details($targetFacultyId, $academicYear);
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

/* ========================================================================
   1. EXCEL (.xlsx)
   ===================================================================== */
if ($format === 'excel') {
    $headers = ['S.No', 'Category', 'Title / Paper / Activity', 'Department', 'Status', 'Academic Year', 'Submission Date', 'Proof'];
    
    $rows = [];
    $sno = 1;
    foreach ($records as $r) {
        $pfile = trim((string)($r['proof_file'] ?? ''));
        $pType = $r['type_key'] ?? '';
        $pId   = (int)($r['id'] ?? 0);
        $proofVal = ($pfile !== '')
            ? ['text' => 'View Proof', 'url' => record_proof_url($pType, $pId, $pfile, false, true)]
            : '—';
        $rows[] = [
            $sno++,
            $r['category'],
            $r['title'],
            $r['department'],
            $r['status'],
            $r['year'],
            date('d/m/Y', strtotime($r['created_at'])),
            $proofVal,
        ];
    }

    // TS-REP-03 — this report is countersigned like every other export.
    $rows = array_merge($rows, report_signoff_rows(
        report_signoff_columns(department_full_name($faculty['department'] ?? null)), count($headers)));

    $xlsxData = SimpleXlsxWriter::createXlsx($headers, $rows, 'Individual Report', [
        'title'       => $title,
        'department'  => $faculty['department'],
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
    fputcsv($out, ['Faculty Name: ' . $faculty['name']]);
    fputcsv($out, ['Employee ID: ' . $faculty['employee_id']]);
    fputcsv($out, ['Designation: ' . $faculty['designation']]);
    fputcsv($out, ['Department: ' . $faculty['department']]);
    fputcsv($out, ['Academic Year: ' . ($academicYear ?: 'All Years')]);
    fputcsv($out, ['Report Date: ' . $today]);
    fputcsv($out, []);

    fputcsv($out, ['S.No', 'Category', 'Title / Description', 'Department', 'Status', 'Academic Year', 'Date', 'Proof']);

    $sno = 1;
    foreach ($records as $r) {
        $pfile = trim((string)($r['proof_file'] ?? ''));
        $pType = $r['type_key'] ?? '';
        $pId   = (int)($r['id'] ?? 0);
        $proofVal = ($pfile !== '') ? record_proof_url($pType, $pId, $pfile, false, true) : '—';
        fputcsv($out, [
            $sno++,
            $r['category'],
            $r['title'],
            $r['department'],
            $r['status'],
            $r['year'],
            date('d/m/Y', strtotime($r['created_at'])),
            $proofVal,
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
  <title><?= e($title) ?> — <?= e($faculty['name']) ?></title>
  <style>
    body { font-family: "Calibri", "Segoe UI", Arial, sans-serif; font-size: 13px; color: #131D3B; margin: 20px; line-height: 1.4; }
    .hdr-table { width: 100%; border-collapse: collapse; margin-bottom: 16px; border-bottom: 2px solid #131D3B; }
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

<?php if ($format === 'pdf'): ?>
  <div class="no-print" style="position:sticky;top:0;background:#1A2547;color:#fff;padding:10px 16px;margin:-20px -20px 20px -20px;display:flex;align-items:center;justify-content:space-between;">
    <span style="font-size:13px">Use your browser's print dialog and select <strong>Save as PDF</strong>.</span>
    <button onclick="window.print()" style="background:#FF4F01;color:#fff;border:0;border-radius:6px;padding:8px 16px;font-size:13px;font-weight:600;cursor:pointer;">Print / Save as PDF</button>
  </div>
<?php endif; ?>

  <table class="hdr-table">
    <tr>
      <td>
        <div class="hdr-logo"><?= e(REPORT_INSTITUTION) ?></div>
        <div class="hdr-sub">Internal Quality Assurance Cell (IQAC) &middot; Faculty Profile</div>
      </td>
      <td style="text-align:right; font-size:11px; color:#5A6785;">
        Date: <?= e($today) ?><br>
        Academic Year: <strong><?= e($academicYear ?: 'All Years') ?></strong>
      </td>
    </tr>
  </table>

  <div class="title-box">
    <h1><?= e($title) ?></h1>
    <table class="fac-info-table">
      <tr>
        <td class="fac-info-label">Faculty Name:</td>
        <td class="fw-bold" style="font-size:14px; color:#FF4F01;"><?= e($faculty['name']) ?></td>
        <td class="fac-info-label">Employee ID:</td>
        <td class="fw-bold"><?= e($faculty['employee_id']) ?></td>
      </tr>
      <tr>
        <td class="fac-info-label">Designation:</td>
        <td><?= e($faculty['designation']) ?></td>
        <td class="fac-info-label">Department:</td>
        <td><?= e($faculty['department']) ?></td>
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
            <th>Proof</th>
          </tr>
        </thead>
        <tbody>
          <?php $idx = 1; foreach ($catItems as $item): ?>
            <?php
              $pfile = trim((string)($item['proof_file'] ?? ''));
              $pType = $item['type_key'] ?? '';
              $pId   = (int)($item['id'] ?? 0);
              $meta  = ($pfile !== '') ? record_proof_meta($pType, $pId, $pfile) : null;
            ?>
            <tr>
              <td><?= $idx++ ?></td>
              <td class="fw-bold"><?= e($item['title']) ?></td>
              <td><?= e($item['status']) ?></td>
              <td><?= e($item['year']) ?></td>
              <td><?= date('d/m/Y', strtotime($item['created_at'])) ?></td>
              <td style="text-align:center;">
                <?php if ($meta): ?>
                  <?php if ($meta['is_image'] && !empty($meta['base64_data'])): ?>
                    <a href="<?= e($meta['view_url']) ?>" target="_blank" style="text-decoration:none;">
                      <img src="<?= $meta['base64_data'] ?>" alt="Proof" style="max-width:80px;max-height:50px;object-fit:contain;border:1px solid #ccc;border-radius:3px;display:block;margin:0 auto 2px auto;">
                      <span style="font-size:8pt;color:#0044cc;text-decoration:underline;">View Proof</span>
                    </a>
                  <?php else: ?>
                    <a href="<?= e($meta['view_url']) ?>" target="_blank" style="color:#0044cc;font-weight:600;font-size:9pt;text-decoration:underline;">
                      View Proof<?= $meta['ext'] ? ' (' . strtoupper(e($meta['ext'])) . ')' : '' ?>
                    </a>
                  <?php endif; ?>
                <?php else: ?>
                  —
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endforeach; ?>
  <?php endif; ?>

  <div class="footer">
    Official Individual Faculty Achievement Document &middot; <?= e(REPORT_INSTITUTION) ?> &middot; Generated <?= e($today) ?>
  </div>

</body>
</html>
