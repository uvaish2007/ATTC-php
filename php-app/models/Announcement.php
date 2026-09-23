<?php
require_once __DIR__ . '/../inc/db.php';

function announcement_categories(): array
{
    return ['Academic', 'Examination', 'IQAC', 'Placement', 'Administration', 'Events', 'Circular', 'Research'];
}

function announcement_priorities(): array
{
    return ['Normal', 'Important', 'Urgent'];
}

function announcement_can_manage(array $user): bool
{
    return in_array($user['role'], ['Admin', 'Director', 'Principal'], true);
}

function announcements_ready(): bool
{
    static $ready = null;

    if ($ready === null) {
        try {
            $ready = (bool) db()->query("SHOW TABLES LIKE 'announcements'")->fetchColumn();
        } catch (\PDOException $e) {
            $ready = false;
        }
    }

    return $ready;
}

function announcement_sync_expired(): int
{
    if (!announcements_ready()) {
        return 0;
    }

    static $synced = false;
    if ($synced) {
        return 0;
    }
    $synced = true;

    return announcement_archive_expired()['expired'];
}

function announcements_archive_ready(): bool
{
    static $ready = null;

    if ($ready === null) {
        try {
            $ready = (bool) db()->query("SHOW TABLES LIKE 'announcements_archive'")->fetchColumn();
        } catch (\PDOException $e) {
            $ready = false;
        }
    }

    return $ready;
}

function announcement_archive_fields(): array
{
    return ['title', 'body', 'category', 'priority', 'audience', 'department',
            'pinned', 'publish_at', 'expires_at', 'require_read', 'views',
            'created_by', 'created_at', 'updated_at'];
}

function announcement_archive_one(int $announcementId): array
{
    if ($announcementId <= 0) {
        return [false, 'Invalid announcement id.', false];
    }
    if (!announcements_ready() || !announcements_archive_ready()) {
        return [false, 'The announcement archive table has not been created yet.', false];
    }

    try {
        $stmt = db()->prepare(
            'SELECT a.*, u.name AS author_name
               FROM announcements a
               LEFT JOIN users u ON u.id = a.created_by
              WHERE a.id = ?'
        );
        $stmt->execute([$announcementId]);
        $row = $stmt->fetch();

        if (!$row) {
            return [false, 'That announcement no longer exists.', false];
        }

        $existing = db()->prepare(
            'SELECT archive_id, restore_status FROM announcements_archive WHERE original_announcement_id = ?'
        );
        $existing->execute([$announcementId]);
        $already = $existing->fetch();

        $values = [
            $row['title'],
            $row['body'],
            $row['category'],
            $row['priority'],
            $row['audience'],
            $row['department'],
            $row['status'],
            (int) $row['pinned'],
            $row['publish_at'],
            $row['expires_at'],
            (int) $row['require_read'],
            (int) $row['views'],
            $row['created_by'] !== null ? (int) $row['created_by'] : null,
            $row['author_name'],
            $row['created_at'],
            $row['updated_at'],
        ];

        if ($already) {
            if ($already['restore_status'] !== 'Restored') {
                // Already archived and never restored — nothing to do. This is
                // what makes a second expiry sweep a no-op.
                return [true, 'Already archived.', true];
            }

            $upd = db()->prepare(
                'UPDATE announcements_archive
                    SET title = ?, body = ?, category = ?, priority = ?, audience = ?, department = ?,
                        original_status = ?, pinned = ?, publish_at = ?, expires_at = ?,
                        require_read = ?, views = ?, created_by = ?, created_by_name = ?,
                        created_at = ?, updated_at = ?, archived_at = NOW(),
                        restore_status = \'Archived\', restored_at = NULL,
                        restored_by = NULL, restored_announcement_id = NULL
                  WHERE archive_id = ?'
            );
            $upd->execute(array_merge($values, [(int) $already['archive_id']]));

            return [true, 'Archive record refreshed.', false];
        }

        $ins = db()->prepare(
            'INSERT INTO announcements_archive
                (original_announcement_id, title, body, category, priority, audience, department,
                 original_status, pinned, publish_at, expires_at, require_read, views,
                 created_by, created_by_name, created_at, updated_at, archived_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
        );
        $ins->execute(array_merge([$announcementId], $values));

        return [true, 'Announcement archived.', false];
    } catch (\PDOException $e) {
        if ($e->getCode() === '23000') {
            return [true, 'Already archived.', true];
        }

        error_log('announcement_archive_one failed for ' . $announcementId . ': ' . $e->getMessage());
        return [false, 'The announcement could not be archived.', false];
    }
}

function announcement_archive_expired(): array
{
    $result = ['expired' => 0, 'archived' => 0, 'failed' => 0];

    if (!announcements_ready()) {
        return $result;
    }

    if (!announcements_archive_ready()) {
        try {
            $result['expired'] = (int) db()->exec(
                "UPDATE announcements
                    SET status = 'Expired', pinned = 0
                  WHERE status = 'Published'
                    AND expires_at IS NOT NULL
                    AND expires_at < NOW()"
            );
        } catch (\PDOException $e) {
            error_log('announcement_archive_expired (no archive table) failed: ' . $e->getMessage());
        }

        return $result;
    }

    try {
        $due = db()->query(
            "SELECT a.id, a.status
               FROM announcements a
          LEFT JOIN announcements_archive ar ON ar.original_announcement_id = a.id
              WHERE a.expires_at IS NOT NULL
                AND a.expires_at < NOW()
                AND (
                      a.status = 'Published'
                   OR (a.status = 'Expired' AND (ar.archive_id IS NULL OR ar.restore_status = 'Restored'))
                )"
        )->fetchAll();
    } catch (\PDOException $e) {
        error_log('announcement_archive_expired (select) failed: ' . $e->getMessage());
        return $result;
    }

    $pdo = db();

    foreach ($due as $row) {
        $id = (int) $row['id'];

        try {
            $pdo->beginTransaction();

            [$ok, $msg, $wasAlready] = announcement_archive_one($id);

            if (!$ok) {
                $pdo->rollBack();
                $result['failed']++;
                continue;
            }

            if (!$wasAlready) {
                $result['archived']++;
            }

            // Only now, with the historical copy safely written, does the
            // original change state. It is updated, never deleted.
            if ($row['status'] === 'Published') {
                $pdo->prepare("UPDATE announcements SET status = 'Expired', pinned = 0 WHERE id = ? AND status = 'Published'")
                    ->execute([$id]);
                $result['expired']++;
            }

            $pdo->commit();
        } catch (\PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $result['failed']++;
            error_log('announcement_archive_expired failed for ' . $id . ': ' . $e->getMessage());
        }
    }

    return $result;
}

function announcement_archive_count(): int
{
    if (!announcements_archive_ready()) {
        return 0;
    }

    try {
        return (int) db()->query('SELECT COUNT(*) FROM announcements_archive')->fetchColumn();
    } catch (\PDOException $e) {
        return 0;
    }
}

function announcement_archive_counts(): array
{
    $counts = ['total' => 0, 'archived' => 0, 'restored' => 0];

    if (!announcements_archive_ready()) {
        return $counts;
    }

    try {
        $rows = db()->query(
            'SELECT restore_status, COUNT(*) AS c FROM announcements_archive GROUP BY restore_status'
        )->fetchAll();

        foreach ($rows as $row) {
            $key = $row['restore_status'] === 'Restored' ? 'restored' : 'archived';
            $counts[$key]   = (int) $row['c'];
            $counts['total'] += (int) $row['c'];
        }
    } catch (\PDOException $e) {
        error_log('announcement_archive_counts failed: ' . $e->getMessage());
    }

    return $counts;
}

function announcements_archive_list(array $filters = []): array
{
    $empty = ['rows' => [], 'total' => 0, 'page' => 1, 'pages' => 1];

    if (!announcements_archive_ready()) {
        return $empty;
    }

    $where  = ['1 = 1'];
    $params = [];

    $search = trim((string) ($filters['search'] ?? ''));
    if ($search !== '') {
        $where[]  = '(ar.title LIKE ? OR ar.body LIKE ?)';
        $params[] = "%$search%";
        $params[] = "%$search%";
    }

    $category = trim((string) ($filters['category'] ?? ''));
    if ($category !== '' && in_array($category, announcement_categories(), true)) {
        $where[]  = 'ar.category = ?';
        $params[] = $category;
    }

    $department = trim((string) ($filters['department'] ?? ''));
    if ($department !== '') {
        $where[]  = 'ar.department = ?';
        $params[] = $department;
    }

    $restore = trim((string) ($filters['restore_status'] ?? ''));
    if (in_array($restore, ['Archived', 'Restored'], true)) {
        $where[]  = 'ar.restore_status = ?';
        $params[] = $restore;
    }

    $from = announcement_clean_datetime((string) ($filters['archived_from'] ?? ''));
    if ($from !== null) {
        $where[]  = 'ar.archived_at >= ?';
        $params[] = date('Y-m-d 00:00:00', strtotime($from));
    }

    $to = announcement_clean_datetime((string) ($filters['archived_to'] ?? ''));
    if ($to !== null) {
        $where[]  = 'ar.archived_at <= ?';
        $params[] = date('Y-m-d 23:59:59', strtotime($to));
    }

    $whereSql = implode(' AND ', $where);

    // Whitelist: the key chooses a clause, the key itself is never used in SQL.
    $order = [
        'archived_new' => 'ar.archived_at DESC, ar.archive_id DESC',
        'archived_old' => 'ar.archived_at ASC, ar.archive_id ASC',
        'expiry_new'   => 'ar.expires_at IS NULL, ar.expires_at DESC',
        'expiry_old'   => 'ar.expires_at IS NULL, ar.expires_at ASC',
        'title'        => 'ar.title ASC',
    ][$filters['sort'] ?? 'archived_new'] ?? 'ar.archived_at DESC, ar.archive_id DESC';

    $perPage = max(1, min(100, (int) ($filters['per_page'] ?? 10)));
    $page    = max(1, (int) ($filters['page'] ?? 1));

    try {
        $countStmt = db()->prepare("SELECT COUNT(*) FROM announcements_archive ar WHERE $whereSql");
        $countStmt->execute($params);

        $total  = (int) $countStmt->fetchColumn();
        $pages  = max(1, (int) ceil($total / $perPage));
        $page   = min($page, $pages);
        $offset = ($page - 1) * $perPage;

        // LIMIT / OFFSET are cast to int, never bound as text — the same thing
        // announcements_list() does.
        $stmt = db()->prepare(
            "SELECT ar.*,
                    u.name AS restored_by_name,
                    (SELECT COUNT(*) FROM announcements a WHERE a.id = ar.original_announcement_id) AS original_exists
               FROM announcements_archive ar
          LEFT JOIN users u ON u.id = ar.restored_by
              WHERE $whereSql
           ORDER BY $order
              LIMIT $perPage OFFSET $offset"
        );
        $stmt->execute($params);

        return ['rows' => $stmt->fetchAll(), 'total' => $total, 'page' => $page, 'pages' => $pages];
    } catch (\PDOException $e) {
        error_log('announcements_archive_list failed: ' . $e->getMessage());
        return $empty;
    }
}

function announcement_archive_find(int $archiveId): ?array
{
    if ($archiveId <= 0 || !announcements_archive_ready()) {
        return null;
    }

    try {
        $stmt = db()->prepare(
            'SELECT ar.*,
                    u.name AS restored_by_name,
                    (SELECT COUNT(*) FROM announcements a WHERE a.id = ar.original_announcement_id) AS original_exists
               FROM announcements_archive ar
          LEFT JOIN users u ON u.id = ar.restored_by
              WHERE ar.archive_id = ?'
        );
        $stmt->execute([$archiveId]);

        return $stmt->fetch() ?: null;
    } catch (\PDOException $e) {
        error_log('announcement_archive_find failed: ' . $e->getMessage());
        return null;
    }
}

function announcement_archive_attachments(array $originalIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $originalIds))));

    if (!$ids || !announcements_ready()) {
        return [];
    }

    $in = implode(',', array_fill(0, count($ids), '?'));

    try {
        $stmt = db()->prepare(
            "SELECT id, announcement_id, file_name, size_bytes
               FROM announcement_files
              WHERE announcement_id IN ($in)
           ORDER BY id"
        );
        $stmt->execute($ids);

        $out = [];
        foreach ($stmt->fetchAll() as $file) {
            $out[(int) $file['announcement_id']][] = $file;
        }

        return $out;
    } catch (\PDOException $e) {
        error_log('announcement_archive_attachments failed: ' . $e->getMessage());
        return [];
    }
}

function announcement_archive_needs_expiry(array $archive): bool
{
    return !empty($archive['expires_at']) && strtotime((string) $archive['expires_at']) < time();
}

function announcement_restore_from_archive(int $archiveId, int $adminId, string $newExpiresAt = ''): array
{
    if ($archiveId <= 0) {
        return [false, 'Invalid archive record.'];
    }
    if (!announcements_ready() || !announcements_archive_ready()) {
        return [false, 'The announcement archive table has not been created yet.'];
    }

    $archive = announcement_archive_find($archiveId);
    if (!$archive) {
        return [false, 'That archived announcement could not be found.'];
    }
    if ($archive['restore_status'] === 'Restored') {
        return [false, 'This archived announcement has already been restored.'];
    }

    /* ---- work out the expiry date the notice comes back with ------------ */
    $expiresAt = $archive['expires_at'];

    if (announcement_archive_needs_expiry($archive)) {
        $chosen = announcement_clean_datetime($newExpiresAt);

        if ($chosen === null) {
            return [false, 'The original expiry date has passed. Choose a new expiry date before restoring.'];
        }
        if (strtotime($chosen) <= time()) {
            return [false, 'The new expiry date must be in the future, otherwise the announcement expires again immediately.'];
        }

        $expiresAt = $chosen;
    } elseif (trim($newExpiresAt) !== '') {
        $chosen = announcement_clean_datetime($newExpiresAt);

        if ($chosen === null || strtotime($chosen) <= time()) {
            return [false, 'The new expiry date must be a valid date in the future.'];
        }

        $expiresAt = $chosen;
    }

    $originalId = (int) $archive['original_announcement_id'];
    $pdo        = db();

    try {
        $pdo->beginTransaction();

        $claim = $pdo->prepare(
            "UPDATE announcements_archive
                SET restore_status = 'Restored', restored_at = NOW(), restored_by = ?
              WHERE archive_id = ? AND restore_status = 'Archived'"
        );
        $claim->execute([$adminId, $archiveId]);

        if ($claim->rowCount() === 0) {
            $pdo->rollBack();
            return [false, 'This archived announcement has already been restored.'];
        }

        // Is the original still there? Asked inside the transaction so the
        // answer cannot change underneath us.
        $exists = $pdo->prepare('SELECT COUNT(*) FROM announcements WHERE id = ?');
        $exists->execute([$originalId]);
        $liveId = (int) $exists->fetchColumn() > 0 ? $originalId : 0;

        if ($liveId > 0) {
            $pdo->prepare(
                "UPDATE announcements
                    SET status = 'Published', expires_at = ?, pinned = 0
                  WHERE id = ?"
            )->execute([$expiresAt, $liveId]);

            $restoredId = $liveId;
            $note       = 'Announcement #' . $restoredId . ' restored and published again.';
        } else {
            $insert = $pdo->prepare(
                "INSERT INTO announcements
                    (title, body, category, priority, audience, department, status, pinned,
                     publish_at, expires_at, require_read, views, created_by, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, 'Published', 0, ?, ?, ?, ?, ?, ?)"
            );
            $insert->execute([
                $archive['title'],
                $archive['body'],
                $archive['category'],
                $archive['priority'],
                $archive['audience'],
                $archive['department'],
                $archive['publish_at'],
                $expiresAt,
                (int) $archive['require_read'],
                (int) $archive['views'],
                $archive['created_by'] !== null ? (int) $archive['created_by'] : null,
                $archive['created_at'] ?: date('Y-m-d H:i:s'),
            ]);

            $restoredId = (int) $pdo->lastInsertId();
            $note       = 'The original announcement had been deleted, so it was recreated as #' . $restoredId . '.';
        }

        $pdo->prepare('UPDATE announcements_archive SET restored_announcement_id = ? WHERE archive_id = ?')
            ->execute([$restoredId, $archiveId]);

        $pdo->commit();
    } catch (\PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('announcement_restore_from_archive failed for ' . $archiveId . ': ' . $e->getMessage());
        return [false, 'The announcement could not be restored. Nothing was changed.'];
    }

    $when = $expiresAt ? ' It now expires on ' . date('d-m-Y H:i', strtotime((string) $expiresAt)) . '.' : '';

    return [true, $note . $when . ' The archive record has been kept.'];
}

function announcement_visibility(array $user): array
{
    if (announcement_can_manage($user)) {
        return ['1=1', []];
    }

    $dept = (string) ($user['department'] ?? '');
    $sql = "a.status = 'Published'
            AND (a.publish_at IS NULL OR a.publish_at <= NOW())
            AND (a.expires_at IS NULL OR a.expires_at >= NOW())
            AND (a.audience = 'Everyone' OR a.audience = ? OR a.created_by = ?)
            AND (a.department IS NULL OR a.department = '' OR a.department = ? OR REPLACE(a.department, ' ', '') = REPLACE(?, ' ', ''))";

    return [$sql, [$user['role'], (int)$user['id'], $dept, $dept]];
}

function announcement_state(array $row): string
{
    if ($row['status'] === 'Draft')    return 'Draft';
    if ($row['status'] === 'Archived') return 'Archived';
    if ($row['status'] === 'Expired')  return 'Expired';

    if ($row['publish_at'] && strtotime($row['publish_at']) > time()) return 'Scheduled';
    if ($row['expires_at'] && strtotime($row['expires_at']) < time()) return 'Expired';

    return 'Active';
}

function announcements_list(array $user, array $filters = []): array
{
    if (!announcements_ready()) {
        return ['rows' => [], 'total' => 0, 'page' => 1, 'pages' => 1];
    }

    announcement_sync_expired();

    [$visible, $params] = announcement_visibility($user);

    $where  = [$visible];
    $userId = (int) $user['id'];

    $search = trim((string) ($filters['search'] ?? ''));
    if ($search !== '') {
        $where[]  = '(a.title LIKE ? OR a.body LIKE ?)';
        $params[] = "%$search%";
        $params[] = "%$search%";
    }

    $category = trim((string) ($filters['category'] ?? ''));
    if ($category !== '' && in_array($category, announcement_categories(), true)) {
        $where[]  = 'a.category = ?';
        $params[] = $category;
    }

    $curYear  = (int) date('Y');
    $curMonth = (int) date('n');

    $targetYear  = isset($filters['year']) ? (int) $filters['year'] : $curYear;
    $targetMonth = isset($filters['month']) ? (int) $filters['month'] : $curMonth;
    $targetDay   = isset($filters['day']) ? (int) $filters['day'] : 0;

    // Strict guard: Never allow future months!
    if ($targetYear > $curYear || ($targetYear === $curYear && $targetMonth > $curMonth)) {
        $targetYear  = $curYear;
        $targetMonth = $curMonth;
        $targetDay   = 0;
    }

    $isCurrentMonth = ($targetYear === $curYear && $targetMonth === $curMonth);

    $scope = (string) ($filters['scope'] ?? 'all');
    if ($scope === 'archived') {
        $where[] = "a.status = 'Archived'";
    } elseif ($scope === 'expired') {
        $where[] = "(a.status = 'Expired' OR (a.expires_at IS NOT NULL AND a.expires_at < NOW()))";
    } elseif ($scope === 'mine') {
        $where[]  = 'a.created_by = ?';
        $params[] = $userId;
    } elseif ($scope === 'bookmarked') {
        $where[]  = 'EXISTS (SELECT 1 FROM announcement_reads rb
                             WHERE rb.announcement_id = a.id AND rb.user_id = ? AND rb.bookmarked = 1)';
        $params[] = $userId;
    } else {
        if ($targetDay > 0) {
            $targetDate = sprintf('%04d-%02d-%02d', $targetYear, $targetMonth, $targetDay);
            $where[] = "a.status NOT IN ('Archived', 'Draft') AND (
                (DATE(a.created_at) = ?)
                OR (a.expires_at IS NOT NULL AND DATE(a.expires_at) = ?)
                OR (a.publish_at IS NOT NULL AND DATE(a.publish_at) = ?)
            )";
            $params[] = $targetDate;
            $params[] = $targetDate;
            $params[] = $targetDate;
        } elseif ($isCurrentMonth) {
            $where[] = "a.status NOT IN ('Archived') AND (
                (YEAR(a.created_at) = ? AND MONTH(a.created_at) = ?)
                OR (a.expires_at IS NOT NULL AND YEAR(a.expires_at) = ? AND MONTH(a.expires_at) = ?)
                OR (a.status = 'Published' AND (a.expires_at IS NULL OR a.expires_at >= NOW()))
            )";
            $params[] = $targetYear;
            $params[] = $targetMonth;
            $params[] = $targetYear;
            $params[] = $targetMonth;
        } else {
            $where[] = "a.status NOT IN ('Archived', 'Draft') AND (
                (YEAR(a.created_at) = ? AND MONTH(a.created_at) = ?)
                OR (a.expires_at IS NOT NULL AND YEAR(a.expires_at) = ? AND MONTH(a.expires_at) = ?)
                OR (a.publish_at IS NOT NULL AND YEAR(a.publish_at) = ? AND MONTH(a.publish_at) = ?)
            )";
            $params[] = $targetYear;
            $params[] = $targetMonth;
            $params[] = $targetYear;
            $params[] = $targetMonth;
            $params[] = $targetYear;
            $params[] = $targetMonth;
        }
    }

    $whereSql = implode(' AND ', $where);

    $rowParams = [$userId, $userId];

    $select = "SELECT a.*,
                      u.name AS author_name,
                      (SELECT COUNT(*) FROM announcement_files f
                        WHERE f.announcement_id = a.id) AS file_count,
                      (SELECT COUNT(*) FROM announcement_reads r
                        WHERE r.announcement_id = a.id AND r.read_at IS NOT NULL) AS read_count,
                      (SELECT COUNT(*) FROM announcement_reads r2
                        WHERE r2.announcement_id = a.id AND r2.user_id = ? AND r2.read_at IS NOT NULL) AS is_read,
                      (SELECT COUNT(*) FROM announcement_reads r3
                        WHERE r3.announcement_id = a.id AND r3.user_id = ? AND r3.bookmarked = 1) AS bookmarked
               FROM announcements a
               LEFT JOIN users u ON u.id = a.created_by
               WHERE $whereSql";

    $order = [
        'oldest' => 'a.pinned DESC, a.created_at ASC',
        'viewed' => 'a.pinned DESC, a.views DESC, a.created_at DESC',
        'unread' => 'a.pinned DESC, is_read ASC, a.created_at DESC',
    ][$filters['sort'] ?? 'newest'] ?? 'a.pinned DESC, a.created_at DESC';

    $perPage = max(1, (int) ($filters['per_page'] ?? 8));
    $page    = max(1, (int) ($filters['page'] ?? 1));

    $countStmt = db()->prepare("SELECT COUNT(*) FROM announcements a WHERE $whereSql");
    $countStmt->execute($params);

    $total = (int) $countStmt->fetchColumn();
    $pages = max(1, (int) ceil($total / $perPage));
    $page  = min($page, $pages);
    $offset = ($page - 1) * $perPage;

    $stmt = db()->prepare("$select ORDER BY $order LIMIT $perPage OFFSET $offset");
    $stmt->execute(array_merge($rowParams, $params));

    $rows = $stmt->fetchAll();
    foreach ($rows as $i => $row) {
        $rows[$i]['_state'] = announcement_state($row);
    }

    return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages];
}

function announcement_find(int $id, array $user): ?array
{
    if (!announcements_ready()) {
        return null;
    }

    [$visible, $params] = announcement_visibility($user);

    $stmt = db()->prepare(
        "SELECT a.*, u.name AS author_name, u.role AS author_role
         FROM announcements a
         LEFT JOIN users u ON u.id = a.created_by
         WHERE a.id = ? AND ($visible)"
    );
    $stmt->execute(array_merge([$id], $params));

    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }

    $row['_state'] = announcement_state($row);

    return $row;
}

function announcement_files(int $id): array
{
    if (!announcements_ready()) {
        return [];
    }

    $stmt = db()->prepare('SELECT * FROM announcement_files WHERE announcement_id = ? ORDER BY id');
    $stmt->execute([$id]);

    return $stmt->fetchAll();
}

function announcement_recent_files(int $limit = 4): array
{
    if (!announcements_ready()) {
        return [];
    }

    $limit = max(1, (int) $limit);

    return db()->query(
        "SELECT f.*, a.title
         FROM announcement_files f
         JOIN announcements a ON a.id = f.announcement_id
         WHERE a.status = 'Published'
         ORDER BY f.id DESC LIMIT $limit"
    )->fetchAll();
}

function announcement_audience_size(array $announcement): int
{
    $sql    = 'SELECT COUNT(*) FROM users WHERE status = 1';
    $params = [];

    if ($announcement['audience'] !== 'Everyone') {
        $sql .= ' AND role = ?';
        $params[] = $announcement['audience'];
    }

    if (!empty($announcement['department'])) {
        $sql .= ' AND department = ?';
        $params[] = $announcement['department'];
    }

    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    return (int) $stmt->fetchColumn();
}

function announcement_analytics(int $id, array $announcement): array
{
    $audience = announcement_audience_size($announcement);

    $sql = "SELECT u.id, u.name, u.department, r.read_at
            FROM users u
            LEFT JOIN announcement_reads r
                   ON r.announcement_id = ? AND r.user_id = u.id AND r.read_at IS NOT NULL
            WHERE u.status = 1";
    $params = [$id];

    if ($announcement['audience'] !== 'Everyone') {
        $sql .= ' AND u.role = ?';
        $params[] = $announcement['audience'];
    }
    if (!empty($announcement['department'])) {
        $sql .= ' AND u.department = ?';
        $params[] = $announcement['department'];
    }

    $sql .= ' ORDER BY u.department, u.name';

    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    $byDepartment = [];
    $unread       = [];
    $readCount    = 0;

    foreach ($stmt as $person) {
        $dept = $person['department'] ?: 'Unassigned';

        if (!isset($byDepartment[$dept])) {
            $byDepartment[$dept] = ['total' => 0, 'read' => 0];
        }

        $byDepartment[$dept]['total']++;

        if ($person['read_at']) {
            $byDepartment[$dept]['read']++;
            $readCount++;
        } else {
            $unread[] = $person;
        }
    }

    ksort($byDepartment);

    return [
        'audience'     => $audience,
        'read'         => $readCount,
        'percent'      => $audience > 0 ? (int) round($readCount / $audience * 100) : 0,
        'byDepartment' => $byDepartment,
        'unread'       => $unread,
    ];
}

function announcement_stats(array $user): array
{
    $blank = ['total' => 0, 'active' => 0, 'expiring' => 0, 'unread' => 0];

    if (!announcements_ready()) {
        return $blank;
    }

    [$visible, $params] = announcement_visibility($user);

    $stmt = db()->prepare("SELECT COUNT(*) FROM announcements a WHERE $visible");
    $stmt->execute($params);
    $total = (int) $stmt->fetchColumn();

    $live = "a.status = 'Published'
             AND (a.publish_at IS NULL OR a.publish_at <= NOW())
             AND (a.expires_at IS NULL OR a.expires_at >= NOW())";

    $stmt = db()->prepare("SELECT COUNT(*) FROM announcements a WHERE ($visible) AND $live");
    $stmt->execute($params);
    $active = (int) $stmt->fetchColumn();

    $stmt = db()->prepare(
        "SELECT COUNT(*) FROM announcements a
         WHERE ($visible) AND $live
           AND a.expires_at IS NOT NULL
           AND a.expires_at <= DATE_ADD(NOW(), INTERVAL 7 DAY)"
    );
    $stmt->execute($params);
    $expiring = (int) $stmt->fetchColumn();

    $stmt = db()->prepare(
        "SELECT COUNT(*) FROM announcements a
         WHERE ($visible)
           AND (a.status = 'Expired' OR (a.expires_at IS NOT NULL AND a.expires_at < NOW()))"
    );
    $stmt->execute($params);
    $expired = (int) $stmt->fetchColumn();

    return [
        'total'    => $total,
        'active'   => $active,
        'expiring' => $expiring,
        'expired'  => $expired,
        'unread'   => announcement_can_manage($user)
            ? unread_receipts_count()
            : unread_announcements_count($user),
    ];
}

function unread_receipts_count(): int
{
    if (!announcements_ready()) {
        return 0;
    }

    $sql = "SELECT COUNT(*)
            FROM announcements a
            JOIN users u
              ON u.status = 1
             AND u.role = 'Faculty'
             AND (a.audience = 'Everyone' OR a.audience = 'Faculty')
             AND (a.department IS NULL OR a.department = '' OR a.department = u.department)
            LEFT JOIN announcement_reads r
              ON r.announcement_id = a.id AND r.user_id = u.id AND r.read_at IS NOT NULL
            WHERE a.status = 'Published'
              AND (a.publish_at IS NULL OR a.publish_at <= NOW())
              AND (a.expires_at IS NULL OR a.expires_at >= NOW())
              AND r.id IS NULL";

    return (int) db()->query($sql)->fetchColumn();
}

function unread_announcements_count(array $user): int
{
    if (!announcements_ready()) {
        return 0;
    }

    $sql = "SELECT COUNT(*)
            FROM announcements a
            LEFT JOIN announcement_reads r
              ON r.announcement_id = a.id AND r.user_id = ? AND r.read_at IS NOT NULL
            WHERE a.status = 'Published'
              AND (a.publish_at IS NULL OR a.publish_at <= NOW())
              AND (a.expires_at IS NULL OR a.expires_at >= NOW())
              AND (a.audience = 'Everyone' OR a.audience = ?)
              AND (a.department IS NULL OR a.department = '' OR a.department = ?)
              AND r.id IS NULL";

    $stmt = db()->prepare($sql);
    $stmt->execute([(int) $user['id'], $user['role'], (string) ($user['department'] ?? '')]);

    return (int) $stmt->fetchColumn();
}

function announcement_deadlines(array $user, int $limit = 5): array
{
    if (!announcements_ready()) {
        return [];
    }

    [$visible, $params] = announcement_visibility($user);
    $limit = max(1, (int) $limit);

    $stmt = db()->prepare(
        "SELECT a.id, a.title, a.expires_at, a.priority
         FROM announcements a
         WHERE ($visible)
           AND a.status = 'Published'
           AND a.expires_at IS NOT NULL
           AND a.expires_at >= NOW()
         ORDER BY a.expires_at ASC
         LIMIT $limit"
    );
    $stmt->execute($params);

    return $stmt->fetchAll();
}

function announcement_calendar(array $user, int $year, int $month): array
{
    if (!announcements_ready()) {
        return [];
    }

    [$visible, $params] = announcement_visibility($user);

    $days = [];

    $stmt = db()->prepare(
        "SELECT DAY(a.expires_at) AS day, COUNT(*) AS n
         FROM announcements a
         WHERE ($visible)
           AND a.status NOT IN ('Archived')
           AND a.expires_at IS NOT NULL
           AND YEAR(a.expires_at) = ? AND MONTH(a.expires_at) = ?
         GROUP BY DAY(a.expires_at)"
    );
    $stmt->execute(array_merge($params, [$year, $month]));
    foreach ($stmt as $row) {
        $days[(int) $row['day']] = (int) $row['n'];
    }

    $stmt2 = db()->prepare(
        "SELECT DAY(a.created_at) AS day, COUNT(*) AS n
         FROM announcements a
         WHERE ($visible)
           AND a.status NOT IN ('Archived')
           AND YEAR(a.created_at) = ? AND MONTH(a.created_at) = ?
         GROUP BY DAY(a.created_at)"
    );
    $stmt2->execute(array_merge($params, [$year, $month]));
    foreach ($stmt2 as $row) {
        $d = (int) $row['day'];
        $days[$d] = ($days[$d] ?? 0) + (int) $row['n'];
    }

    return $days;
}

function announcement_create(array $fields, int $authorId): array
{
    $title = trim((string) ($fields['title'] ?? ''));
    $body  = trim((string) ($fields['body'] ?? ''));

    if ($title === '' || $body === '') {
        return [false, 'A title and a message are both required.', 0];
    }

    $stmt = db()->prepare(
        'INSERT INTO announcements
            (title, body, category, priority, audience, department, status, pinned,
             publish_at, expires_at, require_read, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );

    $stmt->execute([
        $title,
        $body,
        announcement_clean_category($fields['category'] ?? ''),
        announcement_clean_priority($fields['priority'] ?? ''),
        announcement_clean_audience($fields['audience'] ?? ''),
        ($fields['department'] ?? '') ?: null,
        ($fields['status'] ?? 'Published') === 'Draft' ? 'Draft' : 'Published',
        !empty($fields['pinned']) ? 1 : 0,
        announcement_clean_datetime($fields['publish_at'] ?? ''),
        announcement_clean_datetime($fields['expires_at'] ?? ''),
        !empty($fields['require_read']) ? 1 : 0,
        $authorId,
    ]);

    $id = (int) db()->lastInsertId();

    // Only one notice can be the pinned one at a time.
    if (!empty($fields['pinned'])) {
        announcement_pin($id);
    }

    return [true, 'Announcement published.', $id];
}

function announcement_update(int $id, array $fields): array
{
    $title = trim((string) ($fields['title'] ?? ''));
    $body  = trim((string) ($fields['body'] ?? ''));

    if ($title === '' || $body === '') {
        return [false, 'A title and a message are both required.'];
    }

    $stmt = db()->prepare(
        'UPDATE announcements SET
            title = ?, body = ?, category = ?, priority = ?, audience = ?, department = ?,
            status = ?, pinned = ?, publish_at = ?, expires_at = ?, require_read = ?
         WHERE id = ?'
    );

    $stmt->execute([
        $title,
        $body,
        announcement_clean_category($fields['category'] ?? ''),
        announcement_clean_priority($fields['priority'] ?? ''),
        announcement_clean_audience($fields['audience'] ?? ''),
        ($fields['department'] ?? '') ?: null,
        in_array($fields['status'] ?? '', ['Draft', 'Published', 'Archived', 'Expired'], true) ? $fields['status'] : 'Published',
        !empty($fields['pinned']) ? 1 : 0,
        announcement_clean_datetime($fields['publish_at'] ?? ''),
        announcement_clean_datetime($fields['expires_at'] ?? ''),
        !empty($fields['require_read']) ? 1 : 0,
        $id,
    ]);

    if (!empty($fields['pinned'])) {
        announcement_pin($id);
    }

    return [true, 'Announcement updated.'];
}

function announcement_pin(int $id): void
{
    db()->prepare('UPDATE announcements SET pinned = 0 WHERE id <> ?')->execute([$id]);
    db()->prepare('UPDATE announcements SET pinned = 1 WHERE id = ?')->execute([$id]);
}

function announcement_unpin(int $id): void
{
    db()->prepare('UPDATE announcements SET pinned = 0 WHERE id = ?')->execute([$id]);
}

function announcement_set_status(int $id, string $status): array
{
    if (!in_array($status, ['Draft', 'Published', 'Archived', 'Expired'], true)) {
        return [false, 'Unknown status.'];
    }

    db()->prepare('UPDATE announcements SET status = ? WHERE id = ?')->execute([$status, $id]);

    return [true, "Announcement moved to $status."];
}

function announcement_delete(int $id): array
{
    foreach (announcement_files($id) as $file) {
        $path = UPLOAD_DIR . '/announcements/' . $file['stored_name'];
        if (is_file($path)) {
            unlink($path);
        }
    }

    db()->prepare('DELETE FROM announcements WHERE id = ?')->execute([$id]);

    return [true, 'Announcement deleted.'];
}

function announcement_count_view(int $id): void
{
    if (announcements_ready()) {
        db()->prepare('UPDATE announcements SET views = views + 1 WHERE id = ?')->execute([$id]);
    }
}

function announcement_mark_read(int $id, int $userId): void
{
    db()->prepare(
        'INSERT INTO announcement_reads (announcement_id, user_id, read_at)
         VALUES (?, ?, NOW())
         ON DUPLICATE KEY UPDATE read_at = COALESCE(read_at, NOW())'
    )->execute([$id, $userId]);
}

function announcement_toggle_bookmark(int $id, int $userId): void
{
    db()->prepare(
        'INSERT INTO announcement_reads (announcement_id, user_id, bookmarked)
         VALUES (?, ?, 1)
         ON DUPLICATE KEY UPDATE bookmarked = 1 - bookmarked'
    )->execute([$id, $userId]);
}

function announcement_allowed_types(): array
{
    return ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'png', 'jpg', 'jpeg'];
}

function announcement_save_files(int $announcementId, array $upload): array
{
    $problems = [];
    $folder   = UPLOAD_DIR . '/announcements';

    if (!is_dir($folder)) {
        @mkdir($folder, 0775, true);
    }

    $maxBytes = 5 * 1024 * 1024;   
    $allowed  = announcement_allowed_types();

    foreach ($upload['name'] as $i => $originalName) {
        if (($upload['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            continue;
        }

        if ($upload['error'][$i] !== UPLOAD_ERR_OK) {
            $problems[] = "$originalName could not be uploaded.";
            continue;
        }

        if ($upload['size'][$i] > $maxBytes) {
            $problems[] = "$originalName is larger than 5 MB.";
            continue;
        }

        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        if (!in_array($extension, $allowed, true)) {
            $problems[] = "$originalName is not an allowed file type.";
            continue;
        }

        $storedName = bin2hex(random_bytes(16)) . '.' . $extension;

        if (!move_uploaded_file($upload['tmp_name'][$i], $folder . '/' . $storedName)) {
            $problems[] = "$originalName could not be saved.";
            continue;
        }

        db()->prepare(
            'INSERT INTO announcement_files (announcement_id, file_name, stored_name, size_bytes)
             VALUES (?, ?, ?, ?)'
        )->execute([$announcementId, $originalName, $storedName, (int) $upload['size'][$i]]);
    }

    return $problems;
}

function announcement_file_find(int $fileId): ?array
{
    $stmt = db()->prepare('SELECT * FROM announcement_files WHERE id = ?');
    $stmt->execute([$fileId]);
    $row = $stmt->fetch();

    return $row ?: null;
}

function announcement_clean_category(string $value): string
{
    return in_array($value, announcement_categories(), true) ? $value : 'Academic';
}

function announcement_clean_priority(string $value): string
{
    return in_array($value, announcement_priorities(), true) ? $value : 'Normal';
}

function announcement_clean_audience(string $value): string
{
    return in_array($value, ['Everyone', 'HoD', 'Coordinator', 'Faculty'], true) ? $value : 'Everyone';
}

function announcement_clean_datetime(string $value): ?string
{
    $value = trim($value);

    if ($value === '') {
        return null;
    }

    $time = strtotime($value);

    return $time === false ? null : date('Y-m-d H:i:s', $time);
}
