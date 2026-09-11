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
        'Dean' => [
            ['section' => 'Menu',    'label' => 'Dashboard',     'path' => 'dashboard.php',    'icon' => 'dashboard'],
            ['section' => 'Menu',    'label' => 'Approvals',     'path' => 'approvals.php',    'icon' => 'approvals', 'badge' => 'approvals'],
            ['section' => 'Menu',    'label' => 'Announcements', 'path' => 'announcements.php','icon' => 'megaphone', 'badge' => 'announcements'],
            ['section' => 'Menu',    'label' => 'Reports',       'path' => 'reports.php',      'icon' => 'reports'],
            ['section' => 'Menu',    'label' => 'Targets',       'path' => 'targets.php',      'icon' => 'target', 'badge' => 'targets'],
            ['section' => 'Account', 'label' => 'Profile',       'path' => 'profile.php',      'icon' => 'user'],
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
            ['section' => 'Workspace', 'label' => 'Approvals',     'path' => 'approvals.php',     'icon' => 'approvals', 'badge' => 'approvals'],
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

/** Total pending records a reviewer should act on, for the badge. */
function pending_approvals_count(array $user): int
{
    // Coordinator/HoD/Dean/Admin review; Coordinator and HoD are scoped to
    // their own department.
    if (!in_array($user['role'], ['Admin', 'Dean', 'HoD', 'Coordinator'], true)) {
        return 0;
    }

    // The header badge and the notification bell both ask for this in the same
    // request, and it fans out to one COUNT(*) per record table. Memoise per
    // request (keyed by the only inputs that matter) so that fan-out happens
    // once, not once per caller.
    static $memo = [];
    $memoKey = $user['role'] . '|' . ($user['department'] ?? '');
    if (array_key_exists($memoKey, $memo)) {
        return $memo[$memoKey];
    }

    require_once __DIR__ . '/../models/Record.php';   // record_types(), target_record_table_columns()

    $role = $user['role'];
    $scopeDept = in_array($role, ['HoD', 'Coordinator'], true) ? ($user['department'] ?? null) : null;
    $year      = active_academic_year();   // the badge only counts the active year's pending records

    if ($role === 'Coordinator') {
        $targetStatuses = ['Submitted'];
    } elseif ($role === 'HoD') {
        $targetStatuses = ['HOD Pending', 'Submitted'];
    } elseif ($role === 'Dean') {
        $targetStatuses = ['Dean Pending'];
    } else {   // Admin — everything still awaiting a decision
        $targetStatuses = ['Submitted', 'HOD Pending', 'Dean Pending'];
    }

    $inClause = implode(',', array_fill(0, count($targetStatuses), '?'));

    $total = 0;
    foreach (record_types() as $t) {
        $table = $t['table'];
        try {
            $sql    = "SELECT COUNT(*) FROM `$table` WHERE status IN ($inClause)";
            $params = $targetStatuses;
            if ($scopeDept !== null) {
                $sql     .= ' AND department = ?';
                $params[] = $scopeDept;
            }
            if (in_array('academic_year', target_record_table_columns($table), true)) {
                $sql     .= ' AND academic_year = ?';
                $params[] = $year;
            }
            $stmt = db()->prepare($sql);
            $stmt->execute($params);
            $total += (int) $stmt->fetchColumn();
        } catch (\PDOException $e) {
            continue;
        }
    }

    return $memo[$memoKey] = $total;
}
