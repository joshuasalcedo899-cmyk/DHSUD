<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DHSUD_MAIL TRACKER</title>
    <link rel="icon" type="image/x-icon" href="assets/DHSUDLogo.ico">
    <link rel="stylesheet" href="main.css">
</head>
<body class="home-landing">
    <div class="button-container home-landing-buttons">
        <img src="assets/DHSUD_Logo.webp" alt="DHSUD Logo" class="home-landing-logo">
        <a href="pages/Admin_LogIn.php"><button>ADMIN</button></a>
        <a href="pages/Tracking_Page.php"><button >TRACKING RECORDS</button></a>
    </div>
    <script>
        (function () {
            function shouldAnimateFromLogin() {
                return document.referrer.indexOf('/pages/Admin_LogIn.php') !== -1;
            }

            function applyLogoFallbackTransition() {
                if (!shouldAnimateFromLogin() || !document.body) {
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
