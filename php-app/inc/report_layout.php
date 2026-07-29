<?php
/**
 * Shared presentation for every downloadable report.
 *
 * One letterhead, one table style, one signature block — so a records export
 * and a target meeting report come out looking like the same institution's
 * paperwork. Both export.php and meeting-report.php build their Word/Excel
 * output through these helpers; only the columns and the rows differ.
 *
 * The output is plain HTML sent with a Word or Excel content type (set by the
 * caller). Word and Excel both open an HTML table and keep the layout, so no
 * library is needed.
 */

require_once __DIR__ . '/helpers.php';   // for e()

/**
 * The institution name — used as the image alt text and as a text fallback if
 * the banner image is ever missing.
 */
const REPORT_INSTITUTION = 'Mohamed Sathak Engineering College';

/**
 * The college letterhead banner as a base64 data URI, so the downloaded Word /
 * PDF is self-contained (no external image to fetch). Read once per request.
 */
function report_banner_datauri(): string
{
    static $uri = null;
    if ($uri !== null) {
        return $uri;
    }
    $dir = dirname(__DIR__) . '/assets/img/';
    // Prefer the compact JPEG (small base64 loads reliably in Word); fall back
    // to PNG if that is what is present.
    foreach (['letterhead.jpg' => 'image/jpeg', 'letterhead.png' => 'image/png'] as $file => $mime) {
        if (is_file($dir . $file)) {
            $uri = 'data:' . $mime . ';base64,' . base64_encode((string) file_get_contents($dir . $file));
            return $uri;
        }
    }
    $uri = '';
    return $uri;
}

/** Expand a department code to its full name for the report heading. */
function department_full_name(string $dept): string
{
    $map = [
        'CSE'     => 'Computer Science and Engineering',
        'CSBS'    => 'Computer Science and Business Systems',
        'AI & DS' => 'Artificial Intelligence and Data Science',
        'ECE'     => 'Electronics and Communication Engineering',
        'EEE'     => 'Electrical and Electronics Engineering',
        'MECH'    => 'Mechanical Engineering',
        'CIVIL'   => 'Civil Engineering',
        'IT'      => 'Information Technology',
        'Agri'    => 'Agriculture Engineering',
    ];
    return $map[$dept] ?? $dept;
}

/**
 * The duration an academic year spans, as ['01.07.YYYY', '30.06.YYYY'].
 * "2025-26" -> ['01.07.2025', '30.06.2026'].
 */
function report_year_duration(?string $year): array
{
    if ($year && preg_match('/^(\d{4})-\d{2}$/', $year, $m)) {
        $start = (int) $m[1];
        return ['01.07.' . $start, '30.06.' . ($start + 1)];
    }
    return ['', ''];
}

/**
 * Open the report document: <html><head> with the shared style, then <body>.
 *
 * $orientation is 'portrait' (a plain record list) or 'landscape' (wide tables
 * with long remarks, e.g. the meeting report).
 */
function report_document_head(string $docTitle, string $orientation = 'portrait'): void
{
    $size = $orientation === 'landscape' ? 'A4 landscape' : 'A4';
    ?>
<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:w="urn:schemas-microsoft-com:office:word">
<head>
  <meta charset="UTF-8">
  <title><?= e($docTitle) ?></title>
  <!--[if gte mso 9]><xml><w:WordDocument><w:View>Print</w:View>
    <w:Zoom>100</w:Zoom><w:DoNotOptimizeForBrowser/></w:WordDocument></xml><![endif]-->
  <style>
    @page { size: <?= $size ?>; margin: 1.4cm 1.2cm; }
    body   { font-family: 'Times New Roman', serif; font-size: 11pt; color:#000; }

    /* ---- Letterhead ---- */
    .rpt-head    { text-align:center; }
    .rpt-banner  { width:100%; max-width:100%; height:auto; display:block; margin:0 auto 4px; }
    .rpt-head .inst  { font-size: 15pt; font-weight: bold; letter-spacing:.5px; }
    .rpt-head .title { font-size: 13pt; font-weight: bold; margin-top: 6px; text-transform: uppercase; }
    .rpt-head .subtitle { font-size: 12pt; font-weight: bold; margin-top: 3px; }
    .rpt-meta  { width:100%; margin-top:10px; font-size:10.5pt; }
    .rpt-meta td { padding:1px 0; }
    .rpt-meta .r { text-align:right; }

    /* ---- The data grid ---- */
    table.grid { border-collapse: collapse; width: 100%; margin-top: 10px; }
    table.grid th, table.grid td {
      border: 1px solid #000; padding: 4px 6px; font-size: 10pt; vertical-align: top;
    }
    table.grid th { background: #D9D9D9; text-align: center; font-weight: bold; }
    table.grid .c   { text-align:center; }
    table.grid .num { text-align:center; }
    /* A completed (achieved) target — shaded green like the proforma. */
    table.grid td.met { background: #1E7E34; color: #ffffff; font-weight: bold; }
    .target-name { font-weight: bold; }
    .muted { color:#333; font-size: 9pt; }

    /* ---- Signatures ---- */
    .rpt-sign { width:100%; margin-top: 40px; }
    .rpt-sign td { text-align:center; font-weight:bold; font-size:10.5pt; border:0; padding-top:24px; }
  </style>
</head>
<body>
<?php
}

/**
 * The letterhead: institution, IQAC line, the report's own title, then a
 * two-column strip of meta facts.
 *
 * $meta is a list of [label, value] pairs; they are laid out two per row, the
 * left one left-aligned and the right one right-aligned, so an odd number ends
 * with a single left-aligned fact.
 */
function report_letterhead(string $title, array $meta = [], array $headingLines = []): void
{
    $banner = report_banner_datauri();
    ?>
  <div class="rpt-head">
    <?php if ($banner !== ''): ?>
      <img class="rpt-banner" src="<?= $banner ?>" alt="<?= e(REPORT_INSTITUTION) ?>">
    <?php else: ?>
      <div class="inst"><?= e(REPORT_INSTITUTION) ?></div>
    <?php endif; ?>
    <div class="title"><?= e($title) ?></div>
    <?php foreach ($headingLines as $line): ?>
      <div class="subtitle"><?= e($line) ?></div>
    <?php endforeach; ?>
  </div>
    <?php if ($meta): ?>
  <table class="rpt-meta">
    <?php for ($i = 0; $i < count($meta); $i += 2): ?>
      <tr>
        <td><strong><?= e($meta[$i][0]) ?>:</strong> <?= e($meta[$i][1]) ?></td>
        <td class="r">
          <?php if (isset($meta[$i + 1])): ?>
            <strong><?= e($meta[$i + 1][0]) ?>:</strong> <?= e($meta[$i + 1][1]) ?>
          <?php endif; ?>
        </td>
      </tr>
    <?php endfor; ?>
  </table>
    <?php endif; ?>
<?php
}

/** The signature line. Defaults to the standard IQAC sign-off. */
function report_signoff(array $columns = ['HOD', 'IQAC COORDINATOR', 'PRINCIPAL']): void
{
    ?>
  <table class="rpt-sign">
    <tr>
      <?php foreach ($columns as $column): ?>
        <td><?= e($column) ?></td>
      <?php endforeach; ?>
    </tr>
  </table>
<?php
}

/** Close the document. */
function report_document_foot(): void
{
    echo "\n</body>\n</html>";
}
