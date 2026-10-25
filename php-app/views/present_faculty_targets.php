<?php
/**
 * Individual Faculty Achievement Presentation View
 * ATTS IQAC Portal — Mohamed Sathak Engineering College
 *
 * Renders the personalized Faculty Achievement Presentation:
 * Slide 1: Faculty Overall Achievement Summary
 * Slide 2: My Achievements by Category
 * Slides 3..N: Individual Category Achievement Detail Slides
 * Slide N+1: Final Faculty Achievement Summary
 *
 * Scoped strictly to the authenticated faculty member and selected academic year.
 * Read-only presentation.
 */

declare(strict_types=1);

$faculty               = $dataset['faculty'];
$academicYear          = $dataset['academic_year'];
$summary               = $dataset['summary'];
$categories            = $dataset['categories'];
$activeCategorySlides  = $dataset['active_category_slides'];
$totalSlides           = $dataset['total_slides'];
$verifiedHighlights    = $dataset['verified_highlights'];
$facultyDisplayName    = strtoupper(trim($faculty['name']));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= e($facultyDisplayName) ?> — Faculty Achievement Presentation — Academic Year <?= e($academicYear) ?> — ATTS IQAC</title>
  <?php require __DIR__ . '/../inc/favicon.php'; ?>
  <style>
    :root {
      --navy: #131D3B;
      --navy-dark: #0D1427;
      --navy-surface: #1E2B52;
      --navy-50: #F4F6FA;
      --navy-100: #E2E8F0;
      --orange: #FF4F01;
      --orange-hover: #E04400;
      --orange-50: #FFF1EB;
      --orange-100: #FFE4D6;
      --ink: #131D3B;
      --ink-muted: #5A6785;
      --ink-faint: #8A97B0;
      --surface: #FFFFFF;
      --hairline: #E4E9F2;
      --hairline-soft: #F1F5F9;
      --success: #10B981;
      --success-50: #ECFDF5;
      --warning: #F59E0B;
      --warning-50: #FFFBEB;
      --danger: #EF4444;
      --danger-50: #FEF2F2;
      --info: #3B82F6;
      --info-50: #EFF6FF;
      --shadow-sm: 0 1px 3px rgba(19, 29, 59, 0.05);
      --shadow-md: 0 4px 14px rgba(19, 29, 59, 0.07);
      --shadow-lg: 0 12px 28px rgba(19, 29, 59, 0.12);
      --r-sm: 6px;
      --r-md: 10px;
      --r-lg: 14px;
    }

    * {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
    }

    body {
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
      background: #F8FAFC;
      color: var(--ink);
      line-height: 1.5;
      min-height: 100vh;
      display: flex;
      flex-direction: column;
      overflow-x: hidden;
      user-select: none;
    }

    /* Top Control Header */
    .present-header {
      background: var(--navy);
      color: #FFFFFF;
      padding: 12px 24px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 16px;
      border-bottom: 2px solid var(--orange);
      box-shadow: 0 2px 10px rgba(0,0,0,0.15);
      position: sticky;
      top: 0;
      z-index: 100;
    }

    .present-brand {
      display: flex;
      align-items: center;
      gap: 12px;
    }

    .present-logo-badge {
      background: var(--orange);
      color: #FFFFFF;
      font-weight: 800;
      font-size: 13px;
      padding: 4px 10px;
      border-radius: var(--r-sm);
      letter-spacing: 0.05em;
      display: inline-flex;
      align-items: center;
      gap: 6px;
      text-transform: uppercase;
    }

    .present-title-group h1 {
      font-size: 15px;
      font-weight: 700;
      color: #FFFFFF;
      margin: 0;
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .present-title-group p {
      font-size: 11.5px;
      color: #94A3B8;
      margin: 0;
    }

    .header-center-info {
      display: flex;
      align-items: center;
      gap: 12px;
    }

    .header-year-pill {
      background: rgba(255, 255, 255, 0.1);
      border: 1px solid rgba(255, 255, 255, 0.2);
      border-radius: 20px;
      padding: 3px 12px;
      font-size: 12px;
      font-weight: 600;
      color: #E2E8F0;
      display: inline-flex;
      align-items: center;
      gap: 6px;
    }

    .header-year-dot {
      width: 7px;
      height: 7px;
      border-radius: 50%;
      background: #10B981;
    }

    .slide-counter-display {
      font-size: 13px;
      font-weight: 700;
      color: #CBD5E1;
      letter-spacing: 0.04em;
    }

    .present-controls {
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .btn-ctrl {
      background: rgba(255, 255, 255, 0.12);
      color: #FFFFFF;
      border: 1px solid rgba(255, 255, 255, 0.2);
      padding: 6px 14px;
      border-radius: var(--r-sm);
      font-size: 12.5px;
      font-weight: 600;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 6px;
      transition: all 0.15s ease;
      text-decoration: none;
    }

    .btn-ctrl:hover:not(:disabled) {
      background: rgba(255, 255, 255, 0.22);
      border-color: rgba(255, 255, 255, 0.35);
    }

    .btn-ctrl:disabled {
      opacity: 0.4;
      cursor: not-allowed;
    }

    .btn-ctrl.btn-primary {
      background: var(--orange);
      border-color: var(--orange);
    }

    .btn-ctrl.btn-primary:hover:not(:disabled) {
      background: var(--orange-hover);
      border-color: var(--orange-hover);
    }

    .btn-ctrl.btn-close-present {
      background: rgba(239, 68, 68, 0.2);
      border-color: rgba(239, 68, 68, 0.4);
      color: #FCA5A5;
    }

    .btn-ctrl.btn-close-present:hover {
      background: rgba(239, 68, 68, 0.35);
      color: #FFFFFF;
    }

    /* Presentation Viewport */
    .present-viewport {
      flex: 1;
      position: relative;
      display: flex;
      flex-direction: column;
      padding: 24px;
      max-width: 1440px;
      width: 100%;
      margin: 0 auto;
    }

    /* Individual Slide */
    .present-slide {
      display: none;
      flex-direction: column;
      width: 100%;
      animation: fadeInSlide 0.25s ease-out;
    }

    .present-slide.active {
      display: flex;
    }

    @keyframes fadeInSlide {
      from { opacity: 0; transform: translateY(6px); }
      to { opacity: 1; transform: translateY(0); }
    }

    /* Slide Header */
    .slide-header {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      margin-bottom: 20px;
      padding-bottom: 14px;
      border-bottom: 1px solid var(--hairline);
      gap: 16px;
    }

    .slide-title-wrap h2 {
      font-size: 22px;
      font-weight: 800;
      color: var(--navy);
      display: flex;
      align-items: center;
      gap: 10px;
      letter-spacing: -0.01em;
    }

    .slide-title-wrap p {
      font-size: 13.5px;
      color: var(--ink-muted);
      margin-top: 3px;
    }

    .slide-badge-wrap {
      display: flex;
      align-items: center;
      gap: 8px;
      flex-shrink: 0;
    }

    .badge-pill {
      font-size: 12px;
      font-weight: 700;
      padding: 4px 12px;
      border-radius: 20px;
      display: inline-flex;
      align-items: center;
      gap: 5px;
      text-transform: uppercase;
      letter-spacing: 0.04em;
    }

    .badge-pill.brand {
      background: var(--orange-50);
      color: var(--orange);
      border: 1px solid var(--orange-100);
    }

    .badge-pill.dept {
      background: #EFF6FF;
      color: #2563EB;
      border: 1px solid #BFDBFE;
    }

    .badge-pill.year {
      background: #FEF3C7;
      color: #D97706;
      border: 1px solid #FDE68A;
    }

    .badge-pill.success {
      background: var(--success-50);
      color: var(--success);
      border: 1px solid #A7F3D0;
    }

    .badge-pill.warning {
      background: var(--warning-50);
      color: var(--warning);
      border: 1px solid #FDE68A;
    }

    .badge-pill.danger {
      background: var(--danger-50);
      color: var(--danger);
      border: 1px solid #FECACA;
    }

    .badge-pill.info {
      background: var(--info-50);
      color: var(--info);
      border: 1px solid #BFDBFE;
    }

    /* KPI Cards Row */
    .kpi-cards-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
      gap: 16px;
      margin-bottom: 24px;
    }

    .kpi-card {
      background: var(--surface);
      border: 1px solid var(--hairline);
      border-radius: var(--r-md);
      padding: 18px 20px;
      box-shadow: var(--shadow-sm);
      display: flex;
      flex-direction: column;
      position: relative;
      overflow: hidden;
      transition: transform 0.15s ease, box-shadow 0.15s ease;
    }

    .kpi-card:hover {
      transform: translateY(-2px);
      box-shadow: var(--shadow-md);
    }

    .kpi-card::before {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      height: 3px;
    }

    .kpi-card.accent-navy::before { background: var(--navy); }
    .kpi-card.accent-green::before { background: var(--success); }
    .kpi-card.accent-orange::before { background: var(--orange); }
    .kpi-card.accent-red::before { background: var(--danger); }
    .kpi-card.accent-purple::before { background: #8B5CF6; }

    .kpi-top {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 8px;
    }

    .kpi-label {
      font-size: 11.5px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.05em;
      color: var(--ink-muted);
    }

    .kpi-icon {
      width: 32px;
      height: 32px;
      border-radius: 8px;
      display: flex;
      align-items: center;
      justify-content: center;
    }

    .kpi-icon.navy { background: var(--navy-50); color: var(--navy); }
    .kpi-icon.green { background: var(--success-50); color: var(--success); }
    .kpi-icon.orange { background: var(--orange-50); color: var(--orange); }
    .kpi-icon.red { background: var(--danger-50); color: var(--danger); }
    .kpi-icon.purple { background: #F3E8FF; color: #8B5CF6; }

    .kpi-val {
      font-size: 32px;
      font-weight: 800;
      color: var(--navy);
      line-height: 1.1;
      font-variant-numeric: tabular-nums;
    }

    .kpi-sub {
      font-size: 11.5px;
      color: var(--ink-faint);
      margin-top: 6px;
    }

    /* Panels & Tables */
    .split-grid-2 {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 20px;
      margin-bottom: 24px;
    }

    @media (max-width: 992px) {
      .split-grid-2 {
        grid-template-columns: 1fr;
      }
    }

    .panel-card {
      background: var(--surface);
      border: 1px solid var(--hairline);
      border-radius: var(--r-md);
      padding: 20px;
      box-shadow: var(--shadow-sm);
    }

    .panel-head {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 16px;
      padding-bottom: 10px;
      border-bottom: 1px solid var(--hairline-soft);
    }

    .panel-title {
      font-size: 15px;
      font-weight: 700;
      color: var(--navy);
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .progress-track {
      background: var(--hairline);
      border-radius: 999px;
      height: 10px;
      overflow: hidden;
      margin: 10px 0;
    }

    .progress-fill {
      height: 100%;
      background: var(--orange);
      border-radius: 999px;
      transition: width 0.3s ease;
    }

    .progress-fill.success {
      background: var(--success);
    }

    /* Data Table */
    .table-container {
      background: var(--surface);
      border: 1px solid var(--hairline);
      border-radius: var(--r-md);
      overflow-x: auto;
      max-height: 520px;
      box-shadow: var(--shadow-sm);
    }

    table.data-table {
      width: 100%;
      border-collapse: collapse;
      text-align: left;
      font-size: 13px;
    }

    table.data-table th {
      background: var(--navy-50);
      color: var(--navy);
      font-weight: 700;
      font-size: 12px;
      text-transform: uppercase;
      letter-spacing: 0.04em;
      padding: 12px 16px;
      border-bottom: 1px solid var(--hairline);
      position: sticky;
      top: 0;
      z-index: 10;
    }

    table.data-table td {
      padding: 12px 16px;
      border-bottom: 1px solid var(--hairline-soft);
      color: var(--ink);
      vertical-align: middle;
    }

    table.data-table tr:hover td {
      background: #F8FAFC;
    }

    table.data-table tfoot td {
      background: var(--navy-50);
      font-weight: 700;
      border-top: 2px solid var(--hairline);
      border-bottom: none;
    }

    .status-badge {
      display: inline-flex;
      align-items: center;
      gap: 4px;
      padding: 3px 10px;
      border-radius: 12px;
      font-size: 11px;
      font-weight: 700;
      letter-spacing: 0.03em;
      text-transform: uppercase;
    }

    .status-badge.approved { background: var(--success-50); color: var(--success); border: 1px solid #A7F3D0; }
    .status-badge.submitted { background: #F5F3FF; color: #7C3AED; border: 1px solid #DDD6FE; }
    .status-badge.dean-pending { background: var(--warning-50); color: var(--warning); border: 1px solid #FDE68A; }
    .status-badge.rejected { background: var(--danger-50); color: var(--danger); border: 1px solid #FECACA; }
    .status-badge.draft { background: var(--navy-50); color: var(--ink-muted); border: 1px solid var(--hairline); }

    .btn-action-proof {
      display: inline-flex;
      align-items: center;
      gap: 5px;
      padding: 4px 10px;
      background: var(--info-50);
      color: var(--info);
      border: 1px solid #BFDBFE;
      border-radius: var(--r-sm);
      font-size: 12px;
      font-weight: 600;
      text-decoration: none;
      transition: all 0.15s ease;
    }

    .btn-action-proof:hover {
      background: #DBEAFE;
      color: #1D4ED8;
    }

    .btn-action-ext {
      display: inline-flex;
      align-items: center;
      gap: 4px;
      color: var(--orange);
      font-size: 12px;
      font-weight: 600;
      text-decoration: none;
    }

    .btn-action-ext:hover {
      text-decoration: underline;
    }

    /* Bottom Control Bar */
    .present-bottom-bar {
      margin-top: auto;
      padding-top: 20px;
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 16px;
      border-top: 1px solid var(--hairline);
    }

    .slide-dots-container {
      display: flex;
      align-items: center;
      gap: 6px;
      flex-wrap: wrap;
    }

    .slide-dot {
      width: 10px;
      height: 10px;
      border-radius: 50%;
      background: #CBD5E1;
      cursor: pointer;
      transition: all 0.2s ease;
    }

    .slide-dot.active {
      background: var(--orange);
      transform: scale(1.3);
    }

    .slide-dot:hover:not(.active) {
      background: #94A3B8;
    }

    .bottom-shortcuts-hint {
      font-size: 12px;
      color: var(--ink-faint);
      display: flex;
      align-items: center;
      gap: 12px;
    }

    .shortcut-kbd {
      background: #FFFFFF;
      border: 1px solid #CBD5E1;
      border-radius: 4px;
      padding: 1px 6px;
      font-size: 10.5px;
      font-weight: 700;
      color: var(--ink-muted);
      box-shadow: 0 1px 0 rgba(0,0,0,0.1);
    }

    .bottom-scope-pill {
      display: flex;
      align-items: center;
      gap: 6px;
    }
  </style>
</head>
<body>

  <!-- Top Navigation Header -->
  <header class="present-header">
    <div class="present-brand">
      <div class="present-logo-badge">
        <?= icon('award', 15) ?>
        ATTS IQAC • <?= e($faculty['department']) ?>
      </div>
      <div class="present-title-group">
        <h1><?= e($facultyDisplayName) ?> — FACULTY ACHIEVEMENT PRESENTATION</h1>
        <p><?= e($faculty['department']) ?> • Personal Faculty Achievements &amp; Submissions</p>
      </div>
    </div>

    <div class="header-center-info">
      <div class="header-year-pill">
        <span class="header-year-dot"></span>
        Department: <?= e($faculty['department']) ?>
      </div>
      <div class="header-year-pill">
        <span class="header-year-dot"></span>
        Academic Year: <?= e($academicYear) ?>
      </div>
      <div class="slide-counter-display" id="slideCounterDisplay">
        Slide 1 of <?= (int) $totalSlides ?>
      </div>
    </div>

    <div class="present-controls">
      <button type="button" class="btn-ctrl" id="btnPrev" title="Previous Slide (Left Arrow)">
        <?= icon('chevron-left', 14) ?> Prev
      </button>
      <button type="button" class="btn-ctrl btn-primary" id="btnNext" title="Next Slide (Right Arrow or Space)">
        Next <?= icon('chevron-right', 14) ?>
      </button>
      <button type="button" class="btn-ctrl" id="btnAuto" title="Toggle Auto-Play (5s per slide)">
        <?= icon('play-circle', 14) ?> <span id="autoBtnText">Auto</span>
      </button>
      <button type="button" class="btn-ctrl" id="btnFullscreen" title="Toggle Fullscreen Mode">
        <?= icon('maximize', 14) ?>
      </button>
      <button type="button" class="btn-ctrl btn-close-present" id="btnClose" title="Close Presentation (Esc)">
        <?= icon('x', 14) ?> Close
      </button>
    </div>
  </header>

  <!-- Presentation Main Viewport -->
  <main class="present-viewport">

    <!-- =================================================================== -->
    <!-- SLIDE 1: FACULTY OVERALL ACHIEVEMENT SUMMARY                        -->
    <!-- =================================================================== -->
    <section class="present-slide active" data-slide-index="1">
      <div class="slide-header">
        <div class="slide-title-wrap">
          <h2><?= icon('award', 22) ?> <?= e($facultyDisplayName) ?> — FACULTY OVERALL ACHIEVEMENT SUMMARY</h2>
          <p>Faculty: <strong><?= e($faculty['name']) ?></strong> &bull; Department: <strong><?= e($faculty['department']) ?></strong> &bull; Academic Year: <strong><?= e($academicYear) ?></strong> &bull; Designation: <strong><?= e($faculty['designation']) ?></strong></p>
        </div>
        <div class="slide-badge-wrap">
          <span class="badge-pill brand"><?= icon('shield', 12) ?> Faculty Scope</span>
          <span class="badge-pill dept"><?= e($faculty['department']) ?></span>
          <span class="badge-pill year"><?= e($academicYear) ?></span>
        </div>
      </div>

      <!-- Top Summary Cards -->
      <div class="kpi-cards-grid">
        <div class="kpi-card accent-navy">
          <div class="kpi-top">
            <span class="kpi-label">Total My Records</span>
            <div class="kpi-icon navy"><?= icon('file-stack', 16) ?></div>
          </div>
          <div class="kpi-val"><?= (int) $summary['total_records'] ?></div>
          <div class="kpi-sub">Total submissions filed for <?= e($academicYear) ?></div>
        </div>

        <div class="kpi-card accent-green">
          <div class="kpi-top">
            <span class="kpi-label">Approved Achievements</span>
            <div class="kpi-icon green"><?= icon('check', 16) ?></div>
          </div>
          <div class="kpi-val" style="color:var(--success);"><?= (int) $summary['approved_records'] ?></div>
          <div class="kpi-sub">Accepted records meeting criteria</div>
        </div>

        <div class="kpi-card accent-orange">
          <div class="kpi-top">
            <span class="kpi-label">Pending / In Progress</span>
            <div class="kpi-icon orange"><?= icon('clock', 16) ?></div>
          </div>
          <div class="kpi-val" style="color:var(--orange);"><?= (int) $summary['pending_records'] ?></div>
          <div class="kpi-sub">Submissions currently under review</div>
        </div>

        <div class="kpi-card accent-red">
          <div class="kpi-top">
            <span class="kpi-label">Rejected Records</span>
            <div class="kpi-icon red"><?= icon('x', 16) ?></div>
          </div>
          <div class="kpi-val" style="color:var(--danger);"><?= (int) $summary['rejected_records'] ?></div>
          <div class="kpi-sub">Records requiring attention or correction</div>
        </div>

        <div class="kpi-card accent-purple">
          <div class="kpi-top">
            <span class="kpi-label">Categories Contributed To</span>
            <div class="kpi-icon purple"><?= icon('grid', 16) ?></div>
          </div>
          <div class="kpi-val" style="color:#8B5CF6;"><?= (int) $summary['categories_contributed'] ?></div>
          <div class="kpi-sub">Distinct categories with activity</div>
        </div>
      </div>

      <!-- Lower Panels -->
      <div class="split-grid-2">
        <div class="panel-card">
          <div class="panel-head">
            <span class="panel-title"><?= icon('bar-chart', 16) ?> Achievement Distinction &amp; Progress</span>
            <span class="badge-pill info">Personal Submissions Scope</span>
          </div>

          <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:16px;">
            <div style="background:var(--navy-50); padding:16px; border-radius:var(--r-md); border:1px solid var(--hairline);">
              <div style="font-size:11.5px; font-weight:700; color:var(--ink-muted); text-transform:uppercase;">Total Filed Records</div>
              <div style="font-size:26px; font-weight:800; color:var(--navy); margin-top:4px;"><?= (int) $summary['total_records'] ?></div>
              <div style="font-size:11.5px; color:var(--ink-faint); margin-top:2px;">Submissions filed for <?= e($academicYear) ?></div>
            </div>
            <div style="background:var(--navy-50); padding:16px; border-radius:var(--r-md); border:1px solid var(--hairline);">
              <div style="font-size:11.5px; font-weight:700; color:var(--ink-muted); text-transform:uppercase;">Verified Achievements</div>
              <div style="font-size:26px; font-weight:800; color:var(--success); margin-top:4px;"><?= (int) $summary['approved_records'] ?></div>
              <div style="font-size:11.5px; color:var(--ink-faint); margin-top:2px;">Officially accepted records</div>
            </div>
          </div>

          <div class="progress-track" style="height:14px;">
            <div class="progress-fill <?= $summary['verification_rate'] >= 100 ? 'success' : '' ?>" style="width:<?= min(100, $summary['verification_rate']) ?>%;"></div>
          </div>
          <div style="display:flex; justify-content:space-between; font-size:12px; margin-top:6px; color:var(--ink-muted);">
            <span>Achievement Verification Rate</span>
            <strong style="color:var(--navy);"><?= $summary['verification_rate'] ?>% Verified</strong>
          </div>
        </div>

        <div class="panel-card">
          <div class="panel-head">
            <span class="panel-title"><?= icon('shield', 16) ?> Target Distinction &amp; Workflow Status</span>
            <span class="badge-pill brand"><?= e($faculty['department']) ?> Workflow</span>
          </div>
          <div style="display:flex; flex-direction:column; gap:12px; font-size:13px; color:var(--ink);">
            <div style="display:flex; align-items:flex-start; gap:10px;">
              <span class="badge-pill success" style="width:140px; justify-content:center; flex-shrink:0;">Approved</span>
              <span>Achievement verified and accepted under official departmental IQAC guidelines.</span>
            </div>
            <div style="display:flex; align-items:flex-start; gap:10px;">
              <span class="badge-pill warning" style="width:140px; justify-content:center; flex-shrink:0;">Pending / Review</span>
              <span>Under active tracking cycle or undergoing coordinator, HOD, or Dean review.</span>
            </div>
            <div style="display:flex; align-items:flex-start; gap:10px;">
              <span class="badge-pill danger" style="width:140px; justify-content:center; flex-shrink:0;">Rejected</span>
              <span>Returned for correction, clarification, or missing supporting documentation.</span>
            </div>
            <div style="display:flex; align-items:flex-start; gap:10px; margin-top:4px; padding-top:10px; border-top:1px dashed var(--hairline); font-size:12px; color:var(--ink-muted);">
              <span><?= icon('info', 13) ?> Personal record ownership and approval states are verified from the live database.</span>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- =================================================================== -->
    <!-- SLIDE 2: MY ACHIEVEMENTS BY CATEGORY                               -->
    <!-- =================================================================== -->
    <section class="present-slide" data-slide-index="2">
      <div class="slide-header">
        <div class="slide-title-wrap">
          <h2><?= icon('grid', 22) ?> MY ACHIEVEMENTS BY CATEGORY</h2>
          <p>Personal achievement metrics breakdown across all configured categories for Academic Year <?= e($academicYear) ?></p>
        </div>
        <div class="slide-badge-wrap">
          <span class="badge-pill brand"><?= icon('target', 12) ?> Category Breakdown</span>
          <span class="badge-pill year"><?= e($academicYear) ?></span>
        </div>
      </div>

      <div class="table-container">
        <table class="data-table">
          <thead>
            <tr>
              <th style="width:40px;">#</th>
              <th>Achievement Category</th>
              <th style="text-align:center; width:120px;">Total Records</th>
              <th style="text-align:center; width:120px;">Approved</th>
              <th style="text-align:center; width:120px;">Pending</th>
              <th style="text-align:center; width:120px;">Rejected</th>
              <th style="text-align:center; width:130px;">Verification %</th>
              <th style="text-align:center; width:150px;">Status</th>
            </tr>
          </thead>
          <tbody>
            <?php $catIdx = 0; foreach ($categories as $ck => $cat): $catIdx++; ?>
              <tr style="<?= $cat['has_records'] ? 'background:#F8FAFC; font-weight:600;' : 'opacity:0.75;' ?>">
                <td style="color:var(--ink-faint);"><?= $catIdx ?></td>
                <td>
                  <div style="display:flex; align-items:center; gap:8px;">
                    <span style="color:var(--orange); display:inline-flex;"><?= icon($cat['icon'], 16) ?></span>
                    <span><?= e($cat['label']) ?></span>
                  </div>
                </td>
                <td style="text-align:center; font-weight:700; color:var(--navy);"><?= (int) $cat['total'] ?></td>
                <td style="text-align:center; color:var(--success); font-weight:<?= $cat['approved'] > 0 ? '700' : '400' ?>;">
                  <?= (int) $cat['approved'] ?>
                </td>
                <td style="text-align:center; color:var(--orange); font-weight:<?= $cat['pending'] > 0 ? '700' : '400' ?>;">
                  <?= (int) $cat['pending'] ?>
                </td>
                <td style="text-align:center; color:var(--danger); font-weight:<?= $cat['rejected'] > 0 ? '700' : '400' ?>;">
                  <?= (int) $cat['rejected'] ?>
                </td>
                <td style="text-align:center;">
                  <?php if ($cat['total'] > 0): ?>
                    <span style="font-weight:700; color:<?= $cat['verification_pct'] >= 100 ? 'var(--success)' : 'var(--navy)' ?>;">
                      <?= $cat['verification_pct'] ?>%
                    </span>
                  <?php else: ?>
                    <span style="color:var(--ink-faint);">&mdash;</span>
                  <?php endif; ?>
                </td>
                <td style="text-align:center;">
                  <?php if ($cat['approved'] > 0): ?>
                    <span class="status-badge approved"><?= icon('check', 11) ?> Active</span>
                  <?php elseif ($cat['pending'] > 0): ?>
                    <span class="status-badge submitted"><?= icon('clock', 11) ?> Review</span>
                  <?php elseif ($cat['rejected'] > 0): ?>
                    <span class="status-badge rejected"><?= icon('x', 11) ?> Attention</span>
                  <?php else: ?>
                    <span class="status-badge draft" style="opacity:0.6;">None</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr>
              <td>&Sigma;</td>
              <td>Overall Personal Total (Reconciled)</td>
              <td style="text-align:center; font-size:14px; color:var(--navy);"><?= (int) $summary['total_records'] ?></td>
              <td style="text-align:center; font-size:14px; color:var(--success);"><?= (int) $summary['approved_records'] ?></td>
              <td style="text-align:center; font-size:14px; color:var(--orange);"><?= (int) $summary['pending_records'] ?></td>
              <td style="text-align:center; font-size:14px; color:var(--danger);"><?= (int) $summary['rejected_records'] ?></td>
              <td style="text-align:center; font-size:14px; color:var(--navy);"><?= $summary['verification_rate'] ?>%</td>
              <td style="text-align:center;">
                <span class="badge-pill brand"><?= (int) $summary['categories_contributed'] ?> Categories</span>
              </td>
            </tr>
          </tfoot>
        </table>
      </div>
    </section>

    <!-- =================================================================== -->
    <!-- SLIDES 3..N: INDIVIDUAL CATEGORY ACHIEVEMENT SLIDES                 -->
    <!-- =================================================================== -->
    <?php
    $currentSlideIndex = 2;
    if (empty($activeCategorySlides)):
      $currentSlideIndex++;
    ?>
      <!-- Empty state when faculty member has zero records in this academic year -->
      <section class="present-slide" data-slide-index="<?= $currentSlideIndex ?>">
        <div class="slide-header">
          <div class="slide-title-wrap">
            <h2><?= icon('info', 22) ?> NO ACHIEVEMENT RECORDS SUBMITTED</h2>
            <p>Personal achievements status for Academic Year <?= e($academicYear) ?></p>
          </div>
          <div class="slide-badge-wrap">
            <span class="badge-pill info">Empty Scope</span>
            <span class="badge-pill year"><?= e($academicYear) ?></span>
          </div>
        </div>

        <div class="panel-card" style="text-align:center; padding:60px 20px;">
          <div style="color:var(--orange); margin-bottom:16px;">
            <?= icon('file-stack', 48) ?>
          </div>
          <h3 style="font-size:18px; color:var(--navy); margin-bottom:8px;">No records filed for Academic Year <?= e($academicYear) ?></h3>
          <p style="font-size:14px; color:var(--ink-muted); max-width:540px; margin:0 auto 20px;">
            You have not submitted any achievement records for this academic year yet.
            To file publications, training, patents, or student records, use the Submit a Record action on your Faculty Dashboard.
          </p>
          <a class="btn-ctrl btn-primary" href="<?= e(url('upload.php?reset=1')) ?>">
            <?= icon('upload', 14) ?> Submit a Record
          </a>
        </div>
      </section>
    <?php
    else:
      foreach ($activeCategorySlides as $cs):
        $currentSlideIndex++;
    ?>
      <section class="present-slide" data-slide-index="<?= $currentSlideIndex ?>">
        <div class="slide-header">
          <div class="slide-title-wrap">
            <h2><?= icon($cs['icon'], 22) ?> <?= e($cs['slide_title']) ?></h2>
            <p>Personal achievement records filed by <strong><?= e($faculty['name']) ?></strong> (<?= e($faculty['department']) ?>) in Academic Year <?= e($academicYear) ?></p>
          </div>
          <div class="slide-badge-wrap">
            <span class="badge-pill brand"><?= e($cs['label']) ?></span>
            <span class="badge-pill info"><?= (int) $cs['total'] ?> Records</span>
            <span class="badge-pill success"><?= (int) $cs['approved'] ?> Approved</span>
          </div>
        </div>

        <!-- Category KPI Strip -->
        <div class="kpi-cards-grid" style="grid-template-columns: repeat(4, 1fr); margin-bottom:16px;">
          <div class="kpi-card accent-navy" style="padding:12px 16px;">
            <div class="kpi-top" style="margin-bottom:2px;"><span class="kpi-label">Category Total</span><div class="kpi-icon navy"><?= icon($cs['icon'], 14) ?></div></div>
            <div class="kpi-val" style="font-size:22px;"><?= (int) $cs['total'] ?></div>
          </div>
          <div class="kpi-card accent-green" style="padding:12px 16px;">
            <div class="kpi-top" style="margin-bottom:2px;"><span class="kpi-label">Approved</span><div class="kpi-icon green"><?= icon('check', 14) ?></div></div>
            <div class="kpi-val" style="font-size:22px; color:var(--success);"><?= (int) $cs['approved'] ?></div>
          </div>
          <div class="kpi-card accent-orange" style="padding:12px 16px;">
            <div class="kpi-top" style="margin-bottom:2px;"><span class="kpi-label">Pending / Review</span><div class="kpi-icon orange"><?= icon('clock', 14) ?></div></div>
            <div class="kpi-val" style="font-size:22px; color:var(--orange);"><?= (int) $cs['pending'] ?></div>
          </div>
          <div class="kpi-card accent-red" style="padding:12px 16px;">
            <div class="kpi-top" style="margin-bottom:2px;"><span class="kpi-label">Rejected</span><div class="kpi-icon red"><?= icon('x', 14) ?></div></div>
            <div class="kpi-val" style="font-size:22px; color:var(--danger);"><?= (int) $cs['rejected'] ?></div>
          </div>
        </div>

        <!-- Detail Records Table -->
        <div class="table-container">
          <table class="data-table">
            <thead>
              <tr>
                <th style="width:40px;">#</th>
                <th>Record Title &amp; Details</th>
                <th style="width:260px;">Category Attributes</th>
                <th style="width:140px; text-align:center;">Workflow Status</th>
                <th style="width:160px; text-align:center;">Proof / Supporting Link</th>
              </tr>
            </thead>
            <tbody>
              <?php $rIdx = 0; foreach ($cs['records'] as $r): $rIdx++; ?>
                <?php
                  $st = strtolower(str_replace(' ', '-', (string) $r['status']));
                  $statusClass = 'status-badge ' . ($st === 'approved' ? 'approved' : ($st === 'rejected' ? 'rejected' : ($st === 'dean-pending' ? 'dean-pending' : 'submitted')));
                ?>
                <tr>
                  <td style="color:var(--ink-faint); font-weight:700;"><?= $rIdx ?></td>
                  <td>
                    <div style="font-weight:700; color:var(--navy); font-size:13.5px; margin-bottom:3px;">
                      <?= e($r['title']) ?>
                    </div>
                    <?php if (!empty($r['meta_text'])): ?>
                      <div style="font-size:11.5px; color:var(--ink-muted);">
                        <?= e($r['meta_text']) ?>
                      </div>
                    <?php endif; ?>
                  </td>
                  <td>
                    <div style="font-size:12px; color:var(--ink);">
                      <span class="badge-pill info" style="font-size:10px; padding:2px 8px;"><?= e($cs['label']) ?></span>
                    </div>
                    <?php if (!empty($r['created_at'])): ?>
                      <div style="font-size:11px; color:var(--ink-faint); margin-top:3px;">
                        Filed: <?= e(substr((string) $r['created_at'], 0, 10)) ?>
                      </div>
                    <?php endif; ?>
                  </td>
                  <td style="text-align:center;">
                    <span class="<?= $statusClass ?>">
                      <?= icon($st === 'approved' ? 'check' : ($st === 'rejected' ? 'x' : 'clock'), 11) ?>
                      <?= e($r['status']) ?>
                    </span>
                  </td>
                  <td style="text-align:center;">
                    <?php if (!empty($r['proof_file'])): ?>
                      <a class="btn-action-proof" href="<?= e(url('proof.php?file=' . urlencode($r['proof_file']) . '&download=1')) ?>" target="_blank" rel="noopener" title="Download Official Proof Attachment">
                        <?= icon('download', 12) ?> View Proof
                      </a>
                    <?php elseif (!empty($r['doi'])): ?>
                      <a class="btn-action-ext" href="https://doi.org/<?= e(urlencode($r['doi'])) ?>" target="_blank" rel="noopener" title="Open DOI">
                        <?= icon('link', 12) ?> DOI Link
                      </a>
                    <?php elseif (!empty($r['doc_link'])): ?>
                      <a class="btn-action-ext" href="<?= e($r['doc_link']) ?>" target="_blank" rel="noopener" title="Open Supporting Link">
                        <?= icon('link', 12) ?> Supporting Link
                      </a>
                    <?php else: ?>
                      <span style="font-size:11.5px; color:var(--ink-faint);">No attachment</span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </section>
    <?php
      endforeach;
    endif;
    ?>

    <!-- =================================================================== -->
    <!-- FINAL SLIDE: FINAL FACULTY ACHIEVEMENT SUMMARY                      -->
    <!-- =================================================================== -->
    <?php $finalSlideIndex = $totalSlides; ?>
    <section class="present-slide" data-slide-index="<?= $finalSlideIndex ?>">
      <div class="slide-header">
        <div class="slide-title-wrap">
          <h2><?= icon('award', 22) ?> FINAL FACULTY ACHIEVEMENT SUMMARY</h2>
          <p>Executive achievement scorecard and submission reconciliation for <strong><?= e($faculty['name']) ?></strong> (<?= e($faculty['department']) ?>) &bull; Academic Year <strong><?= e($academicYear) ?></strong></p>
        </div>
        <div class="slide-badge-wrap">
          <span class="badge-pill brand"><?= icon('award', 12) ?> Final Scorecard</span>
          <span class="badge-pill dept"><?= e($faculty['department']) ?></span>
          <span class="badge-pill year"><?= e($academicYear) ?></span>
        </div>
      </div>

      <!-- 4 Scorecard KPI Cards -->
      <div class="kpi-cards-grid" style="grid-template-columns: repeat(4, 1fr);">
        <div class="kpi-card accent-navy">
          <div class="kpi-top">
            <span class="kpi-label">Total Records Filed</span>
            <div class="kpi-icon navy"><?= icon('file-stack', 16) ?></div>
          </div>
          <div class="kpi-val"><?= (int) $summary['total_records'] ?></div>
          <div class="kpi-sub">Total verified personal filings for <?= e($academicYear) ?></div>
        </div>

        <div class="kpi-card accent-green">
          <div class="kpi-top">
            <span class="kpi-label">Approved Achievements</span>
            <div class="kpi-icon green"><?= icon('check', 16) ?></div>
          </div>
          <div class="kpi-val" style="color:var(--success);"><?= (int) $summary['approved_records'] ?></div>
          <div class="kpi-sub">Officially accepted achievements</div>
        </div>

        <div class="kpi-card accent-orange">
          <div class="kpi-top">
            <span class="kpi-label">Pending Review</span>
            <div class="kpi-icon orange"><?= icon('clock', 16) ?></div>
          </div>
          <div class="kpi-val" style="color:var(--orange);"><?= (int) $summary['pending_records'] ?></div>
          <div class="kpi-sub">Submissions advancing through review pipeline</div>
        </div>

        <div class="kpi-card accent-red">
          <div class="kpi-top">
            <span class="kpi-label">Rejected Records</span>
            <div class="kpi-icon red"><?= icon('x', 16) ?></div>
          </div>
          <div class="kpi-val" style="color:var(--danger);"><?= (int) $summary['rejected_records'] ?></div>
          <div class="kpi-sub">Records needing clarification or update</div>
        </div>
      </div>

      <!-- Two Lower Reconciled Panels -->
      <div class="split-grid-2">
        <div class="panel-card">
          <div class="panel-head">
            <span class="panel-title"><?= icon('grid', 16) ?> Achievement Category Breakdown &amp; Reconciliation</span>
            <span class="badge-pill success"><?= (int) $summary['categories_contributed'] ?> Active Categories</span>
          </div>

          <div class="table-container" style="max-height:300px;">
            <table class="data-table">
              <thead>
                <tr>
                  <th>Category</th>
                  <th style="text-align:center;">Total</th>
                  <th style="text-align:center;">Approved</th>
                  <th style="text-align:center;">Pending</th>
                  <th style="text-align:center;">Rejected</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($activeCategorySlides)): ?>
                  <tr>
                    <td colspan="5" style="text-align:center; color:var(--ink-faint); padding:20px;">No categories with active filings</td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($activeCategorySlides as $acs): ?>
                    <tr>
                      <td>
                        <div style="display:flex; align-items:center; gap:6px;">
                          <span style="color:var(--orange);"><?= icon($acs['icon'], 14) ?></span>
                          <strong style="color:var(--navy);"><?= e($acs['label']) ?></strong>
                        </div>
                      </td>
                      <td style="text-align:center; font-weight:700; color:var(--navy);"><?= (int) $acs['total'] ?></td>
                      <td style="text-align:center; color:var(--success); font-weight:700;"><?= (int) $acs['approved'] ?></td>
                      <td style="text-align:center; color:var(--orange); font-weight:700;"><?= (int) $acs['pending'] ?></td>
                      <td style="text-align:center; color:var(--danger); font-weight:700;"><?= (int) $acs['rejected'] ?></td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
              <tfoot>
                <tr>
                  <td>Total (Reconciled)</td>
                  <td style="text-align:center; color:var(--navy); font-weight:800;"><?= (int) $summary['total_records'] ?></td>
                  <td style="text-align:center; color:var(--success); font-weight:800;"><?= (int) $summary['approved_records'] ?></td>
                  <td style="text-align:center; color:var(--orange); font-weight:800;"><?= (int) $summary['pending_records'] ?></td>
                  <td style="text-align:center; color:var(--danger); font-weight:800;"><?= (int) $summary['rejected_records'] ?></td>
                </tr>
              </tfoot>
            </table>
          </div>
        </div>

        <div class="panel-card">
          <div class="panel-head">
            <span class="panel-title"><?= icon('award', 16) ?> Key Achievement Highlights &amp; Scope</span>
            <span class="badge-pill brand"><?= e($facultyDisplayName) ?></span>
          </div>

          <div style="display:flex; flex-direction:column; gap:12px; font-size:13px; color:var(--ink);">
            <?php if (!empty($verifiedHighlights)): ?>
              <div style="background:var(--success-50); border:1px solid #A7F3D0; border-radius:var(--r-md); padding:12px 14px;">
                <div style="font-weight:700; color:var(--success); margin-bottom:4px; display:flex; align-items:center; gap:6px;">
                  <?= icon('check-circle', 15) ?> Verified Submissions Highlights
                </div>
                <ul style="margin-left:20px; color:var(--navy); font-size:12.5px; line-height:1.6;">
                  <?php foreach ($verifiedHighlights as $vh): ?>
                    <li><?= e($vh) ?></li>
                  <?php endforeach; ?>
                </ul>
              </div>
            <?php endif; ?>

            <div style="background:var(--navy-50); border:1px solid var(--hairline); border-radius:var(--r-md); padding:12px 14px;">
              <div style="font-weight:700; color:var(--navy); margin-bottom:4px; display:flex; align-items:center; gap:6px;">
                <?= icon('clock', 15) ?> Submissions Under Review Pipeline
              </div>
              <p style="font-size:12.5px; color:var(--ink-muted); margin:0;">
                <strong><?= (int) $summary['pending_records'] ?></strong> records currently in progress across departmental coordinator and Dean review stages.
              </p>
            </div>

            <div style="border-top:1px dashed var(--hairline); padding-top:10px; font-size:12px; color:var(--ink-muted); line-height:1.5;">
              <p><?= icon('shield', 13) ?> <strong>Read-Only Attestation:</strong> This presentation displays exclusively records owned by <strong><?= e($faculty['name']) ?></strong> (ID: <?= e((string) $faculty['id']) ?>) for Academic Year <strong><?= e($academicYear) ?></strong>. Department or institutional targets are excluded from this faculty scope.</p>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- Bottom Presentation Control Bar -->
    <footer class="present-bottom-bar">
      <div class="bottom-shortcuts-hint">
        <span><kbd class="shortcut-kbd">&larr;</kbd> Prev</span>
        <span><kbd class="shortcut-kbd">Space</kbd> / <kbd class="shortcut-kbd">&rarr;</kbd> Next</span>
        <span><kbd class="shortcut-kbd">Esc</kbd> Close</span>
      </div>

      <div class="slide-dots-container" id="slideDotsContainer">
        <?php for ($d = 1; $d <= $totalSlides; $d++): ?>
          <div class="slide-dot <?= $d === 1 ? 'active' : '' ?>" data-target-slide="<?= $d ?>" title="Go to Slide <?= $d ?>"></div>
        <?php endfor; ?>
      </div>

      <div class="bottom-scope-pill">
        <span class="badge-pill dept" style="font-size:11px;"><?= e($faculty['department']) ?></span>
        <span class="badge-pill brand" style="font-size:11px;">Faculty: <?= e($faculty['name']) ?></span>
      </div>
    </footer>

  </main>

  <script>
    (function() {
      'use strict';

      var currentSlide = 1;
      var totalSlides = <?= (int) $totalSlides ?>;
      var isAuto = false;
      var autoTimer = null;
      var isEmbedded = <?= $isEmbedded ? 'true' : 'false' ?>;
      var exitUrl = <?= json_encode($exitUrl) ?>;

      var slides = document.querySelectorAll('.present-slide');
      var dots = document.querySelectorAll('.slide-dot');
      var slideCounterText = document.getElementById('slideCounterDisplay');
      var btnPrev = document.getElementById('btnPrev');
      var btnNext = document.getElementById('btnNext');
      var btnAuto = document.getElementById('btnAuto');
      var autoBtnText = document.getElementById('autoBtnText');
      var btnFullscreen = document.getElementById('btnFullscreen');
      var btnClose = document.getElementById('btnClose');

      function showSlide(index) {
        if (index < 1) index = 1;
        if (index > totalSlides) index = totalSlides;
        currentSlide = index;

        slides.forEach(function(s) {
          var sIdx = parseInt(s.getAttribute('data-slide-index'), 10);
          if (sIdx === currentSlide) {
            s.classList.add('active');
          } else {
            s.classList.remove('active');
          }
        });

        dots.forEach(function(d) {
          var dIdx = parseInt(d.getAttribute('data-target-slide'), 10);
          if (dIdx === currentSlide) {
            d.classList.add('active');
          } else {
            d.classList.remove('active');
          }
        });

        if (slideCounterText) {
          slideCounterText.textContent = 'Slide ' + currentSlide + ' of ' + totalSlides;
        }

        if (btnPrev) {
          btnPrev.disabled = (currentSlide === 1);
          btnPrev.style.opacity = (currentSlide === 1) ? '0.4' : '1';
        }
        if (btnNext) {
          btnNext.disabled = (currentSlide === totalSlides);
          btnNext.style.opacity = (currentSlide === totalSlides) ? '0.4' : '1';
        }
      }

      function nextSlide() {
        if (currentSlide < totalSlides) {
          showSlide(currentSlide + 1);
        } else if (isAuto) {
          showSlide(1);
        }
      }

      function prevSlide() {
        if (currentSlide > 1) {
          showSlide(currentSlide - 1);
        }
      }

      function toggleAuto() {
        if (isAuto) {
          stopAuto();
        } else {
          startAuto();
        }
      }

      function startAuto() {
        isAuto = true;
        if (btnAuto) btnAuto.classList.add('active');
        if (autoBtnText) autoBtnText.textContent = 'Pause';
        if (autoTimer) clearInterval(autoTimer);
        autoTimer = setInterval(nextSlide, 5000);
      }

      function stopAuto() {
        isAuto = false;
        if (btnAuto) btnAuto.classList.remove('active');
        if (autoBtnText) autoBtnText.textContent = 'Auto';
        if (autoTimer) {
          clearInterval(autoTimer);
          autoTimer = null;
        }
      }

      function closePresentation() {
        stopAuto();
        if (isEmbedded || (window.parent && window.parent !== window)) {
          window.parent.postMessage({ atts: 'targets-present-close' }, '*');
        } else {
          window.location.href = exitUrl;
        }
      }

      function toggleFullscreen() {
        if (!document.fullscreenElement) {
          document.documentElement.requestFullscreen().catch(function() {});
        } else {
          if (document.exitFullscreen) {
            document.exitFullscreen().catch(function() {});
          }
        }
      }

      // Event listeners
      if (btnPrev) btnPrev.addEventListener('click', function() { stopAuto(); prevSlide(); });
      if (btnNext) btnNext.addEventListener('click', function() { stopAuto(); nextSlide(); });
      if (btnAuto) btnAuto.addEventListener('click', toggleAuto);
      if (btnFullscreen) btnFullscreen.addEventListener('click', toggleFullscreen);
      if (btnClose) btnClose.addEventListener('click', closePresentation);

      // Dot Clicks
      dots.forEach(function(dot) {
        dot.addEventListener('click', function() {
          stopAuto();
          var tIdx = parseInt(this.getAttribute('data-target-slide'), 10);
          showSlide(tIdx);
        });
      });

      // Keyboard Controls
      window.addEventListener('keydown', function(e) {
        var tag = (document.activeElement || {}).tagName || '';
        if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return;

        if (e.key === 'ArrowRight' || e.key === ' ' || e.key === 'PageDown') {
          e.preventDefault();
          stopAuto();
          nextSlide();
        } else if (e.key === 'ArrowLeft' || e.key === 'PageUp') {
          e.preventDefault();
          stopAuto();
          prevSlide();
        } else if (e.key === 'Escape') {
          e.preventDefault();
          closePresentation();
        }
      });

      // Touch swipe
      var touchStartX = 0;
      var touchEndX = 0;
      document.addEventListener('touchstart', function(e) {
        touchStartX = e.changedTouches[0].screenX;
      }, false);
      document.addEventListener('touchend', function(e) {
        touchEndX = e.changedTouches[0].screenX;
        var diff = touchEndX - touchStartX;
        if (Math.abs(diff) > 50) {
          stopAuto();
          if (diff < 0) nextSlide();
          else prevSlide();
        }
      }, false);

      // Initialize slide 1
      showSlide(1);
    })();
  </script>
</body>
</html>
