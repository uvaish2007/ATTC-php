<?php

// Departments, metrics and the accounts people sign in with.
//
//   php seed-accounts.php
//
// Re-running updates rather than duplicating, so it is safe after a restore.
// CLI only: it sets passwords.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/inc/env.php';
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/db.php';

$pdo = db();

/* ---------------------------------------------------------------- departments */
$departments = [
    ['Computer Science and Engineering',             'CSE'],
    ['Computer Science and Business Systems',        'CSBS'],
    ['Artificial Intelligence and Data Science',     'AI & DS'],
    ['Information Technology',                       'IT'],
    ['Electronics and Communication Engineering',    'ECE'],
    ['Electrical and Electronics Engineering',       'EEE'],
    ['Mechanical Engineering',                       'MECH'],
    ['Civil Engineering',                            'CIVIL'],
    ['Agriculture Engineering',                      'AGRI'],
    ['Aeronautical Engineering',                     'AERO'],
    ['Marine Engineering',                           'MARINE'],
    ['Artificial Intelligence and Machine Learning', 'AIML'],
    ['Cyber Security',                               'CYBER'],
    ['Chemical Engineering',                         'CHEM'],
    ['Architecture',                                 'ARCH'],
    ['Master of Computer Applications',              'MCA'],
    ['Master of Business Administration',            'MBA'],
];

$ins = $pdo->prepare(
    'INSERT INTO departments (name, code, status) VALUES (?, ?, 1)
     ON DUPLICATE KEY UPDATE name = VALUES(name), status = 1'
);
$have = $pdo->query('SELECT code FROM departments')->fetchAll(PDO::FETCH_COLUMN);
$added = 0;
foreach ($departments as [$name, $code]) {
    if (!in_array($code, $have, true)) {
        $ins->execute([$name, $code]);
        $added++;
    }
}
echo "  departments: {$added} added, " . $pdo->query('SELECT COUNT(*) FROM departments')->fetchColumn() . " total\n";

/* -------------------------------------------------------------------- metrics */
$metrics = [
    ['Journal Publications',   'Faculty Contributions', 1],
    ['Book Publications',      'Faculty Contributions', 1],
    ['Conference Papers',      'Faculty Contributions', 1],
    ['Patents',                'Faculty Contributions', 1],
    ['FDP / Workshops',        'Activities & Outreach', 1],
    ['MoUs',                   'Activities & Outreach', 1],
    ['Events Conducted',       'Activities & Outreach', 0],
    ['NPTEL Certifications',   'Student Records',       1],
    ['Internships',            'Student Records',       1],
    ['Placements',             'Student Records',       1],
];
$mins = $pdo->prepare('INSERT INTO metrics (name, category, proof_required, status) VALUES (?, ?, ?, 1)');
$haveM = $pdo->query('SELECT name FROM metrics')->fetchAll(PDO::FETCH_COLUMN);
$addedM = 0;
foreach ($metrics as [$n, $c, $p]) {
    if (!in_array($n, $haveM, true)) { $mins->execute([$n, $c, $p]); $addedM++; }
}
echo "  metrics: {$addedM} added, " . $pdo->query('SELECT COUNT(*) FROM metrics')->fetchColumn() . " total\n";

/* --------------------------------------------------------------------- people */
function put_user(PDO $pdo, string $name, string $email, string $pass, string $role, ?string $dept): string
{
    $hash = password_hash($pass, PASSWORD_BCRYPT);

    $find = $pdo->prepare('SELECT id FROM users WHERE email = ?');
    $find->execute([$email]);
    $id = $find->fetchColumn();

    if ($id) {
        $pdo->prepare('UPDATE users SET name = ?, password = ?, role = ?, department = ?, status = 1 WHERE id = ?')
            ->execute([$name, $hash, $role, $dept, $id]);
        return 'updated';
    }

    $pdo->prepare('INSERT INTO users (name, email, password, role, department, status) VALUES (?, ?, ?, ?, ?, 1)')
        ->execute([$name, $email, $hash, $role, $dept]);
    return 'created';
}

// The five the user named, plus a Dean the login screen has always offered.
$core = [
    ['Mohamed Uvaish',    'mohameduvaish132@gmail.com', 'uvaish123',  'Admin',       null],
    ['Principal',         'director@atts.edu',          'director123','Director',    null],
    ['Dean Academics',    'dean@atts.edu',              'dean1234',   'Dean',        null],
    ['Head of Department','hod@atts.edu',               'hod123',     'HoD',         'CSBS'],
    ['IQAC Coordinator',  'coordinator@atts.edu',       'coord1234',  'Coordinator', 'CSBS'],
    ['Faculty Member',    'faculty@atts.edu',           'faculty123', 'Faculty',     'CSBS'],
];

echo "\n  core accounts\n";
foreach ($core as [$n, $e, $p, $r, $d]) {
    $what = put_user($pdo, $n, $e, $p, $r, $d);
    printf("    %-9s %-28s %-12s %-9s %s\n", $what, $e, $r, $d ?? '—', $p);
}

/* The master account: one per role, all sharing master@atts.edu / master123
   through the alias in inc/auth.php. */
$master = [
    ['Master (Admin)',       'master.admin@atts.edu',       'Admin',       null],
    ['Master (Principal)',   'master.principal@atts.edu',   'Director',    null],
    ['Master (Dean)',        'master.dean@atts.edu',        'Dean',        null],
    ['Master (HoD)',         'master.hod@atts.edu',         'HoD',         'CSBS'],
    ['Master (Coordinator)', 'master.coordinator@atts.edu', 'Coordinator', 'CSBS'],
    ['Master (Faculty)',     'master.faculty@atts.edu',     'Faculty',     'CSBS'],
];

echo "\n  master account (all reached as master@atts.edu / master123)\n";
foreach ($master as [$n, $e, $r, $d]) {
    $what = put_user($pdo, $n, $e, 'master123', $r, $d);
    printf("    %-9s %-30s %-12s %s\n", $what, $e, $r, $d ?? '—');
}

/* Per-department HoD, Coordinator and Faculty. */
$deptCodes = $pdo->query('SELECT code FROM departments ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
$perDept = 0;
foreach ($deptCodes as $code) {
    $slug = strtolower(str_replace([' ', '&'], ['', ''], $code));
    foreach ([['HoD', 'hod', 'hod123'], ['Coordinator', 'coord', 'coord1234'], ['Faculty', 'fac', 'faculty123']] as [$role, $tag, $pw]) {
        put_user($pdo, "{$code} {$role}", "{$slug}.{$tag}@atts.edu", $pw, $role, $code);
        $perDept++;
    }
}
echo "\n  per-department accounts: {$perDept} across " . count($deptCodes) . " departments\n";

echo "\n  users total: " . $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() . "\n";
foreach ($pdo->query('SELECT role, COUNT(*) c FROM users GROUP BY role')->fetchAll(PDO::FETCH_ASSOC) as $r) {
    printf("    %-12s %d\n", $r['role'], $r['c']);
}
