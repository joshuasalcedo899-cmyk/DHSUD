<?php
require_once __DIR__ . '/../auth.php';

requireLogin();

header('Location: Home_Page.php?dept=prls');
exit;
