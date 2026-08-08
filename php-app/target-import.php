<?php
/**
 * Bulk-import department targets from a CSV file — with a preview step.
 *
 * The HoD downloads the template, fills a row per target, uploads it, and sees a
 * preview: every row is validated and marked OK or "Needs review" (with the
 * reason) BEFORE anything is written. On confirm, the OK rows are inserted as
 * Drafts in the HoD's own department — exactly as if typed on the Targets page —
 * so they then flow through the normal send-for-review workflow. Bad rows are
 * never saved and never silently dropped: they are shown so they can be fixed.
 *
 * Import is HoD-only and always into the HoD's own department, mirroring
 * target_create() (an Admin freezes/unlocks targets; a HoD enters them).
 */

require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/models/Target.php';

$user = require_role(['HoD']);
require_module('targets');

$department = (string) ($user['department'] ?? '');
$years      = academic_years();
$SESSION_KEY = 'target_import_preview';

/** The columns the template and parser use, in order. */
$columns = ['metric', 'target_value', 'academic_year', 'coordinator', 'remarks'];

/* -------------------------------------------------------------------------
   Template download:  target-import.php?template=1
   ---------------------------------------------------------------------- */
if (($_GET['template'] ?? '') !== '') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="target-import-template.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, chr(0xEF) . chr(0xBB) . chr(0xBF)); // BOM so Excel reads UTF-8
    fputcsv($out, $columns, ',', '"', '');
    // Two example rows to show the shape (delete before importing).
    fputcsv($out, ['Journal Publications', '45', $years[0] ?? '2025-26', 'Dr. A. Kumar', 'SCI / Scopus indexed'], ',', '"', '');
    fputcsv($out, ['Patents Filed', '10', $years[0] ?? '2025-26', '', ''], ',', '"', '');
    fclose($out);
    exit;
}

/**
 * Validate one raw CSV row into a preview row.
 * Returns [normalisedRow, error|null].
 */
function import_validate_row(array $raw, array $years): array
{
    $metric = trim((string) ($raw['metric'] ?? ''));
    $valRaw = trim((string) ($raw['target_value'] ?? ''));
    $year   = trim((string) ($raw['academic_year'] ?? ''));
    $coord  = trim((string) ($raw['coordinator'] ?? ''));
    $rem    = trim((string) ($raw['remarks'] ?? ''));

    $row = [
        'metric'        => $metric,
        'target_value'  => $valRaw,
        'academic_year' => $year !== '' ? $year : ($years[0] ?? ''),
        'coordinator'   => $coord,
        'remarks'       => $rem,
    ];

    if ($metric === '') {
        return [$row, 'Target / metric is empty.'];
    }
    if ($valRaw === '' || !ctype_digit(ltrim($valRaw, '+')) ) {
        return [$row, 'Target value must be a whole number (got "' . $valRaw . '").'];
    }
    if ((int) $valRaw < 0) {
        return [$row, 'Target value cannot be negative.'];
    }
    if ($year !== '' && !in_array($year, $years, true)) {
        return [$row, 'Academic year "' . $year . '" is not one of the allowed years.'];
    }

    return [$row, null];
}

/* -------------------------------------------------------------------------
   POST: upload -> parse -> preview  |  confirm -> insert  |  cancel -> clear
   ---------------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) input('action');

    if ($action === 'cancel') {
        unset($_SESSION[$SESSION_KEY]);
        flash('info', 'Import cancelled.');
        redirect('/target-import.php');
    }

    if ($action === 'upload') {
        $file = $_FILES['csv'] ?? null;

        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            flash('error', 'Choose a CSV file to upload.');
            redirect('/target-import.php');
        }
        if ($file['size'] > 1048576) { // 1 MB is plenty for a target list
            flash('error', 'That file is too large (max 1 MB).');
            redirect('/target-import.php');
        }

        $handle = fopen($file['tmp_name'], 'r');
        if (!$handle) {
            flash('error', 'The file could not be read.');
            redirect('/target-import.php');
        }

        // First non-empty line is the header. Map its names to our columns.
        $header = null;
        $rows   = [];
        $okCount = 0;

        while (($cells = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            // Skip completely blank lines.
            if ($cells === [null] || (count($cells) === 1 && trim((string) $cells[0]) === '')) {
                continue;
            }

            if ($header === null) {
                $header = array_map(fn($h) => strtolower(trim((string) $h)), $cells);
                continue;
            }

            $raw = [];
            foreach ($header as $i => $name) {
                $raw[$name] = $cells[$i] ?? '';
            }

            [$row, $error] = import_validate_row($raw, $years);
            $row['_error'] = $error;
            if ($error === null) {
                $okCount++;
            }
            $rows[] = $row;

            if (count($rows) >= 500) { // guardrail against a runaway file
                break;
            }
        }
        fclose($handle);

        if (empty($rows)) {
            flash('error', 'No data rows found. Make sure the first line is the header row.');
            redirect('/target-import.php');
        }

        $_SESSION[$SESSION_KEY] = ['rows' => $rows, 'ok' => $okCount, 'department' => $department];
        redirect('/target-import.php');
    }

    if ($action === 'confirm') {
        $preview = $_SESSION[$SESSION_KEY] ?? null;
        if (!$preview) {
            flash('error', 'Nothing to import — upload a file first.');
            redirect('/target-import.php');
        }

        $imported = 0;
        $skipped  = 0;
        foreach ($preview['rows'] as $row) {
            if (($row['_error'] ?? null) !== null) {
                $skipped++;
                continue;
            }
            [$ok] = target_create(
                $user,
                $department,
                (string) $row['academic_year'],
                (string) $row['metric'],
                (int) $row['target_value'],
                $row['remarks'] !== '' ? $row['remarks'] : null,
                $row['coordinator'] !== '' ? $row['coordinator'] : null
            );
            $ok ? $imported++ : $skipped++;
        }

        unset($_SESSION[$SESSION_KEY]);
        flash(
            $imported > 0 ? 'success' : 'error',
            "Imported {$imported} target" . ($imported === 1 ? '' : 's') . " as drafts"
              . ($skipped > 0 ? ", skipped {$skipped} row" . ($skipped === 1 ? '' : 's') . " that needed review." : ".")
        );
        redirect('/targets.php');
    }

    redirect('/target-import.php');
}

$preview = $_SESSION[$SESSION_KEY] ?? null;

$pageTitle  = 'Import Targets';
$breadcrumb = 'Import Targets';
require __DIR__ . '/inc/header.php';
?>

<div class="page-head">
  <div>
    <h1>Import Targets</h1>
    <div class="sub">Bulk-add targets for <strong><?= e($department ?: '—') ?></strong> from a CSV — with a preview before anything is saved.</div>
  </div>
  <div class="actions">
    <a class="btn btn-outline btn-sm" href="<?= e(url('targets.php')) ?>"><?= icon('arrow-left', 15) ?> Back to Targets</a>
  </div>
</div>

<?php if (!$preview): ?>

  <div class="card" style="margin-bottom:16px">
    <div class="card-head"><div>
      <div class="card-title">How it works</div>
      <div class="card-sub">Download the template, fill one row per target, upload it, then review before saving.</div>
    </div></div>
    <div class="card-body">
      <ol class="import-steps">
        <li><a href="<?= e(url('target-import.php?template=1')) ?>"><?= icon('download', 14) ?> Download the CSV template</a></li>
        <li>Fill a row per target — columns: <code><?= e(implode(', ', $columns)) ?></code>. Leave <code>academic_year</code> blank to use the current year.</li>
        <li>Upload it below. Every row is checked; bad rows are flagged, not saved.</li>
        <li>Confirm — valid rows are added as <strong>drafts</strong> you then send for review.</li>
      </ol>

      <form method="post" enctype="multipart/form-data" style="margin-top:8px">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="upload">
        <div class="field">
          <label>CSV file</label>
          <input class="input file" type="file" name="csv" accept=".csv,text/csv" required>
        </div>
        <button type="submit" class="btn btn-primary btn-sm"><?= icon('upload', 15) ?> Upload &amp; preview</button>
      </form>
    </div>
  </div>

<?php else: ?>

  <?php
    $rows    = $preview['rows'];
    $okCount = $preview['ok'];
    $badCount = count($rows) - $okCount;
  ?>

  <div class="card">
    <div class="card-head"><div>
      <div class="card-title">Preview — nothing is saved yet</div>
      <div class="card-sub">
        <strong style="color:#047857"><?= (int) $okCount ?> ready</strong>
        <?php if ($badCount): ?>&middot; <strong style="color:#B45309"><?= (int) $badCount ?> need review</strong><?php endif; ?>
        &middot; <?= count($rows) ?> row<?= count($rows) === 1 ? '' : 's' ?> total
      </div>
    </div></div>
    <div class="card-body" style="padding:0">
      <div class="table-wrap"><table class="data" style="min-width:720px">
        <thead><tr>
          <th style="padding-left:24px">#</th>
          <th>Target / Metric</th>
          <th class="num">Value</th>
          <th>Year</th>
          <th>Coordinator</th>
          <th style="padding-right:24px">Status</th>
        </tr></thead>
        <tbody>
        <?php foreach ($rows as $i => $row): ?>
          <?php $bad = ($row['_error'] ?? null) !== null; ?>
          <tr<?= $bad ? ' style="background:#FFFBEB"' : '' ?>>
            <td style="padding-left:24px" class="faint tabular"><?= $i + 1 ?></td>
            <td class="fw-500"><?= e($row['metric'] !== '' ? $row['metric'] : '—') ?></td>
            <td class="num tabular"><?= e($row['target_value']) ?></td>
            <td><span class="badge badge-neutral"><?= e($row['academic_year']) ?></span></td>
            <td class="card-sub"><?= e($row['coordinator'] !== '' ? $row['coordinator'] : '—') ?></td>
            <td style="padding-right:24px">
              <?php if ($bad): ?>
                <span class="badge badge-warning"><?= icon('alert-triangle', 12) ?> Needs review</span>
                <div class="card-sub" style="color:#B45309"><?= e($row['_error']) ?></div>
              <?php else: ?>
                <span class="badge badge-success"><?= icon('check', 12) ?> Ready</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    </div>
  </div>

  <div class="flex gap-2" style="margin-top:16px; justify-content:flex-end">
    <form method="post"><?= csrf_field() ?>
      <input type="hidden" name="action" value="cancel">
      <button class="btn btn-outline btn-sm" type="submit">Cancel</button>
    </form>
    <?php if ($okCount > 0): ?>
      <form method="post" onsubmit="return confirm('Import <?= (int) $okCount ?> target(s) as drafts?<?= $badCount ? ' The ' . (int) $badCount . ' flagged row(s) will be skipped.' : '' ?>')">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="confirm">
        <button class="btn btn-primary btn-sm" type="submit"><?= icon('check', 15) ?> Import <?= (int) $okCount ?> as drafts</button>
      </form>
    <?php else: ?>
      <span class="card-sub" style="align-self:center">No valid rows to import — fix the file and upload again.</span>
    <?php endif; ?>
  </div>

<?php endif; ?>

<style>
  .import-steps { margin:0; padding-left:20px; line-height:2; font-size:13.5px; color:var(--ink-muted); }
  .import-steps code { background:var(--navy-50); padding:1px 6px; border-radius:5px; font-size:12px; }
</style>

<?php require __DIR__ . '/inc/footer.php'; ?>
