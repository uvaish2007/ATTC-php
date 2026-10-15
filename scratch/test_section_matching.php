<?php
require_once __DIR__ . '/../php-app/inc/config.php';
require_once __DIR__ . '/../php-app/inc/db.php';
require_once __DIR__ . '/../php-app/inc/upload_sections.php';

function section_keywords_map(): array {
    return [
        'upercent'               => ['PASS PERCENTAGE', 'UNIVERSITY PASS', 'EXAM RESULT'],
        'student_cgpa'           => ['CGPA', '7.5 CGPA'],
        'placement_mnc'          => ['PLACEMENT', 'MNC'],
        'publications'           => ['PUBLICATION', 'SCOPUS', 'SCI', 'SPRINGER', 'UGC CARE', 'JOURNAL', 'INTERNATIONAL', 'CONFERENCE'],
        'books'                  => ['BOOK', 'CHAPTER'],
        'patents'                => ['PATENT', 'COPY RIGHT', 'COPYRIGHT'],
        'sponsored_research'     => ['SPONSORED RESEARCH', 'CONSULTANCY', 'FUNDS'],
        'research_centre'        => ['RESEARCH CENTRE'],
        'ipr_programmes'         => ['INTELLECTUAL PROPERTY', 'IPR', 'HIGHER STUDIES', 'ENTREPRENEURSHIP'],
        'faculty_certifications' => ['NPTEL', 'ONLINE CERTIFICATION COURSES COMPLETED BY FACULTY'],
        'mou_interactions'       => ['MOU', 'INDUSTRY INTERACTION', 'INDUSTRY SUPPORTED LAB'],
        'internships'            => ['INTERNSHIP', '4 WEEKS'],
        'summer_training'        => ['SUMMER TRAINING', 'WINTER TRAINING', 'LESS THAN 4 WEEKS'],
        'student_projects'       => ['STUDENT PROJECT', 'YOUTUBE'],
        'faculty_participations' => ['FDP', 'FACULTY PARTICIPATION', 'STTP', 'TRAINING ACTIVITIES'],
        'society_memberships'    => ['PROFESSIONAL SOCIETIES', 'SOCIETY MEMBERSHIP'],
        'newsletters'            => ['NEWSLETTER'],
        'student_certifications' => ['ONLINE CERTIFICATIONS COMPLETED BY STUDENTS', 'STUDENT ONLINE CERTIFICATION'],
        'spoken_tutorials'       => ['SPOKEN TUTORIAL', 'IIT-BOMBAY', 'IIT-'],
        'inter_institute'        => ['INTER-INSTITUTE', 'WITHIN STATE', 'OUTSIDE STATE', 'AWARDS/PRIZES'],
        'value_added'            => ['VALUE ADDED', 'VALUE-ADDED', 'HANDS ON TRAINING'],
        'sports'                 => ['SPORTS', 'STATE LEVEL', 'NATIONAL LEVEL'],
        'innovation_events'      => ['INNOVATION EVENT'],
        'iic_activities'         => ['IIC'],
        'website_updations'      => ['WEBSITE'],
        'google_ratings'         => ['GOOGLE RATING'],
        'startups'               => ['STARTUP', 'START UP'],
        'alumni_chapters'        => ['ALUMNI'],
        'awards_recognitions'    => ['RECOGNITION', 'BOS/DC', 'QP/KEY SETTER', 'REVIEWER', 'RESOURCE PERSON', 'AWARDS & RECOGNITION', 'AWARDS'],
        'nss_activities'         => ['NSS', 'YRC', 'RRC'],
    ];
}

$rows = db()->query('SELECT id, academic_year, metric FROM targets ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$sections = upload_approved_sections();
$kwMap = section_keywords_map();

$unmatched = [];
foreach ($rows as $r) {
    $m = strtoupper(trim($r['metric']));
    $matchedSec = null;
    foreach ($kwMap as $secId => $kws) {
        foreach ($kws as $kw) {
            if (strpos($m, $kw) !== false) {
                $matchedSec = $secId;
                break 2;
            }
        }
    }
    if ($matchedSec) {
        // ok
    } else {
        $unmatched[] = $r;
    }
}

echo "Total target rows: " . count($rows) . "\n";
echo "Unmatched target rows: " . count($unmatched) . "\n";
if (!empty($unmatched)) {
    print_r($unmatched);
}
