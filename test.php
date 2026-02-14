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
    .then(async (data) => {
        document.getElementById("result").innerHTML = data;

        // Generate and save the PDF on the server before returning to table.
        await generateReceiptPDF(trackingNumber);

        // return to table
        window.location.href = "pages/Home_Page.php?updated=1";
    });


    html5QrcodeScanner.clear();


   
}

async function generateReceiptPDF(trackingNumber) {
    if (!trackingNumber) return;

    // Avoid indefinite waiting; still attempt generation.
    const timeoutMs = 90000;
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), timeoutMs);

    try {
        const response = await fetch(`api/download-receipt.php?tracking=${encodeURIComponent(trackingNumber)}`, {
            method: "GET",
            cache: "no-store",
            signal: controller.signal
        });

        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }

        // Consume body so request fully completes server-side.
        await response.blob();
    } catch (error) {
        console.error("PDF generation failed:", error);
    } finally {
        clearTimeout(timeout);
    }
}


var html5QrcodeScanner = new Html5QrcodeScanner(
    "reader",
    { fps: 10, qrbox: 250 }
);


html5QrcodeScanner.render(onScanSuccess);
</script>


</body>
</html>





