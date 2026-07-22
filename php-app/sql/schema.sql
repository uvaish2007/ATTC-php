-- ============================================================================
--  ATTS IQAC Portal — MySQL schema
--  Converted from the MongoDB (Mongoose) models 1:1. Collection -> table,
--  document field -> column (camelCase -> snake_case). Array fields
--  (coAuthors, proofs) are stored as TEXT, matching how the app renders them.
--
--  Run this first, then seed.sql.
--  Engine: InnoDB, charset utf8mb4 (full Unicode incl. em-dashes in report text).
-- ============================================================================

CREATE DATABASE IF NOT EXISTS atts_main
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE atts_main;

SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------------
--  users
-- ---------------------------------------------------------------------------
DROP TABLE IF EXISTS users;
CREATE TABLE users (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(150) NOT NULL,
  email       VARCHAR(190) NOT NULL,
  password    VARCHAR(255) NOT NULL,
  role        ENUM('Admin','Director','HoD','Coordinator','Faculty') NOT NULL DEFAULT 'Faculty',
  department  VARCHAR(150) NULL,
  phone       VARCHAR(30)  NULL,
  status      TINYINT(1)   NOT NULL DEFAULT 1,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_users_email (email),
  KEY idx_users_role (role),
  KEY idx_users_department (department)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
--  departments  (the admin-managed canonical list)
-- ---------------------------------------------------------------------------
DROP TABLE IF EXISTS departments;
CREATE TABLE departments (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(150) NOT NULL,
  code        VARCHAR(30)  NOT NULL,
  hod_id      INT NULL,
  status      TINYINT(1)   NOT NULL DEFAULT 1,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_departments_name (name),
  UNIQUE KEY uq_departments_code (code),
  KEY fk_departments_hod (hod_id),
  CONSTRAINT fk_departments_hod FOREIGN KEY (hod_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
--  metrics  (record types that can be tracked / submitted)
-- ---------------------------------------------------------------------------
DROP TABLE IF EXISTS metrics;
CREATE TABLE metrics (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  name           VARCHAR(150) NOT NULL,
  category       VARCHAR(100) NOT NULL,
  proof_required TINYINT(1)   NOT NULL DEFAULT 0,
  status         TINYINT(1)   NOT NULL DEFAULT 1,
  created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_metrics_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
--  targets
-- ---------------------------------------------------------------------------
DROP TABLE IF EXISTS targets;
CREATE TABLE targets (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  department     VARCHAR(150) NULL,
  academic_year  VARCHAR(20)  NULL,
  metric         VARCHAR(150) NULL,
  target_value   INT          NOT NULL DEFAULT 0,
  achieved_value INT          NOT NULL DEFAULT 0,
  status         VARCHAR(30)  NOT NULL DEFAULT 'Draft',
  remarks        TEXT         NULL,
  created_by     INT NULL,
  approved_by    INT NULL,
  created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_targets_department (department),
  CONSTRAINT fk_targets_created_by  FOREIGN KEY (created_by)  REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_targets_approved_by FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
--  journal_publications
-- ---------------------------------------------------------------------------
DROP TABLE IF EXISTS journal_publications;
CREATE TABLE journal_publications (
  id                INT AUTO_INCREMENT PRIMARY KEY,
  faculty_name      VARCHAR(190) NOT NULL,
  department        VARCHAR(150) NOT NULL,
  academic_year     VARCHAR(20)  NOT NULL,
  author_type       VARCHAR(50)  NULL,
  co_authors        TEXT         NULL,          -- comma-separated
  paper_title       TEXT         NOT NULL,
  journal_name      VARCHAR(255) NULL,
  journal_type      VARCHAR(100) NULL,
  issn              VARCHAR(100) NULL,
  volume_issue      VARCHAR(150) NULL,
  publication_month VARCHAR(20)  NULL,
  publication_year  VARCHAR(10)  NULL,
  doi               VARCHAR(255) NULL,
  journal_link      VARCHAR(500) NULL,
  document_link     VARCHAR(500) NULL,
  proofs            TEXT         NULL,          -- JSON array of file paths
  status            ENUM('Draft','Submitted','Approved','Rejected') NOT NULL DEFAULT 'Draft',
  review_remark     VARCHAR(500) NULL,
  created_by        INT NULL,
  approved_by       INT NULL,
  created_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_journal_department (department),
  KEY idx_journal_status (status),
  KEY idx_journal_created_by (created_by),
  KEY idx_journal_year (academic_year),
  CONSTRAINT fk_journal_created_by  FOREIGN KEY (created_by)  REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_journal_approved_by FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
--  book_publications
-- ---------------------------------------------------------------------------
DROP TABLE IF EXISTS book_publications;
CREATE TABLE book_publications (
  id                   INT AUTO_INCREMENT PRIMARY KEY,
  faculty_name         VARCHAR(190) NOT NULL,
  department           VARCHAR(150) NOT NULL,
  academic_year        VARCHAR(20)  NOT NULL,
  publication_category VARCHAR(50)  NULL,
  title                TEXT         NOT NULL,
  publisher_name       VARCHAR(255) NULL,
  isbn                 VARCHAR(100) NULL,
  publication_month    VARCHAR(20)  NULL,
  publication_year     VARCHAR(10)  NULL,
  document_link        VARCHAR(500) NULL,
  proofs               TEXT         NULL,
  status               ENUM('Draft','Submitted','Approved','Rejected') NOT NULL DEFAULT 'Draft',
  review_remark        VARCHAR(500) NULL,
  created_by           INT NULL,
  approved_by          INT NULL,
  created_at           TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at           TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_book_department (department),
  KEY idx_book_status (status),
  KEY idx_book_created_by (created_by),
  KEY idx_book_year (academic_year),
  CONSTRAINT fk_book_created_by  FOREIGN KEY (created_by)  REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_book_approved_by FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
--  conference_publications
-- ---------------------------------------------------------------------------
DROP TABLE IF EXISTS conference_publications;
CREATE TABLE conference_publications (
  id                INT AUTO_INCREMENT PRIMARY KEY,
  faculty_name      VARCHAR(190) NOT NULL,
  department        VARCHAR(150) NOT NULL,
  academic_year     VARCHAR(20)  NOT NULL,
  author_type       VARCHAR(50)  NULL,
  co_authors        TEXT         NULL,
  paper_title       TEXT         NOT NULL,
  conference_name   VARCHAR(255) NULL,
  conference_type   VARCHAR(100) NULL,
  isbn              VARCHAR(100) NULL,
  conference_date   VARCHAR(50)  NULL,
  venue             VARCHAR(255) NULL,
  proceedings_link  VARCHAR(500) NULL,
  document_link     VARCHAR(500) NULL,
  proofs            TEXT         NULL,
  status            ENUM('Draft','Submitted','Approved','Rejected') NOT NULL DEFAULT 'Draft',
  review_remark     VARCHAR(500) NULL,
  created_by        INT NULL,
  approved_by       INT NULL,
  created_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_conf_department (department),
  KEY idx_conf_status (status),
  KEY idx_conf_created_by (created_by),
  CONSTRAINT fk_conf_created_by  FOREIGN KEY (created_by)  REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_conf_approved_by FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
--  patents
-- ---------------------------------------------------------------------------
DROP TABLE IF EXISTS patents;
CREATE TABLE patents (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  faculty_name     VARCHAR(190) NOT NULL,
  department       VARCHAR(150) NOT NULL,
  academic_year    VARCHAR(20)  NOT NULL,
  category         VARCHAR(50)  NULL,
  title            TEXT         NOT NULL,
  patent_number    VARCHAR(100) NULL,
  publication_date VARCHAR(50)  NULL,
  document_link    VARCHAR(500) NULL,
  status           ENUM('Draft','Submitted','Approved','Rejected') NOT NULL DEFAULT 'Draft',
  review_remark    VARCHAR(500) NULL,
  created_by       INT NULL,
  approved_by      INT NULL,
  created_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_patent_department (department),
  KEY idx_patent_status (status),
  KEY idx_patent_created_by (created_by),
  CONSTRAINT fk_patent_created_by  FOREIGN KEY (created_by)  REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_patent_approved_by FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
--  fdp  (faculty development — FDP / workshop / seminar)
-- ---------------------------------------------------------------------------
DROP TABLE IF EXISTS fdp;
CREATE TABLE fdp (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  faculty_name    VARCHAR(190) NOT NULL,
  department      VARCHAR(150) NOT NULL,
  from_date       VARCHAR(50)  NULL,
  to_date         VARCHAR(50)  NULL,
  event_type      VARCHAR(50)  NULL,
  title           TEXT         NOT NULL,
  mode            VARCHAR(30)  NULL,
  organized_by    VARCHAR(255) NULL,
  certificate_link VARCHAR(500) NULL,
  status          ENUM('Draft','Submitted','Approved','Rejected') NOT NULL DEFAULT 'Draft',
  review_remark   VARCHAR(500) NULL,
  created_by      INT NULL,
  approved_by     INT NULL,
  created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_fdp_department (department),
  KEY idx_fdp_status (status),
  KEY idx_fdp_created_by (created_by),
  CONSTRAINT fk_fdp_created_by  FOREIGN KEY (created_by)  REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_fdp_approved_by FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
--  mou
-- ---------------------------------------------------------------------------
DROP TABLE IF EXISTS mou;
CREATE TABLE mou (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  department    VARCHAR(150) NULL,
  signed_date   VARCHAR(50)  NULL,
  organization  TEXT         NULL,
  valid_upto    VARCHAR(50)  NULL,
  purpose       TEXT         NULL,
  document_link VARCHAR(500) NULL,
  status        ENUM('Draft','Submitted','Approved','Rejected') NOT NULL DEFAULT 'Draft',
  review_remark VARCHAR(500) NULL,
  created_by    INT NULL,
  approved_by   INT NULL,
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_mou_department (department),
  KEY idx_mou_status (status),
  CONSTRAINT fk_mou_created_by  FOREIGN KEY (created_by)  REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_mou_approved_by FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
--  events
-- ---------------------------------------------------------------------------
DROP TABLE IF EXISTS events;
CREATE TABLE events (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  department      VARCHAR(150) NULL,
  event_date      VARCHAR(50)  NULL,
  event_title     TEXT         NULL,
  event_type      VARCHAR(100) NULL,
  mode            VARCHAR(30)  NULL,
  resource_person VARCHAR(190) NULL,
  designation     VARCHAR(190) NULL,
  contact_details VARCHAR(255) NULL,
  participants    VARCHAR(50)  NULL,
  sponsorship     VARCHAR(255) NULL,
  report_link     VARCHAR(500) NULL,
  status          ENUM('Draft','Submitted','Approved','Rejected') NOT NULL DEFAULT 'Draft',
  review_remark   VARCHAR(500) NULL,
  created_by      INT NULL,
  approved_by     INT NULL,
  created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_events_department (department),
  KEY idx_events_status (status),
  CONSTRAINT fk_events_created_by  FOREIGN KEY (created_by)  REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_events_approved_by FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
--  nptel  (student online courses)
-- ---------------------------------------------------------------------------
DROP TABLE IF EXISTS nptel;
CREATE TABLE nptel (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  candidate_name   VARCHAR(190) NULL,
  department       VARCHAR(150) NULL,
  category         VARCHAR(100) NULL,
  course_title     TEXT         NULL,
  session          VARCHAR(100) NULL,
  grade            VARCHAR(50)  NULL,
  certificate_link VARCHAR(500) NULL,
  status           ENUM('Draft','Submitted','Approved','Rejected') NOT NULL DEFAULT 'Draft',
  review_remark    VARCHAR(500) NULL,
  created_by       INT NULL,
  approved_by      INT NULL,
  created_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_nptel_department (department),
  KEY idx_nptel_status (status),
  CONSTRAINT fk_nptel_created_by  FOREIGN KEY (created_by)  REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_nptel_approved_by FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
--  internships  (student — no department, matching the model)
-- ---------------------------------------------------------------------------
DROP TABLE IF EXISTS internships;
CREATE TABLE internships (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  reg_no           VARCHAR(50)  NULL,
  student_name     VARCHAR(190) NULL,
  title            TEXT         NULL,
  industry         VARCHAR(255) NULL,
  address          VARCHAR(255) NULL,
  duration         VARCHAR(100) NULL,
  days             VARCHAR(50)  NULL,
  certificate_link VARCHAR(500) NULL,
  status           ENUM('Draft','Submitted','Approved','Rejected') NOT NULL DEFAULT 'Draft',
  review_remark    VARCHAR(500) NULL,
  created_by       INT NULL,
  approved_by      INT NULL,
  created_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_intern_status (status),
  CONSTRAINT fk_intern_created_by  FOREIGN KEY (created_by)  REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_intern_approved_by FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
--  placements  (student — no department, matching the model)
-- ---------------------------------------------------------------------------
DROP TABLE IF EXISTS placements;
CREATE TABLE placements (
  id                     INT AUTO_INCREMENT PRIMARY KEY,
  reg_no                 VARCHAR(50)  NULL,
  student_name           VARCHAR(190) NULL,
  job_title              VARCHAR(190) NULL,
  mode                   VARCHAR(50)  NULL,
  company                VARCHAR(255) NULL,
  contact_details        VARCHAR(255) NULL,
  pay_scale              VARCHAR(100) NULL,
  appointment_order_link VARCHAR(500) NULL,
  status                 ENUM('Draft','Submitted','Approved','Rejected') NOT NULL DEFAULT 'Draft',
  review_remark          VARCHAR(500) NULL,
  created_by             INT NULL,
  approved_by            INT NULL,
  created_at             TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at             TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_placement_status (status),
  CONSTRAINT fk_placement_created_by  FOREIGN KEY (created_by)  REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_placement_approved_by FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
