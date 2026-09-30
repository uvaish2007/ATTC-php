-- ---------------------------------------------------------------------------
--  Journal publications: one publication, many faculty authors.
--
--  1. journal_authors links a journal_publications row to the faculty members
--     (users.id) who wrote it, each with a unique author position:
--       - one row per author, exactly one of them the Main Author (is_main = 1)
--       - UNIQUE (journal_id, user_id)          the same faculty only once
--       - UNIQUE (journal_id, author_position)  every position used once
--     faculty_name / author_type / co_authors on journal_publications stay as
--     the readable copy the IQAC reports print, so every report and export
--     keeps working unchanged.
--
--  2. journal_publications gains two lookup keys for duplicate detection:
--       doi_key    the DOI in canonical form (lower case, no https://doi.org/
--                  or doi: prefix) — the primary duplicate key
--       title_key  SHA-1 of the title reduced to A-Z / 0-9 — used with year,
--                  ISSN and journal name when a publication has no DOI
--     Both are filled by the application (journal_backfill_keys() in
--     models/Record.php), so the normalisation rules live in one place.
--     They are indexed but not UNIQUE: the table already holds duplicate rows
--     from before this check existed, and those rows are kept as they are.
--
--  Existing rows are not changed and nothing is deleted. Rows saved before
--  this file have no journal_authors rows; they keep counting for their
--  uploader exactly as before until they are edited.
--  Additive and safe to re-run.
-- ---------------------------------------------------------------------------
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS journal_authors (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  journal_id       INT NOT NULL,
  user_id          INT NOT NULL,
  author_position  TINYINT UNSIGNED NOT NULL,
  is_main          TINYINT(1) NOT NULL DEFAULT 0,
  created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_journal_author_user (journal_id, user_id),
  UNIQUE KEY uq_journal_author_position (journal_id, author_position),
  KEY idx_journal_author_user (user_id),
  CONSTRAINT fk_journal_author_journal FOREIGN KEY (journal_id) REFERENCES journal_publications(id) ON DELETE CASCADE,
  CONSTRAINT fk_journal_author_user    FOREIGN KEY (user_id)    REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP PROCEDURE IF EXISTS journal_keys_migrate;
DELIMITER //
CREATE PROCEDURE journal_keys_migrate()
BEGIN
  IF EXISTS (
    SELECT 1 FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'journal_publications'
  ) THEN
    IF NOT EXISTS (
      SELECT 1 FROM information_schema.COLUMNS
       WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'journal_publications' AND COLUMN_NAME = 'doi_key'
    ) THEN
      ALTER TABLE journal_publications ADD COLUMN doi_key VARCHAR(255) NULL AFTER doi;
    END IF;

    IF NOT EXISTS (
      SELECT 1 FROM information_schema.COLUMNS
       WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'journal_publications' AND COLUMN_NAME = 'title_key'
    ) THEN
      ALTER TABLE journal_publications ADD COLUMN title_key CHAR(40) NULL AFTER doi_key;
    END IF;

    IF NOT EXISTS (
      SELECT 1 FROM information_schema.STATISTICS
       WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'journal_publications' AND INDEX_NAME = 'idx_journal_doi_key'
    ) THEN
      ALTER TABLE journal_publications ADD INDEX idx_journal_doi_key (doi_key);
    END IF;

    IF NOT EXISTS (
      SELECT 1 FROM information_schema.STATISTICS
       WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'journal_publications' AND INDEX_NAME = 'idx_journal_title_key'
    ) THEN
      ALTER TABLE journal_publications ADD INDEX idx_journal_title_key (title_key);
    END IF;
  END IF;
END //
DELIMITER ;

CALL journal_keys_migrate();
DROP PROCEDURE IF EXISTS journal_keys_migrate;
