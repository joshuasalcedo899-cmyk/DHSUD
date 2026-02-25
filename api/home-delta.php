<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../auth.php';

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

function safeText($value) {
    return trim((string)$value);
}

try {
    // Keep ordering aligned with Home_Page.php rendering logic.
    $rows = $pdo->query('SELECT * FROM mailtracking ORDER BY `Sender Details` ASC, `id` ASC')->fetchAll(PDO::FETCH_ASSOC);

    $statusOptions = ['DELIVERED', 'RETURNED TO SENDER', 'ONGOING DELIVERY', 'PERSONALLY RECEIVED'];
    $statusCounts = array_fill_keys($statusOptions, 0);
    $statusCounts['Unassigned'] = 0;
    $statusCounts['Other'] = 0;

    $years = [];
    foreach ($rows as $r) {
        $status = safeText($r['Status'] ?? '');
        if ($status === '') {
            $statusCounts['Unassigned']++;
        } elseif (in_array($status, $statusOptions, true)) {
            $statusCounts[$status]++;
        } else {
            $statusCounts['Other']++;
        }

        $dateAfd = safeText($r['Date released to AFD'] ?? '');
        if ($dateAfd !== '' && preg_match('/(\d{4})/', $dateAfd, $m)) {
            $years[] = $m[1];
        }
    }

    $years = array_values(array_unique($years));
    rsort($years);

    $totalCount = count($rows);
    $rts = (int)($statusCounts['RETURNED TO SENDER'] ?? 0);
    $ogd = (int)($statusCounts['ONGOING DELIVERY'] ?? 0);
    $ndrPercent = ($totalCount > 0) ? round((($rts + $ogd) / $totalCount) * 100, 1) : 0;

    // Keep same version algorithm as home-version.php.
    $versionSql = "SELECT
                COUNT(*) AS row_count,
                COALESCE(MAX(`id`), 0) AS max_id,
                COALESCE(SUM(CRC32(CONCAT_WS('|',
                    `id`,
                    `Notice/Order Code`,
                    `Date released to AFD`,
                    `Parcel No.`,
                    `Recipient Details`,
                    `Parcel Details`,
                    `Sender Details`,
                    `File Name (PDF)`,
                    `Tracking No.`,
                    `Status`,
                    `Transmittal ID`,
                    `Transmittal Remarks/Received By`,
                    `Date`,
                    `Evaluator`
                ))), 0) AS checksum
            FROM `mailtracking`";
    $versionRow = $pdo->query($versionSql)->fetch(PDO::FETCH_ASSOC) ?: [];
    $version = (string)($versionRow['row_count'] ?? '0') . ':' . (string)($versionRow['max_id'] ?? '0') . ':' . (string)($versionRow['checksum'] ?? '0');

    echo json_encode([
        'success' => true,
        'version' => $version,
        'rows' => $rows,
        'stats' => [
            'returnedToSender' => $rts,
            'ongoingDelivery' => $ogd,
            'delivered' => (int)($statusCounts['DELIVERED'] ?? 0),
            'total' => (int)$totalCount,
            'ndrPercent' => $ndrPercent
        ],
        'years' => $years
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to load delta payload'
    ]);
}

