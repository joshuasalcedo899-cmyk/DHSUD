<?php
// config.php — PDO MySQL connection
// Update these values for your environment
$DB_HOST = '127.0.0.1';
$DB_NAME = 'dshudmail_db';
$DB_USER = 'root';
$DB_PASS = '';
$DB_CHARSET = 'utf8mb4';

$dsn = "mysql:host={$DB_HOST};dbname={$DB_NAME};charset={$DB_CHARSET}";

$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];

try {
    $pdo = new PDO($dsn, $DB_USER, $DB_PASS, $options);
} catch (PDOException $e) {
    // In development show error; in production, log and show generic message
    if (php_sapi_name() === 'cli' || getenv('APP_ENV') === 'development') {
        echo "DB connection failed: " . $e->getMessage();
    }
    exit;
}

?>
