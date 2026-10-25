-- Migration: Merge State & National Sports Achievements
-- ATTS IQAC System — Safe Schema Migration

ALTER TABLE `inst_sports_state` ADD COLUMN IF NOT EXISTS `participation_id` INT NULL DEFAULT NULL AFTER `award`;
ALTER TABLE `inst_sports_state` ADD INDEX IF NOT EXISTS `idx_iss_part_id` (`participation_id`);

ALTER TABLE `inst_sports_national` ADD COLUMN IF NOT EXISTS `participation_id` INT NULL DEFAULT NULL AFTER `award`;
ALTER TABLE `inst_sports_national` ADD INDEX IF NOT EXISTS `idx_isn_part_id` (`participation_id`);

-- Update Metric Studio table to merge separate State/National sports subcategories into unified form
UPDATE `metrics` SET `subcategories_config` = NULL, `metric_type` = 'specialized' WHERE `metric_key` = 'sports';
