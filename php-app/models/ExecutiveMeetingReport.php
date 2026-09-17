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

    return ($user['department'] ?? '') ?: null;       // HoD / Coordinator / Faculty
}

/** Whether this user may choose a department at all (drives the UI state). */
function em_can_pick_department(array $user): bool
{
    return in_array($user['role'] ?? '', ['Admin', 'Dean'], true);
}

/**
 * Faculty selectable in this scope. A Faculty user only ever gets themselves,
 * which matches report_records() pinning them to their own submissions.
 */
function em_faculty_options(array $user, ?string $dept): array
{
    if (($user['role'] ?? '') === 'Faculty') {
        return [['id' => (int) $user['id'], 'name' => $user['name'], 'department' => $user['department'] ?: '']];
    }

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
    $onlyMine = (($user['role'] ?? '') === 'Faculty') ? (int) $user['id'] : null;

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
        if ($onlyMine !== null && in_array('created_by', $cols, true)) {
            $sql     .= ' AND created_by = ?';
            $params[] = $onlyMine;
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

    return [
        'Department'        => $f['department'] ? department_full_name($f['department']) : 'All Departments',
        'Academic Year'     => $f['year'],
        'Faculty'           => $facultyLabel,
        'Student'           => $studentLabel,
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
    // record_from/record_to carry the FEAT-07 EM period and any recorded
    // meeting's cut-off, already combined by em_resolve_filters().
    $records = report_records($user, $f['department'], null, null, $f['record_from'], $f['record_to'], $f['year']);

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

    // FEAT-07: for one Executive Meeting, "achieved" is what was achieved
    // DURING it, counted by the existing target_record_count() over the
    // meeting's window. A target whose metric maps to no record type (pass
    // percentage, CGPA, …) has no dated records to count, so it is marked
    // unlinked rather than shown with the whole year's figure.
    if ($f['em'] !== 'all') {
        foreach ($targets as &$t) {
            $count               = target_record_count($t, $f['record_from'], $f['record_to']);
            $t['_em_unlinked']   = ($count === null);
            $t['achieved_value'] = $count;
        }
        unset($t);
    }
    if ($f['faculty_id'] !== null) {
        // A single faculty member has no targets of their own in this schema;
        // targets are set per department, so the department's targets stand as
        // the context for that person's contribution.
        $targets = array_values($targets);
    }

    $meetings = $f['meeting'] ? [$f['meeting']] : $f['meetings'];

    return [
        'filters'      => $f,
        'summary'      => em_filter_summary($user, $f),
        'faculty'      => $faculty,
        'student'      => $student,
        'activity'     => $activity,
        'by_type'      => $byType,
        'departments'  => array_keys($depts),
        'user_names'   => $names,
        'targets'      => $targets,
        'rollup'       => em_target_rollup($targets),
        'meetings'     => $meetings,
        'total'        => count($faculty) + count($student) + count($activity),
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
 * Build the deck. Sections with no data still produce ONE slide carrying a
 * plain explanation, so the presenter never hits a blank or missing section.
 */
function em_slides(array $ds): array
{
    $f       = $ds['filters'];
    $summary = $ds['summary'];
    $slides  = [];

    // 1 — Cover, with the applied filters.
    $slides[] = [
        'type'    => 'title',
        'title'   => 'EXECUTIVE MEETING REPORT',
        'summary' => $summary,
        'meeting' => $f['meeting'],
        'totals'  => [
            'records'  => $ds['total'],
            'faculty'  => count($ds['faculty']),
            'student'  => count($ds['student']),
            'targets'  => $ds['rollup']['count'],
        ],
    ];

    // 2 — Overall college development, from the record counts actually found.
    $slides[] = [
        'type'        => 'college',
        'title'       => 'Overall College Development',
        'summary'     => $summary,
        'by_type'     => $ds['by_type'],
        'departments' => $ds['departments'],
        'rollup'      => $ds['rollup'],
        'totals'      => [
            'records'  => $ds['total'],
            'faculty'  => count($ds['faculty']),
            'activity' => count($ds['activity']),
            'student'  => count($ds['student']),
            'meetings' => count($ds['meetings']),
        ],
    ];

    // 3 — Faculty achievements, paginated.
    $facultyRows = array_map(fn($r) => em_record_row($r, $ds['user_names']), $ds['faculty']);
    $pages       = em_paginate($facultyRows);
    if (!$pages) {
        $slides[] = [
            'type'    => 'empty',
            'title'   => 'Faculty Achievements',
            'summary' => $summary,
            'message' => 'No faculty achievements available for the selected filters.',
        ];
    } else {
        foreach ($pages as $i => $page) {
            $slides[] = [
                'type'        => 'records',
                'title'       => 'Faculty Achievements',
                'summary'     => $summary,
                'rows'        => $page,
                'page'        => $i + 1,
                'total_pages' => count($pages),
                'total_rows'  => count($facultyRows),
                'kind'        => 'faculty',
            ];
        }
    }

    // 4 — Activities & outreach, only when there is something to show.
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

    // 5 — Student information, paginated.
    $studentRows = array_map(fn($r) => em_record_row($r, $ds['user_names']), $ds['student']);
    $pages       = em_paginate($studentRows);
    if (!$pages) {
        $slides[] = [
            'type'    => 'empty',
            'title'   => 'Student Information',
            'summary' => $summary,
            'message' => 'No student data available for the selected filters.',
        ];
    } else {
        foreach ($pages as $i => $page) {
            $slides[] = [
                'type'        => 'records',
                'title'       => 'Student Achievements & Records',
                'summary'     => $summary,
                'rows'        => $page,
                'page'        => $i + 1,
                'total_pages' => count($pages),
                'total_rows'  => count($studentRows),
                'kind'        => 'student',
            ];
        }
    }

    // 6 — Target vs achieved, paginated, plus the rollup on each page.
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
    $pages = em_paginate($targetRows);
    if (!$pages) {
        $slides[] = [
            'type'    => 'empty',
            'title'   => 'Target vs Achieved',
            'summary' => $summary,
            'message' => 'No target data available for the selected filters.',
        ];
    } else {
        foreach ($pages as $i => $page) {
            $slides[] = [
                'type'        => 'targets',
                'title'       => 'Target vs Achieved',
                'summary'     => $summary,
                'rows'        => $page,
                'rollup'      => $ds['rollup'],
                'page'        => $i + 1,
                'total_pages' => count($pages),
            ];
        }
    }

    // 7 — The meetings themselves.
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
    if (!$meetingRows) {
        $slides[] = [
            'type'    => 'empty',
            'title'   => 'Executive Meetings',
            'summary' => $summary,
            'message' => 'No Executive Meetings recorded for ' . $f['year'] . '.',
        ];
    } else {
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
    }

    // 8 — Close on the same numbers the deck opened with.
    $slides[] = [
        'type'    => 'closing',
        'title'   => 'Summary',
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
