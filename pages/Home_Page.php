 
<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth.php';

// Require login to access this page
requireLogin();

// Handle status update when submitted per-row
$message = '';
$updatedNotice = '';
$updatedStatus = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (!empty($_POST['row_id']) || !empty($_POST['notice_code'])) && isset($_POST['status'])) {
    $rowId = (int)($_POST['row_id'] ?? 0);
    $notice = trim($_POST['notice_code'] ?? '');
    $status = trim($_POST['status']);
    $isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest';
    if ($rowId <= 0 && $notice === '') {
        $message = 'Missing record id.';
    } elseif ($status === '') {
        // placeholder or empty selection — don't save
        $message = 'No status selected.';
    } else {
        try {
            $sql = ($rowId > 0)
                ? 'UPDATE mailtracking SET `Status` = :status WHERE `id` = :row_id'
                : 'UPDATE mailtracking SET `Status` = :status WHERE `Notice/Order Code` = :notice';
            $stmt = $pdo->prepare($sql);
            if ($rowId > 0) {
                $stmt->execute([':status' => $status, ':row_id' => $rowId]);
            } else {
                $stmt->execute([':status' => $status, ':notice' => $notice]);
            }
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
            'row_id' => $rowId,
            'notice' => $updatedNotice,
            'status' => $updatedStatus,
        ]);
        exit;
    }
}


// Fetch all rows to display
try {
    // Keep rows from the same batch adjacent so merged-cell rendering stays coherent,
    // including records recovered from archive.
    $rows = $pdo->query('SELECT * FROM mailtracking ORDER BY `Sender Details` ASC, `id` ASC')->fetchAll();
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
    <link rel="icon" type="image/x-icon" href="../assets/DHSUDLogo.ico">
    <link rel="stylesheet" href="../main.css">
</head>

<body class="admin-home-bg">
    <div class="admin-home-header">
    <div class="welcome-block">
        <div style="font-size:1.2em;font-weight:600;color:#22336A;margin-top:5px;margin-bottom:2px;">Welcome, Admin!</div>
        <a href="logout.php" class="logout-btn">Logout</a>
    </div>
        <img src="../assets/Admin_HomePage_New.svg" alt="Admin Home Header" class="admin-home-header-img">
        <div class="admin-home-header-border"></div>
    </div>
        <!-- Edit Modal (hidden by default) -->
        <div id="editModalOverlay" class="edit-modal-overlay" style="display:none;">
            <div class="edit-modal edit-modal-scrollable" id="editModal">
<button class="modal-close" onclick="closeEditModal()" title="Close">&times;</button>
                <h2>EDIT MAIL RECORD</h2>
                <form id="editForm" autocomplete="off">
                    <input type="hidden" name="original_id" id="editRowId">
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

            function openPdfViewerModal(pdfUrl, pdfTitle) {
                const modal = document.getElementById('pdfViewerModal');
                const frame = document.getElementById('pdfViewerFrame');
                const title = document.getElementById('pdfViewerTitle');
                if (!modal || !frame || !title || !pdfUrl) return;
                const cleanUrl = String(pdfUrl).split('#')[0];
                const fitUrl = cleanUrl + '#zoom=95';
                frame.src = 'about:blank';
                frame.src = fitUrl;
                title.textContent = (pdfTitle || 'PDF Preview');
                modal.style.display = 'flex';
                modal.setAttribute('aria-hidden', 'false');
            }

            function closePdfViewerModal() {
                const modal = document.getElementById('pdfViewerModal');
                const frame = document.getElementById('pdfViewerFrame');
                if (!modal || !frame) return;
                modal.style.display = 'none';
                modal.setAttribute('aria-hidden', 'true');
                frame.src = 'about:blank';
            }

            document.addEventListener('click', function(event) {
                const link = event.target.closest('.pdf-link-in-cell[data-pdf-url]');
                if (link) {
                    event.preventDefault();
                    const pdfUrl = link.getAttribute('data-pdf-url') || link.getAttribute('href') || '';
                    const pdfTitle = link.getAttribute('data-pdf-title') || (link.textContent || '').trim();
                    if (pdfUrl) openPdfViewerModal(pdfUrl, pdfTitle);
                    return;
                }

                const pdfModal = document.getElementById('pdfViewerModal');
                if (pdfModal && event.target === pdfModal) {
                    closePdfViewerModal();
                }
            });

            document.addEventListener('keydown', function(event) {
                if (event.key === 'Escape') {
                    closePdfViewerModal();
                }
            });

            </script>
        <div class="table-sort-bar">
            <select id="tableSortYear" class="table-sort-select" required style="min-width:65px;">
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
                <button class="table-notif-btn" id="tableNotifBtn" title="Tracking Status Notifications">
                    <img src="../assets/Notif_Icon.svg" alt="Notifications">
                    <span class="notif-badge" id="notifBadge" style="display: none;">0</span>
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
                                <tr class="<?= htmlspecialchars(implode(' ', $rowClasses)) ?>" data-id="<?= (int)($row['id'] ?? 0) ?>" data-batch-id="<?= htmlspecialchars($rowBatchId) ?>" data-notice="<?= htmlspecialchars($row['Notice/Order Code'] ?? '') ?>" data-tracking-no="<?= htmlspecialchars($rowTrackingNo) ?>">
                                    <td style="width:32px;">
                                        <input type="checkbox" class="row-checkbox" value="<?= (int)($row['id'] ?? 0) ?>" data-notice="<?= htmlspecialchars($row['Notice/Order Code'] ?? '') ?>">
                                    </td>
                                    <td class="notice-code-cell">
                                        <div style="display: flex; align-items: center; gap: 0.3em;">
                                            <div class="row-menu-container">
                                                <button class="row-menu-btn" type="button" tabindex="0" aria-label="Row menu" onclick="toggleRowMenu(event, <?= (int)($row['id'] ?? 0) ?>)">
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
                                                    <a href="<?= htmlspecialchars($fileHref) ?>" data-pdf-url="<?= htmlspecialchars($fileHref) ?>" data-pdf-title="<?= htmlspecialchars($linkLabel, ENT_QUOTES) ?>" class="pdf-link-in-cell"><?= htmlspecialchars($linkLabel) ?></a>
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
        <div id="notifModalOverlay" class="notif-modal-overlay">
            <div class="notif-modal">
                <div class="notif-modal-header">
                    <h2>Notifications</h2>
                    <button class="notif-modal-close" onclick="closeNotifModal()" aria-label="Close">×</button>
                </div>
                <div class="notif-modal-content" id="notifModalContent">
                    <!-- Notifications will be dynamically inserted here -->
                    <div class="notif-empty">No notifications</div>
                </div>
            </div>
        </div>
        <div id="pdfViewerModal" class="pdf-viewer-modal" aria-hidden="true">
            <div class="pdf-viewer-panel">
                <div class="pdf-viewer-head">
                    <h3 id="pdfViewerTitle" class="pdf-viewer-title">PDF Preview</h3>
                    <button type="button" class="pdf-viewer-close" onclick="closePdfViewerModal()"><img class="exit-modal" src="../assets/icon.svg" alt="Close"></button>
                </div>
                <iframe id="pdfViewerFrame" name="pdfViewerFrame" class="pdf-viewer-frame" src="about:blank" title="PDF Viewer"></iframe>
            </div>
        </div>

        <!-- Add New Record Modal (hidden by default) -->
        
        <div style="display: flex; gap: 10px; margin-top: 2 px; align-items: center;">
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
<script>
            (function cleanDeleteQueryParam() {
                var url = new URL(window.location.href);
                if (url.searchParams.has('deleted')) {
                    url.searchParams.delete('deleted');
                    window.history.replaceState({}, '', url.pathname + (url.search ? url.search : '') + url.hash);
                }
            })();

            var currentRowMenuId = 0;
            function hideRowMenuDropdown() {
                var dropdown = document.getElementById('rowMenuDropdown');
                if (dropdown) dropdown.style.display = 'none';
                currentRowMenuId = 0;
            }
            function toggleRowMenu(event, rowId) {
                event.stopPropagation();
                var dropdown = document.getElementById('rowMenuDropdown');
                if (!dropdown) return;
                var safeRowId = parseInt(rowId, 10) || 0;
                if (dropdown.style.display === 'block' && currentRowMenuId === safeRowId) {
                    hideRowMenuDropdown();
                    return;
                }
                currentRowMenuId = safeRowId;
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
                var rowId = currentRowMenuId;
                hideRowMenuDropdown();
                if (rowId > 0) {
                    editRow(rowId);
                }
            }
            function deleteRecordFromMenu() {
                var rowId = currentRowMenuId;
                hideRowMenuDropdown();
                if (rowId > 0) {
                    deleteRecord(rowId);
                }
            }
            function deleteRecord(rowId) {
                if (confirm('Are you sure you want to delete this record?')) {
                   var safeRowId = parseInt(rowId, 10) || 0;
                   if (safeRowId <= 0) return;
                        fetch('../api/Delete.php', {
                            method: 'POST',
                            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                            body: 'id=' + encodeURIComponent(String(safeRowId))
                        })
                        .then(function (resp) {
                            if (!resp.ok) {
                                return resp.text().then(function (t) { throw new Error(t || 'Delete failed'); });
                            }
                            return refreshHomeData();
                        })
                        .catch(function (err) {
                            console.error(err);
                            alert('Failed to delete record: ' + (err && err.message ? err.message : 'Unknown error'));
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

                        function getRowDataFromTable(rowId) {
                            var safeRowId = parseInt(rowId, 10) || 0;
                            if (safeRowId <= 0) return null;

                            if (Array.isArray(window.mailRows)) {
                                var cached = window.mailRows.find(function(r) {
                                    return (parseInt(r.id, 10) || 0) === safeRowId;
                                });
                                if (cached) return cached;
                            }

                            var tr = document.querySelector('tr[data-id="' + String(safeRowId) + '"]');
                            if (!tr) return null;
                            var row = {
                                'id': safeRowId,
                                'Notice/Order Code': (tr.dataset.notice || '').trim()
                            };
                            tr.querySelectorAll('td[data-col]').forEach(function(td) {
                                var key = td.getAttribute('data-col');
                                row[key] = (td.textContent || '').trim();
                            });
                            return row;
                        }

                        function openEditModal(rowData) {
                            document.getElementById('editModalOverlay').style.display = 'flex';
                            // Fill form fields - both id and notice are kept for compatibility.
                            var rowId = parseInt(rowData.id, 10) || 0;
                            var noticeCode = (rowData['Notice/Order Code'] || '').trim();
                            document.getElementById('editRowId').value = String(rowId);
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
                        function editRow(rowId) {
                            var safeRowId = parseInt(rowId, 10) || 0;
                            if (safeRowId <= 0) return;
                            // Prefer rowspan-safe cached rows.
                            var row = null;
                            if (Array.isArray(window.mailRows)) {
                                row = window.mailRows.find(function(r) {
                                    return (parseInt(r.id, 10) || 0) === safeRowId;
                                });
                            }
                            if (!row) row = getRowDataFromTable(safeRowId);
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
            const trs = document.querySelectorAll('.admin-table-container table tbody tr[data-id]');
            const rows = [];
            const activeSpans = {};

            trs.forEach(function(tr) {
                const row = {
                    'id': parseInt(tr.dataset.id || '0', 10) || 0,
                    'Notice/Order Code': (tr.dataset.notice || '').trim()
                };

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
            const focusRowId = parseInt(options.focusRowId || 0, 10) || 0;
            const immediateTrackNotices = Array.isArray(options.immediateTrackNotices) ? options.immediateTrackNotices : [];
            const previousStatusSnapshot = cloneStatusSnapshot();
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
                    notifyStatusDiffsAfterRefresh(previousStatusSnapshot);
                    autoTrackEligibleRows();
                    immediateTrackNotices.forEach(function(notice) {
                        triggerImmediateTrackingOnce(notice);
                    });

                    if (focusRowId > 0) {
                        sessionStorage.setItem('dhsud_focus_id', String(focusRowId));
                    }
                    if (focusNotice) {
                        sessionStorage.setItem('dhsud_focus_notice', focusNotice);
                    }
                    if (focusRowId > 0 || focusNotice) {
                        focusScannedRow();
                    }
                    const knownVersion = ((options.knownVersion || '') + '').trim();
                    if (knownVersion) {
                        smartPollingState.lastKnownVersion = knownVersion;
                    } else {
                        syncHomeDataVersionSilently();
                    }
                    return true;
                })
                .catch(function(err) {
                    console.error('refreshHomeData failed:', err);
                    return false;
                });
        }

        const SMART_POLLING = {
            burstIntervalMs: 1000,
            burstWindowMs: 15000,
            activeIntervalMs: 12000,
            idleIntervalMs: 30000,
            hiddenIntervalMs: 120000,
            activeWindowMs: 45000,
            maxBackoffMs: 300000,
            jitterRatio: 0.1
        };

        const smartPollingState = {
            timerId: 0,
            inProgress: false,
            failureCount: 0,
            lastUserActivityAt: Date.now(),
            lastKnownVersion: '',
            burstUntilAt: 0
        };

        function markPollingActivity() {
            smartPollingState.lastUserActivityAt = Date.now();
        }

        function getSmartPollingDelay() {
            const now = Date.now();
            const inactiveForMs = now - smartPollingState.lastUserActivityAt;
            let delay = SMART_POLLING.activeIntervalMs;

            if (!document.hidden && now < smartPollingState.burstUntilAt) {
                delay = SMART_POLLING.burstIntervalMs;
            } else if (document.hidden) {
                delay = SMART_POLLING.hiddenIntervalMs;
            } else if (inactiveForMs > SMART_POLLING.activeWindowMs) {
                delay = SMART_POLLING.idleIntervalMs;
            }

            if (smartPollingState.failureCount > 0) {
                const multiplier = Math.pow(2, smartPollingState.failureCount);
                delay = Math.min(delay * multiplier, SMART_POLLING.maxBackoffMs);
            }

            const jitter = Math.round(delay * SMART_POLLING.jitterRatio * Math.random());
            return delay + jitter;
        }

        function scheduleSmartPolling(options = {}) {
            if (smartPollingState.timerId) {
                clearTimeout(smartPollingState.timerId);
            }
            const immediate = options.immediate === true;
            const delay = immediate ? 250 : getSmartPollingDelay();

            smartPollingState.timerId = window.setTimeout(function() {
                runSmartPollingTick();
            }, delay);
        }

        function fetchHomeDataVersion() {
            const url = '../api/home-version.php?_ts=' + Date.now();
            return fetch(url, {
                method: 'GET',
                cache: 'no-store',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(function(resp) {
                if (!resp.ok) throw new Error('Version endpoint failed');
                return resp.json();
            })
            .then(function(data) {
                if (!data || data.success !== true) {
                    throw new Error((data && data.message) ? data.message : 'Invalid version response');
                }
                return ((data.version || '') + '').trim();
            });
        }

        function checkForHomeDataChange() {
            return fetchHomeDataVersion().then(function(currentVersion) {
                if (!currentVersion) {
                    return { changed: true, version: '' };
                }

                const previousVersion = smartPollingState.lastKnownVersion;
                smartPollingState.lastKnownVersion = currentVersion;

                if (!previousVersion) {
                    return { changed: true, version: currentVersion };
                }

                if (previousVersion !== currentVersion) {
                    smartPollingState.burstUntilAt = Date.now() + SMART_POLLING.burstWindowMs;
                }

                return { changed: previousVersion !== currentVersion, version: currentVersion };
            });
        }

        function syncHomeDataVersionSilently() {
            return fetchHomeDataVersion()
                .then(function(version) {
                    if (version) {
                        smartPollingState.lastKnownVersion = version;
                    }
                })
                .catch(function() {
                    // Best effort only.
                });
        }

        function runSmartPollingTick() {
            if (smartPollingState.inProgress) {
                scheduleSmartPolling();
                return;
            }

            smartPollingState.inProgress = true;

            checkForHomeDataChange()
                .then(function(result) {
                    if (!result.changed) {
                        autoTrackEligibleRows();
                        return true;
                    }
                    return refreshHomeData({ knownVersion: result.version });
                })
                .then(function(success) {
                    if (success) {
                        smartPollingState.failureCount = 0;
                    } else {
                        smartPollingState.failureCount += 1;
                    }
                })
                .catch(function() {
                    smartPollingState.failureCount += 1;
                })
                .finally(function() {
                    smartPollingState.inProgress = false;
                    scheduleSmartPolling();
                });
        }

        function initSmartPolling() {
            const activityEvents = ['mousedown', 'keydown', 'touchstart', 'scroll'];
            activityEvents.forEach(function(evtName) {
                window.addEventListener(evtName, markPollingActivity, { passive: true });
            });

            document.addEventListener('visibilitychange', function() {
                if (!document.hidden) {
                    markPollingActivity();
                    scheduleSmartPolling({ immediate: true });
                    return;
                }
                scheduleSmartPolling();
            });

            window.addEventListener('focus', function() {
                markPollingActivity();
                scheduleSmartPolling({ immediate: true });
            });

            window.addEventListener('online', function() {
                scheduleSmartPolling({ immediate: true });
            });

            syncHomeDataVersionSilently();
            scheduleSmartPolling();
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

        const statusSnapshotByNotice = new Map();

        function cloneStatusSnapshot() {
            return new Map(statusSnapshotByNotice);
        }

        function rebuildStatusSnapshotFromMailRows() {
            statusSnapshotByNotice.clear();
            if (!Array.isArray(window.mailRows)) return;

            window.mailRows.forEach(function(row) {
                const notice = ((row['Notice/Order Code'] || '') + '').trim();
                if (!notice) return;
                const status = ((row['Status'] || '') + '').trim().toUpperCase();
                const eventDate = (formatDisplayDate(row['Date'] || '') || ((row['Date'] || '') + '').trim());
                statusSnapshotByNotice.set(notice, {
                    status: status,
                    eventDate: eventDate
                });
            });
        }

        function notifyStatusDiffsAfterRefresh(previousSnapshot) {
            if (!(previousSnapshot instanceof Map) || previousSnapshot.size === 0) {
                rebuildStatusSnapshotFromMailRows();
                return;
            }
            if (!Array.isArray(window.mailRows)) return;

            window.mailRows.forEach(function(row) {
                const notice = ((row['Notice/Order Code'] || '') + '').trim();
                if (!notice) return;

                const nextStatus = ((row['Status'] || '') + '').trim().toUpperCase();
                const eventDate = (formatDisplayDate(row['Date'] || '') || ((row['Date'] || '') + '').trim());
                const prevEntry = previousSnapshot.get(notice);
                const previousStatus = prevEntry ? (((prevEntry.status || '') + '').trim().toUpperCase()) : '';

                if (prevEntry && previousStatus !== nextStatus) {
                    maybeNotifyStatusChange(notice, previousStatus, nextStatus, eventDate);
                }

                statusSnapshotByNotice.set(notice, {
                    status: nextStatus,
                    eventDate: eventDate
                });
            });
        }

        function normalizeCellTextForSearchView(colName, rawValue) {
            const value = (rawValue || '').toString().trim();
            if (!value) return '';
            if (colName === 'Date released to AFD' || colName === 'Date') {
                return formatDisplayDate(value) || value;
            }
            if (colName === 'Sender Details') {
                return value.replace(/\r?\n?Batch ID:\s*[A-Za-z0-9\-]+\s*/i, '').trim();
            }
            return value;
        }

        function rebuildVisibleRowCellsForSearch(tr, rowObj) {
            if (!tr || !rowObj) return;
            const actionCell = tr.lastElementChild;
            if (!actionCell) return;

            const cellsByCol = new Map();
            tr.querySelectorAll('td[data-col]').forEach(function(td) {
                const col = td.getAttribute('data-col');
                if (col) cellsByCol.set(col, td);
            });

            tableDataColumns.forEach(function(colName) {
                let td = cellsByCol.get(colName);
                if (!td) {
                    td = document.createElement('td');
                    td.setAttribute('data-col', colName);
                    td.setAttribute('data-temp-fill', '1');
                    const text = normalizeCellTextForSearchView(colName, rowObj[colName]);
                    if (colName === 'Status') {
                        const span = document.createElement('span');
                        span.className = getStatusClass(text);
                        span.textContent = text;
                        td.appendChild(span);
                    } else if (colName === 'File Name (PDF)') {
                        const trackingValue = ((rowObj['Tracking No.'] || '') + '').trim();
                        if (text !== '' && trackingValue !== '') {
                            const link = document.createElement('a');
                            const pdfName = 'proof_' + trackingValue + '.pdf';
                            const pdfUrl = '../JRS_PDFs/' + encodeURIComponent(pdfName);
                            link.className = 'pdf-link-in-cell';
                            link.href = pdfUrl;
                            link.setAttribute('data-pdf-url', pdfUrl);
                            link.setAttribute('data-pdf-title', text);
                            link.textContent = text;
                            td.appendChild(link);
                        }
                    } else {
                        td.textContent = text;
                    }
                }
                if (td.hasAttribute('rowspan')) {
                    td.removeAttribute('rowspan');
                }
                tr.insertBefore(td, actionCell);
            });
        }

        function findRowByNoticeCode(noticeCode) {
            const safeNotice = (noticeCode || '').trim();
            if (!safeNotice) return null;
            return document.querySelector('tr[data-notice="' + CSS.escape(safeNotice) + '"]');
        }

        function updateTrackingRow(noticeCode, data) {
            const row = findRowByNoticeCode(noticeCode);
            if (!row) return;
            const resolvedNotice = ((row.dataset.notice || '').trim() || (noticeCode || '').trim());

            const dateCell = row.querySelector('td[data-col="Date"]');
            const existingDateText = ((dateCell && dateCell.textContent) ? dateCell.textContent : '').trim();
            const nextDateText = (data.dateDisplay || formatDisplayDate(data.date || '') || existingDateText).trim();
            const statusCell = row.querySelector('td[data-col="Status"]');
            if (statusCell && typeof data.status !== 'undefined') {
                const previousStatus = ((statusCell.textContent || '') + '').trim().toUpperCase();
                const nextStatus = ((data.status || '') + '').trim().toUpperCase();
                const statusClass = getStatusClass(data.status);
                statusCell.innerHTML = `<span class="${statusClass}">${data.status || ''}</span>`;
                maybeNotifyStatusChange(resolvedNotice, previousStatus, nextStatus, nextDateText);
                statusSnapshotByNotice.set(resolvedNotice, {
                    status: nextStatus,
                    eventDate: nextDateText
                });
            }

            if (dateCell) {
                dateCell.textContent = nextDateText;
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
            const isFiltering = (filter !== '' || selectedYear !== '');
            const yearBatchMode = (filter === '' && selectedYear !== '');
            const table = document.querySelector('.admin-table-container table');
            const trs = table.querySelectorAll('tbody tr[data-notice]');
            const rowDataById = new Map();
            const matchedBatchIds = new Set();
            if (Array.isArray(window.mailRows)) {
                window.mailRows.forEach(function(r) {
                    const id = parseInt(r.id, 10) || 0;
                    if (id > 0) rowDataById.set(id, r);
                });
            }

            Array.from(trs).forEach(function(tr) {
                const rowId = parseInt(tr.dataset.id || '0', 10) || 0;
                const notice = (tr.dataset.notice || '').trim();
                const batchId = (tr.dataset.batchId || '').trim();
                const cb = tr.querySelector('.row-checkbox');
                const isChecked = !!(cb && cb.checked);

                tr.querySelectorAll('td[data-temp-fill="1"]').forEach(function(td) { td.remove(); });
                if (isChecked) {
                    tr.style.display = '';
                    if (isFiltering && !yearBatchMode) {
                        const rowObjChecked = rowDataById.get(rowId);
                        rebuildVisibleRowCellsForSearch(tr, rowObjChecked);
                    }
                    return;
                }

                const codeMatch = notice.toLowerCase().indexOf(filter) > -1;

                let yearMatch = true;
                if (selectedYear) {
                    const rowObj = rowDataById.get(rowId);
                    const dateAfd = rowObj ? String(rowObj['Date released to AFD'] || '') : '';
                    yearMatch = dateAfd.indexOf(selectedYear) > -1;
                }

                const visible = codeMatch && yearMatch;
                if (visible && yearBatchMode && batchId !== '') {
                    matchedBatchIds.add(batchId);
                }
                tr.style.display = visible ? '' : 'none';
                if (visible && isFiltering && !yearBatchMode) {
                    const rowObj = rowDataById.get(rowId);
                    rebuildVisibleRowCellsForSearch(tr, rowObj);
                }
            });

            // Year-only filter should keep batch rows together.
            if (yearBatchMode && matchedBatchIds.size > 0) {
                Array.from(trs).forEach(function(tr) {
                    const cb = tr.querySelector('.row-checkbox');
                    const isChecked = !!(cb && cb.checked);
                    if (isChecked) return;
                    const batchId = (tr.dataset.batchId || '').trim();
                    if (batchId && matchedBatchIds.has(batchId)) {
                        tr.style.display = '';
                    }
                });
            }
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
            rebuildStatusSnapshotFromMailRows();
            focusScannedRow();
            autoTrackEligibleRows();
            initSmartPolling();

            const scannerModal = document.getElementById('scannerModal');
            if (scannerModal) {
                scannerModal.addEventListener('click', function(e) {
                    if (e.target === scannerModal) {
                        closeScannerModal();
                    }
                });
            }

        });

        window.addEventListener('message', function(event) {
            if (event.origin !== window.location.origin) return;
            const data = event.data || {};
            if (data.type === 'scanner-success') {
                closeScannerModal();
                triggerReceiptGenerationFromScanner(data.noticeCode || '', data.trackingNumber || '');
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

        function triggerReceiptGenerationFromScanner(noticeCode, trackingNumber) {
            const safeNotice = (noticeCode || '').trim();
            const safeTracking = (trackingNumber || '').trim();
            if (!safeNotice || !safeTracking) return;

            fetch('../api/remarks.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: 'notice_code=' + encodeURIComponent(safeNotice)
            })
            .then(function(resp) {
                if (!resp.ok) throw new Error('Status check failed');
                return resp.json();
            })
            .then(function(data) {
                if (data && data.error) return;
                const status = (((data && data.status) || '') + '').trim().toUpperCase();
                if (status !== 'DELIVERED' && status !== 'RETURNED TO SENDER') return;
                return fetch('../api/download-receipt.php?tracking=' + encodeURIComponent(safeTracking), {
                    method: 'GET',
                    cache: 'no-store'
                }).catch(function() {
                    return null;
                });
            })
            .catch(function() {
                // Background task only.
            });
        }

        function focusScannedRow() {
            const url = new URL(window.location.href);
            const urlRowId = parseInt(url.searchParams.get('scanned_id') || '0', 10) || 0;
            const urlNotice = (url.searchParams.get('scanned_notice') || '').trim();
            const storedRowId = parseInt(sessionStorage.getItem('dhsud_focus_id') || '0', 10) || 0;
            const storedNotice = (sessionStorage.getItem('dhsud_focus_notice') || '').trim();
            const rowId = urlRowId || storedRowId;
            const noticeCode = urlNotice || storedNotice;
            if (rowId <= 0 && !noticeCode) return;

            let row = null;
            if (rowId > 0) {
                row = document.querySelector('tr[data-id="' + String(rowId) + '"]');
            }
            if (!row && noticeCode) {
                row = document.querySelector('tr[data-notice="' + CSS.escape(noticeCode) + '"]');
            }
            if (!row) {
                sessionStorage.removeItem('dhsud_focus_notice');
                sessionStorage.removeItem('dhsud_focus_id');
                return;
            }

            row.scrollIntoView({ behavior: 'smooth', block: 'center', inline: 'nearest' });
            row.classList.add('scanned-row-focus');
            setTimeout(function() { row.classList.remove('scanned-row-focus'); }, 2600);

            sessionStorage.removeItem('dhsud_focus_notice');
            sessionStorage.removeItem('dhsud_focus_id');
            if (urlNotice || urlRowId > 0) {
                url.searchParams.delete('scanned_notice');
                url.searchParams.delete('scanned_id');
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
            checked.forEach(cb => {
                const code = (cb.dataset.notice || '').trim();
                if (code) codes.push(code);
            });
            if (codes.length === 0) {
                alert("Selected rows have no Notice/Order Code.");
                return;
            }

            let form = document.createElement("form");
            form.method = "POST";
            form.action = "../api/jrs_tracking.php";
            form.target = "pdfViewerFrame";

            let input = document.createElement("input");
            input.type = "hidden";
            input.name = "notice_codes";
            input.value = JSON.stringify(codes);

            const modal = document.getElementById('pdfViewerModal');
            const frame = document.getElementById('pdfViewerFrame');
            const title = document.getElementById('pdfViewerTitle');
            if (modal && frame && title) {
                title.textContent = "Exported PDF";
                frame.src = 'about:blank';
                modal.style.display = 'flex';
                modal.setAttribute('aria-hidden', 'false');
            }

            form.appendChild(input);
            document.body.appendChild(form);
            form.submit();
            setTimeout(function() {
                if (form && form.parentNode) form.parentNode.removeChild(form);
            }, 0);
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
            const targetRow = findRowByNoticeCode(safeNotice);
            const rowId = targetRow ? (parseInt(targetRow.dataset.id || '0', 10) || 0) : 0;

            if (!safeNotice) {
                return Promise.resolve({ ok: false, reason: "missing-notice" });
            }

            if (result) result.innerHTML = "";

            return fetch("../api/remarks.php", {
                method: "POST",
                headers: {"Content-Type": "application/x-www-form-urlencoded"},
                body: "row_id=" + encodeURIComponent(String(rowId)) + "&notice_code=" + encodeURIComponent(safeNotice)
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

        const NOTIFICATION_STORAGE_KEY = 'dhsud_status_notifications_v1';
        let activeNotifActionId = 0;

        function isNotifiableStatus(statusValue) {
            const s = ((statusValue || '') + '').trim().toUpperCase();
            return s === 'DELIVERED' || s === 'RETURNED TO SENDER';
        }

        function getNotificationStatusType(statusValue) {
            const s = ((statusValue || '') + '').trim().toUpperCase();
            return s === 'RETURNED TO SENDER' ? 'returned' : 'delivered';
        }

        function readNotifications() {
            try {
                const raw = localStorage.getItem(NOTIFICATION_STORAGE_KEY);
                if (!raw) return [];
                const parsed = JSON.parse(raw);
                return Array.isArray(parsed) ? parsed : [];
            } catch (e) {
                return [];
            }
        }

        function writeNotifications(items) {
            try {
                localStorage.setItem(NOTIFICATION_STORAGE_KEY, JSON.stringify(Array.isArray(items) ? items : []));
            } catch (e) {
                // ignore storage failures
            }
        }

        function formatNotificationTimestamp(notif) {
            const eventDate = ((notif && notif.eventDate) ? String(notif.eventDate) : '').trim();
            const isoText = (notif && notif.timestampIso) ? String(notif.timestampIso) : '';
            const d = new Date(isoText);
            if (eventDate && Number.isNaN(d.getTime())) return eventDate;
            if (Number.isNaN(d.getTime())) return eventDate;

            const datePart = eventDate || d.toLocaleDateString('en-US', {
                year: 'numeric',
                month: 'long',
                day: 'numeric'
            });
            const timePart = d.toLocaleTimeString('en-US', {
                hour: 'numeric',
                minute: '2-digit',
                hour12: true
            });
            return `${datePart} ${timePart}`;
        }

        function maybeNotifyStatusChange(noticeCode, previousStatus, nextStatus, eventDateText) {
            const notice = (noticeCode || '').trim();
            const prev = ((previousStatus || '') + '').trim().toUpperCase();
            const next = ((nextStatus || '') + '').trim().toUpperCase();
            const eventDate = ((eventDateText || '') + '').trim();
            if (!notice) return;
            if (!isNotifiableStatus(next)) return;
            if (prev === next) return;

            const notifications = readNotifications();
            notifications.unshift({
                id: Date.now() + Math.floor(Math.random() * 1000),
                trackingId: notice,
                status: next,
                statusType: getNotificationStatusType(next),
                eventDate: eventDate,
                timestampIso: new Date().toISOString(),
                read: false
            });

            if (notifications.length > 100) notifications.length = 100;
            writeNotifications(notifications);
            updateNotifBadge(notifications.filter(function(n) { return !n.read; }).length);
        }

        function positionNotifModal() {
            const overlay = document.getElementById('notifModalOverlay');
            const modal = overlay ? overlay.querySelector('.notif-modal') : null;
            const notifBtn = document.getElementById('tableNotifBtn');
            if (!overlay || !modal || !notifBtn) return;

            const btnRect = notifBtn.getBoundingClientRect();
            const modalWidth = Math.min(420, Math.max(260, window.innerWidth - 16));
            let left = btnRect.right - modalWidth;
            left = Math.max(8, Math.min(left, window.innerWidth - modalWidth - 8));

            const top = Math.max(8, btnRect.bottom + 10);
            const maxHeight = Math.max(220, window.innerHeight - top - 10);
            const arrowLeft = Math.max(18, Math.min(modalWidth - 18, btnRect.right - left - 14));

            overlay.style.setProperty('--notif-left', left + 'px');
            overlay.style.setProperty('--notif-top', top + 'px');
            overlay.style.setProperty('--notif-max-height', maxHeight + 'px');
            overlay.style.setProperty('--notif-arrow-left', arrowLeft + 'px');
        }

        // Notification Modal Functions
        function openNotifModal() {
            const overlay = document.getElementById('notifModalOverlay');
            if (overlay) {
                positionNotifModal();
                overlay.classList.add('show');
                loadNotifications();
            }
        }

        function closeNotifModal() {
            const overlay = document.getElementById('notifModalOverlay');
            if (overlay) {
                overlay.classList.remove('show');
            }
        }

        function loadNotifications() {
            const content = document.getElementById('notifModalContent');
            if (!content) return;

            const notifications = readNotifications();

            if (notifications.length === 0) {
                content.innerHTML = '<div class="notif-empty">No notifications</div>';
                updateNotifBadge(0);
                return;
            }

            const unreadCount = notifications.filter(n => !n.read).length;
            updateNotifBadge(unreadCount);

            content.innerHTML = notifications.map(notif => {
                const notifId = parseInt(notif.id, 10) || 0;
                const menuOpen = activeNotifActionId === notifId;
                return `
                <div class="notif-item" data-notif-id="${notif.id}" data-notice="${escapeHtml(notif.trackingId)}" onclick="handleNotifClick(${notif.id})">
                    <div class="notif-indicator ${notif.read ? 'read' : 'unread'}"></div>
                    <div class="notif-content">
                        <div class="notif-text">
                            <span class="notif-tracking-id">${escapeHtml(notif.trackingId)}</span> is now <span class="notif-status ${notif.statusType}">${escapeHtml(notif.status)}</span>
                        </div>
                        <div class="notif-timestamp">
                            <span>&#128339;</span> ${escapeHtml(formatNotificationTimestamp(notif))}
                        </div>
                    </div>
                    <div class="notif-action-wrap" onclick="event.stopPropagation();">
                        <button class="notif-action" onclick="event.stopPropagation(); handleNotifAction(${notif.id})" aria-label="More options">&#8942;</button>
                        ${menuOpen ? `
                        <div class="notif-action-menu">
                            <button class="notif-action-item" onclick="event.stopPropagation(); deleteNotification(${notif.id});">Delete</button>
                        </div>` : ''}
                    </div>
                </div>
            `;
            }).join('');
        }

        function updateNotifBadge(count) {
            const badge = document.getElementById('notifBadge');
            if (badge) {
                if (count > 0) {
                    badge.textContent = count > 99 ? '99+' : count;
                    badge.style.display = 'flex';
                } else {
                    badge.style.display = 'none';
                }
            }
        }

        function handleNotifClick(notifId) {
            const targetId = parseInt(notifId, 10) || 0;
            if (targetId <= 0) return;
            const notifications = readNotifications();
            let noticeCode = '';
            let statusType = '';
            let changed = false;
            notifications.forEach(function(n) {
                if ((parseInt(n.id, 10) || 0) === targetId) {
                    noticeCode = ((n.trackingId || '') + '').trim();
                    statusType = (n.statusType === 'returned' ? 'returned' : 'delivered');
                    if (!n.read) {
                        n.read = true;
                        changed = true;
                    }
                }
            });
            if (changed) {
                writeNotifications(notifications);
                loadNotifications();
            }

            // Ensure filters do not hide the target row before focusing.
            const searchInput = document.getElementById('tableSearchInput');
            if (searchInput) {
                searchInput.value = '';
            }
            const yearSelect = document.getElementById('tableSortYear');
            if (yearSelect) {
                yearSelect.value = 'all';
            }
            if (typeof filterTableRows === 'function') {
                filterTableRows();
            }

            closeNotifModal();

            if (noticeCode) {
                navigateToNoticeRow(noticeCode, statusType);
            }
        }

        function navigateToNoticeRow(noticeCode, statusType) {
            const safeNotice = (noticeCode || '').trim();
            if (!safeNotice) return;

            const row = document.querySelector('tr[data-notice="' + CSS.escape(safeNotice) + '"]');
            if (row) {
                row.scrollIntoView({ behavior: 'smooth', block: 'center', inline: 'nearest' });
                var focusClass = statusType === 'returned' ? 'notif-row-focus-returned' : (statusType === 'delivered' ? 'notif-row-focus-delivered' : 'scanned-row-focus');
                row.classList.add(focusClass);
                setTimeout(function() {
                    row.classList.remove('notif-row-focus-delivered', 'notif-row-focus-returned', 'scanned-row-focus');
                }, 2600);
                return;
            }

            // If row is not currently in DOM, refresh then focus using existing flow.
            sessionStorage.setItem('dhsud_focus_notice', safeNotice);
            if (typeof refreshHomeData === 'function') {
                refreshHomeData({ focusNotice: safeNotice });
            }
        }

        function handleNotifAction(notifId) {
            const targetId = parseInt(notifId, 10) || 0;
            if (targetId <= 0) return;
            activeNotifActionId = (activeNotifActionId === targetId) ? 0 : targetId;
            loadNotifications();
        }

        function deleteNotification(notifId) {
            const targetId = parseInt(notifId, 10) || 0;
            if (targetId <= 0) return;
            const notifications = readNotifications();
            const nextNotifications = notifications.filter(function(n) {
                return (parseInt(n.id, 10) || 0) !== targetId;
            });
            activeNotifActionId = 0;
            writeNotifications(nextNotifications);
            loadNotifications();
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Initialize notification button click handler
        document.addEventListener('DOMContentLoaded', function() {
            const notifBtn = document.getElementById('tableNotifBtn');
            if (notifBtn) {
                notifBtn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    openNotifModal();
                });
            }

            window.addEventListener('resize', function() {
                const overlay = document.getElementById('notifModalOverlay');
                if (overlay && overlay.classList.contains('show')) {
                    positionNotifModal();
                }
            });

            window.addEventListener('scroll', function() {
                const overlay = document.getElementById('notifModalOverlay');
                if (overlay && overlay.classList.contains('show')) {
                    positionNotifModal();
                }
            }, true);

            // Close modal when clicking outside
            const overlay = document.getElementById('notifModalOverlay');
            if (overlay) {
                overlay.addEventListener('click', function(e) {
                    if (e.target === overlay) {
                        activeNotifActionId = 0;
                        closeNotifModal();
                    }
                });
            }

            // Close modal on Escape key
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    const overlay = document.getElementById('notifModalOverlay');
                    if (overlay && overlay.classList.contains('show')) {
                        activeNotifActionId = 0;
                        closeNotifModal();
                    }
                }
            });

            document.addEventListener('click', function() {
                if (activeNotifActionId !== 0) {
                    activeNotifActionId = 0;
                    loadNotifications();
                }
            });

            // Load initial notification count
            loadNotifications();
        });

        </script>
    </div>
</body>
</html>


