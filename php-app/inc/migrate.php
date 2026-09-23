<?php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

/**
 * Migrations run in this order.
 *
 * Only additive, idempotent schema files belong here. Deliberately excluded:
 *
 *   schema.sql        DROPs and recreates every table — it is the first-install
 *                     bootstrap, not a migration. Running it over a populated
 *                     database destroys the contents.
 *   database.sql      a mysqldump snapshot.
 *   seed.sql, dummy_data.sql, reset_real_data.sql, meeting_report_cse.sql
 *                     data, not structure.
 *
 * migration_guard() re-checks this at run time, so adding a destructive file to
 * this list is refused rather than executed.
 */
const MIGRATION_FILES = [
    'app_settings.sql',
    'feature_flags.sql',
    'announcements.sql',
    'announcements_archive.sql',
    'password_reset_requests.sql',
    'report_template.sql',
    'report_template_proforma.sql',
    'template_align.sql',
    'template_new_types.sql',
    'target_workflow.sql',
    'target_unlock.sql',
    'target_deadline.sql',
    'upload_proofs.sql',
    'user_photo.sql',
    'workflow_hierarchy.sql',
    'workflow_edit_requests.sql',
    'executive_meetings.sql',
];

const MIGRATIONS_DDL = "CREATE TABLE IF NOT EXISTS schema_migrations (
  filename    VARCHAR(190) NOT NULL PRIMARY KEY,
  checksum    CHAR(40)     NOT NULL,
  applied_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  duration_ms INT          NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

/**
 * Refuses a statement that destroys data.
 *
 * A migration adds structure. Anything that drops a table or database, empties
 * one, or deletes rows is either a bootstrap script or a seed reset, and must
 * be run deliberately by a person who knows what is in the database — never by
 * a runner walking a directory. DROP INDEX and DROP FOREIGN KEY are allowed:
 * those are schema changes that keep the rows.
 */
function migration_guard(string $statement): ?string
{
    $probe = strtoupper(preg_replace('/\s+/', ' ', $statement));

    $banned = [
        'DROP TABLE'    => 'drops a table',
        'DROP DATABASE' => 'drops the database',
        'DROP SCHEMA'   => 'drops the schema',
        'TRUNCATE'      => 'empties a table',
        'DELETE FROM'   => 'deletes rows',
    ];

    foreach ($banned as $needle => $why) {
        if (strpos($probe, $needle) !== false) {
            return $why;
        }
    }
    return null;
}

function migrations_dir(): string
{
    return dirname(__DIR__) . '/sql';
}

/** Where the "nothing to do" marker lives, so a normal request never queries. */
function migrations_marker_path(): string
{
    $dir = defined('UPLOAD_DIR') ? rtrim(UPLOAD_DIR, '/\\') : dirname(__DIR__) . '/uploads';
    return $dir . '/.schema-version';
}

/** A fingerprint of every migration file, so editing one re-arms the runner. */
function migrations_fingerprint(): string
{
    $parts = [];
    foreach (MIGRATION_FILES as $file) {
        $path = migrations_dir() . '/' . $file;
        $parts[] = $file . ':' . (is_file($path) ? sha1_file($path) : 'missing');
    }
    return sha1(implode('|', $parts));
}

function migrations_table_ready(): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    try {
        db()->exec(MIGRATIONS_DDL);
        return $ready = true;
    } catch (\PDOException $e) {
        error_log('schema_migrations unavailable: ' . $e->getMessage());
        return $ready = false;
    }
}

/** filename => ['checksum' => ..., 'applied_at' => ...] for everything applied. */
function migrations_applied(): array
{
    if (!migrations_table_ready()) {
        return [];
    }
    try {
        $rows = db()->query('SELECT filename, checksum, applied_at FROM schema_migrations')
                    ->fetchAll(PDO::FETCH_ASSOC);
    } catch (\PDOException $e) {
        return [];
    }

    $out = [];
    foreach ($rows as $r) {
        $out[$r['filename']] = $r;
    }
    return $out;
}

/**
 * Split a migration into statements.
 *
 * These files hold plain DDL plus the PREPARE/EXECUTE trick that makes an
 * ALTER idempotent, so splitting on a semicolon at end of line is enough —
 * there are no routine bodies with their own semicolons inside.
 */
function migration_statements(string $sql): array
{
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);

    $out = [];
    foreach (preg_split('/;\s*[\r\n]+/', $sql) as $part) {
        $part = trim($part, " \t\r\n;");
        if ($part !== '') {
            $out[] = $part;
        }
    }
    return $out;
}

/** The migrations not yet recorded as applied, in manifest order. */
function migrations_pending(): array
{
    $applied = migrations_applied();

    $pending = [];
    foreach (MIGRATION_FILES as $file) {
        if (!is_file(migrations_dir() . '/' . $file)) {
            continue;
        }
        if (!isset($applied[$file])) {
            $pending[] = $file;
        }
    }
    return $pending;
}

/**
 * Apply one migration and record it.
 *
 * Each statement is idempotent by construction (CREATE TABLE IF NOT EXISTS, or
 * an ALTER guarded by information_schema), so a file that is half-applied
 * already — which is exactly the state this project was in — completes rather
 * than failing. MySQL commits DDL implicitly, so there is no transaction to
 * wrap this in; the record is written last, and a file that throws stays
 * pending and will be retried.
 */
function migration_apply(string $file): array
{
    $path = migrations_dir() . '/' . $file;
    if (!is_file($path)) {
        return [false, "{$file}: not found"];
    }
    if (!migrations_table_ready()) {
        return [false, 'schema_migrations table is unavailable'];
    }

    $sql   = (string) file_get_contents($path);
    $start = microtime(true);

    $statements = migration_statements($sql);

    // Checked before anything runs, so a destructive file is refused whole
    // rather than half-applied.
    foreach ($statements as $statement) {
        if ($why = migration_guard($statement)) {
            return [false, "{$file}: refused — it {$why}. Run it by hand if that is what you want."];
        }
    }

    try {
        foreach ($statements as $statement) {
            db()->exec($statement);
        }
    } catch (\PDOException $e) {
        return [false, "{$file}: " . $e->getMessage()];
    }

    $ms = (int) round((microtime(true) - $start) * 1000);

    try {
        $stmt = db()->prepare(
            'INSERT INTO schema_migrations (filename, checksum, duration_ms)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE checksum = VALUES(checksum), duration_ms = VALUES(duration_ms)'
        );
        $stmt->execute([$file, sha1_file($path), $ms]);
    } catch (\PDOException $e) {
        return [false, "{$file}: applied but could not be recorded — " . $e->getMessage()];
    }

    return [true, "{$file}: applied in {$ms}ms"];
}

/** Apply everything outstanding. Returns [appliedFiles, errors]. */
function migrations_run(): array
{
    $done = [];
    $errs = [];

    foreach (migrations_pending() as $file) {
        [$ok, $msg] = migration_apply($file);
        if ($ok) {
            $done[] = $msg;
        } else {
            $errs[] = $msg;
            break;   // order matters; a later file may depend on this one
        }
    }
    return [$done, $errs];
}

/**
 * The boot hook.
 *
 * A marker file records the fingerprint of the migration set that was last
 * found to be fully applied. While it matches, this costs one file read and no
 * database work at all, so the check is safe to leave in the request path.
 * Editing or adding a migration changes the fingerprint and re-arms it.
 *
 * Applying is opt-in: without MIGRATE_ON_BOOT=true the runner only records that
 * something is outstanding and leaves it alone. Schema changes against a
 * database with data in it should be a decision, not a side effect of someone
 * loading a page.
 */
function migrations_boot(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $marker      = migrations_marker_path();
    $fingerprint = migrations_fingerprint();

    if (is_file($marker) && trim((string) file_get_contents($marker)) === $fingerprint) {
        return;
    }

    if (!migrations_pending()) {
        migrations_marker_write($fingerprint);
        return;
    }

    $auto = defined('MIGRATE_ON_BOOT') ? MIGRATE_ON_BOOT : false;
    if (!$auto) {
        error_log('Pending database migrations: ' . implode(', ', migrations_pending()));
        return;
    }

    [$applied, $errors] = migrations_run();

    foreach ($applied as $line) {
        error_log('Migration ' . $line);
    }
    foreach ($errors as $line) {
        error_log('Migration FAILED ' . $line);
    }

    if (!$errors) {
        migrations_marker_write($fingerprint);
    }
}

function migrations_marker_write(string $fingerprint): void
{
    $marker = migrations_marker_path();
    $dir    = dirname($marker);

    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    @file_put_contents($marker, $fingerprint);
}

/** What the CLI and any status screen report. */
function migrations_status(): array
{
    $applied = migrations_applied();

    $rows = [];
    foreach (MIGRATION_FILES as $file) {
        $path = migrations_dir() . '/' . $file;
        $rows[] = [
            'file'       => $file,
            'present'    => is_file($path),
            'applied'    => isset($applied[$file]),
            'applied_at' => $applied[$file]['applied_at'] ?? null,
            'changed'    => isset($applied[$file]) && is_file($path)
                            && $applied[$file]['checksum'] !== sha1_file($path),
        ];
    }
    return $rows;
}
