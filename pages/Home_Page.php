 
<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth.php';

// Require login to access this page
requireLogin();

// Handle status update when submitted per-row
$message = '';
$updatedNotice = '';
$updatedStatus = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['notice_code']) && isset($_POST['status'])) {
    $notice = trim($_POST['notice_code']);
    $status = trim($_POST['status']);
    $isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest';
    if ($notice === '') {
        $message = 'Missing Notice/Order Code.';
    } elseif ($status === '') {
        // placeholder or empty selection — don't save
        $message = 'No status selected.';
    } else {
        try {
            $sql = 'UPDATE mailtracking SET `Status` = :status WHERE `Notice/Order Code` = :notice';
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':status' => $status, ':notice' => $notice]);
            // track which row was updated so we can show a per-row message in the UI
            $updatedNotice = $notice;
            $updatedStatus = $status;
            $message = '';
        } catch (PDOException $e) {
            $message = 'Update failed: ' . $e->getMessage();
        }
    }

    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => ($message === ''),
            'message' => $message,
            'notice' => $updatedNotice,
            'status' => $updatedStatus,
        ]);
        exit;
    }
}


// Fetch all rows to display
try {
    $rows = $pdo->query('SELECT * FROM mailtracking')->fetchAll();
} catch (Exception $e) {
    $rows = [];
    $message = 'Failed to load records: ' . $e->getMessage();
}

function extractBatchIdFromSenderDetails($senderDetails) {
    $text = trim((string)$senderDetails);
    if ($text === '') return '';
    if (preg_match('/Batch ID:\s*([A-Za-z0-9\-]+)/i', $text, $m)) {
        return trim($m[1]);
    }
    return '';
}

// Column order to render (matches table header in UI)
$columns = [
    'Notice/Order Code',
    'Date released to AFD',
    'Parcel No.',
    'Recipient Details',
    'Parcel Details',
    'Sender Details',
    'File Name (PDF)',
    'Tracking No.',
    'Status',
    'Transmittal Remarks/Received By',
    'Date',
    'Evaluator',
];

// Status options
$statusOptions = ['DELIVERED','RETURNED TO SENDER','ONGOING DELIVERY', 'PERSONALLY RECEIVED',];

// Compute counts per status
$statusCounts = array_fill_keys($statusOptions, 0);
$statusCounts['Unassigned'] = 0;
$statusCounts['Other'] = 0;
foreach ($rows as $r) {
    $s = trim($r['Status'] ?? '');
    if ($s === '') {
        $statusCounts['Unassigned']++;
    } elseif (in_array($s, $statusOptions, true)) {
        $statusCounts[$s]++;
    } else {
        $statusCounts['Other']++;
    }
}

$del = (int)($statusCounts['DELIVERED'] ?? 0);
$rts = (int)$statusCounts['RETURNED TO SENDER'] ?? 0;
$ogd = (int)$statusCounts['ONGOING DELIVERY'] ?? 0;

// Totals and non-delivery rate
$totalCount = count($rows);
$ndrPercent = ($totalCount > 0) ? round((($rts + $ogd )/ $totalCount) * 100, 1) : 0;

// Build counts per batch ID so only true batch groups are highlighted.
$batchIdCounts = [];
foreach ($rows as $r) {
    $bid = extractBatchIdFromSenderDetails($r['Sender Details'] ?? '');
    if ($bid !== '') {
        if (!isset($batchIdCounts[$bid])) $batchIdCounts[$bid] = 0;
        $batchIdCounts[$bid]++;
    }
}

function formatDateCell($value) {
    if ($value === null) return '';
    $value = trim((string)$value);
    if ($value === '' || $value === '0000-00-00' || $value === '0000-00-00 00:00:00') return '';
    $ts = strtotime($value);
    if ($ts === false) return '';
    return date('F-d-Y', $ts);
}

function normalizedCellValueForMerge($row, $colName) {
    if ($colName === '__ACTION__') {
        $trackingValue = trim((string)($row['Tracking No.'] ?? $row['Tracking No'] ?? $row['tracking_no'] ?? $row['TrackingNo'] ?? ''));
        return (!empty($trackingValue) && $trackingValue !== '0') ? 'AUTO_TRACKING' : 'SCAN';
    }

    $cellValue = $row[$colName] ?? '';
    if ($colName === 'Date released to AFD' || $colName === 'Date') {
        return formatDateCell($cellValue);
    }
    if ($colName === 'Sender Details') {
        $cellValue = preg_replace('/\R?Batch ID:\s*[A-Za-z0-9\-]+\s*/i', '', (string)$cellValue);
        return trim((string)$cellValue);
    }
    return trim((string)$cellValue);
}

// Build rowspan metadata for consecutive batch rows.
// Cells are merged only when same batch ID and same displayed value.
$mergeSkip = [];
$mergeRowspan = [];
$mergeColumns = array_values(array_filter($columns, function($c) {
    return $c !== 'Notice/Order Code';
}));
$mergeColumns[] = '__ACTION__';

$rowCount = count($rows);
for ($ri = 0; $ri < $rowCount; $ri++) {
    $batchId = extractBatchIdFromSenderDetails($rows[$ri]['Sender Details'] ?? '');
    if ($batchId === '' || (($batchIdCounts[$batchId] ?? 0) <= 1)) {
        continue;
    }

    foreach ($mergeColumns as $colName) {
        if (!empty($mergeSkip[$ri][$colName])) {
            continue;
        }

        $baseValue = normalizedCellValueForMerge($rows[$ri], $colName);
        $span = 1;

        for ($rj = $ri + 1; $rj < $rowCount; $rj++) {
            $nextBatchId = extractBatchIdFromSenderDetails($rows[$rj]['Sender Details'] ?? '');
            if ($nextBatchId !== $batchId) {
                break;
            }

            $nextValue = normalizedCellValueForMerge($rows[$rj], $colName);
            if ($nextValue !== $baseValue) {
                break;
            }

            $span++;
            $mergeSkip[$rj][$colName] = true;
        }

        if ($span > 1) {
            $mergeRowspan[$ri][$colName] = $span;
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Home Page</title>
    <link rel="stylesheet" href="../main.css">
    <style>
        /* Table uses .listview-table .tracking-table from main.css (same as Tracking_Page) */
        .admin-table-container .table-scroll-area thead th {
            position: sticky;
            top: 0;
            z-index: 3;
            background: #22336A !important;
            padding: 0px;
        }
        form.inline { margin:0; }
        select { padding:4px; }
        button.save { padding:4px 8px; }
        /* Ensure status column does not overflow */
        td.status-cell {
            position: relative;
            overflow: hidden;
            padding: 8px;
            min-width: 0;
            max-width: 100%;
        }
        .status-text { font-weight: 700; }
        .status-delivered { color: #2e7d32; }
        .status-returned { color: #c62828; }
        .status-ongoing { color: #b39b00; }
        .status-personal { color: #22336A; }
        tr.batch-row td { background: #ececec !important; }
        tr.scanned-row-focus td {
            background: #fff7c2 !important;
            transition: background 0.8s ease;
        }
        .notice-code-cell {
            position: relative;
        }
        .batch-icon {
            position: absolute;
            top: 4px;
            right: 6px;
            width: 18px;
            height: 18px;
            flex-shrink: 0;
        }
        .message { padding:8px; margin:10px 0; }
        .row-message { font-size:0.9em; color: green; margin-top:6px; opacity:1; transition: opacity 0.5s ease; }
        .stats { margin-bottom:10px; }
        .stat-item { display:inline-block; margin-right:12px; padding:4px 6px; background:#f1f1f1; border-radius:4px; font-weight:600; }
        .btn-track, .btn-scan { padding:6px 12px; font-weight: 600; color:white; border:none; border-radius:4px; cursor:pointer; font-size:0.7rem; }
        .btn-track { background-color:#22336A; }
        .btn-track:hover { background-color:black; }
        .btn-scan { background-color:#2e7d32; }
        .btn-scan:hover { background-color:#1b5e20; }
        .status-delivered { color:#1b7f3b; font-weight:700; }

        /* Modal Form UI - Two Column Grid */
        .edit-modal {
            background: #fff;
            border-radius: 4px;
            border: solid 15px #22336A ;
            box-shadow: 0 2px 16px rgba(0,0,0,0.18);
            padding: 32px 32px 24px 32px;
            max-width: 780px;
            width: min(780px, calc(100vw - 24px));
            margin: 0 auto;
            position: relative;
            box-sizing: border-box;
            max-height: calc(100vh - 24px);
            overflow: auto;
        }
        @media (max-width: 768px) {
            .edit-modal {
                padding: 20px 20px 16px 20px;
                width: calc(100vw - 20px);
                border-width: 8px;
            }
        }
        @media (max-width: 480px) {
            .edit-modal {
                padding: 16px 16px 12px 16px;
                width: calc(100vw - 12px);
                border-width: 6px;
            }
        }
        .edit-modal h2 {
            text-align: center;
            color: #22336A;
            font-size: 1.15em;
            font-weight: bold;
            margin-bottom: 30px;
            letter-spacing: 1px;
        }
        .edit-modal form {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0 24px;
        }
        @media (max-width: 768px) {
            .edit-modal form {
                grid-template-columns: 1fr;
                gap: 0;
            }
        }
        .edit-modal label {
            font-size: 0.98em;
            color: #22336A;
            margin-bottom: 4px;
            font-weight: 600;
            display: block;
        }
        .edit-modal input,
        .edit-modal select,
        .edit-modal textarea {
            width: 100%;
            padding: 8px 10px;
            border: 1px solid #bdbdbd;
            border-radius: 4px;
            font-size: 1em;
            background: #f7f8fa;
            margin-bottom: 10px;
            display: block;
        }
        .edit-modal textarea {
            resize: vertical;
            min-height: 42px;
        }
        .edit-modal select {
            background: #b6bed3;
        }
        .edit-modal input[type="date"] {
            padding-right: 30px;
        }
        .edit-modal .modal-actions {
            grid-column: 1 / span 2;
            display: flex;
            justify-content: center;
            flex-wrap: wrap;
            gap: 1em;
            margin-top: 10px;
            margin-bottom: 0;
        }
        .edit-modal .modal-btn {
            padding: 8px 22px;
            border-radius: 4px;
            font-size: 1em;
            font-weight: 600;
            border: none;
            cursor: pointer;
            transition: background 0.2s;
        }
        .edit-modal .modal-btn.save {
            background: #1a237e;
            color: #fff;
        }
        .edit-modal .modal-btn.save:hover {
            background: #3949ab;
        }
        .edit-modal .modal-btn.cancel {
            background: #AA4444;
            color: #ffffffff;
        }
        .edit-modal .modal-btn.cancel:hover {
            background: #bdbdbd;
        }
        .edit-modal .modal-close {
            position: absolute;
            top: 18px;
            right: 18px;
            background: none;
            border: none;
            font-size: 2em;
            color: #1a237e;
            cursor: pointer;
            z-index: 2;
        }
        .edit-modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.8);
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 12px;
            box-sizing: border-box;
            overflow: auto;
            z-index: 1000;
            backdrop-filter: blur(4px);
        }
        .add-pairs-grid {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .add-pair-row {
            display: grid;
            grid-template-columns: 1fr 34px 1fr;
            gap: 10px;
            align-items: start;
        }
        .pair-row-btn {
            width: 30px;
            height: 30px;
            align-self: center;
            border: 2px solid #22336A;
            border-radius: 4px;
            background: #fff;
            color: #22336A;
            font-size: 1.1rem;
            font-weight: 700;
            cursor: pointer;
            line-height: 1;
            padding: 0;
        }
        .pair-row-btn:hover {
            background: #22336A;
            color: #fff;
        }
        #scannerModal {
            padding: 12px;
            box-sizing: border-box;
        }
        #scannerModal .modal-panel {
            width: min(980px, calc(100vw - 24px));
            max-height: calc(100vh - 24px);
            overflow: auto;
            border-radius: 10px;
        }
        #scannerModal .modal-panel {
            overflow: hidden;
        }
        #scannerModal #scannerFrame {
            height: min(75vh, 760px);
        }
        @media (max-width: 768px) {
            .add-pair-row {
                grid-template-columns: 1fr;
            }
            .pair-row-btn {
                width: 36px;
                height: 36px;
                justify-self: start;
            }
            .edit-modal .modal-actions {
                grid-column: 1 / span 1;
            }
            .edit-modal .modal-btn {
                width: 100%;
            }
            #scannerModal {
                padding: 8px;
            }
            #scannerModal .modal-panel {
                width: calc(100vw - 16px);
                max-height: calc(100vh - 16px);
            }
            #scannerModal #scannerFrame {
                height: 68vh;
            }
        }
        .top-bar {
            display: grid;
            grid-template-columns: 1fr auto 1fr;
            align-items: center;
            width: 100%;
            box-sizing: border-box;
            gap: 12px;
            overflow-x: hidden;
        }
        .top-bar-left {
            display: flex;
            align-items: center;
            justify-content: flex-start;
        }
        .top-bar-title {
            text-align: center;
            font-size: 1.3em;
            font-weight: 700;
            color: #22336A;
            white-space: nowrap;
        }
        .table-sort-bar {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 8px;
            max-width: 100%;
            flex-wrap: wrap;
            box-sizing: border-box;
            min-width: 0;
        }

        .table-search-bar {
            display: flex;
            align-items: center;
            gap: 0;
            margin-left: auto;
            flex-shrink: 0; /* prevent breaking layout */
        }

        .table-search-input {
            max-width: 200px; /* limit input width */
            width: 150px;
            min-width: 0;
            flex: 1;
            padding: 0.4rem;
            border: 1.5px solid #22336a59;
            border-radius: 5px;
            font-size: 0.8rem;
            font-weight: 500;
            outline: none;
            transition: border 0.2s;
        }
        @media (max-width: 1024px) {
            .table-search-input {
                width: 140px;
            }
        }

        .table-search-btn img {
            width: 16px;
            height: 16px;
            margin-right: 0;
        }
        @media (max-width: 768px) {
            .top-bar {
                grid-template-columns: 1fr;
                align-items: stretch;
                gap: 8px;
            }
            .top-bar-left {
                justify-content: flex-start;
            }
            .top-bar-title {
                order: -1;
                text-align: center;
            }
            .table-sort-bar {
                width: 100%;
                justify-content: flex-end;
                gap: 8px;
            }
            .table-search-input {
                width: 100%;
                max-width: 100%;
            }
        }


    </style>
</head>

<body class="admin-home-bg">
    <div class="admin-home-header">
    <div class="welcome-block">
        <div style="font-size:1.2em;font-weight:600;color:#22336A;margin-top:15px;margin-bottom:2px;">Welcome, Admin!</div>
        <a href="logout.php" class="logout-btn">Logout</a>
    </div>
        <img src="../assets/Admin_HomePage_New.svg" alt="Admin Home Header" class="admin-home-header-img">
        <div class="admin-home-header-border"></div>
    </div>
        <!-- Edit Modal (hidden by default) -->
        <div id="editModalOverlay" class="edit-modal-overlay" style="display:none;">
            <div class="edit-modal edit-modal-scrollable" id="editModal">
                    <style>
                        /* Edit Modal scrollable and responsive */
                        .edit-modal-scrollable {
                            max-height: 90vh;
                            overflow-y: auto;
                            min-width: 320px;
                            width: 100%;
                            box-sizing: border-box;
                        }
                        @media (max-width: 768px) {
                            .edit-modal-scrollable {
                                max-width: 98vw;
                                min-width: 0;
                                padding: 12px 6px 8px 6px;
                            }
                        }
                        @media (max-width: 480px) {
                            .edit-modal-scrollable {
                                max-width: 100vw;
                                min-width: 0;
                                padding: 8px 2px 6px 2px;
                            }
                        }
                    </style>
                <button class="modal-close" onclick="closeEditModal()" title="Close">&times;</button>
                <h2>EDIT MAIL RECORD</h2>
                <form id="editForm" autocomplete="off">
                    <input type="hidden" name="original_notice_code" id="editNoticeCode">
                    <div style="display:contents">
                        <div>
                            <label for="editNoticeCodeDisplay">Notice/Order Code*</label>
                            <input type="text" name="Notice/Order Code" id="editNoticeCodeDisplay" style="background:#f7f8fa;" required />
                        </div>
                        <div>
                            <label for="editDateAfd">Date Released to AFD*</label>
                            <input type="date" name="Date released to AFD" id="editDateAfd" required>
                        </div>
                        <div>
                            <label for="editParcelNo">Parcel No.</label>
                            <input type="number" name="Parcel No." id="editParcelNo">
                        </div>
                        <div>
                            <label for="editTrackingNo">Tracking No.</label>
                            <input type="text" name="Tracking No." id="editTrackingNo">
                        </div>
                        <div style="grid-column:1/span 2;">
                            <label for="editRecipient">Recipient Details</label>
                            <textarea name="Recipient Details" row="5" id="editRecipient"></textarea>
                        </div>
                        <div style="grid-column:1/span 2;">
                            <labelwhite for="editParcelDetails">Parcel Details</label>
                            <textarea name="Parcel Details" row="5" id="editParcelDetails"></textarea>
                        </div>
                        <div style="grid-column:1/span 2;">
                            <label for="editSender">Sender Details</label>
                            <textarea name="Sender Details" row="5" id="editSender"></textarea>
                        </div>
                        <div style="grid-column:1/span 2;">
                            <label for="editFileName">File Name (PDF)</label>
                            <input type="text" name="File Name (PDF)" id="editFileName">
                        </div>
                        
                    </div>
                    <div class="modal-actions">
                        <button type="submit" class="modal-btn save">Save</button>
                        <button type="button" class="modal-btn cancel" onclick="clearEditForm()">Clear Form</button>
                    </div>
                </form>
            </div>
        </div>
        <div id="addModalOverlay" class="edit-modal-overlay" style="display:none;">
            <div class="edit-modal add-modal-scrollable" id="addModal">
                    <style>
                        /* Add Modal scrollable and responsive */
                        .add-modal-scrollable {
                            max-height: 90vh;
                            overflow-y: auto;
                            min-width: 320px;
                            width: 100%;
                            box-sizing: border-box;
                        }
                        @media (max-width: 768px) {
                            .add-modal-scrollable {
                                max-width: 98vw;
                                min-width: 0;
                                padding: 12px 6px 8px 6px;
                            }
                        }
                        @media (max-width: 480px) {
                            .add-modal-scrollable {
                                max-width: 100vw;
                                min-width: 0;
                                padding: 8px 2px 6px 2px;
                            }
                        }
                    </style>
                <button class="modal-close" onclick="closeAddModal()" title="Close">&times;</button>
                <h2>ADD RECORD</h2>
                <form id="addForm" action="../api/Add.php" method="post" autocomplete="off">

                    <div style="display:contents">
                        <div style="grid-column:1/span 2;">
                            <div id="addPairRows" class="add-pairs-grid">
                                <div class="add-pair-row">
                                    <div>
                                        <label>Code*</label>
                                        <input type="text" name="noticeCodes[]" placeholder="Notice/Order Code" required>
                                    </div>
                                    <button type="button" class="pair-row-btn" title="Add row">+</button>
                                    <div>
                                        <label>Parcel Details</label>
                                        <textarea name="parcelDetailsList[]" rows="1" placeholder="Parcel Details" required></textarea>
                                    </div>
                                </div>
                            </div>
                        </div>
                            <style>
                                .notice-code-row {
                                    display: flex;
                                    align-items: center;
                                    gap: 6px;
                                    margin-bottom: 4px;
                                }
                                .add-notice-btn, .remove-notice-btn {
                                    background: none;
                                    border: none;
                                    padding: 0.3em 0.7em;
                                    display: flex;
                                    align-items: center;
                                    justify-content: center;
                                    cursor: pointer;
                                    margin-left: 4px;
                                    transition: background 0.2s;
                                }
                                .add-notice-btn img,  {
                                    width: 22px;
                                    height: 22px;
                                }
                                .remove-notice-btn img {
                                    width: 20px;
                                    height: 20px;
                                }
                            </style>
                            <script>
                            // Dynamic Notice/Order Code fields in Add Modal
                            document.addEventListener('DOMContentLoaded', function() {
                                const fieldsContainer = document.getElementById('noticeCodeFields');
                                let noticeCodeCount = 1;
                                function addNoticeCodeField() {
                                    const idx = noticeCodeCount;
                                    const row = document.createElement('div');
                                    row.className = 'notice-code-row';
                                    row.style.marginBottom = '4px';
                                    row.innerHTML = `
                                        <label for="addNoticeCode_${idx}" style="flex:0 0 90px;"></label>
                                        <input type="text" name="notice_Code[]" id="addNoticeCode_${idx}" required style="flex:1;" />
                                        <button type="button" class="remove-notice-btn">
                                            <img src="../assets/Minus_Icon.svg" alt="Remove" >
                                        </button>
                                    `;
                                    // Remove label for subsequent fields
                                    row.querySelector('label').textContent = '';
                                    // Attach remove handler
                                    row.querySelector('.remove-notice-btn').addEventListener('click', function() {
                                        fieldsContainer.removeChild(row);
                                    });
                                    fieldsContainer.appendChild(row);
                                    noticeCodeCount++;
                                }
                                // Attach add handler to first Add button
                                fieldsContainer.querySelector('.add-notice-btn').addEventListener('click', function() {
                                    addNoticeCodeField();
                                });
                            });
                            </script>
                        <div>
                            <label for="addDateAfd">Date Released to AFD*</label>
                            <input type="date" name="dateReleased" id="addDateAfd" required>
                        </div>
                        <div>
                            <label for="addParcelNo">Parcel No.</label>
                            <input type="number" name="parcelNo" id="addParcelNo">
                        </div>
                        <div style="grid-column:1/span 2;">
                            <label for="addTrackingNo">Tracking No.</label>
                            <input type="text" name="trackingNo" id="addTrackingNo">
                        </div>
                        <div style="grid-column:1/span 2;">
                            <label for="addRecipient">Recipient Details</label>
                            <textarea name="recipientDetails" rows="2" id="addRecipient"></textarea>
                        </div>
                        <div style="grid-column:1/span 2;">
                            <label for="addSender">Sender Details</label>
                            <textarea name="senderDetails" rows="3" id="addSender" readonly>Department of Human Settlements and Urban Development Region 4A
HREDRD-EMES
0935 542 1538</textarea>
                        </div>
                        <div style="grid-column:1/span 2;">
                            <label for="addFileName">File Name (PDF)</label>
                            <input type="text" name="fileName" id="addFileName">
                        </div>
                    </div>
                    <div class="modal-actions">
                        <button type="submit" class="modal-btn save">Add Record</button>
                        <button type="button" class="modal-btn cancel" onclick="clearAddForm()">Clear Form</button>
                    </div>
                </form>
            </div>
        </div>
    <div class="admin-table-container">
    <div class="top-bar">
        <div class="top-bar-left">
            <button class="export-btn" onclick="exportSelectedToPDF()">Export Selected to PDF</button>
        </div>
        <div class="top-bar-title">MAIL TRACKING RECORDS</div>
            <script>
            let scannerSelectedNoticeCode = '';

            function openScannerModal(noticeCode) {
                const modal = document.getElementById('scannerModal');
                const frame = document.getElementById('scannerFrame');
                if (!modal || !frame) return;
                scannerSelectedNoticeCode = (noticeCode || '').trim();
                if (!scannerSelectedNoticeCode) return;
                frame.src = '../test.php?code=' + encodeURIComponent(scannerSelectedNoticeCode) + '&embedded=1';
                modal.style.display = 'flex';
            }

            function closeScannerModal() {
                const modal = document.getElementById('scannerModal');
                const frame = document.getElementById('scannerFrame');
                if (!modal || !frame) return;
                modal.style.display = 'none';
                frame.src = 'about:blank';
                scannerSelectedNoticeCode = '';
            }

            </script>
        <div class="table-sort-bar">
            <select id="tableSortYear" class="table-sort-select" required style="min-width:70px;">
                <option value="" disabled selected hidden>Year</option>
                <option value="all">All</option>
                <?php
                $years = [];
                foreach ($rows as $row) {
                    $dateAfd = $row['Date released to AFD'] ?? '';
                    if ($dateAfd && preg_match('/(\d{4})/', $dateAfd, $m)) {
                        $years[] = $m[1];
                    }
                }
                $years = array_unique($years);
                rsort($years);
                foreach ($years as $year) {
                    echo '<option value="' . htmlspecialchars($year) . '">' . htmlspecialchars($year) . '</option>';
                }
                ?>
            </select>
                <input type="text" id="tableSearchInput" class="table-search-input" placeholder="Search">
                <button class="table-search-btn" id="tableSearchBtn">
                    <img src="../assets/Search Icon.svg" alt="Search">
                </button>
        </div>
</div>

        <div class="table-scroll-area">
            <div class="tracking-table-container">
                <div class="tracking-table-scroll">
                    <table class="listview-table tracking-table">
                <thead>
                        <tr>
                            <th style="width:32px;">
                                <input type="checkbox" id="selectAllCheckbox" onclick="toggleAllCheckboxes(this)">
                            </th>
                            <?php foreach ($columns as $h): ?>
                                <th><?= htmlspecialchars($h) ?></th>
                            <?php endforeach; ?>
                            <th>Action</th>
                        </tr>
                </thead>
                <tbody>
                        <?php if (empty($rows)): ?>
                            <tr><td colspan="<?= count($columns) + 2 ?>">No records found.</td></tr>
                        <?php else: ?>
                            <?php for ($ri = 0; $ri < count($rows); $ri++): ?>
                                <?php
                                    $row = $rows[$ri];
                                    $rowBatchId = extractBatchIdFromSenderDetails($row['Sender Details'] ?? '');
                                    $isBatchRow = ($rowBatchId !== '' && (($batchIdCounts[$rowBatchId] ?? 0) > 1));

                                    $prevBatchId = '';
                                    if ($ri > 0) {
                                        $prevBatchId = extractBatchIdFromSenderDetails($rows[$ri - 1]['Sender Details'] ?? '');
                                    }
                                    $nextBatchId = '';
                                    if ($ri + 1 < count($rows)) {
                                        $nextBatchId = extractBatchIdFromSenderDetails($rows[$ri + 1]['Sender Details'] ?? '');
                                    }
                                    $showBatchBadge = $isBatchRow && ($rowBatchId !== '' && $rowBatchId !== $prevBatchId);
                                    $isBatchEnd = $isBatchRow && ($rowBatchId !== '' && $rowBatchId !== $nextBatchId);
                                    $rowClasses = [];
                                    if ($isBatchRow) $rowClasses[] = 'batch-row';
                                    if ($isBatchEnd) $rowClasses[] = 'batch-end-row';
                                ?>
                                <?php
                                    $rowTrackingNo = trim((string)($row['Tracking No.'] ?? $row['Tracking No'] ?? $row['tracking_no'] ?? $row['TrackingNo'] ?? ''));
                                ?>
                                <tr class="<?= htmlspecialchars(implode(' ', $rowClasses)) ?>" data-batch-id="<?= htmlspecialchars($rowBatchId) ?>" data-notice="<?= htmlspecialchars($row['Notice/Order Code'] ?? '') ?>" data-tracking-no="<?= htmlspecialchars($rowTrackingNo) ?>">
                                    <td style="width:32px;">
                                        <input type="checkbox" class="row-checkbox" value="<?= htmlspecialchars($row['Notice/Order Code'] ?? '') ?>">
                                    </td>
                                    <td class="notice-code-cell">
                                        <div style="display: flex; align-items: center; gap: 0.3em;">
                                            <div class="row-menu-container">
                                                <button class="row-menu-btn" type="button" tabindex="0" aria-label="Row menu" onclick="toggleRowMenu(event, '<?= htmlspecialchars($row['Notice/Order Code'] ?? '') ?>')">
                                                    <span style="font-size:1.5em;line-height:1;">&#8942;</span>
                                                </button>
                                            </div>
                                            <span>
                                                <?= htmlspecialchars($row['Notice/Order Code'] ?? '') ?>
                                            </span>
                                            <?php if ($showBatchBadge): ?>
                                                <img src="../assets/Batch_Icon.svg" alt="Batch" class="batch-icon" title="Consecutive batch row">
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                <?php foreach ($columns as $idx => $colName): ?>
                                    <?php if ($idx === 0) continue; // skip Notice/Order Code, already rendered ?>
                                    <?php if (!empty($mergeSkip[$ri][$colName])) continue; ?>
                                    <?php
                                        $rowspanVal = !empty($mergeRowspan[$ri][$colName]) ? (int)$mergeRowspan[$ri][$colName] : 1;
                                        $rowspanAttr = $rowspanVal > 1 ? (' rowspan="' . $rowspanVal . '"') : '';
                                        $spanToBatchEndClass = '';
                                        if ($rowspanVal > 1) {
                                            $endRi = $ri + $rowspanVal - 1;
                                            $endBatchId = extractBatchIdFromSenderDetails($rows[$endRi]['Sender Details'] ?? '');
                                            $nextAfterEndBatchId = '';
                                            if ($endRi + 1 < count($rows)) {
                                                $nextAfterEndBatchId = extractBatchIdFromSenderDetails($rows[$endRi + 1]['Sender Details'] ?? '');
                                            }
                                            if ($endBatchId !== '' && (($batchIdCounts[$endBatchId] ?? 0) > 1) && $endBatchId !== $nextAfterEndBatchId) {
                                                $spanToBatchEndClass = ' batch-span-end';
                                            }
                                        }
                                    ?>
                                    <?php if ($idx === 8): // STATUS column (9th)
                                    ?>
                                        <?php
                                            $current = trim($row['Status'] ?? '');
                                            $statusClass = '';
                                            switch ($current) {
                                                case 'DELIVERED':
                                                    $statusClass = 'status-text status-delivered';
                                                    break;
                                                case 'RETURNED TO SENDER':
                                                    $statusClass = 'status-text status-returned';
                                                    break;
                                                case 'ONGOING DELIVERY':
                                                    $statusClass = 'status-text status-ongoing';
                                                    break;
                                                case 'PERSONALLY RECEIVED':
                                                    $statusClass = 'status-text status-personal';
                                                    break;
                                            }
                                        ?>
                                        <td class="status-cell<?= $spanToBatchEndClass ?>" data-col="Status"<?= $rowspanAttr ?>>
                                            <span class="<?= $statusClass ?>"><?= htmlspecialchars($current) ?></span>
                                        </td>
                                    <?php else: ?>
                                        <?php
                                            $cellValue = $row[$colName] ?? '';
                                            if ($colName === 'Date released to AFD' || $colName === 'Date') {
                                                $cellValue = formatDateCell($cellValue);
                                            }
                                            if ($colName === 'Sender Details') {
                                                $cellValue = preg_replace('/\R?Batch ID:\s*[A-Za-z0-9\-]+\s*/i', '', (string)$cellValue);
                                                $cellValue = trim((string)$cellValue);
                                            }
                                        ?>
                                        <?php if ($colName === 'File Name (PDF)'): ?>
                                            <?php
                                                $fileName = trim((string)$cellValue);
                                                $trackingValue = trim((string)($row['Tracking No.'] ?? $row['Tracking No'] ?? $row['tracking_no'] ?? $row['TrackingNo'] ?? ''));
                                                $pdfName = $trackingValue !== '' ? ('proof_' . $trackingValue . '.pdf') : '';
                                                $fileHref = $pdfName !== '' ? '../JRS_PDFs/' . rawurlencode($pdfName) : '';
                                                $linkLabel = $fileName !== '' ? basename($fileName) : '';
                                            ?>
                                            <td data-col="<?= htmlspecialchars($colName) ?>" class="pdf-link-cell<?= $spanToBatchEndClass ?>"<?= $rowspanAttr ?>>
                                                <?php if ($fileHref && $linkLabel !== ''): ?>
                                                    <a href="<?= htmlspecialchars($fileHref) ?>" target="_blank" rel="noopener" class="pdf-link-in-cell"><?= htmlspecialchars($linkLabel) ?></a>
                                                <?php endif; ?>
                                            </td>
                                        <?php else: ?>
                                            <td data-col="<?= htmlspecialchars($colName) ?>" class="<?= trim($spanToBatchEndClass) ?>"<?= $rowspanAttr ?>><?= htmlspecialchars($cellValue) ?></td>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                                <?php if (empty($mergeSkip[$ri]['__ACTION__'])): ?>
                                    <?php
                                        $actionRowspanVal = !empty($mergeRowspan[$ri]['__ACTION__']) ? (int)$mergeRowspan[$ri]['__ACTION__'] : 1;
                                        $actionRowspanAttr = $actionRowspanVal > 1 ? (' rowspan="' . $actionRowspanVal . '"') : '';
                                        $actionSpanToBatchEndClass = '';
                                        if ($actionRowspanVal > 1) {
                                            $actionEndRi = $ri + $actionRowspanVal - 1;
                                            $actionEndBatchId = extractBatchIdFromSenderDetails($rows[$actionEndRi]['Sender Details'] ?? '');
                                            $nextAfterActionEndBatchId = '';
                                            if ($actionEndRi + 1 < count($rows)) {
                                                $nextAfterActionEndBatchId = extractBatchIdFromSenderDetails($rows[$actionEndRi + 1]['Sender Details'] ?? '');
                                            }
                                            if ($actionEndBatchId !== '' && (($batchIdCounts[$actionEndBatchId] ?? 0) > 1) && $actionEndBatchId !== $nextAfterActionEndBatchId) {
                                                $actionSpanToBatchEndClass = ' batch-span-end';
                                            }
                                        }
                                    ?>
                                    <td class="<?= trim($actionSpanToBatchEndClass) ?>"<?= $actionRowspanAttr ?>>
                                        <?php if (!empty($rowTrackingNo) && $rowTrackingNo !== '0'): ?>
                                            <span style="font-size:0.72rem;color:#22336A;font-weight:700;">Auto Tracking</span>
                                            <div class="track-result"></div>
                                        <?php else: ?>
                                            <button type="button" class="btn-scan" onclick="openScannerModal('<?= htmlspecialchars($row['Notice/Order Code'] ?? '', ENT_QUOTES) ?>')" style="display:inline-block;text-decoration:none;">Scan</button>
                                        <?php endif; ?>
                                    </td>
                                <?php endif; ?>
                                
                            </tr>
                        <?php endfor; ?>
                    <?php endif; ?>
                </tbody>
            </table>
                </div>
            </div>
        </div>
        <div id="rowMenuDropdown" class="row-menu-dropdown" style="display:none; position:fixed; left:0; top:0; min-width:120px; background:#fff; border:1px solid #d1d5db; box-shadow:0 2px 8px rgba(0,0,0,0.08); border-radius:6px; z-index:1000; padding:0.3em 0;">
            <button class="row-menu-item" onclick="editRowFromMenu()" style="display:flex;align-items:center;gap:0.5em;padding:8px 18px;width:100%;background:none;border:none;cursor:pointer;color:#22336a;font-size:1em;font-weight:600;text-align:left;">
                <img src="../assets/Edit_Icon.svg" alt="Edit" style="width:20px;height:20px;"> Edit
            </button>
            <button class="row-menu-item" onclick="deleteRecordFromMenu()" style="display:flex;align-items:center;gap:0.5em;padding:8px 18px;width:100%;background:none;border:none;cursor:pointer;color:#22336a;font-size:1em;font-weight:600;text-align:left;">
                <img src="../assets/Delete_Icon.svg" alt="Delete" style="width:20px;height:20px;"> Delete
            </button>
        </div>
        <div id="scannerModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.72);z-index:10000;justify-content:center;align-items:center;">
            <div class="modal-panel" style="background:#fff;padding:14px;box-shadow:0 10px 30px rgba(0,0,0,.28);">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
                    <h2 style="margin:0;color:#22336A;font-size:1.1rem;">Scan QR Code</h2>
                    <button type="button" onclick="closeScannerModal()" style="border:none;background:#f3f4f6;color:#22336A;font-weight:700;border-radius:6px;padding:6px 10px;cursor:pointer;">Close</button>
                </div>
                <iframe id="scannerFrame" src="about:blank" title="QR Scanner" style="width:100%;border:1px solid #d1d5db;border-radius:8px;"></iframe>
            </div>
        </div>

        <!-- Add New Record Modal (hidden by default) -->
        
        <div style="display: flex; gap: 10px; margin-top: 10px; align-items: center;">
            <button class="add-btn" onclick="openAddModal()">Add</button>
            <a href="Archive_Page.php" class="archive-btn">Archive</a>
            <div class="statistics-section">
                <div class="statistics-title">Statistics</div>
                <div class="statistics-bar">
                    <div class="stat-box stat-rtos">Returned to Sender
                        <div class="stat-count"><?= $rts ?></div>
                    </div>
                    <div class="stat-box stat-ongoing">Ongoing Delivery
                        <div class="stat-count"><?= $ogd?></div>
                    </div>
                    <div class="stat-box stat-delivered">Delivered
                        <div class="stat-count"><?= $del ?></div>
                    </div>
                    <div class="stat-box stat-total">Total
                        <div class="stat-count"><?= (int)$totalCount ?></div>
                    </div>
                    <div class="stat-box stat-ndr">Non-delivery Rate
                        <div class="stat-count"><?= htmlspecialchars($ndrPercent) ?>%</div>
                    </div>
            </div>
        </div>
        </div>
        
    <style>
        .logout-btn {
            display: block;
            text-decoration: none;
            font-weight: 600;
            color: #726868;
            font-size: 1em;
            margin-bottom: -160px;
            transition: color 0.2s, background 0.2s;
        }
        .logout-btn:hover {
            color: black;
        }
        .add-btn {
            background: #22336A;
            color: #fff;
            padding: 8px 15px;
            border: none;
            border-radius: 4px;
            font-weight: 700;
            font-size: 0.8rem;
            cursor: pointer;
            transition: background 0.2s, color 0.2s;
            height: 35px;
        }
        .add-btn:hover {
            background: black;
            color: #fff;
        }
        .export-btn {
            background: #22336A;
            color: #fff;
            padding: 8px 16px;
            border: none;
            border-radius: 6px;
            font-weight: bold;
            cursor: pointer;
            transition: background 0.2s, color 0.2s;
        }
        .export-btn:hover {
            background: black;
            color: #fff;
        }
        .archive-btn {
            background: #AA4444;
            color: #fff;
            padding: 8px 15px;
            border: none;
            border-radius: 4px;
            font-weight: 700;
            font-size: 0.8rem;
            cursor: pointer;
            display: inline-block;
            text-align: center;
            text-decoration: none;
            transition: background 0.2s, color 0.2s;
        }
        .archive-btn:hover {
            background: #d32f2f;
            color: #fff;
        }
    </style>
        
        <script>
            (function cleanDeleteQueryParam() {
                var url = new URL(window.location.href);
                if (url.searchParams.has('deleted')) {
                    url.searchParams.delete('deleted');
                    window.history.replaceState({}, '', url.pathname + (url.search ? url.search : '') + url.hash);
                }
            })();

            var currentRowMenuNoticeCode = '';
            function hideRowMenuDropdown() {
                var dropdown = document.getElementById('rowMenuDropdown');
                if (dropdown) dropdown.style.display = 'none';
                currentRowMenuNoticeCode = '';
            }
            function toggleRowMenu(event, noticeCode) {
                event.stopPropagation();
                var dropdown = document.getElementById('rowMenuDropdown');
                if (!dropdown) return;
                if (dropdown.style.display === 'block' && currentRowMenuNoticeCode === noticeCode) {
                    hideRowMenuDropdown();
                    return;
                }
                currentRowMenuNoticeCode = noticeCode || '';
                dropdown.style.display = 'block';
                var rect = event.currentTarget.getBoundingClientRect();
                var left = rect.left;
                var top = rect.bottom + 6;
                dropdown.style.left = left + 'px';
                dropdown.style.top = top + 'px';
                var ddRect = dropdown.getBoundingClientRect();
                if (ddRect.right > window.innerWidth - 8) {
                    dropdown.style.left = Math.max(8, window.innerWidth - ddRect.width - 8) + 'px';
                }
                if (ddRect.bottom > window.innerHeight - 8) {
                    dropdown.style.top = Math.max(8, rect.top - ddRect.height - 6) + 'px';
                }
            }
            function editRowFromMenu() {
                var noticeCode = currentRowMenuNoticeCode;
                hideRowMenuDropdown();
                if (noticeCode) {
                    editRow(noticeCode);
                }
            }
            function deleteRecordFromMenu() {
                var noticeCode = currentRowMenuNoticeCode;
                hideRowMenuDropdown();
                if (noticeCode) {
                    deleteRecord(noticeCode);
                }
            }
            function deleteRecord(noticeCode) {
                if (confirm('Are you sure you want to delete this record?')) {
                   if (!noticeCode) return;
                        fetch('../api/Delete.php', {
                            method: 'POST',
                            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                            body: 'noticeCode=' + encodeURIComponent(noticeCode)
                        })
                        .then(function () { return refreshHomeData(); })
                        .catch(function (err) {
                            console.error(err);
                            alert('Failed to delete record.');
                        });
                }
            }
            // Select all checkboxes logic
            function toggleAllCheckboxes(master) {
                var checkboxes = document.querySelectorAll('.row-checkbox');
                checkboxes.forEach(function(cb) {
                    cb.checked = master.checked;
                });
            }
        function bindRowCheckboxListeners() {
            document.querySelectorAll('.row-checkbox').forEach(function(cb) {
                cb.onchange = function() {
                    var allChecked = Array.from(document.querySelectorAll('.row-checkbox')).every(function(c) { return c.checked; });
                    var master = document.getElementById('selectAllCheckbox');
                    if (master) master.checked = allChecked;
                };
            });
        }
        // Add Modal logic
        function openAddModal() {
            document.getElementById('addModalOverlay').style.display = 'flex';
        }
        function closeAddModal() {
            document.getElementById('addModalOverlay').style.display = 'none';
        }
        // Close modal when clicking outside
        document.addEventListener('DOMContentLoaded', function() {
            const overlay = document.getElementById('addModalOverlay');
            if (overlay) {
                overlay.addEventListener('click', function(e) {
                    if (e.target === overlay) {
                        closeAddModal();
                    }
                });
            }
        });
        // Clear form fields
        function clearAddForm() {
            var form = document.getElementById('addForm');
            if (form) form.reset();
            var rowsWrap = document.getElementById('addPairRows');
            if (rowsWrap) {
                rowsWrap.innerHTML = '';
                addAddPairRow();
            }
            refreshAddPairButtons();
        }

        function addAddPairRow(noticeValue = '', parcelValue = '') {
            var rowsWrap = document.getElementById('addPairRows');
            if (!rowsWrap) return;
            var row = document.createElement('div');
            row.className = 'add-pair-row';
            row.innerHTML =
                '<div><label>Code*</label><input type="text" name="noticeCodes[]" placeholder="Notice/Order Code" required></div>' +
                '<button type="button" class="pair-row-btn" title="Add row">+</button>' +
                '<div><label>Parcel Details</label><textarea name="parcelDetailsList[]" rows="1" placeholder="Parcel Details" required></textarea></div>';
            rowsWrap.appendChild(row);
            var inputs = row.querySelectorAll('input, textarea');
            if (inputs[0]) inputs[0].value = noticeValue;
            if (inputs[1]) inputs[1].value = parcelValue;
            refreshAddPairButtons();
        }

        function removeAddPairRow(btn) {
            var rowsWrap = document.getElementById('addPairRows');
            if (!rowsWrap) return;
            var rows = rowsWrap.querySelectorAll('.add-pair-row');
            if (rows.length <= 1) {
                alert('At least one Notice/Order Code + Parcel Details pair is required.');
                return;
            }
            var row = btn.closest('.add-pair-row');
            if (row) row.remove();
            refreshAddPairButtons();
        }

        function refreshAddPairButtons() {
            var rowsWrap = document.getElementById('addPairRows');
            if (!rowsWrap) return;
            var rows = rowsWrap.querySelectorAll('.add-pair-row');
            rows.forEach(function(row, index) {
                var btn = row.querySelector('.pair-row-btn');
                if (!btn) return;
                if (index === 0) {
                    btn.textContent = '+';
                    btn.title = 'Add row';
                    btn.onclick = function() { addAddPairRow(); };
                } else {
                    btn.textContent = '-';
                    btn.title = 'Remove row';
                    btn.onclick = function() { removeAddPairRow(btn); };
                }
            });
        }
        // Submit handler (AJAX)
        document.addEventListener('DOMContentLoaded', function() {
            var addForm = document.getElementById('addForm');
            if (addForm) {
                var rowsWrap = document.getElementById('addPairRows');
                if (rowsWrap && rowsWrap.querySelectorAll('.add-pair-row').length === 0) {
                    addAddPairRow();
                }
                refreshAddPairButtons();
                addForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    var formData = new FormData(addForm);

                    var noticeCodes = formData.getAll('noticeCodes[]').map(function(v){ return (v || '').trim(); });
                    var parcelDetails = formData.getAll('parcelDetailsList[]').map(function(v){ return (v || '').trim(); });
                    if (!noticeCodes.length) {
                        alert('Please add at least one Notice/Order Code.');
                        return;
                    }
                    for (var i = 0; i < noticeCodes.length; i++) {
                        if (!noticeCodes[i]) {
                            alert('Notice/Order Code is required for pair #' + (i + 1) + '.');
                            return;
                        }
                        if (!parcelDetails[i]) {
                            alert('Parcel Details is required for pair #' + (i + 1) + '.');
                            return;
                        }
                    }
                    
                    // Debug: Log form data
                    console.log('Form Data being sent:');
                    for (let [key, value] of formData.entries()) {
                        console.log('  ' + key + ': "' + value + '"');
                    }
                    
                    fetch('../api/Add.php', {
                        method: 'POST',
                        body: formData,
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    })
                    .then(resp => resp.json())
                    .then(data => {
                        console.log('Response from Add.php:', data);
                        if (data.success) {
                            clearAddForm();
                            closeAddModal();
                            const firstAddedNotice = (data.firstNotice || '').trim();
                            const addedTrackingNo = ((formData.get('trackingNo') || formData.get('Tracking No.') || '') + '').trim();
                            const immediateTrackNotices = addedTrackingNo !== '' ? (data.insertedNotices || []) : [];
                            refreshHomeData({
                                focusNotice: firstAddedNotice,
                                immediateTrackNotices: immediateTrackNotices
                            });
                        } else {
                            alert(data.message || 'Failed to add record.');
                        }
                    })
                    .catch(err => {
                        console.error('Error:', err);
                        alert('Failed to add record.');
                    });
                });
            }
        });
         
                        // Modal logic
                        function normalizeDateForInput(value) {
                            var v = (value || '').trim();
                            if (!v) return '';
                            if (/^\d{4}-\d{2}-\d{2}$/.test(v)) return v;
                            var parsed = new Date(v);
                            if (!isNaN(parsed.getTime())) {
                                var y = parsed.getFullYear();
                                var m = String(parsed.getMonth() + 1).padStart(2, '0');
                                var d = String(parsed.getDate()).padStart(2, '0');
                                return y + '-' + m + '-' + d;
                            }
                            return '';
                        }

                        const tableDataColumns = <?php echo json_encode(array_values(array_filter($columns, function($c){ return $c !== 'Notice/Order Code'; })), JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT); ?>;

                        function getRowDataFromTable(noticeCode) {
                            var safeNotice = (noticeCode || '').trim();
                            if (!safeNotice) return null;

                            if (Array.isArray(window.mailRows)) {
                                var cached = window.mailRows.find(function(r) {
                                    return ((r['Notice/Order Code'] || '').trim() === safeNotice);
                                });
                                if (cached) return cached;
                            }

                            var tr = document.querySelector('tr[data-notice="' + CSS.escape(safeNotice) + '"]');
                            if (!tr) return null;
                            var row = {'Notice/Order Code': safeNotice};
                            tr.querySelectorAll('td[data-col]').forEach(function(td) {
                                var key = td.getAttribute('data-col');
                                row[key] = (td.textContent || '').trim();
                            });
                            return row;
                        }

                        function openEditModal(rowData) {
                            document.getElementById('editModalOverlay').style.display = 'flex';
                            // Fill form fields - CRITICAL: original_notice_code must be set for lookup
                            var noticeCode = (rowData['Notice/Order Code'] || '').trim();
                            document.getElementById('editNoticeCode').value = noticeCode;
                            document.getElementById('editNoticeCodeDisplay').value = noticeCode;
                            document.getElementById('editDateAfd').value = normalizeDateForInput(rowData['Date released to AFD'] || '');
                            document.getElementById('editParcelNo').value = rowData['Parcel No.'] || '';
                            document.getElementById('editRecipient').value = rowData['Recipient Details'] || '';
                            document.getElementById('editParcelDetails').value = rowData['Parcel Details'] || '';
                            document.getElementById('editSender').value = rowData['Sender Details'] || '';
                            var editFileName = document.getElementById('editFileName');
                            if (editFileName) editFileName.value = rowData['File Name (PDF)'] || '';
                            var editTrackingNo = document.getElementById('editTrackingNo');
                            if (editTrackingNo) editTrackingNo.value = rowData['Tracking No.'] || '';
                            var editStatus = document.getElementById('editStatus');
                            if (editStatus) editStatus.value = rowData['Status'] || '';
                            var editTransmittal = document.getElementById('editTransmittal');
                            if (editTransmittal) editTransmittal.value = rowData['Transmittal Remarks/Received By'] || '';
                            var editDate = document.getElementById('editDate');
                            if (editDate) editDate.value = rowData['Date'] || '';
                            var editEvaluator = document.getElementById('editEvaluator');
                            if (editEvaluator) editEvaluator.value = rowData['Evaluator'] || '';
                            console.log('Modal opened for Notice Code: "' + noticeCode + '"');
                        }

                        function closeEditModal() {
                            document.getElementById('editModalOverlay').style.display = 'none';
                        }

                        // Close modal when clicking outside
                        document.addEventListener('DOMContentLoaded', function() {
                            const overlay = document.getElementById('editModalOverlay');
                            overlay.addEventListener('click', function(e) {
                                if (e.target === overlay) {
                                    closeEditModal();
                                }
                            });
                        });

                        // Clear form fields
                        function clearEditForm() {
                            var form = document.getElementById('editForm');
                            form.reset();
                            // Also clear disabled display field
                            document.getElementById('editNoticeCodeDisplay').value = '';
                        }

                        // Attach to edit icon
                        function editRow(noticeCode) {
                            if (!noticeCode) return;
                            // Prefer rowspan-safe cached rows.
                            var row = null;
                            if (Array.isArray(window.mailRows)) {
                                row = window.mailRows.find(function(r) {
                                    return ((r['Notice/Order Code'] || '').trim() === (noticeCode || '').trim());
                                });
                            }
                            if (!row) row = getRowDataFromTable(noticeCode);
                            if (row) openEditModal(row);
                        }

                        // Save handler (AJAX)
                        document.addEventListener('DOMContentLoaded', function() {
                            document.getElementById('editForm').addEventListener('submit', function(e) {
                                e.preventDefault();
                                var form = e.target;
                                var formData = new FormData(form);
                                
                                // Debug: Log what's being sent
                                console.log('Submitting form with:');
                                for (let [key, value] of formData.entries()) {
                                    console.log('  ' + key + ': "' + value + '"');
                                }
                                
                                fetch('../api/EditMail.php', {
                                    method: 'POST',
                                    body: formData
                                })
                                .then(resp => resp.json())
                                .then(data => {
                                    console.log('Response:', data);
                                    if (data.success) {
                                        if (data.pdfWarning) {
                                            alert(data.pdfWarning);
                                        }
                                        var focusNotice = (document.getElementById('editNoticeCodeDisplay').value || '').trim();
                                        var submittedTrackingNo = (formData.get('Tracking No.') || '').trim();
                                        closeEditModal();
                                        refreshHomeData({
                                            focusNotice: focusNotice,
                                            immediateTrackNotices: (submittedTrackingNo !== '' ? [focusNotice] : [])
                                        });
                                    } else {
                                        alert(data.message || 'Failed to save changes.');
                                    }
                                })
                                .catch(err => {
                                    console.error('Error:', err);
                                    alert('Failed to save changes.');
                                });
                            });
                        });

                        // Expose PHP rows as JS array for modal
                        window.mailRows = <?php echo json_encode($rows, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT); ?>;

        function rebuildMailRowsFromTable() {
            const trs = document.querySelectorAll('.admin-table-container table tbody tr[data-notice]');
            const rows = [];
            const activeSpans = {};

            trs.forEach(function(tr) {
                const row = {'Notice/Order Code': (tr.dataset.notice || '').trim()};

                // Fill values inherited via rowspan from previous rows.
                Object.keys(activeSpans).forEach(function(col) {
                    row[col] = activeSpans[col].value;
                });

                // Read explicit cells present on this row.
                tr.querySelectorAll('td[data-col]').forEach(function(td) {
                    const key = td.getAttribute('data-col');
                    row[key] = (td.textContent || '').trim();
                });

                // Keep row objects complete.
                tableDataColumns.forEach(function(col) {
                    if (!Object.prototype.hasOwnProperty.call(row, col)) {
                        row[col] = '';
                    }
                });

                rows.push(row);

                // Consume one row from currently active spans.
                Object.keys(activeSpans).forEach(function(col) {
                    activeSpans[col].remaining -= 1;
                    if (activeSpans[col].remaining <= 0) {
                        delete activeSpans[col];
                    }
                });

                // Register new rowspans starting from this row.
                tr.querySelectorAll('td[data-col]').forEach(function(td) {
                    const key = td.getAttribute('data-col');
                    const span = parseInt(td.getAttribute('rowspan') || '1', 10);
                    if (span > 1) {
                        activeSpans[key] = {
                            value: (td.textContent || '').trim(),
                            remaining: span - 1
                        };
                    }
                });
            });
            window.mailRows = rows;
        }

        function refreshHomeData(options = {}) {
            const focusNotice = (options.focusNotice || '').trim();
            const immediateTrackNotices = Array.isArray(options.immediateTrackNotices) ? options.immediateTrackNotices : [];
            const url = new URL(window.location.href);
            url.searchParams.set('_ts', Date.now().toString());

            return fetch(url.pathname + '?' + url.searchParams.toString(), { cache: 'no-store' })
                .then(function(resp) { return resp.text(); })
                .then(function(html) {
                    const parser = new DOMParser();
                    const doc = parser.parseFromString(html, 'text/html');

                    const currentArea = document.querySelector('.admin-table-container .table-scroll-area');
                    const nextArea = doc.querySelector('.admin-table-container .table-scroll-area');
                    if (currentArea && nextArea) {
                        currentArea.innerHTML = nextArea.innerHTML;
                    }

                    const currentStats = document.querySelector('.statistics-bar');
                    const nextStats = doc.querySelector('.statistics-bar');
                    if (currentStats && nextStats) {
                        currentStats.innerHTML = nextStats.innerHTML;
                    }

                    const currentYear = document.getElementById('tableSortYear');
                    const nextYear = doc.getElementById('tableSortYear');
                    if (currentYear && nextYear) {
                        const selected = currentYear.value;
                        currentYear.innerHTML = nextYear.innerHTML;
                        if (selected && Array.from(currentYear.options).some(function(o){ return o.value === selected; })) {
                            currentYear.value = selected;
                        }
                    }

                    bindRowCheckboxListeners();
                    rebuildMailRowsFromTable();
                    filterTableRows();
                    autoTrackEligibleRows();
                    immediateTrackNotices.forEach(function(notice) {
                        triggerImmediateTrackingOnce(notice);
                    });

                    if (focusNotice) {
                        sessionStorage.setItem('dhsud_focus_notice', focusNotice);
                        focusScannedRow();
                    }
                })
                .catch(function(err) {
                    console.error('refreshHomeData failed:', err);
                });
        }

        function getStatusClass(statusValue) {
            switch ((statusValue || '').trim()) {
                case 'DELIVERED':
                    return 'status-text status-delivered';
                case 'RETURNED TO SENDER':
                    return 'status-text status-returned';
                case 'ONGOING DELIVERY':
                    return 'status-text status-ongoing';
                case 'PERSONALLY RECEIVED':
                    return 'status-text status-personal';
                default:
                    return '';
            }
        }

        function formatDisplayDate(value) {
            if (!value) return '';
            const dateObj = new Date(value);
            if (Number.isNaN(dateObj.getTime())) return '';
            const month = dateObj.toLocaleString('en-US', { month: 'long' });
            const day = String(dateObj.getDate()).padStart(2, '0');
            const year = dateObj.getFullYear();
            return `${month}-${day}-${year}`;
        }

        function findRowByNoticeCode(noticeCode) {
            const checkboxes = document.querySelectorAll('.row-checkbox');
            for (const cb of checkboxes) {
                if ((cb.value || '').trim() === (noticeCode || '').trim()) {
                    return cb.closest('tr');
                }
            }
            return null;
        }

        function updateTrackingRow(noticeCode, data) {
            const row = findRowByNoticeCode(noticeCode);
            if (!row) return;

            const statusCell = row.querySelector('td[data-col="Status"]');
            if (statusCell && typeof data.status !== 'undefined') {
                const statusClass = getStatusClass(data.status);
                statusCell.innerHTML = `<span class="${statusClass}">${data.status || ''}</span>`;
            }

            const dateCell = row.querySelector('td[data-col="Date"]');
            if (dateCell) {
                const dateText = (data.dateDisplay || formatDisplayDate(data.date || '') || '').trim();
                dateCell.textContent = dateText;
            }

            const transmittalCell = row.querySelector('td[data-col="Transmittal Remarks/Received By"]');
            if (transmittalCell && typeof data.transmittalRemarks !== 'undefined') {
                transmittalCell.textContent = data.transmittalRemarks || '';
            }
        }

        function updateTrackingRowsByBatch(batchId, data) {
            const rows = document.querySelectorAll('tr[data-batch-id]');
            rows.forEach(row => {
                if ((row.dataset.batchId || '').trim() === (batchId || '').trim()) {
                    const notice = (row.dataset.notice || '').trim();
                    if (notice) {
                        updateTrackingRow(notice, data);
                    }
                }
            });
        }



        // Table search and sort functionality (filter by Notice/Order Code and year)
        // Keep checked rows visible regardless of filter.
        function filterTableRows() {
            const input = document.getElementById('tableSearchInput');
            const filter = input.value.toLowerCase();
            const yearSelect = document.getElementById('tableSortYear');
            let selectedYear = yearSelect.value;
            if (selectedYear === 'all' || !selectedYear) selectedYear = '';
            const table = document.querySelector('.admin-table-container table');
            const trs = table.querySelectorAll('tbody tr[data-notice]');
            const rowDataByNotice = new Map();
            if (Array.isArray(window.mailRows)) {
                window.mailRows.forEach(function(r) {
                    const n = (r['Notice/Order Code'] || '').trim();
                    if (n) rowDataByNotice.set(n, r);
                });
            }

            const groups = new Map();
            Array.from(trs).forEach(function(tr, idx) {
                const notice = (tr.dataset.notice || '').trim();
                const batchId = (tr.dataset.batchId || '').trim();
                const isBatchRow = tr.classList.contains('batch-row') && batchId !== '';
                const key = isBatchRow ? ('batch:' + batchId) : ('row:' + notice + ':' + idx);
                if (!groups.has(key)) {
                    groups.set(key, { rows: [], notices: [] });
                }
                const g = groups.get(key);
                g.rows.push(tr);
                if (notice) g.notices.push(notice);
            });

            groups.forEach(function(group) {
                const hasChecked = group.rows.some(function(tr) {
                    const cb = tr.querySelector('.row-checkbox');
                    return !!(cb && cb.checked);
                });
                if (hasChecked) {
                    group.rows.forEach(function(tr) { tr.style.display = ''; });
                    return;
                }

                const codeMatch = group.notices.some(function(n) {
                    return n.toLowerCase().indexOf(filter) > -1;
                });

                let yearMatch = true;
                if (selectedYear) {
                    yearMatch = group.notices.some(function(n) {
                        const rowObj = rowDataByNotice.get(n);
                        const dateAfd = rowObj ? String(rowObj['Date released to AFD'] || '') : '';
                        return dateAfd.indexOf(selectedYear) > -1;
                    });
                }

                const visible = codeMatch && yearMatch;
                group.rows.forEach(function(tr) {
                    tr.style.display = visible ? '' : 'none';
                });
            });
        }
        document.addEventListener('DOMContentLoaded', function() {
            document.addEventListener('click', function(e) {
                var dropdown = document.getElementById('rowMenuDropdown');
                if (!dropdown) return;
                var isMenuClick = dropdown.contains(e.target);
                var isButtonClick = e.target.closest && e.target.closest('.row-menu-btn');
                if (!isMenuClick && !isButtonClick) {
                    hideRowMenuDropdown();
                }
            });
            window.addEventListener('scroll', hideRowMenuDropdown, true);
            window.addEventListener('resize', hideRowMenuDropdown);
            // Search and sort bar events
            const searchInput = document.getElementById('tableSearchInput');
            const searchBtn = document.getElementById('tableSearchBtn');
            const yearSelect = document.getElementById('tableSortYear');
            searchInput.addEventListener('input', filterTableRows);
            searchBtn.addEventListener('click', function(e) {
                e.preventDefault();
                filterTableRows();
            });
            yearSelect.addEventListener('change', filterTableRows);
            bindRowCheckboxListeners();
            rebuildMailRowsFromTable();
            focusScannedRow();
            autoTrackEligibleRows();

            const scannerModal = document.getElementById('scannerModal');
            if (scannerModal) {
                scannerModal.addEventListener('click', function(e) {
                    if (e.target === scannerModal) {
                        closeScannerModal();
                    }
                });
            }

            setInterval(function() {
                if (document.hidden) return;
                refreshHomeData();
            }, 12000);
        });

        window.addEventListener('message', function(event) {
            if (event.origin !== window.location.origin) return;
            const data = event.data || {};
            if (data.type === 'scanner-success') {
                closeScannerModal();
                if ((data.noticeCode || '').trim() !== '') {
                    sessionStorage.setItem('dhsud_focus_notice', data.noticeCode.trim());
                }
                const scannerNotice = (data.noticeCode || '').trim();
                refreshHomeData({
                    focusNotice: scannerNotice,
                    immediateTrackNotices: (scannerNotice !== '' ? [scannerNotice] : [])
                });
            }
        });

        function focusScannedRow() {
            const url = new URL(window.location.href);
            const urlNotice = (url.searchParams.get('scanned_notice') || '').trim();
            const storedNotice = (sessionStorage.getItem('dhsud_focus_notice') || '').trim();
            const noticeCode = urlNotice || storedNotice;
            if (!noticeCode) return;

            const row = document.querySelector('tr[data-notice="' + CSS.escape(noticeCode) + '"]');
            if (!row) {
                sessionStorage.removeItem('dhsud_focus_notice');
                return;
            }

            row.scrollIntoView({ behavior: 'smooth', block: 'center', inline: 'nearest' });
            row.classList.add('scanned-row-focus');
            setTimeout(function() { row.classList.remove('scanned-row-focus'); }, 2600);

            sessionStorage.removeItem('dhsud_focus_notice');
            if (urlNotice) {
                url.searchParams.delete('scanned_notice');
                window.history.replaceState({}, '', url.pathname + (url.search ? url.search : '') + url.hash);
            }
        }
        
        function exportSelectedToPDF() {
            let checked = document.querySelectorAll('.row-checkbox:checked');

            if (checked.length === 0) {
                alert("Select record first!");
                return;
            }

            let codes = [];
            checked.forEach(cb => codes.push(cb.value));

            let form = document.createElement("form");
            form.method = "POST";
            form.action = "../api/jrs_tracking.php";
            form.target = "_blank";

            let input = document.createElement("input");
            input.type = "hidden";
            input.name = "notice_codes";
            input.value = JSON.stringify(codes);

            form.appendChild(input);
            document.body.appendChild(form);
            form.submit();
        }

        const AUTO_TRACK_INTERVAL_MS = 12 * 60 * 60 * 1000;
        const autoTrackLastRunByKey = new Map();
        const immediateTrackOnceKeys = new Set();
        let autoTrackInProgress = false;

        function getTrackResultElementForNotice(noticeCode) {
            const row = findRowByNoticeCode(noticeCode);
            if (!row) return null;
            const actionCell = row.lastElementChild;
            return actionCell ? actionCell.querySelector(".track-result") : null;
        }

        function runTrackingUpdate(noticeCode, options = {}) {
            const safeNotice = (noticeCode || "").trim();
            const silent = options.silent === true;
            const result = getTrackResultElementForNotice(safeNotice);

            if (!safeNotice) {
                return Promise.resolve({ ok: false, reason: "missing-notice" });
            }

            if (result) result.innerHTML = "";

            return fetch("../api/remarks.php", {
                method: "POST",
                headers: {"Content-Type": "application/x-www-form-urlencoded"},
                body: "notice_code=" + encodeURIComponent(safeNotice)
            })
            .then(res => res.json())
            .then(data => {
                if (data.error) {
                    if (result && !silent) {
                        result.innerHTML = `<span style="color:red">${data.error}</span>`;
                        setTimeout(function () { result.innerHTML = ""; }, 2000);
                    }
                    return { ok: false, reason: "api-error", data: data };
                }

                if ((data.batchId || '').trim() !== '') {
                    updateTrackingRowsByBatch(data.batchId, data);
                } else {
                    updateTrackingRow(safeNotice, data);
                }

                if (result && !silent) {
                    result.innerHTML = `<span style="color:green">Tracking updated successfully!</span>`;
                    setTimeout(function () { result.innerHTML = ""; }, 2000);
                }
                return { ok: true, data: data };
            })
            .catch(err => {
                console.error(err);
                if (result && !silent) {
                    result.innerHTML = `<span style="color:red">An error occurred</span>`;
                    setTimeout(function () { result.innerHTML = ""; }, 2000);
                }
                return { ok: false, reason: "request-failed", error: err };
            });
        }

        function triggerImmediateTrackingOnce(noticeCode) {
            const safeNotice = (noticeCode || '').trim();
            if (!safeNotice) return;

            const row = findRowByNoticeCode(safeNotice);
            if (!row) return;

            const trackingNo = (row.dataset.trackingNo || '').trim();
            if (!trackingNo || trackingNo === '0') return;

            const batchId = (row.dataset.batchId || '').trim();
            const key = (batchId ? ('batch:' + batchId) : ('notice:' + safeNotice)) + '|tracking:' + trackingNo;
            if (immediateTrackOnceKeys.has(key)) return;
            immediateTrackOnceKeys.add(key);

            const throttleKey = batchId ? ('batch:' + batchId) : ('notice:' + safeNotice);
            autoTrackLastRunByKey.set(throttleKey, Date.now());
            runTrackingUpdate(safeNotice, { silent: true });
        }

        function collectAutoTrackItems() {
            const rows = document.querySelectorAll('tr[data-notice][data-tracking-no]');
            const items = [];
            const seenBatchIds = new Set();

            rows.forEach(function(row) {
                const noticeCode = (row.dataset.notice || '').trim();
                const batchId = (row.dataset.batchId || '').trim();
                const trackingNo = (row.dataset.trackingNo || '').trim();
                const statusCell = row.querySelector('td[data-col="Status"]');
                const statusText = ((statusCell && statusCell.textContent) ? statusCell.textContent : '').trim().toUpperCase();

                if (!noticeCode || !trackingNo || trackingNo === '0') return;
                if (statusText !== 'ONGOING DELIVERY') return;

                if (batchId) {
                    if (seenBatchIds.has(batchId)) return;
                    seenBatchIds.add(batchId);
                    items.push({ key: 'batch:' + batchId, noticeCode: noticeCode });
                    return;
                }

                items.push({ key: 'notice:' + noticeCode, noticeCode: noticeCode });
            });

            return items;
        }

        function autoTrackEligibleRows() {
            if (autoTrackInProgress) return;
            autoTrackInProgress = true;

            const now = Date.now();
            const items = collectAutoTrackItems().filter(function(item) {
                const lastRun = autoTrackLastRunByKey.get(item.key) || 0;
                return (now - lastRun) >= AUTO_TRACK_INTERVAL_MS;
            });

            if (items.length === 0) {
                autoTrackInProgress = false;
                return;
            }

            items.reduce(function(chain, item) {
                return chain.then(function() {
                    return runTrackingUpdate(item.noticeCode, { silent: true }).finally(function() {
                        autoTrackLastRunByKey.set(item.key, Date.now());
                    });
                }).then(function() {
                    return new Promise(function(resolve) { setTimeout(resolve, 200); });
                });
            }, Promise.resolve())
            .finally(function() {
                autoTrackInProgress = false;
            });
        }

        document.addEventListener("click", function (evt) {
            const button = evt.target.closest(".btn-track");
            if (!button) return;
            const noticeCode = (button.dataset.notice || "").trim();
            runTrackingUpdate(noticeCode, { silent: false });
        });


        </script>
    </div>
</body>
</html>
