<?php
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/report_layout.php';
require_once __DIR__ . '/inc/record_specs.php';
require_once __DIR__ . '/models/Record.php';
require_once __DIR__ . '/models/Target.php';   

$user = require_login();

$type = trim((string) input('type'));
$spec = record_report_spec($type);
if ($spec === null) {
    http_response_code(404);
    exit('Unknown report type.');
}

$format = strtolower(trim((string) input('format', 'word')));
if (!in_array($format, ['word', 'excel', 'pdf'], true)) {
    $format = 'word';
}

$isOversight  = user_can_choose_department($user);
$department   = user_department_scope($user, input('department'));

$rawYear = input('academic_year') ?: input('year');
$emCtx   = em_resolve_filter_context($rawYear, input('em'));
$year    = $emCtx['year'];
$em      = $emCtx['em'];
$singleDept = $department !== null;

$status = trim((string) input('status')) ?: null;
if (!in_array($status, ['Draft', 'Submitted', 'Approved', 'Rejected'], true)) { $status = null; }
$from = parse_date_input((string) input('from'));
$to   = parse_date_input((string) input('to'));
[$from, $to] = em_intersect_period($em, $from, $to, $year);

$records = report_records($user, $isOversight ? $department : null, $status, $type, $from, $to, $year);

$columns = array_values(array_filter($spec['columns'], fn($c) => !($singleDept && $c[1] === 'department')));

$deptFullName = $singleDept ? department_full_name((string) $department) : null;
$deptLabel    = $singleDept ? strtoupper($deptFullName) : 'ALL DEPARTMENTS';
$today     = date('d.m.Y');
$span      = max(1, count($columns));

$slug     = preg_replace('/[^A-Za-z0-9]+/', '-', $type . '-' . ($department ?: 'all'));
$fileStem = trim($slug, '-') . '-' . date('Y-m-d');

if ($format === 'word') {
    header('Content-Type: application/msword; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $fileStem . '.doc"');
} elseif ($format === 'excel') {
    require_once __DIR__ . '/inc/xlsx_writer.php';

    $headers = array_map(fn($c) => (string) $c[0], $columns);
    $exportRows = [];
    $serial = 1;
    foreach ($records as $r) {
        $rowVal = [];
        foreach ($columns as [$label, $field]) {
            if ($field === '#') {
                $rowVal[] = (string) $serial;
            } elseif ($field === 'department') {
                $rowVal[] = department_full_name((string) ($r[$field] ?? ''));
            } elseif ($field === 'proof_file') {
                $pfile = trim((string)($r['proof_file'] ?? ''));
                if ($pfile !== '') {
                    $rowVal[] = [
                        'text' => 'View Proof',
                        'url'  => record_proof_url($type, (int)$r['id'], $pfile, false, true)
                    ];
                } else {
                    $rowVal[] = '—';
                }
            } else {
                $rowVal[] = (string) ($r[$field] ?? '');
            }
        }
        $exportRows[] = $rowVal;
        $serial++;
    }

    $titleLine = $spec['title'] . ($year !== null ? ' - DURING THE ACADEMIC YEAR ' . $year : ' - ALL ACADEMIC YEARS');
    $metaLines = [
        'MOHAMED SATHAK ENGINEERING COLLEGE',
        $titleLine,
        'Department: ' . ($singleDept ? $deptFullName : 'All departments'),
        'Report Date: ' . $today
    ];

    $xlsxData = class_exists('SimpleXlsxWriter')
        ? SimpleXlsxWriter::createXlsx($headers,
            array_merge($exportRows, report_signoff_rows(
                report_signoff_columns($singleDept ? $deptFullName : null), count($headers))),
            mb_substr($spec['title'], 0, 31), $metaLines)
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
} else {
    header('Content-Type: text/html; charset=UTF-8');
}

report_document_head($spec['title'], 'landscape');
?>

<?php if ($format === 'pdf'): ?>
  <?php report_pdf_bar($spec['title'], [$deptFullName ?? $deptLabel,
      $year !== null ? 'AY ' . $year : 'All academic years',
      number_format(count($records)) . ' records']); ?>
<?php endif; ?>

<?php
$reportTitle  = $spec['title'] . ($year !== null ? ' - DURING THE ACADEMIC YEAR ' . $year : ' - ALL ACADEMIC YEARS');
$headingLines = [];
if ($singleDept)               { $mainTitle = 'DEPARTMENT OF ' . $deptLabel; $headingLines[] = $reportTitle; }
else                           { $mainTitle = $reportTitle; }
if (!empty($spec['subtitle'])) { $headingLines[] = $spec['subtitle']; }

$meta = [['Department', $singleDept ? $deptFullName : 'All departments']];
if ($status !== null) { $meta[] = ['Status', $status]; }
if ($from || $to) {
    $fmt = fn(?string $d) => $d ? date('d.m.Y', strtotime($d)) : '…';
    $meta[] = ['Period', $fmt($from) . ' to ' . $fmt($to)];
}
$meta[] = ['Total Records', (string) count($records)];
$meta[] = ['Report Date', $today];
report_letterhead($mainTitle, $meta, $headingLines);
?>

  <table class="grid">
    <thead>
      <tr>
        <?php foreach ($columns as [$label, $field]): ?>
          <th><?= e($label) ?></th>
        <?php endforeach; ?>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($records)): ?>
        <tr><td colspan="<?= $span ?>" class="c">No records for this scope.</td></tr>
      <?php else: ?>
        <?php $serial = 1; ?>
        <?php foreach ($records as $r): ?>
          <tr>
            <?php foreach ($columns as [$label, $field]): ?>
              <?php
                $isHtml = false;
                if ($field === '#') {
                    $val = (string) $serial;
                } elseif ($field === 'department') {
                    $val = department_full_name((string) ($r[$field] ?? ''));
                } elseif ($field === 'proof_file') {
                    $meta = record_proof_meta($type, (int)($r['id'] ?? 0), $r['proof_file'] ?? null);
                    if ($meta) {
                        $isHtml = true;
                        if ($meta['is_image'] && !empty($meta['base64_data'])) {
                            $val = '<div style="text-align:center;">'
                                 . '<a href="' . e($meta['view_url']) . '" target="_blank" style="text-decoration:none;">'
                                 . '<img src="' . $meta['base64_data'] . '" alt="Proof thumbnail" style="max-width:90px;max-height:60px;object-fit:contain;border:1px solid #d1d5db;border-radius:3px;display:block;margin:0 auto 3px auto;">'
                                 . '<div style="font-size:8pt;color:#0044cc;text-decoration:underline;word-break:break-all;">' . e($meta['filename']) . '</div>'
                                 . '<span style="font-size:7.5pt;color:#555;">(Click to view)</span>'
                                 . '</a>'
                                 . '</div>';
                        } else {
                            $extLabel = $meta['ext'] ? ' (' . strtoupper($meta['ext']) . ')' : '';
                            $val = '<div style="text-align:center;">'
                                 . '<a href="' . e($meta['view_url']) . '" target="_blank" style="color:#0044cc;font-weight:600;text-decoration:underline;font-size:9pt;word-break:break-all;">'
                                 . 'View Proof' . e($extLabel)
                                 . '</a>'
                                 . '<div style="font-size:7.5pt;color:#666;margin-top:2px;word-break:break-all;">' . e($meta['filename']) . '</div>'
                                 . '</div>';
                        }
                    } else {
                        $val = '—';
                    }
                } else {
                    $val = (string) ($r[$field] ?? '');
                }
              ?>
              <td<?= $field === '#' ? ' class="num"' : ($field === 'proof_file' ? ' class="c"' : '') ?>><?= $isHtml ? $val : e($val) ?></td>
            <?php endforeach; ?>
          </tr>
          <?php $serial++; ?>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>

<?php
report_signoff(report_signoff_columns($singleDept ? $deptFullName : null));
report_document_foot();
