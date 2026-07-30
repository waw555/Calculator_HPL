<?php
session_start();
require_once __DIR__ . '/includes/db.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = :username LIMIT 1");
    $stmt->execute(['username' => $username]);
    $user = $stmt->fetch();

    $passwordMatches = false;

    if ($user) {
        $storedPassword = (string)$user['password'];
        $passwordMatches = password_verify($password, $storedPassword) || hash_equals($storedPassword, md5($password));
    }

    if ($user && $passwordMatches) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role'] = $user['role'];

        if ($user['role'] === 'admin') {
            header('Location: admin.php');
            exit;
        } else {
            header('Location: calculator.php');
            exit;
        }
    } else {
        $error = 'Неверный логин или пароль';
    }
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<title>Вход</title>
<style>
body { font-family: 'Inter', Arial, sans-serif; background: #f5f5f5; display: flex; justify-content: center; align-items: center; height: 100vh; }
form { background: #fff; padding: 30px; border-radius: 8px; box-shadow: 0 0 10px rgba(0,0,0,0.2); }
input { display: block; margin-bottom: 15px; padding: 10px; width: 250px; }
button { padding: 10px 20px; }
.error { color: red; margin-bottom: 10px; }
.header h1 { margin: 0; font-size: clamp(24px, 3.5vw, 36px); letter-spacing: -.02em; font-weight: 900; }
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;900&amp;display=swap"></style>
</head>
<body>
<form method="post">
    <h2>Вход</h2>
    <?php if($error) echo "<div class='error'>$error</div>"; ?>
    <input type="text" name="username" placeholder="Логин" required>
    <input type="password" name="password" placeholder="Пароль" required>
    <button type="submit">Войти</button>
</form>
</body>
</html>
