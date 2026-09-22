<?php
/**
 * EM-SPEC-01 — Admin EM Schedule Manager.
 *
 * Dedicated interface for the Admin to configure Start Date and End Date for:
 *   - Executive Meeting 1 (EM1)
 *   - Executive Meeting 2 (EM2)
 *
 * Stored in the database (app_settings) and strictly linked to the active Academic Year.
 */

require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/models/Target.php';
require_once __DIR__ . '/models/ExecutiveMeeting.php';

// Only Admin may access this page
$user = require_role(['Admin']);

// Active operating academic year — resolved server-side from centralized engine
$activeYear = active_academic_year();
$isLocked   = academic_year_is_locked($activeYear);
$lockInfo   = academic_year_lock_info($activeYear);

// Handle schedule save
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    // Server determines the active Academic Year — do not trust client input
    $year     = active_academic_year();
    $em1Start = (string) input('em1_start');
    $em1End   = (string) input('em1_end');
    $em2Start = (string) input('em2_start');
    $em2End   = (string) input('em2_end');

    [$ok, $msg] = em_schedule_save(
        $year,
        $em1Start, $em1End,
        $em2Start, $em2End,
        (int) $user['id']
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

// Current schedule and status for the active academic year
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
      <?php if ($emStatus['configured']): ?>
        <span class="ay-pill <?= $emStatus['em1_locked'] ? 'locked' : 'active' ?>" style="font-size:12.5px; padding:6px 14px; font-weight:600;" id="emStatusBadge">
          <?= icon('clock', 13) ?>
          <?= e($emStatus['label']) ?>
        </span>
      <?php else: ?>
        <span class="ay-pill term" style="font-size:12.5px; padding:6px 14px; font-weight:600;" id="emStatusBadge">
          <?= icon('clock', 13) ?> Unscheduled
        </span>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php if ($emStatus['configured'] && !empty($emSchedule['updated_at'])): ?>
  <div class="em-sched-info mb-4" style="padding:12px 18px; border:1px solid var(--hairline); border-radius:var(--radius); background:var(--surface); font-size:12.5px; color:var(--ink-muted);">
    <?= icon('info', 14) ?>
    <strong>Current status:</strong> <?= e($emStatus['detail']) ?>
    &middot; Last saved <?= e($fmtDate($emSchedule['updated_at'])) ?>
    <?php if (!empty($emSchedule['updated_by_name'])): ?>by <?= e($emSchedule['updated_by_name']) ?><?php endif; ?>
  </div>
<?php endif; ?>

<!-- Schedule Form with 2 Meeting Cards -->
<form method="post" action="<?= e(url('em-schedule.php')) ?>" id="emScheduleManagerForm" novalidate>
  <?= csrf_field() ?>

  <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(320px, 1fr)); gap:20px; margin-bottom:24px;">

    <!-- EXECUTIVE MEETING 1 CARD -->
    <div class="card em-card" id="cardEM1">
      <div class="card-head">
        <div>
          <h2 class="card-title">EXECUTIVE MEETING 1</h2>
          <div class="card-sub">Start and end dates for EM1 during <?= e($activeYear) ?></div>
        </div>
        <span class="ay-pill <?= (!empty($emVal('em1_start')) && !empty($emVal('em1_end'))) ? 'active' : 'term' ?>" id="em1Pill">
          EM1
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

    <!-- EXECUTIVE MEETING 2 CARD -->
    <div class="card em-card" id="cardEM2">
      <div class="card-head">
        <div>
          <h2 class="card-title">EXECUTIVE MEETING 2</h2>
          <div class="card-sub">Start and end dates for EM2 during <?= e($activeYear) ?></div>
        </div>
        <span class="ay-pill <?= (!empty($emVal('em2_start')) && !empty($emVal('em2_end'))) ? 'active' : 'term' ?>" id="em2Pill">
          EM2
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

  </div>

  <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px;">
    <div style="font-size:12px; color:var(--ink-faint); max-width:600px; line-height:1.5;">
      Dates must belong to academic year <strong><?= e($activeYear) ?></strong>
      <?php if ($emSpan): ?>(<?= e($fmtDate($emSpan['from'])) ?> &ndash; <?= e($fmtDate($emSpan['to'])) ?>)<?php endif; ?>.
      EM1 start date must be before or on end date. EM2 must start after EM1 ends.
    </div>
    <div>
      <button type="submit" class="btn btn-primary" id="btnSaveSchedule" style="min-width:180px; height:44px; font-weight:600;">
        <?= icon('save', 16) ?> Save Schedule
      </button>
    </div>
  </div>
</form>

<?php require __DIR__ . '/inc/footer.php'; ?>
