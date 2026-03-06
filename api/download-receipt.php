<?php
$tracking = $_GET['tracking'] ?? '';
$silent = isset($_GET['silent']) && (string)$_GET['silent'] === '1';

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
    
