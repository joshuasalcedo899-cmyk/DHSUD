<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

try {
    $sql = "SELECT
                COUNT(*) AS row_count,
                COALESCE(MAX(`id`), 0) AS max_id,
                COALESCE(SUM(CRC32(CONCAT_WS('|',
                    `id`,
                    `Notice/Order Code`,
                    `Date released to AFD`,
                    `Parcel No.`,
                    `Recipient Details`,
                    `Parcel Details`,
                    `Sender Details`,
                    `File Name (PDF)`,
                    `Tracking No.`,
                    `Status`,
                    `Transmittal Remarks/Received By`,
                    `Date`,
                    `Evaluator`
                ))), 0) AS checksum
            FROM `mailtracking`";

    $stmt = $pdo->query($sql);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $rowCount = (string)($row['row_count'] ?? '0');
    $maxId = (string)($row['max_id'] ?? '0');
    $checksum = (string)($row['checksum'] ?? '0');
    $version = $rowCount . ':' . $maxId . ':' . $checksum;

    echo json_encode([
        'success' => true,
        'version' => $version
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to compute version'
    ]);
}

