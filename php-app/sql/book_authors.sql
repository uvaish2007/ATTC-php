-- ---------------------------------------------------------------------------
--  Book / Book Chapter publications: one publication, many faculty authors.
--  Same design as journal_authors.sql.
--
--  1. book_authors links a book_publications row to the faculty members
--     (users.id) who wrote it, each with a unique author position:
--       - one row per author, exactly one of them the Main Author (is_main = 1)
--       - UNIQUE (book_id, user_id)          the same faculty only once
--       - UNIQUE (book_id, author_position)  every position used once
--     faculty_name / author_type / co_authors on book_publications are the
--     readable copy the IQAC reports print.
--
--  2. book_publications gains:
--       author_type       Main Author's position ("1st Author")
--       co_authors        "NAME (2nd Author), NAME (3rd Author)"
--       academic_session  'January - June' / 'July - December', set by the
--                         application from the IST date of submission
--       doi               optional DOI of the book / chapter
--       doi_key           the DOI in canonical form     } duplicate lookup keys,
--       isbn_key          the ISBN as 13 digits         } filled by the
--       title_key         SHA-1 of the title (A-Z/0-9)  } application
--     The keys are indexed but not UNIQUE: a Book Chapter shares its book's
--     ISBN with the other chapters, and the table may already hold duplicates.
--
--  Existing rows are not deleted. Rows saved before this file get the Academic
--  Session of the date they were created; they have no book_authors rows and
--  keep counting for their uploader until they are edited.
--  Additive and safe to re-run.
-- ---------------------------------------------------------------------------
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS book_authors (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  book_id          INT NOT NULL,
  user_id          INT NOT NULL,
  author_position  TINYINT UNSIGNED NOT NULL,
  is_main          TINYINT(1) NOT NULL DEFAULT 0,
  created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_book_author_user (book_id, user_id),
  UNIQUE KEY uq_book_author_position (book_id, author_position),
  KEY idx_book_author_user (user_id),
  CONSTRAINT fk_book_author_book FOREIGN KEY (book_id) REFERENCES book_publications(id) ON DELETE CASCADE,
  CONSTRAINT fk_book_author_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP PROCEDURE IF EXISTS book_add_col;
DROP PROCEDURE IF EXISTS book_add_index;
DELIMITER //
CREATE PROCEDURE book_add_col(IN col VARCHAR(64), IN ddl VARCHAR(255))
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'book_publications' AND COLUMN_NAME = col
  ) THEN
    SET @ddl = CONCAT('ALTER TABLE book_publications ADD COLUMN ', ddl);
    PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;
  END IF;
END //

CREATE PROCEDURE book_add_index(IN idx VARCHAR(64), IN col VARCHAR(64))
BEGIN
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'book_publications' AND INDEX_NAME = idx
  ) THEN
    SET @ddl = CONCAT('ALTER TABLE book_publications ADD INDEX ', idx, ' (', col, ')');
    PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;
  END IF;
END //
DELIMITER ;

CALL book_add_col('author_type',      'author_type VARCHAR(50) NULL AFTER department');
CALL book_add_col('co_authors',       'co_authors TEXT NULL AFTER author_type');
CALL book_add_col('academic_session', 'academic_session VARCHAR(20) NULL AFTER academic_year');
CALL book_add_col('doi',              'doi VARCHAR(255) NULL AFTER isbn');
CALL book_add_col('doi_key',          'doi_key VARCHAR(255) NULL AFTER doi');
CALL book_add_col('isbn_key',         'isbn_key VARCHAR(13) NULL AFTER doi_key');
CALL book_add_col('title_key',        'title_key CHAR(40) NULL AFTER isbn_key');
CALL book_add_index('idx_book_doi_key',   'doi_key');
CALL book_add_index('idx_book_isbn_key',  'isbn_key');
CALL book_add_index('idx_book_title_key', 'title_key');

DROP PROCEDURE IF EXISTS book_add_col;
DROP PROCEDURE IF EXISTS book_add_index;

-- Session of the (IST) month each existing row was created in; updated_at is
-- kept so the fill does not look like an edit.
UPDATE book_publications
   SET academic_session = IF(MONTH(created_at) <= 6, 'January - June', 'July - December'),
       updated_at = updated_at
 WHERE academic_session IS NULL;
