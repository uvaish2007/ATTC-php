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

/** Build a URL for a proof file, served through the resilient proof viewer endpoint. */
function proof_url(?string $filename): string
{
    $filename = trim((string) $filename);
    if ($filename === '') {
        return '';
    }
    if (str_starts_with($filename, 'http://') || str_starts_with($filename, 'https://')) {
        return $filename;
    }
    return url('proof.php?file=' . rawurlencode(basename($filename)));
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
        'Draft'                 => 'neutral',
        'Submitted'             => 'info',
        'HOD Pending'           => 'info',
        'Dean Pending'          => 'warning',
        'Approved'              => 'success',
        'Rejected'              => 'danger',
        'Edit Requested'        => 'warning',
        'Unlocked for Edit'     => 'primary',
        'Resubmitted'           => 'info',
        'Correction Authorized' => 'primary',
        'Completed'             => 'success',
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
}

/**
 * Return all valid format representations for an academic year (e.g. ['2025-26', '2025-2026']).
 * Ensures queries match regardless of 2-digit or 4-digit end-year convention.
 */
function academic_year_variants(?string $year): array
{
    $year = trim((string) $year);
    if ($year === '') return [];
    $variants = [$year];
    // If format like 2025-26 -> add 2025-2026
    if (preg_match('/^(\d{4})-(\d{2})$/', $year, $m)) {
        $century = substr($m[1], 0, 2);
        $variants[] = $m[1] . '-' . $century . $m[2];
    }
    // If format like 2025-2026 -> add 2025-26
    elseif (preg_match('/^(\d{4})-(\d{4})$/', $year, $m)) {
        $variants[] = $m[1] . '-' . substr($m[2], 2, 2);
    }
    return array_values(array_unique($variants));
}

/**
 * Compare two department names robustly, ignoring spacing, case, and standard abbreviations.
 * E.g. 'AI & DS' matches 'AI&DS', 'AIDS', and 'CSE' matches 'Computer Science and Engineering'.
 */
function department_names_match(?string $deptA, ?string $deptB): bool
{
    if ($deptA === null || $deptB === null) return false;
    $trimA = trim($deptA);
    $trimB = trim($deptB);
    if ($trimA === '' || $trimB === '') return false;
    if (strcasecmp($trimA, $trimB) === 0) return true;

    $knownAliases = [
        'cse'   => ['cse', 'computer science and engineering', 'computer science & engineering'],
        'csbs'  => ['csbs', 'computer science and business systems', 'computer science & business systems'],
        'aids'  => ['aids', 'ai & ds', 'ai&ds', 'artificial intelligence and data science', 'artificial intelligence & data science'],
        'aiml'  => ['aiml', 'ai & ml', 'ai&ml', 'artificial intelligence and machine learning', 'artificial intelligence & machine learning'],
        'ece'   => ['ece', 'electronics and communication engineering', 'electronics & communication engineering'],
        'eee'   => ['eee', 'electrical and electronics engineering', 'electrical & electronics engineering'],
        'it'    => ['it', 'information technology'],
        'mech'  => ['mech', 'mechanical engineering'],
        'civil' => ['civil', 'civil engineering'],
        'aero'  => ['aero', 'aeronautical engineering'],
        'chem'  => ['chem', 'chemical engineering'],
        'cyber' => ['cyber', 'cyber security', 'cybersecurity'],
        'arch'  => ['arch', 'architecture'],
        'mca'   => ['mca', 'master of computer applications'],
        'mba'   => ['mba', 'master of business administration'],
    ];

    $clean = function(string $s): string {
        $s = strtolower($s);
        $s = str_replace(['&', 'and'], '+', $s);
        $s = preg_replace('/[^a-z0-9+]/', '', $s);
        return $s;
    };

    $cA = $clean($trimA);
    $cB = $clean($trimB);
    if ($cA === $cB) return true;

    // Check known alias groups
    $lowerA = strtolower($trimA);
    $lowerB = strtolower($trimB);
    foreach ($knownAliases as $code => $group) {
        $inA = in_array($lowerA, $group, true) || $cA === $code;
        $inB = in_array($lowerB, $group, true) || $cB === $code;
        if ($inA && $inB) return true;
    }

    return false;
}

/**
 * Expand a department code/short name to its full official name for all report
 * headings, meta lines, table columns, and sign-offs across PDF/Excel/Word/CSV.
 */
if (!function_exists('department_full_name')) {
    function department_full_name(?string $dept): string
    {
        $dept = trim((string) $dept);
        if ($dept === '' || strcasecmp($dept, 'ALL DEPARTMENTS') === 0 || strcasecmp($dept, 'All departments') === 0) {
            return $dept;
        }

        static $map = [
            'CSE'                  => 'Computer Science and Engineering',
            'CSBS'                 => 'Computer Science and Business Systems',
            'AIDS'                 => 'Artificial Intelligence and Data Science',
            'ECE'                  => 'Electronics and Communication Engineering',
            'EEE'                  => 'Electrical and Electronics Engineering',
            'MECH'                 => 'Mechanical Engineering',
            'CIVIL'                => 'Civil Engineering',
            'IT'                   => 'Information Technology',
            'AGRI'                 => 'Agriculture Engineering',
            'AERO'                 => 'Aeronautical Engineering',
            'MARINE'               => 'Marine Engineering',
            'AIML'                 => 'Artificial Intelligence and Machine Learning',
            'CYBER'                => 'Cyber Security',
            'CYBERSECURITY'        => 'Cyber Security',
            'CHEM'                 => 'Chemical Engineering',
            'ARCH'                 => 'Architecture',
            'MCA'                  => 'Master of Computer Applications',
            'MBA'                  => 'Master of Business Administration',
            'SH'                   => 'Science and Humanities',
            'SANDH'                => 'Science and Humanities',
            'SCIENCEANDHUMANITIES' => 'Science and Humanities',
            'BME'                  => 'Biomedical Engineering',
            'BT'                   => 'Biotechnology',
        ];

        $key = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $dept));
        if (isset($map[$key])) {
            return $map[$key];
        }

        // Check if $dept already matches a full name in the map
        foreach ($map as $k => $fullName) {
            if (strcasecmp($dept, $fullName) === 0) {
                return $fullName;
            }
        }

        // Check database departments table if managed by Admin
        if (function_exists('departments_all')) {
            foreach (departments_all() as $d) {
                $codeKey = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)$d['code']));
                $nameKey = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)$d['name']));
                if ($key === $codeKey || $key === $nameKey) {
                    if (isset($map[$codeKey])) {
                        return $map[$codeKey];
                    }
                    if (isset($map[$nameKey])) {
                        return $map[$nameKey];
                    }
                    return mb_strlen($d['name']) >= mb_strlen($d['code']) ? $d['name'] : $d['code'];
                }
            }
        }

        return $dept;
    }
}
