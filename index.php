<!doctype html>
<title>wk</title>
<head>
	<style>
		body {
			display: flex;
			align-items: flex-start;
			gap: 15px;
		}

		h1 a {
			color: blue;
			text-decoration: none;
		}

		article {
			box-sizing: border-box;
			min-width: 300px;
			max-width: 500px;
			padding: 15px;
		}

		ul {
			padding-left: 1em;
		}

		.meta {
			font-style: italic;
		}

		footer {
			font-size: 80%;
		}
	</style>
</head>

<?php
const PAGE_TITLE = "/\b(([[:upper:]][[:lower:]]+){2,})\b/";

function is_valid_title($text)
{
	preg_match(PAGE_TITLE, $text, $matches);
	return $matches && $matches[0] == $text;
}

class Page
{
	public $slug;
	public $title;
	public $last_modified;

	private $body;
	private $time_modified;

	public function __construct()
	{
		$this->last_modified = DateTime::createFromFormat('U', $this->time_modified);
	}

	public function IntoHtml()
	{
		$list_level = 0;
		foreach(explode("\n", $this->body) as $line) {
			if (!$line) {
				continue;
			}

			if (str_starts_with($line, "---")) {
				if ($list_level > 0) {
					$list_level = 0;
					yield "</ul>";
				}

				yield "<hr>";
				continue;
			}

			if (str_starts_with($line, "*")) {
				if ($list_level == 0) {
					$list_level += 1;
					yield "<ul>";
				}

				$line = substr($line, 1);
				yield "<li>" . $line . "</li>";
				continue;
			}

			if ($list_level > 0) {
				$list_level = 0;
				yield "</ul>";
			}

			$line = htmlentities($line);
			$line = $this->Strongify($line);
			$line = $this->Linkify($line);
			yield "<p>" . $line . "</p>";
		}

		if ($list_level > 0) {
			$list_level = 0;
			yield "</ul>";
		}

	}

	private function Linkify($text)
	{
		return preg_replace(
				PAGE_TITLE,
				"<a href='?$1'>$1</a>",
				$text);
	}

	private function Strongify($text)
	{
		return preg_replace(
				"/\b__(.+?)__\b/",
				"<strong>$1</strong>",
				$text);
	}
}

function view_read($slug)
{
	$pdo = new PDO('sqlite:./wk.sqlite');
	$statement = $pdo->prepare("
		SELECT slug, title, body, time_modified
		FROM pages
		WHERE pages.slug = ?
	;");

	$statement->execute(array($slug));
	$statement->setFetchMode(PDO::FETCH_CLASS, 'Page');
	$page = $statement->fetch();

	if (!$page) {
		render_not_found($slug);
	} else {
		render_page($page);
	}
}

function view_refs($slug)
{
	$pdo = new PDO('sqlite:./wk.sqlite');
	$statement = $pdo->prepare("
		SELECT slug
		FROM pages
		WHERE slug = ?
	;");
	$statement->execute(array($slug));
	$page = $statement->fetch(PDO::FETCH_OBJ);

	if (!$page) {
		render_not_found($slug);
		return;
	}

	$statement = $pdo->prepare("
		SELECT slug, body
		FROM pages
		WHERE body LIKE ?
	;");

	$statement->execute(array("%" . $slug . "%"));
	$references = $statement->fetchAll(PDO::FETCH_OBJ);
	render_refs($page, $references);
}

?>

<body>
<?php

foreach($_GET as $slug => $action) {
	if (!is_valid_title($slug)) {
		die("Not a valid page");
	}

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

<?php // Rendering templates

function render_not_found($slug)
{ ?>
	<article class="meta">
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

		<footer class="meta">
			last modified: <span title="<?=$page->last_modified->format(DateTime::COOKIE)?>"><?=$page->last_modified->format("F j, Y")?></span>
			<br>
			<a href="?<?=$page->slug?>=hist">history</a>
			<a href="?<?=$page->slug?>=edit">edit</a>
			<a href="?<?=$page->slug?>=refs">backlinks</a>
		</footer>
	</article>
<?php }

function render_refs($page, $references)
{ ?>
	<article>
		<h1 class="meta">
			What links to <a href="?<?=$page->slug?>"><?=$page->slug?></a>?
		</h1>

		<ul>
		<?php foreach ($references as $reference): ?>
			<li>
				<a href="?<?=$reference->slug?>"><?=$reference->slug?></a>
			</li>
		<?php endforeach ?>
		</ul>
	</article>
<?php }

?>
