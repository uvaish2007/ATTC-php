-- ---------------------------------------------------------------------------
--  NPTEL participant, topper flag and academic year.
--
--  The existing `category` column (Faculty / Student) stays the participant
--  type. This adds what that alone could not record:
--    participant_user_id  the faculty member who earned a Faculty NPTEL
--                         (the uploader may be someone else)
--    reg_no               the register number of the student behind a
--                         Student NPTEL, same as the other student tables
--    is_topper            NPTEL Topper, 1 = Yes / 0 = No
--    academic_year        the global academic year, like every other record
--
--  Additive and safe to re-run. Existing rows keep their data; academic_year
--  is back-filled from created_at (the year runs June to May) so they stay
--  visible once reports start filtering NPTEL by year.
-- ---------------------------------------------------------------------------
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

DROP PROCEDURE IF EXISTS add_nptel_col;
DELIMITER //
CREATE PROCEDURE add_nptel_col(IN col VARCHAR(64), IN def VARCHAR(255))
BEGIN
  IF EXISTS (
    SELECT 1 FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nptel'
  ) AND NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nptel' AND COLUMN_NAME = col
  ) THEN
    SET @ddl = CONCAT('ALTER TABLE `nptel` ADD COLUMN `', col, '` ', def);
    PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;
  END IF;
END //
DELIMITER ;

CALL add_nptel_col('participant_user_id', 'INT NULL');
CALL add_nptel_col('reg_no',              'VARCHAR(50) NULL');
CALL add_nptel_col('is_topper',           'TINYINT(1) NOT NULL DEFAULT 0');
CALL add_nptel_col('academic_year',       'VARCHAR(20) NULL');

DROP PROCEDURE IF EXISTS add_nptel_col;

UPDATE nptel
   SET academic_year = CONCAT(
         IF(MONTH(created_at) >= 6, YEAR(created_at), YEAR(created_at) - 1), '-',
         LPAD(MOD(IF(MONTH(created_at) >= 6, YEAR(created_at), YEAR(created_at) - 1) + 1, 100), 2, '0'))
 WHERE (academic_year IS NULL OR academic_year = '') AND created_at IS NOT NULL;
