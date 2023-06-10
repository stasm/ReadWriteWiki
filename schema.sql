BEGIN TRANSACTION;

CREATE TABLE IF NOT EXISTS "revisions" (
	"id"	INTEGER PRIMARY KEY,
	"slug"	INTEGER NOT NULL,
	"time_created"	INTEGER NOT NULL,
	"remote_addr"	BLOB NOT NULL,
	"body"	TEXT,
	"image_hash"	TEXT
);

CREATE INDEX IF NOT EXISTS "slugs" ON "revisions" (
	"slug"
);

CREATE TABLE IF NOT EXISTS "images" (
	"hash"	text PRIMARY KEY,
	"time_created"	integer NOT NULL,
	"remote_addr"	blob NOT NULL,
	"content_type"	text NOT NULL,
	"image_data"	blob NOT NULL,
	"image_width"	INTEGER,
	"image_height"	INTEGER,
	"file_size"	INTEGER NOT NULL,
	"file_name"	TEXT NOT NULL,
	"page_slug"	TEXT NOT NULL
);

COMMIT;
