<?php
foreach (['php-app/present-executive-meeting.php', 'php-app/present-faculty-report.php', 'php-app/present-student-report.php'] as $f) {
    $fullPath = __DIR__ . '/../' . $f;
    $content = file_get_contents($fullPath);
    if (preg_match('/<style>(.*?)<\/style>/s', $content, $m)) {
        $css = $m[1];
        $opens = substr_count($css, '{');
        $closes = substr_count($css, '}');
        echo "$f: opens=$opens, closes=$closes, diff=" . ($opens - $closes) . "\n";
    } else {
        echo "$f: NO STYLE TAG FOUND\n";
    }
}
