<?php
/**
 * ATTS-METRIC-STUDIO — Core Administrative Metric Management Engine
 *
 * Handles database schema migration, metric configuration persistence,
 * dynamic metric resolution for faculty/coordinator portals, form template
 * builder schemas, and generic record submissions.
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/upload_sections.php';

/**
 * Ensures the MySQL schema for Metric Studio is fully up to date.
 * Automatically adds missing columns to `metrics`, ensures `generic_metric_records`
 * table exists, and synchronizes the 30 approved institutional targets into the DB.
 */
function ensure_metric_studio_schema(): void
{
    static $initialized = false;
    if ($initialized) {
        return;
    }

    $pdo = db();

    // 1. Inspect existing columns in `metrics` table
    $existingCols = [];
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM `metrics`");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $col) {
            $existingCols[strtolower($col['Field'])] = $col;
        }
    } catch (Throwable $e) {
        // Table doesn't exist yet, create base table
        $pdo->exec("CREATE TABLE IF NOT EXISTS `metrics` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `name` VARCHAR(255) NOT NULL,
            `category` VARCHAR(100) NOT NULL,
            `proof_required` TINYINT(1) NOT NULL DEFAULT 0,
            `status` TINYINT(1) NOT NULL DEFAULT 1,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        
        $stmt = $pdo->query("SHOW COLUMNS FROM `metrics`");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $col) {
            $existingCols[strtolower($col['Field'])] = $col;
        }
    }

    // 2. Add missing columns safely
    $alters = [];
    if (!isset($existingCols['metric_key'])) {
        $alters[] = "ADD COLUMN `metric_key` VARCHAR(64) NULL AFTER `id`";
    }
    if (!isset($existingCols['description'])) {
        $alters[] = "ADD COLUMN `description` TEXT NULL AFTER `category`";
    }
    if (!isset($existingCols['help_text'])) {
        $alters[] = "ADD COLUMN `help_text` TEXT NULL AFTER `description`";
    }
    if (!isset($existingCols['display_order'])) {
        $alters[] = "ADD COLUMN `display_order` INT NOT NULL DEFAULT 1 AFTER `help_text`";
    }
    if (!isset($existingCols['faculty_visible'])) {
        $alters[] = "ADD COLUMN `faculty_visible` TINYINT(1) NOT NULL DEFAULT 1 AFTER `display_order`";
    }
    if (!isset($existingCols['coordinator_visible'])) {
        $alters[] = "ADD COLUMN `coordinator_visible` TINYINT(1) NOT NULL DEFAULT 1 AFTER `faculty_visible`";
    }
    if (!isset($existingCols['hod_visible'])) {
        $alters[] = "ADD COLUMN `hod_visible` TINYINT(1) NOT NULL DEFAULT 1 AFTER `coordinator_visible`";
    }
    if (!isset($existingCols['metric_type'])) {
        $alters[] = "ADD COLUMN `metric_type` VARCHAR(32) NOT NULL DEFAULT 'specialized' AFTER `proof_required`";
    }
    if (!isset($existingCols['default_type'])) {
        $alters[] = "ADD COLUMN `default_type` VARCHAR(64) NULL AFTER `metric_type`";
    }
    if (!isset($existingCols['supported_types'])) {
        $alters[] = "ADD COLUMN `supported_types` TEXT NULL AFTER `default_type`";
    }
    if (!isset($existingCols['subcategories_config'])) {
        $alters[] = "ADD COLUMN `subcategories_config` LONGTEXT NULL AFTER `supported_types`";
    }
    if (!isset($existingCols['form_config'])) {
        $alters[] = "ADD COLUMN `form_config` LONGTEXT NULL AFTER `subcategories_config`";
    }
    if (!isset($existingCols['reporting_category'])) {
        $alters[] = "ADD COLUMN `reporting_category` VARCHAR(100) NULL AFTER `form_config`";
    }

    if (!empty($alters)) {
        $pdo->exec("ALTER TABLE `metrics` " . implode(', ', $alters));
    }

    // Drop unique index on name if it exists so display names can be edited freely without collision
    try {
        $indexes = $pdo->query("SHOW INDEX FROM `metrics` WHERE Key_name = 'name'")->fetchAll();
        if (!empty($indexes)) {
            $pdo->exec("ALTER TABLE `metrics` DROP INDEX `name`");
        }
    } catch (Throwable $e) {}

    // Ensure metric_key unique index exists
    try {
        $keyIndex = $pdo->query("SHOW INDEX FROM `metrics` WHERE Key_name = 'idx_metric_key'")->fetchAll();
        if (empty($keyIndex)) {
            $pdo->exec("ALTER TABLE `metrics` ADD UNIQUE INDEX `idx_metric_key` (`metric_key`)");
        }
    } catch (Throwable $e) {}

    // 3. Ensure generic_metric_records table exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS `generic_metric_records` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `metric_key` VARCHAR(64) NOT NULL,
        `academic_year` VARCHAR(20) NOT NULL,
        `department` VARCHAR(50) NOT NULL,
        `user_id` INT NOT NULL,
        `title` VARCHAR(255) NOT NULL,
        `data_json` LONGTEXT NOT NULL,
        `proof_file` VARCHAR(255) NULL,
        `status` VARCHAR(30) NOT NULL DEFAULT 'Submitted',
        `remarks` TEXT NULL,
        `submitted_at` DATETIME NULL,
        `approved_at` DATETIME NULL,
        `approved_by` INT NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX `idx_gen_metric_key` (`metric_key`),
        INDEX `idx_gen_academic_year` (`academic_year`),
        INDEX `idx_gen_dept` (`department`),
        INDEX `idx_gen_user` (`user_id`),
        INDEX `idx_gen_status` (`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Clean any legacy unkeyed rows
    $pdo->exec("DELETE FROM `metrics` WHERE `metric_key` IS NULL OR `metric_key` = ''");

    // 4. Migrate and synchronize the 30 approved institutional targets into DB
    metric_studio_sync_approved_targets();

    $initialized = true;
}

/**
 * Synchronizes the 30 canonical institutional targets into the `metrics` table.
 * If a metric key is missing, it inserts it. If present, it ensures baseline attributes exist.
 */
function metric_studio_sync_approved_targets(): void
{
    $pdo = db();
    $builtIn = upload_approved_sections_built_in();

    // Check existing metric_keys in DB
    $existingStmt = $pdo->query("SELECT id, metric_key, name FROM `metrics`");
    $existing = [];
    $nullKeyRows = [];
    foreach ($existingStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if (!empty($r['metric_key'])) {
            $existing[$r['metric_key']] = (int)$r['id'];
        } else {
            $nullKeyRows[$r['name']] = (int)$r['id'];
        }
    }

    $insertStmt = $pdo->prepare("INSERT INTO `metrics` (
        `metric_key`, `name`, `category`, `description`, `help_text`,
        `display_order`, `faculty_visible`, `coordinator_visible`, `hod_visible`,
        `proof_required`, `status`, `metric_type`, `default_type`,
        `supported_types`, `subcategories_config`, `form_config`, `reporting_category`
    ) VALUES (?, ?, ?, ?, ?, ?, 1, 1, 1, 1, 1, ?, ?, ?, ?, ?, ?)");

    $updateKeyStmt = $pdo->prepare("UPDATE `metrics` SET
        `metric_key` = ?, `category` = ?, `description` = ?, `display_order` = ?,
        `metric_type` = ?, `default_type` = ?, `supported_types` = ?, `subcategories_config` = ?
        WHERE `id` = ?");

    foreach ($builtIn as $secKey => $sec) {
        $subcatsJson = !empty($sec['subcategories']) ? json_encode($sec['subcategories'], JSON_UNESCAPED_UNICODE) : null;
        $typesJson   = !empty($sec['types']) ? json_encode($sec['types'], JSON_UNESCAPED_UNICODE) : null;
        $metricType  = !empty($sec['subcategories']) ? 'hybrid' : 'specialized';
        $formConfig  = null;

        if (isset($existing[$secKey])) {
            // Already synced, preserve admin edits!
            continue;
        }

        // Check if legacy row with similar name exists without metric_key
        $matchedLegacyId = null;
        foreach ($nullKeyRows as $legacyName => $legacyId) {
            if (stripos($legacyName, $sec['title']) !== false || stripos($sec['title'], $legacyName) !== false) {
                $matchedLegacyId = $legacyId;
                unset($nullKeyRows[$legacyName]);
                break;
            }
        }

        if ($matchedLegacyId !== null) {
            $updateKeyStmt->execute([
                $secKey,
                $sec['group'],
                $sec['description'],
                (int)$sec['order'],
                $metricType,
                $sec['default_type'],
                $typesJson,
                $subcatsJson,
                $matchedLegacyId
            ]);
            $existing[$secKey] = $matchedLegacyId;
        } else {
            // Insert cleanly
            $insertStmt->execute([
                $secKey,
                $sec['title'],
                $sec['group'],
                $sec['description'],
                'Submit faculty academic records with relevant proof documents.',
                (int)$sec['order'],
                $metricType,
                $sec['default_type'],
                $typesJson,
                $subcatsJson,
                $formConfig,
                $sec['group']
            ]);
            $existing[$secKey] = (int)$pdo->lastInsertId();
        }
    }
}

/**
 * Returns all metrics with optional filtering and sorting.
 *
 * @param array{q?: string, status?: string, category?: string, sort?: string} $filters
 * @return array<int, array>
 */
function metric_studio_all(array $filters = []): array
{
    ensure_metric_studio_schema();
    $pdo = db();

    $where = [];
    $params = [];

    // Search query
    if (!empty($filters['q'])) {
        $q = trim((string)$filters['q']);
        $where[] = "(`name` LIKE ? OR `metric_key` LIKE ? OR `description` LIKE ?)";
        $params[] = "%{$q}%";
        $params[] = "%{$q}%";
        $params[] = "%{$q}%";
    }

    // Status filter
    if (isset($filters['status']) && $filters['status'] !== '' && $filters['status'] !== 'all') {
        $statusVal = ($filters['status'] === 'active' || $filters['status'] === '1') ? 1 : 0;
        $where[] = "`status` = ?";
        $params[] = $statusVal;
    }

    // Category / Group filter
    if (!empty($filters['category']) && $filters['category'] !== 'all') {
        $where[] = "`category` = ?";
        $params[] = trim((string)$filters['category']);
    }

    $sql = "SELECT * FROM `metrics`";
    if (!empty($where)) {
        $sql .= " WHERE " . implode(' AND ', $where);
    }

    // Sorting
    $sort = $filters['sort'] ?? 'order';
    switch ($sort) {
        case 'name':
            $sql .= " ORDER BY `name` ASC";
            break;
        case 'category':
            $sql .= " ORDER BY `category` ASC, `display_order` ASC";
            break;
        case 'updated':
            $sql .= " ORDER BY `updated_at` DESC";
            break;
        case 'order':
        default:
            $sql .= " ORDER BY `display_order` ASC, `id` ASC";
            break;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Decode JSON configs
    foreach ($rows as &$r) {
        $r['display_order']       = (int)($r['display_order'] ?? 0);
        $r['status']              = (int)($r['status'] ?? 1);
        $r['faculty_visible']     = (int)($r['faculty_visible'] ?? 1);
        $r['coordinator_visible'] = (int)($r['coordinator_visible'] ?? 1);
        $r['hod_visible']         = (int)($r['hod_visible'] ?? 1);
        $r['proof_required']      = (int)($r['proof_required'] ?? 0);
        $r['form_config_parsed']  = !empty($r['form_config']) ? json_decode($r['form_config'], true) : [];
        $r['subcategories_parsed']= !empty($r['subcategories_config']) ? json_decode($r['subcategories_config'], true) : [];
        $r['types_parsed']        = !empty($r['supported_types']) ? json_decode($r['supported_types'], true) : [];
        $r['fields_count']        = is_array($r['form_config_parsed']) ? count($r['form_config_parsed']) : 0;
    }
    unset($r);

    return $rows;
}

/**
 * Finds a single metric by ID or unique key.
 */
function metric_studio_find(string|int $idOrKey): ?array
{
    ensure_metric_studio_schema();
    $pdo = db();

    if (is_numeric($idOrKey)) {
        $stmt = $pdo->prepare("SELECT * FROM `metrics` WHERE `id` = ? LIMIT 1");
        $stmt->execute([(int)$idOrKey]);
    } else {
        $stmt = $pdo->prepare("SELECT * FROM `metrics` WHERE `metric_key` = ? OR `id` = ? LIMIT 1");
        $stmt->execute([(string)$idOrKey, (int)$idOrKey]);
    }

    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }

    $row['display_order']       = (int)($row['display_order'] ?? 0);
    $row['status']              = (int)($row['status'] ?? 1);
    $row['faculty_visible']     = (int)($row['faculty_visible'] ?? 1);
    $row['coordinator_visible'] = (int)($row['coordinator_visible'] ?? 1);
    $row['hod_visible']         = (int)($row['hod_visible'] ?? 1);
    $row['form_config_parsed']  = !empty($row['form_config']) ? json_decode($row['form_config'], true) : [];
    $row['subcategories_parsed']= !empty($row['subcategories_config']) ? json_decode($row['subcategories_config'], true) : [];
    $row['types_parsed']        = !empty($row['supported_types']) ? json_decode($row['supported_types'], true) : [];
    $row['fields_count']        = is_array($row['form_config_parsed']) ? count($row['form_config_parsed']) : 0;

    return $row;
}

/**
 * Creates a new metric.
 *
 * @param array{
 *   name: string,
 *   metric_key?: string,
 *   category: string,
 *   description?: string,
 *   help_text?: string,
 *   display_order?: int,
 *   metric_type?: string,
 *   faculty_visible?: int,
 *   coordinator_visible?: int,
 *   form_config?: array
 * } $data
 * @return array{0: bool, 1: string, 2: ?array}
 */
function metric_studio_create(array $data): array
{
    ensure_metric_studio_schema();
    $pdo = db();

    $name     = trim((string)($data['name'] ?? ''));
    $category = trim((string)($data['category'] ?? ''));

    if ($name === '' || $category === '') {
        return [false, 'Metric name and category are required.', null];
    }

    // Determine or generate unique metric_key
    $rawKey = trim((string)($data['metric_key'] ?? ''));
    if ($rawKey === '') {
        $rawKey = strtolower(preg_replace('/[^a-z0-9]+/i', '_', $name));
        $rawKey = trim($rawKey, '_');
    } else {
        $rawKey = strtolower(preg_replace('/[^a-z0-9_]+/i', '_', $rawKey));
        $rawKey = trim($rawKey, '_');
    }

    if ($rawKey === '') {
        $rawKey = 'metric_' . time();
    }

    // Check duplicate key
    $stmt = $pdo->prepare("SELECT id FROM `metrics` WHERE `metric_key` = ?");
    $stmt->execute([$rawKey]);
    if ($stmt->fetch()) {
        return [false, "The metric identifier '{$rawKey}' is already in use. Please specify a unique key.", null];
    }

    // Determine display order
    $order = isset($data['display_order']) && (int)$data['display_order'] > 0
        ? (int)$data['display_order']
        : (int)$pdo->query("SELECT COALESCE(MAX(`display_order`), 0) + 1 FROM `metrics`")->fetchColumn();

    $description = trim((string)($data['description'] ?? ''));
    $helpText    = trim((string)($data['help_text'] ?? 'Submit academic data according to institutional guidelines.'));
    $metricType  = in_array($data['metric_type'] ?? '', ['specialized', 'generic', 'hybrid'], true) ? $data['metric_type'] : 'generic';
    $facVis      = isset($data['faculty_visible']) ? (int)$data['faculty_visible'] : 1;
    $coordVis    = isset($data['coordinator_visible']) ? (int)$data['coordinator_visible'] : 1;
    $hodVis      = isset($data['hod_visible']) ? (int)$data['hod_visible'] : 1;
    $status      = isset($data['status']) ? (int)$data['status'] : 1;

    // Default form configuration for generic metrics if none provided
    $formConfig = $data['form_config'] ?? null;
    if ($formConfig === null && $metricType === 'generic') {
        $formConfig = [
            [
                'key'         => 'title',
                'label'       => 'Title / Details',
                'type'        => 'text',
                'placeholder' => 'Enter title or name...',
                'help_text'   => 'Primary identifier for this entry.',
                'required'    => true,
            ],
            [
                'key'         => 'description',
                'label'       => 'Description / Summary',
                'type'        => 'textarea',
                'placeholder' => 'Detailed summary of activity or achievement...',
                'help_text'   => 'Include relevant dates, outcomes, or notes.',
                'required'    => false,
            ],
            [
                'key'         => 'event_date',
                'label'       => 'Date of Occurrence / Completion',
                'type'        => 'date',
                'placeholder' => 'YYYY-MM-DD',
                'help_text'   => 'Date related to this achievement.',
                'required'    => true,
            ],
            [
                'key'         => 'proof_file',
                'label'       => 'Supporting Proof (PDF, max 2MB)',
                'type'        => 'file',
                'placeholder' => 'Choose PDF proof',
                'help_text'   => 'Upload official certificate, order, or document.',
                'required'    => true,
            ]
        ];
    }

    $formJson = is_array($formConfig) ? json_encode($formConfig, JSON_UNESCAPED_UNICODE) : null;
    $defaultType = 'gen_' . $rawKey;
    $typesJson = json_encode([$defaultType], JSON_UNESCAPED_UNICODE);

    $insert = $pdo->prepare("INSERT INTO `metrics` (
        `metric_key`, `name`, `category`, `description`, `help_text`,
        `display_order`, `faculty_visible`, `coordinator_visible`, `hod_visible`,
        `proof_required`, `status`, `metric_type`, `default_type`,
        `supported_types`, `form_config`, `reporting_category`
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?)");

    $insert->execute([
        $rawKey,
        $name,
        $category,
        $description,
        $helpText,
        $order,
        $facVis,
        $coordVis,
        $hodVis,
        $status,
        $metricType,
        $defaultType,
        $typesJson,
        $formJson,
        $category
    ]);

    $newId = (int)$pdo->lastInsertId();
    $created = metric_studio_find($newId);

    return [true, "Metric '{$name}' created successfully.", $created];
}

/**
 * Updates an existing metric's configurable properties.
 * Note: `metric_key` is strictly preserved to maintain data relationships.
 */
function metric_studio_update(string|int $idOrKey, array $data): array
{
    ensure_metric_studio_schema();
    $pdo = db();

    $metric = metric_studio_find($idOrKey);
    if (!$metric) {
        return [false, 'Metric not found.'];
    }

    $name = trim((string)($data['name'] ?? $metric['name']));
    $category = trim((string)($data['category'] ?? $metric['category']));

    if ($name === '' || $category === '') {
        return [false, 'Metric name and category cannot be empty.'];
    }

    $description = isset($data['description']) ? trim((string)$data['description']) : $metric['description'];
    $helpText    = isset($data['help_text']) ? trim((string)$data['help_text']) : $metric['help_text'];
    $order       = isset($data['display_order']) ? (int)$data['display_order'] : (int)$metric['display_order'];
    $facVis      = isset($data['faculty_visible']) ? (int)$data['faculty_visible'] : (int)$metric['faculty_visible'];
    $coordVis    = isset($data['coordinator_visible']) ? (int)$data['coordinator_visible'] : (int)$metric['coordinator_visible'];
    $hodVis      = isset($data['hod_visible']) ? (int)$data['hod_visible'] : (int)$metric['hod_visible'];
    $status      = isset($data['status']) ? (int)$data['status'] : (int)$metric['status'];
    $reporting   = isset($data['reporting_category']) ? trim((string)$data['reporting_category']) : $category;

    $stmt = $pdo->prepare("UPDATE `metrics` SET
        `name` = ?,
        `category` = ?,
        `description` = ?,
        `help_text` = ?,
        `display_order` = ?,
        `faculty_visible` = ?,
        `coordinator_visible` = ?,
        `hod_visible` = ?,
        `status` = ?,
        `reporting_category` = ?
        WHERE `id` = ?");

    $stmt->execute([
        $name,
        $category,
        $description,
        $helpText,
        $order,
        $facVis,
        $coordVis,
        $hodVis,
        $status,
        $reporting,
        (int)$metric['id']
    ]);

    return [true, "Metric '{$name}' configuration updated successfully."];
}

/**
 * Toggles a metric's active/inactive status.
 */
function metric_studio_toggle_status(string|int $idOrKey): array
{
    ensure_metric_studio_schema();
    $pdo = db();

    $metric = metric_studio_find($idOrKey);
    if (!$metric) {
        return [false, 'Metric not found.', 0];
    }

    $newStatus = $metric['status'] === 1 ? 0 : 1;
    $stmt = $pdo->prepare("UPDATE `metrics` SET `status` = ? WHERE `id` = ?");
    $stmt->execute([$newStatus, (int)$metric['id']]);

    $action = $newStatus === 1 ? 'activated' : 'deactivated';
    return [true, "Metric '{$metric['name']}' has been {$action}.", $newStatus];
}

/**
 * Permanently deletes a metric, its generic records, and re-sequences display orders.
 *
 * @param string|int $idOrKey
 * @return array{0: bool, 1: string}
 */
function metric_studio_delete(string|int $idOrKey): array
{
    ensure_metric_studio_schema();
    $pdo = db();

    $metric = metric_studio_find($idOrKey);
    if (!$metric) {
        return [false, 'Metric not found.'];
    }

    $metricId = (int)$metric['id'];
    $metricKey = $metric['metric_key'];
    $metricName = $metric['name'];
    $deletedOrder = (int)$metric['display_order'];

    // 1. Clean up generic records and proof files if generic metric
    if (($metric['metric_type'] ?? '') === 'generic') {
        try {
            $stmt = $pdo->prepare("SELECT `proof_file` FROM `generic_metric_records` WHERE `metric_key` = ?");
            $stmt->execute([$metricKey]);
            $proofs = $stmt->fetchAll(PDO::FETCH_COLUMN);

            $baseFolder = rtrim(UPLOAD_DIR, '/\\');
            foreach ($proofs as $p) {
                if (!empty($p)) {
                    $fullP = $baseFolder . '/' . $p;
                    if (is_file($fullP)) @unlink($fullP);
                }
            }

            $delRecs = $pdo->prepare("DELETE FROM `generic_metric_records` WHERE `metric_key` = ?");
            $delRecs->execute([$metricKey]);
        } catch (\Throwable $e) {
            // Ignore generic table cleanup errors
        }
    }

    // 2. Delete the metric from metrics table
    $del = $pdo->prepare("DELETE FROM `metrics` WHERE `id` = ?");
    $del->execute([$metricId]);

    // 3. Re-sequence display orders so remaining metrics stay sequential (1, 2, 3...)
    try {
        $reseq = $pdo->prepare("UPDATE `metrics` SET `display_order` = `display_order` - 1 WHERE `display_order` > ?");
        $reseq->execute([$deletedOrder]);
    } catch (\Throwable $e) {}

    // 4. Invalidate upload_approved_sections static cache if loaded
    if (function_exists('upload_approved_sections')) {
        upload_approved_sections(null, true);
    }

    return [true, "Metric '{$metricName}' has been permanently deleted."];
}

/**
 * Moves a metric up or down in display order by swapping with its neighbor.
 */
function metric_studio_move(string|int $idOrKey, string $direction): array
{
    ensure_metric_studio_schema();
    $pdo = db();

    $all = metric_studio_all(['sort' => 'order']);
    $idx = null;
    foreach ($all as $i => $m) {
        if ($m['id'] == $idOrKey || $m['metric_key'] === $idOrKey) {
            $idx = $i;
            break;
        }
    }

    if ($idx === null) {
        return [false, 'Metric not found.'];
    }

    $swapIdx = ($direction === 'up') ? $idx - 1 : $idx + 1;
    if ($swapIdx < 0 || $swapIdx >= count($all)) {
        return [false, 'Cannot move further in that direction.'];
    }

    $curr = $all[$idx];
    $swap = $all[$swapIdx];

    // Normalize display orders if they were equal
    $order1 = $swap['display_order'];
    $order2 = $curr['display_order'];
    if ($order1 === $order2) {
        $order1 = $direction === 'up' ? max(1, $order2 - 1) : $order2 + 1;
    }

    $stmt = $pdo->prepare("UPDATE `metrics` SET `display_order` = ? WHERE `id` = ?");
    $stmt->execute([$order1, (int)$curr['id']]);
    $stmt->execute([$order2, (int)$swap['id']]);

    return [true, "Display order updated for '{$curr['name']}'."];
}

/**
 * Persists an exact list of ordered metric keys or IDs.
 *
 * @param array<int, string|int> $orderedKeys
 * @return array{0: bool, 1: string}
 */
function metric_studio_reorder(array $orderedKeys): array
{
    ensure_metric_studio_schema();
    $pdo = db();

    $stmt = $pdo->prepare("UPDATE `metrics` SET `display_order` = ? WHERE `metric_key` = ? OR `id` = ?");
    $order = 1;
    foreach ($orderedKeys as $keyOrId) {
        $stmt->execute([$order, (string)$keyOrId, (int)$keyOrId]);
        $order++;
    }

    return [true, 'Metric display order re-sequenced successfully.'];
}

/**
 * Saves a form configuration template for a metric.
 *
 * @param string|int $idOrKey
 * @param array<int, array> $fields
 * @return array{0: bool, 1: string}
 */
function metric_studio_save_form_template(string|int $idOrKey, array $fields): array
{
    ensure_metric_studio_schema();
    $pdo = db();

    $metric = metric_studio_find($idOrKey);
    if (!$metric) {
        return [false, 'Metric not found.'];
    }

    $cleaned = [];
    $order = 1;
    foreach ($fields as $f) {
        $key = trim((string)($f['key'] ?? ''));
        $label = trim((string)($f['label'] ?? ''));
        $type = trim((string)($f['type'] ?? 'text'));

        if ($key === '' || $label === '') {
            continue;
        }

        // Validate key characters
        $key = strtolower(preg_replace('/[^a-z0-9_]+/i', '_', $key));

        $cleaned[] = [
            'order'        => $order++,
            'key'          => $key,
            'label'        => $label,
            'type'         => in_array($type, [
                'text', 'textarea', 'integer', 'decimal', 'date',
                'select', 'radio', 'checkbox', 'url', 'file', 'readonly'
            ], true) ? $type : 'text',
            'placeholder'  => trim((string)($f['placeholder'] ?? '')),
            'help_text'    => trim((string)($f['help_text'] ?? '')),
            'required'     => !empty($f['required']),
            'min'          => isset($f['min']) && $f['min'] !== '' ? (float)$f['min'] : null,
            'max'          => isset($f['max']) && $f['max'] !== '' ? (float)$f['max'] : null,
            'options'      => !empty($f['options']) ? (is_array($f['options']) ? $f['options'] : array_map('trim', explode(',', (string)$f['options']))) : [],
            'allowed_types'=> !empty($f['allowed_types']) ? (is_array($f['allowed_types']) ? $f['allowed_types'] : array_map('trim', explode(',', (string)$f['allowed_types']))) : ['pdf'],
            'max_file_mb'  => !empty($f['max_file_mb']) ? (int)$f['max_file_mb'] : 2,
        ];
    }

    if (empty($cleaned)) {
        return [false, 'Form template must include at least one valid field.'];
    }

    $json = json_encode($cleaned, JSON_UNESCAPED_UNICODE);
    $newType = ($metric['metric_type'] === 'specialized') ? 'hybrid' : $metric['metric_type'];

    $stmt = $pdo->prepare("UPDATE `metrics` SET `form_config` = ?, `metric_type` = ? WHERE `id` = ?");
    $stmt->execute([$json, $newType, (int)$metric['id']]);

    return [true, "Form template saved with " . count($cleaned) . " fields."];
}

/**
 * Computes dashboard summary statistics for metrics.
 */
function metric_studio_summary(): array
{
    ensure_metric_studio_schema();
    $pdo = db();

    $total = (int)$pdo->query("SELECT COUNT(*) FROM `metrics`")->fetchColumn();
    $active = (int)$pdo->query("SELECT COUNT(*) FROM `metrics` WHERE `status` = 1")->fetchColumn();
    $inactive = $total - $active;

    // Recently added/updated in the last 30 days
    $recent = (int)$pdo->query("SELECT COUNT(*) FROM `metrics` WHERE `created_at` >= DATE_SUB(NOW(), INTERVAL 30 DAY)")->fetchColumn();

    // Generic vs Specialized breakdown
    $genericCount = (int)$pdo->query("SELECT COUNT(*) FROM `metrics` WHERE `metric_type` = 'generic'")->fetchColumn();

    return [
        'total'          => $total,
        'active'         => $active,
        'inactive'       => $inactive,
        'recently_added' => $recent,
        'generic_count'  => $genericCount,
    ];
}

/**
 * Returns a list of distinct metric categories/groups.
 */
function metric_studio_categories(): array
{
    ensure_metric_studio_schema();
    $pdo = db();

    $stmt = $pdo->query("SELECT DISTINCT `category` FROM `metrics` WHERE `category` IS NOT NULL AND `category` != '' ORDER BY `category` ASC");
    return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
}

/**
 * Submits a new record for a generic metric.
 *
 * @param string $metricKey
 * @param array $user
 * @param string $academicYear
 * @param array $postData
 * @param array|null $fileData
 * @return array{0: bool, 1: string, 2: ?int}
 */
function generic_metric_record_submit(string $metricKey, array $user, string $academicYear, array $postData, ?array $fileData = null): array
{
    ensure_metric_studio_schema();
    $pdo = db();

    $metric = metric_studio_find($metricKey);
    if (!$metric) {
        return [false, 'Target metric not found.', null];
    }

    $fields = $metric['form_config_parsed'] ?: [];
    $dataValues = [];
    $title = '';

    // Handle and validate fields
    foreach ($fields as $field) {
        $k = $field['key'];
        $val = $postData[$k] ?? null;

        if ($field['type'] === 'file') {
            continue; // Handled separately below
        }

        if (!empty($field['required']) && ($val === null || trim((string)$val) === '')) {
            return [false, "The field '{$field['label']}' is required.", null];
        }

        if ($val !== null) {
            $dataValues[$k] = is_array($val) ? $val : trim((string)$val);
            if (empty($title) && !is_array($val) && trim((string)$val) !== '') {
                $title = trim((string)$val);
            }
        }
    }

    if (empty($title)) {
        $title = $metric['name'] . ' Entry #' . time();
    }

    // Handle proof upload if file field exists
    $proofPath = null;
    if ($fileData && !empty($fileData['tmp_name']) && is_uploaded_file($fileData['tmp_name'])) {
        $fileExt = strtolower(pathinfo($fileData['name'] ?? '', PATHINFO_EXTENSION));
        if ($fileExt !== 'pdf') {
            return [false, 'The uploaded proof must be a PDF document (.pdf).', null];
        }
        if ($fileData['size'] > (2 * 1024 * 1024)) {
            return [false, 'The proof file must not exceed 2 MB.', null];
        }

        $baseFolder = rtrim(UPLOAD_DIR, '/\\');
        $proofsFolder = $baseFolder . '/proofs';
        if (!is_dir($proofsFolder)) {
            @mkdir($proofsFolder, 0775, true);
        }

        $uniqueId = bin2hex(random_bytes(8));
        $stored = "gen_{$metricKey}_{$uniqueId}_" . time() . ".pdf";
        $destPath = $baseFolder . '/' . $stored;

        if (move_uploaded_file($fileData['tmp_name'], $destPath)) {
            $proofPath = $stored;
        } else {
            return [false, 'Failed to save uploaded proof to storage.', null];
        }
    }

    $json = json_encode($dataValues, JSON_UNESCAPED_UNICODE);
    $dept = $user['department'] ?? 'General';
    $userId = (int)$user['id'];

    $stmt = $pdo->prepare("INSERT INTO `generic_metric_records` (
        `metric_key`, `academic_year`, `department`, `user_id`, `title`,
        `data_json`, `proof_file`, `status`, `submitted_at`
    ) VALUES (?, ?, ?, ?, ?, ?, ?, 'Submitted', NOW())");

    $stmt->execute([
        $metricKey,
        $academicYear,
        $dept,
        $userId,
        $title,
        $json,
        $proofPath
    ]);

    $recordId = (int)$pdo->lastInsertId();

    // Log in audit log if table exists
    try {
        $pdo->prepare("INSERT INTO `workflow_audit_logs` (`record_id`, `record_type`, `action`, `actor_id`, `actor_role`, `details`, `created_at`)
            VALUES (?, ?, 'submit', ?, ?, 'Submitted generic metric record', NOW())")
            ->execute([$recordId, 'gen_' . $metricKey, $userId, $user['role'] ?? 'Faculty']);
    } catch (Throwable $e) {}

    return [true, "Record submitted successfully.", $recordId];
}

/**
 * Returns generic metric submissions for a given user or department.
 */
function generic_metric_records_for(string $metricKey, ?int $userId = null, ?string $academicYear = null): array
{
    ensure_metric_studio_schema();
    $pdo = db();

    $where = ["`metric_key` = ?"];
    $params = [$metricKey];

    if ($userId !== null) {
        $where[] = "`user_id` = ?";
        $params[] = $userId;
    }

    if (!empty($academicYear)) {
        $where[] = "`academic_year` = ?";
        $params[] = $academicYear;
    }

    $sql = "SELECT r.*, u.name as user_name FROM `generic_metric_records` r
            LEFT JOIN `users` u ON r.user_id = u.id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY r.id DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$r) {
        $r['data_parsed'] = !empty($r['data_json']) ? json_decode($r['data_json'], true) : [];
    }
    unset($r);

    return $rows;
}

/**
 * Deletes a generic metric record (if allowed).
 */
function generic_metric_record_delete(int $recordId, int $userId, bool $isAdmin = false): array
{
    ensure_metric_studio_schema();
    $pdo = db();

    $stmt = $pdo->prepare("SELECT * FROM `generic_metric_records` WHERE `id` = ?");
    $stmt->execute([$recordId]);
    $rec = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$rec) {
        return [false, 'Record not found.'];
    }

    if (!$isAdmin && (int)$rec['user_id'] !== $userId) {
        return [false, 'You do not have permission to delete this record.'];
    }

    if (!$isAdmin && in_array($rec['status'], ['Approved'], true)) {
        return [false, 'Approved records cannot be deleted.'];
    }

    // Delete proof file if present
    if (!empty($rec['proof_file'])) {
        $baseFolder = rtrim(UPLOAD_DIR, '/\\');
        $fullPath = $baseFolder . '/' . $rec['proof_file'];
        if (is_file($fullPath)) {
            @unlink($fullPath);
        }
    }

    $del = $pdo->prepare("DELETE FROM `generic_metric_records` WHERE `id` = ?");
    $del->execute([$recordId]);

    return [true, 'Record deleted.'];
}
