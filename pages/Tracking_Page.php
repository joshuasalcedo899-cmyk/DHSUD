<?php
require_once __DIR__ . '/../config.php';

$departmentConfig = [
    'emes' => ['code' => 'EMES', 'sender' => 'HREDRD-EMES'],
    'prls' => ['code' => 'PRLS', 'sender' => 'HREDRD-PRLS'],
    'afd' => ['code' => 'AFD', 'sender' => 'HREDRD-AFD'],
    'phsd' => ['code' => 'PHSD', 'sender' => 'HREDRD-PHSD'],
    'elupd' => ['code' => 'ELUPD', 'sender' => 'HREDRD-ELUPD'],
    'ord' => ['code' => 'ORD', 'sender' => 'HREDRD-ORD'],
];
$currentDept = strtolower(trim((string)($_GET['dept'] ?? 'emes')));
if (!isset($departmentConfig[$currentDept])) {
    $currentDept = 'emes';
}
$currentDeptCode = $departmentConfig[$currentDept]['code'];
$currentDeptSenderTag = $departmentConfig[$currentDept]['sender'];

try {
    $deptPrefix = strtoupper($currentDeptCode) . '-%';
    $deptSenderPattern = '%' . strtoupper($currentDeptSenderTag) . '%';
    $rowsStmt = $pdo->prepare(
        'SELECT * FROM mailtracking
         WHERE UPPER(COALESCE(`Notice/Order Code`, \'\')) LIKE :dept_prefix
            OR UPPER(COALESCE(`Sender Details`, \'\')) LIKE :dept_sender
         ORDER BY `Sender Details` ASC, `id` ASC'
    );
    $rowsStmt->execute([
        ':dept_prefix' => $deptPrefix,
        ':dept_sender' => $deptSenderPattern,
    ]);
    $rows = $rowsStmt->fetchAll();
} catch (Exception $e) {
    $rows = [];
}

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

$statusCounts = [
    'RETURNED TO SENDER' => 0,
    'ONGOING DELIVERY' => 0,
    'DELIVERED' => 0,
];

foreach ($rows as $row) {
    $status = strtoupper(trim((string)($row['Status'] ?? '')));
    if (isset($statusCounts[$status])) {
        $statusCounts[$status]++;
    }
}

$rts = (int)$statusCounts['RETURNED TO SENDER'];
$ogd = (int)$statusCounts['ONGOING DELIVERY'];
$del = (int)$statusCounts['DELIVERED'];
$totalCount = count($rows);
$ndrPercent = $totalCount > 0 ? round((($rts + $ogd) / $totalCount) * 100, 1) : 0;

function formatDateCell($value) {
    if ($value === null) {
        return '';
    }

    $value = trim((string)$value);
    if ($value === '' || $value === '0000-00-00' || $value === '0000-00-00 00:00:00') {
        return '';
    }

    $ts = strtotime($value);
    if ($ts === false) {
        return '';
    }

    return date('d F Y', $ts);
}

function buildDefaultPdfFileName($dateReleasedValue, $parcelNoValue) {
    global $currentDeptCode;
    $text = trim((string)$dateReleasedValue);
    if ($text === '' || $text === '0000-00-00' || $text === '0000-00-00 00:00:00') {
        return '';
    }

    $ts = strtotime($text);
    if ($ts === false) {
        return '';
    }

    $formattedDate = date('ymd', $ts);
    $formattedParcelNo = sprintf('%03d', (int)$parcelNoValue);
    return strtoupper($currentDeptCode) . '-' . $formattedDate . '-' . $formattedParcelNo;
}

function extractBatchIdFromSenderDetails($senderDetails) {
    $text = trim((string)$senderDetails);
    if ($text === '') {
        return '';
    }
    if (preg_match('/Batch ID:\s*([A-Za-z0-9\-]+)/i', $text, $m)) {
        return trim((string)$m[1]);
    }
    return '';
}

$years = [];
foreach ($rows as $row) {
    $dateAfd = trim((string)($row['Date released to AFD'] ?? ''));
    if ($dateAfd !== '' && preg_match('/(\d{4})/', $dateAfd, $m)) {
        $years[] = $m[1];
    }
}
$years = array_values(array_unique($years));
rsort($years);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tracking Page</title>
    <link rel="icon" type="image/x-icon" href="../assets/DHSUDLogo.ico">
    <link rel="stylesheet" href="../main.css">
    <style>
        body {
            background: #ffffff;
        }

        .tracking-return-link {
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
            color: #000;
            font-weight: 700;
            text-decoration: none;
            padding: 5px 16px;
            border-radius: 6px;
            font-size: 1rem;
            transition: background 0.2s, color 0.2s;
        }

        .tracking-return-link:hover {
            background: #e3e6f3;
            color: black;
        }

        .tracking-view-shell {
            max-width: 1280px;
            margin: 0 auto;
            padding: 0 1.8rem 0;
        }

        .tracking-view-shell .top-bar {
            margin-top: 0.5rem;
            margin-bottom: 0.2rem;
        }

        .tracking-view-shell .top-bar-left {
            visibility: visible;
        }

        .tracking-view-shell .table-sort-bar {
            justify-content: flex-end;
            gap: 6px;
        }

        .tracking-view-shell .table-sort-select {
            min-width: 96px;
            border-radius: 8px;
            border: 1.6px solid #222;
            height: 40px;
            padding: 0 8px;
            font-weight: 600;
        }

        .tracking-view-shell .table-year-trigger {
            min-width: 110px;
            height: 40px;
            padding: 0 8px;
            border-radius: 8px;
            border: 1.6px solid #222;
            font-weight: 600;
        }

        .tracking-view-shell .table-search-input {
            width: 165px;
            max-width: 165px;
            border-radius: 8px;
            height: 40px;
            padding: 0 10px;
        }

        .tracking-view-shell .table-search-btn {
            border-radius: 8px;
            width: 32px;
            height: 32px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 1.6px solid #222;
            background: #fff;
        }

        .tracking-view-shell .table-search-btn img {
            width: 20px;
            height: 20px;
            margin: 0;
        }

        .tracking-view-shell .table-scroll-area {
            padding: 0;
        }

        .tracking-table td.notice-code-cell {
            white-space: normal !important;
            word-break: normal !important;
            overflow-wrap: break-word !important;
            min-width: 0;
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

        .tracking-table td[data-col]:not(.notice-code-cell) {
            white-space: pre-wrap;
            word-break: break-word;
            overflow-wrap: anywhere;
        }

        .tracking-view-shell .tracking-table-scroll {
            overflow-x: auto;
            width: 100%;
        }

        .tracking-view-shell .tracking-table {
            table-layout: fixed;
            width: 100%;
            min-width: 1760px;
            border-collapse: collapse;
        }

        .tracking-view-shell .tracking-table th,
        .tracking-view-shell .tracking-table td {
            width: auto;
            min-width: 0;
        }

        .tracking-view-shell .tracking-table th:nth-child(1),
        .tracking-view-shell .tracking-table td:nth-child(1) { width: 150px; }
        .tracking-view-shell .tracking-table th:nth-child(2),
        .tracking-view-shell .tracking-table td:nth-child(2) { width: 120px; }
        .tracking-view-shell .tracking-table th:nth-child(3),
        .tracking-view-shell .tracking-table td:nth-child(3) { width: 90px; }
        .tracking-view-shell .tracking-table th:nth-child(4),
        .tracking-view-shell .tracking-table td:nth-child(4) { width: 170px; }
        .tracking-view-shell .tracking-table th:nth-child(5),
        .tracking-view-shell .tracking-table td:nth-child(5) { width: 170px; }
        .tracking-view-shell .tracking-table th:nth-child(6),
        .tracking-view-shell .tracking-table td:nth-child(6) { width: 170px; }
        .tracking-view-shell .tracking-table th:nth-child(7),
        .tracking-view-shell .tracking-table td:nth-child(7) { width: 170px; }
        .tracking-view-shell .tracking-table th:nth-child(8),
        .tracking-view-shell .tracking-table td:nth-child(8) { width: 130px; }
        .tracking-view-shell .tracking-table th:nth-child(9),
        .tracking-view-shell .tracking-table td:nth-child(9) { width: 130px; }
        .tracking-view-shell .tracking-table th:nth-child(10),
        .tracking-view-shell .tracking-table td:nth-child(10) { width: 220px; }
        .tracking-view-shell .tracking-table th:nth-child(11),
        .tracking-view-shell .tracking-table td:nth-child(11) { width: 120px; }
        .tracking-view-shell .tracking-table th:nth-child(12),
        .tracking-view-shell .tracking-table td:nth-child(12) { width: 120px; }

        @media (max-width: 1200px) {
            .tracking-view-shell .tracking-table {
                min-width: 1400px;
            }
        }

        .statistics-section {
            margin-top: 1rem;
            justify-content: center;
            width: 100%;
        }

        .statistics-title {
            margin-right: 0.4rem;
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
            <img src="../assets/DHSUD_Header.svg" alt="Admin Home Header" class="admin-home-header-img">
        </div>
        <div class="admin-home-header-border"></div>
    </div>

    <div id="homeSidebarOverlay" class="home-sidebar-overlay" hidden></div>
    <aside id="homeSidebar" class="home-sidebar" aria-hidden="true">
        <div class="home-sidebar-head">
            <button type="button" id="homeSidebarClose" class="home-sidebar-close" aria-label="Close sidebar">&times;</button>
        </div>

        <nav class="home-sidebar-nav" aria-label="Department menu">
            <a href="Tracking_Page.php?dept=emes" class="home-sidebar-link dept-emes<?= $currentDept === 'emes' ? ' is-active' : '' ?>" data-dept="emes"><img src="../assets/Department_File_Icon.svg" alt="" aria-hidden="true"><span>EMES</span></a>
            <a href="Tracking_Page.php?dept=prls" class="home-sidebar-link dept-prls<?= $currentDept === 'prls' ? ' is-active' : '' ?>" data-dept="prls"><img src="../assets/Department_File_Icon.svg" alt="" aria-hidden="true"><span>PRLS</span></a>
            <a href="Tracking_Page.php?dept=afd" class="home-sidebar-link dept-afd<?= $currentDept === 'afd' ? ' is-active' : '' ?>" data-dept="afd"><img src="../assets/Department_File_Icon.svg" alt="" aria-hidden="true"><span>AFD</span></a>
            <a href="Tracking_Page.php?dept=phsd" class="home-sidebar-link dept-phsd<?= $currentDept === 'phsd' ? ' is-active' : '' ?>" data-dept="phsd"><img src="../assets/Department_File_Icon.svg" alt="" aria-hidden="true"><span>PHSD</span></a>
            <a href="Tracking_Page.php?dept=elupd" class="home-sidebar-link dept-elupd<?= $currentDept === 'elupd' ? ' is-active' : '' ?>" data-dept="elupd"><img src="../assets/Department_File_Icon.svg" alt="" aria-hidden="true"><span>ELUPD</span></a>
            <a href="Tracking_Page.php?dept=ord" class="home-sidebar-link dept-ord<?= $currentDept === 'ord' ? ' is-active' : '' ?>" data-dept="ord"><img src="../assets/Department_File_Icon.svg" alt="" aria-hidden="true"><span>ORD</span></a>
        </nav>
    </aside>

    <div class="tracking-view-shell admin-table-container">
        <div class="top-bar">
            <div class="top-bar-left">
                <a href="../index.php" class="tracking-return-link">
                    <img src="../assets/Return_Icon.svg" alt="Return"> <span>Return</span>
                </a>
            </div>
            <div class="top-bar-title">MAIL TRACKING RECORDS</div>
            <div class="table-sort-bar">
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
                    <select id="tableSortYear" class="table-sort-select table-sort-select-native" aria-label="Filter by year and month">
                        <option value="" disabled hidden>Year</option>
                        <option value="all" selected>All</option>
                        <?php foreach ($years as $year): ?>
                            <option value="<?= htmlspecialchars($year) ?>"><?= htmlspecialchars($year) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="hidden" id="tableSortMonth" value="">
                </div>
                <input type="text" id="tableSearchInput" class="table-search-input" placeholder="Search" aria-label="Search records">
                <button type="button" class="table-search-btn" id="tableSearchBtn" aria-label="Search">
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
                                <?php foreach ($columns as $header): ?>
                                    <th><?= htmlspecialchars($header) ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody id="trackingTableBody">
                            <?php if (empty($rows)): ?>
                                <tr>
                                    <td colspan="<?= count($columns) ?>">No records found.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($rows as $row): ?>
                                    <?php
                                    $dateAfdRaw = (string)($row['Date released to AFD'] ?? '');
                                    $rowYear = '';
                                    $rowMonth = '';
                                    $tsDateAfd = strtotime($dateAfdRaw);
                                    if ($tsDateAfd !== false) {
                                        $rowYear = date('Y', $tsDateAfd);
                                        $rowMonth = date('m', $tsDateAfd);
                                    } elseif ($dateAfdRaw !== '' && preg_match('/(\d{4})-(\d{1,2})/', $dateAfdRaw, $m)) {
                                        $rowYear = $m[1];
                                        $rowMonth = str_pad((string)$m[2], 2, '0', STR_PAD_LEFT);
                                    } elseif ($dateAfdRaw !== '' && preg_match('/(\d{4})/', $dateAfdRaw, $m)) {
                                        $rowYear = $m[1];
                                    }
                                    $rowTextSearch = strtoupper(implode(' ', array_map(static function ($v) {
                                        return trim((string)$v);
                                    }, $row)));

                                    $trackingNo = trim((string)($row['Tracking No.'] ?? $row['Tracking No'] ?? ''));
                                    $statusValue = strtoupper(trim((string)($row['Status'] ?? '')));
                                    $statusClass = '';
                                    if ($statusValue === 'DELIVERED') {
                                        $statusClass = 'status-text status-delivered';
                                    } elseif ($statusValue === 'RETURNED TO SENDER') {
                                        $statusClass = 'status-text status-returned';
                                    } elseif ($statusValue === 'ONGOING DELIVERY') {
                                        $statusClass = 'status-text status-ongoing';
                                    } elseif ($statusValue === 'PERSONALLY RECEIVED') {
                                        $statusClass = 'status-text status-personal';
                                    }
                                    $rowBatchId = extractBatchIdFromSenderDetails($row['Sender Details'] ?? '');
                                    ?>
                                    <tr data-year="<?= htmlspecialchars($rowYear) ?>" data-month="<?= htmlspecialchars($rowMonth) ?>" data-search="<?= htmlspecialchars($rowTextSearch) ?>" data-status="<?= htmlspecialchars($statusValue) ?>" data-batch-id="<?= htmlspecialchars($rowBatchId) ?>">
                                        <?php foreach ($columns as $idx => $colName): ?>
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
                                            <?php if ($idx === 0): ?>
                                                <td class="notice-code-cell">
                                                    <div>
                                                        <span><?= htmlspecialchars((string)$cellValue) ?></span>
                                                    </div>
                                                </td>
                                            <?php elseif ($colName === 'Status'): ?>
                                                <td data-col="Status"><span class="<?= htmlspecialchars($statusClass) ?>"><?= htmlspecialchars((string)$cellValue) ?></span></td>
                                            <?php elseif ($colName === 'File Name (PDF)'): ?>
                                                <?php
                                                $fileName = trim((string)$cellValue);
                                                $defaultPdfName = buildDefaultPdfFileName($row['Date released to AFD'] ?? '', $row['Parcel No.'] ?? 0);
                                                $resolvedPdfName = $fileName !== '' ? basename($fileName) : $defaultPdfName;
                                                $proofAssetName = $trackingNo !== '' ? ('proof_' . $trackingNo . '.pdf') : '';
                                                $fileHref = $proofAssetName !== '' ? '../JRS_PDFs/' . rawurlencode($proofAssetName) : '';
                                                ?>
                                                <td data-col="<?= htmlspecialchars($colName) ?>" class="pdf-link-cell">
                                                    <?php if ($fileHref !== '' && $resolvedPdfName !== ''): ?>
                                                        <a href="<?= htmlspecialchars($fileHref) ?>" class="pdf-link-in-cell" target="_blank" rel="noopener noreferrer"><?= htmlspecialchars($resolvedPdfName) ?></a>
                                                    <?php endif; ?>
                                                </td>
                                            <?php else: ?>
                                                <td data-col="<?= htmlspecialchars($colName) ?>"><?= htmlspecialchars((string)$cellValue) ?></td>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="statistics-section">
            <div class="statistics-title">Statistics</div>
            <div class="statistics-bar">
                <button type="button" class="stat-box stat-rtos stat-filter-btn" data-status-filter="RETURNED TO SENDER" title="Show Returned to Sender">
                    Returned to Sender
                    <div class="stat-count"><?= $rts ?></div>
                </button>
                <button type="button" class="stat-box stat-ongoing stat-filter-btn" data-status-filter="ONGOING DELIVERY" title="Show Ongoing Delivery">
                    Ongoing Delivery
                    <div class="stat-count"><?= $ogd ?></div>
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
                    <div class="stat-count"><?= htmlspecialchars((string)$ndrPercent) ?>%</div>
                </button>
            </div>
        </div>
    </div>

    <script>
        (function () {
            const yearSelect = document.getElementById('tableSortYear');
            const monthInput = document.getElementById('tableSortMonth');
            const yearTrigger = document.getElementById('tableSortYearTrigger');
            const yearMonthFilterWrap = document.getElementById('tableYearMonthFilter');
            const yearList = document.getElementById('tableYearList');
            const monthGrid = document.getElementById('tableMonthGrid');
            const searchInput = document.getElementById('tableSearchInput');
            const searchBtn = document.getElementById('tableSearchBtn');
            const statButtons = Array.from(document.querySelectorAll('.stat-filter-btn'));
            const rows = Array.from(document.querySelectorAll('#trackingTableBody tr[data-year]'));
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

            function rowMatchesStatusFilter(rowStatus, normalizedFilter) {
                const status = ((rowStatus || '') + '').trim().toUpperCase();
                if (normalizedFilter === 'ALL') return true;
                if (normalizedFilter === 'NDR') {
                    return status === 'RETURNED TO SENDER' || status === 'ONGOING DELIVERY';
                }
                return status === normalizedFilter;
            }

            function updateStatusFilterButtonsUI() {
                const active = normalizeStatusFilterValue(selectedStatusFilter);
                statButtons.forEach(function (btn) {
                    const buttonFilter = normalizeStatusFilterValue(btn.getAttribute('data-status-filter'));
                    const isActive = buttonFilter === active;
                    btn.classList.toggle('stat-filter-active', isActive);
                    btn.setAttribute('aria-pressed', isActive ? 'true' : 'false');
                });
            }

            function getMonthShortLabel(monthValue) {
                const monthMap = {
                    '01': 'Jan', '02': 'Feb', '03': 'Mar', '04': 'Apr',
                    '05': 'May', '06': 'Jun', '07': 'Jul', '08': 'Aug',
                    '09': 'Sep', '10': 'Oct', '11': 'Nov', '12': 'Dec'
                };
                return monthMap[monthValue] || '';
            }

            function syncYearMonthFilterUI() {
                if (!yearSelect || !monthInput) return;
                const yearLabel = document.getElementById('tableSortYearLabel');
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
                if (!yearSelect || !yearList) {
                    syncYearMonthFilterUI();
                    return;
                }
                const activeValue = ((yearSelect.value || '') + '').trim() || 'all';
                const buttons = [];
                Array.from(yearSelect.options).forEach(function(opt) {
                    const value = ((opt.value || '') + '').trim();
                    if (!value) return;
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
                if (dropdown) dropdown.hidden = true;
                if (yearTrigger) yearTrigger.setAttribute('aria-expanded', 'false');
            }

            function openYearMonthDropdown() {
                const dropdown = document.getElementById('tableYearMonthDropdown');
                if (dropdown) dropdown.hidden = false;
                if (yearTrigger) yearTrigger.setAttribute('aria-expanded', 'true');
            }

            function filterRows() {
                const search = (searchInput ? searchInput.value : '').trim().toUpperCase();
                const normalizedStatusFilter = normalizeStatusFilterValue(selectedStatusFilter);
                const selectedYearRaw = ((yearSelect && yearSelect.value) ? yearSelect.value : '').trim();
                const selectedYear = (selectedYearRaw === 'all') ? '' : selectedYearRaw;
                let selectedMonth = monthInput ? ((monthInput.value || '') + '').trim() : '';
                if (!selectedYear) selectedMonth = '';
                const hasYearFilter = selectedYear !== '';
                const hasMonthFilter = selectedMonth !== '';
                const hasStatusFilter = normalizedStatusFilter !== 'ALL';
                const batchPreserveMode = (search === '' && (hasYearFilter || hasMonthFilter || hasStatusFilter));
                const matchedBatchIds = new Set();

                rows.forEach(function (row) {
                    const rowYear = ((row.getAttribute('data-year') || '') + '').trim();
                    const rowMonth = ((row.getAttribute('data-month') || '') + '').trim();
                    const rowSearch = ((row.getAttribute('data-search') || '') + '').toUpperCase();
                    const rowStatus = ((row.getAttribute('data-status') || '') + '').toUpperCase();
                    const rowBatchId = ((row.getAttribute('data-batch-id') || '') + '').trim();
                    const yearMatch = (!selectedYear || rowYear === selectedYear);
                    const monthMatch = (!selectedMonth || rowMonth === selectedMonth);
                    const searchMatch = (search === '' || rowSearch.indexOf(search) !== -1);
                    const statusMatch = rowMatchesStatusFilter(rowStatus, normalizedStatusFilter);
                    const visible = (yearMatch && monthMatch && searchMatch && statusMatch);
                    if (visible && batchPreserveMode && rowBatchId !== '') {
                        matchedBatchIds.add(rowBatchId);
                    }
                    row.style.display = visible ? '' : 'none';
                });

                if (batchPreserveMode && matchedBatchIds.size > 0) {
                    rows.forEach(function (row) {
                        const rowBatchId = ((row.getAttribute('data-batch-id') || '') + '').trim();
                        if (rowBatchId !== '' && matchedBatchIds.has(rowBatchId)) {
                            row.style.display = '';
                        }
                    });
                }
            }

            if (yearSelect) {
                yearSelect.addEventListener('change', function () {
                    if (yearSelect.value === 'all' && monthInput) {
                        monthInput.value = '';
                    }
                    syncYearMonthFilterUI();
                    filterRows();
                });
            }

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
                    filterRows();
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
                    filterRows();
                    closeYearMonthDropdown();
                });
            }

            document.addEventListener('click', function(e) {
                if (!yearMonthFilterWrap) return;
                if (yearMonthFilterWrap.contains(e.target)) return;
                closeYearMonthDropdown();
            });

            if (searchInput) {
                searchInput.addEventListener('input', filterRows);
                searchInput.addEventListener('keydown', function (event) {
                    if (event.key === 'Enter') {
                        event.preventDefault();
                        filterRows();
                    }
                });
            }
            if (searchBtn) {
                searchBtn.addEventListener('click', filterRows);
            }

            statButtons.forEach(function (btn) {
                btn.addEventListener('click', function () {
                    selectedStatusFilter = normalizeStatusFilterValue(btn.getAttribute('data-status-filter'));
                    updateStatusFilterButtonsUI();
                    filterRows();
                });
            });

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
            }

            updateStatusFilterButtonsUI();
            rebuildYearMonthFilterOptionsFromSelect();
            filterRows();
            closeYearMonthDropdown();
            initHomeSidebar();
        })();
    </script>
</body>
</html>
