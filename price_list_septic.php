<?php
require_once __DIR__ . '/includes/admin_auth.php';
require_login();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<title>Прайс-лист сантехнических кабин</title>
<style>
body { font-family: Inter, Arial, sans-serif; margin: 0; background: #f5f7fb; color: #1f2937; }
.header { background: linear-gradient(120deg, #0f172a, #1e3a8a 58%, #0f766e); color: #fff; padding: 22px 36px 30px; }
.header a { color: #dbeafe; font-weight: 700; text-decoration: none; }
.container { max-width: 920px; margin: 32px auto; padding: 0 20px; }
.panel { background: #fff; border: 1px solid #e5e7eb; border-radius: 16px; padding: 24px; box-shadow: 0 8px 24px rgba(15, 23, 42, .08); }
.button { display: inline-block; margin-top: 16px; padding: 10px 16px; border-radius: 8px; background: #2563eb; color: #fff; text-decoration: none; font-weight: 700; }
</style>
</head>
<body>
<header class="header"><a href="calculator.php">← К калькуляторам</a><h1>Прайс-лист сантехнических кабин</h1></header>
<main class="container">
    <section class="panel">
        <h2>Раздел в подготовке</h2>
        <p>Прайс-лист сантехнических кабин ещё не опубликован. Для расчёта стоимости используйте калькулятор сантехнических кабин.</p>
        <a class="button" href="calculator_septic.php">Открыть калькулятор</a>
    </section>
</main>
</body>
</html>
