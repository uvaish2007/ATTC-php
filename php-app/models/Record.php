<?php
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/Target.php';   
require_once __DIR__ . '/ExecutiveMeeting.php';   

function record_types(): array
{
    static $filtered = null;
    if ($filtered !== null) {
        return $filtered;
    }

    $all = [
        'journal'    => ['table' => 'journal_publications',    'label' => 'Journal Publication',    'title_col' => 'paper_title'],
        'book'       => ['table' => 'book_publications',       'label' => 'Book / Chapter',         'title_col' => 'title'],
        'conference' => ['table' => 'conference_publications', 'label' => 'Conference Publication', 'title_col' => 'paper_title'],
        'patent'     => ['table' => 'patents',                 'label' => 'Patent / Copyright',     'title_col' => 'title'],
        'fdp'        => ['table' => 'fdp',                     'label' => 'FDP / Workshop',         'title_col' => 'title'],
        'mou'        => ['table' => 'mou',                     'label' => 'MoU',                    'title_col' => 'organization'],
        'event'      => ['table' => 'events',                  'label' => 'Event',                  'title_col' => 'event_title'],
        'nptel'      => ['table' => 'nptel',                   'label' => 'NPTEL',                  'title_col' => 'course_title'],
        'internship' => ['table' => 'internships',             'label' => 'Internship',             'title_col' => 'title'],
        'placement'  => ['table' => 'placements',              'label' => 'Placement',              'title_col' => 'student_name'],

        // Types added straight from the IQAC templates.
        'nss'                   => ['table' => 'nss',                    'label' => 'NSS / YRC / RRC',           'title_col' => 'activity_name', 'approval_required' => true],
        'online_course'         => ['table' => 'online_courses',         'label' => 'Online Course',             'title_col' => 'course_title',  'approval_required' => true],
        'co_curricular'         => ['table' => 'student_achievements',   'label' => 'Co-Curricular',             'title_col' => 'student_name',  'approval_required' => true, 'category_val' => 'Co-Curricular'],
        'extra_curricular'      => ['table' => 'student_achievements',   'label' => 'Extra-Curricular',          'title_col' => 'student_name',  'approval_required' => true, 'category_val' => 'Extra-Curricular'],
        'summer_training'       => ['table' => 'summer_training',        'label' => 'Summer / Winter Training',  'title_col' => 'title',         'approval_required' => true],
        'value_added'           => ['table' => 'value_added_courses',    'label' => 'Value Added Course',        'title_col' => 'course_title',  'approval_required' => true],
        'training'              => ['table' => 'training',               'label' => 'Training Programme',        'title_col' => 'event_title',   'approval_required' => true],
        'student_achievement'   => ['table' => 'student_achievements',   'label' => 'Student Achievement',       'title_col' => 'student_name',  'approval_required' => true],

        // ===== INSTITUTIONAL / DEPARTMENT ACHIEVEMENT CATEGORIES =====
        // 1. University Pass Percentage
        'inst_pass_percentage'     => ['table' => 'inst_pass_percentage',     'label' => 'University Pass Percentage',                'title_col' => 'programme',    'approval_required' => true],
        // 2. Rank in Anna University (College)
        'inst_college_rank'        => ['table' => 'inst_college_rank',        'label' => 'Rank in Anna University (College)',         'title_col' => 'student_name', 'approval_required' => true],
        // 3. University Rank (Students)
        'inst_student_rank'        => ['table' => 'inst_student_rank',        'label' => 'University Rank (Students)',                'title_col' => 'student_name', 'approval_required' => true],
        // 4. II & III Year Students Above 7.5 CGPA
        'inst_student_cgpa'        => ['table' => 'inst_student_cgpa',        'label' => 'Students Above 7.5 CGPA (II & III Year)',   'title_col' => 'student_name', 'approval_required' => true],
        // 5. Placement — Top MNCs
        'inst_placement_mnc'       => ['table' => 'inst_placement_mnc',       'label' => 'Placement in Top MNCs',                    'title_col' => 'student_name', 'approval_required' => true],
        // 6. Quality Publications (Scopus / SCI / Springer / UGC CARE / H-Index)
        'inst_publications'        => ['table' => 'inst_publications',        'label' => 'Quality Publications (Scopus/SCI/Springer)', 'title_col' => 'title',        'approval_required' => true],
        // 7. Books Publication
        'inst_books'               => ['table' => 'inst_books',               'label' => 'Books Publication',                        'title_col' => 'book_title',   'approval_required' => true],
        // 8. Book Chapter
        'inst_book_chapters'       => ['table' => 'inst_book_chapters',       'label' => 'Book Chapter',                             'title_col' => 'chapter_title','approval_required' => true],
        // 9. Patent Published / Design
        'inst_patents_published'   => ['table' => 'inst_patents_published',   'label' => 'Patent Published / Design',                'title_col' => 'title',        'approval_required' => true],
        // 10. Patent Granted
        'inst_patents_granted'     => ['table' => 'inst_patents_granted',     'label' => 'Patent Granted',                           'title_col' => 'title',        'approval_required' => true],
        // 11. Copyrights
        'inst_copyrights'          => ['table' => 'inst_copyrights',          'label' => 'Copyrights',                               'title_col' => 'title',        'approval_required' => true],
        // 12. Sponsored Research
        'inst_sponsored_research'  => ['table' => 'inst_sponsored_research',  'label' => 'Sponsored Research (in Lakhs)',             'title_col' => 'project_title','approval_required' => true],
        // 13. Consultancy Projects
        'inst_consultancy'         => ['table' => 'inst_consultancy',         'label' => 'Consultancy Projects (in Lakhs)',           'title_col' => 'project_title','approval_required' => true],
        // 14. Research Centre Recognition
        'inst_research_centre'     => ['table' => 'inst_research_centre',     'label' => 'Research Centre Recognition (Anna Univ.)',  'title_col' => 'recognition_name','approval_required' => true],
        // 15. IPR / Higher Studies / Entrepreneurship Programme
        'inst_ipr_programmes'      => ['table' => 'inst_ipr_programmes',      'label' => 'Programme on IPR / Higher Studies / Entrepreneurship', 'title_col' => 'programme_title', 'approval_required' => true],
        // 16. Faculty Online Certification Courses
        'inst_faculty_certifications' => ['table' => 'inst_faculty_certifications', 'label' => 'Faculty Online Certification Courses', 'title_col' => 'course_name', 'approval_required' => true],
        // 17. Industry Interaction / MOU / Industry Supported Lab
        'inst_mou_interactions'    => ['table' => 'inst_mou_interactions',    'label' => 'Industry Interaction / MOU / Industry Supported Lab', 'title_col' => 'industry_name', 'approval_required' => true],
        // 18. Student Industry Internship (4 weeks & above)
        'inst_internships'         => ['table' => 'inst_internships',         'label' => "Student Industry Internship (4 Weeks+)",    'title_col' => 'student_name', 'approval_required' => true],
        // 19. Summer Training (less than 4 weeks)
        'inst_summer_trainings'    => ['table' => 'inst_summer_trainings',    'label' => 'Summer Training (Less Than 4 Weeks)',       'title_col' => 'student_name', 'approval_required' => true],
        // 20. Quality Students Project + YouTube
        'inst_student_projects'    => ['table' => 'inst_student_projects',    'label' => 'Quality Student Projects (YouTube)',        'title_col' => 'project_title','approval_required' => true],
        // 21. Faculty FDP / Training / STTP / Conference
        'inst_faculty_participations' => ['table' => 'inst_faculty_participations', 'label' => 'Faculty FDP / Training / STTP / Conference', 'title_col' => 'programme_title', 'approval_required' => true],
        // 22. Professional Society Membership
        'inst_society_memberships' => ['table' => 'inst_society_memberships', 'label' => 'Membership in Professional Societies',     'title_col' => 'society_name', 'approval_required' => true],
        // 23. Newsletter
        'inst_newsletters'         => ['table' => 'inst_newsletters',         'label' => 'Newsletter',                               'title_col' => 'title',        'approval_required' => true],
        // 24. Student Online Certification
        'inst_student_certifications' => ['table' => 'inst_student_certifications', 'label' => 'Student Online Certification', 'title_col' => 'student_name', 'approval_required' => true],
        // 25. NSS Events
        'inst_nss_events'          => ['table' => 'inst_nss_events',          'label' => 'Events Conducted by NSS',                  'title_col' => 'event_name',   'approval_required' => true],
        // 26. Inter-Institute Events — Within State
        'inst_inter_inst_within'   => ['table' => 'inst_inter_inst_within',   'label' => 'Inter-Institute Events — Within State',    'title_col' => 'student_name', 'approval_required' => true],
        // 27. Inter-Institute Events — Outside State
        'inst_inter_inst_outside'  => ['table' => 'inst_inter_inst_outside',  'label' => 'Inter-Institute Events — Outside State',   'title_col' => 'student_name', 'approval_required' => true],
        // 28. Awards/Medals in Inter-Institute Events
        'inst_inter_inst_awards'   => ['table' => 'inst_inter_inst_awards',   'label' => 'Awards / Medals — Inter-Institute Events', 'title_col' => 'student_name', 'approval_required' => true],
        // 29. Value Added / Hands-On Training Courses
        'inst_value_added_courses' => ['table' => 'inst_value_added_courses', 'label' => 'Value Added / Hands-On Training Courses',  'title_col' => 'course_name',  'approval_required' => true],
        // 30. Sports — State Level
        'inst_sports_state'        => ['table' => 'inst_sports_state',        'label' => 'Sports Participation — State Level',       'title_col' => 'student_name', 'approval_required' => true],
        // 31. Sports — National Level
        'inst_sports_national'     => ['table' => 'inst_sports_national',     'label' => 'Sports Participation — National Level',    'title_col' => 'student_name', 'approval_required' => true],
        // 32. Innovation Events
        'inst_innovation_events'   => ['table' => 'inst_innovation_events',   'label' => 'Innovation Events (Every Department)',     'title_col' => 'event_name',   'approval_required' => true],
        // 33. IIC Activities
        'inst_iic_activities'      => ['table' => 'inst_iic_activities',      'label' => 'IIC Activities',                           'title_col' => 'activity_name','approval_required' => true],
        // 34. Website Updation
        'inst_website_updations'   => ['table' => 'inst_website_updations',   'label' => 'Website Updation',                        'title_col' => 'update_title', 'approval_required' => true],
        // 35. Google Rating
        'inst_google_ratings'      => ['table' => 'inst_google_ratings',      'label' => 'Google Rating',                           'title_col' => 'department',   'approval_required' => true],
        // 36. Startup
        'inst_startups'            => ['table' => 'inst_startups',            'label' => 'Startup',                                 'title_col' => 'startup_name', 'approval_required' => true],
        // 37. Alumni Chapter
        'inst_alumni_chapters'     => ['table' => 'inst_alumni_chapters',     'label' => 'Alumni Chapter',                          'title_col' => 'chapter_name', 'approval_required' => true],
        // 38. Awards & Recognition (Faculty/Dept), BoS, DC, QP/Key Setting
        'inst_awards_recognitions' => ['table' => 'inst_awards_recognitions', 'label' => 'Awards & Recognition / BoS / DC / QP Setting', 'title_col' => 'title', 'approval_required' => true],
        // 39. IIT-Bombay Spoken Tutorial
        'inst_spoken_tutorials'    => ['table' => 'inst_spoken_tutorials',    'label' => 'IIT-Bombay Spoken Tutorial Courses',       'title_col' => 'student_name', 'approval_required' => true],
    ];

    try {
        $dbTables = array_map('strtolower', db()->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN));
        $filtered = array_filter($all, fn($item) => in_array(strtolower($item['table']), $dbTables, true));
    } catch (\PDOException $e) {
        $filtered = $all;
    }

    return $filtered;
}

function record_requires_approval(string $type): bool
{
    $types = record_types();
    if (isset($types[$type]['approval_required'])) {
        return (bool) $types[$type]['approval_required'];
    }
    return $type !== 'book';
}

/**
 * The three categories records are grouped under, and which types belong to
 * each. Same grouping the dashboard's "Records by Category" card uses (see
 * all_metrics() in models/Dashboard.php), but keyed by record type so the
 * Reports page can be narrowed to one category from a dashboard link.
 *
 * Types the database doesn't have are dropped, exactly as record_types() does.
 */
function record_categories(): array
{
    $cats = [
        'faculty'  => ['label' => 'Faculty Contributions', 'icon' => 'file-text',
                       'types' => ['journal', 'book', 'conference', 'patent', 'fdp', 'mou', 'nptel', 'online_course']],
        'activity' => ['label' => 'Activities & Outreach', 'icon' => 'calendar',
                       'types' => ['event', 'nss', 'value_added', 'training']],
        // NPTEL sits in both: record_row_category() puts each row on one side.
        'student'  => ['label' => 'Student Records',       'icon' => 'users',
<<<<<<< HEAD
                       'types' => ['internship', 'placement', 'summer_training', 'co_curricular', 'extra_curricular']],
=======
                       'types' => ['internship', 'placement', 'summer_training', 'student_achievement', 'student_participation', 'nptel']],
>>>>>>> 2505422ae6fabcfce2155b4b8b3676678dc6761a
    ];

    $known = record_types();
    foreach ($cats as $key => $cat) {
        $cats[$key]['types'] = array_values(array_filter($cat['types'], fn($t) => isset($known[$t])));
    }

    return $cats;
}

// The two exam sessions the college runs each academic year, stored as-is in exam_session.
function exam_sessions(): array
{
    return ['Nov-Dec', 'Apr-May'];
}

// Nov-Dec falls in the first calendar year of an academic year, Apr-May in the second.
function exam_session_label(string $session, string $academicYear): string
{
    if (!preg_match('/^(\d{4})/', $academicYear, $m)) {
        return $session;
    }
    return $session . ' ' . ((int) $m[1] + ($session === 'Apr-May' ? 1 : 0));
}

// June to December leads up to the Nov-Dec exams; January to May to Apr-May.
function exam_session_current(?int $timestamp = null): string
{
    return (int) date('n', $timestamp ?? time()) >= 6 ? 'Nov-Dec' : 'Apr-May';
}

// The half-year a Book / Book Chapter is recorded in, stored as-is in academic_session.
function academic_sessions(): array
{
    return ['January - June', 'July - December'];
}

// Session of a moment by the calendar in India (IST), whatever the server's zone.
function academic_session_current(?int $timestamp = null): string
{
    $ist = (new DateTimeImmutable('@' . ($timestamp ?? time())))->setTimezone(new DateTimeZone('Asia/Kolkata'));
    return (int) $ist->format('n') <= 6 ? 'January - June' : 'July - December';
}

function record_category_types(?string $category): array
{
    $cats = record_categories();
    return isset($cats[$category]) ? $cats[$category]['types'] : array_keys(record_types());
}

// The category one record belongs to. NPTEL is split by who earned the
// certificate — never by who uploaded it. Null for types in no category.
function record_row_category(array $row): ?string
{
    $type = (string) ($row['_type_key'] ?? '');
    if ($type === 'nptel') {
        return nptel_is_student($row) ? 'student' : 'faculty';
    }
    foreach (record_categories() as $key => $cat) {
        if (in_array($type, $cat['types'], true)) {
            return $key;
        }
    }
    return null;
}

// ---------------------------------------------------------------------------
//  NPTEL. The `category` column holds the participant type: whoever earned the
//  certificate, which is not necessarily whoever uploaded it.
// ---------------------------------------------------------------------------

function nptel_participant_types(): array
{
    return ['Faculty', 'Student'];
}

// Certificate grades as NPTEL prints them.
function nptel_grades(): array
{
    return ['Elite + Gold', 'Elite + Silver', 'Elite', 'Successfully Completed'];
}

// Older rows spell grades loosely ("Elite+Gold"); map them onto the list.
function nptel_grade_normalize(?string $grade): ?string
{
    $key = strtolower(preg_replace('/\s+/', '', (string) $grade));
    foreach (nptel_grades() as $g) {
        if (strtolower(str_replace(' ', '', $g)) === $key) {
            return $g;
        }
    }
    return null;
}

// Staff who can be the faculty participant of a Faculty NPTEL.
function nptel_faculty_roles(): array
{
    return ['Faculty', 'Coordinator', 'HoD'];
}

function nptel_is_student(array $row): bool
{
    return trim((string) ($row['category'] ?? '')) === 'Student';
}

// WHERE condition for Faculty or Student NPTEL rows. Anything not marked
// Student (older free-text categories included) stays on the faculty side,
// which is where every NPTEL row was counted before.
function nptel_participant_sql(string $participant, string $alias = ''): string
{
    $col = ($alias !== '' ? $alias . '.' : '') . '`category`';
    return $participant === 'Student'
        ? "{$col} = 'Student'"
        : "({$col} IS NULL OR {$col} <> 'Student')";
}

// The faculty member a Faculty NPTEL counts for: the chosen participant, or
// the uploader for rows saved before participants were recorded.
function nptel_faculty_owner_sql(string $alias = ''): string
{
    $p = $alias !== '' ? $alias . '.' : '';
    return in_array('participant_user_id', target_record_table_columns('nptel'), true)
        ? "COALESCE({$p}`participant_user_id`, {$p}`created_by`)"
        : "{$p}`created_by`";
}

function nptel_row_label(array $row): string
{
    return nptel_is_student($row) ? 'NPTEL (Student)' : 'NPTEL (Faculty)';
}

// Report-friendly extras on an NPTEL row: the split label and Yes/No topper.
function nptel_decorate_row(array $row): array
{
    $row['_type_label']  = nptel_row_label($row);
    $row['nptel_topper'] = !empty($row['is_topper']) ? 'Yes' : 'No';
    return $row;
}

// Faculty the user may name as the participant: themselves for Faculty, their
// own department for Coordinator / HoD, anyone for oversight roles.
function nptel_participant_faculty(array $user): array
{
    $roles = nptel_faculty_roles();
    $ph    = implode(',', array_fill(0, count($roles), '?'));
    $sql   = "SELECT id, name, role, department FROM users WHERE role IN ($ph) AND status = 1";
    $args  = $roles;

    if (($user['role'] ?? '') === 'Faculty') {
        $sql   .= ' AND id = ?';
        $args[] = (int) $user['id'];
    } elseif (!user_can_choose_department($user) && ($user['role'] ?? '') !== 'Admin') {
        $vars = department_variants($user['department'] ?? '') ?: [(string) ($user['department'] ?? '')];
        $sql .= ' AND department IN (' . implode(',', array_fill(0, count($vars), '?')) . ')';
        $args = array_merge($args, $vars);
    }
    $sql .= ' ORDER BY department, name';

    try {
        $stmt = db()->prepare($sql);
        $stmt->execute($args);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (\PDOException $e) {
        return [];
    }
}

// Departments a Student NPTEL may be filed under. Coordinators and HoDs record
// straight to Approved, so they stay inside their own department; a Faculty
// upload is only Submitted and is verified by the student's department.
function nptel_student_departments(array $user, array $departments): array
{
    $names = array_map(fn($d) => (string) $d['name'], $departments);
    if (in_array($user['role'] ?? '', ['Faculty', 'Admin'], true) || user_can_choose_department($user)) {
        return $names;
    }
    return array_values(array_filter($names, fn($n) => department_names_match($n, $user['department'] ?? '')));
}

/**
 * Validates an NPTEL upload server-side and returns the values to store.
 * The participant decides the classification and the department; the
 * uploader's role and department never do.
 *
 * @return array{0: array, 1: string[]} [clean values, errors]
 */
function nptel_prepare_submission(array $user, array $in, array $departments): array
{
    $errors = [];
    $out    = [];

    $type = trim((string) ($in['category'] ?? ''));
    if (!in_array($type, nptel_participant_types(), true)) {
        return [$out, ['Participant Type must be Faculty or Student.']];
    }
    $out['category'] = $type;

    $grade = trim((string) ($in['grade'] ?? ''));
    if (!in_array($grade, nptel_grades(), true)) {
        $errors[] = 'Grade must be one of: ' . implode(', ', nptel_grades()) . '.';
    }
    $out['grade'] = $grade;

    $topper = trim((string) ($in['is_topper'] ?? ''));
    if (!in_array($topper, ['0', '1'], true)) {
        $errors[] = 'NPTEL Topper must be Yes or No.';
    }
    $out['is_topper'] = $topper === '1' ? 1 : 0;

    if ($type === 'Faculty') {
        $pid     = (int) ($in['participant_user_id'] ?? 0);
        $allowed = array_column(nptel_participant_faculty($user), null, 'id');
        if (!isset($allowed[$pid])) {
            $errors[] = ($user['role'] ?? '') === 'Faculty'
                ? 'A faculty NPTEL uploaded by a faculty member must be their own certificate.'
                : 'Select the faculty member who earned the certificate (from your department).';
            return [$out, $errors];
        }
        $person = $allowed[$pid];
        $out['participant_user_id'] = $pid;
        $out['candidate_name']      = $person['name'];
        $out['department']          = $person['department'];
        $out['reg_no']              = null;
        return [$out, $errors];
    }

    $reg  = strtoupper(trim((string) ($in['reg_no'] ?? '')));
    $name = trim((string) ($in['candidate_name'] ?? ''));
    $dept = trim((string) ($in['department'] ?? ''));

    if (!preg_match('/^[A-Z0-9][A-Z0-9\/\-]{2,29}$/', $reg)) {
        $errors[] = 'Student Register No. is required (letters, digits, / or -).';
    }
    if ($name === '') {
        $errors[] = 'Student Name is required.';
    }

    $canonical = null;
    foreach ($departments as $d) {
        if (department_names_match((string) $d['name'], $dept)) {
            $canonical = (string) $d['name'];
            break;
        }
    }
    if ($canonical === null) {
        $errors[] = "Choose the student's department from the list.";
    } elseif (!in_array($canonical, nptel_student_departments($user, $departments), true)) {
        $errors[] = 'You can only record student NPTEL certificates for your own department (' . ($user['department'] ?? '—') . ').';
    } elseif (department_names_match($canonical, $user['department'] ?? '')) {
        $canonical = (string) $user['department'];   // same spelling as the department's other records
    }

    $out['participant_user_id'] = null;
    $out['reg_no']              = $reg;
    $out['candidate_name']      = $name;
    $out['department']          = $canonical ?? $dept;
    return [$out, $errors];
}

// ---------------------------------------------------------------------------
//  Event Mode. Events organised by a department live in two record types —
//  `event` (seminar / workshop / webinar …) and `training` (career guidance /
//  soft skills …). Both keep the mode in their existing `mode` column; the
//  venue and online meeting details sit beside it.
// ---------------------------------------------------------------------------

function event_mode_types(): array
{
    return ['event', 'training'];
}

function is_event_type(?string $type): bool
{
    return in_array((string) $type, event_mode_types(), true);
}

function event_modes(): array
{
    return ['Online', 'Offline', 'Hybrid'];
}

// Canonical spelling of a stored or submitted mode, or null when it is not one
// of the three. Nothing is guessed: an empty or unknown value stays null.
function event_mode_normalize($mode): ?string
{
    $key = strtolower(trim((string) $mode));
    foreach (event_modes() as $m) {
        if (strtolower($m) === $key) {
            return $m;
        }
    }
    return null;
}

// Which of the location fields a mode uses.
function event_mode_uses_venue(?string $mode): bool
{
    return in_array($mode, ['Offline', 'Hybrid'], true);
}

function event_mode_uses_online(?string $mode): bool
{
    return in_array($mode, ['Online', 'Hybrid'], true);
}

// Location columns, in form order, with their labels.
function event_mode_location_fields(): array
{
    return [
        'venue'           => 'Venue',
        'online_platform' => 'Online Platform',
        'meeting_link'    => 'Meeting Link',
    ];
}

/**
 * Validates the Event Mode block of an event upload server-side.
 *   Online  — platform required, meeting link optional (must be a URL)
 *   Offline — physical venue required
 *   Hybrid  — both of the above
 * Fields the chosen mode does not use come back as null so they are not kept.
 *
 * @return array{0: array, 1: string[]} [clean values, errors]
 */
function event_mode_prepare_submission(array $in): array
{
    $errors = [];
    $mode   = event_mode_normalize($in['mode'] ?? '');
    if ($mode === null) {
        return [[], ['Event Mode is required: choose ' . implode(', ', event_modes()) . '.']];
    }

    $venue    = trim((string) ($in['venue'] ?? ''));
    $platform = trim((string) ($in['online_platform'] ?? ''));
    $link     = trim((string) ($in['meeting_link'] ?? ''));

    if (event_mode_uses_venue($mode) && $venue === '') {
        $errors[] = "Venue is required when Event Mode is {$mode}.";
    }
    if (event_mode_uses_online($mode)) {
        if ($platform === '') {
            $errors[] = "Online Platform is required when Event Mode is {$mode}.";
        }
        if ($link !== '' && !preg_match('/^https?:\/\/.+/i', $link)) {
            $errors[] = 'Meeting Link must be a valid URL starting with http:// or https://.';
        }
    }

    return [[
        'mode'            => $mode,
        'venue'           => event_mode_uses_venue($mode) ? mb_substr($venue, 0, 255) : null,
        'online_platform' => event_mode_uses_online($mode) ? mb_substr($platform, 0, 150) : null,
        'meeting_link'    => event_mode_uses_online($mode) && $link !== '' ? mb_substr($link, 0, 500) : null,
    ], $errors];
}

// "Venue / Online Platform" as one line for reports, following the mode.
function event_mode_location_text(array $row): string
{
    $mode  = event_mode_normalize($row['mode'] ?? '');
    $parts = [];
    if ($mode === null || event_mode_uses_venue($mode)) {
        if (trim((string) ($row['venue'] ?? '')) !== '') {
            $parts[] = trim((string) $row['venue']);
        }
    }
    if ($mode === null || event_mode_uses_online($mode)) {
        $online = trim((string) ($row['online_platform'] ?? ''));
        $link   = trim((string) ($row['meeting_link'] ?? ''));
        if ($online !== '' || $link !== '') {
            $parts[] = 'Online: ' . trim($online . ($link !== '' ? ' (' . $link . ')' : ''));
        }
    }
    return implode('; ', $parts);
}

// Report-friendly extras on an event row: the canonical mode and the location.
function event_mode_decorate_row(array $row): array
{
    $row['event_mode']      = event_mode_normalize($row['mode'] ?? '') ?? 'Not specified';
    $row['_event_location'] = event_mode_location_text($row);
    return $row;
}

// The mode as a badge. Rows saved before modes were required show
// "Mode not set" rather than an assumed value.
function event_mode_badge($mode, string $prefix = ''): string
{
    $m = event_mode_normalize($mode);
    $class = ['Online' => 'badge-info', 'Offline' => 'badge-neutral', 'Hybrid' => 'badge-brand'][$m] ?? 'badge-warning';
    $text  = $m ?? 'Mode not set';
    return '<span class="badge ' . $class . ' event-mode-badge" title="Event Mode">' . e($prefix . $text) . '</span>';
}

// ---------------------------------------------------------------------------
//  Publications with faculty authors: Journal and Book / Book Chapter.
//  One row per publication in its table; its faculty authors are rows in the
//  type's authors table (users.id + a unique author position).
//  faculty_name / author_type / co_authors keep a readable copy for the IQAC
//  reports. A publication may be entered once — see journal_find_duplicate()
//  and book_find_duplicate().
// ---------------------------------------------------------------------------

// Record types whose faculty authors are recorded, and where.
function publication_author_tables(): array
{
    return [
        'journal' => ['table' => 'journal_publications', 'authors' => 'journal_authors', 'fk' => 'journal_id'],
        'book'    => ['table' => 'book_publications',    'authors' => 'book_authors',    'fk' => 'book_id'],
    ];
}

// Co-authors a publication may list besides its Main Author.
function publication_max_coauthors(): int
{
    return 9;
}

function publication_ordinal(int $n): string
{
    $suffix = in_array($n % 100, [11, 12, 13], true) ? 'th' : (['th', 'st', 'nd', 'rd'][$n % 10] ?? 'th');
    return $n . $suffix;
}

function publication_position_label(int $position): string
{
    return publication_ordinal($position) . ' Author';
}

// Years offered for "Month & Year of Publication", newest first.
function publication_years(): array
{
    return range((int) date('Y') + 1, 2000);
}

// The bare DOI ("10.1234/ABC123") from a DOI, a doi: reference or a doi.org
// link; null when there is no valid DOI in it.
function publication_doi_normalize($doi): ?string
{
    $d = trim(rawurldecode((string) $doi));
    $d = (string) preg_replace('~^(?:https?://)?(?:www\.)?(?:dx\.)?doi\.org/~i', '', $d);
    $d = trim((string) preg_replace('~^doi\s*:\s*~i', '', $d));
    return preg_match('~^10\.\d{4,9}/\S+$~', $d) ? $d : null;
}

// DOIs are case-insensitive, so the duplicate key is the lower-case form.
function publication_doi_key($doi): ?string
{
    $d = publication_doi_normalize($doi);
    return $d === null ? null : strtolower($d);
}

// Title with case, spacing and punctuation removed, hashed for the index.
function publication_title_key($title): ?string
{
    $t = (string) preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtoupper((string) $title, 'UTF-8'));
    return $t === '' ? null : sha1($t);
}

function publication_name_key($name): string
{
    return (string) preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtoupper((string) $name, 'UTF-8'));
}

// Publication year of a row, whichever way it was stored (2025 / 12/2025 / 2026-03).
function publication_row_year(array $row): ?string
{
    if (preg_match('/^(19|20)\d{2}$/', trim((string) ($row['publication_year'] ?? '')))) {
        return trim((string) $row['publication_year']);
    }
    return preg_match('/(?:19|20)\d{2}/', (string) ($row['publication_month'] ?? ''), $m) ? $m[0] : null;
}

function publication_authors_ready(string $type): bool
{
    static $ready = [];
    $table = publication_author_tables()[$type]['authors'] ?? null;
    if ($table === null) {
        return false;
    }
    if (!isset($ready[$type])) {
        try {
            $ready[$type] = (bool) db()->query("SHOW TABLES LIKE '{$table}'")->fetchColumn();
        } catch (\PDOException $e) {
            $ready[$type] = false;
        }
    }
    return $ready[$type];
}

// Who may be named Main Author: the uploader for Faculty, their own
// department for Coordinator / HoD, anyone for oversight roles — the same
// rule as the NPTEL faculty participant.
function publication_main_author_options(array $user): array
{
    return nptel_participant_faculty($user);
}

// Co-authors may be any active teaching staff member of the college.
function publication_coauthor_options(): array
{
    $roles = nptel_faculty_roles();
    $ph    = implode(',', array_fill(0, count($roles), '?'));
    try {
        $stmt = db()->prepare("SELECT id, name, role, department FROM users WHERE role IN ($ph) AND status = 1 ORDER BY department, name");
        $stmt->execute($roles);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (\PDOException $e) {
        return [];
    }
}

// A submitted author position, or null unless it is a whole number 1..$total.
function publication_position_input($value, int $total): ?int
{
    $v = trim((string) $value);
    return preg_match('/^\d{1,2}$/', $v) && (int) $v >= 1 && (int) $v <= $total ? (int) $v : null;
}

/**
 * Validates the authors of a Journal or Book upload. Every author is a
 * faculty member from the users table, picked by id; the Main Author's name
 * and department are taken from there, never typed.
 *
 * @param string $noun what is uploaded, for messages ("journal")
 * @return array{0: array, 1: array, 2: string[]} [authors, readable columns, errors]
 *         authors: [['user_id','name','department','position','is_main'], …] Main Author first
 *         columns: faculty_name, department, author_type, co_authors
 */
function publication_prepare_authors(array $user, array $in, string $noun): array
{
    $errors  = [];
    $authors = [];

    $mainOpts = array_column(publication_main_author_options($user), null, 'id');
    $coOpts   = array_column(publication_coauthor_options(), null, 'id');

    $countIn = trim((string) ($in['co_author_count'] ?? ''));
    $count   = 0;
    if (!preg_match('/^\d{1,2}$/', $countIn) || (int) $countIn > publication_max_coauthors()) {
        $errors[] = 'Number of Co-Authors must be between 0 and ' . publication_max_coauthors() . '.';
    } else {
        $count = (int) $countIn;
    }
    $total = $count + 1;
    $range = '1st Author' . ($total > 1 ? ' to ' . publication_position_label($total) : '');

    $mainId = (int) ($in['main_author_id'] ?? 0);
    if (!isset($mainOpts[$mainId])) {
        $errors[] = ($user['role'] ?? '') === 'Faculty'
            ? "The Main Author of a {$noun} you upload must be yourself."
            : 'Select the Main Author from the faculty list.';
    }
    $mainPos = publication_position_input($in['main_author_position'] ?? '', $total);
    if ($mainPos === null) {
        $errors[] = "Main Author Position must be one of {$range}.";
    }
    $authors[] = [
        'user_id'    => $mainId,
        'name'       => $mainOpts[$mainId]['name'] ?? '',
        'department' => $mainOpts[$mainId]['department'] ?? '',
        'position'   => $mainPos,
        'is_main'    => 1,
    ];

    for ($i = 1; $i <= $count; $i++) {
        $cid = (int) ($in["co_author_id_{$i}"] ?? 0);
        if (!isset($coOpts[$cid])) {
            $errors[] = "Co-Author Name {$i}: select a faculty member from the list.";
        }
        $cpos = publication_position_input($in["co_author_pos_{$i}"] ?? '', $total);
        if ($cpos === null) {
            $errors[] = "Co-Author Position {$i} must be one of {$range}.";
        }
        $authors[] = [
            'user_id'    => $cid,
            'name'       => $coOpts[$cid]['name'] ?? '',
            'department' => $coOpts[$cid]['department'] ?? '',
            'position'   => $cpos,
            'is_main'    => 0,
        ];
    }

    // Each faculty member once, each position once. With N authors and
    // positions 1..N, unique positions also means every position is used.
    $ids = array_filter(array_column($authors, 'user_id'));
    if (count($ids) !== count(array_unique($ids))) {
        $errors[] = 'The same faculty member is listed more than once. The Main Author and every Co-Author must be different faculty members.';
    }
    $positions = array_filter(array_column($authors, 'position'), fn($p) => $p !== null);
    if (count($positions) !== count(array_unique($positions))) {
        $errors[] = "Two authors have the same position. Give every author a different position ({$range}).";
    }
    usort($authors, fn($a, $b) => ($b['is_main'] <=> $a['is_main']) ?: (($a['position'] ?? 99) <=> ($b['position'] ?? 99)));

    // The record belongs to the Main Author's department, in the uploader's
    // spelling when it is the same department.
    $dept = trim((string) $authors[0]['department']);
    if ($dept === '' || department_names_match($dept, $user['department'] ?? '')) {
        $dept = (string) ($user['department'] ?? $dept);
    }
    $coText = [];
    foreach (array_slice($authors, 1) as $a) {
        $coText[] = $a['name'] . ($a['position'] ? ' (' . publication_position_label($a['position']) . ')' : '');
    }
    $out = [
        'faculty_name' => $authors[0]['name'],
        'department'   => $dept,
        'author_type'  => $mainPos ? publication_position_label($mainPos) : '',
        'co_authors'   => $coText ? implode(', ', $coText) : null,
    ];

    return [$authors, $out, $errors];
}

// "MM/YYYY" and the year from the Month / Year of Publication selects.
// @return array{0: string, 1: string, 2: ?string} [publication_month, publication_year, error]
function publication_month_year(array $in): array
{
    $month = trim((string) ($in['pub_month'] ?? ''));
    $year  = trim((string) ($in['pub_year'] ?? ''));
    if (!preg_match('/^\d{1,2}$/', $month) || (int) $month < 1 || (int) $month > 12
        || !preg_match('/^\d{4}$/', $year) || !in_array((int) $year, publication_years(), true)) {
        return ['', '', 'Choose the Month and Year of Publication from the lists.'];
    }
    return [sprintf('%02d/%s', (int) $month, $year), $year, null];
}

// Optional DOI: [doi, doi_key, error].
function publication_doi_input($value): array
{
    $in  = trim((string) $value);
    $doi = $in === '' ? null : publication_doi_normalize($in);
    if ($in !== '' && $doi === null) {
        return [null, null, 'DOI must look like 10.1234/ABC123 (a https://doi.org/… link or doi: prefix is also accepted). Leave it empty if there is no DOI.'];
    }
    return [$doi, $doi === null ? null : strtolower($doi), null];
}

// Duplicate lookup keys of a stored row, from its own columns.
function publication_row_keys(string $type, array $row): array
{
    if ($type === 'book') {
        return [
            'title_key' => publication_title_key($row['title'] ?? '') ?? '',
            'doi_key'   => publication_doi_key($row['doi'] ?? ''),
            'isbn_key'  => book_isbn_normalize($row['isbn'] ?? '')[1],
        ];
    }
    return [
        'title_key' => publication_title_key($row['paper_title'] ?? '') ?? '',
        'doi_key'   => publication_doi_key($row['doi'] ?? ''),
    ];
}

// Fills the lookup keys on rows saved before those columns existed. Only rows
// without a title_key are read, so it is cheap once caught up.
function publication_backfill_keys(string $type): void
{
    static $done = [];
    $table = publication_author_tables()[$type]['table'] ?? null;
    if ($table === null || !empty($done[$type])) {
        return;
    }
    $done[$type] = true;
    if (!in_array('title_key', target_record_table_columns($table), true)) {
        return;
    }
    try {
        $rows = db()->query("SELECT * FROM `{$table}` WHERE title_key IS NULL")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $keys = publication_row_keys($type, $r);
            $set  = implode(', ', array_map(fn($c) => "`{$c}` = ?", array_keys($keys)));
            // updated_at is set to itself so a key fill does not look like an edit.
            db()->prepare("UPDATE `{$table}` SET {$set}, updated_at = updated_at WHERE id = ?")
                ->execute([...array_values($keys), (int) $r['id']]);
        }
    } catch (\PDOException $e) {
        error_log("publication_backfill_keys({$type}): " . $e->getMessage());
    }
}

// ---- Journal -----------------------------------------------------------

function journal_types(): array
{
    return ['UGC Care', 'Scopus', 'SCI', 'Springer', 'H-INDEX', 'CONFERENCE'];
}

// Title-style text (paper title, journal name): trimmed, single-spaced and in
// capitals. Returns [clean value, true when it is only A-Z, 0-9 and spaces].
function journal_caps_text($value): array
{
    $v = mb_strtoupper(trim((string) preg_replace('/\s+/u', ' ', (string) $value)), 'UTF-8');
    return [$v, $v !== '' && preg_match('/^[A-Z0-9 ]+$/', $v) === 1];
}

// "1234-567X" from any text holding an ISSN ("e-ISSN: 1234-567x", "1234567X").
function journal_issn_normalize($issn): ?string
{
    $v = strtoupper((string) preg_replace('/[\s\-]/', '', (string) $issn));
    return preg_match('/(\d{4})(\d{3}[\dX])/', $v, $m) ? $m[1] . '-' . $m[2] : null;
}

/**
 * Validates a Journal upload server-side and returns the values to store.
 *
 * @return array{0: array, 1: array, 2: string[]} [clean column values, authors, errors]
 */
function journal_prepare_submission(array $user, array $in): array
{
    [$authors, $out, $errors] = publication_prepare_authors($user, $in, 'journal');

    foreach (['paper_title' => ['Title of the Paper', 500], 'journal_name' => ['Journal Name', 255]] as $col => [$label, $max]) {
        [$val, $ok] = journal_caps_text($in[$col] ?? '');
        if ($val === '') {
            $errors[] = "{$label} is required.";
        } elseif (!$ok) {
            $errors[] = "{$label} may contain only CAPITAL LETTERS (A-Z), digits and single spaces — no punctuation or special characters.";
        } elseif (mb_strlen($val) > $max) {
            $errors[] = "{$label} must be {$max} characters or fewer.";
        }
        $out[$col] = $val;
    }

    $jt = trim((string) ($in['journal_type'] ?? ''));
    if (!in_array($jt, journal_types(), true)) {
        $errors[] = 'Journal Type must be one of: ' . implode(', ', journal_types()) . '.';
    }
    $out['journal_type'] = $jt;

    $issnIn = strtoupper((string) preg_replace('/\s+/', '', (string) ($in['issn'] ?? '')));
    if (!preg_match('/^(\d{4})-?(\d{3}[\dX])$/', $issnIn, $m)) {
        $errors[] = 'ISSN Number must be 8 characters in the form 1234-5678 (the last character may be X).';
        $out['issn'] = $issnIn;
    } else {
        $out['issn'] = $m[1] . '-' . $m[2];
    }

    $vol = mb_strtoupper(trim((string) preg_replace('/\s+/u', ' ', (string) ($in['volume_issue'] ?? ''))), 'UTF-8');
    if ($vol === '') {
        $errors[] = 'Volume & Issue No is required.';
    } elseif (!preg_match('/^[A-Z0-9 .,:\/()\-]+$/', $vol) || mb_strlen($vol) > 150) {
        $errors[] = 'Volume & Issue No may contain only letters, digits, spaces and . , : / ( ) - (e.g. VOL 10, ISSUE 2).';
    }
    $out['volume_issue'] = $vol;

    [$out['publication_month'], $out['publication_year'], $dateError] = publication_month_year($in);
    if ($dateError !== null) {
        $errors[] = $dateError;
    }

    // DOI is optional; when given it must be a real DOI and becomes the key.
    [$out['doi'], $out['doi_key'], $doiError] = publication_doi_input($in['doi'] ?? '');
    if ($doiError !== null) {
        $errors[] = $doiError;
    }
    $out['title_key'] = publication_title_key($out['paper_title']);

    return [$out, $authors, $errors];
}

// Year and journal of two rows agree wherever both rows record them. Journals
// often carry a print and an e-ISSN, so a matching journal name also counts.
function journal_same_issue(array $a, array $b): bool
{
    $ya = publication_row_year($a);
    $yb = publication_row_year($b);
    if ($ya !== null && $yb !== null && $ya !== $yb) {
        return false;
    }
    $ia = journal_issn_normalize($a['issn'] ?? '');
    $ib = journal_issn_normalize($b['issn'] ?? '');
    $na = publication_name_key($a['journal_name'] ?? '');
    $nb = publication_name_key($b['journal_name'] ?? '');
    $issnKnown = $ia !== null && $ib !== null;
    $nameKnown = $na !== '' && $nb !== '';
    if (!$issnKnown && !$nameKnown) {
        return true;
    }
    return ($issnKnown && $ia === $ib) || ($nameKnown && $na === $nb);
}

/**
 * The existing publication a Journal submission would duplicate, or null.
 *   With a DOI — any row with the same DOI (case and doi.org / doi: prefixes
 *     ignored), or a row with no DOI whose title, year and journal match.
 *     A row with a different DOI is a different paper.
 *   No DOI — any row whose title, year and journal match.
 * Titles match on title_key (capitals, digits only). Rejected rows are not
 * counted; $excludeId is the row being edited.
 */
function journal_find_duplicate(array $clean, ?int $excludeId = null): ?array
{
    publication_backfill_keys('journal');
    if (!in_array('title_key', target_record_table_columns('journal_publications'), true)) {
        return null;
    }

    $where  = ['title_key = ?'];
    $params = [(string) ($clean['title_key'] ?? '')];
    if (!empty($clean['doi_key'])) {
        $where[]  = 'doi_key = ?';
        $params[] = $clean['doi_key'];
    }
    $sql = 'SELECT id, paper_title, journal_name, issn, publication_month, publication_year, doi, doi_key, title_key,
                   faculty_name, department, status, academic_year
              FROM journal_publications
             WHERE status <> \'Rejected\' AND (' . implode(' OR ', $where) . ')';
    if ($excludeId) {
        $sql     .= ' AND id <> ?';
        $params[] = $excludeId;
    }
    $sql .= ' ORDER BY id';

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (!empty($clean['doi_key']) && $row['doi_key'] === $clean['doi_key']) {
            $row['_match'] = 'DOI';
            return $row;
        }
        if ($row['title_key'] !== ($clean['title_key'] ?? null)) {
            continue;
        }
        if (!empty($clean['doi_key']) && !empty($row['doi_key'])) {
            continue;   // different DOIs: different papers
        }
        if (journal_same_issue($clean, $row)) {
            $row['_match'] = 'title, year and journal';
            return $row;
        }
    }
    return null;
}

function journal_duplicate_message(array $dup): string
{
    return 'This journal publication already exists (matched by ' . $dup['_match'] . '): Record #' . (int) $dup['id']
        . ' "' . $dup['paper_title'] . '" — ' . ($dup['faculty_name'] ?: 'unknown author')
        . ', ' . ($dup['department'] ?: 'no department') . ', status ' . $dup['status']
        . '. A publication is entered once for all its authors; ask the Coordinator to add you as a co-author on that record instead.';
}

// ---- Book / Book Chapter -----------------------------------------------

function book_categories(): array
{
    return ['Book', 'Book Chapter'];
}

// Free text (titles, publisher): control characters dropped, spaces trimmed
// and collapsed. Case and punctuation are kept — they are part of the name.
function book_text($value): string
{
    return trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/[\x00-\x1F\x7F]/u', ' ', (string) $value)));
}

// ISBN-13 check digit of its first twelve digits.
function book_isbn13_check(string $first12): int
{
    $sum = 0;
    for ($i = 0; $i < 12; $i++) {
        $sum += (int) $first12[$i] * ($i % 2 ? 3 : 1);
    }
    return (10 - $sum % 10) % 10;
}

/**
 * An ISBN-13, ISBN-10 or (for a book series) ISSN as entered, tidied, with
 * its duplicate key: "ISBN-13: 978 3 16 148410 0" gives
 * ['978-3-16-148410-0', '9783161484100', 'ISBN'].
 * The key is the ISBN as 13 digits, so the ISBN-10 of the same book matches.
 * An ISSN names a whole series, not one book, so it has no key. Kind is null
 * when the value is none of these, or an ISBN's check digit is wrong.
 *
 * @return array{0: string, 1: ?string, 2: ?string} [value, isbn_key, kind]
 */
function book_isbn_normalize($value): array
{
    $v = strtoupper(trim((string) $value));
    $v = (string) preg_replace('/^E?-?(?:ISBN|ISSN)(?:-?1[03])?\s*:?\s*/', '', $v);
    $v = trim((string) preg_replace('/[\s\-]+/', '-', $v), '-');
    $d = str_replace('-', '', $v);

    if (preg_match('/^97[89]\d{10}$/', $d)) {
        return book_isbn13_check($d) === (int) $d[12] ? [$v, $d, 'ISBN'] : [$v, null, null];
    }
    if (preg_match('/^\d{9}[\dX]$/', $d)) {
        $sum = 0;
        for ($i = 0; $i < 10; $i++) {
            $sum += ($d[$i] === 'X' ? 10 : (int) $d[$i]) * (10 - $i);
        }
        if ($sum % 11 !== 0) {
            return [$v, null, null];
        }
        $k = '978' . substr($d, 0, 9);
        return [$v, $k . book_isbn13_check($k), 'ISBN'];
    }
    if (preg_match('/^(\d{4})(\d{3}[\dX])$/', $d, $m)) {
        return [$m[1] . '-' . $m[2], null, 'ISSN'];
    }
    return [$v, null, null];
}

/**
 * Validates a Book / Book Chapter upload server-side and returns the values
 * to store, including the Academic Session of the current IST date.
 *
 * @return array{0: array, 1: array, 2: string[]} [clean column values, authors, errors]
 */
function book_prepare_submission(array $user, array $in): array
{
    [$authors, $out, $errors] = publication_prepare_authors($user, $in, 'book or book chapter');

    $cat = trim((string) ($in['publication_category'] ?? ''));
    if (!in_array($cat, book_categories(), true)) {
        $errors[] = 'Book / Book Chapter: choose Book or Book Chapter.';
    }
    $out['publication_category'] = $cat;

    foreach (['title' => ['Title of the Book / Book Chapter', 500], 'publisher_name' => ['Publisher Name', 255]] as $col => [$label, $max]) {
        $val = book_text($in[$col] ?? '');
        if ($val === '') {
            $errors[] = "{$label} is required.";
        } elseif (mb_strlen($val) > $max) {
            $errors[] = "{$label} must be {$max} characters or fewer.";
        }
        $out[$col] = $val;
    }

    [$isbn, $isbnKey, $kind] = book_isbn_normalize($in['isbn'] ?? '');
    if ($isbn === '') {
        $errors[] = 'ISSN / ISBN Number is required.';
    } elseif ($kind === null) {
        $errors[] = 'ISSN / ISBN Number must be a valid ISBN-13 (978-…), ISBN-10 or ISSN (1234-5678). Check the digits against the book — an ISBN\'s last digit is a check digit.';
    }
    $out['isbn']     = $isbn;
    $out['isbn_key'] = $isbnKey;

    [$out['doi'], $out['doi_key'], $doiError] = publication_doi_input($in['doi'] ?? '');
    if ($doiError !== null) {
        $errors[] = $doiError;
    }

    [$out['publication_month'], $out['publication_year'], $dateError] = publication_month_year($in);
    if ($dateError !== null) {
        $errors[] = $dateError;
    }

    $out['title_key']        = publication_title_key($out['title']);
    $out['academic_session'] = academic_session_current();

    return [$out, $authors, $errors];
}

// Year and publisher of two rows agree wherever both rows record them;
// "Springer Nature" and "Springer Nature Switzerland" are one publisher.
function book_same_edition(array $a, array $b): bool
{
    $ya = publication_row_year($a);
    $yb = publication_row_year($b);
    if ($ya !== null && $yb !== null && $ya !== $yb) {
        return false;
    }
    $pa = publication_name_key($a['publisher_name'] ?? '');
    $pb = publication_name_key($b['publisher_name'] ?? '');
    return $pa === '' || $pb === '' || str_contains($pa, $pb) || str_contains($pb, $pa);
}

/**
 * The existing publication a Book / Book Chapter submission would duplicate,
 * or null. In order of strength:
 *   DOI  — one DOI is one publication.
 *   ISBN — a Book with the same ISBN (ISBN-10 and -13 compared as 13 digits).
 *          Every chapter of a book shares its ISBN, so a Book Chapter also
 *          needs the same chapter title.
 *   Title — same title (case, spacing and punctuation ignored), publisher
 *          and year, when the ISBNs do not already tell the two apart.
 * A Book is never a duplicate of a Book Chapter, nor rows with different
 * DOIs of each other. Rejected rows are not counted; $excludeId is the row
 * being edited.
 */
function book_find_duplicate(array $clean, ?int $excludeId = null): ?array
{
    publication_backfill_keys('book');
    if (!in_array('title_key', target_record_table_columns('book_publications'), true)) {
        return null;
    }

    $where  = [];
    $params = [];
    foreach (['doi_key', 'isbn_key', 'title_key'] as $key) {
        if (!empty($clean[$key])) {
            $where[]  = "{$key} = ?";
            $params[] = $clean[$key];
        }
    }
    if (!$where) {
        return null;
    }
    $sql = 'SELECT id, publication_category, title, publisher_name, isbn, isbn_key, doi, doi_key, title_key,
                   publication_month, publication_year, faculty_name, department, co_authors, status
              FROM book_publications
             WHERE status <> \'Rejected\' AND (' . implode(' OR ', $where) . ')';
    if ($excludeId) {
        $sql     .= ' AND id <> ?';
        $params[] = $excludeId;
    }
    $sql .= ' ORDER BY id';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as $row) {
        if (!empty($clean['doi_key']) && $row['doi_key'] === $clean['doi_key']) {
            $row['_match'] = 'DOI';
            return $row;
        }
    }

    $isChapter = ($clean['publication_category'] ?? '') === 'Book Chapter';
    foreach ($rows as $row) {
        $rowCat = trim((string) $row['publication_category']);
        if ($rowCat !== '' && strcasecmp($rowCat, (string) ($clean['publication_category'] ?? '')) !== 0) {
            continue;   // a chapter is not the book it is in
        }
        if (!empty($clean['doi_key']) && !empty($row['doi_key'])) {
            continue;   // different DOIs: different publications
        }
        $sameTitle = !empty($clean['title_key']) && $row['title_key'] === $clean['title_key'];
        $bothIsbn  = !empty($clean['isbn_key']) && !empty($row['isbn_key']);
        if ($bothIsbn && $row['isbn_key'] === $clean['isbn_key']) {
            if (!$isChapter) {
                $row['_match'] = 'ISBN';
                return $row;
            }
            if ($sameTitle) {
                $row['_match'] = 'ISBN and chapter title';
                return $row;
            }
            continue;   // another chapter of the same book
        }
        if ($sameTitle && !$bothIsbn && book_same_edition($clean, $row)) {
            $row['_match'] = 'title, publisher and year';
            return $row;
        }
    }
    return null;
}

// What the uploader is told instead of a second record being created.
function book_duplicate_message(array $dup): string
{
    $id      = (int) $dup['id'];
    $authors = publication_authors_for('book', [$id])[$id] ?? [];
    $who     = $authors
        ? publication_authors_text($authors)
        : ($dup['faculty_name'] ?: 'unknown author') . ' (' . ($dup['department'] ?: 'no department') . ')'
          . (!empty($dup['co_authors']) ? '; Co-Authors: ' . $dup['co_authors'] : '');
    $ids = [];
    if (!empty($dup['isbn'])) {
        $ids[] = 'ISSN / ISBN ' . $dup['isbn'];
    }
    if (!empty($dup['doi'])) {
        $ids[] = 'DOI ' . $dup['doi'];
    }
    $dept = $dup['department'] ?: "the Main Author's department";

    return 'This Book/Book Chapter already exists in the system (matched by ' . $dup['_match'] . '): Record #' . $id
        . ' — ' . ($dup['publication_category'] ?: 'Book') . ' "' . $dup['title'] . '"'
        . ($ids ? ', ' . implode(', ', $ids) : '')
        . ', published ' . ($dup['publication_month'] ?: (publication_row_year($dup) ?? 'date not recorded'))
        . '. Authors: ' . $who . '. Status: ' . $dup['status'] . '.'
        . ' A publication is recorded once for all its authors, so no new record was created.'
        . " If you are an author, ask the HoD of {$dept} to raise an Edit Request so the Coordinator can add you as a co-author on Record #{$id}.";
}

// ---- Authors storage (Journal and Book) -------------------------------

// One row per author. Called inside the caller's transaction.
function publication_save_authors(string $type, int $publicationId, array $authors): void
{
    $t   = publication_author_tables()[$type];
    $pdo = db();
    $pdo->prepare("DELETE FROM `{$t['authors']}` WHERE `{$t['fk']}` = ?")->execute([$publicationId]);
    $ins = $pdo->prepare("INSERT INTO `{$t['authors']}` (`{$t['fk']}`, user_id, author_position, is_main) VALUES (?, ?, ?, ?)");
    foreach ($authors as $a) {
        $ins->execute([$publicationId, (int) $a['user_id'], (int) $a['position'], (int) $a['is_main']]);
    }
}

// Authors of the given publications in position order, keyed by publication id.
function publication_authors_for(string $type, array $publicationIds): array
{
    $publicationIds = array_values(array_unique(array_filter(array_map('intval', $publicationIds))));
    if (!$publicationIds || !publication_authors_ready($type)) {
        return [];
    }
    $t    = publication_author_tables()[$type];
    $ph   = implode(',', array_fill(0, count($publicationIds), '?'));
    $stmt = db()->prepare(
        "SELECT pa.`{$t['fk']}` AS publication_id, pa.user_id, pa.author_position, pa.is_main, u.name, u.department
           FROM `{$t['authors']}` pa JOIN users u ON u.id = pa.user_id
          WHERE pa.`{$t['fk']}` IN ($ph)
          ORDER BY pa.`{$t['fk']}`, pa.author_position"
    );
    $stmt->execute($publicationIds);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[(int) $r['publication_id']][] = $r;
    }
    return $out;
}

// "1st Author: NAME (DEPT) — Main Author; 2nd Author: …"
function publication_authors_text(array $authors): string
{
    $parts = [];
    foreach ($authors as $a) {
        $parts[] = publication_position_label((int) $a['author_position']) . ': ' . $a['name']
            . ($a['department'] ? ' (' . $a['department'] . ')' : '')
            . (!empty($a['is_main']) ? ' — Main Author' : '');
    }
    return implode('; ', $parts);
}

// Whether a user is one of a publication's recorded authors.
function publication_user_is_author(string $type, int $publicationId, int $userId): bool
{
    foreach (publication_authors_for($type, [$publicationId])[$publicationId] ?? [] as $a) {
        if ((int) $a['user_id'] === $userId) {
            return true;
        }
    }
    return false;
}

// The type's table once per faculty author, the author in _owner_id, for
// per-faculty counts. Rows saved before authors were recorded count for
// their uploader, as they always have.
function publication_owner_source_sql(string $type): string
{
    $t = publication_author_tables()[$type];
    if (!publication_authors_ready($type)) {
        return "(SELECT p.*, p.created_by AS _owner_id FROM `{$t['table']}` p)";
    }
    return "(SELECT p.*, pa.user_id AS _owner_id FROM `{$t['table']}` p JOIN `{$t['authors']}` pa ON pa.`{$t['fk']}` = p.id"
         . " UNION ALL SELECT p.*, p.created_by AS _owner_id FROM `{$t['table']}` p"
         . " WHERE NOT EXISTS (SELECT 1 FROM `{$t['authors']}` pa2 WHERE pa2.`{$t['fk']}` = p.id))";
}

// Edit-form values (main_author_id, co_author_id_1, pub_month …) for a stored
// publication, so the author and date selects are filled in.
function publication_edit_values(string $type, array $record): array
{
    $vals    = [];
    $id      = (int) ($record['id'] ?? 0);
    $authors = publication_authors_for($type, [$id])[$id] ?? [];
    $co      = 0;
    foreach ($authors as $a) {
        if (!empty($a['is_main'])) {
            $vals['main_author_id']       = (string) $a['user_id'];
            $vals['main_author_position'] = (string) $a['author_position'];
        } else {
            $co++;
            $vals["co_author_id_{$co}"]  = (string) $a['user_id'];
            $vals["co_author_pos_{$co}"] = (string) $a['author_position'];
        }
    }
    if ($authors) {
        $vals['co_author_count'] = (string) $co;
    }
    // Stored as 12/2025, 2026-03, or a bare month beside publication_year.
    $pm = trim((string) ($record['publication_month'] ?? ''));
    if (preg_match('/^(\d{1,2})\/(\d{4})$/', $pm, $m)) {
        [$month, $year] = [$m[1], $m[2]];
    } elseif (preg_match('/^(\d{4})-(\d{1,2})$/', $pm, $m)) {
        [$month, $year] = [$m[2], $m[1]];
    } else {
        $month = preg_match('/^\d{1,2}$/', $pm) ? $pm : null;
        $year  = publication_row_year($record);
    }
    if ($month !== null) {
        $vals['pub_month'] = (string) (int) $month;
    }
    if ($year !== null) {
        $vals['pub_year'] = $year;
    }
    return $vals;
}

function journal_process_approval_expiry(?int $specificId = null, bool $forceCheck = false): int
{
    static $checkedThisRequest = false;
    if ($specificId === null && $checkedThisRequest && !$forceCheck) {
        return 0;
    }

    $pdo = db();
    static $hasRequiredColumns = null;
    if ($hasRequiredColumns === null) {
        try {
            $cols = $pdo->query("SHOW COLUMNS FROM `journal_publications`")->fetchAll(PDO::FETCH_COLUMN);
            $hasRequiredColumns = in_array('approved_at', $cols, true) && in_array('approval_history', $cols, true);
        } catch (\Throwable $e) {
            $hasRequiredColumns = false;
        }
    }
    if (!$hasRequiredColumns) {
        $checkedThisRequest = true;
        return 0;
    }

    $sql = "SELECT id, status, approved_by, approved_at, review_remark, approval_history, academic_year, department
            FROM journal_publications
            WHERE status = 'Approved'
              AND approved_at IS NOT NULL
              AND approved_at <= DATE_SUB(NOW(), INTERVAL 7 DAY)";
    $params = [];

    if ($specificId !== null) {
        $sql .= " AND id = ?";
        $params[] = $specificId;
    } else {
        $checkedThisRequest = true;
    }

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $expiredRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($expiredRows)) {
            return 0;
        }

        $now = date('Y-m-d H:i:s');
        $expiredCount = 0;

        static $userCache = [];
        $getUserInfo = function(?int $uid) use ($pdo, &$userCache): array {
            if (!$uid) return ['name' => null, 'role' => null];
            if (!isset($userCache[$uid])) {
                $uStmt = $pdo->prepare("SELECT name, role FROM users WHERE id = ?");
                $uStmt->execute([$uid]);
                $userCache[$uid] = $uStmt->fetch(PDO::FETCH_ASSOC) ?: ['name' => null, 'role' => null];
            }
            return $userCache[$uid];
        };

        $pdo->beginTransaction();

        $updateStmt = $pdo->prepare(
            "UPDATE journal_publications
             SET status = 'Submitted',
                 approved_by = NULL,
                 approved_at = NULL,
                 review_remark = NULL,
                 approval_history = ?,
                 updated_at = NOW()
             WHERE id = ? AND status = 'Approved'"
        );

        foreach ($expiredRows as $row) {
            $recId = (int)$row['id'];
            $history = [];
            if (!empty($row['approval_history'])) {
                $decoded = json_decode((string)$row['approval_history'], true);
                if (is_array($decoded)) {
                    $history = $decoded;
                }
            }

            $revInfo = $getUserInfo(!empty($row['approved_by']) ? (int)$row['approved_by'] : null);

            $archiveEntry = [
                'status'         => 'Approved',
                'approved_by'    => !empty($row['approved_by']) ? (int)$row['approved_by'] : null,
                'approved_at'    => $row['approved_at'],
                'reviewer_name'  => $revInfo['name'] ?? null,
                'reviewer_role'  => $revInfo['role'] ?? null,
                'review_remark'  => $row['review_remark'] ?? null,
                'expired_at'     => $now,
            ];

            $history[] = $archiveEntry;
            $encodedHistory = json_encode($history, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $updateStmt->execute([$encodedHistory, $recId]);
            if ($updateStmt->rowCount() > 0) {
                $expiredCount++;
            }
        }

        $pdo->commit();

        if ($expiredCount > 0) {
            try {
                require_once __DIR__ . '/Target.php';
                if (function_exists('sync_target_achieved_for_type')) {
                    sync_target_achieved_for_type('journal');
                }
            } catch (\Throwable $e) {
                error_log('sync_target_achieved_for_type error during journal expiry: ' . $e->getMessage());
            }
        }

        return $expiredCount;
    } catch (\PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('journal_process_approval_expiry PDOException: ' . $e->getMessage());
        return 0;
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('journal_process_approval_expiry error: ' . $e->getMessage());
        return 0;
    }
}

function record_approval_history(string $type, int $id): array
{
    $types = record_types();
    if (!isset($types[$type])) {
        return [];
    }
    $table = $types[$type]['table'];
    try {
        $stmt = db()->prepare("SELECT approval_history FROM `{$table}` WHERE id = ?");
        $stmt->execute([$id]);
        $raw = $stmt->fetchColumn();
        if (!$raw) {
            return [];
        }
        $decoded = json_decode((string)$raw, true);
        return is_array($decoded) ? $decoded : [];
    } catch (\Throwable $e) {
        return [];
    }
}

/**
 * Fetch records for a given type, with optional filters.
 *
 * $year scopes to one academic year — the active one, from every caller —
 * but only for tables that actually carry an academic_year column; a table
 * that doesn't (e.g. fdp, mou, nptel) is returned unfiltered by year, the
 * same convention all_metrics() already uses on the dashboard.
 */
function records_list(string $type, ?string $department = null, ?string $status = null, ?int $createdBy = null, ?string $from = null, ?string $to = null, ?string $year = null, ?string $eventMode = null, bool $withAuthorDepartments = false): array
{
    $types = record_types();
    if (!isset($types[$type])) {
        return [];
    }

    $t = $types[$type];

    $hasDept = true;

    $sql = "SELECT * FROM `{$t['table']}` WHERE 1=1";
    $params = [];

    if (isset($t['category_val'])) {
        $sql .= " AND (category = ? OR (category IS NULL AND ? = 'Co-Curricular'))";
        $params[] = $t['category_val'];
        $params[] = $t['category_val'];
    }

    if ($department && $hasDept) {
        $deptVars = department_variants($department);
        if (!empty($deptVars)) {
            $inPh     = implode(',', array_fill(0, count($deptVars), '?'));
            $deptSql  = "department IN ($inPh)";
            $authSql  = "u.department IN ($inPh)";
            $deptArgs = array_values($deptVars);
        } else {
            $deptSql  = '(department = ? OR REPLACE(department, " ", "") = REPLACE(?, " ", ""))';
            $authSql  = '(u.department = ? OR REPLACE(u.department, " ", "") = REPLACE(?, " ", ""))';
            $deptArgs = [$department, $department];
        }
        // Record lists also show a book to every department one of its
        // authors belongs to. EXISTS keeps it one row however many authors
        // match; counts leave this off, so each book still counts once.
        if ($withAuthorDepartments && $type === 'book' && publication_authors_ready($type)) {
            $pa   = publication_author_tables()[$type];
            $sql .= " AND ($deptSql OR EXISTS (SELECT 1 FROM `{$pa['authors']}` pa JOIN users u ON u.id = pa.user_id"
                  . " WHERE pa.`{$pa['fk']}` = `{$t['table']}`.id AND $authSql))";
            $params = array_merge($params, $deptArgs, $deptArgs);
        } else {
            $sql .= " AND $deptSql";
            $params = array_merge($params, $deptArgs);
        }
    }
    if ($year !== null && in_array('academic_year', target_record_table_columns($t['table']), true)) {
        $ayVars = academic_year_variants($year);
        $ayPlaceholders = implode(',', array_fill(0, count($ayVars), '?'));
        $sql .= " AND academic_year IN ($ayPlaceholders)";
        foreach ($ayVars as $v) {
            $params[] = $v;
        }
    }
    if ($status) {
        if ($status === 'Submitted' || $status === 'Pending') {
            $sql .= " AND status IN ('HOD Pending', 'Dean Pending', 'Submitted')";
        } else {
            $sql .= ' AND status = ?';
            $params[] = $status;
        }
    }
    if ($createdBy !== null) {
        // A journal or book is also "mine" when I am one of its recorded authors.
        if (publication_authors_ready($type)) {
            $pa   = publication_author_tables()[$type];
            $sql .= " AND (created_by = ? OR id IN (SELECT `{$pa['fk']}` FROM `{$pa['authors']}` WHERE user_id = ?))";
            $params[] = $createdBy;
            $params[] = $createdBy;
        } else {
            $sql .= ' AND created_by = ?';
            $params[] = $createdBy;
        }
    }
    // Event Mode applies to the event types only; any other type has no mode.
    $eventMode = event_mode_normalize($eventMode);
    if ($eventMode !== null) {
        if (!is_event_type($type)) {
            return [];
        }
        $sql .= ' AND mode = ?';
        $params[] = $eventMode;
    }

    if ($from) {
        $sql .= ' AND created_at >= ?';
        $params[] = $from . ' 00:00:00';
    }
    if ($to) {
        $sql .= ' AND created_at <= ?';
        $params[] = $to . ' 23:59:59';
    }

    $sql .= ' ORDER BY created_at DESC';
    try {
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    } catch (\PDOException $e) {
        return [];
    }
}

// $withAuthorDepartments: for record lists (not counts) — a book shows in
// every department one of its authors belongs to; see records_list().
function report_records(array $user, ?string $department, ?string $status, ?string $type, ?string $from = null, ?string $to = null, ?string $year = null, bool $departmentWide = false, ?string $eventMode = null, bool $withAuthorDepartments = false): array
{
    $types = record_types();

    if ($user['role'] === 'Director' || $user['role'] === 'Principal') {
        $scopeDept = null;
    } elseif ($user['role'] === 'Admin' || $user['role'] === 'Dean') {
        $scopeDept = $department;
    } else {
        $scopeDept = $user['department'] ?: '__UNASSIGNED_DEPT__';
    }

    $onlyMine = ($user['role'] === 'Faculty' && !$departmentWide) ? (int) $user['id'] : null;

    $wanted = ($type && isset($types[$type])) ? [$type => $types[$type]] : $types;
    $eventMode = event_mode_normalize($eventMode);
    if ($eventMode !== null) {
        $wanted = array_intersect_key($wanted, array_flip(event_mode_types()));
    }

    $all = [];

    foreach ($wanted as $key => $t) {
        foreach (records_list($key, $scopeDept, $status, $onlyMine, $from, $to, $year, $eventMode, $withAuthorDepartments) as $row) {
            $row['_type_key']   = $key;
            $row['_type_label'] = $t['label'];
            $row['_title']      = $row[$t['title_col']] ?? '(untitled)';
            $row['_person']     = $row['faculty_name']
                ?? $row['student_name']
                ?? $row['candidate_name']
                ?? '';
            $all[] = $key === 'nptel' ? nptel_decorate_row($row) : $row;
        }
    }

    usort($all, fn($a, $b) => strtotime($b['created_at']) <=> strtotime($a['created_at']));

    return $all;
}

function record_counts_for_users(array $userIds): array
{
    $userIds = array_values(array_unique(array_map('intval', $userIds)));

    if (empty($userIds)) {
        return [];
    }

    // Start every person at zero so the page never has to check for gaps.
    $blank   = ['total' => 0, 'Approved' => 0, 'Dean Pending' => 0, 'HOD Pending' => 0, 'Submitted' => 0, 'Draft' => 0, 'Rejected' => 0];
    $summary = array_fill_keys($userIds, $blank);

    $placeholders = implode(',', array_fill(0, count($userIds), '?'));

    foreach (record_types() as $t) {
        try {
            $stmt = db()->prepare(
                "SELECT created_by, status, COUNT(*) AS n
                 FROM `{$t['table']}`
                 WHERE created_by IN ($placeholders)
                 GROUP BY created_by, status"
            );
            $stmt->execute($userIds);

            foreach ($stmt as $row) {
                $id     = (int) $row['created_by'];
                $status = $row['status'] ?: 'Draft';
                $n      = (int) $row['n'];

                $summary[$id]['total'] += $n;

                if (isset($summary[$id][$status])) {
                    $summary[$id][$status] += $n;
                }
            }
        } catch (\PDOException $e) {
            continue;
        }
    }

    return $summary;
}

function user_record_counts(int $userId): array
{
    $counts = record_counts_for_users([$userId]);

    return $counts[$userId] ?? ['total' => 0, 'Approved' => 0, 'Submitted' => 0, 'Draft' => 0, 'Rejected' => 0];
}

function pending_records(?string $department = null, ?string $stage = null, ?string $role = null, ?string $year = null): array
{
    $types = record_types();
    $all = [];

    if ($role === 'Coordinator' || $stage === 'Submitted') {
        $targetStatuses = ['Submitted', 'Unlocked for Edit', 'Resubmitted'];
    } elseif ($role === 'HoD' || $stage === 'HOD Pending') {
        $targetStatuses = ['Approved', 'Submitted', 'Edit Requested', 'HOD Pending', 'Resubmitted', 'Unlocked for Edit'];
    } elseif ($role === 'Dean' || $stage === 'Dean Pending') {
        $targetStatuses = ['Edit Requested', 'Dean Pending'];
    } else {
        $targetStatuses = ['Submitted', 'Edit Requested', 'Dean Pending', 'HOD Pending', 'Unlocked for Edit', 'Resubmitted'];
    }

    $inClause = implode(',', array_fill(0, count($targetStatuses), '?'));

    foreach ($types as $key => $t) {
        $sql = "SELECT *, '{$key}' AS record_type FROM `{$t['table']}` WHERE status IN ($inClause)";
        $params = $targetStatuses;

        if ($department) {
            $deptVars = department_variants($department);
            if (!empty($deptVars)) {
                $inPh = implode(',', array_fill(0, count($deptVars), '?'));
                $sql .= " AND (department IN ($inPh) OR REPLACE(department, ' ', '') = REPLACE(?, ' ', ''))";
                foreach ($deptVars as $v) {
                    $params[] = $v;
                }
                $params[] = $department;
            } else {
                $sql .= ' AND (department = ? OR REPLACE(department, " ", "") = REPLACE(?, " ", ""))';
                $params[] = $department;
                $params[] = $department;
            }
        }
        if ($year !== null && in_array('academic_year', target_record_table_columns($t['table']), true)) {
            $ayVars = academic_year_variants($year);
            $ayPlaceholders = implode(',', array_fill(0, count($ayVars), '?'));
            $sql .= " AND academic_year IN ($ayPlaceholders)";
            foreach ($ayVars as $v) {
                $params[] = $v;
            }
        }

        $sql .= ' ORDER BY created_at DESC';
        try {
            $stmt = db()->prepare($sql);
            $stmt->execute($params);

            foreach ($stmt as $row) {
                $row['_type_key']   = $key;
                $row['_type_label'] = $t['label'];
                $row['_title']      = $row[$t['title_col']] ?? '(untitled)';
                $all[] = $key === 'nptel' ? nptel_decorate_row($row) : $row;
            }
        } catch (\PDOException $e) {
            continue;
        }
    }

    usort($all, fn($a, $b) => strtotime($b['created_at']) <=> strtotime($a['created_at']));
    return $all;
}

function record_review(string $type, int $id, string $action, ?string $remark, int $approvedBy, ?string $scopeDept = null, string $userRole = 'Admin', ?string $year = null): array
{
    $types = record_types();
    if (!isset($types[$type])) {
        return [false, 'Invalid record type.'];
    }

    if (!in_array($action, ['approve', 'reject', 'request_edit', 'approve_edit'], true)) {
        return [false, 'Invalid review action.'];
    }

    // BUG-WF-11: Strict RBAC for record reviews
    if (!in_array($userRole, ['Coordinator', 'Admin', 'Dean', 'HoD'], true) || $userRole === 'Faculty') {
        return [false, 'Access Denied: Your role is not authorized to approve or review records.'];
    }

    // HoD can NEVER approve or reject records directly
    if ($userRole === 'HoD' && in_array($action, ['approve', 'reject', 'approve_edit'], true)) {
        return [false, 'HOD is a reviewer only and cannot approve or reject submitted records. To request changes, use Request Edit to Dean.'];
    }

    $effectiveYear = $year ?: active_academic_year();
    if ($userRole !== 'Admin' && academic_year_is_locked($effectiveYear)) {
        return [false, "Academic year {$effectiveYear} cycle is locked by Administrator. Record reviews are frozen for all roles."];
    }

    $table = $types[$type]['table'];

    // FEAT-07: a record submitted during EM1 is read-only once EM1 has closed.
    if (function_exists('em_locked_windows') && em_locked_windows($effectiveYear)) {
        $lookup = db()->prepare("SELECT created_at FROM `$table` WHERE id = ?");
        $lookup->execute([$id]);
        if (em_record_is_locked($userRole, $lookup->fetchColumn() ?: null, $effectiveYear)) {
            return [false, EM1_LOCKED_MESSAGE];
        }
    }

    if ($userRole === 'Coordinator') {
        $validCurrent = ['Submitted', 'Unlocked for Edit', 'Resubmitted'];
        $newStatus    = ($action === 'reject') ? 'Rejected' : 'Approved';
    } elseif ($userRole === 'HoD') {
        if ($action !== 'request_edit') {
            return [false, 'HOD can only submit Edit Requests to Dean/Admin.'];
        }
        $validCurrent = ['Approved', 'Submitted', 'HOD Pending', 'Dean Pending', 'Resubmitted'];
        $newStatus    = 'Edit Requested';
    } elseif ($userRole === 'Dean') {
        $validCurrent = ['Edit Requested', 'Dean Pending'];
        $newStatus    = ($action === 'reject') ? 'Approved' : 'Unlocked for Edit';
    } else { 
        $validCurrent = ['Submitted', 'Edit Requested', 'Dean Pending', 'HOD Pending', 'Approved', 'Unlocked for Edit', 'Resubmitted'];
        if ($action === 'request_edit') {
            $newStatus = 'Edit Requested';
        } elseif ($action === 'approve_edit') {
            $newStatus = 'Unlocked for Edit';
        } elseif ($action === 'reject') {
            $newStatus = 'Rejected';
        } else {
            $newStatus = 'Approved';
        }
    }

    $recBefore = record_find($type, $id);
    $oldStatus = $recBefore['status'] ?? 'Unknown';
    if ($type === 'journal') {
        journal_process_approval_expiry($id, true);
    }

    $tableCols     = target_record_table_columns($table);
    $hasApprovedAt = in_array('approved_at', $tableCols, true);
    $approvedAtSql = '';
    if ($hasApprovedAt) {
        $approvedAtSql = ($newStatus === 'Approved') ? ', approved_at = NOW()' : ', approved_at = NULL';
    }

    $inClause = implode(',', array_fill(0, count($validCurrent), '?'));
    $sql      = "UPDATE `$table` SET status = ?, review_remark = ?, approved_by = ?{$approvedAtSql}, updated_at = NOW() WHERE id = ? AND status IN ($inClause)";
    $params   = array_merge([$newStatus, $remark ?: null, $approvedBy, $id], $validCurrent);

    if ($scopeDept !== null) {
        $dVars = department_variants($scopeDept);
        if (empty($dVars)) {
            $dVars = [$scopeDept];
        }
        $dPlaceholders = implode(',', array_fill(0, count($dVars), '?'));
        $sql     .= " AND (department IN ($dPlaceholders) OR REPLACE(department, ' ', '') = REPLACE(?, ' ', ''))";
        foreach ($dVars as $dv) {
            $params[] = $dv;
        }
        $params[] = $scopeDept;
    }
    if ($year !== null && in_array('academic_year', target_record_table_columns($table), true)) {
        $ayVars = academic_year_variants($year);
        $ayPlaceholders = implode(',', array_fill(0, count($ayVars), '?'));
        $sql .= " AND academic_year IN ($ayPlaceholders)";
        foreach ($ayVars as $v) {
            $params[] = $v;
        }
    }
    // FEAT-07: never touch a row inside a locked EM window (Admin exempt).
    $sql .= em_locked_exclusion_sql($userRole, $effectiveYear, $params);

    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    if ($stmt->rowCount() === 0) {
        return [false, 'Record not found, already reviewed, outside your department scope, or not in the active academic year.'];
    }

    record_workflow_audit(
        $type,
        $id,
        $userRole === 'Coordinator' ? 'COORDINATOR_APPROVED' : ($newStatus === 'Edit Requested' ? 'HOD_EDIT_REQUESTED' : 'RECORD_REVIEWED'),
        ['id' => $approvedBy, 'name' => $userRole, 'role' => $userRole, 'department' => $scopeDept],
        $oldStatus,
        $newStatus,
        $remark,
        ['action' => $action],
        $scopeDept,
        $effectiveYear
    );

    if ($newStatus === 'Approved') {
        require_once __DIR__ . '/Target.php';
        sync_target_achieved_for_type($type);
    }

    $msgStatus = match ($newStatus) {
        'Edit Requested'    => 'submitted as Edit Request to Dean',
        'Unlocked for Edit' => 'unlocked by Dean for Coordinator to edit',
        'Approved'          => 'approved and saved to database',
        default             => strtolower($newStatus),
    };
    return [true, "Record {$msgStatus}."];
}

function records_bulk_approve(string $department, int $approvedBy, ?string $scopeDept = null, string $userRole = 'HoD', ?string $year = null): array
{
    // HoD cannot approve records
    if ($userRole === 'HoD') {
        return [false, 'Bulk approvals are not permitted for HoD. HoD is a reviewer only.'];
    }

    $department = trim($department);
    if ($department === '') {
        return [false, 'No department given.'];
    }
    if ($scopeDept !== null && !department_names_match($scopeDept, $department)) {
        return [false, 'You can only approve your own department.'];
    }

    $effectiveYear = $year ?: active_academic_year();
    if ($userRole !== 'Admin' && academic_year_is_locked($effectiveYear)) {
        return [false, "Academic year {$effectiveYear} cycle is locked by Administrator. Approvals are frozen for all roles."];
    }

    if ($userRole === 'Coordinator') {
        $validCurrent = ['Submitted', 'Resubmitted'];
        $newStatus    = 'Approved';
    } else {
        $validCurrent = ['Edit Requested', 'Dean Pending', 'HOD Pending', 'Submitted', 'Resubmitted'];
        $newStatus    = 'Approved';
    }

    $inClause = implode(',', array_fill(0, count($validCurrent), '?'));
    $total = 0;

    $lockedWindows = ($userRole !== 'Admin') ? em_locked_windows($effectiveYear) : [];
    $heldBack      = 0;

    $dVars = department_variants($department);
    if (empty($dVars)) {
        $dVars = [$department];
    }
    $dPlaceholders = implode(',', array_fill(0, count($dVars), '?'));

    foreach (record_types() as $key => $t) {
        if (!record_requires_approval($key)) {
            continue;
        }
        try {
            $tCols = target_record_table_columns($t['table']);
            $hasApprovedAt = in_array('approved_at', $tCols, true);
            $approvedAtSql = ($hasApprovedAt && $newStatus === 'Approved') ? ', approved_at = NOW()' : '';

            $sql    = "UPDATE `{$t['table']}` SET status = ?, approved_by = ?{$approvedAtSql}, updated_at = NOW()
                        WHERE status IN ($inClause) AND (department IN ($dPlaceholders) OR REPLACE(department, ' ', '') = REPLACE(?, ' ', ''))";
            $params = array_merge([$newStatus, $approvedBy], $validCurrent, $dVars, [$department]);
            if ($year !== null && in_array('academic_year', target_record_table_columns($t['table']), true)) {
                $sql      .= ' AND academic_year = ?';
                $params[]  = $year;
            }

            if ($lockedWindows) {
                $countSql    = "SELECT COUNT(*) FROM `{$t['table']}` WHERE status IN ($inClause) AND (department IN ($dPlaceholders) OR REPLACE(department, ' ', '') = REPLACE(?, ' ', ''))";
                $countParams = array_merge($validCurrent, $dVars, [$department]);
                if ($year !== null && in_array('academic_year', target_record_table_columns($t['table']), true)) {
                    $countSql     .= ' AND academic_year = ?';
                    $countParams[] = $year;
                }
                $inWindow = [];
                foreach ($lockedWindows as $w) {
                    $inWindow[]    = '(created_at >= ? AND created_at <= ?)';
                    $countParams[] = $w['from'];
                    $countParams[] = $w['to'];
                }
                $countStmt = db()->prepare($countSql . ' AND (' . implode(' OR ', $inWindow) . ')');
                $countStmt->execute($countParams);
                $heldBack += (int) $countStmt->fetchColumn();
            }

            $sql .= em_locked_exclusion_sql($userRole, $effectiveYear, $params);

            $stmt = db()->prepare($sql);
            $stmt->execute($params);
            $total += $stmt->rowCount();
        } catch (\PDOException $e) {
            continue;
        }
    }

    if ($total === 0) {
        return [false, !empty($heldBack)
            ? EM1_LOCKED_MESSAGE . " {$heldBack} pending EM1 record" . ($heldBack === 1 ? ' was' : 's were') . ' left unchanged.'
            : 'Nothing pending to approve in ' . $department . '.'];
    }

    if ($newStatus === 'Approved') {
        require_once __DIR__ . '/Target.php';
        sync_all_target_achieved();
    }

    return [true, "Approved and saved {$total} record" . ($total === 1 ? '' : 's') . " in {$department} directly to database."
        . ($heldBack ? " {$heldBack} pending EM1 record" . ($heldBack === 1 ? ' was' : 's were') . ' left unchanged because EM1 is locked.' : '')];
}

function record_workflow_audit(string $type, int $id, string $action, array $user, ?string $oldStatus = null, ?string $newStatus = null, ?string $reason = null, ?array $details = null, ?string $dept = null, ?string $year = null): void
{
    try {
        $stmt = db()->prepare(
            "INSERT INTO workflow_audit_logs (record_id, record_type, action, user_id, user_name, user_role, department, academic_year, old_status, new_status, reason, details, ip_address, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())"
        );
        $stmt->execute([
            $id,
            $type,
            $action,
            (int) ($user['id'] ?? 0),
            $user['name'] ?? null,
            $user['role'] ?? 'Unknown',
            $dept ?? ($user['department'] ?? null),
            $year ?? active_academic_year(),
            $oldStatus !== null ? substr($oldStatus, 0, 50) : null,
            $newStatus !== null ? substr($newStatus, 0, 50) : null,
            $reason,
            $details ? json_encode($details) : null,
            $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (\PDOException $e) {
        error_log('Failed to write workflow audit log: ' . $e->getMessage());
    }
}

function record_find(string $type, int $id): ?array
{
    $types = record_types();
    if (!isset($types[$type])) {
        return null;
    }
    $table = $types[$type]['table'];
    try {
        $stmt = db()->prepare("SELECT *, '{$type}' AS _type_key FROM `{$table}` WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        $row['_type_label'] = $types[$type]['label'];
        $row['_title'] = $row[$types[$type]['title_col']] ?? '(untitled)';
        return $type === 'nptel' ? nptel_decorate_row($row) : $row;
    } catch (\PDOException $e) {
        return null;
    }
}

function can_edit_record(string $type, int $id, array $user): array
{
    $rec = record_find($type, $id);
    if (!$rec) {
        return [false, 'Record not found.', null];
    }
    if ($user['role'] === 'Admin') {
        return [true, 'Admin edit permitted.', $rec];
    }

    if (function_exists('em_record_is_locked') && em_record_is_locked($user['role'], $rec['created_at'] ?? null, $rec['academic_year'] ?? null)) {
        return [false, 'Executive Meeting 1 has ended and is no longer editable.', $rec];
    }
    if ($user['role'] === 'Coordinator') {
        if (!empty($user['department']) && !department_names_match($rec['department'] ?? '', $user['department'])) {
            return [false, 'Access Denied: You cannot edit records belonging to another department.', $rec];
        }
        if (($rec['status'] ?? '') !== 'Unlocked for Edit') {
            return [false, 'This record is not currently unlocked for editing. An approved Edit Request from Dean is required.', $rec];
        }
        return [true, 'Coordinator authorized correction permitted.', $rec];
    }
    return [false, 'Direct record editing is not permitted for your role. Contact your Coordinator or Dean.', $rec];
}

function edit_request_create(array $data, ?array $user = null): array
{
    if ($user === null) {
        if (!empty($data['requested_by'])) {
            $uStmt = db()->prepare("SELECT id, name, email, role, department FROM users WHERE id = ?");
            $uStmt->execute([(int)$data['requested_by']]);
            $user = $uStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        if (!$user) {
            $user = current_user() ?? [
                'id'         => (int)($data['requested_by'] ?? 0),
                'role'       => $data['requested_by_role'] ?? 'HoD',
                'name'       => $data['requested_by_name'] ?? 'HoD',
                'department' => $data['department'] ?? '',
            ];
        }
    }

    if (!in_array($user['role'], ['HoD', 'Admin'], true)) {
        return [false, 'Only HoD or Admin can submit an Edit Request to Dean.'];
    }

    $type   = trim((string)($data['record_type'] ?? ''));
    $id     = (int)($data['record_id'] ?? 0);
    $reason = trim((string)($data['reason'] ?? ''));
    if ($reason === '' && !empty($data['correction'])) {
        $reason = trim((string)$data['correction']);
    }

    if ($reason === '') {
        return [false, 'A clear reason / explanation is required for the Edit Request.'];
    }

    $rec = record_find($type, $id);
    if (!$rec) {
        return [false, 'The specified record does not exist.'];
    }

    $hodDept = $user['department'] ?? '';
    if ($user['role'] === 'HoD' && !empty($hodDept)) {
        if (!department_names_match($rec['department'] ?? '', $hodDept)) {
            return [false, 'Access Denied: You can only request edits for records in your own department (' . $hodDept . ').'];
        }
    }

    if (($rec['status'] ?? '') === 'Edit Requested') {
        return [false, 'This record is already pending Dean review.'];
    }
    if (($rec['status'] ?? '') === 'Unlocked for Edit') {
        return [false, 'This record is already unlocked for Coordinator correction.'];
    }

    $existingPending = db()->prepare("SELECT id FROM edit_requests WHERE record_id = ? AND record_type = ? AND status = 'Pending' LIMIT 1");
    $existingPending->execute([$id, $type]);
    if ($existingPending->fetch()) {
        return [false, 'An Edit Request for this record is already pending Dean decision.'];
    }

    $academicYear = $rec['academic_year'] ?? active_academic_year();
    if ($user['role'] !== 'Admin' && academic_year_is_locked($academicYear)) {
        return [false, "Academic year {$academicYear} is locked. Edit requests are frozen."];
    }

    $types = record_types();
    if (!isset($types[$type])) {
        return [false, 'Invalid record type.'];
    }
    $table = $types[$type]['table'];

    if (function_exists('em_locked_windows') && em_locked_windows($academicYear)) {
        if (em_record_is_locked($user['role'], $rec['created_at'] ?? null, $academicYear)) {
            return [false, EM1_LOCKED_MESSAGE];
        }
    }

    $facultyName = $rec['faculty_name'] ?? $rec['candidate_name'] ?? $rec['student_name'] ?? 'Faculty Member';
    $facultyId   = !empty($rec['created_by']) ? (int)$rec['created_by'] : null;
    $dept        = $rec['department'] ?? $hodDept;
    $title       = $rec['_title'] ?? '';

    $specificField = !empty($data['specific_field']) ? trim((string)$data['specific_field']) : null;
    $currentVal    = !empty($data['current_value']) ? trim((string)$data['current_value']) : null;
    $requestedVal  = !empty($data['requested_value']) ? trim((string)$data['requested_value']) : null;
    $correctionVal = !empty($data['correction']) ? trim((string)$data['correction']) : null;
    $hodCommentsVal = !empty($data['hod_comments']) ? trim((string)$data['hod_comments']) : null;

    try {
        db()->beginTransaction();

        $stmt = db()->prepare(
            "INSERT INTO edit_requests (
                record_id, record_type, record_title, proof_file, academic_year, department, faculty_id, faculty_name,
                requested_by, requested_by_name, requested_by_role, reason, correction, hod_comments, specific_field, current_value,
                requested_value, status, created_at
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending', NOW())"
        );
        $stmt->execute([
            $id,
            $type,
            $title,
            $rec['proof_file'] ?? null,
            $academicYear,
            $dept,
            $facultyId,
            $facultyName,
            (int)$user['id'],
            $user['name'] ?? 'HoD',
            $user['role'],
            $reason,
            $correctionVal,
            $hodCommentsVal,
            $specificField,
            $currentVal,
            $requestedVal,
        ]);
        $requestId = (int)db()->lastInsertId();

        $oldStatus = $rec['status'] ?? 'Approved';
        $upd = db()->prepare("UPDATE `{$table}` SET status = 'Edit Requested', review_remark = ?, updated_at = NOW() WHERE id = ?");
        $upd->execute(["Edit Request ER-{$requestId}: " . $reason, $id]);

        record_workflow_audit(
            $type,
            $id,
            'HOD_EDIT_REQUESTED',
            $user,
            $oldStatus,
            'Edit Requested',
            $reason,
            ['request_id' => $requestId, 'specific_field' => $specificField, 'requested_value' => $requestedVal],
            $dept,
            $academicYear
        );

        db()->commit();
        return [true, "Edit Request ER-{$requestId} submitted to Dean for review.", $requestId];
    } catch (\PDOException $e) {
        if (db()->inTransaction()) db()->rollBack();
        error_log('edit_request_create error: ' . $e->getMessage());
        return [false, 'Failed to create edit request: ' . $e->getMessage()];
    }
}

function edit_requests_list($deptOrFilters = null, ?string $status = null, ?string $year = null, ?int $requestedBy = null): array
{
    $q = null;
    if (is_array($deptOrFilters)) {
        $dept        = $deptOrFilters['department'] ?? null;
        $status      = $deptOrFilters['status'] ?? null;
        $year        = $deptOrFilters['academic_year'] ?? null;
        $requestedBy = $deptOrFilters['requested_by'] ?? null;
        $q           = $deptOrFilters['q'] ?? null;
    } else {
        $dept = $deptOrFilters;
    }

    $sql = "SELECT er.*, u.email AS requested_by_email FROM edit_requests er
            LEFT JOIN users u ON er.requested_by = u.id
            WHERE 1=1";
    $params = [];

    if ($dept !== null && $dept !== '') {
        $sql .= " AND er.department = ?";
        $params[] = $dept;
    }
    if ($status !== null && $status !== '' && $status !== 'all') {
        $sql .= " AND er.status = ?";
        $params[] = $status;
    }
    if ($year !== null && $year !== '') {
        $sql .= " AND er.academic_year = ?";
        $params[] = $year;
    }
    if ($requestedBy !== null && $requestedBy > 0) {
        $sql .= " AND er.requested_by = ?";
        $params[] = $requestedBy;
    }
    if ($q !== null && $q !== '') {
        $sql .= " AND (er.faculty_name LIKE ? OR er.record_title LIKE ? OR er.reason LIKE ? OR er.department LIKE ?)";
        $params[] = "%{$q}%";
        $params[] = "%{$q}%";
        $params[] = "%{$q}%";
        $params[] = "%{$q}%";
    }

    $sql .= " ORDER BY er.created_at DESC";

    try {
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (\PDOException $e) {
        error_log('edit_requests_list error: ' . $e->getMessage());
        return [];
    }
}

function edit_request_find(int $id): ?array
{
    try {
        $stmt = db()->prepare("SELECT * FROM edit_requests WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (\PDOException $e) {
        return null;
    }
}

function edit_request_review(int $requestId, string $decision, ?string $comment, array $user): array
{
    if (!in_array($user['role'], ['Dean', 'Admin'], true)) {
        return [false, 'Only Dean or Admin can approve or reject Edit Requests.'];
    }

    if (!in_array($decision, ['approve', 'reject'], true)) {
        return [false, 'Invalid decision.'];
    }

    $req = edit_request_find($requestId);
    if (!$req) {
        return [false, 'Edit Request not found.'];
    }

    if ($req['status'] !== 'Pending') {
        return [false, "This Edit Request has already been decided ({$req['status']})."];
    }

    $types = record_types();
    if (!isset($types[$req['record_type']])) {
        return [false, 'Invalid record type referenced in Edit Request.'];
    }
    $table = $types[$req['record_type']]['table'];

    try {
        db()->beginTransaction();

        $newReqStatus = ($decision === 'approve') ? 'Approved' : 'Rejected';
        $stmt = db()->prepare(
            "UPDATE edit_requests SET
                status = ?,
                decision_by = ?,
                decision_by_name = ?,
                decision_role = ?,
                decision_comment = ?,
                decided_at = NOW(),
                processed_by = ?,
                processed_at = NOW(),
                admin_comments = ?
             WHERE id = ?"
        );
        $stmt->execute([
            $newReqStatus,
            (int)$user['id'],
            $user['name'] ?? 'Dean',
            $user['role'],
            $comment ?: null,
            (int)$user['id'],
            $comment ?: null,
            $requestId,
        ]);

        if ($decision === 'approve') {
            $newRecordStatus = 'Unlocked for Edit';
            $note = $comment ?: "Dean approved HoD edit request ER-{$requestId}";
            $upd = db()->prepare("UPDATE `{$table}` SET status = ?, review_remark = ?, updated_at = NOW() WHERE id = ?");
            $upd->execute([$newRecordStatus, $note, $req['record_id']]);

            record_workflow_audit(
                $req['record_type'],
                $req['record_id'],
                'DEAN_EDIT_APPROVED',
                $user,
                'Edit Requested',
                'Unlocked for Edit',
                $comment,
                ['request_id' => $requestId, 'reason' => $req['reason']],
                $req['department'],
                $req['academic_year']
            );

            $msg = "Edit Request ER-{$requestId} approved. Record unlocked for Coordinator correction.";
        } else {
            $newRecordStatus = 'Approved';
            $note = "Edit request ER-{$requestId} rejected by Dean: " . ($comment ?: 'No reason provided');
            $upd = db()->prepare("UPDATE `{$table}` SET status = ?, review_remark = ?, updated_at = NOW() WHERE id = ?");
            $upd->execute([$newRecordStatus, $note, $req['record_id']]);

            record_workflow_audit(
                $req['record_type'],
                $req['record_id'],
                'DEAN_EDIT_REJECTED',
                $user,
                'Edit Requested',
                'Approved',
                $comment,
                ['request_id' => $requestId, 'reason' => $req['reason']],
                $req['department'],
                $req['academic_year']
            );

            $msg = "Edit Request ER-{$requestId} rejected. Record remains unchanged.";
        }

        db()->commit();
        return [true, $msg];
    } catch (\PDOException $e) {
        if (db()->inTransaction()) db()->rollBack();
        error_log('edit_request_review error: ' . $e->getMessage());
        return [false, 'Failed to process decision: ' . $e->getMessage()];
    }
}

function edit_request_complete(int $recordId, string $recordType, int $coordinatorId, ?array $oldValues = null, ?array $newValues = null): void
{
    try {
        $stmt = db()->prepare(
            "UPDATE edit_requests SET status = 'Completed', authorized_coordinator_id = ?, completed_at = NOW()
             WHERE record_id = ? AND record_type = ? AND status = 'Approved'"
        );
        $stmt->execute([$coordinatorId, $recordId, $recordType]);
    } catch (\PDOException $e) {
        error_log('edit_request_complete error: ' . $e->getMessage());
    }
}

function record_acknowledge_review(string $type, int $id, array $user): array
{
    if ($user['role'] !== 'HoD' && $user['role'] !== 'Admin') {
        return [false, 'Only HoD or Admin can acknowledge record review.'];
    }
    $rec = record_find($type, $id);
    if (!$rec) {
        return [false, 'Record not found.'];
    }
    $hodDept = $user['department'] ?? '';
    if ($user['role'] === 'HoD' && !empty($hodDept) && !department_names_match($rec['department'] ?? '', $hodDept)) {
        return [false, 'Access Denied: Record belongs to another department.'];
    }
    $types = record_types();
    if (!isset($types[$type])) {
        return [false, 'Invalid record type.'];
    }
    $table = $types[$type]['table'];

    try {
        $oldStatus = $rec['status'] ?? 'Resubmitted';
        $newStatus = 'Approved';
        $remark = 'Review completed and acknowledged by HoD ' . ($user['name'] ?? '');
        $stmt = db()->prepare("UPDATE `{$table}` SET status = ?, review_remark = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$newStatus, $remark, $id]);

        record_workflow_audit(
            $type,
            $id,
            'HOD_REVIEW_ACKNOWLEDGED',
            $user,
            $oldStatus,
            $newStatus,
            $remark,
            ['acknowledged_by' => $user['name'] ?? 'HoD', 'role' => $user['role']],
            $rec['department'] ?? $hodDept,
            $rec['academic_year'] ?? active_academic_year()
        );

        return [true, 'Record review acknowledged and confirmed in database.'];
    } catch (\PDOException $e) {
        return [false, 'Failed to acknowledge review: ' . $e->getMessage()];
    }
}

function my_records(int $userId): array
{
    $types = record_types();
    $all = [];

    foreach ($types as $key => $t) {
        try {
            $stmt = db()->prepare("SELECT *, '{$key}' AS record_type FROM `{$t['table']}` WHERE created_by = ? ORDER BY created_at DESC");
            $stmt->execute([$userId]);

            foreach ($stmt as $row) {
                $row['_type_key']   = $key;
                $row['_type_label'] = $t['label'];
                $row['_title']      = $row[$t['title_col']] ?? '(untitled)';
                $all[] = $key === 'nptel' ? nptel_decorate_row($row) : $row;
            }
        } catch (\PDOException $e) {
            continue;
        }
    }

    usort($all, fn($a, $b) => strtotime($b['created_at']) <=> strtotime($a['created_at']));
    return $all;
}

/**
 * Dean or Admin direct 'Request Edit' from Dual View without forcing direct approval or silent overwrite.
 * Unlocks the record for Coordinator correction and creates an authorized edit request ticket.
 */
function record_request_edit_by_dean(string $type, int $id, array $user, string $reason, ?string $specificField = null, ?string $currentVal = null, ?string $requestedVal = null): array
{
    if (!in_array($user['role'], ['Dean', 'Admin'], true)) {
        return [false, 'Only Dean or Administrator can request edits on records in dual view.'];
    }

    $reason = trim($reason);
    if ($reason === '') {
        return [false, 'A clear reason / instruction for the edit request is required.'];
    }

    $rec = record_find($type, $id);
    if (!$rec) {
        return [false, 'Record not found.'];
    }

    $academicYear = $rec['academic_year'] ?? active_academic_year();
    if ($user['role'] !== 'Admin' && academic_year_is_locked($academicYear)) {
        return [false, "Academic year {$academicYear} cycle is locked by Administrator. Workflow is frozen."];
    }

    $types = record_types();
    if (!isset($types[$type])) {
        return [false, 'Invalid record type.'];
    }
    $table = $types[$type]['table'];

    $facultyName = $rec['faculty_name'] ?? $rec['candidate_name'] ?? $rec['student_name'] ?? 'Faculty Member';
    $facultyId   = !empty($rec['created_by']) ? (int)$rec['created_by'] : null;
    $dept        = $rec['department'] ?? ($user['department'] ?? 'General');
    $title       = $rec['_title'] ?? '';

    try {
        db()->beginTransaction();

        // 1. Create or update the edit_requests entry with status 'Approved' (since Dean/Admin is the authority)
        $stmtExisting = db()->prepare("SELECT id FROM edit_requests WHERE record_id = ? AND record_type = ? ORDER BY id DESC LIMIT 1");
        $stmtExisting->execute([$id, $type]);
        $existingReqId = (int) $stmtExisting->fetchColumn();

        if ($existingReqId > 0) {
            $stmtUpd = db()->prepare(
                "UPDATE edit_requests SET
                    status = 'Approved',
                    decision_by = ?,
                    decision_by_name = ?,
                    decision_role = ?,
                    decision_comment = ?,
                    decided_at = NOW(),
                    reason = ?
                 WHERE id = ?"
            );
            $stmtUpd->execute([
                (int)($user['id'] ?? 0),
                $user['name'] ?? 'Dean',
                $user['role'] ?? 'Dean',
                'Correction authorized by ' . ($user['role'] ?? 'Dean') . ' for Coordinator correction',
                $reason,
                $existingReqId
            ]);
            $requestId = $existingReqId;
        } else {
            $stmtIns = db()->prepare(
                "INSERT INTO edit_requests (
                    record_type, record_id, record_title, faculty_name, faculty_id,
                    department, academic_year, requested_by, requested_by_name,
                    requested_by_role, reason, specific_field, current_value, requested_value,
                    status, decision_by, decision_by_name, decision_role, decision_comment, decided_at, created_at
                ) VALUES (
                    ?, ?, ?, ?, ?,
                    ?, ?, ?, ?,
                    ?, ?, ?, ?, ?,
                    'Approved', ?, ?, ?, ?, NOW(), NOW()
                )"
            );
            $stmtIns->execute([
                $type,
                $id,
                $title,
                $facultyName,
                $facultyId,
                $dept,
                $academicYear,
                (int)($user['id'] ?? 0),
                $user['name'] ?? 'Authorized Reviewer',
                $user['role'] ?? 'Dean',
                $reason,
                $specificField,
                $currentVal,
                $requestedVal,
                (int)($user['id'] ?? 0),
                $user['name'] ?? 'Authorized Reviewer',
                $user['role'] ?? 'Dean',
                'Correction authorized by ' . ($user['role'] ?? 'Dean') . ' for Coordinator correction'
            ]);
            $requestId = (int) db()->lastInsertId();
        }

        // 2. Unlock record for Coordinator correction
        $oldStatus = $rec['status'] ?? 'Approved';
        $newStatus = 'Unlocked for Edit';
        $rolePrefix = ($user['role'] === 'Dean') ? 'Dean Note: ' : 'Admin Note: ';
        $remark = $rolePrefix . $reason . ($specificField ? " (Target Field: {$specificField})" : '');

        $updRec = db()->prepare("UPDATE `{$table}` SET status = ?, review_remark = ?, updated_at = NOW() WHERE id = ?");
        $updRec->execute([$newStatus, $remark, $id]);

        // 3. Workflow audit trail
        $auditAction = ($user['role'] === 'Dean') ? 'DEAN_REQUESTED_CORRECTION' : 'ADMIN_REQUESTED_CORRECTION';
        record_workflow_audit(
            $type,
            $id,
            $auditAction,
            $user,
            $oldStatus,
            $newStatus,
            $reason,
            [
                'request_id' => $requestId,
                'specific_field' => $specificField,
                'requested_by_role' => $user['role'],
                'unlocked_for' => 'Coordinator',
            ],
            $dept,
            $academicYear
        );

        db()->commit();

        return [
            true,
            "Edit requested successfully for Record #{$id}. The record is now Unlocked for Coordinator correction without direct approval or silent override."
        ];
    } catch (\PDOException $e) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        return [false, 'Failed to request edit: ' . $e->getMessage()];
    }
}

function record_submit_for_review(string $type, int $id, array $user, ?array $fieldsData = null, ?string $proofFile = null): array
{
    $types = record_types();
    if (!isset($types[$type])) {
        return [false, 'Invalid record type.'];
    }
    $table = $types[$type]['table'];
    $activeYear = active_academic_year();
    $targetStatus = record_requires_approval($type) ? 'Submitted' : 'Approved';

    $pdo = db();
    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("SELECT * FROM `{$table}` WHERE id = ? FOR UPDATE");
        $stmt->execute([$id]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$record) {
            $pdo->rollBack();
            return [false, 'Record not found.'];
        }

        if ($user['role'] === 'Faculty') {
            if ((int)($record['created_by'] ?? 0) !== (int)$user['id']) {
                $pdo->rollBack();
                return [false, 'You do not have permission to submit this record for review.'];
            }
        } elseif ($user['role'] === 'Coordinator') {
            if (!empty($user['department']) && !empty($record['department']) && !department_names_match($record['department'], $user['department']) && (int)($record['created_by'] ?? 0) !== (int)$user['id']) {
                $pdo->rollBack();
                return [false, 'Record is outside your department scope.'];
            }
        }

        if (isset($record['academic_year']) && $record['academic_year'] !== $activeYear && $user['role'] !== 'Admin') {
            $pdo->rollBack();
            return [false, "Record belongs to academic year {$record['academic_year']}, but the active academic year is {$activeYear}."];
        }

        $validFromStatuses = ['Draft', 'Unlocked for Edit'];
        if (!in_array($record['status'], $validFromStatuses, true) && $record['status'] !== $targetStatus) {
            $pdo->rollBack();
            return [false, "Record is currently in status '{$record['status']}' and cannot be submitted for review."];
        }

        $setPairs = ["`status` = ?", "`updated_at` = NOW()"];
        $params = [$targetStatus];

        $tableColumns = $pdo->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_COLUMN);

        if ($targetStatus === 'Approved') {
            if (in_array('approved_at', $tableColumns, true)) {
                $setPairs[] = "`approved_at` = NOW()";
            }
            if (in_array('approved_by', $tableColumns, true)) {
                $setPairs[] = "`approved_by` = ?";
                $params[] = (int)$user['id'];
            }
        }

        if ($fieldsData !== null && is_array($fieldsData)) {
            $protected = ['id', 'created_by', 'status', 'approved_by', 'review_remark', 'created_at', 'updated_at', 'academic_year'];
            $allowed = array_diff($tableColumns, $protected);

            foreach ($fieldsData as $k => $v) {
                if (!in_array($k, $allowed, true) || $v === '') continue;
                $safeKey = str_replace('`', '``', $k);
                $setPairs[] = "`{$safeKey}` = ?";
                $params[] = $v;
            }
        }

        if ($proofFile !== null && in_array('proof_file', $tableColumns, true)) {
            $setPairs[] = "`proof_file` = ?";
            $params[] = $proofFile;
        }

        $sql = "UPDATE `{$table}` SET " . implode(', ', $setPairs) . " WHERE id = ?";
        $params[] = $id;

        if ($user['role'] === 'Faculty') {
            $sql .= " AND created_by = ?";
            $params[] = (int)$user['id'];
        } elseif ($user['role'] === 'Coordinator' && !empty($user['department'])) {
            $deptVars = department_variants($user['department']);
            if (!empty($deptVars)) {
                $inPh = implode(',', array_fill(0, count($deptVars), '?'));
                $sql .= " AND (department IN ($inPh) OR created_by = ?)";
                $params = array_merge($params, $deptVars);
                $params[] = (int)$user['id'];
            } else {
                $sql .= " AND (department = ? OR created_by = ?)";
                $params[] = $user['department'];
                $params[] = (int)$user['id'];
            }
        }

        $updateStmt = $pdo->prepare($sql);
        $updateStmt->execute($params);

        if ($updateStmt->rowCount() === 0 && $record['status'] !== $targetStatus) {
            $pdo->rollBack();
            return [false, 'Failed to update record status. The record was not modified.'];
        }

        if ($targetStatus === 'Approved') {
            require_once __DIR__ . '/Target.php';
            sync_target_achieved_for_type($type);
        }

        $pdo->commit();
        $msg = !record_requires_approval($type) ? 'Record submitted successfully.' : 'Record submitted for review successfully.';
        return [true, $msg];
    } catch (\PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('record_submit_for_review PDOException: ' . $e->getMessage());
        return [false, 'Failed to submit record for review due to a database error. Please check the fields and try again.'];
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('record_submit_for_review failed: ' . $e->getMessage());
        return [false, 'An error occurred while submitting the record for review. Please try again.'];
    }
}

function placement_job_roles(): array
{
    return [
        'Software Engineer',
        'Full Stack Developer',
        'Frontend Developer',
        'Backend Developer',
        'Data Scientist',
        'Data Analyst',
        'Cloud Engineer',
        'DevOps Engineer',
        'QA / Test Engineer',
        'Systems Engineer',
        'Business Analyst',
        'Graduate Engineer Trainee',
        'Embedded Engineer',
        'Network Engineer',
        'Others',
    ];
}

function nss_activity_types(): array
{
    return [
        'NSS',
        'YRC',
        'RRC',
        'UBA',
    ];
}

/**
 * Returns formatted human-readable field labels and values for an entry,
 * using IQAC report specifications when available.
 */
function record_display_attributes(string $type, array $record): array
{
    require_once __DIR__ . '/../inc/record_specs.php';
    $specs = function_exists('record_report_specs') ? record_report_specs() : [];
    $typeSpec = $specs[$type] ?? null;

    $knownLabels = [];
    if ($typeSpec && !empty($typeSpec['columns'])) {
        foreach ($typeSpec['columns'] as $colDef) {
            $field = $colDef[1] ?? '';
            $lbl   = $colDef[0] ?? '';
            if ($field && $field !== '#' && $lbl && $lbl !== 'S.No') {
                $knownLabels[$field] = $lbl;
            }
        }
    }

    // Common column labels
    $fallbackLabels = [
        'faculty_name'        => 'Faculty Name',
        'department'          => 'Department',
        'academic_year'       => 'Academic Year',
        'academic_session'    => 'Academic Session',
        'author_type'         => 'Author Type',
        'co_authors'          => 'Co-Authors',
        'paper_title'         => 'Paper Title',
        'title'               => 'Title',
        'journal_name'        => 'Journal Name',
        'journal_type'        => 'Journal Type',
        'issn'                => 'ISSN',
        'isbn'                => 'ISBN',
        'volume_issue'        => 'Volume & Issue',
        'publication_month'   => 'Publication Month',
        'doi'                 => 'DOI / Link',
        'journal_link'        => 'Journal Website Link',
        'document_link'       => 'Document / Proof Link',
        'certificate_link'    => 'Certificate Link',
        'report_link'         => 'Report Link',
        'event_title'         => 'Event Title',
        'event_type'          => 'Event Type',
        'start_date'          => 'Start Date',
        'end_date'            => 'End Date',
        'participants_count'  => 'Participants Count',
        'organization'        => 'Organization / Agency',
        'student_name'        => 'Student Name',
        'company_name'        => 'Company / Institution',
        'salary_package'      => 'Package / Stipend',
        'course_title'        => 'Course Title',
        'score'               => 'Score / Grade',
        'activity_name'       => 'Activity Name',
    ];

    $ignoreCols = [
        'id', 'created_at', 'updated_at', 'created_by', 'approved_by', 'status',
        'review_remark', 'proof_file', '_type_key', '_type_label', '_title',
        // NPTEL: shown as the participant's name and as "NPTEL Topper: Yes/No".
        'participant_user_id', 'is_topper',
    ];

<<<<<<< HEAD
    if ($type === 'placement' || $type === 'nss' || $type === 'co_curricular' || $type === 'extra_curricular') {
        $ignoreCols[] = 'exam_session';
    }
    if ($type === 'co_curricular' || $type === 'extra_curricular') {
        $ignoreCols[] = 'category';
=======
    // Events: the mode is always shown (never guessed), and only the location
    // fields that mode uses.
    if (is_event_type($type)) {
        $mode = event_mode_normalize($record['mode'] ?? '');
        $record['mode'] = $mode ?? 'Not specified';
        foreach (event_mode_location_fields() as $col => $lbl) {
            $fallbackLabels[$col] = $lbl;
            $used = $col === 'venue' ? event_mode_uses_venue($mode) : event_mode_uses_online($mode);
            if ($mode !== null && !$used) {
                $ignoreCols[] = $col;
            }
        }
        $knownLabels['mode'] = 'Event Mode';
    }

    // Journals and books: no Exam Session; the lookup keys are internal. The
    // recorded authors are listed after the Main Author.
    $pubAuthors = [];
    if (in_array($type, ['journal', 'book'], true)) {
        array_push($ignoreCols, 'exam_session', 'doi_key', 'title_key', 'isbn_key');
        $knownLabels['faculty_name'] = 'Main Author';
        $knownLabels['author_type']  = 'Main Author Position';
        $knownLabels['doi']          = 'DOI';
        $pubAuthors = publication_authors_for($type, [(int) ($record['id'] ?? 0)])[(int) ($record['id'] ?? 0)] ?? [];
>>>>>>> 2505422ae6fabcfce2155b4b8b3676678dc6761a
    }

    $attributes = [];
    foreach ($record as $k => $v) {
        if (in_array($k, $ignoreCols, true)) {
            continue;
        }
        $label = $knownLabels[$k] ?? $fallbackLabels[$k] ?? ucwords(str_replace('_', ' ', $k));
        if ($type === 'placement') {
            if ($k === 'job_title') {
                $label = 'Job Role';
            } elseif ($k === 'academic_session' || $k === 'exam_session') {
                $label = 'Academic Session';
            }
        } elseif ($type === 'nss') {
            if ($k === 'academic_session' || $k === 'exam_session') {
                $label = 'Academic Session';
            }
        } elseif ($type === 'co_curricular' || $type === 'extra_curricular') {
            if ($k === 'academic_session' || $k === 'exam_session') {
                $label = 'Academic Session';
            } elseif ($k === 'function_name') {
                $label = 'Name of the Function / Programme';
            } elseif ($k === 'event_name') {
                $label = 'Name of the Event';
            } elseif ($k === 'event_type') {
                $label = 'Event Type';
            } elseif ($k === 'activity_type') {
                $label = 'Activity Type';
            } elseif ($k === 'level_secured') {
                $label = 'Level';
            } elseif ($k === 'position_secured') {
                $label = 'Position Secured';
            } elseif ($k === 'organising_institution') {
                $label = 'Name of the Organising Institution';
            } elseif ($k === 'team_individual') {
                $label = 'Team / Individual';
            } elseif ($k === 'team_members') {
                $label = 'Team Members';
            } elseif ($k === 'other_details') {
                $label = 'Other Details';
            }
        }
        $val = (string)($v ?? '');
        if ($type === 'placement' && $k === 'pay_scale' && $val !== '') {
            if (!str_ends_with(strtoupper($val), 'LPA')) {
                $val .= ' LPA';
            }
        }
        if (($type === 'co_curricular' || $type === 'extra_curricular') && $k === 'team_members' && $val !== '') {
            $parsed = json_decode($val, true);
            if (is_array($parsed) && !empty($parsed)) {
                $mStrings = [];
                foreach ($parsed as $m) {
                    $mStr = trim(($m['name'] ?? '') . ' (' . ($m['reg_no'] ?? '') . ')');
                    if (!empty($m['department'])) $mStr .= ' - ' . $m['department'];
                    $mStrings[] = $mStr;
                }
                $val = implode(', ', $mStrings);
            }
        }
        $attributes[] = [
            'key'   => $k,
            'label' => $label,
            'value' => $val,
        ];
        if ($k === 'faculty_name' && $pubAuthors) {
            $attributes[] = [
                'key'   => 'publication_authors',
                'label' => 'All Authors (' . count($pubAuthors) . ')',
                'value' => publication_authors_text($pubAuthors),
            ];
        }
    }

    return $attributes;
}

class Record
{
    public static function report_records(array $user, ?string $department, ?string $status, ?string $type, ?string $from = null, ?string $to = null, ?string $year = null, bool $departmentWide = false): array
    {
        return report_records($user, $department, $status, $type, $from, $to, $year, $departmentWide);
    }

    public static function academic_records(array $user, ?string $department = null, ?string $status = null, ?string $from = null, ?string $to = null, ?string $year = null): array
    {
        return report_records($user, $department, $status, null, $from, $to, $year);
    }

    public static function list(string $type, ?string $department = null, ?string $status = null, ?int $createdBy = null, ?string $from = null, ?string $to = null, ?string $year = null): array
    {
        return records_list($type, $department, $status, $createdBy, $from, $to, $year);
    }

    public static function types(): array
    {
        return record_types();
    }

    public static function categories(): array
    {
        return record_categories();
    }

    public static function process_journal_expiry(?int $specificId = null, bool $forceCheck = false): int
    {
        return journal_process_approval_expiry($specificId, $forceCheck);
    }

    public static function approval_history(string $type, int $id): array
    {
        return record_approval_history($type, $id);
    }

    public static function requires_approval(string $type): bool
    {
        return record_requires_approval($type);
    }

    public static function nss_activity_types(): array
    {
        return nss_activity_types();
    }
}
