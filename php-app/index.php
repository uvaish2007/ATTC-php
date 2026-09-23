<?php
require_once __DIR__ . '/inc/auth.php';

auth_boot();

redirect(is_logged_in() ? '/dashboard.php' : '/login.php');
