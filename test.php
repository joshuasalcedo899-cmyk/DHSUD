<?php
    $noticeCode = $_GET['code'] ?? '';
    $embedded = isset($_GET['embedded']) && $_GET['embedded'] === '1';
?>
<!DOCTYPE html>
<html>
<head>
    <title>QR Scanner</title>
    <link rel="icon" type="image/x-icon" href="assets/DHSUDLogo.ico">


    <script src="https://unpkg.com/@zxing/library@latest"></script>


    <style>
        body{
            font-family: Arial;
            text-align:center;
            background:#f2f2f2;
            margin:0;
            min-height:100vh;
            display:flex;
            flex-direction:column;
            justify-content:center;
            align-items:center;
            padding:12px;
            box-sizing:border-box;
        }
        #cameraSection,
        #usbSection{
            width:100%;
            display:flex;
            flex-direction:column;
            align-items:center;
        }
        #reader{
            width:640px;
            max-width:100%;
            margin:auto;
        }
        #preview{
            width:100%;
            height:100%;
            border-radius:8px;
            background:#000;
            display:block;
            object-fit:cover;
        }
        .usb-reference-image{
            width:100%;
            height:100%;
            display:block;
            object-fit:cover;
            border-radius:8px;
            background:#111827;
        }
        .polydev-qrcode-animate {
            width: 100%;
            overflow: hidden;
            display: flex;
            justify-content: center;
            align-items: center;
            position: relative;
        }
        .qa-info .scan-qa {
            position: relative;
            width: min(360px, 100%);
            aspect-ratio: 1 / 1;
            border: 4px solid #fff;
            border-radius: 8px;
            overflow: hidden;
            background: #000;
            box-shadow: 0 10px 24px rgba(0, 0, 0, 0.2);
        }
        .qa-info .scan-qa .scan-animation {
            width: 100%;
            height: 12px;
            position: absolute;
            animation-name: scanQr;
            animation-duration: 4s;
            animation-iteration-count: infinite;
            top: 0;
            left: 0;
            right: 0;
            z-index: 2;
            pointer-events: none;
        }
        @keyframes scanQr {
            0% {
                background: linear-gradient(0deg, #ff5b23 0, rgba(255, 167, 137, 0.54) 24.43%, rgba(255, 255, 255, 0) 100%);
                top: 0;
            }
            50% {
                background: linear-gradient(0deg, #ff5b23 0, rgba(255, 167, 137, 0.54) 24.43%, rgba(255, 255, 255, 0) 100%);
                top: calc(100% - 12px);
            }
            51% {
                background: linear-gradient(180deg, #ff5b23 0, rgba(255, 167, 137, 0.54) 24.43%, rgba(255, 255, 255, 0) 100%);
                top: calc(100% - 12px);
            }
            100% {
                background: linear-gradient(180deg, #ff5b23 0, rgba(255, 167, 137, 0.54) 24.43%, rgba(255, 255, 255, 0) 100%);
                top: 0;
            }
        }
        .flash {
            animation: flash-animation 0.5s ease-in-out 3;
        }
        @keyframes flash-animation {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.35; }
        }
        .scan-mode-controls{
            width:640px;
            max-width:100%;
            margin:10px auto 0;
            display:flex;
            gap:8px;
            align-items:center;
        }
        .scan-mode-controls label{
            font-size:14px;
            color:#1f2937;
            white-space:nowrap;
            font-weight:600;
        }
        #scanModeSelect{
            flex:1 1 auto;
            min-width:0;
            padding:8px;
        }
        #cameraSelect{
            padding:8px;
            width:100%;
            max-width:100%;
        }
        .camera-controls{
            width:640px;
            max-width:100%;
            margin:12px auto 0;
            display:flex;
            gap:8px;
            align-items:center;
        }
        .camera-controls label{
            font-size:14px;
            color:#1f2937;
            white-space:nowrap;
        }
        #refreshCamerasBtn{
            border:1px solid #cbd5e1;
            background:#fff;
            color:#22336A;
            padding:8px 10px;
            border-radius:6px;
            cursor:pointer;
            font-weight:600;
        }
        #refreshCamerasBtn:hover{
            background:#f8fafc;
        }
        .hidden-mode{
            display:none !important;
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


<div class="scan-mode-controls">
    <label for="scanModeSelect">Scanner Mode:</label>
    <select id="scanModeSelect" aria-label="Select scanner mode">
        <option value="camera">Camera</option>
        <option value="usb">USB Barcode Scanner</option>
    </select>
</div>

<div id="cameraSection">
    <div id="reader" class="polydev-qrcode-animate">
        <div class="qa-info">
            <div class="scan-qa" id="scanFrame">
                <video id="preview" autoplay muted playsinline></video>
                <div class="scan-animation" aria-hidden="true"></div>
            </div>
        </div>
    </div>
    <div class="camera-controls">
        <label for="cameraSelect">Available Device:</label>
        <select id="cameraSelect" aria-label="Available camera devices"></select>
        <button type="button" id="refreshCamerasBtn">Refresh</button>
    </div>
</div>

<div id="usbSection">
    <div id="reader" class="polydev-qrcode-animate">
        <div class="qa-info">
            <div class="scan-qa" id="usbScanFrame">
                <img src="assets/qr.jpg" alt="QR Reference" class="usb-reference-image">
                <div class="scan-animation" aria-hidden="true"></div>
            </div>
        </div>
    </div>
</div>


<div class="result" id="result">Waiting for scan...</div>

    


<script>


// get Notice/Order Code from URL
const noticeCode = "<?= htmlspecialchars($noticeCode) ?>";
const isEmbedded = <?= $embedded ? 'true' : 'false' ?>;
const isInIframe = window.self !== window.top;


let codeReader = null;
let activeControls = null;
let isProcessing = false;
let cameraDevices = [];
let currentScanMode = "camera";
let usbKeyboardBuffer = "";
let usbAutoSubmitTimer = null;
let lastUsbSubmitted = "";
const SCAN_MODE_KEY = "preferredScanMode";
const ZXING_HINTS = new Map();
ZXING_HINTS.set(ZXing.DecodeHintType.TRY_HARDER, true);
ZXING_HINTS.set(ZXing.DecodeHintType.POSSIBLE_FORMATS, [ZXing.BarcodeFormat.QR_CODE]);

function extractTrackingNumber(decodedText) {
    const raw = normalizeScanText(decodedText);
    if (!raw) return "";

    // Direct JRS URL format, e.g. https://jrs-express.com/track?or=6049323292229202
    try {
        const url = new URL(raw);
        const orParam = (url.searchParams.get("or") || "").trim();
        if (orParam) return orParam;
    } catch (e) {
        // Not a full URL; continue with fallbacks.
    }

    // Fallback for scanner strings that still contain "or=" but may not be a valid URL.
    const byParam = raw.match(/[?&]or=([A-Za-z0-9\-]+)/i);
    if (byParam && byParam[1]) return byParam[1];

    // Final fallback: accept plain tracking text.
    return raw;
}

function normalizeScanText(value) {
    return ((value || "") + "").trim();
}

function clearUsbCaptureState() {
    usbKeyboardBuffer = "";
    if (usbAutoSubmitTimer) {
        clearTimeout(usbAutoSubmitTimer);
        usbAutoSubmitTimer = null;
    }
}

function focusUsbCaptureSurface() {
    try { window.focus(); } catch (e) {}
    try {
        if (document.body && !document.body.hasAttribute('tabindex')) {
            document.body.setAttribute('tabindex', '-1');
        }
        if (document.body) {
            document.body.focus({ preventScroll: true });
        }
    } catch (e) {}
}

function maybeAutoSubmitUsb(forceSubmit) {
    if (isProcessing) return;
    const raw = normalizeScanText(usbKeyboardBuffer);
    if (!raw) return;

    const tracking = extractTrackingNumber(raw);
    if (!tracking) return;

    const looksLikeJrsUrl = /^https?:\/\//i.test(raw) || /(?:\?|&)or=/i.test(raw);
    const looksLikePlainTracking = /^[A-Za-z0-9\-]{8,40}$/.test(tracking);
    if (!forceSubmit && !looksLikeJrsUrl && !looksLikePlainTracking) {
        return;
    }

    if (raw === lastUsbSubmitted) return;
    lastUsbSubmitted = raw;
    clearUsbCaptureState();
    onScanSuccess(raw);
}

function handleUsbScannerKeydown(event) {
    if (currentScanMode !== "usb") return;
    if (isProcessing) return;
    if (event.ctrlKey || event.altKey || event.metaKey) return;

    const key = event.key || "";
    if (key === "Enter" || key === "Tab") {
        event.preventDefault();
        maybeAutoSubmitUsb(true);
        return;
    }

    if (key === "Escape") {
        clearUsbCaptureState();
        return;
    }

    if (key === "Backspace") {
        usbKeyboardBuffer = usbKeyboardBuffer.slice(0, -1);
        return;
    }

    if (key.length === 1) {
        event.preventDefault();
        usbKeyboardBuffer += key;
        if (usbAutoSubmitTimer) clearTimeout(usbAutoSubmitTimer);
        usbAutoSubmitTimer = setTimeout(function() {
            maybeAutoSubmitUsb(false);
        }, 140);
    }
}

function onScanSuccess(decodedText) {
    if (isProcessing) return;
    decodedText = normalizeScanText(decodedText);
    if (!decodedText) return;
    isProcessing = true;
    flashEffect();


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

        if ((isEmbedded || isInIframe) && window.parent && window.parent !== window) {
            await stopCurrentStream();
            window.parent.postMessage({
                type: "scanner-success",
                noticeCode: noticeCode,
                trackingNumber: trackingNumber
            }, window.location.origin);
            return;
        }

        // Only generate PDF for final statuses.
        const shouldDownloadPdf = await shouldGeneratePdfByStatus(noticeCode);
        if (shouldDownloadPdf) {
            await generateReceiptPDF(trackingNumber);
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

function flashEffect() {
    const frameIds = ["scanFrame", "usbScanFrame"];
    frameIds.forEach(function(id) {
        const frame = document.getElementById(id);
        if (!frame) return;
        frame.classList.remove("flash");
        void frame.offsetWidth;
        frame.classList.add("flash");
        setTimeout(function() {
            frame.classList.remove("flash");
        }, 1500);
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
            width: { ideal: 1280, max: 1920 },
            height: { ideal: 720, max: 1080 },
            frameRate: { ideal: 24, max: 30 }
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
}

function getPreferredDevice(devices) {
    const savedCamera = localStorage.getItem("preferredCameraId");
    const savedDevice = devices.find(d => d.deviceId === savedCamera) || null;
    const rearDevice = devices.find((d) => /rear|back|environment/i.test((d.label || '').toLowerCase())) || null;
    const savedLooksRear = !!(savedDevice && /rear|back|environment/i.test((savedDevice.label || '').toLowerCase()));
    return (savedLooksRear ? savedDevice : null) || rearDevice || savedDevice || devices[0];
}

function renderCameraOptions(devices) {
    const cameraSelect = document.getElementById("cameraSelect");
    cameraSelect.innerHTML = "";

    devices.forEach((device, index) => {
        const opt = document.createElement("option");
        opt.value = device.deviceId;
        opt.text = device.label || ("Camera " + (index + 1));
        cameraSelect.appendChild(opt);
    });
}

async function refreshAvailableDevices(keepSelection = true, silentNoCamera = false) {
    const cameraSelect = document.getElementById("cameraSelect");
    const currentSelected = keepSelection ? cameraSelect.value : "";
    const devices = await codeReader.listVideoInputDevices();

    if (!devices || devices.length === 0) {
        cameraDevices = [];
        cameraSelect.innerHTML = "";
        if (!silentNoCamera && currentScanMode === "camera") {
            document.getElementById("result").innerHTML = "No camera device found. Switch Scanner Mode to USB Barcode Scanner.";
        }
        return null;
    }

    cameraDevices = devices;
    renderCameraOptions(devices);

    const preserved = devices.find(d => d.deviceId === currentSelected);
    const preferred = preserved || getPreferredDevice(devices);
    cameraSelect.value = preferred.deviceId;
    return preferred.deviceId;
}

function setModeSections(mode) {
    const cameraSection = document.getElementById("cameraSection");
    const usbSection = document.getElementById("usbSection");
    if (!cameraSection || !usbSection) return;

    if (mode === "usb") {
        cameraSection.classList.add("hidden-mode");
        usbSection.classList.remove("hidden-mode");
    } else {
        cameraSection.classList.remove("hidden-mode");
        usbSection.classList.add("hidden-mode");
    }
}

async function applyScanMode(mode, updateResult = true) {
    const normalizedMode = (mode === "usb") ? "usb" : "camera";
    currentScanMode = normalizedMode;
    localStorage.setItem(SCAN_MODE_KEY, normalizedMode);
    setModeSections(normalizedMode);
    clearUsbCaptureState();

    if (normalizedMode === "usb") {
        await stopCurrentStream();
        isProcessing = false;
        if (updateResult) {
            document.getElementById("result").innerHTML = "Waiting for USB scan...";
        }
        focusUsbCaptureSurface();
        return true;
    }

    const defaultDeviceId = await refreshAvailableDevices(false, false);
    if (!defaultDeviceId) {
        return false;
    }

    await startScanner(defaultDeviceId);
    if (updateResult) {
        document.getElementById("result").innerHTML = "Waiting for scan...";
    }
    return true;
}

async function initScanner() {
    const resultEl = document.getElementById("result");
    const scanModeSelect = document.getElementById("scanModeSelect");
    const cameraSelect = document.getElementById("cameraSelect");
    const refreshCamerasBtn = document.getElementById("refreshCamerasBtn");

    if (!window.ZXing || !ZXing.BrowserMultiFormatReader) {
        document.getElementById("result").innerHTML = "ZXing library failed to load.";
        return;
    }

    codeReader = new ZXing.BrowserMultiFormatReader(ZXING_HINTS, 120);

    cameraSelect.addEventListener("change", async function() {
        await startScanner(cameraSelect.value);
    });

    document.addEventListener("keydown", handleUsbScannerKeydown, true);
    document.addEventListener("pointerdown", function() {
        if (currentScanMode === "usb") {
            focusUsbCaptureSurface();
        }
    }, true);
    window.addEventListener("focus", function() {
        if (currentScanMode === "usb") {
            focusUsbCaptureSurface();
        }
    });

    refreshCamerasBtn.addEventListener("click", async function() {
        try {
            const nextDeviceId = await refreshAvailableDevices(true);
            if (nextDeviceId) {
                await startScanner(nextDeviceId);
                resultEl.innerHTML = "Waiting for scan...";
            }
        } catch (e) {
            console.error(e);
            resultEl.innerHTML = "Unable to refresh camera devices.";
        }
    });

    scanModeSelect.addEventListener("change", async function() {
        const ok = await applyScanMode(scanModeSelect.value, true);
        if (!ok && scanModeSelect.value === "camera") {
            scanModeSelect.value = "usb";
            await applyScanMode("usb", true);
        }
    });

    const savedMode = localStorage.getItem(SCAN_MODE_KEY);
    const initialMode = (savedMode === "usb" || savedMode === "camera") ? savedMode : "camera";
    scanModeSelect.value = initialMode;

    const modeOk = await applyScanMode(initialMode, false);
    if (!modeOk && initialMode === "camera") {
        scanModeSelect.value = "usb";
        await applyScanMode("usb", true);
    }
    if (currentScanMode === "usb") {
        focusUsbCaptureSurface();
    }
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

