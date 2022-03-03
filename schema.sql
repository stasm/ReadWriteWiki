BEGIN TRANSACTION;
CREATE TABLE IF NOT EXISTS "revisions" (
	"id"	INTEGER NOT NULL UNIQUE,
	"slug"	INTEGER NOT NULL,
	"body"	TEXT,
	"time_created"	INTEGER NOT NULL,
	"remote_addr"	TEXT,
	PRIMARY KEY("id" AUTOINCREMENT)
);
CREATE INDEX IF NOT EXISTS "slugs" ON "revisions" (
	"slug"
);
CREATE VIEW changelog AS
SELECT id, slug, body, LENGTH(body) AS size, time_created, remote_addr
FROM revisions;
CREATE VIEW latest AS
SELECT MAX(id) AS id, slug, body, time_created, remote_addr
FROM revisions
GROUP BY slug;
COMMIT;
