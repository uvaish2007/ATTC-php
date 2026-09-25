-- ---------------------------------------------------------------------------
--  Exam session on record uploads.
--
--  The college runs two exam sessions a year, Nov-Dec and Apr-May. Every record
--  table gains an `exam_session` column holding the one picked on the upload
--  form. Additive and safe to re-run — a column is added only when the table
--  exists and the column is missing.
-- ---------------------------------------------------------------------------
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

DROP PROCEDURE IF EXISTS add_exam_session_col;
DELIMITER //
CREATE PROCEDURE add_exam_session_col(IN tbl VARCHAR(64))
BEGIN
  IF EXISTS (
    SELECT 1 FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = tbl
  ) AND NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = tbl AND COLUMN_NAME = 'exam_session'
  ) THEN
    SET @ddl = CONCAT('ALTER TABLE `', tbl, '` ADD COLUMN exam_session VARCHAR(20) NULL');
    PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;
  END IF;
END //
DELIMITER ;

CALL add_exam_session_col('journal_publications');
CALL add_exam_session_col('book_publications');
CALL add_exam_session_col('conference_publications');
CALL add_exam_session_col('patents');
CALL add_exam_session_col('fdp');
CALL add_exam_session_col('mou');
CALL add_exam_session_col('events');
CALL add_exam_session_col('nptel');
CALL add_exam_session_col('internships');
CALL add_exam_session_col('placements');
CALL add_exam_session_col('nss');
CALL add_exam_session_col('online_courses');
CALL add_exam_session_col('student_achievements');
CALL add_exam_session_col('student_participations');
CALL add_exam_session_col('summer_training');
CALL add_exam_session_col('value_added_courses');
CALL add_exam_session_col('training');

DROP PROCEDURE IF EXISTS add_exam_session_col;
