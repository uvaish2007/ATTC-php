<?php
/**
 * FEAT-07 — current Executive Meeting status, shown on the dashboards.
 *
 * Reads only from em_status() (models/ExecutiveMeeting.php); no date logic
 * lives here. Expects $user from the including page. The header has already
 * loaded the EM engine, but require it anyway so the partial stands alone.
 */

require_once __DIR__ . '/../models/ExecutiveMeeting.php';

$emDash    = em_status();
$emDashFmt = fn(string $d): string => date('d M Y', strtotime($d));
?>
<div class="card mt-4 em-dash">
  <div class="card-body em-dash-body">
    <div class="em-dash-main">
      <div class="em-dash-k"><?= icon('calendar', 13) ?> Current Executive Meeting &middot; <?= e($emDash['year']) ?></div>
      <?php if (!$emDash['configured']): ?>
        <div class="em-dash-v"><?= e(EM_NOT_CONFIGURED_MESSAGE) ?></div>
        <?php if (($user['role'] ?? '') === 'Admin'): ?>
          <a class="em-dash-link" href="<?= e(url('academic-years.php') . '?year=' . urlencode($emDash['year']) . '#emScheduleBox') ?>">
            Configure the EM1 / EM2 schedule &rarr;
          </a>
        <?php endif; ?>
      <?php else: ?>
        <div class="em-dash-v"><?= e($emDash['label']) ?></div>
        <div class="em-dash-s"><?= e($emDash['detail']) ?></div>
      <?php endif; ?>
    </div>

    <?php if ($emDash['configured']): ?>
      <?php $sch = $emDash['schedule']; ?>
      <div class="em-dash-meetings">
        <?php foreach (EM_MEETINGS as $key => $name): ?>
          <?php
            if ($emDash['current'] === $key) {
                [$pillClass, $pillText] = ['active', 'Active'];
            } elseif ($key === 'em1' && $emDash['em1_locked']) {
                [$pillClass, $pillText] = ['locked', 'Locked'];
            } elseif ($key === 'em2' && $emDash['state'] === EM_STATE_EM2_ENDED) {
                [$pillClass, $pillText] = ['past', 'Ended'];
            } else {
                [$pillClass, $pillText] = ['newer', 'Upcoming'];
            }
          ?>
          <div class="em-dash-meeting">
            <div class="em-dash-name"><?= e($name) ?> <span class="ay-pill <?= $pillClass ?>"><?= e($pillText) ?></span></div>
            <div class="em-dash-dates"><?= e($emDashFmt($sch[$key . '_start'])) ?> – <?= e($emDashFmt($sch[$key . '_end'])) ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<style>
  .em-dash-body { display:flex; align-items:center; justify-content:space-between; gap:16px 28px; flex-wrap:wrap; padding:16px 22px; }
  .em-dash-main { flex:1 1 280px; min-width:0; }
  .em-dash-k { display:flex; align-items:center; gap:6px; font-size:11px; font-weight:700; text-transform:uppercase;
      letter-spacing:.05em; color:var(--ink-faint); }
  .em-dash-v { font-size:17px; font-weight:700; color:var(--ink); margin-top:4px; }
  .em-dash-s { font-size:12.5px; color:var(--ink-muted); margin-top:2px; }
  .em-dash-link { display:inline-block; margin-top:6px; font-size:12.5px; font-weight:600; color:var(--orange-600); }
  .em-dash-meetings { display:flex; gap:12px; flex-wrap:wrap; }
  .em-dash-meeting { border:1px solid var(--hairline); border-radius:var(--r-lg); padding:9px 14px; min-width:190px; }
  .em-dash-name { display:flex; align-items:center; gap:8px; font-size:13px; font-weight:700; color:var(--ink); }
  .em-dash-dates { font-size:12px; color:var(--ink-muted); margin-top:3px; white-space:nowrap; }
</style>
