<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

function resolveDepartmentScope($deptKeyRaw) {
    $deptKey = normalizeDepartmentKey($deptKeyRaw);
    $deptMap = [
        'emes' => ['code' => 'EMES', 'sender' => getDepartmentSenderTag('emes')],
        'prls' => ['code' => 'PRLS', 'sender' => getDepartmentSenderTag('prls')],
        'afd' => ['code' => 'AFD', 'sender' => getDepartmentSenderTag('afd')],
        'phsd' => ['code' => 'PHSD', 'sender' => getDepartmentSenderTag('phsd')],
        'elupd' => ['code' => 'ELUPD', 'sender' => getDepartmentSenderTag('elupd')],
        'ord' => ['code' => 'ORD', 'sender' => getDepartmentSenderTag('ord')],
        'hoa' => ['code' => 'HOA', 'sender' => getDepartmentSenderTag('hoa')],
        'lo' => ['code' => 'LO', 'sender' => getDepartmentSenderTag('lo')],
        'philpost' => ['code' => 'PHILPOST', 'sender' => getDepartmentSenderTag('philpost')],
    ];
    return $deptMap[$deptKey];
}

try {
    $deptScope = resolveDepartmentScope($_GET['dept'] ?? 'emes');
    $scope = buildMailtrackingDepartmentScope($_GET['dept'] ?? 'emes');
    $hasUpdatedAt = mailtrackingHasUpdatedAt();
    $latestUpdateSql = $hasUpdatedAt
        ? 'COALESCE(UNIX_TIMESTAMP(MAX(`updated_at`)), 0)'
        : '0';

    $sql = "SELECT
                COUNT(*) AS row_count,
                COALESCE(MAX(`id`), 0) AS max_id,
                {$latestUpdateSql} AS latest_update_ts
            FROM `mailtracking`
            WHERE {$scope['sql']}";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($scope['params']);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $rowCount = (string)($row['row_count'] ?? '0');
    $maxId = (string)($row['max_id'] ?? '0');
    $latestUpdateTs = (string)($row['latest_update_ts'] ?? '0');
    $version = $rowCount . ':' . $maxId . ':' . $latestUpdateTs;

    echo json_encode([
        'success' => true,
        'version' => $version
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to compute version'
    ]);
}

