<?php
require_once __DIR__ . '/includes/admin_auth.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

if (($_SESSION['role'] ?? '') === 'admin') {
    header('Location: admin.php');
    exit;
}

header('Location: calculator.php');
exit;
