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
.signatory-space { height: 105px; }
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
        <td style=\"white-space: pre-wrap; word-break: break-word; overflow-wrap: anywhere;\">{$r['Recipient Details']}</td>
        <td style=\"white-space: pre-wrap; word-break: break-word; overflow-wrap: anywhere;\">{$r['Parcel Details']}<br>{$r['Notice/Order Code']}</td>
    </tr>";
    $no++;
}

$html .= '</table><div class="signatory-space"></div></body></html>';

// DOMPDF Setup
$options = new Options();
$options->set('defaultFont', 'DejaVu Sans');
$options->set('isRemoteEnabled', true);

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

// Draw signatory block only on the last page, bottom-left.
$canvas = $dompdf->getCanvas();
$fontMetrics = $dompdf->getFontMetrics();
$fontRegular = $fontMetrics->getFont('DejaVu Sans', 'normal');
$fontBold = $fontMetrics->getFont('DejaVu Sans', 'bold');

$canvas->page_script(function ($pageNumber, $pageCount, $canvas, $fontMetrics) use ($fontRegular, $fontBold) {
    if ($pageNumber !== $pageCount) {
        return;
    }

    $leftX = 72; // 2.54cm
    $bottomMargin = 14.2; // 0.5cm
    $boxWidth = 170;
    $boxHeight = 58;

    $pageHeight = $canvas->get_height();
    $boxY = $pageHeight - $bottomMargin - $boxHeight;
    $titleY = $boxY - 30;

    $canvas->text($leftX, $titleY, 'Prepared by:', $fontRegular, 8, [0, 0, 0]);
    $canvas->text($leftX, $titleY + 13, 'Cindy A. Trasmano', $fontBold, 8, [0, 0, 0]);

    $line1 = 'Received by:';
    $line2 = 'Date:';
    $line3 = 'Time:';
    $fontSize = 8;
    $labelStartX = $leftX;
    $firstLineY = $boxY + 10;
    $lineGap = 14;

    $canvas->text($labelStartX, $firstLineY, $line1, $fontRegular, $fontSize, [0, 0, 0]);
    $canvas->text($labelStartX, $firstLineY + $lineGap, $line2, $fontRegular, $fontSize, [0, 0, 0]);
    $canvas->text($labelStartX, $firstLineY + ($lineGap * 2), $line3, $fontRegular, $fontSize, [0, 0, 0]);
});

// Download PDF
$filename = "DHSUD_Report_" . date("Ymd_His") . ".pdf";
$dompdf->stream($filename, ["Attachment" => false]);
exit;
