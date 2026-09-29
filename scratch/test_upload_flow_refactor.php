<?php
/**
 * Test upload flow refactor:
 * - Removal of FACULTY DATA card from Step 2
 * - Removal of data_type='faculty' constraint
 * - Direct access to /upload.php?type=... for Faculty and Coordinator
 */

require_once __DIR__ . '/../php-app/inc/helpers.php';
require_once __DIR__ . '/../php-app/inc/db.php';
require_once __DIR__ . '/../php-app/inc/auth.php';
require_once __DIR__ . '/../php-app/models/UploadFlow.php';

echo "=== Upload Flow Refactor Tests ===\n";

$pass = 0;
$fail = 0;

function assert_test(string $name, bool $cond, string $details = '') {
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo " [PASS] $name\n";
    } else {
        $fail++;
        echo " [FAIL] $name" . ($details ? " ($details)" : "") . "\n";
    }
}

// 1. Check UploadFlow.php definitions
$defs = upload_flow_data_types();
assert_test("FACULTY DATA is not in upload_flow_data_types()", !isset($defs['faculty']));
assert_test("STUDENT DATA is in upload_flow_data_types()", isset($defs['student']));
assert_test("INSTITUTIONAL is in upload_flow_data_types()", isset($defs['institutional']));

// 2. Check upload_flow_data_type_of()
assert_test("journal has no partition (returns null)", upload_flow_data_type_of('journal') === null);
assert_test("internship belongs to student", upload_flow_data_type_of('internship') === 'student');

// 3. Check upload_flow_choose_data_type() with faculty user
$fakeFaculty = ['id' => 999, 'role' => 'Faculty'];
$_SESSION = [];
upload_flow_store($fakeFaculty, ['year' => active_academic_year()]);
[$okFacultyStudent, $err1] = upload_flow_choose_data_type($fakeFaculty, 'student');
assert_test("Faculty user can choose student data without error", $okFacultyStudent === true, (string)$err1);

[$okFacultyInvalid, $err3] = upload_flow_choose_data_type($fakeFaculty, 'faculty');
assert_test("Faculty data type cannot be chosen since it was removed", $okFacultyInvalid === false);

// 4. Test HTTP requests against local running server (localhost:8000) using real session cookie file
class TestHttpClient {
    private string $cookieFile;

    public function __construct() {
        $this->cookieFile = tempnam(sys_get_temp_dir(), 'atts_sess_');
    }

    public function __destruct() {
        if (file_exists($this->cookieFile)) {
            @unlink($this->cookieFile);
        }
    }

    public function request(string $path, ?array $postData = null): array {
        $url = "http://127.0.0.1:8000" . $path;
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_COOKIEJAR, $this->cookieFile);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $this->cookieFile);
        if ($postData !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
        }
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $effUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        return ['code' => $code, 'url' => $effUrl, 'body' => (string)$body];
    }

    public function login(string $username, string $password, string $role): bool {
        $page = $this->request('/login.php');
        preg_match('/name="csrf"\s+value="([^"]+)"|name="csrf_token"\s+value="([^"]+)"/', $page['body'], $m);
        $csrf = $m[1] ?: ($m[2] ?? '');
        $resp = $this->request('/login.php', [
            'csrf'       => $csrf,
            'csrf_token' => $csrf,
            'email'      => $username,
            'password'   => $password,
            'role'       => $role,
        ]);
        return str_contains($resp['body'], 'Dashboard') || str_contains($resp['url'], 'dashboard.php');
    }
}

// Test Faculty session
$facClient = new TestHttpClient();
$facLogged = $facClient->login('master@atts.edu', 'master123', 'Faculty');
assert_test("Faculty master login succeeded", $facLogged);

// Test Faculty accessing /upload.php?type=journal directly
$facJournal = $facClient->request('/upload.php?type=journal');
assert_test("Faculty direct access to /upload.php?type=journal returns 200", $facJournal['code'] === 200);
assert_test("Faculty sees journal upload form", str_contains($facJournal['body'], 'Title of the Paper') || str_contains($facJournal['body'], 'Journal Name'));
assert_test("No error flash about choosing Faculty or Student Data", !str_contains($facJournal['body'], 'Please choose Faculty Data or Student Data'));
assert_test("No error flash about not part of faculty uploads", !str_contains($facJournal['body'], 'is not part of faculty uploads'));

// Test Faculty accessing /upload.php?type=internship directly
$facIntern = $facClient->request('/upload.php?type=internship');
assert_test("Faculty direct access to /upload.php?type=internship returns 200", $facIntern['code'] === 200);
assert_test("Faculty sees internship upload form without lockout", str_contains($facIntern['body'], 'Title of Internship') || str_contains($facIntern['body'], 'Student Name'));
assert_test("Faculty is not blocked on internship", !str_contains($facIntern['body'], 'is not part of faculty uploads'));

// Test Faculty accessing /upload.php without type (redirects cleanly to first form)
$facHome = $facClient->request('/upload.php');
assert_test("Faculty access to /upload.php opens upload form", $facHome['code'] === 200 && str_contains($facHome['body'], 'Upload Data'));

// Test Coordinator session
$coordClient = new TestHttpClient();
$coordLogged = $coordClient->login('master@atts.edu', 'master123', 'Coordinator');
assert_test("Coordinator master login succeeded", $coordLogged);

// Test Coordinator visiting Step 2 (upload.php?step=data-type)
// First set year so step=data-type doesn't redirect to year
$coordSetYear = $coordClient->request('/upload.php', [
    'upload_flow_step' => 'year',
    'academic_year'    => active_academic_year(),
    'csrf_token'       => (function() use ($coordClient) {
        $p = $coordClient->request('/upload.php?step=year');
        preg_match('/name="csrf_token"\s+value="([^"]+)"/', $p['body'], $m);
        return $m[1] ?? '';
    })(),
]);

$coordStep2 = $coordClient->request('/upload.php?step=data-type');
assert_test("Step 2 does NOT contain 'FACULTY DATA' choice card", !str_contains($coordStep2['body'], 'FACULTY DATA'));
assert_test("Step 2 contains 'STUDENT DATA' choice card", str_contains($coordStep2['body'], 'STUDENT DATA'));
assert_test("Step 2 contains 'INSTITUTIONAL ACHIEVEMENTS' choice card", str_contains($coordStep2['body'], 'INSTITUTIONAL ACHIEVEMENTS'));

// Test Coordinator direct access to /upload.php?type=journal
$coordDirectJournal = $coordClient->request('/upload.php?type=journal');
assert_test("Coordinator direct access to /upload.php?type=journal returns 200", $coordDirectJournal['code'] === 200);
assert_test("Coordinator sees journal form without error", str_contains($coordDirectJournal['body'], 'Title of the Paper') || str_contains($coordDirectJournal['body'], 'Journal Name'));
assert_test("No error flash for coordinator", !str_contains($coordDirectJournal['body'], 'Please choose Faculty Data or Student Data'));

// Test Admin session
$adminClient = new TestHttpClient();
$adminLogged = $adminClient->login('master@atts.edu', 'master123', 'Admin');
assert_test("Admin master login succeeded", $adminLogged);
$adminUpload = $adminClient->request('/upload.php?type=journal');
assert_test("Admin direct access to /upload.php?type=journal returns 200", $adminUpload['code'] === 200);

echo "\nSummary: $pass PASSED, $fail FAILED\n";
exit($fail > 0 ? 1 : 0);
