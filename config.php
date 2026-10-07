<?php
/**
 * Globe Link – configuration.
 *
 * To override values without touching this file, create config.local.php
 * (ignored by git) that returns an array with only the keys you want to change:
 *   <?php return ['admin_password' => 'secret', 'site_title' => 'My Trips'];
 */
$config = [
    // Page title shown in the browser tab and the header of the globe page.
    'site_title' => 'Travel Globe',

    // SQLite database file. Keep it outside the web root if you can.
    'db_path' => __DIR__ . '/data/globe.sqlite',

    // Base directory used to resolve *relative* GPS track paths.
    // Absolute paths (/srv/gpx/tour.gpx, C:\gpx\tour.gpx) and http(s) URLs are used as-is.
    'tracks_base_dir' => __DIR__,

    // Directory where GPX files uploaded via the configuration view are stored.
    'upload_dir' => __DIR__ . '/tracks/uploads',

    // Preview images uploaded in the configuration view (must be reachable from the web).
    'media_dir' => __DIR__ . '/media',
    'media_url' => 'media',

    // Folder suggested in the "Scan folder" form (relative to tracks_base_dir or absolute).
    'scan_default_dir' => 'tracks',

    // Initial admin password. Change it after the first login in the
    // configuration view (the new password is stored hashed in the database
    // and then takes precedence over this value).
    'admin_password' => 'changeme',

    // Maximum number of points per track sent to the browser (tracks are simplified).
    'max_points_per_track' => 1500,

    // Map tiles used when zooming in. {z}/{x}/{y} are replaced.
    // Set to '' to only use the static earth texture.
    'tile_url'         => 'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}',
    'tile_attribution' => 'Imagery © Esri, Maxar, Earthstar Geographics',
    'tile_max_level'   => 17,

    // Static earth texture (used when tile_url is empty) and background.
    'globe_image'      => 'https://unpkg.com/three-globe@2.45.3/example/img/earth-blue-marble.jpg',
    'bump_image'       => 'https://unpkg.com/three-globe@2.45.3/example/img/earth-topology.png',
    'background_image' => 'https://unpkg.com/three-globe@2.45.3/example/img/night-sky.png',

    // Open article links in a new tab.
    'link_target_blank' => true,
];

if (is_file(__DIR__ . '/config.local.php')) {
    $local = require __DIR__ . '/config.local.php';
    if (is_array($local)) {
        $config = array_replace($config, $local);
    }
}

return $config;
