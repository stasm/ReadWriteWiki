<?php

$pdo = new PDO('sqlite:./wiki.db');
$revisions = $pdo->query('
	SELECT *
	FROM revisions
;');

foreach ($revisions as $rev) {
    $id = $rev['id'];
    $slug = $rev['slug'];
    $addr = $rev['remote_addr'];
	$blob = inet_pton($addr);

	$statement = $pdo->prepare('
		UPDATE revisions
		SET remote_ip = ?
		WHERE id = ?
	;');

	$statement->execute(array($blob, $id));
	print "${slug}[$id]\n";
}
