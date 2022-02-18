<!doctype html>
<title>wk</title>
<head>
	<style>
		article {
			width: 300px;
			float: left;
		}
	</style>
</head>

<?php
	$pdo = new PDO('sqlite:./wk.sqlite');
	$placeholders = implode(',', array_fill(0, count($_GET), '?'));
	$statement = $pdo->prepare(
		"SELECT
			pages.title as title,
			revisions.body as body,
			MAX(revisions.time_created) as time_created
		FROM
			revisions
			JOIN pages ON revisions.page_id = pages.id
		WHERE
			pages.slug IN ($placeholders)
		GROUP BY
			pages.slug
		;"
	);

	$statement->execute(array_keys($_GET));
?>
<body>
	<?php foreach($statement as $page) { ?>
		<article>
			<h1><?= $page["title"] ?></h1>
			<?= $page["body"] ?>
		</article>
	<?php } ?>
</body>
