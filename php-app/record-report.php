<?php
/**
 * Record Report — any record type rendered as its official IQAC template.
 *
 * The columns and title come from inc/record_specs.php (built from the Excel/Word
 * templates). Data is that type's records, role-scoped. A single-department
 * report drops the "Dept" column and shows DEPARTMENT OF <name> in the heading
 * (the Word template); an all-departments report keeps the Dept column (the
 * Excel template).
 *
 *   ?type=journal   which report
 *   ?department=CSE oversight roles may pick one (or omit for all)
 *   ?year=2025-26   the academic year in the title (and filter, where records carry one)
 *   ?format=word|excel|pdf
 */

require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/report_layout.php';
require_once __DIR__ . '/inc/record_specs.php';
require_once __DIR__ . '/models/Record.php';
require_once __DIR__ . '/models/Target.php';   // academic_years()

$user = require_login();

$type = trim((string) input('type'));
$spec = record_report_spec($type);
if ($spec === null) {
    http_response_code(404);
    exit('Unknown report type.');
}

$format = strtolower(trim((string) input('format', 'word')));
if (!in_array($format, ['word', 'excel', 'pdf'], true)) {
    $format = 'word';
}

// Scope: oversight roles choose a department (or all); everyone else is pinned
// to their own. This mirrors report_records()'s own scoping.
$isOversight  = in_array($user['role'], ['Admin', 'Director'], true);
$department   = $isOversight ? (trim((string) input('department')) ?: null) : ($user['department'] ?? null);
// The system's active academic year — never the client-supplied ?year=,
// which a hand-built URL could set to any year (this page is reachable
// directly, not only through reports.php's own, already year-locked links).
$year         = active_academic_year();
$singleDept   = $department !== null;

// Optional review-status and submission-period filters (from the Reports page).
$status = trim((string) input('status')) ?: null;
if (!in_array($status, ['Draft', 'Submitted', 'Approved', 'Rejected'], true)) { $status = null; }
$from = parse_date_input((string) input('from'));
$to   = parse_date_input((string) input('to'));

// Records of this type, in the user's scope and the active year, newest first.
$records = report_records($user, $isOversight ? $department : null, $status, $type, $from, $to, $year);

// Columns: drop the consolidated "Dept" column for a single-department report.
$columns = array_values(array_filter($spec['columns'], fn($c) => !($singleDept && $c[1] === 'department')));

$deptLabel = $singleDept ? strtoupper(department_full_name((string) $department)) : 'ALL DEPARTMENTS';
$today     = date('d.m.Y');
$span      = max(1, count($columns));

$slug     = preg_replace('/[^A-Za-z0-9]+/', '-', $type . '-' . ($department ?: 'all'));
$fileStem = trim($slug, '-') . '-' . date('Y-m-d');

if ($format === 'word') {
    header('Content-Type: application/msword; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $fileStem . '.doc"');
} elseif ($format === 'excel') {
    require_once __DIR__ . '/inc/xlsx_writer.php';

    $headers = array_map(fn($c) => (string) $c[0], $columns);
    $exportRows = [];
    $serial = 1;
    foreach ($records as $r) {
        $rowVal = [];
        foreach ($columns as [$label, $field]) {
            $rowVal[] = $field === '#' ? (string) $serial : (string) ($r[$field] ?? '');
        }
        $exportRows[] = $rowVal;
        $serial++;
    }

    $titleLine = $spec['title'] . ($year !== null ? ' - DURING THE ACADEMIC YEAR ' . $year : ' - ALL ACADEMIC YEARS');
    $metaLines = [
        'MOHAMED SATHAK ENGINEERING COLLEGE',
        $titleLine,
        'Department: ' . ($singleDept ? (string) $department : 'All departments'),
        'Report Date: ' . $today
    ];

    $xlsxData = (class_exists('ZipArchive') && class_exists('SimpleXlsxWriter'))
        ? SimpleXlsxWriter::createXlsx($headers, $exportRows, mb_substr($spec['title'], 0, 31), $metaLines)
        : '';

    if (!empty($xlsxData)) {
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $fileStem . '.xlsx"');
        header('Content-Length: ' . strlen($xlsxData));
        header('Cache-Control: max-age=0');
        echo $xlsxData;
        exit;
    }

    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $fileStem . '.xls"');
} else {
    header('Content-Type: text/html; charset=UTF-8');
}

report_document_head($spec['title'], 'landscape');
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
$reportTitle  = $spec['title'] . ($year !== null ? ' - DURING THE ACADEMIC YEAR ' . $year : ' - ALL ACADEMIC YEARS');
$headingLines = [];
if ($singleDept)               { $mainTitle = 'DEPARTMENT OF ' . $deptLabel; $headingLines[] = $reportTitle; }
else                           { $mainTitle = $reportTitle; }
if (!empty($spec['subtitle'])) { $headingLines[] = $spec['subtitle']; }

$meta = [['Department', $singleDept ? (string) $department : 'All departments']];
if ($status !== null) { $meta[] = ['Status', $status]; }
if ($from || $to) {
    $fmt = fn(?string $d) => $d ? date('d.m.Y', strtotime($d)) : '…';
    $meta[] = ['Period', $fmt($from) . ' to ' . $fmt($to)];
}
$meta[] = ['Total Records', (string) count($records)];
$meta[] = ['Report Date', $today];
report_letterhead($mainTitle, $meta, $headingLines);
?>

  <table class="grid">
    <thead>
      <tr>
        <?php foreach ($columns as [$label, $field]): ?>
          <th><?= e($label) ?></th>
        <?php endforeach; ?>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($records)): ?>
        <tr><td colspan="<?= $span ?>" class="c">No records for this scope.</td></tr>
      <?php else: ?>
        <?php $serial = 1; ?>
        <?php foreach ($records as $r): ?>
          <tr>
            <?php foreach ($columns as [$label, $field]): ?>
              <?php $val = $field === '#' ? (string) $serial : (string) ($r[$field] ?? ''); ?>
              <td<?= $field === '#' ? ' class="num"' : '' ?>><?= e($val) ?></td>
            <?php endforeach; ?>
          </tr>
          <?php $serial++; ?>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>

<?php
report_signoff(['HOD' . ($singleDept ? ' / ' . $department : ''), 'DEAN / ACADEMICS', 'PRINCIPAL']);
report_document_foot();
