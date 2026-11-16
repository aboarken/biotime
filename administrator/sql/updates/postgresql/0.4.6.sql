-- 0.4.6: saved ZKBioTime data in its own table instead of the user session.
CREATE TABLE IF NOT EXISTS "#__biotime_cache" (
  "cache_key" varchar(191) NOT NULL,
  "user_id" bigint DEFAULT 0 NOT NULL,
  "expires" bigint NOT NULL,
  "value" text NOT NULL,
  PRIMARY KEY ("cache_key")
);
CREATE INDEX IF NOT EXISTS "#__biotime_cache_idx_user_id" ON "#__biotime_cache" ("user_id");
CREATE INDEX IF NOT EXISTS "#__biotime_cache_idx_expires" ON "#__biotime_cache" ("expires");
