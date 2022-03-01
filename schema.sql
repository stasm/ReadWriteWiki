BEGIN TRANSACTION;
CREATE VIEW changelog AS
SELECT id, slug, body, LENGTH(body) AS size, time_created, remote_addr
FROM revisions;
COMMIT;
