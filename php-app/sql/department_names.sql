-- Department names, matched to the ones the reports print.
--
-- The table was seeded with short forms ("Aero", "Mech", "AI&ML") while
-- department_full_name() in inc/report_layout.php holds the official names.
-- The two disagreed on thirteen of seventeen rows, so a department read as
-- "Aero" on screen and "Aeronautical Engineering" on its own printed report.
--
-- Only the display name changes; codes, ids and every row that references a
-- department are untouched. Safe to re-run.

UPDATE departments SET name = 'Aeronautical Engineering'                     WHERE code = 'AERO';
UPDATE departments SET name = 'Civil Engineering'                            WHERE code = 'CIVIL';
UPDATE departments SET name = 'Computer Science and Business Systems'        WHERE code = 'CSBS';
UPDATE departments SET name = 'Electrical and Electronics Engineering'       WHERE code = 'EEE';
UPDATE departments SET name = 'Electronics and Communication Engineering'    WHERE code = 'ECE';
UPDATE departments SET name = 'Marine Engineering'                           WHERE code = 'MARINE';
UPDATE departments SET name = 'Mechanical Engineering'                       WHERE code = 'MECH';
UPDATE departments SET name = 'Artificial Intelligence and Machine Learning' WHERE code = 'AIML';
UPDATE departments SET name = 'Chemical Engineering'                         WHERE code = 'CHEM';
UPDATE departments SET name = 'Information Technology'                       WHERE code = 'IT';
UPDATE departments SET name = 'Architecture'                                 WHERE code = 'ARCH';
UPDATE departments SET name = 'Master of Computer Applications'              WHERE code = 'MCA';
UPDATE departments SET name = 'Master of Business Administration'            WHERE code = 'MBA';
