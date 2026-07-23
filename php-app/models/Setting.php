<?php
/**
 * Institution-wide settings — a thin key/value store (app_settings table).
 *
 * Used for choices an Admin makes once for everyone, the first being the report
 * template every department's report must follow. Values are cached per request
 * so a page that reads the same key many times hits the database once.
 */

require_once __DIR__ . '/../inc/db.php';

/** The report templates on offer, and what each means. */
function report_templates(): array
{
    return [
        'full'    => 'Full proforma — Fixed, two Achieved periods, Remarks, Coordinator',
        'compact' => 'Compact — Fixed, one Achieved, Remarks, Coordinator',
    ];
}

/** Read a setting, falling back to $default when it has never been set. */
function setting_get(string $name, ?string $default = null): ?string
{
    static $cache = [];

    if (!array_key_exists($name, $cache)) {
        $stmt = db()->prepare('SELECT value FROM app_settings WHERE name = ?');
        $stmt->execute([$name]);
        $row = $stmt->fetch();
        $cache[$name] = $row ? $row['value'] : null;
    }

    return $cache[$name] ?? $default;
}

/** Write a setting (upsert), stamping who changed it. */
function setting_set(string $name, string $value, ?int $userId = null): void
{
    $stmt = db()->prepare(
        'INSERT INTO app_settings (name, value, updated_by) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE value = VALUES(value), updated_by = VALUES(updated_by)'
    );
    $stmt->execute([$name, $value, $userId]);
}

/** The active report template, validated against the known list. */
function active_report_template(): string
{
    $t = (string) setting_get('report_template', 'full');
    return array_key_exists($t, report_templates()) ? $t : 'full';
}
