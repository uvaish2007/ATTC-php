<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

function feature_core_modules(): array
{
    return ['dashboard', 'profile'];
}

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
        $cache = [];
    }

    return $cache;
}

function module_status(string $module): string
{
    if (in_array($module, feature_core_modules(), true)) {
        return 'active';
    }

    return feature_flags_all()[$module] ?? 'active';
}

function module_is_active(string $module): bool
{
    return module_status($module) === 'active';
}

function module_for_path(string $path): string
{
    $clean = strtok($path, '?');
    return basename($clean, '.php');
}

function require_module(string $module): void
{
    if (module_status($module) === 'active') {
        return;
    }

    http_response_code(423); 

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
