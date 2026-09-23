<?php
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/models/Target.php';
require_once __DIR__ . '/models/ExecutiveMeeting.php';

// Only Admin may access this page
$user = require_role(['Admin']);

// Active operating academic year — resolved server-side from centralized engine
$activeYear = active_academic_year();
$isLocked   = academic_year_is_locked($activeYear);
$lockInfo   = academic_year_lock_info($activeYear);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    // Server determines the active Academic Year — do not trust client input
    $year         = active_academic_year();
    $saveTarget   = (string) input('save_target', 'all');
    $em1Start     = (string) input('em1_start');
    $em1End       = (string) input('em1_end');
    $em2Start     = (string) input('em2_start');
    $em2End       = (string) input('em2_end');

    [$ok, $msg] = em_schedule_save(
        $year,
        $em1Start, $em1End,
        $em2Start, $em2End,
        (int) $user['id'],
        $saveTarget
    );

    if (!$ok) {
        // Keep what the Admin typed so a validation error does not wipe the form
        $_SESSION['em_schedule_draft'] = [
            'year'      => $year,
            'em1_start' => $em1Start,
            'em1_end'   => $em1End,
            'em2_start' => $em2Start,
            'em2_end'   => $em2End,
        ];
    }

    flash($ok ? 'success' : 'error', $msg);
    redirect('/em-schedule.php');
}

$emStatus   = em_status($activeYear);
$emSchedule = $emStatus['schedule'];
$emSpan     = em_academic_year_span($activeYear);

$emDraft = $_SESSION['em_schedule_draft'] ?? null;
unset($_SESSION['em_schedule_draft']);
if ($emDraft && ($emDraft['year'] ?? null) !== $activeYear) {
    $emDraft = null;
}

$emVal = fn(string $k): string => (string) ($emDraft[$k] ?? $emSchedule[$k] ?? '');
$fmtDate = fn(?string $d): string => $d ? date('d M Y', strtotime($d)) : '—';

$pageTitle  = 'EM Schedule Manager';
$breadcrumb = 'EM Schedule Manager';

require __DIR__ . '/inc/header.php';
?>

<div class="page-head">
  <div>
    <h1>EM Schedule Manager</h1>
    <div class="sub">Configure date ranges for Executive Meeting 1 (EM1) and Executive Meeting 2 (EM2)</div>
  </div>
</div>

<!-- Active Academic Year Banner -->
<div class="card mb-4" id="academicYearBanner">
  <div class="card-body" style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:16px; padding:18px 24px;">
    <div style="display:flex; align-items:center; gap:14px;">
      <div style="width:42px; height:42px; border-radius:var(--r-md); background:var(--navy-50); color:var(--navy-700); display:grid; place-items:center;">
        <?= icon('calendar', 22) ?>
      </div>
      <div>
        <div style="font-size:11.5px; font-weight:700; text-transform:uppercase; letter-spacing:0.06em; color:var(--ink-faint);">
          Active Academic Year
        </div>
        <div style="font-size:20px; font-weight:700; color:var(--ink); letter-spacing:-0.02em;" id="activeYearLabel">
          <?= e($activeYear) ?>
        </div>
      </div>
    </div>
    <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
      <span class="ay-pill <?= $isLocked ? 'locked' : 'active' ?>" style="font-size:12.5px; padding:6px 14px; font-weight:600;" id="yearLockStatus">
        <?= icon($isLocked ? 'lock' : 'shield', 13) ?>
        <?= $isLocked ? 'Locked by Admin' : 'Controlled by Admin' ?>
      </span>
      <span class="ay-pill <?= e($emStatus['em1']['pill']) ?>" style="font-size:12.5px; padding:6px 14px; font-weight:600;" id="em1StatusBadge" title="<?= e($emStatus['em1']['detail']) ?>">
        <?= icon('clock', 13) ?> <?= e($emStatus['em1']['label']) ?>
      </span>
      <span class="ay-pill <?= e($emStatus['em2']['pill']) ?>" style="font-size:12.5px; padding:6px 14px; font-weight:600;" id="em2StatusBadge" title="<?= e($emStatus['em2']['detail']) ?>">
        <?= icon('clock', 13) ?> <?= e($emStatus['em2']['label']) ?>
      </span>
    </div>
  </div>
</div>

<?php if ($emStatus['configured']): ?>
  <div class="em-sched-info mb-4" style="padding:12px 18px; border:1px solid var(--hairline); border-radius:var(--radius); background:var(--surface); font-size:12.5px; color:var(--ink-muted); display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
    <div>
      <?= icon('info', 14) ?>
      <strong>Schedule Status:</strong> <?= e($emStatus['em1']['detail']) ?> &middot; <?= e($emStatus['em2']['detail']) ?>
    </div>
    <?php if (!empty($emSchedule['updated_at'])): ?>
      <div style="font-size:12px; color:var(--ink-faint);">
        Last saved <?= e($fmtDate($emSchedule['updated_at'])) ?>
        <?php if (!empty($emSchedule['updated_by_name'])): ?>by <?= e($emSchedule['updated_by_name']) ?><?php endif; ?>
      </div>
    <?php endif; ?>
  </div>
<?php endif; ?>

<!-- Schedule Form with 2 Meeting Cards -->
<form method="post" action="<?= e(url('em-schedule.php')) ?>" id="emScheduleManagerForm" novalidate>
  <?= csrf_field() ?>

  <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(340px, 1fr)); gap:20px; margin-bottom:24px;">

    <!-- EXECUTIVE MEETING 1 CARD -->
    <div class="card em-card" id="cardEM1" style="display:flex; flex-direction:column; justify-content:space-between;">
      <div>
        <div class="card-head">
          <div>
            <h2 class="card-title">EXECUTIVE MEETING 1</h2>
            <div class="card-sub">Start and end dates for EM1 during <?= e($activeYear) ?></div>
          </div>
          <span class="ay-pill <?= e($emStatus['em1']['pill']) ?>" id="em1Pill">
            <?= e($emStatus['em1']['label']) ?>
          </span>
        </div>
        <div class="card-body">
          <div class="field mb-3">
            <label for="em1_start" style="font-size:13px; font-weight:600; color:var(--ink); margin-bottom:6px; display:block;">
              Start Date <span class="req" style="color:var(--orange-600)">*</span>
            </label>
            <input type="date" class="input" id="em1_start" name="em1_start"
                   value="<?= e($emVal('em1_start')) ?>"
                   placeholder="YYYY-MM-DD"
                   style="width:100%; height:42px;">
          </div>
          <div class="field">
            <label for="em1_end" style="font-size:13px; font-weight:600; color:var(--ink); margin-bottom:6px; display:block;">
              End Date <span class="req" style="color:var(--orange-600)">*</span>
            </label>
            <input type="date" class="input" id="em1_end" name="em1_end"
                   value="<?= e($emVal('em1_end')) ?>"
                   placeholder="YYYY-MM-DD"
                   style="width:100%; height:42px;">
          </div>
        </div>
      </div>

      <div class="card-foot" style="display:flex; justify-content:space-between; align-items:center; padding:14px 24px; border-top:1px solid var(--hairline); background:var(--navy-50); border-radius:0 0 var(--radius) var(--radius);">
        <span style="font-size:12px; color:var(--ink-faint);">Save EM1 individually</span>
        <button type="submit" name="save_target" value="em1" class="btn btn-primary btn-sm" id="btnSaveEM1" style="height:38px; padding:0 16px; font-weight:600;">
          <?= icon('save', 14) ?> Save EM1 Schedule
        </button>
      </div>
    </div>

    <!-- EXECUTIVE MEETING 2 CARD -->
    <div class="card em-card" id="cardEM2" style="display:flex; flex-direction:column; justify-content:space-between;">
      <div>
        <div class="card-head">
          <div>
            <h2 class="card-title">EXECUTIVE MEETING 2</h2>
            <div class="card-sub">Start and end dates for EM2 during <?= e($activeYear) ?></div>
          </div>
          <span class="ay-pill <?= e($emStatus['em2']['pill']) ?>" id="em2Pill">
            <?= e($emStatus['em2']['label']) ?>
          </span>
        </div>
        <div class="card-body">
          <div class="field mb-3">
            <label for="em2_start" style="font-size:13px; font-weight:600; color:var(--ink); margin-bottom:6px; display:block;">
              Start Date <span class="req" style="color:var(--orange-600)">*</span>
            </label>
            <input type="date" class="input" id="em2_start" name="em2_start"
                   value="<?= e($emVal('em2_start')) ?>"
                   placeholder="YYYY-MM-DD"
                   style="width:100%; height:42px;">
          </div>
          <div class="field">
            <label for="em2_end" style="font-size:13px; font-weight:600; color:var(--ink); margin-bottom:6px; display:block;">
              End Date <span class="req" style="color:var(--orange-600)">*</span>
            </label>
            <input type="date" class="input" id="em2_end" name="em2_end"
                   value="<?= e($emVal('em2_end')) ?>"
                   placeholder="YYYY-MM-DD"
                   style="width:100%; height:42px;">
          </div>
        </div>
      </div>

      <div class="card-foot" style="display:flex; justify-content:space-between; align-items:center; padding:14px 24px; border-top:1px solid var(--hairline); background:var(--navy-50); border-radius:0 0 var(--radius) var(--radius);">
        <span style="font-size:12px; color:var(--ink-faint);">Save EM2 individually</span>
        <button type="submit" name="save_target" value="em2" class="btn btn-primary btn-sm" id="btnSaveEM2" style="height:38px; padding:0 16px; font-weight:600;">
          <?= icon('save', 14) ?> Save EM2 Schedule
        </button>
      </div>
    </div>

  </div>

  <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px;">
    <div style="font-size:12px; color:var(--ink-faint); max-width:600px; line-height:1.5;">
      Dates must belong to academic year <strong><?= e($activeYear) ?></strong>
      <?php if ($emSpan): ?>(<?= e($fmtDate($emSpan['from'])) ?> &ndash; <?= e($fmtDate($emSpan['to'])) ?>)<?php endif; ?>.
      You can save EM1 and EM2 separately using the buttons inside each card, or save both together.
    </div>
    <div>
      <button type="submit" name="save_target" value="all" class="btn btn-primary" id="btnSaveSchedule" style="min-width:190px; height:44px; font-weight:600;">
        <?= icon('save', 16) ?> Save Both Schedules
      </button>
    </div>
  </div>
</form>

<?php require __DIR__ . '/inc/footer.php'; ?>
