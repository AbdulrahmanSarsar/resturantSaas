<?php
/**
 * restaurant/staff/logout.php
 * تسجيل خروج آمن للموظفين — يمسح الجلسة ويوجّه للوغن
 */
require_once __DIR__ . '/../../bootstrap.php';

$auth->logout();
header('Location: login.php');
exit;