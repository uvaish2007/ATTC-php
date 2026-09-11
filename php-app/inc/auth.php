<?php
/**
 * Session-based authentication and authorisation.
 *
 * Replaces the React/Express JWT flow: instead of a token in localStorage,
 * the signed-in user lives in a server session. Pages call require_login()
 * (and optionally require_role()) at the top to gate access.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/feature_flags.php';

/** Start the session with a stable name and hardened cookie flags. */
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
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => $https,
    ]);

    session_name(SESSION_NAME);
    session_start();
}

/** Attempt login. Returns the user row on success, or null on failure.
 *  If $role is provided, verifies that user's role matches before setting session.
 */
function attempt_login(string $email, string $password, ?string $role = null, ?string &$failReason = null): ?array
{
    $stmt = db()->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user) {
        $failReason = 'invalid_credentials';
        return null;
    }

    if ((int) $user['status'] !== 1) {
        $failReason = 'deactivated';
        return null;
    }

    if (!password_verify($password, $user['password'])) {
        $failReason = 'invalid_credentials';
        return null;
    }

    if ($role !== null && $user['role'] !== $role) {
        $failReason = 'role_mismatch:' . $user['role'];
        return null;
    }

    // Preserve CSRF token across regeneration
    $csrf = $_SESSION['csrf'] ?? null;

    // Regenerate the id on privilege change to prevent session fixation.
    session_regenerate_id(true);

    if ($csrf) {
        $_SESSION['csrf'] = $csrf;
    }

    $_SESSION['user'] = [
        'id'         => (int) $user['id'],
        'name'       => $user['name'],
        'email'      => $user['email'],
        'role'       => $user['role'],
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

/**
 * Login-flow gate: has THIS Admin session already stepped through Academic
 * Year Selection? This is purely a per-login UX gate — it does not carry the
 * active year itself. The active year is a system-wide value (see
 * active_academic_year() / activate_academic_year() in models/Target.php),
 * stored in app_settings so every role/session sees the same one; the gate
 * just decides whether *this* Admin login still needs to see the picker.
 */
function admin_year_gate_passed(): bool
{
    auth_boot();
    return !empty($_SESSION['admin_year_gate']);
}

/** Mark this Admin session as having activated (or confirmed) a year. */
function admin_year_gate_set(): void
{
    auth_boot();
    $_SESSION['admin_year_gate'] = true;
}

/** Gate a page to signed-in users; bounce to login otherwise. */
function require_login(): array
{
    auth_boot();
    if (!is_logged_in()) {
        redirect('/login.php');
    }
    return current_user();
}

/** Gate a page to specific roles; bounce to an "access denied" page otherwise. */
function require_role(array $roles): array
{
    $user = require_login();
    if (!in_array($user['role'], $roles, true)) {
        http_response_code(403);
        redirect('/denied.php');
    }
    return $user;
}

/* --------------------------------------------------------------------------
 *  CSRF protection for state-changing forms.
 * ------------------------------------------------------------------------ */

function csrf_token(): string
{
    auth_boot();
    if (empty($_SESSION['csrf']) || !is_string($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

/** Hidden input for forms. */
function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

/** Verify whether the submitted CSRF token matches the session. */
function csrf_verify(?string $token = null): bool
{
    auth_boot();
    $token = $token ?? ($_POST['csrf'] ?? '');
    if (!is_string($token) || $token === '' || empty($_SESSION['csrf'])) {
        return false;
    }
    return hash_equals($_SESSION['csrf'], $token);
}

/** Verify the token on a POST; abort with friendly 419 page on mismatch. */
function csrf_check(): void
{
    if (!csrf_verify()) {
        http_response_code(419);
        // Refresh token so subsequent retry requests have a clean token
        $_SESSION['csrf'] = bin2hex(random_bytes(32));

        $loginUrl = url('/login.php');
        $backUrl = !empty($_SERVER['HTTP_REFERER']) ? htmlspecialchars($_SERVER['HTTP_REFERER'], ENT_QUOTES, 'UTF-8') : $loginUrl;
        ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Session Expired · ATTS</title>
  <style>
    body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #0b1329; color: #f8fafc; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; padding: 16px; box-sizing: border-box; }
    .card { background: #16203c; border: 1px solid rgba(255,255,255,0.1); border-radius: 16px; padding: 36px 28px; max-width: 440px; width: 100%; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.5); text-align: center; }
    .icon { width: 56px; height: 56px; border-radius: 50%; background: rgba(239, 68, 68, 0.15); color: #f87171; display: inline-flex; align-items: center; justify-content: center; margin-bottom: 20px; font-size: 28px; font-weight: bold; }
    h1 { font-size: 20px; font-weight: 700; margin: 0 0 10px; color: #fff; }
    p { font-size: 14px; color: #94a3b8; line-height: 1.5; margin: 0 0 24px; }
    .btn { display: inline-block; width: 100%; box-sizing: border-box; background: #ea580c; color: #ffffff; font-weight: 600; font-size: 14px; padding: 12px 20px; border-radius: 10px; text-decoration: none; transition: background 0.2s; border: none; cursor: pointer; }
    .btn:hover { background: #c2410c; }
    .sublink { display: inline-block; margin-top: 16px; font-size: 13px; color: #94a3b8; text-decoration: none; }
    .sublink:hover { color: #ea580c; text-decoration: underline; }
  </style>
</head>
<body>
  <div class="card">
    <div class="icon">!</div>
    <h1>Session or Form Expired</h1>
    <p>Your session timed out or this page was submitted from an older session. Please return to the login page to continue.</p>
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
