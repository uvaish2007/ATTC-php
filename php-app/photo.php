<?php
/**
 * Secure profile-photo access controller.
 *
 * Serves one account's passport photograph from uploads/photos/. The file is
 * never linked directly, so every request is checked here first:
 *
 *   - you must be signed in;
 *   - you may always see your own photo;
 *   - otherwise the same rule as the Faculty Details document applies —
 *     Admin / Principal / Director / Dean see anyone, an HoD or Coordinator
 *     sees their own department only.
 *
 * Changing ?user=123 to another id therefore gains nothing that the Faculty
 * Details PDF would not already show.
 *
 *   photo.php?user=12
 */

require_once __DIR__ . '/inc/auth.php';
require_once __DIR__ . '/models/User.php';
require_once __DIR__ . '/models/FacultyAchievement.php';

$user = require_login();

$targetId = (int) input('user', 0);
if ($targetId <= 0) {
    http_response_code(400);
    exit('No account specified.');
}

if ((int) $user['id'] !== $targetId && !can_user_view_faculty_report($user, $targetId)) {
    http_response_code(403);
    exit('You are not allowed to view this photo.');
}

$filename = user_photo_filename($targetId);
$path     = user_photo_path($filename);

if ($path === null) {
    http_response_code(404);
    exit('No photo on file.');
}

$ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$mime = ($ext === 'png') ? 'image/png' : (($ext === 'webp') ? 'image/webp' : 'image/jpeg');

// Private: a photo may only be cached by the browser that asked for it.
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: inline; filename="' . basename($path) . '"');
header('Cache-Control: private, max-age=600');
header('X-Content-Type-Options: nosniff');

readfile($path);
