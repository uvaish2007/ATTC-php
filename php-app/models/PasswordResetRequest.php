<?php
/**
 * FEAT-11 — Self-service password reset REQUESTS (not resets).
 *
 * Someone who cannot sign in raises a ticket from the login page; the Admin
 * decides it on password-requests.php. The requester never sets a password,
 * and never learns whether the identifier they typed matches an account.
 *
 * Nothing here lets a requester write a password: completing a ticket goes
 * through the same password_hash() / users.password path the Users page
 * already uses, inside one transaction with the status update. The new
 * password is never stored in this table, in plaintext or otherwise.
 */

require_once __DIR__ . '/../inc/db.php';

if (!function_exists('auth_find_user')) {
    // The identifier resolver login already uses (email, username, alias), so
    // whatever signs a person in is what raises their ticket.
    require_once __DIR__ . '/../inc/auth.php';
}

/** Minimum password length — the same rule as user_change_password(). */
const PASSWORD_RESET_MIN_LENGTH = 6;

/**
 * Quiet window after a ticket is raised for one account. A second request
 * inside it is dropped, which is what keeps someone from filling the Admin's
 * queue by resubmitting. The requester sees the same reply either way, so the
 * limit reveals nothing.
 */
const PASSWORD_RESET_COOLDOWN_MINUTES = 15;

/**
 * The one reply an unauthenticated requester ever gets. It says nothing about
 * whether the identifier matched, so it must be shown for a real account, an
 * unknown one, a duplicate and a throttled attempt alike. Defined once so no
 * caller can drift into a wording that gives the difference away.
 */
const PASSWORD_RESET_GENERIC_REPLY =
    'If an account matches your details, a password reset request has been submitted to the Administrator.';

/** How many requests one browser session may send in an hour. */
const PASSWORD_RESET_MAX_PER_SESSION = 5;

/**
 * Per-session cap, which is what stops someone walking a list of identifiers
 * from the login page. Returns false once the cap is reached; the caller still
 * shows PASSWORD_RESET_GENERIC_REPLY, so being throttled is indistinguishable
 * from being accepted.
 */
function password_reset_request_session_allowed(): bool
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return true;   // no session to count in; the per-account checks still apply
    }

    $window = $_SESSION['pw_reset_attempts'] ?? [];
    $cutoff = time() - 3600;
    $window = array_values(array_filter($window, static fn($t) => (int) $t >= $cutoff));
    $_SESSION['pw_reset_attempts'] = $window;

    return count($window) < PASSWORD_RESET_MAX_PER_SESSION;
}

/** Count one attempt against the session cap above. */
function password_reset_request_session_record(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }
    $_SESSION['pw_reset_attempts'][] = time();
}

/** Statuses a ticket can hold, in the order the filter tabs show them. */
function password_reset_statuses(): array
{
    return ['Pending', 'Completed', 'Rejected'];
}

/**
 * Create the table on first use.
 *
 * sql/password_reset_requests.sql is the migration of record; this mirrors it
 * so a site that has not run the file yet still works, exactly as
 * edit_requests_table_init() does for the edit-request workflow.
 */
function password_reset_requests_table_init(): void
{
    static $initialised = false;
    if ($initialised) {
        return;
    }

    $sql = "CREATE TABLE IF NOT EXISTS `password_reset_requests` (
        `id`           INT AUTO_INCREMENT PRIMARY KEY,
        `user_id`      INT NOT NULL,
        `email`        VARCHAR(190) NOT NULL,
        `name`         VARCHAR(150) NOT NULL,
        `role`         VARCHAR(50)  NOT NULL,
        `department`   VARCHAR(150) NULL,
        `message`      TEXT NULL,
        `status`       ENUM('Pending','Completed','Rejected') NOT NULL DEFAULT 'Pending',
        `admin_notes`  TEXT NULL,
        `processed_by` INT NULL,
        `processed_at` DATETIME NULL,
        `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX `idx_status` (`status`),
        INDEX `idx_user_id` (`user_id`),
        INDEX `idx_created_at` (`created_at`),
        CONSTRAINT `fk_password_reset_user`
            FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
        CONSTRAINT `fk_password_reset_processor`
            FOREIGN KEY (`processed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    try {
        db()->exec($sql);
        $initialised = true;
    } catch (\PDOException $e) {
        error_log('password_reset_requests_table_init failed: ' . $e->getMessage());
    }
}

/**
 * Raise a ticket for whoever the identifier belongs to.
 *
 * The caller must show the SAME reply whichever result comes back, because
 * the difference between them is exactly the fact an unauthenticated visitor
 * is not allowed to learn. The second element is a short internal reason, for
 * the log — never for the page.
 *
 * Role, department, name and email are read from the users row. Nothing the
 * requester typed reaches those columns.
 *
 * @return array{0:bool,1:string}  [raised, internal reason]
 */
function password_reset_request_create(string $identifier, ?string $message): array
{
    password_reset_requests_table_init();

    $identifier = trim($identifier);
    if ($identifier === '') {
        return [false, 'empty_identifier'];
    }

    $message = trim((string) $message);
    if ($message === '') {
        $message = null;
    } elseif (mb_strlen($message) > 2000) {
        // Long enough for any genuine explanation; a cap keeps the column sane.
        $message = mb_substr($message, 0, 2000);
    }

    $user = auth_find_user($identifier);
    if (!$user) {
        return [false, 'no_match'];
    }

    // A ticket already waiting, or one raised moments ago, is not raised twice.
    if (password_reset_request_recently_raised((int) $user['id'])) {
        return [false, 'duplicate'];
    }

    try {
        $stmt = db()->prepare(
            "INSERT INTO `password_reset_requests`
                (`user_id`, `email`, `name`, `role`, `department`, `message`, `status`)
             VALUES (?, ?, ?, ?, ?, ?, 'Pending')"
        );
        $stmt->execute([
            (int) $user['id'],
            (string) $user['email'],
            (string) $user['name'],
            (string) $user['role'],
            trim((string) ($user['department'] ?? '')) !== '' ? $user['department'] : null,
            $message,
        ]);
    } catch (\PDOException $e) {
        error_log('password_reset_request_create failed: ' . $e->getMessage());
        return [false, 'db_error'];
    }

    return [true, 'created'];
}

/**
 * Is there already a Pending ticket for this account, or one raised inside the
 * cooldown? Either way a second one adds nothing.
 */
function password_reset_request_recently_raised(int $userId): bool
{
    password_reset_requests_table_init();

    try {
        $stmt = db()->prepare(
            "SELECT COUNT(*) FROM `password_reset_requests`
              WHERE `user_id` = ?
                AND (`status` = 'Pending'
                     OR `created_at` >= (NOW() - INTERVAL ? MINUTE))"
        );
        $stmt->execute([$userId, PASSWORD_RESET_COOLDOWN_MINUTES]);
        return ((int) $stmt->fetchColumn()) > 0;
    } catch (\PDOException $e) {
        // If the check itself fails, do not silently open the floodgates.
        error_log('password_reset_request_recently_raised failed: ' . $e->getMessage());
        return true;
    }
}

/**
 * Tickets for the Admin list.
 *
 * Filters: status ('' = every status), q (name / email / department), user_id,
 * limit. Everything is bound; nothing user-supplied is concatenated into SQL.
 */
function password_reset_requests_list(array $filters = []): array
{
    password_reset_requests_table_init();

    $sql = 'SELECT r.*, p.name AS processed_by_name
              FROM `password_reset_requests` r
              LEFT JOIN `users` p ON p.id = r.processed_by
             WHERE 1 = 1';
    $params = [];

    $status = trim((string) ($filters['status'] ?? ''));
    if ($status !== '' && in_array($status, password_reset_statuses(), true)) {
        $sql .= ' AND r.status = ?';
        $params[] = $status;
    }

    $q = trim((string) ($filters['q'] ?? ''));
    if ($q !== '') {
        $sql .= ' AND (r.name LIKE ? OR r.email LIKE ? OR r.department LIKE ?)';
        $like = '%' . $q . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }

    if (!empty($filters['user_id'])) {
        $sql .= ' AND r.user_id = ?';
        $params[] = (int) $filters['user_id'];
    }

    // Pending first — those are the ones still needing a decision.
    $sql .= " ORDER BY FIELD(r.status, 'Pending', 'Rejected', 'Completed'), r.created_at DESC";

    $limit = (int) ($filters['limit'] ?? 0);
    if ($limit > 0) {
        $sql .= ' LIMIT ' . $limit;   // cast to int above, so never user text
    }

    try {
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    } catch (\PDOException $e) {
        error_log('password_reset_requests_list failed: ' . $e->getMessage());
        return [];
    }
}

/** How many tickets are still waiting — for the nav badge and dashboard card. */
function password_reset_requests_pending_count(): int
{
    password_reset_requests_table_init();

    try {
        return (int) db()->query(
            "SELECT COUNT(*) FROM `password_reset_requests` WHERE `status` = 'Pending'"
        )->fetchColumn();
    } catch (\PDOException $e) {
        return 0;
    }
}

/** One ticket by id, or null. Bound parameter — the id is never trusted. */
function password_reset_request_find(int $id): ?array
{
    password_reset_requests_table_init();

    if ($id <= 0) {
        return null;
    }

    try {
        $stmt = db()->prepare(
            'SELECT r.*, p.name AS processed_by_name, u.status AS account_status
               FROM `password_reset_requests` r
               LEFT JOIN `users` p ON p.id = r.processed_by
               LEFT JOIN `users` u ON u.id = r.user_id
              WHERE r.id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    } catch (\PDOException $e) {
        error_log('password_reset_request_find failed: ' . $e->getMessage());
        return null;
    }
}

/** Counts per status, for the filter tabs. */
function password_reset_requests_counts(): array
{
    password_reset_requests_table_init();

    $counts = ['All' => 0, 'Pending' => 0, 'Completed' => 0, 'Rejected' => 0];

    try {
        foreach (db()->query('SELECT status, COUNT(*) AS c FROM `password_reset_requests` GROUP BY status') as $row) {
            $counts[$row['status']] = (int) $row['c'];
            $counts['All'] += (int) $row['c'];
        }
    } catch (\PDOException $e) {
        // Leave the zeroes.
    }

    return $counts;
}

/**
 * Decide one ticket. Admin-only — the CALLER gates the role; this function
 * still refuses anything that is not a Pending ticket, so a replayed or
 * guessed id cannot overturn a decision that was already made.
 *
 *   $action = 'complete'  set a new password on the user, mark Completed
 *   $action = 'reject'    leave the password alone, mark Rejected
 *
 * The password change and the status change are one transaction: the ticket
 * can never say Completed against a password that was not written.
 *
 * @return array{0:bool,1:string}  [ok, message for the Admin]
 */
function password_reset_request_process(
    int $id,
    string $action,
    ?string $newPassword,
    ?string $adminNotes,
    int $adminId
): array {
    password_reset_requests_table_init();

    $action = strtolower(trim($action));
    if (!in_array($action, ['complete', 'reject'], true)) {
        return [false, 'Unknown action.'];
    }

    $request = password_reset_request_find($id);
    if (!$request) {
        return [false, 'That password request no longer exists.'];
    }
    if ($request['status'] !== 'Pending') {
        return [false, 'That request was already ' . strtolower($request['status']) . '.'];
    }

    $adminNotes = trim((string) $adminNotes);
    if ($adminNotes === '') {
        $adminNotes = null;
    }

    if ($action === 'reject') {
        if ($adminNotes === null) {
            return [false, 'Give a reason for rejecting the request — the record is the only explanation the user has.'];
        }

        try {
            $stmt = db()->prepare(
                "UPDATE `password_reset_requests`
                    SET `status` = 'Rejected', `admin_notes` = ?, `processed_by` = ?, `processed_at` = NOW()
                  WHERE `id` = ? AND `status` = 'Pending'"
            );
            $stmt->execute([$adminNotes, $adminId, $id]);
            if ($stmt->rowCount() === 0) {
                return [false, 'That request was decided by someone else. Nothing was changed.'];
            }
        } catch (\PDOException $e) {
            error_log('password_reset_request_process (reject) failed: ' . $e->getMessage());
            return [false, 'The request could not be updated. Please try again.'];
        }

        return [true, 'Request rejected. The password was not changed.'];
    }

    /* ---- complete: write the new password ------------------------------- */

    $newPassword = (string) $newPassword;
    if (trim($newPassword) === '') {
        return [false, 'Enter the new password to set for this user.'];
    }
    if (strlen($newPassword) < PASSWORD_RESET_MIN_LENGTH) {
        return [false, 'The new password must be at least ' . PASSWORD_RESET_MIN_LENGTH . ' characters.'];
    }

    $targetId = (int) $request['user_id'];

    // Same hashing the Users page and the profile password change already use,
    // so the account keeps working with the existing password_verify() login.
    $hash = password_hash($newPassword, PASSWORD_BCRYPT);

    $pdo = db();
    try {
        $pdo->beginTransaction();

        // Make sure the account is still there before claiming anything.
        $check = $pdo->prepare('SELECT COUNT(*) FROM `users` WHERE `id` = ?');
        $check->execute([$targetId]);
        if ((int) $check->fetchColumn() === 0) {
            $pdo->rollBack();
            return [false, 'That user account no longer exists.'];
        }

        $stmt = $pdo->prepare('UPDATE `users` SET `password` = ? WHERE `id` = ?');
        $stmt->execute([$hash, $targetId]);

        $stmt = $pdo->prepare(
            "UPDATE `password_reset_requests`
                SET `status` = 'Completed', `admin_notes` = ?, `processed_by` = ?, `processed_at` = NOW()
              WHERE `id` = ? AND `status` = 'Pending'"
        );
        $stmt->execute([$adminNotes, $adminId, $id]);

        if ($stmt->rowCount() === 0) {
            // Someone decided it between the read above and here.
            $pdo->rollBack();
            return [false, 'That request was decided by someone else. Nothing was changed.'];
        }

        $pdo->commit();
    } catch (\PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('password_reset_request_process (complete) failed: ' . $e->getMessage());
        return [false, 'The password could not be changed. Nothing was saved.'];
    }

    return [true, 'Password changed for ' . $request['name'] . '. The request is marked Completed.'];
}
