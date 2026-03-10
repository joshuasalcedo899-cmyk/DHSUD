<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized'
    ]);
    exit;
}

const JRS_TRACK_CACHE_DIR = __DIR__ . '/../cache/jrs-track';
const JRS_TRACK_METRICS_PATH = JRS_TRACK_CACHE_DIR . '/metrics.json';

function readJrsTrackMetricsSnapshot() {
    if (!is_file(JRS_TRACK_METRICS_PATH)) {
        return [];
    }
    $raw = @file_get_contents(JRS_TRACK_METRICS_PATH);
    if ($raw === false || trim($raw) === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function countFilesByPattern($pattern) {
    $matches = glob($pattern);
    return is_array($matches) ? count($matches) : 0;
}

$metrics = readJrsTrackMetricsSnapshot();

echo json_encode([
    'success' => true,
    'cacheDir' => JRS_TRACK_CACHE_DIR,
    'metrics' => [
        'cache_hit' => (int)($metrics['cache_hit'] ?? 0),
        'cache_miss' => (int)($metrics['cache_miss'] ?? 0),
        'coalesced_hit' => (int)($metrics['coalesced_hit'] ?? 0),
        'upstream_error' => (int)($metrics['upstream_error'] ?? 0),
        'lock_stale_reset' => (int)($metrics['lock_stale_reset'] ?? 0),
        'updated_at' => (string)($metrics['updated_at'] ?? ''),
    ],
    'files' => [
        'cache_entries' => countFilesByPattern(JRS_TRACK_CACHE_DIR . '/*.json') - (is_file(JRS_TRACK_METRICS_PATH) ? 1 : 0),
        'lock_files' => countFilesByPattern(JRS_TRACK_CACHE_DIR . '/*.lock'),
    ],
], JSON_UNESCAPED_SLASHES);
