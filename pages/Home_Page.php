
<?php
require_once __DIR__ . '/../config.php';

// Handle status update when submitted per-row
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['notice_code']) && isset($_POST['status'])) {
    $notice = trim($_POST['notice_code']);
    $status = trim($_POST['status']);
    if ($notice === '') {
        $message = 'Missing Notice/Order Code.';
    } else {
        try {
            $sql = 'UPDATE mailtracking SET `STATUS` = :status WHERE `Notice/Order Code` = :notice';
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':status' => $status, ':notice' => $notice]);
            $message = 'Status updated for ' . htmlspecialchars($notice) . '.';
        } catch (PDOException $e) {
            $message = 'Update failed: ' . $e->getMessage();
        }
    }

    // If AJAX request, return JSON and exit early
    if (!empty($_POST['ajax'])) {
        header('Content-Type: application/json');
        echo json_encode(['message' => $message]);
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
$statusOptions = ['DELIVERED','RETURNED TO SENDER','ON GOING DELIVERY', 'PERSONALLY RECEIVED'];

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
    </style>
</head>
<body class="admin-home-bg">
    <div class="admin-home-container">
        <div class="statistics-section">
            <div class="statistics-title">STATISTICS</div>
            <div class="statistics-bar">
                <div class="stat-box stat-rtos"><span class="color"></span>Returned to Sender</div>
                <div class="stat-box stat-ongoing"><span class="color"></span>Ongoing Delivery</div>
                <div class="stat-box stat-delivered"><span class="color"></span>Delivered</div>
                <div class="stat-box stat-total"><span class="color"></span>Total</div>
                <div class="stat-box stat-ndr"><span class="color"></span>Non-delivery Rate</div>
            </div>
        </div>
    </div>
    <div class="admin-table-container">
        <div style="overflow-x:auto;">
            <?php if ($message): ?>
                <div class="message"><?= htmlspecialchars($message) ?></div>
            <?php endif; ?>
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
                                            <input type="hidden" class="notice-code" value="<?= htmlspecialchars($row['Notice/Order Code'] ?? '') ?>">
                                            <select class="status-select">
                                                <?php
                                                $current = $row['STATUS'] ?? '';
                                                // placeholder option
                                                $phSelected = ($current === '') ? ' selected' : '';
                                                echo '<option value="" disabled' . $phSelected . '>-- Select status --</option>';
                                                // if current not in options, show it next so user can keep it
                                                if ($current !== '' && !in_array($current, $statusOptions, true)) {
                                                    echo '<option value="' . htmlspecialchars($current) . '" selected>' . htmlspecialchars($current) . '</option>';
                                                }
                                                foreach ($statusOptions as $opt) {
                                                    $sel = ($opt === $current) ? ' selected' : '';
                                                    echo '<option value="' . htmlspecialchars($opt) . '"' . $sel . '>' . htmlspecialchars($opt) . '</option>';
                                                }
                                                ?>
                                            </select>
                                            <span class="save-state" style="margin-left:8px; font-size:0.9em; color:#666"></span>
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
    </div>
</body>
</html>
<script>
// Attach change listeners to all status selects and send AJAX POST to save
document.addEventListener('DOMContentLoaded', function () {
    const selects = document.querySelectorAll('.status-select');
    selects.forEach(function (sel) {
        sel.addEventListener('change', function () {
            const value = sel.value;
            if (!value) return; // ignore placeholder
            const row = sel.closest('tr');
            const noticeInput = row.querySelector('.notice-code');
            const notice = noticeInput ? noticeInput.value : '';
            const stateSpan = row.querySelector('.save-state');
            if (!notice) {
                if (stateSpan) stateSpan.textContent = 'Missing notice code';
                return;
            }
            // show saving
            if (stateSpan) stateSpan.textContent = 'Saving...';
            // prepare form data
            const fd = new FormData();
            fd.append('notice_code', notice);
            fd.append('status', value);
            fd.append('ajax', '1');

            fetch(window.location.href, {
                method: 'POST',
                body: fd,
                credentials: 'same-origin'
            }).then(r => r.json())
              .then(data => {
                  if (stateSpan) stateSpan.textContent = data.message || 'Saved';
                  setTimeout(() => { if (stateSpan) stateSpan.textContent = ''; }, 2500);
              }).catch(err => {
                  if (stateSpan) stateSpan.textContent = 'Save failed';
                  console.error('Save error', err);
                  setTimeout(() => { if (stateSpan) stateSpan.textContent = ''; }, 2500);
              });
        });
    });
});
</script>
