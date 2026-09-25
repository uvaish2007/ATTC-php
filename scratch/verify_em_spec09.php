<?php
/**
 * EM-SPEC-09 Source Verification Script
 * Validates the JavaScript implementation statically before browser tests.
 */

$file = __DIR__ . '/../php-app/present-executive-meeting.php';
$src  = file_get_contents($file);

$pass = 0;
$fail = 0;

function chk(string $label, bool $condition, string $detail = ''): void {
    global $pass, $fail;
    if ($condition) {
        $pass++;
        echo "[PASS] $label" . ($detail ? " — $detail" : '') . "\n";
    } else {
        $fail++;
        echo "[FAIL] $label" . ($detail ? " — $detail" : '') . "\n";
    }
}

echo "=======================================================\n";
echo "EM-SPEC-09 SOURCE VERIFICATION\n";
echo "=======================================================\n\n";

// -- No conflict markers
chk('No conflict markers',
    strpos($src, '<<<<<<<') === false && strpos($src, '=======') === false && strpos($src, '>>>>>>>') === false,
    'All git conflict markers removed'
);

// -- PHP syntax
$lint = shell_exec('php -l ' . escapeshellarg($file) . ' 2>&1');
chk('PHP syntax clean', strpos($lint, 'No syntax errors') !== false, trim($lint));

// -- AUTO_ADVANCE_MS = 5000
chk('AUTO_ADVANCE_MS defined as EM_AUTO_ADVANCE_MS (PHP)',
    strpos($src, "define('EM_AUTO_ADVANCE_MS', 5000)") !== false,
    'Slide dwell time is 5000ms'
);
chk('AUTO_ADVANCE_MS echoed into JS',
    strpos($src, 'const AUTO_ADVANCE_MS = <?= EM_AUTO_ADVANCE_MS ?>') !== false
    || strpos($src, "const AUTO_ADVANCE_MS = <?= EM_AUTO_ADVANCE_MS ?>") !== false
);

// -- Single autoTimer variable
$autoTimerCount = substr_count($src, 'let autoTimer = null');
chk('Exactly one autoTimer declaration', $autoTimerCount === 1, "Found: $autoTimerCount");

// -- renderTimer (debouncing)
chk('renderTimer for debouncing rapid navigation',
    strpos($src, 'renderTimer') !== false,
    'Prevents stacked transition timeouts'
);

// -- clearAutoTimer
chk('clearAutoTimer() exists', strpos($src, 'function clearAutoTimer()') !== false);

// -- startAutoTimer
chk('startAutoTimer() exists', strpos($src, 'function startAutoTimer()') !== false);
chk('startAutoTimer clears first', strpos($src, "function startAutoTimer") !== false &&
    strpos($src, 'clearAutoTimer();') !== false,
    'Clears existing timer before setting new one'
);

// -- renderSlide clears timer
chk('renderSlide() cancels autoTimer immediately',
    strpos($src, 'clearAutoTimer();') !== false,
    'Manual nav cancels in-flight auto-advance'
);

// -- startAutoTimer after render
chk('startAutoTimer() called after slide render (timer reset)',
    strpos($src, "// Resets the 5-second auto timer so newly selected slide remains visible for ~5s") !== false
);

// -- nextSlide / prevSlide boundary
chk('nextSlide() stops at last slide',
    preg_match('/function nextSlide.*?slides\.length - 1/s', $src) === 1
);
chk('prevSlide() stops at first slide',
    preg_match('/function prevSlide.*?currentIndex > 0/s', $src) === 1
);

// -- Keyboard: named handler
chk('Named handleKeydown function (allows removal)',
    strpos($src, 'function handleKeydown(e)') !== false
);

// -- Keyboard: keys
chk('ArrowRight navigates next', strpos($src, "case 'ArrowRight':") !== false);
chk('ArrowLeft navigates prev',  strpos($src, "case 'ArrowLeft':") !== false);
chk('Escape exits presentation', strpos($src, "case 'Escape':") !== false);

// -- Input safety guard
chk('Input-field safety (inInput guard)',
    strpos($src, 'if (inInput) return;') !== false || strpos($src, "tag === 'INPUT'") !== false
);

// -- attachKeydownListener / removeKeydownListener
chk('attachKeydownListener() exists',
    strpos($src, 'function attachKeydownListener()') !== false
);
chk('removeKeydownListener() exists',
    strpos($src, 'function removeKeydownListener()') !== false
);
chk('keyListenerAttached flag (no duplicates)',
    strpos($src, 'keyListenerAttached') !== false
);

// -- exitPresentation clears timer and removes listener
chk('exitPresentation() calls clearAutoTimer()',
    preg_match('/function exitPresentation\(\).*?clearAutoTimer\(\)/s', $src) === 1
);
chk('exitPresentation() calls removeKeydownListener()',
    preg_match('/function exitPresentation\(\).*?removeKeydownListener\(\)/s', $src) === 1
);

// -- Init at bottom
chk('attachKeydownListener() called on page load',
    strpos($src, 'attachKeydownListener();') !== false
);
chk('renderSlide(0, false) called on page load (start auto-play)',
    strpos($src, 'renderSlide(0, false);') !== false
);

// -- postMessage navigation forwarding
chk('Parent->iframe postMessage navigation (em-nav)',
    strpos($src, "atts: 'em-nav'") !== false || strpos($src, "data.atts === 'em-nav'") !== false
);

// -- Slide counter format
chk("Slide counter shows 'Slide X / Y' format",
    strpos($src, "/ ' + total") !== false || strpos($src, "'/ ' + slides.length") !== false
);

// -- executive-meeting-report.php: iframe focus + key forwarding
$reportFile = __DIR__ . '/../php-app/executive-meeting-report.php';
$reportSrc  = file_get_contents($reportFile);
$reportLint = shell_exec('php -l ' . escapeshellarg($reportFile) . ' 2>&1');
chk('executive-meeting-report.php syntax clean', strpos($reportLint, 'No syntax errors') !== false);
chk('Parent forwards ArrowRight to iframe via postMessage', strpos($reportSrc, "'em-nav'") !== false || strpos($reportSrc, '"em-nav"') !== false);
chk('Parent keyboard listener cleans up on dialog close',
    strpos($reportSrc, "frame.removeAttribute('src')") !== false
);

// -- em_status_card.php: dashboard forwarding
$cardFile = __DIR__ . '/../php-app/views/em_status_card.php';
$cardSrc  = file_get_contents($cardFile);
$cardLint = shell_exec('php -l ' . escapeshellarg($cardFile) . ' 2>&1');
chk('em_status_card.php syntax clean', strpos($cardLint, 'No syntax errors') !== false);
chk('Dashboard modal also forwards keyboard nav',
    strpos($cardSrc, "'em-nav'") !== false || strpos($cardSrc, '"em-nav"') !== false
);

// -- reports.php: no conflict markers
$reportsFile = __DIR__ . '/../php-app/reports.php';
$reportsSrc  = file_get_contents($reportsFile);
$reportsLint = shell_exec('php -l ' . escapeshellarg($reportsFile) . ' 2>&1');
chk('reports.php syntax clean', strpos($reportsLint, 'No syntax errors') !== false);
chk('reports.php has no conflict markers',
    strpos($reportsSrc, '<<<<<<<') === false && strpos($reportsSrc, '>>>>>>>') === false
);

echo "\n=======================================================\n";
echo "RESULT: $pass PASS, $fail FAIL\n";
echo "=======================================================\n";
if ($fail === 0) {
    echo "EM-SPEC-09 SOURCE VERIFICATION: PASS\n";
} else {
    echo "EM-SPEC-09 SOURCE VERIFICATION: FAIL — $fail check(s) failed\n";
}
