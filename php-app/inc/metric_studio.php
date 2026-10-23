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
    if (!isset($existingCols['principal_visible'])) {
        $alters[] = "ADD COLUMN `principal_visible` TINYINT(1) NOT NULL DEFAULT 1 AFTER `hod_visible`";
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
    if (!isset($existingCols['form_config_draft'])) {
        $alters[] = "ADD COLUMN `form_config_draft` LONGTEXT NULL AFTER `form_config`";
    }
    if (!isset($existingCols['attachment_config'])) {
        $alters[] = "ADD COLUMN `attachment_config` TEXT NULL AFTER `form_config_draft`";
    }
    if (!isset($existingCols['form_version'])) {
        $alters[] = "ADD COLUMN `form_version` INT NOT NULL DEFAULT 1 AFTER `attachment_config`";
    }
    if (!isset($existingCols['reporting_category'])) {
        $alters[] = "ADD COLUMN `reporting_category` VARCHAR(100) NULL AFTER `form_version`";
    }
    if (!isset($existingCols['settings_json'])) {
        $alters[] = "ADD COLUMN `settings_json` LONGTEXT NULL AFTER `reporting_category`";
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
        `proof_file` TEXT NULL,
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

    // Ensure proof_file in generic_metric_records can hold multiple filenames / JSON metadata
    try {
        $pdo->exec("ALTER TABLE `generic_metric_records` MODIFY COLUMN `proof_file` TEXT NULL");
    } catch (Throwable $e) {}

    // 4. Ensure metric_option_sets table exists (Shared reusable dropdown sets)
    $pdo->exec("CREATE TABLE IF NOT EXISTS `metric_option_sets` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `set_key` VARCHAR(64) NOT NULL UNIQUE,
        `name` VARCHAR(255) NOT NULL,
        `description` VARCHAR(255) NULL,
        `options_json` LONGTEXT NOT NULL,
        `is_system` TINYINT(1) NOT NULL DEFAULT 0,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX `idx_set_key` (`set_key`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // 5. Ensure metric_reusable_fields table exists (Reusable Field Library)
    $pdo->exec("CREATE TABLE IF NOT EXISTS `metric_reusable_fields` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `field_key` VARCHAR(64) NOT NULL UNIQUE,
        `label` VARCHAR(255) NOT NULL,
        `type` VARCHAR(32) NOT NULL DEFAULT 'text',
        `placeholder` VARCHAR(255) NULL,
        `help_text` TEXT NULL,
        `required` TINYINT(1) NOT NULL DEFAULT 0,
        `options_json` LONGTEXT NULL,
        `option_set_key` VARCHAR(64) NULL,
        `min_val` DECIMAL(15,4) NULL,
        `max_val` DECIMAL(15,4) NULL,
        `is_system` TINYINT(1) NOT NULL DEFAULT 0,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX `idx_reusable_key` (`field_key`),
        INDEX `idx_opt_set` (`option_set_key`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // 6. Ensure metric_form_history table exists (Version history & rollback)
    $pdo->exec("CREATE TABLE IF NOT EXISTS `metric_form_history` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `metric_id` INT NOT NULL,
        `version` INT NOT NULL,
        `config_json` LONGTEXT NOT NULL,
        `attachment_config_json` TEXT NULL,
        `published_by` INT NULL,
        `published_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX `idx_hist_metric_ver` (`metric_id`, `version`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Clean any legacy unkeyed rows
    $pdo->exec("DELETE FROM `metrics` WHERE `metric_key` IS NULL OR `metric_key` = ''");

    // 7. Seed reusable field templates & shared option sets
    metric_studio_seed_defaults();

    // 8. Migrate and synchronize the 30 approved institutional targets into DB
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

    // Ensure all existing metrics have attachment_config populated
    $defaultAttJson = json_encode(metric_studio_default_attachment_config(), JSON_UNESCAPED_UNICODE);
    $pdo->exec("UPDATE `metrics` SET `attachment_config` = '" . addslashes($defaultAttJson) . "' WHERE `attachment_config` IS NULL OR `attachment_config` = ''");
    $pdo->exec("UPDATE `metrics` SET `form_config_draft` = `form_config` WHERE (`form_config_draft` IS NULL OR `form_config_draft` = '') AND `form_config` IS NOT NULL AND `form_config` != ''");
}

/**
 * Default attachment configuration enforced on all metrics.
 */
function metric_studio_default_attachment_config(): array
{
    return [
        'enabled'       => true,
        'label'         => 'Proof / Attachment',
        'required'      => true,
        'allowed_types' => ['pdf'],
        'max_file_mb'   => 2,
        'help_text'     => 'PDF document strictly 2 MB or less.',
    ];
}

/**
 * Seeds baseline shared option sets and reusable fields into DB if missing.
 */
function metric_studio_seed_defaults(): void
{
    $pdo = db();

    // 1. Seed Shared Option Sets
    $defaultSets = [
        'opt_exam_session' => [
            'name'        => 'Exam Session (Apr/May, Nov/Dec)',
            'description' => 'Standard Anna University examination sessions',
            'options'     => [
                ['value' => 'APR_MAY', 'label' => 'Apr/May'],
                ['value' => 'NOV_DEC', 'label' => 'Nov/Dec'],
            ],
        ],
        'opt_semester' => [
            'name'        => 'Engineering Semesters (1 to 8)',
            'description' => 'Undergraduate 8-semester curriculum levels',
            'options'     => [
                ['value' => '1', 'label' => 'Semester 1'],
                ['value' => '2', 'label' => 'Semester 2'],
                ['value' => '3', 'label' => 'Semester 3'],
                ['value' => '4', 'label' => 'Semester 4'],
                ['value' => '5', 'label' => 'Semester 5'],
                ['value' => '6', 'label' => 'Semester 6'],
                ['value' => '7', 'label' => 'Semester 7'],
                ['value' => '8', 'label' => 'Semester 8'],
            ],
        ],
        'opt_completion_status' => [
            'name'        => 'Completion Status',
            'description' => 'Target and project lifecycle states',
            'options'     => [
                ['value' => 'ONGOING', 'label' => 'Ongoing / In-Progress'],
                ['value' => 'COMPLETED', 'label' => 'Completed'],
                ['value' => 'DISCONTINUED', 'label' => 'Discontinued'],
            ],
        ],
        'opt_publication_type' => [
            'name'        => 'Publication Indexing / Classification',
            'description' => 'Quality publication indexing categories',
            'options'     => [
                ['value' => 'SCI', 'label' => 'SCI / SCIE Indexed'],
                ['value' => 'SCOPUS', 'label' => 'Scopus Indexed'],
                ['value' => 'UGC_CARE', 'label' => 'UGC-CARE Listed'],
                ['value' => 'PEER_REVIEWED', 'label' => 'Peer-Reviewed'],
                ['value' => 'OTHER', 'label' => 'Other / Non-Indexed'],
            ],
        ],
        'opt_event_mode' => [
            'name'        => 'Event Mode (Offline/Online/Hybrid)',
            'description' => 'Instructional and activity delivery modality',
            'options'     => [
                ['value' => 'Offline', 'label' => 'Offline'],
                ['value' => 'Online', 'label' => 'Online'],
                ['value' => 'Hybrid', 'label' => 'Hybrid'],
            ],
        ],
        'opt_student_year' => [
            'name'        => 'Student Class Year',
            'description' => 'Academic progression year levels',
            'options'     => [
                ['value' => 'I', 'label' => '1st Year'],
                ['value' => 'II', 'label' => '2nd Year'],
                ['value' => 'III', 'label' => '3rd Year'],
                ['value' => 'IV', 'label' => '4th Year'],
            ],
        ],
    ];

    $checkSetStmt = $pdo->prepare("SELECT id FROM `metric_option_sets` WHERE `set_key` = ?");
    $insertSetStmt = $pdo->prepare("INSERT INTO `metric_option_sets` (`set_key`, `name`, `description`, `options_json`, `is_system`, `created_at`) VALUES (?, ?, ?, ?, 1, NOW())");

    foreach ($defaultSets as $setKey => $data) {
        $checkSetStmt->execute([$setKey]);
        if (!$checkSetStmt->fetch()) {
            $insertSetStmt->execute([
                $setKey,
                $data['name'],
                $data['description'],
                json_encode($data['options'], JSON_UNESCAPED_UNICODE)
            ]);
        }
    }

    // 2. Seed Reusable Field Library
    $defaultFields = [
        'reg_no' => [
            'label'       => 'Student Register Number',
            'type'        => 'text',
            'placeholder' => 'e.g. 912821104001',
            'help_text'   => '12-digit university registration number',
            'required'    => 1,
            'options'     => null,
            'set_key'     => null,
        ],
        'student_name' => [
            'label'       => 'Student Name',
            'type'        => 'text',
            'placeholder' => 'Full student name as per university records',
            'help_text'   => '',
            'required'    => 1,
            'options'     => null,
            'set_key'     => null,
        ],
        'faculty_name' => [
            'label'       => 'Faculty Name',
            'type'        => 'text',
            'placeholder' => 'Name of faculty member',
            'help_text'   => '',
            'required'    => 1,
            'options'     => null,
            'set_key'     => null,
        ],
        'faculty_dept' => [
            'label'       => 'Faculty Department',
            'type'        => 'text',
            'placeholder' => 'e.g. AI & DS',
            'help_text'   => 'Department of the contributing faculty',
            'required'    => 1,
            'options'     => null,
            'set_key'     => null,
        ],
        'semester' => [
            'label'       => 'Semester',
            'type'        => 'select',
            'placeholder' => 'Select semester',
            'help_text'   => 'Academic semester of enrolment or completion',
            'required'    => 1,
            'options'     => null,
            'set_key'     => 'opt_semester',
        ],
        'exam_session' => [
            'label'       => 'Exam Session',
            'type'        => 'select',
            'placeholder' => 'Select exam session',
            'help_text'   => 'Apr/May or Nov/Dec academic session',
            'required'    => 1,
            'options'     => null,
            'set_key'     => 'opt_exam_session',
        ],
        'academic_year' => [
            'label'       => 'Academic Year',
            'type'        => 'text',
            'placeholder' => 'e.g. 2025-26',
            'help_text'   => 'Institutional reporting cycle',
            'required'    => 1,
            'options'     => null,
            'set_key'     => null,
        ],
        'organization' => [
            'label'       => 'Organization / Company Name',
            'type'        => 'text',
            'placeholder' => 'e.g. Zoho, TCS, Infosys, IIT Madras',
            'help_text'   => 'Hosting institute, company, or publisher',
            'required'    => 1,
            'options'     => null,
            'set_key'     => null,
        ],
        'event_date' => [
            'label'       => 'Event / Completion Date',
            'type'        => 'date',
            'placeholder' => '',
            'help_text'   => 'Date of achievement or completion',
            'required'    => 1,
            'options'     => null,
            'set_key'     => null,
        ],
        'duration_weeks' => [
            'label'       => 'Duration (Weeks)',
            'type'        => 'integer',
            'placeholder' => 'e.g. 4',
            'help_text'   => 'Number of completed weeks',
            'required'    => 0,
            'min'         => 1,
            'max'         => 52,
            'options'     => null,
            'set_key'     => null,
        ],
        'completion_status' => [
            'label'       => 'Completion Status',
            'type'        => 'select',
            'placeholder' => 'Select status',
            'help_text'   => 'Current state of this initiative',
            'required'    => 1,
            'options'     => null,
            'set_key'     => 'opt_completion_status',
        ],
        'publication_type' => [
            'label'       => 'Publication Indexing / Category',
            'type'        => 'select',
            'placeholder' => 'Select publication type',
            'help_text'   => 'Index classification of published work',
            'required'    => 1,
            'options'     => null,
            'set_key'     => 'opt_publication_type',
        ],
        'proof_attachment' => [
            'label'       => 'Supporting Document',
            'type'        => 'file',
            'placeholder' => '',
            'help_text'   => 'Official certificate or verification proof (PDF strictly 2 MB or less)',
            'required'    => 1,
            'options'     => null,
            'set_key'     => null,
        ],
    ];

    $checkFieldStmt = $pdo->prepare("SELECT id FROM `metric_reusable_fields` WHERE `field_key` = ?");
    $insertFieldStmt = $pdo->prepare("INSERT INTO `metric_reusable_fields` (
        `field_key`, `label`, `type`, `placeholder`, `help_text`,
        `required`, `options_json`, `option_set_key`, `min_val`, `max_val`, `is_system`, `created_at`
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())");

    foreach ($defaultFields as $k => $f) {
        $checkFieldStmt->execute([$k]);
        if (!$checkFieldStmt->fetch()) {
            $insertFieldStmt->execute([
                $k,
                $f['label'],
                $f['type'],
                $f['placeholder'],
                $f['help_text'],
                (int)$f['required'],
                $f['options'] ? json_encode($f['options']) : null,
                $f['set_key'],
                $f['min'] ?? null,
                $f['max'] ?? null,
            ]);
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

    // Role visibility filter
    if (!empty($filters['role']) && $filters['role'] !== 'all') {
        $roleKey = strtolower(trim((string)$filters['role']));
        if ($roleKey === 'faculty') {
            $where[] = "`faculty_visible` = 1";
        } elseif ($roleKey === 'coordinator') {
            $where[] = "`coordinator_visible` = 1";
        } elseif ($roleKey === 'hod') {
            $where[] = "`hod_visible` = 1";
        } elseif ($roleKey === 'principal') {
            $where[] = "`principal_visible` = 1";
        }
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
        $r['display_order']            = (int)($r['display_order'] ?? 0);
        $r['status']                   = (int)($r['status'] ?? 1);
        $r['faculty_visible']          = (int)($r['faculty_visible'] ?? 1);
        $r['coordinator_visible']      = (int)($r['coordinator_visible'] ?? 1);
        $r['hod_visible']              = (int)($r['hod_visible'] ?? 1);
        $r['principal_visible']        = (int)($r['principal_visible'] ?? 1);
        $r['proof_required']           = (int)($r['proof_required'] ?? 0);
        $r['form_config_parsed']       = !empty($r['form_config']) ? json_decode($r['form_config'], true) : [];
        $r['form_config_draft_parsed'] = !empty($r['form_config_draft']) ? json_decode($r['form_config_draft'], true) : $r['form_config_parsed'];
        $r['attachment_config_parsed'] = !empty($r['attachment_config']) ? json_decode($r['attachment_config'], true) : metric_studio_default_attachment_config();
        $r['form_version']             = (int)($r['form_version'] ?? 1);
        $r['subcategories_parsed']     = !empty($r['subcategories_config']) ? json_decode($r['subcategories_config'], true) : [];
        $r['types_parsed']             = !empty($r['supported_types']) ? json_decode($r['supported_types'], true) : [];
        $r['settings_parsed']          = !empty($r['settings_json']) ? json_decode($r['settings_json'], true) : [];
        $r['fields_count']             = is_array($r['form_config_parsed']) ? count($r['form_config_parsed']) : 0;
    }
    unset($r);

    return $rows;
}

/**
 * Returns overall statistics for metrics studio dashboard.
 */
function metric_studio_summary(): array
{
    ensure_metric_studio_schema();
    $pdo = db();

    $total = (int)$pdo->query("SELECT COUNT(*) FROM `metrics`")->fetchColumn();
    $active = (int)$pdo->query("SELECT COUNT(*) FROM `metrics` WHERE `status` = 1")->fetchColumn();
    $inactive = $total - $active;
    $genericCount = (int)$pdo->query("SELECT COUNT(*) FROM `metrics` WHERE `metric_type` IN ('generic', 'hybrid')")->fetchColumn();

    return [
        'total'         => $total,
        'active'        => $active,
        'inactive'      => $inactive,
        'generic_count' => $genericCount,
    ];
}

/**
 * Returns all distinct metric categories for filters.
 */
function metric_studio_categories(): array
{
    ensure_metric_studio_schema();
    $pdo = db();

    $stmt = $pdo->query("SELECT DISTINCT `category` FROM `metrics` WHERE `category` IS NOT NULL AND `category` != '' ORDER BY `category` ASC");
    return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
}

if (!function_exists('metric_format_cat')) {
    /**
     * Formats an institutional metric category into title case.
     */
    function metric_format_cat(string $cat): string
    {
        $c = strtolower(trim($cat));
        $c = ucwords($c);
        $c = str_ireplace(['And', 'In', 'Of', 'From', 'For'], ['and', 'in', 'of', 'from', 'for'], $c);
        return ucfirst($c);
    }
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

    $row['display_order']            = (int)($row['display_order'] ?? 0);
    $row['status']                   = (int)($row['status'] ?? 1);
    $row['faculty_visible']          = (int)($row['faculty_visible'] ?? 1);
    $row['coordinator_visible']      = (int)($row['coordinator_visible'] ?? 1);
    $row['hod_visible']              = (int)($row['hod_visible'] ?? 1);
    $row['principal_visible']        = (int)($row['principal_visible'] ?? 1);
    $row['settings_parsed']          = !empty($row['settings_json']) ? (is_array($row['settings_json']) ? $row['settings_json'] : (json_decode($row['settings_json'], true) ?: [])) : [];
    $row['form_config_parsed']       = !empty($row['form_config']) ? json_decode($row['form_config'], true) : [];
    $row['form_config_draft_parsed'] = !empty($row['form_config_draft']) ? json_decode($row['form_config_draft'], true) : $row['form_config_parsed'];
    $row['attachment_config_parsed'] = !empty($row['attachment_config']) ? json_decode($row['attachment_config'], true) : metric_studio_default_attachment_config();
    $row['form_version']             = (int)($row['form_version'] ?? 1);
    $row['subcategories_parsed']     = !empty($row['subcategories_config']) ? json_decode($row['subcategories_config'], true) : [];
    $row['types_parsed']             = !empty($row['supported_types']) ? json_decode($row['supported_types'], true) : [];
    $row['fields_count']             = is_array($row['form_config_parsed']) ? count($row['form_config_parsed']) : 0;

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
    $prinVis     = isset($data['principal_visible']) ? (int)$data['principal_visible'] : 1;
    $status      = isset($data['status']) ? (int)$data['status'] : 1;
    $settingsJson = isset($data['settings_json']) ? (is_array($data['settings_json']) ? json_encode($data['settings_json'], JSON_UNESCAPED_UNICODE) : (string)$data['settings_json']) : null;

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
        `display_order`, `faculty_visible`, `coordinator_visible`, `hod_visible`, `principal_visible`,
        `proof_required`, `status`, `metric_type`, `default_type`,
        `supported_types`, `form_config`, `reporting_category`, `settings_json`
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?, ?)");

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
        $prinVis,
        $status,
        $metricType,
        $defaultType,
        $typesJson,
        $formJson,
        $category,
        $settingsJson
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
    $prinVis     = isset($data['principal_visible']) ? (int)$data['principal_visible'] : (int)($metric['principal_visible'] ?? 1);
    $status      = isset($data['status']) ? (int)$data['status'] : (int)$metric['status'];
    $reporting   = isset($data['reporting_category']) ? trim((string)$data['reporting_category']) : $category;
    $settingsJson = isset($data['settings_json']) ? (is_array($data['settings_json']) ? json_encode($data['settings_json'], JSON_UNESCAPED_UNICODE) : (string)$data['settings_json']) : ($metric['settings_json'] ?? null);

    $stmt = $pdo->prepare("UPDATE `metrics` SET
        `name` = ?,
        `category` = ?,
        `description` = ?,
        `help_text` = ?,
        `display_order` = ?,
        `faculty_visible` = ?,
        `coordinator_visible` = ?,
        `hod_visible` = ?,
        `principal_visible` = ?,
        `status` = ?,
        `reporting_category` = ?,
        `settings_json` = ?
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
        $prinVis,
        $status,
        $reporting,
        $settingsJson,
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
 * Persists an exact list of ordered metric keys or IDs inside a safe transaction.
 *
 * @param array<int, string|int> $orderedKeys
 * @return array{0: bool, 1: string, 2?: array<int, int>}
 */
function metric_studio_reorder(array $orderedKeys): array
{
    if (empty($orderedKeys)) {
        return [false, 'No metric identifiers provided for reordering.'];
    }

    ensure_metric_studio_schema();
    $pdo = db();

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("UPDATE `metrics` SET `display_order` = ? WHERE `id` = ? OR `metric_key` = ?");
        $order = 1;
        $orderMap = [];
        foreach ($orderedKeys as $keyOrId) {
            $stmt->execute([$order, (int)$keyOrId, (string)$keyOrId]);
            $orderMap[$keyOrId] = $order;
            $order++;
        }
        $pdo->commit();

        // Invalidate upload_approved_sections static cache if loaded
        if (function_exists('upload_approved_sections')) {
            upload_approved_sections(null, true);
        }

        return [true, 'Metric display order re-sequenced successfully.', $orderMap];
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return [false, 'Failed to update metric ordering: ' . $e->getMessage()];
    }
}

/**
 * Cleans, sanitizes, and normalizes a single field definition for Form Studio.
 */
function metric_studio_clean_field(array $f, int $order = 1): array
{
    $key   = trim((string)($f['key'] ?? ''));
    $label = trim((string)($f['label'] ?? ''));
    $type  = trim((string)($f['type'] ?? 'text'));

    $validTypes = [
        'text', 'textarea', 'integer', 'decimal', 'date', 'time', 'datetime',
        'select', 'multiselect', 'radio', 'checkbox', 'url',
        'email', 'tel', 'file', 'readonly', 'specialized',
        'section_header', 'heading', 'divider', 'instructions', 'table_grid', 'acknowledgement'
    ];
    $cleanType = in_array($type, $validTypes, true) ? $type : 'text';

    // Normalize stable field key (snake_case)
    $cleanKey = strtolower(preg_replace('/[^a-z0-9_]+/i', '_', $key));
    if ($cleanKey === '') {
        $cleanKey = 'field_' . $order;
    }
    if ($label === '') {
        $label = ucwords(str_replace('_', ' ', $cleanKey));
    }

    // Parse options if provided
    $cleanOptions = [];
    if (!empty($f['options'])) {
        $rawOptions = is_array($f['options']) ? $f['options'] : array_map('trim', explode(',', (string)$f['options']));
        foreach ($rawOptions as $opt) {
            if (is_array($opt)) {
                $val = trim((string)($opt['value'] ?? ''));
                $lbl = trim((string)($opt['label'] ?? $val));
                if ($val !== '' || $lbl !== '') {
                    $cleanOptions[] = ['value' => $val ?: $lbl, 'label' => $lbl ?: $val];
                }
            } else {
                $str = trim((string)$opt);
                if ($str !== '') {
                    $cleanOptions[] = ['value' => $str, 'label' => $str];
                }
            }
        }
    }

    $setKey = !empty($f['option_set_key']) ? trim((string)$f['option_set_key']) : null;
    $optMode = (!empty($f['options_mode']) && $f['options_mode'] === 'copied') ? 'copied' : ($setKey ? 'shared' : 'custom');

    // Layout configuration: support 12-column granular layout, custom width, alignments, dimensions & spacing
    $rawSpan = isset($f['col_span']) ? (int)$f['col_span'] : null;
    $rawWidth = isset($f['width']) ? trim((string)$f['width']) : null;

    if ($rawSpan !== null && $rawSpan > 2 && $rawSpan <= 12) {
        $colSpan = $rawSpan;
        $width = ($colSpan === 12) ? 'full' : ($colSpan === 6 ? 'half' : ($rawWidth ?: "{$colSpan}/12"));
    } elseif ($rawSpan === 2 || $rawWidth === 'full' || in_array($cleanType, ['textarea', 'section_header', 'heading', 'divider', 'instructions', 'table_grid', 'acknowledgement'], true)) {
        $colSpan = 2; // Preserves backward compat with legacy assertions ($pubCfg['fields'][10]['col_span'] === 2)
        $width = 'full';
    } elseif ($rawSpan === 1 || $rawWidth === 'half') {
        $colSpan = 1; // Preserves backward compat with legacy assertions ($draftCfg['fields'][0]['width'] === 'half')
        $width = 'half';
    } else {
        $colSpan = 1;
        $width = $rawWidth ?: 'half';
    }

    $align = isset($f['align']) && in_array(strtolower(trim((string)$f['align'])), ['left', 'center', 'right', 'stretch'], true)
        ? strtolower(trim((string)$f['align']))
        : 'stretch';

    $verticalAlign = isset($f['vertical_align']) && in_array(strtolower(trim((string)$f['vertical_align'])), ['top', 'center', 'bottom'], true)
        ? strtolower(trim((string)$f['vertical_align']))
        : 'top';

    $spacing = isset($f['spacing']) && in_array(strtolower(trim((string)$f['spacing'])), ['compact', 'normal', 'relaxed', 'spacious'], true)
        ? strtolower(trim((string)$f['spacing']))
        : 'normal';

    $offset = isset($f['offset']) ? max(0, min(11, (int)$f['offset'])) : 0;
    $customHeight = !empty($f['custom_height']) ? trim((string)$f['custom_height']) : null;
    $sectionId = !empty($f['section_id']) ? trim((string)$f['section_id']) : null;

    return [
        'order'                => $order,
        'key'                  => $cleanKey,
        'label'                => $label,
        'type'                 => $cleanType,
        'width'                => $width,
        'col_span'             => $colSpan,
        'align'                => $align,
        'vertical_align'       => $verticalAlign,
        'custom_height'        => $customHeight,
        'spacing'              => $spacing,
        'offset'               => $offset,
        'section_id'           => $sectionId,
        'placeholder'          => trim((string)($f['placeholder'] ?? '')),
        'help_text'            => trim((string)($f['help_text'] ?? '')),
        'required'             => !empty($f['required']),
        'readonly'             => !empty($f['readonly']) || $cleanType === 'readonly',
        'default_val'          => isset($f['default_val']) && $f['default_val'] !== '' ? trim((string)$f['default_val']) : null,
        'min'                  => isset($f['min']) && $f['min'] !== '' ? (float)$f['min'] : null,
        'max'                  => isset($f['max']) && $f['max'] !== '' ? (float)$f['max'] : null,
        'min_length'           => isset($f['min_length']) && $f['min_length'] !== '' ? (int)$f['min_length'] : null,
        'max_length'           => isset($f['max_length']) && $f['max_length'] !== '' ? (int)$f['max_length'] : null,
        'pattern'              => trim((string)($f['pattern'] ?? '')),
        'options'              => $cleanOptions,
        'option_set_key'       => $setKey,
        'options_mode'         => $optMode,
        'section'              => trim((string)($f['section'] ?? '')),
        'page'                 => max(1, (int)($f['page'] ?? 1)),
        'conditional'          => !empty($f['conditional']) && is_array($f['conditional']) && !empty($f['conditional']['field']) ? [
            'field'    => trim((string)$f['conditional']['field']),
            'operator' => trim((string)($f['conditional']['operator'] ?? 'equals')),
            'value'    => (string)($f['conditional']['value'] ?? ''),
        ] : null,
        'faculty_editable'     => !isset($f['faculty_editable']) || !empty($f['faculty_editable']),
        'coordinator_editable' => !isset($f['coordinator_editable']) || !empty($f['coordinator_editable']),
        'hod_editable'         => !isset($f['hod_editable']) || !empty($f['hod_editable']),
        'principal_editable'   => !isset($f['principal_editable']) || !empty($f['principal_editable']),
        'include_in_reports'   => !isset($f['include_in_reports']) || !empty($f['include_in_reports']),
        'protected'            => !empty($f['protected']),
        'protected_reason'     => trim((string)($f['protected_reason'] ?? '')),
        'allowed_types'        => !empty($f['allowed_types']) ? (is_array($f['allowed_types']) ? $f['allowed_types'] : array_map('trim', explode(',', (string)$f['allowed_types']))) : ['pdf'],
        'max_file_mb'          => !empty($f['max_file_mb']) ? (int)$f['max_file_mb'] : 2,
        'grid_columns'         => !empty($f['grid_columns']) && is_array($f['grid_columns']) ? $f['grid_columns'] : [],
    ];
}

/**
 * Resolves the effective 12-column grid span for any field definition.
 * Seamlessly handles:
 * - 12-column integer spans (1 to 12)
 * - Percentage representations ('25%', '33%', '50%', '66%', '75%', '100%')
 * - Fraction representations ('1/4', '1/3', '1/2', '2/3', '3/4', '12/12')
 * - Legacy 2-column mode (col_span=1 & width='half' -> 6; col_span=2 & width='full' -> 12)
 */
function metric_studio_field_grid_span(array $f): int
{
    $type = $f['type'] ?? 'text';
    if (in_array($type, ['section_header', 'heading', 'divider', 'instructions'], true)) {
        return 12;
    }

    $rawWidth = strtolower(trim((string)($f['width'] ?? '')));
    $rawSpan = isset($f['col_span']) ? (int)$f['col_span'] : null;

    // Direct 12-column spans (3, 4, 5, 6, 7, 8, 9, 10, 11, 12)
    if ($rawSpan !== null && $rawSpan > 2 && $rawSpan <= 12) {
        return $rawSpan;
    }

    // Common fractions & percentages
    if ($rawWidth === '25%' || $rawWidth === '1/4' || $rawWidth === '3/12') return 3;
    if ($rawWidth === '33%' || $rawWidth === '33.3%' || $rawWidth === '1/3' || $rawWidth === '4/12') return 4;
    if ($rawWidth === '50%' || $rawWidth === '1/2' || $rawWidth === '6/12' || $rawWidth === 'half') return 6;
    if ($rawWidth === '66%' || $rawWidth === '66.7%' || $rawWidth === '2/3' || $rawWidth === '8/12') return 8;
    if ($rawWidth === '75%' || $rawWidth === '3/4' || $rawWidth === '9/12') return 9;
    if ($rawWidth === '100%' || $rawWidth === '1/1' || $rawWidth === '12/12' || $rawWidth === 'full') return 12;

    // Full-width controls
    if ($rawSpan === 2 || in_array($type, ['textarea', 'file', 'specialized', 'table_grid', 'acknowledgement'], true)) {
        return 12;
    }
    // Half-width controls
    if ($rawSpan === 1) {
        return 6;
    }

    return 6;
}

/**
 * Sanitizes and normalizes the mandatory attachment configuration for a metric.
 */
function metric_studio_clean_attachment_config(?array $att): array
{
    $base = metric_studio_default_attachment_config();
    if (!$att) {
        $base['preset'] = 'pdf_only';
        $base['max_files'] = 1;
        $base['min_files'] = 1;
        $base['summary'] = 'PDF only · Maximum 2 MB per file · Single file';
        return $base;
    }

    $preset = trim((string)($att['preset'] ?? 'custom'));
    $rawAllowed = $att['allowed_types'] ?? $base['allowed_types'];
    if (is_string($rawAllowed)) {
        $rawAllowed = array_map('trim', explode(',', strtolower($rawAllowed)));
    }

    if ($preset === 'pdf_only') {
        $rawAllowed = ['pdf'];
    } elseif ($preset === 'images_only') {
        $rawAllowed = ['png', 'jpg', 'jpeg'];
    } elseif ($preset === 'office_docs') {
        $rawAllowed = ['docx', 'doc', 'xlsx', 'xls', 'pptx'];
    } elseif ($preset === 'pdf_images') {
        $rawAllowed = ['pdf', 'png', 'jpg', 'jpeg'];
    } elseif ($preset === 'docs_sheets') {
        $rawAllowed = ['pdf', 'docx', 'xlsx', 'csv'];
    }

    $multiple = !empty($att['multiple']);
    $maxFiles = isset($att['max_files']) ? max(1, min(10, (int)$att['max_files'])) : ($multiple ? 5 : 1);
    $required = isset($att['required']) ? !empty($att['required']) : true;
    $minFiles = isset($att['min_files']) ? max(0, min($maxFiles, (int)$att['min_files'])) : ($required ? 1 : 0);
    $maxMb = isset($att['max_file_mb']) ? max(1, min(50, (int)$att['max_file_mb'])) : 2;
    $cleanAllowed = !empty($rawAllowed) ? array_values(array_filter($rawAllowed)) : ['pdf'];

    $fmtsDisplay = strtoupper(implode(', ', $cleanAllowed));
    $summary = $fmtsDisplay . ' · Maximum ' . $maxMb . ' MB per file · ' . ($multiple ? "Up to {$maxFiles} files" : 'Single file');

    return [
        'enabled'       => true, // MANDATORY: always enabled
        'label'         => trim((string)($att['label'] ?? '')) ?: 'Proof / Attachment',
        'required'      => $required,
        'allowed_types' => $cleanAllowed,
        'max_file_mb'   => $maxMb,
        'multiple'      => $multiple,
        'preset'        => $preset,
        'max_files'     => $maxFiles,
        'min_files'     => $minFiles,
        'summary'       => $summary,
        'help_text'     => trim((string)($att['help_text'] ?? '')) ?: ($fmtsDisplay . ' strictly ' . $maxMb . ' MB or less.'),
    ];
}

/**
 * Returns active published form configuration or draft form configuration for a metric.
 */
function metric_studio_get_form_config(string|int $idOrKey, bool $draft = false): array
{
    $metric = metric_studio_find($idOrKey);
    if (!$metric) {
        return [
            'fields'     => [],
            'attachment' => metric_studio_default_attachment_config(),
            'version'    => 1,
            'metric'     => [
                'id'           => is_numeric($idOrKey) ? (int)$idOrKey : 0,
                'name'         => 'Metric #' . $idOrKey,
                'metric_key'   => (string)$idOrKey,
                'form_version' => 1,
                'metric_type'  => 'generic',
            ],
        ];
    }

    $fields = $draft ? ($metric['form_config_draft_parsed'] ?: $metric['form_config_parsed']) : $metric['form_config_parsed'];
    $att    = $metric['attachment_config_parsed'] ?: metric_studio_default_attachment_config();

    // If metric is specialized and has no custom field config yet, generate standard core fields
    if (empty($fields) && ($metric['metric_type'] === 'specialized' || $metric['metric_type'] === 'hybrid')) {
        $fields = metric_studio_synthesize_specialized_fields($metric);
    }

    return [
        'fields'     => $fields,
        'attachment' => $att,
        'version'    => (int)($metric['form_version'] ?? 1),
        'metric'     => $metric,
    ];
}

/**
 * Synthesizes standard field definitions for recognized specialized metrics.
 */
function metric_studio_synthesize_specialized_fields(array $metric): array
{
    $key = $metric['metric_key'] ?? '';
    $order = 1;

    if ($key === 'upercent' || $key === 'inst_pass_percentage') {
        return [
            metric_studio_clean_field([
                'key' => 'exam_session', 'label' => 'Exam Session', 'type' => 'select',
                'option_set_key' => 'opt_exam_session', 'required' => true,
                'protected' => true, 'protected_reason' => 'Mandatory university exam session'
            ], $order++),
            metric_studio_clean_field([
                'key' => 'semester', 'label' => 'Semester', 'type' => 'select',
                'option_set_key' => 'opt_semester', 'required' => true,
                'protected' => true, 'protected_reason' => 'Mandatory curriculum semester'
            ], $order++),
            metric_studio_clean_field([
                'key' => 'registered_students', 'label' => 'Registered Students', 'type' => 'integer',
                'min' => 1, 'required' => true, 'placeholder' => 'Total appeared students',
                'protected' => true, 'protected_reason' => 'Core university pass calculation input'
            ], $order++),
            metric_studio_clean_field([
                'key' => 'passed_students', 'label' => 'Passed Students', 'type' => 'integer',
                'min' => 0, 'required' => true, 'placeholder' => 'Total passed students',
                'protected' => true, 'protected_reason' => 'Core university pass calculation input'
            ], $order++),
            metric_studio_clean_field([
                'key' => 'pass_percentage', 'label' => 'Pass Percentage (%)', 'type' => 'readonly',
                'placeholder' => 'Auto-calculated (Passed / Registered * 100)',
                'protected' => true, 'protected_reason' => 'Auto-calculated formula field'
            ], $order++),
        ];
    }

    if ($key === 'student_cgpa' || $key === 'inst_student_cgpa') {
        return [
            metric_studio_clean_field([
                'key' => 'student_year', 'label' => 'Class Year', 'type' => 'select',
                'option_set_key' => 'opt_student_year', 'required' => true,
                'protected' => true, 'protected_reason' => 'Student class cohort'
            ], $order++),
            metric_studio_clean_field([
                'key' => 'semester', 'label' => 'Semester', 'type' => 'select',
                'option_set_key' => 'opt_semester', 'required' => true,
                'protected' => true, 'protected_reason' => 'Examination semester'
            ], $order++),
            metric_studio_clean_field([
                'key' => 'register_no', 'label' => 'Student Register Number', 'type' => 'text',
                'required' => true, 'placeholder' => 'e.g. 912821104001',
                'protected' => true, 'protected_reason' => 'Student university identifier'
            ], $order++),
            metric_studio_clean_field([
                'key' => 'student_name', 'label' => 'Student Name', 'type' => 'text',
                'required' => true, 'placeholder' => 'Full name of candidate',
                'protected' => true, 'protected_reason' => 'Candidate identification'
            ], $order++),
            metric_studio_clean_field([
                'key' => 'cgpa', 'label' => 'Cumulative GPA (CGPA)', 'type' => 'decimal',
                'min' => 0, 'max' => 10, 'required' => true, 'placeholder' => '0.00 - 10.00',
                'protected' => true, 'protected_reason' => 'Grade point score'
            ], $order++),
        ];
    }

    if ($key === 'publications' || $key === 'journal') {
        return [
            metric_studio_clean_field([
                'key' => 'paper_title', 'label' => 'Title of Paper', 'type' => 'text',
                'required' => true, 'placeholder' => 'CAPITAL LETTERS and digits only',
                'protected' => true, 'protected_reason' => 'Publication title'
            ], $order++),
            metric_studio_clean_field([
                'key' => 'journal_name', 'label' => 'Journal Name', 'type' => 'text',
                'required' => true, 'placeholder' => 'Full journal title'
            ], $order++),
            metric_studio_clean_field([
                'key' => 'publication_type', 'label' => 'Journal Classification', 'type' => 'select',
                'option_set_key' => 'opt_publication_type', 'required' => true
            ], $order++),
            metric_studio_clean_field([
                'key' => 'doi', 'label' => 'DOI / Direct Article URL', 'type' => 'url',
                'required' => true, 'placeholder' => 'https://doi.org/10.xxxx/...'
            ], $order++),
            metric_studio_clean_field([
                'key' => 'publication_date', 'label' => 'Publication Date', 'type' => 'date',
                'required' => true
            ], $order++),
        ];
    }

    // Default template for other metrics
    return [
        metric_studio_clean_field([
            'key' => 'title', 'label' => 'Entry Title / Name', 'type' => 'text',
            'required' => true, 'placeholder' => 'Enter title or name...'
        ], $order++),
        metric_studio_clean_field([
            'key' => 'details', 'label' => 'Details / Summary', 'type' => 'textarea',
            'required' => false, 'placeholder' => 'Detailed description...'
        ], $order++),
        metric_studio_clean_field([
            'key' => 'event_date', 'label' => 'Date', 'type' => 'date',
            'required' => true
        ], $order++),
    ];
}

/**
 * Saves draft form configuration for a metric without publishing.
 */
function metric_studio_save_draft_form(string|int $idOrKey, array $fields, ?array $attachmentConfig = null, ?array $settings = null): array
{
    ensure_metric_studio_schema();
    $pdo = db();

    $metric = metric_studio_find($idOrKey);
    if (!$metric) {
        return [false, 'Target metric not found.'];
    }

    $cleanedFields = [];
    $order = 1;
    $seenKeys = [];

    foreach ($fields as $f) {
        $clean = metric_studio_clean_field($f, $order++);
        if (isset($seenKeys[$clean['key']])) {
            return [false, "Duplicate field key '{$clean['key']}'. Every field key must be unique."];
        }
        $seenKeys[$clean['key']] = true;
        $cleanedFields[] = $clean;
    }

    if (empty($cleanedFields)) {
        return [false, 'Form configuration must contain at least one field.'];
    }

    $cleanedAtt = metric_studio_clean_attachment_config($attachmentConfig ?? $metric['attachment_config_parsed']);
    $draftJson  = json_encode($cleanedFields, JSON_UNESCAPED_UNICODE);
    $attJson    = json_encode($cleanedAtt, JSON_UNESCAPED_UNICODE);

    // Update settings if provided
    $name     = isset($settings['name']) && trim((string)$settings['name']) !== '' ? trim((string)$settings['name']) : $metric['name'];
    $category = isset($settings['category']) && trim((string)$settings['category']) !== '' ? trim((string)$settings['category']) : $metric['category'];
    $desc     = isset($settings['description']) ? trim((string)$settings['description']) : $metric['description'];
    $help     = isset($settings['help_text']) ? trim((string)$settings['help_text']) : $metric['help_text'];
    $facVis   = isset($settings['faculty_visible']) ? (int)$settings['faculty_visible'] : (int)$metric['faculty_visible'];
    $coordVis = isset($settings['coordinator_visible']) ? (int)$settings['coordinator_visible'] : (int)$metric['coordinator_visible'];
    $hodVis   = isset($settings['hod_visible']) ? (int)$settings['hod_visible'] : (int)$metric['hod_visible'];
    $prinVis  = isset($settings['principal_visible']) ? (int)$settings['principal_visible'] : (int)($metric['principal_visible'] ?? 1);
    $settJson = isset($settings['settings_json']) ? (is_array($settings['settings_json']) ? json_encode($settings['settings_json'], JSON_UNESCAPED_UNICODE) : (string)$settings['settings_json']) : ($metric['settings_json'] ?? null);

    $stmt = $pdo->prepare("UPDATE `metrics` SET
        `form_config_draft` = ?,
        `attachment_config` = ?,
        `name` = ?,
        `category` = ?,
        `description` = ?,
        `help_text` = ?,
        `faculty_visible` = ?,
        `coordinator_visible` = ?,
        `hod_visible` = ?,
        `principal_visible` = ?,
        `settings_json` = ?
        WHERE `id` = ?");
    $stmt->execute([
        $draftJson, $attJson,
        $name, $category, $desc, $help,
        $facVis, $coordVis, $hodVis, $prinVis,
        $settJson,
        (int)$metric['id']
    ]);

    return [true, "Draft saved with " . count($cleanedFields) . " fields and attachment settings."];
}

/**
 * Validates, versions, and publishes form configuration for a metric.
 */
function metric_studio_publish_form(string|int $idOrKey, array $fields, ?array $attachmentConfig = null, int $adminId = 0, ?array $settings = null): array
{
    ensure_metric_studio_schema();
    $pdo = db();

    $metric = metric_studio_find($idOrKey);
    if (!$metric) {
        return [false, 'Target metric not found.'];
    }

    $cleanedFields = [];
    $order = 1;
    $seenKeys = [];

    foreach ($fields as $f) {
        $clean = metric_studio_clean_field($f, $order++);
        if (isset($seenKeys[$clean['key']])) {
            return [false, "Duplicate field key '{$clean['key']}'. Field keys must be strictly unique."];
        }
        $seenKeys[$clean['key']] = true;
        $cleanedFields[] = $clean;
    }

    if (empty($cleanedFields)) {
        return [false, 'Cannot publish an empty form configuration.'];
    }

    // Enforce protected specialized-field preservation
    $defaultSpecialized = metric_studio_synthesize_specialized_fields($metric);
    foreach ($defaultSpecialized as $dsf) {
        if (!empty($dsf['protected'])) {
            $foundKey = false;
            foreach ($cleanedFields as $pubF) {
                if (($pubF['key'] ?? '') === $dsf['key']) {
                    $foundKey = true;
                    break;
                }
            }
            if (!$foundKey) {
                return [false, "Protected field '{$dsf['label']}' ({$dsf['key']}) is required by institutional calculations and cannot be removed."];
            }
        }
    }

    $cleanedAtt = metric_studio_clean_attachment_config($attachmentConfig ?? $metric['attachment_config_parsed']);
    $publishedJson = json_encode($cleanedFields, JSON_UNESCAPED_UNICODE);
    $attJson       = json_encode($cleanedAtt, JSON_UNESCAPED_UNICODE);

    $currentVer = (int)($metric['form_version'] ?? 1);
    $newVer     = $currentVer + 1;

    // Determine new metric_type: if specialized, upgrade to hybrid; if generic, keep generic
    $newType = ($metric['metric_type'] === 'specialized') ? 'hybrid' : $metric['metric_type'];

    // Update settings if provided
    $name     = isset($settings['name']) && trim((string)$settings['name']) !== '' ? trim((string)$settings['name']) : $metric['name'];
    $category = isset($settings['category']) && trim((string)$settings['category']) !== '' ? trim((string)$settings['category']) : $metric['category'];
    $desc     = isset($settings['description']) ? trim((string)$settings['description']) : $metric['description'];
    $help     = isset($settings['help_text']) ? trim((string)$settings['help_text']) : $metric['help_text'];
    $facVis   = isset($settings['faculty_visible']) ? (int)$settings['faculty_visible'] : (int)$metric['faculty_visible'];
    $coordVis = isset($settings['coordinator_visible']) ? (int)$settings['coordinator_visible'] : (int)$metric['coordinator_visible'];
    $hodVis   = isset($settings['hod_visible']) ? (int)$settings['hod_visible'] : (int)$metric['hod_visible'];
    $prinVis  = isset($settings['principal_visible']) ? (int)$settings['principal_visible'] : (int)($metric['principal_visible'] ?? 1);
    $settJson = isset($settings['settings_json']) ? (is_array($settings['settings_json']) ? json_encode($settings['settings_json'], JSON_UNESCAPED_UNICODE) : (string)$settings['settings_json']) : ($metric['settings_json'] ?? null);

    // Update metrics table
    $stmt = $pdo->prepare("UPDATE `metrics` SET
        `form_config` = ?,
        `form_config_draft` = ?,
        `attachment_config` = ?,
        `form_version` = ?,
        `metric_type` = ?,
        `name` = ?,
        `category` = ?,
        `description` = ?,
        `help_text` = ?,
        `faculty_visible` = ?,
        `coordinator_visible` = ?,
        `hod_visible` = ?,
        `principal_visible` = ?,
        `settings_json` = ?
        WHERE `id` = ?");
    $stmt->execute([
        $publishedJson, $publishedJson, $attJson, $newVer, $newType,
        $name, $category, $desc, $help,
        $facVis, $coordVis, $hodVis, $prinVis,
        $settJson,
        (int)$metric['id']
    ]);

    // Save snapshot to history
    $histStmt = $pdo->prepare("INSERT INTO `metric_form_history` (
        `metric_id`, `version`, `config_json`, `attachment_config_json`, `published_by`, `published_at`
    ) VALUES (?, ?, ?, ?, ?, NOW())");
    $histStmt->execute([(int)$metric['id'], $newVer, $publishedJson, $attJson, $adminId ?: null]);

    return [true, "Form configuration published successfully (v{$newVer}) with " . count($cleanedFields) . " fields."];
}

/**
 * Restores previous published form configuration from history.
 */
function metric_studio_restore_previous_form(string|int $idOrKey, ?int $historyId = null): array
{
    ensure_metric_studio_schema();
    $pdo = db();

    $metric = metric_studio_find($idOrKey);
    if (!$metric) {
        return [false, 'Target metric not found.'];
    }

    if ($historyId) {
        $stmt = $pdo->prepare("SELECT * FROM `metric_form_history` WHERE `id` = ? AND `metric_id` = ? LIMIT 1");
        $stmt->execute([(int)$historyId, (int)$metric['id']]);
        $prev = $stmt->fetch(PDO::FETCH_ASSOC);
    } else {
        // Find the latest historical snapshot before current version
        $stmt = $pdo->prepare("SELECT * FROM `metric_form_history`
            WHERE `metric_id` = ? AND `version` < ?
            ORDER BY `version` DESC LIMIT 1");
        $stmt->execute([(int)$metric['id'], (int)($metric['form_version'] ?? 1)]);
        $prev = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$prev) {
            // Fallback: check any prior record in history
            $stmt2 = $pdo->prepare("SELECT * FROM `metric_form_history`
                WHERE `metric_id` = ?
                ORDER BY `id` DESC LIMIT 1 OFFSET 1");
            $stmt2->execute([(int)$metric['id']]);
            $prev = $stmt2->fetch(PDO::FETCH_ASSOC);
        }
    }

    if (!$prev) {
        return [false, 'No previous version found in history for this metric.'];
    }

    $newVer = (int)($metric['form_version'] ?? 1) + 1;
    $upd = $pdo->prepare("UPDATE `metrics` SET
        `form_config` = ?,
        `form_config_draft` = ?,
        `attachment_config` = ?,
        `form_version` = ?
        WHERE `id` = ?");
    $upd->execute([
        $prev['config_json'],
        $prev['config_json'],
        $prev['attachment_config_json'] ?: json_encode(metric_studio_default_attachment_config()),
        $newVer,
        (int)$metric['id']
    ]);

    return [true, "Form restored to historical configuration v{$prev['version']} (new version v{$newVer})."];
}

/**
 * Returns version history records for a metric.
 */
function metric_studio_get_form_history(string|int $idOrKey): array
{
    ensure_metric_studio_schema();
    $pdo = db();

    $metric = metric_studio_find($idOrKey);
    if (!$metric) {
        return [];
    }

    $stmt = $pdo->prepare("SELECT h.*, u.name as publisher_name FROM `metric_form_history` h
        LEFT JOIN `users` u ON h.published_by = u.id
        WHERE h.metric_id = ?
        ORDER BY h.version DESC");
    $stmt->execute([(int)$metric['id']]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Backward-compatible wrapper for metric_studio_save_form_template.
 */
function metric_studio_save_form_template(string|int $idOrKey, array $fields): array
{
    return metric_studio_publish_form($idOrKey, $fields);
}

/**
 * Returns all shared option sets.
 */
function metric_studio_get_option_sets(): array
{
    ensure_metric_studio_schema();
    $pdo = db();

    $stmt = $pdo->query("SELECT * FROM `metric_option_sets` ORDER BY `name` ASC");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($rows as &$r) {
        $r['options_parsed'] = !empty($r['options_json']) ? json_decode($r['options_json'], true) : [];
    }
    unset($r);

    return $rows;
}

/**
 * Finds a single option set by key.
 */
function metric_studio_find_option_set(string $setKey): ?array
{
    ensure_metric_studio_schema();
    $pdo = db();

    $stmt = $pdo->prepare("SELECT * FROM `metric_option_sets` WHERE `set_key` = ? LIMIT 1");
    $stmt->execute([$setKey]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        $row['options_parsed'] = !empty($row['options_json']) ? json_decode($row['options_json'], true) : [];
    }

    return $row ?: null;
}

/**
 * Creates or updates a shared option set.
 */
function metric_studio_save_option_set(string|array $setKey, string $name = '', array $options = [], ?string $description = null): array
{
    ensure_metric_studio_schema();
    $pdo = db();

    if (is_array($setKey)) {
        $data        = $setKey;
        $setKey      = (string)($data['set_key'] ?? '');
        $name        = (string)($data['name'] ?? $setKey);
        $options     = (array)($data['options'] ?? []);
        $description = $data['description'] ?? null;
    }

    $cleanKey = strtolower(preg_replace('/[^a-z0-9_]+/i', '_', trim($setKey)));
    $cleanName = trim($name);

    if ($cleanKey === '' || $cleanName === '') {
        return [false, 'Option set key and name are required.'];
    }

    $cleanOpts = [];
    foreach ($options as $opt) {
        if (is_array($opt)) {
            $val = trim((string)($opt['value'] ?? ''));
            $lbl = trim((string)($opt['label'] ?? $val));
            if ($val !== '' || $lbl !== '') {
                $cleanOpts[] = ['value' => $val ?: $lbl, 'label' => $lbl ?: $val];
            }
        } else {
            $str = trim((string)$opt);
            if ($str !== '') {
                $cleanOpts[] = ['value' => $str, 'label' => $str];
            }
        }
    }

    if (empty($cleanOpts)) {
        return [false, 'Option set must include at least one valid option.'];
    }

    $json = json_encode($cleanOpts, JSON_UNESCAPED_UNICODE);

    $check = $pdo->prepare("SELECT id FROM `metric_option_sets` WHERE `set_key` = ?");
    $check->execute([$cleanKey]);
    $existing = $check->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        $stmt = $pdo->prepare("UPDATE `metric_option_sets` SET `name` = ?, `description` = ?, `options_json` = ? WHERE `id` = ?");
        $stmt->execute([$cleanName, $description ? trim($description) : null, $json, (int)$existing['id']]);
        return [true, "Shared option set '{$cleanName}' updated with " . count($cleanOpts) . " options."];
    } else {
        $stmt = $pdo->prepare("INSERT INTO `metric_option_sets` (`set_key`, `name`, `description`, `options_json`, `is_system`, `created_at`) VALUES (?, ?, ?, ?, 0, NOW())");
        $stmt->execute([$cleanKey, $cleanName, $description ? trim($description) : null, $json]);
        return [true, "New shared option set '{$cleanName}' created."];
    }
}

/**
 * Returns all reusable fields in the library.
 */
function metric_studio_get_reusable_fields(): array
{
    ensure_metric_studio_schema();
    $pdo = db();

    $stmt = $pdo->query("SELECT * FROM `metric_reusable_fields` ORDER BY `label` ASC");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($rows as &$r) {
        $r['options_parsed'] = !empty($r['options_json']) ? json_decode($r['options_json'], true) : [];
    }
    unset($r);

    return $rows;
}

/**
 * Creates or updates a reusable field in the library.
 */
function metric_studio_save_reusable_field(array $fieldData): array
{
    ensure_metric_studio_schema();
    $pdo = db();

    $clean = metric_studio_clean_field($fieldData);
    $key   = $clean['key'];
    $label = $clean['label'];

    $check = $pdo->prepare("SELECT id FROM `metric_reusable_fields` WHERE `field_key` = ?");
    $check->execute([$key]);
    $existing = $check->fetch(PDO::FETCH_ASSOC);

    $optJson = !empty($clean['options']) ? json_encode($clean['options'], JSON_UNESCAPED_UNICODE) : null;

    if ($existing) {
        $stmt = $pdo->prepare("UPDATE `metric_reusable_fields` SET
            `label` = ?, `type` = ?, `placeholder` = ?, `help_text` = ?,
            `required` = ?, `options_json` = ?, `option_set_key` = ?,
            `min_val` = ?, `max_val` = ? WHERE `id` = ?");
        $stmt->execute([
            $label, $clean['type'], $clean['placeholder'], $clean['help_text'],
            $clean['required'] ? 1 : 0, $optJson, $clean['option_set_key'],
            $clean['min'], $clean['max'], (int)$existing['id']
        ]);
        return [true, "Reusable field '{$label}' updated."];
    } else {
        $stmt = $pdo->prepare("INSERT INTO `metric_reusable_fields` (
            `field_key`, `label`, `type`, `placeholder`, `help_text`,
            `required`, `options_json`, `option_set_key`, `min_val`, `max_val`, `is_system`, `created_at`
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, NOW())");
        $stmt->execute([
            $key, $label, $clean['type'], $clean['placeholder'], $clean['help_text'],
            $clean['required'] ? 1 : 0, $optJson, $clean['option_set_key'],
            $clean['min'], $clean['max']
        ]);
        return [true, "Reusable field '{$label}' added to library."];
    }
}

/**
 * Server-side submission validation against published metric configuration.
 *
 * @param string $metricKey
 * @param array $postData
 * @param array|null $fileData
 * @return array{0: bool, 1: string, 2: array, 3: ?string}
 */
function metric_studio_validate_submission(string $metricKey, array $postData, ?array $fileData = null): array
{
    $metric = metric_studio_find($metricKey);
    if (!$metric) {
        return [false, 'Target metric not found.', [], null];
    }

    $formCfg = metric_studio_get_form_config($metricKey, false);
    $fields  = $formCfg['fields'];
    $attCfg  = $formCfg['attachment'];

    $cleanedData = [];
    $title = '';

    // Cache option sets for lookup
    $optionSets = [];

    foreach ($fields as $f) {
        $k = $f['key'];
        $val = $postData[$k] ?? null;
        $req = !empty($f['required']);
        $label = $f['label'];
        $type = $f['type'];

        // Skip non-input layout elements
        if (in_array($type, ['section_header', 'heading', 'divider', 'instructions'], true)) {
            continue;
        }

        if ($type === 'file') {
            continue; // Handled in mandatory attachment section below
        }

        // Evaluate conditional visibility if defined
        if (!empty($f['conditional']) && is_array($f['conditional']) && !empty($f['conditional']['field'])) {
            $pKey = $f['conditional']['field'];
            $pOp  = $f['conditional']['operator'] ?? 'equals';
            $pExp = $f['conditional']['value'] ?? '';
            $pVal = $postData[$pKey] ?? ($cleanedData[$pKey] ?? '');
            $isMet = true;
            if ($pOp === 'equals') {
                $isMet = (trim((string)$pVal) === trim((string)$pExp));
            } elseif ($pOp === 'not_equals') {
                $isMet = (trim((string)$pVal) !== trim((string)$pExp));
            } elseif ($pOp === 'contains') {
                $isMet = (stripos((string)$pVal, (string)$pExp) !== false);
            } elseif ($pOp === 'not_empty') {
                $isMet = (trim((string)$pVal) !== '');
            } elseif ($pOp === 'gt') {
                $isMet = (is_numeric($pVal) && (float)$pVal > (float)$pExp);
            } elseif ($pOp === 'lt') {
                $isMet = (is_numeric($pVal) && (float)$pVal < (float)$pExp);
            }
            if (!$isMet) {
                // Condition not satisfied; field is inactive
                continue;
            }
        }

        // Required check
        if ($req && ($val === null || (is_string($val) && trim($val) === '') || (is_array($val) && empty($val)))) {
            return [false, "The field '{$label}' is required.", [], null];
        }

        if ($val !== null && (!is_string($val) || trim($val) !== '')) {
            // Type-specific validation
            if ($type === 'integer') {
                if (!is_numeric($val) || (int)$val != $val) {
                    return [false, "The field '{$label}' must be a valid integer number.", [], null];
                }
                $intVal = (int)$val;
                if (isset($f['min']) && $intVal < $f['min']) {
                    return [false, "The field '{$label}' must be at least {$f['min']}.", [], null];
                }
                if (isset($f['max']) && $intVal > $f['max']) {
                    return [false, "The field '{$label}' cannot exceed {$f['max']}.", [], null];
                }
                $cleanedData[$k] = $intVal;
            } elseif ($type === 'decimal') {
                if (!is_numeric($val)) {
                    return [false, "The field '{$label}' must be a valid decimal number.", [], null];
                }
                $fltVal = (float)$val;
                if (isset($f['min']) && $fltVal < $f['min']) {
                    return [false, "The field '{$label}' must be at least {$f['min']}.", [], null];
                }
                if (isset($f['max']) && $fltVal > $f['max']) {
                    return [false, "The field '{$label}' cannot exceed {$f['max']}.", [], null];
                }
                $cleanedData[$k] = $fltVal;
            } elseif ($type === 'date') {
                $dtStr = trim((string)$val);
                if (strtotime($dtStr) === false) {
                    return [false, "The field '{$label}' must be a valid calendar date.", [], null];
                }
                $cleanedData[$k] = date('Y-m-d', strtotime($dtStr));
            } elseif ($type === 'url') {
                $urlStr = trim((string)$val);
                if (!filter_var($urlStr, FILTER_VALIDATE_URL)) {
                    return [false, "The field '{$label}' must be a valid URL starting with http:// or https://.", [], null];
                }
                $cleanedData[$k] = $urlStr;
            } elseif ($type === 'email') {
                $emStr = trim((string)$val);
                if (!filter_var($emStr, FILTER_VALIDATE_EMAIL)) {
                    return [false, "The field '{$label}' must be a valid email address.", [], null];
                }
                $cleanedData[$k] = $emStr;
            } elseif ($type === 'select' || $type === 'radio') {
                $selVal = trim((string)$val);
                // Determine allowed option values
                $allowedVals = [];
                if (!empty($f['option_set_key'])) {
                    if (!isset($optionSets[$f['option_set_key']])) {
                        $optionSets[$f['option_set_key']] = metric_studio_find_option_set($f['option_set_key']);
                    }
                    $set = $optionSets[$f['option_set_key']];
                    if ($set && !empty($set['options_parsed'])) {
                        foreach ($set['options_parsed'] as $opt) {
                            $allowedVals[] = (string)($opt['value'] ?? '');
                            $allowedVals[] = (string)($opt['label'] ?? '');
                        }
                    }
                }
                if (!empty($f['options'])) {
                    foreach ($f['options'] as $opt) {
                        $allowedVals[] = (string)($opt['value'] ?? '');
                        $allowedVals[] = (string)($opt['label'] ?? '');
                    }
                }
                $allowedVals = array_unique(array_filter($allowedVals));

                if (!empty($allowedVals) && !in_array($selVal, $allowedVals, true)) {
                    return [false, "Invalid selection '{$selVal}' for field '{$label}'.", [], null];
                }
                $cleanedData[$k] = $selVal;
            } elseif ($type === 'multiselect') {
                $arr = is_array($val) ? $val : array_map('trim', explode(',', (string)$val));
                $cleanArr = [];
                foreach ($arr as $item) {
                    if (trim((string)$item) !== '') {
                        $cleanArr[] = trim((string)$item);
                    }
                }
                $cleanedData[$k] = $cleanArr;
            } elseif ($type === 'checkbox') {
                $cleanedData[$k] = !empty($val) && $val !== '0';
            } else {
                $strVal = is_array($val) ? json_encode($val) : trim((string)$val);
                if (isset($f['min_length']) && mb_strlen($strVal) < $f['min_length']) {
                    return [false, "The field '{$label}' must be at least {$f['min_length']} characters long.", [], null];
                }
                if (isset($f['max_length']) && mb_strlen($strVal) > $f['max_length']) {
                    return [false, "The field '{$label}' cannot exceed {$f['max_length']} characters.", [], null];
                }
                $cleanedData[$k] = $strVal;
            }

            if (empty($title) && !is_array($cleanedData[$k]) && $cleanedData[$k] !== '') {
                $title = (string)$cleanedData[$k];
            }
        }
    }

    if (empty($title)) {
        $title = $metric['name'] . ' Entry #' . time();
    }

    // Mandatory Proof / Attachment Validation
    $savedProofPath = null;
    $attRequired = !empty($attCfg['required']);
    $attLabel    = $attCfg['label'] ?: 'Proof / Attachment';
    $maxBytes    = ($attCfg['max_file_mb'] ?: 2) * 1024 * 1024;
    $allowedExts = array_map('strtolower', $attCfg['allowed_types'] ?: ['pdf']);
    $maxFiles    = isset($attCfg['max_files']) ? max(1, min(10, (int)$attCfg['max_files'])) : (!empty($attCfg['multiple']) ? 5 : 1);
    $minFiles    = isset($attCfg['min_files']) ? (int)$attCfg['min_files'] : ($attRequired ? 1 : 0);

    // Normalize $_FILES item (single or multi-file)
    $normalizedFiles = [];
    if ($fileData && !empty($fileData['name'])) {
        if (is_array($fileData['name'])) {
            $count = count($fileData['name']);
            for ($i = 0; $i < $count; $i++) {
                if (($fileData['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                    $normalizedFiles[] = [
                        'name'     => $fileData['name'][$i] ?? '',
                        'type'     => $fileData['type'][$i] ?? '',
                        'tmp_name' => $fileData['tmp_name'][$i] ?? '',
                        'error'    => $fileData['error'][$i] ?? UPLOAD_ERR_OK,
                        'size'     => $fileData['size'][$i] ?? 0,
                    ];
                }
            }
        } else {
            if (($fileData['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $normalizedFiles[] = $fileData;
            }
        }
    }

    if ($minFiles > 0 && count($normalizedFiles) < $minFiles) {
        return [false, "{$attLabel} is mandatory. Please upload at least {$minFiles} supporting document.", [], null];
    }
    if (count($normalizedFiles) > $maxFiles) {
        return [false, "You cannot upload more than {$maxFiles} files for {$attLabel}.", [], null];
    }

    $savedFiles = [];
    if (!empty($normalizedFiles)) {
        $baseFolder = rtrim(UPLOAD_DIR, '/\\');
        $proofsFolder = $baseFolder . '/proofs';
        if (!is_dir($proofsFolder)) {
            @mkdir($proofsFolder, 0775, true);
        }

        foreach ($normalizedFiles as $singleFile) {
            $err = $singleFile['error'] ?? UPLOAD_ERR_OK;
            if ($err !== UPLOAD_ERR_OK) {
                return [false, "File upload failed (Error code: {$err}). Please try again.", [], null];
            }

            $isHttpUpload = is_uploaded_file($singleFile['tmp_name']);
            $isCliTest = (php_sapi_name() === 'cli' && is_file($singleFile['tmp_name']));
            if (!$isHttpUpload && !$isCliTest) {
                return [false, "The uploaded proof file is invalid.", [], null];
            }

            $fileExt = strtolower(pathinfo($singleFile['name'] ?? '', PATHINFO_EXTENSION));
            if (!in_array($fileExt, $allowedExts, true)) {
                $allowedList = strtoupper(implode(', ', $allowedExts));
                return [false, "The uploaded proof must be in {$allowedList} format.", [], null];
            }

            if ($singleFile['size'] > $maxBytes) {
                $mbLimit = $attCfg['max_file_mb'] ?: 2;
                return [false, "The uploaded file exceeds the {$mbLimit} MB limit. Please upload a smaller file.", [], null];
            }

            // Binary header magic check
            if ($fileExt === 'pdf') {
                $handle = @fopen($singleFile['tmp_name'], 'rb');
                $header = $handle ? fread($handle, 4) : '';
                if ($handle) fclose($handle);
                if ($header !== '%PDF') {
                    return [false, "The uploaded file is not a valid PDF document.", [], null];
                }
            }

            $uniqueId = bin2hex(random_bytes(8));
            $stored = "proof_{$metricKey}_{$uniqueId}_" . time() . '.' . $fileExt;
            $destPath = $baseFolder . '/' . $stored;

            $saved = $isHttpUpload ? move_uploaded_file($singleFile['tmp_name'], $destPath) : @copy($singleFile['tmp_name'], $destPath);
            if ($saved) {
                @copy($destPath, $proofsFolder . '/' . $stored);
                $savedFiles[] = $stored;
            } else {
                return [false, "Failed to save uploaded proof to storage directory.", [], null];
            }
        }
    }

    if (count($savedFiles) === 1) {
        $savedProofPath = $savedFiles[0];
    } elseif (count($savedFiles) > 1) {
        $savedProofPath = json_encode($savedFiles);
    }

    $cleanedData['_title'] = $title;
    return [true, '', $cleanedData, $savedProofPath];
}

/**
 * Submits a new record for a generic or hybrid metric using full schema validation.
 */
function generic_metric_record_submit(string $metricKey, array $user, string $academicYear, array $postData, ?array $fileData = null): array
{
    ensure_metric_studio_schema();
    $pdo = db();

    $metric = metric_studio_find($metricKey);
    if (!$metric) {
        return [false, 'Target metric not found.', null];
    }

    // Execute comprehensive validation
    [$valid, $errMsg, $dataValues, $proofPath] = metric_studio_validate_submission($metricKey, $postData, $fileData);
    if (!$valid) {
        return [false, $errMsg, null];
    }

    $title = $dataValues['_title'] ?? ($metric['name'] . ' Entry #' . time());
    unset($dataValues['_title']);

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
            VALUES (?, ?, 'submit', ?, ?, 'Submitted dynamic metric record', NOW())")
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
        $pf = trim((string)($r['proof_file'] ?? ''));
        if (str_starts_with($pf, '[') && str_ends_with($pf, ']')) {
            $r['proof_files'] = json_decode($pf, true) ?: [];
        } elseif ($pf !== '') {
            $r['proof_files'] = [$pf];
        } else {
            $r['proof_files'] = [];
        }
    }
    unset($r);

    return $rows;
}

/**
 * Updates status of a generic metric record (Approved, Rejected, Edit Requested).
 */
function generic_metric_record_update_status(int $recordId, string $status, int $userId, string $remarks = ''): array
{
    ensure_metric_studio_schema();
    $pdo = db();
    $validStatuses = ['Submitted', 'Approved', 'Rejected', 'Edit Requested'];
    if (!in_array($status, $validStatuses, true)) {
        return [false, 'Invalid record status.'];
    }

    $stmt = $pdo->prepare("UPDATE `generic_metric_records` SET
        `status` = ?,
        `remarks` = ?,
        `approved_by` = ?,
        `approved_at` = NOW()
        WHERE `id` = ?");
    $stmt->execute([$status, $remarks, $userId, $recordId]);
    return [true, "Record status updated to {$status}."];
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

    // Delete proof files if present
    if (!empty($rec['proof_file'])) {
        $baseFolder = rtrim(UPLOAD_DIR, '/\\');
        $pf = trim((string)$rec['proof_file']);
        $filesToDelete = [];
        if (str_starts_with($pf, '[') && str_ends_with($pf, ']')) {
            $filesToDelete = json_decode($pf, true) ?: [];
        } else {
            $filesToDelete = [$pf];
        }
        foreach ($filesToDelete as $fName) {
            $fullPath = $baseFolder . '/' . basename($fName);
            if (is_file($fullPath)) {
                @unlink($fullPath);
            }
        }
    }

    $del = $pdo->prepare("DELETE FROM `generic_metric_records` WHERE `id` = ?");
    $del->execute([$recordId]);

    return [true, 'Record deleted.'];
}
