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
    requireCsrfToken();
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
    <link rel="icon" type="image/x-icon" href="../assets/DHSUDLogo.ico">
    <link rel="stylesheet" href="../main.css">
</head>
<body class="admin-login-bg admin-login-page">
    <div class="bottom-container admin-login-shell">
        <div class="admin-login-branding">
            <img src="../assets/DHSUD_Logo.webp" alt="DHSUD Logo" class="admin-login-logo">
            <h1>MAIL TRACKING SYSTEM</h1>
        </div>
        <form class="login-form" method="post" action="Admin_LogIn.php">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(getCsrfToken(), ENT_QUOTES); ?>">
            <div class="login-form-header">
                <a href="../index.php" class="return-button" title="Return to home">
                    <img src="../assets/Return_Icon.svg" alt="Return">
                </a>
                <h2>Log in to your account</h2>
            </div>
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
    <script>
        (function () {
            function shouldAnimateFromIndex() {
                return document.referrer.indexOf('/index.php') !== -1;
            }

            function applyLogoFallbackTransition() {
                if (!shouldAnimateFromIndex() || !document.body) {
                    return;
                }
                document.body.classList.remove('logo-fallback-enter');
                void document.body.offsetWidth;
                document.body.classList.add('logo-fallback-enter');
            }

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', applyLogoFallbackTransition, { once: true });
            } else {
                applyLogoFallbackTransition();
            }

            window.addEventListener('pageshow', applyLogoFallbackTransition);
        })();
    </script>
</body>
</html>
