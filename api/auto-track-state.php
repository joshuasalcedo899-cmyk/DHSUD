<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth.php';

header('Content-Type: application/json');

function ensureAutoTrackStateTable($pdo) {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS auto_track_state (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            dept_key VARCHAR(20) NOT NULL,
            state_key VARCHAR(190) NOT NULL,
            last_run_ms BIGINT UNSIGNED NOT NULL DEFAULT 0,
            interval_ms BIGINT UNSIGNED NOT NULL DEFAULT 0,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_dept_state (dept_key, state_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function normalizeDeptParam($value) {
    return normalizeDepartmentKey($value);
}

try {
    ensureAutoTrackStateTable($pdo);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to initialize auto-track state.']);
    exit;
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($method === 'GET') {
    $deptKey = normalizeDeptParam($_GET['dept'] ?? '');
    $stmt = $pdo->prepare("SELECT state_key, last_run_ms, interval_ms FROM auto_track_state WHERE dept_key = :dept");
    $stmt->execute([':dept' => $deptKey]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $payload = [];
    foreach ($rows as $row) {
        $key = (string)($row['state_key'] ?? '');
        if ($key === '') continue;
        $payload[$key] = [
            'ts' => (int)($row['last_run_ms'] ?? 0),
            'interval' => (int)($row['interval_ms'] ?? 0),
        ];
    }
    echo json_encode(['success' => true, 'data' => $payload]);
    exit;
}

if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid payload.']);
        exit;
    }

    $deptKey = normalizeDeptParam($data['dept'] ?? '');
    $items = $data['items'] ?? [];
    if (!is_array($items) || $deptKey === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Missing department or items.']);
        exit;
    }

    $stmt = $pdo->prepare("
        INSERT INTO auto_track_state (dept_key, state_key, last_run_ms, interval_ms)
        VALUES (:dept, :state_key, :last_run_ms, :interval_ms)
        ON DUPLICATE KEY UPDATE
            last_run_ms = VALUES(last_run_ms),
            interval_ms = VALUES(interval_ms)
    ");

    $saved = 0;
    foreach ($items as $item) {
        $stateKey = trim((string)($item['key'] ?? ''));
        if ($stateKey === '') continue;
        $lastRunMs = (int)($item['ts'] ?? 0);
        $intervalMs = (int)($item['interval'] ?? 0);
        $stmt->execute([
            ':dept' => $deptKey,
            ':state_key' => $stateKey,
            ':last_run_ms' => max(0, $lastRunMs),
            ':interval_ms' => max(0, $intervalMs),
        ]);
        $saved += 1;
    }

    echo json_encode(['success' => true, 'saved' => $saved]);
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
exit;
