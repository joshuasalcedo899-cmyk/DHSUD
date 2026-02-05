<?php
require_once __DIR__ . '/../config.php';

$searchResult = null;
$searchError = '';

// Handle search
if (!empty($_GET['search'])) {
    $noticeCode = trim($_GET['search']);
    try {
        $sql = 'SELECT * FROM mailtracking WHERE `Notice/Order Code` = :notice';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':notice' => $noticeCode]);
        $searchResult = $stmt->fetch();
        if (!$searchResult) {
            $searchError = 'No record found for: ' . htmlspecialchars($noticeCode);
        }
    } catch (PDOException $e) {
        $searchError = 'Search failed: ' . $e->getMessage();
    }
}

// Handle insert
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tracking = trim($_POST['tracking'] ?? '');
    $notice = trim($_POST['notice'] ?? '');

    if ($tracking === '' || $notice === '') {
        $error = 'Tracking number and notice are required.';
    } else {
        try {
            $sql = 'INSERT INTO mailtracking (`Tracking No.`, `Notice/Order Code`) VALUES (:tracking, :notice)';
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':tracking' => $tracking, ':notice' => $notice]);
            $success = 'Record inserted successfully. ID: ' . $pdo->lastInsertId();
        } catch (PDOException $e) {
            $error = 'Insert failed: ' . $e->getMessage();
        }
    }
}

?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Insert & Search</title>
    <style>
        body { font-family: Arial; margin: 2rem; }
        .section { margin: 2rem 0; padding: 1rem; border: 1px solid #ddd; border-radius: 4px; }
        .search-box { display: flex; gap: 0.5rem; flex-wrap: wrap; }
        .search-box input { flex: 1; padding: 0.5rem; min-width: 150px; }
        .search-box button { padding: 0.5rem 1rem; background: #007bff; color: white; border: none; cursor: pointer; }
        .success { color: green; background: #efe; padding: 10px; margin: 1rem 0; }
        .error { color: darkred; background: #fee; padding: 10px; margin: 1rem 0; }
        table { border-collapse: collapse; width: 100%; margin: 1rem 0; overflow-x: auto; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background: #f0f0f0; }
        .edit-btn { padding: 0.5rem 1rem; background: #007bff; color: white; border: none; cursor: pointer; border-radius: 4px; }
        @media (max-width: 768px) {
            body { margin: 1rem; }
            .section { padding: 0.75rem; }
            table { font-size: 0.9rem; }
            th, td { padding: 6px; }
            .search-box { flex-direction: column; }
            .search-box input { min-width: 100%; }
            .search-box button { width: 100%; }
        }
        @media (max-width: 480px) {
            body { margin: 0.5rem; }
            .section { padding: 0.5rem; margin: 1rem 0; }
            h1 { font-size: 1.3rem; }
            h2 { font-size: 1.1rem; }
            table { font-size: 0.8rem; }
            th, td { padding: 4px; }
            .edit-btn { padding: 0.4rem 0.8rem; font-size: 0.9rem; }
        }
    </style>
</head>
<body>
    <h1>Mail Tracking System</h1>

    <!-- Search Section -->
    <div class="section">
        <h2>Search by Notice/Order Code</h2>
        <form method="get" action="">
            <div class="search-box">
                <input type="text" name="search" placeholder="Enter Notice/Order Code..." required>
                <button type="submit">Search</button>
            </div>
        </form>

        <?php if ($searchError): ?>
            <div class="error"><?= $searchError ?></div>
        <?php elseif ($searchResult): ?>
            <div class="success">Record Found!</div>
            <table>
                <thead>
                    <tr>
                        <?php foreach (array_keys($searchResult) as $col): ?>
                            <th><?= htmlspecialchars($col) ?></th>
                        <?php endforeach; ?>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <?php foreach ($searchResult as $val): ?>
                            <td><?= htmlspecialchars($val ?? '') ?></td>
                        <?php endforeach; ?>
                        <td>
                            <button class="edit-btn" onclick="openEditForm()">Edit</button>
                        </td>
                    </tr>
                </tbody>
            </table>

            <!-- Edit Form Modal -->
            <div id="editModal" style="display: none; margin-top: 2rem; padding: 1rem; border: 1px solid #ddd; background: #f9f9f9; border-radius: 4px;">
                <h3>Edit Record</h3>
                <form id="editForm" onsubmit="submitEditForm(event)">
                    <input type="hidden" name="original_notice_code" value="<?= htmlspecialchars($searchResult['Notice/Order Code'] ?? '') ?>">
                    
                    <!-- Display Notice/Order Code as read-only -->
                    <div style="margin-bottom: 1rem; padding: 0.5rem; background: #e9ecef; border: 1px solid #dee2e6; border-radius: 4px;">
                        <label style="display: block; margin-bottom: 0.5rem; font-weight: bold;">Notice/Order Code (Read-Only)</label>
                        <div style="padding: 0.5rem;">
                            <?= htmlspecialchars($searchResult['Notice/Order Code'] ?? '') ?>
                        </div>
                    </div>
                    
                    <?php 
                    // Only editable columns (matching EditMail.php)
                    // Note: Notice/Order Code is the PRIMARY KEY and cannot be edited
                    $editableColumns = [
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
                        'Evaluator'
                    ];
                    foreach ($editableColumns as $col): 
                        $val = $searchResult[$col] ?? '';
                    ?>
                        <div style="margin-bottom: 1rem;">
                            <label for="<?= htmlspecialchars(str_replace(' ', '_', $col)) ?>" style="display: block; margin-bottom: 0.5rem; font-weight: bold;">
                                <?= htmlspecialchars($col) ?>
                            </label>
                            <input type="text" 
                                   id="<?= htmlspecialchars(str_replace(' ', '_', $col)) ?>" 
                                   name="<?= htmlspecialchars($col) ?>" 
                                   value="<?= htmlspecialchars($val ?? '') ?>"
                                   style="width: 100%; padding: 0.5rem; box-sizing: border-box;">
                        </div>
                    <?php endforeach; ?>

                    <div style="display: flex; gap: 1rem;">
                        <button type="submit" style="padding: 0.5rem 1rem; background: #28a745; color: white; border: none; cursor: pointer; border-radius: 4px;">Save Changes</button>
                        <button type="button" onclick="closeEditForm()" style="padding: 0.5rem 1rem; background: #6c757d; color: white; border: none; cursor: pointer; border-radius: 4px;">Cancel</button>
                    </div>
                </form>
                <div id="editMessage" style="margin-top: 1rem;"></div>
            </div>

            <script>
                function openEditForm() {
                    document.getElementById('editModal').style.display = 'block';
                }

                function closeEditForm() {
                    document.getElementById('editModal').style.display = 'none';
                    document.getElementById('editMessage').innerHTML = '';
                }

                function submitEditForm(event) {
                    event.preventDefault();
                    const formData = new FormData(document.getElementById('editForm'));
                    const messageDiv = document.getElementById('editMessage');
                    
                    // Log form data for debugging
                    console.log('Submitting form data:');
                    for (let [key, value] of formData.entries()) {
                        console.log(key + ':', value);
                    }

                    fetch('/DHSUD/api/EditMail.php', {
                        method: 'POST',
                        body: formData,
                        credentials: 'same-origin' // Important: send cookies with AJAX requests
                    })
                    .then(response => {
                        console.log('Response status:', response.status);
                        console.log('Response headers:', response.headers.get('content-type'));
                        return response.text().then(text => ({
                            status: response.status,
                            body: text
                        }));
                    })
                    .then(({status, body}) => {
                        console.log('Response body:', body);
                        try {
                            const data = JSON.parse(body);
                            console.log('Parsed response:', data);
                            if (data.success) {
                                const affectedMsg = data.affected > 0 ? ` (${data.affected} row(s) updated)` : '';
                                messageDiv.innerHTML = '<div class="success">Record updated successfully!' + affectedMsg + '</div>';
                                setTimeout(() => {
                                    location.reload();
                                }, 1500);
                            } else {
                                messageDiv.innerHTML = '<div class="error">Error: ' + escapeHtml(data.message) + '</div>';
                            }
                        } catch (e) {
                            console.error('JSON parse error:', e);
                            messageDiv.innerHTML = '<div class="error">Server error: Invalid response. Status: ' + status + '</div>';
                        }
                    })
                    .catch(error => {
                        console.error('Fetch error:', error);
                        messageDiv.innerHTML = '<div class="error">Request failed: ' + escapeHtml(error.message) + '</div>';
                    });
                }

                function escapeHtml(text) {
                    const map = {
                        '&': '&amp;',
                        '<': '&lt;',
                        '>': '&gt;',
                        '"': '&quot;',
                        "'": '&#039;'
                    };
                    return text.replace(/[&<>"']/g, m => map[m]);
                }
            </script>
        <?php endif; ?>
    </div>
</body>
</html>
