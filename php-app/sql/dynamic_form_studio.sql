-- =========================================================================
-- ATTS-DYNAMIC-FORM-STUDIO — Database Schema Migration
-- =========================================================================

DROP PROCEDURE IF EXISTS add_dfs_col_if_absent;
DELIMITER //
CREATE PROCEDURE add_dfs_col_if_absent(IN tbl VARCHAR(64), IN col VARCHAR(64), IN ddl VARCHAR(255))
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = tbl AND COLUMN_NAME = col
  ) THEN
    SET @s = CONCAT('ALTER TABLE `', tbl, '` ', ddl);
    PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
  END IF;
END //
DELIMITER ;

CALL add_dfs_col_if_absent('metrics', 'form_config_draft', 'ADD COLUMN `form_config_draft` LONGTEXT NULL AFTER `form_config`');
CALL add_dfs_col_if_absent('metrics', 'attachment_config', 'ADD COLUMN `attachment_config` TEXT NULL AFTER `form_config_draft`');
CALL add_dfs_col_if_absent('metrics', 'form_version', 'ADD COLUMN `form_version` INT NOT NULL DEFAULT 1 AFTER `attachment_config`');

DROP PROCEDURE IF EXISTS add_dfs_col_if_absent;

-- 2. Shared Option Sets table (for reusable dropdowns)
CREATE TABLE IF NOT EXISTS `metric_option_sets` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `set_key` VARCHAR(64) NOT NULL UNIQUE,
  `name` VARCHAR(255) NOT NULL,
  `description` VARCHAR(255) NULL,
  `options_json` LONGTEXT NOT NULL,
  `is_system` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_set_key` (`set_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Reusable Fields Library table
CREATE TABLE IF NOT EXISTS `metric_reusable_fields` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Form Configuration Version History table (for audits and rollback)
CREATE TABLE IF NOT EXISTS `metric_form_history` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `metric_id` INT NOT NULL,
  `version` INT NOT NULL,
  `config_json` LONGTEXT NOT NULL,
  `attachment_config_json` TEXT NULL,
  `published_by` INT NULL,
  `published_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_hist_metric_ver` (`metric_id`, `version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
