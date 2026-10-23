<?php
/**
 * ATTS-IQAC — Dedicated Form Studio Workspace
 *
 * Professional, fully visual form-building application for IQAC Admin to create,
 * customize, publish, version, and manage dynamic forms and upload workflows for
 * Faculty, Coordinator, HoD, and Principal.
 */

declare(strict_types=1);

require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/inc/metric_studio.php';
require_once __DIR__ . '/inc/helpers.php';
require_once __DIR__ . '/inc/icons.php';

// Strict backend authorization — Admin role only
$user = require_role(['Admin']);

$isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
    || isset($_GET['ajax'])
    || isset($_POST['ajax']);

// -----------------------------------------------------------------------------
// AJAX Endpoint: GET Form Configuration & Dependencies
// -----------------------------------------------------------------------------
if ($isAjax && $_SERVER['REQUEST_METHOD'] === 'GET' && input('action') === 'get_form_config') {
    $id = input('id');
    $cfg = metric_studio_get_form_config($id, true);
    $history = metric_studio_get_form_history($id);
    $reusable = metric_studio_get_reusable_fields();
    $optSets = metric_studio_get_option_sets();
    $allMetrics = metric_studio_all(['sort' => 'order']);

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success'         => true,
        'config'          => $cfg,
        'history'         => $history,
        'reusable_fields' => $reusable,
        'option_sets'     => $optSets,
        'all_metrics'     => array_map(fn($m) => [
            'id' => (int)$m['id'],
            'metric_key' => $m['metric_key'],
            'name' => $m['name'],
            'category' => $m['category'],
            'form_version' => (int)($m['form_version'] ?? 1),
            'status' => (int)($m['status'] ?? 1),
            'metric_type' => $m['metric_type'] ?? 'generic',
            'faculty_visible' => (int)($m['faculty_visible'] ?? 1),
            'coordinator_visible' => (int)($m['coordinator_visible'] ?? 1),
            'hod_visible' => (int)($m['hod_visible'] ?? 1),
            'principal_visible' => (int)($m['principal_visible'] ?? 1),
        ], $allMetrics),
    ]);
    exit;
}

// -----------------------------------------------------------------------------
// POST Handlers (AJAX & Form Submissions)
// -----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = input('action');

    // Create a new dynamic form
    if ($action === 'create_form') {
        $name = trim((string)input('name'));
        $key = trim((string)input('metric_key'));
        $cat = trim((string)input('category', 'QUALITY INITIATIVES AND COMPLIANCE'));
        $desc = trim((string)input('description', ''));

        [$ok, $msg, $created] = metric_studio_create([
            'name'                => $name,
            'metric_key'          => $key,
            'category'            => $cat,
            'description'         => $desc,
            'display_order'       => 99,
            'metric_type'         => 'generic',
            'faculty_visible'     => input('faculty_visible') !== null ? (input('faculty_visible') ? 1 : 0) : 1,
            'coordinator_visible' => input('coordinator_visible') !== null ? (input('coordinator_visible') ? 1 : 0) : 1,
            'hod_visible'         => input('hod_visible') !== null ? (input('hod_visible') ? 1 : 0) : 1,
            'principal_visible'   => input('principal_visible') !== null ? (input('principal_visible') ? 1 : 0) : 1,
            'status'              => 1,
        ]);

        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => $ok,
                'message' => $msg,
                'metric'  => $created,
            ]);
            exit;
        }

        flash($ok ? 'success' : 'error', $msg);
        redirect($ok && $created ? '/form-studio.php?id=' . $created['id'] : '/form-studio.php');
    }

    // Save Draft Form
    if ($action === 'save_form_draft') {
        $id = input('id');
        $fields = json_decode((string)input('fields_json'), true) ?: [];
        $attachment = json_decode((string)input('attachment_json'), true) ?: null;
        $settings = json_decode((string)input('settings_json'), true) ?: null;

        [$ok, $msg] = metric_studio_save_draft_form($id, $fields, $attachment, $settings);

        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => $ok, 'message' => $msg]);
            exit;
        }

        flash($ok ? 'success' : 'error', $msg);
        redirect('/form-studio.php?id=' . urlencode((string)$id));
    }

    // Publish Form Configuration (Immutable Version Snapshot)
    if ($action === 'publish_form') {
        $id = input('id');
        $rawFields = input('fields_json');
        $fields = json_decode((string)$rawFields, true) ?: [];
        $attachment = json_decode((string)input('attachment_json'), true) ?: null;
        $settings = json_decode((string)input('settings_json'), true) ?: null;

        [$ok, $msg] = metric_studio_publish_form($id, $fields, $attachment, (int)$user['id'], $settings);

        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            $updated = metric_studio_find($id);
            echo json_encode([
                'success' => $ok,
                'message' => $msg,
                'version' => (int)($updated['form_version'] ?? 1),
                'history' => metric_studio_get_form_history($id),
            ]);
            exit;
        }

        flash($ok ? 'success' : 'error', $msg);
        redirect('/form-studio.php?id=' . urlencode((string)$id));
    }

    // Restore Previous Historical Version
    if ($action === 'restore_form_version') {
        $id = input('id');
        $histId = input('history_id') ? (int)input('history_id') : null;

        [$ok, $msg] = metric_studio_restore_previous_form($id, $histId);

        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            $cfg = metric_studio_get_form_config($id, true);
            echo json_encode([
                'success' => $ok,
                'message' => $msg,
                'config'  => $cfg,
                'history' => metric_studio_get_form_history($id),
            ]);
            exit;
        }

        flash($ok ? 'success' : 'error', $msg);
        redirect('/form-studio.php?id=' . urlencode((string)$id));
    }

    // Save to Reusable Field Library
    if ($action === 'save_reusable_field') {
        $fieldData = json_decode((string)input('field_json'), true) ?: [];
        [$ok, $msg] = metric_studio_save_reusable_field($fieldData);

        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success'         => $ok,
                'message'         => $msg,
                'reusable_fields' => metric_studio_get_reusable_fields(),
            ]);
            exit;
        }

        flash($ok ? 'success' : 'error', $msg);
        redirect('/form-studio.php');
    }

    // Save Shared Option Set
    if ($action === 'save_option_set') {
        $setKey  = (string)input('set_key');
        $name    = (string)input('name');
        $desc    = (string)input('description');
        $options = json_decode((string)input('options_json'), true) ?: [];

        [$ok, $msg] = metric_studio_save_option_set($setKey, $name, $options, $desc);

        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success'     => $ok,
                'message'     => $msg,
                'option_sets' => metric_studio_get_option_sets(),
            ]);
            exit;
        }

        flash($ok ? 'success' : 'error', $msg);
        redirect('/form-studio.php');
    }

    flash('error', 'Unrecognized Form Studio action.');
    redirect('/form-studio.php');
}

// -----------------------------------------------------------------------------
// Initial Page Setup & Data Loading
// -----------------------------------------------------------------------------
$allMetrics = metric_studio_all(['sort' => 'order']);
$categories = metric_studio_categories();
$optSets = metric_studio_get_option_sets();
$reusableFields = metric_studio_get_reusable_fields();

// Determine starting metric ID
$targetId = input('id');
$initialMetric = null;
if (!empty($targetId)) {
    $initialMetric = metric_studio_find($targetId);
}
if (!$initialMetric && !empty($allMetrics)) {
    $initialMetric = $allMetrics[0];
}

$pageTitle = 'Form Studio · Metric Management';
$breadcrumb = [
    ['label' => 'Metric Management', 'url' => 'admin-metrics.php'],
    ['label' => 'Form Studio']
];
require __DIR__ . '/inc/header.php';
?>

<link rel="stylesheet" href="<?= e(url('assets/css/form-studio.css')) ?>">

<!-- Form Studio Stylesheet Loaded from assets/css/form-studio.css -->

<div class="studio-page-container" id="formStudioApp">
  <!-- 1. TOP TOOLBAR -->
  <header class="studio-topbar">
    <!-- Left: Navigation & Form Identity -->
    <div class="studio-tb-left">
      <a href="<?= e(url('admin-metrics.php')) ?>" class="studio-btn-back" id="btnBackToLibrary" title="Return to Metric Management Overview">
        <?= icon('arrow-left', 13) ?> <span>Metrics</span>
      </a>

      <div class="studio-tb-divider"></div>

      <!-- Custom Form Switcher Dropdown Menu Component -->
      <div class="studio-form-menu-wrap" id="formMenuWrap">
        <button type="button" class="studio-form-menu-trigger" id="formMenuTrigger" onclick="toggleFormMenu(event)" aria-haspopup="true" aria-expanded="false" title="Switch or create dynamic forms">
          <span class="studio-menu-trigger-icon"><?= icon('file-text', 14) ?></span>
          <span class="studio-menu-trigger-label" id="formMenuCurrentLabel">
            #<?= (int)($initialMetric['id'] ?? 11) ?> · <?= e($initialMetric['name'] ?? 'Custom Form') ?>
          </span>
          <span class="studio-menu-trigger-chevron"><?= icon('chevron-down', 13) ?></span>
        </button>

        <!-- Hidden <select id="formSelector"> preserved for automated test compatibility -->
        <select class="studio-hidden-select" id="formSelector" onchange="onFormSelectorChange(this.value)" style="display:none;" aria-hidden="true">
          <optgroup label="Select Form to Customize">
            <?php foreach ($allMetrics as $m): ?>
              <option value="<?= (int)$m['id'] ?>" <?= ($initialMetric && $initialMetric['id'] === $m['id']) ? 'selected' : '' ?>>
                #<?= (int)$m['id'] ?> · <?= e($m['name']) ?> (v<?= (int)($m['form_version'] ?? 1) ?>)
              </option>
            <?php endforeach; ?>
          </optgroup>
          <optgroup label="New Form">
            <option value="NEW">+ Create New Dynamic Form...</option>
          </optgroup>
        </select>

        <!-- Floating Custom Menu Dropdown Popover -->
        <div class="studio-form-menu-dropdown" id="formMenuDropdown" style="display:none;" role="menu">
          <div class="studio-menu-search-wrap">
            <span class="studio-menu-search-icon"><?= icon('search', 13) ?></span>
            <input type="text" class="studio-menu-search-input" id="formMenuSearchInput" placeholder="Search forms by name, key, or ID..." oninput="filterFormMenu(this.value)">
          </div>
          
          <div class="studio-menu-list" id="formMenuList">
            <?php 
            $metricsByCat = [];
            foreach ($allMetrics as $m) {
                $catName = $m['category'] ?: 'GENERAL';
                $metricsByCat[$catName][] = $m;
            }
            foreach ($metricsByCat as $catName => $catMetrics): 
            ?>
              <div class="studio-menu-group-header"><?= e(metric_format_cat($catName)) ?></div>
              <?php foreach ($catMetrics as $m): 
                $isActive = ($initialMetric && $initialMetric['id'] === $m['id']);
              ?>
                <div class="studio-menu-item <?= $isActive ? 'active' : '' ?>" 
                     data-id="<?= (int)$m['id'] ?>"
                     data-name="<?= e(strtolower($m['name'])) ?>"
                     data-key="<?= e(strtolower($m['metric_key'])) ?>"
                     onclick="selectFormFromMenu(<?= (int)$m['id'] ?>)"
                     role="menuitem">
                  <div class="studio-menu-item-left">
                    <span class="studio-menu-item-id">#<?= (int)$m['id'] ?></span>
                    <div class="studio-menu-item-info">
                      <div class="studio-menu-item-title"><?= e($m['name']) ?></div>
                      <div class="studio-menu-item-sub">
                        <span class="studio-menu-item-key"><?= e($m['metric_key']) ?></span>
                      </div>
                    </div>
                  </div>
                  <div class="studio-menu-item-right">
                    <span class="studio-menu-item-ver">v<?= (int)($m['form_version'] ?? 1) ?></span>
                    <?php if ($isActive): ?>
                      <span class="studio-menu-item-check"><?= icon('check', 13) ?></span>
                    <?php endif; ?>
                  </div>
                </div>
              <?php endforeach; ?>
            <?php endforeach; ?>
          </div>

          <div class="studio-menu-footer">
            <button type="button" class="studio-menu-new-btn" onclick="openNewFormModalFromMenu()">
              <?= icon('plus', 13) ?> <span>Create New Dynamic Form...</span>
            </button>
          </div>
        </div>
      </div>

      <!-- Hidden input preserved for script compatibility -->
      <input type="hidden" id="formNameInput" value="<?= e($initialMetric['name'] ?? 'Custom Form') ?>">

      <!-- Stable Metadata Badges -->
      <div class="studio-tb-badges">
        <span class="studio-status-pill published" id="formVersionPill" title="Published live version">v<?= (int)($initialMetric['form_version'] ?? 1) ?></span>
        <code class="studio-meta-pill" id="formKeyPill" title="Canonical metric key"><?= e($initialMetric['metric_key'] ?? '') ?></code>
        <span class="studio-status-pill draft" id="draftBadge" style="display:none;" title="Unpublished changes pending in draft"><?= icon('alert-circle', 11) ?> Draft</span>
        <span class="studio-status-pill protected" id="protectedBadge" style="display:none;" title="Core institutional metric"><?= icon('shield', 11) ?> Core</span>
      </div>
    </div>

    <!-- Center: Live Save State & Workspace Tools -->
    <div class="studio-tb-center">
      <!-- Live Save State Indicator -->
      <div class="save-indicator-badge saved" id="saveIndicator" title="Real-time save status">
        <span class="save-dot"></span>
        <span id="saveIndicatorText">Saved</span>
      </div>

      <!-- Undo / Redo Group -->
      <div class="studio-history-btn-group" role="group" aria-label="Undo and Redo">
        <button type="button" class="studio-icon-btn" id="btnUndo" onclick="undoStudioAction()" title="Undo action (Ctrl+Z)">
          <?= icon('rotate-ccw', 13) ?>
        </button>
        <button type="button" class="studio-icon-btn" id="btnRedo" onclick="redoStudioAction()" title="Redo action (Ctrl+Y)">
          <?= icon('rotate-cw', 13) ?>
        </button>
      </div>

      <!-- Viewport Device Switcher -->
      <div class="viewport-switch-group" role="group" aria-label="Canvas viewport switcher">
        <button type="button" class="viewport-btn active" id="btnVpDesktop" onclick="setCanvasViewport('desktop')" title="Desktop View (100% width)">
          <?= icon('monitor', 13) ?>
        </button>
        <button type="button" class="viewport-btn" id="btnVpTablet" onclick="setCanvasViewport('tablet')" title="Tablet View (720px width)">
          <?= icon('tablet', 13) ?>
        </button>
        <button type="button" class="viewport-btn" id="btnVpMobile" onclick="setCanvasViewport('mobile')" title="Mobile View (375px width)">
          <?= icon('smartphone', 13) ?>
        </button>
      </div>
    </div>

    <!-- Right: Lifecycle Actions (Versions, Save Draft, Publish) -->
    <div class="studio-tb-right">
      <button type="button" class="btn-studio-history" onclick="openVersionHistoryModal()" title="View Version Snapshots and Restore Previous Configurations">
        <?= icon('history', 13) ?> <span>Versions</span>
      </button>

      <button type="button" class="btn-studio-draft" onclick="saveDraft()" id="btnSaveDraftAction" title="Save Draft (Ctrl+S)">
        <?= icon('save', 13) ?> <span>Save Draft</span>
      </button>

      <button type="button" class="btn-studio-publish" onclick="publishConfiguration()" id="btnPublishAction" title="Publish Configuration to Respondents">
        <?= icon('check-circle', 14) ?> <span>Publish Form</span>
      </button>
    </div>
  </header>

  <!-- 2. MAIN 3-PANEL WORKSPACE GRID -->
  <main class="studio-main-grid">
    <!-- PANEL 1: LEFT FIELD PALETTE & LIBRARIES -->
    <section class="studio-panel-left">
      <div class="panel-tab-strip">
        <button type="button" class="panel-tab-btn active" id="tabBtnPalette" onclick="switchLeftTab('palette')">Field Palette</button>
        <button type="button" class="panel-tab-btn" id="tabBtnReusable" onclick="switchLeftTab('reusable')">Reusable (<?= count($reusableFields) ?>)</button>
        <button type="button" class="panel-tab-btn" id="tabBtnOptionSets" onclick="switchLeftTab('options')">Option Sets</button>
      </div>

      <!-- Search in Palette -->
      <div class="palette-search-wrap">
        <input type="text" class="palette-search-input" id="paletteSearchInput" placeholder="Search fields or elements..." oninput="filterPalette(this.value)">
      </div>

      <!-- Scrollable Tab Content 1: Categorized Palette -->
      <div class="panel-scroll-content" id="panePalette">
        <!-- 1. Basic Fields -->
        <div class="palette-category" data-cat="basic">
          <div class="category-group-title"><span>Standard Inputs</span> <span class="badge" style="font-size:10px;">11</span></div>
          <div class="palette-items-list">
            <div class="palette-item-card" draggable="true" ondragstart="onPaletteDragStart(event, 'text')" onclick="addFieldFromPalette('text')">
              <div class="palette-item-icon"><?= icon('type', 14) ?></div>
              <div class="palette-item-text"><div class="palette-item-title">Short Text</div><div class="palette-item-desc">Single-line text, titles, codes</div></div>
            </div>
            <div class="palette-item-card" draggable="true" ondragstart="onPaletteDragStart(event, 'textarea')" onclick="addFieldFromPalette('textarea')">
              <div class="palette-item-icon"><?= icon('align-left', 14) ?></div>
              <div class="palette-item-text"><div class="palette-item-title">Long Text / Textarea</div><div class="palette-item-desc">Descriptions, detailed notes</div></div>
            </div>
            <div class="palette-item-card" draggable="true" ondragstart="onPaletteDragStart(event, 'integer')" onclick="addFieldFromPalette('integer')">
              <div class="palette-item-icon"><?= icon('hash', 14) ?></div>
              <div class="palette-item-text"><div class="palette-item-title">Integer Number</div><div class="palette-item-desc">Counts, scores, participants</div></div>
            </div>
            <div class="palette-item-card" draggable="true" ondragstart="onPaletteDragStart(event, 'decimal')" onclick="addFieldFromPalette('decimal')">
              <div class="palette-item-icon"><?= icon('percent', 14) ?></div>
              <div class="palette-item-text"><div class="palette-item-title">Decimal / Amount</div><div class="palette-item-desc">CGPA, Lakhs, percentages</div></div>
            </div>
            <div class="palette-item-card" draggable="true" ondragstart="onPaletteDragStart(event, 'email')" onclick="addFieldFromPalette('email')">
              <div class="palette-item-icon"><?= icon('mail', 14) ?></div>
              <div class="palette-item-text"><div class="palette-item-title">Email Address</div><div class="palette-item-desc">Valid email validation</div></div>
            </div>
            <div class="palette-item-card" draggable="true" ondragstart="onPaletteDragStart(event, 'tel')" onclick="addFieldFromPalette('tel')">
              <div class="palette-item-icon"><?= icon('phone', 14) ?></div>
              <div class="palette-item-text"><div class="palette-item-title">Telephone / Mobile</div><div class="palette-item-desc">Contact numbers</div></div>
            </div>
            <div class="palette-item-card" draggable="true" ondragstart="onPaletteDragStart(event, 'url')" onclick="addFieldFromPalette('url')">
              <div class="palette-item-icon"><?= icon('link', 14) ?></div>
              <div class="palette-item-text"><div class="palette-item-title">URL Link</div><div class="palette-item-desc">Website links, DOI, repos</div></div>
            </div>
            <div class="palette-item-card" draggable="true" ondragstart="onPaletteDragStart(event, 'date')" onclick="addFieldFromPalette('date')">
              <div class="palette-item-icon"><?= icon('calendar', 14) ?></div>
              <div class="palette-item-text"><div class="palette-item-title">Date Picker</div><div class="palette-item-desc">Event date, completion date</div></div>
            </div>
            <div class="palette-item-card" draggable="true" ondragstart="onPaletteDragStart(event, 'time')" onclick="addFieldFromPalette('time')">
              <div class="palette-item-icon"><?= icon('clock', 14) ?></div>
              <div class="palette-item-text"><div class="palette-item-title">Time Picker</div><div class="palette-item-desc">Session start / end time</div></div>
            </div>
            <div class="palette-item-card" draggable="true" ondragstart="onPaletteDragStart(event, 'datetime')" onclick="addFieldFromPalette('datetime')">
              <div class="palette-item-icon"><?= icon('calendar', 14) ?></div>
              <div class="palette-item-text"><div class="palette-item-title">Date &amp; Time</div><div class="palette-item-desc">Timestamp, meeting slot</div></div>
            </div>
            <div class="palette-item-card" draggable="true" ondragstart="onPaletteDragStart(event, 'hidden_field')" onclick="addFieldFromPalette('hidden_field')">
              <div class="palette-item-icon"><?= icon('eye-off', 14) ?></div>
              <div class="palette-item-text"><div class="palette-item-title">Hidden Field</div><div class="palette-item-desc">System pass-through parameter</div></div>
            </div>
          </div>
        </div>

        <!-- 2. Choice Fields -->
        <div class="palette-category" data-cat="choice">
          <div class="category-group-title"><span>Choice &amp; Selection</span> <span class="badge" style="font-size:10px;">5</span></div>
          <div class="palette-items-list">
            <div class="palette-item-card" draggable="true" ondragstart="onPaletteDragStart(event, 'select')" onclick="addFieldFromPalette('select')">
              <div class="palette-item-icon"><?= icon('list', 14) ?></div>
              <div class="palette-item-text"><div class="palette-item-title">Dropdown / Select</div><div class="palette-item-desc">Single option from list</div></div>
            </div>
            <div class="palette-item-card" draggable="true" ondragstart="onPaletteDragStart(event, 'radio')" onclick="addFieldFromPalette('radio')">
              <div class="palette-item-icon"><?= icon('disc', 14) ?></div>
              <div class="palette-item-text"><div class="palette-item-title">Radio Buttons</div><div class="palette-item-desc">Mutually exclusive options</div></div>
            </div>
            <div class="palette-item-card" draggable="true" ondragstart="onPaletteDragStart(event, 'checkbox')" onclick="addFieldFromPalette('checkbox')">
              <div class="palette-item-icon"><?= icon('check', 14) ?></div>
              <div class="palette-item-text"><div class="palette-item-title">Checkbox Toggle</div><div class="palette-item-desc">Boolean true/false state</div></div>
            </div>
            <div class="palette-item-card" draggable="true" ondragstart="onPaletteDragStart(event, 'yes_no')" onclick="addFieldFromPalette('yes_no')">
              <div class="palette-item-icon"><?= icon('check-circle', 14) ?></div>
              <div class="palette-item-text"><div class="palette-item-title">Yes / No Switch</div><div class="palette-item-desc">Binary affirmative toggle</div></div>
            </div>
            <div class="palette-item-card" draggable="true" ondragstart="onPaletteDragStart(event, 'multiselect')" onclick="addFieldFromPalette('multiselect')">
              <div class="palette-item-icon"><?= icon('check-square', 14) ?></div>
              <div class="palette-item-text"><div class="palette-item-title">Multi-Select</div><div class="palette-item-desc">Select multiple choices</div></div>
            </div>
          </div>
        </div>

        <!-- 3. Academic & Institutional Fields -->
        <div class="palette-category" data-cat="academic">
          <div class="category-group-title"><span>Academic &amp; Institutional</span> <span class="badge" style="font-size:10px;">13</span></div>
          <div class="palette-items-list">
            <div class="palette-item-card" draggable="true" ondragstart="onPaletteDragStart(event, 'academic_dept')" onclick="addFieldFromPalette('academic_dept')">
              <div class="palette-item-icon"><?= icon('building', 14) ?></div>
              <div class="palette-item-text"><div class="palette-item-title">Department</div><div class="palette-item-desc">Institutional department selector</div></div>
            </div>
            <div class="palette-item-card" draggable="true" ondragstart="onPaletteDragStart(event, 'academic_year')" onclick="addFieldFromPalette('academic_year')">
              <div class="palette-item-icon"><?= icon('calendar', 14) ?></div>
              <div class="palette-item-text"><div class="palette-item-title">Academic Year</div><div class="palette-item-desc">Target cycle e.g. 2025-26</div></div>
            </div>
            <div class="palette-item-card" draggable="true" ondragstart="onPaletteDragStart(event, 'academic_semester')" onclick="addFieldFromPalette('academic_semester')">
              <div class="palette-item-icon"><?= icon('graduation', 14) ?></div>
              <div class="palette-item-text"><div class="palette-item-title">Semester (1 to 8)</div><div class="palette-item-desc">Curriculum semester level</div></div>
            </div>
            <div class="palette-item-card" draggable="true" ondragstart="onPaletteDragStart(event, 'academic_batch')" onclick="addFieldFromPalette('academic_batch')">
              <div class="palette-item-icon"><?= icon('clock', 14) ?></div>
              <div class="palette-item-text"><div class="palette-item-title">Batch / Class</div><div class="palette-item-desc">e.g. 2022-2026 Batch</div></div>
            </div>
            <div class="palette-item-card" draggable="true" ondragstart="onPaletteDragStart(event, 'student_name')" onclick="addFieldFromPalette('student_name')">
              <div class="palette-item-icon"><?= icon('user', 14) ?></div>
              <div class="palette-item-text"><div class="palette-item-title">Student Name</div><div class="palette-item-desc">Enrolled student full name</div></div>
            </div>
            <div class="palette-item-card" draggable="true" ondragstart="onPaletteDragStart(event, 'register_number')" onclick="addFieldFromPalette('register_number')">
              <div class="palette-item-icon"><?= icon('hash', 14) ?></div>
              <div class="palette-item-text"><div class="palette-item-title">Register Number</div><div class="palette-item-desc">Unique university roll / reg no</div></div>
            </div>
            <div class="palette-item-card" draggable="true" ondragstart="onPaletteDragStart(event, 'activity_title')" onclick="addFieldFromPalette('activity_title')">
              <div class="palette-item-icon"><?= icon('target', 14) ?></div>
              <div class="palette-item-text"><div class="palette-item-title">Activity / Event Title</div><div class="palette-item-desc">Event or initiative title</div></div>
            </div>
            <div class="palette-item-card" draggable="true" ondragstart="onPaletteDragStart(event, 'company_name')" onclick="addFieldFromPalette('company_name')">
              <div class="palette-item-icon"><?= icon('briefcase', 14) ?></div>
              <div class="palette-item-text"><div class="palette-item-title">Company Name</div><div class="palette-item-desc">Hiring company or organization</div></div>
            </div>
            <div class="palette-item-card" draggable="true" ondragstart="onPaletteDragStart(event, 'company_type')" onclick="addFieldFromPalette('company_type')">
              <div class="palette-item-icon"><?= icon('layers', 14) ?></div>
              <div class="palette-item-text"><div class="palette-item-title">Company Type</div><div class="palette-item-desc">MNC, Core, IT, Startup, Govt</div></div>
            </div>
            <div class="palette-item-card" draggable="true" ondragstart="onPaletteDragStart(event, 'placement_status')" onclick="addFieldFromPalette('placement_status')">
              <div class="palette-item-icon"><?= icon('award', 14) ?></div>
              <div class="palette-item-text"><div class="palette-item-title">Placement Status</div><div class="palette-item-desc">Placed, Higher Studies, Entrepreneur</div></div>
            </div>
            <div class="palette-item-card" draggable="true" ondragstart="onPaletteDragStart(event, 'package_ctc')" onclick="addFieldFromPalette('package_ctc')">
              <div class="palette-item-icon"><?= icon('percent', 14) ?></div>
              <div class="palette-item-text"><div class="palette-item-title">Package / CTC (LPA)</div><div class="palette-item-desc">Salary or financial amount</div></div>
            </div>
            <div class="palette-item-card" draggable="true" ondragstart="onPaletteDragStart(event, 'placement_date')" onclick="addFieldFromPalette('placement_date')">
              <div class="palette-item-icon"><?= icon('calendar', 14) ?></div>
              <div class="palette-item-text"><div class="palette-item-title">Placement Date</div><div class="palette-item-desc">Offer or drive date</div></div>
            </div>
            <div class="palette-item-card" draggable="true" ondragstart="onPaletteDragStart(event, 'remarks')" onclick="addFieldFromPalette('remarks')">
              <div class="palette-item-icon"><?= icon('message-square', 14) ?></div>
              <div class="palette-item-text"><div class="palette-item-title">Remarks / Outcome</div><div class="palette-item-desc">Quality impact and outcomes</div></div>
            </div>
          </div>
        </div>

        <!-- 4. File Upload Fields -->
        <div class="palette-category" data-cat="uploads">
          <div class="category-group-title"><span>File Upload Controls</span> <span class="badge" style="font-size:10px;">5</span></div>
          <div class="palette-items-list">
            <div class="palette-item-card" draggable="true" ondragstart="onPaletteDragStart(event, 'file_single')" onclick="addFieldFromPalette('file_single')">
              <div class="palette-item-icon"><?= icon('file', 14) ?></div>
              <div class="palette-item-text"><div class="palette-item-title">Single Document File</div><div class="palette-item-desc">One supporting document</div></div>
            </div>
            <div class="palette-item-card" draggable="true" ondragstart="onPaletteDragStart(event, 'file_multi')" onclick="addFieldFromPalette('file_multi')">
              <div class="palette-item-icon"><?= icon('files', 14) ?></div>
              <div class="palette-item-text"><div class="palette-item-title">Multiple Files Upload</div><div class="palette-item-desc">Up to 10 supporting documents</div></div>
            </div>
            <div class="palette-item-card" draggable="true" ondragstart="onPaletteDragStart(event, 'file_pdf')" onclick="addFieldFromPalette('file_pdf')">
              <div class="palette-item-icon"><?= icon('file-text', 14) ?></div>
              <div class="palette-item-text"><div class="palette-item-title">PDF Document Only</div><div class="palette-item-desc">Strictly verified PDF files</div></div>
            </div>
            <div class="palette-item-card" draggable="true" ondragstart="onPaletteDragStart(event, 'file_evidence')" onclick="addFieldFromPalette('file_evidence')">
              <div class="palette-item-icon"><?= icon('award', 14) ?></div>
              <div class="palette-item-text"><div class="palette-item-title">Supporting Attachment</div><div class="palette-item-desc">Sanction letters, reports, orders</div></div>
            </div>
            <div class="palette-item-card" draggable="true" ondragstart="onPaletteDragStart(event, 'file_image')" onclick="addFieldFromPalette('file_image')">
              <div class="palette-item-icon"><?= icon('image', 14) ?></div>
              <div class="palette-item-text"><div class="palette-item-title">Image / Geo-Tag Photo</div><div class="palette-item-desc">Event photos, certificates</div></div>
            </div>
          </div>
        </div>

        <!-- 5. Advanced & Structured Fields -->
        <div class="palette-category" data-cat="advanced">
          <div class="category-group-title"><span>Advanced &amp; Structured</span> <span class="badge" style="font-size:10px;">4</span></div>
          <div class="palette-items-list">
            <div class="palette-item-card" draggable="true" ondragstart="onPaletteDragStart(event, 'table_grid')" onclick="addFieldFromPalette('table_grid')">
              <div class="palette-item-icon"><?= icon('grid', 14) ?></div>
              <div class="palette-item-text"><div class="palette-item-title">Table-Style Grid</div><div class="palette-item-desc">Itemized multi-row data entry</div></div>
            </div>
            <div class="palette-item-card" draggable="true" ondragstart="onPaletteDragStart(event, 'acknowledgement')" onclick="addFieldFromPalette('acknowledgement')">
              <div class="palette-item-icon"><?= icon('check-circle', 14) ?></div>
              <div class="palette-item-text"><div class="palette-item-title">Acknowledgement Check</div><div class="palette-item-desc">Respondent legal/IQAC declaration</div></div>
            </div>
            <div class="palette-item-card" draggable="true" ondragstart="onPaletteDragStart(event, 'readonly')" onclick="addFieldFromPalette('readonly')">
              <div class="palette-item-icon"><?= icon('lock', 14) ?></div>
              <div class="palette-item-text"><div class="palette-item-title">Read-Only / Calculated</div><div class="palette-item-desc">System computed value</div></div>
            </div>
            <div class="palette-item-card" draggable="true" ondragstart="onPaletteDragStart(event, 'specialized')" onclick="addFieldFromPalette('specialized')">
              <div class="palette-item-icon"><?= icon('shield', 14) ?></div>
              <div class="palette-item-text"><div class="palette-item-title">Specialized Component</div><div class="palette-item-desc">Core institutional formula</div></div>
            </div>
          </div>
        </div>

        <!-- 6. Layout Elements -->
        <div class="palette-category" data-cat="layout">
          <div class="category-group-title"><span>Layout &amp; Instructions</span> <span class="badge" style="font-size:10px;">5</span></div>
          <div class="palette-items-list">
            <div class="palette-item-card" draggable="true" ondragstart="onPaletteDragStart(event, 'section_header')" onclick="addFieldFromPalette('section_header')">
              <div class="palette-item-icon"><?= icon('folder', 14) ?></div>
              <div class="palette-item-text"><div class="palette-item-title">Section Heading</div><div class="palette-item-desc">Dividing heading &amp; description</div></div>
            </div>
            <div class="palette-item-card" draggable="true" ondragstart="onPaletteDragStart(event, 'instructions')" onclick="addFieldFromPalette('instructions')">
              <div class="palette-item-icon"><?= icon('info', 14) ?></div>
              <div class="palette-item-text"><div class="palette-item-title">Description / Guidelines</div><div class="palette-item-desc">Helpful instructions banner</div></div>
            </div>
            <div class="palette-item-card" draggable="true" ondragstart="onPaletteDragStart(event, 'divider')" onclick="addFieldFromPalette('divider')">
              <div class="palette-item-icon"><?= icon('minus', 14) ?></div>
              <div class="palette-item-text"><div class="palette-item-title">Visual Divider</div><div class="palette-item-desc">Clean horizontal separator</div></div>
            </div>
            <div class="palette-item-card" draggable="true" ondragstart="onPaletteDragStart(event, 'section_container')" onclick="addFieldFromPalette('section_container')">
              <div class="palette-item-icon"><?= icon('box', 14) ?></div>
              <div class="palette-item-text"><div class="palette-item-title">Section Container</div><div class="palette-item-desc">Bordered content group</div></div>
            </div>
            <div class="palette-item-card" draggable="true" ondragstart="onPaletteDragStart(event, 'page_break')" onclick="addFieldFromPalette('page_break')">
              <div class="palette-item-icon"><?= icon('book', 14) ?></div>
              <div class="palette-item-text"><div class="palette-item-title">Page Break</div><div class="palette-item-desc">Multi-step wizard boundary</div></div>
            </div>
          </div>
        </div>
      </div>

      <!-- Scrollable Tab Content 2: Reusable Fields -->
      <div class="panel-scroll-content" id="paneReusable" style="display:none;">
        <div style="font-size:11.5px;color:#64748B;line-height:1.4;">Insert pre-built institutional fields with standardized validation.</div>
        <div id="reusableListContainer" style="display:flex;flex-direction:column;gap:8px;margin-top:8px;">
          <!-- Dynamically populated -->
        </div>
      </div>

      <!-- Scrollable Tab Content 3: Shared Option Sets -->
      <div class="panel-scroll-content" id="paneOptionSets" style="display:none;">
        <div style="display:flex;align-items:center;justify-content:space-between;">
          <span style="font-size:12px;font-weight:700;color:var(--studio-navy);">Shared Option Sets</span>
          <button type="button" class="btn btn-sm btn-outline" onclick="openNewOptionSetModal()" style="font-size:11px;padding:2px 8px;">+ New Set</button>
        </div>
        <div id="optionSetsListContainer" style="display:flex;flex-direction:column;gap:8px;margin-top:8px;">
          <!-- Dynamically populated -->
        </div>
      </div>
    </section>

    <!-- PANEL 2: CENTER LIVE CANVAS -->
    <section class="studio-panel-center" id="canvasScrollArea" ondragover="onCanvasDragOver(event)" ondrop="onCanvasDrop(event)">
      <div class="canvas-viewport-wrapper" id="canvasViewportWrapper">
        <!-- Form Header Card (Clickable to edit form settings) -->
        <div class="canvas-form-header-card" id="canvasFormHeader" onclick="selectFormSettings()">
          <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;">
            <div>
              <h2 class="canvas-header-title" id="canvasTitleDisplay"><?= e($initialMetric['name'] ?? 'Form Title') ?></h2>
              <p class="canvas-header-desc" id="canvasDescDisplay"><?= e($initialMetric['description'] ?: 'Click here to configure form settings, instructions, and role assignments.') ?></p>
            </div>
            <button type="button" class="btn btn-sm btn-ghost" style="color:var(--studio-orange);font-size:11.5px;font-weight:700;">
              <?= icon('sliders', 13) ?> Settings
            </button>
          </div>

          <div class="canvas-header-meta">
            <span class="badge badge-neutral" id="canvasCatBadge"><?= e($initialMetric['category'] ?? 'General') ?></span>
            <span class="badge badge-info" id="canvasRoleFacultyBadge">Faculty</span>
            <span class="badge badge-info" id="canvasRoleCoordBadge">Coordinator</span>
            <span class="badge badge-info" id="canvasRoleHodBadge">HoD</span>
            <span class="badge badge-info" id="canvasRolePrincipalBadge">Principal</span>
          </div>
        </div>

        <!-- Quick Layout Templates Strip -->
        <div class="canvas-quick-templates-strip" id="quickTemplatesStrip">
          <span style="font-size:11.5px;font-weight:800;color:var(--studio-navy);margin-right:4px;">⚡ Quick Layouts:</span>
          <button type="button" class="btn-quick-tpl" onclick="applyPlacementTemplate()" title="1-click: 2-column Student Placement layout with 11 standard fields + offer letter proof">
            <?= icon('award', 13) ?> Placement Drive Form
          </button>
          <button type="button" class="btn-quick-tpl" onclick="applyFacultyActivityTemplate()" title="1-click: Faculty Development / Workshop Activity layout">
            <?= icon('users', 13) ?> Faculty Activity / FDP
          </button>
          <button type="button" class="btn-quick-tpl" onclick="applyPublicationTemplate()" title="1-click: Research Publication &amp; Journal form">
            <?= icon('book', 13) ?> Publication / Journal
          </button>
          <div style="flex:1;"></div>
          <button type="button" class="btn-quick-tpl" onclick="addFieldFromPalette('section_header')" style="background:#FFF7ED;border-color:#FDBA74;color:#C2410C;">
            <?= icon('plus', 12) ?> Add Section
          </button>
        </div>

        <!-- Section / Fields Container (Surface + 12-Column Responsive Grid) -->
        <div class="canvas-form-surface" id="canvasFormSurface" style="position:relative;">
          <div class="canvas-grid-guides-overlay" aria-hidden="true">
            <div class="grid-guide-col"></div>
            <div class="grid-guide-col"></div>
            <div class="grid-guide-col"></div>
            <div class="grid-guide-col"></div>
            <div class="grid-guide-col"></div>
            <div class="grid-guide-col"></div>
            <div class="grid-guide-col"></div>
            <div class="grid-guide-col"></div>
            <div class="grid-guide-col"></div>
            <div class="grid-guide-col"></div>
            <div class="grid-guide-col"></div>
            <div class="grid-guide-col"></div>
          </div>
          <div class="canvas-form-grid" id="canvasSectionsContainer">
            <!-- Dynamically generated 12-column grid of realistic field cards -->
          </div>
        </div>

        <!-- Anchored Mandatory Proof Attachment Card -->
        <div class="canvas-proof-card" id="canvasProofCard" onclick="selectProofSettings()">
          <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;">
            <div style="display:flex;align-items:center;gap:10px;">
              <div style="width:36px;height:36px;border-radius:8px;background:#F0FDF4;color:#16A34A;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                <?= icon('upload-cloud', 20) ?>
              </div>
              <div>
                <div style="font-size:14px;font-weight:700;color:var(--studio-navy);" id="proofCardLabel">
                  Proof / Attachment <span style="color:#DC2626;">*</span>
                </div>
                <div style="font-size:11.5px;color:#64748B;" id="proofCardHelpText">
                  Official supporting document verification.
                </div>
              </div>
            </div>
            <button type="button" class="btn btn-sm btn-ghost" style="color:#16A34A;font-size:11.5px;font-weight:700;">
              <?= icon('sliders', 13) ?> Configure
            </button>
          </div>

          <!-- Realistic Dropzone Mockup for Proof Card -->
          <div class="canvas-proof-dropzone-box" style="margin-top:12px;pointer-events:none;">
            <div class="dropzone-icon-circle"><?= icon('upload-cloud', 22) ?></div>
            <div class="dropzone-prompt">Drag &amp; drop supporting file here, or click to browse</div>
            <div class="dropzone-restrictions" id="proofSummaryText">PDF only · Maximum 2 MB per file · Up to 1 file</div>
          </div>
        </div>
      </div>
    </section>

    <!-- PANEL 3: RIGHT DYNAMIC PROPERTIES INSPECTOR & LIVE PREVIEW -->
    <aside class="studio-panel-right">
      <div class="inspector-tab-strip">
        <button type="button" class="inspector-tab-btn active" id="tabBtnInspector" onclick="switchRightTab('inspector')">
          <?= icon('sliders', 12) ?> Properties Inspector
        </button>
        <button type="button" class="inspector-tab-btn" id="tabBtnPreview" onclick="switchRightTab('preview')">
          <?= icon('eye', 12) ?> Interactive Preview
        </button>
      </div>

      <!-- Tab 1: Inspector Pane (Field, Section, Form Settings, or Proof) -->
      <div class="inspector-scroll-pane" id="paneInspector">
        <div id="inspectorContent">
          <!-- Dynamically populated by JS based on active selection -->
        </div>
      </div>

      <!-- Tab 2: Interactive Live Preview Pane -->
      <div class="inspector-scroll-pane" id="panePreview" style="display:none;background:#F8FAFC;">
        <div style="background:#FFFFFF;border:1px solid var(--studio-border);border-radius:8px;padding:12px;margin-bottom:12px;">
          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
            <label style="font-size:11.5px;font-weight:700;color:#334155;">Simulate Role:</label>
            <select class="select" id="previewRoleSelect" onchange="updatePreviewRole(this.value)" style="height:28px;font-size:11.5px;width:130px;">
              <option value="Faculty">Faculty</option>
              <option value="Coordinator">Coordinator</option>
              <option value="HoD">HoD</option>
              <option value="Principal">Principal</option>
            </select>
          </div>
          <div style="font-size:11px;color:#64748B;">
            Test real-time validation and conditional logic before publishing.
          </div>
        </div>

        <div id="interactivePreviewContainer" style="display:flex;flex-direction:column;gap:12px;">
          <!-- Dynamically populated interactive form -->
        </div>

        <div style="margin-top:16px;">
          <button type="button" class="btn btn-primary btn-block" onclick="testPreviewSubmission()" style="font-size:12.5px;font-weight:700;">
            <?= icon('check-circle', 14) ?> Test Form Submission
          </button>
        </div>
      </div>
    </aside>
  </main>
</div>

<!-- ========================================================================= -->
<!-- MODAL: VERSION HISTORY & RESTORE                                          -->
<!-- ========================================================================= -->
<dialog class="modal" id="versionHistoryModal" style="max-width:38rem;width:95vw;border-radius:12px;">
  <div class="modal-head" style="padding:16px 20px;border-bottom:1px solid #E2E8F0;display:flex;align-items:center;justify-content:space-between;">
    <div>
      <h3 style="margin:0;font-size:16px;font-weight:700;color:var(--studio-navy);">Form Version History</h3>
      <div style="font-size:12px;color:#64748B;">Audit trail of immutable published versions and rollback options.</div>
    </div>
    <button type="button" class="btn-ghost" onclick="document.getElementById('versionHistoryModal').close()">&times;</button>
  </div>
  <div class="modal-body" style="padding:16px 20px;max-height:60vh;overflow-y:auto;" id="versionHistoryList">
    <!-- Populated by JS -->
  </div>
</dialog>

<!-- ========================================================================= -->
<!-- MODAL: CREATE NEW FORM                                                    -->
<!-- ========================================================================= -->
<dialog class="modal" id="newFormModal" style="max-width:34rem;width:95vw;border-radius:12px;">
  <form method="post" action="<?= e(url('form-studio.php')) ?>" onsubmit="return handleCreateNewForm(event)">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="create_form">

    <div class="modal-head" style="padding:16px 20px;border-bottom:1px solid #E2E8F0;">
      <h3 style="margin:0;font-size:16px;font-weight:700;color:var(--studio-navy);">Create New Dynamic Form</h3>
      <div style="font-size:12px;color:#64748B;margin-top:2px;">Build a custom academic target or document collection form.</div>
    </div>

    <div class="modal-body" style="padding:16px 20px;display:flex;flex-direction:column;gap:12px;">
      <div class="field">
        <label>Form Title <span class="req">*</span></label>
        <input class="input" type="text" name="name" id="newFormTitleInput" placeholder="e.g. Student Research Grant Application" required oninput="suggestMetricKey(this.value)">
      </div>

      <div class="field">
        <label>Form Key / Code <span class="req">*</span> <span class="card-sub">(Unique identifier)</span></label>
        <input class="input" type="text" name="metric_key" id="newFormKeyInput" placeholder="e.g. student_research_grants" required pattern="[a-z0-9_]+" title="Lower-case letters, numbers, and underscores only.">
      </div>

      <div class="field">
        <label>Category / Pillar <span class="req">*</span></label>
        <select class="select" name="category" required>
          <?php foreach ($categories as $cat): ?>
            <option value="<?= e($cat) ?>"><?= e(metric_format_cat($cat)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="field">
        <label>Description / Scope</label>
        <textarea class="input" name="description" placeholder="Briefly describe what records this form captures..." rows="2"></textarea>
      </div>

      <div class="field">
        <label>Authorized Roles <span class="card-sub">(Assign to respondents)</span></label>
        <div class="role-checkbox-grid">
          <label class="role-checkbox-item"><input type="checkbox" name="faculty_visible" value="1" checked> Faculty</label>
          <label class="role-checkbox-item"><input type="checkbox" name="coordinator_visible" value="1" checked> Coordinator</label>
          <label class="role-checkbox-item"><input type="checkbox" name="hod_visible" value="1" checked> HoD</label>
          <label class="role-checkbox-item"><input type="checkbox" name="principal_visible" value="1" checked> Principal</label>
        </div>
      </div>
    </div>

    <div class="modal-foot" style="padding:12px 20px;border-top:1px solid #E2E8F0;display:flex;justify-content:flex-end;gap:8px;">
      <button type="button" class="btn btn-ghost" onclick="document.getElementById('newFormModal').close()">Cancel</button>
      <button type="submit" class="btn btn-primary" style="background:var(--studio-orange);border:none;font-weight:700;">
        <?= icon('plus', 14) ?> Create &amp; Open in Studio
      </button>
    </div>
  </form>
</dialog>

<!-- ========================================================================= -->
<!-- MODAL: CREATE SHARED OPTION SET                                           -->
<!-- ========================================================================= -->
<dialog class="modal" id="newOptionSetModal" style="max-width:32rem;width:95vw;border-radius:12px;">
  <div class="modal-head" style="padding:16px 20px;border-bottom:1px solid #E2E8F0;">
    <h3 style="margin:0;font-size:16px;font-weight:700;color:var(--studio-navy);">Create Shared Option Set</h3>
    <div style="font-size:12px;color:#64748B;">Re-usable choice options for dropdowns, radios, and checkboxes across all forms.</div>
  </div>
  <div class="modal-body" style="padding:16px 20px;display:flex;flex-direction:column;gap:12px;">
    <div class="field">
      <label>Option Set Name <span class="req">*</span></label>
      <input class="input" type="text" id="newOptSetName" placeholder="e.g. Funding Agency Classification" required>
    </div>
    <div class="field">
      <label>Identifier Key <span class="req">*</span></label>
      <input class="input" type="text" id="newOptSetKey" placeholder="e.g. opt_funding_agency" required>
    </div>
    <div class="field">
      <label>Options (one value per line)</label>
      <textarea class="input" id="newOptSetLines" rows="5" placeholder="Government / AICTE&#10;DST / SERB&#10;Industry Sponsored&#10;Internal Institutional Seed Grant"></textarea>
    </div>
  </div>
  <div class="modal-foot" style="padding:12px 20px;border-top:1px solid #E2E8F0;display:flex;justify-content:flex-end;gap:8px;">
    <button type="button" class="btn btn-ghost" onclick="document.getElementById('newOptionSetModal').close()">Cancel</button>
    <button type="button" class="btn btn-primary" onclick="submitNewOptionSet()" style="background:var(--studio-orange);border:none;font-weight:700;">Save Option Set</button>
  </div>
</dialog>

<!-- Load Complete Form Studio JavaScript Engine -->
<script src="<?= e(url('assets/js/form-studio.js')) ?>"></script>

<script>
// Bootstrap Studio with Initial Selected Metric
document.addEventListener('DOMContentLoaded', function () {
  var initialId = <?= json_encode((int)($initialMetric['id'] ?? 0)) ?>;
  var action = <?= json_encode(input('action', '')) ?>;
  if (initialId > 0) {
    loadFormStudioConfig(initialId);
  }
  if (action === 'new') {
    var modal = document.getElementById('newFormModal');
    if (modal && typeof modal.showModal === 'function') {
      modal.showModal();
    }
  }
});
</script>

<?php require __DIR__ . '/inc/footer.php'; ?>
