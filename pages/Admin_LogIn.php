<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Log-In Page</title>
    <link rel="stylesheet" href="../main.css">
</head>
<body>
    <body class="admin-login-bg">
    <div class="bottom-container">
        <form class="login-form" method="post" action="#">
            <h2>Log in to your account  </h2>
            <div style="margin-bottom: 1rem;">
                <label for="email">Email Address</label>
                <input type="email" id="email" name="email" placeholder="Enter your Email"required>
            </div>
            <div style="margin-bottom: 1.5rem;">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" placeholder="Enter your Password"required>
            </div>
            <button type="submit">Log In</button>
        </form>
    </div>
</body>
</html>