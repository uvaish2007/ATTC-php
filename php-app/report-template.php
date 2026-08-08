<?php
/**
 * Report Template Builder — Admin only.
 *
 * The Admin designs the report here: they add, rename, reorder and remove
 * COLUMNS, and add/edit/reorder/remove ROWS, filling a value per column. What
 * they build is stored (report_columns / report_rows) and becomes the template
 * every department's report follows — rendered by template-report.php.
 *
 * The layout is CSS-grid rows, not an HTML table, so each editable line can be
 * its own <form> (a <form> may not live inside a <tr>). Reorder and delete are
 * separate one-button forms beside the edit form.
 */

require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/models/ReportTemplate.php';
require_once __DIR__ . '/models/Department.php';

$user = require_role(['Admin']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) input('action');

    if ($action === 'col_add') {
        [$ok, $msg] = template_column_add((string) input('label'), (int) input('width'), (string) input('align'), (string) input('source'), (string) input('field'));
    } elseif ($action === 'col_update') {
        [$ok, $msg] = template_column_update((int) input('id'), (string) input('label'), (int) input('width'), (string) input('align'), (string) input('source'), (string) input('field'));
    } elseif ($action === 'col_delete') {
        [$ok, $msg] = template_column_delete((int) input('id'));
    } elseif ($action === 'col_move') {
        [$ok, $msg] = template_move('report_columns', (int) input('id'), (string) input('dir'));
    } elseif ($action === 'row_add') {
        [$ok, $msg] = template_row_add((array) ($_POST['cell'] ?? []));
    } elseif ($action === 'row_update') {
        [$ok, $msg] = template_row_update((int) input('id'), (array) ($_POST['cell'] ?? []));
    } elseif ($action === 'row_delete') {
        [$ok, $msg] = template_row_delete((int) input('id'));
    } elseif ($action === 'row_move') {
        [$ok, $msg] = template_move('report_rows', (int) input('id'), (string) input('dir'));
    } else {
        [$ok, $msg] = [false, 'Unknown action.'];
    }

    if ($msg !== '') {
        flash($ok ? 'success' : 'error', $msg);
    }
    redirect('/report-template.php');
}

$columns     = template_columns();
$rows        = template_rows();
$dataFields  = target_data_fields();
$departments = departments_all();

// The row editor only edits Label cells — Data cells are auto-filled from each
// department's targets and are never typed here — so the editor grid spans just
// the label columns, with the data columns shown once as a legend.
$labelColumns = array_values(array_filter($columns, fn($c) => ($c['source'] ?? 'label') !== 'data'));
$dataColumns  = array_values(array_filter($columns, fn($c) => ($c['source'] ?? 'label') === 'data'));
$labelGrid = 'grid-template-columns:' . implode(' ', array_map(
    fn($c) => 'minmax(160px,' . max(1, (int) $c['width']) . 'fr)',
    $labelColumns
)) . ' auto;';

$pageTitle  = 'Report Template';
$breadcrumb = 'Report Template';
require __DIR__ . '/inc/header.php';
?>

<div class="page-head">
  <div>
    <h1>Report Template</h1>
    <div class="sub">Design the columns and rows every department's report follows</div>
  </div>
  <div class="actions">
    <select id="tplDept" class="select" title="Fill data from this department" style="min-width:150px">
      <option value="">Structure only</option>
      <?php foreach ($departments as $d): ?><option value="<?= e($d['name']) ?>"><?= e($d['name']) ?></option><?php endforeach; ?>
    </select>
    <button class="btn btn-outline btn-sm" onclick="tplGo('pdf', true)"><?= icon('eye') ?> Preview</button>
    <button class="btn btn-primary btn-sm" onclick="tplGo('word', false)"><?= icon('download') ?> Download</button>
  </div>
</div>
<script>
  /* Open the template report, filling data from the chosen department (or the
     empty structure when none is picked). */
  function tplGo(fmt, newTab) {
    var d = document.getElementById('tplDept').value;
    var u = '<?= e(url('template-report.php')) ?>?format=' + fmt + (d ? '&department=' + encodeURIComponent(d) : '');
    if (newTab) { window.open(u, '_blank'); } else { window.location = u; }
  }
</script>


<!-- ============================ COLUMNS ============================ -->
<div class="card">
  <div class="card-head">
    <div>
      <div class="card-title">Columns</div>
      <div class="card-sub">
        <?= count($columns) ?> column<?= count($columns) !== 1 ? 's' : '' ?> &middot;
        a <strong>Label</strong> column you fill here (the structure); a <strong>Data</strong> column
        fills itself from each department's uploaded targets
      </div>
    </div>
  </div>
  <div class="card-body">
    <div class="tpl-scroll">
    <div class="tpl-line tpl-head">
      <div class="tpl-ops" style="visibility:hidden"><button class="mini-btn"><?= icon('arrow-left', 15) ?></button><button class="mini-btn"><?= icon('arrow-right', 15) ?></button></div>
      <div class="tpl-edit" style="grid-template-columns:1fr 66px 92px 104px 1fr auto">
        <span class="tpl-colname">Column name</span>
        <span class="tpl-colname">Width %</span>
        <span class="tpl-colname">Align</span>
        <span class="tpl-colname">Fills from</span>
        <span class="tpl-colname">Data field</span>
        <span></span>
      </div>
      <span class="mini-btn" style="visibility:hidden"></span>
    </div>
    <?php foreach ($columns as $i => $c): ?>
      <div class="tpl-line">
        <div class="tpl-ops">
          <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="col_move"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>"><input type="hidden" name="dir" value="up">
            <button class="mini-btn" title="Move left" <?= $i === 0 ? 'disabled' : '' ?>><?= icon('arrow-left', 15) ?></button></form>
          <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="col_move"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>"><input type="hidden" name="dir" value="down">
            <button class="mini-btn" title="Move right" <?= $i === count($columns) - 1 ? 'disabled' : '' ?>><?= icon('arrow-right', 15) ?></button></form>
        </div>
        <form method="post" class="tpl-edit" style="grid-template-columns:1fr 66px 92px 104px 1fr auto">
          <?= csrf_field() ?><input type="hidden" name="action" value="col_update"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
          <input class="input" name="label" value="<?= e($c['label']) ?>" required aria-label="Column name">
          <input class="input" type="number" name="width" value="<?= (int) $c['width'] ?>" min="3" max="60" title="Width %">
          <select class="select" name="align" title="Alignment">
            <?php foreach (['left', 'center', 'right'] as $a): ?>
              <option value="<?= $a ?>" <?= $c['align'] === $a ? 'selected' : '' ?>><?= ucfirst($a) ?></option>
            <?php endforeach; ?>
          </select>
          <select class="select" name="source" title="Who fills this column">
            <option value="label" <?= ($c['source'] ?? 'label') !== 'data' ? 'selected' : '' ?>>Label</option>
            <option value="data"  <?= ($c['source'] ?? 'label') === 'data' ? 'selected' : '' ?>>Data</option>
          </select>
          <select class="select" name="field" title="For a Data column: which uploaded field fills it">
            <?php foreach ($dataFields as $key => $fl): ?>
              <option value="<?= e($key) ?>" <?= ($c['field'] ?? '') === $key ? 'selected' : '' ?>><?= e($fl) ?></option>
            <?php endforeach; ?>
          </select>
          <button class="btn btn-outline btn-sm" title="Save changes"><?= icon('save', 15) ?></button>
        </form>
        <form method="post" onsubmit="return confirm('Remove this column from the report?')">
          <?= csrf_field() ?><input type="hidden" name="action" value="col_delete"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
          <button class="mini-btn danger" title="Delete column"><?= icon('trash', 15) ?></button>
        </form>
      </div>
    <?php endforeach; ?>

    <!-- Add a column -->
    <form method="post" class="tpl-line tpl-add" style="border-bottom:0">
      <?= csrf_field() ?><input type="hidden" name="action" value="col_add">
      <input class="input" name="label" placeholder="New column name" required style="flex:1;min-width:170px">
      <input class="input" type="number" name="width" value="12" min="3" max="60" title="Width %" style="width:78px">
      <select class="select" name="align" style="width:92px" title="Alignment"><option value="left">Left</option><option value="center">Center</option><option value="right">Right</option></select>
      <select class="select" name="source" style="width:104px" title="Who fills it"><option value="label">Label</option><option value="data">Data</option></select>
      <select class="select" name="field" style="min-width:150px" title="Data field">
        <?php foreach ($dataFields as $key => $fl): ?><option value="<?= e($key) ?>"><?= e($fl) ?></option><?php endforeach; ?>
      </select>
      <button class="btn btn-primary btn-sm"><?= icon('plus') ?> Add</button>
    </form>
    </div>
  </div>
</div>


<!-- ============================ ROWS ============================ -->
<div class="mt-5 card">
  <div class="card-head">
    <div>
      <div class="card-title">Rows</div>
      <div class="card-sub">
        <?= count($rows) ?> row<?= count($rows) !== 1 ? 's' : '' ?> &middot;
        fill only the label cells below &mdash; the data columns fill themselves when the report is generated
      </div>
    </div>
  </div>
  <div class="card-body">
    <?php if ($dataColumns): ?>
      <div class="tpl-legend">
        <span class="tpl-legend-lead"><?= icon('refresh', 13) ?> Auto-filled from each department's targets:</span>
        <?php foreach ($dataColumns as $c): ?><span class="tpl-type data"><?= e($c['label']) ?></span><?php endforeach; ?>
      </div>
    <?php endif; ?>

    <div class="tpl-scroll">
      <!-- Only the label columns are edited here -->
      <div class="tpl-line tpl-head">
        <div class="tpl-ops" style="visibility:hidden"><button class="mini-btn"><?= icon('arrow-left', 15) ?></button><button class="mini-btn"><?= icon('arrow-right', 15) ?></button></div>
        <div class="tpl-edit" style="<?= $labelGrid ?>">
          <?php foreach ($labelColumns as $c): ?><span class="tpl-colname"><?= e($c['label']) ?></span><?php endforeach; ?>
          <span></span>
        </div>
        <span class="mini-btn" style="visibility:hidden"></span>
      </div>

      <?php if (empty($rows)): ?>
        <div class="card-sub" style="padding:12px 0">No rows yet — add the first one below.</div>
      <?php endif; ?>

      <?php foreach ($rows as $i => $r): ?>
        <div class="tpl-line">
          <div class="tpl-ops">
            <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="row_move"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="dir" value="up">
              <button class="mini-btn" title="Move up" <?= $i === 0 ? 'disabled' : '' ?>><?= icon('arrow-left', 15) ?></button></form>
            <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="row_move"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="dir" value="down">
              <button class="mini-btn" title="Move down" <?= $i === count($rows) - 1 ? 'disabled' : '' ?>><?= icon('arrow-right', 15) ?></button></form>
          </div>
          <form method="post" class="tpl-edit" style="<?= $labelGrid ?>">
            <?= csrf_field() ?><input type="hidden" name="action" value="row_update"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
            <?php foreach ($labelColumns as $c): ?>
              <input class="input" name="cell[<?= e($c['col_key']) ?>]" value="<?= e($r['cells'][$c['col_key']] ?? '') ?>" aria-label="<?= e($c['label']) ?>">
            <?php endforeach; ?>
            <button class="btn btn-outline btn-sm" title="Save row"><?= icon('save', 15) ?></button>
          </form>
          <form method="post" onsubmit="return confirm('Delete this row?')">
            <?= csrf_field() ?><input type="hidden" name="action" value="row_delete"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
            <button class="mini-btn danger" title="Delete row"><?= icon('trash', 15) ?></button>
          </form>
        </div>
      <?php endforeach; ?>

      <!-- Add a row -->
      <div class="tpl-line tpl-add" style="border-top:0">
        <div class="tpl-ops" style="visibility:hidden"><button class="mini-btn"><?= icon('arrow-left', 15) ?></button><button class="mini-btn"><?= icon('arrow-right', 15) ?></button></div>
        <form method="post" class="tpl-edit" style="<?= $labelGrid ?>">
          <?= csrf_field() ?><input type="hidden" name="action" value="row_add">
          <?php foreach ($labelColumns as $c): ?>
            <input class="input" name="cell[<?= e($c['col_key']) ?>]" placeholder="<?= e($c['label']) ?>">
          <?php endforeach; ?>
          <button class="btn btn-primary btn-sm" title="Add row"><?= icon('plus', 15) ?> Add row</button>
        </form>
        <span class="mini-btn" style="visibility:hidden"></span>
      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/inc/footer.php'; ?>
