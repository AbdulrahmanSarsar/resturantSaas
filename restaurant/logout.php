<?php
session_start();
unset($_SESSION['restaurant_id']);
unset($_SESSION['restaurant_name']);
unset($_SESSION['restaurant_slug']);
unset($_SESSION['restaurant_plan']);
session_destroy();
header('Location: login.php');
exit;
?>