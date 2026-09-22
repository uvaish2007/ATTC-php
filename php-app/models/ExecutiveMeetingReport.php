<?php
/**
 * FEAT-06 — Executive Meeting Report: filters, dataset, slide deck.
 *
 * This is an orchestration layer, NOT a second reporting engine. Every figure
 * on every slide comes from the functions the Reports module already uses:
 *
 *   report_records()             models/Record.php   — records, already role-scoped
 *   target_report_items()        models/Target.php   — target vs achieved rows
 *   executive_meetings_for_year() models/Target.php  — the meetings themselves
 *   departments_all()            models/Department.php
 *   active_academic_year()       models/Target.php   — FEAT-02 global year
 *
 * The dataset is built ONCE per request (em_dataset) and the slides are derived
 * from that array in memory, so a college-wide deck costs the same queries as
 * the equivalent report page — no per-slide querying.
 */

require_once __DIR__ . '/Record.php';
require_once __DIR__ . '/Target.php';
require_once __DIR__ . '/Department.php';
require_once __DIR__ . '/ExecutiveMeeting.php';   // FEAT-07 EM1/EM2 engine
require_once __DIR__ . '/FacultyAchievement.php';
require_once __DIR__ . '/StudentAchievement.php';
require_once __DIR__ . '/Dashboard.php';
require_once __DIR__ . '/../inc/report_layout.php';   // department_full_name()

/** Rows shown on one table slide before it spills onto another slide. */
const EM_SLIDE_ROWS = 6;

/* --------------------------------------------------------------------------
 *  Filter resolution — every value validated server-side against the caller's
 *  own scope, so a hand-edited query string cannot widen what they may see.
 * ------------------------------------------------------------------------ */

/**
 * The department this user may actually report on.
 *
 * Deliberately mirrors report_records() (models/Record.php): Director and
 * Principal always see the whole institution, Admin and Dean may pick any
 * department, everyone else is pinned to their own. Keeping the rule identical
 * means the dropdown can never promise data the query then refuses.
 */
function em_department_scope(array $user, ?string $requested): ?string
{
    $role = $user['role'] ?? '';

    if (in_array($role, ['Director', 'Principal'], true)) {
        return null;                                  // institution-wide only
    }
    if (in_array($role, ['Admin', 'Dean'], true)) {
        $requested = trim((string) $requested);
        if ($requested === '') {
            return null;                              // all departments
        }
        // Only a department that actually exists.
        foreach (departments_all() as $d) {
            if ($d['name'] === $requested) {
                return $requested;
            }
        }
        return null;
    }

    // Strictly enforce department from authenticated user for HoD, Coordinator, Faculty.
    // Any manually requested parameter is completely ignored to prevent tampering and IDOR.
    $userDept = trim((string) ($user['department'] ?? ''));
    return $userDept !== '' ? $userDept : '__UNASSIGNED_DEPT__';
}

/** Whether this user may choose a department at all (drives the UI state). */
function em_can_pick_department(array $user): bool
{
    return in_array($user['role'] ?? '', ['Admin', 'Dean'], true);
}

/**
 * Faculty selectable in this scope.
 */
function em_faculty_options(array $user, ?string $dept): array
{
    $sql    = "SELECT id, name, department FROM users WHERE role IN ('Faculty', 'Coordinator', 'HoD')";
    $params = [];
    if ($dept) {
        $sql     .= ' AND department = ?';
        $params[] = $dept;
    }
    $sql .= ' ORDER BY name ASC';

    try {
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll() ?: [];
    } catch (\PDOException $e) {
        return [];
    }
}

/**
 * Students selectable in this scope.
 *
 * There is no students table in this schema — a student exists as rows in the
 * student-category record tables (internships, placements, summer_training,
 * student_achievements, student_participations), keyed by reg_no. So the list
 * is the distinct reg_no/name pairs already present in those tables, gathered
 * in ONE union query rather than one query per table.
 */
function em_student_options(array $user, ?string $dept, ?string $year): array
{
    $types    = record_types();
    $parts    = [];
    $params   = [];

    foreach (record_category_types('student') as $typeKey) {
        if (!isset($types[$typeKey])) {
            continue;
        }
        $table = $types[$typeKey]['table'];
        try {
            $cols = target_record_table_columns($table);
        } catch (\Exception $e) {
            continue;
        }
        if (!in_array('reg_no', $cols, true) || !in_array('student_name', $cols, true)) {
            continue;
        }

        $sql = "SELECT reg_no, student_name FROM `{$table}` WHERE reg_no IS NOT NULL AND reg_no <> ''";
        if ($dept && in_array('department', $cols, true)) {
            $sql     .= ' AND department = ?';
            $params[] = $dept;
        }
        // Same convention as records_list(): only year-bearing tables are
        // narrowed by year; the others contribute their rows unfiltered.
        if ($year && in_array('academic_year', $cols, true)) {
            $sql     .= ' AND academic_year = ?';
            $params[] = $year;
        }
        $parts[] = $sql;
    }

    if (!$parts) {
        return [];
    }

    try {
        $stmt = db()->prepare(implode(' UNION ', $parts) . ' ORDER BY student_name ASC');
        $stmt->execute($params);
        return $stmt->fetchAll() ?: [];
    } catch (\PDOException $e) {
        return [];
    }
}

/**
 * The target types selectable in this scope.
 *
 * There is no "target type" column in this schema - a target's type IS its
 * metric, numbered by the proforma (S.No 1..30). The master `metrics` table
 * lists only a handful of those names, so the options are read from the
 * targets themselves, through the very function the report uses
 * (target_report_items), department-name variants and all. The dropdown can
 * therefore never offer a type the report has no rows for.
 */
function em_target_options(?string $dept, ?string $year): array
{
    // Both the filter validation and the page ask for this list in the same
    // request; build it once.
    static $memo = [];
    $key = ($dept ?? '') . '|' . ($year ?? '');
    if (isset($memo[$key])) {
        return $memo[$key];
    }

    try {
        $rows = target_report_items($dept, $year);
    } catch (\PDOException $e) {
        return $memo[$key] = [];
    }

    $options = [];
    foreach ($rows as $r) {
        $metric = trim((string) ($r['metric'] ?? ''));
        if ($metric === '') {
            continue;
        }
        $serial = trim((string) ($r['serial_no'] ?? ''));
        $order  = isset($r['sort_order']) && $r['sort_order'] !== null ? (int) $r['sort_order'] : PHP_INT_MAX;

        // The same proforma item is set once per department, so one entry per
        // metric: the earliest position any department gave it, and the first
        // serial number actually recorded - none is invented.
        if (!isset($options[$metric])) {
            $options[$metric] = ['metric' => $metric, 'serial' => $serial, 'order' => $order, 'count' => 0];
        }
        $options[$metric]['order'] = min($options[$metric]['order'], $order);
        if ($options[$metric]['serial'] === '' && $serial !== '') {
            $options[$metric]['serial'] = $serial;
        }
        $options[$metric]['count']++;
    }

    $options = array_values($options);
    usort($options, static fn(array $a, array $b): int
        => [$a['order'], $a['metric']] <=> [$b['order'], $b['metric']]);

    foreach ($options as &$o) {
        $o['label'] = ($o['serial'] !== '' ? $o['serial'] . '. ' : '') . $o['metric'];
    }
    unset($o);

    return $memo[$key] = $options;
}

/**
 * Validate the submitted filters into the exact set used for both the preview
 * page and the presentation, so the two can never disagree.
 */
function em_resolve_filters(array $user, array $in): array
{
    // --- Academic year: the FEAT-02 global active year unless the user picked
    //     another real one from the existing academic_years() list.
    $activeYear = active_academic_year();
    $year       = trim((string) ($in['academic_year'] ?? ''));
    if ($year === '' || !in_array($year, academic_years(), true)) {
        $year = $activeYear;
    }

    // --- Department (role-scoped, existence-checked).
    $department = em_department_scope($user, $in['department'] ?? null);

    // --- Faculty: must be one of the ids this user may actually pick.
    $facultyId = (int) ($in['faculty_id'] ?? 0) ?: null;
    if ($facultyId !== null) {
        $allowed = array_map('intval', array_column(em_faculty_options($user, $department), 'id'));
        if (!in_array($facultyId, $allowed, true)) {
            $facultyId = null;            // silently widen to "All Faculty"
        }
    }

    // --- Student: must be a reg_no present in this scope.
    $studentReg = trim((string) ($in['student_reg'] ?? '')) ?: null;
    if ($studentReg !== null) {
        $allowed = array_column(em_student_options($user, $department, $year), 'reg_no');
        if (!in_array($studentReg, $allowed, true)) {
            $studentReg = null;
        }
    }

    // --- Target type: one of the metrics actually set in this scope, or all
    //     of them. Checked against the same rows the report will read, so a
    //     hand-edited target_metric= selects nothing it should not.
    $targetMetric = trim((string) ($in['target_metric'] ?? '')) ?: null;
    if ($targetMetric !== null) {
        $allowed = array_column(em_target_options($department, $year), 'metric');
        if (!in_array($targetMetric, $allowed, true)) {
            $targetMetric = null;         // silently widen to "All Targets"
        }
    }

    // --- Executive meeting: a real meeting_number recorded for this year, or
    //     all of them. Nothing is invented; the options ARE the table's rows.
    $meetings      = executive_meetings_for_year($year);
    $meetingNumber = trim((string) ($in['meeting_number'] ?? '')) ?: null;
    $meeting       = null;
    if ($meetingNumber !== null) {
        foreach ($meetings as $m) {
            if ((string) $m['meeting_number'] === $meetingNumber) {
                $meeting = $m;
                break;
            }
        }
        if (!$meeting) {
            $meetingNumber = null;
        }
    }

    // A named meeting reports the position as it stood when that meeting sat:
    // records submitted on or before its date. "All meetings" covers the year.
    $cutoff = $meeting ? (string) $meeting['meeting_date'] : null;

    // The dropdown lists each meeting number once. The table's unique key on
    // (academic_year, meeting_number) is not enforced in every database this
    // runs against, so a year can carry repeats — picking either resolves to
    // the same meeting, but offering the same number twice looks broken. The
    // full list is still what the Executive Meeting slide reports.
    $meetingOptions = [];
    foreach ($meetings as $m) {
        $meetingOptions[(string) $m['meeting_number']] ??= $m;
    }

    // FEAT-07: the Executive Meeting period (All / EM1 / EM2), resolved by the
    // one EM engine in models/ExecutiveMeeting.php — no dates are compared here.
    // It combines with a recorded meeting's cut-off: the record window is the
    // EM period, ending no later than that meeting's date.
    $em = em_filter_value($in['em'] ?? null);
    [$recordFrom, $recordTo] = em_intersect_period($em, null, $cutoff, $year);

    return [
        'year'           => $year,
        'active_year'    => $activeYear,
        'is_active_year' => $year === $activeYear,
        'department'     => $department,
        'faculty_id'     => $facultyId,
        'student_reg'    => $studentReg,
        'target_metric'  => $targetMetric,
        'meeting_number' => $meetingNumber,
        'meeting'        => $meeting,
        'meetings'       => $meetings,
        'meeting_options' => array_values($meetingOptions),
        'cutoff_date'    => $cutoff,
        'em'             => $em,
        'record_from'    => $recordFrom,
        'record_to'      => $recordTo,
    ];
}

/** The filters as a query string, so Present carries exactly what was applied. */
function em_filter_query(array $f): array
{
    return array_filter([
        'department'     => $f['department'],
        'academic_year'  => $f['year'],
        'faculty_id'     => $f['faculty_id'],
        'student_reg'    => $f['student_reg'],
        'target_metric'  => $f['target_metric'] ?? null,
        'meeting_number' => $f['meeting_number'],
        'em'             => $f['em'] !== 'all' ? $f['em'] : null,   // FEAT-07
    ], fn($v) => $v !== null && $v !== '');
}

/** Human-readable filter summary, shown on the page and on every slide. */
function em_filter_summary(array $user, array $f): array
{
    $facultyLabel = 'All Faculty';
    if ($f['faculty_id']) {
        foreach (em_faculty_options($user, $f['department']) as $o) {
            if ((int) $o['id'] === $f['faculty_id']) {
                $facultyLabel = $o['name'];
                break;
            }
        }
    }

    $studentLabel = 'All Students';
    if ($f['student_reg']) {
        foreach (em_student_options($user, $f['department'], $f['year']) as $o) {
            if ($o['reg_no'] === $f['student_reg']) {
                $studentLabel = $o['student_name'] . ' (' . $o['reg_no'] . ')';
                break;
            }
        }
    }

    // The target type, shown by its proforma number when it has one.
    $targetLabel = 'All Targets';
    if (!empty($f['target_metric'])) {
        $targetLabel = (string) $f['target_metric'];
        foreach (em_target_options($f['department'], $f['year']) as $o) {
            if ($o['metric'] === $f['target_metric']) {
                $targetLabel = $o['label'];
                break;
            }
        }
    }

    return [
        'Department'        => $f['department'] ? department_full_name($f['department']) : 'All Departments',
        'Academic Year'     => $f['year'],
        'Faculty'           => $facultyLabel,
        'Student'           => $studentLabel,
        'Targets'           => $targetLabel,
        // FEAT-07: the EM1/EM2 period. The title slide reads this key.
        'Executive Meeting' => $f['em'] === 'all' ? 'All (EM1 & EM2)' : em_filter_label($f['em'], $f['year']),
        'Recorded Meeting'  => $f['meeting']
            ? 'Meeting #' . $f['meeting']['meeting_number'] . ' · ' . date('d M Y', strtotime($f['meeting']['meeting_date']))
            : 'All Recorded Meetings',
    ];
}

/* --------------------------------------------------------------------------
 *  Dataset — one build, reused by the preview page and the slide deck.
 * ------------------------------------------------------------------------ */

/**
 * Target vs achieved rollup. Same arithmetic as
 * faculty_achievement_presentation_data() in models/FacultyAchievement.php —
 * achieved/target as a percentage, remaining never negative — so the deck
 * agrees with the rest of the system rather than inventing a second rule.
 */
function em_target_rollup(array $targets): array
{
    $target   = 0;
    $achieved = 0;
    $unlinked = 0;
    foreach ($targets as $t) {
        // FEAT-07: an unlinked target has no in-meeting figure, so it is kept
        // out of both sums rather than dragging the percentage down.
        if (!empty($t['_em_unlinked'])) {
            $unlinked++;
            continue;
        }
        $target   += (int) ($t['target_value'] ?? 0);
        $achieved += (int) ($t['achieved_value'] ?? 0);
    }

    return [
        'target'     => $target,
        'achieved'   => $achieved,
        'remaining'  => max($target - $achieved, 0),
        'percentage' => $target > 0 ? round(($achieved / $target) * 100, 1) : null,
        'count'      => count($targets),
        'unlinked'   => $unlinked,
    ];
}

/**
 * Summary of Target Achievements for presentation mode first page.
 * Calculates:
 *  - academic_targets: total academic targets in scope
 *  - targets_achieved: count of targets where achieved_value >= target_value
 *  - targets_in_progress: count of targets currently in progress
 */
function em_target_summary(array $targets): array
{
    $totalCount = count($targets);
    $achieved   = 0;
    $inProgress = 0;

    foreach ($targets as $t) {
        $tv = (int) ($t['target_value'] ?? 0);
        $av = (int) ($t['achieved_value'] ?? 0);
        if ($tv > 0 && $av >= $tv) {
            $achieved++;
        } elseif ($tv > 0 && $av > 0 && $av < $tv) {
            $inProgress++;
        }
    }

    // If no targets have registered partial progress yet, but targets are assigned,
    // count active approved targets as in-progress.
    if ($inProgress === 0 && $achieved === 0) {
        foreach ($targets as $t) {
            $tv = (int) ($t['target_value'] ?? 0);
            $av = (int) ($t['achieved_value'] ?? 0);
            if ($tv > 0 && $av < $tv && in_array($t['status'] ?? '', ['Approved', 'Pending Review'], true)) {
                $inProgress++;
            }
        }
    }

    return [
        'academic_targets'    => $totalCount,
        'targets_achieved'    => $achieved,
        'targets_in_progress' => $inProgress,
        'targets_remaining'   => max($totalCount - $achieved, 0),
    ];
}

/**
 * Return short code for department (e.g. CSE, CSBS, EEE, MECH, etc.)
 */
function department_short_code(?string $dept): string
{
    $dept = trim((string) $dept);
    if (!$dept) return '';
    $key = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $dept));
    static $revMap = [
        'COMPUTERSCIENCEANDENGINEERING' => 'CSE',
        'COMPUTERSCIENCEANDBUSINESSSYSTEMS' => 'CSBS',
        'ARTIFICIALINTELLIGENCEANDDATASCIENCE' => 'AI&DS',
        'ELECTRONICSANDCOMMUNICATIONENGINEERING' => 'ECE',
        'ELECTRICALANDELECTRONICSENGINEERING' => 'EEE',
        'MECHANICALENGINEERING' => 'MECH',
        'CIVILENGINEERING' => 'CIVIL',
        'INFORMATIONTECHNOLOGY' => 'IT',
        'AGRICULTUREENGINEERING' => 'AGRI',
        'AERONAUTICALENGINEERING' => 'AERO',
        'MARINEENGINEERING' => 'MARINE',
        'ARTIFICIALINTELLIGENCEANDMACHINELEARNING' => 'AIML',
        'CYBERSECURITY' => 'CYBER',
        'CHEMICALENGINEERING' => 'CHEM',
        'ARCHITECTURE' => 'ARCH',
        'MASTEROFCOMPUTERAPPLICATIONS' => 'MCA',
        'MASTEROFBUSINESSADMINISTRATION' => 'MBA',
    ];
    if (isset($revMap[$key])) {
        return $revMap[$key];
    }
    if (strlen($dept) <= 6) {
        return strtoupper($dept);
    }
    return strtoupper(substr($dept, 0, 5));
}

/**
 * Detailed contributions breakdown for the executive UI slide.
 * Supports both College-wide department breakdown and single department category breakdown.
 */
function em_contributions_summary(array $targets, array $allRecords, ?string $deptScope = null): array
{
    $isDept = !empty($deptScope) && $deptScope !== 'All Departments';

    if (!$isDept) {
        // College-wide: Aggregate by Department
        $stats = [];
        foreach ($targets as $t) {
            $dept = trim((string) ($t['department'] ?? ''));
            if (!$dept) continue;
            $code = department_short_code($dept);
            if (!isset($stats[$code])) {
                $stats[$code] = [
                    'code'        => $code,
                    'name'        => department_full_name($dept) ?: $dept,
                    'target'      => 0,
                    'achieved'    => 0,
                    'in_progress' => 0,
                    'total'       => 0,
                ];
            }
            $tv = (int) ($t['target_value'] ?? 0);
            $av = (int) ($t['achieved_value'] ?? 0);
            $stats[$code]['target'] += $tv;
            $stats[$code]['achieved'] += $av;
            if ($tv > 0 && $av < $tv) {
                $stats[$code]['in_progress'] += max($tv - $av, 0);
            }
        }

        foreach ($allRecords as $r) {
            $dept = trim((string) ($r['department'] ?? ''));
            if (!$dept) continue;
            $code = department_short_code($dept);
            if (!isset($stats[$code])) {
                $stats[$code] = [
                    'code'        => $code,
                    'name'        => department_full_name($dept) ?: $dept,
                    'target'      => 0,
                    'achieved'    => 0,
                    'in_progress' => 0,
                    'total'       => 0,
                ];
            }
            $stats[$code]['total'] += 1;
        }

        foreach ($stats as $k => &$s) {
            if ($s['total'] === 0) {
                $s['total'] = $s['achieved'] + $s['in_progress'];
            }
            if ($s['target'] === 0 && $s['achieved'] === 0 && $s['total'] > 0) {
                $s['achieved'] = $s['total'];
            }
        }
        unset($s);

        $active = array_filter($stats, fn($s) => $s['target'] > 0 || $s['achieved'] > 0 || $s['total'] > 0);
        uasort($active, fn($a, $b) => $b['total'] <=> $a['total'] ?: $b['achieved'] <=> $a['achieved']);

        $totalCount = array_sum(array_column($active, 'total'));
        $totalTarget = array_sum(array_column($active, 'target'));
        $totalAchieved = array_sum(array_column($active, 'achieved'));
        $totalInProg = array_sum(array_column($active, 'in_progress'));

        $top3 = array_slice($active, 0, 3, true);

        // Donut slices
        $slices = [];
        $palette = ['#1B65C5', '#F59E0B', '#168A53', '#6366F1', '#EC4899', '#06B6D4', '#84CC16'];
        $i = 0;
        $otherTotal = 0;
        foreach ($active as $item) {
            if ($i < 3) {
                $pct = $totalCount > 0 ? round(($item['total'] / $totalCount) * 100) : 0;
                $slices[] = [
                    'label'      => $item['code'],
                    'count'      => $item['total'],
                    'percentage' => $pct,
                    'color'      => $palette[$i % count($palette)],
                ];
            } else {
                $otherTotal += $item['total'];
            }
            $i++;
        }
        if ($otherTotal > 0) {
            $pct = $totalCount > 0 ? round(($otherTotal / $totalCount) * 100) : 0;
            $slices[] = [
                'label'      => 'Other Depts',
                'count'      => $otherTotal,
                'percentage' => $pct,
                'color'      => '#94A3B8',
            ];
        }

        return [
            'mode'           => 'department',
            'title'          => 'All Departments Contribution',
            'sub_title'      => 'Top 3 Departments',
            'chart_label'    => 'Department-wise Contribution',
            'items'          => array_values($active),
            'top3'           => array_values($top3),
            'donut_slices'   => $slices,
            'total_target'   => $totalTarget,
            'total_achieved' => $totalAchieved,
            'total_in_prog'  => $totalInProg,
            'total_count'    => $totalCount,
        ];
    } else {
        // Single Department: Aggregate real target and record metrics
        $targetMap = [];
        foreach ($targets as $t) {
            $metric = trim((string) ($t['metric'] ?? ''));
            if ($metric !== '') {
                $tv = (int) ($t['target_value'] ?? 0);
                $av = (int) ($t['achieved_value'] ?? 0);
                $targetMap[$metric] = [
                    'target'   => $tv,
                    'achieved' => $av,
                ];
            }
        }

        $byType = [];
        foreach ($allRecords as $r) {
            $type = $r['_type_label'] ?? $r['type'] ?? 'Achievement';
            $byType[$type] = ($byType[$type] ?? 0) + 1;
        }
        arsort($byType);

        $items = [];
        $handledTargets = [];
        foreach ($byType as $type => $cnt) {
            $matched = null;
            $matchedMetric = null;
            foreach ($targetMap as $mName => $mData) {
                if (stripos($mName, $type) !== false || stripos($type, $mName) !== false) {
                    $matched = $mData;
                    $matchedMetric = $mName;
                    break;
                }
            }
            if ($matchedMetric !== null) {
                $handledTargets[$matchedMetric] = true;
            }
            $targetVal = $matched ? $matched['target'] : 0;
            $achievedVal = $matched ? $matched['achieved'] : $cnt;
            $inProgVal = max($targetVal - $achievedVal, 0);

            $items[] = [
                'code'        => strlen($type) > 12 ? substr($type, 0, 10) . '..' : $type,
                'name'        => $type,
                'target'      => $targetVal,
                'achieved'    => $achievedVal,
                'in_progress' => $inProgVal,
                'total'       => $cnt,
            ];
        }

        // Include any targets for this department that had no records yet
        foreach ($targetMap as $mName => $mData) {
            if (!isset($handledTargets[$mName])) {
                $tv = $mData['target'];
                $av = $mData['achieved'];
                $items[] = [
                    'code'        => strlen($mName) > 12 ? substr($mName, 0, 10) . '..' : $mName,
                    'name'        => $mName,
                    'target'      => $tv,
                    'achieved'    => $av,
                    'in_progress' => max($tv - $av, 0),
                    'total'       => $av,
                ];
            }
        }

        $top3 = array_slice($items, 0, 3);
        $totalCount = array_sum(array_column($items, 'total'));
        $totalTarget = array_sum(array_column($items, 'target'));
        $totalAchieved = array_sum(array_column($items, 'achieved'));
        $totalInProg = array_sum(array_column($items, 'in_progress'));

        $slices = [];
        $palette = ['#1B65C5', '#F59E0B', '#168A53', '#6366F1'];
        $i = 0;
        $otherTotal = 0;
        foreach ($items as $item) {
            if ($i < 3) {
                $pct = $totalCount > 0 ? round(($item['total'] / $totalCount) * 100) : 0;
                $slices[] = [
                    'label'      => $item['code'],
                    'count'      => $item['total'],
                    'percentage' => $pct,
                    'color'      => $palette[$i % count($palette)],
                ];
            } else {
                $otherTotal += $item['total'];
            }
            $i++;
        }
        if ($otherTotal > 0) {
            $pct = $totalCount > 0 ? round(($otherTotal / $totalCount) * 100) : 0;
            $slices[] = [
                'label'      => 'Other Types',
                'count'      => $otherTotal,
                'percentage' => $pct,
                'color'      => '#94A3B8',
            ];
        }

        return [
            'mode'           => 'category',
            'title'          => 'Category-wise Contribution',
            'sub_title'      => 'Top 3 Categories',
            'chart_label'    => 'Category Distribution',
            'items'          => $items,
            'top3'           => $top3,
            'donut_slices'   => $slices,
            'total_target'   => $totalTarget,
            'total_achieved' => $totalAchieved,
            'total_in_prog'  => $totalInProg,
            'total_count'    => $totalCount,
        ];
    }
}

/**
 * Everything the deck needs, from the smallest number of queries:
 *   1 x report_records()  (all record types, role-scoped)
 *   1 x target_report_items()
 *   1 x executive_meetings_for_year()  (already fetched during filter resolve)
 *   1 x users lookup for the submitter names
 */
function em_dataset(array $user, array $f): array
{
    // One pass over every record type, already scoped to this user's role and
    // narrowed by department, academic year and the meeting's cut-off date.
    // In presentation mode, we enable department-wide collection so faculty see their department's data.
    $records = report_records($user, $f['department'], null, null, $f['record_from'], $f['record_to'], $f['year'], true);

    // Which category each type belongs to, from the existing grouping.
    $catOfType = [];
    foreach (record_categories() as $catKey => $cat) {
        foreach ($cat['types'] as $typeKey) {
            $catOfType[$typeKey] = $catKey;
        }
    }

    $faculty  = [];
    $student  = [];
    $activity = [];
    $byType   = [];
    $depts    = [];

    foreach ($records as $r) {
        $typeKey = $r['_type_key'];
        $cat     = $catOfType[$typeKey] ?? 'activity';

        // A chosen faculty member narrows the faculty/activity side only —
        // those records are attributed by created_by, the same way
        // faculty_achievement_details() attributes them.
        if ($f['faculty_id'] !== null && $cat !== 'student'
            && (int) ($r['created_by'] ?? 0) !== $f['faculty_id']) {
            continue;
        }
        // A chosen student narrows the student side by reg_no.
        if ($f['student_reg'] !== null && $cat === 'student'
            && (string) ($r['reg_no'] ?? '') !== $f['student_reg']) {
            continue;
        }
        // Picking one faculty member means the deck is about that person, so
        // student rows they did not submit are not theirs to report.
        if ($f['faculty_id'] !== null && $cat === 'student'
            && (int) ($r['created_by'] ?? 0) !== $f['faculty_id']) {
            continue;
        }

        $byType[$r['_type_label']] = ($byType[$r['_type_label']] ?? 0) + 1;
        if (!empty($r['department'])) {
            $depts[$r['department']] = true;
        }

        if ($cat === 'student')      { $student[]  = $r; }
        elseif ($cat === 'faculty')  { $faculty[]  = $r; }
        else                         { $activity[] = $r; }
    }

    // Submitter names in one query, so the slides never look users up per row.
    $ids = array_values(array_unique(array_filter(array_map(
        fn($r) => (int) ($r['created_by'] ?? 0),
        array_merge($faculty, $activity)
    ))));
    $names = [];
    if ($ids) {
        try {
            $ph   = implode(',', array_fill(0, count($ids), '?'));
            $stmt = db()->prepare("SELECT id, name, department FROM users WHERE id IN ($ph)");
            $stmt->execute($ids);
            foreach ($stmt->fetchAll() as $u) {
                $names[(int) $u['id']] = $u;
            }
        } catch (\PDOException $e) {
            // Names simply fall back to whatever the record itself carries.
        }
    }

    // Target vs achieved — the existing report query, unchanged.
    $targets = target_report_items($f['department'], $f['year']);

    // One target type only, when one was chosen. It narrows the Target vs
    // Achieved section (and the rollup that section reports); the record
    // sections are unaffected, because a target is not a record.
    if (!empty($f['target_metric'])) {
        $targets = array_values(array_filter(
            $targets,
            static fn(array $t): bool => (string) ($t['metric'] ?? '') === $f['target_metric']
        ));
    }

    // For each target, compute achieved value over the meeting window or whole academic year
    foreach ($targets as &$t) {
        if ($f['em'] !== 'all') {
            $count               = target_record_count($t, $f['record_from'], $f['record_to']);
            $t['_em_unlinked']   = ($count === null);
            $t['achieved_value'] = $count;
        } else {
            $count               = target_record_count($t);
            $t['_em_unlinked']   = ($count === null);
            $t['achieved_value'] = $count;
        }
    }
    unset($t);

    if ($f['faculty_id'] !== null) {
        // A single faculty member has no targets of their own in this schema;
        // targets are set per department, so the department's targets stand as
        // the context for that person's contribution.
        $targets = array_values($targets);
    }

    $meetings = $f['meeting'] ? [$f['meeting']] : $f['meetings'];

    $allRecords = array_merge($faculty, $student, $activity);
    $contributions = em_contributions_summary($targets, $allRecords, $f['department']);

    // Dynamic Faculty Achievements & Student Achievements from active models
    $emWindow = null;
    if ($f['em'] !== 'all' && !empty($f['record_from']) && !empty($f['record_to'])) {
        $emWindow = ['from' => $f['record_from'], 'to' => $f['record_to']];
    }

    $facSummary = faculty_achievements_summary($user, $f['department'], $f['year'], null, $f['faculty_id'], $emWindow);
    $catCounts = $facSummary['categoryCounts'] ?? [];
    $facultyMetrics = [
        'journals'       => (int) ($catCounts['Journal Publication'] ?? 0),
        'conferences'    => (int) ($catCounts['Conference Publication'] ?? 0),
        'books'          => (int) ($catCounts['Book / Book Chapter'] ?? 0),
        'events'         => (int) ($catCounts['Events Organized'] ?? 0),
        'training'       => (int) (($catCounts['FDP / Workshop / Seminar'] ?? 0) + ($catCounts['Training Programmes'] ?? 0)),
        'patents'        => (int) ($catCounts['Patents & Copyrights'] ?? 0),
        'other'          => (int) (($catCounts['SWAYAM-NPTEL Courses'] ?? 0) + ($catCounts['Online Courses'] ?? 0) + ($catCounts['MoUs Signed'] ?? 0)),
        'total'          => (int) ($facSummary['totalAchievements'] ?? 0),
        'approved'       => (int) ($facSummary['approvedRecords'] ?? 0),
        'pending'        => (int) ($facSummary['pendingRecords'] ?? 0),
        'approval_rate'  => (int) ($facSummary['approvalRate'] ?? 0),
        'total_faculty'  => (int) ($facSummary['totalFaculty'] ?? 0),
        'departments'    => (int) ($facSummary['departments'] ?? 0),
    ];

    $studSummary = student_achievements_summary($user, $f['department'], $f['year'], null, $f['student_reg'], $emWindow);
    $studCats = $studSummary['categoryCounts'] ?? [];
    $studentMetrics = [
        'nptel'          => (int) ($studCats['SWAYAM-NPTEL'] ?? 0),
        'internships'    => (int) ($studCats['Internships'] ?? 0),
        'placements'     => (int) ($studCats['Placements'] ?? 0),
        'online_courses' => (int) ($studCats['Online Courses'] ?? 0),
        'achievements'   => (int) ($studCats['Student Achievements'] ?? 0),
        'participations' => (int) ($studCats['Student Participations'] ?? 0),
        'training'       => (int) ($studCats['Summer / Winter Training'] ?? 0),
        'total'          => (int) ($studSummary['totalAchievements'] ?? 0),
        'total_students' => (int) ($studSummary['totalStudents'] ?? 0),
    ];

    $emStatus = em_status($f['year']);

    // Institutional Development & College-Wide Performance (EM-SPEC-07)
    $allDepts = departments_all();
    $totalDeptCount = count($allDepts) > 0 ? count($allDepts) : count($depts);
    $activeDeptCount = count($depts);

    $approvedRecords = count(array_filter($allRecords, fn($r) => in_array($r['status'] ?? '', ['Approved'], true)));
    $pendingRecords = count(array_filter($allRecords, fn($r) => in_array($r['status'] ?? '', ['Submitted', 'Dean Pending', 'HOD Pending', 'Pending Review'], true)));
    $draftRecords = count(array_filter($allRecords, fn($r) => in_array($r['status'] ?? '', ['Draft', ''], true)));
    $overallApprovalRate = count($allRecords) > 0 ? round(($approvedRecords / count($allRecords)) * 100, 1) : 0;

    $rollupData = em_target_rollup($targets);
    $targetSummaryData = em_target_summary($targets);

    // Legitimate Year-over-Year comparison where prior year records exist
    $allYears = academic_years();
    $currYearIdx = array_search($f['year'], $allYears, true);
    $prevYear = ($currYearIdx !== false && isset($allYears[$currYearIdx + 1])) ? $allYears[$currYearIdx + 1] : null;
    $prevYearRecordsCount = 0;
    $hasPrevYearData = false;
    $yoyDiff = null;
    $yoyPct = null;

    if ($prevYear !== null) {
        $prevRecords = report_records($user, $f['department'], null, null, null, null, $prevYear, true);
        $prevYearRecordsCount = count($prevRecords);
        if ($prevYearRecordsCount > 0) {
            $hasPrevYearData = true;
            $yoyDiff = count($allRecords) - $prevYearRecordsCount;
            $yoyPct = round(($yoyDiff / $prevYearRecordsCount) * 100, 1);
        }
    }

    $institutionalOverview = [
        'is_institutional'      => empty($f['department']),
        'department_name'       => !empty($f['department']) ? department_full_name($f['department']) : 'All Departments',
        'academic_year'         => $f['year'],
        'total_records'         => count($allRecords),
        'faculty_records'       => count($faculty),
        'student_records'       => count($student),
        'activity_records'      => count($activity),
        'faculty_achievements'  => $facultyMetrics['total'],
        'student_achievements'  => $studentMetrics['total'],
        'approved_records'      => $approvedRecords,
        'pending_records'       => $pendingRecords,
        'draft_records'         => $draftRecords,
        'approval_rate'         => $overallApprovalRate,
        'total_departments'     => $totalDeptCount,
        'active_departments'    => $activeDeptCount,
        'total_targets'         => $rollupData['count'] ?? count($targets),
        'targets_achieved'      => $targetSummaryData['targets_achieved'] ?? 0,
        'targets_in_progress'   => $targetSummaryData['targets_in_progress'] ?? 0,
        'target_realization'    => $rollupData['percentage'] ?? null,
        'prev_year'             => $prevYear,
        'prev_year_records'     => $prevYearRecordsCount,
        'has_prev_year_data'    => $hasPrevYearData,
        'yoy_diff'              => $yoyDiff,
        'yoy_pct'               => $yoyPct,
    ];

    return [
        'filters'                => $f,
        'summary'                => em_filter_summary($user, $f),
        'faculty'                => $faculty,
        'student'                => $student,
        'activity'               => $activity,
        'by_type'                => $byType,
        'departments'            => array_keys($depts),
        'user_names'             => $names,
        'targets'                => $targets,
        'rollup'                 => $rollupData,
        'target_summary'         => $targetSummaryData,
        'contributions'          => $contributions,
        'meetings'               => $meetings,
        'total'                  => count($faculty) + count($student) + count($activity),
        'faculty_summary'        => $facSummary,
        'faculty_metrics'        => $facultyMetrics,
        'student_summary'        => $studSummary,
        'student_metrics'        => $studentMetrics,
        'em_status'              => $emStatus,
        'institutional_overview' => $institutionalOverview,
    ];
}

/* --------------------------------------------------------------------------
 *  Slides
 * ------------------------------------------------------------------------ */

/** A record as the deck displays it — flattened, escaped at render time. */
function em_record_row(array $r, array $names): array
{
    $submitter = $names[(int) ($r['created_by'] ?? 0)]['name'] ?? '';

    // Prefer the name the record itself names; fall back to whoever filed it.
    $person = trim((string) ($r['_person'] ?? '')) ?: $submitter;

    // Any date the row actually carries; no invented dates.
    $date = $r['event_date'] ?? $r['academic_year'] ?? $r['created_at'] ?? '';
    if ($date && preg_match('/^\d{4}-\d{2}-\d{2}/', (string) $date)) {
        $date = date('d M Y', strtotime((string) $date));
    }

    return [
        'title'      => (string) ($r['_title'] ?? '(untitled)'),
        'type'       => (string) ($r['_type_label'] ?? ''),
        'person'     => (string) $person,
        'department' => (string) ($r['department'] ?? ''),
        'status'     => (string) ($r['status'] ?? ''),
        'date'       => (string) $date,
        'reg_no'     => (string) ($r['reg_no'] ?? ''),
    ];
}

/** Split rows into slide-sized pages so nothing is crammed onto one slide. */
function em_paginate(array $rows, int $per = EM_SLIDE_ROWS): array
{
    return $rows ? array_chunk($rows, $per) : [];
}

/**
 * Build the deck. A section with no rows in the current scope is left out
 * of the deck entirely, so the presentation never shows a heading with
 * nothing under it. When no section has anything, the deck is a single
 * slide that states why. The rule is the same for every role; what differs
 * is only the scope the server resolved for that role.
 */
function em_slides(array $ds): array
{
    $f       = $ds['filters'];
    $summary = $ds['summary'];
    $slides  = [];

    $hasAnyData = ($ds['total'] > 0)
        || (count($ds['targets']) > 0)
        || (!empty($ds['faculty_metrics']['total']))
        || (!empty($ds['student_metrics']['total']));

    // If scoped to a department and there is no presentation data:
    if (!empty($f['department']) && !$hasAnyData) {
        $deptName = department_full_name($f['department']);
        return [
            [
                'type'    => 'empty',
                'title'   => 'Executive Meeting Presentation',
                'summary' => $summary,
                'message' => "No presentation data available for {$deptName} for the selected Academic Year / Executive Meeting.",
            ],
        ];
    }

    if (!$hasAnyData) {
        return [
            [
                'type'    => 'empty',
                'title'   => 'Executive Meeting Presentation',
                'summary' => $summary,
                'message' => 'No presentation data available for the selected Academic Year / Executive Meeting.',
            ],
        ];
    }

    // Prepare target rows from real database targets
    $targetRows = [];
    foreach ($ds['targets'] as $t) {
        $tv       = (int) ($t['target_value'] ?? 0);
        $av       = (int) ($t['achieved_value'] ?? 0);
        $unlinked = !empty($t['_em_unlinked']);   // FEAT-07: no in-meeting figure
        $targetRows[] = [
            'metric'     => (string) ($t['metric'] ?? ''),
            'department' => (string) ($t['department'] ?? ''),
            'target'     => $tv,
            'achieved'   => $unlinked ? null : $av,
            'difference' => $unlinked ? null : max($tv - $av, 0),
            'percentage' => ($unlinked || $tv <= 0) ? null : round(($av / $tv) * 100, 1),
            'status'     => (string) ($t['status'] ?? ''),
            'unlinked'   => $unlinked,
        ];
    }

    // 1 — Slide 1: Executive Meeting & Academic Year Overview (EM1/EM2 info, scope, dates)
    $ts = $ds['target_summary'] ?? em_target_summary($ds['targets']);
    $isDept = !empty($f['department']);
    $deptLabel = $isDept ? department_full_name($f['department']) : '';
    $io = $ds['institutional_overview'] ?? [];

    $slides[] = [
        'type'          => 'title',
        'title'         => 'EXECUTIVE MEETING REPORT',
        'summary'       => $summary,
        'meeting'       => $f['meeting'],
        'em_status'     => $ds['em_status'] ?? em_status($f['year']),
        'totals'        => [
            'records'   => $ds['total'],
            'faculty'   => count($ds['faculty']),
            'student'   => count($ds['student']),
            'activity'  => count($ds['activity']),
            'targets'   => $ds['rollup']['count'],
            'approved'  => $ds['faculty_metrics']['approved'] ?? 0,
            'pending'   => $ds['faculty_metrics']['pending'] ?? 0,
        ],
        'rollup'        => $ds['rollup'],
        'contributions' => $ds['contributions'] ?? null,
    ];

    // 2 — Slide 2: Overall College Development & Improvement (EM-SPEC-07)
    $slides[] = [
        'type'          => 'college_development',
        'title'         => $isDept ? "{$deptLabel} Development & Improvement" : 'Overall College Development & Improvement',
        'summary'       => $summary,
        'overview'      => $io,
        'rollup'        => $ds['rollup'],
        'contributions' => $ds['contributions'] ?? null,
        'totals'        => [
            'records'        => $io['total_records'] ?? $ds['total'],
            'faculty'        => $io['faculty_achievements'] ?? ($ds['faculty_metrics']['total'] ?? 0),
            'student'        => $io['student_achievements'] ?? ($ds['student_metrics']['total'] ?? 0),
            'activity'       => $io['activity_records'] ?? count($ds['activity']),
            'targets'        => $io['total_targets'] ?? ($ds['rollup']['count'] ?? 0),
            'achieved'       => $io['targets_achieved'] ?? 0,
            'approved'       => $io['approved_records'] ?? 0,
            'pending'        => $io['pending_records'] ?? 0,
            'draft'          => $io['draft_records'] ?? 0,
            'approval_rate'  => $io['approval_rate'] ?? 0,
            'departments'    => $io['active_departments'] ?? count($ds['departments']),
            'all_depts'      => $io['total_departments'] ?? count($ds['departments']),
        ],
    ];

    // 3 — Slide 3: Overall Institutional Performance / Key Metrics (EM-SPEC-07)
    $slides[] = [
        'type'             => 'institutional_performance',
        'title'            => $isDept ? "{$deptLabel} Performance & Key Metrics" : 'Overall Institutional Performance',
        'summary'          => $summary,
        'overview'         => $io,
        'faculty_metrics'  => $ds['faculty_metrics'],
        'student_metrics'  => $ds['student_metrics'],
        'contributions'    => $ds['contributions'] ?? null,
        'rollup'           => $ds['rollup'],
        'target_summary'   => $ts,
        'totals'           => [
            'records'        => $io['total_records'] ?? $ds['total'],
            'faculty'        => count($ds['faculty']),
            'student'        => count($ds['student']),
            'activity'       => count($ds['activity']),
            'approval_rate'  => $io['approval_rate'] ?? 0,
            'target_rate'    => $io['target_realization'] ?? null,
        ],
    ];

    // 4 — Slide 4: Faculty Achievements Summary (Dynamic matching /faculty-achievements.php)
    $slides[] = [
        'type'            => 'faculty_summary',
        'title'           => 'Faculty Achievements',
        'summary'         => $summary,
        'metrics'         => $ds['faculty_metrics'],
        'category_counts' => $ds['faculty_summary']['categoryCounts'] ?? [],
        'totals'          => [
            'total'         => $ds['faculty_metrics']['total'],
            'approved'      => $ds['faculty_metrics']['approved'],
            'pending'       => $ds['faculty_metrics']['pending'],
            'approval_rate' => $ds['faculty_metrics']['approval_rate'],
            'total_faculty' => $ds['faculty_metrics']['total_faculty'],
        ],
    ];

    // 5 — Slide 5: Student Achievements Performance Matrix
    $slides[] = [
        'type'            => 'student_summary',
        'title'           => 'Student Achievements Performance Matrix',
        'summary'         => $summary,
        'metrics'         => $ds['student_metrics'],
        'category_counts' => $ds['student_summary']['categoryCounts'] ?? [],
        'totals'          => [
            'total'          => $ds['student_metrics']['total'],
            'total_students' => $ds['student_metrics']['total_students'],
        ],
    ];

    // 6 — Slide 6: Department Achievements / Milestones
    $slides[] = [
        'type'           => 'department_milestones',
        'title'          => $isDept ? "{$deptLabel} Milestones" : 'Department Milestones & Contributions',
        'summary'        => $summary,
        'by_type'        => $ds['by_type'],
        'departments'    => $ds['departments'],
        'rollup'         => $ds['rollup'],
        'target_summary' => $ts,
        'contributions'  => $ds['contributions'] ?? null,
        'totals'         => [
            'records'  => $ds['total'],
            'faculty'  => count($ds['faculty']),
            'activity' => count($ds['activity']),
            'student'  => count($ds['student']),
            'meetings' => count($ds['meetings']),
        ],
    ];

    // 7 — Slide 7: Target vs Achieved Summary
    $slides[] = [
        'type'                => 'target_summary',
        'title'               => 'Target vs Achieved',
        'summary'             => $summary,
        'academic_targets'    => (int) ($ts['academic_targets'] ?? 0),
        'targets_achieved'    => (int) ($ts['targets_achieved'] ?? 0),
        'targets_in_progress' => (int) ($ts['targets_in_progress'] ?? 0),
        'targets_remaining'   => (int) ($ts['targets_remaining'] ?? 0),
        'contributions'       => $ds['contributions'] ?? null,
        'meeting'             => $f['meeting'],
        'rollup'              => $ds['rollup'],
        'target_rows'         => $targetRows,
        'totals'              => [
            'records'  => $ds['total'],
            'faculty'  => count($ds['faculty']),
            'student'  => count($ds['student']),
            'targets'  => $ds['rollup']['count'],
        ],
    ];

    // Paginated Target Breakdown (if target rows exist)
    foreach (em_paginate($targetRows) as $i => $page) {
        $slides[] = [
            'type'        => 'targets',
            'title'       => 'Target vs Achieved Breakdown',
            'summary'     => $summary,
            'rows'        => $page,
            'rollup'      => $ds['rollup'],
            'page'        => $i + 1,
            'total_pages' => (int) ceil(count($targetRows) / EM_SLIDE_ROWS),
        ];
    }

    // Paginated verified Faculty records (only if rows exist)
    $facultyRows = array_map(fn($r) => em_record_row($r, $ds['user_names']), $ds['faculty']);
    foreach (em_paginate($facultyRows) as $i => $page) {
        $slides[] = [
            'type'        => 'records',
            'title'       => 'Faculty Achievements & Verified Records',
            'summary'     => $summary,
            'rows'        => $page,
            'page'        => $i + 1,
            'total_pages' => (int) ceil(count($facultyRows) / EM_SLIDE_ROWS),
            'total_rows'  => count($facultyRows),
            'kind'        => 'faculty',
        ];
    }

    // Paginated verified Student records (only if rows exist)
    $studentRows = array_map(fn($r) => em_record_row($r, $ds['user_names']), $ds['student']);
    foreach (em_paginate($studentRows) as $i => $page) {
        $slides[] = [
            'type'        => 'records',
            'title'       => 'Student Achievements & Records',
            'summary'     => $summary,
            'rows'        => $page,
            'page'        => $i + 1,
            'total_pages' => (int) ceil(count($studentRows) / EM_SLIDE_ROWS),
            'total_rows'  => count($studentRows),
            'kind'        => 'student',
        ];
    }

    // Paginated Activities & Outreach records (only if rows exist)
    $activityRows = array_map(fn($r) => em_record_row($r, $ds['user_names']), $ds['activity']);
    foreach (em_paginate($activityRows) as $i => $page) {
        $slides[] = [
            'type'        => 'records',
            'title'       => 'Activities & Outreach',
            'summary'     => $summary,
            'rows'        => $page,
            'page'        => $i + 1,
            'total_pages' => (int) ceil(count($activityRows) / EM_SLIDE_ROWS),
            'total_rows'  => count($activityRows),
            'kind'        => 'activity',
        ];
    }

    // Executive Meetings Summary (if meetings exist)
    $meetingRows = [];
    foreach ($ds['meetings'] as $m) {
        $meetingRows[] = [
            'number' => (string) $m['meeting_number'],
            'date'   => $m['meeting_date'] ? date('d M Y', strtotime((string) $m['meeting_date'])) : '',
            'status' => (string) ($m['status'] ?? ''),
            'notes'  => (string) ($m['notes'] ?? ''),
            'admin'  => (string) ($m['admin_name'] ?? ''),
        ];
    }
    foreach (em_paginate($meetingRows) as $i => $page) {
        $slides[] = [
            'type'        => 'meetings',
            'title'       => 'Executive Meeting Summary',
            'summary'     => $summary,
            'rows'        => $page,
            'page'        => $i + 1,
            'total_pages' => (int) ceil(count($meetingRows) / EM_SLIDE_ROWS),
        ];
    }

    // Performance Summary / Closing Slide
    $slides[] = [
        'type'    => 'closing',
        'title'   => 'Performance Summary',
        'summary' => $summary,
        'totals'  => [
            'records'  => $ds['total'],
            'faculty'  => count($ds['faculty']),
            'activity' => count($ds['activity']),
            'student'  => count($ds['student']),
        ],
        'rollup'  => $ds['rollup'],
    ];

    return $slides;
}
