<?php
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/models/Record.php';

$user = require_role(['Admin', 'HoD']);

// An HoD may only review their own department; Admin has no such limit.
$scopeDept = ($user['role'] === 'HoD') ? ($user['department'] ?? null) : null;

// Handle approve/reject
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) input('review_action');
    $type   = (string) input('record_type');
    $id     = (int)    input('record_id');
    $remark = (string) input('review_remark');

    // $scopeDept is enforced inside record_review, so a forged record_id for
    // another department cannot be approved from here.
    [$ok, $msg] = record_review($type, $id, $action, $remark, $user['id'], $scopeDept);
    flash($ok ? 'success' : 'error', $msg);
    redirect('/approvals.php');
}

$records = pending_records($scopeDept);

$pageTitle  = 'Approvals';
$breadcrumb = 'Approvals';
require __DIR__ . '/inc/header.php';
?>

<div class="page-head">
  <div>
    <h1>Pending Approvals</h1>
    <div class="sub"><?= count($records) ?> record<?= count($records)!==1?'s':'' ?> awaiting review<?= $scopeDept ? ' · ' . e($scopeDept) : '' ?></div>
  </div>
</div>

<div class="card">
  <div class="card-body" style="padding:0">
    <?php if (empty($records)): ?>
      <div class="empty" style="padding:80px 24px">
        <div class="ic" style="background:#ECFDF5; color:#047857; width:56px; height:56px"><?= icon('check', 24) ?></div>
        <p style="font-size:16px; font-weight:600">All caught up!</p>
        <div class="note">No records pending approval right now.</div>
      </div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="data" style="min-width:700px">
          <thead><tr>
            <th style="padding-left:24px">Record</th>
            <th>Type</th>
            <th>Department</th>
            <th>Submitted</th>
            <th class="num" style="padding-right:24px">Actions</th>
          </tr></thead>
          <tbody>
          <?php foreach ($records as $r): ?>
            <tr>
              <td style="padding-left:24px">
                <div style="font-weight:500; max-width:300px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis"><?= e($r['_title']) ?></div>
                <div class="card-sub"><?= e($r['faculty_name'] ?? $r['candidate_name'] ?? $r['student_name'] ?? '') ?></div>
              </td>
              <td><span class="badge badge-info"><?= e($r['_type_label']) ?></span></td>
              <td style="color:var(--ink-muted)"><?= e($r['department'] ?? '—') ?></td>
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
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

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
</script>

<?php require __DIR__ . '/inc/footer.php'; ?>
