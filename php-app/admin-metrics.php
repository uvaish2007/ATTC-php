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

    if ($action === 'save_template') {
        $id = (int)input('id');
        $rawFields = input('fields_json');
        $fields = json_decode((string)$rawFields, true) ?: [];
        [$ok, $msg] = metric_studio_save_form_template($id, $fields);
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

/* Order Column Pill & Controls */
.order-cell-wrap {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
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
}
.order-arrows {
  display: inline-flex;
  flex-direction: column;
  gap: 2px;
}
.order-arrow-btn {
  width: 18px;
  height: 13px;
  display: flex;
  align-items: center;
  justify-content: center;
  background: #FFFFFF;
  border: 1px solid #CBD5E1;
  border-radius: 3px;
  color: #64748B;
  padding: 0;
  cursor: pointer;
  line-height: 0;
  transition: all .12s ease;
}
.order-arrow-btn:hover {
  background: var(--orange-500, #FF4F01);
  border-color: var(--orange-500, #FF4F01);
  color: #FFFFFF;
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
      <div class="metrics-head-hint">
        <?= icon('info', 14) ?>
        <span>Use &uarr; / &darr; to adjust sequential order on Faculty Upload page</span>
      </div>
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
              <th style="width:75px;text-align:center;">Order</th>
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
              <tr class="<?= !$isActive ? 'row-inactive' : '' ?>">
                <!-- Order & Move Controls -->
                <td style="text-align:center;">
                  <div class="order-cell-wrap">
                    <span class="order-number-pill">
                      <?= sprintf('%02d', (int)$m['display_order']) ?>
                    </span>
                    <div class="order-arrows">
                      <form method="post" style="margin:0;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="move">
                        <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
                        <input type="hidden" name="direction" value="up">
                        <button type="submit" class="order-arrow-btn" title="Move Up" aria-label="Move Up">
                          <?= icon('chevron-up', 10) ?>
                        </button>
                      </form>
                      <form method="post" style="margin:0;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="move">
                        <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
                        <input type="hidden" name="direction" value="down">
                        <button type="submit" class="order-arrow-btn" title="Move Down" aria-label="Move Down">
                          <?= icon('chevron-down', 10) ?>
                        </button>
                      </form>
                    </div>
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
                  <?php if ($isSpecialized || $isHybrid): ?>
                    <div class="form-type-pill form-builtin">
                      <?= icon('shield', 13) ?>
                      <span>Built-in Logic</span>
                    </div>
                    <?php if (!empty($m['subcategories_parsed'])): ?>
                      <div class="form-sub-info">
                        <span style="color:#94A3B8;">↳</span> <?= count($m['subcategories_parsed']) ?> subcategories configured
                      </div>
                    <?php endif; ?>
                  <?php else: ?>
                    <div class="form-type-pill form-dynamic">
                      <?= icon('layers', 13) ?>
                      <span>Dynamic (<?= (int)$m['fields_count'] ?> fields)</span>
                    </div>
                    <div>
                      <button type="button" class="btn-edit-fields" onclick='openFormBuilder(<?= json_encode($m, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>)'>
                        <?= icon('pencil', 11) ?> Configure Fields
                      </button>
                    </div>
                  <?php endif; ?>
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

                    <!-- Form Builder (for generic/hybrid) -->
                    <button type="button" class="dock-btn dock-builder" onclick='openFormBuilder(<?= json_encode($m, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>)' title="Configure Form Fields">
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
<!-- MODAL: FORM TEMPLATE BUILDER                                              -->
<!-- ========================================================================= -->
<dialog class="modal" id="builderModal" style="max-width:56rem;width:96vw;border-radius:12px;">
  <form method="post" action="<?= e(url('admin-metrics.php')) ?>" id="templateBuilderForm">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_template">
    <input type="hidden" name="id" id="builderMetricId">
    <input type="hidden" name="fields_json" id="builderFieldsJson">

    <div class="modal-head" style="padding:16px 20px;border-bottom:1px solid #E2E8F0;display:flex;justify-content:space-between;align-items:center;">
      <div>
        <h3 style="margin:0;font-size:16px;font-weight:700;color:var(--ink, #1E293B);" id="builderTitle">Form Template Builder</h3>
        <div class="msub" style="font-size:12px;color:#64748B;margin-top:2px;">
          Configure fields, validation constraints, and view live interactive preview
        </div>
      </div>
      <button type="button" class="btn btn-sm btn-outline" onclick="addNewFieldRow()" style="gap:4px;">
        <?= icon('plus', 14) ?> Add Field
      </button>
    </div>

    <div class="modal-body" style="padding:18px 20px;display:grid;grid-template-columns:1fr 1fr;gap:20px;max-height:68vh;overflow-y:auto;">
      <!-- Left Column: Configured Fields Editor -->
      <div>
        <div style="font-weight:700;font-size:13px;color:#334155;margin-bottom:8px;display:flex;justify-content:space-between;align-items:center;">
          <span>Form Fields (<span id="fieldsCountBadge">0</span>)</span>
          <span style="font-size:11px;color:#64748B;font-weight:normal;">Drag or reorder fields</span>
        </div>
        <div id="fieldsContainer" style="display:flex;flex-direction:column;gap:10px;">
          <!-- Field rows dynamically rendered by JS -->
        </div>
      </div>

      <!-- Right Column: Real-Time Live Visual Preview -->
      <div style="background:#F8FAFC;border:1px solid #E2E8F0;border-radius:10px;padding:16px;">
        <div style="font-weight:700;font-size:13px;color:#334155;margin-bottom:12px;display:flex;align-items:center;gap:6px;">
          <span style="color:#FF4F01;"><?= icon('eye', 14) ?></span>
          <span>Live Form Preview</span>
          <span class="badge" style="background:#E2E8F0;color:#475569;font-size:10px;margin-left:auto;">Faculty View</span>
        </div>
        <div id="livePreviewContainer" style="display:grid;grid-template-columns:1fr 1fr;gap:12px 14px;background:#FFFFFF;border:1px solid #E2E8F0;padding:16px;border-radius:8px;">
          <!-- Live preview inputs rendered by JS -->
        </div>
      </div>
    </div>

    <div class="modal-foot" style="padding:12px 20px;border-top:1px solid #E2E8F0;display:flex;justify-content:space-between;align-items:center;">
      <button type="button" class="btn btn-ghost btn-sm" onclick="openDeleteFromBuilder()" style="color:#DC2626;display:inline-flex;align-items:center;gap:4px;">
        <?= icon('trash-2', 14) ?> Delete Metric
      </button>
      <div style="display:flex;gap:8px;">
        <button type="button" class="btn btn-outline btn-sm" onclick="this.closest('dialog').close()">Cancel</button>
        <button type="submit" class="btn btn-primary btn-sm" onclick="submitBuilderForm()"><?= icon('save', 14) ?> Save Template</button>
      </div>
    </div>
  </form>
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
  document.getElementById('builderMetricId').value = metric.id;
  document.getElementById('builderTitle').textContent = 'Form Template Builder — ' + metric.name;

  currentBuilderFields = Array.isArray(metric.form_config_parsed) && metric.form_config_parsed.length > 0
    ? JSON.parse(JSON.stringify(metric.form_config_parsed))
    : [
        { key: 'title', label: 'Entry Title / Name', type: 'text', placeholder: 'Enter title...', help_text: 'Primary title for this entry', required: true },
        { key: 'details', label: 'Details / Description', type: 'textarea', placeholder: 'Detailed summary...', help_text: '', required: false },
        { key: 'event_date', label: 'Date', type: 'date', placeholder: '', help_text: 'Date of completion or event', required: true },
        { key: 'proof_file', label: 'Proof Document (PDF)', type: 'file', placeholder: '', help_text: 'Official certificate or document', required: true }
      ];

  renderBuilderFields();
  document.getElementById('builderModal').showModal();
}

function renderBuilderFields() {
  var container = document.getElementById('fieldsContainer');
  document.getElementById('fieldsCountBadge').textContent = currentBuilderFields.length;
  var html = '';

  currentBuilderFields.forEach(function(f, idx) {
    html += '<div class="card" style="padding:12px;border:1px solid #E2E8F0;background:#FFFFFF;border-radius:8px;position:relative;">'
          + '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">'
          + '  <div style="display:flex;align-items:center;gap:6px;">'
          + '    <span class="badge badge-neutral" style="font-size:10.5px;font-weight:700;">#' + (idx + 1) + '</span>'
          + '    <input type="text" class="input" value="' + escapeHtml(f.label) + '" oninput="updateFieldProperty(' + idx + ', \'label\', this.value)" style="height:28px;font-size:12px;font-weight:700;width:180px;" placeholder="Field Label">'
          + '  </div>'
          + '  <div style="display:flex;align-items:center;gap:4px;">'
          + '    <button type="button" class="btn btn-ghost btn-sm" onclick="moveBuilderField(' + idx + ', -1)" ' + (idx === 0 ? 'disabled' : '') + ' style="padding:0 5px;font-size:10px;">&blacktriangle;</button>'
          + '    <button type="button" class="btn btn-ghost btn-sm" onclick="moveBuilderField(' + idx + ', 1)" ' + (idx === currentBuilderFields.length - 1 ? 'disabled' : '') + ' style="padding:0 5px;font-size:10px;">&blacktriangledown;</button>'
          + '    <button type="button" class="btn btn-ghost btn-sm" onclick="deleteBuilderField(' + idx + ')" title="Delete this field" style="padding:2px 8px;color:#DC2626;font-size:11px;font-weight:600;display:inline-flex;align-items:center;gap:3px;background:#FEE2E2;border-radius:4px;">&times; Delete Field</button>'
          + '  </div>'
          + '</div>'
          + '<div style="display:grid;grid-template-columns:1fr 1fr;gap:6px 10px;">'
          + '  <div>'
          + '    <label style="font-size:11px;font-weight:600;color:#64748B;">Field Key</label>'
          + '    <input type="text" class="input" value="' + escapeHtml(f.key) + '" oninput="updateFieldProperty(' + idx + ', \'key\', this.value)" style="height:26px;font-size:11px;font-family:monospace;" placeholder="key_name">'
          + '  </div>'
          + '  <div>'
          + '    <label style="font-size:11px;font-weight:600;color:#64748B;">Field Type</label>'
          + '    <select class="select" onchange="updateFieldProperty(' + idx + ', \'type\', this.value)" style="height:26px;font-size:11px;">'
          + '      <option value="text"' + (f.type === 'text' ? ' selected' : '') + '>Short Text</option>'
          + '      <option value="textarea"' + (f.type === 'textarea' ? ' selected' : '') + '>Long Text (Textarea)</option>'
          + '      <option value="integer"' + (f.type === 'integer' ? ' selected' : '') + '>Integer Number</option>'
          + '      <option value="decimal"' + (f.type === 'decimal' ? ' selected' : '') + '>Decimal / Amount</option>'
          + '      <option value="date"' + (f.type === 'date' ? ' selected' : '') + '>Date</option>'
          + '      <option value="select"' + (f.type === 'select' ? ' selected' : '') + '>Dropdown Options</option>'
          + '      <option value="url"' + (f.type === 'url' ? ' selected' : '') + '>URL Link</option>'
          + '      <option value="file"' + (f.type === 'file' ? ' selected' : '') + '>File / PDF Upload</option>'
          + '    </select>'
          + '  </div>'
          + '</div>'
          + '<div style="display:flex;align-items:center;gap:12px;margin-top:6px;">'
          + '  <label style="display:inline-flex;align-items:center;gap:4px;font-size:11.5px;color:#334155;cursor:pointer;margin:0;">'
          + '    <input type="checkbox" ' + (f.required ? 'checked' : '') + ' onchange="updateFieldProperty(' + idx + ', \'required\', this.checked)"> Required'
          + '  </label>'
          + '  <input type="text" class="input" value="' + escapeHtml(f.placeholder || '') + '" oninput="updateFieldProperty(' + idx + ', \'placeholder\', this.value)" style="height:24px;font-size:11px;flex:1;" placeholder="Placeholder text...">'
          + '</div>';

    if (f.type === 'select') {
      var optsStr = Array.isArray(f.options) ? f.options.join(', ') : (f.options || '');
      html += '<div style="margin-top:6px;">'
            + '<label style="font-size:11px;font-weight:600;color:#64748B;">Dropdown Options (comma separated)</label>'
            + '<input type="text" class="input" value="' + escapeHtml(optsStr) + '" oninput="updateFieldProperty(' + idx + ', \'options\', this.value.split(\',\').map(function(s){return s.trim();}))" style="height:24px;font-size:11px;" placeholder="Option 1, Option 2, Option 3">'
            + '</div>';
    }

    html += '</div>';
  });

  container.innerHTML = html;
  renderLivePreview();
}

function updateFieldProperty(idx, prop, val) {
  if (currentBuilderFields[idx]) {
    currentBuilderFields[idx][prop] = val;
    renderLivePreview();
  }
}

function addNewFieldRow() {
  var count = currentBuilderFields.length + 1;
  currentBuilderFields.push({
    key: 'field_' + count,
    label: 'Field ' + count,
    type: 'text',
    placeholder: '',
    help_text: '',
    required: false
  });
  renderBuilderFields();
}

function moveBuilderField(idx, delta) {
  var newIdx = idx + delta;
  if (newIdx < 0 || newIdx >= currentBuilderFields.length) return;
  var temp = currentBuilderFields[idx];
  currentBuilderFields[idx] = currentBuilderFields[newIdx];
  currentBuilderFields[newIdx] = temp;
  renderBuilderFields();
}

function deleteBuilderField(idx) {
  if (currentBuilderFields.length <= 1) {
    alert('A form must have at least one field.');
    return;
  }
  currentBuilderFields.splice(idx, 1);
  renderBuilderFields();
}

function renderLivePreview() {
  var preview = document.getElementById('livePreviewContainer');
  var html = '';

  currentBuilderFields.forEach(function(f) {
    var fullSpan = (f.type === 'textarea' || f.type === 'file') ? 'grid-column:span 2;' : '';
    var reqStar = f.required ? ' <span style="color:#EF4444;">*</span>' : '';

    html += '<div style="' + fullSpan + '">'
          + '<label style="display:block;font-size:12px;font-weight:600;color:#1E293B;margin-bottom:3px;">'
          + escapeHtml(f.label || 'Untitled Field') + reqStar + '</label>';

    if (f.type === 'textarea') {
      html += '<textarea class="input" rows="2" placeholder="' + escapeHtml(f.placeholder || '') + '" style="font-size:12px;width:100%;"></textarea>';
    } else if (f.type === 'select') {
      html += '<select class="select" style="font-size:12px;width:100%;"><option value="">' + escapeHtml(f.placeholder || 'Select option...') + '</option>';
      if (Array.isArray(f.options)) {
        f.options.forEach(function(opt) {
          if (opt) html += '<option>' + escapeHtml(opt) + '</option>';
        });
      }
      html += '</select>';
    } else if (f.type === 'file') {
      html += '<input class="input" type="file" style="font-size:11.5px;width:100%;">';
    } else if (f.type === 'date') {
      html += '<input class="input" type="date" style="font-size:12px;width:100%;">';
    } else {
      html += '<input class="input" type="' + (f.type === 'integer' || f.type === 'decimal' ? 'number' : 'text') + '" placeholder="' + escapeHtml(f.placeholder || '') + '" style="font-size:12px;width:100%;">';
    }
    html += '</div>';
  });

  preview.innerHTML = html;
}

function submitBuilderForm() {
  document.getElementById('builderFieldsJson').value = JSON.stringify(currentBuilderFields);
  document.getElementById('templateBuilderForm').submit();
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

function openDeleteFromBuilder() {
  if (!currentMetric) return;
  var modal = document.getElementById('builderModal');
  if (modal && modal.open) modal.close();
  openDeleteModal(currentMetric.id, currentMetric.name, currentMetric.metric_key, currentMetric.metric_type);
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
</script>

<?php require __DIR__ . '/inc/footer.php'; ?>
