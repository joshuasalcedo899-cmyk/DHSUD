<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config.php';
require_once __DIR__ .'/../auth.php';

function extractBatchIdFromSenderDetails($senderDetails) {
    $text = trim((string)$senderDetails);
    if ($text === '') return '';
    if (preg_match('/Batch ID:\s*([A-Za-z0-9\-]+)/i', $text, $m)) {
        return trim($m[1]);
    }
    return '';
}


$noticeCode = isset($_POST['notice_code']) ? trim($_POST['notice_code']) : '';

if ($noticeCode === '') {
    echo json_encode(["error" => "No notice/order code provided"]);
    exit;
}

// Fetch tracking number + sender details from DB using Notice/Order Code
$stmt = $pdo->prepare("SELECT `Tracking No.`, `Sender Details` FROM mailtracking WHERE `Notice/Order Code` = :notice LIMIT 1");
$stmt->execute([':notice' => $noticeCode]);
$selectedRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
$trackingNo = trim((string)($selectedRow['Tracking No.'] ?? ''));
$batchId = extractBatchIdFromSenderDetails($selectedRow['Sender Details'] ?? '');

if ($trackingNo === '' || $trackingNo === '0') {
    echo json_encode(["error" => "No tracking number found for this notice/order code"]);
    exit;
}

$apiUrl = "https://jrs-core-api.azurewebsites.net/api/Tracking/v1/track-airbill?airbill=" . urlencode($trackingNo);

$response = @file_get_contents($apiUrl);

if ($response === false) {
    echo json_encode(["error" => "API request failed"]);
    exit;
}

$data = json_decode($response, true);

if (!is_array($data) || empty($data)) {
    echo json_encode(["error" => "Invalid API response"]);
    exit;
}

// ✅ Get ONLY the latest record (last array item)
$latest = end($data);
$statusText   = trim($latest['statusText'] ?? '');
$dateReceived = $latest['dateReceived'] ?? null;
$receiver     = $latest['receiver'] ?? '';
$relation     = $latest['relation'] ?? '';

$hasReturnToOrigin = false;
foreach ($data as $record) {
    $recordStatus = strtoupper(trim($record['statusText'] ?? ''));
    if ($recordStatus === "RETURN TO ORIGIN") {
        $hasReturnToOrigin = true;
        break;
    }
}

// Normalize status to match DB values
$statusUpper = strtoupper($statusText);
if ($hasReturnToOrigin) {
    $statusText = "RETURNED TO SENDER";
} elseif ($statusUpper === "DELIVERED TO" || $statusUpper === "DELIVERED") {
    $statusText = "DELIVERED";
} elseif ($statusUpper === "OUT FOR DELIVERY") {
    $statusText = "ONGOING DELIVERY";
} else{
    $statusText = "ONGOING DELIVERY";
}

// Convert date
$mysqlDate = null;
if (!empty($dateReceived)) {
    $mysqlDate = date("Y-m-d H:i:s", strtotime($dateReceived));
}

// Combine receiver + relation
$receivedBy = $receiver;
if (!empty($relation)) {
    $receivedBy .= " ($relation)";
}

// If Delivered and receiver exists
// Apply updates to single row OR entire batch.
$isBatch = ($batchId !== '');

if ($statusText === "DELIVERED" && !empty($receiver)) {
    if ($isBatch) {
        $sql = "UPDATE mailtracking 
                SET 
                    `Transmittal Remarks/Received By` = :receivedBy,
                    `Status` = :status,
                    `Date` = :date
                WHERE `Sender Details` LIKE :batchLike";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':receivedBy' => $receivedBy,
            ':status'     => $statusText,
            ':date'       => $mysqlDate,
            ':batchLike'  => '%Batch ID: ' . $batchId . '%'
        ]);
    } else {
        $sql = "UPDATE mailtracking 
                SET 
                    `Transmittal Remarks/Received By` = :receivedBy,
                    `Status` = :status,
                    `Date` = :date
                WHERE `Notice/Order Code` = :notice";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':receivedBy' => $receivedBy,
            ':status'     => $statusText,
            ':date'       => $mysqlDate,
            ':notice'     => $noticeCode
        ]);
    }
} else {
    if ($isBatch) {
        $sql = "UPDATE mailtracking 
                SET 
                    `Status` = :status,
                    `Date` = :date
                WHERE `Sender Details` LIKE :batchLike";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':status'    => $statusText,
            ':date'      => $mysqlDate,
            ':batchLike' => '%Batch ID: ' . $batchId . '%'
        ]);
    } else {
        $sql = "UPDATE mailtracking 
                SET 
                    `Status` = :status,
                    `Date` = :date
                WHERE `Notice/Order Code` = :notice";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':status'   => $statusText,
            ':date'     => $mysqlDate,
            ':notice'   => $noticeCode
        ]);
    }
}

// Return the latest saved row values so UI can update without page refresh.
$rowStmt = $pdo->prepare("SELECT `Status`, `Date`, `Transmittal Remarks/Received By` FROM mailtracking WHERE `Notice/Order Code` = :notice LIMIT 1");
$rowStmt->execute([':notice' => $noticeCode]);
$savedRow = $rowStmt->fetch(PDO::FETCH_ASSOC) ?: [];

$savedDate = $savedRow['Date'] ?? '';
$dateDisplay = '';
if (!empty($savedDate) && $savedDate !== '0000-00-00' && $savedDate !== '0000-00-00 00:00:00') {
    $ts = strtotime($savedDate);
    if ($ts !== false) {
        $dateDisplay = date('F-d-Y', $ts);
    }
}

echo json_encode([
    "success" => true,
    "trackingNo" => $trackingNo,
    "updated" => (int) $stmt->rowCount(),
    "batchId" => $batchId,
    "status" => $savedRow['Status'] ?? '',
    "date" => $savedDate,
    "dateDisplay" => $dateDisplay,
    "transmittalRemarks" => $savedRow['Transmittal Remarks/Received By'] ?? ''
]);

