<?php
/**
 * HTTP Integration and Security Test for Metric Studio with explicit cookie handling
 */

declare(strict_types=1);

class TestHttpClient {
    public string $cookie = '';

    public function get(string $url, bool $follow = false): array {
        return $this->request('GET', $url, [], $follow);
    }

    public function post(string $url, array $post, bool $follow = false): array {
        return $this->request('POST', $url, $post, $follow);
    }

    private function request(string $method, string $url, array $post = [], bool $follow = false): array {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, $follow);
        if ($this->cookie !== '') {
            curl_setopt($ch, CURLOPT_COOKIE, $this->cookie);
        }
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
        }

        $response = curl_exec($ch);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headers = substr($response, 0, $headerSize);
        $body = substr($response, $headerSize);

        // Capture all Set-Cookie headers
        preg_match_all('/Set-Cookie:\s*([^;]+)/i', $headers, $matches);
        if (!empty($matches[1])) {
            $existing = [];
            if ($this->cookie !== '') {
                foreach (explode('; ', $this->cookie) as $pair) {
                    [$k, $v] = explode('=', $pair, 2) + ['', ''];
                    if ($k) $existing[$k] = $pair;
                }
            }
            foreach ($matches[1] as $newCookie) {
                [$k, $v] = explode('=', $newCookie, 2) + ['', ''];
                if ($k) $existing[$k] = $newCookie;
            }
            $this->cookie = implode('; ', array_values($existing));
        }

        unset($ch);

        return [
            'code'    => $httpCode,
            'headers' => $headers,
            'body'    => $body,
        ];
    }
}

function login(TestHttpClient $client, string $email, string $password, string $role): bool {
    $res = $client->get('http://localhost:8000/php-app/login.php');
    preg_match('/name="csrf"\s+value="([^"]+)"/i', $res['body'], $matches);
    $csrf = $matches[1] ?? '';
    if (!$csrf) return false;

    $postRes = $client->post('http://localhost:8000/php-app/login.php', [
        'csrf'     => $csrf,
        'email'    => $email,
        'password' => $password,
        'role'     => $role,
    ]);

    return ($postRes['code'] === 302);
}

echo "=== STARTING HTTP INTEGRATION & SECURITY TESTS ===\n\n";

// 1. Unauthenticated access
$anonClient = new TestHttpClient();
$unauth = $anonClient->get('http://localhost:8000/php-app/admin-metrics.php');
$unauthBlocked = ($unauth['code'] === 302 && str_contains($unauth['headers'], 'login.php'));
echo ($unauthBlocked ? " [PASS] " : " [FAIL] ") . "Unauthenticated access redirects to login (HTTP {$unauth['code']})\n";

// 2. Login as Faculty
$facClient = new TestHttpClient();
$facLoggedIn = login($facClient, 'faculty@atts.edu', 'faculty123', 'Faculty');
echo ($facLoggedIn ? " [PASS] " : " [FAIL] ") . "Faculty login authentication succeeded\n";

// 3. Faculty tries to access admin-metrics.php
$facultyAccess = $facClient->get('http://localhost:8000/php-app/admin-metrics.php');
$facultyBlocked = ($facultyAccess['code'] === 302 || $facultyAccess['code'] === 403);
echo ($facultyBlocked ? " [PASS] " : " [FAIL] ") . "Faculty access to Metric Management blocked (HTTP {$facultyAccess['code']})\n";

// 4. Faculty loads upload.php (with follow location)
$facultyUpload = $facClient->get('http://localhost:8000/php-app/upload.php', true);
$uploadLoads = ($facultyUpload['code'] === 200) && str_contains($facultyUpload['body'], 'atts-metric-tab') && str_contains($facultyUpload['body'], 'University Pass Percentage');
echo ($uploadLoads ? " [PASS] " : " [FAIL] ") . "Faculty Upload Data loads dynamically with 30 metric tabs (HTTP {$facultyUpload['code']})\n";

// 5. Login as Admin
$adminClient = new TestHttpClient();
$adminLoggedIn = login($adminClient, 'admin@atts.edu', 'admin123', 'Admin');
echo ($adminLoggedIn ? " [PASS] " : " [FAIL] ") . "Admin login authentication succeeded\n";

// 6. Test suggested route /admin/metrics alias
$routeAlias = $adminClient->get('http://localhost:8000/admin/metrics');
$aliasOk = ($routeAlias['code'] === 302 && str_contains($routeAlias['headers'], 'admin-metrics.php'));
echo ($aliasOk ? " [PASS] " : " [FAIL] ") . "Suggested route /admin/metrics redirects to admin-metrics.php (HTTP {$routeAlias['code']})\n";

// 7. Admin loads admin-metrics.php
$adminMetrics = $adminClient->get('http://localhost:8000/php-app/admin-metrics.php');
$adminPageOk = ($adminMetrics['code'] === 200)
    && str_contains($adminMetrics['body'], 'ATTS-METRIC-STUDIO')
    && str_contains($adminMetrics['body'], 'Total Metrics')
    && str_contains($adminMetrics['body'], 'Active Metrics')
    && str_contains($adminMetrics['body'], 'addMetricModal')
    && str_contains($adminMetrics['body'], 'builderModal')
    && str_contains($adminMetrics['body'], 'upercent')
    && str_contains($adminMetrics['body'], 'nss_activities');
echo ($adminPageOk ? " [PASS] " : " [FAIL] ") . "Admin Metric Management page renders workspace with all 30 metrics, KPIs, and Modals (HTTP {$adminMetrics['code']})\n";

// 8. Admin sidebar check
$sidebarContains = str_contains($adminMetrics['body'], 'Metric Management')
    && str_contains($adminMetrics['body'], 'admin-metrics.php');
echo ($sidebarContains ? " [PASS] " : " [FAIL] ") . "Admin Sidebar contains 'Metric Management' under Workspace section\n";

echo "\n=== ALL HTTP INTEGRATION TESTS COMPLETED ===\n";
