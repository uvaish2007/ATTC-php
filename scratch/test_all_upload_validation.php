<?php
/**
 * Automated test script to verify frontend and backend required-field validation
 * across all 17 Faculty Upload Data categories on upload.php.
 */

const BASE_URL = 'http://localhost:8000';
const COOKIE_FILE = __DIR__ . '/test_faculty_cookie.txt';

function http_req(string $url, string $method = 'GET', array $postData = [], array $files = []): array {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_COOKIEJAR, COOKIE_FILE);
    curl_setopt($ch, CURLOPT_COOKIEFILE, COOKIE_FILE);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if (!empty($files)) {
            // Multipart form data
            $payload = $postData;
            foreach ($files as $field => $filePath) {
                if ($filePath && file_exists($filePath)) {
                    $payload[$field] = new CURLFile($filePath, 'application/pdf', basename($filePath));
                }
            }
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        } else {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
        }
    }

    $response = curl_exec($ch);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $header = substr($response, 0, $headerSize);
    $body = substr($response, $headerSize);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ['code' => $httpCode, 'header' => $header, 'body' => $body];
}

// Create dummy proof PDF file for valid submissions
$dummyPdf = __DIR__ . '/test_proof.pdf';
if (!file_exists($dummyPdf)) {
    file_put_contents($dummyPdf, "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj 2 0 obj<</Type/Pages/Count 1/Kids[3 0 R]>>endobj 3 0 obj<</Type/Page/MediaBox[0 0 612 792]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF");
}

echo "=== 1. Logging in as Faculty ===\n";
$res = http_req(BASE_URL . '/login.php');
preg_match('/name="csrf"\s+value="([^"]+)"/', $res['body'], $matches);
$csrfToken = $matches[1] ?? '';

$loginRes = http_req(BASE_URL . '/login.php', 'POST', [
    'csrf' => $csrfToken,
    'email' => 'faculty@atts.edu',
    'password' => 'faculty123',
    'role' => 'Faculty',
]);

// Fetch upload.php to verify logged in session
$uploadCheck = http_req(BASE_URL . '/upload.php');
if (strpos($uploadCheck['body'], 'Upload Data') === false) {
    preg_match('/<div class="alert alert-danger[^"]*">(.*?)<\/div>/s', $loginRes['body'], $errMatch);
    echo "Login Error: " . ($errMatch[1] ?? 'Unknown error') . "\n";
    die("Login failed! Could not access upload.php\n");
}
echo "Login successful!\n\n";

// Get fresh CSRF token from upload.php
$uploadPage = http_req(BASE_URL . '/upload.php');
preg_match('/name="csrf"\s+value="([^"]+)"/', $uploadPage['body'], $matches);
$csrfToken = $matches[1] ?? '';

$categories = [
    'journal', 'book', 'conference', 'patent', 'fdp',
    'mou', 'event', 'nptel', 'internship', 'placement',
    'nss', 'online_course', 'student_achievement', 'student_participation',
    'summer_training', 'value_added', 'training'
];

echo "=== 2. Testing Empty Form Submission (Backend Validation Test) ===\n";
foreach ($categories as $cat) {
    $res = http_req(BASE_URL . '/upload.php', 'POST', [
        'csrf' => $csrfToken,
        'record_type' => $cat,
        'nav' => 'submit'
    ]);
    
    // Check if flash error contains required messages
    if (strpos($res['body'], 'is required.') !== false || strpos($res['body'], 'Proof / Attachment is required') !== false) {
        echo "[PASS] Empty submission for '{$cat}' correctly blocked by backend validation.\n";
    } else {
        echo "[FAIL] Empty submission for '{$cat}' was NOT blocked!\n";
    }
}

echo "\n=== 3. Testing Missing File Attachment Test ===\n";
$res = http_req(BASE_URL . '/upload.php', 'POST', [
    'csrf' => $csrfToken,
    'record_type' => 'journal',
    'faculty_name' => 'Dr. Test Faculty',
    'department' => 'CSE',
    'academic_year' => '2025-2026',
    'author_type' => 'Author-1',
    'co_authors' => 'Dr. Co-author',
    'paper_title' => 'Deep Learning in Software Testing',
    'journal_name' => 'IEEE Transactions',
    'journal_type' => 'SCI',
    'issn' => '1234-5678',
    'volume_issue' => 'Vol. 10, Issue 2',
    'publication_month' => '05/2025',
    'doi' => 'https://doi.org/10.1109/test.2025',
    'journal_link' => 'https://ieee.org',
    'document_link' => 'https://msec.edu/doc.pdf',
    'nav' => 'submit'
]);
if (strpos($res['body'], 'Proof / Attachment is required') !== false) {
    echo "[PASS] Submission without proof attachment was correctly blocked.\n";
} else {
    echo "[FAIL] Submission without proof attachment was accepted!\n";
}

echo "\n=== 4. Testing Invalid URL Validation Test ===\n";
$res = http_req(BASE_URL . '/upload.php', 'POST', [
    'csrf' => $csrfToken,
    'record_type' => 'journal',
    'faculty_name' => 'Dr. Test Faculty',
    'department' => 'CSE',
    'academic_year' => '2025-2026',
    'author_type' => 'Author-1',
    'co_authors' => 'Dr. Co-author',
    'paper_title' => 'Deep Learning in Software Testing',
    'journal_name' => 'IEEE Transactions',
    'journal_type' => 'SCI',
    'issn' => '1234-5678',
    'volume_issue' => 'Vol. 10, Issue 2',
    'publication_month' => '05/2025',
    'doi' => 'invalid_url_without_http',
    'journal_link' => 'https://ieee.org',
    'document_link' => 'https://msec.edu/doc.pdf',
    'nav' => 'submit'
], ['proof' => $dummyPdf]);
if (strpos($res['body'], 'must be a valid URL') !== false) {
    echo "[PASS] Invalid URL correctly rejected by backend validation.\n";
} else {
    echo "[FAIL] Invalid URL was accepted!\n";
}

echo "\n=== 5. Testing Valid Full Submissions for All 17 Categories ===\n";

$validData = [
    'journal' => [
        'faculty_name' => 'Dr. Test Faculty',
        'department' => 'CSE',
        'academic_year' => '2025-2026',
        'author_type' => 'Author-1',
        'co_authors' => 'Dr. Co-author',
        'paper_title' => 'AI Applications in Education',
        'journal_name' => 'Journal of AI Research',
        'journal_type' => 'Scopus',
        'issn' => '9876-5432',
        'volume_issue' => 'Vol 5, Issue 1',
        'publication_month' => '06/2025',
        'doi' => 'https://doi.org/10.1016/j.jair.2025.01',
        'journal_link' => 'https://jair.org',
        'document_link' => 'https://jair.org/article.pdf',
    ],
    'book' => [
        'faculty_name' => 'Dr. Test Faculty',
        'department' => 'CSE',
        'academic_year' => '2025-2026',
        'publication_category' => 'Book',
        'title' => 'Advanced Cloud Computing',
        'publisher_name' => 'Springer Nature',
        'isbn' => '978-3-16-148410-0',
        'publication_month' => '02/2026',
        'document_link' => 'https://springer.com/book',
    ],
    'conference' => [
        'faculty_name' => 'Dr. Test Faculty',
        'department' => 'CSE',
        'academic_year' => '2025-2026',
        'author_type' => 'Author-1',
        'paper_title' => 'Cybersecurity in Smart Grids',
        'conference_name' => 'International Conference on Cyber Systems',
        'conference_type' => 'International',
        'venue' => 'MSEC Chennai',
        'conference_date' => '2026-03-15',
    ],
    'patent' => [
        'faculty_name' => 'Dr. Test Faculty',
        'department' => 'CSE',
        'academic_year' => '2025-2026',
        'category' => 'Patent',
        'title' => 'IoT Based Agricultural Sensor Node',
        'patent_number' => 'PAT202610099',
        'publication_date' => '2026-04-10',
        'document_link' => 'https://ipindia.gov.in/patent/202610099',
    ],
    'fdp' => [
        'faculty_name' => 'Dr. Test Faculty',
        'department' => 'CSE',
        'academic_year' => '2025-2026',
        'duration' => '5 days',
        'event_type' => 'FDP',
        'title' => 'Recent Trends in Data Analytics',
        'mode' => 'Offline',
        'organized_by' => 'IIT Madras',
        'from_date' => '2026-01-10',
        'to_date' => '2026-01-14',
        'certificate_link' => 'https://nptel.ac.in/cert/12345',
    ],
    'mou' => [
        'department' => 'CSE',
        'signed_date' => '2026-01-01',
        'organization' => 'TCS Innovation Labs',
        'valid_upto' => '2029-01-01',
        'purpose' => 'Joint Research and Student Training',
        'document_link' => 'https://msec.edu/mou/tcs.pdf',
    ],
    'event' => [
        'department' => 'CSE',
        'event_date' => '2026-02-20',
        'event_title' => 'National Web Tech Symposium',
        'event_type' => 'Symposium',
        'mode' => 'Offline',
        'resource_person' => 'Dr. Tech Lead, Google',
        'participants' => '150',
        'sponsorship' => 'DST (Rs 50,000)',
        'report_link' => 'https://msec.edu/events/symposium.pdf',
    ],
    'nptel' => [
        'department' => 'CSE',
        'candidate_name' => 'Dr. Test Faculty',
        'category' => 'Faculty',
        'course_title' => 'Deep Learning for Computer Vision',
        'session' => 'Jul 2025 - Oct 2025',
        'grade' => 'Elite + Gold',
        'certificate_link' => 'https://nptel.ac.in/noc/Ecert/101',
    ],
    'internship' => [
        'reg_no' => '711322104001',
        'student_name' => 'Aravind Kumar',
        'department' => 'CSE',
        'title' => 'Full Stack Web Development Internship',
        'industry' => 'Zoho Corporation, Chennai',
        'duration' => '3 months',
        'days' => '90',
        'certificate_link' => 'https://zoho.com/cert/9001',
    ],
    'placement' => [
        'reg_no' => '711322104002',
        'student_name' => 'Bala Subramanian',
        'department' => 'CSE',
        'job_title' => 'Software Development Engineer',
        'mode' => 'On Campus',
        'company' => 'Cognizant Technology Solutions',
        'pay_scale' => '6.5 LPA',
        'appointment_order_link' => 'https://cts.com/offers/2026/002',
    ],
    'nss' => [
        'department' => 'CSE',
        'academic_year' => '2025-2026',
        'activity_date' => '2026-01-26',
        'activity_type' => 'NSS',
        'activity_name' => 'Blood Donation Camp',
        'venue' => 'MSEC Auditorium',
        'participants' => '200',
        'external_agency' => 'Red Cross Society',
        'report_link' => 'https://msec.edu/nss/blood_camp.pdf',
    ],
    'online_course' => [
        'department' => 'CSE',
        'academic_year' => '2025-2026',
        'candidate_name' => 'Dr. Test Faculty',
        'category' => 'Faculty',
        'course_title' => 'Machine Learning Specialization',
        'provider' => 'Coursera',
        'duration' => '3 months',
        'month_year' => '11/2025',
        'certificate_link' => 'https://coursera.org/verify/ML123',
    ],
    'student_achievement' => [
        'department' => 'CSE',
        'academic_year' => '2025-2026',
        'reg_no' => '711322104003',
        'student_name' => 'Chandran R',
        'event_type' => 'Hackathon',
        'event_name' => 'Smart India Hackathon 2025',
        'function_name' => 'Grand Finale',
        'event_date' => '2025-12-20',
        'team_individual' => 'Team',
        'level_secured' => 'National',
        'position_secured' => 'First Prize (1 Lakh)',
        'organising_institution' => 'AICTE / MoE',
        'certificate_link' => 'https://sih.gov.in/cert/winner01',
    ],
    'student_participation' => [
        'department' => 'CSE',
        'academic_year' => '2025-2026',
        'activity_category' => 'Co-curricular',
        'reg_no' => '711322104004',
        'student_name' => 'Deepak S',
        'event_type' => 'Paper Presentation',
        'event_name' => 'TechFest 2026',
        'function_name' => 'National Level Symposium',
        'event_date' => '2026-02-10',
        'team_individual' => 'Individual',
        'level_secured' => 'State',
        'position_secured' => 'Participant',
        'organising_institution' => 'Anna University',
        'certificate_link' => 'https://annauniv.edu/techfest/cert04',
    ],
    'summer_training' => [
        'department' => 'CSE',
        'academic_year' => '2025-2026',
        'reg_no' => '711322104005',
        'student_name' => 'Elango M',
        'title' => 'Embedded Systems & IoT Hands-on',
        'industry' => 'L&T Technology Services',
        'duration' => '2 weeks',
        'days' => '14',
        'certificate_link' => 'https://ltts.com/training/cert05',
    ],
    'value_added' => [
        'department' => 'CSE',
        'academic_year' => '2025-2026',
        'from_date' => '2026-02-01',
        'to_date' => '2026-02-05',
        'course_title' => 'Ethical Hacking & Network Defense',
        'mode' => 'Offline',
        'participants' => '60',
        'resource_person' => 'Mr. Security Consultant, EC-Council',
        'report_link' => 'https://msec.edu/vac/hacking.pdf',
    ],
    'training' => [
        'department' => 'CSE',
        'academic_year' => '2025-2026',
        'event_date' => '2026-03-01',
        'event_title' => 'Career Guidance for Higher Studies',
        'event_type' => 'Career Guidance',
        'mode' => 'Hybrid',
        'participants' => '120',
        'sponsorship' => 'Alumni Association',
        'resource_person' => 'Dr. Overseas Advisor',
        'report_link' => 'https://msec.edu/training/higher_ed.pdf',
    ],
];

foreach ($validData as $cat => $fields) {
    $postData = array_merge([
        'csrf' => $csrfToken,
        'record_type' => $cat,
        'nav' => 'submit'
    ], $fields);

    $res = http_req(BASE_URL . '/upload.php', 'POST', $postData, ['proof' => $dummyPdf]);
    if (strpos($res['body'], 'submitted for review') !== false) {
        echo "[PASS] Category '{$cat}' submitted successfully!\n";
    } else {
        echo "[FAIL] Category '{$cat}' submission failed!\n";
        preg_match_all('/<div class="alert alert-danger[^"]*">(.*?)<\/div>/s', $res['body'], $m);
        if (!empty($m[1])) {
            echo "       Error: " . strip_tags(implode("\n", $m[1])) . "\n";
        }
    }
}

echo "\n=== ALL VALIDATION TESTS COMPLETED ===\n";
