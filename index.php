<?php
const DB_NAME = 'wk.sqlite';
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
		$inside_list = false;
		$inside_pre = false;

		foreach(explode(PHP_EOL, $this->body) as $line) {

			if (str_starts_with($line, "---")) {
				if ($inside_list) {
					$inside_list = false;
					yield "</ul>";
				}
				if ($inside_pre) {
					$inside_pre = false;
					yield "</pre>";
				}

				yield "<hr>";
				continue;
			}

			if (str_starts_with($line, "*")) {
				if ($inside_pre) {
					$inside_pre = false;
					yield "</pre>";
				}
				if ($inside_list == false) {
					$inside_list = true;
					yield "<ul>";
				}

				$line = substr($line, 1);
				yield "<li>" . $line . "</li>";
				continue;
			}

			if (str_starts_with($line, " ")) {
				if ($inside_list) {
					$inside_list = false;
					yield "</ul>";
				}
				if ($inside_pre == false) {
					$inside_pre = true;
					yield "<pre>";
				}

				yield substr($line, 1);
				continue;
			}

			$line = trim($line);
			if ($line != '') {
				if ($inside_list) {
					$inside_list = false;
					yield "</ul>";
				}
				if ($inside_pre) {
					$inside_pre = false;
					yield "</pre>";
				}

				$line = htmlentities($line);
				$line = $this->Strongify($line);
				$line = $this->Linkify($line);
				yield "<p>" . $line . "</p>";
				continue;
			}

			if ($inside_pre) {
				$inside_pre = false;
				yield "</pre>";
			}
		}

		if ($inside_list) {
			yield "</ul>";
		}
		if ($inside_pre) {
			yield "</pre>";
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

function view_read($pdo, $slug)
{
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

function view_rev($pdo, $slug, $rev)
{
	$statement = $pdo->prepare("
		SELECT
			pages.slug as slug,
			revisions.body as body,
			revisions.time_created as time_modified
		FROM
			revisions
			JOIN pages ON revisions.page_id = pages.id
		WHERE
			pages.slug = ?
			AND revisions.id = ?
	;");

	$statement->execute(array($slug, $rev));
	$statement->setFetchMode(PDO::FETCH_CLASS, 'Page');
	$page = $statement->fetch();

	if (!$page) {
		render_not_found($slug, $rev);
	} else {
		render_rev($page);
	}
}

function view_edit($pdo, $slug)
{
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

function view_refs($pdo, $slug)
{
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

$pdo = new PDO('sqlite:./' . DB_NAME);

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

		if (is_array($action)) {
			foreach ($action as $rev => $_) {
				view_rev($pdo, $slug, $rev);
			}
			// Only reading is supported for revisions.
			continue;
		}

		switch ($action) {
			case "edit":
				view_edit($pdo, $slug);
			case "hist":
				break;
			case "refs":
				view_refs($pdo, $slug);
				break;
			case "read":
			default:
				view_read($pdo, $slug);
		}
	}
	render_end();
	break;
case 'POST':
	// TODO Validate
	// TODO CSRF
	$slug = $_POST['slug'];
	$body = $_POST['body'];
	$time = date('U');
	$addr = $_SERVER['REMOTE_ADDR'];

	$statement = $pdo->prepare("
		INSERT INTO pages (slug, body, time_modified, remote_addr)
		VALUES (:slug, :body, :time, :addr)
		ON CONFLICT (slug) DO UPDATE
		SET
			body = :body_up,
			time_modified = :time_up,
			remote_addr = :addr_up
	;");

	$statement->bindParam('slug', $slug, PDO::PARAM_STR);
	$statement->bindParam('body', $body, PDO::PARAM_STR);
	$statement->bindParam('time', $time, PDO::PARAM_INT);
	$statement->bindParam('addr', $addr, PDO::PARAM_STR);
	$statement->bindParam('body_up', $body, PDO::PARAM_STR);
	$statement->bindParam('time_up', $time, PDO::PARAM_INT);
	$statement->bindParam('addr_up', $addr, PDO::PARAM_STR);

	if ($statement->execute()) {
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

			article ul {
				padding-left: 1em;
			}

			article pre {
				padding: 3px 1px;
				background: whitesmoke;
			}

			article a {
				text-decoration: none;
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

function render_not_found($slug, $rev = null)
{ ?>
	<article class="meta">
	<?php if ($rev): ?>
		<h1>Revision Not Found</h1>
		<p><?=$slug?>[<?=$rev?>] doesn't exist.</p>
	<?php else: ?>
		<h1>Page Not Found</h1>
		<p><?=$slug?> doesn't exist yet. <a href="?<?=$slug?>=edit">Create?</a></p>
	<?php endif ?>
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

	<?php foreach($page->IntoHtml() as $elem): ?><?=$elem?><?php endforeach ?>

		<footer class="meta">
			last modified: <span title="<?=$page->last_modified->format(DateTime::COOKIE)?>"><?=$page->last_modified->format("F j, Y")?></span>
			<br>
			<a href="?<?=$page->slug?>=hist">history</a>
			<a href="?<?=$page->slug?>=edit">edit</a>
			<a href="?<?=$page->slug?>=refs">backlinks</a>
		</footer>
	</article>
<?php }

function render_rev($page)
{ ?>
	<article style="background:honeydew;">
		<h1>
			<a href="?<?=$page->slug?>">
				<?=$page->title?>
			</a>
		</h1>

	<?php foreach($page->IntoHtml() as $elem): ?><?=$elem?><?php endforeach ?>

		<footer class="meta">
			Viewing revision from <span title="<?=$page->last_modified->format(DateTime::COOKIE)?>"><?=$page->last_modified->format("F j, Y")?></span>
			<br>
			<a href="?<?=$page->slug?>=hist">history</a>
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
