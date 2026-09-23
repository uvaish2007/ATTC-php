<?php
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/Target.php';   
require_once __DIR__ . '/Record.php';
require_once __DIR__ . '/ExecutiveMeeting.php';   

function all_metrics(): array
{
    return [
        'journals'        => ['label' => 'Journals',        'table' => 'journal_publications',    'group' => 'faculty',  'year' => true],
        'books'           => ['label' => 'Books',           'table' => 'book_publications',       'group' => 'faculty',  'year' => true],
        'conferences'     => ['label' => 'Conferences',     'table' => 'conference_publications', 'group' => 'faculty',  'year' => true],
        'patents'         => ['label' => 'Patents',         'table' => 'patents',                 'group' => 'faculty',  'year' => true],
        'fdp'             => ['label' => 'FDP',             'table' => 'fdp',                     'group' => 'faculty',  'year' => false],
        'mou'             => ['label' => 'MoUs',            'table' => 'mou',                     'group' => 'faculty',  'year' => false],
        'nptel'           => ['label' => 'NPTEL',           'table' => 'nptel',                   'group' => 'faculty',  'year' => false],
        'online'          => ['label' => 'Online Courses',  'table' => 'online_courses',          'group' => 'faculty',  'year' => true],
        'events'          => ['label' => 'Events',          'table' => 'events',                  'group' => 'activity', 'year' => false],
        'nss'             => ['label' => 'NSS/YRC/RRC',      'table' => 'nss',                     'group' => 'activity', 'year' => true],
        'value_added'     => ['label' => 'Value Added',     'table' => 'value_added_courses',     'group' => 'activity', 'year' => true],
        'training'        => ['label' => 'Training',        'table' => 'training',                'group' => 'activity', 'year' => true],
        'internships'     => ['label' => 'Internships',     'table' => 'internships',             'group' => 'student',  'year' => false],
        'placements'      => ['label' => 'Placements',      'table' => 'placements',              'group' => 'student',  'year' => false],
        'summer_training' => ['label' => 'Summer Training', 'table' => 'summer_training',         'group' => 'student',  'year' => true],
        'achievements'    => ['label' => 'Achievements',    'table' => 'student_achievements',    'group' => 'student',  'year' => true,  'dedup' => ['event_name', 'event_date']],
        'participations'  => ['label' => 'Participations',  'table' => 'student_participations',  'group' => 'student',  'year' => true,  'dedup' => ['event_name', 'event_date']],
    ];
}

function metric_count_expr(array $m): string
{
    if (!empty($m['dedup'])) {
        $cols = implode(', ', array_map(fn($c) => "`$c`", $m['dedup']));
        return "COUNT(DISTINCT $cols)";
    }
    return 'COUNT(*)';
}

function dept_metrics(): array  { return all_metrics(); }
function other_metrics(): array { return []; }

function dashboard_data(array $user): array
{
    journal_process_approval_expiry();
    $pdo = db();

    $isOversight = in_array($user['role'], ['Admin', 'Director', 'Principal', 'Dean'], true);

    // Server-side scope: only oversight roles may choose a department.
    $departmentFilter = user_department_scope($user, $_GET['department'] ?? null);

    $valid  = ['Draft', 'Submitted', 'Approved', 'Rejected'];
    $status = in_array(($_GET['status'] ?? ''), $valid, true) ? $_GET['status'] : null;

    $rawYear  = $_GET['academic_year'] ?? ($_GET['year'] ?? null);
    $emCtx    = em_resolve_filter_context($rawYear, $_GET['em'] ?? null);
    $year     = $emCtx['year'];
    $em       = $emCtx['em'];
    $emWindow = $emCtx['window'];

    $metrics = all_metrics();

    $deptCounts = [];   
    $seen = [];

    foreach ($metrics as $key => $m) {
        $expr   = metric_count_expr($m);
        $sql    = "SELECT department, $expr AS n FROM `{$m['table']}`";
        $where  = [];
        $params = [];
        if ($departmentFilter !== null)   { $where[] = 'department = ?';   $params[] = $departmentFilter; }
        if ($status !== null)             { $where[] = 'status = ?';        $params[] = $status; }
        if ($year !== null && $m['year']) { $where[] = 'academic_year = ?'; $params[] = $year; }
        if ($emWindow !== null) {
            $where[] = 'created_at >= ?'; $params[] = $emWindow['from'] . ' 00:00:00';
            $where[] = 'created_at <= ?'; $params[] = $emWindow['to'] . ' 23:59:59';
        }
        if ($where) { $sql .= ' WHERE ' . implode(' AND ', $where); }
        $sql .= ' GROUP BY department';

        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            foreach ($stmt as $row) {
                $dept = $row['department'] !== null && $row['department'] !== '' ? $row['department'] : 'Unassigned';
                $seen[$dept] = true;
                $deptCounts[$dept][$key] = (int) $row['n'];
            }
        } catch (\PDOException $e) {
            continue;
        }
    }

    require_once __DIR__ . '/Department.php';
    $configured      = array_map(fn($d) => $d['name'], departments_all());
    $usingConfigured = count($configured) > 0;
    $dataDepartments = array_keys($seen);

    if ($departmentFilter !== null) {
        $departments       = [$departmentFilter];
        $matrixDepartments = [$departmentFilter];
    } else {
        $departments       = $usingConfigured ? array_values(array_unique(array_merge($configured, $dataDepartments))) : $dataDepartments;
        sort($departments);
        $matrixDepartments = $dataDepartments;
    }

    $matrixRows = [];
    foreach ($matrixDepartments as $dept) {
        $counts = [];
        $total = 0;
        foreach ($metrics as $key => $m) {
            $n = $deptCounts[$dept][$key] ?? 0;
            $counts[$key] = $n;
            $total += $n;
        }
        $matrixRows[] = ['department' => $dept, 'counts' => $counts, 'total' => $total];
    }

    $totals = [];
    foreach ($metrics as $key => $m) {
        if ($departmentFilter !== null) {
            $totals[$key] = $deptCounts[$departmentFilter][$key] ?? 0;
        } else {
            $sum = 0;
            foreach ($deptCounts as $c) {
                $sum += $c[$key] ?? 0;
            }
            $totals[$key] = $sum;
        }
    }

    $grandTotal = 0;
    foreach ($totals as $v) {
        $grandTotal += (int) $v;
    }

    $statusBreakdown = [
        'Approved'     => 0,
        'Dean Pending' => 0,
        'HOD Pending'  => 0,
        'Submitted'    => 0,
        'Rejected'     => 0,
        'Draft'        => 0,
    ];
    foreach ($metrics as $key => $m) {
        $expr   = metric_count_expr($m);
        $sql    = "SELECT status, $expr AS n FROM `{$m['table']}`";
        $where  = [];
        $params = [];
        if ($departmentFilter !== null)   { $where[] = 'department = ?';    $params[] = $departmentFilter; }
        if ($year !== null && $m['year']) { $where[] = 'academic_year = ?'; $params[] = $year; }
        if ($emWindow !== null) {
            $where[] = 'created_at >= ?'; $params[] = $emWindow['from'] . ' 00:00:00';
            $where[] = 'created_at <= ?'; $params[] = $emWindow['to'] . ' 23:59:59';
        }
        if ($where) { $sql .= ' WHERE ' . implode(' AND ', $where); }
        $sql .= ' GROUP BY status';
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            foreach ($stmt as $row) {
                $s = $row['status'] ?: 'Draft';
                if (isset($statusBreakdown[$s])) {
                    $statusBreakdown[$s] += (int) $row['n'];
                }
            }
        } catch (\PDOException $e) {
            continue;
        }
    }

    $targetWhere = [];
    $targetParams = [];
    if ($departmentFilter !== null) {
        $targetWhere[] = 'department = ?';
        $targetParams[] = $departmentFilter;
    }
    if ($year !== null) {
        $targetWhere[] = 'academic_year = ?';
        $targetParams[] = $year;
    }
    $targetCountSql = 'SELECT COUNT(*) FROM targets' . ($targetWhere ? ' WHERE ' . implode(' AND ', $targetWhere) : '');
    $targetCountStmt = $pdo->prepare($targetCountSql);
    $targetCountStmt->execute($targetParams);
    $targetCount = (int) $targetCountStmt->fetchColumn();

    $userCountSql = 'SELECT COUNT(*) FROM users' . ($departmentFilter !== null ? ' WHERE department = ?' : '');
    $userCountStmt = $pdo->prepare($userCountSql);
    $userCountStmt->execute($departmentFilter !== null ? [$departmentFilter] : []);
    $userCount = (int) $userCountStmt->fetchColumn();

    $stats = [
        'users'       => $userCount,
        'departments' => $departmentFilter !== null ? 1 : count($departments),
        'metrics'     => (int) $pdo->query('SELECT COUNT(*) FROM metrics')->fetchColumn(),
        'targets'     => $targetCount,
        'totalRecords'=> $grandTotal,
        'pendingApprovals' => (int) (($statusBreakdown['Dean Pending'] ?? 0) + ($statusBreakdown['HOD Pending'] ?? 0) + ($statusBreakdown['Submitted'] ?? 0)),
    ];

    $usersByRole = ['Admin' => 0, 'Principal' => 0, 'Dean' => 0, 'HoD' => 0, 'Coordinator' => 0, 'Faculty' => 0];
    $usersByRoleSql = 'SELECT role, COUNT(*) AS n FROM users' . ($departmentFilter !== null ? ' WHERE department = ?' : '') . ' GROUP BY role';
    $usersByRoleStmt = $pdo->prepare($usersByRoleSql);
    $usersByRoleStmt->execute($departmentFilter !== null ? [$departmentFilter] : []);
    foreach ($usersByRoleStmt as $row) {
        $roleName = in_array($row['role'], ['Director', 'Principal'], true) ? 'Principal' : $row['role'];
        if (isset($usersByRole[$roleName])) {
            $usersByRole[$roleName] += (int) $row['n'];
        }
    }

    $recent  = recent_activity($departmentFilter, $status, null, $year, $emWindow);
    $targets = target_progress($departmentFilter, 6, $year);

    $attainment       = null;
    $attainFilters    = [];
    $attainOptions    = [];
    if ($isOversight) {
        $attainOptions = target_filter_options();
        $attainFilters = [
            'dept'   => trim((string) ($_GET['t_dept']   ?? '')) ?: null,
            // Always the system's active year — this mini-chart narrows by
            // department/metric/status, but never by a different year.
            'year'   => $year,
            'metric' => trim((string) ($_GET['t_metric'] ?? '')) ?: null,
            'status' => trim((string) ($_GET['t_status'] ?? '')) ?: null,
        ];
        // Only accept a value the data actually offers, so a hand-edited URL
        // cannot put the selects into a state the page can't show.
        foreach (['dept' => 'departments', 'metric' => 'metrics', 'status' => 'statuses'] as $key => $list) {
            if ($attainFilters[$key] !== null && !in_array($attainFilters[$key], $attainOptions[$list], true)) {
                $attainFilters[$key] = null;
            }
        }
        $attainment = target_attainment($departmentFilter, $attainFilters);
    }

    return [
        'scope'            => ['department' => $departmentFilter, 'status' => $status, 'year' => $year,
                               'em' => $em, 'emLabel' => em_filter_label($em, $year),
                               'emWindow' => $emWindow],
        'departmentFilter' => $departmentFilter,
        'isOversight'      => $isOversight,
        'departments'    => $departments,
        'years'          => academic_years(),
        'activeYear'     => active_academic_year(),
        'usingConfigured'=> $usingConfigured,
        'stats'          => $stats,
        'metricDefs'     => array_map(
            fn($k, $m) => ['key' => $k, 'label' => $m['label']],
            array_keys($metrics),
            array_values($metrics)
        ),
        'totals'         => $totals,
        'matrix'         => [
            'metrics' => array_map(fn($k, $m) => ['key' => $k, 'label' => $m['label'], 'group' => $m['group']], array_keys($metrics), array_values($metrics)),
            'rows'    => $matrixRows,
        ],
        'statusBreakdown'=> $statusBreakdown,
        'usersByRole'    => $usersByRole,

        'chartData'      => [
            'labels' => array_values(array_map(fn($m) => $m['label'], $metrics)),
            'values' => array_values(array_map(fn($k) => (int) ($totals[$k] ?? 0), array_keys($metrics))),
        ],
        'recent'         => $recent,
        'targetProgress' => $targets,
        'targetSummary'  => target_summary($departmentFilter, $year),
        'targetAttainment' => $attainment,
        'targetFilters'    => $attainFilters,
        'targetOptions'    => $attainOptions,
    ];
}

function target_progress(?string $department, int $limit = 6, ?string $year = null): array
{
    $sql    = 'SELECT department, academic_year, metric, target_value, achieved_value FROM targets';
    $where  = [];
    $params = [];

    if ($department !== null) {
        $where[]  = 'department = ?';
        $params[] = $department;
    }
    if ($year !== null) {
        $where[]  = 'academic_year = ?';
        $params[] = $year;
    }
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }

    $sql .= ' ORDER BY created_at DESC LIMIT ' . (int) $limit;

    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    $rows = [];
    foreach ($stmt as $row) {
        $target   = (int) $row['target_value'];
        $achieved = (int) $row['achieved_value'];

        $rows[] = [
            'metric'     => (string) $row['metric'],
            'department' => (string) $row['department'],
            'year'       => (string) $row['academic_year'],
            'target'     => $target,
            'achieved'   => $achieved,
            'percent'    => $target > 0 ? min(100, (int) round($achieved / $target * 100)) : 0,
        ];
    }

    return $rows;
}

function target_summary(?string $department, ?string $year = null): array
{
    $sql    = 'SELECT department, target_value, achieved_value FROM targets';
    $where  = [];
    $params = [];
    if ($department !== null) {
        $where[]  = 'department = ?';
        $params[] = $department;
    }
    if ($year !== null) {
        $where[]  = 'academic_year = ?';
        $params[] = $year;
    }
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    $count = 0; $totT = 0; $totA = 0; $met = 0; $onTrack = 0; $behind = 0;
    $byDept = [];
    foreach ($stmt as $r) {
        $t = (int) $r['target_value'];
        $a = (int) $r['achieved_value'];
        $count++; $totT += $t; $totA += $a;
        $pct = $t > 0 ? $a / $t * 100 : 0;
        if     ($pct >= 100) { $met++; }
        elseif ($pct >= 50)  { $onTrack++; }
        else                 { $behind++; }

        $d = ($r['department'] ?? '') !== '' ? $r['department'] : 'Unassigned';
        if (!isset($byDept[$d])) { $byDept[$d] = ['target' => 0, 'achieved' => 0, 'count' => 0]; }
        $byDept[$d]['target']   += $t;
        $byDept[$d]['achieved'] += $a;
        $byDept[$d]['count']++;
    }

    $rows = [];
    foreach ($byDept as $d => $v) {
        $rows[] = [
            'department' => $d,
            'target'     => $v['target'],
            'achieved'   => $v['achieved'],
            'count'      => $v['count'],
            'percent'    => $v['target'] > 0 ? (int) round($v['achieved'] / $v['target'] * 100) : 0,
        ];
    }
    usort($rows, fn($a, $b) => $b['percent'] <=> $a['percent']);

    return [
        'count'        => $count,
        'target'       => $totT,
        'achieved'     => $totA,
        'percent'      => $totT > 0 ? (int) round($totA / $totT * 100) : 0,
        'met'          => $met,
        'onTrack'      => $onTrack,
        'behind'       => $behind,
        'byDepartment' => $rows,
    ];
}

function target_metric_sources(): array
{
    return [
        'Journal Publications'    => ['table' => 'journal_publications',    'dept' => true,  'year' => true],
        'Book & Book Chapters'    => ['table' => 'book_publications',       'dept' => true,  'year' => true],
        'Conference Publications' => ['table' => 'conference_publications', 'dept' => true,  'year' => true],
        'Patents & Copyrights'    => ['table' => 'patents',                 'dept' => true,  'year' => true],
        'MoU Signed'              => ['table' => 'mou',                     'dept' => true,  'year' => false],
        'FDP Participation'       => ['table' => 'fdp',                     'dept' => true,  'year' => false],
        'NPTEL'                   => ['table' => 'nptel',                   'dept' => true,  'year' => false],
        'Students Internship'     => ['table' => 'internships',             'dept' => false, 'year' => false],
        'Placements'              => ['table' => 'placements',              'dept' => false, 'year' => false],
    ];
}

function target_attainment(?string $department, array $filters = [], int $limit = 8): array
{
    $pdo     = db();
    $sources = target_metric_sources();

    $where  = [];
    $params = [];

    $effectiveDept = ($filters['dept'] ?? null) ?: $department;
    if ($effectiveDept !== null) {
        $where[]  = 'department = ?';
        $params[] = $effectiveDept;
    }
    foreach (['year' => 'academic_year', 'metric' => 'metric', 'status' => 'status'] as $key => $column) {
        if (!empty($filters[$key])) {
            $where[]  = "$column = ?";
            $params[] = $filters[$key];
        }
    }

    $sql = 'SELECT department, academic_year, metric, target_value, achieved_value, status FROM targets';
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $rows          = [];
    $totalTarget   = 0;
    $totalAchieved = 0;
    $met           = 0;
    $approximate   = false;

    foreach ($stmt as $row) {
        $source = $sources[$row['metric']] ?? null;

        if ($source === null) {
            $achieved = (int) $row['achieved_value'];
            $target   = (int) $row['target_value'];
            $totalTarget   += $target;
            $totalAchieved += $achieved;
            if ($target > 0 && $achieved >= $target) { $met++; }
            $rows[] = [
                'metric'     => (string) $row['metric'],
                'department' => (string) ($row['department'] ?? ''),
                'year'       => (string) ($row['academic_year'] ?? ''),
                'target'     => $target,
                'achieved'   => $achieved,
                'percent'    => $target > 0 ? (int) round($achieved / $target * 100) : 0,
                'exact'      => true,
                'status'     => (string) ($row['status'] ?? 'Draft'),
                'frozen'     => ($row['status'] ?? '') === 'Approved',
            ];
            continue;
        }

        $where = ['status = ?'];
        $args  = ['Approved'];
        $exact = true;   

        if ($row['department']) {
            if ($source['dept']) {
                $where[] = 'department = ?';
                $args[]  = $row['department'];
            } else {
                $exact = false;
            }
        }
        if ($row['academic_year']) {
            if ($source['year']) {
                $where[] = 'academic_year = ?';
                $args[]  = $row['academic_year'];
            } else {
                $exact = false;
            }
        }

        // The table name comes from the map above, never from user input.
        $count = $pdo->prepare("SELECT COUNT(*) FROM `{$source['table']}` WHERE " . implode(' AND ', $where));
        $count->execute($args);

        $achieved = (int) $count->fetchColumn();
        $target   = (int) $row['target_value'];

        $totalTarget   += $target;
        $totalAchieved += $achieved;
        if ($target > 0 && $achieved >= $target) {
            $met++;
        }
        if (!$exact) {
            $approximate = true;
        }

        $rows[] = [
            'metric'     => (string) $row['metric'],
            'department' => (string) ($row['department'] ?? ''),
            'year'       => (string) ($row['academic_year'] ?? ''),
            'target'     => $target,
            'achieved'   => $achieved,
            'percent'    => $target > 0 ? (int) round($achieved / $target * 100) : 0,
            'exact'      => $exact,
            'status'     => (string) ($row['status'] ?? 'Draft'),

            'frozen'     => ($row['status'] ?? '') === 'Approved',
        ];
    }

    $byMetricAgg = [];
    foreach ($rows as $r) {
        $m = $r['metric'];
        if (!isset($byMetricAgg[$m])) {
            $byMetricAgg[$m] = ['metric' => $m, 'target' => 0, 'achieved' => 0, 'count' => 0, 'met' => 0];
        }
        $byMetricAgg[$m]['target']   += $r['target'];
        $byMetricAgg[$m]['achieved'] += $r['achieved'];
        $byMetricAgg[$m]['count']++;
        if ($r['target'] > 0 && $r['achieved'] >= $r['target']) { $byMetricAgg[$m]['met']++; }
    }
    $byMetric = [];
    foreach ($byMetricAgg as $v) {
        $v['percent'] = $v['target'] > 0 ? (int) round($v['achieved'] / $v['target'] * 100) : 0;
        $byMetric[] = $v;
    }
    usort($byMetric, fn($a, $b) => $a['percent'] <=> $b['percent']);   

    usort($rows, fn($a, $b) => $a['percent'] <=> $b['percent']);

    return [
        'rows'     => array_slice($rows, 0, $limit),
        'byMetric' => $byMetric,
        'summary'  => [
            'targets'     => count($rows),
            'met'         => $met,
            'frozen'      => count(array_filter($rows, fn($r) => $r['frozen'])),
            'target'      => $totalTarget,
            'achieved'    => $totalAchieved,
            'percent'     => $totalTarget > 0 ? (int) round($totalAchieved / $totalTarget * 100) : 0,
            'approximate' => $approximate,
        ],
    ];
}

function target_filter_options(): array
{
    $pdo = db();

    $distinct = function (string $column) use ($pdo): array {
        $rows = $pdo->query("SELECT DISTINCT `$column` FROM targets WHERE `$column` IS NOT NULL AND `$column` <> '' ORDER BY `$column`")
                    ->fetchAll(PDO::FETCH_COLUMN);
        return $rows ?: [];
    };

    return [
        'departments' => $distinct('department'),
        'years'       => $distinct('academic_year'),
        'metrics'     => $distinct('metric'),
        'statuses'    => $distinct('status'),
    ];
}

function recent_activity(?string $department, ?string $status, ?int $createdBy = null, ?string $year = null, ?array $emWindow = null): array
{
    $sources = [
        ['table' => 'journal_publications',    'label' => 'Journal',        'title' => 'paper_title',   'year' => true],
        ['table' => 'book_publications',       'label' => 'Book',           'title' => 'title',         'year' => true],
        ['table' => 'conference_publications', 'label' => 'Conference',     'title' => 'paper_title',   'year' => true],
        ['table' => 'patents',                 'label' => 'Patent',         'title' => 'title',         'year' => true],
        ['table' => 'fdp',                     'label' => 'FDP',            'title' => 'title',         'year' => false],
        ['table' => 'mou',                     'label' => 'MoU',            'title' => 'organization',  'year' => false],
        ['table' => 'events',                  'label' => 'Event',          'title' => 'event_title',   'year' => false],
        ['table' => 'nptel',                   'label' => 'NPTEL',          'title' => 'course_title',  'year' => false],
        ['table' => 'online_courses',          'label' => 'Online Course',  'title' => 'course_title',  'year' => true],
        ['table' => 'nss',                     'label' => 'NSS/YRC/RRC',    'title' => 'activity_name', 'year' => true],
        ['table' => 'value_added_courses',     'label' => 'Value Added',    'title' => 'course_title',  'year' => true],
        ['table' => 'training',                'label' => 'Training',       'title' => 'event_title',   'year' => true],
        ['table' => 'internships',             'label' => 'Internship',     'title' => 'title',         'year' => false],
        ['table' => 'placements',              'label' => 'Placement',      'title' => 'student_name',  'year' => false],
        ['table' => 'summer_training',         'label' => 'Summer Training','title' => 'title',         'year' => true],
        ['table' => 'student_achievements',    'label' => 'Achievement',    'title' => 'event_name',    'year' => true],
        ['table' => 'student_participations',  'label' => 'Participation',  'title' => 'event_name',    'year' => true],
    ];

    $rows = [];
    foreach ($sources as $s) {
        $where = [];
        $params = [];
        if ($status !== null)             { $where[] = 'status = ?';        $params[] = $status; }
        if ($department !== null)         { $where[] = 'department = ?';    $params[] = $department; }
        if ($createdBy !== null)          { $where[] = 'created_by = ?';    $params[] = $createdBy; }
        if ($year !== null && $s['year']) { $where[] = 'academic_year = ?'; $params[] = $year; }
        if ($emWindow !== null) {
            $where[] = 'created_at >= ?'; $params[] = $emWindow['from'] . ' 00:00:00';
            $where[] = 'created_at <= ?'; $params[] = $emWindow['to'] . ' 23:59:59';
        }

        $sql = "SELECT `{$s['title']}` AS title, status, created_at, department FROM `{$s['table']}`";
        if ($where) { $sql .= ' WHERE ' . implode(' AND ', $where); }
        $sql .= ' ORDER BY created_at DESC LIMIT 5';

        try {
            $stmt = db()->prepare($sql);
            $stmt->execute($params);
            foreach ($stmt as $r) {
                $rows[] = [
                    'metric'     => $s['label'],
                    'title'      => (string) ($r['title'] ?: '(untitled)'),
                    'department' => $r['department'],
                    'status'     => $r['status'],
                    'at'         => $r['created_at'],
                ];
            }
        } catch (\PDOException $e) {
            continue;
        }
    }

    usort($rows, fn($a, $b) => strtotime($b['at']) <=> strtotime($a['at']));
    return array_slice($rows, 0, 8);
}

function my_dashboard_data(array $user): array
{
    $pdo  = db();
    $uid  = (int) $user['id'];

    $rawYear  = $_GET['academic_year'] ?? ($_GET['year'] ?? null);
    $emCtx    = em_resolve_filter_context($rawYear, $_GET['em'] ?? null);
    $year     = $emCtx['year'];
    $em       = $emCtx['em'];
    $emWindow = $emCtx['window'];

    $metrics = dept_metrics() + other_metrics();
    $statusBreakdown = [
        'Approved'     => 0,
        'Dean Pending' => 0,
        'HOD Pending'  => 0,
        'Submitted'    => 0,
        'Rejected'     => 0,
        'Draft'        => 0,
    ];
    $totals = [];

    foreach ($metrics as $key => $m) {
        try {
            $sql    = "SELECT status, COUNT(*) AS n FROM `{$m['table']}` WHERE created_by = ?";
            $params = [$uid];
            if (!empty($m['year'])) {
                $sql .= ' AND academic_year = ?';
                $params[] = $year;
            }
            $sql .= em_window_sql($emWindow, $params);
            $sql .= ' GROUP BY status';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $sum = 0;
            foreach ($stmt as $row) {
                $sum += (int) $row['n'];
                $s = $row['status'] ?: 'Draft';
                if (isset($statusBreakdown[$s])) {
                    $statusBreakdown[$s] += (int) $row['n'];
                }
            }
            $totals[$key] = $sum;
        } catch (\PDOException $e) {
            $totals[$key] = 0;
        }
    }

    $total = array_sum($totals);

    return [
        'stats' => [
            'totalRecords' => $total,
            'approved'     => (int) ($statusBreakdown['Approved'] ?? 0),
            'pending'      => (int) (($statusBreakdown['Dean Pending'] ?? 0) + ($statusBreakdown['HOD Pending'] ?? 0) + ($statusBreakdown['Submitted'] ?? 0)),
            'rejected'     => (int) ($statusBreakdown['Rejected'] ?? 0),
        ],
        'totals'          => $totals,
        'metricLabels'    => array_map(fn($m) => $m['label'], $metrics),
        'statusBreakdown' => $statusBreakdown,
        'recent'          => recent_activity(null, null, $uid, $year, $emWindow),
        'years'           => academic_years(),
        'activeYear'      => active_academic_year(),
        'scope'           => ['year' => $year, 'em' => $em, 'emLabel' => em_filter_label($em, $year),
                              'emWindow' => $emWindow],
    ];
}
