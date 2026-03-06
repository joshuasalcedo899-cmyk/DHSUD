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
    ];
    $currentDept = strtolower(trim((string)($_POST['department_id'] ?? $_POST['dept'] ?? 'emes')));
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
    $transmittalId = trim($_POST['transmittal_id'] ?? $_POST['Transmittal ID'] ?? '');

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

            $batchId = null;
            if (count($pairs) > 1) {
                $batchId = 'BATCH-' . date('Ymd-His') . '-' . strtoupper(substr(bin2hex(random_bytes(2)), 0, 4));
            }

            $pdo->beginTransaction();

            $parcelScopeTransmittalId = ($transmittalId !== '' ? $transmittalId : '');
            $maxParcelStmt = $pdo->prepare(
                'SELECT COALESCE(MAX(`Parcel No.`), 0)
                 FROM mailtracking
                 WHERE `Transmittal ID` = :transmittal_id
                   AND UPPER(COALESCE(`Sender Details`, \'\')) LIKE :dept_sender'
            );
            $maxParcelStmt->execute([
                ':transmittal_id' => $parcelScopeTransmittalId,
                ':dept_sender' => '%' . strtoupper($currentDeptSenderTag) . '%'
            ]);
            $maxParcelNo = (int)$maxParcelStmt->fetchColumn();
            if ($maxParcelNo < 0) {
                $maxParcelNo = 0;
            }
            $parcelNo = $maxParcelNo + 1;
            $formattedParcelNo = sprintf("%03d", $parcelNo);
            $baseFileName = $currentDeptCode . "-" . $newFormatDate . "-" . $formattedParcelNo;

            $sql = 'INSERT INTO mailtracking 
                    (`Notice/Order Code`, `Date released to AFD`, `Parcel No.`, `Recipient Details`, 
                     `Parcel Details`, `Sender Details`, `File Name (PDF)`, `Tracking No.`, `Transmittal ID`) 
                    VALUES (:notice_code, :date_released, :parcel_no, :recipient_details, 
                            :parcel_details, :sender_details, :file_name, :tracking_no, :transmittal_id)';
            $stmt = $pdo->prepare($sql);
            $inserted = 0;
            foreach ($pairs as $index => $pair) {
                $senderDetails = $senderDetailsBase;
                if ($customSenderDetails !== '') {
                    $senderDetails = $customSenderDetails . "\n" . $senderDetails;
                }
                if ($batchId !== null) {
                    $senderDetails .= "\nBatch ID: " . $batchId;
                }

                $stmt->execute([
                    ':notice_code' => $pair['notice'],
                    ':date_released' => $dateReleased,
                    ':parcel_no' => $parcelNo,
                    ':recipient_details' => $recipientDetails,
                    ':parcel_details' => $pair['parcel_details'],
                    ':sender_details' => $senderDetails,
                    ':file_name' => $baseFileName,
                    ':tracking_no' => $trackingNo,
                    ':transmittal_id' => ($transmittalId !== '' ? $transmittalId : '')
                ]);
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
            'insertedNotices' => $insertedNotices,
            'firstNotice' => $insertedNotices[0] ?? ''
        ]);
        exit;
    }
}
?>
