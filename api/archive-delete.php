<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth.php';

if (!isset($_POST['id']) && !isset($_POST['noticeCode'])) {
    die("Invalid request");
}

$ids = $_POST['id'] ?? $_POST['noticeCode']; // backward-compatible fallback

// Make it always an array
if (!is_array($ids)) {
    $ids = [$ids];
}

// Prepare statement
$stmt = $pdo->prepare("DELETE FROM archive WHERE `id` = ?");

// Loop and delete each
foreach ($ids as $id) {
    $safeId = (int)$id;
    if ($safeId <= 0) {
        continue;
    }
    $stmt->execute([$safeId]);
}

// Redirect back
header("Location: ../pages/Archive_Page.php?deleted=1");
exit;
