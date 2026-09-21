<?php
/**
 * Student Achievement Presentation Mode — Fullscreen Academic Review Presentation Engine.
 * Allows Admin, Principal, Director, Dean, HoD, Coordinator, and Faculty to present a student's achievements in a clean slide format.
 * Strictly enforced backend authorization via can_user_view_student_report().
 */

require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/icons.php';
require_once __DIR__ . '/inc/report_layout.php';
require_once __DIR__ . '/models/StudentAchievement.php';

if (!defined('REPORT_INSTITUTION')) {
    define('REPORT_INSTITUTION', 'Mohamed Sathak Engineering College');
}

$user = require_login();

// Read query parameters
$studentKey   = trim((string) input('key', ''));
$regNoHint    = trim((string) input('reg_no', ''));
$nameHint     = trim((string) input('name', ''));
$deptHint     = trim((string) input('dept', ''));
$academicYear = trim((string) input('academic_year', '')) ?: active_academic_year();

// If key is empty, compute key
if ($studentKey === '') {
    if ($regNoHint !== '' && $regNoHint !== '—') {
        $studentKey = student_make_key($regNoHint, $nameHint, $deptHint);
    } elseif ($nameHint !== '') {
        $studentKey = student_make_key(null, $nameHint, $deptHint);
    }
}

if ($studentKey === '') {
    http_response_code(400);
    echo "<h1>400 Bad Request</h1><p>Student identifier not specified.</p>";
    exit;
}

$presentation = student_achievement_presentation_data($studentKey, $academicYear, $regNoHint, $nameHint, $deptHint);
$student      = $presentation['student'];

if (!$student) {
    http_response_code(404);
    echo "<h1>404 Not Found</h1><p>Student record not found.</p>";
    exit;
}

// Strict backend role authorization gate
if (!can_user_view_student_report($user, $student['department'])) {
    http_response_code(403);
    require __DIR__ . '/denied.php';
    exit;
}

// Build slides array if not already formatted
$slides = $presentation['slides'];

// Ensure we also have an overall summary slide if there are achievements
if (!empty($presentation['summary']['total']) && count($slides) > 1) {
    $overallSlide = [
        'type'         => 'overall_summary',
        'student'      => $student,
        'academic_year'=> $academicYear,
        'summary'      => $presentation['summary'],
    ];
    // Insert overall summary right after title slide
    array_splice($slides, 1, 0, [$overallSlide]);
}

$slidesJson = json_encode($slides, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
$years      = academic_years();
$exitUrl    = url('individual-student-report.php') . '?' . http_build_query([
    'key'           => $student['key'],
    'reg_no'        => $student['reg_no'],
    'name'          => $student['name'],
    'dept'          => $student['department'],
    'academic_year' => $academicYear,
]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Presentation: <?= e($student['name']) ?> — ATTS IQAC</title>
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
      --radius: 12px;
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

    /* Top Control Header Bar */
    .hdr {
      background: var(--navy);
      border-bottom: 1px solid rgba(255,255,255,0.1);
      padding: 12px 24px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      height: 60px;
      flex-shrink: 0;
      z-index: 100;
    }
    .hdr-brand {
      display: flex;
      align-items: center;
      gap: 12px;
    }
    .hdr-logo {
      background: var(--brand);
      color: #fff;
      font-weight: 800;
      font-size: 14px;
      padding: 4px 8px;
      border-radius: 6px;
      letter-spacing: .05em;
    }
    .hdr-title {
      font-size: 15px;
      font-weight: 700;
      color: #fff;
    }
    .hdr-sub {
      font-size: 12px;
      color: #94A3B8;
    }

    .hdr-actions {
      display: flex;
      align-items: center;
      gap: 12px;
    }
    .btn-hdr {
      background: rgba(255,255,255,0.1);
      color: #F8FAFC;
      border: 1px solid rgba(255,255,255,0.15);
      padding: 6px 14px;
      border-radius: 999px;
      font-size: 12.5px;
      font-weight: 600;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 6px;
      text-decoration: none;
      transition: all .2s ease;
    }
    .btn-hdr:hover {
      background: rgba(255,255,255,0.2);
      color: #fff;
    }
    .btn-hdr-brand {
      background: var(--brand);
      border-color: var(--brand);
    }
    .btn-hdr-brand:hover {
      background: var(--brand-hover);
    }

    /* Main Presentation Viewport Container */
    .viewport {
      flex: 1;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 24px;
      position: relative;
      overflow: hidden;
    }

    .slide-card {
      background: var(--surface);
      color: var(--ink);
      width: 100%;
      max-width: 1100px;
      height: 100%;
      max-height: 640px;
      border-radius: 16px;
      box-shadow: 0 20px 50px rgba(0,0,0,0.5);
      display: flex;
      flex-direction: column;
      position: relative;
      overflow: hidden;
      opacity: 0;
      transform: translateY(10px) scale(0.99);
      transition: opacity .3s ease, transform .3s ease;
    }
    .slide-card.active {
      opacity: 1;
      transform: translateY(0) scale(1);
    }

    .slide-body {
      padding: 36px 44px;
      flex: 1;
      overflow-y: auto;
      display: flex;
      flex-direction: column;
    }

    /* Typography & Visual Helpers */
    .intro-badge {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      background: #FFF3EC;
      color: var(--brand);
      font-size: 12.5px;
      font-weight: 700;
      padding: 6px 14px;
      border-radius: 999px;
      border: 1px solid rgba(255,79,1,0.2);
      text-transform: uppercase;
      letter-spacing: .04em;
    }
    .intro-title {
      font-size: 32px;
      font-weight: 800;
      color: var(--navy);
      margin-top: 12px;
      line-height: 1.2;
    }
    .intro-sub {
      font-size: 18px;
      color: var(--muted);
      margin-top: 6px;
      font-weight: 500;
    }

    .slide-hdr-bar {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      margin-bottom: 24px;
      padding-bottom: 16px;
      border-bottom: 2px solid var(--hairline);
    }
    .slide-cat-title {
      font-size: 24px;
      font-weight: 800;
      color: var(--navy);
    }
    .slide-cat-sub {
      font-size: 13.5px;
      color: var(--muted);
      margin-top: 4px;
    }

    .kpi-row {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 16px;
      margin-bottom: 24px;
    }
    .kpi-box {
      background: var(--navy-50);
      border: 1px solid var(--hairline);
      border-radius: 12px;
      padding: 16px 20px;
    }
    .kpi-box.brand {
      background: #FFF3EC;
      border-color: rgba(255,79,1,0.2);
    }
    .kpi-box-lbl {
      font-size: 11.5px;
      font-weight: 700;
      color: var(--muted);
      text-transform: uppercase;
      letter-spacing: .05em;
    }
    .kpi-box.brand .kpi-box-lbl {
      color: var(--brand);
    }
    .kpi-box-val {
      font-size: 28px;
      font-weight: 800;
      color: var(--navy);
      margin-top: 4px;
    }
    .kpi-box.brand .kpi-box-val {
      color: var(--brand);
    }

    .rec-item {
      background: #FFFFFF;
      border: 1px solid var(--hairline);
      border-radius: 10px;
      padding: 14px 18px;
      margin-bottom: 10px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 16px;
      transition: border-color .15s ease;
    }
    .rec-item:hover {
      border-color: #CBD5E1;
    }
    .rec-title {
      font-weight: 600;
      font-size: 14px;
      color: var(--navy);
    }
    .rec-meta {
      font-size: 12px;
      color: var(--muted);
      margin-top: 3px;
    }

    /* Bottom Presentation Navigation Bar */
    .ctrl-bar {
      background: var(--navy);
      border-top: 1px solid rgba(255,255,255,0.1);
      padding: 12px 24px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      height: 64px;
      flex-shrink: 0;
      z-index: 100;
    }
    .slide-counter {
      font-size: 13px;
      font-weight: 600;
      color: #94A3B8;
      font-variant-numeric: tabular-nums;
    }
    .nav-btns {
      display: flex;
      align-items: center;
      gap: 12px;
    }
    .btn-nav {
      background: rgba(255,255,255,0.1);
      color: #F8FAFC;
      border: 1px solid rgba(255,255,255,0.15);
      padding: 8px 18px;
      border-radius: 8px;
      font-size: 13px;
      font-weight: 600;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 6px;
      transition: all .15s ease;
    }
    .btn-nav:hover:not(:disabled) {
      background: rgba(255,255,255,0.2);
    }
    .btn-nav:disabled {
      opacity: 0.35;
      cursor: not-allowed;
    }

    /* Auto-Play Mode Toggles & Countdown */
    .mode-toggle-group {
      display: flex;
      align-items: center;
      background: rgba(255,255,255,0.06);
      border: 1px solid rgba(255,255,255,0.12);
      border-radius: 999px;
      padding: 2px;
      gap: 2px;
    }
    .btn-mode {
      background: transparent;
      color: #94A3B8;
      border: 0;
      padding: 5px 12px;
      border-radius: 999px;
      font-size: 12px;
      font-weight: 600;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 5px;
      transition: all .2s ease;
    }
    .btn-mode.active {
      background: var(--brand);
      color: #ffffff;
    }

    .auto-progress {
      position: absolute;
      top: 0;
      left: 0;
      height: 3px;
      background: var(--brand);
      width: 0%;
      z-index: 10;
    }
    .auto-progress.running {
      animation: autoFill linear forwards;
    }
    @keyframes autoFill {
      from { width: 0%; }
      to { width: 100%; }
    }
  </style>
</head>
<body>

  <!-- Top Bar -->
  <header class="hdr">
    <div class="hdr-brand">
      <div class="hdr-logo">ATTS</div>
      <div>
        <div class="hdr-title"><?= e($student['name']) ?></div>
        <div class="hdr-sub"><?= e($student['department']) ?> &middot; Reg No: <?= e($student['reg_no']) ?></div>
      </div>
    </div>

    <div class="hdr-actions">
      <!-- Mode Toggle -->
      <div class="mode-toggle-group">
        <button type="button" class="btn-mode active" id="btnModeManual" onclick="setPresentationMode('manual')">
          <?= icon('mouse-pointer', 12) ?> Manual
        </button>
        <button type="button" class="btn-mode" id="btnModeAuto" onclick="setPresentationMode('auto')">
          <?= icon('play', 12) ?> Auto Play
        </button>
      </div>

      <!-- Year Selector -->
      <select onchange="changeAcademicYear(this.value)" style="background:rgba(255,255,255,0.1); color:#F8FAFC; border:1px solid rgba(255,255,255,0.2); padding:5px 12px; border-radius:999px; font-size:12px; font-weight:600; cursor:pointer;">
        <?php foreach ($years as $y): ?>
          <option value="<?= e($y) ?>" <?= $academicYear === $y ? 'selected' : '' ?> style="background:#131D3B; color:#fff;"><?= e($y) ?></option>
        <?php endforeach; ?>
      </select>

      <button type="button" class="btn-hdr" onclick="toggleFullscreen()" title="Toggle Fullscreen">
        <?= icon('maximize', 13) ?> Fullscreen
      </button>

      <button type="button" class="btn-hdr btn-hdr-brand" onclick="exitPresentation()" title="Exit Presentation">
        <?= icon('x', 13) ?> Exit
      </button>
    </div>
  </header>

  <!-- Viewport -->
  <main class="viewport">
    <div class="slide-card active" id="slideCard">
      <div class="auto-progress" id="autoBar"></div>
      <div class="slide-body" id="slideContent">
        <!-- Slide content dynamically rendered by JavaScript -->
      </div>
    </div>
  </main>

  <!-- Bottom Navigation Control Bar -->
  <footer class="ctrl-bar">
    <div class="slide-counter" id="slideCounter">
      Slide 1 of 1
    </div>

    <div class="nav-btns">
      <button type="button" class="btn-nav" id="btnPrev" onclick="prevSlide()">
        <?= icon('chevron-left', 14) ?> Previous
      </button>
      <button type="button" class="btn-nav" id="btnNext" onclick="nextSlide()">
        Next <?= icon('chevron-right', 14) ?>
      </button>
    </div>

    <div style="font-size:12px; color:#64748B; display:flex; align-items:center; gap:16px;">
      <span id="autoTimerNotice" style="color:#FF4F01; font-weight:600; display:none;">
        Advancing in <span id="autoSecondsLeft">10</span>s
      </span>
      <span>Use &larr; &rarr; arrow keys to navigate</span>
    </div>
  </footer>

  <script>
    const slides = <?= $slidesJson ?: '[]' ?>;
    let currentIndex = 0;
    let presentationMode = 'manual';
    let autoTimer = null;
    let autoRemaining = 10;
    const AUTO_ADVANCE_SECONDS = 10;

    function renderCountdown() {
      const notice = document.getElementById('autoTimerNotice');
      const counter = document.getElementById('autoSecondsLeft');
      if (!notice || !counter) return;

      if (presentationMode === 'auto' && autoRemaining > 0) {
        counter.textContent = String(autoRemaining);
        notice.style.display = 'inline';
      } else {
        notice.style.display = 'none';
      }
    }

    function clearAutoTimer() {
      if (autoTimer) {
        clearInterval(autoTimer);
        autoTimer = null;
      }
      autoRemaining = 0;
      const bar = document.getElementById('autoBar');
      if (bar) {
        bar.classList.remove('running');
        bar.style.animationDuration = '';
        bar.style.width = '0';
      }
      renderCountdown();
    }

    function startAutoTimer() {
      clearAutoTimer();
      if (presentationMode !== 'auto') return;

      if (currentIndex >= slides.length - 1) {
        setPresentationMode('manual');
        return;
      }

      autoRemaining = AUTO_ADVANCE_SECONDS;
      renderCountdown();

      const bar = document.getElementById('autoBar');
      if (bar) {
        bar.style.animationDuration = AUTO_ADVANCE_SECONDS + 's';
        void bar.offsetWidth;
        bar.classList.add('running');
      }

      autoTimer = setInterval(() => {
        autoRemaining -= 1;
        renderCountdown();
        if (autoRemaining <= 0) {
          clearAutoTimer();
          nextSlide();
        }
      }, 1000);
    }

    function setPresentationMode(next) {
      presentationMode = (next === 'auto') ? 'auto' : 'manual';
      document.getElementById('btnModeAuto').classList.toggle('active', presentationMode === 'auto');
      document.getElementById('btnModeManual').classList.toggle('active', presentationMode === 'manual');

      if (presentationMode === 'auto') {
        startAutoTimer();
      } else {
        clearAutoTimer();
      }
    }

    function exitPresentation() {
      clearAutoTimer();
      if (document.fullscreenElement && document.exitFullscreen) {
        document.exitFullscreen().catch(() => {});
      }
      location.href = '<?= e($exitUrl) ?>';
    }

    function changeAcademicYear(y) {
      clearAutoTimer();
      const u = new URL(location.href);
      u.searchParams.set('academic_year', y);
      location.href = u.toString();
    }

    window.addEventListener('pagehide', clearAutoTimer);

    function renderSlide(index) {
      if (index < 0 || index >= slides.length) return;
      currentIndex = index;
      clearAutoTimer();

      const slide = slides[currentIndex];
      const card = document.getElementById('slideCard');
      const content = document.getElementById('slideContent');

      card.classList.remove('active');

      setTimeout(() => {
        let html = '';

        if (slide.type === 'title') {
          html = `
            <div style="flex:1; display:flex; flex-direction:column; justify-content:center; align-items:flex-start;">
              <div class="intro-badge"><?= icon('presentation', 13) ?> Individual Student Report</div>
              <h1 class="intro-title">${slide.title}</h1>
              <div class="intro-sub">Department of ${slide.department} &middot; Register No: ${slide.reg_no}</div>
              <div style="margin-top:8px; font-size:14px; color:#64748B; font-weight:600;">Academic Year ${slide.academicYear}</div>

              <div style="margin-top:36px; width:100%; display:grid; grid-template-columns: repeat(3, 1fr); gap:16px;">
                <div class="kpi-box brand">
                  <div class="kpi-box-lbl">Total Verified Achievements</div>
                  <div class="kpi-box-val">${slide.total}</div>
                </div>
                <div class="kpi-box">
                  <div class="kpi-box-lbl">Approved Records</div>
                  <div class="kpi-box-val" style="color:var(--success);">${slide.approved}</div>
                </div>
                <div class="kpi-box">
                  <div class="kpi-box-lbl">Pending Review</div>
                  <div class="kpi-box-val">${slide.total - slide.approved}</div>
                </div>
              </div>
            </div>
          `;
        } else if (slide.type === 'overall_summary') {
          const s = slide.summary;
          html = `
            <div class="slide-hdr-bar">
              <div>
                <div class="slide-cat-title">Overall Student Achievement Summary</div>
                <div class="slide-cat-sub">Consolidated student activity breakdown &middot; AY ${slide.academic_year}</div>
              </div>
              <div style="background:#FFF3EC; color:#FF4F01; font-weight:800; font-size:18px; padding:6px 16px; border-radius:999px;">
                ${s.total} Submissions
              </div>
            </div>

            <div class="kpi-row">
              <div class="kpi-box brand">
                <div class="kpi-box-lbl">Total Achievements</div>
                <div class="kpi-box-val">${s.total}</div>
              </div>
              <div class="kpi-box">
                <div class="kpi-box-lbl">Approved Records</div>
                <div class="kpi-box-val" style="color:var(--success);">${s.approved}</div>
              </div>
              <div class="kpi-box">
                <div class="kpi-box-lbl">Pending / Submitted</div>
                <div class="kpi-box-val" style="color:var(--warning);">${s.pending}</div>
              </div>
            </div>

            <div style="margin-top:20px; background:#F8FAFC; border:1px solid #E2E8F0; border-radius:12px; padding:24px; display:flex; align-items:center; gap:20px;">
              <div style="font-size:38px; font-weight:900; color:#131D3B;">${s.total}</div>
              <div style="font-size:14px; color:#475569; line-height:1.5;">
                <strong>Consolidated Student Performance:</strong> This student has recorded <strong>${s.total}</strong> validated achievements across curricular, co-curricular, and technical categories for Academic Year <strong>${slide.academic_year}</strong>.
              </div>
            </div>
          `;
        } else if (slide.type === 'category') {
          const recs = slide.records || [];
          let recsHtml = '';
          if (recs.length === 0) {
            recsHtml = '<div style="color:#64748B; padding:20px; text-align:center;">No individual records recorded</div>';
          } else {
            recsHtml = recs.map(r => `
              <div class="rec-item">
                <div>
                  <div class="rec-title">${r.title || 'Record #' + r.id}</div>
                  <div class="rec-meta">Date: ${r.date || '—'} &middot; Status: <strong>${r.status || 'Submitted'}</strong></div>
                </div>
                <span class="badge" style="background:#F1F5F9; color:#1E2B52; font-size:11px; padding:4px 8px; border-radius:6px; font-weight:600;">
                  ${r.status || 'Submitted'}
                </span>
              </div>
            `).join('');
          }

          html = `
            <div class="slide-hdr-bar">
              <div>
                <div class="slide-cat-title">${slide.category}</div>
                <div class="slide-cat-sub">Student Records &middot; AY <?= e($academicYear) ?></div>
              </div>
              <div style="background:#FFF3EC; color:#FF4F01; font-weight:800; font-size:16px; padding:6px 14px; border-radius:999px;">
                ${slide.count} ${slide.count === 1 ? 'Record' : 'Records'}
              </div>
            </div>

            <div class="kpi-row" style="grid-template-columns: repeat(2, 1fr); margin-bottom:16px;">
              <div class="kpi-box brand">
                <div class="kpi-box-lbl">Category Total</div>
                <div class="kpi-box-val">${slide.count}</div>
              </div>
              <div class="kpi-box">
                <div class="kpi-box-lbl">Department</div>
                <div class="kpi-box-val" style="font-size:20px;"><?= e($student['department']) ?></div>
              </div>
            </div>

            <div style="flex:1; overflow-y:auto; margin-top:8px;">
              ${recsHtml}
            </div>
          `;
        }

        content.innerHTML = html;
        card.classList.add('active');

        // Update Counter and Buttons
        document.getElementById('slideCounter').textContent = `Slide ${currentIndex + 1} of ${slides.length}`;
        document.getElementById('btnPrev').disabled = (currentIndex === 0);
        document.getElementById('btnNext').disabled = (currentIndex === slides.length - 1);

        if (presentationMode === 'auto') {
          startAutoTimer();
        }
      }, 150);
    }

    function nextSlide() {
      if (currentIndex < slides.length - 1) {
        renderSlide(currentIndex + 1);
      }
    }

    function prevSlide() {
      if (currentIndex > 0) {
        renderSlide(currentIndex - 1);
      }
    }

    function toggleFullscreen() {
      if (!document.fullscreenElement) {
        document.documentElement.requestFullscreen().catch(() => {});
      } else {
        document.exitFullscreen().catch(() => {});
      }
    }

    // Keyboard Shortcuts
    document.addEventListener('keydown', (e) => {
      if (e.key === 'ArrowRight' || e.key === 'PageDown' || e.key === ' ') {
        e.preventDefault();
        nextSlide();
      } else if (e.key === 'ArrowLeft' || e.key === 'PageUp') {
        e.preventDefault();
        prevSlide();
      } else if (e.key === 'Escape') {
        exitPresentation();
      }
    });

    // Initialize First Slide
    if (slides.length > 0) {
      renderSlide(0);
    } else {
      document.getElementById('slideContent').innerHTML = '<div style="padding:40px; text-align:center;"><h2>No achievements to present for this student.</h2></div>';
    }
  </script>
</body>
</html>
