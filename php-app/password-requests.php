<?php
/**
 * FEAT-11 — Password Requests (Admin only).
 *
 * The queue of "Request Admin to Change Password" tickets raised from the
 * login page. It is the last tab of Settings — it keeps its own page because
 * it has its own POST handler and feature module, but it draws the Settings
 * tab strip (inc/settings_tabs.php) so it reads as part of that screen. The Admin reviews one, then either completes it (setting a new
 * password on the existing users row) or rejects it with a reason.
 *
 * Authorisation is server-side and unconditional: require_role(['Admin'])
 * runs before anything is read or written, so nothing here depends on a
 * button being hidden. The ticket id in a POST is only ever an integer
 * looked up through a prepared statement, and a ticket that is no longer
 * Pending is refused by the model whatever the browser sends.
 */

require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/models/PasswordResetRequest.php';

$user = require_role(['Admin']);
require_module('password-requests');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    if ((string) input('action') === 'process') {
        [$ok, $msg] = password_reset_request_process(
            (int) input('id'),
            (string) input('decision'),
            (string) input('new_password'),
            (string) input('admin_notes'),
            (int) $user['id']
        );
        flash($ok ? 'success' : 'error', $msg);
    }

    // Back to the view the decision was made from.
    $back = array_filter(
        ['status' => $_GET['status'] ?? '', 'q' => $_GET['q'] ?? ''],
        fn($v) => is_string($v) && trim($v) !== ''
    );
    redirect('/password-requests.php' . ($back ? '?' . http_build_query($back) : ''));
}

// Default view is Pending — the tickets that still need a decision.
$statusFilter = trim((string) ($_GET['status'] ?? 'Pending'));
if ($statusFilter !== 'All' && !in_array($statusFilter, password_reset_statuses(), true)) {
    $statusFilter = 'Pending';
}
$search = trim((string) ($_GET['q'] ?? ''));

$requests = password_reset_requests_list([
    'status' => $statusFilter === 'All' ? '' : $statusFilter,
    'q'      => $search,
]);
$counts = password_reset_requests_counts();

$statusBadge = ['Pending' => 'warning', 'Completed' => 'success', 'Rejected' => 'danger'];

$pageTitle  = 'Settings';
$breadcrumb = 'Settings';
require __DIR__ . '/inc/header.php';
?>

<div class="page-head">
  <div>
    <h1>Settings</h1>
    <div class="sub">Metrics, your account, and how this portal is set up</div>
  </div>
</div>

<!-- Same strip settings.php draws, with this page as the last tab -->
<?php $settingsTab = 'password'; require __DIR__ . '/inc/settings_tabs.php'; ?>

<form method="get" class="fbar mt-5">
  <span class="fbar-title"><?= icon('filter', 14) ?> Filters</span>

  <label class="fb-field<?= $statusFilter !== 'Pending' ? ' is-set' : '' ?>"><span class="fb-k">Status</span>
    <select name="status" onchange="this.form.submit()">
      <?php foreach (['All', 'Pending', 'Completed', 'Rejected'] as $key): ?>
        <option value="<?= e($key) ?>" <?= $statusFilter === $key ? 'selected' : '' ?>><?= e($key) ?> (<?= (int) ($counts[$key] ?? 0) ?>)</option>
      <?php endforeach; ?>
    </select>
  </label>

  <label class="fb-field fb-search<?= $search !== '' ? ' is-set' : '' ?>">
    <?= icon('search', 15) ?>
    <input type="search" name="q" value="<?= e($search) ?>" placeholder="Search name, email or department…" aria-label="Search password requests">
    <button type="submit" class="fb-go" title="Search" aria-label="Search"><?= icon('arrow-right', 14) ?></button>
  </label>

  <span class="fbar-end">
    <?php if ($search !== '' || $statusFilter !== 'Pending'): ?>
      <a class="fbar-clear" href="<?= e(url('password-requests.php')) ?>"><?= icon('x', 13) ?> Clear</a>
    <?php else: ?>
      <span class="fbar-note"><?= (int) $counts['Pending'] ?> pending</span>
    <?php endif; ?>
  </span>
</form>

<?php if (empty($requests)): ?>
  <div class="card">
    <div class="empty">
      <div class="ic"><?= icon('key', 20) ?></div>
      <p><?= $statusFilter === 'Pending' ? 'No pending password requests' : 'No password requests found' ?></p>
      <div class="note">
        <?php if ($search !== ''): ?>
          Nobody matches that search.
        <?php else: ?>
          Requests appear here when someone uses &ldquo;Request Admin to Change Password&rdquo; on the login page.
        <?php endif; ?>
      </div>
    </div>
  </div>
<?php else: ?>
  <div class="card">
    <div class="card-head">
      <div>
        <div class="card-title">Password Requests</div>
        <div class="card-sub">
          Raised from the login page &middot; <?= (int) $counts['Pending'] ?> pending &middot;
          showing <?= count($requests) ?> <?= $statusFilter === 'All' ? 'request' . (count($requests) === 1 ? '' : 's') : strtolower($statusFilter) ?>
        </div>
      </div>
    </div>
    <div class="table-wrap">
      <table class="data">
        <thead>
          <tr>
            <th style="width:64px">ID</th>
            <th>User</th>
            <th style="width:120px">Role</th>
            <th style="width:150px">Department</th>
            <th>Message</th>
            <th style="width:110px">Status</th>
            <th style="width:130px">Created</th>
            <th style="width:170px">Processed</th>
            <th style="width:110px" class="num">Action</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($requests as $r): ?>
          <tr id="pr-<?= (int) $r['id'] ?>">
            <td class="tabular">#<?= (int) $r['id'] ?></td>
            <td>
              <div class="flex items-center gap-3 min-w-0">
                <div class="avatar-dark avatar-sm"><?= e(initials($r['name'])) ?></div>
                <div class="min-w-0">
                  <div class="truncate" style="font-weight:600" title="<?= e($r['name']) ?>"><?= e($r['name']) ?></div>
                  <div class="card-sub truncate" title="<?= e($r['email']) ?>"><?= e($r['email']) ?></div>
                </div>
              </div>
            </td>
            <td><span class="badge badge-neutral"><?= e($r['role'] === 'Director' ? 'Principal' : $r['role']) ?></span></td>
            <td class="card-sub"><?= e($r['department'] ?: '—') ?></td>
            <td class="card-sub"><?= $r['message'] ? e(excerpt((string) $r['message'], 70)) : '<span style="opacity:.6">No message</span>' ?></td>
            <td><span class="badge badge-<?= $statusBadge[$r['status']] ?? 'neutral' ?>"><?= e($r['status']) ?></span></td>
            <td class="card-sub"><?= e(date('d-m-Y H:i', strtotime((string) $r['created_at']))) ?></td>
            <td class="card-sub">
              <?php if ($r['processed_at']): ?>
                <?= e(date('d-m-Y H:i', strtotime((string) $r['processed_at']))) ?>
                <div style="font-size:11.5px;opacity:.8">by <?= e($r['processed_by_name'] ?: 'Admin') ?></div>
              <?php else: ?>
                —
              <?php endif; ?>
            </td>
            <td class="num">
              <button type="button" class="btn btn-<?= $r['status'] === 'Pending' ? 'primary' : 'outline' ?> btn-sm"
                      onclick='reviewRequest(<?= htmlspecialchars(json_encode([
                          'id'           => (int) $r['id'],
                          'name'         => $r['name'],
                          'email'        => $r['email'],
                          'role'         => $r['role'] === 'Director' ? 'Principal' : $r['role'],
                          'department'   => $r['department'] ?: '—',
                          'message'      => (string) ($r['message'] ?? ''),
                          'status'       => $r['status'],
                          'created'      => date('d-m-Y H:i', strtotime((string) $r['created_at'])),
                          'processed'    => $r['processed_at'] ? date('d-m-Y H:i', strtotime((string) $r['processed_at'])) : '',
                          'processed_by' => (string) ($r['processed_by_name'] ?? ''),
                          'admin_notes'  => (string) ($r['admin_notes'] ?? ''),
                      ], JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>)'>
                <?= $r['status'] === 'Pending' ? 'Review' : 'View' ?>
              </button>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<!-- Review / process dialog -->
<dialog class="modal" id="reviewDlg" style="max-width:40rem; max-height:calc(100vh - 40px); overflow-y:auto;">
  <form method="post" id="reviewForm">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="process">
    <input type="hidden" name="id" id="rv-id">

    <div class="modal-head" style="display:flex; align-items:flex-start; justify-content:space-between; gap:12px;">
      <div style="flex:1; min-width:0;">
        <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
          <h3 style="margin:0;">Password Request <span id="rv-num" class="tabular"></span></h3>
          <span class="badge" id="rv-status"></span>
        </div>
        <div class="msub" style="margin-top:3px;">Raised from the login page</div>
      </div>
      <div style="display:flex; align-items:center; gap:8px; flex-shrink:0;">
        <button type="button" class="btn btn-outline btn-sm" onclick="document.getElementById('reviewDlg').close()" title="Back to requests" style="display:inline-flex; align-items:center; gap:5px; height:32px;">
          <?= icon('arrow-left', 14) ?> Back
        </button>
        <button type="button" class="btn btn-ghost btn-sm" onclick="document.getElementById('reviewDlg').close()" title="Close dialog" aria-label="Close" style="width:32px; height:32px; padding:0; display:inline-flex; align-items:center; justify-content:center; color:var(--ink-muted); border-radius:8px;">
          <?= icon('x', 16) ?>
        </button>
      </div>
    </div>

    <div class="modal-body">
      <div style="display:grid;grid-template-columns:120px 1fr;gap:9px 14px;font-size:13.5px;margin-bottom:6px">
        <div class="card-sub">User</div>          <div id="rv-name" style="font-weight:600"></div>
        <div class="card-sub">Email</div>         <div id="rv-email"></div>
        <div class="card-sub">Role</div>          <div id="rv-role"></div>
        <div class="card-sub">Department</div>    <div id="rv-dept"></div>
        <div class="card-sub">Created</div>       <div id="rv-created" class="tabular"></div>
        <div class="card-sub">Request message</div>
        <div id="rv-message" style="white-space:pre-wrap"></div>
      </div>

      <div id="rv-decided" style="display:none;margin-top:16px;padding-top:16px;border-top:1px solid var(--hairline)">
        <div style="display:grid;grid-template-columns:120px 1fr;gap:9px 14px;font-size:13.5px">
          <div class="card-sub">Processed</div>    <div id="rv-processed" class="tabular"></div>
          <div class="card-sub">Processed by</div> <div id="rv-processedby"></div>
          <div class="card-sub">Admin notes</div>  <div id="rv-notes" style="white-space:pre-wrap"></div>
        </div>
      </div>

      <div id="rv-actions" style="display:none;margin-top:18px;padding-top:16px;border-top:1px solid var(--hairline)">
        <div class="field">
          <label for="rv-password">New password <span class="req">*</span> <span class="card-sub" style="font-weight:400">— required to complete, ignored when rejecting</span></label>
          <input class="input" type="text" name="new_password" id="rv-password" minlength="6" autocomplete="new-password"
                 placeholder="At least 6 characters">
          <div class="card-sub" style="margin-top:5px">
            Stored only as a hash on the user&rsquo;s account. It is never written to this request.
          </div>
        </div>
        <div class="field">
          <label for="rv-adminnotes">Admin notes <span class="card-sub" style="font-weight:400">— required when rejecting</span></label>
          <textarea class="input textarea" name="admin_notes" id="rv-adminnotes" rows="3"
                    placeholder="How this was handled, or why it was rejected…"></textarea>
        </div>
      </div>
    </div>

    <div class="modal-foot" id="rv-foot">
      <button type="button" class="btn btn-outline btn-sm" onclick="this.closest('dialog').close()" style="display:inline-flex; align-items:center; gap:5px;">
        <?= icon('arrow-left', 13) ?> Back
      </button>
      <button type="submit" class="btn btn-outline btn-sm" name="decision" value="reject" id="rv-reject" formnovalidate>
        Reject Request
      </button>
      <button type="submit" class="btn btn-primary btn-sm" name="decision" value="complete" id="rv-complete">
        Complete &amp; Change Password
      </button>
    </div>
  </form>
</dialog>

<script>
function reviewRequest(d) {
  document.getElementById('rv-id').value = d.id;
  document.getElementById('rv-num').textContent = '#' + d.id;
  document.getElementById('rv-name').textContent = d.name;
  document.getElementById('rv-email').textContent = d.email;
  document.getElementById('rv-role').textContent = d.role;
  document.getElementById('rv-dept').textContent = d.department;
  document.getElementById('rv-created').textContent = d.created;
  document.getElementById('rv-message').textContent = d.message || 'No message provided.';

  var badge = document.getElementById('rv-status');
  badge.textContent = d.status;
  badge.className = 'badge badge-' + (d.status === 'Pending' ? 'warning' : (d.status === 'Completed' ? 'success' : 'danger'));

  var pending = (d.status === 'Pending');

  // A decided ticket is read-only: the inputs and the two decision buttons are
  // removed from the form, not merely hidden. The model refuses a non-Pending
  // id in any case, so this only keeps the page honest about what it offers.
  document.getElementById('rv-actions').style.display = pending ? 'block' : 'none';
  document.getElementById('rv-complete').style.display = pending ? 'inline-flex' : 'none';
  document.getElementById('rv-reject').style.display = pending ? 'inline-flex' : 'none';
  document.getElementById('rv-password').disabled = !pending;
  document.getElementById('rv-adminnotes').disabled = !pending;

  var decided = document.getElementById('rv-decided');
  decided.style.display = pending ? 'none' : 'block';
  if (!pending) {
    document.getElementById('rv-processed').textContent = d.processed || '—';
    document.getElementById('rv-processedby').textContent = d.processed_by || 'Admin';
    document.getElementById('rv-notes').textContent = d.admin_notes || 'No notes recorded.';
  }

  if (pending) {
    document.getElementById('rv-password').value = '';
    document.getElementById('rv-adminnotes').value = '';
  }

  document.getElementById('reviewDlg').showModal();
}

/* Completing needs a password; rejecting needs a reason. Both are enforced
   again on the server — this only saves a round trip. */
document.getElementById('reviewForm').addEventListener('submit', function (ev) {
  var decision = ev.submitter ? ev.submitter.value : '';
  var pw    = document.getElementById('rv-password');
  var notes = document.getElementById('rv-adminnotes');

  if (decision === 'complete' && pw.value.trim().length < 6) {
    ev.preventDefault();
    pw.focus();
    alert('Enter a new password of at least 6 characters to complete this request.');
  } else if (decision === 'reject' && notes.value.trim() === '') {
    ev.preventDefault();
    notes.focus();
    alert('Give a reason for rejecting this request.');
  }
});

var rvDlg = document.getElementById('reviewDlg');
if (rvDlg) {
  rvDlg.addEventListener('click', function(e) {
    if (e.target === rvDlg) {
      rvDlg.close();
    }
  });
}
</script>
<?php require __DIR__ . '/inc/footer.php'; ?>
