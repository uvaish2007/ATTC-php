<?php
/**
 * TargetPresentation Model
 * Handles data aggregation, classification, and role summaries for the
 * complete 35-slide Institutional Academic Targets Presentation.
 */

declare(strict_types=1);

require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/helpers.php';
require_once __DIR__ . '/../inc/upload_sections.php';
require_once __DIR__ . '/Target.php';
require_once __DIR__ . '/Record.php';
require_once __DIR__ . '/Department.php';
require_once __DIR__ . '/Setting.php';

/**
 * Maps each of the 30 approved upload sections to its metric matching keywords and measurement unit.
 */
function section_metadata_map(): array
{
    static $map = null;
    if ($map !== null) {
        return $map;
    }

    $map = [
        'upercent' => [
            'unit'     => '%',
            'keywords' => ['PASS PERCENTAGE', 'UNIVERSITY PASS', 'EXAM RESULT'],
        ],
        'student_cgpa' => [
            'unit'     => 'Students',
            'keywords' => ['CGPA', '7.5 CGPA', 'II & III YEAR'],
        ],
        'placement_mnc' => [
            'unit'     => 'Placements',
            'keywords' => ['PLACEMENT', 'MNC', 'BATCH STUDENTS PLACEMENT'],
        ],
        'publications' => [
            'unit'     => 'Publications',
            'keywords' => ['PUBLICATION', 'SCOPUS', 'SCI', 'SPRINGER', 'UGC CARE', 'JOURNAL', 'INTERNATIONAL', 'CONFERENCE'],
        ],
        'books' => [
            'unit'     => 'Books / Chapters',
            'keywords' => ['BOOK', 'CHAPTER'],
        ],
        'patents' => [
            'unit'     => 'Patents / Copyrights',
            'keywords' => ['PATENT', 'COPY RIGHT', 'COPYRIGHT'],
        ],
        'sponsored_research' => [
            'unit'     => 'Lakhs (₹)',
            'keywords' => ['SPONSORED RESEARCH', 'CONSULTANCY', 'FUNDS'],
        ],
        'research_centre' => [
            'unit'     => 'Recognitions',
            'keywords' => ['RESEARCH CENTRE'],
        ],
        'ipr_programmes' => [
            'unit'     => 'Programmes',
            'keywords' => ['INTELLECTUAL PROPERTY', 'IPR', 'HIGHER STUDIES', 'ENTREPRENEURSHIP'],
        ],
        'faculty_certifications' => [
            'unit'     => 'Certifications',
            'keywords' => ['NPTEL', 'ONLINE CERTIFICATION COURSES COMPLETED BY FACULTY'],
        ],
        'mou_interactions' => [
            'unit'     => 'MoUs / Labs',
            'keywords' => ['MOU', 'INDUSTRY INTERACTION', 'INDUSTRY SUPPORTED LAB'],
        ],
        'internships' => [
            'unit'     => 'Internships',
            'keywords' => ['INTERNSHIP', '4 WEEKS'],
        ],
        'summer_training' => [
            'unit'     => 'Trainings',
            'keywords' => ['SUMMER TRAINING', 'WINTER TRAINING', 'LESS THAN 4 WEEKS'],
        ],
        'student_projects' => [
            'unit'     => 'Projects',
            'keywords' => ['STUDENT PROJECT', 'YOUTUBE'],
        ],
        'faculty_participations' => [
            'unit'     => 'Participations',
            'keywords' => ['FDP', 'FACULTY PARTICIPATION', 'STTP', 'TRAINING ACTIVITIES'],
        ],
        'society_memberships' => [
            'unit'     => 'Memberships',
            'keywords' => ['PROFESSIONAL SOCIETIES', 'SOCIETY MEMBERSHIP'],
        ],
        'newsletters' => [
            'unit'     => 'Newsletters',
            'keywords' => ['NEWSLETTER'],
        ],
        'student_certifications' => [
            'unit'     => 'Certifications',
            'keywords' => ['ONLINE CERTIFICATIONS COMPLETED BY STUDENTS', 'STUDENT ONLINE CERTIFICATION'],
        ],
        'spoken_tutorials' => [
            'unit'     => 'Students',
            'keywords' => ['SPOKEN TUTORIAL', 'IIT-BOMBAY', 'IIT-'],
        ],
        'inter_institute' => [
            'unit'     => 'Participations / Awards',
            'keywords' => ['INTER-INSTITUTE', 'WITHIN STATE', 'OUTSIDE STATE', 'AWARDS/PRIZES'],
        ],
        'value_added' => [
            'unit'     => 'Courses',
            'keywords' => ['VALUE ADDED', 'VALUE-ADDED', 'HANDS ON TRAINING'],
        ],
        'sports' => [
            'unit'     => 'Events / Awards',
            'keywords' => ['SPORTS', 'STATE LEVEL', 'NATIONAL LEVEL'],
        ],
        'innovation_events' => [
            'unit'     => 'Events',
            'keywords' => ['INNOVATION EVENT'],
        ],
        'iic_activities' => [
            'unit'     => 'Activities',
            'keywords' => ['IIC'],
        ],
        'website_updations' => [
            'unit'     => 'Updates',
            'keywords' => ['WEBSITE'],
        ],
        'google_ratings' => [
            'unit'     => 'Score (0–5)',
            'keywords' => ['GOOGLE RATING'],
        ],
        'startups' => [
            'unit'     => 'Startups',
            'keywords' => ['STARTUP', 'START UP'],
        ],
        'alumni_chapters' => [
            'unit'     => 'Chapters / Meets',
            'keywords' => ['ALUMNI'],
        ],
        'awards_recognitions' => [
            'unit'     => 'Recognitions',
            'keywords' => ['RECOGNITION', 'BOS/DC', 'QP/KEY SETTER', 'REVIEWER', 'RESOURCE PERSON', 'AWARDS & RECOGNITION', 'AWARDS'],
        ],
        'nss_activities' => [
            'unit'     => 'Activities',
            'keywords' => ['NSS', 'YRC', 'RRC'],
        ],
    ];

    return $map;
}

/**
 * Checks if a target metric string matches a specific section id.
 */
function section_matches_metric(string $metric, string $sectionId, array $sectionTypes = []): bool
{
    $m = strtoupper(trim($metric));
    if ($m === '') {
        return false;
    }

    $meta = section_metadata_map();
    if (isset($meta[$sectionId])) {
        foreach ($meta[$sectionId]['keywords'] as $kw) {
            if (strpos($m, $kw) !== false) {
                return true;
            }
        }
    }

    return false;
}

/**
 * Classifies an individual target record into presentation states:
 * - 'Achieved': Benchmark genuinely reached (achieved_value >= target_value and target_value > 0)
 * - 'Not Completed': Deadline has passed and target is incomplete
 * - 'Pending / In Progress': Active target progressing, awaiting review, or deadline in future
 */
function target_presentation_classify(array $target): string
{
    $targetVal   = (int) ($target['target_value'] ?? 0);
    $achievedVal = (int) ($target['achieved_value'] ?? 0);
    $deadline    = !empty($target['target_deadline']) ? trim((string) $target['target_deadline']) : null;
    $status      = $target['status'] ?? 'Draft';

    // 1. Targets Achieved: Target genuinely completed / met
    if ($targetVal > 0 && $achievedVal >= $targetVal) {
        return 'Achieved';
    }

    // 2. Not Completed: Demonstrably incomplete where deadline has passed
    $today = date('Y-m-d');
    if ($deadline !== null && $deadline !== '' && $deadline < $today && ($targetVal === 0 || $achievedVal < $targetVal)) {
        return 'Not Completed';
    }

    // 3. Pending / In Progress: Target is still active, progressing, or awaiting review
    return 'Pending / In Progress';
}

/**
 * Gathers the complete, authorized dataset for the Targets Presentation.
 * Always resolves against the centralized Admin-controlled Active Academic Year.
 */
function targets_presentation_dataset(array $user): array
{
    // Centralized active academic year — never overridden by client input
    $activeYear = active_academic_year();

    // Respect user scope
    $userRole   = $user['role'] ?? 'Faculty';
    $isOversight = in_array($userRole, ['Admin', 'Director', 'Principal', 'Dean'], true);
    $deptScope  = $isOversight ? null : ($user['department'] ?? null);

    // Sync achieved counts from active database records
    if (function_exists('sync_all_target_achieved')) {
        try {
            sync_all_target_achieved();
        } catch (\Exception $e) {
            // non-fatal
        }
    }

    // Fetch all targets for the active academic year
    $rawTargets = targets_all($deptScope, $activeYear);

    // Fetch all configured departments
    $configuredDepts = departments_all();
    $deptNames = array_map(fn($d) => $d['name'], $configuredDepts);

    $totalTargets       = count($rawTargets);
    $achievedTargets    = 0;
    $inProgressTargets  = 0;
    $notCompletedTargets = 0;
    $totalTargetFixed   = 0;
    $totalTargetAchieved = 0;
    $deanPendingCount   = 0;
    $draftCount         = 0;
    $approvedCount      = 0;

    $classifiedTargets = [];
    $byDepartment      = [];

    // Initialize all departments with zero stats
    foreach ($deptNames as $dName) {
        $byDepartment[$dName] = [
            'department'          => $dName,
            'targets_count'       => 0,
            'target_fixed'        => 0,
            'achieved_value'      => 0,
            'achieved_count'      => 0,
            'in_progress_count'   => 0,
            'not_completed_count' => 0,
            'attainment_pct'      => 0.0,
            'targets'             => [],
        ];
    }

    foreach ($rawTargets as $t) {
        $metric     = trim((string) ($t['metric'] ?? 'General Target'));
        $dept       = trim((string) ($t['department'] ?? 'Unassigned'));
        if ($dept === '') {
            $dept = 'Unassigned';
        }
        $targetVal   = (int) ($t['target_value'] ?? 0);
        $achievedVal = (int) ($t['achieved_value'] ?? 0);
        $status      = $t['status'] ?? 'Draft';
        $deadline    = !empty($t['target_deadline']) ? trim((string) $t['target_deadline']) : null;

        $classification = target_presentation_classify($t);

        if ($classification === 'Achieved') {
            $achievedTargets++;
        } elseif ($classification === 'Not Completed') {
            $notCompletedTargets++;
        } else {
            $inProgressTargets++;
        }

        $totalTargetFixed   += $targetVal;
        $totalTargetAchieved += $achievedVal;

        if ($status === 'Dean Pending' || $status === 'Pending Review') {
            $deanPendingCount++;
        } elseif (in_array($status, ['Draft', 'Changes Requested'], true)) {
            $draftCount++;
        } elseif ($status === 'Approved') {
            $approvedCount++;
        }

        $tItem = $t;
        $tItem['classification'] = $classification;
        $tItem['target_value']   = $targetVal;
        $tItem['achieved_value'] = $achievedVal;
        $tItem['progress_pct']   = ($targetVal > 0) ? min(100.0, round(($achievedVal / $targetVal) * 100, 1)) : ($achievedVal > 0 ? 100.0 : 0.0);
        $classifiedTargets[] = $tItem;

        // Group by Department
        if (!isset($byDepartment[$dept])) {
            $byDepartment[$dept] = [
                'department'          => $dept,
                'targets_count'       => 0,
                'target_fixed'        => 0,
                'achieved_value'      => 0,
                'achieved_count'      => 0,
                'in_progress_count'   => 0,
                'not_completed_count' => 0,
                'attainment_pct'      => 0.0,
                'targets'             => [],
            ];
        }
        $byDepartment[$dept]['targets_count']++;
        $byDepartment[$dept]['target_fixed']   += $targetVal;
        $byDepartment[$dept]['achieved_value'] += $achievedVal;
        if ($classification === 'Achieved') {
            $byDepartment[$dept]['achieved_count']++;
        } elseif ($classification === 'Not Completed') {
            $byDepartment[$dept]['not_completed_count']++;
        } else {
            $byDepartment[$dept]['in_progress_count']++;
        }
        $byDepartment[$dept]['targets'][] = $tItem;
    }

    // Compute Department Attainment %
    foreach ($byDepartment as &$d) {
        $tf = $d['target_fixed'];
        $av = $d['achieved_value'];
        $d['attainment_pct'] = ($tf > 0) ? min(100.0, round(($av / $tf) * 100, 1)) : ($av > 0 ? 100.0 : 0.0);
    }
    unset($d);

    // =========================================================================
    // BUILD THE AUTHORITATIVE 30 CONFIGURED SECTIONS (from upload_approved_sections)
    // =========================================================================
    $approvedSections = upload_approved_sections();
    $metaMap          = section_metadata_map();
    $recordTypesDef   = record_types();
    $sections30       = [];
    $totalUploadedRecords = 0;

    foreach ($approvedSections as $secId => $sec) {
        $order = (int) $sec['order'];
        $unit  = $metaMap[$secId]['unit'] ?? 'Records';

        // 1. Find matching targets in rawTargets for this section
        $secTargets = [];
        foreach ($rawTargets as $rt) {
            if (section_matches_metric((string) ($rt['metric'] ?? ''), $secId, $sec['types'])) {
                $secTargets[] = $rt;
            }
        }

        $secFixed    = 0;
        $secAchieved = 0;
        $secDeadlines = [];
        $secByDept   = [];

        foreach ($secTargets as $st) {
            $tVal = (int) ($st['target_value'] ?? 0);
            $aVal = (int) ($st['achieved_value'] ?? 0);
            $dVal = !empty($st['target_deadline']) ? trim((string) $st['target_deadline']) : null;
            $sDept = trim((string) ($st['department'] ?? 'General'));

            $secFixed += $tVal;
            $secAchieved += $aVal;
            if ($dVal && !in_array($dVal, $secDeadlines, true)) {
                $secDeadlines[] = $dVal;
            }

            if (!isset($secByDept[$sDept])) {
                $secByDept[$sDept] = [
                    'department'     => $sDept,
                    'target_fixed'   => 0,
                    'achieved_value' => 0,
                    'attainment_pct' => 0.0,
                ];
            }
            $secByDept[$sDept]['target_fixed']   += $tVal;
            $secByDept[$sDept]['achieved_value'] += $aVal;
        }

        // 2. Fetch actual approved records from database for this section's types in active year
        $uploadedRecords = [];
        foreach ($sec['types'] as $tKey) {
            if (!isset($recordTypesDef[$tKey])) {
                continue;
            }
            $tInfo = $recordTypesDef[$tKey];
            $table = $tInfo['table'];

            try {
                $cols = target_record_table_columns($table);
                if (!in_array('status', $cols, true)) {
                    continue;
                }
                $hasYear = in_array('academic_year', $cols, true);
                $hasDept = in_array('department', $cols, true);

                $sql = "SELECT r.*, u.name AS approver_name, creator.name AS creator_name
                          FROM `{$table}` r
                          LEFT JOIN users u ON r.approved_by = u.id
                          LEFT JOIN users creator ON r.created_by = creator.id
                         WHERE r.status = 'Approved'";
                $args = [];
                if ($hasYear) {
                    $sql .= " AND r.academic_year = ?";
                    $args[] = $activeYear;
                }
                if ($deptScope && $hasDept) {
                    $sql .= " AND r.department = ?";
                    $args[] = $deptScope;
                }
                $sql .= " ORDER BY r.updated_at DESC, r.id DESC LIMIT 15";

                $stmt = db()->prepare($sql);
                $stmt->execute($args);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

                foreach ($rows as $r) {
                    $uploadedRecords[] = [
                        'title'      => $r[$tInfo['title_col']] ?? ($r['title'] ?? ($r['name'] ?? 'Submitted Record')),
                        'person'     => $r['faculty_name'] ?? ($r['creator_name'] ?? ($r['student_name'] ?? 'Faculty / Submitter')),
                        'department' => $r['department'] ?? 'CSBS',
                        'type_label' => $tInfo['label'] ?? $tKey,
                        'date'       => $r['created_at'] ?? date('Y-m-d'),
                        'status'     => $r['status'] ?? 'Approved',
                    ];
                }
            } catch (\Throwable $e) {
                // Table non-existent or error
            }
        }

        $totalUploadedRecords += count($uploadedRecords);

        // If targets didn't register achieved yet, but actual approved uploaded records exist, count them
        if (count($uploadedRecords) > 0 && $secAchieved < count($uploadedRecords)) {
            $secAchieved = max($secAchieved, count($uploadedRecords));
        }

        // Compute attainment
        $pct = ($secFixed > 0)
            ? min(100.0, round(($secAchieved / $secFixed) * 100, 1))
            : ($secAchieved > 0 ? 100.0 : 0.0);

        // Compute dept attainment
        foreach ($secByDept as &$sd) {
            $stf = $sd['target_fixed'];
            $sav = $sd['achieved_value'];
            $sd['attainment_pct'] = ($stf > 0) ? min(100.0, round(($sav / $stf) * 100, 1)) : ($sav > 0 ? 100.0 : 0.0);
        }
        unset($sd);

        // Classification
        $today = date('Y-m-d');
        if ($secFixed > 0 && $secAchieved >= $secFixed) {
            $classification = 'Achieved';
        } elseif (!empty($secDeadlines) && max($secDeadlines) < $today && ($secFixed === 0 || $secAchieved < $secFixed)) {
            $classification = 'Not Completed';
        } else {
            $classification = 'Pending / In Progress';
        }

        $sections30[$order] = [
            'id'               => $secId,
            'order'            => $order,
            'title'            => $sec['title'],
            'group'            => $sec['group'],
            'description'      => $sec['description'],
            'unit'             => $unit,
            'target_fixed'     => $secFixed,
            'achieved_value'   => $secAchieved,
            'attainment_pct'   => $pct,
            'remaining'        => max(0, $secFixed - $secAchieved),
            'classification'   => $classification,
            'deadlines'        => $secDeadlines,
            'targets_count'    => count($secTargets),
            'by_department'    => $secByDept,
            'uploaded_records' => $uploadedRecords,
            'total_records'    => count($uploadedRecords),
        ];
    }

    // Overall Attainment %
    $overallAttainmentPct = ($totalTargetFixed > 0)
        ? min(100.0, round(($totalTargetAchieved / $totalTargetFixed) * 100, 1))
        : ($totalTargetAchieved > 0 ? 100.0 : 0.0);

    $targetsMetPct = ($totalTargets > 0)
        ? round(($achievedTargets / $totalTargets) * 100, 1)
        : 0.0;

    // Filter departments to only those with targets or records, sorted by attainment % DESC
    $activeDepts = array_filter($byDepartment, fn($d) => $d['targets_count'] > 0 || $d['achieved_value'] > 0);
    if (empty($activeDepts)) {
        $activeDepts = $byDepartment;
    }

    uasort($activeDepts, function($a, $b) {
        if ($b['attainment_pct'] !== $a['attainment_pct']) {
            return ($b['attainment_pct'] <=> $a['attainment_pct']);
        }
        if ($b['achieved_value'] !== $a['achieved_value']) {
            return ($b['achieved_value'] <=> $a['achieved_value']);
        }
        return ($b['targets_count'] <=> $a['targets_count']);
    });

    $topDepartments = array_slice(array_values($activeDepts), 0, 3);
    $allDepartments = array_values($activeDepts);

    // Summary statistics object
    $summary = [
        'academic_year'          => $activeYear,
        'total_targets'          => $totalTargets,
        'achieved_targets'       => $achievedTargets,
        'in_progress_targets'    => $inProgressTargets,
        'not_completed_targets'  => $notCompletedTargets,
        'total_target_fixed'     => $totalTargetFixed,
        'total_target_achieved'  => $totalTargetAchieved,
        'overall_attainment_pct' => $overallAttainmentPct,
        'targets_met_pct'        => $targetsMetPct,
        'dean_pending_count'     => $deanPendingCount,
        'draft_count'            => $draftCount,
        'approved_count'         => $approvedCount,
    ];

    // Priority Categories requiring immediate attention (attainment < 50% or incomplete)
    $attentionCategories = [];
    foreach ($sections30 as $secItem) {
        if ($secItem['targets_count'] > 0 && ($secItem['attainment_pct'] < 50.0 || $secItem['classification'] === 'Not Completed')) {
            $attentionCategories[] = [
                'title'          => $secItem['title'],
                'target_fixed'   => $secItem['target_fixed'],
                'achieved_value' => $secItem['achieved_value'],
                'attainment_pct' => $secItem['attainment_pct'],
                'not_completed'  => $secItem['classification'] === 'Not Completed' ? 1 : 0,
            ];
        }
    }

    // Role-Specific Summary 1: Admin Summary
    $adminSummary = [
        'total_targets'          => $totalTargets,
        'achieved_targets'       => $achievedTargets,
        'in_progress_targets'    => $inProgressTargets,
        'not_completed_targets'  => $notCompletedTargets,
        'total_target_fixed'     => $totalTargetFixed,
        'total_target_achieved'  => $totalTargetAchieved,
        'overall_attainment_pct' => $overallAttainmentPct,
        'attention_categories'   => $attentionCategories,
        'top_department'         => $topDepartments[0] ?? null,
        'active_cycle_open'      => true,
        'total_uploaded_records' => $totalUploadedRecords,
        'pending_target_reviews' => $deanPendingCount,
    ];

    // Role-Specific Summary 2: Dean Summary
    $deanSummary = [
        'dean_pending_reviews'   => $deanPendingCount,
        'achieved_targets'       => $achievedTargets,
        'in_progress_targets'    => $inProgressTargets,
        'not_completed_targets'  => $notCompletedTargets,
        'total_target_fixed'     => $totalTargetFixed,
        'total_target_achieved'  => $totalTargetAchieved,
        'overall_attainment_pct' => $overallAttainmentPct,
        'departments_ranking'    => $allDepartments,
        'followup_categories'    => $attentionCategories,
    ];

    // Role-Specific Summary 3: Principal Summary
    $highestCategories = [];
    foreach ($sections30 as $secItem) {
        if ($secItem['target_fixed'] > 0 || $secItem['achieved_value'] > 0) {
            $highestCategories[] = $secItem;
        }
    }
    uasort($highestCategories, fn($a, $b) => ($b['attainment_pct'] <=> $a['attainment_pct']));

    $principalSummary = [
        'total_targets'          => $totalTargets,
        'achieved_targets'       => $achievedTargets,
        'in_progress_targets'    => $inProgressTargets,
        'not_completed_targets'  => $notCompletedTargets,
        'total_target_fixed'     => $totalTargetFixed,
        'total_target_achieved'  => $totalTargetAchieved,
        'overall_attainment_pct' => $overallAttainmentPct,
        'top_performing_depts'   => $topDepartments,
        'highest_categories'     => array_slice($highestCategories, 0, 5),
        'academic_year'          => $activeYear,
    ];

    return [
        'academic_year'     => $activeYear,
        'summary'           => $summary,
        'admin_summary'     => $adminSummary,
        'dean_summary'      => $deanSummary,
        'principal_summary' => $principalSummary,
        'top_departments'   => $topDepartments,
        'all_departments'   => $allDepartments,
        'sections_30'       => $sections30,
        'categories'        => array_values($sections30),
        'targets'           => $classifiedTargets,
    ];
}

/**
 * Gathers the complete, authorized dataset for a specific Department's Target Presentation.
 * Scoped strictly to $department and the active academic year.
 */
function department_presentation_dataset(string $department, array $user): array
{
    $activeYear = active_academic_year();
    $rawTargets = targets_all($department, $activeYear);

    $totalTargets        = count($rawTargets);
    $achievedTargets     = 0;
    $inProgressTargets   = 0;
    $notCompletedTargets = 0;
    $totalTargetFixed    = 0;
    $totalTargetAchieved = 0;
    $deanPendingCount    = 0;
    $draftCount          = 0;
    $approvedCount       = 0;

    $classifiedTargets = [];

    foreach ($rawTargets as $t) {
        $targetVal   = (int) ($t['target_value'] ?? 0);
        $achievedVal = (int) ($t['achieved_value'] ?? 0);
        $status      = $t['status'] ?? 'Draft';

        $classification = target_presentation_classify($t);

        if ($classification === 'Achieved') {
            $achievedTargets++;
        } elseif ($classification === 'Not Completed') {
            $notCompletedTargets++;
        } else {
            $inProgressTargets++;
        }

        $totalTargetFixed    += $targetVal;
        $totalTargetAchieved += $achievedVal;

        if ($status === 'Dean Pending' || $status === 'Pending Review') {
            $deanPendingCount++;
        } elseif (in_array($status, ['Draft', 'Changes Requested'], true)) {
            $draftCount++;
        } elseif ($status === 'Approved') {
            $approvedCount++;
        }

        $tItem = $t;
        $tItem['classification'] = $classification;
        $tItem['target_value']   = $targetVal;
        $tItem['achieved_value'] = $achievedVal;
        $tItem['progress_pct']   = ($targetVal > 0) ? min(100.0, round(($achievedVal / $targetVal) * 100, 1)) : ($achievedVal > 0 ? 100.0 : 0.0);
        $classifiedTargets[] = $tItem;
    }

    $overallAttainmentPct = ($totalTargetFixed > 0)
        ? min(100.0, round(($totalTargetAchieved / $totalTargetFixed) * 100, 1))
        : ($totalTargetAchieved > 0 ? 100.0 : 0.0);

    $targetsMetPct = ($totalTargets > 0)
        ? round(($achievedTargets / $totalTargets) * 100, 1)
        : 0.0;

    $summary = [
        'department'             => $department,
        'academic_year'          => $activeYear,
        'total_targets'          => $totalTargets,
        'achieved_targets'       => $achievedTargets,
        'in_progress_targets'    => $inProgressTargets,
        'not_completed_targets'  => $notCompletedTargets,
        'total_target_fixed'     => $totalTargetFixed,
        'total_target_achieved'  => $totalTargetAchieved,
        'overall_attainment_pct' => $overallAttainmentPct,
        'targets_met_pct'        => $targetsMetPct,
        'remaining_target'       => max(0, $totalTargetFixed - $totalTargetAchieved),
        'dean_pending_count'     => $deanPendingCount,
        'draft_count'            => $draftCount,
        'approved_count'         => $approvedCount,
    ];

    // Faculty contributions for this department only
    $recordTypesDef = record_types();
    $facultyMap = [];
    $totalDeptUploadedRecords = 0;

    foreach ($recordTypesDef as $tKey => $tInfo) {
        $table = $tInfo['table'];
        try {
            $cols = target_record_table_columns($table);
            if (!in_array('status', $cols, true) || !in_array('department', $cols, true)) {
                continue;
            }
            $hasYear = in_array('academic_year', $cols, true);

            $sql = "SELECT r.*, u.name AS creator_name, u.email AS creator_email
                      FROM `{$table}` r
                      LEFT JOIN users u ON r.created_by = u.id
                     WHERE r.department = ? AND r.status = 'Approved'";
            $args = [$department];
            if ($hasYear) {
                $sql .= " AND r.academic_year = ?";
                $args[] = $activeYear;
            }
            $sql .= " ORDER BY r.updated_at DESC, r.id DESC";

            $stmt = db()->prepare($sql);
            $stmt->execute($args);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($rows as $r) {
                $totalDeptUploadedRecords++;
                $fName = trim((string) ($r['faculty_name'] ?? ($r['creator_name'] ?? 'Faculty Member')));
                if ($fName === '') {
                    $fName = 'Faculty Member';
                }

                if (!isset($facultyMap[$fName])) {
                    $facultyMap[$fName] = [
                        'faculty_name'   => $fName,
                        'department'     => $department,
                        'eligible_count' => 0,
                        'categories'     => [],
                        'records'        => [],
                    ];
                }
                $facultyMap[$fName]['eligible_count']++;
                $catLabel = $tInfo['label'] ?? $tKey;
                if (!in_array($catLabel, $facultyMap[$fName]['categories'], true)) {
                    $facultyMap[$fName]['categories'][] = $catLabel;
                }
                $facultyMap[$fName]['records'][] = [
                    'title'    => $r[$tInfo['title_col']] ?? ($r['title'] ?? 'Approved Record'),
                    'category' => $catLabel,
                    'date'     => $r['created_at'] ?? date('Y-m-d'),
                    'status'   => 'Approved',
                ];
            }
        } catch (\Throwable $e) {}
    }

    uasort($facultyMap, fn($a, $b) => ($b['eligible_count'] <=> $a['eligible_count']));
    $facultyContributions = array_values($facultyMap);

    // Build the 30 individual sections for this department
    $approvedSections = upload_approved_sections();
    $metaMap          = section_metadata_map();
    $sections30       = [];

    foreach ($approvedSections as $secId => $sec) {
        $order = (int) $sec['order'];
        $unit  = $metaMap[$secId]['unit'] ?? 'Records';

        // Matching targets for this department only
        $secTargets = [];
        foreach ($rawTargets as $rt) {
            if (section_matches_metric((string) ($rt['metric'] ?? ''), $secId, $sec['types'])) {
                $secTargets[] = $rt;
            }
        }

        $secFixed     = 0;
        $secAchieved  = 0;
        $secDeadlines = [];

        foreach ($secTargets as $st) {
            $tVal = (int) ($st['target_value'] ?? 0);
            $aVal = (int) ($st['achieved_value'] ?? 0);
            $dVal = !empty($st['target_deadline']) ? trim((string) $st['target_deadline']) : null;

            $secFixed    += $tVal;
            $secAchieved += $aVal;
            if ($dVal && !in_array($dVal, $secDeadlines, true)) {
                $secDeadlines[] = $dVal;
            }
        }

        // Fetch approved records from this department for this section's types
        $uploadedRecords = [];
        $secFacultyContributors = [];
        foreach ($sec['types'] as $tKey) {
            if (!isset($recordTypesDef[$tKey])) {
                continue;
            }
            $tInfo = $recordTypesDef[$tKey];
            $table = $tInfo['table'];

            try {
                $cols = target_record_table_columns($table);
                if (!in_array('status', $cols, true) || !in_array('department', $cols, true)) {
                    continue;
                }
                $hasYear = in_array('academic_year', $cols, true);

                $sql = "SELECT r.*, u.name AS creator_name
                          FROM `{$table}` r
                          LEFT JOIN users u ON r.created_by = u.id
                         WHERE r.department = ? AND r.status = 'Approved'";
                $args = [$department];
                if ($hasYear) {
                    $sql .= " AND r.academic_year = ?";
                    $args[] = $activeYear;
                }
                $sql .= " ORDER BY r.updated_at DESC, r.id DESC LIMIT 15";

                $stmt = db()->prepare($sql);
                $stmt->execute($args);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

                foreach ($rows as $r) {
                    $fac = $r['faculty_name'] ?? ($r['creator_name'] ?? ($r['student_name'] ?? 'Faculty'));
                    if (!in_array($fac, $secFacultyContributors, true)) {
                        $secFacultyContributors[] = $fac;
                    }
                    $uploadedRecords[] = [
                        'title'      => $r[$tInfo['title_col']] ?? ($r['title'] ?? 'Submitted Record'),
                        'person'     => $fac,
                        'department' => $department,
                        'type_label' => $tInfo['label'] ?? $tKey,
                        'date'       => $r['created_at'] ?? date('Y-m-d'),
                        'status'     => $r['status'] ?? 'Approved',
                    ];
                }
            } catch (\Throwable $e) {}
        }

        if (count($uploadedRecords) > 0 && $secAchieved < count($uploadedRecords)) {
            $secAchieved = max($secAchieved, count($uploadedRecords));
        }

        $pct = ($secFixed > 0)
            ? min(100.0, round(($secAchieved / $secFixed) * 100, 1))
            : ($secAchieved > 0 ? 100.0 : 0.0);

        $today = date('Y-m-d');
        if ($secFixed > 0 && $secAchieved >= $secFixed) {
            $classification = 'Achieved';
        } elseif (!empty($secDeadlines) && max($secDeadlines) < $today && ($secFixed === 0 || $secAchieved < $secFixed)) {
            $classification = 'Not Completed';
        } else {
            $classification = 'Pending / In Progress';
        }

        $sections30[$order] = [
            'id'                   => $secId,
            'order'                => $order,
            'title'                => $sec['title'],
            'group'                => $sec['group'],
            'description'          => $sec['description'],
            'unit'                 => $unit,
            'target_fixed'         => $secFixed,
            'achieved_value'       => $secAchieved,
            'attainment_pct'       => $pct,
            'remaining'            => max(0, $secFixed - $secAchieved),
            'classification'       => $classification,
            'deadlines'            => $secDeadlines,
            'targets_count'        => count($secTargets),
            'targets'              => $secTargets,
            'uploaded_records'     => $uploadedRecords,
            'total_records'        => count($uploadedRecords),
            'faculty_contributors' => $secFacultyContributors,
        ];
    }

    $attentionCategories = [];
    foreach ($sections30 as $secItem) {
        if ($secItem['targets_count'] > 0 && ($secItem['attainment_pct'] < 50.0 || $secItem['classification'] === 'Not Completed')) {
            $attentionCategories[] = [
                'title'          => $secItem['title'],
                'target_fixed'   => $secItem['target_fixed'],
                'achieved_value' => $secItem['achieved_value'],
                'attainment_pct' => $secItem['attainment_pct'],
                'unit'           => $secItem['unit'],
                'not_completed'  => $secItem['classification'] === 'Not Completed' ? 1 : 0,
            ];
        }
    }

    return [
        'department'             => $department,
        'academic_year'          => $activeYear,
        'summary'                => $summary,
        'targets'                => $classifiedTargets,
        'faculty_contributions'  => $facultyContributions,
        'sections_30'            => $sections30,
        'attention_categories'   => $attentionCategories,
        'total_uploaded_records' => $totalDeptUploadedRecords,
    ];
}
