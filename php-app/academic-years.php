`<?php
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/models/Target.php';
require_once __DIR__ . '/models/Record.php';

$user = require_role(['Admin']);

// Active operating academic year
$activeYear     = active_academic_year();
$currentCalYear = current_academic_year();
$allYears       = academic_years();

// Selected academic year to view & manage (defaults to activeYear, only current year and past years)
$selectedYear = trim((string) input('year', $activeYear));
if (!is_valid_academic_year($selectedYear) || !in_array($selectedYear, $allYears, true)) {
    $selectedYear = $activeYear;
}

// Handle state changes
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) input('action');

    if ($action === 'activate') {
        $year = (string) input('academic_year');
        [$ok, $msg] = activate_academic_year($year, (int) $user['id']);
        flash($ok ? 'success' : 'error', $msg);
        redirect('/academic-years.php?year=' . urlencode($year));

    } elseif ($action === 'finish_executive_meeting') {
        $year          = (string) input('academic_year', $selectedYear);
        $meetingNumber = (string) input('meeting_number');
        $meetingDate   = (string) input('meeting_date', date('Y-m-d'));
        $notes         = (string) input('notes', '');
        [$ok, $msg] = executive_meeting_finish_and_lock($year, $meetingNumber, $meetingDate, $notes, (int) $user['id']);
        flash($ok ? 'success' : 'error', $msg);
        redirect('/academic-years.php?year=' . urlencode($year));

    } elseif ($action === 'unlock_cycle') {
        $year   = (string) input('academic_year', $selectedYear);
        $reason = (string) input('reason', '');
        [$ok, $msg] = executive_meeting_unlock($year, (int) $user['id'], $reason);
        flash($ok ? 'success' : 'error', $msg);
        redirect('/academic-years.php?year=' . urlencode($year));

    } elseif ($action === 'toggle_lock') {
        $year      = (string) input('academic_year', $selectedYear);
        $lockState = (input('lock_state') === '1');
        $note      = (string) input('note', '');
        [$ok, $msg] = academic_year_set_lock($year, $lockState, (int) $user['id'], $note);
        flash($ok ? 'success' : 'error', $msg);
        redirect('/academic-years.php?year=' . urlencode($year));
    }
}

// Metadata for the selected academic year
$selectedLockInfo   = academic_year_lock_info($selectedYear);
$selectedStats      = academic_year_summary_stats($selectedYear);
$selectedExecMeetings = executive_meetings_for_year($selectedYear);
$selectedExecCount  = count($selectedExecMeetings);
$selectedLatestMeeting = $selectedExecMeetings[0] ?? null;
$selectedNextMeetingNum = $selectedExecCount + 1;
$isSelectedActive   = ($selectedYear === $activeYear);
$isSelectedCurrentCal = ($selectedYear === $currentCalYear);

// Detailed Category Breakdown for the selected academic year
$selectedCategoryData = [];
foreach (record_types() as $k => $t) {
    $tbl = $t['table'];
    $cols = target_record_table_columns($tbl);
    if (!in_array('academic_year', $cols, true)) continue;
    try {
        $stmt = db()->prepare("SELECT COUNT(*) as total, SUM(CASE WHEN status='Approved' THEN 1 ELSE 0 END) as approved FROM `{$tbl}` WHERE academic_year = ?");
        $stmt->execute([$selectedYear]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        $total = (int) ($r['total'] ?? 0);
        $approved = (int) ($r['approved'] ?? 0);
        if ($total > 0 || $approved > 0) {
            $selectedCategoryData[$k] = [
                'label'    => $t['label'],
                'total'    => $total,
                'approved' => $approved,
            ];
        }
    } catch (\PDOException $e) {}
}

// Department Targets Breakdown for the selected academic year
$selectedDeptTargets = [];
try {
    $stmt = db()->prepare(
        "SELECT department, COUNT(*) as total_targets,
                SUM(CASE WHEN status='Approved' THEN 1 ELSE 0 END) as approved_targets,
                SUM(CASE WHEN status='Dean Pending' THEN 1 ELSE 0 END) as pending_targets,
                SUM(CASE WHEN status='Draft' THEN 1 ELSE 0 END) as draft_targets
         FROM targets
         WHERE academic_year = ?
         GROUP BY department
         ORDER BY department ASC"
    );
    $stmt->execute([$selectedYear]);
    $selectedDeptTargets = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (\PDOException $e) {}

// Calendar calculation for the current real-time month
$now = new DateTime('now', new DateTimeZone('Asia/Kolkata'));
$currentMonthNum  = (int) $now->format('n');
$currentYearNum   = (int) $now->format('Y');
$currentDayNum    = (int) $now->format('j');
$monthName        = $now->format('F');
$firstDayOfMonth  = (new DateTime("{$currentYearNum}-{$currentMonthNum}-01"))->format('w');
$daysInMonth      = (int) $now->format('t');

// Calculate Selected Academic Year Cycle Progress (July 1 to June 30)
$ayStartYear = (int) substr($selectedYear, 0, 4);
$ayStartDate = new DateTime("{$ayStartYear}-07-01");
$ayEndDate   = new DateTime(($ayStartYear + 1) . "-06-30");

$totalAyDays = max(1, $ayEndDate->diff($ayStartDate)->days);
if ($now < $ayStartDate) {
    $cyclePct = 0;
    $cyclePhase = 'Upcoming Academic Year';
} elseif ($now > $ayEndDate) {
    $cyclePct = 100;
    $cyclePhase = 'Past Academic Cycle (Concluded)';
} else {
    $passedAyDays = max(0, min($totalAyDays, $now->diff($ayStartDate)->days));
    $cyclePct = round(($passedAyDays / $totalAyDays) * 100);
    if ($cyclePct < 35) {
        $cyclePhase = 'Phase 1: Odd Semester Setup & Ingestion';
    } elseif ($cyclePct < 75) {
        $cyclePhase = 'Phase 2: Even Semester Progress & Verification';
    } else {
        $cyclePhase = 'Phase 3: IQAC Audit & Year-End Lock Cycle';
    }
}

$pageTitle  = 'Academic Year & Lock Cycle';
$breadcrumb = 'Academic Year';
require __DIR__ . '/inc/header.php';
?>

<style>
/* ---- Academic Year & Lock Cycle UI Enhancements ---- */
.ay-tabs-nav {
  display: flex;
  align-items: center;
  gap: 8px;
  border-bottom: 2px solid var(--navy-100, #e2e8f0);
  margin-bottom: 24px;
  overflow-x: auto;
  padding-bottom: 2px;
}
.ay-tab-btn {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 10px 18px;
  font-size: 13px;
  font-weight: 700;
  color: var(--navy-600, #475569);
  background: transparent;
  border: none;
  border-bottom: 3px solid transparent;
  border-radius: 8px 8px 0 0;
  cursor: pointer;
  transition: all 0.18s ease;
  white-space: nowrap;
  margin-bottom: -2px;
}
.ay-tab-btn:hover {
  color: var(--navy-900, #0f172a);
  background: var(--navy-50, #f8fafc);
}
.ay-tab-btn.active {
  color: var(--orange-600, #ea580c);
  border-bottom-color: var(--orange-500, #ff4f01);
  background: rgba(255, 79, 1, 0.05);
}
.ay-stat-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(310px, 1fr));
  gap: 18px;
  margin-bottom: 24px;
}
.ay-stat-card {
  background: var(--surface, #ffffff);
  border: 1px solid var(--navy-100, #e2e8f0);
  border-radius: 12px;
  padding: 20px;
  box-shadow: 0 2px 8px rgba(15, 23, 42, 0.04);
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  min-height: 215px;
  transition: transform 0.15s ease, box-shadow 0.15s ease;
}
.ay-stat-card:hover {
  box-shadow: 0 6px 16px rgba(15, 23, 42, 0.07);
}
.ay-card-scope { border-top: 3px solid var(--orange-500, #ff4f01); }
.ay-card-lock  { border-top: 3px solid <?= $selectedLockInfo['locked'] ? '#DC2626' : '#10B981' ?>; }
.ay-card-cal   { border-top: 3px solid var(--navy-700, #334155); }

.ay-form-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
  gap: 14px;
  margin-bottom: 14px;
}
.ay-form-bottom {
  display: flex;
  align-items: flex-end;
  gap: 12px;
  flex-wrap: wrap;
}
.ay-form-bottom .field {
  flex: 1;
  min-width: 260px;
  margin: 0;
}
.ay-registry-toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 16px 20px;
  background: #fafbfc;
  border-bottom: 1px solid var(--navy-100, #e2e8f0);
  flex-wrap: wrap;
  gap: 12px;
}
.ay-search-box {
  position: relative;
  min-width: 260px;
  max-width: 360px;
  flex: 1;
}
.ay-search-box svg {
  position: absolute;
  left: 12px;
  top: 50%;
  transform: translateY(-50%);
  color: var(--ink-faint, #94a3b8);
  pointer-events: none;
}
.ay-search-box input {
  padding-left: 36px;
  height: 38px;
  font-size: 13px;
  width: 100%;
}
.ay-quick-chip {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 5px 12px;
  border-radius: 8px;
  font-size: 12px;
  font-weight: 700;
  font-family: monospace;
  text-decoration: none;
  transition: all 0.15s ease;
}
.ay-quick-chip:hover {
  transform: translateY(-1px);
}
</style>

<!-- PAGE HEADER -->
<div class="page-head">
  <div>
    <div style="display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:600;color:var(--navy-600);background:var(--navy-50,#f1f5f9);padding:3px 10px;border-radius:20px;margin-bottom:6px;border:1px solid var(--navy-100,#e2e8f0)">
      <?= icon('calendar', 14) ?>
      <span>Institutional Workspace &middot; Executive Meeting &amp; Multi-Year Cycle Lock</span>
    </div>
    <h1 style="font-size:24px;font-weight:700;color:var(--navy-900);letter-spacing:-.02em;margin:0">Academic Year &amp; Lock Cycle</h1>
    <div class="sub" style="font-size:13px;color:var(--navy-500);margin-top:4px">
      Manage active operating year, inspect past years' records &amp; targets, record finished executive meetings, and lock cycles for all roles.
    </div>
  </div>
  <div class="actions" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
    <!-- BUTTON: ON-DEMAND REGISTRY VIEW -->
    <button type="button" class="btn btn-outline btn-sm" id="btnToggleRegistry" onclick="switchAyTab('registry')" style="font-weight:700">
      <?= icon('file-stack', 14) ?> View Academic Years Registry (<?= count($allYears) ?>)
    </button>
    <button type="button" class="btn btn-outline btn-sm" onclick="document.getElementById('switchYearDlg').showModal()">
      <?= icon('refresh', 14) ?> Switch Operating Year
    </button>
    <?php if ($selectedLockInfo['locked']): ?>
      <button type="button" class="btn btn-primary btn-sm" style="background:#059669;border-color:#059669" onclick="openUnlockModal('<?= e($selectedYear) ?>')">
        <?= icon('unlock', 14) ?> Unlock Year <?= e($selectedYear) ?>
      </button>
    <?php else: ?>
      <button type="button" class="btn btn-primary btn-sm" style="background:#DC2626;border-color:#DC2626" onclick="openExecMeetingForm()">
        <?= icon('lock', 14) ?> Lock Year <?= e($selectedYear) ?>
      </button>
    <?php endif; ?>
  </div>
</div>

<!-- =========================================================================
     STRUCTURED NAVIGATION TABS
     ========================================================================= -->
<div class="ay-tabs-nav">
  <button type="button" class="ay-tab-btn active" id="ay_nav_overview" onclick="switchAyTab('overview')">
    <?= icon('dashboard', 16) ?>
    <span>Year Overview &amp; Cycle Lock &middot; <strong style="font-family:monospace"><?= e($selectedYear) ?></strong></span>
    <?php if ($selectedLockInfo['locked']): ?>
      <span style="background:#DC2626;color:#fff;font-size:10px;font-weight:700;padding:2px 7px;border-radius:8px">Locked</span>
    <?php else: ?>
      <span style="background:#059669;color:#fff;font-size:10px;font-weight:700;padding:2px 7px;border-radius:8px">Open</span>
    <?php endif; ?>
  </button>
  <button type="button" class="ay-tab-btn" id="ay_nav_registry" onclick="switchAyTab('registry')">
    <?= icon('building', 16) ?>
    <span>Academic Years Registry</span>
    <span class="badge badge-neutral" style="font-size:10px;padding:2px 7px"><?= count($allYears) ?> Years</span>
  </button>
</div>

<!-- =========================================================================
     TAB 1: YEAR OVERVIEW & CYCLE LOCK WORKSPACE
     ========================================================================= -->
<div id="ay_tab_overview">

  <!-- ACADEMIC YEAR SELECTOR & SCOPE BANNER -->
  <div class="card" style="background:linear-gradient(135deg, #1e293b 0%, var(--navy-900,#131D3B) 100%);color:#ffffff;border:none;border-radius:12px;padding:16px 20px;margin-bottom:20px;box-shadow:0 4px 14px rgba(15,23,42,0.08)">
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:14px">
      <div style="display:flex;align-items:center;gap:12px">
        <div style="width:40px;height:40px;border-radius:10px;background:rgba(255,255,255,0.12);display:flex;align-items:center;justify-content:center;color:#ffffff;flex-shrink:0">
          <?= icon('calendar', 20) ?>
        </div>
        <div>
          <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
            <span style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:rgba(255,255,255,0.7)">
              Active Scope:
            </span>
            <span style="font-family:monospace;font-size:18px;font-weight:800;color:#ffffff">
              <?= e($selectedYear) ?>
            </span>
            <?php if ($isSelectedActive): ?>
              <span style="background:#10B981;color:#fff;font-size:10px;font-weight:700;padding:2px 8px;border-radius:10px">
                ★ Active System Year
              </span>
            <?php else: ?>
              <span style="background:rgba(255,255,255,0.18);color:#fff;font-size:10px;font-weight:700;padding:2px 8px;border-radius:10px">
                Past Academic Year
              </span>
            <?php endif; ?>
            <?php if ($selectedLockInfo['locked']): ?>
              <span style="background:#DC2626;color:#fff;font-size:10px;font-weight:700;padding:2px 8px;border-radius:10px;display:inline-flex;align-items:center;gap:3px">
                <?= icon('lock', 10) ?> Locked for All Roles
              </span>
            <?php else: ?>
              <span style="background:#059669;color:#fff;font-size:10px;font-weight:700;padding:2px 8px;border-radius:10px;display:inline-flex;align-items:center;gap:3px">
                <?= icon('unlock', 10) ?> Cycle Open
              </span>
            <?php endif; ?>
          </div>
          <div style="font-size:12px;color:rgba(255,255,255,0.7);margin-top:2px">
            Inspect historical records, targets, executive meetings, and cycle lock states for this academic year.
          </div>
        </div>
      </div>

      <!-- Quick Chips & Selector Dropdown -->
      <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
        <div style="display:flex;align-items:center;gap:6px">
          <?php foreach (array_slice($allYears, 0, 4) as $chipY):
              $isChipSelected = ($chipY === $selectedYear);
              $chipLocked     = academic_year_is_locked($chipY);
          ?>
            <a href="<?= e(url('academic-years.php?year=' . urlencode($chipY))) ?>" class="ay-quick-chip"
               style="<?= $isChipSelected ? 'background:#ff4f01;color:#ffffff;box-shadow:0 2px 6px rgba(255,79,1,0.4);' : 'background:rgba(255,255,255,0.12);color:rgba(255,255,255,0.9);' ?>">
              <?= e($chipY) ?>
              <?php if ($chipLocked): ?>
                <span title="Locked" style="color:<?= $isChipSelected ? '#fff' : '#f87171' ?>">🔒</span>
              <?php endif; ?>
              <?php if ($chipY === $activeYear): ?>
                <span title="Active Operating Year" style="font-size:9px">★</span>
              <?php endif; ?>
            </a>
          <?php endforeach; ?>
        </div>

        <div style="min-width:200px">
          <select class="select" style="height:36px;font-size:13px;font-weight:700;background:#ffffff;color:var(--navy-900);border-radius:8px"
                  onchange="location.href='academic-years.php?year=' + encodeURIComponent(this.value)">
            <option value="" disabled>-- Select Academic Year --</option>
            <?php foreach ($allYears as $optY):
                $optStats  = academic_year_summary_stats($optY);
                $optLocked = academic_year_is_locked($optY);
            ?>
              <option value="<?= e($optY) ?>" <?= $optY === $selectedYear ? 'selected' : '' ?>>
                <?= e($optY) ?> <?= $optY === $activeYear ? '★ Active' : '' ?> <?= $optLocked ? '[Locked]' : '[Open]' ?> (<?= (int)$optStats['records'] ?> rec)
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
    </div>
  </div>

  <!-- TOP 3 HERO CARDS (BALANCED, NEAT STRUCTURE) -->
  <div class="ay-stat-grid">

    <!-- CARD 1: ACADEMIC SCOPE & CYCLE PROGRESS -->
    <div class="ay-stat-card ay-card-scope">
      <div>
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px">
          <span style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--navy-500)">
            Academic Scope Details
          </span>
          <?php if ($isSelectedActive): ?>
            <span class="badge badge-success" style="font-size:11px;padding:3px 8px;font-weight:700">
              <?= icon('check', 11) ?> Active Operating
            </span>
          <?php else: ?>
            <span class="badge badge-neutral" style="font-size:11px;padding:3px 8px">
              Past Year Data
            </span>
          <?php endif; ?>
        </div>

        <div style="display:flex;align-items:baseline;gap:8px">
          <span style="font-size:30px;font-weight:800;color:var(--navy-900);letter-spacing:-.03em;font-family:monospace">
            <?= e($selectedYear) ?>
          </span>
          <?php if ($isSelectedCurrentCal): ?>
            <span class="badge badge-neutral" style="font-size:10px">Current Cal Year</span>
          <?php endif; ?>
        </div>

        <p style="font-size:12px;color:var(--navy-600);line-height:1.45;margin-top:6px;margin-bottom:0">
          <?php if ($isSelectedActive): ?>
            All institutional roles (<strong>Faculty, Coordinator, HoD, Dean, Principal</strong>) currently submit live data in this active year.
          <?php else: ?>
            Historical data, records, and performance targets preserved permanently for Academic Year <strong><?= e($selectedYear) ?></strong>.
          <?php endif; ?>
        </p>
      </div>

      <div style="margin-top:14px;border-top:1px solid var(--navy-100,#e2e8f0);padding-top:12px">
        <div style="display:flex;align-items:center;justify-content:space-between;font-size:11px;font-weight:600;color:var(--navy-700);margin-bottom:5px">
          <span><?= e($cyclePhase) ?></span>
          <span><?= (int) $cyclePct ?>%</span>
        </div>
        <div style="height:6px;background:var(--navy-100,#e2e8f0);border-radius:3px;overflow:hidden;margin-bottom:8px">
          <div style="width:<?= (int) $cyclePct ?>%;height:100%;background:<?= $selectedLockInfo['locked'] ? '#EF4444' : 'var(--orange-500,#ff4f01)' ?>;border-radius:3px"></div>
        </div>
        <div style="display:flex;align-items:center;justify-content:space-between;font-size:11px;color:var(--navy-600)">
          <span><strong><?= (int) $selectedStats['records'] ?></strong> Records (<?= (int)$selectedStats['approved_records'] ?> app.)</span>
          <span><strong><?= (int) $selectedStats['targets'] ?></strong> Targets</span>
        </div>
      </div>
    </div>

    <!-- CARD 2: CYCLE LOCK STATUS -->
    <div class="ay-stat-card ay-card-lock">
      <div>
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px">
          <span style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--navy-500)">
            Cycle Lock Status
          </span>
          <?php if ($selectedLockInfo['locked']): ?>
            <span style="display:inline-flex;align-items:center;gap:4px;background:#FEE2E2;color:#991B1B;border:1px solid #FECACA;padding:2px 8px;border-radius:6px;font-size:11px;font-weight:700">
              <?= icon('lock', 11) ?> LOCKED
            </span>
          <?php else: ?>
            <span style="display:inline-flex;align-items:center;gap:4px;background:#ECFDF5;color:#065F46;border:1px solid #A7F3D0;padding:2px 8px;border-radius:6px;font-size:11px;font-weight:700">
              <?= icon('unlock', 11) ?> OPEN
            </span>
          <?php endif; ?>
        </div>

        <div style="display:flex;align-items:center;gap:12px;margin-top:8px">
          <div style="width:42px;height:42px;border-radius:10px;display:flex;align-items:center;justify-content:center;background:<?= $selectedLockInfo['locked'] ? 'rgba(239,68,68,0.1)' : 'rgba(16,185,129,0.1)' ?>;color:<?= $selectedLockInfo['locked'] ? '#DC2626' : '#059669' ?>;flex-shrink:0">
            <?= icon($selectedLockInfo['locked'] ? 'lock' : 'unlock', 22) ?>
          </div>
          <div>
            <div style="font-size:14px;font-weight:700;color:var(--navy-900)">
              <?= $selectedLockInfo['locked'] ? 'Submissions Frozen for All Roles' : 'Submissions Active for All Roles' ?>
            </div>
            <div style="font-size:11px;color:var(--navy-500);margin-top:2px">
              <?php if ($selectedLockInfo['locked'] && $selectedLatestMeeting): ?>
                Locked following Meeting #<?= e($selectedLatestMeeting['meeting_number']) ?> (<?= date('d M Y', strtotime($selectedLatestMeeting['meeting_date'])) ?>)
              <?php elseif ($selectedLockInfo['locked']): ?>
                Administrative lock enforced
              <?php else: ?>
                Open for uploads, target creation, and approvals
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>

      <div style="border-top:1px solid var(--navy-100,#e2e8f0);padding-top:12px;margin-top:14px;display:flex;align-items:center;justify-content:space-between">
        <span style="font-size:11px;color:var(--navy-600)">
          <strong><?= $selectedExecCount ?></strong> Executive Meeting<?= $selectedExecCount === 1 ? '' : 's' ?> Held
        </span>
        <?php if ($selectedLockInfo['locked']): ?>
          <button type="button" class="btn btn-outline btn-sm" style="font-size:11px;padding:3px 10px;color:#059669;border-color:#A7F3D0" onclick="openUnlockModal('<?= e($selectedYear) ?>')">
            <?= icon('unlock', 12) ?> Unlock Year
          </button>
        <?php else: ?>
          <button type="button" class="btn btn-outline btn-sm" style="font-size:11px;padding:3px 10px;color:#DC2626;border-color:#FECACA" onclick="openExecMeetingForm()">
            <?= icon('lock', 12) ?> Lock via Meeting
          </button>
        <?php endif; ?>
      </div>
    </div>

    <!-- CARD 3: CURRENT CALENDAR (COMPACT & CLEAN) -->
    <div class="ay-stat-card ay-card-cal">
      <div>
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px">
          <span style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--navy-500)">
            Current Calendar
          </span>
          <span style="font-size:12px;font-weight:700;color:var(--navy-800)">
            <?= e($monthName . ' ' . $currentYearNum) ?>
          </span>
        </div>

        <div style="display:grid;grid-template-columns:repeat(7, 1fr);gap:3px;text-align:center;font-size:10px">
          <?php foreach (['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'] as $dow): ?>
            <div style="font-weight:700;color:var(--navy-400);padding:2px 0"><?= $dow ?></div>
          <?php endforeach; ?>

          <?php
          for ($i = 0; $i < $firstDayOfMonth; $i++) {
              echo '<div style="color:transparent;padding:2px 0">&middot;</div>';
          }
          for ($d = 1; $d <= $daysInMonth; $d++) {
              $isToday = ($d === $currentDayNum);
              $style = 'padding:2px 0;border-radius:5px;font-size:10px;';
              if ($isToday) {
                  $style .= 'background:var(--orange-500,#ff4f01);color:#ffffff;font-weight:700;box-shadow:0 1px 4px rgba(255,79,1,0.3);';
              } else {
                  $style .= 'color:var(--navy-700);';
              }
              echo "<div style=\"{$style}\">{$d}</div>";
          }
          ?>
        </div>
      </div>

      <div style="display:flex;align-items:center;justify-content:space-between;border-top:1px solid var(--navy-100,#e2e8f0);padding-top:10px;margin-top:10px;font-size:11px;color:var(--navy-600)">
        <span>Today: <strong><?= $now->format('D, d M Y') ?></strong></span>
        <span style="color:var(--orange-600);font-weight:600">Active IST</span>
      </div>
    </div>

  </div>

  <!-- =========================================================================
       EXECUTIVE MEETING & CYCLE LOCK CONTROL (SPACIOUS, STRUCTURED FORM)
       ========================================================================= -->
  <div id="execMeetingBox" class="card" style="background:#ffffff;border:1px solid var(--navy-200,#cbd5e1);border-radius:12px;box-shadow:0 3px 10px rgba(15,23,42,0.04);margin-bottom:24px;overflow:hidden">
    <div style="background:linear-gradient(135deg, var(--navy-900,#131D3B) 0%, #1e293b 100%);color:#ffffff;padding:16px 20px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px">
      <div style="display:flex;align-items:center;gap:12px">
        <div style="width:38px;height:38px;border-radius:8px;background:rgba(255,255,255,0.12);border:1px solid rgba(255,255,255,0.2);display:flex;align-items:center;justify-content:center;color:#ffffff">
          <?= icon('award', 20) ?>
        </div>
        <div>
          <div style="display:flex;align-items:center;gap:8px">
            <h2 style="font-size:16px;font-weight:700;color:#ffffff;margin:0">Executive Meeting &amp; Cycle Lock Control</h2>
            <span style="background:rgba(255,79,1,0.95);color:#fff;font-size:11px;font-weight:700;padding:2px 8px;border-radius:10px;font-family:monospace">
              <?= e($selectedYear) ?>
            </span>
          </div>
          <div style="font-size:12px;color:rgba(255,255,255,0.7);margin-top:2px">
            Record a finished executive meeting to immediately lock academic year <strong><?= e($selectedYear) ?></strong> across all roles.
          </div>
        </div>
      </div>

      <div style="display:flex;align-items:center;gap:10px">
        <span class="badge badge-neutral" style="background:rgba(255,255,255,0.15);color:#fff;font-size:11px;padding:4px 10px">
          <?= $selectedExecCount ?> Meeting<?= $selectedExecCount === 1 ? '' : 's' ?> Recorded
        </span>
        <?php if ($selectedLockInfo['locked']): ?>
          <span style="background:#DC2626;color:#ffffff;padding:4px 10px;border-radius:6px;font-size:11px;font-weight:700;display:inline-flex;align-items:center;gap:4px">
            <?= icon('lock', 11) ?> Cycle Locked
          </span>
        <?php else: ?>
          <span style="background:#059669;color:#ffffff;padding:4px 10px;border-radius:6px;font-size:11px;font-weight:700;display:inline-flex;align-items:center;gap:4px">
            <?= icon('unlock', 11) ?> Cycle Open
          </span>
        <?php endif; ?>
      </div>
    </div>

    <div style="padding:20px">
      <?php if ($selectedLockInfo['locked']): ?>
        <!-- LOCKED ALERT BANNER -->
        <div style="background:#FEF2F2;border:1px solid #FECACA;border-left:4px solid #DC2626;border-radius:8px;padding:12px 16px;margin-bottom:18px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px">
          <div style="display:flex;align-items:center;gap:10px">
            <div style="width:32px;height:32px;border-radius:6px;background:#FEE2E2;color:#DC2626;display:flex;align-items:center;justify-content:center;flex-shrink:0">
              <?= icon('lock', 16) ?>
            </div>
            <div>
              <div style="font-weight:700;font-size:13px;color:#991B1B">
                Academic Year <?= e($selectedYear) ?> is currently Locked
                <?php if ($selectedLatestMeeting): ?>
                  &middot; Meeting #<?= e($selectedLatestMeeting['meeting_number']) ?> (<?= date('d M Y', strtotime($selectedLatestMeeting['meeting_date'])) ?>)
                <?php endif; ?>
              </div>
              <div style="font-size:12px;color:#B91C1C;margin-top:1px">
                Data uploads and targets are frozen in read-only mode for Faculty, Coordinators, HoDs, and Deans.
              </div>
            </div>
          </div>
          <button type="button" class="btn btn-primary btn-sm" style="background:#059669;border-color:#059669;font-weight:700" onclick="openUnlockModal('<?= e($selectedYear) ?>')">
            <?= icon('unlock', 13) ?> Reopen / Unlock Year
          </button>
        </div>
      <?php endif; ?>

      <!-- STRUCTURED MEETING ENTRY FORM -->
      <div style="background:var(--navy-50,#f8fafc);border:1px solid var(--navy-100,#e2e8f0);border-radius:10px;padding:18px;margin-bottom:20px">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;flex-wrap:wrap;gap:8px">
          <div style="font-size:13px;font-weight:700;color:var(--navy-900);display:flex;align-items:center;gap:6px">
            <?= icon('pencil', 14) ?>
            <span>Record Finished Executive Meeting &amp; Lock Academic Year</span>
          </div>
          <div style="font-size:11px;color:var(--navy-500)">
            Next Suggested: <strong>Meeting #<?= $selectedNextMeetingNum ?></strong>
          </div>
        </div>

        <form method="post" onsubmit="return confirm('Record this Executive Meeting and lock academic year ' + document.getElementById('meeting_academic_year').value + ' for all roles?');">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="finish_executive_meeting">

          <!-- Row 1: Academic Year, Meeting Number, Date Finished -->
          <div class="ay-form-grid">
            <div class="field" style="margin:0">
              <label for="meeting_academic_year" style="font-size:12px;font-weight:700;color:var(--navy-800);margin-bottom:5px;display:block">
                Choose Year to Lock <span style="color:#DC2626">*</span>
              </label>
              <select class="select" id="meeting_academic_year" name="academic_year" style="width:100%;height:40px;font-weight:700;font-size:13px;font-family:monospace"
                      onchange="if(this.value !== '<?= e($selectedYear) ?>') location.href='academic-years.php?year=' + encodeURIComponent(this.value);">
                <?php foreach ($allYears as $optY): ?>
                  <option value="<?= e($optY) ?>" <?= $optY === $selectedYear ? 'selected' : '' ?>>
                    <?= e($optY) ?><?= $optY === $activeYear ? ' (Active System Year)' : '' ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="field" style="margin:0">
              <label for="meeting_number" style="font-size:12px;font-weight:700;color:var(--navy-800);margin-bottom:5px;display:block">
                Meeting Number <span style="color:#DC2626">*</span>
              </label>
              <input type="text" class="input" id="meeting_number" name="meeting_number" value="<?= $selectedNextMeetingNum ?>" placeholder="e.g. 1" required style="width:100%;height:40px;font-weight:700;font-size:13px">
            </div>

            <div class="field" style="margin:0">
              <label for="meeting_date" style="font-size:12px;font-weight:700;color:var(--navy-800);margin-bottom:5px;display:block">
                Date Finished <span style="color:#DC2626">*</span>
              </label>
              <input type="date" class="input" id="meeting_date" name="meeting_date" value="<?= date('Y-m-d') ?>" required style="width:100%;height:40px;font-size:13px">
            </div>
          </div>

          <!-- Row 2: Minutes Remarks + Lock Button -->
          <div class="ay-form-bottom">
            <div class="field">
              <label for="meeting_notes" style="font-size:12px;font-weight:700;color:var(--navy-800);margin-bottom:5px;display:block">
                Meeting Minutes / Remarks Summary (Optional)
              </label>
              <input type="text" class="input" id="meeting_notes" name="notes" placeholder="e.g. Executive Committee reviewed semester targets, audits complete." style="width:100%;height:40px;font-size:13px">
            </div>

            <div>
              <button type="submit" class="btn btn-primary" style="height:40px;background:#DC2626;border-color:#DC2626;color:#ffffff;display:inline-flex;align-items:center;gap:6px;font-weight:700;white-space:nowrap;padding:0 20px">
                <?= icon('lock', 14) ?> Finish &amp; Lock Cycle
              </button>
            </div>
          </div>
        </form>
      </div>

      <!-- AUDIT TRAIL LOG OF EXECUTIVE MEETINGS -->
      <div>
        <div style="font-size:13px;font-weight:700;color:var(--navy-800);margin-bottom:10px;display:flex;align-items:center;justify-content:space-between">
          <span>Executive Meetings Finished for <?= e($selectedYear) ?> (<?= $selectedExecCount ?> Total)</span>
          <span style="font-size:11px;color:var(--navy-500)">Institutional Audit Trail Log</span>
        </div>

        <?php if ($selectedExecCount === 0): ?>
          <div style="background:var(--navy-50,#f8fafc);border:1px dashed var(--navy-200,#cbd5e1);padding:16px;border-radius:8px;text-align:center;color:var(--navy-500);font-size:12px">
            No executive meetings recorded for Academic Year <strong><?= e($selectedYear) ?></strong> yet.
          </div>
        <?php else: ?>
          <div class="table-wrap" style="margin:0;border:1px solid var(--navy-100,#e2e8f0);border-radius:8px">
            <table class="table" style="width:100%;font-size:12px">
              <thead>
                <tr style="background:#fafbfc">
                  <th style="width:130px">Meeting #</th>
                  <th style="width:130px">Date Finished</th>
                  <th style="width:150px">Recorded By</th>
                  <th>Remarks / Minutes Summary</th>
                  <th style="width:140px;text-align:right">Cycle State</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($selectedExecMeetings as $idx => $m): ?>
                  <tr>
                    <td>
                      <div style="font-weight:700;color:var(--navy-900);display:flex;align-items:center;gap:6px">
                        <span style="width:20px;height:20px;border-radius:50%;background:rgba(255,79,1,0.1);color:var(--orange-600);display:inline-flex;align-items:center;justify-content:center;font-size:10px;font-weight:800">
                          <?= $idx + 1 ?>
                        </span>
                        Meeting #<?= e($m['meeting_number']) ?>
                      </div>
                    </td>
                    <td><?= date('d M Y', strtotime($m['meeting_date'])) ?></td>
                    <td><span style="font-weight:600;color:var(--navy-800)"><?= e($m['admin_name'] ?? 'Administrator') ?></span></td>
                    <td><?= e($m['notes'] ?: 'Executive meeting concluded.') ?></td>
                    <td style="text-align:right">
                      <span style="display:inline-flex;align-items:center;gap:4px;color:#991B1B;background:#FEE2E2;border:1px solid #FECACA;font-size:10px;font-weight:700;padding:2px 7px;border-radius:6px">
                        <?= icon('lock', 10) ?> Finished &amp; Locked
                      </span>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- ACADEMIC DATA & PERFORMANCE BREAKDOWN (NEAT 2-COLUMN STRUCTURE) -->
  <div class="card" style="margin-bottom:24px;border:1px solid var(--navy-100,#e2e8f0);border-radius:12px;overflow:hidden">
    <div style="padding:14px 18px;border-bottom:1px solid var(--navy-100,#e2e8f0);background:#fafbfc;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px">
      <div style="display:flex;align-items:center;gap:10px">
        <div style="width:30px;height:30px;border-radius:6px;background:rgba(255,79,1,0.1);color:var(--orange-600);display:flex;align-items:center;justify-content:center">
          <?= icon('bar-chart', 15) ?>
        </div>
        <div>
          <h2 style="font-size:14px;font-weight:700;color:var(--navy-900);margin:0">
            Academic Data &amp; Targets Breakdown &middot; <?= e($selectedYear) ?>
          </h2>
        </div>
      </div>
      <div style="display:flex;align-items:center;gap:8px">
        <a href="<?= e(url('reports.php?year=' . urlencode($selectedYear))) ?>" class="btn btn-outline btn-sm" style="font-size:11px;padding:3px 10px">
          <?= icon('download', 12) ?> Open Reports
        </a>
        <a href="<?= e(url('targets.php')) ?>" class="btn btn-outline btn-sm" style="font-size:11px;padding:3px 10px">
          <?= icon('target', 12) ?> Open Targets
        </a>
      </div>
    </div>

    <div style="padding:18px">
      <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(320px, 1fr));gap:18px">

        <!-- FACULTY RECORDS BY CATEGORY -->
        <div>
          <div style="font-size:12px;font-weight:700;color:var(--navy-800);margin-bottom:8px;display:flex;align-items:center;justify-content:space-between">
            <span>Faculty Records by Category (<?= (int)$selectedStats['records'] ?> Total)</span>
            <span style="font-size:11px;color:#059669;font-weight:600"><?= (int)$selectedStats['approved_records'] ?> Approved</span>
          </div>

          <?php if (empty($selectedCategoryData)): ?>
            <div style="background:var(--navy-50,#f8fafc);border:1px dashed var(--navy-200,#cbd5e1);padding:20px;border-radius:8px;text-align:center;color:var(--navy-400);font-size:12px">
              No record submissions found for Academic Year <strong><?= e($selectedYear) ?></strong>.
            </div>
          <?php else: ?>
            <div class="table-wrap" style="margin:0;border:1px solid var(--navy-100,#e2e8f0);border-radius:8px">
              <table class="table" style="width:100%;font-size:12px">
                <thead>
                  <tr style="background:#f8fafc">
                    <th>Record Category</th>
                    <th style="text-align:center;width:80px">Submitted</th>
                    <th style="text-align:center;width:80px">Approved</th>
                    <th style="text-align:right;width:90px">Approval %</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($selectedCategoryData as $catKey => $cat):
                      $pct = $cat['total'] > 0 ? round(($cat['approved'] / $cat['total']) * 100) : 0;
                  ?>
                    <tr>
                      <td><div style="font-weight:600;color:var(--navy-900)"><?= e($cat['label']) ?></div></td>
                      <td style="text-align:center"><span class="badge badge-neutral" style="font-size:11px"><?= $cat['total'] ?></span></td>
                      <td style="text-align:center"><span class="badge badge-success" style="font-size:11px"><?= $cat['approved'] ?></span></td>
                      <td style="text-align:right">
                        <span style="font-weight:700;color:<?= $pct >= 80 ? '#059669' : ($pct >= 50 ? '#D97706' : '#DC2626') ?>"><?= $pct ?>%</span>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>

        <!-- DEPARTMENT TARGETS -->
        <div>
          <div style="font-size:12px;font-weight:700;color:var(--navy-800);margin-bottom:8px;display:flex;align-items:center;justify-content:space-between">
            <span>Department Targets Registered (<?= (int)$selectedStats['targets'] ?> Total)</span>
            <span style="font-size:11px;color:var(--navy-500)">By Department</span>
          </div>

          <?php if (empty($selectedDeptTargets)): ?>
            <div style="background:var(--navy-50,#f8fafc);border:1px dashed var(--navy-200,#cbd5e1);padding:20px;border-radius:8px;text-align:center;color:var(--navy-400);font-size:12px">
              No department targets registered for Academic Year <strong><?= e($selectedYear) ?></strong>.
            </div>
          <?php else: ?>
            <div class="table-wrap" style="margin:0;border:1px solid var(--navy-100,#e2e8f0);border-radius:8px">
              <table class="table" style="width:100%;font-size:12px">
                <thead>
                  <tr style="background:#f8fafc">
                    <th>Department</th>
                    <th style="text-align:center;width:75px">Total</th>
                    <th style="text-align:center;width:75px">Approved</th>
                    <th style="text-align:center;width:75px">Pending</th>
                    <th style="text-align:right;width:75px">Draft</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($selectedDeptTargets as $dt): ?>
                    <tr>
                      <td><div style="font-weight:700;color:var(--navy-900)"><?= e($dt['department']) ?></div></td>
                      <td style="text-align:center"><strong><?= (int)$dt['total_targets'] ?></strong></td>
                      <td style="text-align:center"><span class="badge badge-success" style="font-size:10px"><?= (int)$dt['approved_targets'] ?></span></td>
                      <td style="text-align:center"><span class="badge badge-neutral" style="font-size:10px"><?= (int)$dt['pending_targets'] ?></span></td>
                      <td style="text-align:right"><span style="font-size:11px;color:var(--navy-500)"><?= (int)$dt['draft_targets'] ?></span></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>

      </div>
    </div>
  </div>

  <!-- DISCOVERY BANNER CARD: VIEW REGISTRY BY BUTTON -->
  <div class="card" style="background:linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);border:1px solid var(--navy-200,#cbd5e1);border-radius:12px;padding:18px 24px;margin-bottom:24px">
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:14px">
      <div style="display:flex;align-items:center;gap:14px">
        <div style="width:40px;height:40px;border-radius:10px;background:rgba(15,23,42,0.08);display:flex;align-items:center;justify-content:center;color:var(--navy-800)">
          <?= icon('building', 20) ?>
        </div>
        <div>
          <div style="font-size:14px;font-weight:700;color:var(--navy-900)">
            Institutional Academic Years Registry &middot; <?= count($allYears) ?> Years Registered
          </div>
          <div style="font-size:12px;color:var(--navy-600);margin-top:2px">
            Browse complete institutional timeline, inspect past data across decades, or switch active system years.
          </div>
        </div>
      </div>
      <button type="button" class="btn btn-primary btn-sm" onclick="switchAyTab('registry')" style="font-weight:700;padding:8px 18px">
        <?= icon('file-stack', 14) ?> View Full Academic Years Registry &rarr;
      </button>
    </div>
  </div>

</div>


<!-- =========================================================================
     TAB 2: ACADEMIC YEARS REGISTRY (VIEWABLE ON-DEMAND VIA BUTTON)
     ========================================================================= -->
<div id="ay_tab_registry" style="display:none">

  <div class="card" style="padding:0;overflow:hidden;border:1px solid var(--navy-100,#e2e8f0);border-radius:12px;box-shadow:0 3px 12px rgba(15,23,42,0.04);margin-bottom:24px">

    <!-- REGISTRY TOOLBAR WITH SEARCH -->
    <div class="ay-registry-toolbar">
      <div>
        <div style="display:flex;align-items:center;gap:8px">
          <h2 style="font-size:16px;font-weight:700;color:var(--navy-900);margin:0">Academic Years Registry &amp; Multi-Year Status</h2>
          <span class="badge badge-neutral" style="font-size:11px">
            <span id="ay_visible_count"><?= count($allYears) ?></span> of <?= count($allYears) ?> Years
          </span>
        </div>
        <div style="font-size:12px;color:var(--navy-500);margin-top:2px">
          Inspect any year's data, manage cycle locks, or change system-wide operating year.
        </div>
      </div>

      <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
        <!-- LIVE SEARCH INPUT -->
        <label class="fb-field fb-search" style="min-width:300px">
          <?= icon('search', 15) ?>
          <input type="search" id="ay_registry_search" placeholder="Search year or status (e.g. 2025, locked)…"
                 oninput="filterRegistryTable(this.value)" aria-label="Search the academic years registry">
        </label>

        <button type="button" class="btn btn-outline btn-sm" onclick="switchAyTab('overview')" style="font-weight:600">
          &larr; Back to Year Overview
        </button>
      </div>
    </div>

    <!-- REGISTRY TABLE -->
    <div class="table-wrap" style="margin:0;border:0;max-height:680px;overflow-y:auto">
      <table class="table" style="width:100%">
        <thead>
          <tr style="position:sticky;top:0;background:#fafbfc;z-index:2">
            <th style="width:150px">Academic Year</th>
            <th style="width:160px">System Status</th>
            <th style="width:170px">Cycle Lock</th>
            <th style="width:130px;text-align:center">Exec Meetings</th>
            <th style="width:140px;text-align:right">Faculty Records</th>
            <th style="width:90px;text-align:right">Targets</th>
            <th style="width:250px;text-align:right">Actions</th>
          </tr>
        </thead>
        <tbody id="ay_registry_tbody">
          <?php foreach ($allYears as $y):
              $isActive   = ($y === $activeYear);
              $isSelected = ($y === $selectedYear);
              $isCurCal   = ($y === $currentCalYear);
              $isLocked   = academic_year_is_locked($y);
              $stats      = academic_year_summary_stats($y);
              $mCount     = executive_meeting_count($y);
          ?>
            <tr style="<?= $isSelected ? 'background:rgba(255,79,1,0.04);' : '' ?>">
              <td>
                <div style="display:flex;align-items:center;gap:6px">
                  <span style="font-family:monospace;font-size:14px;font-weight:700;color:var(--navy-900)"><?= e($y) ?></span>
                  <?php if ($isSelected): ?>
                    <span class="badge badge-brand" style="font-size:9px;padding:2px 6px">Viewing</span>
                  <?php endif; ?>
                  <?php if ($isCurCal): ?>
                    <span class="badge badge-neutral" style="font-size:9px;padding:2px 6px" title="Current Real Calendar Year">Cal</span>
                  <?php endif; ?>
                </div>
              </td>
              <td>
                <?php if ($isActive): ?>
                  <span class="badge badge-success" style="font-size:11px;font-weight:700">
                    <?= icon('check', 12) ?> Active Operating
                  </span>
                <?php else: ?>
                  <span class="badge badge-neutral" style="font-size:11px">Past Year</span>
                <?php endif; ?>
              </td>
              <td>
                <?php if ($isLocked): ?>
                  <span style="display:inline-flex;align-items:center;gap:4px;color:#991B1B;background:#FEE2E2;border:1px solid #FECACA;font-size:11px;font-weight:700;padding:2px 8px;border-radius:6px">
                    <?= icon('lock', 11) ?> Locked / Frozen
                  </span>
                <?php else: ?>
                  <span style="display:inline-flex;align-items:center;gap:4px;color:#065F46;background:#ECFDF5;border:1px solid #A7F3D0;font-size:11px;font-weight:700;padding:2px 8px;border-radius:6px">
                    <?= icon('unlock', 11) ?> Open for Uploads
                  </span>
                <?php endif; ?>
              </td>
              <td style="text-align:center">
                <span class="badge <?= $mCount > 0 ? 'badge-brand' : 'badge-neutral' ?>" style="font-size:11px;font-weight:600">
                  <?= $mCount ?> Meeting<?= $mCount === 1 ? '' : 's' ?>
                </span>
              </td>
              <td style="text-align:right">
                <span style="font-weight:600;color:var(--navy-800)"><?= (int) $stats['records'] ?></span>
                <span style="font-size:11px;color:var(--navy-400)"> (<?= (int) $stats['approved_records'] ?> app.)</span>
              </td>
              <td style="text-align:right">
                <span style="font-weight:600;color:var(--navy-800)"><?= (int) $stats['targets'] ?></span>
              </td>
              <td style="text-align:right">
                <div style="display:inline-flex;align-items:center;gap:6px">
                  <!-- VIEW DATA BUTTON -->
                  <a href="<?= e(url('academic-years.php?year=' . urlencode($y))) ?>"
                     class="btn <?= $isSelected ? 'btn-primary' : 'btn-outline' ?> btn-sm"
                     style="font-size:11px;padding:3px 8px" title="Inspect records, targets and meetings for <?= e($y) ?>">
                    <?= $isSelected ? 'Viewing' : 'View Data' ?>
                  </a>

                  <!-- LOCK / UNLOCK TOGGLES -->
                  <?php if ($isLocked): ?>
                    <button type="button" class="btn btn-outline btn-sm" style="font-size:11px;padding:3px 8px;color:#059669;border-color:#A7F3D0" onclick="openUnlockModal('<?= e($y) ?>')">
                      <?= icon('unlock', 12) ?> Unlock
                    </button>
                  <?php else: ?>
                    <button type="button" class="btn btn-outline btn-sm" style="font-size:11px;padding:3px 8px;color:#DC2626;border-color:#FECACA" onclick="openDirectLockModal('<?= e($y) ?>')">
                      <?= icon('lock', 12) ?> Lock
                    </button>
                  <?php endif; ?>

                  <!-- SET ACTIVE YEAR (IF NOT ACTIVE) -->
                  <?php if (!$isActive): ?>
                    <form method="post" style="margin:0;display:inline" onsubmit="return confirm('Activate academic year <?= e($y) ?> for all users system-wide?');">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="activate">
                      <input type="hidden" name="academic_year" value="<?= e($y) ?>">
                      <button type="submit" class="btn btn-outline btn-sm" style="font-size:11px;padding:3px 8px" title="Make this the active operating year for all users">
                        Set Active
                      </button>
                    </form>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>

      <div id="ay_registry_no_match" style="display:none;padding:36px;text-align:center;color:var(--navy-400);font-size:13px">
        No academic years match that search query.
      </div>
    </div>
  </div>

</div>

<!-- =========================================================================
     MODAL 1: SWITCH SYSTEM-WIDE OPERATING ACADEMIC YEAR
     ========================================================================= -->
<dialog id="switchYearDlg" class="modal">
  <div class="modal-box" style="max-width:440px">
    <div class="modal-head">
      <div style="display:flex;align-items:center;gap:8px">
        <?= icon('calendar', 18) ?>
        <span style="font-weight:700;font-size:16px">Switch Active Academic Year</span>
      </div>
      <button type="button" class="modal-close" onclick="this.closest('dialog').close()">&times;</button>
    </div>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="activate">
      <div class="modal-body" style="padding:20px">
        <p style="font-size:13px;color:var(--navy-600);margin-bottom:16px;line-height:1.5">
          Select the academic year you want the entire ATTS portal to operate in. All user roles will immediately switch to this year.
        </p>

        <div class="field">
          <label for="modal_ay_select" style="font-weight:600;font-size:13px;margin-bottom:6px;display:block">
            Choose Academic Year
          </label>
          <select class="select" id="modal_ay_select" name="academic_year" style="width:100%;height:44px;font-size:14px;font-weight:600">
            <?php foreach ($allYears as $ay): ?>
              <option value="<?= e($ay) ?>" <?= $ay === $activeYear ? 'selected' : '' ?>>
                <?= e($ay) ?><?= $ay === $currentCalYear ? ' (Current Calendar Year)' : '' ?><?= $ay === $activeYear ? ' ★ Active' : '' ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div style="background:var(--navy-50,#f1f5f9);padding:10px 12px;border-radius:6px;font-size:11px;color:var(--navy-600);margin-top:14px">
          💡 <strong>Tip:</strong> Past records are preserved permanently. Switching years only shifts the display and tracking scope.
        </div>
      </div>
      <div class="modal-foot" style="display:flex;justify-content:flex-end;gap:8px;padding:12px 20px">
        <button type="button" class="btn btn-outline btn-sm" onclick="this.closest('dialog').close()">Cancel</button>
        <button type="submit" class="btn btn-primary btn-sm">Activate for All Roles</button>
      </div>
    </form>
  </div>
</dialog>

<!-- =========================================================================
     MODAL 2: UNLOCK CYCLE CONFIRMATION FOR CHOSEN YEAR
     ========================================================================= -->
<dialog id="unlockCycleDlg" class="modal">
  <div class="modal-box" style="max-width:440px">
    <div class="modal-head">
      <div style="display:flex;align-items:center;gap:8px">
        <span style="color:#059669"><?= icon('unlock', 18) ?></span>
        <span style="font-weight:700;font-size:16px">Unlock Academic Year Cycle</span>
      </div>
      <button type="button" class="modal-close" onclick="this.closest('dialog').close()">&times;</button>
    </div>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="unlock_cycle">
      <input type="hidden" name="academic_year" id="unlock-modal-year" value="<?= e($selectedYear) ?>">

      <div class="modal-body" style="padding:20px">
        <p style="font-size:13px;color:var(--navy-600);margin-bottom:14px;line-height:1.5">
          You are unlocking Academic Year <strong><span id="unlock-modal-year-display"><?= e($selectedYear) ?></span></strong>.
          Submissions and target adjustments will be re-enabled for all authorized roles until the next Executive Meeting.
        </p>

        <div class="field">
          <label for="unlock_reason" style="font-weight:600;font-size:13px;margin-bottom:6px;display:block">
            Reason for Reopening (Optional)
          </label>
          <input type="text" class="input" id="unlock_reason" name="reason" placeholder="e.g. Additional submission window before next Executive Meeting" style="width:100%">
        </div>
      </div>
      <div class="modal-foot" style="display:flex;justify-content:flex-end;gap:8px;padding:12px 20px">
        <button type="button" class="btn btn-outline btn-sm" onclick="this.closest('dialog').close()">Cancel</button>
        <button type="submit" class="btn btn-primary btn-sm" style="background:#059669;border-color:#059669">Confirm Unlock</button>
      </div>
    </form>
  </div>
</dialog>

<!-- =========================================================================
     MODAL 3: DIRECT LOCK CONFIRMATION FOR CHOSEN YEAR
     ========================================================================= -->
<dialog id="directLockDlg" class="modal">
  <div class="modal-box" style="max-width:440px">
    <div class="modal-head">
      <div style="display:flex;align-items:center;gap:8px">
        <span style="color:#DC2626"><?= icon('lock', 18) ?></span>
        <span style="font-weight:700;font-size:16px">Lock Academic Year Cycle</span>
      </div>
      <button type="button" class="modal-close" onclick="this.closest('dialog').close()">&times;</button>
    </div>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="toggle_lock">
      <input type="hidden" name="academic_year" id="direct-lock-year" value="">
      <input type="hidden" name="lock_state" value="1">

      <div class="modal-body" style="padding:20px">
        <p style="font-size:13px;color:var(--navy-600);margin-bottom:14px;line-height:1.5">
          Lock academic year <strong><span id="direct-lock-year-display"></span></strong>? When locked, all data submissions and target edits will be frozen across all roles.
        </p>

        <div class="field">
          <label for="direct_lock_note" style="font-weight:600;font-size:13px;margin-bottom:6px;display:block">
            Administrative Note (Optional)
          </label>
          <input type="text" class="input" id="direct_lock_note" name="note" placeholder="e.g. Manual cycle lock by Administrator" style="width:100%">
        </div>
      </div>
      <div class="modal-foot" style="display:flex;justify-content:flex-end;gap:8px;padding:12px 20px">
        <button type="button" class="btn btn-outline btn-sm" onclick="this.closest('dialog').close()">Cancel</button>
        <button type="submit" class="btn btn-primary btn-sm" style="background:#DC2626;border-color:#DC2626">Lock for All Roles</button>
      </div>
    </form>
  </div>
</dialog>

<script>
function switchAyTab(tabName) {
  var overviewTab = document.getElementById('ay_tab_overview');
  var registryTab = document.getElementById('ay_tab_registry');
  var navOverview = document.getElementById('ay_nav_overview');
  var navRegistry = document.getElementById('ay_nav_registry');
  var topBtn = document.getElementById('btnToggleRegistry');

  if (tabName === 'registry') {
    if (overviewTab) overviewTab.style.display = 'none';
    if (registryTab) registryTab.style.display = 'block';
    if (navOverview) navOverview.classList.remove('active');
    if (navRegistry) navRegistry.classList.add('active');
    if (topBtn) {
      topBtn.innerHTML = '<?= icon('dashboard', 14) ?> View Year Overview';
      topBtn.setAttribute('onclick', "switchAyTab('overview')");
      topBtn.className = 'btn btn-primary btn-sm';
    }
  } else {
    if (overviewTab) overviewTab.style.display = 'block';
    if (registryTab) registryTab.style.display = 'none';
    if (navOverview) navOverview.classList.add('active');
    if (navRegistry) navRegistry.classList.remove('active');
    if (topBtn) {
      topBtn.innerHTML = '<?= icon('file-stack', 14) ?> View Academic Years Registry (<?= count($allYears) ?>)';
      topBtn.setAttribute('onclick', "switchAyTab('registry')");
      topBtn.className = 'btn btn-outline btn-sm';
    }
  }
}

function openExecMeetingForm() {
  switchAyTab('overview');
  var el = document.getElementById('execMeetingBox');
  if (el) el.scrollIntoView({ behavior: 'smooth' });
}

function openUnlockModal(year) {
  document.getElementById('unlock-modal-year').value = year;
  document.getElementById('unlock-modal-year-display').textContent = year;
  document.getElementById('unlockCycleDlg').showModal();
}

function openDirectLockModal(year) {
  document.getElementById('direct-lock-year').value = year;
  document.getElementById('direct-lock-year-display').textContent = year;
  document.getElementById('directLockDlg').showModal();
}

function filterRegistryTable(query) {
  var q = (query || '').toLowerCase().trim();
  var rows = document.querySelectorAll('#ay_registry_tbody tr');
  var visible = 0;
  rows.forEach(function(r) {
    var txt = r.textContent.toLowerCase();
    if (!q || txt.indexOf(q) !== -1) {
      r.style.display = '';
      visible++;
    } else {
      r.style.display = 'none';
    }
  });
  var noMatch = document.getElementById('ay_registry_no_match');
  if (noMatch) noMatch.style.display = (visible === 0) ? 'block' : 'none';
  var cnt = document.getElementById('ay_visible_count');
  if (cnt) cnt.textContent = visible;
}

// Check on load if URL requests registry tab
document.addEventListener('DOMContentLoaded', function() {
  var params = new URLSearchParams(window.location.search);
  if (params.get('tab') === 'registry' || window.location.hash === '#registry') {
    switchAyTab('registry');
  }
});
</script>

<?php require __DIR__ . '/inc/footer.php'; ?>
