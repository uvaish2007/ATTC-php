<?php
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/models/Record.php';
require_once __DIR__ . '/models/Department.php';

$user = require_role(['Admin', 'HoD', 'Dean', 'Coordinator']);

// A Coordinator or HoD may only review their own department; Admin/Dean may
// review any. The Coordinator clears the first stage (faculty "Submitted"),
// the HoD the second.
$scopeDept = in_array($user['role'], ['HoD', 'Coordinator'], true) ? ($user['department'] ?? null) : null;

// The system-wide active academic year — every approval queue and every
// approve/reject action is scoped to THIS year, never a client-supplied one
// (section 9: an approval page for one year must never touch a record from
// another year).
$activeYear = active_academic_year();

// Handle approve/reject (single) and bulk approve-all-in-department.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if ($user['role'] !== 'Admin' && academic_year_is_locked($activeYear)) {
        flash('error', "Academic year {$activeYear} cycle is locked by Administrator. Approvals are frozen for all roles.");
        redirect('/approvals.php');
    }
    $action = (string) input('review_action');

    if ($action === 'approve_all') {
        // $scopeDept is enforced inside records_bulk_approve, so an HoD can only
        // ever clear their own department.
        [$ok, $msg] = records_bulk_approve((string) input('department'), (int) $user['id'], $scopeDept, $user['role'], $activeYear);
    } else {
        $type   = (string) input('record_type');
        $id     = (int)    input('record_id');
        $remark = (string) input('review_remark');
        // $scopeDept and $activeYear are enforced inside record_review, so a
        // forged record_id for another department or year cannot be approved
        // from here.
        [$ok, $msg] = record_review($type, $id, $action, $remark, $user['id'], $scopeDept, $user['role'], $activeYear);
    }

    flash($ok ? 'success' : 'error', $msg);
    redirect('/approvals.php');
}

$types       = record_types();
$departments = departments_all();

// Filters: Admin may narrow by department; anyone may narrow by type and search.
$filterDept = in_array($user['role'], ['Admin', 'Dean'], true) ? (trim((string) input('department')) ?: null) : null;
$filterType = (string) input('type');
if (!isset($types[$filterType])) { $filterType = ''; }
$search = trim((string) input('q'));

// Department scope: an HoD is pinned to their own; an Admin uses the filter.
$effectiveDept = $scopeDept ?? $filterDept;

$records = pending_records($effectiveDept, null, $user['role'], $activeYear);

// Type + free-text narrowing happen in PHP over the already-scoped list.
if ($filterType !== '') {
    $records = array_values(array_filter($records, fn($r) => $r['_type_key'] === $filterType));
}
if ($search !== '') {
    $needle  = mb_strtolower($search);
    $records = array_values(array_filter($records, function ($r) use ($needle) {
        $hay = mb_strtolower(($r['_title'] ?? '') . ' ' . ($r['faculty_name'] ?? $r['candidate_name'] ?? $r['student_name'] ?? '') . ' ' . ($r['department'] ?? ''));
        return mb_strpos($hay, $needle) !== false;
    }));
}

$hasFilter = $filterDept || $filterType !== '' || $search !== '';

$isHod = $user['role'] === 'HoD';
$pageTitle  = $isHod ? 'Review Records' : 'Approvals';
$breadcrumb = $isHod ? 'Review Records' : 'Approvals';
require __DIR__ . '/inc/header.php';
?>

<?php $activeCount = ($filterDept ? 1 : 0) + ($filterType !== '' ? 1 : 0) + ($search !== '' ? 1 : 0); ?>
<div class="page-head">
  <div>
    <h1><?= $isHod ? 'Review Records' : 'Pending Approvals' ?></h1>
    <div class="sub"><?= count($records) ?> record<?= count($records)!==1?'s':'' ?> <?= $isHod ? 'under review' : 'awaiting review' ?><?= $scopeDept ? ' · ' . e($scopeDept) : '' ?></div>
  </div>

</div>

<form method="get" class="fbar">
  <span class="fbar-title"><?= icon('filter', 14) ?> Filters</span>

  <?php if (in_array($user['role'], ['Admin', 'Dean'], true)): ?>
    <label class="fb-field"><span class="fb-k">Department</span>
      <select name="department" onchange="this.form.submit()">
        <option value="">All</option>
        <?php foreach ($departments as $d): ?>
          <option value="<?= e($d['name']) ?>" <?= $filterDept === $d['name'] ? 'selected' : '' ?>><?= e($d['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
  <?php endif; ?>

  <label class="fb-field"><span class="fb-k">Record type</span>
    <select name="type" onchange="this.form.submit()">
      <option value="">All</option>
      <?php foreach ($types as $key => $t): ?>
        <option value="<?= e($key) ?>" <?= $filterType === $key ? 'selected' : '' ?>><?= e($t['label']) ?></option>
      <?php endforeach; ?>
    </select>
  </label>

  <label class="fb-field fb-search">
    <?= icon('search', 15) ?>
    <input type="search" name="q" value="<?= e($search) ?>" placeholder="Search title, person or department…" aria-label="Search approvals">
    <button type="submit" class="fb-go" title="Search" aria-label="Search"><?= icon('arrow-right', 14) ?></button>
  </label>

    <span class="fbar-end">
      <?php if ($activeCount): ?>
        <span class="fbar-count"><?= (int) ($activeCount) ?> active</span>
        <a class="fbar-clear" href="<?= e(url('approvals.php')) ?>"><?= icon('x', 13) ?> Clear all</a>
      <?php else: ?>
        <span class="fbar-note">Showing everything in scope</span>
      <?php endif; ?>
    </span>
</form>

<?php $isYearLocked = academic_year_is_locked($activeYear); ?>
<?php if ($isYearLocked): ?>
  <div style="background:#FEF2F2;border:1px solid #FECACA;border-left:4px solid #DC2626;border-radius:10px;padding:14px 18px;margin-bottom:20px;display:flex;align-items:center;gap:12px">
    <div style="width:36px;height:36px;border-radius:8px;background:#FEE2E2;display:flex;align-items:center;justify-content:center;flex-shrink:0;color:#DC2626">
      <?= icon('lock', 20) ?>
    </div>
    <div style="flex:1">
      <div style="font-weight:700;font-size:13px;color:#991B1B">Academic Year <?= e($activeYear) ?> Cycle is Locked</div>
      <div style="font-size:12px;color:#B91C1C;margin-top:2px">
        The Administrator has locked this academic year cycle following an Executive Meeting. Record reviews and approvals are frozen across all roles. <?= $user['role'] === 'Admin' ? 'As an Administrator, you retain review authority.' : 'Approvals cannot be made until the cycle is unlocked by an Administrator.' ?>
      </div>
    </div>
  </div>
<?php endif; ?>

<?php if (empty($records)): ?>
  <div class="card"><div class="card-body" style="padding:0">
    <div class="empty" style="padding:80px 24px">
      <?php if ($hasFilter): ?>
        <div class="ic" style="width:56px; height:56px"><?= icon('filter', 24) ?></div>
        <p style="font-size:16px; font-weight:600">No records match these filters</p>
        <div class="note">Widen or <a href="<?= e(url('approvals.php')) ?>">clear</a> them to see every pending record.</div>
      <?php else: ?>
        <div class="ic" style="background:#ECFDF5; color:#047857; width:56px; height:56px"><?= icon('check', 24) ?></div>
        <p style="font-size:16px; font-weight:600">All caught up!</p>
        <div class="note">No records pending review right now.</div>
      <?php endif; ?>
    </div>
  </div></div>
<?php else: ?>
  <?php
    // Group pending records by department so a reviewer works one department at
    // a time — and can clear a whole department in a single click.
    $byDept = [];
    foreach ($records as $r) {
        $k = ($r['department'] ?? '') !== '' ? $r['department'] : 'Unassigned';
        $byDept[$k][] = $r;
    }
    ksort($byDept, SORT_NATURAL | SORT_FLAG_CASE);
    $single = count($byDept) === 1;   // a HoD sees only their own dept — open it
  ?>
  <?php foreach ($byDept as $deptName => $deptRecs): ?>
    <details class="card tg-group ap-group"<?= $single ? ' open' : '' ?>>
      <summary class="tg-group-head">
        <span class="tg-dept"><?= icon('building', 15) ?> <?= e($deptName) ?></span>
        <span class="badge badge-info"><?= count($deptRecs) ?> <?= $isHod ? 'under review' : 'pending' ?></span>
        <span class="ap-head-actions">
          <?php if (!$isYearLocked || $user['role'] === 'Admin'): ?>
            <?php if ($user['role'] === 'HoD'): ?>
              <button type="button" class="btn btn-sm ap-approve-all" style="background:#EFF6FF;color:#1D4ED8;border-color:#BFDBFE"
                onclick="approveAll(event, '<?= e($deptName) ?>', <?= count($deptRecs) ?>)"><?= icon('edit', 14) ?> Edit Request all to Dean</button>
            <?php elseif ($user['role'] === 'Coordinator'): ?>
              <button type="button" class="btn btn-sm ap-approve-all"
                onclick="approveAll(event, '<?= e($deptName) ?>', <?= count($deptRecs) ?>)"><?= icon('check', 14) ?> Approve all & Save to DB</button>
            <?php else: ?>
              <button type="button" class="btn btn-sm ap-approve-all"
                onclick="approveAll(event, '<?= e($deptName) ?>', <?= count($deptRecs) ?>)"><?= icon('check', 14) ?> Approve all</button>
            <?php endif; ?>
          <?php endif; ?>
          <span class="tg-chev"><?= icon('chevron', 16) ?></span>
        </span>
      </summary>
      <div class="table-wrap"><table class="data" style="min-width:600px">
        <thead><tr>
          <th style="padding-left:24px">Record</th>
          <th>Type</th>
          <th>Status</th>
          <th>Submitted / Note</th>
          <th class="num" style="padding-right:24px">Actions</th>
        </tr></thead>
        <tbody>
        <?php foreach ($deptRecs as $r): ?>
          <tr>
            <td style="padding-left:24px">
              <div style="font-weight:500; max-width:340px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis"><?= e($r['_title']) ?></div>
              <?php $who = $r['faculty_name'] ?? $r['candidate_name'] ?? $r['student_name'] ?? ''; ?>
              <?php if ($who !== ''): ?><div class="card-sub"><?= e($who) ?> &middot; Dept: <?= e($r['department'] ?? 'N/A') ?></div><?php endif; ?>
            </td>
            <td><span class="badge badge-info"><?= e($r['_type_label']) ?></span></td>
            <td><span class="badge badge-<?= status_class($r['status']) ?>"><?= e($r['status']) ?></span></td>
            <td class="card-sub">
              <?= e(time_ago($r['created_at'])) ?>
              <?php if (!empty($r['review_remark'])): ?>
                <div style="font-size:11px;color:#1D4ED8;margin-top:2px"><strong>Note:</strong> <?= e($r['review_remark']) ?></div>
              <?php endif; ?>
            </td>
            <td class="num" style="padding-right:24px">
              <?php if (!$isYearLocked || $user['role'] === 'Admin'): ?>
                <div class="flex gap-2" style="justify-content:flex-end">
                  <?php if ($user['role'] === 'HoD'): ?>
                    <button class="btn btn-sm" style="background:#EFF6FF;color:#1D4ED8;border-color:#BFDBFE;height:32px;padding:0 10px;font-size:12px"
                      onclick="reviewRecord('<?=e($r['_type_key'])?>',<?=(int)$r['id']?>,'request_edit', <?= json_encode($r['_title']) ?>, <?= json_encode($who) ?>, <?= json_encode($r['department'] ?? '') ?>)"><?= icon('edit',14) ?> Edit Request to Dean</button>
                  <?php elseif ($user['role'] === 'Coordinator'): ?>
                    <?php if ($r['status'] === 'Unlocked for Edit'): ?>
                      <a class="btn btn-sm" style="background:#EFF6FF;color:#1D4ED8;border-color:#BFDBFE;height:32px;padding:0 10px;font-size:12px;text-decoration:none;display:inline-flex;align-items:center;gap:4px"
                        href="<?= e(url('upload.php?type=' . urlencode($r['_type_key']) . '&edit_id=' . (int)$r['id'])) ?>"><?= icon('edit',14) ?> Edit & Resubmit</a>
                    <?php else: ?>
                      <button class="btn btn-sm" style="background:#ECFDF5;color:#047857;border-color:#A7F3D0;height:32px;padding:0 10px;font-size:12px"
                        onclick="reviewRecord('<?=e($r['_type_key'])?>',<?=(int)$r['id']?>,'approve')"><?= icon('check',14) ?> Approve & Save to DB</button>
                    <?php endif; ?>
                  <?php elseif ($user['role'] === 'Dean'): ?>
                    <?php if ($r['status'] === 'Edit Requested'): ?>
                      <button class="btn btn-sm" style="background:#ECFDF5;color:#047857;border-color:#A7F3D0;height:32px;padding:0 10px;font-size:12px"
                        onclick="reviewRecord('<?=e($r['_type_key'])?>',<?=(int)$r['id']?>,'approve_edit')"><?= icon('check',14) ?> Approve Edit Request</button>
                    <?php else: ?>
                      <button class="btn btn-sm" style="background:#ECFDF5;color:#047857;border-color:#A7F3D0;height:32px;padding:0 10px;font-size:12px"
                        onclick="reviewRecord('<?=e($r['_type_key'])?>',<?=(int)$r['id']?>,'approve')"><?= icon('check',14) ?> Approve</button>
                    <?php endif; ?>
                  <?php else: ?>
                    <button class="btn btn-sm" style="background:#ECFDF5;color:#047857;border-color:#A7F3D0;height:32px;padding:0 10px;font-size:12px"
                      onclick="reviewRecord('<?=e($r['_type_key'])?>',<?=(int)$r['id']?>,'approve')"><?= icon('check',14) ?> Approve</button>
                  <?php endif; ?>
                  <?php if ($user['role'] !== 'HoD'): ?>
                    <button class="btn btn-sm" style="background:#FEF2F2;color:#B91C1C;border-color:#FECACA;height:32px;padding:0 10px;font-size:12px"
                      onclick="reviewRecord('<?=e($r['_type_key'])?>',<?=(int)$r['id']?>,'reject')"><?= icon('x',14) ?> Reject</button>
                  <?php endif; ?>
                </div>
              <?php else: ?>
                <span style="display:inline-flex;align-items:center;gap:4px;color:#991B1B;background:#FEE2E2;border:1px solid #FECACA;font-size:11px;font-weight:700;padding:2px 8px;border-radius:6px">
                  <?= icon('lock', 11) ?> Cycle Locked
                </span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    </details>
  <?php endforeach; ?>

  <!-- Hidden form used by the per-department "Approve all" button -->
  <form method="post" id="bulkForm" style="display:none">
    <?= csrf_field() ?>
    <input type="hidden" name="review_action" value="approve_all">
    <input type="hidden" name="department" id="bulk-dept">
  </form>
<?php endif; ?>

<style>
  .ap-head-actions { margin-left:auto; display:inline-flex; align-items:center; gap:12px; }
  .ap-approve-all { background:#ECFDF5; color:#047857; border-color:#A7F3D0; height:32px; padding:0 12px; font-size:12px; }
  .ap-approve-all:hover { background:#D1FAE5; border-color:#6EE7B7; }
</style>

<!-- Review / Edit Request dialog -->
<dialog class="modal" id="reviewDlg" style="max-width:32rem">
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="record_type" id="rv-type">
    <input type="hidden" name="record_id" id="rv-id">
    <input type="hidden" name="review_action" id="rv-action">
    <div class="modal-head"><div><h3 id="rv-title">Review Record</h3></div></div>
    <div class="modal-body">
      <div id="rv-meta-box" style="background:#F4F6FA;border:1px solid #E4E9F2;border-radius:8px;padding:10px 14px;margin-bottom:14px;font-size:12.5px;display:none">
        <div style="margin-bottom:4px"><strong>Department:</strong> <span id="rv-meta-dept"></span></div>
        <div style="margin-bottom:4px"><strong>Faculty Name:</strong> <span id="rv-meta-who"></span></div>
        <div><strong>Record Title:</strong> <span id="rv-meta-rec"></span></div>
      </div>
      <div class="field">
        <label id="rv-remark-label">Explanation / Remark</label>
        <textarea class="input" name="review_remark" id="rv-remark" rows="3" placeholder="Explain what needs to be edited or add notes…"></textarea>
      </div>
    </div>
    <div class="modal-foot">
      <button type="button" class="btn btn-outline btn-sm" onclick="this.closest('dialog').close()">Cancel</button>
      <button type="submit" class="btn btn-primary btn-sm" id="rv-btn">Confirm</button>
    </div>
  </form>
</dialog>

<script>
const currentRole = <?= json_encode($user['role']) ?>;

function reviewRecord(type, id, action, title = '', who = '', dept = '') {
  document.getElementById('rv-type').value = type;
  document.getElementById('rv-id').value = id;
  document.getElementById('rv-action').value = action;
  
  const metaBox = document.getElementById('rv-meta-box');
  const remarkLabel = document.getElementById('rv-remark-label');
  const remarkInput = document.getElementById('rv-remark');
  const btn = document.getElementById('rv-btn');

  if (action === 'request_edit' || currentRole === 'HoD') {
    document.getElementById('rv-action').value = 'request_edit';
    document.getElementById('rv-title').textContent = 'Edit Request to Dean';
    metaBox.style.display = 'block';
    document.getElementById('rv-meta-dept').textContent = dept || <?= json_encode($user['department'] ?? 'Department') ?>;
    document.getElementById('rv-meta-who').textContent = who || 'Faculty Member';
    document.getElementById('rv-meta-rec').textContent = title || 'Selected Record';
    remarkLabel.textContent = 'Explanation for Dean (Required)';
    remarkInput.placeholder = 'Please explain why this submitted record needs to be edited…';
    remarkInput.required = true;
    btn.textContent = 'Submit Edit Request to Dean';
    btn.className = 'btn btn-sm';
    btn.style.cssText = 'background:#1D4ED8;color:#fff';
  } else if (action === 'approve_edit') {
    document.getElementById('rv-title').textContent = 'Approve Edit Request';
    metaBox.style.display = 'none';
    remarkLabel.textContent = 'Note for Coordinator (Optional)';
    remarkInput.placeholder = 'Add any instructions for Coordinator…';
    remarkInput.required = false;
    btn.textContent = 'Approve Edit & Unlock for Coordinator';
    btn.className = 'btn btn-sm';
    btn.style.cssText = 'background:#047857;color:#fff';
  } else if (action === 'reject') {
    document.getElementById('rv-title').textContent = 'Reject Record';
    metaBox.style.display = 'none';
    remarkLabel.textContent = 'Rejection Reason (Optional)';
    remarkInput.placeholder = 'State reason for rejection…';
    remarkInput.required = false;
    btn.textContent = 'Reject Record';
    btn.className = 'btn btn-danger btn-sm';
    btn.style.cssText = '';
  } else {
    document.getElementById('rv-title').textContent = currentRole === 'Coordinator' ? 'Approve & Save to Database' : 'Approve Record';
    metaBox.style.display = 'none';
    remarkLabel.textContent = 'Remark (Optional)';
    remarkInput.placeholder = 'Add a note…';
    remarkInput.required = false;
    btn.textContent = currentRole === 'Coordinator' ? 'Approve & Save to DB' : 'Approve';
    btn.className = 'btn btn-sm';
    btn.style.cssText = 'background:#047857;color:#fff';
  }

  document.getElementById('reviewDlg').showModal();
}

/* Approve or send edit request for every pending record in one department. */
function approveAll(ev, dept, n) {
  ev.preventDefault();
  ev.stopPropagation();
  let msg = 'Approve all ' + n + ' pending record' + (n === 1 ? '' : 's') + ' in ' + dept + '?';
  if (currentRole === 'HoD') {
    msg = 'Submit Edit Request for all ' + n + ' record' + (n === 1 ? '' : 's') + ' in ' + dept + ' to Dean?';
  } else if (currentRole === 'Coordinator') {
    msg = 'Approve and save all ' + n + ' pending record' + (n === 1 ? '' : 's') + ' in ' + dept + ' directly to the database?';
  }
  if (!confirm(msg)) return;
  document.getElementById('bulk-dept').value = dept;
  document.getElementById('bulkForm').submit();
}
</script>

<?php require __DIR__ . '/inc/footer.php'; ?>
