/**
 * ATTS-IQAC — Form Studio Workspace Engine
 * Full visual visual form builder with drag-and-drop, categorized field palette,
 * dynamic properties inspector, live interactive preview, and immutable versioning.
 */

(function (window, document) {
  'use strict';

  // Studio Application State
  var studioState = {
    metricId: null,
    metric: {
      id: 0,
      name: 'Custom Form',
      metric_key: 'custom_form',
      category: 'QUALITY INITIATIVES AND COMPLIANCE',
      faculty_visible: 1,
      coordinator_visible: 1,
      hod_visible: 1,
      principal_visible: 1,
      status: 1,
      form_version: 1,
      metric_type: 'generic'
    },
    fields: [],
    attachment: {
      enabled: true,
      label: 'Proof / Attachment',
      required: true,
      preset: 'pdf_only',
      allowed_types: ['pdf'],
      max_file_mb: 2,
      max_files: 1,
      min_files: 1,
      help_text: 'PDF document strictly 2 MB or less.'
    },
    optionSets: [],
    reusableFields: [],
    history: [],
    allMetrics: [],
    selectedIdx: 'header', // 'header', 'proof', or integer field index
    isDirty: false,
    undoStack: [],
    redoStack: [],
    viewport: 'desktop', // 'desktop', 'tablet', 'mobile'
    previewRole: 'Faculty' // 'Faculty', 'Coordinator', 'HoD', 'Principal'
  };

  // Expose state for debugging/testing
  window.studioState = studioState;

  // ---------------------------------------------------------------------------
  // 1. Initial Loading & Config Fetching
  // ---------------------------------------------------------------------------
  function loadFormStudioConfig(metricId) {
    if (!metricId) return;

    updateSaveIndicator('saving', 'Loading form...');
    var url = 'form-studio.php?ajax=1&action=get_form_config&id=' + encodeURIComponent(metricId);

    fetch(url, {
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(function (res) { return res.json(); })
    .then(function (data) {
      if (!data || !data.success) {
        alert(data && data.message ? data.message : 'Failed to load form configuration.');
        updateSaveIndicator('failed', 'Load failed');
        return;
      }

      var cfg = data.config || {};
      studioState.metricId = metricId;
      studioState.metric = cfg.metric || studioState.metric;
      studioState.fields = Array.isArray(cfg.fields) ? JSON.parse(JSON.stringify(cfg.fields)) : [];
      studioState.attachment = cfg.attachment || studioState.attachment;
      studioState.optionSets = data.option_sets || [];
      studioState.reusableFields = data.reusable_fields || [];
      studioState.history = data.history || [];
      studioState.allMetrics = data.all_metrics || [];
      studioState.isDirty = false;
      studioState.undoStack = [];
      studioState.redoStack = [];

      // If no fields, seed a default Title field
      if (studioState.fields.length === 0) {
        studioState.fields.push({
          key: 'activity_title',
          label: 'Activity / Initiative Title',
          type: 'text',
          placeholder: 'Enter title or name...',
          help_text: 'Primary title or identifier',
          required: true,
          faculty_editable: true,
          coordinator_editable: true,
          hod_editable: true,
          principal_editable: true
        });
      }

      // Update Topbar UI
      updateTopbarUI();

      // Render All Panels
      renderCanvas();
      renderReusableList();
      renderOptionSetsList();
      renderInspector();
      renderInteractivePreview();

      updateSaveIndicator('saved', 'Saved');
    })
    .catch(function (err) {
      console.error('Studio load error:', err);
      updateSaveIndicator('failed', 'Connection error');
    });
  }

  function updateTopbarUI() {
    var m = studioState.metric;
    document.getElementById('formNameInput').value = m.name || '';
    document.getElementById('formKeyPill').textContent = m.metric_key || '';
    document.getElementById('formVersionPill').textContent = 'v' + (m.form_version || 1);

    var hasDraft = (m.form_config_draft && m.form_config_draft !== m.form_config);
    document.getElementById('draftBadge').style.display = hasDraft ? 'inline-flex' : 'none';

    var isSpecialized = (m.metric_type === 'specialized' || m.metric_type === 'hybrid');
    document.getElementById('protectedBadge').style.display = isSpecialized ? 'inline-flex' : 'none';

    // Canvas Header Meta
    document.getElementById('canvasTitleDisplay').textContent = m.name || 'Untitled Form';
    document.getElementById('canvasDescDisplay').textContent = m.description || 'Click here to configure form settings and access permissions.';
    document.getElementById('canvasCatBadge').textContent = m.category || 'General';

    document.getElementById('canvasRoleFacultyBadge').style.display = m.faculty_visible ? 'inline-flex' : 'none';
    document.getElementById('canvasRoleCoordBadge').style.display = m.coordinator_visible ? 'inline-flex' : 'none';
    document.getElementById('canvasRoleHodBadge').style.display = m.hod_visible ? 'inline-flex' : 'none';
    document.getElementById('canvasRolePrincipalBadge').style.display = m.principal_visible ? 'inline-flex' : 'none';

    var liveLink = document.getElementById('previewLiveUploadLink');
    if (liveLink && m.metric_key) {
      liveLink.href = 'upload.php?metric=' + encodeURIComponent(m.metric_key);
    }

    // Keep formSelector dropdown and custom menu label in sync
    var sel = document.getElementById('formSelector');
    if (sel && m.id) {
      sel.value = m.id;
    }
    var menuLabel = document.getElementById('formMenuCurrentLabel');
    if (menuLabel && m.id) {
      menuLabel.textContent = '#' + m.id + ' · ' + (m.name || 'Custom Form');
    }

    // Render / refresh custom menu list and formSelector options dynamically
    if (typeof renderFormMenuList === 'function') {
      renderFormMenuList();
    }

    // Keep URL in sync without full reload
    if (window.history && window.history.replaceState && m.id) {
      window.history.replaceState(null, '', 'form-studio.php?id=' + encodeURIComponent(m.id));
    }

    // Proof Summary
    updateProofSummaryDisplay();
  }

  // ---------------------------------------------------------------------------
  // 2. Undo / Redo History Stack & Keyboard Shortcuts
  // ---------------------------------------------------------------------------
  function updateUndoRedoButtons() {
    var uBtn = document.getElementById('btnUndo');
    var rBtn = document.getElementById('btnRedo');
    if (uBtn) uBtn.disabled = (studioState.undoStack.length === 0);
    if (rBtn) rBtn.disabled = (studioState.redoStack.length === 0);
  }

  function recordState() {
    var snapshot = {
      fields: JSON.parse(JSON.stringify(studioState.fields)),
      attachment: JSON.parse(JSON.stringify(studioState.attachment)),
      metric: JSON.parse(JSON.stringify(studioState.metric))
    };
    studioState.undoStack.push(snapshot);
    if (studioState.undoStack.length > 50) {
      studioState.undoStack.shift();
    }
    studioState.redoStack = [];
    updateUndoRedoButtons();
    markDirty();
  }

  function undoStudioAction() {
    if (studioState.undoStack.length === 0) return;
    var current = {
      fields: JSON.parse(JSON.stringify(studioState.fields)),
      attachment: JSON.parse(JSON.stringify(studioState.attachment)),
      metric: JSON.parse(JSON.stringify(studioState.metric))
    };
    studioState.redoStack.push(current);

    var prev = studioState.undoStack.pop();
    studioState.fields = prev.fields;
    studioState.attachment = prev.attachment;
    studioState.metric = prev.metric;

    renderCanvas();
    renderInspector();
    renderInteractivePreview();
    updateUndoRedoButtons();
    markDirty();
  }

  function redoStudioAction() {
    if (studioState.redoStack.length === 0) return;
    var current = {
      fields: JSON.parse(JSON.stringify(studioState.fields)),
      attachment: JSON.parse(JSON.stringify(studioState.attachment)),
      metric: JSON.parse(JSON.stringify(studioState.metric))
    };
    studioState.undoStack.push(current);

    var next = studioState.redoStack.pop();
    studioState.fields = next.fields;
    studioState.attachment = next.attachment;
    studioState.metric = next.metric;

    renderCanvas();
    renderInspector();
    renderInteractivePreview();
    updateUndoRedoButtons();
    markDirty();
  }

  function markDirty() {
    studioState.isDirty = true;
    updateSaveIndicator('dirty', 'Unsaved changes');
  }

  function updateSaveIndicator(status, text) {
    var ind = document.getElementById('saveIndicator');
    var txt = document.getElementById('saveIndicatorText');
    if (!ind || !txt) return;

    ind.className = 'save-indicator-badge ' + status;
    txt.textContent = text || (status === 'saved' ? 'Saved' : (status === 'saving' ? 'Saving...' : 'Unsaved changes'));
  }

  // Global Keyboard Shortcuts (Ctrl+Z, Ctrl+Y, Ctrl+Shift+Z, Ctrl+S)
  window.addEventListener('keydown', function (e) {
    if (e.target && (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') && e.target.type !== 'range') {
      // Allow native undo/redo inside text inputs unless Ctrl+S
      if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') {
        e.preventDefault();
        saveDraft();
      }
      return;
    }
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'z') {
      if (e.shiftKey) {
        e.preventDefault();
        redoStudioAction();
      } else {
        e.preventDefault();
        undoStudioAction();
      }
    } else if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'y') {
      e.preventDefault();
      redoStudioAction();
    } else if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') {
      e.preventDefault();
      saveDraft();
    }
  });

  // ---------------------------------------------------------------------------
  // 3. Save Draft & Publish Workflows
  // ---------------------------------------------------------------------------
  function saveDraft() {
    if (!studioState.metricId) return;

    updateSaveIndicator('saving', 'Saving draft...');
    var payload = new FormData();
    payload.append('action', 'save_form_draft');
    payload.append('id', studioState.metricId);
    payload.append('csrf', getCsrfToken());
    payload.append('csrf_token', getCsrfToken());
    payload.append('fields_json', JSON.stringify(studioState.fields));
    payload.append('attachment_json', JSON.stringify(studioState.attachment));
    payload.append('settings_json', JSON.stringify({
      name: studioState.metric.name,
      description: studioState.metric.description,
      category: studioState.metric.category,
      faculty_visible: studioState.metric.faculty_visible,
      coordinator_visible: studioState.metric.coordinator_visible,
      hod_visible: studioState.metric.hod_visible,
      principal_visible: studioState.metric.principal_visible,
      status: studioState.metric.status !== undefined ? studioState.metric.status : 1,
      help_text: studioState.metric.help_text || '',
      proof_required: studioState.attachment.required ? 1 : 0
    }));

    fetch('form-studio.php?ajax=1', {
      method: 'POST',
      headers: {
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-Token': getCsrfToken()
      },
      body: payload
    })
    .then(function (res) { return res.json(); })
    .then(function (data) {
      if (data && data.success) {
        studioState.isDirty = false;
        updateSaveIndicator('saved', 'Draft Saved');
        document.getElementById('draftBadge').style.display = 'inline-flex';
      } else {
        updateSaveIndicator('failed', 'Save failed');
        alert('Failed to save draft: ' + (data && data.message ? data.message : 'Unknown error'));
      }
    })
    .catch(function (err) {
      console.error('Draft save error:', err);
      updateSaveIndicator('failed', 'Save failed');
    });
  }

  function publishConfiguration() {
    if (!studioState.metricId) return;

    if (!confirm('Publish this form configuration? This creates an immutable version snapshot (v' + ((studioState.metric.form_version || 1) + 1) + ') and makes the form live for authorized roles immediately.')) {
      return;
    }

    updateSaveIndicator('saving', 'Publishing...');
    var payload = new FormData();
    payload.append('action', 'publish_form');
    payload.append('id', studioState.metricId);
    payload.append('csrf', getCsrfToken());
    payload.append('csrf_token', getCsrfToken());
    payload.append('fields_json', JSON.stringify(studioState.fields));
    payload.append('attachment_json', JSON.stringify(studioState.attachment));
    payload.append('settings_json', JSON.stringify({
      name: studioState.metric.name,
      description: studioState.metric.description,
      category: studioState.metric.category,
      faculty_visible: studioState.metric.faculty_visible,
      coordinator_visible: studioState.metric.coordinator_visible,
      hod_visible: studioState.metric.hod_visible,
      principal_visible: studioState.metric.principal_visible,
      status: studioState.metric.status !== undefined ? studioState.metric.status : 1,
      help_text: studioState.metric.help_text || '',
      proof_required: studioState.attachment.required ? 1 : 0
    }));

    fetch('form-studio.php?ajax=1', {
      method: 'POST',
      headers: {
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-Token': getCsrfToken()
      },
      body: payload
    })
    .then(function (res) { return res.json(); })
    .then(function (data) {
      if (data && data.success) {
        studioState.isDirty = false;
        studioState.metric.form_version = data.version || (studioState.metric.form_version + 1);
        studioState.history = data.history || studioState.history;

        // Synchronize allMetrics entry for live dropdown badge & title updates
        if (Array.isArray(studioState.allMetrics)) {
          for (var i = 0; i < studioState.allMetrics.length; i++) {
            if (parseInt(studioState.allMetrics[i].id, 10) === parseInt(studioState.metricId, 10)) {
              studioState.allMetrics[i].form_version = studioState.metric.form_version;
              studioState.allMetrics[i].name = studioState.metric.name;
              break;
            }
          }
        }

        updateTopbarUI();
        updateSaveIndicator('saved', 'Published v' + studioState.metric.form_version);
        document.getElementById('draftBadge').style.display = 'none';
        alert('Form configuration published successfully as Version ' + studioState.metric.form_version + '!');
      } else {
        updateSaveIndicator('failed', 'Publish failed');
        alert('Failed to publish: ' + (data && data.message ? data.message : 'Validation error'));
      }
    })
    .catch(function (err) {
      console.error('Publish error:', err);
      updateSaveIndicator('failed', 'Publish error');
    });
  }

  // ---------------------------------------------------------------------------
  // 4. Center Canvas Rendering & Dynamic Drag Reordering
  // ---------------------------------------------------------------------------
  var canvasDragState = {
    isDragging: false,
    source: null,        // 'canvas' | 'palette'
    fromIndex: -1,       // index in studioState.fields
    draggedCard: null,   // DOM element of dragged card
    placeholder: null,   // DOM element of placeholder slot
    paletteType: null,   // if source === 'palette'
    span: 6
  };

  function createDragPlaceholder(span, heightPx) {
    var ph = document.createElement('div');
    ph.className = 'canvas-drag-placeholder';
    ph.style.gridColumn = 'span ' + Math.min(12, Math.max(1, span || 6));
    ph.style.minHeight = Math.max(heightPx || 70, 60) + 'px';
    ph.innerHTML = '<div class="canvas-drag-placeholder-inner">' +
      '<span class="placeholder-icon">↕</span>' +
      '<span class="placeholder-label">Move here</span>' +
      '</div>';
    return ph;
  }

  function cleanupDragState() {
    if (canvasDragState.placeholder && canvasDragState.placeholder.parentNode) {
      canvasDragState.placeholder.parentNode.removeChild(canvasDragState.placeholder);
    }
    if (canvasDragState.draggedCard) {
      canvasDragState.draggedCard.classList.remove('is-dragging', 'dragging');
    }
    canvasDragState.isDragging = false;
    canvasDragState.source = null;
    canvasDragState.fromIndex = -1;
    canvasDragState.draggedCard = null;
    canvasDragState.placeholder = null;
    canvasDragState.paletteType = null;

    var surface = document.querySelector('.canvas-form-surface');
    if (surface) surface.classList.remove('show-grid-guides');

    document.querySelectorAll('.canvas-field-card, .canvas-section-header').forEach(function (c) {
      c.classList.remove('drag-insert-before', 'drag-insert-after', 'is-dragging', 'dragging');
    });
  }

  function handleCardDragOver(e, targetElement) {
    e.preventDefault();
    if (e.dataTransfer) {
      e.dataTransfer.dropEffect = 'move';
    }

    var container = document.getElementById('canvasSectionsContainer');
    if (!container) return;

    if (!canvasDragState.isDragging || !canvasDragState.placeholder) {
      var raw = '';
      try {
        raw = e.dataTransfer ? e.dataTransfer.getData('text/plain') : '';
      } catch (err) {}
      var pType = 'text';
      if (raw) {
        try {
          var p = JSON.parse(raw);
          if (p.source === 'palette') pType = p.type;
        } catch (err) {}
      }
      canvasDragState.isDragging = true;
      canvasDragState.source = 'palette';
      canvasDragState.paletteType = pType;
      var span = (['section_header', 'heading', 'divider', 'instructions'].indexOf(pType) !== -1) ? 12 : 6;
      canvasDragState.span = span;
      canvasDragState.placeholder = createDragPlaceholder(span, 70);
      container.appendChild(canvasDragState.placeholder);
    }

    var placeholder = canvasDragState.placeholder;
    if (!placeholder) return;

    if (targetElement === placeholder || targetElement.closest('.canvas-drag-placeholder')) {
      return;
    }
    if (targetElement === canvasDragState.draggedCard) {
      return;
    }

    var rect = targetElement.getBoundingClientRect();
    var midY = rect.top + rect.height / 2;
    var midX = rect.left + rect.width / 2;

    var isBefore = false;
    if (Math.abs(e.clientY - midY) > rect.height * 0.2) {
      isBefore = (e.clientY < midY);
    } else {
      isBefore = (e.clientX < midX);
    }

    if (isBefore) {
      if (placeholder.nextSibling !== targetElement) {
        container.insertBefore(placeholder, targetElement);
      }
    } else {
      if (placeholder.previousSibling !== targetElement) {
        container.insertBefore(placeholder, targetElement.nextSibling);
      }
    }
  }

  function calculateDropIndex() {
    var container = document.getElementById('canvasSectionsContainer');
    if (!container || !canvasDragState.placeholder) return -1;

    var placeholder = canvasDragState.placeholder;
    var draggedCard = canvasDragState.draggedCard;
    var children = Array.from(container.children);
    var dropIndex = 0;

    for (var i = 0; i < children.length; i++) {
      var el = children[i];
      if (el === placeholder) {
        break;
      }
      if (el !== draggedCard && (el.classList.contains('canvas-field-card') || el.classList.contains('canvas-section-header'))) {
        dropIndex++;
      }
    }
    return dropIndex;
  }

  function executeCanvasDrop(e) {
    if (e) {
      e.preventDefault();
      e.stopPropagation();
    }

    if (!canvasDragState.isDragging || !canvasDragState.placeholder) {
      cleanupDragState();
      return;
    }

    var newIndex = calculateDropIndex();
    var fromIdx = canvasDragState.fromIndex;
    var source = canvasDragState.source;
    var paletteType = canvasDragState.paletteType;

    cleanupDragState();

    if (source === 'canvas') {
      if (fromIdx >= 0 && fromIdx < studioState.fields.length && newIndex >= 0) {
        if (fromIdx !== newIndex) {
          moveField(fromIdx, newIndex);
        } else {
          renderCanvas();
        }
      } else {
        renderCanvas();
      }
    } else if (source === 'palette' && paletteType) {
      addFieldFromPalette(paletteType, newIndex >= 0 ? newIndex : undefined);
    }
  }

  function renderCanvas() {
    var container = document.getElementById('canvasSectionsContainer');
    if (!container) return;
    container.innerHTML = '';

    if (studioState.fields.length === 0) {
      container.innerHTML = '<div class="canvas-empty-state">' +
        '<div style="font-size:24px;margin-bottom:8px;">📋</div>' +
        '<div style="font-weight:700;font-size:14px;color:#1E293B;">No fields in this form yet</div>' +
        '<div style="font-size:12px;margin-top:4px;">Click any field type on the left palette to add it to the canvas.</div>' +
        '</div>';
      return;
    }

    // Group fields by section (if configured)
    var currentSection = null;
    var currentSectionList = null;

    studioState.fields.forEach(function (f, idx) {
      if (f.type === 'section_header') {
        var secDiv = document.createElement('div');
        secDiv.className = 'canvas-section-header' + (studioState.selectedIdx === idx ? ' active-selection' : '');
        secDiv.style.gridColumn = '1 / -1';
        secDiv.style.width = '100%';
        secDiv.draggable = true;
        secDiv.dataset.index = idx;
        secDiv.onclick = function (e) {
          e.stopPropagation();
          selectField(idx);
        };
        secDiv.addEventListener('dragstart', function (e) {
          if (e.target.closest('.field-actions-toolbar') || e.target.closest('button, a')) {
            e.preventDefault();
            return false;
          }
          canvasDragState.isDragging = true;
          canvasDragState.source = 'canvas';
          canvasDragState.fromIndex = idx;
          canvasDragState.draggedCard = secDiv;
          canvasDragState.span = 12;

          if (e.dataTransfer) {
            e.dataTransfer.setData('text/plain', JSON.stringify({ source: 'canvas', index: idx }));
            e.dataTransfer.effectAllowed = 'move';
          }

          var surface = document.querySelector('.canvas-form-surface');
          if (surface) surface.classList.add('show-grid-guides');

          var rect = secDiv.getBoundingClientRect();
          canvasDragState.placeholder = createDragPlaceholder(12, rect.height);
          secDiv.parentNode.insertBefore(canvasDragState.placeholder, secDiv);

          setTimeout(function () {
            if (canvasDragState.isDragging && secDiv) {
              secDiv.classList.add('is-dragging');
            }
          }, 0);
        });
        secDiv.addEventListener('dragend', function () {
          cleanupDragState();
        });
        secDiv.addEventListener('dragover', function (e) {
          handleCardDragOver(e, secDiv);
        });
        secDiv.addEventListener('drop', function (e) {
          executeCanvasDrop(e);
        });
        secDiv.innerHTML = '<div style="display:flex;align-items:center;gap:8px;min-width:0;">' +
          '<span style="color:#FF4F01;font-size:16px;">📂</span>' +
          '<span class="section-title-text" style="word-break:break-word;">' + escapeHtml(f.label || 'New Section') + '</span>' +
          (f.help_text ? '<span style="font-size:11.5px;color:#64748B;font-weight:normal;margin-left:6px;word-break:break-word;">' + escapeHtml(f.help_text) + '</span>' : '') +
          '</div>' +
          '<div class="field-actions-toolbar">' +
          '<button type="button" class="field-act-btn delete-btn" title="Delete Section" onclick="event.stopPropagation();deleteField(' + idx + ');">&times;</button>' +
          '</div>';
        container.appendChild(secDiv);
        return;
      }

      var card = createFieldCardElement(f, idx);
      container.appendChild(card);
    });
  }

  function getFieldSpan(f) {
    if (!f) return 6;
    if (['section_header', 'heading', 'divider', 'instructions'].indexOf(f.type) !== -1) {
      return 12;
    }
    var span = parseInt(f.col_span, 10);
    var w = (f.width || '').toString().toLowerCase().trim();

    if (!isNaN(span) && span > 2 && span <= 12) return span;
    if (w === '25%' || w === '1/4' || w === '3/12') return 3;
    if (w === '33%' || w === '33.3%' || w === '1/3' || w === '4/12') return 4;
    if (w === '50%' || w === '1/2' || w === '6/12' || w === 'half') return 6;
    if (w === '66%' || w === '66.7%' || w === '2/3' || w === '8/12') return 8;
    if (w === '75%' || w === '3/4' || w === '9/12') return 9;
    if (w === '100%' || w === '1/1' || w === '12/12' || w === 'full') return 12;

    if (span === 2 || ['textarea', 'file', 'specialized', 'table_grid', 'acknowledgement'].indexOf(f.type) !== -1) return 12;
    if (span === 1) return 6;
    return 6;
  }

  function startFieldResize(e, idx, card) {
    e.preventDefault();
    e.stopPropagation();

    var surface = document.querySelector('.canvas-form-surface');
    if (surface) surface.classList.add('show-grid-guides');

    var grid = card.closest('.canvas-form-grid');
    if (!grid) return;

    var gridRect = grid.getBoundingClientRect();
    var cardRect = card.getBoundingClientRect();
    var gap = 16;
    var colWidth = (gridRect.width - 11 * gap) / 12;
    var colStep = colWidth + gap;

    var f = studioState.fields[idx];
    var initialSpan = getFieldSpan(f);
    var currentSpan = initialSpan;

    // Live Floating Tooltip
    var tooltip = document.createElement('div');
    tooltip.className = 'resize-floating-tooltip';
    tooltip.textContent = 'Width: ' + currentSpan + ' / 12 Cols (' + Math.round((currentSpan / 12) * 100) + '%)';
    document.body.appendChild(tooltip);

    function updateTooltipPos(span, clientX, clientY) {
      tooltip.textContent = 'Width: ' + span + ' / 12 Cols (' + Math.round((span / 12) * 100) + '%)';
      tooltip.style.left = clientX + 'px';
      tooltip.style.top = (clientY - 12) + 'px';
    }
    updateTooltipPos(currentSpan, e.clientX, e.clientY);

    document.body.style.cursor = 'col-resize';
    document.body.style.userSelect = 'none';

    function onMouseMove(ev) {
      var currentW = ev.clientX - cardRect.left;
      var newSpan = Math.round((currentW + gap / 2) / colStep);
      newSpan = Math.max(1, Math.min(12, newSpan));
      if (newSpan !== currentSpan) {
        currentSpan = newSpan;
        card.style.gridColumn = 'span ' + currentSpan;
      }
      updateTooltipPos(currentSpan, ev.clientX, ev.clientY);
    }

    function onMouseUp() {
      window.removeEventListener('mousemove', onMouseMove);
      window.removeEventListener('mouseup', onMouseUp);
      document.body.style.cursor = '';
      document.body.style.userSelect = '';
      if (surface) surface.classList.remove('show-grid-guides');
      if (tooltip && tooltip.parentNode) tooltip.parentNode.removeChild(tooltip);

      if (currentSpan !== initialSpan) {
        recordState();
        f.col_span = currentSpan;
        f.width = (currentSpan === 12) ? 'full' : (currentSpan === 6 ? 'half' : (currentSpan + '/12'));
        markDirty();
        renderCanvas();
        if (studioState.selectedIdx === idx) {
          renderInspector();
        }
      }
    }

    window.addEventListener('mousemove', onMouseMove);
    window.addEventListener('mouseup', onMouseUp);
  }

  function startHeightResize(e, idx, card) {
    e.preventDefault();
    e.stopPropagation();

    var startY = e.clientY;
    var startHeight = card.offsetHeight;
    var f = studioState.fields[idx];
    var currentH = startHeight;

    document.body.style.cursor = 'row-resize';
    document.body.style.userSelect = 'none';

    function onMouseMove(ev) {
      var newH = Math.max(38, Math.min(500, startHeight + (ev.clientY - startY)));
      currentH = newH;
      card.style.minHeight = newH + 'px';
    }

    function onMouseUp() {
      window.removeEventListener('mousemove', onMouseMove);
      window.removeEventListener('mouseup', onMouseUp);
      document.body.style.cursor = '';
      document.body.style.userSelect = '';

      recordState();
      f.custom_height = Math.round(currentH);
      markDirty();
      renderCanvas();
      if (studioState.selectedIdx === idx) {
        renderInspector();
      }
    }

    window.addEventListener('mousemove', onMouseMove);
    window.addEventListener('mouseup', onMouseUp);
  }

  function createFieldCardElement(f, idx) {
    var card = document.createElement('div');
    var isSelected = (studioState.selectedIdx === idx);
    var span = getFieldSpan(f);

    card.className = 'canvas-field-card' + (span === 12 ? ' width-full' : ' width-half') + (isSelected ? ' active-field' : '');
    card.style.gridColumn = 'span ' + span;

    if (f.offset && parseInt(f.offset, 10) > 0) {
      card.style.gridColumnStart = 1 + parseInt(f.offset, 10);
    }
    if (f.align && f.align !== 'stretch') {
      card.style.justifySelf = f.align;
    }
    if (f.vertical_align) {
      card.style.alignSelf = f.vertical_align;
    }
    if (f.spacing) {
      card.classList.add('spacing-' + f.spacing);
    }
    if (f.custom_height) {
      if (!isNaN(parseInt(f.custom_height, 10))) {
        card.style.minHeight = parseInt(f.custom_height, 10) + 'px';
      } else if (f.custom_height === 'compact') {
        card.style.minHeight = '36px';
      } else if (f.custom_height === 'tall') {
        card.style.minHeight = '80px';
      }
    }

    card.draggable = true;
    card.dataset.index = idx;

    // Real-time dynamic drag-and-drop reordering with live placeholder flow
    card.addEventListener('dragstart', function (e) {
      if (e.target.closest('.field-actions-toolbar') ||
          e.target.closest('.field-resize-handle') ||
          e.target.closest('.field-height-handle') ||
          e.target.closest('button, input, select, textarea, a')) {
        e.preventDefault();
        return false;
      }

      canvasDragState.isDragging = true;
      canvasDragState.source = 'canvas';
      canvasDragState.fromIndex = idx;
      canvasDragState.draggedCard = card;
      canvasDragState.span = span;

      if (e.dataTransfer) {
        e.dataTransfer.setData('text/plain', JSON.stringify({ source: 'canvas', index: idx }));
        e.dataTransfer.effectAllowed = 'move';
      }

      var surface = document.querySelector('.canvas-form-surface');
      if (surface) surface.classList.add('show-grid-guides');

      var rect = card.getBoundingClientRect();
      canvasDragState.placeholder = createDragPlaceholder(span, rect.height);
      card.parentNode.insertBefore(canvasDragState.placeholder, card);

      setTimeout(function () {
        if (canvasDragState.isDragging && card) {
          card.classList.add('is-dragging');
        }
      }, 0);
    });

    card.addEventListener('dragend', function () {
      cleanupDragState();
    });

    card.addEventListener('dragover', function (e) {
      handleCardDragOver(e, card);
    });

    card.addEventListener('drop', function (e) {
      executeCanvasDrop(e);
    });

    // Selection on click
    card.addEventListener('click', function (e) {
      e.stopPropagation();
      selectField(idx);
    });

    // Card Header
    var header = document.createElement('div');
    header.className = 'field-card-header';

    var headerLeft = document.createElement('div');
    headerLeft.className = 'field-card-header-left';
    headerLeft.innerHTML = '<span class="field-drag-grip" title="Drag to reorder position on canvas">⋮⋮</span>' +
      '<span class="field-label-preview" title="' + escapeHtml(f.label || '') + '">' + escapeHtml(f.label || 'Untitled Field') + '</span>' +
      (f.required ? '<span class="field-req-mark" title="Required field">*</span>' : '') +
      '<span class="field-span-pill" title="Grid column span">' + span + '/12</span>' +
      (f.readonly || f.read_only ? '<span class="field-mini-pill" title="Read-Only / Computed">🔒</span>' : '') +
      (f.conditional && f.conditional.field ? '<span class="field-mini-pill fx" title="Conditional logic">fx</span>' : '') +
      (f.protected ? '<span class="field-mini-pill protected" title="Core Institutional Field">★</span>' : '');

    var headerRight = document.createElement('div');
    headerRight.className = 'field-actions-toolbar';
    headerRight.innerHTML =
      '<button type="button" class="field-act-btn btn-width-toggle" title="Click to cycle width (25% → 33% → 50% → 67% → 75% → 100%)" onclick="event.stopPropagation();toggleFieldWidth(' + idx + ');">' + Math.round((span/12)*100) + '%</button>' +
      '<button type="button" class="field-act-btn" title="Move Left / Up" onclick="event.stopPropagation();moveFieldUp(' + idx + ');">↑</button>' +
      '<button type="button" class="field-act-btn" title="Move Right / Down" onclick="event.stopPropagation();moveFieldDown(' + idx + ');">↓</button>' +
      '<button type="button" class="field-act-btn" title="Duplicate Field" onclick="event.stopPropagation();duplicateField(' + idx + ');">⧉</button>' +
      '<button type="button" class="field-act-btn delete-btn" title="Delete Field" onclick="event.stopPropagation();deleteField(' + idx + ');">🗑</button>';

    header.appendChild(headerLeft);
    header.appendChild(headerRight);
    card.appendChild(header);

    // Realistic Field Input Preview matching upload.php styling
    var body = document.createElement('div');
    body.className = 'field-control-preview';
    body.innerHTML = renderControlMockup(f);
    card.appendChild(body);

    if (f.help_text) {
      var help = document.createElement('div');
      help.style.cssText = 'font-size:11px;color:#64748B;margin-top:4px;';
      help.textContent = f.help_text;
      card.appendChild(help);
    }

    // Interactive Horizontal Resize Handle
    var resizeHandle = document.createElement('div');
    resizeHandle.className = 'field-resize-handle';
    resizeHandle.title = 'Drag horizontally to resize field width (1 to 12 columns)';
    resizeHandle.addEventListener('mousedown', function (e) {
      startFieldResize(e, idx, card);
    });
    card.appendChild(resizeHandle);

    // Interactive Vertical Height Handle
    var heightHandle = document.createElement('div');
    heightHandle.className = 'field-height-handle';
    heightHandle.title = 'Drag vertically to adjust field height';
    heightHandle.addEventListener('mousedown', function (e) {
      startHeightResize(e, idx, card);
    });
    card.appendChild(heightHandle);

    return card;
  }

  function renderControlMockup(f) {
    var isRo = !!(f.readonly || f.read_only);
    var roStyle = isRo ? 'background:#F1F5F9;font-weight:600;color:#475569;cursor:not-allowed;' : 'background:#FFFFFF;';

    switch (f.type) {
      case 'textarea':
        return '<textarea class="textarea realistic-canvas-input" rows="2" placeholder="' + escapeHtml(f.placeholder || 'Enter remarks / description...') + '" readonly style="' + roStyle + 'resize:none;font-size:12.5px;"></textarea>';

      case 'select':
        var opts = resolveFieldOptions(f);
        var optHtml = '<option selected>' + escapeHtml(f.placeholder || ('Select ' + f.label + '...')) + '</option>';
        var hasOtherOpt = false;
        opts.forEach(function (opt) {
          var lbl = typeof opt === 'object' ? (opt.label || opt.value) : opt;
          var val = typeof opt === 'object' ? (opt.value || lbl) : opt;
          var vLow = String(val).toLowerCase().trim();
          var lLow = String(lbl).toLowerCase().trim();
          if (vLow === 'other' || vLow === 'others' || lLow === 'other' || lLow === 'others') {
            hasOtherOpt = true;
          }
          optHtml += '<option disabled>' + escapeHtml(lbl) + '</option>';
        });
        var selectMockup = '<select class="select realistic-canvas-input" disabled style="' + roStyle + 'font-size:12.5px;opacity:1;cursor:default;">' + optHtml + '</select>';
        if (hasOtherOpt) {
          selectMockup += '<div style="margin-top:6px;display:flex;align-items:center;gap:6px;font-size:11px;color:#64748B;">' +
            '<span style="color:#2563EB;font-weight:700;">↳</span> ' +
            '<input class="input" style="height:28px;font-size:11px;background:#F8FAFC;border:1px dashed #CBD5E1;width:100%;color:#64748B;cursor:default;" placeholder="' + escapeHtml(f.label) + ' (Please specify) — auto text on Other" disabled>' +
            '</div>';
        }
        return selectMockup;

      case 'radio':
      case 'checkbox':
      case 'yes_no':
        var rOpts = resolveFieldOptions(f);
        if (rOpts.length === 0) rOpts = ['Option 1', 'Option 2'];
        var hasOtherRadio = false;
        var rHtml = '<div style="display:flex;flex-wrap:wrap;gap:12px;padding:4px 0;">';
        rOpts.forEach(function (opt, i) {
          var lbl = typeof opt === 'object' ? (opt.label || opt.value) : opt;
          var val = typeof opt === 'object' ? (opt.value || lbl) : opt;
          var vLow = String(val).toLowerCase().trim();
          var lLow = String(lbl).toLowerCase().trim();
          if (vLow === 'other' || vLow === 'others' || lLow === 'other' || lLow === 'others') {
            hasOtherRadio = true;
          }
          rHtml += '<label style="display:inline-flex;align-items:center;gap:6px;font-size:12px;color:#334155;cursor:default;">' +
            '<input type="' + (f.type === 'checkbox' ? 'checkbox' : 'radio') + '" disabled ' + (i === 0 ? 'checked' : '') + '> ' +
            '<span>' + escapeHtml(lbl) + '</span>' +
            '</label>';
        });
        rHtml += '</div>';
        if (hasOtherRadio && f.type === 'radio') {
          rHtml += '<div style="margin-top:4px;display:flex;align-items:center;gap:6px;font-size:11px;color:#64748B;">' +
            '<span style="color:#2563EB;font-weight:700;">↳</span> ' +
            '<input class="input" style="height:28px;font-size:11px;background:#F8FAFC;border:1px dashed #CBD5E1;width:100%;color:#64748B;cursor:default;" placeholder="' + escapeHtml(f.label) + ' (Please specify) — auto text on Other" disabled>' +
            '</div>';
        }
        return rHtml;

      case 'file':
      case 'file_single':
      case 'file_multi':
      case 'file_pdf':
      case 'file_image':
      case 'file_evidence':
        var fileIcon = (f.type === 'file_pdf') ? '📄' : (f.type === 'file_image' ? '🖼️' : '📁');
        var helpLimit = (f.type === 'file_pdf') ? 'PDF document strictly 2 MB or less' : 'Allowed formats: PDF, JPG, PNG up to 2 MB';
        return '<div style="border:1.5px dashed #CBD5E1;border-radius:6px;padding:10px 12px;background:#F8FAFC;display:flex;align-items:center;gap:10px;">' +
          '<div style="width:30px;height:30px;border-radius:6px;background:#EFF6FF;color:#2563EB;display:flex;align-items:center;justify-content:center;font-size:14px;flex-shrink:0;">' + fileIcon + '</div>' +
          '<div style="flex:1;"><div style="font-size:12px;font-weight:600;color:#1E293B;">Upload ' + escapeHtml(f.label) + '...</div>' +
          '<div style="font-size:10.5px;color:#64748B;">' + helpLimit + '</div></div>' +
          '</div>';

      case 'table_grid':
        return '<div style="background:#F1F5F9;border:1px dashed #CBD5E1;padding:10px;border-radius:6px;font-size:11.5px;color:#64748B;text-align:center;">⊞ Tabular Grid Data Entry Table (Multi-row support)</div>';

      case 'acknowledgement':
        return '<div style="display:flex;align-items:center;gap:8px;font-size:12px;font-weight:600;color:#92400E;background:#FEF3C7;padding:8px 10px;border-radius:6px;"><input type="checkbox" disabled checked> ' + escapeHtml(f.label) + '</div>';

      case 'divider':
        return '<div style="border-top:1.5px solid #CBD5E1;margin:8px 0;"></div>';

      case 'instructions':
        return '<div style="background:#EFF6FF;border-left:3px solid #3B82F6;padding:8px 12px;font-size:12px;color:#1E40AF;border-radius:4px;">' + escapeHtml(f.label) + '</div>';

      case 'section_container':
        return '<div style="border:1px dashed #94A3B8;border-radius:6px;padding:12px;background:#F8FAFC;color:#475569;font-size:12px;font-weight:600;">📦 Section Container Box</div>';

      case 'date':
      case 'placement_date':
        return '<input type="date" class="input realistic-canvas-input" readonly style="' + roStyle + 'font-size:12.5px;">';

      case 'time':
        return '<input type="time" class="input realistic-canvas-input" readonly style="' + roStyle + 'font-size:12.5px;">';

      case 'datetime':
        return '<input type="datetime-local" class="input realistic-canvas-input" readonly style="' + roStyle + 'font-size:12.5px;">';

      default:
        var inputType = (f.type === 'integer' || f.type === 'decimal') ? 'number' : (f.type === 'email' ? 'email' : (f.type === 'tel' ? 'tel' : (f.type === 'url' ? 'url' : 'text')));
        return '<input type="' + inputType + '" class="input realistic-canvas-input" placeholder="' + escapeHtml(f.placeholder || ('Enter ' + f.label + '...')) + '" readonly style="' + roStyle + 'font-size:12.5px;">';
    }
  }

  // ---------------------------------------------------------------------------
  // 5. Field Selection & Dynamic Properties Inspector
  // ---------------------------------------------------------------------------
  function selectField(idx) {
    if (idx === 'header' || idx === 'proof') {
      studioState.selectedIdx = idx;
    } else {
      idx = parseInt(idx, 10);
      if (isNaN(idx) || !studioState.fields[idx]) return;
      studioState.selectedIdx = idx;
    }
    clearSelectionStyles();

    var cards = document.querySelectorAll('.canvas-field-card');
    if (typeof studioState.selectedIdx === 'number' && cards[studioState.selectedIdx]) {
      cards[studioState.selectedIdx].classList.add('active-field');
    }

    switchRightTab('inspector');
    renderInspector();
  }

  function selectFormSettings() {
    studioState.selectedIdx = 'header';
    clearSelectionStyles();
    document.getElementById('canvasFormHeader').classList.add('active-selection');
    switchRightTab('inspector');
    renderInspector();
  }

  function selectProofSettings() {
    studioState.selectedIdx = 'proof';
    clearSelectionStyles();
    document.getElementById('canvasProofCard').classList.add('active-selection');
    switchRightTab('inspector');
    renderInspector();
  }

  function clearSelectionStyles() {
    document.querySelectorAll('.canvas-field-card').forEach(function (c) {
      c.classList.remove('active-field');
    });
    var hdr = document.getElementById('canvasFormHeader');
    if (hdr) hdr.classList.remove('active-selection');
    var prf = document.getElementById('canvasProofCard');
    if (prf) prf.classList.remove('active-selection');
  }

  function renderInspector() {
    var pane = document.getElementById('inspectorContent');
    if (!pane) return;
    pane.innerHTML = '';

    // Context 1: Form Level Settings
    if (studioState.selectedIdx === 'header') {
      pane.appendChild(createFormSettingsInspector());
      return;
    }

    // Context 2: Proof Attachment Settings
    if (studioState.selectedIdx === 'proof') {
      pane.appendChild(createProofSettingsInspector());
      return;
    }

    // Context 3: Selected Field Settings
    var idx = typeof studioState.selectedIdx === 'number' ? studioState.selectedIdx : parseInt(studioState.selectedIdx, 10);
    if (!isNaN(idx) && studioState.fields[idx]) {
      pane.appendChild(createFieldSettingsInspector(studioState.fields[idx], idx));
      return;
    }

    pane.innerHTML = '<div style="text-align:center;padding:30px;color:#64748B;">Select any field on the canvas to configure its properties.</div>';
  }

  function createFieldSettingsInspector(f, idx) {
    var wrap = document.createElement('div');
    wrap.style.cssText = 'display:flex;flex-direction:column;gap:14px;';

    // Header with Type & Key
    var isFull = (f.width === 'full' || f.col_span === 2);
    var head = document.createElement('div');
    head.style.cssText = 'display:flex;align-items:center;justify-content:space-between;padding-bottom:10px;border-bottom:1px solid #E2E8F0;';
    head.innerHTML = '<div>' +
      '<div style="font-size:14px;font-weight:800;color:var(--studio-navy);">' + escapeHtml(f.label || 'Untitled') + '</div>' +
      '<code style="font-size:11px;color:#2563EB;">key: ' + escapeHtml(f.key) + '</code>' +
      '</div>' +
      '<span class="badge badge-neutral" style="text-transform:uppercase;">' + escapeHtml(f.type) + '</span>';
    wrap.appendChild(head);

    // Section: Grid Layout, Dimensions & Free Positioning
    var secLayout = document.createElement('div');
    secLayout.className = 'prop-section';
    var curSpan = getFieldSpan(f);
    var curAlign = f.align || 'stretch';
    var curValign = f.vertical_align || 'top';
    var curSpacing = f.spacing || 'normal';
    var curOffset = parseInt(f.offset || 0, 10);
    var curHeight = f.custom_height || 'default';

    // Find all sections in the form
    var sectionOptionsHtml = '<option value="">(Top-level / Default)</option>';
    studioState.fields.forEach(function (secF, sIdx) {
      if (secF.type === 'section_header') {
        var sVal = secF.key || ('sec_' + sIdx);
        var sLbl = secF.label || ('Section #' + (sIdx + 1));
        sectionOptionsHtml += '<option value="' + escapeHtml(sVal) + '" ' + (f.section_id === sVal ? 'selected' : '') + '>' + escapeHtml(sLbl) + '</option>';
      }
    });

    secLayout.innerHTML =
      '<div class="prop-section-title">Grid Layout &amp; Free Placement</div>' +

      // 1-12 Column Presets
      '<div class="prop-field-group">' +
      '<div style="display:flex;justify-content:space-between;align-items:center;">' +
      '<label class="prop-label" style="margin:0;">Column Span (1 to 12)</label>' +
      '<span class="col-range-value-badge" id="spanBadgeDisplay">' + curSpan + ' / 12 (' + Math.round((curSpan/12)*100) + '%)</span>' +
      '</div>' +
      '<div class="col-presets-grid">' +
      '<button type="button" class="btn-col-preset ' + (curSpan === 3 ? 'active' : '') + '" onclick="setFieldColumnSpan(' + idx + ', 3)">25%</button>' +
      '<button type="button" class="btn-col-preset ' + (curSpan === 4 ? 'active' : '') + '" onclick="setFieldColumnSpan(' + idx + ', 4)">33%</button>' +
      '<button type="button" class="btn-col-preset ' + (curSpan === 6 ? 'active' : '') + '" onclick="setFieldColumnSpan(' + idx + ', 6)">50%</button>' +
      '<button type="button" class="btn-col-preset ' + (curSpan === 8 ? 'active' : '') + '" onclick="setFieldColumnSpan(' + idx + ', 8)">67%</button>' +
      '<button type="button" class="btn-col-preset ' + (curSpan === 9 ? 'active' : '') + '" onclick="setFieldColumnSpan(' + idx + ', 9)">75%</button>' +
      '<button type="button" class="btn-col-preset ' + (curSpan === 12 ? 'active' : '') + '" onclick="setFieldColumnSpan(' + idx + ', 12)">100%</button>' +
      '</div>' +
      '<div class="col-range-slider-wrap">' +
      '<input type="range" class="col-range-slider" min="1" max="12" step="1" value="' + curSpan + '" oninput="setFieldColumnSpan(' + idx + ', this.value, true)" onchange="setFieldColumnSpan(' + idx + ', this.value, false)">' +
      '</div>' +
      '</div>' +

      // Horizontal Alignment (Justify)
      '<div class="prop-field-group" style="margin-top:10px;">' +
      '<label class="prop-label">Horizontal Alignment</label>' +
      '<div class="segmented-btn-group">' +
      '<button type="button" class="segmented-btn ' + (curAlign === 'stretch' ? 'active' : '') + '" onclick="setFieldAlignment(' + idx + ', \'stretch\')">Stretch</button>' +
      '<button type="button" class="segmented-btn ' + (curAlign === 'left' ? 'active' : '') + '" onclick="setFieldAlignment(' + idx + ', \'left\')">Left</button>' +
      '<button type="button" class="segmented-btn ' + (curAlign === 'center' ? 'active' : '') + '" onclick="setFieldAlignment(' + idx + ', \'center\')">Center</button>' +
      '<button type="button" class="segmented-btn ' + (curAlign === 'right' ? 'active' : '') + '" onclick="setFieldAlignment(' + idx + ', \'right\')">Right</button>' +
      '</div>' +
      '</div>' +

      // Vertical Alignment
      '<div class="prop-field-group" style="margin-top:10px;">' +
      '<label class="prop-label">Vertical Alignment</label>' +
      '<div class="segmented-btn-group">' +
      '<button type="button" class="segmented-btn ' + (curValign === 'top' ? 'active' : '') + '" onclick="setFieldVerticalAlignment(' + idx + ', \'top\')">Top</button>' +
      '<button type="button" class="segmented-btn ' + (curValign === 'center' ? 'active' : '') + '" onclick="setFieldVerticalAlignment(' + idx + ', \'center\')">Middle</button>' +
      '<button type="button" class="segmented-btn ' + (curValign === 'bottom' ? 'active' : '') + '" onclick="setFieldVerticalAlignment(' + idx + ', \'bottom\')">Bottom</button>' +
      '</div>' +
      '</div>' +

      // Custom Spacing (Bottom Margin)
      '<div class="prop-field-group" style="margin-top:10px;">' +
      '<label class="prop-label">Field Spacing</label>' +
      '<div class="segmented-btn-group">' +
      '<button type="button" class="segmented-btn ' + (curSpacing === 'compact' ? 'active' : '') + '" onclick="setFieldSpacing(' + idx + ', \'compact\')">Compact</button>' +
      '<button type="button" class="segmented-btn ' + (curSpacing === 'normal' ? 'active' : '') + '" onclick="setFieldSpacing(' + idx + ', \'normal\')">Normal</button>' +
      '<button type="button" class="segmented-btn ' + (curSpacing === 'relaxed' ? 'active' : '') + '" onclick="setFieldSpacing(' + idx + ', \'relaxed\')">Relaxed</button>' +
      '<button type="button" class="segmented-btn ' + (curSpacing === 'spacious' ? 'active' : '') + '" onclick="setFieldSpacing(' + idx + ', \'spacious\')">Spacious</button>' +
      '</div>' +
      '</div>' +

      // Height / Dimensions
      '<div class="prop-field-group" style="margin-top:10px;">' +
      '<label class="prop-label">Height Dimensions</label>' +
      '<div style="display:flex;gap:6px;">' +
      '<select class="select" onchange="setFieldCustomHeight(' + idx + ', this.value)" style="flex:1;">' +
      '<option value="default" ' + (curHeight === 'default' ? 'selected' : '') + '>Standard Height</option>' +
      '<option value="compact" ' + (curHeight === 'compact' ? 'selected' : '') + '>Compact (36px)</option>' +
      '<option value="tall" ' + (curHeight === 'tall' ? 'selected' : '') + '>Tall (80px)</option>' +
      '<option value="custom" ' + (!isNaN(parseInt(curHeight, 10)) ? 'selected' : '') + '>Custom (px)</option>' +
      '</select>' +
      (!isNaN(parseInt(curHeight, 10)) ? '<input type="number" class="input" style="width:75px;" value="' + parseInt(curHeight, 10) + '" min="30" max="500" onchange="setFieldCustomHeight(' + idx + ', this.value)">' : '') +
      '</div>' +
      '</div>' +

      // Column Offset
      '<div class="prop-field-group" style="margin-top:10px;">' +
      '<div style="display:flex;justify-content:space-between;"><label class="prop-label">Column Offset (Indent)</label><span style="font-size:11px;font-weight:700;color:#64748B;">' + curOffset + ' cols</span></div>' +
      '<input type="range" class="col-range-slider" min="0" max="11" step="1" value="' + curOffset + '" oninput="setFieldOffset(' + idx + ', this.value)">' +
      '</div>' +

      // Section Membership
      '<div class="prop-field-group" style="margin-top:10px;">' +
      '<label class="prop-label">Section Membership</label>' +
      '<select class="select" onchange="moveFieldToSection(' + idx + ', this.value)">' +
      sectionOptionsHtml +
      '</select>' +
      '</div>' +

      // Read-Only lock
      '<div style="display:flex;align-items:center;justify-content:space-between;margin-top:10px;padding-top:10px;border-top:1px solid #F1F5F9;">' +
      '<div>' +
      '<span style="font-size:12px;font-weight:700;color:var(--studio-navy);">Read-Only / Pre-filled</span>' +
      '<div style="font-size:10.5px;color:#64748B;">Lock field value; non-editable by respondents</div>' +
      '</div>' +
      '<input type="checkbox" ' + (f.readonly || f.read_only ? 'checked' : '') + ' onchange="updateFieldProperty(' + idx + ', \'readonly\', this.checked)">' +
      '</div>';

    wrap.appendChild(secLayout);

    // Basic Settings
    var secBasic = document.createElement('div');
    secBasic.className = 'prop-section';
    secBasic.innerHTML =
      '<div class="prop-section-title">General Settings</div>' +
      '<div class="prop-field-group"><label class="prop-label">Field Label</label>' +
      '<input type="text" class="prop-input" value="' + escapeHtml(f.label || '') + '" oninput="updateFieldProperty(' + idx + ', \'label\', this.value)"></div>' +
      '<div class="prop-field-group"><label class="prop-label">Field Key / Slug</label>' +
      '<input type="text" class="prop-input" value="' + escapeHtml(f.key || '') + '" oninput="updateFieldProperty(' + idx + ', \'key\', this.value)"></div>' +
      '<div class="prop-field-group"><label class="prop-label">Placeholder Text</label>' +
      '<input type="text" class="prop-input" value="' + escapeHtml(f.placeholder || '') + '" oninput="updateFieldProperty(' + idx + ', \'placeholder\', this.value)"></div>' +
      '<div class="prop-field-group"><label class="prop-label">Help / Instructions</label>' +
      '<input type="text" class="prop-input" value="' + escapeHtml(f.help_text || '') + '" oninput="updateFieldProperty(' + idx + ', \'help_text\', this.value)"></div>' +
      '<div style="display:flex;align-items:center;justify-content:space-between;margin-top:6px;">' +
      '<span style="font-size:12px;font-weight:700;color:var(--studio-navy);">Required Field</span>' +
      '<input type="checkbox" ' + (f.required ? 'checked' : '') + ' onchange="updateFieldProperty(' + idx + ', \'required\', this.checked)">' +
      '</div>';
    wrap.appendChild(secBasic);

    // Choice Options (if applicable)
    if (['select', 'radio', 'checkbox', 'multiselect'].indexOf(f.type) !== -1) {
      wrap.appendChild(createOptionsManager(f, idx));
    }

    // Role Editability Permissions
    var secRoles = document.createElement('div');
    secRoles.className = 'prop-section';
    secRoles.innerHTML =
      '<div class="prop-section-title">Role Edit Permissions</div>' +
      '<div style="font-size:11px;color:#64748B;margin-bottom:6px;">Uncheck to make this field read-only for specific roles:</div>' +
      '<div class="role-checkbox-grid">' +
      '<label class="role-checkbox-item"><input type="checkbox" ' + (f.faculty_editable !== false ? 'checked' : '') + ' onchange="updateFieldProperty(' + idx + ', \'faculty_editable\', this.checked)"> Faculty</label>' +
      '<label class="role-checkbox-item"><input type="checkbox" ' + (f.coordinator_editable !== false ? 'checked' : '') + ' onchange="updateFieldProperty(' + idx + ', \'coordinator_editable\', this.checked)"> Coordinator</label>' +
      '<label class="role-checkbox-item"><input type="checkbox" ' + (f.hod_editable !== false ? 'checked' : '') + ' onchange="updateFieldProperty(' + idx + ', \'hod_editable\', this.checked)"> HoD</label>' +
      '<label class="role-checkbox-item"><input type="checkbox" ' + (f.principal_editable !== false ? 'checked' : '') + ' onchange="updateFieldProperty(' + idx + ', \'principal_editable\', this.checked)"> Principal</label>' +
      '</div>';
    wrap.appendChild(secRoles);

    // Smart Conditional Visibility Rule Builder
    wrap.appendChild(createConditionalRuleBuilder(f, idx));

    return wrap;
  }

  function createOptionsManager(f, idx) {
    var sec = document.createElement('div');
    sec.className = 'prop-section';
    var isShared = (f.options_mode === 'shared' && f.option_set_key);

    var html = '<div class="prop-section-title">Choice Options</div>' +
      '<div style="display:flex;gap:6px;margin-bottom:8px;">' +
      '<button type="button" class="btn btn-sm ' + (!isShared ? 'btn-primary' : 'btn-outline') + '" onclick="setOptionMode(' + idx + ', \'custom\')" style="font-size:11px;flex:1;">Custom Options</button>' +
      '<button type="button" class="btn btn-sm ' + (isShared ? 'btn-primary' : 'btn-outline') + '" onclick="setOptionMode(' + idx + ', \'shared\')" style="font-size:11px;flex:1;">Shared Option Set</button>' +
      '</div>';

    if (isShared) {
      html += '<div class="prop-field-group"><label class="prop-label">Select Shared Set</label>' +
        '<select class="select" onchange="updateFieldProperty(' + idx + ', \'option_set_key\', this.value)">';
      studioState.optionSets.forEach(function (set) {
        html += '<option value="' + escapeHtml(set.set_key) + '" ' + (f.option_set_key === set.set_key ? 'selected' : '') + '>' + escapeHtml(set.name) + '</option>';
      });
      html += '</select></div>';
    } else {
      var opts = Array.isArray(f.options) ? f.options : [];
      html += '<div id="customOptionsList">';
      opts.forEach(function (opt, oIdx) {
        var optVal = typeof opt === 'object' ? (opt.value || '') : opt;
        var optLbl = typeof opt === 'object' ? (opt.label || optVal) : opt;
        html += '<div class="option-item-row">' +
          '<input type="text" class="input" value="' + escapeHtml(optLbl) + '" placeholder="Label" oninput="updateCustomOption(' + idx + ', ' + oIdx + ', this.value)" style="flex:1;">' +
          '<button type="button" class="btn btn-sm btn-ghost" onclick="removeCustomOption(' + idx + ', ' + oIdx + ')" style="color:#DC2626;">&times;</button>' +
          '</div>';
      });
      html += '</div>' +
        '<div style="display:flex;gap:6px;margin-top:6px;">' +
        '<button type="button" class="btn btn-sm btn-outline" onclick="addCustomOption(' + idx + ')" style="font-size:11px;flex:1;">+ Add Choice Option</button>' +
        '<button type="button" class="btn btn-sm btn-outline" onclick="addOtherOption(' + idx + ')" style="font-size:11px;color:#2563EB;border-color:#93C5FD;background:#EFF6FF;" title="Add an Other choice that automatically reveals a text field for faculty">+ Add \'Other\'</button>' +
        '</div>';
    }

    sec.innerHTML = html;
    return sec;
  }

  function createConditionalRuleBuilder(f, idx) {
    var sec = document.createElement('div');
    sec.className = 'prop-section';

    var cond = f.conditional || { field: '', operator: 'equals', value: '' };
    var isEnabled = !!(cond.field);

    var html = '<div class="prop-section-title">Conditional Visibility Logic</div>' +
      '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px;">' +
      '<span style="font-size:11.5px;color:#334155;font-weight:600;">Enable Conditional Logic</span>' +
      '<input type="checkbox" ' + (isEnabled ? 'checked' : '') + ' onchange="toggleConditionalLogic(' + idx + ', this.checked)">' +
      '</div>';

    if (isEnabled) {
      html += '<div class="conditional-logic-box">' +
        '<div style="font-size:11px;font-weight:700;color:#166534;">Show this field only when:</div>' +
        '<div class="prop-field-group"><label class="prop-label">Trigger Field</label>' +
        '<select class="select" onchange="updateConditionProp(' + idx + ', \'field\', this.value)" style="height:28px;font-size:11.5px;">' +
        '<option value="">Select field...</option>';

      studioState.fields.forEach(function (otherF, otherIdx) {
        if (otherIdx !== idx && otherF.key) {
          html += '<option value="' + escapeHtml(otherF.key) + '" ' + (cond.field === otherF.key ? 'selected' : '') + '>' + escapeHtml(otherF.label) + '</option>';
        }
      });

      html += '</select></div>' +
        '<div class="prop-field-group"><label class="prop-label">Comparison</label>' +
        '<select class="select" onchange="updateConditionProp(' + idx + ', \'operator\', this.value)" style="height:28px;font-size:11.5px;">' +
        '<option value="equals" ' + (cond.operator === 'equals' ? 'selected' : '') + '>Equals</option>' +
        '<option value="not_equals" ' + (cond.operator === 'not_equals' ? 'selected' : '') + '>Does not equal</option>' +
        '<option value="contains" ' + (cond.operator === 'contains' ? 'selected' : '') + '>Contains</option>' +
        '<option value="is_empty" ' + (cond.operator === 'is_empty' ? 'selected' : '') + '>Is empty</option>' +
        '<option value="is_not_empty" ' + (cond.operator === 'is_not_empty' ? 'selected' : '') + '>Is not empty</option>' +
        '<option value="greater_than" ' + (cond.operator === 'greater_than' ? 'selected' : '') + '>Greater than</option>' +
        '<option value="less_than" ' + (cond.operator === 'less_than' ? 'selected' : '') + '>Less than</option>' +
        '</select></div>' +
        '<div class="prop-field-group"><label class="prop-label">Expected Value</label>' +
        '<input type="text" class="input" value="' + escapeHtml(cond.value || '') + '" oninput="updateConditionProp(' + idx + ', \'value\', this.value)" placeholder="e.g. Workshop or Yes" style="height:28px;font-size:11.5px;">' +
        '</div>' +
        '</div>';
    }

    sec.innerHTML = html;
    return sec;
  }

  function createFormSettingsInspector() {
    var wrap = document.createElement('div');
    wrap.style.cssText = 'display:flex;flex-direction:column;gap:14px;';
    var m = studioState.metric;

    wrap.innerHTML =
      '<div style="padding-bottom:8px;border-bottom:1px solid #E2E8F0;">' +
      '<div style="font-size:15px;font-weight:800;color:var(--studio-navy);">Form Configuration Settings</div>' +
      '<div style="font-size:11.5px;color:#64748B;">Global availability, scope, and institutional delivery rules.</div>' +
      '</div>' +

      '<div class="prop-section">' +
      '<div class="prop-section-title">General Information</div>' +
      '<div class="prop-field-group"><label class="prop-label">Form Title</label>' +
      '<input type="text" class="prop-input" value="' + escapeHtml(m.name || '') + '" oninput="updateFormSetting(\'name\', this.value)"></div>' +
      '<div class="prop-field-group"><label class="prop-label">Category / Pillar</label>' +
      '<input type="text" class="prop-input" value="' + escapeHtml(m.category || '') + '" oninput="updateFormSetting(\'category\', this.value)"></div>' +
      '<div class="prop-field-group"><label class="prop-label">Scope Description</label>' +
      '<textarea class="prop-textarea" rows="2" oninput="updateFormSetting(\'description\', this.value)">' + escapeHtml(m.description || '') + '</textarea></div>' +
      '</div>' +

      '<div class="prop-section">' +
      '<div class="prop-section-title">Role-Based Delivery Assignment</div>' +
      '<div style="font-size:11px;color:#64748B;margin-bottom:6px;">Select which institutional user roles have access to submit this form:</div>' +
      '<div class="role-checkbox-grid">' +
      '<label class="role-checkbox-item"><input type="checkbox" ' + (m.faculty_visible ? 'checked' : '') + ' onchange="updateFormSetting(\'faculty_visible\', this.checked ? 1 : 0)"> Faculty</label>' +
      '<label class="role-checkbox-item"><input type="checkbox" ' + (m.coordinator_visible ? 'checked' : '') + ' onchange="updateFormSetting(\'coordinator_visible\', this.checked ? 1 : 0)"> Coordinator</label>' +
      '<label class="role-checkbox-item"><input type="checkbox" ' + (m.hod_visible ? 'checked' : '') + ' onchange="updateFormSetting(\'hod_visible\', this.checked ? 1 : 0)"> HoD</label>' +
      '<label class="role-checkbox-item"><input type="checkbox" ' + (m.principal_visible ? 'checked' : '') + ' onchange="updateFormSetting(\'principal_visible\', this.checked ? 1 : 0)"> Principal</label>' +
      '</div>' +
      '</div>' +

      '<div class="prop-section">' +
      '<div class="prop-section-title">Lifecycle &amp; Status</div>' +
      '<div style="display:flex;align-items:center;justify-content:space-between;">' +
      '<span style="font-size:12px;font-weight:700;color:var(--studio-navy);">Active in Upload Navigation</span>' +
      '<input type="checkbox" ' + (m.status ? 'checked' : '') + ' onchange="updateFormSetting(\'status\', this.checked ? 1 : 0)">' +
      '</div>' +
      '</div>';

    return wrap;
  }

  function createProofSettingsInspector() {
    var wrap = document.createElement('div');
    wrap.style.cssText = 'display:flex;flex-direction:column;gap:14px;';
    var att = studioState.attachment;

    wrap.innerHTML =
      '<div style="padding-bottom:8px;border-bottom:1px solid #E2E8F0;">' +
      '<div style="font-size:15px;font-weight:800;color:var(--studio-navy);">Mandatory Proof Configuration</div>' +
      '<div style="font-size:11.5px;color:#64748B;">Configure file upload security, presets, formats, and limits.</div>' +
      '</div>' +

      '<div class="prop-section">' +
      '<div class="prop-section-title">Attachment Rules</div>' +
      '<div class="prop-field-group"><label class="prop-label">Attachment Field Label</label>' +
      '<input type="text" class="prop-input" value="' + escapeHtml(att.label || 'Proof / Attachment') + '" oninput="updateAttachmentProp(\'label\', this.value)"></div>' +
      '<div class="prop-field-group"><label class="prop-label">File Type Preset</label>' +
      '<select class="select" onchange="applyProofPreset(this.value)">' +
      '<option value="pdf_only" ' + (att.preset === 'pdf_only' ? 'selected' : '') + '>PDF Document Only (Recommended)</option>' +
      '<option value="images_only" ' + (att.preset === 'images_only' ? 'selected' : '') + '>Images Only (PNG, JPG, WEBP)</option>' +
      '<option value="office_docs" ' + (att.preset === 'office_docs' ? 'selected' : '') + '>Office Documents (DOC, DOCX, XLS, XLSX)</option>' +
      '<option value="pdf_images" ' + (att.preset === 'pdf_images' ? 'selected' : '') + '>PDF and Images</option>' +
      '<option value="docs_sheets" ' + (att.preset === 'docs_sheets' ? 'selected' : '') + '>Documents and Spreadsheets</option>' +
      '<option value="custom" ' + (att.preset === 'custom' ? 'selected' : '') + '>Custom Formats</option>' +
      '</select></div>' +

      '<div class="prop-field-group"><label class="prop-label">Max File Size: <span id="maxMbValueDisplay">' + (att.max_file_mb || 2) + ' MB</span></label>' +
      '<input type="range" min="1" max="50" step="1" value="' + (att.max_file_mb || 2) + '" oninput="updateMaxMb(this.value)" style="width:100%;">' +
      '</div>' +

      '<div class="prop-field-group"><label class="prop-label">Max Files Allowed: <span id="maxFilesDisplay">' + (att.max_files || 1) + ' file(s)</span></label>' +
      '<input type="range" min="1" max="10" step="1" value="' + (att.max_files || 1) + '" oninput="updateMaxFiles(this.value)" style="width:100%;">' +
      '</div>' +

      '<div class="prop-field-group"><label class="prop-label">Respondent Instructions</label>' +
      '<input type="text" class="prop-input" value="' + escapeHtml(att.help_text || '') + '" oninput="updateAttachmentProp(\'help_text\', this.value)">' +
      '</div>' +

      '<div style="display:flex;align-items:center;justify-content:space-between;margin-top:6px;">' +
      '<span style="font-size:12px;font-weight:700;color:var(--studio-navy);">Mandatory Proof Submission</span>' +
      '<input type="checkbox" ' + (att.required ? 'checked' : '') + ' onchange="updateAttachmentProp(\'required\', this.checked)">' +
      '</div>' +
      '</div>';

    return wrap;
  }

  // ---------------------------------------------------------------------------
  // 6. Interactive Live Preview & Reactive Conditional Logic
  // ---------------------------------------------------------------------------
  function renderInteractivePreview() {
    var container = document.getElementById('interactivePreviewContainer');
    if (!container) return;
    container.innerHTML = '';

    var form = document.createElement('form');
    form.id = 'previewFormSimulation';
    form.style.cssText = 'display:grid;grid-template-columns:repeat(12, 1fr);gap:16px 20px;align-items:start;';

    studioState.fields.forEach(function (f) {
      if (f.type === 'section_header') {
        var secHead = document.createElement('div');
        secHead.style.cssText = 'grid-column:1 / -1;border-bottom:2px solid #E2E8F0;padding-bottom:6px;margin-top:16px;margin-bottom:8px;font-weight:700;color:#131D3B;font-size:14px;';
        secHead.textContent = f.label || 'Section';
        if (f.help_text) {
          var subH = document.createElement('div');
          subH.style.cssText = 'font-size:11.5px;color:#64748B;font-weight:normal;margin-top:2px;';
          subH.textContent = f.help_text;
          secHead.appendChild(subH);
        }
        form.appendChild(secHead);
        return;
      }
      if (f.type === 'heading') {
        var hHead = document.createElement('div');
        hHead.style.cssText = 'grid-column:1 / -1;margin-top:12px;margin-bottom:4px;';
        hHead.innerHTML = '<h4 style="margin:0;font-size:13.5px;font-weight:700;color:#131D3B;">' + escapeHtml(f.label || '') + '</h4>';
        form.appendChild(hHead);
        return;
      }
      if (f.type === 'divider') {
        var div = document.createElement('div');
        div.style.cssText = 'grid-column:1 / -1;border-top:1px solid #E2E8F0;margin:12px 0;';
        form.appendChild(div);
        return;
      }
      if (f.type === 'instructions') {
        var inst = document.createElement('div');
        inst.style.cssText = 'grid-column:1 / -1;background:#F8FAFC;border:1px solid #CBD5E1;border-left:4px solid #3B82F6;border-radius:6px;padding:8px 12px;font-size:12px;color:#334155;';
        inst.textContent = f.label || f.help_text || '';
        form.appendChild(inst);
        return;
      }

      var span = getFieldSpan(f);
      var fieldWrap = document.createElement('div');
      fieldWrap.className = 'field preview-sim-field';
      fieldWrap.dataset.fieldKey = f.key;
      if (f.conditional && f.conditional.field) {
        fieldWrap.dataset.condField = f.conditional.field;
        fieldWrap.dataset.condOp = f.conditional.operator || 'equals';
        fieldWrap.dataset.condVal = f.conditional.value || '';
      }

      var fieldStyles = ['grid-column: span ' + span];
      if (f.offset && parseInt(f.offset, 10) > 0) {
        fieldStyles.push('grid-column-start: ' + (1 + parseInt(f.offset, 10)));
      }
      if (f.align && f.align !== 'stretch') {
        fieldStyles.push('justify-self: ' + f.align);
      }
      if (f.vertical_align) {
        fieldStyles.push('align-self: ' + f.vertical_align);
      }
      if (f.spacing) {
        var spacingMap = { compact: 'margin-bottom:8px', normal: 'margin-bottom:16px', relaxed: 'margin-bottom:24px', spacious: 'margin-bottom:32px' };
        if (spacingMap[f.spacing]) fieldStyles.push(spacingMap[f.spacing]);
      }
      if (f.custom_height) {
        if (!isNaN(parseInt(f.custom_height, 10))) {
          fieldStyles.push('min-height: ' + parseInt(f.custom_height, 10) + 'px');
        } else if (f.custom_height === 'compact') {
          fieldStyles.push('min-height: 36px');
        } else if (f.custom_height === 'tall') {
          fieldStyles.push('min-height: 80px');
        }
      }
      fieldWrap.style.cssText = fieldStyles.join('; ') + ';';

      var label = document.createElement('label');
      label.style.cssText = 'font-size:12px;font-weight:700;color:#131D3B;display:block;margin-bottom:4px;';
      label.innerHTML = escapeHtml(f.label) + (f.required ? ' <span style="color:#DC2626;">*</span>' : '');
      fieldWrap.appendChild(label);

      // Check role editability
      var isRoleDisabled = false;
      var r = studioState.previewRole;
      if (r === 'Faculty' && f.faculty_editable === false) isRoleDisabled = true;
      if (r === 'Coordinator' && f.coordinator_editable === false) isRoleDisabled = true;
      if (r === 'HoD' && f.hod_editable === false) isRoleDisabled = true;
      if (r === 'Principal' && f.principal_editable === false) isRoleDisabled = true;

      // Render actual interactive element
      var inputEl = createInteractiveInputElement(f, isRoleDisabled);
      fieldWrap.appendChild(inputEl);

      if (f.help_text) {
        var sub = document.createElement('div');
        sub.style.cssText = 'font-size:11px;color:#64748B;margin-top:2px;';
        sub.textContent = f.help_text;
        fieldWrap.appendChild(sub);
      }

      form.appendChild(fieldWrap);
    });

    // Add Simulated Proof Field
    var proofWrap = document.createElement('div');
    proofWrap.style.cssText = 'grid-column: 1 / -1; border:2px dashed #CBD5E1;border-radius:8px;padding:14px;background:#F8FAFC;margin-top:10px;';
    proofWrap.innerHTML = '<div style="font-size:12.5px;font-weight:700;color:var(--studio-navy);margin-bottom:4px;">' +
      escapeHtml(studioState.attachment.label || 'Proof / Attachment') + (studioState.attachment.required ? ' <span style="color:#DC2626;">*</span>' : '') + '</div>' +
      '<input type="file" class="input" style="font-size:11.5px;padding:4px 8px;width:100%;" ' + (studioState.attachment.max_files > 1 ? 'multiple' : '') + '>' +
      '<div style="font-size:11px;color:#64748B;margin-top:4px;">' + escapeHtml(studioState.attachment.help_text || '') + '</div>';
    form.appendChild(proofWrap);

    // Event listener for live reactive conditional rules
    form.addEventListener('input', evaluatePreviewConditions);
    form.addEventListener('change', evaluatePreviewConditions);

    container.appendChild(form);
    evaluatePreviewConditions();
  }

  function createInteractiveInputElement(f, isDisabled) {
    var disabledAttr = isDisabled ? 'disabled' : '';

    if (f.type === 'textarea') {
      var ta = document.createElement('textarea');
      ta.className = 'input';
      ta.name = f.key;
      ta.rows = 2;
      ta.placeholder = f.placeholder || '';
      if (isDisabled) ta.disabled = true;
      return ta;
    }

    if (f.type === 'select') {
      var wrap = document.createElement('div');
      wrap.style.cssText = 'width:100%;';

      var sel = document.createElement('select');
      sel.className = 'select';
      sel.name = f.key;
      if (isDisabled) sel.disabled = true;
      sel.innerHTML = '<option value="">' + escapeHtml(f.placeholder || 'Select option...') + '</option>';

      var options = resolveFieldOptions(f);
      var hasOther = false;
      options.forEach(function (opt) {
        var val = typeof opt === 'object' ? (opt.value || '') : opt;
        var lbl = typeof opt === 'object' ? (opt.label || val) : opt;
        var vLow = String(val).toLowerCase().trim();
        var lLow = String(lbl).toLowerCase().trim();
        if (vLow === 'other' || vLow === 'others' || lLow === 'other' || lLow === 'others') {
          hasOther = true;
        }
        sel.innerHTML += '<option value="' + escapeHtml(val) + '">' + escapeHtml(lbl) + '</option>';
      });
      wrap.appendChild(sel);

      if (hasOther) {
        var otherWrap = document.createElement('div');
        otherWrap.style.cssText = 'display:none;margin-top:6px;';
        otherWrap.innerHTML = '<label style="font-size:11px;font-weight:600;color:#475569;display:block;margin-bottom:2px;">' + escapeHtml(f.label) + ' (Please specify)' + (f.required ? ' <span style="color:#DC2626;">*</span>' : '') + '</label>' +
          '<input type="text" class="input" style="height:32px;font-size:12px;" placeholder="Please specify ' + escapeHtml(f.label) + '..." ' + (isDisabled ? 'disabled' : '') + '>';
        wrap.appendChild(otherWrap);

        sel.addEventListener('change', function () {
          var v = (sel.value || '').toLowerCase().trim();
          var isOther = (v === 'other' || v === 'others');
          otherWrap.style.display = isOther ? 'block' : 'none';
          if (isOther && !isDisabled) {
            var inp = otherWrap.querySelector('input');
            if (inp) inp.focus();
          }
        });
      }

      return wrap;
    }

    if (f.type === 'radio' || f.type === 'checkbox') {
      var wrap = document.createElement('div');
      wrap.style.cssText = 'width:100%;';

      var group = document.createElement('div');
      group.style.cssText = 'display:flex;flex-wrap:wrap;gap:10px;margin-top:2px;';
      var opts = resolveFieldOptions(f);
      if (opts.length === 0) opts = ['Yes', 'No'];

      var hasOtherRadio = false;
      opts.forEach(function (opt) {
        var val = typeof opt === 'object' ? (opt.value || '') : opt;
        var lbl = typeof opt === 'object' ? (opt.label || val) : opt;
        var vLow = String(val).toLowerCase().trim();
        var lLow = String(lbl).toLowerCase().trim();
        if (vLow === 'other' || vLow === 'others' || lLow === 'other' || lLow === 'others') {
          hasOtherRadio = true;
        }
        group.innerHTML += '<label style="display:inline-flex;align-items:center;gap:6px;font-size:12px;cursor:pointer;">' +
          '<input type="' + (f.type === 'radio' ? 'radio' : 'checkbox') + '" name="' + escapeHtml(f.key) + '" value="' + escapeHtml(val) + '" ' + disabledAttr + '> ' +
          '<span>' + escapeHtml(lbl) + '</span></label>';
      });
      wrap.appendChild(group);

      if (hasOtherRadio && f.type === 'radio') {
        var otherWrap = document.createElement('div');
        otherWrap.style.cssText = 'display:none;margin-top:6px;';
        otherWrap.innerHTML = '<label style="font-size:11px;font-weight:600;color:#475569;display:block;margin-bottom:2px;">' + escapeHtml(f.label) + ' (Please specify)' + (f.required ? ' <span style="color:#DC2626;">*</span>' : '') + '</label>' +
          '<input type="text" class="input" style="height:32px;font-size:12px;" placeholder="Please specify ' + escapeHtml(f.label) + '..." ' + (isDisabled ? 'disabled' : '') + '>';
        wrap.appendChild(otherWrap);

        group.querySelectorAll('input[type="radio"]').forEach(function (r) {
          r.addEventListener('change', function () {
            var v = (this.value || '').toLowerCase().trim();
            var isOther = (v === 'other' || v === 'others');
            otherWrap.style.display = isOther ? 'block' : 'none';
            if (isOther && !isDisabled) {
              var inp = otherWrap.querySelector('input');
              if (inp) inp.focus();
            }
          });
        });
      }

      return wrap;
    }

    var inp = document.createElement('input');
    inp.className = 'input';
    inp.name = f.key;
    inp.type = (f.type === 'integer' || f.type === 'decimal') ? 'number' : (f.type === 'date' ? 'date' : (f.type === 'time' ? 'time' : 'text'));
    inp.placeholder = f.placeholder || '';
    if (isDisabled) inp.disabled = true;
    return inp;
  }

  function resolveFieldOptions(f) {
    if (f.options_mode === 'shared' && f.option_set_key) {
      for (var i = 0; i < studioState.optionSets.length; i++) {
        if (studioState.optionSets[i].set_key === f.option_set_key) {
          return studioState.optionSets[i].options_parsed || [];
        }
      }
    }
    return Array.isArray(f.options) ? f.options : [];
  }

  function evaluatePreviewConditions() {
    var form = document.getElementById('previewFormSimulation');
    if (!form) return;

    var condFields = form.querySelectorAll('[data-cond-field]');
    condFields.forEach(function (fieldWrap) {
      var triggerKey = fieldWrap.dataset.condField;
      var op = fieldWrap.dataset.condOp || 'equals';
      var triggerVal = fieldWrap.dataset.condVal || '';

      var triggerInputs = form.querySelectorAll('[name="' + triggerKey + '"], [name="' + triggerKey + '[]"]');
      var curVal = '';
      if (triggerInputs.length === 1 && triggerInputs[0].type === 'checkbox') {
        curVal = triggerInputs[0].checked ? (triggerInputs[0].value || '1') : '';
      } else if (triggerInputs.length > 0 && triggerInputs[0].type === 'radio') {
        var checkedRadio = form.querySelector('[name="' + triggerKey + '"]:checked');
        curVal = checkedRadio ? checkedRadio.value : '';
      } else if (triggerInputs.length > 0) {
        curVal = triggerInputs[0].value;
      }

      var isVisible = false;
      var strCur = String(curVal).trim().toLowerCase();
      var strTrig = String(triggerVal).trim().toLowerCase();

      switch (op) {
        case 'equals':
          isVisible = (strCur === strTrig);
          break;
        case 'not_equals':
          isVisible = (strCur !== strTrig);
          break;
        case 'contains':
          isVisible = (strCur.indexOf(strTrig) !== -1);
          break;
        case 'is_empty':
          isVisible = (strCur === '');
          break;
        case 'is_not_empty':
          isVisible = (strCur !== '');
          break;
        case 'greater_than':
          isVisible = (parseFloat(curVal) > parseFloat(triggerVal));
          break;
        case 'less_than':
          isVisible = (parseFloat(curVal) < parseFloat(triggerVal));
          break;
        default:
          isVisible = true;
      }

      fieldWrap.style.display = isVisible ? 'block' : 'none';
    });
  }

  function testPreviewSubmission() {
    var form = document.getElementById('previewFormSimulation');
    if (!form) return;

    if (form.checkValidity && !form.checkValidity()) {
      if (typeof form.reportValidity === 'function') {
        form.reportValidity();
      } else {
        alert('Please fill out all required fields marked with * before submitting simulation.');
      }
      return;
    }

    var formData = new FormData(form);
    var payload = {};
    formData.forEach(function (val, key) {
      payload[key] = val;
    });

    alert('Simulation test successful!\nPayload captured by respondent form:\n\n' + JSON.stringify(payload, null, 2));
  }

  // ---------------------------------------------------------------------------
  // 7. Palette Add, Drag & Drop, Mutations
  // ---------------------------------------------------------------------------
  function onPaletteDragStart(e, type) {
    if (e && e.dataTransfer) {
      e.dataTransfer.setData('text/plain', JSON.stringify({ source: 'palette', type: type }));
      e.dataTransfer.effectAllowed = 'copy';
    }
    var span = (['section_header', 'heading', 'divider', 'instructions'].indexOf(type) !== -1) ? 12 : 6;
    canvasDragState.isDragging = true;
    canvasDragState.source = 'palette';
    canvasDragState.fromIndex = -1;
    canvasDragState.draggedCard = null;
    canvasDragState.paletteType = type;
    canvasDragState.span = span;

    var surface = document.querySelector('.canvas-form-surface');
    if (surface) surface.classList.add('show-grid-guides');
  }

  function onCanvasDragOver(e) {
    if (e) e.preventDefault();
    if (e && e.dataTransfer) e.dataTransfer.dropEffect = 'move';

    if (!canvasDragState.isDragging || !canvasDragState.placeholder) return;

    var container = document.getElementById('canvasSectionsContainer');
    if (!container) return;

    var placeholder = canvasDragState.placeholder;
    var cards = Array.from(container.querySelectorAll('.canvas-field-card:not(.is-dragging), .canvas-section-header:not(.is-dragging)'));
    if (cards.length > 0) {
      var first = cards[0];
      var last = cards[cards.length - 1];
      var firstRect = first.getBoundingClientRect();
      var lastRect = last.getBoundingClientRect();

      if (e.clientY < firstRect.top) {
        if (container.firstElementChild !== placeholder) {
          container.insertBefore(placeholder, container.firstElementChild);
        }
      } else if (e.clientY > lastRect.bottom) {
        if (container.lastElementChild !== placeholder) {
          container.appendChild(placeholder);
        }
      }
    }
  }

  function onCanvasDrop(e) {
    executeCanvasDrop(e);
  }

  function toggleFieldWidth(idx) {
    if (!studioState.fields[idx]) return;
    recordState();
    var cur = studioState.fields[idx].width || (studioState.fields[idx].col_span === 2 ? 'full' : 'half');
    var next = (cur === 'full') ? 'half' : 'full';
    studioState.fields[idx].width = next;
    studioState.fields[idx].col_span = (next === 'full') ? 2 : 1;
    renderCanvas();
    renderInspector();
    renderInteractivePreview();
    markDirty();
  }

  function setFieldWidth(idx, width) {
    if (!studioState.fields[idx]) return;
    recordState();
    studioState.fields[idx].width = width;
    studioState.fields[idx].col_span = (width === 'full') ? 2 : 1;
    renderCanvas();
    renderInspector();
    renderInteractivePreview();
    markDirty();
  }

  function setFieldColumnSpan(idx, span, isLiveSlider) {
    if (!studioState.fields[idx]) return;
    span = parseInt(span, 10);
    if (isNaN(span) || span < 1) span = 1;
    if (span > 12) span = 12;

    if (!isLiveSlider) {
      recordState();
    }
    studioState.fields[idx].col_span = span;
    studioState.fields[idx].width = (span === 12) ? 'full' : (span === 6 ? 'half' : String(span));

    var card = document.querySelector('.field-designer-card[data-field-index="' + idx + '"]');
    if (card) {
      card.dataset.colSpan = span;
      card.style.gridColumn = 'span ' + span;
      var badge = card.querySelector('.field-span-badge');
      if (badge) badge.textContent = span + ' col' + (span > 1 ? 's' : '') + ' (' + Math.round((span / 12) * 100) + '%)';
    }

    var disp = document.getElementById('colSpanValueDisplay');
    if (disp) {
      disp.textContent = span + ' cols (' + Math.round((span / 12) * 100) + '%)';
    }

    if (!isLiveSlider) {
      renderCanvas();
      renderInspector();
      renderInteractivePreview();
      markDirty();
    }
  }

  function setFieldAlignment(idx, align) {
    if (!studioState.fields[idx]) return;
    recordState();
    studioState.fields[idx].align = align;
    renderCanvas();
    renderInspector();
    renderInteractivePreview();
    markDirty();
  }

  function setFieldVerticalAlignment(idx, valign) {
    if (!studioState.fields[idx]) return;
    recordState();
    studioState.fields[idx].vertical_align = valign;
    renderCanvas();
    renderInspector();
    renderInteractivePreview();
    markDirty();
  }

  function setFieldSpacing(idx, spacing) {
    if (!studioState.fields[idx]) return;
    recordState();
    studioState.fields[idx].spacing = spacing;
    renderCanvas();
    renderInspector();
    renderInteractivePreview();
    markDirty();
  }

  function setFieldCustomHeight(idx, height) {
    if (!studioState.fields[idx]) return;
    recordState();
    studioState.fields[idx].custom_height = height;
    renderCanvas();
    renderInspector();
    renderInteractivePreview();
    markDirty();
  }

  function setFieldOffset(idx, offset) {
    if (!studioState.fields[idx]) return;
    offset = parseInt(offset, 10);
    if (isNaN(offset) || offset < 0) offset = 0;
    if (offset > 11) offset = 11;
    recordState();
    studioState.fields[idx].offset = offset;
    renderCanvas();
    renderInspector();
    renderInteractivePreview();
    markDirty();
  }

  function moveFieldToSection(idx, sectionId) {
    if (!studioState.fields[idx]) return;
    recordState();
    studioState.fields[idx].section_id = sectionId || '';
    renderCanvas();
    renderInspector();
    renderInteractivePreview();
    markDirty();
  }

  function applyPlacementTemplate() {
    if (studioState.fields.length > 0) {
      if (!confirm('Apply the 2-column Student Placement layout? This will load the 11 standard placement fields and official offer letter proof.')) {
        return;
      }
    }

    recordState();
    studioState.metric.name = 'Student Campus Placement & Higher Studies';
    studioState.metric.category = 'STUDENT SUPPORT AND PROGRESSION';
    studioState.metric.description = 'Record verified student on-campus and off-campus placements, package offers, and higher education progression with official proof.';

    studioState.fields = [
      {
        key: 'department',
        label: 'Department',
        type: 'select',
        width: 'half',
        col_span: 1,
        required: true,
        options: ['Computer Science and Engineering', 'Electronics and Communication Engineering', 'Electrical and Electronics Engineering', 'Mechanical Engineering', 'Civil Engineering', 'Information Technology', 'Artificial Intelligence and Data Science', 'Management Studies (MBA)'],
        options_mode: 'custom',
        faculty_editable: true,
        coordinator_editable: true,
        hod_editable: true,
        principal_editable: true
      },
      {
        key: 'academic_year',
        label: 'Academic Year',
        type: 'select',
        width: 'half',
        col_span: 1,
        required: true,
        options: ['2025-26', '2024-25', '2023-24', '2022-23'],
        options_mode: 'custom',
        faculty_editable: true,
        coordinator_editable: true,
        hod_editable: true,
        principal_editable: true
      },
      {
        key: 'batch',
        label: 'Batch / Class',
        type: 'text',
        width: 'half',
        col_span: 1,
        placeholder: 'e.g. 2022-2026 Batch',
        help_text: 'Graduating degree cohort',
        required: true,
        faculty_editable: true,
        coordinator_editable: true,
        hod_editable: true,
        principal_editable: true
      },
      {
        key: 'register_number',
        label: 'Register Number',
        type: 'text',
        width: 'half',
        col_span: 1,
        placeholder: 'e.g. 910022104001',
        help_text: 'Unique university roll / reg number',
        required: true,
        faculty_editable: true,
        coordinator_editable: true,
        hod_editable: true,
        principal_editable: true
      },
      {
        key: 'student_name',
        label: 'Student Name',
        type: 'text',
        width: 'half',
        col_span: 1,
        placeholder: 'Full student name as per university records',
        required: true,
        faculty_editable: true,
        coordinator_editable: true,
        hod_editable: true,
        principal_editable: true
      },
      {
        key: 'company_name',
        label: 'Company / Employer Name',
        type: 'text',
        width: 'half',
        col_span: 1,
        placeholder: 'e.g. Tata Consultancy Services, Infosys, Zoho',
        required: true,
        faculty_editable: true,
        coordinator_editable: true,
        hod_editable: true,
        principal_editable: true
      },
      {
        key: 'company_type',
        label: 'Company Type / Sector',
        type: 'select',
        width: 'half',
        col_span: 1,
        required: true,
        options: ['Tier-1 MNC', 'Core Engineering & Manufacturing', 'IT & Software Services', 'Product / Startup', 'Consulting & Financial Services', 'Government / Public Sector Unit (PSU)'],
        options_mode: 'custom',
        faculty_editable: true,
        coordinator_editable: true,
        hod_editable: true,
        principal_editable: true
      },
      {
        key: 'placement_status',
        label: 'Placement / Progression Status',
        type: 'select',
        width: 'half',
        col_span: 1,
        required: true,
        options: ['On-Campus Placement', 'Off-Campus Placement', 'Higher Studies (GATE/GRE/CAT)', 'Entrepreneurship / Startup Founder'],
        options_mode: 'custom',
        faculty_editable: true,
        coordinator_editable: true,
        hod_editable: true,
        principal_editable: true
      },
      {
        key: 'package_ctc',
        label: 'Package / CTC (LPA)',
        type: 'decimal',
        width: 'half',
        col_span: 1,
        placeholder: 'Salary package in Lakhs per annum (e.g. 6.50)',
        help_text: 'Gross annual compensation in INR Lakhs',
        required: true,
        faculty_editable: true,
        coordinator_editable: true,
        hod_editable: true,
        principal_editable: true
      },
      {
        key: 'placement_date',
        label: 'Placement / Offer Date',
        type: 'date',
        width: 'half',
        col_span: 1,
        help_text: 'Date of offer letter release or drive completion',
        required: true,
        faculty_editable: true,
        coordinator_editable: true,
        hod_editable: true,
        principal_editable: true
      },
      {
        key: 'remarks',
        label: 'Remarks / Designation & Job Profile',
        type: 'textarea',
        width: 'full',
        col_span: 2,
        placeholder: 'Specify role title (e.g. Systems Engineer, Graduate Trainee), joining location, and drive details...',
        help_text: 'Spans full width for comprehensive notes and outcomes',
        required: false,
        faculty_editable: true,
        coordinator_editable: true,
        hod_editable: true,
        principal_editable: true
      }
    ];

    studioState.attachment = {
      enabled: true,
      label: 'Offer Letter / Placement Order',
      required: true,
      preset: 'pdf_only',
      allowed_types: ['pdf'],
      max_file_mb: 2,
      max_files: 1,
      min_files: 1,
      help_text: 'Upload official offer letter or selection confirmation (PDF strictly 2 MB or less).'
    };

    updateTopbarUI();
    renderCanvas();
    renderInspector();
    renderInteractivePreview();
    markDirty();
  }

  function applyFacultyActivityTemplate() {
    if (studioState.fields.length > 0 && !confirm('Apply Faculty Development / Workshop Activity layout?')) return;
    recordState();
    studioState.metric.name = 'Faculty Development & Workshop Initiatives';
    studioState.metric.category = 'TEACHING-LEARNING AND EVALUATION';
    studioState.fields = [
      { key: 'department', label: 'Department', type: 'select', width: 'half', col_span: 1, required: true, options: ['Computer Science and Engineering', 'Electronics and Communication Engineering', 'Mechanical Engineering', 'Civil Engineering', 'Management Studies'], options_mode: 'custom', faculty_editable: true, coordinator_editable: true, hod_editable: true, principal_editable: true },
      { key: 'academic_year', label: 'Academic Year', type: 'select', width: 'half', col_span: 1, required: true, options: ['2025-26', '2024-25', '2023-24'], options_mode: 'custom', faculty_editable: true, coordinator_editable: true, hod_editable: true, principal_editable: true },
      { key: 'activity_title', label: 'Program / Workshop Title', type: 'text', width: 'half', col_span: 1, placeholder: 'e.g. AI & Machine Learning in Engineering', required: true, faculty_editable: true, coordinator_editable: true, hod_editable: true, principal_editable: true },
      { key: 'organizing_agency', label: 'Organizing Institution / Agency', type: 'text', width: 'half', col_span: 1, placeholder: 'e.g. IIT Madras, NPTEL, ATAL Academy', required: true, faculty_editable: true, coordinator_editable: true, hod_editable: true, principal_editable: true },
      { key: 'start_date', label: 'Start Date', type: 'date', width: 'half', col_span: 1, required: true, faculty_editable: true, coordinator_editable: true, hod_editable: true, principal_editable: true },
      { key: 'end_date', label: 'End Date', type: 'date', width: 'half', col_span: 1, required: true, faculty_editable: true, coordinator_editable: true, hod_editable: true, principal_editable: true },
      { key: 'duration_days', label: 'Duration (in Days)', type: 'integer', width: 'half', col_span: 1, placeholder: 'e.g. 5', required: true, faculty_editable: true, coordinator_editable: true, hod_editable: true, principal_editable: true },
      { key: 'financial_support', label: 'Institutional Support (in INR)', type: 'decimal', width: 'half', col_span: 1, placeholder: 'Amount in INR (0 if self-financed)', required: false, faculty_editable: true, coordinator_editable: true, hod_editable: true, principal_editable: true },
      { key: 'remarks', label: 'Outcomes & Quality Impact', type: 'textarea', width: 'full', col_span: 2, placeholder: 'Pedagogical impact, curriculum enrichment, certifications earned...', required: false, faculty_editable: true, coordinator_editable: true, hod_editable: true, principal_editable: true }
    ];
    studioState.attachment = {
      enabled: true,
      label: 'Completion Certificate / Sanction Letter',
      required: true,
      preset: 'pdf_only',
      allowed_types: ['pdf'],
      max_file_mb: 2,
      max_files: 1,
      min_files: 1,
      help_text: 'Participation certificate or financial sanction order (PDF max 2 MB).'
    };
    updateTopbarUI();
    renderCanvas();
    renderInspector();
    renderInteractivePreview();
    markDirty();
  }

  function applyPublicationTemplate() {
    if (studioState.fields.length > 0 && !confirm('Apply Research Publication & Journal layout?')) return;
    recordState();
    studioState.metric.name = 'Research Papers in UGC / Scopus Journals';
    studioState.metric.category = 'RESEARCH, INNOVATIONS AND EXTENSION';
    studioState.fields = [
      { key: 'department', label: 'Department', type: 'select', width: 'half', col_span: 1, required: true, options: ['Computer Science and Engineering', 'Electronics and Communication Engineering', 'Mechanical Engineering', 'Civil Engineering'], options_mode: 'custom', faculty_editable: true, coordinator_editable: true, hod_editable: true, principal_editable: true },
      { key: 'academic_year', label: 'Academic Year', type: 'select', width: 'half', col_span: 1, required: true, options: ['2025-26', '2024-25', '2023-24'], options_mode: 'custom', faculty_editable: true, coordinator_editable: true, hod_editable: true, principal_editable: true },
      { key: 'paper_title', label: 'Title of the Research Paper', type: 'text', width: 'half', col_span: 1, placeholder: 'Full title of the published paper', required: true, faculty_editable: true, coordinator_editable: true, hod_editable: true, principal_editable: true },
      { key: 'journal_name', label: 'Journal Name', type: 'text', width: 'half', col_span: 1, placeholder: 'e.g. IEEE Transactions, Springer Nature', required: true, faculty_editable: true, coordinator_editable: true, hod_editable: true, principal_editable: true },
      { key: 'indexing_type', label: 'Indexing Database', type: 'select', width: 'half', col_span: 1, required: true, options: ['Scopus Indexed', 'Web of Science (SCI/SCIE)', 'UGC-CARE List Group I', 'UGC-CARE List Group II'], options_mode: 'custom', faculty_editable: true, coordinator_editable: true, hod_editable: true, principal_editable: true },
      { key: 'issn_number', label: 'ISSN Number', type: 'text', width: 'half', col_span: 1, placeholder: 'e.g. 2320-2882', required: true, faculty_editable: true, coordinator_editable: true, hod_editable: true, principal_editable: true },
      { key: 'publication_date', label: 'Publication Date', type: 'date', width: 'half', col_span: 1, required: true, faculty_editable: true, coordinator_editable: true, hod_editable: true, principal_editable: true },
      { key: 'doi_link', label: 'DOI / Web Link', type: 'url', width: 'half', col_span: 1, placeholder: 'https://doi.org/...', required: false, faculty_editable: true, coordinator_editable: true, hod_editable: true, principal_editable: true },
      { key: 'abstract_summary', label: 'Abstract / Paper Summary', type: 'textarea', width: 'full', col_span: 2, placeholder: 'Brief summary of the research methodology and key findings...', required: false, faculty_editable: true, coordinator_editable: true, hod_editable: true, principal_editable: true }
    ];
    studioState.attachment = {
      enabled: true,
      label: 'Published Paper First Page / Reprints',
      required: true,
      preset: 'pdf_only',
      allowed_types: ['pdf'],
      max_file_mb: 2,
      max_files: 1,
      min_files: 1,
      help_text: 'PDF reprint showing journal name, ISSN, author affiliation and date.'
    };
    updateTopbarUI();
    renderCanvas();
    renderInspector();
    renderInteractivePreview();
    markDirty();
  }

  function addFieldFromPalette(type, atIndex) {
    recordState();
    var key = generateUniqueFieldKey(type);
    var defLabel = formatDefaultLabel(type);
    var normType = normalizeFieldType(type);
    var isFullDefault = (type === 'remarks' || type === 'textarea' || type === 'table_grid' || type === 'section_header' || type === 'instructions' || type === 'divider' || type === 'section_container' || type === 'acknowledgement' || type === 'hidden_field');

    var newField = {
      key: key,
      label: defLabel,
      type: normType,
      width: isFullDefault ? 'full' : 'half',
      col_span: isFullDefault ? 2 : 1,
      placeholder: getDefaultPlaceholder(type),
      help_text: '',
      required: isFieldRequiredByDefault(type),
      faculty_editable: true,
      coordinator_editable: true,
      hod_editable: true,
      principal_editable: true,
      options: getDefaultOptions(type),
      options_mode: 'custom'
    };

    if (type === 'readonly') {
      newField.readonly = true;
    }

    if (typeof atIndex === 'number' && atIndex >= 0 && atIndex <= studioState.fields.length) {
      studioState.fields.splice(atIndex, 0, newField);
      renderCanvas();
      selectField(atIndex);
    } else {
      studioState.fields.push(newField);
      renderCanvas();
      selectField(studioState.fields.length - 1);
    }
    renderInteractivePreview();
    markDirty();
  }

  function moveField(fromIdx, toIdx) {
    if (fromIdx < 0 || fromIdx >= studioState.fields.length) return;
    if (toIdx < 0) toIdx = 0;
    if (toIdx >= studioState.fields.length) toIdx = studioState.fields.length - 1;
    if (fromIdx === toIdx) return;

    recordState();
    var moved = studioState.fields.splice(fromIdx, 1)[0];
    studioState.fields.splice(toIdx, 0, moved);

    renderCanvas();
    selectField(toIdx);
    renderInteractivePreview();
    markDirty();
  }

  function moveFieldUp(idx) {
    if (idx > 0) moveField(idx, idx - 1);
  }

  function moveFieldDown(idx) {
    if (idx < studioState.fields.length - 1) moveField(idx, idx + 1);
  }

  function duplicateField(idx) {
    if (!studioState.fields[idx]) return;
    recordState();

    var clone = JSON.parse(JSON.stringify(studioState.fields[idx]));
    clone.key = clone.key + '_copy_' + Math.floor(Math.random() * 1000);
    clone.label = clone.label + ' (Copy)';
    studioState.fields.splice(idx + 1, 0, clone);

    renderCanvas();
    selectField(idx + 1);
    renderInteractivePreview();
  }

  function deleteField(idx) {
    var f = studioState.fields[idx];
    if (!f) return;

    if (f.protected) {
      alert("Field '" + f.label + "' is marked as a protected core institutional metric and cannot be removed.");
      return;
    }

    if (!confirm("Are you sure you want to remove the field '" + f.label + "'?")) {
      return;
    }

    recordState();
    studioState.fields.splice(idx, 1);
    renderCanvas();
    selectFormSettings();
    renderInteractivePreview();
  }

  function saveFieldToReusable(idx) {
    var f = studioState.fields[idx];
    if (!f) return;

    var payload = new FormData();
    payload.append('action', 'save_reusable_field');
    payload.append('csrf', getCsrfToken());
    payload.append('csrf_token', getCsrfToken());
    payload.append('field_json', JSON.stringify(f));

    fetch('form-studio.php?ajax=1', {
      method: 'POST',
      headers: {
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-Token': getCsrfToken()
      },
      body: payload
    })
    .then(function (res) { return res.json(); })
    .then(function (data) {
      if (data && data.success) {
        studioState.reusableFields = data.reusable_fields || studioState.reusableFields;
        renderReusableList();
        alert("Field '" + f.label + "' saved to Reusable Field Library!");
      } else {
        alert('Failed to save reusable field.');
      }
    });
  }

  // ---------------------------------------------------------------------------
  // 8. Properties Update Handlers
  // ---------------------------------------------------------------------------
  function updateFieldProperty(idx, prop, val) {
    if (!studioState.fields[idx]) return;
    recordState();
    studioState.fields[idx][prop] = val;

    if (prop === 'width') {
      studioState.fields[idx].col_span = (val === 'full') ? 2 : 1;
    } else if (prop === 'readonly') {
      studioState.fields[idx].readonly = !!val;
    }

    renderCanvas();
    renderInteractivePreview();
    markDirty();
  }

  function updateConditionProp(idx, prop, val) {
    if (!studioState.fields[idx]) return;
    recordState();
    if (!studioState.fields[idx].conditional) {
      studioState.fields[idx].conditional = { field: '', operator: 'equals', value: '' };
    }
    studioState.fields[idx].conditional[prop] = val;
    renderCanvas();
    renderInteractivePreview();
  }

  function toggleConditionalLogic(idx, enabled) {
    if (!studioState.fields[idx]) return;
    recordState();
    if (enabled) {
      studioState.fields[idx].conditional = { field: '', operator: 'equals', value: '' };
    } else {
      delete studioState.fields[idx].conditional;
    }
    renderCanvas();
    renderInspector();
    renderInteractivePreview();
  }

  function setOptionMode(idx, mode) {
    if (!studioState.fields[idx]) return;
    recordState();
    studioState.fields[idx].options_mode = mode;
    if (mode === 'shared' && !studioState.fields[idx].option_set_key && studioState.optionSets.length > 0) {
      studioState.fields[idx].option_set_key = studioState.optionSets[0].set_key;
    }
    renderInspector();
    renderInteractivePreview();
  }

  function addCustomOption(idx) {
    if (!studioState.fields[idx]) return;
    recordState();
    if (!Array.isArray(studioState.fields[idx].options)) {
      studioState.fields[idx].options = [];
    }
    var count = studioState.fields[idx].options.length + 1;
    studioState.fields[idx].options.push('Option ' + count);
    renderInspector();
    renderInteractivePreview();
    renderCanvas();
  }

  function addOtherOption(idx) {
    if (!studioState.fields[idx]) return;
    recordState();
    if (!Array.isArray(studioState.fields[idx].options)) {
      studioState.fields[idx].options = [];
    }
    var exists = studioState.fields[idx].options.some(function (opt) {
      var v = String(typeof opt === 'object' ? (opt.value || opt.label || '') : opt).toLowerCase().trim();
      return v === 'other' || v === 'others';
    });
    if (!exists) {
      studioState.fields[idx].options.push('Other');
    }
    renderInspector();
    renderInteractivePreview();
    renderCanvas();
    showToast('info', "'Other' option added — faculty will see an automatic text field when selected");
  }

  function updateCustomOption(idx, oIdx, val) {
    if (!studioState.fields[idx] || !studioState.fields[idx].options) return;
    studioState.fields[idx].options[oIdx] = val;
    markDirty();
    renderInteractivePreview();
    renderCanvas();
  }

  function removeCustomOption(idx, oIdx) {
    if (!studioState.fields[idx] || !studioState.fields[idx].options) return;
    recordState();
    studioState.fields[idx].options.splice(oIdx, 1);
    renderInspector();
    renderInteractivePreview();
    renderCanvas();
  }

  function updateFormSetting(key, val) {
    recordState();
    studioState.metric[key] = val;
    updateTopbarUI();
  }

  function updateAttachmentProp(key, val) {
    recordState();
    studioState.attachment[key] = val;
    updateProofSummaryDisplay();
  }

  function applyProofPreset(preset) {
    recordState();
    studioState.attachment.preset = preset;
    switch (preset) {
      case 'pdf_only':
        studioState.attachment.allowed_types = ['pdf'];
        studioState.attachment.help_text = 'PDF document strictly ' + studioState.attachment.max_file_mb + ' MB or less.';
        break;
      case 'images_only':
        studioState.attachment.allowed_types = ['png', 'jpg', 'jpeg', 'webp'];
        studioState.attachment.help_text = 'Image files (PNG, JPG, WEBP) strictly ' + studioState.attachment.max_file_mb + ' MB or less.';
        break;
      case 'office_docs':
        studioState.attachment.allowed_types = ['doc', 'docx', 'xls', 'xlsx'];
        studioState.attachment.help_text = 'Office documents strictly ' + studioState.attachment.max_file_mb + ' MB or less.';
        break;
      case 'pdf_images':
        studioState.attachment.allowed_types = ['pdf', 'png', 'jpg', 'jpeg'];
        studioState.attachment.help_text = 'PDF or image files strictly ' + studioState.attachment.max_file_mb + ' MB or less.';
        break;
      case 'docs_sheets':
        studioState.attachment.allowed_types = ['pdf', 'docx', 'xlsx'];
        studioState.attachment.help_text = 'PDF, Word, or Excel files strictly ' + studioState.attachment.max_file_mb + ' MB or less.';
        break;
    }
    updateProofSummaryDisplay();
    renderInspector();
  }

  function updateMaxMb(val) {
    updateAttachmentProp('max_file_mb', parseInt(val, 10));
    var disp = document.getElementById('maxMbValueDisplay');
    if (disp) disp.textContent = val + ' MB';
  }

  function updateMaxFiles(val) {
    updateAttachmentProp('max_files', parseInt(val, 10));
    var disp = document.getElementById('maxFilesDisplay');
    if (disp) disp.textContent = val + ' file(s)';
  }

  function updateProofSummaryDisplay() {
    var att = studioState.attachment;
    var cardLbl = document.getElementById('proofCardLabel');
    if (cardLbl) {
      cardLbl.innerHTML = escapeHtml(att.label || 'Proof / Attachment') + (att.required ? ' <span style="color:#DC2626;">*</span>' : '');
    }
    var cardHelp = document.getElementById('proofCardHelpText');
    if (cardHelp) {
      cardHelp.textContent = att.help_text || 'Official supporting document verification.';
    }

    var types = (att.allowed_types || ['pdf']).join(', ').toUpperCase();
    var maxMb = att.max_file_mb || 2;
    var maxFiles = att.max_files || 1;
    var summary = types + ' · Maximum ' + maxMb + ' MB per file · Up to ' + maxFiles + ' file' + (maxFiles > 1 ? 's' : '');

    var badgeText = document.getElementById('proofSummaryText');
    if (badgeText) badgeText.textContent = summary;
  }

  // ---------------------------------------------------------------------------
  // 9. Reusable Fields & Option Sets Tabs
  // ---------------------------------------------------------------------------
  function renderReusableList() {
    var cont = document.getElementById('reusableListContainer');
    if (!cont) return;
    cont.innerHTML = '';

    studioState.reusableFields.forEach(function (rf) {
      var item = document.createElement('div');
      item.style.cssText = 'background:#FFFFFF;border:1px solid #CBD5E1;border-radius:6px;padding:8px 10px;display:flex;align-items:center;justify-content:space-between;gap:8px;';
      item.innerHTML = '<div><div style="font-size:12px;font-weight:700;color:var(--studio-navy);">' + escapeHtml(rf.label) + '</div>' +
        '<div style="font-size:10.5px;color:#64748B;">' + escapeHtml(rf.type) + '</div></div>' +
        '<button type="button" class="btn btn-sm btn-outline" style="font-size:11px;padding:2px 8px;">+ Add</button>';

      item.querySelector('button').onclick = function () {
        recordState();
        var copy = JSON.parse(JSON.stringify(rf));
        copy.key = copy.field_key || (copy.key + '_' + Math.floor(Math.random() * 1000));
        studioState.fields.push(copy);
        renderCanvas();
        selectField(studioState.fields.length - 1);
        renderInteractivePreview();
      };

      cont.appendChild(item);
    });
  }

  function renderOptionSetsList() {
    var cont = document.getElementById('optionSetsListContainer');
    if (!cont) return;
    cont.innerHTML = '';

    studioState.optionSets.forEach(function (set) {
      var item = document.createElement('div');
      item.style.cssText = 'background:#FFFFFF;border:1px solid #CBD5E1;border-radius:6px;padding:8px 10px;';
      item.innerHTML = '<div style="font-size:12px;font-weight:700;color:var(--studio-navy);">' + escapeHtml(set.name) + '</div>' +
        '<div style="font-size:11px;color:#64748B;margin-top:2px;">' + (set.options_parsed ? set.options_parsed.length : 0) + ' choice options</div>';
      cont.appendChild(item);
    });
  }

  // ---------------------------------------------------------------------------
  // 10. Version History Snapshots Modal
  // ---------------------------------------------------------------------------
  function openVersionHistoryModal() {
    var modal = document.getElementById('versionHistoryModal');
    var list = document.getElementById('versionHistoryList');
    if (!modal || !list) return;

    list.innerHTML = '';
    if (studioState.history.length === 0) {
      list.innerHTML = '<div style="text-align:center;padding:24px;color:#64748B;">No version snapshots available yet. Publishing the form will record an immutable snapshot here.</div>';
    } else {
      studioState.history.forEach(function (h) {
        var card = document.createElement('div');
        card.className = 'version-snapshot-card';
        card.innerHTML = '<div>' +
          '<div style="display:flex;align-items:center;gap:8px;">' +
          '<span class="badge badge-primary" style="font-weight:700;">v' + h.version + '</span>' +
          '<span style="font-size:12.5px;font-weight:600;color:#1E293B;">' + escapeHtml(h.published_by_name || 'Admin') + '</span>' +
          '</div>' +
          '<div style="font-size:11.5px;color:#64748B;margin-top:4px;">Published on ' + escapeHtml(h.created_at) + '</div>' +
          '</div>' +
          '<button type="button" class="btn btn-sm btn-outline" style="font-size:11.5px;">Revert to v' + h.version + '</button>';

        card.querySelector('button').onclick = function () {
          if (confirm('Revert the form configuration to Version ' + h.version + '? Current draft changes will be replaced.')) {
            restoreHistoricalVersion(h.id);
            modal.close();
          }
        };

        list.appendChild(card);
      });
    }

    modal.showModal();
  }

  function restoreHistoricalVersion(historyId) {
    updateSaveIndicator('saving', 'Restoring...');
    var payload = new FormData();
    payload.append('action', 'restore_form_version');
    payload.append('id', studioState.metricId);
    payload.append('history_id', historyId);
    payload.append('csrf', getCsrfToken());
    payload.append('csrf_token', getCsrfToken());

    fetch('form-studio.php?ajax=1', {
      method: 'POST',
      headers: {
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-Token': getCsrfToken()
      },
      body: payload
    })
    .then(function (res) { return res.json(); })
    .then(function (data) {
      if (data && data.success) {
        loadFormStudioConfig(studioState.metricId);
        alert('Form successfully restored to historical version!');
      } else {
        alert('Failed to restore version: ' + (data && data.message ? data.message : 'Unknown error'));
      }
    });
  }

  // ---------------------------------------------------------------------------
  // 11. Viewports & Tab Switchers
  // ---------------------------------------------------------------------------
  function setCanvasViewport(mode) {
    studioState.viewport = mode;
    var wrap = document.getElementById('canvasViewportWrapper');
    if (!wrap) return;

    wrap.className = 'canvas-viewport-wrapper';
    if (mode === 'tablet') wrap.classList.add('viewport-tablet');
    if (mode === 'mobile') wrap.classList.add('viewport-mobile');

    document.querySelectorAll('.viewport-switch-group .viewport-btn').forEach(function (b) {
      b.classList.remove('active');
    });
    if (mode === 'desktop') document.getElementById('btnVpDesktop').classList.add('active');
    if (mode === 'tablet') document.getElementById('btnVpTablet').classList.add('active');
    if (mode === 'mobile') document.getElementById('btnVpMobile').classList.add('active');
  }

  function switchLeftTab(tab) {
    document.querySelectorAll('.panel-tab-btn').forEach(function (b) { b.classList.remove('active'); });
    document.getElementById('panePalette').style.display = 'none';
    document.getElementById('paneReusable').style.display = 'none';
    document.getElementById('paneOptionSets').style.display = 'none';

    if (tab === 'palette') {
      document.getElementById('tabBtnPalette').classList.add('active');
      document.getElementById('panePalette').style.display = 'flex';
    } else if (tab === 'reusable') {
      document.getElementById('tabBtnReusable').classList.add('active');
      document.getElementById('paneReusable').style.display = 'flex';
    } else if (tab === 'options') {
      document.getElementById('tabBtnOptionSets').classList.add('active');
      document.getElementById('paneOptionSets').style.display = 'flex';
    }
  }

  function switchRightTab(tab) {
    document.querySelectorAll('.inspector-tab-btn').forEach(function (b) { b.classList.remove('active'); });
    document.getElementById('paneInspector').style.display = 'none';
    document.getElementById('panePreview').style.display = 'none';

    if (tab === 'inspector') {
      document.getElementById('tabBtnInspector').classList.add('active');
      document.getElementById('paneInspector').style.display = 'flex';
    } else if (tab === 'preview') {
      document.getElementById('tabBtnPreview').classList.add('active');
      document.getElementById('panePreview').style.display = 'flex';
      renderInteractivePreview();
    }
  }

  function updatePreviewRole(role) {
    studioState.previewRole = role;
    renderInteractivePreview();
  }

  // ---------------------------------------------------------------------------
  // 12. Helpers & Utilities
  // ---------------------------------------------------------------------------
  function formatDefaultLabel(type) {
    var map = {
      'text': 'Short Text Title',
      'textarea': 'Detailed Description',
      'integer': 'Count / Quantity',
      'decimal': 'Amount / Score',
      'email': 'Email Address',
      'tel': 'Phone Number',
      'url': 'Web Link (URL)',
      'date': 'Event Date',
      'time': 'Event Time',
      'datetime': 'Date & Time',
      'select': 'Dropdown Selection',
      'radio': 'Choice Selection',
      'checkbox': 'Confirmation Toggle',
      'yes_no': 'Yes / No Status',
      'multiselect': 'Multiple Items Selection',
      'academic_dept': 'Department',
      'academic_year': 'Academic Year',
      'academic_semester': 'Semester',
      'academic_session': 'Examination Session',
      'academic_batch': 'Batch / Class',
      'student_name': 'Student Name',
      'register_number': 'Register Number',
      'activity_title': 'Activity / Initiative Title',
      'company_name': 'Company / Employer Name',
      'company_type': 'Company Type / Sector',
      'placement_status': 'Placement / Progression Status',
      'package_ctc': 'Package / CTC (LPA)',
      'placement_date': 'Placement / Offer Date',
      'participant_count': 'Number of Participants',
      'budget': 'Budget / Expenditure (in Lakhs)',
      'remarks': 'Remarks & Outcomes',
      'file_single': 'Supporting Document',
      'file_multi': 'Supporting Attachments',
      'file_pdf': 'PDF Document Proof',
      'file_image': 'Event Photograph',
      'file_evidence': 'Supporting Attachment',
      'table_grid': 'Tabular Data Entry Grid',
      'acknowledgement': 'IQAC Quality Declaration',
      'readonly': 'Calculated Result',
      'specialized': 'Institutional Component',
      'section_header': 'Section Heading',
      'instructions': 'Description / Guidelines',
      'divider': 'Divider',
      'section_container': 'Section Container',
      'page_break': 'Page Break',
      'hidden_field': 'Hidden Parameter'
    };
    return map[type] || 'New Field';
  }

  function normalizeFieldType(type) {
    if (type.indexOf('file') === 0) return 'file';
    if (type === 'academic_dept' || type === 'academic_session' || type === 'academic_semester' || type === 'company_type' || type === 'placement_status') return 'select';
    if (type === 'placement_date') return 'date';
    if (type === 'package_ctc') return 'decimal';
    if (type.indexOf('academic_') === 0) return 'text';
    if (type === 'yes_no') return 'radio';
    if (type === 'hidden_field') return 'text';
    if (type === 'section_container') return 'section_header';
    return type;
  }

  function getDefaultOptions(type) {
    switch (type) {
      case 'academic_dept':
        return ['Computer Science and Engineering', 'Electronics and Communication Engineering', 'Electrical and Electronics Engineering', 'Mechanical Engineering', 'Civil Engineering', 'Information Technology', 'Artificial Intelligence and Data Science', 'Management Studies (MBA)'];
      case 'academic_year':
        return ['2025-26', '2024-25', '2023-24', '2022-23'];
      case 'academic_semester':
        return ['Semester 1', 'Semester 2', 'Semester 3', 'Semester 4', 'Semester 5', 'Semester 6', 'Semester 7', 'Semester 8'];
      case 'academic_session':
        return ['Apr/May Session', 'Nov/Dec Session'];
      case 'company_type':
        return ['Tier-1 MNC', 'Core Engineering & Manufacturing', 'IT & Software Services', 'Product / Startup', 'Government / PSU'];
      case 'placement_status':
        return ['On-Campus Placement', 'Off-Campus Placement', 'Higher Studies', 'Entrepreneurship / Startup'];
      case 'yes_no':
        return ['Yes', 'No'];
      default:
        return ['Option 1', 'Option 2'];
    }
  }

  function getDefaultPlaceholder(type) {
    switch (type) {
      case 'academic_batch': return 'e.g. 2022-2026 Batch';
      case 'student_name': return 'Full student name as per university records';
      case 'register_number': return 'e.g. 910022104001';
      case 'company_name': return 'e.g. Infosys, TCS, Cognizant';
      case 'package_ctc': return 'Salary in Lakhs per annum (e.g. 6.50)';
      case 'remarks': return 'Specify role title, drive details, and remarks...';
      case 'textarea': return 'Enter detailed notes / description...';
      case 'activity_title': return 'e.g. Annual Technical Symposium';
      case 'budget': return 'Amount in Lakhs (e.g. 1.50)';
      default: return '';
    }
  }

  function isFieldRequiredByDefault(type) {
    if (type === 'remarks' || type === 'instructions' || type === 'divider' || type === 'page_break' || type === 'hidden_field' || type === 'url') {
      return false;
    }
    return true;
  }

  function generateUniqueFieldKey(type) {
    var base = type.replace(/[^a-z0-9_]/gi, '_').toLowerCase();
    var count = 1;
    var existingKeys = studioState.fields.map(function (f) { return f.key; });
    var key = base + '_' + count;
    while (existingKeys.indexOf(key) !== -1) {
      count++;
      key = base + '_' + count;
    }
    return key;
  }

  function escapeHtml(str) {
    if (!str) return '';
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function getCsrfToken() {
    var tokenInput = document.getElementById('globalCsrfToken') ||
                     document.getElementById('globalCsrfTokenAlt') ||
                     document.querySelector('input[name="csrf"]') ||
                     document.querySelector('input[name="csrf_token"]');
    return tokenInput ? tokenInput.value : '';
  }

  // Window BeforeUnload Guard
  window.addEventListener('beforeunload', function (e) {
    if (studioState.isDirty) {
      e.preventDefault();
      e.returnValue = 'You have unsaved changes in Form Studio. Discard and leave?';
      return e.returnValue;
    }
  });

  // Global Keyboard Shortcuts
  window.addEventListener('keydown', function (e) {
    if ((e.ctrlKey || e.metaKey) && e.key === 's') {
      e.preventDefault();
      saveDraft();
    } else if ((e.ctrlKey || e.metaKey) && e.key === 'z') {
      e.preventDefault();
      undoStudioAction();
    } else if ((e.ctrlKey || e.metaKey) && e.key === 'y') {
      e.preventDefault();
      redoStudioAction();
    }
  });

  function openNewOptionSetModal() {
    var modal = document.getElementById('newOptionSetModal');
    if (modal && typeof modal.showModal === 'function') {
      modal.showModal();
    }
  }

  function submitNewOptionSet() {
    var nameInp = document.getElementById('newOptSetName');
    var keyInp = document.getElementById('newOptSetKey');
    var linesInp = document.getElementById('newOptSetLines');

    var name = nameInp ? nameInp.value.trim() : '';
    var key = keyInp ? keyInp.value.trim().toLowerCase().replace(/[^a-z0-9_]+/g, '_') : '';
    var rawLines = linesInp ? linesInp.value.split('\n') : [];

    if (!name || !key) {
      alert('Please provide both an option set name and identifier key.');
      return;
    }

    var options = [];
    rawLines.forEach(function (l) {
      var trimmed = l.trim();
      if (trimmed) {
        options.push({ value: trimmed, label: trimmed });
      }
    });

    if (options.length === 0) {
      alert('Please add at least one option line.');
      return;
    }

    var fd = new FormData();
    fd.append('action', 'save_option_set');
    fd.append('csrf', getCsrfToken());
    fd.append('csrf_token', getCsrfToken());
    fd.append('set_key', key);
    fd.append('name', name);
    fd.append('description', 'Custom option set defined via Form Studio.');
    fd.append('options_json', JSON.stringify(options));
    fd.append('ajax', '1');

    fetch('form-studio.php', {
      method: 'POST',
      body: fd,
      headers: {
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-Token': getCsrfToken()
      }
    })
    .then(function (res) { return res.json(); })
    .then(function (data) {
      if (data && data.success) {
        studioState.optionSets = data.option_sets || [];
        renderOptionSetsList();
        var modal = document.getElementById('newOptionSetModal');
        if (modal) modal.close();
        if (nameInp) nameInp.value = '';
        if (keyInp) keyInp.value = '';
        if (linesInp) linesInp.value = '';
        alert('Option set saved successfully!');
      } else {
        alert(data && data.message ? data.message : 'Failed to save option set.');
      }
    })
    .catch(function (err) {
      console.error(err);
      alert('Error saving option set.');
    });
  }

  function handleCreateNewForm(e) {
    if (e && typeof e.preventDefault === 'function') {
      e.preventDefault();
    }
    var formEl = e && e.target ? e.target : document.querySelector('#newFormModal form');
    if (!formEl) return false;

    var formData = new FormData(formEl);
    formData.append('ajax', '1');
    if (!formData.has('csrf')) formData.append('csrf', getCsrfToken());
    if (!formData.has('csrf_token')) formData.append('csrf_token', getCsrfToken());

    updateSaveIndicator('saving', 'Creating form...');
    fetch('form-studio.php', {
      method: 'POST',
      body: formData,
      headers: {
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-Token': getCsrfToken()
      }
    })
    .then(function (res) { return res.json(); })
    .then(function (data) {
      if (data && data.success && data.metric) {
        updateSaveIndicator('saved', 'Form created');
        var modal = document.getElementById('newFormModal');
        if (modal) modal.close();

        if (Array.isArray(studioState.allMetrics)) {
          var exists = false;
          for (var i = 0; i < studioState.allMetrics.length; i++) {
            if (parseInt(studioState.allMetrics[i].id, 10) === parseInt(data.metric.id, 10)) {
              exists = true;
              break;
            }
          }
          if (!exists) {
            studioState.allMetrics.push({
              id: parseInt(data.metric.id, 10),
              metric_key: data.metric.metric_key,
              name: data.metric.name,
              category: data.metric.category || 'GENERAL',
              form_version: parseInt(data.metric.form_version || 1, 10)
            });
          }
        }

        // Load the new form into studio
        loadFormStudioConfig(data.metric.id);
        if (typeof renderFormMenuList === 'function') {
          renderFormMenuList();
        }

        if (window.history && window.history.replaceState) {
          window.history.replaceState({}, '', 'form-studio.php?id=' + encodeURIComponent(data.metric.id));
        }
      } else {
        alert(data && data.message ? data.message : 'Could not create form.');
        updateSaveIndicator('failed', 'Creation failed');
      }
    })
    .catch(function (err) {
      console.error(err);
      alert('Network or server error while creating form.');
      updateSaveIndicator('failed', 'Error');
    });

    return false;
  }

  // Expose global methods
  window.loadFormStudioConfig = loadFormStudioConfig;
  window.saveDraft = saveDraft;
  window.publishConfiguration = publishConfiguration;
  window.selectField = selectField;
  window.selectFormSettings = selectFormSettings;
  window.selectProofSettings = selectProofSettings;
  window.addFieldFromPalette = addFieldFromPalette;
  window.onPaletteDragStart = onPaletteDragStart;
  window.onCanvasDragOver = onCanvasDragOver;
  window.onCanvasDrop = onCanvasDrop;
  window.toggleFieldWidth = toggleFieldWidth;
  window.setFieldWidth = setFieldWidth;
  window.setFieldColumnSpan = setFieldColumnSpan;
  window.setFieldAlignment = setFieldAlignment;
  window.setFieldVerticalAlignment = setFieldVerticalAlignment;
  window.setFieldSpacing = setFieldSpacing;
  window.setFieldCustomHeight = setFieldCustomHeight;
  window.setFieldOffset = setFieldOffset;
  window.moveFieldToSection = moveFieldToSection;
  window.applyPlacementTemplate = applyPlacementTemplate;
  window.applyFacultyActivityTemplate = applyFacultyActivityTemplate;
  window.applyPublicationTemplate = applyPublicationTemplate;
  window.moveField = moveField;
  window.moveFieldUp = moveFieldUp;
  window.moveFieldDown = moveFieldDown;
  window.duplicateField = duplicateField;
  window.deleteField = deleteField;
  window.saveFieldToReusable = saveFieldToReusable;
  window.updateFieldProperty = updateFieldProperty;
  window.updateConditionProp = updateConditionProp;
  window.toggleConditionalLogic = toggleConditionalLogic;
  window.setOptionMode = setOptionMode;
  window.addCustomOption = addCustomOption;
  window.addOtherOption = addOtherOption;
  window.updateCustomOption = updateCustomOption;
  window.removeCustomOption = removeCustomOption;
  window.updateFormSetting = updateFormSetting;
  window.updateAttachmentProp = updateAttachmentProp;
  window.applyProofPreset = applyProofPreset;
  window.updateMaxMb = updateMaxMb;
  window.updateMaxFiles = updateMaxFiles;
  window.setCanvasViewport = setCanvasViewport;
  window.switchLeftTab = switchLeftTab;
  window.switchRightTab = switchRightTab;
  window.updatePreviewRole = updatePreviewRole;
  window.testPreviewSubmission = testPreviewSubmission;
  window.undoStudioAction = undoStudioAction;
  window.redoStudioAction = redoStudioAction;
  window.openVersionHistoryModal = openVersionHistoryModal;
  window.restoreHistoricalVersion = restoreHistoricalVersion;
  window.openNewOptionSetModal = openNewOptionSetModal;
  window.submitNewOptionSet = submitNewOptionSet;
  window.handleCreateNewForm = handleCreateNewForm;

  // ---------------------------------------------------------------------------
  // Custom Form Switcher Dropdown Menu Handlers
  // ---------------------------------------------------------------------------
  function toggleFormMenu(evt) {
    if (evt) evt.stopPropagation();
    var dropdown = document.getElementById('formMenuDropdown');
    var trigger = document.getElementById('formMenuTrigger');
    if (!dropdown) return;
    
    var isOpen = dropdown.style.display !== 'none';
    if (isOpen) {
      closeFormMenu();
    } else {
      dropdown.style.display = 'flex';
      if (trigger) trigger.setAttribute('aria-expanded', 'true');
      var searchInput = document.getElementById('formMenuSearchInput');
      if (searchInput) {
        searchInput.value = '';
        filterFormMenu('');
        setTimeout(function () { searchInput.focus(); }, 50);
      }
    }
  }

  function closeFormMenu() {
    var dropdown = document.getElementById('formMenuDropdown');
    var trigger = document.getElementById('formMenuTrigger');
    if (dropdown) dropdown.style.display = 'none';
    if (trigger) trigger.setAttribute('aria-expanded', 'false');
  }

  function filterFormMenu(query) {
    var q = (query || '').toLowerCase().trim();
    var items = document.querySelectorAll('.studio-menu-item');
    var headers = document.querySelectorAll('.studio-menu-group-header');

    items.forEach(function (el) {
      var name = (el.dataset.name || '').toLowerCase();
      var key = (el.dataset.key || '').toLowerCase();
      var id = (el.dataset.id || '');
      var match = !q || name.indexOf(q) !== -1 || key.indexOf(q) !== -1 || ('#' + id).indexOf(q) !== -1;
      el.style.display = match ? 'flex' : 'none';
    });

    // Hide empty category headers
    headers.forEach(function (hdr) {
      var next = hdr.nextElementSibling;
      var hasVisible = false;
      while (next && next.classList.contains('studio-menu-item')) {
        if (next.style.display !== 'none') {
          hasVisible = true;
          break;
        }
        next = next.nextElementSibling;
      }
      hdr.style.display = hasVisible ? 'block' : 'none';
    });
  }

  function selectFormFromMenu(id) {
    closeFormMenu();
    var formSel = document.getElementById('formSelector');
    if (formSel) {
      formSel.value = id;
    }
    window.onFormSelectorChange(id);
  }

  function openNewFormModalFromMenu() {
    closeFormMenu();
    var modal = document.getElementById('newFormModal');
    if (modal && typeof modal.showModal === 'function') {
      var titleInput = document.getElementById('newFormTitleInput');
      var keyInput = document.getElementById('newFormKeyInput');
      if (titleInput) titleInput.value = '';
      if (keyInput) {
        keyInput.value = '';
        delete keyInput.dataset.manual;
      }
      modal.showModal();
    }
  }

  function renderFormMenuList() {
    var menuList = document.getElementById('formMenuList');
    var formSel = document.getElementById('formSelector');
    if (!menuList && !formSel) return;

    var metrics = Array.isArray(studioState.allMetrics) ? studioState.allMetrics : [];
    var currentId = studioState.metricId ? parseInt(studioState.metricId, 10) : null;

    if (menuList) {
      var byCat = {};
      metrics.forEach(function (m) {
        var cat = (m.category || 'GENERAL').toUpperCase();
        if (!byCat[cat]) byCat[cat] = [];
        byCat[cat].push(m);
      });

      var html = '';
      Object.keys(byCat).sort().forEach(function (cat) {
        var catItems = byCat[cat];
        html += '<div class="studio-menu-group-header">' + escapeHtml(cat) + '</div>';
        catItems.forEach(function (m) {
          var mId = parseInt(m.id, 10);
          var isActive = (currentId === mId);
          var mName = m.name || 'Untitled Form';
          var mKey = m.metric_key || '';
          var mVer = parseInt(m.form_version || 1, 10);

          html += '<div class="studio-menu-item ' + (isActive ? 'active' : '') + '" ' +
                  'data-id="' + mId + '" ' +
                  'data-name="' + escapeHtml(mName.toLowerCase()) + '" ' +
                  'data-key="' + escapeHtml(mKey.toLowerCase()) + '" ' +
                  'onclick="selectFormFromMenu(' + mId + ')" role="menuitem">' +
                  '<div class="studio-menu-item-left">' +
                    '<span class="studio-menu-item-id">#' + mId + '</span>' +
                    '<div class="studio-menu-item-info">' +
                      '<div class="studio-menu-item-title">' + escapeHtml(mName) + '</div>' +
                      '<div class="studio-menu-item-sub"><span class="studio-menu-item-key">' + escapeHtml(mKey) + '</span></div>' +
                    '</div>' +
                  '</div>' +
                  '<div class="studio-menu-item-right">' +
                    '<span class="studio-menu-item-ver">v' + mVer + '</span>' +
                    (isActive ? '<span class="studio-menu-item-check"><svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg></span>' : '') +
                  '</div>' +
                '</div>';
        });
      });
      menuList.innerHTML = html;

      var searchInput = document.getElementById('formMenuSearchInput');
      if (searchInput && searchInput.value) {
        filterFormMenu(searchInput.value);
      }
    }

    if (formSel) {
      var selHtml = '<optgroup label="Select Form to Customize">';
      metrics.forEach(function (m) {
        var mId = parseInt(m.id, 10);
        var isSelected = (currentId === mId);
        selHtml += '<option value="' + mId + '" ' + (isSelected ? 'selected' : '') + '>#' + mId + ' · ' + escapeHtml(m.name || 'Custom Form') + ' (v' + (m.form_version || 1) + ')</option>';
      });
      selHtml += '</optgroup><option value="NEW">+ Create New Dynamic Form...</option>';
      formSel.innerHTML = selHtml;
      if (currentId) formSel.value = currentId;
    }
  }

  // Close menu on click outside or Escape
  document.addEventListener('click', function (e) {
    var wrap = document.getElementById('formMenuWrap');
    if (wrap && !wrap.contains(e.target)) {
      closeFormMenu();
    }
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
      closeFormMenu();
    }
  });

  // Zero out parent container padding so Form Studio sits flush with topbar without overlap
  document.addEventListener('DOMContentLoaded', function () {
    var studioApp = document.getElementById('formStudioApp');
    if (studioApp) {
      var parentContent = studioApp.closest('.content');
      if (parentContent) {
        parentContent.style.padding = '0';
        parentContent.style.margin = '0';
        parentContent.style.maxWidth = '100%';
      }
      var parentContainer = studioApp.closest('.container');
      if (parentContainer) {
        parentContainer.style.maxWidth = '100%';
        parentContainer.style.padding = '0';
        parentContainer.style.margin = '0';
      }
    }
  });

  window.toggleFormMenu = toggleFormMenu;
  window.closeFormMenu = closeFormMenu;
  window.filterFormMenu = filterFormMenu;
  window.selectFormFromMenu = selectFormFromMenu;
  window.openNewFormModalFromMenu = openNewFormModalFromMenu;
  window.renderFormMenuList = renderFormMenuList;

  window.onFormSelectorChange = function (val) {
    if (val === 'NEW') {
      document.getElementById('newFormModal').showModal();
    } else if (val) {
      if (studioState.isDirty) {
        if (!confirm('You have unsaved changes in this form. Switch forms and discard changes?')) {
          document.getElementById('formSelector').value = studioState.metricId;
          return;
        }
      }
      loadFormStudioConfig(parseInt(val, 10));
    }
  };

  window.suggestMetricKey = function (name) {
    var keyInp = document.getElementById('newFormKeyInput');
    if (keyInp && !keyInp.dataset.manual) {
      keyInp.value = name.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '');
    }
  };

  window.addEventListener('dragend', function () {
    if (canvasDragState && canvasDragState.isDragging) {
      cleanupDragState();
    }
  });

})(window, document);

