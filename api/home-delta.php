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

function safeText($value) {
    return trim((string)$value);
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
    ];
    return $deptMap[$deptKey];
}

function parseVersionParts($rawVersion) {
    $parts = explode(':', trim((string)$rawVersion));
    return [
        'row_count' => (int)($parts[0] ?? 0),
        'max_id' => (int)($parts[1] ?? 0),
        'latest_update_ts' => (int)($parts[2] ?? 0),
    ];
}

try {
    $deptScope = resolveDepartmentScope($_GET['dept'] ?? 'emes');
    $previousVersion = parseVersionParts($_GET['previous_version'] ?? '');
    $scope = buildMailtrackingDepartmentScope($_GET['dept'] ?? 'emes');
    $baseWhereSql = "FROM `mailtracking`
            WHERE {$scope['sql']}";
    $scopeParams = $scope['params'];
    $hasUpdatedAt = mailtrackingHasUpdatedAt();

    $statusOptions = ['DELIVERED', 'RETURNED TO SENDER', 'ONGOING DELIVERY', 'PERSONALLY RECEIVED'];
    $statusCounts = array_fill_keys($statusOptions, 0);
    $statusCounts['Unassigned'] = 0;
    $statusCounts['Other'] = 0;

    $versionLatestUpdateSql = $hasUpdatedAt
        ? 'COALESCE(UNIX_TIMESTAMP(MAX(`updated_at`)), 0)'
        : '0';
    $versionSql = "SELECT
                COUNT(*) AS row_count,
                COALESCE(MAX(`id`), 0) AS max_id,
                {$versionLatestUpdateSql} AS latest_update_ts
            " . $baseWhereSql;
    $versionStmt = $pdo->prepare($versionSql);
    $versionStmt->execute($scopeParams);
    $versionRow = $versionStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $currentRowCount = (int)($versionRow['row_count'] ?? 0);
    $currentMaxId = (int)($versionRow['max_id'] ?? 0);
    $currentLatestUpdateTs = (int)($versionRow['latest_update_ts'] ?? 0);
    $version = (string)$currentRowCount . ':' . (string)$currentMaxId . ':' . (string)$currentLatestUpdateTs;

    $selectCols = "SELECT
                `id`,
                `Notice/Order Code`,
                `Date released to AFD`,
                `Parcel No.`,
                `Recipient Details`,
                `Parcel Details`,
                `Sender Details`,
                `File Name (PDF)`,
                `Tracking No.`,
                `Status`,
                `Transmittal ID`,
                `Transmittal Remarks/Received By`,
                `Date`,
                `Evaluator`";
    if ($hasUpdatedAt) {
        $selectCols .= ",
                `updated_at` ";
    } else {
        $selectCols .= ' ';
    }

    $mode = 'full';
    $rows = [];

    if ($hasUpdatedAt
        && $previousVersion['row_count'] > 0
        && $previousVersion['row_count'] === $currentRowCount
        && $previousVersion['max_id'] === $currentMaxId
        && $previousVersion['latest_update_ts'] > 0) {
        $patchSql = $selectCols
            . $baseWhereSql
            . " AND UNIX_TIMESTAMP(`updated_at`) >= :since_ts
                ORDER BY `id` ASC";
        $patchStmt = $pdo->prepare($patchSql);
        $patchParams = $scopeParams;
        $patchParams[':since_ts'] = $previousVersion['latest_update_ts'];
        $patchStmt->execute($patchParams);
        $rows = $patchStmt->fetchAll(PDO::FETCH_ASSOC);
        $mode = 'patch';
    } else {
        $rowSql = $selectCols
            . $baseWhereSql
            . " ORDER BY `Sender Details` ASC, `id` ASC";
        $rowStmt = $pdo->prepare($rowSql);
        $rowStmt->execute($scopeParams);
        $rows = $rowStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $years = [];
    foreach ($rows as $r) {
        $status = safeText($r['Status'] ?? '');
        if ($status === '') {
            $statusCounts['Unassigned']++;
        } elseif (in_array($status, $statusOptions, true)) {
            $statusCounts[$status]++;
        } else {
            $statusCounts['Other']++;
        }

        $dateAfd = safeText($r['Date released to AFD'] ?? '');
        if ($dateAfd !== '' && preg_match('/(\d{4})/', $dateAfd, $m)) {
            $years[] = $m[1];
        }
    }

    $years = array_values(array_unique($years));
    rsort($years);

    $totalCount = count($rows);
    $rts = (int)($statusCounts['RETURNED TO SENDER'] ?? 0);
    $ogd = (int)($statusCounts['ONGOING DELIVERY'] ?? 0);
    $ndrPercent = ($totalCount > 0) ? round((($rts + $ogd) / $totalCount) * 100, 1) : 0;

    echo json_encode([
        'success' => true,
        'mode' => $mode,
        'version' => $version,
        'rows' => $rows,
        'stats' => [
            'returnedToSender' => $rts,
            'ongoingDelivery' => $ogd,
            'delivered' => (int)($statusCounts['DELIVERED'] ?? 0),
            'total' => (int)$totalCount,
            'ndrPercent' => $ndrPercent
        ],
        'years' => $years
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to load delta payload'
    ]);
}

