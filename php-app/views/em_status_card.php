<?php
/**
 * FEAT-07 — current Executive Meeting status, shown on the dashboards.
 *
 * Reads only from em_status() (models/ExecutiveMeeting.php); no date logic
 * lives here. Expects $user from the including page. The header has already
 * loaded the EM engine, but require it anyway so the partial stands alone.
 */

require_once __DIR__ . '/../models/ExecutiveMeeting.php';

$dashYear   = $data['scope']['year'] ?? ($year ?? active_academic_year());
$emDash     = em_status($dashYear);
$emDashFmt  = fn(string $d): string => date('d M Y', strtotime($d));

// EM-SPEC-05: Check whether an active Executive Meeting is in session.
$isEm1Active = ($emDash['configured'] ?? false)
    && ($emDash['current'] === 'em1' || ($emDash['em1']['state'] ?? '') === 'ACTIVE')
    && empty($emDash['em1_locked']);
$isEm2Active = ($emDash['configured'] ?? false)
    && ($emDash['current'] === 'em2' || ($emDash['em2']['state'] ?? '') === 'ACTIVE');

$canPresent    = $isEm1Active || $isEm2Active;
$activeMeeting = $isEm1Active ? 'em1' : ($isEm2Active ? 'em2' : null);

$dashPresentUrl = '';
if ($canPresent) {
    $dashPresentParams = [
        'academic_year'  => $emDash['year'],
        'em'             => $activeMeeting,
        'active_session' => '1',
    ];
    if (!empty($user['department']) && in_array($user['role'] ?? '', ['HoD', 'Coordinator', 'Faculty'], true)) {
        $dashPresentParams['department'] = $user['department'];
    } elseif (!empty($data['scope']['department'])) {
        $dashPresentParams['department'] = $data['scope']['department'];
    }
    $dashPresentUrl = url('present-executive-meeting.php') . '?' . http_build_query(array_filter($dashPresentParams));
}
?>
<div class="card mt-4 em-dash">
  <div class="card-body em-dash-body">
    <div class="em-dash-main">
      <div class="em-dash-k"><?= icon('calendar', 13) ?> Current Executive Meeting &middot; <?= e($emDash['year']) ?></div>
      <?php if (!$emDash['configured']): ?>
        <div class="em-dash-v"><?= e(EM_NOT_CONFIGURED_MESSAGE) ?></div>
        <?php if (($user['role'] ?? '') === 'Admin'): ?>
          <a class="em-dash-link" href="<?= e(url('em-schedule.php')) ?>">
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
                [$pillClass, $pillText] = ['locked', 'Closed'];
            } elseif ($key === 'em2' && $emDash['state'] === EM_STATE_EM2_ENDED) {
                [$pillClass, $pillText] = ['locked', 'Closed'];
            } else {
                [$pillClass, $pillText] = ['newer', 'Upcoming'];
            }
          ?>
          <div class="em-dash-meeting">
            <div class="em-dash-name"><?= e($name) ?> <span class="ay-pill <?= $pillClass ?>"><?= e($pillText) ?></span></div>
            <div class="em-dash-dates"><?= e($emDashFmt($sch[$key . '_start'])) ?> – <?= e($emDashFmt($sch[$key . '_end'])) ?></div>
            <?php if ($key === 'em1' && $emDash['em1_locked']): ?>
              <div style="font-size:11px; color:#B91C1C; margin-top:2px;">EM1 Ended: <?= e($emDashFmt($sch['em1_end'])) ?></div>
            <?php elseif ($key === 'em2' && $emDash['em2_active']): ?>
              <div style="font-size:11px; color:#047857; margin-top:2px;">EM2 Started: <?= e($emDashFmt($sch['em2_start'])) ?></div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>

      <?php if ($canPresent): ?>
        <div class="em-dash-actions">
          <a class="btn btn-primary em-dash-present-btn" id="emDashboardPresentBtn"
             href="<?= e($dashPresentUrl) ?>"
             onclick="return openEmDashboardPresentation(this.href, event);"
             title="Present <?= $activeMeeting === 'em1' ? 'Executive Meeting 1' : 'Executive Meeting 2' ?> Full Screen">
            <?= icon('presentation', 16) ?> Present
          </a>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<?php if ($canPresent): ?>
<!-- EM-SPEC-05: Full-screen interactive presentation modal over the dashboard -->
<dialog id="emDashboardPresentDlg" class="em-present-modal" aria-label="Executive Meeting presentation">
  <iframe id="emDashboardPresentFrame" title="Executive Meeting presentation" allowfullscreen allow="fullscreen"></iframe>
</dialog>

<script>
  function openEmDashboardPresentation(url, evt) {
    var dlg = document.getElementById('emDashboardPresentDlg');
    var frame = document.getElementById('emDashboardPresentFrame');
    if (!dlg || !frame || typeof dlg.showModal !== 'function') {
      return true; // Fallback to normal navigation
    }
    if (evt) evt.preventDefault();
    var embedUrl = url + (url.indexOf('?') === -1 ? '?' : '&') + 'embed=1';
    frame.src = embedUrl;
    dlg.showModal();
    frame.onload = function() {
      try { frame.contentWindow.focus(); } catch (err) {}
    };
    return false;
  }

  function closeEmDashboardPresentation() {
    var dlg = document.getElementById('emDashboardPresentDlg');
    var frame = document.getElementById('emDashboardPresentFrame');
    if (dlg) {
      if (typeof dlg.close === 'function') {
        dlg.close();
      } else {
        dlg.removeAttribute('open');
      }
    }
    if (frame) {
      frame.removeAttribute('src');
    }
  }

  // Handle close requested by the deck inside the iframe (Exit button or Esc)
  window.addEventListener('message', function (e) {
    if (e.origin !== window.location.origin) return;
    if (e.data && e.data.atts === 'em-present-close') {
      closeEmDashboardPresentation();
    }
  });

  // Forward parent window keyboard navigation to dashboard presentation iframe while modal is open
  window.addEventListener('keydown', function (e) {
    var dlg = document.getElementById('emDashboardPresentDlg');
    var frame = document.getElementById('emDashboardPresentFrame');
    if (!dlg || !dlg.open || !frame || !frame.contentWindow) return;

    var tag = (document.activeElement || {}).tagName || '';
    if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || (document.activeElement || {}).isContentEditable) {
      return;
    }

    if (e.key === 'ArrowRight' || e.key === 'PageDown' || e.key === ' ') {
      e.preventDefault();
      try { frame.contentWindow.postMessage({ atts: 'em-nav', key: 'ArrowRight' }, window.location.origin); } catch (err) {}
    } else if (e.key === 'ArrowLeft' || e.key === 'PageUp') {
      e.preventDefault();
      try { frame.contentWindow.postMessage({ atts: 'em-nav', key: 'ArrowLeft' }, window.location.origin); } catch (err) {}
    } else if (e.key === 'Escape') {
      e.preventDefault();
      closeEmDashboardPresentation();
    }
  });

  // Handle Esc closed by <dialog> itself
  document.addEventListener('DOMContentLoaded', function () {
    var dlg = document.getElementById('emDashboardPresentDlg');
    if (dlg) {
      dlg.addEventListener('close', function () {
        var frame = document.getElementById('emDashboardPresentFrame');
        if (frame) frame.removeAttribute('src');
      });
    }
  });
</script>
<?php endif; ?>

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
  .em-dash-actions { display:flex; align-items:center; flex-shrink:0; }
  .em-dash-present-btn { display:inline-flex; align-items:center; gap:8px; font-weight:700; letter-spacing:.02em; padding:9px 18px; border-radius:var(--r-md, 8px); box-shadow:var(--shadow-brand); }
</style>
