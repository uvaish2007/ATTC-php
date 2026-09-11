<?php
/**
 * Target data access, and the review workflow a target moves through.
 *
 * A HoD writes a target and sends it up. A Director or Admin either approves
 * it — which freezes it — or sends it back with a remark for the HoD to revise
 * and resubmit. A frozen target is the agreed number: from then on only an
 * Admin can change it.
 *
 *     Draft ──submit──▶ Pending Review ──approve──▶ Approved  (frozen)
 *       ▲                     │
 *       │                  send back
 *       │                     ▼
 *       └───edit──── Changes Requested
 *
 * An Admin's own target skips the queue: they are the final authority, so it is
 * approved and frozen the moment it is created.
 *
 * Every rule lives in the four target_can_*() predicates below, and each write
 * re-checks the one that guards it. The page uses the same predicates to decide
 * which buttons to draw, so what you can see is exactly what you can do — and a
 * forged POST still cannot move a target its sender is not allowed to move.
 */

require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/Setting.php';

/* ==========================================================================
   Timed unlock permits
   Once a department's targets are locked (Approved), a HoD asks for an unlock
   with a reason; the Admin grants it, opening a timed edit window. There is no
   background job — the window is simply "unlocked_until > now", checked on
   every request, and the dashboard counts down to that same instant.
   ========================================================================= */

/**
 * Request-scoped memo for unlock_active(), keyed by department.
 *
 * target_can_edit() calls unlock_active() once for every Approved target drawn
 * on the Targets page, and each call is the same query for the same department.
 * Without this, a department with dozens of approved targets fires dozens of
 * identical round-trips to the (remote) database and the page can exceed the
 * PHP time limit. Any write that could change the answer clears this.
 */
$GLOBALS['_unlock_active_cache'] = [];

/** Forget the memoised unlock_active() answers (call after an unlock write). */
function unlock_active_cache_clear(): void
{
    $GLOBALS['_unlock_active_cache'] = [];
}

/** The default edit-window length in hours (Admin-configurable). */
function unlock_default_hours(): int
{
    return max(1, (int) setting_get('unlock_hours', '12'));
}

/** Mark any window whose time has run out as Expired (lazy, cosmetic). */
function unlock_expire_due(): void
{
    db()->exec("UPDATE unlock_requests SET status='Expired' WHERE status='Granted' AND unlocked_until <= NOW()");
    unlock_active_cache_clear();
}

/** The department's live unlock window, if one is open right now. */
function unlock_active(?string $department): ?array
{
    if (!$department) {
        return null;
    }
    if (array_key_exists($department, $GLOBALS['_unlock_active_cache'])) {
        return $GLOBALS['_unlock_active_cache'][$department];
    }
    $stmt = db()->prepare(
        "SELECT * FROM unlock_requests
          WHERE department = ? AND status = 'Granted' AND unlocked_until > NOW()
          ORDER BY unlocked_until DESC LIMIT 1"
    );
    $stmt->execute([$department]);
    return $GLOBALS['_unlock_active_cache'][$department] = ($stmt->fetch() ?: null);
}

/** A pending (awaiting-Admin) request for a department, if any. */
function unlock_pending_for(?string $department): ?array
{
    if (!$department) {
        return null;
    }
    $stmt = db()->prepare("SELECT * FROM unlock_requests WHERE department = ? AND status = 'Requested' ORDER BY id DESC LIMIT 1");
    $stmt->execute([$department]);
    return $stmt->fetch() ?: null;
}

/**
 * A department's unlock state for the UI.
 *   state: 'locked' | 'requested' | 'unlocked'
 *   until: epoch seconds the window closes (when unlocked)
 */
function unlock_state(?string $department): array
{
    $active = unlock_active($department);
    if ($active) {
        return [
            'state'  => 'unlocked',
            'active' => $active,
            'pending'=> null,
            'until'  => strtotime($active['unlocked_until']),
            'hours'  => (int) $active['hours'],
        ];
    }
    $pending = unlock_pending_for($department);
    return [
        'state'   => $pending ? 'requested' : 'locked',
        'active'  => null,
        'pending' => $pending,
        'until'   => null,
        'hours'   => 0,
    ];
}

/** HoD asks to unlock their department's locked targets. */
function unlock_request(?string $department, int $userId, string $reason): array
{
    if (!$department) {
        return [false, 'No department to unlock.'];
    }
    if (unlock_active($department)) {
        return [false, 'The targets are already unlocked.'];
    }
    if (unlock_pending_for($department)) {
        return [false, 'An unlock request is already awaiting the Admin.'];
    }
    $reason = trim($reason);
    if ($reason === '') {
        return [false, 'Give a reason for the unlock request.'];
    }

    $stmt = db()->prepare("INSERT INTO unlock_requests (department, requested_by, reason, status) VALUES (?,?,?,'Requested')");
    $stmt->execute([$department, $userId, $reason]);
    unlock_active_cache_clear();
    return [true, 'Unlock request sent to the Admin.'];
}

/** Admin grants a request, opening the timed window. */
function unlock_grant(int $id, int $adminId, int $hours): array
{
    $stmt = db()->prepare("SELECT * FROM unlock_requests WHERE id = ? AND status = 'Requested'");
    $stmt->execute([$id]);
    if (!$stmt->fetch()) {
        return [false, 'That request is no longer pending.'];
    }

    $hours = max(1, min(720, $hours));
    setting_set('unlock_hours', (string) $hours, $adminId);   // remember as the new default

    $upd = db()->prepare(
        "UPDATE unlock_requests
            SET status='Granted', hours=?, granted_by=?, granted_at=NOW(),
                unlocked_until = DATE_ADD(NOW(), INTERVAL ? HOUR)
          WHERE id = ?"
    );
    $upd->execute([$hours, $adminId, $hours, $id]);
    unlock_active_cache_clear();
    return [true, "Unlocked for {$hours}h — the HoD can edit until the timer ends."];
}

/** Admin denies a request. */
function unlock_deny(int $id, int $adminId, string $note = ''): array
{
    $stmt = db()->prepare("UPDATE unlock_requests SET status='Denied', granted_by=?, admin_note=? WHERE id = ? AND status='Requested'");
    $stmt->execute([$adminId, trim($note) ?: null, $id]);
    unlock_active_cache_clear();
    return [true, 'Unlock request denied.'];
}

/** All pending requests, for the Admin's queue. */
function unlock_pending_all(): array
{
    return db()->query(
        "SELECT u.*, r.name AS requester_name
           FROM unlock_requests u LEFT JOIN users r ON r.id = u.requested_by
          WHERE u.status = 'Requested' ORDER BY u.created_at"
    )->fetchAll();
}

/** How many requests are waiting, for the nav badge. */
function unlock_pending_count(): int
{
    return (int) db()->query("SELECT COUNT(*) FROM unlock_requests WHERE status='Requested'")->fetchColumn();
}

/** The states a target can be in, in the order it travels through them. */
function target_statuses(): array
{
    return ['Draft', 'Dean Pending', 'Changes Requested', 'Approved'];
}

/**
 * Badge colour for a workflow state. Deliberately not status_class(): those are
 * record-review colours (Submitted/Rejected), and these are different words for
 * a different flow — reusing that map would have "Changes Requested" fall
 * through to grey.
 */
function target_status_class(string $status): string
{
    $map = [
        'Draft'             => 'neutral',
        'Dean Pending'      => 'info',
        'Pending Review'    => 'info',
        'Changes Requested' => 'warning',
        'Approved'          => 'success',
    ];

    return $map[$status] ?? 'neutral';
}

/** A frozen target is settled: the numbers are the institution's commitment. */
function target_is_frozen(array $target): bool
{
    return ($target['status'] ?? '') === 'Approved';
}

/** The HoD's own department, or null for anyone not scoped to one. */
function target_owns(array $target, array $user): bool
{
    if (in_array($user['role'], ['Admin', 'Director', 'Dean'], true)) {
        return true;
    }
    return ($user['department'] ?? null) !== null
        && ($target['department'] ?? null) === $user['department'];
}

/**
 * May this user change the target's numbers?
 *
 * Admin always, including after it is frozen — that is the escape hatch when a
 * settled figure genuinely has to move. A HoD only while it is still theirs to
 * write: their own department, and not currently under review or frozen.
 */
function target_can_edit(array $target, array $user): bool
{
    if ($user['role'] === 'Admin') {
        return true;
    }
    if (!in_array($user['role'], ['HoD', 'Dean'], true) || !target_owns($target, $user)) {
        return false;
    }

    $status = $target['status'] ?? 'Draft';

    // Still theirs to write before it is locked...
    if (in_array($status, ['Draft', 'Changes Requested'], true)) {
        return true;
    }

    // ...or a locked target while an unlock window is open and unexpired.
    $dept = $target['department'] ?? ($user['department'] ?? null);
    return $status === 'Approved' && unlock_active($dept) !== null;
}

/** May this user send it up for review? Only the HoD who owns it. */
function target_can_submit(array $target, array $user): bool
{
    return in_array($user['role'], ['HoD', 'Dean'], true)
        && target_owns($target, $user)
        && in_array($target['status'] ?? 'Draft', ['Draft', 'Changes Requested'], true);
}

/** May this user approve it or send it back? Only while it is waiting. */
function target_can_review(array $target, array $user): bool
{
    return in_array($user['role'], ['Admin', 'Director', 'Dean'], true)
        && in_array($target['status'] ?? '', ['Dean Pending', 'Pending Review'], true);
}

/** May this user delete it? A frozen target is Admin-only. */
function target_can_delete(array $target, array $user): bool
{
    if ($user['role'] === 'Admin') {
        return true;
    }

    return in_array($user['role'], ['HoD', 'Dean'], true)
        && target_owns($target, $user)
        && !target_is_frozen($target);
}

/**
 * Targets, newest first, optionally narrowed by department, year, status or metric.
 */
function targets_all(?string $department = null, ?string $year = null, ?string $status = null, ?string $metric = null, bool $excludeDrafts = false): array
{
    $sql = 'SELECT t.*, u.name AS creator_name, a.name AS approver_name
              FROM targets t
              LEFT JOIN users u ON t.created_by  = u.id
              LEFT JOIN users a ON t.approved_by = a.id
             WHERE 1=1';
    $params = [];

    if ($department) {
        $sql .= ' AND t.department = ?';
        $params[] = $department;
    }
    if ($year) {
        $sql .= ' AND t.academic_year = ?';
        $params[] = $year;
    }
    if ($status) {
        if ($status === 'Dean Pending' || $status === 'Pending Review') {
            $sql .= " AND t.status IN ('Dean Pending', 'Pending Review')";
        } else {
            $sql .= ' AND t.status = ?';
            $params[] = $status;
        }
    }
    if ($excludeDrafts) {
        $sql .= " AND t.status != 'Draft'";
    }
    if ($metric) {
        $sql .= ' AND t.metric = ?';
        $params[] = $metric;
    }

    $sql .= ' ORDER BY (t.sort_order IS NULL OR t.sort_order = 0), t.sort_order ASC, t.id ASC';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Targets for the Executive Meeting Report, in proforma order.
 *
 * Ordered by sort_order (the hand-set sequence of the printed proforma, with
 * its lettered sub-items) and then id, so seeded rows keep their exact order
 * and any form-added target falls in after them.
 */
function target_report_items(?string $department = null, ?string $year = null): array
{
    $sql    = 'SELECT * FROM targets WHERE 1=1';
    $params = [];

    if ($department) {
        $sql .= ' AND department = ?';
        $params[] = $department;
    }
    if ($year) {
        $sql .= ' AND academic_year = ?';
        $params[] = $year;
    }

    $sql .= ' ORDER BY id ASC';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function target_find(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM targets WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * How many targets are sitting in the review queue, for the nav badge.
 * Scoped to a year (the active one, from every caller) so the badge never
 * counts a different year's leftover pending targets.
 */
function targets_pending_count(?string $year = null): int
{
    if ($year !== null) {
        $stmt = db()->prepare("SELECT COUNT(*) FROM targets WHERE status IN ('Dean Pending', 'Pending Review') AND academic_year = ?");
        $stmt->execute([$year]);
        return (int) $stmt->fetchColumn();
    }
    $stmt = db()->query("SELECT COUNT(*) FROM targets WHERE status IN ('Dean Pending', 'Pending Review')");
    return (int) $stmt->fetchColumn();
}

/**
 * Create a target.
 *
 * An Admin's target is frozen straight away; a HoD's starts as a Draft they
 * still have to send up. A HoD's department is taken from their account, never
 * from the form.
 */
function target_create(array $user, string $department, string $academicYear, string $metric, int $targetValue, ?string $remarks, ?string $coordinator = null, string $status = 'Draft', ?string $targetDeadline = null): array
{
    if (!in_array($user['role'], ['HoD', 'Dean'], true)) {
        return [false, 'Only a HoD or Dean enters targets.'];
    }

    // A new target always lands in the system's active academic year —
    // regardless of what a caller passes in $academicYear (a page filter, a
    // CSV import column, …). This is the one enforcement point for every
    // caller, present and future; see active_academic_year().
    $academicYear = active_academic_year();

    if ($user['role'] === 'HoD') {
        $department = (string) ($user['department'] ?? '');
    } else {
        $department = trim($department) ?: ((string) ($user['department'] ?? '') ?: 'CSE');
    }

    // Metric is free text (any target title), so only emptiness is invalid.
    $metric = trim($metric);
    if ($department === '' || $metric === '') {
        return [false, 'Department and target are required.'];
    }
    if ($targetValue < 0) {
        return [false, 'A target cannot be negative.'];
    }

    $isPending = in_array($status, ['Pending Review', 'Dean Pending'], true);
    $statusVal = $isPending ? 'Dean Pending' : 'Draft';
    $submittedAt = $isPending ? date('Y-m-d H:i:s') : null;

    $targetDeadline = !empty(trim((string) $targetDeadline)) ? trim((string) $targetDeadline) : null;
    if ($targetDeadline !== null) {
        $d = DateTime::createFromFormat('Y-m-d', $targetDeadline);
        if (!$d || $d->format('Y-m-d') !== $targetDeadline) {
            $targetDeadline = null;
        }
    }

    $stmt = db()->prepare(
        'INSERT INTO targets (department, academic_year, metric, target_value, target_deadline, remarks, coordinator, status, submitted_at, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $department, $academicYear, $metric, $targetValue, $targetDeadline, $remarks ?: null, $coordinator ?: null,
        $statusVal, $submittedAt, $user['id'],
    ]);

    if ($isPending) {
        return [true, 'Target created and submitted for Dean review.'];
    }

    return [true, 'Target saved as a draft. Send it for review when it is ready.'];
}

/**
 * Update a target's numbers.
 *
 * A frozen target stays frozen when an Admin edits it, but the approval stamp
 * is rewritten so the record always shows who last set the figure. A HoD can
 * never move a target into another department.
 */
function target_update(int $id, array $user, string $department, string $academicYear, string $metric, int $targetValue, int $achievedValue, ?string $remarks, ?string $coordinator = null, ?string $targetDeadline = null, ?string $fixedText = null): array
{
    $existing = target_find($id);
    if (!$existing) {
        return [false, 'Target not found.'];
    }
    if (!target_can_edit($existing, $user)) {
        return [false, target_is_frozen($existing)
            ? 'That target is frozen. Only an Admin can change it now.'
            : 'That target is not yours to edit.'];
    }
    $metric = trim($metric);
    if ($metric === '') {
        return [false, 'The target title is required.'];
    }
    if ($targetValue < 0 || $achievedValue < 0) {
        return [false, 'Values cannot be negative.'];
    }

    if ($user['role'] !== 'Admin') {
        $department   = (string) $existing['department'];      // pinned to where it already is
        $academicYear = (string) $existing['academic_year'];   // a HoD/Dean cannot move a target to another year
    }

    $frozen = target_is_frozen($existing);

    $targetDeadline = !empty(trim((string) $targetDeadline)) ? trim((string) $targetDeadline) : null;
    if ($targetDeadline !== null) {
        $d = DateTime::createFromFormat('Y-m-d', $targetDeadline);
        if (!$d || $d->format('Y-m-d') !== $targetDeadline) {
            $targetDeadline = null;
        }
    }

    $sql  = 'UPDATE targets SET department = ?, academic_year = ?, metric = ?, target_value = ?, target_deadline = ?, achieved_value = ?, remarks = ?, coordinator = ?';
    $args = [$department, $academicYear, $metric, $targetValue, $targetDeadline, $achievedValue, $remarks ?: null, $coordinator ?: null];

    if ($fixedText !== null) {
        $sql .= ', fixed_text = ?';
        $args[] = trim($fixedText);
    }

    // Re-stamp the approval only when an Admin edits a frozen target — a HoD
    // editing inside an unlock window is not re-approving it, so the original
    // approver and date stand.
    if ($frozen && $user['role'] === 'Admin') {
        $sql .= ', approved_by = ?, approved_at = ?';
        $args[] = $user['id'];
        $args[] = date('Y-m-d H:i:s');
    }

    $sql   .= ' WHERE id = ?';
    $args[] = $id;

    db()->prepare($sql)->execute($args);

    return [true, $frozen ? 'Frozen target updated.' : 'Target updated.'];
}

/** Send a draft (or a sent-back target) up for review. */
function target_submit(int $id, array $user): array
{
    $existing = target_find($id);
    if (!$existing) {
        return [false, 'Target not found.'];
    }
    if (!target_can_submit($existing, $user)) {
        return [false, 'That target cannot be sent for review.'];
    }

    $stmt = db()->prepare("UPDATE targets SET status = 'Dean Pending', submitted_at = ? WHERE id = ?");
    $stmt->execute([date('Y-m-d H:i:s'), $id]);

    return [true, 'Target submitted for Dean review.'];
}

/**
 * Approve a target (freezing it) or send it back for changes.
 *
 * Sending one back needs a reason — a bare rejection tells the HoD nothing
 * about what to fix.
 */
function target_review(int $id, array $user, string $decision, ?string $remark): array
{
    $existing = target_find($id);
    if (!$existing) {
        return [false, 'Target not found.'];
    }
    if (!target_can_review($existing, $user)) {
        return [false, 'That target is not waiting for your review.'];
    }

    if ($decision === 'approve') {
        $stmt = db()->prepare("UPDATE targets SET status = 'Approved', approved_by = ?, approved_at = ?, review_remark = ? WHERE id = ?");
        $stmt->execute([$user['id'], date('Y-m-d H:i:s'), $remark ?: null, $id]);
        return [true, 'Target approved and frozen.'];
    }

    if ($decision === 'changes' || $decision === 'reject') {
        if (trim((string) $remark) === '') {
            return [false, 'Say what needs changing before sending it back.'];
        }
        $stmt = db()->prepare("UPDATE targets SET status = 'Changes Requested', review_remark = ?, approved_by = NULL, approved_at = NULL WHERE id = ?");
        $stmt->execute([trim((string) $remark), $id]);
        return [true, 'Sent back to the HoD with your note.'];
    }

    return [false, 'Unknown review decision.'];
}

/**
 * Bulk approve all currently eligible targets waiting for review (status 'Dean Pending').
 * Scoped to user's authorized role and optional department/academic-year filters.
 * Runs atomically inside a database transaction.
 */
function targets_bulk_approve(array $user, ?string $deptFilter = null, ?string $yearFilter = null): array
{
    if (!in_array($user['role'], ['Dean', 'Admin', 'Director'], true)) {
        return [false, 'You are not authorized to approve targets.'];
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $sql = "SELECT id, department, metric, academic_year, created_by
                  FROM targets
                 WHERE status IN ('Dean Pending', 'Pending Review')";
        $params = [];

        if ($deptFilter) {
            $sql .= " AND department = ?";
            $params[] = $deptFilter;
        }
        if ($yearFilter) {
            $sql .= " AND academic_year = ?";
            $params[] = $yearFilter;
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $targets = $stmt->fetchAll();

        if (empty($targets)) {
            $pdo->rollBack();
            return [false, 'No eligible Dean Pending targets found to approve.'];
        }

        $now = date('Y-m-d H:i:s');
        $updSql = "UPDATE targets
                      SET status = 'Approved', approved_by = ?, approved_at = ?
                    WHERE id = ? AND status IN ('Dean Pending', 'Pending Review')";
        $updStmt = $pdo->prepare($updSql);

        $approvedCount = 0;
        foreach ($targets as $t) {
            $updStmt->execute([$user['id'], $now, $t['id']]);
            $approvedCount += $updStmt->rowCount();
        }

        $pdo->commit();
        return [true, "$approvedCount target" . ($approvedCount !== 1 ? 's' : '') . " approved successfully."];
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return [false, 'Failed to approve targets: ' . $e->getMessage()];
    }
}

function target_delete(int $id, array $user): array
{
    $existing = target_find($id);
    if (!$existing) {
        return [false, 'Target not found.'];
    }
    if (!target_can_delete($existing, $user)) {
        return [false, target_is_frozen($existing)
            ? 'That target is frozen. Only an Admin can delete it.'
            : 'That target is not yours to delete.'];
    }

    $stmt = db()->prepare('DELETE FROM targets WHERE id = ?');
    $stmt->execute([$id]);

    return [true, 'Target deleted.'];
}

/* ==========================================================================
   Contribution counting (non-destructive suggestion)

   Most targets are free-text meeting-report proforma rows (pass %, website
   updation, lettered sub-items) whose achieved figure is tracked by hand and
   must never be touched. Only a handful correspond to a record type faculty
   actually upload. For those, we can COUNT the approved records and OFFER that
   number beside the target — the HoD accepts it into achieved_value with one
   click. Nothing is ever overwritten automatically.
   ========================================================================= */

/**
 * The record type whose approved rows back a target, inferred from its free-text
 * metric. Returns null for every proforma row that is not record-backed, so only
 * the mappable handful ever gets a suggestion.
 */
function target_suggested_type(string $metric): ?string
{
    $m = strtoupper($metric);

    // Order matters: least-ambiguous words are checked first, and the broad
    // "CONFERENCE"/"JOURNAL" catch-alls last — so a row that names FDP *and*
    // conference ("Faculty participations in FDP … / Conference") maps to FDP,
    // not conference. This is a best-effort hint only; the HoD reviews it.
    $rules = [
        'nptel'      => ['NPTEL'],
        'internship' => ['INTERNSHIP'],
        'placement'  => ['PLACEMENT'],
        'patent'     => ['PATENT', 'COPY RIGHT', 'COPYRIGHT'],
        'book'       => ['BOOK'],            // "BOOKS PUBLICATION" and "BOOK CHAPTER"
        'mou'        => ['MOU'],
        'fdp'        => ['FDP', 'STTP'],
        'conference' => ['CONFERENCE'],
        'journal'    => ['SCOPUS', 'SCI JOURNAL', 'UGC CARE', 'QUALITY PUBLICATION', 'JOURNAL'],
    ];

    foreach ($rules as $type => $words) {
        foreach ($words as $w) {
            if (strpos($m, $w) !== false) {
                return $type;
            }
        }
    }

    return null;
}

/** Columns of a record table, cached, so a count only filters on columns it has. */
function target_record_table_columns(string $table): array
{
    static $cache = [];
    if (!array_key_exists($table, $cache)) {
        try {
            $cache[$table] = db()->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_COLUMN);
        } catch (\PDOException $e) {
            $cache[$table] = [];
        }
    }
    return $cache[$table];
}

/**
 * How many APPROVED records back this target right now — the figure the HoD may
 * accept into achieved_value. Null when the target is not record-backed.
 *
 * Scoped to the target's department (where the record type has that column) and
 * to its academic year (likewise), so the count matches the target's scope.
 */
function target_record_count(array $target): ?int
{
    static $countCache = [];

    $type = target_suggested_type((string) ($target['metric'] ?? ''));
    if ($type === null) {
        return null;
    }

    $dept = (string) ($target['department'] ?? '');
    $year = (string) ($target['academic_year'] ?? '');
    $cacheKey = "{$type}|{$dept}|{$year}";
    if (array_key_exists($cacheKey, $countCache)) {
        return $countCache[$cacheKey];
    }

    require_once __DIR__ . '/Record.php';
    $types = record_types();
    if (!isset($types[$type])) {
        return null;
    }

    $table = $types[$type]['table'];
    $cols  = target_record_table_columns($table);
    if (!in_array('status', $cols, true)) {
        return null;
    }

    $sql  = "SELECT COUNT(*) FROM `$table` WHERE status = 'Approved'";
    $args = [];

    if (in_array('department', $cols, true) && !empty($target['department'])) {
        $sql .= ' AND department = ?';
        $args[] = $target['department'];
    }
    if (in_array('academic_year', $cols, true) && !empty($target['academic_year'])) {
        $sql .= ' AND academic_year = ?';
        $args[] = $target['academic_year'];
    }

    try {
        $stmt = db()->prepare($sql);
        $stmt->execute($args);
        $count = (int) $stmt->fetchColumn();
        $countCache[$cacheKey] = $count;
        return $count;
    } catch (\PDOException $e) {
        return 0;
    }
}

/**
 * Accept the counted-from-records figure into achieved_value (the HoD's one-click
 * "Use"). Goes through the same target_can_edit() gate as any other edit, so a
 * frozen target still needs Admin rights or an open unlock window.
 */
function target_apply_count(int $id, array $user): array
{
    $existing = target_find($id);
    if (!$existing) {
        return [false, 'Target not found.'];
    }
    if (!target_can_edit($existing, $user)) {
        return [false, target_is_frozen($existing)
            ? 'That target is frozen. Only an Admin can change it now.'
            : 'That target is not yours to edit.'];
    }

    $count = target_record_count($existing);
    if ($count === null) {
        return [false, 'This target is not linked to an uploaded record type.'];
    }

    db()->prepare('UPDATE targets SET achieved_value = ? WHERE id = ?')->execute([$count, $id]);

    return [true, "Achieved set to {$count} from approved records."];
}

/**
 * The earliest starting year for academic years (2000-01).
 */
const ACADEMIC_YEAR_FIRST_START = 2000;

/**
 * Determine the start year of the current academic year dynamically from current date.
 * Academic year runs June–May, so from June onward the "current" year has rolled over.
 */
function current_academic_year_start(): int
{
    return (int) date('n') >= 6 ? (int) date('Y') : (int) date('Y') - 1;
}

/**
 * Current academic year string in YYYY-YY format, e.g. "2026-27".
 */
function current_academic_year(): string
{
    $y = current_academic_year_start();
    return sprintf('%d-%02d', $y, ($y + 1) % 100);
}

/**
 * Validate an academic year format and ensure it is between 2000-01 and current academic year.
 * Future academic years and years prior to 2000 are strictly rejected.
 */
function is_valid_academic_year(?string $year): bool
{
    if ($year === null) {
        return false;
    }
    $year = trim($year);
    if (!preg_match('/^(\d{4})-(\d{2})$/', $year, $matches)) {
        return false;
    }
    $startYear = (int) $matches[1];
    $endSuffix = (int) $matches[2];

    if ($startYear < ACADEMIC_YEAR_FIRST_START) {
        return false;
    }

    $currentStart = current_academic_year_start();
    if ($startYear > $currentStart) {
        return false;
    }

    if ($endSuffix !== (($startYear + 1) % 100)) {
        return false;
    }

    return true;
}

/**
 * All valid academic years up to the current one.
 *
 * An academic year runs June–May, so from June onward the "current" year has
 * already rolled over. The list runs from 2000-01 up to the current academic
 * year (never showing future academic years), newest first.
 */
function academic_years(): array
{
    $firstStart   = ACADEMIC_YEAR_FIRST_START;
    $currentStart = current_academic_year_start();
    $lastStart    = $currentStart;   // current academic year only (no future years)

    $years = [];
    for ($y = $lastStart; $y >= $firstStart; $y--) {
        $years[] = sprintf('%d-%02d', $y, ($y + 1) % 100);
    }
    return $years;
}

/* ==========================================================================
   Global active academic year (Admin Year Control)

   ONE centralized, system-wide "what year is ATTS operating on right now"
   value — not per-browser-session, so the moment an Admin activates a year
   every signed-in Faculty/Coordinator/HoD/Dean/Director sees it too, not just
   the Admin's own session. Backed by the existing app_settings key/value
   store (models/Setting.php) — the same mechanism already used for the
   report-template choice ("choices an Admin makes once for everyone") — so
   this does not introduce a second, conflicting settings system.

   Every year-dependent page must call active_academic_year() to read it, and
   never trust a client-supplied year for anything but display. Only
   activate_academic_year() may change it, and only after validating the year
   server-side (format, range, not in the future).
   ========================================================================= */

const ACTIVE_ACADEMIC_YEAR_SETTING = 'active_academic_year';

/**
 * The one active academic year for the whole system right now.
 *
 * Falls back to the real current academic year when nothing has ever been
 * activated (fresh install) or the stored value is somehow no longer valid
 * (e.g. the calendar rolled over past a year an Admin pinned long ago) —
 * the system always has a sane, never-future year to operate on.
 */
function active_academic_year(): string
{
    $stored = setting_get(ACTIVE_ACADEMIC_YEAR_SETTING);
    return is_valid_academic_year($stored) ? $stored : current_academic_year();
}

/**
 * Admin activates a year as the system-wide active academic year.
 *
 * Validates strictly server-side — format, range 2000-01..current, never a
 * future year — before writing. This only changes the application's
 * CONTEXT/FILTER (the app_settings row); it never touches a single row of
 * historical data in targets/records.
 */
function activate_academic_year(string $year, int $adminUserId): array
{
    $year = trim($year);
    if (!is_valid_academic_year($year)) {
        return [false, 'Please select a valid academic year (2000-01 through ' . current_academic_year() . '). Future academic years are not permitted.'];
    }

    setting_set(ACTIVE_ACADEMIC_YEAR_SETTING, $year, $adminUserId);

    return [true, "Academic year {$year} is now active for the whole system."];
}

function metric_names(): array
{
    $rows = db()->query('SELECT name FROM metrics WHERE status = 1 ORDER BY name')->fetchAll(PDO::FETCH_COLUMN);
    return $rows ?: [];
}

/**
 * Automatically update achieved_value for all record-backed targets in the targets table.
 */
function sync_all_target_achieved(): void
{
    $targets = db()->query("SELECT * FROM targets")->fetchAll();
    $updateStmt = db()->prepare("UPDATE targets SET achieved_value = ? WHERE id = ?");
    foreach ($targets as $t) {
        $count = target_record_count($t);
        if ($count !== null) {
            $updateStmt->execute([$count, (int) $t['id']]);
        }
    }
}

/**
 * Automatically update achieved_value for targets matching a specific record type.
 */
function sync_target_achieved_for_type(string $type): void
{
    $targets = db()->query("SELECT * FROM targets")->fetchAll();
    $updateStmt = db()->prepare("UPDATE targets SET achieved_value = ? WHERE id = ?");
    foreach ($targets as $t) {
        $suggestedType = target_suggested_type((string) ($t['metric'] ?? ''));
        if ($suggestedType === $type) {
            $count = target_record_count($t);
            if ($count !== null) {
                $updateStmt->execute([$count, (int) $t['id']]);
            }
        }
    }
}

/**
 * Default targets list from the CSE Executive Meeting Report Word document.
 * Fixed target details and fixed target values are sourced directly from the document.
 */
function target_defaults(): array
{
    return [
        ['sort_order' => 1,  'serial_no' => '1',     'metric' => 'PASS PERCENTAGE', 'fixed_text' => '86 %', 'target_value' => 86],
        ['sort_order' => 2,  'serial_no' => '2',     'metric' => 'TO IMPROVE II, III & IV YEAR STUDENTS CGPA', 'fixed_text' => '50', 'target_value' => 50],
        ['sort_order' => 3,  'serial_no' => '3',     'metric' => '2022-26 BATCH STUDENTS PLACEMENT', 'fixed_text' => '60', 'target_value' => 60],
        ['sort_order' => 4,  'serial_no' => '4',     'metric' => 'NUMBER OF QUALITY PUBLICATIONS IN SCOPUS/SCI JOURNALS/SPRINGER/UGC CARE/H-INDEX', 'fixed_text' => 'UGC – 18', 'target_value' => 18],
        ['sort_order' => 5,  'serial_no' => '5(a)',  'metric' => 'BOOKS PUBLICATION', 'fixed_text' => '1', 'target_value' => 1],
        ['sort_order' => 6,  'serial_no' => '5(b)',  'metric' => 'BOOK CHAPTER', 'fixed_text' => '1', 'target_value' => 1],
        ['sort_order' => 7,  'serial_no' => '6(a)',  'metric' => 'PATENT PUBLISHED', 'fixed_text' => '1', 'target_value' => 1],
        ['sort_order' => 8,  'serial_no' => '6(b)',  'metric' => 'PATENT GRANTED', 'fixed_text' => '1', 'target_value' => 1],
        ['sort_order' => 9,  'serial_no' => '6(c)',  'metric' => 'COPY RIGHTS', 'fixed_text' => '1', 'target_value' => 1],
        ['sort_order' => 10, 'serial_no' => '7(a)',  'metric' => 'SPONSORED RESEARCH', 'fixed_text' => '10 Lakhs', 'target_value' => 10],
        ['sort_order' => 11, 'serial_no' => '7(b)',  'metric' => 'FUNDS CONSULTANCY PROJECTS', 'fixed_text' => '2 Lakhs', 'target_value' => 2],
        ['sort_order' => 12, 'serial_no' => '8',     'metric' => 'RESEARCH CENTRE RECOGNITION FROM ANNA UNIVERSITY', 'fixed_text' => '-', 'target_value' => 0],
        ['sort_order' => 13, 'serial_no' => '9(a)',  'metric' => 'PROGRAMME ON INTELLECTUAL PROPERTY RIGHTS', 'fixed_text' => '1', 'target_value' => 1],
        ['sort_order' => 14, 'serial_no' => '9(b)',  'metric' => 'PROGRAMME ON HIGHER STUDIES', 'fixed_text' => '1', 'target_value' => 1],
        ['sort_order' => 15, 'serial_no' => '9(c)',  'metric' => 'PROGRAMME ON ENTREPRENEURSHIP', 'fixed_text' => '1', 'target_value' => 1],
        ['sort_order' => 16, 'serial_no' => '10(a)', 'metric' => 'NPTEL', 'fixed_text' => '9', 'target_value' => 9],
        ['sort_order' => 17, 'serial_no' => '10(b)', 'metric' => 'Others', 'fixed_text' => '-', 'target_value' => 0],
        ['sort_order' => 18, 'serial_no' => '11',    'metric' => 'INDUSTRY INTERACTION/MOU/INDUSTRY SUPPORTED LAB', 'fixed_text' => '1', 'target_value' => 1],
        ['sort_order' => 19, 'serial_no' => '12',    'metric' => 'NO OF STUDENTS COMPLETED INDUSTRY INTERNSHIP (4 weeks & above)', 'fixed_text' => '85', 'target_value' => 85],
        ['sort_order' => 20, 'serial_no' => '13',    'metric' => 'NO OF STUDENTS COMPLETED SUMMER TRAINING (less than 4 weeks)', 'fixed_text' => '20', 'target_value' => 20],
        ['sort_order' => 21, 'serial_no' => '14',    'metric' => 'STUDENTS PROJECT WITH QUALITY AND PUBLISH THE PROJECTS in Conference, Journal, Hackathon & YouTube', 'fixed_text' => '10', 'target_value' => 10],
        ['sort_order' => 22, 'serial_no' => '15',    'metric' => 'FACULTY PARTICIPATIONS IN FDP / TRAINING ACTIVITIES / STTP/ CONFERENCE', 'fixed_text' => '27', 'target_value' => 27],
        ['sort_order' => 23, 'serial_no' => '16',    'metric' => 'NO. OF MEMBERSHIP IN PROFESSIONAL SOCIETIES (Faculties & Students)', 'fixed_text' => '50', 'target_value' => 50],
        ['sort_order' => 24, 'serial_no' => '17',    'metric' => 'NEWSLETTER', 'fixed_text' => '2', 'target_value' => 2],
        ['sort_order' => 25, 'serial_no' => '18',    'metric' => 'NO. OF ONLINE CERTIFICATIONS COMPLETED BY STUDENTS', 'fixed_text' => '50', 'target_value' => 50],
        ['sort_order' => 26, 'serial_no' => '19',    'metric' => 'NO. OF STUDENTS COMPLETED IIT-', 'fixed_text' => '100', 'target_value' => 100],
        ['sort_order' => 27, 'serial_no' => '20(a)', 'metric' => 'PARTICIPATION IN INTER-INSTITUTE EVENTS BY STUDENTS WITHIN STATE', 'fixed_text' => '10', 'target_value' => 10],
        ['sort_order' => 28, 'serial_no' => '20(b)', 'metric' => 'OUTSIDE STATE', 'fixed_text' => '2', 'target_value' => 2],
        ['sort_order' => 29, 'serial_no' => '20(c)', 'metric' => 'AWARDS/PRIZES', 'fixed_text' => '5', 'target_value' => 5],
        ['sort_order' => 30, 'serial_no' => '21',    'metric' => 'NO OF VALUE ADDED COURSE/HANDS ON TRAINING COURSES', 'fixed_text' => '3', 'target_value' => 3],
        ['sort_order' => 31, 'serial_no' => '22(a)', 'metric' => 'EVENTS PARTICIPATION IN SPORTS – STATE LEVEL', 'fixed_text' => '10', 'target_value' => 10],
        ['sort_order' => 32, 'serial_no' => '22(b)', 'metric' => 'NATIONAL LEVEL', 'fixed_text' => '1', 'target_value' => 1],
        ['sort_order' => 33, 'serial_no' => '22(c)', 'metric' => 'AWARDS / MEDALS', 'fixed_text' => '5', 'target_value' => 5],
        ['sort_order' => 34, 'serial_no' => '23',    'metric' => 'INNOVATION EVENTS TO BE CONDUCTED', 'fixed_text' => '2', 'target_value' => 2],
        ['sort_order' => 35, 'serial_no' => '24',    'metric' => 'IIC ACTIVITIES', 'fixed_text' => '2', 'target_value' => 2],
        ['sort_order' => 36, 'serial_no' => '25',    'metric' => 'WEBSITE UPDATION', 'fixed_text' => '-', 'target_value' => 0],
        ['sort_order' => 37, 'serial_no' => '26',    'metric' => 'STARTUP', 'fixed_text' => '1', 'target_value' => 1],
        ['sort_order' => 38, 'serial_no' => '27',    'metric' => 'ALUMNI CHAPTER', 'fixed_text' => '-', 'target_value' => 0],
        ['sort_order' => 39, 'serial_no' => '28',    'metric' => 'AWARDS', 'fixed_text' => '1', 'target_value' => 1],
        ['sort_order' => 40, 'serial_no' => '29(a)', 'metric' => 'RECOGNITION for Faculty – BOS/DC MEMBERS', 'fixed_text' => '10', 'target_value' => 10],
        ['sort_order' => 41, 'serial_no' => '29(b)', 'metric' => 'QP/Key SETTER', 'fixed_text' => '03', 'target_value' => 3],
        ['sort_order' => 42, 'serial_no' => '29(c)', 'metric' => 'Reviewer for Journal', 'fixed_text' => '01', 'target_value' => 1],
        ['sort_order' => 43, 'serial_no' => '29(d)', 'metric' => 'Resource Person', 'fixed_text' => '-', 'target_value' => 0],
        ['sort_order' => 44, 'serial_no' => '29(e)', 'metric' => 'Others', 'fixed_text' => '01', 'target_value' => 1],
        ['sort_order' => 45, 'serial_no' => '30',    'metric' => 'NO. OF ACTIVITIES CONDUCTED BY NSS/YRC', 'fixed_text' => '-', 'target_value' => 0],
    ];
}

/**
 * Ensure default targets exist for a department and academic year.
 * If targets already exist, does not duplicate them (safe idempotent seed).
 *
 * $academicYear defaults to the system's active academic year — never a
 * hard-coded year — so seeding always lands in whichever year ATTS is
 * currently operating on.
 */
function ensure_default_targets(string $department, ?string $academicYear = null, ?int $createdBy = null): int
{
    $department = trim($department);
    $academicYear = trim((string) ($academicYear ?: active_academic_year()));
    if ($department === '' || $academicYear === '') {
        return 0;
    }

    $chk = db()->prepare("SELECT COUNT(*) FROM targets WHERE department = ? AND academic_year = ? AND serial_no = '1'");
    $chk->execute([$department, $academicYear]);
    if ((int) $chk->fetchColumn() > 0) {
        return 0;
    }

    $defaults = target_defaults();
    $sql = 'INSERT INTO targets (department, academic_year, sort_order, serial_no, metric, fixed_text, target_value, target_deadline, achieved_value, status, created_by, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, NULL, 0, \'Draft\', ?, NOW(), NOW())';
    $stmt = db()->prepare($sql);

    $inserted = 0;
    foreach ($defaults as $item) {
        $stmt->execute([
            $department,
            $academicYear,
            $item['sort_order'],
            $item['serial_no'],
            $item['metric'],
            $item['fixed_text'],
            $item['target_value'],
            $createdBy,
        ]);
        $inserted++;
    }

    return $inserted;
}

