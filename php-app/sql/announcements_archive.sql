-- ---------------------------------------------------------------------------
--  FEAT-12 — Expired Announcement Database Archival
--
--  Adds ONE new table, announcements_archive, which holds a complete
--  historical copy of every announcement that has expired. The existing
--  announcements / announcement_files / announcement_reads tables are not
--  touched, and no announcement is ever deleted: the original row stays in
--  announcements as 'Expired' so its attachments and read receipts keep
--  working, and this table is the permanent audit copy beside it.
--
--  Run once, from the php-app folder:
--
--      mysql -u root -p atts_main < sql/announcements_archive.sql
--
--  Additive and safe to re-run (CREATE TABLE IF NOT EXISTS). Nothing is
--  dropped or altered, so running it on a database that already holds
--  records changes none of them.
--
--  The columns mirror the live announcements table exactly — same types, same
--  enum members, same charset — so a row copies across without conversion.
--  Two deliberate differences:
--
--    created_at / updated_at are DATETIME here, not TIMESTAMP. A TIMESTAMP
--    with ON UPDATE CURRENT_TIMESTAMP would rewrite the original dates the
--    first time an archive row was edited, which is the opposite of an audit
--    copy. These hold the ORIGINAL announcement's dates, unchanged forever.
--
--    There is no foreign key back to announcements(id), and none to users.
--    The archive has to outlive whatever it describes: if the original notice
--    is eventually deleted, or the author's account is removed, the history
--    must survive both. created_by_name is a name snapshot taken at archival
--    so the author is still readable after the account is gone.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS announcements_archive (
  archive_id               INT AUTO_INCREMENT PRIMARY KEY,
  original_announcement_id INT NOT NULL,

  -- ---- the complete historical copy of the announcement -----------------
  title           VARCHAR(200) NOT NULL,
  body            TEXT         NOT NULL,
  category        VARCHAR(50)  NOT NULL DEFAULT 'Academic',
  priority        ENUM('Normal','Important','Urgent')            NOT NULL DEFAULT 'Normal',
  audience        ENUM('Everyone','HoD','Coordinator','Faculty') NOT NULL DEFAULT 'Everyone',
  department      VARCHAR(150) NULL,

  -- The status the announcement carried when it was archived, kept apart from
  -- restore_status below so the two are never confused.
  original_status ENUM('Draft','Published','Archived','Expired') NOT NULL DEFAULT 'Expired',

  pinned          TINYINT(1)   NOT NULL DEFAULT 0,
  publish_at      DATETIME     NULL,
  expires_at      DATETIME     NULL,
  require_read    TINYINT(1)   NOT NULL DEFAULT 0,
  views           INT          NOT NULL DEFAULT 0,

  created_by      INT          NULL,
  created_by_name VARCHAR(150) NULL,
  created_at      DATETIME     NULL,
  updated_at      DATETIME     NULL,

  archived_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

  -- ---- restoration metadata (section 13) --------------------------------
  --  A restored record STAYS here for the audit trail; it is never deleted.
  --  restore_status is what the double-restore guard reads.
  restore_status           ENUM('Archived','Restored') NOT NULL DEFAULT 'Archived',
  restored_at              DATETIME NULL,
  restored_by              INT      NULL,
  restored_announcement_id INT      NULL,

  -- One archive row per announcement. This is what makes running the expiry
  -- sweep twice a no-op rather than a second copy; the application checks
  -- first, and this constraint is the backstop if two requests race.
  UNIQUE KEY uq_arch_original (original_announcement_id),

  KEY idx_arch_archived_at (archived_at),
  KEY idx_arch_expires_at  (expires_at),
  KEY idx_arch_department  (department),
  KEY idx_arch_category    (category),
  KEY idx_arch_restore     (restore_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
