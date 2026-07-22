<?php
/**
 * Targets — set them, send them up for review, and freeze the agreed figure.
 *
 * Who does what:
 *   HoD       writes targets for their own department and sends them for review
 *   Director  approves what comes up, or sends it back with a note
 *   Admin     the same, plus the last word on an already-frozen target
 *
 * The page never decides permissions itself: every button is drawn from the
 * target_can_*() predicates in models/Target.php, and each POST re-checks the
 * same predicate before writing. See that file for the state machine.
 */

require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/models/Target.php';
require_once __DIR__ . '/models/Department.php';

$user = require_role(['Admin', 'HoD', 'Director']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) input('action');

    if ($action === 'create') {
        [$ok, $msg] = target_create(
            $user,
            (string) input('department'),
            (string) input('academic_year'),
            (string) input('metric'),
            (int) input('target_value'),
            (string) input('remarks')
        );
    } elseif ($action === 'update') {
        [$ok, $msg] = target_update(
            (int) input('id'),
            $user,
            (string) input('department'),
            (string) input('academic_year'),
            (string) input('metric'),
            (int) input('target_value'),
            (int) input('achieved_value'),
            (string) input('remarks')
        );
    } elseif ($action === 'submit') {
        [$ok, $msg] = target_submit((int) input('id'), $user);
    } elseif ($action === 'review') {
        [$ok, $msg] = target_review((int) input('id'), $user, (string) input('decision'), (string) input('review_remark'));
    } elseif ($action === 'delete') {
        [$ok, $msg] = target_delete((int) input('id'), $user);
    } else {
        [$ok, $msg] = [false, 'Unknown action.'];
    }

    flash($ok ? 'success' : 'error', $msg);
    redirect('/targets.php');
}

// A HoD only ever sees their own department; the other two choose.
$isHod      = $user['role'] === 'HoD';
$canCreate  = in_array($user['role'], ['Admin', 'HoD'], true);
$deptFilter = $isHod ? ($user['department'] ?? null) : (trim((string) ($_GET['department'] ?? '')) ?: null);
$yearFilter = trim((string) ($_GET['year'] ?? '')) ?: null;
$statFilter = in_array(($_GET['status'] ?? ''), target_statuses(), true) ? $_GET['status'] : null;

$targets     = targets_all($deptFilter, $yearFilter, $statFilter);
$departments = departments_all();
$metrics     = metric_names();
$years       = academic_years();
$awaiting    = count(array_filter($targets, fn($t) => target_can_review($t, $user)));

$pageTitle = 'Targets';
$breadcrumb = 'Targets';
require __DIR__ . '/inc/header.php';
?>

<div class="page-head">
  <div>
    <h1>Targets</h1>
    <div class="sub">
      <?= count($targets) ?> target<?= count($targets) !== 1 ? 's' : '' ?>
      <?php if ($awaiting): ?>
        &middot; <strong><?= $awaiting ?></strong> waiting for your review
      <?php endif; ?>
      <?php if ($isHod): ?>&middot; <?= e($user['department'] ?? '') ?><?php endif; ?>
    </div>
  </div>

  <div class="actions">
    <form method="get" class="flex gap-2 items-center">
      <?php if (!$isHod): ?>
        <select class="select" name="department" style="min-width:150px" onchange="this.form.submit()">
          <option value="">All Depts</option>
          <?php foreach ($departments as $d): ?>
            <option value="<?= e($d['name']) ?>" <?= $deptFilter === $d['name'] ? 'selected' : '' ?>><?= e($d['name']) ?></option>
          <?php endforeach; ?>
        </select>
      <?php endif; ?>

      <select class="select" name="year" style="min-width:120px" onchange="this.form.submit()">
        <option value="">All Years</option>
        <?php foreach ($years as $y): ?>
          <option value="<?= e($y) ?>" <?= $yearFilter === $y ? 'selected' : '' ?>><?= e($y) ?></option>
        <?php endforeach; ?>
      </select>

      <select class="select" name="status" style="min-width:150px" onchange="this.form.submit()">
        <option value="">All Statuses</option>
        <?php foreach (target_statuses() as $s): ?>
          <option value="<?= e($s) ?>" <?= $statFilter === $s ? 'selected' : '' ?>><?= e($s) ?></option>
        <?php endforeach; ?>
      </select>
    </form>

    <?php if ($canCreate): ?>
      <button class="btn btn-primary btn-sm" onclick="document.getElementById('addDlg').showModal()">
        <?= icon('plus') ?> Add Target
      </button>
    <?php endif; ?>
  </div>
</div>

<div class="card"><div class="card-body" style="padding:0">
  <?php if (empty($targets)): ?>

    <div class="empty">
      <div class="ic"><?= icon('target', 20) ?></div>
      <p>No targets here</p>
      <div class="note">
        <?= $canCreate ? 'Add a target to start tracking department progress.' : 'Targets appear here once a HoD sends one up for review.' ?>
      </div>
    </div>

  <?php else: ?>

    <div class="table-wrap"><table class="data" style="min-width:900px">
      <thead><tr>
        <th style="padding-left:24px">Metric</th>
        <th>Department</th>
        <th>Year</th>
        <th>Status</th>
        <th class="num">Target</th>
        <th class="num">Achieved</th>
        <th class="num">Progress</th>
        <th class="num" style="padding-right:24px">Actions</th>
      </tr></thead>
      <tbody>
      <?php foreach ($targets as $t): ?>
        <?php
          $pct      = $t['target_value'] > 0 ? min(100, round($t['achieved_value'] / $t['target_value'] * 100)) : 0;
          $barColor = $pct >= 100 ? '#10B981' : ($pct >= 50 ? 'var(--orange-500)' : '#EF4444');
          $frozen   = target_is_frozen($t);
          $status   = (string) ($t['status'] ?? 'Draft');
        ?>
        <tr>
          <td style="padding-left:24px">
            <div style="font-weight:500"><?= e($t['metric']) ?></div>
            <?php if (!empty($t['remarks'])): ?>
              <div class="card-sub"><?= e($t['remarks']) ?></div>
            <?php endif; ?>
            <?php if ($status === 'Changes Requested' && !empty($t['review_remark'])): ?>
              <div class="card-sub" style="color:#B45309;margin-top:2px">
                <?= icon('alert-triangle', 12) ?> <?= e($t['review_remark']) ?>
              </div>
            <?php endif; ?>
          </td>
          <td style="color:var(--ink-muted)"><?= e($t['department'] ?? '—') ?></td>
          <td><span class="badge badge-neutral"><?= e($t['academic_year'] ?? '—') ?></span></td>
          <td>
            <span class="badge badge-<?= target_status_class($status) ?>">
              <?php if ($frozen): ?><?= icon('shield', 12) ?> <?php endif; ?><?= e($status) ?>
            </span>
            <?php if ($frozen && !empty($t['approver_name'])): ?>
              <div class="card-sub" style="margin-top:3px">by <?= e($t['approver_name']) ?></div>
            <?php endif; ?>
          </td>
          <td class="num tabular" style="font-weight:600"><?= (int) $t['target_value'] ?></td>
          <td class="num tabular" style="font-weight:600"><?= (int) $t['achieved_value'] ?></td>
          <td class="num">
            <div class="flex items-center gap-2" style="justify-content:flex-end">
              <span class="tabular" style="font-size:12px;font-weight:600;color:<?= $barColor ?>"><?= $pct ?>%</span>
              <span style="width:48px;height:6px;border-radius:999px;background:var(--navy-100);overflow:hidden;display:inline-block">
                <span style="display:block;height:100%;width:<?= $pct ?>%;background:<?= $barColor ?>;border-radius:999px"></span>
              </span>
            </div>
          </td>
          <td class="num" style="padding-right:24px">
            <div class="dept-actions" style="justify-content:flex-end">

              <?php if (target_can_submit($t, $user)): ?>
                <form method="post" style="display:inline">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="submit">
                  <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                  <button class="mini-btn" title="Send for review"><?= icon('send', 15) ?></button>
                </form>
              <?php endif; ?>

              <?php if (target_can_review($t, $user)): ?>
                <button class="mini-btn" title="Approve and freeze"
                        onclick='reviewTarget(<?= e(json_encode(["id" => (int) $t["id"], "metric" => $t["metric"], "dept" => $t["department"], "target" => (int) $t["target_value"]])) ?>, "approve")'>
                  <?= icon('check', 15) ?>
                </button>
                <button class="mini-btn" title="Send back for changes"
                        onclick='reviewTarget(<?= e(json_encode(["id" => (int) $t["id"], "metric" => $t["metric"], "dept" => $t["department"], "target" => (int) $t["target_value"]])) ?>, "changes")'>
                  <?= icon('refresh', 15) ?>
                </button>
              <?php endif; ?>

              <?php if (target_can_edit($t, $user)): ?>
                <button class="mini-btn" title="<?= $frozen ? 'Edit frozen target' : 'Edit' ?>"
                        onclick='editTarget(<?= e(json_encode($t)) ?>)'><?= icon('pencil', 15) ?></button>
              <?php endif; ?>

              <?php if (target_can_delete($t, $user)): ?>
                <button class="mini-btn danger" title="Delete"
                        onclick='delTarget(<?= (int) $t["id"] ?>, "<?= e($t["metric"]) ?>")'><?= icon('trash', 15) ?></button>
              <?php endif; ?>

              <?php if ($status === 'Pending Review' && !target_can_review($t, $user)): ?>
                <span class="card-sub"><?= icon('clock', 14) ?></span>
              <?php endif; ?>

            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody></table></div>

  <?php endif; ?>
</div></div>

<?php if ($canCreate): ?>
<!-- Add dialog. A HoD's department is fixed by their account, so it is shown, not chosen. -->
<dialog class="modal" id="addDlg"><form method="post"><?= csrf_field() ?>
  <input type="hidden" name="action" value="create">
  <div class="modal-head"><div>
    <h3>Add Target</h3>
    <div class="msub"><?= $isHod ? 'Saved as a draft — send it for review when ready.' : 'Created by an Admin, so it is frozen straight away.' ?></div>
  </div></div>
  <div class="modal-body" style="display:grid;grid-template-columns:1fr 1fr;gap:0 16px">
    <div class="field"><label>Metric <span class="req">*</span></label>
      <select class="select" name="metric" required>
        <?php foreach ($metrics as $m): ?><option><?= e($m) ?></option><?php endforeach; ?>
      </select></div>
    <div class="field"><label>Department <span class="req">*</span></label>
      <?php if ($isHod): ?>
        <input class="input" value="<?= e($user['department'] ?? '') ?>" disabled>
      <?php else: ?>
        <select class="select" name="department" required>
          <?php foreach ($departments as $d): ?><option value="<?= e($d['name']) ?>"><?= e($d['name']) ?></option><?php endforeach; ?>
        </select>
      <?php endif; ?></div>
    <div class="field"><label>Academic Year</label>
      <select class="select" name="academic_year">
        <?php foreach ($years as $y): ?><option><?= e($y) ?></option><?php endforeach; ?>
      </select></div>
    <div class="field"><label>Target Value <span class="req">*</span></label>
      <input class="input" type="number" name="target_value" min="0" required></div>
    <div class="field" style="grid-column:span 2"><label>Remarks</label>
      <input class="input" name="remarks" placeholder="Optional notes"></div>
  </div>
  <div class="modal-foot">
    <button type="button" class="btn btn-outline btn-sm" onclick="this.closest('dialog').close()">Cancel</button>
    <button type="submit" class="btn btn-primary btn-sm">Add</button>
  </div>
</form></dialog>

<!-- Edit dialog -->
<dialog class="modal" id="editDlg"><form method="post"><?= csrf_field() ?>
  <input type="hidden" name="action" value="update">
  <input type="hidden" name="id" id="et-id">
  <div class="modal-head"><div>
    <h3>Edit Target</h3>
    <div class="msub" id="et-note"></div>
  </div></div>
  <div class="modal-body" style="display:grid;grid-template-columns:1fr 1fr;gap:0 16px">
    <div class="field"><label>Metric</label>
      <select class="select" name="metric" id="et-metric">
        <?php foreach ($metrics as $m): ?><option><?= e($m) ?></option><?php endforeach; ?>
      </select></div>
    <div class="field"><label>Department</label>
      <select class="select" name="department" id="et-dept" <?= $isHod ? 'disabled' : '' ?>>
        <?php foreach ($departments as $d): ?><option value="<?= e($d['name']) ?>"><?= e($d['name']) ?></option><?php endforeach; ?>
      </select></div>
    <div class="field"><label>Year</label>
      <select class="select" name="academic_year" id="et-year">
        <?php foreach ($years as $y): ?><option><?= e($y) ?></option><?php endforeach; ?>
      </select></div>
    <div class="field"><label>Target Value</label>
      <input class="input" type="number" name="target_value" id="et-tv" min="0" required></div>
    <div class="field"><label>Achieved Value</label>
      <input class="input" type="number" name="achieved_value" id="et-av" min="0"></div>
    <div class="field"><label>Remarks</label>
      <input class="input" name="remarks" id="et-rem"></div>
  </div>
  <div class="modal-foot">
    <button type="button" class="btn btn-outline btn-sm" onclick="this.closest('dialog').close()">Cancel</button>
    <button type="submit" class="btn btn-primary btn-sm">Save</button>
  </div>
</form></dialog>

<!-- Delete dialog -->
<dialog class="modal" id="delDlg" style="max-width:28rem"><form method="post"><?= csrf_field() ?>
  <input type="hidden" name="action" value="delete">
  <input type="hidden" name="id" id="dt-id">
  <div class="modal-head"><div><h3>Delete target?</h3></div></div>
  <div class="modal-body">
    <p class="modal-text">Target for <strong id="dt-name"></strong> will be removed.</p>
  </div>
  <div class="modal-foot">
    <button type="button" class="btn btn-outline btn-sm" onclick="this.closest('dialog').close()">Cancel</button>
    <button type="submit" class="btn btn-danger btn-sm">Delete</button>
  </div>
</form></dialog>
<?php endif; ?>

<!-- Review dialog — one form, two decisions; the note is required only to send back. -->
<dialog class="modal" id="revDlg" style="max-width:32rem"><form method="post"><?= csrf_field() ?>
  <input type="hidden" name="action" value="review">
  <input type="hidden" name="id" id="rv-id">
  <input type="hidden" name="decision" id="rv-decision">
  <div class="modal-head"><div>
    <h3 id="rv-title">Review target</h3>
    <div class="msub" id="rv-sub"></div>
  </div></div>
  <div class="modal-body">
    <p class="modal-text" id="rv-text"></p>
    <div class="field" style="margin-top:16px">
      <label id="rv-label">Note</label>
      <input class="input" name="review_remark" id="rv-remark" placeholder="">
    </div>
  </div>
  <div class="modal-foot">
    <button type="button" class="btn btn-outline btn-sm" onclick="this.closest('dialog').close()">Cancel</button>
    <button type="submit" class="btn btn-primary btn-sm" id="rv-go">Confirm</button>
  </div>
</form></dialog>

<script>
function editTarget(t) {
  document.getElementById('et-id').value     = t.id;
  document.getElementById('et-metric').value = t.metric || '';
  document.getElementById('et-dept').value   = t.department || '';
  document.getElementById('et-year').value   = t.academic_year || '';
  document.getElementById('et-tv').value     = t.target_value;
  document.getElementById('et-av').value     = t.achieved_value;
  document.getElementById('et-rem').value    = t.remarks || '';
  document.getElementById('et-note').textContent =
    t.status === 'Approved'
      ? 'This target is frozen. Your change is recorded against your name and it stays frozen.'
      : 'Status: ' + (t.status || 'Draft');
  document.getElementById('editDlg').showModal();
}

function delTarget(id, name) {
  document.getElementById('dt-id').value      = id;
  document.getElementById('dt-name').textContent = name;
  document.getElementById('delDlg').showModal();
}

/* One dialog serves both decisions — only the wording and whether the note is
   required change, so an approval and a send-back never drift apart. */
function reviewTarget(t, decision) {
  var approve = decision === 'approve';
  document.getElementById('rv-id').value       = t.id;
  document.getElementById('rv-decision').value = decision;
  document.getElementById('rv-title').textContent = approve ? 'Approve and freeze?' : 'Send back for changes?';
  document.getElementById('rv-sub').textContent   = t.metric + ' · ' + (t.dept || '—') + ' · target ' + t.target;
  document.getElementById('rv-text').textContent  = approve
    ? 'Once approved the target is frozen. The HoD can no longer edit it — only an Admin can change it after this.'
    : 'The HoD gets your note and can revise the target, then send it back up for review.';
  document.getElementById('rv-label').textContent = approve ? 'Note (optional)' : 'What needs changing?';
  var remark = document.getElementById('rv-remark');
  remark.value       = '';
  remark.required    = !approve;
  remark.placeholder = approve ? 'Anything worth recording' : 'e.g. raise this to 120 for the year';
  document.getElementById('rv-go').textContent = approve ? 'Approve & freeze' : 'Send back';
  document.getElementById('revDlg').showModal();
}
</script>
<?php require __DIR__ . '/inc/footer.php'; ?>
