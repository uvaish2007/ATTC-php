<?php
/**
 * Dashboard data — the PHP port of the Node dashboardController.
 *
 * Roles are scoped server-side: Admin/Director see the whole institution and
 * may filter by department; HoD/Coordinator/Faculty are pinned to their own
 * department. Faculty additionally get a personal (own-records) view.
 */

require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/Target.php';   // academic_years()

/**
 * Every record type the dashboard counts, and how to count it. This is the one
 * place that lists them, so a new type shows up on the dashboard automatically.
 *
 *   group  faculty | activity | student — how the type is grouped on screen.
 *   year   the table has an academic_year column, so the year filter applies.
 *   dedup  the type stores one row per student for a *group* activity (a team
 *          event is entered once per participant). The academy counts such an
 *          activity ONCE — dedup lists the columns that identify the one event,
 *          so five team-mates are not counted as five. Each student still keeps
 *          their own row, which is their individual credit in the per-student
 *          report; only the institution tally is de-duplicated.
 */
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

/**
 * How a metric is counted. A plain type counts every row; a group activity
 * counts each distinct event once, so a team entered as many student rows is
 * one activity in the institution tally.
 */
function metric_count_expr(array $m): string
{
    if (!empty($m['dedup'])) {
        $cols = implode(', ', array_map(fn($c) => "`$c`", $m['dedup']));
        return "COUNT(DISTINCT $cols)";
    }
    return 'COUNT(*)';
}

/** Back-compat: every table now carries a department, so all metrics are here. */
function dept_metrics(): array  { return all_metrics(); }
function other_metrics(): array { return []; }

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
    $year   = trim((string) ($_GET['year'] ?? '')) ?: null;

    $metrics = all_metrics();

    // ---- per-department counts, one grouped query per metric ----
    // Group activities are de-duplicated here (metric_count_expr), so the
    // institution tally counts a team event once, not once per participant.
    $deptCounts = [];   // [department][metricKey] = n
    $seen = [];

    foreach ($metrics as $key => $m) {
        $expr   = metric_count_expr($m);
        $sql    = "SELECT department, $expr AS n FROM `{$m['table']}`";
        $where  = [];
        $params = [];
        if ($status !== null)             { $where[] = 'status = ?';        $params[] = $status; }
        if ($year !== null && $m['year']) { $where[] = 'academic_year = ?'; $params[] = $year; }
        if ($where) { $sql .= ' WHERE ' . implode(' AND ', $where); }
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
        foreach ($metrics as $key => $m) {
            $n = $deptCounts[$dept][$key] ?? 0;
            $counts[$key] = $n;
            $total += $n;
        }
        $matrixRows[] = ['department' => $dept, 'counts' => $counts, 'total' => $total];
    }

    // ---- scoped totals ----
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

    // ---- status pipeline (respects department + year, ignores status filter) ----
    $statusBreakdown = ['Draft' => 0, 'Submitted' => 0, 'Approved' => 0, 'Rejected' => 0];
    foreach ($metrics as $key => $m) {
        $expr   = metric_count_expr($m);
        $sql    = "SELECT status, $expr AS n FROM `{$m['table']}`";
        $where  = [];
        $params = [];
        if ($departmentFilter !== null)   { $where[] = 'department = ?';    $params[] = $departmentFilter; }
        if ($year !== null && $m['year']) { $where[] = 'academic_year = ?'; $params[] = $year; }
        if ($where) { $sql .= ' WHERE ' . implode(' AND ', $where); }
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
    $recent  = recent_activity($departmentFilter, $status, null, $year);
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
        'scope'          => ['department' => $departmentFilter, 'status' => $status, 'year' => $year],
        'isOversight'    => $isOversight,
        'departments'    => $departments,
        'years'          => academic_years(),
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
        // labels + values are both re-indexed 0..n so the page can zip them by
        // position (array_map over an assoc array would keep string keys).
        'chartData'      => [
            'labels' => array_values(array_map(fn($m) => $m['label'], $metrics)),
            'values' => array_values(array_map(fn($k) => (int) ($totals[$k] ?? 0), array_keys($metrics))),
        ],
        'recent'         => $recent,
        'targetProgress' => $targets,
        'targetSummary'  => target_summary($departmentFilter),
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
 * A whole-scope summary of the targets — so the dashboard can show how ALL
 * targets are doing at a glance, not just a handful. Returns the overall
 * achieved/target figure, a Met / On-track / Behind distribution, and a
 * per-department rollup (each department's aggregate progress).
 */
function target_summary(?string $department): array
{
    $sql    = 'SELECT department, target_value, achieved_value FROM targets';
    $params = [];
    if ($department !== null) {
        $sql .= ' WHERE department = ?';
        $params[] = $department;
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

        // A free-text target (e.g. the Executive-Meeting proforma rows) has no
        // record table to count against, so its progress is the achieved figure
        // the HoD entered. Record-backed metrics are still counted live below.
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

    // Institution-wide attainment per target type: the same target set folded by
    // its name, so the chart can show "how are we doing on each target" across
    // every department rather than a handful of single-department rows.
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
    usort($byMetric, fn($a, $b) => $a['percent'] <=> $b['percent']);   // worst first

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

/** Recent submissions across every record type. */
function recent_activity(?string $department, ?string $status, ?int $createdBy = null, ?string $year = null): array
{
    // table, label, its title column, and whether it keeps an academic_year.
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

        $sql = "SELECT `{$s['title']}` AS title, status, created_at, department FROM `{$s['table']}`";
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
