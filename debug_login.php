<?php
require_once 'config.php';

echo "<h3>Debug Login Information</h3>";

// Check database connection
echo "<p><strong>Database Connection:</strong> ";
try {
    $stmt = $pdo->query('SELECT DATABASE()');
    echo "✓ Connected</p>";
} catch (Exception $e) {
    echo "✗ Failed: " . $e->getMessage() . "</p>";
    exit;
}

// Check if users table exists
echo "<p><strong>Users Table:</strong> ";
try {
    $stmt = $pdo->query('SHOW TABLES LIKE "users"');
    if ($stmt->fetch()) {
        echo "✓ Exists</p>";
    } else {
        echo "✗ Does not exist</p>";
    }
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "</p>";
}

// Show all users in database
echo "<p><strong>Users in Database:</strong></p>";
try {
    $stmt = $pdo->query('SELECT id, username, email, password FROM users');
    $users = $stmt->fetchAll();
    
    if (empty($users)) {
        echo "<p style='color: red;'>No users found in database!</p>";
    } else {
        echo "<table border='1' cellpadding='10'>";
        echo "<tr><th>ID</th><th>Username</th><th>Email</th><th>Password Hash</th></tr>";
        foreach ($users as $user) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($user['id']) . "</td>";
            echo "<td>" . htmlspecialchars($user['username']) . "</td>";
            echo "<td>" . htmlspecialchars($user['email']) . "</td>";
            echo "<td style='font-family: monospace; font-size: 12px;'>" . htmlspecialchars(substr($user['password'], 0, 50)) . "...</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
} catch (Exception $e) {
    echo "<p style='color: red;'>Error: " . $e->getMessage() . "</p>";
}

// Test password verification
echo "<p><strong>Password Verification Test:</strong></p>";
$test_password = 'admin123';
$test_hash = '$2y$10$N9qo8uLOickgx2ZMRZoMyeIjZAgcg7b3XeKeUxWdeS86E36DRcT36';

if (password_verify($test_password, $test_hash)) {
    echo "<p style='color: green;'>✓ Password 'admin123' verifies correctly with the hash</p>";
} else {
    echo "<p style='color: red;'>✗ Password verification FAILED</p>";
}

// Generate a fresh hash for admin123
echo "<p><strong>Fresh Hash for 'admin123':</strong></p>";
$fresh_hash = password_hash('admin123', PASSWORD_BCRYPT);
echo "<p style='font-family: monospace; word-break: break-all;'>" . htmlspecialchars($fresh_hash) . "</p>";
echo "<p>Use this hash in your database for the admin user.</p>";

?>
