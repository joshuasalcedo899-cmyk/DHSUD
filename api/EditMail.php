<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth.php';

// Require login for API access
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Original notice code stored in hidden field
$originalNotice = trim($_POST['original_notice_code'] ?? '');
// New/edited notice code (display field)
$newNotice = trim($_POST['Notice/Order Code'] ?? $originalNotice);

if ($originalNotice === '' && $newNotice === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing Notice/Order Code']);
    exit;
}

// Map of editable DB columns to the expected POST key names
// We will only include columns in the UPDATE when the corresponding POST key exists.
$columns = [
    'Date released to AFD' => 'Date released to AFD',
    'Parcel No.' => 'Parcel No.',
    'Recipient Details' => 'Recipient Details',
    'Parcel Details' => 'Parcel Details',
    'Sender Details' => 'Sender Details',
    'File Name (PDF)' => 'File Name (PDF)',
    'Tracking No.' => 'Tracking No.',
    'Status' => 'Status',
    'Transmittal Remarks/Received By' => 'Transmittal Remarks/Received By',
    'Date' => 'Date',
    'Evaluator' => 'Evaluator',
];

try {
    $updates = [];
    $params = [];

    // Basic validation: if the form sent an empty required field, reject
    if (array_key_exists('Notice/Order Code', $_POST) && trim($_POST['Notice/Order Code']) === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Notice/Order Code cannot be empty']);
        exit;
    }
    if (array_key_exists('Date released to AFD', $_POST) && trim($_POST['Date released to AFD']) === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Date released to AFD cannot be empty']);
        exit;
    }

    // Prepare update fragments and params only for provided POST keys
    foreach ($columns as $col => $postKey) {
        if (!array_key_exists($postKey, $_POST)) {
            continue; // skip if this field was not sent in the request
        }
        $val = trim((string)($_POST[$postKey] ?? ''));
        $pname = 'p_' . preg_replace('/[^a-z0-9_]/i', '_', $col);
        $updates[] = "`$col` = :$pname";
        $params[":$pname"] = $val;
    }

    // If Notice/Order Code was provided in POST and changed, include it
    if (array_key_exists('Notice/Order Code', $_POST) && $newNotice !== $originalNotice) {
        $updates[] = "`Notice/Order Code` = :new_notice";
        $params[':new_notice'] = $newNotice;
    }

    if (empty($updates)) {
        echo json_encode(['success' => true, 'message' => 'No changes to update']);
        exit;
    }

    // Build WHERE clause: prefer original notice when available
    $whereNotice = ($originalNotice !== '') ? $originalNotice : $newNotice;
    $sql = 'UPDATE mailtracking SET ' . implode(', ', $updates) . ' WHERE `Notice/Order Code` = :where_notice';
    $params[':where_notice'] = $whereNotice;

    // Execute inside a transaction to ensure atomic update
    $pdo->beginTransaction();
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $affected = $stmt->rowCount();
    $pdo->commit();

    echo json_encode(['success' => true, 'message' => 'Record updated', 'affected' => $affected]);
    exit;

} catch (PDOException $e) {
    if ($pdo && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    // include SQL and params for debugging in development
    $errMsg = 'Database error: ' . $e->getMessage();
    echo json_encode(['success' => false, 'message' => $errMsg]);
    exit;
}

?>
