<?php
@include('../config.php');
defined('DB_NAME') or define('DB_NAME', 'wiki.db');
defined('MAIN_PAGE') or define('MAIN_PAGE', 'HomePage');
defined('HELP_PAGE') or define('HELP_PAGE', 'WikiHelp');
defined('RECENT_CHANGES') or define('RECENT_CHANGES', 'RecentChanges');
defined('CACHE_MAX_AGE') or define('CACHE_MAX_AGE', 60 * 10);
defined('USE_MULTICOLUMN') or define('USE_MULTICOLUMN', true);

const AS_DATE = 'Y-m-d';
const AS_TIME = 'H:i';

const RE_HASH_SHA1 = '/^[[:xdigit:]]{40}$/';
const RE_PAGE_SLUG = '/\b(?<slug>\p{Lu}\p{Ll}+(?:\p{Lu}\p{Ll}+|\d+)+)\b/u';
const RE_PAGE_LINK = '/(?:\[(?<title>.+?)\])?\b(?<slug>\p{Lu}\p{Ll}+(?:\p{Lu}\p{Ll}+|\d+)+)(?:=(?<action>[a-z]+)\b)?/u';
const RE_HREF_LINK = '@(?:\[([^][]+)\])?([a-z]+://(\((?3)*\)|[^\s()<>]*[^\s().,;:?!<>{}*"\'])+)@ui';
const RE_FIGURE_IMAGE = '/^(?<slug>\p{Lu}\p{Ll}+(?:\p{Lu}\p{Ll}+|\d+)+)=image$/u';
const RE_FIGURE_LINK = '@^[a-z]+://[^\s]+$@ui';
const RE_WORD_BOUNDARY = '/((?<=\p{Ll}|\d)(?=\p{Lu})|(?<=\p{Ll})(?=\d))/u';

class State
{
	public $pdo;
	public $revision_created = false;
	public $render_mode = 'html';
	public $title;

	private $page_exists_stmt;

	public function __construct()
	{
		$this->pdo = new PDO('sqlite:./' . DB_NAME);
		$this->page_exists_stmt = $this->pdo->prepare('
			SELECT 1 FROM revisions WHERE slug = ? LIMIT 1
		;');


		if (isset($_SESSION['revision_created'])) {
			$this->revision_created = $_SESSION['revision_created'];
			unset($_SESSION['revision_created']);
		}
	}

	public function __invoke($buffer)
	{
		switch ($this->render_mode) {
		case 'html':
			return wrap_html($this->title, $buffer);
		case 'text':
			header('Content-Type: text/plain;');
			return $buffer;
		default:
			return $buffer;
		}
	}

	public function PageExists($slug)
	{
		if ($slug === RECENT_CHANGES) {
			return true;
		}

		$this->page_exists_stmt->execute(array($slug));
		return (bool)$this->page_exists_stmt->fetchColumn();
	}
}

class Change
{
	public $id;
	public $prev_id;
	public $slug;
	public $date_created;
	public $remote_ip;
	public $body;
	public $prev_body;

	private $time_created;
	private $remote_addr;

	public function __construct()
	{
		$this->date_created = DateTime::createFromFormat('U', $this->time_created);
		$this->remote_ip = inet_ntop($this->remote_addr);
	}

	public function DiffToHtml()
	{
		$diff = diff(
				preg_split('/(\s+)/', htmlspecialchars($this->prev_body ?: ''), -1, PREG_SPLIT_DELIM_CAPTURE),
				preg_split('/(\s+)/', htmlspecialchars($this->body ?: ''), -1, PREG_SPLIT_DELIM_CAPTURE));
		$ret = '';
		foreach ($diff as $k) {
			if (is_array($k)) {
				$ret .= (!empty($k['d'])?"<del>".implode($k['d'])."</del>":'').
					(!empty($k['i'])?"<ins>".implode($k['i'])."</ins>":'');
			} else {
				$ret .= $k;
			}
		}
		return $ret;
	}

	public function LineStats()
	{
		$diff = diff(
				preg_split('/\n/', $this->prev_body, -1, 0),
				preg_split('/\n/', $this->body, -1, 0));
		$d = -0;
		$i = 0;
		foreach ($diff as $k) {
			if (is_array($k)) {
				$d += count($k['d']);
				$i += count($k['i']);
			}
		}
		return [-ceil($d / 2), ceil($i / 2)];
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
	public $image_width;
	public $image_height;

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
			$line = htmlspecialchars($line);

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
				yield $line;
				continue;
			}

			if ($inside_list) {
				$inside_list = false;
				yield '</ul>';
			}
			if ($inside_pre) {
				$inside_pre = false;
				yield '</pre>';
			}

			if (starts_with($line, '---')) {
				yield '<hr>';

				$heading = ltrim($line, '- ');
				if ($heading) {
					$heading = $this->Linkify($heading);
					yield '<h2>' . $heading . '</h2>';
				}
				continue;
			}

			if (starts_with($line, '&gt; ')) {
				$line = substr($line, 4);
				$line = $this->Inline($line);
				$line = $this->Linkify($line);
				yield '<blockquote>' . $line . '</blockquote>';
				continue;
			}

			$line = trim($line);

			if (preg_match(RE_FIGURE_IMAGE, $line, $matches)) {
				$slug = $matches['slug'];
				yield "<figure><img src=\"?slug=$slug&action=image\" alt=\"$slug\" loading=lazy></figure>";
				continue;
			}

			if (preg_match('#^https?://.+\.(jpg|jpeg|png|gif|webp)$#', $line)) {
				yield "<figure><img src=\"$line\" loading=lazy></figure>";
				continue;
			}

			if (preg_match(RE_FIGURE_LINK, $line)) {
				yield "<figure><a href=\"$line\" target=_parent>$line</a></figure>";
				continue;
			}

			if ($line != '') {
				$line = $this->Inline($line);
				$line = $this->Linkify($line);
				yield '<p>' . $line . '</p>';
				continue;
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
		$parts = preg_split(RE_HREF_LINK, $text, -1, PREG_SPLIT_DELIM_CAPTURE);
		$result = '';
		$part = current($parts);
		while ($part !== false) {
			$result .= $this->LinkifySlugs($part);

			$link_text = next($parts);
			$link_href = next($parts);
			if ($link_text) {
				$result .= "<a href=\"$link_href\" target=_parent>$link_text</a>";
			} elseif ($link_href) {
				$result .= "<a href=\"$link_href\" target=_parent>$link_href</a>";
			}

			// Pop the balanced parens capture group/subroutine.
			next($parts);
			$part = next($parts);
		}
		return $result;
	}

	private function LinkifySlugs($text)
	{
		$state = $this->state;
		return preg_replace_callback(
				RE_PAGE_LINK,
				function($matches) use(&$state) {
					$slug = $matches["slug"];
					$missing = $state->PageExists($slug) ? '' : 'data-missing';

					$href = "?$slug";
					if ($action = $matches["action"]) {
						if ($action == 'image') {
							$href = "?slug=$slug&action=image";
							return "<img src=\"$href\" alt=\"$slug\" loading=lazy>";
						} else {
							$href .= "=$action";
						}
					}

					if ($title = $matches["title"]) {
						return "<a $missing href=\"$href\">$title</a>";
					}

					return "<a $missing href=\"$href\">$slug</a>";
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

session_cache_limiter('none');
session_start();
$state = new State();

switch (strtoupper($_SERVER['REQUEST_METHOD'])) {
case 'GET':
	if (empty($_GET)) {
		header('Location: ?' . MAIN_PAGE, true, 303);
	}

	$slug = filter_input(INPUT_GET, 'slug');
	if (empty($slug)) {
		if (USE_MULTICOLUMN) {
			render_viewer();
			exit;
		} else {
			$slug = array_key_first($_GET);
			$action = $_GET[$slug];
			if (is_array($action)) {
				$id = array_key_first($action);
				$action = $action[$id];
				$state->title = $slug . ($action ? "[$id]=$action" : "[$id]");
			} else {
				$id = null;
				$state->title = $slug . ($action ? "=$action" : '');
			}
		}
	} else {
		$state->title = $slug;
		$id = filter_input(INPUT_GET, 'id');
		$action = filter_input(INPUT_GET, 'action');
	}

	ob_start($state);

	if (!filter_var($slug, FILTER_VALIDATE_REGEXP, ['options' => ['regexp' => RE_PAGE_SLUG]])) {
		render_invalid_slug($slug);
		exit;
	}

	if ($id && !filter_var($id, FILTER_VALIDATE_INT)) {
		render_invalid_revision($slug, $id);
		exit;
	}

	switch ($slug) {
	case RECENT_CHANGES:
		$remote_ip = $action;
		if ($remote_ip == null) {
			view_recent_changes($state, $id);
		} elseif (filter_var($remote_ip, FILTER_VALIDATE_IP)) {
			view_recent_changes_from($state, $remote_ip, $id);
		} else {
			render_invalid_address($slug, $remote_ip);
		}
		return;
	}

	switch ($action) {
	case 'diff':
		view_diff_at_revision($state, $slug, $id);
		break;
	case 'edit':
		view_edit($state, $slug, $id);
		break;
	case 'history':
		view_history($state, $slug, $id);
		break;
	case 'backlinks':
		view_backlinks($state, $slug, $id);
		break;
	case 'text':
		$state->render_mode = 'text';
	case 'html':
	case '':
		if ($id) {
			view_page_at_revision($state, $slug, $id);
		} else {
			view_page_latest($state, $slug);
		}
		break;
	case 'image':
		if ($id) {
			view_image_at_revision($state, $slug, $id);
		} else {
			view_image_latest($state, $slug);
		}
		break;
	default:
		render_invalid_action($slug, $action);
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
		header($_SERVER['SERVER_PROTOCOL'] . ' 400 Bad Request');
		exit('Invalid page slug; must be CamelCase123.');
	}

	$state->pdo->beginTransaction();

	$image_file = $_FILES['image_data'];
	if (file_exists($image_file['tmp_name']) && is_uploaded_file($image_file['tmp_name'])) {
		if ($image_file['error'] > UPLOAD_ERR_OK) {
			header($_SERVER['SERVER_PROTOCOL'] . ' 400 Bad Request');
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
				header($_SERVER['SERVER_PROTOCOL'] . ' 400 Bad Request');
				exit('Only JPG, PNG, WEBP are accepted.');
			}

			$image_width = imagesx($image);
			if ($image_width > 470) {
				$image = imagescale($image, 470);
				$image_width = imagesx($image);
			}
			$image_height = imagesy($image);

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
			header($_SERVER['SERVER_PROTOCOL'] . ' 400 Bad Request');
			exit('File is not an image; MIME type must be image/*.');
		}

		if ($image_file_size > 100000) {
			header($_SERVER['SERVER_PROTOCOL'] . ' 400 Bad Request');
			exit('File too big; must be less than 100KB.');
		}

		$statement = $state->pdo->prepare('
			INSERT OR IGNORE INTO images (hash, page_slug, content_type, time_created, remote_addr, image_data, image_width, image_height, file_size, file_name)
			VALUES (:hash, :page_slug, :content_type, :time_created, :remote_addr, :image_data, :image_width, :image_height, :file_size, :file_name)
		;');

		$image_hash = sha1_file($image_temp_name);
		$file = fopen($image_temp_name, 'rb');

		$statement->bindParam('hash', $image_hash, PDO::PARAM_STR);
		$statement->bindParam('page_slug', $slug, PDO::PARAM_STR);
		$statement->bindParam('content_type', $image_file_type, PDO::PARAM_STR);
		$statement->bindParam('time_created', $time, PDO::PARAM_INT);
		$statement->bindParam('remote_addr', $addr, PDO::PARAM_STR);
		$statement->bindParam('image_data', $file, PDO::PARAM_LOB);
		$statement->bindParam('image_width', $image_width, PDO::PARAM_INT);
		$statement->bindParam('image_height', $image_height, PDO::PARAM_INT);
		$statement->bindParam('file_size', $image_file_size, PDO::PARAM_INT);
		$statement->bindParam('file_name', $image_file['name'], PDO::PARAM_STR);

		if (!$statement->execute()) {
			exit('Unable to upload the image.');
		}
	} elseif (empty($image_hash)) {
		$image_hash = null;
	} elseif (!filter_var($image_hash, FILTER_VALIDATE_REGEXP, ['options' => ['regexp' => RE_HASH_SHA1]])) {
		header($_SERVER['SERVER_PROTOCOL'] . ' 400 Bad Request');
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

// Views.

function view_page_latest($state, $slug)
{
	$statement = $state->pdo->prepare('
		SELECT id, slug, body, latest.time_created, image_hash, image_width, image_height
		FROM latest LEFT JOIN images ON image_hash = hash
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

	if ($state->render_mode === 'text') {
		render_source($page);
	} else {
		render_latest($page, $state);
	}
}

function view_page_at_revision($state, $slug, $id)
{
	$statement = $state->pdo->prepare('
		SELECT id, slug, body, revisions.time_created, image_hash, image_width, image_height
		FROM revisions LEFT JOIN images ON image_hash = hash
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

	if ($state->render_mode === 'text') {
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
		$state->render_mode = $content_type;
		header("Content-Type: $content_type");
		header("Content-Length: $file_size");
		header("ETag: $image_hash");
		header('Cache-Control: max-age=' . CACHE_MAX_AGE);

		if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && $_SERVER['HTTP_IF_NONE_MATCH'] == $image_hash) {
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
		$state->render_mode = $content_type;
		header("Content-Type: $content_type");
		header("Content-Length: $file_size");
		header("ETag: $image_hash");
		header('Cache-Control: max-age=' . CACHE_MAX_AGE);

		if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && $_SERVER['HTTP_IF_NONE_MATCH'] == $image_hash) {
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
		SELECT id, slug, body, revisions.time_created, image_hash, image_width, image_height
		FROM revisions LEFT JOIN images ON image_hash = hash
		WHERE slug = ? AND id = ?
	;' : '
		SELECT id, slug, body, latest.time_created, image_hash, image_width, image_height
		FROM latest LEFT JOIN images ON image_hash = hash
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
			slug, id, time_created, remote_addr, body,
			LEAD(id, 1, 0) OVER (PARTITION BY slug ORDER BY id DESC) prev_id,
			LEAD(body, 1, 0) OVER (PARTITION BY slug ORDER BY id DESC) prev_body
		FROM revisions
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

function view_diff_at_revision($state, $slug, $id)
{
	$statement = $state->pdo->prepare('
		SELECT
			slug, id, time_created, remote_addr, body,
			LEAD(id, 1, 0) OVER (PARTITION BY slug ORDER BY id DESC) prev_id,
			LEAD(body, 1, 0) OVER (PARTITION BY slug ORDER BY id DESC) prev_body
		FROM revisions
		WHERE slug = ?' . ($id ? ' AND id <= ?' : '') . '
		ORDER BY id DESC
		LIMIT 1
	;');

	$statement->execute($id ? array($slug, $id) : array($slug));
	$statement->setFetchMode(PDO::FETCH_CLASS, 'Change');
	$change = $statement->fetch();

	if (!$change) {
		render_page_not_found($slug);
	} else {
		render_diff($change);
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
		render_invalid_offset(RECENT_CHANGES, $p);
		return;
	}

	$limit = 25;
	$statement = $state->pdo->prepare('
		SELECT
			slug, id, time_created, remote_addr, body,
			LEAD(id, 1, 0) OVER (PARTITION BY slug ORDER BY id DESC) prev_id,
			LEAD(body, 1, 0) OVER (PARTITION BY slug ORDER BY id DESC) prev_body
		FROM revisions
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
		render_invalid_offset(RECENT_CHANGES, $p);
		return;
	}

	$limit = 25;
	$statement = $state->pdo->prepare('
		WITH recent_changes AS (
			SELECT
				slug, id, time_created, remote_addr, body,
				LEAD(id, 1, 0) OVER (PARTITION BY slug ORDER BY id DESC) prev_id,
				LEAD(body, 1, 0) OVER (PARTITION BY slug ORDER BY id DESC) prev_body
			FROM revisions
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

// Rendering templates.

function render_viewer()
{ ?>
<!doctype html>
<title><?=MAIN_PAGE?></title>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
	body {
		margin: 0;
		display: inline-flex;
		align-items: flex-start;
	}
	iframe {
		display: block;
		height: 100vh;
		border: none;
		box-sizing: border-box;
		min-width: min(100vw, 500px);
		max-width: 500px;
		overflow-y: auto;
	}
</style>
<body>
<script>
	const NICE_SLUG = /\?(?<slug>[^[=]+)(?:\[(?<id>.*?)\])?(?:=(?<action>.+))?/;

	let realEntry = real(location);
	let entrySlug = realEntry.searchParams.get("slug");
	document.title = decodeURI(entrySlug);

	if (history.state) {
		setTimeout(() => syncState(history.state));
	} else {
		history.replaceState([realEntry.href], "", location.href);
		let frame = createFrame(realEntry.href);
		document.body.appendChild(frame);
		frame.scrollIntoView({behavior: "smooth"});
	}

	window.addEventListener("popstate", (evt) => {
		let currentSlug = real(location).searchParams.get("slug");
		document.title = decodeURI(currentSlug);
		syncState(evt.state);
	});

	function syncState(state) {
		let frames = [...document.querySelectorAll("iframe")];
		for (let i = 0; i < Math.max(frames.length, state.length); i++) {
			let href = state[i];
			if (!frames[i]) {
				// No more existing frames; create new ones to reflect the state.
				let frame = createFrame(href);
				document.body.appendChild(frame);
				frame.scrollIntoView({behavior: "smooth"});
			} else if (!href) {
				// No more hrefs in the state; remove trailing frames.
				frames[i].remove();
			} else if (href !== frames[i].src) {
				// Href-frame mismatch under index i.
				let frame = createFrame(href);
				frames[i].insertAdjacentElement("beforebegin", frame);
				frame.scrollIntoView({behavior: "smooth"});
				// Update the list of currently open frames.
				frames = [...document.querySelectorAll("iframe")];
			} else {
				// Href and frame match; all good;
			}
		}
	}

	// Convert a nice URL (?PageSlug[id]=action) to a real URL (?slug=PageSlug&…).
	function real(url) {
		let copy = new URL(url);
		copy.search = "";

		let match = NICE_SLUG.exec(decodeURI(url.search));
		if (match.groups.slug) {
			copy.searchParams.set("slug", match.groups.slug);
		}
		if (match.groups.id) {
			copy.searchParams.set("id", match.groups.id);
		}
		if (match.groups.action) {
			copy.searchParams.set("action", match.groups.action);
		}

		return copy;
	}

	function captureLinks(evt) {
		evt.preventDefault();

		let a = evt.currentTarget;
		let win = a.ownerDocument.defaultView;
		let frame = win.frameElement;

		// Remove all panes to the right.
		while (frame.nextElementSibling) {
			frame.nextElementSibling.remove();
		}

		let state = getState();
		let realTarget = real(a);
		let targetSlug = realTarget.searchParams.get("slug");

		if (realTarget.href === win.location.href) {
			// It's a link to the same page on which the click happened.
			if (win.location.search === real(location).search) {
				// The viewport URL already points to it; remove all other frames.
				history.pushState([realTarget.href], "", a.href);
				while (frame.previousElementSibling) {
					frame.previousElementSibling.remove();
				}
			} else {
				// The viewport URL points to another page; update the viewport URL.
				history.pushState(state, "", a.href);
				document.title = decodeURI(targetSlug);
			}
		} else if (state.includes(realTarget.href)) {
			// There's already a pane with this page.
			history.pushState(state, "", a.href);
			document.title = decodeURI(targetSlug);
			let frame = document.querySelector(`iframe[src="${realTarget.href}"]`);
			frame.scrollIntoView({behavior: "smooth"});
		} else {
			let state = getState();
			state.push(realTarget.href);
			history.pushState(state, "", a.href);
			document.title = decodeURI(targetSlug);
			let frame = createFrame(realTarget.href);
			document.body.appendChild(frame);
			frame.scrollIntoView({behavior: "smooth"});
		}
	}

	function createFrame(href) {
		let frame = document.createElement("iframe");
		frame.src = href;
		frame.addEventListener("load", () => {
			for (let a of frame.contentWindow.document.querySelectorAll("a[href^='?']")) {
				a.addEventListener("click", captureLinks);
			}
		});
		return frame;
	}

	function getState() {
		let state = [];
		for (let frame of document.querySelectorAll("iframe")) {
			state.push(frame.src);
		}
		return state;
	}
</script>
</body>
<?php }

function wrap_html($title, $buffer)
{
	return <<<EOF
<!doctype html>
<html>
	<title>$title</title>
	<head>
		<meta charset="utf-8">
		<meta name="viewport" content="width=device-width, initial-scale=1">
		<style>
			body {
				margin: 0;
			}

			h1 a {
				color: blue;
				text-decoration: none;
			}

			article {
				overflow-wrap: break-word;
				padding: 15px;
			}

			article a {
				text-decoration: none;
			}

			article a[data-missing] {
				color: firebrick;
			}

			article a:not([href^="?"]) {
				word-break: break-all;
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

			article figure img {
				display: block;
				max-width: 100%;
				height: auto;
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

			ins {
				background: palegreen;
				text-decoration: none;
			}

			del {
				background: pink;
				text-decoration: none;
			}
		</style>
	</head>
	<body>
$buffer
	</body>
</html>
EOF;
}

function render_invalid_slug($slug)
{
	header($_SERVER['SERVER_PROTOCOL'] . ' 400 Bad Request');
?>
	<article class="meta" style="background:mistyrose">
		<h1>Invalid Page Name </h1>
		<p><?=htmlspecialchars($slug)?> is not a valid page name.</p>
		<hr>
		<footer class="meta">
			<a href="?<?=MAIN_PAGE?>">home</a>
			<a href="?<?=HELP_PAGE?>">help</a>
			<a href="?<?=RECENT_CHANGES?>">recent</a>
		</footer>
	</article>
<?php }

function render_invalid_revision($slug, $id)
{
	header($_SERVER['SERVER_PROTOCOL'] . ' 400 Bad Request');
?>
	<article class="meta" style="background:mistyrose">
		<h1>Invalid Revision</h1>
		<p><?=htmlspecialchars($slug)?>[<?=htmlspecialchars($id)?>] is not a valid revision.</p>
		<hr>
		<footer class="meta">
			<a href="?<?=MAIN_PAGE?>">home</a>
			<a href="?<?=HELP_PAGE?>">help</a>
			<a href="?<?=RECENT_CHANGES?>">recent</a>
		</footer>
	</article>
<?php }

function render_invalid_action($slug, $action)
{
	header($_SERVER['SERVER_PROTOCOL'] . ' 400 Bad Request');
?>
	<article class="meta" style="background:mistyrose">
		<h1>Invalid Action</h1>
		<p><?=htmlspecialchars($action)?> is not a valid action name.</p>
		<hr>
		<footer class="meta">
			<a href="?<?=MAIN_PAGE?>">home</a>
			<a href="?<?=HELP_PAGE?>">help</a>
			<a href="?<?=RECENT_CHANGES?>">recent</a>
		</footer>
	</article>
<?php }

function render_invalid_offset($slug, $p)
{
	header($_SERVER['SERVER_PROTOCOL'] . ' 400 Bad Request');
?>
	<article class="meta" style="background:mistyrose">
		<h1>Invalid Range</h1>
		<p><?=htmlspecialchars($slug)?>[<?=htmlspecialchars($p)?>] is not a valid range offset.</p>
		<hr>
		<footer class="meta">
			<a href="?<?=MAIN_PAGE?>">home</a>
			<a href="?<?=HELP_PAGE?>">help</a>
			<a href="?<?=RECENT_CHANGES?>">recent</a>
		</footer>
	</article>
<?php }

function render_invalid_address($slug, $ip = null)
{
	header($_SERVER['SERVER_PROTOCOL'] . ' 400 Bad Request');
?>
	<article class="meta" style="background:mistyrose">
		<h1>Invalid Address</h1>
		<p><?=htmlspecialchars($ip)?> is not a valid IP address.</p>
		<hr>
		<footer class="meta">
			<a href="?<?=MAIN_PAGE?>">home</a>
			<a href="?<?=HELP_PAGE?>">help</a>
			<a href="?<?=RECENT_CHANGES?>">recent</a>
		</footer>
	</article>
<?php }

function render_page_not_found($slug)
{
	header($_SERVER['SERVER_PROTOCOL'] . ' 404 Not Found');
?>
	<article class="meta" style="background:mistyrose">
		<h1>Page Not Found</h1>
		<p><?=$slug?> doesn't exist yet. <a href="?<?=$slug?>=edit">Create?</a></p>
		<hr>
		<footer class="meta">
			<a href="?<?=$slug?>=backlinks">backlinks</a>
			<br>
			<a href="?<?=MAIN_PAGE?>">home</a>
			<a href="?<?=HELP_PAGE?>">help</a>
			<a href="?<?=RECENT_CHANGES?>">recent</a>
		</footer>
	</article>
<?php }

function render_revision_not_found($slug, $id)
{
	header($_SERVER['SERVER_PROTOCOL'] . ' 404 Not Found');
?>
	<article class="meta" style="background:mistyrose">
		<h1>Revision Not Found</h1>
		<p><?=$slug?>[<?=$id?>] doesn't exist.</p>
		<hr>
		<footer class="meta">
			<a href="?<?=$slug?>=backlinks">backlinks</a>
			<a href="?<?=$slug?>=history">history</a>
			<a href="?<?=$slug?>">latest</a>
			<br>
			<a href="?<?=MAIN_PAGE?>">home</a>
			<a href="?<?=HELP_PAGE?>">help</a>
			<a href="?<?=RECENT_CHANGES?>">recent</a>
		</footer>
	</article>
<?php }

function render_image_not_found($slug, $id = null)
{
	header($_SERVER['SERVER_PROTOCOL'] . ' 404 Not Found');
?>
	<article class="meta" style="background:mistyrose">
	<?php if ($id): ?>
		<h1>Image Not Found</h1>
		<p><?=$slug?>[<?=$id?>] doesn't have an image.</p>
	<?php else: ?>
		<h1>Image Not Found</h1>
		<p><?=$slug?> doesn't have an image. <a href="?<?=$slug?>=edit">Edit?</a></p>
	<?php endif ?>
		<hr>
		<footer class="meta">
			<a href="?<?=$slug?>=backlinks">backlinks</a>
		<?php if ($id): ?>
			<a href="?<?=$slug?>=history">history</a>
			<a href="?<?=$slug?>">latest</a>
		<?php endif ?>
			<br>
			<a href="?<?=MAIN_PAGE?>">home</a>
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

	<?php if ($page->image_hash): ?>
		<figure><img src="?slug=<?=$page->slug?>&action=image" width="<?=$page->image_width?>" height="<?=$page->image_height?>"></figure>
	<?php endif ?>

	<?php foreach($page->IntoHtml() as $elem): ?><?=$elem?><?php endforeach ?>

		<hr>
		<footer class="meta">
			last modified on <span title="<?=$page->date_created->format(AS_TIME)?>">
				<?=$page->date_created->format(AS_DATE)?></span>
			<a href="?<?=$page->slug?>=diff">diff</a>
			<br>
			<a href="?<?=$page->slug?>=edit">edit</a>
			<a href="?<?=$page->slug?>=text">text</a>
		<?php if ($page->image_hash): ?>
			<a href="?<?=$page->slug?>=image">image</a>
		<?php endif ?>
			<a href="?<?=$page->slug?>=backlinks">backlinks</a>
			<a href="?<?=$page->slug?>=history">history</a>
			<br>
			<a href="?<?=MAIN_PAGE?>">home</a>
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
		<figure><img src="?slug=<?=$page->slug?>&id=<?=$page->id?>&action=image" width="<?=$page->image_width?>" height="<?=$page->image_height?>"></figure>
	<?php endif ?>

	<?php foreach($page->IntoHtml() as $elem): ?><?=$elem?><?php endforeach ?>

		<hr>
		<footer class="meta">
			revision <?=$page->id?> from <span title="<?=$page->date_created->format(AS_TIME)?>">
				<?=$page->date_created->format(AS_DATE)?></span>
			<a href="?<?=$page->slug?>[<?=$page->id?>]=diff">diff</a>
			<br>
			<a href="?<?=$page->slug?>[<?=$page->id?>]=edit">restore</a>
			<a href="?<?=$page->slug?>[<?=$page->id?>]=text">text</a>
		<?php if ($page->image_hash): ?>
			<a href="?<?=$page->slug?>[<?=$page->id?>]=image">image</a>
		<?php endif ?>
			<a href="?<?=$page->slug?>[<?=$page->id?>]=backlinks">backlinks</a>
			<a href="?<?=$page->slug?>[<?=$page->id?>]=history">history</a>
			<a href="?<?=$page->slug?>">latest</a>
			<br>
			<a href="?<?=MAIN_PAGE?>">home</a>
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
			<figure><img src="?slug=<?=$page->slug?>&id=<?=$page->id?>&action=image" width="<?=$page->image_width?>" height="<?=$page->image_height?>"></figure>
		<?php endif ?>
			<input type="file" name="image_data" accept="image/*" onchange="let size_kb = this.files[0].size / 1024; this.nextElementSibling.textContent = new Intl.NumberFormat('en', {style: 'unit', unit: 'kilobyte', maximumFractionDigits: 1}).format(size_kb);"><small></small>

			<textarea name="body" placeholder="Type here..."><?=$page->body?></textarea>
			<input type="text" name="user" placeholder="Leave this empty.">
			<button type="submit">Save</button>
		</form>

		<hr>
		<footer class="meta">
			<a href="?<?=$page->slug?>=text">text</a>
			<a href="?<?=$page->slug?>=backlinks">backlinks</a>
			<a href="?<?=$page->slug?>=history">history</a>
			<br>
			<a href="?<?=MAIN_PAGE?>">home</a>
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
		<?php foreach ($changes as $change):
			[$del, $ins] = $change->LineStats();
		?>
			<li>
				<a href="?<?=$slug?>[<?=$change->id?>]">
					[<?=$change->id?>]
				</a>
				<small>(<a href="?<?=$slug?>[<?=$change->id?>]=diff"><del><?=$del === 0 ? '-0' : sprintf("%+d", $del)?></del> <ins><?=sprintf("%+d", $ins)?></ins></a>)</small>
				on <?=$change->date_created->format(AS_DATE)?>
				at <?=$change->date_created->format(AS_TIME)?>
				from <a href="?<?=RECENT_CHANGES?>=<?=$change->remote_ip?>"><?=$change->remote_ip?></a>
			</li>
		<?php endforeach ?>
		</ul>

		<hr>
		<footer class="meta">
			<a href="?<?=MAIN_PAGE?>">home</a>
			<a href="?<?=HELP_PAGE?>">help</a>
			<a href="?<?=RECENT_CHANGES?>">recent</a>
		</footer>
	</article>
<?php }

function render_diff($change)
{ ?>
	<article style="background:aliceblue">
		<h1 class="meta">
			Diff for <a href="?<?=$change->slug?>"><?=$change->slug?></a>
			<small>
			<del><a href="?<?=$change->slug?>[<?=$change->prev_id?>]">
				[<?=$change->prev_id?>]</a></del> →
			<ins><a href="?<?=$change->slug?>[<?=$change->id?>]">
				[<?=$change->id?>]</a></ins>
			</small>
		</h1>

		<pre style="background:none"><?=$change->DiffToHtml()?></pre>

		<hr>
		<footer class="meta">
			<a href="?<?=MAIN_PAGE?>">home</a>
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

		<hr>
		<footer class="meta">
			<a href="?<?=MAIN_PAGE?>">home</a>
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
		<?php foreach ($changes as $change):
			[$del, $ins] = $change->LineStats();
		?>
			<li>
				<a href="?<?=$change->slug?>[<?=$change->id?>]">
					<?=$change->slug?>[<?=$change->id?>]
				</a>
				<small>(<a href="?<?=$change->slug?>[<?=$change->id?>]=diff"><del><?=$del === 0 ? '-0' : sprintf("%+d", $del)?></del> <ins><?=sprintf("%+d", $ins)?></ins></a>)</small>
				on <?=$change->date_created->format(AS_DATE)?>
				at <?=$change->date_created->format(AS_TIME)?>
				from <a href="?<?=RECENT_CHANGES?>=<?=$change->remote_ip?>"><?=$change->remote_ip?></a>
			</li>
		<?php endforeach ?>
		</ul>

		<p>
			<a href="?<?=RECENT_CHANGES?>[<?=$next?>]">next</a>
		</p>

		<hr>
		<footer class="meta">
			<a href="?<?=MAIN_PAGE?>">home</a>
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
		<?php foreach ($changes as $change):
			[$del, $ins] = $change->LineStats();
		?>
			<li>
				<a href="?<?=$change->slug?>[<?=$change->id?>]">
					<?=$change->slug?>[<?=$change->id?>]
				</a>
				<small>(<a href="?<?=$change->slug?>[<?=$change->id?>]=diff"><del><?=$del === 0 ? '-0' : sprintf("%+d", $del)?></del> <ins><?=sprintf("%+d", $ins)?></ins></a>)</small>
				on <?=$change->date_created->format(AS_DATE)?>
				at <?=$change->date_created->format(AS_TIME)?>
			</li>
		<?php endforeach ?>
		</ul>

		<p>
			<a href="?<?=RECENT_CHANGES?>[<?=$next?>]=<?=$remote_ip?>">next</a>
		</p>

		<hr>
		<footer class="meta">
			<a href="?<?=MAIN_PAGE?>">home</a>
			<a href="?<?=HELP_PAGE?>">help</a>
			<a href="?<?=RECENT_CHANGES?>">recent</a>
		</footer>
	</article>
<?php }

// Paul's Simple Diff Algorithm v 0.1
// (C) Paul Butler 2007 <http://www.paulbutler.org/>
// May be used and distributed under the zlib/libpng license.
// https://paulbutler.org/2007/a-simple-diff-algorithm-in-php/

function diff($old, $new){
	$matrix = array();
	$maxlen = 1;
	foreach ($old as $oindex => $ovalue) {
		$nkeys = array_keys($new, $ovalue);
		foreach ($nkeys as $nindex) {
			$matrix[$oindex][$nindex] = isset($matrix[$oindex - 1][$nindex - 1]) ?
				$matrix[$oindex - 1][$nindex - 1] + 1 : 1;
			if ($matrix[$oindex][$nindex] > $maxlen) {
				$maxlen = $matrix[$oindex][$nindex];
				$omax = $oindex + 1 - $maxlen;
				$nmax = $nindex + 1 - $maxlen;
			}
		}
	}
	if ($maxlen == 1) {
		return array(array('d'=>$old, 'i'=>$new));
	}
	return array_merge(
			diff(array_slice($old, 0, $omax), array_slice($new, 0, $nmax)),
			array_slice($new, $nmax, $maxlen),
			diff(array_slice($old, $omax + $maxlen), array_slice($new, $nmax + $maxlen)));
}
