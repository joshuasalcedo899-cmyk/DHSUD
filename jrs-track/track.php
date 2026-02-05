<?php
require 'config.php';

$trackingNumber = $_GET['tracking'] ?? '';

if (!$trackingNumber) {
    die('Tracking number is required');
}

$url = JRS_BASE_URL . '/shipments/track/' . urlencode($trackingNumber);

$ch = curl_init($url);

$headers = [
    'Accept: application/json'
];

// add this only if JRS requires it
if (defined('JRS_API_KEY') && JRS_API_KEY !== '') {
    $headers[] = 'Authorization: Bearer ' . JRS_API_KEY;
}

curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => $headers,
    CURLOPT_TIMEOUT        => 20
]);

$response = curl_exec($ch);

if (curl_errno($ch)) {
    die('cURL Error: ' . curl_error($ch));
}

curl_close($ch);

$data = json_decode($response, true);

if (!$data) {
    die('Invalid response from JRS');
}

echo "<pre>";
print_r($data);
echo "</pre>";
