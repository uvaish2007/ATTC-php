-- Adds Dean to users.role.
--
-- The login screen has always offered Dean, and the code branches on it
-- (approvals.php, attempt_login), but the enum had no such member, so a Dean
-- account could not be stored. Safe to re-run.

ALTER TABLE users
  MODIFY COLUMN role ENUM('Admin','Director','Dean','HoD','Coordinator','Faculty')
  NOT NULL DEFAULT 'Faculty';
