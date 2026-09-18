<?php
/**
 * FEAT-06 — Executive Meeting Report, full-screen presentation mode.
 *
 * The visual layer only: the slides come from em_slides() over the same
 * filtered dataset executive-meeting-report.php previews, so the deck can never
 * show something the report page did not. Filters arrive on the query string
 * and are re-validated here against this user's own scope — the URL is never
 * trusted.
 *
 * Auto mode advances every 10 seconds (EM_AUTO_ADVANCE_MS); manual mode stops
 * the timer entirely. One timer handle exists, and it is always cleared before
 * another is started.
 */

require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/icons.php';
require_once __DIR__ . '/models/ExecutiveMeetingReport.php';

if (!defined('REPORT_INSTITUTION')) {
    define('REPORT_INSTITUTION', 'Mohamed Sathak Engineering College');
}

/** Auto mode dwell time per slide. The requirement is 10 seconds, not 5. */
if (!defined('EM_AUTO_ADVANCE_MS')) {
    define('EM_AUTO_ADVANCE_MS', 10000);
}

$user = require_login();

// Re-validated server-side: a hand-edited query string cannot widen the scope.
$filters = em_resolve_filters($user, [
    'department'     => input('department'),
    'academic_year'  => input('academic_year'),
    'faculty_id'     => input('faculty_id'),
    'student_reg'    => input('student_reg'),
    'meeting_number' => input('meeting_number'),
    'em'             => input('em'),   // FEAT-07 EM1 / EM2 / All
]);

$dataset = em_dataset($user, $filters);
$slides  = em_slides($dataset);

$slidesJson = json_encode($slides, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
$exitUrl    = url('executive-meeting-report.php') . '?' . http_build_query(em_filter_query($filters));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Executive Meeting Report — ATTS IQAC</title>
  <style>
    :root {
      --navy: #131D3B;
      --navy-light: #1E2B52;
      --navy-50: #F4F6FA;
      --brand: #FF4F01;
      --brand-hover: #E04400;
      --ink: #131D3B;
      --muted: #5A6785;
      --hairline: #E4E9F2;
      --surface: #FFFFFF;
      --success: #10B981;
      --warning: #F59E0B;
      --danger: #EF4444;
    }

    * { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
      background: #0D1427;
      color: #F8FAFC;
      height: 100vh;
      display: flex;
      flex-direction: column;
      overflow: hidden;
      user-select: none;
    }

    /* ---- Top bar ---- */
    .hdr {
      background: var(--navy);
      border-bottom: 1px solid rgba(255,255,255,0.1);
      padding: 12px 24px;
      display: flex; align-items: center; justify-content: space-between;
      height: 60px; flex-shrink: 0; z-index: 100;
      transition: opacity .3s ease;
    }
    .hdr-brand { display: flex; align-items: center; gap: 12px; }
    .hdr-logo { background: var(--brand); color: #fff; font-weight: 800; font-size: 14px;
      padding: 4px 8px; border-radius: 6px; letter-spacing: .05em; }
    .hdr-title { font-size: 15px; font-weight: 700; color: #fff; }
    .hdr-sub { font-size: 12px; color: #94A3B8; }
    .hdr-actions { display: flex; align-items: center; gap: 10px; }

    .btn-hdr {
      background: rgba(255,255,255,0.1); color: #F8FAFC;
      border: 1px solid rgba(255,255,255,0.15);
      padding: 6px 14px; border-radius: 999px;
      font-size: 12.5px; font-weight: 600; cursor: pointer;
      display: inline-flex; align-items: center; gap: 6px;
      text-decoration: none; transition: all .2s ease; font-family: inherit;
    }
    .btn-hdr:hover { background: rgba(255,255,255,0.2); color: #fff; }
    .btn-hdr-brand { background: var(--brand); border-color: var(--brand); }
    .btn-hdr-brand:hover { background: var(--brand-hover); }

    /* Mode switch — always shows which mode is live. */
    .mode-switch { display: inline-flex; background: rgba(255,255,255,0.08);
      border: 1px solid rgba(255,255,255,0.15); border-radius: 999px; padding: 3px; gap: 2px; }
    .mode-btn { background: none; border: 0; color: #94A3B8; font-family: inherit;
      font-size: 12.5px; font-weight: 700; padding: 5px 14px; border-radius: 999px;
      cursor: pointer; display: inline-flex; align-items: center; gap: 6px; transition: all .2s ease; }
    .mode-btn.active { background: var(--brand); color: #fff; }
    .mode-btn:not(.active):hover { color: #fff; }

    /* ---- Viewport ---- */
    .viewport { flex: 1; display: flex; align-items: center; justify-content: center;
      padding: 24px; position: relative; overflow: hidden; }
    .slide-card {
      background: var(--surface); color: var(--ink);
      width: 100%; max-width: 1100px; height: 100%; max-height: 640px;
      border-radius: 16px; box-shadow: 0 20px 50px rgba(0,0,0,0.5);
      display: flex; flex-direction: column; position: relative; overflow: hidden;
      opacity: 0; transform: translateY(10px) scale(0.99);
      transition: opacity .3s ease, transform .3s ease;
    }
    .slide-card.active { opacity: 1; transform: translateY(0) scale(1); }
    .slide-body { padding: 32px 40px; flex: 1; overflow-y: auto; display: flex; flex-direction: column; }

    /* Auto-mode countdown, so the 10 seconds are visible. */
    .auto-bar { position: absolute; top: 0; left: 0; height: 4px; width: 0;
      background: var(--brand); z-index: 5; }
    .auto-bar.running { animation: emCountdown linear forwards; }
    @keyframes emCountdown { from { width: 0; } to { width: 100%; } }

    /* ---- Slide content ---- */
    .intro-badge { display: inline-flex; align-items: center; gap: 7px; background: rgba(255,79,1,0.1);
      color: var(--brand); border: 1px solid rgba(255,79,1,0.25); padding: 6px 14px;
      border-radius: 999px; font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; }
    .intro-title { font-size: 40px; font-weight: 800; letter-spacing: -0.02em; margin-top: 18px; line-height: 1.1; }
    .intro-sub { font-size: 16px; color: var(--muted); margin-top: 10px; font-weight: 500; }

    .slide-hdr-bar { display: flex; align-items: center; justify-content: space-between;
      border-bottom: 2px solid var(--hairline); padding-bottom: 14px; margin-bottom: 20px; gap: 16px; }
    .slide-h { font-size: 24px; font-weight: 800; letter-spacing: -0.015em; }
    .slide-meta { font-size: 12.5px; color: var(--muted); font-weight: 600; text-align: right; white-space: nowrap; }

    .kpi-row { display: grid; grid-template-columns: repeat(4, 1fr); gap: 14px; }
    .kpi-box { background: var(--navy-50); border: 1px solid var(--hairline);
      border-radius: 12px; padding: 16px 18px; }
    .kpi-box.brand { background: rgba(255,79,1,0.07); border-color: rgba(255,79,1,0.25); }
    .kpi-box-lbl { font-size: 11px; font-weight: 700; color: var(--muted);
      text-transform: uppercase; letter-spacing: .04em; }
    .kpi-box-val { font-size: 28px; font-weight: 800; margin-top: 6px; letter-spacing: -0.02em; }
    .kpi-box.brand .kpi-box-val { color: var(--brand); }

    table.sl { width: 100%; border-collapse: collapse; font-size: 13.5px; }
    table.sl thead th { text-align: left; background: var(--navy-50); color: var(--muted);
      font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em;
      padding: 10px 12px; border-bottom: 1px solid var(--hairline); }
    table.sl tbody td { padding: 11px 12px; border-bottom: 1px solid var(--hairline); vertical-align: top; }
    table.sl tbody tr:last-child td { border-bottom: 0; }
    .t-title { font-weight: 600; color: var(--ink); }
    .t-sub { font-size: 11.5px; color: var(--muted); margin-top: 2px; }
    .num { text-align: right; font-variant-numeric: tabular-nums; }

    .pill { display: inline-block; padding: 3px 9px; border-radius: 999px;
      font-size: 11px; font-weight: 700; background: var(--navy-50); color: var(--muted); }
    .pill-ok { background: rgba(16,185,129,0.12); color: #047857; }
    .pill-warn { background: rgba(245,158,11,0.14); color: #B45309; }
    .pill-bad { background: rgba(239,68,68,0.12); color: #B91C1C; }

    .chips { display: flex; flex-wrap: wrap; gap: 8px; }
    .chip { background: var(--navy-50); border: 1px solid var(--hairline); border-radius: 999px;
      padding: 5px 12px; font-size: 12px; font-weight: 600; }
    .chip b { color: var(--muted); font-weight: 700; text-transform: uppercase; font-size: 10.5px;
      letter-spacing: .03em; margin-right: 5px; }

    .bar-track { height: 10px; background: var(--navy-50); border-radius: 999px;
      overflow: hidden; border: 1px solid var(--hairline); }
    .bar-fill { height: 100%; background: var(--brand); border-radius: 999px; }

    .empty-wrap { flex: 1; display: flex; flex-direction: column; align-items: center;
      justify-content: center; text-align: center; color: var(--muted); gap: 10px; }
    .empty-ic { width: 54px; height: 54px; border-radius: 50%; background: var(--navy-50);
      display: grid; place-items: center; color: var(--muted); }

    /* ---- Bottom control bar ---- */
    .bbar {
      background: var(--navy); border-top: 1px solid rgba(255,255,255,0.1);
      padding: 10px 24px; display: flex; align-items: center; justify-content: space-between;
      height: 58px; flex-shrink: 0; gap: 16px; transition: opacity .3s ease;
    }
    .bbar-ctrl { display: flex; align-items: center; gap: 8px; }
    .btn-nav { background: rgba(255,255,255,0.1); color: #F8FAFC; border: 1px solid rgba(255,255,255,0.15);
      padding: 7px 15px; border-radius: 8px; font-size: 13px; font-weight: 600; cursor: pointer;
      display: inline-flex; align-items: center; gap: 6px; font-family: inherit; transition: all .2s ease; }
    .btn-nav:hover:not(:disabled) { background: rgba(255,255,255,0.2); }
    .btn-nav:disabled { opacity: .35; cursor: not-allowed; }
    .dots { display: flex; gap: 6px; align-items: center; flex-wrap: wrap;
      justify-content: center; max-width: 50%; }
    .dot { width: 8px; height: 8px; border-radius: 50%; background: rgba(255,255,255,0.25);
      cursor: pointer; transition: all .2s ease; }
    .dot.active { background: var(--brand); width: 20px; border-radius: 999px; }
    .slide-counter { font-size: 13px; font-weight: 700; color: #94A3B8; white-space: nowrap; }
    .slide-counter b { color: #fff; }

    /* Controls fade while presenting; any mouse move brings them back. */
    body.idle .hdr, body.idle .bbar { opacity: 0.12; }

    @media (max-width: 860px) {
      .kpi-row { grid-template-columns: repeat(2, 1fr); }
      .intro-title { font-size: 30px; }
      .hdr-sub, .dots { display: none; }
      .slide-body { padding: 22px 20px; }
    }
  </style>
</head>
<body>

  <header class="hdr" id="hdr">
    <div class="hdr-brand">
      <div class="hdr-logo">ATTS</div>
      <div>
        <div class="hdr-title"><?= e(REPORT_INSTITUTION) ?></div>
        <div class="hdr-sub">Executive Meeting Report &middot; Presentation Mode</div>
      </div>
    </div>

    <div class="hdr-actions">
      <!-- Auto / Manual. The active mode is always visible. -->
      <div class="mode-switch" role="group" aria-label="Presentation mode">
        <button type="button" class="mode-btn" id="btnAuto" onclick="setMode('auto')" title="Advance automatically every 10 seconds">
          <?= icon('play-circle', 14) ?> Auto Mode
        </button>
        <button type="button" class="mode-btn active" id="btnManual" onclick="setMode('manual')" title="Advance only when you choose">
          <?= icon('grip', 14) ?> Manual Mode
        </button>
      </div>

      <button type="button" class="btn-hdr" onclick="toggleFullscreen()" title="Toggle full screen">
        <?= icon('maximize', 14) ?> <span id="fsText">Fullscreen</span>
      </button>

      <a href="<?= e($exitUrl) ?>" class="btn-hdr btn-hdr-brand" title="Exit presentation (Esc)">
        <?= icon('x', 14) ?> Exit
      </a>
    </div>
  </header>

  <main class="viewport">
    <div class="slide-card" id="slideCard">
      <div class="auto-bar" id="autoBar"></div>
      <div class="slide-body" id="slideContent"></div>
    </div>
  </main>

  <footer class="bbar" id="bbar">
    <div class="bbar-ctrl">
      <button type="button" class="btn-nav" id="btnPrev" onclick="prevSlide(true)">
        <?= icon('arrow-left', 15) ?> Previous
      </button>
      <button type="button" class="btn-nav" id="btnNext" onclick="nextSlide(true)">
        Next <?= icon('arrow-right', 15) ?>
      </button>
    </div>

    <div class="dots" id="dotsContainer"></div>

    <div class="slide-counter" id="slideCounter">Slide <b>1</b> of 1</div>
  </footer>

  <script>
    const slides = <?= $slidesJson ?>;

    /* Auto mode dwell: 10 seconds per slide, per the FEAT-06 requirement. */
    const AUTO_ADVANCE_MS = <?= EM_AUTO_ADVANCE_MS ?>;

    /* Rows per table slide, so continued pages keep numbering in sequence. */
    const SLIDE_ROWS = <?= EM_SLIDE_ROWS ?>;

    let currentIndex = 0;
    let mode = 'manual';
    let autoTimer = null;        // the ONLY timer handle; never more than one

    /* ---- Timer control -------------------------------------------------- */

    function clearAutoTimer() {
      if (autoTimer !== null) {
        clearTimeout(autoTimer);
        autoTimer = null;
      }
      const bar = document.getElementById('autoBar');
      bar.classList.remove('running');
      bar.style.animationDuration = '';
      bar.style.width = '0';
    }

    /* Always clears first, so switching modes or slides can never leave two
       timers running against each other. */
    function startAutoTimer() {
      clearAutoTimer();
      if (mode !== 'auto') return;
      if (currentIndex >= slides.length - 1) return;   // stop on the final slide

      const bar = document.getElementById('autoBar');
      bar.style.animationDuration = AUTO_ADVANCE_MS + 'ms';
      void bar.offsetWidth;                            // restart the animation
      bar.classList.add('running');

      autoTimer = setTimeout(() => {
        autoTimer = null;
        nextSlide(false);
      }, AUTO_ADVANCE_MS);
    }

    function setMode(next) {
      mode = (next === 'auto') ? 'auto' : 'manual';
      document.getElementById('btnAuto').classList.toggle('active', mode === 'auto');
      document.getElementById('btnManual').classList.toggle('active', mode === 'manual');

      if (mode === 'auto') {
        startAutoTimer();        // resumes from whichever slide is showing
      } else {
        clearAutoTimer();        // manual stops the clock immediately
      }
    }

    /* ---- Navigation ----------------------------------------------------- */

    function renderSlide(index, userDriven) {
      if (index < 0 || index >= slides.length) return;
      currentIndex = index;

      clearAutoTimer();          // never advance off a slide being replaced

      const card = document.getElementById('slideCard');
      const content = document.getElementById('slideContent');
      card.classList.remove('active');

      setTimeout(() => {
        content.innerHTML = buildSlide(slides[currentIndex]);
        content.scrollTop = 0;
        card.classList.add('active');

        document.getElementById('slideCounter').innerHTML =
          'Slide <b>' + (currentIndex + 1) + '</b> of ' + slides.length;
        document.getElementById('btnPrev').disabled = currentIndex === 0;
        document.getElementById('btnNext').disabled = currentIndex === slides.length - 1;

        const dots = document.getElementById('dotsContainer');
        dots.innerHTML = '';
        slides.forEach((_, i) => {
          const dot = document.createElement('div');
          dot.className = 'dot' + (i === currentIndex ? ' active' : '');
          dot.title = 'Slide ' + (i + 1);
          dot.onclick = () => renderSlide(i, true);
          dots.appendChild(dot);
        });

        startAutoTimer();        // a no-op unless auto mode is on
      }, 120);
    }

    function prevSlide() { if (currentIndex > 0) renderSlide(currentIndex - 1, true); }
    function nextSlide() { if (currentIndex < slides.length - 1) renderSlide(currentIndex + 1, true); }
    function firstSlide() { renderSlide(0, true); }
    function lastSlide() { renderSlide(slides.length - 1, true); }

    function exitPresentation() {
      clearAutoTimer();
      if (document.fullscreenElement && document.exitFullscreen) {
        document.exitFullscreen().catch(() => {});
      }
      location.href = '<?= e($exitUrl) ?>';
    }

    /* ---- Keyboard ------------------------------------------------------- */

    document.addEventListener('keydown', (e) => {
      switch (e.key) {
        case 'ArrowRight':
        case ' ':
        case 'PageDown':  e.preventDefault(); nextSlide(); break;
        case 'ArrowLeft':
        case 'PageUp':    e.preventDefault(); prevSlide(); break;
        case 'Home':      e.preventDefault(); firstSlide(); break;
        case 'End':       e.preventDefault(); lastSlide(); break;
        case 'Escape':    exitPresentation(); break;
        case 'a': case 'A': setMode(mode === 'auto' ? 'manual' : 'auto'); break;
      }
    });

    /* ---- Fullscreen (API where available, full viewport regardless) ------ */

    function toggleFullscreen() {
      const label = document.getElementById('fsText');
      if (!document.fullscreenElement) {
        const el = document.documentElement;
        const req = el.requestFullscreen || el.webkitRequestFullscreen || el.msRequestFullscreen;
        if (req) {
          req.call(el).then(() => { label.textContent = 'Exit Fullscreen'; }).catch(() => {});
        }
      } else if (document.exitFullscreen) {
        document.exitFullscreen().then(() => { label.textContent = 'Fullscreen'; }).catch(() => {});
      }
    }
    document.addEventListener('fullscreenchange', () => {
      document.getElementById('fsText').textContent =
        document.fullscreenElement ? 'Exit Fullscreen' : 'Fullscreen';
    });

    /* ---- Let the chrome fade while presenting ---------------------------- */

    let idleTimer = null;
    function wake() {
      document.body.classList.remove('idle');
      clearTimeout(idleTimer);
      idleTimer = setTimeout(() => document.body.classList.add('idle'), 3500);
    }
    ['mousemove', 'keydown', 'click', 'touchstart'].forEach(ev =>
      document.addEventListener(ev, wake, { passive: true }));

    /* ---- Rendering ------------------------------------------------------- */

    function esc(str) {
      return String(str === null || str === undefined ? '' : str)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    }

    function chipsOf(summary) {
      return Object.keys(summary)
        .map(k => `<span class="chip"><b>${esc(k)}</b>${esc(summary[k])}</span>`).join('');
    }

    function slideHeader(slide, right) {
      return `
        <div class="slide-hdr-bar">
          <div class="slide-h">${esc(slide.title)}</div>
          <div class="slide-meta">${right || ''}</div>
        </div>`;
    }

    function pctPill(pct) {
      if (pct === null || pct === undefined) return '<span class="pill">No target</span>';
      const cls = pct >= 100 ? 'pill-ok' : (pct >= 50 ? 'pill-warn' : 'pill-bad');
      return `<span class="pill ${cls}">${esc(pct)}%</span>`;
    }

    function buildSlide(s) {
      const scope = `${esc(s.summary['Department'])} &middot; ${esc(s.summary['Academic Year'])}`;

      if (s.type === 'title') {
        return `
          <div style="flex:1; display:flex; flex-direction:column; justify-content:center;">
            <div><span class="intro-badge">Executive Meeting Report</span></div>
            <h1 class="intro-title">${esc(s.title)}</h1>
            <div class="intro-sub">${esc(s.summary['Executive Meeting'])} &middot; ${scope}</div>
            <div style="margin-top:26px;" class="chips">${chipsOf(s.summary)}</div>
            <div class="kpi-row" style="margin-top:26px;">
              <div class="kpi-box brand"><div class="kpi-box-lbl">Records in Scope</div><div class="kpi-box-val">${esc(s.totals.records)}</div></div>
              <div class="kpi-box"><div class="kpi-box-lbl">Faculty</div><div class="kpi-box-val">${esc(s.totals.faculty)}</div></div>
              <div class="kpi-box"><div class="kpi-box-lbl">Student</div><div class="kpi-box-val">${esc(s.totals.student)}</div></div>
              <div class="kpi-box"><div class="kpi-box-lbl">Targets</div><div class="kpi-box-val">${esc(s.totals.targets)}</div></div>
            </div>
          </div>`;
      }

      if (s.type === 'college') {
        const types = Object.keys(s.by_type);
        const rows = types.length
          ? types.map(k => `
              <tr><td class="t-title">${esc(k)}</td><td class="num">${esc(s.by_type[k])}</td></tr>`).join('')
          : '<tr><td colspan="2" style="color:var(--muted);">No records in this scope.</td></tr>';

        const r = s.rollup;
        const pctWidth = (r.percentage === null ? 0 : Math.min(r.percentage, 100)) + '%';

        return slideHeader(s, scope) + `
          <div class="kpi-row">
            <div class="kpi-box brand"><div class="kpi-box-lbl">Total Records</div><div class="kpi-box-val">${esc(s.totals.records)}</div></div>
            <div class="kpi-box">
              <div class="kpi-box-lbl">${s.summary['Department'] && s.summary['Department'] !== 'All Departments' ? 'Department' : 'Departments'}</div>
              <div class="kpi-box-val" style="${s.summary['Department'] && s.summary['Department'] !== 'All Departments' ? 'font-size:15px; line-height:1.2; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;' : ''}" title="${esc(s.summary['Department'] && s.summary['Department'] !== 'All Departments' ? s.summary['Department'] : s.departments.length)}">
                ${esc(s.summary['Department'] && s.summary['Department'] !== 'All Departments' ? s.summary['Department'] : s.departments.length)}
              </div>
            </div>
            <div class="kpi-box"><div class="kpi-box-lbl">Activities</div><div class="kpi-box-val">${esc(s.totals.activity)}</div></div>
            <div class="kpi-box"><div class="kpi-box-lbl">Meetings</div><div class="kpi-box-val">${esc(s.totals.meetings)}</div></div>
          </div>
          <div style="margin-top:20px; display:grid; grid-template-columns: 1.3fr 1fr; gap:22px; flex:1; min-height:0;">
            <div style="min-height:0; overflow:auto;">
              <table class="sl">
                <thead><tr><th>Record Type</th><th class="num">Count</th></tr></thead>
                <tbody>${rows}</tbody>
              </table>
            </div>
            <div>
              <div class="kpi-box-lbl">Overall Target Achievement</div>
              <div style="font-size:34px; font-weight:800; margin:6px 0 10px; color:var(--brand);">
                ${r.percentage === null ? '&mdash;' : esc(r.percentage) + '%'}
              </div>
              <div class="bar-track"><div class="bar-fill" style="width:${pctWidth}"></div></div>
              <div class="t-sub" style="margin-top:10px;">
                Achieved ${esc(r.achieved)} of ${esc(r.target)} across ${esc(r.count)} target${r.count === 1 ? '' : 's'}
              </div>
              <div style="margin-top:16px;" class="chips">
                <span class="chip"><b>Faculty</b>${esc(s.totals.faculty)}</span>
                <span class="chip"><b>Student</b>${esc(s.totals.student)}</span>
              </div>
            </div>
          </div>`;
      }

      if (s.type === 'records') {
        const isStudent = s.kind === 'student';
        const pageInfo = s.total_pages > 1
          ? `Page ${esc(s.page)} of ${esc(s.total_pages)} &middot; ${esc(s.total_rows)} total`
          : `${esc(s.total_rows)} record${s.total_rows === 1 ? '' : 's'}`;

        const rows = s.rows.map((r, i) => `
          <tr>
            <td class="num" style="color:var(--muted); width:38px;">${(s.page - 1) * SLIDE_ROWS + i + 1}</td>
            <td>
              <div class="t-title">${esc(r.title)}</div>
              ${r.person ? `<div class="t-sub">${isStudent ? 'Student' : 'Faculty'}: ${esc(r.person)}${r.reg_no ? ' &middot; ' + esc(r.reg_no) : ''}</div>` : ''}
            </td>
            <td><span class="pill">${esc(r.type)}</span></td>
            <td class="t-sub">${esc(r.department)}</td>
            <td class="t-sub">${esc(r.date)}</td>
            <td>${r.status ? `<span class="pill">${esc(r.status)}</span>` : ''}</td>
          </tr>`).join('');

        return slideHeader(s, `${scope}<br>${pageInfo}`) + `
          <div style="flex:1; min-height:0; overflow:auto;">
            <table class="sl">
              <thead>
                <tr>
                  <th>#</th>
                  <th>${isStudent ? 'Achievement / Record' : 'Achievement'}</th>
                  <th>Category</th><th>Department</th><th>Date</th><th>Status</th>
                </tr>
              </thead>
              <tbody>${rows}</tbody>
            </table>
          </div>`;
      }

      if (s.type === 'targets') {
        const r = s.rollup;
        const pageInfo = s.total_pages > 1 ? `Page ${esc(s.page)} of ${esc(s.total_pages)}` : '';
        const rows = s.rows.map(t => `
          <tr>
            <td><div class="t-title">${esc(t.metric)}</div><div class="t-sub">${esc(t.department)}</div></td>
            <td class="num">${esc(t.target)}</td>
            <td class="num" style="font-weight:700;">${t.unlinked ? '&mdash;' : esc(t.achieved)}</td>
            <td class="num">${t.unlinked ? '&mdash;' : esc(t.difference)}</td>
            <td class="num">${t.unlinked ? '<span class="pill">Not linked</span>' : pctPill(t.percentage)}</td>
          </tr>`).join('');

        return slideHeader(s, `${scope}${pageInfo ? '<br>' + pageInfo : ''}`) + `
          <div class="kpi-row" style="grid-template-columns: repeat(4, 1fr);">
            <div class="kpi-box"><div class="kpi-box-lbl">Total Target</div><div class="kpi-box-val">${esc(r.target)}</div></div>
            <div class="kpi-box brand"><div class="kpi-box-lbl">Achieved</div><div class="kpi-box-val">${esc(r.achieved)}</div></div>
            <div class="kpi-box"><div class="kpi-box-lbl">Difference</div><div class="kpi-box-val">${esc(r.remaining)}</div></div>
            <div class="kpi-box"><div class="kpi-box-lbl">Achievement %</div><div class="kpi-box-val">${r.percentage === null ? '&mdash;' : esc(r.percentage) + '%'}</div></div>
          </div>
          <div style="flex:1; min-height:0; overflow:auto; margin-top:18px;">
            <table class="sl">
              <thead>
                <tr><th>Target / Metric</th><th class="num">Target</th><th class="num">Achieved</th>
                    <th class="num">Difference</th><th class="num">%</th></tr>
              </thead>
              <tbody>${rows}</tbody>
            </table>
          </div>`;
      }

      if (s.type === 'meetings') {
        const rows = s.rows.map(m => `
          <tr>
            <td class="t-title">Meeting #${esc(m.number)}</td>
            <td class="t-sub">${esc(m.date)}</td>
            <td>${m.status ? `<span class="pill pill-ok">${esc(m.status)}</span>` : ''}</td>
            <td class="t-sub">${esc(m.admin)}</td>
            <td class="t-sub">${esc(m.notes)}</td>
          </tr>`).join('');

        return slideHeader(s, scope) + `
          <div style="flex:1; min-height:0; overflow:auto;">
            <table class="sl">
              <thead><tr><th>Meeting</th><th>Date</th><th>Status</th><th>Recorded By</th><th>Minutes / Remarks</th></tr></thead>
              <tbody>${rows}</tbody>
            </table>
          </div>`;
      }

      if (s.type === 'closing') {
        const r = s.rollup;
        return slideHeader(s, scope) + `
          <div style="flex:1; display:flex; flex-direction:column; justify-content:center;">
            <div class="kpi-row">
              <div class="kpi-box brand"><div class="kpi-box-lbl">Total Records</div><div class="kpi-box-val">${esc(s.totals.records)}</div></div>
              <div class="kpi-box"><div class="kpi-box-lbl">Faculty</div><div class="kpi-box-val">${esc(s.totals.faculty)}</div></div>
              <div class="kpi-box"><div class="kpi-box-lbl">Activities</div><div class="kpi-box-val">${esc(s.totals.activity)}</div></div>
              <div class="kpi-box"><div class="kpi-box-lbl">Student</div><div class="kpi-box-val">${esc(s.totals.student)}</div></div>
            </div>
            <div style="margin-top:24px;">
              <div class="kpi-box-lbl">Target vs Achieved</div>
              <div style="font-size:30px; font-weight:800; margin:6px 0 10px;">
                ${esc(r.achieved)} <span style="color:var(--muted); font-size:20px;">of ${esc(r.target)}</span>
                ${r.percentage === null ? '' : ` <span style="color:var(--brand);">(${esc(r.percentage)}%)</span>`}
              </div>
              <div class="bar-track"><div class="bar-fill" style="width:${(r.percentage === null ? 0 : Math.min(r.percentage, 100))}%"></div></div>
            </div>
            <div style="margin-top:24px;" class="chips">${chipsOf(s.summary)}</div>
          </div>`;
      }

      // 'empty' — a stated reason, never a blank slide.
      return slideHeader(s, scope) + `
        <div class="empty-wrap">
          <div class="empty-ic"><?= icon('info', 22) ?></div>
          <div style="font-size:17px; font-weight:700; color:var(--ink);">${esc(s.message)}</div>
          <div style="font-size:13px;">Adjust the report filters to widen this section.</div>
        </div>`;
    }

    renderSlide(0, false);
    wake();
  </script>
</body>
</html>
