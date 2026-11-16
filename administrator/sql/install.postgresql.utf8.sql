CREATE TABLE IF NOT EXISTS "#__biotime_settings" (
  "name" varchar(100) NOT NULL,
  "value" text NOT NULL,
  PRIMARY KEY ("name")
);

CREATE TABLE IF NOT EXISTS "#__biotime_audit" (
  "id" bigserial NOT NULL,
  "user_id" bigint DEFAULT 0 NOT NULL,
  "actor_id" bigint DEFAULT 0 NOT NULL,
  "action" varchar(50) NOT NULL,
  "result" varchar(20) NOT NULL,
  "detail" varchar(255) DEFAULT '' NOT NULL,
  "created" timestamp without time zone NOT NULL,
  PRIMARY KEY ("id")
);
CREATE INDEX IF NOT EXISTS "#__biotime_audit_idx_user_action_created" ON "#__biotime_audit" ("user_id", "action", "created");
CREATE INDEX IF NOT EXISTS "#__biotime_audit_idx_action_created" ON "#__biotime_audit" ("action", "created");

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

CREATE TABLE IF NOT EXISTS "#__biotime_cache" (
  "cache_key" varchar(191) NOT NULL,
  "user_id" bigint DEFAULT 0 NOT NULL,
  "expires" bigint NOT NULL,
  "value" text NOT NULL,
  PRIMARY KEY ("cache_key")
);
CREATE INDEX IF NOT EXISTS "#__biotime_cache_idx_user_id" ON "#__biotime_cache" ("user_id");
CREATE INDEX IF NOT EXISTS "#__biotime_cache_idx_expires" ON "#__biotime_cache" ("expires");
