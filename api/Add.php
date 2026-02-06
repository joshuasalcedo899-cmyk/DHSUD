<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth.php';

if(!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized: Please log in']);
    exit;
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
    // Support both full column names and snake_case names
    $noticeCode = trim ($_POST['Notice/Order Code'] ?? $_POST['notice_Code'] ?? '');
    $dateReleased = trim($_POST['Date released to AFD'] ?? $_POST['dateReleased'] ?? '');
    $parcelNo = trim($_POST['Parcel No.'] ?? $_POST['parcelNo'] ?? '');
    $recipientDetails = trim($_POST['Recipient Details'] ?? $_POST['recipientDetails'] ?? '');
    $parcelDetails = trim($_POST['Parcel Details'] ?? $_POST['parcelDetails'] ?? '');
    $senderDetails = trim($_POST['Sender Details'] ?? $_POST['senderDetails'] ?? '');
    $fileName = trim($_POST['File Name (PDF)'] ?? $_POST['fileName'] ?? '');
    $trackingNo = trim($_POST['Tracking No.'] ?? $_POST['trackingNo'] ?? '');
    $transmittalRemarks = trim($_POST['Transmittal Remarks/Received By'] ?? $_POST['transmittalRemarks'] ?? '');

    error_log("noticeCode: '$noticeCode'");
    error_log("dateReleased: '$dateReleased'");
    

    // Validation
    if ($noticeCode === '') {
        $message = 'Notice/Order Code is required.';
        $messageType = 'error';
        error_log("ERROR: Notice code is empty");
    } elseif ($dateReleased === '') {
        $message = 'Date Released to AFD is required.';
        $messageType = 'error';
        error_log("ERROR: Date released is empty");
    } else {
        try {
            $sql = 'INSERT INTO mailtracking 
                    (`Notice/Order Code`, `Date released to AFD`, `Parcel No.`, `Recipient Details`, 
                     `Parcel Details`, `Sender Details`, `File Name (PDF)`, `Tracking No.`) 
                    VALUES (:notice_code, :date_released, :parcel_no, :recipient_details, 
                            :parcel_details, :sender_details, :file_name, :tracking_no)';
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':notice_code' => $noticeCode,
                ':date_released' => $dateReleased,
                ':parcel_no' => $parcelNo ? (int)$parcelNo : 0,
                ':recipient_details' => $recipientDetails,
                ':parcel_details' => $parcelDetails,
                ':sender_details' => $senderDetails,
                ':file_name' => $fileName,
                ':tracking_no' => $trackingNo
            ]);
            $message = 'Record added successfully!';
            $messageType = 'success';
            $success = true;
            error_log("SUCCESS: Record added");
            // Reset form
            $_POST = [];
        } catch (PDOException $e) {
            $message = 'Error adding record: ' . $e->getMessage();
            $messageType = 'error';
            error_log("ERROR: " . $e->getMessage());
        }
    }
    
    // Return JSON for AJAX requests
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => $success,
            'message' => $message,
            'messageType' => $messageType
        ]);
        exit;
    }
}
?>
