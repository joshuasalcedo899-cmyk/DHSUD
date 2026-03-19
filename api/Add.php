<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth.php';

if(!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized: Please log in']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();
}

function extractBatchIdFromSenderDetails($senderDetails) {
    $text = trim((string)$senderDetails);
    if ($text === '') return '';
    if (preg_match('/Batch ID:\s*([A-Za-z0-9\-]+)/i', $text, $m)) {
        return trim($m[1]);
    }
    return '';
}

// Handle AJAX requests (return JSON)
$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest';

$message = '';
$messageType = '';
$success = false;

// Debug: Log what was received
error_log("=== Add.php REQUEST ===");
error_log("Is AJAX: " . ($isAjax ? 'yes' : 'no'));
error_log("POST data: " . json_encode($_POST));

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $departmentConfig = [
        'emes' => ['code' => 'EMES', 'sender' => getDepartmentSenderTag('emes')],
        'prls' => ['code' => 'PRLS', 'sender' => getDepartmentSenderTag('prls')],
        'afd' => ['code' => 'AFD', 'sender' => getDepartmentSenderTag('afd')],
        'phsd' => ['code' => 'PHSD', 'sender' => getDepartmentSenderTag('phsd')],
        'elupd' => ['code' => 'ELUPD', 'sender' => getDepartmentSenderTag('elupd')],
        'ord' => ['code' => 'ORD', 'sender' => getDepartmentSenderTag('ord')],
        'hoa' => ['code' => 'HOA', 'sender' => getDepartmentSenderTag('hoa')],
        'lo' => ['code' => 'LO', 'sender' => getDepartmentSenderTag('lo')],
    ];
    $currentDept = normalizeDepartmentKey($_POST['department_id'] ?? $_POST['dept'] ?? 'emes');
    if (!isset($departmentConfig[$currentDept])) {
        $currentDept = 'emes';
    }
    $currentDeptCode = $departmentConfig[$currentDept]['code'];
    $currentDeptSenderTag = $departmentConfig[$currentDept]['sender'];

    $dateReleased = trim($_POST['Date released to AFD'] ?? $_POST['dateReleased'] ?? '');
    $parcelNo = 0;
    $recipientDetails = trim($_POST['Recipient Details'] ?? $_POST['recipientDetails'] ?? '');
    $customSenderDetails = trim($_POST['Sender Details'] ?? $_POST['senderDetails'] ?? '');
    $trackingNo = trim($_POST['Tracking No.'] ?? $_POST['trackingNo'] ?? '');
    $statusValue = trim($_POST['Status'] ?? $_POST['status'] ?? '');
    $remarksValue = trim($_POST['Transmittal Remarks/Received By'] ?? $_POST['transmittalRemarks'] ?? '');
    $eventDateValue = trim($_POST['Date'] ?? $_POST['eventDate'] ?? '');
    $evaluatorValue = trim($_POST['Evaluator'] ?? $_POST['evaluator'] ?? '');
    $transmittalId = trim($_POST['transmittal_id'] ?? $_POST['Transmittal ID'] ?? '');
    $requestedBatchId = trim($_POST['batch_id'] ?? $_POST['Batch ID'] ?? '');
    $batchSourceRowId = (int)($_POST['batch_source_row_id'] ?? $_POST['batchSourceRowId'] ?? 0);

    // Backward-compatible single inputs
    $singleNoticeCode = trim($_POST['Notice/Order Code'] ?? $_POST['notice_Code'] ?? '');
    $singleParcelDetails = trim($_POST['Parcel Details'] ?? $_POST['parcelDetails'] ?? '');

    error_log($singleParcelDetails);

    // New multi inputs from Add modal
    $noticeCodes = $_POST['noticeCodes'] ?? [];
    $parcelDetailsList = $_POST['parcelDetailsList'] ?? [];
    if (!is_array($noticeCodes)) $noticeCodes = [];
    if (!is_array($parcelDetailsList)) $parcelDetailsList = [];

    if (count($noticeCodes) === 0 && $singleNoticeCode !== '') {
        $noticeCodes = [$singleNoticeCode];
    }
    if (count($parcelDetailsList) === 0 && $singleParcelDetails !== '') {
        $parcelDetailsList = [$singleParcelDetails];
    }

    // Normalize and pair entries by index.
    $pairs = [];
    $maxCount = max(count($noticeCodes), count($parcelDetailsList));
    for ($i = 0; $i < $maxCount; $i++) {
        $notice = trim((string)($noticeCodes[$i] ?? ''));
        $parcelDetails = trim((string)($parcelDetailsList[$i] ?? ''));
        if ($notice === '' && $parcelDetails === '') {
            continue;
        }
        $pairs[] = [
            'notice' => $notice,
            'parcel_details' => $parcelDetails
        ];
    }
    // If no pair rows have values, still insert one record with empty notice/parcel details.
    if (count($pairs) === 0) {
        $pairs[] = [
            'notice' => '',
            'parcel_details' => ''
        ];
    }

    error_log("dateReleased: '$dateReleased'");
    error_log("pairs count: " . count($pairs));

    if ($dateReleased === '') {
        $message = 'Date Released to AFD is required.';
        $messageType = 'error';
        error_log("ERROR: Date released is empty");
    }

    if ($messageType !== 'error') {
        try {
            $st = strtotime($dateReleased);
            if ($st === false) {
                throw new Exception('Invalid Date Released to AFD.');
            }

            $formattedDate = date('F-d-Y', $st);
            $senderContactNo = getSenderContactNumber($currentDept, $currentDeptSenderTag);
            $senderDetailsBase = "Department of Human Settlements and Urban Development Region 4A\n" . $currentDeptSenderTag . "\n" . $senderContactNo . "\n\n" . "(" . $formattedDate . ")";
            $newFormatDate = date('ymd', $st);

            $parcelScopeTransmittalId = ($transmittalId !== '' ? $transmittalId : '');
            $departmentScope = buildMailtrackingDepartmentScope($currentDept);
            $batchParcelNo = null;
            $batchRow = null;
            if ($batchSourceRowId > 0) {
                $batchStmt = $pdo->prepare(
                    'SELECT `Parcel No.`, `Transmittal ID`, `Date released to AFD`, `Recipient Details`, `Sender Details`,
                            `File Name (PDF)`, `Tracking No.`, `Status`, `Transmittal Remarks/Received By`, `Date`, `Evaluator`
                     FROM mailtracking
                     WHERE id = :id
                     LIMIT 1'
                );
                $batchStmt->execute([':id' => $batchSourceRowId]);
                $batchRow = $batchStmt->fetch(PDO::FETCH_ASSOC);
                if ($batchRow) {
                    $batchParcelNo = (int)($batchRow['Parcel No.'] ?? 0);
                    if ($transmittalId === '' && !empty($batchRow['Transmittal ID'])) {
                        $transmittalId = trim((string)$batchRow['Transmittal ID']);
                        $parcelScopeTransmittalId = $transmittalId;
                    }
                    if ($dateReleased === '') $dateReleased = trim((string)($batchRow['Date released to AFD'] ?? ''));
                    if ($recipientDetails === '') $recipientDetails = trim((string)($batchRow['Recipient Details'] ?? ''));
                    if ($trackingNo === '') $trackingNo = trim((string)($batchRow['Tracking No.'] ?? ''));
                    if ($statusValue === '') $statusValue = trim((string)($batchRow['Status'] ?? ''));
                    if ($remarksValue === '') $remarksValue = trim((string)($batchRow['Transmittal Remarks/Received By'] ?? ''));
                    if ($eventDateValue === '') $eventDateValue = trim((string)($batchRow['Date'] ?? ''));
                    if ($evaluatorValue === '') $evaluatorValue = trim((string)($batchRow['Evaluator'] ?? ''));
                }
            }

            $batchId = null;
            $existingBatchId = '';
            if ($batchRow) {
                $existingBatchId = extractBatchIdFromSenderDetails($batchRow['Sender Details'] ?? '');
            }
            if ($requestedBatchId !== '') {
                $batchId = $requestedBatchId;
            } elseif ($existingBatchId !== '') {
                $batchId = $existingBatchId;
            } elseif ($batchSourceRowId > 0) {
                $batchId = 'BATCH-' . date('Ymd-His') . '-' . strtoupper(substr(bin2hex(random_bytes(2)), 0, 4));
            } elseif (count($pairs) > 1) {
                $batchId = 'BATCH-' . date('Ymd-His') . '-' . strtoupper(substr(bin2hex(random_bytes(2)), 0, 4));
            }

            $pdo->beginTransaction();

            if ($batchSourceRowId > 0 && $batchRow && $existingBatchId === '' && $batchId) {
                $currentSender = trim((string)($batchRow['Sender Details'] ?? ''));
                $updatedSender = $currentSender !== '' ? ($currentSender . "\nBatch ID: " . $batchId) : ("Batch ID: " . $batchId);
                $updStmt = $pdo->prepare('UPDATE mailtracking SET `Sender Details` = :sender WHERE id = :row_id');
                $updStmt->execute([':sender' => $updatedSender, ':row_id' => $batchSourceRowId]);
            }

            if ($batchParcelNo === null && $requestedBatchId !== '') {
                $batchLookup = $pdo->prepare(
                    'SELECT `Parcel No.`
                     FROM mailtracking
                     WHERE `Transmittal ID` = :transmittal_id
                       AND ' . $departmentScope['sql'] . '
                       AND `Sender Details` LIKE :batch_pattern
                     ORDER BY id ASC
                     LIMIT 1'
                );
                $batchParams = $departmentScope['params'];
                $batchParams[':transmittal_id'] = $parcelScopeTransmittalId;
                $batchParams[':batch_pattern'] = '%Batch ID: ' . $requestedBatchId . '%';
                $batchLookup->execute($batchParams);
                $batchParcelNo = $batchLookup->fetchColumn();
                if ($batchParcelNo !== false) {
                    $batchParcelNo = (int)$batchParcelNo;
                } else {
                    $batchParcelNo = null;
                }
            }
            if ($batchParcelNo !== null) {
                $parcelNo = $batchParcelNo;
            } else {
                $maxParcelStmt = $pdo->prepare(
                    'SELECT COALESCE(MAX(`Parcel No.`), 0)
                     FROM mailtracking
                     WHERE `Transmittal ID` = :transmittal_id
                       AND ' . $departmentScope['sql']
                );
                $parcelParams = $departmentScope['params'];
                $parcelParams[':transmittal_id'] = $parcelScopeTransmittalId;
                $maxParcelStmt->execute($parcelParams);
                $maxParcelNo = (int)$maxParcelStmt->fetchColumn();
                if ($maxParcelNo < 0) {
                    $maxParcelNo = 0;
                }
                $parcelNo = $maxParcelNo + 1;
            }
            $formattedParcelNo = sprintf("%03d", $parcelNo);
            $baseFileName = $currentDeptCode . "-" . $newFormatDate . "-" . $formattedParcelNo;

            if ($statusValue === '') $statusValue = '';
            if ($remarksValue === '') $remarksValue = '';
            if ($eventDateValue === '') $eventDateValue = '0000-00-00';
            if ($evaluatorValue === '') $evaluatorValue = '';

            $insertColumns = [
                '`Notice/Order Code`',
                '`Date released to AFD`',
                '`Parcel No.`',
                '`Recipient Details`',
                '`Parcel Details`',
                '`Sender Details`',
                '`File Name (PDF)`',
                '`Tracking No.`',
                '`Status`',
                '`Transmittal Remarks/Received By`',
                '`Date`',
                '`Evaluator`',
            ];
            $insertValues = [
                ':notice_code',
                ':date_released',
                ':parcel_no',
                ':recipient_details',
                ':parcel_details',
                ':sender_details',
                ':file_name',
                ':tracking_no',
                ':status_value',
                ':remarks_value',
                ':event_date',
                ':evaluator',
            ];
            if (mailtrackingHasDepartmentKey()) {
                $insertColumns[] = '`department_key`';
                $insertValues[] = ':department_key';
            }
            $insertColumns[] = '`Transmittal ID`';
            $insertValues[] = ':transmittal_id';

            $sql = 'INSERT INTO mailtracking 
                    (' . implode(', ', $insertColumns) . ') 
                    VALUES (' . implode(', ', $insertValues) . ')';
            $stmt = $pdo->prepare($sql);
            $inserted = 0;
            $insertedIds = [];
            foreach ($pairs as $index => $pair) {
                $senderDetails = $senderDetailsBase;
                if ($customSenderDetails !== '') {
                    $senderDetails = $customSenderDetails . "\n" . $senderDetails;
                }
                if ($batchId !== null) {
                    $senderDetails .= "\nBatch ID: " . $batchId;
                }

                $insertParams = [
                    ':notice_code' => $pair['notice'],
                    ':date_released' => $dateReleased,
                    ':parcel_no' => $parcelNo,
                    ':recipient_details' => $recipientDetails,
                    ':parcel_details' => $pair['parcel_details'],
                    ':sender_details' => $senderDetails,
                    ':file_name' => $baseFileName,
                    ':tracking_no' => $trackingNo,
                    ':status_value' => $statusValue,
                    ':remarks_value' => $remarksValue,
                    ':event_date' => $eventDateValue,
                    ':evaluator' => $evaluatorValue,
                    ':transmittal_id' => ($transmittalId !== '' ? $transmittalId : '')
                ];
                if (mailtrackingHasDepartmentKey()) {
                    $insertParams[':department_key'] = $currentDept;
                }
                $stmt->execute($insertParams);
                $newId = (int)$pdo->lastInsertId();
                if ($newId > 0) {
                    $insertedIds[] = $newId;
                }
                $inserted++;
            }
            $pdo->commit();

            $message = $inserted . ' record(s) added successfully.';
            if ($batchId !== null) {
                $message .= ' Batch ID: ' . $batchId;
            }
            $messageType = 'success';
            $success = true;
            error_log("SUCCESS: Added records = " . $inserted . " | Parcel No: " . $parcelNo . ($batchId ? " | Batch ID: " . $batchId : ""));
            $_POST = [];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $message = 'Error adding record(s): ' . $e->getMessage();
            $messageType = 'error';
            error_log("ERROR: " . $e->getMessage());
        }
    }
    
    // Return JSON for AJAX requests
    if ($isAjax) {
        header('Content-Type: application/json');
        $insertedNotices = array_map(function($p) {
            return $p['notice'] ?? '';
        }, $pairs ?? []);
        $insertedNotices = array_values(array_filter(array_map('trim', $insertedNotices), function($v) {
            return $v !== '';
        }));
        echo json_encode([
            'success' => $success,
            'message' => $message,
            'messageType' => $messageType,
            'parcelNo' => (int)($parcelNo ?? 0),
            'insertedIds' => array_values(array_map('intval', $insertedIds ?? [])),
            'firstId' => (int)(($insertedIds[0] ?? 0)),
            'insertedNotices' => $insertedNotices,
            'firstNotice' => $insertedNotices[0] ?? ''
        ]);
        exit;
    }
}
?>
