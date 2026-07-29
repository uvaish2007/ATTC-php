-- ===========================================================================
--  Dummy data for demos / testing.
--
--  Adds 5 more departments, a HoD + faculty per department, and a large spread
--  of records across all ten metrics, academic years and review statuses, plus
--  some department targets.
--
--  Safe to re-run: every dummy record is created by a "seed.*@atts.edu" user,
--  so the script deletes those users' records first and reloads them — it never
--  touches the real seed users (ids 1-5) or their data. All dummy accounts use
--  the password  faculty123 .
-- ===========================================================================
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

SET @HASH := (SELECT password FROM users WHERE email = 'faculty@atts.edu');
SET @ADMIN := (SELECT id FROM users WHERE role = 'Admin' ORDER BY id LIMIT 1);

-- ---- 1. Departments (added only if missing) -------------------------------
INSERT INTO departments (name, code)
SELECT * FROM (
  SELECT 'ECE'   AS name, 'ECE'   AS code UNION ALL
  SELECT 'MECH',  'MECH'  UNION ALL
  SELECT 'EEE',   'EEE'   UNION ALL
  SELECT 'IT',    'IT'    UNION ALL
  SELECT 'CIVIL', 'CIVIL'
) s
WHERE NOT EXISTS (SELECT 1 FROM departments d WHERE d.name = s.name);

-- ---- 2. Users: one HoD + one seed-faculty per department ------------------
INSERT INTO users (name, email, password, role, department)
SELECT * FROM (
  SELECT 'Dr. Anitha Raman'      AS name, 'hod.aids@atts.edu'  AS email, @HASH AS password, 'HoD'     AS role, 'AI & DS' AS department UNION ALL
  SELECT 'Dr. Suresh Kumar',      'hod.agri@atts.edu',  @HASH, 'HoD',     'Agri'  UNION ALL
  SELECT 'Dr. Mohan Das',         'hod.cse@atts.edu',   @HASH, 'HoD',     'CSE'   UNION ALL
  SELECT 'Dr. Priya Venkat',      'hod.ece@atts.edu',   @HASH, 'HoD',     'ECE'   UNION ALL
  SELECT 'Dr. Karthik Rajan',     'hod.mech@atts.edu',  @HASH, 'HoD',     'MECH'  UNION ALL
  SELECT 'Dr. Latha Krishnan',    'hod.eee@atts.edu',   @HASH, 'HoD',     'EEE'   UNION ALL
  SELECT 'Dr. Vignesh Balaji',    'hod.it@atts.edu',    @HASH, 'HoD',     'IT'    UNION ALL
  SELECT 'Dr. Ramesh Pillai',     'hod.civil@atts.edu', @HASH, 'HoD',     'CIVIL' UNION ALL
  SELECT 'Prof. K. Bavana',       'seed.csbs@atts.edu', @HASH, 'Faculty', 'CSBS'  UNION ALL
  SELECT 'Prof. R. Meena',        'seed.aids@atts.edu', @HASH, 'Faculty', 'AI & DS' UNION ALL
  SELECT 'Prof. S. Ganesh',       'seed.agri@atts.edu', @HASH, 'Faculty', 'Agri'  UNION ALL
  SELECT 'Prof. N. Bala',         'seed.cse@atts.edu',  @HASH, 'Faculty', 'CSE'   UNION ALL
  SELECT 'Prof. D. Kavya',        'seed.ece@atts.edu',  @HASH, 'Faculty', 'ECE'   UNION ALL
  SELECT 'Prof. M. Arjun',        'seed.mech@atts.edu', @HASH, 'Faculty', 'MECH'  UNION ALL
  SELECT 'Prof. P. Divya',        'seed.eee@atts.edu',  @HASH, 'Faculty', 'EEE'   UNION ALL
  SELECT 'Prof. T. Harish',       'seed.it@atts.edu',   @HASH, 'Faculty', 'IT'    UNION ALL
  SELECT 'Prof. V. Sneha',        'seed.civil@atts.edu',@HASH, 'Faculty', 'CIVIL'
) s
WHERE NOT EXISTS (SELECT 1 FROM users u WHERE u.email = s.email);

-- Handy ids
SET @f_csbs  := (SELECT id FROM users WHERE email='seed.csbs@atts.edu');
SET @f_aids  := (SELECT id FROM users WHERE email='seed.aids@atts.edu');
SET @f_agri  := (SELECT id FROM users WHERE email='seed.agri@atts.edu');
SET @f_cse   := (SELECT id FROM users WHERE email='seed.cse@atts.edu');
SET @f_ece   := (SELECT id FROM users WHERE email='seed.ece@atts.edu');
SET @f_mech  := (SELECT id FROM users WHERE email='seed.mech@atts.edu');
SET @f_eee   := (SELECT id FROM users WHERE email='seed.eee@atts.edu');
SET @f_it    := (SELECT id FROM users WHERE email='seed.it@atts.edu');
SET @f_civil := (SELECT id FROM users WHERE email='seed.civil@atts.edu');

-- ---- 3. Clear any previous dummy records (by the seed faculty) -------------
SET @SEEDS := (SELECT GROUP_CONCAT(id) FROM users WHERE email LIKE 'seed.%@atts.edu');
DELETE FROM journal_publications    WHERE FIND_IN_SET(created_by, @SEEDS);
DELETE FROM book_publications       WHERE FIND_IN_SET(created_by, @SEEDS);
DELETE FROM conference_publications WHERE FIND_IN_SET(created_by, @SEEDS);
DELETE FROM patents                 WHERE FIND_IN_SET(created_by, @SEEDS);
DELETE FROM fdp                     WHERE FIND_IN_SET(created_by, @SEEDS);
DELETE FROM mou                     WHERE FIND_IN_SET(created_by, @SEEDS);
DELETE FROM events                  WHERE FIND_IN_SET(created_by, @SEEDS);
DELETE FROM nptel                   WHERE FIND_IN_SET(created_by, @SEEDS);
DELETE FROM internships             WHERE FIND_IN_SET(created_by, @SEEDS);
DELETE FROM placements              WHERE FIND_IN_SET(created_by, @SEEDS);
DELETE FROM targets                 WHERE FIND_IN_SET(created_by, @SEEDS);

-- ---- 4. Journal publications ----------------------------------------------
INSERT INTO journal_publications (faculty_name, department, academic_year, author_type, paper_title, journal_name, journal_type, issn, doi, status, created_by, approved_by) VALUES
('Prof. R. Meena','AI & DS','2025-26','Author-1','Explainable AI for Crop Yield Prediction','Journal of Machine Learning Research','Scopus','1533-7928','10.1000/jmlr.2025.01','Approved',@f_aids,@ADMIN),
('Prof. D. Kavya','ECE','2025-26','Author-1','Low-Power VLSI Design for IoT Edge Nodes','IEEE Transactions on VLSI','SCI','1063-8210','10.1109/tvlsi.2025.22','Approved',@f_ece,@ADMIN),
('Prof. M. Arjun','MECH','2024-25','Co-Author','Thermal Analysis of Additive-Manufactured Alloys','Materials Today','Scopus','2214-7853','10.1016/j.matpr.2024.09','Approved',@f_mech,@ADMIN),
('Prof. T. Harish','IT','2025-26','Author-1','Federated Learning for Privacy-Preserving Health Data','Springer Cluster Computing','Springer','1386-7857','10.1007/s10586-025-03','Submitted',@f_it,NULL),
('Prof. P. Divya','EEE','2024-25','Author-1','Grid-Tied Solar Inverter with MPPT Optimisation','IET Power Electronics','SCI','1755-4535','10.1049/iet-pel.2024.7','Approved',@f_eee,@ADMIN),
('Prof. V. Sneha','CIVIL','2023-24','Author-1','Self-Healing Concrete with Bacterial Agents','Construction and Building Materials','Scopus','0950-0618','10.1016/j.conbuild.2023.3','Approved',@f_civil,@ADMIN),
('Prof. K. Bavana','CSBS','2025-26','Author-1','Blockchain-Based Academic Credential Verification','UGC Care Journal of Computing','UGC Care','2456-1234','','Submitted',@f_csbs,NULL),
('Prof. S. Ganesh','Agri','2024-25','Co-Author','Precision Irrigation using Wireless Sensor Networks','Computers and Electronics in Agriculture','SCI','0168-1699','10.1016/j.compag.2024.5','Approved',@f_agri,@ADMIN),
('Prof. N. Bala','CSE','2025-26','Author-1','Transformer Models for Code Summarisation','ACM Computing Surveys','Scopus','0360-0300','10.1145/3579.2025','Approved',@f_cse,@ADMIN),
('Prof. R. Meena','AI & DS','2024-25','Author-1','GAN-Based Data Augmentation for Rare Diseases','Elsevier Pattern Recognition','SCI','0031-3203','10.1016/j.patcog.2024.8','Rejected',@f_aids,@ADMIN),
('Prof. D. Kavya','ECE','2023-24','Co-Author','5G Antenna Array for mmWave Applications','IEEE Antennas & Wireless','SCI','1536-1225','10.1109/lawp.2023.9','Approved',@f_ece,@ADMIN),
('Prof. T. Harish','IT','2024-25','Author-1','Serverless Architecture Cost Optimisation','Journal of Cloud Computing','Springer','2192-113X','10.1186/s13677-024-1','Approved',@f_it,@ADMIN),
('Prof. M. Arjun','MECH','2025-26','Author-1','Fatigue Life Prediction of Welded Joints','Engineering Failure Analysis','Scopus','1350-6307','','Draft',@f_mech,NULL),
('Prof. P. Divya','EEE','2025-26','Author-1','Fault Detection in Distribution Networks using ML','Electric Power Systems Research','SCI','0378-7796','10.1016/j.epsr.2025.2','Submitted',@f_eee,NULL),
('Prof. V. Sneha','CIVIL','2024-25','Co-Author','Seismic Retrofitting of RC Frames','Journal of Structural Engineering','SCI','0733-9445','10.1061/jsendh.2024.4','Approved',@f_civil,@ADMIN);

-- ---- 5. Book / chapter publications ---------------------------------------
INSERT INTO book_publications (faculty_name, department, academic_year, publication_category, title, publisher_name, isbn, status, created_by, approved_by) VALUES
('Prof. N. Bala','CSE','2024-25','Book','Foundations of Deep Learning','Springer Nature','978-3-030-1111','Approved',@f_cse,@ADMIN),
('Prof. D. Kavya','ECE','2025-26','Book Chapter','Embedded Systems for Smart Cities','Elsevier','978-0-12-822222','Approved',@f_ece,@ADMIN),
('Prof. R. Meena','AI & DS','2025-26','Book Chapter','Ethics in Artificial Intelligence','CRC Press','978-1-032-3333','Submitted',@f_aids,NULL),
('Prof. M. Arjun','MECH','2023-24','Book','Modern Manufacturing Processes','McGraw Hill','978-93-5316-44','Approved',@f_mech,@ADMIN),
('Prof. V. Sneha','CIVIL','2024-25','Book Chapter','Sustainable Construction Materials','Woodhead','978-0-08-102444','Approved',@f_civil,@ADMIN),
('Prof. T. Harish','IT','2025-26','Book','Cloud Native Applications','Packt','978-1-80020-555','Draft',@f_it,NULL),
('Prof. K. Bavana','CSBS','2024-25','Book Chapter','Business Analytics with Python','Wiley','978-1-119-6666','Approved',@f_csbs,@ADMIN),
('Prof. P. Divya','EEE','2023-24','Book','Power Electronics Handbook','Pearson','978-93-325-77','Approved',@f_eee,@ADMIN),
('Prof. S. Ganesh','Agri','2025-26','Book Chapter','Smart Farming Technologies','Springer','978-3-030-8888','Submitted',@f_agri,NULL),
('Prof. N. Bala','CSE','2023-24','Book Chapter','Algorithms for Big Data','Cambridge','978-1-108-9999','Approved',@f_cse,@ADMIN);

-- ---- 6. Conference publications -------------------------------------------
INSERT INTO conference_publications (faculty_name, department, academic_year, author_type, paper_title, conference_name, conference_type, venue, conference_date, status, created_by, approved_by) VALUES
('Prof. R. Meena','AI & DS','2025-26','Author-1','Real-Time Object Detection on Edge Devices','IEEE ICCV','International','Chennai','2025-11-14','Approved',@f_aids,@ADMIN),
('Prof. D. Kavya','ECE','2024-25','Author-1','Reconfigurable Antennas for Cognitive Radio','IEEE INDICON','National','Delhi','2024-12-05','Approved',@f_ece,@ADMIN),
('Prof. T. Harish','IT','2025-26','Co-Author','Microservice Observability at Scale','ACM SoCC','International','Bengaluru','2025-10-20','Submitted',@f_it,NULL),
('Prof. M. Arjun','MECH','2024-25','Author-1','Topology Optimisation for Lightweight Brackets','ASME IMECE','International','Kochi','2024-11-02','Approved',@f_mech,@ADMIN),
('Prof. P. Divya','EEE','2023-24','Author-1','Battery Management for EV Fleets','IEEE PES GTD','International','Hyderabad','2023-09-18','Approved',@f_eee,@ADMIN),
('Prof. V. Sneha','CIVIL','2025-26','Co-Author','BIM Adoption in Indian Construction','ICSECM','National','Madurai','2025-08-30','Rejected',@f_civil,@ADMIN),
('Prof. K. Bavana','CSBS','2024-25','Author-1','Sentiment Analysis of Product Reviews','Springer ICACDS','International','Kolkata','2024-04-25','Approved',@f_csbs,@ADMIN),
('Prof. S. Ganesh','Agri','2025-26','Author-1','Drone-Based Pest Detection','IEEE AGRETECH','National','Coimbatore','2025-07-11','Submitted',@f_agri,NULL),
('Prof. N. Bala','CSE','2024-25','Author-1','Graph Neural Networks for Fraud Detection','ACM CIKM','International','Mumbai','2024-10-21','Approved',@f_cse,@ADMIN),
('Prof. D. Kavya','ECE','2023-24','Co-Author','Energy Harvesting for Wearables','IEEE SENSORS','International','Chennai','2023-10-29','Approved',@f_ece,@ADMIN);

-- ---- 7. Patents & copyrights ----------------------------------------------
INSERT INTO patents (faculty_name, department, academic_year, category, title, patent_number, publication_date, status, created_by, approved_by) VALUES
('Prof. M. Arjun','MECH','2024-25','Patent','Compact Waste Heat Recovery Unit','IN202441012345','2024-08-16','Approved',@f_mech,@ADMIN),
('Prof. D. Kavya','ECE','2025-26','Patent','Wideband Antenna for 5G Devices','IN202541023456','2025-06-20','Submitted',@f_ece,NULL),
('Prof. N. Bala','CSE','2024-25','Copyright','Adaptive E-Learning Platform (Software)','L-134567/2024','2024-03-10','Approved',@f_cse,@ADMIN),
('Prof. P. Divya','EEE','2023-24','Patent','Smart Energy Meter with Theft Detection','IN202341034567','2023-11-25','Approved',@f_eee,@ADMIN),
('Prof. S. Ganesh','Agri','2025-26','Patent','Automated Seed Sorting Mechanism','IN202541045678','2025-05-05','Approved',@f_agri,@ADMIN),
('Prof. V. Sneha','CIVIL','2024-25','Copyright','Structural Health Monitoring Dashboard','L-145678/2024','2024-09-14','Submitted',@f_civil,NULL),
('Prof. R. Meena','AI & DS','2025-26','Patent','Explainable Diagnosis Assist System','IN202541056789','2025-07-02','Approved',@f_aids,@ADMIN),
('Prof. T. Harish','IT','2024-25','Copyright','Container Orchestration Toolkit','L-156789/2024','2024-06-30','Approved',@f_it,@ADMIN),
('Prof. K. Bavana','CSBS','2023-24','Patent','Blockchain Voting Protocol','IN202341067890','2023-12-01','Rejected',@f_csbs,@ADMIN),
('Prof. D. Kavya','ECE','2024-25','Copyright','IoT Firmware Update Framework','L-167890/2024','2024-02-18','Approved',@f_ece,@ADMIN);

-- ---- 8. FDP / workshops (faculty development) -----------------------------
INSERT INTO fdp (faculty_name, department, from_date, to_date, event_type, title, mode, organized_by, status, created_by, approved_by) VALUES
('Prof. K. Bavana','CSBS','2025-07-01','2025-07-05','FDP','Outcome-Based Education & NBA','Online','IUCEE','Approved',@f_csbs,@ADMIN),
('Prof. R. Meena','AI & DS','2025-06-16','2025-06-21','Workshop','Generative AI with LLMs','Hybrid','NVIDIA DLI','Approved',@f_aids,@ADMIN),
('Prof. D. Kavya','ECE','2024-12-02','2024-12-06','FDP','FPGA-Based System Design','Offline','Xilinx','Approved',@f_ece,@ADMIN),
('Prof. M. Arjun','MECH','2025-01-13','2025-01-17','Workshop','CFD using ANSYS Fluent','Offline','ANSYS','Submitted',@f_mech,NULL),
('Prof. P. Divya','EEE','2024-08-19','2024-08-23','FDP','Electric Vehicle Technologies','Online','ISTE','Approved',@f_eee,@ADMIN),
('Prof. V. Sneha','CIVIL','2025-02-10','2025-02-14','Seminar','Green Building Certification (LEED)','Hybrid','IGBC','Approved',@f_civil,@ADMIN),
('Prof. T. Harish','IT','2025-03-03','2025-03-07','FDP','DevOps & CI/CD Pipelines','Online','AWS Academy','Approved',@f_it,@ADMIN),
('Prof. S. Ganesh','Agri','2024-11-11','2024-11-15','Workshop','Precision Agriculture with GIS','Offline','ICAR','Approved',@f_agri,@ADMIN),
('Prof. N. Bala','CSE','2025-05-19','2025-05-24','FDP','Research Methodology & Publication Ethics','Online','Anna University','Approved',@f_cse,@ADMIN),
('Prof. D. Kavya','ECE','2023-09-04','2023-09-08','Workshop','PCB Design with Altium','Offline','IEEE SB','Approved',@f_ece,@ADMIN),
('Prof. R. Meena','AI & DS','2024-07-22','2024-07-26','FDP','Data Science with Python','Hybrid','SkillsDA','Rejected',@f_aids,@ADMIN),
('Prof. M. Arjun','MECH','2025-06-09','2025-06-13','Seminar','Industry 4.0 & Smart Manufacturing','Online','CII','Draft',@f_mech,NULL);

-- ---- 9. MoUs ---------------------------------------------------------------
INSERT INTO mou (department, signed_date, organization, valid_upto, purpose, status, created_by, approved_by) VALUES
('CSE','2024-08-01','Infosys Ltd','2027-08-01','Internships and industry-aligned curriculum','Approved',@f_cse,@ADMIN),
('AI & DS','2025-01-15','Google Developer Groups','2028-01-15','AI/ML workshops and hackathons','Approved',@f_aids,@ADMIN),
('ECE','2024-11-20','Texas Instruments','2027-11-20','Embedded systems lab and training','Approved',@f_ece,@ADMIN),
('MECH','2025-03-10','Ashok Leyland','2028-03-10','Automotive research and internships','Submitted',@f_mech,NULL),
('EEE','2024-06-05','Schneider Electric','2027-06-05','Smart grid centre of excellence','Approved',@f_eee,@ADMIN),
('CIVIL','2023-12-12','L&T Construction','2026-12-12','Site training and guest lectures','Approved',@f_civil,@ADMIN),
('IT','2025-02-28','Amazon Web Services','2028-02-28','Cloud academy and certifications','Approved',@f_it,@ADMIN),
('Agri','2024-09-30','ICAR-IIHR','2027-09-30','Precision farming research','Submitted',@f_agri,NULL);

-- ---- 10. Events ------------------------------------------------------------
INSERT INTO events (department, event_date, event_title, event_type, mode, resource_person, designation, participants, status, created_by, approved_by) VALUES
('CSBS','2025-08-11','Entrepreneurship as a Career','Guest Lecture','Offline','Mr. Arun Prakash','Founder, TechStart','120','Approved',@f_csbs,@ADMIN),
('AI & DS','2025-09-05','National Hackathon: AI for Good','Hackathon','Hybrid','Panel of Experts','Industry Mentors','200','Approved',@f_aids,@ADMIN),
('ECE','2024-10-18','Workshop on Antenna Design','Workshop','Offline','Dr. S. Raghavan','Professor, IIT-M','85','Approved',@f_ece,@ADMIN),
('MECH','2025-02-22','Seminar on Additive Manufacturing','Seminar','Online','Dr. Kumar','Scientist, DRDO','150','Submitted',@f_mech,NULL),
('EEE','2024-07-30','Renewable Energy Awareness Drive','Others','Offline','Ms. Latha','TNEB Engineer','90','Approved',@f_eee,@ADMIN),
('CIVIL','2025-03-15','Guest Lecture on Smart Cities','Guest Lecture','Hybrid','Ar. Vijay','Urban Planner','70','Approved',@f_civil,@ADMIN),
('IT','2025-01-27','Cloud Computing Bootcamp','Workshop','Online','AWS Trainer','Solutions Architect','160','Approved',@f_it,@ADMIN),
('Agri','2024-12-09','Field Day: Organic Farming','Others','Offline','Dr. Senthil','Agri Scientist','110','Approved',@f_agri,@ADMIN),
('CSE','2025-04-03','Coding Marathon 24hrs','Hackathon','Offline','Student Council','Organisers','180','Approved',@f_cse,@ADMIN),
('ECE','2023-11-08','IoT Project Expo','Others','Offline','Faculty Panel','Judges','95','Rejected',@f_ece,@ADMIN);

-- ---- 11. NPTEL / online certifications ------------------------------------
INSERT INTO nptel (candidate_name, department, category, course_title, session, grade, status, created_by, approved_by) VALUES
('Prof. K. Bavana','CSBS','NPTEL','Data Structures and Algorithms','Jan-Apr 2025','Elite+Gold','Approved',@f_csbs,@ADMIN),
('Prof. R. Meena','AI & DS','NPTEL','Deep Learning','Jul-Oct 2024','Elite+Silver','Approved',@f_aids,@ADMIN),
('Prof. D. Kavya','ECE','NPTEL','Digital Signal Processing','Jan-Apr 2025','Elite','Approved',@f_ece,@ADMIN),
('Prof. M. Arjun','MECH','Coursera','Finite Element Analysis','2024','Completed','Approved',@f_mech,@ADMIN),
('Prof. P. Divya','EEE','NPTEL','Power System Analysis','Jul-Oct 2024','Elite+Gold','Submitted',@f_eee,NULL),
('Prof. V. Sneha','CIVIL','NPTEL','Structural Dynamics','Jan-Apr 2025','Elite','Approved',@f_civil,@ADMIN),
('Prof. T. Harish','IT','Coursera','Google Cloud Architecture','2025','Completed','Approved',@f_it,@ADMIN),
('Prof. S. Ganesh','Agri','NPTEL','Soil Science','Jul-Oct 2024','Elite+Silver','Approved',@f_agri,@ADMIN),
('Prof. N. Bala','CSE','NPTEL','Machine Learning','Jan-Apr 2025','Elite+Gold','Approved',@f_cse,@ADMIN),
('Prof. D. Kavya','ECE','Others','edX VLSI Design','2024','Completed','Approved',@f_ece,@ADMIN),
('Prof. R. Meena','AI & DS','NPTEL','Reinforcement Learning','Jul-Oct 2024','Elite','Rejected',@f_aids,@ADMIN),
('Prof. K. Bavana','CSBS','NPTEL','Database Management Systems','Jan-Apr 2025','Elite+Silver','Approved',@f_csbs,@ADMIN);

-- ---- 12. Internships (student records; no department column) --------------
INSERT INTO internships (reg_no, student_name, title, industry, duration, days, status, created_by, approved_by) VALUES
('21CS001','Arun Kumar','Full-Stack Web Development Intern','Zoho Corp','8 weeks','40','Approved',@f_cse,@ADMIN),
('21AI014','Deepa Shri','Machine Learning Intern','Freshworks','6 weeks','30','Approved',@f_aids,@ADMIN),
('21EC022','Vishal R','VLSI Verification Intern','Intel India','12 weeks','60','Approved',@f_ece,@ADMIN),
('21ME010','Sanjay P','CAD Design Intern','TVS Motors','4 weeks','20','Approved',@f_mech,@ADMIN),
('21EE005','Priyanka M','Power Systems Intern','TNEB','6 weeks','30','Submitted',@f_eee,NULL),
('21CE018','Hari Prasad','Site Engineering Intern','L&T','8 weeks','40','Approved',@f_civil,@ADMIN),
('21IT031','Nithya S','Cloud Engineering Intern','AWS','10 weeks','50','Approved',@f_it,@ADMIN),
('21AG007','Karthik V','AgriTech Data Intern','Ninjacart','5 weeks','25','Approved',@f_agri,@ADMIN),
('21CB012','Sneha Raj','Business Analyst Intern','Deloitte','8 weeks','40','Approved',@f_csbs,@ADMIN),
('21CS045','Manoj Kumar','Backend Developer Intern','PayPal','6 weeks','30','Rejected',@f_cse,@ADMIN),
('21EC050','Gokul Nath','Embedded Firmware Intern','Bosch','12 weeks','60','Approved',@f_ece,@ADMIN),
('21AI033','Fathima N','Data Science Intern','Mu Sigma','8 weeks','40','Approved',@f_aids,@ADMIN),
('21ME028','Ragul S','Thermal Simulation Intern','Ashok Leyland','4 weeks','20','Draft',@f_mech,NULL),
('21IT009','Divya Bharathi','DevOps Intern','Zoho','6 weeks','30','Approved',@f_it,@ADMIN);

-- ---- 13. Placements (student records; no department column) ----------------
INSERT INTO placements (reg_no, student_name, job_title, mode, company, pay_scale, status, created_by, approved_by) VALUES
('21CS002','Aravind Balaji','Software Engineer','On-Campus','Amazon','18 LPA','Approved',@f_cse,@ADMIN),
('21AI001','Meghana Rao','Data Scientist','On-Campus','Microsoft','16 LPA','Approved',@f_aids,@ADMIN),
('21EC003','Surya Prakash','ASIC Design Engineer','On-Campus','Qualcomm','14 LPA','Approved',@f_ece,@ADMIN),
('21IT005','Lakshmi Priya','Cloud Engineer','On-Campus','AWS','12 LPA','Approved',@f_it,@ADMIN),
('21ME009','Vimal Raj','Design Engineer','On-Campus','TVS','7 LPA','Approved',@f_mech,@ADMIN),
('21EE011','Bhuvana S','Graduate Engineer Trainee','Off-Campus','Schneider','8 LPA','Approved',@f_eee,@ADMIN),
('21CE015','Naveen Kumar','Structural Engineer','On-Campus','L&T','9 LPA','Submitted',@f_civil,NULL),
('21CB020','Ishwarya M','Business Analyst','On-Campus','Deloitte','11 LPA','Approved',@f_csbs,@ADMIN),
('21AG004','Prakash R','AgriTech Associate','Off-Campus','Ninjacart','6 LPA','Approved',@f_agri,@ADMIN),
('21CS088','Sri Hari','SDE-1','On-Campus','Flipkart','20 LPA','Approved',@f_cse,@ADMIN),
('21AI045','Nandhini K','ML Engineer','On-Campus','Freshworks','13 LPA','Approved',@f_aids,@ADMIN),
('21EC066','Ajay Kumar','Embedded Engineer','On-Campus','Bosch','10 LPA','Rejected',@f_ece,@ADMIN),
('21IT070','Keerthana S','Full-Stack Developer','On-Campus','Zoho','9 LPA','Approved',@f_it,@ADMIN),
('21ME055','Dinesh Babu','Graduate Apprentice','Off-Campus','Ashok Leyland','6.5 LPA','Approved',@f_mech,@ADMIN);

-- ---- 14. Some department targets (Approved / frozen) ----------------------
INSERT INTO targets (department, academic_year, metric, target_value, achieved_value, coordinator, status, created_by, approved_by, approved_at) VALUES
('ECE','2025-26','Journal Publications',10,3,'Dr. Priya Venkat','Approved',@f_ece,@ADMIN,NOW()),
('ECE','2025-26','Patents & Copyrights',5,2,'Dr. Priya Venkat','Approved',@f_ece,@ADMIN,NOW()),
('ECE','2025-26','FDP Participation',20,12,'Dr. Priya Venkat','Approved',@f_ece,@ADMIN,NOW()),
('MECH','2025-26','Journal Publications',8,2,'Dr. Karthik Rajan','Approved',@f_mech,@ADMIN,NOW()),
('MECH','2025-26','Patents & Copyrights',4,1,'Dr. Karthik Rajan','Approved',@f_mech,@ADMIN,NOW()),
('EEE','2025-26','Journal Publications',9,2,'Dr. Latha Krishnan','Approved',@f_eee,@ADMIN,NOW()),
('EEE','2025-26','MoU Signed',3,1,'Dr. Latha Krishnan','Approved',@f_eee,@ADMIN,NOW()),
('IT','2025-26','Placements',80,45,'Dr. Vignesh Balaji','Approved',@f_it,@ADMIN,NOW()),
('IT','2025-26','FDP Participation',15,8,'Dr. Vignesh Balaji','Approved',@f_it,@ADMIN,NOW()),
('CIVIL','2025-26','Journal Publications',6,2,'Dr. Ramesh Pillai','Approved',@f_civil,@ADMIN,NOW()),
('AI & DS','2025-26','Journal Publications',12,4,'Dr. Anitha Raman','Approved',@f_aids,@ADMIN,NOW()),
('Agri','2025-26','Patents & Copyrights',3,1,'Dr. Suresh Kumar','Approved',@f_agri,@ADMIN,NOW());
