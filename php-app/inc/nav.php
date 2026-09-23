<?php
require_once __DIR__ . '/db.php';

function navigation_for(string $role): array
{
    $items = [
        'Admin' => [
            ['section' => 'Overview',  'label' => 'Dashboard',            'path' => 'dashboard.php',            'icon' => 'dashboard'],
            ['section' => 'Overview',  'label' => 'Announcements',        'path' => 'announcements.php',        'icon' => 'megaphone', 'badge' => 'announcements'],

            ['section' => 'Overview',  'label' => 'Announcement Archive', 'path' => 'announcements-archive.php', 'icon' => 'archive'],
            ['section' => 'Workspace', 'label' => 'Approvals',            'path' => 'approvals.php',            'icon' => 'approvals', 'badge' => 'approvals'],
            ['section' => 'Workspace', 'label' => 'Academic Year',        'path' => 'academic-years.php',       'icon' => 'calendar'],
            ['section' => 'Workspace', 'label' => 'EM Schedule Manager',  'path' => 'em-schedule.php',          'icon' => 'clock'],
            ['section' => 'Workspace', 'label' => 'Review Targets',       'path' => 'targets.php',              'icon' => 'target', 'badge' => 'targets'],
            ['section' => 'Workspace', 'label' => 'Reports',              'path' => 'reports.php',              'icon' => 'reports'],
            ['section' => 'Workspace', 'label' => 'Faculty Achievements', 'path' => 'faculty-achievements.php', 'icon' => 'award'],
            ['section' => 'Manage',    'label' => 'Users',                'path' => 'users.php',                'icon' => 'users'],
            ['section' => 'Manage',    'label' => 'Departments',          'path' => 'departments.php',          'icon' => 'building'],
            ['section' => 'Manage',    'label' => 'Report Template',      'path' => 'report-template.php',      'icon' => 'reports'],

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

function group_navigation(array $items): array
{
    $groups = [];
    foreach ($items as $item) {
        $section = $item['section'] ?? 'Menu';
        $groups[$section][] = $item;
    }
    return $groups;
}

function nav_href(string $path): string
{
    $file   = strtok($path, '?');
    $exists = is_file(dirname(__DIR__) . '/' . $file);
    return $exists ? url($path) : url('coming-soon.php?page=' . urlencode($path));
}

function pending_approvals_count(array $user): int
{
    if (!in_array($user['role'], ['Admin', 'Dean', 'HoD', 'Coordinator'], true)) {
        return 0;
    }

    static $memo = [];
    $memoKey = $user['role'] . '|' . ($user['department'] ?? '');
    if (array_key_exists($memoKey, $memo)) {
        return $memo[$memoKey];
    }

    require_once __DIR__ . '/../models/Record.php';   

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
    } else {   
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

    if (in_array($role, ['Dean', 'Admin'], true)) {
        try {
            $total += (int) db()->query("SELECT COUNT(*) FROM edit_requests WHERE status = 'Pending'")->fetchColumn();
        } catch (\PDOException $e) {}
    }

    return $memo[$memoKey] = $total;
}
