<!doctype html>
<title>wk</title>
<head>
	<style>
		h1 a {
			color: #00f;
			text-decoration: none;
		}

		article {
			max-width: 500px;
			float: left;
		}

		footer {
			font-size: 70%;
		}
	</style>
</head>
<body>

<?php
class Page
{
	private $body;
	public $slug;
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
		render_not_found($slug);
	} else {
		render_page($page);
	}
}

function render_not_found($slug)
{ ?>
	<article>
		<h1>Page Not Found</h1>
		<p><?=$slug?> doesn't exist yet. <a href="?<?=$slug?>=edit">Create?</a></p>
	</article>
<?php }

function render_page($page)
{ ?>
	<article>
		<h1>
			<a href="?<?=$page->slug?>">
				<?=$page->title?>
			</a>
		</h1>

	<?php foreach($page->IntoHtml() as $elem): ?>
		<?=$elem?>
	<?php endforeach ?>

		<footer>
			<a href="?<?=$page->slug?>=refs">what links here?</a>
		</footer>
	</article>
<?php }

function view_refs($slug)
{
	$pdo = new PDO('sqlite:./wk.sqlite');
	$statement = $pdo->prepare(
		"SELECT
			pages.slug as slug,
			pages.title as title
		FROM
			pages
		WHERE
			slug = ?
		;"
	);
	$statement->execute(array($slug));
	$page = $statement->fetch(PDO::FETCH_OBJ);

	if (!$page) {
		render_not_found($slug);
		return;
	}

	$statement = $pdo->prepare(
		"SELECT
			pages.slug as slug,
			pages.title as title,
			revisions.body as body,
			MAX(revisions.time_created) as last_modified
		FROM
			revisions
			JOIN pages ON revisions.page_id = pages.id
		GROUP BY
			pages.slug
		HAVING
			revisions.body LIKE ?
		;"
	);

	$statement->execute(array("%" . $slug . "%"));
	$references = $statement->fetchAll(PDO::FETCH_OBJ);
	render_refs($page, $references);
}

function render_refs($page, $references)
{ ?>
	<article>
		<h1>
			What links to <a href="?<?=$page->slug?>"><?=$page->title?></a>?
		</h1>

		<ul>
		<?php foreach ($references as $reference): ?>
			<li>
				<a href="?<?=$reference->slug?>"><?=$reference->title?></a>
			</li>
		<?php endforeach ?>
		</ul>
	</article>
<?php }

foreach($_GET as $slug => $action) {
	switch ($action) {
		case "edit":
			break;
		case "hist":
			break;
		case "refs":
			view_refs($slug);
			break;
		case "read":
		default:
			view_read($slug);
	}
}

?>
</body>
