<?php
require_once __DIR__ . '/../inc/db.php';

function report_templates(): array
{
    return [
        'full'    => 'Full proforma — Fixed, two Achieved periods, Remarks, Coordinator',
        'compact' => 'Compact — Fixed, one Achieved, Remarks, Coordinator',
    ];
}

function setting_get(string $name, ?string $default = null): ?string
{
    global $g_settings_cache;
    if (!is_array($g_settings_cache)) {
        $g_settings_cache = [];
    }

    if (!array_key_exists($name, $g_settings_cache)) {
        $stmt = db()->prepare('SELECT value FROM app_settings WHERE name = ?');
        $stmt->execute([$name]);
        $row = $stmt->fetch();
        $g_settings_cache[$name] = $row ? $row['value'] : null;
    }

    return $g_settings_cache[$name] ?? $default;
}

function setting_set(string $name, string $value, ?int $userId = null): void
{
    global $g_settings_cache;
    if (!is_array($g_settings_cache)) {
        $g_settings_cache = [];
    }
    $g_settings_cache[$name] = $value;

    $stmt = db()->prepare(
        'INSERT INTO app_settings (name, value, updated_by) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE value = VALUES(value), updated_by = VALUES(updated_by)'
    );
    $stmt->execute([$name, $value, $userId]);
}

function active_report_template(): string
{
    $t = (string) setting_get('report_template', 'full');
    return array_key_exists($t, report_templates()) ? $t : 'full';
}
