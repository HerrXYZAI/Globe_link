<?php
/**
 * Shared helpers: configuration, database, track loading and parsing.
 */
declare(strict_types=1);

const APP_ROOT = __DIR__ . '/..';

function cfg(?string $key = null)
{
    static $config = null;
    if ($config === null) {
        $config = require APP_ROOT . '/config.php';
    }
    return $key === null ? $config : ($config[$key] ?? null);
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }
    $path = cfg('db_path');
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $pdo = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('CREATE TABLE IF NOT EXISTS tracks (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        name        TEXT    NOT NULL,
        path        TEXT    NOT NULL,
        description TEXT    NOT NULL DEFAULT \'\',
        image       TEXT    NOT NULL DEFAULT \'\',
        link        TEXT    NOT NULL DEFAULT \'\',
        color       TEXT    NOT NULL DEFAULT \'#ff6b35\',
        visible     INTEGER NOT NULL DEFAULT 1,
        sort_order  INTEGER NOT NULL DEFAULT 0,
        cache_key   TEXT,
        cache_json  TEXT,
        point_count INTEGER,
        distance_km REAL,
        last_error  TEXT,
        created_at  TEXT    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at  TEXT    NOT NULL DEFAULT CURRENT_TIMESTAMP
    )');
    // Columns added after the first release.
    $cols = array_column($pdo->query('PRAGMA table_info(tracks)')->fetchAll(), 'name');
    foreach (['start_date' => 'TEXT', 'end_date' => 'TEXT'] as $col => $type) {
        if (!in_array($col, $cols, true)) {
            $pdo->exec("ALTER TABLE tracks ADD COLUMN $col $type");
        }
    }
    $pdo->exec('CREATE TABLE IF NOT EXISTS markers (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        name        TEXT    NOT NULL,
        lat         REAL    NOT NULL,
        lng         REAL    NOT NULL,
        description TEXT    NOT NULL DEFAULT \'\',
        image       TEXT    NOT NULL DEFAULT \'\',
        link        TEXT    NOT NULL DEFAULT \'\',
        color       TEXT    NOT NULL DEFAULT \'#e63946\',
        visible     INTEGER NOT NULL DEFAULT 1,
        sort_order  INTEGER NOT NULL DEFAULT 0,
        created_at  TEXT    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at  TEXT    NOT NULL DEFAULT CURRENT_TIMESTAMP
    )');
    $pdo->exec('CREATE TABLE IF NOT EXISTS settings (
        key   TEXT PRIMARY KEY,
        value TEXT NOT NULL
    )');
    if (setting('seeded') === null) {
        seed_examples($pdo);
        set_setting('seeded', '1');
    }
    return $pdo;
}

/** Insert the bundled example tracks on first start so the globe is not empty. */
function seed_examples(PDO $pdo): void
{
    if ((int)$pdo->query('SELECT COUNT(*) FROM tracks')->fetchColumn() > 0) {
        return;
    }
    $examples = [
        ['Alpine crossing Oberstdorf – Meran', 'tracks/examples/alpine-crossing.gpx',
         'Six days on foot across the Alps on the E5 long-distance trail.', 'assets/examples/alpine.svg', '#ff6b35'],
        ['Iceland Ring Road', 'tracks/examples/iceland-ring-road.gpx',
         'Around the island on Route 1: glaciers, waterfalls and black beaches.', 'assets/examples/iceland.svg', '#4cc9f0'],
        ['Pacific Coast Highway', 'tracks/examples/pacific-coast.gpx',
         'From San Francisco to Los Angeles along Highway 1 and Big Sur.', 'assets/examples/pacific.svg', '#f9c74f'],
    ];
    $st = $pdo->prepare('INSERT INTO tracks (name, path, description, image, link, color, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?)');
    foreach ($examples as $i => [$name, $path, $text, $img, $color]) {
        $st->execute([$name, $path, $text, $img, 'https://wordpress.org/', $color, $i + 1]);
    }
}

function setting(string $key, ?string $default = null): ?string
{
    $st = db()->prepare('SELECT value FROM settings WHERE key = ?');
    $st->execute([$key]);
    $v = $st->fetchColumn();
    return $v === false ? $default : $v;
}

function set_setting(string $key, string $value): void
{
    db()->prepare('INSERT INTO settings (key, value) VALUES (?, ?)
                   ON CONFLICT(key) DO UPDATE SET value = excluded.value')
        ->execute([$key, $value]);
}

function h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function is_url(string $path): bool
{
    return (bool)preg_match('#^https?://#i', $path);
}

/** Resolve a track path (absolute, relative to tracks_base_dir, or URL). */
function resolve_track_path(string $path): string
{
    $path = trim($path);
    if ($path === '' || is_url($path)) {
        return $path;
    }
    $isAbsolute = $path[0] === '/' || $path[0] === '\\' || preg_match('#^[A-Za-z]:[\\\\/]#', $path);
    if ($isAbsolute) {
        return $path;
    }
    return rtrim((string)cfg('tracks_base_dir'), '/\\') . DIRECTORY_SEPARATOR . $path;
}

function http_get(string $url, int $timeout = 15): string
{
    $ctx = stream_context_create([
        'http' => [
            'timeout' => $timeout,
            'follow_location' => 1,
            'user_agent' => 'GlobeLink/1.0 (+track loader)',
        ],
    ]);
    $data = @file_get_contents($url, false, $ctx);
    if ($data === false) {
        throw new RuntimeException('Could not download ' . $url);
    }
    return $data;
}

/** A key that changes whenever the underlying file changes. */
function track_cache_key(string $path): string
{
    $resolved = resolve_track_path($path);
    if (is_url($resolved)) {
        // Remote files are re-fetched at most once per hour.
        return 'v2:url:' . $resolved . ':' . floor(time() / 3600);
    }
    if (!is_file($resolved)) {
        return 'missing:' . $resolved;
    }
    return 'v2:file:' . $resolved . ':' . filemtime($resolved) . ':' . filesize($resolved)
        . ':' . cfg('max_points_per_track');
}

/**
 * Parse a GPS file into a list of segments, each a list of [lat, lng] pairs.
 * Supports GPX (tracks, routes), KML and GeoJSON.
 */
function parse_track_data(string $data): array
{
    $trim = ltrim($data);
    if ($trim !== '' && ($trim[0] === '{' || $trim[0] === '[')) {
        return parse_geojson(json_decode($trim, true, 512, JSON_THROW_ON_ERROR));
    }

    $prev = libxml_use_internal_errors(true);
    $xml = simplexml_load_string($data, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
    libxml_use_internal_errors($prev);
    if ($xml === false) {
        throw new RuntimeException('File is neither valid XML (GPX/KML) nor GeoJSON');
    }
    $root = strtolower($xml->getName());
    $segments = [];

    if ($root === 'gpx') {
        $ns = $xml->getNamespaces(true)[''] ?? null;
        $px = $ns ? 'g:' : '';
        if ($ns) {
            $xml->registerXPathNamespace('g', $ns);
        }
        $segNodes = $xml->xpath("//{$px}trkseg") ?: [];
        $routeNodes = $xml->xpath("//{$px}rte") ?: [];
        $ptName = "{$px}trkpt";
        $rtName = "{$px}rtept";
        foreach ([[$segNodes, $ptName], [$routeNodes, $rtName]] as [$nodes, $pt]) {
            foreach ($nodes as $node) {
                if ($ns) {
                    $node->registerXPathNamespace('g', $ns);
                }
                $seg = [];
                foreach ($node->xpath($pt) ?: [] as $p) {
                    $seg[] = [(float)$p['lat'], (float)$p['lon']];
                }
                if (count($seg) > 1) {
                    $segments[] = $seg;
                }
            }
        }
        if (!$segments) {
            // Fall back to waypoints if there are no tracks/routes.
            $wpts = $xml->xpath("//{$px}wpt") ?: [];
            $seg = [];
            foreach ($wpts as $p) {
                $seg[] = [(float)$p['lat'], (float)$p['lon']];
            }
            if (count($seg) > 1) {
                $segments[] = $seg;
            }
        }
    } elseif ($root === 'kml') {
        // <coordinates>lng,lat[,alt] lng,lat[,alt] ...</coordinates> and gx:coord "lng lat alt"
        if (preg_match_all('#<coordinates>(.*?)</coordinates>#s', $data, $m)) {
            foreach ($m[1] as $block) {
                $seg = [];
                foreach (preg_split('/\s+/', trim($block)) as $tuple) {
                    $c = explode(',', $tuple);
                    if (count($c) >= 2) {
                        $seg[] = [(float)$c[1], (float)$c[0]];
                    }
                }
                if (count($seg) > 1) {
                    $segments[] = $seg;
                }
            }
        }
        if (preg_match_all('#<gx:Track>(.*?)</gx:Track>#s', $data, $tm)) {
            foreach ($tm[1] as $block) {
                preg_match_all('#<gx:coord>\s*([-\d.eE]+)\s+([-\d.eE]+)#', $block, $cm, PREG_SET_ORDER);
                $seg = array_map(fn($c) => [(float)$c[2], (float)$c[1]], $cm);
                if (count($seg) > 1) {
                    $segments[] = $seg;
                }
            }
        }
    } else {
        throw new RuntimeException('Unsupported XML format <' . $root . '>');
    }

    if (!$segments) {
        throw new RuntimeException('No track points found');
    }
    return $segments;
}

function parse_geojson(array $g): array
{
    $segments = [];
    $addLine = function (array $coords) use (&$segments) {
        $seg = [];
        foreach ($coords as $c) {
            if (is_array($c) && count($c) >= 2) {
                $seg[] = [(float)$c[1], (float)$c[0]];
            }
        }
        if (count($seg) > 1) {
            $segments[] = $seg;
        }
    };
    $walk = function (array $g) use (&$walk, $addLine) {
        switch ($g['type'] ?? '') {
            case 'FeatureCollection':
                foreach ($g['features'] ?? [] as $f) {
                    $walk($f);
                }
                break;
            case 'Feature':
                if (!empty($g['geometry'])) {
                    $walk($g['geometry']);
                }
                break;
            case 'GeometryCollection':
                foreach ($g['geometries'] ?? [] as $x) {
                    $walk($x);
                }
                break;
            case 'LineString':
                $addLine($g['coordinates'] ?? []);
                break;
            case 'MultiLineString':
            case 'Polygon':
                foreach ($g['coordinates'] ?? [] as $line) {
                    $addLine($line);
                }
                break;
            case 'MultiPolygon':
                foreach ($g['coordinates'] ?? [] as $poly) {
                    foreach ($poly as $line) {
                        $addLine($line);
                    }
                }
                break;
        }
    };
    $walk($g);
    return $segments;
}

function haversine_km(array $a, array $b): float
{
    $r = 6371.0088;
    $dLat = deg2rad($b[0] - $a[0]);
    $dLng = deg2rad($b[1] - $a[1]);
    $x = sin($dLat / 2) ** 2 + cos(deg2rad($a[0])) * cos(deg2rad($b[0])) * sin($dLng / 2) ** 2;
    return 2 * $r * asin(min(1.0, sqrt($x)));
}

/** Simplify segments so that the total point count stays below $maxPoints. */
function simplify_segments(array $segments, int $maxPoints): array
{
    $total = array_sum(array_map('count', $segments));
    $length = 0.0;
    foreach ($segments as $seg) {
        for ($i = 1, $n = count($seg); $i < $n; $i++) {
            $length += haversine_km($seg[$i - 1], $seg[$i]);
        }
    }
    if ($total <= $maxPoints || $length <= 0) {
        $out = $segments;
    } else {
        // Keep a point only when it is at least $step km away from the last kept one.
        $step = $length / $maxPoints;
        $out = [];
        foreach ($segments as $seg) {
            $kept = [$seg[0]];
            $last = $seg[0];
            $n = count($seg);
            for ($i = 1; $i < $n - 1; $i++) {
                if (haversine_km($last, $seg[$i]) >= $step) {
                    $kept[] = $last = $seg[$i];
                }
            }
            $kept[] = $seg[$n - 1];
            $out[] = $kept;
        }
    }
    // Round to ~1 m precision to keep the JSON small.
    $out = array_map(fn($seg) => array_map(fn($p) => [round($p[0], 5), round($p[1], 5)], $seg), $out);
    return ['segments' => $out, 'distance_km' => round($length, 2)];
}

/**
 * First and last timestamp of the recorded points (GPX <time>, KML <when>,
 * GeoJSON coordTimes/time) as ISO 8601 UTC strings, or null when the file has none.
 */
function track_time_range(string $data): array
{
    $stamps = [];
    $trim = ltrim($data);
    if ($trim !== '' && ($trim[0] === '{' || $trim[0] === '[')) {
        $json = json_decode($trim, true);
        array_walk_recursive($json, function ($v, $k) use (&$stamps) {
            if (is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}T/', $v)) {
                $stamps[] = $v;
            }
        });
    } else {
        // Ignore the file creation time in <metadata>, use only the point timestamps.
        $body = preg_replace('#<metadata\b.*?</metadata>#s', '', $data);
        preg_match_all('#<(?:time|when)>\s*([^<]+?)\s*</(?:time|when)>#', $body, $m);
        $stamps = $m[1];
    }
    $min = $max = null;
    foreach ($stamps as $t) {
        $ts = strtotime($t);
        if ($ts === false) {
            continue;
        }
        $min = $min === null ? $ts : min($min, $ts);
        $max = $max === null ? $ts : max($max, $ts);
    }
    return [
        'start_time' => $min === null ? null : gmdate('Y-m-d\TH:i:s\Z', $min),
        'end_time' => $max === null ? null : gmdate('Y-m-d\TH:i:s\Z', $max),
    ];
}

/**
 * Return the (cached) geometry of a track row:
 * ['segments' => [[[lat,lng],...],...], 'distance_km' => float, 'start_time' => ?string, 'end_time' => ?string]
 * or throws.
 */
function track_geometry(array $track, bool $force = false): array
{
    $key = track_cache_key($track['path']);
    if (!$force && $track['cache_key'] === $key && $track['cache_json']) {
        return json_decode($track['cache_json'], true);
    }
    try {
        $resolved = resolve_track_path($track['path']);
        if ($resolved === '') {
            throw new RuntimeException('No path configured');
        }
        if (is_url($resolved)) {
            $data = http_get($resolved);
        } else {
            if (!is_file($resolved) || !is_readable($resolved)) {
                throw new RuntimeException('File not found or not readable: ' . $resolved);
            }
            $data = file_get_contents($resolved);
        }
        $geo = simplify_segments(parse_track_data($data), (int)cfg('max_points_per_track'))
            + track_time_range($data);
        $points = array_sum(array_map('count', $geo['segments']));
        db()->prepare('UPDATE tracks SET cache_key = ?, cache_json = ?, point_count = ?, distance_km = ?, last_error = NULL WHERE id = ?')
            ->execute([$key, json_encode($geo), $points, $geo['distance_km'], $track['id']]);
        return $geo;
    } catch (Throwable $e) {
        db()->prepare('UPDATE tracks SET cache_key = NULL, cache_json = NULL, point_count = NULL, last_error = ? WHERE id = ?')
            ->execute([$e->getMessage(), $track['id']]);
        throw $e;
    }
}

/**
 * Read Open Graph metadata (title, description, image) from a WordPress article.
 */
function fetch_article_meta(string $url): array
{
    $html = http_get($url, 10);
    $meta = [];
    if (preg_match_all('#<meta\s[^>]*>#i', $html, $tags)) {
        foreach ($tags[0] as $tag) {
            if (preg_match('#(?:property|name)\s*=\s*["\'](og:title|og:description|og:image|description)["\']#i', $tag, $p)
                && preg_match('#content\s*=\s*(["\'])(.*?)\1#is', $tag, $c)) {
                $meta[strtolower($p[1])] ??= html_entity_decode($c[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }
    }
    if (empty($meta['og:title']) && preg_match('#<title[^>]*>(.*?)</title>#is', $html, $t)) {
        $meta['og:title'] = html_entity_decode(trim($t[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    return [
        'title' => trim($meta['og:title'] ?? ''),
        'description' => trim($meta['og:description'] ?? $meta['description'] ?? ''),
        'image' => trim($meta['og:image'] ?? ''),
    ];
}

/**
 * Parse GPS coordinates typed by a person. Accepts
 *   "47.4058, 10.2793"   "47.4058 10.2793"   "N 47.4058 E 10.2793"
 *   "47°24'20.9\"N 10°16'45.5\"E"   "47° 24.348' N, 10° 16.758' E"
 * Returns [lat, lng] or throws.
 */
function parse_coordinates(string $input): array
{
    $s = trim(str_replace(['′', '’', '″', '“', '”', "''"], ["'", "'", '"', '"', '"', '"'], $input));
    $s = str_replace(',', ' , ', $s);
    // Decimal comma ("47,4058; 10,2793") -> decimal point.
    if (str_contains($input, ';')) {
        $s = trim(str_replace([' , ', ';'], [',', ' '], $s));
        $s = preg_replace('/(\d),(\d)/', '$1.$2', $s);
    }
    $re = '/([NSEWO])?\s*(-?\d+(?:\.\d+)?)\s*°?\s*(?:(\d+(?:\.\d+)?)\s*\'\s*)?(?:(\d+(?:\.\d+)?)\s*"\s*)?([NSEWO])?/iu';
    preg_match_all($re, $s, $m, PREG_SET_ORDER);
    $values = [];
    foreach ($m as $g) {
        if (($g[2] ?? '') === '') {
            continue;
        }
        $v = abs((float)$g[2]) + (float)($g[3] ?? 0) / 60 + (float)($g[4] ?? 0) / 3600;
        $hemi = strtoupper(($g[1] ?? '') !== '' ? $g[1] : ($g[5] ?? ''));
        $neg = str_starts_with($g[2], '-') || $hemi === 'S' || $hemi === 'W';
        $values[] = ['v' => $neg ? -$v : $v, 'h' => $hemi];
    }
    if (count($values) !== 2) {
        throw new RuntimeException('Could not read the coordinates. Example: 47.4058, 10.2793');
    }
    [$a, $b] = $values;
    // "E 10 N 47" -> swap
    if (in_array($a['h'], ['E', 'W', 'O'], true) || in_array($b['h'], ['N', 'S'], true)) {
        [$a, $b] = [$b, $a];
    }
    [$lat, $lng] = [$a['v'], $b['v']];
    if (abs($lat) > 90 || abs($lng) > 180) {
        throw new RuntimeException('Coordinates out of range (latitude ±90, longitude ±180).');
    }
    return [round($lat, 7), round($lng, 7)];
}

/** Path to store in the database: relative to tracks_base_dir when inside it, else absolute. */
function storable_path(string $file): string
{
    $real = realpath($file) ?: $file;
    $base = realpath((string)cfg('tracks_base_dir'));
    if ($base && str_starts_with($real, $base . DIRECTORY_SEPARATOR)) {
        return str_replace('\\', '/', substr($real, strlen($base) + 1));
    }
    return $real;
}

/** Name / description stored inside a GPX or KML file (first one found). */
function track_file_meta(string $data): array
{
    $meta = ['name' => '', 'description' => ''];
    if (preg_match('#<(?:metadata|trk|rte|Document|Folder|Placemark)\b[^>]*>\s*(?:<[^>]+>\s*)*?<name>\s*(?:<!\[CDATA\[)?(.*?)(?:\]\]>)?\s*</name>#s', $data, $m)) {
        $meta['name'] = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_XML1, 'UTF-8'));
    }
    if (preg_match('#<(desc|description)>\s*(?:<!\[CDATA\[)?(.*?)(?:\]\]>)?\s*</\1>#s', $data, $m)) {
        $meta['description'] = mb_strimwidth(trim(html_entity_decode(strip_tags($m[2]), ENT_QUOTES | ENT_XML1, 'UTF-8')), 0, 500, '…');
    }
    return $meta;
}

/** Turn "2024-06_alpen-ueberquerung_E5" into "2024 06 Alpen Ueberquerung E5". */
function name_from_filename(string $path): string
{
    $name = trim(preg_replace('/[\s_\-.]+/', ' ', pathinfo($path, PATHINFO_FILENAME)));
    return $name === '' ? 'Untitled track' : mb_convert_case($name, MB_CASE_TITLE, 'UTF-8');
}

/**
 * Scan a folder for GPS files that are not yet configured as tracks.
 * Returns a list of suggestions (path, name, description, size, mtime, distance, points, error).
 */
function scan_folder(string $folder, bool $recursive): array
{
    $dir = resolve_track_path($folder);
    if (is_url($dir) || !is_dir($dir)) {
        throw new RuntimeException('Folder not found: ' . $dir);
    }
    if (!is_readable($dir)) {
        throw new RuntimeException('Folder is not readable: ' . $dir);
    }

    $known = [];
    foreach (db()->query('SELECT path FROM tracks')->fetchAll(PDO::FETCH_COLUMN) as $p) {
        $r = resolve_track_path($p);
        $known[realpath($r) ?: $r] = true;
    }
    $ignored = array_flip(json_decode(setting('scan_ignored', '[]'), true) ?: []);

    $it = $recursive
        ? new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS),
            RecursiveIteratorIterator::LEAVES_ONLY, RecursiveIteratorIterator::CATCH_GET_CHILD)
        : new FilesystemIterator($dir, FilesystemIterator::SKIP_DOTS);

    $found = [];
    $max = 500;
    foreach ($it as $file) {
        /** @var SplFileInfo $file */
        if (!$file->isFile() || !in_array(strtolower($file->getExtension()), ['gpx', 'kml'], true)) {
            continue;
        }
        $real = $file->getRealPath() ?: $file->getPathname();
        if (isset($known[$real])) {
            continue;
        }
        $stored = storable_path($real);
        $row = [
            'path' => $stored,
            'ignored' => isset($ignored[$stored]),
            'name' => name_from_filename($real),
            'description' => '',
            'size' => $file->getSize(),
            'mtime' => $file->getMTime(),
            'distance_km' => null,
            'points' => null,
            'error' => null,
        ];
        try {
            $data = file_get_contents($real);
            $meta = track_file_meta($data);
            if ($meta['name'] !== '') {
                $row['name'] = $meta['name'];
            }
            $row['description'] = $meta['description'];
            $geo = simplify_segments(parse_track_data($data), (int)cfg('max_points_per_track'));
            $row['distance_km'] = $geo['distance_km'];
            $row['points'] = array_sum(array_map('count', $geo['segments']));
        } catch (Throwable $e) {
            $row['error'] = $e->getMessage();
        }
        $found[] = $row;
        if (count($found) >= $max) {
            break;
        }
    }
    usort($found, fn($a, $b) => [$a['ignored'], $a['path']] <=> [$b['ignored'], $b['path']]);
    return $found;
}

/** Store an uploaded preview image in the media folder and return its URL path. */
function handle_image_upload(array $file): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Image upload failed (error code ' . $file['error'] . ')');
    }
    $types = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
    $info = @getimagesize($file['tmp_name']);
    $ext = $types[$info['mime'] ?? ''] ?? null;
    if ($ext === null) {
        throw new RuntimeException('Only JPG, PNG, GIF and WebP images are allowed');
    }
    $dir = rtrim((string)cfg('media_dir'), '/\\');
    if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
        throw new RuntimeException('Media directory cannot be created: ' . $dir);
    }
    $base = preg_replace('/[^A-Za-z0-9_-]+/', '-', pathinfo($file['name'], PATHINFO_FILENAME)) ?: 'image';
    $name = $base . '.' . $ext;
    for ($i = 2; file_exists("$dir/$name"); $i++) {
        $name = "$base-$i.$ext";
    }
    if (!move_uploaded_file($file['tmp_name'], "$dir/$name")) {
        throw new RuntimeException('Could not store the uploaded image');
    }
    return rtrim((string)cfg('media_url'), '/') . '/' . $name;
}
