<?php
/**
 * Feature flags — decide, per module, whether a page is reachable.
 *
 * Each app module maps to a row in the `feature_flags` table. A page calls
 * require_module('users') right after its auth check; if the module is not
 * 'active', a clean 423 "Coming Soon" page is shown instead of the real page.
 * The sidebar uses module_is_active() to grey out and lock non-active links.
 *
 * Fail-open by design: if the table has not been created yet, or a module has
 * no row, it is treated as 'active' — installing this file never breaks a page.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

/**
 * Modules a signed-in user must always be able to reach, whatever the flags
 * say — otherwise a bad flag could lock someone out of their own dashboard.
 */
function feature_core_modules(): array
{
    return ['dashboard', 'profile'];
}

/** All flags as [module => status], read once per request. Empty if unmigrated. */
function feature_flags_all(): array
{
    static $cache = null;

    if ($cache !== null) {
        return $cache;
    }

    $cache = [];
    try {
        foreach (db()->query('SELECT module_name, status FROM feature_flags') as $row) {
            $cache[$row['module_name']] = $row['status'];
        }
    } catch (\PDOException $e) {
        // Table not created yet — leave the cache empty so everything reads active.
        $cache = [];
    }

    return $cache;
}

/** Status for one module: 'active' | 'maintenance' | 'disabled'. Defaults active. */
function module_status(string $module): string
{
    if (in_array($module, feature_core_modules(), true)) {
        return 'active';
    }

    return feature_flags_all()[$module] ?? 'active';
}

/** True when a module is live (used by the sidebar to decide lock vs link). */
function module_is_active(string $module): bool
{
    return module_status($module) === 'active';
}

/** Module name for a page path, e.g. 'targets.php' => 'targets'. */
function module_for_path(string $path): string
{
    return basename($path, '.php');
}

/**
 * Gate a page behind its feature flag. Call right after require_login() /
 * require_role(). If the module is not active, render a 423 "Coming Soon"
 * page inside the normal app shell and stop.
 */
function require_module(string $module): void
{
    if (module_status($module) === 'active') {
        return;
    }

    http_response_code(423); // 423 Locked

    // Shown by the shell below. Local vars are visible to the included files.
    $pageTitle  = 'Coming Soon';
    $breadcrumb = 'Coming Soon';
    $label      = ucwords(str_replace('_', ' ', $module));

    require __DIR__ . '/header.php';
    ?>
    <div class="card">
      <div class="empty" style="padding:80px 24px">
        <div class="ic" style="background:#FFF7ED; color:#C2410C; width:56px; height:56px"><?= icon('lock', 24) ?></div>
        <p style="font-size:18px; font-weight:600; color:var(--ink)"><?= e($label) ?> &middot; Coming Soon</p>
        <div class="note">This module is in maintenance for the current alpha release. It will be switched on soon.</div>
        <a class="btn btn-outline btn-sm" style="margin-top:20px" href="<?= e(url('dashboard.php')) ?>">Back to Dashboard</a>
      </div>
    </div>
    <?php
    require __DIR__ . '/footer.php';
    exit;
}
