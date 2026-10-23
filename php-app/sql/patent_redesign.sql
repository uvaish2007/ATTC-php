-- Migration: Patent / Copyright Form Redesign
-- Co-Authors, Document Links, Multiple Attachments & Normalization

-- 1. Add academic_session to patents if not present
SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'patents'
    AND COLUMN_NAME = 'academic_session'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE patents ADD COLUMN academic_session VARCHAR(50) NULL AFTER academic_year',
    'SELECT "academic_session already exists in patents"'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2. Add co_authors to patents if not present
SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'patents'
    AND COLUMN_NAME = 'co_authors'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE patents ADD COLUMN co_authors TEXT NULL AFTER faculty_name',
    'SELECT "co_authors already exists in patents"'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3. Add attachments JSON cache column to patents if not present
SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'patents'
    AND COLUMN_NAME = 'attachments'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE patents ADD COLUMN attachments TEXT NULL AFTER proof_file',
    'SELECT "attachments already exists in patents"'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 4. Create patent_coauthors table
CREATE TABLE IF NOT EXISTS `patent_coauthors` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `patent_id` INT NOT NULL,
  `coauthor_name` VARCHAR(190) NOT NULL,
  `sort_order` INT NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_patent_coauth_pid` (`patent_id`),
  CONSTRAINT `fk_patent_coauth_patent` FOREIGN KEY (`patent_id`) REFERENCES `patents` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Create patent_attachments table
CREATE TABLE IF NOT EXISTS `patent_attachments` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `patent_id` INT NOT NULL,
  `doc_type` ENUM('Patent Copy', 'Certificate', 'Supporting Document') NOT NULL,
  `file_path` VARCHAR(255) NOT NULL,
  `file_name` VARCHAR(255) NOT NULL,
  `file_size` INT NOT NULL DEFAULT 0,
  `mime_type` VARCHAR(100) NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_patent_att_pid` (`patent_id`),
  INDEX `idx_patent_att_doctype` (`doc_type`),
  CONSTRAINT `fk_patent_att_patent` FOREIGN KEY (`patent_id`) REFERENCES `patents` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Migrate existing legacy proof_file into patent_attachments
INSERT INTO `patent_attachments` (`patent_id`, `doc_type`, `file_path`, `file_name`, `created_at`)
SELECT p.id,
       CASE 
         WHEN p.category = 'Copyrights' THEN 'Certificate'
         ELSE 'Patent Copy'
       END AS doc_type,
       p.proof_file,
       p.proof_file AS file_name,
       p.created_at
FROM `patents` p
WHERE p.proof_file IS NOT NULL 
  AND p.proof_file != ''
  AND NOT EXISTS (
      SELECT 1 FROM `patent_attachments` pa WHERE pa.patent_id = p.id
  );
