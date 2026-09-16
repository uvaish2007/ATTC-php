<?php
/**
 * Faculty Achievement Data Access Model — aggregated helpers across all record types.
 * Connects directly to persisted MySQL achievement tables and respects role-based scoping.
 */

require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/Record.php';
require_once __DIR__ . '/User.php';

/**
 * Returns all active record categories and metric types for faculty achievement reporting.
 */
function faculty_achievement_categories(): array
{
    return [
        'journal'    => ['label' => 'Journal Publication',    'group' => 'Publications', 'table' => 'journal_publications',    'title_col' => 'paper_title'],
        'conference' => ['label' => 'Conference Publication', 'group' => 'Conferences',  'table' => 'conference_publications', 'title_col' => 'paper_title'],
        'book'       => ['label' => 'Book / Book Chapter',    'group' => 'Books',        'table' => 'book_publications',       'title_col' => 'title'],
        'event'      => ['label' => 'Events Organized',       'group' => 'Events',       'table' => 'events',                  'title_col' => 'event_title'],
        'fdp'        => ['label' => 'FDP / Workshop / Seminar','group' => 'Training',     'table' => 'fdp',                     'title_col' => 'title'],
        'training'   => ['label' => 'Training Programmes',    'group' => 'Training',     'table' => 'training',                'title_col' => 'event_title'],
        'patent'     => ['label' => 'Patents & Copyrights',   'group' => 'Patents',      'table' => 'patents',                 'title_col' => 'title'],
        'nptel'      => ['label' => 'SWAYAM-NPTEL Courses',   'group' => 'Other',        'table' => 'nptel',                   'title_col' => 'course_title'],
        'online_course' => ['label' => 'Online Courses',      'group' => 'Other',        'table' => 'online_courses',          'title_col' => 'course_title'],
        'mou'        => ['label' => 'MoUs Signed',            'group' => 'Other',        'table' => 'mou',                     'title_col' => 'organization'],
    ];
}

/**
 * Validates and resolves the effective department filter based on user role.
 */
function resolve_faculty_achievement_scope(array $currentUser, ?string $requestedDept): ?string
{
    $role = $currentUser['role'] ?? 'Faculty';
    if ($role === 'HoD') {
        // HoD is strictly locked to their own assigned department
        return $currentUser['department'] ?: null;
    }
    // Admin, Director, Principal, Dean can filter by any department or view all
    return trim((string) $requestedDept) ?: null;
}

/**
 * Calculates summary KPI metrics across the scoped faculty database.
 */
function faculty_achievements_summary(array $currentUser, ?string $deptFilter = null, ?string $yearFilter = null, ?string $catFilter = null, ?int $facultyIdFilter = null): array
{
    $effDept = resolve_faculty_achievement_scope($currentUser, $deptFilter);
    $categories = faculty_achievement_categories();

    // 1. Total Faculty in scope
    $facSql = "SELECT COUNT(*) FROM users WHERE role IN ('Faculty', 'Coordinator', 'HoD')";
    $facParams = [];
    if ($effDept) {
        $facSql .= " AND department = ?";
        $facParams[] = $effDept;
    }
    if ($facultyIdFilter) {
        $facSql .= " AND id = ?";
        $facParams[] = $facultyIdFilter;
    }
    $facStmt = db()->prepare($facSql);
    $facStmt->execute($facParams);
    $totalFaculty = (int) $facStmt->fetchColumn();

    // 2. Count achievements across each metric table
    $totalAchievements = 0;
    $categoryCounts = [];
    $activeDepts = [];

    foreach ($categories as $catKey => $meta) {
        if ($catFilter && $catFilter !== $catKey && $catFilter !== $meta['group']) {
            continue;
        }

        // Check if table exists
        try {
            $cols = target_record_table_columns($meta['table']);
        } catch (\Exception $e) {
            continue;
        }

        $sql = "SELECT COUNT(*) AS cnt, department FROM `{$meta['table']}` WHERE 1=1";
        $params = [];

        if ($effDept) {
            $sql .= " AND department = ?";
            $params[] = $effDept;
        }
        if ($facultyIdFilter) {
            $sql .= " AND created_by = ?";
            $params[] = $facultyIdFilter;
        }
        if ($yearFilter && in_array('academic_year', $cols, true)) {
            $sql .= " AND academic_year = ?";
            $params[] = $yearFilter;
        }
        $sql .= " GROUP BY department";

        try {
            $stmt = db()->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll();
            $catTotal = 0;
            foreach ($rows as $r) {
                $cnt = (int) $r['cnt'];
                $catTotal += $cnt;
                if (!empty($r['department'])) {
                    $activeDepts[$r['department']] = true;
                }
            }
            $categoryCounts[$meta['label']] = $catTotal;
            $totalAchievements += $catTotal;
        } catch (\PDOException $e) {
            // Ignore missing table
        }
    }

    // Calculate approved vs pending record counts across all record tables
    $approvedRecords = 0;
    $pendingRecords  = 0;

    foreach ($categories as $catKey => $meta) {
        if ($catFilter && $catFilter !== $catKey && $catFilter !== $meta['group']) {
            continue;
        }
        try {
            $cols = target_record_table_columns($meta['table']);
        } catch (\Exception $e) {
            continue;
        }

        $sql = "SELECT 
                    SUM(CASE WHEN status = 'Approved' THEN 1 ELSE 0 END) AS app_cnt,
                    SUM(CASE WHEN status LIKE '%Pending%' OR status = 'Submitted' THEN 1 ELSE 0 END) AS pend_cnt
                FROM `{$meta['table']}` WHERE 1=1";
        $params = [];
        if ($effDept) {
            $sql .= " AND department = ?";
            $params[] = $effDept;
        }
        if ($facultyIdFilter) {
            $sql .= " AND created_by = ?";
            $params[] = $facultyIdFilter;
        }
        if ($yearFilter && in_array('academic_year', $cols, true)) {
            $sql .= " AND academic_year = ?";
            $params[] = $yearFilter;
        }

        try {
            $stmt = db()->prepare($sql);
            $stmt->execute($params);
            $r = $stmt->fetch(PDO::FETCH_ASSOC);
            $approvedRecords += (int) ($r['app_cnt'] ?? 0);
            $pendingRecords  += (int) ($r['pend_cnt'] ?? 0);
        } catch (\PDOException $e) {
            // Ignore missing tables
        }
    }

    $approvalRate = $totalAchievements > 0 ? round(($approvedRecords / $totalAchievements) * 100) : 0;

    // Calculate targets met from targets table
    $tSql = "SELECT COUNT(*) as total_targets, SUM(CASE WHEN achieved_value >= target_value AND target_value > 0 THEN 1 ELSE 0 END) as met_targets FROM targets WHERE 1=1";
    $tParams = [];
    if ($effDept) {
        $tSql .= " AND department = ?";
        $tParams[] = $effDept;
    }
    if ($yearFilter) {
        $tSql .= " AND academic_year = ?";
        $tParams[] = $yearFilter;
    }

    try {
        $tStmt = db()->prepare($tSql);
        $tStmt->execute($tParams);
        $tRow = $tStmt->fetch(PDO::FETCH_ASSOC);
        $totalTargets = (int) ($tRow['total_targets'] ?? 0);
        $targetsMet   = (int) ($tRow['met_targets'] ?? 0);
    } catch (\PDOException $e) {
        $totalTargets = 0;
        $targetsMet   = 0;
    }

    $targetAttainment = $totalTargets > 0 ? round(($targetsMet / $totalTargets) * 100) : 0;

    // Total registered accounts (Team)
    $teamSql = "SELECT COUNT(*) FROM users WHERE 1=1";
    $teamParams = [];
    if ($effDept) {
        $teamSql .= " AND department = ?";
        $teamParams[] = $effDept;
    }
    $teamStmt = db()->prepare($teamSql);
    $teamStmt->execute($teamParams);
    $registeredAccounts = (int) $teamStmt->fetchColumn();

    return [
        'totalFaculty'       => $totalFaculty,
        'totalAchievements'  => $totalAchievements,
        'approvedRecords'    => $approvedRecords,
        'pendingRecords'     => $pendingRecords,
        'approvalRate'       => $approvalRate,
        'targetAttainment'   => $targetAttainment,
        'targetsMet'         => $targetsMet,
        'totalTargets'       => $totalTargets,
        'registeredAccounts' => $registeredAccounts,
        'departments'        => $deptCount,
        'topCategory'        => $topCategory,
        'categoryCounts'     => $categoryCounts,
    ];
}

/**
 * Returns aggregated per-faculty achievement counts for the main data table.
 */
function faculty_achievements_grid(array $currentUser, ?string $deptFilter = null, ?string $yearFilter = null, ?string $catFilter = null, ?int $facultyIdFilter = null, ?string $search = null): array
{
    $effDept = resolve_faculty_achievement_scope($currentUser, $deptFilter);
    $categories = faculty_achievement_categories();

    // Fetch faculty members in scope
    $sql = "SELECT id, name, email, department, role, phone FROM users WHERE role IN ('Faculty', 'Coordinator', 'HoD')";
    $params = [];

    if ($effDept) {
        $sql .= " AND department = ?";
        $params[] = $effDept;
    }
    if ($facultyIdFilter) {
        $sql .= " AND id = ?";
        $params[] = $facultyIdFilter;
    }
    if ($search) {
        $sql .= " AND (name LIKE ? OR email LIKE ?)";
        $params[] = '%' . $search . '%';
        $params[] = '%' . $search . '%';
    }

    $sql .= " ORDER BY department ASC, name ASC";

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $facultyMembers = $stmt->fetchAll();

    if (empty($facultyMembers)) {
        return [];
    }

    $facIds = array_column($facultyMembers, 'id');
    $facMap = [];
    foreach ($facultyMembers as $f) {
        $facMap[$f['id']] = [
            'id'           => (int) $f['id'],
            'name'         => $f['name'],
            'email'        => $f['email'],
            'department'   => $f['department'] ?: '—',
            'role'         => $f['role'],
            'employee_id'  => $f['employee_id'] ?? ('EMP' . str_pad($f['id'], 3, '0', STR_PAD_LEFT)),
            'designation'  => $f['designation'] ?? ($f['role'] === 'HoD' ? 'Head of Department' : ($f['role'] === 'Coordinator' ? 'Department Coordinator' : 'Assistant Professor')),
            'journals'     => 0,
            'conferences'  => 0,
            'books'        => 0,
            'events'       => 0,
            'training'     => 0,
            'patents'      => 0,
            'other'        => 0,
            'total'        => 0,
        ];
    }

    // Query each metric table and accumulate counts per faculty member
    $inClause = implode(',', array_fill(0, count($facIds), '?'));

    foreach ($categories as $catKey => $meta) {
        if ($catFilter && $catFilter !== $catKey && $catFilter !== $meta['group']) {
            continue;
        }

        try {
            $cols = target_record_table_columns($meta['table']);
        } catch (\Exception $e) {
            continue;
        }

        $q = "SELECT created_by, COUNT(*) as cnt FROM `{$meta['table']}` WHERE created_by IN ({$inClause})";
        $p = $facIds;

        if ($yearFilter && in_array('academic_year', $cols, true)) {
            $q .= " AND academic_year = ?";
            $p[] = $yearFilter;
        }

        $q .= " GROUP BY created_by";

        try {
            $st = db()->prepare($q);
            $st->execute($p);
            $rows = $st->fetchAll();

            foreach ($rows as $r) {
                $fid = (int) $r['created_by'];
                $cnt = (int) $r['cnt'];
                if (isset($facMap[$fid])) {
                    $grpKey = strtolower($meta['group']);
                    if (!isset($facMap[$fid][$grpKey])) {
                        $grpKey = 'other';
                    }
                    $facMap[$fid][$grpKey] += $cnt;
                    $facMap[$fid]['total'] += $cnt;
                }
            }
        } catch (\PDOException $e) {
            // Ignore missing tables
        }
    }

    return array_values($facMap);
}

/**
 * Backend permission check verifying if the current logged-in user is authorized
 * to view the individual report of a target faculty member.
 */
function can_user_view_faculty_report(array $currentUser, int $targetFacultyId): bool
{
    // 1. A faculty/staff member can view their own report
    if ((int) ($currentUser['id'] ?? 0) === $targetFacultyId) {
        return true;
    }

    // 2. Admin, Principal, Director, Dean can view any faculty report
    $role = $currentUser['role'] ?? '';
    if (in_array($role, ['Admin', 'Principal', 'Director', 'Dean'], true)) {
        return true;
    }

    // 3. HoD can view reports of faculty members in their own department
    if ($role === 'HoD') {
        $uStmt = db()->prepare("SELECT department FROM users WHERE id = ?");
        $uStmt->execute([$targetFacultyId]);
        $targetDept = $uStmt->fetchColumn();
        return $targetDept && $targetDept === ($currentUser['department'] ?? '');
    }

    return false;
}

/**
 * Retrieves full detailed achievement records for a specific faculty member.
 */
function faculty_achievement_details(int $facultyId, ?string $yearFilter = null, ?string $catFilter = null): array
{
    // Fetch faculty user info
    $uStmt = db()->prepare("SELECT id, name, email, department, role, phone FROM users WHERE id = ?");
    $uStmt->execute([$facultyId]);
    $faculty = uStmtFetch($uStmt);

    if (!$faculty) {
        return ['faculty' => null, 'records' => [], 'by_category' => [], 'summary' => []];
    }

    $categories = faculty_achievement_categories();
    $detailedRecords = [];
    $byCategory = [];
    $summary = [];

    foreach ($categories as $catKey => $meta) {
        if ($catFilter && $catFilter !== $catKey && $catFilter !== $meta['group']) {
            continue;
        }

        try {
            $cols = target_record_table_columns($meta['table']);
        } catch (\Exception $e) {
            continue;
        }

        $sql = "SELECT * FROM `{$meta['table']}` WHERE created_by = ?";
        $params = [$facultyId];

        if ($yearFilter && in_array('academic_year', $cols, true)) {
            $sql .= " AND academic_year = ?";
            $params[] = $yearFilter;
        }

        $sql .= " ORDER BY created_at DESC";

        try {
            $stmt = db()->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll();

            if (!empty($rows)) {
                $summary[$meta['label']] = count($rows);
                foreach ($rows as $r) {
                    $title = $r[$meta['title_col']] ?? $r['title'] ?? 'Achievement Record';
                    $item = [
                        'id'          => (int) ($r['id'] ?? 0),
                        'category'    => $meta['label'],
                        'group'       => $meta['group'],
                        'type_key'    => $catKey,
                        'title'       => $title,
                        'department'  => $r['department'] ?? $faculty['department'],
                        'status'      => $r['status'] ?? 'Approved',
                        'created_at'  => $r['created_at'] ?? date('Y-m-d H:i:s'),
                        'year'        => $r['academic_year'] ?? '—',
                        'raw'         => $r,
                    ];
                    $detailedRecords[] = $item;
                    $byCategory[$meta['label']][] = $item;
                }
            }
        } catch (\PDOException $e) {
            // Ignore missing table
        }
    }

    return [
        'faculty' => [
            'id'          => (int) $faculty['id'],
            'name'        => $faculty['name'],
            'email'       => $faculty['email'],
            'department'  => $faculty['department'] ?: '—',
            'role'        => $faculty['role'],
            'employee_id' => $faculty['employee_id'] ?? ('EMP' . str_pad($faculty['id'], 3, '0', STR_PAD_LEFT)),
            'designation' => $faculty['designation'] ?? ($faculty['role'] === 'HoD' ? 'Head of Department' : ($faculty['role'] === 'Coordinator' ? 'Department Coordinator' : 'Assistant Professor')),
            'phone'       => $faculty['phone'] ?? '—',
        ],
        'records'     => $detailedRecords,
        'by_category' => $byCategory,
        'summary'     => $summary,
    ];
}

/**
 * Helper to fetch a single array row safely across PDO versions.
 */
function uStmtFetch($stmt): ?array
{
    $row = $stmt->fetch();
    return is_array($row) ? $row : null;
}

/**
 * Aggregates department-wise achievements for comparison charts.
 */
function department_achievements_comparison(array $currentUser, ?string $yearFilter = null, ?string $catFilter = null): array
{
    $effDept = resolve_faculty_achievement_scope($currentUser, null);
    $categories = faculty_achievement_categories();

    // Get all departments
    $deptStmt = db()->query("SELECT name FROM departments ORDER BY name ASC");
    $departments = $deptStmt->fetchAll(PDO::FETCH_COLUMN);

    if ($effDept) {
        $departments = array_values(array_filter($departments, fn($d) => $d === $effDept));
    }

    $result = [];
    foreach ($departments as $d) {
        $result[$d] = [
            'department'   => $d,
            'total'        => 0,
            'publications' => 0,
            'conferences'  => 0,
            'books'        => 0,
            'events'       => 0,
            'training'     => 0,
            'patents'      => 0,
            'other'        => 0,
        ];
    }

    foreach ($categories as $catKey => $meta) {
        if ($catFilter && $catFilter !== $catKey && $catFilter !== $meta['group']) {
            continue;
        }

        try {
            $cols = target_record_table_columns($meta['table']);
        } catch (\Exception $e) {
            continue;
        }

        $sql = "SELECT department, COUNT(*) as cnt FROM `{$meta['table']}` WHERE 1=1";
        $params = [];

        if ($effDept) {
            $sql .= " AND department = ?";
            $params[] = $effDept;
        }
        if ($yearFilter && in_array('academic_year', $cols, true)) {
            $sql .= " AND academic_year = ?";
            $params[] = $yearFilter;
        }
        $sql .= " GROUP BY department";

        try {
            $stmt = db()->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll();

            $grpKey = strtolower($meta['group']);
            foreach ($rows as $r) {
                $deptName = $r['department'];
                $cnt = (int) $r['cnt'];
                if (isset($result[$deptName])) {
                    if (isset($result[$deptName][$grpKey])) {
                        $result[$deptName][$grpKey] += $cnt;
                    } else {
                        $result[$deptName]['other'] += $cnt;
                    }
                    $result[$deptName]['total'] += $cnt;
                }
            }
        } catch (\PDOException $e) {
            // Ignore missing tables
        }
    }

    return array_values($result);
}

/**
 * Returns top N faculty contributors ranked by achievement count.
 */
function top_faculty_contributors(array $currentUser, ?string $deptFilter = null, ?string $yearFilter = null, ?string $catFilter = null, int $limit = 5): array
{
    $grid = faculty_achievements_grid($currentUser, $deptFilter, $yearFilter, $catFilter);
    usort($grid, fn($a, $b) => $b['total'] <=> $a['total']);
    return array_slice($grid, 0, $limit);
}

/**
 * Prepares structured presentation slides payload for a faculty member.
 * Combines targets from the targets table with persisted achievement records.
 */
function faculty_achievement_presentation_data(int $facultyId, ?string $academicYear = null): array
{
    require_once __DIR__ . '/Target.php';

    $academicYear = trim((string) ($academicYear ?: active_academic_year()));
    $details = faculty_achievement_details($facultyId, $academicYear);
    $faculty = $details['faculty'];

    if (!$faculty) {
        return ['faculty' => null, 'slides' => []];
    }

    $dept = $faculty['department'];
    $allTargets = targets_all($dept, $academicYear);

    // Group target values by suggested record type
    $targetByCategory = [];
    foreach ($allTargets as $t) {
        $typeKey = target_suggested_type((string) ($t['metric'] ?? ''));
        if ($typeKey) {
            if (!isset($targetByCategory[$typeKey])) {
                $targetByCategory[$typeKey] = 0;
            }
            $targetByCategory[$typeKey] += (int) ($t['target_value'] ?? 0);
        }
    }

    $categoriesMeta = faculty_achievement_categories();
    $byCategory = $details['by_category'];
    $recordsByCategory = [];

    // Map records into category keys
    foreach ($details['records'] as $rec) {
        $typeKey = $rec['type_key'] ?? 'other';
        $recordsByCategory[$typeKey][] = $rec;
    }

    $slides = [];
    $categorySummaries = [];

    $overallTarget = 0;
    $overallAchieved = 0;
    $hasConfiguredTargets = false;

    foreach ($categoriesMeta as $catKey => $meta) {
        $catLabel = $meta['label'];
        $recs = $recordsByCategory[$catKey] ?? [];
        $achievedCount = count($recs);

        $hasTargetConfig = isset($targetByCategory[$catKey]);
        $targetVal = $hasTargetConfig ? $targetByCategory[$catKey] : null;

        if ($hasTargetConfig) {
            $hasConfiguredTargets = true;
            $overallTarget += $targetVal;
            $remainingVal = max($targetVal - $achievedCount, 0);
            $pctVal = $targetVal > 0 ? round(($achievedCount / $targetVal) * 100, 1) : 0;
        } else {
            $remainingVal = null;
            $pctVal = null;
        }

        $overallAchieved += $achievedCount;

        $catSummary = [
            'key'        => $catKey,
            'label'      => $catLabel,
            'group'      => $meta['group'],
            'target'     => $targetVal,
            'achieved'   => $achievedCount,
            'remaining'  => $remainingVal,
            'percentage' => $pctVal,
            'configured' => $hasTargetConfig,
        ];
        $categorySummaries[] = $catSummary;

        // Generate slide(s) for this category (paginated if > 4 records)
        $pageSize = 4;
        $totalRecs = count($recs);
        $totalPages = max(1, (int) ceil($totalRecs / $pageSize));

        for ($p = 1; $p <= $totalPages; $p++) {
            $offset = ($p - 1) * $pageSize;
            $pageRecs = array_slice($recs, $offset, $pageSize);

            $slides[] = [
                'type'           => 'category',
                'category_key'   => $catKey,
                'category_label' => $catLabel,
                'target'         => $targetVal,
                'achieved'       => $achievedCount,
                'remaining'      => $remainingVal,
                'percentage'     => $pctVal,
                'configured'     => $hasTargetConfig,
                'records'        => $pageRecs,
                'page'           => $p,
                'total_pages'    => $totalPages,
            ];
        }
    }

    $overallRemaining = $hasConfiguredTargets ? max($overallTarget - $overallAchieved, 0) : null;
    $overallPct = ($hasConfiguredTargets && $overallTarget > 0) ? round(($overallAchieved / $overallTarget) * 100, 1) : null;

    $overallSummary = [
        'target'     => $overallTarget,
        'achieved'   => $overallAchieved,
        'remaining'  => $overallRemaining,
        'percentage' => $overallPct,
        'configured' => $hasConfiguredTargets,
    ];

    // Build final ordered slide stack:
    // Slide 1: Intro
    // Slide 2: Overall Target vs Achievement
    // Slides 3..N: Category Slides
    // Final Slide: Overall Summary Matrix
    $slideDeck = [];

    $slideDeck[] = [
        'type'          => 'intro',
        'title'         => 'INDIVIDUAL FACULTY ACHIEVEMENT REPORT',
        'faculty'       => $faculty,
        'academic_year' => $academicYear,
        'overall'       => $overallSummary,
    ];

    $slideDeck[] = [
        'type'          => 'overall_summary',
        'title'         => 'Target vs Achievement Summary',
        'faculty'       => $faculty,
        'academic_year' => $academicYear,
        'overall'       => $overallSummary,
    ];

    foreach ($slides as $catSlide) {
        $slideDeck[] = array_merge(['faculty' => $faculty, 'academic_year' => $academicYear], $catSlide);
    }

    $slideDeck[] = [
        'type'          => 'final_summary',
        'title'         => 'Faculty Achievement Summary',
        'faculty'       => $faculty,
        'academic_year' => $academicYear,
        'categories'    => $categorySummaries,
        'overall'       => $overallSummary,
    ];

    return [
        'faculty'       => $faculty,
        'academic_year' => $academicYear,
        'overall'       => $overallSummary,
        'categories'    => $categorySummaries,
        'slides'        => $slideDeck,
    ];
}

