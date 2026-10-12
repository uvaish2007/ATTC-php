-- ===========================================================================
--  ATTS-UPERCENT: University Pass Percentage Table Redesign
--  - Adds faculty_name, faculty_dept, and failed_members to child rows
--  - Relaxes programme and class_year constraints on parent table
--  - Idempotent schema upgrade
-- ===========================================================================

-- 1. Modify parent table columns if needed
ALTER TABLE inst_pass_percentage
  MODIFY COLUMN programme VARCHAR(150) NULL DEFAULT NULL,
  MODIFY COLUMN class_year VARCHAR(50) NULL DEFAULT NULL;

-- 2. Add columns to inst_pass_percentage_rows if not already present
SET @dbname = DATABASE();

-- Check faculty_name
SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @dbname
    AND TABLE_NAME = 'inst_pass_percentage_rows'
    AND COLUMN_NAME = 'faculty_name'
);
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE inst_pass_percentage_rows ADD COLUMN faculty_name VARCHAR(190) NULL AFTER subject_name',
  'SELECT "faculty_name already exists"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Check faculty_dept
SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @dbname
    AND TABLE_NAME = 'inst_pass_percentage_rows'
    AND COLUMN_NAME = 'faculty_dept'
);
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE inst_pass_percentage_rows ADD COLUMN faculty_dept VARCHAR(150) NULL AFTER faculty_name',
  'SELECT "faculty_dept already exists"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Check failed_members
SET @col_exists = (
  SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = @dbname
    AND TABLE_NAME = 'inst_pass_percentage_rows'
    AND COLUMN_NAME = 'failed_members'
);
SET @sql = IF(@col_exists = 0,
  'ALTER TABLE inst_pass_percentage_rows ADD COLUMN failed_members INT NOT NULL DEFAULT 0 AFTER passed_members',
  'SELECT "failed_members already exists"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
