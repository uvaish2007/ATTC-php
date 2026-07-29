-- ===========================================================================
--  The official IQAC report template — the 30-item Executive Meeting proforma.
--
--  This loads the proforma as the report TEMPLATE: the S.No and Target/Details
--  are the structure (label columns) an Admin owns; Fixed, Achieved (two
--  periods), Progress/Remarks and Coordinator are DATA columns that fill in
--  from each department's own targets when a report is generated.
--
--  Only an Admin can change this template (report-template.php is Admin-only).
--  Safe to re-run: it replaces the template rows each time.
-- ===========================================================================
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Column order to match the proforma: Progress/Remarks before Coordinator.
UPDATE report_columns SET sort_order = 6 WHERE col_key = 'remarks';
UPDATE report_columns SET sort_order = 7 WHERE col_key = 'coordinator';

-- Reload the 30 numbered items (with a./b./c. sub-items) as the template rows.
DELETE FROM report_rows;
INSERT INTO report_rows (sort_order, cells) VALUES
( 1, JSON_OBJECT('sno','1','target','PASS PERCENTAGE')),
( 2, JSON_OBJECT('sno','2','target','TO IMPROVE II, III & IV YEAR STUDENTS CGPA')),
( 3, JSON_OBJECT('sno','3','target','2022-26 BATCH STUDENTS PLACEMENT')),
( 4, JSON_OBJECT('sno','4','target','NUMBER OF QUALITY PUBLICATIONS IN SCOPUS / SCI JOURNALS / SPRINGER / UGC CARE / H-INDEX')),
( 5, JSON_OBJECT('sno','5','target','a. BOOKS PUBLICATION')),
( 6, JSON_OBJECT('sno','','target','b. BOOK CHAPTER')),
( 7, JSON_OBJECT('sno','6','target','a. PATENT PUBLISHED')),
( 8, JSON_OBJECT('sno','','target','b. PATENT GRANTED')),
( 9, JSON_OBJECT('sno','','target','c. COPY RIGHTS')),
(10, JSON_OBJECT('sno','7','target','a. SPONSORED RESEARCH')),
(11, JSON_OBJECT('sno','','target','b. FUNDS / CONSULTANCY PROJECTS')),
(12, JSON_OBJECT('sno','8','target','RESEARCH CENTRE RECOGNITION FROM ANNA UNIVERSITY')),
(13, JSON_OBJECT('sno','9','target','a. PROGRAMME ON INTELLECTUAL PROPERTY RIGHTS')),
(14, JSON_OBJECT('sno','','target','b. PROGRAMME ON HIGHER STUDIES')),
(15, JSON_OBJECT('sno','','target','c. PROGRAMME ON ENTREPRENEURSHIP')),
(16, JSON_OBJECT('sno','10','target','NO. OF FACULTY MEMBERS - COMPLETED ONLINE CERTIFICATE COURSE')),
(17, JSON_OBJECT('sno','','target','a. NPTEL')),
(18, JSON_OBJECT('sno','','target','b. Others')),
(19, JSON_OBJECT('sno','11','target','INDUSTRY INTERACTION / MOU / INDUSTRY SUPPORTED LAB')),
(20, JSON_OBJECT('sno','12','target','NO OF STUDENTS COMPLETED INDUSTRY INTERNSHIP (4 weeks & above)')),
(21, JSON_OBJECT('sno','13','target','NO OF STUDENTS COMPLETED SUMMER TRAINING (less than 4 weeks)')),
(22, JSON_OBJECT('sno','14','target','STUDENTS PROJECT WITH QUALITY AND PUBLISH THE PROJECTS in Conference, Journal, Hackathon & YouTube')),
(23, JSON_OBJECT('sno','15','target','FACULTY PARTICIPATIONS IN FDP / TRAINING ACTIVITIES / STTP / CONFERENCE')),
(24, JSON_OBJECT('sno','16','target','NO. OF MEMBERSHIP IN PROFESSIONAL SOCIETIES (Faculties & Students)')),
(25, JSON_OBJECT('sno','17','target','NEWSLETTER')),
(26, JSON_OBJECT('sno','18','target','NO. OF ONLINE CERTIFICATIONS COMPLETED BY STUDENTS')),
(27, JSON_OBJECT('sno','19','target','NO. OF STUDENTS COMPLETED IIT-BOMBAY SPOKEN TUTORIAL COURSES')),
(28, JSON_OBJECT('sno','20','target','a. PARTICIPATION IN INTER-INSTITUTE EVENTS BY STUDENTS - WITHIN STATE')),
(29, JSON_OBJECT('sno','','target','b. OUTSIDE STATE')),
(30, JSON_OBJECT('sno','','target','c. AWARDS / PRIZES')),
(31, JSON_OBJECT('sno','21','target','NO OF VALUE ADDED COURSE / HANDS ON TRAINING COURSES')),
(32, JSON_OBJECT('sno','22','target','EVENTS PARTICIPATION IN SPORTS')),
(33, JSON_OBJECT('sno','','target','a. STATE LEVEL')),
(34, JSON_OBJECT('sno','','target','b. NATIONAL LEVEL')),
(35, JSON_OBJECT('sno','','target','c. AWARDS / MEDALS')),
(36, JSON_OBJECT('sno','23','target','INNOVATION EVENTS TO BE CONDUCTED')),
(37, JSON_OBJECT('sno','24','target','IIC ACTIVITIES')),
(38, JSON_OBJECT('sno','25','target','WEBSITE UPDATION')),
(39, JSON_OBJECT('sno','26','target','STARTUP')),
(40, JSON_OBJECT('sno','27','target','ALUMNI CHAPTER')),
(41, JSON_OBJECT('sno','28','target','AWARDS')),
(42, JSON_OBJECT('sno','29','target','RECOGNITION for Faculty')),
(43, JSON_OBJECT('sno','','target','a. BOS / DC MEMBERS')),
(44, JSON_OBJECT('sno','','target','b. QP / Key SETTER')),
(45, JSON_OBJECT('sno','','target','c. Reviewer for Journal')),
(46, JSON_OBJECT('sno','','target','d. Resource Person')),
(47, JSON_OBJECT('sno','','target','e. Others')),
(48, JSON_OBJECT('sno','30','target','NO. OF ACTIVITIES CONDUCTED BY NSS / YRC'));
