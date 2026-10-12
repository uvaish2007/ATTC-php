-- ---------------------------------------------------------------------------
-- ATTS-SMARTBULK: Bulk Student Import & History Tables / Column Alignment
-- ---------------------------------------------------------------------------
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bulk_import_history (
  id                INT AUTO_INCREMENT PRIMARY KEY,
  batch_id          VARCHAR(64) NOT NULL UNIQUE,
  module            VARCHAR(50) NOT NULL,
  file_name         VARCHAR(255) NOT NULL,
  department        VARCHAR(150) NOT NULL,
  academic_year     VARCHAR(20) NOT NULL,
  uploaded_by       INT NOT NULL,
  total_rows        INT NOT NULL DEFAULT 0,
  valid_rows        INT NOT NULL DEFAULT 0,
  imported_records  INT NOT NULL DEFAULT 0,
  duplicate_records INT NOT NULL DEFAULT 0,
  failed_rows       INT NOT NULL DEFAULT 0,
  status            VARCHAR(50) NOT NULL DEFAULT 'Completed',
  errors_json       LONGTEXT NULL,
  created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_bih_dept (department),
  KEY idx_bih_module (module),
  KEY idx_bih_uploader (uploaded_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP PROCEDURE IF EXISTS add_bulk_col_if_absent;
DELIMITER //
CREATE PROCEDURE add_bulk_col_if_absent(IN tbl VARCHAR(64), IN col VARCHAR(64), IN ddl VARCHAR(255))
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

CALL add_bulk_col_if_absent('internships', 'academic_year', 'ADD COLUMN academic_year VARCHAR(20) NULL AFTER department');
CALL add_bulk_col_if_absent('internships', 'exam_session', 'ADD COLUMN exam_session VARCHAR(50) NULL AFTER academic_year');
CALL add_bulk_col_if_absent('internships', 'import_batch_id', 'ADD COLUMN import_batch_id VARCHAR(64) NULL AFTER proof_file');

CALL add_bulk_col_if_absent('summer_training', 'exam_session', 'ADD COLUMN exam_session VARCHAR(50) NULL AFTER academic_year');
CALL add_bulk_col_if_absent('summer_training', 'import_batch_id', 'ADD COLUMN import_batch_id VARCHAR(64) NULL AFTER proof_file');

DROP PROCEDURE IF EXISTS add_bulk_col_if_absent;

-- Update existing internships rows that had NULL academic_year to default active academic year
UPDATE internships SET academic_year = '2025-26' WHERE academic_year IS NULL OR academic_year = '';
