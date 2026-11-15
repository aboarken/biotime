CREATE TABLE IF NOT EXISTS `#__biotime_settings` (
  `name` VARCHAR(100) NOT NULL,
  `value` MEDIUMTEXT NOT NULL,
  PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__biotime_audit` (
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

CREATE TABLE IF NOT EXISTS `#__biotime_usermap` (
  `joomla_user_id` INT UNSIGNED NOT NULL,
  `employee_id` VARCHAR(20) NULL DEFAULT NULL,
  `emp_code` VARCHAR(30) NULL DEFAULT NULL,
  `email` VARCHAR(320) NOT NULL DEFAULT '',
  `status` VARCHAR(20) NOT NULL,
  `source` VARCHAR(10) NOT NULL DEFAULT 'auto',
  `matched_at` DATETIME NULL DEFAULT NULL,
  `checked_at` DATETIME NOT NULL,
  `modified_by` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`joomla_user_id`),
  UNIQUE KEY `idx_employee_id` (`employee_id`),
  KEY `idx_status` (`status`),
  KEY `idx_emp_code` (`emp_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;
