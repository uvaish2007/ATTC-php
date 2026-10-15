<?php
$css = file_get_contents(__DIR__ . '/../php-app/assets/css/app.css');
$html = file_get_contents(__DIR__ . '/../php-app/upload.php');

// Test 1: scroll-behavior: smooth in container
$pos = strpos($css, '.atts-metric-nav-container {');
$posEnd = strpos($css, '}', $pos);
$containerBlock = substr($css, $pos, $posEnd - $pos);
$hasSmoothInNav = (strpos($containerBlock, 'scroll-behavior') !== false);
echo "1. scroll-behavior in .atts-metric-nav-container: " . ($hasSmoothInNav ? "FAIL (found)" : "PASS (none)") . PHP_EOL;

// Test 2: inline immediate positioning script present
$hasInlineScript = (strpos($html, 'Restore saved scroll position or center active metric tab immediately') !== false);
echo "2. Inline immediate positioning script: " . ($hasInlineScript ? "PASS" : "FAIL") . PHP_EOL;

// Test 3: sessionStorage syncing in tab click
$hasSessionStorage = (strpos($html, "sessionStorage.setItem('atts_metric_scroll', targetScroll)") !== false);
echo "3. sessionStorage scroll recording: " . ($hasSessionStorage ? "PASS" : "FAIL") . PHP_EOL;

// Test 4: No smooth scroll in ensureActiveCategoryVisible
$funcStart = strpos($html, 'function ensureActiveCategoryVisible(activeElement)');
$funcEnd = strpos($html, 'var tabs = track', $funcStart);
$funcBlock = substr($html, $funcStart, $funcEnd - $funcStart);
$hasSmoothInFunc = (strpos($funcBlock, 'behavior: \'smooth\'') !== false || strpos($funcBlock, 'behavior:"smooth"') !== false);
echo "4. No smooth scroll in ensureActiveCategoryVisible: " . ($hasSmoothInFunc ? "FAIL (smooth found)" : "PASS (instant)") . PHP_EOL;

// Test 5: Arrow buttons STILL have smooth scrolling
$hasSmoothInArrows = (strpos($html, "container.scrollBy({ left: -320, behavior: 'smooth' })") !== false);
echo "5. Arrow buttons retain smooth nudging: " . ($hasSmoothInArrows ? "PASS" : "FAIL") . PHP_EOL;
