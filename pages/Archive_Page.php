<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth.php';

// Require login to access this page
requireLogin();

// Fetch archived rows (example: Status = 'ARCHIVED' or a dedicated column, adjust as needed)
try {
    $rows = $pdo->query('SELECT * FROM archive')->fetchAll();
} catch (Exception $e) {
    $rows = [];
    $message = 'Failed to load records: ' . $e->getMessage();
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

function formatDateCell($value) {
    if ($value === null || $value === '') return '';
    $ts = strtotime($value);
    if ($ts === false) return $value;
    return date('F-d-Y', $ts);
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Archive</title>
    <link rel="icon" type="image/x-icon" href="../assets/DHSUDLogo.ico">
    <link rel="stylesheet" href="../main.css">
    <style>
        .status-cell .status-archived {
            background: #AA4444;
            color: #fff;
            border-radius: 12px;
            padding: 2px 10px;
            font-weight: 600;
            font-size: 0.8em;
            display: inline-block;
        }
    </style>
</head>
<body class="admin-home-bg">
    <div class="admin-home-header">
        <img src="../assets/DHSUD_Header.svg" alt="Admin Home Header" class="admin-home-header-img">
        <div class="admin-home-header-border"></div>
    </div>
    <div class="admin-table-container archive-table-shell">
        <div class="top-bar">
            <div class="top-bar-left">
                <a href="Home_Page.php" class="archive-return-link">
                    <img src="../assets/Return_Icon.svg" alt="Return">
                    <span>Return</span>
                </a>
            </div>
            <div class="top-bar-title">ARCHIVE</div>
            <div class="archive-top-actions">
                <button class="archive-action-btn delete-btn" type="button" onclick="deleteSelected()">Delete Forever</button>
                <button class="archive-action-btn recover-btn" type="button" onclick="recoverSelected()">Recover</button>
            </div>
        </div>
        <div class="table-scroll-area">
            <div class="tracking-table-container">
                <div class="tracking-table-scroll">
                    <table class="listview-table tracking-table">
                        <thead>
                            <tr>
                                <th style="width:40px;">
                                    <div class="checkbox-header-tools">
                                        <input type="checkbox" id="selectAllCheckbox" onclick="toggleAllCheckboxes(this)">
                                    </div>
                                </th>
                                <?php foreach ($columns as $col): ?>
                                    <th><?= htmlspecialchars($col) ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($rows)): ?>
                                <tr><td colspan="<?= count($columns) + 1 ?>">No records found.</td></tr>
                            <?php else: ?>
                                <?php foreach ($rows as $row): ?>
                                    <tr data-id="<?= (int)($row['id'] ?? 0) ?>">
                                        <td>
                                            <input type="checkbox" class="row-checkbox" value="<?= (int)($row['id'] ?? 0) ?>" data-notice="<?= htmlspecialchars($row['Notice/Order Code'] ?? '') ?>">
                                        </td>
                                        <?php foreach ($columns as $col): ?>
                                            <td class="<?= $col === 'Status' ? 'status-cell' : '' ?>">
                                                <?php if ($col === 'Status'): ?>
                                                    <span class="status-archived">ARCHIVED</span>
                                                <?php else: ?>
                                                    <?php
                                                        $cellValue = $row[$col] ?? '';
                                                        if ($col === 'Date released to AFD' || $col === 'Date') {
                                                            $cellValue = formatDateCell($cellValue);
                                                        }
                                                    ?>
                                                    <?= htmlspecialchars($cellValue) ?>
                                                <?php endif; ?>
                                            </td>
                                        <?php endforeach; ?>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <script>
        const CSRF_TOKEN = <?php echo json_encode(getCsrfToken(), JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT); ?>;
        function toggleAllCheckboxes(master) {
            var checkboxes = document.querySelectorAll('.row-checkbox');
            checkboxes.forEach(function(cb) {
                cb.checked = master.checked;
            });
        }
        function deleteRecord(rowIds) {
            if (!rowIds || rowIds.length === 0) return;

            if (!confirm('Are you sure you want to delete selected records?')) return;

            const form = document.createElement('form');
            form.method = 'POST';
            form.action = '../api/archive-delete.php';

            // Loop through all selected ids
            rowIds.forEach(id => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'id[]'; // PHP array
                input.value = id;
                form.appendChild(input);
            });
            const csrf = document.createElement('input');
            csrf.type = 'hidden';
            csrf.name = 'csrf_token';
            csrf.value = CSRF_TOKEN;
            form.appendChild(csrf);

            document.body.appendChild(form);
            form.submit();
        }


        function getSelectedRecords() {
            // Get all checkboxes
            const checkboxes = document.querySelectorAll('.row-checkbox');
            const selected = [];

            // Loop through and check if each is checked
            checkboxes.forEach(cb => {
            if (cb.checked) {
                selected.push(cb.value); // Get the value attribute
            }
            });
            return selected;
        }
        function deleteSelected() {
            const rowIds = getSelectedRecords();

            if (rowIds.length === 0) {
                alert("No records selected!");
                return;
            }

            deleteRecord(rowIds);
        }
        function recoverSelected() {
            const rowIds = getSelectedRecords();

            if (rowIds.length === 0) {
                alert("No records selected!");
                return;
            }
            recoverRecord(rowIds);
        }

        function recoverRecord(rowIds) {
            if (!rowIds || rowIds.length === 0) return;

            if (!confirm('Are you sure you want to recover selected records?')) return;

            const form = document.createElement('form');
            form.method = 'POST';
            form.action = '../api/archived-recover.php';

            // Loop through all selected ids
            rowIds.forEach(id => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'id[]'; // PHP array
                input.value = id;
                form.appendChild(input);
            });
            const csrf = document.createElement('input');
            csrf.type = 'hidden';
            csrf.name = 'csrf_token';
            csrf.value = CSRF_TOKEN;
            form.appendChild(csrf);

            document.body.appendChild(form);
            form.submit();
        }
    </script>
</body>
</html>
