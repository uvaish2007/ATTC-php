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
    return in_array($user['role'], ['Admin', 'Director', 'Principal'], true);
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
 * Automatically update database state for any published announcement whose expiry date
 * has passed. Expired announcements are kept safely in the database forever as
 * historical institutional records (never deleted).
 *
 * FEAT-12: before the original row is marked Expired, a complete copy of it is
 * written to announcements_archive — see announcement_archive_expired(), which
 * does the whole job in one transaction per announcement. The original row is
 * still only ever updated, never deleted, so announcement_files and
 * announcement_reads keep pointing at it.
 *
 * Returns the number of announcements that moved from Published to Expired.
 */
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

/* ==========================================================================
   FEAT-12 — Expired announcement archival
   ======================================================================= */

/**
 * Has sql/announcements_archive.sql been run yet?
 *
 * Everything below checks this first and degrades quietly when it is false,
 * exactly as announcements_ready() does for the main tables — so a database
 * that has not had the migration applied keeps working, just without the
 * archive.
 */
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

/**
 * The announcement columns the archive copies, in the order the INSERT below
 * uses them. Named once so the copy and the restore cannot drift apart.
 */
function announcement_archive_fields(): array
{
    return ['title', 'body', 'category', 'priority', 'audience', 'department',
            'pinned', 'publish_at', 'expires_at', 'require_read', 'views',
            'created_by', 'created_at', 'updated_at'];
}

/**
 * Copy ONE announcement into announcements_archive.
 *
 * Idempotent in both directions: the row is looked up first, and the UNIQUE
 * key on original_announcement_id is the backstop if two requests race, in
 * which case the duplicate is reported as "already archived" rather than
 * raised. Returns [ok, message, alreadyArchived].
 *
 * A notice that was archived, then restored, and has now expired again is
 * re-archived in place: the snapshot is refreshed and the restore metadata
 * cleared, because those columns describe the state of THIS archive record,
 * and it is archived once more. It is never a second row.
 *
 * The original announcement is not touched here — the caller decides that.
 */
function announcement_archive_one(int $announcementId): array
{
    if ($announcementId <= 0) {
        return [false, 'Invalid announcement id.', false];
    }
    if (!announcements_ready() || !announcements_archive_ready()) {
        return [false, 'The announcement archive table has not been created yet.', false];
    }

    try {
        // The author's name is snapshotted so the archive still reads properly
        // after the account is deleted (announcements.created_by goes NULL).
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

            // Restored, and now expired again: refresh the snapshot and put the
            // record back into the Archived state so it can be restored again.
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
        // 23000 is the duplicate-key class: another request archived the same
        // announcement between the SELECT above and the INSERT. That is the
        // outcome we wanted anyway, so it is success, not an error.
        if ($e->getCode() === '23000') {
            return [true, 'Already archived.', true];
        }

        error_log('announcement_archive_one failed for ' . $announcementId . ': ' . $e->getMessage());
        return [false, 'The announcement could not be archived.', false];
    }
}

/**
 * The archival sweep behind announcement_sync_expired().
 *
 * Two groups are picked up:
 *
 *   1. Published notices whose expires_at has passed — these are archived and
 *      then marked Expired.
 *   2. Notices already sitting at Expired with no archive row — notices that
 *      expired before this feature existed. They are archived where they are;
 *      their status is already correct, so it is not touched.
 *
 * Manually Archived notices (status = 'Archived') and Drafts are deliberately
 * left alone. Manual archiving is a different thing from expiry and stays that
 * way.
 *
 * Each announcement gets its own transaction: the archive copy is written
 * first and the original is only updated once that succeeded, so a failure
 * rolls that one announcement back and leaves it Published to be retried on
 * the next sweep. One bad row cannot stop the rest, and no announcement is
 * ever lost because archival failed.
 *
 * Returns ['expired' => n, 'archived' => n, 'failed' => n].
 */
function announcement_archive_expired(): array
{
    $result = ['expired' => 0, 'archived' => 0, 'failed' => 0];

    if (!announcements_ready()) {
        return $result;
    }

    // Without the archive table the old behaviour is kept exactly as it was,
    // so the portal still expires notices correctly before the migration runs.
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

/** How many announcements are held in the archive. */
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

/** Archive totals for the summary cards: total, still archived, restored. */
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

/**
 * The archive list, with search / category / department / archived-date range
 * / sort / paging applied.
 *
 * $filters keys (all optional):
 *   search, category, department, archived_from, archived_to,
 *   restore_status, sort, page, per_page
 *
 * Every value is bound as a parameter. `sort` never reaches SQL as text: it
 * only ever picks one of the fixed ORDER BY clauses below, so an unknown value
 * falls back to the default instead of being interpolated.
 *
 * Returns ['rows' => [...], 'total' => n, 'page' => n, 'pages' => n].
 */
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

    // Date range over archived_at. A bad date is dropped rather than guessed at.
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

/** One archive record by its archive_id, or null. Always a prepared lookup. */
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

/**
 * The attachments belonging to the archived announcements on one page.
 *
 * Attachments were never copied: announcement_files rows point at
 * announcements.id, and the original announcement is kept precisely so that
 * relationship survives. When the original row is gone its files went with it
 * (ON DELETE CASCADE), and this simply returns nothing for that id rather than
 * inventing a record of what used to be there.
 *
 * Returns [originalAnnouncementId => [fileRow, ...]].
 */
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

/**
 * Does this archive record need the Admin to choose a new expiry date before
 * it can be restored? True when it carried an expiry date that has passed.
 *
 * A record with no expiry at all does not: the announcements table allows a
 * published notice with expires_at NULL, and that is the existing convention
 * this follows rather than inventing a date for it.
 */
function announcement_archive_needs_expiry(array $archive): bool
{
    return !empty($archive['expires_at']) && strtotime((string) $archive['expires_at']) < time();
}

/**
 * Restore an archived announcement. Returns [ok, message].
 *
 * $newExpiresAt is the date the Admin chose in the restore dialog. It is
 * required, and must be in the future, whenever the archived expiry has
 * already passed — otherwise the notice would be published in the past and
 * the very next expiry sweep would put it straight back to Expired. The
 * archived date is never silently changed: either it is still valid and is
 * reused as it is, or the Admin supplies a new one.
 *
 * Two cases, both handled:
 *
 *   A. The original announcement row still exists — it is brought back to
 *      Published with the agreed expiry. No duplicate is created, and the
 *      live title/body are left as they are so an edit made since expiry is
 *      not silently overwritten by the snapshot.
 *
 *   B. The original row was deleted — a new announcement is created from the
 *      archived data and receives a new AUTO_INCREMENT id. The old id is not
 *      forced; it stays recorded in original_announcement_id.
 *
 * The archive record itself is NEVER deleted. It is marked Restored, which is
 * what refuses a second restore.
 */
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
        // The archived date is still valid, but the Admin supplied one anyway.
        // Honour it, as long as it is a real future date.
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

        // Claim the archive record FIRST. If two Admins press Restore at the
        // same moment, only one of these updates matches a row, so only one
        // goes on to touch the announcements table.
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
            // --- CASE A: bring the existing row back to life -------------
            $pdo->prepare(
                "UPDATE announcements
                    SET status = 'Published', expires_at = ?, pinned = 0
                  WHERE id = ?"
            )->execute([$expiresAt, $liveId]);

            $restoredId = $liveId;
            $note       = 'Announcement #' . $restoredId . ' restored and published again.';

        } else {
            // --- CASE B: rebuild it from the archived data ---------------
            // A new AUTO_INCREMENT id is issued; the old one stays recorded in
            // original_announcement_id. pinned is deliberately not restored —
            // a notice coming back out of the archive should not silently take
            // the pin from whatever is pinned today.
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

    $dept = (string) ($user['department'] ?? '');
    $sql = "a.status = 'Published'
            AND (a.publish_at IS NULL OR a.publish_at <= NOW())
            AND (a.expires_at IS NULL OR a.expires_at >= NOW())
            AND (a.audience = 'Everyone' OR a.audience = ? OR a.created_by = ?)
            AND (a.department IS NULL OR a.department = '' OR a.department = ? OR REPLACE(a.department, ' ', '') = REPLACE(?, ' ', ''))";

    return [$sql, [$user['role'], (int)$user['id'], $dept, $dept]];
}

/**
 * A short word for the state a notice is in, used for the little grey badge:
 * Draft, Scheduled, Expired, Archived or Active.
 */
function announcement_state(array $row): string
{
    if ($row['status'] === 'Draft')    return 'Draft';
    if ($row['status'] === 'Archived') return 'Archived';
    if ($row['status'] === 'Expired')  return 'Expired';

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

    // Automatically synchronize any expired announcements and save state in database
    announcement_sync_expired();

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

    // --- Calendar month & date filtering ---
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
        // Past expired notices safely stored and preserved in database
        $where[] = "(a.status = 'Expired' OR (a.expires_at IS NOT NULL AND a.expires_at < NOW()))";

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
        // The normal list based on calendar month & date:
        if ($targetDay > 0) {
            // Specific day in the month:
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
            // Present month: show published/active notices that belong to this month or are active
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
            // Past month: show notices created, published, or with deadlines in that past month (both Published & Expired)
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

    // Expired notices safely preserved in database
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

    $days = [];

    // Deadlines in this month
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

    // Also mark announcements created in this month
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
    if (!in_array($status, ['Draft', 'Published', 'Archived', 'Expired'], true)) {
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
