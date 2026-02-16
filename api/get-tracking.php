<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth.php';

if (!isset($_POST['tracking']) || !isset($_POST['codes'])) {
    die("Invalid request");
}

$trackingNo = trim($_POST['tracking']); // actual tracking number
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
    die("Invalid request");
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
    http_response_code(500);
    die("Failed to update tracking number");
}

header("Location: ../pages/Home_Page.php?updated=1");
exit;
?>
