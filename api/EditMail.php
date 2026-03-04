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
requireCsrfToken();

// DIAGNOSTIC: Log all POST data exactly as received
error_log('=== EDIT MAIL DIAGNOSTIC ===');
error_log('Timestamp: ' . date('Y-m-d H:i:s'));
error_log('POST keys: ' . implode(', ', array_keys($_POST)));
foreach ($_POST as $key => $val) {
    $displayVal = is_array($val) ? json_encode($val) : $val;
    error_log("  POST['{$key}'] = '" . $displayVal . "' (length: " . strlen($displayVal) . ")");
}

// Original row id (primary key) and notice code (for compatibility/logging).
$originalId = (int)($_POST['original_id'] ?? 0);
$originalNotice = trim($_POST['original_notice_code'] ?? '');
$originalNoticeRaw = $_POST['original_notice_code'] ?? '';

error_log('Original ID: ' . $originalId);
error_log('Original Notice (raw): "' . $originalNoticeRaw . '" (len: ' . strlen($originalNoticeRaw) . ')');
error_log('Original Notice (trimmed): "' . $originalNotice . '" (len: ' . strlen($originalNotice) . ')');

function extractBatchIdFromSenderDetails($senderDetails) {
    $text = trim((string)$senderDetails);
    if ($text === '') return '';
    if (preg_match('/Batch ID:\s*([A-Za-z0-9\-]+)/i', $text, $m)) {
        return trim($m[1]);
    }
    return '';
}

function detectSenderTag($senderDetails, $noticeCode = '') {
    $senderText = (string)$senderDetails;
    if (preg_match('/\bHREDRD-([A-Z]+)\b/i', $senderText, $m)) {
        return 'HREDRD-' . strtoupper(trim((string)$m[1]));
    }

    $noticeText = trim((string)$noticeCode);
    if (preg_match('/^([A-Z]+)-/i', $noticeText, $m)) {
        return 'HREDRD-' . strtoupper(trim((string)$m[1]));
    }

    return 'HREDRD-EMES';
}

function buildDefaultSenderDetails($dateReleasedValue, $batchId = '', $senderTag = 'HREDRD-EMES') {
    $dateText = '';
    $ts = strtotime((string)$dateReleasedValue);
    if ($ts !== false) {
        $dateText = date('F-d-Y', $ts);
    }

    $normalizedSenderTag = strtoupper(trim((string)$senderTag));
    if ($normalizedSenderTag === '') {
        $normalizedSenderTag = 'HREDRD-EMES';
    }

    $sender = "Department of Human Settlements and Urban Development Region 4A\n" . $normalizedSenderTag . "\n0935 542 1538";
    if ($dateText !== '') {
        $sender .= "\n\n(" . $dateText . ")";
    }
    if (trim((string)$batchId) !== '') {
        $sender .= "\nBatch ID: " . trim((string)$batchId);
    }
    return $sender;
}

function generateProofPdfForTracking($trackingNo, &$error = null) {
    $trackingNo = trim((string)$trackingNo);
    if ($trackingNo === '' || $trackingNo === '0') {
        $error = 'Invalid tracking number';
        return false;
    }

    $url = 'https://jrs-express.com/track?or=' . urlencode($trackingNo);
    $nodeScript = realpath(__DIR__ . '/script/savepdf.js');
    if ($nodeScript === false) {
        $error = 'savepdf.js not found';
        return false;
    }

    $outputDir = realpath(__DIR__ . '/../JRS_PDFs');
    if ($outputDir === false) {
        $outputDir = __DIR__ . '/../JRS_PDFs';
        if (!is_dir($outputDir) && !mkdir($outputDir, 0777, true)) {
            $error = 'Failed to create JRS_PDFs directory';
            return false;
        }
    }

    $pdfFile = rtrim($outputDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'proof_' . $trackingNo . '.pdf';

    $nodeCandidates = [
        getenv('NODE_PATH') ?: null,
        'C:\\Program Files\\nodejs\\node.exe',
        'C:\\Program Files (x86)\\nodejs\\node.exe',
        'node',
    ];
    $nodeExecutable = null;
    foreach ($nodeCandidates as $candidate) {
        if (!$candidate) continue;
        if ($candidate === 'node' || file_exists($candidate)) {
            $nodeExecutable = $candidate;
            break;
        }
    }
    if ($nodeExecutable === null) {
        $error = 'Node.js executable not found';
        return false;
    }

    $command = escapeshellarg($nodeExecutable) . ' '
        . escapeshellarg($nodeScript) . ' '
        . escapeshellarg($url) . ' '
        . escapeshellarg($pdfFile) . ' 2>&1';
    $output = [];
    $returnCode = 1;
    exec($command, $output, $returnCode);

    if ($returnCode !== 0 || !file_exists($pdfFile)) {
        $error = 'PDF generation failed (code ' . $returnCode . '): ' . implode("\n", $output);
        return false;
    }

    return true;
}

function isPdfEligibleStatus($statusValue) {
    $s = strtoupper(trim((string)$statusValue));
    return ($s === 'DELIVERED' || $s === 'RETURNED TO SENDER');
}

// Validate original primary key is provided
if ($originalId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing original_id - cannot identify record to update']);
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
    $columnValues = [];
    $trackingSubmitted = false;
    $submittedTrackingNo = '';

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
        $columnValues[$col] = $val;
        if ($col === 'Tracking No.') {
            $trackingSubmitted = true;
            $submittedTrackingNo = (string)$val;
        }
        
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
            error_log("  Notice/Order Code changed: '{$originalNotice}' -> '{$newNotice}'");
        }
    }

    // CRITICAL FIX: Check that at least one update was provided
    if (empty($updates)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'No fields to update']);
        exit;
    }

    // Determine if this record belongs to a batch.
    $batchId = '';
    $senderStmt = $pdo->prepare('SELECT `Sender Details`, `Tracking No.`, `File Name (PDF)`, `Date released to AFD`, `Status`, `Notice/Order Code` FROM mailtracking WHERE `id` = :row_id LIMIT 1');
    $senderStmt->execute([':row_id' => $originalId]);
    $currentRecord = $senderStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $currentSenderDetails = (string)($currentRecord['Sender Details'] ?? '');
    $existingTrackingNo = trim((string)($currentRecord['Tracking No.'] ?? ''));
    $existingFileName = trim((string)($currentRecord['File Name (PDF)'] ?? ''));
    $existingStatus = trim((string)($currentRecord['Status'] ?? ''));
    $batchId = extractBatchIdFromSenderDetails($currentSenderDetails);
    $dateForDefaultSender = array_key_exists('Date released to AFD', $columnValues)
        ? $columnValues['Date released to AFD']
        : ($currentRecord['Date released to AFD'] ?? '');

    // If tracking number is present but File Name is empty, auto-assign proof_<tracking>.pdf.
    if ($trackingSubmitted) {
        $trackingClean = trim((string)$submittedTrackingNo);
        $fileNameCol = 'File Name (PDF)';
        $currentFileNameValue = array_key_exists($fileNameCol, $columnValues)
            ? trim((string)$columnValues[$fileNameCol])
            : '';

        if ($trackingClean !== '' && $trackingClean !== '0' && $currentFileNameValue === '') {
            $autoFileName = 'proof_' . $trackingClean . '.pdf';
            $columnValues[$fileNameCol] = $autoFileName;
            $fileParamName = ':p_' . preg_replace('/[^a-z0-9_]/i', '_', $fileNameCol);
            $params[$fileParamName] = $autoFileName;

            $hasFileNameUpdate = false;
            foreach ($updates as $u) {
                if (strpos($u, '`' . $fileNameCol . '`') !== false) {
                    $hasFileNameUpdate = true;
                    break;
                }
            }
            if (!$hasFileNameUpdate) {
                $updates[] = '`' . $fileNameCol . '` = ' . $fileParamName;
            }
        }
    }

    // If tracking number is explicitly cleared, also clear tracking-derived fields.
    if ($trackingSubmitted && (trim($submittedTrackingNo) === '' || trim($submittedTrackingNo) === '0')) {
        $dependentTrackingFields = [
            'Status' => '',
            'Date' => '',
            'Transmittal Remarks/Received By' => '',
            'File Name (PDF)' => '',
        ];

        foreach ($dependentTrackingFields as $depCol => $depVal) {
            $columnValues[$depCol] = $depVal;
            $depParamName = ':p_' . preg_replace('/[^a-z0-9_]/i', '_', $depCol);
            $params[$depParamName] = $depVal;

            $hasDependentUpdate = false;
            foreach ($updates as $u) {
                if (strpos($u, "`{$depCol}`") !== false) {
                    $hasDependentUpdate = true;
                    break;
                }
            }
            if (!$hasDependentUpdate) {
                $updates[] = "`{$depCol}` = {$depParamName}";
            }
        }
    }

    // Always enforce canonical sender details on edit while preserving the record department.
    $senderTag = detectSenderTag($currentSenderDetails, (string)($currentRecord['Notice/Order Code'] ?? $originalNotice));
    $defaultSender = buildDefaultSenderDetails($dateForDefaultSender, $batchId, $senderTag);
    $columnValues['Sender Details'] = $defaultSender;
    $senderParamName = ':p_' . preg_replace('/[^a-z0-9_]/i', '_', 'Sender Details');
    $params[$senderParamName] = $defaultSender;
    $hasSenderUpdate = false;
    foreach ($updates as $u) {
        if (strpos($u, '`Sender Details`') !== false) {
            $hasSenderUpdate = true;
            break;
        }
    }
    if (!$hasSenderUpdate) {
        $updates[] = '`Sender Details` = ' . $senderParamName;
    }

    // Execute in transaction
    $pdo->beginTransaction();
    
    try {
        $affected = 0;

        // If batch row: apply shared fields to all rows in batch.
        // Keep Parcel Details row-specific.
        if ($batchId !== '') {
            $sharedCols = array_diff(array_keys($columnValues), ['Parcel Details']);
            if (!empty($sharedCols)) {
                $sharedUpdates = [];
                $sharedParams = [];
                foreach ($sharedCols as $col) {
                    $pname = ':s_' . preg_replace('/[^a-z0-9_]/i', '_', $col);
                    $val = $columnValues[$col];

                    // Safety: in batch updates, avoid wiping Recipient if modal sent it empty.
                    if ($col === 'Recipient Details' && trim((string)$val) === '') {
                        continue;
                    }

                    $sharedUpdates[] = "`$col` = $pname";
                    $sharedParams[$pname] = $val;
                }

                if (!empty($sharedUpdates)) {
                    $sharedSql = 'UPDATE mailtracking SET ' . implode(', ', $sharedUpdates) . ' WHERE `Sender Details` LIKE :batch_like';
                    $sharedParams[':batch_like'] = '%Batch ID: ' . $batchId . '%';
                    error_log('Batch SQL: ' . $sharedSql);
                    error_log('Batch Parameters: ' . json_encode($sharedParams));
                    $sharedStmt = $pdo->prepare($sharedSql);
                    $sharedStmt->execute($sharedParams);
                    $affected += $sharedStmt->rowCount();
                }
            }
        }

        // Always update row-specific fields on the selected record
        // (Parcel Details and optional Notice/Order Code change).
        $rowOnlyUpdates = [];
        $rowOnlyParams = [];
        if (array_key_exists('Parcel Details', $columnValues)) {
            $rowOnlyUpdates[] = '`Parcel Details` = :r_parcel_details';
            $rowOnlyParams[':r_parcel_details'] = $columnValues['Parcel Details'];
        }
        if (isset($params[':new_notice'])) {
            $rowOnlyUpdates[] = '`Notice/Order Code` = :r_new_notice';
            $rowOnlyParams[':r_new_notice'] = $params[':new_notice'];
        }

        // Non-batch: update all submitted fields on selected row exactly as before.
        if ($batchId === '') {
            $rowOnlyUpdates = $updates;
            $rowOnlyParams = [];
            foreach ($params as $k => $v) {
                if ($k === ':where_id') continue;
                $rowOnlyParams[$k] = $v;
            }
        }

        if (!empty($rowOnlyUpdates)) {
            $rowSql = 'UPDATE mailtracking SET ' . implode(', ', $rowOnlyUpdates) . ' WHERE `id` = :where_id LIMIT 1';
            $rowOnlyParams[':where_id'] = $originalId;
            error_log('Row SQL: ' . $rowSql);
            error_log('Row Parameters: ' . json_encode($rowOnlyParams));
            $rowStmt = $pdo->prepare($rowSql);
            $rowStmt->execute($rowOnlyParams);
            $affected += $rowStmt->rowCount();
        }
        
        if ($affected === 0) {
            $pdo->rollBack();
            
            // Debug: Check if record exists at all
            $checkSql = 'SELECT `id` FROM mailtracking WHERE `id` = :check_id LIMIT 1';
            $checkStmt = $pdo->prepare($checkSql);
            $checkStmt->execute([':check_id' => $originalId]);
            $recordExists = $checkStmt->fetch() !== false;
            
            // Try to find similar records
            $allSql = 'SELECT `id`, `Notice/Order Code` FROM mailtracking ORDER BY `id` LIMIT 10';
            $allStmt = $pdo->query($allSql);
            $allRecords = $allStmt->fetchAll(PDO::FETCH_ASSOC);
            
            $debugInfo = [
                'row_id_sent' => $originalId,
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

        $pdfGenerated = false;
        $pdfWarning = '';
        if ($trackingSubmitted) {
            $trackingClean = trim($submittedTrackingNo);
            $proofFileName = 'proof_' . $trackingClean . '.pdf';
            $proofFilePath = __DIR__ . '/../JRS_PDFs/' . $proofFileName;
            $finalStatus = array_key_exists('Status', $columnValues) ? $columnValues['Status'] : $existingStatus;
            $statusAllowsPdf = isPdfEligibleStatus($finalStatus);
            $trackingChanged = ($trackingClean !== '' && $trackingClean !== $existingTrackingNo);
            $fileMissing = (!file_exists($proofFilePath));
            $shouldGeneratePdf = ($trackingClean !== '' && $trackingClean !== '0') && $statusAllowsPdf && ($trackingChanged || $existingFileName === '' || $fileMissing);

            if ($shouldGeneratePdf) {
                $pdfError = null;
                $pdfGenerated = generateProofPdfForTracking($trackingClean, $pdfError);
                if (!$pdfGenerated) {
                    $pdfWarning = 'Tracking saved, but PDF was not generated. ' . (string)$pdfError;
                    error_log($pdfWarning);
                }
            }

            // Apply generated/existing proof file name to all affected rows.
            // For batches: all rows with same Batch ID. For non-batch: only edited row.
            if ($trackingClean !== '' && $trackingClean !== '0' && $statusAllowsPdf && file_exists($proofFilePath)) {
                if ($batchId !== '') {
                    $fileStmt = $pdo->prepare('UPDATE mailtracking SET `File Name (PDF)` = :file_name WHERE `Sender Details` LIKE :batch_like');
                    $fileStmt->execute([
                        ':file_name' => $proofFileName,
                        ':batch_like' => '%Batch ID: ' . $batchId . '%'
                    ]);
                } else {
                    $fileStmt = $pdo->prepare('UPDATE mailtracking SET `File Name (PDF)` = :file_name WHERE `id` = :row_id LIMIT 1');
                    $fileStmt->execute([
                        ':file_name' => $proofFileName,
                        ':row_id' => $originalId
                    ]);
                }
            }
        }
        
        echo json_encode([
            'success' => true,
            'message' => 'Record updated successfully',
            'affected' => $affected,
            'pdfGenerated' => $pdfGenerated,
            'pdfWarning' => $pdfWarning
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
