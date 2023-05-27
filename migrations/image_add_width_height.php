<?php

// ALTER TABLE images ADD COLUMN "image_width" INTEGER;
// ALTER TABLE images ADD COLUMN "image_height" INTEGER;

$pdo = new PDO('sqlite:./wiki.db');
$rows = $pdo->query('
	SELECT *
	FROM images
;');

foreach ($rows as $row) {
    $hash = $row['hash'];
    $blob = $row['image_data'];
	$image = imagecreatefromstring($blob);
	$width = imagesx($image);
	$height = imagesy($image);

	$statement = $pdo->prepare('
		UPDATE images
		SET image_width = ?, image_height = ?
		WHERE hash = ?
	;');

	$statement->execute(array($width, $height, $hash));
	print "$hash: $width x $height\n";
}
