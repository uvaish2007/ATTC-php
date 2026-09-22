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
require_once __DIR__ . '/inc/report_layout.php';
require_once __DIR__ . '/models/Target.php';
require_once __DIR__ . '/models/Setting.php';

$user = require_role(['Admin', 'HoD', 'Director', 'Principal', 'Dean', 'Coordinator']);

$rawYear = trim((string) (input('academic_year') ?: input('year')));
$emRaw   = trim((string) input('em'));
$em      = $emRaw !== '' ? em_filter_value($emRaw) : null;
$params  = array_filter([
    'department'    => user_department_scope($user, trim((string) input('department')) ?: null),
    'academic_year' => $rawYear ?: null,
    'year'          => $rawYear ?: null,
    'em'            => ($em && $em !== 'all') ? $em : null,
    'format'        => in_array((string) input('format'), ['word', 'excel', 'pdf'], true) ? (string) input('format') : null,
]);

header('Location: ' . url('template-report.php') . ($params ? '?' . http_build_query($params) : ''));
exit;
