<?php
/**
 * Dashboard data — the PHP port of the Node dashboardController.
 *
 * Roles are scoped server-side: Admin/Director see the whole institution and
 * may filter by department; HoD/Coordinator/Faculty are pinned to their own
 * department. Faculty additionally get a personal (own-records) view.
 */

require_once __DIR__ . '/../inc/db.php';

/** Metrics that carry a department (appear in the breakdown matrix). */
function dept_metrics(): array
{
    return [
        'journals'    => ['label' => 'Journals',    'table' => 'journal_publications'],
        'books'       => ['label' => 'Books',       'table' => 'book_publications'],
        'conferences' => ['label' => 'Conferences', 'table' => 'conference_publications'],
        'patents'     => ['label' => 'Patents',     'table' => 'patents'],
        'fdp'         => ['label' => 'FDP',         'table' => 'fdp'],
        'mou'         => ['label' => 'MoUs',        'table' => 'mou'],
        'events'      => ['label' => 'Events',      'table' => 'events'],
        'nptel'       => ['label' => 'NPTEL',       'table' => 'nptel'],
    ];
}

/** Department-less metrics (institution-wide only). */
function other_metrics(): array
{
    return [
        'internships' => ['label' => 'Internships', 'table' => 'internships'],
        'placements'  => ['label' => 'Placements',  'table' => 'placements'],
    ];
}

/**
 * Build the full dashboard payload for a user, honouring the department/status
 * filters (which are ignored/forced for department-scoped roles).
 */
function dashboard_data(array $user): array
{
    $pdo = db();

    $isOversight = in_array($user['role'], ['Admin', 'Director'], true);

    // Server-side scope: only oversight roles may choose a department.
    if ($isOversight) {
        $departmentFilter = trim((string) ($_GET['department'] ?? '')) ?: null;
    } else {
        $departmentFilter = $user['department'] ?: null;
    }

    $valid  = ['Draft', 'Submitted', 'Approved', 'Rejected'];
    $status = in_array(($_GET['status'] ?? ''), $valid, true) ? $_GET['status'] : null;

    $deptMetrics  = dept_metrics();
    $otherMetrics = other_metrics();

    // ---- per-department counts, one grouped query per dept-metric ----
    $deptCounts = [];   // [department][metricKey] = n
    $seen = [];

    foreach ($deptMetrics as $key => $m) {
        $sql = "SELECT department, COUNT(*) AS n FROM `{$m['table']}`";
        $params = [];
        if ($status !== null) {
            $sql .= ' WHERE status = ?';
            $params[] = $status;
        }
        $sql .= ' GROUP BY department';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        foreach ($stmt as $row) {
            $dept = $row['department'] !== null && $row['department'] !== '' ? $row['department'] : 'Unassigned';
            $seen[$dept] = true;
            $deptCounts[$dept][$key] = (int) $row['n'];
        }
    }

    // ---- department list: admin-managed if any, else derived from data ----
    $configured = $pdo->query('SELECT name FROM departments ORDER BY name')->fetchAll(PDO::FETCH_COLUMN);
    $dataDepartments = array_keys($seen);
    sort($dataDepartments);
    $usingConfigured = count($configured) > 0;
    $departments = $usingConfigured ? $configured : $dataDepartments;

    // ---- matrix rows (real data; single row when a department is selected) ----
    $matrixDepartments = $departmentFilter !== null
        ? array_values(array_filter($dataDepartments, fn($d) => $d === $departmentFilter))
        : $dataDepartments;

    $matrixRows = [];
    foreach ($matrixDepartments as $dept) {
        $counts = [];
        $total = 0;
        foreach ($deptMetrics as $key => $m) {
            $n = $deptCounts[$dept][$key] ?? 0;
            $counts[$key] = $n;
            $total += $n;
        }
        $matrixRows[] = ['department' => $dept, 'counts' => $counts, 'total' => $total];
    }

    // ---- scoped totals ----
    $totals = [];
    foreach ($deptMetrics as $key => $m) {
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
    foreach ($otherMetrics as $key => $m) {
        if ($departmentFilter !== null) {
            $totals[$key] = null; // can't attribute to a department
        } else {
            $sql = "SELECT COUNT(*) FROM `{$m['table']}`";
            if ($status !== null) {
                $stmt = $pdo->prepare($sql . ' WHERE status = ?');
                $stmt->execute([$status]);
            } else {
                $stmt = $pdo->query($sql);
            }
            $totals[$key] = (int) $stmt->fetchColumn();
        }
    }

    $grandTotal = 0;
    foreach ($totals as $v) {
        $grandTotal += (int) $v;
    }

    // ---- status pipeline (respects department filter, ignores status filter) ----
    $statusBreakdown = ['Draft' => 0, 'Submitted' => 0, 'Approved' => 0, 'Rejected' => 0];
    foreach (array_merge($deptMetrics, $otherMetrics) as $key => $m) {
        $hasDept = isset($deptMetrics[$key]);
        if ($departmentFilter !== null && !$hasDept) {
            continue;
        }
        $sql = "SELECT status, COUNT(*) AS n FROM `{$m['table']}`";
        $params = [];
        if ($departmentFilter !== null && $hasDept) {
            $sql .= ' WHERE department = ?';
            $params[] = $departmentFilter;
        }
        $sql .= ' GROUP BY status';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        foreach ($stmt as $row) {
            $s = $row['status'] ?: 'Draft';
            if (isset($statusBreakdown[$s])) {
                $statusBreakdown[$s] += (int) $row['n'];
            }
        }
    }

    // ---- top-line stats ----
    $stats = [
        'users'       => (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(),
        'departments' => count($departments),
        'metrics'     => (int) $pdo->query('SELECT COUNT(*) FROM metrics')->fetchColumn(),
        'targets'     => (int) $pdo->query('SELECT COUNT(*) FROM targets')->fetchColumn(),
        'totalRecords'=> $grandTotal,
        'pendingApprovals' => $statusBreakdown['Submitted'],
    ];

    // ---- users by role ----
    $usersByRole = ['Admin' => 0, 'Director' => 0, 'HoD' => 0, 'Coordinator' => 0, 'Faculty' => 0];
    foreach ($pdo->query('SELECT role, COUNT(*) AS n FROM users GROUP BY role') as $row) {
        if (isset($usersByRole[$row['role']])) {
            $usersByRole[$row['role']] = (int) $row['n'];
        }
    }

    // ---- recent submissions + how the targets are doing ----
    $recent  = recent_activity($departmentFilter, $status);
    $targets = target_progress($departmentFilter);

    /*
     * Target-vs-achieved is an oversight-only card, and it costs one COUNT per
     * target, so only Admin/Director pay for it.
     *
     * Its four filters ride on their own `t_` query parameters. They have to be
     * separate from the page's `department`/`status`: those two scope the whole
     * dashboard and `status` there means a record's review state, which is a
     * different vocabulary from a target's.
     */
    $attainment       = null;
    $attainFilters    = [];
    $attainOptions    = [];
    if ($isOversight) {
        $attainOptions = target_filter_options();
        $attainFilters = [
            'dept'   => trim((string) ($_GET['t_dept']   ?? '')) ?: null,
            'year'   => trim((string) ($_GET['t_year']   ?? '')) ?: null,
            'metric' => trim((string) ($_GET['t_metric'] ?? '')) ?: null,
            'status' => trim((string) ($_GET['t_status'] ?? '')) ?: null,
        ];
        // Only accept a value the data actually offers, so a hand-edited URL
        // cannot put the selects into a state the page can't show.
        foreach (['dept' => 'departments', 'year' => 'years', 'metric' => 'metrics', 'status' => 'statuses'] as $key => $list) {
            if ($attainFilters[$key] !== null && !in_array($attainFilters[$key], $attainOptions[$list], true)) {
                $attainFilters[$key] = null;
            }
        }
        $attainment = target_attainment($departmentFilter, $attainFilters);
    }

    return [
        'scope'          => ['department' => $departmentFilter, 'status' => $status],
        'isOversight'    => $isOversight,
        'departments'    => $departments,
        'usingConfigured'=> $usingConfigured,
        'stats'          => $stats,
        'metricDefs'     => array_map(
            fn($k, $m) => ['key' => $k, 'label' => $m['label']],
            array_keys($deptMetrics + $otherMetrics),
            array_values($deptMetrics + $otherMetrics)
        ),
        'totals'         => $totals,
        'matrix'         => [
            'metrics' => array_map(fn($k, $m) => ['key' => $k, 'label' => $m['label']], array_keys($deptMetrics), array_values($deptMetrics)),
            'rows'    => $matrixRows,
        ],
        'statusBreakdown'=> $statusBreakdown,
        'usersByRole'    => $usersByRole,
        // labels + values are both re-indexed 0..n so the page can zip them by
        // position (array_map over an assoc array would keep string keys).
        'chartData'      => (function () use ($departmentFilter, $deptMetrics, $otherMetrics, $totals) {
            $set = $departmentFilter !== null ? $deptMetrics : ($deptMetrics + $otherMetrics);
            return [
                'labels' => array_values(array_map(fn($m) => $m['label'], $set)),
                'values' => array_values(array_map(fn($k) => (int) ($totals[$k] ?? 0), array_keys($set))),
            ];
        })(),
        'recent'         => $recent,
        'targetProgress' => $targets,
        'targetAttainment' => $attainment,
        'targetFilters'    => $attainFilters,
        'targetOptions'    => $attainOptions,
    ];
}

/**
 * Targets in the current scope, with how far each one has been achieved.
 *
 * The percentage is capped at 100 so a target that was beaten does not draw a
 * bar wider than its track; the raw numbers are still shown beside it.
 */
function target_progress(?string $department, int $limit = 6): array
{
    $sql    = 'SELECT department, academic_year, metric, target_value, achieved_value FROM targets';
    $params = [];

    if ($department !== null) {
        $sql .= ' WHERE department = ?';
        $params[] = $department;
    }

    // LIMIT can't be a placeholder in MySQL, so it is cast to an int instead.
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

/**
 * Where the records behind each target metric live.
 *
 * The keys are the metric names the Targets page offers (the `metrics` table).
 * `dept` and `year` say whether that table can actually honour those two parts
 * of a target's scope: three tables keep no academic_year, and two keep no
 * department at all, so a target written against them is counted more broadly
 * than it reads. target_attainment() marks those rows so the page can say so
 * rather than quietly overstating the number.
 */
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

/**
 * Each fixed target beside what has actually been achieved against it.
 *
 * "Achieved" is counted live from Approved records, not read from
 * targets.achieved_value — that column is only ever filled in by hand on the
 * Targets page, so it drifts the moment somebody forgets. Counting the records
 * cannot drift.
 *
 * Rows come back furthest-behind first: a target that is already met is not
 * what an oversight dashboard is for. Percent is deliberately NOT capped at
 * 100, so a target that was beaten still shows its overshoot.
 *
 * Returns ['rows' => [...], 'summary' => [...]].
 */
function target_attainment(?string $department, array $filters = [], int $limit = 8): array
{
    $pdo     = db();
    $sources = target_metric_sources();

    /*
     * The chart carries its own filters, so a Director can narrow to one year
     * or one metric without disturbing the rest of the dashboard. A department
     * chosen on the card wins over the page-wide one — it can only ever narrow
     * the view, since the page filter has already scoped everything else.
     */
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

    $sql = 'SELECT department, academic_year, metric, target_value, status FROM targets';
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
            continue;   // a metric with no record table behind it — nothing to count
        }

        $where = ['status = ?'];
        $args  = ['Approved'];
        $exact = true;   // could every part of this target's scope be honoured?

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
            // A frozen target is an agreed commitment; anything else is still
            // provisional, and the chart draws the two differently.
            'frozen'     => ($row['status'] ?? '') === 'Approved',
        ];
    }

    usort($rows, fn($a, $b) => $a['percent'] <=> $b['percent']);

    return [
        'rows'    => array_slice($rows, 0, $limit),
        'summary' => [
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

/**
 * The values actually present in the targets table, for the chart's filters.
 *
 * Read from the data rather than from the master lists so the selects only ever
 * offer a choice that returns something — an empty chart is a dead end for the
 * person using it.
 */
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

/** Recent submissions across the main record types. */
function recent_activity(?string $department, ?string $status, ?int $createdBy = null): array
{
    $sources = [
        ['table' => 'journal_publications', 'label' => 'Journal',   'title' => 'paper_title',  'dept' => true],
        ['table' => 'book_publications',    'label' => 'Book',      'title' => 'title',        'dept' => true],
        ['table' => 'patents',              'label' => 'Patent',    'title' => 'title',        'dept' => true],
        ['table' => 'fdp',                  'label' => 'FDP',       'title' => 'title',        'dept' => true],
        ['table' => 'mou',                  'label' => 'MoU',       'title' => 'organization', 'dept' => true],
        ['table' => 'placements',           'label' => 'Placement', 'title' => 'student_name', 'dept' => false],
    ];

    $rows = [];
    foreach ($sources as $s) {
        $where = [];
        $params = [];
        if ($status !== null) { $where[] = 'status = ?'; $params[] = $status; }
        if ($department !== null && $s['dept']) { $where[] = 'department = ?'; $params[] = $department; }
        if ($createdBy !== null) { $where[] = 'created_by = ?'; $params[] = $createdBy; }

        $sql = "SELECT `{$s['title']}` AS title, status, created_at"
             . ($s['dept'] ? ', department' : ", NULL AS department")
             . " FROM `{$s['table']}`";
        if ($where) { $sql .= ' WHERE ' . implode(' AND ', $where); }
        $sql .= ' ORDER BY created_at DESC LIMIT 5';

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
    }

    usort($rows, fn($a, $b) => strtotime($b['at']) <=> strtotime($a['at']));
    return array_slice($rows, 0, 8);
}

/** Faculty personal view: only the signed-in user's own submissions. */
function my_dashboard_data(array $user): array
{
    $pdo = db();
    $uid = (int) $user['id'];

    $metrics = dept_metrics() + other_metrics();
    $statusBreakdown = ['Draft' => 0, 'Submitted' => 0, 'Approved' => 0, 'Rejected' => 0];
    $totals = [];

    foreach ($metrics as $key => $m) {
        $stmt = $pdo->prepare("SELECT status, COUNT(*) AS n FROM `{$m['table']}` WHERE created_by = ? GROUP BY status");
        $stmt->execute([$uid]);
        $sum = 0;
        foreach ($stmt as $row) {
            $sum += (int) $row['n'];
            $s = $row['status'] ?: 'Draft';
            if (isset($statusBreakdown[$s])) {
                $statusBreakdown[$s] += (int) $row['n'];
            }
        }
        $totals[$key] = $sum;
    }

    $total = array_sum($totals);

    return [
        'stats' => [
            'totalRecords' => $total,
            'approved'     => $statusBreakdown['Approved'],
            'pending'      => $statusBreakdown['Submitted'],
            'rejected'     => $statusBreakdown['Rejected'],
        ],
        'totals'          => $totals,
        'metricLabels'    => array_map(fn($m) => $m['label'], $metrics),
        'statusBreakdown' => $statusBreakdown,
        'recent'          => recent_activity(null, null, $uid),
    ];
}
