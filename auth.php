<?php
// auth.php — Authentication functions

session_start();

require_once 'config.php';

/**
 * Register a new user
 */
function registerUser($username, $password, $email) {
    global $pdo;
    
    // Validate input
    if (empty($username) || empty($password) || empty($email)) {
        return ['success' => false, 'message' => 'All fields are required'];
    }
    
    // Check if username already exists
    $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ?');
    $stmt->execute([$username]);
    if ($stmt->fetch()) {
        return ['success' => false, 'message' => 'Username already exists'];
    }
    
    // Check if email already exists
    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        return ['success' => false, 'message' => 'Email already registered'];
    }
    
    // Hash password
    $hashedPassword = password_hash($password, PASSWORD_BCRYPT);
    
    // Insert user
    try {
        $stmt = $pdo->prepare('INSERT INTO users (username, email, password) VALUES (?, ?, ?)');
        $stmt->execute([$username, $email, $hashedPassword]);
        return ['success' => true, 'message' => 'User registered successfully'];
    } catch (PDOException $e) {
        return ['success' => false, 'message' => 'Registration failed: ' . $e->getMessage()];
    }
}

/**
 * Authenticate user login
 */
function loginUser($username, $password) {
    global $pdo;
    
    // Validate input
    if (empty($username) || empty($password)) {
        return ['success' => false, 'message' => 'Username and password are required'];
    }
    
    // Fetch user from database
    $stmt = $pdo->prepare('SELECT id, username, password, email FROM users WHERE username = ?');
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    
    // Check if user exists
    if (!$user) {
        return ['success' => false, 'message' => 'Invalid username or password'];
    }
    
    // Verify password
    if (!password_verify($password, $user['password'])) {
        return ['success' => false, 'message' => 'Invalid username or password'];
    }
    
    // Set session variables
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['email'] = $user['email'];
    $_SESSION['logged_in'] = true;
    
    return ['success' => true, 'message' => 'Login successful'];
}

/**
 * Check if user is logged in
 */
function isLoggedIn() {
    return isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
}

/**
 * Get current logged-in user
 */
function getCurrentUser() {
    if (isLoggedIn()) {
        return [
            'id' => $_SESSION['user_id'],
            'username' => $_SESSION['username'],
            'email' => $_SESSION['email']
        ];
    }
    return null;
}

/**
 * Logout user
 */
function logoutUser() {
    $_SESSION = [];
    session_destroy();
    return ['success' => true, 'message' => 'Logged out successfully'];
}

/**
 * Require login (redirect if not authenticated)
 */
function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: /DHSUD/pages/Admin_LogIn.php');
        exit;
    }
}

?>
