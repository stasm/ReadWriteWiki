BEGIN TRANSACTION;
DROP TABLE IF EXISTS "revisions";
CREATE TABLE IF NOT EXISTS "revisions" (
	"id"	INTEGER NOT NULL UNIQUE,
	"page_id"	INTEGER NOT NULL,
	"body"	TEXT,
	"time_created"	INTEGER NOT NULL,
	"is_cosmetic"	INTEGER,
	FOREIGN KEY("page_id") REFERENCES "pages"("id"),
	PRIMARY KEY("id" AUTOINCREMENT)
);
DROP TABLE IF EXISTS "pages";
CREATE TABLE IF NOT EXISTS "pages" (
	"id"	INTEGER NOT NULL UNIQUE,
	"title"	TEXT NOT NULL UNIQUE,
	"slug"	TEXT NOT NULL UNIQUE,
	"body"	TEXT,
	"last_modified"	INTEGER,
	PRIMARY KEY("id" AUTOINCREMENT)
);
DROP INDEX IF EXISTS "slugs";
CREATE UNIQUE INDEX IF NOT EXISTS "slugs" ON "pages" (
	"slug"
);
DROP TRIGGER IF EXISTS "update_page_body";
CREATE TRIGGER update_page_body INSERT ON revisions 
  BEGIN
    UPDATE
		pages
	SET
		body = new.body,
		last_modified = new.time_created
	WHERE
		id = new.page_id;
  END;
COMMIT;
