<?php
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/report_layout.php';
require_once __DIR__ . '/models/Record.php';

$user = require_login();
require_module('reports');

$format     = strtolower(trim((string) input('format', 'csv')));
$department = user_department_scope($user, input('department'));
$status     = trim((string) input('status', '')) ?: null;
$type       = trim((string) input('type', '')) ?: null;
<<<<<<< HEAD
$from       = parse_date_input(input('from', ''));   // period start (YYYY-MM-DD)
$to         = parse_date_input(input('to', ''));     // period end
// FEAT-07: narrow to one Executive Meeting (EM1/EM2), same as the Reports page.
// A meeting window belongs to one academic year, so the year is pinned too;
// with "all" em_filter_year() is null and this export is unchanged.
$emFilter    = em_filter_value(input('em'));
[$from, $to] = em_intersect_period($emFilter, $from, $to);

=======
$category   = trim((string) input('category', '')) ?: null;
$from       = parse_date_input(input('from', ''));   
$to         = parse_date_input(input('to', ''));     

$rawYear  = input('academic_year') ?: input('year');
$emCtx    = em_resolve_filter_context($rawYear, input('em'));
$year     = $emCtx['year'];
$emFilter = $emCtx['em'];
[$from, $to] = em_intersect_period($emFilter, $from, $to, $year);

>>>>>>> ac1da4e95ff4ae97513194a6ace61514656c41a6
if (!in_array($format, ['csv', 'excel', 'word', 'pdf'], true)) {
    $format = 'csv';
}

// A Director's report is always the whole institution — never one department.
if ($user['role'] === 'Director') {
    $department = null;
}

<<<<<<< HEAD
// ---- Get the records (role scope is applied inside) ---------------------
$records = report_records($user, $department, $status, $type, $from, $to, em_filter_year($emFilter));
=======
// Handle 'academic_record' or 'all' type alias
$isAllAcademic = ($type === null || $type === 'academic_record' || $type === 'all');
$queryType     = $isAllAcademic ? null : $type;

$records = report_records($user, $department, $status, $queryType, $from, $to, $year);

$categories = record_categories();
if ($category !== null && isset($categories[$category])) {
    $catTypes = record_category_types($category);
    $records  = array_values(array_filter($records, fn($r) => in_array($r['_type_key'], $catTypes, true)));
}
>>>>>>> ac1da4e95ff4ae97513194a6ace61514656c41a6

$isOversight = in_array($user['role'], ['Admin', 'Director', 'Dean'], true);
$scopeLabel  = $isOversight
    ? department_full_name($department ?: 'ALL DEPARTMENTS')
    : department_full_name($user['department'] ?: 'ALL DEPARTMENTS');

$reportTitle = 'ACADEMIC RECORDS';
if ($type) {
    $types = record_types();
    $reportTitle = strtoupper($types[$type]['label'] ?? 'ACADEMIC RECORDS');
}

$periodLabel = null;
if ($from || $to) {
    $fmt = fn(?string $d) => $d ? date('d.m.Y', strtotime($d)) : '…';
    $periodLabel = 'From ' . $fmt($from) . ' to ' . $fmt($to);
}

$today    = date('d.m.Y');
$fileStem = 'iqac-report-' . date('Y-m-d');

<<<<<<< HEAD
// The columns, in order. Same for every format.
$columns = ['S.No', 'Record', 'Type', 'Faculty / Student', 'Department', 'Status', 'Date'];
=======
$columns = ['S.No', 'Record', 'Type', 'Faculty / Student', 'Department', 'Status', 'Date', 'Proof'];
>>>>>>> ac1da4e95ff4ae97513194a6ace61514656c41a6

function csv_line($handle, array $fields): void
{
    fputcsv($handle, $fields, ',', '"', '');
}

<<<<<<< HEAD
/** Build one row of plain values for a record. */
function export_row(array $record, int $serial): array
{
=======
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
    } else { 
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

>>>>>>> ac1da4e95ff4ae97513194a6ace61514656c41a6
    return [
        $serial,
        $record['_title'],
        $record['_type_label'],
        $record['_person'],
        !empty($record['department']) ? department_full_name($record['department']) : '-',
        $record['status'],
        date('d/m/Y', strtotime($record['created_at'])),
    ];
}

if ($format === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $fileStem . '.csv"');

    $out = fopen('php://output', 'w');

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
        csv_line($out, export_row($record, $serial++));
    }

    csv_line($out, []);
    csv_line($out, []);
    csv_line($out, ['HOD' . ($scopeLabel !== 'ALL DEPARTMENTS' ? ' / ' . $scopeLabel : ''), 'DEAN / ACADEMICS', 'IQAC COORDINATOR', 'PRINCIPAL']);

    fclose($out);
    exit;
}

if ($format === 'excel') {
    require_once __DIR__ . '/inc/xlsx_writer.php';
    $exportRows = [];
    foreach ($records as $i => $r) {
        $exportRows[] = export_row($r, $i + 1);
    }
    $sigCols = ['HOD' . ($scopeLabel !== 'ALL DEPARTMENTS' ? ' / ' . $scopeLabel : ''), 'DEAN / ACADEMICS', 'IQAC COORDINATOR', 'PRINCIPAL'];
    foreach (report_signoff_excel_rows($sigCols, count($columns)) as $sRow) {
        $exportRows[] = $sRow;
    }
    $metaLines = [
        'MOHAMED SATHAK ENGINEERING COLLEGE',
        $reportTitle,
        'Department: ' . $scopeLabel,
        'Report Date: ' . $today
    ];
    $xlsxData = class_exists('SimpleXlsxWriter')
        ? SimpleXlsxWriter::createXlsx($columns,
            array_merge($exportRows, report_signoff_rows(report_signoff_columns($scopeLabel), count($columns))),
            'Academic Records', $metaLines)
        : '';

    if (!empty($xlsxData)) {
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
} elseif ($format === 'word') {
    header('Content-Type: application/msword; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $fileStem . '.doc"');
} else {
    header('Content-Type: text/html; charset=UTF-8');
}

$meta = [['Department', $scopeLabel]];
if ($periodLabel) {
    $meta[] = ['Period', $periodLabel];
}
$meta[] = ['Total Records', (string) count($records)];
$meta[] = ['Report Date', $today];

report_document_head($reportTitle . ' Report');
?>

<?php if ($format === 'pdf'): ?>
  <?php report_pdf_bar($reportTitle . ' Report', [$scopeLabel, $periodLabel,
      number_format(count($records)) . ' records']); ?>
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
            <?php foreach (export_row($record, $serial++) as $i => $value): ?>
              <td<?= $i === 0 ? ' class="num"' : '' ?>><?= e($value) ?></td>
            <?php endforeach; ?>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>

<?php
<<<<<<< HEAD
report_signoff(['HOD' . ($scopeLabel !== 'ALL DEPARTMENTS' ? ' / ' . $scopeLabel : ''), 'DEAN / ACADEMICS', 'IQAC COORDINATOR', 'PRINCIPAL']);
=======
report_signoff(report_signoff_columns($scopeLabel));
>>>>>>> ac1da4e95ff4ae97513194a6ace61514656c41a6
report_document_foot();
