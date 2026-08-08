-- ---------------------------------------------------------------------------
--  Sample data for the seven template-driven record types, so every dashboard
--  and report shows real content. Records are attributed to each department's
--  faculty (created_by) and HoD (approved_by), matching the existing seed.
--
--  Student participations include TEAM events entered once per participant:
--  four Kabaddi players / three Hackathon members share one event, so the
--  academy tally counts the activity ONCE while each student keeps their own
--  row (individual credit). This is what the dashboard's de-duplication shows.
--
--  Re-runnable: every seeded row carries a sample link, deleted first below.
-- ---------------------------------------------------------------------------
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

DELETE FROM online_courses         WHERE certificate_link LIKE 'https://iqac.msec.edu/sample/%';
DELETE FROM nss                    WHERE report_link      LIKE 'https://iqac.msec.edu/sample/%';
DELETE FROM value_added_courses    WHERE report_link      LIKE 'https://iqac.msec.edu/sample/%';
DELETE FROM training               WHERE report_link      LIKE 'https://iqac.msec.edu/sample/%';
DELETE FROM summer_training        WHERE certificate_link LIKE 'https://iqac.msec.edu/sample/%';
DELETE FROM student_achievements   WHERE certificate_link LIKE 'https://iqac.msec.edu/sample/%';
DELETE FROM student_participations WHERE certificate_link LIKE 'https://iqac.msec.edu/sample/%';

-- Online Courses -------------------------------------------------------------
INSERT INTO online_courses
  (candidate_name, department, academic_year, category, course_title, provider, duration, month_year, certificate_link, status, created_by, approved_by) VALUES
('Dr. K. Ramesh',  'CSE',     '2025-26', 'Faculty', 'Deep Learning Specialization', 'Coursera', '16 weeks', '02/2026', 'https://iqac.msec.edu/sample/oc1',  'Approved',  17, 8),
('S. Arun',        'CSE',     '2025-26', 'Student', 'Python for Everybody',         'Coursera', '8 weeks',  '01/2026', 'https://iqac.msec.edu/sample/oc2',  'Approved',  17, 8),
('Dr. S. Latha',   'ECE',     '2025-26', 'Faculty', 'Embedded Systems Design',      'NPTEL',    '12 weeks', '12/2025', 'https://iqac.msec.edu/sample/oc3',  'Approved',  18, 9),
('Dr. M. Vinoth',  'IT',      '2025-26', 'Faculty', 'Cloud Computing Basics',       'Udemy',    '6 weeks',  '03/2026', 'https://iqac.msec.edu/sample/oc4',  'Submitted', 21, NULL),
('R. Karthik',     'MECH',    '2025-26', 'Student', 'CAD Fundamentals',             'Coursera', '5 weeks',  '11/2025', 'https://iqac.msec.edu/sample/oc5',  'Approved',  19, 10),
('Dr. P. Anand',   'EEE',     '2025-26', 'Faculty', 'Power Electronics',            'NPTEL',    '10 weeks', '02/2026', 'https://iqac.msec.edu/sample/oc6',  'Approved',  20, 11),
('Dr. J. Priya',   'CSBS',    '2025-26', 'Faculty', 'Business Analytics',           'edX',      '8 weeks',  '01/2026', 'https://iqac.msec.edu/sample/oc7',  'Draft',     5,  NULL),
('Dr. N. Kavya',   'AI & DS', '2025-26', 'Faculty', 'Data Science with R',          'Coursera', '20 weeks', '03/2026', 'https://iqac.msec.edu/sample/oc8',  'Approved',  15, 6),
('T. Meena',       'AI & DS', '2024-25', 'Student', 'Machine Learning',             'Coursera', '11 weeks', '10/2024', 'https://iqac.msec.edu/sample/oc9',  'Approved',  15, 6),
('Dr. R. Sathya',  'CIVIL',   '2025-26', 'Faculty', 'Structural Analysis',          'NPTEL',    '12 weeks', '02/2026', 'https://iqac.msec.edu/sample/oc10', 'Approved',  22, 13);

-- NSS / YRC / RRC ------------------------------------------------------------
INSERT INTO nss
  (department, academic_year, activity_date, activity_name, activity_type, venue, external_agency, participants, report_link, status, created_by, approved_by) VALUES
('CSE',  '2025-26', '12/01/2026', 'Blood Donation Camp',       'NSS', 'College Auditorium', 'Red Cross Society - Mr. Kumar, Coordinator (98765 43210)', '120', 'https://iqac.msec.edu/sample/nss1', 'Approved',  17, 8),
('ECE',  '2025-26', '05/02/2026', 'Tree Plantation Drive',     'NSS', 'Campus Grounds',     'Forest Dept - Officer Ravi (94430 11220)',                '85',  'https://iqac.msec.edu/sample/nss2', 'Approved',  18, 9),
('MECH', '2025-26', '26/01/2026', 'Road Safety Awareness',     'YRC', 'Nearby Village',     'Traffic Police - Insp. Selvam (94431 55667)',             '60',  'https://iqac.msec.edu/sample/nss3', 'Approved',  19, 10),
('IT',   '2025-26', '01/03/2026', 'Blood Donation Camp',       'RRC', 'Health Centre',      'Govt Hospital - Dr. Meena (90031 22114)',                 '95',  'https://iqac.msec.edu/sample/nss4', 'Submitted', 21, NULL),
('CSBS', '2024-25', '15/08/2024', 'Independence Day Rally',    'NSS', 'Town Hall',          'District Collector Office - Tahsildar',                    '150', 'https://iqac.msec.edu/sample/nss5', 'Approved',  5,  3),
('EEE',  '2025-26', '10/02/2026', 'Energy Conservation Drive', 'NSS', 'Village Panchayat',  'TNEB - AE Mr. Bala',                                      '70',  'https://iqac.msec.edu/sample/nss6', 'Approved',  20, 11);

-- Value Added Courses --------------------------------------------------------
INSERT INTO value_added_courses
  (department, academic_year, from_date, to_date, course_title, mode, resource_person, participants, report_link, status, created_by, approved_by) VALUES
('CSE',   '2025-26', '05/01/2026', '20/01/2026', 'Full Stack Web Development', 'Offline', 'Mr. Sundar, Senior Developer, TCS (90000 12345)', '55', 'https://iqac.msec.edu/sample/vac1', 'Approved',  17, 8),
('ECE',   '2025-26', '10/02/2026', '24/02/2026', 'PCB Design & Fabrication',   'Hybrid',  'Dr. Anita, Professor, IIT Madras',               '40', 'https://iqac.msec.edu/sample/vac2', 'Approved',  18, 9),
('IT',    '2025-26', '15/01/2026', '29/01/2026', 'Cybersecurity Essentials',   'Online',  'Mr. Raj, CISO, Infosys',                         '60', 'https://iqac.msec.edu/sample/vac3', 'Submitted', 21, NULL),
('MECH',  '2024-25', '01/09/2024', '15/09/2024', 'Industrial Automation',      'Offline', 'Er. Vasanth, Bosch Ltd.',                        '35', 'https://iqac.msec.edu/sample/vac4', 'Approved',  19, 10),
('AI & DS','2025-26','12/02/2026', '26/02/2026', 'Generative AI Workshop',     'Offline', 'Dr. Kavya, Data Scientist, Google',              '48', 'https://iqac.msec.edu/sample/vac5', 'Approved',  15, 6);

-- Training programmes --------------------------------------------------------
INSERT INTO training
  (department, academic_year, event_date, event_title, event_type, mode, resource_person, participants, sponsorship, report_link, status, created_by, approved_by) VALUES
('CSE',   '2025-26', '05/03/2026', 'Aptitude & Soft Skills Bootcamp', 'Soft Skills',      'Offline', 'Ms. Latha, Corporate Trainer, CoCubes', '150', 'CII',   'https://iqac.msec.edu/sample/tr1', 'Approved',  17, 8),
('ECE',   '2025-26', '18/02/2026', 'Career Guidance Seminar',         'Career Guidance',  'Offline', 'Mr. Prakash, HR Manager, Wipro',        '200', 'AICTE', 'https://iqac.msec.edu/sample/tr2', 'Approved',  18, 9),
('IT',    '2025-26', '22/01/2026', 'ICT Skills Workshop',             'ICT',              'Hybrid',  'Dr. Kannan, Trainer, NIIT',             '90',  '-',     'https://iqac.msec.edu/sample/tr3', 'Draft',     21, NULL),
('CIVIL', '2024-25', '10/10/2024', 'Life Skills Program',             'Life Skills',      'Offline', 'Ms. Deepa, Counsellor',                 '110', '-',     'https://iqac.msec.edu/sample/tr4', 'Approved',  22, 13),
('EEE',   '2025-26', '28/02/2026', 'Placement Readiness Counselling', 'Counselling',      'Offline', 'Mr. Ganesh, Placement Officer',         '130', '-',     'https://iqac.msec.edu/sample/tr5', 'Approved',  20, 11);

-- Summer / Winter Training ---------------------------------------------------
INSERT INTO summer_training
  (reg_no, student_name, department, academic_year, title, industry, duration, days, certificate_link, status, created_by, approved_by) VALUES
('21CS001', 'S. Arun',    'CSE',  '2025-26', 'Web Development Internship', 'Zoho Corp, Chennai',          '1 month',  '30', 'https://iqac.msec.edu/sample/st1', 'Approved',  17, 8),
('21EC012', 'R. Divya',   'ECE',  '2025-26', 'VLSI Design Training',       'HCL Technologies, Chennai',   '6 weeks',  '42', 'https://iqac.msec.edu/sample/st2', 'Approved',  18, 9),
('21ME045', 'K. Vijay',   'MECH', '2025-26', 'CNC Machining',             'Ashok Leyland, Hosur',        '15 days',  '15', 'https://iqac.msec.edu/sample/st3', 'Approved',  19, 10),
('21IT023', 'M. Priya',   'IT',   '2025-26', 'Cloud & DevOps',            'Freshworks, Chennai',         '1 month',  '28', 'https://iqac.msec.edu/sample/st4', 'Submitted', 21, NULL),
('20CB008', 'A. Karthik', 'CSBS', '2024-25', 'Data Analytics',            'Standard Chartered GBS',      '2 months', '45', 'https://iqac.msec.edu/sample/st5', 'Approved',  5,  3),
('21EE034', 'V. Nandini', 'EEE',  '2025-26', 'Power Systems Internship',  'TNEB, Chennai',               '1 month',  '30', 'https://iqac.msec.edu/sample/st6', 'Approved',  20, 11);

-- Student Achievements -------------------------------------------------------
INSERT INTO student_achievements
  (reg_no, student_name, department, academic_year, event_type, event_name, function_name, event_date, team_individual, level_secured, position_secured, organising_institution, certificate_link, status, created_by, approved_by) VALUES
('21CS017', 'P. Sneha',  'CSE',  '2025-26', 'Technical', 'Smart India Hackathon',        'SIH 2026 Grand Finale',    '20/02/2026', 'Team',       'National', 'Winner',     'AICTE / Ministry of Education', 'https://iqac.msec.edu/sample/ach1', 'Approved', 17, 8),
('21EC030', 'V. Ramesh', 'ECE',  '2025-26', 'Technical', 'IEEE Paper Presentation',      'TechXplore 2026',          '18/01/2026', 'Individual', 'National', 'First',      'IEEE Madras Section',           'https://iqac.msec.edu/sample/ach2', 'Approved', 18, 9),
('21ME011', 'N. Kavya',  'MECH', '2025-26', 'Sports',    'Inter-University Athletics',   'Anna Univ Sports Meet',    '02/02/2026', 'Individual', 'State',    'Gold Medal', 'Anna University',               'https://iqac.msec.edu/sample/ach3', 'Approved', 19, 10),
('20CB021', 'J. Hari',   'CSBS', '2024-25', 'Cultural',  'Classical Dance Competition',  'Kalai Vizha 2025',         '12/03/2025', 'Individual', 'State',    'Runner-up',  'Bharathidasan University',      'https://iqac.msec.edu/sample/ach4', 'Approved', 5,  3),
('21IT005', 'D. Suresh', 'IT',   '2025-26', 'Technical', 'National Coding Contest',      'CodeStorm 2026',           '08/03/2026', 'Individual', 'National', 'Second',     'CodeChef',                      'https://iqac.msec.edu/sample/ach5', 'Submitted', 21, NULL);

-- Student Participations -----------------------------------------------------
-- A CSE Kabaddi TEAM (4 players, one event) and an ECE Hackathon TEAM (3
-- members, one event): each student is a row (individual credit); the academy
-- counts each event once.
INSERT INTO student_participations
  (reg_no, student_name, department, academic_year, activity_category, event_type, event_name, function_name, event_date, team_individual, level_secured, position_secured, organising_institution, certificate_link, status, created_by, approved_by) VALUES
('21CS040', 'A. Bala',    'CSE',  '2025-26', 'Extra-curricular', 'Sports',   'Inter-College Kabaddi', 'Sports Fest 2026',   '14/02/2026', 'Team',       'State',    'Winner',      'Anna University',      'https://iqac.msec.edu/sample/par1', 'Approved', 17, 8),
('21CS041', 'B. Chandru', 'CSE',  '2025-26', 'Extra-curricular', 'Sports',   'Inter-College Kabaddi', 'Sports Fest 2026',   '14/02/2026', 'Team',       'State',    'Winner',      'Anna University',      'https://iqac.msec.edu/sample/par2', 'Approved', 17, 8),
('21CS042', 'C. Deepak',  'CSE',  '2025-26', 'Extra-curricular', 'Sports',   'Inter-College Kabaddi', 'Sports Fest 2026',   '14/02/2026', 'Team',       'State',    'Winner',      'Anna University',      'https://iqac.msec.edu/sample/par3', 'Approved', 17, 8),
('21CS043', 'D. Elango',  'CSE',  '2025-26', 'Extra-curricular', 'Sports',   'Inter-College Kabaddi', 'Sports Fest 2026',   '14/02/2026', 'Team',       'State',    'Winner',      'Anna University',      'https://iqac.msec.edu/sample/par4', 'Approved', 17, 8),
('21EC050', 'E. Farhan',  'ECE',  '2025-26', 'Co-curricular',    'Technical','CodeFest Hackathon',    'TechXplore 2026',    '25/01/2026', 'Team',       'National', 'Participant', 'IEEE Madras Section',  'https://iqac.msec.edu/sample/par5', 'Approved', 18, 9),
('21EC051', 'F. Gowri',   'ECE',  '2025-26', 'Co-curricular',    'Technical','CodeFest Hackathon',    'TechXplore 2026',    '25/01/2026', 'Team',       'National', 'Participant', 'IEEE Madras Section',  'https://iqac.msec.edu/sample/par6', 'Approved', 18, 9),
('21EC052', 'G. Harish',  'ECE',  '2025-26', 'Co-curricular',    'Technical','CodeFest Hackathon',    'TechXplore 2026',    '25/01/2026', 'Team',       'National', 'Participant', 'IEEE Madras Section',  'https://iqac.msec.edu/sample/par7', 'Approved', 18, 9),
('21IT060', 'H. Indhu',   'IT',   '2025-26', 'Co-curricular',    'Technical','Paper Presentation',    'Symposium 2026',     '08/02/2026', 'Individual', 'State',    'Participant', 'SSN College',          'https://iqac.msec.edu/sample/par8', 'Approved', 21, 12),
('21ME070', 'I. Jagan',   'MECH', '2025-26', 'Extra-curricular', 'Sports',   'Football Tournament',   'Sports Day 2026',    '30/01/2026', 'Team',       'University','Participant', 'Anna University',      'https://iqac.msec.edu/sample/par9', 'Submitted', 19, NULL);
