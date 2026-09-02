<?php
/**
 * Announcement Centre — notices published by the Director / Admin.
 *
 * Everything a page needs lives here: reading the list with its filters,
 * writing a notice, attaching files, and the read receipts that make the
 * "who has seen this?" analytics possible.
 *
 * The tables come from sql/announcements.sql. If that file has not been run
 * yet, announcements_ready() returns false and every page degrades quietly
 * instead of crashing.
 */

require_once __DIR__ . '/../inc/db.php';

/** The kinds of notice the college publishes. */
function announcement_categories(): array
{
    return ['Academic', 'Examination', 'IQAC', 'Placement', 'Administration', 'Events', 'Circular', 'Research'];
}

function announcement_priorities(): array
{
    return ['Normal', 'Important', 'Urgent'];
}

/** Who is allowed to publish, edit, pin, archive and see the analytics. */
function announcement_can_manage(array $user): bool
{
    return in_array($user['role'], ['Admin', 'Director'], true);
}

/**
 * Have the announcement tables been created yet?
 * Checked once per request, then remembered.
 */
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

/**
 * The WHERE clause that decides what this person may see.
 *
 * Admin and Director see everything, including drafts and archived notices.
 * Everybody else only sees a notice that is published, inside its publish /
 * expiry window, aimed at their role, and either college-wide or addressed to
 * their own department.
 *
 * Returns [sqlFragment, params].
 */
function announcement_visibility(array $user): array
{
    if (announcement_can_manage($user)) {
        return ['1=1', []];
    }

    $sql = "a.status = 'Published'
            AND (a.publish_at IS NULL OR a.publish_at <= NOW())
            AND (a.expires_at IS NULL OR a.expires_at >= NOW())
            AND (a.audience = 'Everyone' OR a.audience = ?)
            AND (a.department IS NULL OR a.department = '' OR a.department = ?)";

    return [$sql, [$user['role'], (string) ($user['department'] ?? '')]];
}

/**
 * A short word for the state a notice is in, used for the little grey badge:
 * Draft, Scheduled, Expired, Archived or Active.
 */
function announcement_state(array $row): string
{
    if ($row['status'] === 'Draft')    return 'Draft';
    if ($row['status'] === 'Archived') return 'Archived';

    if ($row['publish_at'] && strtotime($row['publish_at']) > time()) return 'Scheduled';
    if ($row['expires_at'] && strtotime($row['expires_at']) < time()) return 'Expired';

    return 'Active';
}

/**
 * The list, with search / category / sort / paging applied.
 *
 * $filters keys (all optional):
 *   search, category, sort (newest|oldest|viewed|unread), scope
 *   (all|bookmarked|archived|mine), page, per_page
 *
 * Returns ['rows' => [...], 'total' => n, 'page' => n, 'pages' => n].
 */
function announcements_list(array $user, array $filters = []): array
{
    if (!announcements_ready()) {
        return ['rows' => [], 'total' => 0, 'page' => 1, 'pages' => 1];
    }

    [$visible, $params] = announcement_visibility($user);

    $where  = [$visible];
    $userId = (int) $user['id'];

    // --- the filters the page offers ---
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

    $scope = (string) ($filters['scope'] ?? 'all');
    if ($scope === 'archived') {
        $where[] = "a.status = 'Archived'";

    } elseif ($scope === 'mine') {
        $where[]  = 'a.created_by = ?';
        $params[] = $userId;

    } elseif ($scope === 'bookmarked') {
        // "Has this person bookmarked it?" asked as a condition, so the count
        // query below can reuse exactly the same WHERE clause.
        $where[]  = 'EXISTS (SELECT 1 FROM announcement_reads rb
                             WHERE rb.announcement_id = a.id AND rb.user_id = ? AND rb.bookmarked = 1)';
        $params[] = $userId;

    } else {
        // The normal list keeps archived notices out of the way.
        $where[] = "a.status <> 'Archived'";
    }

    $whereSql = implode(' AND ', $where);

    // The three per-person numbers each row carries. They go in as parameters
    // ahead of the WHERE ones, because they appear earlier in the statement.
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

    // --- how to order them ---
    $order = [
        'oldest' => 'a.pinned DESC, a.created_at ASC',
        'viewed' => 'a.pinned DESC, a.views DESC, a.created_at DESC',
        'unread' => 'a.pinned DESC, is_read ASC, a.created_at DESC',
    ][$filters['sort'] ?? 'newest'] ?? 'a.pinned DESC, a.created_at DESC';

    // --- paging ---
    $perPage = max(1, (int) ($filters['per_page'] ?? 8));
    $page    = max(1, (int) ($filters['page'] ?? 1));

    // Count first, so we know how many pages there are.
    $countStmt = db()->prepare("SELECT COUNT(*) FROM announcements a WHERE $whereSql");
    $countStmt->execute($params);

    $total = (int) $countStmt->fetchColumn();
    $pages = max(1, (int) ceil($total / $perPage));
    $page  = min($page, $pages);
    $offset = ($page - 1) * $perPage;

    // LIMIT / OFFSET are cast to int rather than bound, which MySQL prefers.
    $stmt = db()->prepare("$select ORDER BY $order LIMIT $perPage OFFSET $offset");
    $stmt->execute(array_merge($rowParams, $params));

    $rows = $stmt->fetchAll();
    foreach ($rows as $i => $row) {
        $rows[$i]['_state'] = announcement_state($row);
    }

    return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages];
}

/** One announcement the user is allowed to see, or null. */
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

/** The documents attached to a notice. */
function announcement_files(int $id): array
{
    if (!announcements_ready()) {
        return [];
    }

    $stmt = db()->prepare('SELECT * FROM announcement_files WHERE announcement_id = ? ORDER BY id');
    $stmt->execute([$id]);

    return $stmt->fetchAll();
}

/** The newest attachments across all notices (for the sidebar). */
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

/**
 * How many people a notice is addressed to. Used as the denominator of the
 * read percentage. Only active accounts count.
 */
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

/**
 * Read analytics for one notice: how many of its audience have opened it,
 * the split by department, and who still has not.
 */
function announcement_analytics(int $id, array $announcement): array
{
    $audience = announcement_audience_size($announcement);

    // Everyone the notice is aimed at, with their read time if they have one.
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

/** The four numbers on the summary cards. */
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

    // Closing within the next seven days.
    $stmt = db()->prepare(
        "SELECT COUNT(*) FROM announcements a
         WHERE ($visible) AND $live
           AND a.expires_at IS NOT NULL
           AND a.expires_at <= DATE_ADD(NOW(), INTERVAL 7 DAY)"
    );
    $stmt->execute($params);
    $expiring = (int) $stmt->fetchColumn();

    return [
        'total'    => $total,
        'active'   => $active,
        'expiring' => $expiring,
        'unread'   => announcement_can_manage($user)
            ? unread_receipts_count()
            : unread_announcements_count($user),
    ];
}

/**
 * How many faculty × notice pairs are still unread. This is the number the
 * Director cares about: every faculty member who has not opened a live notice.
 */
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

/** How many live notices this person has not opened yet (the sidebar badge). */
function unread_announcements_count(array $user): int
{
    if (!announcements_ready()) {
        return 0;
    }

    // Managers see their own unread count the same way everyone else does.
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

/** Notices with a deadline still to come (the sidebar list). */
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

/** Every deadline day in one month, for the little calendar. */
function announcement_calendar(array $user, int $year, int $month): array
{
    if (!announcements_ready()) {
        return [];
    }

    [$visible, $params] = announcement_visibility($user);

    $stmt = db()->prepare(
        "SELECT DAY(a.expires_at) AS day, COUNT(*) AS n
         FROM announcements a
         WHERE ($visible)
           AND a.expires_at IS NOT NULL
           AND YEAR(a.expires_at) = ? AND MONTH(a.expires_at) = ?
         GROUP BY DAY(a.expires_at)"
    );
    $stmt->execute(array_merge($params, [$year, $month]));

    $days = [];
    foreach ($stmt as $row) {
        $days[(int) $row['day']] = (int) $row['n'];
    }

    return $days;
}


/* ==========================================================================
   Writing
   ======================================================================= */

/**
 * Create a notice. $fields uses the same names as the form.
 * Returns [ok, message, newId].
 */
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

/** Edit a notice. Returns [ok, message]. */
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
        in_array($fields['status'] ?? '', ['Draft', 'Published', 'Archived'], true) ? $fields['status'] : 'Published',
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

/** Make this the pinned notice, and unpin whatever was pinned before. */
function announcement_pin(int $id): void
{
    db()->prepare('UPDATE announcements SET pinned = 0 WHERE id <> ?')->execute([$id]);
    db()->prepare('UPDATE announcements SET pinned = 1 WHERE id = ?')->execute([$id]);
}

function announcement_unpin(int $id): void
{
    db()->prepare('UPDATE announcements SET pinned = 0 WHERE id = ?')->execute([$id]);
}

/** Move a notice to Draft / Published / Archived. */
function announcement_set_status(int $id, string $status): array
{
    if (!in_array($status, ['Draft', 'Published', 'Archived'], true)) {
        return [false, 'Unknown status.'];
    }

    db()->prepare('UPDATE announcements SET status = ? WHERE id = ?')->execute([$status, $id]);

    return [true, "Announcement moved to $status."];
}

/** Delete a notice. Its files and read receipts go with it. */
function announcement_delete(int $id): array
{
    // Take the uploaded files off the disk too, not just out of the table.
    foreach (announcement_files($id) as $file) {
        $path = UPLOAD_DIR . '/announcements/' . $file['stored_name'];
        if (is_file($path)) {
            unlink($path);
        }
    }

    db()->prepare('DELETE FROM announcements WHERE id = ?')->execute([$id]);

    return [true, 'Announcement deleted.'];
}

/** Count one more view of a notice. */
function announcement_count_view(int $id): void
{
    if (announcements_ready()) {
        db()->prepare('UPDATE announcements SET views = views + 1 WHERE id = ?')->execute([$id]);
    }
}

/** Record that someone has read a notice (once only). */
function announcement_mark_read(int $id, int $userId): void
{
    db()->prepare(
        'INSERT INTO announcement_reads (announcement_id, user_id, read_at)
         VALUES (?, ?, NOW())
         ON DUPLICATE KEY UPDATE read_at = COALESCE(read_at, NOW())'
    )->execute([$id, $userId]);
}

/** Turn a bookmark on or off. */
function announcement_toggle_bookmark(int $id, int $userId): void
{
    db()->prepare(
        'INSERT INTO announcement_reads (announcement_id, user_id, bookmarked)
         VALUES (?, ?, 1)
         ON DUPLICATE KEY UPDATE bookmarked = 1 - bookmarked'
    )->execute([$id, $userId]);
}


/* ==========================================================================
   Attachments
   ======================================================================= */

/** Only these file types may be attached. */
function announcement_allowed_types(): array
{
    return ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'png', 'jpg', 'jpeg'];
}

/**
 * Save the files that came with the form.
 *
 * The name on disk is random, so a file called "../evil.php" cannot escape the
 * uploads folder or be executed. The original name is kept in the table and
 * used when the file is downloaded again.
 *
 * Returns a list of problems (empty when everything saved).
 */
function announcement_save_files(int $announcementId, array $upload): array
{
    $problems = [];
    $folder   = UPLOAD_DIR . '/announcements';

    if (!is_dir($folder)) {
        @mkdir($folder, 0775, true);
    }

    $maxBytes = 5 * 1024 * 1024;   // 5 MB per file
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

/** One attachment row by id, or null. */
function announcement_file_find(int $fileId): ?array
{
    $stmt = db()->prepare('SELECT * FROM announcement_files WHERE id = ?');
    $stmt->execute([$fileId]);
    $row = $stmt->fetch();

    return $row ?: null;
}


/* ==========================================================================
   Small tidying helpers, so bad form input never reaches the database
   ======================================================================= */

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

/** Turn a browser datetime-local value into something MySQL accepts, or null. */
function announcement_clean_datetime(string $value): ?string
{
    $value = trim($value);

    if ($value === '') {
        return null;
    }

    $time = strtotime($value);

    return $time === false ? null : date('Y-m-d H:i:s', $time);
}
