<?php
require_once __DIR__ . '/../php-app/inc/auth.php';
$reason = null;
$user = attempt_login('faculty@atts.edu', 'faculty123', 'Faculty', $reason);
var_dump($user, $reason);
