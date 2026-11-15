CREATE TABLE IF NOT EXISTS "#__zkbiotime_settings" (
  "name" varchar(100) NOT NULL,
  "value" text NOT NULL,
  PRIMARY KEY ("name")
);

CREATE TABLE IF NOT EXISTS "#__zkbiotime_audit" (
  "id" bigserial NOT NULL,
  "user_id" bigint DEFAULT 0 NOT NULL,
  "actor_id" bigint DEFAULT 0 NOT NULL,
  "action" varchar(50) NOT NULL,
  "result" varchar(20) NOT NULL,
  "detail" varchar(255) DEFAULT '' NOT NULL,
  "created" timestamp without time zone NOT NULL,
  PRIMARY KEY ("id")
);
CREATE INDEX IF NOT EXISTS "#__zkbiotime_audit_idx_user_action_created" ON "#__zkbiotime_audit" ("user_id", "action", "created");
CREATE INDEX IF NOT EXISTS "#__zkbiotime_audit_idx_action_created" ON "#__zkbiotime_audit" ("action", "created");
