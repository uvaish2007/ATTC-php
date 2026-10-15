-- ATTS-METRIC-STUDIO — Admin Metric Management Workspace Schema Migration
-- Ensures the metrics table supports dynamic configuration and stable keys,
-- and creates generic_metric_records for dynamic form submissions.

CREATE TABLE IF NOT EXISTS `generic_metric_records` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
