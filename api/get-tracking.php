<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth.php';

if (!isset($_POST['tracking']) || !isset($_POST['codes'])) {
    die("Invalid request");
}

$trackingNo = trim($_POST['tracking']); // actual tracking number
$codes = $_POST['codes'];                // selected notice/order codes

// ensure array
if (!is_array($codes)) {
    $codes = [$codes];
}

// prepare query
$stmt = $pdo->prepare("
    UPDATE mailtracking 
    SET `Tracking No.` = ?
    WHERE `Notice/Order Code` = ?
");

// update each selected record
foreach ($codes as $code) {
    $stmt->execute([$trackingNo, $code]);
}

header("Location: ../pages/Home_Page.php?updated=1");
exit;
?>
