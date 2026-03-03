<?php
require_once __DIR__ . '/../auth.php';

// Require login to access this page
requireLogin();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AFD Page</title>
    <link rel="icon" type="image/x-icon" href="../assets/DHSUDLogo.ico">
    <link rel="stylesheet" href="../main.css">
</head>
<body class="admin-home-bg">
    <div class="admin-home-header">
        <div class="admin-home-header-main">
            <div class="sidebar-trigger-wrap">
                <button type="button" id="homeSidebarTrigger" class="sidebar-menu-trigger" aria-label="Open sidebar" title="Menu" aria-controls="homeSidebar" aria-expanded="false">
                    <img src="../assets/Sidebar_Menu_Icon.svg" alt="" aria-hidden="true">
                </button>
            </div>
            <img src="../assets/DHSUD_Header.svg" alt="Admin Home Header" class="admin-home-header-img">
        </div>
        <div class="admin-home-header-border"></div>
    </div>

    <div id="homeSidebarOverlay" class="home-sidebar-overlay" hidden></div>
    <aside id="homeSidebar" class="home-sidebar" aria-hidden="true">
        <div class="home-sidebar-head">
            <div class="home-sidebar-user">
                <img src="../assets/User_Icon.svg" alt="" aria-hidden="true" class="home-sidebar-user-icon">
                <span>Welcome, Admin!</span>
            </div>
            <button type="button" id="homeSidebarClose" class="home-sidebar-close" aria-label="Close sidebar">&times;</button>
        </div>

        <nav class="home-sidebar-nav" aria-label="Department menu">
            <a href="Home_Page.php" class="home-sidebar-link dept-emes" data-dept="emes"><img src="../assets/Department_File_Icon.svg" alt="" aria-hidden="true"><span>EMES</span></a>
            <a href="PRLS_Page.php" class="home-sidebar-link dept-prls" data-dept="prls"><img src="../assets/Department_File_Icon.svg" alt="" aria-hidden="true"><span>PRLS</span></a>
            <a href="AFD_Page.php" class="home-sidebar-link dept-afd is-active" data-dept="afd"><img src="../assets/Department_File_Icon.svg" alt="" aria-hidden="true"><span>AFD</span></a>
            <a href="PHSD_Page.php" class="home-sidebar-link dept-phsd" data-dept="phsd"><img src="../assets/Department_File_Icon.svg" alt="" aria-hidden="true"><span>PHSD</span></a>
            <a href="ELUPD_Page.php" class="home-sidebar-link dept-elupd" data-dept="elupd"><img src="../assets/Department_File_Icon.svg" alt="" aria-hidden="true"><span>ELUPD</span></a>
            <a href="ORD_Page.php" class="home-sidebar-link dept-ord" data-dept="ord"><img src="../assets/Department_File_Icon.svg" alt="" aria-hidden="true"><span>ORD</span></a>
        </nav>

        <a href="logout.php" class="home-sidebar-logout">
            <img src="../assets/Logout_Icon.svg" alt="" aria-hidden="true">
            <span>Logout</span>
        </a>
    </aside>

    <div class="admin-home-container" style="max-width:1200px;margin-top:1rem;padding:1rem;">
        <h1 style="color:#22336A;margin:0 0 0.75rem;">AFD</h1>
        <p style="margin:0;color:#555;">Department page is ready for content.</p>
    </div>

    <script>
        function openHomeSidebar() {
            const sidebar = document.getElementById('homeSidebar');
            const overlay = document.getElementById('homeSidebarOverlay');
            const trigger = document.getElementById('homeSidebarTrigger');
            if (!sidebar || !overlay || !trigger) return;
            sidebar.classList.add('open');
            overlay.hidden = false;
            overlay.classList.add('show');
            sidebar.setAttribute('aria-hidden', 'false');
            trigger.setAttribute('aria-expanded', 'true');
            document.body.classList.add('home-sidebar-open');
        }

        function closeHomeSidebar() {
            const sidebar = document.getElementById('homeSidebar');
            const overlay = document.getElementById('homeSidebarOverlay');
            const trigger = document.getElementById('homeSidebarTrigger');
            if (!sidebar || !overlay || !trigger) return;
            sidebar.classList.remove('open');
            overlay.classList.remove('show');
            overlay.hidden = true;
            sidebar.setAttribute('aria-hidden', 'true');
            trigger.setAttribute('aria-expanded', 'false');
            document.body.classList.remove('home-sidebar-open');
        }

        function initHomeSidebar() {
            const trigger = document.getElementById('homeSidebarTrigger');
            const closeBtn = document.getElementById('homeSidebarClose');
            const overlay = document.getElementById('homeSidebarOverlay');
            const sidebar = document.getElementById('homeSidebar');
            if (!trigger || !closeBtn || !overlay || !sidebar) return;

            trigger.addEventListener('click', function(event) {
                event.stopPropagation();
                openHomeSidebar();
            });

            closeBtn.addEventListener('click', function() {
                closeHomeSidebar();
            });

            overlay.addEventListener('click', function() {
                closeHomeSidebar();
            });

            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    closeHomeSidebar();
                }
            });
        }

        document.addEventListener('DOMContentLoaded', function() {
            initHomeSidebar();
        });
    </script>
</body>
</html>
