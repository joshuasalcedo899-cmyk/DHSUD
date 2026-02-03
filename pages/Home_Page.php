
<?php
require_once __DIR__ . '/../config.php';
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
    'Date Released to AFD',
    'Parcel No.',
    'RECIPIENT DETAILS',
    'PARCEL DETAILS',
    'SENDER DETAILS',
    'FILE NAME (PDF)',
    'Tracking No.',
    'STATUS',
    'TRANSMITTAL REMARKS / RECEIVED BY',
    'DATE',
    'EVALUATOR',
];

// Status options
$statusOptions = ['DELIVERED','RETURNED TO SENDER','ON GOING DELIVERY', 'PERSONALLY RECEIVED',];

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
        th, td { border: 1px solid #ccc; padding: 8px; }
        th { background:#f7f7f7; }
        form.inline { margin:0; }
        select { padding:4px; }
        button.save { padding:4px 8px; }
        .message { padding:8px; margin:10px 0; }
        .row-message { font-size:0.9em; color: green; margin-top:6px; opacity:1; transition: opacity 0.5s ease; }
    </style>
</head>
<body>
    <div style="overflow-x:auto; padding: 2rem;">
        <table style="width:100%; border-collapse: collapse; background: rgba(255,255,255,0.95);">
            <thead>
                <tr>
                    <?php foreach ($columns as $h): ?>
                        <th><?= htmlspecialchars($h) ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rows)): ?>
                    <tr><td colspan="<?= count($columns) ?>">No records found.</td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <?php foreach ($columns as $idx => $colName): ?>
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
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('.row-message').forEach(function(el) {
            setTimeout(function() {
                el.style.opacity = '0';
                setTimeout(function() { if (el.parentNode) el.parentNode.removeChild(el); }, 500);
            }, 2000);
        });
    });
    </script>
</body>
</html>
