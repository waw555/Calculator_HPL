<?php
require_once __DIR__ . '/includes/admin_auth.php';
require_admin();

$query = $_SERVER['QUERY_STRING'] ?? '';
$location = 'admin_furniture.php' . ($query !== '' ? '?' . $query : '');
header('Location: ' . $location, true, 302);
exit;
