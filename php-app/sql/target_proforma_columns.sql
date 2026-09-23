-- Proforma columns on targets.
--
-- targets.fixed_text, achieved_p1 and achieved_p2 are named by report_columns
-- (sql/report_template.sql maps col_key -> field), and models/Target.php and
-- models/ReportTemplate.php read and write them, but no migration ever created
-- them: they were added by hand, so a database rebuilt from schema.sql came up
-- without them. sub_label goes with serial_no on the proforma.
--
-- Additive and safe to re-run.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

DROP PROCEDURE IF EXISTS add_target_col;
DELIMITER //
CREATE PROCEDURE add_target_col(IN col VARCHAR(64), IN ddl VARCHAR(255))
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'targets' AND COLUMN_NAME = col
  ) THEN
    SET @s = CONCAT('ALTER TABLE `targets` ', ddl);
    PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
  END IF;
END //
DELIMITER ;

CALL add_target_col('sub_label',   'ADD COLUMN sub_label VARCHAR(8) NULL AFTER serial_no');
CALL add_target_col('fixed_text',  'ADD COLUMN fixed_text VARCHAR(120) NULL AFTER target_value');
CALL add_target_col('achieved_p1', 'ADD COLUMN achieved_p1 VARCHAR(200) NULL AFTER achieved_value');
CALL add_target_col('achieved_p2', 'ADD COLUMN achieved_p2 VARCHAR(200) NULL AFTER achieved_p1');

DROP PROCEDURE IF EXISTS add_target_col;
