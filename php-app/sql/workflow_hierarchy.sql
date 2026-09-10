-- ---------------------------------------------------------------------------
--  ATTS Workflow Hierarchy SQL Migration
--  Updates status column on all 17 record tables to VARCHAR(50)
--  Migrates 'Submitted' records to 'HOD Pending'
-- ---------------------------------------------------------------------------

-- 1. journal_publications
ALTER TABLE journal_publications MODIFY COLUMN status VARCHAR(50) NOT NULL DEFAULT 'Draft';
UPDATE journal_publications SET status = 'HOD Pending' WHERE status = 'Submitted';

-- 2. book_publications
ALTER TABLE book_publications MODIFY COLUMN status VARCHAR(50) NOT NULL DEFAULT 'Draft';
UPDATE book_publications SET status = 'HOD Pending' WHERE status = 'Submitted';

-- 3. conference_publications
ALTER TABLE conference_publications MODIFY COLUMN status VARCHAR(50) NOT NULL DEFAULT 'Draft';
UPDATE conference_publications SET status = 'HOD Pending' WHERE status = 'Submitted';

-- 4. patents
ALTER TABLE patents MODIFY COLUMN status VARCHAR(50) NOT NULL DEFAULT 'Draft';
UPDATE patents SET status = 'HOD Pending' WHERE status = 'Submitted';

-- 5. fdp
ALTER TABLE fdp MODIFY COLUMN status VARCHAR(50) NOT NULL DEFAULT 'Draft';
UPDATE fdp SET status = 'HOD Pending' WHERE status = 'Submitted';

-- 6. mou
ALTER TABLE mou MODIFY COLUMN status VARCHAR(50) NOT NULL DEFAULT 'Draft';
UPDATE mou SET status = 'HOD Pending' WHERE status = 'Submitted';

-- 7. events
ALTER TABLE events MODIFY COLUMN status VARCHAR(50) NOT NULL DEFAULT 'Draft';
UPDATE events SET status = 'HOD Pending' WHERE status = 'Submitted';

-- 8. nptel
ALTER TABLE nptel MODIFY COLUMN status VARCHAR(50) NOT NULL DEFAULT 'Draft';
UPDATE nptel SET status = 'HOD Pending' WHERE status = 'Submitted';

-- 9. internships
ALTER TABLE internships MODIFY COLUMN status VARCHAR(50) NOT NULL DEFAULT 'Draft';
UPDATE internships SET status = 'HOD Pending' WHERE status = 'Submitted';

-- 10. placements
ALTER TABLE placements MODIFY COLUMN status VARCHAR(50) NOT NULL DEFAULT 'Draft';
UPDATE placements SET status = 'HOD Pending' WHERE status = 'Submitted';

-- 11. nss (if table exists)
SET @ddl = IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nss') > 0,
  'ALTER TABLE nss MODIFY COLUMN status VARCHAR(50) NOT NULL DEFAULT ''Draft''', 'DO 0');
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;
SET @ddl = IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'nss') > 0,
  'UPDATE nss SET status = ''HOD Pending'' WHERE status = ''Submitted''', 'DO 0');
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

-- 12. online_courses (if table exists)
SET @ddl = IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'online_courses') > 0,
  'ALTER TABLE online_courses MODIFY COLUMN status VARCHAR(50) NOT NULL DEFAULT ''Draft''', 'DO 0');
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;
SET @ddl = IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'online_courses') > 0,
  'UPDATE online_courses SET status = ''HOD Pending'' WHERE status = ''Submitted''', 'DO 0');
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

-- 13. student_achievements (if table exists)
SET @ddl = IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'student_achievements') > 0,
  'ALTER TABLE student_achievements MODIFY COLUMN status VARCHAR(50) NOT NULL DEFAULT ''Draft''', 'DO 0');
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;
SET @ddl = IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'student_achievements') > 0,
  'UPDATE student_achievements SET status = ''HOD Pending'' WHERE status = ''Submitted''', 'DO 0');
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

-- 14. student_participations (if table exists)
SET @ddl = IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'student_participations') > 0,
  'ALTER TABLE student_participations MODIFY COLUMN status VARCHAR(50) NOT NULL DEFAULT ''Draft''', 'DO 0');
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;
SET @ddl = IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'student_participations') > 0,
  'UPDATE student_participations SET status = ''HOD Pending'' WHERE status = ''Submitted''', 'DO 0');
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

-- 15. summer_training (if table exists)
SET @ddl = IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'summer_training') > 0,
  'ALTER TABLE summer_training MODIFY COLUMN status VARCHAR(50) NOT NULL DEFAULT ''Draft''', 'DO 0');
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;
SET @ddl = IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'summer_training') > 0,
  'UPDATE summer_training SET status = ''HOD Pending'' WHERE status = ''Submitted''', 'DO 0');
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

-- 16. value_added_courses (if table exists)
SET @ddl = IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'value_added_courses') > 0,
  'ALTER TABLE value_added_courses MODIFY COLUMN status VARCHAR(50) NOT NULL DEFAULT ''Draft''', 'DO 0');
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;
SET @ddl = IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'value_added_courses') > 0,
  'UPDATE value_added_courses SET status = ''HOD Pending'' WHERE status = ''Submitted''', 'DO 0');
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

-- 17. training (if table exists)
SET @ddl = IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'training') > 0,
  'ALTER TABLE training MODIFY COLUMN status VARCHAR(50) NOT NULL DEFAULT ''Draft''', 'DO 0');
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;
SET @ddl = IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'training') > 0,
  'UPDATE training SET status = ''HOD Pending'' WHERE status = ''Submitted''', 'DO 0');
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;
