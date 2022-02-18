<!doctype html>
<title>wk</title>
<head>
	<style>
		article {
			max-width: 500px;
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
			$line = htmlentities($line);
			$line = linkify_titles($line);
			yield "<p>" . $line . "</p>";
		}
	}

	function linkify_titles($text)
	{
		return preg_replace(
				"/\b(([A-Z][a-z]+){2,})/",
				"<a href='?$1'>$1</a>",
				$text);
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
