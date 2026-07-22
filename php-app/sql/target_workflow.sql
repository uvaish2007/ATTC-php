-- ---------------------------------------------------------------------------
--  Target review workflow
--
--  Adds the three columns the review flow needs, then brings existing rows in
--  line with the new rule that an Admin's own target is approved the moment it
--  is created.
--
--  `status` and `approved_by` are already on the table from schema.sql, so they
--  are not re-added here; status now carries one of:
--
--      Draft  ->  Pending Review  ->  Approved            (frozen)
--                        |
--                        +------->  Changes Requested     (back to the HoD)
--
--  Additive and safe to re-run: each ALTER is skipped when the column already
--  exists, and the backfill only touches rows still sitting at Draft. MySQL 8
--  has no "ADD COLUMN IF NOT EXISTS", which is why these go through PREPARE.
-- ---------------------------------------------------------------------------

-- The reviewer's note when a target is sent back ("raise this to 120").
SET @ddl = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'targets'
       AND COLUMN_NAME = 'review_remark') > 0,
  'DO 0',
  'ALTER TABLE targets ADD COLUMN review_remark TEXT NULL AFTER remarks');
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

-- When the HoD last sent it up for review.
SET @ddl = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'targets'
       AND COLUMN_NAME = 'submitted_at') > 0,
  'DO 0',
  'ALTER TABLE targets ADD COLUMN submitted_at DATETIME NULL AFTER review_remark');
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

-- When it was frozen. Re-stamped if an Admin later edits the frozen figure,
-- so this always answers "who fixed this number, and when".
SET @ddl = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'targets'
       AND COLUMN_NAME = 'approved_at') > 0,
  'DO 0',
  'ALTER TABLE targets ADD COLUMN approved_at DATETIME NULL AFTER submitted_at');
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

-- Targets an Admin created before this workflow existed would otherwise sit at
-- Draft forever, since an Admin never submits to anybody. Freeze them, dated to
-- when they were written. HoD-authored drafts are left alone to go through
-- review properly.
UPDATE targets t
  JOIN users u ON u.id = t.created_by
   SET t.status      = 'Approved',
       t.approved_by = t.created_by,
       t.approved_at = t.created_at
 WHERE t.status IN ('', 'Draft')
   AND u.role = 'Admin';

-- Anything with no status at all (or an unrecognised one) starts as a Draft.
UPDATE targets
   SET status = 'Draft'
 WHERE status IS NULL
    OR status NOT IN ('Draft', 'Pending Review', 'Changes Requested', 'Approved');
