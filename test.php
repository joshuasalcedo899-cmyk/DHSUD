<?php
    $noticeCode = $_GET['code'] ?? '';
    $embedded = isset($_GET['embedded']) && $_GET['embedded'] === '1';
?>
<!DOCTYPE html>
<html>
<head>
    <title>QR Scanner</title>


    <script src="https://unpkg.com/@zxing/library@latest"></script>


    <style>
        body{
            font-family: Arial;
            text-align:center;
            background:#f2f2f2;
        }
        #reader{
            width:640px;
            margin:auto;
        }
        #preview{
            width:640px;
            max-width:100%;
            border-radius:8px;
            background:#000;
        }
        #cameraSelect{
            margin:12px auto 0;
            padding:8px;
            width:640px;
            max-width:100%;
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


<div id="reader">
    <video id="preview" autoplay muted playsinline></video>
</div>
<select id="cameraSelect"></select>


<div class="result" id="result">Waiting for scan...</div>

    


<script>


// get Notice/Order Code from URL
const noticeCode = "<?= htmlspecialchars($noticeCode) ?>";
const isEmbedded = <?= $embedded ? 'true' : 'false' ?>;
const isInIframe = window.self !== window.top;


let codeReader = null;
let activeControls = null;
let isProcessing = false;
const ZXING_HINTS = new Map();
ZXING_HINTS.set(ZXing.DecodeHintType.TRY_HARDER, true);
ZXING_HINTS.set(ZXing.DecodeHintType.POSSIBLE_FORMATS, [ZXing.BarcodeFormat.QR_CODE]);

function extractTrackingNumber(decodedText) {
    let trackingNumber = decodedText;
    if (decodedText.includes("or=")) {
        try {
            const url = new URL(decodedText);
            trackingNumber = url.searchParams.get("or") || decodedText;
        } catch (e) {
            // Keep original decoded text if not a valid URL.
        }
    }
    return trackingNumber;
}

function onScanSuccess(decodedText) {
    if (isProcessing) return;
    isProcessing = true;


    const trackingNumber = extractTrackingNumber(decodedText);


    document.getElementById("result").innerHTML =
        "Tracking Number: " + trackingNumber;


    fetch("api/get-tracking.php", {
        method: "POST",
        headers: {
            "Content-Type": "application/x-www-form-urlencoded",
            "X-Requested-With": "XMLHttpRequest"
        },
        body:
            "tracking=" + encodeURIComponent(trackingNumber) +
            "&codes[]=" + encodeURIComponent(noticeCode)
    })
    .then(async (res) => {
        if (!res.ok || res.redirected) {
            throw new Error("Failed to save tracking number.");
        }
        document.getElementById("result").innerHTML = "Tracking number saved.";

        // Only generate PDF for final statuses.
        const shouldDownloadPdf = await shouldGeneratePdfByStatus(noticeCode);
        if (shouldDownloadPdf) {
            await generateReceiptPDF(trackingNumber);
        }

        if ((isEmbedded || isInIframe) && window.parent && window.parent !== window) {
            await stopCurrentStream();
            window.parent.postMessage({
                type: "scanner-success",
                noticeCode: noticeCode,
                trackingNumber: trackingNumber
            }, window.location.origin);
            return;
        }

        // return to table
        window.location.href = "pages/Home_Page.php?updated=1&scanned_notice=" + encodeURIComponent(noticeCode);
    })
    .catch((error) => {
        console.error("Tracking update failed:", error);
        document.getElementById("result").innerHTML = "Failed to process scanned QR.";
        isProcessing = false;
    });
}

async function shouldGeneratePdfByStatus(noticeCode) {
    if (!noticeCode) return false;
    try {
        const response = await fetch("api/remarks.php", {
            method: "POST",
            headers: {
                "Content-Type": "application/x-www-form-urlencoded",
                "X-Requested-With": "XMLHttpRequest"
            },
            body: "notice_code=" + encodeURIComponent(noticeCode)
        });

        if (!response.ok) return false;
        const data = await response.json();
        if (data.error) return false;

        const status = ((data.status || "") + "").trim().toUpperCase();
        return status === "DELIVERED" || status === "RETURNED TO SENDER";
    } catch (error) {
        console.error("Status check failed:", error);
        return false;
    }
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


async function stopCurrentStream() {
    if (activeControls && typeof activeControls.stop === "function") {
        try { activeControls.stop(); } catch (e) {}
        activeControls = null;
    }
}

async function tryApplyZoomIfSupported(targetZoom) {
    const video = document.getElementById("preview");
    if (!video || !video.srcObject) return;

    const tracks = video.srcObject.getVideoTracks ? video.srcObject.getVideoTracks() : [];
    if (!tracks.length) return;

    const track = tracks[0];
    if (!track.getCapabilities || !track.applyConstraints) return;

    try {
        const caps = track.getCapabilities();
        if (!caps.zoom) return;

        const min = Number(caps.zoom.min);
        const max = Number(caps.zoom.max);
        const step = Number(caps.zoom.step || 0.1);
        let zoom = targetZoom;

        if (Number.isFinite(min)) zoom = Math.max(min, zoom);
        if (Number.isFinite(max)) zoom = Math.min(max, zoom);
        if (Number.isFinite(step) && step > 0) {
            zoom = Math.round(zoom / step) * step;
        }

        await track.applyConstraints({ advanced: [{ zoom: zoom }] });
    } catch (e) {
        console.warn("Zoom not supported:", e);
    }
}

async function startScanner(deviceId) {
    if (!codeReader) {
        codeReader = new ZXing.BrowserMultiFormatReader(ZXING_HINTS, 120);
    }

    await stopCurrentStream();
    isProcessing = false;

    const constraints = {
        video: {
            deviceId: deviceId ? { exact: deviceId } : undefined,
            width: { ideal: 1920 },
            height: { ideal: 1080 },
            frameRate: { ideal: 30, max: 30 },
            focusMode: "continuous",
            advanced: [{ focusMode: "continuous" }]
        }
    };

    try {
        activeControls = await codeReader.decodeFromConstraints(constraints, "preview", (result, err) => {
            if (result) {
                onScanSuccess(result.getText ? result.getText() : String(result));
            }
        });
    } catch (e) {
        // Fallback for cameras/browsers that reject advanced constraints.
        activeControls = await codeReader.decodeFromVideoDevice(
            deviceId || undefined,
            "preview",
            (result, err) => {
                if (result) {
                    onScanSuccess(result.getText ? result.getText() : String(result));
                }
            }
        );
    }

    localStorage.setItem("preferredCameraId", deviceId || "");
    setTimeout(() => { tryApplyZoomIfSupported(2.0); }, 1000);
}

async function initScanner() {
    const cameraSelect = document.getElementById("cameraSelect");

    if (!window.ZXing || !ZXing.BrowserMultiFormatReader) {
        document.getElementById("result").innerHTML = "ZXing library failed to load.";
        return;
    }

    codeReader = new ZXing.BrowserMultiFormatReader(ZXING_HINTS, 120);
    const devices = await codeReader.listVideoInputDevices();

    if (!devices || devices.length === 0) {
        document.getElementById("result").innerHTML = "No camera device found.";
        return;
    }

    cameraSelect.innerHTML = "";
    devices.forEach((device, index) => {
        const opt = document.createElement("option");
        opt.value = device.deviceId;
        opt.text = device.label || ("Camera " + (index + 1));
        cameraSelect.appendChild(opt);
    });

    const savedCamera = localStorage.getItem("preferredCameraId");
    const defaultDevice = devices.find(d => d.deviceId === savedCamera) || devices[0];
    cameraSelect.value = defaultDevice.deviceId;

    cameraSelect.addEventListener("change", async function() {
        await startScanner(cameraSelect.value);
    });

    await startScanner(defaultDevice.deviceId);
}

window.addEventListener("beforeunload", function() {
    stopCurrentStream();
});

initScanner().catch((e) => {
    console.error(e);
    document.getElementById("result").innerHTML = "Unable to initialize camera scanner.";
});
</script>


</body>
</html>





