<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config.php';
require_once __DIR__ .'/../auth.php';



$noticeCode = isset($_POST['notice_code']) ? trim($_POST['notice_code']) : '';

if ($noticeCode === '') {
    echo json_encode(["error" => "No notice/order code provided"]);
    exit;
}

// Fetch tracking number from DB using Notice/Order Code
$stmt = $pdo->prepare("SELECT `Tracking No.` FROM mailtracking WHERE `Notice/Order Code` = :notice LIMIT 1");
$stmt->execute([':notice' => $noticeCode]);
$trackingNo = trim((string) $stmt->fetchColumn());

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

// Normalize status to match DB values
$statusUpper = strtoupper($statusText);
if ($statusUpper === "DELIVERED TO" || $statusUpper === "DELIVERED") {
    $statusText = "DELIVERED";
} elseif ($statusUpper === "OUT FOR DELIVERY") {
    $statusText = "ON GOING DELIVERY";
} elseif ($statusUpper === "RETURN TO ORIGIN") {
    $statusText = "RETURNED TO SENDER";
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
if ($statusText === "DELIVERED" && !empty($receiver)) {

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

echo json_encode([
    "success" => true,
    "trackingNo" => $trackingNo,
    "updated" => (int) $stmt->rowCount()
]);
