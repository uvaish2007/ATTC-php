<?php
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
    } elseif ($action === 'col_reorder') {
        $ids = (array) ($_POST['ids'] ?? []);
        if (empty($ids) && isset($_POST['order'])) {
            $ids = array_filter(array_map('intval', explode(',', (string) $_POST['order'])));
        }
        [$ok, $msg] = template_reorder('report_columns', $ids);
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            header('Content-Type: application/json');
            echo json_encode(['ok' => $ok, 'message' => $msg]);
            exit;
        }
    } elseif ($action === 'row_add') {
        [$ok, $msg] = template_row_add((array) ($_POST['cell'] ?? []));
    } elseif ($action === 'row_update') {
        [$ok, $msg] = template_row_update((int) input('id'), (array) ($_POST['cell'] ?? []));
    } elseif ($action === 'row_delete') {
        [$ok, $msg] = template_row_delete((int) input('id'));
    } elseif ($action === 'row_move') {
        [$ok, $msg] = template_move('report_rows', (int) input('id'), (string) input('dir'));
    } elseif ($action === 'row_reorder') {
        $ids = (array) ($_POST['ids'] ?? []);
        if (empty($ids) && isset($_POST['order'])) {
            $ids = array_filter(array_map('intval', explode(',', (string) $_POST['order'])));
        }
        [$ok, $msg] = template_reorder('report_rows', $ids);
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            header('Content-Type: application/json');
            echo json_encode(['ok' => $ok, 'message' => $msg]);
            exit;
        }
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
  function tplGo(fmt, newTab) {
    var d = document.getElementById('tplDept').value;
    var u = '<?= e(url('template-report.php')) ?>?format=' + fmt + (d ? '&department=' + encodeURIComponent(d) : '');
    if (newTab) { window.open(u, '_blank'); } else { window.location = u; }
  }</script>

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
      <div class="tpl-ops" style="visibility:hidden"><div class="tpl-drag-handle"></div></div>
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
    <div id="tpl_columns_container">
    <?php foreach ($columns as $i => $c): ?>
      <div class="tpl-line tpl-col-line" data-id="<?= (int) $c['id'] ?>">
        <div class="tpl-ops">
          <div class="tpl-drag-handle" title="Drag to reorder column" aria-label="Drag to reorder column" tabindex="0">
            <?= icon('grip-vertical', 16) ?>
          </div>
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
    </div>

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
        <div class="tpl-ops" style="visibility:hidden"><div class="tpl-drag-handle"></div></div>
        <div class="tpl-edit" style="<?= $labelGrid ?>">
          <?php foreach ($labelColumns as $c): ?><span class="tpl-colname"><?= e($c['label']) ?></span><?php endforeach; ?>
          <span></span>
        </div>
        <span class="mini-btn" style="visibility:hidden"></span>
      </div>

      <?php if (empty($rows)): ?>
        <div class="card-sub" style="padding:12px 0">No rows yet — add the first one below.</div>
      <?php endif; ?>

      <div id="tpl_rows_container">
      <?php foreach ($rows as $i => $r): ?>
        <div class="tpl-line tpl-row-line" data-id="<?= (int) $r['id'] ?>">
          <div class="tpl-ops">
            <div class="tpl-drag-handle" title="Drag to reorder row" aria-label="Drag to reorder row" tabindex="0">
              <?= icon('grip-vertical', 16) ?>
            </div>
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
      </div>

      <!-- Add a row -->
      <div class="tpl-line tpl-add" style="border-top:0">
        <div class="tpl-ops" style="visibility:hidden"><div class="tpl-drag-handle"></div></div>
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

<style>
.tpl-ops {
  width: 32px;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
.tpl-drag-handle {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 28px;
  height: 28px;
  border-radius: 6px;
  color: var(--ink-faint, #94a3b8);
  cursor: grab;
  cursor: -webkit-grab;
  transition: background 0.15s ease, color 0.15s ease, transform 0.15s ease, border-color 0.15s ease;
  user-select: none;
  -webkit-user-select: none;
  touch-action: none;
  background: var(--navy-50, #f8fafc);
  border: 1px solid var(--hairline, #e2e8f0);
}
.tpl-drag-handle:hover {
  background: var(--navy-100, #e2e8f0);
  color: var(--navy-900, #0f172a);
  border-color: var(--navy-300, #cbd5e1);
  transform: scale(1.05);
}
.tpl-drag-handle:active,
.tpl-line.is-dragging .tpl-drag-handle {
  cursor: grabbing;
  cursor: -webkit-grabbing;
  background: rgba(255, 79, 1, 0.12) !important;
  color: var(--orange-600, #ea580c) !important;
  border-color: rgba(255, 79, 1, 0.4) !important;
}
.tpl-line {
  transition: background 0.15s ease, box-shadow 0.15s ease, transform 0.15s ease;
}
.tpl-line.is-dragging {
  opacity: 0.45;
  background: #fff7ed !important;
  outline: 2px dashed #f97316;
  border-radius: 8px;
  box-shadow: 0 8px 20px rgba(249, 115, 22, 0.15);
}
.tpl-toast {
  position: fixed;
  bottom: 24px;
  right: 24px;
  background: #0f172a;
  color: #f8fafc;
  padding: 10px 18px;
  border-radius: 10px;
  font-size: 13px;
  font-weight: 600;
  display: flex;
  align-items: center;
  gap: 8px;
  box-shadow: 0 10px 30px rgba(0,0,0,0.25);
  border: 1px solid rgba(255,255,255,0.1);
  opacity: 0;
  transform: translateY(12px);
  transition: opacity 0.25s ease, transform 0.25s ease;
  z-index: 9999;
  pointer-events: none;
}
.tpl-toast.show {
  opacity: 1;
  transform: translateY(0);
}
</style>

<script>
(function() {
  function initDragAndDrop(containerSelector, itemSelector, type) {
    var container = document.querySelector(containerSelector);
    if (!container) return;

    var draggedItem = null;
    var initialOrder = [];

    function getIds() {
      return Array.from(container.querySelectorAll(itemSelector)).map(function(el) {
        return el.getAttribute('data-id');
      });
    }

    container.addEventListener('mousedown', function(e) {
      var handle = e.target.closest('.tpl-drag-handle');
      if (handle) {
        var item = handle.closest(itemSelector);
        if (item) {
          item.draggable = true;
        }
      }
    });

    container.addEventListener('mouseup', function(e) {
      var item = container.querySelector(itemSelector + '[draggable="true"]');
      if (item) {
        item.draggable = false;
      }
    });

    container.addEventListener('dragstart', function(e) {
      var item = e.target.closest(itemSelector);
      if (!item) {
        e.preventDefault();
        return;
      }

      draggedItem = item;
      initialOrder = getIds();
      item.classList.add('is-dragging');
      e.dataTransfer.effectAllowed = 'move';
      e.dataTransfer.setData('text/plain', item.getAttribute('data-id') || '');
    });

    container.addEventListener('dragover', function(e) {
      if (!draggedItem) return;
      e.preventDefault();
      e.dataTransfer.dropEffect = 'move';

      var targetItem = e.target.closest(itemSelector);
      if (!targetItem || targetItem === draggedItem) return;

      var rect = targetItem.getBoundingClientRect();
      var midY = rect.top + rect.height / 2;
      if (e.clientY < midY) {
        targetItem.parentNode.insertBefore(draggedItem, targetItem);
      } else {
        targetItem.parentNode.insertBefore(draggedItem, targetItem.nextSibling);
      }
    });

    container.addEventListener('dragend', function(e) {
      if (!draggedItem) return;
      draggedItem.classList.remove('is-dragging');
      draggedItem.draggable = false;
      draggedItem = null;

      var currentOrder = getIds();
      if (JSON.stringify(initialOrder) === JSON.stringify(currentOrder)) {
        return;
      }

      saveOrder(type, currentOrder);
    });

    var touchItem = null;
    var touchStartY = 0;
    var touchInitialOrder = [];

    container.addEventListener('touchstart', function(e) {
      var handle = e.target.closest('.tpl-drag-handle');
      if (!handle) return;
      var item = handle.closest(itemSelector);
      if (!item) return;

      touchItem = item;
      touchStartY = e.touches[0].clientY;
      touchInitialOrder = getIds();
      item.classList.add('is-dragging');
    }, { passive: true });

    container.addEventListener('touchmove', function(e) {
      if (!touchItem) return;
      var touch = e.touches[0];
      var elemBelow = document.elementFromPoint(touch.clientX, touch.clientY);
      if (!elemBelow) return;
      var targetItem = elemBelow.closest(itemSelector);
      if (!targetItem || targetItem === touchItem) return;

      var rect = targetItem.getBoundingClientRect();
      var midY = rect.top + rect.height / 2;
      if (touch.clientY < midY) {
        targetItem.parentNode.insertBefore(touchItem, targetItem);
      } else {
        targetItem.parentNode.insertBefore(touchItem, targetItem.nextSibling);
      }
      e.preventDefault();
    }, { passive: false });

    container.addEventListener('touchend', function(e) {
      if (!touchItem) return;
      touchItem.classList.remove('is-dragging');
      touchItem = null;

      var currentOrder = getIds();
      if (JSON.stringify(touchInitialOrder) === JSON.stringify(currentOrder)) {
        return;
      }
      saveOrder(type, currentOrder);
    });
  }

  function saveOrder(type, ids) {
    var formData = new FormData();
    formData.append('action', type === 'col' ? 'col_reorder' : 'row_reorder');
    formData.append('csrf', '<?= e(csrf_token()) ?>');
    formData.append('order', ids.join(','));
    ids.forEach(function(id) {
      formData.append('ids[]', id);
    });

    fetch('report-template.php', {
      method: 'POST',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      body: formData
    })
    .then(function(res) { return res.json(); })
    .then(function(data) {
      if (data && data.ok) {
        showToast((type === 'col' ? 'Column' : 'Row') + ' order updated');
      } else {
        showToast('Error updating order: ' + (data ? data.message : 'Unknown error'), true);
      }
    })
    .catch(function(err) {
      console.error('Reorder error:', err);
      showToast('Error saving order', true);
    });
  }

  var toastEl = null;
  var toastTimer = null;
  function showToast(msg, isError) {
    if (!toastEl) {
      toastEl = document.createElement('div');
      toastEl.className = 'tpl-toast';
      document.body.appendChild(toastEl);
    }
    clearTimeout(toastTimer);
    toastEl.innerHTML = (isError ? '✕ ' : '✓ ') + msg;
    toastEl.style.background = isError ? '#991b1b' : '#0f172a';
    toastEl.classList.add('show');
    toastTimer = setTimeout(function() {
      toastEl.classList.remove('show');
    }, 2200);
  }

  initDragAndDrop('#tpl_columns_container', '.tpl-col-line', 'col');
  initDragAndDrop('#tpl_rows_container', '.tpl-row-line', 'row');
})();</script>

<?php require __DIR__ . '/inc/footer.php'; ?>
