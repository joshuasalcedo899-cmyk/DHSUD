
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
    <meta charset="utf-8">
    <title>Insert From DB</title>
    <style>
        body { font-family: Arial; margin: 2rem; }
        table { border-collapse: collapse; width: 100%; margin: 1rem 0; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background: #f0f0f0; }
    </style>
</head>
<body>
    <h1>Insert Data From Database</h1>
    
    <?php if (!empty($error)): ?>
        <div style="color:darkred; padding:10px; background:#fee;"><?= htmlspecialchars($error) ?></div>
    <?php elseif (!empty($success)): ?>
        <div style="color:green; padding:10px; background:#efe;"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <h2>Source Data (from staging_parcels)</h2>
    <?php if (!empty($sourceData)): ?>
        <table>
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
