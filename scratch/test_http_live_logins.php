<?php
$base = 'http://localhost:8000';

function test_http_login(string $role, string $email, string $password, string $label): void {
    global $base;
    $cookieF = tempnam(sys_get_temp_dir(), 'httplog_');

    // 1. GET login page to get CSRF
    $ch = curl_init("$base/login.php");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEFILE     => $cookieF,
        CURLOPT_COOKIEJAR      => $cookieF,
        CURLOPT_TIMEOUT        => 10,
    ]);
    $body = curl_exec($ch);
    curl_close($ch);

    preg_match('/name="csrf"[^>]*value="([^"]+)"/', $body, $cm);
    if (empty($cm[1])) {
        preg_match('/value="([^"]+)"[^>]*name="csrf"/', $body, $cm);
    }
    $csrf = $cm[1] ?? '';

    // 2. POST login
    $ch = curl_init("$base/login.php");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'csrf'     => $csrf,
            'role'     => $role,
            'email'    => $email,
            'password' => $password,
        ]),
        CURLOPT_COOKIEFILE     => $cookieF,
        CURLOPT_COOKIEJAR      => $cookieF,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HEADER         => true,
        CURLOPT_TIMEOUT        => 10,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $is302 = ($code === 302);

    // Follow to check dashboard
    $ch = curl_init("$base/dashboard.php");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEFILE     => $cookieF,
        CURLOPT_COOKIEJAR      => $cookieF,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 10,
    ]);
    $dashBody = curl_exec($ch);
    $dashUrl  = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);

    $logged = !str_contains($dashUrl, 'login.php') && str_contains($dashBody, 'Logout');

    if ($logged) {
        echo "[PASS] {$label} -> HTTP {$code} -> Dashboard OK\n";
    } else {
        echo "[FAIL] {$label} -> HTTP {$code} -> redirect to {$dashUrl}\n";
    }

    @unlink($cookieF);
}

echo "HTTP LIVE LOGIN TESTS:\n\n";

test_http_login('Admin', 'mohameduvaish132@gmail.com', 'uvaish123', 'Admin: mohameduvaish132@gmail.com / uvaish123');
test_http_login('Principal', 'director@atts.edu', 'director123', 'Director: director@atts.edu / director123');
test_http_login('Dean', 'dean@atts.edu', 'dean1234', 'Dean: dean@atts.edu / dean1234');
test_http_login('HoD', 'hod@atts.edu', 'hod12345', 'HoD: hod@atts.edu / hod12345');
test_http_login('Coordinator', 'coordinator@atts.edu', 'coord1234', 'Coordinator: coordinator@atts.edu / coord1234');
test_http_login('Faculty', 'faculty@atts.edu', 'faculty12', 'Faculty: faculty@atts.edu / faculty12');
test_http_login('Admin', 'coordinator@atts.edu', 'coord1234', 'Cross-role: Clicked Admin, entered coordinator@atts.edu / coord1234');
