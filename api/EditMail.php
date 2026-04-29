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

    if (preg_match('/Department ID:\s*([^\r\n]+)/i', $senderText, $m)) {
        $resolved = trim((string)($m[1] ?? ''));
        if ($resolved !== '') return $resolved;
    }

    foreach (['emes', 'prls', 'afd', 'phsd', 'elupd', 'ord', 'hoa', 'lo', 'philpost'] as $deptKey) {
        $configuredTag = getDepartmentSenderTag($deptKey);
        if ($configuredTag !== '' && stripos($senderText, $configuredTag) !== false) {
            return $configuredTag;
        }
    }

    $noticeText = trim((string)$noticeCode);
    if (preg_match('/^([A-Z]+)-/i', $noticeText, $m)) {
        return getDepartmentSenderTag(strtolower(trim((string)$m[1])));
    }

    return getDepartmentSenderTag('emes');
}

function buildDefaultSenderDetails($dateReleasedValue, $batchId = '', $senderTag = 'HREDRD-EMES') {
    $dateText = '';
    $ts = strtotime((string)$dateReleasedValue);
    if ($ts !== false) {
        $dateText = date('F-d-Y', $ts);
    }

    $normalizedSenderTag = strtoupper(trim((string)$senderTag));
    if ($normalizedSenderTag === '') {
        $normalizedSenderTag = getDepartmentSenderTag('emes');
    }

    $senderContactNo = getSenderContactNumber('', $normalizedSenderTag);
    $sender = "Department of Human Settlements and Urban Development Region 4A\n" . $normalizedSenderTag . "\n" . $senderContactNo;
    if ($dateText !== '') {
        $sender .= "\n\n(" . $dateText . ")";
    }
    if (trim((string)$batchId) !== '') {
        $sender .= "\nBatch ID: " . trim((string)$batchId);
    }
    return $sender;
}

function buildDefaultPdfFileName($dateReleasedValue, $parcelNoValue, $departmentCode = 'EMES') {
    $ts = strtotime((string)$dateReleasedValue);
    if ($ts === false) return '';
    $formattedDate = date('ymd', $ts);
    $formattedParcelNo = sprintf('%03d', (int)$parcelNoValue);
    $code = strtoupper(trim((string)$departmentCode));
    if ($code === '') {
        $code = 'EMES';
    }
    return $code . '-' . $formattedDate . '-' . $formattedParcelNo;
}

function sanitizeTransmittalFolderName($value) {
    $name = trim((string)$value);
    if ($name === '') return 'UNASSIGNED';
    $name = preg_replace('/[\\\\\/:*?"<>|]+/', '_', $name);
    $name = preg_replace('/\s+/', ' ', $name);
    $name = trim($name, " .\t\n\r\0\x0B");
    return ($name !== '' ? $name : 'UNASSIGNED');
}

function sanitizeDepartmentFolderName($value) {
    $name = strtoupper(trim((string)$value));
    if ($name === '') return 'UNASSIGNED';
    $name = preg_replace('/[^A-Z0-9_-]+/', '_', $name);
    return ($name !== '' ? $name : 'UNASSIGNED');
}

function extractDepartmentCodeFromSender($senderText) {
    $raw = strtoupper(trim((string)$senderText));
    if ($raw === '') return 'UNASSIGNED';

    if (preg_match('/\bHREDRD[-\s]*([A-Z0-9]+)\b/', $raw, $m)) {
        $dept = trim((string)($m[1] ?? ''));
        if ($dept !== '') return $dept;
    }

    $known = ['EMES', 'PRLS', 'AFD', 'PHSD', 'ELUPD', 'ORD', 'HOA', 'LO', 'PHILPOST'];
    foreach ($known as $code) {
        if (strpos($raw, $code) !== false) return $code;
    }

    return 'UNASSIGNED';
}

function resolveArchiveTargetsForTracking($pdo, $trackingNo, $rowId = 0) {
    $targets = [];
    $hasDepartmentKey = mailtrackingHasDepartmentKey();

    $addTarget = function($transmittalRaw, $senderRaw, $departmentKeyRaw = '') use (&$targets) {
        $tid = trim((string)$transmittalRaw);
        $deptKey = normalizeDepartmentKey($departmentKeyRaw);
        $dept = ($deptKey !== 'emes' || strtolower(trim((string)$departmentKeyRaw)) === 'emes')
            ? getDepartmentCodeFromKey($deptKey)
            : '';
        if ($dept === '') {
            $dept = extractDepartmentCodeFromSender($senderRaw);
        }
        if ($tid === '') $tid = 'UNASSIGNED';
        if ($dept === '') $dept = 'UNASSIGNED';
        $key = $dept . '|' . $tid;
        $targets[$key] = [
            'department' => $dept,
            'transmittal' => $tid
        ];
    };

    if ($rowId > 0) {
        try {
            $selectSql = $hasDepartmentKey
                ? "SELECT `Transmittal ID`, `Sender Details`, `department_key` FROM mailtracking WHERE `id` = :row_id LIMIT 1"
                : "SELECT `Transmittal ID`, `Sender Details` FROM mailtracking WHERE `id` = :row_id LIMIT 1";
            $stmt = $pdo->prepare($selectSql);
            $stmt->execute([':row_id' => $rowId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($row) && !empty($row)) {
                $addTarget($row['Transmittal ID'] ?? '', $row['Sender Details'] ?? '', $row['department_key'] ?? '');
            }
        } catch (Throwable $e) {}
    }

    if (empty($targets)) {
        try {
            $selectSql = $hasDepartmentKey
                ? "SELECT DISTINCT `Transmittal ID`, `Sender Details`, `department_key` FROM mailtracking WHERE `Tracking No.` = :tracking"
                : "SELECT DISTINCT `Transmittal ID`, `Sender Details` FROM mailtracking WHERE `Tracking No.` = :tracking";
            $stmt = $pdo->prepare($selectSql);
            $stmt->execute([':tracking' => $trackingNo]);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $addTarget($row['Transmittal ID'] ?? '', $row['Sender Details'] ?? '', $row['department_key'] ?? '');
            }
        } catch (Throwable $e) {}
    }

    if (empty($targets)) {
        $targets['UNASSIGNED|UNASSIGNED'] = [
            'department' => 'UNASSIGNED',
            'transmittal' => 'UNASSIGNED'
        ];
    }

    return array_values($targets);
}

function resolveDesktopDownloadedPdfRoot() {
    $ensureWritable = function($dirPath) {
        $dir = rtrim((string)$dirPath, '\\/');
        if ($dir === '') return false;
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        if (!is_dir($dir)) return false;
        $probe = $dir . DIRECTORY_SEPARATOR . '.write_test_' . uniqid('', true) . '.tmp';
        $ok = (@file_put_contents($probe, 'ok') !== false);
        if ($ok && file_exists($probe)) {
            @unlink($probe);
        }
        return $ok;
    };

    $configuredDesktopRoot = trim((string)getConfiguredDesktopPdfRoot());
    if ($configuredDesktopRoot !== '') {
        if ($ensureWritable($configuredDesktopRoot)) return $configuredDesktopRoot;
    }

    $overrideRoot = trim((string)getenv('DHSUD_PDF_ROOT'));
    if ($overrideRoot !== '') {
        $overrideTarget = rtrim($overrideRoot, '\\/') . DIRECTORY_SEPARATOR . 'Downloaded_PDFs';
        if ($ensureWritable($overrideTarget)) return $overrideTarget;
    }

    $desktopCandidates = [];
    $oneDrive = trim((string)getenv('OneDrive'));
    if ($oneDrive !== '') $desktopCandidates[] = rtrim($oneDrive, '\\/') . DIRECTORY_SEPARATOR . 'Desktop';
    $oneDriveConsumer = trim((string)getenv('OneDriveConsumer'));
    if ($oneDriveConsumer !== '') $desktopCandidates[] = rtrim($oneDriveConsumer, '\\/') . DIRECTORY_SEPARATOR . 'Desktop';
    $oneDriveCommercial = trim((string)getenv('OneDriveCommercial'));
    if ($oneDriveCommercial !== '') $desktopCandidates[] = rtrim($oneDriveCommercial, '\\/') . DIRECTORY_SEPARATOR . 'Desktop';

    $userProfile = trim((string)getenv('USERPROFILE'));
    if ($userProfile !== '') $desktopCandidates[] = rtrim($userProfile, '\\/') . DIRECTORY_SEPARATOR . 'Desktop';

    $homeDrive = trim((string)getenv('HOMEDRIVE'));
    $homePath = trim((string)getenv('HOMEPATH'));
    if ($homeDrive !== '' && $homePath !== '') {
        $desktopCandidates[] = rtrim($homeDrive . $homePath, '\\/') . DIRECTORY_SEPARATOR . 'Desktop';
    }

    $home = trim((string)getenv('HOME'));
    if ($home !== '') $desktopCandidates[] = rtrim($home, '\\/') . DIRECTORY_SEPARATOR . 'Desktop';

    $publicDir = trim((string)getenv('PUBLIC'));
    if ($publicDir !== '') $desktopCandidates[] = rtrim($publicDir, '\\/') . DIRECTORY_SEPARATOR . 'Desktop';

    $desktopCandidates[] = 'C:\\Users\\Public\\Desktop';

    $seen = [];
    foreach ($desktopCandidates as $desktopDirRaw) {
        $desktopDir = rtrim((string)$desktopDirRaw, '\\/');
        if ($desktopDir === '') continue;
        $key = strtolower($desktopDir);
        if (isset($seen[$key])) continue;
        $seen[$key] = true;

        $target = $desktopDir . DIRECTORY_SEPARATOR . 'Downloaded_PDFs';
        if ($ensureWritable($target)) return $target;
    }

    $fallbackCandidates = [
        __DIR__ . '/../Downloaded_PDFs'
    ];
    foreach ($fallbackCandidates as $fallback) {
        if ($ensureWritable($fallback)) return $fallback;
    }

    return __DIR__ . '/../Downloaded_PDFs';
}

function archiveProofPdfByTransmittalFolders($pdo, $trackingNo, $pdfFile, $archiveRoot, $rowId = 0) {
    $targets = resolveArchiveTargetsForTracking($pdo, $trackingNo, (int)$rowId);
    $base = rtrim((string)$archiveRoot, '\\/');
    if (!is_dir($base)) {
        if (!@mkdir($base, 0777, true) && !is_dir($base)) {
            error_log('Failed to create archive root: ' . $base);
            return;
        }
    }
    $fileName = 'proof_' . $trackingNo . '.pdf';
    foreach ((array)$targets as $target) {
        $deptRaw = is_array($target) ? ($target['department'] ?? '') : '';
        $tidRaw = is_array($target) ? ($target['transmittal'] ?? '') : '';
        $deptFolder = sanitizeTransmittalFolderName($deptRaw);
        $transmittalFolder = sanitizeTransmittalFolderName($tidRaw);
        $dir = $base . DIRECTORY_SEPARATOR . $deptFolder . DIRECTORY_SEPARATOR . $transmittalFolder;
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0777, true) && !is_dir($dir)) {
                error_log('Failed to create archive directory: ' . $dir);
                continue;
            }
        }
        $dest = $dir . DIRECTORY_SEPARATOR . $fileName;
        if (!@copy($pdfFile, $dest)) {
            $pdfBytes = @file_get_contents($pdfFile);
            if ($pdfBytes === false || @file_put_contents($dest, $pdfBytes) === false) {
                error_log('Failed to archive PDF to: ' . $dest);
            }
        }
    }
}

function generateProofPdfForTracking($trackingNo, &$error = null, $rowId = 0) {
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

    global $pdo;
    $archiveTargets = resolveArchiveTargetsForTracking($pdo, $trackingNo, (int)$rowId);
    $primaryDepartmentFolder = sanitizeDepartmentFolderName(((is_array($archiveTargets[0] ?? null) ? ($archiveTargets[0]['department'] ?? '') : '')));
    $primaryTransmittalFolder = sanitizeTransmittalFolderName(((is_array($archiveTargets[0] ?? null) ? ($archiveTargets[0]['transmittal'] ?? '') : '')));

    $outputDir = __DIR__ . '/../JRS_PDFs/' . $primaryDepartmentFolder . '/' . $primaryTransmittalFolder;
    if (!is_dir($outputDir)) {
        if (!mkdir($outputDir, 0777, true) && !is_dir($outputDir)) {
            $error = 'Failed to create JRS_PDFs directory';
            return false;
        }
    }
    $pdfFile = rtrim($outputDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'proof_' . $trackingNo . '.pdf';

    $generateViaBrowserCli = function($url, $pdfFile, &$fallbackError = null) {
        $browserCandidates = [
            'C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe',
            'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
            'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
            'C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe',
        ];

        $browserExe = null;
        foreach ($browserCandidates as $candidate) {
            if (is_string($candidate) && $candidate !== '' && file_exists($candidate)) {
                $browserExe = $candidate;
                break;
            }
        }
        if ($browserExe === null) {
            $fallbackError = 'No supported browser executable found for fallback PDF generation.';
            return false;
        }

        $tmpUserData = rtrim(sys_get_temp_dir(), '\\/') . DIRECTORY_SEPARATOR . 'dhsud_pdf_' . uniqid('', true);
        @mkdir($tmpUserData, 0777, true);

        $argSets = [
            '--headless=new --disable-gpu --no-sandbox --disable-dev-shm-usage --virtual-time-budget=18000',
            '--headless --disable-gpu --no-sandbox --disable-dev-shm-usage --virtual-time-budget=18000',
        ];

        $lastOutput = [];
        $lastCode = 1;
        foreach ($argSets as $extraArgs) {
            $cmd = escapeshellarg($browserExe)
                . ' ' . $extraArgs
                . ' --user-data-dir=' . escapeshellarg($tmpUserData)
                . ' --print-to-pdf=' . escapeshellarg($pdfFile)
                . ' ' . escapeshellarg($url)
                . ' 2>&1';

            $output = [];
            $code = 1;
            exec($cmd, $output, $code);
            clearstatcache(true, $pdfFile);
            if ($code === 0 && file_exists($pdfFile) && filesize($pdfFile) > 0) {
                return true;
            }

            $lastOutput = $output;
            $lastCode = $code;
        }

        $fallbackError = 'Browser fallback failed (code ' . $lastCode . '): ' . implode("\n", $lastOutput);
        return false;
    };

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
        $fallbackError = null;
        if (!$generateViaBrowserCli($url, $pdfFile, $fallbackError)) {
            $details = implode("\n", $output);
            if ($fallbackError) {
                $details .= ($details !== '' ? "\n" : '') . 'Fallback: ' . $fallbackError;
            }
            $error = 'PDF generation failed (code ' . $returnCode . '): ' . $details;
            return false;
        }
    }

    try {
        $archiveRoot = resolveDesktopDownloadedPdfRoot();
        archiveProofPdfByTransmittalFolders($pdo, $trackingNo, $pdfFile, $archiveRoot, (int)$rowId);
    } catch (Throwable $e) {}

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
    $statusSubmitted = false;
    $submittedStatusValue = null;

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
        if ($col === 'Status') {
            $statusSubmitted = true;
            $submittedStatusValue = (string)$val;
        }
        
        error_log("  Column '{$col}' (POST key: '{$foundKey}') = '{$val}'");
    }

    $isTrackingExplicitlyCleared = $trackingSubmitted && (trim($submittedTrackingNo) === '' || trim($submittedTrackingNo) === '0');
    if ($statusSubmitted && trim((string)$submittedStatusValue) === '' && !$isTrackingExplicitlyCleared) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'No status selected.']);
        exit;
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

        // Notice/Order Code is optional in Edit; only update when a non-empty value is provided.
        if ($newNotice !== '' && $newNotice !== $originalNotice) {
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
    $senderSelectColumns = [
        '`Sender Details`',
        '`Tracking No.`',
        '`File Name (PDF)`',
        '`Date released to AFD`',
        '`Parcel No.`',
        '`Status`',
        '`Notice/Order Code`',
    ];
    if (mailtrackingHasDepartmentKey()) {
        $senderSelectColumns[] = '`department_key`';
    }
    $senderStmt = $pdo->prepare('SELECT ' . implode(', ', $senderSelectColumns) . ' FROM mailtracking WHERE `id` = :row_id LIMIT 1');
    $senderStmt->execute([':row_id' => $originalId]);
    $currentRecord = $senderStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $currentSenderDetails = (string)($currentRecord['Sender Details'] ?? '');
    $existingTrackingNo = trim((string)($currentRecord['Tracking No.'] ?? ''));
    $existingFileName = trim((string)($currentRecord['File Name (PDF)'] ?? ''));
    $existingStatus = trim((string)($currentRecord['Status'] ?? ''));
    $existingDepartmentKey = normalizeDepartmentKey($currentRecord['department_key'] ?? 'emes');
    $batchId = extractBatchIdFromSenderDetails($currentSenderDetails);
    $dateForDefaultSender = array_key_exists('Date released to AFD', $columnValues)
        ? $columnValues['Date released to AFD']
        : ($currentRecord['Date released to AFD'] ?? '');
    $parcelForDefault = array_key_exists('Parcel No.', $columnValues)
        ? $columnValues['Parcel No.']
        : ($currentRecord['Parcel No.'] ?? 0);
    $deptCodeForDefault = '';
    if ($existingDepartmentKey !== '') {
        $deptCodeForDefault = getDepartmentCodeFromKey($existingDepartmentKey);
    }
    if ($deptCodeForDefault === '') {
        $deptCodeForDefault = extractDepartmentCodeFromSender($currentSenderDetails);
    }
    $defaultPdfName = buildDefaultPdfFileName($dateForDefaultSender, $parcelForDefault, $deptCodeForDefault);

    // If tracking number is present, always keep File Name at the default format.
    if ($trackingSubmitted) {
        $trackingClean = trim((string)$submittedTrackingNo);
        $fileNameCol = 'File Name (PDF)';
        $currentFileNameValue = array_key_exists($fileNameCol, $columnValues)
            ? trim((string)$columnValues[$fileNameCol])
            : '';

        if ($trackingClean !== '' && $trackingClean !== '0' && $defaultPdfName !== '') {
            $columnValues[$fileNameCol] = $defaultPdfName;
            $fileParamName = ':p_' . preg_replace('/[^a-z0-9_]/i', '_', $fileNameCol);
            $params[$fileParamName] = $defaultPdfName;

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
        } elseif ($trackingClean !== '' && $trackingClean !== '0' && $defaultPdfName === '' && $currentFileNameValue !== '') {
            // Keep existing file name if a default can't be computed.
        }
    }

    // If tracking number is explicitly cleared, also clear tracking-derived fields.
    if ($isTrackingExplicitlyCleared) {
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
    if (mailtrackingHasDepartmentKey()) {
        $columnValues['department_key'] = $existingDepartmentKey;
        $departmentKeyParamName = ':p_department_key';
        $params[$departmentKeyParamName] = $existingDepartmentKey;
        $hasDepartmentKeyUpdate = false;
        foreach ($updates as $u) {
            if (strpos($u, '`department_key`') !== false) {
                $hasDepartmentKeyUpdate = true;
                break;
            }
        }
        if (!$hasDepartmentKeyUpdate) {
            $updates[] = '`department_key` = ' . $departmentKeyParamName;
        }
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
            // If record exists, treat as no-op instead of 404.
            $checkSql = 'SELECT `id` FROM mailtracking WHERE `id` = :check_id LIMIT 1';
            $checkStmt = $pdo->prepare($checkSql);
            $checkStmt->execute([':check_id' => $originalId]);
            $recordExists = $checkStmt->fetch() !== false;

            if ($recordExists) {
                $pdo->commit();
                echo json_encode([
                    'success' => true,
                    'message' => 'No changes applied',
                    'affected' => 0,
                    'pdfGenerated' => false,
                    'pdfWarning' => ''
                ]);
                exit;
            }

            $pdo->rollBack();
            http_response_code(404);
            echo json_encode([
                'success' => false,
                'message' => 'Record not found'
            ]);
            exit;
        }
        
        $pdo->commit();
        error_log('Update successful - Rows affected: ' . $affected);

        $pdfGenerated = false;
        $pdfWarning = '';
        if ($trackingSubmitted) {
            $trackingClean = trim($submittedTrackingNo);
            $proofFileName = $defaultPdfName;
            $proofTargets = resolveArchiveTargetsForTracking($pdo, $trackingClean, (int)$originalId);
            $proofDeptFolder = sanitizeDepartmentFolderName(((is_array($proofTargets[0] ?? null) ? ($proofTargets[0]['department'] ?? '') : '')));
            $proofTransmittalFolder = sanitizeTransmittalFolderName(((is_array($proofTargets[0] ?? null) ? ($proofTargets[0]['transmittal'] ?? '') : '')));
            $proofFilePath = __DIR__ . '/../JRS_PDFs/' . $proofDeptFolder . '/' . $proofTransmittalFolder . '/proof_' . $trackingClean . '.pdf';
            $finalStatus = array_key_exists('Status', $columnValues) ? $columnValues['Status'] : $existingStatus;
            $statusAllowsPdf = isPdfEligibleStatus($finalStatus);
            $trackingChanged = ($trackingClean !== '' && $trackingClean !== $existingTrackingNo);
            $fileMissing = (!file_exists($proofFilePath));
            $shouldGeneratePdf = ($trackingClean !== '' && $trackingClean !== '0') && $statusAllowsPdf && ($trackingChanged || $existingFileName === '' || $fileMissing);

            if ($shouldGeneratePdf) {
                $pdfError = null;
                $pdfGenerated = generateProofPdfForTracking($trackingClean, $pdfError, $originalId);
                if (!$pdfGenerated) {
                    $pdfWarning = 'Tracking saved, but PDF was not generated. ' . (string)$pdfError;
                    error_log($pdfWarning);
                }
            }

            // Apply generated/existing proof file name to all affected rows.
            // For batches: all rows with same Batch ID. For non-batch: only edited row.
            if ($trackingClean !== '' && $trackingClean !== '0' && $statusAllowsPdf && $proofFileName !== '' && file_exists($proofFilePath)) {
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
