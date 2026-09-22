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

$from     = trim((string) input('from', ''));
$referer  = (string) ($_SERVER['HTTP_REFERER'] ?? '');
$userRole = $user['role'] ?? '';

// Build return query parameters for faculty-achievements
$faParams = [];
if (!empty($academicYear)) {
    $faParams['academic_year'] = $academicYear;
}
if (input('return_dept')) {
    $faParams['department'] = input('return_dept');
}
if (input('return_cat')) {
    $faParams['category'] = input('return_cat');
}
$faUrl = url('faculty-achievements.php') . ($faParams ? '?' . http_build_query($faParams) : '');

if ($from === 'faculty-achievements' || $from === 'faculty_achievements' || strpos($referer, 'faculty-achievements.php') !== false) {
    $exitUrl = $faUrl;
} elseif ($from === 'reports' || strpos($referer, 'reports.php') !== false) {
    $exitUrl = url('reports.php');
} elseif ($from === 'individual' || $from === 'individual-faculty-report' || strpos($referer, 'individual-faculty-report.php') !== false) {
    $exitUrl = url('individual-faculty-report.php?id=' . $targetFacultyId . (!empty($academicYear) ? '&academic_year=' . urlencode($academicYear) : ''));
} else {
    // Principal/Director manage primarily from Faculty Achievements, so exit returns there
    if (in_array($userRole, ['Principal', 'Director'], true)) {
        $exitUrl = $faUrl;
    } else {
        $exitUrl = url('individual-faculty-report.php?id=' . $targetFacultyId . (!empty($academicYear) ? '&academic_year=' . urlencode($academicYear) : ''));
    }
}
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
      padding: 16px 20px;
      position: relative;
      overflow: hidden;
    }

    .slide-card {
      background: var(--surface);
      color: var(--ink);
      width: 100%;
      max-width: 1260px;
      height: 100%;
      max-height: 720px;
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
      padding: 12px 16px;
      flex: 1;
      overflow: hidden;
      display: flex;
      flex-direction: column;
      min-height: 0;
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

    /* ---- Target Summary Slide (FEAT-First-Page) ---- */
    .ts-wrap { flex: 1; display: flex; flex-direction: column; justify-content: space-between; gap: 16px; height: 100%; }
    .ts-banner {
      background: #0E2548;
      border-radius: 14px;
      padding: 14px 26px;
      display: flex;
      align-items: center;
      gap: 18px;
      box-shadow: 0 4px 18px rgba(14,37,72,0.18);
    }
    .ts-banner-icon {
      width: 48px; height: 48px; flex-shrink: 0;
      display: grid; place-items: center; color: #FFFFFF;
    }
    .ts-banner-divider {
      width: 2px; height: 46px; background: rgba(255,255,255,0.3); border-radius: 999px; flex-shrink: 0;
    }
    .ts-banner-titles { display: flex; flex-direction: column; }
    .ts-banner-sub {
      font-size: 19px; font-weight: 600; color: #D6E4FF; line-height: 1.2; letter-spacing: -0.01em;
    }
    .ts-banner-title {
      font-size: 36px; font-weight: 800; color: #FFFFFF; line-height: 1.1; letter-spacing: -0.02em;
    }
    .ts-banner-meta {
      margin-left: auto; display: flex; flex-direction: column; align-items: flex-end; gap: 4px;
    }
    .ts-scope-badge {
      background: rgba(255,255,255,0.12); color: #F0F4FF; border: 1px solid rgba(255,255,255,0.22);
      padding: 5px 14px; border-radius: 999px; font-size: 12.5px; font-weight: 600;
    }

    .ts-grid {
      display: grid; grid-template-columns: repeat(3, 1fr); gap: 22px; flex: 1; min-height: 0;
    }
    .ts-card {
      border-radius: 14px; overflow: hidden; display: flex; flex-direction: column;
      box-shadow: 0 8px 24px rgba(0,0,0,0.06); border: 1px solid #E2E8F0; background: #FFFFFF;
    }
    .ts-card-hdr {
      padding: 20px 16px 16px; text-align: center; color: #FFFFFF;
      display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 10px;
    }
    .ts-card-hdr.blue  { background: #1B65C5; }
    .ts-card-hdr.green { background: #168A53; }
    .ts-card-hdr.amber { background: #E59819; }

    .ts-card-ic {
      width: 44px; height: 44px; display: grid; place-items: center;
    }
    .ts-card-title {
      font-size: 19px; font-weight: 700; line-height: 1.25; letter-spacing: -0.01em; color: #FFFFFF;
    }

    .ts-card-body {
      flex: 1; display: flex; align-items: center; justify-content: center;
      padding: 22px 18px; min-height: 160px;
    }
    .ts-card-body.blue  { background: #EBF3FC; }
    .ts-card-body.green { background: #EAF6EE; }
    .ts-card-body.amber { background: #FEF8EC; }

    .ts-card-val {
      font-size: 86px; font-weight: 900; line-height: 1;
      letter-spacing: -0.03em; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
    }
    .ts-card-val.blue  { color: #0E3366; }
    .ts-card-val.green { color: #0A4D20; }
    .ts-card-val.amber { color: #7E4A05; }

    .ts-foot {
      display: flex; align-items: center; justify-content: space-between;
      border-top: 1px solid var(--hairline); padding-top: 10px;
      font-size: 12px; color: var(--muted);
    }

    /* ---- Auto / Manual presentation mode ---- */
    .mode-switch {
      display: inline-flex;
      background: rgba(255,255,255,0.08);
      border: 1px solid rgba(255,255,255,0.15);
      border-radius: 999px;
      padding: 3px;
      gap: 2px;
      margin: 0 4px;
    }
    .mode-btn {
      background: none;
      border: 0;
      color: #94A3B8;
      font-family: inherit;
      font-size: 12px;
      font-weight: 700;
      letter-spacing: .02em;
      padding: 6px 14px;
      border-radius: 999px;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 6px;
      white-space: nowrap;
      transition: all .2s ease;
    }
    .mode-btn.active { background: var(--brand); color: #fff; }
    .mode-btn:not(.active):hover { color: #fff; }

    /* Countdown label — only filled in while Auto is running. */
    .auto-count {
      font-size: 12px;
      font-weight: 700;
      color: #94A3B8;
      white-space: nowrap;
      min-width: 108px;
    }
    .auto-count b { color: var(--brand); }

    /* The 10 seconds, drawn across the top of the slide. */
    .auto-bar {
      position: absolute;
      top: 0; left: 0;
      height: 4px;
      width: 0;
      background: var(--brand);
      border-radius: 16px 0 0 0;
      z-index: 5;
    }
    /* ---- Executive Dashboard Reference Layout Styles ---- */
    .ex-wrap {
      flex: 1; display: flex; flex-direction: column; justify-content: space-between; gap: 10px; height: 100%; min-height: 0;
    }
    .ex-banner {
      background: linear-gradient(135deg, #072146 0%, #0B2D59 55%, #0C3B70 100%);
      border-radius: 12px; padding: 11px 22px; display: flex; align-items: center; justify-content: space-between;
      box-shadow: 0 4px 16px rgba(11,45,89,0.25); flex-shrink: 0; color: #FFFFFF;
    }
    .ex-banner-left { display: flex; align-items: center; gap: 12px; }
    .ex-banner-badge {
      width: 44px; height: 44px; border-radius: 10px; background: rgba(255,255,255,0.12);
      border: 1px solid rgba(255,255,255,0.22); display: flex; align-items: center; justify-content: center; flex-shrink: 0;
    }
    .ex-banner-divider {
      width: 1.5px; height: 38px; background: rgba(255,255,255,0.3); border-radius: 999px; margin: 0 4px; flex-shrink: 0;
    }
    .ex-banner-titles { display: flex; flex-direction: column; }
    .ex-banner-title {
      font-size: 24px; font-weight: 800; color: #FFFFFF; line-height: 1.15; letter-spacing: -0.015em;
    }
    .ex-banner-subtitle {
      font-size: 13px; font-style: italic; color: #93C5FD; font-weight: 500; margin-top: 2px; letter-spacing: 0.02em;
    }
    .ex-banner-right {
      display: flex; flex-direction: column; align-items: flex-end; text-align: right;
    }
    .ex-banner-motto {
      font-family: "Georgia", "Palatino", "Brush Script MT", cursive, serif;
      font-size: 18px; font-style: italic; color: #FFFFFF; line-height: 1.1;
      text-shadow: 0 1px 3px rgba(0,0,0,0.35);
    }
    .ex-banner-motto-sub {
      font-size: 11px; color: #BFDBFE; font-weight: 600; margin-top: 2px; letter-spacing: 0.03em;
    }

    .ex-kpi-grid {
      display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; flex-shrink: 0;
    }
    .ex-kpi-card {
      border-radius: 12px; padding: 10px 14px; display: flex; align-items: center; gap: 14px;
      box-shadow: 0 2px 8px rgba(0,0,0,0.04); transition: transform 0.15s ease;
    }
    .ex-kpi-card.blue   { background: #EBF5FB; border: 1px solid #D4E6F1; }
    .ex-kpi-card.green  { background: #E8F8F0; border: 1px solid #D1F2DF; }
    .ex-kpi-card.amber  { background: #FEF7E6; border: 1px solid #FDEBD0; }
    .ex-kpi-card.purple { background: #F4EFFE; border: 1px solid #E9D5FF; }

    .ex-kpi-ic {
      width: 44px; height: 44px; display: flex; align-items: center; justify-content: center; flex-shrink: 0;
    }
    .ex-kpi-info { display: flex; flex-direction: column; flex: 1; min-width: 0; }
    .ex-kpi-label { font-size: 12.5px; font-weight: 800; line-height: 1.15; }
    .ex-kpi-card.blue .ex-kpi-label   { color: #0B2D59; }
    .ex-kpi-card.green .ex-kpi-label  { color: #168A53; }
    .ex-kpi-card.amber .ex-kpi-label  { color: #B45309; }
    .ex-kpi-card.purple .ex-kpi-label { color: #5B21B6; }

    .ex-kpi-sub { font-size: 10px; font-weight: 600; line-height: 1.1; margin-top: 1px; color: #7C3AED; }

    .ex-kpi-val-col { display: flex; flex-direction: column; align-items: flex-start; margin-top: 3px; }
    .ex-kpi-val { font-size: 28px; font-weight: 900; line-height: 1; letter-spacing: -0.02em; }
    .ex-kpi-card.blue .ex-kpi-val   { color: #0B2D59; }
    .ex-kpi-card.green .ex-kpi-val  { color: #168A53; }
    .ex-kpi-card.amber .ex-kpi-val  { color: #B45309; }
    .ex-kpi-card.purple .ex-kpi-val { color: #5B21B6; }

    .ex-kpi-pct { font-size: 12px; font-weight: 700; margin-top: 2px; line-height: 1; }
    .ex-kpi-card.green .ex-kpi-pct { color: #168A53; }
    .ex-kpi-card.amber .ex-kpi-pct { color: #B45309; }

    .ex-split-row {
      display: grid; grid-template-columns: 0.96fr 1.04fr; gap: 12px; flex: 1; min-height: 0;
    }
    .ex-panel {
      border-radius: 10px; border: 1px solid #CBD5E1; background: #FFFFFF;
      overflow: hidden; display: flex; flex-direction: column; box-shadow: 0 2px 10px rgba(0,0,0,0.03);
    }
    .ex-panel-hdr {
      background: #0B2D59; color: #FFFFFF; padding: 7px 14px;
      display: flex; align-items: center; justify-content: space-between; flex-shrink: 0;
    }
    .ex-panel-title { font-size: 13.5px; font-weight: 800; letter-spacing: -0.01em; }
    .ex-panel-sub { font-size: 10.5px; font-style: italic; color: #93C5FD; font-weight: 500; margin-top: 1px; }
    .ex-panel-body {
      flex: 1; padding: 8px 10px; display: flex; flex-direction: column; min-height: 0; overflow: hidden;
    }

    .ex-barchart-legend {
      display: flex; align-items: center; justify-content: flex-end; gap: 12px; margin-bottom: 4px; flex-shrink: 0;
    }
    .ex-leg-item { display: inline-flex; align-items: center; gap: 5px; font-size: 10.5px; font-weight: 700; color: #475569; }
    .ex-leg-color { width: 10px; height: 10px; border-radius: 2px; flex-shrink: 0; }
    .ex-leg-color.green { background: #168A53; }
    .ex-leg-color.blue  { background: #1B65C5; }
    .ex-leg-color.amber { background: #F59E0B; }

    .ex-barchart-wrap {
      flex: 1; width: 100%; min-height: 0; display: flex; align-items: center; justify-content: center;
    }

    .ex-contrib-grid {
      display: grid; grid-template-columns: 1.15fr 0.85fr; gap: 10px; height: 100%; min-height: 0;
    }
    .ex-table-wrap {
      overflow-y: auto; min-height: 0; border: 1px solid #E2E8F0; border-radius: 6px;
    }
    .ex-table { width: 100%; border-collapse: collapse; font-size: 11px; }
    .ex-table thead th {
      background: #0B2D59; color: #FFFFFF; font-size: 10px; font-weight: 800;
      text-transform: uppercase; letter-spacing: 0.03em; padding: 6px 8px; position: sticky; top: 0; z-index: 2; text-align: right;
    }
    .ex-table thead th:first-child { text-align: left; }
    .ex-table tbody td {
      padding: 5px 8px; border-bottom: 1px solid #EDF2F7; color: #1E293B; font-weight: 600; text-align: right; font-variant-numeric: tabular-nums;
    }
    .ex-table tbody td:first-child { text-align: left; font-weight: 700; color: #0F172A; }
    .ex-table tbody tr:nth-child(even) { background: #F8FAFC; }
    .ex-table tfoot td {
      background: #EDF2F7; color: #0B2D59; font-weight: 800; padding: 6px 8px; border-top: 2px solid #CBD5E1; text-align: right; font-size: 11px;
    }
    .ex-table tfoot td:first-child { text-align: left; }

    .ex-donut-side {
      display: flex; flex-direction: column; align-items: center; justify-content: space-between; min-height: 0;
    }
    .ex-donut-title { font-size: 11px; font-weight: 800; color: #0B2D59; text-align: center; margin-bottom: 2px; }
    .ex-donut-chart-wrap {
      flex: 1; width: 100%; min-height: 120px; max-height: 160px; display: flex; align-items: center; justify-content: center;
    }
    .ex-donut-legend {
      display: grid; grid-template-columns: repeat(2, 1fr); gap: 4px 6px; width: 100%; margin-top: 4px;
    }
    .ex-donut-leg-item {
      display: flex; align-items: center; gap: 5px; font-size: 9.5px; font-weight: 700; color: #334155;
      white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    }
    .ex-donut-leg-box { width: 8px; height: 8px; border-radius: 2px; flex-shrink: 0; }

    .ex-footer {
      background: #EAF2F8; border-radius: 8px; border: 1px solid #D4E6F1; height: 32px;
      padding: 0 14px; display: flex; align-items: center; justify-content: space-between; flex-shrink: 0; overflow: hidden; position: relative;
    }
    .ex-footer-left { display: flex; align-items: center; gap: 8px; }
    .ex-footer-ic { display: flex; align-items: center; justify-content: center; color: #0B2D59; }
    .ex-footer-motto {
      font-size: 12px; font-weight: 600; font-style: italic; color: #0B2D59; letter-spacing: 0.01em; text-align: center; flex: 1;
    }
    .ex-footer-stripes {
      display: flex; gap: 4px; height: 100%; align-items: center; transform: skewX(-30deg); margin-right: -10px;
    }
    .ex-footer-stripe { width: 6px; height: 140%; }
    .ex-footer-stripe.s1 { background: #0284C7; }
    .ex-footer-stripe.s2 { background: #0B2D59; }
    .ex-footer-stripe.s3 { background: #38BDF8; }
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
        <?php
          $switchYearParams = ['id' => $targetFacultyId];
          if (!empty($from)) $switchYearParams['from'] = $from;
          if (input('return_dept')) $switchYearParams['return_dept'] = input('return_dept');
          if (input('return_cat'))  $switchYearParams['return_cat'] = input('return_cat');
          $switchYearBase = url('present-faculty-report.php') . '?' . http_build_query($switchYearParams) . '&academic_year=';
        ?>
        <select onchange="location.href='<?= e($switchYearBase) ?>' + this.value" style="background:#1E2B52; color:#fff; border:1px solid rgba(255,255,255,0.2); padding:4px 8px; border-radius:6px; font-size:12px; font-weight:600;">
          <?php foreach ($years as $y): ?>
            <option value="<?= e($y) ?>" <?= $academicYear === $y ? 'selected' : '' ?>><?= e($y) ?></option>
          <?php endforeach; ?>
        </select>
      </label>

      <button class="btn-hdr" onclick="toggleFullscreen()" title="Toggle Fullscreen View">
        <?= icon('maximize', 14) ?> <span id="fsText">Fullscreen</span>
      </button>

      <a href="<?= e($exitUrl) ?>" class="btn-hdr btn-hdr-brand" title="Exit Presentation"
         onclick="clearAutoTimer();">
        <?= icon('x', 14) ?> Exit Presentation
      </a>
    </div>
  </header>

  <!-- Viewport Area -->
  <main class="viewport">
    <div class="slide-card" id="slideCard">
      <div class="auto-bar" id="autoBar"></div>
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

      <!-- Presentation mode. Manual is active on load; Auto advances every 10s. -->
      <div class="mode-switch" role="group" aria-label="Presentation mode">
        <button type="button" class="mode-btn active" id="btnModeManual"
                onclick="setPresentationMode('manual')" title="Advance slides yourself">
          MANUAL MODE
        </button>
        <button type="button" class="mode-btn" id="btnModeAuto"
                onclick="setPresentationMode('auto')" title="Advance automatically every 10 seconds">
          <?= icon('play-circle', 13) ?> AUTO MODE
        </button>
      </div>

      <button class="btn-nav" id="btnNext" onclick="nextSlide()">
        Next <?= icon('arrow-right', 15) ?>
      </button>

      <span class="auto-count" id="autoCount"></span>
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

    /* ---- Auto / Manual presentation mode --------------------------------
       Manual is the default: nothing moves until the user asks for Auto.
       renderSlide() stays the one place a slide changes, so every path —
       Previous/Next, the dots, the arrow keys, and the timer itself — clears
       the countdown and restarts it for whichever slide ends up on screen.
       There is exactly ONE timer handle, and it is always cleared before
       another is started, so slides can never skip or double-advance. */

    const AUTO_ADVANCE_SECONDS = 10;   // 10 seconds per slide — not 3, 5 or 15
    let presentationMode = 'manual';   // default on open
    let autoTimer = null;              // the ONLY timer handle
    let autoRemaining = 0;

    function renderCountdown() {
      const el = document.getElementById('autoCount');
      if (!el) return;
      el.innerHTML = (presentationMode === 'auto' && autoTimer !== null)
        ? 'Next slide in <b>' + autoRemaining + '</b>s'
        : '';
    }

    /** Stop any running countdown and reset its progress bar. Safe to call twice. */
    function clearAutoTimer() {
      if (autoTimer !== null) {
        clearInterval(autoTimer);
        autoTimer = null;
      }
      const bar = document.getElementById('autoBar');
      if (bar) {
        bar.classList.remove('running');
        bar.style.animationDuration = '';
        bar.style.width = '0';
      }
      renderCountdown();
    }

    /** Start the 10-second countdown for the slide currently on screen. */
    function startAutoTimer() {
      clearAutoTimer();                            // never two timers at once
      if (presentationMode !== 'auto') return;

      // Auto stops at the final slide rather than looping or stacking timers.
      if (currentIndex >= slides.length - 1) {
        setPresentationMode('manual');
        return;
      }

      autoRemaining = AUTO_ADVANCE_SECONDS;
      renderCountdown();

      const bar = document.getElementById('autoBar');
      if (bar) {
        bar.style.animationDuration = AUTO_ADVANCE_SECONDS + 's';
        void bar.offsetWidth;                      // restart the CSS animation
        bar.classList.add('running');
      }

      // One interval, ten one-second ticks, then advance.
      autoTimer = setInterval(() => {
        autoRemaining -= 1;
        renderCountdown();
        if (autoRemaining <= 0) {
          clearAutoTimer();
          nextSlide();                             // re-renders and restarts
        }
      }, 1000);
    }

    /** Switch mode, keeping whichever slide is on screen. */
    function setPresentationMode(next) {
      presentationMode = (next === 'auto') ? 'auto' : 'manual';

      document.getElementById('btnModeAuto').classList.toggle('active', presentationMode === 'auto');
      document.getElementById('btnModeManual').classList.toggle('active', presentationMode === 'manual');

      if (presentationMode === 'auto') {
        startAutoTimer();     // counts 10s from the CURRENT slide, no skip
      } else {
        clearAutoTimer();     // manual stops the clock immediately
      }
    }

    /** Leave the deck, making sure no timer outlives the page. */
    function exitPresentation() {
      clearAutoTimer();
      if (document.fullscreenElement && document.exitFullscreen) {
        document.exitFullscreen().catch(() => {});
      }
      location.href = '<?= e($exitUrl) ?>';
    }

    // Belt and braces: whatever tears the page down, the timer goes with it.
    window.addEventListener('pagehide', clearAutoTimer);

    /* ---- Executive Chart & SVG Helpers ---- */

    function renderGroupedBarChart(top3) {
      if (!top3 || !top3.length) {
        return `<div style="display:flex;align-items:center;justify-content:center;height:100%;color:#94A3B8;font-size:12px;">No category target data available</div>`;
      }
      let maxVal = 1;
      top3.forEach(t => {
        maxVal = Math.max(maxVal, Number(t.target || 0), Number(t.achieved || 0), Number(t.in_progress || 0));
      });
      const yMax = Math.max(8, Math.ceil(maxVal / 4) * 4);

      const svgW = 460;
      const svgH = 220;
      const plotTop = 25;
      const plotBottom = 185;
      const plotHeight = plotBottom - plotTop;
      const plotLeft = 40;
      const plotRight = 445;
      const plotWidth = plotRight - plotLeft;

      let gridLines = '';
      const ticks = 4;
      for (let i = 0; i <= ticks; i++) {
        const val = Math.round((yMax / ticks) * i);
        const y = plotBottom - ((val / yMax) * plotHeight);
        gridLines += `<line x1="${plotLeft}" y1="${y}" x2="${plotRight}" y2="${y}" stroke="#E2E8F0" stroke-width="1" stroke-dasharray="${i === 0 ? '' : '3 3'}" />`;
        gridLines += `<text x="${plotLeft - 7}" y="${y + 4}" font-size="10" font-weight="600" fill="#64748B" text-anchor="end">${val}</text>`;
      }

      const groupSlot = plotWidth / top3.length;
      const barW = 18;
      const barGap = 3;
      const groupBarsW = (barW * 3) + (barGap * 2);

      let barsSvg = '';
      top3.forEach((item, idx) => {
        const centerX = plotLeft + (idx * groupSlot) + (groupSlot / 2);
        const startX = centerX - (groupBarsW / 2);

        const bars = [
          { val: Number(item.target || 0), color: '#168A53' },
          { val: Number(item.achieved || 0), color: '#1B65C5' },
          { val: Number(item.in_progress || 0), color: '#F59E0B' }
        ];

        bars.forEach((b, bIdx) => {
          const bx = startX + (bIdx * (barW + barGap));
          let h = (b.val / yMax) * plotHeight;
          if (b.val > 0 && h < 3) h = 3;
          const by = plotBottom - h;
          barsSvg += `<rect x="${bx}" y="${by}" width="${barW}" height="${h}" rx="2" ry="2" fill="${b.color}" />`;
          if (b.val > 0) {
            barsSvg += `<text x="${bx + barW / 2}" y="${by - 4}" font-size="10" font-weight="800" fill="#1E293B" text-anchor="middle">${b.val}</text>`;
          }
        });

        const code = escapeHtml(item.code || '');
        barsSvg += `<text x="${centerX}" y="206" font-size="12" font-weight="800" fill="#0B2D59" text-anchor="middle">${code}</text>`;
      });

      return `<svg viewBox="0 0 ${svgW} ${svgH}" width="100%" height="100%" preserveAspectRatio="xMidYMid meet">${gridLines}${barsSvg}</svg>`;
    }

    function renderDonutChart(slices, totalCount, centerSub) {
      const svgW = 200;
      const svgH = 200;
      const cx = 100;
      const cy = 100;
      const rOut = 80;
      const rIn = 48;
      centerSub = centerSub || 'Total Records';

      if (!totalCount || !slices || !slices.length) {
        return `<svg viewBox="0 0 ${svgW} ${svgH}" width="100%" height="100%">
          <circle cx="${cx}" cy="${cy}" r="${rOut}" fill="#F1F5F9" />
          <circle cx="${cx}" cy="${cy}" r="${rIn}" fill="#FFFFFF" />
          <text x="${cx}" y="${cy}" font-size="16" font-weight="800" fill="#0B2D59" text-anchor="middle" dominant-baseline="central">0</text>
          <text x="${cx}" y="${cy + 15}" font-size="8.5" font-weight="600" fill="#64748B" text-anchor="middle">${centerSub}</text>
        </svg>`;
      }

      let paths = '';
      let currentAngle = -90;

      slices.forEach(slice => {
        const pct = Number(slice.percentage || 0);
        if (pct <= 0) return;

        let sweep = (pct / 100) * 360;
        if (sweep >= 359.99) sweep = 359.99;

        const startRad = (currentAngle * Math.PI) / 180;
        const endRad = ((currentAngle + sweep) * Math.PI) / 180;

        const x1Out = cx + rOut * Math.cos(startRad);
        const y1Out = cy + rOut * Math.sin(startRad);
        const x2Out = cx + rOut * Math.cos(endRad);
        const y2Out = cy + rOut * Math.sin(endRad);

        const x1In = cx + rIn * Math.cos(endRad);
        const y1In = cy + rIn * Math.sin(endRad);
        const x2In = cx + rIn * Math.cos(startRad);
        const y2In = cy + rIn * Math.sin(startRad);

        const largeArc = sweep > 180 ? 1 : 0;
        const d = `M ${x1Out} ${y1Out} A ${rOut} ${rOut} 0 ${largeArc} 1 ${x2Out} ${y2Out} L ${x1In} ${y1In} A ${rIn} ${rIn} 0 ${largeArc} 0 ${x2In} ${y2In} Z`;

        const color = slice.color || '#1B65C5';
        paths += `<path d="${d}" fill="${color}" stroke="#FFFFFF" stroke-width="2" />`;

        if (pct >= 8) {
          const midRad = ((currentAngle + sweep / 2) * Math.PI) / 180;
          const lblR = (rOut + rIn) / 2;
          const lblX = cx + lblR * Math.cos(midRad);
          const lblY = cy + lblR * Math.sin(midRad);
          paths += `<text x="${lblX}" y="${lblY}" font-size="9.5" font-weight="800" fill="#FFFFFF" text-anchor="middle" dominant-baseline="central">${pct}%</text>`;
        }

        currentAngle += sweep;
      });

      const center = `
        <circle cx="${cx}" cy="${cy}" r="${rIn}" fill="#FFFFFF" />
        <text x="${cx}" y="${cy - 4}" font-size="18" font-weight="900" fill="#0B2D59" text-anchor="middle">${totalCount}</text>
        <text x="${cx}" y="${cy + 11}" font-size="8" font-weight="700" fill="#64748B" text-anchor="middle">${centerSub}</text>`;

      return `<svg viewBox="0 0 ${svgW} ${svgH}" width="100%" height="100%" preserveAspectRatio="xMidYMid meet">${paths}${center}</svg>`;
    }

    function renderExecutiveBanner(title, subtitle, motto) {
      subtitle = (subtitle || 'Research • Innovation • Global Impact').replace(/&middot;/g, ' • ');
      motto = motto || '';
      return `
        <div class="ex-banner">
          <div class="ex-banner-left">
            <div class="ex-banner-badge">
              <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="#FFFFFF" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                <polyline points="14 2 14 8 20 8"/>
                <circle cx="12" cy="14" r="3"/>
                <path d="m14 16.5 1.5 3-3.5-1.5-3.5 1.5 1.5-3"/>
              </svg>
            </div>
            <div class="ex-banner-divider"></div>
            <div class="ex-banner-titles">
              <div class="ex-banner-title">${escapeHtml(title)}</div>
              <div class="ex-banner-subtitle">${escapeHtml(subtitle)}</div>
            </div>
          </div>
          ${motto ? `
          <div class="ex-banner-right">
            <div class="ex-banner-motto">${escapeHtml(motto)}</div>
            <svg width="140" height="7" viewBox="0 0 140 7" fill="none" style="margin-top:2px;">
              <path d="M2 5 C 45 1, 95 6, 138 2" stroke="#60A5FA" stroke-width="2" stroke-linecap="round"/>
            </svg>
          </div>` : ''}
        </div>`;
    }

    function renderExecutiveKpis(fixed, achieved, achievedPct, inProg, inProgPct, total, totalSub) {
      totalSub = totalSub || '(Achieved + In Progress)';
      const achPctText = (achievedPct !== null && achievedPct !== undefined) ? `(${achievedPct}%)` : '';
      const inProgPctText = (inProgPct !== null && inProgPct !== undefined) ? `(${inProgPct}%)` : '';

      return `
        <div class="ex-kpi-grid">
          <div class="ex-kpi-card blue">
            <div class="ex-kpi-ic">
              <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#0B2D59" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="10"/>
                <circle cx="12" cy="12" r="6"/>
                <circle cx="12" cy="12" r="2"/>
                <line x1="12" y1="2" x2="12" y2="4"/>
                <line x1="12" y1="20" x2="12" y2="22"/>
                <line x1="2" y1="12" x2="4" y2="12"/>
                <line x1="20" y1="12" x2="22" y2="12"/>
              </svg>
            </div>
            <div class="ex-kpi-info">
              <div class="ex-kpi-label">Target Fixed</div>
              <div class="ex-kpi-val-col">
                <span class="ex-kpi-val">${escapeHtml(String(fixed))}</span>
              </div>
            </div>
          </div>

          <div class="ex-kpi-card green">
            <div class="ex-kpi-ic">
              <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#168A53" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="10" fill="#E8F8F0"/>
                <path d="m8 12 3 3 6-6" stroke-width="2.6"/>
              </svg>
            </div>
            <div class="ex-kpi-info">
              <div class="ex-kpi-label">Target Achieved</div>
              <div class="ex-kpi-val-col">
                <span class="ex-kpi-val">${escapeHtml(String(achieved))}</span>
                ${achPctText ? `<span class="ex-kpi-pct">${escapeHtml(achPctText)}</span>` : ''}
              </div>
            </div>
          </div>

          <div class="ex-kpi-card amber">
            <div class="ex-kpi-ic">
              <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#D97706" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="10" fill="#FEF7E6"/>
                <polyline points="12 6 12 12 16 14" stroke-width="2.6"/>
              </svg>
            </div>
            <div class="ex-kpi-info">
              <div class="ex-kpi-label">In Progress</div>
              <div class="ex-kpi-val-col">
                <span class="ex-kpi-val">${escapeHtml(String(inProg))}</span>
                ${inProgPctText ? `<span class="ex-kpi-pct">${escapeHtml(inProgPctText)}</span>` : ''}
              </div>
            </div>
          </div>

          <div class="ex-kpi-card purple">
            <div class="ex-kpi-ic">
              <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#6D28D9" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                <polyline points="14 2 14 8 20 8"/>
                <line x1="8" y1="13" x2="16" y2="13"/>
                <line x1="8" y1="17" x2="14" y2="17"/>
              </svg>
            </div>
            <div class="ex-kpi-info">
              <div class="ex-kpi-label">Total Publications</div>
              <div class="ex-kpi-sub">${escapeHtml(totalSub)}</div>
              <div class="ex-kpi-val-col">
                <span class="ex-kpi-val">${escapeHtml(String(total))}</span>
              </div>
            </div>
          </div>
        </div>`;
    }

    function renderExecutiveFooter(motto) {
      motto = motto || '';
      return `
        <div class="ex-footer">
          <div class="ex-footer-left">
            <div class="ex-footer-ic">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#0B2D59" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/>
                <path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/>
              </svg>
            </div>
          </div>
          <div class="ex-footer-motto">${motto ? escapeHtml(motto) : ''}</div>
          <div class="ex-footer-stripes">
            <span class="ex-footer-stripe s1"></span>
            <span class="ex-footer-stripe s2"></span>
            <span class="ex-footer-stripe s3"></span>
          </div>
        </div>`;
    }

    function renderExecutiveSplitRow(contrib, basedOnLabel) {
      if (!contrib) {
        return `<div style="flex:1;display:flex;align-items:center;justify-content:center;color:#94A3B8;">No category contribution data available.</div>`;
      }
      basedOnLabel = basedOnLabel || 'Faculty Category Achievements';

      const top3 = contrib.top3 || [];
      const barSvg = renderGroupedBarChart(top3);

      const tableRows = (contrib.items || []).map(item => `
        <tr>
          <td title="${escapeHtml(item.name)}">${escapeHtml(item.code)}</td>
          <td>${escapeHtml(String(item.target))}</td>
          <td>${escapeHtml(String(item.achieved))}</td>
          <td>${escapeHtml(String(item.in_progress))}</td>
          <td style="font-weight:700;">${escapeHtml(String(item.total))}</td>
        </tr>
      `).join('');

      const donutSvg = renderDonutChart(contrib.donut_slices || [], contrib.total_count || 0, 'Total Records');

      const donutLegend = (contrib.donut_slices || []).map(sl => `
        <div class="ex-donut-leg-item" title="${escapeHtml(sl.label)}: ${escapeHtml(String(sl.count))} (${escapeHtml(String(sl.percentage))}%)">
          <span class="ex-donut-leg-box" style="background:${escapeHtml(sl.color)}"></span>
          <span style="overflow:hidden;text-overflow:ellipsis;">${escapeHtml(sl.label)} ${escapeHtml(String(sl.count))} (${escapeHtml(String(sl.percentage))}%)</span>
        </div>
      `).join('');

      return `
        <div class="ex-split-row">
          <!-- Left Panel: Top 3 Categories -->
          <div class="ex-panel">
            <div class="ex-panel-hdr">
              <div>
                <div class="ex-panel-title">${escapeHtml(contrib.sub_title || 'Top 3 Categories')}</div>
                <div class="ex-panel-sub">(Based on ${escapeHtml(basedOnLabel)})</div>
              </div>
            </div>
            <div class="ex-panel-body">
              <div class="ex-barchart-legend">
                <span class="ex-leg-item"><span class="ex-leg-color green"></span> Target Fixed</span>
                <span class="ex-leg-item"><span class="ex-leg-color blue"></span> Achieved</span>
                <span class="ex-leg-item"><span class="ex-leg-color amber"></span> In Progress</span>
              </div>
              <div class="ex-barchart-wrap">
                ${barSvg}
              </div>
            </div>
          </div>

          <!-- Right Panel: All Categories Contribution -->
          <div class="ex-panel">
            <div class="ex-panel-hdr">
              <div class="ex-panel-title">${escapeHtml(contrib.title || 'All Categories Contribution')}</div>
            </div>
            <div class="ex-panel-body">
              <div class="ex-contrib-grid">
                <div class="ex-table-wrap">
                  <table class="ex-table">
                    <thead>
                      <tr>
                        <th>Category</th>
                        <th>Target Fixed</th>
                        <th>Achieved</th>
                        <th>In Progress</th>
                        <th>Total</th>
                      </tr>
                    </thead>
                    <tbody>
                      ${tableRows}
                    </tbody>
                    <tfoot>
                      <tr>
                        <td>Total</td>
                        <td>${escapeHtml(String(contrib.total_target))}</td>
                        <td>${escapeHtml(String(contrib.total_achieved))}</td>
                        <td>${escapeHtml(String(contrib.total_in_prog))}</td>
                        <td>${escapeHtml(String(contrib.total_count))}</td>
                      </tr>
                    </tfoot>
                  </table>
                </div>

                <div class="ex-donut-side">
                  <div class="ex-donut-title">${escapeHtml(contrib.chart_label || 'Category-wise Contribution')}</div>
                  <div class="ex-donut-chart-wrap">
                    ${donutSvg}
                  </div>
                  <div class="ex-donut-legend">
                    ${donutLegend}
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>`;
    }

    function renderSlide(index) {
      if (index < 0 || index >= slides.length) return;
      currentIndex = index;

      clearAutoTimer();   // the outgoing slide's countdown never survives

      const slide = slides[currentIndex];
      const card = document.getElementById('slideCard');
      const content = document.getElementById('slideContent');

      card.classList.remove('active');

      setTimeout(() => {
        let html = '';

        if (slide.type === 'target_summary') {
          const f = slide.faculty;
          const c = slide.contributions;
          const fixed = c ? c.total_target : (slide.academic_targets || 0);
          const achieved = c ? c.total_achieved : (slide.targets_achieved || 0);
          const inProg = c ? c.total_in_prog : (slide.targets_in_progress || 0);
          const total = c ? c.total_count : (achieved + inProg);
          const achPct = fixed > 0 ? Math.round((achieved / fixed) * 100) : 0;
          const inProgPct = fixed > 0 ? Math.round((inProg / fixed) * 100) : 0;

          html = `
            <div class="ex-wrap">
              ${renderExecutiveBanner('Summary of Target Achievements', `Dept. of ${escapeHtml(f.department)} &middot; AY ${escapeHtml(slide.academic_year)}`)}
              ${renderExecutiveKpis(fixed, achieved, achPct, inProg, inProgPct, total, '(Achieved + In Progress)')}
              ${renderExecutiveSplitRow(c, 'Academic Target Achievements')}
              ${renderExecutiveFooter()}
            </div>
          `;
        } else if (slide.type === 'intro') {
          const f = slide.faculty;
          const o = slide.overall;
          const c = slide.contributions;
          const fixed = o.configured ? o.target : (c ? c.total_target : 0);
          const achieved = o.achieved;
          const achPct = (o.configured && o.percentage !== null) ? o.percentage : (fixed > 0 ? Math.round((achieved/fixed)*100) : null);
          const inProg = c ? c.total_in_prog : (o.remaining || 0);

          html = `
            <div class="ex-wrap">
              ${renderExecutiveBanner(slide.title, `${escapeHtml(f.name)} &middot; ${escapeHtml(f.designation)} &middot; Dept. of ${escapeHtml(f.department)}`)}
              ${renderExecutiveKpis(fixed || 'Not Set', achieved, achPct, inProg, null, achieved + inProg, '(Verified Achievements)')}
              <div style="flex:1; display:flex; flex-direction:column; justify-content:center; align-items:center; background:#F8FAFC; border:1px solid #E2E8F0; border-radius:10px; padding:28px; text-align:center;">
                <div class="intro-badge" style="margin-bottom:12px;"><?= icon('presentation', 13) ?> Individual Faculty Review</div>
                <h1 style="font-size:32px; font-weight:800; color:#0B2D59;">${escapeHtml(f.name)}</h1>
                <div style="font-size:16px; color:#475569; font-weight:600; margin-top:6px;">
                  ${escapeHtml(f.designation)} &middot; Department of ${escapeHtml(f.department)}
                </div>
                <div style="margin-top:8px; font-size:13.5px; color:#64748B;">
                  Employee ID: <strong>${escapeHtml(f.employee_id)}</strong> &middot; Academic Year: <strong>${escapeHtml(slide.academic_year)}</strong>
                </div>
                <div style="margin-top:20px; font-size:14px; max-width:600px; color:#64748B; line-height:1.5;">
                  Comprehensive individual faculty portfolio reviewing publication metrics, sponsored research, consultancy, patents, and faculty development achievements.
                </div>
              </div>
              ${renderExecutiveFooter()}
            </div>
          `;
        } else if (slide.type === 'overall_summary') {
          const o = slide.overall;
          const c = slide.contributions;
          const fixed = o.configured ? o.target : (c ? c.total_target : 0);
          const achieved = o.achieved;
          const inProg = c ? c.total_in_prog : (o.remaining || 0);
          const total = c ? c.total_count : (achieved + inProg);
          const achPct = (o.configured && o.percentage !== null) ? o.percentage : (fixed > 0 ? Math.round((achieved/fixed)*100) : 0);
          const inProgPct = fixed > 0 ? Math.round((inProg / fixed) * 100) : 0;

          html = `
            <div class="ex-wrap">
              ${renderExecutiveBanner('Target vs Achievement Summary', `Overall Performance Summary &middot; AY ${escapeHtml(slide.academic_year)}`)}
              ${renderExecutiveKpis(fixed || 'Not Set', achieved, achPct, inProg, inProgPct, total, '(Achieved + In Progress)')}
              ${renderExecutiveSplitRow(c, 'Faculty Academic Achievements')}
              ${renderExecutiveFooter()}
            </div>
          `;
        } else if (slide.type === 'category') {
          const f = slide.faculty;
          const tgtText = slide.configured ? slide.target : 'Not Configured';
          const remText = slide.configured ? slide.remaining : 'N/A';
          const pctText = (slide.configured && slide.percentage !== null) ? slide.percentage + '%' : 'N/A';
          const pageInfo = slide.total_pages > 1 ? `Page ${slide.page} of ${slide.total_pages}` : `${slide.achieved} record${slide.achieved === 1 ? '' : 's'}`;

          const recRows = (slide.records || []).map((r, i) => `
            <tr>
              <td style="width:38px; color:#64748B; text-align:center;">${(slide.page - 1) * 4 + i + 1}</td>
              <td>
                <div style="font-weight:700; color:#0B2D59;">${escapeHtml(r.title || '(untitled)')}</div>
                ${r.description ? `<div style="font-size:11.5px; color:#64748B; margin-top:2px;">${escapeHtml(r.description)}</div>` : ''}
              </td>
              <td><span class="pill">${escapeHtml(r.type_label || slide.category_label)}</span></td>
              <td style="font-size:12px; color:#64748B;">${escapeHtml(r.event_date || r.academic_year || '')}</td>
              <td><span class="pill pill-ok">${escapeHtml(r.status || 'Verified')}</span></td>
            </tr>
          `).join('');

          html = `
            <div class="ex-wrap">
              ${renderExecutiveBanner(slide.category_label, `Faculty Achievements &middot; AY ${escapeHtml(slide.academic_year)} &middot; ${pageInfo}`)}
              ${renderExecutiveKpis(tgtText, slide.achieved, slide.configured ? slide.percentage : null, remText, null, slide.achieved, '(Category Submissions)')}
              <div class="ex-panel" style="flex:1; min-height:0; display:flex; flex-direction:column;">
                <div class="ex-panel-hdr">
                  <div class="ex-panel-title">${escapeHtml(slide.category_label)} &mdash; Documented Achievements</div>
                  <div class="ex-panel-sub">${pageInfo}</div>
                </div>
                <div style="flex:1; min-height:0; overflow-y:auto;">
                  <table class="sl">
                    <thead>
                      <tr style="background:#0B2D59; color:#FFFFFF;">
                        <th style="background:#0B2D59; color:#FFFFFF; width:38px;">#</th>
                        <th style="background:#0B2D59; color:#FFFFFF;">Title / Description</th>
                        <th style="background:#0B2D59; color:#FFFFFF;">Category</th>
                        <th style="background:#0B2D59; color:#FFFFFF;">Date</th>
                        <th style="background:#0B2D59; color:#FFFFFF;">Status</th>
                      </tr>
                    </thead>
                    <tbody>${recRows}</tbody>
                  </table>
                </div>
              </div>
              ${renderExecutiveFooter()}
            </div>
          `;
        } else if (slide.type === 'final_summary') {
          const o = slide.overall;
          const c = slide.contributions;
          const fixed = o.configured ? o.target : (c ? c.total_target : 0);
          const achieved = o.achieved;
          const inProg = c ? c.total_in_prog : (o.remaining || 0);
          const total = c ? c.total_count : (achieved + inProg);
          const achPct = (o.configured && o.percentage !== null) ? o.percentage : (fixed > 0 ? Math.round((achieved/fixed)*100) : 0);

          const catRows = (slide.categories || []).map(cat => {
            const tgt = cat.configured ? cat.target : 'Not Configured';
            const rem = cat.configured ? cat.remaining : 'N/A';
            const pct = (cat.configured && cat.percentage !== null) ? cat.percentage + '%' : 'N/A';
            return `
              <tr>
                <td style="font-weight:700; color:#0B2D59;">${escapeHtml(cat.label)}</td>
                <td style="text-align:right;">${tgt}</td>
                <td style="text-align:right; font-weight:800; color:#168A53;">${cat.achieved}</td>
                <td style="text-align:right;">${rem}</td>
                <td style="text-align:right; font-weight:700;">${pct}</td>
              </tr>`;
          }).join('');

          html = `
            <div class="ex-wrap">
              ${renderExecutiveBanner('Faculty Achievement Summary Matrix', `Consolidated Faculty Review &middot; AY ${escapeHtml(slide.academic_year)}`)}
              ${renderExecutiveKpis(fixed || 'Not Set', achieved, achPct, inProg, null, total, '(Total Verified)')}
              <div class="ex-panel" style="flex:1; min-height:0; display:flex; flex-direction:column;">
                <div class="ex-panel-hdr">
                  <div class="ex-panel-title">Institutional Category Matrix</div>
                </div>
                <div style="flex:1; min-height:0; overflow-y:auto;">
                  <table class="sl">
                    <thead>
                      <tr style="background:#0B2D59; color:#FFFFFF;">
                        <th style="background:#0B2D59; color:#FFFFFF;">Category Name</th>
                        <th style="background:#0B2D59; color:#FFFFFF; text-align:right;">Target</th>
                        <th style="background:#0B2D59; color:#FFFFFF; text-align:right;">Achieved</th>
                        <th style="background:#0B2D59; color:#FFFFFF; text-align:right;">Remaining</th>
                        <th style="background:#0B2D59; color:#FFFFFF; text-align:right;">Achievement %</th>
                      </tr>
                    </thead>
                    <tbody>${catRows}</tbody>
                  </table>
                </div>
              </div>
              ${renderExecutiveFooter()}
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

        // Restart the countdown for the slide now on screen. A no-op unless
        // Auto is on, so Manual stays completely still.
        startAutoTimer();
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
        exitPresentation();
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
