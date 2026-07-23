-- ---------------------------------------------------------------------------
--  Timed unlock permits for frozen targets.
--
--  Once an Admin locks a department's targets, a HoD who needs to change them
--  asks for an unlock with a reason. The Admin grants it, which opens a timed
--  edit window (default 12 hours, Admin-configurable). While the window is
--  open the HoD may edit and re-submit freely; when it expires the targets
--  re-freeze and the HoD must request again.
--
--  There is no background job: the window is enforced by comparing the current
--  time against unlocked_until on every request, and the dashboard counts down
--  to that same instant.
--
--  Additive and safe to re-run.
-- ---------------------------------------------------------------------------
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS unlock_requests (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  department     VARCHAR(150) NOT NULL,
  requested_by   INT          NULL,
  reason         TEXT         NULL,
  status         VARCHAR(20)  NOT NULL DEFAULT 'Requested',   -- Requested | Granted | Denied | Expired
  hours          INT          NULL,                            -- window length granted
  granted_by     INT          NULL,
  granted_at     DATETIME     NULL,
  unlocked_until DATETIME     NULL,
  admin_note     VARCHAR(255) NULL,
  created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_unlock_dept_status (department, status),
  CONSTRAINT fk_unlock_requested_by FOREIGN KEY (requested_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_unlock_granted_by   FOREIGN KEY (granted_by)   REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The default edit-window length (hours). Admin can change it when granting.
INSERT INTO app_settings (name, value)
SELECT 'unlock_hours', '12'
WHERE NOT EXISTS (SELECT 1 FROM app_settings WHERE name = 'unlock_hours');
