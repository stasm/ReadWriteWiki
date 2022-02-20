BEGIN TRANSACTION;
DROP TABLE IF EXISTS "pages";
CREATE TABLE IF NOT EXISTS "pages" (
	"id"	INTEGER NOT NULL UNIQUE,
	"slug"	TEXT NOT NULL UNIQUE,
	"body"	TEXT,
	"time_modified"	INTEGER,
	"remote_addr"	TEXT,
	PRIMARY KEY("id" AUTOINCREMENT)
);
DROP TABLE IF EXISTS "revisions";
CREATE TABLE IF NOT EXISTS "revisions" (
	"id"	INTEGER NOT NULL UNIQUE,
	"page_id"	INTEGER NOT NULL,
	"body"	TEXT,
	"time_created"	INTEGER NOT NULL,
	"remote_addr"	TEXT,
	PRIMARY KEY("id" AUTOINCREMENT),
	FOREIGN KEY("page_id") REFERENCES "pages"("id")
);
DROP INDEX IF EXISTS "slugs";
CREATE UNIQUE INDEX IF NOT EXISTS "slugs" ON "pages" (
	"slug"
);
DROP TRIGGER IF EXISTS "record_page_creation";
CREATE TRIGGER record_page_creation
AFTER INSERT ON pages 
BEGIN
    INSERT INTO revisions (
		page_id,
		body,
		time_created,
		remote_addr
	) VALUES (
		new.id,
		new.body,
		new.time_modified,
		new.remote_addr
	);
END;
DROP TRIGGER IF EXISTS "record_page_revision";
CREATE TRIGGER record_page_revision
AFTER UPDATE ON pages 
BEGIN
    INSERT INTO revisions (
		page_id,
		body,
		time_created,
		remote_addr
	) VALUES (
		new.id,
		new.body,
		new.time_modified,
		new.remote_addr
	);
END;
COMMIT;
