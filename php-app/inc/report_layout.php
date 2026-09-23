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

/**
 * Expand a department code/short name to its full name, for every report
 * heading, meta line, "Dept" column and sign-off across the PDF/Excel/Word
 * downloads. Matches regardless of spacing/punctuation/case, so "AI&ML",
 * "AI & ML" and "aiml" all resolve the same way, and falls back to whatever
 * an Admin has set up in Manage Departments before giving up and returning
 * the value unchanged.
 */
function department_full_name(?string $dept): string
{
    $dept = trim((string) $dept);
    if ($dept === '' || strcasecmp($dept, 'ALL DEPARTMENTS') === 0 || strcasecmp($dept, 'All departments') === 0) {
        return $dept;
    }

    static $map = [
        'CSE'           => 'Computer Science and Engineering',
        'CSBS'          => 'Computer Science and Business Systems',
        'AIDS'          => 'Artificial Intelligence and Data Science',
        'ECE'           => 'Electronics and Communication Engineering',
        'EEE'           => 'Electrical and Electronics Engineering',
        'MECH'          => 'Mechanical Engineering',
        'CIVIL'         => 'Civil Engineering',
        'IT'            => 'Information Technology',
        'AGRI'          => 'Agriculture Engineering',
        'AERO'          => 'Aeronautical Engineering',
        'MARINE'        => 'Marine Engineering',
        'AIML'          => 'Artificial Intelligence and Machine Learning',
        'CYBER'         => 'Cyber Security',
        'CYBERSECURITY' => 'Cyber Security',
        'CHEM'          => 'Chemical Engineering',
        'ARCH'          => 'Architecture',
        'MCA'           => 'Master of Computer Applications',
        'MBA'           => 'Master of Business Administration',
    ];

    $key = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $dept));
    if (isset($map[$key])) {
        return $map[$key];
    }

    // Not a known abbreviation — check the Admin-managed department list, in
    // case it already carries a fuller name than either the code or what was
    // stored on the record.
    require_once __DIR__ . '/../models/Department.php';
    foreach (departments_all() as $d) {
        if (strcasecmp($d['code'], $dept) === 0 || strcasecmp($d['name'], $dept) === 0) {
            return mb_strlen($d['name']) >= mb_strlen($d['code']) ? $d['name'] : $d['code'];
        }
    }

    return $dept;
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
    $GLOBALS['REPORT_ORIENTATION'] = $orientation;
    $size = $orientation === 'landscape' ? 'A4 landscape' : 'A4';
    $msoSize = $orientation === 'landscape' ? '841.9pt 595.3pt' : '595.3pt 841.9pt';
    $msoOrientation = $orientation === 'landscape' ? 'landscape' : 'portrait';
    ?>
<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:w="urn:schemas-microsoft-com:office:word">
<head>
  <meta charset="UTF-8">
  <title><?= e($docTitle) ?></title>
  <!--[if gte mso 9]><xml><w:WordDocument><w:View>Print</w:View>
    <w:Zoom>100</w:Zoom><w:DoNotOptimizeForBrowser/></w:WordDocument></xml><![endif]-->
  <style>
    @page { size: <?= $size ?>; margin: 1.4cm 1.2cm; }
    @page WordSection1 { size: <?= $msoSize ?>; mso-page-orientation: <?= $msoOrientation ?>; margin: 1.4cm 1.2cm; }
    div.WordSection1 { page: WordSection1; }
    /* Force background colours (e.g. the green "achieved" cells) to actually
       print — browsers drop them by default, which turned white-on-green data
       cells into invisible white-on-white. */
    html, body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    body   { font-family: 'Times New Roman', serif; font-size: 11pt; color:#000; }

    /* ---- Letterhead ---- */
    .rpt-head    { text-align:center; }
    .rpt-banner-wrap { width:100%; max-width:100%; margin:0 auto 4px; text-align:center; }
    .rpt-banner  { width:100%; max-width:<?= $orientation === 'landscape' ? '850px' : '680px' ?>; height:auto; display:inline-block; margin:0 auto; }
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
<div class="WordSection1">
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
    $isLandscape = (($GLOBALS['REPORT_ORIENTATION'] ?? 'portrait') === 'landscape');
    $bannerWidth = $isLandscape ? 850 : 680;
    ?>
  <div class="rpt-head" align="center">
    <?php if ($banner !== ''): ?>
      <table class="rpt-banner-wrap" align="center" border="0" cellpadding="0" cellspacing="0" style="width:100%; max-width:100%; margin:0 auto 4px auto; border-collapse:collapse; border:none; text-align:center;">
        <tr>
          <td align="center" style="border:none; padding:0; text-align:center;">
            <img class="rpt-banner" src="<?= $banner ?>" alt="<?= e(REPORT_INSTITUTION) ?>" align="center" width="<?= $bannerWidth ?>" style="max-width:100%; height:auto;">
          </td>
        </tr>
      </table>
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

/**
 * The standard sign-off columns, in order of authority.
 *
 * TS-REP-03 — Dean / Academics countersigns every IQAC export, so the column
 * belongs in the shared default rather than in the handful of reports that
 * happened to name it. $hodScope appends the department to the HOD column
 * ("HOD / Computer Science and Business Systems") when a report covers one
 * department; pass null for an all-department report.
 */
function report_signoff_columns(?string $hodScope = null, string $hodLabel = 'HOD'): array
{
    $hod = $hodLabel;
    if ($hodScope !== null && trim($hodScope) !== '' && strcasecmp(trim($hodScope), 'ALL DEPARTMENTS') !== 0) {
        $hod .= ' / ' . trim($hodScope);
    }

    return [$hod, 'DEAN / ACADEMICS', 'IQAC COORDINATOR', 'PRINCIPAL'];
}

/** The signature line. Defaults to the standard IQAC sign-off. */
function report_signoff(?array $columns = null): void
{
    $columns = $columns ?? report_signoff_columns();
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

/**
 * The same sign-off, as spreadsheet rows to append after the data.
 *
 * TS-REP-03 asks for the block on Excel exports too, where there is no table
 * markup to hang it on — so it becomes a blank spacer row followed by the
 * signature captions, padded to the sheet's column count.
 */
function report_signoff_rows(?array $columns = null, int $width = 0): array
{
    $columns = $columns ?? report_signoff_columns();
    $width   = max($width, count($columns));

    $blank = array_fill(0, $width, '');
    $line  = $blank;
    foreach (array_values($columns) as $i => $column) {
        $line[$i] = $column;
    }

    return [$blank, $blank, $line];
}

/** Close the document. */
function report_document_foot(): void
{
    echo "\n</div>\n</body>\n</html>";
}
