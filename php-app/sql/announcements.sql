-- ============================================================================
--  ATTS IQAC Portal — Announcement Centre
--
--  Run this AFTER schema.sql and seed.sql. It only adds new tables, so it is
--  safe on a database that already holds records:
--
--      mysql -u root -p atts_main < sql/announcements.sql
--
--  Three tables:
--    announcements        one row per notice
--    announcement_files   the documents attached to a notice
--    announcement_reads   who has opened / bookmarked which notice
-- ============================================================================

USE atts_main;

-- ---------------------------------------------------------------------------
--  announcements
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS announcements (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  title         VARCHAR(200) NOT NULL,
  body          TEXT         NOT NULL,

  -- What kind of notice this is, and how loudly it should shout.
  category      VARCHAR(50)  NOT NULL DEFAULT 'Academic',
  priority      ENUM('Normal','Important','Urgent') NOT NULL DEFAULT 'Normal',

  -- Who should see it. department NULL means every department.
  audience      ENUM('Everyone','HoD','Coordinator','Faculty') NOT NULL DEFAULT 'Everyone',
  department    VARCHAR(150) NULL,

  -- Draft = only the author sees it. Archived = kept, but out of the way.
  status        ENUM('Draft','Published','Archived') NOT NULL DEFAULT 'Published',
  pinned        TINYINT(1)   NOT NULL DEFAULT 0,

  -- publish_at in the future = scheduled. expires_at in the past = expired.
  publish_at    DATETIME     NULL,
  expires_at    DATETIME     NULL,

  require_read  TINYINT(1)   NOT NULL DEFAULT 0,
  views         INT          NOT NULL DEFAULT 0,

  created_by    INT          NULL,
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  KEY idx_ann_status     (status),
  KEY idx_ann_category   (category),
  KEY idx_ann_department (department),
  KEY idx_ann_pinned     (pinned),
  KEY fk_ann_author      (created_by),
  CONSTRAINT fk_ann_author FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
--  announcement_files
--  stored_name is the safe name on disk; file_name is what the user uploaded.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS announcement_files (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  announcement_id INT          NOT NULL,
  file_name       VARCHAR(255) NOT NULL,
  stored_name     VARCHAR(255) NOT NULL,
  size_bytes      INT          NOT NULL DEFAULT 0,
  created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,

  KEY fk_annfile_ann (announcement_id),
  CONSTRAINT fk_annfile_ann FOREIGN KEY (announcement_id) REFERENCES announcements(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
--  announcement_reads
--  One row per person per announcement — the read receipt.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS announcement_reads (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  announcement_id INT        NOT NULL,
  user_id         INT        NOT NULL,
  read_at         DATETIME   NULL,
  bookmarked      TINYINT(1) NOT NULL DEFAULT 0,

  UNIQUE KEY uq_read_pair (announcement_id, user_id),
  KEY fk_annread_user (user_id),
  CONSTRAINT fk_annread_ann  FOREIGN KEY (announcement_id) REFERENCES announcements(id) ON DELETE CASCADE,
  CONSTRAINT fk_annread_user FOREIGN KEY (user_id)         REFERENCES users(id)         ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------------
--  A couple of examples, so the page is not empty on a fresh install.
--  They are attributed to the first Director (or Admin) that exists.
-- ---------------------------------------------------------------------------
INSERT INTO announcements (title, body, category, priority, audience, status, pinned, expires_at, require_read, created_by)
SELECT
  'IQAC data submission for the current academic year',
  'All departments are requested to complete their IQAC data entry for the current academic year.

Please make sure the following are uploaded before the deadline:
- Journal and conference publications
- Books and book chapters
- Patents, MoUs and FDP records
- Placement and internship details

Records left in Draft after the deadline will not be counted in the annual report.',
  'IQAC', 'Important', 'Everyone', 'Published', 1,
  DATE_ADD(NOW(), INTERVAL 21 DAY), 1,
  (SELECT id FROM users WHERE role IN ('Director','Admin') ORDER BY FIELD(role,'Director','Admin') LIMIT 1)
WHERE NOT EXISTS (SELECT 1 FROM announcements);

INSERT INTO announcements (title, body, category, priority, audience, status, pinned, expires_at, created_by)
SELECT
  'Faculty Development Programme — registration open',
  'A five day Faculty Development Programme on Outcome Based Education will be conducted next month.

Interested faculty may register through their Head of Department. Seats are limited and will be allotted department wise.',
  'Academic', 'Normal', 'Faculty', 'Published', 0,
  DATE_ADD(NOW(), INTERVAL 10 DAY),
  (SELECT id FROM users WHERE role IN ('Director','Admin') ORDER BY FIELD(role,'Director','Admin') LIMIT 1)
WHERE (SELECT COUNT(*) FROM announcements) = 1;
