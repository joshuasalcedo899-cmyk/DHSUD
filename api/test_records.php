<?php
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json; charset=utf-8');

try {
    // Get sample records to see what's in the database
    $sql = 'SELECT `Notice/Order Code` FROM mailtracking ORDER BY `Notice/Order Code` LIMIT 20';
    $results = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    
    $records = [];
    foreach ($results as $row) {
        $code = $row['Notice/Order Code'];
        $records[] = [
            'value' => $code,
            'length' => strlen($code),
            'hex' => bin2hex($code),
            'trimmed' => trim($code),
            'trimmed_length' => strlen(trim($code))
        ];
    }
    
    echo json_encode([
        'success' => true,
        'total' => count($records),
        'records' => $records
    ], JSON_PRETTY_PRINT);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>
