<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth.php';

// Require login to access this page
requireLogin();

$departmentConfig = [
    'emes' => ['code' => 'EMES', 'sender' => getDepartmentSenderTag('emes')],
    'prls' => ['code' => 'PRLS', 'sender' => getDepartmentSenderTag('prls')],
    'afd' => ['code' => 'AFD', 'sender' => getDepartmentSenderTag('afd')],
    'phsd' => ['code' => 'PHSD', 'sender' => getDepartmentSenderTag('phsd')],
    'elupd' => ['code' => 'ELUPD', 'sender' => getDepartmentSenderTag('elupd')],
    'ord' => ['code' => 'ORD', 'sender' => getDepartmentSenderTag('ord')],
    'hoa' => ['code' => 'HOA CDD', 'sender' => getDepartmentSenderTag('hoa')],
    'lo' => ['code' => 'LO', 'sender' => getDepartmentSenderTag('lo')],
];
$currentDept = normalizeDepartmentKey($_GET['dept'] ?? 'emes');
$currentDeptCode = $departmentConfig[$currentDept]['code'];
$currentDeptSenderTag = $departmentConfig[$currentDept]['sender'];
$currentDeptContactNo = getSenderContactNumber($currentDept, $currentDeptSenderTag);

// Handle status update when submitted per-row
$message = '';
$updatedNotice = '';
$updatedStatus = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['row_id']) && isset($_POST['status'])) {
    requireCsrfToken();
    $rowId = (int)($_POST['row_id'] ?? 0);
    $status = trim($_POST['status']);
    $isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest';
    if ($rowId <= 0) {
        $message = 'Missing record id.';
    } elseif ($status === '') {
        // placeholder or empty selection — don't save
        $message = 'No status selected.';
    } else {
        try {
            $sql = 'UPDATE mailtracking SET `Status` = :status WHERE `id` = :row_id';
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':status' => $status, ':row_id' => $rowId]);
            // track which row was updated so we can show a per-row message in the UI
            $updatedNotice = '';
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
    $departmentScope = buildMailtrackingDepartmentScope($currentDept);
    // Keep rows from the same batch adjacent so merged-cell rendering stays coherent,
    // including records recovered from archive.
    $rowsStmt = $pdo->prepare(
        'SELECT * FROM mailtracking
         WHERE ' . $departmentScope['sql'] . '
         ORDER BY `Sender Details` ASC, `id` ASC'
    );
    $rowsStmt->execute($departmentScope['params']);
    $rows = $rowsStmt->fetchAll();
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

function sanitizeTransmittalFolderName($value) {
    $name = trim((string)$value);
    if ($name === '') return 'UNASSIGNED';
    $name = preg_replace('/[\\\\\/:*?"<>|]+/', '_', $name);
    $name = preg_replace('/\s+/', ' ', $name);
    $name = trim($name, " .\t\n\r\0\x0B");
    return ($name !== '' ? $name : 'UNASSIGNED');
}

function buildDefaultPdfFileName($dateReleasedValue, $parcelNoValue) {
    global $currentDeptCode;
    $text = trim((string)$dateReleasedValue);
    if ($text === '' || $text === '0000-00-00' || $text === '0000-00-00 00:00:00') return '';
    $ts = strtotime($text);
    if ($ts === false) return '';
    $formattedDate = date('ymd', $ts);
    $formattedParcelNo = sprintf('%03d', (int)$parcelNoValue);
    return strtoupper($currentDeptCode) . '-' . $formattedDate . '-' . $formattedParcelNo;
}

function buildMainPdfHref($trackingValue, $transmittalIdValue) {
    global $currentDeptCode;
    $tracking = trim((string)$trackingValue);
    if ($tracking === '') return '';
    $transmittalFolder = sanitizeTransmittalFolderName($transmittalIdValue ?? '');
    return '../JRS_PDFs/' . rawurlencode($currentDeptCode) . '/' . rawurlencode($transmittalFolder) . '/' . rawurlencode('proof_' . $tracking . '.pdf');
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
// In batch mode, every column except Notice/Order Code and Parcel Details
// is merged into a single cell across the batch.
$mergeSkip = [];
$mergeRowspan = [];
$mergeColumns = array_values(array_filter($columns, function($c) {
    return $c !== 'Notice/Order Code' && $c !== 'Parcel Details';
}));
$mergeColumns[] = '__ACTION__';

$rowCount = count($rows);
for ($ri = 0; $ri < $rowCount; $ri++) {
    $batchId = extractBatchIdFromSenderDetails($rows[$ri]['Sender Details'] ?? '');
    if ($batchId === '' || (($batchIdCounts[$batchId] ?? 0) <= 1)) {
        continue;
    }
    $prevBatchId = '';
    if ($ri > 0) {
        $prevBatchId = extractBatchIdFromSenderDetails($rows[$ri - 1]['Sender Details'] ?? '');
    }
    if ($prevBatchId === $batchId) {
        continue;
    }
    $span = 1;
    for ($rj = $ri + 1; $rj < $rowCount; $rj++) {
        $nextBatchId = extractBatchIdFromSenderDetails($rows[$rj]['Sender Details'] ?? '');
        if ($nextBatchId !== $batchId) {
            break;
        }
        $span++;
    }
    if ($span <= 1) {
        continue;
    }
    foreach ($mergeColumns as $colName) {
        $mergeRowspan[$ri][$colName] = $span;
        for ($rj = $ri + 1; $rj < $ri + $span; $rj++) {
            $mergeSkip[$rj][$colName] = true;
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
    <link rel="stylesheet" href="../main.css?v=<?= urlencode((string)@filemtime(__DIR__ . '/../main.css')) ?>">
    <style>
        .batch-toggle-btn {
            border: none;
            background: transparent;
            color: #22336A;
            font-size: 0.9rem;
            font-weight: 700;
            line-height: 1;
            cursor: pointer;
            padding: 0 2px;
            margin-left: 2px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 20px;
            height: 20px;
            margin-left: 4px;
            flex: 0 0 auto;
        }
        .batch-toggle-btn .batch-icon {
            width: 18px;
            height: 18px;
            display: block;
            position: static !important;
            top: auto !important;
            right: auto !important;
        }
        .batch-toggle-btn:focus-visible {
            outline: 2px solid #22336A;
            outline-offset: 1px;
            border-radius: 2px;
        }
        .tracking-table tbody tr.batch-row td.notice-code-cell,
        .tracking-table tbody tr.batch-row td.parcel-details-cell {
            border-bottom: 1px solid #000 !important;
        }
        .tracking-table tbody tr.batch-row.batch-end-row td.notice-code-cell,
        .tracking-table tbody tr.batch-row.batch-end-row td.parcel-details-cell {
            border-bottom: 3px solid #000 !important;
        }
        .tracking-table tbody tr.batch-collapsed-head td.notice-code-cell,
        .tracking-table tbody tr.batch-collapsed-head td.parcel-details-cell {
            border-bottom: 3px solid #000 !important;
        }
        .tracking-table td.notice-code-cell {
            white-space: normal !important;
            word-break: normal !important;
            overflow-wrap: break-word !important;
            min-width: 170px;
        }
        .tracking-table td.notice-code-cell > div {
            display: flex;
            align-items: flex-start;
            width: 100%;
            min-width: 0;
        }
        .tracking-table td.notice-code-cell > div > span {
            display: block;
            flex: 1 1 auto;
            min-width: 0;
            white-space: normal;
            word-break: normal;
            overflow-wrap: break-word;
            hyphens: auto;
        }
        /* Keep other data columns from overflowing on long values. */
        .tracking-table td[data-col]:not(.notice-code-cell) {
            white-space: pre-line;
            word-break: break-word;
            overflow-wrap: anywhere;
        }

    </style>
</head>

<body class="admin-home-bg dept-theme-<?= htmlspecialchars($currentDept) ?>">
    <div class="admin-home-header">
        <div class="admin-home-header-main">
            <div class="sidebar-trigger-wrap">
                <button type="button" id="homeSidebarTrigger" class="sidebar-menu-trigger" aria-label="Open sidebar" title="Menu" aria-controls="homeSidebar" aria-expanded="false">
                    <img src="../assets/Sidebar_Menu_Icon.svg" alt="" aria-hidden="true">
                </button>
            </div>
            <img src="../assets/DHSUD_Header.webp" alt="Admin Home Header" class="admin-home-header-img">
        </div>
        <div class="admin-home-header-border"></div>
    </div>

    <div id="homeSidebarOverlay" class="home-sidebar-overlay" hidden></div>
    <aside id="homeSidebar" class="home-sidebar" aria-hidden="true">
        <div class="home-sidebar-head">
            <div class="home-sidebar-user">
                <img src="../assets/User_Icon.svg" alt="" aria-hidden="true" class="home-sidebar-user-icon">
                <span>Welcome, Admin!</span>
            </div>
            <button type="button" id="homeSidebarClose" class="home-sidebar-close" aria-label="Close sidebar">&times;</button>
        </div>

        <nav class="home-sidebar-nav" aria-label="Department menu">
            <a href="Home_Page.php?dept=emes" class="home-sidebar-link dept-emes<?= $currentDept === 'emes' ? ' is-active' : '' ?>" data-dept="emes"><img src="../assets/Department_File_Icon.svg" alt="" aria-hidden="true"><span class="home-sidebar-link-text"><strong>EMES</strong><small>Mail Tracking Records</small></span></a>
            <a href="Home_Page.php?dept=prls" class="home-sidebar-link dept-prls<?= $currentDept === 'prls' ? ' is-active' : '' ?>" data-dept="prls"><img src="../assets/Department_File_Icon.svg" alt="" aria-hidden="true"><span class="home-sidebar-link-text"><strong>PRLS</strong><small>Mail Tracking Records</small></span></a>
            <a href="Home_Page.php?dept=afd" class="home-sidebar-link dept-afd<?= $currentDept === 'afd' ? ' is-active' : '' ?>" data-dept="afd"><img src="../assets/Department_File_Icon.svg" alt="" aria-hidden="true"><span class="home-sidebar-link-text"><strong>AFD</strong><small>Mail Tracking Records</small></span></a>
            <a href="Home_Page.php?dept=phsd" class="home-sidebar-link dept-phsd<?= $currentDept === 'phsd' ? ' is-active' : '' ?>" data-dept="phsd"><img src="../assets/Department_File_Icon.svg" alt="" aria-hidden="true"><span class="home-sidebar-link-text"><strong>PHSD</strong><small>Mail Tracking Records</small></span></a>
            <a href="Home_Page.php?dept=elupd" class="home-sidebar-link dept-elupd<?= $currentDept === 'elupd' ? ' is-active' : '' ?>" data-dept="elupd"><img src="../assets/Department_File_Icon.svg" alt="" aria-hidden="true"><span class="home-sidebar-link-text"><strong>ELUPD</strong><small>Mail Tracking Records</small></span></a>
            <a href="Home_Page.php?dept=ord" class="home-sidebar-link dept-ord<?= $currentDept === 'ord' ? ' is-active' : '' ?>" data-dept="ord"><img src="../assets/Department_File_Icon.svg" alt="" aria-hidden="true"><span class="home-sidebar-link-text"><strong>ORD</strong><small>Mail Tracking Records</small></span></a>
            <a href="Home_Page.php?dept=hoa" class="home-sidebar-link dept-hoa<?= $currentDept === 'hoa' ? ' is-active' : '' ?>" data-dept="hoa"><img src="../assets/Department_File_Icon.svg" alt="" aria-hidden="true"><span class="home-sidebar-link-text"><strong>HOA CDD</strong><small>Mail Tracking Records</small></span></a>
            <a href="Home_Page.php?dept=lo" class="home-sidebar-link dept-lo<?= $currentDept === 'lo' ? ' is-active' : '' ?>" data-dept="lo"><img src="../assets/Department_File_Icon.svg" alt="" aria-hidden="true"><span class="home-sidebar-link-text"><strong>LO</strong><small>Mail Tracking Records</small></span></a>
        </nav>

        <a href="logout.php" class="home-sidebar-logout">
            <img src="../assets/Logout_Icon.svg" alt="" aria-hidden="true">
            <span>Logout</span>
        </a>
        <button type="button" class="home-sidebar-update" id="appUpdateBtn">
            <img src="../assets/Download_Icon.svg" alt="" aria-hidden="true">
            <span>Update App</span>
        </button>
    </aside>
        <div id="addModalOverlay" class="edit-modal-overlay" data-add-mode="default" style="display:none;">
            <div class="edit-modal add-modal-scrollable" id="addModal">
<button class="modal-close" onclick="closeAddModal()" title="Close">&times;</button>
                <h2 id="addModalTitle">ADD RECORD</h2>
                <form id="addForm" action="../api/Add.php" method="post" autocomplete="off">
                    <input type="hidden" name="department_id" value="<?= htmlspecialchars($currentDept) ?>">
                    <input type="hidden" name="transmittal_id" id="addTransmittalId">
                    <input type="hidden" name="batch_id" id="addBatchId">
                    <input type="hidden" name="batch_source_row_id" id="addBatchSourceRowId">
                    <input type="hidden" name="status" id="addStatus">
                    <input type="hidden" name="transmittalRemarks" id="addTransmittalRemarks">
                    <input type="hidden" name="eventDate" id="addEventDate">
                    <input type="hidden" name="evaluator" id="addEvaluator">

                    <div style="display:contents">
                        <div style="grid-column:1/span 2;" class="add-field add-field-pairs">
                            <div id="addPairRows" class="add-pairs-grid">
                                <div class="add-pair-row">
                                    <div>
                                        <label>Code</label>
                                        <input type="text" name="noticeCodes[]" placeholder="Notice/Order Code">
                                    </div>
                                    <div>
                                        <label>Parcel Details</label>
                                        <textarea name="parcelDetailsList[]" rows="1" placeholder="Parcel Details"></textarea>
                                    </div>
                                    <button type="button" class="pair-row-btn" title="Add row">+</button>
                                </div>
                            </div>
                        </div>
<script>
                            // Dynamic Notice/Order Code fields in Add Modal
                            document.addEventListener('DOMContentLoaded', function() {
                                const fieldsContainer = document.getElementById('noticeCodeFields');
                                if (!fieldsContainer) return;
                                let noticeCodeCount = 1;
                                function addNoticeCodeField() {
                                    const idx = noticeCodeCount;
                                    const row = document.createElement('div');
                                    row.className = 'notice-code-row';
                                    row.style.marginBottom = '4px';
                                    row.innerHTML = `
                                        <label for="addNoticeCode_${idx}" style="flex:0 0 90px;"></label>
                                        <input type="text" name="notice_Code[]" id="addNoticeCode_${idx}" style="flex:1;" />
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
                                const addBtn = fieldsContainer.querySelector('.add-notice-btn');
                                if (!addBtn) return;
                                addBtn.addEventListener('click', function() {
                                    addNoticeCodeField();
                                });
                            });
                            </script>
                        <div class="add-field add-field-date">
                            <label for="addDateAfd">Date Released to AFD*</label>
                            <input type="date" name="dateReleased" id="addDateAfd" required>
                        </div>
                        <div class="add-field add-field-parcel">
                            <label for="addParcelNo">Parcel No.</label>
                            <input type="number" name="parcelNo" id="addParcelNo" readonly>
                        </div>
                        <div style="grid-column:1/span 2;" class="add-field add-field-tracking">
                            <label for="addTrackingNo">Tracking No.</label>
                            <input type="text" name="trackingNo" id="addTrackingNo">
                        </div>
                        <div style="grid-column:1/span 2;" class="add-field add-field-recipient">
                            <label for="addRecipient">Recipient Details</label>
                            <textarea name="recipientDetails" rows="2" id="addRecipient"></textarea>
                        </div>
                        <div style="grid-column:1/span 2;" class="add-field add-field-sender">
                            <label for="addSender">Sender Details</label>
                            <textarea name="senderDetails" rows="2" id="addSender" placeholder="Optional additional sender details"></textarea>
                        </div>
                        <div style="grid-column:1/span 2;" class="add-field add-field-file">
                            <label for="addFileName">File Name (PDF)</label>
                            <input type="text" name="fileName" id="addFileName">
                        </div>
                    </div>
                    <div class="modal-actions">
                        <button type="submit" class="modal-btn save" id="addModalSubmit">Add Record</button>
                        <button type="button" class="modal-btn cancel" onclick="clearAddForm()">Clear Form</button>
                    </div>
                </form>
            </div>
        </div>
    <div class="admin-table-container">
        <div class="top-bar">
        <div class="top-bar-left">
            <button class="transmittal-btn" aria-pressed="true" aria-label="Table Module">
                <img src="../assets/table.svg" alt="" class="transmittal-table-logo" aria-hidden="true">
                <span class="module-btn-label">Table</span>
            </button>
            <button type="button" class="transmittal-back-btn" id="transmittalBackToListBtn" aria-label="Transmittal Module">
                <img src="../assets/Folder.svg" alt="" class="transmittal-back-logo" aria-hidden="true">
                <span class="module-btn-label">Transmittal</span>
            </button>
        </div>
        <div class="top-bar-title"><?= htmlspecialchars($currentDeptCode) ?> MAIL TRACKING RECORDS</div>
        <div class="top-bar-right-main">
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
            ?>
            <div class="table-sort-bar top-main-table-controls">
                <div class="table-controls-group">
                    <div class="table-year-month-filter" id="tableYearMonthFilter">
                        <button type="button" id="tableSortYearTrigger" class="table-year-trigger" aria-haspopup="true" aria-expanded="false">
                            <span id="tableSortYearLabel">Year</span>
                            <span class="table-year-trigger-icon" aria-hidden="true"></span>
                        </button>
                        <div id="tableYearMonthDropdown" class="table-year-month-dropdown" hidden>
                            <div id="tableYearList" class="table-year-list" role="listbox" aria-label="Year options">
                                <button type="button" class="table-year-option is-active" data-year="all">All</button>
                                <?php foreach ($years as $year): ?>
                                    <button type="button" class="table-year-option" data-year="<?= htmlspecialchars($year) ?>"><?= htmlspecialchars($year) ?></button>
                                <?php endforeach; ?>
                            </div>
                            <div id="tableMonthGrid" class="table-month-grid" aria-label="Month options" aria-hidden="true">
                                <button type="button" class="table-month-option" data-month="01">Jan</button>
                                <button type="button" class="table-month-option" data-month="02">Feb</button>
                                <button type="button" class="table-month-option" data-month="03">Mar</button>
                                <button type="button" class="table-month-option" data-month="04">Apr</button>
                                <button type="button" class="table-month-option" data-month="05">May</button>
                                <button type="button" class="table-month-option" data-month="06">Jun</button>
                                <button type="button" class="table-month-option" data-month="07">Jul</button>
                                <button type="button" class="table-month-option" data-month="08">Aug</button>
                                <button type="button" class="table-month-option" data-month="09">Sep</button>
                                <button type="button" class="table-month-option" data-month="10">Oct</button>
                                <button type="button" class="table-month-option" data-month="11">Nov</button>
                                <button type="button" class="table-month-option" data-month="12">Dec</button>
                            </div>
                        </div>
                        <select id="tableSortYear" class="table-sort-select table-sort-select-native" required style="min-width:65px;">
                            <option value="" disabled hidden>Year</option>
                            <option value="all" selected>All</option>
                            <?php
                            foreach ($years as $year) {
                                echo '<option value="' . htmlspecialchars($year) . '">' . htmlspecialchars($year) . '</option>';
                            }
                            ?>
                        </select>
                        <input type="hidden" id="tableSortMonth" value="">
                    </div>
                    <div class="table-search-wrap">
                        <input type="text" id="tableSearchInput" class="table-search-input" placeholder="Search">
                        <button class="table-search-btn" id="tableSearchBtn" aria-label="Search">
                            <img src="../assets/Search Icon.svg" alt="">
                        </button>
                    </div>
                </div>
                <button class="table-notif-btn" id="tableNotifBtn" title="Tracking Status Notifications">
                    <img src="../assets/Notif_Icon.svg" alt="Notifications">
                    <span class="notif-badge" id="notifBadge" style="display: none;">0</span>
                </button>
            </div>
            <div class="transmittal-top-filter">
                <div class="transmittal-year-month-filter" id="transmittalYearMonthFilter">
                    <button type="button" id="transmittalSortYearTrigger" class="transmittal-year-trigger" aria-haspopup="true" aria-expanded="false">
                        <span id="transmittalSortYearLabel">Year</span>
                        <span class="transmittal-year-trigger-icon" aria-hidden="true"></span>
                    </button>
                    <div id="transmittalYearMonthDropdown" class="transmittal-year-month-dropdown" hidden>
                        <div id="transmittalYearList" class="transmittal-year-list" role="listbox" aria-label="Transmittal year options">
                            <button type="button" class="transmittal-year-option is-active" data-year="all">All</button>
                        </div>
                        <div id="transmittalMonthGrid" class="transmittal-month-grid" aria-label="Transmittal month options" aria-hidden="true">
                            <button type="button" class="transmittal-month-option" data-month="01">Jan</button>
                            <button type="button" class="transmittal-month-option" data-month="02">Feb</button>
                            <button type="button" class="transmittal-month-option" data-month="03">Mar</button>
                            <button type="button" class="transmittal-month-option" data-month="04">Apr</button>
                            <button type="button" class="transmittal-month-option" data-month="05">May</button>
                            <button type="button" class="transmittal-month-option" data-month="06">Jun</button>
                            <button type="button" class="transmittal-month-option" data-month="07">Jul</button>
                            <button type="button" class="transmittal-month-option" data-month="08">Aug</button>
                            <button type="button" class="transmittal-month-option" data-month="09">Sep</button>
                            <button type="button" class="transmittal-month-option" data-month="10">Oct</button>
                            <button type="button" class="transmittal-month-option" data-month="11">Nov</button>
                            <button type="button" class="transmittal-month-option" data-month="12">Dec</button>
                        </div>
                    </div>
                    <select id="transmittalSortYear" class="table-sort-select table-sort-select-native" required style="min-width:65px;">
                        <option value="" disabled hidden>Year</option>
                        <option value="all" selected>All</option>
                    </select>
                    <input type="hidden" id="transmittalSortMonth" value="">
                </div>
            </div>
        </div>
    <script>
        const currentDeptCode = <?= json_encode($currentDeptCode, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
        const currentDeptKey = <?= json_encode($currentDept, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;

            let scannerSelectedNoticeCode = '';
            let scannerSelectedRowId = 0;
            let activeTransmittalId = '';
            const pendingTransmittals = new Set();

            function seedPendingRecoveredTransmittalsFromUrl() {
                let url;
                try {
                    url = new URL(window.location.href);
                } catch (e) {
                    return;
                }

                const raw = ((url.searchParams.get('recovered_transmittals') || '') + '').trim();
                if (!raw) return;
                raw.split(',').forEach(function(id) {
                    const tid = ((id || '') + '').trim();
                    if (tid) pendingTransmittals.add(tid);
                });
            }

            seedPendingRecoveredTransmittalsFromUrl();

            function openScannerModal(noticeCode, rowId) {
                const modal = document.getElementById('scannerModal');
                const frame = document.getElementById('scannerFrame');
                if (!modal || !frame) return;
                document.body.classList.add('scanner-modal-open');
                scannerSelectedNoticeCode = (noticeCode || '').trim();
                scannerSelectedRowId = parseInt(rowId || '0', 10) || 0;
                if (scannerSelectedRowId <= 0) return;
                frame.onload = function() {
                    try { frame.focus(); } catch (e) {}
                    try { if (frame.contentWindow) frame.contentWindow.focus(); } catch (e) {}
                };
                frame.src = '../test.php?row_id=' + encodeURIComponent(String(scannerSelectedRowId)) + '&code=' + encodeURIComponent(scannerSelectedNoticeCode) + '&embedded=1';
                modal.style.display = 'flex';
                animateModalPanelFromTrigger(modal, modal.querySelector('.modal-panel'), document.activeElement);
                setTimeout(function() {
                    try { frame.focus(); } catch (e) {}
                    try { if (frame.contentWindow) frame.contentWindow.focus(); } catch (e) {}
                }, 120);
            }

            function closeScannerModal() {
                const modal = document.getElementById('scannerModal');
                const frame = document.getElementById('scannerFrame');
                if (!modal || !frame) return;
                modal.style.display = 'none';
                frame.src = 'about:blank';
                scannerSelectedNoticeCode = '';
                scannerSelectedRowId = 0;
                document.body.classList.remove('scanner-modal-open');
            }

            let lastPdfViewerFocus = null;
            let lastOngoingDeliveryFocus = null;
            let exportPdfBlobUrl = '';
            let pdfPreviewRowIds = [];
            let pdfPreviewTransmittalName = '';
            let pdfPreviewTitle = '';

            function normalizePdfFileName(rawName, fallbackName) {
                let name = ((rawName || '') + '').trim();
                if (!name) {
                    name = ((fallbackName || '') + '').trim() || 'DHSUD_Report';
                }
                name = name.replace(/[\\\/:*?"<>|]+/g, '_').replace(/\s+/g, ' ').trim().replace(/^[. ]+|[. ]+$/g, '');
                if (!name) name = 'DHSUD_Report';
                if (!/\.pdf$/i.test(name)) name += '.pdf';
                return name;
            }

            function parseFilenameFromContentDisposition(value) {
                const raw = ((value || '') + '').trim();
                if (!raw) return '';
                const utf8Match = /filename\*=UTF-8''([^;]+)/i.exec(raw);
                if (utf8Match && utf8Match[1]) {
                    try { return decodeURIComponent(utf8Match[1]); } catch (e) {}
                }
                const quotedMatch = /filename="([^"]+)"/i.exec(raw);
                if (quotedMatch && quotedMatch[1]) return quotedMatch[1];
                const plainMatch = /filename=([^;]+)/i.exec(raw);
                if (plainMatch && plainMatch[1]) return plainMatch[1].trim();
                return '';
            }

            function setPdfViewerDownloadTarget(url, fileName) {
                const btn = document.getElementById('pdfViewerDownloadBtn');
                if (!btn) return;
                const safeUrl = ((url || '') + '').trim();
                if (!safeUrl) {
                    btn.removeAttribute('href');
                    btn.removeAttribute('download');
                    btn.setAttribute('aria-disabled', 'true');
                    btn.classList.add('is-disabled');
                    return;
                }
                btn.href = safeUrl;
                btn.download = normalizePdfFileName(fileName, 'DHSUD_Report');
                btn.setAttribute('aria-disabled', 'false');
                btn.classList.remove('is-disabled');
            }

            function restoreFocus(target) {
                if (target && typeof target.focus === 'function' && document.contains(target)) {
                    try { target.focus(); return true; } catch (e) {}
                }
                return false;
            }

            function blurIfInside(modal) {
                if (!modal) return;
                const active = document.activeElement;
                if (active && modal.contains(active)) {
                    try { active.blur(); } catch (e) {}
                }
            }

            function animateModalPanelFromTrigger(overlayEl, panelEl, triggerEl) {
                if (!overlayEl || !panelEl) return;
                const trigger = (triggerEl && typeof triggerEl.getBoundingClientRect === 'function')
                    ? triggerEl
                    : (document.activeElement && typeof document.activeElement.getBoundingClientRect === 'function' ? document.activeElement : null);
                const triggerRect = trigger ? trigger.getBoundingClientRect() : null;

                requestAnimationFrame(function() {
                    const panelRect = panelEl.getBoundingClientRect();
                    let originX = panelRect.width / 2;
                    let originY = Math.min(56, panelRect.height / 2);

                    if (triggerRect) {
                        const cx = triggerRect.left + (triggerRect.width / 2);
                        const cy = triggerRect.top + (triggerRect.height / 2);
                        originX = cx - panelRect.left;
                        originY = cy - panelRect.top;
                    }

                    originX = Math.max(24, Math.min(panelRect.width - 24, originX));
                    originY = Math.max(20, Math.min(panelRect.height - 20, originY));
                    panelEl.style.setProperty('--modal-origin-x', originX + 'px');
                    panelEl.style.setProperty('--modal-origin-y', originY + 'px');
                    panelEl.classList.remove('modal-pop-enter');
                    void panelEl.offsetWidth;
                    panelEl.classList.add('modal-pop-enter');
                });
            }

            function openPdfViewerModal(pdfUrl, pdfTitle, triggerEl) {
                const modal = document.getElementById('pdfViewerModal');
                const frame = document.getElementById('pdfViewerFrame');
                const title = document.getElementById('pdfViewerTitle');
                if (!modal || !frame || !title || !pdfUrl) return;
                lastPdfViewerFocus = document.activeElement;
                const cleanUrl = String(pdfUrl).split('#')[0];
                const fitUrl = cleanUrl + '#zoom=95';
                frame.src = 'about:blank';
                frame.src = fitUrl;
                title.textContent = (pdfTitle || 'PDF Preview');
                setPdfViewerDownloadTarget(cleanUrl, pdfTitle || 'PDF Preview');
                modal.style.display = 'flex';
                modal.setAttribute('aria-hidden', 'false');
                modal.removeAttribute('inert');
                animateModalPanelFromTrigger(modal, modal.querySelector('.pdf-viewer-panel'), triggerEl);
                const closeBtn = modal.querySelector('.pdf-viewer-close');
                if (closeBtn) {
                    try { closeBtn.focus(); } catch (e) {}
                }
            }

            function closePdfViewerModal() {
                const modal = document.getElementById('pdfViewerModal');
                const frame = document.getElementById('pdfViewerFrame');
                const preparedInput = document.getElementById('pdfPreparedByInput');
                if (!modal || !frame) return;
                if (modal.contains(document.activeElement)) {
                    if (!restoreFocus(lastPdfViewerFocus)) {
                        blurIfInside(modal);
                    }
                }
                modal.style.display = 'none';
                modal.setAttribute('aria-hidden', 'true');
                modal.setAttribute('inert', '');
                frame.src = 'about:blank';
                if (preparedInput) {
                    preparedInput.value = '';
                }
                if (exportPdfBlobUrl) {
                    try { URL.revokeObjectURL(exportPdfBlobUrl); } catch (e) {}
                    exportPdfBlobUrl = '';
                }
                setPdfViewerDownloadTarget('', '');
                lastPdfViewerFocus = null;
            }

            function openOngoingDeliveryModal(triggerEl) {
                const modal = document.getElementById('ongoingDeliveryModal');
                if (!modal) return;
                lastOngoingDeliveryFocus = document.activeElement;
                modal.style.display = 'flex';
                modal.setAttribute('aria-hidden', 'false');
                modal.removeAttribute('inert');
                animateModalPanelFromTrigger(modal, modal.querySelector('.ongoing-delivery-panel'), triggerEl);
                const closeBtn = modal.querySelector('.ongoing-delivery-close');
                if (closeBtn) {
                    try { closeBtn.focus(); } catch (e) {}
                }
            }

            function closeOngoingDeliveryModal() {
                const modal = document.getElementById('ongoingDeliveryModal');
                if (!modal) return;
                if (modal.contains(document.activeElement)) {
                    if (!restoreFocus(lastOngoingDeliveryFocus)) {
                        blurIfInside(modal);
                    }
                }
                modal.style.display = 'none';
                modal.setAttribute('aria-hidden', 'true');
                modal.setAttribute('inert', '');
                lastOngoingDeliveryFocus = null;
            }

            function resolveStatusFromLink(linkEl) {
                const row = linkEl ? linkEl.closest('tr[data-notice]') : null;
                if (!row) return '';

                function readStatusFromRow(tr) {
                    const cell = tr ? tr.querySelector('td[data-col="Status"]') : null;
                    return ((cell && cell.textContent) ? cell.textContent : '').trim().toUpperCase();
                }

                let status = readStatusFromRow(row);
                if (status) return status;

                const batchId = ((row.dataset.batchId || '') + '').trim();
                if (!batchId) return '';
                let probe = row.previousElementSibling;
                while (probe) {
                    if (((probe.dataset && probe.dataset.batchId) || '').trim() !== batchId) break;
                    status = readStatusFromRow(probe);
                    if (status) return status;
                    probe = probe.previousElementSibling;
                }
                return '';
            }

            document.addEventListener('click', function(event) {
                const link = event.target.closest('.pdf-link-in-cell[data-pdf-url]');
                if (link) {
                    event.preventDefault();
                    const statusText = resolveStatusFromLink(link);
                    if (statusText === 'ONGOING DELIVERY') {
                        openOngoingDeliveryModal(link);
                        return;
                    }
                    const pdfUrl = link.getAttribute('data-pdf-url') || link.getAttribute('href') || '';
                    const pdfTitle = link.getAttribute('data-pdf-title') || (link.textContent || '').trim();
                    if (pdfUrl) openPdfViewerModal(pdfUrl, pdfTitle, link);
                    return;
                }

                const pdfModal = document.getElementById('pdfViewerModal');
                if (pdfModal && event.target === pdfModal) {
                    closePdfViewerModal();
                }

                const ongoingModal = document.getElementById('ongoingDeliveryModal');
                if (ongoingModal && event.target === ongoingModal) {
                    closeOngoingDeliveryModal();
                }
            });

            document.addEventListener('keydown', function(event) {
                if (event.key === 'Escape') {
                    closePdfViewerModal();
                    closeOngoingDeliveryModal();
                }
            });

            </script>
        </div>

        <div id="transmittalManager" class="transmittal-manager" data-view="grid" style="display:none;">
            <div class="transmittal-grid" id="transmittalGrid"></div>
        </div>

        <div class="table-scroll-area">
                <div class="tracking-table-container">
                    <div class="transmittal-table-headbar" id="transmittalTableHeadbar">
                        <div class="transmittal-table-headbar-left">
                            <div class="export-dropdown">
                                <button type="button" class="transmittal-head-export-btn" id="exportDropdownBtn" aria-label="Export options" title="Export options">
                                    <img src="../assets/export.svg" alt="" class="transmittal-head-export-icon" aria-hidden="true">
                                </button>
                                <div class="export-dropdown-menu" id="exportDropdownMenu" role="menu" aria-label="Export options">
                                    <button type="button" class="export-dropdown-item" onclick="handleExportOption('pdf')" role="menuitem">Export Transmittal (PDF)</button>
                                    <button type="button" class="export-dropdown-item" onclick="handleExportOption('excel')" role="menuitem">Export as Excel</button>
                                </div>
                            </div>
                        </div>
                        <div class="transmittal-table-headbar-title" id="transmittalTableBarTitle"></div>
                        <div class="transmittal-table-headbar-right">
                            <button type="button" class="transmittal-head-nav-btn" id="transmittalPrevBtn" aria-label="Previous transmittal" title="Previous transmittal">
                                <span class="transmittal-head-nav-icon" aria-hidden="true">&#8249;</span>
                        </button>
                        <button type="button" class="transmittal-head-nav-btn" id="transmittalNextBtn" aria-label="Next transmittal" title="Next transmittal">
                            <span class="transmittal-head-nav-icon" aria-hidden="true">&#8250;</span>
                        </button>
                    </div>
                </div>
                <div class="tracking-table-scroll">
                    <table class="listview-table tracking-table">
                <thead>
                        <tr>
                            <th style="width:40px;">
                                <div class="checkbox-header-tools">
                                    <input type="checkbox" id="selectAllCheckbox" onclick="toggleAllCheckboxes(this)">
                                </div>
                            </th>
                            <?php foreach ($columns as $h): ?>
                                <th data-col="<?= htmlspecialchars($h) ?>"><?= htmlspecialchars($h) ?></th>
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
                                <tr class="<?= htmlspecialchars(implode(' ', $rowClasses)) ?>" data-id="<?= (int)($row['id'] ?? 0) ?>" data-batch-id="<?= htmlspecialchars($rowBatchId) ?>" data-transmittal-id="<?= htmlspecialchars($row['Transmittal ID'] ?? '') ?>" data-notice="<?= htmlspecialchars($row['Notice/Order Code'] ?? '') ?>" data-tracking-no="<?= htmlspecialchars($rowTrackingNo) ?>">
                                    <td style="width:32px;">
                                        <input type="checkbox" class="row-checkbox" value="<?= (int)($row['id'] ?? 0) ?>" data-notice="<?= htmlspecialchars($row['Notice/Order Code'] ?? '') ?>">
                                    </td>
                                    <td class="notice-code-cell has-cell-copy">
                                        <div style="display: flex; align-items: center; gap: 0.3em;">
                                            <div class="row-menu-container">
                                                <button class="row-menu-btn" type="button" tabindex="0" aria-label="Row menu" onclick="toggleRowMenu(event, <?= (int)($row['id'] ?? 0) ?>)">
                                                    <span style="font-size:1.5em;line-height:1;">&#8942;</span>
                                                </button>
                                            </div>
                                            <span class="cell-text">
                                                <?= htmlspecialchars($row['Notice/Order Code'] ?? '') ?>
                                            </span>
                                        </div>
                                        <button type="button" class="cell-copy-btn" aria-label="Copy cell">
                                            <img src="../assets/copy-svgrepo-com.svg" alt="">
                                        </button>
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
                                        <td class="status-cell<?= $spanToBatchEndClass ?> has-cell-copy" data-col="Status"<?= $rowspanAttr ?>>
                                            <span class="<?= $statusClass ?> cell-text"><?= htmlspecialchars($current) ?></span>
                                            <button type="button" class="cell-copy-btn" aria-label="Copy cell">
                                                <img src="../assets/copy-svgrepo-com.svg" alt="">
                                            </button>
                                        </td>
                                    <?php else: ?>
                                        <?php
                                            $cellValue = $row[$colName] ?? '';
                                            if ($colName === 'Date released to AFD' || $colName === 'Date') {
                                                $cellValue = formatDateCell($cellValue);
                                            }
                                            if ($colName === 'Sender Details') {
                                                $cellValue = preg_replace('/\R?Department ID:\s*[^\r\n]+/i', '', (string)$cellValue);
                                                $cellValue = preg_replace('/\R?Batch ID:\s*[A-Za-z0-9\-]+\s*/i', '', (string)$cellValue);
                                                $cellValue = trim((string)$cellValue);
                                            }
                                        ?>
                                        <?php if ($colName === 'File Name (PDF)'): ?>
                                            <?php
                                                $fileName = trim((string)$cellValue);
                                                $trackingValue = trim((string)($row['Tracking No.'] ?? $row['Tracking No'] ?? $row['tracking_no'] ?? $row['TrackingNo'] ?? ''));
                                                $defaultPdfName = buildDefaultPdfFileName($row['Date released to AFD'] ?? '', $row['Parcel No.'] ?? 0);
                                                $resolvedPdfName = $fileName !== '' ? basename($fileName) : $defaultPdfName;
                                                $proofAssetName = $trackingValue !== '' ? ('proof_' . $trackingValue . '.pdf') : '';
                                                $fileHref = buildMainPdfHref($trackingValue, $row['Transmittal ID'] ?? '');
                                                $linkLabel = $resolvedPdfName;
                                            ?>
                                            <td data-col="<?= htmlspecialchars($colName) ?>" class="pdf-link-cell<?= $spanToBatchEndClass ?>"<?= $rowspanAttr ?>>
                                                <?php if ($fileHref && $linkLabel !== ''): ?>
                                                    <a href="<?= htmlspecialchars($fileHref) ?>" data-pdf-url="<?= htmlspecialchars($fileHref) ?>" data-pdf-title="<?= htmlspecialchars($linkLabel, ENT_QUOTES) ?>" class="pdf-link-in-cell"><?= htmlspecialchars($linkLabel) ?></a>
                                                <?php endif; ?>
                                            </td>
                                        <?php else: ?>
                                        <td data-col="<?= htmlspecialchars($colName) ?>" class="<?= trim($spanToBatchEndClass) ?> has-cell-copy"<?= $rowspanAttr ?>>
                                            <span class="cell-text"><?= htmlspecialchars($cellValue) ?></span>
                                            <button type="button" class="cell-copy-btn" aria-label="Copy cell">
                                                <img src="../assets/copy-svgrepo-com.svg" alt="">
                                            </button>
                                        </td>
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
                                    <td class="action-cell <?= trim($actionSpanToBatchEndClass) ?>"<?= $actionRowspanAttr ?>>
                                        <?php if ($showBatchBadge): ?>
                                            <button type="button" class="batch-toggle-btn" title="Batch row" aria-label="Batch row" style="margin-bottom:4px;">
                                                <img src="../assets/Batch_Icon.svg" alt="Batch" class="batch-icon">
                                            </button>
                                        <?php endif; ?>
                                        <?php if (!empty($rowTrackingNo) && $rowTrackingNo !== '0' && strtoupper(trim($row['Status'] ?? '')) === 'ONGOING DELIVERY'): ?>
                                            <span style="font-size:0.72rem;color:#22336A;font-weight:700;">Auto Tracking</span>
                                            <div class="track-result"></div>
                                        <?php elseif (empty($rowTrackingNo) || $rowTrackingNo === '0'): ?>
                                            <button type="button" class="btn-scan" onclick="openScannerModal('<?= htmlspecialchars($row['Notice/Order Code'] ?? '', ENT_QUOTES) ?>', <?= (int)($row['id'] ?? 0) ?>)" style="display:inline-block;text-decoration:none;">Scan</button>
                                        <?php else: ?>
                                            <span class="tracking-present-note">Tracking recorded</span>
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
            <button id="rowMenuAddBatchBtn" class="row-menu-item" onclick="addBatchRowFromMenu()" style="display:none;align-items:center;gap:0.5em;padding:8px 18px;width:100%;background:none;border:none;cursor:pointer;color:#22336a;font-size:1em;font-weight:600;text-align:left;">
                <img src="../assets/Add_Icon.svg" alt="Add" style="width:20px;height:20px;"> Add Batch Item
            </button>
            <button class="row-menu-item" onclick="deleteRecordFromMenu()" style="display:flex;align-items:center;gap:0.5em;padding:8px 18px;width:100%;background:none;border:none;cursor:pointer;color:#22336a;font-size:1em;font-weight:600;text-align:left;">
                <img src="../assets/Delete_Icon.svg" alt="Delete" style="width:20px;height:20px;"> Delete
            </button>
        </div>
        <div id="scannerModal" class="scanner-modal" style="display:none;">
            <div class="modal-panel scanner-modal-panel">
                <div class="scanner-modal-head">
                    <div class="scanner-modal-title-wrap">
                        <span class="scanner-modal-kicker">Scanner</span>
                        <h2 class="scanner-modal-title">Scan QR Code</h2>
                    </div>
                    <button type="button" class="scanner-modal-close" onclick="closeScannerModal()">Close</button>
                </div>
                <div class="scanner-modal-frame-shell">
                    <iframe id="scannerFrame" src="about:blank" title="QR Scanner" allow="camera" class="scanner-modal-frame"></iframe>
                </div>
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
        <div id="excelPreviewModal" class="excel-preview-modal" aria-hidden="true" role="dialog" aria-modal="true">
            <div class="excel-preview-panel">
                <div class="excel-preview-head">
                    <h3 id="excelPreviewTitle" class="excel-preview-title">Excel Export Preview</h3>
                    <div class="excel-preview-actions">
                        <a id="excelPreviewDownloadBtn" class="excel-preview-download" onclick="confirmExcelExport()">Save Excel</a>
                    </div>
                    <button type="button" class="excel-preview-close" onclick="closeExcelPreview()" aria-label="Close">
                        <img class="exit-modal" src="../assets/icon.svg" alt="">
                    </button>
                </div>
                <div class="excel-preview-meta" id="excelPreviewMeta"></div>
                <div class="excel-preview-table-wrap">
                    <table class="excel-preview-table">
                        <thead id="excelPreviewHead"></thead>
                        <tbody id="excelPreviewBody"></tbody>
                    </table>
                </div>
            </div>
        </div>
        <div id="copyToast" class="copy-toast" aria-live="polite" aria-atomic="true">Copied</div>
        <?php $defaultOfficerName = getTransmittalOfficerName($currentDept); ?>
        <div id="pdfViewerModal" class="pdf-viewer-modal" aria-hidden="true" inert role="dialog" aria-modal="true">
            <div class="pdf-viewer-panel">
                <div class="pdf-viewer-head">
                    <h3 id="pdfViewerTitle" class="pdf-viewer-title">PDF Preview</h3>
                    <div class="pdf-viewer-prepared-group" role="group" aria-label="Prepared By">
                        <label class="pdf-viewer-prepared" for="pdfPreparedByInput">Prepared By</label>
                        <input id="pdfPreparedByInput" type="text" placeholder="<?= htmlspecialchars($defaultOfficerName) ?>" autocomplete="off">
                        <button type="button" class="pdf-viewer-apply" onclick="refreshPdfPreview()">Apply</button>
                    </div>
                    <div class="pdf-viewer-actions">
                        <a id="pdfViewerDownloadBtn" class="pdf-viewer-download is-disabled" aria-disabled="true">Save PDF</a>
                    </div>
                    <button type="button" class="pdf-viewer-close" onclick="closePdfViewerModal()"><img class="exit-modal" src="../assets/icon.svg" alt="Close"></button>
                </div>
                <iframe id="pdfViewerFrame" name="pdfViewerFrame" class="pdf-viewer-frame" src="about:blank" title="PDF Viewer"></iframe>
            </div>
        </div>

        <div id="ongoingDeliveryModal" class="ongoing-delivery-modal" aria-hidden="true" inert role="dialog" aria-modal="true">
            <div class="ongoing-delivery-panel">
                <div class="ongoing-delivery-title-bar">
                    <span class="ongoing-delivery-title-text">DHSUD</span>
                    <button type="button" class="ongoing-delivery-close" onclick="closeOngoingDeliveryModal()" aria-label="Close">×</button>
                </div>
                <div class="ongoing-delivery-content">
                    <span class="ongoing-delivery-text">Ongoing Delivery...</span>
                    <img src="../assets/Tracking_Icon.svg" alt="" class="ongoing-delivery-icon">
                </div>
                <div class="ongoing-delivery-bottom-bar"></div>
            </div>
        </div>

        <div id="rowDetailModal" class="row-detail-modal-overlay" hidden aria-hidden="true">
            <div class="row-detail-modal" role="dialog" aria-modal="true" aria-labelledby="rowDetailTitle">
                <div class="row-detail-header">
                    <h2 id="rowDetailTitle">Record Details</h2>
                    <button type="button" class="row-detail-close" aria-label="Close" onclick="closeRowDetailModal()">×</button>
                </div>
                <div class="row-detail-body" id="rowDetailBody"></div>
            </div>
        </div>

        <!-- Add New Record Modal (hidden by default) -->
        
        <div class="table-footer-section">
            <button class="add-btn" onclick="openAddModal()">Add</button>
            <a href="Archive_Page.php" class="archive-btn">Archive</a>
            <div class="statistics-section">
                <div class="statistics-title">Statistics</div>
                <div class="statistics-bar">
                    <button type="button" class="stat-box stat-rtos stat-filter-btn" data-status-filter="RETURNED TO SENDER" title="Show Returned to Sender">
                        Returned to Sender
                        <div class="stat-count"><?= $rts ?></div>
                    </button>
                    <button type="button" class="stat-box stat-ongoing stat-filter-btn" data-status-filter="ONGOING DELIVERY" title="Show Ongoing Delivery">
                        Ongoing Delivery
                        <div class="stat-count"><?= $ogd?></div>
                    </button>
                    <button type="button" class="stat-box stat-delivered stat-filter-btn" data-status-filter="DELIVERED" title="Show Delivered">
                        Delivered
                        <div class="stat-count"><?= $del ?></div>
                    </button>
                    <button type="button" class="stat-box stat-total stat-filter-btn" data-status-filter="ALL" title="Show All Statuses">
                        Total
                        <div class="stat-count"><?= (int)$totalCount ?></div>
                    </button>
                    <button type="button" class="stat-box stat-ndr stat-filter-btn" data-status-filter="NDR" title="Show Non-delivery statuses">
                        Non-delivery Rate
                        <div class="stat-count"><?= htmlspecialchars($ndrPercent) ?>%</div>
                    </button>
            </div>
        </div>
        </div>
<script>
            const CSRF_TOKEN = <?php echo json_encode(getCsrfToken(), JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT); ?>;
            (function cleanDeleteQueryParam() {
                var url = new URL(window.location.href);
                if (url.searchParams.has('deleted')) {
                    url.searchParams.delete('deleted');
                    window.history.replaceState({}, '', url.pathname + (url.search ? url.search : '') + url.hash);
                }
            })();

            var currentRowMenuId = 0;
            function getRowElementById(rowId) {
                var safeRowId = parseInt(rowId, 10) || 0;
                if (safeRowId <= 0) return null;
                return document.querySelector('tr[data-id="' + String(safeRowId) + '"]');
            }
            function isBatchRowElement(rowEl) {
                if (!rowEl) return false;
                if (rowEl.classList.contains('batch-row')) return true;
                var batchId = ((rowEl.dataset.batchId || '') + '').trim();
                return batchId !== '';
            }
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
                var addBatchBtn = document.getElementById('rowMenuAddBatchBtn');
                if (addBatchBtn) {
                    addBatchBtn.style.display = 'flex';
                }
                dropdown.style.display = 'block';
                var scrollArea = event.currentTarget.closest('.table-scroll-area') || document.querySelector('.admin-table-container .table-scroll-area');
                var rect = event.currentTarget.getBoundingClientRect();
                var ddRect = dropdown.getBoundingClientRect();
                var areaRect = scrollArea ? scrollArea.getBoundingClientRect() : { left: 8, top: 8, right: window.innerWidth - 8, bottom: window.innerHeight - 8 };
                var areaLeft = areaRect.left + 8;
                var areaRight = areaRect.right - 8;
                var areaTop = areaRect.top + 8;
                var areaBottom = areaRect.bottom - 8;

                var left = rect.left + ((rect.width - ddRect.width) / 2);
                var minLeft = areaLeft;
                var maxLeft = Math.max(minLeft, areaRight - ddRect.width);
                left = Math.max(minLeft, Math.min(left, maxLeft));

                var topBelow = rect.bottom + 6;
                var topAbove = rect.top - ddRect.height - 6;
                var top = topBelow;
                if (topBelow + ddRect.height > areaBottom) {
                    top = topAbove;
                }
                var minTop = areaTop;
                var maxTop = Math.max(minTop, areaBottom - ddRect.height);
                top = Math.max(minTop, Math.min(top, maxTop));

                dropdown.style.left = left + 'px';
                dropdown.style.top = top + 'px';
            }
            function deleteRecordFromMenu() {
                var rowId = currentRowMenuId;
                hideRowMenuDropdown();
                if (rowId > 0) {
                    deleteRecord(rowId);
                }
            }
            function addBatchRowFromMenu() {
                var rowId = currentRowMenuId;
                hideRowMenuDropdown();
                if (rowId > 0) {
                    openAddModal({ mode: 'batch', rowId: rowId });
                }
            }
            function deleteRecord(rowId) {
                if (confirm('Are you sure you want to delete this record?')) {
                   var safeRowId = parseInt(rowId, 10) || 0;
                   if (safeRowId <= 0) return;
                        fetch('../api/Delete.php', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/x-www-form-urlencoded',
                                'X-CSRF-Token': CSRF_TOKEN
                            },
                            body: 'id=' + encodeURIComponent(String(safeRowId)) + '&csrf_token=' + encodeURIComponent(CSRF_TOKEN)
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
        function syncAddTransmittalField() {
            var input = document.getElementById('addTransmittalId');
            if (input) input.value = (activeTransmittalId || '').trim();
        }
        function getNextParcelNoForTransmittal(transmittalId) {
            var rows = Array.isArray(window.mailRows) ? window.mailRows : [];
            var scopeId = ((transmittalId || '') + '').trim();
            var maxParcel = 0;
            rows.forEach(function(row) {
                if (!row || typeof row !== 'object') return;
                var rowTransmittalId = ((row['Transmittal ID'] || '') + '').trim();
                if (rowTransmittalId !== scopeId) return;
                var parsed = parseInt(((row['Parcel No.'] || '') + '').trim(), 10);
                if (Number.isFinite(parsed) && parsed > maxParcel) {
                    maxParcel = parsed;
                }
            });
            return maxParcel + 1;
        }
        function syncAddParcelNoField() {
            var input = document.getElementById('addParcelNo');
            if (!input) return;
            var nextNo = getNextParcelNoForTransmittal(activeTransmittalId);
            input.value = String(nextNo);
        }
        function syncAddParcelNoFieldForTransmittal(transmittalId) {
            var input = document.getElementById('addParcelNo');
            if (!input) return;
            var nextNo = getNextParcelNoForTransmittal(transmittalId);
            input.value = String(nextNo);
        }
        var currentAddMode = 'default';
        var currentBatchSourceRowId = 0;
        function getAddModalMode() {
            var overlay = document.getElementById('addModalOverlay');
            if (!overlay) return 'default';
            return overlay.getAttribute('data-add-mode') || 'default';
        }
        function setAddModalMode(mode) {
            var overlay = document.getElementById('addModalOverlay');
            if (!overlay) return;
            var safeMode = (mode === 'batch') ? 'batch' : 'default';
            overlay.setAttribute('data-add-mode', safeMode);
            currentAddMode = safeMode;
            var title = document.getElementById('addModalTitle');
            var submitBtn = document.getElementById('addModalSubmit');
            if (safeMode === 'batch') {
                if (title) title.textContent = 'ADD BATCH ITEM';
                if (submitBtn) submitBtn.textContent = 'Add Batch Item';
            } else {
                if (title) title.textContent = 'ADD RECORD';
                if (submitBtn) submitBtn.textContent = 'Add Record';
            }
        }
        function extractCustomSenderDetails(senderDetails) {
            var text = ((senderDetails || '') + '').trim();
            if (!text) return '';
            var marker = 'Department of Human Settlements and Urban Development Region 4A';
            var idx = text.indexOf(marker);
            if (idx > 0) {
                return text.slice(0, idx).trim();
            }
            return '';
        }
        function getBatchIdForRow(rowId, rowData) {
            var rowEl = getRowElementById(rowId);
            var batchId = rowEl ? (((rowEl.dataset.batchId || '') + '').trim()) : '';
            if (batchId) return batchId;
            var senderDetails = ((rowData && rowData['Sender Details']) ? String(rowData['Sender Details']) : '').trim();
            var match = senderDetails.match(/Batch ID:\s*([A-Za-z0-9\-]+)/i);
            return match ? ((match[1] || '') + '').trim() : '';
        }
        function buildBatchSenderDetails(rowData, batchId) {
            var senderDetails = ((rowData && rowData['Sender Details']) ? String(rowData['Sender Details']) : '');
            var custom = extractCustomSenderDetails(senderDetails);
            var parts = [];
            if (custom) parts.push(custom);
            if (batchId) parts.push('Batch ID: ' + batchId);
            return parts.join('\n');
        }
        function resetAddPairRows() {
            var rowsWrap = document.getElementById('addPairRows');
            if (!rowsWrap) return;
            rowsWrap.innerHTML = '';
            addAddPairRow();
            refreshAddPairButtons();
        }
        function prepareBatchAddModal(rowId) {
            var form = document.getElementById('addForm');
            if (form) form.reset();
            resetAddPairRows();
            var rowData = getRowDataForInlineEdit(rowId);
            if (!rowData) {
                syncAddTransmittalField();
                syncAddParcelNoField();
                return;
            }
            var transmittalId = ((rowData['Transmittal ID'] || '') + '').trim();
            var transmittalInput = document.getElementById('addTransmittalId');
            if (transmittalInput) transmittalInput.value = transmittalId;
            var batchInput = document.getElementById('addBatchId');
            if (batchInput) {
                batchInput.value = getBatchIdForRow(rowId, rowData);
            }
            var batchSourceInput = document.getElementById('addBatchSourceRowId');
            if (batchSourceInput) {
                batchSourceInput.value = String(parseInt(rowId || '0', 10) || 0);
            }
            syncAddParcelNoFieldForTransmittal(transmittalId);
            var dateInput = document.getElementById('addDateAfd');
            if (dateInput) dateInput.value = normalizeDateForInput(rowData['Date released to AFD'] || '');
            var recipientInput = document.getElementById('addRecipient');
            if (recipientInput) recipientInput.value = ((rowData['Recipient Details'] || '') + '').trim();
            var trackingInput = document.getElementById('addTrackingNo');
            if (trackingInput) trackingInput.value = ((rowData['Tracking No.'] || '') + '').trim();
            var fileInput = document.getElementById('addFileName');
            if (fileInput) fileInput.value = ((rowData['File Name (PDF)'] || '') + '').trim();
            var senderInput = document.getElementById('addSender');
            if (senderInput) {
                var senderDetails = ((rowData['Sender Details'] || '') + '').trim();
                senderInput.value = extractCustomSenderDetails(senderDetails);
            }
            var statusInput = document.getElementById('addStatus');
            if (statusInput) statusInput.value = ((rowData['Status'] || '') + '').trim();
            var remarksInput = document.getElementById('addTransmittalRemarks');
            if (remarksInput) remarksInput.value = ((rowData['Transmittal Remarks/Received By'] || '') + '').trim();
            var eventDateInput = document.getElementById('addEventDate');
            if (eventDateInput) eventDateInput.value = ((rowData['Date'] || '') + '').trim();
            var evaluatorInput = document.getElementById('addEvaluator');
            if (evaluatorInput) evaluatorInput.value = ((rowData['Evaluator'] || '') + '').trim();
        }
        // Add Modal logic
        function openAddModal(options) {
            var opts = options || {};
            var mode = (opts.mode === 'batch') ? 'batch' : 'default';
            setAddModalMode(mode);
            if (mode === 'batch') {
                currentBatchSourceRowId = parseInt(opts.rowId || '0', 10) || 0;
                prepareBatchAddModal(currentBatchSourceRowId);
            } else {
                currentBatchSourceRowId = 0;
                var batchInput = document.getElementById('addBatchId');
                if (batchInput) batchInput.value = '';
                var batchSourceInput = document.getElementById('addBatchSourceRowId');
                if (batchSourceInput) batchSourceInput.value = '';
                var statusInput = document.getElementById('addStatus');
                if (statusInput) statusInput.value = '';
                var remarksInput = document.getElementById('addTransmittalRemarks');
                if (remarksInput) remarksInput.value = '';
                var eventDateInput = document.getElementById('addEventDate');
                if (eventDateInput) eventDateInput.value = '';
                var evaluatorInput = document.getElementById('addEvaluator');
                if (evaluatorInput) evaluatorInput.value = '';
                syncAddTransmittalField();
                syncAddParcelNoField();
            }
            hideRowMenuDropdown();
            var overlay = document.getElementById('addModalOverlay');
            if (!overlay) return;
            overlay.style.display = 'flex';
            overlay.style.pointerEvents = 'auto';
            overlay.removeAttribute('inert');
            animateModalPanelFromTrigger(overlay, overlay.querySelector('.edit-modal'), document.activeElement);
            setTimeout(function() {
                var firstInput = overlay.querySelector('input:not([type="hidden"]):not([readonly]), textarea:not([readonly]), select');
                if (firstInput) {
                    try { firstInput.focus(); } catch (e) {}
                }
            }, 20);
        }
        function closeAddModal() {
            var overlay = document.getElementById('addModalOverlay');
            if (!overlay) return;
            overlay.style.display = 'none';
            overlay.style.pointerEvents = 'none';
            overlay.setAttribute('inert', '');
            setAddModalMode('default');
            currentBatchSourceRowId = 0;
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

        document.addEventListener('DOMContentLoaded', function() {
            const updateBtn = document.getElementById('appUpdateBtn');
            if (!updateBtn) return;
            updateBtn.addEventListener('click', async function() {
                if (!window.dhsudApp || typeof window.dhsudApp.checkForUpdates !== 'function') {
                    alert('Updates are available in the installed desktop app only.');
                    return;
                }
                updateBtn.disabled = true;
                try {
                    const result = await window.dhsudApp.checkForUpdates();
                    const status = result && result.status ? result.status : '';
                    if (status === 'no-update') {
                        alert('No updates found.');
                    } else if (status === 'dev') {
                        alert('Updates are only available in the installed app.');
                    } else if (status === 'deferred') {
                        alert('Update available. You can install it later from the same button.');
                    }
                } catch (e) {
                    alert('Update check failed.');
                } finally {
                    updateBtn.disabled = false;
                }
            });
        });
        // Clear form fields
        function clearAddForm() {
            var mode = getAddModalMode();
            if (mode === 'batch') {
                prepareBatchAddModal(currentBatchSourceRowId);
                return;
            }
            var form = document.getElementById('addForm');
            if (form) form.reset();
            var batchInput = document.getElementById('addBatchId');
            if (batchInput) batchInput.value = '';
            var batchSourceInput = document.getElementById('addBatchSourceRowId');
            if (batchSourceInput) batchSourceInput.value = '';
            var statusInput = document.getElementById('addStatus');
            if (statusInput) statusInput.value = '';
            var remarksInput = document.getElementById('addTransmittalRemarks');
            if (remarksInput) remarksInput.value = '';
            var eventDateInput = document.getElementById('addEventDate');
            if (eventDateInput) eventDateInput.value = '';
            var evaluatorInput = document.getElementById('addEvaluator');
            if (evaluatorInput) evaluatorInput.value = '';
            syncAddTransmittalField();
            syncAddParcelNoField();
            var rowsWrap = document.getElementById('addPairRows');
            if (rowsWrap) {
                rowsWrap.innerHTML = '';
                addAddPairRow();
            }
            refreshAddPairButtons();
            setAddModalMode('default');
        }

        function addAddPairRow(noticeValue = '', parcelValue = '') {
            var rowsWrap = document.getElementById('addPairRows');
            if (!rowsWrap) return;
            var row = document.createElement('div');
            row.className = 'add-pair-row';
            row.innerHTML =
                '<div><label>Code</label><input type="text" name="noticeCodes[]" placeholder="Notice/Order Code"></div>' +
                '<div><label>Parcel Details</label><textarea name="parcelDetailsList[]" rows="1" placeholder="Parcel Details"></textarea></div>' +
                '<button type="button" class="pair-row-btn" title="Add row">+</button>';
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
                alert('At least one Notice/Order Code + Parcel Details pair is needed.');
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
                    if (getAddModalMode() !== 'batch') {
                        syncAddTransmittalField();
                    }
                    var formData = new FormData(addForm);
                    formData.set('csrf_token', CSRF_TOKEN);

                    // Debug: Log form data
                    console.log('Form Data being sent:');
                    for (let [key, value] of formData.entries()) {
                        console.log('  ' + key + ': "' + value + '"');
                    }
                    
                    fetch('../api/Add.php', {
                        method: 'POST',
                        body: formData,
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest',
                            'X-CSRF-Token': CSRF_TOKEN
                        }
                    })
                    .then(resp => resp.json())
                    .then(data => {
                        console.log('Response from Add.php:', data);
                        if (data.success) {
                            clearAddForm();
                            closeAddModal();
                            const firstAddedNotice = (data.firstNotice || '').trim();
                            const firstAddedRowId = parseInt(data.firstId || '0', 10) || 0;
                            const addedTrackingNo = ((formData.get('trackingNo') || formData.get('Tracking No.') || '') + '').trim();
                            const immediateTrackItems = addedTrackingNo !== ''
                                ? ((Array.isArray(data.insertedIds) ? data.insertedIds : []).map(function(id, index) {
                                    return {
                                        rowId: parseInt(id || '0', 10) || 0,
                                        noticeCode: ((Array.isArray(data.insertedNotices) ? data.insertedNotices[index] : '') || '').trim()
                                    };
                                }).filter(function(item) {
                                    return item.rowId > 0 || item.noticeCode !== '';
                                }))
                                : [];
                            refreshHomeData({
                                focusNotice: firstAddedNotice,
                                focusRowId: firstAddedRowId,
                                immediateTrackItems: immediateTrackItems
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
                                'Notice/Order Code': (tr.dataset.notice || '').trim(),
                                'Transmittal ID': (tr.dataset.transmittalId || '').trim()
                            };
                            tr.querySelectorAll('td[data-col]').forEach(function(td) {
                                var key = td.getAttribute('data-col');
                                row[key] = (td.textContent || '').trim();
                            });
                            return row;
                        }

                        const INLINE_EDITABLE_COLUMNS = new Set([
                            'Notice/Order Code',
                            'Date released to AFD',
                            'Parcel No.',
                            'Recipient Details',
                            'Parcel Details',
                            'Tracking No.',
                            'Status',
                            'Transmittal Remarks/Received By',
                            'Date',
                            'Evaluator'
                        ]);
                        const INLINE_TEXTAREA_COLUMNS = new Set([
                            'Recipient Details',
                            'Parcel Details',
                            'Transmittal Remarks/Received By'
                        ]);
                        const inlineEditState = {
                            cell: null,
                            rowId: 0,
                            column: '',
                            originalValue: '',
                            originalHtml: '',
                            saving: false
                        };

                        function getInlineEditableColumn(cell) {
                            if (!cell) return '';
                            if (cell.classList.contains('notice-code-cell')) return 'Notice/Order Code';
                            return (cell.getAttribute('data-col') || '').trim();
                        }

                        function isInlineEditableCell(cell) {
                            var colName = getInlineEditableColumn(cell);
                            if (!colName || !INLINE_EDITABLE_COLUMNS.has(colName)) return false;
                            if (cell.classList.contains('action-cell') || cell.classList.contains('pdf-link-cell')) return false;
                            return true;
                        }

                        function annotateInlineEditableCells(scope) {
                            var root = scope || document;
                            if (!root || !root.querySelectorAll) return;
                            root.querySelectorAll('.admin-table-container td.notice-code-cell, .admin-table-container td[data-col]').forEach(function(cell) {
                                if (isInlineEditableCell(cell)) {
                                    cell.classList.add('is-inline-editable');
                                    if (!cell.getAttribute('title')) {
                                        cell.setAttribute('title', 'Double-click to edit');
                                    }
                                } else {
                                    cell.classList.remove('is-inline-editable');
                                    if (cell.getAttribute('title') === 'Double-click to edit') {
                                        cell.removeAttribute('title');
                                    }
                                }
                            });
                        }

                        function getRowDataForInlineEdit(rowId) {
                            var safeRowId = parseInt(rowId, 10) || 0;
                            if (safeRowId <= 0) return null;
                            if (Array.isArray(window.mailRows)) {
                                var cached = window.mailRows.find(function(r) {
                                    return (parseInt(r.id, 10) || 0) === safeRowId;
                                });
                                if (cached) return cached;
                            }
                            return getRowDataFromTable(safeRowId);
                        }

                        function normalizeInlineCellValue(columnName, rowData) {
                            var safeRow = rowData || {};
                            if (columnName === 'Notice/Order Code') return ((safeRow['Notice/Order Code'] || '') + '').trim();
                            if (columnName === 'Date released to AFD' || columnName === 'Date') {
                                return normalizeDateForInput(safeRow[columnName] || '');
                            }
                            return ((safeRow[columnName] || '') + '');
                        }

                        function normalizeInlineCompareValue(columnName, value) {
                            var v = ((value || '') + '').trim();
                            if (columnName === 'Status') return v.toUpperCase();
                            return v;
                        }

                        function formatInlineDateDisplay(value) {
                            var v = (value || '').trim();
                            if (!v) return '';
                            var parsed = new Date(v);
                            if (isNaN(parsed.getTime())) return v;
                            var months = ['January','February','March','April','May','June','July','August','September','October','November','December'];
                            var month = months[parsed.getMonth()] || '';
                            var day = String(parsed.getDate()).padStart(2, '0');
                            var year = parsed.getFullYear();
                            return month + '-' + day + '-' + year;
                        }

                        function ensureCopyButtonForCell(cell, columnName) {
                            if (!cell) return;
                            if (columnName && !INLINE_EDITABLE_COLUMNS.has(columnName)) return;
                            if (cell.classList.contains('pdf-link-cell') || cell.classList.contains('action-cell')) return;
                            cell.classList.add('has-cell-copy');
                            if (!cell.querySelector('.cell-copy-btn')) {
                                const btn = document.createElement('button');
                                btn.type = 'button';
                                btn.className = 'cell-copy-btn';
                                btn.setAttribute('aria-label', 'Copy cell');
                                btn.innerHTML = '<img src="../assets/copy-svgrepo-com.svg" alt="">';
                                cell.appendChild(btn);
                            }
                        }

                        function copyTextToClipboard(text) {
                            const value = ((text || '') + '').trim();
                            if (navigator.clipboard && navigator.clipboard.writeText) {
                                navigator.clipboard.writeText(value).catch(function() {});
                            } else {
                                const temp = document.createElement('textarea');
                                temp.value = value;
                                temp.setAttribute('readonly', 'readonly');
                                temp.style.position = 'fixed';
                                temp.style.left = '-9999px';
                                document.body.appendChild(temp);
                                temp.select();
                                try { document.execCommand('copy'); } catch (e) {}
                                document.body.removeChild(temp);
                            }
                        }

                        let copyToastTimer = null;
                        function showCopyToast() {
                            const toast = document.getElementById('copyToast');
                            if (!toast) return;
                            toast.classList.add('is-visible');
                            if (copyToastTimer) {
                                clearTimeout(copyToastTimer);
                                copyToastTimer = null;
                            }
                            copyToastTimer = setTimeout(function() {
                                toast.classList.remove('is-visible');
                            }, 1200);
                        }

                        document.addEventListener('click', function(e) {
                            const btn = e.target.closest && e.target.closest('.cell-copy-btn');
                            if (!btn) return;
                            e.preventDefault();
                            e.stopPropagation();
                            const cell = btn.closest('td');
                            const textEl = cell ? cell.querySelector('.cell-text') : null;
                            copyTextToClipboard(textEl ? textEl.textContent : '');
                            showCopyToast();
                        });

                        function updateInlineCellDisplay(cell, rowId, columnName, nextValue, originalHtml) {
                            var row = findRowById(rowId);
                            var displayValue = (columnName === 'Date released to AFD' || columnName === 'Date')
                                ? formatInlineDateDisplay(nextValue)
                                : nextValue;

                            if (columnName === 'Notice/Order Code') {
                                if (row) {
                                    row.dataset.notice = nextValue;
                                    var cb = row.querySelector('.row-checkbox');
                                    if (cb) cb.setAttribute('data-notice', nextValue);
                                }
                                if (typeof originalHtml === 'string' && originalHtml !== '') {
                                    cell.innerHTML = originalHtml;
                                }
                                var noticeSpan = cell.querySelector('.notice-code-cell .cell-text');
                                if (noticeSpan) {
                                    noticeSpan.textContent = nextValue;
                                } else {
                                    cell.textContent = nextValue;
                                }
                                return;
                            }

                            var textEl = cell.querySelector('.cell-text');
                            if (textEl) {
                                textEl.textContent = displayValue;
                            } else {
                                cell.textContent = displayValue;
                            }
                            if (cell.hasAttribute('data-search-raw-text')) {
                                cell.setAttribute('data-search-raw-text', displayValue);
                            }

                            if (row && columnName === 'Tracking No.') {
                                row.dataset.trackingNo = nextValue;
                            }
                        }

                        function updateRowCache(rowId, columnName, nextValue, originalNotice, originalTracking) {
                            if (!Array.isArray(window.mailRows)) return;
                            var rowObj = window.mailRows.find(function(r) {
                                return (parseInt(r && r.id, 10) || 0) === rowId;
                            });
                            if (!rowObj) return;
                            rowObj[columnName] = nextValue;
                            if (columnName === 'Notice/Order Code' && originalNotice && originalNotice !== nextValue) {
                                mailRowIndexByNotice.delete(originalNotice);
                                mailRowIndexByNotice.set(nextValue, rowObj);
                            }
                            if (columnName === 'Tracking No.') {
                                if (originalTracking) mailRowIndexByTracking.delete(originalTracking);
                                if (nextValue && nextValue !== '0') {
                                    mailRowIndexByTracking.set(nextValue, rowObj);
                                }
                            }
                        }

                        function updateActionCellForRow(row) {
                            if (!row) return;
                            var actionCell = row.querySelector('td.action-cell');
                            if (!actionCell) return;
                            var trackingNo = (row.dataset.trackingNo || '').trim();
                            var noticeCode = (row.dataset.notice || '').trim();
                            var statusCell = row.querySelector('td[data-col="Status"]');
                            var statusText = ((statusCell && statusCell.textContent) ? statusCell.textContent : '').trim().toUpperCase();
                            actionCell.innerHTML = '';

                            if (trackingNo !== '' && trackingNo !== '0' && statusText === 'ONGOING DELIVERY') {
                                var label = document.createElement('span');
                                label.style.fontSize = '0.72rem';
                                label.style.color = '#22336A';
                                label.style.fontWeight = '700';
                                label.textContent = 'Auto Tracking';
                                actionCell.appendChild(label);
                                var result = document.createElement('div');
                                result.className = 'track-result';
                                actionCell.appendChild(result);
                            } else if (trackingNo === '' || trackingNo === '0') {
                                var button = document.createElement('button');
                                button.type = 'button';
                                button.className = 'btn-scan';
                                button.style.display = 'inline-block';
                                button.style.textDecoration = 'none';
                                button.textContent = 'Scan';
                                button.addEventListener('click', function() {
                                    openScannerModal(noticeCode, parseInt(row.dataset.id || '0', 10) || 0);
                                });
                                actionCell.appendChild(button);
                            } else {
                                var note = document.createElement('span');
                                note.className = 'tracking-present-note';
                                note.textContent = 'Tracking recorded';
                                actionCell.appendChild(note);
                            }
                        }

                        function createInlineEditor(columnName, currentValue) {
                            var editor;
                            if (columnName === 'Status') {
                                editor = document.createElement('select');
                                ['', 'DELIVERED', 'RETURNED TO SENDER', 'ONGOING DELIVERY', 'PERSONALLY RECEIVED'].forEach(function(optionValue) {
                                    var option = document.createElement('option');
                                    option.value = optionValue;
                                    option.textContent = optionValue || 'Select status';
                                    editor.appendChild(option);
                                });
                                editor.value = currentValue;
                            } else if (INLINE_TEXTAREA_COLUMNS.has(columnName)) {
                                editor = document.createElement('textarea');
                                editor.rows = 3;
                                editor.value = currentValue;
                            } else {
                                editor = document.createElement('input');
                                if (columnName === 'Date released to AFD' || columnName === 'Date') {
                                    editor.type = 'date';
                                } else if (columnName === 'Parcel No.') {
                                    editor.type = 'number';
                                    editor.step = '1';
                                    editor.min = '0';
                                } else {
                                    editor.type = 'text';
                                }
                                editor.value = currentValue;
                            }
                            editor.className = 'inline-cell-editor';
                            editor.setAttribute('data-inline-editor', '1');
                            return editor;
                        }

                        function copyInlineCellValue(editor) {
                            const text = ((editor && editor.value) ? editor.value : '');
                            if (navigator.clipboard && navigator.clipboard.writeText) {
                                navigator.clipboard.writeText(text).catch(function() {});
                            } else {
                                const temp = document.createElement('textarea');
                                temp.value = text;
                                temp.setAttribute('readonly', 'readonly');
                                temp.style.position = 'fixed';
                                temp.style.left = '-9999px';
                                document.body.appendChild(temp);
                                temp.select();
                                try { document.execCommand('copy'); } catch (e) {}
                                document.body.removeChild(temp);
                            }
                        }

                        function cancelInlineCellEdit(options) {
                            var opts = options || {};
                            if (!inlineEditState.cell) return;
                            var activeCell = inlineEditState.cell;
                            activeCell.classList.remove('is-inline-editing', 'is-inline-saving');
                            if (!opts.skipRestore && typeof inlineEditState.originalHtml === 'string') {
                                activeCell.innerHTML = inlineEditState.originalHtml;
                            }
                            inlineEditState.cell = null;
                            inlineEditState.rowId = 0;
                            inlineEditState.column = '';
                            inlineEditState.originalValue = '';
                            inlineEditState.originalHtml = '';
                            inlineEditState.saving = false;
                            if (!opts.keepFocus) {
                                try { activeCell.blur(); } catch (e) {}
                            }
                        }

                        function beginInlineCellEdit(cell) {
                            if (!cell || !isInlineEditableCell(cell)) return;
                            if (inlineEditState.saving) return;
                            if (inlineEditState.cell && inlineEditState.cell !== cell) {
                                cancelInlineCellEdit({ keepFocus: true });
                            }

                            var row = cell.closest('tr[data-id]');
                            if (!row) return;
                            var rowId = parseInt(row.getAttribute('data-id') || '0', 10) || 0;
                            if (rowId <= 0) return;
                            var columnName = getInlineEditableColumn(cell);
                            if (!columnName) return;
                            var rowData = getRowDataForInlineEdit(rowId);
                            if (!rowData) return;
                            var currentValue = normalizeInlineCellValue(columnName, rowData);
                            var editor = createInlineEditor(columnName, currentValue);

                            inlineEditState.cell = cell;
                            inlineEditState.rowId = rowId;
                            inlineEditState.column = columnName;
                            inlineEditState.originalValue = currentValue;
                            inlineEditState.originalHtml = cell.innerHTML;
                            inlineEditState.saving = false;

                            cell.classList.add('is-inline-editing');
                            cell.innerHTML = '';
                            const wrap = document.createElement('div');
                            wrap.className = 'inline-cell-editor-wrap';
                            const copyBtn = document.createElement('button');
                            copyBtn.type = 'button';
                            copyBtn.className = 'inline-cell-copy-btn';
                            copyBtn.setAttribute('aria-label', 'Copy cell');
                            copyBtn.innerHTML = '<img src="../assets/copy-svgrepo-com.svg" alt="">';
                            copyBtn.addEventListener('pointerdown', function(e) {
                                e.preventDefault();
                                e.stopPropagation();
                            });
                            copyBtn.addEventListener('click', function(e) {
                                e.preventDefault();
                                e.stopPropagation();
                                copyInlineCellValue(editor);
                                try { editor.focus(); } catch (e2) {}
                            });
                            wrap.appendChild(editor);
                            wrap.appendChild(copyBtn);
                            cell.appendChild(wrap);

                            editor.addEventListener('keydown', function(e) {
                                if (e.key === 'Escape') {
                                    e.preventDefault();
                                    cancelInlineCellEdit();
                                    return;
                                }
                                if (e.key === 'Enter' && (editor.tagName !== 'TEXTAREA' || e.ctrlKey || e.metaKey)) {
                                    e.preventDefault();
                                    saveInlineCellEdit();
                                }
                            });
                            editor.addEventListener('blur', function() {
                                setTimeout(function() {
                                    if (inlineEditState.cell === cell && !inlineEditState.saving) {
                                        saveInlineCellEdit();
                                    }
                                }, 0);
                            });
                            if (editor.tagName === 'SELECT') {
                                editor.addEventListener('change', function() {
                                    saveInlineCellEdit();
                                });
                            }

                            setTimeout(function() {
                                try {
                                    editor.focus();
                                    if (typeof editor.select === 'function' && editor.tagName !== 'SELECT') editor.select();
                                } catch (e) {}
                            }, 0);
                        }

                        function saveInlineCellEdit() {
                            if (!inlineEditState.cell || inlineEditState.saving) return;
                            var cell = inlineEditState.cell;
                            var rowId = inlineEditState.rowId;
                            var columnName = inlineEditState.column;
                            var editor = cell.querySelector('[data-inline-editor="1"]');
                            if (!editor) {
                                cancelInlineCellEdit();
                                return;
                            }

                            var nextValue = ((editor.value || '') + '').trim();
                            var originalValue = ((inlineEditState.originalValue || '') + '').trim();
                            if (normalizeInlineCompareValue(columnName, nextValue) === normalizeInlineCompareValue(columnName, originalValue)) {
                                cancelInlineCellEdit();
                                return;
                            }

                            inlineEditState.saving = true;
                            cell.classList.add('is-inline-saving');
                            editor.disabled = true;

                            var rowData = getRowDataForInlineEdit(rowId) || {};
                            var noticeCode = ((rowData['Notice/Order Code'] || '') + '').trim();
                            var originalTracking = ((rowData['Tracking No.'] || '') + '').trim();
                            var formData = new FormData();
                            formData.set('csrf_token', CSRF_TOKEN);
                            formData.set('original_id', String(rowId));
                            formData.set('original_notice_code', noticeCode);
                            formData.set(columnName, nextValue);

                            fetch('../api/EditMail.php', {
                                method: 'POST',
                                headers: {
                                    'X-CSRF-Token': CSRF_TOKEN
                                },
                                body: formData
                            })
                            .then(function(resp) { return resp.json(); })
                            .then(function(data) {
                                if (!data || !data.success) {
                                    throw new Error((data && data.message) ? data.message : 'Failed to save changes.');
                                }
                                if (data.pdfWarning) {
                                    alert(data.pdfWarning);
                                }
                                var originalHtml = inlineEditState.originalHtml;
                                cancelInlineCellEdit({ keepFocus: true, skipRestore: true });
                                updateInlineCellDisplay(cell, rowId, columnName, nextValue, originalHtml);
                                updateRowCache(rowId, columnName, nextValue, noticeCode, originalTracking);
                                if (columnName === 'Notice/Order Code') {
                                    noticeCode = nextValue;
                                }
                                if (columnName === 'Tracking No.' || columnName === 'Status') {
                                    updateActionCellForRow(findRowById(rowId));
                                    refreshAutoTrackBadges();
                                }
                                if (columnName === 'Tracking No.' && nextValue !== '' && nextValue !== '0') {
                                    runTrackingUpdate(noticeCode, { silent: true, rowId: rowId, force: true, bypassCooldown: true });
                                }
                            })
                            .catch(function(err) {
                                cancelInlineCellEdit({ keepFocus: true });
                                alert(err && err.message ? err.message : 'Failed to save changes.');
                            });
                        }

                        function handleTableCellDoubleClick(event) {
                            var target = event.target;
                            if (!target) return;
                            if (target.closest('button, input, textarea, select, a, label')) return;

                            var cell = target.closest('td');
                            if (!isInlineEditableCell(cell)) return;
                            beginInlineCellEdit(cell);
                        }

                        // Save handler (AJAX)
                        document.addEventListener('DOMContentLoaded', function() {
                            const editForm = document.getElementById('editForm');
                            if (!editForm) return;
                            editForm.addEventListener('submit', function(e) {
                                e.preventDefault();
                                var form = e.target;
                                var formData = new FormData(form);
                                formData.set('csrf_token', CSRF_TOKEN);
                                
                                // Debug: Log what's being sent
                                console.log('Submitting form with:');
                                for (let [key, value] of formData.entries()) {
                                    console.log('  ' + key + ': "' + value + '"');
                                }
                                
                                fetch('../api/EditMail.php', {
                                    method: 'POST',
                                    headers: {
                                        'X-CSRF-Token': CSRF_TOKEN
                                    },
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
                                        var focusRowId = parseInt((formData.get('original_id') || '0'), 10) || 0;
                                        var submittedTrackingNo = (formData.get('Tracking No.') || '').trim();
                                        closeEditModal();
                                        refreshHomeData({
                                            focusNotice: focusNotice,
                                            focusRowId: focusRowId,
                                            immediateTrackItems: (submittedTrackingNo !== '' ? [{
                                                noticeCode: focusNotice,
                                                rowId: focusRowId
                                            }] : [])
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
                        let mailRowIndexById = new Map();
                        let mailRowIndexByNotice = new Map();
                        let mailRowIndexByTracking = new Map();

                        function rebuildMailRowIndexes(rowsOverride) {
                            const rows = Array.isArray(rowsOverride) ? rowsOverride : (Array.isArray(window.mailRows) ? window.mailRows : []);
                            mailRowIndexById = new Map();
                            mailRowIndexByNotice = new Map();
                            mailRowIndexByTracking = new Map();
                            rows.forEach(function(r) {
                                const id = parseInt(r && r.id, 10) || 0;
                                if (id > 0) mailRowIndexById.set(id, r);
                                const noticeCode = ((r && r['Notice/Order Code']) ? String(r['Notice/Order Code']) : '').trim();
                                if (noticeCode !== '' && !mailRowIndexByNotice.has(noticeCode)) {
                                    mailRowIndexByNotice.set(noticeCode, r);
                                }
                                const trackingNo = ((r && r['Tracking No.']) ? String(r['Tracking No.']) : '').trim();
                                if (trackingNo !== '' && trackingNo !== '0' && !mailRowIndexByTracking.has(trackingNo)) {
                                    mailRowIndexByTracking.set(trackingNo, r);
                                }
                            });
                            if (typeof updateTransmittalGrid === 'function') {
                                updateTransmittalGrid();
                            }
                        }

                        window.mailRows = <?php echo json_encode($rows, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT); ?>;
                        rebuildMailRowIndexes();

        function rebuildMailRowsFromTable() {
            const trs = document.querySelectorAll('.admin-table-container table tbody tr[data-id]');
            const rows = [];
            const activeSpans = {};

            trs.forEach(function(tr) {
                const row = {
                    'id': parseInt(tr.dataset.id || '0', 10) || 0,
                    'Notice/Order Code': (tr.dataset.notice || '').trim(),
                    'Transmittal ID': (tr.dataset.transmittalId || '').trim()
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
            rebuildMailRowIndexes(rows);
        }

        function isRowDetailMobileView() {
            return !!(window.matchMedia && window.matchMedia('(max-width: 640px)').matches);
        }

        function closeRowDetailModal() {
            const modal = document.getElementById('rowDetailModal');
            if (!modal) return;
            modal.hidden = true;
            modal.setAttribute('aria-hidden', 'true');
            modal.classList.remove('is-open');
            document.body.classList.remove('row-detail-open');
        }

        function openRowDetailModal(rowId) {
            const safeRowId = parseInt(rowId || 0, 10) || 0;
            if (safeRowId <= 0) return;
            const modal = document.getElementById('rowDetailModal');
            const body = document.getElementById('rowDetailBody');
            const title = document.getElementById('rowDetailTitle');
            if (!modal || !body) return;

            const rowData = getRowDataFromTable(safeRowId) || (mailRowIndexById.get(safeRowId) || null);
            const fields = ['Notice/Order Code'].concat(Array.isArray(tableDataColumns) ? tableDataColumns : []);

            body.innerHTML = '';
            fields.forEach(function(key) {
                const raw = (rowData && Object.prototype.hasOwnProperty.call(rowData, key)) ? rowData[key] : '';
                const value = (raw == null ? '' : String(raw)).trim();
                const item = document.createElement('div');
                item.className = 'row-detail-item';
                const label = document.createElement('div');
                label.className = 'row-detail-label';
                label.textContent = key;
                const val = document.createElement('div');
                val.className = 'row-detail-value';
                val.textContent = value || '—';
                item.appendChild(label);
                item.appendChild(val);
                body.appendChild(item);
            });

            const noticeTitle = (rowData && rowData['Notice/Order Code']) ? String(rowData['Notice/Order Code']).trim() : '';
            if (title) {
                title.textContent = noticeTitle ? ('Record ' + noticeTitle) : 'Record Details';
            }

            modal.hidden = false;
            modal.setAttribute('aria-hidden', 'false');
            modal.classList.add('is-open');
            document.body.classList.add('row-detail-open');
        }

        document.addEventListener('click', function(evt) {
            if (!isRowDetailMobileView()) return;
            const modal = document.getElementById('rowDetailModal');
            if (modal && modal.classList.contains('is-open') && evt.target === modal) {
                closeRowDetailModal();
            }
        });

        document.addEventListener('click', function(evt) {
            if (!isRowDetailMobileView()) return;
            const tr = evt.target.closest('.admin-table-container table tbody tr[data-id]');
            if (!tr) return;
            if (evt.target.closest('button, a, input, select, textarea, .row-menu-btn, .cell-copy-btn')) return;
            openRowDetailModal(tr.dataset.id || 0);
        });

        document.addEventListener('keydown', function(evt) {
            if (evt.key === 'Escape') {
                closeRowDetailModal();
            }
        });

        function refreshHomeDataFull(options = {}) {
            const focusNotice = (options.focusNotice || '').trim();
            const focusRowId = parseInt(options.focusRowId || 0, 10) || 0;
            const immediateTrackNotices = Array.isArray(options.immediateTrackNotices) ? options.immediateTrackNotices : [];
            const immediateTrackItems = Array.isArray(options.immediateTrackItems) ? options.immediateTrackItems : [];
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
                        annotateInlineEditableCells(currentArea);
                    }

                    const currentStats = document.querySelector('.statistics-bar');
                    const nextStats = doc.querySelector('.statistics-bar');
                    if (currentStats && nextStats) {
                        currentStats.innerHTML = nextStats.innerHTML;
                        if (typeof updateStatusFilterButtonsUI === 'function') {
                            updateStatusFilterButtonsUI();
                        }
                    }

                    const currentYear = document.getElementById('tableSortYear');
                    const nextYear = doc.getElementById('tableSortYear');
                    if (currentYear && nextYear) {
                        const selected = currentYear.value;
                        currentYear.innerHTML = nextYear.innerHTML;
                        if (selected && Array.from(currentYear.options).some(function(o){ return o.value === selected; })) {
                            currentYear.value = selected;
                        } else {
                            currentYear.value = 'all';
                        }
                        if (typeof rebuildYearMonthFilterOptionsFromSelect === 'function') {
                            rebuildYearMonthFilterOptionsFromSelect();
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
                    immediateTrackItems.forEach(function(item) {
                        const safeItem = (item && typeof item === 'object') ? item : {};
                        runTrackingUpdate((safeItem.noticeCode || '').trim(), {
                            rowId: parseInt(safeItem.rowId || '0', 10) || 0,
                            silent: true,
                            force: true,
                            bypassCooldown: true
                        });
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

        function renderStatsBarFromValues(stats) {
            if (!stats || typeof stats !== 'object') return;
            const rtsEl = document.querySelector('.statistics-bar .stat-box.stat-rtos .stat-count');
            const ogdEl = document.querySelector('.statistics-bar .stat-box.stat-ongoing .stat-count');
            const delEl = document.querySelector('.statistics-bar .stat-box.stat-delivered .stat-count');
            const totalEl = document.querySelector('.statistics-bar .stat-box.stat-total .stat-count');
            const ndrEl = document.querySelector('.statistics-bar .stat-box.stat-ndr .stat-count');
            if (rtsEl) rtsEl.textContent = String(parseInt(stats.returnedToSender, 10) || 0);
            if (ogdEl) ogdEl.textContent = String(parseInt(stats.ongoingDelivery, 10) || 0);
            if (delEl) delEl.textContent = String(parseInt(stats.delivered, 10) || 0);
            if (totalEl) totalEl.textContent = String(parseInt(stats.total, 10) || 0);
            if (ndrEl) {
                const ndr = Number(stats.ndrPercent);
                ndrEl.textContent = (Number.isFinite(ndr) ? ndr.toFixed(1) : '0.0') + '%';
            }
        }

        function computeStatsForRows(rows, scopedTransmittalId) {
            const list = Array.isArray(rows) ? rows : [];
            const scopeId = ((scopedTransmittalId || '') + '').trim();
            let total = 0;
            let returnedToSender = 0;
            let ongoingDelivery = 0;
            let delivered = 0;

            list.forEach(function(row) {
                if (!row || typeof row !== 'object') return;
                const rowTransmittalId = ((row['Transmittal ID'] || '') + '').trim();
                if (scopeId !== '' && rowTransmittalId !== scopeId) return;

                total += 1;
                const status = ((row['Status'] || '') + '').trim().toUpperCase();
                if (status === 'RETURNED TO SENDER') {
                    returnedToSender += 1;
                } else if (status === 'ONGOING DELIVERY') {
                    ongoingDelivery += 1;
                } else if (status === 'DELIVERED') {
                    delivered += 1;
                }
            });

            const ndrBase = returnedToSender + ongoingDelivery;
            const ndrPercent = total > 0 ? (ndrBase / total) * 100 : 0;
            return {
                returnedToSender: returnedToSender,
                ongoingDelivery: ongoingDelivery,
                delivered: delivered,
                total: total,
                ndrPercent: ndrPercent
            };
        }

        function refreshStatisticsBar() {
            const rows = Array.isArray(window.mailRows) ? window.mailRows : [];
            const scopedTransmittalId = ((activeTransmittalId || '') + '').trim();
            renderStatsBarFromValues(computeStatsForRows(rows, scopedTransmittalId));
        }

        function updateStatsBarFromDelta(stats) {
            if (Array.isArray(window.mailRows)) {
                refreshStatisticsBar();
                return;
            }
            renderStatsBarFromValues(stats || {});
        }

        function getMonthShortLabel(monthValue) {
            const monthMap = {
                '01': 'Jan', '02': 'Feb', '03': 'Mar', '04': 'Apr',
                '05': 'May', '06': 'Jun', '07': 'Jul', '08': 'Aug',
                '09': 'Sep', '10': 'Oct', '11': 'Nov', '12': 'Dec'
            };
            return monthMap[monthValue] || '';
        }

        function extractYearMonthFromDateValue(rawDateValue) {
            const text = ((rawDateValue || '') + '').trim();
            if (!text) return { year: '', month: '' };

            const isoMatch = /^(\d{4})[-\/](\d{1,2})[-\/](\d{1,2})/.exec(text);
            if (isoMatch) {
                return {
                    year: isoMatch[1],
                    month: String(parseInt(isoMatch[2], 10) || 0).padStart(2, '0')
                };
            }

            const monthNameToNum = {
                january: '01', february: '02', march: '03', april: '04',
                may: '05', june: '06', july: '07', august: '08',
                september: '09', october: '10', november: '11', december: '12'
            };
            const monthNameMatch = /^(January|February|March|April|May|June|July|August|September|October|November|December)[-\s\/](\d{1,2})[-\s\/](\d{4})$/i.exec(text);
            if (monthNameMatch) {
                return {
                    year: monthNameMatch[3],
                    month: monthNameToNum[monthNameMatch[1].toLowerCase()] || ''
                };
            }

            const parsedDate = new Date(text);
            if (!Number.isNaN(parsedDate.getTime())) {
                return {
                    year: String(parsedDate.getFullYear()),
                    month: String(parsedDate.getMonth() + 1).padStart(2, '0')
                };
            }

            const yearOnlyMatch = /(\d{4})/.exec(text);
            return {
                year: yearOnlyMatch ? yearOnlyMatch[1] : '',
                month: ''
            };
        }

        function syncYearMonthFilterUI() {
            const yearSelect = document.getElementById('tableSortYear');
            const monthInput = document.getElementById('tableSortMonth');
            const yearLabel = document.getElementById('tableSortYearLabel');
            const yearTrigger = document.getElementById('tableSortYearTrigger');
            const monthGrid = document.getElementById('tableMonthGrid');
            if (!yearSelect || !monthInput) return;

            const selectedYearRaw = ((yearSelect.value || '') + '').trim();
            const selectedYear = (selectedYearRaw === 'all') ? '' : selectedYearRaw;
            let selectedMonth = ((monthInput.value || '') + '').trim();

            if (!selectedYear) {
                selectedMonth = '';
                monthInput.value = '';
            }

            if (yearLabel) {
                const monthLabel = selectedMonth ? (' - ' + getMonthShortLabel(selectedMonth)) : '';
                yearLabel.textContent = selectedYear ? (selectedYear + monthLabel) : 'Year';
            }
            if (yearTrigger) {
                yearTrigger.classList.toggle('has-selection', !!selectedYear);
            }

            document.querySelectorAll('.table-year-option').forEach(function(btn) {
                const y = ((btn.getAttribute('data-year') || '') + '').trim();
                const isActive = y === (selectedYear || 'all');
                btn.classList.toggle('is-active', isActive);
            });

            if (monthGrid) {
                const monthsEnabled = !!selectedYear;
                monthGrid.classList.toggle('is-enabled', monthsEnabled);
                monthGrid.setAttribute('aria-hidden', monthsEnabled ? 'false' : 'true');
                monthGrid.querySelectorAll('.table-month-option').forEach(function(btn) {
                    const m = ((btn.getAttribute('data-month') || '') + '').trim();
                    btn.disabled = !monthsEnabled;
                    btn.classList.toggle('is-active', monthsEnabled && m === selectedMonth);
                });
            }
        }

        function rebuildYearMonthFilterOptionsFromSelect() {
            const yearSelect = document.getElementById('tableSortYear');
            const yearList = document.getElementById('tableYearList');
            if (!yearSelect || !yearList) {
                syncYearMonthFilterUI();
                return;
            }
            const activeValue = ((yearSelect.value || '') + '').trim() || 'all';
            const buttons = [];
            Array.from(yearSelect.options).forEach(function(opt) {
                const value = ((opt.value || '') + '').trim();
                if (!value || value === '') return;
                const label = (opt.textContent || '').trim() || value;
                buttons.push(
                    '<button type="button" class="table-year-option' + (value === activeValue ? ' is-active' : '') + '" data-year="' +
                    value.replace(/"/g, '&quot;') + '">' + label.replace(/</g, '&lt;') + '</button>'
                );
            });
            yearList.innerHTML = buttons.join('');
            syncYearMonthFilterUI();
        }

        function closeYearMonthDropdown() {
            const dropdown = document.getElementById('tableYearMonthDropdown');
            const trigger = document.getElementById('tableSortYearTrigger');
            if (dropdown) dropdown.hidden = true;
            if (trigger) trigger.setAttribute('aria-expanded', 'false');
        }

        function openYearMonthDropdown() {
            const dropdown = document.getElementById('tableYearMonthDropdown');
            const trigger = document.getElementById('tableSortYearTrigger');
            if (dropdown) dropdown.hidden = false;
            if (trigger) trigger.setAttribute('aria-expanded', 'true');
        }

        function updateYearSelectFromDelta(years) {
            const yearSelect = document.getElementById('tableSortYear');
            const monthInput = document.getElementById('tableSortMonth');
            if (!yearSelect || !Array.isArray(years)) return;
            const previousYearValue = yearSelect.value;
            const previousMonthValue = monthInput ? monthInput.value : '';
            const opts = ['<option value="" disabled hidden>Year</option>', '<option value="all">All</option>'];
            years.forEach(function(y) {
                const yy = ((y || '') + '').trim();
                if (yy) {
                    opts.push('<option value="' + yy.replace(/"/g, '&quot;') + '">' + yy.replace(/</g, '&lt;') + '</option>');
                }
            });
            yearSelect.innerHTML = opts.join('');
            if (previousYearValue && Array.from(yearSelect.options).some(function(o) { return o.value === previousYearValue; })) {
                yearSelect.value = previousYearValue;
                if (monthInput) monthInput.value = previousMonthValue;
            } else {
                yearSelect.value = 'all';
                if (monthInput) monthInput.value = '';
            }
            rebuildYearMonthFilterOptionsFromSelect();
        }

        function areRowsSameOrder(currentRows, nextRows) {
            if (!Array.isArray(currentRows) || !Array.isArray(nextRows)) return false;
            if (currentRows.length !== nextRows.length) return false;
            for (let i = 0; i < nextRows.length; i++) {
                const currentId = parseInt(currentRows[i] && currentRows[i].id, 10) || 0;
                const nextId = parseInt(nextRows[i] && nextRows[i].id, 10) || 0;
                if (currentId <= 0 || nextId <= 0 || currentId !== nextId) return false;
            }
            return true;
        }

        function applyDeltaRefreshPayload(payload, previousStatusSnapshot) {
            if (!payload || payload.success !== true || !Array.isArray(payload.rows)) return false;
            const currentRows = Array.isArray(window.mailRows) ? window.mailRows : [];
            const safeDeltaCols = new Set(['Status', 'Date', 'Transmittal Remarks/Received By', 'File Name (PDF)']);
            let nextRows = currentRows.slice();

            if ((payload.mode || 'full') === 'patch') {
                const currentIndexById = new Map();
                currentRows.forEach(function(row, index) {
                    const rowId = parseInt(row && row.id, 10) || 0;
                    if (rowId > 0) currentIndexById.set(rowId, index);
                });

                for (let i = 0; i < payload.rows.length; i++) {
                    const after = payload.rows[i] || {};
                    const rowId = parseInt(after.id, 10) || 0;
                    const rowIndex = currentIndexById.get(rowId);
                    if (!rowId || typeof rowIndex === 'undefined') return false;

                    const before = currentRows[rowIndex] || {};
                    const changedCols = [];
                    if ((((before['Notice/Order Code'] || '') + '').trim()) !== (((after['Notice/Order Code'] || '') + '').trim())) {
                        return false;
                    }

                    tableDataColumns.forEach(function(col) {
                        const a = ((before[col] || '') + '').trim();
                        const b = ((after[col] || '') + '').trim();
                        if (a !== b) changedCols.push(col);
                    });

                    if (changedCols.length === 0) {
                        nextRows[rowIndex] = Object.assign({}, before, after);
                        continue;
                    }
                    if (changedCols.some(function(col) { return !safeDeltaCols.has(col); })) {
                        return false;
                    }

                    const notice = ((after['Notice/Order Code'] || '') + '').trim();
                    const rowIdForUpdate = parseInt(after.id, 10) || 0;
                    updateTrackingRow(notice, {
                        status: ((after['Status'] || '') + '').trim(),
                        date: ((after['Date'] || '') + '').trim(),
                        dateDisplay: formatDisplayDate(after['Date'] || ''),
                        transmittalRemarks: ((after['Transmittal Remarks/Received By'] || '') + '').trim(),
                        fileNamePdf: ((after['File Name (PDF)'] || '') + '').trim(),
                        trackingNo: ((after['Tracking No.'] || '') + '').trim()
                    }, { suppressNotification: true, rowId: rowIdForUpdate });
                    nextRows[rowIndex] = Object.assign({}, before, after);
                }
            } else {
                nextRows = payload.rows;
                if (!areRowsSameOrder(currentRows, nextRows)) return false;

                for (let i = 0; i < nextRows.length; i++) {
                    const before = currentRows[i] || {};
                    const after = nextRows[i] || {};
                    const changedCols = [];

                    if ((((before['Notice/Order Code'] || '') + '').trim()) !== (((after['Notice/Order Code'] || '') + '').trim())) {
                        return false;
                    }

                    tableDataColumns.forEach(function(col) {
                        const a = ((before[col] || '') + '').trim();
                        const b = ((after[col] || '') + '').trim();
                        if (a !== b) changedCols.push(col);
                    });

                    if (changedCols.length === 0) continue;
                    if (changedCols.some(function(col) { return !safeDeltaCols.has(col); })) {
                        return false;
                    }

                    const notice = ((after['Notice/Order Code'] || '') + '').trim();
                    const rowIdForUpdate = parseInt(after.id, 10) || 0;
                    updateTrackingRow(notice, {
                        status: ((after['Status'] || '') + '').trim(),
                        date: ((after['Date'] || '') + '').trim(),
                        dateDisplay: formatDisplayDate(after['Date'] || ''),
                        transmittalRemarks: ((after['Transmittal Remarks/Received By'] || '') + '').trim(),
                        fileNamePdf: ((after['File Name (PDF)'] || '') + '').trim(),
                        trackingNo: ((after['Tracking No.'] || '') + '').trim()
                    }, { suppressNotification: true, rowId: rowIdForUpdate });
                }
            }

            window.mailRows = nextRows;
            rebuildMailRowIndexes(nextRows);
            notifyStatusDiffsAfterRefresh(previousStatusSnapshot);
            updateStatsBarFromDelta(payload.stats || {});
            updateYearSelectFromDelta(payload.years || []);
            filterTableRows();
            return true;
        }

        function fetchHomeDeltaPayload(options = {}) {
            const url = new URL('../api/home-delta.php', window.location.href);
            url.searchParams.set('dept', currentDeptKey);
            url.searchParams.set('_ts', String(Date.now()));
            const previousVersion = ((options.previousVersion || '') + '').trim();
            if (previousVersion) {
                url.searchParams.set('previous_version', previousVersion);
            }
            return fetch(url.toString(), {
                method: 'GET',
                cache: 'no-store',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(function(resp) {
                if (!resp.ok) throw new Error('Delta endpoint failed');
                return resp.json();
            })
            .then(function(data) {
                if (!data || data.success !== true) {
                    throw new Error((data && data.message) ? data.message : 'Invalid delta payload');
                }
                return data;
            });
        }

        function refreshHomeData(options = {}) {
            const focusNotice = (options.focusNotice || '').trim();
            const focusRowId = parseInt(options.focusRowId || 0, 10) || 0;
            const immediateTrackNotices = Array.isArray(options.immediateTrackNotices) ? options.immediateTrackNotices : [];
            const immediateTrackItems = Array.isArray(options.immediateTrackItems) ? options.immediateTrackItems : [];
            const previousStatusSnapshot = cloneStatusSnapshot();

            return fetchHomeDeltaPayload({ previousVersion: options.previousVersion || '' })
                .then(function(deltaPayload) {
                    const deltaApplied = applyDeltaRefreshPayload(deltaPayload, previousStatusSnapshot);
                    if (!deltaApplied) {
                        return refreshHomeDataFull(options);
                    }

                    bindRowCheckboxListeners();
                    autoTrackEligibleRows();
                    immediateTrackNotices.forEach(function(notice) {
                        triggerImmediateTrackingOnce(notice);
                    });
                    immediateTrackItems.forEach(function(item) {
                        const safeItem = (item && typeof item === 'object') ? item : {};
                        runTrackingUpdate((safeItem.noticeCode || '').trim(), {
                            rowId: parseInt(safeItem.rowId || '0', 10) || 0,
                            silent: true,
                            force: true,
                            bypassCooldown: true
                        });
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
                    } else if (deltaPayload.version) {
                        smartPollingState.lastKnownVersion = String(deltaPayload.version);
                    } else {
                        syncHomeDataVersionSilently();
                    }
                    return true;
                })
                .catch(function(err) {
                    console.warn('delta refresh failed; falling back to full refresh', err);
                    return refreshHomeDataFull(options);
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
            const url = '../api/home-version.php?dept=' + encodeURIComponent(currentDeptKey) + '&_ts=' + Date.now();
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
                    return { changed: true, version: currentVersion, previousVersion: '' };
                }

                if (previousVersion !== currentVersion) {
                    smartPollingState.burstUntilAt = Date.now() + SMART_POLLING.burstWindowMs;
                }

                return { changed: previousVersion !== currentVersion, version: currentVersion, previousVersion: previousVersion };
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
                    return refreshHomeData({ knownVersion: result.version, previousVersion: result.previousVersion || '' });
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

        function buildDefaultPdfFileNameFromRow(rowObj) {
            if (!rowObj) return '';
            const rawDate = ((rowObj['Date released to AFD'] || '') + '').trim();
            const parsed = new Date(rawDate);
            if (Number.isNaN(parsed.getTime())) return '';
            const yy = String(parsed.getFullYear()).slice(-2);
            const mm = String(parsed.getMonth() + 1).padStart(2, '0');
            const dd = String(parsed.getDate()).padStart(2, '0');
            const parcel = parseInt(((rowObj['Parcel No.'] || '') + '').trim(), 10);
            const formattedParcel = String(Number.isFinite(parcel) ? parcel : 0).padStart(3, '0');
            return `${currentDeptCode}-${yy}${mm}${dd}-${formattedParcel}`;
        }

        function buildProofPdfAssetNameFromTracking(trackingNo) {
            const tracking = ((trackingNo || '') + '').trim();
            if (!tracking || tracking === '0') return '';
            return `proof_${tracking}.pdf`;
        }

        function sanitizeTransmittalFolderNameJs(value) {
            const text = ((value || '') + '').trim();
            if (!text) return 'UNASSIGNED';
            const cleaned = text.replace(/[\\/:*?"<>|]+/g, '_').replace(/\s+/g, ' ').trim().replace(/[.\s]+$/g, '');
            return cleaned || 'UNASSIGNED';
        }

        function buildMainPdfUrl(trackingNo, transmittalId) {
            const proofAssetName = buildProofPdfAssetNameFromTracking(trackingNo);
            if (!proofAssetName) return '';
            return '../JRS_PDFs/'
                + encodeURIComponent(currentDeptCode)
                + '/'
                + encodeURIComponent(sanitizeTransmittalFolderNameJs(transmittalId))
                + '/'
                + encodeURIComponent(proofAssetName);
        }

        const statusSnapshotByNotice = new Map();

        function getNotificationIdentityFromRow(row) {
            const safeRow = row || {};
            const rowId = parseInt(safeRow.id || safeRow['id'] || '0', 10) || 0;
            const notice = ((safeRow['Notice/Order Code'] || '') + '').trim();
            const trackingNo = ((safeRow['Tracking No.'] || '') + '').trim();
            let key = '';
            if (rowId > 0) {
                key = 'id:' + String(rowId);
            } else if (notice) {
                key = 'notice:' + notice;
            } else if (trackingNo && trackingNo !== '0') {
                key = 'tracking:' + trackingNo;
            }
            return {
                key: key,
                rowId: rowId,
                notice: notice,
                trackingNo: trackingNo
            };
        }

        function cloneStatusSnapshot() {
            return new Map(statusSnapshotByNotice);
        }

        function rebuildStatusSnapshotFromMailRows() {
            statusSnapshotByNotice.clear();
            if (!Array.isArray(window.mailRows)) return;

            window.mailRows.forEach(function(row) {
                const identity = getNotificationIdentityFromRow(row);
                if (!identity.key) return;
                const status = ((row['Status'] || '') + '').trim().toUpperCase();
                const eventDate = (formatDisplayDate(row['Date'] || '') || ((row['Date'] || '') + '').trim());
                statusSnapshotByNotice.set(identity.key, {
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
            const pendingBatchNotifications = new Map();

            window.mailRows.forEach(function(row) {
                const identity = getNotificationIdentityFromRow(row);
                if (!identity.key) return;
                const senderDetails = ((row['Sender Details'] || '') + '').trim();
                // Prefer DOM batch metadata because full-refresh row rebuilding may strip
                // "Batch ID: ..." text from sender details for display purposes.
                let batchId = '';
                try {
                    let rowEl = null;
                    if (identity.rowId > 0) {
                        rowEl = document.querySelector('tr[data-id="' + String(identity.rowId) + '"]');
                    }
                    if (!rowEl && identity.notice) {
                        rowEl = document.querySelector('tr[data-notice="' + CSS.escape(identity.notice) + '"]');
                    }
                    batchId = rowEl ? (((rowEl.dataset.batchId || '') + '').trim()) : '';
                } catch (e) {
                    batchId = '';
                }
                if (!batchId) {
                    const batchMatch = senderDetails.match(/Batch ID:\s*([A-Za-z0-9\-]+)/i);
                    batchId = batchMatch ? (batchMatch[1] || '').trim() : '';
                }

                const nextStatus = ((row['Status'] || '') + '').trim().toUpperCase();
                const eventDate = (formatDisplayDate(row['Date'] || '') || ((row['Date'] || '') + '').trim());
                const prevEntry = previousSnapshot.get(identity.key);
                const previousStatus = prevEntry ? (((prevEntry.status || '') + '').trim().toUpperCase()) : '';

                if (prevEntry && previousStatus !== nextStatus) {
                    if (batchId && isNotifiableStatus(nextStatus)) {
                        const batchKey = batchId + '|' + nextStatus;
                        if (!pendingBatchNotifications.has(batchKey)) {
                            pendingBatchNotifications.set(batchKey, {
                                batchId: batchId,
                                nextStatus: nextStatus,
                                eventDate: eventDate,
                                notice: identity.notice,
                                trackingNo: identity.trackingNo,
                                rowId: identity.rowId
                            });
                        }
                    } else {
                        maybeNotifyStatusChange(identity.notice, previousStatus, nextStatus, eventDate, {
                            trackingNo: identity.trackingNo,
                            rowId: identity.rowId
                        });
                    }
                }

                statusSnapshotByNotice.set(identity.key, {
                    status: nextStatus,
                    eventDate: eventDate
                });
            });

            pendingBatchNotifications.forEach(function(info) {
                maybeNotifyStatusChange(info.notice, '', info.nextStatus, info.eventDate, {
                    displayTrackingId: getBatchNoticeCodesLabel(info.batchId) || ('Batch ' + info.batchId),
                    noticeCode: info.notice,
                    trackingNo: info.trackingNo,
                    rowId: info.rowId,
                    dedupeKey: 'batch:' + info.batchId + '|status:' + info.nextStatus
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
                return value
                    .replace(/\r?\n?Department ID:\s*[^\r\n]+/i, '')
                    .replace(/\r?\n?Batch ID:\s*[A-Za-z0-9\-]+\s*/i, '')
                    .trim();
            }
            return value;
        }

        function escapeRegExp(text) {
            return String(text || '').replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        }

        function renderSearchHighlightedText(text, searchTerm) {
            const rawText = (text || '').toString();
            const needle = ((searchTerm || '') + '').trim();
            if (!needle) return escapeHtml(rawText);
            const rx = new RegExp(escapeRegExp(needle), 'ig');
            let lastIndex = 0;
            let html = '';
            rawText.replace(rx, function(match, offset) {
                html += escapeHtml(rawText.slice(lastIndex, offset));
                html += '<mark class="table-search-highlight">' + escapeHtml(match) + '</mark>';
                lastIndex = offset + match.length;
                return match;
            });
            html += escapeHtml(rawText.slice(lastIndex));
            return html;
        }

        function applyNoticeCellSearchHighlight(tr, searchTerm, rowObj) {
            if (!tr) return;
            const noticeSpan = tr.querySelector('.notice-code-cell .cell-text');
            if (!noticeSpan) return;
            const sourceText = ((rowObj && rowObj['Notice/Order Code']) ? String(rowObj['Notice/Order Code']) : (tr.dataset.notice || '')).trim();
            noticeSpan.innerHTML = renderSearchHighlightedText(sourceText, searchTerm);
        }

        function applySearchHighlightToCell(cell, sourceText, searchTerm) {
            if (!cell) return;
            const textEl = cell.querySelector('.cell-text');
            if (!textEl) return;
            const existingRaw = cell.getAttribute('data-search-raw-text');
            const rawText = (existingRaw !== null) ? existingRaw : (sourceText || '').toString();
            if (existingRaw === null) {
                cell.setAttribute('data-search-raw-text', rawText);
            }
            textEl.innerHTML = renderSearchHighlightedText(rawText, searchTerm);
        }

        function applyParcelAndTrackingSearchHighlights(tr, rowObj, searchTerm) {
            if (!tr) return;
            const recipientCell = tr.querySelector('td[data-col="Recipient Details"]');
            const parcelCell = tr.querySelector('td[data-col="Parcel Details"]');
            const trackingCell = tr.querySelector('td[data-col="Tracking No."]');

            if (recipientCell) {
                const recipientText = ((rowObj && rowObj['Recipient Details']) ? String(rowObj['Recipient Details']) : recipientCell.textContent || '').trim();
                applySearchHighlightToCell(recipientCell, recipientText, searchTerm);
            }

            if (parcelCell) {
                const parcelText = ((rowObj && rowObj['Parcel Details']) ? String(rowObj['Parcel Details']) : parcelCell.textContent || '').trim();
                applySearchHighlightToCell(parcelCell, parcelText, searchTerm);
            }

            if (trackingCell) {
                const trackingText = ((rowObj && (rowObj['Tracking No.'] ?? rowObj['Tracking No'] ?? rowObj['tracking_no'] ?? rowObj['TrackingNo']))
                    ? String(rowObj['Tracking No.'] ?? rowObj['Tracking No'] ?? rowObj['tracking_no'] ?? rowObj['TrackingNo'])
                    : (trackingCell.textContent || '')
                ).trim();
                applySearchHighlightToCell(trackingCell, trackingText, searchTerm);
            }
        }

        function clearTempSearchCells(table) {
            if (!table) return;
            table.querySelectorAll('td[data-temp-fill="1"]').forEach(function(td) {
                td.remove();
            });
            table.querySelectorAll('td[data-temp-action="1"]').forEach(function(td) {
                td.remove();
            });
        }

        function restoreRowspansFromSearch(table) {
            if (!table) return;
            table.querySelectorAll('td[data-original-rowspan]').forEach(function(td) {
                const raw = td.getAttribute('data-original-rowspan');
                const span = parseInt(raw || '1', 10);
                if (span > 1) {
                    td.setAttribute('rowspan', String(span));
                } else {
                    td.removeAttribute('rowspan');
                }
                td.removeAttribute('data-original-rowspan');
            });
        }

        function resetSearchViewMutations(table) {
            if (!table) return;
            clearTempSearchCells(table);
            restoreRowspansFromSearch(table);
        }

        function rebuildVisibleRowCellsForSearch(tr, rowObj, searchTerm) {
            if (!tr) return;
            const safeRowObj = rowObj || {};
            const actionCell = ensureActionCellForSearch(tr, safeRowObj);
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
                    const text = normalizeCellTextForSearchView(colName, safeRowObj[colName]);
                    if (colName === 'Status') {
                        const span = document.createElement('span');
                        span.className = getStatusClass(text);
                        span.textContent = text;
                        td.appendChild(span);
                        ensureCopyButtonForCell(td, colName);
                    } else if (colName === 'File Name (PDF)') {
                        const trackingValue = ((safeRowObj['Tracking No.'] || '') + '').trim();
                        const proofAssetName = buildProofPdfAssetNameFromTracking(trackingValue);
                        const defaultPdfName = buildDefaultPdfFileNameFromRow(safeRowObj);
                        const fileName = (text || defaultPdfName).trim();
                        if (fileName !== '' && proofAssetName !== '') {
                            const link = document.createElement('a');
                            const pdfUrl = buildMainPdfUrl(trackingValue, safeRowObj['Transmittal ID'] || '');
                            link.className = 'pdf-link-in-cell';
                            link.href = pdfUrl;
                            link.setAttribute('data-pdf-url', pdfUrl);
                            link.setAttribute('data-pdf-title', fileName);
                            link.textContent = fileName;
                            td.appendChild(link);
                        }
                    } else {
                        const shouldHighlight = (colName === 'Recipient Details' || colName === 'Parcel Details' || colName === 'Tracking No.');
                        if (shouldHighlight) {
                            td.setAttribute('data-search-raw-text', text);
                            const span = document.createElement('span');
                            span.className = 'cell-text';
                            span.innerHTML = renderSearchHighlightedText(text, searchTerm);
                            td.appendChild(span);
                        } else {
                            const span = document.createElement('span');
                            span.className = 'cell-text';
                            span.textContent = text;
                            td.appendChild(span);
                        }
                        ensureCopyButtonForCell(td, colName);
                    }
                } else {
                    ensureCopyButtonForCell(td, colName);
                }
                if (td && (colName === 'Recipient Details' || colName === 'Parcel Details' || colName === 'Tracking No.')) {
                    const rawText = td.getAttribute('data-search-raw-text');
                    if (rawText === null) {
                        const textEl = td.querySelector('.cell-text');
                        td.setAttribute('data-search-raw-text', (textEl ? textEl.textContent : td.textContent || '').trim());
                    }
                    const textEl = td.querySelector('.cell-text');
                    if (textEl) {
                        textEl.innerHTML = renderSearchHighlightedText(td.getAttribute('data-search-raw-text') || '', searchTerm);
                    }
                }
                if (td.hasAttribute('rowspan')) {
                    if (!td.hasAttribute('data-original-rowspan')) {
                        td.setAttribute('data-original-rowspan', td.getAttribute('rowspan') || '1');
                    }
                    td.removeAttribute('rowspan');
                }
                tr.insertBefore(td, actionCell);
            });
        }

        function ensureActionCellForSearch(tr, rowObj) {
            if (!tr) return null;
            const safeRowObj = rowObj || {};
            let actionCell = tr.querySelector('td.action-cell');
            if (!actionCell) {
                actionCell = document.createElement('td');
                actionCell.className = 'action-cell';
                actionCell.setAttribute('data-temp-action', '1');
                tr.appendChild(actionCell);
            }

            if (actionCell.hasAttribute('rowspan')) {
                if (!actionCell.hasAttribute('data-original-rowspan')) {
                    actionCell.setAttribute('data-original-rowspan', actionCell.getAttribute('rowspan') || '1');
                }
                actionCell.removeAttribute('rowspan');
            }

            if (actionCell.getAttribute('data-temp-action') === '1') {
                const rowTracking = ((safeRowObj['Tracking No.'] || safeRowObj['Tracking No'] || tr.dataset.trackingNo || '') + '').trim();
                const rowNotice = ((safeRowObj['Notice/Order Code'] || tr.dataset.notice || '') + '').trim();
                const rowStatus = ((safeRowObj['Status'] || '') + '').trim().toUpperCase();
                actionCell.innerHTML = '';

                if (rowTracking !== '' && rowTracking !== '0' && rowStatus === 'ONGOING DELIVERY') {
                    const label = document.createElement('span');
                    label.style.fontSize = '0.72rem';
                    label.style.color = '#22336A';
                    label.style.fontWeight = '700';
                    label.textContent = 'Auto Tracking';
                    actionCell.appendChild(label);

                    const result = document.createElement('div');
                    result.className = 'track-result';
                    actionCell.appendChild(result);
                } else if (rowTracking === '' || rowTracking === '0') {
                    const button = document.createElement('button');
                    button.type = 'button';
                    button.className = 'btn-scan';
                    button.style.display = 'inline-block';
                    button.style.textDecoration = 'none';
                    button.textContent = 'Scan';
                    button.addEventListener('click', function() {
                        openScannerModal(rowNotice, (safeRowObj && safeRowObj.id) ? safeRowObj.id : (tr.dataset.id || 0));
                    });
                    actionCell.appendChild(button);
                } else {
                    const note = document.createElement('span');
                    note.className = 'tracking-present-note';
                    note.textContent = 'Tracking recorded';
                    actionCell.appendChild(note);
                }
            }

            return actionCell;
        }

        function findRowByNoticeCode(noticeCode) {
            const safeNotice = (noticeCode || '').trim();
            if (!safeNotice) return null;
            return document.querySelector('tr[data-notice="' + CSS.escape(safeNotice) + '"]');
        }

        function findRowByTrackingNo(trackingNo) {
            const safeTracking = (trackingNo || '').trim();
            if (!safeTracking) return null;
            return document.querySelector('tr[data-tracking-no="' + CSS.escape(safeTracking) + '"]');
        }

        function findRowById(rowId) {
            const safeId = parseInt(rowId || '0', 10) || 0;
            if (safeId <= 0) return null;
            return document.querySelector('tr[data-id="' + String(safeId) + '"]');
        }

        function getBatchAwareCell(row, colName) {
            if (!row) return null;
            let cell = row.querySelector('td[data-col="' + CSS.escape(colName) + '"]');
            if (cell) return cell;

            const batchId = (row.dataset.batchId || '').trim();
            if (!batchId) return null;

            let probe = row.previousElementSibling;
            while (probe) {
                if ((probe.dataset.batchId || '').trim() !== batchId) break;
                cell = probe.querySelector('td[data-col="' + CSS.escape(colName) + '"]');
                if (cell) return cell;
                probe = probe.previousElementSibling;
            }
            return null;
        }

        function buildDefaultPdfFileNameFromRowElement(row) {
            if (!row) return '';
            const dateCell = getBatchAwareCell(row, 'Date released to AFD');
            const parcelCell = getBatchAwareCell(row, 'Parcel No.');
            const dateText = ((dateCell && dateCell.textContent) ? dateCell.textContent : '').trim();
            const parcelText = ((parcelCell && parcelCell.textContent) ? parcelCell.textContent : '').trim();
            const parsed = new Date(dateText);
            if (Number.isNaN(parsed.getTime())) return '';
            const yy = String(parsed.getFullYear()).slice(-2);
            const mm = String(parsed.getMonth() + 1).padStart(2, '0');
            const dd = String(parsed.getDate()).padStart(2, '0');
            const parcel = parseInt(parcelText, 10);
            const formattedParcel = String(Number.isFinite(parcel) ? parcel : 0).padStart(3, '0');
            return `${currentDeptCode}-${yy}${mm}${dd}-${formattedParcel}`;
        }

        function updateTrackingRow(noticeCode, data, options = {}) {
            const optionRowId = parseInt(options.rowId || '0', 10) || 0;
            const row = findRowByNoticeCode(noticeCode)
                || findRowById(optionRowId)
                || (((data && data.trackingNo) ? findRowByTrackingNo(data.trackingNo) : null));
            if (!row) return null;
            const suppressNotification = options.suppressNotification === true;
            const suppressStatsRefresh = options.suppressStatsRefresh === true;
            const resolvedNotice = ((row.dataset.notice || '').trim() || (noticeCode || '').trim());
            const resolvedRowId = parseInt(row.dataset.id || '0', 10) || 0;
            const resolvedTrackingNo = ((data && data.trackingNo) ? String(data.trackingNo) : ((row.dataset.trackingNo || '') + '')).trim();

            const dateCell = getBatchAwareCell(row, 'Date');
            const existingDateText = ((dateCell && dateCell.textContent) ? dateCell.textContent : '').trim();
            const nextDateText = (data.dateDisplay || formatDisplayDate(data.date || '') || existingDateText).trim();
            const statusCell = getBatchAwareCell(row, 'Status');
            let previousStatus = '';
            let nextStatus = '';
            if (statusCell && typeof data.status !== 'undefined') {
                previousStatus = ((statusCell.textContent || '') + '').trim().toUpperCase();
                nextStatus = ((data.status || '') + '').trim().toUpperCase();
                const statusClass = getStatusClass(data.status);
                statusCell.innerHTML = `<span class="${statusClass}">${data.status || ''}</span>`;
                if (!suppressNotification) {
                    maybeNotifyStatusChange(resolvedNotice, previousStatus, nextStatus, nextDateText, {
                        trackingNo: resolvedTrackingNo,
                        rowId: resolvedRowId
                    });
                }
                const snapshotKey = (resolvedRowId > 0)
                    ? ('id:' + String(resolvedRowId))
                    : (resolvedNotice ? ('notice:' + resolvedNotice) : (resolvedTrackingNo && resolvedTrackingNo !== '0' ? ('tracking:' + resolvedTrackingNo) : ''));
                if (snapshotKey) {
                    statusSnapshotByNotice.set(snapshotKey, {
                        status: nextStatus,
                        eventDate: nextDateText
                    });
                }
            }

            if (dateCell) {
                dateCell.textContent = nextDateText;
            }

            const transmittalCell = getBatchAwareCell(row, 'Transmittal Remarks/Received By');
            if (transmittalCell && typeof data.transmittalRemarks !== 'undefined') {
                transmittalCell.textContent = data.transmittalRemarks || '';
            }

            const fileCell = getBatchAwareCell(row, 'File Name (PDF)');
            if (fileCell && typeof data.fileNamePdf !== 'undefined') {
                const trackingNo = ((data.trackingNo || row.dataset.trackingNo || '') + '').trim();
                const proofAssetName = buildProofPdfAssetNameFromTracking(trackingNo);
                const defaultPdfName = buildDefaultPdfFileNameFromRowElement(row);
                const fileName = (((data.fileNamePdf || '') + '').trim() || defaultPdfName);
                fileCell.innerHTML = '';
                if (fileName !== '' && proofAssetName !== '') {
                    const pdfUrl = buildMainPdfUrl(trackingNo, row.dataset.transmittalId || '');
                    const link = document.createElement('a');
                    link.href = pdfUrl;
                    link.className = 'pdf-link-in-cell';
                    link.setAttribute('data-pdf-url', pdfUrl);
                    link.setAttribute('data-pdf-title', fileName);
                    link.textContent = fileName;
                    fileCell.appendChild(link);
                }
            }

            const rowId = parseInt(row.dataset.id || '0', 10) || 0;
            const cachedRow = mailRowIndexById.get(rowId) || mailRowIndexByNotice.get(resolvedNotice) || null;
            if (cachedRow) {
                if (typeof data.status !== 'undefined') {
                    cachedRow['Status'] = ((data.status || '') + '').trim();
                }
                if (typeof data.date !== 'undefined') {
                    cachedRow['Date'] = ((data.date || '') + '').trim();
                }
                if (typeof data.transmittalRemarks !== 'undefined') {
                    cachedRow['Transmittal Remarks/Received By'] = ((data.transmittalRemarks || '') + '').trim();
                }
                if (typeof data.fileNamePdf !== 'undefined') {
                    cachedRow['File Name (PDF)'] = ((data.fileNamePdf || '') + '').trim();
                }
            }

            if (!suppressStatsRefresh) {
                refreshStatisticsBar();
            }

            return {
                previousStatus: previousStatus,
                nextStatus: nextStatus,
                nextDateText: nextDateText
            };
        }

        function updateTrackingRowsByBatch(batchId, data) {
            const rows = document.querySelectorAll('tr[data-batch-id]');
            let notifyNotice = '';
            let notifyTracking = '';
            let notifyRowId = 0;
            let notifyDateText = '';
            let shouldNotifyBatch = false;
            const nextBatchStatus = ((data && data.status) ? data.status : '').trim().toUpperCase();

            rows.forEach(row => {
                if ((row.dataset.batchId || '').trim() === (batchId || '').trim()) {
                    const notice = (row.dataset.notice || '').trim();
                    if (notice) {
                        const result = updateTrackingRow(notice, data, { suppressNotification: true, suppressStatsRefresh: true });
                        if (!notifyNotice) {
                            notifyNotice = notice;
                        }
                        if (!notifyTracking) {
                            notifyTracking = ((data && data.trackingNo) ? String(data.trackingNo) : ((row.dataset.trackingNo || '') + '')).trim();
                        }
                        if (notifyRowId <= 0) {
                            notifyRowId = parseInt(row.dataset.id || '0', 10) || 0;
                        }
                        if (!notifyDateText && result && result.nextDateText) {
                            notifyDateText = result.nextDateText;
                        }
                        if (
                            result &&
                            isNotifiableStatus(result.nextStatus) &&
                            result.previousStatus !== result.nextStatus
                        ) {
                            shouldNotifyBatch = true;
                        }
                    }
                }
            });

            refreshStatisticsBar();

            if (shouldNotifyBatch && (notifyNotice || notifyTracking || notifyRowId > 0)) {
                maybeNotifyStatusChange(notifyNotice, '', nextBatchStatus, notifyDateText, {
                    displayTrackingId: getBatchNoticeCodesLabel(batchId) || ('Batch ' + batchId),
                    noticeCode: notifyNotice,
                    trackingNo: notifyTracking,
                    rowId: notifyRowId,
                    dedupeKey: 'batch:' + batchId + '|status:' + nextBatchStatus
                });
            }
        }

        function getBatchNoticeCodesLabel(batchId) {
            const safeBatchId = ((batchId || '') + '').trim();
            if (!safeBatchId) return '';
            const rows = document.querySelectorAll('tr[data-batch-id]');
            const seen = new Set();
            const notices = [];
            rows.forEach(function(row) {
                if (((row.dataset.batchId || '') + '').trim() !== safeBatchId) return;
                const notice = ((row.dataset.notice || '') + '').trim();
                if (!notice || seen.has(notice)) return;
                seen.add(notice);
                notices.push(notice);
            });
            return notices.join(', ');
        }



        // Table search and sort functionality (filter by Notice/Order Code, Recipient Details, Parcel Details, Tracking No., and year)
        // Keep checked rows visible regardless of filter, except when a status button filter is active.
        let selectedStatusFilter = 'ALL';

        function normalizeStatusFilterValue(rawValue) {
            const value = ((rawValue || '') + '').trim().toUpperCase();
            if (!value || value === 'TOTAL') return 'ALL';
            if (value === 'NDR') return 'NDR';
            if (value === 'DELIVERED') return 'DELIVERED';
            if (value === 'RETURNED TO SENDER') return 'RETURNED TO SENDER';
            if (value === 'ONGOING DELIVERY') return 'ONGOING DELIVERY';
            return 'ALL';
        }

        function updateStatusFilterButtonsUI() {
            const active = normalizeStatusFilterValue(selectedStatusFilter);
            document.querySelectorAll('.stat-filter-btn').forEach(function(btn) {
                const buttonFilter = normalizeStatusFilterValue(btn.getAttribute('data-status-filter'));
                const isActive = buttonFilter === active;
                btn.classList.toggle('stat-filter-active', isActive);
                btn.setAttribute('aria-pressed', isActive ? 'true' : 'false');
            });
        }

        function applyStatusFilterSelection(nextFilter) {
            selectedStatusFilter = normalizeStatusFilterValue(nextFilter);
            updateStatusFilterButtonsUI();
            requestTableSortAnimation();
            scheduleFilterTableRows(0);
        }

        function rowMatchesStatusFilter(statusText, normalizedFilter) {
            const status = ((statusText || '') + '').trim().toUpperCase();
            if (normalizedFilter === 'ALL') return true;
            if (normalizedFilter === 'NDR') {
                return status === 'RETURNED TO SENDER' || status === 'ONGOING DELIVERY';
            }
            return status === normalizedFilter;
        }

        let filterTableRowsTimer = null;
        let filterTableRowsRaf = null;
        let tableSortSignature = null;
        let tableSortAnimatePending = false;
        let tableSortAnimCleanupTimer = null;

        function requestTableSortAnimation() {
            tableSortAnimatePending = true;
        }

        function maybeAnimateTableSortRows(table, signature) {
            const isFirstRun = tableSortSignature === null;
            const signatureChanged = tableSortSignature !== signature;
            tableSortSignature = signature;

            if (isFirstRun || !signatureChanged || !tableSortAnimatePending) {
                tableSortAnimatePending = false;
                return;
            }

            tableSortAnimatePending = false;

            const visibleRows = Array.from(table.querySelectorAll('tbody tr[data-notice]')).filter(function(tr) {
                return tr.style.display !== 'none';
            });
            if (visibleRows.length === 0) return;

            visibleRows.forEach(function(tr) {
                tr.classList.remove('table-sort-row-enter');
                tr.style.removeProperty('--table-sort-row-delay');
            });

            void table.offsetWidth;

            visibleRows.forEach(function(tr, idx) {
                tr.style.setProperty('--table-sort-row-delay', String(Math.min(idx, 18) * 16) + 'ms');
                tr.classList.add('table-sort-row-enter');
            });

            if (tableSortAnimCleanupTimer) clearTimeout(tableSortAnimCleanupTimer);
            tableSortAnimCleanupTimer = setTimeout(function() {
                visibleRows.forEach(function(tr) {
                    tr.classList.remove('table-sort-row-enter');
                    tr.style.removeProperty('--table-sort-row-delay');
                });
            }, 560);
        }

        function scheduleFilterTableRows(delayMs) {
            const delay = Number.isFinite(delayMs) ? delayMs : 0;
            if (filterTableRowsTimer) clearTimeout(filterTableRowsTimer);
            if (filterTableRowsRaf) cancelAnimationFrame(filterTableRowsRaf);
            filterTableRowsTimer = setTimeout(function() {
                filterTableRowsTimer = null;
                filterTableRowsRaf = requestAnimationFrame(function() {
                    filterTableRowsRaf = null;
                    filterTableRows();
                });
            }, delay);
        }

        function filterTableRows() {
            const input = document.getElementById('tableSearchInput');
            const filter = input.value.toLowerCase();
            const yearSelect = document.getElementById('tableSortYear');
            const monthInput = document.getElementById('tableSortMonth');
            let selectedYear = yearSelect.value;
            if (selectedYear === 'all' || !selectedYear) selectedYear = '';
            let selectedMonth = monthInput ? ((monthInput.value || '') + '').trim() : '';
            if (!selectedYear) selectedMonth = '';
            const normalizedStatusFilter = normalizeStatusFilterValue(selectedStatusFilter);
            const hasStatusFilter = normalizedStatusFilter !== 'ALL';
            const activeTransmittal = (activeTransmittalId || '').trim();
            const sortSignature = [
                selectedYear || 'all',
                selectedMonth || 'all',
                normalizedStatusFilter || 'ALL',
                activeTransmittal || 'all'
            ].join('|');
            const hasTransmittalFilter = activeTransmittal !== '';
            const isFiltering = (filter !== '' || selectedYear !== '' || selectedMonth !== '' || hasStatusFilter || hasTransmittalFilter);
            // Preserve rowspan-based batch layout when using structured filters
            // (year/month/status/transmittal) and no free-text search.
            const hasStructuredFilter = (selectedYear !== '' || selectedMonth !== '' || hasStatusFilter || hasTransmittalFilter);
            const batchPreserveMode = (filter === '' && hasStructuredFilter);
            const table = document.querySelector('.admin-table-container table');
            if (!table) return;
            // Always restore previous search mutations before applying a new filter state.
            // This keeps rowspan-based batch layout stable across search/delete cycles.
            resetSearchViewMutations(table);
            const trs = table.querySelectorAll('tbody tr[data-notice]');
            const rowDataById = mailRowIndexById || new Map();
            const rowDataByNotice = mailRowIndexByNotice || new Map();
            const matchedBatchIds = new Set();

            Array.from(trs).forEach(function(tr) {
                const rowId = parseInt(tr.dataset.id || '0', 10) || 0;
                const notice = (tr.dataset.notice || '').trim();
                const batchId = (tr.dataset.batchId || '').trim();
                const cb = tr.querySelector('.row-checkbox');
                const isChecked = !!(cb && cb.checked);
                const rowObj = rowDataById.get(rowId) || rowDataByNotice.get(notice) || null;
                const rowStatus = ((rowObj && rowObj['Status']) ? String(rowObj['Status']) : '').trim();
                const statusMatch = rowMatchesStatusFilter(rowStatus, normalizedStatusFilter);
                applyNoticeCellSearchHighlight(tr, filter, rowObj);
                applyParcelAndTrackingSearchHighlights(tr, rowObj, filter);

                if (isChecked && !hasStatusFilter && !hasTransmittalFilter) {
                    tr.style.display = '';
                    if (isFiltering && !batchPreserveMode) {
                        rebuildVisibleRowCellsForSearch(tr, rowObj, filter);
                    }
                    return;
                }

                const recipientDetails = ((rowObj && rowObj['Recipient Details']) ? String(rowObj['Recipient Details']) : '').trim();
                const parcelDetails = ((rowObj && rowObj['Parcel Details']) ? String(rowObj['Parcel Details']) : '').trim();
                const trackingNo = ((rowObj && (rowObj['Tracking No.'] ?? rowObj['Tracking No'] ?? rowObj['tracking_no'] ?? rowObj['TrackingNo'])) ? String(rowObj['Tracking No.'] ?? rowObj['Tracking No'] ?? rowObj['tracking_no'] ?? rowObj['TrackingNo']) : (tr.dataset.trackingNo || '')).trim();
                const codeMatch = notice.toLowerCase().indexOf(filter) > -1;
                const recipientMatch = recipientDetails.toLowerCase().indexOf(filter) > -1;
                const parcelMatch = parcelDetails.toLowerCase().indexOf(filter) > -1;
                const trackingMatch = trackingNo.toLowerCase().indexOf(filter) > -1;
                const searchMatch = codeMatch || recipientMatch || parcelMatch || trackingMatch;
                const rowTransmittal = ((rowObj && rowObj['Transmittal ID']) ? String(rowObj['Transmittal ID']) : (tr.dataset.transmittalId || '')).trim();
                const transmittalMatch = !hasTransmittalFilter || rowTransmittal === activeTransmittal;

                let yearMatch = true;
                let monthMatch = true;
                if (selectedYear) {
                    const dateAfd = rowObj ? String(rowObj['Date released to AFD'] || '') : '';
                    const ym = extractYearMonthFromDateValue(dateAfd);
                    yearMatch = ym.year === selectedYear;
                    if (selectedMonth) {
                        monthMatch = ym.month === selectedMonth;
                    }
                }

                const visible = searchMatch && yearMatch && monthMatch && statusMatch && transmittalMatch;
                if (visible && batchPreserveMode && batchId !== '') {
                    matchedBatchIds.add(batchId);
                }
                tr.style.display = visible ? '' : 'none';
                if (visible && isFiltering && !batchPreserveMode) {
                    rebuildVisibleRowCellsForSearch(tr, rowObj, filter);
                }
            });

            // Structured filters should keep batch rows together.
            if (batchPreserveMode && matchedBatchIds.size > 0) {
                Array.from(trs).forEach(function(tr) {
                    const cb = tr.querySelector('.row-checkbox');
                    const isChecked = !!(cb && cb.checked);
                    if (isChecked) return;
                    const batchId = (tr.dataset.batchId || '').trim();
                    const rowId = parseInt(tr.dataset.id || '0', 10) || 0;
                    const notice = (tr.dataset.notice || '').trim();
                    const rowObj = rowDataById.get(rowId) || rowDataByNotice.get(notice) || null;
                    const rowTransmittal = ((rowObj && rowObj['Transmittal ID']) ? String(rowObj['Transmittal ID']) : (tr.dataset.transmittalId || '')).trim();
                    const transmittalMatch = !hasTransmittalFilter || rowTransmittal === activeTransmittal;
                    if (batchId && matchedBatchIds.has(batchId) && transmittalMatch) {
                        tr.style.display = '';
                    }
                });
            }

            if (hasTransmittalFilter || !hasTransmittalFilter) {
                const tbody = table.querySelector('tbody');
                if (tbody) {
                    const visibleRows = Array.from(trs).filter(function(tr) {
                        return tr.style.display !== 'none';
                    });
                    const transmittalGroups = new Map();
                    visibleRows.forEach(function(tr) {
                        const rowTransmittal = (tr.dataset.transmittalId || '').trim() || 'UNASSIGNED';
                        if (!transmittalGroups.has(rowTransmittal)) {
                            transmittalGroups.set(rowTransmittal, []);
                        }
                        transmittalGroups.get(rowTransmittal).push(tr);
                    });

                    const transmittalIds = Array.from(transmittalGroups.keys());
                    if (!hasTransmittalFilter) {
                        transmittalIds.sort(function(a, b) {
                            return a.toLowerCase().localeCompare(b.toLowerCase());
                        });
                    }

                    transmittalIds.forEach(function(tid) {
                        const groupRows = transmittalGroups.get(tid) || [];
                        groupRows.sort(function(a, b) {
                            const aId = parseInt(a.dataset.id || '0', 10) || 0;
                            const bId = parseInt(b.dataset.id || '0', 10) || 0;
                            const aNotice = (a.dataset.notice || '').trim();
                            const bNotice = (b.dataset.notice || '').trim();
                            const aObj = rowDataById.get(aId) || rowDataByNotice.get(aNotice) || null;
                            const bObj = rowDataById.get(bId) || rowDataByNotice.get(bNotice) || null;
                            const aParcel = parseInt(((aObj && aObj['Parcel No.']) ? aObj['Parcel No.'] : ''), 10);
                            const bParcel = parseInt(((bObj && bObj['Parcel No.']) ? bObj['Parcel No.'] : ''), 10);
                            const aVal = Number.isFinite(aParcel) ? aParcel : Number.MAX_SAFE_INTEGER;
                            const bVal = Number.isFinite(bParcel) ? bParcel : Number.MAX_SAFE_INTEGER;
                            if (aVal !== bVal) return aVal - bVal;
                            return aId - bId;
                        });
                        groupRows.forEach(function(tr) { tbody.appendChild(tr); });
                    });
                }
            }

            maybeAnimateTableSortRows(table, sortSignature);
            refreshStatisticsBar();
        }

        function padTransmittalNum(value, size) {
            const raw = String(value || '');
            if (raw.length >= size) return raw;
            return ('000000' + raw).slice(-size);
        }

        function parseTransmittalIdParts(id) {
            const raw = (id || '').trim();
            const match = /^TR-(\d{8})-(\d{3})$/i.exec(raw);
            if (!match) return null;
            const ymd = match[1];
            const seq = parseInt(match[2], 10) || 0;
            const year = parseInt(ymd.slice(0, 4), 10) || 0;
            const month = parseInt(ymd.slice(4, 6), 10) || 0;
            const day = parseInt(ymd.slice(6, 8), 10) || 0;
            if (!year || !month || !day) return null;
            return { id: raw, ymd: ymd, seq: seq, year: year, month: month, day: day };
        }

        function formatTransmittalDisplayName(id) {
            const parts = parseTransmittalIdParts(id);
            if (!parts) return id;
            const dateObj = new Date(parts.year, parts.month - 1, parts.day);
            if (Number.isNaN(dateObj.getTime())) return id;
            const base = dateObj.toLocaleDateString('en-US', {
                year: 'numeric',
                month: 'long',
                day: 'numeric'
            });
            return parts.seq > 1 ? (base + ' #' + parts.seq) : base;
        }

        function collectTransmittalSummary(rows) {
            const summary = new Map();
            rows.forEach(function(r) {
                const tid = ((r && r['Transmittal ID']) ? String(r['Transmittal ID']) : '').trim();
                if (!tid) return;
                summary.set(tid, (summary.get(tid) || 0) + 1);
            });
            pendingTransmittals.forEach(function(tid) {
                if (!summary.has(tid)) summary.set(tid, 0);
            });
            return summary;
        }

        function sortTransmittalIds(ids) {
            return ids.sort(function(a, b) {
                const pa = parseTransmittalIdParts(a);
                const pb = parseTransmittalIdParts(b);
                if (pa && pb) {
                    if (pa.ymd !== pb.ymd) return pb.ymd.localeCompare(pa.ymd);
                    return (pb.seq || 0) - (pa.seq || 0);
                }
                if (pa) return -1;
                if (pb) return 1;
                return a.localeCompare(b);
            });
        }

        function getTransmittalFilterSelection() {
            const yearSelect = document.getElementById('transmittalSortYear');
            const monthInput = document.getElementById('transmittalSortMonth');
            if (!yearSelect || !monthInput) {
                return { year: '', month: '' };
            }
            const yearRaw = ((yearSelect.value || '') + '').trim();
            const year = (yearRaw === 'all') ? '' : yearRaw;
            let month = ((monthInput.value || '') + '').trim();
            if (!year) month = '';
            return { year: year, month: month };
        }

        function syncTransmittalYearMonthFilterUI() {
            const yearSelect = document.getElementById('transmittalSortYear');
            const monthInput = document.getElementById('transmittalSortMonth');
            const yearLabel = document.getElementById('transmittalSortYearLabel');
            const yearTrigger = document.getElementById('transmittalSortYearTrigger');
            const monthGrid = document.getElementById('transmittalMonthGrid');
            if (!yearSelect || !monthInput) return;

            const selectedYearRaw = ((yearSelect.value || '') + '').trim();
            const selectedYear = (selectedYearRaw === 'all') ? '' : selectedYearRaw;
            let selectedMonth = ((monthInput.value || '') + '').trim();

            if (!selectedYear) {
                selectedMonth = '';
                monthInput.value = '';
            }

            if (yearLabel) {
                const monthLabel = selectedMonth ? (' - ' + getMonthShortLabel(selectedMonth)) : '';
                yearLabel.textContent = selectedYear ? (selectedYear + monthLabel) : 'Year';
            }
            if (yearTrigger) {
                yearTrigger.classList.toggle('has-selection', !!selectedYear);
            }

            document.querySelectorAll('.transmittal-year-option').forEach(function(btn) {
                const y = ((btn.getAttribute('data-year') || '') + '').trim();
                const isActive = y === (selectedYear || 'all');
                btn.classList.toggle('is-active', isActive);
            });

            if (monthGrid) {
                const monthsEnabled = !!selectedYear;
                monthGrid.classList.toggle('is-enabled', monthsEnabled);
                monthGrid.setAttribute('aria-hidden', monthsEnabled ? 'false' : 'true');
                monthGrid.querySelectorAll('.transmittal-month-option').forEach(function(btn) {
                    const m = ((btn.getAttribute('data-month') || '') + '').trim();
                    btn.disabled = !monthsEnabled;
                    btn.classList.toggle('is-active', monthsEnabled && m === selectedMonth);
                });
            }
        }

        function rebuildTransmittalYearMonthFilterOptionsFromSelect() {
            const yearSelect = document.getElementById('transmittalSortYear');
            const yearList = document.getElementById('transmittalYearList');
            if (!yearSelect || !yearList) {
                syncTransmittalYearMonthFilterUI();
                return;
            }
            const activeValue = ((yearSelect.value || '') + '').trim() || 'all';
            const buttons = [];
            Array.from(yearSelect.options).forEach(function(opt) {
                const value = ((opt.value || '') + '').trim();
                if (!value || value === '') return;
                const label = (opt.textContent || '').trim() || value;
                buttons.push(
                    '<button type="button" class="transmittal-year-option' + (value === activeValue ? ' is-active' : '') + '" data-year="' +
                    value.replace(/"/g, '&quot;') + '">' + label.replace(/</g, '&lt;') + '</button>'
                );
            });
            yearList.innerHTML = buttons.join('');
            syncTransmittalYearMonthFilterUI();
        }

        function closeTransmittalYearMonthDropdown() {
            const dropdown = document.getElementById('transmittalYearMonthDropdown');
            const trigger = document.getElementById('transmittalSortYearTrigger');
            if (dropdown) dropdown.hidden = true;
            if (trigger) trigger.setAttribute('aria-expanded', 'false');
        }

        function openTransmittalYearMonthDropdown() {
            const dropdown = document.getElementById('transmittalYearMonthDropdown');
            const trigger = document.getElementById('transmittalSortYearTrigger');
            if (dropdown) dropdown.hidden = false;
            if (trigger) trigger.setAttribute('aria-expanded', 'true');
        }

        function updateTransmittalYearSelectOptionsFromIds(ids) {
            const yearSelect = document.getElementById('transmittalSortYear');
            const monthInput = document.getElementById('transmittalSortMonth');
            if (!yearSelect) return;

            const previousYearValue = ((yearSelect.value || '') + '').trim() || 'all';
            const previousMonthValue = monthInput ? ((monthInput.value || '') + '').trim() : '';
            const yearSet = new Set();

            (Array.isArray(ids) ? ids : []).forEach(function(tid) {
                const parts = parseTransmittalIdParts(tid);
                if (!parts || !parts.year) return;
                yearSet.add(String(parts.year));
            });

            const years = Array.from(yearSet).sort(function(a, b) { return b.localeCompare(a); });
            const opts = ['<option value="" disabled hidden>Year</option>', '<option value="all">All</option>'];
            years.forEach(function(y) {
                const yy = ((y || '') + '').trim();
                if (yy) {
                    opts.push('<option value="' + yy.replace(/"/g, '&quot;') + '">' + yy.replace(/</g, '&lt;') + '</option>');
                }
            });
            yearSelect.innerHTML = opts.join('');

            const hasPrevious = Array.from(yearSelect.options).some(function(opt) {
                return ((opt.value || '') + '').trim() === previousYearValue;
            });
            if (hasPrevious) {
                yearSelect.value = previousYearValue;
                if (monthInput) monthInput.value = previousMonthValue;
            } else {
                yearSelect.value = 'all';
                if (monthInput) monthInput.value = '';
            }
            rebuildTransmittalYearMonthFilterOptionsFromSelect();
        }

        var activeTransmittalMenuId = '';
        function closeTransmittalTileMenus() {
            activeTransmittalMenuId = '';
            document.querySelectorAll('.transmittal-tile-wrap.menu-open').forEach(function(wrap) {
                wrap.classList.remove('menu-open');
                const menu = wrap.querySelector('.transmittal-tile-menu');
                if (menu) menu.hidden = true;
            });
        }

        function toggleTransmittalTileMenu(transmittalId, wrap) {
            const safeId = ((transmittalId || '') + '').trim();
            if (!wrap || !safeId) return;
            const menu = wrap.querySelector('.transmittal-tile-menu');
            if (!menu) return;
            const isOpen = wrap.classList.contains('menu-open') && activeTransmittalMenuId === safeId;
            closeTransmittalTileMenus();
            if (isOpen) return;
            activeTransmittalMenuId = safeId;
            wrap.classList.add('menu-open');
            menu.hidden = false;
        }

        function updateTransmittalGrid() {
            const grid = document.getElementById('transmittalGrid');
            if (!grid) return;
            activeTransmittalMenuId = '';
            const rows = Array.isArray(window.mailRows) ? window.mailRows : [];
            const summary = collectTransmittalSummary(rows);
            const ids = sortTransmittalIds(Array.from(summary.keys()));
            updateTransmittalYearSelectOptionsFromIds(ids);
            const transmittalFilter = getTransmittalFilterSelection();
            const filteredIds = ids.filter(function(tid) {
                if (!transmittalFilter.year && !transmittalFilter.month) return true;
                const parts = parseTransmittalIdParts(tid);
                if (!parts) return false;
                const yearMatch = !transmittalFilter.year || String(parts.year) === transmittalFilter.year;
                const monthMatch = !transmittalFilter.month || padTransmittalNum(parts.month, 2) === transmittalFilter.month;
                return yearMatch && monthMatch;
            });

            // Remove pending entries that now exist in DB.
            pendingTransmittals.forEach(function(tid) {
                if (summary.has(tid) && summary.get(tid) > 0) {
                    pendingTransmittals.delete(tid);
                }
            });

            grid.innerHTML = '';

            const addTile = document.createElement('button');
            addTile.type = 'button';
            addTile.className = 'transmittal-tile transmittal-tile-new';
            addTile.setAttribute('aria-label', 'Add new transmittal');

            const addFolder = document.createElement('div');
            addFolder.className = 'transmittal-folder transmittal-folder-new';

            const addBadge = document.createElement('span');
            addBadge.className = 'transmittal-folder-add';
            addBadge.setAttribute('aria-hidden', 'true');
            const addBadgeIcon = document.createElement('img');
            addBadgeIcon.src = '../assets/plus.svg';
            addBadgeIcon.alt = '';
            addBadge.appendChild(addBadgeIcon);
            addFolder.appendChild(addBadge);

            const addName = document.createElement('div');
            addName.className = 'transmittal-name';
            addName.textContent = 'New';

            const addMeta = document.createElement('div');
            addMeta.className = 'transmittal-meta';
            addMeta.textContent = 'Add transmittal';

            addTile.appendChild(addFolder);
            addTile.appendChild(addName);
            addTile.appendChild(addMeta);
            addTile.addEventListener('click', function() {
                const newId = generateNewTransmittalId();
                pendingTransmittals.add(newId);
                openTransmittalDetail(newId, addTile);
            });
            const addTileWrap = document.createElement('div');
            addTileWrap.className = 'transmittal-tile-wrap transmittal-tile-wrap-new';
            addTileWrap.appendChild(addTile);
            grid.appendChild(addTileWrap);

            if (filteredIds.length === 0) {
                const empty = document.createElement('div');
                empty.className = 'transmittal-empty';
                empty.textContent = (ids.length === 0) ? 'No transmittals yet.' : 'No transmittals for selected period.';
                grid.appendChild(empty);
                updateTransmittalNavButtons();
                return;
            }

            filteredIds.forEach(function(tid) {
                const tileWrap = document.createElement('div');
                tileWrap.className = 'transmittal-tile-wrap';
                tileWrap.setAttribute('data-transmittal-id', tid);

                const tile = document.createElement('button');
                tile.type = 'button';
                tile.className = 'transmittal-tile';
                tile.setAttribute('data-transmittal-id', tid);

                const folder = document.createElement('div');
                folder.className = 'transmittal-folder';

                const name = document.createElement('div');
                name.className = 'transmittal-name';
                name.textContent = formatTransmittalDisplayName(tid);

                const count = document.createElement('div');
                count.className = 'transmittal-meta';
                const qty = summary.get(tid) || 0;
                count.textContent = qty + (qty === 1 ? ' item' : ' items');

                tile.appendChild(folder);
                tile.appendChild(name);
                tile.appendChild(count);
                tile.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    closeTransmittalTileMenus();
                    openTransmittalDetail(tid, tile);
                });

                const menuBtn = document.createElement('button');
                menuBtn.type = 'button';
                menuBtn.className = 'transmittal-tile-menu-btn';
                menuBtn.setAttribute('aria-label', 'Transmittal options');
                menuBtn.setAttribute('title', 'Options');
                menuBtn.innerHTML = '<span aria-hidden="true">&#8942;</span>';
                menuBtn.addEventListener('click', function(event) {
                    event.preventDefault();
                    event.stopPropagation();
                    toggleTransmittalTileMenu(tid, tileWrap);
                });

                const menu = document.createElement('div');
                menu.className = 'transmittal-tile-menu';
                menu.hidden = true;

                const exportBtn = document.createElement('button');
                exportBtn.type = 'button';
                exportBtn.className = 'transmittal-tile-menu-item';
                exportBtn.innerHTML = '<img src="../assets/export.svg" alt="" class="transmittal-tile-menu-icon" aria-hidden="true"><span>Export</span>';
                exportBtn.addEventListener('click', function(event) {
                    event.preventDefault();
                    event.stopPropagation();
                    closeTransmittalTileMenus();
                    exportTransmittalById(tid);
                });

                const exportExcelBtn = document.createElement('button');
                exportExcelBtn.type = 'button';
                exportExcelBtn.className = 'transmittal-tile-menu-item';
                exportExcelBtn.innerHTML = '<img src="../assets/export.svg" alt="" class="transmittal-tile-menu-icon" aria-hidden="true"><span>Export as Excel</span>';
                exportExcelBtn.addEventListener('click', function(event) {
                    event.preventDefault();
                    event.stopPropagation();
                    closeTransmittalTileMenus();
                    exportTransmittalToExcel(tid);
                });

                const deleteBtn = document.createElement('button');
                deleteBtn.type = 'button';
                deleteBtn.className = 'transmittal-tile-menu-item transmittal-tile-menu-item-danger';
                deleteBtn.innerHTML = '<img src="../assets/Delete_Icon.svg" alt="" class="transmittal-tile-menu-icon" aria-hidden="true"><span>Delete</span>';
                deleteBtn.addEventListener('click', function(event) {
                    event.preventDefault();
                    event.stopPropagation();
                    closeTransmittalTileMenus();
                    deleteTransmittalById(tid);
                });

                menu.appendChild(exportBtn);
                menu.appendChild(exportExcelBtn);
                menu.appendChild(deleteBtn);

                tileWrap.appendChild(tile);
                tileWrap.appendChild(menuBtn);
                tileWrap.appendChild(menu);
                grid.appendChild(tileWrap);
            });

            updateTransmittalNavButtons();
        }

        function getTransmittalIdsForNavigation() {
            const rows = Array.isArray(window.mailRows) ? window.mailRows : [];
            const summary = collectTransmittalSummary(rows);
            const idSet = new Set(Array.from(summary.keys()));

            pendingTransmittals.forEach(function(tid) {
                const safeTid = ((tid || '') + '').trim();
                if (safeTid) idSet.add(safeTid);
            });

            const activeId = ((activeTransmittalId || '') + '').trim();
            if (activeId) idSet.add(activeId);

            return sortTransmittalIds(Array.from(idSet));
        }

        function updateTransmittalNavButtons() {
            const prevBtn = document.getElementById('transmittalPrevBtn');
            const nextBtn = document.getElementById('transmittalNextBtn');
            if (!prevBtn || !nextBtn) return;

            const activeId = ((activeTransmittalId || '') + '').trim();
            if (!activeId) {
                prevBtn.disabled = true;
                nextBtn.disabled = true;
                return;
            }

            const ids = getTransmittalIdsForNavigation();
            const index = ids.indexOf(activeId);
            if (index === -1) {
                prevBtn.disabled = true;
                nextBtn.disabled = true;
                return;
            }

            prevBtn.disabled = (index <= 0);
            nextBtn.disabled = (index >= ids.length - 1);
        }

        function openAdjacentTransmittal(step) {
            const activeId = ((activeTransmittalId || '') + '').trim();
            if (!activeId) return;

            const ids = getTransmittalIdsForNavigation();
            const index = ids.indexOf(activeId);
            if (index === -1) return;

            const nextIndex = index + (step > 0 ? 1 : -1);
            if (nextIndex < 0 || nextIndex >= ids.length) return;

            openTransmittalDetail(ids[nextIndex], null, {
                navDirection: step > 0 ? 'next' : 'prev'
            });
        }

        function generateNewTransmittalId() {
            const now = new Date();
            const ymd = String(now.getFullYear()) + padTransmittalNum(now.getMonth() + 1, 2) + padTransmittalNum(now.getDate(), 2);
            let maxSeq = 0;
            const rows = Array.isArray(window.mailRows) ? window.mailRows : [];
            rows.forEach(function(r) {
                const tid = ((r && r['Transmittal ID']) ? String(r['Transmittal ID']) : '').trim();
                const parts = parseTransmittalIdParts(tid);
                if (parts && parts.ymd === ymd) {
                    if (parts.seq > maxSeq) maxSeq = parts.seq;
                }
            });
            pendingTransmittals.forEach(function(tid) {
                const parts = parseTransmittalIdParts(tid);
                if (parts && parts.ymd === ymd) {
                    if (parts.seq > maxSeq) maxSeq = parts.seq;
                }
            });
            const nextSeq = maxSeq + 1;
            return 'TR-' + ymd + '-' + padTransmittalNum(nextSeq, 3);
        }

        let defaultTopBarTitle = '';
        const defaultDeptTopBarTitle = `${currentDeptCode} MAIL TRACKING RECORDS`;
        function setTopBarTitle(nextTitle) {
            const topTitle = document.querySelector('.top-bar-title');
            const headbarTitle = document.getElementById('transmittalTableBarTitle');
            if (topTitle) {
                if (!defaultTopBarTitle) {
                    defaultTopBarTitle = (topTitle.textContent || '').trim() || defaultDeptTopBarTitle;
                }
                topTitle.textContent = nextTitle || defaultTopBarTitle;
            }
            if (headbarTitle) {
                headbarTitle.textContent = nextTitle || '';
            }
        }

        function clampPercent(value) {
            if (!Number.isFinite(value)) return 0;
            if (value < 0) return 0;
            if (value > 100) return 100;
            return value;
        }

        function setTransmittalAnimOrigin(xPercent, yPercent) {
            const container = document.querySelector('.admin-table-container');
            if (!container) return;
            if (!Number.isFinite(xPercent) || !Number.isFinite(yPercent)) {
                container.style.removeProperty('--transmittal-origin-x');
                container.style.removeProperty('--transmittal-origin-y');
                return;
            }
            container.style.setProperty('--transmittal-origin-x', clampPercent(xPercent).toFixed(2) + '%');
            container.style.setProperty('--transmittal-origin-y', clampPercent(yPercent).toFixed(2) + '%');
        }

        function setTransmittalAnimOriginFromElement(el) {
            const container = document.querySelector('.admin-table-container');
            if (!container || !el || typeof el.getBoundingClientRect !== 'function') {
                setTransmittalAnimOrigin(NaN, NaN);
                return;
            }
            const containerRect = container.getBoundingClientRect();
            const elRect = el.getBoundingClientRect();
            if (containerRect.width <= 0 || containerRect.height <= 0) {
                setTransmittalAnimOrigin(NaN, NaN);
                return;
            }
            const x = ((elRect.left + elRect.width / 2) - containerRect.left) / containerRect.width * 100;
            const y = ((elRect.top + elRect.height / 2) - containerRect.top) / containerRect.height * 100;
            setTransmittalAnimOrigin(x, y);
        }

        let transmittalAnimTimer = null;
        let transmittalSlideAnimTimer = null;
        let transmittalModeAnimTimer = null;
        let currentTransmittalView = 'none';

        function triggerTransmittalModeAnimation(nextView) {
            const container = document.querySelector('.admin-table-container');
            if (!container) return;

            let animValue = '';
            if (nextView === 'grid') {
                animValue = 'grid';
            } else if (nextView === 'none') {
                animValue = 'table';
            }

            if (!animValue) return;
            container.setAttribute('data-module-anim', animValue);
            if (transmittalModeAnimTimer) clearTimeout(transmittalModeAnimTimer);
            transmittalModeAnimTimer = setTimeout(function() {
                container.removeAttribute('data-module-anim');
            }, 280);
        }

        function triggerTransmittalDetailAnimation() {
            const container = document.querySelector('.admin-table-container');
            if (!container) return;
            container.setAttribute('data-transmittal-anim', 'detail');
            if (transmittalAnimTimer) clearTimeout(transmittalAnimTimer);
            transmittalAnimTimer = setTimeout(function() {
                container.removeAttribute('data-transmittal-anim');
            }, 320);
        }

        function triggerTransmittalSlideAnimation(direction) {
            const container = document.querySelector('.admin-table-container');
            if (!container) return;
            const safeDirection = ((direction || '') + '').trim().toLowerCase();
            if (safeDirection !== 'next' && safeDirection !== 'prev') return;
            container.setAttribute('data-transmittal-slide', safeDirection);
            if (transmittalSlideAnimTimer) clearTimeout(transmittalSlideAnimTimer);
            transmittalSlideAnimTimer = setTimeout(function() {
                container.removeAttribute('data-transmittal-slide');
            }, 560);
        }

        function setTransmittalView(view, options) {
            const container = document.querySelector('.admin-table-container');
            const manager = document.getElementById('transmittalManager');
            const transBtn = document.querySelector('.transmittal-btn');
            const backBtn = document.getElementById('transmittalBackToListBtn');
            const addBtn = document.getElementById('addTransmittalBtn');
            closeTransmittalTileMenus();
            const nextView = (!view || view === 'none') ? 'none' : view;
            const previousView = currentTransmittalView;
            const shouldAnimate = !(options && options.animate === false);
            const isTransmittalGrid = (view === 'grid');
            if (container) {
                if (!view || view === 'none') {
                    container.removeAttribute('data-transmittal-view');
                } else {
                    container.setAttribute('data-transmittal-view', view);
                }
            }
            if (manager) {
                if (!view || view === 'none') {
                    manager.style.display = 'none';
                    manager.removeAttribute('data-view');
                } else {
                    manager.style.display = 'flex';
                    manager.setAttribute('data-view', view);
                }
            }
            if (transBtn) {
                if (!view || view === 'none') {
                    transBtn.setAttribute('aria-label', 'Table Module');
                    transBtn.setAttribute('aria-pressed', 'true');
                    transBtn.classList.add('module-active');
                } else {
                    transBtn.setAttribute('aria-label', 'Table Module');
                    transBtn.setAttribute('aria-pressed', 'false');
                    transBtn.classList.remove('module-active');
                }
            }
            if (backBtn) {
                backBtn.style.display = 'inline-flex';
                backBtn.classList.toggle('module-active', isTransmittalGrid);
                backBtn.setAttribute('aria-pressed', isTransmittalGrid ? 'true' : 'false');
                backBtn.setAttribute('aria-label', 'Transmittal Module');
            }
            currentTransmittalView = nextView;
            if (shouldAnimate && previousView !== nextView) {
                triggerTransmittalModeAnimation(nextView);
            }
            updateTransmittalNavButtons();
            refreshStatisticsBar();
        }

        function openTransmittalGrid() {
            activeTransmittalId = '';
            setTransmittalView('grid');
            setTopBarTitle('Transmittals');
            updateTransmittalGrid();
            scheduleFilterTableRows(0);
        }

        function openTransmittalDetail(transmittalId, originEl, options) {
            const safeId = (transmittalId || '').trim();
            if (!safeId) return;
            const navDirection = ((options && options.navDirection) ? String(options.navDirection) : '').trim().toLowerCase();
            activeTransmittalId = safeId;
            setTransmittalAnimOriginFromElement(originEl);
            setTransmittalView('detail');
            setTopBarTitle(formatTransmittalDisplayName(safeId) + ' Transmittal');
            if (navDirection === 'next' || navDirection === 'prev') {
                triggerTransmittalSlideAnimation(navDirection);
            } else {
                triggerTransmittalDetailAnimation();
            }
            scheduleFilterTableRows(0);
        }

        function exitTransmittalMode() {
            activeTransmittalId = '';
            setTransmittalView('none');
            setTopBarTitle('');
            scheduleFilterTableRows(0);
        }
        document.addEventListener('DOMContentLoaded', function() {
            annotateInlineEditableCells(document);
            var tableScrollArea = document.querySelector('.admin-table-container .table-scroll-area');
            if (tableScrollArea) {
                tableScrollArea.addEventListener('dblclick', handleTableCellDoubleClick);
            }
            document.addEventListener('click', function(e) {
                var dropdown = document.getElementById('rowMenuDropdown');
                if (!dropdown) return;
                var isMenuClick = dropdown.contains(e.target);
                var isButtonClick = e.target.closest && e.target.closest('.row-menu-btn');
                if (!isMenuClick && !isButtonClick) {
                    hideRowMenuDropdown();
                }
            });
            document.addEventListener('click', function(e) {
                const inTileMenu = e.target.closest && e.target.closest('.transmittal-tile-menu, .transmittal-tile-menu-btn');
                if (!inTileMenu) {
                    closeTransmittalTileMenus();
                }
            });
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    closeTransmittalTileMenus();
                }
            });
            window.addEventListener('scroll', hideRowMenuDropdown, true);
            window.addEventListener('resize', hideRowMenuDropdown);
            // Search and sort bar events
            const searchInput = document.getElementById('tableSearchInput');
            const searchBtn = document.getElementById('tableSearchBtn');
            const yearSelect = document.getElementById('tableSortYear');
            const monthInput = document.getElementById('tableSortMonth');
            const yearTrigger = document.getElementById('tableSortYearTrigger');
            const yearMonthFilterWrap = document.getElementById('tableYearMonthFilter');
            const yearList = document.getElementById('tableYearList');
            const monthGrid = document.getElementById('tableMonthGrid');
            const transYearSelect = document.getElementById('transmittalSortYear');
            const transMonthInput = document.getElementById('transmittalSortMonth');
            const transYearTrigger = document.getElementById('transmittalSortYearTrigger');
            const transYearMonthFilterWrap = document.getElementById('transmittalYearMonthFilter');
            const transYearList = document.getElementById('transmittalYearList');
            const transMonthGrid = document.getElementById('transmittalMonthGrid');
            updateStatusFilterButtonsUI();
            rebuildYearMonthFilterOptionsFromSelect();
            rebuildTransmittalYearMonthFilterOptionsFromSelect();
            setTransmittalView('none', { animate: false });
            searchInput.addEventListener('input', function() {
                scheduleFilterTableRows(150);
            });
            searchBtn.addEventListener('click', function(e) {
                e.preventDefault();
                scheduleFilterTableRows(0);
            });
            yearSelect.addEventListener('change', function() {
                if (yearSelect.value === 'all' && monthInput) {
                    monthInput.value = '';
                }
                syncYearMonthFilterUI();
                requestTableSortAnimation();
                scheduleFilterTableRows(0);
            });
            if (yearTrigger) {
                yearTrigger.addEventListener('click', function(e) {
                    e.preventDefault();
                    const dropdown = document.getElementById('tableYearMonthDropdown');
                    if (!dropdown || dropdown.hidden) {
                        openYearMonthDropdown();
                    } else {
                        closeYearMonthDropdown();
                    }
                });
            }
            if (yearList) {
                yearList.addEventListener('click', function(e) {
                    const btn = e.target.closest ? e.target.closest('.table-year-option') : null;
                    if (!btn) return;
                    e.preventDefault();
                    const yearValue = ((btn.getAttribute('data-year') || '') + '').trim() || 'all';
                    yearSelect.value = yearValue;
                    if (yearValue === 'all' && monthInput) {
                        monthInput.value = '';
                    }
                    syncYearMonthFilterUI();
                    requestTableSortAnimation();
                    scheduleFilterTableRows(0);
                });
            }
            if (monthGrid) {
                monthGrid.addEventListener('click', function(e) {
                    const btn = e.target.closest ? e.target.closest('.table-month-option') : null;
                    if (!btn || btn.disabled) return;
                    e.preventDefault();
                    const yearValue = ((yearSelect.value || '') + '').trim();
                    if (!yearValue || yearValue === 'all') return;
                    const monthValue = ((btn.getAttribute('data-month') || '') + '').trim();
                    if (monthInput) {
                        monthInput.value = (monthInput.value === monthValue) ? '' : monthValue;
                    }
                    syncYearMonthFilterUI();
                    requestTableSortAnimation();
                    scheduleFilterTableRows(0);
                    closeYearMonthDropdown();
                });
            }
            if (transYearSelect) {
                transYearSelect.addEventListener('change', function() {
                    if (transYearSelect.value === 'all' && transMonthInput) {
                        transMonthInput.value = '';
                    }
                    syncTransmittalYearMonthFilterUI();
                    updateTransmittalGrid();
                });
            }
            if (transYearTrigger) {
                transYearTrigger.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    const dropdown = document.getElementById('transmittalYearMonthDropdown');
                    if (!dropdown || dropdown.hidden) {
                        openTransmittalYearMonthDropdown();
                    } else {
                        closeTransmittalYearMonthDropdown();
                    }
                });
            }
            if (transYearList) {
                transYearList.addEventListener('click', function(e) {
                    const btn = e.target.closest ? e.target.closest('.transmittal-year-option') : null;
                    if (!btn || !transYearSelect) return;
                    e.preventDefault();
                    e.stopPropagation();
                    const yearValue = ((btn.getAttribute('data-year') || '') + '').trim() || 'all';
                    transYearSelect.value = yearValue;
                    if (yearValue === 'all' && transMonthInput) {
                        transMonthInput.value = '';
                    }
                    syncTransmittalYearMonthFilterUI();
                    updateTransmittalGrid();
                    if (yearValue === 'all') {
                        closeTransmittalYearMonthDropdown();
                    } else {
                        openTransmittalYearMonthDropdown();
                    }
                });
            }
            if (transMonthGrid) {
                transMonthGrid.addEventListener('click', function(e) {
                    const btn = e.target.closest ? e.target.closest('.transmittal-month-option') : null;
                    if (!btn || btn.disabled || !transYearSelect) return;
                    e.preventDefault();
                    e.stopPropagation();
                    const yearValue = ((transYearSelect.value || '') + '').trim();
                    if (!yearValue || yearValue === 'all') return;
                    const monthValue = ((btn.getAttribute('data-month') || '') + '').trim();
                    if (transMonthInput) {
                        transMonthInput.value = (transMonthInput.value === monthValue) ? '' : monthValue;
                    }
                    syncTransmittalYearMonthFilterUI();
                    updateTransmittalGrid();
                    closeTransmittalYearMonthDropdown();
                });
            }
            document.addEventListener('click', function(e) {
                if (!yearMonthFilterWrap) return;
                if (yearMonthFilterWrap.contains(e.target)) return;
                closeYearMonthDropdown();
            });
            document.addEventListener('click', function(e) {
                if (!transYearMonthFilterWrap) return;
                if (transYearMonthFilterWrap.contains(e.target)) return;
                closeTransmittalYearMonthDropdown();
            });
            document.addEventListener('click', function(e) {
                const btn = e.target.closest ? e.target.closest('.stat-filter-btn') : null;
                if (!btn) return;
                e.preventDefault();
                applyStatusFilterSelection(btn.getAttribute('data-status-filter') || 'ALL');
            });
            bindRowCheckboxListeners();
            rebuildMailRowsFromTable();
            rebuildStatusSnapshotFromMailRows();
            refreshStatisticsBar();
            focusScannedRow();
            autoTrackEligibleRows();
            initSmartPolling();

            const transmittalBtn = document.querySelector('.transmittal-btn');
            if (transmittalBtn) {
                transmittalBtn.addEventListener('click', function() {
                    exitTransmittalMode();
                });
            }

            const transmittalBackBtn = document.getElementById('transmittalBackToListBtn');
            if (transmittalBackBtn) {
                transmittalBackBtn.addEventListener('click', function() {
                    openTransmittalGrid();
                });
            }

            const transmittalPrevBtn = document.getElementById('transmittalPrevBtn');
            if (transmittalPrevBtn) {
                transmittalPrevBtn.addEventListener('click', function() {
                    openAdjacentTransmittal(-1);
                });
            }

            const transmittalNextBtn = document.getElementById('transmittalNextBtn');
            if (transmittalNextBtn) {
                transmittalNextBtn.addEventListener('click', function() {
                    openAdjacentTransmittal(1);
                });
            }

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
                const scannerRowId = parseInt(data.rowId || '0', 10) || 0;
                if (scannerRowId > 0) {
                    sessionStorage.setItem('dhsud_focus_id', String(scannerRowId));
                }
                if ((data.noticeCode || '').trim() !== '') {
                    sessionStorage.setItem('dhsud_focus_notice', data.noticeCode.trim());
                }
                const scannerNotice = (data.noticeCode || '').trim();
                // Trigger status fetch immediately for near real-time UI update.
                const immediateTrack = (scannerNotice !== '' || scannerRowId > 0)
                    ? runTrackingUpdate(scannerNotice, {
                        rowId: scannerRowId,
                        silent: true,
                        force: true,
                        bypassCooldown: true
                    })
                    : Promise.resolve({ ok: false, reason: 'missing-target' });

                refreshHomeData({
                    focusNotice: scannerNotice,
                    focusRowId: scannerRowId,
                    immediateTrackNotices: (scannerNotice !== '' ? [scannerNotice] : [])
                }).then(function() {
                    if (scannerNotice !== '' || scannerRowId > 0) {
                        immediateTrack.then(function(result) {
                            // Second pass after refresh catches late DB writes after scan.
                            if (!result || result.ok !== true) {
                                runTrackingUpdate(scannerNotice, {
                                    rowId: scannerRowId,
                                    silent: true,
                                    force: true,
                                    bypassCooldown: true
                                });
                            }
                        });
                    }
                });
            }
        });

        const receiptDownloadOnceKeys = new Set();

        function maybeAutoDownloadReceipt(trackingNumber, statusValue, options = {}) {
            const safeTracking = ((trackingNumber || '') + '').trim();
            const status = ((statusValue || '') + '').trim().toUpperCase();
            const safeRowId = parseInt(options.rowId || '0', 10) || 0;
            const safeTransmittalId = ((options.transmittalId || '') + '').trim();
            const safeDepartment = ((options.department || currentDeptKey || '') + '').trim();
            if (!safeTracking) return;
            if (status !== 'DELIVERED' && status !== 'RETURNED TO SENDER') return;

            const key = safeTracking + '|' + status;
            if (receiptDownloadOnceKeys.has(key)) return;
            receiptDownloadOnceKeys.add(key);

            const qs = new URLSearchParams();
            qs.set('tracking', safeTracking);
            qs.set('_dl', Date.now().toString());
            if (safeRowId > 0) qs.set('row_id', String(safeRowId));
            if (safeTransmittalId) qs.set('transmittal_id', safeTransmittalId);
            if (safeDepartment) qs.set('dept', safeDepartment);

            const directUrl = '../api/download-receipt.php?' + qs.toString();
            fetch(directUrl, {
                method: 'GET',
                cache: 'no-store',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
                .then(function(response) {
                    if (!response.ok) throw new Error('HTTP ' + response.status);
                    return response.arrayBuffer();
                })
                .catch(function(err) {
                    console.error(err);
                    receiptDownloadOnceKeys.delete(key);
                });
        }

        function focusScannedRow() {
            const url = new URL(window.location.href);
            const urlRowId = parseInt(url.searchParams.get('scanned_id') || '0', 10) || 0;
            const urlNotice = (url.searchParams.get('scanned_notice') || '').trim();
            const recoveredParam = (url.searchParams.get('recovered') || '').trim();
            const recoveredTransmittalsParam = (url.searchParams.get('recovered_transmittals') || '').trim();
            const storedRowId = parseInt(sessionStorage.getItem('dhsud_focus_id') || '0', 10) || 0;
            const storedNotice = (sessionStorage.getItem('dhsud_focus_notice') || '').trim();
            const rowId = urlRowId || storedRowId;
            const noticeCode = urlNotice || storedNotice;
            if (rowId <= 0 && !noticeCode) {
                if (recoveredParam || recoveredTransmittalsParam) {
                    url.searchParams.delete('recovered');
                    url.searchParams.delete('recovered_transmittals');
                    window.history.replaceState({}, '', url.pathname + (url.search ? url.search : '') + url.hash);
                }
                return;
            }

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

            ensureRowVisibleForFocus(row);
            row.scrollIntoView({ behavior: 'smooth', block: 'center', inline: 'nearest' });
            row.classList.add('scanned-row-focus');
            setTimeout(function() { row.classList.remove('scanned-row-focus'); }, 2600);

            sessionStorage.removeItem('dhsud_focus_notice');
            sessionStorage.removeItem('dhsud_focus_id');
            if (urlNotice || urlRowId > 0 || recoveredParam || recoveredTransmittalsParam) {
                url.searchParams.delete('scanned_notice');
                url.searchParams.delete('scanned_id');
                url.searchParams.delete('recovered');
                url.searchParams.delete('recovered_transmittals');
                window.history.replaceState({}, '', url.pathname + (url.search ? url.search : '') + url.hash);
            }
        }
        
        async function submitPdfExportForNoticeCodes(rowIds, modalTitle, transmittalName, options) {
            if (!Array.isArray(rowIds) || rowIds.length === 0) {
                alert("No rows available for export.");
                return;
            }

            const modal = document.getElementById('pdfViewerModal');
            const frame = document.getElementById('pdfViewerFrame');
            const title = document.getElementById('pdfViewerTitle');
            if (!modal || !frame || !title) return;

            const reuseModal = options && options.reuseModal;
            pdfPreviewRowIds = Array.isArray(rowIds) ? rowIds.slice() : [];
            pdfPreviewTransmittalName = (transmittalName || '').trim();
            pdfPreviewTitle = modalTitle || "Exported PDF";
            if (!reuseModal) {
                lastPdfViewerFocus = document.activeElement;
                title.textContent = pdfPreviewTitle;
                frame.src = 'about:blank';
                setPdfViewerDownloadTarget('', '');
                modal.style.display = 'flex';
                modal.setAttribute('aria-hidden', 'false');
                modal.removeAttribute('inert');
                animateModalPanelFromTrigger(modal, modal.querySelector('.pdf-viewer-panel'), document.activeElement);
                const closeBtn = modal.querySelector('.pdf-viewer-close');
                if (closeBtn) {
                    try { closeBtn.focus(); } catch (e) {}
                }
            } else {
                title.textContent = pdfPreviewTitle;
                setPdfViewerDownloadTarget('', '');
            }

            try {
                const formData = new URLSearchParams();
                formData.set('row_ids', JSON.stringify(rowIds));
                formData.set('transmittal_name', (transmittalName || '').trim());
                formData.set('dept', String(currentDeptKey || '').trim());
                const preparedInput = document.getElementById('pdfPreparedByInput');
                if (preparedInput) {
                    const preparedName = (preparedInput.value || '').trim();
                    if (preparedName) {
                        formData.set('officer_name', preparedName);
                    }
                }

                const response = await fetch("../api/jrs_tracking.php", {
                    method: "POST",
                    headers: { "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8" },
                    body: formData.toString()
                });
                if (!response.ok) {
                    throw new Error("PDF export failed");
                }

                const blob = await response.blob();
                const disposition = response.headers.get('Content-Disposition') || '';
                const headerFileName = parseFilenameFromContentDisposition(disposition);
                const fallbackName = normalizePdfFileName(transmittalName || modalTitle || 'DHSUD_Report', 'DHSUD_Report');
                const finalFileName = normalizePdfFileName(headerFileName, fallbackName);

                if (exportPdfBlobUrl) {
                    try { URL.revokeObjectURL(exportPdfBlobUrl); } catch (e) {}
                    exportPdfBlobUrl = '';
                }

                exportPdfBlobUrl = URL.createObjectURL(blob);
                frame.src = exportPdfBlobUrl + '#zoom=95';
                setPdfViewerDownloadTarget(exportPdfBlobUrl, finalFileName);
            } catch (err) {
                console.error(err);
                alert("Failed to export PDF.");
                if (!reuseModal) {
                    closePdfViewerModal();
                }
            }
        }

        function refreshPdfPreview() {
            if (!Array.isArray(pdfPreviewRowIds) || pdfPreviewRowIds.length === 0) return;
            submitPdfExportForNoticeCodes(pdfPreviewRowIds, pdfPreviewTitle || 'Exported PDF', pdfPreviewTransmittalName, { reuseModal: true });
        }

        function collectRowIdsForTransmittal(transmittalId) {
            const safeTid = ((transmittalId || '') + '').trim();
            if (!safeTid) return [];
            const rows = Array.isArray(window.mailRows) ? window.mailRows : [];
            const seen = new Set();
            const rowIds = [];
            rows.forEach(function(row) {
                const rowTid = ((row && row['Transmittal ID']) ? String(row['Transmittal ID']) : '').trim();
                if (rowTid !== safeTid) return;
                const rowId = parseInt((row && row.id) || 0, 10) || 0;
                if (rowId <= 0 || seen.has(rowId)) return;
                seen.add(rowId);
                rowIds.push(rowId);
            });
            return rowIds;
        }

        function exportTransmittalById(transmittalId) {
            const safeTid = ((transmittalId || '') + '').trim();
            if (!safeTid) return;
            const rowIds = collectRowIdsForTransmittal(safeTid);
            if (rowIds.length === 0) {
                alert("No rows available for this transmittal.");
                return;
            }
            const transmittalName = formatTransmittalDisplayName(safeTid);
            submitPdfExportForNoticeCodes(rowIds, transmittalName + " Transmittal Export", transmittalName);
        }

        function exportTransmittalToExcel(transmittalId) {
            const safeTid = ((transmittalId || '') + '').trim();
            if (!safeTid) return;
            const rows = Array.isArray(window.mailRows) ? window.mailRows : [];
            const rowsForTid = rows.filter(function(r) {
                const rowTid = ((r && r['Transmittal ID']) ? String(r['Transmittal ID']) : '').trim();
                return rowTid === safeTid;
            });
            if (rowsForTid.length === 0) {
                alert("No rows available for this transmittal.");
                return;
            }
            const headers = ['Notice/Order Code'].concat(tableDataColumns || []);
            const transmittalName = formatTransmittalDisplayName(safeTid);
            const safeName = transmittalName.replace(/[^A-Za-z0-9_-]+/g, '_').replace(/_+/g, '_').replace(/^_+|_+$/g, '');
            const filename = (safeName || 'transmittal') + "_export.xls";
            const dataRows = rowsForTid.map(function(r) { return headers.map(function(h){ return (r && r[h]) || ''; }); });
            openExcelPreview("Excel Export Preview", headers, dataRows, filename);
        }

        function deleteTransmittalById(transmittalId) {
            const safeTid = ((transmittalId || '') + '').trim();
            if (!safeTid) return;
            const rows = Array.isArray(window.mailRows) ? window.mailRows : [];
            const recordIds = [];
            rows.forEach(function(row) {
                const rowTid = ((row && row['Transmittal ID']) ? String(row['Transmittal ID']) : '').trim();
                if (rowTid !== safeTid) return;
                const id = parseInt((row && row.id) || 0, 10) || 0;
                if (id > 0) recordIds.push(id);
            });

            if (recordIds.length === 0) {
                pendingTransmittals.delete(safeTid);
                if ((activeTransmittalId || '').trim() === safeTid) {
                    openTransmittalGrid();
                } else {
                    updateTransmittalGrid();
                }
                return;
            }

            const label = formatTransmittalDisplayName(safeTid);
            const msg = 'Delete transmittal "' + label + '" and all ' + recordIds.length + ' record(s)?';
            if (!confirm(msg)) return;

            const wasActive = ((activeTransmittalId || '').trim() === safeTid);
            let chain = Promise.resolve();
            recordIds.forEach(function(id) {
                chain = chain.then(function() {
                    return fetch('../api/Delete.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                            'X-CSRF-Token': CSRF_TOKEN
                        },
                        body: 'id=' + encodeURIComponent(String(id)) + '&csrf_token=' + encodeURIComponent(CSRF_TOKEN)
                    }).then(function(resp) {
                        if (!resp.ok) {
                            throw new Error('Delete failed for record ID ' + id);
                        }
                    });
                });
            });

            chain
                .then(function() {
                    pendingTransmittals.delete(safeTid);
                    return refreshHomeData();
                })
                .then(function() {
                    if (wasActive) {
                        openTransmittalGrid();
                    } else {
                        updateTransmittalGrid();
                    }
                })
                .catch(function(err) {
                    console.error(err);
                    alert('Failed to delete transmittal: ' + (err && err.message ? err.message : 'Unknown error'));
                });
        }

        function exportSelectedToPDF() {
            const activeTid = (activeTransmittalId || '').trim();
            const rows = Array.from(document.querySelectorAll('tr[data-notice]'));
            const contextRows = rows.filter(function(tr) {
                if (!activeTid) return true;
                const rowId = parseInt(tr.dataset.id || '0', 10) || 0;
                const notice = (tr.dataset.notice || '').trim();
                const rowObj = (mailRowIndexById.get(rowId) || mailRowIndexByNotice.get(notice) || null);
                const rowTid = ((rowObj && rowObj['Transmittal ID']) ? String(rowObj['Transmittal ID']) : (tr.dataset.transmittalId || '')).trim();
                return rowTid === activeTid;
            });

            if (contextRows.length === 0) {
                alert("No rows available for export.");
                return;
            }

            const selectedRows = contextRows.filter(function(tr) {
                const cb = tr.querySelector('.row-checkbox');
                return !!(cb && cb.checked);
            });

            if (selectedRows.length === 0) {
                alert("Please check at least one checkbox before exporting.");
                return;
            }
            const rowsToExport = selectedRows;

            const rowIds = [];
            const seen = new Set();
            rowsToExport.forEach(function(tr) {
                const rowId = parseInt(tr.dataset.id || '0', 10) || 0;
                if (rowId <= 0 || seen.has(rowId)) return;
                seen.add(rowId);
                rowIds.push(rowId);
            });
            if (rowIds.length === 0) {
                alert("Selected rows have no valid ID.");
                return;
            }
            const transmittalName = activeTid ? formatTransmittalDisplayName(activeTid) : "";
            submitPdfExportForNoticeCodes(rowIds, "Exported PDF", transmittalName);
        }

        function escapeTsvValue(val) {
            const safe = (val || '').replace(/\s+/g, ' ').trim();
            if (/[\t\n\r"]/.test(safe)) {
                return '"' + safe.replace(/"/g, '""') + '"';
            }
            return safe;
        }

        let excelPreviewState = null;

        function openExcelPreview(title, headers, rows, filename) {
            const modal = document.getElementById('excelPreviewModal');
            const tableHead = document.getElementById('excelPreviewHead');
            const tableBody = document.getElementById('excelPreviewBody');
            const meta = document.getElementById('excelPreviewMeta');
            if (!modal || !tableHead || !tableBody || !meta) return;
            tableHead.innerHTML = '';
            tableBody.innerHTML = '';
            const headRow = document.createElement('tr');
            headers.forEach(function(h) {
                const th = document.createElement('th');
                th.textContent = h;
                headRow.appendChild(th);
            });
            tableHead.appendChild(headRow);

            tableBody.innerHTML = '';
            for (let i = 0; i < rows.length; i++) {
                const tr = document.createElement('tr');
                rows[i].forEach(function(cell) {
                    const td = document.createElement('td');
                    td.textContent = (cell || '').toString();
                    tr.appendChild(td);
                });
                tableBody.appendChild(tr);
            }

            meta.textContent = rows.length + ' row(s) will be exported as Excel.';
            excelPreviewState = { headers, rows, filename };
            const titleEl = document.getElementById('excelPreviewTitle');
            if (titleEl) titleEl.textContent = title || 'Excel Export Preview';
            const dlBtn = document.getElementById('excelPreviewDownloadBtn');
            if (dlBtn) {
                dlBtn.textContent = 'Save Excel';
                dlBtn.setAttribute('aria-disabled', 'false');
            }
            modal.style.display = 'flex';
            modal.setAttribute('aria-hidden', 'false');
        }

        function closeExcelPreview() {
            const modal = document.getElementById('excelPreviewModal');
            if (modal) {
                modal.style.display = 'none';
                modal.setAttribute('aria-hidden', 'true');
            }
            const dlBtn = document.getElementById('excelPreviewDownloadBtn');
            if (dlBtn) {
                dlBtn.setAttribute('aria-disabled', 'true');
            }
        }

        function confirmExcelExport() {
            if (!excelPreviewState) {
                closeExcelPreview();
                return;
            }
            const headers = excelPreviewState.headers || [];
            const rows = excelPreviewState.rows || [];
            const lines = [];
            lines.push(headers.map(escapeTsvValue).join('\t'));
            rows.forEach(function(r) { lines.push(r.map(escapeTsvValue).join('\t')); });
            const blob = new Blob([lines.join('\r\n')], { type: 'application/vnd.ms-excel' });
            const url = URL.createObjectURL(blob);
            const safeName = (excelPreviewState.filename || 'export').replace(/[^A-Za-z0-9_-]+/g, '_').replace(/_+/g, '_').replace(/^_+|_+$/g, '');
            const link = document.createElement('a');
            link.href = url;
            link.download = (safeName || 'export') + '.xls';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            setTimeout(function() { try { URL.revokeObjectURL(url); } catch(e) {} }, 2000);
            excelPreviewState = null;
            closeExcelPreview();
        }

        function exportSelectedToExcel() {
            const activeTid = (activeTransmittalId || '').trim();
            const rows = Array.from(document.querySelectorAll('tr[data-notice]'));
            const contextRows = rows.filter(function(tr) {
                if (!activeTid) return true;
                const rowId = parseInt(tr.dataset.id || '0', 10) || 0;
                const notice = (tr.dataset.notice || '').trim();
                const rowObj = (mailRowIndexById.get(rowId) || mailRowIndexByNotice.get(notice) || null);
                const rowTid = ((rowObj && rowObj['Transmittal ID']) ? String(rowObj['Transmittal ID']) : (tr.dataset.transmittalId || '')).trim();
                return rowTid === activeTid;
            });

            if (contextRows.length === 0) {
                alert("No rows available for export.");
                return;
            }

            const selectedRows = contextRows.filter(function(tr) {
                const cb = tr.querySelector('.row-checkbox');
                return !!(cb && cb.checked);
            });

            if (selectedRows.length === 0) {
                alert("Please check at least one checkbox before exporting.");
                return;
            }

            const table = document.querySelector('.listview-table');
            if (!table) {
                alert("Export failed: table not found.");
                return;
            }

            const headerCells = Array.from(table.querySelectorAll('thead th')).slice(1, -1); // skip checkbox + action
            const headers = headerCells.map(function(th) { return (th.textContent || '').trim(); });

            const dataRows = selectedRows.map(function(tr) {
                const cells = Array.from(tr.querySelectorAll('td')).slice(1, -1); // skip checkbox + action
                return cells.map(function(td) { return (td.innerText || td.textContent || ''); });
            });

            const transmittalName = activeTid ? formatTransmittalDisplayName(activeTid) : "mail-tracking";
            const safeName = transmittalName.replace(/[^A-Za-z0-9_-]+/g, '_').replace(/_+/g, '_').replace(/^_+|_+$/g, '');
            const filename = (safeName || 'mail-tracking') + "_export.xls";
            openExcelPreview("Excel Export Preview", headers, dataRows, filename);
        }

        function handleExportOption(type) {
            closeExportDropdown();
            if (type === 'pdf') {
                exportSelectedToPDF();
            } else if (type === 'excel') {
                exportSelectedToExcel();
            }
        }

        function toggleExportDropdown(event) {
            event.stopPropagation();
            const menu = document.getElementById('exportDropdownMenu');
            if (!menu) return;
            const isOpen = menu.classList.contains('is-open');
            closeExportDropdown();
            if (!isOpen) {
                menu.classList.add('is-open');
            }
        }

        function closeExportDropdown() {
            const menu = document.getElementById('exportDropdownMenu');
            if (menu) menu.classList.remove('is-open');
        }

        document.addEventListener('click', function(evt) {
            const btn = document.getElementById('exportDropdownBtn');
            const menu = document.getElementById('exportDropdownMenu');
            if (!btn || !menu) return;
            if (btn.contains(evt.target)) {
                toggleExportDropdown(evt);
                return;
            }
            if (!menu.contains(evt.target)) {
                closeExportDropdown();
            }
        });

        document.addEventListener('click', function(evt) {
            const modal = document.getElementById('excelPreviewModal');
            if (modal && evt.target === modal) {
                closeExcelPreview();
            }
        });

        const AUTO_TRACK_INTERVAL_MS = 6 * 60 * 60 * 1000;
        const AUTO_TRACK_JITTER_RATIO = 0.2;
        const AUTO_TRACK_STORAGE_KEY = 'dhsud_auto_track_last_run_v1:' + String(currentDeptKey || 'all');
        const autoTrackLastRunByKey = new Map();
        const IMMEDIATE_TRACK_DEDUP_WINDOW_MS = 3000;
        const immediateTrackLastRunAtByKey = new Map();
        let autoTrackInProgress = false;
        let autoTrackStateLoadPromise = null;
        const pendingAutoTrackPersist = new Map();
        let autoTrackPersistTimer = null;

        function loadAutoTrackCacheLocal() {
            try {
                const raw = localStorage.getItem(AUTO_TRACK_STORAGE_KEY);
                if (!raw) return;
                const data = JSON.parse(raw);
                if (!data || typeof data !== 'object') return;
                Object.keys(data).forEach(function(key) {
                    const val = data[key];
                    if (val && typeof val === 'object') {
                        const ts = Number(val.ts) || 0;
                        const interval = Number(val.interval) || AUTO_TRACK_INTERVAL_MS;
                        if (ts > 0) autoTrackLastRunByKey.set(key, { ts: ts, interval: interval });
                    } else {
                        const ts = Number(val) || 0;
                        if (ts > 0) autoTrackLastRunByKey.set(key, { ts: ts, interval: AUTO_TRACK_INTERVAL_MS });
                    }
                });
            } catch (e) {}
        }

        function saveAutoTrackCacheLocal() {
            try {
                const obj = {};
                autoTrackLastRunByKey.forEach(function(value, key) {
                    if (value && typeof value === 'object') {
                        const ts = Number(value.ts) || 0;
                        const interval = Number(value.interval) || AUTO_TRACK_INTERVAL_MS;
                        if (ts > 0) obj[key] = { ts: ts, interval: interval };
                    } else {
                        const ts = Number(value) || 0;
                        if (ts > 0) obj[key] = { ts: ts, interval: AUTO_TRACK_INTERVAL_MS };
                    }
                });
                localStorage.setItem(AUTO_TRACK_STORAGE_KEY, JSON.stringify(obj));
            } catch (e) {}
        }

        async function loadAutoTrackCacheFromServer() {
            try {
                const response = await fetch('../api/auto-track-state.php?dept=' + encodeURIComponent(currentDeptKey || ''));
                if (!response.ok) throw new Error('auto-track state unavailable');
                const payload = await response.json();
                if (payload && payload.success && payload.data && typeof payload.data === 'object') {
                    Object.keys(payload.data).forEach(function(key) {
                        const record = payload.data[key];
                        if (!record || typeof record !== 'object') return;
                        const ts = Number(record.ts) || 0;
                        const interval = Number(record.interval) || AUTO_TRACK_INTERVAL_MS;
                        if (ts > 0) autoTrackLastRunByKey.set(key, { ts: ts, interval: interval });
                    });
                }
            } catch (e) {
                if (autoTrackLastRunByKey.size === 0) {
                    loadAutoTrackCacheLocal();
                }
            }
        }

        function initAutoTrackState() {
            if (!autoTrackStateLoadPromise) {
                autoTrackStateLoadPromise = loadAutoTrackCacheFromServer();
            }
            return autoTrackStateLoadPromise;
        }

        function queueAutoTrackPersist(key, record) {
            if (!key || !record) return;
            pendingAutoTrackPersist.set(key, {
                ts: Number(record.ts) || 0,
                interval: Number(record.interval) || AUTO_TRACK_INTERVAL_MS
            });
            saveAutoTrackCacheLocal();
            if (autoTrackPersistTimer) return;
            autoTrackPersistTimer = window.setTimeout(flushAutoTrackPersist, 600);
        }

        async function flushAutoTrackPersist() {
            if (autoTrackPersistTimer) {
                clearTimeout(autoTrackPersistTimer);
                autoTrackPersistTimer = null;
            }
            if (pendingAutoTrackPersist.size === 0) return;
            const items = [];
            pendingAutoTrackPersist.forEach(function(value, key) {
                items.push({ key: key, ts: value.ts, interval: value.interval });
            });
            pendingAutoTrackPersist.clear();
            try {
                await fetch('../api/auto-track-state.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ dept: String(currentDeptKey || '').trim(), items: items })
                });
            } catch (e) {}
        }

        initAutoTrackState();

        function findRowById(rowId) {
            const safeRowId = parseInt(rowId || '0', 10) || 0;
            if (safeRowId <= 0) return null;
            return document.querySelector('tr[data-id="' + String(safeRowId) + '"]');
        }

        function getTrackResultElement(noticeCode, rowId) {
            const row = findRowByNoticeCode(noticeCode) || findRowById(rowId);
            if (!row) return null;
            const actionCell = row.lastElementChild;
            return actionCell ? actionCell.querySelector(".track-result") : null;
        }

        function runTrackingUpdate(noticeCode, options = {}) {
            const safeNotice = (noticeCode || "").trim();
            const optionRowId = parseInt(options.rowId || '0', 10) || 0;
            const silent = options.silent === true;
            const force = options.force === true;
            const bypassCooldown = options.bypassCooldown === true;
            const result = getTrackResultElement(safeNotice, optionRowId);
            const targetRow = findRowByNoticeCode(safeNotice) || findRowById(optionRowId);
            const mappedRow = (!targetRow && safeNotice !== '') ? (mailRowIndexByNotice.get(safeNotice) || null) : null;
            const rowId = optionRowId > 0
                ? optionRowId
                : (targetRow ? (parseInt(targetRow.dataset.id || '0', 10) || 0) : ((mappedRow && mappedRow.id) ? (parseInt(mappedRow.id, 10) || 0) : 0));

            if (rowId <= 0) {
                return Promise.resolve({ ok: false, reason: "missing-row-id" });
            }

            if (result) result.innerHTML = "";
            const params = new URLSearchParams();
            params.set("row_id", String(rowId));
            params.set("csrf_token", CSRF_TOKEN);
            if (force) params.set("force", "1");
            if (bypassCooldown) params.set("bypass_cooldown", "1");

            return fetch("../api/remarks.php", {
                method: "POST",
                cache: "no-store",
                headers: {
                    "Content-Type": "application/x-www-form-urlencoded",
                    "X-CSRF-Token": CSRF_TOKEN
                },
                body: params.toString()
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
                    updateTrackingRow(safeNotice, data, { rowId: rowId });
                }

                const mappedById = mailRowIndexById.get(rowId) || null;
                const resolvedTrackingNo = ((data.trackingNo || (targetRow ? targetRow.dataset.trackingNo : '') || (mappedById ? mappedById['Tracking No.'] : '') || '') + '').trim();
                const resolvedStatus = ((data.status || '') + '').trim();
                const resolvedTransmittalId = ((data.transmittalId || (targetRow ? targetRow.dataset.transmittalId : '') || (mappedById ? mappedById['Transmittal ID'] : '') || '') + '').trim();
                maybeAutoDownloadReceipt(resolvedTrackingNo, resolvedStatus, {
                    rowId: rowId,
                    transmittalId: resolvedTransmittalId,
                    department: currentDeptKey
                });

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
            const now = Date.now();
            const lastRunAt = immediateTrackLastRunAtByKey.get(key) || 0;
            if ((now - lastRunAt) < IMMEDIATE_TRACK_DEDUP_WINDOW_MS) return;
            immediateTrackLastRunAtByKey.set(key, now);

            const throttleKey = batchId ? ('batch:' + batchId) : ('notice:' + safeNotice);
            const immediateRecord = { ts: Date.now(), interval: applyAutoTrackJitter(AUTO_TRACK_INTERVAL_MS) };
            autoTrackLastRunByKey.set(throttleKey, immediateRecord);
            queueAutoTrackPersist(throttleKey, immediateRecord);
            runTrackingUpdate(safeNotice, {
                silent: true,
                force: true,
                bypassCooldown: true
            });
        }

        function collectAutoTrackItems() {
            const rows = document.querySelectorAll('tr[data-notice][data-tracking-no]');
            const items = [];
            const seenBatchIds = new Set();

            rows.forEach(function(row) {
                const noticeCode = (row.dataset.notice || '').trim();
                const rowId = parseInt(row.dataset.id || '0', 10) || 0;
                const batchId = (row.dataset.batchId || '').trim();
                const trackingNo = (row.dataset.trackingNo || '').trim();
                const statusCell = row.querySelector('td[data-col="Status"]');
                const statusText = ((statusCell && statusCell.textContent) ? statusCell.textContent : '').trim().toUpperCase();

                if (rowId <= 0 || !trackingNo || trackingNo === '0') return;
                if (statusText !== 'ONGOING DELIVERY') return;

                if (batchId) {
                    if (seenBatchIds.has(batchId)) return;
                    seenBatchIds.add(batchId);
                    const key = 'batch:' + batchId;
                    row.dataset.autoTrackKey = key;
                    items.push({ key: key, noticeCode: noticeCode, rowId: rowId });
                    return;
                }

                const itemKey = noticeCode ? ('notice:' + noticeCode) : ('row:' + String(rowId));
                row.dataset.autoTrackKey = itemKey;
                items.push({ key: itemKey, noticeCode: noticeCode, rowId: rowId });
            });

            return items;
        }

        function applyAutoTrackJitter(baseMs) {
            const base = Math.max(60000, parseInt(baseMs, 10) || 0);
            const delta = Math.round(base * AUTO_TRACK_JITTER_RATIO);
            if (delta <= 0) return base;
            const offset = Math.floor((Math.random() * (delta * 2 + 1)) - delta);
            return Math.max(60000, base + offset);
        }

        async function autoTrackEligibleRows() {
            if (autoTrackInProgress) return;
            autoTrackInProgress = true;

            await initAutoTrackState();
            const now = Date.now();
            const items = collectAutoTrackItems().filter(function(item) {
                const record = autoTrackLastRunByKey.get(item.key);
                const lastRun = (record && typeof record === 'object') ? (record.ts || 0) : (record || 0);
                const interval = (record && typeof record === 'object') ? (record.interval || AUTO_TRACK_INTERVAL_MS) : AUTO_TRACK_INTERVAL_MS;
                return (now - lastRun) >= interval;
            });

            if (items.length === 0) {
                autoTrackInProgress = false;
                return;
            }

            items.reduce(function(chain, item) {
                return chain.then(function() {
                    return runTrackingUpdate(item.noticeCode, { silent: true, rowId: item.rowId }).finally(function() {
                        const record = { ts: Date.now(), interval: applyAutoTrackJitter(AUTO_TRACK_INTERVAL_MS) };
                        autoTrackLastRunByKey.set(item.key, record);
                        queueAutoTrackPersist(item.key, record);
                    });
                }).then(function() {
                    return new Promise(function(resolve) { setTimeout(resolve, 200); });
                });
            }, Promise.resolve())
            .finally(function() {
                autoTrackInProgress = false;
                refreshAutoTrackBadges();
            });

            refreshAutoTrackBadges();
        }

        function formatCheckTimes(lastRun, nextAt) {
            const fmt = function(ts) {
                if (!Number.isFinite(ts) || ts <= 0) return '--';
                try {
                    return new Date(ts).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
                } catch (e) {
                    return '--';
                }
            };
            return fmt(lastRun) + ' → ' + fmt(nextAt);
        }

        function refreshAutoTrackBadges() {
            const rows = document.querySelectorAll('tr[data-notice][data-tracking-no]');
            rows.forEach(function(row) {
                const statusCell = row.querySelector('td[data-col="Status"]');
                const statusText = ((statusCell && statusCell.textContent) ? statusCell.textContent : '').trim().toUpperCase();
                const trackingNo = (row.dataset.trackingNo || '').trim();
                const badge = row.querySelector('.track-result');
                if (!badge) return;

                if (!trackingNo || trackingNo === '0' || statusText !== 'ONGOING DELIVERY') {
                    badge.textContent = '';
                    badge.style.display = 'none';
                    return;
                }

                let key = row.dataset.autoTrackKey || '';
                if (!key) {
                    const notice = (row.dataset.notice || '').trim();
                    const rid = parseInt(row.dataset.id || '0', 10) || 0;
                    key = notice ? ('notice:' + notice) : (rid ? ('row:' + rid) : '');
                    if (key) row.dataset.autoTrackKey = key;
                }
                const record = key ? (autoTrackLastRunByKey.get(key) || null) : null;
                const lastRun = (record && typeof record === 'object') ? (record.ts || 0) : (record || 0);
                const interval = (record && typeof record === 'object') ? (record.interval || AUTO_TRACK_INTERVAL_MS) : AUTO_TRACK_INTERVAL_MS;
                const nextAt = lastRun ? (lastRun + interval) : 0;
                badge.innerHTML = '<span>' + formatCheckTimes(lastRun, nextAt) + '</span>';
                badge.classList.add('notif-timestamp');
                badge.style.display = 'inline-flex';
            });
        }

        setInterval(refreshAutoTrackBadges, 30000);

        document.addEventListener("click", function (evt) {
            const button = evt.target.closest(".btn-track");
            if (!button) return;
            const noticeCode = (button.dataset.notice || "").trim();
            const row = button.closest('tr[data-id]');
            const rowId = row ? (parseInt(row.dataset.id || '0', 10) || 0) : 0;
            runTrackingUpdate(noticeCode, { silent: false, rowId: rowId });
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

        const DEPT_CODE_TO_KEY = Object.freeze({
            EMES: 'emes',
            PRLS: 'prls',
            AFD: 'afd',
            PHSD: 'phsd',
            ELUPD: 'elupd',
            ORD: 'ord',
            HOA: 'hoa',
            LO: 'lo'
        });

        const DEPT_KEY_TO_CODE = Object.freeze({
            emes: 'EMES',
            prls: 'PRLS',
            afd: 'AFD',
            phsd: 'PHSD',
            elupd: 'ELUPD',
            ord: 'ORD',
            hoa: 'HOA',
            lo: 'LO'
        });

        function normalizeDepartmentKey(rawValue) {
            const key = ((rawValue || '') + '').trim().toLowerCase();
            return Object.prototype.hasOwnProperty.call(DEPT_KEY_TO_CODE, key) ? key : 'emes';
        }

        function resolveNotificationDepartmentKey(noticeCode, fallbackDeptKey) {
            const notice = ((noticeCode || '') + '').trim();
            if (notice) {
                const codeMatch = notice.match(/^([A-Za-z]+)-/);
                if (codeMatch && codeMatch[1]) {
                    const mapped = DEPT_CODE_TO_KEY[String(codeMatch[1]).trim().toUpperCase()] || '';
                    if (mapped) return mapped;
                }
            }
            return normalizeDepartmentKey(fallbackDeptKey || currentDeptKey);
        }

        function getNotificationDepartmentKey(notif) {
            if (!notif || typeof notif !== 'object') return normalizeDepartmentKey(currentDeptKey);
            const rawStored = ((notif.department || '') + '').trim();
            if (rawStored) return normalizeDepartmentKey(rawStored);
            const noticeCode = ((notif.noticeCode || notif.trackingId || '') + '').trim();
            return resolveNotificationDepartmentKey(noticeCode, currentDeptKey);
        }

        function getNotificationDepartmentLabel(notif) {
            const deptKey = getNotificationDepartmentKey(notif);
            return DEPT_KEY_TO_CODE[deptKey] || 'EMES';
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

        function maybeNotifyStatusChange(noticeCode, previousStatus, nextStatus, eventDateText, options = {}) {
            const notice = (noticeCode || '').trim();
            const trackingFallback = ((options.trackingNo || '') + '').trim();
            const displayTrackingId = ((options.displayTrackingId || '') + '').trim() || trackingFallback || notice;
            const navigateNoticeCode = ((options.noticeCode || '') + '').trim() || notice;
            let navigateRowId = parseInt(options.rowId || '0', 10) || 0;
            let dedupeKey = ((options.dedupeKey || '') + '').trim();
            const fallbackDepartment = normalizeDepartmentKey(options.department || currentDeptKey);
            const notificationDepartment = resolveNotificationDepartmentKey(navigateNoticeCode || notice, fallbackDepartment);
            const prev = ((previousStatus || '') + '').trim().toUpperCase();
            const next = ((nextStatus || '') + '').trim().toUpperCase();
            const eventDate = ((eventDateText || '') + '').trim();
            if (!navigateNoticeCode && !trackingFallback && navigateRowId <= 0) return;
            if (!isNotifiableStatus(next)) return;
            if (prev === next) return;

            // Fallback dedupe for non-batch notifications:
            // Avoid duplicate entries when the same status change is detected by both
            // immediate tracking update and subsequent delta/full refresh.
            if (!dedupeKey) {
                const dedupeIdentity = navigateNoticeCode
                    ? ('notice:' + navigateNoticeCode)
                    : (trackingFallback ? ('tracking:' + trackingFallback) : (navigateRowId > 0 ? ('id:' + String(navigateRowId)) : 'unknown'));
                dedupeKey = dedupeIdentity + '|status:' + next + '|date:' + eventDate;
            }
            if (navigateRowId <= 0 && navigateNoticeCode && mailRowIndexByNotice && typeof mailRowIndexByNotice.get === 'function') {
                const mappedRow = mailRowIndexByNotice.get(navigateNoticeCode) || null;
                navigateRowId = (mappedRow && mappedRow.id) ? (parseInt(mappedRow.id, 10) || 0) : 0;
            }
            if (navigateRowId <= 0 && trackingFallback && mailRowIndexByTracking && typeof mailRowIndexByTracking.get === 'function') {
                const mappedByTracking = mailRowIndexByTracking.get(trackingFallback) || null;
                navigateRowId = (mappedByTracking && mappedByTracking.id) ? (parseInt(mappedByTracking.id, 10) || 0) : 0;
            }

            const notifications = readNotifications();
            const notificationItem = {
                id: Date.now() + Math.floor(Math.random() * 1000),
                trackingId: displayTrackingId,
                noticeCode: navigateNoticeCode,
                trackingNo: trackingFallback,
                rowId: navigateRowId,
                status: next,
                statusType: getNotificationStatusType(next),
                eventDate: eventDate,
                timestampIso: new Date().toISOString(),
                department: notificationDepartment,
                read: false
            };

            notificationItem.dedupeKey = dedupeKey;
            const existingIndex = notifications.findIndex(function(n) {
                return (((n && n.dedupeKey) ? String(n.dedupeKey) : '').trim()) === dedupeKey;
            });
            if (existingIndex >= 0) {
                notifications.splice(existingIndex, 1);
            }

            notifications.unshift(notificationItem);

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

            const top = Math.max(8, btnRect.bottom + 3);
            const maxHeight = Math.max(220, window.innerHeight - top - 10);
            const btnCenterX = btnRect.left + (btnRect.width / 2);
            const arrowLeft = Math.max(18, Math.min(modalWidth - 18, btnCenterX - left));

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
                        <div class="notif-dept">Department: ${escapeHtml(getNotificationDepartmentLabel(notif))}</div>
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
            let rowId = 0;
            let statusType = '';
            let targetDeptKey = normalizeDepartmentKey(currentDeptKey);
            let changed = false;
            notifications.forEach(function(n) {
                if ((parseInt(n.id, 10) || 0) === targetId) {
                    noticeCode = ((n.noticeCode || n.trackingId || '') + '').trim();
                    rowId = parseInt(n.rowId || '0', 10) || 0;
                    if (rowId <= 0 && noticeCode && mailRowIndexByNotice && typeof mailRowIndexByNotice.get === 'function') {
                        const mappedRow = mailRowIndexByNotice.get(noticeCode) || null;
                        rowId = (mappedRow && mappedRow.id) ? (parseInt(mappedRow.id, 10) || 0) : 0;
                    }
                    if (rowId <= 0) {
                        const trackingCandidate = ((n.trackingNo || n.trackingId || '') + '').trim();
                        if (trackingCandidate && mailRowIndexByTracking && typeof mailRowIndexByTracking.get === 'function') {
                            const mappedByTracking = mailRowIndexByTracking.get(trackingCandidate) || null;
                            rowId = (mappedByTracking && mappedByTracking.id) ? (parseInt(mappedByTracking.id, 10) || 0) : 0;
                        }
                    }
                    statusType = (n.statusType === 'returned' ? 'returned' : 'delivered');
                    targetDeptKey = resolveNotificationDepartmentKey(noticeCode, getNotificationDepartmentKey(n));
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

            if ((rowId > 0 || noticeCode) && targetDeptKey !== normalizeDepartmentKey(currentDeptKey)) {
                closeNotifModal();
                const targetUrl = new URL(window.location.href);
                targetUrl.searchParams.set('dept', targetDeptKey);
                if (rowId > 0) {
                    targetUrl.searchParams.set('scanned_id', String(rowId));
                } else {
                    targetUrl.searchParams.delete('scanned_id');
                }
                if (noticeCode) {
                    targetUrl.searchParams.set('scanned_notice', noticeCode);
                } else {
                    targetUrl.searchParams.delete('scanned_notice');
                }
                targetUrl.searchParams.delete('recovered');
                targetUrl.searchParams.delete('recovered_transmittals');
                window.location.assign(targetUrl.toString());
                return;
            }

            // Always return to the main table when opening from notifications.
            if (typeof exitTransmittalMode === 'function') {
                exitTransmittalMode();
            }

            // Ensure filters do not hide the target row before focusing.
            const searchInput = document.getElementById('tableSearchInput');
            if (searchInput) {
                searchInput.value = '';
            }
            const yearSelect = document.getElementById('tableSortYear');
            const monthInput = document.getElementById('tableSortMonth');
            if (yearSelect) {
                yearSelect.value = 'all';
            }
            if (monthInput) {
                monthInput.value = '';
            }
            if (typeof syncYearMonthFilterUI === 'function') {
                syncYearMonthFilterUI();
            }
            if (typeof filterTableRows === 'function') {
                filterTableRows();
            }

            closeNotifModal();

            if (rowId > 0 || noticeCode) {
                navigateToNoticeRow(noticeCode, statusType, rowId);
            }
        }

        function navigateToNoticeRow(noticeCode, statusType, rowId) {
            const safeNotice = (noticeCode || '').trim();
            const safeRowId = parseInt(rowId || '0', 10) || 0;
            if (safeRowId <= 0 && !safeNotice) return;

            let row = findRowById(safeRowId);
            if (!row && safeNotice) {
                row = findRowByNoticeCode(safeNotice);
            }
            if (row) {
                const batchId = ((row.dataset.batchId || '') + '').trim();
                ensureRowVisibleForFocus(row);

                row.scrollIntoView({ behavior: 'smooth', block: 'center', inline: 'nearest' });
                var focusClass = statusType === 'returned' ? 'notif-row-focus-returned' : (statusType === 'delivered' ? 'notif-row-focus-delivered' : 'scanned-row-focus');
                const targetRows = [];
                if (batchId) {
                    document.querySelectorAll('tr[data-batch-id]').forEach(function(tr) {
                        if (((tr.dataset.batchId || '') + '').trim() === batchId) {
                            targetRows.push(tr);
                        }
                    });
                } else {
                    targetRows.push(row);
                }

                targetRows.forEach(function(tr) {
                    tr.classList.add(focusClass);
                });
                setTimeout(function() {
                    targetRows.forEach(function(tr) {
                        tr.classList.remove('notif-row-focus-delivered', 'notif-row-focus-returned', 'scanned-row-focus');
                    });
                }, 2600);
                return;
            }

            // If row is not currently in DOM, refresh then focus using existing flow.
            if (safeRowId > 0) {
                sessionStorage.setItem('dhsud_focus_id', String(safeRowId));
            }
            if (safeNotice) {
                sessionStorage.setItem('dhsud_focus_notice', safeNotice);
            }
            if (typeof refreshHomeData === 'function') {
                refreshHomeData({ focusNotice: safeNotice, focusRowId: safeRowId });
            }
        }

        function ensureRowVisibleForFocus(row) {
            if (!row) return;
            let needsRefilter = false;

            const isHidden = row.style && row.style.display === 'none';
            if (isHidden) {
                const searchInput = document.getElementById('tableSearchInput');
                if (searchInput && searchInput.value !== '') {
                    searchInput.value = '';
                    needsRefilter = true;
                }
                const yearSelect = document.getElementById('tableSortYear');
                const monthInput = document.getElementById('tableSortMonth');
                if (yearSelect && yearSelect.value !== 'all') {
                    yearSelect.value = 'all';
                    if (monthInput) monthInput.value = '';
                    needsRefilter = true;
                }
                if (monthInput && monthInput.value !== '') {
                    monthInput.value = '';
                    needsRefilter = true;
                }
            }

            if (needsRefilter && typeof filterTableRows === 'function') {
                if (typeof syncYearMonthFilterUI === 'function') {
                    syncYearMonthFilterUI();
                }
                filterTableRows();
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
            const removeFromStore = function() {
                const notifications = readNotifications();
                const nextNotifications = notifications.filter(function(n) {
                    return (parseInt(n.id, 10) || 0) !== targetId;
                });
                activeNotifActionId = 0;
                writeNotifications(nextNotifications);
                loadNotifications();
            };

            const item = document.querySelector('.notif-item[data-notif-id="' + String(targetId) + '"]');
            if (!item) {
                removeFromStore();
                return;
            }
            if (item.classList.contains('is-deleting')) return;

            item.classList.add('is-deleting');
            let settled = false;
            const finalize = function() {
                if (settled) return;
                settled = true;
                removeFromStore();
            };

            item.addEventListener('animationend', finalize, { once: true });
            setTimeout(finalize, 320);
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        function openHomeSidebar() {
            const sidebar = document.getElementById('homeSidebar');
            const overlay = document.getElementById('homeSidebarOverlay');
            const trigger = document.getElementById('homeSidebarTrigger');
            if (!sidebar || !overlay || !trigger) return;
            sidebar.classList.add('open');
            overlay.hidden = false;
            overlay.classList.add('show');
            sidebar.setAttribute('aria-hidden', 'false');
            trigger.setAttribute('aria-expanded', 'true');
            document.body.classList.add('home-sidebar-open');
        }

        function closeHomeSidebar() {
            const sidebar = document.getElementById('homeSidebar');
            const overlay = document.getElementById('homeSidebarOverlay');
            const trigger = document.getElementById('homeSidebarTrigger');
            if (!sidebar || !overlay || !trigger) return;
            sidebar.classList.remove('open');
            overlay.classList.remove('show');
            overlay.hidden = true;
            sidebar.setAttribute('aria-hidden', 'true');
            trigger.setAttribute('aria-expanded', 'false');
            document.body.classList.remove('home-sidebar-open');
        }

        let isDeptSwitchNavigating = false;
        function initDepartmentSwitchAnimations() {
            let raw = '';
            try {
                raw = sessionStorage.getItem('dhsud_dept_switch_pending') || '';
            } catch (e) {
                raw = '';
            }
            if (!raw) return;

            let payload = null;
            try {
                payload = JSON.parse(raw);
            } catch (e) {
                payload = null;
            }

            try {
                sessionStorage.removeItem('dhsud_dept_switch_pending');
            } catch (e) {}

            if (!payload || !payload.ts) return;

            const ageMs = Date.now() - Number(payload.ts);
            if (!Number.isFinite(ageMs) || ageMs < 0 || ageMs > 5000) return;

            document.body.classList.add('dept-switch-enter');
            window.setTimeout(function() {
                document.body.classList.remove('dept-switch-enter');
            }, 280);
        }

        function bindDepartmentSwitchLinks() {
            const links = document.querySelectorAll('.home-sidebar-nav .home-sidebar-link[href*="dept="]');
            if (!links || links.length === 0) return;

            links.forEach(function(link) {
                link.addEventListener('click', function(event) {
                    if (isDeptSwitchNavigating) {
                        event.preventDefault();
                        return;
                    }
                    if (event.defaultPrevented) return;
                    if (event.button !== 0) return;
                    if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
                    if (link.target && link.target !== '' && link.target !== '_self') return;

                    const currentUrl = new URL(window.location.href);
                    const targetUrl = new URL(link.href, window.location.href);
                    const sameDestination = (currentUrl.pathname === targetUrl.pathname && currentUrl.search === targetUrl.search);

                    event.preventDefault();
                    closeHomeSidebar();

                    if (sameDestination) {
                        return;
                    }

                    isDeptSwitchNavigating = true;
                    document.body.classList.add('dept-switch-leaving');

                    try {
                        sessionStorage.setItem('dhsud_dept_switch_pending', JSON.stringify({
                            ts: Date.now(),
                            from: currentUrl.searchParams.get('dept') || '',
                            to: targetUrl.searchParams.get('dept') || ''
                        }));
                    } catch (e) {}

                    window.setTimeout(function() {
                        window.location.assign(targetUrl.toString());
                    }, 170);
                });
            });
        }

        function bindLogoutLink() {
            const logoutLink = document.querySelector('.home-sidebar-logout');
            if (!logoutLink) return;

            logoutLink.addEventListener('click', function(event) {
                if (event.defaultPrevented) return;
                if (event.button !== 0) return;
                if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
                if (logoutLink.target && logoutLink.target !== '' && logoutLink.target !== '_self') return;

                event.preventDefault();
                logoutLink.classList.add('is-clicked');
                window.setTimeout(function() {
                    window.location.assign(logoutLink.href);
                }, 130);
            });
        }

        function initHomeSidebar() {
            const trigger = document.getElementById('homeSidebarTrigger');
            const closeBtn = document.getElementById('homeSidebarClose');
            const overlay = document.getElementById('homeSidebarOverlay');
            const sidebar = document.getElementById('homeSidebar');
            if (!trigger || !closeBtn || !overlay || !sidebar) return;

            trigger.addEventListener('click', function(event) {
                event.stopPropagation();
                openHomeSidebar();
            });

            closeBtn.addEventListener('click', function() {
                closeHomeSidebar();
            });

            overlay.addEventListener('click', function() {
                closeHomeSidebar();
            });

            bindDepartmentSwitchLinks();
            bindLogoutLink();
        }

        // Initialize notification button click handler
        document.addEventListener('DOMContentLoaded', function() {
            initDepartmentSwitchAnimations();
            function moveModalToBody(modalId) {
                const el = document.getElementById(modalId);
                if (el && el.parentElement !== document.body) {
                    document.body.appendChild(el);
                }
            }
            moveModalToBody('pdfViewerModal');
            moveModalToBody('ongoingDeliveryModal');
            moveModalToBody('rowMenuDropdown');
            const notifOverlayRoot = document.getElementById('notifModalOverlay');
            if (notifOverlayRoot && notifOverlayRoot.parentElement !== document.body) {
                document.body.appendChild(notifOverlayRoot);
            }
            refreshAutoTrackBadges();
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
                    closeHomeSidebar();
                }
            });

            document.addEventListener('click', function() {
                if (activeNotifActionId !== 0) {
                    activeNotifActionId = 0;
                    loadNotifications();
                }
            });
            initHomeSidebar();

            // Load initial notification count
            loadNotifications();
        });

        </script>
    </div>
</body>
</html>




