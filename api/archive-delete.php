<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth.php';

if (!isset($_POST['noticeCode'])) {
    die("Invalid request");
}

$codes = $_POST['noticeCode']; // Can be string or array

// Make it always an array
if (!is_array($codes)) {
    $codes = [$codes];
}

// Prepare statement
$stmt = $pdo->prepare("DELETE FROM archive WHERE `Notice/Order Code` = ?");

// Loop and delete each
foreach ($codes as $code) {
    $stmt->execute([$code]);
}

// Redirect back
header("Location: ../pages/Archive_Page.php?deleted=1");
exit;
