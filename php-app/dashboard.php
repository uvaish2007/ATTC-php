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

$user = require_login();

if ($user['role'] === 'Faculty') {

    $data      = my_dashboard_data($user);
    $pageTitle = 'Dashboard';
    $view      = __DIR__ . '/views/dashboard_faculty.php';

} else {

    $data = dashboard_data($user);

    $titles = [
        'Admin'       => 'Admin Dashboard',
        'Director'    => 'Director Dashboard',
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
