<?php
require_once __DIR__ . '/../config.php';

// Insert data from one table into mailtracking
// Example: pull from a 'staging_parcels' table and insert into 'mailtracking'

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // INSERT INTO ... SELECT with duplicate key check
        // Notice/Order Code is the primary key, so we avoid duplicates
        $sql = '
            INSERT INTO mailtracking (`Notice/Order Code`) 
            SELECT `Notice/Order Code`
            FROM mailtrackdb.mailtracking
            WHERE `Notice/Order Code` IS NOT NULL
            AND `Notice/Order Code` NOT IN (SELECT `Notice/Order Code` FROM mailtracking)
        ';
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        $rowsInserted = $stmt->rowCount();
        
        $success = "Inserted {$rowsInserted} records from database.";
    } catch (PDOException $e) {
        $error = 'Insert from DB failed: ' . $e->getMessage();
    }
}

// Show available data from source table
try {
    $sourceData = $pdo->query('SELECT * FROM mailtracking LIMIT 10')->fetchAll();
} catch (Exception $e) {
    $sourceData = [];
}

?>
<!doctype html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Home Page</title>
    <link rel="stylesheet" href="main.css">
</head>
<body>
    <div style="overflow-x:auto; padding: 2rem;">
        <table style="width:100%; border-collapse: collapse; background: rgba(255,255,255,0.95);">
            <thead>
                <tr>
                    <?php foreach (array_keys($sourceData[0]) as $col): ?>
                        <th><?= htmlspecialchars($col) ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($sourceData as $row): ?>
                    <tr>
                        <?php foreach ($row as $val): ?>
                            <td><?= htmlspecialchars($val) ?></td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

</body>
</html>
