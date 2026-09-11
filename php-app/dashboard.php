<?php
/**
 * Dashboard — chooses the right view for the signed-in role.
 *
 *   Faculty  → their own submissions only        (views/dashboard_faculty.php)
 *   Everyone → master dashboard, scoped by role  (views/dashboard_master.php)
 *
 * This file only decides WHICH view to show and loads its data; the markup
 * lives in the view files.
 */

require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/models/Dashboard.php';
require_once __DIR__ . '/models/Target.php';

$user = require_login();

if ($user['role'] === 'Admin') {
    // Hasn't stepped through Academic Year Selection this login yet.
    if (!admin_year_gate_passed()) {
        redirect('/login.php');
    }

    // Admin Dashboard Year Switcher (section 10): a real POST + CSRF, like
    // every other state change in the app — not a bare GET link, since this
    // changes the SYSTEM-WIDE active year for every role/session, not just a
    // per-page filter.
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && input('action') === 'switch_academic_year') {
        csrf_check();
        [$ok, $msg] = activate_academic_year((string) input('academic_year'), (int) $user['id']);
        flash($ok ? 'success' : 'error', $msg);
        redirect('/dashboard.php');
    }
}

if ($user['role'] === 'Faculty') {

    $data      = my_dashboard_data($user);
    $pageTitle = 'Dashboard';
    $view      = __DIR__ . '/views/dashboard_faculty.php';

} else {

    $data = dashboard_data($user);

    $titles = [
        'Admin'       => 'Admin Dashboard',
        'Director'    => 'Director Dashboard',
        'Dean'        => 'Dean Dashboard',
        'HoD'         => 'HoD Dashboard',
        'Coordinator' => 'Coordinator Dashboard',
    ];

    $pageTitle = $titles[$user['role']] ?? 'Dashboard';
    $view      = __DIR__ . '/views/dashboard_master.php';
}

$breadcrumb = 'Dashboard';

require __DIR__ . '/inc/header.php';
require $view;
require __DIR__ . '/inc/footer.php';
