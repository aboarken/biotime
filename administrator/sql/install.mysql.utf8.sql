CREATE TABLE IF NOT EXISTS `#__zkbiotime_settings` (
  `name` VARCHAR(100) NOT NULL,
  `value` MEDIUMTEXT NOT NULL,
  PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__zkbiotime_audit` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `actor_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `action` VARCHAR(50) NOT NULL,
  `result` VARCHAR(20) NOT NULL,
  `detail` VARCHAR(255) NOT NULL DEFAULT '',
  `created` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user_action_created` (`user_id`, `action`, `created`),
  KEY `idx_action_created` (`action`, `created`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;
