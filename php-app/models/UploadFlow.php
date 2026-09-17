<?php
/**
 * Upload Data entry flow — Academic Year → Data Type → the existing upload form.
 *
 * Faculty and Coordinator users no longer land straight on upload.php's form.
 * They first pick the academic year, then the kind of data (Faculty or
 * Student), and upload.php opens its form only once both are chosen. HoD and
 * Admin keep opening the form directly.
 *
 * This is not a second academic-year system. The years offered are the
 * existing academic_years() narrowed to the one the Admin activated (FEAT-02,
 * active_academic_year()), because upload.php stamps every new record with
 * that year and never with a client-supplied one.
 *
 * The two data types are built from the existing record grouping,
 * record_categories(), so no record type is added, removed or duplicated:
 *     Faculty Data → "faculty" + "activity" categories — the side FEAT-06
 *                    attributes to faculty (by created_by)
 *     Student Data → "student" category
 *
 * The choice lives in the server-side session, never in the URL, and is
 * re-validated on every request: it must belong to the signed-in user and to
 * this login (session id), and its year must still be open.
 */

require_once __DIR__ . '/Record.php';   // record_types(), record_categories(); loads Target.php

const UPLOAD_FLOW_SESSION_KEY = 'upload_flow';

/** Does this user go through the selection screens before the form? */
function upload_flow_applies(array $user): bool
{
    return in_array($user['role'] ?? '', ['Faculty', 'Coordinator'], true);
}

/**
 * Academic years a record can be uploaded for: all valid academic years from FEAT-02.
 * Taken from academic_years() so the list is the existing one, not a new one.
 */
function upload_flow_years(): array
{
    return academic_years();
}

/**
 * The two data types, each with the record types (upload.php tab keys) it
 * opens, in upload.php's existing tab order. A type that no category claims
 * falls under Faculty Data, as FEAT-06 files it with the faculty side.
 */
function upload_flow_data_types(): array
{
    static $defs = null;
    if ($defs !== null) {
        return $defs;
    }

    $defs = [
        'faculty' => ['label' => 'FACULTY DATA', 'icon' => 'file-text', 'categories' => ['faculty', 'activity'],
                      'description' => 'Faculty academic records'],
        'student' => ['label' => 'STUDENT DATA', 'icon' => 'users', 'categories' => ['student'],
                      'description' => 'Student academic records'],
    ];

    $allTypes = array_keys(record_types());
    $studentTypes = ['internship', 'placement', 'summer_training', 'student_achievement', 'student_participation', 'nptel', 'online_course'];

    $defs['faculty']['types'] = $allTypes;
    $defs['student']['types'] = array_values(array_filter($studentTypes, fn($t) => in_array($t, $allTypes, true)));

    return $defs;
}

/** Which data type a record type belongs to, or null for an unknown type. */
function upload_flow_data_type_of(string $recordType): ?string
{
    foreach (upload_flow_data_types() as $key => $def) {
        if (in_array($recordType, $def['types'], true)) {
            return $key;
        }
    }
    return null;
}

/**
 * The user's current choice, validated. Keys:
 *   year       — chosen year, only while it is still open (else null)
 *   data_type  — chosen data type, only alongside a valid year (else null)
 *   return_to  — the in-app page the flow was entered from (else null)
 *   stale_year — a year chosen earlier that is no longer open (else null)
 */
function upload_flow_state(array $user): array
{
    $state = ['year' => null, 'data_type' => null, 'return_to' => null, 'stale_year' => null];
    $raw   = $_SESSION[UPLOAD_FLOW_SESSION_KEY] ?? null;

    // Nothing chosen yet by THIS user in THIS login.
    if (!is_array($raw)
        || (int) ($raw['user_id'] ?? 0) !== (int) $user['id']
        || ($raw['sid'] ?? '') !== session_id()) {
        return $state;
    }

    $state['return_to'] = is_string($raw['return_to'] ?? null) ? $raw['return_to'] : null;

    $year = $raw['year'] ?? null;
    if (!is_string($year) || $year === '') {
        return $state;
    }
    if (!in_array($year, upload_flow_years(), true)) {
        $state['stale_year'] = $year;
        return $state;
    }
    $state['year'] = $year;

    $dataType = $raw['data_type'] ?? null;
    $defs     = upload_flow_data_types();
    if (is_string($dataType) && !empty($defs[$dataType]['types'])) {
        $state['data_type'] = $dataType;
    }

    return $state;
}

/** Merge changes into this user's stored choice (starting fresh if it isn't theirs). */
function upload_flow_store(array $user, array $changes): void
{
    $raw = $_SESSION[UPLOAD_FLOW_SESSION_KEY] ?? null;
    if (!is_array($raw)
        || (int) ($raw['user_id'] ?? 0) !== (int) $user['id']
        || ($raw['sid'] ?? '') !== session_id()) {
        $raw = [];
    }

    $_SESSION[UPLOAD_FLOW_SESSION_KEY] = array_merge(
        ['year' => null, 'data_type' => null, 'return_to' => null],
        $raw,
        $changes,
        ['user_id' => (int) $user['id'], 'sid' => session_id()]
    );
}

/** Step 1 — choose the academic year. Returns [ok, errorMessage]. */
function upload_flow_choose_year(array $user, $year): array
{
    $year = is_string($year) ? trim($year) : '';

    if ($year === '') {
        return [false, 'Please select an academic year to continue.'];
    }
    if (!is_valid_academic_year($year)) {
        return [false, 'Please select a valid academic year from the list.'];
    }
    if (!in_array($year, upload_flow_years(), true)) {
        return [false, 'Please select a valid academic year from the list.'];
    }
    if (academic_year_is_locked($year)) {
        return [false, "Academic year {$year} cycle is currently locked by the Administrator. New record submissions for {$year} are frozen."];
    }

    // A new year means the data type must be chosen again for it.
    upload_flow_store($user, ['year' => $year, 'data_type' => null]);
    return [true, null];
}

/** Step 2 — choose the data type. Needs a valid year first. Returns [ok, errorMessage]. */
function upload_flow_choose_data_type(array $user, $dataType): array
{
    if (upload_flow_state($user)['year'] === null) {
        return [false, 'Please select the academic year first.'];
    }

    $dataType = is_string($dataType) ? trim($dataType) : '';
    $defs     = upload_flow_data_types();

    if ($dataType === '') {
        return [false, 'Please choose Faculty Data or Student Data to continue.'];
    }
    if (!isset($defs[$dataType])) {
        return [false, 'Please choose a valid data type: Faculty Data or Student Data.'];
    }
    if (empty($defs[$dataType]['types'])) {
        return [false, $defs[$dataType]['label'] . ' is not available yet.'];
    }

    upload_flow_store($user, ['data_type' => $dataType]);
    return [true, null];
}

/**
 * Remember the in-app page the flow was entered from, so Back on the
 * Academic Year screen returns there. Only a page of this app on this host
 * is kept — it is rebuilt from a known file name, so it can never point
 * off-site — and upload.php itself is ignored so Back never loops.
 */
function upload_flow_remember_return(array $user): void
{
    $referer = (string) ($_SERVER['HTTP_REFERER'] ?? '');
    $parts   = $referer !== '' ? parse_url($referer) : false;
    if (!$parts || empty($parts['host']) || empty($parts['path'])) {
        return;
    }

    $refHost = strtolower($parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : ''));
    if ($refHost !== strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''))) {
        return;
    }

    $page = basename($parts['path']);
    if (!preg_match('/^[a-z0-9][a-z0-9-]*\.php$/', $page)
        || in_array($page, ['upload.php', 'login.php', 'logout.php', 'denied.php'], true)
        || !is_file(dirname(__DIR__) . '/' . $page)) {
        return;
    }

    upload_flow_store($user, ['return_to' => $page . (isset($parts['query']) ? '?' . $parts['query'] : '')]);
}
