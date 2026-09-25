<?php
require_once __DIR__ . '/inc/auth.php';

$qs = !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '';
redirect('/faculty-achievements.php' . $qs);
