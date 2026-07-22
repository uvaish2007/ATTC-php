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
require_once __DIR__ . '/models/Record.php';

$user = require_login();

// ---- Read the same filters the Reports page uses ------------------------
$format     = strtolower(trim((string) input('format', 'csv')));
$department = trim((string) input('department', '')) ?: null;
$status     = trim((string) input('status', '')) ?: null;
$type       = trim((string) input('type', '')) ?: null;

if (!in_array($format, ['csv', 'excel', 'word'], true)) {
    $format = 'csv';
}

// ---- Get the records (role scope is applied inside) ---------------------
$records = report_records($user, $department, $status, $type);

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

    csv_line($out, ['INTERNAL QUALITY ASSURANCE CELL (IQAC)']);
    csv_line($out, [$reportTitle . ' - ' . $scopeLabel]);
    csv_line($out, ['Date: ' . $today]);
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
?>
<html>
<head>
  <meta charset="UTF-8">
  <style>
    body  { font-family: Calibri, Arial, sans-serif; font-size: 11pt; }
    h2, h3, .date { text-align: center; margin: 2px 0; }
    h2    { font-size: 14pt; }
    h3    { font-size: 12pt; }
    .date { font-size: 10pt; margin-bottom: 12px; }
    table { border-collapse: collapse; width: 100%; }
    th, td { border: 1px solid #333; padding: 5px 7px; font-size: 10pt; vertical-align: top; }
    th    { background: #E8EAF0; text-align: center; }
    .sign { margin-top: 40px; width: 100%; }
    .sign td { border: 0; text-align: center; font-weight: bold; font-size: 10pt; }
  </style>
</head>
<body>

  <h2>INTERNAL QUALITY ASSURANCE CELL (IQAC)</h2>
  <h3><?= e($reportTitle) ?> &ndash; <?= e($scopeLabel) ?></h3>
  <div class="date">Date: <?= e($today) ?></div>

  <table>
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
          <td colspan="<?= count($columns) ?>" style="text-align:center">No records found.</td>
        </tr>
      <?php else: ?>
        <?php $serial = 1; ?>
        <?php foreach ($records as $record): ?>
          <tr>
            <?php foreach (export_row($record, $serial++) as $value): ?>
              <td><?= e($value) ?></td>
            <?php endforeach; ?>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>

  <!-- The signature line the printed IQAC proforma carries -->
  <table class="sign">
    <tr>
      <td>HOD<?= $scopeLabel !== 'ALL DEPARTMENTS' ? ' / ' . e($scopeLabel) : '' ?></td>
      <td>DEAN / ACADEMICS</td>
      <td>PRINCIPAL</td>
    </tr>
  </table>

</body>
</html>
