-- ---------------------------------------------------------------------------
--  Event Mode (Online / Offline / Hybrid) for the two event record types:
--    events    LIST OF EVENTS ORGANIZED (seminar / workshop / webinar / FDP …)
--    training  LIST OF EVENTS ORGANIZED (career guidance / soft skills …)
--
--  Both tables already keep the mode in `mode` VARCHAR(30); it stays the one
--  Event Mode column (no second `event_mode` column is added). This file:
--    1. adds the location details a mode needs:
--         venue            physical venue       (Offline, Hybrid)
--         online_platform  meeting platform     (Online, Hybrid)
--         meeting_link     meeting URL          (Online, Hybrid; optional)
--    2. tidies the spelling of existing modes ('online ' -> 'Online') and
--       turns empty strings into NULL. No mode is invented for a row that
--       has none — those rows show "Not specified" until they are edited,
--       and the upload form then requires a mode.
--    3. converts `mode` to ENUM('Online','Offline','Hybrid') NULL, but only
--       when every stored value is one of the three; otherwise the column is
--       left as it is so no value is lost, and the app keeps validating.
--
--  Additive and safe to re-run. No row is deleted and no existing value is
--  changed other than its spelling.
-- ---------------------------------------------------------------------------
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

DROP PROCEDURE IF EXISTS event_mode_migrate;
DELIMITER //
CREATE PROCEDURE event_mode_migrate(IN tbl VARCHAR(64))
BEGIN
  IF EXISTS (
    SELECT 1 FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = tbl
  ) THEN
    IF NOT EXISTS (
      SELECT 1 FROM information_schema.COLUMNS
       WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = tbl AND COLUMN_NAME = 'venue'
    ) THEN
      SET @ddl = CONCAT('ALTER TABLE `', tbl, '` ADD COLUMN `venue` VARCHAR(255) NULL AFTER `mode`');
      PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;
    END IF;

    IF NOT EXISTS (
      SELECT 1 FROM information_schema.COLUMNS
       WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = tbl AND COLUMN_NAME = 'online_platform'
    ) THEN
      SET @ddl = CONCAT('ALTER TABLE `', tbl, '` ADD COLUMN `online_platform` VARCHAR(150) NULL AFTER `venue`');
      PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;
    END IF;

    IF NOT EXISTS (
      SELECT 1 FROM information_schema.COLUMNS
       WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = tbl AND COLUMN_NAME = 'meeting_link'
    ) THEN
      SET @ddl = CONCAT('ALTER TABLE `', tbl, '` ADD COLUMN `meeting_link` VARCHAR(500) NULL AFTER `online_platform`');
      PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;
    END IF;

    -- Spelling only: 'online', ' ONLINE ' -> 'Online'; '' -> NULL.
    SET @q = CONCAT('UPDATE `', tbl, '` SET `mode` = CASE LOWER(TRIM(`mode`)) ',
                    'WHEN ''online'' THEN ''Online'' WHEN ''offline'' THEN ''Offline'' ',
                    'WHEN ''hybrid'' THEN ''Hybrid'' ELSE NULL END ',
                    'WHERE `mode` IS NOT NULL AND LOWER(TRIM(`mode`)) IN (''online'', ''offline'', ''hybrid'', '''')');
    PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

    SET @bad = 0;
    SET @q = CONCAT('SELECT COUNT(*) INTO @bad FROM `', tbl, '` ',
                    'WHERE `mode` IS NOT NULL AND BINARY `mode` NOT IN (''Online'', ''Offline'', ''Hybrid'')');
    PREPARE s FROM @q; EXECUTE s; DEALLOCATE PREPARE s;

    IF @bad = 0 THEN
      SET @ddl = CONCAT('ALTER TABLE `', tbl, '` MODIFY COLUMN `mode` ENUM(''Online'', ''Offline'', ''Hybrid'') NULL');
      PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;
    END IF;
  END IF;
END //
DELIMITER ;

CALL event_mode_migrate('events');
CALL event_mode_migrate('training');

DROP PROCEDURE IF EXISTS event_mode_migrate;
