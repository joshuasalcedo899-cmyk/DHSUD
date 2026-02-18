<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth.php';

if (!isset($_POST['noticeCode'])) {
    die("Invalid request");
}

$codes = $_POST['noticeCode'];

// Ensure array
if (!is_array($codes)) {
    $codes = [$codes];
}

try {
    $pdo->beginTransaction();

    // Prepare statements
    $insert = $pdo->prepare("INSERT INTO mailtracking SELECT * FROM archive WHERE `Notice/Order Code` = ?");
    $delete = $pdo->prepare("DELETE FROM archive WHERE `Notice/Order Code` = ?");

    foreach ($codes as $code) {
        $insert->execute([$code]);
        $delete->execute([$code]);
    }

    $pdo->commit();

    // Send user back to Home and focus the first recovered row.
    $focusNotice = '';
    foreach ($codes as $code) {
        $focusNotice = trim((string)$code);
        if ($focusNotice !== '') break;
    }

    header("Location: ../pages/Home_Page.php?recovered=1&scanned_notice=" . urlencode($focusNotice));
    exit;

} catch (Exception $e) {
    $pdo->rollBack();
    die("Error recovering record: " . $e->getMessage());
}
