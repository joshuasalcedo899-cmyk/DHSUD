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
    <link rel="icon" type="image/x-icon" href="../assets/DHSUDLogo.ico">
    <link rel="stylesheet" href="../main.css">
    <style>
        body {
            background: #fff !important;
            font-family: 'Inter', Arial, Helvetica, sans-serif;
            margin: 0;
            min-height: 100vh;
        }
        .section {
            margin: 0 auto;
            padding: 1rem 2rem;
            max-width: 1200px;
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
            width: 100%;
            position: sticky;
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
            width: 100%;
            height: 6px;
            background: #22336a;
            margin-top: 1.2rem;
        }
        .tracking-return-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 1rem 2rem 0 2rem;
        }
        .tracking-return-container .return-link {
            color: black;
            text-decoration: none;
            font-weight: 600;
            padding: 2px 16px 2px 16px;
            border-radius: 6px;
            transition: background 0.2s, color 0.2s;
        }
        .tracking-return-container .return-link:hover {
            background: #e3e6f3;
            color: black;
        }
        .page-shell .page-title {
            margin-top: 0;
        }
    </style>
</head>
<body>
    <div class="admin-home-header">
        <img src="../assets/Admin_HomePage_New.svg" alt="Admin Home Header" class="admin-home-header-img">
        <div class="admin-home-header-border"></div>
    </div>
    <div class="tracking-return-container">
        <a href="../index.php" class="return-link" style="display:inline-flex;align-items:center;">
            <img src="../assets/Return_Icon.svg" alt="Return" style="height:20px;width:20px;margin-right:8px;"> <span>Return</span>
        </a>
    </div>

    <div class="page-shell">
        <h1 class="page-title">MAIL TRACKING RECORDS</h1>




    <!-- Search Section -->


    <div class="section section-plain">
        <div class="center-stack">
            <div class="search-card">
                <div style="font-size: 1rem; font-weight: 700; color: #22336a; text-align: center; margin-bottom: 1.5rem;">Search by Notice/Order Code</div>
                <form method="get" action="" style="width: 100%;">
                                    <div class="tracking-search-bar" style="justify-content: center; margin-bottom: 0;">
                                        <input type="text" name="search" class="tracking-search-input" placeholder="Enter notice/order code" required>
                                        <button type="submit" class="tracking-search-btn btn-primary">Search</button>
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
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <?php
                                $colKeys = array_keys($searchResult);
                                foreach ($colKeys as $key):
                                    echo '<td>';
                                    // Place edit icon inside Transmittal Remarks/Received By column
                                    if (strtolower($key) === 'transmittal remarks/received by' || strtolower($key) === 'transmittal remarks / received by') {
                                        echo htmlspecialchars($searchResult[$key] ?? '');
                                        echo ' ';
                                    } else {
                                        echo htmlspecialchars($searchResult[$key] ?? '');
                                    }
                                    echo '</td>';
                                endforeach;
                                // Remove the separate Actions column
                                ?>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>


            <script>
                function openEditForm() {
                    document.getElementById('editModalOverlay').style.display = 'flex';
                }

                function closeEditForm() {
                    document.getElementById('editModalOverlay').style.display = 'none';
                    document.getElementById('editMessage').innerHTML = '';
                }

                // Close modal when clicking outside
                document.addEventListener('DOMContentLoaded', function() {
                    const overlay = document.getElementById('editModalOverlay');
                    if (overlay) {
                        overlay.addEventListener('click', function(e) {
                            if (e.target === overlay) {
                                closeEditForm();
                            }
                        });
                    }
                });


                function submitEditForm(event) {
                    event.preventDefault();
                    const formData = new FormData(document.getElementById('editForm'));
                    const messageDiv = document.getElementById('editMessage');
                   
                    // Log form data for debugging
                    console.log('Submitting form data:');
                    for (let [key, value] of formData.entries()) {
                        console.log(key + ':', value);
                    }


                    fetch('/DHSUD/api/remarks.php', {
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
</body>
</html>
