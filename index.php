<?php
const DB_NAME = 'wiki.db';
const MAIN_PAGE = 'HomePage';
const HELP_PAGE = 'WikiHelp';
const RECENT_CHANGES = 'RecentChanges';

const AS_DATE = 'Y-m-d';
const AS_TIME = 'H:i';

const RE_PAGE_SLUG = '/\b(?<slug>\p{Lu}\p{Ll}+(?:\p{Lu}\p{Ll}+|\d+)+)\b/u';
const RE_PAGE_LINK = '/(?:\[(?<title>.+?)\])?\b(?<slug>\p{Lu}\p{Ll}+(?:\p{Lu}\p{Ll}+|\d+)+)(?:=(?<action>[a-z]+)\b)?/u';
const RE_WORD_BOUNDARY = '/((?<=\p{Ll}|\d)(?=\p{Lu})|(?<=\p{Ll})(?=\d))/u';

class State
{
	public $pdo;
	public $revision_created = false;
	public $content_type = 'html';
	public $nav_trail = array();

	public function __construct()
	{
		$this->pdo = new PDO('sqlite:./' . DB_NAME);

		if (isset($_SESSION['revision_created'])) {
			$this->revision_created = $_SESSION['revision_created'];
			unset($_SESSION['revision_created']);
		}
	}

	public function __invoke($buffer)
	{
		switch ($this->content_type) {
		case 'text':
			header('Content-Type: text/plain;');
			return $buffer;
		case 'html':
			return wrap_html($buffer);
		}
	}

	public function PageExists($slug)
	{
		if ($slug === RECENT_CHANGES) {
			return true;
		}

		$statement = $this->pdo->prepare('
			SELECT 1 FROM latest WHERE slug = ?
		;');

		$statement->execute(array($slug));
		return (bool)$statement->fetch();
	}
}

class Change
{
	public $id;
	public $prev_id;
	public $slug;
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

	private $state;
	private $time_created;

	public function __construct($slug = null)
	{
		if ($this->time_created) {
			$this->date_created = DateTime::createFromFormat('U', $this->time_created);
		}

		if ($slug) {
			$this->slug = $slug;
		}

		$words = preg_split(RE_WORD_BOUNDARY, $this->slug);
		$this->title = implode(' ', $words);
	}

	public function Anchor($state)
	{
		$this->state = $state;
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
					$heading = htmlspecialchars($heading);
					$heading = $this->Linkify($heading);
					yield '<h2>' . $heading . '</h2>';
				}

				continue;
			}

			if (starts_with($line, '* ')) {
				if ($inside_pre) {
					$inside_pre = false;
					yield '</pre>';
				}
				if ($inside_list == false) {
					$inside_list = true;
					yield '<ul>';
				}

				$line = substr($line, 1);
				$line = htmlspecialchars($line);
				$line = $this->Inline($line);
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
				$line = htmlspecialchars($line);
				yield $line;
				continue;
			}

			if (starts_with($line, '> ')) {
				if ($inside_list) {
					$inside_list = false;
					yield '</ul>';
				}
				if ($inside_pre) {
					$inside_pre = false;
					yield '</pre>';
				}

				$line = substr($line, 1);
				$line = htmlspecialchars($line);
				$line = $this->Inline($line);
				$line = $this->Linkify($line);
				yield '<blockquote>' . $line . '</blockquote>';
				continue;
			}

			$line = trim($line);

			if (preg_match('#^https?://.+\.(jpg|jpeg|png|gif|webp)$#', $line)) {
				if ($inside_pre) {
					$inside_pre = false;
					yield '</pre>';
				}
				if ($inside_list) {
					$inside_list = false;
					yield '</ul>';
				}
				yield "<figure><img src=\"$line\"/></figure>";
				continue;
			}

			if (preg_match('#^https?://[^ ]+$#', $line)) {
				if ($inside_pre) {
					$inside_pre = false;
					yield '</pre>';
				}
				if ($inside_list) {
					$inside_list = false;
					yield '</ul>';
				}
				yield "<figure><a href=\"$line\">$line</a></figure>";
				continue;
			}

			if ($line != '') {
				if ($inside_list) {
					$inside_list = false;
					yield '</ul>';
				}
				if ($inside_pre) {
					$inside_pre = false;
					yield '</pre>';
				}

				$line = htmlspecialchars($line);
				$line = $this->Inline($line);
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
		$state = $this->state;
		$trail = $this->state->nav_trail;
		return preg_replace_callback(
				RE_PAGE_LINK,
				function($matches) use(&$state, $trail) {
					$slug = $matches["slug"];
					$missing = $state->PageExists($slug) ? '' : 'data-missing';

					$index = array_search($slug, $trail);
					if ($index !== false) {
						// The page is already in the navigation trail.
						// Truncate the trail up to it.
						$trail = array_slice($trail, 0, $index);
					}
					$trail[] = $slug;
					$breadcrumbs = implode('&', $trail);

					if ($action = $matches["action"]) {
						$breadcrumbs .= "=$action";
						$slug .= "=$action";
					}

					if ($title = $matches["title"]) {
						$slug = $title;
					}

					return "<a $missing href=\"?$breadcrumbs\">$slug</a>";
				},
				$text,
				-1, $count, PREG_UNMATCHED_AS_NULL);
	}

	private function Inline($text)
	{
		return preg_replace(
				array('/\*([^ ](.*?[^ ])?)\*/', '/&quot;(.+?)&quot;/', '/`(.+?)`/'),
				array('<strong>$1</strong>', '<em>$1</em>', '<code>$1</code>'),
				$text);
	}
}

function starts_with($string, $prefix) {
	return substr($string, 0, strlen($prefix)) == $prefix;
}

function view_revision($state, $slug, $id, $mode)
{
	$statement = $state->pdo->prepare($id ? '
		SELECT id, slug, body, time_created
		FROM revisions
		WHERE slug = ? AND id = ?
	;' : '
		SELECT id, slug, body, time_created
		FROM latest
		WHERE slug = ?
	;');

	$statement->execute($id ? array($slug, $id) : array($slug));
	$statement->setFetchMode(PDO::FETCH_CLASS, 'Revision');
	$page = $statement->fetch();

	if (!$page) {
		render_not_found($slug, $id);
		return;
	}

	$page->Anchor($state);

	if ($mode === 'text') {
		render_source($page);
	} elseif ($id === null) {
		render_latest($page, $state);
	} else {
		render_revision($page);
	}
}

function view_edit($state, $slug, $id)
{
	$statement = $state->pdo->prepare($id ? '
		SELECT id, slug, body, time_created
		FROM revisions
		WHERE slug = ? AND id = ?
	;' : '
		SELECT slug, body, time_created
		FROM latest
		WHERE slug = ?
	;');

	$statement->execute($id ? array($slug, $id) : array($slug));
	$statement->setFetchMode(PDO::FETCH_CLASS, 'Revision');
	$page = $statement->fetch();

	if (!$page) {
		if ($id !== null) {
			render_not_found($slug, $id);
			return;
		}

		$page = new Revision($slug);
	}

	render_edit($page);
}

function view_history($state, $slug, $id)
{
	$statement = $state->pdo->prepare('
		SELECT
			id, time_created, remote_addr, size,
			LEAD(id, 1, 0) OVER (PARTITION BY slug ORDER BY id DESC) prev_id,
			LEAD(size, 1, 0) OVER (PARTITION BY slug ORDER BY id DESC) prev_size
		FROM changelog
		WHERE slug = ?' . ($id ? ' AND id <= ?' : '') . '
		ORDER BY id DESC
	;');

	$statement->execute($id ? array($slug, $id) : array($slug));
	$statement->setFetchMode(PDO::FETCH_CLASS, 'Change');
	$changes = $statement->fetchAll();

	if (!$changes) {
		render_not_found($slug);
	} else {
		render_history($slug, $changes);
	}
}

function view_backlinks($state, $slug, $id)
{
	$statement = $state->pdo->prepare($id ? '
		SELECT DISTINCT slug
		FROM revisions
		WHERE slug != ? AND body LIKE ? AND id <= ?
	;' : '
		SELECT slug
		FROM latest
		WHERE slug != ? AND body LIKE ?
	;');

	$statement->execute($id ? array($slug, "%$slug%", $id) : array($slug, "%$slug%"));
	$references = $statement->fetchAll(PDO::FETCH_OBJ);
	render_backlinks($slug, $references);
}

function view_recent_changes($state, $p = 0)
{
	if ($p > 0) {
		render_not_valid(RECENT_CHANGES, null, $p);
		return;
	}

	$limit = 25;
	$statement = $state->pdo->prepare('
		SELECT
			id, slug, time_created, remote_addr, size,
			LEAD(id, 1, 0) OVER (PARTITION BY slug ORDER BY id DESC) prev_id,
			LEAD(size, 1, 0) OVER (PARTITION BY slug ORDER BY id DESC) prev_size
		FROM changelog
		ORDER BY id DESC
		LIMIT ? OFFSET ?
	;');

	$statement->execute(array($limit, $limit * $p * -1));
	$statement->setFetchMode(PDO::FETCH_CLASS, 'Change');
	$changes = $statement->fetchAll();
	render_recent_changes($p, $changes);
}

function view_recent_changes_from($state, $remote_ip, $p = 0)
{
	if ($p > 0) {
		render_not_valid(RECENT_CHANGES, null, $p);
		return;
	}

	$limit = 25;
	$statement = $state->pdo->prepare('
		WITH recent_changes AS (
			SELECT
				id, slug, time_created, remote_addr, size,
				LEAD(id, 1, 0) OVER (PARTITION BY slug ORDER BY id DESC) prev_id,
				LEAD(size, 1, 0) OVER (PARTITION BY slug ORDER BY id DESC) prev_size
			FROM changelog
		)
		SELECT *
		FROM recent_changes
		WHERE remote_addr = ?
		ORDER BY id DESC
		LIMIT ? OFFSET ?
	;');

	$statement->execute(array(inet_pton($remote_ip), $limit, $limit * $p * -1));
	$statement->setFetchMode(PDO::FETCH_CLASS, 'Change');
	$changes = $statement->fetchAll();
	render_recent_changes_from($remote_ip, $p, $changes);
}

session_start();
$state = new State();

switch (strtoupper($_SERVER['REQUEST_METHOD'])) {
case 'GET':
	if (empty($_GET)) {
		header('Location: ?' . MAIN_PAGE, true, 303);
		exit;
	}

	ob_start($state);
	$panes = array();

	// Normalize query parameters into (slug, id, action) tuples.
	foreach ($_GET as $slug => $action) {
		if (is_array($action)) {
			$ids = $action;
			foreach ($ids as $id => $action) {
				$panes[] = ['slug' => $slug, 'id' => $id, 'action' => $action];
			}
		} else {
			$panes[] = ['slug' => $slug, 'id' => null, 'action' => $action];
		}
	}

	foreach ($panes as ['slug' => $slug, 'id' => $id, 'action' => $action]) {
		if (!filter_var($slug, FILTER_VALIDATE_REGEXP, ['options' => ['regexp' => RE_PAGE_SLUG]])) {
			render_not_valid($slug);
			continue;
		}

		if ($id === null) {
			if (!in_array($slug, $state->nav_trail)) {
				$state->nav_trail[] = $slug;
			}
		} elseif (!filter_var($id, FILTER_VALIDATE_INT)) {
			render_not_valid($slug, $id);
			continue;
		} else {
			$slugid = "${slug}[$id]";
			if (!in_array($slugid, $state->nav_trail)) {
				$state->nav_trail[] = $slugid;
			}
		}

		switch ($slug) {
		case RECENT_CHANGES:
			$remote_ip = $action;
			if ($remote_ip == null) {
				view_recent_changes($state, $id);
			} elseif (filter_var($remote_ip, FILTER_VALIDATE_IP)) {
				view_recent_changes_from($state, $remote_ip, $id);
			} else {
				render_not_valid($slug, null, null, $remote_ip);
			}
			return;
		}

		switch ($action) {
		case 'edit':
			view_edit($state, $slug, $id);
			break;
		case 'history':
			view_history($state, $slug, $id);
			break;
		case 'backlinks':
			view_backlinks($state, $slug, $id);
			break;
		case 'html':
		case 'text':
			$state->content_type = $action;
			view_revision($state, $slug, $id, $action);
			break;
		default:
			view_revision($state, $slug, $id, 'html');
		}
	}

	ob_end_flush();
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
	if (!filter_var($slug, FILTER_VALIDATE_REGEXP, ['options' => ['regexp' => RE_PAGE_SLUG]])) {
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

function wrap_html($buffer)
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

	$title = htmlspecialchars(implode(' & ', $panels));
	return <<<EOF
<!doctype html>
<html>
	<title>$title</title>
	<head>
		<meta charset="utf-8">
		<meta name="viewport" content="width=device-width, initial-scale=1">
		<style>
			body {
				display: inline-flex;
				align-items: flex-start;
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
				overflow-wrap: break-word;
				padding: 15px;
			}

			article a {
				text-decoration: none;
			}

			article a[data-missing] {
				color: firebrick;
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

			article figure:has(> img) {
				margin: 0;
			}

			article figure:has(> a) {
				margin: 1rem;
			}

			article figure a {
				text-decoration: underline;
				word-break: break-all;
			}

			article figure img {
				display: block;
				width: 100%;
				object-fit: cover;
			}

			article pre {
				white-space: pre-wrap;
				padding: 3px 1px;
				background: whitesmoke;
			}

			article code {
				background: whitesmoke;
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
	<body onload="window.scrollTo(document.body.scrollWidth, 0);">
$buffer
	</body>
</html>
EOF;
}

function render_not_valid($slug, $id = null, $p = null, $ip = null)
{ ?>
	<article class="meta" style="background:mistyrose">
	<?php if ($id !== null): ?>
		<h1>Invalid Revision</h1>
		<p><?=htmlspecialchars($slug)?>[<?=htmlspecialchars($id)?>] is not a valid revision.</p>
	<?php elseif ($p !== null): ?>
		<h1>Invalid Range</h1>
		<p><?=htmlspecialchars($slug)?>[<?=htmlspecialchars($p)?>] is not a valid range offset.</p>
	<?php elseif ($ip !== null): ?>
		<h1>Invalid Address</h1>
		<p><?=htmlspecialchars($ip)?> is not a valid IP address.</p>
	<?php else: ?>
		<h1>Invalid Page Name </h1>
		<p><?=htmlspecialchars($slug)?> is not a valid page name.</p>
	<?php endif ?>
		<footer class="meta">
			<a href="?">home</a>
			<a href="?<?=HELP_PAGE?>">help</a>
			<a href="?<?=RECENT_CHANGES?>">recent</a>
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
	<?php endif ?>

		<footer class="meta">
			<a href="?<?=$slug?>=backlinks">backlinks</a>
			<br>
			<a href="?">home</a>
			<a href="?<?=HELP_PAGE?>">help</a>
			<a href="?<?=RECENT_CHANGES?>">recent</a>
		</footer>
	</article>
<?php }

function render_source($page)
{
	echo "=== $page->title [$page->id] {$page->date_created->format(AS_DATE)}

$page->body

";
}

function render_latest($page, $state)
{
	$breadcrumbs = implode('&', $state->nav_trail);
?>
	<article>
	<?php if ($state->revision_created): ?>
		<header class="meta" style="background:cornsilk">
			Page updated successfully.
		</header>
	<?php endif ?>

		<h1>
			<a href="?<?=$breadcrumbs?>">
				<?=$page->title?>
			</a>
		</h1>

	<?php foreach($page->IntoHtml() as $elem): ?><?=$elem?><?php endforeach ?>

		<footer class="meta">
			last modified on <span title="<?=$page->date_created->format(AS_TIME)?>">
				<?=$page->date_created->format(AS_DATE)?>
			</span>
			<a href="?<?=$page->slug?>=edit">edit</a>
			<br>
			<a href="?<?=$page->slug?>">html</a>
			<a href="?<?=$page->slug?>=text">text</a>
			<a href="?<?=$page->slug?>=backlinks">backlinks</a>
			<a href="?<?=$page->slug?>=history">history</a>
			<br>
			<a href="?">home</a>
			<a href="?<?=HELP_PAGE?>">help</a>
			<a href="?<?=RECENT_CHANGES?>">recent</a>
		</footer>
	</article>
<?php }

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
			revision <?=$page->id?> from <span title="<?=$page->date_created->format(AS_TIME)?>">
				<?=$page->date_created->format(AS_DATE)?>
			</span>
			<a href="?<?=$page->slug?>[<?=$page->id?>]=edit">restore</a>
			<br>
			<a href="?<?=$page->slug?>[<?=$page->id?>]">html</a>
			<a href="?<?=$page->slug?>[<?=$page->id?>]=text">text</a>
			<a href="?<?=$page->slug?>[<?=$page->id?>]=backlinks">backlinks</a>
			<a href="?<?=$page->slug?>[<?=$page->id?>]=history">history</a>
			<a href="?<?=$page->slug?>">latest</a>
			<br>
			<a href="?">home</a>
			<a href="?<?=HELP_PAGE?>">help</a>
			<a href="?<?=RECENT_CHANGES?>">recent</a>
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

		<footer class="meta">
			<a href="?">home</a>
			<a href="?<?=HELP_PAGE?>">help</a>
			<a href="?<?=RECENT_CHANGES?>">recent</a>
		</footer>
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
				(<a href="?<?=$slug?>[<?=$change->prev_id?>]&<?=$slug?>[<?=$change->id?>]"><?=sprintf("%+d", $change->delta)?> chars</a>)
				on <?=$change->date_created->format(AS_DATE)?>
				at <?=$change->date_created->format(AS_TIME)?>
				from <a href="?<?=RECENT_CHANGES?>=<?=$change->remote_ip?>"><?=$change->remote_ip?></a>
			</li>
		<?php endforeach ?>
		</ul>

		<footer class="meta">
			<a href="?<?=$slug?>">html</a>
			<a href="?<?=$slug?>=text">text</a>
			<a href="?<?=$slug?>=backlinks">backlinks</a>
			<a href="?<?=$slug?>=history">history</a>
			<br>
			<a href="?">home</a>
			<a href="?<?=HELP_PAGE?>">help</a>
			<a href="?<?=RECENT_CHANGES?>">recent</a>
		</footer>
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

		<footer class="meta">
			<a href="?<?=$slug?>">html</a>
			<a href="?<?=$slug?>=text">text</a>
			<a href="?<?=$slug?>=backlinks">backlinks</a>
			<a href="?<?=$slug?>=history">history</a>
			<br>
			<a href="?">home</a>
			<a href="?<?=HELP_PAGE?>">help</a>
			<a href="?<?=RECENT_CHANGES?>">recent</a>
		</footer>
	</article>
<?php }

function render_recent_changes($p, $changes)
{
	$next = $p - 1;
?>
	<article style="background:aliceblue">
		<h1 class="meta">
			Recent Changes
		</h1>

		<ul>
		<?php foreach ($changes as $change): ?>
			<li>
				<a href="?<?=$change->slug?>[<?=$change->id?>]">
					<?=$change->slug?>[<?=$change->id?>]
				</a>
				(<a href="?<?=$change->slug?>[<?=$change->prev_id?>]&<?=$change->slug?>[<?=$change->id?>]"><?=sprintf("%+d", $change->delta)?> chars</a>)
				on <?=$change->date_created->format(AS_DATE)?>
				at <?=$change->date_created->format(AS_TIME)?>
				from <a href="?<?=RECENT_CHANGES?>=<?=$change->remote_ip?>"><?=$change->remote_ip?></a>
			</li>
		<?php endforeach ?>
		</ul>

		<p>
			<a href="?<?=RECENT_CHANGES?>[<?=$next?>]">next</a>
		</p>

		<footer class="meta">
			<a href="?">home</a>
			<a href="?<?=HELP_PAGE?>">help</a>
			<a href="?<?=RECENT_CHANGES?>">recent</a>
		</footer>
	</article>
<?php }

function render_recent_changes_from($remote_ip, $p, $changes)
{
	$next = $p - 1;
?>
	<article style="background:aliceblue">
		<h1 class="meta">
			Recent Changes from <?=htmlspecialchars($remote_ip)?>
		</h1>

		<ul>
		<?php foreach ($changes as $change): ?>
			<li>
				<a href="?<?=$change->slug?>[<?=$change->id?>]">
					<?=$change->slug?>[<?=$change->id?>]
				</a>
				(<a href="?<?=$change->slug?>[<?=$change->prev_id?>]&<?=$change->slug?>[<?=$change->id?>]"><?=sprintf("%+d", $change->delta)?> chars</a>)
				on <?=$change->date_created->format(AS_DATE)?>
				at <?=$change->date_created->format(AS_TIME)?>
			</li>
		<?php endforeach ?>
		</ul>

		<p>
			<a href="?<?=RECENT_CHANGES?>[<?=$next?>]=<?=$remote_ip?>">next</a>
		</p>

		<footer class="meta">
			<a href="?">home</a>
			<a href="?<?=HELP_PAGE?>">help</a>
			<a href="?<?=RECENT_CHANGES?>">recent</a>
		</footer>
	</article>
<?php }
