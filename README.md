# Globe Link

An interactive 3D globe that shows your GPS tracks and single markers. Hovering a
track or marker opens a small window with a preview image, the travel dates, a short
text and a link to the matching WordPress article. Everything is managed in a
password-protected configuration view.

- **Globe:** drag to rotate, scroll or pinch to zoom. Satellite map tiles load in more
  detail as you zoom in.
- **Tracks:** GPX (tracks, routes, waypoints), KML and GeoJSON, loaded from **relative
  paths**, **absolute paths** or **http(s) URLs**.
- **Markers:** single points placed by GPS coordinates, each with its own preview
  text, picture and optional link.
- **Hover window:** preview image, title, start and end day (only one day if the
  trip started and ended on the same day), distance, text and a "Read article →" link.
  Clicking a track or marker pins the window. Click the globe or press Esc to close it.
- **Track list:** the "Tracks" button lists all tracks and flies to the one you pick.
  You can deep-link to a track or marker with `index.html#track=<id>` or `index.html#marker=<id>`.
- **Configuration view:** `admin.php`, password-protected.
  - **GPS tracks table:** every track with its status (OK/error, distance, points,
    dates). You can add, edit, hide/show and delete tracks, upload GPS files and
    preview images, and fill the name, text and image from a WordPress article's
    Open Graph tags.
  - **Scan folder:** pick a folder on the server, optionally with its subfolders. All
    `.gpx`/`.kml` files that are not configured yet appear in a second table of
    suggestions, with a suggested name (from the file's `<name>`, or else the file
    name), distance and point count. You can edit the names, add files one at a time
    or select several, and hide files you don't want with "Ignore".
  - **Markers table:** add a single point by pasting its coordinates, for example
    `47.4058, 10.2793`, `47°24'20.9"N 10°16'45.5"E` or `47° 24.348' N, 10° 16.758' E`.
    Then set a preview text, a picture (URL or upload) and an optional link.

## Requirements

- PHP 8.1 or newer with `pdo_sqlite` and `simplexml` (both are enabled by default on most hosts)
- A web server that runs PHP (Apache, nginx + PHP-FPM, or `php -S` for local testing)

## Installation

1. Copy the files to your web server, for example to `https://example.com/globe/`.
2. Make `data/` and `tracks/uploads/` writable by the web server. The SQLite
   database `data/globe.sqlite` is created on the first request, together with three
   example tracks.
3. Open `admin.php`, log in with the default password **`changeme`** and change it
   right away. The new password is stored as a hash in the database.
4. Open `index.html` to see the globe.

Local test:

```bash
php -S localhost:8000
# http://localhost:8000/index.html  and  http://localhost:8000/admin.php
```

## Configuration

`config.php` holds all settings: site title, database location, base directory for
relative track paths, map tiles, upload directory and maximum points per track. To
change settings without editing that file, create `config.local.php`, which git
ignores:

```php
<?php
return [
    'site_title'      => 'My Travels',
    'db_path'         => '/var/lib/globe/globe.sqlite',  // outside the web root
    'tracks_base_dir' => '/srv/gpx',                       // base for relative paths
];
```

### Track paths

| Example                          | Resolved as                                        |
|----------------------------------|----------------------------------------------------|
| `tracks/2024/alps.gpx`           | relative to `tracks_base_dir` (default: app folder) |
| `/srv/gpx/iceland.gpx`           | absolute path on the server                         |
| `C:\gpx\tour.gpx`                | absolute path (Windows)                             |
| `https://example.com/tour.gpx`   | downloaded, then re-checked at most once an hour   |

### Travel dates

The start and end day come from the point timestamps in the GPS file: GPX `<time>`,
KML `<when>`, or GeoJSON time properties. The file creation time in a GPX file's
`<metadata>` is ignored. If a file has no timestamps, or you want other dates, enter a
start and end date in the track's edit form. Dates entered there take precedence.

### Caching

Parsed tracks are simplified and cached in SQLite. A cached track is re-read
automatically when the file's modification time or size changes. "↻ Reload files"
in the configuration view forces all tracks to be re-read.

### Map tiles

By default the globe uses Esri World Imagery tiles. Set `tile_url` to another
`{z}/{x}/{y}` template, for example OpenStreetMap, and update `tile_attribution`.
Follow the tile provider's usage terms. If you set `tile_url` to `''`, the globe
uses a static NASA Blue Marble texture.

## Embedding in WordPress

Put the globe in a page or post with an iframe, for example in a "Custom HTML" block:

```html
<iframe src="https://example.com/globe/index.html" style="width:100%;height:600px;border:0" loading="lazy"></iframe>
```

## Security notes

- The `.htaccess` files block web access to `data/`, `lib/` and the config files on
  Apache. They also stop scripts from running in the upload folders `media/` and
  `tracks/uploads/`. Uploaded images are checked to be real JPG, PNG, GIF or WebP files. On nginx, add equivalent `deny` rules, or move the database outside the
  web root with `db_path`.
- The admin uses PHP sessions (HttpOnly, SameSite=Strict cookies), CSRF tokens on every
  form, password hashing and a one-second delay after each failed login.

## Files

```
index.html          globe page (static HTML, loads assets/globe.js)
api.php             JSON endpoint with all visible tracks
admin.php           password-protected configuration view
config.php          settings (override in config.local.php)
lib/bootstrap.php   database, GPX/KML/GeoJSON parsing, caching
assets/             JS/CSS and example preview images
tracks/examples/    example GPX files
tracks/uploads/     GPS files uploaded in the configuration view
media/              preview images uploaded in the configuration view
data/               SQLite database (created automatically)
```
