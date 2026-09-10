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

$user = require_role(['Admin', 'HoD', 'Director', 'Dean']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) input('action');

    if ($action === 'create' || $action === 'create_and_submit') {
        $targetStatus = ($action === 'create_and_submit') ? 'Dean Pending' : 'Draft';
        [$ok, $msg] = target_create(
            $user,
            (string) input('department'),
            (string) input('academic_year'),
            (string) input('metric'),
            (int) input('target_value'),
            (string) input('remarks'),
            (string) input('coordinator'),
            $targetStatus,
            (string) input('target_deadline')
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
            (string) input('coordinator'),
            (string) input('target_deadline'),
            input('fixed_text') !== null ? (string) input('fixed_text') : null
        );
    } elseif ($action === 'update_deadline') {
        $id = (int) input('id');
        $rawDeadline = trim((string) input('target_deadline'));
        $targetDeadline = null;
        if ($rawDeadline !== '') {
            $parsed = parse_date_input($rawDeadline);
            if ($parsed) {
                $targetDeadline = $parsed;
            } else {
                $d = DateTime::createFromFormat('Y-m-d', $rawDeadline);
                if ($d && $d->format('Y-m-d') === $rawDeadline) {
                    $targetDeadline = $rawDeadline;
                }
            }
        }
        $existing = target_find($id);
        if (!$existing) {
            [$ok, $msg] = [false, 'Target not found.'];
        } elseif (!target_can_edit($existing, $user)) {
            [$ok, $msg] = [false, 'You cannot edit this target.'];
        } else {
            db()->prepare('UPDATE targets SET target_deadline = ?, updated_at = NOW() WHERE id = ?')->execute([$targetDeadline, $id]);
            [$ok, $msg] = [true, 'Target deadline updated.'];
        }

        if (!empty($_POST['ajax'])) {
            header('Content-Type: application/json');
            echo json_encode([
                'ok'        => $ok,
                'msg'       => $msg,
                'formatted' => $targetDeadline ? date('d-m-Y', strtotime($targetDeadline)) : '—'
            ]);
            exit;
        }
    } elseif ($action === 'submit_all' && in_array($user['role'], ['HoD', 'Dean'], true)) {
        $dept = $user['department'] ?? (trim((string) input('department')) ?: 'CSE');
        $year = trim((string) input('academic_year')) ?: '2025-26';
        $stmt = db()->prepare("UPDATE targets SET status = 'Dean Pending', submitted_at = NOW() WHERE department = ? AND academic_year = ? AND status IN ('Draft', 'Changes Requested')");
        $stmt->execute([$dept, $year]);
        $count = $stmt->rowCount();
        [$ok, $msg] = [true, "$count target" . ($count !== 1 ? 's' : '') . " submitted for Dean review."];
    } elseif ($action === 'submit') {
        [$ok, $msg] = target_submit((int) input('id'), $user);
    } elseif ($action === 'review') {
        [$ok, $msg] = target_review((int) input('id'), $user, (string) input('decision'), (string) input('review_remark'));
    } elseif ($action === 'bulk_approve' && in_array($user['role'], ['Dean', 'Admin', 'Director'], true)) {
        $dept = trim((string) input('department')) ?: null;
        $year = trim((string) input('academic_year')) ?: null;
        [$ok, $msg] = targets_bulk_approve($user, $dept, $year);
    } elseif ($action === 'delete') {
        [$ok, $msg] = target_delete((int) input('id'), $user);
    } elseif ($action === 'apply_count') {
        // Accept the "counted from approved records" figure into achieved_value.
        [$ok, $msg] = target_apply_count((int) input('id'), $user);

    // ---- Timed unlock workflow ----
    } elseif ($action === 'unlock_request' && in_array($user['role'], ['HoD', 'Dean'], true)) {
        $unlockDept = $user['department'] ?? (trim((string) input('department')) ?: 'CSE');
        [$ok, $msg] = unlock_request($unlockDept, (int) $user['id'], (string) input('reason'));
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

// A HoD or Dean enters and manages targets for their scope; the other roles choose.
$isHod       = $user['role'] === 'HoD';
$isDean      = $user['role'] === 'Dean';
$isHodOrDean = $isHod || $isDean;

$canCreate   = $isHod;
$canManage   = in_array($user['role'], ['Admin', 'HoD', 'Dean'], true);
$deptFilter   = $isHod ? ($user['department'] ?? null) : (trim((string) ($_GET['department'] ?? '')) ?: null);
$yearFilter   = trim((string) ($_GET['year'] ?? '')) ?: null;
$statFilter   = in_array(($_GET['status'] ?? ''), target_statuses(), true) ? $_GET['status'] : null;
$metricFilter = trim((string) ($_GET['metric'] ?? '')) ?: null;

// Dean must only see HOD-submitted targets awaiting approval (or reviewed ones), NEVER HOD Drafts
if ($isDean) {
    if ($statFilter === null || $statFilter === 'Draft') {
        $statFilter = 'Dean Pending';
    }
}

// Safe automatic seeding: ONLY for HoD for their own department; never seed on Dean browsing
$seedYear = $yearFilter ?: '2025-26';
if ($isHod && !empty($user['department'])) {
    ensure_default_targets($user['department'], $seedYear, (int) $user['id']);
}

$targets     = targets_all($deptFilter, $yearFilter, $statFilter, $metricFilter, $isDean);
$departments = departments_all();
$metrics     = metric_names();
$years       = academic_years();
$awaiting    = count(array_filter($targets, fn($t) => target_can_review($t, $user)));

// Unlock workflow state:
//   HoD/Dean sees their department's lock/unlock banner and countdown.
//   Admin sees the queue of unlock requests waiting to be granted.
$unlockDept     = $user['department'] ?? ($deptFilter ?: 'CSE');
$myUnlock       = $isHodOrDean ? unlock_state($unlockDept) : null;
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
    <?php $tgActive = ((!$isHod && $deptFilter) ? 1 : 0) + ($yearFilter ? 1 : 0) + ($statFilter ? 1 : 0) + ($metricFilter ? 1 : 0); ?>
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
          <div class="ff-field"><label class="ff-label">Metric</label>
            <select class="select" name="metric" onchange="this.form.submit()">
              <option value="">All metrics</option>
              <?php foreach ($metrics as $m): ?>
                <option value="<?= e($m) ?>" <?= $metricFilter === $m ? 'selected' : '' ?>><?= e($m) ?></option>
              <?php endforeach; ?>
            </select></div>
          <div class="ff-field"><label class="ff-label">Status</label>
            <select class="select" name="status" onchange="this.form.submit()">
              <?php if ($isDean): ?>
                <option value="Dean Pending" <?= $statFilter === 'Dean Pending' ? 'selected' : '' ?>>Dean Pending</option>
                <option value="Approved" <?= $statFilter === 'Approved' ? 'selected' : '' ?>>Approved</option>
                <option value="Changes Requested" <?= $statFilter === 'Changes Requested' ? 'selected' : '' ?>>Changes Requested</option>
              <?php else: ?>
                <option value="">All statuses</option>
                <?php foreach (target_statuses() as $s): ?>
                  <option value="<?= e($s) ?>" <?= $statFilter === $s ? 'selected' : '' ?>><?= e($s) ?></option>
                <?php endforeach; ?>
              <?php endif; ?>
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


<?php /* ---- HoD / Dean: lock / request / countdown banner ---- */ ?>
<?php if ($isHodOrDean && $myUnlock): ?>
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
      $draftCount = count(array_filter($deptTargets, fn($x) => in_array($x['status'] ?? 'Draft', ['Draft', 'Changes Requested'], true)));
    ?>
    <details class="card tg-group" <?= $isHod ? 'open' : '' ?>>
      <summary class="tg-group-head">
        <span class="tg-dept"><?= icon('building', 15) ?> <?= e($deptName) ?></span>
        <span class="badge badge-neutral"><?= count($deptTargets) ?> target<?= count($deptTargets) !== 1 ? 's' : '' ?></span>
        <span class="tg-overall">
          <span class="tabular" style="font-weight:600;color:<?= $dCol ?>"><?= $dPct ?>%</span>
          <span class="tg-bar"><span style="width:<?= $dPct ?>%;background:<?= $dCol ?>"></span></span>
        </span>
        <?php if ($isHod && $draftCount > 0): ?>
          <form method="post" style="display:inline;margin-left:auto;margin-right:12px" onclick="event.stopPropagation()">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="submit_all">
            <input type="hidden" name="department" value="<?= e($deptName) ?>">
            <input type="hidden" name="academic_year" value="<?= e($yearFilter ?: '2025-26') ?>">
            <button type="submit" class="btn btn-primary btn-sm" onclick="return confirm('Submit all <?= $draftCount ?> targets to the Dean for review?')">
              <?= icon('send', 14) ?> Submit All for Review (<?= $draftCount ?>)
            </button>
          </form>
        <?php endif; ?>
        <span class="tg-chev"><?= icon('chevron', 16) ?></span>
      </summary>

      <div class="table-wrap"><table class="data" style="min-width:760px">
        <thead><tr>
          <?php if ($isHod): ?>
            <th style="padding-left:24px;width:75px">S.No</th>
            <th style="min-width:320px">Target Details</th>
            <th class="num" style="width:130px">Fixed Target</th>
            <th style="width:210px">Target Deadline</th>
            <th style="width:150px">Status</th>
            <th class="num" style="padding-right:24px;width:130px">Actions</th>
          <?php else: ?>
            <th style="padding-left:24px">Metric</th>
            <th>Year</th>
            <th>Status</th>
            <th class="num">Target</th>
            <th>Deadline</th>
            <th class="num">Achieved</th>
            <th class="num">Progress</th>
            <th class="num" style="padding-right:24px">Actions</th>
          <?php endif; ?>
        </tr></thead>
        <tbody>
        <?php foreach ($deptTargets as $index => $t): ?>
          <?php
            $pct      = $t['target_value'] > 0 ? min(100, round($t['achieved_value'] / $t['target_value'] * 100)) : 0;
            $barColor = $pct >= 100 ? '#10B981' : ($pct >= 50 ? 'var(--orange-500)' : '#EF4444');
            $frozen   = target_is_frozen($t);
            $status   = (string) ($t['status'] ?? 'Draft');
            // Non-destructive suggestion: only compute for non-HoD view where the [Use] button is actually shown
            $recCount = !$isHod ? target_record_count($t) : null;
          ?>
          <tr>
            <?php if ($isHod): ?>
              <td style="padding-left:24px;font-weight:600;color:var(--navy-700);white-space:nowrap">
                <?= (int) ($index + 1) ?>
              </td>
              <td>
                <div style="font-weight:600;color:var(--navy-900);line-height:1.45"><?= e($t['metric']) ?></div>
                <?php if (!empty($t['academic_year']) && !$yearFilter): ?>
                  <div class="card-sub" style="font-size:12px;margin-top:2px"><?= icon('calendar', 12) ?> <?= e($t['academic_year']) ?></div>
                <?php endif; ?>
                <?php if (!empty($t['coordinator'])): ?>
                  <div class="card-sub"><?= icon('user', 12) ?> Coordinator: <?= e($t['coordinator']) ?></div>
                <?php endif; ?>
                <?php if (!empty($t['remarks'])): ?>
                  <div class="card-sub"><?= e($t['remarks']) ?></div>
                <?php endif; ?>
                <?php if ($status === 'Changes Requested' && !empty($t['review_remark'])): ?>
                  <div class="card-sub" style="color:#B45309;margin-top:3px;font-weight:500">
                    <?= icon('alert-triangle', 12) ?> Dean's note: <?= e($t['review_remark']) ?>
                  </div>
                <?php endif; ?>
              </td>
              <td class="num tabular" style="font-weight:700;font-size:14px;color:var(--navy-800);white-space:nowrap">
                <?= e(!empty($t['fixed_text']) ? $t['fixed_text'] : ($t['target_value'] > 0 ? (string)$t['target_value'] : '-')) ?>
              </td>
              <td>
                <?php if (target_can_edit($t, $user)): ?>
                  <form method="post" class="deadline-form" onsubmit="return handleDeadlineSubmit(event, this)">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="update_deadline">
                    <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                    <input type="hidden" name="ajax" value="1">
                    <div style="display:flex;align-items:center;gap:6px">
                      <input type="date" class="input input-sm deadline-picker" name="target_deadline"
                             value="<?= e($t['target_deadline'] ?? '') ?>"
                             onchange="saveDeadlineToServer(this.form)"
                             title="Pick deadline"
                             style="width:125px;height:30px;padding:2px 6px;font-size:12px">
                      <span class="deadline-display card-sub tabular" style="font-size:11px;font-weight:600;min-width:70px">
                        <?= !empty($t['target_deadline']) ? date('d-m-Y', strtotime($t['target_deadline'])) : '—' ?>
                      </span>
                    </div>
                  </form>
                <?php else: ?>
                  <div style="display:flex;align-items:center;gap:4px">
                    <?php if (!empty($t['target_deadline'])): ?>
                      <?php
                        $deadlineTs = strtotime($t['target_deadline']);
                        $isOverdue = ($deadlineTs < strtotime('today') && $status !== 'Approved');
                      ?>
                      <span class="tabular font-medium" style="font-size:13px;<?= $isOverdue ? 'color:#DC2626;font-weight:600' : 'color:var(--ink)' ?>">
                        <?= icon('clock', 12) ?> <?= date('d-m-Y', $deadlineTs) ?>
                      </span>
                      <?php if ($isOverdue): ?>
                        <span class="badge badge-danger" style="font-size:10px;padding:1px 5px">Overdue</span>
                      <?php endif; ?>
                    <?php else: ?>
                      <span class="card-sub">—</span>
                    <?php endif; ?>
                  </div>
                <?php endif; ?>
              </td>
              <td>
                <?php if ($status === 'Draft'): ?>
                  <span class="badge badge-neutral">Draft</span>
                <?php elseif ($status === 'Pending Review' || $status === 'Dean Pending'): ?>
                  <span class="badge badge-info" title="Awaiting Dean review"><?= icon('clock', 11) ?> Dean Pending</span>
                <?php elseif ($status === 'Changes Requested'): ?>
                  <span class="badge badge-warning" title="Dean requested changes"><?= icon('alert-triangle', 11) ?> Changes Requested</span>
                <?php elseif ($status === 'Approved'): ?>
                  <span class="badge badge-success" title="Approved & Frozen"><?= icon('shield', 11) ?> Approved</span>
                <?php else: ?>
                  <span class="badge badge-<?= target_status_class($status) ?>"><?= e($status) ?></span>
                <?php endif; ?>
                <?php if ($frozen && !empty($t['approver_name'])): ?>
                  <div class="card-sub" style="margin-top:2px;font-size:11px">by <?= e($t['approver_name']) ?></div>
                <?php endif; ?>
              </td>
              <td class="num" style="padding-right:24px">
                <div class="dept-actions" style="justify-content:flex-end">
                  <button type="button" class="mini-btn" title="View Target Details"
                          onclick='viewTarget(<?= e(json_encode($t)) ?>)'><?= icon('eye', 15) ?></button>
                  <?php if (target_can_submit($t, $user)): ?>
                    <form method="post" style="display:inline">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="submit">
                      <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                      <button class="mini-btn" title="Send for Dean review" style="color:var(--orange-500)"><?= icon('send', 15) ?></button>
                    </form>
                  <?php endif; ?>
                  <?php if (target_can_edit($t, $user)): ?>
                    <button class="mini-btn" title="<?= $frozen ? 'Edit frozen target' : 'Edit' ?>"
                            onclick='editTarget(<?= e(json_encode($t)) ?>)'><?= icon('pencil', 15) ?></button>
                  <?php endif; ?>
                  <?php if (target_can_delete($t, $user)): ?>
                    <button class="mini-btn danger" title="Delete"
                            onclick='delTarget(<?= (int) $t["id"] ?>, "<?= e($t["metric"]) ?>")'><?= icon('trash', 15) ?></button>
                  <?php endif; ?>
                  <?php if (in_array($status, ['Pending Review', 'Dean Pending'], true) && !target_can_review($t, $user)): ?>
                    <span class="card-sub" title="Awaiting Dean review"><?= icon('clock', 14) ?></span>
                  <?php endif; ?>
                </div>
              </td>
            <?php else: ?>
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
              <td>
                <?php if (!empty($t['target_deadline'])): ?>
                  <?php
                    $deadlineTs = strtotime($t['target_deadline']);
                    $isOverdue = ($deadlineTs < strtotime('today') && $status !== 'Approved');
                  ?>
                  <div style="font-weight:500;font-size:13px;<?= $isOverdue ? 'color:#DC2626;' : '' ?>">
                    <?= icon('clock', 12) ?> <?= date('d M Y', $deadlineTs) ?>
                  </div>
                  <?php if ($isOverdue): ?>
                    <span class="badge badge-danger" style="font-size:10px;padding:1px 6px">Overdue</span>
                  <?php endif; ?>
                <?php else: ?>
                  <span class="card-sub">—</span>
                <?php endif; ?>
              </td>
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
                  <button type="button" class="mini-btn" title="View Target Details"
                          onclick='viewTarget(<?= e(json_encode($t)) ?>)'><?= icon('eye', 15) ?></button>
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
            <?php endif; ?>
          </tr>
        <?php endforeach; ?>
        </tbody></table></div>
    </details>
  <?php endforeach; ?>

  <?php if ($isDean && $awaiting > 0): ?>
    <div style="margin-top:24px;margin-bottom:12px;display:flex;justify-content:flex-end">
      <form method="post" id="bulkApproveForm" onsubmit="return confirmBulkApprove(event, this)">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="bulk_approve">
        <input type="hidden" name="department" value="<?= e($deptFilter ?? '') ?>">
        <input type="hidden" name="academic_year" value="<?= e($yearFilter ?? '') ?>">
        <button type="submit" class="btn btn-primary" style="font-weight:600;padding:10px 22px;display:inline-flex;align-items:center;gap:8px;box-shadow:0 2px 6px rgba(249,115,22,0.25)">
          <?= icon('check', 16) ?> Approve All Targets
        </button>
      </form>
    </div>
  <?php endif; ?>

<?php endif; ?>

<?php if ($canCreate): ?>
<!-- Add dialog. A HoD's department is fixed by their account, so it is shown, not chosen. -->
<dialog class="modal" id="addDlg"><form method="post"><?= csrf_field() ?>
  <div class="modal-head"><div>
    <h3>Add Target</h3>
    <div class="msub">Save as a draft to edit later, or submit for review immediately.</div>
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
    <div class="field"><label>Target Deadline</label>
      <input class="input" type="date" name="target_deadline"></div>
    <div class="field"><label>Coordinator</label>
      <input class="input" name="coordinator" placeholder="Responsible person"></div>
    <div class="field" style="grid-column:span 2"><label>Progress / Remarks</label>
      <input class="input" name="remarks" placeholder="Optional notes"></div>
  </div>
  <div class="modal-foot">
    <button type="button" class="btn btn-outline btn-sm" onclick="this.closest('dialog').close()">Cancel</button>
    <button type="submit" name="action" value="create" class="btn btn-outline btn-sm">Save Draft</button>
    <button type="submit" name="action" value="create_and_submit" class="btn btn-primary btn-sm">Submit for Review</button>
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
    <div class="field"><label>Fixed Target</label>
      <input class="input" type="text" name="fixed_text" id="et-fixed-text" placeholder="e.g. 86 %, 10 Lakhs, 50">
      <input type="hidden" name="target_value" id="et-tv"></div>
    <div class="field"><label>Target Deadline</label>
      <input class="input" type="date" name="target_deadline" id="et-dl"></div>
    <div class="field"><label>Achieved value</label>
      <input class="input" type="number" name="achieved_value" id="et-av" min="0"></div>
    <div class="field"><label>Coordinator</label>
      <input class="input" name="coordinator" id="et-coord"></div>
    <div class="field" style="grid-column:span 2"><label>Progress / Remarks</label>
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

<?php if ($isHodOrDean && $myUnlock && $myUnlock['state'] === 'locked'): ?>
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

<!-- View Target dialog -->
<dialog class="modal" id="viewDlg" style="max-width:32rem">
  <div class="modal-head"><div>
    <h3 id="vt-title">Target Details</h3>
    <div class="msub" id="vt-dept-year"></div>
  </div></div>
  <div class="modal-body" style="display:flex;flex-direction:column;gap:14px">
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;background:var(--navy-50, #f8fafc);padding:14px;border-radius:8px">
      <div>
        <div class="card-sub" style="font-size:11px;text-transform:uppercase;letter-spacing:0.5px">Fixed Target</div>
        <div id="vt-target" style="font-size:18px;font-weight:700;color:var(--navy-900, #0f172a)">—</div>
      </div>
      <div>
        <div class="card-sub" style="font-size:11px;text-transform:uppercase;letter-spacing:0.5px">Target Deadline</div>
        <div id="vt-deadline" style="font-size:15px;font-weight:600;color:var(--navy-800, #1e293b)">—</div>
      </div>
      <div>
        <div class="card-sub" style="font-size:11px;text-transform:uppercase;letter-spacing:0.5px">Status</div>
        <div id="vt-status" style="margin-top:3px"></div>
      </div>
      <div>
        <div class="card-sub" style="font-size:11px;text-transform:uppercase;letter-spacing:0.5px">Coordinator</div>
        <div id="vt-coordinator" style="font-size:14px;font-weight:500;margin-top:2px">—</div>
      </div>
    </div>
    <div>
      <div class="card-sub" style="font-weight:600;margin-bottom:4px">Target Metric / Details:</div>
      <div id="vt-metric" style="font-weight:500;font-size:14px;line-height:1.4"></div>
    </div>
    <div id="vt-remarks-box">
      <div class="card-sub" style="font-weight:600;margin-bottom:2px">Remarks / Notes:</div>
      <div id="vt-remarks" class="card-sub" style="color:var(--navy-700)">—</div>
    </div>
    <div id="vt-review-box" style="display:none;background:#fffbeb;border:1px solid #fef3c7;padding:10px 12px;border-radius:6px">
      <div style="font-size:12px;font-weight:600;color:#b45309">Dean / Reviewer Remark:</div>
      <div id="vt-review-remark" style="font-size:13px;color:#92400e;margin-top:2px"></div>
    </div>
  </div>
  <div class="modal-foot">
    <button type="button" class="btn btn-outline btn-sm" onclick="this.closest('dialog').close()">Close</button>
  </div>
</dialog>

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
  document.getElementById('et-tv').value     = (t.target_value != null ? t.target_value : 0);
  var fixedEl = document.getElementById('et-fixed-text');
  if (fixedEl) fixedEl.value = t.fixed_text || (t.target_value != null ? t.target_value : '');
  document.getElementById('et-dl').value     = t.target_deadline || '';
  document.getElementById('et-av').value     = (t.achieved_value != null ? t.achieved_value : 0);
  document.getElementById('et-coord').value  = t.coordinator || '';
  document.getElementById('et-rem').value    = t.remarks || '';
  document.getElementById('et-note').textContent =
    t.status === 'Approved'
      ? 'This target is frozen. Your change is recorded against your name and it stays frozen.'
      : 'Status: ' + (t.status || 'Draft');
  document.getElementById('editDlg').showModal();
}

function viewTarget(t) {
  document.getElementById('vt-title').textContent = t.metric || 'Target Details';
  document.getElementById('vt-dept-year').textContent = (t.department || '') + (t.academic_year ? ' · ' + t.academic_year : '');
  document.getElementById('vt-target').textContent = t.fixed_text || (t.target_value != null ? t.target_value : '—');

  var dl = t.target_deadline;
  if (dl) {
    var parts = dl.split('-');
    if (parts.length === 3) {
      document.getElementById('vt-deadline').textContent = parts[2] + '-' + parts[1] + '-' + parts[0];
    } else {
      document.getElementById('vt-deadline').textContent = dl;
    }
  } else {
    document.getElementById('vt-deadline').textContent = 'None set';
  }

  var stMap = {
    'Approved': 'success',
    'Changes Requested': 'warning',
    'Pending Review': 'info',
    'Draft': 'neutral'
  };
  var st = t.status || 'Draft';
  var stClass = stMap[st] || 'neutral';
  document.getElementById('vt-status').innerHTML = '<span class="badge badge-' + stClass + '">' + st + '</span>';

  document.getElementById('vt-coordinator').textContent = t.coordinator || '—';
  document.getElementById('vt-metric').textContent = t.metric || '—';
  document.getElementById('vt-remarks').textContent = t.remarks || 'None';

  var revBox = document.getElementById('vt-review-box');
  if (t.review_remark) {
    revBox.style.display = 'block';
    document.getElementById('vt-review-remark').textContent = t.review_remark;
  } else {
    revBox.style.display = 'none';
  }

  document.getElementById('viewDlg').showModal();
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

function saveDeadlineToServer(form) {
  var formData = new FormData(form);
  var displaySpan = form.querySelector('.deadline-display');
  var picker = form.querySelector('.deadline-picker');

  fetch(window.location.href, {
    method: 'POST',
    body: formData,
    headers: { 'X-Requested-With': 'XMLHttpRequest' }
  })
  .then(function(res) { return res.json(); })
  .then(function(data) {
    if (data && data.ok) {
      if (displaySpan) displaySpan.textContent = data.formatted || '—';
      if (picker) {
        picker.style.borderColor = '#10B981';
        setTimeout(function() { picker.style.borderColor = ''; }, 1200);
      }
    } else if (data && data.msg) {
      alert(data.msg);
    }
  })
  .catch(function() {
    form.submit();
  });
}

function handleDeadlineSubmit(e, form) {
  e.preventDefault();
  saveDeadlineToServer(form);
  return false;
}

function confirmBulkApprove(e, form) {
  e.preventDefault();
  var msg = 'Approve all targets?\n\nAll currently eligible Dean Pending targets shown for review will be approved.';
  if (confirm(msg)) {
    form.submit();
  }
  return false;
}
</script>
<?php require __DIR__ . '/inc/footer.php'; ?>
