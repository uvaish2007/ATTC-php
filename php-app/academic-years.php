`<?php
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/models/Target.php';
require_once __DIR__ . '/models/Record.php';

$user = require_role(['Admin']);

// Active operating academic year
$activeYear     = active_academic_year();
$currentCalYear = current_academic_year();
$allYears       = academic_years();          // newest first, up to the current term

// The year being viewed (defaults to the active one; never a future year).
$selectedYear = trim((string) input('year', $activeYear));
if (!is_valid_academic_year($selectedYear) || !in_array($selectedYear, $allYears, true)) {
    $selectedYear = $activeYear;
}

// Handle state changes
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) input('action');
    $year   = (string) input('academic_year', $selectedYear);
    // Come back to the tab the change was made from.
    $tabQs  = input('tab') === 'registry' ? '&tab=registry' : '';

    if ($action === 'activate') {
        [$ok, $msg] = activate_academic_year($year, (int) $user['id']);
    } elseif ($action === 'finish_executive_meeting') {
        [$ok, $msg] = executive_meeting_finish_and_lock(
            $year, (string) input('meeting_number'), (string) input('meeting_date', date('Y-m-d')),
            (string) input('notes', ''), (int) $user['id']
        );
    } elseif ($action === 'unlock_cycle') {
        [$ok, $msg] = executive_meeting_unlock($year, (int) $user['id'], (string) input('reason', ''));
    } elseif ($action === 'toggle_lock') {
        [$ok, $msg] = academic_year_set_lock($year, input('lock_state') === '1', (int) $user['id'], (string) input('note', ''));
    } else {
        [$ok, $msg] = [false, 'Unknown action.'];
    }
    flash($ok ? 'success' : 'error', $msg);
    redirect('/academic-years.php?year=' . urlencode($year) . $tabQs);
}

// ---- Every year at once (registry, picker, stepper) -------------------------
$overview        = academic_years_overview($allYears);
$meetingsReady   = executive_meetings_ready();

// ---- The year being viewed --------------------------------------------------
$selectedLockInfo      = academic_year_lock_info($selectedYear);
$isLocked              = $selectedLockInfo['locked'];
$selectedStats         = $overview[$selectedYear];
$selectedExecMeetings  = executive_meetings_for_year($selectedYear);
$selectedExecCount     = count($selectedExecMeetings);
$selectedLatestMeeting = $selectedExecMeetings[0] ?? null;
$isSelectedActive      = ($selectedYear === $activeYear);

// Suggest the next meeting number: one past the highest numeric one so far.
$nums = array_map('intval', array_filter(array_column($selectedExecMeetings, 'meeting_number'), 'ctype_digit'));
$selectedNextMeetingNum = ($nums ? max($nums) : 0) + 1;

// Where a year sits relative to the operating year. The current calendar term
// is often NEWER than the operating year (the calendar rolls over in June;
// the Admin switches when ready) — that is not a past year.
$yearStart = fn(string $y): int => (int) substr($y, 0, 4);
$yearKind  = fn(string $y): string => $y === $activeYear ? 'active'
                                     : ($yearStart($y) > $yearStart($activeYear) ? 'newer' : 'past');
$kindLabel = ['active' => 'Active', 'newer' => 'Not yet active', 'past' => 'Past'];
$selectedKind = $yearKind($selectedYear);

// Newer / older neighbours for the stepper ($allYears runs newest first).
$idx       = array_search($selectedYear, $allYears, true);
$newerYear = $allYears[$idx - 1] ?? null;
$olderYear = $allYears[$idx + 1] ?? null;

// ---- Records by category (viewed year) --------------------------------------
$selectedCategoryData = [];
foreach (record_types() as $k => $t) {
    $tbl = $t['table'];
    if (!in_array('academic_year', target_record_table_columns($tbl), true)) continue;
    try {
        $stmt = db()->prepare("SELECT COUNT(*) AS total, SUM(status = 'Approved') AS approved FROM `{$tbl}` WHERE academic_year = ?");
        $stmt->execute([$selectedYear]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        if ((int) ($r['total'] ?? 0) > 0) {
            $selectedCategoryData[$k] = ['label' => $t['label'], 'total' => (int) $r['total'], 'approved' => (int) $r['approved']];
        }
    } catch (\PDOException $e) {}
}

// ---- Targets by department (viewed year) ------------------------------------
// Every target status has a column, so each row adds up to its total.
$selectedDeptTargets = [];
try {
    $stmt = db()->prepare(
        "SELECT department, COUNT(*) AS total_targets,
                SUM(status = 'Approved')          AS approved_targets,
                SUM(status = 'Dean Pending')      AS pending_targets,
                SUM(status = 'Changes Requested') AS returned_targets,
                SUM(status = 'Draft')             AS draft_targets
         FROM targets WHERE academic_year = ?
         GROUP BY department ORDER BY department ASC"
    );
    $stmt->execute([$selectedYear]);
    $selectedDeptTargets = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (\PDOException $e) {}
$tgtTotals = ['approved' => 0, 'pending' => 0, 'returned' => 0, 'draft' => 0];
foreach ($selectedDeptTargets as $dt) {
    $tgtTotals['approved'] += (int) $dt['approved_targets'];
    $tgtTotals['pending']  += (int) $dt['pending_targets'];
    $tgtTotals['returned'] += (int) $dt['returned_targets'];
    $tgtTotals['draft']    += (int) $dt['draft_targets'];
}

// ---- The term, June to May --------------------------------------------------
// Matches current_academic_year_start(): the year rolls over on 1 June. (This
// page used to draw a July–June term, so in June it disagreed with the rest
// of the system about which year was current.)
$ayStartYear = $yearStart($selectedYear);
$termStart   = new DateTime(sprintf('%d-06-01', $ayStartYear));
$termEnd     = new DateTime(sprintf('%d-05-31', $ayStartYear + 1));
$today       = new DateTime('today');
if ($today < $termStart) {
    $termState = 'upcoming'; $monthNow = -1;
} elseif ($today > $termEnd) {
    $termState = 'ended';    $monthNow = 12;
} else {
    $termState = 'running';
    $monthNow  = ((int) $today->format('n') - 6 + 12) % 12;    // 0 = June … 11 = May
}
$termPct = $termState === 'running'
    ? (int) round(($today->getTimestamp() - $termStart->getTimestamp()) / max(1, $termEnd->getTimestamp() - $termStart->getTimestamp()) * 100)
    : ($termState === 'ended' ? 100 : 0);
$phases = [
    ['Odd semester',  'Setup & data entry',      0, 3],
    ['Even semester', 'Progress & verification', 4, 8],
    ['IQAC audit',    'Year-end review & lock',  9, 11],
];

$fmtDate = fn(?string $d): string => $d ? date('d M Y', strtotime($d)) : '';

$pageTitle  = 'Academic Year & Lock Cycle';
$breadcrumb = 'Academic Year';
require __DIR__ . '/inc/header.php';
?>

<div class="page-head">
  <div>
    <h1>Academic Year &amp; Lock Cycle</h1>
    <div class="sub">Set the operating year, review any year's figures, and lock a year once its Executive Meeting has finished.</div>
  </div>
  <div class="actions">
    <button type="button" class="btn btn-outline btn-sm" onclick="document.getElementById('switchYearDlg').showModal()">
      <?= icon('refresh', 14) ?> Switch operating year
    </button>
    <?php if ($isLocked): ?>
      <button type="button" class="btn btn-success btn-sm" onclick="openUnlockModal(<?= e(json_encode($selectedYear)) ?>)">
        <?= icon('unlock', 14) ?> Unlock <?= e($selectedYear) ?>
      </button>
    <?php else: ?>
      <button type="button" class="btn btn-danger btn-sm" onclick="openExecMeetingForm()">
        <?= icon('lock', 14) ?> Lock <?= e($selectedYear) ?> after meeting
      </button>
    <?php endif; ?>
  </div>
</div>

<div class="tabs" role="tablist">
  <button type="button" class="tab active" id="ay_nav_overview" role="tab" onclick="switchAyTab('overview')">
    <?= icon('dashboard', 15) ?> Overview <span class="tab-count"><?= e($selectedYear) ?></span>
  </button>
  <button type="button" class="tab" id="ay_nav_registry" role="tab" onclick="switchAyTab('registry')">
    <?= icon('layers', 15) ?> All years <span class="tab-count"><?= count($allYears) ?></span>
  </button>
</div>

<!-- =========================================================================
     OVERVIEW
     ========================================================================= -->
<div id="ay_tab_overview">

  <?php if (!$meetingsReady): ?>
    <div class="alert alert-warning"><?= icon('alert-triangle', 16) ?>
      <span>Executive meetings can't be recorded yet: the <strong>executive_meetings</strong> table is missing and
      could not be created automatically. Run <strong>sql/executive_meetings.sql</strong> on the database.</span></div>
  <?php endif; ?>

  <!-- ---- The year being viewed ---- -->
  <section class="card ay-hero">
    <div class="ay-hero-top">
      <div>
        <div class="ay-eyebrow">Viewing</div>
        <div class="ay-year"><?= e($selectedYear) ?></div>
        <div class="ay-pills">
          <span class="ay-pill <?= $selectedKind ?>"><span class="dot"></span><?= $selectedKind === 'active' ? 'Active operating year' : e($kindLabel[$selectedKind]) ?></span>
          <?php if ($isLocked): ?>
            <span class="ay-pill locked"><?= icon('lock', 12) ?> Locked for all roles</span>
          <?php else: ?>
            <span class="ay-pill open"><?= icon('unlock', 12) ?> Open</span>
          <?php endif; ?>
          <?php if ($selectedYear === $currentCalYear): ?>
            <span class="ay-pill term" title="The academic year the calendar is in today"><?= icon('calendar', 12) ?> Current term</span>
          <?php endif; ?>
        </div>
        <p class="ay-lede">
          <?php if ($selectedKind === 'active'): ?>
            Every role — Faculty, Coordinators, HoDs, Deans and the Principal — is working in this year right now.
            <?php if ($termState === 'ended'): ?>Its term ended on <?= e($termEnd->format('d M Y')) ?>; switch the operating year when you're ready to move everyone to <?= e($currentCalYear) ?>.<?php endif; ?>
          <?php elseif ($selectedKind === 'newer'): ?>
            The calendar has reached this year, but ATTS is still operating in <strong><?= e($activeYear) ?></strong>.
            Make it the operating year when you're ready to move everyone over.
          <?php else: ?>
            A past year. Its records, targets and meetings are kept here permanently for reference.
          <?php endif; ?>
        </p>
      </div>

      <div class="ay-nav">
        <a class="ay-step<?= $olderYear ? '' : ' is-off' ?>" href="<?= $olderYear ? e(url('academic-years.php?year=' . urlencode($olderYear))) : '#' ?>"
           title="Previous year"<?= $olderYear ? '' : ' aria-disabled="true" tabindex="-1"' ?>><?= icon('arrow-left', 14) ?> <?= e($olderYear ?? '') ?></a>
        <label class="fb-field" title="Go to any year">
          <span class="fb-k">Year</span>
          <select onchange="location.href=<?= e(json_encode(url('academic-years.php?year='))) ?> + encodeURIComponent(this.value)"
                  data-default="<?= e($selectedYear) ?>" aria-label="Academic year to view">
            <?php foreach ($allYears as $y): ?>
              <option value="<?= e($y) ?>" <?= $y === $selectedYear ? 'selected' : '' ?>><?= e($y) ?><?= $y === $activeYear ? ' — active' : '' ?><?= $overview[$y]['locked'] ? ' — locked' : '' ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <a class="ay-step next<?= $newerYear ? '' : ' is-off' ?>" href="<?= $newerYear ? e(url('academic-years.php?year=' . urlencode($newerYear))) : '#' ?>"
           title="Next year"<?= $newerYear ? '' : ' aria-disabled="true" tabindex="-1"' ?>><?= e($newerYear ?? '') ?> <?= icon('arrow-right', 14) ?></a>
      </div>
    </div>

    <!-- The term, month by month -->
    <div class="ay-term">
      <div class="ay-term-head">
        <span>Term <strong><?= e($termStart->format('d M Y')) ?> – <?= e($termEnd->format('d M Y')) ?></strong></span>
        <span>
          <?php if ($termState === 'running'): ?>
            Month <strong><?= $monthNow + 1 ?> of 12</strong> · <?= $termPct ?>% through · today <?= e($today->format('D, d M')) ?>
          <?php elseif ($termState === 'ended'): ?>
            Term ended <?= e($termEnd->format('d M Y')) ?>
          <?php else: ?>
            Starts <?= e($termStart->format('d M Y')) ?>
          <?php endif; ?>
        </span>
      </div>
      <div class="ay-months" aria-hidden="true">
        <?php for ($i = 0; $i < 12; $i++):
            $m  = ($i + 5) % 12 + 1;                          // 6..12, 1..5
            $yy = $i < 7 ? $ayStartYear : $ayStartYear + 1;
            $cls = $i < $monthNow ? 'done' : ($i === $monthNow ? 'now' : '');
        ?>
          <div class="ay-month <?= $cls ?>"><?= date('M', mktime(0, 0, 0, $m, 1)) ?><?php if ($i === 0 || $i === 7): ?><small><?= $yy ?></small><?php endif; ?></div>
        <?php endfor; ?>
      </div>
      <div class="ay-phases">
        <?php foreach ($phases as [$pName, $pSub, $from, $to]):
            $pCls = $monthNow > $to ? 'is-done' : ($monthNow >= $from && $monthNow <= $to ? 'is-now' : '');
        ?>
          <div class="ay-phase <?= $pCls ?>"><b><?= e($pName) ?></b><?= e($pSub) ?></div>
        <?php endforeach; ?>
      </div>
    </div>
  </section>

  <!-- ---- The four figures ---- -->
  <?php $appPct = $selectedStats['records'] > 0 ? (int) round($selectedStats['approved_records'] / $selectedStats['records'] * 100) : 0; ?>
  <div class="stat-grid grid-4">
    <div class="stat">
      <div class="stat-top"><span class="stat-label">Records</span><span class="stat-ic brand"><?= icon('layers', 16) ?></span></div>
      <div class="stat-value"><?= (int) $selectedStats['records'] ?></div>
      <div class="stat-desc"><?= $selectedStats['records'] ? (int) $selectedStats['approved_records'] . ' approved · ' . $appPct . '%' : 'None submitted in this year' ?></div>
    </div>
    <div class="stat">
      <div class="stat-top"><span class="stat-label">Targets</span><span class="stat-ic navy"><?= icon('target', 16) ?></span></div>
      <div class="stat-value"><?= (int) $selectedStats['targets'] ?></div>
      <?php $tgtOpen = $tgtTotals['pending'] + $tgtTotals['returned'] + $tgtTotals['draft']; ?>
      <div class="stat-desc"><?= !$selectedStats['targets'] ? 'None set in this year' : ($tgtOpen ? $tgtTotals['approved'] . ' approved · ' . $tgtOpen . ' in progress' : 'All approved') ?></div>
    </div>
    <div class="stat">
      <div class="stat-top"><span class="stat-label">Executive meetings</span><span class="stat-ic navy"><?= icon('award', 16) ?></span></div>
      <div class="stat-value"><?= $selectedExecCount ?></div>
      <div class="stat-desc"><?= $selectedLatestMeeting ? 'Last: #' . e($selectedLatestMeeting['meeting_number']) . ' on ' . e($fmtDate($selectedLatestMeeting['meeting_date'])) : 'None recorded yet' ?></div>
    </div>
    <div class="stat">
      <div class="stat-top"><span class="stat-label">Cycle</span><span class="stat-ic <?= $isLocked ? 'brand' : 'navy' ?>"><?= icon($isLocked ? 'lock' : 'unlock', 16) ?></span></div>
      <div class="stat-value"><?= $isLocked ? 'Locked' : 'Open' ?></div>
      <div class="stat-desc">
        <?php if ($isLocked): ?>Since <?= e($fmtDate($selectedLockInfo['updated_at'])) ?> · <?= e($selectedLockInfo['admin_name']) ?>
        <?php elseif (!empty($selectedLockInfo['updated_at'])): ?>Reopened <?= e($fmtDate($selectedLockInfo['updated_at'])) ?>
        <?php else: ?>Uploads, targets and approvals are on<?php endif; ?>
      </div>
    </div>
  </div>

  <!-- ---- Executive meeting & lock ---- -->
  <section id="execMeetingBox" class="card">
    <div class="card-head">
      <div>
        <h2 class="card-title">Executive meeting &amp; lock</h2>
        <div class="card-sub">Recording a finished Executive Meeting locks <strong><?= e($selectedYear) ?></strong> for every role. You can reopen it later; the meeting stays on record.</div>
      </div>
      <?php if ($isLocked): ?>
        <span class="ay-pill locked"><?= icon('lock', 12) ?> Locked</span>
      <?php else: ?>
        <span class="ay-pill open"><?= icon('unlock', 12) ?> Open</span>
      <?php endif; ?>
    </div>
    <div class="card-body">

      <?php if ($isLocked): ?>
        <div class="ay-lockstrip">
          <div class="ic"><?= icon('lock', 17) ?></div>
          <div>
            <div class="t"><?= e($selectedYear) ?> is locked<?php if ($selectedLatestMeeting): ?> · after Meeting #<?= e($selectedLatestMeeting['meeting_number']) ?> on <?= e($fmtDate($selectedLatestMeeting['meeting_date'])) ?><?php endif; ?></div>
            <div class="s">Uploads and target edits are read-only for Faculty, Coordinators, HoDs and Deans.<?php if ($selectedLockInfo['note'] !== ''): ?> Note: <?= e($selectedLockInfo['note']) ?><?php endif; ?></div>
          </div>
          <button type="button" class="btn btn-success btn-sm" onclick="openUnlockModal(<?= e(json_encode($selectedYear)) ?>)"><?= icon('unlock', 14) ?> Unlock year</button>
        </div>
      <?php endif; ?>

      <?php // The same form either way; while the year is already locked it is
            // folded away, because recording another meeting is then the exception. ?>
      <?php if ($isLocked): ?><details class="ay-more" id="meetFormWrap"><summary><?= icon('chevron', 14) ?> Record another meeting for <?= e($selectedYear) ?></summary><?php endif; ?>
      <form method="post" class="ay-meet-form"
            onsubmit="return confirm(<?= e(json_encode($isLocked ? 'Record this Executive Meeting for ' . $selectedYear . '?' : 'Record this Executive Meeting and lock ' . $selectedYear . ' for all roles?')) ?>);">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="finish_executive_meeting">
        <input type="hidden" name="academic_year" value="<?= e($selectedYear) ?>">
        <div class="field">
          <label for="meeting_number">Meeting number <span class="req">*</span></label>
          <input type="text" class="input" id="meeting_number" name="meeting_number" value="<?= $selectedNextMeetingNum ?>"
                 placeholder="e.g. 3" maxlength="50" required <?= $meetingsReady ? '' : 'disabled' ?>>
        </div>
        <div class="field">
          <label for="meeting_date">Date finished <span class="req">*</span></label>
          <input type="date" class="input" id="meeting_date" name="meeting_date" value="<?= e($today->format('Y-m-d')) ?>"
                 max="<?= e($today->format('Y-m-d')) ?>" required <?= $meetingsReady ? '' : 'disabled' ?>>
        </div>
        <div class="field ay-f-notes">
          <label for="meeting_notes">Minutes / remarks <span class="faint">(optional)</span></label>
          <input type="text" class="input" id="meeting_notes" name="notes" maxlength="1000"
                 placeholder="e.g. Committee reviewed semester targets; audits complete." <?= $meetingsReady ? '' : 'disabled' ?>>
        </div>
        <div class="ay-f-go">
          <button type="submit" class="btn <?= $isLocked ? 'btn-secondary' : 'btn-danger' ?>" <?= $meetingsReady ? '' : 'disabled' ?>>
            <?= icon($isLocked ? 'check' : 'lock', 15) ?> <?= $isLocked ? 'Record meeting' : 'Finish &amp; lock ' . e($selectedYear) ?>
          </button>
        </div>
      </form>
      <?php if ($isLocked): ?></details><?php else: ?>
        <p class="ay-form-note">Next number suggested from the meetings already recorded. The date can't be later than today.</p>
      <?php endif; ?>

      <div class="ay-section-title">Meetings for <?= e($selectedYear) ?> <span><?= $selectedExecCount ?> recorded · audit trail</span></div>
      <?php if ($selectedExecCount === 0): ?>
        <div class="ay-empty-row">No Executive Meetings recorded for <?= e($selectedYear) ?> yet.</div>
      <?php else: ?>
        <div class="ay-table-box table-wrap">
          <table class="data ay-t">
            <thead><tr><th>Meeting</th><th>Date finished</th><th>Recorded by</th><th>Minutes / remarks</th><th class="num">State</th></tr></thead>
            <tbody>
              <?php foreach ($selectedExecMeetings as $i => $m): ?>
                <tr>
                  <td><span class="ay-meet-no"><i><?= $selectedExecCount - $i ?></i> Meeting #<?= e($m['meeting_number']) ?></span></td>
                  <td class="nowrap"><?= e($fmtDate($m['meeting_date'])) ?></td>
                  <td class="nowrap"><?= e($m['admin_name'] ?? 'Administrator') ?></td>
                  <td><?= $m['notes'] ? e($m['notes']) : '<span class="faint">—</span>' ?></td>
                  <td class="num"><span class="ay-pill locked"><?= icon('lock', 11) ?> Finished &amp; locked</span></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </section>

  <!-- ---- The year's data ---- -->
  <section class="card mt-5">
    <div class="card-head">
      <div>
        <h2 class="card-title">Data for <?= e($selectedYear) ?></h2>
        <div class="card-sub">Faculty records by category and targets by department, as entered in this year.</div>
      </div>
      <?php if ($isSelectedActive): ?>
        <div class="flex gap-2">
          <a href="<?= e(url('reports.php')) ?>" class="btn btn-outline btn-sm"><?= icon('reports', 14) ?> Reports</a>
          <a href="<?= e(url('targets.php')) ?>" class="btn btn-outline btn-sm"><?= icon('target', 14) ?> Targets</a>
        </div>
      <?php else: ?>
        <?php // Reports and Targets only ever show the operating year, so a link
              // from a different year would open the wrong year's data. ?>
        <span class="ay-note" title="Make <?= e($selectedYear) ?> the operating year to report on it"><?= icon('info', 13) ?> Reports and Targets show the operating year (<?= e($activeYear) ?>)</span>
      <?php endif; ?>
    </div>
    <div class="card-body">
      <div class="ay-breakdown">

        <div>
          <div class="ay-sub">Records by category <span><?= (int) $selectedStats['records'] ?> total · <?= (int) $selectedStats['approved_records'] ?> approved</span></div>
          <?php if (empty($selectedCategoryData)): ?>
            <div class="ay-empty-row">No records submitted in <?= e($selectedYear) ?>.</div>
          <?php else: ?>
            <div class="ay-table-box table-wrap">
              <table class="data ay-t">
                <thead><tr><th>Category</th><th class="num">Submitted</th><th class="num">Approved</th><th class="num">Approval</th></tr></thead>
                <tbody>
                  <?php foreach ($selectedCategoryData as $cat):
                      $pct = (int) round($cat['approved'] / $cat['total'] * 100);
                      $col = $pct >= 80 ? 'var(--ok)' : ($pct >= 50 ? 'var(--orange-500)' : 'var(--bad)');
                  ?>
                    <tr>
                      <td class="fw-500"><?= e($cat['label']) ?></td>
                      <td class="num"><?= $cat['total'] ?></td>
                      <td class="num"><?= $cat['approved'] ?></td>
                      <td class="num"><span class="ay-rate"><span class="track"><span style="width:<?= $pct ?>%;background:<?= $col ?>"></span></span><b style="color:<?= $col ?>"><?= $pct ?>%</b></span></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>

        <div>
          <div class="ay-sub">Targets by department <span><?= (int) $selectedStats['targets'] ?> total</span></div>
          <?php if (empty($selectedDeptTargets)): ?>
            <div class="ay-empty-row">No targets set in <?= e($selectedYear) ?>.</div>
          <?php else: ?>
            <div class="ay-table-box table-wrap">
              <table class="data ay-t">
                <thead><tr><th>Department</th><th class="num">Total</th><th class="num">Approved</th><th class="num">Pending</th><th class="num" title="Changes requested">Returned</th><th class="num">Draft</th></tr></thead>
                <tbody>
                  <?php foreach ($selectedDeptTargets as $dt): ?>
                    <tr>
                      <td class="fw-600"><?= e($dt['department']) ?></td>
                      <td class="num fw-600"><?= (int) $dt['total_targets'] ?></td>
                      <?php foreach (['approved_targets', 'pending_targets', 'returned_targets', 'draft_targets'] as $col): $v = (int) $dt[$col]; ?>
                        <td class="num<?= $v ? '' : ' ay-zero' ?>"><?= $v ?></td>
                      <?php endforeach; ?>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>

      </div>
    </div>
  </section>

</div>

<!-- =========================================================================
     ALL YEARS (registry)
     ========================================================================= -->
<div id="ay_tab_registry" hidden>
  <section class="card">
    <div class="card-head">
      <div>
        <h2 class="card-title">All academic years <span class="tab-count"><span id="ay_visible_count"><?= count($allYears) ?></span></span></h2>
        <div class="card-sub">From <?= e(end($allYears)) ?> to <?= e($allYears[0]) ?>. Open a year to see its figures, lock it, or make it the operating year.</div>
      </div>
      <label class="fb-field fb-search" style="max-width:320px">
        <?= icon('search', 15) ?>
        <input type="search" id="ay_registry_search" placeholder="Search year or status — e.g. 2024, locked"
               oninput="filterRegistryTable(this.value)" aria-label="Search the academic years">
      </label>
    </div>

    <div class="table-wrap ay-reg-wrap">
      <table class="data ay-reg">
        <thead>
          <tr>
            <th>Year</th><th>Status</th><th>Cycle</th>
            <th class="num">Meetings</th><th class="num">Records</th><th class="num">Targets</th>
            <th class="num">Actions</th>
          </tr>
        </thead>
        <tbody id="ay_registry_tbody">
          <?php foreach ($allYears as $y):
              $o      = $overview[$y];
              $kind   = $yearKind($y);
              $isView = ($y === $selectedYear);
              $quiet  = !$o['records'] && !$o['targets'] && !$o['meetings'];
              $search = strtolower($y . ' ' . $kindLabel[$kind] . ' ' . ($o['locked'] ? 'locked' : 'open') . ($y === $currentCalYear ? ' current term' : ''));
          ?>
            <tr class="<?= $isView ? 'is-viewing' : '' ?><?= $quiet ? ' is-quiet' : '' ?>" data-search="<?= e($search) ?>">
              <td class="nowrap">
                <span class="ay-yr"><?= e($y) ?></span>
                <?php if ($isView): ?><span class="ay-tag viewing">Viewing</span><?php endif; ?>
                <?php if ($y === $currentCalYear): ?><span class="ay-tag" title="The academic year the calendar is in today">Current term</span><?php endif; ?>
              </td>
              <td><span class="ay-pill <?= $kind ?>"><span class="dot"></span><?= e($kindLabel[$kind]) ?></span></td>
              <td>
                <?php if ($o['locked']): ?>
                  <span class="ay-pill locked"><?= icon('lock', 11) ?> Locked</span>
                <?php else: ?>
                  <span class="ay-pill open"><?= icon('unlock', 11) ?> Open</span>
                <?php endif; ?>
              </td>
              <td class="num"><?= $o['meetings'] ?></td>
              <td class="num"><?= $o['records'] ?><?php if ($o['records']): ?> <span class="faint">(<?= $o['approved_records'] ?> approved)</span><?php endif; ?></td>
              <td class="num"><?= $o['targets'] ?></td>
              <td class="num">
                <div class="ay-acts">
                  <?php if ($isView): ?>
                    <a class="btn btn-primary btn-sm" href="<?= e(url('academic-years.php?year=' . urlencode($y))) ?>" onclick="switchAyTab('overview'); return false;">Viewing</a>
                  <?php else: ?>
                    <a class="btn btn-outline btn-sm" href="<?= e(url('academic-years.php?year=' . urlencode($y))) ?>" title="See <?= e($y) ?>'s figures and meetings">Open</a>
                  <?php endif; ?>

                  <?php if ($o['locked']): ?>
                    <button type="button" class="btn btn-sm btn-unlock" onclick="openUnlockModal(<?= e(json_encode($y)) ?>, 'registry')"><?= icon('unlock', 13) ?> Unlock</button>
                  <?php else: ?>
                    <button type="button" class="btn btn-sm btn-lock" onclick="openDirectLockModal(<?= e(json_encode($y)) ?>)"><?= icon('lock', 13) ?> Lock</button>
                  <?php endif; ?>

                  <?php if ($kind === 'active'): ?>
                    <span class="ay-slot"><?= icon('check', 13) ?>&nbsp;Operating</span>
                  <?php else: ?>
                    <form method="post" onsubmit="return confirm(<?= e(json_encode('Make ' . $y . ' the operating year for every user?')) ?>);">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="activate">
                      <input type="hidden" name="academic_year" value="<?= e($y) ?>">
                      <input type="hidden" name="tab" value="registry">
                      <button type="submit" class="btn btn-outline btn-sm" title="Make <?= e($y) ?> the operating year for every user">Make active</button>
                    </form>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <div id="ay_registry_no_match" class="empty" hidden><p>No year matches that search.</p></div>
    </div>
  </section>
</div>

<!-- =========================================================================
     DIALOGS
     ========================================================================= -->
<dialog id="switchYearDlg" class="modal" style="max-width:30rem">
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="activate">
    <div class="modal-head"><div><h3>Switch operating year</h3><div class="msub">Every user moves to the year you choose, straight away.</div></div></div>
    <div class="modal-body">
      <div class="field">
        <label for="modal_ay_select">Academic year</label>
        <select class="select" id="modal_ay_select" name="academic_year">
          <?php foreach ($allYears as $ay): ?>
            <option value="<?= e($ay) ?>" <?= $ay === $activeYear ? 'selected' : '' ?>><?= e($ay) ?><?= $ay === $activeYear ? ' — operating now' : '' ?><?= $ay === $currentCalYear ? ' — current term' : '' ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <p class="modal-text" style="font-size:12.5px">Nothing is deleted: every year's records stay where they are. Switching only changes which year everyone works in.</p>
    </div>
    <div class="modal-foot">
      <button type="button" class="btn btn-outline btn-sm" onclick="this.closest('dialog').close()">Cancel</button>
      <button type="submit" class="btn btn-primary btn-sm">Switch for everyone</button>
    </div>
  </form>
</dialog>

<dialog id="unlockCycleDlg" class="modal" style="max-width:30rem">
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="unlock_cycle">
    <input type="hidden" name="academic_year" id="unlock-modal-year" value="<?= e($selectedYear) ?>">
    <input type="hidden" name="tab" id="unlock-modal-tab" value="">
    <div class="modal-head"><div><h3>Unlock <span id="unlock-modal-year-display"><?= e($selectedYear) ?></span>?</h3>
      <div class="msub">Uploads and target changes reopen for every role until the year is locked again.</div></div></div>
    <div class="modal-body">
      <div class="field" style="margin:0">
        <label for="unlock_reason">Reason <span class="faint">(optional)</span></label>
        <input type="text" class="input" id="unlock_reason" name="reason" maxlength="255" placeholder="e.g. Extra submission window before the next meeting">
      </div>
    </div>
    <div class="modal-foot">
      <button type="button" class="btn btn-outline btn-sm" onclick="this.closest('dialog').close()">Cancel</button>
      <button type="submit" class="btn btn-success btn-sm"><?= icon('unlock', 14) ?> Unlock</button>
    </div>
  </form>
</dialog>

<dialog id="directLockDlg" class="modal" style="max-width:30rem">
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="toggle_lock">
    <input type="hidden" name="academic_year" id="direct-lock-year" value="">
    <input type="hidden" name="lock_state" value="1">
    <input type="hidden" name="tab" value="registry">
    <div class="modal-head"><div><h3>Lock <span id="direct-lock-year-display"></span>?</h3>
      <div class="msub">Uploads and target edits freeze for every role. This does not record an Executive Meeting.</div></div></div>
    <div class="modal-body">
      <div class="field" style="margin:0">
        <label for="direct_lock_note">Note <span class="faint">(optional)</span></label>
        <input type="text" class="input" id="direct_lock_note" name="note" maxlength="255" placeholder="e.g. Manual lock by the Administrator">
      </div>
    </div>
    <div class="modal-foot">
      <button type="button" class="btn btn-outline btn-sm" onclick="this.closest('dialog').close()">Cancel</button>
      <button type="submit" class="btn btn-danger btn-sm"><?= icon('lock', 14) ?> Lock for all roles</button>
    </div>
  </form>
</dialog>

<script>
/* Tabs. The choice is kept in the address (?tab=registry), so a reload or a
   change made from the registry comes back to the registry. */
function switchAyTab(tab) {
  var reg = tab === 'registry';
  document.getElementById('ay_tab_overview').hidden = reg;
  document.getElementById('ay_tab_registry').hidden = !reg;
  document.getElementById('ay_nav_overview').classList.toggle('active', !reg);
  document.getElementById('ay_nav_registry').classList.toggle('active', reg);
  document.getElementById('ay_nav_overview').setAttribute('aria-selected', String(!reg));
  document.getElementById('ay_nav_registry').setAttribute('aria-selected', String(reg));
  try {
    var u = new URL(window.location.href);
    if (reg) u.searchParams.set('tab', 'registry'); else u.searchParams.delete('tab');
    history.replaceState(null, '', u);
  } catch (e) {}
}

/* The header's Lock button: bring the meeting form into view, ready to type. */
function openExecMeetingForm() {
  switchAyTab('overview');
  var wrap = document.getElementById('meetFormWrap');
  if (wrap) wrap.open = true;
  var box = document.getElementById('execMeetingBox');
  if (box) box.scrollIntoView({ behavior: 'smooth', block: 'start' });
  var f = document.getElementById('meeting_number');
  if (f) setTimeout(function () { f.focus(); f.select(); }, 350);
}

function openUnlockModal(year, tab) {
  document.getElementById('unlock-modal-year').value = year;
  document.getElementById('unlock-modal-year-display').textContent = year;
  document.getElementById('unlock-modal-tab').value = tab || '';
  document.getElementById('unlockCycleDlg').showModal();
}

function openDirectLockModal(year) {
  document.getElementById('direct-lock-year').value = year;
  document.getElementById('direct-lock-year-display').textContent = year;
  document.getElementById('directLockDlg').showModal();
}

function filterRegistryTable(query) {
  var q = (query || '').toLowerCase().trim(), visible = 0;
  document.querySelectorAll('#ay_registry_tbody tr').forEach(function (r) {
    var hit = !q || (r.getAttribute('data-search') || '').indexOf(q) !== -1;
    r.hidden = !hit;
    if (hit) visible++;
  });
  document.getElementById('ay_registry_no_match').hidden = visible !== 0;
  document.getElementById('ay_visible_count').textContent = visible;
}

if (new URLSearchParams(window.location.search).get('tab') === 'registry' || window.location.hash === '#registry') {
  switchAyTab('registry');
}
</script>

<?php require __DIR__ . '/inc/footer.php'; ?>
