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

    $mailtrackingCols = [];
    $mailColStmt = $pdo->query("SHOW COLUMNS FROM mailtracking");
    while ($c = $mailColStmt->fetch(PDO::FETCH_ASSOC)) {
        $mailtrackingCols[] = $c['Field'];
    }
    $mailtrackingHasTransmittalId = in_array('Transmittal ID', $mailtrackingCols, true);

    $resolveRowTransmittalId = function (array $row) {
        $candidateKeys = [
            'Transmittal ID',
            'transmittal_id',
            'transmittalId',
            'TransmittalID',
            'transmittalid',
        ];
        foreach ($candidateKeys as $k) {
            if (!array_key_exists($k, $row)) continue;
            $value = trim((string)$row[$k]);
            if ($value !== '') {
                return $value;
            }
        }
        return '';
    };

    $focusId = 0;
    $focusDept = 'emes';
    $recoveredTransmittalIds = [];

    $detectDeptKeyFromRow = function (array $row) {
        $storedDeptKey = normalizeDepartmentKey($row['department_key'] ?? '');
        if ($storedDeptKey !== 'emes' || strtolower(trim((string)($row['department_key'] ?? ''))) === 'emes') {
            return $storedDeptKey;
        }

        $noticeText = trim((string)($row['Notice/Order Code'] ?? ''));
        if ($noticeText !== '' && preg_match('/^([A-Z]+)-/i', $noticeText, $m)) {
            $code = strtoupper(trim((string)$m[1]));
        } else {
            $senderText = (string)($row['Sender Details'] ?? '');
            $code = '';
            if ($senderText !== '' && preg_match('/\bHREDRD-([A-Z]+)\b/i', $senderText, $m)) {
                $code = strtoupper(trim((string)$m[1]));
            }
        }

        $deptMap = [
            'EMES' => 'emes',
            'PRLS' => 'prls',
            'AFD' => 'afd',
            'PHSD' => 'phsd',
            'ELUPD' => 'elupd',
            'ORD' => 'ord',
            'HOA' => 'hoa',
            'PHILPOST' => 'philpost',
        ];
        return $deptMap[$code] ?? 'emes';
    };

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

        $rowTransmittalId = $resolveRowTransmittalId($row);
        if ($rowTransmittalId !== '') {
            $recoveredTransmittalIds[$rowTransmittalId] = true;
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
            'department_key',
            'Status',
            'Transmittal Remarks/Received By',
            'Date',
            'Evaluator',
        ];
        if ($mailtrackingHasTransmittalId) {
            array_splice($insertCols, 8, 0, ['Transmittal ID']);
        }

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
            } elseif ($c === 'Transmittal ID') {
                $params[$p] = $rowTransmittalId;
            } elseif ($c === 'department_key') {
                $params[$p] = $detectDeptKeyFromRow($row);
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
            $focusDept = $detectDeptKeyFromRow($row);
        }
    }

    $pdo->commit();

    // Send user back to Home and focus the first recovered row by id.
    $redirectParams = [
        'dept' => $focusDept,
        'recovered' => '1',
        'scanned_id' => (string)$focusId,
    ];
    if (!empty($recoveredTransmittalIds)) {
        $redirectParams['recovered_transmittals'] = implode(',', array_keys($recoveredTransmittalIds));
    }
    header("Location: ../pages/Home_Page.php?" . http_build_query($redirectParams));
    exit;

} catch (Exception $e) {
    $pdo->rollBack();
    die("Error recovering record: " . $e->getMessage());
}
