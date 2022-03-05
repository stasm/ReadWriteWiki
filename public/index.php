<?php
const DB_NAME = '../wiki.db';
const MAIN_PAGE = 'HomePage';
const RE_PAGE_TITLE = '/\b((\p{Lu}\p{Ll}+){2,})\b/u';
const RE_PAGE_LINK = '/\b((\p{Lu}\p{Ll}+){2,}(=[a-z]+)?)\b/u';
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

class Change
{
	public $id;
	public $prev_id;
	public $date_created;
	public $remote_ip;
	public $size;
	public $delta;

	private $time_created;
	private $remote_addr;
	private $prev_size;

	public function __construct()
	{
		$this->date_created = DateTime::createFromFormat('U', $this->time_created);
		$this->delta = $this->size - $this->prev_size;
		$this->remote_ip = inet_ntop($this->remote_addr);
	}
}

class Revision
{
	public $id;
	public $slug;
	public $title;
	public $body;
	public $date_created;

	private $time_created;

	public function __construct($slug = null)
	{
		if ($this->time_created) {
			$this->date_created = DateTime::createFromFormat('U', $this->time_created);
		}

		if ($slug) {
			$this->slug = $slug;
		}

		$words = preg_split(RE_BEFORE_UPPER, $this->slug);
		$this->title = implode(' ', $words);
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

				$heading = ltrim($line, '- ');
				if ($heading) {
					$heading = htmlentities($heading);
					$heading = $this->Linkify($heading);
					yield '<h2>' . $heading . '</h2>';
				}

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
				RE_PAGE_LINK,
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

function view_read($state, $slug, $mode)
{
	$statement = $state->pdo->prepare('
		SELECT id, slug, body, time_created
		FROM latest
		WHERE slug = ?
	;');

	$statement->execute(array($slug));
	$statement->setFetchMode(PDO::FETCH_CLASS, 'Revision');
	$page = $statement->fetch();

	if (!$page) {
		render_not_found($slug);
		return;
	}

	switch ($mode) {
	case 'text':
		render_page_text($page, $state);
		break;
	case 'html':
		render_page_html($page, $state);
		break;
	}
}

function view_revision($state, $slug, $id)
{
	$statement = $state->pdo->prepare('
		SELECT id, slug, body, time_created
		FROM revisions
		WHERE slug = ? AND id = ?
	;');

	$statement->execute(array($slug, $id));
	$statement->setFetchMode(PDO::FETCH_CLASS, 'Revision');
	$page = $statement->fetch();

	if (!$page) {
		render_not_found($slug, $id);
	} else {
		render_revision($page);
	}
}

function view_edit($state, $slug)
{
	$statement = $state->pdo->prepare('
		SELECT slug, body, time_created
		FROM latest
		WHERE slug = ?
	;');

	$statement->execute(array($slug));
	$statement->setFetchMode(PDO::FETCH_CLASS, 'Revision');
	$page = $statement->fetch();

	if (!$page) {
		$page = new Revision($slug);
	}

	render_edit($page);
}

function view_restore($state, $slug, $id)
{
	$statement = $state->pdo->prepare('
		SELECT id, slug, body, time_created
		FROM revisions
		WHERE slug = ? AND id = ?
	;');

	$statement->execute(array($slug, $id));
	$statement->setFetchMode(PDO::FETCH_CLASS, 'Revision');
	$page = $statement->fetch();

	if (!$page) {
		render_not_found($slug, $id);
	} else {
		render_edit($page);
	}
}

function view_history($state, $slug)
{
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
	$statement->setFetchMode(PDO::FETCH_CLASS, 'Change');
	$changes = $statement->fetchAll();

	if (!$changes) {
		render_not_found($slug);
	} else {
		render_history($slug, $changes);
	}
}

function view_backlinks($state, $slug)
{
	$statement = $state->pdo->prepare('
		SELECT slug, body, time_created
		FROM latest
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

	$mode = 'html';
	ob_start();

	foreach ($_GET as $slug => $action) {
		if (!filter_var($slug, FILTER_VALIDATE_REGEXP, ['options' => ['regexp' => RE_PAGE_TITLE]])) {
			render_not_valid($slug);
			continue;
		}

		if (is_array($action)) {
			$ids = $action;
			foreach ($ids as $id => $action) {
				if (!filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])) {
					render_not_valid($slug, $id);
					continue;
				}

				switch ($action) {
				case 'edit':
					view_restore($state, $slug, $id);
					break;
				case 'html':
				default:
					view_revision($state, $slug, $id);
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
			case 'html':
			case 'text':
				$mode = $action;
				view_read($state, $slug, $action);
				break;
			default:
				view_read($state, $slug, 'html');
			}
		}
	}

	switch ($mode) {
	case 'text':
		header('Content-Type: text/plain;');
		ob_end_flush();
		break;
	case 'html':
		render_head_html();
		ob_end_flush();
		render_end_html();
		break;
	}

	break;
case 'POST':
	$honeypot = filter_input(INPUT_POST, 'user');
	if ($honeypot) {
		header($_SERVER['SERVER_PROTOCOL'] . ' 405 Method Not Allowed', true, 405);
		exit();
	}

	$body = filter_input(INPUT_POST, 'body');
	$time = date('U');
	$addr = inet_pton($_SERVER['REMOTE_ADDR']);
	$slug = filter_input(INPUT_POST, 'slug');
	if (!filter_var($slug, FILTER_VALIDATE_REGEXP, ['options' => ['regexp' => RE_PAGE_TITLE]])) {
		header($_SERVER['SERVER_PROTOCOL'] . ' 400 Bad Request', true, 400);
		exit();
	}

	$statement = $state->pdo->prepare('
		INSERT INTO revisions (slug, body, time_created, remote_addr)
		VALUES (:slug, :body, :time, :addr)
	;');

	$statement->bindParam('slug', $slug, PDO::PARAM_STR);
	$statement->bindParam('body', $body, PDO::PARAM_STR);
	$statement->bindParam('time', $time, PDO::PARAM_INT);
	$statement->bindParam('addr', $addr, PDO::PARAM_STR);

	if ($statement->execute()) {
		$_SESSION['revision_created'] = true;
		header("Location: ?$slug", true, 303);
		exit;
	}

	die("Unable to create a new revision of $slug.");
}

// Rendering templates

function render_head_html()
{
	$panels = array();
	foreach ($_GET as $slug => $action) {
		if (is_array($action)) {
			$ids = implode(',', array_keys($action));
			$panels[] = "{$slug}[$ids]";
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

function render_end_html()
{ ?>
	</body>
<?php }

function render_not_valid($slug, $id = null)
{ ?>
	<article class="meta" style="background:mistyrose">
	<?php if ($id !== null): ?>
		<h1>Invalid Revision</h1>
		<p><?=htmlentities($slug)?>[<?=htmlentities($id)?>] is not a valid revision.</p>
	<?php else: ?>
		<h1>Invalid Page Name </h1>
		<p><?=htmlentities($slug)?> is not a valid page name.</p>
	<?php endif ?>
		<footer class="meta">
			<a href="?">home</a>
		</footer>
	</article>
<?php }

function render_not_found($slug, $id = null)
{ ?>
	<article class="meta" style="background:mistyrose">
	<?php if ($id): ?>
		<h1>Revision Not Found</h1>
		<p><?=$slug?>[<?=$id?>] doesn't exist.</p>
	<?php else: ?>
		<h1>Page Not Found</h1>
		<p><?=$slug?> doesn't exist yet. <a href="?<?=$slug?>=edit">Create?</a></p>

		<footer class="meta">
			<a href="?">home</a>
			<a href="?<?=$slug?>=backlinks">backlinks</a>
		</footer>
	<?php endif ?>
	</article>
<?php }

function render_page_html($page, $state)
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
			last modified on <?=$page->date_created->format(AS_DATE)?><br>
			<a href="?">home</a>
			<a href="?<?=$page->slug?>=backlinks">backlinks</a>
			<a href="?<?=$page->slug?>=history">history</a>
			<a href="?<?=$page->slug?>=edit">edit</a>
		</footer>
	</article>
<?php }

function render_page_text($page, $state)
{
	echo "===$page->title [{$page->date_created->format(AS_DATE)}]

$page->body

";
}

function render_revision($page)
{ ?>
	<article style="background:honeydew">
		<h1>
			<a href="?<?=$page->slug?>">
				<?=$page->title?>
			</a> <small>[<?=$page->id?>]</small>
		</h1>

	<?php foreach($page->IntoHtml() as $elem): ?><?=$elem?><?php endforeach ?>

		<footer class="meta">
			revision <?=$page->id?> from <?=$page->date_created->format(AS_DATE)?>
			<a href="?<?=$page->slug?>[<?=$page->id?>]=edit">restore?</a>
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
		<?php if ($page->id): ?>
			Restore <a href="?<?=$page->slug?>">
				<?=$page->title?>
			</a> <small>[<?=$page->id?>]</small>
		<?php else: ?>
			Edit <a href="?<?=$page->slug?>">
				<?=$page->title?>
			</a>
		<?php endif ?>
		</h1>

		<form method="post" action="?">
			<input type="hidden" name="slug" value="<?=$page->slug?>">
			<textarea name="body" placeholder="Type here..."><?=$page->body?></textarea>
			<input type="text" name="user" placeholder="Leave this empty.">
			<button type="submit">Save</button>
		</form>
	</article>
<?php }

function render_history($slug, $changes)
{ ?>
	<article style="background:aliceblue">
		<h1 class="meta">
			Revision history for <a href="?<?=$slug?>"><?=$slug?></a>
		</h1>

		<ul>
		<?php foreach ($changes as $change): ?>
			<li>
				<a href="?<?=$slug?>[<?=$change->id?>]">
					[<?=$change->id?>]
				</a>
				on <?=$change->date_created->format(AS_DATE)?>
				at <?=$change->date_created->format(AS_TIME)?>
				from <?=$change->remote_ip?>
				(<a href="?<?=$slug?>[<?=$change->prev_id?>]&<?=$slug?>[<?=$change->id?>]"><?=sprintf("%+d", $change->delta)?> chars</a>)
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
