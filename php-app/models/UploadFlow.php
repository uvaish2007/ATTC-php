<?php
require_once __DIR__ . '/Record.php';   

const UPLOAD_FLOW_SESSION_KEY = 'upload_flow';

function upload_flow_applies(array $user): bool
{
    return in_array($user['role'] ?? '', ['Faculty', 'Coordinator'], true);
}

// Faculty skip the Faculty/Student choice and land on one upload home page.
function upload_flow_is_faculty(array $user): bool
{
    return ($user['role'] ?? '') === 'Faculty';
}

// The record types a Faculty member uploads, grouped the way the home page shows them.
function upload_flow_faculty_groups(): array
{
    $groups = array_intersect_key(record_categories(), array_flip(['faculty', 'activity']));
    return array_filter($groups, fn($g) => !empty($g['types']));
}

function upload_flow_faculty_types(): array
{
    $types = [];
    foreach (upload_flow_faculty_groups() as $group) {
        $types = array_merge($types, $group['types']);
    }
    return array_values(array_unique($types));
}

function upload_flow_years(): array
{
    return academic_years();
}

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

function upload_flow_data_type_of(string $recordType): ?string
{
    foreach (upload_flow_data_types() as $key => $def) {
        if (in_array($recordType, $def['types'], true)) {
            return $key;
        }
    }
    return null;
}

function upload_flow_state(array $user): array
{
    $state = ['year' => null, 'data_type' => null, 'return_to' => null, 'stale_year' => null];
    $raw   = $_SESSION[UPLOAD_FLOW_SESSION_KEY] ?? null;

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
    if (upload_flow_is_faculty($user) && $dataType !== 'faculty') {
        return [false, 'Faculty accounts upload Faculty Data only.'];
    }

    upload_flow_store($user, ['data_type' => $dataType]);
    return [true, null];
}

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
