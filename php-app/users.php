<?php
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/models/User.php';
require_once __DIR__ . '/models/Department.php';

$user = require_role(['Admin']);
require_module('users');

$allRoles = ['Admin','Principal','Dean','HoD','Coordinator','Faculty'];
// A Principal account may still be stored under the older name "Director";
// auth treats the two as one role (see inc/auth.php).
$validRoles = array_merge($allRoles, ['Director']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) input('action');

    if (in_array($action, ['create', 'update'], true) && !in_array((string) input('role'), $validRoles, true)) {
        // Never write a blank or unknown role: that account could no longer sign in.
        flash('error', 'Choose a valid role for this user.');
    } elseif ($action === 'create') {
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

    // Back to the same filtered view the change was made from (the dialogs
    // post to the current URL, so the filters are still in the query string).
    $back = array_filter(['q' => $_GET['q'] ?? '', 'role' => $_GET['role'] ?? '', 'department' => $_GET['department'] ?? ''],
                         fn($v) => is_string($v) && trim($v) !== '');
    redirect('/users.php' . ($back ? '?' . http_build_query($back) : ''));
}

$roleFilter = trim((string)($_GET['role']??''));
$deptFilter = trim((string)($_GET['department']??''));
$search = trim((string)($_GET['q']??''));
$users = users_all($roleFilter?:null, $deptFilter?:null, $search?:null);
$departments = departments_all();
$uActive = ($search ? 1 : 0) + ($roleFilter ? 1 : 0) + ($deptFilter ? 1 : 0);

// ---- Categorise by department -------------------------------------------------
// Institution-level accounts (no department) come first, then each department
// in the Departments list's order, then any department a user still carries
// that is no longer on that list — so nobody silently drops out of view.
// Within a group: by role, most senior first, then by name.
$roleRank  = ['Admin' => 0, 'Principal' => 1, 'Director' => 1, 'Dean' => 2, 'HoD' => 3, 'Coordinator' => 4, 'Faculty' => 5];
$shownRole = fn(string $r) => $r === 'Director' ? 'Principal' : $r;
$deptKey   = fn(string $name) => mb_strtolower(trim($name));

$deptIndex = [];
$deptOrder = ['__institution' => -1];
foreach ($departments as $i => $d) {
    $deptIndex[$deptKey($d['name'])] = $d;
    $deptOrder[$deptKey($d['name'])] = $i;
}

$userGroups = [];
foreach ($users as $u) {
    $raw = trim((string) ($u['department'] ?? ''));
    $key = $raw === '' ? '__institution' : $deptKey($raw);
    if (!isset($userGroups[$key])) {
        if ($key === '__institution') {
            $userGroups[$key] = ['kind' => 'institution', 'name' => 'Institution', 'code' => null, 'dept' => ''];
        } elseif (isset($deptIndex[$key])) {
            $userGroups[$key] = ['kind' => 'department', 'name' => $deptIndex[$key]['name'],
                                 'code' => $deptIndex[$key]['code'] ?: $deptIndex[$key]['name'], 'dept' => $deptIndex[$key]['name']];
        } else {
            $userGroups[$key] = ['kind' => 'unlisted', 'name' => $raw, 'code' => null, 'dept' => $raw];
        }
        $userGroups[$key] += ['key' => $key, 'users' => []];
    }
    $userGroups[$key]['users'][] = $u;
}

uasort($userGroups, fn($a, $b) => (($deptOrder[$a['key']] ?? PHP_INT_MAX) <=> ($deptOrder[$b['key']] ?? PHP_INT_MAX))
                                  ?: strcasecmp($a['name'], $b['name']));

foreach ($userGroups as &$g) {
    usort($g['users'], fn($x, $y) => (($roleRank[$x['role']] ?? 9) <=> ($roleRank[$y['role']] ?? 9))
                                     ?: strcasecmp($x['name'], $y['name']));
    $g['roles'] = [];
    foreach ($g['users'] as $u) {
        $r = $shownRole($u['role']);
        $g['roles'][$r] = ($g['roles'][$r] ?? 0) + 1;
    }
    $g['inactive'] = count(array_filter($g['users'], fn($u) => (int) $u['status'] !== 1));
    $g['anchor']   = 'dept-' . trim(preg_replace('/[^a-z0-9]+/', '-', $g['key'] === '__institution' ? 'institution' : $g['key']), '-');
}
unset($g);

$deptGroupCount = count(array_filter($userGroups, fn($g) => $g['kind'] !== 'institution'));
// Departments with nobody in them. Only worth listing on the unfiltered view —
// under a filter, "no match" is not the same as "no users".
$emptyDepts = $uActive ? [] : array_values(array_filter($departments, fn($d) => !isset($userGroups[$deptKey($d['name'])])));

$pageTitle = 'Users'; $breadcrumb = 'Users';
require __DIR__ . '/inc/header.php';

// After a change, the page can return to the row it was made on — unless the
// change failed, when the message at the top must be the first thing seen.
$hadError = (bool) array_filter($flashes, fn($f) => ($f['type'] ?? '') === 'error');
?>

<div class="page-head">
  <div><h1>Users</h1>
    <div class="sub">Manage accounts · <?= count($users) ?> user<?= count($users) !== 1 ? 's' : '' ?><?php if ($deptGroupCount): ?> across <?= $deptGroupCount ?> department<?= $deptGroupCount !== 1 ? 's' : '' ?><?php endif; ?></div>
  </div>
  <div class="actions"><button class="btn btn-primary btn-sm" onclick="openAdd('')"><?= icon('plus') ?> Add User</button></div>
</div>

<form method="get" class="fbar">
  <label class="fb-field fb-search">
    <?= icon('search', 15) ?>
    <input type="search" name="q" value="<?= e($search) ?>" placeholder="Search name or email…" aria-label="Search users">
    <button type="submit" class="fb-go" title="Search" aria-label="Search"><?= icon('arrow-right', 14) ?></button>
  </label>

  <label class="fb-field"><span class="fb-k">Role</span>
    <select name="role" onchange="this.form.submit()">
      <option value="">All</option>
      <?php foreach ($allRoles as $r): ?><option value="<?= e($r) ?>" <?= $roleFilter === $r ? 'selected' : '' ?>><?= e($r) ?></option><?php endforeach; ?>
    </select>
  </label>

  <label class="fb-field"><span class="fb-k">Department</span>
    <select name="department" onchange="this.form.submit()">
      <option value="">All</option>
      <?php foreach ($departments as $d): ?><option value="<?= e($d['name']) ?>" <?= $deptFilter === $d['name'] ? 'selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?>
    </select>
  </label>

  <span class="fbar-end">
    <?php if ($uActive): ?>
      <span class="fbar-count"><?= (int) $uActive ?> active</span>
      <a class="fbar-clear" href="<?= e(url('users.php')) ?>"><?= icon('x', 13) ?> Clear all</a>
    <?php else: ?>
      <span class="fbar-note">Everyone</span>
    <?php endif; ?>
  </span>
</form>

<?php if (empty($users)): ?>
  <div class="card"><div class="empty"><div class="ic"><?= icon('users', 20) ?></div><p>No users found</p>
    <?php if ($uActive): ?><div class="note">Nobody matches these filters.</div><?php endif; ?></div></div>
<?php else: ?>

  <div class="ug-toolbar">
    <span class="ug-caption"><?= icon('layers', 14) ?> Grouped by department</span>
    <button type="button" class="btn btn-ghost btn-sm" id="ugToggle"><?= icon('chevron-down', 14) ?> <span>Collapse all</span></button>
  </div>

  <?php $rb = ['Admin'=>'danger','Principal'=>'info','Dean'=>'brand','HoD'=>'warning','Coordinator'=>'brand','Faculty'=>'neutral']; ?>
  <?php foreach ($userGroups as $g):
    // A department with no HoD has nobody to review its records: worth flagging,
    // but only when the list is not already narrowed to other roles.
    $noHod = $g['kind'] !== 'institution' && !$roleFilter && !$search && empty($g['roles']['HoD']);
  ?>
    <details class="card tg-group ug-group" id="<?= e($g['anchor']) ?>" data-ug-key="<?= e($g['key']) ?>" open>
      <summary>
        <?php if ($g['kind'] === 'institution'): ?>
          <span class="ug-code inst" aria-hidden="true"><?= icon('shield', 17) ?></span>
        <?php elseif ($g['kind'] === 'unlisted'): ?>
          <span class="ug-code warn" aria-hidden="true"><?= icon('alert-triangle', 17) ?></span>
        <?php else: ?>
          <span class="ug-code" aria-hidden="true"><?= e($g['code']) ?></span>
        <?php endif; ?>

        <span class="ug-head">
          <span class="ug-name">
            <?= e($g['name']) ?>
            <?php if ($g['kind'] === 'institution'): ?><span class="ug-tag">Not tied to a department</span><?php endif; ?>
            <?php if ($g['kind'] === 'unlisted'): ?><span class="badge badge-warning">Not on the Departments list</span><?php endif; ?>
            <?php if ($noHod): ?><span class="badge badge-warning" title="Nobody in this department can review its records"><?= icon('alert-triangle', 11) ?> No HoD</span><?php endif; ?>
          </span>
          <span class="ug-roles">
            <?php $bits = []; foreach ($roleRank as $r => $_) { if (!empty($g['roles'][$r]) && $r !== 'Director') $bits[] = '<b>' . (int) $g['roles'][$r] . '</b> ' . e($r); } ?>
            <?= implode(' <span class="ug-dot">·</span> ', $bits) ?>
          </span>
        </span>

        <span class="ug-meta">
          <?php if ($g['inactive']): ?><span class="badge badge-danger"><?= (int) $g['inactive'] ?> inactive</span><?php endif; ?>
          <span class="ug-count"><b><?= count($g['users']) ?></b> <span class="ug-count-word">user<?= count($g['users']) !== 1 ? 's' : '' ?></span></span>
          <button type="button" class="mini-btn ug-add" title="Add a user<?= $g['dept'] !== '' ? ' to ' . e($g['name']) : '' ?>"
                  aria-label="Add a user<?= $g['dept'] !== '' ? ' to ' . e($g['name']) : '' ?>"
                  onclick="event.preventDefault(); event.stopPropagation(); openAdd(<?= e(json_encode($g['dept'])) ?>)"><?= icon('plus', 15) ?></button>
          <span class="tg-chev"><?= icon('chevron', 16) ?></span>
        </span>
      </summary>

      <div class="table-wrap">
        <table class="data ug-table">
          <colgroup><col><col style="width:150px"><col style="width:110px"><col style="width:170px"><col style="width:132px"></colgroup>
          <tbody>
          <?php foreach ($g['users'] as $u): $role = $shownRole($u['role']); ?>
            <tr id="u-<?= (int) $u['id'] ?>">
              <td>
                <div class="flex items-center gap-3 min-w-0">
                  <div class="avatar-dark avatar-sm"><?= e(initials($u['name'])) ?></div>
                  <div class="min-w-0">
                    <div class="ug-person truncate" title="<?= e($u['name']) ?>"><?= e($u['name']) ?><?php if ((int) $u['id'] === (int) $user['id']): ?> <span class="ug-you">You</span><?php endif; ?></div>
                    <div class="card-sub truncate" title="<?= e($u['email']) ?>"><?= e($u['email']) ?></div>
                  </div>
                </div>
              </td>
              <td><span class="badge badge-<?= $rb[$role] ?? 'neutral' ?>"><?= e($role) ?></span></td>
              <td><?= (int) $u['status'] === 1 ? '<span class="badge badge-success"><span class="dot"></span>Active</span>' : '<span class="badge badge-danger"><span class="dot"></span>Inactive</span>' ?></td>
              <td class="card-sub">Joined <?= e(date('d M Y', strtotime($u['created_at']))) ?></td>
              <td class="num">
                <div class="dept-actions" style="justify-content:flex-end">
                  <button class="mini-btn" title="Edit" onclick='editUser(<?= (int) $u["id"] ?>,<?= e(json_encode($u)) ?>)'><?= icon('pencil', 15) ?></button>
                  <button class="mini-btn" title="Reset Password" onclick='resetPw(<?= (int) $u["id"] ?>,<?= htmlspecialchars(json_encode($u["name"]), ENT_QUOTES) ?>)'><?= icon('key', 15) ?></button>
                  <?php if ((int) $u['id'] !== (int) $user['id']): ?><button class="mini-btn danger" title="Delete" onclick='deleteUser(<?= (int) $u["id"] ?>,<?= htmlspecialchars(json_encode($u["name"]), ENT_QUOTES) ?>)'><?= icon('trash', 15) ?></button><?php else: ?><span class="mini-btn ug-slot" aria-hidden="true"></span><?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </details>
  <?php endforeach; ?>

  <?php if ($emptyDepts): ?>
    <div class="ug-empty">
      <span class="ug-empty-k"><?= icon('info', 15) ?> No users yet</span>
      <span class="ug-empty-list">
        <?php foreach ($emptyDepts as $d): ?>
          <button type="button" class="ug-chip" onclick="openAdd(<?= e(json_encode($d['name'])) ?>)"
                  title="Add a user to <?= e($d['name']) ?>"><?= icon('plus', 12) ?> <?= e($d['name']) ?></button>
        <?php endforeach; ?>
      </span>
    </div>
  <?php endif; ?>

<?php endif; ?>

<!-- Add dialog -->
<dialog class="modal" id="addDlg" style="max-width:36rem"><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="create">
  <div class="modal-head"><div><h3>Add User</h3><div class="msub" id="add-sub"></div></div></div>
  <div class="modal-body" style="display:grid;grid-template-columns:1fr 1fr;gap:0 16px">
    <div class="field"><label>Name <span class="req">*</span></label><input class="input" name="name" required></div>
    <div class="field"><label>Email <span class="req">*</span></label><input class="input" type="email" name="email" required></div>
    <div class="field"><label>Password <span class="req">*</span></label><input class="input" type="password" name="password" required></div>
    <div class="field"><label>Phone</label><input class="input" name="phone"></div>
    <div class="field"><label>Role <span class="req">*</span></label><select class="select" name="role" id="add-role"><?php foreach($allRoles as $r):?><option><?=e($r)?></option><?php endforeach;?></select></div>
    <div class="field"><label>Department</label><select class="select" name="department" id="add-dept"><option value="">None</option><?php foreach($departments as $d):?><option value="<?=e($d['name'])?>"><?=e($d['name'])?></option><?php endforeach;?></select></div>
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
function editUser(id,d){
  document.getElementById('edit-id').value=id;
  document.getElementById('edit-name').value=d.name;
  document.getElementById('edit-email').value=d.email;
  document.getElementById('edit-phone').value=d.phone||'';
  // "Director" is the older name for Principal; the list only offers Principal,
  // and a role the list can't show would be submitted blank.
  document.getElementById('edit-role').value=(d.role==='Director'?'Principal':d.role);
  document.getElementById('edit-dept').value=d.department||'';
  document.getElementById('edit-status').value=d.status;
  document.getElementById('editDlg').showModal();
}
function resetPw(id,n){document.getElementById('pw-id').value=id;document.getElementById('pw-name').textContent=n;document.getElementById('pwDlg').showModal();}
function deleteUser(id,n){document.getElementById('del-id').value=id;document.getElementById('del-name').textContent=n;document.getElementById('delDlg').showModal();}

/* Add, optionally straight into a department (from a group header or an
   empty-department chip). A user added to a department is most often
   Faculty, so that becomes the starting role there. */
function openAdd(dept){
  var dlg=document.getElementById('addDlg');
  document.getElementById('add-dept').value=dept||'';
  if(dept){ document.getElementById('add-role').value='Faculty'; }
  document.getElementById('add-sub').textContent=dept?('Into '+dept):'';
  dlg.showModal();
}

/* ---- Department groups: collapse/expand, remembered between visits -------- */
(function(){
  var groups=Array.prototype.slice.call(document.querySelectorAll('.ug-group'));
  var toggle=document.getElementById('ugToggle');
  if(!groups.length||!toggle) return;
  var KEY='atts.users.collapsed';
  var filtered=<?= $uActive ? 'true' : 'false' ?>;   // a search/filter always shows every match
  var collapsed={};
  try{ collapsed=JSON.parse(localStorage.getItem(KEY)||'{}')||{}; }catch(e){}
  if(!filtered){ groups.forEach(function(g){ if(collapsed[g.dataset.ugKey]) g.open=false; }); }

  function sync(){
    var anyOpen=groups.some(function(g){ return g.open; });
    toggle.querySelector('span').textContent=anyOpen?'Collapse all':'Expand all';
    toggle.classList.toggle('is-collapsed',!anyOpen);
  }
  groups.forEach(function(g){
    g.addEventListener('toggle',function(){
      if(!filtered){
        if(g.open) delete collapsed[g.dataset.ugKey]; else collapsed[g.dataset.ugKey]=true;
        try{ localStorage.setItem(KEY,JSON.stringify(collapsed)); }catch(e){}
      }
      sync();
    });
  });
  toggle.addEventListener('click',function(){
    var open=!groups.some(function(g){ return g.open; });
    groups.forEach(function(g){ g.open=open; });
  });
  sync();
})();

/* ---- Come back to the row that was just changed ---------------------------
   The page reloads after every save; on a long grouped list that would drop
   the Admin back at the top. Edit and password reset return to (and briefly
   mark) the row; delete returns to where the list was. A failed save stays at
   the top, where its error message is. */
(function(){
  var KEY='atts.users.return';
  document.querySelectorAll('dialog form').forEach(function(f){
    f.addEventListener('submit',function(){
      var action=(f.querySelector('input[name=action]')||{}).value;
      var id=(f.querySelector('input[name=id]')||{}).value;
      try{ sessionStorage.setItem(KEY,JSON.stringify({action:action,id:id,y:window.scrollY})); }catch(e){}
    });
  });
  var saved=null;
  try{ saved=JSON.parse(sessionStorage.getItem(KEY)||'null'); sessionStorage.removeItem(KEY); }catch(e){}
  if(!saved||<?= $hadError ? 'true' : 'false' ?>) return;
  if(saved.action==='update'||saved.action==='reset_password'){
    var row=document.getElementById('u-'+saved.id);
    if(!row) return;
    var grp=row.closest('details'); if(grp) grp.open=true;
    row.scrollIntoView({block:'center'});
    row.classList.add('ug-flash');
  }else if(saved.action==='delete'){
    window.scrollTo(0,saved.y||0);
  }
})();
</script>
<?php require __DIR__ . '/inc/footer.php'; ?>
