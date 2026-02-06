<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth.php';

if (!isset($_GET['tracking'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing tracking number']);
    exit;
}

$tracking = $_GET['tracking'];

$url = "https://jrs-core-api.azurewebsites.net/api/Tracking/v1/track-airbill?airbill=" . urlencode($tracking);

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 15,
    CURLOPT_HTTPHEADER => ['Accept: application/json']
]);

$response = curl_exec($ch);
$http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($http !== 200 || !$response) {
    http_response_code(500);
    echo json_encode(['error' => 'Tracking unavailable']);
    exit;
}

echo $response; // JSON array
