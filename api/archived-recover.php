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

    header("Location: ../pages/Archive_Page.php?recovered=1");
    exit;

} catch (Exception $e) {
    $pdo->rollBack();
    die("Error recovering record: " . $e->getMessage());
}
