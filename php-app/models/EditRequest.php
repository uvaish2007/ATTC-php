<?php
/**
 * Edit Request Model — Structured tickets for HOD 'Request Edit to Dean/Admin' workflow.
 */

require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/Target.php'; // active_academic_year()
require_once __DIR__ . '/Record.php'; // record_types()

/**
 * Ensure the edit_requests table exists.
 */
function edit_requests_table_init(): void
{
    static $initialized = false;
    if ($initialized) {
        return;
    }

    $sql = "CREATE TABLE IF NOT EXISTS `edit_requests` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `record_type` VARCHAR(50) NOT NULL,
        `record_id` INT NOT NULL,
        `faculty_name` VARCHAR(150) NULL,
        `department` VARCHAR(100) NOT NULL,
        `academic_year` VARCHAR(20) NOT NULL,
        `category` VARCHAR(100) NULL,
        `record_title` VARCHAR(255) NULL,
        `reason` TEXT NOT NULL,
        `correction` TEXT NOT NULL,
        `hod_comments` TEXT NULL,
        `requested_by` INT NOT NULL,
        `status` ENUM('Pending', 'Approved', 'Rejected', 'Completed') NOT NULL DEFAULT 'Pending',
        `processed_by` INT NULL,
        `processed_at` DATETIME NULL,
        `admin_comments` TEXT NULL,
        `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX `idx_record` (`record_type`, `record_id`),
        INDEX `idx_dept` (`department`),
        INDEX `idx_status` (`status`),
        INDEX `idx_year` (`academic_year`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

    try {
        db()->exec($sql);
        $initialized = true;
    } catch (\PDOException $e) {
        error_log('edit_requests_table_init failed: ' . $e->getMessage());
    }
}

/**
 * Check whether an active pending edit request exists for a record.
 */
function edit_request_has_active(string $recordType, int $recordId): bool
{
    edit_requests_table_init();
    try {
        $stmt = db()->prepare("SELECT COUNT(*) FROM `edit_requests` WHERE `record_type` = ? AND `record_id` = ? AND `status` = 'Pending'");
        $stmt->execute([$recordType, $recordId]);
        return ((int) $stmt->fetchColumn()) > 0;
    } catch (\PDOException $e) {
        return false;
    }
}

/**
 * Create a new structured edit request ticket.
 */
function edit_request_create(array $data): array
{
    edit_requests_table_init();

    $recordType   = trim((string) ($data['record_type'] ?? ''));
    $recordId     = (int) ($data['record_id'] ?? 0);
    $facultyName  = trim((string) ($data['faculty_name'] ?? ''));
    $department   = trim((string) ($data['department'] ?? ''));
    $academicYear = trim((string) ($data['academic_year'] ?? ''));
    $category     = trim((string) ($data['category'] ?? ''));
    $recordTitle  = trim((string) ($data['record_title'] ?? ''));
    $reason       = trim((string) ($data['reason'] ?? ''));
    $correction   = trim((string) ($data['correction'] ?? ''));
    $hodComments  = trim((string) ($data['hod_comments'] ?? ''));
    $requestedBy  = (int) ($data['requested_by'] ?? 0);

    if ($recordType === '' || $recordId <= 0) {
        return [false, 'Invalid record specified.'];
    }
    if ($reason === '') {
        return [false, 'Reason for edit is required.'];
    }
    if ($correction === '') {
        return [false, 'Requested correction is required.'];
    }
    if ($department === '') {
        return [false, 'Department is required.'];
    }
    if ($academicYear === '') {
        $academicYear = active_academic_year();
    }

    if (edit_request_has_active($recordType, $recordId)) {
        return [false, 'An active edit request is already pending for this record.'];
    }

    $types = record_types();
    if (!isset($types[$recordType])) {
        return [false, 'Unknown record type.'];
    }
    $table = $types[$recordType]['table'];

    try {
        $pdo = db();
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("INSERT INTO `edit_requests` 
            (`record_type`, `record_id`, `faculty_name`, `department`, `academic_year`, `category`, `record_title`, `reason`, `correction`, `hod_comments`, `requested_by`, `status`) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending')");
        $stmt->execute([
            $recordType,
            $recordId,
            $facultyName ?: null,
            $department,
            $academicYear,
            $category ?: $types[$recordType]['label'],
            $recordTitle ?: null,
            $reason,
            $correction,
            $hodComments ?: null,
            $requestedBy,
        ]);
        $requestId = (int) $pdo->lastInsertId();

        // Update target record status to 'Edit Requested'
        $updateSql = "UPDATE `{$table}` SET `status` = 'Edit Requested', `review_remark` = ?, `updated_at` = NOW() WHERE `id` = ?";
        $pdo->prepare($updateSql)->execute(["Edit Request #ER-{$requestId}: " . $reason, $recordId]);

        $pdo->commit();
        return [true, "Edit Request #ER-{$requestId} submitted to Dean/Admin successfully.", $requestId];
    } catch (\PDOException $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('edit_request_create failed: ' . $e->getMessage());
        return [false, 'Failed to create edit request. ' . $e->getMessage()];
    }
}

/**
 * Fetch a single edit request ticket with user names.
 */
function edit_request_get(int $id): ?array
{
    edit_requests_table_init();
    try {
        $sql = "SELECT er.*, 
                       u_req.name AS requester_name, u_req.email AS requester_email, u_req.role AS requester_role,
                       u_proc.name AS processor_name, u_proc.email AS processor_email, u_proc.role AS processor_role
                FROM `edit_requests` er
                LEFT JOIN `users` u_req ON u_req.id = er.requested_by
                LEFT JOIN `users` u_proc ON u_proc.id = er.processed_by
                WHERE er.id = ?
                LIMIT 1";
        $stmt = db()->prepare($sql);
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (\PDOException $e) {
        return null;
    }
}

/**
 * List edit request tickets with optional filters.
 *
 * Accepts either an associative array of filters:
 *   ['department' => ..., 'status' => ..., 'academic_year' => ..., 'requested_by' => ..., 'q' => ...]
 * or legacy/positional arguments:
 *   edit_requests_list(?string $dept, ?string $status, ?string $academic_year, ?int $requested_by)
 *
 * @param array|string|null $filters Filter array or department string
 * @param string|null $status Ticket status filter (when using positional args)
 * @param string|null $academicYear Academic year filter (when using positional args)
 * @param int|null $requestedBy Requester user ID filter (when using positional args)
 * @return array List of edit request rows
 */
function edit_requests_list($filters = [], ?string $status = null, ?string $academicYear = null, ?int $requestedBy = null): array
{
    edit_requests_table_init();

    if (!is_array($filters)) {
        $department = $filters;
        $filters = [];
        if (!empty($department)) { $filters['department'] = (string) $department; }
        if (!empty($status)) { $filters['status'] = (string) $status; }
        if (!empty($academicYear)) { $filters['academic_year'] = (string) $academicYear; }
        if (!empty($requestedBy)) { $filters['requested_by'] = (int) $requestedBy; }
    }

    $sql = "SELECT er.*, 
                   u_req.name AS requester_name, u_req.email AS requester_email,
                   u_proc.name AS processor_name, u_proc.email AS processor_email
            FROM `edit_requests` er
            LEFT JOIN `users` u_req ON u_req.id = er.requested_by
            LEFT JOIN `users` u_proc ON u_proc.id = er.processed_by
            WHERE 1=1";
    $params = [];

    if (!empty($filters['department'])) {
        $sql .= " AND er.department = ?";
        $params[] = $filters['department'];
    }

    if (!empty($filters['status']) && $filters['status'] !== 'all') {
        $sql .= " AND er.status = ?";
        $params[] = $filters['status'];
    }

    if (!empty($filters['academic_year']) && $filters['academic_year'] !== 'all') {
        $sql .= " AND er.academic_year = ?";
        $params[] = $filters['academic_year'];
    }

    if (!empty($filters['requested_by'])) {
        $sql .= " AND er.requested_by = ?";
        $params[] = (int) $filters['requested_by'];
    }

    if (!empty($filters['q'])) {
        $q = '%' . trim((string) $filters['q']) . '%';
        $sql .= " AND (er.faculty_name LIKE ? OR er.record_title LIKE ? OR er.reason LIKE ? OR er.correction LIKE ? OR er.department LIKE ? OR CONCAT('#ER-', er.id) LIKE ?)";
        $params = array_merge($params, [$q, $q, $q, $q, $q, $q]);
    }

    $sql .= " ORDER BY er.created_at DESC";

    try {
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    } catch (\PDOException $e) {
        error_log('edit_requests_list failed: ' . $e->getMessage());
        return [];
    }
}

/**
 * Process an edit request ticket: Approve (unlock for edit) or Reject.
 */
function edit_request_process(int $id, string $action, ?string $adminComments, int $processedBy, string $userRole): array
{
    edit_requests_table_init();

    if (!in_array($userRole, ['Admin', 'Dean'], true)) {
        return [false, 'Only Dean and Administrator can approve or reject edit requests.'];
    }

    if (!in_array($action, ['approve', 'reject', 'complete'], true)) {
        return [false, 'Invalid action specified.'];
    }

    $ticket = edit_request_get($id);
    if (!$ticket) {
        return [false, 'Edit request ticket not found.'];
    }

    if ($action !== 'complete' && $ticket['status'] !== 'Pending') {
        return [false, "Ticket #ER-{$id} has already been processed ({$ticket['status']})."];
    }

    $recordType = $ticket['record_type'];
    $recordId   = (int) $ticket['record_id'];
    $types      = record_types();
    if (!isset($types[$recordType])) {
        return [false, 'Associated record type is invalid.'];
    }
    $table = $types[$recordType]['table'];

    try {
        $pdo = db();
        $pdo->beginTransaction();

        $adminCommentsTrimmed = trim((string) $adminComments);

        if ($action === 'approve') {
            $newTicketStatus = 'Approved';
            $newRecordStatus = 'Unlocked for Edit';
            $remark = "Edit allowed by {$userRole}" . ($adminCommentsTrimmed ? ": {$adminCommentsTrimmed}" : '');

            // Update ticket
            $stmt = $pdo->prepare("UPDATE `edit_requests` SET `status` = ?, `processed_by` = ?, `processed_at` = NOW(), `admin_comments` = ?, `updated_at` = NOW() WHERE `id` = ?");
            $stmt->execute([$newTicketStatus, $processedBy, $adminCommentsTrimmed ?: null, $id]);

            // Update underlying record to 'Unlocked for Edit'
            $stmtRec = $pdo->prepare("UPDATE `{$table}` SET `status` = ?, `review_remark` = ?, `updated_at` = NOW() WHERE `id` = ?");
            $stmtRec->execute([$newRecordStatus, $remark, $recordId]);

            $pdo->commit();
            return [true, "Edit Request #ER-{$id} approved. Record unlocked for Coordinator/Faculty editing."];
        } elseif ($action === 'reject') {
            $newTicketStatus = 'Rejected';
            $newRecordStatus = 'Approved'; // Revert back to approved so record remains valid in reports
            $remark = "Edit request rejected by {$userRole}" . ($adminCommentsTrimmed ? ": {$adminCommentsTrimmed}" : '');

            // Update ticket
            $stmt = $pdo->prepare("UPDATE `edit_requests` SET `status` = ?, `processed_by` = ?, `processed_at` = NOW(), `admin_comments` = ?, `updated_at` = NOW() WHERE `id` = ?");
            $stmt->execute([$newTicketStatus, $processedBy, $adminCommentsTrimmed ?: null, $id]);

            // Revert underlying record status
            $stmtRec = $pdo->prepare("UPDATE `{$table}` SET `status` = ?, `review_remark` = ?, `updated_at` = NOW() WHERE `id` = ?");
            $stmtRec->execute([$newRecordStatus, $remark, $recordId]);

            $pdo->commit();
            return [true, "Edit Request #ER-{$id} rejected."];
        } elseif ($action === 'complete') {
            $stmt = $pdo->prepare("UPDATE `edit_requests` SET `status` = 'Completed', `updated_at` = NOW() WHERE `id` = ?");
            $stmt->execute([$id]);
            $pdo->commit();
            return [true, "Edit Request #ER-{$id} marked as Completed."];
        }

        $pdo->rollBack();
        return [false, 'Unknown operation.'];
    } catch (\PDOException $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('edit_request_process failed: ' . $e->getMessage());
        return [false, 'Failed to process edit request: ' . $e->getMessage()];
    }
}

/**
 * Mark any pending/approved edit request for a record as Completed (e.g. after edit is saved).
 */
function edit_request_mark_completed_for_record(string $recordType, int $recordId): void
{
    edit_requests_table_init();
    try {
        $stmt = db()->prepare("UPDATE `edit_requests` SET `status` = 'Completed', `updated_at` = NOW() WHERE `record_type` = ? AND `record_id` = ? AND `status` IN ('Pending', 'Approved')");
        $stmt->execute([$recordType, $recordId]);
    } catch (\PDOException $e) {
        error_log('edit_request_mark_completed_for_record failed: ' . $e->getMessage());
    }
}

/**
 * Count pending edit requests for navigation badge.
 */
function edit_requests_pending_count(array $user): int
{
    if (!in_array($user['role'], ['Admin', 'Dean', 'HoD'], true)) {
        return 0;
    }

    edit_requests_table_init();

    try {
        if ($user['role'] === 'HoD') {
            $dept = $user['department'] ?? '';
            $stmt = db()->prepare("SELECT COUNT(*) FROM `edit_requests` WHERE `status` = 'Pending' AND `department` = ?");
            $stmt->execute([$dept]);
            return (int) $stmt->fetchColumn();
        } else {
            // Admin and Dean see all pending tickets
            $stmt = db()->query("SELECT COUNT(*) FROM `edit_requests` WHERE `status` = 'Pending'");
            return (int) $stmt->fetchColumn();
        }
    } catch (\PDOException $e) {
        return 0;
    }
}

/**
 * Retrieve the full original record row for previewing in the review modal.
 */
function edit_request_original_record(string $recordType, int $recordId): ?array
{
    $types = record_types();
    if (!isset($types[$recordType])) {
        return null;
    }
    $table = $types[$recordType]['table'];

    try {
        $stmt = db()->prepare("SELECT * FROM `{$table}` WHERE `id` = ? LIMIT 1");
        $stmt->execute([$recordId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row) {
            $row['_type_key']   = $recordType;
            $row['_type_label'] = $types[$recordType]['label'];
            $row['_title']      = $row[$types[$recordType]['title_col']] ?? '(untitled)';
        }
        return $row ?: null;
    } catch (\PDOException $e) {
        return null;
    }
}

/**
 * Fetch a single edit request by ID (alias for edit_request_get).
 */
function edit_request_find(int $id): ?array
{
    return edit_request_get($id);
}

/**
 * Review an edit request (Dean or Admin approves or rejects).
 */
function edit_request_review(int $requestId, string $decision, ?string $comment, array $user): array
{
    $action = ($decision === 'approve') ? 'approve' : 'reject';
    return edit_request_process($requestId, $action, $comment, (int) ($user['id'] ?? 0), (string) ($user['role'] ?? 'Dean'));
}

/**
 * Mark edit request as completed upon Coordinator resubmission.
 */
function edit_request_complete(int $recordId, string $recordType, int $coordinatorId = 0, ?array $oldValues = null, ?array $newValues = null): void
{
    edit_request_mark_completed_for_record($recordType, $recordId);
}

