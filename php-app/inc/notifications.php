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

    // 4. Important Target Updates (for Dean / Admin / Director)
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

    // 5. Target Decision Notifications for HoD
    if ($user['role'] === 'HoD') {
        try {
            $dept = $user['department'] ?? '';
            $uid  = (int) $user['id'];

            // 5a. Changes Requested by Dean/Admin
            $stmt = db()->prepare(
                "SELECT id, metric, department, review_remark, updated_at
                 FROM targets
                 WHERE status = 'Changes Requested' AND (department = ? OR created_by = ?)
                 ORDER BY updated_at DESC LIMIT 5"
            );
            $stmt->execute([$dept, $uid]);
            $changesTargets = $stmt->fetchAll();

            foreach ($changesTargets as $t) {
                $remarkText = !empty($t['review_remark']) ? $t['review_remark'] : 'Please review and revise.';
                $isUnread   = ($markAllTime === 0 || strtotime($t['updated_at']) > $markAllTime);
                $metricText = !empty($t['metric']) ? ' (' . $t['metric'] . ')' : '';
                $notifications[] = [
                    'id'          => 'target_changes_' . $t['id'],
                    'type'        => 'target',
                    'title'       => 'Target Changes Requested',
                    'description' => "The Dean requested changes to your target{$metricText}: " . $remarkText,
                    'time'        => time_ago($t['updated_at']),
                    'link'        => url('targets.php'),
                    'unread'      => $isUnread,
                    'icon'        => 'target'
                ];
            }

            // 5b. Targets Approved by Dean/Admin
            $stmt = db()->prepare(
                "SELECT id, metric, department, approved_at, updated_at
                 FROM targets
                 WHERE status = 'Approved' AND approved_by IS NOT NULL AND approved_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) AND (department = ? OR created_by = ?)
                 ORDER BY approved_at DESC LIMIT 5"
            );
            $stmt->execute([$dept, $uid]);
            $approvedTargets = $stmt->fetchAll();

            foreach ($approvedTargets as $t) {
                $timeRef    = !empty($t['approved_at']) ? $t['approved_at'] : $t['updated_at'];
                $isUnread   = ($markAllTime === 0 || strtotime($timeRef) > $markAllTime);
                $metricText = !empty($t['metric']) ? ' (' . $t['metric'] . ')' : '';
                $deptText   = !empty($t['department']) ? $t['department'] : 'your department';
                $notifications[] = [
                    'id'          => 'target_approved_' . $t['id'],
                    'type'        => 'target',
                    'title'       => 'Target Approved',
                    'description' => "Your target for {$deptText}{$metricText} was approved by the Dean.",
                    'time'        => time_ago($timeRef),
                    'link'        => url('targets.php'),
                    'unread'      => $isUnread,
                    'icon'        => 'target'
                ];
            }
        } catch (\Throwable $e) {}
    }

    return $notifications;
}
