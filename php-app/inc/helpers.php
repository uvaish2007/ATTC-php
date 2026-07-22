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
    header('Location: ' . url($path));
    exit;
}

/** Read a request value (GET or POST) with a default. */
function input(string $key, $default = '')
{
    return $_POST[$key] ?? $_GET[$key] ?? $default;
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
        'Draft'     => 'neutral',
        'Submitted' => 'info',
        'Approved'  => 'success',
        'Rejected'  => 'danger',
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
