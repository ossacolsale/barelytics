-- Synthetic schema-2 store. No production data or usable credential.
CREATE TABLE schema_migrations(version INTEGER PRIMARY KEY,applied_at TEXT NOT NULL);
INSERT INTO schema_migrations VALUES (1,'2026-01-01'),(2,'2026-01-01');
CREATE TABLE settings(key TEXT PRIMARY KEY,value TEXT NOT NULL);
INSERT INTO settings VALUES ('retention_days','30'),('admin_password_hash','fixture-unchanged-credential'),('setup_complete','1');
CREATE TABLE pageviews_daily(day TEXT NOT NULL,path TEXT NOT NULL,country TEXT NOT NULL DEFAULT 'XX',views INTEGER NOT NULL DEFAULT 0,PRIMARY KEY(day,path,country));
INSERT INTO pageviews_daily VALUES ('2026-01-01','/legacy','XX',7);
CREATE TABLE referrers_daily(day TEXT NOT NULL,path TEXT NOT NULL,referrer_host TEXT NOT NULL,views INTEGER NOT NULL DEFAULT 0,PRIMARY KEY(day,path,referrer_host));
CREATE TABLE dimensions_daily(day TEXT NOT NULL,path TEXT NOT NULL,dimension TEXT NOT NULL,value TEXT NOT NULL,views INTEGER NOT NULL DEFAULT 0,PRIMARY KEY(day,path,dimension,value));
CREATE TABLE privacy_configuration_history(timestamp TEXT NOT NULL,profile TEXT NOT NULL,effective_configuration_json TEXT NOT NULL,configuration_hash TEXT NOT NULL,application_version TEXT NOT NULL,schema_version INTEGER NOT NULL);
