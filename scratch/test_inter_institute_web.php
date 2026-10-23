<?php
/**
 * Test web rendering for unified Inter-Institute page
 */
require_once __DIR__ . '/../php-app/inc/db.php';

class HttpSession {
    public $cookieJar = '';
    public $lastUrl = '';
    public $lastHttpCode = 0;

    public function __construct() {
        $this->cookieJar = tempnam(sys_get_temp_dir(), 'atts_cookie_');
    }

    public function request(string $url, string $method = 'GET', array $data = []): string {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_COOKIEJAR, $this->cookieJar);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $this->cookieJar);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        }

        $resp = curl_exec($ch);
        $this->lastHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $this->lastUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);

        return $resp ?: '';
    }

    public function extractCsrf(string $html): string {
        if (preg_match('/name="csrf"\s+value="([^"]+)"/', $html, $m)) {
            return $m[1];
        }
        if (preg_match('/value="([^"]+)"\s+name="csrf"/', $html, $m)) {
            return $m[1];
        }
        return '';
    }

    public function login(string $email, string $password, string $role): bool {
        $html = $this->request('http://localhost:8000/php-app/login.php');
        $csrf = $this->extractCsrf($html);
        $post = [
            'csrf' => $csrf,
            'email' => $email,
            'password' => $password,
            'role' => $role,
        ];
        $resp = $this->request('http://localhost:8000/php-app/login.php', 'POST', $post);
        return strpos($this->lastUrl, 'dashboard.php') !== false || strpos($resp, 'logout.php') !== false;
    }
}

$session = new HttpSession();
$ok = $session->login('master@atts.edu', 'master123', 'Faculty');
if (!$ok) {
    echo "Login failed!\n";
    exit(1);
}

// Fetch upload.php?section=inter_institute
$html = $session->request('http://localhost:8000/php-app/upload.php?section=inter_institute');
echo "HTTP Code: {$session->lastHttpCode}\n";
echo "Effective URL: {$session->lastUrl}\n";
echo "HTML Length: " . strlen($html) . "\n";

// Checks
$checks = [
    'Metric Title' => str_contains($html, 'Student Participation in Inter-Institute Events and Awards / Medals'),
    'Event Level: District' => str_contains($html, '<option value="District"'),
    'Event Level: State Within' => str_contains($html, '<option value="State — Within State"'),
    'Event Level: State Outside' => str_contains($html, '<option value="State — Outside State"'),
    'Event Level: National' => str_contains($html, '<option value="National"'),
    'Participation Type: Participant' => str_contains($html, '<option value="Participant"'),
    'Participation Type: Winner / First Place' => str_contains($html, '<option value="Winner / First Place"'),
    'Participation Type: Second Runner' => str_contains($html, '<option value="Second Runner"'),
    'Participation Type: Third Runner' => str_contains($html, '<option value="Third Runner"'),
    'Participation Type: Presenter' => str_contains($html, '<option value="Presenter"'),
    'Achievement Type: Medal' => str_contains($html, '<option value="Medal"'),
    'Position / Medal Label' => str_contains($html, 'Position / Medal'),
    'Certificate Label' => str_contains($html, 'Certificate') && str_contains($html, 'PDF strictly 2 MB or less'),
    'No Subcategory Tabs' => !str_contains($html, 'upload-subcategories') || !str_contains($html, 'Participation Within State'),
    'Navigation Previous' => str_contains($html, 'spoken_tutorials') || str_contains($html, 'Spoken Tutorial'),
    'Navigation Next' => str_contains($html, 'value_added') || str_contains($html, 'Value Added Courses'),
];

foreach ($checks as $name => $pass) {
    echo sprintf("[%s] %s\n", $pass ? 'PASS' : 'FAIL', $name);
}
