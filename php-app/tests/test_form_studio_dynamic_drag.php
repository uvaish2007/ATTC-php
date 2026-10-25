<?php
/**
 * Test Suite: Form Studio Dynamic Drag & Drop Reordering and Card Header UI
 */

$jsFile = __DIR__ . '/../assets/js/form-studio.js';
$cssFile = __DIR__ . '/../assets/css/form-studio.css';

$jsContent = file_get_contents($jsFile);
$cssContent = file_get_contents($cssFile);

$passed = 0;
$failed = 0;

function assertCondition($desc, $cond) {
    global $passed, $failed;
    if ($cond) {
        echo "[PASS] $desc\n";
        $passed++;
    } else {
        echo "[FAIL] $desc\n";
        $failed++;
    }
}

echo "=== Form Studio Dynamic Live Drag & Drop Reordering Test Suite ===\n\n";

echo "--- 1. JavaScript Drag State & Helper Architecture ---\n";
assertCondition("canvasDragState object is defined", strpos($jsContent, "var canvasDragState = {") !== false);
assertCondition("createDragPlaceholder helper exists", strpos($jsContent, "function createDragPlaceholder(") !== false);
assertCondition("cleanupDragState helper exists", strpos($jsContent, "function cleanupDragState()") !== false);
assertCondition("handleCardDragOver helper exists", strpos($jsContent, "function handleCardDragOver(") !== false);
assertCondition("calculateDropIndex helper exists", strpos($jsContent, "function calculateDropIndex()") !== false);
assertCondition("executeCanvasDrop helper exists", strpos($jsContent, "function executeCanvasDrop(") !== false);

echo "\n--- 2. Live Grid Dynamic Flow & Drop Index Calculation ---\n";
assertCondition("Interactive child elements (buttons, inputs, handles) prevent accidental dragstart", 
    strpos($jsContent, "e.target.closest('.field-actions-toolbar')") !== false &&
    strpos($jsContent, "e.target.closest('.field-resize-handle')") !== false &&
    strpos($jsContent, "e.target.closest('.field-height-handle')") !== false);

assertCondition("Placeholder is created with dynamic card span and height", 
    strpos($jsContent, "createDragPlaceholder(span, rect.height)") !== false);

assertCondition("Delayed is-dragging class ensures crisp drag ghost snapshot", 
    strpos($jsContent, "setTimeout(function () {") !== false &&
    strpos($jsContent, "card.classList.add('is-dragging');") !== false);

assertCondition("Vertical/horizontal directional check determines isBefore vs isAfter placement", 
    strpos($jsContent, "isBefore = (e.clientY < midY)") !== false);

assertCondition("insertBefore and targetElement.nextSibling dynamically reflow DOM items in CSS Grid", 
    strpos($jsContent, "container.insertBefore(placeholder, targetElement)") !== false &&
    strpos($jsContent, "container.insertBefore(placeholder, targetElement.nextSibling)") !== false);

assertCondition("Global window dragend listener ensures foolproof state cleanup", 
    strpos($jsContent, "window.addEventListener('dragend', function () {") !== false);

echo "\n--- 3. Boundary Clamping & Persistence in moveField ---\n";
assertCondition("moveField bounds clamped safely to 0 and fields.length - 1", 
    strpos($jsContent, "if (toIdx < 0) toIdx = 0;") !== false &&
    strpos($jsContent, "if (toIdx >= studioState.fields.length) toIdx = studioState.fields.length - 1;") !== false);

assertCondition("moveField ignores no-op reorders (fromIdx === toIdx)", 
    strpos($jsContent, "if (fromIdx === toIdx) return;") !== false);

assertCondition("moveField marks studioState dirty for save indicator", 
    strpos($jsContent, "markDirty();") !== false);

echo "\n--- 4. CSS Design & Visual Excellence ---\n";
assertCondition("canvas-drag-placeholder styled with dynamic dashed orange border and pulse animation", 
    strpos($cssContent, ".canvas-drag-placeholder {") !== false &&
    strpos($cssContent, "border: 2px dashed var(--studio-orange") !== false &&
    strpos($cssContent, "animation: placeholderPulse") !== false);

assertCondition("is-dragging state hides original element without breaking Chromium drag tree", 
    strpos($cssContent, ".canvas-field-card.is-dragging") !== false &&
    strpos($cssContent, "opacity: 0.001 !important;") !== false &&
    strpos($cssContent, "position: absolute !important;") !== false &&
    strpos($cssContent, "width: 0 !important;") !== false);

assertCondition("Action toolbar floats cleanly above card at top: -14px to prevent label truncation", 
    strpos($cssContent, "top: -14px;") !== false &&
    strpos($cssContent, "right: 6px;") !== false);

assertCondition("Action toolbars are completely hidden during drag operations to prevent visual clutter", 
    strpos($cssContent, ".canvas-form-surface.show-grid-guides .field-actions-toolbar") !== false);

echo "\n=======================================================\n";
echo "Tests Run: " . ($passed + $failed) . " | Passed: $passed | Failed: $failed\n";
echo "=======================================================\n";

if ($failed > 0) {
    exit(1);
}
