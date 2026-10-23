<?php
/**
 * Migration for ATTS-RESOURCE-PERSON-DETAILS
 * Enhances value_added_courses table with structured resource person fields
 * and updates metric configuration for Event Report attachment.
 */
require_once __DIR__ . '/../inc/helpers.php';
require_once __DIR__ . '/../inc/auth.php';

function run_value_added_migration(): array
{
    $pdo = db();
    $results = [
        'columns_added' => [],
        'metric_updated' => false,
        'records_synced' => 0,
    ];

    $existingCols = $pdo->query("SHOW COLUMNS FROM `value_added_courses`")->fetchAll(PDO::FETCH_COLUMN);

    $columnsToAdd = [
        'resource_person_name'    => "ALTER TABLE `value_added_courses` ADD COLUMN `resource_person_name` VARCHAR(255) NULL AFTER `resource_person`",
        'designation'             => "ALTER TABLE `value_added_courses` ADD COLUMN `designation` VARCHAR(255) NULL AFTER `resource_person_name`",
        'company_name'            => "ALTER TABLE `value_added_courses` ADD COLUMN `company_name` VARCHAR(255) NULL AFTER `designation`",
        'resource_person_address' => "ALTER TABLE `value_added_courses` ADD COLUMN `resource_person_address` TEXT NULL AFTER `company_name`",
        'company_address'         => "ALTER TABLE `value_added_courses` ADD COLUMN `company_address` TEXT NULL AFTER `resource_person_address`",
        'contact_number'          => "ALTER TABLE `value_added_courses` ADD COLUMN `contact_number` VARCHAR(50) NULL AFTER `company_address`",
        'email'                   => "ALTER TABLE `value_added_courses` ADD COLUMN `email` VARCHAR(190) NULL AFTER `contact_number`",
        'event_report_file'       => "ALTER TABLE `value_added_courses` ADD COLUMN `event_report_file` VARCHAR(255) NULL AFTER `report_link`",
    ];

    foreach ($columnsToAdd as $col => $ddl) {
        if (!in_array($col, $existingCols, true)) {
            $pdo->exec($ddl);
            $results['columns_added'][] = $col;
        }
    }

    // Sync event_report_file with proof_file for existing rows where proof_file is set
    $stmt = $pdo->query("UPDATE `value_added_courses` SET `event_report_file` = `proof_file` WHERE `proof_file` IS NOT NULL AND `proof_file` != '' AND (`event_report_file` IS NULL OR `event_report_file` = '')");
    $results['records_synced'] = $stmt->rowCount();

    // Update metric definition for value_added attachment_config
    $attConfig = json_encode([
        'enabled'       => true,
        'label'         => 'Event Report',
        'required'      => true,
        'allowed_types' => ['pdf'],
        'max_file_mb'   => 2,
        'help_text'     => 'PDF document strictly 2 MB or less.',
    ], JSON_UNESCAPED_SLASHES);

    $updMetric = $pdo->prepare("UPDATE `metrics` SET `attachment_config` = ?, `proof_required` = 1 WHERE `metric_key` = 'value_added'");
    $updMetric->execute([$attConfig]);
    $results['metric_updated'] = $updMetric->rowCount() > 0;

    return $results;
}

if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    echo "Running Value Added Courses migration...\n";
    $res = run_value_added_migration();
    echo "Columns added: " . implode(', ', $res['columns_added'] ?: ['(none, already exist)']) . "\n";
    echo "Metric updated: " . ($res['metric_updated'] ? 'Yes' : 'No / unchanged') . "\n";
    echo "Records synced: {$res['records_synced']}\n";
    echo "Migration completed successfully.\n";
}
