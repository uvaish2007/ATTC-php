<?php
/**
 * Mark all notifications as read for the current user.
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../models/Announcement.php';

$user = require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $userId = (int) $user['id'];

    // 1. Record read receipt for all unread announcements visible to user
    if (function_exists('announcements_ready') && announcements_ready()) {
        try {
            [$visible, $params] = announcement_visibility($user);
            $sql = "SELECT a.id FROM announcements a
                    LEFT JOIN announcement_reads r
                           ON r.announcement_id = a.id AND r.user_id = ? AND r.read_at IS NOT NULL
                    WHERE ($visible) AND a.status = 'Published' AND r.id IS NULL";
            $stmt = db()->prepare($sql);
            $stmt->execute(array_merge([$userId], $params));
            $unreadIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

            foreach ($unreadIds as $aid) {
                announcement_mark_read((int)$aid, $userId);
            }
        } catch (\Throwable $e) {}
    }

    // 2. Set mark all timestamp in session
    $_SESSION['notifications_read_' . $userId] = time();

    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) || !empty($_REQUEST['ajax'])) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'unread_count' => 0]);
        exit;
    }

    $back = input('back') ?: url('dashboard.php');
    flash('success', 'All notifications marked as read.');
    redirect($back);
}
