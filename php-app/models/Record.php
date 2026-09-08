<?php
/**
 * Record data access — unified helpers for the 10 record types.
 * Used by upload.php and approvals.php.
 */

require_once __DIR__ . '/../inc/db.php';

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

/** Fetch records for a given type, with optional filters. */
function records_list(string $type, ?string $department = null, ?string $status = null, ?int $createdBy = null, ?string $from = null, ?string $to = null): array
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
        $sql .= ' AND department = ?';
        $params[] = $department;
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
function report_records(array $user, ?string $department, ?string $status, ?string $type, ?string $from = null, ?string $to = null): array
{
    $types = record_types();

    // Admin may look at any department; Director only ever at the whole
    // institution (never one department); everyone else is pinned to their own.
    if ($user['role'] === 'Admin') {
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
        foreach (records_list($key, $scopeDept, $status, $onlyMine, $from, $to) as $row) {
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

/** Get all pending records across all types for approval view. */
function pending_records(?string $department = null, ?string $stage = null, ?string $role = null): array
{
    $types = record_types();
    $all = [];

    if ($role === 'HoD' || $stage === 'HOD Pending') {
        $targetStatuses = ['HOD Pending', 'Submitted'];
    } elseif ($role === 'Dean' || $stage === 'Dean Pending') {
        $targetStatuses = ['Dean Pending'];
    } else {
        if ($stage === 'HOD Pending') {
            $targetStatuses = ['HOD Pending', 'Submitted'];
        } elseif ($stage === 'Dean Pending') {
            $targetStatuses = ['Dean Pending'];
        } else {
            $targetStatuses = ['Dean Pending', 'HOD Pending', 'Submitted'];
        }
    }

    $inClause = implode(',', array_fill(0, count($targetStatuses), '?'));

    foreach ($types as $key => $t) {
        $sql = "SELECT *, '{$key}' AS record_type FROM `{$t['table']}` WHERE status IN ($inClause)";
        $params = $targetStatuses;

        if ($department) {
            $sql .= ' AND department = ?';
            $params[] = $department;
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
 * Approve or reject a record.
 *
 * $scopeDept restricts the action to one department (an HoD may only review
 * their own). Passing null means no department restriction (Admin/Dean).
 */
function record_review(string $type, int $id, string $action, ?string $remark, int $approvedBy, ?string $scopeDept = null, string $userRole = 'Admin'): array
{
    $types = record_types();
    if (!isset($types[$type])) {
        return [false, 'Invalid record type.'];
    }

    if (!in_array($action, ['approve', 'reject'], true)) {
        return [false, 'Invalid review action.'];
    }

    $table = $types[$type]['table'];

    if ($userRole === 'HoD') {
        $validCurrent = ['HOD Pending', 'Submitted'];
        $newStatus    = ($action === 'approve') ? 'Dean Pending' : 'Rejected';
    } elseif ($userRole === 'Dean') {
        $validCurrent = ['Dean Pending'];
        $newStatus    = ($action === 'approve') ? 'Approved' : 'Rejected';
    } else {
        $validCurrent = ['HOD Pending', 'Dean Pending', 'Submitted'];
        $newStatus    = ($action === 'approve') ? 'Approved' : 'Rejected';
    }

    $inClause = implode(',', array_fill(0, count($validCurrent), '?'));
    $sql      = "UPDATE `$table` SET status = ?, review_remark = ?, approved_by = ? WHERE id = ? AND status IN ($inClause)";
    $params   = array_merge([$newStatus, $remark ?: null, $approvedBy, $id], $validCurrent);

    if ($scopeDept !== null) {
        $sql     .= ' AND department = ?';
        $params[] = $scopeDept;
    }

    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    if ($stmt->rowCount() === 0) {
        return [false, 'Record not found, already reviewed, or outside your department scope.'];
    }

    if ($newStatus === 'Approved') {
        require_once __DIR__ . '/Target.php';
        sync_target_achieved_for_type($type);
    }

    $msgStatus = ($newStatus === 'Dean Pending') ? 'approved by HOD and submitted for Dean review' : $newStatus;
    return [true, "Record {$msgStatus}."];
}

/**
 * Approve every pending record of a department in one go.
 */
function records_bulk_approve(string $department, int $approvedBy, ?string $scopeDept = null, string $userRole = 'HoD'): array
{
    $department = trim($department);
    if ($department === '') {
        return [false, 'No department given.'];
    }
    if ($scopeDept !== null && $scopeDept !== $department) {
        return [false, 'You can only approve your own department.'];
    }

    if ($userRole === 'HoD') {
        $validCurrent = ['HOD Pending', 'Submitted'];
        $newStatus    = 'Dean Pending';
    } else {
        $validCurrent = ['Dean Pending'];
        $newStatus    = 'Approved';
    }

    $inClause = implode(',', array_fill(0, count($validCurrent), '?'));
    $total = 0;

    foreach (record_types() as $t) {
        try {
            $stmt = db()->prepare(
                "UPDATE `{$t['table']}` SET status = ?, approved_by = ?
                 WHERE status IN ($inClause) AND department = ?"
            );
            $params = array_merge([$newStatus, $approvedBy], $validCurrent, [$department]);
            $stmt->execute($params);
            $total += $stmt->rowCount();
        } catch (\PDOException $e) {
            continue;
        }
    }

    if ($total === 0) {
        return [false, 'Nothing pending to approve in ' . $department . '.'];
    }

    if ($newStatus === 'Approved') {
        require_once __DIR__ . '/Target.php';
        sync_all_target_achieved();
    }

    $msgStatus = ($newStatus === 'Dean Pending') ? 'submitted for Dean review' : 'approved';
    return [true, "Approved {$total} record" . ($total === 1 ? '' : 's') . " in {$department} ({$msgStatus})."];
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
