<?php
/**
 * Template Report — the Admin-designed template (report_columns / report_rows)
 * rendered for one department, in the shared ATTS letterhead.
 *
 * The Admin builds only the STRUCTURE: the columns, and the label cells of each
 * row (S.No, Target / Details). The DATA columns (Fixed, Achieved, Coordinator,
 * Remarks) are filled here from what that department uploaded — each template
 * row is matched to the department's target with the same Target / Details, and
 * every data column pulls the target field it is mapped to.
 *
 *   ?department=CSE   which department's uploaded data fills the report
 *   ?format=word|excel|pdf
 *
 * Scope: a HoD only ever gets their own department; Admin and Director may pass
 * any department (omit it to preview the empty structure).
 */

require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/report_layout.php';
require_once __DIR__ . '/models/ReportTemplate.php';
require_once __DIR__ . '/models/Target.php';

$user = require_role(['Admin', 'HoD', 'Director']);

$format = strtolower(trim((string) input('format', 'word')));
if (!in_array($format, ['word', 'excel', 'pdf'], true)) {
    $format = 'word';
}

// A HoD is pinned to their own department; the oversight roles choose one.
$isHod      = $user['role'] === 'HoD';
$department = $isHod ? ($user['department'] ?? null) : (trim((string) input('department')) ?: null);

$columns = template_columns();
$rows    = template_rows();
$today   = date('d.m.Y');
$span    = max(1, count($columns));

/*
 * The label column whose value identifies a row (its Target / Details). Data
 * rows are matched to a department's target by comparing this against the
 * target's metric. Prefer the 'target' column; fall back to the first label
 * column that is not the serial number.
 */
$matchKey = null;
foreach ($columns as $c) {
    if (($c['source'] ?? 'label') !== 'label') {
        continue;
    }
    if ($c['col_key'] === 'target') { $matchKey = 'target'; break; }
    if ($matchKey === null && $c['col_key'] !== 'sno') { $matchKey = $c['col_key']; }
}

// Index the department's uploaded targets by their (normalised) metric text.
$byMetric = [];
if ($department !== null) {
    foreach (target_report_items($department) as $t) {
        $key = mb_strtolower(trim((string) $t['metric']));
        if ($key !== '' && !isset($byMetric[$key])) {
            $byMetric[$key] = $t;
        }
    }
}

$deptLabel = $department ?: '—';
$fileStem  = 'report-' . trim(preg_replace('/[^A-Za-z0-9]+/', '-', $department ?: 'template'), '-') . '-' . date('Y-m-d');

if ($format === 'word') {
    header('Content-Type: application/msword; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $fileStem . '.doc"');
} elseif ($format === 'excel') {
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $fileStem . '.xls"');
} else {
    header('Content-Type: text/html; charset=UTF-8');
}

report_document_head('Executive Meeting Report', 'landscape');
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
report_letterhead('Executive Meeting Report', [
    ['Department', $deptLabel],
    ['Total Rows', (string) count($rows)],
    ['Report Date', $today],
]);
?>

  <table class="grid">
    <colgroup>
      <?php foreach ($columns as $c): ?><col style="width:<?= (int) $c['width'] ?>%"><?php endforeach; ?>
    </colgroup>
    <thead>
      <tr>
        <?php foreach ($columns as $c): ?>
          <th style="text-align:<?= e($c['align']) ?>"><?= e($c['label']) ?></th>
        <?php endforeach; ?>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($rows) || empty($columns)): ?>
        <tr><td colspan="<?= $span ?>" class="c">This template has no rows yet — add them in the Report Template builder.</td></tr>
      <?php else: ?>
        <?php foreach ($rows as $r): ?>
          <?php
            // The department's target for this row, matched on Target / Details.
            $matchText = $matchKey ? mb_strtolower(trim((string) ($r['cells'][$matchKey] ?? ''))) : '';
            $deptRow   = $matchText !== '' ? ($byMetric[$matchText] ?? null) : null;
          ?>
          <tr>
            <?php foreach ($columns as $c): ?>
              <?php
                if (($c['source'] ?? 'label') === 'data') {
                    // Filled from the department's upload, never from the template.
                    $field = (string) ($c['field'] ?? '');
                    $val   = $deptRow ? (string) ($deptRow[$field] ?? '') : '';
                } else {
                    $val = (string) ($r['cells'][$c['col_key']] ?? '');
                }
              ?>
              <td style="text-align:<?= e($c['align']) ?>"><?= e($val) ?></td>
            <?php endforeach; ?>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>

<?php
report_signoff(['HOD' . ($department ? ' / ' . $department : ''), 'IQAC COORDINATOR', 'PRINCIPAL']);
report_document_foot();
