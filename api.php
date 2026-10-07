<?php
/**
 * Public JSON endpoint used by the globe page.
 *   GET api.php            -> settings + all visible tracks (with geometry) and markers
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');

$tracks = [];
$rows = db()->query('SELECT * FROM tracks WHERE visible = 1 ORDER BY sort_order, name')->fetchAll();
foreach ($rows as $row) {
    try {
        $geo = track_geometry($row);
    } catch (Throwable $e) {
        continue; // Broken tracks are listed with their error in the configuration view.
    }
    // Manually entered dates win over the timestamps found in the GPS file.
    if ($row['start_date'] || $row['end_date']) {
        $start = $row['start_date'] ?: $row['end_date'];
        $end = $row['end_date'] ?: $start;
    } else {
        $start = isset($geo['start_time']) ? substr($geo['start_time'], 0, 10) : null;
        $end = isset($geo['end_time']) ? substr($geo['end_time'], 0, 10) : null;
    }
    $tracks[] = [
        'id' => (int)$row['id'],
        'name' => $row['name'],
        'description' => $row['description'],
        'image' => $row['image'],
        'link' => $row['link'],
        'color' => $row['color'],
        'distance_km' => $geo['distance_km'],
        'start_date' => $start,
        'end_date' => $end,
        'segments' => $geo['segments'],
    ];
}

$markers = [];
foreach (db()->query('SELECT * FROM markers WHERE visible = 1 ORDER BY sort_order, name')->fetchAll() as $row) {
    $markers[] = [
        'id' => (int)$row['id'],
        'name' => $row['name'],
        'description' => $row['description'],
        'image' => $row['image'],
        'link' => $row['link'],
        'color' => $row['color'],
        'lat' => (float)$row['lat'],
        'lng' => (float)$row['lng'],
    ];
}

echo json_encode([
    'title' => cfg('site_title'),
    'tileUrl' => cfg('tile_url'),
    'tileAttribution' => cfg('tile_attribution'),
    'tileMaxLevel' => (int)cfg('tile_max_level'),
    'globeImage' => cfg('globe_image'),
    'bumpImage' => cfg('bump_image'),
    'backgroundImage' => cfg('background_image'),
    'linkTargetBlank' => (bool)cfg('link_target_blank'),
    'tracks' => $tracks,
    'markers' => $markers,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
