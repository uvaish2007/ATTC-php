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

// Every signed-in user may generate the report (the template itself is still
// Admin-only to edit — see report-template.php).
$user = require_login();

$format = strtolower(trim((string) input('format', 'word')));
if (!in_array($format, ['word', 'excel', 'pdf'], true)) {
    $format = 'word';
}

/*
 * Scope by role: oversight roles (Admin, Director) may pick any department or
 * see all; everyone else is pinned to their own department.
 */
$isOversight = in_array($user['role'], ['Admin', 'Director'], true);
$department  = $isOversight
    ? (trim((string) input('department')) ?: null)
    : ($user['department'] ?? null);

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

/*
 * Normalise a title for matching a template row to a department's target:
 * lower-cased, a leading "a. " sub-letter dropped, spacing around slashes and
 * runs of whitespace collapsed. So "a. BOOKS PUBLICATION" matches "BOOKS
 * PUBLICATION", and "SCOPUS / SCI" matches "SCOPUS/SCI".
 */
$norm = function ($s): string {
    $s = mb_strtolower(trim((string) $s));
    $s = preg_replace('/^[a-z]\.\s*/', '', $s);   // drop a leading sub-letter
    $s = preg_replace('/[^a-z0-9]+/', ' ', $s);    // any punctuation (/ & , -) -> space
    $s = preg_replace('/\s+/', ' ', $s);           // collapse whitespace
    return trim($s);
};

// Index the department's uploaded targets by their normalised metric text.
$byMetric = [];
if ($department !== null) {
    foreach (target_report_items($department) as $t) {
        $key = $norm($t['metric']);
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
require_once __DIR__ . '/models/Target.php';   // academic_years()
$deptHeading = $department !== null
    ? 'DEPARTMENT OF ' . strtoupper(department_full_name($department))
    : 'REPORT TEMPLATE';
[$durFrom, $durTo] = report_year_duration(academic_years()[0] ?? null);
$headingLines = [];
if ($department !== null && $durFrom !== '') {
    $headingLines[] = 'DETAILS OF TARGETS FIXED & ACHIEVED FOR THE DURATION FROM ' . $durFrom . ' TO ' . $durTo;
    $headingLines[] = '(Target Achieved Status – from ' . $durFrom . ' to ' . $today . ')';
}
report_letterhead($deptHeading, [
    ['Total Rows', (string) count($rows)],
    ['Report Date', $today],
], $headingLines);
?>

  <table class="grid">
    <colgroup>
      <?php foreach ($columns as $c): ?><col style="width:<?= (int) $c['width'] ?>%"><?php endforeach; ?>
    </colgroup>
    <thead>
      <?php
        // Columns like "Achieved (From ...)" and "Achieved (During ...)" are
        // grouped under a single "Achieved" heading spanning them, with their
        // "(From ...)" part shown on a second header row — exactly the proforma.
        $grp = [];
        foreach ($columns as $c) {
            $grp[] = preg_match('/^\s*(Achieved)\s*\((.+)\)\s*$/i', (string) $c['label'], $m)
                ? ['Achieved', trim($m[2])]
                : [null, (string) $c['label']];
        }
        $hasGroups = false;
        foreach ($grp as $x) { if ($x[0] !== null) { $hasGroups = true; break; } }
        $nCols = count($columns);
      ?>
      <tr>
        <?php for ($i = 0; $i < $nCols; $i++): ?>
          <?php [$g, $sub] = $grp[$i]; ?>
          <?php if ($g === null): ?>
            <th<?= $hasGroups ? ' rowspan="2"' : '' ?> style="text-align:<?= e($columns[$i]['align']) ?>;vertical-align:middle"><?= e($columns[$i]['label']) ?></th>
          <?php elseif ($i === 0 || $grp[$i - 1][0] !== $g): ?>
            <?php $run = 0; for ($j = $i; $j < $nCols && $grp[$j][0] === $g; $j++) { $run++; } ?>
            <th colspan="<?= $run ?>" style="text-align:center"><?= e($g) ?></th>
          <?php endif; ?>
        <?php endfor; ?>
      </tr>
      <?php if ($hasGroups): ?>
      <tr>
        <?php for ($i = 0; $i < $nCols; $i++): [$g, $sub] = $grp[$i]; if ($g !== null): ?>
          <th style="text-align:center"><?= e($sub) ?></th>
        <?php endif; endfor; ?>
      </tr>
      <?php endif; ?>
    </thead>
    <tbody>
      <?php if (empty($rows) || empty($columns)): ?>
        <tr><td colspan="<?= $span ?>" class="c">This template has no rows yet — add them in the Report Template builder.</td></tr>
      <?php else: ?>
        <?php
          // 1. Build the value matrix: label cells from the template, data cells
          //    auto-filled from the matching department target. A row is "met"
          //    when its target was reached (achieved >= a non-zero fixed target),
          //    which shades its cells green like the proforma.
          $matrix = [];
          $rowMet = [];
          foreach ($rows as $ri => $r) {
              $matchText = $matchKey ? $norm($r['cells'][$matchKey] ?? '') : '';
              $deptRow   = $matchText !== '' ? ($byMetric[$matchText] ?? null) : null;
              $rowMet[$ri] = $deptRow
                  && (int) ($deptRow['target_value'] ?? 0) > 0
                  && (int) ($deptRow['achieved_value'] ?? 0) >= (int) ($deptRow['target_value'] ?? 0);
              foreach ($columns as $c) {
                  if (($c['source'] ?? 'label') === 'data') {
                      $matrix[$ri][$c['col_key']] = $deptRow ? (string) ($deptRow[(string) $c['field']] ?? '') : '';
                  } else {
                      $matrix[$ri][$c['col_key']] = (string) ($r['cells'][$c['col_key']] ?? '');
                  }
              }
          }

          // 2. Group rows under one S.No: a numbered item plus its a./b./c.
          //    sub-rows. A new group begins wherever the S.No cell is non-empty.
          $rowCount = count($rows);
          $groups   = [];
          $g = 0;
          while ($g < $rowCount) {
              $h = $g + 1;
              while ($h < $rowCount && trim((string) ($matrix[$h]['sno'] ?? '')) === '') { $h++; }
              $groups[] = [$g, $h];
              $g = $h;
          }

          // 3. Within each group, merge each column downward: a value's cell spans
          //    over the blank cells that follow it, so "5" covers its two sub-rows
          //    and a lone "-" in Remarks covers its group — exactly the proforma.
          $cellRender = [];
          $cellSpan   = [];
          foreach ($groups as [$gs, $ge]) {
              foreach ($columns as $c) {
                  $k = $c['col_key'];
                  $head = -1;
                  for ($r = $gs; $r < $ge; $r++) {
                      $v = trim((string) ($matrix[$r][$k] ?? ''));
                      if ($v !== '') {
                          $head = $r; $cellRender[$r][$k] = true; $cellSpan[$r][$k] = 1;
                      } elseif ($head >= 0) {
                          $cellSpan[$head][$k]++; $cellRender[$r][$k] = false;
                      } else {
                          $cellRender[$r][$k] = true; $cellSpan[$r][$k] = 1;
                      }
                  }
              }
          }
        ?>
        <?php foreach ($rows as $ri => $r): ?>
          <tr>
            <?php foreach ($columns as $c): ?>
              <?php
                $k = $c['col_key'];
                if (!$cellRender[$ri][$k]) continue;
                $rs = $cellSpan[$ri][$k];
                // Green only the cells that belong solely to a met row; a merged
                // cell (rowspan) also covers sub-rows that may not be met.
                $met = $rowMet[$ri] && $rs === 1;
              ?>
              <td<?= $met ? ' class="met"' : '' ?> style="text-align:<?= e($c['align']) ?><?= $rs > 1 ? ';vertical-align:middle' : '' ?>"<?= $rs > 1 ? ' rowspan="' . $rs . '"' : '' ?>><?= e($matrix[$ri][$k]) ?></td>
            <?php endforeach; ?>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>

<?php
report_signoff(['HOD' . ($department ? ' / ' . $department : ''), 'IQAC COORDINATOR', 'PRINCIPAL']);
report_document_foot();
