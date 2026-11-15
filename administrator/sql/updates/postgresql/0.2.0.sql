-- 0.2.0: employee mapping table (Joomla user -> ZKBioTime employee).
CREATE TABLE IF NOT EXISTS "#__biotime_usermap" (
  "joomla_user_id" bigint NOT NULL,
  "employee_id" varchar(20) DEFAULT NULL,
  "emp_code" varchar(30) DEFAULT NULL,
  "email" varchar(320) DEFAULT '' NOT NULL,
  "status" varchar(20) NOT NULL,
  "source" varchar(10) DEFAULT 'auto' NOT NULL,
  "matched_at" timestamp without time zone DEFAULT NULL,
  "checked_at" timestamp without time zone NOT NULL,
  "modified_by" bigint DEFAULT 0 NOT NULL,
  PRIMARY KEY ("joomla_user_id"),
  CONSTRAINT "#__biotime_usermap_idx_employee_id" UNIQUE ("employee_id")
);
CREATE INDEX IF NOT EXISTS "#__biotime_usermap_idx_status" ON "#__biotime_usermap" ("status");
CREATE INDEX IF NOT EXISTS "#__biotime_usermap_idx_emp_code" ON "#__biotime_usermap" ("emp_code");
