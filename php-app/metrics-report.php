<?php
/**
 * Metrics Report — a per-metric summary of the academic records.
 *
 * One row per record type (Journals, Patents, FDP …) with how many have been
 * submitted and where they sit in review (Approved / Pending / Rejected / Draft),
 * for the chosen scope and period. Built for a HoD's "metrics report" and for
 * the Director's clear, institution-wide academic overview.
 *
 * Same three outputs as the meeting report — ?format=word|excel|pdf — from one
 * HTML body, with the shared letterhead and sign-off, so it matches every other
 * report. Scope is role-enforced inside report_records():
 *     Admin    → any department (or all)
 *     HoD      → own department
 *     Director → whole institution (overall only)
 */

require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/report_layout.php';
require_once __DIR__ . '/models/Record.php';

$user = require_role(['Admin', 'HoD', 'Director']);

$format = strtolower(trim((string) input('format', 'word')));
if (!in_array($format, ['word', 'excel', 'pdf'], true)) {
    $format = 'word';
}

$department = trim((string) input('department')) ?: null;   // honoured only for Admin
$from       = trim((string) input('from')) ?: null;
$to         = trim((string) input('to')) ?: null;

// report_records applies the role scope (Director → all, HoD → own dept).
$records = report_records($user, $department, null, null, $from, $to);

// The label shown on the report reflects the scope actually applied.
if ($user['role'] === 'HoD') {
    $deptLabel = $user['department'] ?: 'ALL DEPARTMENTS';
} else {
    $deptLabel = $department ?: 'ALL DEPARTMENTS';   // Admin/Director may narrow
}

// ---- Aggregate: one row per record type, counted by review status ----------
$statuses = ['Approved', 'Submitted', 'Rejected', 'Draft'];
$rows     = [];
foreach (record_types() as $t) {
    $rows[$t['label']] = array_fill_keys($statuses, 0) + ['total' => 0];
}
foreach ($records as $r) {
    $label  = $r['_type_label'];
    $status = in_array($r['status'], $statuses, true) ? $r['status'] : 'Draft';
    $rows[$label][$status]++;
    $rows[$label]['total']++;
}

$grand = array_fill_keys($statuses, 0) + ['total' => 0];
foreach ($rows as $r) {
    foreach ($grand as $k => $_) {
        $grand[$k] += $r[$k];
    }
}

$today = date('d.m.Y');
$periodLabel = null;
if ($from || $to) {
    $fmt = fn(?string $d) => $d ? date('d.m.Y', strtotime($d)) : '…';
    $periodLabel = 'From ' . $fmt($from) . ' to ' . $fmt($to);
}

$slug     = preg_replace('/[^A-Za-z0-9]+/', '-', $deptLabel);
$fileStem = 'metrics-report-' . trim(strtolower($slug), '-') . '-' . date('Y-m-d');

if ($format === 'word') {
    header('Content-Type: application/msword; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $fileStem . '.doc"');
} elseif ($format === 'excel') {
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $fileStem . '.xls"');
} else {
    header('Content-Type: text/html; charset=UTF-8');
}

$meta = [['Department', $deptLabel]];
if ($periodLabel) {
    $meta[] = ['Period', $periodLabel];
}
$meta[] = ['Total Records', (string) $grand['total']];
$meta[] = ['Report Date', $today];

report_document_head('Metrics Report');
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

<?php report_letterhead('Metrics Report', $meta); ?>

  <table class="grid">
    <colgroup><col style="width:6%"><col style="width:40%"><col><col><col><col><col></colgroup>
    <thead>
      <tr>
        <th>S.No</th>
        <th>Metric</th>
        <th>Total</th>
        <th>Approved</th>
        <th>Pending</th>
        <th>Rejected</th>
        <th>Draft</th>
      </tr>
    </thead>
    <tbody>
      <?php $i = 1; ?>
      <?php foreach ($rows as $label => $c): ?>
        <tr>
          <td class="num"><?= $i++ ?></td>
          <td><?= e($label) ?></td>
          <td class="num"><?= (int) $c['total'] ?></td>
          <td class="num"><?= (int) $c['Approved'] ?></td>
          <td class="num"><?= (int) $c['Submitted'] ?></td>
          <td class="num"><?= (int) $c['Rejected'] ?></td>
          <td class="num"><?= (int) $c['Draft'] ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr style="font-weight:bold;background:#EEF1F6">
        <td class="num"></td>
        <td>TOTAL</td>
        <td class="num"><?= (int) $grand['total'] ?></td>
        <td class="num"><?= (int) $grand['Approved'] ?></td>
        <td class="num"><?= (int) $grand['Submitted'] ?></td>
        <td class="num"><?= (int) $grand['Rejected'] ?></td>
        <td class="num"><?= (int) $grand['Draft'] ?></td>
      </tr>
    </tfoot>
  </table>

<?php
report_signoff(['HOD' . ($deptLabel !== 'ALL DEPARTMENTS' ? ' / ' . $deptLabel : ''), 'IQAC COORDINATOR', 'PRINCIPAL']);
report_document_foot();
