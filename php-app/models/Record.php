<?php
/**
 * Record data access — unified helpers for the 10 record types.
 * Used by upload.php and approvals.php.
 */

require_once __DIR__ . '/../inc/db.php';

/** All record types with their table names and display info. */
function record_types(): array
{
    return [
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
    ];
}

/** Fetch records for a given type, with optional filters. */
function records_list(string $type, ?string $department = null, ?string $status = null, ?int $createdBy = null): array
{
    $types = record_types();
    if (!isset($types[$type])) {
        return [];
    }

    $t = $types[$type];
    $hasDept = !in_array($type, ['internship', 'placement'], true);

    $sql = "SELECT * FROM `{$t['table']}` WHERE 1=1";
    $params = [];

    if ($department && $hasDept) {
        $sql .= ' AND department = ?';
        $params[] = $department;
    }
    if ($status) {
        $sql .= ' AND status = ?';
        $params[] = $status;
    }
    if ($createdBy !== null) {
        $sql .= ' AND created_by = ?';
        $params[] = $createdBy;
    }

    $sql .= ' ORDER BY created_at DESC';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
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
function report_records(array $user, ?string $department, ?string $status, ?string $type): array
{
    $types = record_types();

    // Only Admin and Director may look outside their own department.
    $isOversight = in_array($user['role'], ['Admin', 'Director'], true);
    $scopeDept   = $isOversight ? $department : ($user['department'] ?: null);

    // Faculty only ever see their own submissions.
    $onlyMine = ($user['role'] === 'Faculty') ? (int) $user['id'] : null;

    // One type, or all of them.
    $wanted = ($type && isset($types[$type])) ? [$type => $types[$type]] : $types;

    $all = [];

    foreach ($wanted as $key => $t) {
        // Internships and placements are student records with no department column.
        $hasDept = !in_array($key, ['internship', 'placement'], true);
        $dept    = $hasDept ? $scopeDept : null;

        foreach (records_list($key, $dept, $status, $onlyMine) as $row) {
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
    $blank   = ['total' => 0, 'Approved' => 0, 'Submitted' => 0, 'Draft' => 0, 'Rejected' => 0];
    $summary = array_fill_keys($userIds, $blank);

    $placeholders = implode(',', array_fill(0, count($userIds), '?'));

    foreach (record_types() as $t) {
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
function pending_records(?string $department = null): array
{
    $types = record_types();
    $all = [];

    foreach ($types as $key => $t) {
        $hasDept = !in_array($key, ['internship', 'placement'], true);

        $sql = "SELECT *, '{$key}' AS record_type FROM `{$t['table']}` WHERE status = 'Submitted'";
        $params = [];

        if ($department && $hasDept) {
            $sql .= ' AND department = ?';
            $params[] = $department;
        } elseif ($department && !$hasDept) {
            continue;
        }

        $sql .= ' ORDER BY created_at DESC';
        $stmt = db()->prepare($sql);
        $stmt->execute($params);

        foreach ($stmt as $row) {
            $row['_type_key']   = $key;
            $row['_type_label'] = $t['label'];
            $row['_title']      = $row[$t['title_col']] ?? '(untitled)';
            $all[] = $row;
        }
    }

    usort($all, fn($a, $b) => strtotime($b['created_at']) <=> strtotime($a['created_at']));
    return $all;
}

/**
 * Approve or reject a record.
 *
 * $scopeDept restricts the action to one department (an HoD may only review
 * their own). Passing null means no department restriction (Admin). The scope
 * is enforced in the WHERE clause, so an out-of-scope id simply matches no row
 * and cannot be flipped — the id in the POST is never trusted on its own.
 */
function record_review(string $type, int $id, string $action, ?string $remark, int $approvedBy, ?string $scopeDept = null): array
{
    $types = record_types();
    if (!isset($types[$type])) {
        return [false, 'Invalid record type.'];
    }

    if (!in_array($action, ['approve', 'reject'], true)) {
        return [false, 'Invalid review action.'];
    }

    $newStatus = ($action === 'approve') ? 'Approved' : 'Rejected';
    $table = $types[$type]['table'];

    // Internships and placements have no department column, so a
    // department-scoped reviewer (HoD) can never act on them.
    $hasDept = !in_array($type, ['internship', 'placement'], true);

    if ($scopeDept !== null && !$hasDept) {
        return [false, 'Record not found or outside your department.'];
    }

    $sql    = "UPDATE `$table` SET status = ?, review_remark = ?, approved_by = ? WHERE id = ? AND status = 'Submitted'";
    $params = [$newStatus, $remark ?: null, $approvedBy, $id];

    if ($scopeDept !== null) {
        $sql     .= ' AND department = ?';
        $params[] = $scopeDept;
    }

    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    if ($stmt->rowCount() === 0) {
        return [false, 'Record not found, already reviewed, or outside your department.'];
    }

    return [true, "Record {$newStatus}."];
}

/** Get all records by the current user across all types. */
function my_records(int $userId): array
{
    $types = record_types();
    $all = [];

    foreach ($types as $key => $t) {
        $stmt = db()->prepare("SELECT *, '{$key}' AS record_type FROM `{$t['table']}` WHERE created_by = ? ORDER BY created_at DESC");
        $stmt->execute([$userId]);

        foreach ($stmt as $row) {
            $row['_type_key']   = $key;
            $row['_type_label'] = $t['label'];
            $row['_title']      = $row[$t['title_col']] ?? '(untitled)';
            $all[] = $row;
        }
    }

    usort($all, fn($a, $b) => strtotime($b['created_at']) <=> strtotime($a['created_at']));
    return $all;
}
