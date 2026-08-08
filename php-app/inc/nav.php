<?php
/**
 * Role-based sidebar navigation — the PHP mirror of the React navigation.js.
 * Grouped into sections; each item names an icon and target page. Pages that
 * aren't built yet resolve to coming-soon.php automatically (see nav_href).
 */

require_once __DIR__ . '/db.php';

function navigation_for(string $role): array
{
    // Sections are consistent across roles — Overview, Workspace, Manage,
    // Account — and render in that order (group_navigation keeps first-seen
    // order). Each role only lists the pages it can actually reach.
    $items = [
        'Admin' => [
            ['section' => 'Overview',  'label' => 'Dashboard',      'path' => 'dashboard.php',      'icon' => 'dashboard'],
            ['section' => 'Overview',  'label' => 'Announcements',  'path' => 'announcements.php',  'icon' => 'megaphone', 'badge' => 'announcements'],
            ['section' => 'Workspace', 'label' => 'Approvals',      'path' => 'approvals.php',      'icon' => 'approvals', 'badge' => 'approvals'],
            ['section' => 'Workspace', 'label' => 'Reports',        'path' => 'reports.php',        'icon' => 'reports'],
            ['section' => 'Manage',    'label' => 'Users',          'path' => 'users.php',          'icon' => 'users'],
            ['section' => 'Manage',    'label' => 'Departments',    'path' => 'departments.php',    'icon' => 'building'],
            ['section' => 'Manage',    'label' => 'Targets',        'path' => 'targets.php',        'icon' => 'target', 'badge' => 'targets'],
            ['section' => 'Manage',    'label' => 'Report Template','path' => 'report-template.php','icon' => 'reports'],
            ['section' => 'Account',   'label' => 'Settings',       'path' => 'settings.php',       'icon' => 'settings'],
        ],
        'Director' => [
            ['section' => 'Overview',  'label' => 'Dashboard',     'path' => 'dashboard.php',     'icon' => 'dashboard'],
            ['section' => 'Overview',  'label' => 'Announcements', 'path' => 'announcements.php', 'icon' => 'megaphone', 'badge' => 'announcements'],
            ['section' => 'Workspace', 'label' => 'Reports',       'path' => 'reports.php',       'icon' => 'reports'],
            // Director reviews the targets a HoD sends up, so they need the page.
            ['section' => 'Manage',    'label' => 'Targets',       'path' => 'targets.php',       'icon' => 'target', 'badge' => 'targets'],
            ['section' => 'Account',   'label' => 'Profile',       'path' => 'profile.php',       'icon' => 'user'],
        ],
        'HoD' => [
            ['section' => 'Overview',  'label' => 'Dashboard',     'path' => 'dashboard.php',     'icon' => 'dashboard'],
            ['section' => 'Overview',  'label' => 'Announcements', 'path' => 'announcements.php', 'icon' => 'megaphone', 'badge' => 'announcements'],
            ['section' => 'Workspace', 'label' => 'Upload Data',   'path' => 'upload.php',        'icon' => 'upload'],
            ['section' => 'Workspace', 'label' => 'Approvals',     'path' => 'approvals.php',     'icon' => 'approvals', 'badge' => 'approvals'],
            ['section' => 'Workspace', 'label' => 'Reports',       'path' => 'reports.php',       'icon' => 'reports'],
            ['section' => 'Manage',    'label' => 'Faculty',       'path' => 'faculty.php',       'icon' => 'graduation'],
            ['section' => 'Manage',    'label' => 'Targets',       'path' => 'targets.php',       'icon' => 'target'],
            ['section' => 'Account',   'label' => 'Profile',       'path' => 'profile.php',       'icon' => 'user'],
        ],
        'Coordinator' => [
            ['section' => 'Overview',  'label' => 'Dashboard',     'path' => 'dashboard.php',     'icon' => 'dashboard'],
            ['section' => 'Overview',  'label' => 'Announcements', 'path' => 'announcements.php', 'icon' => 'megaphone', 'badge' => 'announcements'],
            ['section' => 'Workspace', 'label' => 'Upload Data',   'path' => 'upload.php',        'icon' => 'upload'],
            ['section' => 'Workspace', 'label' => 'Reports',       'path' => 'reports.php',       'icon' => 'reports'],
            ['section' => 'Account',   'label' => 'Profile',       'path' => 'profile.php',       'icon' => 'user'],
        ],
        'Faculty' => [
            ['section' => 'Overview',  'label' => 'Dashboard',     'path' => 'dashboard.php',     'icon' => 'dashboard'],
            ['section' => 'Overview',  'label' => 'Announcements', 'path' => 'announcements.php', 'icon' => 'megaphone', 'badge' => 'announcements'],
            ['section' => 'Workspace', 'label' => 'Upload Data',   'path' => 'upload.php',        'icon' => 'upload'],
            ['section' => 'Account',   'label' => 'Profile',       'path' => 'profile.php',       'icon' => 'user'],
        ],
    ];

    return $items[$role] ?? [];
}

/** Group a role's flat item list into ordered sections (default "Menu"). */
function group_navigation(array $items): array
{
    $groups = [];
    foreach ($items as $item) {
        $section = $item['section'] ?? 'Menu';
        $groups[$section][] = $item;
    }
    return $groups;
}

/** Link target: the real page if it exists, else the coming-soon placeholder. */
function nav_href(string $path): string
{
    $exists = is_file(dirname(__DIR__) . '/' . $path);
    return $exists ? url($path) : url('coming-soon.php?page=' . urlencode($path));
}

/** Total pending (Submitted) records a reviewer should act on, for the badge. */
function pending_approvals_count(array $user): int
{
    // Only Admin/HoD review; HoD is scoped to their own department.
    if (!in_array($user['role'], ['Admin', 'HoD'], true)) {
        return 0;
    }

    require_once __DIR__ . '/../models/Record.php';   // record_types()

    $scopeDept = $user['role'] === 'HoD' ? ($user['department'] ?? null) : null;

    // Every record type, all of which now carry a department column.
    $total = 0;
    foreach (record_types() as $t) {
        $table = $t['table'];
        if ($scopeDept !== null) {
            $stmt = db()->prepare("SELECT COUNT(*) FROM `$table` WHERE status='Submitted' AND department = ?");
            $stmt->execute([$scopeDept]);
        } else {
            $stmt = db()->query("SELECT COUNT(*) FROM `$table` WHERE status='Submitted'");
        }
        $total += (int) $stmt->fetchColumn();
    }

    return $total;
}
