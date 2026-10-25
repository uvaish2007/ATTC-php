-- Migration: Student CGPA Achievement Module Redesign
-- Adds students_above_8_5 and students_above_7_5 columns
-- Makes individual student columns nullable for aggregate submissions

SET @dbname = DATABASE();

-- 1. Add students_above_8_5 column if not exists
SET @exist_8_5 = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @dbname
    AND TABLE_NAME = 'inst_student_cgpa'
    AND COLUMN_NAME = 'students_above_8_5'
);
SET @sql_8_5 = IF(@exist_8_5 = 0,
    'ALTER TABLE inst_student_cgpa ADD COLUMN students_above_8_5 INT NOT NULL DEFAULT 0 AFTER semester',
    'SELECT 1'
);
PREPARE stmt_8_5 FROM @sql_8_5;
EXECUTE stmt_8_5;
DEALLOCATE PREPARE stmt_8_5;

-- 2. Add students_above_7_5 column if not exists
SET @exist_7_5 = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = @dbname
    AND TABLE_NAME = 'inst_student_cgpa'
    AND COLUMN_NAME = 'students_above_7_5'
);
SET @sql_7_5 = IF(@exist_7_5 = 0,
    'ALTER TABLE inst_student_cgpa ADD COLUMN students_above_7_5 INT NOT NULL DEFAULT 0 AFTER semester',
    'SELECT 1'
);
PREPARE stmt_7_5 FROM @sql_7_5;
EXECUTE stmt_7_5;
DEALLOCATE PREPARE stmt_7_5;

-- 3. Make reg_no, student_name, cgpa, eligibility nullable for aggregate records
ALTER TABLE inst_student_cgpa MODIFY COLUMN reg_no VARCHAR(50) NULL;
ALTER TABLE inst_student_cgpa MODIFY COLUMN student_name VARCHAR(190) NULL;
ALTER TABLE inst_student_cgpa MODIFY COLUMN cgpa DECIMAL(4,2) NULL;
ALTER TABLE inst_student_cgpa MODIFY COLUMN eligibility VARCHAR(50) NULL DEFAULT 'Eligible (Above 7.5)';

-- 4. Update section name in metrics table if exists
UPDATE metrics 
SET name = 'Students Secured Above 8.5 CGPA', 
    description = 'Tracking and recording students achieving above 8.5 CGPA across I, II, III, and IV Year.'
WHERE metric_key = 'student_cgpa';
