<?php
// Entry point: send signed-in users to their dashboard, everyone else to login.
require_once __DIR__ . '/inc/auth.php';

auth_boot();

redirect(is_logged_in() ? '/dashboard.php' : '/login.php');
