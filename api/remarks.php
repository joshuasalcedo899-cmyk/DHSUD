<?php
require_once __DIR__ . '/../config.php'; // gives $pdo

$trackingNo = isset($_GET['tracking']) ? trim($_GET['tracking']) : '';

if ($trackingNo === '') {
    die(json_encode(["error" => "No tracking number provided"]));
}

// JRS API URL
$apiUrl = "https://jrs-core-api.azurewebsites.net/api/Tracking/v1/track-airbill?airbill=$trackingNo";

// Fetch API
$response = file_get_contents($apiUrl);
if (!$response) {
    die(json_encode(["error" => "API request failed"]));
}

$data = json_decode($response, true);
if (!is_array($data)) {
    die(json_encode(["error" => "Invalid API response"]));
}

// Loop API data
foreach ($data as $row) {

    $trackingID   = $row['trackingID'];
    $statusText   = trim($row['statusText']);
    $dateReceived = $row['dateReceived'];
    $receiver     = $row['receiver'] ?? null;

    // Convert ISO date to MySQL datetime
    $mysqlDate = date("Y-m-d H:i:s", strtotime($dateReceived));

    // ==============================
    // ✅ If Delivered To → Save receiver
    // ==============================
    if ($statusText === "Delivered To" && !empty($receiver)) {

        $sql = "UPDATE mailtracking 
                SET 
                    `Transmittal Remarks/Received By` = :receiver,
                    `Status` = :status,
                    `Date` = :date
                WHERE `Tracking No.` = :tracking";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':receiver' => $receiver,
            ':status'   => $statusText,
            ':date'     => $mysqlDate,
            ':tracking' => $trackingID
        ]);

    } 
    // ==============================
    // ✅ Other status → Update status only
    // ==============================
    else {

        $sql = "UPDATE mailtracking 
                SET 
                    `Status` = :status,
                    `Date` = :date
                WHERE `Tracking No.` = :tracking";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':status'   => $statusText,
            ':date'     => $mysqlDate,
            ':tracking' => $trackingID
        ]);
    }
}

// Return API data to JavaScript
header("Content-Type: application/json");
echo json_encode($data);
