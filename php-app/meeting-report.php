<?php
/**
 * Executive Meeting Report.
 *
 * Every report in the system now shares ONE format — the Admin-designed
 * template — so this simply forwards to template-report.php, preserving the
 * department and the chosen format. That keeps old links working while giving a
 * single, consistent report (banner, grouped Achieved header, merged S.No,
 * green completed targets).
 */

require_once __DIR__ . '/inc/auth.php';

require_login();

$params = array_filter([
    'department' => trim((string) input('department')) ?: null,
    'format'     => in_array((string) input('format'), ['word', 'excel', 'pdf'], true) ? (string) input('format') : null,
]);

header('Location: ' . url('template-report.php') . ($params ? '?' . http_build_query($params) : ''));
exit;
