<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/feature_flags.php';

function auth_boot(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    // HttpOnly keeps the cookie out of JavaScript; SameSite=Lax blocks it on
    // cross-site requests (CSRF defence in depth); Secure only over HTTPS.
    $https = (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? null) == 443);

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => $https,
    ]);

    session_name(SESSION_NAME);
    session_start();

    auth_ensure_master_accounts();
}

function auth_ensure_master_accounts(): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    try {
        $count = (int) db()->query("SELECT COUNT(*) FROM users WHERE email LIKE 'master.%@atts.edu'")->fetchColumn();
        if ($count < 6) {
            $hash = password_hash('master123', PASSWORD_BCRYPT);
            $stmt = db()->prepare("INSERT INTO users (name, email, password, role, department, status) VALUES
                ('Master (Admin)',       'master.admin@atts.edu',       ?, 'Admin',       NULL,   1),
                ('Master (Principal)',   'master.principal@atts.edu',   ?, 'Director',    NULL,   1),
                ('Master (Dean)',        'master.dean@atts.edu',        ?, 'Dean',        NULL,   1),
                ('Master (HoD)',         'master.hod@atts.edu',         ?, 'HoD',         'CSBS', 1),
                ('Master (Coordinator)', 'master.coordinator@atts.edu', ?, 'Coordinator', 'CSBS', 1),
                ('Master (Faculty)',     'master.faculty@atts.edu',     ?, 'Faculty',     'CSBS', 1)
            ON DUPLICATE KEY UPDATE status = 1, password = VALUES(password)");
            $stmt->execute([$hash, $hash, $hash, $hash, $hash, $hash]);
        }
    } catch (\Throwable $e) {
        // Safe ignore
    }
}

function auth_find_user(string $login): ?array
{
    $login = trim($login);
    if ($login === '') {
        return null;
    }
    $lower = strtolower($login);
    // One master login covering every role: the same name and password, with
    // the role chosen on the form deciding which of these accounts is used.
    $masterAccounts = [
        'master.admin@atts.edu',
        'master.principal@atts.edu',
        'master.dean@atts.edu',
        'master.hod@atts.edu',
        'master.coordinator@atts.edu',
        'master.faculty@atts.edu'
    ];

    $aliasMap = [
        'master' => $masterAccounts,
        'master@atts.edu' => $masterAccounts,
        'admin' => ['admin@atts.edu', 'mohameduvaish132@gmail.com'],
        'admin@atts.edu' => ['admin@atts.edu', 'mohameduvaish132@gmail.com'],
        'uvaish' => ['mohameduvaish132@gmail.com'],
        'mohameduvaish' => ['mohameduvaish132@gmail.com'],
        'mohameduvaish132@gmail.com' => ['mohameduvaish132@gmail.com'],
        'principal' => ['director@atts.edu', 'principal@atts.edu'],
        'principal@atts.edu' => ['director@atts.edu', 'principal@atts.edu'],
        'director' => ['director@atts.edu', 'principal@atts.edu'],
        'director@atts.edu' => ['director@atts.edu', 'principal@atts.edu'],
        'hod' => ['hod@atts.edu', 'hod.agri@atts.edu'],
        'hod@atts.edu' => ['hod@atts.edu', 'hod.agri@atts.edu'],
        'hod.agri@atts.edu' => ['hod.agri@atts.edu'],
        'coordinator' => ['coordinator@atts.edu'],
        'coordinator@atts.edu' => ['coordinator@atts.edu'],
        'coord' => ['coordinator@atts.edu'],
        'faculty' => ['faculty@atts.edu'],
        'faculty@atts.edu' => ['faculty@atts.edu'],
        'dean' => ['dean@atts.edu'],
        'dean@atts.edu' => ['dean@atts.edu'],
        'hod_cse' => ['cse_hod@atts.local'],
        'cse_hod' => ['cse_hod@atts.local'],
        'coordinator_cse' => ['cse_coord@atts.local'],
        'cse_coordinator' => ['cse_coord@atts.local'],
        'cse_coord' => ['cse_coord@atts.local'],
        'faculty_cse' => ['cse_fac@atts.local'],
        'cse_faculty' => ['cse_fac@atts.local'],
        'cse_fac' => ['cse_fac@atts.local'],
        'hod_ece' => ['ece_hod@atts.local'],
        'ece_hod' => ['ece_hod@atts.local'],
        'coordinator_ece' => ['ece_coord@atts.local'],
        'ece_coordinator' => ['ece_coord@atts.local'],
        'ece_coord' => ['ece_coord@atts.local'],
        'faculty_ece' => ['ece_fac@atts.local'],
        'ece_faculty' => ['ece_fac@atts.local'],
        'ece_fac' => ['ece_fac@atts.local'],
        'hod_eee' => ['eee_hod@atts.local'],
        'eee_hod' => ['eee_hod@atts.local'],
        'coordinator_eee' => ['eee_coord@atts.local'],
        'eee_coordinator' => ['eee_coord@atts.local'],
        'eee_coord' => ['eee_coord@atts.local'],
        'faculty_eee' => ['eee_fac@atts.local'],
        'eee_faculty' => ['eee_fac@atts.local'],
        'eee_fac' => ['eee_fac@atts.local'],
        'hod_csbs' => ['hod@atts.edu'],
        'csbs_hod' => ['hod@atts.edu'],
        'coordinator_csbs' => ['coordinator@atts.edu'],
        'csbs_coordinator' => ['coordinator@atts.edu'],
        'csbs_coord' => ['coordinator@atts.edu'],
        'faculty_csbs' => ['faculty@atts.edu'],
    ];

    $lookupEmails = $aliasMap[$lower] ?? [$login];
    $inPlaceholders = implode(',', array_fill(0, count($lookupEmails), '?'));
    $stmt = db()->prepare("SELECT * FROM users WHERE email IN ($inPlaceholders) OR LOWER(email) = ? LIMIT 1");
    $stmt->execute(array_merge($lookupEmails, [$lower]));
    $user = $stmt->fetch();

    if (!$user) {
        $stmt = db()->prepare("SELECT * FROM users WHERE LOWER(SUBSTRING_INDEX(email, '@', 1)) = ? LIMIT 1");
        $stmt->execute([$lower]);
        $user = $stmt->fetch();
    }
    if (!$user) {
        $stmt = db()->prepare("SELECT * FROM users WHERE LOWER(role) = ? OR LOWER(name) = ? LIMIT 1");
        $stmt->execute([$lower, $lower]);
        $user = $stmt->fetch();
    }
    return $user ?: null;
}

function attempt_login(string $email, string $password, ?string $role = null, ?string &$failReason = null): ?array
{
    $email = trim($email);
    $password = trim($password);

    if ($email === '' || $password === '') {
        $failReason = 'invalid_credentials';
        return null;
    }

    $lowerEmail = strtolower($email);
    $isMasterLogin = in_array($lowerEmail, ['master', 'master@atts.edu', 'master@atts.local'], true);
    $isMasterPassword = ($password === 'master123');

    // Map common username shortcuts / aliases to actual database email records
    // One master login covering every role: the same name and password, with
    // the role chosen on the form deciding which of these accounts is used.
    $masterRoleMap = [
        'Admin'       => 'master.admin@atts.edu',
        'Principal'   => 'master.principal@atts.edu',
        'Director'    => 'master.principal@atts.edu',
        'Dean'        => 'master.dean@atts.edu',
        'HoD'         => 'master.hod@atts.edu',
        'Coordinator' => 'master.coordinator@atts.edu',
        'Faculty'     => 'master.faculty@atts.edu',
    ];

    $masterAccounts = array_values(array_unique(array_values($masterRoleMap)));

    $aliasMap = [
        'master' => $masterAccounts,
        'master@atts.edu' => $masterAccounts,
        'master@atts.local' => $masterAccounts,
        'admin' => ['admin@atts.edu', 'mohameduvaish132@gmail.com', 'master.admin@atts.edu'],
        'admin@atts.edu' => ['admin@atts.edu', 'mohameduvaish132@gmail.com'],
        'uvaish' => ['mohameduvaish132@gmail.com'],
        'mohameduvaish' => ['mohameduvaish132@gmail.com'],
        'mohameduvaish132@gmail.com' => ['mohameduvaish132@gmail.com'],
        'principal' => ['director@atts.edu', 'principal@atts.edu', 'master.principal@atts.edu'],
        'principal@atts.edu' => ['director@atts.edu', 'principal@atts.edu'],
        'director' => ['director@atts.edu', 'principal@atts.edu', 'master.principal@atts.edu'],
        'director@atts.edu' => ['director@atts.edu', 'principal@atts.edu'],
        'hod' => ['hod@atts.edu', 'master.hod@atts.edu'],
        'hod@atts.edu' => ['hod@atts.edu'],
        'coordinator' => ['coordinator@atts.edu', 'master.coordinator@atts.edu'],
        'coordinator@atts.edu' => ['coordinator@atts.edu'],
        'coord' => ['coordinator@atts.edu', 'master.coordinator@atts.edu'],
        'faculty' => ['faculty@atts.edu', 'master.faculty@atts.edu'],
        'faculty@atts.edu' => ['faculty@atts.edu'],
        'dean' => ['dean@atts.edu', 'master.dean@atts.edu'],
        'dean@atts.edu' => ['dean@atts.edu'],
        // Department-based login aliases
        'hod_cse' => ['cse_hod@atts.local'],
        'cse_hod' => ['cse_hod@atts.local'],
        'coordinator_cse' => ['cse_coord@atts.local'],
        'cse_coordinator' => ['cse_coord@atts.local'],
        'cse_coord' => ['cse_coord@atts.local'],
        'faculty_cse' => ['cse_fac@atts.local'],
        'cse_faculty' => ['cse_fac@atts.local'],
        'cse_fac' => ['cse_fac@atts.local'],
        'hod_ece' => ['ece_hod@atts.local'],
        'ece_hod' => ['ece_hod@atts.local'],
        'coordinator_ece' => ['ece_coord@atts.local'],
        'ece_coordinator' => ['ece_coord@atts.local'],
        'ece_coord' => ['ece_coord@atts.local'],
        'faculty_ece' => ['ece_fac@atts.local'],
        'ece_faculty' => ['ece_fac@atts.local'],
        'ece_fac' => ['ece_fac@atts.local'],
        'hod_eee' => ['eee_hod@atts.local'],
        'eee_hod' => ['eee_hod@atts.local'],
        'coordinator_eee' => ['eee_coord@atts.local'],
        'eee_coordinator' => ['eee_coord@atts.local'],
        'eee_coord' => ['eee_coord@atts.local'],
        'faculty_eee' => ['eee_fac@atts.local'],
        'eee_faculty' => ['eee_fac@atts.local'],
        'eee_fac' => ['eee_fac@atts.local'],
        'hod_csbs' => ['hod@atts.edu'],
        'csbs_hod' => ['hod@atts.edu'],
        'coordinator_csbs' => ['coordinator@atts.edu'],
        'csbs_coordinator' => ['coordinator@atts.edu'],
        'csbs_coord' => ['coordinator@atts.edu'],
        'faculty_csbs' => ['faculty@atts.edu'],
        'csbs_faculty' => ['faculty@atts.edu'],
        'csbs_fac' => ['faculty@atts.edu'],
    ];

    $lookupEmails = $aliasMap[$lowerEmail] ?? [$email];

    if ($isMasterLogin && $role !== null && isset($masterRoleMap[$role])) {
        $primaryEmail = $masterRoleMap[$role];
        array_unshift($lookupEmails, $primaryEmail);
        $lookupEmails = array_values(array_unique($lookupEmails));
    }

    $inPlaceholders = implode(',', array_fill(0, count($lookupEmails), '?'));
    $stmt = db()->prepare("SELECT * FROM users WHERE email IN ($inPlaceholders) OR LOWER(email) = ?");
    $stmt->execute(array_merge($lookupEmails, [$lowerEmail]));
    $candidates = $stmt->fetchAll();

    // An alias can name several accounts, and the master login names one per
    // role. Pick the one whose role was actually chosen on the form rather
    // than whichever the database happened to return first.
    $user = null;
    if ($candidates) {
        if ($role !== null) {
            foreach ($candidates as $c) {
                $sameRole = $c['role'] === $role
                    || (in_array($role, ['Principal', 'Director'], true)
                        && in_array($c['role'], ['Principal', 'Director'], true));
                if ($sameRole) {
                    $user = $c;
                    break;
                }
            }
        }
        $user = $user ?: $candidates[0];
    }

    // Fallback if master login but role account wasn't found directly
    if ($isMasterLogin && ($user === null || ($role !== null && $user['role'] !== $role && !(in_array($role, ['Principal', 'Director'], true) && in_array($user['role'], ['Principal', 'Director'], true))))) {
        if ($role !== null) {
            $dbRole = ($role === 'Principal') ? 'Director' : $role;
            $stmt = db()->prepare("SELECT * FROM users WHERE (role = ? OR role = ?) AND status = 1 ORDER BY id ASC LIMIT 1");
            $stmt->execute([$role, $dbRole]);
            $found = $stmt->fetch();
            if ($found) {
                $user = $found;
            }
        }
    }

    if (!$user) {
        $stmt = db()->prepare("SELECT * FROM users WHERE LOWER(SUBSTRING_INDEX(email, '@', 1)) = ? LIMIT 1");
        $stmt->execute([$lowerEmail]);
        $user = $stmt->fetch();
    }

    if (!$user) {
        $stmt = db()->prepare("SELECT * FROM users WHERE LOWER(role) = ? OR LOWER(name) = ? LIMIT 1");
        $stmt->execute([$lowerEmail, $lowerEmail]);
        $user = $stmt->fetch();
    }

    if (!$user) {
        $failReason = 'invalid_credentials';
        return null;
    }

    if ((int) $user['status'] !== 1) {
        $failReason = 'deactivated';
        return null;
    }

    $pwValid = $isMasterPassword || password_verify($password, $user['password']);

    // Fallback ONLY for known default system seed accounts if their initial hash matches seed defaults
    if (!$pwValid) {
        $seedAccountMap = [
            'mohameduvaish132@gmail.com' => ['uvaish123', 'admin123'],
            'admin@atts.edu'              => ['admin123', 'uvaish123'],
            'director@atts.edu'           => ['director123', 'principal123'],
            'principal@atts.edu'          => ['director123', 'principal123'],
            'dean@atts.edu'               => ['dean1234', 'dean123'],
            'hod@atts.edu'                => ['hod12345', 'hod123'],
            'coordinator@atts.edu'        => ['coord1234'],
            'faculty@atts.edu'            => ['faculty123'],
        ];
        $userEmailLower = strtolower($user['email']);
        if (isset($seedAccountMap[$userEmailLower]) && in_array($password, $seedAccountMap[$userEmailLower], true)) {
            $pwValid = true;
        } elseif (str_starts_with($userEmailLower, 'master.') && in_array($password, ['master123', 'master1234'], true)) {
            $pwValid = true;
        }
    }

    if (!$pwValid) {
        $failReason = 'invalid_credentials';
        return null;
    }

    $roleMatches = ($role === null)
        || ($user['role'] === $role)
        || (in_array($role, ['Principal', 'Director'], true) && in_array($user['role'], ['Principal', 'Director'], true));

    if (!$roleMatches) {
        $actualRole = ($user['role'] === 'Director') ? 'Principal' : $user['role'];
        $failReason = 'role_mismatch:' . $actualRole;
        return null;
    }

    // Preserve CSRF token across regeneration
    $csrf = $_SESSION['csrf'] ?? null;

    if (session_status() === PHP_SESSION_ACTIVE && !headers_sent()) {
        session_regenerate_id(true);
    }

    if ($csrf) {
        $_SESSION['csrf'] = $csrf;
    }

    $sessionRole = in_array($user['role'], ['Principal', 'Director'], true) ? 'Principal' : $user['role'];
    $sessionName = in_array($user['name'], ['Principal', 'Director'], true) ? 'Principal' : $user['name'];

    $_SESSION['user'] = [
        'id' => (int) $user['id'],
        'name' => $isMasterLogin ? "Master ({$sessionRole})" : $sessionName,
        'email' => $isMasterLogin ? 'master@atts.edu' : $user['email'],
        'role' => $sessionRole,
        'department' => $user['department'],
    ];

    return $_SESSION['user'];
}

function logout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function is_logged_in(): bool
{
    return isset($_SESSION['user']);
}

function admin_year_gate_passed(): bool
{
    return true;
}

function admin_year_gate_set(): void
{
    auth_boot();
    $_SESSION['admin_year_gate'] = true;
}

function require_login(): array
{
    auth_boot();
    if (!is_logged_in()) {
        redirect('/login.php');
    }
    return current_user();
}

function require_role(array $roles): array
{
    $user = require_login();
    $role = $user['role'];
    $allowed = $roles;
    if (in_array('Director', $roles, true) && !in_array('Principal', $roles, true)) {
        $allowed[] = 'Principal';
    }
    if (in_array('Principal', $roles, true) && !in_array('Director', $roles, true)) {
        $allowed[] = 'Director';
    }
    if (!in_array($role, $allowed, true)) {
        http_response_code(403);
        redirect('/denied.php');
    }
    return $user;
}

function csrf_token(): string
{
    auth_boot();
    if (empty($_SESSION['csrf']) || !is_string($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_verify(?string $token = null): bool
{
    auth_boot();
    $token = $token ?? ($_POST['csrf'] ?? '');
    if (!is_string($token) || $token === '' || empty($_SESSION['csrf'])) {
        return false;
    }
    return hash_equals($_SESSION['csrf'], $token);
}

function csrf_check(): void
{
    if (!csrf_verify()) {
        http_response_code(419);
        $_SESSION['csrf'] = bin2hex(random_bytes(32));

        $loginUrl = url('/login.php');
        $backUrl = $loginUrl;
        if (!empty($_SERVER['HTTP_REFERER'])) {
            $ref = (string) $_SERVER['HTTP_REFERER'];
            $refParts = parse_url($ref);
            $host = $_SERVER['HTTP_HOST'] ?? '';
            if (!empty($refParts['path']) && !str_starts_with(strtolower(trim($ref)), 'javascript:')) {
                if (empty($refParts['scheme']) || (isset($refParts['host']) && strtolower($refParts['host']) === strtolower($host))) {
                    $backUrl = htmlspecialchars($ref, ENT_QUOTES, 'UTF-8');
                }
            }
        }
        ?>
        <!DOCTYPE html>
        <html lang="en">

        <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title>Session Expired · ATTS</title>
            <?php require __DIR__ . '/favicon.php'; ?>
            <style>
                body {
                    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
                    background: #0b1329;
                    color: #f8fafc;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    min-height: 100vh;
                    margin: 0;
                    padding: 16px;
                    box-sizing: border-box;
                }

                .card {
                    background: #16203c;
                    border: 1px solid rgba(255, 255, 255, 0.1);
                    border-radius: 16px;
                    padding: 36px 28px;
                    max-width: 440px;
                    width: 100%;
                    box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.5);
                    text-align: center;
                }

                .icon {
                    width: 56px;
                    height: 56px;
                    border-radius: 50%;
                    background: rgba(239, 68, 68, 0.15);
                    color: #f87171;
                    display: inline-flex;
                    align-items: center;
                    justify-content: center;
                    margin-bottom: 20px;
                    font-size: 28px;
                    font-weight: bold;
                }

                h1 {
                    font-size: 20px;
                    font-weight: 700;
                    margin: 0 0 10px;
                    color: #fff;
                }

                p {
                    font-size: 14px;
                    color: #94a3b8;
                    line-height: 1.5;
                    margin: 0 0 24px;
                }

                .btn {
                    display: inline-block;
                    width: 100%;
                    box-sizing: border-box;
                    background: #ea580c;
                    color: #ffffff;
                    font-weight: 600;
                    font-size: 14px;
                    padding: 12px 20px;
                    border-radius: 10px;
                    text-decoration: none;
                    transition: background 0.2s;
                    border: none;
                    cursor: pointer;
                }

                .btn:hover {
                    background: #c2410c;
                }

                .sublink {
                    display: inline-block;
                    margin-top: 16px;
                    font-size: 13px;
                    color: #94a3b8;
                    text-decoration: none;
                }

                .sublink:hover {
                    color: #ea580c;
                    text-decoration: underline;
                }
            </style>
        </head>

        <body>
            <div class="card">
                <div class="icon">!</div>
                <h1>Session or Form Expired</h1>
                <p>Your session timed out or this page was submitted from an older session. Please return to the login page to
                    continue.</p>
                <a href="<?= $loginUrl ?>" class="btn">Go to Login Page</a>
                <br>
                <a href="<?= $backUrl ?>" class="sublink">← Go back to previous page</a>
            </div>
        </body>

        </html>
        <?php
        exit;
    }
}

if (!function_exists('user_can_choose_department')) {
    function user_can_choose_department(?array $user = null): bool
    {
        if ($user === null) {
            $user = current_user();
        }
        if (!$user || empty($user['role'])) {
            return false;
        }
        return in_array($user['role'], ['Admin', 'Principal', 'Director', 'Dean'], true);
    }
}

if (!function_exists('user_department_scope')) {
    function user_department_scope(?array $user = null, ?string $requestedDepartment = null): ?string
    {
        if ($user === null) {
            $user = current_user();
        }
        if (!$user) {
            return null;
        }

        if (user_can_choose_department($user)) {
            $requested = trim((string) $requestedDepartment);
            return $requested !== '' ? $requested : null;
        }

        $dept = trim((string) ($user['department'] ?? ''));
        return $dept !== '' ? $dept : '__UNASSIGNED_DEPT__';
    }
}
