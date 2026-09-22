<?php
/**
 * FEAT-07 — Executive Meeting schedule engine.
 *
 * The ONE source of truth for the EM1/EM2 schedule and everything derived from
 * it: which meeting is current, whether EM1 is locked, whether EM2 is active,
 * which records belong to which meeting, and whether a write is still allowed.
 * Pages, reports, dashboards and the FEAT-06 presentation all ask this file —
 * none of them compare dates on their own.
 *
 * Storage
 *   One app_settings row per academic year, key "em_schedule_{year}", holding
 *   the four dates as JSON. That is the store this project already uses for
 *   Admin choices made once for everyone, including per-year ones such as
 *   ay_locked_{year}; app_settings also carries updated_by / updated_at. No new
 *   table, no second configuration system.
 *
 * Which records belong to a meeting
 *   There is no meeting column on any record table, and executive_meetings
 *   rows are free-text meeting events, not EM1/EM2 periods. Every record,
 *   though, carries created_at, stamped by the database and protected from the
 *   submission form (see upload.php's $protected list). A record therefore
 *   belongs to EM1 or EM2 when it was submitted inside that meeting's
 *   configured window. Nothing historical is rewritten to make this work.
 *
 * Dates
 *   All four dates are whole days in the app timezone (APP_TIMEZONE). A window
 *   runs from 00:00:00 on its start date to 23:59:59 on its end date — the
 *   same inclusive convention records_list() uses for its period filter.
 *
 * Independence from FEAT-02
 *   The EM1 lock is computed from the schedule alone. It never reads or sets
 *   the academic-year lock (ay_locked_{year}); the year can stay open while
 *   EM1 is locked, and both checks apply independently.
 */

require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/Setting.php';
require_once __DIR__ . '/Target.php';   // active_academic_year(), academic_years(), is_valid_academic_year()

/** The two meetings the schedule configures, keyed by their filter value. */
const EM_MEETINGS = ['em1' => 'EM1', 'em2' => 'EM2'];

/* States a year's schedule can be in at a given moment. */
const EM_STATE_NOT_CONFIGURED = 'NOT_CONFIGURED';
const EM_STATE_BEFORE_EM1     = 'BEFORE_EM1';
const EM_STATE_EM1_ACTIVE     = 'EM1_ACTIVE';
const EM_STATE_BETWEEN        = 'BETWEEN_EM1_EM2';   // EM1 locked, EM2 upcoming
const EM_STATE_EM2_ACTIVE     = 'EM2_ACTIVE';
const EM_STATE_EM2_ENDED      = 'EM2_ENDED';

/** Shown wherever the active year has no schedule. */
const EM_NOT_CONFIGURED_MESSAGE = 'Executive Meeting schedule is not configured for the active Academic Year.';

/** Shown when a write touches locked EM1 data. */
const EM1_LOCKED_MESSAGE = 'EM1 is locked. Changes are no longer permitted.';

/* --------------------------------------------------------------------------
 *  Schedule storage
 * ------------------------------------------------------------------------ */

function em_schedule_setting_key(string $year): string
{
    return 'em_schedule_' . $year;
}

/**
 * The academic year's June–May span, matching current_academic_year_start()
 * in models/Target.php ("Academic year runs June–May"). "2026-27" is
 * 2026-06-01 .. 2027-05-31.
 */
function em_academic_year_span(string $year): ?array
{
    if (!preg_match('/^(\d{4})-\d{2}$/', $year, $m)) {
        return null;
    }
    $start = (int) $m[1];
    return ['from' => sprintf('%04d-06-01', $start), 'to' => sprintf('%04d-05-31', $start + 1)];
}

/**
 * Per-request cache of parsed schedules. Status is read by the header, the
 * dashboard and every filter on a page, so the row is parsed once; a save
 * clears its year so the same request never sees the old dates.
 */
function &em_schedule_cache(): array
{
    static $cache = [];
    return $cache;
}

/** Strict Y-m-d check: a real calendar date, nothing else. */
function em_parse_date(?string $value): ?string
{
    $value = trim((string) $value);
    if ($value === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return null;
    }
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return ($d && $d->format('Y-m-d') === $value) ? $value : null;
}

/**
 * The saved schedule for a year, or null when none is configured.
 *
 * Returns ['year', 'em1_start', 'em1_end', 'em2_start', 'em2_end',
 *          'updated_by', 'updated_by_name', 'updated_at'].
 * A stored value that is unreadable or no longer valid counts as not
 * configured, so a damaged row can never produce invented dates.
 */
function em_schedule_for_year(?string $year = null): ?array
{
    $year = $year ?: active_academic_year();

    $cache = &em_schedule_cache();
    if (array_key_exists($year, $cache)) {
        return $cache[$year];
    }

    $raw  = setting_get(em_schedule_setting_key($year));
    $data = $raw ? json_decode($raw, true) : null;
    if (!is_array($data)) {
        return $cache[$year] = null;
    }

    [$ok, , $clean] = em_schedule_validate(
        $year,
        $data['em1_start'] ?? null, $data['em1_end'] ?? null,
        $data['em2_start'] ?? null, $data['em2_end'] ?? null
    );
    if (!$ok) {
        return $cache[$year] = null;
    }

    // Who last saved it, from the columns app_settings already keeps.
    $meta = ['updated_by' => null, 'updated_by_name' => null, 'updated_at' => null];
    try {
        $stmt = db()->prepare(
            'SELECT s.updated_by, s.updated_at, u.name
               FROM app_settings s LEFT JOIN users u ON u.id = s.updated_by
              WHERE s.name = ?'
        );
        $stmt->execute([em_schedule_setting_key($year)]);
        if ($row = $stmt->fetch()) {
            $meta = [
                'updated_by'      => $row['updated_by'] !== null ? (int) $row['updated_by'] : null,
                'updated_by_name' => $row['name'],
                'updated_at'      => $row['updated_at'],
            ];
        }
    } catch (\PDOException $e) {
        // The dates are what matter; missing audit details are not fatal.
    }

    $dates = [
        'em1_start' => $clean['em1_start'] ?? null,
        'em1_end'   => $clean['em1_end'] ?? null,
        'em2_start' => $clean['em2_start'] ?? null,
        'em2_end'   => $clean['em2_end'] ?? null,
    ];

    return $cache[$year] = array_merge(['year' => $year], $dates, $meta);
}

/**
 * Validate a schedule. Returns [ok, errors[], clean-dates].
 *
 * Rules: valid format (Y-m-d); if a meeting has start, end is required (and vice-versa);
 * start <= end (end date cannot be before start date); EM2 starts after EM1 ends;
 * all dates inside the academic year June-May span.
 */
function em_schedule_validate(string $year, ?string $em1Start, ?string $em1End, ?string $em2Start, ?string $em2End): array
{
    $errors = [];

    if (!is_valid_academic_year($year)) {
        $errors[] = 'Please choose a valid academic year.';
    }

    $labels = [
        'em1_start' => 'EM1 start date', 'em1_end' => 'EM1 end date',
        'em2_start' => 'EM2 start date', 'em2_end' => 'EM2 end date',
    ];
    $given = ['em1_start' => $em1Start, 'em1_end' => $em1End, 'em2_start' => $em2Start, 'em2_end' => $em2End];
    $clean = [];

    // Parse and validate syntax for any non-empty date string
    foreach ($given as $key => $value) {
        $trimmed = trim((string) $value);
        if ($trimmed !== '') {
            $d = em_parse_date($trimmed);
            if ($d === null) {
                $errors[] = $labels[$key] . ' is not a valid date.';
            } else {
                $clean[$key] = $d;
            }
        }
    }

    // Pair checks: if start is present, end is required; if end is present, start is required
    $hasEm1Start = trim((string) $em1Start) !== '';
    $hasEm1End   = trim((string) $em1End) !== '';
    if ($hasEm1Start && !$hasEm1End) {
        $errors[] = 'EM1 end date is required.';
    } elseif (!$hasEm1Start && $hasEm1End) {
        $errors[] = 'EM1 start date is required.';
    }

    $hasEm2Start = trim((string) $em2Start) !== '';
    $hasEm2End   = trim((string) $em2End) !== '';
    if ($hasEm2Start && !$hasEm2End) {
        $errors[] = 'EM2 end date is required.';
    } elseif (!$hasEm2Start && $hasEm2End) {
        $errors[] = 'EM2 start date is required.';
    }

    // If completely empty, at least one meeting range must be provided
    if (!$hasEm1Start && !$hasEm1End && !$hasEm2Start && !$hasEm2End) {
        $errors[] = 'Please provide the Executive Meeting schedule dates.';
    }

    // Range checks: start must not be after end
    if (isset($clean['em1_start'], $clean['em1_end'])) {
        if ($clean['em1_start'] > $clean['em1_end']) {
            $errors[] = 'EM1 end date cannot be before EM1 start date.';
        }
    }
    if (isset($clean['em2_start'], $clean['em2_end'])) {
        if ($clean['em2_start'] > $clean['em2_end']) {
            $errors[] = 'EM2 end date cannot be before EM2 start date.';
        }
    }

    // Sequence check: EM2 cannot start before or on EM1 end date
    if (isset($clean['em1_end'], $clean['em2_start'])) {
        if ($clean['em2_start'] <= $clean['em1_end']) {
            $errors[] = 'EM2 cannot start before EM1 ends — choose an EM2 start date after '
                . date('d-m-Y', strtotime($clean['em1_end'])) . '.';
        }
    }

    // Span check: all provided dates must fall within academic year span
    $span = em_academic_year_span($year);
    if ($span) {
        foreach ($clean as $key => $d) {
            if ($d < $span['from'] || $d > $span['to']) {
                $errors[] = $labels[$key] . ' must fall within academic year ' . $year . ' ('
                    . date('d-m-Y', strtotime($span['from'])) . ' to '
                    . date('d-m-Y', strtotime($span['to'])) . ').';
            }
        }
    }

    return [empty($errors), $errors, $clean];
}

/**
 * Admin saves the schedule for a year. Callers must already have gated the
 * request to the Admin role and verified CSRF; this validates and stores.
 * Merges submitted dates with existing saved dates for that year.
 * Returns [ok, message].
 */
function em_schedule_save(string $year, ?string $em1Start, ?string $em1End, ?string $em2Start, ?string $em2End, int $adminId): array
{
    $year = trim($year);

    // Validate the submitted inputs directly first
    [$inputOk, $inputErrors, $inputClean] = em_schedule_validate($year, $em1Start, $em1End, $em2Start, $em2End);
    if (!$inputOk) {
        return [false, implode(' ', $inputErrors)];
    }

    // Retrieve existing schedule to allow merging when only one meeting is submitted
    $existing = em_schedule_for_year($year);

    $mergedEm1Start = array_key_exists('em1_start', $inputClean) ? $inputClean['em1_start'] : ($existing['em1_start'] ?? null);
    $mergedEm1End   = array_key_exists('em1_end', $inputClean)   ? $inputClean['em1_end']   : ($existing['em1_end'] ?? null);
    $mergedEm2Start = array_key_exists('em2_start', $inputClean) ? $inputClean['em2_start'] : ($existing['em2_start'] ?? null);
    $mergedEm2End   = array_key_exists('em2_end', $inputClean)   ? $inputClean['em2_end']   : ($existing['em2_end'] ?? null);

    // Validate the merged schedule
    [$ok, $errors, $clean] = em_schedule_validate($year, $mergedEm1Start, $mergedEm1End, $mergedEm2Start, $mergedEm2End);
    if (!$ok) {
        return [false, implode(' ', $errors)];
    }

    setting_set(em_schedule_setting_key($year), json_encode($clean), $adminId);

    $cache = &em_schedule_cache();
    unset($cache[$year]);

    return [true, "Executive Meeting schedule saved for {$year}."];
}

/* --------------------------------------------------------------------------
 *  Status — computed from the schedule and the clock, never stored
 * ------------------------------------------------------------------------ */

/** Now, in the app timezone. Tests may pass a fixed moment instead. */
function em_now(?DateTimeInterface $now = null): DateTimeImmutable
{
    return $now ? DateTimeImmutable::createFromInterface($now) : new DateTimeImmutable('now');
}

/** A meeting's window as full timestamps: start 00:00:00 to end 23:59:59. */
function em_window_bounds(array $schedule, string $meeting): array
{
    $start = $schedule[$meeting . '_start'] ?? null;
    $end   = $schedule[$meeting . '_end'] ?? null;
    if (!$start || !$end) {
        return [
            'from' => '9999-12-31 23:59:59',
            'to'   => '0001-01-01 00:00:00',
        ];
    }
    return [
        'from' => $start . ' 00:00:00',
        'to'   => $end . ' 23:59:59',
    ];
}

/**
 * The full status of a year's meetings at a moment.
 *
 * Returns:
 *   configured, year, schedule, state,
 *   current      'em1' | 'em2' | null   — the meeting in session right now
 *   em1_locked   true once EM1's end date has passed
 *   em2_active   true while EM2 is in session
 *   em2_upcoming true after EM1 locks and before EM2 opens
 *   label        short text for badges, e.g. "EM1 · LOCKED"
 *   detail       one sentence for status strips
 */
function em_status(?string $year = null, ?DateTimeInterface $now = null): array
{
    $year     = $year ?: active_academic_year();
    $schedule = em_schedule_for_year($year);

    $base = [
        'configured'   => false,
        'year'         => $year,
        'schedule'     => $schedule,
        'state'        => EM_STATE_NOT_CONFIGURED,
        'current'      => null,
        'em1_locked'   => false,
        'em2_active'   => false,
        'em2_upcoming' => false,
        'label'        => 'EM · NOT SET',
        'detail'       => EM_NOT_CONFIGURED_MESSAGE,
    ];
    if (!$schedule || empty($schedule['em1_start']) || empty($schedule['em1_end'])) {
        return $base;
    }

    $t   = em_now($now)->format('Y-m-d H:i:s');
    $em1 = em_window_bounds($schedule, 'em1');
    $hasEm2 = !empty($schedule['em2_start']) && !empty($schedule['em2_end']);
    $em2 = $hasEm2 ? em_window_bounds($schedule, 'em2') : null;
    $fmt = fn(string $d): string => date('d M Y', strtotime($d));

    $s = array_merge($base, ['configured' => true]);

    if ($t < $em1['from']) {
        $s['state']  = EM_STATE_BEFORE_EM1;
        $s['label']  = 'EM1 · UPCOMING';
        $s['detail'] = 'EM1 opens on ' . $fmt($schedule['em1_start']) . '.';
    } elseif ($t <= $em1['to']) {
        $s['state']   = EM_STATE_EM1_ACTIVE;
        $s['current'] = 'em1';
        $s['label']   = 'EM1 · ACTIVE';
        $s['detail']  = 'EM1 is in session until ' . $fmt($schedule['em1_end']) . '.';
    } elseif (!$hasEm2) {
        $s['state']      = EM_STATE_BETWEEN;
        $s['em1_locked'] = true;
        $s['label']      = 'EM1 · LOCKED';
        $s['detail']     = 'EM1 closed on ' . $fmt($schedule['em1_end']) . ' and is read-only.';
    } elseif ($t < $em2['from']) {
        $s['state']        = EM_STATE_BETWEEN;
        $s['em1_locked']   = true;
        $s['em2_upcoming'] = true;
        $s['label']        = 'EM1 · LOCKED';
        $s['detail']       = 'EM1 closed on ' . $fmt($schedule['em1_end']) . ' and is read-only. EM2 opens on '
                           . $fmt($schedule['em2_start']) . '.';
    } elseif ($t <= $em2['to']) {
        $s['state']      = EM_STATE_EM2_ACTIVE;
        $s['current']    = 'em2';
        $s['em1_locked'] = true;
        $s['em2_active'] = true;
        $s['label']      = 'EM2 · ACTIVE';
        $s['detail']     = 'EM2 is in session until ' . $fmt($schedule['em2_end']) . '. EM1 is locked.';
    } else {
        $s['state']      = EM_STATE_EM2_ENDED;
        $s['em1_locked'] = true;
        $s['label']      = 'EM2 · ENDED';
        $s['detail']     = 'EM2 ended on ' . $fmt($schedule['em2_end']) . '. EM1 is locked.';
    }

    return $s;
}

/** 'em1', 'em2', or null when neither meeting is in session. */
function em_current_meeting(?string $year = null, ?DateTimeInterface $now = null): ?string
{
    return em_status($year, $now)['current'];
}

/** True once EM1's end date has passed. False when no schedule exists. */
function em1_is_locked(?string $year = null, ?DateTimeInterface $now = null): bool
{
    return em_status($year, $now)['em1_locked'];
}

/** True while EM2 is in session. */
function em2_is_active(?string $year = null, ?DateTimeInterface $now = null): bool
{
    return em_status($year, $now)['em2_active'];
}

/* --------------------------------------------------------------------------
 *  Filtering — All / EM1 / EM2
 * ------------------------------------------------------------------------ */

/** Normalise a filter value from the request: 'all', 'em1' or 'em2'. */
function em_filter_value($raw): string
{
    $raw = strtolower(trim((string) $raw));
    return isset(EM_MEETINGS[$raw]) ? $raw : 'all';
}

/** The filter's display name, e.g. "EM1 (01 Sep 2026 – 30 Sep 2026)". */
function em_filter_label(string $em, ?string $year = null): string
{
    if (!isset(EM_MEETINGS[$em])) {
        return 'All Meetings';
    }
    $schedule = em_schedule_for_year($year);
    if (!$schedule) {
        return EM_MEETINGS[$em];
    }
    return EM_MEETINGS[$em] . ' (' . date('d M Y', strtotime($schedule[$em . '_start']))
        . ' – ' . date('d M Y', strtotime($schedule[$em . '_end'])) . ')';
}

/**
 * The date window (Y-m-d, inclusive) a filter value selects, or null for "all".
 *
 * A meeting filter on a year with no schedule selects NOTHING (an empty
 * window), rather than silently falling back to everything — "EM1" must never
 * show data that is not EM1.
 */
function em_filter_window(string $em, ?string $year = null): ?array
{
    if (!isset(EM_MEETINGS[$em])) {
        return null;
    }
    $schedule = em_schedule_for_year($year);
    if (!$schedule) {
        return ['from' => '9999-12-31', 'to' => '0001-01-01', 'empty' => true];
    }
    return ['from' => $schedule[$em . '_start'], 'to' => $schedule[$em . '_end'], 'empty' => false];
}

/**
 * Combine the EM filter with a period the page already has (from/to, Y-m-d or
 * null). Returns [from, to] for report_records() / records_list(), which then
 * do the actual filtering in SQL. With "all" the period is returned unchanged.
 */
function em_intersect_period(string $em, ?string $from, ?string $to, ?string $year = null): array
{
    $w = em_filter_window($em, $year);
    if ($w === null) {
        return [$from, $to];
    }
    $from = ($from === null || $from < $w['from']) ? $w['from'] : $from;
    $to   = ($to === null || $to > $w['to']) ? $w['to'] : $to;
    return [$from, $to];
}

/**
 * The academic year an EM-filtered query must also be restricted to.
 *
 * An EM1/EM2 window belongs to ONE year's schedule, so a record filed under a
 * different academic year is not that meeting's data even if it happened to be
 * submitted on dates inside the window. Returns null for "all", so a caller
 * that never filtered by year keeps exactly its existing behaviour.
 */
function em_filter_year(string $em, ?string $year = null): ?string
{
    return isset(EM_MEETINGS[$em]) ? ($year ?: active_academic_year()) : null;
}

/** An SQL fragment restricting $column to a window; appends its params. */
function em_window_sql(?array $window, array &$params, string $column = 'created_at'): string
{
    if ($window === null) {
        return '';
    }
    $params[] = $window['from'] . ' 00:00:00';
    $params[] = $window['to'] . ' 23:59:59';
    return " AND `{$column}` >= ? AND `{$column}` <= ?";
}

/** Which meeting a submission time belongs to: 'em1', 'em2' or null. */
function em_meeting_for_datetime(?string $datetime, ?string $year = null): ?string
{
    $schedule = em_schedule_for_year($year);
    if (!$schedule || !$datetime || ($ts = strtotime($datetime)) === false) {
        return null;
    }
    $t = date('Y-m-d H:i:s', $ts);
    foreach (array_keys(EM_MEETINGS) as $m) {
        $b = em_window_bounds($schedule, $m);
        if ($t >= $b['from'] && $t <= $b['to']) {
            return $m;
        }
    }
    return null;
}

/* --------------------------------------------------------------------------
 *  Write enforcement — called from the server-side write paths
 * ------------------------------------------------------------------------ */

/**
 * Windows whose data is read-only right now. Per FEAT-07 only EM1 locks, once
 * its end date has passed. Returned as a list so every caller enforces the
 * same set.
 */
function em_locked_windows(?string $year = null, ?DateTimeInterface $now = null): array
{
    $status = em_status($year, $now);
    if (!$status['em1_locked']) {
        return [];
    }
    return [em_window_bounds($status['schedule'], 'em1')];
}

/**
 * SQL that excludes locked-meeting records from an UPDATE, appending params.
 * Admin is exempt, exactly as with the FEAT-02 academic-year lock.
 */
function em_locked_exclusion_sql(string $role, ?string $year, array &$params, string $column = 'created_at'): string
{
    if ($role === 'Admin') {
        return '';
    }
    $sql = '';
    foreach (em_locked_windows($year) as $w) {
        $sql     .= " AND NOT (`{$column}` >= ? AND `{$column}` <= ?)";
        $params[] = $w['from'];
        $params[] = $w['to'];
    }
    return $sql;
}

/** Whether a record submitted at $createdAt is locked for this role. */
function em_record_is_locked(string $role, ?string $createdAt, ?string $year = null): bool
{
    if ($role === 'Admin' || !$createdAt || ($ts = strtotime($createdAt)) === false) {
        return false;
    }
    $t = date('Y-m-d H:i:s', $ts);
    foreach (em_locked_windows($year) as $w) {
        if ($t >= $w['from'] && $t <= $w['to']) {
            return true;
        }
    }
    return false;
}

/**
 * Why a NEW submission would be refused right now, or null when allowed.
 *
 * After EM1 closes and before EM2 opens, a new record can only be a late EM1
 * submission — EM1 is locked, so it is refused. Once EM2 opens, submissions
 * belong to EM2 and are accepted again. Admin is exempt, as with FEAT-02.
 */
function em_submission_block_reason(string $role, ?string $year = null, ?DateTimeInterface $now = null): ?string
{
    if ($role === 'Admin') {
        return null;
    }
    $status = em_status($year, $now);
    if ($status['state'] !== EM_STATE_BETWEEN) {
        return null;
    }
    return EM1_LOCKED_MESSAGE . ' New submissions open with EM2 on '
        . date('d M Y', strtotime($status['schedule']['em2_start'])) . '.';
}
