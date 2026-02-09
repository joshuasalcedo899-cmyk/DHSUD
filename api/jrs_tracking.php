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
    margin: 2.54cm 2.54cm 2.54cm 2.54cm; /* top right bottom left */
}

body { font-family: DejaVu Sans, sans-serif; font-size: 11px; }
h2 { text-align:center; color:#22336A; }
table { width:100%; border-collapse: collapse; }
th { background:#22336A; color:white; padding:6px; font-size:10px; }
td { border:1px solid #000; padding:4px; font-size:9px; word-wrap:break-word; white-space:pre-wrap; }
</style>
</head>
<body>

<h2>DEPARTMENT OF HUMAN SETTLEMENT AND URBAN DEVELOPMENT REGIONAL OFFICE 4A (CALABARZON)</h2>
<center><h4>SHIPPER: HREDRD-EMES<br>DATE: '.date("F-d-Y").'</h4></center>

<table>
<tr>
<th>Recipient Details</th>
<th>Parcel Details</th>
<th>Tracking No.</th>
</tr>';

foreach ($rows as $r) {
    $html .= "<tr>
        <td>{$r['Recipient Details']}</td>
        <td>{$r['Parcel Details']}</td>
        <td>{$r['Tracking No.']}</td>
    </tr>";
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

