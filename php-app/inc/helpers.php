<?php
/**
 * Small shared helpers: output escaping, URLs, redirects, and flash messages.
 */

require_once __DIR__ . '/config.php';

/** Escape a value for safe HTML output. Use on EVERYTHING echoed into a page. */
function e($value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

/** Build an app URL from a path, e.g. url('/dashboard.php'). */
function url(string $path = ''): string
{
    return BASE_URL . '/' . ltrim($path, '/');
}

/** Redirect to an app path and stop. */
function redirect(string $path): void
{
    if (strpos($path, 'http://') === 0 || strpos($path, 'https://') === 0) {
        header('Location: ' . $path);
        exit;
    }
    if (BASE_URL !== '' && strpos($path, BASE_URL) === 0) {
        header('Location: ' . $path);
        exit;
    }
    header('Location: ' . url($path));
    exit;
}

/** Read a request value (GET or POST) with a default. */
function input(string $key, $default = '')
{
    return $_POST[$key] ?? $_GET[$key] ?? $default;
}

/**
 * Normalise a user-entered date string (DD-MM-YYYY or YYYY-MM-DD) to ISO YYYY-MM-DD for SQL.
 * Returns null if invalid or empty.
 */
function parse_date_input(?string $input): ?string
{
    $input = trim((string) $input);
    if ($input === '') {
        return null;
    }
    // DD-MM-YYYY or DD/MM/YYYY
    if (preg_match('/^(\d{1,2})[-|\/](\d{1,2})[-|\/](\d{4})$/', $input, $m)) {
        $day   = (int) $m[1];
        $month = (int) $m[2];
        $year  = (int) $m[3];
        if (checkdate($month, $day, $year)) {
            return sprintf('%04d-%02d-%02d', $year, $month, $day);
        }
    }
    // YYYY-MM-DD
    if (preg_match('/^(\d{4})[-|\/](\d{1,2})[-|\/](\d{1,2})$/', $input, $m)) {
        $year  = (int) $m[1];
        $month = (int) $m[2];
        $day   = (int) $m[3];
        if (checkdate($month, $day, $year)) {
            return sprintf('%04d-%02d-%02d', $year, $month, $day);
        }
    }
    $ts = strtotime($input);
    return $ts ? date('Y-m-d', $ts) : null;
}

/**
 * Format ISO YYYY-MM-DD or date input string into display format DD-MM-YYYY.
 */
function format_date_display(?string $dateStr): string
{
    $dateStr = trim((string) $dateStr);
    if ($dateStr === '') {
        return '';
    }
    $iso = parse_date_input($dateStr);
    if (!$iso) {
        return $dateStr;
    }
    return date('d-m-Y', strtotime($iso));
}

/** Queue a one-shot flash message shown on the next page load. */
function flash(string $type, string $message): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

/** Pull and clear all queued flash messages. */
function take_flashes(): array
{
    $messages = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $messages;
}

/** Format an ISO/DB datetime as a short relative time ("3m ago"). */
function time_ago($datetime): string
{
    if (!$datetime) {
        return '';
    }

    $ts = strtotime((string) $datetime);
    if ($ts === false) {
        return '';
    }

    $diff = time() - $ts;

    if ($diff < 60)      return 'just now';
    if ($diff < 3600)    return floor($diff / 60) . 'm ago';
    if ($diff < 86400)   return floor($diff / 3600) . 'h ago';
    if ($diff < 2592000) return floor($diff / 86400) . 'd ago';

    return date('d M Y', $ts);
}

/**
 * Colour class for a record status.
 * Draft = grey, Submitted = blue, Approved = green, Rejected = red.
 */
function status_class(string $status): string
{
    $map = [
        'Draft'             => 'neutral',
        'Submitted'         => 'info',
        'HOD Pending'       => 'info',
        'Dean Pending'      => 'warning',
        'Approved'          => 'success',
        'Rejected'          => 'danger',
        'Pending'           => 'warning',
        'Completed'         => 'info',
        'Edit Requested'    => 'warning',
        'Unlocked for Edit' => 'info',
    ];

    return $map[$status] ?? 'neutral';
}

/**
 * Turn a plain-text message into safe HTML for the announcement body.
 *
 * The text is escaped FIRST, so nothing a user types can become real markup.
 * Only three touches of formatting are then added back:
 *
 *   a blank line          starts a new paragraph
 *   a line beginning "- " becomes a bullet
 *   **words like this**   become bold
 */
function format_text(string $text): string
{
    $text = str_replace(["\r\n", "\r"], "\n", trim($text));

    $html = '';

    // A blank line separates one block from the next.
    foreach (preg_split('/\n{2,}/', $text) as $block) {

        // Lines are collected as we go and written out when the kind of line
        // changes, so an intro sentence followed by bullets comes out as a
        // paragraph AND a list, not one run-on paragraph.
        $sentences = [];
        $bullets   = [];

        $writeSentences = function () use (&$html, &$sentences) {
            if ($sentences) {
                $html .= '<p>' . implode('<br>', array_map('e', $sentences)) . '</p>';
                $sentences = [];
            }
        };

        $writeBullets = function () use (&$html, &$bullets) {
            if ($bullets) {
                $html .= '<ul><li>' . implode('</li><li>', array_map('e', $bullets)) . '</li></ul>';
                $bullets = [];
            }
        };

        foreach (explode("\n", trim($block)) as $line) {
            $line = trim($line);

            if (strpos($line, '- ') === 0) {
                $writeSentences();
                $bullets[] = substr($line, 2);
            } else {
                $writeBullets();
                $sentences[] = $line;
            }
        }

        $writeSentences();
        $writeBullets();
    }

    // **bold** — safe to do now, because everything else is already escaped.
    return preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $html);
}

/**
 * The opening words of a long message, for a card preview.
 * Cuts on a space so the last word is never chopped in half.
 */
function excerpt(string $text, int $limit = 160): string
{
    $text = trim(preg_replace('/\s+/', ' ', strip_tags($text)));

    if (strlen($text) <= $limit) {
        return $text;
    }

    $cut   = substr($text, 0, $limit);
    $space = strrpos($cut, ' ');

    return rtrim($space ? substr($cut, 0, $space) : $cut, ' ,.;:-') . '…';
}

/** A file size people can read, e.g. "1.4 MB". */
function human_size(int $bytes): string
{
    if ($bytes >= 1048576) {
        return round($bytes / 1048576, 1) . ' MB';
    }
    if ($bytes >= 1024) {
        return round($bytes / 1024) . ' KB';
    }

    return $bytes . ' B';
}

/**
 * How long until a deadline, in words: "in 3 days", "today", "overdue".
 */
function time_until($datetime): string
{
    if (!$datetime) {
        return '';
    }

    $ts = strtotime((string) $datetime);
    if ($ts === false) {
        return '';
    }

    $days = (int) floor(($ts - time()) / 86400);

    if ($days < 0)  return 'closed';
    if ($days === 0) return 'today';
    if ($days === 1) return 'tomorrow';

    return "in $days days";
}

/**
 * The outline of one chart bar, for the <path d="..."> of an SVG column.
 *
 * A plain rectangle with rx="4" would round all four corners, including the
 * two sitting on the baseline. This draws the same box but curves only the
 * top two, so every bar rests flat on the axis:
 *
 *      ,--------.   <- rounded top (the end that carries the value)
 *      |        |
 *      |________|   <- square bottom, on the baseline
 */
function bar_path(float $x, float $top, float $width, float $baseline, float $radius = 4): string
{
    $height = $baseline - $top;

    // A very short bar can't fit the curve, so shrink the radius to suit.
    $r = min($radius, $height, $width / 2);

    return sprintf(
        'M %1$.1f %2$.1f V %3$.1f Q %1$.1f %4$.1f %5$.1f %4$.1f H %6$.1f Q %7$.1f %4$.1f %7$.1f %3$.1f V %2$.1f Z',
        $x,                    // 1 left edge
        $baseline,             // 2 bottom
        $top + $r,             // 3 where the curve starts
        $top,                  // 4 the very top
        $x + $r,               // 5 end of the top-left curve
        $x + $width - $r,      // 6 start of the top-right curve
        $x + $width            // 7 right edge
    );
}

/** Initials from a name, e.g. "Mohamed Uvaish" -> "MU". */
function initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name));
    $out = '';
    foreach (array_slice($parts, 0, 2) as $p) {
        if ($p !== '') {
            $out .= strtoupper($p[0]);
        }
    }
    return $out !== '' ? $out : 'U';
}

// Fallbacks for mbstring functions if the extension is not enabled in PHP
if (!function_exists('mb_strtolower')) {
    function mb_strtolower(string $string, ?string $encoding = null): string {
        return strtolower($string);
    }
}
if (!function_exists('mb_strpos')) {
    function mb_strpos(string $haystack, string $needle, int $offset = 0, ?string $encoding = null) {
        return strpos($haystack, $needle, $offset);
    }
}
if (!function_exists('mb_substr')) {
    function mb_substr(string $string, int $start, ?int $length = null, ?string $encoding = null): string {
        return $length === null ? substr($string, $start) : substr($string, $start, $length);
    }
}
if (!function_exists('mb_strlen')) {
    function mb_strlen(string $string, ?string $encoding = null): int {
        return strlen($string);
    }
}/**
 * Build a secure URL for accessing a record proof attachment.
 * When $absolute is true, includes scheme and host (essential for exported Word/Excel/PDF).
 */
function record_proof_url(string $type, int $id, ?string $filename = null, bool $download = false, bool $absolute = true): string
{
    $params = [
        'type' => $type,
        'id'   => $id,
    ];
    if ($filename !== null && $filename !== '') {
        $params['file'] = basename($filename);
    }
    if ($download) {
        $params['download'] = '1';
    }
    $rel = url('proof.php?' . http_build_query($params));
    if (!$absolute) {
        return $rel;
    }
    if (strpos($rel, 'http://') === 0 || strpos($rel, 'https://') === 0) {
        return $rel;
    }
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost:8000';
    return $scheme . '://' . $host . $rel;
}

/**
 * Inspect a record's proof file on disk and return metadata.
 */
function record_proof_meta(string $type, int $id, ?string $filename): ?array
{
    $filename = basename(trim((string) $filename));
    if ($filename === '') {
        return null;
    }
    $baseDir = defined('UPLOAD_DIR') ? realpath(UPLOAD_DIR) : null;
    if (!$baseDir) {
        $baseDir = realpath(__DIR__ . '/../uploads');
    }
    if (!$baseDir) {
        return null;
    }
    $paths = [
        $baseDir . DIRECTORY_SEPARATOR . 'proofs' . DIRECTORY_SEPARATOR . $filename,
        $baseDir . DIRECTORY_SEPARATOR . $filename,
    ];
    $filePath = null;
    foreach ($paths as $p) {
        if (file_exists($p) && is_file($p)) {
            $filePath = realpath($p);
            break;
        }
    }

    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $isImage = in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true);
    $viewUrl = record_proof_url($type, $id, $filename, false, true);
    $downUrl = record_proof_url($type, $id, $filename, true, true);

    $base64Data = null;
    if ($isImage && $filePath && filesize($filePath) <= 2097152) { // up to 2MB for base64 thumbnail
        $mime = ($ext === 'png') ? 'image/png' : (($ext === 'webp') ? 'image/webp' : (($ext === 'gif') ? 'image/gif' : 'image/jpeg'));
        $base64Data = 'data:' . $mime . ';base64,' . base64_encode((string) @file_get_contents($filePath));
    }

    return [
        'filename'     => $filename,
        'ext'          => $ext,
        'is_image'     => $isImage,
        'view_url'     => $viewUrl,
        'download_url' => $downUrl,
        'exists'       => ($filePath !== null),
        'base64_data'  => $base64Data,
    ];
}

/**
 * Resolve the physical filesystem path of a stored proof file.
 * Returns null if the file does not exist.
 */
function proof_file_path(?string $filename): ?string
{
    if (!$filename) {
        return null;
    }
    $safe = basename($filename);
    if ($safe === '') {
        return null;
    }
    $base = rtrim(UPLOAD_DIR, '/\\');
    if (is_file($base . '/' . $safe)) {
        return $base . '/' . $safe;
    }
    if (is_file($base . '/proofs/' . $safe)) {
        return $base . '/proofs/' . $safe;
    }
    return null;
}

/** Check if a proof file exists physically on the server. */
function proof_file_exists(?string $filename): bool
{
    return proof_file_path($filename) !== null;
}

/**
 * Render the Proof table cell:
 * - If no proof: "No proof attached"
 * - If physical file is missing: "Proof unavailable"
 * - If file exists: [ View Proof ] and [ Download ] actions
 */
function render_proof_cell(?string $proofFile, ?string $typeKey = null, ?int $recordId = null): string
{
    require_once __DIR__ . '/icons.php';

    if (empty($proofFile)) {
        return '<span class="card-sub">No proof attached</span>';
    }

    if (!proof_file_exists($proofFile)) {
        return '<span class="card-sub" style="color:var(--ink-muted,#64748b);">Proof unavailable</span>';
    }

    $params = ['file' => $proofFile];
    if (!empty($typeKey)) {
        $params['type'] = $typeKey;
    }
    if (!empty($recordId)) {
        $params['id'] = $recordId;
    }

    $viewUrl = url('view-proof.php?' . http_build_query($params));
    $params['download'] = '1';
    $downloadUrl = url('view-proof.php?' . http_build_query($params));

    return '<div style="display:inline-flex;align-items:center;gap:6px;">'
        . '<a class="btn btn-ghost btn-sm" href="' . e($viewUrl) . '" target="_blank" rel="noopener" title="View Proof">'
        . icon('paperclip', 14) . ' View Proof</a>'
        . '<a class="btn btn-ghost btn-sm" href="' . e($downloadUrl) . '" download title="Download Proof">'
        . icon('download', 14) . '</a>'
        . '</div>';
}

