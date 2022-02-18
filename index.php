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
			pages.slug as slug,
			pages.title as title,
			revisions.body as body,
			MAX(revisions.time_created) as last_modified
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

<?php
	function into_html($body)
	{
		foreach(explode("\n", $body) as $line) {
			yield "<p>" . htmlentities($line) . "</p>";
		}
	}
?>
<body>
	<?php foreach($statement as $page) {
		$slug = $page["slug"];
		$title = $page["title"];
		$body = $page["body"];
		$last_modified = $page["last_modified"];

		$action = $_GET[$slug];
	?>
		<article>
			<h1><?= $title ?></h1>
			<?php foreach(into_html($body) as $elem) {
				echo $elem;
			} ?>
		</article>
	<?php } ?>
</body>
