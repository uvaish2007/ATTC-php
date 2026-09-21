<?php
/**
 * Student achievements are consolidated directly on faculty-achievements.php below the faculty matrix.
 * This file redirects any direct access to faculty-achievements.php.
 */

require_once __DIR__ . '/inc/auth.php';

$qs = !empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '';
redirect('/faculty-achievements.php' . $qs);
