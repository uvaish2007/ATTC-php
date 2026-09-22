<?php
/**
 * Download the report as a file.
 *
 * reports.php links here with the same filters, e.g.
 *     export.php?format=excel&department=CSBS&status=Approved
 *
 * Three formats, all made with plain PHP - no extra libraries needed:
 *   csv    -> a .csv file (opens in Excel or Google Sheets)
 *   excel  -> an HTML table saved as .xls (Excel opens it and keeps the layout)
 *   word   -> an HTML page saved as .doc (Word opens it and keeps the layout)
 *
 * For PDF: open the Print view from reports.php and choose "Save as PDF".
 */

require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/report_layout.php';
require_once __DIR__ . '/models/Record.php';

$user = require_login();
require_module('reports');

// ---- Read the same filters the Reports page uses ------------------------
$format     = strtolower(trim((string) input('format', 'csv')));
$department = user_department_scope($user, input('department'));
$status     = trim((string) input('status', '')) ?: null;
$type       = trim((string) input('type', '')) ?: null;
$category   = trim((string) input('category', '')) ?: null;
$from       = parse_date_input(input('from', ''));   // period start (YYYY-MM-DD)
$to         = parse_date_input(input('to', ''));     // period end
// EM-SPEC-03: Centralized Academic Year and EM Duration filter resolution.
$rawYear  = input('academic_year') ?: input('year');
$emCtx    = em_resolve_filter_context($rawYear, input('em'));
$year     = $emCtx['year'];
$emFilter = $emCtx['em'];
[$from, $to] = em_intersect_period($emFilter, $from, $to, $year);

if (!in_array($format, ['csv', 'excel', 'word', 'pdf'], true)) {
    $format = 'csv';
}

// A Director's report is always the whole institution — never one department.
if ($user['role'] === 'Director') {
    $department = null;
}

// Handle 'academic_record' or 'all' type alias
$isAllAcademic = ($type === null || $type === 'academic_record' || $type === 'all');
$queryType     = $isAllAcademic ? null : $type;

// ---- Get the records (role scope is applied inside) ---------------------
$records = report_records($user, $department, $status, $queryType, $from, $to, $year);

// Apply category filter if specified
$categories = record_categories();
if ($category !== null && isset($categories[$category])) {
    $catTypes = record_category_types($category);
    $records  = array_values(array_filter($records, fn($r) => in_array($r['_type_key'], $catTypes, true)));
}

// ---- Things that appear in the report heading ---------------------------
$isOversight = in_array($user['role'], ['Admin', 'Director', 'Dean'], true);
$scopeLabel  = $isOversight
    ? department_full_name($department ?: 'ALL DEPARTMENTS')
    : department_full_name($user['department'] ?: 'ALL DEPARTMENTS');

$reportTitle = 'ACADEMIC RECORDS';
if ($queryType) {
    $types = record_types();
    $reportTitle = strtoupper($types[$queryType]['label'] ?? 'ACADEMIC RECORDS');
} elseif ($category && isset($categories[$category])) {
    $reportTitle = strtoupper($categories[$category]['label']);
}

// A human-readable period line for the heading, when a range was chosen.
$periodLabel = null;
if ($from || $to) {
    $fmt = fn(?string $d) => $d ? date('d.m.Y', strtotime($d)) : '…';
    $periodLabel = 'From ' . $fmt($from) . ' to ' . $fmt($to);
}

$today    = date('d.m.Y');
$fileStem = 'iqac-report-' . date('Y-m-d');

// The columns, in order. Same for every format.
$columns = ['S.No', 'Record', 'Type', 'Faculty / Student', 'Department', 'Status', 'Date', 'Proof'];

/**
 * Write one line of the CSV file.
 *
 * PHP 8.4 asks every caller to say which escape character to use. An empty
 * string means "none", which is what Excel and Google Sheets expect: a quote
 * inside a field is doubled ("") rather than backslashed.
 */
function csv_line($handle, array $fields): void
{
    fputcsv($handle, $fields, ',', '"', '');
}

/** Build one row of values for a record in the requested export format. */
function export_row(array $record, int $serial, string $format = 'csv'): array
{
    $type  = $record['_type_key'] ?? '';
    $id    = (int) ($record['id'] ?? 0);
    $pfile = trim((string) ($record['proof_file'] ?? ''));

    if ($format === 'excel') {
        $proofVal = ($pfile !== '')
            ? ['text' => 'View Proof', 'url' => record_proof_url($type, $id, $pfile, false, true)]
            : '—';
    } elseif ($format === 'csv') {
        $proofVal = ($pfile !== '')
            ? record_proof_url($type, $id, $pfile, false, true)
            : '—';
    } else { // html / word / pdf
        if ($pfile !== '') {
            $meta = record_proof_meta($type, $id, $pfile);
            if ($meta) {
                if ($meta['is_image'] && !empty($meta['base64_data'])) {
                    $proofVal = '<div style="text-align:center;">'
                              . '<a href="' . e($meta['view_url']) . '" target="_blank" style="text-decoration:none;">'
                              . '<img src="' . $meta['base64_data'] . '" alt="Proof" style="max-width:90px;max-height:60px;object-fit:contain;border:1px solid #ccc;border-radius:3px;display:block;margin:0 auto 3px auto;">'
                              . '<div style="font-size:8pt;color:#0044cc;text-decoration:underline;word-break:break-all;">' . e($meta['filename']) . '</div>'
                              . '<span style="font-size:7.5pt;color:#555;">(Click to view)</span>'
                              . '</a></div>';
                } else {
                    $ext = $meta['ext'] ? ' (' . strtoupper($meta['ext']) . ')' : '';
                    $proofVal = '<div style="text-align:center;">'
                              . '<a href="' . e($meta['view_url']) . '" target="_blank" style="color:#0044cc;font-weight:600;text-decoration:underline;font-size:9pt;word-break:break-all;">'
                              . 'View Proof' . e($ext)
                              . '</a>'
                              . '<div style="font-size:7.5pt;color:#666;margin-top:2px;word-break:break-all;">' . e($meta['filename']) . '</div>'
                              . '</div>';
                }
            } else {
                $proofVal = '—';
            }
        } else {
            $proofVal = '—';
        }
    }

    return [
        $serial,
        $record['_title'],
        $record['_type_label'],
        $record['_person'],
        !empty($record['department']) ? department_full_name($record['department']) : '-',
        $record['status'],
        date('d/m/Y', strtotime($record['created_at'])),
        $proofVal,
    ];
}


/* ========================================================================
   CSV
   ===================================================================== */
if ($format === 'csv') {

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $fileStem . '.csv"');

    $out = fopen('php://output', 'w');

    // Excel needs this marker to read UTF-8 (é, ñ, …) correctly.
    fwrite($out, chr(0xEF) . chr(0xBB) . chr(0xBF));

    csv_line($out, [REPORT_INSTITUTION . ' - Internal Quality Assurance Cell (IQAC)']);
    csv_line($out, [$reportTitle]);
    csv_line($out, ['Department: ' . $scopeLabel]);
    if ($periodLabel) {
        csv_line($out, ['Period: ' . $periodLabel]);
    }
    csv_line($out, ['Report Date: ' . $today]);
    csv_line($out, []);
    csv_line($out, $columns);

    $serial = 1;
    foreach ($records as $record) {
        csv_line($out, export_row($record, $serial++, 'csv'));
    }

    fclose($out);
    exit;
}


/* ========================================================================
   Excel (.xls) and Word (.doc)

   Both open an HTML table, so the markup below is shared. Only the
   content type and the file extension change.
   ===================================================================== */
if ($format === 'excel') {
    require_once __DIR__ . '/inc/xlsx_writer.php';
    $exportRows = [];
    foreach ($records as $i => $r) {
        $exportRows[] = export_row($r, $i + 1, 'excel');
    }
    $titleLine = $reportTitle . ($year ? ' (' . $year . ')' : '');
    $metaLines = [
        'MOHAMED SATHAK ENGINEERING COLLEGE',
        $titleLine,
        'Department: ' . $scopeLabel,
        'Report Date: ' . $today
    ];
    $xlsxData = (class_exists('ZipArchive') && class_exists('SimpleXlsxWriter'))
        ? SimpleXlsxWriter::createXlsx($columns, $exportRows, 'Academic Records', $metaLines)
        : '';

    if (!empty($xlsxData)) {
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $fileStem . '.xlsx"');
        header('Content-Length: ' . strlen($xlsxData));
        header('Cache-Control: max-age=0');
        echo $xlsxData;
        exit;
    }

    http_response_code(500);
    exit('Failed to generate Excel spreadsheet.');
} elseif ($format === 'word') {
    header('Content-Type: application/msword; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $fileStem . '.doc"');
} else {
    header('Content-Type: text/html; charset=UTF-8');
}

// Same letterhead, grid and sign-off as every other report (inc/report_layout).
$meta = [['Department', $scopeLabel]];
if ($periodLabel) {
    $meta[] = ['Period', $periodLabel];
}
$meta[] = ['Total Records', (string) count($records)];
$meta[] = ['Report Date', $today];

report_document_head($reportTitle . ' Report');
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
report_letterhead($reportTitle, $meta);
?>

  <table class="grid">
    <thead>
      <tr>
        <?php foreach ($columns as $column): ?>
          <th><?= e($column) ?></th>
        <?php endforeach; ?>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($records)): ?>
        <tr>
          <td colspan="<?= count($columns) ?>" class="c">No records found.</td>
        </tr>
      <?php else: ?>
        <?php $serial = 1; ?>
        <?php foreach ($records as $record): ?>
          <tr>
            <?php foreach (export_row($record, $serial++, $format) as $i => $value): ?>
              <?php $isProof = ($i === 7); ?>
              <td<?= $i === 0 ? ' class="num"' : ($isProof ? ' class="c"' : '') ?>><?= $isProof ? $value : e($value) ?></td>
            <?php endforeach; ?>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>

<?php
report_signoff(['HOD' . ($scopeLabel !== 'ALL DEPARTMENTS' ? ' / ' . $scopeLabel : ''), 'IQAC COORDINATOR', 'PRINCIPAL']);
report_document_foot();

