-- ---------------------------------------------------------------------------
--  New record types drawn straight from the official IQAC templates:
--    nss                   NSS / YRC / RRC Programmes Organized
--    online_courses        Online Courses Completed (faculty & students)
--    student_achievements  Students Achievements
--    student_participations Students Participations (co / extra-curricular)
--    summer_training       Students Training (Summer / Winter) Details
--    value_added_courses   Value Added Courses Conducted
--    training              Training programmes (career guidance / soft skills …)
--
--  Every table follows the same shape as the existing record tables (workflow
--  columns + proof_file), so upload.php, approvals.php and the report engine
--  pick them up with no special-casing. Additive and safe to re-run.
-- ---------------------------------------------------------------------------
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET FOREIGN_KEY_CHECKS = 1;

-- 1. NSS / YRC / RRC ---------------------------------------------------------
CREATE TABLE IF NOT EXISTS nss (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  department       VARCHAR(150) NULL,
  academic_year    VARCHAR(20)  NULL,
  activity_date    VARCHAR(50)  NULL,
  activity_name    TEXT         NULL,
  activity_type    VARCHAR(100) NULL,
  venue            VARCHAR(255) NULL,
  external_agency  VARCHAR(500) NULL,
  participants     VARCHAR(50)  NULL,
  report_link      VARCHAR(500) NULL,
  status           ENUM('Draft','Submitted','Approved','Rejected') NOT NULL DEFAULT 'Draft',
  review_remark    VARCHAR(500) NULL,
  created_by       INT NULL,
  approved_by      INT NULL,
  proof_file       VARCHAR(255) NULL,
  created_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_nss_department (department),
  KEY idx_nss_status (status),
  CONSTRAINT fk_nss_created_by  FOREIGN KEY (created_by)  REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_nss_approved_by FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Online Courses ----------------------------------------------------------
CREATE TABLE IF NOT EXISTS online_courses (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  candidate_name   VARCHAR(190) NULL,
  department       VARCHAR(150) NULL,
  academic_year    VARCHAR(20)  NULL,
  category         VARCHAR(100) NULL,
  course_title     TEXT         NULL,
  provider         VARCHAR(190) NULL,
  duration         VARCHAR(100) NULL,
  month_year       VARCHAR(50)  NULL,
  certificate_link VARCHAR(500) NULL,
  status           ENUM('Draft','Submitted','Approved','Rejected') NOT NULL DEFAULT 'Draft',
  review_remark    VARCHAR(500) NULL,
  created_by       INT NULL,
  approved_by      INT NULL,
  proof_file       VARCHAR(255) NULL,
  created_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_oc_department (department),
  KEY idx_oc_status (status),
  CONSTRAINT fk_oc_created_by  FOREIGN KEY (created_by)  REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_oc_approved_by FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Students Achievements ---------------------------------------------------
CREATE TABLE IF NOT EXISTS student_achievements (
  id                     INT AUTO_INCREMENT PRIMARY KEY,
  reg_no                 VARCHAR(50)  NULL,
  student_name           VARCHAR(190) NULL,
  department             VARCHAR(150) NULL,
  academic_year          VARCHAR(20)  NULL,
  event_type             VARCHAR(100) NULL,
  event_name             TEXT         NULL,
  function_name          VARCHAR(255) NULL,
  event_date             VARCHAR(50)  NULL,
  team_individual        VARCHAR(50)  NULL,
  level_secured          VARCHAR(120) NULL,
  position_secured       VARCHAR(120) NULL,
  organising_institution VARCHAR(255) NULL,
  certificate_link       VARCHAR(500) NULL,
  status                 ENUM('Draft','Submitted','Approved','Rejected') NOT NULL DEFAULT 'Draft',
  review_remark          VARCHAR(500) NULL,
  created_by             INT NULL,
  approved_by            INT NULL,
  proof_file             VARCHAR(255) NULL,
  created_at             TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at             TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_ach_department (department),
  KEY idx_ach_status (status),
  CONSTRAINT fk_ach_created_by  FOREIGN KEY (created_by)  REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_ach_approved_by FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Students Participations (co / extra-curricular) -------------------------
CREATE TABLE IF NOT EXISTS student_participations (
  id                     INT AUTO_INCREMENT PRIMARY KEY,
  reg_no                 VARCHAR(50)  NULL,
  student_name           VARCHAR(190) NULL,
  department             VARCHAR(150) NULL,
  academic_year          VARCHAR(20)  NULL,
  activity_category      VARCHAR(60)  NULL,
  event_type             VARCHAR(100) NULL,
  event_name             TEXT         NULL,
  function_name          VARCHAR(255) NULL,
  event_date             VARCHAR(50)  NULL,
  team_individual        VARCHAR(50)  NULL,
  level_secured          VARCHAR(120) NULL,
  position_secured       VARCHAR(120) NULL,
  organising_institution VARCHAR(255) NULL,
  certificate_link       VARCHAR(500) NULL,
  status                 ENUM('Draft','Submitted','Approved','Rejected') NOT NULL DEFAULT 'Draft',
  review_remark          VARCHAR(500) NULL,
  created_by             INT NULL,
  approved_by            INT NULL,
  proof_file             VARCHAR(255) NULL,
  created_at             TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at             TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_par_department (department),
  KEY idx_par_status (status),
  CONSTRAINT fk_par_created_by  FOREIGN KEY (created_by)  REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_par_approved_by FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Students Training (Summer / Winter) ------------------------------------
CREATE TABLE IF NOT EXISTS summer_training (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  reg_no           VARCHAR(50)  NULL,
  student_name     VARCHAR(190) NULL,
  department       VARCHAR(150) NULL,
  academic_year    VARCHAR(20)  NULL,
  title            TEXT         NULL,
  industry         VARCHAR(255) NULL,
  duration         VARCHAR(100) NULL,
  days             VARCHAR(50)  NULL,
  certificate_link VARCHAR(500) NULL,
  status           ENUM('Draft','Submitted','Approved','Rejected') NOT NULL DEFAULT 'Draft',
  review_remark    VARCHAR(500) NULL,
  created_by       INT NULL,
  approved_by      INT NULL,
  proof_file       VARCHAR(255) NULL,
  created_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_st_department (department),
  KEY idx_st_status (status),
  CONSTRAINT fk_st_created_by  FOREIGN KEY (created_by)  REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_st_approved_by FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Value Added Courses -----------------------------------------------------
CREATE TABLE IF NOT EXISTS value_added_courses (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  department       VARCHAR(150) NULL,
  academic_year    VARCHAR(20)  NULL,
  from_date        VARCHAR(50)  NULL,
  to_date          VARCHAR(50)  NULL,
  course_title     TEXT         NULL,
  mode             VARCHAR(30)  NULL,
  resource_person  VARCHAR(500) NULL,
  participants     VARCHAR(50)  NULL,
  report_link      VARCHAR(500) NULL,
  status           ENUM('Draft','Submitted','Approved','Rejected') NOT NULL DEFAULT 'Draft',
  review_remark    VARCHAR(500) NULL,
  created_by       INT NULL,
  approved_by      INT NULL,
  proof_file       VARCHAR(255) NULL,
  created_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_vac_department (department),
  KEY idx_vac_status (status),
  CONSTRAINT fk_vac_created_by  FOREIGN KEY (created_by)  REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_vac_approved_by FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Training programmes -----------------------------------------------------
CREATE TABLE IF NOT EXISTS training (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  department       VARCHAR(150) NULL,
  academic_year    VARCHAR(20)  NULL,
  event_date       VARCHAR(50)  NULL,
  event_title      TEXT         NULL,
  event_type       VARCHAR(100) NULL,
  mode             VARCHAR(30)  NULL,
  resource_person  VARCHAR(500) NULL,
  participants     VARCHAR(50)  NULL,
  sponsorship      VARCHAR(255) NULL,
  report_link      VARCHAR(500) NULL,
  status           ENUM('Draft','Submitted','Approved','Rejected') NOT NULL DEFAULT 'Draft',
  review_remark    VARCHAR(500) NULL,
  created_by       INT NULL,
  approved_by      INT NULL,
  proof_file       VARCHAR(255) NULL,
  created_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_tr_department (department),
  KEY idx_tr_status (status),
  CONSTRAINT fk_tr_created_by  FOREIGN KEY (created_by)  REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_tr_approved_by FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
