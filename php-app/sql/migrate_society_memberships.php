<?php
/**
 * Migration for ATTS-DYNAMIC-SOCIETY-MEMBERSHIP
 * Ensures inst_society_memberships has all necessary columns for Faculty and Student
 * memberships, and updates the metric attachment_config label to 'Certificate'.
 */

require_once __DIR__ . '/../inc/db.php';

function run_society_memberships_migration(): array
{
    $pdo = db();
    $results = [];

    // 1. Ensure table exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS `inst_society_memberships` (
        `id` int NOT NULL AUTO_INCREMENT,
        `academic_year` varchar(20) NOT NULL,
        `department` varchar(150) NOT NULL,
        `exam_session` varchar(50) DEFAULT NULL,
        `membership_category` varchar(50) NOT NULL DEFAULT 'Faculty',
        `reg_no` varchar(50) DEFAULT NULL,
        `student_name` varchar(190) DEFAULT NULL,
        `class_year` varchar(50) DEFAULT NULL,
        `faculty_name` varchar(190) DEFAULT NULL,
        `society_name` varchar(255) NOT NULL,
        `membership_number` varchar(100) NOT NULL,
        `membership_type` varchar(100) DEFAULT NULL,
        `start_date` varchar(50) DEFAULT NULL,
        `expiry_date` varchar(50) DEFAULT NULL,
        `membership_status` varchar(100) DEFAULT NULL,
        `proof_file` varchar(255) DEFAULT NULL,
        `certificate_link` varchar(500) DEFAULT NULL,
        `import_batch_id` varchar(64) DEFAULT NULL,
        `status` enum('Draft','Submitted','Approved','Rejected') NOT NULL DEFAULT 'Draft',
        `review_remark` varchar(500) DEFAULT NULL,
        `created_by` int DEFAULT NULL,
        `approved_by` int DEFAULT NULL,
        `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_ay` (`academic_year`),
        KEY `idx_dept` (`department`),
        KEY `idx_status` (`status`),
        KEY `idx_created_by` (`created_by`),
        KEY `idx_cat` (`membership_category`),
        KEY `idx_reg` (`reg_no`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

    $cols = $pdo->query("SHOW COLUMNS FROM `inst_society_memberships`")->fetchAll(PDO::FETCH_COLUMN);

    $neededCols = [
        'membership_category' => "ALTER TABLE `inst_society_memberships` ADD COLUMN `membership_category` VARCHAR(50) NOT NULL DEFAULT 'Faculty' AFTER `exam_session`",
        'reg_no'              => "ALTER TABLE `inst_society_memberships` ADD COLUMN `reg_no` VARCHAR(50) NULL AFTER `membership_category`",
        'student_name'        => "ALTER TABLE `inst_society_memberships` ADD COLUMN `student_name` VARCHAR(190) NULL AFTER `reg_no`",
        'class_year'          => "ALTER TABLE `inst_society_memberships` ADD COLUMN `class_year` VARCHAR(50) NULL AFTER `student_name`",
        'faculty_name'        => "ALTER TABLE `inst_society_memberships` ADD COLUMN `faculty_name` VARCHAR(190) NULL AFTER `class_year`",
        'certificate_link'    => "ALTER TABLE `inst_society_memberships` ADD COLUMN `certificate_link` VARCHAR(500) NULL AFTER `proof_file`",
        'import_batch_id'     => "ALTER TABLE `inst_society_memberships` ADD COLUMN `import_batch_id` VARCHAR(64) NULL AFTER `certificate_link`",
    ];

    foreach ($neededCols as $c => $alterSql) {
        if (!in_array($c, $cols, true)) {
            $pdo->exec($alterSql);
            $results[] = "Added column {$c}";
        } else {
            $results[] = "Column {$c} already exists";
        }
    }

    // 2. Update metric attachment_config to rename Proof / Attachment to Certificate
    $attConfig = json_encode([
        'enabled'       => true,
        'label'         => 'Certificate',
        'required'      => true,
        'allowed_types' => ['pdf'],
        'max_file_mb'   => 2,
        'help_text'     => 'PDF document strictly 2 MB or less.'
    ], JSON_UNESCAPED_SLASHES);

    $updMetric = $pdo->prepare("UPDATE `metrics` SET `attachment_config` = ?, `proof_required` = 1 WHERE `metric_key` = 'society_memberships'");
    $updMetric->execute([$attConfig]);
    $results[] = "Updated metric attachment_config label to Certificate";

    return $results;
}

if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    $res = run_society_memberships_migration();
    foreach ($res as $line) {
        echo "[OK] {$line}\n";
    }
}
