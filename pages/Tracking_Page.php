<?php
require_once __DIR__ . '/config.php';

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
        .search-box { display: flex; gap: 0.5rem; }
        .search-box input { flex: 1; padding: 0.5rem; }
        .search-box button { padding: 0.5rem 1rem; background: #007bff; color: white; border: none; cursor: pointer; }
        .success { color: green; background: #efe; padding: 10px; margin: 1rem 0; }
        .error { color: darkred; background: #fee; padding: 10px; margin: 1rem 0; }
        table { border-collapse: collapse; width: 100%; margin: 1rem 0; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background: #f0f0f0; }
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
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <?php foreach ($searchResult as $val): ?>
                            <td><?= htmlspecialchars($val ?? '') ?></td>
                        <?php endforeach; ?>
                    </tr>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <!-- Insert Section -->
    <div class="section">
        <h2>Insert New Record</h2>
        <?php if (!empty($error)): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php elseif (!empty($success)): ?>
            <div class="success"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>

        <form method="post" action="">
            <label>Notice/Order Code: <input type="text" name="notice" required></label><br><br>
            <label>Tracking No.: <input type="text" name="tracking" required></label><br><br>
            <button type="submit">Insert Record</button>
        </form>
    </div>
</body>
</html>
