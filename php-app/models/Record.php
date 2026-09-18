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
        'journal'    => ['table' => 'journal_publications',    'label' => 'Journal Publication',    'title_col' => 'paper_title'],
        'book'       => ['table' => 'book_publications',       'label' => 'Book / Chapter',         'title_col' => 'title'],
        'conference' => ['table' => 'conference_publications', 'label' => 'Conference Publication', 'title_col' => 'paper_title'],
        'patent'     => ['table' => 'patents',                 'label' => 'Patent / Copyright',     'title_col' => 'title'],
        'fdp'        => ['table' => 'fdp',                     'label' => 'FDP / Workshop',         'title_col' => 'title'],
        'mou'        => ['table' => 'mou',                     'label' => 'MoU',                    'title_col' => 'organization'],
        'event'      => ['table' => 'events',                  'label' => 'Event',                  'title_col' => 'event_title'],
        'nptel'      => ['table' => 'nptel',                   'label' => 'NPTEL',                  'title_col' => 'course_title'],
        'internship' => ['table' => 'internships',             'label' => 'Internship',             'title_col' => 'title'],
        'placement'  => ['table' => 'placements',              'label' => 'Placement',              'title_col' => 'student_name'],

        // Types added straight from the IQAC templates.
        'nss'                   => ['table' => 'nss',                    'label' => 'NSS / YRC / RRC',           'title_col' => 'activity_name'],
        'online_course'         => ['table' => 'online_courses',         'label' => 'Online Course',             'title_col' => 'course_title'],
        'student_achievement'   => ['table' => 'student_achievements',   'label' => 'Student Achievement',       'title_col' => 'student_name'],
        'student_participation' => ['table' => 'student_participations', 'label' => 'Student Participation',      'title_col' => 'student_name'],
        'summer_training'       => ['table' => 'summer_training',        'label' => 'Summer / Winter Training',  'title_col' => 'title'],
        'value_added'           => ['table' => 'value_added_courses',    'label' => 'Value Added Course',        'title_col' => 'course_title'],
        'training'              => ['table' => 'training',               'label' => 'Training Programme',        'title_col' => 'event_title'],
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
 * Fetch records for a given type, with optional filters.
 *
 * $year scopes to one academic year — the active one, from every caller —
 * but only for tables that actually carry an academic_year column; a table
 * that doesn't (e.g. fdp, mou, nptel) is returned unfiltered by year, the
 * same convention all_metrics() already uses on the dashboard.
 */
function records_list(string $type, ?string $department = null, ?string $status = null, ?int $createdBy = null, ?string $from = null, ?string $to = null, ?string $year = null): array
{
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
function report_records(array $user, ?string $department, ?string $status, ?string $type, ?string $from = null, ?string $to = null, ?string $year = null): array
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
        $scopeDept = $user['department'] ?: null;
    }

    // Faculty only ever see their own submissions.
    $onlyMine = ($user['role'] === 'Faculty') ? (int) $user['id'] : null;

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

    if (!in_array($action, ['approve', 'reject', 'request_edit', 'approve_edit'], true)) {
        return [false, 'Invalid review action.'];
    }

    // BUG-WF-11: Strict RBAC for record reviews
    if (!in_array($userRole, ['Coordinator', 'Admin', 'Dean', 'HoD'], true) || $userRole === 'Faculty') {
        return [false, 'Access Denied: Your role is not authorized to approve or review records.'];
    }

    // HoD can NEVER approve or reject records directly
    if ($userRole === 'HoD' && in_array($action, ['approve', 'reject'], true)) {
        return [false, 'HoD is a reviewer only and cannot approve or reject submitted records. To request changes, use Request Edit to Dean.'];
    }

    $effectiveYear = $year ?: active_academic_year();
    if ($userRole !== 'Admin' && academic_year_is_locked($effectiveYear)) {
        return [false, "Academic year {$effectiveYear} cycle is locked by Administrator. Record reviews are frozen for all roles."];
    }

    $table = $types[$type]['table'];

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
        // HoD can only request edit
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

    $inClause = implode(',', array_fill(0, count($validCurrent), '?'));
    $sql      = "UPDATE `$table` SET status = ?, review_remark = ?, approved_by = ?, updated_at = NOW() WHERE id = ? AND status IN ($inClause)";
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

    foreach (record_types() as $t) {
        try {
            $sql    = "UPDATE `{$t['table']}` SET status = ?, approved_by = ?, updated_at = NOW()
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
            : 'Nothing pending in ' . $department . '.'];
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
 * Create an edit request from HoD to Dean.
 * HoD department is auto-determined from authenticated user session.
 */
function edit_request_create(array $data, array $user): array
{
    if (!in_array($user['role'], ['HoD', 'Admin'], true)) {
        return [false, 'Only HoD or Admin can submit an Edit Request to Dean.'];
    }

    $type   = trim((string)($data['record_type'] ?? ''));
    $id     = (int)($data['record_id'] ?? 0);
    $reason = trim((string)($data['reason'] ?? ''));

    if ($reason === '') {
        return [false, 'A clear reason / explanation is required for the Edit Request.'];
    }

    $rec = record_find($type, $id);
    if (!$rec) {
        return [false, 'The specified record does not exist.'];
    }

    // HoD department is strictly enforced from the user profile
    $hodDept = $user['department'] ?? '';
    if ($user['role'] === 'HoD' && !empty($hodDept)) {
        if (!department_names_match($rec['department'] ?? '', $hodDept)) {
            return [false, 'Access Denied: You can only request edits for records in your own department (' . $hodDept . ').'];
        }
    }

    // Prevent duplicate or invalid edit requests
    if (($rec['status'] ?? '') === 'Edit Requested') {
        return [false, 'This record is already pending Dean review.'];
    }
    if (($rec['status'] ?? '') === 'Unlocked for Edit') {
        return [false, 'This record is already unlocked for Coordinator correction.'];
    }

    $existingPending = db()->prepare("SELECT id FROM edit_requests WHERE record_id = ? AND record_type = ? AND status = 'Pending' LIMIT 1");
    $existingPending->execute([$id, $type]);
    if ($existingPending->fetch()) {
        return [false, 'An Edit Request for this record is already pending Dean decision.'];
    }

    $academicYear = $rec['academic_year'] ?? active_academic_year();
    if ($user['role'] !== 'Admin' && academic_year_is_locked($academicYear)) {
        return [false, "Academic year {$academicYear} is locked. Edit requests are frozen."];
    }

    $types = record_types();
    if (!isset($types[$type])) {
        return [false, 'Invalid record type.'];
    }
    $table = $types[$type]['table'];

    // Check EM1 lock
    if (function_exists('em_locked_windows') && em_locked_windows($academicYear)) {
        if (em_record_is_locked($user['role'], $rec['created_at'] ?? null, $academicYear)) {
            return [false, EM1_LOCKED_MESSAGE];
        }
    }

    $facultyName = $rec['faculty_name'] ?? $rec['candidate_name'] ?? $rec['student_name'] ?? 'Faculty Member';
    $facultyId   = !empty($rec['created_by']) ? (int)$rec['created_by'] : null;
    $dept        = $rec['department'] ?? $hodDept;
    $title       = $rec['_title'] ?? '';

    $specificField = !empty($data['specific_field']) ? trim((string)$data['specific_field']) : null;
    $currentVal    = !empty($data['current_value']) ? trim((string)$data['current_value']) : null;
    $requestedVal  = !empty($data['requested_value']) ? trim((string)$data['requested_value']) : null;

    try {
        db()->beginTransaction();

        $stmt = db()->prepare(
            "INSERT INTO edit_requests (
                record_id, record_type, record_title, proof_file, academic_year, department, faculty_id, faculty_name,
                requested_by, requested_by_name, requested_by_role, reason, specific_field, current_value,
                requested_value, status, created_at
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending', NOW())"
        );
        $stmt->execute([
            $id,
            $type,
            $title,
            $rec['proof_file'] ?? null,
            $academicYear,
            $dept,
            $facultyId,
            $facultyName,
            (int)$user['id'],
            $user['name'] ?? 'HoD',
            $user['role'],
            $reason,
            $specificField,
            $currentVal,
            $requestedVal,
        ]);
        $requestId = (int)db()->lastInsertId();

        // Update record status to 'Edit Requested'
        $oldStatus = $rec['status'] ?? 'Approved';
        $upd = db()->prepare("UPDATE `{$table}` SET status = 'Edit Requested', review_remark = ?, updated_at = NOW() WHERE id = ?");
        $upd->execute(["Edit Request ER-{$requestId}: " . $reason, $id]);

        // Audit log
        record_workflow_audit(
            $type,
            $id,
            'HOD_EDIT_REQUESTED',
            $user,
            $oldStatus,
            'Edit Requested',
            $reason,
            ['request_id' => $requestId, 'specific_field' => $specificField, 'requested_value' => $requestedVal],
            $dept,
            $academicYear
        );

        db()->commit();
        return [true, "Edit Request ER-{$requestId} submitted to Dean for review."];
    } catch (\PDOException $e) {
        if (db()->inTransaction()) db()->rollBack();
        error_log('edit_request_create error: ' . $e->getMessage());
        return [false, 'Failed to create edit request: ' . $e->getMessage()];
    }
}

/**
 * List edit requests with optional filters.
 */
function edit_requests_list(?string $dept = null, ?string $status = null, ?string $year = null, ?int $requestedBy = null): array
{
    $sql = "SELECT er.*, u.email AS requested_by_email FROM edit_requests er
            LEFT JOIN users u ON er.requested_by = u.id
            WHERE 1=1";
    $params = [];

    if ($dept !== null && $dept !== '') {
        $sql .= " AND er.department = ?";
        $params[] = $dept;
    }
    if ($status !== null && $status !== '') {
        $sql .= " AND er.status = ?";
        $params[] = $status;
    }
    if ($year !== null && $year !== '') {
        $sql .= " AND er.academic_year = ?";
        $params[] = $year;
    }
    if ($requestedBy !== null && $requestedBy > 0) {
        $sql .= " AND er.requested_by = ?";
        $params[] = $requestedBy;
    }

    $sql .= " ORDER BY er.created_at DESC";

    try {
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (\PDOException $e) {
        error_log('edit_requests_list error: ' . $e->getMessage());
        return [];
    }
}

/**
 * Fetch a single edit request by ID.
 */
function edit_request_find(int $id): ?array
{
    try {
        $stmt = db()->prepare("SELECT * FROM edit_requests WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (\PDOException $e) {
        return null;
    }
}

/**
 * Review an edit request (Dean or Admin approves or rejects).
 */
function edit_request_review(int $requestId, string $decision, ?string $comment, array $user): array
{
    if (!in_array($user['role'], ['Dean', 'Admin'], true)) {
        return [false, 'Only Dean or Admin can approve or reject Edit Requests.'];
    }

    if (!in_array($decision, ['approve', 'reject'], true)) {
        return [false, 'Invalid decision.'];
    }

    $req = edit_request_find($requestId);
    if (!$req) {
        return [false, 'Edit Request not found.'];
    }

    if ($req['status'] !== 'Pending') {
        return [false, "This Edit Request has already been decided ({$req['status']})."];
    }

    $types = record_types();
    if (!isset($types[$req['record_type']])) {
        return [false, 'Invalid record type referenced in Edit Request.'];
    }
    $table = $types[$req['record_type']]['table'];

    try {
        db()->beginTransaction();

        $newReqStatus = ($decision === 'approve') ? 'Approved' : 'Rejected';
        $stmt = db()->prepare(
            "UPDATE edit_requests SET
                status = ?,
                decision_by = ?,
                decision_by_name = ?,
                decision_role = ?,
                decision_comment = ?,
                decided_at = NOW()
             WHERE id = ?"
        );
        $stmt->execute([
            $newReqStatus,
            (int)$user['id'],
            $user['name'] ?? 'Dean',
            $user['role'],
            $comment ?: null,
            $requestId,
        ]);

        if ($decision === 'approve') {
            // Unlocks record for Coordinator to edit
            $newRecordStatus = 'Unlocked for Edit';
            $note = $comment ?: "Dean approved HoD edit request ER-{$requestId}";
            $upd = db()->prepare("UPDATE `{$table}` SET status = ?, review_remark = ?, updated_at = NOW() WHERE id = ?");
            $upd->execute([$newRecordStatus, $note, $req['record_id']]);

            record_workflow_audit(
                $req['record_type'],
                $req['record_id'],
                'DEAN_EDIT_APPROVED',
                $user,
                'Edit Requested',
                'Unlocked for Edit',
                $comment,
                ['request_id' => $requestId, 'reason' => $req['reason']],
                $req['department'],
                $req['academic_year']
            );

            $msg = "Edit Request ER-{$requestId} approved. Record unlocked for Coordinator correction.";
        } else {
            // Rejects request; record remains Approved (unchanged)
            $newRecordStatus = 'Approved';
            $note = "Edit request ER-{$requestId} rejected by Dean: " . ($comment ?: 'No reason provided');
            $upd = db()->prepare("UPDATE `{$table}` SET status = ?, review_remark = ?, updated_at = NOW() WHERE id = ?");
            $upd->execute([$newRecordStatus, $note, $req['record_id']]);

            record_workflow_audit(
                $req['record_type'],
                $req['record_id'],
                'DEAN_EDIT_REJECTED',
                $user,
                'Edit Requested',
                'Approved',
                $comment,
                ['request_id' => $requestId, 'reason' => $req['reason']],
                $req['department'],
                $req['academic_year']
            );

            $msg = "Edit Request ER-{$requestId} rejected. Record remains unchanged.";
        }

        db()->commit();
        return [true, $msg];
    } catch (\PDOException $e) {
        if (db()->inTransaction()) db()->rollBack();
        error_log('edit_request_review error: ' . $e->getMessage());
        return [false, 'Failed to process decision: ' . $e->getMessage()];
    }
}

/**
 * Mark edit request as completed upon Coordinator resubmission.
 */
function edit_request_complete(int $recordId, string $recordType, int $coordinatorId, ?array $oldValues = null, ?array $newValues = null): void
{
    try {
        $stmt = db()->prepare(
            "UPDATE edit_requests SET status = 'Completed', authorized_coordinator_id = ?, completed_at = NOW()
             WHERE record_id = ? AND record_type = ? AND status = 'Approved'"
        );
        $stmt->execute([$coordinatorId, $recordId, $recordType]);
    } catch (\PDOException $e) {
        error_log('edit_request_complete error: ' . $e->getMessage());
    }
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
 * Dean or Admin direct 'Request Edit' from Dual View without forcing direct approval or silent overwrite.
 * Unlocks the record for Coordinator correction and creates an authorized edit request ticket.
 */
function record_request_edit_by_dean(string $type, int $id, array $user, string $reason, ?string $specificField = null, ?string $currentVal = null, ?string $requestedVal = null): array
{
    if (!in_array($user['role'], ['Dean', 'Admin'], true)) {
        return [false, 'Only Dean or Administrator can request edits on records in dual view.'];
    }

    $reason = trim($reason);
    if ($reason === '') {
        return [false, 'A clear reason / instruction for the edit request is required.'];
    }

    $rec = record_find($type, $id);
    if (!$rec) {
        return [false, 'Record not found.'];
    }

    $academicYear = $rec['academic_year'] ?? active_academic_year();
    if ($user['role'] !== 'Admin' && academic_year_is_locked($academicYear)) {
        return [false, "Academic year {$academicYear} cycle is locked by Administrator. Workflow is frozen."];
    }

    $types = record_types();
    if (!isset($types[$type])) {
        return [false, 'Invalid record type.'];
    }
    $table = $types[$type]['table'];

    $facultyName = $rec['faculty_name'] ?? $rec['candidate_name'] ?? $rec['student_name'] ?? 'Faculty Member';
    $facultyId   = !empty($rec['created_by']) ? (int)$rec['created_by'] : null;
    $dept        = $rec['department'] ?? ($user['department'] ?? 'General');
    $title       = $rec['_title'] ?? '';

    try {
        db()->beginTransaction();

        // 1. Create or update the edit_requests entry with status 'Approved' (since Dean/Admin is the authority)
        $stmtExisting = db()->prepare("SELECT id FROM edit_requests WHERE record_id = ? AND record_type = ? ORDER BY id DESC LIMIT 1");
        $stmtExisting->execute([$id, $type]);
        $existingReqId = (int) $stmtExisting->fetchColumn();

        if ($existingReqId > 0) {
            $stmtUpd = db()->prepare(
                "UPDATE edit_requests SET
                    status = 'Approved',
                    decision_by = ?,
                    decision_by_name = ?,
                    decision_role = ?,
                    decision_comment = ?,
                    decided_at = NOW(),
                    reason = ?,
                    specific_field = ?,
                    current_value = ?,
                    requested_value = ?
                 WHERE id = ?"
            );
            $stmtUpd->execute([
                (int)$user['id'],
                $user['name'] ?? $user['role'],
                $user['role'],
                $reason,
                $reason,
                $specificField ?: null,
                $currentVal ?: null,
                $requestedVal ?: null,
                $existingReqId
            ]);
            $requestId = $existingReqId;
        } else {
            $stmtIns = db()->prepare(
                "INSERT INTO edit_requests (
                    record_id, record_type, record_title, proof_file, academic_year, department, faculty_id, faculty_name,
                    requested_by, requested_by_name, requested_by_role, reason, specific_field, current_value,
                    requested_value, status, decision_by, decision_by_name, decision_role, decision_comment, decided_at, created_at
                 ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Approved', ?, ?, ?, ?, NOW(), NOW())"
            );
            $stmtIns->execute([
                $id,
                $type,
                $title,
                $rec['proof_file'] ?? null,
                $academicYear,
                $dept,
                $facultyId,
                $facultyName,
                (int)$user['id'],
                $user['name'] ?? $user['role'],
                $user['role'],
                $reason,
                $specificField ?: null,
                $currentVal ?: null,
                $requestedVal ?: null,
                (int)$user['id'],
                $user['name'] ?? $user['role'],
                $user['role'],
                $reason,
            ]);
            $requestId = (int)db()->lastInsertId();
        }

        // 2. Unlock record for Coordinator correction
        $oldStatus = $rec['status'] ?? 'Approved';
        $newStatus = 'Unlocked for Edit';
        $rolePrefix = ($user['role'] === 'Dean') ? 'Dean Note: ' : 'Admin Note: ';
        $remark = $rolePrefix . $reason . ($specificField ? " (Target Field: {$specificField})" : '');

        $updRec = db()->prepare("UPDATE `{$table}` SET status = ?, review_remark = ?, updated_at = NOW() WHERE id = ?");
        $updRec->execute([$newStatus, $remark, $id]);

        // 3. Workflow audit trail
        $auditAction = ($user['role'] === 'Dean') ? 'DEAN_REQUESTED_CORRECTION' : 'ADMIN_REQUESTED_CORRECTION';
        record_workflow_audit(
            $type,
            $id,
            $auditAction,
            $user,
            $oldStatus,
            $newStatus,
            $reason,
            [
                'request_id' => $requestId,
                'specific_field' => $specificField,
                'requested_by_role' => $user['role'],
                'unlocked_for' => 'Coordinator',
            ],
            $dept,
            $academicYear
        );

        db()->commit();

        return [
            true,
            "Edit requested successfully for Record #{$id}. The record is now Unlocked for Coordinator correction without direct approval or silent override."
        ];
    } catch (\PDOException $e) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        return [false, 'Failed to request edit: ' . $e->getMessage()];
    }
}

/**
 * Returns formatted human-readable field labels and values for an entry,
 * using IQAC report specifications when available.
 */
function record_display_attributes(string $type, array $record): array
{
    require_once __DIR__ . '/../inc/record_specs.php';
    $specs = function_exists('record_report_specs') ? record_report_specs() : [];
    $typeSpec = $specs[$type] ?? null;

    $knownLabels = [];
    if ($typeSpec && !empty($typeSpec['columns'])) {
        foreach ($typeSpec['columns'] as $colDef) {
            $field = $colDef[1] ?? '';
            $lbl   = $colDef[0] ?? '';
            if ($field && $field !== '#' && $lbl && $lbl !== 'S.No') {
                $knownLabels[$field] = $lbl;
            }
        }
    }

    // Common column labels
    $fallbackLabels = [
        'faculty_name'        => 'Faculty Name',
        'department'          => 'Department',
        'academic_year'       => 'Academic Year',
        'author_type'         => 'Author Type',
        'co_authors'          => 'Co-Authors',
        'paper_title'         => 'Paper Title',
        'title'               => 'Title',
        'journal_name'        => 'Journal Name',
        'journal_type'        => 'Journal Type',
        'issn'                => 'ISSN',
        'isbn'                => 'ISBN',
        'volume_issue'        => 'Volume & Issue',
        'publication_month'   => 'Publication Month',
        'doi'                 => 'DOI / Link',
        'journal_link'        => 'Journal Website Link',
        'document_link'       => 'Document / Proof Link',
        'certificate_link'    => 'Certificate Link',
        'report_link'         => 'Report Link',
        'event_title'         => 'Event Title',
        'event_type'          => 'Event Type',
        'start_date'          => 'Start Date',
        'end_date'            => 'End Date',
        'participants_count'  => 'Participants Count',
        'organization'        => 'Organization / Agency',
        'student_name'        => 'Student Name',
        'company_name'        => 'Company / Institution',
        'salary_package'      => 'Package / Stipend',
        'course_title'        => 'Course Title',
        'score'               => 'Score / Grade',
        'activity_name'       => 'Activity Name',
    ];

    $ignoreCols = [
        'id', 'created_at', 'updated_at', 'created_by', 'approved_by', 'status',
        'review_remark', 'proof_file', '_type_key', '_type_label', '_title'
    ];

    $attributes = [];
    foreach ($record as $k => $v) {
        if (in_array($k, $ignoreCols, true)) {
            continue;
        }
        $label = $knownLabels[$k] ?? $fallbackLabels[$k] ?? ucwords(str_replace('_', ' ', $k));
        $attributes[] = [
            'key'   => $k,
            'label' => $label,
            'value' => (string)($v ?? ''),
        ];
    }

    return $attributes;
}
