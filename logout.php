<?php
// logout.php - Kuhitimisha Session na kurudi kwenye Login
require_once 'config.php';
session_unset();
session_destroy();
header('Location: login.php');
exit;
?>
