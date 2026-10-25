<?php
/**
 * Interactive Targets Presentation View
 * ATTS IQAC Portal — Mohamed Sathak Engineering College
 *
 * Implements the full 38-slide Institutional Academic Targets Presentation:
 * Slide 1: Overall Institutional Target Summary (Ref 2 Layout)
 * Slide 2: Admin Summary
 * Slide 3: Dean Summary
 * Slide 4: Principal Summary
 * Slide 5: Overall Target Fixed vs Target Achieved
 * Slide 6: Department-Wise Target Performance
 * Slide 7: All Departments Contribution (Ref 1 Layout)
 * Slides 8–37: The 30 individually configured Institutional Academic Target sections
 * Slide 38: Final Institutional Target Performance Summary Scorecard
 */

declare(strict_types=1);

require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/icons.php';
require_once __DIR__ . '/models/TargetPresentation.php';

$user = require_login();

$userRole = (string) ($user['role'] ?? '');

// =========================================================================
// FACULTY ACHIEVEMENT PRESENTATION (Role: Faculty)
// =========================================================================
if ($userRole === 'Faculty') {
    require_once __DIR__ . '/models/FacultyTargetPresentation.php';

    // 1. Strict Server-Side Security Checks:
    // Block any attempt to inspect another faculty member's presentation or department via URL parameters
    $reqFacultyId = input('id') ?: input('faculty_id') ?: input('user_id') ?: null;
    if ($reqFacultyId !== null && (int) $reqFacultyId !== (int) $user['id']) {
        http_response_code(403);
        echo "<!DOCTYPE html><html lang='en'><head><meta charset='UTF-8'><title>403 Forbidden</title></head><body style='font-family:sans-serif; background:#0D1427; color:#fff; display:flex; align-items:center; justify-content:center; height:100vh; margin:0;'><div style='text-align:center; padding:30px; background:#1E2B52; border-radius:10px;'><h2>403 Forbidden</h2><p>You are not authorized to view another faculty member's achievement presentation.</p></div></body></html>";
        exit;
    }

    $reqFacultyName = input('faculty') ?: input('name') ?: null;
    if ($reqFacultyName !== null && strcasecmp(trim((string) $reqFacultyName), trim((string) $user['name'])) !== 0) {
        http_response_code(403);
        echo "<!DOCTYPE html><html lang='en'><head><meta charset='UTF-8'><title>403 Forbidden</title></head><body style='font-family:sans-serif; background:#0D1427; color:#fff; display:flex; align-items:center; justify-content:center; height:100vh; margin:0;'><div style='text-align:center; padding:30px; background:#1E2B52; border-radius:10px;'><h2>403 Forbidden</h2><p>You are not authorized to view another faculty member's achievement presentation.</p></div></body></html>";
        exit;
    }

    $reqDept = trim((string) (input('dept') ?: input('department') ?: ''));
    $userDept = trim((string) ($user['department'] ?? ''));
    if ($reqDept !== '' && !department_names_match($reqDept, $userDept)) {
        http_response_code(403);
        echo "<!DOCTYPE html><html lang='en'><head><meta charset='UTF-8'><title>403 Forbidden</title></head><body style='font-family:sans-serif; background:#0D1427; color:#fff; display:flex; align-items:center; justify-content:center; height:100vh; margin:0;'><div style='text-align:center; padding:30px; background:#1E2B52; border-radius:10px;'><h2>403 Forbidden</h2><p>You are not authorized to view another department's presentation.</p></div></body></html>";
        exit;
    }

    $reqRole = input('role');
    if ($reqRole !== null && trim((string) $reqRole) !== '' && trim((string) $reqRole) !== 'Faculty') {
        http_response_code(403);
        echo "<!DOCTYPE html><html lang='en'><head><meta charset='UTF-8'><title>403 Forbidden</title></head><body style='font-family:sans-serif; background:#0D1427; color:#fff; display:flex; align-items:center; justify-content:center; height:100vh; margin:0;'><div style='text-align:center; padding:30px; background:#1E2B52; border-radius:10px;'><h2>403 Forbidden</h2><p>Invalid role request.</p></div></body></html>";
        exit;
    }

    // 2. Academic Year Synchronization:
    // Respect authorized selection from dashboard or default to centralized active academic year
    $rawYear = trim((string) (input('academic_year') ?: input('year') ?: ''));
    $allowedYears = academic_years();
    if ($rawYear !== '' && in_array($rawYear, $allowedYears, true)) {
        $academicYear = $rawYear;
    } else {
        $academicYear = active_academic_year();
    }

    // 3. Load dataset strictly scoped to authenticated user
    $dataset    = faculty_target_presentation_dataset($user, $academicYear);
    $isEmbedded = ((string) input('embed') === '1');
    $exitUrl    = url('dashboard.php');

    require __DIR__ . '/views/present_faculty_targets.php';
    exit;
}

$isHoDRole = in_array($userRole, ['HoD', 'Coordinator'], true);
$requestedDept = trim((string) (input('dept') ?: input('department') ?: ''));

if ($isHoDRole) {
    $authorizedDept = trim((string) ($user['department'] ?? ''));
    if ($authorizedDept === '') {
        http_response_code(403);
        echo "<!DOCTYPE html><html lang='en'><head><meta charset='UTF-8'><title>403 Forbidden</title></head><body style='font-family:sans-serif; background:#0D1427; color:#fff; display:flex; align-items:center; justify-content:center; height:100vh; margin:0;'><div style='text-align:center; padding:30px; background:#1E2B52; border-radius:10px;'><h2>403 Forbidden</h2><p>No department is assigned to your account.</p></div></body></html>";
        exit;
    }
    // Server-side security check: Block attempting to inspect another department via URL parameter
    if ($requestedDept !== '' && !department_names_match($requestedDept, $authorizedDept)) {
        http_response_code(403);
        echo "<!DOCTYPE html><html lang='en'><head><meta charset='UTF-8'><title>403 Forbidden</title></head><body style='font-family:sans-serif; background:#0D1427; color:#fff; display:flex; align-items:center; justify-content:center; height:100vh; margin:0;'><div style='text-align:center; padding:30px; background:#1E2B52; border-radius:10px;'><h2>403 Forbidden</h2><p>You are not authorized to view another department's targets presentation.</p></div></body></html>";
        exit;
    }
    $department = $authorizedDept;
    $isDepartmentMode = true;
} else {
    // Admin, Director, Principal, Dean can view a specific department presentation if requested
    if ($requestedDept !== '') {
        $department = $requestedDept;
        $isDepartmentMode = true;
    } else {
        $department = null;
        $isDepartmentMode = false;
    }
}

if ($isDepartmentMode) {
    // Department-Specific Presentation (35 Slides)
    $deptDataset = department_presentation_dataset($department, $user);
    $academicYear         = $deptDataset['academic_year'];
    $summary              = $deptDataset['summary'];
    $classifiedTargets    = $deptDataset['targets'];
    $facultyContributions = $deptDataset['faculty_contributions'];
    $sections30           = $deptDataset['sections_30'];
    $attentionCategories  = $deptDataset['attention_categories'];
    $totalUploadedRecords = $deptDataset['total_uploaded_records'];
    $totalSlides          = 35;
} else {
    // Institutional Presentation for authorized roles (38 Slides)
    $dataset = targets_presentation_dataset($user);
    $academicYear     = $dataset['academic_year'];
    $summary          = $dataset['summary'];
    $adminSummary     = $dataset['admin_summary'];
    $deanSummary      = $dataset['dean_summary'];
    $principalSummary = $dataset['principal_summary'];
    $topDepartments   = $dataset['top_departments'];
    $allDepartments   = $dataset['all_departments'];
    $sections30       = $dataset['sections_30'];
    $allTargets       = $dataset['targets'];
    $totalSlides      = 38;
}

$isEmbedded = ((string) input('embed') === '1');
$exitUrl    = url('dashboard.php');
if (!empty($_SERVER['HTTP_REFERER']) && strpos($_SERVER['HTTP_REFERER'], 'targets.php') !== false) {
    $exitUrl = url('targets.php');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= $isDepartmentMode ? e($department) . ' — Department Targets Presentation' : 'Institutional Academic Targets Presentation' ?> — Academic Year <?= e($academicYear) ?> — ATTS IQAC</title>
  <?php require __DIR__ . '/inc/favicon.php'; ?>
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

    .badge-pill.success {
      background: var(--success-50);
      color: var(--success);
      border: 1px solid #A7F3D0;
    }

    .badge-pill.warning {
      background: var(--warning-50);
      color: #D97706;
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

    .badge-pill.neutral {
      background: #F1F5F9;
      color: #475569;
      border: 1px solid #CBD5E1;
    }

    /* KPI Cards Grid */
    .kpi-cards-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
      gap: 16px;
      margin-bottom: 24px;
    }

    .kpi-card {
      background: var(--surface);
      border: 1px solid var(--hairline);
      border-radius: var(--r-lg);
      padding: 20px 18px;
      box-shadow: var(--shadow-sm);
      position: relative;
      overflow: hidden;
      display: flex;
      flex-direction: column;
      justify-content: space-between;
    }

    .kpi-card::before {
      content: "";
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      height: 4px;
      background: var(--hairline);
    }

    .kpi-card.accent-navy::before   { background: var(--navy); }
    .kpi-card.accent-orange::before { background: var(--orange); }
    .kpi-card.accent-green::before  { background: var(--success); }
    .kpi-card.accent-blue::before   { background: var(--info); }
    .kpi-card.accent-red::before    { background: var(--danger); }

    .kpi-top {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 10px;
    }

    .kpi-label {
      font-size: 12px;
      font-weight: 700;
      color: var(--ink-muted);
      text-transform: uppercase;
      letter-spacing: 0.05em;
    }

    .kpi-icon {
      width: 32px;
      height: 32px;
      border-radius: var(--r-sm);
      display: flex;
      align-items: center;
      justify-content: center;
    }

    .kpi-icon.navy   { background: var(--navy-50); color: var(--navy); }
    .kpi-icon.orange { background: var(--orange-50); color: var(--orange); }
    .kpi-icon.green  { background: var(--success-50); color: var(--success); }
    .kpi-icon.blue   { background: var(--info-50); color: var(--info); }
    .kpi-icon.red    { background: var(--danger-50); color: var(--danger); }

    .kpi-val {
      font-size: 32px;
      font-weight: 800;
      color: var(--navy);
      line-height: 1.1;
      margin-bottom: 4px;
      letter-spacing: -0.02em;
    }

    .kpi-sub {
      font-size: 12px;
      color: var(--ink-faint);
    }

    /* Split Grids */
    .split-grid-2 {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 20px;
      margin-bottom: 20px;
    }

    @media (max-width: 992px) {
      .split-grid-2 {
        grid-template-columns: 1fr;
      }
    }

    /* Panel Card */
    .panel-card {
      background: var(--surface);
      border: 1px solid var(--hairline);
      border-radius: var(--r-lg);
      padding: 20px;
      box-shadow: var(--shadow-sm);
    }

    .panel-head {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 16px;
      padding-bottom: 12px;
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

    /* Present Tables */
    .table-container {
      width: 100%;
      overflow-x: auto;
      border: 1px solid var(--hairline);
      border-radius: var(--r-md);
      background: var(--surface);
    }

    .present-table {
      width: 100%;
      border-collapse: collapse;
      text-align: left;
      font-size: 13px;
    }

    .present-table th {
      background: var(--navy-50);
      color: var(--navy);
      font-weight: 700;
      padding: 12px 14px;
      border-bottom: 2px solid var(--hairline);
      font-size: 12px;
      text-transform: uppercase;
      letter-spacing: 0.04em;
    }

    .present-table td {
      padding: 12px 14px;
      border-bottom: 1px solid var(--hairline-soft);
      color: var(--ink);
    }

    .present-table tr:last-child td {
      border-bottom: none;
    }

    .present-table tbody tr:hover {
      background: #F8FAFC;
    }

    .present-table tfoot td {
      background: var(--navy-50);
      font-weight: 800;
      border-top: 2px solid var(--hairline);
    }

    .num-align {
      text-align: right;
      font-variant-numeric: tabular-nums;
    }

    /* Progress bar */
    .progress-track {
      background: #E2E8F0;
      border-radius: 10px;
      height: 10px;
      overflow: hidden;
      width: 100%;
    }

    .progress-fill {
      background: var(--orange);
      height: 100%;
      border-radius: 10px;
      transition: width 0.3s ease;
    }

    .progress-fill.success {
      background: var(--success);
    }

    /* Podium Cards for Top 3 */
    .podium-grid {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 16px;
      margin-bottom: 20px;
    }

    @media (max-width: 768px) {
      .podium-grid {
        grid-template-columns: 1fr;
      }
    }

    .podium-card {
      background: var(--surface);
      border: 1px solid var(--hairline);
      border-radius: var(--r-md);
      padding: 18px 16px;
      text-align: center;
      position: relative;
      box-shadow: var(--shadow-sm);
    }

    .podium-rank-badge {
      width: 34px;
      height: 34px;
      border-radius: 50%;
      background: var(--navy);
      color: #FFFFFF;
      font-weight: 800;
      font-size: 15px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      margin-bottom: 10px;
    }

    .podium-card:nth-child(1) .podium-rank-badge {
      background: var(--orange);
      box-shadow: 0 0 0 3px var(--orange-100);
    }

    .podium-dept-name {
      font-size: 18px;
      font-weight: 800;
      color: var(--navy);
      margin-bottom: 4px;
    }

    .podium-pct {
      font-size: 26px;
      font-weight: 800;
      color: var(--orange);
      margin-bottom: 8px;
    }

    .podium-stats {
      font-size: 12px;
      color: var(--ink-muted);
      line-height: 1.6;
    }

    /* Comparison Bar Row */
    .bar-row {
      display: flex;
      align-items: center;
      gap: 12px;
      margin-bottom: 10px;
      font-size: 13px;
    }

    .bar-row-label {
      width: 90px;
      font-weight: 700;
      color: var(--navy);
      flex-shrink: 0;
    }

    .bar-row-track {
      flex: 1;
      background: var(--navy-50);
      border-radius: 6px;
      height: 22px;
      overflow: hidden;
      position: relative;
    }

    .bar-row-fill {
      height: 100%;
      border-radius: 6px;
      transition: width 0.3s ease;
    }

    .bar-row-val {
      width: 60px;
      text-align: right;
      font-weight: 700;
      font-size: 12px;
      color: var(--navy);
      flex-shrink: 0;
    }

    /* Bottom Navigation / Hints */
    .present-footer {
      background: var(--navy-dark);
      color: #FFFFFF;
      padding: 10px 24px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      border-top: 1px solid rgba(255, 255, 255, 0.1);
      font-size: 12px;
      position: sticky;
      bottom: 0;
      z-index: 100;
    }

    .footer-hints {
      display: flex;
      align-items: center;
      gap: 16px;
      color: #94A3B8;
    }

    .footer-hint-item {
      display: inline-flex;
      align-items: center;
      gap: 6px;
    }

    .footer-hint-key {
      background: rgba(255, 255, 255, 0.15);
      color: #E2E8F0;
      padding: 1px 6px;
      border-radius: 4px;
      font-weight: 700;
      font-size: 11px;
    }

    .footer-progress-dots {
      display: flex;
      align-items: center;
      gap: 4px;
      max-width: 50vw;
      overflow-x: auto;
      padding: 4px 0;
    }

    .dot-step {
      width: 8px;
      height: 8px;
      border-radius: 50%;
      background: rgba(255, 255, 255, 0.25);
      cursor: pointer;
      transition: all 0.2s ease;
      flex-shrink: 0;
    }

    .dot-step.active {
      background: var(--orange);
      transform: scale(1.3);
    }

    .empty-box {
      background: var(--navy-50);
      border: 1px dashed var(--hairline);
      border-radius: var(--r-md);
      padding: 28px 20px;
      text-align: center;
      color: var(--ink-muted);
      font-size: 13px;
    }
  </style>
</head>
<body>

  <!-- Top Presentation Header -->
  <header class="present-header">
    <div class="present-brand">
      <span class="present-logo-badge"><?= icon('target', 16) ?> ATTS IQAC <?= $isDepartmentMode ? '&middot; ' . e($department) : '' ?></span>
      <div class="present-title-group">
        <h1><?= $isDepartmentMode ? e($department) . ' &middot; Department Targets Presentation' : 'Institutional Academic Targets Presentation' ?></h1>
        <p>Mohamed Sathak Engineering College &middot; <?= $isDepartmentMode ? 'Department Quality Assurance' : 'Quality Assurance Cell' ?></p>
      </div>
    </div>

    <div class="header-center-info">
      <?php if ($isDepartmentMode): ?>
        <div class="header-year-pill">
          <span class="header-year-dot"></span>
          <span>Department: <?= e($department) ?></span>
        </div>
      <?php endif; ?>
      <div class="header-year-pill">
        <span class="header-year-dot"></span>
        <span>Academic Year: <?= e($academicYear) ?></span>
      </div>
      <div class="slide-counter-display" id="slideCounterText">
        Slide 1 of <?= $totalSlides ?>
      </div>
    </div>

    <div class="present-controls">
      <button type="button" class="btn-ctrl" id="btnPrevSlide" title="Previous Slide (←)">
        <?= icon('chevron-left', 14) ?> Prev
      </button>
      <button type="button" class="btn-ctrl btn-primary" id="btnNextSlide" title="Next Slide (→)">
        Next <?= icon('chevron-right', 14) ?>
      </button>
      <button type="button" class="btn-ctrl" id="btnAutoAdvance" title="Toggle Auto Advance">
        <?= icon('clock', 14) ?> <span id="autoBtnText">Auto</span>
      </button>
      <button type="button" class="btn-ctrl" id="btnFullscreen" title="Toggle Fullscreen">
        <?= icon('maximize', 14) ?>
      </button>
      <button type="button" class="btn-ctrl btn-close-present" id="btnClosePresentation" title="Close Presentation (Esc)">
        <?= icon('x', 14) ?> Close
      </button>
    </div>
  </header>

  <!-- Slide Viewport -->
  <main class="present-viewport">

  <?php if ($isDepartmentMode): ?>
    <?php require __DIR__ . '/views/department_targets_presentation.php'; ?>
  <?php else: ?>

    <!-- =================================================================== -->
    <!-- SLIDE 1: Overall Institutional Target Summary (Reference 2 Layout) -->
    <!-- =================================================================== -->
    <section class="present-slide active" data-slide-index="1">
      <div class="slide-header" style="margin-bottom:18px;">
        <div class="slide-title-wrap">
          <div style="font-size:12px; font-weight:800; color:var(--orange); text-transform:uppercase; letter-spacing:0.08em; margin-bottom:4px;">
            ATTS IQAC &middot; MOHAMED SATHAK ENGINEERING COLLEGE
          </div>
          <h2 style="font-size:24px; font-weight:800; color:var(--navy);">
            <?= icon('target', 24) ?> Institutional Academic Targets — Overall Summary
          </h2>
          <p>Active Academic Year: <strong><?= e($academicYear) ?></strong> &middot; Tracked benchmarks, verified departmental evidence, and institutional attainment</p>
        </div>
        <div class="slide-badge-wrap">
          <span class="badge-pill brand"><?= icon('calendar', 13) ?> Academic Year <?= e($academicYear) ?></span>
          <span class="badge-pill success"><?= icon('shield', 13) ?> IQAC Verified</span>
        </div>
      </div>

      <!-- 4 Large KPI Cards (Reference 2 Layout) -->
      <div class="kpi-cards-grid" style="grid-template-columns: repeat(4, 1fr); margin-bottom:20px;">
        <div class="kpi-card accent-navy" style="padding:22px 18px;">
          <div class="kpi-top">
            <span class="kpi-label">Total Academic Targets</span>
            <div class="kpi-icon navy"><?= icon('target', 18) ?></div>
          </div>
          <div class="kpi-val"><?= (int) $summary['total_targets'] ?></div>
          <div class="kpi-sub">Institutional benchmarks configured for <?= e($academicYear) ?></div>
        </div>

        <div class="kpi-card accent-green" style="padding:22px 18px;">
          <div class="kpi-top">
            <span class="kpi-label">Targets Achieved</span>
            <div class="kpi-icon green"><?= icon('check', 18) ?></div>
          </div>
          <div class="kpi-val" style="color:var(--success);"><?= (int) $summary['achieved_targets'] ?></div>
          <div class="kpi-sub"><?= $summary['targets_met_pct'] ?>% of configured benchmarks fulfilled</div>
        </div>

        <div class="kpi-card accent-orange" style="padding:22px 18px;">
          <div class="kpi-top">
            <span class="kpi-label">Pending / In Progress</span>
            <div class="kpi-icon orange"><?= icon('clock', 18) ?></div>
          </div>
          <div class="kpi-val" style="color:var(--orange);"><?= (int) $summary['in_progress_targets'] ?></div>
          <div class="kpi-sub">Active cycles advancing toward targets</div>
        </div>

        <div class="kpi-card accent-red" style="padding:22px 18px;">
          <div class="kpi-top">
            <span class="kpi-label">Not Completed</span>
            <div class="kpi-icon red"><?= icon('alert-triangle', 18) ?></div>
          </div>
          <div class="kpi-val" style="color:var(--danger);"><?= (int) $summary['not_completed_targets'] ?></div>
          <div class="kpi-sub">Benchmarks incomplete where official deadline has elapsed</div>
        </div>
      </div>

      <!-- Quantitative Aggregate Metrics -->
      <div class="split-grid-2">
        <div class="panel-card">
          <div class="panel-head">
            <span class="panel-title"><?= icon('bar-chart', 16) ?> Target Fixed vs Target Achieved Values</span>
            <span class="badge-pill info">Institutional Aggregate</span>
          </div>
          <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-bottom:16px;">
            <div style="background:var(--navy-50); padding:16px; border-radius:var(--r-md); border:1px solid var(--hairline);">
              <div style="font-size:11.5px; font-weight:700; color:var(--ink-muted); text-transform:uppercase;">Total Target Fixed</div>
              <div style="font-size:26px; font-weight:800; color:var(--navy); margin-top:4px;"><?= number_format($summary['total_target_fixed']) ?></div>
              <div style="font-size:11.5px; color:var(--ink-faint); margin-top:2px;">Cumulative benchmark goal</div>
            </div>
            <div style="background:var(--navy-50); padding:16px; border-radius:var(--r-md); border:1px solid var(--hairline);">
              <div style="font-size:11.5px; font-weight:700; color:var(--ink-muted); text-transform:uppercase;">Total Achieved</div>
              <div style="font-size:26px; font-weight:800; color:var(--success); margin-top:4px;"><?= number_format($summary['total_target_achieved']) ?></div>
              <div style="font-size:11.5px; color:var(--ink-faint); margin-top:2px;">Cumulative verified submissions</div>
            </div>
          </div>
          <div class="progress-track" style="height:14px;">
            <div class="progress-fill <?= $summary['overall_attainment_pct'] >= 100 ? 'success' : '' ?>" style="width:<?= min(100, $summary['overall_attainment_pct']) ?>%;"></div>
          </div>
          <div style="display:flex; justify-content:space-between; font-size:12px; margin-top:6px; color:var(--ink-muted);">
            <span>Overall Attainment Progress</span>
            <strong style="color:var(--navy);"><?= $summary['overall_attainment_pct'] ?>%</strong>
          </div>
        </div>

        <div class="panel-card">
          <div class="panel-head">
            <span class="panel-title"><?= icon('shield', 16) ?> Target Status Definitions &amp; Business Rules</span>
          </div>
          <div style="display:flex; flex-direction:column; gap:12px; font-size:13px; color:var(--ink);">
            <div style="display:flex; align-items:flex-start; gap:10px;">
              <span class="badge-pill success" style="width:140px; justify-content:center; flex-shrink:0;">Achieved</span>
              <span>Target genuinely met or exceeded through coordinator-approved submissions.</span>
            </div>
            <div style="display:flex; align-items:flex-start; gap:10px;">
              <span class="badge-pill warning" style="width:140px; justify-content:center; flex-shrink:0;">Pending / In Progress</span>
              <span>Under active tracking cycle or awaiting review where deadline has not yet elapsed.</span>
            </div>
            <div style="display:flex; align-items:flex-start; gap:10px;">
              <span class="badge-pill danger" style="width:140px; justify-content:center; flex-shrink:0;">Not Completed</span>
              <span>Target deadline has elapsed without achieving the configured institutional goal.</span>
            </div>
            <div style="display:flex; align-items:flex-start; gap:10px; margin-top:4px; padding-top:10px; border-top:1px dashed var(--hairline); font-size:12px; color:var(--ink-muted);">
              <span><?= icon('info', 13) ?> Approval workflow status (e.g. Dean Pending) is maintained distinctly from target achievement status.</span>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- =================================================================== -->
    <!-- SLIDE 2: Admin Summary                                             -->
    <!-- =================================================================== -->
    <section class="present-slide" data-slide-index="2">
      <div class="slide-header">
        <div class="slide-title-wrap">
          <h2><?= icon('shield', 20) ?> Admin Executive Summary</h2>
          <p>Institution-wide governance overview, compliance benchmarks, and strategic interventions</p>
        </div>
        <div class="slide-badge-wrap">
          <span class="badge-pill info"><?= icon('user', 13) ?> Admin Scope</span>
          <span class="badge-pill success"><?= icon('check', 13) ?> Audit Ready</span>
        </div>
      </div>

      <div class="kpi-cards-grid">
        <div class="kpi-card accent-navy">
          <div class="kpi-top"><span class="kpi-label">Total Targets</span><div class="kpi-icon navy"><?= icon('target', 16) ?></div></div>
          <div class="kpi-val"><?= (int) $adminSummary['total_targets'] ?></div>
          <div class="kpi-sub">Institutional benchmarks</div>
        </div>
        <div class="kpi-card accent-green">
          <div class="kpi-top"><span class="kpi-label">Targets Achieved</span><div class="kpi-icon green"><?= icon('check', 16) ?></div></div>
          <div class="kpi-val" style="color:var(--success);"><?= (int) $adminSummary['achieved_targets'] ?></div>
          <div class="kpi-sub">Completed benchmarks</div>
        </div>
        <div class="kpi-card accent-orange">
          <div class="kpi-top"><span class="kpi-label">In Progress</span><div class="kpi-icon orange"><?= icon('clock', 16) ?></div></div>
          <div class="kpi-val" style="color:var(--orange);"><?= (int) $adminSummary['in_progress_targets'] ?></div>
          <div class="kpi-sub">Active cycles advancing</div>
        </div>
        <div class="kpi-card accent-red">
          <div class="kpi-top"><span class="kpi-label">Not Completed</span><div class="kpi-icon red"><?= icon('alert-triangle', 16) ?></div></div>
          <div class="kpi-val" style="color:var(--danger);"><?= (int) $adminSummary['not_completed_targets'] ?></div>
          <div class="kpi-sub">Past target deadline</div>
        </div>
      </div>

      <div class="split-grid-2">
        <div class="panel-card">
          <div class="panel-head">
            <span class="panel-title"><?= icon('alert-triangle', 16) ?> Categories Requiring Administrative Attention</span>
            <span class="badge-pill warning">Priority Action</span>
          </div>
          <?php if (empty($adminSummary['attention_categories'])): ?>
            <div class="empty-box">All active categories are progressing satisfactorily with attainment above 50%.</div>
          <?php else: ?>
            <div style="display:flex; flex-direction:column; gap:12px;">
              <?php foreach ($adminSummary['attention_categories'] as $att): ?>
                <div style="background:var(--navy-50); padding:12px 14px; border-radius:var(--r-md); border:1px solid var(--hairline);">
                  <div style="display:flex; justify-content:space-between; align-items:center;">
                    <strong style="color:var(--navy); font-size:13px;"><?= e($att['title']) ?></strong>
                    <span class="badge-pill danger"><?= $att['attainment_pct'] ?>% Attained</span>
                  </div>
                  <div style="display:flex; justify-content:space-between; font-size:12px; color:var(--ink-muted); margin-top:6px;">
                    <span>Goal: <?= number_format($att['target_fixed']) ?> &middot; Achieved: <?= number_format($att['achieved_value']) ?></span>
                    <?php if ($att['not_completed'] > 0): ?>
                      <span style="color:var(--danger); font-weight:600;"><?= $att['not_completed'] ?> deadline(s) elapsed</span>
                    <?php endif; ?>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>

        <div class="panel-card">
          <div class="panel-head">
            <span class="panel-title"><?= icon('building', 16) ?> Department Overview &amp; Attainment Lead</span>
            <span class="badge-pill brand">Participation</span>
          </div>
          <?php if (!empty($adminSummary['top_department'])): ?>
            <div style="background:var(--orange-50); border:1px solid var(--orange-100); border-radius:var(--r-md); padding:16px; margin-bottom:14px;">
              <div style="font-size:12px; font-weight:700; color:var(--orange); text-transform:uppercase;">Top Performing Department</div>
              <div style="font-size:22px; font-weight:800; color:var(--navy); margin-top:2px;"><?= e($adminSummary['top_department']['department']) ?></div>
              <div style="font-size:12.5px; color:var(--ink-muted); margin-top:4px;">
                Attainment: <strong><?= $adminSummary['top_department']['attainment_pct'] ?>%</strong> &middot;
                Verified Submissions: <strong><?= number_format($adminSummary['top_department']['achieved_value']) ?></strong>
              </div>
            </div>
          <?php endif; ?>

          <div style="font-size:13px; color:var(--ink-muted); line-height:1.6;">
            <p><strong>Cycle Governance:</strong> <?= $adminSummary['active_cycle_open'] ? 'Cycle is currently OPEN for Coordinator and Faculty uploads.' : 'Cycle is LOCKED following Executive Meeting review.' ?></p>
            <p style="margin-top:6px;"><strong>Verified Uploaded Records:</strong> <?= number_format($adminSummary['total_uploaded_records']) ?> records verified across academic departments.</p>
            <p style="margin-top:6px;"><strong>Pending Review Workload:</strong> <?= number_format($adminSummary['pending_target_reviews']) ?> targets pending Dean/Admin workflow decision.</p>
          </div>
        </div>
      </div>
    </section>

    <!-- =================================================================== -->
    <!-- SLIDE 3: Dean Summary                                              -->
    <!-- =================================================================== -->
    <section class="present-slide" data-slide-index="3">
      <div class="slide-header">
        <div class="slide-title-wrap">
          <h2><?= icon('award', 20) ?> Dean Academic Summary</h2>
          <p>Academic operations, review workload, department attainment, and faculty follow-up</p>
        </div>
        <div class="slide-badge-wrap">
          <span class="badge-pill brand"><?= icon('layers', 13) ?> Dean Academic Scope</span>
        </div>
      </div>

      <div class="kpi-cards-grid">
        <div class="kpi-card accent-orange">
          <div class="kpi-top">
            <span class="kpi-label">Targets Awaiting Dean Review</span>
            <div class="kpi-icon orange"><?= icon('bell', 16) ?></div>
          </div>
          <div class="kpi-val" style="color:var(--orange);"><?= (int) $deanSummary['dean_pending_reviews'] ?></div>
          <div class="kpi-sub">Workflow review actions awaiting Dean decision</div>
        </div>

        <div class="kpi-card accent-green">
          <div class="kpi-top">
            <span class="kpi-label">Achieved Targets</span>
            <div class="kpi-icon green"><?= icon('check', 16) ?></div>
          </div>
          <div class="kpi-val" style="color:var(--success);"><?= (int) $deanSummary['achieved_targets'] ?></div>
          <div class="kpi-sub">Benchmarks met with verified evidence</div>
        </div>

        <div class="kpi-card accent-blue">
          <div class="kpi-top">
            <span class="kpi-label">In Progress Targets</span>
            <div class="kpi-icon blue"><?= icon('clock', 16) ?></div>
          </div>
          <div class="kpi-val" style="color:var(--info);"><?= (int) $deanSummary['in_progress_targets'] ?></div>
          <div class="kpi-sub">Under active academic tracking</div>
        </div>

        <div class="kpi-card accent-red">
          <div class="kpi-top">
            <span class="kpi-label">Not Completed</span>
            <div class="kpi-icon red"><?= icon('alert-triangle', 16) ?></div>
          </div>
          <div class="kpi-val" style="color:var(--danger);"><?= (int) $deanSummary['not_completed_targets'] ?></div>
          <div class="kpi-sub">Incomplete with elapsed deadline</div>
        </div>
      </div>

      <div class="split-grid-2">
        <div class="panel-card">
          <div class="panel-head">
            <span class="panel-title"><?= icon('building', 16) ?> Academic Department Performance Leaderboard</span>
          </div>
          <table class="present-table" style="font-size:12.5px;">
            <thead>
              <tr>
                <th>Department</th>
                <th class="num-align">Targets</th>
                <th class="num-align">Achieved</th>
                <th class="num-align">Attainment</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($deanSummary['departments_ranking'] as $d): ?>
                <tr>
                  <td style="font-weight:700; color:var(--navy);"><?= e($d['department']) ?></td>
                  <td class="num-align"><?= (int) $d['targets_count'] ?></td>
                  <td class="num-align" style="font-weight:700; color:var(--success);"><?= number_format($d['achieved_value']) ?></td>
                  <td class="num-align" style="font-weight:700; color:var(--orange);"><?= $d['attainment_pct'] ?>%</td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <div class="panel-card">
          <div class="panel-head">
            <span class="panel-title"><?= icon('info', 16) ?> Academic Workflow Note &amp; Follow-up Areas</span>
          </div>
          <div style="background:var(--info-50); border:1px solid #BFDBFE; border-radius:var(--r-md); padding:14px; margin-bottom:14px; font-size:12.5px; color:#1E40AF; line-height:1.5;">
            <strong>Workflow Clarification:</strong> Targets awaiting Dean review represent pending administrative verification actions. They are strictly separated from achievement completion metrics.
          </div>

          <div style="font-size:13px; font-weight:700; color:var(--navy); margin-bottom:8px;">Categories Requiring Academic Follow-up:</div>
          <?php if (empty($deanSummary['followup_categories'])): ?>
            <div class="empty-box" style="padding:16px;">No lagging categories requiring immediate intervention.</div>
          <?php else: ?>
            <div style="display:flex; flex-direction:column; gap:8px;">
              <?php foreach ($deanSummary['followup_categories'] as $fc): ?>
                <div style="display:flex; justify-content:space-between; align-items:center; background:var(--navy-50); padding:8px 12px; border-radius:var(--r-sm); font-size:12.5px;">
                  <span style="font-weight:600; color:var(--navy);"><?= e($fc['title']) ?></span>
                  <span style="color:var(--danger); font-weight:700;"><?= $fc['attainment_pct'] ?>% Attained</span>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </section>

    <!-- =================================================================== -->
    <!-- SLIDE 4: Principal Summary                                         -->
    <!-- =================================================================== -->
    <section class="present-slide" data-slide-index="4">
      <div class="slide-header">
        <div class="slide-title-wrap">
          <h2><?= icon('graduation', 20) ?> Principal Executive Scorecard</h2>
          <p>Institutional quality assurance overview, strategic growth areas, and executive milestones</p>
        </div>
        <div class="slide-badge-wrap">
          <span class="badge-pill brand"><?= icon('award', 13) ?> Executive Institutional Leadership</span>
        </div>
      </div>

      <div class="kpi-cards-grid">
        <div class="kpi-card accent-navy">
          <div class="kpi-top"><span class="kpi-label">Institutional Targets</span><div class="kpi-icon navy"><?= icon('target', 16) ?></div></div>
          <div class="kpi-val"><?= (int) $principalSummary['total_targets'] ?></div>
          <div class="kpi-sub">Total tracked institutional indicators</div>
        </div>

        <div class="kpi-card accent-green">
          <div class="kpi-top"><span class="kpi-label">Targets Achieved</span><div class="kpi-icon green"><?= icon('check', 16) ?></div></div>
          <div class="kpi-val" style="color:var(--success);"><?= (int) $principalSummary['achieved_targets'] ?></div>
          <div class="kpi-sub">Verified benchmarks accomplished</div>
        </div>

        <div class="kpi-card accent-orange">
          <div class="kpi-top"><span class="kpi-label">In Progress</span><div class="kpi-icon orange"><?= icon('clock', 16) ?></div></div>
          <div class="kpi-val" style="color:var(--orange);"><?= (int) $principalSummary['in_progress_targets'] ?></div>
          <div class="kpi-sub">Actively advancing in cycle</div>
        </div>

        <div class="kpi-card accent-blue">
          <div class="kpi-top"><span class="kpi-label">Overall Attainment</span><div class="kpi-icon blue"><?= icon('bar-chart', 16) ?></div></div>
          <div class="kpi-val" style="color:var(--info);"><?= $principalSummary['overall_attainment_pct'] ?>%</div>
          <div class="kpi-sub">Macro completion rate</div>
        </div>
      </div>

      <div class="split-grid-2">
        <div class="panel-card">
          <div class="panel-head">
            <span class="panel-title"><?= icon('award', 16) ?> Best-Performing Academic Departments</span>
          </div>
          <?php if (empty($principalSummary['top_performing_depts'])): ?>
            <div class="empty-box">No department target data available for this academic year.</div>
          <?php else: ?>
            <div style="display:flex; flex-direction:column; gap:12px;">
              <?php $rank = 1; foreach ($principalSummary['top_performing_depts'] as $pd): ?>
                <div style="display:flex; align-items:center; justify-content:space-between; background:var(--navy-50); padding:14px 16px; border-radius:var(--r-md); border:1px solid var(--hairline);">
                  <div style="display:flex; align-items:center; gap:12px;">
                    <div style="width:28px; height:28px; border-radius:50%; background:<?= $rank === 1 ? 'var(--orange)' : 'var(--navy)' ?>; color:#FFFFFF; font-weight:800; font-size:13px; display:flex; align-items:center; justify-content:center;">
                      <?= $rank++ ?>
                    </div>
                    <div>
                      <div style="font-size:15px; font-weight:800; color:var(--navy);"><?= e($pd['department']) ?></div>
                      <div style="font-size:12px; color:var(--ink-muted);"><?= (int) $pd['targets_count'] ?> Targets &middot; <?= number_format($pd['achieved_value']) ?> Submissions</div>
                    </div>
                  </div>
                  <div style="text-align:right;">
                    <div style="font-size:18px; font-weight:800; color:var(--orange);"><?= $pd['attainment_pct'] ?>%</div>
                    <div style="font-size:11px; color:var(--ink-faint);">Attainment Rate</div>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>

        <div class="panel-card">
          <div class="panel-head">
            <span class="panel-title"><?= icon('shield', 16) ?> Strategic Focus &amp; Quality Mandate</span>
          </div>
          <div style="display:flex; flex-direction:column; gap:12px; font-size:13px; color:var(--ink); line-height:1.6;">
            <p><strong>Governing Council Benchmarks:</strong> All academic departments are aligned with NAAC Criteria 3 (Research, Innovations &amp; Extension) and Criteria 5 (Student Support &amp; Progression).</p>
            <div style="background:var(--navy-50); padding:12px 14px; border-radius:var(--r-md); border-left:4px solid var(--orange);">
              <div style="font-weight:700; color:var(--navy);">Priority Focus Areas for Academic Year <?= e($academicYear) ?>:</div>
              <ul style="margin-left:18px; margin-top:6px; color:var(--ink-muted); font-size:12.5px;">
                <li>High-impact Scopus / SCI / UGC CARE journal publications.</li>
                <li>Expansion of 4-week+ industry internships &amp; top MNC placements.</li>
                <li>NPTEL, Swayam &amp; online technical certifications.</li>
              </ul>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- =================================================================== -->
    <!-- SLIDE 5: Overall Target Fixed vs Target Achieved                   -->
    <!-- =================================================================== -->
    <section class="present-slide" data-slide-index="5">
      <div class="slide-header">
        <div class="slide-title-wrap">
          <h2><?= icon('bar-chart', 20) ?> Target Fixed vs Target Achieved</h2>
          <p>Comprehensive comparison of configured benchmarks versus verified completion status</p>
        </div>
        <div class="slide-badge-wrap">
          <span class="badge-pill brand"><?= icon('target', 13) ?> Quantitative Comparison</span>
        </div>
      </div>

      <div class="kpi-cards-grid">
        <div class="kpi-card accent-navy">
          <div class="kpi-top"><span class="kpi-label">Cumulative Fixed</span><div class="kpi-icon navy"><?= icon('target', 16) ?></div></div>
          <div class="kpi-val"><?= number_format($summary['total_target_fixed']) ?></div>
          <div class="kpi-sub">Total institutional target volume</div>
        </div>

        <div class="kpi-card accent-green">
          <div class="kpi-top"><span class="kpi-label">Cumulative Achieved</span><div class="kpi-icon green"><?= icon('check', 16) ?></div></div>
          <div class="kpi-val" style="color:var(--success);"><?= number_format($summary['total_target_achieved']) ?></div>
          <div class="kpi-sub">Total verified achievements</div>
        </div>

        <div class="kpi-card accent-orange">
          <div class="kpi-top"><span class="kpi-label">In Progress Deficit</span><div class="kpi-icon orange"><?= icon('clock', 16) ?></div></div>
          <div class="kpi-val" style="color:var(--orange);"><?= number_format(max(0, $summary['total_target_fixed'] - $summary['total_target_achieved'])) ?></div>
          <div class="kpi-sub">Remaining volume to goal</div>
        </div>

        <div class="kpi-card accent-blue">
          <div class="kpi-top"><span class="kpi-label">Attainment Rate</span><div class="kpi-icon blue"><?= icon('bar-chart', 16) ?></div></div>
          <div class="kpi-val" style="color:var(--info);"><?= $summary['overall_attainment_pct'] ?>%</div>
          <div class="kpi-sub">Cumulative completion percentage</div>
        </div>
      </div>

      <div class="table-container">
        <table class="present-table">
          <thead>
            <tr>
              <th style="width:50px;">S.No</th>
              <th>Target Category / Institutional Indicator</th>
              <th class="num-align">Target Fixed</th>
              <th class="num-align">Target Achieved</th>
              <th class="num-align">Remaining</th>
              <th style="width:200px;">Attainment %</th>
              <th style="width:130px; text-align:center;">Status</th>
            </tr>
          </thead>
          <tbody>
            <?php $sn = 1; foreach ($sections30 as $secItem): ?>
              <?php if ($secItem['target_fixed'] > 0 || $secItem['achieved_value'] > 0): ?>
                <tr>
                  <td style="color:var(--ink-muted); font-weight:600;"><?= $sn++ ?></td>
                  <td style="font-weight:700; color:var(--navy);"><?= e($secItem['title']) ?></td>
                  <td class="num-align" style="font-weight:700;"><?= number_format($secItem['target_fixed']) ?> <?= e($secItem['unit']) ?></td>
                  <td class="num-align" style="font-weight:700; color:var(--success);"><?= number_format($secItem['achieved_value']) ?> <?= e($secItem['unit']) ?></td>
                  <td class="num-align" style="color:var(--ink-muted); font-weight:600;"><?= number_format($secItem['remaining']) ?></td>
                  <td>
                    <div style="display:flex; align-items:center; gap:8px;">
                      <div class="progress-track" style="flex:1; height:8px;">
                        <div class="progress-fill" style="width:<?= min(100, $secItem['attainment_pct']) ?>%; background:<?= $secItem['attainment_pct'] >= 100 ? 'var(--success)' : ($secItem['attainment_pct'] >= 50 ? 'var(--orange)' : 'var(--danger)') ?>;"></div>
                      </div>
                      <span style="font-weight:700; font-size:12px; width:45px; text-align:right; color:<?= $secItem['attainment_pct'] >= 100 ? 'var(--success)' : ($secItem['attainment_pct'] >= 50 ? 'var(--orange)' : 'var(--danger)') ?>;"><?= $secItem['attainment_pct'] ?>%</span>
                    </div>
                  </td>
                  <td style="text-align:center;">
                    <span class="badge-pill <?= $secItem['classification'] === 'Achieved' ? 'success' : ($secItem['classification'] === 'Not Completed' ? 'danger' : 'warning') ?>">
                      <?= e($secItem['classification']) ?>
                    </span>
                  </td>
                </tr>
              <?php endif; ?>
            <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr>
              <td colspan="2" style="font-weight:800;">Institutional Total</td>
              <td class="num-align" style="font-weight:800;"><?= number_format($summary['total_target_fixed']) ?></td>
              <td class="num-align" style="font-weight:800; color:var(--success);"><?= number_format($summary['total_target_achieved']) ?></td>
              <td class="num-align" style="font-weight:800;"><?= number_format(max(0, $summary['total_target_fixed'] - $summary['total_target_achieved'])) ?></td>
              <td>
                <div style="display:flex; align-items:center; gap:8px;">
                  <div class="progress-track" style="flex:1; height:8px;">
                    <div class="progress-fill" style="width:<?= min(100, $summary['overall_attainment_pct']) ?>%;"></div>
                  </div>
                  <span style="font-weight:800; font-size:12px; width:45px; text-align:right; color:var(--navy);"><?= $summary['overall_attainment_pct'] ?>%</span>
                </div>
              </td>
              <td style="text-align:center;">
                <span class="badge-pill brand"><?= $summary['targets_met_pct'] ?>% Met</span>
              </td>
            </tr>
          </tfoot>
        </table>
      </div>
    </section>

    <!-- =================================================================== -->
    <!-- SLIDE 6: Department-Wise Target Performance (Reference 1 Layout)    -->
    <!-- =================================================================== -->
    <section class="present-slide" data-slide-index="6">
      <div class="slide-header">
        <div class="slide-title-wrap">
          <h2><?= icon('award', 20) ?> Department-Wise Target Performance</h2>
          <p>Institutional ranking based on verified target attainment rate and achievement records</p>
        </div>
        <div class="slide-badge-wrap">
          <span class="badge-pill brand"><?= icon('bar-chart', 13) ?> Performance Ranking</span>
        </div>
      </div>

      <!-- Top 3 Podium Cards -->
      <div class="podium-grid">
        <?php for ($i = 0; $i < 3; $i++): ?>
          <?php $td = $topDepartments[$i] ?? null; ?>
          <div class="podium-card">
            <div class="podium-rank-badge"><?= $i + 1 ?></div>
            <?php if ($td): ?>
              <div class="podium-dept-name"><?= e($td['department']) ?></div>
              <div class="podium-pct"><?= $td['attainment_pct'] ?>%</div>
              <div class="podium-stats">
                Target Fixed: <strong><?= number_format($td['target_fixed']) ?></strong><br>
                Achieved: <strong><?= number_format($td['achieved_value']) ?></strong><br>
                Targets: <strong><?= (int) $td['targets_count'] ?></strong> (<?= (int) $td['achieved_count'] ?> Met)
              </div>
            <?php else: ?>
              <div class="podium-dept-name" style="color:var(--ink-faint);">&mdash;</div>
              <div class="podium-pct" style="color:var(--ink-faint);">0%</div>
              <div class="podium-stats" style="color:var(--ink-faint);">No department ranked</div>
            <?php endif; ?>
          </div>
        <?php endfor; ?>
      </div>

      <!-- Ranking Comparison Bars -->
      <div class="panel-card">
        <div class="panel-head">
          <span class="panel-title"><?= icon('bar-chart', 16) ?> Department Attainment Comparison</span>
          <span class="badge-pill neutral">Sorted by Performance</span>
        </div>
        <div style="display:flex; flex-direction:column; gap:8px;">
          <?php foreach ($allDepartments as $deptItem): ?>
            <?php if ($deptItem['targets_count'] > 0 || $deptItem['achieved_value'] > 0): ?>
              <div class="bar-row">
                <div class="bar-row-label"><?= e($deptItem['department']) ?></div>
                <div class="bar-row-track">
                  <div class="bar-row-fill" style="width:<?= min(100, $deptItem['attainment_pct']) ?>%; background:<?= $deptItem['attainment_pct'] >= 100 ? 'var(--success)' : ($deptItem['attainment_pct'] >= 50 ? 'var(--orange)' : 'var(--danger)') ?>;"></div>
                </div>
                <div class="bar-row-val"><?= $deptItem['attainment_pct'] ?>%</div>
              </div>
            <?php endif; ?>
          <?php endforeach; ?>
        </div>
      </div>
    </section>

    <!-- =================================================================== -->
    <!-- SLIDE 7: All Departments Contribution (Reference 1 Layout)         -->
    <!-- =================================================================== -->
    <section class="present-slide" data-slide-index="7">
      <div class="slide-header">
        <div class="slide-title-wrap">
          <h2><?= icon('building', 20) ?> All Departments Contribution</h2>
          <p>Comprehensive contribution table and target attainment across all participating academic departments</p>
        </div>
        <div class="slide-badge-wrap">
          <span class="badge-pill neutral"><?= count($allDepartments) ?> Departments</span>
        </div>
      </div>

      <div class="table-container">
        <table class="present-table">
          <thead>
            <tr>
              <th style="width:60px;">S.No</th>
              <th>Department</th>
              <th class="num-align">Targets Configured</th>
              <th class="num-align">Target Fixed</th>
              <th class="num-align">Achieved</th>
              <th class="num-align">In Progress</th>
              <th class="num-align">Not Completed</th>
              <th style="width:200px;">Attainment %</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($allDepartments)): ?>
              <tr>
                <td colspan="8" style="text-align:center; padding:32px; color:var(--ink-muted);">
                  No departmental data available.
                </td>
              </tr>
            <?php else: ?>
              <?php $dn = 1; foreach ($allDepartments as $d): ?>
                <?php
                  $pct = $d['attainment_pct'];
                  $bCol = $pct >= 100 ? 'var(--success)' : ($pct >= 50 ? 'var(--orange)' : 'var(--danger)');
                ?>
                <tr>
                  <td style="color:var(--ink-muted); font-weight:600;"><?= $dn++ ?></td>
                  <td style="font-weight:700; color:var(--navy);"><?= e($d['department']) ?></td>
                  <td class="num-align" style="font-weight:600;"><?= (int) $d['targets_count'] ?></td>
                  <td class="num-align" style="font-weight:700;"><?= number_format($d['target_fixed']) ?></td>
                  <td class="num-align" style="font-weight:700; color:var(--success);"><?= number_format($d['achieved_value']) ?></td>
                  <td class="num-align" style="color:var(--orange); font-weight:600;"><?= (int) $d['in_progress_count'] ?></td>
                  <td class="num-align" style="color:var(--danger); font-weight:600;"><?= (int) $d['not_completed_count'] ?></td>
                  <td>
                    <div style="display:flex; align-items:center; gap:8px;">
                      <div class="progress-track" style="flex:1; height:8px;">
                        <div class="progress-fill" style="width:<?= min(100, $pct) ?>%; background:<?= $bCol ?>;"></div>
                      </div>
                      <span style="font-weight:700; font-size:12px; width:45px; text-align:right; color:<?= $bCol ?>;"><?= $pct ?>%</span>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
          <tfoot>
            <tr>
              <td colspan="2" style="font-weight:800;">Total Institutional Contribution</td>
              <td class="num-align" style="font-weight:800;"><?= (int) $summary['total_targets'] ?></td>
              <td class="num-align" style="font-weight:800;"><?= number_format($summary['total_target_fixed']) ?></td>
              <td class="num-align" style="font-weight:800; color:var(--success);"><?= number_format($summary['total_target_achieved']) ?></td>
              <td class="num-align" style="font-weight:800; color:var(--orange);"><?= (int) $summary['in_progress_targets'] ?></td>
              <td class="num-align" style="font-weight:800; color:var(--danger);"><?= (int) $summary['not_completed_targets'] ?></td>
              <td>
                <div style="display:flex; align-items:center; gap:8px;">
                  <div class="progress-track" style="flex:1; height:8px;">
                    <div class="progress-fill" style="width:<?= min(100, $summary['overall_attainment_pct']) ?>%;"></div>
                  </div>
                  <span style="font-weight:800; font-size:12px; width:45px; text-align:right; color:var(--navy);"><?= $summary['overall_attainment_pct'] ?>%</span>
                </div>
              </td>
            </tr>
          </tfoot>
        </table>
      </div>
    </section>

    <!-- =================================================================== -->
    <!-- SLIDES 8–37: ALL 30 INDIVIDUAL TARGET SECTIONS (1 TO 30)            -->
    <!-- =================================================================== -->
    <?php foreach ($sections30 as $secOrder => $sec): ?>
      <?php $secSlideIdx = 7 + (int) $secOrder; ?>
      <section class="present-slide" data-slide-index="<?= $secSlideIdx ?>">
        <div class="slide-header">
          <div class="slide-title-wrap">
            <div style="font-size:11.5px; font-weight:800; color:var(--orange); text-transform:uppercase; letter-spacing:0.06em; margin-bottom:3px;">
              Section <?= $sec['order'] ?> of 30 &middot; <?= e($sec['group']) ?>
            </div>
            <h2><?= icon('target', 20) ?> <?= e($sec['title']) ?></h2>
            <p><?= e($sec['description']) ?></p>
          </div>
          <div class="slide-badge-wrap">
            <span class="badge-pill brand"><?= icon('calendar', 13) ?> Academic Year <?= e($academicYear) ?></span>
            <span class="badge-pill <?= $sec['classification'] === 'Achieved' ? 'success' : ($sec['classification'] === 'Not Completed' ? 'danger' : 'warning') ?>">
              <?= e($sec['classification']) ?>
            </span>
          </div>
        </div>

        <!-- 4 KPI Cards for Section -->
        <div class="kpi-cards-grid" style="grid-template-columns: repeat(4, 1fr); margin-bottom:18px;">
          <div class="kpi-card accent-navy">
            <div class="kpi-top">
              <span class="kpi-label">Target Fixed</span>
              <div class="kpi-icon navy"><?= icon('target', 16) ?></div>
            </div>
            <div class="kpi-val"><?= number_format($sec['target_fixed']) ?> <span style="font-size:13px; font-weight:600; color:var(--ink-muted);"><?= e($sec['unit']) ?></span></div>
            <div class="kpi-sub">Configured institutional goal</div>
          </div>

          <div class="kpi-card accent-green">
            <div class="kpi-top">
              <span class="kpi-label">Target Achieved</span>
              <div class="kpi-icon green"><?= icon('check', 16) ?></div>
            </div>
            <div class="kpi-val" style="color:var(--success);"><?= number_format($sec['achieved_value']) ?> <span style="font-size:13px; font-weight:600; color:var(--ink-muted);"><?= e($sec['unit']) ?></span></div>
            <div class="kpi-sub">Verified approved achievements</div>
          </div>

          <div class="kpi-card accent-orange">
            <div class="kpi-top">
              <span class="kpi-label">Remaining / In Progress</span>
              <div class="kpi-icon orange"><?= icon('clock', 16) ?></div>
            </div>
            <div class="kpi-val" style="color:var(--orange);"><?= number_format($sec['remaining']) ?> <span style="font-size:13px; font-weight:600; color:var(--ink-muted);"><?= e($sec['unit']) ?></span></div>
            <div class="kpi-sub">Deficit to target completion</div>
          </div>

          <div class="kpi-card accent-blue">
            <div class="kpi-top">
              <span class="kpi-label">Attainment Rate</span>
              <div class="kpi-icon blue"><?= icon('bar-chart', 16) ?></div>
            </div>
            <div class="kpi-val" style="color:var(--info);"><?= $sec['attainment_pct'] ?>%</div>
            <div class="kpi-sub"><?= $sec['attainment_pct'] >= 100 ? 'Target benchmark met' : 'Active progression' ?></div>
          </div>
        </div>

        <!-- Attainment Progress Track -->
        <div style="background:var(--surface); border:1px solid var(--hairline); border-radius:var(--r-md); padding:14px 18px; margin-bottom:18px;">
          <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
            <span style="font-weight:700; font-size:13px; color:var(--navy);">Section Attainment Progress</span>
            <span style="font-weight:800; font-size:13px; color:<?= $sec['attainment_pct'] >= 100 ? 'var(--success)' : ($sec['attainment_pct'] >= 50 ? 'var(--orange)' : 'var(--danger)') ?>;"><?= $sec['attainment_pct'] ?>%</span>
          </div>
          <div class="progress-track" style="height:10px;">
            <div class="progress-fill <?= $sec['attainment_pct'] >= 100 ? 'success' : '' ?>" style="width:<?= min(100, $sec['attainment_pct']) ?>%; background:<?= $sec['attainment_pct'] >= 100 ? 'var(--success)' : ($sec['attainment_pct'] >= 50 ? 'var(--orange)' : 'var(--danger)') ?>;"></div>
          </div>
        </div>

        <!-- Split Grid: Department Breakdown & Verified Evidence -->
        <div class="split-grid-2">
          <!-- Department Performance Panel -->
          <div class="panel-card">
            <div class="panel-head">
              <span class="panel-title"><?= icon('building', 16) ?> Department Breakdown</span>
              <span class="badge-pill neutral"><?= count($sec['by_department']) ?> Allocated</span>
            </div>
            <?php if (empty($sec['by_department'])): ?>
              <div class="empty-box">No department targets configured yet for this section in Academic Year <?= e($academicYear) ?>.</div>
            <?php else: ?>
              <table class="present-table" style="font-size:12.5px;">
                <thead>
                  <tr>
                    <th>Department</th>
                    <th class="num-align">Target Fixed</th>
                    <th class="num-align">Achieved</th>
                    <th class="num-align">Attainment</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($sec['by_department'] as $deptKey => $dStats): ?>
                    <tr>
                      <td style="font-weight:700; color:var(--navy);"><?= e($deptKey) ?></td>
                      <td class="num-align" style="font-weight:600;"><?= number_format($dStats['target_fixed']) ?></td>
                      <td class="num-align" style="font-weight:700; color:var(--success);"><?= number_format($dStats['achieved_value']) ?></td>
                      <td class="num-align" style="font-weight:700; color:<?= $dStats['attainment_pct'] >= 100 ? 'var(--success)' : ($dStats['attainment_pct'] >= 50 ? 'var(--orange)' : 'var(--danger)') ?>;">
                        <?= $dStats['attainment_pct'] ?>%
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            <?php endif; ?>
          </div>

          <!-- Verified Submissions Panel -->
          <div class="panel-card">
            <div class="panel-head">
              <span class="panel-title"><?= icon('file-text', 16) ?> Verified Faculty &amp; Coordinator Submissions</span>
              <span class="badge-pill brand"><?= $sec['total_records'] ?> Recorded</span>
            </div>
            <?php if (empty($sec['uploaded_records'])): ?>
              <div class="empty-box">
                No achievement records submitted yet for this category under Academic Year <?= e($academicYear) ?>.
              </div>
            <?php else: ?>
              <div style="max-height:280px; overflow-y:auto; display:flex; flex-direction:column; gap:8px;">
                <?php foreach ($sec['uploaded_records'] as $rec): ?>
                  <div style="background:var(--navy-50); border:1px solid var(--hairline); border-radius:var(--r-sm); padding:10px 14px;">
                    <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:8px;">
                      <strong style="color:var(--navy); font-size:13px; line-height:1.4;"><?= e($rec['title']) ?></strong>
                      <span class="badge-pill success" style="font-size:10px; flex-shrink:0;"><?= icon('check', 10) ?> <?= e($rec['status']) ?></span>
                    </div>
                    <div style="display:flex; justify-content:space-between; font-size:11.5px; color:var(--ink-muted); margin-top:6px;">
                      <span><?= e($rec['person']) ?> &middot; <strong><?= e($rec['department']) ?></strong></span>
                      <span><?= date('d M Y', strtotime($rec['date'])) ?></span>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </section>
    <?php endforeach; ?>

    <!-- =================================================================== -->
    <!-- SLIDE 38: Final Institutional Target Performance Summary Scorecard  -->
    <!-- =================================================================== -->
    <section class="present-slide" data-slide-index="38">
      <div class="slide-header">
        <div class="slide-title-wrap">
          <h2><?= icon('file-text', 20) ?> Final Institutional Target Performance Summary</h2>
          <p>Consolidated institutional indicator scorecard and IQAC quality assurance compliance summary</p>
        </div>
        <div class="slide-badge-wrap">
          <span class="badge-pill brand"><?= icon('shield', 13) ?> Audit Summary</span>
          <span class="badge-pill success">Academic Year <?= e($academicYear) ?></span>
        </div>
      </div>

      <div class="table-container" style="max-height:480px; overflow-y:auto;">
        <table class="present-table">
          <thead>
            <tr>
              <th style="width:50px;">S.No</th>
              <th>Target Category / Institutional Indicator</th>
              <th>Department</th>
              <th class="num-align">Target Fixed</th>
              <th class="num-align">Achieved</th>
              <th>Target Deadline</th>
              <th style="width:160px;">Progress</th>
              <th style="width:130px; text-align:center;">Status</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($allTargets)): ?>
              <tr>
                <td colspan="8" style="text-align:center; padding:32px; color:var(--ink-muted);">
                  No targets configured for Academic Year <?= e($academicYear) ?>.
                </td>
              </tr>
            <?php else: ?>
              <?php $ti = 1; foreach ($allTargets as $tRow): ?>
                <?php
                  $p = $tRow['progress_pct'];
                  $c = $tRow['classification'];
                  $bStyle = $c === 'Achieved' ? 'success' : ($c === 'Not Completed' ? 'danger' : 'warning');
                  $barC = $c === 'Achieved' ? 'var(--success)' : ($c === 'Not Completed' ? 'var(--danger)' : 'var(--orange)');
                ?>
                <tr>
                  <td style="color:var(--ink-muted); font-weight:600;"><?= $ti++ ?></td>
                  <td style="font-weight:700; color:var(--navy);"><?= e($tRow['metric']) ?></td>
                  <td style="font-weight:600;"><?= e($tRow['department'] ?: 'All') ?></td>
                  <td class="num-align" style="font-weight:700;"><?= number_format($tRow['target_value']) ?></td>
                  <td class="num-align" style="font-weight:700; color:var(--success);"><?= number_format($tRow['achieved_value']) ?></td>
                  <td style="font-size:12px; color:var(--ink-muted);"><?= e($tRow['target_deadline'] ?: 'Active Cycle') ?></td>
                  <td>
                    <div style="display:flex; align-items:center; gap:8px;">
                      <div class="progress-track" style="flex:1; height:8px;">
                        <div class="progress-fill" style="width:<?= min(100, $p) ?>%; background:<?= $barC ?>;"></div>
                      </div>
                      <span style="font-weight:700; font-size:11.5px; width:40px; text-align:right; color:<?= $barC ?>;"><?= $p ?>%</span>
                    </div>
                  </td>
                  <td style="text-align:center;">
                    <span class="badge-pill <?= $bStyle ?>"><?= e($c) ?></span>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
          <tfoot>
            <tr>
              <td colspan="3" style="font-weight:800;">Institutional Scorecard Total</td>
              <td class="num-align" style="font-weight:800;"><?= number_format($summary['total_target_fixed']) ?></td>
              <td class="num-align" style="font-weight:800; color:var(--success);"><?= number_format($summary['total_target_achieved']) ?></td>
              <td>&mdash;</td>
              <td>
                <div style="display:flex; align-items:center; gap:8px;">
                  <div class="progress-track" style="flex:1; height:8px;">
                    <div class="progress-fill" style="width:<?= min(100, $summary['overall_attainment_pct']) ?>%;"></div>
                  </div>
                  <span style="font-weight:800; font-size:11.5px; width:40px; text-align:right; color:var(--navy);"><?= $summary['overall_attainment_pct'] ?>%</span>
                </div>
              </td>
              <td style="text-align:center;">
                <span class="badge-pill brand"><?= $summary['targets_met_pct'] ?>% Achieved</span>
              </td>
            </tr>
          </tfoot>
        </table>
      </div>
    </section>

  <?php endif; ?>
  </main>

  <!-- Bottom Navigation Bar -->
  <footer class="present-footer">
    <div class="footer-hints">
      <div class="footer-hint-item">
        <span class="footer-hint-key">&larr;</span>
        <span>Prev</span>
      </div>
      <div class="footer-hint-item">
        <span class="footer-hint-key">&rarr;</span>
        <span class="footer-hint-key">Space</span>
        <span>Next</span>
      </div>
      <div class="footer-hint-item">
        <span class="footer-hint-key">Esc</span>
        <span>Close</span>
      </div>
    </div>

    <!-- Quick Dots Strip for 38 slides -->
    <div class="footer-progress-dots" id="footerDotsStrip">
      <?php for ($d = 1; $d <= $totalSlides; $d++): ?>
        <div class="dot-step <?= $d === 1 ? 'active' : '' ?>" data-target-slide="<?= $d ?>" title="Jump to Slide <?= $d ?>"></div>
      <?php endfor; ?>
    </div>

    <div style="font-size:12px; color:#94A3B8; display:flex; align-items:center; gap:8px;">
      <?php if ($isDepartmentMode): ?>
        <span class="badge-pill brand" style="font-size:11px; padding:2px 8px;"><?= e($department) ?></span>
      <?php endif; ?>
      <span><?= e($user['role'] ?? 'User') ?>: <?= e($user['name'] ?? 'User') ?></span>
    </div>
  </footer>

  <script>
    (function() {
      var totalSlides = <?= (int) $totalSlides ?>;
      var currentSlide = 1;
      var autoTimer = null;
      var isAuto = false;
      var isEmbedded = <?= $isEmbedded ? 'true' : 'false' ?>;
      var exitUrl = <?= json_encode($exitUrl) ?>;

      var slideCounterText = document.getElementById('slideCounterText');
      var btnPrev = document.getElementById('btnPrevSlide');
      var btnNext = document.getElementById('btnNextSlide');
      var btnAuto = document.getElementById('btnAutoAdvance');
      var autoBtnText = document.getElementById('autoBtnText');
      var btnFullscreen = document.getElementById('btnFullscreen');
      var btnClose = document.getElementById('btnClosePresentation');
      var dots = document.querySelectorAll('.dot-step');
      var slides = document.querySelectorAll('.present-slide');

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
          showSlide(1); // loop in auto mode
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

      // Button Event Listeners
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

      // Touch Swipe Support
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
