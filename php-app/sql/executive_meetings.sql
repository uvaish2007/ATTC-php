-- ---------------------------------------------------------------------------
--  Executive meetings.
--
--  When the Executive Committee finishes a meeting, the Admin records it on
--  the Academic Year page and that academic year locks for every role. Each
--  row is one finished meeting; the list is the year's audit trail.
--
--  The Academic Year page was built against this table but nothing created
--  it, so every meeting query failed quietly ("0 meetings") and recording a
--  meeting could never lock a year. models/Target.php now also creates it on
--  first use; this file is the same definition for a manual setup.
--
--  A meeting number is unique within its academic year.
--
--  Additive and safe to re-run.
-- ---------------------------------------------------------------------------
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS executive_meetings (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  academic_year  VARCHAR(9)   NOT NULL,                        -- e.g. 2025-26
  meeting_number VARCHAR(50)  NOT NULL,                        -- stored bare: "3", not "Meeting #3"
  meeting_date   DATE         NOT NULL,                        -- the day it finished
  notes          TEXT         NULL,                            -- minutes / remarks
  status         VARCHAR(20)  NOT NULL DEFAULT 'Finished',
  locked_cycle   TINYINT(1)   NOT NULL DEFAULT 1,              -- recording it locked the year
  created_by     INT          NULL,                            -- users.id of the Admin
  created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_exec_meeting (academic_year, meeting_number),
  KEY idx_exec_meeting_year (academic_year, meeting_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
