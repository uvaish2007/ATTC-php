<?php
/**
 * Test: Institutional upload system integration
 * Verifies: types are registered, tables exist, forms include parses,
 *           handler functions are callable.
 */
require_once __DIR__ . '/../php-app/inc/db.php';
require_once __DIR__ . '/../php-app/models/Record.php';
require_once __DIR__ . '/../php-app/models/UploadFlow.php';

$pass = 0; $fail = 0;
function check(string $name, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "[PASS] $name\n"; }
    else      { $fail++; echo "[FAIL] $name" . ($detail ? " — $detail" : '') . "\n"; }
}

echo "=== INSTITUTIONAL UPLOAD SYSTEM — INTEGRATION TEST ===\n\n";

// 1. All 39 inst_ types registered in record_types()
$types = record_types();
$instTypes = array_filter($types, fn($k) => str_starts_with($k, 'inst_'), ARRAY_FILTER_USE_KEY);
check('39 inst_ record types registered', count($instTypes) >= 39, 'Got ' . count($instTypes));

// 2. All inst_ tables exist in DB
$pdo = db();
$dbTables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
$missingTables = [];
foreach ($instTypes as $key => $def) {
    if (!in_array($def['table'], $dbTables)) {
        $missingTables[] = $def['table'];
    }
}
check('All inst_ tables exist in DB', empty($missingTables), 'Missing: ' . implode(', ', $missingTables));

// 3. inst_pass_percentage_rows child table exists
check('inst_pass_percentage_rows child table exists', in_array('inst_pass_percentage_rows', $dbTables));

// 4. Required key types present
$expectedKeys = ['inst_pass_percentage','inst_college_rank','inst_student_rank','inst_student_cgpa',
    'inst_placement_mnc','inst_publications','inst_books','inst_book_chapters',
    'inst_patents_published','inst_patents_granted','inst_copyrights','inst_sponsored_research',
    'inst_consultancy','inst_research_centre','inst_ipr_programmes','inst_faculty_certifications',
    'inst_mou_interactions','inst_internships','inst_summer_trainings','inst_student_projects',
    'inst_faculty_participations','inst_society_memberships','inst_newsletters',
    'inst_student_certifications','inst_nss_events','inst_inter_inst_within',
    'inst_inter_inst_outside','inst_inter_inst_awards','inst_value_added_courses',
    'inst_sports_state','inst_sports_national','inst_innovation_events','inst_iic_activities',
    'inst_website_updations','inst_google_ratings','inst_startups','inst_alumni_chapters',
    'inst_awards_recognitions','inst_spoken_tutorials'];
$missingKeys = array_diff($expectedKeys, array_keys($instTypes));
check('All 39 specific type keys registered', empty($missingKeys), 'Missing: ' . implode(', ', $missingKeys));

// 5. Upload flow has institutional data type
$flowDefs = upload_flow_data_types();
check('UploadFlow has institutional data type', isset($flowDefs['institutional']));
$instFlowTypes = $flowDefs['institutional']['types'] ?? [];
check('UploadFlow institutional has 39+ types', count($instFlowTypes) >= 39, 'Got ' . count($instFlowTypes));

// 6. Handler functions exist (by including the handler file)
require_once __DIR__ . '/../php-app/inc/inst_upload_handler.php';
check('inst_handle_post() function exists', function_exists('inst_handle_post'));
check('inst_handle_pass_percentage() function exists', function_exists('inst_handle_pass_percentage'));
check('inst_required_fields() function exists', function_exists('inst_required_fields'));

// 7. Required fields map covers all 39 types (minus pass_percentage which has own handler)
$reqFields = inst_required_fields();
$missingReqMap = array_diff($expectedKeys, ['inst_pass_percentage'], array_keys($reqFields));
check('Required fields map covers all non-pp types', empty($missingReqMap), 'Missing: ' . implode(', ', $missingReqMap));

// 8. DB columns match handler expectations for inst_pass_percentage
$cols = $pdo->query("SHOW COLUMNS FROM inst_pass_percentage")->fetchAll(PDO::FETCH_COLUMN);
check('inst_pass_percentage has programme column', in_array('programme', $cols));
check('inst_pass_percentage has overall_pass_percentage column', in_array('overall_pass_percentage', $cols));
check('inst_pass_percentage has total_subjects column', in_array('total_subjects', $cols));

$rowCols = $pdo->query("SHOW COLUMNS FROM inst_pass_percentage_rows")->fetchAll(PDO::FETCH_COLUMN);
check('inst_pass_percentage_rows has subject_code column', in_array('subject_code', $rowCols));
check('inst_pass_percentage_rows has pass_percentage column', in_array('pass_percentage', $rowCols));
check('inst_pass_percentage_rows has pass_status column', in_array('pass_status', $rowCols));

// 9. PHP syntax of forms file
$result = shell_exec('php -l ' . escapeshellarg(__DIR__ . '/../php-app/inc/inst_upload_forms.php') . ' 2>&1');
check('inst_upload_forms.php syntax valid', str_contains($result, 'No syntax errors'));
$result2 = shell_exec('php -l ' . escapeshellarg(__DIR__ . '/../php-app/inc/inst_upload_handler.php') . ' 2>&1');
check('inst_upload_handler.php syntax valid', str_contains($result2, 'No syntax errors'));
$result3 = shell_exec('php -l ' . escapeshellarg(__DIR__ . '/../php-app/upload.php') . ' 2>&1');
check('upload.php syntax valid', str_contains($result3, 'No syntax errors'));

// 10. approval_required flag set for all inst_ types
$noApproval = array_filter($instTypes, fn($def) => !($def['approval_required'] ?? false));
check('All inst_ types have approval_required = true', empty($noApproval), 'Without flag: ' . implode(', ', array_keys($noApproval)));

// 11. Faculty institutional types list
require_once __DIR__ . '/../php-app/models/UploadFlow.php';
$facInst = upload_flow_faculty_institutional_types();
check('Faculty institutional types list populated', count($facInst) > 0, 'Got ' . count($facInst));

echo "\n=== SUMMARY: {$pass} PASS, {$fail} FAIL ===\n";
