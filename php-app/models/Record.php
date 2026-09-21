<?php
/**
 * Record data access — unified helpers for the 10 record types.
 * Used by upload.php and approvals.php.
 */

require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/Target.php';   // active_academic_year(), target_record_table_columns()
require_once __DIR__ . '/ExecutiveMeeting.php';   // FEAT-07 EM1 lock

/** All record types with their table names and display info. */
function record_types(): array
{
    static $filtered = null;
    if ($filtered !== null) {
        return $filtered;
    }

    $all = [
        'journal'    => ['table' => 'journal_publications',    'label' => 'Journal Publication',    'title_col' => 'paper_title', 'approval_required' => true],
        'book'       => ['table' => 'book_publications',       'label' => 'Book / Chapter',         'title_col' => 'title',       'approval_required' => false],
        'conference' => ['table' => 'conference_publications', 'label' => 'Conference Publication', 'title_col' => 'paper_title', 'approval_required' => true],
        'patent'     => ['table' => 'patents',                 'label' => 'Patent / Copyright',     'title_col' => 'title',       'approval_required' => true],
        'fdp'        => ['table' => 'fdp',                     'label' => 'FDP / Workshop',         'title_col' => 'title',       'approval_required' => true],
        'mou'        => ['table' => 'mou',                     'label' => 'MoU',                    'title_col' => 'organization','approval_required' => true],
        'event'      => ['table' => 'events',                  'label' => 'Event',                  'title_col' => 'event_title', 'approval_required' => true],
        'nptel'      => ['table' => 'nptel',                   'label' => 'NPTEL',                  'title_col' => 'course_title','approval_required' => true],
        'internship' => ['table' => 'internships',             'label' => 'Internship',             'title_col' => 'title',       'approval_required' => true],
        'placement'  => ['table' => 'placements',              'label' => 'Placement',              'title_col' => 'student_name','approval_required' => true],

        // Types added straight from the IQAC templates.
        'nss'                   => ['table' => 'nss',                    'label' => 'NSS / YRC / RRC',           'title_col' => 'activity_name', 'approval_required' => true],
        'online_course'         => ['table' => 'online_courses',         'label' => 'Online Course',             'title_col' => 'course_title',  'approval_required' => true],
        'student_achievement'   => ['table' => 'student_achievements',   'label' => 'Student Achievement',       'title_col' => 'student_name',  'approval_required' => true],
        'student_participation' => ['table' => 'student_participations', 'label' => 'Student Participation',      'title_col' => 'student_name',  'approval_required' => true],
        'summer_training'       => ['table' => 'summer_training',        'label' => 'Summer / Winter Training',  'title_col' => 'title',         'approval_required' => true],
        'value_added'           => ['table' => 'value_added_courses',    'label' => 'Value Added Course',        'title_col' => 'course_title',  'approval_required' => true],
        'training'              => ['table' => 'training',               'label' => 'Training Programme',        'title_col' => 'event_title',   'approval_required' => true],
    ];

    try {
        $dbTables = array_map('strtolower', db()->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN));
        $filtered = array_filter($all, fn($item) => in_array(strtolower($item['table']), $dbTables, true));
    } catch (\PDOException $e) {
        $filtered = $all;
    }

    return $filtered;
}

/**
 * Check whether a given record type requires approval.
 * Book / Chapter ('book') does NOT require approval (COORD-14).
 */
function record_requires_approval(string $type): bool
{
    $types = record_types();
    if (isset($types[$type]['approval_required'])) {
        return (bool) $types[$type]['approval_required'];
    }
    return $type !== 'book';
}

/**
 * The three categories records are grouped under, and which types belong to
 * each. Same grouping the dashboard's "Records by Category" card uses (see
 * all_metrics() in models/Dashboard.php), but keyed by record type so the
 * Reports page can be narrowed to one category from a dashboard link.
 *
 * Types the database doesn't have are dropped, exactly as record_types() does.
 */
function record_categories(): array
{
    $cats = [
        'faculty'  => ['label' => 'Faculty Contributions', 'icon' => 'file-text',
                       'types' => ['journal', 'book', 'conference', 'patent', 'fdp', 'mou', 'nptel', 'online_course']],
        'activity' => ['label' => 'Activities & Outreach', 'icon' => 'calendar',
                       'types' => ['event', 'nss', 'value_added', 'training']],
        'student'  => ['label' => 'Student Records',       'icon' => 'users',
                       'types' => ['internship', 'placement', 'summer_training', 'student_achievement', 'student_participation']],
    ];

    $known = record_types();
    foreach ($cats as $key => $cat) {
        $cats[$key]['types'] = array_values(array_filter($cat['types'], fn($t) => isset($known[$t])));
    }

    return $cats;
}

/** The record types in one category, or every type when the category is unknown. */
function record_category_types(?string $category): array
{
    $cats = record_categories();
    return isset($cats[$category]) ? $cats[$category]['types'] : array_keys(record_types());
}

/**
 * Process 7-day approval expiration for Journal Publications (COORD-13).
 *
 * Lifecycle:
 * Submitted -> Approved -> (7 days valid) -> Expired -> Submitted.
 *
 * When an approved Journal Publication exceeds 7 days from approved_at:
 * - Archives previous approval metadata (approved_by, approved_at, previous status,
 *   reviewer details, remarks, expired_at) into `approval_history` JSON.
 * - Reverts current status to 'Submitted'.
 * - Clears active approved_at, approved_by, review_remark.
 * - Preserves academic_year and all other row fields intact without duplication.
 * - Non-destructive: does not delete records or past history.
 * - Syncs journal target metrics if any records expired.
 *
 * @param int|null $specificId If provided, processes expiry for this specific journal ID.
 * @param bool $forceCheck If true, bypasses in-memory per-request cache.
 * @return int Number of journal publication records transitioned to 'Submitted'.
 */
function journal_process_approval_expiry(?int $specificId = null, bool $forceCheck = false): int
{
    static $checkedThisRequest = false;
    if ($specificId === null && $checkedThisRequest && !$forceCheck) {
        return 0;
    }

    $pdo = db();
    $sql = "SELECT id, status, approved_by, approved_at, review_remark, approval_history, academic_year, department
            FROM journal_publications
            WHERE status = 'Approved'
              AND approved_at IS NOT NULL
              AND approved_at <= DATE_SUB(NOW(), INTERVAL 7 DAY)";
    $params = [];

    if ($specificId !== null) {
        $sql .= " AND id = ?";
        $params[] = $specificId;
    } else {
        $checkedThisRequest = true;
    }

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $expiredRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($expiredRows)) {
            return 0;
        }

        $now = date('Y-m-d H:i:s');
        $expiredCount = 0;

        // User details cache for reviewer information archival
        static $userCache = [];
        $getUserInfo = function(?int $uid) use ($pdo, &$userCache): array {
            if (!$uid) return ['name' => null, 'role' => null];
            if (!isset($userCache[$uid])) {
                $uStmt = $pdo->prepare("SELECT name, role FROM users WHERE id = ?");
                $uStmt->execute([$uid]);
                $userCache[$uid] = $uStmt->fetch(PDO::FETCH_ASSOC) ?: ['name' => null, 'role' => null];
            }
            return $userCache[$uid];
        };

        $pdo->beginTransaction();

        $updateStmt = $pdo->prepare(
            "UPDATE journal_publications
             SET status = 'Submitted',
                 approved_by = NULL,
                 approved_at = NULL,
                 review_remark = NULL,
                 approval_history = ?,
                 updated_at = NOW()
             WHERE id = ? AND status = 'Approved'"
        );

        foreach ($expiredRows as $row) {
            $recId = (int)$row['id'];
            $history = [];
            if (!empty($row['approval_history'])) {
                $decoded = json_decode((string)$row['approval_history'], true);
                if (is_array($decoded)) {
                    $history = $decoded;
                }
            }

            $revInfo = $getUserInfo(!empty($row['approved_by']) ? (int)$row['approved_by'] : null);

            $archiveEntry = [
                'status'         => 'Approved',
                'approved_by'    => !empty($row['approved_by']) ? (int)$row['approved_by'] : null,
                'approved_at'    => $row['approved_at'],
                'reviewer_name'  => $revInfo['name'] ?? null,
                'reviewer_role'  => $revInfo['role'] ?? null,
                'review_remark'  => $row['review_remark'] ?? null,
                'expired_at'     => $now,
            ];

            $history[] = $archiveEntry;
            $encodedHistory = json_encode($history, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $updateStmt->execute([$encodedHistory, $recId]);
            if ($updateStmt->rowCount() > 0) {
                $expiredCount++;
            }
        }

        $pdo->commit();

        if ($expiredCount > 0) {
            try {
                require_once __DIR__ . '/Target.php';
                if (function_exists('sync_target_achieved_for_type')) {
                    sync_target_achieved_for_type('journal');
                }
            } catch (\Throwable $e) {
                error_log('sync_target_achieved_for_type error during journal expiry: ' . $e->getMessage());
            }
        }

        return $expiredCount;
    } catch (\PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('journal_process_approval_expiry PDOException: ' . $e->getMessage());
        return 0;
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('journal_process_approval_expiry error: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Retrieve the archived approval history for a record.
 */
function record_approval_history(string $type, int $id): array
{
    $types = record_types();
    if (!isset($types[$type])) {
        return [];
    }
    $table = $types[$type]['table'];
    try {
        $stmt = db()->prepare("SELECT approval_history FROM `{$table}` WHERE id = ?");
        $stmt->execute([$id]);
        $raw = $stmt->fetchColumn();
        if (!$raw) {
            return [];
        }
        $decoded = json_decode((string)$raw, true);
        return is_array($decoded) ? $decoded : [];
    } catch (\Throwable $e) {
        return [];
    }
}

/**
 * Fetch records for a given type, with optional filters.
 *
 * $year scopes to one academic year — the active one, from every caller —
 * but only for tables that actually carry an academic_year column; a table
 * that doesn't (e.g. fdp, mou, nptel) is returned unfiltered by year, the
 * same convention all_metrics() already uses on the dashboard.
 */
function records_list(string $type, ?string $department = null, ?string $status = null, ?int $createdBy = null, ?string $from = null, ?string $to = null, ?string $year = null): array
{
    if ($type === 'journal' || $type === '') {
        journal_process_approval_expiry();
    }
    $types = record_types();
    if (!isset($types[$type])) {
        return [];
    }

    $t = $types[$type];
    // Every record table now carries a department column (internships and
    // placements gained a "Dept / Branch" to match their templates), so a
    // department filter applies uniformly.
    $hasDept = true;

    $sql = "SELECT * FROM `{$t['table']}` WHERE 1=1";
    $params = [];

    if ($department && $hasDept) {
        $deptVars = department_variants($department);
        if (!empty($deptVars)) {
            $inPh = implode(',', array_fill(0, count($deptVars), '?'));
            $sql .= " AND department IN ($inPh)";
            foreach ($deptVars as $v) {
                $params[] = $v;
            }
        } else {
            $sql .= ' AND (department = ? OR REPLACE(department, " ", "") = REPLACE(?, " ", ""))';
            $params[] = $department;
            $params[] = $department;
        }
    }
    if ($year !== null && in_array('academic_year', target_record_table_columns($t['table']), true)) {
        $ayVars = academic_year_variants($year);
        $ayPlaceholders = implode(',', array_fill(0, count($ayVars), '?'));
        $sql .= " AND academic_year IN ($ayPlaceholders)";
        foreach ($ayVars as $v) {
            $params[] = $v;
        }
    }
    if ($status) {
        if ($status === 'Submitted' || $status === 'Pending') {
            $sql .= " AND status IN ('HOD Pending', 'Dean Pending', 'Submitted')";
        } else {
            $sql .= ' AND status = ?';
            $params[] = $status;
        }
    }
    if ($createdBy !== null) {
        $sql .= ' AND created_by = ?';
        $params[] = $createdBy;
    }
    // Period filter, on the submission date every record type shares. $to is
    // pushed to the end of its day so the range is inclusive.
    if ($from) {
        $sql .= ' AND created_at >= ?';
        $params[] = $from . ' 00:00:00';
    }
    if ($to) {
        $sql .= ' AND created_at <= ?';
        $params[] = $to . ' 23:59:59';
    }

    $sql .= ' ORDER BY created_at DESC';
    try {
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    } catch (\PDOException $e) {
        return [];
    }
}

/**
 * Records for the Reports page and for the file downloads.
 *
 * Applies the filters the user picked, and keeps each role inside its own
 * scope: Admin/Director see everything, HoD/Coordinator only their own
 * department, and Faculty only the records they themselves submitted.
 *
 * Returns one flat list, newest first, with a few helper keys added:
 *   _type_key, _type_label, _title, _person
 */
function report_records(array $user, ?string $department, ?string $status, ?string $type, ?string $from = null, ?string $to = null, ?string $year = null, bool $departmentWide = false): array
{
    $types = record_types();

    // Admin and Dean may look at any department (or all, when none is picked);
    // Director only ever at the whole institution (never one department);
    // everyone else is pinned to their own department.
    if ($user['role'] === 'Director' || $user['role'] === 'Principal') {
        $scopeDept = null;
    } elseif ($user['role'] === 'Admin' || $user['role'] === 'Dean') {
        $scopeDept = $department;
    } else {
        $scopeDept = $user['department'] ?: '__UNASSIGNED_DEPT__';
    }

    // Faculty only ever see their own submissions in personal reports.
    // In department presentation mode ($departmentWide = true), all department records are included.
    $onlyMine = ($user['role'] === 'Faculty' && !$departmentWide) ? (int) $user['id'] : null;

    // One type, or all of them.
    $wanted = ($type && isset($types[$type])) ? [$type => $types[$type]] : $types;

    $all = [];

    foreach ($wanted as $key => $t) {
        // Every table now has a department column, so each type is scoped to the
        // caller's department (student records carry a "Dept / Branch" too).
        foreach (records_list($key, $scopeDept, $status, $onlyMine, $from, $to, $year) as $row) {
            $row['_type_key']   = $key;
            $row['_type_label'] = $t['label'];
            $row['_title']      = $row[$t['title_col']] ?? '(untitled)';
            $row['_person']     = $row['faculty_name']
                ?? $row['student_name']
                ?? $row['candidate_name']
                ?? '';
            $all[] = $row;
        }
    }

    usort($all, fn($a, $b) => strtotime($b['created_at']) <=> strtotime($a['created_at']));

    return $all;
}

/**
 * How many records each person has submitted, split by status.
 *
 * Pass the ids you care about (a department's staff, or just one person) and
 * you get back one row per id:
 *
 *   [ 7 => ['total' => 12, 'Approved' => 9, 'Submitted' => 2,
 *           'Draft' => 1,  'Rejected' => 0], ... ]
 *
 * Counting by created_by rather than by department means student records
 * (internships, placements), which have no department column, are included.
 */
function record_counts_for_users(array $userIds): array
{
    journal_process_approval_expiry();

    $userIds = array_values(array_unique(array_map('intval', $userIds)));

    if (empty($userIds)) {
        return [];
    }

    // Start every person at zero so the page never has to check for gaps.
    $blank   = ['total' => 0, 'Approved' => 0, 'Dean Pending' => 0, 'HOD Pending' => 0, 'Submitted' => 0, 'Draft' => 0, 'Rejected' => 0];
    $summary = array_fill_keys($userIds, $blank);

    $placeholders = implode(',', array_fill(0, count($userIds), '?'));

    foreach (record_types() as $t) {
        try {
            $stmt = db()->prepare(
                "SELECT created_by, status, COUNT(*) AS n
                 FROM `{$t['table']}`
                 WHERE created_by IN ($placeholders)
                 GROUP BY created_by, status"
            );
            $stmt->execute($userIds);

            foreach ($stmt as $row) {
                $id     = (int) $row['created_by'];
                $status = $row['status'] ?: 'Draft';
                $n      = (int) $row['n'];

                $summary[$id]['total'] += $n;

                if (isset($summary[$id][$status])) {
                    $summary[$id][$status] += $n;
                }
            }
        } catch (\PDOException $e) {
            continue;
        }
    }

    return $summary;
}

/** The same counts for a single person (used on the Profile page). */
function user_record_counts(int $userId): array
{
    $counts = record_counts_for_users([$userId]);

    return $counts[$userId] ?? ['total' => 0, 'Approved' => 0, 'Submitted' => 0, 'Draft' => 0, 'Rejected' => 0];
}

/**
 * Get all pending records across all types for approval view.
 *
 * $year scopes the queue to one academic year (the active one, from every
 * caller) — section 9: a Coordinator/HoD/Dean approval queue must never show
 * a pending record from a year other than the one ATTS is currently on.
 * Tables without an academic_year column are unaffected by $year.
 */
function pending_records(?string $department = null, ?string $stage = null, ?string $role = null, ?string $year = null): array
{
    journal_process_approval_expiry();

    $types = record_types();
    $all = [];

    if ($role === 'Coordinator' || $stage === 'Submitted') {
        $targetStatuses = ['Submitted', 'Unlocked for Edit'];
    } elseif ($role === 'HoD' || $stage === 'HOD Pending') {
        $targetStatuses = ['Approved', 'Submitted', 'Edit Requested', 'HOD Pending', 'Resubmitted', 'Unlocked for Edit'];
    } elseif ($role === 'Dean' || $stage === 'Dean Pending') {
        $targetStatuses = ['Edit Requested', 'Dean Pending'];
    } else {
        $targetStatuses = ['Submitted', 'Edit Requested', 'Dean Pending', 'HOD Pending', 'Unlocked for Edit', 'Resubmitted'];
    }

    $inClause = implode(',', array_fill(0, count($targetStatuses), '?'));

    foreach ($types as $key => $t) {
        if (!record_requires_approval($key)) {
            continue; // Book / Chapter does not enter approval queues (COORD-14)
        }
        $sql = "SELECT *, '{$key}' AS record_type FROM `{$t['table']}` WHERE status IN ($inClause)";
        $params = $targetStatuses;

        if ($department) {
            $sql .= ' AND (department = ? OR REPLACE(department, " ", "") = REPLACE(?, " ", ""))';
            $params[] = $department;
            $params[] = $department;
        }
        if ($year !== null && in_array('academic_year', target_record_table_columns($t['table']), true)) {
            $ayVars = academic_year_variants($year);
            $ayPlaceholders = implode(',', array_fill(0, count($ayVars), '?'));
            $sql .= " AND academic_year IN ($ayPlaceholders)";
            foreach ($ayVars as $v) {
                $params[] = $v;
            }
        }

        $sql .= ' ORDER BY created_at DESC';
        try {
            $stmt = db()->prepare($sql);
            $stmt->execute($params);

            foreach ($stmt as $row) {
                $row['_type_key']   = $key;
                $row['_type_label'] = $t['label'];
                $row['_title']      = $row[$t['title_col']] ?? '(untitled)';
                $all[] = $row;
            }
        } catch (\PDOException $e) {
            continue;
        }
    }

    usort($all, fn($a, $b) => strtotime($b['created_at']) <=> strtotime($a['created_at']));
    return $all;
}

/**
 * Approve, reject, or request edit for a record.
 * Strict RBAC: HoD CANNOT approve or reject records directly.
 */
function record_review(string $type, int $id, string $action, ?string $remark, int $approvedBy, ?string $scopeDept = null, string $userRole = 'Admin', ?string $year = null): array
{
    $types = record_types();
    if (!isset($types[$type])) {
        return [false, 'Invalid record type.'];
    }

    if (!record_requires_approval($type)) {
        return [false, "{$types[$type]['label']} does not require approval."];
    }

    if (!in_array($action, ['approve', 'reject', 'request_edit', 'approve_edit'], true)) {
        return [false, 'Invalid review action.'];
    }

    // BUG-WF-11: Strict RBAC for record reviews
    if (!in_array($userRole, ['Coordinator', 'Admin', 'Dean', 'HoD'], true) || $userRole === 'Faculty') {
        return [false, 'Access Denied: Your role is not authorized to approve or review records.'];
    }

    // HoD can NEVER approve or reject records directly
    if ($userRole === 'HoD' && in_array($action, ['approve', 'reject', 'approve_edit'], true)) {
        return [false, 'HOD is not authorized to directly approve or reject records. Please use the Request Edit to Dean/Admin workflow.'];
    }

    $effectiveYear = $year ?: active_academic_year();
    if ($userRole !== 'Admin' && academic_year_is_locked($effectiveYear)) {
        return [false, "Academic year {$effectiveYear} cycle is locked by Administrator. Record reviews are frozen for all roles."];
    }

    $table = $types[$type]['table'];

    // Review & Edit Request Chain:
    // 1. Faculty uploads -> status 'Submitted'
    // 2. Coordinator approves -> status 'Approved' (direct to DB, syncs targets)
    // 3. HoD requests edit to Dean -> status 'Edit Requested'
    // 4. Dean approves edit request -> status 'Unlocked for Edit'
    // 5. Coordinator edits and resubmits -> status 'Approved'

    // FEAT-07: a record submitted during EM1 is read-only once EM1 has closed.
    if (function_exists('em_locked_windows') && em_locked_windows($effectiveYear)) {
        $lookup = db()->prepare("SELECT created_at FROM `$table` WHERE id = ?");
        $lookup->execute([$id]);
        if (em_record_is_locked($userRole, $lookup->fetchColumn() ?: null, $effectiveYear)) {
            return [false, EM1_LOCKED_MESSAGE];
        }
    }

    if ($userRole === 'Coordinator') {
        $validCurrent = ['Submitted', 'Unlocked for Edit'];
        $newStatus    = ($action === 'reject') ? 'Rejected' : 'Approved';
    } elseif ($userRole === 'HoD') {
        if ($action !== 'request_edit') {
            return [false, 'HOD can only submit Edit Requests to Dean/Admin.'];
        }
        $validCurrent = ['Approved', 'Submitted', 'HOD Pending', 'Dean Pending'];
        $newStatus    = 'Edit Requested';
    } elseif ($userRole === 'Dean') {
        $validCurrent = ['Edit Requested', 'Dean Pending'];
        $newStatus    = ($action === 'reject') ? 'Approved' : 'Unlocked for Edit';
    } else { // Admin
        $validCurrent = ['Submitted', 'Edit Requested', 'Dean Pending', 'HOD Pending', 'Approved', 'Unlocked for Edit'];
        if ($action === 'request_edit') {
            $newStatus = 'Edit Requested';
        } elseif ($action === 'approve_edit') {
            $newStatus = 'Unlocked for Edit';
        } elseif ($action === 'reject') {
            $newStatus = 'Rejected';
        } else {
            $newStatus = 'Approved';
        }
    }

    $recBefore = record_find($type, $id);
    $oldStatus = $recBefore['status'] ?? 'Unknown';

    if ($type === 'journal') {
        journal_process_approval_expiry($id, true);
    }

    $tableCols     = target_record_table_columns($table);
    $hasApprovedAt = in_array('approved_at', $tableCols, true);
    $approvedAtSql = '';
    if ($hasApprovedAt) {
        $approvedAtSql = ($newStatus === 'Approved') ? ', approved_at = NOW()' : ', approved_at = NULL';
    }

    $inClause = implode(',', array_fill(0, count($validCurrent), '?'));
    $sql      = "UPDATE `$table` SET status = ?, review_remark = ?, approved_by = ?{$approvedAtSql}, updated_at = NOW() WHERE id = ? AND status IN ($inClause)";
    $params   = array_merge([$newStatus, $remark ?: null, $approvedBy, $id], $validCurrent);

    if ($scopeDept !== null) {
        $sql     .= ' AND (department = ? OR REPLACE(department, " ", "") = REPLACE(?, " ", ""))';
        $params[] = $scopeDept;
        $params[] = $scopeDept;
    }
    if ($year !== null && in_array('academic_year', target_record_table_columns($table), true)) {
        $ayVars = academic_year_variants($year);
        $ayPlaceholders = implode(',', array_fill(0, count($ayVars), '?'));
        $sql .= " AND academic_year IN ($ayPlaceholders)";
        foreach ($ayVars as $v) {
            $params[] = $v;
        }
    }
    // FEAT-07: never touch a row inside a locked EM window (Admin exempt).
    $sql .= em_locked_exclusion_sql($userRole, $effectiveYear, $params);

    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    if ($stmt->rowCount() === 0) {
        return [false, 'Record not found, already reviewed, outside your department scope, or not in the active academic year.'];
    }

    // Write audit log
    record_workflow_audit(
        $type,
        $id,
        $userRole === 'Coordinator' ? 'COORDINATOR_APPROVED' : ($newStatus === 'Edit Requested' ? 'HOD_EDIT_REQUESTED' : 'RECORD_REVIEWED'),
        ['id' => $approvedBy, 'name' => $userRole, 'role' => $userRole, 'department' => $scopeDept],
        $oldStatus,
        $newStatus,
        $remark,
        ['action' => $action],
        $scopeDept,
        $effectiveYear
    );

    if ($newStatus === 'Approved') {
        require_once __DIR__ . '/Target.php';
        sync_target_achieved_for_type($type);
    }

    $msgStatus = match ($newStatus) {
        'Edit Requested'    => 'submitted as Edit Request to Dean',
        'Unlocked for Edit' => 'unlocked by Dean for Coordinator to edit',
        'Approved'          => 'approved and saved to database',
        default             => strtolower($newStatus),
    };
    return [true, "Record {$msgStatus}."];
}

/**
 * Approve every pending record of a department in one go.
 * HoD is BLOCKED from bulk approval.
 */
function records_bulk_approve(string $department, int $approvedBy, ?string $scopeDept = null, string $userRole = 'HoD', ?string $year = null): array
{
    // HoD cannot approve records
    if ($userRole === 'HoD') {
        return [false, 'Bulk approvals are not permitted for HoD. HoD is a reviewer only.'];
    }

    $department = trim($department);
    if ($department === '') {
        return [false, 'No department given.'];
    }
    if ($scopeDept !== null && $scopeDept !== $department) {
        return [false, 'You can only approve your own department.'];
    }

    $effectiveYear = $year ?: active_academic_year();
    if ($userRole !== 'Admin' && academic_year_is_locked($effectiveYear)) {
        return [false, "Academic year {$effectiveYear} cycle is locked by Administrator. Approvals are frozen for all roles."];
    }

    if ($userRole === 'HoD') {
        return [false, 'HOD is not authorized to directly approve or bulk-approve records.'];
    }

    if ($userRole === 'Coordinator') {
        $validCurrent = ['Submitted'];
        $newStatus    = 'Approved';
    } else {
        $validCurrent = ['Edit Requested', 'Dean Pending', 'HOD Pending', 'Submitted'];
        $newStatus    = 'Approved';
    }

    $inClause = implode(',', array_fill(0, count($validCurrent), '?'));
    $total = 0;

    $lockedWindows = ($userRole !== 'Admin') ? em_locked_windows($effectiveYear) : [];
    $heldBack      = 0;

    foreach (record_types() as $key => $t) {
        if (!record_requires_approval($key)) {
            continue;
        }
        try {
            $tCols = target_record_table_columns($t['table']);
            $hasApprovedAt = in_array('approved_at', $tCols, true);
            $approvedAtSql = ($hasApprovedAt && $newStatus === 'Approved') ? ', approved_at = NOW()' : '';

            $sql    = "UPDATE `{$t['table']}` SET status = ?, approved_by = ?{$approvedAtSql}, updated_at = NOW()
                        WHERE status IN ($inClause) AND department = ?";
            $params = array_merge([$newStatus, $approvedBy], $validCurrent, [$department]);
            if ($year !== null && in_array('academic_year', target_record_table_columns($t['table']), true)) {
                $sql      .= ' AND academic_year = ?';
                $params[]  = $year;
            }

            if ($lockedWindows) {
                $countSql    = "SELECT COUNT(*) FROM `{$t['table']}` WHERE status IN ($inClause) AND department = ?";
                $countParams = array_merge($validCurrent, [$department]);
                if ($year !== null && in_array('academic_year', target_record_table_columns($t['table']), true)) {
                    $countSql     .= ' AND academic_year = ?';
                    $countParams[] = $year;
                }
                $inWindow = [];
                foreach ($lockedWindows as $w) {
                    $inWindow[]    = '(created_at >= ? AND created_at <= ?)';
                    $countParams[] = $w['from'];
                    $countParams[] = $w['to'];
                }
                $countStmt = db()->prepare($countSql . ' AND (' . implode(' OR ', $inWindow) . ')');
                $countStmt->execute($countParams);
                $heldBack += (int) $countStmt->fetchColumn();
            }

            $sql .= em_locked_exclusion_sql($userRole, $effectiveYear, $params);

            $stmt = db()->prepare($sql);
            $stmt->execute($params);
            $total += $stmt->rowCount();
        } catch (\PDOException $e) {
            continue;
        }
    }

    if ($total === 0) {
        return [false, !empty($heldBack)
            ? EM1_LOCKED_MESSAGE . " {$heldBack} pending EM1 record" . ($heldBack === 1 ? ' was' : 's were') . ' left unchanged.'
            : 'Nothing pending to approve in ' . $department . '.'];
    }

    if ($newStatus === 'Approved') {
        require_once __DIR__ . '/Target.php';
        sync_all_target_achieved();
    }

    return [true, "Approved and saved {$total} record" . ($total === 1 ? '' : 's') . " in {$department} directly to database."
        . ($heldBack ? " {$heldBack} pending EM1 record" . ($heldBack === 1 ? ' was' : 's were') . ' left unchanged because EM1 is locked.' : '')];
}

/**
 * Log a workflow action into workflow_audit_logs table.
 */
function record_workflow_audit(string $type, int $id, string $action, array $user, ?string $oldStatus = null, ?string $newStatus = null, ?string $reason = null, ?array $details = null, ?string $dept = null, ?string $year = null): void
{
    try {
        $stmt = db()->prepare(
            "INSERT INTO workflow_audit_logs (record_id, record_type, action, user_id, user_name, user_role, department, academic_year, old_status, new_status, reason, details, ip_address, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())"
        );
        $stmt->execute([
            $id,
            $type,
            $action,
            (int) ($user['id'] ?? 0),
            $user['name'] ?? null,
            $user['role'] ?? 'Unknown',
            $dept ?? ($user['department'] ?? null),
            $year ?? active_academic_year(),
            $oldStatus !== null ? substr($oldStatus, 0, 50) : null,
            $newStatus !== null ? substr($newStatus, 0, 50) : null,
            $reason,
            $details ? json_encode($details) : null,
            $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (\PDOException $e) {
        error_log('Failed to write workflow audit log: ' . $e->getMessage());
    }
}

/**
 * Fetch a single record row by its type and ID.
 */
function record_find(string $type, int $id): ?array
{
    $types = record_types();
    if (!isset($types[$type])) {
        return null;
    }
    $table = $types[$type]['table'];
    try {
        $stmt = db()->prepare("SELECT *, '{$type}' AS _type_key FROM `{$table}` WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        $row['_type_label'] = $types[$type]['label'];
        $row['_title'] = $row[$types[$type]['title_col']] ?? '(untitled)';
        return $row;
    } catch (\PDOException $e) {
        return null;
    }
}

/**
 * Check if the user is authorized to edit a specific record.
 * Coordinator can only edit records from their department with status 'Unlocked for Edit'.
 */
function can_edit_record(string $type, int $id, array $user): array
{
    $rec = record_find($type, $id);
    if (!$rec) {
        return [false, 'Record not found.', null];
    }
    if ($user['role'] === 'Admin') {
        return [true, 'Admin edit permitted.', $rec];
    }
    if ($user['role'] === 'Coordinator') {
        if (!empty($user['department']) && !department_names_match($rec['department'] ?? '', $user['department'])) {
            return [false, 'Access Denied: You cannot edit records belonging to another department.', $rec];
        }
        if (($rec['status'] ?? '') !== 'Unlocked for Edit') {
            return [false, 'This record is not currently unlocked for editing. An approved Edit Request from Dean is required.', $rec];
        }
        return [true, 'Coordinator authorized correction permitted.', $rec];
    }
    return [false, 'Direct record editing is not permitted for your role. Contact your Coordinator or Dean.', $rec];
}

/**
 * HoD acknowledges the review of a corrected/resubmitted record.
 * Status becomes 'Approved' and audit event HOD_REVIEW_ACKNOWLEDGED is logged.
 */
function record_acknowledge_review(string $type, int $id, array $user): array
{
    if ($user['role'] !== 'HoD' && $user['role'] !== 'Admin') {
        return [false, 'Only HoD or Admin can acknowledge record review.'];
    }
    $rec = record_find($type, $id);
    if (!$rec) {
        return [false, 'Record not found.'];
    }
    $hodDept = $user['department'] ?? '';
    if ($user['role'] === 'HoD' && !empty($hodDept) && !department_names_match($rec['department'] ?? '', $hodDept)) {
        return [false, 'Access Denied: Record belongs to another department.'];
    }
    $types = record_types();
    if (!isset($types[$type])) {
        return [false, 'Invalid record type.'];
    }
    $table = $types[$type]['table'];

    try {
        $oldStatus = $rec['status'] ?? 'Resubmitted';
        $newStatus = 'Approved';
        $remark = 'Review completed and acknowledged by HoD ' . ($user['name'] ?? '');
        $stmt = db()->prepare("UPDATE `{$table}` SET status = ?, review_remark = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$newStatus, $remark, $id]);

        record_workflow_audit(
            $type,
            $id,
            'HOD_REVIEW_ACKNOWLEDGED',
            $user,
            $oldStatus,
            $newStatus,
            $remark,
            ['acknowledged_by' => $user['name'] ?? 'HoD', 'role' => $user['role']],
            $rec['department'] ?? $hodDept,
            $rec['academic_year'] ?? active_academic_year()
        );

        return [true, 'Record review acknowledged and confirmed in database.'];
    } catch (\PDOException $e) {
        return [false, 'Failed to acknowledge review: ' . $e->getMessage()];
    }
}

/** Get all records by the current user across all types. */
function my_records(int $userId): array
{
    journal_process_approval_expiry();

    $types = record_types();
    $all = [];

    foreach ($types as $key => $t) {
        try {
            $stmt = db()->prepare("SELECT *, '{$key}' AS record_type FROM `{$t['table']}` WHERE created_by = ? ORDER BY created_at DESC");
            $stmt->execute([$userId]);

            foreach ($stmt as $row) {
                $row['_type_key']   = $key;
                $row['_type_label'] = $t['label'];
                $row['_title']      = $row[$t['title_col']] ?? '(untitled)';
                $all[] = $row;
            }
        } catch (\PDOException $e) {
            continue;
        }
    }

    usort($all, fn($a, $b) => strtotime($b['created_at']) <=> strtotime($a['created_at']));
    return $all;
}

/**
 * Transition a record from Draft (or Unlocked for Edit) to its canonical review status.
 * Canonical transition:
 * - Faculty: 'Draft' -> 'Submitted' (lands in Coordinator queue)
 * - Coordinator: 'Draft' -> 'HOD Pending' (lands in HoD queue)
 * - HoD / Admin: 'Draft' -> 'Approved'
 *
 * Checks authentication, role authorization, department, active academic year,
 * uses a database transaction, and validates that rowCount() > 0.
 */
function record_submit_for_review(string $type, int $id, array $user, ?array $fieldsData = null, ?string $proofFile = null): array
{
    $types = record_types();
    if (!isset($types[$type])) {
        return [false, 'Invalid record type.'];
    }

    $activeYear = active_academic_year();
    if ($user['role'] !== 'Admin' && academic_year_is_locked($activeYear)) {
        return [false, "Academic year {$activeYear} cycle is locked by Administrator. Record submissions are frozen."];
    }

    $table = $types[$type]['table'];
    $pdo = db();

    // Determine canonical target status (Book / Chapter does not require approval: COORD-14)
    if (!record_requires_approval($type)) {
        $targetStatus = 'Submitted';
    } elseif (in_array($user['role'], ['HoD', 'Admin'], true)) {
        $targetStatus = 'Approved';
    } elseif ($user['role'] === 'Coordinator') {
        $targetStatus = 'HOD Pending';
    } else {
        $targetStatus = 'Submitted';
    }

    try {
        $pdo->beginTransaction();

        // 1. Fetch current record
        $stmt = $pdo->prepare("SELECT * FROM `{$table}` WHERE id = ? FOR UPDATE");
        $stmt->execute([$id]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$record) {
            $pdo->rollBack();
            return [false, 'Record not found.'];
        }

        // 2. Authorization and ownership check
        if ($user['role'] === 'Faculty') {
            if ((int)($record['created_by'] ?? 0) !== (int)$user['id']) {
                $pdo->rollBack();
                return [false, 'You do not have permission to submit this record for review.'];
            }
        } elseif ($user['role'] === 'Coordinator') {
            if (!empty($user['department']) && !empty($record['department']) && $record['department'] !== $user['department'] && (int)($record['created_by'] ?? 0) !== (int)$user['id']) {
                $pdo->rollBack();
                return [false, 'Record is outside your department scope.'];
            }
        }

        // 3. Academic year check
        if (isset($record['academic_year']) && $record['academic_year'] !== $activeYear && $user['role'] !== 'Admin') {
            $pdo->rollBack();
            return [false, "Record belongs to academic year {$record['academic_year']}, but the active academic year is {$activeYear}."];
        }

        // 4. Status eligibility check: Draft or Unlocked for Edit
        $validFromStatuses = ['Draft', 'Unlocked for Edit'];
        if (!in_array($record['status'], $validFromStatuses, true) && $record['status'] !== $targetStatus) {
            $pdo->rollBack();
            return [false, "Record is currently in status '{$record['status']}' and cannot be submitted for review."];
        }

        // 5. Build UPDATE parameters
        $setPairs = ["`status` = ?", "`updated_at` = NOW()"];
        $params = [$targetStatus];

        $tableColumns = $pdo->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_COLUMN);

        if ($targetStatus === 'Approved') {
            if (in_array('approved_at', $tableColumns, true)) {
                $setPairs[] = "`approved_at` = NOW()";
            }
            if (in_array('approved_by', $tableColumns, true)) {
                $setPairs[] = "`approved_by` = ?";
                $params[] = (int)$user['id'];
            }
        }

        if ($fieldsData !== null && is_array($fieldsData)) {
            $protected = ['id', 'created_by', 'status', 'approved_by', 'review_remark', 'created_at', 'updated_at', 'academic_year'];
            $allowed = array_diff($tableColumns, $protected);

            foreach ($fieldsData as $k => $v) {
                if (!in_array($k, $allowed, true) || $v === '') continue;
                $safeKey = str_replace('`', '``', $k);
                $setPairs[] = "`{$safeKey}` = ?";
                $params[] = $v;
            }
        }

        if ($proofFile !== null && in_array('proof_file', $tableColumns, true)) {
            $setPairs[] = "`proof_file` = ?";
            $params[] = $proofFile;
        }

        // Build WHERE clause
        $sql = "UPDATE `{$table}` SET " . implode(', ', $setPairs) . " WHERE id = ?";
        $params[] = $id;

        if ($user['role'] === 'Faculty') {
            $sql .= " AND created_by = ?";
            $params[] = (int)$user['id'];
        } elseif ($user['role'] === 'Coordinator' && !empty($user['department'])) {
            $sql .= " AND (department = ? OR created_by = ?)";
            $params[] = $user['department'];
            $params[] = (int)$user['id'];
        }

        $updateStmt = $pdo->prepare($sql);
        $updateStmt->execute($params);

        if ($updateStmt->rowCount() === 0 && $record['status'] !== $targetStatus) {
            $pdo->rollBack();
            return [false, 'Failed to update record status. The record was not modified.'];
        }

        // If target is Approved, sync target metrics
        if ($targetStatus === 'Approved') {
            require_once __DIR__ . '/Target.php';
            sync_target_achieved_for_type($type);
        }

        $pdo->commit();
        $msg = !record_requires_approval($type) ? 'Record submitted successfully.' : 'Record submitted for review successfully.';
        return [true, $msg];
    } catch (\PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('record_submit_for_review PDOException: ' . $e->getMessage());
        return [false, 'Failed to submit record for review due to a database error. Please check the fields and try again.'];
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('record_submit_for_review failed: ' . $e->getMessage());
        return [false, 'An error occurred while submitting the record for review. Please try again.'];
    }
}

/**
 * Canonical Record class wrapper for object-oriented callers and test suites.
 */
class Record
{
    public static function report_records(array $user, ?string $department, ?string $status, ?string $type, ?string $from = null, ?string $to = null, ?string $year = null, bool $departmentWide = false): array
    {
        return report_records($user, $department, $status, $type, $from, $to, $year, $departmentWide);
    }

    public static function academic_records(array $user, ?string $department = null, ?string $status = null, ?string $from = null, ?string $to = null, ?string $year = null): array
    {
        return report_records($user, $department, $status, null, $from, $to, $year);
    }

    public static function list(string $type, ?string $department = null, ?string $status = null, ?int $createdBy = null, ?string $from = null, ?string $to = null, ?string $year = null): array
    {
        return records_list($type, $department, $status, $createdBy, $from, $to, $year);
    }

    public static function types(): array
    {
        return record_types();
    }

    public static function categories(): array
    {
        return record_categories();
    }

    public static function process_journal_expiry(?int $specificId = null, bool $forceCheck = false): int
    {
        return journal_process_approval_expiry($specificId, $forceCheck);
    }

    public static function approval_history(string $type, int $id): array
    {
        return record_approval_history($type, $id);
    }

    public static function requires_approval(string $type): bool
    {
        return record_requires_approval($type);
    }
}
