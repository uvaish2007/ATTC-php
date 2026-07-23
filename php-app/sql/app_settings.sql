-- ---------------------------------------------------------------------------
--  app_settings — small key/value store for institution-wide choices.
--
--  Holds things an Admin sets once for everyone, such as which report template
--  every department's report must follow. Additive and safe to re-run.
-- ---------------------------------------------------------------------------
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS app_settings (
  name        VARCHAR(64) PRIMARY KEY,
  value       TEXT NULL,
  updated_by  INT NULL,
  updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_app_settings_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The report layout every department follows. 'full' = the seven-column
-- proforma with the two Achieved periods; 'compact' = a single Achieved column.
INSERT INTO app_settings (name, value)
SELECT 'report_template', 'full'
WHERE NOT EXISTS (SELECT 1 FROM app_settings WHERE name = 'report_template');
