<?php
/**
 * Notifications helper for the header notification bell.
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../models/Announcement.php';
require_once __DIR__ . '/../models/Target.php';

function fetch_header_notifications(array $user): array
{
    $notifications = [];
    $userId = (int) ($user['id'] ?? 0);
    $markAllTime = $_SESSION['notifications_read_' . $userId] ?? 0;

    // 1. Pending Approvals (Dean, Admin, HoD)
    if (in_array($user['role'], ['Dean', 'Admin', 'HoD'], true)) {
        try {
            $pendingCount = pending_approvals_count($user);
            if ($pendingCount > 0) {
                $roleLabel = ($user['role'] === 'Dean') ? 'Dean' : (($user['role'] === 'HoD') ? 'HoD' : '');
                $descLabel = $roleLabel ? "$pendingCount record" . ($pendingCount > 1 ? 's' : '') . " awaiting $roleLabel review." : "$pendingCount record" . ($pendingCount > 1 ? 's' : '') . " awaiting your review.";
                $notifications[] = [
                    'id'          => 'approval_pending',
                    'type'        => 'approval',
                    'title'       => 'Pending Approvals',
                    'description' => $descLabel,
                    'time'        => 'Action Required',
                    'link'        => url('approvals.php'),
                    'unread'      => ($markAllTime === 0),
                    'icon'        => 'approvals'
                ];
            }
        } catch (\Throwable $e) {}
    }

    // 2. Unread Announcements
    if (function_exists('announcements_ready') && announcements_ready()) {
        try {
            [$visible, $params] = announcement_visibility($user);
            $sql = "SELECT a.id, a.title, a.priority, a.created_at
                    FROM announcements a
                    LEFT JOIN announcement_reads r
                           ON r.announcement_id = a.id AND r.user_id = ? AND r.read_at IS NOT NULL
                    WHERE ($visible)
                      AND a.status = 'Published'
                      AND (a.publish_at IS NULL OR a.publish_at <= NOW())
                      AND (a.expires_at IS NULL OR a.expires_at >= NOW())
                      AND r.id IS NULL
                    ORDER BY a.created_at DESC
                    LIMIT 5";
            $stmt = db()->prepare($sql);
            $stmt->execute(array_merge([$userId], $params));
            $unreadNotices = $stmt->fetchAll();

            foreach ($unreadNotices as $n) {
                $isUnread = ($markAllTime === 0 || strtotime($n['created_at']) > $markAllTime);
                $notifications[] = [
                    'id'          => 'announcement_' . $n['id'],
                    'ann_id'      => $n['id'],
                    'type'        => 'announcement',
                    'title'       => 'Announcement: ' . ($n['priority'] !== 'Normal' ? '[' . $n['priority'] . '] ' : ''),
                    'description' => $n['title'],
                    'time'        => time_ago($n['created_at']),
                    'link'        => url('announcements.php?view=' . $n['id']),
                    'unread'      => $isUnread,
                    'icon'        => 'megaphone'
                ];
            }
        } catch (\Throwable $e) {}
    }

    // 3. Upcoming Deadlines
    if (function_exists('announcement_deadlines')) {
        try {
            $deadlines = announcement_deadlines($user, 3);
            foreach ($deadlines as $d) {
                $timeStr = !empty($d['expires_at']) ? date('d M Y', strtotime($d['expires_at'])) : '';
                $notifications[] = [
                    'id'          => 'deadline_' . $d['id'],
                    'type'        => 'deadline',
                    'title'       => 'Upcoming Deadline',
                    'description' => $d['title'] . ($timeStr ? " (Due $timeStr)" : ''),
                    'time'        => $timeStr ?: 'Upcoming',
                    'link'        => url('announcements.php?view=' . $d['id']),
                    'unread'      => false,
                    'icon'        => 'reports'
                ];
            }
        } catch (\Throwable $e) {}
    }

    // 4. Important Target Updates
    if (in_array($user['role'], ['Dean', 'Admin', 'Director'], true)) {
        try {
            $targetCount = targets_pending_count();
            if ($targetCount > 0) {
                $notifications[] = [
                    'id'          => 'target_pending',
                    'type'        => 'target',
                    'title'       => 'Target Review Required',
                    'description' => "$targetCount target" . ($targetCount > 1 ? 's' : '') . " submitted for review.",
                    'time'        => 'Action Required',
                    'link'        => url('targets.php'),
                    'unread'      => ($markAllTime === 0),
                    'icon'        => 'target'
                ];
            }
        } catch (\Throwable $e) {}
    }

    return $notifications;
}
