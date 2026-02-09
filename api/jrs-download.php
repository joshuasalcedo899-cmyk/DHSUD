<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../dompdf/autoload.inc.php';

use Dompdf\Dompdf;
use Dompdf\Options;

$trackingNo = $_GET['tracking'] ?? '';

if (!$trackingNo) {
    die("No tracking number.");
}

// Fetch API data
$apiUrl = "https://jrs-core-api.azurewebsites.net/api/Tracking/v1/track-airbill?airbill=" . urlencode($trackingNo);
$json = @file_get_contents($apiUrl);
$data = json_decode($json, true);

// Build HTML for PDF
ob_start();

function formatDateTime($dt) {
    if (!$dt) return '';
    $ts = strtotime($dt);
    if ($ts === false) return htmlspecialchars($dt);
    return date('n/d/Y, g:i A', $ts);
}
?>
<!DOCTYPE html>
<html>
<head>
<style>
body { font-family: Arial, sans-serif; font-size: 12px; }
h2 { text-align: center; color:#22336A; }
table { width:100%; border-collapse: collapse; margin-top:10px; }
th, td { border:1px solid #000; padding:6px; font-size:11px; }
th { background:#22336A; color:white; }
</style>
</head>
<body>

<h2>JRS Tracking Report</h2>
<p><b>Tracking Number:</b> <?= htmlspecialchars($trackingNo) ?></p>

<table>
<tr>
    <th>Date & Time</th>
    <th>Status</th>
    <th>Remarks</th>
    <th>Branch Location</th>
</tr>

<?php if ($data): foreach ($data as $row): ?>
<tr>
    <td><?= formatDateTime($row['dateReceived'] ?? '') ?></td>
    <td><?= htmlspecialchars($row['statusText'] . (!empty($row['receiver']) ?  " " . $row['receiver'] : '')) ?>
        <?php
            
                if (!empty($row['relation'])) {
                    echo '<br>Relationship: ' . htmlspecialchars($row['relation']);
                }
        ?>
    </td>
    <td></td>
    <td><?= $row['branchLocation'] ?? '' ?></td>
</tr>
<?php endforeach; else: ?>
<tr><td colspan="4">No data found</td></tr>
<?php endif; ?>

</table>

</body>
</html>
<?php
$html = ob_get_clean();

// Dompdf settings
$options = new Options();
$options->set('isHtml5ParserEnabled', true);
$options->set('isRemoteEnabled', true);

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);

// A4 Portrait
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

// Preview in browser (IMPORTANT)
$dompdf->stream("JRS_Tracking_$trackingNo.pdf", ["Attachment" => true]);
