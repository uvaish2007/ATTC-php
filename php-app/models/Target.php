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

/** The default edit-window length in hours (Admin-configurable). */
function unlock_default_hours(): int
{
    return max(1, (int) setting_get('unlock_hours', '12'));
}

/** Mark any window whose time has run out as Expired (lazy, cosmetic). */
function unlock_expire_due(): void
{
    db()->exec("UPDATE unlock_requests SET status='Expired' WHERE status='Granted' AND unlocked_until <= NOW()");
}

/** The department's live unlock window, if one is open right now. */
function unlock_active(?string $department): ?array
{
    if (!$department) {
        return null;
    }
    $stmt = db()->prepare(
        "SELECT * FROM unlock_requests
          WHERE department = ? AND status = 'Granted' AND unlocked_until > NOW()
          ORDER BY unlocked_until DESC LIMIT 1"
    );
    $stmt->execute([$department]);
    return $stmt->fetch() ?: null;
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
    return [true, "Unlocked for {$hours}h — the HoD can edit until the timer ends."];
}

/** Admin denies a request. */
function unlock_deny(int $id, int $adminId, string $note = ''): array
{
    $stmt = db()->prepare("UPDATE unlock_requests SET status='Denied', granted_by=?, admin_note=? WHERE id = ? AND status='Requested'");
    $stmt->execute([$adminId, trim($note) ?: null, $id]);
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
    return ['Draft', 'Pending Review', 'Changes Requested', 'Approved'];
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
    if ($user['role'] !== 'HoD' || !target_owns($target, $user)) {
        return false;
    }

    $status = $target['status'] ?? 'Draft';

    // Still theirs to write before it is locked...
    if (in_array($status, ['Draft', 'Changes Requested'], true)) {
        return true;
    }

    // ...or a locked target while an unlock window is open and unexpired.
    return $status === 'Approved' && unlock_active($user['department'] ?? null) !== null;
}

/** May this user send it up for review? Only the HoD who owns it. */
function target_can_submit(array $target, array $user): bool
{
    return $user['role'] === 'HoD'
        && target_owns($target, $user)
        && in_array($target['status'] ?? 'Draft', ['Draft', 'Changes Requested'], true);
}

/** May this user approve it or send it back? Only while it is waiting. */
function target_can_review(array $target, array $user): bool
{
    return in_array($user['role'], ['Admin', 'Director'], true)
        && ($target['status'] ?? '') === 'Pending Review';
}

/** May this user delete it? A frozen target is Admin-only. */
function target_can_delete(array $target, array $user): bool
{
    if ($user['role'] === 'Admin') {
        return true;
    }

    return $user['role'] === 'HoD'
        && target_owns($target, $user)
        && !target_is_frozen($target);
}

/**
 * Targets, newest first, optionally narrowed by department, year or status.
 */
function targets_all(?string $department = null, ?string $year = null, ?string $status = null): array
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
        $sql .= ' AND t.status = ?';
        $params[] = $status;
    }

    $sql .= ' ORDER BY t.created_at DESC';
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

    $sql .= ' ORDER BY (sort_order IS NULL), sort_order, id';
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

/** How many targets are sitting in the review queue, for the nav badge. */
function targets_pending_count(): int
{
    $stmt = db()->query("SELECT COUNT(*) FROM targets WHERE status = 'Pending Review'");
    return (int) $stmt->fetchColumn();
}

/**
 * Create a target.
 *
 * An Admin's target is frozen straight away; a HoD's starts as a Draft they
 * still have to send up. A HoD's department is taken from their account, never
 * from the form.
 */
function target_create(array $user, string $department, string $academicYear, string $metric, int $targetValue, ?string $remarks, ?string $coordinator = null): array
{
    // Entering targets is the HoD's job. An Admin freezes and unlocks them but
    // does not write them; a Director only reviews. So creation is HoD-only.
    if ($user['role'] !== 'HoD') {
        return [false, 'Only a HoD enters targets. The Admin freezes and unlocks them.'];
    }

    // A HoD's department comes from their account, never from the form.
    $department = (string) ($user['department'] ?? '');

    // Metric is free text (any target title), so only emptiness is invalid.
    $metric = trim($metric);
    if ($department === '' || $metric === '') {
        return [false, 'Department and target are required.'];
    }
    if ($targetValue < 0) {
        return [false, 'A target cannot be negative.'];
    }

    // Always starts as a draft the HoD then sends up for review.
    $stmt = db()->prepare(
        'INSERT INTO targets (department, academic_year, metric, target_value, remarks, coordinator, status, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $department, $academicYear, $metric, $targetValue, $remarks ?: null, $coordinator ?: null,
        'Draft', $user['id'],
    ]);

    return [true, 'Target saved as a draft. Send it for review when it is ready.'];
}

/**
 * Update a target's numbers.
 *
 * A frozen target stays frozen when an Admin edits it, but the approval stamp
 * is rewritten so the record always shows who last set the figure. A HoD can
 * never move a target into another department.
 */
function target_update(int $id, array $user, string $department, string $academicYear, string $metric, int $targetValue, int $achievedValue, ?string $remarks, ?string $coordinator = null): array
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
        $department = (string) $existing['department'];   // pinned to where it already is
    }

    $frozen = target_is_frozen($existing);

    $sql  = 'UPDATE targets SET department = ?, academic_year = ?, metric = ?, target_value = ?, achieved_value = ?, remarks = ?, coordinator = ?';
    $args = [$department, $academicYear, $metric, $targetValue, $achievedValue, $remarks ?: null, $coordinator ?: null];

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

    $stmt = db()->prepare("UPDATE targets SET status = 'Pending Review', submitted_at = ? WHERE id = ?");
    $stmt->execute([date('Y-m-d H:i:s'), $id]);

    return [true, 'Sent to the Director and Admin for review.'];
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

    if ($decision === 'changes') {
        if (trim((string) $remark) === '') {
            return [false, 'Say what needs changing before sending it back.'];
        }
        $stmt = db()->prepare("UPDATE targets SET status = 'Changes Requested', review_remark = ?, approved_by = NULL, approved_at = NULL WHERE id = ?");
        $stmt->execute([trim((string) $remark), $id]);
        return [true, 'Sent back to the HoD with your note.'];
    }

    return [false, 'Unknown review decision.'];
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

function academic_years(): array
{
    return ['2023-24', '2024-25', '2025-26', '2026-27'];
}

function metric_names(): array
{
    $rows = db()->query('SELECT name FROM metrics WHERE status = 1 ORDER BY name')->fetchAll(PDO::FETCH_COLUMN);
    return $rows ?: [];
}
