<?php
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/models/Record.php';
require_once __DIR__ . '/models/Department.php';

$user = require_role(['Admin', 'HoD']);
require_module('approvals');

// An HoD may only review their own department; Admin has no such limit.
$scopeDept = ($user['role'] === 'HoD') ? ($user['department'] ?? null) : null;

// Handle approve/reject (single) and bulk approve-all-in-department.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) input('review_action');

    if ($action === 'approve_all') {
        // $scopeDept is enforced inside records_bulk_approve, so an HoD can only
        // ever clear their own department.
        [$ok, $msg] = records_bulk_approve((string) input('department'), (int) $user['id'], $scopeDept);
    } else {
        $type   = (string) input('record_type');
        $id     = (int)    input('record_id');
        $remark = (string) input('review_remark');
        // $scopeDept is enforced inside record_review, so a forged record_id for
        // another department cannot be approved from here.
        [$ok, $msg] = record_review($type, $id, $action, $remark, $user['id'], $scopeDept);
    }

    flash($ok ? 'success' : 'error', $msg);
    redirect('/approvals.php');
}

$types       = record_types();
$departments = departments_all();

// Filters: Admin may narrow by department; anyone may narrow by type and search.
$filterDept = ($user['role'] === 'Admin') ? (trim((string) input('department')) ?: null) : null;
$filterType = (string) input('type');
if (!isset($types[$filterType])) { $filterType = ''; }
$search = trim((string) input('q'));

// Department scope: an HoD is pinned to their own; an Admin uses the filter.
$effectiveDept = $scopeDept ?? $filterDept;

$records = pending_records($effectiveDept);

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

$pageTitle  = 'Approvals';
$breadcrumb = 'Approvals';
require __DIR__ . '/inc/header.php';
?>

<?php $activeCount = ($filterDept ? 1 : 0) + ($filterType !== '' ? 1 : 0) + ($search !== '' ? 1 : 0); ?>
<div class="page-head">
  <div>
    <h1>Pending Approvals</h1>
    <div class="sub"><?= count($records) ?> record<?= count($records)!==1?'s':'' ?> awaiting review<?= $scopeDept ? ' · ' . e($scopeDept) : '' ?></div>
  </div>

  <div class="actions">
    <details class="filter-funnel">
      <summary class="btn btn-outline btn-sm">
        <?= icon('filter', 15) ?> Filters<?php if ($activeCount): ?> <span class="ff-dot"><?= $activeCount ?></span><?php endif; ?>
      </summary>
      <span class="filter-backdrop" onclick="this.closest('details').removeAttribute('open')"></span>
      <div class="filter-pop">
        <form method="get">
          <div class="ff-head">
            <span>Filter approvals</span>
            <?php if ($hasFilter): ?><a class="ff-clear" href="<?= e(url('approvals.php')) ?>">Clear all</a><?php endif; ?>
          </div>

          <?php if ($user['role'] === 'Admin'): ?>
            <div class="ff-field"><label class="ff-label">Department</label>
              <select class="select" name="department" onchange="this.form.submit()">
                <option value="">All departments</option>
                <?php foreach ($departments as $d): ?>
                  <option value="<?= e($d['name']) ?>" <?= $filterDept === $d['name'] ? 'selected' : '' ?>><?= e($d['name']) ?></option>
                <?php endforeach; ?>
              </select></div>
          <?php endif; ?>

          <div class="ff-field"><label class="ff-label">Record Type</label>
            <select class="select" name="type" onchange="this.form.submit()">
              <option value="">All types</option>
              <?php foreach ($types as $key => $t): ?>
                <option value="<?= e($key) ?>" <?= $filterType === $key ? 'selected' : '' ?>><?= e($t['label']) ?></option>
              <?php endforeach; ?>
            </select></div>

          <div class="ff-field"><label class="ff-label">Search</label>
            <input class="input" type="search" name="q" value="<?= e($search) ?>" placeholder="Title, person or department…"></div>

          <div class="ff-actions">
            <button class="btn btn-primary btn-sm" type="submit"><?= icon('search', 14) ?> Apply filters</button>
          </div>
        </form>
      </div>
    </details>
  </div>
</div>

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
        <div class="note">No records pending approval right now.</div>
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
        <span class="badge badge-info"><?= count($deptRecs) ?> pending</span>
        <span class="ap-head-actions">
          <button type="button" class="btn btn-sm ap-approve-all"
            onclick="approveAll(event, '<?= e($deptName) ?>', <?= count($deptRecs) ?>)"><?= icon('check', 14) ?> Approve all</button>
          <span class="tg-chev"><?= icon('chevron', 16) ?></span>
        </span>
      </summary>
      <div class="table-wrap"><table class="data" style="min-width:600px">
        <thead><tr>
          <th style="padding-left:24px">Record</th>
          <th>Type</th>
          <th>Submitted</th>
          <th class="num" style="padding-right:24px">Actions</th>
        </tr></thead>
        <tbody>
        <?php foreach ($deptRecs as $r): ?>
          <tr>
            <td style="padding-left:24px">
              <div style="font-weight:500; max-width:340px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis"><?= e($r['_title']) ?></div>
              <?php $who = $r['faculty_name'] ?? $r['candidate_name'] ?? $r['student_name'] ?? ''; ?>
              <?php if ($who !== ''): ?><div class="card-sub"><?= e($who) ?></div><?php endif; ?>
            </td>
            <td><span class="badge badge-info"><?= e($r['_type_label']) ?></span></td>
            <td class="card-sub"><?= e(time_ago($r['created_at'])) ?></td>
            <td class="num" style="padding-right:24px">
              <div class="flex gap-2" style="justify-content:flex-end">
                <button class="btn btn-sm" style="background:#ECFDF5;color:#047857;border-color:#A7F3D0;height:32px;padding:0 10px;font-size:12px"
                  onclick="reviewRecord('<?=e($r['_type_key'])?>',<?=(int)$r['id']?>,'approve')"><?= icon('check',14) ?> Approve</button>
                <button class="btn btn-sm" style="background:#FEF2F2;color:#B91C1C;border-color:#FECACA;height:32px;padding:0 10px;font-size:12px"
                  onclick="reviewRecord('<?=e($r['_type_key'])?>',<?=(int)$r['id']?>,'reject')"><?= icon('x',14) ?> Reject</button>
              </div>
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

<!-- Review dialog -->
<dialog class="modal" id="reviewDlg" style="max-width:28rem">
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="record_type" id="rv-type">
    <input type="hidden" name="record_id" id="rv-id">
    <input type="hidden" name="review_action" id="rv-action">
    <div class="modal-head"><div><h3 id="rv-title">Review Record</h3></div></div>
    <div class="modal-body">
      <div class="field"><label>Remark (optional)</label>
        <input class="input" name="review_remark" placeholder="Add a note…">
      </div>
    </div>
    <div class="modal-foot">
      <button type="button" class="btn btn-outline btn-sm" onclick="this.closest('dialog').close()">Cancel</button>
      <button type="submit" class="btn btn-primary btn-sm" id="rv-btn">Confirm</button>
    </div>
  </form>
</dialog>

<script>
function reviewRecord(type, id, action) {
  document.getElementById('rv-type').value = type;
  document.getElementById('rv-id').value = id;
  document.getElementById('rv-action').value = action;
  const isApprove = action === 'approve';
  document.getElementById('rv-title').textContent = isApprove ? 'Approve Record' : 'Reject Record';
  const btn = document.getElementById('rv-btn');
  btn.textContent = isApprove ? 'Approve' : 'Reject';
  btn.className = isApprove ? 'btn btn-sm' : 'btn btn-danger btn-sm';
  if (isApprove) btn.style.cssText = 'background:#047857;color:#fff';
  else btn.style.cssText = '';
  document.getElementById('reviewDlg').showModal();
}

/* Approve every pending record in one department. The button lives inside the
   <summary>, so stop the click from toggling the section open/closed. */
function approveAll(ev, dept, n) {
  ev.preventDefault();
  ev.stopPropagation();
  if (!confirm('Approve all ' + n + ' pending record' + (n === 1 ? '' : 's') + ' in ' + dept + '?')) return;
  document.getElementById('bulk-dept').value = dept;
  document.getElementById('bulkForm').submit();
}
</script>

<?php require __DIR__ . '/inc/footer.php'; ?>
