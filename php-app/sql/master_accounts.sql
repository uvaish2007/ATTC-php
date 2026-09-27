-- Master Accounts: master@atts.edu / master123 for all roles
INSERT INTO users (name, email, password, role, department, status)
VALUES
  ('Master (Admin)',       'master.admin@atts.edu',       '$2y$12$Zw74FrRALYdT8b32aN6QcOKMA1FoIxQjrWMJp9wDlVrY0QlL8KqIq', 'Admin',       NULL,   1),
  ('Master (Principal)',   'master.principal@atts.edu',   '$2y$12$Zw74FrRALYdT8b32aN6QcOKMA1FoIxQjrWMJp9wDlVrY0QlL8KqIq', 'Director',    NULL,   1),
  ('Master (Dean)',        'master.dean@atts.edu',        '$2y$12$Zw74FrRALYdT8b32aN6QcOKMA1FoIxQjrWMJp9wDlVrY0QlL8KqIq', 'Dean',        NULL,   1),
  ('Master (HoD)',         'master.hod@atts.edu',         '$2y$12$Zw74FrRALYdT8b32aN6QcOKMA1FoIxQjrWMJp9wDlVrY0QlL8KqIq', 'HoD',         'CSBS', 1),
  ('Master (Coordinator)', 'master.coordinator@atts.edu', '$2y$12$Zw74FrRALYdT8b32aN6QcOKMA1FoIxQjrWMJp9wDlVrY0QlL8KqIq', 'Coordinator', 'CSBS', 1),
  ('Master (Faculty)',     'master.faculty@atts.edu',     '$2y$12$Zw74FrRALYdT8b32aN6QcOKMA1FoIxQjrWMJp9wDlVrY0QlL8KqIq', 'Faculty',     'CSBS', 1)
ON DUPLICATE KEY UPDATE
  name = VALUES(name),
  password = VALUES(password),
  role = VALUES(role),
  department = VALUES(department),
  status = 1;
