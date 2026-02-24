<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();
}

const JRS_MANUAL_MIN_INTERVAL_SECONDS = 300;   // 5 minutes
const JRS_BACKOFF_MAX_SECONDS = 1800;          // 30 minutes
const JRS_JITTER_RATIO = 0.10;                 // +/-10%

function extractBatchIdFromSenderDetails($senderDetails) {
    $text = trim((string)$senderDetails);
    if ($text === '') return '';
    if (preg_match('/Batch ID:\s*([A-Za-z0-9\-]+)/i', $text, $m)) {
        return trim($m[1]);
    }
    return '';
}

function normalizeStatusText($statusText, $hasReturnToOrigin) {
    $statusUpper = strtoupper(trim((string)$statusText));
    if ($hasReturnToOrigin || $statusUpper === 'RTS RECEIVED' || $statusUpper === 'INCOMPLETE ADDRESS') {
        return 'RETURNED TO SENDER';
    }
    if ($statusUpper === 'DELIVERED TO' || $statusUpper === 'DELIVERED') {
        return 'DELIVERED';
    }
    if ($statusUpper === 'OUT FOR DELIVERY') {
        return 'ONGOING DELIVERY';
    }
    return 'ONGOING DELIVERY';
}

function isTerminalStatus($statusText) {
    $s = strtoupper(trim((string)$statusText));
    return in_array($s, [
        'DELIVERED',
        'RETURNED TO SENDER',
        'PERSONALLY RECEIVED',
        'CANCELLED',
        'CANCELED',
    ], true);
}

function parseDateToTimestamp($value) {
    $text = trim((string)$value);
    if ($text === '' || $text === '0000-00-00' || $text === '0000-00-00 00:00:00') {
        return null;
    }
    $ts = strtotime($text);
    return ($ts === false) ? null : $ts;
}

function buildDefaultPdfFileName($dateReleasedValue, $parcelNoValue) {
    $ts = parseDateToTimestamp($dateReleasedValue);
    if (!$ts) return '';
    $formattedDate = date('ymd', $ts);
    $formattedParcelNo = sprintf('%03d', (int)$parcelNoValue);
    return 'EMES-' . $formattedDate . '-' . $formattedParcelNo;
}

function computeAdaptiveIntervalSeconds($shipmentStartTs, $nowTs) {
    if (!$shipmentStartTs || $shipmentStartTs > $nowTs) {
        return 2 * 60 * 60;
    }

    $ageSeconds = max(0, $nowTs - $shipmentStartTs);
    if ($ageSeconds <= (6 * 60 * 60)) {
        return 45 * 60; // 30-60 minute window target
    }
    if ($ageSeconds <= (24 * 60 * 60)) {
        return 2 * 60 * 60;
    }
    return 4 * 60 * 60;
}

function applyJitterSeconds($baseSeconds, $ratio = JRS_JITTER_RATIO) {
    $base = max(60, (int)$baseSeconds);
    $delta = (int)round($base * max(0, (float)$ratio));
    if ($delta <= 0) {
        return $base;
    }
    $offset = random_int(-$delta, $delta);
    return max(60, $base + $offset);
}

function getPollingStatePath() {
    return __DIR__ . '/../cache/jrs_polling_state.json';
}

function loadPollingState() {
    $path = getPollingStatePath();
    if (!is_file($path)) {
        return [];
    }
    $raw = @file_get_contents($path);
    if ($raw === false || trim($raw) === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function savePollingState($state) {
    $path = getPollingStatePath();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    $json = json_encode($state, JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return false;
    }
    return @file_put_contents($path, $json, LOCK_EX) !== false;
}

function getSavedRow($pdo, $rowId) {
    $rowStmt = $pdo->prepare("SELECT `Status`, `Date`, `Transmittal Remarks/Received By`, `File Name (PDF)` FROM mailtracking WHERE `id` = :row_id LIMIT 1");
    $rowStmt->execute([':row_id' => $rowId]);
    return $rowStmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

function buildResponse($base, $savedRow, $extra = []) {
    $savedDate = $savedRow['Date'] ?? '';
    $dateDisplay = '';
    if (!empty($savedDate) && $savedDate !== '0000-00-00' && $savedDate !== '0000-00-00 00:00:00') {
        $ts = strtotime($savedDate);
        if ($ts !== false) {
            $dateDisplay = date('F-d-Y', $ts);
        }
    }

    $payload = array_merge([
        'success' => true,
        'rowId' => (int)($base['rowId'] ?? 0),
        'noticeCode' => (string)($base['noticeCode'] ?? ''),
        'trackingNo' => (string)($base['trackingNo'] ?? ''),
        'updated' => (int)($base['updated'] ?? 0),
        'batchId' => (string)($base['batchId'] ?? ''),
        'status' => (string)($savedRow['Status'] ?? ''),
        'date' => $savedDate,
        'dateDisplay' => $dateDisplay,
        'transmittalRemarks' => (string)($savedRow['Transmittal Remarks/Received By'] ?? ''),
        'fileNamePdf' => (string)($savedRow['File Name (PDF)'] ?? '')
    ], $extra);

    echo json_encode($payload);
    exit;
}

$rowId = (int)($_POST['row_id'] ?? 0);
$noticeCode = isset($_POST['notice_code']) ? trim($_POST['notice_code']) : '';
$force = isset($_POST['force']) && (string)$_POST['force'] === '1';
$bypassCooldown = isset($_POST['bypass_cooldown']) && (string)$_POST['bypass_cooldown'] === '1';

if ($rowId <= 0 && $noticeCode === '') {
    echo json_encode(['error' => 'No row id or notice/order code provided']);
    exit;
}

if ($rowId > 0) {
    $stmt = $pdo->prepare("SELECT `id`, `Notice/Order Code`, `Tracking No.`, `Sender Details`, `Status`, `Date released to AFD`, `Parcel No.` FROM mailtracking WHERE `id` = :row_id LIMIT 1");
    $stmt->execute([':row_id' => $rowId]);
} else {
    $stmt = $pdo->prepare("SELECT `id`, `Notice/Order Code`, `Tracking No.`, `Sender Details`, `Status`, `Date released to AFD`, `Parcel No.` FROM mailtracking WHERE `Notice/Order Code` = :notice LIMIT 1");
    $stmt->execute([':notice' => $noticeCode]);
}

$selectedRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
$rowId = (int)($selectedRow['id'] ?? 0);
$noticeCode = trim((string)($selectedRow['Notice/Order Code'] ?? $noticeCode));
$trackingNo = trim((string)($selectedRow['Tracking No.'] ?? ''));
$batchId = extractBatchIdFromSenderDetails($selectedRow['Sender Details'] ?? '');
$currentStatus = trim((string)($selectedRow['Status'] ?? ''));

if ($trackingNo === '' || $trackingNo === '0') {
    echo json_encode(['error' => 'No tracking number found for this notice/order code']);
    exit;
}

$basePayload = [
    'rowId' => $rowId,
    'noticeCode' => $noticeCode,
    'trackingNo' => $trackingNo,
    'batchId' => $batchId,
];

$savedRow = getSavedRow($pdo, $rowId);
$state = loadPollingState();
$stateKey = strtoupper($trackingNo);
$entry = is_array($state[$stateKey] ?? null) ? $state[$stateKey] : [];

$nowTs = time();
$shipmentStartTs = parseDateToTimestamp($selectedRow['Date released to AFD'] ?? null);
$recommendedInterval = computeAdaptiveIntervalSeconds($shipmentStartTs, $nowTs);
$nextAllowedAt = (int)($entry['nextAllowedAt'] ?? 0);
$lastCheckedAt = (int)($entry['lastCheckedAt'] ?? 0);

if (isTerminalStatus($currentStatus)) {
    $entry['terminal'] = true;
    $entry['nextAllowedAt'] = $nowTs + (365 * 24 * 60 * 60);
    $entry['failureCount'] = 0;
    $entry['lastCheckedAt'] = $lastCheckedAt;
    $state[$stateKey] = $entry;
    savePollingState($state);

    buildResponse($basePayload, $savedRow, [
        'updated' => 0,
        'polling' => [
            'requestedAt' => date('c', $nowTs),
            'skipped' => true,
            'reason' => 'terminal-status',
            'nextCheckAt' => date('c', (int)$entry['nextAllowedAt']),
            'recommendedIntervalSeconds' => $recommendedInterval
        ]
    ]);
}

if ($force && !$bypassCooldown && $lastCheckedAt > 0 && ($nowTs - $lastCheckedAt) < JRS_MANUAL_MIN_INTERVAL_SECONDS) {
    $cooldownUntil = $lastCheckedAt + JRS_MANUAL_MIN_INTERVAL_SECONDS;
    buildResponse($basePayload, $savedRow, [
        'updated' => 0,
        'polling' => [
            'requestedAt' => date('c', $nowTs),
            'skipped' => true,
            'reason' => 'manual-cooldown',
            'nextCheckAt' => date('c', $cooldownUntil),
            'recommendedIntervalSeconds' => $recommendedInterval
        ]
    ]);
}

if (!$force && $nextAllowedAt > $nowTs) {
    buildResponse($basePayload, $savedRow, [
        'updated' => 0,
        'polling' => [
            'requestedAt' => date('c', $nowTs),
            'skipped' => true,
            'reason' => 'scheduled-interval',
            'nextCheckAt' => date('c', $nextAllowedAt),
            'recommendedIntervalSeconds' => $recommendedInterval
        ]
    ]);
}

$apiUrl = 'https://jrs-core-api.azurewebsites.net/api/Tracking/v1/track-airbill?airbill=' . urlencode($trackingNo);
$response = @file_get_contents($apiUrl);

if ($response === false) {
    $failureCount = max(0, (int)($entry['failureCount'] ?? 0)) + 1;
    $backoff = min((int)pow(2, min($failureCount, 10)) * 60, JRS_BACKOFF_MAX_SECONDS);
    $entry['failureCount'] = $failureCount;
    $entry['lastCheckedAt'] = $nowTs;
    $entry['nextAllowedAt'] = $nowTs + $backoff;
    $entry['terminal'] = false;
    $state[$stateKey] = $entry;
    savePollingState($state);

    echo json_encode([
        'error' => 'API request failed',
        'polling' => [
            'requestedAt' => date('c', $nowTs),
            'nextCheckAt' => date('c', (int)$entry['nextAllowedAt']),
            'backoffSeconds' => $backoff
        ]
    ]);
    exit;
}

$data = json_decode($response, true);
if (!is_array($data) || empty($data)) {
    $failureCount = max(0, (int)($entry['failureCount'] ?? 0)) + 1;
    $backoff = min((int)pow(2, min($failureCount, 10)) * 60, JRS_BACKOFF_MAX_SECONDS);
    $entry['failureCount'] = $failureCount;
    $entry['lastCheckedAt'] = $nowTs;
    $entry['nextAllowedAt'] = $nowTs + $backoff;
    $entry['terminal'] = false;
    $state[$stateKey] = $entry;
    savePollingState($state);

    echo json_encode([
        'error' => 'Invalid API response',
        'polling' => [
            'requestedAt' => date('c', $nowTs),
            'nextCheckAt' => date('c', (int)$entry['nextAllowedAt']),
            'backoffSeconds' => $backoff
        ]
    ]);
    exit;
}

$latest = end($data);
$statusText = trim((string)($latest['statusText'] ?? ''));
$dateReceived = $latest['dateReceived'] ?? null;
$receiver = trim((string)($latest['receiver'] ?? ''));
$relation = trim((string)($latest['relation'] ?? ''));

$hasReturnToOrigin = false;
foreach ($data as $record) {
    $recordStatus = strtoupper(trim((string)($record['statusText'] ?? '')));
    if ($recordStatus === 'RETURN TO ORIGIN') {
        $hasReturnToOrigin = true;
        break;
    }
}

$statusText = normalizeStatusText($statusText, $hasReturnToOrigin);
$mysqlDate = null;
if (!empty($dateReceived)) {
    $parsed = strtotime((string)$dateReceived);
    if ($parsed !== false) {
        $mysqlDate = date('Y-m-d H:i:s', $parsed);
    }
}

$receivedBy = $receiver;
if ($relation !== '') {
    $receivedBy .= ' (' . $relation . ')';
}

$isBatch = ($batchId !== '');
if ($statusText === 'DELIVERED' && $receiver !== '') {
    if ($isBatch) {
        $sql = "UPDATE mailtracking
                SET `Transmittal Remarks/Received By` = :receivedBy, `Status` = :status, `Date` = :date
                WHERE `Sender Details` LIKE :batchLike";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':receivedBy' => $receivedBy,
            ':status' => $statusText,
            ':date' => $mysqlDate,
            ':batchLike' => '%Batch ID: ' . $batchId . '%'
        ]);
    } else {
        $sql = "UPDATE mailtracking
                SET `Transmittal Remarks/Received By` = :receivedBy, `Status` = :status, `Date` = :date
                WHERE `id` = :row_id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':receivedBy' => $receivedBy,
            ':status' => $statusText,
            ':date' => $mysqlDate,
            ':row_id' => $rowId
        ]);
    }
} else {
    if ($isBatch) {
        $sql = "UPDATE mailtracking
                SET `Status` = :status, `Date` = :date
                WHERE `Sender Details` LIKE :batchLike";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':status' => $statusText,
            ':date' => $mysqlDate,
            ':batchLike' => '%Batch ID: ' . $batchId . '%'
        ]);
    } else {
        $sql = "UPDATE mailtracking
                SET `Status` = :status, `Date` = :date
                WHERE `id` = :row_id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':status' => $statusText,
            ':date' => $mysqlDate,
            ':row_id' => $rowId
        ]);
    }
}

// Keep File Name (PDF) in sync for final statuses so UI can show it immediately.
if (($statusText === 'DELIVERED' || $statusText === 'RETURNED TO SENDER') && $trackingNo !== '' && $trackingNo !== '0') {
    $proofFileName = buildDefaultPdfFileName($selectedRow['Date released to AFD'] ?? '', $selectedRow['Parcel No.'] ?? 0);
    if ($isBatch) {
        $fileStmt = $pdo->prepare("UPDATE mailtracking SET `File Name (PDF)` = :file_name WHERE `Sender Details` LIKE :batchLike");
        $fileStmt->execute([
            ':file_name' => $proofFileName,
            ':batchLike' => '%Batch ID: ' . $batchId . '%'
        ]);
    } else {
        $fileStmt = $pdo->prepare("UPDATE mailtracking SET `File Name (PDF)` = :file_name WHERE `id` = :row_id");
        $fileStmt->execute([
            ':file_name' => $proofFileName,
            ':row_id' => $rowId
        ]);
    }
}

$savedRow = getSavedRow($pdo, $rowId);

$intervalSeconds = applyJitterSeconds($recommendedInterval);
$entry['failureCount'] = 0;
$entry['lastCheckedAt'] = $nowTs;
$entry['nextAllowedAt'] = $nowTs + $intervalSeconds;
$entry['terminal'] = isTerminalStatus($savedRow['Status'] ?? '');
$state[$stateKey] = $entry;
savePollingState($state);

buildResponse($basePayload, $savedRow, [
    'updated' => (int)$stmt->rowCount(),
    'polling' => [
        'requestedAt' => date('c', $nowTs),
        'skipped' => false,
        'nextCheckAt' => date('c', (int)$entry['nextAllowedAt']),
        'recommendedIntervalSeconds' => $recommendedInterval,
        'appliedIntervalSeconds' => $intervalSeconds
    ]
]);
