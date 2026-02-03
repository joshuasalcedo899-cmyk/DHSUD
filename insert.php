<?php
require_once __DIR__ . '/config.php';

// Simple insert example for a `users` table with columns `name` and `email`.
// Update table/column names to match your schema.

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
    <title>Insert Example</title>
</head>
<body>
    <h1>Insert Example</h1>
    <?php if (!empty($error)): ?>
        <div style="color:darkred"><?= htmlspecialchars($error) ?></div>
    <?php elseif (!empty($success)): ?>
        <div style="color:green"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <form method="post" action="">
        <label>Notice: <input type="text" name="notice" required></label><br>
        <label>Tracking No.: <input type="number" name="tracking" required></label><br>
        <button type="submit">Insert</button>
    </form>

    <p>Note: change the SQL in this file to match your table/columns.</p>
</body>
</html>
