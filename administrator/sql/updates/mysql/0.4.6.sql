-- 0.4.6: saved ZKBioTime data in its own table instead of the user session.
CREATE TABLE IF NOT EXISTS `#__biotime_cache` (
  `cache_key` VARCHAR(191) NOT NULL,
  `user_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `expires` BIGINT NOT NULL,
  `value` MEDIUMTEXT NOT NULL,
  PRIMARY KEY (`cache_key`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_expires` (`expires`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;
