<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth.php';
requireCsrfToken();

if (!isset($_POST['id']) && !isset($_POST['noticeCode'])) {
    die("Invalid request");
}

$ids = $_POST['id'] ?? $_POST['noticeCode'];

// Ensure array
if (!is_array($ids)) {
    $ids = [$ids];
}

try {
    $pdo->beginTransaction();

    $archiveCols = [];
    $colStmt = $pdo->query("SHOW COLUMNS FROM archive");
    while ($c = $colStmt->fetch(PDO::FETCH_ASSOC)) {
        $archiveCols[] = $c['Field'];
    }
    $hasOriginalMailId = in_array('original_mail_id', $archiveCols, true);

    $focusId = 0;
    foreach ($ids as $archiveId) {
        $safeId = (int)$archiveId;
        if ($safeId <= 0) {
            continue;
        }

        $rowStmt = $pdo->prepare("SELECT * FROM archive WHERE `id` = :id LIMIT 1");
        $rowStmt->execute([':id' => $safeId]);
        $row = $rowStmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            continue;
        }

        $insertCols = [
            'Notice/Order Code',
            'Date released to AFD',
            'Parcel No.',
            'Recipient Details',
            'Parcel Details',
            'Sender Details',
            'File Name (PDF)',
            'Tracking No.',
            'Status',
            'Transmittal Remarks/Received By',
            'Date',
            'Evaluator',
        ];

        // Restore original ID when archive tracks it.
        if ($hasOriginalMailId && isset($row['original_mail_id']) && (int)$row['original_mail_id'] > 0) {
            array_unshift($insertCols, 'id');
        } elseif (!$hasOriginalMailId && array_key_exists('id', $row)) {
            array_unshift($insertCols, 'id');
        }

        $colSql = array_map(function ($c) { return "`$c`"; }, $insertCols);
        $valSql = [];
        $params = [];
        foreach ($insertCols as $c) {
            $p = ':m_' . preg_replace('/[^a-z0-9_]/i', '_', $c);
            if ($c === 'id') {
                $params[$p] = $hasOriginalMailId ? (int)$row['original_mail_id'] : (int)$row['id'];
            } else {
                $params[$p] = $row[$c] ?? null;
            }
            $valSql[] = $p;
        }

        $insertSql = "INSERT INTO mailtracking (" . implode(', ', $colSql) . ") VALUES (" . implode(', ', $valSql) . ")";
        $insert = $pdo->prepare($insertSql);
        $insert->execute($params);

        $delete = $pdo->prepare("DELETE FROM archive WHERE `id` = :id");
        $delete->execute([':id' => $safeId]);

        if ($focusId <= 0) {
            $focusId = (int)$pdo->lastInsertId();
            if ($focusId <= 0) {
                $focusId = $hasOriginalMailId ? (int)($row['original_mail_id'] ?? 0) : (int)($row['id'] ?? 0);
            }
        }
    }

    $pdo->commit();

    // Send user back to Home and focus the first recovered row by id.
    header("Location: ../pages/Home_Page.php?recovered=1&scanned_id=" . urlencode((string)$focusId));
    exit;

} catch (Exception $e) {
    $pdo->rollBack();
    die("Error recovering record: " . $e->getMessage());
}
