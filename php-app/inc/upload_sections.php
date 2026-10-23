<?php
/**
 * ATTS-MODULE-CONSOLIDATION — Upload Section Reorganization and Category Merging
 *
 * Defines the authoritative 30 approved top-level upload sections in exact order.
 * Handles category mapping, subcategory resolution, backward compatibility,
 * and next/prev progression across academic targets.
 */

declare(strict_types=1);

function upload_approved_sections_built_in(): array
{
    static $sections = null;
    if ($sections !== null) {
        return $sections;
    }

    $sections = [
        // ── 1. UNIVERSITY AND STUDENT PERFORMANCE ──────────────────────────
        'upercent' => [
            'id'           => 'upercent',
            'order'        => 1,
            'title'        => 'University Pass Percentage',
            'group'        => 'UNIVERSITY AND STUDENT PERFORMANCE',
            'default_type' => 'inst_pass_percentage',
            'types'        => ['inst_pass_percentage', 'inst_college_rank', 'inst_student_rank'],
            'subcategories'=> [],
            'description'  => 'University exam session results, semester statistics, and automatic pass percentage calculations.',
        ],
        'student_cgpa' => [
            'id'           => 'student_cgpa',
            'order'        => 2,
            'title'        => 'Students Secured Above 8.5 CGPA',
            'group'        => 'UNIVERSITY AND STUDENT PERFORMANCE',
            'default_type' => 'inst_student_cgpa',
            'types'        => ['inst_student_cgpa'],
            'subcategories'=> [],
            'description'  => 'Identifying and tracking students achieving above 8.5 CGPA across I, II, III, and IV Year.',
        ],
        'placement_mnc' => [
            'id'           => 'placement_mnc',
            'order'        => 3,
            'title'        => 'Place 2024–25 Batch Students in Top MNCs',
            'group'        => 'UNIVERSITY AND STUDENT PERFORMANCE',
            'default_type' => 'inst_placement_mnc',
            'types'        => ['inst_placement_mnc', 'placement'],
            'subcategories'=> [
                'inst_placement_mnc' => 'Top MNCs Placement (2024-25 Batch)',
                'placement'          => 'Placement Record',
            ],
            'description'  => '2024–25 batch placements in top multinational companies, compensation, and appointment orders.',
        ],

        // ── 2. RESEARCH AND PUBLICATIONS ───────────────────────────────────
        'publications' => [
            'id'           => 'publications',
            'order'        => 4,
            'title'        => 'Quality Publications in Scopus / SCI / Springer / UGC CARE / H-Index',
            'group'        => 'RESEARCH AND PUBLICATIONS',
            'default_type' => 'journal',
            'types'        => ['journal', 'conference', 'inst_publications'],
            'subcategories'=> [
                'journal'    => 'Journal Publication',
                'conference' => 'Conference Publication',
            ],
            'description'  => 'Scopus, SCI, Springer, UGC CARE indexed journal and conference publications with DOI and proof.',
        ],
        'books' => [
            'id'           => 'books',
            'order'        => 5,
            'title'        => 'Books Publication — Book / Book Chapter',
            'group'        => 'RESEARCH AND PUBLICATIONS',
            'default_type' => 'book',
            'types'        => ['book', 'inst_books', 'inst_book_chapters'],
            'subcategories'=> [
                'book' => 'Book / Book Chapter',
            ],
            'description'  => 'Books and book chapters published with ISBN, publisher, faculty authors, and documentation.',
        ],
        'patents' => [
            'id'           => 'patents',
            'order'        => 6,
            'title'        => 'Patent / Copyrights',
            'group'        => 'RESEARCH AND PUBLICATIONS',
            'default_type' => 'patent',
            'types'        => ['patent', 'inst_patents_published', 'inst_patents_granted', 'inst_copyrights'],
            'subcategories'=> [], // Handled via category dropdown: Patent Published, Patent Granted, Design, Copyrights
            'description'  => 'Patent published, patent granted, design registrations, and copyrights with status tracking.',
        ],
        'sponsored_research' => [
            'id'           => 'sponsored_research',
            'order'        => 7,
            'title'        => 'Sponsored Research Funds / Consultancy Projects (in Lakhs)',
            'group'        => 'RESEARCH AND PUBLICATIONS',
            'default_type' => 'inst_sponsored_research',
            'types'        => ['inst_sponsored_research', 'inst_consultancy'],
            'subcategories'=> [
                'inst_sponsored_research' => 'Sponsored Research Funds',
                'inst_consultancy'        => 'Consultancy Projects',
            ],
            'description'  => 'External sponsored research funding and consultancy project grants reported in lakhs.',
        ],
        'research_centre' => [
            'id'           => 'research_centre',
            'order'        => 8,
            'title'        => 'Research Centre Recognition from Anna University',
            'group'        => 'RESEARCH AND PUBLICATIONS',
            'default_type' => 'inst_research_centre',
            'types'        => ['inst_research_centre'],
            'subcategories'=> [],
            'description'  => 'Departmental research centre recognitions from Anna University with reference orders.',
        ],
        'ipr_programmes' => [
            'id'           => 'ipr_programmes',
            'order'        => 9,
            'title'        => 'Programme on IPR / Higher Studies / Entrepreneurship',
            'group'        => 'RESEARCH AND PUBLICATIONS',
            'default_type' => 'inst_ipr_programmes',
            'types'        => ['inst_ipr_programmes'],
            'subcategories'=> [], // Handled via programme_type dropdown: IPR, Higher Studies, Entrepreneurship
            'description'  => 'Programmes and workshops conducted on IPR, Higher Studies preparation, and Entrepreneurship.',
        ],

        // ── 3. CERTIFICATIONS, INDUSTRY AND TRAINING ───────────────────────
        'faculty_certifications' => [
            'id'           => 'faculty_certifications',
            'order'        => 10,
            'title'        => 'No. of Online Certification Courses Completed by Faculty',
            'group'        => 'CERTIFICATIONS, INDUSTRY AND TRAINING',
            'default_type' => 'nptel',
            'types'        => ['nptel', 'online_course', 'inst_faculty_certifications'],
            'subcategories'=> [
                'nptel'                       => 'NPTEL / Swayam Certification',
                'inst_faculty_certifications' => 'Other Online Certifications',
            ],
            'description'  => 'Professional online certification courses completed by faculty members (NPTEL, Coursera, etc.).',
        ],
        'mou_interactions' => [
            'id'           => 'mou_interactions',
            'order'        => 11,
            'title'        => 'Industry Interaction / MoU / Industry Supported Lab',
            'group'        => 'CERTIFICATIONS, INDUSTRY AND TRAINING',
            'default_type' => 'mou',
            'types'        => ['mou', 'inst_mou_interactions'],
            'subcategories'=> [
                'mou'                   => 'MoU Signed',
                'inst_mou_interactions' => 'Industry Interaction / Lab',
            ],
            'description'  => 'Memoranda of Understanding, industry interactions, and industry supported labs.',
        ],
        'internships' => [
            'id'           => 'internships',
            'order'        => 12,
            'title'        => "Student's Industry Internship (4 Weeks & Above)",
            'group'        => 'CERTIFICATIONS, INDUSTRY AND TRAINING',
            'default_type' => 'internship',
            'types'        => ['internship', 'inst_internships'],
            'subcategories'=> [],
            'supports_bulk'=> true,
            'description'  => 'Student industry internships lasting 4 weeks or more with individual and SmartBulk Excel imports.',
        ],
        'summer_training' => [
            'id'           => 'summer_training',
            'order'        => 13,
            'title'        => 'Summer/Winter Training (Less Than 4 Weeks)',
            'group'        => 'CERTIFICATIONS, INDUSTRY AND TRAINING',
            'default_type' => 'summer_training',
            'types'        => ['summer_training', 'inst_summer_trainings'],
            'subcategories'=> [], // Handled via Training Season dropdown: Summer, Winter
            'supports_bulk'=> true,
            'description'  => 'Short-term summer and winter training programmes (less than 4 weeks) with SmartBulk import.',
        ],
        'student_projects' => [
            'id'           => 'student_projects',
            'order'        => 14,
            'title'        => 'Quality Student Projects and Publish the Projects on YouTube',
            'group'        => 'CERTIFICATIONS, INDUSTRY AND TRAINING',
            'default_type' => 'inst_student_projects',
            'types'        => ['inst_student_projects'],
            'subcategories'=> [], // Dissemination dropdown: YouTube, Conference, Journal, Hackathon
            'description'  => 'Showcase student projects published on YouTube or presented in conferences and competitions.',
        ],
        'faculty_participations' => [
            'id'           => 'faculty_participations',
            'order'        => 15,
            'title'        => 'Faculty Participation in FDP / Training / STTP / Conference',
            'group'        => 'CERTIFICATIONS, INDUSTRY AND TRAINING',
            'default_type' => 'fdp',
            'types'        => ['fdp', 'training', 'inst_faculty_participations'],
            'subcategories'=> [], // Event Type dropdown: FDP, Workshop, Seminar, STTP, Training, Conference
            'description'  => 'Faculty participation in Faculty Development Programmes, STTPs, workshops, and conferences.',
        ],
        'society_memberships' => [
            'id'           => 'society_memberships',
            'order'        => 16,
            'title'        => 'Membership in Professional Societies (Student / Faculty)',
            'group'        => 'CERTIFICATIONS, INDUSTRY AND TRAINING',
            'default_type' => 'inst_society_memberships',
            'types'        => ['inst_society_memberships'],
            'subcategories'=> [], // Member Type dropdown: Faculty, Student
            'description'  => 'Active memberships in professional societies (IEEE, ACM, CSI, ISTE, ASME) for students and faculty.',
        ],

        // ── 4. INSTITUTIONAL AND STUDENT ACTIVITIES ────────────────────────
        'newsletters' => [
            'id'           => 'newsletters',
            'order'        => 17,
            'title'        => 'Newsletter',
            'group'        => 'INSTITUTIONAL AND STUDENT ACTIVITIES',
            'default_type' => 'inst_newsletters',
            'types'        => ['inst_newsletters'],
            'subcategories'=> [],
            'description'  => 'Departmental newsletters, volume, issue, publication date, and report documents.',
        ],
        'student_certifications' => [
            'id'           => 'student_certifications',
            'order'        => 18,
            'title'        => 'Student Online Certification',
            'group'        => 'INSTITUTIONAL AND STUDENT ACTIVITIES',
            'default_type' => 'inst_student_certifications',
            'types'        => ['inst_student_certifications'],
            'subcategories'=> [],
            'description'  => 'Technical online certifications completed by students from Coursera, NPTEL, edX, etc.',
        ],
        'spoken_tutorials' => [
            'id'           => 'spoken_tutorials',
            'order'        => 19,
            'title'        => 'No. of Students Completed IIT-Bombay Spoken Tutorial Courses',
            'group'        => 'INSTITUTIONAL AND STUDENT ACTIVITIES',
            'default_type' => 'inst_spoken_tutorials',
            'types'        => ['inst_spoken_tutorials'],
            'subcategories'=> [],
            'description'  => 'Students completing IIT-Bombay Spoken Tutorial software courses with certificate references.',
        ],
        'inter_institute' => [
            'id'           => 'inter_institute',
            'order'        => 20,
            'title'        => 'Student Participation in Inter-Institute Events and Awards / Medals',
            'group'        => 'INSTITUTIONAL AND STUDENT ACTIVITIES',
            'default_type' => 'inst_inter_inst_within',
            'types'        => ['inst_inter_inst_within', 'inst_inter_inst_outside', 'inst_inter_inst_awards', 'co_curricular', 'extra_curricular', 'student_achievements', 'student_participations'],
            'subcategories'=> [
                'inst_inter_inst_within'  => 'Participation Within State',
                'inst_inter_inst_outside' => 'Participation Outside State',
                'inst_inter_inst_awards'  => 'Awards / Medals in Events',
                'co_curricular'           => 'Co-Curricular / Team Achievements',
            ],
            'description'  => 'Student participation and awards in inter-institute symposiums, hackathons, and competitions.',
        ],
        'value_added' => [
            'id'           => 'value_added',
            'order'        => 21,
            'title'        => 'No. of Value Added Courses / Hands-on Training Courses',
            'group'        => 'INSTITUTIONAL AND STUDENT ACTIVITIES',
            'default_type' => 'value_added',
            'types'        => ['value_added', 'inst_value_added_courses'],
            'subcategories'=> [], // Course Type: Value Added Course, Hands-on Training Course
            'description'  => 'Value added courses and hands-on skill development workshops organized by departments.',
        ],
        'sports' => [
            'id'           => 'sports',
            'order'        => 22,
            'title'        => 'Event Participation in Sports — State Level / National Level / Awards',
            'group'        => 'INSTITUTIONAL AND STUDENT ACTIVITIES',
            'default_type' => 'inst_sports_state',
            'types'        => ['inst_sports_state', 'inst_sports_national'],
            'subcategories'=> [], // Merged into unified form with Competition Level dropdown: State, National
            'description'  => 'Sports participation and athletic tournament awards at state and national levels.',
        ],
        'innovation_events' => [
            'id'           => 'innovation_events',
            'order'        => 23,
            'title'        => 'Innovation Events to Be Conducted in Every Department',
            'group'        => 'INSTITUTIONAL AND STUDENT ACTIVITIES',
            'default_type' => 'inst_innovation_events',
            'types'        => ['inst_innovation_events', 'events'],
            'subcategories'=> [],
            'description'  => 'Departmental innovation challenges, ideations, hackathons, and prototype exhibitions.',
        ],
        'iic_activities' => [
            'id'           => 'iic_activities',
            'order'        => 24,
            'title'        => 'IIC Activities',
            'group'        => 'INSTITUTIONAL AND STUDENT ACTIVITIES',
            'default_type' => 'inst_iic_activities',
            'types'        => ['inst_iic_activities'],
            'subcategories'=> [],
            'description'  => "Institution's Innovation Council (IIC) approved entrepreneurship, innovation, and design sessions.",
        ],
        'website_updations' => [
            'id'           => 'website_updations',
            'order'        => 25,
            'title'        => 'Website Updation',
            'group'        => 'INSTITUTIONAL AND STUDENT ACTIVITIES',
            'default_type' => 'inst_website_updations',
            'types'        => ['inst_website_updations'],
            'subcategories'=> [],
            'description'  => 'Institutional and department portal content updates, verification dates, and web references.',
        ],
        'google_ratings' => [
            'id'           => 'google_ratings',
            'order'        => 26,
            'title'        => 'Google Rating',
            'group'        => 'INSTITUTIONAL AND STUDENT ACTIVITIES',
            'default_type' => 'inst_google_ratings',
            'types'        => ['inst_google_ratings'],
            'subcategories'=> [],
            'description'  => 'Google Rating tracking (validated on 0.0 to 5.0 scale), review counts, and observation dates.',
        ],
        'startups' => [
            'id'           => 'startups',
            'order'        => 27,
            'title'        => 'Start Up',
            'group'        => 'INSTITUTIONAL AND STUDENT ACTIVITIES',
            'default_type' => 'inst_startups',
            'types'        => ['inst_startups'],
            'subcategories'=> [],
            'description'  => 'Student and faculty startup companies, incubation stage, DPIIT/MSME recognition, and evidence.',
        ],
        'alumni_chapters' => [
            'id'           => 'alumni_chapters',
            'order'        => 28,
            'title'        => 'Alumni Chapter',
            'group'        => 'INSTITUTIONAL AND STUDENT ACTIVITIES',
            'default_type' => 'inst_alumni_chapters',
            'types'        => ['inst_alumni_chapters'],
            'subcategories'=> [],
            'description'  => 'Alumni chapter establishment, reunion meets, networking events, and member participation.',
        ],
        'awards_recognitions' => [
            'id'           => 'awards_recognitions',
            'order'        => 29,
            'title'        => 'Awards & Recognition',
            'group'        => 'INSTITUTIONAL AND STUDENT ACTIVITIES',
            'default_type' => 'inst_awards_recognitions',
            'types'        => ['inst_awards_recognitions'],
            'subcategories'=> [], // Handled via 8-option dropdown: Dept/Faculty Award, BoS, DC, QP Setting, Reviewer, Resource Person, Others
            'description'  => 'Department and faculty awards, BoS and DC memberships, question paper setting, reviewer, and resource person roles.',
        ],
        'nss_activities' => [
            'id'           => 'nss_activities',
            'order'        => 30,
            'title'        => 'No. of Activities Conducted by NSS / YRC',
            'group'        => 'INSTITUTIONAL AND STUDENT ACTIVITIES',
            'default_type' => 'nss',
            'types'        => ['nss', 'inst_nss_events'],
            'subcategories'=> [], // Handled via Unit/Type dropdown: NSS, YRC, RRC
            'description'  => 'Community service programmes, social drives, and awareness campaigns conducted by NSS and YRC.',
        ],
    ];

    return $sections;
}

/**
 * Returns the active upload sections, loaded dynamically from the database
 * via Metric Studio when available, with fallback to built-in sections.
 *
 * @param string|null $role If provided, filters according to role visibility.
 * @param bool $forceRefresh
 * @return array<string, array>
 */
function upload_approved_sections(?string $role = null, bool $forceRefresh = false): array
{
    static $cached = [];
    $cacheKey = ($role ?? 'all') . ($forceRefresh ? '_rf_' . microtime(true) : '');
    if (!$forceRefresh && isset($cached[$cacheKey])) {
        return $cached[$cacheKey];
    }

    try {
        if (!function_exists('metric_studio_all')) {
            require_once __DIR__ . '/metric_studio.php';
        }
        if (function_exists('metric_studio_all')) {
            $filters = ['sort' => 'order'];
            if ($role !== 'all_with_inactive') {
                $filters['status'] = 'active';
            }
            $dbMetrics = metric_studio_all($filters);

            if (!empty($dbMetrics)) {
                $dynamicSections = [];
                foreach ($dbMetrics as $m) {
                    if ($role === 'Faculty' && empty($m['faculty_visible'])) {
                        continue;
                    }
                    if ($role === 'Coordinator' && empty($m['coordinator_visible'])) {
                        continue;
                    }
                    if ($role !== 'all_with_inactive' && empty($m['status'])) {
                        continue;
                    }

                    $key = $m['metric_key'] ?: ('sec_' . $m['id']);
                    $defaultType = !empty($m['default_type']) ? $m['default_type'] : ('gen_' . $key);
                    $types = !empty($m['types_parsed']) ? $m['types_parsed'] : [$defaultType];
                    if (!in_array($defaultType, $types, true)) {
                        array_unshift($types, $defaultType);
                    }

                    $dynamicSections[$key] = [
                        'id'           => $key,
                        'order'        => (int)$m['display_order'],
                        'title'        => $m['name'],
                        'group'        => $m['category'],
                        'default_type' => $defaultType,
                        'types'        => $types,
                        'subcategories'=> $m['subcategories_parsed'] ?: [],
                        'description'  => $m['description'] ?: '',
                        'help_text'    => $m['help_text'] ?: '',
                        'metric_type'  => $m['metric_type'] ?: 'specialized',
                        'form_config'  => $m['form_config_parsed'] ?: [],
                        'status'       => (int)$m['status'],
                        'faculty_visible' => (int)$m['faculty_visible'],
                        'coordinator_visible' => (int)$m['coordinator_visible'],
                    ];
                }

                if (!empty($dynamicSections)) {
                    return $cached[$cacheKey] = $dynamicSections;
                }
            }
        }
    } catch (Throwable $e) {
        // Fallback to built-in if database not ready
    }

    return $cached[$cacheKey] = upload_approved_sections_built_in();
}

/**
 * Resolves a section and record type from either section ID or record type string.
 *
 * @param string|null $sectionId
 * @param string|null $typeId
 * @param string|null $role
 * @return array{section: array, type: string}
 */
function resolve_upload_section(?string $sectionId = null, ?string $typeId = null, ?string $role = null): array
{
    $sections = upload_approved_sections($role);

    // 1. Direct section ID lookup
    if (!empty($sectionId) && isset($sections[$sectionId])) {
        $sec = $sections[$sectionId];
        $type = (!empty($typeId) && in_array($typeId, $sec['types'], true)) ? $typeId : $sec['default_type'];
        return ['section' => $sec, 'type' => $type];
    }

    // 2. Direct record type reverse lookup with legacy consolidation
    if (!empty($typeId)) {
        // Aliases / legacy mappings to consolidate categories into parent sections
        $legacyMap = [
            'journal'                     => ['publications', 'journal'],
            'conference'                  => ['publications', 'conference'],
            'inst_publications'           => ['publications', 'journal'],
            'book'                        => ['books', 'book'],
            'inst_books'                  => ['books', 'book'],
            'inst_book_chapters'          => ['books', 'book'],
            'patent'                      => ['patents', 'patent'],
            'inst_patents_published'      => ['patents', 'patent'],
            'inst_patents_granted'        => ['patents', 'patent'],
            'inst_copyrights'             => ['patents', 'patent'],
            'inst_sponsored_research'     => ['sponsored_research', 'inst_sponsored_research'],
            'inst_consultancy'            => ['sponsored_research', 'inst_consultancy'],
            'inst_research_centre'        => ['research_centre', 'inst_research_centre'],
            'inst_ipr_programmes'         => ['ipr_programmes', 'inst_ipr_programmes'],
            'nptel'                       => ['faculty_certifications', 'nptel'],
            'online_course'               => ['faculty_certifications', 'inst_faculty_certifications'],
            'inst_faculty_certifications' => ['faculty_certifications', 'inst_faculty_certifications'],
            'mou'                         => ['mou_interactions', 'mou'],
            'inst_mou_interactions'       => ['mou_interactions', 'inst_mou_interactions'],
            'internship'                  => ['internships', 'internship'],
            'inst_internships'            => ['internships', 'internship'],
            'summer_training'             => ['summer_training', 'summer_training'],
            'inst_summer_trainings'       => ['summer_training', 'summer_training'],
            'inst_student_projects'       => ['student_projects', 'inst_student_projects'],
            'fdp'                         => ['faculty_participations', 'fdp'],
            'training'                    => ['faculty_participations', 'fdp'],
            'inst_faculty_participations' => ['faculty_participations', 'fdp'],
            'inst_society_memberships'    => ['society_memberships', 'inst_society_memberships'],
            'inst_newsletters'            => ['newsletters', 'inst_newsletters'],
            'inst_student_certifications' => ['student_certifications', 'inst_student_certifications'],
            'inst_spoken_tutorials'       => ['spoken_tutorials', 'inst_spoken_tutorials'],
            'inst_inter_inst_within'      => ['inter_institute', 'inst_inter_inst_within'],
            'inst_inter_inst_outside'     => ['inter_institute', 'inst_inter_inst_outside'],
            'inst_inter_inst_awards'      => ['inter_institute', 'inst_inter_inst_awards'],
            'co_curricular'               => ['inter_institute', 'co_curricular'],
            'extra_curricular'            => ['inter_institute', 'co_curricular'],
            'student_achievement'         => ['inter_institute', 'co_curricular'],
            'student_participation'       => ['inter_institute', 'co_curricular'],
            'value_added'                 => ['value_added', 'value_added'],
            'inst_value_added_courses'    => ['value_added', 'value_added'],
            'inst_sports_state'           => ['sports', 'inst_sports_state'],
            'inst_sports_national'        => ['sports', 'inst_sports_national'],
            'inst_innovation_events'      => ['innovation_events', 'inst_innovation_events'],
            'events'                      => ['innovation_events', 'inst_innovation_events'],
            'event'                       => ['innovation_events', 'inst_innovation_events'],
            'inst_iic_activities'         => ['iic_activities', 'inst_iic_activities'],
            'inst_website_updations'      => ['website_updations', 'inst_website_updations'],
            'inst_google_ratings'         => ['google_ratings', 'inst_google_ratings'],
            'inst_startups'               => ['startups', 'inst_startups'],
            'inst_alumni_chapters'        => ['alumni_chapters', 'inst_alumni_chapters'],
            'inst_awards_recognitions'    => ['awards_recognitions', 'inst_awards_recognitions'],
            'nss'                         => ['nss_activities', 'nss'],
            'inst_nss_events'             => ['nss_activities', 'nss'],
            'inst_pass_percentage'        => ['upercent', 'inst_pass_percentage'],
            'inst_college_rank'           => ['upercent', 'inst_pass_percentage'],
            'inst_student_rank'           => ['upercent', 'inst_pass_percentage'],
            'inst_student_cgpa'           => ['student_cgpa', 'inst_student_cgpa'],
            'inst_placement_mnc'          => ['placement_mnc', 'inst_placement_mnc'],
            'placement'                   => ['placement_mnc', 'placement'],
        ];

        if (isset($legacyMap[$typeId])) {
            [$mappedSecId, $mappedType] = $legacyMap[$typeId];
            if (isset($sections[$mappedSecId])) {
                return ['section' => $sections[$mappedSecId], 'type' => $mappedType];
            }
        }

        foreach ($sections as $sec) {
            if (in_array($typeId, $sec['types'], true)) {
                return ['section' => $sec, 'type' => $typeId];
            }
        }
    }

    // 3. Default to Section 1
    $first = reset($sections);
    return ['section' => $first, 'type' => $first['default_type']];
}

/**
 * Get next section in the exact approved order.
 */
function upload_section_next(string $currentSectionId, ?string $role = null): ?array
{
    $sections = upload_approved_sections($role);
    $keys = array_keys($sections);
    $idx = array_search($currentSectionId, $keys, true);
    if ($idx !== false && isset($keys[$idx + 1])) {
        return $sections[$keys[$idx + 1]];
    }
    return null;
}

/**
 * Get previous section in the exact approved order.
 */
function upload_section_prev(string $currentSectionId, ?string $role = null): ?array
{
    $sections = upload_approved_sections($role);
    $keys = array_keys($sections);
    $idx = array_search($currentSectionId, $keys, true);
    if ($idx !== false && $idx > 0 && isset($keys[$idx - 1])) {
        return $sections[$keys[$idx - 1]];
    }
    return null;
}

/**
 * Returns sections grouped by their institutional target category headings.
 */
function upload_sections_by_group(?string $role = null): array
{
    $grouped = [];
    foreach (upload_approved_sections($role) as $sec) {
        $grouped[$sec['group']][] = $sec;
    }
    return $grouped;
}
