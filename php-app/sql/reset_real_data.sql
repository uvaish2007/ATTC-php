-- ---------------------------------------------------------------------------
-- ATTS IQAC Portal — Reset to Clean Real Production Mode
--
-- Clears out sample/dummy record entries and resets target achievement counts,
-- while preserving configured departments, user accounts, metric structures,
-- and active feature flags for fresh production deployment.
-- ---------------------------------------------------------------------------

SET FOREIGN_KEY_CHECKS = 0;

TRUNCATE TABLE journal_publications;
TRUNCATE TABLE book_publications;
TRUNCATE TABLE conference_publications;
TRUNCATE TABLE patents;
TRUNCATE TABLE fdp;
TRUNCATE TABLE mou;
TRUNCATE TABLE events;
TRUNCATE TABLE nptel;
TRUNCATE TABLE internships;
TRUNCATE TABLE placements;
TRUNCATE TABLE nss;
TRUNCATE TABLE online_courses;
TRUNCATE TABLE student_achievements;
TRUNCATE TABLE student_participations;
TRUNCATE TABLE summer_training;
TRUNCATE TABLE value_added_courses;
TRUNCATE TABLE training;

TRUNCATE TABLE announcement_reads;
TRUNCATE TABLE announcement_files;
TRUNCATE TABLE announcements;

-- Reset target achieved values to 0 for fresh real-time counting
UPDATE targets SET achieved_value = 0;

SET FOREIGN_KEY_CHECKS = 1;
