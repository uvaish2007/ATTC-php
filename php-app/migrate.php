<?php

/**
 * Command line migration runner.
 *
 *   php migrate.php           what is applied and what is outstanding
 *   php migrate.php up        apply everything outstanding
 *
 * Refuses to run over the web: this applies DDL, and nothing on a page should
 * be able to trigger it by being requested.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/inc/env.php';
require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/db.php';
require_once __DIR__ . '/inc/migrate.php';

$command = $argv[1] ?? 'status';

if ($command === 'up') {
    $pending = migrations_pending();
    if (!$pending) {
        echo "Nothing to apply — the schema is up to date.\n";
        exit(0);
    }

    echo count($pending), " migration(s) to apply:\n";
    [$applied, $errors] = migrations_run();

    foreach ($applied as $line) {
        echo '  ok    ', $line, "\n";
    }
    foreach ($errors as $line) {
        echo '  FAIL  ', $line, "\n";
    }

    if (!$errors) {
        migrations_marker_write(migrations_fingerprint());
        echo "Done.\n";
        exit(0);
    }

    echo "Stopped at the first failure; the rest stay pending.\n";
    exit(1);
}

$rows    = migrations_status();
$pending = 0;

printf("%-32s %-9s %-20s %s\n", 'MIGRATION', 'STATE', 'APPLIED', 'NOTE');
foreach ($rows as $r) {
    $state = !$r['present'] ? 'missing' : ($r['applied'] ? 'applied' : 'PENDING');
    if ($state === 'PENDING') {
        $pending++;
    }
    printf(
        "%-32s %-9s %-20s %s\n",
        $r['file'],
        $state,
        $r['applied_at'] ?? '—',
        $r['changed'] ? 'file changed since it was applied' : ''
    );
}

echo "\n", $pending === 0
    ? "Schema is up to date.\n"
    : $pending . " pending. Run: php migrate.php up\n";
