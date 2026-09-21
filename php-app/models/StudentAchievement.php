<?php
/**
 * Student Achievement Data Access Model — aggregated helpers across student record types.
 * Connects directly to persisted MySQL achievement tables and respects role-based scoping.
 */

require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/Record.php';
require_once __DIR__ . '/Target.php';

/**
 * Returns all active record categories and metric types for student achievement reporting.
 */
function student_achievement_categories(): array
{
    return [
        'internship' => [
            'key'         => 'internship',
            'label'       => 'Internship',
            'col_name'    => 'internships',
            'group'       => 'Internships',
            'table'       => 'internships',
            'title_col'   => 'title',
            'student_col' => 'student_name',
            'reg_col'     => 'reg_no',
            'date_col'    => 'created_at',
        ],
        'placement' => [
            'key'         => 'placement',
            'label'       => 'Placement',
            'col_name'    => 'placements',
            'group'       => 'Placements',
            'table'       => 'placements',
            'title_col'   => 'job_title',
            'student_col' => 'student_name',
            'reg_col'     => 'reg_no',
            'date_col'    => 'created_at',
        ],
        'student_achievement' => [
            'key'         => 'student_achievement',
            'label'       => 'Student Achievement',
            'col_name'    => 'achievements',
            'group'       => 'Achievements',
            'table'       => 'student_achievements',
            'title_col'   => 'event_name',
            'student_col' => 'student_name',
            'reg_col'     => 'reg_no',
            'date_col'    => 'event_date',
        ],
        'student_participation' => [
            'key'         => 'student_participation',
            'label'       => 'Student Participation',
            'col_name'    => 'participation',
            'group'       => 'Participation',
            'table'       => 'student_participations',
            'title_col'   => 'event_name',
            'student_col' => 'student_name',
            'reg_col'     => 'reg_no',
            'date_col'    => 'event_date',
        ],
        'summer_training' => [
            'key'         => 'summer_training',
            'label'       => 'Summer / Winter Training',
            'col_name'    => 'training',
            'group'       => 'Training',
            'table'       => 'summer_training',
            'title_col'   => 'title',
            'student_col' => 'student_name',
            'reg_col'     => 'reg_no',
            'date_col'    => 'created_at',
        ],
        'nptel' => [
            'key'         => 'nptel',
            'label'       => 'SWAYAM-NPTEL',
            'col_name'    => 'nptel',
            'group'       => 'NPTEL',
            'table'       => 'nptel',
            'title_col'   => 'course_title',
            'student_col' => 'candidate_name',
            'reg_col'     => null,
            'where_extra' => "category = 'Student'",
            'date_col'    => 'created_at',
        ],
        'online_course' => [
            'key'         => 'online_course',
            'label'       => 'Online Courses',
            'col_name'    => 'online_courses',
            'group'       => 'Online Courses',
            'table'       => 'online_courses',
            'title_col'   => 'course_title',
            'student_col' => 'candidate_name',
            'reg_col'     => null,
            'where_extra' => "category = 'Student'",
            'date_col'    => 'created_at',
        ],
    ];
}

/**
 * Validates and resolves the effective department filter based on user role.
 */
function resolve_student_achievement_scope(array $currentUser, ?string $requestedDept): ?string
{
    $role = $currentUser['role'] ?? 'Faculty';
    if ($role === 'HoD') {
        return $currentUser['department'] ?: null;
    }
    return trim((string) $requestedDept) ?: null;
}

/**
 * Builds a consistent unique student identifier key for merging and routing.
 */
function student_make_key(?string $regNo, ?string $name, ?string $dept): string
{
    $cleanReg = strtoupper(trim((string) $regNo));
    if ($cleanReg !== '' && $cleanReg !== '—' && $cleanReg !== 'N/A') {
        return 'REG_' . preg_replace('/[^A-Z0-9_\-]/', '', $cleanReg);
    }
    $cleanName = strtolower(trim((string) $name));
    $cleanDept = strtolower(trim((string) $dept));
    return 'NAME_' . md5($cleanName . '::' . $cleanDept);
}

/**
 * Returns SQL condition to strictly enforce student-only records at the database level,
 * ensuring no Faculty, Coordinator, HoD, Dean, Director, or Admin users are included.
 */
function student_role_exclusion_sql(string $studentCol): string
{
    return " AND TRIM(r.`{$studentCol}`) NOT IN (SELECT TRIM(name) FROM users WHERE role IN ('Admin', 'Principal', 'Director', 'Dean', 'HoD', 'Coordinator', 'Faculty'))";
}

/**
 * Returns aggregated per-student achievement counts for the main data matrix.
 */
function student_achievements_grid(array $currentUser, ?string $deptFilter = null, ?string $yearFilter = null, ?string $catFilter = null, ?string $search = null, ?array $window = null): array
{
    $effDept = resolve_student_achievement_scope($currentUser, $deptFilter);
    $categories = student_achievement_categories();

    $students = [];
    $nameIndex = [];

    foreach ($categories as $catKey => $meta) {
        if ($catFilter && $catFilter !== $catKey && $catFilter !== $meta['group'] && $catFilter !== $meta['col_name']) {
            continue;
        }

        $table = $meta['table'];
        try {
            $cols = target_record_table_columns($table);
        } catch (\Exception $e) {
            continue;
        }

        $studentCol = $meta['student_col'];
        $hasRegCol  = !empty($meta['reg_col']) && in_array($meta['reg_col'], $cols, true);
        $regSelect  = $hasRegCol ? "r.`{$meta['reg_col']}` AS reg_no," : "NULL AS reg_no,";

        $sql = "SELECT {$regSelect} r.`{$studentCol}` AS student_name, r.department, r.status, COUNT(*) as cnt
                FROM `{$table}` r
                WHERE r.`{$studentCol}` IS NOT NULL AND TRIM(r.`{$studentCol}`) <> ''";
        $sql .= student_role_exclusion_sql($studentCol);
        $params = [];

        if (!empty($meta['where_extra'])) {
            $sql .= " AND ({$meta['where_extra']})";
        }

        if ($effDept && in_array('department', $cols, true)) {
            $sql .= " AND r.department = ?";
            $params[] = $effDept;
        }

        if ($yearFilter && in_array('academic_year', $cols, true)) {
            $sql .= " AND r.academic_year = ?";
            $params[] = $yearFilter;
        }

        if ($search) {
            $searchConds = ["r.`{$studentCol}` LIKE ?"];
            $params[] = '%' . $search . '%';
            if ($hasRegCol) {
                $searchConds[] = "r.`{$meta['reg_col']}` LIKE ?";
                $params[] = '%' . $search . '%';
            }
            $sql .= " AND (" . implode(' OR ', $searchConds) . ")";
        }

        $sql .= em_window_sql($window, $params);
        $groupBy = $hasRegCol ? "r.`{$meta['reg_col']}`, r.`{$studentCol}`, r.department, r.status" : "r.`{$studentCol}`, r.department, r.status";
        $sql .= " GROUP BY {$groupBy}";

        try {
            $stmt = db()->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($rows as $r) {
                $regNo = trim((string)($r['reg_no'] ?? ''));
                $name  = trim((string)($r['student_name'] ?? ''));
                $dept  = trim((string)($r['department'] ?? 'Other Department'));
                if ($dept === '') $dept = 'Other Department';

                $key = student_make_key($regNo, $name, $dept);
                $nameKey = strtolower($name) . '::' . strtolower($dept);

                // If no reg_no on this record, but we already have a registered student with same name and dept, link it
                if (($regNo === '' || $regNo === '—') && isset($nameIndex[$nameKey])) {
                    $key = $nameIndex[$nameKey];
                }

                if (!isset($students[$key])) {
                    $students[$key] = [
                        'key'            => $key,
                        'reg_no'         => ($regNo !== '' && $regNo !== '—') ? $regNo : '—',
                        'student_name'   => $name,
                        'department'     => $dept,
                        'nptel'          => 0,
                        'internships'    => 0,
                        'placements'     => 0,
                        'online_courses' => 0,
                        'achievements'   => 0,
                        'participation'  => 0,
                        'training'       => 0,
                        'other'          => 0,
                        'total'          => 0,
                        'approved_count' => 0,
                        'pending_count'  => 0,
                    ];
                    $nameIndex[$nameKey] = $key;
                } elseif ($students[$key]['reg_no'] === '—' && $regNo !== '' && $regNo !== '—') {
                    $students[$key]['reg_no'] = $regNo;
                }

                $cnt = (int) $r['cnt'];
                $colKey = $meta['col_name'];
                if (isset($students[$key][$colKey])) {
                    $students[$key][$colKey] += $cnt;
                } else {
                    $students[$key]['other'] += $cnt;
                }
                $students[$key]['total'] += $cnt;

                $st = $r['status'] ?? '';
                if ($st === 'Approved') {
                    $students[$key]['approved_count'] += $cnt;
                } else {
                    $students[$key]['pending_count'] += $cnt;
                }
            }
        } catch (\PDOException $e) {
            // Ignore missing table/columns
        }
    }

    // Sort by Department ASC, Student Name ASC
    uasort($students, function ($a, $b) {
        $dc = strcasecmp($a['department'], $b['department']);
        if ($dc !== 0) return $dc;
        return strcasecmp($a['student_name'], $b['student_name']);
    });

    return array_values($students);
}

/**
 * Calculates summary KPI metrics across the scoped student database.
 */
function student_achievements_summary(array $currentUser, ?string $deptFilter = null, ?string $yearFilter = null, ?string $catFilter = null, ?string $search = null, ?array $window = null): array
{
    $grid = student_achievements_grid($currentUser, $deptFilter, $yearFilter, $catFilter, $search, $window);
    $totalStudents = count($grid);

    $totalAchievements = 0;
    $approvedRecords   = 0;
    $pendingRecords    = 0;
    $categoryCounts    = [
        'Internships'              => 0,
        'Placements'               => 0,
        'Student Achievements'     => 0,
        'Student Participations'   => 0,
        'Summer / Winter Training' => 0,
        'SWAYAM-NPTEL'             => 0,
        'Online Courses'           => 0,
    ];
    $activeDepts = [];

    foreach ($grid as $s) {
        $totalAchievements += $s['total'];
        $approvedRecords   += $s['approved_count'];
        $pendingRecords    += $s['pending_count'];
        $categoryCounts['Internships']              += $s['internships'];
        $categoryCounts['Placements']               += $s['placements'];
        $categoryCounts['Student Achievements']     += $s['achievements'];
        $categoryCounts['Student Participations']   += $s['participation'];
        $categoryCounts['Summer / Winter Training'] += $s['training'];
        $categoryCounts['SWAYAM-NPTEL']             += $s['nptel'];
        $categoryCounts['Online Courses']           += $s['online_courses'];
        if (!empty($s['department']) && $s['department'] !== '—') {
            $activeDepts[$s['department']] = true;
        }
    }

    $approvalRate = $totalAchievements > 0 ? round(($approvedRecords / $totalAchievements) * 100) : 0;
    $topCategory = '—';
    if (!empty($categoryCounts)) {
        $sortedCats = $categoryCounts;
        arsort($sortedCats);
        $topCategory = array_key_first($sortedCats) ?: '—';
    }

    return [
        'totalStudents'     => $totalStudents,
        'totalAchievements' => $totalAchievements,
        'approvedRecords'   => $approvedRecords,
        'pendingRecords'    => $pendingRecords,
        'approvalRate'      => $approvalRate,
        'departments'       => count($activeDepts),
        'topCategory'       => $topCategory,
        'categoryCounts'    => $categoryCounts,
    ];
}

/**
 * Aggregates department-wise student achievements for comparison view.
 */
function department_student_achievements_comparison(array $currentUser, ?string $yearFilter = null, ?string $catFilter = null, ?array $window = null): array
{
    $effDept = resolve_student_achievement_scope($currentUser, null);
    $categories = student_achievement_categories();

    $deptStmt = db()->query("SELECT name FROM departments ORDER BY name ASC");
    $departments = $deptStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

    if ($effDept) {
        $departments = array_values(array_filter($departments, fn($d) => $d === $effDept));
    }

    $result = [];
    foreach ($departments as $d) {
        $result[$d] = [
            'department'     => $d,
            'total'          => 0,
            'internships'    => 0,
            'placements'     => 0,
            'achievements'   => 0,
            'participation'  => 0,
            'training'       => 0,
            'nptel'          => 0,
            'online_courses' => 0,
        ];
    }

    foreach ($categories as $catKey => $meta) {
        if ($catFilter && $catFilter !== $catKey && $catFilter !== $meta['group'] && $catFilter !== $meta['col_name']) {
            continue;
        }

        $table = $meta['table'];
        try {
            $cols = target_record_table_columns($table);
        } catch (\Exception $e) {
            continue;
        }

        if (!in_array('department', $cols, true)) {
            continue;
        }

        $studentCol = $meta['student_col'];
        $sql = "SELECT department, COUNT(*) as cnt FROM `{$table}` r WHERE r.`{$studentCol}` IS NOT NULL AND TRIM(r.`{$studentCol}`) <> ''";
        $sql .= student_role_exclusion_sql($studentCol);
        $params = [];

        if (!empty($meta['where_extra'])) {
            $sql .= " AND ({$meta['where_extra']})";
        }
        if ($effDept) {
            $sql .= " AND department = ?";
            $params[] = $effDept;
        }
        if ($yearFilter && in_array('academic_year', $cols, true)) {
            $sql .= " AND academic_year = ?";
            $params[] = $yearFilter;
        }
        $sql .= em_window_sql($window, $params);
        $sql .= " GROUP BY department";

        try {
            $stmt = db()->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $colKey = $meta['col_name'];

            foreach ($rows as $r) {
                $dName = $r['department'] ?? '';
                $cnt = (int) $r['cnt'];
                if (isset($result[$dName])) {
                    if (isset($result[$dName][$colKey])) {
                        $result[$dName][$colKey] += $cnt;
                    }
                    $result[$dName]['total'] += $cnt;
                }
            }
        } catch (\PDOException $e) {}
    }

    return array_values($result);
}

/**
 * Retrieves full detailed achievement records for a specific student.
 */
function student_achievement_details(
    string $studentKey,
    ?string $yearFilter = null,
    ?string $catFilter = null,
    ?array $window = null,
    ?string $regHint = null,
    ?string $nameHint = null,
    ?string $deptHint = null
): array {
    $categories = student_achievement_categories();
    $detailedRecords = [];
    $byCategory = [];
    $studentInfo = null;

    $targetCleanReg = (strpos($studentKey, 'REG_') === 0) ? substr($studentKey, 4) : '';
    if ($targetCleanReg === '' && !empty($regHint) && $regHint !== '—') {
        $targetCleanReg = strtoupper(preg_replace('/[^A-Z0-9_\-]/', '', $regHint));
    }

    $cleanNameHint = !empty($nameHint) ? strtolower(trim($nameHint)) : '';
    $cleanDeptHint = (!empty($deptHint) && $deptHint !== '—') ? strtolower(trim($deptHint)) : '';

    foreach ($categories as $catKey => $meta) {
        if ($catFilter && $catFilter !== $catKey && $catFilter !== $meta['group'] && $catFilter !== $meta['col_name']) {
            continue;
        }

        $table = $meta['table'];
        try {
            $cols = target_record_table_columns($table);
        } catch (\Exception $e) {
            continue;
        }

        $studentCol = $meta['student_col'];
        $hasRegCol  = !empty($meta['reg_col']) && in_array($meta['reg_col'], $cols, true);

        $sql = "SELECT * FROM `{$table}` r WHERE r.`{$studentCol}` IS NOT NULL AND TRIM(r.`{$studentCol}`) <> ''";
        $sql .= student_role_exclusion_sql($studentCol);
        $params = [];

        if (!empty($meta['where_extra'])) {
            $sql .= " AND ({$meta['where_extra']})";
        }
        if ($yearFilter && in_array('academic_year', $cols, true)) {
            $sql .= " AND academic_year = ?";
            $params[] = $yearFilter;
        }
        $sql .= em_window_sql($window, $params);

        try {
            $stmt = db()->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($rows as $r) {
                $regNo = trim((string)($r['reg_no'] ?? ''));
                $name  = trim((string)($r[$studentCol] ?? ''));
                $dept  = trim((string)($r['department'] ?? 'Other Department'));
                if ($dept === '') $dept = 'Other Department';

                $currKey = student_make_key($regNo, $name, $dept);
                $currCleanReg = strtoupper(preg_replace('/[^A-Z0-9_\-]/', '', $regNo));
                $cleanRName   = strtolower(trim($name));
                $cleanRDept   = strtolower(trim($dept));

                $matched = false;
                if ($targetCleanReg !== '' && $currCleanReg !== '' && $currCleanReg === $targetCleanReg) {
                    $matched = true;
                } elseif ($currKey === $studentKey) {
                    $matched = true;
                } elseif ($cleanNameHint !== '' && $cleanRName === $cleanNameHint) {
                    if ($cleanDeptHint === '' || $cleanRDept === $cleanDeptHint) {
                        $matched = true;
                    }
                }

                if (!$matched) {
                    continue;
                }

                if (!$studentInfo) {
                    $studentInfo = [
                        'key'        => $studentKey,
                        'name'       => (!empty($nameHint)) ? $nameHint : $name,
                        'reg_no'     => ($regNo !== '' && $regNo !== '—') ? $regNo : ((!empty($regHint) && $regHint !== '—') ? $regHint : '—'),
                        'department' => (!empty($deptHint) && $deptHint !== '—') ? $deptHint : $dept,
                    ];
                } elseif ($studentInfo['reg_no'] === '—' && $regNo !== '' && $regNo !== '—') {
                    $studentInfo['reg_no'] = $regNo;
                }

                $title = $r[$meta['title_col']] ?? $r['title'] ?? $r['event_name'] ?? $r['course_title'] ?? 'Record #' . $r['id'];
                $item = [
                    'id'          => (int) $r['id'],
                    'type_key'    => $catKey,
                    'category'    => $meta['label'],
                    'title'       => $title,
                    'status'      => $r['status'] ?? 'Submitted',
                    'date'        => $r[$meta['date_col']] ?? $r['created_at'] ?? '—',
                    'proof_file'  => $r['proof_file'] ?? null,
                    'raw'         => $r,
                ];
                $detailedRecords[] = $item;
                $byCategory[$meta['label']][] = $item;
            }
        } catch (\PDOException $e) {}
    }

    if (!$studentInfo && (!empty($nameHint) || !empty($regHint))) {
        $studentInfo = [
            'key'        => $studentKey,
            'name'       => $nameHint ?: 'Student',
            'reg_no'     => (!empty($regHint) && $regHint !== '—') ? $regHint : '—',
            'department' => (!empty($deptHint) && $deptHint !== '—') ? $deptHint : '—',
        ];
    }

    return [
        'student'     => $studentInfo,
        'records'     => $detailedRecords,
        'by_category' => $byCategory,
        'summary'     => [
            'total'    => count($detailedRecords),
            'approved' => count(array_filter($detailedRecords, fn($r) => ($r['status'] ?? '') === 'Approved')),
            'pending'  => count(array_filter($detailedRecords, fn($r) => ($r['status'] ?? '') !== 'Approved')),
        ],
    ];
}

/**
 * Prepares presentation slide deck payload for a student.
 */
function student_achievement_presentation_data(
    string $studentKey,
    ?string $academicYear = null,
    ?string $regHint = null,
    ?string $nameHint = null,
    ?string $deptHint = null
): array {
    $academicYear = trim((string) ($academicYear ?: active_academic_year()));
    $details = student_achievement_details($studentKey, $academicYear, null, null, $regHint, $nameHint, $deptHint);
    $student = $details['student'];

    if (!$student) {
        return ['student' => null, 'slides' => []];
    }

    $categoriesMeta = student_achievement_categories();
    $byCategory = $details['by_category'];
    $slides = [];

    // Title Slide
    $slides[] = [
        'type'        => 'title',
        'title'       => $student['name'],
        'reg_no'      => $student['reg_no'],
        'department'  => $student['department'],
        'academicYear'=> $academicYear,
        'total'       => $details['summary']['total'],
        'approved'    => $details['summary']['approved'],
    ];

    // Category Slides
    foreach ($categoriesMeta as $catKey => $meta) {
        $label = $meta['label'];
        $recs = $byCategory[$label] ?? [];
        if (empty($recs)) continue;

        $slides[] = [
            'type'        => 'category',
            'category'    => $label,
            'group'       => $meta['group'],
            'count'       => count($recs),
            'records'     => array_slice($recs, 0, 8),
        ];
    }

    return [
        'student' => $student,
        'slides'  => $slides,
        'summary' => $details['summary'],
    ];
}

/**
 * Backend permission check verifying if the current logged-in user is authorized
 * to view the individual report of a student.
 */
function can_user_view_student_report(array $currentUser, ?string $studentDept): bool
{
    $role = $currentUser['role'] ?? '';
    // 1. Admin, Principal, Director, Dean can view any student report
    if (in_array($role, ['Admin', 'Principal', 'Director', 'Dean'], true)) {
        return true;
    }

    // 2. Department-scoped users (HoD, Coordinator, Faculty) can view reports of students in their department
    if (in_array($role, ['HoD', 'Coordinator', 'Faculty'], true)) {
        $userDept = trim((string)($currentUser['department'] ?? ''));
        if ($userDept === '') {
            return true;
        }
        $targetDept = trim((string)$studentDept);
        return ($targetDept === '' || strcasecmp($userDept, $targetDept) === 0 || $targetDept === 'Other Department');
    }

    return false;
}

