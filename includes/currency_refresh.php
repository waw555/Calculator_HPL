<?php

require_once __DIR__ . '/admin_auth.php';
require_login();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/admin_schema.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Метод не поддерживается.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$errors = [];
ensure_currencies_table($pdo);
$automatic = isset($_GET['automatic']) && $_GET['automatic'] === '1';
$shouldRefresh = !$automatic || cbr_currency_rates_are_stale($pdo);
$ok = !$shouldRefresh || refresh_cbr_currency_rates($pdo, $errors);

if (!$ok) {
    http_response_code(502);
}

echo json_encode([
    'ok' => $ok,
    'refreshed' => $ok && $shouldRefresh,
    'message' => $ok ? ($shouldRefresh ? 'Курсы валют обновлены.' : 'Курсы валют актуальны.') : (implode(' ', $errors) ?: 'Не удалось обновить курсы валют.'),
], JSON_UNESCAPED_UNICODE);
