-- 1.0.19: users found by e-mail and waiting for an administrator get their own status, "pending"
-- (they were stored as "suspended"). A waiting row was stored with matched_at = checked_at and
-- has not been touched since; a mapping suspended later has a newer checked_at.
UPDATE `#__biotime_usermap`
   SET `status` = 'pending'
 WHERE `status` = 'suspended'
   AND `source` = 'auto'
   AND `employee_id` IS NOT NULL
   AND `matched_at` = `checked_at`;
