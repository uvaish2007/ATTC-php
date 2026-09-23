<?php
require_once __DIR__ . '/helpers.php';   
const REPORT_INSTITUTION = 'Mohamed Sathak Engineering College';

// True when this response is a Word document rather than a page in the browser.
// A .doc has to carry its images inside it; a page can just link to them.
function report_is_word_download(): bool
{
    foreach (headers_list() as $h) {
        if (stripos($h, 'application/msword') !== false) {
            return true;
        }
    }
    return false;
}

function report_banner_datauri(): string
{
    static $cache = [];

    $dir = dirname(__DIR__) . '/assets/img/';
    $key = report_is_word_download() ? 'inline' : 'linked';

    if (isset($cache[$key])) {
        return $cache[$key];
    }

    foreach (['letterhead.jpg' => 'image/jpeg', 'letterhead.png' => 'image/png'] as $file => $mime) {
        if (!is_file($dir . $file)) {
            continue;
        }

        // Word keeps the artwork only if it is embedded. A browser would
        // otherwise hold a copy per letterhead, and the targets proforma draws
        // one per department — seventeen copies of the same image, which took
        // that report past two megabytes.
        $cache[$key] = $key === 'inline'
            ? 'data:' . $mime . ';base64,' . base64_encode((string) file_get_contents($dir . $file))
            : url('assets/img/' . $file);

        return $cache[$key];
    }

    return $cache[$key] = '';
}


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

    require_once __DIR__ . '/../models/Department.php';
    foreach (departments_all() as $d) {
        if (strcasecmp($d['code'], $dept) === 0 || strcasecmp($d['name'], $dept) === 0) {
            return mb_strlen($d['name']) >= mb_strlen($d['code']) ? $d['name'] : $d['code'];
        }
    }

    return $dept;
}

function report_year_duration(?string $year): array
{
    if ($year && preg_match('/^(\d{4})-\d{2}$/', $year, $m)) {
        $start = (int) $m[1];
        return ['01.07.' . $start, '30.06.' . ($start + 1)];
    }
    return ['', ''];
}

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

// The college banner as an <img>, or '' when the file is missing.
//
// Exports show the letterhead artwork rather than the college name set in
// type: it carries the crest, the accreditations and the address, which a
// line of text cannot, and it is what the printed proformas are expected to
// look like. Callers fall back to REPORT_INSTITUTION when this returns ''.
function report_banner_img(int $width = 680, string $extraStyle = ''): string
{
    $banner = report_banner_datauri();
    if ($banner === '') {
        return '';
    }

    return '<img src="' . $banner . '" alt="' . e(REPORT_INSTITUTION) . '"'
         . ' width="' . $width . '" align="center"'
         . ' style="display:block; margin:0 auto; max-width:100%; height:auto;' . $extraStyle . '">';
}

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

// The review screen wrapped around a report opened as ?format=pdf.
//
// Shows the document as a sheet of paper on a neutral canvas with a toolbar
// above it, so what you are about to print is what you are looking at. Both
// the toolbar and the canvas are screen-only; printing gets the bare page.
//
// $facts are the short scope lines under the title ("All departments",
// "2025-26", "42 records"). Links to the same report in the other formats are
// derived from the current URL, so a caller passes nothing for them.
function report_pdf_bar(string $title, array $facts = [], array $alsoOffer = ['word', 'excel'], ?string $orientation = null): void
{
    $landscape = ($orientation ?? $GLOBALS['REPORT_ORIENTATION'] ?? 'portrait') === 'landscape';
    $sheetW    = $landscape ? '29.7cm' : '21cm';
    $sheetMinH = $landscape ? '21cm'   : '29.7cm';

    $swapFormat = static function (string $format): string {
        $uri   = $_SERVER['REQUEST_URI'] ?? '';
        $parts = parse_url($uri);
        parse_str($parts['query'] ?? '', $q);
        $q['format'] = $format;
        return ($parts['path'] ?? '') . '?' . http_build_query($q);
    };

    $facts = array_values(array_filter($facts, static fn($f) => trim((string) $f) !== ''));
    ?>
  <style>
    @media screen {
      html { background: #EDF0F5; }
      body { background: #EDF0F5; margin: 0; padding: 104px 20px 56px; }

      /* The page itself, as paper. */
      div.WordSection1, .pdf-sheet {
        width: <?= $sheetW ?>; min-height: <?= $sheetMinH ?>; box-sizing: border-box;
        margin: 0 auto; padding: 1.4cm 1.2cm; background: #fff;
        box-shadow: 0 1px 2px rgba(19,29,59,.06), 0 18px 48px -12px rgba(19,29,59,.22);
      }

      .pdf-bar {
        position: fixed; inset: 0 0 auto 0; z-index: 50;
        background: #131D3B; color: #fff;
        font-family: 'Inter', 'Segoe UI', system-ui, sans-serif;
        display: flex; align-items: center; justify-content: space-between;
        gap: 12px 20px; padding: 12px 22px; flex-wrap: wrap;
        box-shadow: 0 1px 0 rgba(255,255,255,.08), 0 6px 24px rgba(12,19,41,.28);
      }
      .pdf-bar-id { min-width: 0; }
      .pdf-bar-k {
        font-size: 10px; font-weight: 700; letter-spacing: .09em;
        text-transform: uppercase; color: #FF7A3D;
      }
      .pdf-bar-t {
        font-size: 14.5px; font-weight: 650; margin-top: 2px; color: #fff;
        white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 52vw;
      }
      .pdf-bar-facts {
        font-size: 11.5px; color: #9AA7C4; margin-top: 3px;
        display: flex; gap: 8px; flex-wrap: wrap;
      }
      .pdf-bar-facts span:not(:last-child)::after { content: '·'; margin-left: 8px; color: #55628A; }

      .pdf-bar-do { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
      .pdf-btn {
        font: inherit; font-size: 12.5px; font-weight: 600; cursor: pointer;
        border-radius: 8px; padding: 9px 15px; border: 1px solid transparent;
        text-decoration: none; display: inline-flex; align-items: center; gap: 7px;
        transition: background .15s ease, border-color .15s ease;
      }
      .pdf-btn-main { background: #FF4F01; color: #fff; }
      .pdf-btn-main:hover { background: #E04400; }
      .pdf-btn-alt {
        background: transparent; color: #C9D2E4; border-color: rgba(255,255,255,.22);
      }
      .pdf-btn-alt:hover { background: rgba(255,255,255,.08); color: #fff; }
      .pdf-kbd {
        font-size: 10.5px; color: #8492B4; margin-left: 2px; white-space: nowrap;
      }
      .pdf-kbd b {
        font-weight: 600; color: #C9D2E4; border: 1px solid rgba(255,255,255,.2);
        border-radius: 4px; padding: 1px 5px; font-family: inherit;
      }

      @media (max-width: 720px) {
        body { padding-top: 132px; }
        .pdf-bar-t { max-width: 100%; white-space: normal; }
        .pdf-kbd { display: none; }
      }
    }

    /* Printing gets the document and nothing else. */
    @media print {
      .pdf-bar { display: none !important; }
      html, body { background: #fff; margin: 0; padding: 0; }
      div.WordSection1, .pdf-sheet {
        width: auto; min-height: 0; margin: 0; padding: 0; box-shadow: none;
      }
    }
  </style>

  <div class="pdf-bar">
    <div class="pdf-bar-id">
      <div class="pdf-bar-k">Print preview</div>
      <div class="pdf-bar-t" title="<?= e($title) ?>"><?= e($title) ?></div>
      <?php if ($facts): ?>
        <div class="pdf-bar-facts">
          <?php foreach ($facts as $f): ?><span><?= e($f) ?></span><?php endforeach; ?>
          <span><?= $landscape ? 'A4 landscape' : 'A4 portrait' ?></span>
        </div>
      <?php endif; ?>
    </div>

    <div class="pdf-bar-do">
      <?php if (in_array('word', $alsoOffer, true)): ?>
        <a class="pdf-btn pdf-btn-alt" href="<?= e($swapFormat('word')) ?>">Word</a>
      <?php endif; ?>
      <?php if (in_array('excel', $alsoOffer, true)): ?>
        <a class="pdf-btn pdf-btn-alt" href="<?= e($swapFormat('excel')) ?>">Excel</a>
      <?php endif; ?>
      <button type="button" class="pdf-btn pdf-btn-main" onclick="window.print()">Print / Save as PDF</button>
      <span class="pdf-kbd"><b>Ctrl</b> + <b>P</b></span>
    </div>
  </div>
<?php
}

function report_signoff_columns(?string $hodScope = null, string $hodLabel = 'HOD'): array
{
    $hod = $hodLabel;
    if ($hodScope !== null && trim($hodScope) !== '' && strcasecmp(trim($hodScope), 'ALL DEPARTMENTS') !== 0) {
        $hod .= ' / ' . trim($hodScope);
    }

    return [$hod, 'DEAN / ACADEMICS', 'IQAC COORDINATOR', 'PRINCIPAL'];
}

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

function report_document_foot(): void
{
    echo "\n</div>\n</body>\n</html>";
}
