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

if (!isset($_POST['tracking']) || !isset($_POST['ids'])) {
    respondError('Invalid request', 400, $isAjax);
}


$trackingNo = trim($_POST['tracking']);
$ids = $_POST['ids']; // selected row IDs

// ensure array
if (!is_array($ids)) {
    $ids = [$ids];
}

function extractBatchIdFromSenderDetails($senderDetails) {
    $text = trim((string)$senderDetails);
    if ($text === '') return '';
    if (preg_match('/Batch ID:\s*([A-Za-z0-9\-]+)/i', $text, $m)) {
        return trim($m[1]);
    }
    return '';
}

// Normalize selected ids
$normalizedIds = [];
foreach ($ids as $idValue) {
    $id = (int)$idValue;
    if ($id > 0) $normalizedIds[$id] = true;
}
$ids = array_keys($normalizedIds);

if ($trackingNo === '' || empty($ids)) {
    respondError('Invalid request', 400, $isAjax);
}

try {
    $pdo->beginTransaction();

    // Collect target row ids:
    // 1) explicitly selected ids
    // 2) all rows that share batch ID with any selected row
    $targetIds = [];
    foreach ($ids as $id) {
        $targetIds[$id] = true;
    }

    $selectSenderStmt = $pdo->prepare("SELECT `Sender Details` FROM mailtracking WHERE `id` = ? LIMIT 1");
    $selectBatchMembersStmt = $pdo->prepare("SELECT `id` FROM mailtracking WHERE `Sender Details` LIKE ?");

    foreach ($ids as $id) {
        $selectSenderStmt->execute([$id]);
        $senderDetails = $selectSenderStmt->fetchColumn();
        $batchId = extractBatchIdFromSenderDetails($senderDetails ?: '');
        if ($batchId === '') {
            continue;
        }

        $selectBatchMembersStmt->execute(['%Batch ID: ' . $batchId . '%']);
        while ($batchRowId = $selectBatchMembersStmt->fetchColumn()) {
            $batchRowId = (int)$batchRowId;
            if ($batchRowId > 0) {
                $targetIds[$batchRowId] = true;
            }
        }
    }

    // Update all target rows
    $updateStmt = $pdo->prepare("
        UPDATE mailtracking
        SET `Tracking No.` = ?
        WHERE `id` = ?
    ");
    foreach (array_keys($targetIds) as $targetId) {
        $updateStmt->execute([$trackingNo, $targetId]);
    }

    $updatedIds = array_values(array_keys($targetIds));
    $updatedNotices = [];
    if (!empty($updatedIds)) {
        $noticePlaceholders = implode(',', array_fill(0, count($updatedIds), '?'));
        $noticeStmt = $pdo->prepare("SELECT `Notice/Order Code` FROM mailtracking WHERE `id` IN ($noticePlaceholders)");
        $noticeStmt->execute($updatedIds);
        while ($n = $noticeStmt->fetchColumn()) {
            $notice = trim((string)$n);
            if ($notice !== '') {
                $updatedNotices[] = $notice;
            }
        }
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
        'updatedIds' => $updatedIds ?? [],
        'updatedNotices' => $updatedNotices ?? [],
    ]);
    exit;
}

header("Location: ../pages/Home_Page.php?updated=1");
exit;
?>
