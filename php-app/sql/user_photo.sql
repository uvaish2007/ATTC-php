-- ---------------------------------------------------------------------------
--  Profile Photo Migration
--
--  Adds `photo` to the EXISTING users table so every account (Faculty,
--  Coordinator, HoD, Dean, Principal, Admin) can store one passport-size
--  photograph. It holds only the stored file name; the image itself lives in
--  uploads/photos/ and is served by photo.php.
--
--  No new user / faculty / profile table is created. Safe and idempotent:
--  skips if the column already exists.
-- ---------------------------------------------------------------------------

SET @ddl = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
       AND COLUMN_NAME = 'photo') > 0,
  'DO 0',
  'ALTER TABLE users ADD COLUMN photo VARCHAR(255) NULL AFTER phone');
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;
