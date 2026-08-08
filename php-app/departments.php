<?php
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/models/Department.php';

$user = require_role(['Admin']);
require_module('departments');

// -- handle create / update / delete --------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) input('action');

    if ($action === 'create') {
        [$ok, $msg] = department_create((string) input('name'), (string) input('code'));
        flash($ok ? 'success' : 'error', $msg);

    } elseif ($action === 'update') {
        [$ok, $msg] = department_update((int) input('id'), (string) input('name'), (string) input('code'));
        flash($ok ? 'success' : 'error', $msg);

    } elseif ($action === 'delete') {
        [$ok, $msg] = department_delete((int) input('id'));
        flash($ok ? 'success' : 'error', $msg);
    }

    redirect('/departments.php');
}

$departments = departments_all();

$pageTitle  = 'Departments';
$breadcrumb = 'Departments';
require __DIR__ . '/inc/header.php';
?>

<div class="page-head">
  <div>
    <h1>Departments</h1>
    <div class="sub">Scopes targets, entries, and the department reports</div>
  </div>
  <div class="actions">
    <button class="btn btn-primary btn-sm" onclick="document.getElementById('addDlg').showModal()"><?= icon('plus') ?> Add Department</button>
  </div>
</div>

<div class="card">
  <div class="card-body">
    <?php if (empty($departments)): ?>
      <div class="empty">
        <div class="ic"><?= icon('building', 20) ?></div>
        <p>No departments yet</p>
        <div class="note">Add the departments that submit IQAC records.</div>
      </div>
    <?php else: ?>
      <div class="dept-grid">
        <?php foreach ($departments as $d): ?>
          <div class="dept-card">
            <div class="dept-code"><?= e($d['code']) ?></div>
            <div style="min-width:0">
              <div class="nm"><?= e($d['name']) ?></div>
              <div class="cd">Code · <?= e($d['code']) ?></div>
            </div>
            <div class="dept-actions">
              <button class="mini-btn" title="Edit"
                onclick="editDept(<?= (int) $d['id'] ?>, <?= htmlspecialchars(json_encode($d['name']), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode($d['code']), ENT_QUOTES) ?>)"><?= icon('pencil', 15) ?></button>
              <button class="mini-btn danger" title="Delete"
                onclick="deleteDept(<?= (int) $d['id'] ?>, <?= htmlspecialchars(json_encode($d['name']), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode($d['code']), ENT_QUOTES) ?>)"><?= icon('trash', 15) ?></button>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- Add dialog -->
<dialog class="modal" id="addDlg">
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="create">
    <div class="modal-head"><div><h3>Add Department</h3><div class="msub">Name and code must both be unique.</div></div></div>
    <div class="modal-body">
      <div class="field"><label>Department name <span class="req">*</span></label>
        <input class="input" name="name" placeholder="e.g. Computer Science and Business Systems" required></div>
      <div class="field"><label>Code <span class="req">*</span></label>
        <input class="input" name="code" placeholder="e.g. CSBS" required>
        <div class="hint">Printed on the report header.</div></div>
    </div>
    <div class="modal-foot">
      <button type="button" class="btn btn-outline btn-sm" onclick="this.closest('dialog').close()">Cancel</button>
      <button type="submit" class="btn btn-primary btn-sm">Add Department</button>
    </div>
  </form>
</dialog>

<!-- Edit dialog -->
<dialog class="modal" id="editDlg">
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="update">
    <input type="hidden" name="id" id="edit-id">
    <div class="modal-head"><div><h3>Edit Department</h3><div class="msub">Name and code must both be unique.</div></div></div>
    <div class="modal-body">
      <div class="field"><label>Department name <span class="req">*</span></label>
        <input class="input" name="name" id="edit-name" required></div>
      <div class="field"><label>Code <span class="req">*</span></label>
        <input class="input" name="code" id="edit-code" required></div>
    </div>
    <div class="modal-foot">
      <button type="button" class="btn btn-outline btn-sm" onclick="this.closest('dialog').close()">Cancel</button>
      <button type="submit" class="btn btn-primary btn-sm">Save Changes</button>
    </div>
  </form>
</dialog>

<!-- Delete dialog -->
<dialog class="modal" id="delDlg" style="max-width:28rem">
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="id" id="del-id">
    <div class="modal-head"><div><h3>Delete department?</h3></div></div>
    <div class="modal-body">
      <p style="color:var(--ink-muted); font-size:14px; margin:0">
        <strong id="del-label" style="color:var(--ink)"></strong>
        will be removed from the department list and the dashboard filter.
        Academic records already submitted under it are kept.
      </p>
    </div>
    <div class="modal-foot">
      <button type="button" class="btn btn-outline btn-sm" onclick="this.closest('dialog').close()">Cancel</button>
      <button type="submit" class="btn btn-danger btn-sm">Delete</button>
    </div>
  </form>
</dialog>

<script>
  function editDept(id, name, code) {
    document.getElementById('edit-id').value = id;
    document.getElementById('edit-name').value = name;
    document.getElementById('edit-code').value = code;
    document.getElementById('editDlg').showModal();
  }
  function deleteDept(id, name, code) {
    document.getElementById('del-id').value = id;
    document.getElementById('del-label').textContent = name + ' (' + code + ')';
    document.getElementById('delDlg').showModal();
  }
</script>

<?php require __DIR__ . '/inc/footer.php'; ?>
