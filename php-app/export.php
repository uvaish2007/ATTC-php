<?php
/**
 * Export and print-ready renderers for records.
 *
 * Emits the current list of records — matching whatever filters are in
 * effect — as a downloadable CSV, an Excel (.xlsx) file, a Word (.doc)
 * document, or as a browser-rendered page ready for Ctrl+P / Save as PDF.
 *
 * Query parameters:
 *   format     csv | excel | word | pdf
 *   type       record type key (or omit for all types)
 *   category   faculty | activity | student
 *   department department code (oversight roles only)
 *   status     Draft | Submitted | Approved | Rejected
 *   from       YYYY-MM-DD
 *   to         YYYY-MM-DD
 *   year       academic year (e.g. 2024-25)
 *   em         all | em1 | em2
 *   event_mode Online | Offline | Hybrid (events only)
 */

require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/helpers.php';
require_once __DIR__ . '/inc/report_layout.php';
require_once __DIR__ . '/models/Record.php';
require_once __DIR__ . '/models/ExecutiveMeeting.php';

$user = require_login();

$format     = strtolower(trim((string) input('format', 'csv')));
$type       = trim((string) input('type', '')) ?: null;
$category   = trim((string) input('category', '')) ?: null;
$from       = parse_date_input(input('from', ''));   
$to         = parse_date_input(input('to', ''));     

$rawYear  = input('academic_year') ?: input('year');
$emCtx    = em_resolve_filter_context($rawYear, input('em'));
$year     = $emCtx['year'];
$emFilter = $emCtx['em'];
[$from, $to] = em_intersect_period($emFilter, $from, $to, $year);

$status     = trim((string) input('status', '')) ?: null;
if (!in_array($status, ['Draft', 'Submitted', 'Approved', 'Rejected'], true)) {
    $status = null;
}

$department = user_department_scope($user, input('department'));

// Handle 'academic_record' or 'all' type alias
$isAllAcademic = ($type === null || $type === 'academic_record' || $type === 'all');
$queryType     = $isAllAcademic ? null : $type;

$eventMode = event_mode_normalize(input('event_mode'));
if ($eventMode !== null && $queryType !== null && !is_event_type($queryType)) {
    $eventMode = null;   // only events have a mode
}

$records = report_records($user, $department, $status, $queryType, $from, $to, $year, false, $eventMode, true);

$categories = record_categories();
if ($category !== null && isset($categories[$category])) {
    $records = array_values(array_filter($records, fn($r) => record_row_category($r) === $category));
}

// ---- Naming and header metadata ----------------------------------------
$scopeLabel = $department ? department_full_name($department) : 'ALL DEPARTMENTS';

$types = record_types();
if ($type && isset($types[$type])) {
    $typeLabel = $types[$type]['label'];
} elseif ($category && isset($categories[$category])) {
    $typeLabel = $categories[$category]['label'];
} else {
    $typeLabel = 'All Academic Records';
}

$today = date('d.m.Y');

if ($from && $to) {
    $periodLabel = date('d.m.Y', strtotime($from)) . ' to ' . date('d.m.Y', strtotime($to));
} elseif ($from) {
    $periodLabel = 'From ' . date('d.m.Y', strtotime($from));
} elseif ($to) {
    $periodLabel = 'Up to ' . date('d.m.Y', strtotime($to));
} else {
    $periodLabel = '';
}

$safeType  = preg_replace('/[^A-Za-z0-9\-]/', '_', $typeLabel);
$safeScope = preg_replace('/[^A-Za-z0-9\-]/', '_', $scopeLabel);
$fileStem  = 'ATTS_' . $safeType . '_' . $safeScope . '_' . date('Ymd');

$reportTitle = strtoupper($typeLabel);

// The columns, in order. Same for every format.
$columns = ['S.No', 'Record', 'Type', 'Faculty / Student', 'Department', 'Status', 'Date', 'Proof'];

// Event Mode column, added only when the export holds events. Proof stays last.
$hasEvents = (bool) array_filter($records, fn($r) => is_event_type($r['_type_key'] ?? ''));
if ($hasEvents) {
    array_splice($columns, 3, 0, ['Event Mode']);
}
if ($eventMode !== null) {
    $reportTitle .= ' - ' . strtoupper($eventMode) . ' EVENTS';
}

function csv_line($handle, array $fields): void
{
    $safeFields = array_map(function ($field) {
        $str = (string) $field;
        if (isset($str[0]) && in_array($str[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $str;
        }
        return $str;
    }, $fields);
    fputcsv($handle, $safeFields, ',', '"', '');
}

/** Build one row of values for a record. */
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

    global $hasEvents;
    $row = [
        $serial,
        $record['_title'],
        $record['_type_label'],
        $record['_person'],
        !empty($record['department']) ? department_full_name($record['department']) : '-',
        $record['status'],
        date('d/m/Y', strtotime($record['created_at'])),
        $proofVal,
    ];
    if ($hasEvents) {
        $mode = is_event_type($type) ? (event_mode_normalize($record['mode'] ?? '') ?? 'Not specified') : '—';
        array_splice($row, 3, 0, [$mode]);
    }
    return $row;
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
        csv_line($out, export_row($record, $serial++, 'csv'));
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
        $exportRows[] = export_row($r, $i + 1, 'excel');
    }
    $titleLine = $reportTitle . ($year ? ' (' . $year . ')' : '');
    $metaLines = [
        'MOHAMED SATHAK ENGINEERING COLLEGE',
        $titleLine,
        'Department: ' . $scopeLabel,
        'Report Date: ' . $today
    ];
    $sigCols = ['HOD' . ($scopeLabel !== 'ALL DEPARTMENTS' ? ' / ' . $scopeLabel : ''), 'DEAN / ACADEMICS', 'IQAC COORDINATOR', 'PRINCIPAL'];
    foreach (report_signoff_excel_rows($sigCols, count($columns)) as $sRow) {
        $exportRows[] = $sRow;
    }
    $xlsxData = class_exists('SimpleXlsxWriter')
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
            <?php foreach (export_row($record, $serial++, $format) as $i => $value): ?>
              <?php $isProof = ($i === count($columns) - 1); ?>
              <td<?= $i === 0 ? ' class="num"' : ($isProof ? ' class="c"' : '') ?>><?= $isProof ? $value : e($value) ?></td>
            <?php endforeach; ?>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>

<?php
report_signoff(report_signoff_columns($scopeLabel));
report_document_foot();