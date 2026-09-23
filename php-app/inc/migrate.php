<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

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
    'target_proforma_columns.sql',
    'user_role_dean.sql',
    'upload_proofs.sql',
    'user_photo.sql',
    'workflow_hierarchy.sql',
    'workflow_edit_requests.sql',
    'executive_meetings.sql',
    'department_names.sql',
];

const MIGRATIONS_DDL = "CREATE TABLE IF NOT EXISTS schema_migrations (
  filename    VARCHAR(190) NOT NULL PRIMARY KEY,
  checksum    CHAR(40)     NOT NULL,
  applied_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  duration_ms INT          NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

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

function migrations_marker_path(): string
{
    $dir = defined('UPLOAD_DIR') ? rtrim(UPLOAD_DIR, '/\\') : dirname(__DIR__) . '/uploads';
    return $dir . '/.schema-version';
}

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

function migration_statements(string $sql): array
{
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);

    // DELIMITER is a mysql client directive, not SQL, so PDO cannot run it.
    // Honour it here: a file that declares a stored procedure has semicolons
    // inside the body, and splitting on those tears the procedure apart.
    $delimiter = ';';
    $out       = [];
    $buffer    = '';

    foreach (preg_split('/\R/', $sql) as $line) {
        if (preg_match('/^\s*DELIMITER\s+(\S+)\s*$/i', $line, $m)) {
            if (trim($buffer) !== '') {
                $out[]  = trim($buffer);
                $buffer = '';
            }
            $delimiter = $m[1];
            continue;
        }

        $buffer .= $line . "
";

        while (($pos = strpos($buffer, $delimiter)) !== false) {
            $statement = trim(substr($buffer, 0, $pos));
            $buffer    = substr($buffer, $pos + strlen($delimiter));
            if ($statement !== '') {
                $out[] = $statement;
            }
        }
    }

    if (trim($buffer) !== '') {
        $out[] = trim($buffer);
    }

    return $out;
}

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
