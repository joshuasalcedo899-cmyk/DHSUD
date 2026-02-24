<?php
// auth.php — Authentication and security helpers

require_once 'config.php';

function isHttpsRequest() {
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') return true;
    if ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443) return true;
    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') return true;
    return false;
}

function initSecureSession() {
    if (session_status() === PHP_SESSION_ACTIVE) return;

    $isHttps = isHttpsRequest();
    $cookieParams = session_get_cookie_params();
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => $cookieParams['path'] ?? '/',
        'domain' => $cookieParams['domain'] ?? '',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_secure', $isHttps ? '1' : '0');
    ini_set('session.cookie_samesite', 'Lax');

    session_start();
}

function sendSecurityHeaders() {
    if (headers_sent()) return;
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('Permissions-Policy: camera=(self), microphone=(), geolocation=()');
    if (isHttpsRequest()) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

function getCsrfToken() {
    initSecureSession();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['csrf_token'];
}

function verifyCsrfToken($token) {
    $provided = trim((string)$token);
    $expected = (string)($_SESSION['csrf_token'] ?? '');
    if ($provided === '' || $expected === '') return false;
    return hash_equals($expected, $provided);
}

function requireCsrfToken() {
    initSecureSession();
    $tokenFromHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $tokenFromPost = $_POST['csrf_token'] ?? '';
    $token = $tokenFromHeader !== '' ? $tokenFromHeader : $tokenFromPost;

    if (verifyCsrfToken($token)) return;

    http_response_code(403);
    $isApi = (strpos((string)($_SERVER['REQUEST_URI'] ?? ''), '/api/') !== false);
    if ($isApi || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest')) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
    } else {
        echo 'Invalid CSRF token';
    }
    exit;
}

initSecureSession();
sendSecurityHeaders();

/**
 * Register a new user
 */
function registerUser($username, $password, $email) {
    global $pdo;

    if (empty($username) || empty($password) || empty($email)) {
        return ['success' => false, 'message' => 'All fields are required'];
    }

    $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ?');
    $stmt->execute([$username]);
    if ($stmt->fetch()) {
        return ['success' => false, 'message' => 'Username already exists'];
    }

    $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        return ['success' => false, 'message' => 'Email already registered'];
    }

    $hashedPassword = password_hash($password, PASSWORD_BCRYPT);

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

    if (empty($username) || empty($password)) {
        return ['success' => false, 'message' => 'Username and password are required'];
    }

    $stmt = $pdo->prepare('SELECT id, username, password, email FROM users WHERE username = ?');
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if (!$user) {
        return ['success' => false, 'message' => 'Invalid username or password'];
    }

    if (!password_verify($password, $user['password'])) {
        return ['success' => false, 'message' => 'Invalid username or password'];
    }

    session_regenerate_id(true);
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
