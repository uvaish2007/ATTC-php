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
 * Auto mode advances every 5 seconds (EM_AUTO_ADVANCE_MS); manual mode stops
 * the timer entirely. One timer handle exists, and it is always cleared before
 * another is started.
 */

require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/icons.php';
require_once __DIR__ . '/models/ExecutiveMeetingReport.php';

if (!defined('REPORT_INSTITUTION')) {
    define('REPORT_INSTITUTION', 'Mohamed Sathak Engineering College');
}

/** Auto mode dwell time per slide: 5 seconds (EM-SPEC-09). */
if (!defined('EM_AUTO_ADVANCE_MS')) {
    define('EM_AUTO_ADVANCE_MS', 5000);
}

$user = require_login();

// Re-validated server-side: a hand-edited query string cannot widen the scope.
$filters = em_resolve_filters($user, [
    'department'     => input('department'),
    'academic_year'  => input('academic_year'),
    'faculty_id'     => input('faculty_id'),
    'student_reg'    => input('student_reg'),
    'target_metric'  => input('target_metric'),   // one target type, or all
    'meeting_number' => input('meeting_number'),
    'em'             => input('em'),   // FEAT-07 EM1 / EM2 / All
]);

// EM-SPEC-05: Server-side validation for active session presentation.
// When launched as an active session, verify that the meeting is currently in session and not closed/locked.
if ((string) input('active_session') === '1') {
    $emStatus = em_status($filters['year']);
    $emReq = $filters['em'] ?? 'em1';
    if ($emReq === 'em1') {
        $em1Active = !empty($emStatus['configured'])
            && ($emStatus['current'] === 'em1' || ($emStatus['em1']['state'] ?? '') === 'ACTIVE')
            && empty($emStatus['em1_locked']);
        if (!$em1Active) {
            http_response_code(403);
            echo "<!DOCTYPE html><html lang='en'><head><meta charset='UTF-8'><title>EM1 Presentation Closed</title><link rel='stylesheet' href='" . e(url('assets/css/app.css')) . "'></head><body style='background:#0D1427; color:#fff; display:flex; align-items:center; justify-content:center; height:100vh; font-family:sans-serif; margin:0;'><div style='text-align:center; padding:32px; background:#1E2B52; border-radius:12px; max-width:480px; box-shadow:0 20px 50px rgba(0,0,0,0.5);'><h2 style='color:#F8FAFC; margin-bottom:12px;'>EM1 Presentation Closed</h2><p style='color:#94A3B8; font-size:14px; line-height:1.5;'>Executive Meeting 1 for " . e($filters['year']) . " has ended and is locked. Active session presentation is not permitted.</p><p style='margin-top:24px;'><a href='" . e(url('dashboard.php')) . "' class='btn btn-primary btn-sm' style='text-decoration:none;'>&larr; Return to Dashboard</a></p></div></body></html>";
            exit;
        }
    }
}

$dataset = em_dataset($user, $filters);
$slides  = em_slides($dataset);

$slidesJson = json_encode($slides, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
$exitUrl    = ((string) input('active_session') === '1')
    ? url('dashboard.php')
    : url('executive-meeting-report.php') . '?' . http_build_query(em_filter_query($filters));
// EM-SPEC-05: shown inside the full-screen modal. Leaving then closes that modal.
$embed      = (string) input('embed') === '1';
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
      padding: 16px 20px; position: relative; overflow: hidden; }
    .slide-card {
      background: var(--surface); color: var(--ink);
      width: 100%; max-width: 1260px; height: 100%; max-height: 720px;
      border-radius: 16px; box-shadow: 0 20px 50px rgba(0,0,0,0.5);
      display: flex; flex-direction: column; position: relative; overflow: hidden;
      opacity: 1; transform: translateY(0) scale(1);
      transition: opacity .2s ease, transform .2s ease;
    }
    .slide-card.active { opacity: 1; transform: translateY(0) scale(1); }
    .slide-body { padding: 12px 16px; flex: 1; overflow: hidden; display: flex; flex-direction: column; min-height: 0; }

    /* Auto-mode countdown, so the 5 seconds are visible. */
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
      transition: transform 0.2s ease, box-shadow 0.2s ease;
    }
    .ts-card:hover {
      transform: translateY(-2px); box-shadow: 0 12px 28px rgba(0,0,0,0.09);
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

    /* ---- Category Metrics & Dynamic Slides (EM-SPEC-06) ---- */
    .ex-cat-grid {
      display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; height: 100%; min-height: 0;
    }
    .ex-cat-card {
      background: #FFFFFF; border: 1px solid #CBD5E1; border-radius: 10px; padding: 12px 16px;
      display: flex; align-items: center; gap: 14px; box-shadow: 0 2px 8px rgba(0,0,0,0.03);
      transition: transform 0.15s ease, border-color 0.15s ease;
    }
    .ex-cat-card:hover { transform: translateY(-2px); border-color: #94A3B8; }
    .ex-cat-ic {
      width: 44px; height: 44px; border-radius: 10px; display: flex; align-items: center; justify-content: center; flex-shrink: 0;
    }
    .ex-cat-val { font-size: 26px; font-weight: 900; color: #0B2D59; line-height: 1.1; }
    .ex-cat-lbl { font-size: 11.5px; font-weight: 700; color: #475569; margin-top: 2px; }

    .ex-em-grid {
      display: grid; grid-template-columns: 1fr 1fr; gap: 16px; width: 100%; max-width: 800px; margin-top: 14px;
    }
    .ex-em-card {
      background: #FFFFFF; border: 1px solid #CBD5E1; border-radius: 10px; padding: 14px 18px;
      box-shadow: 0 4px 12px rgba(0,0,0,0.04); text-align: left;
    }

    /* ---- Target vs Achieved Slide (EM-SPEC-08) ---- */
    .ex-target-table {
      width: 100%; border-collapse: separate; border-spacing: 0; font-size: 12px;
    }
    .ex-target-table thead th {
      background: #0B2D59; color: #FFFFFF; font-size: 11px; font-weight: 800;
      text-transform: uppercase; letter-spacing: 0.04em; padding: 10px 14px; position: sticky; top: 0; z-index: 2;
    }
    .ex-target-table tbody tr {
      background: #FFFFFF; transition: background 0.15s ease;
    }
    .ex-target-table tbody tr:nth-child(even) { background: #F8FAFC; }
    .ex-target-table tbody tr:hover { background: #F1F5F9; }
    .ex-target-table tbody td {
      padding: 10px 14px; border-bottom: 1px solid #E2E8F0; vertical-align: middle;
    }
    .ex-target-metric-name {
      font-size: 13px; font-weight: 800; color: #0B2D59; line-height: 1.2;
    }
    .ex-target-metric-dept {
      font-size: 11px; color: #64748B; font-weight: 600; margin-top: 2px;
    }
    .ex-target-progress-wrap {
      display: flex; flex-direction: column; gap: 3px; width: 100%;
    }
    .ex-target-bar-bg {
      height: 12px; background: #E2E8F0; border-radius: 999px; overflow: hidden; position: relative; border: 1px solid #CBD5E1;
    }
    .ex-target-bar-fill {
      height: 100%; border-radius: 999px; transition: width 0.3s ease;
    }
    .ex-target-bar-fill.green { background: linear-gradient(90deg, #10B981, #059669); }
    .ex-target-bar-fill.blue  { background: linear-gradient(90deg, #3B82F6, #1D4ED8); }
    .ex-target-bar-fill.amber { background: linear-gradient(90deg, #F59E0B, #D97706); }
    .ex-target-bar-fill.gray  { background: #94A3B8; }

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
      .ts-grid { grid-template-columns: 1fr; }
      .ts-banner-title { font-size: 26px; }
      .ts-card-val { font-size: 54px; }
      .ts-card-body { min-height: 100px; padding: 14px; }
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
        <button type="button" class="mode-btn active" id="btnAuto" onclick="setMode('auto')" title="Advance automatically every 5 seconds">
          <?= icon('play-circle', 14) ?> Auto Mode
        </button>
        <button type="button" class="mode-btn" id="btnManual" onclick="setMode('manual')" title="Advance only when you choose">
          <?= icon('grip', 14) ?> Manual Mode
        </button>
      </div>

      <button type="button" class="btn-hdr" onclick="toggleFullscreen()" title="Toggle full screen">
        <?= icon('maximize', 14) ?> <span id="fsText">Fullscreen</span>
      </button>

      <?php if ($embed): ?>
        <button type="button" class="btn-hdr btn-hdr-brand" onclick="exitPresentation()" title="Close presentation (Esc)">
          <?= icon('x', 14) ?> Exit
        </button>
      <?php else: ?>
        <a href="<?= e($exitUrl) ?>" class="btn-hdr btn-hdr-brand" title="Exit presentation (Esc)">
          <?= icon('x', 14) ?> Exit
        </a>
      <?php endif; ?>
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

    <div class="slide-counter" id="slideCounter">Slide <b>1</b> / <?= count($slides) ?></div>
  </footer>

  <script>
    const slides = <?= $slidesJson ?>;
    const EMBEDDED = <?= $embed ? 'true' : 'false' ?>;

    /* Auto mode dwell: 5 seconds per slide (EM-SPEC-09). */
    const AUTO_ADVANCE_MS = <?= EM_AUTO_ADVANCE_MS ?>;

    /* Rows per table slide, so continued pages keep numbering in sequence. */
    const SLIDE_ROWS = <?= EM_SLIDE_ROWS ?>;

    let currentIndex = 0;
    let mode = 'auto';           // EM-SPEC-09: start in auto mode by default
    let autoTimer = null;        // the ONLY auto-play timer handle; never more than one
    let renderTimer = null;      // the transition render timer handle
    let keyListenerAttached = false;

    /* ---- Timer control -------------------------------------------------- */

    function clearAutoTimer() {
      if (autoTimer !== null) {
        clearTimeout(autoTimer);
        autoTimer = null;
      }
      const bar = document.getElementById('autoBar');
      if (bar) {
        bar.classList.remove('running');
        bar.style.animationDuration = '';
        bar.style.width = '0';
      }
    }

    /* Starts the 5000ms auto timer. Clears any existing timer first, guaranteeing
       only one active auto-play timer at all times. */
    function startAutoTimer() {
      clearAutoTimer();
      if (mode !== 'auto') return;
      if (currentIndex >= slides.length - 1) return;   // stop on the final slide

      const bar = document.getElementById('autoBar');
      if (bar) {
        bar.style.animationDuration = AUTO_ADVANCE_MS + 'ms';
        void bar.offsetWidth;                            // restart the animation
        bar.classList.add('running');
      }

      autoTimer = setTimeout(() => {
        autoTimer = null;
        nextSlide(false);
      }, AUTO_ADVANCE_MS);
    }

    function setMode(next) {
      mode = (next === 'auto') ? 'auto' : 'manual';
      const btnAuto = document.getElementById('btnAuto');
      const btnManual = document.getElementById('btnManual');
      if (btnAuto) btnAuto.classList.toggle('active', mode === 'auto');
      if (btnManual) btnManual.classList.toggle('active', mode === 'manual');

      if (mode === 'auto') {
        startAutoTimer();        // resumes from whichever slide is showing
      } else {
        clearAutoTimer();        // manual stops the clock immediately
      }
    }

    /* ---- Navigation ----------------------------------------------------- */

    function updateSlideIndicator() {
      const counter = document.getElementById('slideCounter');
      if (!counter) return;
      const cur = currentIndex + 1;
      const total = slides.length;
      counter.innerHTML = 'Slide <b>' + cur + '</b> / ' + total;
      counter.setAttribute('data-slide', cur + ' / ' + total);
      counter.setAttribute('aria-label', cur + ' / ' + total);
    }

    function updateNavButtons() {
      const btnPrev = document.getElementById('btnPrev');
      const btnNext = document.getElementById('btnNext');
      if (btnPrev) btnPrev.disabled = currentIndex === 0;
      if (btnNext) btnNext.disabled = currentIndex === slides.length - 1;
    }

    function updateDots() {
      const dots = document.getElementById('dotsContainer');
      if (!dots) return;
      dots.innerHTML = '';
      slides.forEach((_, i) => {
        const dot = document.createElement('div');
        dot.className = 'dot' + (i === currentIndex ? ' active' : '');
        dot.title = 'Slide ' + (i + 1);
        dot.onclick = () => renderSlide(i, true);
        dots.appendChild(dot);
      });
    }

    function renderSlide(index, userDriven) {
      if (index < 0 || index >= slides.length) return;
      currentIndex = index;

      // Cancel any active auto timer immediately so manual navigation overrides
      clearAutoTimer();

      // Clear any pending render transition timeout so rapid navigation doesn't stack
      if (renderTimer !== null) {
        clearTimeout(renderTimer);
        renderTimer = null;
      }

      const card = document.getElementById('slideCard');
      const content = document.getElementById('slideContent');
      if (card) card.classList.remove('active');

      renderTimer = setTimeout(() => {
        renderTimer = null;
        if (content) {
          content.innerHTML = buildSlide(slides[currentIndex]);
          content.scrollTop = 0;
        }
        if (card) card.classList.add('active');

        updateSlideIndicator();
        updateNavButtons();
        updateDots();

        // Resets the 5-second auto timer so newly selected slide remains visible for ~5s
        startAutoTimer();
      }, 120);
    }

    // EM-SPEC-09: boundary behavior — stops at first/last (no unexpected loop).
    function prevSlide(userDriven = true) {
      if (currentIndex > 0) renderSlide(currentIndex - 1, userDriven);
    }
    function nextSlide(userDriven = true) {
      if (currentIndex < slides.length - 1) renderSlide(currentIndex + 1, userDriven);
    }
    function firstSlide() { renderSlide(0, true); }
    function lastSlide() { renderSlide(slides.length - 1, true); }

    function exitPresentation() {
      clearAutoTimer();
      if (renderTimer !== null) {
        clearTimeout(renderTimer);
        renderTimer = null;
      }
      removeKeydownListener();

      if (document.fullscreenElement && document.exitFullscreen) {
        document.exitFullscreen().catch(() => {});
      }
      if (EMBEDDED) {
        // Ask the report page to close the modal this is running in.
        window.parent.postMessage({ atts: 'em-present-close' }, window.location.origin);
        return;
      }
      location.href = '<?= e($exitUrl) ?>';
    }

    /* ---- Keyboard ------------------------------------------------------- */
    // EM-SPEC-09: Input-field safety: do NOT navigate slides when the user is
    // typing in an input, textarea, or select element.
    function handleKeydown(e) {
      const tag = (document.activeElement || {}).tagName || '';
      const inInput = (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT'
        || (document.activeElement || {}).isContentEditable);

      if (inInput) return; // Do not interfere with typing inside inputs/textareas

      switch (e.key) {
        case 'ArrowRight':
        case 'PageDown':  e.preventDefault(); nextSlide(true); break;
        case 'ArrowLeft':
        case 'PageUp':    e.preventDefault(); prevSlide(true); break;
        case ' ':         e.preventDefault(); nextSlide(true); break;
        case 'Home':      e.preventDefault(); firstSlide(); break;
        case 'End':       e.preventDefault(); lastSlide(); break;
        case 'Escape':    e.preventDefault(); exitPresentation(); break;
        case 'a': case 'A':
          setMode(mode === 'auto' ? 'manual' : 'auto'); break;
      }
    }

    function attachKeydownListener() {
      if (!keyListenerAttached) {
        document.addEventListener('keydown', handleKeydown);
        keyListenerAttached = true;
      }
    }

    function removeKeydownListener() {
      if (keyListenerAttached) {
        document.removeEventListener('keydown', handleKeydown);
        keyListenerAttached = false;
      }
    }

    // Support navigation forwarded from parent window (when embedded in an iframe modal)
    window.addEventListener('message', function (e) {
      if (e.origin !== window.location.origin) return;
      if (e.data && e.data.atts === 'em-nav') {
        if (e.data.key === 'ArrowRight' || e.data.key === 'PageDown' || e.data.key === ' ') {
          nextSlide(true);
        } else if (e.data.key === 'ArrowLeft' || e.data.key === 'PageUp') {
          prevSlide(true);
        } else if (e.data.key === 'Escape') {
          exitPresentation();
        }
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

    /* ---- Executive Chart & SVG Helpers ---- */

    function renderIconSvg(name) {
      switch (name) {
        case 'book-open':
          return `<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg>`;
        case 'users':
          return `<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>`;
        case 'file-text':
          return `<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>`;
        case 'calendar':
          return `<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>`;
        case 'briefcase':
          return `<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="7" width="20" height="14" rx="2" ry="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg>`;
        case 'shield':
          return `<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>`;
        case 'award':
          return `<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="7"/><polyline points="8.21 13.89 7 23 12 20 17 23 15.79 13.88"/></svg>`;
        case 'graduation':
          return `<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c3 3 9 3 12 0v-5"/></svg>`;
        case 'check-circle':
          return `<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>`;
        default:
          return `<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/></svg>`;
      }
    }

    function renderGroupedBarChart(top3) {
      if (!top3 || !top3.length) {
        return `<div style="display:flex;align-items:center;justify-content:center;height:100%;color:#94A3B8;font-size:12px;">No department target data available</div>`;
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

        const code = esc(item.code || '');
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
              <div class="ex-banner-title">${esc(title)}</div>
              <div class="ex-banner-subtitle">${esc(subtitle)}</div>
            </div>
          </div>
          ${motto ? `
          <div class="ex-banner-right">
            <div class="ex-banner-motto">${esc(motto)}</div>
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
                <span class="ex-kpi-val">${esc(fixed)}</span>
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
                <span class="ex-kpi-val">${esc(achieved)}</span>
                ${achPctText ? `<span class="ex-kpi-pct">${esc(achPctText)}</span>` : ''}
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
                <span class="ex-kpi-val">${esc(inProg)}</span>
                ${inProgPctText ? `<span class="ex-kpi-pct">${esc(inProgPctText)}</span>` : ''}
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
              <div class="ex-kpi-sub">${esc(totalSub)}</div>
              <div class="ex-kpi-val-col">
                <span class="ex-kpi-val">${esc(total)}</span>
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
          <div class="ex-footer-motto">${motto ? esc(motto) : ''}</div>
          <div class="ex-footer-stripes">
            <span class="ex-footer-stripe s1"></span>
            <span class="ex-footer-stripe s2"></span>
            <span class="ex-footer-stripe s3"></span>
          </div>
        </div>`;
    }

    function renderExecutiveSplitRow(contrib, basedOnLabel) {
      if (!contrib) {
        return `<div style="flex:1;display:flex;align-items:center;justify-content:center;color:#94A3B8;">No contribution data available.</div>`;
      }
      basedOnLabel = basedOnLabel || (contrib.mode === 'dept' ? 'Quality Journal Publications & Academic Records' : 'Academic Achievements & Publications');

      const top3 = contrib.top3 || [];
      const barSvg = renderGroupedBarChart(top3);

      const tableRows = (contrib.items || []).map(item => `
        <tr>
          <td title="${esc(item.name)}">${esc(item.code)}</td>
          <td>${esc(item.target)}</td>
          <td>${esc(item.achieved)}</td>
          <td>${esc(item.in_progress)}</td>
          <td style="font-weight:700;">${esc(item.total)}</td>
        </tr>
      `).join('');

      const donutSvg = renderDonutChart(contrib.donut_slices || [], contrib.total_count || 0, 'Total Records');

      const donutLegend = (contrib.donut_slices || []).map(sl => `
        <div class="ex-donut-leg-item" title="${esc(sl.label)}: ${esc(sl.count)} (${esc(sl.percentage)}%)">
          <span class="ex-donut-leg-box" style="background:${esc(sl.color)}"></span>
          <span style="overflow:hidden;text-overflow:ellipsis;">${esc(sl.label)} ${esc(sl.count)} (${esc(sl.percentage)}%)</span>
        </div>
      `).join('');

      return `
        <div class="ex-split-row">
          <!-- Left Panel: Top 3 -->
          <div class="ex-panel">
            <div class="ex-panel-hdr">
              <div>
                <div class="ex-panel-title">${esc(contrib.sub_title || 'Top 3 Departments')}</div>
                <div class="ex-panel-sub">(Based on ${esc(basedOnLabel)})</div>
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

          <!-- Right Panel: All Contributions -->
          <div class="ex-panel">
            <div class="ex-panel-hdr">
              <div class="ex-panel-title">${esc(contrib.title || 'All Departments Contribution')}</div>
            </div>
            <div class="ex-panel-body">
              <div class="ex-contrib-grid">
                <div class="ex-table-wrap">
                  <table class="ex-table">
                    <thead>
                      <tr>
                        <th>${(contrib.mode === 'department' || contrib.mode === 'dept') ? 'Dept.' : 'Category'}</th>
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
                        <td>${esc(contrib.total_target)}</td>
                        <td>${esc(contrib.total_achieved)}</td>
                        <td>${esc(contrib.total_in_prog)}</td>
                        <td>${esc(contrib.total_count)}</td>
                      </tr>
                    </tfoot>
                  </table>
                </div>

                <div class="ex-donut-side">
                  <div class="ex-donut-title">${esc(contrib.chart_label || 'Department-wise Contribution')}</div>
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

    function renderTargetProgressBar(target, achieved, unlinked) {
      if (unlinked) {
        return `
          <div class="ex-target-progress-wrap" style="display:flex; align-items:center; gap:8px;">
            <span class="pill" style="font-size:11px; color:#64748B; background:#F1F5F9; border:1px solid #CBD5E1;">Not linked</span>
          </div>`;
      }
      const tv = Number(target) || 0;
      const av = Number(achieved) || 0;

      if (tv <= 0) {
        if (av > 0) {
          return `
            <div class="ex-target-progress-wrap" style="display:flex; align-items:center; gap:8px;">
              <span class="pill pill-ok" style="font-size:11px; background:#ECFDF5; color:#059669; border:1px solid #A7F3D0; font-weight:700;">No target set (${av} achieved)</span>
            </div>`;
        }
        return `
          <div class="ex-target-progress-wrap" style="display:flex; align-items:center; gap:8px;">
            <span class="pill" style="font-size:11px; color:#64748B; background:#F1F5F9; border:1px solid #CBD5E1;">No target set</span>
          </div>`;
      }

      // Safe progress percentage: (achieved / target) * 100
      const rawPct = (av / tv) * 100;
      const pct = Math.round(rawPct * 10) / 10;
      // Visual bar capped at 100% so it never overflows or breaks layout
      const barWidth = Math.min(Math.max(rawPct, 0), 100);

      let colorClass = 'amber';
      let pillStyle = 'background:#FFFBEB; color:#B45309; border:1px solid #FDE68A;';
      let pillText = `${pct}%`;

      if (av > tv) {
        // Target exceeded: keep actual achieved value, cap bar at 100%, show exceeded indicator
        colorClass = 'green';
        pillStyle = 'background:#ECFDF5; color:#047857; border:1px solid #A7F3D0; font-weight:800;';
        pillText = `${pct}% &middot; Exceeded (+${av - tv})`;
      } else if (pct >= 100) {
        colorClass = 'green';
        pillStyle = 'background:#ECFDF5; color:#047857; border:1px solid #A7F3D0; font-weight:700;';
        pillText = `100% Achieved`;
      } else if (pct >= 50) {
        colorClass = 'blue';
        pillStyle = 'background:#EFF6FF; color:#1D4ED8; border:1px solid #BFDBFE; font-weight:700;';
        pillText = `${pct}%`;
      }

      return `
        <div class="ex-target-progress-wrap" style="display:flex; flex-direction:column; gap:4px; min-width:200px; width:100%;">
          <div style="display:flex; align-items:center; justify-content:space-between; gap:8px;">
            <span class="pill" style="${pillStyle} font-size:11px; padding:2px 8px; border-radius:6px; white-space:nowrap;">${pillText}</span>
            <span style="font-size:10.5px; color:#64748B; font-weight:600;">${av} / ${tv}</span>
          </div>
          <div class="ex-target-bar-bg" style="height:10px;">
            <div class="ex-target-bar-fill ${colorClass}" style="width:${barWidth}%;"></div>
          </div>
        </div>`;
    }

    function buildSlide(s) {
      const scope = `${esc(s.summary['Department'])} &middot; ${esc(s.summary['Academic Year'])}`;

      // 1: Title / Executive Meeting Overview Slide
      if (s.type === 'title') {
        const c = s.contributions;
        const tot = s.totals || {};
        const fixed = c ? c.total_target : (tot.targets || 0);
        const achieved = c ? c.total_achieved : 0;
        const inProg = c ? c.total_in_prog : 0;
        const total = tot.records !== undefined ? tot.records : (c ? c.total_count : 0);
        const achPct = fixed > 0 ? Math.round((achieved / fixed) * 100) : null;
        const inProgPct = fixed > 0 ? Math.round((inProg / fixed) * 100) : null;

        const em = s.em_status || {};
        const em1 = em.em1 || {};
        const em2 = em.em2 || {};
        const em1State = (em1.state || 'CLOSED').toUpperCase();
        const em2State = (em2.state || 'SCHEDULED').toUpperCase();

        const em1PillCls = em1State === 'ACTIVE' ? 'pill-ok' : (em1State === 'CLOSED' ? 'pill-warn' : 'pill');
        const em2PillCls = em2State === 'ACTIVE' ? 'pill-ok' : (em2State === 'SCHEDULED' ? 'pill' : 'pill-warn');

        const em1Dates = (em1.start_date && em1.end_date)
          ? `${esc(em1.start_date)} to ${esc(em1.end_date)}`
          : 'Schedule not configured';
        const em2Dates = (em2.start_date && em2.end_date)
          ? `${esc(em2.start_date)} to ${esc(em2.end_date)}`
          : 'Schedule not configured';

        return `
          <div class="ex-wrap">
            ${renderExecutiveBanner('Executive Meeting Report', `${esc(s.summary['Executive Meeting'])} &middot; ${scope}`, 'Better Research for a Brighter Future')}
            ${renderExecutiveKpis(fixed, achieved || (tot.faculty || 0), achPct, inProg || (tot.student || 0), inProgPct, total, '(Records in Scope)')}
            <div style="flex:1; display:flex; flex-direction:column; justify-content:center; align-items:center; background:#F8FAFC; border:1px solid #E2E8F0; border-radius:10px; padding:20px; text-align:center; min-height:0; overflow-y:auto;">
              <div class="intro-badge" style="margin-bottom:8px;"><?= icon('presentation', 13) ?> Executive Presentation Mode</div>
              <h2 style="font-size:26px; font-weight:800; color:#0B2D59;">Internal Quality Assurance Cell (IQAC)</h2>
              <p style="font-size:13.5px; color:#64748B; margin-top:4px; max-width:680px;">
                Comprehensive institutional performance review covering faculty publications, academic targets, student milestones, and department achievements for <strong>${esc(s.summary['Academic Year'])}</strong>.
              </p>

              <!-- Real EM1 & EM2 Schedule Cards -->
              <div class="ex-em-grid">
                <div class="ex-em-card" style="${em1State === 'ACTIVE' ? 'border:2px solid #10B981; background:#F0FDF4;' : ''}">
                  <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:8px;">
                    <span style="font-size:14px; font-weight:800; color:#0B2D59;">Executive Meeting 1 (EM1)</span>
                    <span class="pill ${em1PillCls}">${esc(em1State)}</span>
                  </div>
                  <div style="font-size:12px; color:#475569; font-weight:600; display:flex; align-items:center; gap:6px;">
                    ${renderIconSvg('calendar')} <span>${em1Dates}</span>
                  </div>
                  <div style="font-size:11px; color:#64748B; margin-top:6px;">
                    ${em.em1_locked ? 'EM1 period has concluded and forms are locked' : (em1State === 'ACTIVE' ? 'Active session &middot; forms available' : 'Scheduled period')}
                  </div>
                </div>

                <div class="ex-em-card" style="${em2State === 'ACTIVE' ? 'border:2px solid #10B981; background:#F0FDF4;' : ''}">
                  <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:8px;">
                    <span style="font-size:14px; font-weight:800; color:#0B2D59;">Executive Meeting 2 (EM2)</span>
                    <span class="pill ${em2PillCls}">${esc(em2State)}</span>
                  </div>
                  <div style="font-size:12px; color:#475569; font-weight:600; display:flex; align-items:center; gap:6px;">
                    ${renderIconSvg('calendar')} <span>${em2Dates}</span>
                  </div>
                  <div style="font-size:11px; color:#64748B; margin-top:6px;">
                    ${em2State === 'ACTIVE' ? 'Active review period &middot; EM2 in progress' : 'Scheduled automatic switchover'}
                  </div>
                </div>
              </div>

              <div style="margin-top:14px;" class="chips">${chipsOf(s.summary)}</div>
            </div>
            ${renderExecutiveFooter()}
          </div>`;
      }

      // 2: Overall College Development & Improvement (EM-SPEC-07)
      if (s.type === 'college_development') {
        const o = s.overview || {};
        const tot = s.totals || {};
        const r = s.rollup || {};

        const fixedTargets = tot.targets || 0;
        const achievedTargets = tot.achieved || 0;
        const targetPct = fixedTargets > 0 ? Math.round((achievedTargets / fixedTargets) * 100) : null;
        const totalRecords = tot.records || 0;
        const approvalRate = tot.approval_rate !== undefined ? tot.approval_rate : 0;

        const approved = tot.approved || 0;
        const pending = tot.pending || 0;
        const draft = tot.draft || 0;

        // Historical Year-over-Year Trajectory
        let yoyHtml = '';
        if (o.has_prev_year_data) {
          const diffSign = o.yoy_diff >= 0 ? '+' : '';
          const pctSign = o.yoy_pct >= 0 ? '+' : '';
          const isGrowth = o.yoy_diff >= 0;
          yoyHtml = `
            <div style="background:#FFFFFF; border:1px solid #CBD5E1; border-radius:10px; padding:16px 20px; flex:1; display:flex; flex-direction:column; justify-content:space-between;">
              <div style="display:flex; align-items:center; justify-content:space-between;">
                <div style="font-size:14px; font-weight:800; color:#0B2D59;">Historical Trajectory &amp; Year-over-Year Progress</div>
                <span class="pill ${isGrowth ? 'pill-ok' : 'pill-warn'}">${isGrowth ? 'Positive Trajectory' : 'Review Required'}</span>
              </div>
              <div style="display:grid; grid-template-columns: 1fr auto 1fr; align-items:center; gap:16px; margin:14px 0;">
                <div style="background:#F8FAFC; border:1px solid #E2E8F0; border-radius:8px; padding:12px; text-align:center;">
                  <div style="font-size:11px; font-weight:700; color:#64748B; text-transform:uppercase;">Academic Year ${esc(o.prev_year)}</div>
                  <div style="font-size:24px; font-weight:900; color:#1E293B; margin-top:4px;">${esc(o.prev_year_records)}</div>
                  <div style="font-size:10.5px; color:#64748B;">Verified Submissions</div>
                </div>
                <div style="color:#0B2D59; font-size:20px; font-weight:900; display:flex; flex-direction:column; align-items:center;">
                  <span>&rarr;</span>
                  <span style="font-size:11px; font-weight:800; color:${isGrowth ? '#168A53' : '#D97706'};">${diffSign}${esc(o.yoy_diff)}</span>
                </div>
                <div style="background:#F0FDF4; border:1px solid #BBF7D0; border-radius:8px; padding:12px; text-align:center;">
                  <div style="font-size:11px; font-weight:700; color:#166534; text-transform:uppercase;">Academic Year ${esc(o.academic_year)}</div>
                  <div style="font-size:24px; font-weight:900; color:#166534; margin-top:4px;">${esc(totalRecords)}</div>
                  <div style="font-size:10.5px; color:#166534; font-weight:700;">${pctSign}${esc(o.yoy_pct)}% Net Growth</div>
                </div>
              </div>
              <div style="font-size:11px; color:#64748B; line-height:1.4;">
                Institutional expansion reflects live database submissions across departments, active faculty publications, and student milestones verified through IQAC audit.
              </div>
            </div>`;
        } else {
          yoyHtml = `
            <div style="background:#FFFFFF; border:1px solid #CBD5E1; border-radius:10px; padding:16px 20px; flex:1; display:flex; flex-direction:column; justify-content:space-between;">
              <div style="display:flex; align-items:center; justify-content:space-between;">
                <div style="font-size:14px; font-weight:800; color:#0B2D59;">Institutional Baseline Tracking</div>
                <span class="pill pill-ok">Active Baseline</span>
              </div>
              <div style="background:#F8FAFC; border:1px solid #E2E8F0; border-radius:8px; padding:16px; margin:14px 0; text-align:center;">
                <div style="font-size:13px; font-weight:700; color:#0B2D59;">Academic Year ${esc(o.academic_year)} Baseline Tracking</div>
                <div style="font-size:28px; font-weight:900; color:#FF4F01; margin-top:4px;">${esc(totalRecords)} Records</div>
                <div style="font-size:11px; color:#64748B; margin-top:4px;">Prior-year comparison period ${esc(o.prev_year || 'historical')} has no archived electronic records. Current year forms institutional development baseline.</div>
              </div>
              <div style="font-size:11px; color:#64748B; line-height:1.4;">
                Data is dynamically sourced from live active records and targets configured in the ATTS IQAC database.
              </div>
            </div>`;
        }

        const devCards = [
          { label: 'Total Verified Records', val: totalRecords, sub: `${esc(tot.faculty || 0)} Faculty &middot; ${esc(tot.student || 0)} Student`, ic: 'check-circle', color: '#0B2D59', bg: '#E2E8F0' },
          { label: 'Faculty Achievements', val: tot.faculty || 0, sub: 'Journals, Patents & Events', ic: 'book-open', color: '#0066CC', bg: '#EBF5FF' },
          { label: 'Student Achievements', val: tot.student || 0, sub: 'NPTEL, Placements, Internships', ic: 'users', color: '#059669', bg: '#ECFDF5' },
          { label: 'Academic Targets Configured', val: fixedTargets, sub: `${esc(achievedTargets)} Targets Realized`, ic: 'shield', color: '#D97706', bg: '#FEF3C7' },
          { label: 'Record Approval Rate', val: `${esc(approvalRate)}%`, sub: `${esc(approved)} Approved &middot; ${esc(pending)} Pending`, ic: 'award', color: '#7E22CE', bg: '#F3E8FF' },
          { label: 'Participating Departments', val: tot.departments || 0, sub: `Active out of ${esc(tot.all_depts || tot.departments || 0)} total`, ic: 'graduation', color: '#0D9488', bg: '#CCFBF1' }
        ];

        const cardsHtml = devCards.map(c => `
          <div class="ex-cat-card" style="padding:10px 14px;">
            <div class="ex-cat-ic" style="background:${c.bg}; color:${c.color}; width:38px; height:38px;">
              ${renderIconSvg(c.ic)}
            </div>
            <div style="flex:1; min-width:0;">
              <div class="ex-cat-val" style="font-size:22px;">${esc(c.val)}</div>
              <div class="ex-cat-lbl" style="font-size:11px;">${esc(c.label)}</div>
              <div style="font-size:9.5px; color:#64748B; margin-top:2px;">${c.sub}</div>
            </div>
          </div>
        `).join('');

        return `
          <div class="ex-wrap">
            ${renderExecutiveBanner(s.title || 'Overall College Development & Improvement', `${scope} &middot; Institutional Development & Quality Growth`, 'Better Research for a Brighter Future')}
            ${renderExecutiveKpis(fixedTargets, achievedTargets, targetPct, tot.pending || 0, null, totalRecords, '(Total Verified Records)')}
            <div style="flex:1; display:flex; flex-direction:column; gap:12px; min-height:0; overflow-y:auto;">
              <!-- 6 Core Metric Cards -->
              <div style="display:grid; grid-template-columns:repeat(3, 1fr); gap:10px;">
                ${cardsHtml}
              </div>

              <!-- Split Row: Approval Pipeline + Historical Trajectory -->
              <div style="display:flex; gap:12px; flex:1; min-height:0;">
                <!-- Left: Verification Pipeline Status -->
                <div style="background:#FFFFFF; border:1px solid #CBD5E1; border-radius:10px; padding:16px 20px; flex:1; display:flex; flex-direction:column; justify-content:space-between;">
                  <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:10px;">
                    <div style="font-size:14px; font-weight:800; color:#0B2D59;">Record Quality &amp; Approval Pipeline</div>
                    <span class="pill pill-ok">${esc(approvalRate)}% Verified</span>
                  </div>

                  <div style="display:flex; flex-direction:column; gap:10px;">
                    <div>
                      <div style="display:flex; justify-content:space-between; font-size:11.5px; font-weight:700; color:#334155; margin-bottom:3px;">
                        <span>Approved by Dean / HoD</span>
                        <span>${esc(approved)} / ${esc(totalRecords)} (${totalRecords > 0 ? Math.round((approved / totalRecords) * 100) : 0}%)</span>
                      </div>
                      <div class="bar-track" style="height:10px;"><div class="bar-fill" style="width:${totalRecords > 0 ? Math.round((approved / totalRecords) * 100) : 0}%; background:#10B981;"></div></div>
                    </div>

                    <div>
                      <div style="display:flex; justify-content:space-between; font-size:11.5px; font-weight:700; color:#334155; margin-bottom:3px;">
                        <span>Pending Review (HoD / Dean)</span>
                        <span>${esc(pending)} / ${esc(totalRecords)} (${totalRecords > 0 ? Math.round((pending / totalRecords) * 100) : 0}%)</span>
                      </div>
                      <div class="bar-track" style="height:10px;"><div class="bar-fill" style="width:${totalRecords > 0 ? Math.round((pending / totalRecords) * 100) : 0}%; background:#F59E0B;"></div></div>
                    </div>

                    <div>
                      <div style="display:flex; justify-content:space-between; font-size:11.5px; font-weight:700; color:#334155; margin-bottom:3px;">
                        <span>Draft / Submissions in Progress</span>
                        <span>${esc(draft)} / ${esc(totalRecords)} (${totalRecords > 0 ? Math.round((draft / totalRecords) * 100) : 0}%)</span>
                      </div>
                      <div class="bar-track" style="height:10px;"><div class="bar-fill" style="width:${totalRecords > 0 ? Math.round((draft / totalRecords) * 100) : 0}%; background:#94A3B8;"></div></div>
                    </div>
                  </div>

                  <div style="font-size:11px; color:#64748B; margin-top:8px;">
                    Multi-level verification workflow ensures zero unverified data enters institutional NIRF/NAAC reporting datasets.
                  </div>
                </div>

                <!-- Right: YoY Trajectory Card -->
                ${yoyHtml}
              </div>
            </div>
            ${renderExecutiveFooter('Quality Publications Build Knowledge | Knowledge Builds a Stronger Tomorrow')}
          </div>`;
      }

      // 3: Overall Institutional Performance / Key Metrics (EM-SPEC-07)
      if (s.type === 'institutional_performance') {
        const o = s.overview || {};
        const tot = s.totals || {};
        const fm = s.faculty_metrics || {};
        const sm = s.student_metrics || {};
        const c = s.contributions || {};

        const totalRecords = tot.records || 0;
        const facCount = tot.faculty || 0;
        const studCount = tot.student || 0;
        const actCount = tot.activity || 0;

        const facPct = totalRecords > 0 ? Math.round((facCount / totalRecords) * 100) : 0;
        const studPct = totalRecords > 0 ? Math.round((studCount / totalRecords) * 100) : 0;
        const actPct = totalRecords > 0 ? Math.round((actCount / totalRecords) * 100) : 0;

        const donutSlices = [
          { label: 'Faculty Achievements', count: facCount, percentage: facPct, color: '#0066CC' },
          { label: 'Student Milestones', count: studCount, percentage: studPct, color: '#10B981' },
          { label: 'Outreach & Activities', count: actCount, percentage: actPct, color: '#F59E0B' }
        ];

        const donutSvg = renderDonutChart(donutSlices, totalRecords, 'Total Records');

        const kpis = [
          {
            title: 'Research & Publications',
            val: (fm.journals || 0) + (fm.conferences || 0) + (fm.books || 0) + (fm.patents || 0),
            desc: `${esc(fm.journals || 0)} Journals &middot; ${esc(fm.conferences || 0)} Conf &middot; ${esc(fm.patents || 0)} Patents`,
            ic: 'book-open',
            color: '#0066CC',
            bg: '#EBF5FF'
          },
          {
            title: 'Student Placement & Training',
            val: (sm.placements || 0) + (sm.internships || 0) + (sm.nptel || 0) + (sm.training || 0),
            desc: `${esc(sm.placements || 0)} Placements &middot; ${esc(sm.internships || 0)} Internships &middot; ${esc(sm.nptel || 0)} NPTEL`,
            ic: 'briefcase',
            color: '#059669',
            bg: '#ECFDF5'
          },
          {
            title: 'Faculty Training & Events',
            val: (fm.training || 0) + (fm.events || 0),
            desc: `${esc(fm.training || 0)} FDP / Workshops &middot; ${esc(fm.events || 0)} Events Organized`,
            ic: 'calendar',
            color: '#7E22CE',
            bg: '#F3E8FF'
          },
          {
            title: 'Target Realization Index',
            val: o.target_realization !== null && o.target_realization !== undefined ? `${esc(o.target_realization)}%` : 'Active',
            desc: `${esc(o.targets_achieved || 0)} of ${esc(o.total_targets || 0)} institutional targets achieved`,
            ic: 'shield',
            color: '#D97706',
            bg: '#FEF3C7'
          }
        ];

        const kpiHtml = kpis.map(k => `
          <div style="background:#FFFFFF; border:1px solid #CBD5E1; border-radius:10px; padding:12px 16px; display:flex; align-items:center; gap:14px; box-shadow:0 2px 6px rgba(0,0,0,0.03);">
            <div style="width:42px; height:42px; border-radius:10px; background:${k.bg}; color:${k.color}; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
              ${renderIconSvg(k.ic)}
            </div>
            <div style="flex:1; min-width:0;">
              <div style="font-size:11.5px; font-weight:700; color:#475569;">${esc(k.title)}</div>
              <div style="font-size:22px; font-weight:900; color:#0B2D59; line-height:1.2;">${esc(k.val)}</div>
              <div style="font-size:10px; color:#64748B; margin-top:2px;">${k.desc}</div>
            </div>
          </div>
        `).join('');

        return `
          <div class="ex-wrap">
            ${renderExecutiveBanner(s.title || 'Overall Institutional Performance', `${scope} &middot; Institutional Performance Metrics`, 'Better Research for a Brighter Future')}
            ${renderExecutiveKpis(o.total_targets || 0, o.targets_achieved || 0, o.target_realization, o.pending_records || 0, null, totalRecords, '(Verified Database Records)')}
            <div style="flex:1; display:flex; gap:16px; min-height:0; overflow-y:auto;">
              <!-- Left Panel: Domain Distribution Donut -->
              <div class="ex-panel" style="flex:1; display:flex; flex-direction:column; min-height:0;">
                <div class="ex-panel-hdr">
                  <div class="ex-panel-title">Core Domain Contribution Distribution</div>
                  <div class="ex-panel-sub">Share of verified achievements across institutional categories</div>
                </div>
                <div class="ex-panel-body" style="display:flex; flex-direction:column; align-items:center; justify-content:space-between; padding:16px;">
                  <div style="width:100%; height:180px; display:flex; align-items:center; justify-content:center;">
                    ${donutSvg}
                  </div>
                  <div style="display:grid; grid-template-columns:repeat(3, 1fr); gap:8px; width:100%; margin-top:8px;">
                    <div style="background:#F0F9FF; border:1px solid #BAE6FD; border-radius:8px; padding:8px; text-align:center;">
                      <div style="font-size:10px; font-weight:700; color:#0369A1;">Faculty</div>
                      <div style="font-size:16px; font-weight:900; color:#0369A1;">${esc(facCount)}</div>
                      <div style="font-size:9.5px; color:#0284C7;">${esc(facPct)}%</div>
                    </div>
                    <div style="background:#F0FDF4; border:1px solid #BBF7D0; border-radius:8px; padding:8px; text-align:center;">
                      <div style="font-size:10px; font-weight:700; color:#15803D;">Student</div>
                      <div style="font-size:16px; font-weight:900; color:#15803D;">${esc(studCount)}</div>
                      <div style="font-size:9.5px; color:#16A34A;">${esc(studPct)}%</div>
                    </div>
                    <div style="background:#FFFBEB; border:1px solid #FDE68A; border-radius:8px; padding:8px; text-align:center;">
                      <div style="font-size:10px; font-weight:700; color:#B45309;">Outreach</div>
                      <div style="font-size:16px; font-weight:900; color:#B45309;">${esc(actCount)}</div>
                      <div style="font-size:9.5px; color:#D97706;">${esc(actPct)}%</div>
                    </div>
                  </div>
                </div>
              </div>

              <!-- Right Panel: Key Performance Indicators -->
              <div class="ex-panel" style="flex:1.2; display:flex; flex-direction:column; min-height:0;">
                <div class="ex-panel-hdr">
                  <div class="ex-panel-title">Institutional Key Performance Indicators (KPIs)</div>
                  <div class="ex-panel-sub">Academic productivity, student engagement &amp; institutional capacity</div>
                </div>
                <div class="ex-panel-body" style="padding:14px; display:grid; grid-template-columns:1fr 1fr; gap:12px; align-content:start;">
                  ${kpiHtml}
                </div>
              </div>
            </div>
            ${renderExecutiveFooter('Quality Publications Build Knowledge | Knowledge Builds a Stronger Tomorrow')}
          </div>`;
      }

      // 4: Faculty Achievements Summary (Dynamic matching /faculty-achievements.php)
      if (s.type === 'faculty_summary') {
        const m = s.metrics || {};
        const tot = s.totals || {};
        const catMap = [
          { label: 'Journal Publications', key: 'journals', count: m.journals || 0, icon: 'book-open', color: '#0066CC', bg: '#EBF5FF' },
          { label: 'Conference Publications', key: 'conferences', count: m.conferences || 0, icon: 'users', color: '#059669', bg: '#ECFDF5' },
          { label: 'Books / Book Chapters', key: 'books', count: m.books || 0, icon: 'file-text', color: '#FF4F01', bg: '#FFF3EC' },
          { label: 'Events Organized', key: 'events', count: m.events || 0, icon: 'calendar', color: '#DC2626', bg: '#FEF2F2' },
          { label: 'Training Programmes & FDP', key: 'training', count: m.training || 0, icon: 'briefcase', color: '#7E22CE', bg: '#F3E8FF' },
          { label: 'Patents & Copyrights', key: 'patents', count: m.patents || 0, icon: 'shield', color: '#0D9488', bg: '#CCFBF1' },
          { label: 'Other Achievements / MoUs', key: 'other', count: m.other || 0, icon: 'award', color: '#475569', bg: '#F1F5F9' },
          { label: 'Total Faculty Achievements', key: 'total', count: m.total || 0, icon: 'check-circle', color: '#0B2D59', bg: '#E2E8F0', isTotal: true }
        ];

        const cardsHtml = catMap.map(c => `
          <div class="ex-cat-card" style="${c.isTotal ? 'background:#F8FAFC; border:2px solid #0B2D59;' : ''}">
            <div class="ex-cat-ic" style="background:${c.bg}; color:${c.color};">
              ${renderIconSvg(c.icon)}
            </div>
            <div style="flex:1; min-width:0;">
              <div class="ex-cat-val" style="${c.isTotal ? 'color:#FF4F01;' : ''}">${esc(c.count)}</div>
              <div class="ex-cat-lbl" title="${esc(c.label)}">${esc(c.label)}</div>
            </div>
          </div>
        `).join('');

        return `
          <div class="ex-wrap">
            ${renderExecutiveBanner('Faculty Achievements Performance', `${scope} &middot; Verified Academic Database`, 'Better Research for a Brighter Future')}
            ${renderExecutiveKpis(tot.total_faculty || 0, tot.total || 0, tot.approval_rate !== null ? tot.approval_rate : null, tot.approved || 0, null, tot.pending || 0, '(Pending Review)')}
            <div class="ex-panel" style="flex:1; min-height:0; display:flex; flex-direction:column;">
              <div class="ex-panel-hdr">
                <div class="ex-panel-title">Faculty Performance by Achievement Metric</div>
                <div class="ex-panel-sub">Live database aggregation matching Faculty Achievements Performance Matrix</div>
              </div>
              <div style="flex:1; min-height:0; overflow-y:auto; padding:12px 16px;">
                <div class="ex-cat-grid">
                  ${cardsHtml}
                </div>
              </div>
            </div>
            ${renderExecutiveFooter('Quality Publications Build Knowledge | Knowledge Builds a Stronger Tomorrow')}
          </div>`;
      }

      // 3: Department Milestones & Performance
      if (s.type === 'department_milestones' || s.type === 'college') {
        const c = s.contributions;
        const fixed = c ? c.total_target : (s.rollup ? s.rollup.target : 0);
        const achieved = c ? c.total_achieved : (s.rollup ? s.rollup.achieved : 0);
        const inProg = c ? c.total_in_prog : 0;
        const total = c ? c.total_count : (s.totals ? s.totals.records : (achieved + inProg));
        const achPct = fixed > 0 ? Math.round((achieved / fixed) * 100) : (s.rollup ? s.rollup.percentage : 0);
        const inProgPct = fixed > 0 ? Math.round((inProg / fixed) * 100) : 0;

        return `
          <div class="ex-wrap">
            ${renderExecutiveBanner(s.title || 'Department Milestones & Contributions', `${scope} &middot; Institutional Milestones`, 'Better Research for a Brighter Future')}
            ${renderExecutiveKpis(fixed, achieved, achPct, inProg, inProgPct, total, '(Achieved + In Progress)')}
            ${renderExecutiveSplitRow(c, 'Department Academic Milestones & Records')}
            ${renderExecutiveFooter('Quality Publications Build Knowledge | Knowledge Builds a Stronger Tomorrow')}
          </div>`;
      }

      // 4: Target vs Achieved Summary
      if (s.type === 'target_summary') {
        const c = s.contributions;
        const r = s.rollup || {};
        const fixed = r.target !== undefined ? r.target : (c ? c.total_target : (s.academic_targets || 0));
        const achieved = r.achieved !== undefined ? r.achieved : (c ? c.total_achieved : (s.targets_achieved || 0));
        const inProg = r.remaining !== undefined ? r.remaining : (c ? c.total_in_prog : (s.targets_in_progress || 0));
        const total = r.count !== undefined ? r.count : (c ? c.total_count : (achieved + inProg));
        const achPct = fixed > 0 ? Math.round((achieved / fixed) * 100) : 0;
        const inProgPct = fixed > 0 ? Math.round((inProg / fixed) * 100) : 0;

        let tableHtml = '';
        if (s.target_rows && s.target_rows.length > 0) {
          const rows = s.target_rows.map(t => `
            <tr>
              <td>
                <div class="ex-target-metric-name">${esc(t.metric)}</div>
                <div class="ex-target-metric-dept">${esc(t.department || scope)}</div>
              </td>
              <td class="num" style="text-align:center; font-size:14px; font-weight:700; color:#334155;">${esc(t.target)}</td>
              <td class="num" style="text-align:center; font-size:15px; font-weight:800; color:#0F172A;">${t.unlinked ? '&mdash;' : esc(t.achieved !== null && t.achieved !== undefined ? t.achieved : 0)}</td>
              <td>${renderTargetProgressBar(t.target, t.achieved, t.unlinked)}</td>
            </tr>`).join('');
          tableHtml = `
            <div class="ex-panel" style="flex:1; min-height:0; display:flex; flex-direction:column;">
              <div class="ex-panel-hdr">
                <div class="ex-panel-title">Institutional Targets vs Achievements</div>
                <div class="ex-panel-sub">Verified academic achievement counts vs approved targets &middot; Visual Progress Tracking</div>
              </div>
              <div style="flex:1; min-height:0; overflow-y:auto;">
                <table class="ex-target-table">
                  <thead>
                    <tr>
                      <th style="width:34%;">Target / Metric Category</th>
                      <th style="text-align:center; width:14%;">Target</th>
                      <th style="text-align:center; width:14%;">Achieved</th>
                      <th style="width:38%;">Progress toward Target</th>
                    </tr>
                  </thead>
                  <tbody>${rows}</tbody>
                </table>
              </div>
            </div>`;
        } else if (c && c.items && c.items.length > 0) {
          const rows = c.items.map(item => `
            <tr>
              <td>
                <div class="ex-target-metric-name">${esc(item.name)}</div>
                <div class="ex-target-metric-dept">${esc(item.code || scope)}</div>
              </td>
              <td class="num" style="text-align:center; font-size:14px; font-weight:700; color:#334155;">${item.target > 0 ? esc(item.target) : '<span style="color:#94A3B8;">0</span>'}</td>
              <td class="num" style="text-align:center; font-size:15px; font-weight:800; color:#0F172A;">${esc(item.achieved)}</td>
              <td>${renderTargetProgressBar(item.target, item.achieved, false)}</td>
            </tr>`).join('');
          tableHtml = `
            <div class="ex-panel" style="flex:1; min-height:0; display:flex; flex-direction:column;">
              <div class="ex-panel-hdr">
                <div class="ex-panel-title">Academic Targets vs Achievements</div>
                <div class="ex-panel-sub">Category-wise target vs verified achievement comparison</div>
              </div>
              <div style="flex:1; min-height:0; overflow-y:auto;">
                <table class="ex-target-table">
                  <thead>
                    <tr>
                      <th style="width:34%;">Category / Metric</th>
                      <th style="text-align:center; width:14%;">Target</th>
                      <th style="text-align:center; width:14%;">Achieved</th>
                      <th style="width:38%;">Progress toward Target</th>
                    </tr>
                  </thead>
                  <tbody>${rows}</tbody>
                </table>
              </div>
            </div>`;
        } else {
          tableHtml = `
            <div class="empty-wrap" style="background:#FFFFFF; border:1px solid #E2E8F0; border-radius:12px; padding:30px;">
              <div class="empty-ic">${renderIconSvg('shield')}</div>
              <div style="font-size:16px; font-weight:700; color:var(--ink);">No targets configured for this academic year</div>
              <div style="font-size:13px; color:#64748B;">Department targets can be configured in the Targets module.</div>
            </div>`;
        }

        return `
          <div class="ex-wrap">
            ${renderExecutiveBanner('Target vs Achieved', `${scope} &middot; Institutional Targets`, 'Better Research for a Brighter Future')}
            ${renderExecutiveKpis(fixed, achieved, achPct, inProg, inProgPct, total, '(Configured Targets)')}
            ${tableHtml}
            ${renderExecutiveFooter('Quality Publications Build Knowledge | Knowledge Builds a Stronger Tomorrow')}
          </div>`;
      }

      // 5: Student Achievements Performance Matrix Slide
      if (s.type === 'student_summary') {
        const m = s.metrics || {};
        const tot = s.totals || {};
        const catMap = [
          { label: 'SWAYAM-NPTEL Courses', count: m.nptel || 0, icon: 'award', color: '#0066CC', bg: '#EBF5FF' },
          { label: 'Industrial Internships', count: m.internships || 0, icon: 'briefcase', color: '#059669', bg: '#ECFDF5' },
          { label: 'Campus Placements', count: m.placements || 0, icon: 'users', color: '#FF4F01', bg: '#FFF3EC' },
          { label: 'Online Courses', count: m.online_courses || 0, icon: 'graduation', color: '#7E22CE', bg: '#F3E8FF' },
          { label: 'Student Achievements', count: m.achievements || 0, icon: 'shield', color: '#DC2626', bg: '#FEF2F2' },
          { label: 'Student Participations', count: m.participations || 0, icon: 'calendar', color: '#0D9488', bg: '#CCFBF1' },
          { label: 'Summer / Winter Training', count: m.training || 0, icon: 'file-text', color: '#D97706', bg: '#FEF3C7' },
          { label: 'Total Student Records', count: m.total || 0, icon: 'check-circle', color: '#0B2D59', bg: '#E2E8F0', isTotal: true }
        ];

        const cardsHtml = catMap.map(c => `
          <div class="ex-cat-card" style="${c.isTotal ? 'background:#F8FAFC; border:2px solid #0B2D59;' : ''}">
            <div class="ex-cat-ic" style="background:${c.bg}; color:${c.color};">
              ${renderIconSvg(c.icon)}
            </div>
            <div style="flex:1; min-width:0;">
              <div class="ex-cat-val" style="${c.isTotal ? 'color:#FF4F01;' : ''}">${esc(c.count)}</div>
              <div class="ex-cat-lbl" title="${esc(c.label)}">${esc(c.label)}</div>
            </div>
          </div>
        `).join('');

        return `
          <div class="ex-wrap">
            ${renderExecutiveBanner('Student Achievements Performance Matrix', `${scope} &middot; Verified Academic Database`, 'Better Research for a Brighter Future')}
            ${renderExecutiveKpis(tot.total_students || 0, tot.total || 0, null, m.internships || 0, null, m.placements || 0, '(Campus Placements)')}
            <div class="ex-panel" style="flex:1; min-height:0; display:flex; flex-direction:column;">
              <div class="ex-panel-hdr">
                <div class="ex-panel-title">Student Achievement Distribution</div>
                <div class="ex-panel-sub">Live student achievements across active categories</div>
              </div>
              <div style="flex:1; min-height:0; overflow-y:auto; padding:12px 16px;">
                <div class="ex-cat-grid">
                  ${cardsHtml}
                </div>
              </div>
            </div>
            ${renderExecutiveFooter('Quality Publications Build Knowledge | Knowledge Builds a Stronger Tomorrow')}
          </div>`;
      }

      // 4: Records Slides
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

        return `
          <div class="ex-wrap">
            ${renderExecutiveBanner(s.title, `${scope} &middot; ${pageInfo}`)}
            <div class="ex-panel" style="flex:1; min-height:0; display:flex; flex-direction:column;">
              <div class="ex-panel-hdr">
                <div class="ex-panel-title">${esc(s.title)} &mdash; Verified Records</div>
                <div class="ex-panel-sub">${pageInfo}</div>
              </div>
              <div style="flex:1; min-height:0; overflow-y:auto; padding:0;">
                <table class="sl">
                  <thead>
                    <tr style="background:#0B2D59; color:#FFFFFF;">
                      <th style="background:#0B2D59; color:#FFFFFF;">#</th>
                      <th style="background:#0B2D59; color:#FFFFFF;">${isStudent ? 'Achievement / Record' : 'Achievement'}</th>
                      <th style="background:#0B2D59; color:#FFFFFF;">Category</th>
                      <th style="background:#0B2D59; color:#FFFFFF;">Department</th>
                      <th style="background:#0B2D59; color:#FFFFFF;">Date</th>
                      <th style="background:#0B2D59; color:#FFFFFF;">Status</th>
                    </tr>
                  </thead>
                  <tbody>${rows}</tbody>
                </table>
              </div>
            </div>
            ${renderExecutiveFooter()}
          </div>`;
      }

      // 5: Targets Breakdown Slides
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

        return `
          <div class="ex-wrap">
            ${renderExecutiveBanner('Academic Targets vs Achievements', `${scope}${pageInfo ? ' &middot; ' + pageInfo : ''}`)}
            ${renderExecutiveKpis(r.target, r.achieved, r.percentage, r.remaining, null, r.count, '(Configured Targets)')}
            <div class="ex-panel" style="flex:1; min-height:0; display:flex; flex-direction:column;">
              <div class="ex-panel-hdr">
                <div class="ex-panel-title">Institutional Targets Breakdown</div>
                <div class="ex-panel-sub">${pageInfo}</div>
              </div>
              <div style="flex:1; min-height:0; overflow-y:auto;">
                <table class="sl">
                  <thead>
                    <tr style="background:#0B2D59; color:#FFFFFF;">
                      <th style="background:#0B2D59; color:#FFFFFF;">Target / Metric</th>
                      <th style="background:#0B2D59; color:#FFFFFF;" class="num">Target</th>
                      <th style="background:#0B2D59; color:#FFFFFF;" class="num">Achieved</th>
                      <th style="background:#0B2D59; color:#FFFFFF;" class="num">Difference</th>
                      <th style="background:#0B2D59; color:#FFFFFF;" class="num">%</th>
                    </tr>
                  </thead>
                  <tbody>${rows}</tbody>
                </table>
              </div>
            </div>
            ${renderExecutiveFooter()}
          </div>`;
      }

      // 6: Meetings Slide
      if (s.type === 'meetings') {
        const rows = s.rows.map(m => `
          <tr>
            <td class="t-title">Meeting #${esc(m.number)}</td>
            <td class="t-sub">${esc(m.date)}</td>
            <td>${m.status ? `<span class="pill pill-ok">${esc(m.status)}</span>` : ''}</td>
            <td class="t-sub">${esc(m.admin)}</td>
            <td class="t-sub">${esc(m.notes)}</td>
          </tr>`).join('');

        return `
          <div class="ex-wrap">
            ${renderExecutiveBanner('Executive Meeting Schedule & Minutes', scope)}
            <div class="ex-panel" style="flex:1; min-height:0; display:flex; flex-direction:column;">
              <div class="ex-panel-hdr">
                <div class="ex-panel-title">Executive Reviews</div>
              </div>
              <div style="flex:1; min-height:0; overflow-y:auto;">
                <table class="sl">
                  <thead>
                    <tr style="background:#0B2D59; color:#FFFFFF;">
                      <th style="background:#0B2D59; color:#FFFFFF;">Meeting</th>
                      <th style="background:#0B2D59; color:#FFFFFF;">Date</th>
                      <th style="background:#0B2D59; color:#FFFFFF;">Status</th>
                      <th style="background:#0B2D59; color:#FFFFFF;">Recorded By</th>
                      <th style="background:#0B2D59; color:#FFFFFF;">Minutes / Remarks</th>
                    </tr>
                  </thead>
                  <tbody>${rows}</tbody>
                </table>
              </div>
            </div>
            ${renderExecutiveFooter()}
          </div>`;
      }

      // 7: Closing Summary Slide
      if (s.type === 'closing') {
        const r = s.rollup;
        return `
          <div class="ex-wrap">
            ${renderExecutiveBanner('Executive Performance Summary', scope)}
            ${renderExecutiveKpis(r.target, r.achieved, r.percentage, r.remaining, null, s.totals.records, '(Total Records)')}
            <div style="flex:1; display:flex; flex-direction:column; justify-content:center; align-items:center; background:#F8FAFC; border:1px solid #E2E8F0; border-radius:10px; padding:24px; text-align:center;">
              <h2 style="font-size:32px; font-weight:900; color:#0B2D59;">Summary of Performance & Targets</h2>
              <div style="font-size:20px; font-weight:700; color:#1E293B; margin-top:8px;">
                Achieved <span style="color:#168A53; font-size:26px; font-weight:900;">${esc(r.achieved)}</span> of ${esc(r.target)} across ${esc(r.count)} academic target${r.count === 1 ? '' : 's'}
                ${r.percentage === null ? '' : ` &middot; <span style="color:#FF4F01;">${esc(r.percentage)}% Attained</span>`}
              </div>
              <div style="width:70%; margin-top:20px;">
                <div class="bar-track" style="height:14px;"><div class="bar-fill" style="width:${(r.percentage === null ? 0 : Math.min(r.percentage, 100))}%; background:linear-gradient(90deg, #1B65C5, #168A53);"></div></div>
              </div>
              <div style="margin-top:24px;" class="chips">${chipsOf(s.summary)}</div>
            </div>
            ${renderExecutiveFooter()}
          </div>`;
      }

      // Empty state
      return `
        <div class="ex-wrap">
          ${renderExecutiveBanner('Executive Meeting Presentation', scope)}
          <div class="empty-wrap" style="background:#FFFFFF; border:1px solid #E2E8F0; border-radius:12px; padding:40px;">
            <div class="empty-ic"><?= icon('info', 22) ?></div>
            <div style="font-size:18px; font-weight:700; color:var(--ink);">${esc(s.message || 'No records in this scope.')}</div>
            <div style="font-size:13px; color:#64748B;">Adjust the report filters to widen this section.</div>
          </div>
          ${renderExecutiveFooter()}
        </div>`;
    }

    // EM-SPEC-09: attach keydown listener, render first slide, and start auto-play
    attachKeydownListener();
    renderSlide(0, false);
    wake();
  </script>
</body>
</html>
