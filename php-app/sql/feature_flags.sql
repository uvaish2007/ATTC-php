-- ---------------------------------------------------------------------------
--  Feature flags
--
--  One row per module. `status` decides whether the module is reachable:
--
--      active       normal - the page works, nav link is live
--      maintenance  page returns a clean 423 "Coming Soon"; nav shows it locked
--      disabled     same block, kept for a future "hard off" distinction
--
--  Everything is seeded ACTIVE on purpose: the flag system is installed and
--  ready, but no module is hidden. To scope a release down to Targets only,
--  flip the others to 'maintenance' (see the commented UPDATE at the bottom).
--
--  Additive and safe to re-run: CREATE ... IF NOT EXISTS, and the seed uses
--  INSERT IGNORE so existing rows (and any status you have since set) are kept.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS feature_flags (
  id          INT PRIMARY KEY AUTO_INCREMENT,
  module_name VARCHAR(50) UNIQUE,
  status      ENUM('active','maintenance','disabled') NOT NULL DEFAULT 'active',
  updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

INSERT IGNORE INTO feature_flags (module_name, status) VALUES
  ('dashboard',     'active'),
  ('targets',       'active'),
  ('approvals',     'active'),
  ('announcements', 'active'),
  ('reports',       'active'),
  ('upload',        'active'),
  ('users',         'active'),
  ('departments',   'active'),
  ('faculty',       'active'),
  ('profile',       'active'),
  ('settings',      'active');

-- ---------------------------------------------------------------------------
--  To lock the app down to the Targets alpha (everything but Targets, and the
--  two always-on core modules, goes into maintenance), run:
--
--    UPDATE feature_flags
--       SET status = 'maintenance'
--     WHERE module_name NOT IN ('targets', 'dashboard', 'profile');
--
--  To bring a module back:
--
--    UPDATE feature_flags SET status = 'active' WHERE module_name = 'reports';
-- ---------------------------------------------------------------------------
