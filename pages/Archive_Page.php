<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth.php';

// Require login to access this page
requireLogin();

// Fetch archived rows (example: Status = 'ARCHIVED' or a dedicated column, adjust as needed)
try {
    $rows = $pdo->query("SELECT * FROM mailtracking WHERE `Status` = 'ARCHIVED'")->fetchAll();
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

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Archive</title>
    <link rel="stylesheet" href="../main.css">
    <style>
        body { background: #f8f9fa; }
        .archive-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin: 30px 0 10px 0;
        }
        .archive-header img {
            height: 60px;
        }
        .archive-title {
            color: #AA4444;
            font-size: 32px;
            font-weight: bold;
            letter-spacing: 1px;
            text-align: center;
            margin-top: 80px;
            margin-bottom: 18px;
        }
        .archive-table-container {
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            padding: 24px;
            margin: 0 auto;
            max-width: 1200px;
        }
        table { width:100%; border-collapse: collapse; }
        th, td { border: 1px solid #ccc; padding: 8px; font-size: 0.7rem; text-align: center; white-space: pre-wrap; overflow: hidden; }
        th { background:#22336A; color: #fff; }
        .status-cell .status-archived {
            background: #AA4444;
            color: #fff;
            border-radius: 12px;
            padding: 2px 10px;
            font-weight: 600;
            font-size: 0.8em;
            display: inline-block;
        }
        .archive-actions {
            margin-bottom: 10px;
            margin-left: 120px;
            display: flex;
            align-items: center;
            gap: 0px;
        }
        .archive-actions button {
            background: #22336A;
            color: #fff;
            padding: 8px 16px;
            border: none;
            border-radius: 6px;
            font-weight: bold;
            cursor: pointer;
        }
        .archive-actions .return-link {
            color: black;
            text-decoration: none;
            font-weight: 600;
            margin-right: 18px;
            padding: 8px 16px;
            border-radius: 6px;
            transition: background 0.2s, color 0.2s;
        }
        .archive-actions .return-link:hover {
            background: #e3e6f3;
            color: black;
        }
        .archive-actions .delete-btn, .archive-actions .recover-btn {
            background: #AA4444;
            color: #fff;
            margin-right: 8px;
            transition: background 0.2s, color 0.2s;
        }
        .archive-actions .delete-btn:hover {
            background: #d32f2f;
            color: #fff;
        }
        .archive-actions .recover-btn {
            background: #43AF1B;
        }
        .archive-actions .recover-btn:hover {
            background: #388e3c;
            color: #fff;
        }
    </style>
</head>
<body>
    <div class="admin-home-header">
        <img src="../assets/Admin_HomePage_New.svg" alt="Admin Home Header" class="admin-home-header-img">
        <div class="admin-home-header-border"></div>
    </div>
    <div class="archive-title">ARCHIVE</div>
    <div class="archive-actions">
        <a href="Home_Page.php" class="return-link" style="display:inline-flex;align-items:center;">
            <img src="../assets/Return_Icon.svg" alt="Return" style="height:20px;width:20px;margin-right:8px;"> <span>Return</span>
        </a>
        <button class="delete-btn">Delete Forever</button>
        <button class="recover-btn">Recover</button>
    </div>
    <div class="archive-table-container">
        <table>
            <thead>
                <tr>
                    <th><input type="checkbox" onclick="toggleAllCheckboxes(this)"></th>
                    <?php foreach ($columns as $col): ?>
                        <th><?= htmlspecialchars($col) ?></th>
                    <?php endforeach; ?>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rows)): ?>
                    <tr><td colspan="<?= count($columns) + 2 ?>">No records found.</td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td><input type="checkbox" class="row-checkbox" value="<?= htmlspecialchars($row['Notice/Order Code']) ?>"></td>
                            <?php foreach ($columns as $col): ?>
                                <td class="<?= $col === 'Status' ? 'status-cell' : '' ?>">
                                    <?php if ($col === 'Status'): ?>
                                        <span class="status-archived">ARCHIVED</span>
                                    <?php else: ?>
                                        <?= htmlspecialchars($row[$col] ?? '') ?>
                                    <?php endif; ?>
                                </td>
                            <?php endforeach; ?>
                            <td><button style="background:#22336A;color:#fff;padding:4px 10px;border:none;border-radius:4px;font-weight:600;">Track</button></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <script>
        function toggleAllCheckboxes(master) {
            var checkboxes = document.querySelectorAll('.row-checkbox');
            checkboxes.forEach(function(cb) {
                cb.checked = master.checked;
            });
        }
    </script>
</body>
</html>
