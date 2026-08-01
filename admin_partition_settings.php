<?php
require_once __DIR__ . '/includes/admin_auth.php';
require_once __DIR__ . '/includes/app_header.php';
require_admin();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<title>Конструктор перегородки</title>
<style>
body { font-family: 'Inter', Arial, sans-serif; background: #f5f7fb; margin: 0; color: #1f2937; }
.header{display:flex;align-items:center;gap:16px;flex-wrap:wrap; background: radial-gradient(circle at 10% 10%, rgba(59,130,246,.35), transparent 32%), linear-gradient(120deg, #0f172a, #1e3a8a 58%, #0f766e); color: #fff; padding: 22px 36px 30px; box-shadow: 0 18px 45px rgba(15,23,42,.18); }
.header a { color: #dbeafe; font-weight: 700; text-decoration: none; margin-right: 16px; }
.container { max-width: 1040px; margin: 32px auto; padding: 0 20px; }
.topline { display: flex; justify-content: space-between; gap: 16px; align-items: center; }
.back { display: inline-flex; align-items: center; gap: 6px; color: #dbeafe; text-decoration: none; font-size: 14px; margin-bottom: 4px; }
.back:hover { color: #fff; }
.cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 20px; }
.card { background: #fff; border-radius: 12px; padding: 24px; box-shadow: 0 8px 24px rgba(15,23,42,0.08); border: 1px solid #e5e7eb; display: flex; flex-direction: column; }
.card-icon { font-size: 32px; margin-bottom: 12px; }
.card h2 { margin: 0 0 8px 0; font-size: 18px; }
.card p { color: #64748b; font-size: 14px; flex: 1; margin: 0 0 16px 0; }
.card a { display: inline-block; padding: 10px 16px; border-radius: 8px; background: #2563eb; color: #fff; text-decoration: none; font-weight: 600; text-align: center; }
.card a:hover { background: #1d4ed8; }
.section-label { font-size: 12px; font-weight: 700; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.08em; margin: 28px 0 12px; }
.section-label:first-of-type { margin-top: 0; }
.header h1 { margin: 0; font-size: clamp(24px, 3.5vw, 36px); letter-spacing: -.02em; font-weight: 900; }
</style>
<?php echo app_header_styles(); ?>
</head>
<body>
<?php render_app_header(); ?>
<main class="container">

    <div class="section-label">Основное</div>
    <div class="cards">
        <section class="card">
            <div class="card-icon">🗂️</div>
            <h2>Типы перегородок</h2>
            <p>Справочник типов перегородок с чертежом, фото, превью, описанием и набором параметров для калькулятора.</p>
            <a href="admin_partitions.php?tab=types">Открыть типы</a>
        </section>
    </div>

    <div class="section-label">Настройка расчёта</div>
    <div class="cards">
        <section class="card">
            <div class="card-icon">⚙️</div>
            <h2>Параметры</h2>
            <p>Параметры расчёта и состав деталей перегородок: описание, значения по умолчанию, единицы измерения.</p>
            <a href="admin_partitions.php?tab=params">Открыть параметры</a>
        </section>
        <section class="card">
            <div class="card-icon">🔧</div>
            <h2>Конструктор</h2>
            <p>Поля типов перегородок и правила расчёта: условия, операторы, результаты для автоматического подбора деталей.</p>
            <a href="admin_partitions.php?tab=constructor">Открыть конструктор</a>
        </section>
    </div>

</main>
</body>
</html>
