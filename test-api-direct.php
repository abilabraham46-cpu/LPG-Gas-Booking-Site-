<?php
session_start();
$_SESSION['user_id'] = 2;
$_SESSION['role'] = 'customer';

// Direct include of the notifications API
require_once 'api/notifications.php';
?>