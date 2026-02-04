<?php
require_once '../auth.php';

// Logout the user
logoutUser();

// Redirect to login page
header('Location: Admin_LogIn.php');
exit;
?>
