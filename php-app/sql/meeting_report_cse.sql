-- ---------------------------------------------------------------------------
--  Executive Meeting Report — proforma columns + CSE sample data
--
--  The uploaded CSE report is richer than a plain numeric target:
--    * Fixed and Achieved hold TEXT ("UGC – 18", "10 Lakhs", "85 (without
--      Stipend)", "-"), not just a number.
--    * Achieved is split into two periods (From … / During …).
--    * Rows are numbered with lettered sub-items (a. b. c.).
--
--  So this adds the columns that carry that, then loads the CSE proforma so the
--  report can be seen in the exact uploaded format. The numeric target_value /
--  achieved_value are still filled (parsed from the text) for anything that
--  reads them; the report itself uses the *_text columns.
--
--  Safe to re-run: columns are added only if missing, and the CSE rows are
--  cleared before reloading, so running it twice does not duplicate anything.
-- ---------------------------------------------------------------------------

-- Match the tables' collation so comparing our text literals against the
-- utf8mb4_unicode_ci columns does not raise an "illegal mix of collations".
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- ---- 1. Columns (added only when absent) ----------------------------------
SET @ddl := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='targets' AND COLUMN_NAME='sort_order')>0,
  'DO 0', 'ALTER TABLE targets ADD COLUMN sort_order INT NULL AFTER id');
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

SET @ddl := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='targets' AND COLUMN_NAME='serial_no')>0,
  'DO 0', 'ALTER TABLE targets ADD COLUMN serial_no VARCHAR(8) NULL AFTER sort_order');
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

SET @ddl := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='targets' AND COLUMN_NAME='sub_label')>0,
  'DO 0', 'ALTER TABLE targets ADD COLUMN sub_label VARCHAR(8) NULL AFTER serial_no');
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

SET @ddl := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='targets' AND COLUMN_NAME='fixed_text')>0,
  'DO 0', 'ALTER TABLE targets ADD COLUMN fixed_text VARCHAR(120) NULL AFTER target_value');
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

SET @ddl := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='targets' AND COLUMN_NAME='achieved_p1')>0,
  'DO 0', 'ALTER TABLE targets ADD COLUMN achieved_p1 VARCHAR(200) NULL AFTER achieved_value');
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

SET @ddl := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='targets' AND COLUMN_NAME='achieved_p2')>0,
  'DO 0', 'ALTER TABLE targets ADD COLUMN achieved_p2 VARCHAR(200) NULL AFTER achieved_p1');
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

-- ---- 2. Department + author ------------------------------------------------
INSERT INTO departments (name, code) SELECT 'CSE', 'CSE' WHERE NOT EXISTS (SELECT 1 FROM departments WHERE name='CSE');
SET @admin := (SELECT id FROM users WHERE role='Admin' ORDER BY id LIMIT 1);
SET @yr := '2025-26';

-- ---- 3. Reload the CSE proforma -------------------------------------------
DELETE FROM targets WHERE department='CSE' AND academic_year=@yr;

INSERT INTO targets
  (department, academic_year, sort_order, serial_no, sub_label, metric,
   target_value, fixed_text, achieved_value, achieved_p1, achieved_p2,
   remarks, coordinator, status, created_by, approved_by, approved_at)
VALUES
('CSE',@yr, 1,'1','','PASS PERCENTAGE',86,'86 %',0,'-','-','End Sem Exam Completed','Prof. N. Balasubramanian','Approved',@admin,@admin,NOW()),
('CSE',@yr, 2,'2','','TO IMPROVE II, III & IV YEAR STUDENTS CGPA',50,'50',0,'-','-','End Sem Exam Completed','Prof. N. Balasubramanian','Approved',@admin,@admin,NOW()),
('CSE',@yr, 3,'3','','2022-26 BATCH STUDENTS PLACEMENT',60,'60',31,'31','','Infosys Training given to 25 students.','Ms.R.Bavana Mercy','Approved',@admin,@admin,NOW()),
('CSE',@yr, 4,'4','','NUMBER OF QUALITY PUBLICATIONS IN SCOPUS/ SCI JOURNALS / SPRINGER / UGC CARE / H-INDEX',18,'UGC – 18',4,'04','','Motivating the faculty to convert students projects into quality publications','Prof. N. Balasubramanian','Approved',@admin,@admin,NOW()),
('CSE',@yr, 5,'5','a.','BOOKS PUBLICATION',1,'1',0,'-','-','Necessary steps taken for book publication','Prof. N. Balasubramanian','Approved',@admin,@admin,NOW()),
('CSE',@yr, 6,'','b.','BOOK CHAPTER',1,'1',0,'-','-','Waiting for publication','Prof. N. Balasubramanian','Approved',@admin,@admin,NOW()),
('CSE',@yr, 7,'6','a.','PATENT PUBLISHED',1,'1',1,'01','01 — Mrs. Bavana Mercy published a patent','-','Prof. N. Balasubramanian','Approved',@admin,@admin,NOW()),
('CSE',@yr, 8,'','b.','PATENT GRANTED',1,'1',0,'-','-','','Prof. N. Balasubramanian','Approved',@admin,@admin,NOW()),
('CSE',@yr, 9,'','c.','COPY RIGHTS',1,'1',0,'-','-','','Prof. N. Balasubramanian','Approved',@admin,@admin,NOW()),
('CSE',@yr,10,'7','a.','SPONSORED RESEARCH',10,'10 Lakhs',0,'-','-','Motivating faculty members to submit proposal for sponsored research','Prof. N. Balasubramanian','Approved',@admin,@admin,NOW()),
('CSE',@yr,11,'','b.','FUNDS CONSULTANCY PROJECTS',2,'2 Lakhs',0,'-','-','-','Prof. N. Balasubramanian','Approved',@admin,@admin,NOW()),
('CSE',@yr,12,'8','','RESEARCH CENTRE RECOGNITION FROM ANNA UNIVERSITY',0,'-',0,'-','-','-','Prof. N. Balasubramanian','Approved',@admin,@admin,NOW()),
('CSE',@yr,13,'9','a.','PROGRAMME ON INTELLECTUAL PROPERTY RIGHTS',1,'1',0,'-','-','Will conduct programme during February''26','Ms.M.Kayathri Devi','Approved',@admin,@admin,NOW()),
('CSE',@yr,14,'','b.','PROGRAMME ON HIGHER STUDIES',1,'1',0,'-','-','Will conduct programme during January''26','','Approved',@admin,@admin,NOW()),
('CSE',@yr,15,'','c.','PROGRAMME ON ENTREPRENEURSHIP',1,'1',1,'01','01 — Organized a program on Entrepreneurship as a Career on 11.02.26','Target Achieved','','Approved',@admin,@admin,NOW()),
('CSE',@yr,16,'10','','NO. OF FACULTY MEMBERS - COMPLETED ONLINE CERTIFICATE COURSE',0,'',0,'','','','','Approved',@admin,@admin,NOW()),
('CSE',@yr,17,'','a.','NPTEL',9,'9',1,'01','-','Faculty registered for NPTEL courses','Ms.M.Kayathri Devi','Approved',@admin,@admin,NOW()),
('CSE',@yr,18,'','b.','Others',0,'-',1,'01','-','','','Approved',@admin,@admin,NOW()),
('CSE',@yr,19,'11','','INDUSTRY INTERACTION / MOU / INDUSTRY SUPPORTED LAB',1,'1',0,'-','-','We are approaching industries to sign MoU','Prof. N. Balasubramanian','Approved',@admin,@admin,NOW()),
('CSE',@yr,20,'12','','NO OF STUDENTS COMPLETED INDUSTRY INTERNSHIP (4 weeks & above)',85,'85',85,'85 (without Stipend)','-','Target Achieved. Will try for Internship with stipend.','Ms.R.Bavana Mercy','Approved',@admin,@admin,NOW()),
('CSE',@yr,21,'13','','NO OF STUDENTS COMPLETED SUMMER TRAINING (less than 4 weeks)',20,'20',10,'10','-','We are motivating our II and III year students','Ms.S.Megaladevi','Approved',@admin,@admin,NOW()),
('CSE',@yr,22,'14','','STUDENTS PROJECT WITH QUALITY AND PUBLISH THE PROJECTS in Conference, Journal, Hackathon & YouTube',10,'10',0,'-','-','03 Students Ideas were shortlisted for next round in TN Skills Competition','Ms.S.Ummul Hyrul Fathima','Approved',@admin,@admin,NOW()),
('CSE',@yr,23,'15','','FACULTY PARTICIPATIONS IN FDP / TRAINING ACTIVITIES / STTP / CONFERENCE',27,'27',38,'38','01 — Mrs. S. Ummul Hyrul Fathima participated in FDP','Target Achieved. Faculty are actively participating in various FDPs','Ms.M.Kayathri Devi','Approved',@admin,@admin,NOW()),
('CSE',@yr,24,'16','','NO. OF MEMBERSHIP IN PROFESSIONAL SOCIETIES (Faculties & Students)',50,'50',0,'-','-','Will try to get CSI membership','Ms.M.Kayathri Devi','Approved',@admin,@admin,NOW()),
('CSE',@yr,25,'17','','NEWSLETTER',2,'2',0,'-','-','Will prepare newsletter at the end of every semester','Mr.K.Seenipulavar Pitchai','Approved',@admin,@admin,NOW()),
('CSE',@yr,26,'18','','NO. OF ONLINE CERTIFICATIONS COMPLETED BY STUDENTS',50,'50',52,'52','01 — Mr. Said Fazil of II-B.E CSE completed online courses','Motivating our students to complete online certifications','Ms.U. Vishnupriya','Approved',@admin,@admin,NOW()),
('CSE',@yr,27,'19','','NO. OF STUDENTS COMPLETED IIT-BOMBAY SPOKEN TUTORIAL COURSES',100,'100',140,'140','-','Target Achieved.','Ms.R.Bavana Mercy','Approved',@admin,@admin,NOW()),
('CSE',@yr,28,'20','a.','PARTICIPATION IN INTER-INSTITUTE EVENTS BY STUDENTS — WITHIN STATE',10,'10',9,'09','-','Always motivating our students in participating in events','Ms.S.Ummul Hyrul Fathima','Approved',@admin,@admin,NOW()),
('CSE',@yr,29,'','b.','OUTSIDE STATE',2,'2',0,'-','-','','','Approved',@admin,@admin,NOW()),
('CSE',@yr,30,'','c.','AWARDS / PRIZES',5,'5',7,'07','-','','','Approved',@admin,@admin,NOW()),
('CSE',@yr,31,'21','','NO OF VALUE ADDED COURSE / HANDS ON TRAINING COURSES',3,'3',3,'03','-','Target Achieved','Ms.M.Kayathri Devi','Approved',@admin,@admin,NOW()),
('CSE',@yr,32,'22','','EVENTS PARTICIPATION IN SPORTS',0,'',0,'','','','','Approved',@admin,@admin,NOW()),
('CSE',@yr,33,'','a.','STATE LEVEL',10,'10',22,'22','01','Target Achieved','Ms.S.Ummul Hyrul Fathima','Approved',@admin,@admin,NOW()),
('CSE',@yr,34,'','b.','NATIONAL LEVEL',1,'1',0,'-','-','','','Approved',@admin,@admin,NOW()),
('CSE',@yr,35,'','c.','AWARDS / MEDALS',5,'5',19,'19','01 — Ms. Pavithra of III-CSE won GOLD medal in Silambam','Target Achieved','','Approved',@admin,@admin,NOW()),
('CSE',@yr,36,'23','','INNOVATION EVENTS TO BE CONDUCTED',2,'2',0,'-','-','Will conduct one event in each semester','Ms.M.Kayathri Devi','Approved',@admin,@admin,NOW()),
('CSE',@yr,37,'24','','IIC ACTIVITIES',2,'2',2,'02','-','Target Achieved','Ms.S.Megaladevi','Approved',@admin,@admin,NOW()),
('CSE',@yr,38,'25','','WEBSITE UPDATION',0,'-',0,'-','-','Periodically updating','Mr.K.Seenipulavar Pitchai','Approved',@admin,@admin,NOW()),
('CSE',@yr,39,'26','','STARTUP',1,'1',0,'-','-','Motivating our students to convert their ideas into startup','Prof. N. Balasubramanian','Approved',@admin,@admin,NOW()),
('CSE',@yr,40,'27','','ALUMNI CHAPTER',0,'-',0,'-','-','-','Prof. N. Balasubramanian','Approved',@admin,@admin,NOW()),
('CSE',@yr,41,'28','','AWARDS',1,'1',0,'-','-','-','Prof. N. Balasubramanian','Approved',@admin,@admin,NOW()),
('CSE',@yr,42,'29','','RECOGNITION for Faculty',0,'',0,'','','','','Approved',@admin,@admin,NOW()),
('CSE',@yr,43,'','a.','BOS / DC MEMBERS',10,'10',0,'-','-','-','Prof. N. Balasubramanian','Approved',@admin,@admin,NOW()),
('CSE',@yr,44,'','b.','QP / Key SETTER',0,'',3,'03','-','-','','Approved',@admin,@admin,NOW()),
('CSE',@yr,45,'','c.','Reviewer for Journal',0,'',1,'01','-','-','','Approved',@admin,@admin,NOW()),
('CSE',@yr,46,'','d.','Resource Person',0,'',0,'-','-','-','','Approved',@admin,@admin,NOW()),
('CSE',@yr,47,'','e.','Others',0,'',1,'01','-','-','','Approved',@admin,@admin,NOW()),
('CSE',@yr,48,'30','','NO. OF ACTIVITIES CONDUCTED BY NSS / YRC',0,'-',0,'-','-','-','-','Approved',@admin,@admin,NOW());
