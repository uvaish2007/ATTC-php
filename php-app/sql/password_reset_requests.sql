-- ---------------------------------------------------------------------------
--  FEAT-11: Self-Service Password Reset Request
--
--  A user who cannot sign in asks the Administrator to change their password
--  from the login page. That raises a Pending ticket here; the Admin either
--  completes it (setting a new password on the EXISTING users row) or rejects
--  it. The user never changes their own password through this flow.
--
--  This adds ONE table. No new users / faculty / department / auth table is
--  created: user_id, email, name, role and department are copied from the
--  existing `users` row at the moment the ticket is raised, so the Admin can
--  still see who asked even if that account is later edited.
--
--  The new password is NEVER stored here, in any form. It is hashed straight
--  into users.password by the same password_hash() the rest of the app uses.
--
--  Safe to re-run: CREATE TABLE IF NOT EXISTS.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `password_reset_requests` (
  `id`           INT AUTO_INCREMENT PRIMARY KEY,
  `user_id`      INT NOT NULL,
  `email`        VARCHAR(190) NOT NULL,
  `name`         VARCHAR(150) NOT NULL,
  `role`         VARCHAR(50)  NOT NULL,
  `department`   VARCHAR(150) NULL,
  `message`      TEXT NULL,
  `status`       ENUM('Pending','Completed','Rejected') NOT NULL DEFAULT 'Pending',
  `admin_notes`  TEXT NULL,
  `processed_by` INT NULL,
  `processed_at` DATETIME NULL,
  `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  INDEX `idx_status` (`status`),
  INDEX `idx_user_id` (`user_id`),
  INDEX `idx_created_at` (`created_at`),

  CONSTRAINT `fk_password_reset_user`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,

  CONSTRAINT `fk_password_reset_processor`
    FOREIGN KEY (`processed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
