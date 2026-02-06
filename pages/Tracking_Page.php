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
                                        ?>
                                        <button class="edit-btn tracking-edit-btn" onclick="openEditForm()" style="background: none; border: none; padding: 0; margin-left: 0.3em; vertical-align: middle; box-shadow: none;">
                                            <img src="../assets/Edit_Icon.svg" alt="Edit" style="width: 22px; height: 22px; display: inline-block; vertical-align: middle; filter: none;">
                                        </button>
                                        <?php
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





            <!-- Edit Form Modal as Pop-up Overlay (UI-matched, only Date, Transmittal Remarks, Evaluator editable) -->
            <div id="editModalOverlay" class="edit-modal-overlay" style="display:none;position:fixed;z-index:2000;top:0;left:0;width:100vw;height:100vh;background:rgba(34,51,106,0.13);align-items:center;justify-content:center;">
                <div class="edit-modal" id="editModal" style="position:relative;border: 6px solid #22336A; border-radius: 12px; max-width: 800px; width: 98vw; background: #fff; box-shadow:0 8px 32px rgba(34,51,106,0.13); padding: 2rem 2.5rem 1rem 2.5rem;">
                    <button class="modal-close" onclick="closeEditForm()" title="Close" style="position:absolute;top:18px;right:18px;font-size:2em;background:none;border:none;color:#22336A;cursor:pointer;z-index:2;">&times;</button>
                    <h2 style="text-align:center;color:#22336A;font-size:1.3em;font-weight:bold;margin-bottom:18px;letter-spacing:1px;">MAIL RECORD</h2>
                    <form id="editForm" onsubmit="submitEditForm(event)" autocomplete="off" style="display:grid;grid-template-columns:1fr 1fr;gap:0 32px;">
                        <input type="hidden" name="original_notice_code" value="<?= htmlspecialchars($searchResult['Notice/Order Code'] ?? '') ?>">
                        <!-- Row 1: Notice/Order Code & Date Released to AFD -->
                        <div style="margin-bottom:0.5rem;">
                            <div style="font-size:0.98em;font-weight:600;color:#22336A;margin-bottom:0.2em;">Notice/Order Code*</div>
                            <div style="font-size:1em;line-height:1.5;"><?= htmlspecialchars($searchResult['Notice/Order Code'] ?? '') ?></div>
                        </div>
                        <div style="margin-bottom:0.5rem;">
                            <div style="font-size:0.98em;font-weight:600;color:#22336A;margin-bottom:0.2em;">Date Released to AFD*</div>
                            <div style="font-size:1em;line-height:1.5;"><?= htmlspecialchars($searchResult['Date released to AFD'] ?? '') ?></div>
                        </div>
                        <!-- Row 2: Parcel No. & Tracking No. -->
                        <div style="margin-bottom:0.5rem;">
                            <div style="font-size:0.98em;font-weight:600;color:#22336A;margin-bottom:0.2em;">Parcel No.</div>
                            <div style="font-size:1em;line-height:1.5;"><?= htmlspecialchars($searchResult['Parcel No.'] ?? '') ?></div>
                        </div>
                        <div style="margin-bottom:0.5rem;">
                            <div style="font-size:0.98em;font-weight:600;color:#22336A;margin-bottom:0.2em;">Tracking No.</div>
                            <div style="font-size:1em;line-height:1.5;"><?= htmlspecialchars($searchResult['Tracking No.'] ?? '') ?></div>
                        </div>
                        <!-- Row 3: Recipient Details (full width) -->
                        <div style="grid-column:1/span 2;margin-bottom:0.5rem;">
                            <div style="font-size:0.98em;font-weight:600;color:#22336A;margin-bottom:0.2em;">Recipient Details</div>
                            <div style="font-size:1em;line-height:1.5;"><?= nl2br(htmlspecialchars($searchResult['Recipient Details'] ?? '')) ?></div>
                        </div>
                        <!-- Row 4: Parcel Details (full width) -->
                        <div style="grid-column:1/span 2;margin-bottom:0.5rem;">
                            <div style="font-size:0.98em;font-weight:600;color:#22336A;margin-bottom:0.2em;">Parcel Details</div>
                            <div style="font-size:1em;line-height:1.5;white-space:pre-line;"><?= nl2br(htmlspecialchars($searchResult['Parcel Details'] ?? '')) ?></div>
                        </div>
                        <!-- Row 5: Sender Details (full width) -->
                        <div style="grid-column:1/span 2;margin-bottom:0.5rem;">
                            <div style="font-size:0.98em;font-weight:600;color:#22336A;margin-bottom:0.2em;">Sender Details</div>
                            <div style="font-size:1em;line-height:1.5;white-space:pre-line;"><?= nl2br(htmlspecialchars($searchResult['Sender Details'] ?? '')) ?></div>
                        </div>
                        <!-- Row 6: File Name (PDF) (full width) -->
                        <div style="grid-column:1/span 2;margin-bottom:0.5rem;">
                            <div style="font-size:0.98em;font-weight:600;color:#22336A;margin-bottom:0.2em;">File Name (PDF)</div>
                            <div style="font-size:1em;line-height:1.5;"><?= htmlspecialchars($searchResult['File Name (PDF)'] ?? '') ?></div>
                        </div>
                        <!-- Row 7: Status & Date (Date is editable) -->
                        <div style="margin-bottom:0.5rem;display:flex;flex-direction:column;">
                            <div style="font-size:0.98em;font-weight:600;color:#22336A;margin-bottom:0.2em;">Status</div>
                            <div style="font-size:1em;line-height:1.5;">
                                <?php $status = $searchResult['Status'] ?? ''; ?>
                                <?php if (strtolower($status) === 'delivered'): ?>
                                    <span style="display:inline-block;background:#4cd137;color:#fff;font-weight:600;padding:0.2em 1.5em;border-radius:5px;font-size:1em;">DELIVERED</span>
                                <?php elseif (strtolower($status) === 'pending'): ?>
                                    <span style="display:inline-block;background:#fbc531;color:#22336A;font-weight:600;padding:0.2em 1.5em;border-radius:5px;font-size:1em;">PENDING</span>
                                <?php else: ?>
                                    <span style="display:inline-block;background:#eee;color:#22336A;font-weight:600;padding:0.2em 1.5em;border-radius:5px;font-size:1em;"><?= htmlspecialchars($status) ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div style="margin-bottom:0.5rem;display:flex;flex-direction:column;">
                            <div style="font-size:0.98em;font-weight:600;color:#22336A;margin-bottom:0.2em;">Date</div>
                            <input type="date" id="Date" name="Date" value="<?= htmlspecialchars($searchResult['Date'] ?? '') ?>" style="width:100%;padding:0.5rem 0.8em;border:1.5px solid #bbb;border-radius:6px;font-size:1em;box-sizing:border-box;">
                        </div>
                        <!-- Row 8: Transmittal Remarks/Received By (editable, full width, icon inside textbox) -->
                        <div style="grid-column:1/span 2;margin-bottom:0.5rem;">
                            <div style="font-size:0.98em;font-weight:600;color:#22336A;margin-bottom:0.2em;">Transmittal Remarks / Received By</div>
                            <div style="position:relative;width:100%;">
                                <input type="text" id="Transmittal_Remarks_Received_By" name="Transmittal Remarks/Received By" value="<?= htmlspecialchars($searchResult['Transmittal Remarks/Received By'] ?? '') ?>" style="width:100%;padding:0.5rem 2.2em 0.5rem 0.8em;border:1.5px solid #bbb;border-radius:6px;font-size:1em;box-sizing:border-box;">
                                <img src="../assets/Edit_Icon.svg" alt="Edit" style="position:absolute;right:0.6em;top: 33%;transform:translateY(-50%);width:22px;height:22px;pointer-events:none;filter:none;opacity:0.7;">
                            </div>
                        </div>
                        <!-- Row 9: Evaluator (editable, full width, icon inside textbox) -->
                        <div style="grid-column:1/span 2;margin-bottom:0.5rem;">
                            <div style="font-size:0.98em;font-weight:600;color:#22336A;margin-bottom:0.2em;">Evaluator</div>
                            <div style="position:relative;width:100%;">
                                <input type="text" id="Evaluator" name="Evaluator" value="<?= htmlspecialchars($searchResult['Evaluator'] ?? '') ?>" style="width:100%;padding:0.5rem 2.2em 0.5rem 0.8em;border:1.5px solid #bbb;border-radius:6px;font-size:1em;box-sizing:border-box;">
                                <img src="../assets/Edit_Icon.svg" alt="Edit" style="position:absolute;right:0.6em;top:33%;transform:translateY(-50%);width:22px;height:22px;pointer-events:none;filter:none;opacity:0.7;">
                            </div>
                        </div>
                        <div class="modal-actions" style="grid-column:1/span 2;display:flex;justify-content:center;gap:1em;margin-top:10px;margin-bottom:0;">
                            <button type="submit" class="modal-btn save" style="background:#22336A;color:#fff;font-weight:600;border-radius:6px;padding:0.5em 1.5em;border:none;font-size:1em;cursor:pointer;">Update</button>
                            <button type="button" class="modal-btn cancel" onclick="closeEditForm()" style="background:#AA4444;color:#fff;border-radius:6px;padding:0.5em 1.5em;border:none;font-size:1em;cursor:pointer;">Cancel</button>
                        </div>
                    </form>
                    <div id="editMessage" style="margin-top:1rem;"></div>
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