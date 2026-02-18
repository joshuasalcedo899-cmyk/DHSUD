<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth.php';

// Scanner/test.php calls this endpoint via fetch and expects JSON.
$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
if ($isAjax) {
    header('Content-Type: application/json; charset=utf-8');
}

function respondError($message, $statusCode = 400, $isAjax = false) {
    http_response_code($statusCode);
    if ($isAjax) {
        echo json_encode(['success' => false, 'message' => $message]);
    } else {
        echo $message;
    }
    exit;
}

if (!isset($_POST['tracking']) || !isset($_POST['codes'])) {
    respondError('Invalid request', 400, $isAjax);
}


$trackingNo = trim($_POST['tracking']);
$codes = $_POST['codes'];                // selected notice/order codes

// ensure array
if (!is_array($codes)) {
    $codes = [$codes];
}

function extractBatchIdFromSenderDetails($senderDetails) {
    $text = trim((string)$senderDetails);
    if ($text === '') return '';
    if (preg_match('/Batch ID:\s*([A-Za-z0-9\-]+)/i', $text, $m)) {
        return trim($m[1]);
    }
    return '';
}

// Normalize selected codes
$normalizedCodes = [];
foreach ($codes as $code) {
    $c = trim((string)$code);
    if ($c !== '') $normalizedCodes[$c] = true;
}
$codes = array_keys($normalizedCodes);

if ($trackingNo === '' || empty($codes)) {
    respondError('Invalid request', 400, $isAjax);
}

try {
    $pdo->beginTransaction();

    // Collect target notice codes:
    // 1) explicitly selected codes
    // 2) all rows that share batch ID with any selected code
    $targetCodes = [];
    foreach ($codes as $code) {
        $targetCodes[$code] = true;
    }

    $selectSenderStmt = $pdo->prepare("SELECT `Sender Details` FROM mailtracking WHERE `Notice/Order Code` = ? LIMIT 1");
    $selectBatchMembersStmt = $pdo->prepare("SELECT `Notice/Order Code` FROM mailtracking WHERE `Sender Details` LIKE ?");

    foreach ($codes as $code) {
        $selectSenderStmt->execute([$code]);
        $senderDetails = $selectSenderStmt->fetchColumn();
        $batchId = extractBatchIdFromSenderDetails($senderDetails ?: '');
        if ($batchId === '') {
            continue;
        }

        $selectBatchMembersStmt->execute(['%Batch ID: ' . $batchId . '%']);
        while ($batchCode = $selectBatchMembersStmt->fetchColumn()) {
            $batchCode = trim((string)$batchCode);
            if ($batchCode !== '') {
                $targetCodes[$batchCode] = true;
            }
        }
    }

    // Update all target rows
    $updateStmt = $pdo->prepare("
        UPDATE mailtracking
        SET `Tracking No.` = ?
        WHERE `Notice/Order Code` = ?
    ");
    foreach (array_keys($targetCodes) as $targetCode) {
        $updateStmt->execute([$trackingNo, $targetCode]);
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    respondError('Failed to update tracking number', 500, $isAjax);
}

if ($isAjax) {
    echo json_encode([
        'success' => true,
        'message' => 'Tracking number saved',
        'tracking' => $trackingNo,
        'updatedNotices' => array_values(array_keys($targetCodes)),
    ]);
    exit;
}

header("Location: ../pages/Home_Page.php?updated=1");
exit;
?>
