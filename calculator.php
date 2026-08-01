<?php
// Bootstrap the authenticated calculator index without merge markers or stray output.
require_once __DIR__ . '/includes/admin_auth.php';
require_login();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<title>Калькуляторы</title>
<style>
body { font-family: Arial, sans-serif; margin: 0; color: #1f2937; background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 50%, #3b82f6 100%); min-height: 100vh; }
.header{display:flex;align-items:center;gap:16px;flex-wrap:wrap; padding: 24px 32px; }
.header a { color: #dbeafe; text-decoration: none; }
.header h1 { margin: 0; font-size: clamp(24px, 3.5vw, 36px); letter-spacing: -.02em; font-weight: 900; color: #fff; }
.container { max-width: 1040px; margin: 0 auto; padding: 20px; }
.cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 20px; margin-top: 16px; }
.card { background: #fff; border-radius: 14px; padding: 28px; box-shadow: 0 8px 24px rgba(15,23,42,0.15); border: 1px solid rgba(255,255,255,.2); display:flex; flex-direction:column; }
.card-icon { font-size: 40px; margin-bottom: 14px; }
.card h2 { margin: 0 0 10px 0; font-size: 19px; color:#1f2937; }
.card p { color: #64748b; font-size: 14px; flex: 1; margin: 0 0 18px 0; }
.card a { display: inline-block; padding: 11px 18px; border-radius: 8px; background: #2563eb; color: #fff; text-decoration: none; font-weight: 700; text-align: center; }
.card a:hover { background: #1d4ed8; }
.topline { display: flex; justify-content: space-between; gap: 16px; align-items: center; }
</style>
</head>
<body>
<header class="header">
    <div style="width:100%;display:flex;align-items:center;gap:16px;">
        <a href="admin.php">← Админ-панель</a>
        <h1 style="flex:1;text-align:center;margin:0;">Калькуляторы</h1>
        <a href="logout.php" style="color:#dbeafe">Выйти</a>
    </div>
</header>
<main class="container">
    <div class="cards">
        <section class="card">
            <div class="card-icon">🚿</div>
            <h2>Калькулятор сантехнических кабин</h2>
            <p>Расчёт перегородок, подбор деталей, формирование коммерческого предложения и история расчётов.</p>
            <a href="calculator_septic.php">Открыть калькулятор</a>
        </section>
        <section class="card">
            <div class="card-icon">🪨</div>
            <h2>Калькулятор столешниц</h2>
            <p>Расчёт столешниц: размеры, материалы, кромка и стоимость изготовления.</p>
            <a href="calculator_countertops.php">Открыть калькулятор</a>
        </section>
        <section class="card">
            <div class="card-icon">📐</div>
            <h2>Раскрой панелей</h2>
            <p>Оптимальный раскрой листовых материалов с картой раскроя, расчётом отходов и стоимости.</p>
            <a href="calculator_cutting.php">Открыть раскрой</a>
        </section>
        <section class="card">
            <div class="card-icon">🔧</div>
            <h2>Расчёт подсистемы</h2>
            <p>Расход клея-герметика, ленты, праймера, очистителя и других материалов для монтажа HPL-панелей.</p>
            <a href="calculator_subsystem.php">Открыть расчёт</a>
        </section>
    </div>

    <section style="margin-top:40px;">
        <h2 style="color:#fff;font-size:clamp(20px,3vw,28px);margin:0 0 20px 0;font-weight:900;">Прайс-лист</h2>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:20px;">
            <div class="card">
                <div class="card-icon">🪨</div>
                <h2>Столешницы</h2>
                <p>Цены на столешницы из HPL и других материалов по размерам и конфигурациям.</p>
                <a href="price_list_countertops.php">Смотреть прайс</a>
            </div>
            <div class="card">
                <div class="card-icon">🚿</div>
                <h2>Сантех кабины</h2>
                <p>Стоимость перегородок и элементов сантехнических кабин.</p>
                <a href="price_list_septic.php">Смотреть прайс</a>
            </div>
            <div class="card">
                <div class="card-icon">📐</div>
                <h2>Фасадные панели</h2>
                <p>Цены на HPL-панели для фасадного остекления и отделки.</p>
                <a href="price_list_facades.php">Смотреть прайс</a>
            </div>
        </div>
    </section>
</main>
</body>
</html>
