<?php
require_once __DIR__ . '/inc/auth.php';

auth_boot();

// Logout is a state change, so require a POST with a valid CSRF token.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    logout();
}

redirect('/login.php');
