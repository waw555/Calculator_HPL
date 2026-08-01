<?php
$secureSession = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
if (PHP_SESSION_ACTIVE !== session_status()) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $secureSession,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function require_admin(): void
{
    require_valid_post_request();
    if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
        header('Location: login.php');
        exit;
    }
}

function require_login(): void
{
    require_valid_post_request();
    if (!isset($_SESSION['user_id'])) {
        header('Location: login.php');
        exit;
    }
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf_token(?string $token): bool
{
    return is_string($token) && isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function request_origin_is_same_site(): bool
{
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if ($host === '') {
        return true;
    }

    $fetchSite = strtolower($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '');
    if (in_array($fetchSite, ['same-origin', 'same-site', 'none'], true)) {
        return true;
    }

    foreach (['HTTP_ORIGIN', 'HTTP_REFERER'] as $header) {
        $value = $_SERVER[$header] ?? '';
        if ($value === '') {
            continue;
        }
        $originHost = parse_url($value, PHP_URL_HOST);
        if (is_string($originHost) && strcasecmp($originHost, preg_replace('/:\d+$/', '', $host)) === 0) {
            return true;
        }
    }

    return false;
}

function require_valid_post_request(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        return;
    }

    $token = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
    if (verify_csrf_token(is_string($token) ? $token : null) || request_origin_is_same_site()) {
        return;
    }

    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'CSRF validation failed.';
    exit;
}
