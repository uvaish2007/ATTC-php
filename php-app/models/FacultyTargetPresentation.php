<?php
/**
 * FacultyTargetPresentation Model
 * ATTS IQAC Portal — Mohamed Sathak Engineering College
 *
 * Implements the data aggregation and slide definitions for the
 * Individual Faculty Achievement Presentation.
 *
 * Scoped strictly to the authenticated faculty member and selected academic year.
 * Read-only aggregation.
 */

declare(strict_types=1);

require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/helpers.php';
require_once __DIR__ . '/Record.php';
require_once __DIR__ . '/Dashboard.php';

/**
 * Returns the authoritative list of faculty achievement metrics/categories.
 * Reuses the existing metric registry from all_metrics().
 */
function faculty_presentation_metrics(): array
{
    $base = all_metrics();

    // Enrich each metric with icons, display titles, and field mappings
    $meta = [
        'journals' => [
            'label'     => 'Journal Publications',
            'slide_title' => 'JOURNAL PUBLICATIONS',
            'icon'      => 'file-text',
            'title_col' => 'paper_title',
            'type_name' => 'journal',
        ],
        'books' => [
            'label'     => 'Books / Book Chapters',
            'slide_title' => 'BOOKS / BOOK CHAPTERS',
            'icon'      => 'book-open',
            'title_col' => 'title',
            'type_name' => 'book',
        ],
        'conferences' => [
            'label'     => 'Conference Publications',
            'slide_title' => 'CONFERENCE PUBLICATIONS',
            'icon'      => 'award',
            'title_col' => 'paper_title',
            'type_name' => 'conference',
        ],
        'patents' => [
            'label'     => 'Patents & Copyrights',
            'slide_title' => 'PATENTS / COPYRIGHTS',
            'icon'      => 'shield',
            'title_col' => 'title',
            'type_name' => 'patent',
        ],
        'fdp' => [
            'label'     => 'Faculty Training / FDP',
            'slide_title' => 'FACULTY TRAINING / FDP / WORKSHOPS',
            'icon'      => 'briefcase',
            'title_col' => 'title',
            'type_name' => 'fdp',
        ],
        'mou' => [
            'label'     => 'MoU / Industry Collaborations',
            'slide_title' => 'MOUS / INDUSTRY COLLABORATIONS',
            'icon'      => 'link',
            'title_col' => 'organization',
            'type_name' => 'mou',
        ],
        'nptel' => [
            'label'     => 'SWAYAM-NPTEL (Faculty)',
            'slide_title' => 'ONLINE COURSES / NPTEL (FACULTY)',
            'icon'      => 'graduation',
            'title_col' => 'course_title',
            'type_name' => 'nptel',
        ],
        'online' => [
            'label'     => 'Online Courses',
            'slide_title' => 'ONLINE COURSES COMPLETED',
            'icon'      => 'graduation',
            'title_col' => 'course_title',
            'type_name' => 'online_course',
        ],
        'events' => [
            'label'     => 'Events Organized',
            'slide_title' => 'EVENTS & PROGRAMMES ORGANIZED',
            'icon'      => 'calendar',
            'title_col' => 'event_title',
            'type_name' => 'event',
        ],
        'nss' => [
            'label'     => 'NSS / YRC / Community Activities',
            'slide_title' => 'NSS / YRC / COMMUNITY ACTIVITIES',
            'icon'      => 'shield',
            'title_col' => 'activity_name',
            'type_name' => 'nss',
        ],
        'value_added' => [
            'label'     => 'Value Added Courses',
            'slide_title' => 'VALUE ADDED COURSES COORDINATED',
            'icon'      => 'book',
            'title_col' => 'course_title',
            'type_name' => 'value_added',
        ],
        'training' => [
            'label'     => 'Training Programmes',
            'slide_title' => 'TRAINING PROGRAMMES CONDUCTED',
            'icon'      => 'briefcase',
            'title_col' => 'event_title',
            'type_name' => 'training',
        ],
        'internships' => [
            'label'     => 'Student Internships (Supervised)',
            'slide_title' => 'STUDENT INTERNSHIPS (SUPERVISED)',
            'icon'      => 'briefcase',
            'title_col' => 'title',
            'type_name' => 'internship',
        ],
        'placements' => [
            'label'     => 'Student Placements (Coordinated)',
            'slide_title' => 'STUDENT PLACEMENTS (COORDINATED)',
            'icon'      => 'briefcase',
            'title_col' => 'student_name',
            'type_name' => 'placement',
        ],
        'summer_training' => [
            'label'     => 'Summer / Winter Training',
            'slide_title' => 'SUMMER / WINTER TRAINING',
            'icon'      => 'calendar',
            'title_col' => 'title',
            'type_name' => 'summer_training',
        ],
        'achievements' => [
            'label'     => 'Student Achievements (Mentored)',
            'slide_title' => 'STUDENT ACHIEVEMENTS (MENTORED)',
            'icon'      => 'award',
            'title_col' => 'event_name',
            'type_name' => 'student_achievement',
        ],
        'participations' => [
            'label'     => 'Student Participations (Mentored)',
            'slide_title' => 'STUDENT PARTICIPATIONS (MENTORED)',
            'icon'      => 'users',
            'title_col' => 'event_name',
            'type_name' => 'student_achievement',
        ],
        'inter_institute' => [
            'label'     => 'Inter-Institute Events & Awards',
            'slide_title' => 'INTER-INSTITUTE EVENTS & AWARDS',
            'icon'      => 'award',
            'title_col' => 'event_name',
            'type_name' => 'inst_inter_inst_within',
        ],
        'nptel_student' => [
            'label'     => 'NPTEL (Student Certifications)',
            'slide_title' => 'NPTEL (STUDENT CERTIFICATIONS)',
            'icon'      => 'graduation',
            'title_col' => 'course_title',
            'type_name' => 'nptel',
        ],
    ];

    $result = [];
    foreach ($base as $k => $item) {
        $result[$k] = array_merge($item, $meta[$k] ?? [
            'label'       => $item['label'] ?? ucfirst($k),
            'slide_title' => strtoupper($item['label'] ?? $k),
            'icon'        => 'file-text',
            'title_col'   => 'title',
            'type_name'   => $k,
        ]);
    }

    return $result;
}

/**
 * Formats a record's display fields according to its metric category.
 */
function faculty_format_record_row(string $catKey, array $raw): array
{
    $title = '';
    $details = [];
    $doi = trim((string) ($raw['doi'] ?? ''));
    $docLink = trim((string) ($raw['document_link'] ?? ($raw['journal_link'] ?? ($raw['proceedings_link'] ?? ($raw['certificate_link'] ?? ($raw['appointment_order_link'] ?? ($raw['report_link'] ?? '')))))));
    $proofFile = trim((string) ($raw['proof_file'] ?? ''));

    switch ($catKey) {
        case 'journals':
            $title = (string) ($raw['paper_title'] ?? 'Journal Paper');
            if (!empty($raw['journal_name'])) $details[] = $raw['journal_name'];
            if (!empty($raw['journal_type'])) $details[] = 'Index: ' . $raw['journal_type'];
            if (!empty($raw['publication_year'])) $details[] = 'Year: ' . $raw['publication_year'] . (!empty($raw['publication_month']) ? '/' . $raw['publication_month'] : '');
            if (!empty($raw['issn'])) $details[] = 'ISSN: ' . $raw['issn'];
            if (!empty($raw['author_type'])) $details[] = $raw['author_type'];
            break;

        case 'books':
            $title = (string) ($raw['title'] ?? 'Book / Chapter');
            if (!empty($raw['publication_category'])) $details[] = $raw['publication_category'];
            if (!empty($raw['publisher_name'])) $details[] = 'Publisher: ' . $raw['publisher_name'];
            if (!empty($raw['isbn'])) $details[] = 'ISBN: ' . $raw['isbn'];
            if (!empty($raw['publication_year'])) $details[] = 'Year: ' . $raw['publication_year'];
            break;

        case 'conferences':
            $title = (string) ($raw['paper_title'] ?? 'Conference Paper');
            if (!empty($raw['conference_name'])) $details[] = $raw['conference_name'];
            if (!empty($raw['conference_type'])) $details[] = 'Type: ' . $raw['conference_type'];
            if (!empty($raw['conference_date'])) $details[] = 'Date: ' . $raw['conference_date'];
            if (!empty($raw['venue'])) $details[] = 'Venue: ' . $raw['venue'];
            break;

        case 'patents':
            $title = (string) ($raw['title'] ?? 'Patent / Copyright');
            if (!empty($raw['patent_type'])) $details[] = $raw['patent_type'];
            if (!empty($raw['application_number'])) $details[] = 'App No: ' . $raw['application_number'];
            if (!empty($raw['patent_number'])) $details[] = 'Patent No: ' . $raw['patent_number'];
            if (!empty($raw['filing_date'])) $details[] = 'Filed: ' . $raw['filing_date'];
            break;

        case 'fdp':
            $title = (string) ($raw['title'] ?? 'FDP / Training');
            if (!empty($raw['program_type'])) $details[] = $raw['program_type'];
            if (!empty($raw['host_institution'])) $details[] = 'Host: ' . $raw['host_institution'];
            if (!empty($raw['from_date'])) $details[] = 'Period: ' . $raw['from_date'] . (!empty($raw['to_date']) ? ' to ' . $raw['to_date'] : '');
            break;

        case 'mou':
            $title = (string) ($raw['organization'] ?? 'Industry MoU');
            if (!empty($raw['purpose'])) $details[] = 'Purpose: ' . $raw['purpose'];
            if (!empty($raw['signed_date'])) $details[] = 'Signed: ' . $raw['signed_date'];
            if (!empty($raw['valid_upto'])) $details[] = 'Valid Upto: ' . $raw['valid_upto'];
            break;

        case 'nptel':
        case 'nptel_student':
            $title = (string) ($raw['course_title'] ?? 'SWAYAM-NPTEL Course');
            if (!empty($raw['participant_type'])) $details[] = 'Participant: ' . $raw['participant_type'];
            if (!empty($raw['certificate_type'])) $details[] = 'Certificate: ' . $raw['certificate_type'];
            if (!empty($raw['score'])) $details[] = 'Score: ' . $raw['score'];
            break;

        case 'online':
            $title = (string) ($raw['course_title'] ?? 'Online Course');
            if (!empty($raw['platform'])) $details[] = 'Platform: ' . $raw['platform'];
            if (!empty($raw['completion_date'])) $details[] = 'Completed: ' . $raw['completion_date'];
            break;

        case 'events':
            $title = (string) ($raw['event_title'] ?? 'Event');
            if (!empty($raw['event_type'])) $details[] = 'Type: ' . $raw['event_type'];
            if (!empty($raw['mode'])) $details[] = 'Mode: ' . $raw['mode'];
            if (!empty($raw['event_date'])) $details[] = 'Date: ' . $raw['event_date'];
            if (!empty($raw['resource_person'])) $details[] = 'Resource Person: ' . $raw['resource_person'];
            break;

        case 'internships':
            $title = (string) ($raw['title'] ?? 'Internship');
            if (!empty($raw['student_name'])) $details[] = 'Student: ' . $raw['student_name'] . (!empty($raw['reg_no']) ? ' (' . $raw['reg_no'] . ')' : '');
            if (!empty($raw['industry'])) $details[] = 'Industry: ' . $raw['industry'];
            if (!empty($raw['duration'])) $details[] = 'Duration: ' . $raw['duration'];
            break;

        case 'placements':
            $title = (string) (!empty($raw['job_title']) ? $raw['job_title'] . ' at ' . ($raw['company_name'] ?? $raw['company'] ?? 'Company') : ($raw['student_name'] ?? 'Student Placement'));
            if (!empty($raw['student_name'])) $details[] = 'Student: ' . $raw['student_name'] . (!empty($raw['reg_no']) ? ' (' . $raw['reg_no'] . ')' : '');
            if (!empty($raw['company_name']) || !empty($raw['company'])) $details[] = 'Company: ' . ($raw['company_name'] ?: $raw['company']);
            if (!empty($raw['package_ctc']) || !empty($raw['pay_scale'])) $details[] = 'Package: ₹' . number_format((float) ($raw['package_ctc'] ?: $raw['pay_scale']));
            if (!empty($raw['placement_type'])) $details[] = $raw['placement_type'];
            break;

        default:
            $title = (string) ($raw['paper_title'] ?? ($raw['event_title'] ?? ($raw['course_title'] ?? ($raw['activity_name'] ?? ($raw['title'] ?? ($raw['event_name'] ?? ($raw['student_name'] ?? 'Record #' . ($raw['id'] ?? ''))))))));
            if (!empty($raw['academic_year'])) $details[] = 'AY: ' . $raw['academic_year'];
            if (!empty($raw['created_at'])) $details[] = 'Date: ' . substr((string) $raw['created_at'], 0, 10);
            break;
    }

    $status = $raw['status'] ?: 'Draft';

    return [
        'id'         => (int) ($raw['id'] ?? 0),
        'title'      => $title !== '' ? $title : '(Untitled Record)',
        'meta_text'  => implode(' • ', $details),
        'doi'        => $doi,
        'doc_link'   => $docLink,
        'proof_file' => $proofFile,
        'status'     => $status,
        'created_at' => $raw['created_at'] ?? '',
        'academic_year' => $raw['academic_year'] ?? '',
    ];
}

/**
 * Gathers the complete authorized dataset for the individual Faculty Achievement Presentation.
 * Scoped strictly to $user['id'] and the given $academicYear.
 */
function faculty_target_presentation_dataset(array $user, string $academicYear): array
{
    $pdo = db();
    $userId = (int) $user['id'];
    $academicYear = trim($academicYear);

    // Refresh faculty profile details
    $uStmt = $pdo->prepare("SELECT id, name, email, department, role, phone FROM users WHERE id = ?");
    $uStmt->execute([$userId]);
    $dbUser = $uStmt->fetch(PDO::FETCH_ASSOC);

    $facultyName = $dbUser['name'] ?? ($user['name'] ?? 'Faculty Member');
    $department  = $dbUser['department'] ?? ($user['department'] ?? 'Department');
    $role        = $dbUser['role'] ?? ($user['role'] ?? 'Faculty');

    $designation = trim((string) ($user['designation'] ?? ''));
    if ($designation === '') {
        $designation = ($role === 'HoD') ? 'Head of Department'
                     : (($role === 'Coordinator') ? 'Department Coordinator' : 'Assistant Professor');
    }

    $faculty = [
        'id'          => $userId,
        'name'        => $facultyName,
        'department'  => $department,
        'role'        => $role,
        'designation' => $designation,
        'email'       => $dbUser['email'] ?? ($user['email'] ?? ''),
    ];

    $metricsDef = faculty_presentation_metrics();

    $categories = [];
    $totalRecords = 0;
    $approvedRecords = 0;
    $pendingRecords = 0;
    $rejectedRecords = 0;
    $contributedCategories = 0;

    $activeCategorySlides = [];

    foreach ($metricsDef as $catKey => $m) {
        $table = $m['table'];
        $sql = "SELECT * FROM `{$table}` WHERE created_by = ?";
        $params = [$userId];

        if (!empty($m['where'])) {
            $sql .= " AND ({$m['where']})";
        }
        if (!empty($m['year'])) {
            $sql .= " AND academic_year = ?";
            $params[] = $academicYear;
        }
        $sql .= " ORDER BY created_at DESC, id DESC";

        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (\PDOException $e) {
            $rows = [];
        }

        $catTotal = count($rows);
        $catApproved = 0;
        $catPending = 0;
        $catRejected = 0;
        $formattedRecords = [];

        foreach ($rows as $r) {
            $s = $r['status'] ?: 'Draft';
            if ($s === 'Approved') {
                $catApproved++;
                $approvedRecords++;
            } elseif ($s === 'Rejected') {
                $catRejected++;
                $rejectedRecords++;
            } else {
                $catPending++;
                $pendingRecords++;
            }

            $formatted = faculty_format_record_row($catKey, $r);
            $formatted['type_key'] = $catKey;
            $formatted['type_name'] = $m['type_name'] ?? $catKey;
            $formattedRecords[] = $formatted;
        }

        $totalRecords += $catTotal;

        $catSummary = [
            'key'         => $catKey,
            'label'       => $m['label'],
            'slide_title' => $m['slide_title'],
            'icon'        => $m['icon'],
            'total'       => $catTotal,
            'approved'    => $catApproved,
            'pending'     => $catPending,
            'rejected'    => $catRejected,
            'records'     => $formattedRecords,
            'has_records' => ($catTotal > 0),
            'verification_pct' => ($catTotal > 0) ? round(($catApproved / $catTotal) * 100, 1) : 0,
        ];

        $categories[$catKey] = $catSummary;

        if ($catTotal > 0) {
            $contributedCategories++;
            $activeCategorySlides[] = $catSummary;
        }
    }

    $verificationRate = ($totalRecords > 0)
        ? round(($approvedRecords / $totalRecords) * 100, 1)
        : 0.0;

    $summary = [
        'total_records'          => $totalRecords,
        'approved_records'       => $approvedRecords,
        'pending_records'        => $pendingRecords,
        'rejected_records'       => $rejectedRecords,
        'categories_contributed' => $contributedCategories,
        'verification_rate'      => $verificationRate,
        'academic_year'          => $academicYear,
    ];

    // Compute presentation highlights
    $verifiedHighlights = [];
    foreach ($activeCategorySlides as $cs) {
        if ($cs['approved'] > 0) {
            $verifiedHighlights[] = $cs['approved'] . ' Verified ' . $cs['label'];
        }
    }

    // Determine total slides:
    // Slide 1: Faculty Overall Achievement Summary
    // Slide 2: My Achievements by Category
    // Slides 3..N: Individual Category Detail Slides (1 per active category, minimum 1 if empty)
    // Final Slide: Final Faculty Achievement Summary
    $catSlidesCount = max(1, count($activeCategorySlides));
    $totalSlides = 2 + $catSlidesCount + 1;

    return [
        'faculty'                => $faculty,
        'academic_year'          => $academicYear,
        'summary'                => $summary,
        'categories'             => $categories,
        'active_category_slides' => $activeCategorySlides,
        'total_slides'           => $totalSlides,
        'verified_highlights'    => $verifiedHighlights,
    ];
}
