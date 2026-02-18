<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../dompdf/autoload.inc.php';

use Dompdf\Dompdf;
use Dompdf\Options;

if (!isset($_POST['notice_codes'])) {
    die("No records selected.");
}

$codes = json_decode($_POST['notice_codes'], true);
if (!$codes) die("Invalid data.");

// Fetch records
$placeholders = implode(',', array_fill(0, count($codes), '?'));
$sql = "SELECT * FROM mailtracking WHERE `Notice/Order Code` IN ($placeholders)";
$stmt = $pdo->prepare($sql);
$stmt->execute($codes);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// HTML Template
$html = '
<html>
<head>
<style>
@page {
    margin: 0.5cm 2.54cm 0.5cm 2.54cm; /* top right bottom left */
}

body { font-family: DejaVu Sans, sans-serif; font-size: 11px; }
h2 { text-align:center; color:#22336A; }
table { width:100%; border-collapse: collapse; }
th { background:#22336A; color:white; padding:6px; font-size:10px; }
td { max-width: 200px; border:1px solid #000; padding:4px; font-size:9px; word-wrap:break-word; word-break: break-word; white-space: normal; text-align:center; }
.shipper-label { font-weight: bold; }
.date-label { font-weight: bold; }
.title { font-weight: bold; font-size: 18px;}
</style>
</head>
<body>
<center><span class="title"><p>DEPARTMENT OF HUMAN SETTLEMENTS AND URBAN DEVELOPMENT REGIONAL OFFICE 4A (CALABARZON)</span><span class="shipper-label">SHIPPER</span>: HREDRD-EMES<br><span class="date-label">DATE</span>: '.date("F-d-Y").'</p></center><br>

<table>
<tr>
<th>No.</th>
<th>Recipient Details</th>
<th>Type of Letter</th>
</tr>';

$no = 1;

foreach ($rows as $r) {
    $html .= "<tr>
        <td>{$no}</td>
        <td>{$r['Recipient Details']}</td>
        <td>{$r['Parcel Details']}<br>{$r['Notice/Order Code']}</td>
    </tr>";
    $no++;
}

$html .= '</table></body></html>';

// DOMPDF Setup
$options = new Options();
$options->set('defaultFont', 'DejaVu Sans');
$options->set('isRemoteEnabled', true);

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

// Download PDF
$filename = "DHSUD_Report_" . date("Ymd_His") . ".pdf";
$dompdf->stream($filename, ["Attachment" => false]);
exit;

