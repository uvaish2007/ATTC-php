-- ---------------------------------------------------------------------------
--  ATTS BUG-WF-11: Governance Workflow & Edit Requests Migration
--  Creates edit_requests table and workflow_audit_logs table
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `edit_requests` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `record_id` INT NOT NULL,
  `record_type` VARCHAR(50) NOT NULL,
  `record_title` VARCHAR(255) NULL,
  `proof_file` VARCHAR(255) NULL,
  `academic_year` VARCHAR(20) NOT NULL,
  `department` VARCHAR(100) NOT NULL,
  `faculty_id` INT NULL,
  `faculty_name` VARCHAR(150) NULL,
  `requested_by` INT NOT NULL,
  `requested_by_name` VARCHAR(150) NULL,
  `requested_by_role` VARCHAR(50) NOT NULL DEFAULT 'HoD',
  `reason` TEXT NOT NULL,
  `specific_field` VARCHAR(100) NULL,
  `current_value` TEXT NULL,
  `requested_value` TEXT NULL,
  `status` VARCHAR(50) NOT NULL DEFAULT 'Pending',
  `decision_by` INT NULL,
  `decision_by_name` VARCHAR(150) NULL,
  `decision_role` VARCHAR(50) NULL,
  `decision_comment` TEXT NULL,
  `authorized_coordinator_id` INT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `decided_at` DATETIME NULL,
  `completed_at` DATETIME NULL,
  INDEX `idx_er_dept` (`department`),
  INDEX `idx_er_status` (`status`),
  INDEX `idx_er_record` (`record_type`, `record_id`),
  INDEX `idx_er_requested_by` (`requested_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `workflow_audit_logs` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `record_id` INT NOT NULL,
  `record_type` VARCHAR(50) NOT NULL,
  `action` VARCHAR(60) NOT NULL,
  `user_id` INT NOT NULL,
  `user_name` VARCHAR(150) NULL,
  `user_role` VARCHAR(50) NOT NULL,
  `department` VARCHAR(100) NULL,
  `academic_year` VARCHAR(20) NULL,
  `old_status` VARCHAR(50) NULL,
  `new_status` VARCHAR(50) NULL,
  `reason` TEXT NULL,
  `details` TEXT NULL,
  `ip_address` VARCHAR(45) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_wal_rec` (`record_type`, `record_id`),
  INDEX `idx_wal_user` (`user_id`),
  INDEX `idx_wal_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
