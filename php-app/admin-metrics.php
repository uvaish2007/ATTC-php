<?php
/**
 * ATTS-METRIC-STUDIO — Admin Metric Management Workspace
 *
 * Dedicated workspace for institutional administrators to manage all 30 approved
 * metrics, create new generic metrics, configure form templates, manage display
 * order, and toggle visibility.
 */

declare(strict_types=1);

require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/metric_studio.php';

// Strict backend authorization — Admin role only
$user = require_role(['Admin']);

// Handle AJAX GET config fetch
$isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') || isset($_GET['ajax']) || isset($_POST['ajax']);

if ($isAjax && $_SERVER['REQUEST_METHOD'] === 'GET' && input('action') === 'get_form_config') {
    $id = input('id');
    $cfg = metric_studio_get_form_config($id, true);
    $history = metric_studio_get_form_history($id);
    $reusable = metric_studio_get_reusable_fields();
    $optSets = metric_studio_get_option_sets();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success'         => true,
        'config'          => $cfg,
        'history'         => $history,
        'reusable_fields' => $reusable,
        'option_sets'     => $optSets,
    ]);
    exit;
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = input('action');

    if ($action === 'create') {
        [$ok, $msg, $created] = metric_studio_create([
            'name'                => input('name'),
            'metric_key'          => input('metric_key'),
            'category'            => input('category'),
            'description'         => input('description'),
            'help_text'           => input('help_text'),
            'display_order'       => (int)input('display_order'),
            'metric_type'         => input('metric_type', 'generic'),
            'faculty_visible'     => input('faculty_visible') ? 1 : 0,
            'coordinator_visible' => input('coordinator_visible') ? 1 : 0,
            'status'              => isset($_POST['status']) ? (input('status') ? 1 : 0) : 1,
        ]);
        flash($ok ? 'success' : 'error', $msg);
        redirect('/admin-metrics.php');
    }

    if ($action === 'delete') {
        $id = (int)input('id');
        [$ok, $msg] = metric_studio_delete($id);
        flash($ok ? 'success' : 'error', $msg);
        redirect('/admin-metrics.php');
    }

    if ($action === 'update') {
        $id = (int)input('id');
        [$ok, $msg] = metric_studio_update($id, [
            'name'                => input('name'),
            'category'            => input('category'),
            'description'         => input('description'),
            'help_text'           => input('help_text'),
            'display_order'       => (int)input('display_order'),
            'faculty_visible'     => input('faculty_visible') ? 1 : 0,
            'coordinator_visible' => input('coordinator_visible') ? 1 : 0,
            'status'              => input('status') ? 1 : 0,
        ]);
        flash($ok ? 'success' : 'error', $msg);
        redirect('/admin-metrics.php');
    }

    if ($action === 'toggle_status') {
        $id = (int)input('id');
        [$ok, $msg] = metric_studio_toggle_status($id);
        flash($ok ? 'success' : 'error', $msg);
        redirect('/admin-metrics.php');
    }

    if ($action === 'move') {
        $id = (int)input('id');
        $dir = input('direction') === 'up' ? 'up' : 'down';
        [$ok, $msg] = metric_studio_move($id, $dir);
        flash($ok ? 'success' : 'error', $msg);
        redirect('/admin-metrics.php');
    }

    if ($action === 'reorder_metrics') {
        $rawOrder = $_POST['ordered_ids'] ?? $_POST['order'] ?? null;
        if (is_string($rawOrder)) {
            $orderedIds = json_decode($rawOrder, true);
        } else {
            $orderedIds = $rawOrder;
        }

        if (!is_array($orderedIds) || empty($orderedIds)) {
            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => false, 'message' => 'Invalid reorder data received.']);
                exit;
            }
            flash('error', 'Invalid reorder data received.');
            redirect('/admin-metrics.php');
        }

        $sanitized = [];
        foreach ($orderedIds as $item) {
            if (is_numeric($item) && (int)$item > 0) {
                $sanitized[] = (int)$item;
            } elseif (is_string($item) && trim($item) !== '') {
                $sanitized[] = trim($item);
            }
        }

        if (empty($sanitized)) {
            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => false, 'message' => 'No valid metric identifiers provided.']);
                exit;
            }
            flash('error', 'No valid metric identifiers provided.');
            redirect('/admin-metrics.php');
        }

        [$ok, $msg, $orderMap] = metric_studio_reorder($sanitized);

        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success'   => $ok,
                'message'   => $msg,
                'order'     => $sanitized,
                'order_map' => $orderMap ?? []
            ]);
            exit;
        }

        flash($ok ? 'success' : 'error', $msg);
        redirect('/admin-metrics.php');
    }

    if ($action === 'save_form_draft') {
        $id = input('id');
        $fields = json_decode((string)input('fields_json'), true) ?: [];
        $attachment = json_decode((string)input('attachment_json'), true) ?: null;
        [$ok, $msg] = metric_studio_save_draft_form($id, $fields, $attachment);
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => $ok, 'message' => $msg]);
            exit;
        }
        flash($ok ? 'success' : 'error', $msg);
        redirect('/admin-metrics.php');
    }

    if ($action === 'publish_form' || $action === 'save_template') {
        $id = input('id');
        $rawFields = input('fields_json');
        $fields = json_decode((string)$rawFields, true) ?: [];
        $attachment = json_decode((string)input('attachment_json'), true) ?: null;
        [$ok, $msg] = metric_studio_publish_form($id, $fields, $attachment, (int)$user['id']);
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            $updated = metric_studio_find($id);
            echo json_encode([
                'success' => $ok,
                'message' => $msg,
                'version' => (int)($updated['form_version'] ?? 1)
            ]);
            exit;
        }
        flash($ok ? 'success' : 'error', $msg);
        redirect('/admin-metrics.php');
    }

    if ($action === 'restore_form_version') {
        $id = input('id');
        $histId = input('history_id') ? (int)input('history_id') : null;
        [$ok, $msg] = metric_studio_restore_previous_form($id, $histId);
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => $ok, 'message' => $msg]);
            exit;
        }
        flash($ok ? 'success' : 'error', $msg);
        redirect('/admin-metrics.php');
    }

    if ($action === 'save_reusable_field') {
        $fieldData = json_decode((string)input('field_json'), true) ?: [];
        [$ok, $msg] = metric_studio_save_reusable_field($fieldData);
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => $ok, 'message' => $msg, 'reusable_fields' => metric_studio_get_reusable_fields()]);
            exit;
        }
        flash($ok ? 'success' : 'error', $msg);
        redirect('/admin-metrics.php');
    }

    if ($action === 'save_option_set') {
        $setKey  = (string)input('set_key');
        $name    = (string)input('name');
        $desc    = (string)input('description');
        $options = json_decode((string)input('options_json'), true) ?: [];
        [$ok, $msg] = metric_studio_save_option_set($setKey, $name, $options, $desc);
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => $ok, 'message' => $msg, 'option_sets' => metric_studio_get_option_sets()]);
            exit;
        }
        flash($ok ? 'success' : 'error', $msg);
        redirect('/admin-metrics.php');
    }

    flash('error', 'Unrecognized action.');
    redirect('/admin-metrics.php');
}

// Filters & Querying
$q        = trim((string)input('q', ''));
$status   = trim((string)input('status', 'all'));
$category = trim((string)input('category', 'all'));
$sort     = trim((string)input('sort', 'order'));

$metrics    = metric_studio_all(['q' => $q, 'status' => $status, 'category' => $category, 'sort' => $sort]);
$summary    = metric_studio_summary();
$categories = metric_studio_categories();
$isFilteredOrSorted = ($q !== '' || ($status !== 'all' && $status !== '') || ($category !== 'all' && $category !== '') || $sort !== 'order');

if (!function_exists('metric_format_cat')) {
    function metric_format_cat(string $cat): string {
        $c = strtolower(trim($cat));
        $c = ucwords($c);
        $c = str_ireplace(['And', 'In', 'Of', 'From', 'For'], ['and', 'in', 'of', 'from', 'for'], $c);
        return ucfirst($c);
    }
}

$pageTitle = 'Metric Management';
$breadcrumb = 'Metric Management';
require __DIR__ . '/inc/header.php';
?>

<style>
/* ==========================================================================
   ATTS Metric Studio — Premium SaaS Layout & Design
   ========================================================================== */
.metrics-workspace {
  display: flex;
  flex-direction: column;
  gap: 20px;
  margin-bottom: 40px;
}

.metrics-page-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  flex-wrap: wrap;
  padding-bottom: 4px;
}
.metrics-title-group {
  display: flex;
  align-items: center;
  gap: 10px;
}
.metrics-title-group h1 {
  font-size: 24px;
  font-weight: 700;
  color: var(--ink, #131D3B);
  margin: 0;
  letter-spacing: -0.025em;
  line-height: 1.2;
}
.studio-badge {
  background: linear-gradient(135deg, #FF4F01 0%, #FF6E33 100%);
  color: #FFFFFF;
  font-size: 11px;
  font-weight: 700;
  padding: 3px 10px;
  border-radius: 999px;
  letter-spacing: 0.05em;
  box-shadow: 0 2px 8px rgba(255, 79, 1, 0.35);
  display: inline-flex;
  align-items: center;
}
.metrics-sub {
  font-size: 13.5px;
  color: var(--ink-muted, #5A6785);
  margin-top: 5px;
  line-height: 1.4;
}
.btn-add-metric {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  height: 40px;
  padding: 0 18px;
  border-radius: 9px;
  background: linear-gradient(135deg, #FF4F01 0%, #FF6E33 100%);
  color: #FFFFFF;
  font-weight: 600;
  font-size: 13.5px;
  border: none;
  cursor: pointer;
  box-shadow: 0 3px 10px rgba(255, 79, 1, 0.3);
  transition: all .18s cubic-bezier(.16,1,.3,1);
}
.btn-add-metric:hover {
  transform: translateY(-1px);
  box-shadow: 0 5px 14px rgba(255, 79, 1, 0.4);
  color: #FFFFFF;
}

/* KPI Summary Cards Grid */
.kpi-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
  gap: 16px;
}
.kpi-card {
  background: #FFFFFF;
  border: 1px solid var(--hairline, #E6EAF2);
  border-radius: 12px;
  padding: 16px 18px;
  box-shadow: 0 1px 3px rgba(19, 29, 59, 0.03), 0 2px 6px rgba(19, 29, 59, 0.02);
  transition: transform .18s ease, box-shadow .18s ease;
  position: relative;
  overflow: hidden;
}
.kpi-card:hover {
  transform: translateY(-2px);
  box-shadow: 0 4px 14px rgba(19, 29, 59, 0.07);
}
.kpi-card::before {
  content: "";
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  height: 3.5px;
}
.kpi-card.kpi-total::before { background: var(--orange-500, #FF4F01); }
.kpi-card.kpi-active::before { background: #16A34A; }
.kpi-card.kpi-inactive::before { background: #94A3B8; }
.kpi-card.kpi-custom::before { background: #2563EB; }

.kpi-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.kpi-label {
  font-size: 11.5px;
  font-weight: 700;
  color: var(--ink-muted, #5A6785);
  text-transform: uppercase;
  letter-spacing: 0.05em;
}
.kpi-icon-wrap {
  width: 34px;
  height: 34px;
  border-radius: 8px;
  display: flex;
  align-items: center;
  justify-content: center;
}
.kpi-total .kpi-icon-wrap { background: #FFF3ED; color: #FF4F01; }
.kpi-active .kpi-icon-wrap { background: #DCFCE7; color: #16A34A; }
.kpi-inactive .kpi-icon-wrap { background: #F1F5F9; color: #64748B; }
.kpi-custom .kpi-icon-wrap { background: #EFF6FF; color: #2563EB; }

.kpi-val {
  font-size: 28px;
  font-weight: 800;
  color: var(--ink, #131D3B);
  margin-top: 8px;
  line-height: 1.1;
  font-variant-numeric: tabular-nums;
}
.kpi-sub {
  font-size: 11.5px;
  color: #64748B;
  margin-top: 4px;
}

/* Search & Filter Toolbar */
.filter-toolbar {
  background: #FFFFFF;
  border: 1px solid var(--hairline, #E6EAF2);
  border-radius: 12px;
  padding: 12px 16px;
  box-shadow: 0 1px 3px rgba(19, 29, 59, 0.03);
}
.filter-form {
  display: flex;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
  margin: 0;
}
.search-input-wrap {
  flex: 1 1 260px;
  position: relative;
}
.search-input-wrap .search-icon {
  position: absolute;
  left: 12px;
  top: 50%;
  transform: translateY(-50%);
  color: #94A3B8;
  pointer-events: none;
  display: flex;
}
.search-input-wrap input {
  width: 100%;
  height: 38px;
  padding-left: 36px;
  padding-right: 12px;
  border-radius: 8px;
  border: 1px solid #CBD5E1;
  background: #F8FAFC;
  font-size: 13px;
  transition: all .15s ease;
}
.search-input-wrap input:focus {
  background: #FFFFFF;
  border-color: var(--orange-500, #FF4F01);
  box-shadow: 0 0 0 3px rgba(255, 79, 1, 0.15);
  outline: none;
}
.filter-select-group {
  display: flex;
  align-items: center;
  gap: 6px;
}
.filter-label {
  font-size: 12px;
  font-weight: 600;
  color: #64748B;
  margin: 0;
  white-space: nowrap;
}
.filter-select {
  height: 38px;
  border-radius: 8px;
  border: 1px solid #CBD5E1;
  background: #F8FAFC;
  padding: 0 10px;
  font-size: 12.5px;
  color: #1E293B;
  cursor: pointer;
  transition: all .15s ease;
}
.filter-select:focus {
  background: #FFFFFF;
  border-color: var(--orange-500, #FF4F01);
  box-shadow: 0 0 0 3px rgba(255, 79, 1, 0.15);
  outline: none;
}
.btn-reset-filters {
  height: 38px;
  line-height: 36px;
  padding: 0 14px;
  display: inline-flex;
  align-items: center;
  gap: 5px;
  font-size: 12.5px;
  font-weight: 600;
  color: #64748B;
  border: 1px solid #CBD5E1;
  border-radius: 8px;
  background: #FFFFFF;
  text-decoration: none;
  transition: all .15s ease;
}
.btn-reset-filters:hover {
  background: #F1F5F9;
  color: #1E293B;
}

/* Metrics Table Container */
.metrics-table-card {
  background: #FFFFFF;
  border: 1px solid var(--hairline, #E6EAF2);
  border-radius: 12px;
  box-shadow: 0 1px 4px rgba(19, 29, 59, 0.04);
  overflow: hidden;
}
.metrics-card-head {
  padding: 14px 20px;
  border-bottom: 1px solid var(--hairline, #E6EAF2);
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  flex-wrap: wrap;
  background: #FAFBFC;
}
.metrics-head-title {
  display: flex;
  align-items: center;
  gap: 10px;
}
.metrics-head-title h2 {
  margin: 0;
  font-size: 14.5px;
  font-weight: 700;
  color: var(--ink, #131D3B);
}
.metrics-count-badge {
  background: #E2E8F0;
  color: #475569;
  font-size: 11px;
  font-weight: 700;
  padding: 2px 8px;
  border-radius: 999px;
}
.metrics-head-hint {
  font-size: 12px;
  color: #64748B;
  display: flex;
  align-items: center;
  gap: 6px;
}

/* Table Architecture */
.table-responsive {
  width: 100%;
  overflow-x: auto;
  -webkit-overflow-scrolling: touch;
}
.metrics-table {
  width: 100%;
  border-collapse: collapse;
  margin: 0;
  text-align: left;
}
.metrics-table thead tr {
  background: #F8FAFC;
  border-bottom: 1px solid #E2E8F0;
}
.metrics-table th {
  padding: 12px 14px;
  font-size: 11px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: #64748B;
  white-space: nowrap;
}
.metrics-table td {
  padding: 13px 14px;
  vertical-align: middle !important;
  border-bottom: 1px solid #F1F5F9;
  background: #FFFFFF;
  transition: background .12s ease;
}
.metrics-table tbody tr:hover td {
  background: #FAFBFC;
}
.metrics-table tbody tr.row-inactive td {
  background: #F8FAFC;
  opacity: 0.78;
}

/* Order Column Pill & Drag Controls */
.order-cell-wrap {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  user-select: none;
}
.order-cell-wrap.is-disabled {
  opacity: 0.55;
}

.metric-drag-handle {
  width: 28px;
  height: 28px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  background: #F8FAFC;
  border: 1px solid #CBD5E1;
  border-radius: 6px;
  color: #64748B;
  cursor: grab;
  padding: 0;
  transition: all .15s ease;
  outline: none;
  flex-shrink: 0;
}
.metric-drag-handle:hover:not(:disabled) {
  background: #FFF7ED;
  border-color: #FDBA74;
  color: #FF4F01;
  box-shadow: 0 1px 4px rgba(255, 79, 1, 0.15);
}
.metric-drag-handle:focus-visible {
  border-color: #FF4F01;
  box-shadow: 0 0 0 3px rgba(255, 79, 1, 0.25);
  color: #FF4F01;
}
.metric-drag-handle:active:not(:disabled) {
  cursor: grabbing;
}
.metric-drag-handle:disabled {
  cursor: not-allowed;
  opacity: 0.45;
  background: #F1F5F9;
  border-color: #E2E8F0;
  color: #94A3B8;
}

.order-number-pill {
  width: 28px;
  height: 28px;
  border-radius: 7px;
  background: #F1F5F9;
  border: 1px solid #E2E8F0;
  color: var(--ink, #131D3B);
  font-size: 12px;
  font-weight: 700;
  display: flex;
  align-items: center;
  justify-content: center;
  font-variant-numeric: tabular-nums;
  flex-shrink: 0;
  transition: background .2s ease, border-color .2s ease, color .2s ease, transform .15s ease;
}

/* Dragging visual states */
body.table-row-dragging {
  user-select: none !important;
  -webkit-user-select: none !important;
}
.metric-row {
  transition: background .15s ease, box-shadow .15s ease;
}
.metric-row.is-dragging {
  opacity: 0.35 !important;
  background: #FFF7ED !important;
}
.metric-row.drag-over-above > td {
  border-top: 3px solid #FF4F01 !important;
}
.metric-row.drag-over-below > td {
  border-bottom: 3px solid #FF4F01 !important;
}

/* Keyboard reordering state */
.metric-row.is-keyboard-grabbed {
  background: #FFF7ED !important;
  outline: 2px solid #FF4F01 !important;
  outline-offset: -2px;
  box-shadow: 0 4px 18px rgba(255, 79, 1, 0.22) !important;
}
.metric-row.is-keyboard-grabbed .metric-drag-handle {
  background: #FF4F01 !important;
  border-color: #FF4F01 !important;
  color: #FFFFFF !important;
  box-shadow: 0 2px 8px rgba(255, 79, 1, 0.4) !important;
}
.metric-row.is-keyboard-grabbed .order-number-pill {
  background: #FFEDD5 !important;
  border-color: #FDBA74 !important;
  color: #C2410C !important;
  transform: scale(1.08);
}

/* Toast Notification for Order Save */
.order-toast {
  position: fixed;
  bottom: 24px;
  right: 24px;
  background: #131D3B;
  color: #FFFFFF;
  padding: 12px 18px;
  border-radius: 10px;
  font-size: 13px;
  font-weight: 600;
  display: inline-flex;
  align-items: center;
  gap: 10px;
  box-shadow: 0 10px 25px rgba(19, 29, 59, 0.35);
  z-index: 99999;
  transform: translateY(100px);
  opacity: 0;
  transition: transform .25s cubic-bezier(0.16, 1, 0.3, 1), opacity .25s ease;
  pointer-events: none;
}
.order-toast.show {
  transform: translateY(0);
  opacity: 1;
}
.order-toast.toast-success {
  border-left: 4px solid #10B981;
}
.order-toast.toast-error {
  border-left: 4px solid #EF4444;
}
.order-toast.toast-saving {
  border-left: 4px solid #FF4F01;
}

@keyframes toastSpin {
  100% { transform: rotate(360deg); }
}
.spin-icon {
  display: inline-flex;
  animation: toastSpin 1s linear infinite;
}

.hint-filtered {
  background: #FEF2F2 !important;
  border: 1px solid #FECACA !important;
  color: #991B1B !important;
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 6px 14px;
  border-radius: 8px;
  font-size: 12.5px;
}
.btn-clear-filters-hint {
  color: #DC2626;
  font-weight: 700;
  text-decoration: underline;
  margin-left: 4px;
}
.btn-clear-filters-hint:hover {
  color: #991B1B;
}

/* Metric Title & Metadata */
.metric-title-text {
  font-size: 13.5px;
  font-weight: 700;
  color: var(--ink, #131D3B);
  line-height: 1.35;
  margin-bottom: 4px;
}
.metric-meta-row {
  display: flex;
  align-items: center;
  gap: 6px;
  flex-wrap: wrap;
}
.metric-key-code {
  font-family: 'SFMono-Regular', Consolas, Menlo, monospace;
  font-size: 11px;
  font-weight: 600;
  background: #F1F5F9;
  color: #475569;
  padding: 2px 7px;
  border-radius: 4px;
  border: 1px solid #E2E8F0;
}
.type-badge {
  font-size: 10.5px;
  font-weight: 600;
  padding: 1px 7px;
  border-radius: 999px;
  line-height: 1.4;
}
.type-specialized { background: #EFF6FF; color: #1D4ED8; border: 1px solid #DBEAFE; }
.type-hybrid { background: #FAF5FF; color: #7E22CE; border: 1px solid #F3E8FF; }
.type-generic { background: #ECFDF5; color: #047857; border: 1px solid #D1FAE5; }
.metric-desc-text {
  font-size: 11.5px;
  color: #64748B;
  margin-top: 4px;
  line-height: 1.4;
  max-width: 380px;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

/* Category Pill */
.category-pill {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 4px 10px;
  background: #F8FAFC;
  border: 1px solid #E2E8F0;
  border-radius: 999px;
  font-size: 11.5px;
  font-weight: 600;
  color: #334155;
  max-width: 210px;
}
.category-dot {
  width: 6px;
  height: 6px;
  border-radius: 50%;
  background: #3B82F6;
  flex-shrink: 0;
}
.category-name {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

/* Form Configuration Column */
.form-type-pill {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 3px 8px;
  border-radius: 6px;
  font-size: 11.5px;
  font-weight: 600;
}
.form-builtin {
  background: #EFF6FF;
  color: #1D4ED8;
  border: 1px solid #BFDBFE;
}
.form-dynamic {
  background: #ECFDF5;
  color: #047857;
  border: 1px solid #A7F3D0;
}
.form-sub-info {
  font-size: 11px;
  color: #64748B;
  margin-top: 3px;
  display: flex;
  align-items: center;
  gap: 4px;
}
.btn-edit-fields {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 2px 7px;
  font-size: 11px;
  font-weight: 600;
  color: #2563EB;
  background: transparent;
  border: 1px solid #BFDBFE;
  border-radius: 4px;
  cursor: pointer;
  margin-top: 3px;
  transition: all .15s ease;
}
.btn-edit-fields:hover {
  background: #EFF6FF;
  border-color: #93C5FD;
}

/* Visibility Badges */
.visibility-col {
  display: inline-flex;
  flex-direction: column;
  align-items: center;
  gap: 4px;
}
.status-pill {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 2px 8px;
  border-radius: 999px;
  font-size: 11px;
  font-weight: 700;
}
.status-pill.active { background: #DCFCE7; color: #15803D; }
.status-pill.inactive { background: #F1F5F9; color: #64748B; }
.status-pill-dot {
  width: 6px;
  height: 6px;
  border-radius: 50%;
}
.status-pill.active .status-pill-dot { background: #16A34A; }
.status-pill.inactive .status-pill-dot { background: #94A3B8; }
.channel-row {
  display: flex;
  align-items: center;
  gap: 4px;
}
.channel-tag {
  font-size: 10px;
  font-weight: 600;
  padding: 1px 5px;
  border-radius: 4px;
}
.channel-tag.on { background: #F1F5F9; color: #475569; }
.channel-tag.off { background: #FEE2E2; color: #DC2626; }

/* Action Dock */
.action-dock {
  display: inline-flex;
  align-items: center;
  background: #FFFFFF;
  border: 1px solid #CBD5E1;
  border-radius: 8px;
  padding: 2px;
  box-shadow: 0 1px 2px rgba(0,0,0,0.03);
  gap: 2px;
}
.dock-btn {
  width: 28px;
  height: 28px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border: none;
  background: transparent;
  border-radius: 6px;
  color: #64748B;
  cursor: pointer;
  padding: 0;
  transition: all .15s ease;
}
.dock-btn:hover {
  transform: translateY(-1px);
}
.dock-preview:hover { color: #2563EB; background: #EFF6FF; }
.dock-edit:hover { color: #3B82F6; background: #EFF6FF; }
.dock-builder:hover { color: #059669; background: #ECFDF5; }
.dock-activate:hover { color: #16A34A; background: #DCFCE7; }
.dock-deactivate:hover { color: #D97706; background: #FEF3C7; }
.dock-delete { color: #DC2626; }
.dock-delete:hover { color: #DC2626; background: #FEE2E2; }

/* ==========================================================================
   ATTS-DYNAMIC-FORM-STUDIO — Full 3-Column Studio Workspace CSS
   ========================================================================== */
dialog.studio-modal {
  width: 96vw;
  max-width: 86rem;
  height: 92vh;
  max-height: 94vh;
  border: 0;
  border-radius: 16px;
  padding: 0;
  box-shadow: 0 25px 60px -15px rgba(19, 29, 59, 0.45);
  display: flex;
  flex-direction: column;
  overflow: hidden;
  background: #F8FAFC;
}
dialog.studio-modal::backdrop {
  background: rgba(15, 23, 42, 0.7);
  backdrop-filter: blur(4px);
}
.studio-head {
  background: #131D3B;
  color: #FFFFFF;
  padding: 14px 22px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  border-bottom: 1px solid rgba(255, 255, 255, 0.1);
  flex-shrink: 0;
}
.studio-head-left {
  display: flex;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
}
.studio-head-brand {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  background: rgba(255, 79, 1, 0.2);
  border: 1px solid #FF4F01;
  color: #FF854D;
  font-size: 11px;
  font-weight: 700;
  padding: 3px 8px;
  border-radius: 6px;
  letter-spacing: 0.04em;
}
.studio-metric-title {
  font-size: 16px;
  font-weight: 700;
  color: #FFFFFF;
  margin: 0;
  display: flex;
  align-items: center;
  gap: 8px;
}
.studio-key-pill {
  font-family: 'SFMono-Regular', Consolas, monospace;
  font-size: 11px;
  background: rgba(255, 255, 255, 0.12);
  color: #93C5FD;
  padding: 2px 7px;
  border-radius: 4px;
}
.studio-ver-pill {
  font-size: 11px;
  font-weight: 700;
  background: #3B82F6;
  color: #FFFFFF;
  padding: 2px 8px;
  border-radius: 999px;
}
.studio-head-actions {
  display: flex;
  align-items: center;
  gap: 10px;
}
.btn-studio-save-draft {
  background: rgba(255, 255, 255, 0.12);
  color: #FFFFFF;
  border: 1px solid rgba(255, 255, 255, 0.25);
  font-size: 12.5px;
  font-weight: 600;
  height: 34px;
  padding: 0 14px;
  border-radius: 7px;
  display: inline-flex;
  align-items: center;
  gap: 6px;
  cursor: pointer;
  transition: all .15s ease;
}
.btn-studio-save-draft:hover {
  background: rgba(255, 255, 255, 0.2);
}
.btn-studio-publish {
  background: linear-gradient(135deg, #FF4F01 0%, #FF6E33 100%);
  color: #FFFFFF;
  border: none;
  font-size: 12.5px;
  font-weight: 700;
  height: 34px;
  padding: 0 16px;
  border-radius: 7px;
  display: inline-flex;
  align-items: center;
  gap: 6px;
  cursor: pointer;
  box-shadow: 0 3px 10px rgba(255, 79, 1, 0.35);
  transition: all .15s ease;
}
.btn-studio-publish:hover {
  transform: translateY(-1px);
  box-shadow: 0 4px 14px rgba(255, 79, 1, 0.5);
}
.btn-studio-history {
  background: transparent;
  color: #CBD5E1;
  border: 1px solid rgba(255, 255, 255, 0.2);
  font-size: 12px;
  font-weight: 600;
  height: 34px;
  padding: 0 12px;
  border-radius: 7px;
  display: inline-flex;
  align-items: center;
  gap: 5px;
  cursor: pointer;
}
.btn-studio-history:hover {
  color: #FFFFFF;
  background: rgba(255, 255, 255, 0.1);
}
.btn-studio-close {
  background: transparent;
  border: none;
  color: #94A3B8;
  font-size: 20px;
  line-height: 1;
  width: 32px;
  height: 32px;
  border-radius: 6px;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  justify-content: center;
}
.btn-studio-close:hover {
  color: #FFFFFF;
  background: rgba(255, 255, 255, 0.15);
}

/* 3-Column Layout */
.studio-workspace-grid {
  display: grid;
  grid-template-columns: 280px 1fr 380px;
  flex: 1;
  overflow: hidden;
  height: calc(92vh - 63px);
}
@media (max-width: 1280px) {
  .studio-workspace-grid {
    grid-template-columns: 260px 1fr 340px;
  }
}
@media (max-width: 1080px) {
  .studio-workspace-grid {
    grid-template-columns: 240px 1fr;
    height: auto;
    max-height: calc(94vh - 63px);
    overflow-y: auto;
  }
  .studio-col-preview {
    grid-column: span 2;
    border-left: none;
    border-top: 1px solid #E2E8F0;
    min-height: 400px;
  }
}
@media (max-width: 768px) {
  dialog.studio-modal {
    width: 98vw;
    height: 96vh;
    max-height: 98vh;
  }
  .studio-workspace-grid {
    display: flex;
    flex-direction: column;
    height: auto;
    max-height: calc(96vh - 75px);
    overflow-y: auto;
  }
  .studio-col-library {
    max-height: 320px;
    border-right: none;
    border-bottom: 1px solid #E2E8F0;
  }
  .studio-head {
    flex-direction: column;
    align-items: flex-start;
    gap: 10px;
    height: auto;
    padding: 10px 14px;
  }
  .studio-head-actions {
    width: 100%;
    justify-content: flex-end;
    flex-wrap: wrap;
    gap: 6px;
  }
  .attachment-config-grid {
    grid-template-columns: 1fr !important;
  }
  .att-field-col[style*="span 2"] {
    grid-column: span 1 !important;
  }
}

/* Left Column: Field Library */
.studio-col-library {
  background: #FFFFFF;
  border-right: 1px solid #E2E8F0;
  display: flex;
  flex-direction: column;
  overflow: hidden;
  height: 100%;
}
.library-tab-strip {
  display: flex;
  align-items: center;
  background: #F8FAFC;
  padding: 8px 12px;
  border-bottom: 1px solid #E2E8F0;
  gap: 6px;
  height: 52px;
  min-height: 52px;
  max-height: 52px;
  box-sizing: border-box;
  flex-shrink: 0;
}
.library-tab-btn {
  flex: 1;
  height: 36px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  background: transparent;
  border: 1px solid transparent;
  font-size: 12px;
  font-weight: 600;
  color: #64748B;
  padding: 0 10px;
  border-radius: 6px;
  cursor: pointer;
  text-align: center;
  transition: all .15s ease;
  line-height: 1.2;
}
.library-tab-btn.active {
  background: #FFFFFF;
  color: #131D3B;
  border-color: #CBD5E1;
  box-shadow: 0 1px 3px rgba(0,0,0,0.06);
  font-weight: 700;
}
.library-scroll-pane {
  padding: 14px;
  overflow-y: auto;
  overflow-x: hidden;
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.library-scroll-pane::-webkit-scrollbar,
.canvas-scroll-pane::-webkit-scrollbar,
.preview-scroll-pane::-webkit-scrollbar {
  width: 6px;
}
.library-scroll-pane::-webkit-scrollbar-thumb,
.canvas-scroll-pane::-webkit-scrollbar-thumb,
.preview-scroll-pane::-webkit-scrollbar-thumb {
  background: #CBD5E1;
  border-radius: 4px;
}
.library-type-btn {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 9px 12px;
  background: #F8FAFC;
  border: 1px solid #E2E8F0;
  border-radius: 8px;
  color: #1E293B;
  text-align: left;
  cursor: pointer;
  transition: all .15s ease;
}
.library-type-btn:hover {
  background: #FFF7ED;
  border-color: #FFB38A;
  color: #C2410C;
  transform: translateX(2px);
}
.library-type-icon {
  width: 28px;
  height: 28px;
  border-radius: 6px;
  background: #FFFFFF;
  border: 1px solid #CBD5E1;
  display: flex;
  align-items: center;
  justify-content: center;
  color: #475569;
  flex-shrink: 0;
}
.library-type-btn:hover .library-type-icon {
  background: #FF4F01;
  border-color: #FF4F01;
  color: #FFFFFF;
}
.library-type-info {
  flex: 1;
  min-width: 0;
}
.library-type-title {
  font-size: 12.5px;
  font-weight: 700;
  line-height: 1.25;
}
.library-type-sub {
  font-size: 11px;
  color: #64748B;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  margin-top: 2px;
}

/* Center Column: Form Canvas */
.studio-col-canvas {
  background: #F8FAFC;
  display: flex;
  flex-direction: column;
  overflow: hidden;
  position: relative;
  height: 100%;
}
.canvas-toolbar {
  background: #FFFFFF;
  border-bottom: 1px solid #E2E8F0;
  padding: 0 18px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-shrink: 0;
  gap: 12px;
  height: 52px;
  min-height: 52px;
  max-height: 52px;
  box-sizing: border-box;
}
.canvas-scroll-pane {
  padding: 16px 20px 30px 20px;
  overflow-y: auto;
  overflow-x: hidden;
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 12px;
}
.field-card {
  background: #FFFFFF;
  border: 1px solid #CBD5E1;
  border-radius: 10px;
  box-shadow: 0 1px 3px rgba(0,0,0,0.03);
  transition: all .15s ease;
  overflow: hidden;
}
.field-card:hover {
  border-color: #94A3B8;
  box-shadow: 0 3px 8px rgba(0,0,0,0.06);
}
.field-card.protected-card {
  border-left: 4px solid #3B82F6;
  background: #FAFCFF;
}
.field-card.active-card {
  border-color: #FF4F01;
  box-shadow: 0 0 0 2px rgba(255, 79, 1, 0.18), 0 3px 8px rgba(0,0,0,0.06);
}
.field-card.active-card .field-card-order {
  background: #FF4F01;
  color: #FFFFFF;
}
.field-card-head {
  padding: 10px 14px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  background: #FFFFFF;
  border-bottom: 1px solid #F1F5F9;
  cursor: pointer;
  user-select: none;
  gap: 8px;
}
.field-card-left {
  display: flex;
  align-items: center;
  gap: 8px;
  flex: 1;
  min-width: 0;
}
.field-card-order {
  width: 22px;
  height: 22px;
  border-radius: 5px;
  background: #F1F5F9;
  color: #475569;
  font-size: 11px;
  font-weight: 700;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}
.field-card-title {
  font-size: 13.5px;
  font-weight: 700;
  color: #1E293B;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.field-card-actions {
  display: flex;
  align-items: center;
  gap: 4px;
}
.btn-card-tool {
  width: 26px;
  height: 26px;
  border-radius: 5px;
  border: 1px solid #E2E8F0;
  background: #FFFFFF;
  color: #64748B;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  padding: 0;
  transition: all .12s ease;
}
.btn-card-tool:hover {
  background: #F1F5F9;
  color: #0F172A;
  border-color: #CBD5E1;
}
.btn-card-delete:hover {
  background: #FEE2E2;
  color: #DC2626;
  border-color: #FECACA;
}
.field-card-body {
  padding: 14px;
  display: flex;
  flex-direction: column;
  gap: 12px;
  background: #FFFFFF;
}

/* Empty Canvas Placeholder */
.studio-empty-canvas {
  background: #FFFFFF;
  border: 2px dashed #CBD5E1;
  border-radius: 12px;
  padding: 32px 24px;
  text-align: center;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 8px;
  margin-bottom: 4px;
}
.studio-empty-icon {
  width: 48px;
  height: 48px;
  border-radius: 12px;
  background: #FFF7ED;
  color: #FF4F01;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  margin-bottom: 4px;
}
.studio-empty-title {
  font-size: 15px;
  font-weight: 700;
  color: #1E293B;
}
.studio-empty-desc {
  font-size: 12.5px;
  color: #64748B;
  max-width: 440px;
  line-height: 1.45;
}

/* Anchored Attachment Card */
.studio-attachment-box {
  background: #FFFBF7;
  border: 2px dashed #FF854D;
  border-radius: 12px;
  padding: 18px 20px;
  margin-top: 4px;
  display: flex;
  flex-direction: column;
  gap: 14px;
  box-shadow: 0 1px 3px rgba(255, 79, 1, 0.04);
}
.attachment-box-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 10px;
}
.attachment-tag-badge {
  background: #FF4F01;
  color: #FFFFFF;
  font-size: 11px;
  font-weight: 700;
  padding: 3px 9px;
  border-radius: 999px;
  display: inline-flex;
  align-items: center;
  gap: 5px;
}

/* Structured Grid inside Attachment Card */
.attachment-config-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 12px 16px;
  background: #FFFFFF;
  border: 1px solid #FED7AA;
  border-radius: 10px;
  padding: 16px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.02);
}
.att-field-col {
  display: flex;
  flex-direction: column;
}
.att-field-label {
  font-size: 11.5px;
  font-weight: 700;
  color: #334155;
  margin-bottom: 6px;
  display: block;
}
.att-field-input {
  height: 34px !important;
  font-size: 12px;
  width: 100%;
  box-sizing: border-box;
}
.att-formats-row {
  display: flex;
  align-items: center;
  gap: 14px;
  height: 34px;
  flex-wrap: nowrap;
}
.att-format-pill {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  cursor: pointer;
  margin: 0;
  font-size: 12px;
  color: #334155;
  white-space: nowrap;
  user-select: none;
}
.att-format-pill input[type="checkbox"] {
  cursor: pointer;
  margin: 0;
  width: 15px;
  height: 15px;
  accent-color: #FF4F01;
}

/* Right Column: Live Form Preview */
.studio-col-preview {
  background: #FFFFFF;
  border-left: 1px solid #E2E8F0;
  display: flex;
  flex-direction: column;
  overflow: hidden;
  height: 100%;
}
.preview-pane-head {
  background: #F8FAFC;
  border-bottom: 1px solid #E2E8F0;
  padding: 0 16px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-shrink: 0;
  height: 52px;
  min-height: 52px;
  max-height: 52px;
  box-sizing: border-box;
}
.preview-scroll-pane {
  padding: 16px;
  overflow-y: auto;
  overflow-x: hidden;
  flex: 1;
}
.preview-form-card {
  background: #FFFFFF;
  border: 1px solid #E2E8F0;
  border-radius: 10px;
  padding: 16px;
  box-shadow: 0 1px 4px rgba(0,0,0,0.04);
  display: flex;
  flex-direction: column;
  gap: 12px;
}
.preview-proof-dropzone {
  border: 2px dashed #CBD5E1;
  border-radius: 10px;
  padding: 18px 14px;
  text-align: center;
  background: #F8FAFC;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 6px;
  transition: border-color .15s ease;
}
.preview-proof-dropzone:hover {
  border-color: #FF854D;
  background: #FFFBF7;
}
</style>

<div class="metrics-workspace">
  <!-- Page Header -->
  <div class="metrics-page-head">
    <div>
      <div class="metrics-title-group">
        <h1>Metric Management</h1>
        <span class="studio-badge"><?= icon('target', 13) ?> ATTS-METRIC-STUDIO</span>
      </div>
      <div class="metrics-sub">
        Configure institutional academic targets, dynamic form templates, display order, and faculty upload visibility
      </div>
    </div>
    <div>
      <button type="button" class="btn-add-metric" onclick="openAddMetricModal()">
        <?= icon('plus', 16) ?> Add New Metric
      </button>
    </div>
  </div>

  <!-- KPI Summary Cards Grid -->
  <div class="kpi-grid">
    <!-- Card 1: Total Metrics -->
    <div class="kpi-card kpi-total">
      <div class="kpi-header">
        <span class="kpi-label">Total Metrics</span>
        <div class="kpi-icon-wrap"><?= icon('target', 18) ?></div>
      </div>
      <div class="kpi-val"><?= (int)$summary['total'] ?></div>
      <div class="kpi-sub">Institutional target definitions</div>
    </div>

    <!-- Card 2: Active Metrics -->
    <div class="kpi-card kpi-active">
      <div class="kpi-header">
        <span class="kpi-label">Active Metrics</span>
        <div class="kpi-icon-wrap"><?= icon('check', 18) ?></div>
      </div>
      <div class="kpi-val" style="color:#166534;"><?= (int)$summary['active'] ?></div>
      <div class="kpi-sub" style="color:#16A34A;">Visible for upload submissions</div>
    </div>

    <!-- Card 3: Inactive Metrics -->
    <div class="kpi-card kpi-inactive">
      <div class="kpi-header">
        <span class="kpi-label">Inactive Metrics</span>
        <div class="kpi-icon-wrap"><?= icon('eye-off', 18) ?></div>
      </div>
      <div class="kpi-val" style="color:#475569;"><?= (int)$summary['inactive'] ?></div>
      <div class="kpi-sub">Hidden from new uploads</div>
    </div>

    <!-- Card 4: Custom Templates -->
    <div class="kpi-card kpi-custom">
      <div class="kpi-header">
        <span class="kpi-label">Custom Templates</span>
        <div class="kpi-icon-wrap"><?= icon('layers', 18) ?></div>
      </div>
      <div class="kpi-val" style="color:#1E40AF;"><?= (int)$summary['generic_count'] ?></div>
      <div class="kpi-sub" style="color:#2563EB;">Dynamic form schemas</div>
    </div>
  </div>

  <!-- Search & Filtering Toolbar -->
  <div class="filter-toolbar">
    <form method="get" action="<?= e(url('admin-metrics.php')) ?>" class="filter-form">
      <!-- Search Input -->
      <div class="search-input-wrap">
        <span class="search-icon"><?= icon('search', 16) ?></span>
        <input type="text" name="q" value="<?= e($q) ?>" placeholder="Search metric by title, key, or description...">
      </div>

      <!-- Status Filter -->
      <div class="filter-select-group">
        <label for="filterStatus" class="filter-label">Status:</label>
        <select class="filter-select" id="filterStatus" name="status" onchange="this.form.submit()">
          <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>All Statuses</option>
          <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Active Only</option>
          <option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>Inactive Only</option>
        </select>
      </div>

      <!-- Category Filter -->
      <div class="filter-select-group">
        <label for="filterCat" class="filter-label">Category:</label>
        <select class="filter-select" id="filterCat" name="category" onchange="this.form.submit()" style="max-width:240px;">
          <option value="all" <?= $category === 'all' ? 'selected' : '' ?>>All Categories</option>
          <?php foreach ($categories as $c): ?>
            <option value="<?= e($c) ?>" <?= $category === $c ? 'selected' : '' ?>><?= e(metric_format_cat($c)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- Sort Filter -->
      <div class="filter-select-group">
        <label for="filterSort" class="filter-label">Sort:</label>
        <select class="filter-select" id="filterSort" name="sort" onchange="this.form.submit()">
          <option value="order" <?= $sort === 'order' ? 'selected' : '' ?>>Display Order</option>
          <option value="name" <?= $sort === 'name' ? 'selected' : '' ?>>Metric Name</option>
          <option value="category" <?= $sort === 'category' ? 'selected' : '' ?>>Category</option>
          <option value="updated" <?= $sort === 'updated' ? 'selected' : '' ?>>Recently Updated</option>
        </select>
      </div>

      <!-- Reset Filter Button -->
      <?php if ($q !== '' || $status !== 'all' || $category !== 'all' || $sort !== 'order'): ?>
        <a href="<?= e(url('admin-metrics.php')) ?>" class="btn-reset-filters">
          <?= icon('x', 14) ?> Reset
        </a>
      <?php endif; ?>
    </form>
  </div>

  <!-- Main Metrics Management Table -->
  <div class="metrics-table-card">
    <div class="metrics-card-head">
      <div class="metrics-head-title">
        <h2>Institutional Target Metrics</h2>
        <span class="metrics-count-badge"><?= count($metrics) ?> listed</span>
      </div>
      <?php if ($isFilteredOrSorted): ?>
        <div class="metrics-head-hint hint-filtered">
          <?= icon('alert-triangle', 14) ?>
          <span>Drag-and-drop reordering is locked while filters or custom sorts are active.</span>
          <a href="<?= e(url('admin-metrics.php')) ?>" class="btn-clear-filters-hint">Reset filters</a>
        </div>
      <?php else: ?>
        <div class="metrics-head-hint" id="metricsDragHint">
          <span style="color:#FF4F01;display:inline-flex;align-items:center;"><?= icon('grip-vertical', 14) ?></span>
          <span>Drag handle or press Space on handle to reorder metrics for Faculty &amp; Coordinator pages</span>
        </div>
      <?php endif; ?>
    </div>

    <?php if (empty($metrics)): ?>
      <div style="padding:48px 24px;text-align:center;color:#64748B;">
        <?= icon('target', 36) ?>
        <div style="font-size:15px;font-weight:700;margin-top:10px;color:#334155;">No metrics found matching criteria</div>
        <div style="font-size:12.5px;margin-top:4px;">Try resetting your search query or status filter.</div>
      </div>
    <?php else: ?>
      <div class="table-responsive">
        <table class="metrics-table">
          <thead>
            <tr>
              <th style="width:84px;text-align:center;">Order</th>
              <th style="min-width:280px;">Metric Name &amp; Key</th>
              <th style="min-width:190px;">Category / Group</th>
              <th style="min-width:180px;">Form Configuration</th>
              <th style="width:130px;text-align:center;">Visibility</th>
              <th style="width:115px;">Last Updated</th>
              <th style="width:165px;text-align:right;">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($metrics as $m): 
              $isActive = ($m['status'] === 1);
              $isSpecialized = ($m['metric_type'] === 'specialized');
              $isHybrid = ($m['metric_type'] === 'hybrid');
              $isGeneric = ($m['metric_type'] === 'generic');
            ?>
              <tr class="metric-row <?= !$isActive ? 'row-inactive' : '' ?>"
                  data-id="<?= (int)$m['id'] ?>"
                  data-key="<?= e($m['metric_key']) ?>"
                  data-name="<?= e($m['name']) ?>"
                  data-order="<?= (int)$m['display_order'] ?>">
                <!-- Order & Drag Handle Controls -->
                <td style="text-align:center;" class="order-cell">
                  <div class="order-cell-wrap <?= $isFilteredOrSorted ? 'is-disabled' : '' ?>">
                    <button type="button"
                            class="metric-drag-handle <?= $isFilteredOrSorted ? 'disabled' : '' ?>"
                            <?= $isFilteredOrSorted ? 'disabled aria-disabled="true"' : 'draggable="false"' ?>
                            aria-label="<?= $isFilteredOrSorted ? 'Reordering locked while filtered' : 'Reorder metric ' . e($m['name']) ?>"
                            aria-grabbed="false"
                            title="<?= $isFilteredOrSorted ? 'Reordering is disabled while search filters or custom sorts are applied. Reset filters to enable.' : 'Drag to reorder metric (or press Space to pick up)' ?>"
                            tabindex="<?= $isFilteredOrSorted ? '-1' : '0' ?>">
                      <?= icon('grip-vertical', 14) ?>
                    </button>
                    <span class="order-number-pill" data-order-pill>
                      <?= sprintf('%02d', (int)$m['display_order']) ?>
                    </span>
                  </div>
                </td>

                <!-- Metric Title & Stable Key -->
                <td>
                  <div class="metric-title-text">
                    <?= e($m['name']) ?>
                  </div>
                  <div class="metric-meta-row">
                    <code class="metric-key-code" title="Permanent system identifier">
                      <?= e($m['metric_key']) ?>
                    </code>
                    <?php if ($isSpecialized): ?>
                      <span class="type-badge type-specialized" title="Built-in specialized calculation logic">Specialized</span>
                    <?php elseif ($isHybrid): ?>
                      <span class="type-badge type-hybrid" title="Built-in logic with configurable subcategories">Hybrid</span>
                    <?php else: ?>
                      <span class="type-badge type-generic" title="Configurable dynamic form schema">Dynamic Form</span>
                    <?php endif; ?>
                  </div>
                  <?php if (!empty($m['description'])): ?>
                    <div class="metric-desc-text" title="<?= e($m['description']) ?>">
                      <?= e($m['description']) ?>
                    </div>
                  <?php endif; ?>
                </td>

                <!-- Category / Group -->
                <td>
                  <span class="category-pill" title="<?= e($m['category']) ?>">
                    <span class="category-dot"></span>
                    <span class="category-name"><?= e(metric_format_cat($m['category'])) ?></span>
                  </span>
                </td>

                <!-- Form Config Status -->
                <td>
                  <?php if ($isSpecialized): ?>
                    <div class="form-type-pill form-builtin">
                      <?= icon('shield', 13) ?>
                      <span>Specialized (v<?= (int)($m['form_version'] ?? 1) ?>)</span>
                    </div>
                  <?php elseif ($isHybrid): ?>
                    <div class="form-type-pill form-builtin" style="background:#FAF5FF;color:#7E22CE;border-color:#F3E8FF;">
                      <?= icon('layers', 13) ?>
                      <span>Hybrid (v<?= (int)($m['form_version'] ?? 1) ?>)</span>
                    </div>
                  <?php else: ?>
                    <div class="form-type-pill form-dynamic">
                      <?= icon('layers', 13) ?>
                      <span>Dynamic (v<?= (int)($m['form_version'] ?? 1) ?>)</span>
                    </div>
                  <?php endif; ?>
                  <?php if (!empty($m['subcategories_parsed'])): ?>
                    <div class="form-sub-info">
                      <span style="color:#94A3B8;">↳</span> <?= count($m['subcategories_parsed']) ?> subcategories
                    </div>
                  <?php endif; ?>
                  <div style="margin-top:3px;">
                    <button type="button" class="btn-edit-fields" onclick='openFormStudio(<?= (int)$m['id'] ?>)'>
                      <?= icon('sliders', 11) ?> Configure Form
                    </button>
                  </div>
                </td>

                <!-- Visibility Status -->
                <td style="text-align:center;">
                  <div class="visibility-col">
                    <?php if ($isActive): ?>
                      <span class="status-pill active">
                        <span class="status-pill-dot"></span> Active
                      </span>
                    <?php else: ?>
                      <span class="status-pill inactive">
                        <span class="status-pill-dot"></span> Inactive
                      </span>
                    <?php endif; ?>

                    <div class="channel-row">
                      <?php if (!empty($m['faculty_visible'])): ?>
                        <span class="channel-tag on" title="Visible to Faculty in Upload Data">Faculty</span>
                      <?php else: ?>
                        <span class="channel-tag off" title="Hidden from Faculty">No Fac</span>
                      <?php endif; ?>
                      <?php if (!empty($m['coordinator_visible'])): ?>
                        <span class="channel-tag on" title="Visible to Coordinator">Coord</span>
                      <?php endif; ?>
                    </div>
                  </div>
                </td>

                <!-- Last Updated -->
                <td style="font-size:12px;color:#64748B;white-space:nowrap;">
                  <div style="display:flex;align-items:center;gap:5px;">
                    <span style="color:#94A3B8;"><?= icon('calendar', 12) ?></span>
                    <span style="font-variant-numeric:tabular-nums;">
                      <?= !empty($m['updated_at']) ? date('d M Y', strtotime($m['updated_at'])) : '—' ?>
                    </span>
                  </div>
                </td>

                <!-- Action Buttons Dock -->
                <td style="text-align:right;">
                  <div class="action-dock">
                    <!-- Preview Form -->
                    <button type="button" class="dock-btn dock-preview" onclick='openPreviewModal(<?= json_encode($m, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>)' title="Preview Form">
                      <?= icon('eye', 14) ?>
                    </button>

                    <!-- Edit Details -->
                    <button type="button" class="dock-btn dock-edit" onclick='openEditModal(<?= json_encode($m, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>)' title="Edit Metric Settings">
                      <?= icon('pencil', 14) ?>
                    </button>

                    <!-- Configure Form Fields & Proof (for all metrics) -->
                    <button type="button" class="dock-btn dock-builder" onclick='openFormStudio(<?= (int)$m['id'] ?>)' title="Configure Form Fields &amp; Proof Attachment">
                      <?= icon('layers', 14) ?>
                    </button>

                    <!-- Toggle Status -->
                    <button type="button" class="dock-btn <?= $isActive ? 'dock-deactivate' : 'dock-activate' ?>" onclick="openToggleStatusModal(<?= (int)$m['id'] ?>, '<?= e(addslashes($m['name'])) ?>', <?= $isActive ? 'true' : 'false' ?>)" title="<?= $isActive ? 'Deactivate Metric (Hide from Faculty)' : 'Activate Metric (Show to Faculty)' ?>">
                      <?= icon($isActive ? 'eye-off' : 'check', 14) ?>
                    </button>

                    <!-- Delete Metric -->
                    <button type="button" class="dock-btn dock-delete" onclick="openDeleteModal(<?= (int)$m['id'] ?>, '<?= e(addslashes($m['name'])) ?>', '<?= e($m['metric_key']) ?>', '<?= e($m['metric_type']) ?>')" title="Delete Metric">
                      <?= icon('trash-2', 14) ?>
                    </button>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<!-- ========================================================================= -->
<!-- MODAL: ADD NEW METRIC                                                     -->
<!-- ========================================================================= -->
<dialog class="modal" id="addMetricModal" style="max-width:38rem;width:95vw;border-radius:12px;">
  <form method="post" action="<?= e(url('admin-metrics.php')) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="create">

    <div class="modal-head" style="padding:16px 20px;border-bottom:1px solid #E2E8F0;">
      <div>
        <h3 style="margin:0;font-size:16px;font-weight:700;color:var(--ink, #1E293B);">Add New Institutional Metric</h3>
        <div class="msub" style="font-size:12px;color:#64748B;margin-top:2px;">Define an academic target and configure its data-entry form.</div>
      </div>
    </div>

    <div class="modal-body" style="padding:18px 20px;display:flex;flex-direction:column;gap:14px;">
      <div class="field">
        <label>Metric Name <span class="req">*</span></label>
        <input class="input" type="text" id="addName" name="name" required placeholder="e.g. Community Outreach & Social Impact" oninput="autoGenerateKey(this.value, 'addKey')">
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
        <div class="field">
          <label>Stable Key / Identifier <span class="req">*</span> <span class="card-sub">— unique, permanent</span></label>
          <input class="input" type="text" id="addKey" name="metric_key" required placeholder="e.g. community_outreach" style="font-family:monospace;font-size:12.5px;">
        </div>

        <div class="field">
          <label>Category / Target Group <span class="req">*</span></label>
          <input class="input" list="catList" name="category" required placeholder="Select or type category...">
          <datalist id="catList">
            <?php foreach ($categories as $c): ?>
              <option value="<?= e($c) ?>">
            <?php endforeach; ?>
          </datalist>
        </div>
      </div>

      <div class="field">
        <label>Description / Objectives</label>
        <textarea class="input" name="description" rows="2" placeholder="Brief summary of this target, criteria, or guidelines..."></textarea>
      </div>

      <div class="field">
        <label>Help Text / Submission Instructions</label>
        <input class="input" name="help_text" placeholder="Instructions displayed to faculty when entering data...">
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
        <div class="field">
          <label>Display Order</label>
          <input class="input" type="number" name="display_order" value="<?= count($metrics) + 1 ?>" min="1">
        </div>

        <div class="field">
          <label>Form Type</label>
          <select class="select" name="metric_type">
            <option value="generic" selected>Configurable Form (Dynamic Schema)</option>
            <option value="specialized">Specialized (Requires custom backend handler)</option>
          </select>
        </div>
      </div>

      <div style="background:#F8FAFC;padding:12px;border-radius:8px;border:1px solid #E2E8F0;display:flex;gap:18px;flex-wrap:wrap;">
        <label style="display:inline-flex;align-items:center;gap:6px;font-size:13px;font-weight:600;margin:0;cursor:pointer;">
          <input type="checkbox" name="status" value="1" checked> Active
        </label>
        <label style="display:inline-flex;align-items:center;gap:6px;font-size:13px;font-weight:600;margin:0;cursor:pointer;">
          <input type="checkbox" name="faculty_visible" value="1" checked> Visible to Faculty
        </label>
        <label style="display:inline-flex;align-items:center;gap:6px;font-size:13px;font-weight:600;margin:0;cursor:pointer;">
          <input type="checkbox" name="coordinator_visible" value="1" checked> Visible to Coordinator
        </label>
      </div>
    </div>

    <div class="modal-foot" style="padding:12px 20px;border-top:1px solid #E2E8F0;display:flex;justify-content:flex-end;gap:8px;">
      <button type="button" class="btn btn-outline btn-sm" onclick="this.closest('dialog').close()">Cancel</button>
      <button type="submit" class="btn btn-primary btn-sm"><?= icon('plus', 14) ?> Create Metric</button>
    </div>
  </form>
</dialog>

<!-- ========================================================================= -->
<!-- MODAL: EDIT METRIC PROPERTIES                                             -->
<!-- ========================================================================= -->
<dialog class="modal" id="editMetricModal" style="max-width:38rem;width:95vw;border-radius:12px;">
  <form method="post" action="<?= e(url('admin-metrics.php')) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="update">
    <input type="hidden" name="id" id="editMetricId">

    <div class="modal-head" style="padding:16px 20px;border-bottom:1px solid #E2E8F0;">
      <div>
        <h3 style="margin:0;font-size:16px;font-weight:700;color:var(--ink, #1E293B);">Edit Metric Configuration</h3>
        <div class="msub" style="font-size:12px;color:#64748B;margin-top:2px;">
          Internal Key: <code id="editMetricKey" style="background:#F1F5F9;color:#1E40AF;padding:2px 6px;border-radius:4px;"></code>
        </div>
      </div>
    </div>

    <div class="modal-body" style="padding:18px 20px;display:flex;flex-direction:column;gap:14px;">
      <div class="field">
        <label>Metric Display Name <span class="req">*</span></label>
        <input class="input" type="text" id="editName" name="name" required>
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
        <div class="field">
          <label>Category / Target Group <span class="req">*</span></label>
          <input class="input" list="catListEdit" id="editCategory" name="category" required>
          <datalist id="catListEdit">
            <?php foreach ($categories as $c): ?>
              <option value="<?= e($c) ?>">
            <?php endforeach; ?>
          </datalist>
        </div>

        <div class="field">
          <label>Display Order <span class="req">*</span></label>
          <input class="input" type="number" id="editOrder" name="display_order" min="1" required>
        </div>
      </div>

      <div class="field">
        <label>Description / Objectives</label>
        <textarea class="input" id="editDescription" name="description" rows="2"></textarea>
      </div>

      <div class="field">
        <label>Help Text / Instructions</label>
        <input class="input" id="editHelpText" name="help_text">
      </div>

      <div style="background:#F8FAFC;padding:12px;border-radius:8px;border:1px solid #E2E8F0;display:flex;gap:18px;flex-wrap:wrap;">
        <label style="display:inline-flex;align-items:center;gap:6px;font-size:13px;font-weight:600;margin:0;cursor:pointer;">
          <input type="checkbox" id="editStatus" name="status" value="1"> Active
        </label>
        <label style="display:inline-flex;align-items:center;gap:6px;font-size:13px;font-weight:600;margin:0;cursor:pointer;">
          <input type="checkbox" id="editFacultyVis" name="faculty_visible" value="1"> Visible to Faculty
        </label>
        <label style="display:inline-flex;align-items:center;gap:6px;font-size:13px;font-weight:600;margin:0;cursor:pointer;">
          <input type="checkbox" id="editCoordVis" name="coordinator_visible" value="1"> Visible to Coordinator
        </label>
      </div>
    </div>

    <div class="modal-foot" style="padding:12px 20px;border-top:1px solid #E2E8F0;display:flex;justify-content:space-between;align-items:center;">
      <button type="button" class="btn btn-ghost btn-sm" onclick="openDeleteFromEdit()" style="color:#DC2626;display:inline-flex;align-items:center;gap:4px;">
        <?= icon('trash-2', 14) ?> Delete Metric
      </button>
      <div style="display:flex;gap:8px;">
        <button type="button" class="btn btn-outline btn-sm" onclick="this.closest('dialog').close()">Cancel</button>
        <button type="submit" class="btn btn-primary btn-sm"><?= icon('save', 14) ?> Save Changes</button>
      </div>
    </div>
  </form>
</dialog>

<!-- ========================================================================= -->
<!-- ATTS-DYNAMIC-FORM-STUDIO — Comprehensive Form Builder Workspace Modal     -->
<!-- ========================================================================= -->
<dialog class="studio-modal" id="formStudioModal">
  <!-- Studio Top Navigation Header -->
  <div class="studio-head">
    <div class="studio-head-left">
      <span class="studio-head-brand"><?= icon('sliders', 13) ?> FORM STUDIO</span>
      <h2 class="studio-metric-title" id="studioTitle">Configure Form</h2>
      <code class="studio-key-pill" id="studioKey" title="Permanent identifier"></code>
      <span class="studio-ver-pill" id="studioVersion">v1</span>
      <span id="studioDraftNotice" class="badge" style="background:#FEF3C7;color:#92400E;font-size:11px;font-weight:700;display:none;">
        <?= icon('alert-circle', 11) ?> Draft Changes
      </span>
      <span id="studioProtectedNotice" class="badge" style="background:#EFF6FF;color:#1D4ED8;font-size:11px;font-weight:700;display:none;">
        <?= icon('shield', 11) ?> Core Calculation Logic Protected
      </span>
    </div>
    <div class="studio-head-actions">
      <button type="button" class="btn-studio-history" onclick="openVersionHistoryModal()" title="View Version Snapshots and Restore Previous Configurations">
        <?= icon('history', 13) ?> Versions
      </button>
      <button type="button" class="btn-studio-save-draft" onclick="saveFormDraft()" id="btnSaveDraft">
        <?= icon('save', 13) ?> Save Draft
      </button>
      <button type="button" class="btn-studio-publish" onclick="publishFormConfiguration()" id="btnPublish">
        <?= icon('check-circle', 14) ?> Publish Configuration
      </button>
      <button type="button" class="btn-studio-close" onclick="closeFormStudio()" aria-label="Close Studio">&times;</button>
    </div>
  </div>

  <!-- Studio 3-Column Body -->
  <div class="studio-workspace-grid">
    <!-- Col 1: Left Field Library -->
    <div class="studio-col-library">
      <div class="library-tab-strip">
        <button type="button" class="library-tab-btn active" id="tabBtnFieldTypes" onclick="switchLibraryTab('types')">Field Types (14)</button>
        <button type="button" class="library-tab-btn" id="tabBtnReusable" onclick="switchLibraryTab('reusable')">Reusable Library</button>
      </div>

      <!-- Tab 1: 14 Supported Input Types -->
      <div class="library-scroll-pane" id="paneFieldTypes">
        <div style="font-size:11px;font-weight:700;text-transform:uppercase;color:#64748B;letter-spacing:0.04em;margin-bottom:2px;">Standard Input Types</div>
        <button type="button" class="library-type-btn" onclick="addStudioField('text')">
          <div class="library-type-icon"><?= icon('type', 14) ?></div>
          <div class="library-type-info">
            <div class="library-type-title">Short Text</div>
            <div class="library-type-sub">Single-line text, titles, codes</div>
          </div>
        </button>
        <button type="button" class="library-type-btn" onclick="addStudioField('textarea')">
          <div class="library-type-icon"><?= icon('align-left', 14) ?></div>
          <div class="library-type-info">
            <div class="library-type-title">Long Text / Textarea</div>
            <div class="library-type-sub">Descriptions, multi-line details</div>
          </div>
        </button>
        <button type="button" class="library-type-btn" onclick="addStudioField('integer')">
          <div class="library-type-icon"><?= icon('hash', 14) ?></div>
          <div class="library-type-info">
            <div class="library-type-title">Integer Number</div>
            <div class="library-type-sub">Counts, participants, scores</div>
          </div>
        </button>
        <button type="button" class="library-type-btn" onclick="addStudioField('decimal')">
          <div class="library-type-icon"><?= icon('percent', 14) ?></div>
          <div class="library-type-info">
            <div class="library-type-title">Decimal / Amount</div>
            <div class="library-type-sub">CGPA, amounts in lakhs, %</div>
          </div>
        </button>
        <button type="button" class="library-type-btn" onclick="addStudioField('date')">
          <div class="library-type-icon"><?= icon('calendar', 14) ?></div>
          <div class="library-type-info">
            <div class="library-type-title">Date Picker</div>
            <div class="library-type-sub">Event dates, deadlines, orders</div>
          </div>
        </button>
        <button type="button" class="library-type-btn" onclick="addStudioField('select')">
          <div class="library-type-icon"><?= icon('list', 14) ?></div>
          <div class="library-type-info">
            <div class="library-type-title">Dropdown / Single Select</div>
            <div class="library-type-sub">Select one option from a list</div>
          </div>
        </button>
        <button type="button" class="library-type-btn" onclick="addStudioField('multiselect')">
          <div class="library-type-icon"><?= icon('check-square', 14) ?></div>
          <div class="library-type-info">
            <div class="library-type-title">Multi-Select</div>
            <div class="library-type-sub">Choose multiple items</div>
          </div>
        </button>
        <button type="button" class="library-type-btn" onclick="addStudioField('radio')">
          <div class="library-type-icon"><?= icon('disc', 14) ?></div>
          <div class="library-type-info">
            <div class="library-type-title">Radio Group</div>
            <div class="library-type-sub">Mutually exclusive options</div>
          </div>
        </button>
        <button type="button" class="library-type-btn" onclick="addStudioField('checkbox')">
          <div class="library-type-icon"><?= icon('check', 14) ?></div>
          <div class="library-type-info">
            <div class="library-type-title">Checkbox</div>
            <div class="library-type-sub">Boolean toggle / confirmation</div>
          </div>
        </button>
        <button type="button" class="library-type-btn" onclick="addStudioField('url')">
          <div class="library-type-icon"><?= icon('link', 14) ?></div>
          <div class="library-type-info">
            <div class="library-type-title">URL Link</div>
            <div class="library-type-sub">Web links, DOI, repos</div>
          </div>
        </button>
        <button type="button" class="library-type-btn" onclick="addStudioField('email')">
          <div class="library-type-icon"><?= icon('mail', 14) ?></div>
          <div class="library-type-info">
            <div class="library-type-title">Email Address</div>
            <div class="library-type-sub">Verified institutional emails</div>
          </div>
        </button>
        <button type="button" class="library-type-btn" onclick="addStudioField('file')">
          <div class="library-type-icon"><?= icon('file-text', 14) ?></div>
          <div class="library-type-info">
            <div class="library-type-title">File Upload</div>
            <div class="library-type-sub">Supporting document upload</div>
          </div>
        </button>
        <button type="button" class="library-type-btn" onclick="addStudioField('readonly')">
          <div class="library-type-icon"><?= icon('lock', 14) ?></div>
          <div class="library-type-info">
            <div class="library-type-title">Read-Only / Calculated</div>
            <div class="library-type-sub">Auto-computed or locked value</div>
          </div>
        </button>
        <button type="button" class="library-type-btn" onclick="addStudioField('specialized')">
          <div class="library-type-icon"><?= icon('shield', 14) ?></div>
          <div class="library-type-info">
            <div class="library-type-title">Specialized Component</div>
            <div class="library-type-sub">Institution-specific computation</div>
          </div>
        </button>
      </div>

      <!-- Tab 2: Reusable Field Library -->
      <div class="library-scroll-pane" id="paneReusableFields" style="display:none;">
        <div style="margin-bottom:8px;">
          <input type="text" class="input" id="reusableSearchInput" placeholder="Search reusable fields..." oninput="filterReusableList(this.value)" style="height:30px;font-size:11.5px;">
        </div>
        <div id="reusableCardsContainer" style="display:flex;flex-direction:column;gap:8px;">
          <!-- Dynamically populated from reusable_fields API -->
        </div>
        <div style="margin-top:10px;padding-top:10px;border-top:1px solid #E2E8F0;">
          <button type="button" class="btn btn-outline btn-sm" onclick="openNewReusableFieldModal()" style="width:100%;font-size:11.5px;justify-content:center;">
            <?= icon('plus', 13) ?> New Reusable Template
          </button>
        </div>
      </div>
    </div>

    <!-- Col 2: Center Form Canvas & Field Properties -->
    <div class="studio-col-canvas">
      <div class="canvas-toolbar">
        <div style="display:flex;align-items:center;gap:8px;">
          <button type="button" class="btn btn-sm btn-outline" onclick="addBlankStudioField()" style="gap:5px;font-size:12px;">
            <?= icon('plus', 13) ?> Add Blank Field
          </button>
          <button type="button" class="btn btn-sm btn-outline" onclick="openReuseFieldModal()" style="gap:5px;font-size:12px;color:#047857;border-color:#A7F3D0;background:#ECFDF5;">
            <?= icon('layers', 13) ?> Reuse Existing Field
          </button>
        </div>
        <div style="font-size:12px;color:#64748B;font-weight:600;">
          Fields in Form: <span id="canvasFieldCount" style="color:#FF4F01;font-weight:700;">0</span>
        </div>
      </div>

      <div class="canvas-scroll-pane" id="canvasScrollPane">
        <!-- Draggable / Reorderable Configured Fields -->
        <div id="studioFieldsList" style="display:flex;flex-direction:column;gap:10px;">
          <div class="studio-empty-canvas">
            <div class="studio-empty-icon"><?= icon('layout', 24) ?></div>
            <div class="studio-empty-title">Form Canvas is Ready</div>
            <div class="studio-empty-desc">
              Click any field type on the left or click <strong>+ Add Blank Field</strong> above to build this form. The Proof Attachment section below is enforced for all submissions.
            </div>
            <div style="display:flex;gap:8px;margin-top:6px;">
              <button type="button" class="btn btn-sm btn-primary" onclick="addBlankStudioField()" style="gap:5px;font-size:12px;">
                <?= icon('plus', 13) ?> Add Blank Field
              </button>
              <button type="button" class="btn btn-sm btn-outline" onclick="openReuseFieldModal()" style="gap:5px;font-size:12px;color:#047857;border-color:#A7F3D0;background:#ECFDF5;">
                <?= icon('layers', 13) ?> Browse Reusable Library
              </button>
            </div>
          </div>
        </div>

        <!-- Permanently Anchored Mandatory Proof / Attachment Section -->
        <div class="studio-attachment-box" id="studioAttachmentCard">
          <div class="attachment-box-head">
            <div style="display:flex;align-items:center;gap:8px;">
              <span class="attachment-tag-badge">
                <?= icon('paperclip', 12) ?> Mandatory Proof / Attachment
              </span>
              <span style="font-size:12px;color:#C2410C;font-weight:600;">
                Enforced for all 30 metrics
              </span>
            </div>
            <span class="badge" style="background:#FEF3C7;color:#92400E;font-size:10.5px;border:1px solid #FDE68A;">
              Cannot Be Removed
            </span>
          </div>
          <div style="font-size:12px;color:#5A6785;line-height:1.45;">
            Every institutional upload form in ATTS requires a supporting verification document. You can configure whether it is mandatory or optional, allowed formats, and size limits for this metric.
          </div>
          <div class="attachment-config-grid">
            <div class="att-field-col">
              <label class="att-field-label">Section Label <span style="color:#DC2626;">*</span></label>
              <input type="text" class="input att-field-input" id="attConfigLabel" value="Proof / Attachment" oninput="updateAttachmentProperty('label', this.value)">
            </div>
            <div class="att-field-col">
              <label class="att-field-label">Submission Requirement</label>
              <select class="select att-field-input" id="attConfigRequired" onchange="updateAttachmentProperty('required', this.value === '1')">
                <option value="1">Mandatory (Submission blocked without file)</option>
                <option value="0">Optional (Recommended)</option>
              </select>
            </div>
            <div class="att-field-col">
              <label class="att-field-label">Attachment Mode</label>
              <select class="select att-field-input" id="attConfigMultiple" onchange="updateAttachmentProperty('multiple', this.value === '1')">
                <option value="0">Single File (Default)</option>
                <option value="1">Multiple Files (Up to 5 files)</option>
              </select>
            </div>
            <div class="att-field-col">
              <label class="att-field-label">Max File Size (MB)</label>
              <input type="number" class="input att-field-input" id="attConfigMaxMb" value="2" min="1" max="25" oninput="updateAttachmentProperty('max_file_mb', Math.max(1, parseInt(this.value, 10)||1))" onchange="updateAttachmentProperty('max_file_mb', Math.max(1, parseInt(this.value, 10)||1))">
            </div>
            <div class="att-field-col" style="grid-column: span 2;">
              <label class="att-field-label">Accepted Formats</label>
              <div class="att-formats-row">
                <label class="att-format-pill">
                  <input type="checkbox" id="attFmtPdf" checked onchange="updateAttachmentFormats()">
                  <span>PDF (.pdf)</span>
                </label>
                <label class="att-format-pill">
                  <input type="checkbox" id="attFmtImg" onchange="updateAttachmentFormats()">
                  <span>Images (.png, .jpg)</span>
                </label>
                <label class="att-format-pill">
                  <input type="checkbox" id="attFmtDoc" onchange="updateAttachmentFormats()">
                  <span>Office (.docx)</span>
                </label>
              </div>
            </div>
            <div class="att-field-col" style="grid-column: span 2;">
              <label class="att-field-label">Help Instructions for Faculty</label>
              <input type="text" class="input att-field-input" id="attConfigHelp" value="PDF document strictly 2 MB or less." oninput="updateAttachmentProperty('help_text', this.value)" placeholder="Instructions shown under the upload dropzone...">
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Col 3: Right Live Form Preview -->
    <div class="studio-col-preview">
      <div class="preview-pane-head">
        <div style="display:flex;align-items:center;gap:6px;font-size:13px;font-weight:700;color:#1E293B;">
          <span style="color:#FF4F01;"><?= icon('eye', 15) ?></span> Live Form Preview
        </div>
        <div style="display:inline-flex;background:#E2E8F0;padding:2px;border-radius:6px;gap:2px;">
          <button type="button" class="btn-preview-role active" id="btnRoleFac" onclick="setPreviewRole('Faculty')" style="border:none;background:#FFFFFF;font-size:11px;font-weight:700;padding:3px 8px;border-radius:4px;cursor:pointer;">Faculty</button>
          <button type="button" class="btn-preview-role" id="btnRoleCoord" onclick="setPreviewRole('Coordinator')" style="border:none;background:transparent;color:#64748B;font-size:11px;font-weight:600;padding:3px 8px;border-radius:4px;cursor:pointer;">Coord</button>
        </div>
      </div>
      <div class="preview-scroll-pane">
        <div class="preview-form-card" id="studioLivePreviewContainer">
          <div style="margin-bottom:14px;padding-bottom:10px;border-bottom:1px solid #F1F5F9;">
            <div style="font-size:14px;font-weight:700;color:#131D3B;">Form Live Preview</div>
            <div style="font-size:11.5px;color:#64748B;margin-top:2px;">Viewing as: <strong style="color:#FF4F01;">Faculty</strong></div>
          </div>
          <div style="background:#F8FAFC;border:1px dashed #CBD5E1;border-radius:8px;padding:24px 16px;text-align:center;color:#64748B;margin-bottom:14px;">
            <div style="font-size:12.5px;font-weight:600;color:#334155;">No Input Fields Yet</div>
            <div style="font-size:11px;color:#64748B;margin-top:3px;">Fields added in the center canvas will appear here in real time.</div>
          </div>
          <div>
            <label style="display:block;font-size:12px;font-weight:700;color:#1E293B;margin-bottom:6px;">
              Proof / Attachment <span style="color:#DC2626;">*</span>
              <span style="font-weight:normal;color:#64748B;font-size:11px;"> — PDF strictly 2 MB or less</span>
            </label>
            <div class="preview-proof-dropzone">
              <span style="color:#FF4F01;"><?= icon('upload-cloud', 24) ?></span>
              <div style="font-size:12px;font-weight:600;color:#1E293B;">Click or Drag &amp; Drop Proof File Here</div>
              <div style="font-size:11px;color:#64748B;margin-top:2px;">PDF document strictly 2 MB or less.</div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</dialog>

<!-- ========================================================================= -->
<!-- MODAL: REUSE EXISTING FIELD (SELECTION DIALOG)                           -->
<!-- ========================================================================= -->
<dialog class="modal" id="reuseFieldModal" style="max-width:38rem;width:95vw;border-radius:12px;">
  <div class="modal-head" style="padding:16px 20px;border-bottom:1px solid #E2E8F0;">
    <div style="display:flex;align-items:center;justify-content:space-between;width:100%;">
      <div>
        <h3 style="margin:0;font-size:16px;font-weight:700;color:var(--ink, #1E293B);">Reuse Existing Field</h3>
        <div class="msub" style="font-size:12px;color:#64748B;margin-top:2px;">
          Select a pre-configured field template from your institutional reusable library.
        </div>
      </div>
      <button type="button" class="btn btn-sm btn-outline" onclick="openNewReusableFieldModal()" style="font-size:11.5px;color:#047857;border-color:#A7F3D0;background:#ECFDF5;gap:4px;">
        <?= icon('plus', 12) ?> New Template
      </button>
    </div>
  </div>
  <div style="padding:12px 20px 0 20px;">
    <input type="text" class="input" id="reuseModalSearchInput" placeholder="Search templates by title, key, or type..." oninput="filterReuseModalList(this.value)" style="height:34px;font-size:12px;width:100%;">
  </div>
  <div class="modal-body" style="padding:12px 20px 16px 20px;max-height:55vh;overflow-y:auto;" id="reuseModalBody">
    <!-- Populated by JS -->
  </div>
  <div class="modal-foot" style="padding:12px 20px;border-top:1px solid #E2E8F0;display:flex;justify-content:flex-end;">
    <button type="button" class="btn btn-outline btn-sm" onclick="this.closest('dialog').close()">Close</button>
  </div>
</dialog>

<!-- ========================================================================= -->
<!-- MODAL: VERSION HISTORY & RESTORE                                          -->
<!-- ========================================================================= -->
<dialog class="modal" id="historyModal" style="max-width:36rem;width:95vw;border-radius:12px;">
  <div class="modal-head" style="padding:16px 20px;border-bottom:1px solid #E2E8F0;">
    <div>
      <h3 style="margin:0;font-size:16px;font-weight:700;color:var(--ink, #1E293B);">Form Configuration Version History</h3>
      <div class="msub" style="font-size:12px;color:#64748B;margin-top:2px;" id="historyModalSub">
        Audit trail of published form configurations with one-click restore.
      </div>
    </div>
  </div>
  <div class="modal-body" style="padding:16px 20px;max-height:60vh;overflow-y:auto;" id="historyModalBody">
    <!-- Populated by JS -->
  </div>
  <div class="modal-foot" style="padding:12px 20px;border-top:1px solid #E2E8F0;display:flex;justify-content:flex-end;">
    <button type="button" class="btn btn-outline btn-sm" onclick="this.closest('dialog').close()">Close</button>
  </div>
</dialog>

<!-- ========================================================================= -->
<!-- MODAL: CREATE REUSABLE FIELD TEMPLATE                                     -->
<!-- ========================================================================= -->
<dialog class="modal" id="saveReusableModal" style="max-width:32rem;width:95vw;border-radius:12px;">
  <div class="modal-head" style="padding:16px 20px;border-bottom:1px solid #E2E8F0;">
    <div>
      <h3 style="margin:0;font-size:16px;font-weight:700;color:var(--ink, #1E293B);">Save to Reusable Field Library</h3>
      <div class="msub" style="font-size:12px;color:#64748B;margin-top:2px;">
        Make this field template available across all 30 metrics.
      </div>
    </div>
  </div>
  <div class="modal-body" style="padding:18px 20px;display:flex;flex-direction:column;gap:12px;">
    <div class="field">
      <label>Field Label <span class="req">*</span></label>
      <input type="text" class="input" id="rfLabel" placeholder="e.g. Industry Organization" required>
    </div>
    <div class="field">
      <label>Permanent Key <span class="req">*</span></label>
      <input type="text" class="input" id="rfKey" placeholder="e.g. organization" style="font-family:monospace;" required>
    </div>
    <div class="field">
      <label>Field Type</label>
      <select class="select" id="rfType">
        <option value="text">Short Text</option>
        <option value="textarea">Long Text / Textarea</option>
        <option value="integer">Integer Number</option>
        <option value="decimal">Decimal</option>
        <option value="date">Date</option>
        <option value="select">Dropdown</option>
        <option value="url">URL</option>
      </select>
    </div>
    <div class="field">
      <label>Placeholder / Help Text</label>
      <input type="text" class="input" id="rfPlaceholder" placeholder="Prompt displayed to faculty...">
    </div>
  </div>
  <div class="modal-foot" style="padding:12px 20px;border-top:1px solid #E2E8F0;display:flex;justify-content:flex-end;gap:8px;">
    <button type="button" class="btn btn-outline btn-sm" onclick="this.closest('dialog').close()">Cancel</button>
    <button type="button" class="btn btn-primary btn-sm" onclick="submitSaveReusableField()"><?= icon('plus', 13) ?> Save to Library</button>
  </div>
</dialog>

<!-- ========================================================================= -->
<!-- MODAL: FORM PREVIEW                                                       -->
<!-- ========================================================================= -->
<dialog class="modal" id="previewModal" style="max-width:44rem;width:95vw;border-radius:12px;">
  <div class="modal-head" style="padding:16px 20px;border-bottom:1px solid #E2E8F0;">
    <div>
      <h3 style="margin:0;font-size:16px;font-weight:700;color:var(--ink, #1E293B);" id="previewModalTitle">Metric Form Preview</h3>
      <div class="msub" style="font-size:12px;color:#64748B;margin-top:2px;" id="previewModalSub"></div>
    </div>
  </div>
  <div class="modal-body" style="padding:20px;max-height:70vh;overflow-y:auto;" id="previewModalBody">
    <!-- Populated by JS -->
  </div>
  <div class="modal-foot" style="padding:12px 20px;border-top:1px solid #E2E8F0;display:flex;justify-content:flex-end;">
    <button type="button" class="btn btn-outline btn-sm" onclick="this.closest('dialog').close()">Close Preview</button>
  </div>
</dialog>

<!-- ========================================================================= -->
<!-- MODAL: TOGGLE STATUS CONFIRMATION                                         -->
<!-- ========================================================================= -->
<dialog class="modal" id="toggleStatusModal" style="max-width:30rem;width:90vw;border-radius:12px;">
  <form method="post" action="<?= e(url('admin-metrics.php')) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="toggle_status">
    <input type="hidden" name="id" id="toggleMetricId">

    <div class="modal-head" style="padding:16px 20px;border-bottom:1px solid #E2E8F0;">
      <h3 style="margin:0;font-size:16px;font-weight:700;color:var(--ink, #1E293B);" id="toggleModalTitle">Confirm Status Change</h3>
    </div>
    <div class="modal-body" style="padding:18px 20px;">
      <p style="font-size:13.5px;color:#334155;margin:0;line-height:1.5;" id="toggleModalMessage"></p>
      <div style="margin-top:12px;background:#F0FDF4;border:1px solid #BBF7D0;padding:10px 14px;border-radius:8px;font-size:12px;color:#166534;">
        <strong>Data Safety Guarantee:</strong> All previously uploaded records, approvals, and historical reports remain 100% intact and preserved.
      </div>
    </div>
    <div class="modal-foot" style="padding:12px 20px;border-top:1px solid #E2E8F0;display:flex;justify-content:flex-end;gap:8px;">
      <button type="button" class="btn btn-outline btn-sm" onclick="this.closest('dialog').close()">Cancel</button>
      <button type="submit" class="btn btn-sm" id="toggleConfirmBtn">Confirm</button>
    </div>
  </form>
</dialog>

<!-- ========================================================================= -->
<!-- MODAL: DELETE METRIC CONFIRMATION                                         -->
<!-- ========================================================================= -->
<dialog class="modal" id="deleteModal" style="max-width:32rem;width:95vw;border-radius:12px;">
  <form method="post" action="<?= e(url('admin-metrics.php')) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="id" id="deleteMetricId">

    <div class="modal-head" style="padding:16px 20px;border-bottom:1px solid #FEE2E2;background:#FEF2F2;">
      <div style="display:flex;align-items:center;gap:10px;">
        <span style="color:#DC2626;"><?= icon('alert-triangle', 20) ?></span>
        <div>
          <h3 style="margin:0;font-size:16px;font-weight:700;color:#991B1B;">Delete Metric</h3>
          <div class="msub" style="font-size:12px;color:#B91C1C;margin-top:2px;">This action permanently deletes the metric.</div>
        </div>
      </div>
    </div>

    <div class="modal-body" style="padding:20px;">
      <p style="font-size:13.5px;color:#334155;margin:0 0 12px 0;line-height:1.5;">
        Are you sure you want to permanently delete <strong id="deleteMetricName" style="color:#0F172A;"></strong>?
      </p>
      <div style="background:#F8FAFC;border:1px solid #E2E8F0;border-radius:8px;padding:12px;font-size:12px;color:#64748B;line-height:1.6;">
        <div><strong>Metric Key:</strong> <code id="deleteMetricKey" style="background:#E2E8F0;padding:2px 6px;border-radius:4px;font-family:monospace;"></code></div>
        <div style="margin-top:6px;color:#B91C1C;">This metric will be removed from the institutional catalog and will immediately disappear from the <strong>Faculty Upload Data</strong> page.</div>
      </div>
    </div>

    <div class="modal-foot" style="padding:12px 20px;border-top:1px solid #E2E8F0;display:flex;justify-content:flex-end;gap:8px;">
      <button type="button" class="btn btn-outline btn-sm" onclick="this.closest('dialog').close()">Cancel</button>
      <button type="submit" class="btn btn-primary btn-sm" style="background:#DC2626;border-color:#DC2626;color:#FFFFFF;display:inline-flex;align-items:center;gap:5px;">
        <?= icon('trash-2', 14) ?> Yes, Delete Metric
      </button>
    </div>
  </form>
</dialog>

<!-- Order Action Toast Notification -->
<div id="orderToast" class="order-toast" role="status" aria-live="polite">
  <span id="orderToastIcon" style="display:inline-flex;align-items:center;"></span>
  <span id="orderToastMsg"></span>
</div>

<!-- Metric Management Javascript Engine -->
<script>
var currentBuilderFields = [];
var currentMetric = null;

function autoGenerateKey(name, targetId) {
  var target = document.getElementById(targetId);
  if (!target) return;
  var key = name.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '');
  target.value = key;
}

function openAddMetricModal() {
  document.getElementById('addMetricModal').showModal();
}

function openEditModal(metric) {
  document.getElementById('editMetricId').value = metric.id;
  document.getElementById('editMetricKey').textContent = metric.metric_key;
  document.getElementById('editName').value = metric.name;
  document.getElementById('editCategory').value = metric.category;
  document.getElementById('editOrder').value = metric.display_order;
  document.getElementById('editDescription').value = metric.description || '';
  document.getElementById('editHelpText').value = metric.help_text || '';
  document.getElementById('editStatus').checked = (metric.status == 1);
  document.getElementById('editFacultyVis').checked = (metric.faculty_visible == 1);
  document.getElementById('editCoordVis').checked = (metric.coordinator_visible == 1);

  document.getElementById('editMetricModal').showModal();
}

function openToggleStatusModal(id, name, isActive) {
  document.getElementById('toggleMetricId').value = id;
  var title = isActive ? 'Deactivate Metric?' : 'Activate Metric?';
  var msg = isActive
    ? 'Deactivating <strong>' + name + '</strong> will hide it from the Faculty Upload Data page for new submissions.'
    : 'Activating <strong>' + name + '</strong> will make it visible to Faculty for academic data submissions.';
  var btnText = isActive ? 'Deactivate Metric' : 'Activate Metric';
  var btnClass = isActive ? 'btn-danger' : 'btn-primary';

  document.getElementById('toggleModalTitle').textContent = title;
  document.getElementById('toggleModalMessage').innerHTML = msg;
  var btn = document.getElementById('toggleConfirmBtn');
  btn.textContent = btnText;
  btn.className = 'btn btn-sm ' + btnClass;

  document.getElementById('toggleStatusModal').showModal();
}

function openPreviewModal(metric) {
  document.getElementById('previewModalTitle').textContent = metric.name;
  document.getElementById('previewModalSub').textContent = metric.category + ' — Key: ' + metric.metric_key;
  var body = document.getElementById('previewModalBody');

  var html = '';
  if (metric.description) {
    html += '<div style="background:#F8FAFC;border:1px solid #E2E8F0;border-radius:8px;padding:12px;margin-bottom:16px;font-size:12.5px;color:#475569;">'
          + '<strong>Target Objective:</strong> ' + escapeHtml(metric.description) + '</div>';
  }

  if (metric.subcategories_parsed && Object.keys(metric.subcategories_parsed).length > 0) {
    html += '<div style="margin-bottom:14px;display:flex;align-items:center;gap:6px;flex-wrap:wrap;">'
          + '<span style="font-size:12px;font-weight:700;color:#64748B;">Subcategories:</span>';
    for (var k in metric.subcategories_parsed) {
      html += '<span class="badge" style="background:#EFF6FF;color:#1D4ED8;font-size:11.5px;padding:4px 10px;border-radius:20px;">'
            + escapeHtml(metric.subcategories_parsed[k]) + '</span>';
    }
    html += '</div>';
  }

  var fields = metric.form_config_parsed || [];
  if (fields.length === 0 && (metric.metric_type === 'specialized' || metric.metric_type === 'hybrid')) {
    html += '<div style="background:#EFF6FF;border:1px solid #BFDBFE;padding:18px;border-radius:10px;text-align:center;color:#1E40AF;">'
          + '<div style="font-weight:700;font-size:14px;">Specialized Institutional Component</div>'
          + '<div style="font-size:12px;margin-top:4px;">This metric uses built-in database models and calculations (e.g. University Pass %, Authorship verification, or Anna University Rank tables).</div>'
          + '</div>';
  } else if (fields.length === 0) {
    html += '<div style="padding:24px;text-align:center;color:#64748B;">No form fields configured yet for this custom metric. Click "Configure Form" to add fields.</div>';
  } else {
    html += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px 16px;">';
    fields.forEach(function(f) {
      var fullSpan = (f.type === 'textarea' || f.type === 'file') ? 'grid-column:span 2;' : '';
      var reqStar = f.required ? ' <span style="color:#EF4444;">*</span>' : '';
      var subText = f.help_text ? ' <span style="font-size:11px;color:#64748B;">— ' + escapeHtml(f.help_text) + '</span>' : '';

      html += '<div style="' + fullSpan + '"><label style="display:block;font-size:12.5px;font-weight:600;color:#1E293B;margin-bottom:4px;">'
            + escapeHtml(f.label) + reqStar + subText + '</label>';

      if (f.type === 'textarea') {
        html += '<textarea class="input" disabled placeholder="' + escapeHtml(f.placeholder || '') + '" rows="2" style="background:#F8FAFC;cursor:not-allowed;"></textarea>';
      } else if (f.type === 'select') {
        html += '<select class="select" disabled style="background:#F8FAFC;cursor:not-allowed;"><option>' + escapeHtml(f.placeholder || 'Select option...') + '</option></select>';
      } else if (f.type === 'file') {
        html += '<input class="input" type="file" disabled style="background:#F8FAFC;cursor:not-allowed;">';
      } else if (f.type === 'date') {
        html += '<input class="input" type="date" disabled style="background:#F8FAFC;cursor:not-allowed;">';
      } else {
        html += '<input class="input" type="' + (f.type === 'integer' || f.type === 'decimal' ? 'number' : 'text') + '" disabled placeholder="' + escapeHtml(f.placeholder || '') + '" style="background:#F8FAFC;cursor:not-allowed;">';
      }
      html += '</div>';
    });
    html += '</div>';
  }

  body.innerHTML = html;
  document.getElementById('previewModal').showModal();
}

function openFormBuilder(metric) {
  currentMetric = metric;
  openFormStudio(metric.id);
}

/* Client-side icon generator */
function studioIcon(name, size) {
  size = size || 14;
  var paths = {
    'layout': '<rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="21" x2="9" y2="9"/>',
    'plus': '<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>',
    'layers': '<polygon points="12 2 2 7 12 12 22 7 12 2"/><polyline points="2 17 12 22 22 17"/><polyline points="2 12 12 17 22 12"/>',
    'copy': '<rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>',
    'bookmark': '<path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z"/>',
    'upload-cloud': '<polyline points="16 16 12 12 8 16"/><line x1="12" y1="12" x2="12" y2="21"/><path d="M20.39 18.39A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.3"/><polyline points="16 16 12 12 8 16"/>',
    'save': '<path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/>',
    'check-circle': '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>',
    'check': '<polyline points="20 6 9 17 4 12"/>',
    'sliders': '<line x1="4" y1="21" x2="4" y2="14"/><line x1="4" y1="10" x2="4" y2="3"/><line x1="12" y1="21" x2="12" y2="12"/><line x1="12" y1="8" x2="12" y2="3"/><line x1="20" y1="21" x2="20" y2="16"/><line x1="20" y1="12" x2="20" y2="3"/><line x1="1" y1="14" x2="7" y2="14"/><line x1="9" y1="8" x2="15" y2="8"/><line x1="17" y1="16" x2="23" y2="16"/>',
    'paperclip': '<path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/>',
    'shield': '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
    'history': '<path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><polyline points="3 3 3 8 8 8"/><polyline points="12 7 12 12 15 15"/>',
    'trash-2': '<polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/>',
    'eye': '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>',
    'pencil': '<path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4z"/>',
    'calendar': '<rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>',
    'type': '<polyline points="4 7 4 4 20 4 20 7"/><line x1="9" y1="20" x2="15" y2="20"/><line x1="12" y1="4" x2="12" y2="20"/>',
    'align-left': '<line x1="17" y1="10" x2="3" y2="10"/><line x1="21" y1="6" x2="3" y2="6"/><line x1="21" y1="14" x2="3" y2="14"/><line x1="17" y1="18" x2="3" y2="18"/>',
    'hash': '<line x1="4" y1="9" x2="20" y2="9"/><line x1="4" y1="15" x2="20" y2="15"/><line x1="10" y1="3" x2="8" y2="21"/><line x1="16" y1="3" x2="14" y2="21"/>',
    'percent': '<line x1="19" y1="5" x2="5" y2="19"/><circle cx="6.5" cy="6.5" r="2.5"/><circle cx="17.5" cy="17.5" r="2.5"/>',
    'list': '<line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/>',
    'check-square': '<polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>',
    'disc': '<circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="3"/>',
    'link': '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
    'mail': '<path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/>',
    'file': '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/>',
    'lock': '<rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>'
  };
  var body = paths[name] || '<circle cx="12" cy="12" r="10"/>';
  return '<svg xmlns="http://www.w3.org/2000/svg" width="' + size + '" height="' + size + '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' + body + '</svg>';
}
var icon = studioIcon;

/* ==========================================================================
   ATTS-DYNAMIC-FORM-STUDIO — Complete Client-Side Engine
   ========================================================================== */
var studioState = {
  metricId: null,
  metric: null,
  fields: [],
  attachment: {
    enabled: true,
    label: 'Proof / Attachment',
    required: true,
    allowed_types: ['pdf'],
    max_file_mb: 2,
    help_text: 'PDF document strictly 2 MB or less.'
  },
  optionSets: [],
  reusableFields: [],
  history: [],
  previewRole: 'Faculty',
  isDirty: false,
  expandedFieldIdx: 0
};

var FIELD_TYPE_LABELS = {
  'text': 'Short Text',
  'textarea': 'Long Text (Textarea)',
  'integer': 'Integer Number',
  'decimal': 'Decimal / Amount',
  'date': 'Date Picker',
  'select': 'Dropdown / Single Select',
  'multiselect': 'Multi-Select',
  'radio': 'Radio Group',
  'checkbox': 'Checkbox',
  'url': 'URL Link',
  'email': 'Email Address',
  'file': 'File Upload',
  'readonly': 'Read-Only / Calculated',
  'specialized': 'Specialized Component'
};

function openFormStudio(metricId) {
  studioState.metricId = metricId;
  studioState.isDirty = false;
  studioState.expandedFieldIdx = 0;
  studioState.fields = [];
  studioState.attachment = {
    enabled: true,
    label: 'Proof / Attachment',
    required: true,
    allowed_types: ['pdf'],
    max_file_mb: 2,
    help_text: 'PDF document strictly 2 MB or less.'
  };

  var modal = document.getElementById('formStudioModal');
  modal.showModal();

  document.getElementById('studioTitle').textContent = 'Loading metric...';
  document.getElementById('studioKey').textContent = '...';
  document.getElementById('studioFieldsList').innerHTML = '<div style="padding:40px;text-align:center;color:#64748B;"><div class="spinner" style="margin:0 auto 10px auto;"></div>Loading Form Studio workspace...</div>';

  syncAttachmentUI();
  renderLivePreview();

  var fetchUrl = (window.location.pathname.replace(/\/+$/, '') || 'admin-metrics.php') + '?ajax=1&action=get_form_config&id=' + encodeURIComponent(metricId);

  fetch(fetchUrl, {
    headers: { 'X-Requested-With': 'XMLHttpRequest' }
  })
  .then(function(res) { return res.json(); })
  .then(function(data) {
    if (!data || !data.success) {
      alert(data && data.message ? data.message : 'Failed to load form configuration.');
      modal.close();
      return;
    }

    var cfg = data.config || {};
    studioState.metric = cfg.metric || { id: metricId, name: 'Metric #' + metricId, metric_key: 'metric_' + metricId, form_version: 1, metric_type: 'generic' };
    studioState.fields = Array.isArray(cfg.fields) ? JSON.parse(JSON.stringify(cfg.fields)) : [];
    studioState.attachment = cfg.attachment || studioState.attachment;
    studioState.optionSets = data.option_sets || [];
    studioState.reusableFields = data.reusable_fields || [];
    studioState.history = data.history || [];

    // Header info
    document.getElementById('studioTitle').textContent = studioState.metric.name || 'Configure Form';
    document.getElementById('studioKey').textContent = studioState.metric.metric_key || '';
    document.getElementById('studioVersion').textContent = 'v' + (studioState.metric.form_version || 1);

    var hasDraft = (studioState.metric.form_config_draft && studioState.metric.form_config_draft !== studioState.metric.form_config);
    document.getElementById('studioDraftNotice').style.display = hasDraft ? 'inline-flex' : 'none';

    var isSpecialized = (studioState.metric.metric_type === 'specialized' || studioState.metric.metric_type === 'hybrid');
    document.getElementById('studioProtectedNotice').style.display = isSpecialized ? 'inline-flex' : 'none';

    syncAttachmentUI();
    renderStudioFields();
    renderReusableLibrary();
    renderLivePreview();
  })
  .catch(function(err) {
    console.error('Form Studio load error:', err);
    alert('Failed to connect to Form Studio backend.');
    modal.close();
  });
}

function closeFormStudio() {
  if (studioState.isDirty) {
    if (!confirm('You have unsaved changes in this form studio session. Are you sure you want to close and discard your unsaved changes?')) {
      return;
    }
  }
  studioState.isDirty = false;
  document.getElementById('formStudioModal').close();
}

function switchLibraryTab(tab) {
  var btnTypes = document.getElementById('tabBtnFieldTypes');
  var btnRe = document.getElementById('tabBtnReusable');
  var paneTypes = document.getElementById('paneFieldTypes');
  var paneRe = document.getElementById('paneReusableFields');

  if (tab === 'types') {
    btnTypes.classList.add('active');
    btnRe.classList.remove('active');
    paneTypes.style.display = 'flex';
    paneRe.style.display = 'none';
  } else {
    btnRe.classList.add('active');
    btnTypes.classList.remove('active');
    paneRe.style.display = 'flex';
    paneTypes.style.display = 'none';
  }
}

function renderReusableLibrary(filter) {
  var container = document.getElementById('reusableCardsContainer');
  if (!container) return;

  var q = (filter || '').toLowerCase().trim();
  var html = '';

  studioState.reusableFields.forEach(function(rf) {
    if (q && rf.label.toLowerCase().indexOf(q) === -1 && rf.field_key.toLowerCase().indexOf(q) === -1) {
      return;
    }

    html += '<div style="background:#F8FAFC;border:1px solid #E2E8F0;border-radius:8px;padding:9px 12px;display:flex;align-items:center;justify-content:space-between;gap:8px;">'
          + '  <div style="min-width:0;flex:1;">'
          + '    <div style="font-size:12px;font-weight:700;color:#1E293B;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' + escapeHtml(rf.label) + '</div>'
          + '    <div style="display:flex;align-items:center;gap:6px;margin-top:2px;">'
          + '      <code style="font-size:10px;background:#E2E8F0;color:#475569;padding:1px 4px;border-radius:3px;">' + escapeHtml(rf.field_key) + '</code>'
          + '      <span style="font-size:10px;color:#64748B;">' + (FIELD_TYPE_LABELS[rf.type] || rf.type) + '</span>'
          + '    </div>'
          + '  </div>'
          + '  <button type="button" class="btn btn-sm btn-outline" onclick="insertReusableField(\'' + escapeHtml(rf.field_key) + '\')" style="padding:2px 8px;font-size:11px;font-weight:600;flex-shrink:0;color:#047857;border-color:#A7F3D0;background:#FFFFFF;">'
          + '    + Insert'
          + '  </button>'
          + '</div>';
  });

  if (html === '') {
    html = '<div style="padding:16px;text-align:center;color:#64748B;font-size:11.5px;">No matching reusable fields.</div>';
  }

  container.innerHTML = html;
}

function filterReusableList(val) {
  renderReusableLibrary(val);
}

function openReuseFieldModal() {
  var modal = document.getElementById('reuseFieldModal');
  var searchInput = document.getElementById('reuseModalSearchInput');
  if (searchInput) searchInput.value = '';
  renderReuseModalList('');
  modal.showModal();
}

function filterReuseModalList(query) {
  renderReuseModalList(query);
}

function renderReuseModalList(filter) {
  var body = document.getElementById('reuseModalBody');
  if (!body) return;

  var q = (filter || '').toLowerCase().trim();
  var list = studioState.reusableFields || [];

  var matches = list.filter(function(rf) {
    if (!q) return true;
    return (rf.label && rf.label.toLowerCase().indexOf(q) !== -1) ||
           (rf.field_key && rf.field_key.toLowerCase().indexOf(q) !== -1) ||
           (rf.type && rf.type.toLowerCase().indexOf(q) !== -1);
  });

  if (matches.length === 0) {
    body.innerHTML = '<div style="padding:28px 16px;text-align:center;color:#64748B;">'
                   + '  <div style="font-size:28px;margin-bottom:8px;">📦</div>'
                   + '  <div style="font-weight:700;font-size:13.5px;color:#334155;">No Reusable Fields Found</div>'
                   + '  <div style="font-size:12px;color:#64748B;margin-top:4px;max-width:320px;margin-left:auto;margin-right:auto;">'
                   + (q ? 'No templates match your search "' + escapeHtml(q) + '".' : 'No reusable field templates have been created yet.')
                   + '  </div>'
                   + '  <div style="margin-top:14px;">'
                   + '    <button type="button" class="btn btn-primary btn-sm" onclick="openNewReusableFieldModal()" style="font-size:12px;">'
                   + '      + Create First Reusable Template'
                   + '    </button>'
                   + '  </div>'
                   + '</div>';
    return;
  }

  var html = '<div style="display:flex;flex-direction:column;gap:10px;">';
  matches.forEach(function(rf) {
    var typeLabel = FIELD_TYPE_LABELS[rf.type] || rf.type;
    var opts = Array.isArray(rf.options_parsed) ? rf.options_parsed : [];
    var optsBadge = opts.length > 0 ? ' <span class="badge" style="background:#FEF3C7;color:#92400E;font-size:10px;">' + opts.length + ' options</span>' : '';
    var optSetBadge = rf.option_set_key ? ' <span class="badge" style="background:#EFF6FF;color:#1D4ED8;font-size:10px;">Shared Set: ' + escapeHtml(rf.option_set_key) + '</span>' : '';

    html += '<div style="background:#F8FAFC;border:1px solid #E2E8F0;border-radius:10px;padding:12px 14px;display:flex;align-items:center;justify-content:space-between;gap:12px;transition:border-color .15s ease;">'
          + '  <div style="min-width:0;flex:1;">'
          + '    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">'
          + '      <strong style="font-size:13px;color:#1E293B;">' + escapeHtml(rf.label) + '</strong>'
          + '      <code style="font-family:monospace;font-size:10.5px;background:#E2E8F0;color:#3B82F6;padding:1px 5px;border-radius:4px;">' + escapeHtml(rf.field_key) + '</code>'
          + '      <span class="badge" style="background:#E2E8F0;color:#475569;font-size:10px;">' + escapeHtml(typeLabel) + '</span>'
          + optsBadge + optSetBadge
          + '    </div>'
          + (rf.help_text || rf.placeholder ? '    <div style="font-size:11.5px;color:#64748B;margin-top:3px;">' + escapeHtml(rf.help_text || rf.placeholder) + '</div>' : '')
          + '  </div>'
          + '  <button type="button" class="btn btn-sm btn-primary" onclick="insertReusableFieldFromModal(\'' + escapeHtml(rf.field_key) + '\')" style="flex-shrink:0;padding:4px 12px;font-size:11.5px;font-weight:700;">'
          + '    + Insert Field'
          + '  </button>'
          + '</div>';
  });
  html += '</div>';

  body.innerHTML = html;
}

function insertReusableFieldFromModal(rfKey) {
  insertReusableField(rfKey);
  var modal = document.getElementById('reuseFieldModal');
  if (modal && modal.open) modal.close();
}

function addBlankStudioField() {
  var count = studioState.fields.length + 1;
  var key = 'field_' + count + '_' + Math.floor(Math.random() * 899 + 100);
  while (studioState.fields.some(function(f) { return f.key === key; })) {
    key = 'field_' + count + '_' + Math.floor(Math.random() * 899 + 100);
  }

  var newF = {
    order: count,
    key: key,
    label: 'New Field ' + count,
    type: 'text',
    placeholder: '',
    help_text: '',
    required: false,
    default_val: '',
    min: null,
    max: null,
    min_length: null,
    max_length: null,
    options: [],
    option_set_key: '',
    options_mode: 'custom',
    faculty_editable: true,
    coordinator_editable: true,
    include_in_reports: true,
    protected: false
  };

  studioState.fields.push(newF);
  studioState.expandedFieldIdx = studioState.fields.length - 1;
  markDirty();
  renderStudioFields();
  renderLivePreview();

  setTimeout(function() {
    var pane = document.getElementById('canvasScrollPane');
    if (pane) pane.scrollTop = pane.scrollHeight;
    var card = document.querySelector('.field-card.active-card');
    if (card) {
      var input = card.querySelector('input[type="text"]');
      if (input) {
        input.focus();
        input.select();
      }
    }
  }, 60);
}

function addStudioField(type) {
  var count = studioState.fields.length + 1;
  var key = 'field_' + count + '_' + Math.floor(Math.random() * 899 + 100);
  while (studioState.fields.some(function(f) { return f.key === key; })) {
    key = 'field_' + count + '_' + Math.floor(Math.random() * 899 + 100);
  }
  var label = (FIELD_TYPE_LABELS[type] || 'Field') + ' ' + count;

  var newF = {
    order: count,
    key: key,
    label: label,
    type: type,
    placeholder: '',
    help_text: '',
    required: false,
    default_val: '',
    min: null,
    max: null,
    min_length: null,
    max_length: null,
    options: type === 'select' || type === 'radio' || type === 'multiselect' ? ['Option 1', 'Option 2'] : [],
    option_set_key: '',
    options_mode: 'custom',
    faculty_editable: true,
    coordinator_editable: true,
    include_in_reports: true,
    protected: false
  };

  studioState.fields.push(newF);
  studioState.expandedFieldIdx = studioState.fields.length - 1;
  markDirty();
  renderStudioFields();
  renderLivePreview();

  // Scroll to new field
  setTimeout(function() {
    var pane = document.getElementById('canvasScrollPane');
    if (pane) pane.scrollTop = pane.scrollHeight;
  }, 50);
}

function insertReusableField(rfKey) {
  var rf = studioState.reusableFields.find(function(r) { return r.field_key === rfKey; });
  if (!rf) return;

  // Check if key already exists, append suffix if so
  var key = rf.field_key;
  var count = 1;
  while (studioState.fields.some(function(f) { return f.key === key; })) {
    key = rf.field_key + '_' + count++;
  }

  var newF = {
    order: studioState.fields.length + 1,
    key: key,
    label: rf.label,
    type: rf.type,
    placeholder: rf.placeholder || '',
    help_text: rf.help_text || '',
    required: !!rf.required,
    default_val: '',
    min: rf.min_val || null,
    max: rf.max_val || null,
    min_length: null,
    max_length: null,
    options: Array.isArray(rf.options_parsed) ? rf.options_parsed : [],
    option_set_key: rf.option_set_key || '',
    options_mode: rf.option_set_key ? 'shared' : 'custom',
    faculty_editable: true,
    coordinator_editable: true,
    include_in_reports: true,
    protected: false
  };

  studioState.fields.push(newF);
  studioState.expandedFieldIdx = studioState.fields.length - 1;
  markDirty();
  renderStudioFields();
  renderLivePreview();
}

function duplicateStudioField(idx) {
  var f = studioState.fields[idx];
  if (!f) return;

  var copy = JSON.parse(JSON.stringify(f));
  copy.key = copy.key + '_copy_' + Math.floor(Math.random() * 89 + 10);
  copy.label = copy.label + ' (Copy)';
  copy.protected = false;
  copy.protected_reason = '';

  studioState.fields.splice(idx + 1, 0, copy);
  studioState.expandedFieldIdx = idx + 1;
  markDirty();
  renderStudioFields();
  renderLivePreview();
}

function deleteStudioField(idx) {
  var f = studioState.fields[idx];
  if (!f) return;

  if (f.protected) {
    alert('This is a protected specialized field required by institutional calculations (' + (f.protected_reason || 'Backend Calculation') + ') and cannot be removed.');
    return;
  }

  if (studioState.fields.length <= 1) {
    alert('A metric form must contain at least one field.');
    return;
  }

  if (!confirm('Are you sure you want to delete the field "' + f.label + '"?')) {
    return;
  }

  studioState.fields.splice(idx, 1);
  studioState.expandedFieldIdx = Math.max(0, idx - 1);
  markDirty();
  renderStudioFields();
  renderLivePreview();
}

function moveStudioField(idx, delta) {
  var newIdx = idx + delta;
  if (newIdx < 0 || newIdx >= studioState.fields.length) return;

  var temp = studioState.fields[idx];
  studioState.fields[idx] = studioState.fields[newIdx];
  studioState.fields[newIdx] = temp;
  studioState.expandedFieldIdx = newIdx;

  markDirty();
  renderStudioFields();
  renderLivePreview();
}

function toggleExpandField(idx) {
  studioState.expandedFieldIdx = (studioState.expandedFieldIdx === idx) ? -1 : idx;
  renderStudioFields();
}

function updateFieldProp(idx, prop, val) {
  if (!studioState.fields[idx]) return;
  studioState.fields[idx][prop] = val;
  markDirty();
  renderLivePreview();

  // If label changed, update card header text directly
  if (prop === 'label') {
    var titleEl = document.getElementById('fieldCardTitle_' + idx);
    if (titleEl) titleEl.textContent = val || 'Untitled Field';
  }
}

function markDirty() {
  studioState.isDirty = true;
  var el = document.getElementById('studioDraftNotice');
  if (el) el.style.display = 'inline-flex';
}

function renderStudioFields() {
  var container = document.getElementById('studioFieldsList');
  document.getElementById('canvasFieldCount').textContent = studioState.fields.length;

  if (!studioState.fields || studioState.fields.length === 0) {
    container.innerHTML = '<div class="studio-empty-canvas">'
                        + '  <div class="studio-empty-icon">' + icon('layout', 24) + '</div>'
                        + '  <div class="studio-empty-title">Form Canvas is Ready</div>'
                        + '  <div class="studio-empty-desc">Click any field type on the left or click <strong>+ Add Blank Field</strong> above to build this form. The Proof Attachment section below is enforced for all submissions.</div>'
                        + '  <div style="display:flex;gap:8px;margin-top:6px;">'
                        + '    <button type="button" class="btn btn-sm btn-primary" onclick="addStudioField(\'text\')" style="gap:5px;font-size:12px;">' + icon('plus', 13) + ' Add Short Text Field</button>'
                        + '    <button type="button" class="btn btn-sm btn-outline" onclick="switchLibraryTab(\'reusable\')" style="gap:5px;font-size:12px;color:#047857;border-color:#A7F3D0;background:#ECFDF5;">' + icon('layers', 13) + ' Browse Reusable Library</button>'
                        + '  </div>'
                        + '</div>';
    return;
  }

  var html = '';

  studioState.fields.forEach(function(f, idx) {
    var isExpanded = (studioState.expandedFieldIdx === idx);
    var isProt = !!f.protected;
    var cardClass = 'field-card' + (isProt ? ' protected-card' : '') + (isExpanded ? ' active-card' : '');

    html += '<div class="' + cardClass + '">'
          + '  <div class="field-card-head" onclick="toggleExpandField(' + idx + ')">'
          + '    <div class="field-card-left">'
          + '      <span class="field-card-order">#' + (idx + 1) + '</span>'
          + '      <div class="field-card-title" id="fieldCardTitle_' + idx + '">' + escapeHtml(f.label || 'Untitled Field') + '</div>'
          + '      <code style="font-family:monospace;font-size:10.5px;background:#F1F5F9;color:#3B82F6;padding:1px 5px;border-radius:4px;">' + escapeHtml(f.key) + '</code>'
          + '      <span class="badge" style="background:#F1F5F9;color:#475569;font-size:10px;">' + (FIELD_TYPE_LABELS[f.type] || f.type) + '</span>'
          + (f.required ? '      <span class="badge" style="background:#FEE2E2;color:#DC2626;font-size:10px;">Required</span>' : '')
          + (isProt ? '      <span class="badge" style="background:#DBEAFE;color:#1D4ED8;font-size:10px;" title="' + escapeHtml(f.protected_reason || 'Specialized logic') + '">Protected Logic</span>' : '')
          + '    </div>'
          + '    <div class="field-card-actions" onclick="event.stopPropagation()">'
          + '      <button type="button" class="btn-card-tool" onclick="moveStudioField(' + idx + ', -1)" ' + (idx === 0 ? 'disabled' : '') + ' title="Move Up">&uarr;</button>'
          + '      <button type="button" class="btn-card-tool" onclick="moveStudioField(' + idx + ', 1)" ' + (idx === studioState.fields.length - 1 ? 'disabled' : '') + ' title="Move Down">&darr;</button>'
          + '      <button type="button" class="btn-card-tool" onclick="duplicateStudioField(' + idx + ')" title="Duplicate Field">' + icon('copy', 12) + '</button>'
          + '      <button type="button" class="btn-card-tool" onclick="openSaveToReusableModal(' + idx + ')" title="Save to Reusable Library">' + icon('bookmark', 12) + '</button>'
          + '      <button type="button" class="btn-card-tool btn-card-delete" onclick="deleteStudioField(' + idx + ')" ' + (isProt ? 'disabled title="Protected field cannot be removed"' : 'title="Delete Field"') + '>&times;</button>'
          + '      <button type="button" class="btn-card-tool" onclick="toggleExpandField(' + idx + ')" title="' + (isExpanded ? 'Collapse' : 'Expand') + '">' + (isExpanded ? '&minus;' : '&plus;') + '</button>'
          + '    </div>'
          + '  </div>';

    if (isExpanded) {
      html += '<div class="field-card-body">'
            + '  <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px 14px;">'
            + '    <div>'
            + '      <label style="font-size:11px;font-weight:700;color:#334155;">Field Label <span class="req">*</span></label>'
            + '      <input type="text" class="input" value="' + escapeHtml(f.label) + '" oninput="updateFieldProp(' + idx + ', \'label\', this.value)" style="height:30px;font-size:12px;">'
            + '    </div>'
            + '    <div>'
            + '      <label style="font-size:11px;font-weight:700;color:#334155;">Stable Field Key <span class="req">*</span> <span style="font-size:10px;color:#64748B;">(Database Identifier)</span></label>'
            + '      <input type="text" class="input" value="' + escapeHtml(f.key) + '" ' + (isProt ? 'readonly style="height:30px;font-size:11.5px;font-family:monospace;background:#F8FAFC;cursor:not-allowed;"' : 'oninput="updateFieldProp(' + idx + ', \'key\', this.value.toLowerCase().replace(/[^a-z0-9_]+/g, \'_\'))" style="height:30px;font-size:11.5px;font-family:monospace;"') + '>'
            + '    </div>'
            + '  </div>'
            + '  <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px 14px;">'
            + '    <div>'
            + '      <label style="font-size:11px;font-weight:700;color:#334155;">Field Type</label>'
            + '      <select class="select" ' + (isProt ? 'disabled style="height:30px;font-size:12px;background:#F8FAFC;"' : 'onchange="updateFieldProp(' + idx + ', \'type\', this.value); renderStudioFields();" style="height:30px;font-size:12px;"') + '>';
      for (var tKey in FIELD_TYPE_LABELS) {
        html += '<option value="' + tKey + '"' + (f.type === tKey ? ' selected' : '') + '>' + FIELD_TYPE_LABELS[tKey] + '</option>';
      }
      html += '      </select>'
            + '    </div>'
            + '    <div>'
            + '      <label style="font-size:11px;font-weight:700;color:#334155;">Placeholder</label>'
            + '      <input type="text" class="input" value="' + escapeHtml(f.placeholder || '') + '" oninput="updateFieldProp(' + idx + ', \'placeholder\', this.value)" style="height:30px;font-size:12px;" placeholder="Prompt displayed inside input...">'
            + '    </div>'
            + '  </div>'
            + '  <div>'
            + '    <label style="font-size:11px;font-weight:700;color:#334155;">Help Text / Guidance</label>'
            + '    <input type="text" class="input" value="' + escapeHtml(f.help_text || '') + '" oninput="updateFieldProp(' + idx + ', \'help_text\', this.value)" style="height:30px;font-size:12px;" placeholder="Instructions displayed below field...">'
            + '  </div>'
            + '  <div style="background:#F8FAFC;border:1px solid #E2E8F0;padding:10px 12px;border-radius:8px;display:flex;gap:16px;flex-wrap:wrap;font-size:12px;">'
            + '    <label style="display:inline-flex;align-items:center;gap:5px;cursor:pointer;margin:0;">'
            + '      <input type="checkbox" ' + (f.required ? 'checked' : '') + ' onchange="updateFieldProp(' + idx + ', \'required\', this.checked)"> Required Field'
            + '    </label>'
            + '    <label style="display:inline-flex;align-items:center;gap:5px;cursor:pointer;margin:0;">'
            + '      <input type="checkbox" ' + (f.faculty_editable !== false ? 'checked' : '') + ' onchange="updateFieldProp(' + idx + ', \'faculty_editable\', this.checked)"> Faculty Editable'
            + '    </label>'
            + '    <label style="display:inline-flex;align-items:center;gap:5px;cursor:pointer;margin:0;">'
            + '      <input type="checkbox" ' + (f.coordinator_editable !== false ? 'checked' : '') + ' onchange="updateFieldProp(' + idx + ', \'coordinator_editable\', this.checked)"> Coordinator Editable'
            + '    </label>'
            + '    <label style="display:inline-flex;align-items:center;gap:5px;cursor:pointer;margin:0;">'
            + '      <input type="checkbox" ' + (f.include_in_reports !== false ? 'checked' : '') + ' onchange="updateFieldProp(' + idx + ', \'include_in_reports\', this.checked)"> Include in Reports'
            + '    </label>'
            + '  </div>';

      // Type-specific editors (Options for dropdown, min/max for numbers)
      if (f.type === 'select' || f.type === 'multiselect' || f.type === 'radio') {
        html += renderOptionsEditorHtml(idx, f);
      } else if (f.type === 'integer' || f.type === 'decimal') {
        html += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:10px 14px;">'
              + '  <div>'
              + '    <label style="font-size:11px;font-weight:700;color:#334155;">Minimum Value</label>'
              + '    <input type="number" class="input" value="' + (f.min !== null ? f.min : '') + '" oninput="updateFieldProp(' + idx + ', \'min\', this.value !== \'\' ? parseFloat(this.value) : null)" style="height:30px;font-size:12px;">'
              + '  </div>'
              + '  <div>'
              + '    <label style="font-size:11px;font-weight:700;color:#334155;">Maximum Value</label>'
              + '    <input type="number" class="input" value="' + (f.max !== null ? f.max : '') + '" oninput="updateFieldProp(' + idx + ', \'max\', this.value !== \'\' ? parseFloat(this.value) : null)" style="height:30px;font-size:12px;">'
              + '  </div>'
              + '</div>';
      }

      html += '</div>';
    }

    html += '</div>';
  });

  container.innerHTML = html;
}

function renderOptionsEditorHtml(idx, f) {
  var mode = f.options_mode || 'custom';
  var html = '<div style="background:#FFFBF7;border:1px solid #FFEDD5;border-radius:8px;padding:12px;margin-top:6px;">'
           + '  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;flex-wrap:wrap;gap:8px;">'
           + '    <strong style="font-size:12px;color:#9A3412;">Dropdown / Choice Options Configuration</strong>'
           + '    <div style="display:inline-flex;gap:4px;">'
           + '      <button type="button" class="btn btn-sm ' + (mode === 'custom' ? 'btn-primary' : 'btn-outline') + '" onclick="setFieldOptionsMode(' + idx + ', \'custom\')" style="font-size:11px;padding:2px 8px;">Metric-Specific</button>'
           + '      <button type="button" class="btn btn-sm ' + (mode === 'shared' ? 'btn-primary' : 'btn-outline') + '" onclick="setFieldOptionsMode(' + idx + ', \'shared\')" style="font-size:11px;padding:2px 8px;">Shared Option Set</button>'
           + '    </div>'
           + '  </div>';

  if (mode === 'shared') {
    html += '  <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px;">'
          + '    <label style="font-size:11.5px;font-weight:600;color:#334155;">Select Shared Option Set:</label>'
          + '    <select class="select" onchange="selectSharedOptionSet(' + idx + ', this.value)" style="height:30px;font-size:12px;flex:1;">'
          + '      <option value="">-- Choose Option Set --</option>';
    studioState.optionSets.forEach(function(set) {
      html += '    <option value="' + escapeHtml(set.set_key) + '"' + (f.option_set_key === set.set_key ? ' selected' : '') + '>'
            + escapeHtml(set.name) + ' (' + (set.options_parsed ? set.options_parsed.length : 0) + ' options)'
            + '</option>';
    });
    html += '    </select>'
          + '    <button type="button" class="btn btn-outline btn-sm" onclick="copySharedToCustom(' + idx + ')" title="Copy into this metric as independent options" style="font-size:11px;white-space:nowrap;">'
          + '      Copy as Independent'
          + '    </button>'
          + '  </div>';
  } else {
    html += '  <div style="font-size:11px;color:#64748B;margin-bottom:8px;">Define display labels and permanent internal database values:</div>'
          + '  <div style="display:flex;flex-direction:column;gap:6px;" id="optListContainer_' + idx + '">';

    var options = Array.isArray(f.options) ? f.options : [];
    options.forEach(function(opt, optIdx) {
      var val = (typeof opt === 'object' && opt !== null) ? (opt.value || '') : opt;
      var lbl = (typeof opt === 'object' && opt !== null) ? (opt.label || val) : opt;

      html += '    <div style="display:grid;grid-template-columns:1fr 1fr auto;gap:6px;align-items:center;">'
            + '      <input type="text" class="input" value="' + escapeHtml(lbl) + '" oninput="updateOptionItem(' + idx + ', ' + optIdx + ', \'label\', this.value)" placeholder="Display Label (e.g. Apr/May)" style="height:28px;font-size:11.5px;">'
            + '      <input type="text" class="input" value="' + escapeHtml(val) + '" oninput="updateOptionItem(' + idx + ', ' + optIdx + ', \'value\', this.value)" placeholder="Internal Value (e.g. APR_MAY)" style="height:28px;font-size:11px;font-family:monospace;">'
            + '      <button type="button" class="btn btn-ghost btn-sm" onclick="removeOptionItem(' + idx + ', ' + optIdx + ')" style="color:#DC2626;padding:0 6px;height:28px;">&times;</button>'
            + '    </div>';
    });

    html += '  </div>'
          + '  <div style="margin-top:8px;">'
          + '    <button type="button" class="btn btn-outline btn-sm" onclick="addOptionItem(' + idx + ')" style="font-size:11px;gap:4px;">'
          + '      + Add Option'
          + '    </button>'
          + '  </div>';
  }

  html += '</div>';
  return html;
}

function setFieldOptionsMode(idx, mode) {
  if (!studioState.fields[idx]) return;
  studioState.fields[idx].options_mode = mode;
  if (mode === 'shared' && !studioState.fields[idx].option_set_key && studioState.optionSets.length > 0) {
    studioState.fields[idx].option_set_key = studioState.optionSets[0].set_key;
  }
  markDirty();
  renderStudioFields();
  renderLivePreview();
}

function selectSharedOptionSet(idx, setKey) {
  if (!studioState.fields[idx]) return;
  studioState.fields[idx].option_set_key = setKey;
  markDirty();
  renderLivePreview();
}

function copySharedToCustom(idx) {
  var f = studioState.fields[idx];
  if (!f || !f.option_set_key) return;

  var set = studioState.optionSets.find(function(s) { return s.set_key === f.option_set_key; });
  if (!set || !Array.isArray(set.options_parsed)) return;

  f.options = JSON.parse(JSON.stringify(set.options_parsed));
  f.options_mode = 'custom';
  f.option_set_key = '';
  markDirty();
  renderStudioFields();
  renderLivePreview();
  alert('Options copied into this metric! They can now be customized independently without altering other metrics.');
}

function addOptionItem(fieldIdx) {
  var f = studioState.fields[fieldIdx];
  if (!f) return;
  if (!Array.isArray(f.options)) f.options = [];
  var count = f.options.length + 1;
  f.options.push({ value: 'opt_' + count, label: 'Option ' + count });
  markDirty();
  renderStudioFields();
  renderLivePreview();
}

function updateOptionItem(fieldIdx, optIdx, prop, val) {
  var f = studioState.fields[fieldIdx];
  if (!f || !f.options || !f.options[optIdx]) return;
  if (typeof f.options[optIdx] === 'string') {
    f.options[optIdx] = { value: f.options[optIdx], label: f.options[optIdx] };
  }
  f.options[optIdx][prop] = val;
  markDirty();
  renderLivePreview();
}

function removeOptionItem(fieldIdx, optIdx) {
  var f = studioState.fields[fieldIdx];
  if (!f || !f.options) return;
  f.options.splice(optIdx, 1);
  markDirty();
  renderStudioFields();
  renderLivePreview();
}

function syncAttachmentUI() {
  var att = studioState.attachment || {};
  var lblEl = document.getElementById('attConfigLabel');
  if (lblEl) lblEl.value = att.label || 'Proof / Attachment';

  var reqEl = document.getElementById('attConfigRequired');
  if (reqEl) reqEl.value = (att.required === false || att.required === 0 || att.required === '0') ? '0' : '1';

  var maxEl = document.getElementById('attConfigMaxMb');
  if (maxEl) maxEl.value = att.max_file_mb || 2;

  var helpEl = document.getElementById('attConfigHelp');
  if (helpEl) helpEl.value = att.help_text || 'PDF document strictly 2 MB or less.';

  var multEl = document.getElementById('attConfigMultiple');
  if (multEl) multEl.value = (att.multiple === true || att.multiple === 1 || att.multiple === '1') ? '1' : '0';

  var fmts = att.allowed_types || ['pdf'];
  if (typeof fmts === 'string') {
    fmts = fmts.split(',').map(function(s) { return s.trim().toLowerCase(); });
  }
  var pdfEl = document.getElementById('attFmtPdf');
  if (pdfEl) pdfEl.checked = fmts.indexOf('pdf') !== -1;
  var imgEl = document.getElementById('attFmtImg');
  if (imgEl) imgEl.checked = (fmts.indexOf('png') !== -1 || fmts.indexOf('jpg') !== -1);
  var docEl = document.getElementById('attFmtDoc');
  if (docEl) docEl.checked = (fmts.indexOf('docx') !== -1 || fmts.indexOf('doc') !== -1);
}

function updateAttachmentProperty(prop, val) {
  if (!studioState.attachment) {
    studioState.attachment = {
      enabled: true,
      label: 'Proof / Attachment',
      required: true,
      allowed_types: ['pdf'],
      max_file_mb: 2,
      help_text: 'PDF document strictly 2 MB or less.'
    };
  }
  studioState.attachment[prop] = val;
  markDirty();
  renderLivePreview();
}

function updateAttachmentFormats() {
  if (!studioState.attachment) {
    studioState.attachment = {
      enabled: true,
      label: 'Proof / Attachment',
      required: true,
      allowed_types: ['pdf'],
      max_file_mb: 2,
      help_text: 'PDF document strictly 2 MB or less.'
    };
  }
  var fmts = [];
  var pdfEl = document.getElementById('attFmtPdf');
  var imgEl = document.getElementById('attFmtImg');
  var docEl = document.getElementById('attFmtDoc');
  if (pdfEl && pdfEl.checked) fmts.push('pdf');
  if (imgEl && imgEl.checked) { fmts.push('png'); fmts.push('jpg'); }
  if (docEl && docEl.checked) { fmts.push('docx'); fmts.push('doc'); }
  if (fmts.length === 0) {
    if (pdfEl) pdfEl.checked = true;
    fmts.push('pdf');
  }
  studioState.attachment.allowed_types = fmts;
  markDirty();
  renderLivePreview();
}

function setPreviewRole(role) {
  studioState.previewRole = role;
  document.getElementById('btnRoleFac').classList.toggle('active', role === 'Faculty');
  document.getElementById('btnRoleCoord').classList.toggle('active', role === 'Coordinator');
  renderLivePreview();
}

function renderLivePreview() {
  var container = document.getElementById('studioLivePreviewContainer');
  if (!container) return;

  var metricName = (studioState.metric && studioState.metric.name) ? studioState.metric.name : 'Form Live Preview';
  var role = studioState.previewRole || 'Faculty';
  var html = '<div style="margin-bottom:14px;padding-bottom:10px;border-bottom:1px solid #F1F5F9;">'
           + '  <div style="font-size:14px;font-weight:700;color:#131D3B;">' + escapeHtml(metricName) + '</div>'
           + '  <div style="font-size:11.5px;color:#64748B;margin-top:2px;">Viewing as: <strong style="color:#FF4F01;">' + escapeHtml(role) + '</strong> &bull; <span style="font-size:10.5px;color:#94A3B8;">Role-specific permissions simulated</span></div>'
           + '</div>';

  if (!studioState.fields || studioState.fields.length === 0) {
    html += '<div style="background:#F8FAFC;border:1px dashed #CBD5E1;border-radius:8px;padding:24px 16px;text-align:center;color:#64748B;margin-bottom:14px;">'
          + '  <div style="font-size:12.5px;font-weight:600;color:#334155;">No Input Fields Yet</div>'
          + '  <div style="font-size:11px;color:#64748B;margin-top:3px;">Fields added in the center canvas will appear here in real time.</div>'
          + '</div>';
  } else {
    html += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px 14px;margin-bottom:14px;">';

    studioState.fields.forEach(function(f) {
      var isFullSpan = (f.type === 'textarea' || f.type === 'file' || f.type === 'specialized');
      var gridStyle = isFullSpan ? 'grid-column:span 2;' : '';
      var reqStar = f.required ? ' <span style="color:#DC2626;">*</span>' : '';
      var help = f.help_text ? ' <div style="font-size:11px;color:#64748B;margin-top:2px;">' + escapeHtml(f.help_text) + '</div>' : '';

      var isEditable = (role === 'Coordinator') ? (f.coordinator_editable !== false) : (f.faculty_editable !== false);
      var lockBadge = !isEditable ? ' <span class="badge" style="background:#F1F5F9;color:#64748B;font-size:9.5px;margin-left:4px;" title="Locked for ' + escapeHtml(role) + '">🔒 Locked</span>' : '';
      var disabledAttr = !isEditable ? ' disabled title="This field is locked for ' + escapeHtml(role) + ' submissions"' : '';
      var lockedStyle = !isEditable ? 'background:#F8FAFC;cursor:not-allowed;color:#94A3B8;border-style:dashed;' : '';

      html += '<div style="' + gridStyle + '">'
            + '  <label style="display:block;font-size:12px;font-weight:600;color:#1E293B;margin-bottom:4px;">'
            + escapeHtml(f.label) + reqStar + lockBadge
            + '  </label>';

      if (f.type === 'textarea') {
        html += '<textarea class="input" rows="2" placeholder="' + escapeHtml(f.placeholder || '') + '" style="font-size:12px;width:100%;' + lockedStyle + '"' + disabledAttr + '></textarea>';
      } else if (f.type === 'select') {
        html += '<select class="select" style="font-size:12px;width:100%;' + lockedStyle + '"' + disabledAttr + '>'
              + '  <option value="">' + escapeHtml(f.placeholder || 'Select an option...') + '</option>';
        var opts = getPreviewOptionsForField(f);
        opts.forEach(function(opt) {
          var lbl = (typeof opt === 'object' && opt !== null) ? (opt.label || opt.value) : opt;
          html += '<option>' + escapeHtml(lbl) + '</option>';
        });
        html += '</select>';
      } else if (f.type === 'multiselect') {
        html += '<div style="background:#F8FAFC;border:1px solid #CBD5E1;border-radius:6px;padding:8px;max-height:80px;overflow-y:auto;font-size:11.5px;' + lockedStyle + '">';
        var opts = getPreviewOptionsForField(f);
        opts.forEach(function(opt) {
          var lbl = (typeof opt === 'object' && opt !== null) ? (opt.label || opt.value) : opt;
          html += '<label style="display:flex;align-items:center;gap:6px;margin:2px 0;cursor:pointer;"><input type="checkbox"' + disabledAttr + '> ' + escapeHtml(lbl) + '</label>';
        });
        html += '</div>';
      } else if (f.type === 'radio') {
        html += '<div style="display:flex;flex-wrap:wrap;gap:10px;font-size:12px;margin-top:4px;">';
        var opts = getPreviewOptionsForField(f);
        opts.forEach(function(opt, oIdx) {
          var lbl = (typeof opt === 'object' && opt !== null) ? (opt.label || opt.value) : opt;
          html += '<label style="display:inline-flex;align-items:center;gap:4px;cursor:pointer;"><input type="radio" name="preview_rad_' + f.key + '" ' + (oIdx === 0 ? 'checked' : '') + disabledAttr + '> ' + escapeHtml(lbl) + '</label>';
        });
        html += '</div>';
      } else if (f.type === 'checkbox') {
        html += '<label style="display:inline-flex;align-items:center;gap:6px;font-size:12px;cursor:pointer;margin-top:6px;"><input type="checkbox"' + disabledAttr + '> ' + escapeHtml(f.placeholder || 'Confirm / Yes') + '</label>';
      } else if (f.type === 'date') {
        html += '<input type="date" class="input" style="font-size:12px;width:100%;' + lockedStyle + '"' + disabledAttr + '>';
      } else if (f.type === 'file') {
        html += '<input type="file" class="input" style="font-size:11px;width:100%;' + lockedStyle + '"' + disabledAttr + '>';
      } else if (f.type === 'readonly') {
        html += '<input type="text" class="input" value="' + escapeHtml(f.placeholder || 'Calculated Automatically') + '" readonly style="background:#F1F5F9;font-weight:600;color:#475569;cursor:not-allowed;font-size:12px;width:100%;">';
      } else if (f.type === 'specialized') {
        html += '<div style="background:#EFF6FF;border:1px solid #BFDBFE;padding:8px 12px;border-radius:6px;font-size:11.5px;color:#1D4ED8;">Institutional logic module component</div>';
      } else {
        var inputType = (f.type === 'integer' || f.type === 'decimal') ? 'number' : (f.type === 'email' ? 'email' : (f.type === 'url' ? 'url' : 'text'));
        html += '<input type="' + inputType + '" class="input" placeholder="' + escapeHtml(f.placeholder || '') + '" style="font-size:12px;width:100%;' + lockedStyle + '"' + disabledAttr + '>';
      }

      html += help + '</div>';
    });

    html += '</div>';
  }

  // Render Anchored Mandatory Proof Attachment in live preview
  var att = studioState.attachment || {};
  var attReq = att.required ? ' <span style="color:#DC2626;">*</span>' : '';
  var fmts = (att.allowed_types || ['pdf']).map(function(t) { return t.toUpperCase(); }).join(', ');
  var modeBadge = att.multiple ? ' <span class="badge" style="background:#EFF6FF;color:#1D4ED8;font-size:10px;">Multiple Files</span>' : '';

  html += '<div>'
        + '  <label style="display:block;font-size:12px;font-weight:700;color:#1E293B;margin-bottom:6px;">'
        + escapeHtml(att.label || 'Proof / Attachment') + attReq + modeBadge
        + '    <span style="font-weight:normal;color:#64748B;font-size:11px;"> — ' + escapeHtml(fmts) + ' strictly ' + (att.max_file_mb || 2) + ' MB or less' + (att.multiple ? ' (Up to 5 files)' : '') + '</span>'
        + '  </label>'
        + '  <div class="preview-proof-dropzone">'
        + '    <span style="color:#FF4F01;">' + icon('upload-cloud', 24) + '</span>'
        + '    <div style="font-size:12px;font-weight:600;color:#1E293B;">Click or Drag &amp; Drop Proof File' + (att.multiple ? 's' : '') + ' Here</div>'
        + '    <div style="font-size:11px;color:#64748B;margin-top:2px;">' + escapeHtml(att.help_text || 'PDF document strictly 2 MB or less.') + '</div>'
        + '  </div>'
        + '</div>';

  container.innerHTML = html;
}

function getPreviewOptionsForField(f) {
  if (f.options_mode === 'shared' && f.option_set_key) {
    var set = studioState.optionSets.find(function(s) { return s.set_key === f.option_set_key; });
    if (set && Array.isArray(set.options_parsed)) {
      return set.options_parsed;
    }
  }
  return Array.isArray(f.options) ? f.options : [];
}

function saveFormDraft() {
  var btn = document.getElementById('btnSaveDraft');
  btn.disabled = true;
  btn.innerHTML = 'Saving...';

  var token = getCsrfToken();
  var payload = new FormData();
  payload.append('action', 'save_form_draft');
  payload.append('id', studioState.metricId);
  payload.append('fields_json', JSON.stringify(studioState.fields));
  payload.append('attachment_json', JSON.stringify(studioState.attachment));
  payload.append('csrf', token);
  payload.append('csrf_token', token);
  payload.append('ajax', '1');

  var postUrl = (window.location.pathname.replace(/\/+$/, '') || 'admin-metrics.php');

  fetch(postUrl, {
    method: 'POST',
    body: payload,
    headers: { 'X-Requested-With': 'XMLHttpRequest' }
  })
  .then(function(res) {
    if (!res.ok) {
      throw new Error('HTTP ' + res.status);
    }
    return res.json();
  })
  .then(function(data) {
    btn.disabled = false;
    btn.innerHTML = studioIcon('save', 13) + ' Save Draft';
    if (data.success) {
      studioState.isDirty = false;
      var draftNotice = document.getElementById('studioDraftNotice');
      if (draftNotice) draftNotice.style.display = 'inline-flex';
      alert('Draft form configuration saved successfully! (Faculty will continue seeing published version until you click Publish).');
    } else {
      alert(data.message || 'Failed to save draft.');
    }
  })
  .catch(function(err) {
    btn.disabled = false;
    btn.innerHTML = studioIcon('save', 13) + ' Save Draft';
    console.error('Draft save failed:', err);
    alert('Failed to save draft: ' + (err.message || 'Network error'));
  });
}

function publishFormConfiguration() {
  // Validate unique field keys
  var seenKeys = {};
  for (var i = 0; i < studioState.fields.length; i++) {
    var k = studioState.fields[i].key ? studioState.fields[i].key.trim() : '';
    if (!k) {
      alert('Field #' + (i + 1) + ' is missing a key identifier.');
      return;
    }
    if (seenKeys[k]) {
      alert('Duplicate field key "' + k + '" detected on field #' + (i + 1) + '. Each field must have a unique permanent key.');
      return;
    }
    seenKeys[k] = true;
  }

  if (studioState.fields.length === 0) {
    alert('A form must contain at least one configured field.');
    return;
  }

  var btn = document.getElementById('btnPublish');
  btn.disabled = true;
  btn.innerHTML = 'Publishing...';

  var token = getCsrfToken();
  var payload = new FormData();
  payload.append('action', 'publish_form');
  payload.append('id', studioState.metricId);
  payload.append('fields_json', JSON.stringify(studioState.fields));
  payload.append('attachment_json', JSON.stringify(studioState.attachment));
  payload.append('csrf', token);
  payload.append('csrf_token', token);
  payload.append('ajax', '1');

  var postUrl = (window.location.pathname.replace(/\/+$/, '') || 'admin-metrics.php');

  fetch(postUrl, {
    method: 'POST',
    body: payload,
    headers: { 'X-Requested-With': 'XMLHttpRequest' }
  })
  .then(function(res) {
    if (!res.ok) {
      throw new Error('HTTP ' + res.status);
    }
    return res.json();
  })
  .then(function(data) {
    btn.disabled = false;
    btn.innerHTML = studioIcon('check-circle', 14) + ' Publish Configuration';
    if (data.success) {
      studioState.isDirty = false;
      var draftNotice = document.getElementById('studioDraftNotice');
      if (draftNotice) draftNotice.style.display = 'none';
      var verEl = document.getElementById('studioVersion');
      if (verEl) verEl.textContent = 'v' + (data.version || 2);
      alert('Form configuration published successfully! (Version ' + (data.version || 2) + '). Faculty and Coordinator upload forms are now updated.');
    } else {
      alert(data.message || 'Failed to publish configuration.');
    }
  })
  .catch(function(err) {
    btn.disabled = false;
    btn.innerHTML = studioIcon('check-circle', 14) + ' Publish Configuration';
    console.error('Publish failed:', err);
    alert('Failed to publish configuration: ' + (err.message || 'Network error'));
  });
}

function openVersionHistoryModal() {
  var modal = document.getElementById('historyModal');
  var body = document.getElementById('historyModalBody');
  document.getElementById('historyModalSub').textContent = 'Audit history for ' + (studioState.metric ? studioState.metric.name : 'Metric');

  var html = '';
  if (!studioState.history || studioState.history.length === 0) {
    html = '<div style="padding:24px;text-align:center;color:#64748B;">No prior version snapshots recorded yet. When you publish new configurations, history snapshots are created automatically.</div>';
  } else {
    studioState.history.forEach(function(h) {
      var dateStr = h.published_at ? new Date(h.published_at).toLocaleString() : '—';
      var fields = [];
      try { fields = JSON.parse(h.config_json); } catch(e) {}

      html += '<div style="background:#F8FAFC;border:1px solid #E2E8F0;border-radius:8px;padding:12px;margin-bottom:10px;display:flex;align-items:center;justify-content:space-between;gap:12px;">'
            + '  <div>'
            + '    <div style="display:flex;align-items:center;gap:8px;">'
            + '      <span class="badge badge-primary" style="font-weight:700;">Version ' + h.version + '</span>'
            + '      <span style="font-size:12px;font-weight:600;color:#1E293B;">' + fields.length + ' fields configured</span>'
            + '    </div>'
            + '    <div style="font-size:11.5px;color:#64748B;margin-top:3px;">'
            + '      Published ' + dateStr + (h.publisher_name ? ' by ' + escapeHtml(h.publisher_name) : '')
            + '    </div>'
            + '  </div>'
            + '  <button type="button" class="btn btn-sm btn-outline" onclick="restoreHistoricalVersion(' + h.id + ')" style="font-size:11.5px;color:#2563EB;border-color:#BFDBFE;background:#FFFFFF;">'
            + '    Restore Version'
            + '  </button>'
            + '</div>';
    });
  }

  body.innerHTML = html;
  modal.showModal();
}

function restoreHistoricalVersion(histId) {
  if (!confirm('Are you sure you want to restore this previous form configuration? This will create a new published version reflecting the historical fields.')) {
    return;
  }

  var token = getCsrfToken();
  var payload = new FormData();
  payload.append('action', 'restore_form_version');
  payload.append('id', studioState.metricId);
  if (histId) {
    payload.append('history_id', histId);
  }
  payload.append('csrf', token);
  payload.append('csrf_token', token);
  payload.append('ajax', '1');

  var postUrl = (window.location.pathname.replace(/\/+$/, '') || 'admin-metrics.php');

  fetch(postUrl, {
    method: 'POST',
    body: payload,
    headers: { 'X-Requested-With': 'XMLHttpRequest' }
  })
  .then(function(res) {
    if (!res.ok) {
      throw new Error('HTTP ' + res.status);
    }
    return res.json();
  })
  .then(function(data) {
    if (data.success) {
      document.getElementById('historyModal').close();
      alert('Form restored to historical configuration successfully!');
      openFormStudio(studioState.metricId);
    } else {
      alert(data.message || 'Failed to restore historical version.');
    }
  })
  .catch(function(err) {
    console.error('Restore failed:', err);
    alert('Failed to restore historical version: ' + (err.message || 'Network error'));
  });
}

function openSaveToReusableModal(idx) {
  var f = studioState.fields[idx];
  if (!f) return;

  document.getElementById('rfLabel').value = f.label;
  document.getElementById('rfKey').value = f.key;
  document.getElementById('rfType').value = f.type;
  document.getElementById('rfPlaceholder').value = f.placeholder || '';
  document.getElementById('saveReusableModal').showModal();
}

function openNewReusableFieldModal() {
  document.getElementById('rfLabel').value = '';
  document.getElementById('rfKey').value = '';
  document.getElementById('rfType').value = 'text';
  document.getElementById('rfPlaceholder').value = '';
  document.getElementById('saveReusableModal').showModal();
}

function submitSaveReusableField() {
  var lbl = document.getElementById('rfLabel').value.trim();
  var key = document.getElementById('rfKey').value.trim();
  var type = document.getElementById('rfType').value;
  var ph = document.getElementById('rfPlaceholder').value.trim();

  if (!lbl || !key) {
    alert('Field label and key are required.');
    return;
  }

  var token = getCsrfToken();
  var payload = new FormData();
  payload.append('action', 'save_reusable_field');
  payload.append('field_json', JSON.stringify({
    label: lbl,
    key: key,
    type: type,
    placeholder: ph
  }));
  payload.append('csrf', token);
  payload.append('csrf_token', token);
  payload.append('ajax', '1');

  var postUrl = (window.location.pathname.replace(/\/+$/, '') || 'admin-metrics.php');

  fetch(postUrl, {
    method: 'POST',
    body: payload,
    headers: { 'X-Requested-With': 'XMLHttpRequest' }
  })
  .then(function(res) {
    if (!res.ok) {
      throw new Error('HTTP ' + res.status);
    }
    return res.json();
  })
  .then(function(data) {
    if (data.success) {
      document.getElementById('saveReusableModal').close();
      studioState.reusableFields = data.reusable_fields || [];
      renderReusableLibrary();
      alert('Field saved to Reusable Library!');
    } else {
      alert(data.message || 'Failed to save reusable field.');
    }
  })
  .catch(function(err) {
    console.error('Save reusable field failed:', err);
    alert('Failed to save reusable field: ' + (err.message || 'Network error'));
  });
}

function getCsrfToken() {
  var el = document.querySelector('input[name="csrf"]');
  if (el && el.value) return el.value;
  el = document.querySelector('input[name="csrf_token"]');
  if (el && el.value) return el.value;
  return '<?= e(csrf_token()) ?>';
}

function openDeleteModal(id, name, key, type) {
  document.getElementById('deleteMetricId').value = id;
  document.getElementById('deleteMetricName').textContent = name;
  document.getElementById('deleteMetricKey').textContent = key;
  document.getElementById('deleteModal').showModal();
}

function openDeleteFromEdit() {
  var id = document.getElementById('editMetricId').value;
  var name = document.getElementById('editName').value;
  var key = document.getElementById('editMetricKey').textContent;
  var modal = document.getElementById('editMetricModal');
  if (modal && modal.open) modal.close();
  openDeleteModal(id, name, key, 'generic');
}

function escapeHtml(text) {
  if (!text) return '';
  return String(text)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}

// =========================================================================
// ATTS-METRIC-DRAG-SORT: Drag-and-Drop & Keyboard Reordering Engine
// =========================================================================
var isFilteredOrSorted = <?= $isFilteredOrSorted ? 'true' : 'false' ?>;
var isSavingOrder = false;
var previousOrderIds = [];
var orderToastTimer = null;

function showOrderToast(msg, type, duration) {
  if (typeof type === 'undefined') type = 'success';
  if (typeof duration === 'undefined') duration = 3000;

  var toast = document.getElementById('orderToast');
  var iconSpan = document.getElementById('orderToastIcon');
  var msgSpan = document.getElementById('orderToastMsg');
  if (!toast || !iconSpan || !msgSpan) return;

  toast.className = 'order-toast show toast-' + type;
  msgSpan.textContent = msg;

  if (type === 'saving') {
    iconSpan.innerHTML = '<span class="spin-icon"><?= icon("refresh", 14) ?></span>';
  } else if (type === 'error') {
    iconSpan.innerHTML = '<?= icon("alert-triangle", 14) ?>';
  } else {
    iconSpan.innerHTML = '<?= icon("check", 14) ?>';
  }

  if (orderToastTimer) {
    clearTimeout(orderToastTimer);
    orderToastTimer = null;
  }
  if (duration > 0) {
    orderToastTimer = setTimeout(function() {
      toast.classList.remove('show');
    }, duration);
  }
}

function updateRowOrderNumbers() {
  var tbody = document.querySelector('.metrics-table tbody');
  if (!tbody) return;
  var rows = tbody.querySelectorAll('tr.metric-row');
  rows.forEach(function(r, idx) {
    var pill = r.querySelector('[data-order-pill]');
    var num = idx + 1;
    if (pill) {
      pill.textContent = (num < 10 ? '0' : '') + num;
    }
    r.dataset.order = String(num);
  });
}

function getCurrentlyOrderedIds() {
  var tbody = document.querySelector('.metrics-table tbody');
  if (!tbody) return [];
  var rows = tbody.querySelectorAll('tr.metric-row');
  var ids = [];
  rows.forEach(function(r) {
    if (r.dataset.id) {
      ids.push(r.dataset.id);
    }
  });
  return ids;
}

function saveNewMetricOrder(initialSnapshot) {
  var currentIds = getCurrentlyOrderedIds();
  if (currentIds.length === 0) return;

  // If nothing changed from before drag started, do nothing
  if (initialSnapshot && initialSnapshot.join(',') === currentIds.join(',')) {
    return;
  }

  if (isSavingOrder) {
    return;
  }

  isSavingOrder = true;
  showOrderToast('Saving new metric order...', 'saving', 0);

  var payload = new FormData();
  payload.append('action', 'reorder_metrics');
  payload.append('csrf', getCsrfToken());
  payload.append('csrf_token', getCsrfToken());
  payload.append('ajax', '1');

  currentIds.forEach(function(id) {
    payload.append('ordered_ids[]', id);
  });

  var postUrl = (window.location.pathname.replace(/\/+$/, '') || 'admin-metrics.php');

  fetch(postUrl, {
    method: 'POST',
    body: payload,
    headers: { 'X-Requested-With': 'XMLHttpRequest' }
  })
  .then(function(res) {
    if (!res.ok) {
      throw new Error('HTTP ' + res.status);
    }
    return res.json();
  })
  .then(function(data) {
    isSavingOrder = false;
    if (data.success) {
      previousOrderIds = currentIds.slice();
      showOrderToast('Metric order saved successfully.', 'success', 3000);
    } else {
      throw new Error(data.message || 'Server error occurred while saving order.');
    }
  })
  .catch(function(err) {
    isSavingOrder = false;
    console.error('Order save error:', err);
    showOrderToast('Failed to save order: ' + (err.message || 'Unknown error') + '. Restoring previous order...', 'error', 4500);
    restorePreviousOrder();
  });
}

function restorePreviousOrder() {
  if (!previousOrderIds || previousOrderIds.length === 0) return;
  var tbody = document.querySelector('.metrics-table tbody');
  if (!tbody) return;

  var rowMap = {};
  tbody.querySelectorAll('tr.metric-row').forEach(function(r) {
    if (r.dataset.id) {
      rowMap[r.dataset.id] = r;
    }
  });

  previousOrderIds.forEach(function(id) {
    if (rowMap[id]) {
      tbody.appendChild(rowMap[id]);
    }
  });
  updateRowOrderNumbers();
}

function initMetricDragAndDrop() {
  if (isFilteredOrSorted) return;

  var tbody = document.querySelector('.metrics-table tbody');
  if (!tbody) return;

  previousOrderIds = getCurrentlyOrderedIds();

  var draggedRow = null;
  var initialOrderOnDragStart = null;
  var autoScrollTimer = null;

  function stopAutoScroll() {
    if (autoScrollTimer) {
      cancelAnimationFrame(autoScrollTimer);
      autoScrollTimer = null;
    }
  }

  function handleAutoScroll(clientY) {
    var edgeThreshold = 65;
    var speed = 0;
    if (clientY < edgeThreshold) {
      speed = -Math.max(4, Math.round((edgeThreshold - clientY) / 3));
    } else if (window.innerHeight - clientY < edgeThreshold) {
      speed = Math.max(4, Math.round((edgeThreshold - (window.innerHeight - clientY)) / 3));
    }

    if (speed !== 0) {
      window.scrollBy(0, speed);
      autoScrollTimer = requestAnimationFrame(function() {
        handleAutoScroll(clientY);
      });
    } else {
      stopAutoScroll();
    }
  }

  // Set draggable on row ONLY when mouse is pressed down on drag handle
  tbody.addEventListener('mousedown', function(e) {
    var handle = e.target.closest('.metric-drag-handle');
    if (handle && !handle.disabled) {
      var row = handle.closest('tr.metric-row');
      if (row) {
        row.setAttribute('draggable', 'true');
      }
    }
  });

  document.addEventListener('mouseup', function() {
    tbody.querySelectorAll('tr.metric-row[draggable="true"]').forEach(function(r) {
      r.removeAttribute('draggable');
    });
    stopAutoScroll();
  });

  tbody.addEventListener('dragstart', function(e) {
    var row = e.target.closest('tr.metric-row');
    if (!row || row.getAttribute('draggable') !== 'true') {
      e.preventDefault();
      return;
    }

    draggedRow = row;
    initialOrderOnDragStart = getCurrentlyOrderedIds();

    e.dataTransfer.effectAllowed = 'move';
    e.dataTransfer.setData('text/plain', row.dataset.id || '');

    document.body.classList.add('table-row-dragging');

    setTimeout(function() {
      if (draggedRow) {
        draggedRow.classList.add('is-dragging');
      }
    }, 0);
  });

  tbody.addEventListener('dragover', function(e) {
    if (!draggedRow) return;
    e.preventDefault();
    e.dataTransfer.dropEffect = 'move';

    handleAutoScroll(e.clientY);

    var targetRow = e.target.closest('tr.metric-row');
    if (!targetRow || targetRow === draggedRow) {
      clearDropIndicators();
      return;
    }

    var rect = targetRow.getBoundingClientRect();
    var isAbove = (e.clientY - rect.top) < (rect.height / 2);

    clearDropIndicators();
    if (isAbove) {
      targetRow.classList.add('drag-over-above');
    } else {
      targetRow.classList.add('drag-over-below');
    }
  });

  tbody.addEventListener('dragleave', function(e) {
    var related = e.relatedTarget;
    if (!tbody.contains(related)) {
      clearDropIndicators();
      stopAutoScroll();
    }
  });

  tbody.addEventListener('drop', function(e) {
    if (!draggedRow) return;
    e.preventDefault();
    stopAutoScroll();

    var targetRow = e.target.closest('tr.metric-row');
    if (targetRow && targetRow !== draggedRow) {
      var rect = targetRow.getBoundingClientRect();
      var isAbove = (e.clientY - rect.top) < (rect.height / 2);

      if (isAbove) {
        tbody.insertBefore(draggedRow, targetRow);
      } else {
        tbody.insertBefore(draggedRow, targetRow.nextSibling);
      }

      updateRowOrderNumbers();
      saveNewMetricOrder(initialOrderOnDragStart);
    }

    clearDropIndicators();
  });

  tbody.addEventListener('dragend', function() {
    stopAutoScroll();
    if (draggedRow) {
      draggedRow.classList.remove('is-dragging');
      draggedRow.removeAttribute('draggable');
    }
    draggedRow = null;
    initialOrderOnDragStart = null;
    document.body.classList.remove('table-row-dragging');
    clearDropIndicators();
  });

  function clearDropIndicators() {
    tbody.querySelectorAll('tr.metric-row').forEach(function(r) {
      r.classList.remove('drag-over-above', 'drag-over-below');
    });
  }
}

function initKeyboardReordering() {
  if (isFilteredOrSorted) return;

  var tbody = document.querySelector('.metrics-table tbody');
  if (!tbody) return;

  var grabbedRow = null;
  var initialKeyboardOrder = null;

  tbody.addEventListener('keydown', function(e) {
    var handle = e.target.closest('.metric-drag-handle');
    if (!handle || handle.disabled) return;
    var row = handle.closest('tr.metric-row');
    if (!row) return;

    // Space or Enter: Pick up or Drop
    if (e.key === ' ' || e.key === 'Enter') {
      e.preventDefault();
      if (!grabbedRow) {
        // Pick up
        grabbedRow = row;
        initialKeyboardOrder = getCurrentlyOrderedIds();
        grabbedRow.classList.add('is-keyboard-grabbed');
        handle.setAttribute('aria-grabbed', 'true');
        showOrderToast('Metric picked up. Use ↑ / ↓ arrows to reorder, Space/Enter to place, Esc to cancel.', 'saving', 0);
      } else if (grabbedRow === row) {
        // Drop
        grabbedRow.classList.remove('is-keyboard-grabbed');
        handle.setAttribute('aria-grabbed', 'false');
        grabbedRow = null;
        updateRowOrderNumbers();
        saveNewMetricOrder(initialKeyboardOrder);
        initialKeyboardOrder = null;
      }
      return;
    }

    // Escape: Cancel keyboard reorder
    if (e.key === 'Escape' && grabbedRow) {
      e.preventDefault();
      grabbedRow.classList.remove('is-keyboard-grabbed');
      handle.setAttribute('aria-grabbed', 'false');
      grabbedRow = null;
      if (initialKeyboardOrder) {
        previousOrderIds = initialKeyboardOrder.slice();
        restorePreviousOrder();
        initialKeyboardOrder = null;
      }
      showOrderToast('Reordering cancelled.', 'error', 2500);
      return;
    }

    // ArrowUp / ArrowDown while grabbed
    if (grabbedRow) {
      if (e.key === 'ArrowUp') {
        e.preventDefault();
        var prev = grabbedRow.previousElementSibling;
        if (prev && prev.classList.contains('metric-row')) {
          tbody.insertBefore(grabbedRow, prev);
          updateRowOrderNumbers();
          grabbedRow.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
          handle.focus();
        }
      } else if (e.key === 'ArrowDown') {
        e.preventDefault();
        var next = grabbedRow.nextElementSibling;
        if (next && next.classList.contains('metric-row')) {
          tbody.insertBefore(grabbedRow, next.nextSibling);
          updateRowOrderNumbers();
          grabbedRow.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
          handle.focus();
        }
      }
    }
  });
}

function initTouchReordering() {
  if (isFilteredOrSorted) return;

  var tbody = document.querySelector('.metrics-table tbody');
  if (!tbody) return;

  var touchDraggedRow = null;
  var touchInitialOrder = null;
  var currentOverRow = null;
  var currentIsAbove = false;

  tbody.addEventListener('touchstart', function(e) {
    var handle = e.target.closest('.metric-drag-handle');
    if (!handle || handle.disabled) return;
    var row = handle.closest('tr.metric-row');
    if (!row) return;

    touchDraggedRow = row;
    touchInitialOrder = getCurrentlyOrderedIds();
    touchDraggedRow.classList.add('is-dragging');
    document.body.classList.add('table-row-dragging');
  }, { passive: true });

  tbody.addEventListener('touchmove', function(e) {
    if (!touchDraggedRow) return;
    var touch = e.touches[0];
    if (!touch) return;

    var el = document.elementFromPoint(touch.clientX, touch.clientY);
    var targetRow = el ? el.closest('tr.metric-row') : null;

    tbody.querySelectorAll('tr.metric-row').forEach(function(r) {
      r.classList.remove('drag-over-above', 'drag-over-below');
    });

    if (targetRow && targetRow !== touchDraggedRow) {
      var rect = targetRow.getBoundingClientRect();
      currentIsAbove = (touch.clientY - rect.top) < (rect.height / 2);
      currentOverRow = targetRow;
      if (currentIsAbove) {
        targetRow.classList.add('drag-over-above');
      } else {
        targetRow.classList.add('drag-over-below');
      }
    } else {
      currentOverRow = null;
    }
  }, { passive: true });

  tbody.addEventListener('touchend', function() {
    if (!touchDraggedRow) return;

    if (currentOverRow && currentOverRow !== touchDraggedRow) {
      if (currentIsAbove) {
        tbody.insertBefore(touchDraggedRow, currentOverRow);
      } else {
        tbody.insertBefore(touchDraggedRow, currentOverRow.nextSibling);
      }
      updateRowOrderNumbers();
      saveNewMetricOrder(touchInitialOrder);
    }

    touchDraggedRow.classList.remove('is-dragging');
    document.body.classList.remove('table-row-dragging');
    tbody.querySelectorAll('tr.metric-row').forEach(function(r) {
      r.classList.remove('drag-over-above', 'drag-over-below');
    });

    touchDraggedRow = null;
    touchInitialOrder = null;
    currentOverRow = null;
  });
}

document.addEventListener('DOMContentLoaded', function() {
  initMetricDragAndDrop();
  initKeyboardReordering();
  initTouchReordering();

  var studioModal = document.getElementById('formStudioModal');
  if (studioModal) {
    studioModal.addEventListener('cancel', function(e) {
      if (studioState.isDirty) {
        if (!confirm('You have unsaved changes in this form studio session. Are you sure you want to close and discard your unsaved changes?')) {
          e.preventDefault();
          return;
        }
        studioState.isDirty = false;
      }
    });
  }
});
</script>

<?php require __DIR__ . '/inc/footer.php'; ?>
