<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth.php';
requireCsrfToken();

if (!isset($_POST['id'])) {
    die("Invalid request");
}

$id = (int)$_POST['id'];
if ($id <= 0) {
    die("Invalid id");
}

try {
    $pdo->beginTransaction();

    $rowStmt = $pdo->prepare("SELECT * FROM mailtracking WHERE `id` = :id LIMIT 1");
    $rowStmt->execute([':id' => $id]);
    $row = $rowStmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new Exception("Record not found");
    }

    $triggerStmt = $pdo->prepare("
        SELECT COUNT(*) FROM information_schema.TRIGGERS
        WHERE TRIGGER_SCHEMA = DATABASE()
          AND EVENT_OBJECT_TABLE = 'mailtracking'
          AND EVENT_MANIPULATION = 'DELETE'
    ");
    $triggerStmt->execute();
    $hasDeleteTrigger = ((int)$triggerStmt->fetchColumn() > 0);

    // Support both archive schemas:
    // 1) legacy mirror table
    // 2) archive with `original_mail_id` and/or `deleted_at`
    if (!$hasDeleteTrigger) {
        $archiveCols = [];
        $colStmt = $pdo->query("SHOW COLUMNS FROM archive");
        while ($c = $colStmt->fetch(PDO::FETCH_ASSOC)) {
            $archiveCols[] = $c['Field'];
        }
        $hasOriginalMailId = in_array('original_mail_id', $archiveCols, true);
        $hasDeletedAt = in_array('deleted_at', $archiveCols, true);

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
        if (!$hasOriginalMailId && in_array('id', $archiveCols, true)) {
            $insertCols = array_merge(['id'], $insertCols);
        }
        if ($hasOriginalMailId) {
            $insertCols = array_merge(['original_mail_id'], $insertCols);
        }
        if ($hasDeletedAt) {
            $insertCols[] = 'deleted_at';
        }

        $colSql = array_map(function ($c) { return "`$c`"; }, $insertCols);
        $valSql = [];
        $params = [];
        foreach ($insertCols as $c) {
            $p = ':' . preg_replace('/[^a-z0-9_]/i', '_', $c);
            if ($c === 'original_mail_id') {
                $params[$p] = $id;
            } elseif ($c === 'deleted_at') {
                $params[$p] = date('Y-m-d H:i:s');
            } else {
                $params[$p] = $row[$c] ?? null;
            }
            $valSql[] = $p;
        }

        $insertSql = "INSERT INTO archive (" . implode(', ', $colSql) . ") VALUES (" . implode(', ', $valSql) . ")";
        $insertStmt = $pdo->prepare($insertSql);
        $insertStmt->execute($params);
    }

    $delStmt = $pdo->prepare("DELETE FROM mailtracking WHERE `id` = :id");
    $delStmt->execute([':id' => $id]);

    $pdo->commit();
    header("Location: ../pages/Home_Page.php");
    exit;
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo "Error deleting record: " . $e->getMessage();
}
