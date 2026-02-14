<?php
    $noticeCode = $_GET['code'] ?? '';
?>
<!DOCTYPE html>
<html>
<head>
    <title>QR Scanner</title>


    <script src="https://unpkg.com/html5-qrcode" type="text/javascript"></script>


    <style>
        body{
            font-family: Arial;
            text-align:center;
            background:#f2f2f2;
        }
        #reader{
            width:350px;
            margin:auto;
        }
        .result{
            margin-top:20px;
            font-size:20px;
            color:green;
            font-weight:bold;
        }
    </style>
</head>
<body>


<h2>Scan QR Code</h2>


<div id="reader"></div>


<div class="result" id="result">Waiting for scan...</div>


   




<script>


// get Notice/Order Code from URL
const noticeCode = "<?= htmlspecialchars($noticeCode) ?>";


function onScanSuccess(decodedText, decodedResult) {


    let trackingNumber = decodedText;


    if (decodedText.includes("or=")) {
        let url = new URL(decodedText);
        trackingNumber = url.searchParams.get("or");
    }


    document.getElementById("result").innerHTML =
        "Tracking Number: " + trackingNumber;


    fetch("api/get-tracking.php", {
        method: "POST",
        headers: {
            "Content-Type": "application/x-www-form-urlencoded"
        },
        body:
            "tracking=" + encodeURIComponent(trackingNumber) +
            "&codes[]=" + encodeURIComponent(noticeCode)
    })
    .then(res => res.text())
    .then(data => {
        document.getElementById("result").innerHTML = data;


        // Generate and save the PDF on the server in the background
        generateReceiptPDF(trackingNumber);


        // return to table
        setTimeout(() => {
            window.location.href = "pages/Home_Page.php?updated=1";
        }, 1500);
    });


    html5QrcodeScanner.clear();


   
}


function generateReceiptPDF(trackingNumber) {
    if (!trackingNumber) return;
    fetch(`api/download-receipt.php?tracking=${encodeURIComponent(trackingNumber)}`, {
        method: "GET",
        cache: "no-store"
    }).catch(() => {
        // Ignore errors here; the PDF generation failure will show in server logs/output
    });
}




var html5QrcodeScanner = new Html5QrcodeScanner(
    "reader",
    { fps: 10, qrbox: 250 }
);


html5QrcodeScanner.render(onScanSuccess);
</script>


</body>
</html>





