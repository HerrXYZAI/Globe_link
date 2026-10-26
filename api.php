<?php
/**
 * Public JSON endpoint used by the globe page.
 *   GET api.php            -> settings + all visible tracks (with geometry) and markers
 */
declare(strict_types=1);
require __DIR__ . '/lib/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');

try {
    echo json_encode(globe_payload(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    // Typical causes: data/ not writable, wrong db_path, missing pdo_sqlite extension.
    error_log('Globe Link api.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
