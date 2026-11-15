-- 0.2.0: employee mapping table (Joomla user -> ZKBioTime employee).
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
