<?php
session_start();

// إذا في أدمن مسجل دخول روح للوحة التحكم
if(isset($_SESSION['admin_id'])) {
    header('Location: /admin/index.php');
    exit;
}

// إذا في مطعم مسجل دخول روح للوحة المطعم
if(isset($_SESSION['restaurant_id'])) {
    header('Location: /restaurant/dashboard/index.php');
    exit;
}

// غير هيك روح لصفحة الدخول
header('Location: /admin/login.php');
exit;
?>