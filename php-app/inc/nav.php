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
            ['section' => 'Overview',  'label' => 'Dashboard',            'path' => 'dashboard.php',            'icon' => 'dashboard'],
            ['section' => 'Overview',  'label' => 'Announcements',        'path' => 'announcements.php',        'icon' => 'megaphone', 'badge' => 'announcements'],
            // FEAT-12 — the historical archive of expired notices, kept beside
            // the Announcements entry it belongs to. Admin only: no other
            // role's list has it, and announcements-archive.php gates on the
            // role itself regardless of what the menu shows.
            ['section' => 'Overview',  'label' => 'Announcement Archive', 'path' => 'announcements-archive.php', 'icon' => 'archive'],
            ['section' => 'Workspace', 'label' => 'Approvals',            'path' => 'approvals.php',            'icon' => 'approvals', 'badge' => 'approvals'],
            ['section' => 'Workspace', 'label' => 'Academic Year',        'path' => 'academic-years.php',       'icon' => 'calendar'],
            ['section' => 'Workspace', 'label' => 'Review Targets',       'path' => 'targets.php',              'icon' => 'target', 'badge' => 'targets'],
            ['section' => 'Workspace', 'label' => 'Reports',              'path' => 'reports.php',              'icon' => 'reports'],
            ['section' => 'Workspace', 'label' => 'Faculty Achievements', 'path' => 'faculty-achievements.php', 'icon' => 'award'],
            ['section' => 'Manage',    'label' => 'Users',                'path' => 'users.php',                'icon' => 'users'],
            ['section' => 'Manage',    'label' => 'Departments',          'path' => 'departments.php',          'icon' => 'building'],
            ['section' => 'Manage',    'label' => 'Report Template',      'path' => 'report-template.php',      'icon' => 'reports'],
            // FEAT-11 lives inside Settings (its last tab), so the pending count
            // rides on Settings. Admin only — no other role's list has this entry,
            // and password-requests.php gates on the role itself regardless.
            ['section' => 'Account',   'label' => 'Settings',             'path' => 'settings.php',             'icon' => 'settings', 'badge' => 'password_requests'],
        ],
        'Principal' => [
            ['section' => 'Overview',  'label' => 'Dashboard',            'path' => 'dashboard.php',            'icon' => 'dashboard'],
            ['section' => 'Overview',  'label' => 'Announcements',        'path' => 'announcements.php',        'icon' => 'megaphone', 'badge' => 'announcements'],
            ['section' => 'Workspace', 'label' => 'Review Targets',       'path' => 'targets.php',              'icon' => 'target', 'badge' => 'targets'],
            ['section' => 'Workspace', 'label' => 'Reports',              'path' => 'reports.php',              'icon' => 'reports'],
            ['section' => 'Workspace', 'label' => 'Faculty Achievements', 'path' => 'faculty-achievements.php', 'icon' => 'award'],
            ['section' => 'Account',   'label' => 'Profile',              'path' => 'profile.php',              'icon' => 'user'],
        ],
        'Director' => [
            ['section' => 'Overview',  'label' => 'Dashboard',            'path' => 'dashboard.php',            'icon' => 'dashboard'],
            ['section' => 'Overview',  'label' => 'Announcements',        'path' => 'announcements.php',        'icon' => 'megaphone', 'badge' => 'announcements'],
            ['section' => 'Workspace', 'label' => 'Review Targets',       'path' => 'targets.php',              'icon' => 'target', 'badge' => 'targets'],
            ['section' => 'Workspace', 'label' => 'Reports',              'path' => 'reports.php',              'icon' => 'reports'],
            ['section' => 'Workspace', 'label' => 'Faculty Achievements', 'path' => 'faculty-achievements.php', 'icon' => 'award'],
            ['section' => 'Account',   'label' => 'Profile',              'path' => 'profile.php',              'icon' => 'user'],
        ],
        'Dean' => [
            ['section' => 'Overview',  'label' => 'Dashboard',            'path' => 'dashboard.php',            'icon' => 'dashboard'],
            ['section' => 'Overview',  'label' => 'Announcements',        'path' => 'announcements.php',        'icon' => 'megaphone', 'badge' => 'announcements'],
            ['section' => 'Workspace', 'label' => 'Approvals',            'path' => 'approvals.php',            'icon' => 'approvals', 'badge' => 'approvals'],
            ['section' => 'Workspace', 'label' => 'Review Targets',       'path' => 'targets.php',              'icon' => 'target', 'badge' => 'targets'],
            ['section' => 'Workspace', 'label' => 'Reports',              'path' => 'reports.php',              'icon' => 'reports'],
            ['section' => 'Workspace', 'label' => 'Faculty Achievements', 'path' => 'faculty-achievements.php', 'icon' => 'award'],
            ['section' => 'Account',   'label' => 'Profile',              'path' => 'profile.php',              'icon' => 'user'],
        ],
        'HoD' => [
            ['section' => 'Overview',  'label' => 'Dashboard',            'path' => 'dashboard.php',            'icon' => 'dashboard'],
            ['section' => 'Overview',  'label' => 'Announcements',        'path' => 'announcements.php',        'icon' => 'megaphone', 'badge' => 'announcements'],
            ['section' => 'Workspace', 'label' => 'Upload Data',          'path' => 'upload.php',               'icon' => 'upload'],
            ['section' => 'Workspace', 'label' => 'Review Records',       'path' => 'approvals.php',            'icon' => 'approvals', 'badge' => 'approvals'],
            ['section' => 'Workspace', 'label' => 'Review Targets',       'path' => 'targets.php',              'icon' => 'target'],
            ['section' => 'Workspace', 'label' => 'Reports',              'path' => 'reports.php',              'icon' => 'reports'],
            ['section' => 'Workspace', 'label' => 'Faculty Achievements', 'path' => 'faculty-achievements.php', 'icon' => 'award'],
            ['section' => 'Manage',    'label' => 'Faculty',              'path' => 'faculty.php',              'icon' => 'graduation'],
            ['section' => 'Account',   'label' => 'Profile',              'path' => 'profile.php',              'icon' => 'user'],
        ],
        'Coordinator' => [
            ['section' => 'Overview',  'label' => 'Dashboard',            'path' => 'dashboard.php',                 'icon' => 'dashboard'],
            ['section' => 'Overview',  'label' => 'Announcements',        'path' => 'announcements.php',             'icon' => 'megaphone', 'badge' => 'announcements'],
            ['section' => 'Workspace', 'label' => 'Upload Data',          'path' => 'upload.php?reset=1',            'icon' => 'upload'],
            ['section' => 'Workspace', 'label' => 'Approvals',            'path' => 'approvals.php',                 'icon' => 'approvals', 'badge' => 'approvals'],
            ['section' => 'Workspace', 'label' => 'Review Targets',       'path' => 'targets.php',                   'icon' => 'target'],
            ['section' => 'Workspace', 'label' => 'Reports',              'path' => 'reports.php',                   'icon' => 'reports'],
            ['section' => 'Workspace', 'label' => 'Faculty Achievements', 'path' => 'faculty-achievements.php',      'icon' => 'award'],
            ['section' => 'Workspace', 'label' => 'My Report',            'path' => 'individual-faculty-report.php', 'icon' => 'file-text'],
            ['section' => 'Account',   'label' => 'Profile',              'path' => 'profile.php',                   'icon' => 'user'],
        ],
        'Faculty' => [
            ['section' => 'Overview',  'label' => 'Dashboard',            'path' => 'dashboard.php',            'icon' => 'dashboard'],
            ['section' => 'Overview',  'label' => 'Announcements',        'path' => 'announcements.php',        'icon' => 'megaphone', 'badge' => 'announcements'],
            ['section' => 'Workspace', 'label' => 'Upload Data',          'path' => 'upload.php?reset=1',       'icon' => 'upload'],
            ['section' => 'Workspace', 'label' => 'Faculty Achievements', 'path' => 'faculty-achievements.php', 'icon' => 'award'],
            ['section' => 'Workspace', 'label' => 'My Report',            'path' => 'individual-faculty-report.php', 'icon' => 'file-text'],
            ['section' => 'Account',   'label' => 'Profile',              'path' => 'profile.php',              'icon' => 'user'],
        ],
    ];

    // The staff directory is open to every role that may already open another
    // person's Faculty Details document (see can_user_view_faculty_report):
    // oversight roles browse all departments, a Coordinator their own only.
    // The HoD menu lists it above already, so it is not repeated here.
    if (in_array($role, ['Admin', 'Principal', 'Director', 'Dean', 'Coordinator'], true)) {
        $items[$role][] = [
            'section' => $role === 'Admin' ? 'Manage' : 'Workspace',
            'label'   => 'Faculty',
            'path'    => 'faculty.php',
            'icon'    => 'graduation',
        ];
    }

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
    $file   = strtok($path, '?');
    $exists = is_file(dirname(__DIR__) . '/' . $file);
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

    if ($role === 'HoD') {
        // BUG-WF-11: HoD is a reviewer only. Do not display approval counts that imply HoD approval authority.
        return $memo[$memoKey] = 0;
    }

    if ($role === 'Coordinator') {
        $targetStatuses = ['Submitted', 'Unlocked for Edit'];
    } elseif ($role === 'Dean') {
        $targetStatuses = ['Edit Requested', 'Dean Pending'];
    } else {   // Admin — everything still awaiting a decision
        $targetStatuses = ['Submitted', 'Edit Requested', 'Dean Pending', 'HOD Pending', 'Unlocked for Edit'];
    }

    $inClause = implode(',', array_fill(0, count($targetStatuses), '?'));

    $total = 0;
    foreach (record_types() as $key => $t) {
        if (!record_requires_approval($key)) {
            continue;
        }
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

    // Dean and Admin also count pending edit requests awaiting decision
    if (in_array($role, ['Dean', 'Admin'], true)) {
        try {
            $total += (int) db()->query("SELECT COUNT(*) FROM edit_requests WHERE status = 'Pending'")->fetchColumn();
        } catch (\PDOException $e) {}
    }

    return $memo[$memoKey] = $total;
}
