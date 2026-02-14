<?php
$tracking = $_GET['tracking'] ?? '';
if (!$tracking) die("No tracking number provided");

$url = "https://jrs-express.com/track?or=" . urlencode($tracking);
$nodeScript = realpath(__DIR__ . "/script/savepdf.js");

if ($nodeScript === false) {
    die("savepdf.js not found at " . __DIR__ . "/script/savepdf.js");
}

$outputDir = __DIR__ . "/../JRS_PDFs";
if (!is_dir($outputDir)) {
    mkdir($outputDir, 0777, true);
}

$pdfFile = $outputDir . "/proof_$tracking.pdf";

$command = "node " . escapeshellarg($nodeScript) . " " . escapeshellarg($url) . " " . escapeshellarg($pdfFile) . " 2>&1";
exec($command, $output, $return_var);

if ($return_var !== 0 || !file_exists($pdfFile)) {
    echo "<pre>Return code: $return_var\nOutput:\n" . implode("\n", $output) . "</pre>";
    die("Error generating PDF");
}

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="proof_'.$tracking.'.pdf"');
readfile($pdfFile);
exit;
?>
    
