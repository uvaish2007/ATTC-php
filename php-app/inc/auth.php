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

/** Attempt login. Returns the user row on success, or null on failure. */
function attempt_login(string $email, string $password): ?array
{
    $stmt = db()->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user) {
        return null;
    }

    if ((int) $user['status'] !== 1) {
        return null; // deactivated account
    }

    if (!password_verify($password, $user['password'])) {
        return null;
    }

    // Regenerate the id on privilege change to prevent session fixation.
    session_regenerate_id(true);

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
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

/** Hidden input for forms. */
function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

/** Verify the token on a POST; abort with 419 on mismatch. */
function csrf_check(): void
{
    $token = $_POST['csrf'] ?? '';
    if (!is_string($token) || !hash_equals(csrf_token(), $token)) {
        http_response_code(419);
        die('Invalid or expired form token. Please go back and try again.');
    }
}
