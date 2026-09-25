<?php
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/models/Dashboard.php';
require_once __DIR__ . '/models/Target.php';

$user = require_login();

if ($user['role'] === 'Admin') {
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
        'Principal'   => 'Principal Dashboard',
        'Director'    => 'Principal Dashboard',
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
