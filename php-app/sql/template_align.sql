-- ---------------------------------------------------------------------------
--  Align existing record tables to the official IQAC report templates.
--
--  The templates need three columns the tables did not have:
--    fdp.duration          — the FDP template lists a Duration column
--    internships.department — the internship template has a Dept / Branch column
--    placements.department  — the placement template has a Dept / Branch column
--
--  Additive and safe to re-run (each column is added only if missing).
-- ---------------------------------------------------------------------------
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

DROP PROCEDURE IF EXISTS add_col_if_absent;
DELIMITER //
CREATE PROCEDURE add_col_if_absent(IN tbl VARCHAR(64), IN col VARCHAR(64), IN ddl VARCHAR(255))
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

CALL add_col_if_absent('fdp',         'duration',   'ADD COLUMN duration VARCHAR(60) NULL AFTER department');
CALL add_col_if_absent('internships', 'department', 'ADD COLUMN department VARCHAR(150) NULL AFTER student_name');
CALL add_col_if_absent('placements',  'department', 'ADD COLUMN department VARCHAR(150) NULL AFTER student_name');

DROP PROCEDURE IF EXISTS add_col_if_absent;
