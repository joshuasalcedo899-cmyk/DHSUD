<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth.php';

if (!isset($_POST['noticeCode'])) {
    die("Invalid request");
}

$code = $_POST['noticeCode'];
// Use prepared statement to prevent SQL injection
$stmt = $pdo->prepare("DELETE FROM mailtracking WHERE `Notice/Order Code` = ?");

if ($stmt->execute([$code])) {
    // Redirect back with success
    header("Location: ../pages/Home_Page.php?deleted=1");
    exit;
} else {
    echo "Error deleting record: " . $stmt->errorInfo()[2];
}
