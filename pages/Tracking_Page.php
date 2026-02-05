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
    <title>Mail Tracking System</title>
    <link rel="stylesheet" href="../main.css">
    <style>
        body {
            background: #fff !important;
            font-family: 'Inter', Arial, Helvetica, sans-serif;
            margin: 0;
            min-height: 100vh;
        }
        .section {
            margin: 2rem auto;
            padding: 1.5rem 2rem;
            border: 1px solid #ddd;
            border-radius: 8px;
            max-width: 900px;
            background: #fff;
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
        }
        .search-box { display: flex; gap: 0.5rem; }
        .search-box input { flex: 1; padding: 0.5rem; }
        .search-box button { padding: 0.5rem 1rem; background: #007bff; color: white; border: none; cursor: pointer; border-radius: 6px; }
        .success { color: green; background: #efe; padding: 10px; margin: 1rem 0; border-radius: 6px; }
        .error { color: darkred; background: #fee; padding: 10px; margin: 1rem 0; border-radius: 6px; }
        table { border-collapse: collapse; width: 100%; margin: 1rem 0; background: rgba(255,255,255,0.95); }
        th, td { border: 1px solid #d1d5db; padding: 0.75rem 0.5rem; text-align: left; }
        th { background: #f3f4f6; font-weight: 500; letter-spacing: 0.05em; }
        .edit-btn { padding: 0.5rem 1rem; background: #007bff; color: white; border: none; cursor: pointer; border-radius: 4px; }
        /* Header styles */
        .admin-home-header {
            width: 100vw;
            position: relative;
            top: 0;
            left: 0;
            z-index: 1000;
            background: #fff;
            display: flex;
            flex-direction: column;
            align-items: center;
        }
        .admin-home-header-img {
            margin-top: 1.5rem;
            max-width: 500px;
            width: 100%;
            height: auto;
            display: block;
        }
        .admin-home-header-border {
            width: 100vw;
            height: 6px;
            background: #22336a;
            margin-top: 1.2rem;
            margin-bottom: 1.5rem;
        }
    </style>
</head>
<body>
    <div class="admin-home-header">
        <img src="../assets/Admin_HomePage_New.svg" alt="Admin Home Header" class="admin-home-header-img">
        <div class="admin-home-header-border"></div>
    </div>

    <div style="width: 100vw; max-width: 100vw; margin: 0; padding: 0;">
        <h1 style="text-align:center; color:#22336a; font-size:1.3rem; font-weight:700; margin-top:1rem; margin-bottom:1rem; letter-spacing:0.04em;">MAIL TRACKING RECORDS</h1>


    <!-- Search Section -->

    <div class="section" style="background: #fff; box-shadow: none; border: none; padding: 0; margin-bottom: 2.5rem;">
        <div style="display: flex; flex-direction: column; align-items: center;">
            <div style="background: #fff; border-radius: 10px;  border: solid #22336a59 1px; padding: 2rem 0.2rem 2rem 0.2rem; min-width: 350px; max-width: 480px; width: 100%;">
                <div style="font-size: 1rem; font-weight: 700; color: #22336a; text-align: center; margin-bottom: 1.5rem;">Search by Notice/Order Code</div>
                <form method="get" action="" style="width: 100%;">
                    <div class="table-search-bar" style="justify-content: center; margin-bottom: 0;">
                        <input type="text" name="search" class="table-search-input" placeholder="Enter notice/order code" required style="width: 260px;">
                        <button type="submit" class="table-search-btn" style="font-size: 0.8rem;padding: 0.4rem 1em; background: #22336a;color: #fff;border-radius: 5px;border: none;">Search</button>
                    </div>
                </form>
            </div>
        </div>


        <?php if ($searchError): ?>
            <div class="error" style="max-width: 600px; margin: 2rem auto; text-align: center;"><?= $searchError ?></div>
        <?php elseif ($searchResult): ?>
            <div class="success" style="max-width: 600px; margin: 1rem auto; text-align: center;">Record Found!</div>
            <div class="tracking-table-container">
                <div class="tracking-table-scroll">
                    <table class="listview-table tracking-table">
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
                                <?php foreach ($searchResult as $key => $val): ?>
                                    <td><?= htmlspecialchars($val ?? '') ?></td>
                                <?php endforeach; ?>
                                <td>
                                    <button class="edit-btn tracking-edit-btn" onclick="openEditForm()">Edit</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>


            <!-- Edit Form Modal -->
            <div id="editModal" style="display: none; margin-top: 2rem; padding: 2rem 2.5rem 1.5rem 2.5rem; background: #fff; border-radius: 10px; box-shadow: 0 4px 24px rgba(0,0,0,0.18); min-width: 350px; max-width: 95vw;">
                <h3 style="color: #22336a; font-size: 1.2rem; font-weight: 700; margin-bottom: 1.2rem; text-align: center;">Edit Record</h3>
                <form id="editForm" onsubmit="submitEditForm(event)">
                    <input type="hidden" name="original_notice_code" value="<?= htmlspecialchars($searchResult['Notice/Order Code'] ?? '') ?>">
                    <!-- Display Notice/Order Code as read-only -->
                    <div style="margin-bottom: 1rem; padding: 0.5rem; background: #e9ecef; border: 1px solid #dee2e6; border-radius: 4px;">
                        <label style="display: block; margin-bottom: 0.5rem; font-weight: bold; color: #22336a;">Notice/Order Code (Read-Only)</label>
                        <div style="padding: 0.5rem; color: #22336a; font-weight: 600;">
                            <?= htmlspecialchars($searchResult['Notice/Order Code'] ?? '') ?>
                        </div>
                    </div>
                    <?php 
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
                            <label for="<?= htmlspecialchars(str_replace(' ', '_', $col)) ?>" style="display: block; margin-bottom: 0.5rem; font-weight: 600; color: #22336a;">
                                <?= htmlspecialchars($col) ?>
                            </label>
                            <input type="text" 
                                   id="<?= htmlspecialchars(str_replace(' ', '_', $col)) ?>" 
                                   name="<?= htmlspecialchars($col) ?>" 
                                   value="<?= htmlspecialchars($val ?? '') ?>"
                                   style="width: 100%; padding: 0.5rem 0.8em; border: 1.5px solid #bbb; border-radius: 6px; font-size: 1em; box-sizing: border-box;">
                        </div>
                    <?php endforeach; ?>
                    <div style="display: flex; gap: 1rem; justify-content: flex-end;">
                        <button type="submit" class="modal-btn" style="background: #22336a; color: #fff; font-weight: 600; border-radius: 6px; padding: 0.5em 1.5em; border: none; font-size: 1em; cursor: pointer;">Save Changes</button>
                        <button type="button" class="modal-btn cancel" onclick="closeEditForm()" style="background: #bbb; color: #222; border-radius: 6px; padding: 0.5em 1.5em; border: none; font-size: 1em; cursor: pointer;">Cancel</button>
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
</div>
</div>
</body>
</html>
