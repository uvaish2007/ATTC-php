-- ============================================================================
--  ATTS IQAC Portal — seed data
--  Run AFTER schema.sql.
--
--  Passwords are bcrypt hashes (PHP password_verify compatible). Plaintext:
--    mohameduvaish132@gmail.com / uvaish123   (Admin)
--    director@atts.edu          / director123 (Director)
--    hod@atts.edu               / hod12345    (HoD, CSBS)
--    coordinator@atts.edu       / coord1234   (Coordinator, CSBS)
--    faculty@atts.edu           / faculty123  (Faculty, CSBS)
-- ============================================================================

USE atts_main;

-- --- users -----------------------------------------------------------------
INSERT INTO users (name, email, password, role, department) VALUES
  ('Mohamed Uvaish', 'mohameduvaish132@gmail.com', '$2b$10$odWGO0CYqZROCWNamUohveplY0xEWb5MGGfThJehpvIfsICSmWl.q', 'Admin',       NULL),
  ('Director',       'director@atts.edu',          '$2b$10$b2nf8oLreJewJW8chN1iuO20EOdgEfZMfVs5JiStqq5cxRBHPg53W', 'Director',    NULL),
  ('HoD - CSBS',     'hod@atts.edu',               '$2b$10$YhHdAMF5uZFIq70Ywg/wv.mT60Wef8KzGeagoFhRAeVcplK8kf/ZG', 'HoD',         'CSBS'),
  ('Coordinator',    'coordinator@atts.edu',       '$2b$10$g9Hq5x49dBYVfhHdNxHvhuNQfgvHAAeaDD2nPlT8ilyw2yK2WG5pu', 'Coordinator', 'CSBS'),
  ('Faculty',        'faculty@atts.edu',           '$2b$10$zrETbP0/QiBTrXaGU4yAmOU5BJN47J0nCSpV1X5yBfVRNi5cbhjsG', 'Faculty',     'CSBS');

-- --- departments -----------------------------------------------------------
INSERT INTO departments (name, code) VALUES
  ('CSBS',   'CSBS'),
  ('AI & DS','AIDS'),
  ('Agri',   'AGRI');

-- --- metrics ---------------------------------------------------------------
INSERT INTO metrics (name, category, proof_required) VALUES
  ('Journal Publications',   'Publication',         1),
  ('Book & Book Chapters',   'Publication',         1),
  ('Conference Publications','Publication',         1),
  ('Patents & Copyrights',   'Publication',         1),
  ('MoU Signed',             'Collaboration',       1),
  ('FDP Participation',      'Faculty Development',  1),
  ('Placements',             'Student',             1),
  ('Students Internship',    'Student',             1),
  ('NPTEL',                  'Student',             1);

-- --- sample journal publications (so the dashboard has data) ----------------
INSERT INTO journal_publications
  (faculty_name, department, academic_year, author_type, co_authors, paper_title,
   journal_name, journal_type, issn, volume_issue, publication_month, publication_year,
   doi, status, created_by)
VALUES
  ('Dr.S.Sajithabanu', 'CSBS', '2025-26', 'Author-1',
   'A. Asrin Mahmootha, B. Aysha Banu',
   'Intelligent College Management System with Real-Time Monitoring',
   'MAT Journals', 'UGC Care', 'e-ISSN: 2456-9437', 'Vol. 10, Issue 2', '05', '2025',
   'https://doi.org/10.46610/JODMM.2025.v10i02.002', 'Approved',
   (SELECT id FROM users WHERE email='mohameduvaish132@gmail.com')),

  ('Ms.R.Sowmiya', 'CSBS', '2025-26', 'Author-1',
   'Ms.G.Thiviya Bharathy, Ms.B.Seyed Rasiyammal',
   'Hybrid GAN and CNN Model for Plant Disease Detection',
   'YMER Journals', 'Scopus', 'ISSN : 0044-0477', 'Volume 24 : Issue 12', '12', '2025',
   'DOI :10.37896/YMER24.12/52', 'Approved',
   (SELECT id FROM users WHERE email='mohameduvaish132@gmail.com')),

  ('Ms.A.Ruba', 'AI & DS', '2025-26', 'Author-1', NULL,
   'Secure Data Transmission using GEM Firewall',
   'MAT Journals', 'UGC Care', 'e-ISSN: 2582-2179', 'Volume 10, Issue 3', '12', '2025',
   NULL, 'Approved',
   (SELECT id FROM users WHERE email='mohameduvaish132@gmail.com')),

  ('Faculty', 'CSBS', '2025-26', 'Author-1', NULL,
   'My First Faculty Paper (pending review)',
   'Test Journal', 'Scopus', NULL, NULL, '07', '2026',
   NULL, 'Submitted',
   (SELECT id FROM users WHERE email='faculty@atts.edu'));
