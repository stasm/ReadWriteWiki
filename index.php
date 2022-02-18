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
<body>

<?php
class Page
{
	private $slug;
	private $body;
	public $title;
	public $last_modified;

	public function IntoHtml()
	{
		foreach(explode("\n", $this->body) as $line) {
			$line = htmlentities($line);
			$line = $this->LinkifyTitles($line);
			yield "<p>" . $line . "</p>";
		}
	}

	private function LinkifyTitles($text)
	{
		return preg_replace(
				"/\b(([A-Z][a-z]+){2,})/",
				"<a href='?$1'>$1</a>",
				$text);
	}
}
?>

<?php

function view_read($slug)
{
	$pdo = new PDO('sqlite:./wk.sqlite');
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
	$statement->setFetchMode(PDO::FETCH_CLASS, 'Page');
	$page = $statement->fetch();

	if (!$page) {
		view_not_found($slug);
	} else {
		view_render($page);
	}
}

function view_not_found($slug)
{ ?>
	<article>
		<h1>Page Not Found</h1>
		<p><?=$slug?> doesn't exist yet. <a href="?<?=$slug?>=edit">Create?</a></p>
	</article>
<?php }

function view_render($page)
{ ?>
	<article>
		<h1><?=$page->title?></h1>
		<?php foreach($page->IntoHtml() as $elem) {
			echo $elem;
		} ?>
	</article>
<?php }

foreach($_GET as $slug => $action) {
	switch ($action) {
		case "create":
		case "edit":
		case "refs":
			break;
		case "read":
		default:
			view_read($slug);
	}
}

?>
</body>
