<?php
/**
 * Minimal .env loader — so database credentials live in one editable file
 * (php-app/.env) instead of being hardcoded in PHP. No external library.
 *
 * Lines are KEY=VALUE. Blank lines and lines starting with # are ignored.
 * Values may be wrapped in single or double quotes. Real environment
 * variables (set by the server) always win over the file.
 */

function load_env(string $path): void
{
    static $loaded = [];

    if (isset($loaded[$path]) || !is_file($path) || !is_readable($path)) {
        return;
    }
    $loaded[$path] = true;

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $key   = trim($key);
        $value = trim($value);

        // Strip one layer of surrounding quotes.
        $len = strlen($value);
        if ($len >= 2) {
            $q = $value[0];
            if (($q === '"' || $q === "'") && $value[$len - 1] === $q) {
                $value = substr($value, 1, -1);
            }
        }

        // Don't clobber a real environment variable of the same name.
        if (getenv($key) === false) {
            putenv("$key=$value");
            $_ENV[$key]    = $value;
            $_SERVER[$key] = $value;
        }
    }
}

/**
 * Read an env value with a default. "true"/"false" (any case) come back as
 * booleans; everything else is returned as-is.
 */
function env(string $key, $default = null)
{
    $value = getenv($key);

    if ($value === false) {
        return $default;
    }

    switch (strtolower($value)) {
        case 'true':  return true;
        case 'false': return false;
        case 'null':  return null;
        case '':      return $default;
        default:      return $value;
    }
}
