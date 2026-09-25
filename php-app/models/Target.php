<?php
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/Setting.php';

$GLOBALS['_unlock_active_cache'] = [];

function unlock_active_cache_clear(): void
{
    $GLOBALS['_unlock_active_cache'] = [];
}

function unlock_default_hours(): int
{
    return max(1, (int) setting_get('unlock_hours', '12'));
}

function unlock_expire_due(): void
{
    db()->exec("UPDATE unlock_requests SET status='Expired' WHERE status='Granted' AND unlocked_until <= NOW()");
    unlock_active_cache_clear();
}

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

function unlock_pending_for(?string $department): ?array
{
    if (!$department) {
        return null;
    }
    $stmt = db()->prepare("SELECT * FROM unlock_requests WHERE department = ? AND status = 'Requested' ORDER BY id DESC LIMIT 1");
    $stmt->execute([$department]);
    return $stmt->fetch() ?: null;
}

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

function unlock_grant(int $id, int $adminId, int $hours): array
{
    $stmt = db()->prepare("SELECT * FROM unlock_requests WHERE id = ? AND status = 'Requested'");
    $stmt->execute([$id]);
    if (!$stmt->fetch()) {
        return [false, 'That request is no longer pending.'];
    }

    $hours = max(1, min(720, $hours));
    setting_set('unlock_hours', (string) $hours, $adminId);   
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

function unlock_deny(int $id, int $adminId, string $note = ''): array
{
    $stmt = db()->prepare("UPDATE unlock_requests SET status='Denied', granted_by=?, admin_note=? WHERE id = ? AND status='Requested'");
    $stmt->execute([$adminId, trim($note) ?: null, $id]);
    unlock_active_cache_clear();
    return [true, 'Unlock request denied.'];
}

function unlock_pending_all(): array
{
    return db()->query(
        "SELECT u.*, r.name AS requester_name
           FROM unlock_requests u LEFT JOIN users r ON r.id = u.requested_by
          WHERE u.status = 'Requested' ORDER BY u.created_at"
    )->fetchAll();
}

function unlock_pending_count(): int
{
    return (int) db()->query("SELECT COUNT(*) FROM unlock_requests WHERE status='Requested'")->fetchColumn();
}

function target_statuses(): array
{
    return ['Draft', 'Dean Pending', 'Changes Requested', 'Approved'];
}

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

function target_is_frozen(array $target): bool
{
    return ($target['status'] ?? '') === 'Approved';
}

function target_owns(array $target, array $user): bool
{
    if (in_array($user['role'], ['Admin', 'Director', 'Principal', 'Dean'], true)) {
        return true;
    }
    return ($user['department'] ?? null) !== null
        && ($target['department'] ?? null) === $user['department'];
}

function target_can_edit(array $target, array $user): bool
{
    if ($user['role'] === 'Admin') {
        return true;
    }
    // Academic Year lock does NOT prevent target editing
    if (!in_array($user['role'], ['HoD', 'Dean'], true) || !target_owns($target, $user)) {
        return false;
    }

    $status = $target['status'] ?? 'Draft';

    if (in_array($status, ['Draft', 'Changes Requested'], true)) {
        return true;
    }

    $dept = $target['department'] ?? ($user['department'] ?? null);
    return $status === 'Approved' && unlock_active($dept) !== null;
}

function target_can_submit(array $target, array $user): bool
{
    return in_array($user['role'], ['HoD', 'Dean'], true)
        && target_owns($target, $user)
        && in_array($target['status'] ?? 'Draft', ['Draft', 'Changes Requested'], true);
}

function target_can_review(array $target, array $user): bool
{
    return in_array($user['role'], ['Admin', 'Director', 'Principal', 'Dean'], true)
        && in_array($target['status'] ?? '', ['Dean Pending', 'Pending Review'], true);
}

function target_can_delete(array $target, array $user): bool
{
    if ($user['role'] === 'Admin') {
        return true;
    }

    return in_array($user['role'], ['HoD', 'Dean'], true)
        && target_owns($target, $user)
        && !target_is_frozen($target);
}

function targets_all(?string $department = null, ?string $year = null, ?string $status = null, ?string $metric = null, bool $excludeDrafts = false): array
{
    $sql = 'SELECT t.*, u.name AS creator_name, a.name AS approver_name
              FROM targets t
              LEFT JOIN users u ON t.created_by  = u.id
              LEFT JOIN users a ON t.approved_by = a.id
             WHERE 1=1';
    $params = [];

    if ($department) {
        $deptVars = department_variants($department);
        if (!empty($deptVars)) {
            $inPh = implode(',', array_fill(0, count($deptVars), '?'));
            $sql .= " AND t.department IN ($inPh)";
            $params = array_merge($params, $deptVars);
        } else {
            $sql .= ' AND t.department = ?';
            $params[] = $department;
        }
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

function target_report_items(?string $department = null, ?string $year = null): array
{
    $sql    = 'SELECT * FROM targets WHERE 1=1';
    $params = [];

    if ($department) {
        $deptVars = department_variants($department);
        if (!empty($deptVars)) {
            $inPh = implode(',', array_fill(0, count($deptVars), '?'));
            $sql .= " AND department IN ($inPh)";
            $params = array_merge($params, $deptVars);
        } else {
            $sql .= ' AND department = ?';
            $params[] = $department;
        }
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

function target_create(array $user, string $department, string $academicYear, string $metric, int $targetValue, ?string $remarks, ?string $coordinator = null, string $status = 'Draft', ?string $targetDeadline = null): array
{
    if (!in_array($user['role'], ['HoD', 'Dean', 'Admin'], true)) {
        return [false, 'Only a HoD, Dean, or Admin enters targets.'];
    }

    $targetYear = trim($academicYear) ?: active_academic_year();
    if (!is_valid_academic_year($targetYear) || !in_array($targetYear, academic_years(), true)) {
        return [false, 'Invalid academic year specified for target.'];
    }

    $academicYear = $targetYear;

    if ($user['role'] === 'HoD') {
        $department = (string) ($user['department'] ?? '');
        if ($department === '') {
            return [false, 'Department is required for HoD target creation.'];
        }
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

    // The deadline column is only named when it exists (targets_deadline_ready
    // adds it if the migration never ran); without it the target still saves.
    $hasDeadline = targets_deadline_ready();

    $cols = ['department', 'academic_year', 'metric', 'target_value'];
    $vals = [$department, $academicYear, $metric, $targetValue];
    if ($hasDeadline) {
        $cols[] = 'target_deadline';
        $vals[] = $targetDeadline;
    }
    array_push($cols, 'remarks', 'coordinator', 'status', 'submitted_at', 'created_by');
    array_push($vals, $remarks ?: null, $coordinator ?: null, $statusVal, $submittedAt, $user['id']);

    $stmt = db()->prepare(
        'INSERT INTO targets (' . implode(', ', $cols) . ')
         VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')'
    );
    $stmt->execute($vals);

    if ($isPending) {
        return [true, 'Target created and submitted for Dean review.'];
    }

    return [true, 'Target saved as a draft. Send it for review when it is ready.'];
}

function target_update(int $id, array $user, string $department, string $academicYear, string $metric, int $targetValue, int $achievedValue, ?string $remarks, ?string $coordinator = null, ?string $targetDeadline = null, ?string $fixedText = null): array
{
    if (!in_array($user['role'], ['Admin', 'HoD', 'Dean'], true)) {
        return [false, 'Unauthorized to edit targets.'];
    }

    $existing = target_find($id);
    if (!$existing) {
        return [false, 'Target not found.'];
    }

    $existingYear = (string) ($existing['academic_year'] ?? '');
    $requestedYear = trim($academicYear);

    if ($requestedYear !== '' && $requestedYear !== $existingYear) {
        return [false, "Target #{$id} belongs to Academic Year {$existingYear}, not {$requestedYear}."];
    }

    // Department scope check: HoD/Coordinator can never edit outside their assigned department
    if (in_array($user['role'], ['HoD', 'Coordinator'], true)) {
        $userDept = $user['department'] ?? '';
        if ($userDept === '' || ($existing['department'] ?? '') !== $userDept) {
            return [false, 'You do not have permission to edit targets outside your department.'];
        }
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
        $department = (string) $existing['department'];           $targetYear = $existingYear;                      // non-admins cannot move a target to another year
    } else {
        $department = trim($department) ?: (string) $existing['department'];
        $targetYear = ($requestedYear !== '' && is_valid_academic_year($requestedYear)) ? $requestedYear : $existingYear;
    }

    $frozen = target_is_frozen($existing);

    $targetDeadline = !empty(trim((string) $targetDeadline)) ? trim((string) $targetDeadline) : null;
    if ($targetDeadline !== null) {
        $d = DateTime::createFromFormat('Y-m-d', $targetDeadline);
        if (!$d || $d->format('Y-m-d') !== $targetDeadline) {
            $targetDeadline = null;
        }
    }

    $sql  = 'UPDATE targets SET department = ?, academic_year = ?, metric = ?, target_value = ?';
    $args = [$department, $targetYear, $metric, $targetValue];

    if (targets_deadline_ready()) {
        $sql   .= ', target_deadline = ?';
        $args[] = $targetDeadline;
    }

    $sql   .= ', achieved_value = ?, remarks = ?, coordinator = ?';
    array_push($args, $achievedValue, $remarks ?: null, $coordinator ?: null);

    if ($fixedText !== null) {
        $sql .= ', fixed_text = ?';
        $args[] = trim($fixedText);
    }

    // Re-stamp the approval only when an Admin edits a frozen target
    if ($frozen && $user['role'] === 'Admin') {
        $sql .= ', approved_by = ?, approved_at = ?';
        $args[] = $user['id'];
        $args[] = date('Y-m-d H:i:s');
    }

    $sql   .= ' WHERE id = ? AND academic_year = ?';
    $args[] = $id;
    $args[] = $existingYear;

    db()->prepare($sql)->execute($args);

    return [true, $frozen ? 'Frozen target updated.' : 'Target updated.'];
}

function target_submit(int $id, array $user): array
{
    $existing = target_find($id);
    if (!$existing) {
        return [false, 'Target not found.'];
    }

    if (in_array($user['role'], ['HoD', 'Coordinator'], true)) {
        $userDept = $user['department'] ?? '';
        if ($userDept === '' || ($existing['department'] ?? '') !== $userDept) {
            return [false, 'You do not have permission to submit targets outside your department.'];
        }
    }

    if (!target_can_submit($existing, $user)) {
        return [false, 'That target cannot be sent for review.'];
    }

    $stmt = db()->prepare("UPDATE targets SET status = 'Dean Pending', submitted_at = ? WHERE id = ?");
    $stmt->execute([date('Y-m-d H:i:s'), $id]);

    return [true, 'Target submitted for Dean review.'];
}

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

function targets_bulk_approve(array $user, ?string $deptFilter = null, ?string $yearFilter = null): array
{
    if (!in_array($user['role'], ['Dean', 'Admin', 'Director', 'Principal'], true)) {
        return [false, 'You are not authorized to approve targets.'];
    }

    $effectiveYear = $yearFilter ?: active_academic_year();
    if (!is_valid_academic_year($effectiveYear)) {
        return [false, 'Invalid academic year.'];
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

function target_suggested_type(string $metric): ?string
{
    $m = strtoupper($metric);

    $rules = [
        'nss'                   => ['NSS', 'YRC', 'RRC'],
        'summer_training'       => ['SUMMER TRAINING', 'WINTER TRAINING'],
        'value_added'           => ['VALUE ADDED', 'VALUE-ADDED'],
        'online_course'         => ['ONLINE CERTIF', 'ONLINE COURSE'],
        'student_achievement'   => ['AWARDS/PRIZES', 'AWARDS / MEDALS'],
        'student_participation' => ['PARTICIPATION IN INTER-INSTITUTE', 'OUTSIDE STATE', 'SPORTS – STATE LEVEL', 'NATIONAL LEVEL'],
        'nptel'                 => ['NPTEL'],
        'internship'            => ['INTERNSHIP'],
        'placement'             => ['PLACEMENT'],
        'patent'                => ['PATENT', 'COPY RIGHT', 'COPYRIGHT'],
        'book'                  => ['BOOK'],                    'mou'                   => ['MOU', 'INDUSTRY SUPPORTED LAB'],
        'fdp'                   => ['FDP', 'STTP'],
        'conference'            => ['CONFERENCE'],
        'journal'               => ['SCOPUS', 'SCI JOURNAL', 'UGC CARE', 'QUALITY PUBLICATION', 'JOURNAL'],
        'event'                 => ['INNOVATION EVENTS', 'IIC ACTIVITIES'],
        'training'              => ['TRAINING PROGRAMME', 'TRAINING ACTIVITIES'],
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

function target_record_count(array $target, ?string $from = null, ?string $to = null): ?int
{
    static $countCache = [];

    $type = target_suggested_type((string) ($target['metric'] ?? ''));
    if ($type === null) {
        return null;
    }

    $dept = (string) ($target['department'] ?? '');
    $year = (string) ($target['academic_year'] ?? '');
    $cacheKey = "{$type}|{$dept}|{$year}|{$from}|{$to}";
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
    if ($from !== null) {
        $sql .= ' AND created_at >= ?';
        $args[] = $from . ' 00:00:00';
    }
    if ($to !== null) {
        $sql .= ' AND created_at <= ?';
        $args[] = $to . ' 23:59:59';
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

function target_approved_records(array $target): array
{
    $type = target_suggested_type((string) ($target['metric'] ?? ''));
    if ($type === null) {
        return [];
    }

    require_once __DIR__ . '/Record.php';
    $types = record_types();
    if (!isset($types[$type])) {
        return [];
    }

    $tInfo = $types[$type];
    $table = $tInfo['table'];
    $cols  = target_record_table_columns($table);
    if (!in_array('status', $cols, true)) {
        return [];
    }

    $hasDept = in_array('department', $cols, true);
    $hasYear = in_array('academic_year', $cols, true);

    $sql = "SELECT r.*, u.name AS approver_name, u.role AS approver_role,
                   creator.name AS creator_name, creator.email AS creator_email
            FROM `{$table}` r
            LEFT JOIN users u ON r.approved_by = u.id
            LEFT JOIN users creator ON r.created_by = creator.id
            WHERE r.status = 'Approved'";
    $args = [];

    if ($hasDept && !empty($target['department'])) {
        $sql .= ' AND r.department = ?';
        $args[] = $target['department'];
    }
    if ($hasYear && !empty($target['academic_year'])) {
        $sql .= ' AND r.academic_year = ?';
        $args[] = $target['academic_year'];
    }

    $sql .= ' ORDER BY r.updated_at DESC, r.created_at DESC';

    try {
        $stmt = db()->prepare($sql);
        $stmt->execute($args);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $results = [];
        foreach ($rows as $row) {
            $row['_type_key']   = $type;
            $row['_type_label'] = $tInfo['label'];
            $row['_title']      = $row[$tInfo['title_col']] ?? '(untitled)';
            $row['_person']     = $row['faculty_name']
                ?? $row['candidate_name']
                ?? $row['student_name']
                ?? $row['creator_name']
                ?? 'Faculty';
<<<<<<< HEAD
            $row['_proof_url']  = !empty($row['proof_file']) ? proof_url($row['proof_file']) : null;
=======
            $row['_proof_url']  = !empty($row['proof_file']) ? url('view-proof.php?file=' . rawurlencode($row['proof_file']) . '&type=' . rawurlencode($type) . '&id=' . (int)($row['id'] ?? 0)) : null;
>>>>>>> ac1da4e95ff4ae97513194a6ace61514656c41a6
            $row['_doc_url']    = !empty($row['document_link']) ? $row['document_link'] : (!empty($row['certificate_link']) ? $row['certificate_link'] : null);
            $results[] = $row;
        }
        return $results;
    } catch (\PDOException $e) {
        return [];
    }
}

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

const ACADEMIC_YEAR_FIRST_START = 2000;

function current_academic_year_start(): int
{
    return (int) date('n') >= 6 ? (int) date('Y') : (int) date('Y') - 1;
}

function current_academic_year(): string
{
    $y = current_academic_year_start();
    return sprintf('%d-%02d', $y, ($y + 1) % 100);
}

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

function academic_years(bool $includeUpcoming = false): array
{
    $firstStart   = ACADEMIC_YEAR_FIRST_START;
    $currentStart = current_academic_year_start();
    $lastStart    = $currentStart;

    $years = [];
    for ($y = $lastStart; $y >= $firstStart; $y--) {
        $years[] = sprintf('%d-%02d', $y, ($y + 1) % 100);
    }
    return $years;
}

const ACTIVE_ACADEMIC_YEAR_SETTING = 'active_academic_year';

function active_academic_year(): string
{
    $stored = setting_get(ACTIVE_ACADEMIC_YEAR_SETTING);
    return is_valid_academic_year($stored) ? $stored : current_academic_year();
}

function activate_academic_year(string $year, int $adminUserId): array
{
    $year = trim($year);
    if (!is_valid_academic_year($year)) {
        return [false, 'Please select a valid academic year (2000-01 through ' . current_academic_year() . ').'];
    }

    setting_set(ACTIVE_ACADEMIC_YEAR_SETTING, $year, $adminUserId);

    return [true, "Academic year {$year} is now active for the whole system."];
}

function academic_year_is_locked(?string $year = null): bool
{
    $year = $year ?: active_academic_year();
    return setting_get("ay_locked_{$year}", '0') === '1';
}

function academic_year_set_lock(string $year, bool $locked, int $adminUserId, string $note = ''): array
{
    $year = trim($year);
    if (!is_valid_academic_year($year)) {
        return [false, 'Invalid academic year.'];
    }

    setting_set("ay_locked_{$year}", $locked ? '1' : '0', $adminUserId);
    if ($note !== '') {
        setting_set("ay_lock_note_{$year}", trim($note), $adminUserId);
    }

    $statusText = $locked ? 'Locked (Read-Only / Frozen)' : 'Unlocked (Open for submissions)';
    return [true, "Academic year {$year} cycle is now {$statusText}."];
}

function academic_year_lock_info(string $year): array
{
    $locked = academic_year_is_locked($year);
    $note   = setting_get("ay_lock_note_{$year}", '');

    try {
        $stmt = db()->prepare("SELECT s.updated_at, s.updated_by, u.name as admin_name 
                               FROM app_settings s 
                               LEFT JOIN users u ON u.id = s.updated_by 
                               WHERE s.name = ?");
        $stmt->execute(["ay_locked_{$year}"]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (\PDOException $e) {
        $row = null;
    }

    return [
        'year'       => $year,
        'locked'     => $locked,
        'note'       => $note,
        'updated_at' => $row['updated_at'] ?? null,
        'admin_name' => $row['admin_name'] ?? 'Admin',
    ];
}

function academic_year_summary_stats(string $year): array
{
    static $statsCache = [];
    if (isset($statsCache[$year])) {
        return $statsCache[$year];
    }

    require_once __DIR__ . '/Record.php';
    $types = record_types();

    $totalRecords = 0;
    $approvedRecords = 0;

    foreach ($types as $t) {
        $table = $t['table'];
        $cols = target_record_table_columns($table);
        if (!in_array('academic_year', $cols, true)) {
            continue;
        }

        try {
            $stmt = db()->prepare("SELECT COUNT(*) as total, SUM(CASE WHEN status='Approved' THEN 1 ELSE 0 END) as approved FROM `{$table}` WHERE academic_year = ?");
            $stmt->execute([$year]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $totalRecords += (int) ($row['total'] ?? 0);
            $approvedRecords += (int) ($row['approved'] ?? 0);
        } catch (\PDOException $e) {
            continue;
        }
    }

    $targetsCount = 0;
    try {
        $stmt = db()->prepare("SELECT COUNT(*) FROM targets WHERE academic_year = ?");
        $stmt->execute([$year]);
        $targetsCount = (int) $stmt->fetchColumn();
    } catch (\PDOException $e) {
        $targetsCount = 0;
    }

    return $statsCache[$year] = [
        'records'          => $totalRecords,
        'approved_records' => $approvedRecords,
        'targets'          => $targetsCount,
    ];
}

function academic_years_overview(array $years): array
{
    $out = [];
    foreach ($years as $y) {
        $out[$y] = ['records' => 0, 'approved_records' => 0, 'targets' => 0, 'meetings' => 0, 'locked' => false];
    }

    require_once __DIR__ . '/Record.php';
    foreach (record_types() as $t) {
        $table = $t['table'];
        if (!in_array('academic_year', target_record_table_columns($table), true)) {
            continue;
        }
        try {
            $rows = db()->query("SELECT academic_year AS y, COUNT(*) AS n, SUM(status = 'Approved') AS a FROM `{$table}` GROUP BY academic_year");
            foreach ($rows as $r) {
                if (isset($out[$r['y']])) {
                    $out[$r['y']]['records']          += (int) $r['n'];
                    $out[$r['y']]['approved_records'] += (int) $r['a'];
                }
            }
        } catch (\PDOException $e) {
            continue;
        }
    }

    try {
        foreach (db()->query('SELECT academic_year AS y, COUNT(*) AS n FROM targets GROUP BY academic_year') as $r) {
            if (isset($out[$r['y']])) {
                $out[$r['y']]['targets'] = (int) $r['n'];
            }
        }
    } catch (\PDOException $e) {}

    if (executive_meetings_ready()) {
        try {
            foreach (db()->query("SELECT academic_year AS y, COUNT(*) AS n FROM executive_meetings WHERE status = 'Finished' GROUP BY academic_year") as $r) {
                if (isset($out[$r['y']])) {
                    $out[$r['y']]['meetings'] = (int) $r['n'];
                }
            }
        } catch (\PDOException $e) {}
    }

    try {
        foreach (db()->query("SELECT name, value FROM app_settings WHERE name LIKE 'ay\\_locked\\_%'") as $r) {
            $y = substr($r['name'], strlen('ay_locked_'));
            if (isset($out[$y])) {
                $out[$y]['locked'] = ($r['value'] === '1');
            }
        }
    } catch (\PDOException $e) {}

    return $out;
}

function targets_deadline_ready(): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    try {
        db()->query('SELECT target_deadline FROM targets LIMIT 1');
        return $ready = true;
    } catch (\PDOException $e) {
    }
    try {
        db()->exec('ALTER TABLE targets ADD COLUMN target_deadline DATE NULL AFTER target_value');
        return $ready = true;
    } catch (\PDOException $e) {
        return $ready = false;
    }
}

const EXECUTIVE_MEETINGS_DDL = "CREATE TABLE IF NOT EXISTS executive_meetings (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  academic_year  VARCHAR(9)   NOT NULL,
  meeting_number VARCHAR(50)  NOT NULL,
  meeting_date   DATE         NOT NULL,
  notes          TEXT         NULL,
  status         VARCHAR(20)  NOT NULL DEFAULT 'Finished',
  locked_cycle   TINYINT(1)   NOT NULL DEFAULT 1,
  created_by     INT          NULL,
  created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_exec_meeting (academic_year, meeting_number),
  KEY idx_exec_meeting_year (academic_year, meeting_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

function executive_meetings_ready(): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    try {
        db()->query('SELECT 1 FROM executive_meetings LIMIT 1');
        return $ready = true;
    } catch (\PDOException $e) {
    }
    try {
        db()->exec(EXECUTIVE_MEETINGS_DDL);
        return $ready = true;
    } catch (\PDOException $e) {
        return $ready = false;
    }
}

function executive_meeting_number_normalise(string $number): string
{
    $n = trim($number);
    $n = preg_replace('/^(?:meeting|mtg)\b\.?\s*/i', '', $n);
    $n = preg_replace('/^(?:no|number)\b\.?\s*/i', '', $n);
    $n = ltrim($n, "# \t");
    return trim($n);
}

function executive_meetings_for_year(string $year): array
{
    if (!executive_meetings_ready()) {
        return [];
    }
    try {
        $stmt = db()->prepare(
            "SELECT m.*, u.name as admin_name
             FROM executive_meetings m
             LEFT JOIN users u ON u.id = m.created_by
             WHERE m.academic_year = ?
             ORDER BY m.meeting_date DESC, m.id DESC"
        );
        $stmt->execute([$year]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (\PDOException $e) {
        return [];
    }
}

function executive_meeting_count(string $year): int
{
    if (!executive_meetings_ready()) {
        return 0;
    }
    try {
        $stmt = db()->prepare("SELECT COUNT(*) FROM executive_meetings WHERE academic_year = ? AND status = 'Finished'");
        $stmt->execute([$year]);
        return (int) $stmt->fetchColumn();
    } catch (\PDOException $e) {
        return 0;
    }
}

function executive_meeting_latest(string $year): ?array
{
    if (!executive_meetings_ready()) {
        return null;
    }
    try {
        $stmt = db()->prepare(
            "SELECT m.*, u.name as admin_name
             FROM executive_meetings m
             LEFT JOIN users u ON u.id = m.created_by
             WHERE m.academic_year = ?
             ORDER BY m.meeting_date DESC, m.id DESC
             LIMIT 1"
        );
        $stmt->execute([$year]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (\PDOException $e) {
        return null;
    }
}

function executive_meeting_finish_and_lock(string $year, string $meetingNumber, string $meetingDate, ?string $notes, int $adminUserId): array
{
    $year = trim($year);
    if (!is_valid_academic_year($year)) {
        return [false, 'Invalid academic year.'];
    }

    $meetingNumber = executive_meeting_number_normalise($meetingNumber);
    if ($meetingNumber === '') {
        return [false, 'Please enter the Executive Meeting number (e.g. 1, 2, or Meeting #1).'];
    }
    if (mb_strlen($meetingNumber) > 50) {
        return [false, 'That meeting number is too long — use something like 3 or 3A.'];
    }

    $meetingDate = trim($meetingDate);
    $ts = $meetingDate === '' ? false : strtotime($meetingDate);
    if ($ts === false) {
        return [false, 'Please enter the date the meeting finished.'];
    }
    if (date('Y-m-d', $ts) > date('Y-m-d')) {
        return [false, 'The meeting date is in the future. Record a meeting once it has finished.'];
    }
    $meetingDate = date('Y-m-d', $ts);

    if (!executive_meetings_ready()) {
        return [false, 'Executive meetings cannot be saved: the executive_meetings table is missing and could not be created. Run sql/executive_meetings.sql on the database.'];
    }

    $dup = db()->prepare('SELECT COUNT(*) FROM executive_meetings WHERE academic_year = ? AND meeting_number = ?');
    $dup->execute([$year, $meetingNumber]);
    if ((int) $dup->fetchColumn() > 0) {
        return [false, "Meeting #{$meetingNumber} is already recorded for {$year}. Use the next number."];
    }

    $notes = trim((string) $notes);

    try {
        $stmt = db()->prepare(
            "INSERT INTO executive_meetings (academic_year, meeting_number, meeting_date, notes, status, locked_cycle, created_by)
             VALUES (?, ?, ?, ?, 'Finished', 1, ?)"
        );
        $stmt->execute([$year, $meetingNumber, $meetingDate, $notes ?: null, $adminUserId]);
    } catch (\PDOException $e) {
        return [false, 'The meeting could not be saved, so the year was not locked. Please try again.'];
    }

    $lockNote = "Locked upon completion of Executive Meeting #{$meetingNumber} on " . date('d M Y', strtotime($meetingDate));
    setting_set("ay_exec_meeting_{$year}", $meetingNumber, $adminUserId);
    academic_year_set_lock($year, true, $adminUserId, $lockNote);

    return [true, "Executive Meeting #{$meetingNumber} recorded as finished. Academic year {$year} cycle is now LOCKED for all roles."];
}

function executive_meeting_unlock(string $year, int $adminUserId, string $reason = ''): array
{
    $note = $reason ? trim($reason) : 'Cycle unlocked by Admin for submissions before next Executive Meeting';
    return academic_year_set_lock($year, false, $adminUserId, $note);
}

function metric_names(): array
{
    $rows = db()->query('SELECT name FROM metrics WHERE status = 1 ORDER BY name')->fetchAll(PDO::FETCH_COLUMN);
    return $rows ?: [];
}

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
    $hasDeadline = targets_deadline_ready();
    $dlCol = $hasDeadline ? 'target_deadline, ' : '';
    $dlVal = $hasDeadline ? 'NULL, ' : '';

    $sql = 'INSERT INTO targets (department, academic_year, sort_order, serial_no, metric, fixed_text, target_value, ' . $dlCol . 'achieved_value, status, created_by, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ' . $dlVal . '0, \'Draft\', ?, NOW(), NOW())';
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
