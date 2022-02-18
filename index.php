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
<?php
	foreach($_GET as $slug => $action) {
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
				pages.slug = ?
			GROUP BY
				pages.slug
			;"
		);

		$statement->execute(array($slug));
		$page = $statement->fetch(PDO::FETCH_OBJ);

		if (!$page) {
			?>
				<article>
					<h1>Page Not Found</h1>
					<p><?=$slug?> doesn't exist yet. <a href="?<?=$slug?>=edit">Create?</a></p>
				</article>
			<?php
			continue;
		}
	?>
		<article>
			<h1><?=$page->title?></h1>
			<?php foreach(into_html($page->body) as $elem) {
				echo $elem;
			} ?>
		</article>
	<?php } ?>
</body>
