<?php
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/report_layout.php';
require_once __DIR__ . '/models/ReportTemplate.php';
require_once __DIR__ . '/models/Target.php';

$user = require_role(['Admin', 'HoD', 'Director', 'Principal', 'Dean']);

$format = strtolower(trim((string) input('format', 'word')));
if (!in_array($format, ['word', 'excel', 'pdf'], true)) {
    $format = 'word';
}

<<<<<<< HEAD
/*
 * Scope by role: oversight roles (Admin, Director, Dean) may pick any department or
 * see all; everyone else is pinned to their own department.
 */
$isOversight = in_array($user['role'], ['Admin', 'Director', 'Principal', 'Dean'], true);
$rawDept     = trim((string) (input('department') ?: input('dept')));
$department  = $isOversight
    ? ($rawDept !== '' ? $rawDept : null)
    : ($user['department'] ?? null);

=======
$isOversight = user_can_choose_department($user);
$department  = user_department_scope($user, input('department'));

require_once __DIR__ . '/models/ExecutiveMeeting.php';

$rawYear  = input('academic_year') ?: input('year');
$emCtx    = em_resolve_filter_context($rawYear, input('em'));
$year     = $emCtx['year'];
$em       = $emCtx['em'];
$emWindow = $emCtx['window'];

>>>>>>> ac1da4e95ff4ae97513194a6ace61514656c41a6
$columns = template_columns();
$rows    = template_rows();
$today   = date('d.m.Y');
$span    = max(1, count($columns));

$matchKey = null;
foreach ($columns as $c) {
    if (($c['source'] ?? 'label') !== 'label') {
        continue;
    }
    if ($c['col_key'] === 'target') { $matchKey = 'target'; break; }
    if ($matchKey === null && $c['col_key'] !== 'sno') { $matchKey = $c['col_key']; }
}

$norm = function ($s): string {
    $s = mb_strtolower(trim((string) $s));
    $s = preg_replace('/^[a-z]\.\s*/', '', $s);   
    $s = preg_replace('/[^a-z0-9]+/', ' ', $s);    
    $s = preg_replace('/\s+/', ' ', $s);           
    return trim($s);
};

<<<<<<< HEAD
// Index one department's uploaded targets by their normalised metric text.
$buildByMetric = function (?string $dept) use ($norm): array {
=======
$buildByMetric = function (?string $dept) use ($norm, $year): array {
>>>>>>> ac1da4e95ff4ae97513194a6ace61514656c41a6
    $index = [];
    if ($dept !== null) {
        foreach (target_report_items($dept) as $t) {
            $key = $norm($t['metric']);
            if ($key !== '' && !isset($index[$key])) {
                $index[$key] = $t;
            }
        }
    }
    return $index;
};

require_once __DIR__ . '/models/Department.php';
$deptsToRender = $department !== null
    ? [$department]
    : array_map(fn($d) => $d['name'], departments_all());
if (empty($deptsToRender)) {
    $deptsToRender = [null];   
}

$deptLabel = $department ?: '—';
$fileStem  = 'report-' . trim(preg_replace('/[^A-Za-z0-9]+/', '-', $department ?: 'template'), '-') . '-' . date('Y-m-d');

if ($format === 'word') {
    header('Content-Type: application/msword; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $fileStem . '.doc"');
} elseif ($format === 'excel') {
    require_once __DIR__ . '/inc/xlsx_writer.php';

    $hdr = array_map(fn($c) => (string) $c['label'], $columns);
    $exportRows = [];
    $metaLines = [
        'MOHAMED SATHAK ENGINEERING COLLEGE',
        'EXECUTIVE MEETING REPORT - TARGETS FIXED & ACHIEVED',
        'Department: ' . ($department ? department_full_name($department) : 'ALL DEPARTMENTS'),
        'Report Date: ' . $today
    ];

    foreach ($deptsToRender as $dIndex => $dept) {
        if ($department === null && count($deptsToRender) > 1 && $dept !== null) {
            $exportRows[] = array_merge(['DEPARTMENT OF ' . strtoupper(department_full_name($dept))], array_fill(0, max(0, count($columns) - 1), ''));
        }
        $byMetric = $buildByMetric($dept);
        foreach ($rows as $ri => $r) {
            $matchText = $matchKey ? $norm($r['cells'][$matchKey] ?? '') : '';
            $deptRow   = $matchText !== '' ? ($byMetric[$matchText] ?? null) : null;
            $rowValues = [];
            foreach ($columns as $c) {
                if (($c['source'] ?? 'label') === 'data') {
                    $rowValues[] = $deptRow ? (string) ($deptRow[(string) $c['field']] ?? '') : '';
                } else {
                    $rowValues[] = (string) ($r['cells'][$c['col_key']] ?? '');
                }
            }
            $exportRows[] = $rowValues;
        }
    }

<<<<<<< HEAD
    $sigCols = ['HOD' . ($department ? ' / ' . department_full_name($department) : ''), 'DEAN / ACADEMICS', 'IQAC COORDINATOR', 'PRINCIPAL'];
    foreach (report_signoff_excel_rows($sigCols, count($hdr)) as $sRow) {
        $exportRows[] = $sRow;
    }

    $xlsxData = (class_exists('ZipArchive') && class_exists('SimpleXlsxWriter'))
        ? SimpleXlsxWriter::createXlsx($hdr, $exportRows, 'Targets Report', $metaLines)
=======
    $xlsxData = class_exists('SimpleXlsxWriter')
        ? SimpleXlsxWriter::createXlsx($hdr,
            array_merge($exportRows, report_signoff_rows(
                report_signoff_columns($department ? department_full_name($department) : null), count($hdr))),
            'Targets Report', $metaLines)
>>>>>>> ac1da4e95ff4ae97513194a6ace61514656c41a6
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
} else {
    header('Content-Type: text/html; charset=UTF-8');
}

report_document_head('Executive Meeting Report', 'landscape');
?>

<?php if ($format === 'pdf'): ?>
  <?php report_pdf_bar($reportDocTitle, [$deptLabel,
      $year !== null ? 'AY ' . $year : 'All academic years',
      number_format(count($rows)) . ' rows']); ?>
<?php endif; ?>

<?php
<<<<<<< HEAD
require_once __DIR__ . '/models/Target.php';   // academic_years()
[$durFrom, $durTo] = report_year_duration(academic_years()[0] ?? null);
=======
require_once __DIR__ . '/models/Target.php';   
if ($em !== 'all' && $emWindow !== null && empty($emWindow['empty'])) {
    $durFrom = date('d-m-Y', strtotime($emWindow['from']));
    $durTo   = date('d-m-Y', strtotime($emWindow['to']));
} else {
    [$durFrom, $durTo] = report_year_duration($year);
}
>>>>>>> ac1da4e95ff4ae97513194a6ace61514656c41a6

foreach ($deptsToRender as $dIndex => $dept):
    $byMetric    = $buildByMetric($dept);
    $deptHeading = $dept !== null
        ? 'DEPARTMENT OF ' . strtoupper(department_full_name($dept))
        : 'REPORT TEMPLATE';
    $headingLines = [];
    if ($dept !== null && $durFrom !== '') {
        $headingLines[] = 'DETAILS OF TARGETS FIXED & ACHIEVED FOR THE DURATION FROM ' . $durFrom . ' TO ' . $durTo;
        $headingLines[] = '(Target Achieved Status – from ' . $durFrom . ' to ' . $today . ')';
    }

    if ($dIndex > 0) {
        echo '<div style="page-break-before:always"></div>';
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

          $rowCount = count($rows);
          $groups   = [];
          $g = 0;
          while ($g < $rowCount) {
              $h = $g + 1;
              while ($h < $rowCount && trim((string) ($matrix[$h]['sno'] ?? '')) === '') { $h++; }
              $groups[] = [$g, $h];
              $g = $h;
          }

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
<<<<<<< HEAD
    report_signoff(['HOD' . ($dept ? ' / ' . department_full_name($dept) : ''), 'DEAN / ACADEMICS', 'IQAC COORDINATOR', 'PRINCIPAL']);
=======
    report_signoff(report_signoff_columns($dept ? department_full_name($dept) : null));
>>>>>>> ac1da4e95ff4ae97513194a6ace61514656c41a6
endforeach;
report_document_foot();
