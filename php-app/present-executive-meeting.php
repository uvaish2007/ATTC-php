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

$dataset = em_dataset($user, $filters);
$slides  = em_slides($dataset);

$slidesJson = json_encode($slides, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
$exitUrl    = url('executive-meeting-report.php') . '?' . http_build_query(em_filter_query($filters));
// EM-SPEC-05: shown inside the report page's full-screen modal. Leaving then
// closes that modal instead of navigating this frame back to the report.
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
        <button type="button" class="mode-btn" id="btnAuto" onclick="setMode('auto')" title="Advance automatically every 5 seconds">
          <?= icon('play-circle', 14) ?> Auto Mode
        </button>
        <button type="button" class="mode-btn active" id="btnManual" onclick="setMode('manual')" title="Advance only when you choose">
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

    <div class="slide-counter" id="slideCounter">Slide <b>1</b> of 1</div>
  </footer>

  <script>
    const slides = <?= $slidesJson ?>;
    const EMBEDDED = <?= $embed ? 'true' : 'false' ?>;

    /* Auto mode dwell: 5 seconds per slide (EM-SPEC-09). */
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
      if (EMBEDDED) {
        // Ask the report page to close the modal this is running in.
        window.parent.postMessage({ atts: 'em-present-close' }, window.location.origin);
        return;
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

    /* ---- Executive Chart & SVG Helpers ---- */

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

    function buildSlide(s) {
      const scope = `${esc(s.summary['Department'])} &middot; ${esc(s.summary['Academic Year'])}`;

      // 1: Target Summary (First Slide)
      if (s.type === 'target_summary') {
        const c = s.contributions;
        const fixed = c ? c.total_target : (s.academic_targets || 0);
        const achieved = c ? c.total_achieved : (s.targets_achieved || 0);
        const inProg = c ? c.total_in_prog : (s.targets_in_progress || 0);
        const total = c ? c.total_count : (achieved + inProg);
        const achPct = fixed > 0 ? Math.round((achieved / fixed) * 100) : 0;
        const inProgPct = fixed > 0 ? Math.round((inProg / fixed) * 100) : 0;

        return `
          <div class="ex-wrap">
            ${renderExecutiveBanner('Summary of Target Achievements', 'Research • Innovation • Global Impact')}
            ${renderExecutiveKpis(fixed, achieved, achPct, inProg, inProgPct, total, '(Achieved + In Progress)')}
            ${renderExecutiveSplitRow(c, 'Academic Target Achievements')}
            ${renderExecutiveFooter()}
          </div>`;
      }

      // 2: Title / Cover Slide
      if (s.type === 'title') {
        const c = s.contributions;
        const fixed = c ? c.total_target : (s.totals ? s.totals.targets : 0);
        const achieved = c ? c.total_achieved : 0;
        const inProg = c ? c.total_in_prog : 0;
        const total = s.totals ? s.totals.records : (c ? c.total_count : 0);
        const achPct = fixed > 0 ? Math.round((achieved / fixed) * 100) : null;
        const inProgPct = fixed > 0 ? Math.round((inProg / fixed) * 100) : null;

        return `
          <div class="ex-wrap">
            ${renderExecutiveBanner(s.title, `${esc(s.summary['Executive Meeting'])} &middot; ${scope}`)}
            ${renderExecutiveKpis(fixed, achieved || (s.totals ? s.totals.faculty : 0), achPct, inProg || (s.totals ? s.totals.student : 0), inProgPct, total, '(Records in Scope)')}
            <div style="flex:1; display:flex; flex-direction:column; justify-content:center; align-items:center; background:#F8FAFC; border:1px solid #E2E8F0; border-radius:10px; padding:24px; text-align:center;">
              <div class="intro-badge" style="margin-bottom:12px;"><?= icon('presentation', 13) ?> Executive Presentation</div>
              <h2 style="font-size:28px; font-weight:800; color:#0B2D59;">Internal Quality Assurance Cell (IQAC)</h2>
              <p style="font-size:14px; color:#64748B; margin-top:6px; max-width:640px;">
                Comprehensive institutional performance review covering faculty publications, academic targets, student milestones, and department achievements for <strong>${esc(s.summary['Academic Year'])}</strong>.
              </p>
              <div style="margin-top:18px;" class="chips">${chipsOf(s.summary)}</div>
            </div>
            ${renderExecutiveFooter()}
          </div>`;
      }

      // 3: College / Department Performance (The Core Executive Dashboard View)
      if (s.type === 'college') {
        const c = s.contributions;
        const fixed = c ? c.total_target : (s.rollup ? s.rollup.target : 0);
        const achieved = c ? c.total_achieved : (s.rollup ? s.rollup.achieved : 0);
        const inProg = c ? c.total_in_prog : 0;
        const total = c ? c.total_count : (s.totals ? s.totals.records : (achieved + inProg));
        const achPct = fixed > 0 ? Math.round((achieved / fixed) * 100) : (s.rollup ? s.rollup.percentage : 0);
        const inProgPct = fixed > 0 ? Math.round((inProg / fixed) * 100) : 0;

        return `
          <div class="ex-wrap">
            ${renderExecutiveBanner(s.title, 'Research • Innovation • Global Impact')}
            ${renderExecutiveKpis(fixed, achieved, achPct, inProg, inProgPct, total, '(Achieved + In Progress)')}
            ${renderExecutiveSplitRow(c, 'Academic Records & Publications')}
            ${renderExecutiveFooter()}
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

    renderSlide(0, false);
    wake();
  </script>
</body>
</html>
