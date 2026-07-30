<?php
require_once __DIR__ . '/includes/admin_auth.php';
require_admin();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<title>Админ-панель</title>
<style>
body { font-family: 'Inter', Arial, sans-serif; background: #f5f7fb; margin: 0; color: #1f2937; }
.header{display:flex;align-items:center;gap:16px;flex-wrap:wrap; background: radial-gradient(circle at 10% 10%, rgba(59,130,246,.35), transparent 32%), linear-gradient(120deg, #0f172a, #1e3a8a 58%, #0f766e); color: #fff; padding: 22px 36px 30px; box-shadow: 0 18px 45px rgba(15,23,42,.18); }
.header a { color: #dbeafe; font-weight: 700; text-decoration: none; }
.container { max-width: 1040px; margin: 32px auto; padding: 0 20px; }
.cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 20px; }
.card { background: #fff; border-radius: 12px; padding: 24px; box-shadow: 0 8px 24px rgba(15, 23, 42, 0.08); border: 1px solid #e5e7eb; }
.card h2 { margin-top: 0; }
.card a { display: inline-block; margin-top: 12px; padding: 10px 16px; border-radius: 8px; background: #2563eb; color: #fff; text-decoration: none; }
.topline { display: flex; justify-content: space-between; gap: 16px; align-items: center; }
.section-label { font-size: 12px; font-weight: 700; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.08em; margin: 32px 0 12px; }
.section-label:first-of-type { margin-top: 0; }
.header h1 { margin: 0; font-size: clamp(24px, 3.5vw, 36px); letter-spacing: -.02em; font-weight: 900; }
</style>
</head>
<body>
<header class="header">
    <div style="width:100%;display:flex;align-items:center;gap:16px;">
        <a href="admin.php">← Админ-панель</a>
        <h1 style="flex:1;text-align:center;margin:0;">Админ-панель</h1>
        <a href="logout.php">Выйти</a>
    </div>
</header>
<main class="container">

    <div class="section-label">Продукция</div>
    <div class="cards">
        <section class="card">
            <h2>🪟 Панели</h2>
            <p>Панели HPL: форматы, тиснения, толщины, декор, цены и складская программа.</p>
            <a href="admin_panels.php">Открыть</a>
        </section>
        <section class="card">
            <h2>🔩 Фурнитура</h2>
            <p>Фурнитура, категории и комплекты: фото, цены, складская программа.</p>
            <a href="admin_furniture.php">Открыть</a>
        </section>
        <section class="card">
            <h2>🚪 Перегородки</h2>
            <p>Типы перегородок, параметры расчёта и конструктор правил.</p>
            <a href="admin_partitions.php">Открыть</a>
        </section>
        <section class="card">
            <h2>🛠️ Услуги</h2>
            <p>Услуги и категории услуг: единицы измерения, цены и активность.</p>
            <a href="admin_services.php">Открыть</a>
        </section>
        <section class="card">
            <h2>📐 Формулы</h2>
            <p>Формулы расчёта стоимости изделий: параметры заготовок, обработка кромки, ограничения размеров.</p>
            <a href="admin_formulas.php">Открыть</a>
        </section>
    </div>

    <div class="section-label">Справочники</div>
    <div class="cards">
        <section class="card">
            <h2>🏭 Производители</h2>
            <p>Производители панелей: полное название, страна происхождения и логотип.</p>
            <a href="admin_manufacturers.php">Открыть</a>
        </section>
        <section class="card">
            <h2>🏢 Поставщики</h2>
            <p>Поставщики: компания, продукция, адрес, сайт и контакты.</p>
            <a href="admin_suppliers.php">Открыть</a>
        </section>
        <section class="card">
            <h2>💱 Валюты</h2>
            <p>Действующие валюты и обновление курсов с сайта cbr.ru.</p>
            <a href="admin_currencies.php">Открыть</a>
        </section>
        <section class="card">
            <h2>📐 Единицы измерения</h2>
            <p>Единицы измерения с полным и сокращённым названием.</p>
            <a href="admin_units.php">Открыть</a>
        </section>
        <section class="card">
            <h2>🏷️ Типы цен</h2>
            <p>Типы цен: Дилер, Розница, Опт и другие категории ценовых политик.</p>
            <a href="admin_price_types.php">Открыть</a>
        </section>
    </div>

    <div class="section-label">Система</div>
    <div class="cards">
        <section class="card">
            <h2>🏗️ Организация</h2>
            <p>Реквизиты, контакты, банковские данные и логотип для коммерческих предложений.</p>
            <a href="admin_organization.php">Открыть</a>
        </section>
        <section class="card">
            <h2>👥 Пользователи</h2>
            <p>Учётные записи: добавление, удаление и смена паролей.</p>
            <a href="admin_users.php">Открыть</a>
        </section>
    </div>

</main>
</body>
</html>
