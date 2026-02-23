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
    endWithError("Error generating PDF (return code: $return_var)", $silent, $output);
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
    
