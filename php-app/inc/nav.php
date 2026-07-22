<?php
/**
 * Role-based sidebar navigation — the PHP mirror of the React navigation.js.
 * Grouped into sections; each item names an icon and target page. Pages that
 * aren't built yet resolve to coming-soon.php automatically (see nav_href).
 */

require_once __DIR__ . '/db.php';

function navigation_for(string $role): array
{
    $items = [
        'Admin' => [
            ['section' => 'Main',   'label' => 'Dashboard',   'path' => 'dashboard.php',   'icon' => 'dashboard'],
            ['section' => 'Main',   'label' => 'Approvals',    'path' => 'approvals.php',   'icon' => 'approvals', 'badge' => 'approvals'],
            ['section' => 'Main',   'label' => 'Announcements','path' => 'announcements.php','icon' => 'megaphone', 'badge' => 'announcements'],
            ['section' => 'Main',   'label' => 'Reports',      'path' => 'reports.php',     'icon' => 'reports'],
            ['section' => 'Manage', 'label' => 'Users',        'path' => 'users.php',       'icon' => 'users'],
            ['section' => 'Manage', 'label' => 'Departments',  'path' => 'departments.php', 'icon' => 'building'],
            ['section' => 'Manage', 'label' => 'Targets',      'path' => 'targets.php',     'icon' => 'target', 'badge' => 'targets'],
            ['section' => 'System', 'label' => 'Settings',     'path' => 'settings.php',    'icon' => 'settings'],
        ],
        'Director' => [
            ['section' => 'Menu',    'label' => 'Dashboard',     'path' => 'dashboard.php',    'icon' => 'dashboard'],
            ['section' => 'Menu',    'label' => 'Announcements', 'path' => 'announcements.php','icon' => 'megaphone', 'badge' => 'announcements'],
            ['section' => 'Menu',    'label' => 'Reports',       'path' => 'reports.php',      'icon' => 'reports'],
            // Director reviews the targets a HoD sends up, so they need the page.
            ['section' => 'Menu',    'label' => 'Targets',       'path' => 'targets.php',      'icon' => 'target', 'badge' => 'targets'],
            ['section' => 'Account', 'label' => 'Profile',       'path' => 'profile.php',      'icon' => 'user'],
        ],
        'HoD' => [
            ['section' => 'Main',       'label' => 'Dashboard',    'path' => 'dashboard.php',    'icon' => 'dashboard'],
            ['section' => 'Main',       'label' => 'Approvals',    'path' => 'approvals.php',    'icon' => 'approvals', 'badge' => 'approvals'],
            ['section' => 'Main',       'label' => 'Announcements','path' => 'announcements.php','icon' => 'megaphone', 'badge' => 'announcements'],
            ['section' => 'Department', 'label' => 'Faculty',     'path' => 'faculty.php',   'icon' => 'graduation'],
            ['section' => 'Department', 'label' => 'Targets',     'path' => 'targets.php',   'icon' => 'target'],
            ['section' => 'Records',    'label' => 'Upload Data', 'path' => 'upload.php',    'icon' => 'upload'],
            ['section' => 'Records',    'label' => 'Reports',     'path' => 'reports.php',   'icon' => 'reports'],
            ['section' => 'Account',    'label' => 'Profile',     'path' => 'profile.php',   'icon' => 'user'],
        ],
        'Coordinator' => [
            ['section' => 'Menu',    'label' => 'Dashboard',    'path' => 'dashboard.php',    'icon' => 'dashboard'],
            ['section' => 'Menu',    'label' => 'Announcements','path' => 'announcements.php','icon' => 'megaphone', 'badge' => 'announcements'],
            ['section' => 'Menu',    'label' => 'Upload Data',  'path' => 'upload.php',       'icon' => 'upload'],
            ['section' => 'Menu',    'label' => 'Reports',      'path' => 'reports.php',      'icon' => 'reports'],
            ['section' => 'Account', 'label' => 'Profile',      'path' => 'profile.php',      'icon' => 'user'],
        ],
        'Faculty' => [
            ['section' => 'Menu',    'label' => 'Dashboard',    'path' => 'dashboard.php',    'icon' => 'dashboard'],
            ['section' => 'Menu',    'label' => 'Announcements','path' => 'announcements.php','icon' => 'megaphone', 'badge' => 'announcements'],
            ['section' => 'Menu',    'label' => 'Upload Data',  'path' => 'upload.php',       'icon' => 'upload'],
            ['section' => 'Account', 'label' => 'Profile',      'path' => 'profile.php',      'icon' => 'user'],
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
    $tables = [
        'journal_publications', 'book_publications', 'conference_publications',
        'patents', 'fdp', 'mou', 'events', 'nptel', 'internships', 'placements',
    ];

    // Only Admin/HoD review; HoD is scoped to their own department.
    if (!in_array($user['role'], ['Admin', 'HoD'], true)) {
        return 0;
    }

    $scopeDept = $user['role'] === 'HoD' ? ($user['department'] ?? null) : null;

    $total = 0;
    foreach ($tables as $t) {
        // Tables without a department column can't be scoped to an HoD.
        $hasDept = !in_array($t, ['internships', 'placements'], true);

        if ($scopeDept !== null && !$hasDept) {
            continue;
        }

        if ($scopeDept !== null) {
            $stmt = db()->prepare("SELECT COUNT(*) FROM `$t` WHERE status='Submitted' AND department = ?");
            $stmt->execute([$scopeDept]);
        } else {
            $stmt = db()->query("SELECT COUNT(*) FROM `$t` WHERE status='Submitted'");
        }
        $total += (int) $stmt->fetchColumn();
    }

    return $total;
}
