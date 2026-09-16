<?php
/**
 * Faculty Achievement Presentation Mode — Fullscreen Academic Review Presentation Engine.
 * Allows Admin, Principal, Director, Dean, HoD, and Faculty to present achievements in a clean slide format.
 * Strictly enforced backend authorization via can_user_view_faculty_report().
 */

require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/icons.php';
require_once __DIR__ . '/inc/report_layout.php';
require_once __DIR__ . '/models/FacultyAchievement.php';
require_once __DIR__ . '/models/User.php';

if (!defined('REPORT_INSTITUTION')) {
    define('REPORT_INSTITUTION', 'Mohamed Sathak Engineering College');
}

$user = require_login();

$targetFacultyId = (int) input('id', 0);
if (!$targetFacultyId) {
    $targetFacultyId = (int) $user['id'];
}

// Strict backend role authorization gate
if (!can_user_view_faculty_report($user, $targetFacultyId)) {
    http_response_code(403);
    require __DIR__ . '/denied.php';
    exit;
}

$academicYear = trim((string) input('academic_year', '')) ?: active_academic_year();
$presentation = faculty_achievement_presentation_data($targetFacultyId, $academicYear);
$faculty      = $presentation['faculty'];

if (!$faculty) {
    http_response_code(404);
    echo "<h1>404 Not Found</h1><p>Faculty record not found.</p>";
    exit;
}

$slidesJson = json_encode($presentation['slides'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
$years      = academic_years();
$exitUrl    = url('individual-faculty-report.php?id=' . $targetFacultyId);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Presentation: <?= e($faculty['name']) ?> — ATTS IQAC</title>
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
      align-items: center;
      justify-content: space-between;
      border-bottom: 2px solid var(--navy-50);
      padding-bottom: 16px;
      margin-bottom: 24px;
    }
    .slide-cat-title {
      font-size: 24px;
      font-weight: 800;
      color: var(--navy);
    }
    .slide-cat-sub {
      font-size: 13px;
      color: var(--muted);
      font-weight: 500;
    }

    /* KPI Target vs Achieved Cards */
    .kpi-row {
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      gap: 16px;
      margin-bottom: 24px;
    }
    .kpi-box {
      background: var(--navy-50);
      border: 1px solid var(--hairline);
      border-radius: 12px;
      padding: 16px;
      text-align: center;
    }
    .kpi-box.brand {
      background: #FFF3EC;
      border-color: rgba(255,79,1,0.3);
    }
    .kpi-box-lbl {
      font-size: 11px;
      font-weight: 700;
      color: var(--muted);
      text-transform: uppercase;
      letter-spacing: .05em;
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

    /* Visual Progress Bar */
    .progress-bar-container {
      background: #E2E8F0;
      border-radius: 999px;
      height: 14px;
      width: 100%;
      overflow: hidden;
      margin: 10px 0 20px;
      position: relative;
    }
    .progress-bar-fill {
      background: linear-gradient(90deg, #FF4F01, #FF763B);
      height: 100%;
      border-radius: 999px;
      transition: width .5s ease;
    }

    /* Data Tables */
    table.slide-tbl {
      width: 100%;
      border-collapse: collapse;
      font-size: 13px;
      margin-top: 8px;
    }
    table.slide-tbl th {
      background: var(--navy);
      color: #ffffff;
      padding: 10px 14px;
      font-size: 12px;
      font-weight: 700;
      text-align: left;
    }
    table.slide-tbl td {
      padding: 12px 14px;
      border-bottom: 1px solid var(--hairline);
    }
    table.slide-tbl tr:nth-child(even) td {
      background: var(--navy-50);
    }
    .badge {
      display: inline-block;
      padding: 3px 8px;
      border-radius: 6px;
      font-size: 11px;
      font-weight: 700;
    }
    .badge-success { background: #D1FAE5; color: #065F46; }
    .badge-neutral { background: #F1F5F9; color: #475569; }

    /* Bottom Control Bar */
    .bbar {
      background: var(--navy);
      border-top: 1px solid rgba(255,255,255,0.1);
      padding: 12px 32px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      height: 64px;
      flex-shrink: 0;
      z-index: 100;
    }
    .bbar-ctrl {
      display: flex;
      align-items: center;
      gap: 12px;
    }
    .btn-nav {
      background: rgba(255,255,255,0.1);
      color: #fff;
      border: 1px solid rgba(255,255,255,0.2);
      padding: 8px 20px;
      border-radius: 999px;
      font-size: 13.5px;
      font-weight: 700;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 8px;
      transition: all .2s ease;
    }
    .btn-nav:hover:not(:disabled) {
      background: var(--brand);
      border-color: var(--brand);
    }
    .btn-nav:disabled {
      opacity: 0.3;
      cursor: not-allowed;
    }
    .slide-counter {
      font-size: 14px;
      font-weight: 700;
      color: #94A3B8;
      letter-spacing: .02em;
    }

    /* Dots Indicator */
    .dots {
      display: flex;
      gap: 6px;
    }
    .dot {
      width: 8px;
      height: 8px;
      border-radius: 50%;
      background: rgba(255,255,255,0.2);
      cursor: pointer;
      transition: all .2s ease;
    }
    .dot.active {
      background: var(--brand);
      width: 20px;
      border-radius: 999px;
    }
  </style>
</head>
<body>

  <!-- Top Bar -->
  <header class="hdr">
    <div class="hdr-brand">
      <div class="hdr-logo">ATTS</div>
      <div>
        <div class="hdr-title"><?= e(REPORT_INSTITUTION) ?></div>
        <div class="hdr-sub">Academic Target Tracking System &middot; IQAC Presentation Mode</div>
      </div>
    </div>

    <div class="hdr-actions">
      <label style="display:flex; align-items:center; gap:6px; color:#94A3B8; font-size:12px; font-weight:600;">
        <span>Academic Year:</span>
        <select onchange="location.href='present-faculty-report.php?id=<?= $targetFacultyId ?>&academic_year=' + this.value" style="background:#1E2B52; color:#fff; border:1px solid rgba(255,255,255,0.2); padding:4px 8px; border-radius:6px; font-size:12px; font-weight:600;">
          <?php foreach ($years as $y): ?>
            <option value="<?= e($y) ?>" <?= $academicYear === $y ? 'selected' : '' ?>><?= e($y) ?></option>
          <?php endforeach; ?>
        </select>
      </label>

      <button class="btn-hdr" onclick="toggleFullscreen()" title="Toggle Fullscreen View">
        <?= icon('maximize', 14) ?> <span id="fsText">Fullscreen</span>
      </button>

      <a href="<?= e($exitUrl) ?>" class="btn-hdr btn-hdr-brand" title="Exit Presentation">
        <?= icon('x', 14) ?> Exit Presentation
      </a>
    </div>
  </header>

  <!-- Viewport Area -->
  <main class="viewport">
    <div class="slide-card" id="slideCard">
      <div class="slide-body" id="slideContent">
        <!-- Rendered dynamically by Javascript -->
      </div>
    </div>
  </main>

  <!-- Bottom Navigation Control Bar -->
  <footer class="bbar">
    <div class="bbar-ctrl">
      <button class="btn-nav" id="btnPrev" onclick="prevSlide()">
        <?= icon('arrow-left', 15) ?> Previous
      </button>
      <button class="btn-nav" id="btnNext" onclick="nextSlide()">
        Next <?= icon('arrow-right', 15) ?>
      </button>
    </div>

    <div class="dots" id="dotsContainer">
      <!-- Dots rendered by JS -->
    </div>

    <div class="slide-counter" id="slideCounter">
      Slide 1 of 1
    </div>
  </footer>

  <script>
    const slides = <?= $slidesJson ?>;
    let currentIndex = 0;

    function renderSlide(index) {
      if (index < 0 || index >= slides.length) return;
      currentIndex = index;

      const slide = slides[currentIndex];
      const card = document.getElementById('slideCard');
      const content = document.getElementById('slideContent');

      card.classList.remove('active');

      setTimeout(() => {
        let html = '';

        if (slide.type === 'intro') {
          const f = slide.faculty;
          const o = slide.overall;
          html = `
            <div style="flex:1; display:flex; flex-direction:column; justify-content:center; align-items:flex-start;">
              <div class="intro-badge"><?= icon('presentation', 13) ?> Individual Faculty Report</div>
              <h1 class="intro-title">${f.name}</h1>
              <div class="intro-sub">${f.designation} &middot; Department of ${f.department}</div>
              <div style="margin-top:8px; font-size:14px; color:#64748B; font-weight:600;">Employee ID: ${f.employee_id} &middot; Academic Year ${slide.academic_year}</div>

              <div style="margin-top:36px; width:100%; display:grid; grid-template-columns: repeat(3, 1fr); gap:16px;">
                <div class="kpi-box">
                  <div class="kpi-box-lbl">Overall Target</div>
                  <div class="kpi-box-val">${o.configured ? o.target : 'Not Set'}</div>
                </div>
                <div class="kpi-box brand">
                  <div class="kpi-box-lbl">Overall Achieved</div>
                  <div class="kpi-box-val">${o.achieved}</div>
                </div>
                <div class="kpi-box">
                  <div class="kpi-box-lbl">Achievement %</div>
                  <div class="kpi-box-val">${o.configured && o.percentage !== null ? o.percentage + '%' : 'N/A'}</div>
                </div>
              </div>
            </div>
          `;
        } else if (slide.type === 'overall_summary') {
          const o = slide.overall;
          const pctText = (o.configured && o.percentage !== null) ? o.percentage + '%' : 'N/A';
          const pctWidth = (o.configured && o.percentage !== null) ? Math.min(o.percentage, 100) + '%' : '0%';

          html = `
            <div class="slide-hdr-bar">
              <div>
                <div class="slide-cat-title">Target vs Achievement</div>
                <div class="slide-cat-sub">Overall performance summary across all categories &middot; AY ${slide.academic_year}</div>
              </div>
              <div style="background:#FFF3EC; color:#FF4F01; font-weight:800; font-size:18px; padding:6px 16px; border-radius:999px;">
                ${pctText} Achieved
              </div>
            </div>

            <div class="kpi-row">
              <div class="kpi-box">
                <div class="kpi-box-lbl">Overall Target</div>
                <div class="kpi-box-val">${o.configured ? o.target : 'Not Configured'}</div>
              </div>
              <div class="kpi-box brand">
                <div class="kpi-box-lbl">Total Achieved</div>
                <div class="kpi-box-val">${o.achieved}</div>
              </div>
              <div class="kpi-box">
                <div class="kpi-box-lbl">Remaining</div>
                <div class="kpi-box-val">${o.configured ? o.remaining : 'N/A'}</div>
              </div>
              <div class="kpi-box">
                <div class="kpi-box-lbl">Achievement Score</div>
                <div class="kpi-box-val">${pctText}</div>
              </div>
            </div>

            <div style="margin-top:10px;">
              <div style="display:flex; justify-content:space-between; font-size:13px; font-weight:700; color:#131D3B;">
                <span>Overall Progress</span>
                <span>${o.achieved} / ${o.configured ? o.target : 'N/A'} (${pctText})</span>
              </div>
              <div class="progress-bar-container">
                <div class="progress-bar-fill" style="width: ${pctWidth};"></div>
              </div>
            </div>

            <div style="margin-top:20px; background:#F8FAFC; border:1px solid #E2E8F0; border-radius:12px; padding:20px; display:flex; align-items:center; gap:20px;">
              <div style="font-size:36px; font-weight:900; color:#131D3B;">${o.achieved}</div>
              <div style="font-size:13.5px; color:#475569; line-height:1.5;">
                <strong>Persisted Academic Achievements:</strong> This faculty member has successfully documented <strong>${o.achieved}</strong> verified achievements for Academic Year <strong>${slide.academic_year}</strong>.
              </div>
            </div>
          `;
        } else if (slide.type === 'category') {
          const tgtText = slide.configured ? slide.target : 'Not Configured';
          const remText = slide.configured ? slide.remaining : 'N/A';
          const pctText = (slide.configured && slide.percentage !== null) ? slide.percentage + '%' : 'N/A';
          const pctWidth = (slide.configured && slide.percentage !== null) ? Math.min(slide.percentage, 100) + '%' : '0%';
          const pageInfo = slide.total_pages > 1 ? ` &middot; Page ${slide.page} of ${slide.total_pages}` : '';

          html = `
            <div class="slide-hdr-bar">
              <div>
                <div class="slide-cat-title">${slide.category_label}${pageInfo}</div>
                <div class="slide-cat-sub">Faculty Category Achievements &middot; AY ${slide.academic_year}</div>
              </div>
              <div style="background:#F1F5F9; color:#1E2B52; font-weight:700; font-size:14px; padding:6px 14px; border-radius:999px;">
                Achieved: ${slide.achieved} / Target: ${tgtText}
              </div>
            </div>

            <div class="kpi-row" style="grid-template-columns: repeat(4, 1fr); margin-bottom:16px;">
              <div class="kpi-box">
                <div class="kpi-box-lbl">Target</div>
                <div class="kpi-box-val">${tgtText}</div>
              </div>
              <div class="kpi-box brand">
                <div class="kpi-box-lbl">Achieved</div>
                <div class="kpi-box-val">${slide.achieved}</div>
              </div>
              <div class="kpi-box">
                <div class="kpi-box-lbl">Remaining</div>
                <div class="kpi-box-val">${remText}</div>
              </div>
              <div class="kpi-box">
                <div class="kpi-box-lbl">Achievement %</div>
                <div class="kpi-box-val">${pctText}</div>
              </div>
            </div>

            <div class="progress-bar-container" style="height:8px; margin: 0 0 16px 0;">
              <div class="progress-bar-fill" style="width: ${pctWidth};"></div>
            </div>

            <div style="font-weight:700; font-size:14px; color:#131D3B; margin-bottom:8px;">
              Achievement Records (${slide.records.length} shown)
            </div>
          `;

          if (slide.records.length === 0) {
            html += `
              <div style="background:#F8FAFC; border:1px dashed #CBD5E1; border-radius:12px; padding:30px; text-align:center; color:#64748B; font-size:14px;">
                No ${slide.category_label} records submitted for Academic Year ${slide.academic_year}.
              </div>
            `;
          } else {
            html += `
              <table class="slide-tbl">
                <thead>
                  <tr>
                    <th>Title / Description</th>
                    <th>Department</th>
                    <th>Status</th>
                    <th>Academic Year</th>
                  </tr>
                </thead>
                <tbody>
            `;
            slide.records.forEach(r => {
              html += `
                <tr>
                  <td style="font-weight:600; color:#131D3B;">${escapeHtml(r.title)}</td>
                  <td>${escapeHtml(r.department)}</td>
                  <td><span class="badge badge-success">${escapeHtml(r.status)}</span></td>
                  <td>${escapeHtml(r.year)}</td>
                </tr>
              `;
            });
            html += `</tbody></table>`;
          }
        } else if (slide.type === 'final_summary') {
          const o = slide.overall;
          const pctText = (o.configured && o.percentage !== null) ? o.percentage + '%' : 'N/A';

          html = `
            <div class="slide-hdr-bar">
              <div>
                <div class="slide-cat-title">Faculty Achievement Summary</div>
                <div class="slide-cat-sub">Consolidated performance matrix &middot; AY ${slide.academic_year}</div>
              </div>
              <div style="background:#131D3B; color:#fff; font-weight:800; font-size:15px; padding:6px 16px; border-radius:999px;">
                Overall Score: ${o.achieved} / ${o.configured ? o.target : 'N/A'} (${pctText})
              </div>
            </div>

            <div style="max-height: 380px; overflow-y: auto;">
              <table class="slide-tbl">
                <thead>
                  <tr>
                    <th>Category Name</th>
                    <th style="text-align:right;">Target</th>
                    <th style="text-align:right;">Achieved</th>
                    <th style="text-align:right;">Remaining</th>
                    <th style="text-align:right;">Achievement %</th>
                  </tr>
                </thead>
                <tbody>
          `;

          slide.categories.forEach(c => {
            const tgt = c.configured ? c.target : 'Not Configured';
            const rem = c.configured ? c.remaining : 'N/A';
            const pct = (c.configured && c.percentage !== null) ? c.percentage + '%' : 'N/A';
            html += `
              <tr>
                <td style="font-weight:700; color:#131D3B;">${escapeHtml(c.label)}</td>
                <td style="text-align:right;">${tgt}</td>
                <td style="text-align:right; font-weight:800; color:#FF4F01;">${c.achieved}</td>
                <td style="text-align:right;">${rem}</td>
                <td style="text-align:right; font-weight:700;">${pct}</td>
              </tr>
            `;
          });

          html += `
                </tbody>
              </table>
            </div>

            <div style="margin-top:20px; display:flex; justify-content:space-between; align-items:center; border-top:2px solid #E2E8F0; padding-top:16px;">
              <div style="font-size:12px; color:#64748B;">Official Academic Review Document &middot; Internal Quality Assurance Cell (IQAC)</div>
              <div style="font-size:16px; font-weight:800; color:#131D3B;">
                Overall Achievement: <span style="color:#FF4F01;">${o.achieved} / ${o.configured ? o.target : 'N/A'} (${pctText})</span>
              </div>
            </div>
          `;
        }

        content.innerHTML = html;
        card.classList.add('active');

        // Update Counter & Buttons
        document.getElementById('slideCounter').textContent = `Slide ${currentIndex + 1} of ${slides.length}`;
        document.getElementById('btnPrev').disabled = (currentIndex === 0);
        document.getElementById('btnNext').disabled = (currentIndex === slides.length - 1);

        // Update Dots
        const dotsContainer = document.getElementById('dotsContainer');
        dotsContainer.innerHTML = '';
        slides.forEach((_, i) => {
          const dot = document.createElement('div');
          dot.className = `dot ${i === currentIndex ? 'active' : ''}`;
          dot.onclick = () => renderSlide(i);
          dotsContainer.appendChild(dot);
        });
      }, 100);
    }

    function prevSlide() {
      if (currentIndex > 0) renderSlide(currentIndex - 1);
    }

    function nextSlide() {
      if (currentIndex < slides.length - 1) renderSlide(currentIndex + 1);
    }

    function escapeHtml(str) {
      return String(str || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    // Keyboard Navigation
    document.addEventListener('keydown', (e) => {
      if (e.key === 'ArrowRight' || e.key === ' ') {
        e.preventDefault();
        nextSlide();
      } else if (e.key === 'ArrowLeft') {
        e.preventDefault();
        prevSlide();
      } else if (e.key === 'Escape') {
        location.href = '<?= e($exitUrl) ?>';
      }
    });

    // Fullscreen Toggle
    function toggleFullscreen() {
      if (!document.fullscreenElement) {
        document.documentElement.requestFullscreen().catch(err => {});
        document.getElementById('fsText').textContent = 'Exit Fullscreen';
      } else {
        if (document.exitFullscreen) document.exitFullscreen();
        document.getElementById('fsText').textContent = 'Fullscreen';
      }
    }

    // Initialize slide deck
    renderSlide(0);
  </script>
</body>
</html>
