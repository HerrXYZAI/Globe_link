<?php
/**
 * Export the globe as a static website (no PHP needed), e.g. for GitHub Pages.
 *
 *   php tools/build-static.php [output-dir]      (default: _site)
 *
 * The export is a snapshot of the current configuration: all visible tracks and
 * markers are written to api.json, and index.html, assets/ and media/ are copied.
 * The configuration view (admin.php) is not part of the export.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require __DIR__ . '/../lib/bootstrap.php';

$root = realpath(APP_ROOT);
$out = $argv[1] ?? $root . '/_site';
if (!is_dir($out) && !mkdir($out, 0775, true)) {
    fwrite(STDERR, "Cannot create $out\n");
    exit(1);
}
$out = realpath($out);
if ($out === $root) {
    fwrite(STDERR, "The output directory must not be the application folder.\n");
    exit(1);
}

function copy_tree(string $from, string $to): void
{
    if (!is_dir($from)) {
        return;
    }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if (in_array($file->getFilename(), ['.htaccess', '.gitkeep'], true)) {
            continue;
        }
        $target = $to . substr($file->getPathname(), strlen($from));
        if (!is_dir(dirname($target))) {
            mkdir(dirname($target), 0775, true);
        }
        copy($file->getPathname(), $target);
    }
}

$payload = globe_payload();

// Page: same HTML, but reading the exported JSON instead of api.php.
$html = file_get_contents($root . '/index.html');
$html = str_replace('<meta name="globe-link-data" content="api.php">', '<meta name="globe-link-data" content="api.json">', $html, $replaced);
if ($replaced !== 1) {
    fwrite(STDERR, "index.html: data source meta tag not found\n");
    exit(1);
}
file_put_contents($out . '/index.html', $html);
file_put_contents($out . '/api.json', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
file_put_contents($out . '/.nojekyll', '');

copy_tree($root . '/assets', $out . '/assets');
copy_tree($root . '/media', $out . '/media');

// Preview images referenced by a relative path somewhere else inside the application folder.
foreach (array_merge($payload['tracks'], $payload['markers']) as $item) {
    $img = $item['image'];
    if ($img === '' || is_url($img) || str_starts_with($img, '/') || str_starts_with($img, 'data:')) {
        continue;
    }
    $src = realpath($root . '/' . $img);
    if ($src && str_starts_with($src, $root . DIRECTORY_SEPARATOR) && is_file($src)
        && !preg_match('/\.(php|sqlite)$/i', $src) && !is_file($out . '/' . $img)) {
        @mkdir(dirname($out . '/' . $img), 0775, true);
        copy($src, $out . '/' . $img);
    }
}

printf("Exported %d track(s) and %d marker(s) to %s\n", count($payload['tracks']), count($payload['markers']), $out);
