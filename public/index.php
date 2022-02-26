<?php
const DB_NAME = '../wiki.db';
const MAIN_PAGE = 'HomePage';
const RE_PAGE_TITLE = '/\b((\p{Lu}\p{Ll}+){2,})\b/u';
const RE_BEFORE_UPPER = '/(?=\p{Lu})/u';
const AS_DATE = 'Y-m-d';
const AS_TIME = 'H:i';

class State
{
	public $pdo;
	public $revision_created = false;

	public function __construct()
	{
		$this->pdo = new PDO('sqlite:./' . DB_NAME);

		if (isset($_SESSION['revision_created'])) {
			$this->revision_created = $_SESSION['revision_created'];
			unset($_SESSION['revision_created']);
		}
	}
}

class Revision
{
	public $id;
	public $prev_id;
	public $created;
	public $remote_addr;
	public $size;
	public $delta;

	private $time_created;
	private $prev_size;

	public function __construct()
	{
		$this->created = DateTime::createFromFormat('U', $this->time_created);
		$this->delta = $this->size - $this->prev_size;
	}
}

class NewPage
{
	public $slug;
	public $title;
	public $body = '';

	public function __construct($slug)
	{
		$words = preg_split(RE_BEFORE_UPPER, $slug);
		$this->title = implode(' ', $words);
		$this->slug = $slug;
	}
}

class Page extends NewPage
{
	public $rev = null;
	public $modified;
	private $time_modified;

	public function __construct()
	{
		parent::__construct($this->slug);
		$this->modified = DateTime::createFromFormat('U', $this->time_modified);
	}

	public function IntoHtml()
	{
		$inside_list = false;
		$inside_pre = false;

		foreach(explode(PHP_EOL, $this->body) as $line) {
			if (starts_with($line, '---')) {
				if ($inside_list) {
					$inside_list = false;
					yield '</ul>';
				}
				if ($inside_pre) {
					$inside_pre = false;
					yield '</pre>';
				}

				yield '<hr>';
				continue;
			}

			if (starts_with($line, '#')) {
				if ($inside_list) {
					$inside_list = false;
					yield '</ul>';
				}
				if ($inside_pre) {
					$inside_pre = false;
					yield '</pre>';
				}

				$line = substr($line, 1);
				$line = htmlentities($line);
				$line = $this->Linkify($line);
				yield '<h2>' . $line . '</h2>';
				continue;
			}

			if (starts_with($line, '*')) {
				if ($inside_pre) {
					$inside_pre = false;
					yield '</pre>';
				}
				if ($inside_list == false) {
					$inside_list = true;
					yield '<ul>';
				}

				$line = substr($line, 1);
				$line = htmlentities($line);
				$line = $this->Strongify($line);
				$line = $this->Linkify($line);
				yield '<li>' . $line . '</li>';
				continue;
			}

			if (starts_with($line, ' ')) {
				if ($inside_list) {
					$inside_list = false;
					yield '</ul>';
				}
				if ($inside_pre == false) {
					$inside_pre = true;
					yield '<pre>';
				}

				$line = substr($line, 1);
				$line = htmlentities($line);
				yield $line;
				continue;
			}

			if (starts_with($line, '>')) {
				if ($inside_list) {
					$inside_list = false;
					yield '</ul>';
				}
				if ($inside_pre) {
					$inside_pre = false;
					yield '</pre>';
				}

				$line = substr($line, 1);
				$line = htmlentities($line);
				$line = $this->Strongify($line);
				$line = $this->Linkify($line);
				yield '<blockquote>' . $line . '</blockquote>';
				continue;
			}

			$line = trim($line);
			if ($line != '') {
				if ($inside_list) {
					$inside_list = false;
					yield '</ul>';
				}
				if ($inside_pre) {
					$inside_pre = false;
					yield '</pre>';
				}

				$line = htmlentities($line);
				$line = $this->Strongify($line);
				$line = $this->Linkify($line);
				yield '<p>' . $line . '</p>';
				continue;
			}

			if ($inside_pre) {
				$inside_pre = false;
				yield '</pre>';
			}
		}

		if ($inside_list) {
			yield '</ul>';
		}
		if ($inside_pre) {
			yield '</pre>';
		}
	}

	private function Linkify($text)
	{
		return preg_replace(
				RE_PAGE_TITLE,
				"<a href=\"?$this->slug&$1\">$1</a>",
				$text);
	}

	private function Strongify($text)
	{
		return preg_replace(
				array('/\b_(.+?)_\b/', '/`(.+?)`/'),
				array('<strong>$1</strong>', '<code>$1</code>'),
				$text);
	}
}

function starts_with($string, $prefix) {
	return substr($string, 0, strlen($prefix)) == $prefix;
}

function view_read($state, $slug)
{
	$statement = $state->pdo->prepare('
		SELECT slug, body, time_modified
		FROM pages
		WHERE pages.slug = ?
	;');

	$statement->execute(array($slug));
	$statement->setFetchMode(PDO::FETCH_CLASS, 'Page');
	$page = $statement->fetch();

	if (!$page) {
		render_not_found($slug);
	} else {
		render_page($page, $state);
	}
}

function view_revision($state, $slug, $rev)
{
	$statement = $state->pdo->prepare('
		SELECT id as rev, slug, body, time_created as time_modified
		FROM revisions
		WHERE slug = ? AND id = ?
	;');

	$statement->execute(array($slug, $rev));
	$statement->setFetchMode(PDO::FETCH_CLASS, 'Page');
	$page = $statement->fetch();

	if (!$page) {
		render_not_found($slug, $rev);
	} else {
		render_revision($page);
	}
}

function view_edit($state, $slug)
{
	$statement = $state->pdo->prepare('
		SELECT slug, body, time_modified
		FROM pages
		WHERE slug = ?
	;');

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

function view_restore($state, $slug, $rev)
{
	$statement = $state->pdo->prepare('
		SELECT id as rev, slug, body, time_created as time_modified
		FROM revisions
		WHERE slug = ? AND id = ?
	;');

	$statement->execute(array($slug, $rev));
	$statement->setFetchMode(PDO::FETCH_CLASS, 'Page');
	$page = $statement->fetch();

	if (!$page) {
		render_not_found($slug, $rev);
	} else {
		render_edit($page);
	}
}

function view_history($state, $slug)
{
	$statement = $state->pdo->prepare('
		SELECT slug
		FROM pages
		WHERE slug = ?
	;');
	$statement->execute(array($slug));
	$page = $statement->fetch(PDO::FETCH_OBJ);

	if (!$page) {
		render_not_found($slug);
		return;
	}

	$statement = $state->pdo->prepare('
		SELECT
			id, time_created, remote_addr, size,
			LEAD(id, 1, 0) OVER (PARTITION BY slug ORDER BY id DESC) prev_id,
			LEAD(size, 1, 0) OVER (PARTITION BY slug ORDER BY id DESC) prev_size
		FROM changelog
		WHERE slug = ?
		ORDER BY id DESC
	;');

	$statement->execute(array($slug));
	$statement->setFetchMode(PDO::FETCH_CLASS, 'Revision');
	$revisions = $statement->fetchAll();
	render_history($slug, $revisions);
}

function view_backlinks($state, $slug)
{
	$statement = $state->pdo->prepare('
		SELECT slug
		FROM pages
		WHERE slug = ?
	;');
	$statement->execute(array($slug));
	$page = $statement->fetch(PDO::FETCH_OBJ);

	if (!$page) {
		render_not_found($slug);
		return;
	}

	$statement = $state->pdo->prepare('
		SELECT slug, body
		FROM pages
		WHERE body LIKE ?
	;');

	$statement->execute(array("%$slug%"));
	$references = $statement->fetchAll(PDO::FETCH_OBJ);
	render_backlinks($slug, $references);
}

session_start();
$state = new State();

switch (strtoupper($_SERVER['REQUEST_METHOD'])) {
case 'GET':
	if (empty($_GET)) {
		header('Location: ?' . MAIN_PAGE, true, 303);
		exit;
	}

	render_head();
	foreach ($_GET as $slug => $action) {
		if (!filter_var($slug, FILTER_VALIDATE_REGEXP, ['options' => ['regexp' => RE_PAGE_TITLE]])) {
			render_not_valid($slug);
			continue;
		}

		if (is_array($action)) {
			$revs = $action;
			foreach ($revs as $rev => $action) {
				if (!filter_var($rev, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])) {
					render_not_valid($slug, $rev);
					continue;
				}

				switch ($action) {
				case 'edit':
					view_restore($state, $slug, $rev);
					break;
				case 'read':
				default:
					view_revision($state, $slug, $rev);
				}
			}
		} else {
			switch ($action) {
			case 'edit':
				view_edit($state, $slug);
				break;
			case 'history':
				view_history($state, $slug);
				break;
			case 'backlinks':
				view_backlinks($state, $slug);
				break;
			case 'read':
			default:
				view_read($state, $slug);
			}
		}
	}
	render_end();
	break;
case 'POST':
	$honeypot = filter_input(INPUT_POST, 'user');
	if ($honeypot) {
		header($_SERVER['SERVER_PROTOCOL'] . ' 405 Method Not Allowed', true, 405);
		exit();
	}

	$body = filter_input(INPUT_POST, 'body');
	$time = date('U');
	$addr = $_SERVER['REMOTE_ADDR'];
	$slug = filter_input(INPUT_POST, 'slug');
	if (!filter_var($slug, FILTER_VALIDATE_REGEXP, ['options' => ['regexp' => RE_PAGE_TITLE]])) {
		header($_SERVER['SERVER_PROTOCOL'] . ' 400 Bad Request', true, 400);
		exit();
	}

	$statement = $state->pdo->prepare('
		INSERT INTO pages (slug, body, time_modified, remote_addr)
		VALUES (:slug, :body, :time, :addr)
		ON CONFLICT (slug) DO UPDATE
		SET
			body = :body_up,
			time_modified = :time_up,
			remote_addr = :addr_up
	;');

	$statement->bindParam('slug', $slug, PDO::PARAM_STR);
	$statement->bindParam('body', $body, PDO::PARAM_STR);
	$statement->bindParam('time', $time, PDO::PARAM_INT);
	$statement->bindParam('addr', $addr, PDO::PARAM_STR);
	$statement->bindParam('body_up', $body, PDO::PARAM_STR);
	$statement->bindParam('time_up', $time, PDO::PARAM_INT);
	$statement->bindParam('addr_up', $addr, PDO::PARAM_STR);

	if ($statement->execute()) {
		$_SESSION['revision_created'] = true;
		header("Location: ?$slug", true, 303);
		exit;
	}

	die("Unable to create a new revision of $slug.");
}

// Rendering templates

function render_head()
{
	$panels = array();
	foreach ($_GET as $slug => $action) {
		if (is_array($action)) {
			$revs = implode(',', array_keys($action));
			$panels[] = "{$slug}[$revs]";
		} else if ($action) {
			$panels[] = "$slug=$action";
		} else {
			$panels[] = $slug;
		}
	}
?>
	<!doctype html>
	<title><?=htmlentities(implode(' & ', $panels))?></title>
	<head>
		<meta charset="utf-8">
		<meta name="viewport" content="width=device-width, initial-scale=1">
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
				max-height: calc(100vh - 16px);
				overflow-y: auto;
				padding: 15px;
			}

			article h2 {
				font-size: 1.2rem;
			}

			article ul {
				padding-left: 1rem;
			}

			article blockquote {
				margin-inline-start: 1rem;
				font-style: italic;
			}

			article pre {
				white-space: pre-wrap;
				padding: 3px 1px;
				background: whitesmoke;
			}

			article code {
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
				box-sizing: border-box;
				width: 100%;
				height: 300px;
				font-family: serif;
				font-size: 100%;
			}
		</style>
	</head>
	<body onload="window.scrollBy({left: window.innerWidth, behavior: 'smooth'})">
<?php }

function render_end()
{ ?>
	</body>
<?php }

function render_not_valid($slug, $rev = null)
{ ?>
	<article class="meta" style="background:mistyrose">
	<?php if ($rev !== null): ?>
		<h1>Invalid Revision</h1>
		<p><?=htmlentities($slug)?>[<?=htmlentities($rev)?>] is not a valid revision.</p>
	<?php else: ?>
		<h1>Invalid Page Name </h1>
		<p><?=htmlentities($slug)?> is not a valid page name.</p>
	<?php endif ?>
		<footer class="meta">
			<a href="?">home</a>
		</footer>
	</article>
<?php }

function render_not_found($slug, $rev = null)
{ ?>
	<article class="meta" style="background:mistyrose">
	<?php if ($rev): ?>
		<h1>Revision Not Found</h1>
		<p><?=$slug?>[<?=$rev?>] doesn't exist.</p>
	<?php else: ?>
		<h1>Page Not Found</h1>
		<p><?=$slug?> doesn't exist yet. <a href="?<?=$slug?>=edit">Create?</a></p>

		<footer class="meta">
			<a href="?">home</a>
		</footer>
	<?php endif ?>
	</article>
<?php }

function render_page($page, $state)
{ ?>
	<article>
	<?php if ($state->revision_created): ?>
		<header class="meta" style="background:cornsilk">
			Page updated successfully.
		</header>
	<?php endif ?>

		<h1>
			<a href="?<?=$page->slug?>">
				<?=$page->title?>
			</a>
		</h1>

	<?php foreach($page->IntoHtml() as $elem): ?><?=$elem?><?php endforeach ?>

		<footer class="meta">
			last modified on <?=$page->modified->format(AS_DATE)?><br>
			<a href="?">home</a>
			<a href="?<?=$page->slug?>=backlinks">backlinks</a>
			<a href="?<?=$page->slug?>=history">history</a>
			<a href="?<?=$page->slug?>=edit">edit</a>
		</footer>
	</article>
<?php }

function render_revision($page)
{ ?>
	<article style="background:honeydew">
		<h1>
			<a href="?<?=$page->slug?>">
				<?=$page->title?>
			</a> <small>[<?=$page->rev?>]</small>
		</h1>

	<?php foreach($page->IntoHtml() as $elem): ?><?=$elem?><?php endforeach ?>

		<footer class="meta">
			revision <?=$page->rev?> from <?=$page->modified->format(AS_DATE)?>
			<a href="?<?=$page->slug?>[<?=$page->rev?>]=edit">restore?</a>
			<br>
			<a href="?">home</a>
			<a href="?<?=$page->slug?>=backlinks">backlinks</a>
			<a href="?<?=$page->slug?>=history">history</a>
			<a href="?<?=$page->slug?>">latest</a>
		</footer>

	</article>
<?php }

function render_edit($page)
{ ?>
	<article style="background:cornsilk">
		<h1 class="meta">
		<? if ($page->rev): ?>
			Restore <a href="?<?=$page->slug?>">
				<?=$page->title?>
			</a> <small>[<?=$page->rev?>]</small>
		<? else: ?>
			Edit <a href="?<?=$page->slug?>">
				<?=$page->title?>
			</a>
		<? endif ?>
		</h1>

		<form method="post" action="?">
			<input type="hidden" name="slug" value="<?=$page->slug?>">
			<textarea name="body" placeholder="Type here..."><?=$page->body?></textarea>
			<input type="text" name="user" placeholder="Leave this empty.">
			<button type="submit">Save</button>
		</form>
	</article>
<?php }

function render_history($slug, $revisions)
{ ?>
	<article style="background:aliceblue">
		<h1 class="meta">
			Revision history for <a href="?<?=$slug?>"><?=$slug?></a>
		</h1>

		<ul>
		<?php foreach ($revisions as $revision): ?>
			<li>
				<a href="?<?=$slug?>[<?=$revision->id?>]">
					[<?=$revision->id?>]
				</a>
				on <?=$revision->created->format(AS_DATE)?>
				at <?=$revision->created->format(AS_TIME)?>
				from <?=$revision->remote_addr?>
				(<a href="?<?=$slug?>[<?=$revision->prev_id?>]&<?=$slug?>[<?=$revision->id?>]"><?=sprintf("%+d", $revision->delta)?> chars</a>)
			</li>
		<?php endforeach ?>
		</ul>
	</article>
<?php }

function render_backlinks($slug, $references)
{ ?>
	<article style="background:aliceblue">
		<h1 class="meta">
			What links to <a href="?<?=$slug?>"><?=$slug?></a>?
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
