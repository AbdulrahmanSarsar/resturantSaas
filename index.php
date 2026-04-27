<?php
session_start();
require_once __DIR__ . '/config/database.php'; // يحدد BASE_URL تلقائياً (محلي/إنتاج)

// إذا في أدمن مسجل دخول روح للوحة التحكم
if(isset($_SESSION['admin_id'])) {
    header('Location: ' . BASE_URL . '/admin/index.php');
    exit;
}

// إذا في مطعم مسجل دخول روح للوحة المطعم
if(isset($_SESSION['restaurant_id'])) {
    header('Location: ' . BASE_URL . '/restaurant/dashboard/index.php');
    exit;
}

// غير هيك روح لصفحة الدخول
header('Location: ' . BASE_URL . '/admin/login.php');
exit;
?>