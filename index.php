<?php
@include('../config.php');
defined('DB_NAME') or define('DB_NAME', 'wiki.db');
defined('MAIN_PAGE') or define('MAIN_PAGE', 'HomePage');
defined('HELP_PAGE') or define('HELP_PAGE', 'WikiHelp');
defined('RECENT_CHANGES') or define('RECENT_CHANGES', 'RecentChanges');
defined('CACHE_MAX_AGE') or define('CACHE_MAX_AGE', 60 * 10);

const AS_DATE = 'Y-m-d';
const AS_TIME = 'H:i';

const RE_HASH_SHA1 = '/^[[:xdigit:]]{40}$/';
const RE_PAGE_SLUG = '/\b(?<slug>\p{Lu}\p{Ll}+(?:\p{Lu}\p{Ll}+|\d+)+)\b/u';
const RE_PAGE_LINK = '/(?:\[(?<title>.+?)\])?\b(?<slug>\p{Lu}\p{Ll}+(?:\p{Lu}\p{Ll}+|\d+)+)(?:=(?<action>[a-z]+)\b)?/u';
const RE_IMAGE_EMBED = '/^\p{Lu}\p{Ll}+(?:\p{Lu}\p{Ll}+|\d+)+=image$/u';
const RE_WORD_BOUNDARY = '/((?<=\p{Ll}|\d)(?=\p{Lu})|(?<=\p{Ll})(?=\d))/u';


class State
{
	public $pdo;
	public $revision_created = false;
	public $content_type = null;
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
		default:
			return $buffer;
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
	public $image_hash;

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

			if (preg_match(RE_IMAGE_EMBED, $line)) {
				if ($inside_pre) {
					$inside_pre = false;
					yield '</pre>';
				}
				if ($inside_list) {
					$inside_list = false;
					yield '</ul>';
				}
				yield "<figure><img src=\"?$line\"/></figure>";
				continue;
			}

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

						if ($action == 'image') {
							return "<img src=\"?$slug\">";
						}
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

function view_page_latest($state, $slug, $mode)
{
	$statement = $state->pdo->prepare('
		SELECT id, slug, body, time_created, image_hash
		FROM latest
		WHERE slug = ?
	;');

	$statement->execute(array($slug));
	$statement->setFetchMode(PDO::FETCH_CLASS, 'Revision');
	$page = $statement->fetch();

	if (!$page) {
		render_page_not_found($slug);
		return;
	}

	$page->Anchor($state);

	if ($mode === 'text') {
		render_source($page);
	} else {
		render_latest($page, $state);
	}
}

function view_page_at_revision($state, $slug, $id, $mode)
{
	$statement = $state->pdo->prepare('
		SELECT id, slug, body, time_created, image_hash
		FROM revisions
		WHERE slug = ? AND id = ?
	;');

	$statement->execute(array($slug, $id));
	$statement->setFetchMode(PDO::FETCH_CLASS, 'Revision');
	$page = $statement->fetch();

	if (!$page) {
		render_revision_not_found($slug, $id);
		return;
	}

	$page->Anchor($state);

	if ($mode === 'text') {
		render_source($page);
	} else {
		render_revision($page);
	}
}

function view_image_latest($state, $slug)
{
	$statement = $state->pdo->prepare('
		SELECT hash, content_type, image_data, file_size
		FROM images JOIN latest ON images.hash == latest.image_hash
		WHERE latest.slug = ?
	;');

	$statement->execute(array($slug));
	$statement->bindColumn('hash', $image_hash, PDO::PARAM_STR);
	$statement->bindColumn('content_type', $content_type, PDO::PARAM_STR);
	$statement->bindColumn('image_data', $image_data, PDO::PARAM_LOB);
	$statement->bindColumn('file_size', $file_size, PDO::PARAM_INT);

	if ($statement->fetch(PDO::FETCH_BOUND)) {
		header("Content-Type: $content_type");
		header("Content-Length: $file_size");
		header("ETag: $image_hash");
		header('Cache-Control: max-age=' . CACHE_MAX_AGE);

		if ($_SERVER['HTTP_IF_NONE_MATCH'] == $image_hash) {
			header($_SERVER['SERVER_PROTOCOL'] . ' 304 Not Modified');
			ob_end_flush();
		} else {
			ob_end_flush();
			fpassthru($image_data);
		}
	} elseif ($state->PageExists($slug)) {
		render_image_not_found($slug);
	} else {
		render_page_not_found($slug);
	}


}

function view_image_at_revision($state, $slug, $id)
{
	$statement = $state->pdo->prepare('
		SELECT hash, content_type, image_data, file_size
		FROM images JOIN revisions ON images.hash == revisions.image_hash
		WHERE revisions.slug = ? AND revisions.id = ?
	;');

	$statement->execute(array($slug, $id));
	$statement->bindColumn('hash', $image_hash, PDO::PARAM_STR);
	$statement->bindColumn('content_type', $content_type, PDO::PARAM_STR);
	$statement->bindColumn('image_data', $image_data, PDO::PARAM_LOB);
	$statement->bindColumn('file_size', $file_size, PDO::PARAM_INT);

	if ($statement->fetch(PDO::FETCH_BOUND)) {
		header("Content-Type: $content_type");
		header("Content-Length: $file_size");
		header("ETag: $image_hash");
		header('Cache-Control: max-age=' . CACHE_MAX_AGE);

		if ($_SERVER['HTTP_IF_NONE_MATCH'] == $image_hash) {
			header($_SERVER['SERVER_PROTOCOL'] . ' 304 Not Modified');
			ob_end_flush();
		} else {
			ob_end_flush();
			fpassthru($image_data);
		}
	} elseif ($state->PageExists($slug)) {
		render_image_not_found($slug, $id);
	} else {
		render_revision_not_found($slug, $id);
	}
}

function view_edit($state, $slug, $id)
{
	$statement = $state->pdo->prepare($id ? '
		SELECT id, slug, body, time_created, image_hash
		FROM revisions
		WHERE slug = ? AND id = ?
	;' : '
		SELECT id, slug, body, time_created, image_hash
		FROM latest
		WHERE slug = ?
	;');

	$statement->execute($id ? array($slug, $id) : array($slug));
	$statement->setFetchMode(PDO::FETCH_CLASS, 'Revision');
	$page = $statement->fetch();

	if (!$page) {
		if ($id !== null) {
			render_revision_not_found($slug, $id);
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
		render_page_not_found($slug);
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

session_cache_limiter('none');
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

		if (empty($action)) {
			$action = 'html';
		}

		switch ($action) {
		case 'edit':
			$state->content_type = 'html';
			view_edit($state, $slug, $id);
			break;
		case 'history':
			$state->content_type = 'html';
			view_history($state, $slug, $id);
			break;
		case 'backlinks':
			$state->content_type = 'html';
			view_backlinks($state, $slug, $id);
			break;
		case 'html':
		case 'text':
			$state->content_type = $action;
			if ($id) {
				view_page_at_revision($state, $slug, $id, $action);
			} else {
				view_page_latest($state, $slug, $action);
			}
			break;
		case 'image':
			if ($id) {
				view_image_at_revision($state, $slug, $id);
			} else {
				view_image_latest($state, $slug);
			}
			break;
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
	$image_hash = filter_input(INPUT_POST, 'image_hash');

	if (!filter_var($slug, FILTER_VALIDATE_REGEXP, ['options' => ['regexp' => RE_PAGE_SLUG]])) {
		header($_SERVER['SERVER_PROTOCOL'] . ' 400 Bad Request', true, 400);
		exit('Invalid page slug; must be CamelCase123.');
	}

	$state->pdo->beginTransaction();

	$image_file = $_FILES['image_data'];
	if (file_exists($image_file['tmp_name']) && is_uploaded_file($image_file['tmp_name'])) {
		if ($image_file['error'] > UPLOAD_ERR_OK) {
			header($_SERVER['SERVER_PROTOCOL'] . ' 400 Bad Request', true, 400);
			exit('File too big; must be less than 100KB.');
		}

		$image_file_type = mime_content_type($image_file['tmp_name']);
		if (extension_loaded('gd')) {
			switch ($image_file_type) {
			case 'image/jpeg':
				$image = imagecreatefromjpeg($image_file['tmp_name']);
				break;
			case 'image/png':
				$image = imagecreatefrompng($image_file['tmp_name']);
				imagepalettetotruecolor($image);
				imagealphablending($image, true);
				imagesavealpha($image, true);
				break;
			case 'image/webp':
				$image = imagecreatefromwebp($image_file['tmp_name']);
				break;
			default:
				header($_SERVER['SERVER_PROTOCOL'] . ' 400 Bad Request', true, 400);
				exit('Only JPG, PNG, WEBP are accepted.');
			}

			if (imagesx($image) > 470) {
				$image = imagescale($image, 470);
			}

			if ($image_file_type != 'image/webp') {
				$image_file_type = 'image/webp';
				$image_temp_name = $image_file['tmp_name'] . '.webp';
				imagewebp($image, $image_temp_name, 84);
			} else {
				$image_temp_name = $image_file['tmp_name'];
			}

			$image_file_size = filesize($image_temp_name);
		} elseif (starts_with($image_file_type, 'image/')) {
			$image_temp_name = $image_file['tmp_name'];
			$image_file_size = $image_file['size'];
		} else {
			header($_SERVER['SERVER_PROTOCOL'] . ' 400 Bad Request', true, 400);
			exit('File is not an image; MIME type must be image/*.');
		}


		if ($image_file_size > 100000) {
			header($_SERVER['SERVER_PROTOCOL'] . ' 400 Bad Request', true, 400);
			exit('File too big; must be less than 100KB.');
		}

		$statement = $state->pdo->prepare('
			INSERT OR IGNORE INTO images (hash, page_slug, content_type, time_created, remote_addr, image_data, file_size, file_name)
			VALUES (:hash, :page_slug, :content_type, :time_created, :remote_addr, :image_data, :file_size, :file_name)
		;');

		$image_hash = sha1_file($image_temp_name);
		$file = fopen($image_temp_name, 'rb');

		$statement->bindParam('hash', $image_hash, PDO::PARAM_STR);
		$statement->bindParam('page_slug', $slug, PDO::PARAM_STR);
		$statement->bindParam('content_type', $image_file_type, PDO::PARAM_STR);
		$statement->bindParam('time_created', $time, PDO::PARAM_INT);
		$statement->bindParam('remote_addr', $addr, PDO::PARAM_STR);
		$statement->bindParam('image_data', $file, PDO::PARAM_LOB);
		$statement->bindParam('file_size', $image_file_size, PDO::PARAM_INT);
		$statement->bindParam('file_name', $image_file['name'], PDO::PARAM_STR);

		if (!$statement->execute()) {
			exit('Unable to upload the image.');
		}
	} elseif (empty($image_hash)) {
		$image_hash = null;
	} elseif (!filter_var($image_hash, FILTER_VALIDATE_REGEXP, ['options' => ['regexp' => RE_HASH_SHA1]])) {
		header($_SERVER['SERVER_PROTOCOL'] . ' 400 Bad Request', true, 400);
		exit('Invalid image_hash; must be SHA1 or empty.');
	}

	$statement = $state->pdo->prepare('
		INSERT INTO revisions (slug, body, time_created, remote_addr, image_hash)
		VALUES (:slug, :body, :time, :addr, :image_hash)
	;');

	$statement->bindParam('slug', $slug, PDO::PARAM_STR);
	$statement->bindParam('body', $body, PDO::PARAM_STR);
	$statement->bindParam('time', $time, PDO::PARAM_INT);
	$statement->bindParam('addr', $addr, PDO::PARAM_STR);
	$statement->bindParam('image_hash', $image_hash, PDO::PARAM_STR);

	if ($statement->execute()) {
		$state->pdo->commit();
		$_SESSION['revision_created'] = true;
		header("Location: ?$slug", true, 303);
		exit;
	}

	exit("Unable to create a new revision of $slug.");
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

			article p img {
				max-width: 100px;
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
				max-width: 100%;
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
				margin-top: 1em;
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

function render_page_not_found($slug)
{ ?>
	<article class="meta" style="background:mistyrose">
		<h1>Page Not Found</h1>
		<p><?=$slug?> doesn't exist yet. <a href="?<?=$slug?>=edit">Create?</a></p>

		<footer class="meta">
			<a href="?<?=$slug?>=backlinks">backlinks</a>
			<br>
			<a href="?">home</a>
			<a href="?<?=HELP_PAGE?>">help</a>
			<a href="?<?=RECENT_CHANGES?>">recent</a>
		</footer>
	</article>
<?php }

function render_revision_not_found($slug, $id)
{ ?>
	<article class="meta" style="background:mistyrose">
		<h1>Revision Not Found</h1>
		<p><?=$slug?>[<?=$id?>] doesn't exist.</p>

		<footer class="meta">
			<a href="?<?=$slug?>=backlinks">backlinks</a>
			<a href="?<?=$slug?>=history">history</a>
			<a href="?<?=$slug?>">latest</a>
			<br>
			<a href="?">home</a>
			<a href="?<?=HELP_PAGE?>">help</a>
			<a href="?<?=RECENT_CHANGES?>">recent</a>
		</footer>
	</article>
<?php }

function render_image_not_found($slug, $id = null)
{ ?>
	<article class="meta" style="background:mistyrose">
	<?php if ($id): ?>
		<h1>Image Not Found</h1>
		<p><?=$slug?>[<?=$id?>] doesn't have an image.</p>
	<?php else: ?>
		<h1>Image Not Found</h1>
		<p><?=$slug?> doesn't have an image. <a href="?<?=$slug?>=edit">Edit?</a></p>
	<?php endif ?>

		<footer class="meta">
			<a href="?<?=$slug?>=backlinks">backlinks</a>
		<?php if ($id): ?>
			<a href="?<?=$slug?>=history">history</a>
			<a href="?<?=$slug?>">latest</a>
		<?php endif ?>
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

	<?php if ($page->image_hash): ?>
		<figure><img src="?<?=$page->slug?>=image"></figure>
	<?php endif ?>

	<?php foreach($page->IntoHtml() as $elem): ?><?=$elem?><?php endforeach ?>

		<footer class="meta">
			last modified on <span title="<?=$page->date_created->format(AS_TIME)?>">
				<?=$page->date_created->format(AS_DATE)?></span>
			<a href="?<?=$page->slug?>=edit">edit</a>
			<br>
			<a href="?<?=$page->slug?>">html</a>
			<a href="?<?=$page->slug?>=text">text</a>
		<?php if ($page->image_hash): ?>
			<a href="?<?=$page->slug?>=image">image</a>
		<?php endif ?>
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

	<?php if ($page->image_hash): ?>
		<figure><img src="?<?=$page->slug?>[<?=$page->id?>]=image"></figure>
	<?php endif ?>

	<?php foreach($page->IntoHtml() as $elem): ?><?=$elem?><?php endforeach ?>

		<footer class="meta">
			revision <?=$page->id?> from <span title="<?=$page->date_created->format(AS_TIME)?>">
				<?=$page->date_created->format(AS_DATE)?></span>
			<a href="?<?=$page->slug?>[<?=$page->id?>]=edit">restore</a>
			<br>
			<a href="?<?=$page->slug?>[<?=$page->id?>]">html</a>
			<a href="?<?=$page->slug?>[<?=$page->id?>]=text">text</a>
		<?php if ($page->image_hash): ?>
			<a href="?<?=$page->slug?>[<?=$page->id?>]=image">image</a>
		<?php endif ?>
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
			Edit <a href="?<?=$page->slug?>">
				<?=$page->title?>
			</a> <small>[<?=$page->id?>]</small>
		</h1>

		<form method="post" action="?" enctype="multipart/form-data">
			<input type="hidden" name="slug" value="<?=$page->slug?>">
			<input type="hidden" name="image_hash" value="<?=$page->image_hash?>">

		<?php if ($page->image_hash): ?>
			<figure><img src="?<?=$page->slug?>[<?=$page->id?>]=image"></figure>
		<?php endif ?>
			<input type="file" name="image_data" accept="image/*" onchange="let size_kb = this.files[0].size / 1024; this.nextElementSibling.textContent = new Intl.NumberFormat('en', {style: 'unit', unit: 'kilobyte', maximumFractionDigits: 1}).format(size_kb);"><small></small>

			<textarea name="body" placeholder="Type here..."><?=$page->body?></textarea>
			<input type="text" name="user" placeholder="Leave this empty.">
			<button type="submit">Save</button>
		</form>

		<footer class="meta">
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
