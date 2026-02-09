<?php
// pages/JRS_Tracking_Page.php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth.php';

requireLogin();

$trackingNo = isset($_GET['tracking']) ? trim($_GET['tracking']) : '';

// Fetch tracking data from API if tracking number is provided
$trackingData = [];
if ($trackingNo !== '') {
    $apiUrl = '../api/jrs-track.php?tracking=' . urlencode($trackingNo);
    $json = @file_get_contents($apiUrl);
    if ($json !== false) {
        $trackingData = json_decode($json, true);
        if (!is_array($trackingData)) {
            $trackingData = [];
        }
    }
}

function formatDateTime($dt) {
    if (!$dt) return '';
    $ts = strtotime($dt);
    if ($ts === false) return htmlspecialchars($dt);
    return date('n/d/Y, g:i A', $ts);
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>JRS Tracking</title>
    <link rel="stylesheet" href="../main.css">
    <style>
        body { background: #fff; margin: 0; font-family: 'Segoe UI', Arial, sans-serif; }
        .jrs-header {
            display: flex;
            align-items: center;
            gap: 18px;
            padding: 24px 0 0 0;
            flex-direction: column;
        }
        .jrs-header img { height: 60px; }
        .jrs-title {
            font-size: 2em;
            font-weight: bold;
            color: #22336A;
            margin: 24px 0 0 0;
            text-align: center;
        }
        .jrs-tracking-box {
            background: #fff;
            border-radius: 10px;
            box-shadow: 0 2px 12px rgba(0,0,0,0.07);
            margin: 32px auto 0 auto;
            max-width: 900px;
            padding: 0 0 32px 0;
        }
        .jrs-tracking-label {
            display: inline-block;
            background: #f5f6fa;
            color: #22336A;
            font-weight: 600;
            border-radius: 6px;
            padding: 6px 18px;
            margin: 18px 0 18px 0;
            font-size: 1.1em;
        }
        .jrs-table-container {
            background: #f7f7f7;
            border-radius: 8px;
            padding: 18px 18px 0 18px;
            margin: 0 auto;
            max-width: 900px;
        }
        .jrs-table {
            width: 100%;
            border-collapse: collapse;
            background: #fff;
            margin: 0 auto;
        }
        .jrs-table th {
            background: #22336A;
            color: #fff;
            font-weight: 600;
            padding: 12px 8px;
            font-size: 1em;
            border: 1px solid #d1d5db;
        }
        .jrs-table td {
            border: 1px solid #d1d5db;
            padding: 10px 8px;
            font-size: 1em;
            text-align: left;
            background: #fff;
        }
        .jrs-table tr:not(:last-child) td {
            border-bottom: 1px solid #d1d5db;
        }
        .jrs-table th, .jrs-table td {
            text-align: center;
        }
        .jrs-back {
            margin: 32px 0 0 0;
            display: flex;
            align-items: center;
            gap: 8px;
            color: #22336A;
            font-weight: 600;
            text-decoration: none;
            font-size: 1.1em;
        }
        .jrs-back:hover { text-decoration: underline; }
        .jrs-download {
            margin: 0 0 12px 0;
            font-size: 1.5em;
            color: #22336A;
            cursor: pointer;
            display: inline-block;
        }
        @media (max-width: 900px) {
            .jrs-table-container, .jrs-tracking-box { max-width: 98vw; }
        }
        @media (max-width: 600px) {
            .jrs-table th, .jrs-table td { font-size: 0.9em; padding: 7px 3px; }
            .jrs-header img { height: 40px; }
        }
    </style>
</head>
<body>
    <div class="admin-home-header">
        <img src="../assets/Admin_HomePage_New.svg" alt="Admin Home Header" class="admin-home-header-img">
        <div class="admin-home-header-border"></div>
    </div>
    <div class="section" style="margin: 6rem auto; padding: 1.5rem 2rem; msax-width: 900px; background: #fff; ">
        <a href="javascript:history.back()" class="jrs-back" style="margin-bottom: 1rem;">&larr; Return</a>
        <h1 style="text-align:center; color:#22336a; font-size:1.3rem; font-weight:700; margin-top:0.5rem; margin-bottom:1.2rem; letter-spacing:0.04em;">JRS TRACKING</h1>
        <div style="text-align:center; margin-bottom:1.2rem;">
            <span class="jrs-tracking-label">Tracking Number: <span style="font-weight:700; color:#22336A;"> <?= htmlspecialchars($trackingNo) ?> </span></span>
            <span class="jrs-download" title="Download" onclick="window.print()">
                <img src="../assets/Download_Icon.svg" alt="Download" style="width:28px;height:28px;vertical-align:middle;cursor:pointer;">
            </span>
        </div>
        <div class="tracking-table-container">
            <div class="tracking-table-scroll">
                <table class="listview-table tracking-table">
                    <thead>
                        <tr>
                            <th>Date and Time</th>
                            <th>Status</th>
                            <th>Remarks</th>
                            <th>Branch Location</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($trackingData)): ?>
                            <tr><td colspan="4">No tracking data found.</td></tr>
                        <?php else: ?>
                            <?php foreach ($trackingData as $row): ?>
                                <tr>
                                    <td><?= formatDateTime($row['dateReceived'] ?? '') ?></td>
                                    <td><?= htmlspecialchars($row['statusText'] ?? '') ?></td>
                                    <td>
                                        <?php
                                            if (!empty($row['receiver'])) {
                                                echo 'Delivered To ' . htmlspecialchars($row['receiver']);
                                                if (!empty($row['relation'])) {
                                                    echo '<br>Relationship: ' . htmlspecialchars($row['relation']);
                                                }
                                            } elseif (!empty($row['remarks'])) {
                                                echo htmlspecialchars($row['remarks']);
                                            } else {
                                                echo '';
                                            }
                                        ?>
                                    </td>
                                    <td><?= htmlspecialchars($row['location'] ?? '') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</body>
</html>
