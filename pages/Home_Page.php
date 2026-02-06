
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
}


// Fetch all rows to display
try {
    $rows = $pdo->query('SELECT * FROM mailtracking')->fetchAll();
} catch (Exception $e) {
    $rows = [];
    $message = 'Failed to load records: ' . $e->getMessage();
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
$statusOptions = ['DELIVERED','RETURNED TO SENDER','ON GOING DELIVERY', 'PERSONALLY RECEIVED',];

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
$ogd = (int)$statusCounts['ON GOING DELIVERY'] ?? 0;

// Totals and non-delivery rate
$totalCount = count($rows);
$ndrPercent = ($totalCount > 0) ? round((($rts + $ogd )/ $totalCount) * 100, 1) : 0;

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Home Page</title>
    <link rel="stylesheet" href="../main.css">
    <style>
        table { width:100%; border-collapse: collapse; }
        th, td { border: 1px solid #ccc; padding: 8px; font-size: 0.7rem; text-align: center; white-space: pre-wrap;}
        @media (max-width: 768px) {
            table { font-size: 0.65rem; }
            th, td { padding: 6px; }
        }
        @media (max-width: 480px) {
            table { font-size: 0.6rem; }
            th, td { padding: 4px; }
        }
        th { background:#22336A; color: #ffffffff}
        form.inline { margin:0; }
        select { padding:4px; }
        button.save { padding:4px 8px; }
        .message { padding:8px; margin:10px 0; }
        .row-message { font-size:0.9em; color: green; margin-top:6px; opacity:1; transition: opacity 0.5s ease; }
        .stats { margin-bottom:10px; }
        .stat-item { display:inline-block; margin-right:12px; padding:4px 6px; background:#f1f1f1; border-radius:4px; font-weight:600; }
        .btn-track { padding:6px 12px; font-weight: 600; background-color:#22336A; color:white; border:none; border-radius:4px; cursor:pointer; font-size:0.7rem; }
        .btn-track:hover { background-color:#0b7dda; }

        /* Modal Form UI - Two Column Grid */
        .edit-modal {
            background: #fff;
            border-radius: 4px;
            border: solid 15px #22336A ;
            box-shadow: 0 2px 16px rgba(0,0,0,0.18);
            padding: 32px 32px 24px 32px;
            max-width: 780px;
            width: 100%;
            margin: 40px auto;
            position: relative;
            box-sizing: border-box;
        }
        @media (max-width: 768px) {
            .edit-modal {
                padding: 20px 20px 16px 20px;
                max-width: 90vw;
            }
        }
        @media (max-width: 480px) {
            .edit-modal {
                padding: 16px 16px 12px 16px;
                max-width: 95vw;
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
        .edit-modal select {
            width: 100%;
            padding: 8px 10px;
            border: 1px solid #bdbdbd;
            border-radius: 4px;
            font-size: 1em;
            background: #f7f8fa;
            margin-bottom: 10px;
            display: block;
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
            z-index: 1000;
            backdrop-filter: blur(4px);
        }
    </style>
</head>

<body class="admin-home-bg">
    <div class="admin-home-header">
        <img src="../assets/Admin_HomePage_New.svg" alt="Admin Home Header" class="admin-home-header-img">
        <div class="admin-home-header-border"></div>
        <div style="position: absolute; top: 50px; left: 5px; z-index: 100;">
            <a href="logout.php" style="text-decoration: none; font-weight: 600; color: #726868;">Logout</a>
        </div>
    </div>
        <!-- Edit Modal (hidden by default) -->
        <div id="editModalOverlay" class="edit-modal-overlay" style="display:none;">
            <div class="edit-modal" id="editModal">
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
                            <label for="editParcelDetails">Parcel Details</label>
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
            <div class="edit-modal" id="addModal">
                <button class="modal-close" onclick="closeAddModal()" title="Close">&times;</button>
                <h2 style="text-align:center;color:#1a237e;font-size:1.3em;font-weight:bold;margin-bottom:18px;letter-spacing:1px;">ADD NEW RECORD</h2>
                <form id="addForm" action="../api/Add.php" method="post" autocomplete="off">

                    <div style="display:contents">
                        <div>
                            <label for="addNoticeCode">Notice/Order Code*</label>
                            <input type="text" name="notice_Code" id="addNoticeCode" required />
                        </div>
                        <div>
                            <label for="addDateAfd">Date Released to AFD*</label>
                            <input type="date" name="dateReleased" id="addDateAfd" required>
                        </div>
                        <div>
                            <label for="addParcelNo">Parcel No.</label>
                            <input type="number" name="parcelNo" id="addParcelNo">
                        </div>
                        <div>
                            <label for="addTrackingNo">Tracking No.</label>
                            <input type="text" name="trackingNo" id="addTrackingNo">
                        </div>
                        <div style="grid-column:1/span 2;">
                            <label for="addRecipient">Recipient Details</label>
                            <textarea name="recipientDetails" rows="2" id="addRecipient"></textarea>
                        </div>
                        <div style="grid-column:1/span 2;">
                            <label for="addParcelDetails">Parcel Details</label>
                            <textarea name="parcelDetails" rows="2" id="addParcelDetails"></textarea>
                        </div>
                        <div style="grid-column:1/span 2;">
                            <label for="addSender">Sender Details</label>
                            <textarea name="senderDetails" rows="2" id="addSender"></textarea>
                        </div>
                        <div style="grid-column:1/span 2;">
                            <label for="addFileName">File Name (PDF)</label>
                            <input type="text" name="fileName" id="addFileName">
                        </div>
                    </div>
                    <div class="modal-actions">
                        <button type="submit" class="modal-btn save" style="background:#22336A; color:#fff;">Add Record</button>
                        <button type="button" class="modal-btn cancel" style="background:#b94a48; color:#fff;" onclick="clearAddForm()">Clear Form</button>
                    </div>
                </form>
            </div>
        </div>
    <div class="admin-home-container">
        <div class="statistics-section">
            <div class="statistics-title">STATISTICS</div>
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
    <div class="admin-table-container">
        <div class="table-title">MAIL TRACKING RECORDS</div>
        <div class="table-search-bar">
            <div class="table-sort-bar">
                <select id="tableSortYear" class="table-sort-select" required style="min-width:70px;" aria-label="Year">
                    <option value="" disabled selected hidden>Year</option>
                    <option value="all">All</option>
                    <?php
                    // Collect unique years from the 'Date released to AFD' column
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
            </div>
            <input type="text" id="tableSearchInput" class="table-search-input" placeholder="Search">
            <button class="table-search-btn" id="tableSearchBtn" title="Search">
                <img src="../assets/Search Icon.svg" alt="Search" class="table-search-icon">
            </button>
        </div>
        <div class="table-scroll-area">
            <table style="width:100%; border-collapse: collapse; background: rgba(255,255,255,0.95);">
                <thead>
                    <tr>
                        <?php foreach ($columns as $h): ?>
                            <th><?= htmlspecialchars($h) ?></th>
                        <?php endforeach; ?>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rows)): ?>
                        <tr><td colspan="<?= count($columns) ?>">No records found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($rows as $row): ?>
                            <tr>
                                <td>
                                    <div style="display: flex; align-items: center; gap: 0.3em; position: relative;">
                                        <div class="row-menu-container" style="position: relative;">
                                            <button class="row-menu-btn" type="button" tabindex="0" aria-label="Row menu" onclick="toggleRowMenu(event, '<?= htmlspecialchars($row['Notice/Order Code'] ?? '') ?>')">
                                                <span style="font-size:1.5em;line-height:1;">&#8942;</span>
                                            </button>
                                            <div class="row-menu-dropdown" style="display:none; position:absolute; left:0; top:32px; min-width:120px; background:#fff; border:1px solid #d1d5db; box-shadow:0 2px 8px rgba(0,0,0,0.08); border-radius:6px; z-index:1000; padding:0.3em 0;">
                                                <button class="row-menu-item" onclick="editRow('<?= htmlspecialchars($row['Notice/Order Code'] ?? '') ?>')" style="display:flex;align-items:center;gap:0.5em;padding:8px 18px;width:100%;background:none;border:none;cursor:pointer;color:#22336a;font-size:1em;font-weight:600;text-align:left;">
                                                    <img src="../assets/Edit_Icon.svg" alt="Edit" style="width:20px;height:20px;"> Edit
                                                </button>
                                                <button class="row-menu-item" onclick="deleteRecord('<?= htmlspecialchars($row['Notice/Order Code'] ?? '') ?>')" style="display:flex;align-items:center;gap:0.5em;padding:8px 18px;width:100%;background:none;border:none;cursor:pointer;color:#22336a;font-size:1em;font-weight:600;text-align:left;">
                                                    <img src="../assets/Delete_Icon.svg" alt="Delete" style="width:20px;height:20px;"> Delete
                                                </button>
                                            </div>
                                        </div>
                                        <span><?= htmlspecialchars($row['Notice/Order Code'] ?? '') ?></span>
                                    </div>
                                </td>
                                        <script>
                                            function toggleRowMenu(event, noticeCode) {
                                                event.stopPropagation();
                                                var btn = event.currentTarget;
                                                var dropdown = btn.parentElement.querySelector('.row-menu-dropdown');
                                                var allDropdowns = document.querySelectorAll('.row-menu-dropdown');
                                                allDropdowns.forEach(function(dd) { if (dd !== dropdown) dd.style.display = 'none'; });
                                                dropdown.style.display = dropdown.style.display === 'block' ? 'none' : 'block';
                                                document.addEventListener('click', function hideMenu(e) {
                                                    if (!dropdown.contains(e.target) && e.target !== btn) {
                                                        dropdown.style.display = 'none';
                                                        document.removeEventListener('click', hideMenu);
                                                    }
                                                });
                                            }
                                            function deleteRecord(noticeCode) {
                                                if (confirm('Are you sure you want to delete this record?')) {
                                                   if (!noticeCode) return;
                                                        // Redirect to PHP delete handler via POST using a form
                                                        const form = document.createElement('form');
                                                        form.method = 'POST';
                                                        form.action = '../api/Delete.php'; // your PHP file that handles deletion

                                                        const input = document.createElement('input');
                                                        input.type = 'hidden';
                                                        input.name = 'noticeCode';       // the field name your table uses
                                                        input.value = noticeCode;

                                                        form.appendChild(input);
                                                        document.body.appendChild(form);
                                                        form.submit();
                                                }
                                            }
                                        </script>
                                <?php foreach ($columns as $idx => $colName): ?>
                                    <?php if ($idx === 0) continue; // skip Notice/Order Code, already rendered ?>
                                    <?php if ($idx === 8): // STATUS column (9th)
                                    ?>
                                        <td>
                                            <form method="post" class="inline" style="margin:0;">
                                                <input type="hidden" name="notice_code" value="<?= htmlspecialchars($row['Notice/Order Code'] ?? '') ?>">
                                                <select name="status" onchange="this.form.submit()">
                                                    <?php
                                                    $current = trim($row['Status'] ?? '');
                                                    // placeholder option when no current status
                                                    $phSel = ($current === '') ? ' selected' : '';
                                                    echo '<option value="" disabled' . $phSel . '>Select status</option>';
                                                    // if current not in options, show it first
                                                    if ($current !== '' && !in_array($current, $statusOptions, true)) {
                                                        echo '<option value="' . htmlspecialchars($current) . '" selected>' . htmlspecialchars($current) . '</option>';
                                                    }
                                                    foreach ($statusOptions as $opt) {
                                                        $sel = (trim($opt) === $current) ? ' selected' : '';
                                                        echo '<option value="' . htmlspecialchars($opt) . '"' . $sel . '>' . htmlspecialchars($opt) . '</option>';
                                                    }
                                                    ?>
                                                </select>
                                                <?php if (!empty($updatedNotice) && trim($row['Notice/Order Code'] ?? '') === $updatedNotice): ?>
                                                    <div class="row-message">Status updated</div>
                                                <?php endif; ?>
                                            </form>
                                        </td>
                                    <?php else: ?>
                                        <td><?= htmlspecialchars($row[$colName] ?? '') ?></td>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                                <td>
                                    <?php 
                                    // Try different column name variations
                                    $trackingNo = trim($row['Tracking No.'] ?? $row['Tracking No'] ?? $row['tracking_no'] ?? $row['TrackingNo'] ?? '');
                                    ?>
                                    <?php if (!empty($trackingNo) && $trackingNo !== '0'): ?>
                                        <button class="btn-track" onclick="trackJRS('<?= htmlspecialchars($trackingNo) ?>')">Track</button>
                                    <?php else: ?>
                                        <span style="color:#999; font-size:12px;">No tracking #</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div id="trackingModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:9999;">
            <div style="background:#fff;width:80%;max-width:900px;margin:5% auto;padding:20px;border-radius:8px;max-height:80vh;overflow:auto;">
                <h2>JRS Tracking</h2>
                <div id="trackingContent">Loading...</div>
                <button onclick="closeTrackingModal()">Close</button>
            </div>
        </div>

        <!-- Add New Record Modal (hidden by default) -->
        
        <div>
            <button onclick="openAddModal()" style="background:#22336A;color:#fff;padding:8px 15px;margin-top:10px;border:none;border-radius:4px;font-weight:700;font-size:0.8rem;cursor:pointer;">Add</button>
        </div>
        
        <script>
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
        }
        // Submit handler (AJAX)
        document.addEventListener('DOMContentLoaded', function() {
            var addForm = document.getElementById('addForm');
            if (addForm) {
                addForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    var formData = new FormData(addForm);
                    
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
                            location.reload();
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
        function closeTrackingModal() {
            document.getElementById('trackingModal').style.display = 'none';
        }

                        // Modal logic
                        function openEditModal(rowData) {
                            document.getElementById('editModalOverlay').style.display = 'flex';
                            // Fill form fields - CRITICAL: original_notice_code must be set for lookup
                            var noticeCode = (rowData['Notice/Order Code'] || '').trim();
                            document.getElementById('editNoticeCode').value = noticeCode;
                            document.getElementById('editNoticeCodeDisplay').value = noticeCode;
                            document.getElementById('editDateAfd').value = rowData['Date released to AFD'] || '';
                            document.getElementById('editParcelNo').value = rowData['Parcel No.'] || '';
                            document.getElementById('editRecipient').value = rowData['Recipient Details'] || '';
                            document.getElementById('editParcelDetails').value = rowData['Parcel Details'] || '';
                            document.getElementById('editSender').value = rowData['Sender Details'] || '';
                            document.getElementById('editFileName').value = rowData['File Name (PDF)'] || '';
                            document.getElementById('editTrackingNo').value = rowData['Tracking No.'] || '';
                            document.getElementById('editStatus').value = rowData['Status'] || '';
                            document.getElementById('editTransmittal').value = rowData['Transmittal Remarks/Received By'] || '';
                            document.getElementById('editDate').value = rowData['Date'] || '';
                            document.getElementById('editEvaluator').value = rowData['Evaluator'] || '';
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
                            // Find row data in JS (from PHP array rendered as JS object)
                            var row = window.mailRows.find(r => r['Notice/Order Code'] === noticeCode);
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
                                        closeEditModal();
                                        location.reload();
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

        // Track parcel using JRS Express and copy tracking number to clipboard
            function trackJRS(trackingNo) {
    if (!trackingNo || trackingNo === '0') {
        alert('No valid tracking number found');
        return;
    }

    fetch('../api/jrs-track.php?tracking=' + encodeURIComponent(trackingNo))
        .then(res => res.json())
        .then(data => {

            if (!Array.isArray(data)) {
                throw 'Invalid response';
            }

            let html = `
                <table style="width:100%;border-collapse:collapse;">
                    <tr>
                        <th>Date</th>
                        <th>Status</th>
                        <th>Location</th>
                        <th>Receiver</th>
                    </tr>
            `;

            data.forEach(row => {
                const date = row.dateReceived
                    ? new Date(row.dateReceived).toLocaleString()
                    : '';

                // ✅ Receiver logic (IMPORTANT PART)
                let receiverText = '';
                if (row.receiver) {
                    receiverText = row.receiver;

                    if (row.relation) {
                        receiverText += `<br><small style="color:#666;">${row.relation}</small>`;
                    }
                }

                html += `
                    <tr>
                        <td>${date}</td>
                        <td>${row.statusText ?? ''}</td>
                        <td>${row.location ?? ''}</td>
                        <td>${receiverText}</td>
                    </tr>
                `;
            });

            html += '</table>';

            document.getElementById('trackingContent').innerHTML = html;
            document.getElementById('trackingModal').style.display = 'block';
        })
        .catch(err => {
            alert('Unable to fetch tracking info');
            console.error(err);
        });
}



        // Table search and sort functionality (filter by Notice/Order Code and year)
        function filterTableRows() {
            const input = document.getElementById('tableSearchInput');
            const filter = input.value.toLowerCase();
            const yearSelect = document.getElementById('tableSortYear');
            let selectedYear = yearSelect.value;
            if (selectedYear === 'all' || !selectedYear) selectedYear = '';
            const table = document.querySelector('.admin-table-container table');
            const trs = table.querySelectorAll('tbody tr');
            trs.forEach(tr => {
                const tds = tr.querySelectorAll('td');
                if (!tds.length) {
                    tr.style.display = '';
                    return;
                }
                // Notice/Order Code is first td, Date released to AFD is 2nd td (index 1)
                const code = tds[0].textContent.toLowerCase();
                const dateAfd = tds[1] ? tds[1].textContent : '';
                let yearMatch = true;
                if (selectedYear) {
                    yearMatch = dateAfd.indexOf(selectedYear) > -1;
                }
                const codeMatch = code.indexOf(filter) > -1;
                tr.style.display = (codeMatch && yearMatch) ? '' : 'none';
            });
        }
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('.row-message').forEach(function(el) {
                setTimeout(function() {
                    el.style.opacity = '0';
                    setTimeout(function() { if (el.parentNode) el.parentNode.removeChild(el); }, 500);
                }, 2000);
            });
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
        });
        </script>
    </div>
</body>
</html>