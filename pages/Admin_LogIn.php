<?php
require_once '../auth.php';

// Redirect to home if already logged in
if (isLoggedIn()) {
    header('Location: Home_Page.php');
    exit;
}

$error_message = '';
$success_message = '';

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = isset($_POST['username']) ? trim($_POST['username']) : '';
    $password = isset($_POST['password']) ? $_POST['password'] : '';
    
    $result = loginUser($username, $password);
    
    if ($result['success']) {
        header('Location: Home_Page.php');
        exit;
    } else {
        $error_message = $result['message'];
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Log-In Page</title>
    <link rel="stylesheet" href="../main.css">
    <style>
        .error-message {
            color: #d32f2f;
            background-color: #ffebee;
            padding: 12px;
            border-radius: 4px;
            margin-bottom: 1rem;
            border-left: 4px solid #d32f2f;
        }
        .success-message {
            color: #388e3c;
            background-color: #e8f5e9;
            padding: 12px;
            border-radius: 4px;
            margin-bottom: 1rem;
            border-left: 4px solid #388e3c;
        }
        @media (max-width: 768px) {
            .login-form {
                min-width: 85vw !important;
                max-width: 85vw !important;
            }
            .bottom-container {
                padding-bottom: 2rem;
            }
        }
        @media (max-width: 480px) {
            .login-form {
                min-width: 90vw !important;
                max-width: 90vw !important;
                padding: 1.5rem !important;
            }
            .login-form h2 {
                font-size: 1rem;
            }
            .login-form label {
                font-size: 0.9rem;
            }
            .login-form input,
            .login-form button {
                font-size: 0.95rem;
            }
            .bottom-container {
                padding-bottom: 1rem;
            }
        }
    </style>
</head>
<body>
    <body class="admin-login-bg">
    <div class="bottom-container">
        <form class="login-form" method="post" action="Admin_LogIn.php">
            <h2>Log in to your account</h2>
            
            <?php if (!empty($error_message)): ?>
                <div class="error-message"><?php echo htmlspecialchars($error_message); ?></div>
            <?php endif; ?>
            
            <?php if (!empty($success_message)): ?>
                <div class="success-message"><?php echo htmlspecialchars($success_message); ?></div>
            <?php endif; ?>
            
            <div style="margin-bottom: 1rem;">
                <label for="username">User Name</label>
                <input type="text" id="username" name="username" placeholder="Enter your user name" required>
            </div>
            <div style="margin-bottom: 1.5rem;">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" placeholder="Enter your Password" required>
            </div>
            <button type="submit">Log In</button>
        </form>
    </div>
</body>
</html>
