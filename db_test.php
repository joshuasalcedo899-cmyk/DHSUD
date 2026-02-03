<?php
require_once __DIR__ . '/config.php';

try {
    $stmt = $pdo->query('SELECT DATABASE() AS db, NOW() AS now');
    $row = $stmt->fetch();
    echo 'Connected to DB: ' . ($row['db'] ?? 'unknown') . '<br>';
    echo 'Server time: ' . ($row['now'] ?? 'unknown');
} catch (Exception $e) {
    echo 'Query error: ' . $e->getMessage();
}

?>
