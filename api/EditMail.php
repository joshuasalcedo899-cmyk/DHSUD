<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth.php';

// Require login for API access - but handle JSON response for AJAX
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized: Please log in']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// DIAGNOSTIC: Log all POST data exactly as received
error_log('=== EDIT MAIL DIAGNOSTIC ===');
error_log('Timestamp: ' . date('Y-m-d H:i:s'));
error_log('POST keys: ' . implode(', ', array_keys($_POST)));
foreach ($_POST as $key => $val) {
    $displayVal = is_array($val) ? json_encode($val) : $val;
    error_log("  POST['{$key}'] = '" . $displayVal . "' (length: " . strlen($displayVal) . ")");
}

// Original notice code stored in hidden field (used to identify record)
$originalNotice = trim($_POST['original_notice_code'] ?? '');
$originalNoticeRaw = $_POST['original_notice_code'] ?? '';

error_log('Original Notice (raw): "' . $originalNoticeRaw . '" (len: ' . strlen($originalNoticeRaw) . ')');
error_log('Original Notice (trimmed): "' . $originalNotice . '" (len: ' . strlen($originalNotice) . ')');

// Validate original notice code is provided
if ($originalNotice === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing original_notice_code - cannot identify record to update']);
    exit;
}

// New/edited notice code (display field) - may be same as original
$newNotice = trim($_POST['Notice/Order Code'] ?? '');

// Map of editable DB columns to the expected POST key names
// Need to handle both encoded and non-encoded versions of field names
$columns = [
    'Date released to AFD' => ['Date released to AFD', 'Date_released_to_AFD'],
    'Parcel No.' => ['Parcel No.', 'Parcel_No_'],
    'Recipient Details' => ['Recipient Details', 'Recipient_Details'],
    'Parcel Details' => ['Parcel Details', 'Parcel_Details'],
    'Sender Details' => ['Sender Details', 'Sender_Details'],
    'File Name (PDF)' => ['File Name (PDF)', 'File_Name_(PDF)'],
    'Tracking No.' => ['Tracking No.', 'Tracking_No_'],
    'Status' => ['Status'],
    'Transmittal Remarks/Received By' => ['Transmittal Remarks/Received By', 'Transmittal_Remarks/Received_By'],
    'Date' => ['Date'],
    'Evaluator' => ['Evaluator'],
];

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

try {
    $updates = [];
    $params = [];

    // Process each editable column
    foreach ($columns as $col => $postKeys) {
        // postKeys is now an array of possible field names
        if (!is_array($postKeys)) {
            $postKeys = [$postKeys];
        }
        
        $postValue = null;
        $foundKey = null;
        
        // Try each possible POST key name
        foreach ($postKeys as $postKey) {
            if (array_key_exists($postKey, $_POST)) {
                $postValue = $_POST[$postKey];
                $foundKey = $postKey;
                break;
            }
        }
        
        // Skip if field not found in POST data
        if ($postValue === null) {
            continue;
        }
        
        // Get and trim value
        $val = trim((string)$postValue);
        
        // Convert numeric fields to proper types
        if (in_array($col, ['Parcel No.'])) {
            // Convert Parcel No. to int, default to 0 if empty
            $val = !empty($val) ? (int)$val : 0;
        }
        // Tracking No. stays as varchar/string
        
        // Create parameter name
        $pname = ':p_' . preg_replace('/[^a-z0-9_]/i', '_', $col);
        
        // Add to update list
        $updates[] = "`$col` = $pname";
        $params[$pname] = $val;
        
        error_log("  Column '{$col}' (POST key: '{$foundKey}') = '{$val}'");
    }

    // Check if Notice/Order Code was provided and is different from original
    $noticeCodePostKey = null;
    foreach (['Notice/Order Code', 'Notice/Order_Code'] as $key) {
        if (array_key_exists($key, $_POST)) {
            $noticeCodePostKey = $key;
            break;
        }
    }
    
    if ($noticeCodePostKey !== null) {
        $newNotice = trim($_POST[$noticeCodePostKey] ?? '');
        
        if ($newNotice === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Notice/Order Code cannot be empty']);
            exit;
        }
        
        if ($newNotice !== $originalNotice) {
            // Primary key changed - need UPDATE with new key
            $updates[] = "`Notice/Order Code` = :new_notice";
            $params[':new_notice'] = $newNotice;
            error_log("  Primary Key changed: '{$originalNotice}' -> '{$newNotice}'");
        }
    }

    // CRITICAL FIX: Check that at least one update was provided
    if (empty($updates)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'No fields to update']);
        exit;
    }

    // Build UPDATE query
    $sql = 'UPDATE mailtracking SET ' . implode(', ', $updates) . ' WHERE `Notice/Order Code` = :where_notice LIMIT 1';
    $params[':where_notice'] = $originalNotice;

    error_log('SQL: ' . $sql);
    error_log('Parameters: ' . json_encode($params));

    // Execute in transaction
    $pdo->beginTransaction();
    
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $affected = $stmt->rowCount();
        
        if ($affected === 0) {
            $pdo->rollBack();
            
            // Debug: Check if record exists at all
            $checkSql = 'SELECT `Notice/Order Code` FROM mailtracking WHERE `Notice/Order Code` = :check_notice LIMIT 1';
            $checkStmt = $pdo->prepare($checkSql);
            $checkStmt->execute([':check_notice' => $originalNotice]);
            $recordExists = $checkStmt->fetch() !== false;
            
            // Try to find similar records
            $allSql = 'SELECT `Notice/Order Code` FROM mailtracking ORDER BY `Notice/Order Code` LIMIT 10';
            $allStmt = $pdo->query($allSql);
            $allRecords = $allStmt->fetchAll(PDO::FETCH_COLUMN);
            
            $debugInfo = [
                'notice_code_sent' => $originalNotice,
                'notice_code_length' => strlen($originalNotice),
                'record_found' => $recordExists,
                'sample_records' => $allRecords
            ];
            
            error_log('UPDATE 0 rows - Debug: ' . json_encode($debugInfo));
            
            http_response_code(404);
            echo json_encode([
                'success' => false,
                'message' => 'Record not found',
                'debug' => $debugInfo
            ]);
            exit;
        }
        
        $pdo->commit();
        error_log('Update successful - Rows affected: ' . $affected);
        
        echo json_encode([
            'success' => true,
            'message' => 'Record updated successfully',
            'affected' => $affected
        ]);
        exit;
        
    } catch (PDOException $e) {
        $pdo->rollBack();
        throw $e;
    }

} catch (PDOException $e) {
    http_response_code(500);
    error_log('Database error: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
    exit;
} catch (Exception $e) {
    http_response_code(500);
    error_log('Error: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred'
    ]);
    exit;
}

?>
