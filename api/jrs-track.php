<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth.php';

const JRS_TRACK_CACHE_DIR = __DIR__ . '/../cache/jrs-track';
const JRS_TRACK_DEFAULT_TTL = 1800;   // 30 minutes
const JRS_TRACK_TERMINAL_TTL = 86400; // 24 hours
const JRS_TRACK_ERROR_TTL = 120;      // 2 minutes
const JRS_TRACK_STALE_LOCK_SECONDS = 30;

function ensureJrsTrackCacheDir() {
    if (!is_dir(JRS_TRACK_CACHE_DIR)) {
        @mkdir(JRS_TRACK_CACHE_DIR, 0777, true);
    }
    return is_dir(JRS_TRACK_CACHE_DIR);
}

function buildJrsTrackCachePath($tracking) {
    $normalized = strtoupper(trim((string)$tracking));
    return JRS_TRACK_CACHE_DIR . '/' . sha1($normalized) . '.json';
}

function buildJrsTrackLockPath($tracking) {
    $normalized = strtoupper(trim((string)$tracking));
    return JRS_TRACK_CACHE_DIR . '/' . sha1($normalized) . '.lock';
}

function getJrsTrackMetricsPath() {
    return JRS_TRACK_CACHE_DIR . '/metrics.json';
}

function loadJrsTrackCache($tracking) {
    $path = buildJrsTrackCachePath($tracking);
    if (!is_file($path)) {
        return null;
    }
    $raw = @file_get_contents($path);
    if ($raw === false || trim($raw) === '') {
        return null;
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}

function saveJrsTrackCache($tracking, array $payload) {
    if (!ensureJrsTrackCacheDir()) {
        return false;
    }
    $path = buildJrsTrackCachePath($tracking);
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return false;
    }
    return @file_put_contents($path, $json, LOCK_EX) !== false;
}

function incrementJrsTrackMetric($name) {
    if (!ensureJrsTrackCacheDir()) {
        return false;
    }
    $allowed = ['cache_hit', 'cache_miss', 'coalesced_hit', 'upstream_error', 'lock_stale_reset'];
    if (!in_array($name, $allowed, true)) {
        return false;
    }

    $path = getJrsTrackMetricsPath();
    $handle = @fopen($path, 'c+');
    if ($handle === false) {
        return false;
    }

    $ok = false;
    if (@flock($handle, LOCK_EX)) {
        $raw = stream_get_contents($handle);
        $data = json_decode($raw ?: '{}', true);
        if (!is_array($data)) {
            $data = [];
        }
        $data[$name] = (int)($data[$name] ?? 0) + 1;
        $data['updated_at'] = date('c');
        rewind($handle);
        ftruncate($handle, 0);
        $encoded = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($encoded !== false) {
            $ok = (@fwrite($handle, $encoded) !== false);
        }
        @flock($handle, LOCK_UN);
    }
    @fclose($handle);
    return $ok;
}

function flattenJrsTrackValues($value, array &$bucket) {
    if (is_array($value)) {
        foreach ($value as $item) {
            flattenJrsTrackValues($item, $bucket);
        }
        return;
    }
    if (is_string($value) || is_numeric($value)) {
        $bucket[] = strtoupper(trim((string)$value));
    }
}

function hasTerminalJrsStatus($decodedResponse) {
    if (!is_array($decodedResponse)) {
        return false;
    }
    $values = [];
    flattenJrsTrackValues($decodedResponse, $values);
    if (empty($values)) {
        return false;
    }
    foreach ($values as $value) {
        if ($value === '') {
            continue;
        }
        if (
            strpos($value, 'DELIVERED') !== false ||
            strpos($value, 'RETURNED TO SENDER') !== false ||
            strpos($value, 'RTS') !== false ||
            strpos($value, 'RETURN TO SHIPPER') !== false ||
            strpos($value, 'RETURN TO ORIGIN') !== false ||
            strpos($value, 'RECEIVED') !== false
        ) {
            return true;
        }
    }
    return false;
}

function acquireJrsTrackLock($tracking) {
    if (!ensureJrsTrackCacheDir()) {
        return [null, false];
    }
    $path = buildJrsTrackLockPath($tracking);
    clearstatcache(true, $path);
    if (is_file($path)) {
        $mtime = @filemtime($path);
        if ($mtime && (time() - (int)$mtime) > JRS_TRACK_STALE_LOCK_SECONDS) {
            @unlink($path);
            incrementJrsTrackMetric('lock_stale_reset');
        }
    }
    $handle = @fopen($path, 'c+');
    if ($handle === false) {
        return [null, false];
    }

    $locked = @flock($handle, LOCK_EX);
    if (!$locked) {
        @fclose($handle);
        return [null, false];
    }

    @ftruncate($handle, 0);
    @fwrite($handle, (string)time());
    @fflush($handle);

    return [$handle, true];
}

function releaseJrsTrackLock($handle) {
    if (!is_resource($handle)) {
        return;
    }
    @flock($handle, LOCK_UN);
    @fclose($handle);
}

if (!isset($_GET['tracking'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing tracking number']);
    exit;
}

$tracking = trim((string)($_GET['tracking'] ?? ''));
if ($tracking === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Missing tracking number']);
    exit;
}

$now = time();
$cached = loadJrsTrackCache($tracking);
if (is_array($cached)) {
    $expiresAt = (int)($cached['expires_at'] ?? 0);
    $cachedBody = (string)($cached['body'] ?? '');
    if ($expiresAt > $now && $cachedBody !== '') {
        incrementJrsTrackMetric('cache_hit');
        header('Content-Type: application/json');
        header('X-JRS-Cache: HIT');
        echo $cachedBody;
        exit;
    }
}

list($lockHandle, $lockAcquired) = acquireJrsTrackLock($tracking);
if (!$lockAcquired) {
    http_response_code(503);
    echo json_encode(['error' => 'Tracking temporarily busy']);
    exit;
}

try {
    $cached = loadJrsTrackCache($tracking);
    if (is_array($cached)) {
        $expiresAt = (int)($cached['expires_at'] ?? 0);
        $cachedBody = (string)($cached['body'] ?? '');
        if ($expiresAt > $now && $cachedBody !== '') {
            incrementJrsTrackMetric('coalesced_hit');
            header('Content-Type: application/json');
            header('X-JRS-Cache: HIT');
            header('X-JRS-Coalesced: 1');
            echo $cachedBody;
            releaseJrsTrackLock($lockHandle);
            exit;
        }
    }

    $url = "https://jrs-core-api.azurewebsites.net/api/Tracking/v1/track-airbill?airbill=" . urlencode($tracking);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => ['Accept: application/json']
    ]);

    $response = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http !== 200 || !$response) {
        incrementJrsTrackMetric('upstream_error');
        saveJrsTrackCache($tracking, [
            'tracking' => strtoupper($tracking),
            'body' => json_encode(['error' => 'Tracking unavailable']),
            'is_error' => true,
            'cached_at' => $now,
            'expires_at' => $now + JRS_TRACK_ERROR_TTL,
        ]);
        http_response_code(500);
        echo json_encode(['error' => 'Tracking unavailable']);
        releaseJrsTrackLock($lockHandle);
        exit;
    }

    $decodedResponse = json_decode($response, true);
    $ttl = hasTerminalJrsStatus($decodedResponse) ? JRS_TRACK_TERMINAL_TTL : JRS_TRACK_DEFAULT_TTL;
    incrementJrsTrackMetric('cache_miss');
    saveJrsTrackCache($tracking, [
        'tracking' => strtoupper($tracking),
        'body' => $response,
        'is_error' => false,
        'cached_at' => $now,
        'expires_at' => $now + $ttl,
    ]);

    header('Content-Type: application/json');
    header('X-JRS-Cache: MISS');
    echo $response; // JSON array
    releaseJrsTrackLock($lockHandle);
    exit;
} catch (Throwable $e) {
    releaseJrsTrackLock($lockHandle);
    throw $e;
}
