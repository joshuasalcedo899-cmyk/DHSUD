<?php
require_once __DIR__ . "/../config.php";

if (!isset($pdo)) {
    die("PDO connection not loaded");
}

$trackingNo = $_GET['tracking'] ?? '';
if (!$trackingNo) {
    die("No tracking number");
}

$url = "https://jrs-core-api.azurewebsites.net/api/Tracking/v1/track-airbill?airbill=" . urlencode($trackingNo);
$json = file_get_contents($url);
$data = json_decode($json, true);

if (!is_array($data)) {
    die("Invalid API data");
}

$sql = "INSERT INTO jrs_tracking 
(tracking_id, tracking_code, receiver, relation, status_text, date_received, location, remarks, branch_location)
VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";

$stmt = $pdo->prepare($sql);

foreach ($data as $row) {
    $date = !empty($row['dateReceived']) ? date('Y-m-d H:i:s', strtotime($row['dateReceived'])) : null;

    $stmt->execute([
        $row['trackingID'] ?? null,
        $row['trackingCode'] ?? null,
        $row['receiver'] ?? null,
        $row['relation'] ?? null,
        $row['statusText'] ?? null,
        $date,
        $row['location'] ?? null,
        $row['remarks'] ?? null,
        $row['branchLocation'] ?? null
    ]);
}
