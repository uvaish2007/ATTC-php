-- ---------------------------------------------------------------------------
--  Proof attachments on record uploads.
--
--  Every record type gains a `proof_file` column holding the stored filename of
--  the proof a faculty member uploads with the record (certificate, screenshot,
--  order copy). The file itself lives in php-app/uploads/proofs/. Additive and
--  safe to re-run — each column is added only if it is missing.
-- ---------------------------------------------------------------------------
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

DROP PROCEDURE IF EXISTS add_proof_col;
DELIMITER //
CREATE PROCEDURE add_proof_col(IN tbl VARCHAR(64))
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = tbl AND COLUMN_NAME = 'proof_file'
  ) THEN
    SET @ddl = CONCAT('ALTER TABLE `', tbl, '` ADD COLUMN proof_file VARCHAR(255) NULL');
    PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;
  END IF;
END //
DELIMITER ;

CALL add_proof_col('journal_publications');
CALL add_proof_col('book_publications');
CALL add_proof_col('conference_publications');
CALL add_proof_col('patents');
CALL add_proof_col('fdp');
CALL add_proof_col('mou');
CALL add_proof_col('events');
CALL add_proof_col('nptel');
CALL add_proof_col('internships');
CALL add_proof_col('placements');

DROP PROCEDURE IF EXISTS add_proof_col;
