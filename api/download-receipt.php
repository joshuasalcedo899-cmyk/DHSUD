<?php
require_once __DIR__ . '/../config.php';

$tracking = $_GET['tracking'] ?? '';
$silent = isset($_GET['silent']) && (string)$_GET['silent'] === '1';
$rowId = (int)($_GET['row_id'] ?? 0);
$transmittalId = trim((string)($_GET['transmittal_id'] ?? ''));
$departmentHint = trim((string)($_GET['dept'] ?? ''));

function endWithError($message, $silentMode, $outputLines = []) {
    if ($silentMode) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => (string)$message,
            'output' => $outputLines
        ]);
        exit;
    }
    if (!empty($outputLines)) {
        echo "<pre>Output:\n" . implode("\n", $outputLines) . "</pre>";
    }
    die((string)$message);
}

if (!$tracking) {
    endWithError('No tracking number provided', $silent);
}

function generatePdfViaBrowserCli($url, $pdfFile, &$error = null) {
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
        $error = 'No supported browser executable found for fallback PDF generation.';
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

    $error = 'Browser fallback failed (code ' . $lastCode . '): ' . implode("\n", $lastOutput);
    return false;
}

function sanitizeTransmittalFolderName($value) {
    $name = trim((string)$value);
    if ($name === '') return 'UNASSIGNED';
    $name = preg_replace('/[\\\\\/:*?"<>|]+/', '_', $name);
    $name = preg_replace('/\s+/', ' ', $name);
    $name = trim($name, " .\t\n\r\0\x0B");
    return ($name !== '' ? $name : 'UNASSIGNED');
}

function extractDepartmentCodeFromSender($senderText) {
    $raw = strtoupper(trim((string)$senderText));
    if ($raw === '') return 'UNASSIGNED';

    if (preg_match('/\bHREDRD[-\s]*([A-Z0-9]+)\b/', $raw, $m)) {
        $dept = trim((string)($m[1] ?? ''));
        if ($dept !== '') return $dept;
    }

    $known = ['EMES', 'PRLS', 'AFD', 'PHSD', 'ELUPD', 'ORD'];
    foreach ($known as $code) {
        if (strpos($raw, $code) !== false) return $code;
    }

    return 'UNASSIGNED';
}

function normalizeDepartmentCode($rawValue) {
    $txt = strtoupper(trim((string)$rawValue));
    if ($txt === '') return '';

    if (preg_match('/\bHREDRD[-\s]*([A-Z0-9]+)\b/', $txt, $m)) {
        $v = strtoupper(trim((string)($m[1] ?? '')));
        if ($v !== '') return $v;
    }

    $map = [
        'EMES' => 'EMES',
        'PRLS' => 'PRLS',
        'AFD' => 'AFD',
        'PHSD' => 'PHSD',
        'ELUPD' => 'ELUPD',
        'ORD' => 'ORD',
    ];
    if (isset($map[$txt])) return $map[$txt];
    return '';
}

function resolveArchiveTargetsForTracking($pdo, $trackingNo, $rowId = 0, $explicitTransmittalId = '', $forcedDepartment = '') {
    $targets = [];
    $forcedDeptCode = normalizeDepartmentCode($forcedDepartment);

    $addTarget = function($transmittalRaw, $senderRaw) use (&$targets, $forcedDeptCode) {
        $tid = trim((string)$transmittalRaw);
        $dept = $forcedDeptCode !== '' ? $forcedDeptCode : extractDepartmentCodeFromSender($senderRaw);
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
            $stmt = $pdo->prepare("SELECT `Transmittal ID`, `Sender Details` FROM mailtracking WHERE `id` = :row_id LIMIT 1");
            $stmt->execute([':row_id' => $rowId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($row) && !empty($row)) {
                $addTarget($row['Transmittal ID'] ?? '', $row['Sender Details'] ?? '');
            }
        } catch (Throwable $e) {}
    }

    if (empty($targets)) {
        try {
            $stmt = $pdo->prepare("SELECT DISTINCT `Transmittal ID`, `Sender Details` FROM mailtracking WHERE `Tracking No.` = :tracking");
            $stmt->execute([':tracking' => $trackingNo]);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $addTarget($row['Transmittal ID'] ?? '', $row['Sender Details'] ?? '');
            }
        } catch (Throwable $e) {}
    }

    $explicitTid = trim((string)$explicitTransmittalId);
    if ($explicitTid !== '') {
        $existing = false;
        foreach ($targets as $target) {
            if (strcasecmp((string)$target['transmittal'], $explicitTid) === 0) {
                $existing = true;
                break;
            }
        }
        if (!$existing) {
            $fallbackDept = ($forcedDeptCode !== '' ? $forcedDeptCode : 'UNASSIGNED');
            if (!empty($targets)) {
                $first = reset($targets);
                if (is_array($first) && !empty($first['department'])) {
                    $fallbackDept = (string)$first['department'];
                }
            }
            $addTarget($explicitTid, $fallbackDept);
        }
    }

    if (empty($targets)) {
        $targets['UNASSIGNED|UNASSIGNED'] = [
            'department' => ($forcedDeptCode !== '' ? $forcedDeptCode : 'UNASSIGNED'),
            'transmittal' => ($explicitTid !== '' ? $explicitTid : 'UNASSIGNED')
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
        __DIR__ . '/../JRS_PDFs/Downloaded_PDFs',
        __DIR__ . '/../Downloaded_PDFs'
    ];
    foreach ($fallbackCandidates as $fallback) {
        if ($ensureWritable($fallback)) return $fallback;
    }

    return __DIR__ . '/../JRS_PDFs/Downloaded_PDFs';
}

function archivePdfByTransmittalFolders($pdfFile, $trackingNo, $archiveRoot, $archiveTargets) {
    if (!file_exists($pdfFile)) return;
    $base = rtrim((string)$archiveRoot, '\\/');
    if (!is_dir($base)) {
        @mkdir($base, 0777, true);
    }
    $fileName = 'proof_' . $trackingNo . '.pdf';
    foreach ((array)$archiveTargets as $target) {
        $deptRaw = is_array($target) ? ($target['department'] ?? '') : '';
        $tidRaw = is_array($target) ? ($target['transmittal'] ?? '') : '';
        $deptFolder = sanitizeTransmittalFolderName($deptRaw);
        $transmittalFolder = sanitizeTransmittalFolderName($tidRaw);
        $dir = $base . DIRECTORY_SEPARATOR . $deptFolder . DIRECTORY_SEPARATOR . $transmittalFolder;
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        $dest = $dir . DIRECTORY_SEPARATOR . $fileName;
        @copy($pdfFile, $dest);
    }
}

$url = "https://jrs-express.com/track?or=" . urlencode($tracking);
$nodeScript = realpath(__DIR__ . "/script/savepdf.js");

if ($nodeScript === false) {
    endWithError("savepdf.js not found at " . __DIR__ . "/script/savepdf.js", $silent);
}

$outputDir = __DIR__ . "/../JRS_PDFs";
if (!is_dir($outputDir)) {
    mkdir($outputDir, 0777, true);
}

$pdfFile = $outputDir . "/proof_$tracking.pdf";

// Resolve node executable explicitly for Apache/PHP environments without PATH configured.
$nodeCandidates = [
    getenv('NODE_PATH') ?: null,
    'C:\\Program Files\\nodejs\\node.exe',
    'C:\\Program Files (x86)\\nodejs\\node.exe',
    'node',
];

$nodeExecutable = null;
foreach ($nodeCandidates as $candidate) {
    if (!$candidate) {
        continue;
    }
    if ($candidate === 'node' || file_exists($candidate)) {
        $nodeExecutable = $candidate;
        break;
    }
}

if ($nodeExecutable === null) {
    endWithError("Node.js executable not found. Set NODE_PATH or install Node.js.", $silent);
}

$command = escapeshellarg($nodeExecutable) . " " . escapeshellarg($nodeScript) . " " . escapeshellarg($url) . " " . escapeshellarg($pdfFile) . " 2>&1";
exec($command, $output, $return_var);

if ($return_var !== 0 || !file_exists($pdfFile)) {
    $fallbackError = null;
    if (!generatePdfViaBrowserCli($url, $pdfFile, $fallbackError)) {
        $fullOutput = $output;
        if ($fallbackError) {
            $fullOutput[] = 'Fallback: ' . $fallbackError;
        }
        endWithError("Error generating PDF (return code: $return_var)", $silent, $fullOutput);
    }
}

$archiveTargets = resolveArchiveTargetsForTracking($pdo, $tracking, $rowId, $transmittalId, $departmentHint);
$archiveRoot = resolveDesktopDownloadedPdfRoot();
archivePdfByTransmittalFolders($pdfFile, $tracking, $archiveRoot, $archiveTargets);

if ($silent) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'tracking' => $tracking,
        'file' => basename($pdfFile)
    ]);
    exit;
}

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="proof_'.$tracking.'.pdf"');
readfile($pdfFile);
exit;
?>
    
