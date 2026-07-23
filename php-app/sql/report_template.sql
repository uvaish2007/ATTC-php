-- ---------------------------------------------------------------------------
--  Admin-designed report template — columns and rows the Admin builds by hand,
--  which every department's report then follows.
--
--    report_columns  the columns of the report, in order (Admin adds/removes)
--    report_rows     the rows, each carrying one value per column (JSON keyed
--                    by the column's col_key)
--
--  Additive and safe to re-run. Seeds the seven proforma columns the first
--  time so the builder opens on the familiar layout rather than a blank grid.
-- ---------------------------------------------------------------------------
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS report_columns (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  col_key     VARCHAR(40)  NOT NULL UNIQUE,   -- stable key used inside a row's JSON
  label       VARCHAR(120) NOT NULL,
  width       INT          NOT NULL DEFAULT 12,   -- percent hint for the print layout
  align       VARCHAR(10)  NOT NULL DEFAULT 'left',
  sort_order  INT          NOT NULL DEFAULT 0,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS report_rows (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  sort_order  INT       NOT NULL DEFAULT 0,
  cells       JSON      NULL,              -- { "col_key": "value", ... }
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed the default columns once (only if the table is empty).
INSERT INTO report_columns (col_key, label, width, align, sort_order)
SELECT * FROM (
  SELECT 'sno'        AS col_key, 'S.No'                                AS label,  5 AS width, 'center' AS align, 1 AS sort_order UNION ALL
  SELECT 'target',        'Target / Details',                             34, 'left',   2 UNION ALL
  SELECT 'fixed',         'Fixed',                                         9, 'center', 3 UNION ALL
  SELECT 'achieved_from', 'Achieved (From 01.07.25 to 11.01.26)',        11, 'center', 4 UNION ALL
  SELECT 'achieved_during','Achieved (During 12.01.26 to 11.02.26)',     11, 'center', 5 UNION ALL
  SELECT 'remarks',       'Progress / Remarks',                          20, 'left',   6 UNION ALL
  SELECT 'coordinator',   'Coordinator',                                 10, 'left',   7
) AS seed
WHERE NOT EXISTS (SELECT 1 FROM report_columns);

-- A column is either 'label' (the Admin types it — the report's structure, e.g.
-- S.No and Target/Details) or 'data' (filled automatically from the values a
-- department uploads against that target). For a data column, `field` names the
-- target field that fills it.
SET @ddl := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='report_columns' AND COLUMN_NAME='source')>0,
  'DO 0', "ALTER TABLE report_columns ADD COLUMN source VARCHAR(10) NOT NULL DEFAULT 'label' AFTER align");
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;
SET @ddl := IF((SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='report_columns' AND COLUMN_NAME='field')>0,
  'DO 0', 'ALTER TABLE report_columns ADD COLUMN field VARCHAR(30) NULL AFTER source');
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

-- Default mapping for the seeded columns (only where not already configured, so
-- re-running never clobbers an Admin's later choices).
UPDATE report_columns SET source='label' WHERE col_key IN ('sno','target');
UPDATE report_columns SET source='data', field='fixed_text'  WHERE col_key='fixed'           AND field IS NULL;
UPDATE report_columns SET source='data', field='achieved_p1' WHERE col_key='achieved_from'   AND field IS NULL;
UPDATE report_columns SET source='data', field='achieved_p2' WHERE col_key='achieved_during' AND field IS NULL;
UPDATE report_columns SET source='data', field='remarks'     WHERE col_key='remarks'         AND field IS NULL;
UPDATE report_columns SET source='data', field='coordinator' WHERE col_key='coordinator'     AND field IS NULL;
