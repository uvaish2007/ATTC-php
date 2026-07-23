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

// ---- Read the same filters the Reports page uses ------------------------
$format     = strtolower(trim((string) input('format', 'csv')));
$department = trim((string) input('department', '')) ?: null;
$status     = trim((string) input('status', '')) ?: null;
$type       = trim((string) input('type', '')) ?: null;
$from       = trim((string) input('from', '')) ?: null;   // period start (YYYY-MM-DD)
$to         = trim((string) input('to', '')) ?: null;     // period end

if (!in_array($format, ['csv', 'excel', 'word'], true)) {
    $format = 'csv';
}

// A Director's report is always the whole institution — never one department.
if ($user['role'] === 'Director') {
    $department = null;
}

// ---- Get the records (role scope is applied inside) ---------------------
$records = report_records($user, $department, $status, $type, $from, $to);

// ---- Things that appear in the report heading ---------------------------
$isOversight = in_array($user['role'], ['Admin', 'Director'], true);
$scopeLabel  = $isOversight
    ? ($department ?: 'ALL DEPARTMENTS')
    : ($user['department'] ?: 'ALL DEPARTMENTS');

$reportTitle = 'ACADEMIC RECORDS';
if ($type) {
    $types = record_types();
    $reportTitle = strtoupper($types[$type]['label'] ?? 'ACADEMIC RECORDS');
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
$columns = ['S.No', 'Record', 'Type', 'Faculty / Student', 'Department', 'Status', 'Date'];

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

/** Build one row of plain values for a record. */
function export_row(array $record, int $serial): array
{
    return [
        $serial,
        $record['_title'],
        $record['_type_label'],
        $record['_person'],
        $record['department'] ?? '-',
        $record['status'],
        date('d/m/Y', strtotime($record['created_at'])),
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
        csv_line($out, export_row($record, $serial++));
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
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $fileStem . '.xls"');
} else {
    header('Content-Type: application/msword; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $fileStem . '.doc"');
}

// Same letterhead, grid and sign-off as every other report (inc/report_layout).
$meta = [['Department', $scopeLabel]];
if ($periodLabel) {
    $meta[] = ['Period', $periodLabel];
}
$meta[] = ['Total Records', (string) count($records)];
$meta[] = ['Report Date', $today];

report_document_head($reportTitle . ' Report');
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
report_signoff(['HOD' . ($scopeLabel !== 'ALL DEPARTMENTS' ? ' / ' . $scopeLabel : ''), 'IQAC COORDINATOR', 'PRINCIPAL']);
report_document_foot();
