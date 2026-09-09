-- ---------------------------------------------------------------------------
--  Target Deadline Migration
--
--  Adds target_deadline as a DATE field to the targets table.
--  Safe and idempotent: skips if column already exists.
-- ---------------------------------------------------------------------------

SET @ddl = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'targets'
       AND COLUMN_NAME = 'target_deadline') > 0,
  'DO 0',
  'ALTER TABLE targets ADD COLUMN target_deadline DATE NULL AFTER target_value');
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;
