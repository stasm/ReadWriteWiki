<?php
const MAIN_PAGE = 'HomePage';
const PAGE_TITLE = '/\b(([[:upper:]][[:lower:]]+){2,})\b/';
const BEFORE_UPPER = '/(?=[[:upper:]])/';

class NewPage
{
	public $slug;
	public $title;
	public $body = '';

	public function __construct($slug)
	{
		$words = preg_split(BEFORE_UPPER, $slug);
		$this->title = implode(' ', $words);
		$this->slug = $slug;
	}
}

class Page extends NewPage
{
	public $last_modified;
	private $time_modified;

	public static function IsValidTitle($text)
	{
		preg_match(PAGE_TITLE, $text, $matches);
		return $matches && $matches[0] == $text;
	}

	public function __construct()
	{
		parent::__construct($this->slug);
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
		SELECT slug, body, time_modified
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

function view_edit($slug)
{
	$pdo = new PDO('sqlite:./wk.sqlite');
	$statement = $pdo->prepare("
		SELECT slug, body, time_modified
		FROM pages
		WHERE pages.slug = ?
	;");

	$statement->execute(array($slug));
	$statement->setFetchMode(PDO::FETCH_CLASS, 'Page');
	$page = $statement->fetch();

	if (!$page) {
		$page = new NewPage($slug);
		render_edit($page);
	} else {
		render_edit($page);
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

switch (strtoupper($_SERVER['REQUEST_METHOD'])) {
case 'GET':
	if (empty($_GET)) {
		header('Location: ?' . MAIN_PAGE, true, 303);
		exit;
	}

	render_head();
	foreach($_GET as $slug => $action) {
		if (!Page::IsValidTitle($slug)) {
			die("{$slug} is not a valid page title.");
		}

		switch ($action) {
			case "edit":
				view_edit($slug);
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
	render_end();
	break;
case 'POST':
	// TODO Validate
	// TODO CSRF
	$slug = $_POST['slug'];
	$body = $_POST['body'];

	$pdo = new PDO('sqlite:./wk.sqlite');
	$statement = $pdo->prepare("
		INSERT INTO REVISIONS (page_id, body, time_created)
		VALUES (
			(SELECT id FROM pages WHERE slug = ?),
			?, ?
		)
	;");
	$success = $statement->execute(array($slug, $body, date('U')));
	if ($success) {
		header("Location: ?{$slug}", true, 303);
		exit;
	}

	die("Unable to create a new revision of {$slug}.");
}

// Rendering templates

function render_head()
{ ?>
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
				min-width: min(calc(100vw - 16px), 500px);
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

			textarea {
				width: 460px;
				height: 300px;
				background: cornsilk;
			}
		</style>
	</head>
<?php }

function render_end()
{ ?>
	</body>
<?php }

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

function render_edit($page)
{ ?>
	<article>
		<h1 class="meta">
			Edit
			<a href="?<?=$page->slug?>">
				<?=$page->title?>
			</a>
		</h1>

		<form method="post" action="?">
			<input type="hidden" name="slug" value="<?=$page->slug?>">
			<textarea name="body" placeholder="Type here..."><?=$page->body?></textarea>
			<button type="submit">Save</button>
		</form>
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
