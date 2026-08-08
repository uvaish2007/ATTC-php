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
require_module('targets');

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
            (string) input('remarks'),
            (string) input('coordinator')
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
            (string) input('remarks'),
            (string) input('coordinator')
        );
    } elseif ($action === 'submit') {
        [$ok, $msg] = target_submit((int) input('id'), $user);
    } elseif ($action === 'review') {
        [$ok, $msg] = target_review((int) input('id'), $user, (string) input('decision'), (string) input('review_remark'));
    } elseif ($action === 'delete') {
        [$ok, $msg] = target_delete((int) input('id'), $user);
    } elseif ($action === 'apply_count') {
        // Accept the "counted from approved records" figure into achieved_value.
        [$ok, $msg] = target_apply_count((int) input('id'), $user);

    // ---- Timed unlock workflow ----
    } elseif ($action === 'unlock_request' && $user['role'] === 'HoD') {
        [$ok, $msg] = unlock_request($user['department'] ?? null, (int) $user['id'], (string) input('reason'));
    } elseif ($action === 'unlock_grant' && $user['role'] === 'Admin') {
        $hours = (int) input('hours') ?: unlock_default_hours();
        [$ok, $msg] = unlock_grant((int) input('id'), (int) $user['id'], $hours);
    } elseif ($action === 'unlock_deny' && $user['role'] === 'Admin') {
        [$ok, $msg] = unlock_deny((int) input('id'), (int) $user['id'], (string) input('admin_note'));
    } else {
        [$ok, $msg] = [false, 'Unknown or not-permitted action.'];
    }

    flash($ok ? 'success' : 'error', $msg);
    redirect('/targets.php');
}

// Re-freeze any window that has run out before we read state for this page.
unlock_expire_due();

// A HoD only ever sees their own department; the other two choose.
$isHod      = $user['role'] === 'HoD';
// A HoD enters the targets — that is their job. The Admin's job is to freeze
// (approve) and unlock them, not to enter them. So only a HoD creates.
$canCreate  = $isHod;
$canManage  = in_array($user['role'], ['Admin', 'HoD'], true);   // may edit / delete within permission
$deptFilter = $isHod ? ($user['department'] ?? null) : (trim((string) ($_GET['department'] ?? '')) ?: null);
$yearFilter = trim((string) ($_GET['year'] ?? '')) ?: null;
$statFilter = in_array(($_GET['status'] ?? ''), target_statuses(), true) ? $_GET['status'] : null;

$targets     = targets_all($deptFilter, $yearFilter, $statFilter);
$departments = departments_all();
$metrics     = metric_names();
$years       = academic_years();
$awaiting    = count(array_filter($targets, fn($t) => target_can_review($t, $user)));

// Unlock workflow state:
//   HoD   sees their own department's lock/unlock banner and countdown.
//   Admin sees the queue of unlock requests waiting to be granted.
$myUnlock       = $isHod ? unlock_state($user['department'] ?? null) : null;
$pendingUnlocks = ($user['role'] === 'Admin') ? unlock_pending_all() : [];
$unlockHours    = unlock_default_hours();

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
    <?php $tgActive = ((!$isHod && $deptFilter) ? 1 : 0) + ($yearFilter ? 1 : 0) + ($statFilter ? 1 : 0); ?>
    <details class="filter-funnel">
      <summary class="btn btn-outline btn-sm">
        <?= icon('filter', 15) ?> Filters<?php if ($tgActive): ?> <span class="ff-dot"><?= $tgActive ?></span><?php endif; ?>
      </summary>
      <span class="filter-backdrop" onclick="this.closest('details').removeAttribute('open')"></span>
      <div class="filter-pop">
        <form method="get">
          <div class="ff-head">
            <span>Filter targets</span>
            <?php if ($tgActive): ?><a class="ff-clear" href="<?= e(url('targets.php')) ?>">Clear all</a><?php endif; ?>
          </div>
          <?php if (!$isHod): ?>
            <div class="ff-field"><label class="ff-label">Department</label>
              <select class="select" name="department" onchange="this.form.submit()">
                <option value="">All departments</option>
                <?php foreach ($departments as $d): ?>
                  <option value="<?= e($d['name']) ?>" <?= $deptFilter === $d['name'] ? 'selected' : '' ?>><?= e($d['name']) ?></option>
                <?php endforeach; ?>
              </select></div>
          <?php endif; ?>
          <div class="ff-field"><label class="ff-label">Academic Year</label>
            <select class="select" name="year" onchange="this.form.submit()">
              <option value="">All years</option>
              <?php foreach ($years as $y): ?>
                <option value="<?= e($y) ?>" <?= $yearFilter === $y ? 'selected' : '' ?>><?= e($y) ?></option>
              <?php endforeach; ?>
            </select></div>
          <div class="ff-field"><label class="ff-label">Status</label>
            <select class="select" name="status" onchange="this.form.submit()">
              <option value="">All statuses</option>
              <?php foreach (target_statuses() as $s): ?>
                <option value="<?= e($s) ?>" <?= $statFilter === $s ? 'selected' : '' ?>><?= e($s) ?></option>
              <?php endforeach; ?>
            </select></div>
          <div class="ff-actions"><button class="btn btn-primary btn-sm" type="submit"><?= icon('filter', 14) ?> Apply filters</button></div>
        </form>
      </div>
    </details>

    <?php
      // The meeting report always reflects what is on screen: same department
      // (forced to their own for a HoD) and the same year filter. Same report,
      // three formats — Word, Excel and a print-to-PDF view.
      $reportBase = array_filter([
          'department' => $isHod ? null : $deptFilter,
          'year'       => $yearFilter,
      ]);
      $reportUrl = fn(string $fmt) => e(url('meeting-report.php') . '?' . http_build_query($reportBase + ['format' => $fmt]));
    ?>
    <div class="report-dl" title="Executive Meeting Report">
      <span class="report-dl-label"><?= icon('download', 15) ?> Report</span>
      <a class="report-dl-fmt" href="<?= $reportUrl('word') ?>">Word</a>
      <a class="report-dl-fmt" href="<?= $reportUrl('excel') ?>">Excel</a>
      <a class="report-dl-fmt" href="<?= $reportUrl('pdf') ?>" target="_blank" rel="noopener">PDF</a>
    </div>

    <?php if ($canCreate): ?>
      <a class="btn btn-outline btn-sm" href="<?= e(url('target-import.php')) ?>" title="Bulk-import targets from a CSV">
        <?= icon('upload', 15) ?> Import CSV
      </a>
      <button class="btn btn-primary btn-sm" onclick="document.getElementById('addDlg').showModal()">
        <?= icon('plus') ?> Add Target
      </button>
    <?php endif; ?>
  </div>
</div>


<?php /* ---- HoD: lock / request / countdown banner ---- */ ?>
<?php if ($isHod && $myUnlock): ?>
  <?php if ($myUnlock['state'] === 'unlocked'): ?>
    <div class="unlock-banner open" data-until="<?= (int) $myUnlock['until'] * 1000 ?>">
      <div class="ub-ic"><?= icon('clock', 20) ?></div>
      <div class="ub-body">
        <div class="ub-title">Targets unlocked for editing</div>
        <div class="ub-sub">
          Total window <strong><?= (int) $myUnlock['hours'] ?>h</strong>
          &middot; Remaining <strong class="ub-remaining tabular">…</strong>
          &middot; edit and re-submit as many times as you need before it ends.
        </div>
      </div>
      <div class="ub-clock tabular">…</div>
    </div>
  <?php elseif ($myUnlock['state'] === 'requested'): ?>
    <div class="unlock-banner pending">
      <div class="ub-ic"><?= icon('clock', 20) ?></div>
      <div class="ub-body">
        <div class="ub-title">Unlock request awaiting the Admin</div>
        <div class="ub-sub">Reason: <?= e($myUnlock['pending']['reason'] ?? '') ?></div>
      </div>
    </div>
  <?php else: ?>
    <div class="unlock-banner locked">
      <div class="ub-ic"><?= icon('shield', 20) ?></div>
      <div class="ub-body">
        <div class="ub-title">Targets are locked</div>
        <div class="ub-sub">Ask the Admin to unlock them if you need to make a change.</div>
      </div>
      <button class="btn btn-secondary btn-sm" onclick="document.getElementById('unlockDlg').showModal()">
        <?= icon('key') ?> Request unlock
      </button>
    </div>
  <?php endif; ?>
<?php endif; ?>


<?php /* ---- Admin: queue of unlock requests to grant or deny ---- */ ?>
<?php if ($user['role'] === 'Admin' && !empty($pendingUnlocks)): ?>
  <div class="card" style="border-left:3px solid var(--orange-500)">
    <div class="card-head">
      <div>
        <div class="card-title"><?= icon('key', 16) ?> Unlock requests</div>
        <div class="card-sub"><?= count($pendingUnlocks) ?> department<?= count($pendingUnlocks) !== 1 ? 's' : '' ?> asking to edit locked targets</div>
      </div>
    </div>
    <div class="card-body" style="padding:0">
      <div class="table-wrap"><table class="data" style="min-width:760px"><thead><tr>
        <th style="padding-left:24px">Department</th><th>Requested by</th><th>Reason</th><th>When</th>
        <th class="num" style="padding-right:24px">Decision</th>
      </tr></thead><tbody>
        <?php foreach ($pendingUnlocks as $req): ?>
          <tr>
            <td style="padding-left:24px" class="fw-500"><?= e($req['department']) ?></td>
            <td class="card-sub"><?= e($req['requester_name'] ?? '—') ?></td>
            <td><?= e($req['reason'] ?? '') ?></td>
            <td class="card-sub nowrap"><?= e(time_ago($req['created_at'])) ?></td>
            <td class="num" style="padding-right:24px">
              <div class="dept-actions" style="justify-content:flex-end">
                <form method="post" class="flex gap-2 items-center">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="unlock_grant">
                  <input type="hidden" name="id" value="<?= (int) $req['id'] ?>">
                  <input class="input" type="number" name="hours" value="<?= $unlockHours ?>" min="1" max="720"
                         title="Hours to allow editing" style="width:74px">
                  <button class="btn btn-primary btn-sm"><?= icon('check') ?> Unlock</button>
                </form>
                <form method="post" onsubmit="return confirm('Deny this unlock request?')">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="unlock_deny">
                  <input type="hidden" name="id" value="<?= (int) $req['id'] ?>">
                  <button class="btn btn-outline btn-sm"><?= icon('x') ?></button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody></table></div>
    </div>
  </div>
<?php endif; ?>


<?php if (empty($targets)): ?>

  <div class="card"><div class="card-body">
    <div class="empty">
      <div class="ic"><?= icon('target', 20) ?></div>
      <p>No targets here</p>
      <div class="note">
        <?= $canCreate ? 'Add a target to start tracking department progress.' : 'Targets appear here once a HoD sends one up for review.' ?>
      </div>
    </div>
  </div></div>

<?php else: ?>

  <?php
    // Group every target under its department, so each department is its own
    // card — not one long mixed list.
    $byDept = [];
    foreach ($targets as $t) {
        $key = ($t['department'] ?? '') !== '' ? $t['department'] : 'Unassigned';
        $byDept[$key][] = $t;
    }
    ksort($byDept, SORT_NATURAL | SORT_FLAG_CASE);
  ?>

  <?php foreach ($byDept as $deptName => $deptTargets): ?>
    <?php
      $dSumT = array_sum(array_map(fn($x) => (int) $x['target_value'], $deptTargets));
      $dSumA = array_sum(array_map(fn($x) => (int) $x['achieved_value'], $deptTargets));
      $dPct  = $dSumT > 0 ? min(100, (int) round($dSumA / $dSumT * 100)) : 0;
      $dCol  = $dPct >= 100 ? '#10B981' : ($dPct >= 50 ? 'var(--orange-500)' : '#EF4444');
    ?>
    <details class="card tg-group">
      <summary class="tg-group-head">
        <span class="tg-dept"><?= icon('building', 15) ?> <?= e($deptName) ?></span>
        <span class="badge badge-neutral"><?= count($deptTargets) ?> target<?= count($deptTargets) !== 1 ? 's' : '' ?></span>
        <span class="tg-overall">
          <span class="tabular" style="font-weight:600;color:<?= $dCol ?>"><?= $dPct ?>%</span>
          <span class="tg-bar"><span style="width:<?= $dPct ?>%;background:<?= $dCol ?>"></span></span>
        </span>
        <span class="tg-chev"><?= icon('chevron', 16) ?></span>
      </summary>

      <div class="table-wrap"><table class="data" style="min-width:760px">
        <thead><tr>
          <th style="padding-left:24px">Metric</th>
          <th>Year</th>
          <th>Status</th>
          <th class="num">Target</th>
          <th class="num">Achieved</th>
          <th class="num">Progress</th>
          <th class="num" style="padding-right:24px">Actions</th>
        </tr></thead>
        <tbody>
        <?php foreach ($deptTargets as $t): ?>
          <?php
            $pct      = $t['target_value'] > 0 ? min(100, round($t['achieved_value'] / $t['target_value'] * 100)) : 0;
            $barColor = $pct >= 100 ? '#10B981' : ($pct >= 50 ? 'var(--orange-500)' : '#EF4444');
            $frozen   = target_is_frozen($t);
            $status   = (string) ($t['status'] ?? 'Draft');
            // Non-destructive suggestion: approved records backing this target,
            // or null when the metric is a manual (non-record) proforma row.
            $recCount = target_record_count($t);
          ?>
          <tr>
            <td style="padding-left:24px">
              <div style="font-weight:500"><?= e($t['metric']) ?></div>
              <?php if (!empty($t['coordinator'])): ?>
                <div class="card-sub"><?= icon('user', 12) ?> <?= e($t['coordinator']) ?></div>
              <?php endif; ?>
              <?php if (!empty($t['remarks'])): ?>
                <div class="card-sub"><?= e($t['remarks']) ?></div>
              <?php endif; ?>
              <?php if ($status === 'Changes Requested' && !empty($t['review_remark'])): ?>
                <div class="card-sub" style="color:#B45309;margin-top:2px">
                  <?= icon('alert-triangle', 12) ?> <?= e($t['review_remark']) ?>
                </div>
              <?php endif; ?>
            </td>
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
            <td class="num tabular" style="font-weight:600">
              <?= (int) $t['achieved_value'] ?>
              <?php if ($recCount !== null): ?>
                <div class="rec-suggest">
                  <span class="rec-count" title="Approved records of this type in scope"><?= icon('file-stack', 11) ?> <?= (int) $recCount ?> in records</span>
                  <?php if ($recCount !== (int) $t['achieved_value'] && target_can_edit($t, $user)): ?>
                    <form method="post" style="display:inline">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="apply_count">
                      <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                      <button class="rec-use" title="Set achieved to <?= (int) $recCount ?> from approved records">Use</button>
                    </form>
                  <?php endif; ?>
                </div>
              <?php endif; ?>
            </td>
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
    </details>
  <?php endforeach; ?>

<?php endif; ?>

<?php if ($canCreate): ?>
<!-- Add dialog. A HoD's department is fixed by their account, so it is shown, not chosen. -->
<dialog class="modal" id="addDlg"><form method="post"><?= csrf_field() ?>
  <input type="hidden" name="action" value="create">
  <div class="modal-head"><div>
    <h3>Add Target</h3>
    <div class="msub"><?= $isHod ? 'Saved as a draft — send it for review when ready.' : 'Created by an Admin, so it is frozen straight away.' ?></div>
  </div></div>
  <div class="modal-body" style="display:grid;grid-template-columns:1fr 1fr;gap:0 16px">
    <div class="field" style="grid-column:span 2"><label>Target / Details <span class="req">*</span></label>
      <input class="input" name="metric" list="metricList" required autocomplete="off"
             placeholder="e.g. Pass Percentage, Journal Publications, NPTEL…"></div>
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
    <div class="field"><label>Fixed (target value) <span class="req">*</span></label>
      <input class="input" type="number" name="target_value" min="0" required></div>
    <div class="field"><label>Coordinator</label>
      <input class="input" name="coordinator" placeholder="Responsible person"></div>
    <div class="field" style="grid-column:span 2"><label>Progress / Remarks</label>
      <input class="input" name="remarks" placeholder="Optional notes"></div>
  </div>
  <div class="modal-foot">
    <button type="button" class="btn btn-outline btn-sm" onclick="this.closest('dialog').close()">Cancel</button>
    <button type="submit" class="btn btn-primary btn-sm">Add</button>
  </div>
</form></dialog>
<?php endif; ?>

<?php if ($canManage): ?>
<!-- Edit dialog -->
<dialog class="modal" id="editDlg"><form method="post"><?= csrf_field() ?>
  <input type="hidden" name="action" value="update">
  <input type="hidden" name="id" id="et-id">
  <div class="modal-head"><div>
    <h3>Edit Target</h3>
    <div class="msub" id="et-note"></div>
  </div></div>
  <div class="modal-body" style="display:grid;grid-template-columns:1fr 1fr;gap:0 16px">
    <div class="field" style="grid-column:span 2"><label>Target / Details</label>
      <input class="input" name="metric" id="et-metric" list="metricList" autocomplete="off" required></div>
    <div class="field"><label>Department</label>
      <select class="select" name="department" id="et-dept" <?= $isHod ? 'disabled' : '' ?>>
        <?php foreach ($departments as $d): ?><option value="<?= e($d['name']) ?>"><?= e($d['name']) ?></option><?php endforeach; ?>
      </select></div>
    <div class="field"><label>Year</label>
      <select class="select" name="academic_year" id="et-year">
        <?php foreach ($years as $y): ?><option><?= e($y) ?></option><?php endforeach; ?>
      </select></div>
    <div class="field"><label>Fixed (target value)</label>
      <input class="input" type="number" name="target_value" id="et-tv" min="0" required></div>
    <div class="field"><label>Achieved value</label>
      <input class="input" type="number" name="achieved_value" id="et-av" min="0"></div>
    <div class="field"><label>Coordinator</label>
      <input class="input" name="coordinator" id="et-coord"></div>
    <div class="field"><label>Progress / Remarks</label>
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

<?php if ($isHod && $myUnlock && $myUnlock['state'] === 'locked'): ?>
<!-- HoD asks the Admin to open a timed edit window on the locked targets -->
<dialog class="modal" id="unlockDlg" style="max-width:30rem"><form method="post"><?= csrf_field() ?>
  <input type="hidden" name="action" value="unlock_request">
  <div class="modal-head"><div>
    <h3>Request unlock</h3>
    <div class="msub">The Admin grants a timed window; you can then edit and re-submit until it ends.</div>
  </div></div>
  <div class="modal-body">
    <div class="field"><label>Why do the locked targets need changing? <span class="req">*</span></label>
      <input class="input" name="reason" required placeholder="e.g. revise the FDP target after the new circular">
    </div>
  </div>
  <div class="modal-foot">
    <button type="button" class="btn btn-outline btn-sm" onclick="this.closest('dialog').close()">Cancel</button>
    <button type="submit" class="btn btn-primary btn-sm">Send request</button>
  </div>
</form></dialog>
<?php endif; ?>

<!-- Suggestions for the free-text Target box: the configured metric names,
     offered as a convenience but never a limit. -->
<datalist id="metricList">
  <?php foreach ($metrics as $m): ?><option value="<?= e($m) ?>"></option><?php endforeach; ?>
</datalist>

<script>
function editTarget(t) {
  document.getElementById('et-id').value     = t.id;
  document.getElementById('et-metric').value = t.metric || '';
  document.getElementById('et-dept').value   = t.department || '';
  document.getElementById('et-year').value   = t.academic_year || '';
  document.getElementById('et-tv').value     = t.target_value;
  document.getElementById('et-av').value     = t.achieved_value;
  document.getElementById('et-coord').value  = t.coordinator || '';
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
    ? 'Once approved the target is frozen. The HoD cannot edit it unless you grant a timed unlock; an Admin can change it anytime.'
    : 'The HoD gets your note and can revise the target, then send it back up for review.';
  document.getElementById('rv-label').textContent = approve ? 'Note (optional)' : 'What needs changing?';
  var remark = document.getElementById('rv-remark');
  remark.value       = '';
  remark.required    = !approve;
  remark.placeholder = approve ? 'Anything worth recording' : 'e.g. raise this to 120 for the year';
  document.getElementById('rv-go').textContent = approve ? 'Approve & freeze' : 'Send back';
  document.getElementById('revDlg').showModal();
}

/* Live countdown for the unlock window. Counts down to the exact instant the
   Admin's grant expires; when it hits zero the window is over, so we reload so
   the server re-locks the targets and the edit buttons disappear. */
(function () {
  var banner = document.querySelector('.unlock-banner.open');
  if (!banner) return;
  var until = parseInt(banner.getAttribute('data-until'), 10);
  var remainEl = banner.querySelector('.ub-remaining');
  var clockEl  = banner.querySelector('.ub-clock');

  function pad(n) { return (n < 10 ? '0' : '') + n; }

  function tick() {
    var ms = until - Date.now();
    if (ms <= 0) {
      clockEl.textContent = '00:00:00';
      if (remainEl) remainEl.textContent = 'expired';
      location.reload();
      return;
    }
    var s = Math.floor(ms / 1000);
    var h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60), sec = s % 60;
    var text = pad(h) + ':' + pad(m) + ':' + pad(sec);
    clockEl.textContent = text;
    if (remainEl) remainEl.textContent = h + 'h ' + pad(m) + 'm ' + pad(sec) + 's';
  }
  tick();
  setInterval(tick, 1000);
})();
</script>
<?php require __DIR__ . '/inc/footer.php'; ?>
