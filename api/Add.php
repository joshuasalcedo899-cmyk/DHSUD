<?php
require_once __DIR__ . '/../config.php';

$message = '';
$messageType = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $noticeCode = trim($_POST['notice_code'] ?? '');
    $dateReleased = trim($_POST['date_released'] ?? '');
    $parcelNo = trim($_POST['parcel_no'] ?? '');
    $recipientDetails = trim($_POST['recipient_details'] ?? '');
    $parcelDetails = trim($_POST['parcel_details'] ?? '');
    $senderDetails = trim($_POST['sender_details'] ?? '');
    $fileName = trim($_POST['file_name'] ?? '');
    $trackingNo = trim($_POST['tracking_no'] ?? '');
    $status = trim($_POST['status'] ?? '');
    $transmittalRemarks = trim($_POST['transmittal_remarks'] ?? '');
    $date = trim($_POST['date'] ?? '');
    $evaluator = trim($_POST['evaluator'] ?? '');

    // Validation
    if ($noticeCode === '') {
        $message = 'Notice/Order Code is required.';
        $messageType = 'error';
    } elseif ($dateReleased === '') {
        $message = 'Date Released to AFD is required.';
        $messageType = 'error';
    } else {
        try {
            $sql = 'INSERT INTO mailtracking 
                    (`Notice/Order Code`, `Date released to AFD`, `Parcel No.`, `Recipient Details`, 
                     `Parcel Details`, `Sender Details.`, `File Name (PDF)`, `Tracking No.`, 
                     `Status`, `Transmittal Remarks/Received By`, `Date`, `Evaluator`) 
                    VALUES (:notice_code, :date_released, :parcel_no, :recipient_details, 
                            :parcel_details, :sender_details, :file_name, :tracking_no, 
                            :status, :transmittal_remarks, :date, :evaluator)';
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':notice_code' => $noticeCode,
                ':date_released' => $dateReleased,
                ':parcel_no' => $parcelNo ? (int)$parcelNo : 0,
                ':recipient_details' => $recipientDetails,
                ':parcel_details' => $parcelDetails,
                ':sender_details' => $senderDetails,
                ':file_name' => $fileName,
                ':tracking_no' => $trackingNo ? (int)$trackingNo : 0,
                ':status' => $status,
                ':transmittal_remarks' => $transmittalRemarks,
                ':date' => $date,
                ':evaluator' => $evaluator
            ]);
            $message = 'Record added successfully!';
            $messageType = 'success';
            // Reset form
            $_POST = [];
        } catch (PDOException $e) {
            $message = 'Error adding record: ' . $e->getMessage();
            $messageType = 'error';
        }
    }
}

$statusOptions = ['DELIVERED', 'RETURNED TO SENDER', 'ON GOING DELIVERY', 'PERSONALLY RECEIVED'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add New Record</title>
    <link rel="stylesheet" href="../main.css">
    <style>
        body { font-family: Arial, sans-serif; background-color: #f5f5f5; }
        .container { max-width: 900px; margin: 40px auto; padding: 20px; background: white; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h1 { text-align: center; color: #333; margin-bottom: 30px; }
        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 5px; font-weight: bold; color: #555; }
        input[type="text"], input[type="date"], input[type="number"], textarea, select { 
            width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; box-sizing: border-box; font-size: 14px;
        }
        textarea { resize: vertical; min-height: 80px; }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        .form-row.full { grid-template-columns: 1fr; }
        .button-group { text-align: center; margin-top: 30px; }
        button { padding: 12px 30px; margin: 0 10px; border: none; border-radius: 4px; cursor: pointer; font-size: 16px; font-weight: bold; }
        .btn-submit { background-color: #4CAF50; color: white; }
        .btn-submit:hover { background-color: #45a049; }
        .btn-reset { background-color: #f44336; color: white; }
        .btn-reset:hover { background-color: #da190b; }
        .btn-back { background-color: #2196F3; color: white; }
        .btn-back:hover { background-color: #0b7dda; }
        .message { padding: 15px; margin-bottom: 20px; border-radius: 4px; }
        .message.success { background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .message.error { background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
    </style>
</head>
<body>
    <div class="container">
        <h1>Add New Record</h1>
        
        <?php if ($message): ?>
            <div class="message <?= $messageType ?>"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <form method="POST">
            <div class="form-row">
                <div class="form-group">
                    <label for="notice_code">Notice/Order Code *</label>
                    <input type="text" id="notice_code" name="notice_code" value="<?= htmlspecialchars($_POST['notice_code'] ?? '') ?>" required>
                </div>
                <div class="form-group">
                    <label for="date_released">Date Released to AFD *</label>
                    <input type="date" id="date_released" name="date_released" value="<?= htmlspecialchars($_POST['date_released'] ?? '') ?>" required>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="parcel_no">Parcel No.</label>
                    <input type="number" id="parcel_no" name="parcel_no" value="<?= htmlspecialchars($_POST['parcel_no'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label for="tracking_no">Tracking No.</label>
                    <input type="number" id="tracking_no" name="tracking_no" value="<?= htmlspecialchars($_POST['tracking_no'] ?? '') ?>">
                </div>
            </div>

            <div class="form-group form-row full">
                <label for="recipient_details">Recipient Details</label>
                <textarea id="recipient_details" name="recipient_details"><?= htmlspecialchars($_POST['recipient_details'] ?? '') ?></textarea>
            </div>

            <div class="form-group form-row full">
                <label for="parcel_details">Parcel Details</label>
                <textarea id="parcel_details" name="parcel_details"><?= htmlspecialchars($_POST['parcel_details'] ?? '') ?></textarea>
            </div>

            <div class="form-group form-row full">
                <label for="sender_details">Sender Details</label>
                <textarea id="sender_details" name="sender_details"><?= htmlspecialchars($_POST['sender_details'] ?? '') ?></textarea>
            </div>

            <div class="form-group form-row full">
                <label for="file_name">File Name (PDF)</label>
                <input type="text" id="file_name" name="file_name" value="<?= htmlspecialchars($_POST['file_name'] ?? '') ?>">
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="status">Status</label>
                    <select id="status" name="status">
                        <option value="">-- Select Status --</option>
                        <?php foreach ($statusOptions as $opt): ?>
                            <option value="<?= htmlspecialchars($opt) ?>" <?= (($_POST['status'] ?? '') === $opt) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($opt) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="date">Date</label>
                    <input type="date" id="date" name="date" value="<?= htmlspecialchars($_POST['date'] ?? '') ?>">
                </div>
            </div>

            <div class="form-group form-row full">
                <label for="transmittal_remarks">Transmittal Remarks / Received By</label>
                <textarea id="transmittal_remarks" name="transmittal_remarks"><?= htmlspecialchars($_POST['transmittal_remarks'] ?? '') ?></textarea>
            </div>

            <div class="form-group form-row full">
                <label for="evaluator">Evaluator</label>
                <input type="text" id="evaluator" name="evaluator" value="<?= htmlspecialchars($_POST['evaluator'] ?? '') ?>">
            </div>

            <div class="button-group">
                <button type="submit" class="btn-submit">Add Record</button>
                <button type="reset" class="btn-reset">Clear Form</button>
                <button type="button" class="btn-back" onclick="history.back()">Back</button>
            </div>
        </form>
    </div>
</body>
</html>