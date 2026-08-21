<?php
require_once __DIR__ . '/includes/admin_auth.php';
require_admin();
require_once __DIR__ . '/includes/db.php';

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($id === false || $id === null) {
    http_response_code(404);
    exit;
}

$stmt = $pdo->prepare('SELECT photo_path FROM price_list WHERE id = :id');
$stmt->execute(['id' => $id]);
$storedPath = trim((string)($stmt->fetchColumn() ?: ''));
$fileName = basename(str_replace('\\', '/', $storedPath));
$uploadDirectory = realpath(__DIR__ . '/uploads/furniture');
$photoPath = $uploadDirectory !== false && $fileName !== ''
    ? realpath($uploadDirectory . DIRECTORY_SEPARATOR . $fileName)
    : false;

if ($photoPath === false || !str_starts_with($photoPath, $uploadDirectory . DIRECTORY_SEPARATOR) || !is_file($photoPath)) {
    http_response_code(404);
    exit;
}

$imageInfo = @getimagesize($photoPath);
if ($imageInfo === false || empty($imageInfo['mime']) || !str_starts_with($imageInfo['mime'], 'image/')) {
    http_response_code(404);
    exit;
}

header('Content-Type: ' . $imageInfo['mime']);
header('Content-Length: ' . (string)filesize($photoPath));
header('Cache-Control: private, max-age=3600');
header('X-Content-Type-Options: nosniff');
readfile($photoPath);
