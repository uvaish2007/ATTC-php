<?php
/**
 * Executive Meeting Report — the uploaded IQAC proforma, reproduced exactly.
 *
 * One layout, three ways out, all from the SAME HTML body so the format never
 * drifts between them:
 *     ?format=word   → a .doc download   (default)
 *     ?format=excel  → a .xls download
 *     ?format=pdf    → opens in the browser with a Print / Save-as-PDF button
 *
 * The table is the seven-column proforma:
 *
 *   S.No | Target / Details | Fixed | Achieved (two periods) | Progress / Remarks | Coordinator
 *
 * built from that department's rows in `targets`, in proforma order (see
 * target_report_items). Rows carry their own S.No and a./b./c. sub-label, and
 * Fixed/Achieved are shown as text ("UGC – 18", "10 Lakhs", "-"). A target that
 * was added through the form instead of the proforma has no *_text values, so
 * it falls back to its plain numbers and an auto S.No.
 *
 * Scope is enforced like the Targets page: a HoD only ever gets their own
 * department; Admin and Director may pass ?department= (or omit for all).
 */

require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/report_layout.php';
require_once __DIR__ . '/models/Target.php';
require_once __DIR__ . '/models/Setting.php';

$user = require_role(['Admin', 'HoD', 'Director']);

$format = strtolower(trim((string) input('format', 'word')));
if (!in_array($format, ['word', 'excel', 'pdf'], true)) {
    $format = 'word';
}

/*
 * Scope by role:
 *   Admin     may filter to any department (or all).
 *   HoD       is pinned to their own department.
 *   Director  only ever sees the overall (all-department) report — they cannot
 *             narrow to one department.
 */
$isHod      = $user['role'] === 'HoD';
$isDirector = $user['role'] === 'Director';

if ($isHod) {
    $department = $user['department'] ?? null;
} elseif ($isDirector) {
    $department = null;                                   // overall only
} else {
    $department = trim((string) input('department')) ?: null;
}

$year   = trim((string) input('year')) ?: null;
$metric = trim((string) input('metric')) ?: null;        // narrow to matching targets

$items = target_report_items($department, $year);

// Metric filter: keep rows whose target text contains it (and drop group
// headers that end up with no children showing).
if ($metric !== null) {
    $items = array_values(array_filter($items, fn($t) => mb_stripos((string) $t['metric'], $metric) !== false));
}

// The template an Admin has chosen for everyone: 'full' shows both Achieved
// periods, 'compact' collapses them into one Achieved column.
$template = active_report_template();
$twoPeriods = $template === 'full';

$deptLabel = $department ?: 'ALL DEPARTMENTS';
$yearLabel = $year ?: 'All academic years';
$today     = date('d.m.Y');

// The two achieved periods printed under the "Achieved" heading. They are
// meeting-cycle dates, so they can be overridden per report via the query
// string; the defaults match the uploaded proforma.
$period1 = trim((string) input('p1')) ?: 'From 01.07.25 to 11.01.26';
$period2 = trim((string) input('p2')) ?: 'During 12.01.26 to 11.02.26';

$slug     = preg_replace('/[^A-Za-z0-9]+/', '-', $department ?: 'all-departments');
$fileStem = 'meeting-report-' . trim($slug, '-') . '-' . date('Y-m-d');

if ($format === 'word') {
    header('Content-Type: application/msword; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $fileStem . '.doc"');
} elseif ($format === 'excel') {
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $fileStem . '.xls"');
} else {
    header('Content-Type: text/html; charset=UTF-8');   // pdf: view then print
}

report_document_head('Executive Meeting Report', 'landscape');
?>

<?php if ($format === 'pdf'): ?>
  <!-- On-screen only; hidden when actually printing or saved to PDF -->
  <div class="pdf-bar" style="position:sticky;top:0;background:#1A2547;color:#fff;padding:10px 16px;
       display:flex;align-items:center;justify-content:space-between;font-family:Arial,sans-serif;margin:-1.4cm -1.2cm 16px">
    <span style="font-size:13px">Use your browser's print dialog and choose <strong>Save as PDF</strong>.</span>
    <button onclick="window.print()" style="background:#FF4F01;color:#fff;border:0;border-radius:6px;
       padding:8px 16px;font-size:13px;font-weight:600;cursor:pointer">Print / Save as PDF</button>
  </div>
  <style>@media print { .pdf-bar { display:none !important; } }</style>
<?php endif; ?>

<?php
report_letterhead('Executive Meeting Report', [
    ['Department', $deptLabel],
    ['Academic Year', $yearLabel],
    ['Total Items', (string) count($items)],
    ['Report Date', $today],
]);
?>

  <?php $cols = $twoPeriods ? 7 : 6; ?>
  <table class="grid">
    <colgroup>
      <?php if ($twoPeriods): ?>
        <col style="width:4%"><col style="width:30%"><col style="width:9%">
        <col style="width:11%"><col style="width:11%"><col style="width:23%"><col style="width:12%">
      <?php else: ?>
        <col style="width:5%"><col style="width:34%"><col style="width:11%">
        <col style="width:13%"><col style="width:25%"><col style="width:12%">
      <?php endif; ?>
    </colgroup>
    <thead>
      <tr>
        <th rowspan="2">S.No</th>
        <th rowspan="2">Target / Details</th>
        <th rowspan="2">Fixed</th>
        <?php if ($twoPeriods): ?>
          <th colspan="2">Achieved</th>
        <?php else: ?>
          <th rowspan="2">Achieved</th>
        <?php endif; ?>
        <th rowspan="2">Progress / Remarks</th>
        <th rowspan="2">Coordinator</th>
      </tr>
      <tr>
        <?php if ($twoPeriods): ?>
          <th style="font-weight:normal;font-size:9pt"><?= e($period1) ?></th>
          <th style="font-weight:normal;font-size:9pt"><?= e($period2) ?></th>
        <?php endif; ?>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($items)): ?>
        <tr><td colspan="<?= $cols ?>" class="c">No targets set for this scope.</td></tr>
      <?php else: ?>
        <?php $auto = 1; ?>
        <?php foreach ($items as $t): ?>
          <?php
            // A proforma row carries its own S.No, sub-label and text values;
            // a form-added target has none, so it falls back to plain numbers.
            $isProforma = $t['fixed_text'] !== null || $t['achieved_p1'] !== null || $t['serial_no'] !== null;

            if ($isProforma) {
                $serial = (string) ($t['serial_no'] ?? '');
                $sub    = (string) ($t['sub_label'] ?? '');
                $fixed  = (string) ($t['fixed_text'] ?? '');
                $ap1    = (string) ($t['achieved_p1'] ?? '');
                $ap2    = (string) ($t['achieved_p2'] ?? '');
            } else {
                $serial = (string) $auto++;
                $sub    = '';
                $fixed  = (int) $t['target_value']   > 0 ? (string) $t['target_value']   : '-';
                $ap1    = (int) $t['achieved_value'] > 0 ? (string) $t['achieved_value'] : '-';
                $ap2    = '';
            }

            // A group header (e.g. "EVENTS PARTICIPATION IN SPORTS") carries a
            // number and a title but no values; its a./b./c. sub-rows follow.
            $isHeader = $isProforma && $fixed === '' && $ap1 === '' && $ap2 === '';
          ?>
          <?php
            // Compact template shows a single Achieved cell: the two periods
            // joined (or just the one that carries a value).
            $achievedOne = trim($ap1 . ($ap2 !== '' && $ap2 !== '-' && $ap2 !== $ap1 ? '  ' . $ap2 : ''));
            if ($achievedOne === '') { $achievedOne = $ap1; }
          ?>
          <tr>
            <td class="num"><?= e($serial) ?></td>
            <td<?= $isHeader ? ' class="target-name"' : '' ?>>
              <?php if ($sub !== ''): ?><strong><?= e($sub) ?></strong> <?php endif; ?><?= e($t['metric']) ?>
            </td>
            <td class="num"><?= e($fixed) ?></td>
            <?php if ($twoPeriods): ?>
              <td class="num"><?= e($ap1) ?></td>
              <td class="num"><?= e($ap2) ?></td>
            <?php else: ?>
              <td class="num"><?= e($achievedOne) ?></td>
            <?php endif; ?>
            <td><?= e($t['remarks'] ?? '') ?></td>
            <td><?= e($t['coordinator'] ?? '') ?></td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>

<?php
report_signoff(['HOD' . ($deptLabel !== 'ALL DEPARTMENTS' ? ' / ' . $deptLabel : ''), 'IQAC COORDINATOR', 'PRINCIPAL']);
report_document_foot();
