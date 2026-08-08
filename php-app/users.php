<?php
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/models/User.php';
require_once __DIR__ . '/models/Department.php';

$user = require_role(['Admin']);
require_module('users');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) input('action');

    if ($action === 'create') {
        [$ok, $msg] = user_create((string)input('name'),(string)input('email'),(string)input('password'),(string)input('role'),(string)input('department'),(string)input('phone'));
        flash($ok ? 'success' : 'error', $msg);
    } elseif ($action === 'update') {
        [$ok, $msg] = user_update((int)input('id'),(string)input('name'),(string)input('email'),(string)input('role'),(string)input('department'),(string)input('phone'),(int)input('status'));
        flash($ok ? 'success' : 'error', $msg);
    } elseif ($action === 'delete') {
        [$ok, $msg] = user_delete((int) input('id'));
        flash($ok ? 'success' : 'error', $msg);
    } elseif ($action === 'reset_password') {
        [$ok, $msg] = user_reset_password((int)input('id'), (string)input('new_password'));
        flash($ok ? 'success' : 'error', $msg);
    }
    redirect('/users.php');
}

$roleFilter = trim((string)($_GET['role']??''));
$deptFilter = trim((string)($_GET['department']??''));
$search = trim((string)($_GET['q']??''));
$users = users_all($roleFilter?:null, $deptFilter?:null, $search?:null);
$departments = departments_all();
$allRoles = ['Admin','Director','HoD','Coordinator','Faculty'];

$pageTitle = 'Users'; $breadcrumb = 'Users';
require __DIR__ . '/inc/header.php';
?>

<div class="page-head">
  <div><h1>Users</h1><div class="sub">Manage accounts · <?= count($users) ?> user<?= count($users)!==1?'s':'' ?></div></div>
  <div class="actions"><button class="btn btn-primary btn-sm" onclick="document.getElementById('addDlg').showModal()"><?= icon('plus') ?> Add User</button></div>
</div>

<div class="card" style="margin-bottom:16px"><div class="card-body" style="padding:12px 20px">
  <form method="get" class="flex gap-3 items-center" style="flex-wrap:wrap">
    <input class="input" type="text" name="q" placeholder="Search…" value="<?= e($search) ?>" style="flex:1;min-width:180px;height:36px">
    <select class="select" name="role" style="min-width:130px;height:36px" onchange="this.form.submit()"><option value="">All Roles</option>
      <?php foreach($allRoles as $r):?><option value="<?=e($r)?>" <?=$roleFilter===$r?'selected':''?>><?=e($r)?></option><?php endforeach;?></select>
    <select class="select" name="department" style="min-width:150px;height:36px" onchange="this.form.submit()"><option value="">All Depts</option>
      <?php foreach($departments as $d):?><option value="<?=e($d['name'])?>" <?=$deptFilter===$d['name']?'selected':''?>><?=e($d['name'])?></option><?php endforeach;?></select>
    <button type="submit" class="btn btn-outline btn-sm">Go</button>
    <?php if($search||$roleFilter||$deptFilter):?><a href="<?=e(url('users.php'))?>" class="btn btn-ghost btn-sm">Clear</a><?php endif;?>
  </form>
</div></div>

<div class="card"><div class="card-body" style="padding:0">
  <?php if(empty($users)):?>
    <div class="empty"><div class="ic"><?=icon('users',20)?></div><p>No users found</p></div>
  <?php else:?>
    <div class="table-wrap"><table class="data" style="min-width:700px"><thead><tr>
      <th style="padding-left:24px">User</th><th>Role</th><th>Department</th><th>Status</th><th>Joined</th><th class="num" style="padding-right:24px">Actions</th>
    </tr></thead><tbody>
    <?php foreach($users as $u): $rb=['Admin'=>'danger','Director'=>'info','HoD'=>'warning','Coordinator'=>'brand','Faculty'=>'neutral'];?>
      <tr>
        <td style="padding-left:24px"><div class="flex items-center gap-3"><div class="avatar-dark" style="width:36px;height:36px;font-size:11px;flex-shrink:0"><?=e(initials($u['name']))?></div><div><div style="font-weight:500"><?=e($u['name'])?></div><div class="card-sub"><?=e($u['email'])?></div></div></div></td>
        <td><span class="badge badge-<?=$rb[$u['role']]??'neutral'?>"><?=e($u['role'])?></span></td>
        <td style="color:var(--ink-muted)"><?=$u['department']?e($u['department']):'—'?></td>
        <td><?=(int)$u['status']===1?'<span class="badge badge-success">Active</span>':'<span class="badge badge-danger">Inactive</span>'?></td>
        <td class="card-sub"><?=e(date('d M Y',strtotime($u['created_at'])))?></td>
        <td class="num" style="padding-right:24px"><div class="dept-actions" style="justify-content:flex-end">
          <button class="mini-btn" title="Edit" onclick='editUser(<?=(int)$u["id"]?>,<?=e(json_encode($u))?>)'><?=icon('pencil',15)?></button>
          <button class="mini-btn" title="Reset Password" onclick='resetPw(<?=(int)$u["id"]?>,<?=htmlspecialchars(json_encode($u["name"]),ENT_QUOTES)?>)'><?=icon('key',15)?></button>
          <?php if((int)$u['id']!==$user['id']):?><button class="mini-btn danger" title="Delete" onclick='deleteUser(<?=(int)$u["id"]?>,<?=htmlspecialchars(json_encode($u["name"]),ENT_QUOTES)?>)'><?=icon('trash',15)?></button><?php endif;?>
        </div></td>
      </tr>
    <?php endforeach;?>
    </tbody></table></div>
  <?php endif;?>
</div></div>

<!-- Add dialog -->
<dialog class="modal" id="addDlg" style="max-width:36rem"><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="create">
  <div class="modal-head"><div><h3>Add User</h3></div></div>
  <div class="modal-body" style="display:grid;grid-template-columns:1fr 1fr;gap:0 16px">
    <div class="field"><label>Name <span class="req">*</span></label><input class="input" name="name" required></div>
    <div class="field"><label>Email <span class="req">*</span></label><input class="input" type="email" name="email" required></div>
    <div class="field"><label>Password <span class="req">*</span></label><input class="input" type="password" name="password" required></div>
    <div class="field"><label>Phone</label><input class="input" name="phone"></div>
    <div class="field"><label>Role <span class="req">*</span></label><select class="select" name="role"><?php foreach($allRoles as $r):?><option><?=e($r)?></option><?php endforeach;?></select></div>
    <div class="field"><label>Department</label><select class="select" name="department"><option value="">None</option><?php foreach($departments as $d):?><option value="<?=e($d['name'])?>"><?=e($d['name'])?></option><?php endforeach;?></select></div>
  </div>
  <div class="modal-foot"><button type="button" class="btn btn-outline btn-sm" onclick="this.closest('dialog').close()">Cancel</button><button type="submit" class="btn btn-primary btn-sm">Add User</button></div>
</form></dialog>

<!-- Edit dialog -->
<dialog class="modal" id="editDlg" style="max-width:36rem"><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="update"><input type="hidden" name="id" id="edit-id">
  <div class="modal-head"><div><h3>Edit User</h3></div></div>
  <div class="modal-body" style="display:grid;grid-template-columns:1fr 1fr;gap:0 16px">
    <div class="field"><label>Name</label><input class="input" name="name" id="edit-name" required></div>
    <div class="field"><label>Email</label><input class="input" type="email" name="email" id="edit-email" required></div>
    <div class="field"><label>Phone</label><input class="input" name="phone" id="edit-phone"></div>
    <div class="field"><label>Role</label><select class="select" name="role" id="edit-role"><?php foreach($allRoles as $r):?><option><?=e($r)?></option><?php endforeach;?></select></div>
    <div class="field"><label>Department</label><select class="select" name="department" id="edit-dept"><option value="">None</option><?php foreach($departments as $d):?><option value="<?=e($d['name'])?>"><?=e($d['name'])?></option><?php endforeach;?></select></div>
    <div class="field"><label>Status</label><select class="select" name="status" id="edit-status"><option value="1">Active</option><option value="0">Inactive</option></select></div>
  </div>
  <div class="modal-foot"><button type="button" class="btn btn-outline btn-sm" onclick="this.closest('dialog').close()">Cancel</button><button type="submit" class="btn btn-primary btn-sm">Save</button></div>
</form></dialog>

<!-- Reset Password dialog -->
<dialog class="modal" id="pwDlg" style="max-width:28rem"><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="reset_password"><input type="hidden" name="id" id="pw-id">
  <div class="modal-head"><div><h3>Reset Password</h3><div class="msub">For <strong id="pw-name"></strong></div></div></div>
  <div class="modal-body"><div class="field"><label>New password <span class="req">*</span></label><input class="input" type="password" name="new_password" required minlength="6"></div></div>
  <div class="modal-foot"><button type="button" class="btn btn-outline btn-sm" onclick="this.closest('dialog').close()">Cancel</button><button type="submit" class="btn btn-primary btn-sm">Reset</button></div>
</form></dialog>

<!-- Delete dialog -->
<dialog class="modal" id="delDlg" style="max-width:28rem"><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" id="del-id">
  <div class="modal-head"><div><h3>Delete user?</h3></div></div>
  <div class="modal-body"><p style="color:var(--ink-muted);font-size:14px;margin:0"><strong id="del-name" style="color:var(--ink)"></strong> will be permanently removed.</p></div>
  <div class="modal-foot"><button type="button" class="btn btn-outline btn-sm" onclick="this.closest('dialog').close()">Cancel</button><button type="submit" class="btn btn-danger btn-sm">Delete</button></div>
</form></dialog>

<script>
function editUser(id,d){document.getElementById('edit-id').value=id;document.getElementById('edit-name').value=d.name;document.getElementById('edit-email').value=d.email;document.getElementById('edit-phone').value=d.phone||'';document.getElementById('edit-role').value=d.role;document.getElementById('edit-dept').value=d.department||'';document.getElementById('edit-status').value=d.status;document.getElementById('editDlg').showModal();}
function resetPw(id,n){document.getElementById('pw-id').value=id;document.getElementById('pw-name').textContent=n;document.getElementById('pwDlg').showModal();}
function deleteUser(id,n){document.getElementById('del-id').value=id;document.getElementById('del-name').textContent=n;document.getElementById('delDlg').showModal();}
</script>
<?php require __DIR__ . '/inc/footer.php'; ?>
