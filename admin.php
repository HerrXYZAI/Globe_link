<?php
/**
 * Password-protected configuration view: manage all GPS tracks.
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';

session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Strict',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
]);
session_name('globelink_admin');
session_start();

header('X-Frame-Options: DENY');
header('Cache-Control: no-store');

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

function check_password(string $password): bool
{
    $hash = setting('admin_password_hash');
    if ($hash !== null) {
        return password_verify($password, $hash);
    }
    return hash_equals((string)cfg('admin_password'), $password);
}

function using_default_password(): bool
{
    return setting('admin_password_hash') === null && cfg('admin_password') === 'changeme';
}

function flash(string $msg, string $type = 'ok'): void
{
    $_SESSION['flash'][] = [$type, $msg];
}

function redirect(string $query = ''): never
{
    header('Location: admin.php' . ($query !== '' ? '?' . $query : ''));
    exit;
}

function require_csrf(): void
{
    if (!hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('Invalid form token. Please reload the page and try again.');
    }
}

function find_track(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM tracks WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/** Store an uploaded GPS file and return the path to save in the database. */
function handle_upload(array $file): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload failed (error code ' . $file['error'] . ')');
    }
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['gpx', 'kml', 'geojson', 'json'], true)) {
        throw new RuntimeException('Only .gpx, .kml, .geojson and .json files are allowed');
    }
    parse_track_data(file_get_contents($file['tmp_name'])); // validate before storing

    $dir = rtrim((string)cfg('upload_dir'), '/\\');
    if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
        throw new RuntimeException('Upload directory cannot be created: ' . $dir);
    }
    $base = preg_replace('/[^A-Za-z0-9._-]+/', '-', pathinfo($file['name'], PATHINFO_FILENAME)) ?: 'track';
    $target = $dir . '/' . $base . '.' . $ext;
    for ($i = 2; file_exists($target); $i++) {
        $target = $dir . '/' . $base . '-' . $i . '.' . $ext;
    }
    if (!move_uploaded_file($file['tmp_name'], $target)) {
        throw new RuntimeException('Could not store the uploaded file');
    }
    // Saved relative to tracks_base_dir when possible, so the installation stays movable.
    return storable_path($target);
}

function find_marker(int $id): ?array
{
    $st = db()->prepare('SELECT * FROM markers WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/** Fields shared by tracks and markers, read from the posted form. */
function posted_common_fields(string $defaultColor): array
{
    $data = [
        'name' => trim((string)($_POST['name'] ?? '')),
        'description' => trim((string)($_POST['description'] ?? '')),
        'image' => trim((string)($_POST['image'] ?? '')),
        'link' => trim((string)($_POST['link'] ?? '')),
        'color' => preg_match('/^#[0-9a-fA-F]{6}$/', (string)($_POST['color'] ?? '')) ? $_POST['color'] : $defaultColor,
        'visible' => isset($_POST['visible']) ? 1 : 0,
        'sort_order' => (int)($_POST['sort_order'] ?? 0),
    ];
    if ($data['link'] !== '' && !is_url($data['link'])) {
        throw new RuntimeException('The article link must start with http:// or https://');
    }
    $uploadedImage = handle_image_upload($_FILES['image_upload'] ?? []);
    if ($uploadedImage !== null) {
        $data['image'] = $uploadedImage;
    }
    if (!empty($_POST['fetch_meta']) && $data['link'] !== '') {
        $meta = fetch_article_meta($data['link']);
        foreach (['name' => 'title', 'description' => 'description', 'image' => 'image'] as $field => $key) {
            if ($data[$field] === '') {
                $data[$field] = $meta[$key];
            }
        }
    }
    return $data;
}

const TRACK_COLORS = ['#ff6b35', '#4cc9f0', '#f9c74f', '#90be6d', '#f72585', '#b5179e', '#43aa8b', '#ffd166'];

$loggedIn = !empty($_SESSION['admin']);
$action = $_POST['action'] ?? '';

// ---------------------------------------------------------------- login / logout
if ($action === 'login') {
    require_csrf();
    if (check_password((string)($_POST['password'] ?? ''))) {
        session_regenerate_id(true);
        $_SESSION['admin'] = true;
        redirect();
    }
    sleep(1); // slow down brute force attempts
    $loginError = 'Wrong password.';
}

if ($loggedIn && $action === 'logout') {
    require_csrf();
    $_SESSION = [];
    session_destroy();
    redirect();
}

// ---------------------------------------------------------------- actions
if ($loggedIn && $action !== '') {
    require_csrf();
    try {
        switch ($action) {
            case 'save':
                $id = (int)($_POST['id'] ?? 0);
                $data = posted_common_fields('#ff6b35') + ['path' => trim((string)($_POST['path'] ?? ''))];
                foreach (['start_date', 'end_date'] as $f) {
                    $v = trim((string)($_POST[$f] ?? ''));
                    if ($v !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
                        throw new RuntimeException('Dates must have the format YYYY-MM-DD.');
                    }
                    $data[$f] = $v === '' ? null : $v;
                }
                if ($data['start_date'] && $data['end_date'] && $data['end_date'] < $data['start_date']) {
                    throw new RuntimeException('The end date is before the start date.');
                }
                $uploaded = handle_upload($_FILES['upload'] ?? []);
                if ($uploaded !== null) {
                    $data['path'] = $uploaded;
                }
                if ($data['name'] === '') {
                    $data['name'] = $data['path'] !== '' ? pathinfo($data['path'], PATHINFO_FILENAME) : 'Untitled track';
                }
                if ($data['path'] === '') {
                    throw new RuntimeException('Please enter a path / URL or upload a GPS file.');
                }
                if ($id > 0) {
                    db()->prepare('UPDATE tracks SET name = :name, path = :path, description = :description, image = :image,
                        link = :link, color = :color, visible = :visible, sort_order = :sort_order,
                        start_date = :start_date, end_date = :end_date, cache_key = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = :id')
                        ->execute($data + ['id' => $id]);
                } else {
                    db()->prepare('INSERT INTO tracks (name, path, description, image, link, color, visible, sort_order, start_date, end_date)
                        VALUES (:name, :path, :description, :image, :link, :color, :visible, :sort_order, :start_date, :end_date)')
                        ->execute($data);
                    $id = (int)db()->lastInsertId();
                }
                try {
                    track_geometry(find_track($id), true);
                    flash('Track "' . $data['name'] . '" saved.');
                } catch (Throwable $e) {
                    flash('Track "' . $data['name'] . '" saved, but the GPS file could not be loaded: ' . $e->getMessage(), 'warn');
                }
                break;

            case 'delete':
                db()->prepare('DELETE FROM tracks WHERE id = ?')->execute([(int)$_POST['id']]);
                flash('Track deleted.');
                break;

            case 'toggle':
                db()->prepare('UPDATE tracks SET visible = 1 - visible, updated_at = CURRENT_TIMESTAMP WHERE id = ?')
                    ->execute([(int)$_POST['id']]);
                break;

            case 'reload':
                $ok = $fail = 0;
                foreach (db()->query('SELECT * FROM tracks')->fetchAll() as $t) {
                    try {
                        track_geometry($t, true);
                        $ok++;
                    } catch (Throwable $e) {
                        $fail++;
                    }
                }
                flash("Reloaded $ok track(s)" . ($fail ? ", $fail failed." : '.'), $fail ? 'warn' : 'ok');
                break;

            case 'scan':
                $folder = trim((string)($_POST['folder'] ?? ''));
                $recursive = !empty($_POST['recursive']);
                set_setting('scan_last_dir', $folder);
                set_setting('scan_recursive', $recursive ? '1' : '0');
                $results = scan_folder($folder, $recursive);
                $_SESSION['scan'] = ['folder' => $folder, 'recursive' => $recursive, 'results' => $results];
                $new = count(array_filter($results, fn($r) => !$r['ignored']));
                flash($new ? "Found $new new GPS file(s) – see the suggestions below."
                    : 'No new GPS files found in this folder.', $new ? 'ok' : 'warn');
                redirect('#scan');

            case 'scan_rows':
                $results = $_SESSION['scan']['results'] ?? [];
                [$do, $index] = array_pad(explode(':', (string)($_POST['do'] ?? '')), 2, null);
                $names = $_POST['names'] ?? [];
                $indexes = $do === 'add_selected' ? array_map('intval', (array)($_POST['selected'] ?? [])) : [(int)$index];
                $indexes = array_filter($indexes, fn($i) => isset($results[$i]) && ($do !== 'add_selected' || !$results[$i]['error']));
                if (!$indexes) {
                    throw new RuntimeException('Please select at least one file.');
                }
                $ignored = json_decode(setting('scan_ignored', '[]'), true) ?: [];
                if ($do === 'ignore' || $do === 'unignore') {
                    $path = $results[$indexes[array_key_first($indexes)]]['path'];
                    $ignored = $do === 'ignore' ? array_values(array_unique([...$ignored, $path])) : array_values(array_diff($ignored, [$path]));
                    set_setting('scan_ignored', json_encode($ignored));
                    $_SESSION['scan']['results'][$indexes[array_key_first($indexes)]]['ignored'] = $do === 'ignore';
                    redirect('#scan');
                }
                $count = (int)db()->query('SELECT COUNT(*) FROM tracks')->fetchColumn();
                $order = (int)db()->query('SELECT COALESCE(MAX(sort_order), 0) FROM tracks')->fetchColumn();
                $st = db()->prepare('INSERT INTO tracks (name, path, description, color, sort_order) VALUES (?, ?, ?, ?, ?)');
                $added = 0;
                foreach ($indexes as $i) {
                    $r = $results[$i];
                    if ($r['error']) {
                        continue;
                    }
                    $name = trim((string)($names[$i] ?? '')) ?: $r['name'];
                    $st->execute([$name, $r['path'], $r['description'], TRACK_COLORS[($count + $added) % count(TRACK_COLORS)], $order + $added + 1]);
                    $added++;
                    unset($_SESSION['scan']['results'][$i]);
                    if (in_array($r['path'], $ignored, true)) {
                        set_setting('scan_ignored', json_encode(array_values(array_diff($ignored, [$r['path']]))));
                    }
                }
                flash("Added $added track(s). Edit them to add a preview image, text and article link.");
                redirect('#scan');

            case 'scan_clear':
                unset($_SESSION['scan']);
                redirect('#scan');

            case 'marker_save':
                $id = (int)($_POST['id'] ?? 0);
                $data = posted_common_fields('#e63946');
                [$data['lat'], $data['lng']] = parse_coordinates((string)($_POST['coordinates'] ?? ''));
                if ($data['name'] === '') {
                    $data['name'] = 'Marker';
                }
                if ($id > 0) {
                    db()->prepare('UPDATE markers SET name = :name, lat = :lat, lng = :lng, description = :description,
                        image = :image, link = :link, color = :color, visible = :visible, sort_order = :sort_order,
                        updated_at = CURRENT_TIMESTAMP WHERE id = :id')
                        ->execute($data + ['id' => $id]);
                } else {
                    db()->prepare('INSERT INTO markers (name, lat, lng, description, image, link, color, visible, sort_order)
                        VALUES (:name, :lat, :lng, :description, :image, :link, :color, :visible, :sort_order)')
                        ->execute($data);
                }
                flash('Marker "' . $data['name'] . '" saved.');
                redirect('#markers');

            case 'marker_delete':
                db()->prepare('DELETE FROM markers WHERE id = ?')->execute([(int)$_POST['id']]);
                flash('Marker deleted.');
                redirect('#markers');

            case 'marker_toggle':
                db()->prepare('UPDATE markers SET visible = 1 - visible, updated_at = CURRENT_TIMESTAMP WHERE id = ?')
                    ->execute([(int)$_POST['id']]);
                redirect('#markers');

            case 'password':
                $new = (string)($_POST['new_password'] ?? '');
                if (!check_password((string)($_POST['current_password'] ?? ''))) {
                    throw new RuntimeException('The current password is wrong.');
                }
                if (strlen($new) < 8) {
                    throw new RuntimeException('The new password must have at least 8 characters.');
                }
                if ($new !== ($_POST['new_password2'] ?? '')) {
                    throw new RuntimeException('The new passwords do not match.');
                }
                set_setting('admin_password_hash', password_hash($new, PASSWORD_DEFAULT));
                flash('Password changed.');
                break;
        }
    } catch (Throwable $e) {
        flash($e->getMessage(), 'error');
        if ($action === 'save' || $action === 'marker_save') {
            $_SESSION['form'] = $_POST;
            redirect(($action === 'save' ? 'edit=' : 'marker=') . (int)($_POST['id'] ?? 0));
        }
        if (str_starts_with($action, 'scan')) {
            redirect('#scan');
        }
    }
    redirect();
}

if ($loggedIn && !empty($_GET['edit']) && !find_track((int)$_GET['edit'])) {
    flash('Track not found.', 'error');
    redirect();
}
if ($loggedIn && !empty($_GET['marker']) && !find_marker((int)$_GET['marker'])) {
    flash('Marker not found.', 'error');
    redirect();
}

$flashes = $_SESSION['flash'] ?? [];
unset($_SESSION['flash']);
$csrf = $_SESSION['csrf'];
$title = cfg('site_title') . ' – Configuration';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= h($title) ?></title>
<link rel="stylesheet" href="assets/admin.css">
</head>
<body>
<?php if (!$loggedIn): ?>
<main class="login">
    <form method="post" class="card">
        <h1>Configuration</h1>
        <p class="muted"><?= h(cfg('site_title')) ?></p>
        <?php if (!empty($loginError)): ?><div class="flash error"><?= h($loginError) ?></div><?php endif ?>
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="action" value="login">
        <label>Password <input type="password" name="password" autofocus required autocomplete="current-password"></label>
        <button type="submit" class="primary">Log in</button>
        <a href="index.html" class="muted small">← Back to the globe</a>
    </form>
</main>
<?php else: ?>
<header class="bar">
    <h1><?= h($title) ?></h1>
    <nav>
        <a href="index.html" target="_blank">Open globe ↗</a>
        <form method="post" class="inline">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <button name="action" value="logout" class="link">Log out</button>
        </form>
    </nav>
</header>
<main>
    <?php if (using_default_password()): ?>
        <div class="flash warn">You are using the default password <code>changeme</code>. Please change it below.</div>
    <?php endif ?>
    <?php foreach ($flashes as [$type, $msg]): ?>
        <div class="flash <?= h($type) ?>"><?= h($msg) ?></div>
    <?php endforeach ?>

<?php if (isset($_GET['edit'])):
    $id = (int)$_GET['edit'];
    $t = $id ? find_track($id) : null;
    $t = array_merge([
        'id' => 0, 'name' => '', 'path' => '', 'description' => '', 'image' => '', 'link' => '',
        'color' => '#ff6b35', 'visible' => 1, 'start_date' => '', 'end_date' => '',
        'sort_order' => (int)db()->query('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM tracks')->fetchColumn(),
    ], $t ?? [], $_SESSION['form'] ?? []);
    if (isset($_SESSION['form'])) {
        $t['visible'] = isset($_SESSION['form']['visible']) ? 1 : 0;
    }
    unset($_SESSION['form']);
?>
    <form method="post" enctype="multipart/form-data" class="card edit">
        <h2><?= $id ? 'Edit track' : 'Add track' ?></h2>
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
        <div class="grid">
            <label class="wide">Name
                <input name="name" value="<?= h($t['name']) ?>" placeholder="Shown as title in the hover window">
            </label>
            <label class="wide">GPS file path or URL
                <input name="path" value="<?= h($t['path']) ?>" placeholder="tracks/my-tour.gpx  ·  /srv/gpx/tour.gpx  ·  https://example.com/tour.gpx">
                <small>Relative paths are resolved against <code><?= h(realpath((string)cfg('tracks_base_dir')) ?: cfg('tracks_base_dir')) ?></code>. Supported: GPX, KML, GeoJSON.</small>
            </label>
            <label class="wide">…or upload a GPS file
                <input type="file" name="upload" accept=".gpx,.kml,.geojson,.json">
            </label>
            <label class="wide">WordPress article link
                <input name="link" type="url" value="<?= h($t['link']) ?>" placeholder="https://myblog.example/2024/06/alpine-crossing/">
            </label>
            <label class="check wide"><input type="checkbox" name="fetch_meta" value="1">
                Fill empty name / text / image from the article (Open Graph tags)</label>
            <label class="wide">Preview image URL or path
                <input name="image" value="<?= h($t['image']) ?>" placeholder="https://myblog.example/wp-content/uploads/…/cover.jpg">
            </label>
            <label class="wide">…or upload a preview image
                <input type="file" name="image_upload" accept="image/jpeg,image/png,image/gif,image/webp">
            </label>
            <label class="wide">Text
                <textarea name="description" rows="4"><?= h($t['description']) ?></textarea>
            </label>
            <?php $fileTimes = $t['cache_json'] ?? null ? json_decode($t['cache_json'], true) : []; ?>
            <label>Start date <input type="date" name="start_date" value="<?= h($t['start_date']) ?>"></label>
            <label>End date <input type="date" name="end_date" value="<?= h($t['end_date']) ?>"></label>
            <p class="small muted field-note">Leave empty to use the timestamps from the GPS file<?php
                if (!empty($fileTimes['start_time'])): ?> (<?= h(substr($fileTimes['start_time'], 0, 10)) ?><?=
                    substr($fileTimes['start_time'], 0, 10) !== substr((string)$fileTimes['end_time'], 0, 10) ? ' – ' . h(substr((string)$fileTimes['end_time'], 0, 10)) : '' ?>)<?php
                else: ?> (none found)<?php endif ?>.</p>
            <label>Color <input type="color" name="color" value="<?= h($t['color']) ?>"></label>
            <label>Sort order <input type="number" name="sort_order" value="<?= (int)$t['sort_order'] ?>"></label>
            <label class="check"><input type="checkbox" name="visible" value="1" <?= $t['visible'] ? 'checked' : '' ?>> Visible on globe</label>
        </div>
        <div class="actions">
            <button name="action" value="save" class="primary">Save</button>
            <a href="admin.php" class="button">Cancel</a>
        </div>
    </form>
<?php elseif (isset($_GET['marker'])):
    $id = (int)$_GET['marker'];
    $m = array_merge([
        'id' => 0, 'name' => '', 'lat' => '', 'lng' => '', 'description' => '', 'image' => '', 'link' => '',
        'color' => '#e63946', 'visible' => 1,
        'sort_order' => (int)db()->query('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM markers')->fetchColumn(),
    ], ($id ? find_marker($id) : null) ?? []);
    $m['coordinates'] = $m['lat'] !== '' ? $m['lat'] . ', ' . $m['lng'] : '';
    if (isset($_SESSION['form'])) {
        $m = array_merge($m, $_SESSION['form']);
        $m['visible'] = isset($_SESSION['form']['visible']) ? 1 : 0;
    }
    unset($_SESSION['form']);
?>
    <form method="post" enctype="multipart/form-data" class="card edit">
        <h2><?= $id ? 'Edit marker' : 'Add marker' ?></h2>
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
        <div class="grid">
            <label class="wide">Name
                <input name="name" value="<?= h($m['name']) ?>" placeholder="Shown as title in the hover window">
            </label>
            <label class="wide">GPS coordinates
                <input name="coordinates" value="<?= h($m['coordinates']) ?>" required
                       placeholder="47.4058, 10.2793   or   47°24'20.9&quot;N 10°16'45.5&quot;E">
                <small>Decimal degrees (latitude, longitude) or degrees/minutes/seconds, e.g. copied from Google Maps or OpenStreetMap.</small>
            </label>
            <label class="wide">Text
                <textarea name="description" rows="4"><?= h($m['description']) ?></textarea>
            </label>
            <label class="wide">Preview image URL or path
                <input name="image" value="<?= h($m['image']) ?>" placeholder="https://myblog.example/wp-content/uploads/…/photo.jpg">
            </label>
            <label class="wide">…or upload a preview image
                <input type="file" name="image_upload" accept="image/jpeg,image/png,image/gif,image/webp">
            </label>
            <label class="wide">Link (optional, e.g. WordPress article)
                <input name="link" type="url" value="<?= h($m['link']) ?>" placeholder="https://myblog.example/2024/06/summit/">
            </label>
            <label class="check wide"><input type="checkbox" name="fetch_meta" value="1">
                Fill empty name / text / image from the linked article (Open Graph tags)</label>
            <label>Color <input type="color" name="color" value="<?= h($m['color']) ?>"></label>
            <label>Sort order <input type="number" name="sort_order" value="<?= (int)$m['sort_order'] ?>"></label>
            <label class="check"><input type="checkbox" name="visible" value="1" <?= $m['visible'] ? 'checked' : '' ?>> Visible on globe</label>
        </div>
        <div class="actions">
            <button name="action" value="marker_save" class="primary">Save</button>
            <a href="admin.php#markers" class="button">Cancel</a>
        </div>
    </form>
<?php else:
    $tracks = db()->query('SELECT * FROM tracks ORDER BY sort_order, name')->fetchAll();
    // Make sure status columns are current (cheap: uses the cache when files are unchanged).
    foreach ($tracks as &$row) {
        try {
            $geo = track_geometry($row);
            $row['distance_km'] = $geo['distance_km'];
            $start = $row['start_date'] ?: ($row['end_date'] ?: substr((string)($geo['start_time'] ?? ''), 0, 10));
            $end = $row['end_date'] ?: ($row['start_date'] ?: substr((string)($geo['end_time'] ?? ''), 0, 10));
            $row['date_label'] = $start === '' ? '' : ($start === $end || $end === '' ? $start : "$start – $end");
            $row['point_count'] = array_sum(array_map('count', $geo['segments']));
            $row['last_error'] = null;
        } catch (Throwable $e) {
            $row['last_error'] = $e->getMessage();
            $row['date_label'] = '';
        }
    }
    unset($row);

    $markers = db()->query('SELECT * FROM markers ORDER BY sort_order, name')->fetchAll();
    $scan = $_SESSION['scan'] ?? null;
    $scanRows = $scan['results'] ?? [];
    // Offer the sub folders of the base directory as suggestions for the scan form.
    $folderSuggestions = [];
    $baseDir = realpath((string)cfg('tracks_base_dir'));
    if ($baseDir) {
        foreach (glob($baseDir . '/*', GLOB_ONLYDIR) ?: [] as $d) {
            $n = basename($d);
            if (!in_array($n, ['assets', 'lib', 'data', 'media', '.git'], true)) {
                $folderSuggestions[] = $n;
                foreach (glob($d . '/*', GLOB_ONLYDIR) ?: [] as $sub) {
                    $folderSuggestions[] = $n . '/' . basename($sub);
                }
            }
        }
    }
?>
    <section class="card">
        <div class="section-head">
            <h2>GPS tracks <span class="muted">(<?= count($tracks) ?>)</span></h2>
            <div class="actions">
                <form method="post" class="inline">
                    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                    <button name="action" value="reload" title="Re-read all GPS files">↻ Reload files</button>
                </form>
                <a href="admin.php?edit=0" class="button primary">+ Add track</a>
            </div>
        </div>
        <div class="table-wrap">
        <table>
            <thead>
            <tr>
                <th>#</th><th>Visible</th><th>Track</th><th>GPS file</th><th>Status</th>
                <th>Preview</th><th>Text</th><th>Article</th><th></th>
            </tr>
            </thead>
            <tbody>
            <?php if (!$tracks): ?>
                <tr><td colspan="9" class="muted center">No tracks yet. Click “Add track”.</td></tr>
            <?php endif ?>
            <?php foreach ($tracks as $t): ?>
                <tr class="<?= $t['visible'] ? '' : 'hidden-row' ?>">
                    <td><?= (int)$t['sort_order'] ?></td>
                    <td>
                        <form method="post" class="inline">
                            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                            <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                            <button name="action" value="toggle" class="toggle <?= $t['visible'] ? 'on' : '' ?>"
                                    title="<?= $t['visible'] ? 'Hide' : 'Show' ?>"><?= $t['visible'] ? 'On' : 'Off' ?></button>
                        </form>
                    </td>
                    <td><span class="swatch" style="background: <?= h($t['color']) ?>"></span><strong><?= h($t['name']) ?></strong></td>
                    <td><code class="path"><?= h($t['path']) ?></code></td>
                    <td>
                        <?php if ($t['last_error']): ?>
                            <span class="badge error" title="<?= h($t['last_error']) ?>">Error</span>
                            <div class="small error-text"><?= h($t['last_error']) ?></div>
                        <?php else: ?>
                            <span class="badge ok">OK</span>
                            <div class="small muted"><?= number_format((float)$t['distance_km'], 1) ?> km · <?= (int)$t['point_count'] ?> pts</div>
                            <?php if ($t['date_label']): ?><div class="small muted nowrap"><?= h($t['date_label']) ?></div><?php endif ?>
                        <?php endif ?>
                    </td>
                    <td><?php if ($t['image']): ?><img src="<?= h($t['image']) ?>" alt="" class="thumb" loading="lazy"><?php endif ?></td>
                    <td class="text"><?= h(mb_strimwidth($t['description'], 0, 120, '…')) ?></td>
                    <td><?php if ($t['link']): ?><a href="<?= h($t['link']) ?>" target="_blank" rel="noopener" title="<?= h($t['link']) ?>">Open ↗</a><?php endif ?></td>
                    <td class="nowrap">
                        <a href="index.html#track=<?= (int)$t['id'] ?>" target="_blank" title="Show on globe">🌍</a>
                        <a href="admin.php?edit=<?= (int)$t['id'] ?>" class="button small">Edit</a>
                        <form method="post" class="inline" onsubmit="return confirm('Delete this track?')">
                            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                            <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                            <button name="action" value="delete" class="small danger">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
        </div>
    </section>

    <section class="card" id="scan">
        <div class="section-head">
            <h2>Scan folder for GPS files</h2>
        </div>
        <form method="post" class="scan-form">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <label class="grow">Folder on the server
                <input name="folder" required value="<?= h($scan['folder'] ?? setting('scan_last_dir', (string)cfg('scan_default_dir'))) ?>"
                       placeholder="tracks   ·   /srv/gpx" list="folder-list">
                <datalist id="folder-list">
                    <?php foreach ($folderSuggestions as $f): ?><option value="<?= h($f) ?>"><?php endforeach ?>
                </datalist>
                <small>Relative to <code><?= h(realpath((string)cfg('tracks_base_dir')) ?: cfg('tracks_base_dir')) ?></code> or absolute. Finds .gpx and .kml files that are not yet in the track list.</small>
            </label>
            <label class="check"><input type="checkbox" name="recursive" value="1"
                <?= ($scan['recursive'] ?? setting('scan_recursive', '1') === '1') ? 'checked' : '' ?>> Include subfolders</label>
            <button name="action" value="scan" class="primary">Scan</button>
        </form>

        <?php if ($scan !== null): ?>
            <h3>Suggestions <span class="muted">(<?= count($scanRows) ?> file<?= count($scanRows) === 1 ? '' : 's' ?> in <code><?= h($scan['folder']) ?></code>)</span></h3>
            <?php if (!$scanRows): ?>
                <p class="muted">No new GPS files found.</p>
            <?php else: ?>
            <form method="post">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                <input type="hidden" name="action" value="scan_rows">
                <div class="actions" style="margin-bottom: .75rem">
                    <button name="do" value="add_selected" class="primary">Add selected as tracks</button>
                    <label class="check inline-check"><input type="checkbox" data-select-all> Select all</label>
                </div>
                <div class="table-wrap">
                <table>
                    <thead><tr><th></th><th>Suggested name</th><th>File</th><th>Track</th><th>Modified</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($scanRows as $i => $r): ?>
                        <tr class="<?= $r['ignored'] ? 'hidden-row' : '' ?>">
                            <td><input type="checkbox" name="selected[]" value="<?= (int)$i ?>" <?= $r['error'] ? 'disabled' : '' ?> aria-label="Select"></td>
                            <td><input name="names[<?= (int)$i ?>]" value="<?= h($r['name']) ?>" class="compact" aria-label="Name"></td>
                            <td><code class="path"><?= h($r['path']) ?></code>
                                <div class="small muted"><?= number_format($r['size'] / 1024, 0) ?> KB</div></td>
                            <td>
                                <?php if ($r['error']): ?>
                                    <span class="badge error">Error</span><div class="small error-text"><?= h($r['error']) ?></div>
                                <?php else: ?>
                                    <?= number_format((float)$r['distance_km'], 1) ?> km
                                    <div class="small muted"><?= (int)$r['points'] ?> pts</div>
                                <?php endif ?>
                            </td>
                            <td class="nowrap small"><?= date('Y-m-d', $r['mtime']) ?></td>
                            <td class="nowrap">
                                <?php if (!$r['error']): ?><button name="do" value="add:<?= (int)$i ?>" class="small">Add</button><?php endif ?>
                                <?php if ($r['ignored']): ?>
                                    <button name="do" value="unignore:<?= (int)$i ?>" class="small" title="Suggest this file again">Unignore</button>
                                <?php else: ?>
                                    <button name="do" value="ignore:<?= (int)$i ?>" class="small" title="Do not suggest this file again">Ignore</button>
                                <?php endif ?>
                            </td>
                        </tr>
                    <?php endforeach ?>
                    </tbody>
                </table>
                </div>
            </form>
            <?php endif ?>
            <form method="post" class="inline">
                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                <button name="action" value="scan_clear" class="link small">Clear scan results</button>
            </form>
        <?php endif ?>
    </section>

    <section class="card" id="markers">
        <div class="section-head">
            <h2>Markers <span class="muted">(<?= count($markers) ?>)</span></h2>
            <a href="admin.php?marker=0" class="button primary">+ Add marker</a>
        </div>
        <div class="table-wrap">
        <table>
            <thead>
            <tr><th>#</th><th>Visible</th><th>Marker</th><th>Coordinates</th><th>Preview</th><th>Text</th><th>Link</th><th></th></tr>
            </thead>
            <tbody>
            <?php if (!$markers): ?>
                <tr><td colspan="8" class="muted center">No markers yet. Click “Add marker” to place a single point by its GPS coordinates.</td></tr>
            <?php endif ?>
            <?php foreach ($markers as $m): ?>
                <tr class="<?= $m['visible'] ? '' : 'hidden-row' ?>">
                    <td><?= (int)$m['sort_order'] ?></td>
                    <td>
                        <form method="post" class="inline">
                            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                            <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
                            <button name="action" value="marker_toggle" class="toggle <?= $m['visible'] ? 'on' : '' ?>"><?= $m['visible'] ? 'On' : 'Off' ?></button>
                        </form>
                    </td>
                    <td><span class="swatch" style="background: <?= h($m['color']) ?>"></span><strong><?= h($m['name']) ?></strong></td>
                    <td class="nowrap"><code><?= h(sprintf('%.5f, %.5f', $m['lat'], $m['lng'])) ?></code></td>
                    <td><?php if ($m['image']): ?><img src="<?= h($m['image']) ?>" alt="" class="thumb" loading="lazy"><?php endif ?></td>
                    <td class="text"><?= h(mb_strimwidth($m['description'], 0, 120, '…')) ?></td>
                    <td><?php if ($m['link']): ?><a href="<?= h($m['link']) ?>" target="_blank" rel="noopener" title="<?= h($m['link']) ?>">Open ↗</a><?php endif ?></td>
                    <td class="nowrap">
                        <a href="index.html#marker=<?= (int)$m['id'] ?>" target="_blank" title="Show on globe">🌍</a>
                        <a href="admin.php?marker=<?= (int)$m['id'] ?>" class="button small">Edit</a>
                        <form method="post" class="inline" onsubmit="return confirm('Delete this marker?')">
                            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                            <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
                            <button name="action" value="marker_delete" class="small danger">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
        </div>
    </section>

    <section class="card narrow">
        <h2>Change password</h2>
        <form method="post">
            <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
            <label>Current password <input type="password" name="current_password" required autocomplete="current-password"></label>
            <label>New password (min. 8 characters) <input type="password" name="new_password" required minlength="8" autocomplete="new-password"></label>
            <label>Repeat new password <input type="password" name="new_password2" required minlength="8" autocomplete="new-password"></label>
            <button name="action" value="password" class="primary">Change password</button>
        </form>
    </section>
<?php endif ?>
</main>
<script>
document.querySelectorAll('[data-select-all]').forEach(function (box) {
    box.addEventListener('change', function () {
        box.form.querySelectorAll('input[name="selected[]"]:not(:disabled)').forEach(function (c) { c.checked = box.checked; });
    });
});
</script>
<?php endif ?>
</body>
</html>
