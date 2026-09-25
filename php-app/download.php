<?php
require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/models/Announcement.php';

$user = require_login();
require_module('announcements');

$file = announcement_file_find((int) input('file', 0));

if (!$file) {
    http_response_code(404);
    exit('File not found.');
}

// announcement_find() applies the same visibility rules as the list page, so a
// faculty member cannot fetch a file from another department's notice.
if (!announcement_find((int) $file['announcement_id'], $user)) {
    http_response_code(403);
    exit('You do not have access to this file.');
}

$path = UPLOAD_DIR . '/announcements/' . basename($file['stored_name']);

if (!is_file($path)) {
    http_response_code(404);
    exit('The file is missing from the server.');
}

// Quotes are stripped so they cannot break out of the filename header.
$downloadName = str_replace(['"', "\r", "\n"], '', $file['file_name']);

header('Content-Type: application/octet-stream');
header('Content-Length: ' . filesize($path));
header('Content-Disposition: attachment; filename="' . $downloadName . '"');
header('X-Content-Type-Options: nosniff');

readfile($path);
