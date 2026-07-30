<?php
require_once __DIR__ . '/includes/admin_auth.php';
require_admin();
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/admin_schema.php';

ensure_countertop_settings_table($pdo);

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $kerf = trim($_POST['kerf_mm'] ?? '');
    $blankWidth = trim($_POST['blank_width_mm'] ?? '');
    if ($kerf === '' || !is_numeric($kerf) || (float)$kerf < 0) {
        $errors[] = 'Толщина реза должна быть неотрицательным числом.';
    }
    if ($blankWidth === '' || !is_numeric($blankWidth) || (int)$blankWidth <= 0) {
        $errors[] = 'Ширина заготовки должна быть положительным числом.';
    }
    if (!$errors) {
        $stmt = $pdo->prepare('UPDATE countertop_settings SET kerf_mm = :kerf, blank_width_mm = :bw WHERE id = 1');
        $stmt->execute(['kerf' => (float)$kerf, 'bw' => (int)$blankWidth]);
        header('Location: admin_countertop_settings.php?saved=1');
        exit;
    }
}

$row = $pdo->query('SELECT kerf_mm, blank_width_mm FROM countertop_settings WHERE id = 1')->fetch();
$kerfMm = $row ? (string)$row['kerf_mm'] : '4.0';
$blankWidthMm = $row ? (string)$row['blank_width_mm'] : '600';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<title>Настройка расчёта столешниц</title>
<style>
body { font-family: 'Inter', Arial, sans-serif; background: #f5f7fb; margin: 0; color: #1f2937; }
.header{display:flex;align-items:center;gap:16px;flex-wrap:wrap; background: radial-gradient(circle at 10% 10%, rgba(59,130,246,.35), transparent 32%), linear-gradient(120deg, #0f172a, #1e3a8a 58%, #0f766e); color: #fff; padding: 22px 36px 30px; box-shadow: 0 18px 45px rgba(15,23,42,.18); }
.header a { color: #dbeafe; margin-right: 16px; text-decoration: none; }
.header h1 { margin: 0; font-size: clamp(24px, 3.5vw, 36px); letter-spacing: -.02em; font-weight: 900; }
.container { max-width: 700px; margin: 32px auto; padding: 0 20px; }
.panel { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 24px; box-shadow: 0 8px 24px rgba(15,23,42,0.06); margin-bottom: 20px; }
label { display: block; font-weight: 600; margin-bottom: 6px; font-size: 14px; }
input { width: 100%; box-sizing: border-box; padding: 10px 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 15px; }
input[readonly] { background: #f8fafc; }
button { border: 0; border-radius: 8px; padding: 10px 20px; background: #2563eb; color: #fff; cursor: pointer; font-weight: 600; font-size: 14px; }
button:hover { background: #1d4ed8; }
.hint { color: #64748b; font-size: 12px; margin-top: 4px; }
.success { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; font-size: 14px; }
.error { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; font-size: 14px; }
.topline { display: flex; justify-content: space-between; gap: 16px; align-items: center; }
.grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
@media (max-width: 600px) { .grid { grid-template-columns: 1fr; } }
</style>
</head>
<body>
<header class="header">
    <div style="width:100%;display:flex;align-items:center;gap:16px;">
        <a href="admin.php">← Админ-панель</a>
        <h1 style="flex:1;text-align:center;margin:0;">Настройка расчёта столешниц</h1>
        <a href="logout.php">Выйти</a>
    </div>
</header>
<main class="container">

    <?php if (isset($_GET['saved'])): ?>
        <div class="success">Настройки сохранены.</div>
    <?php endif; ?>
    <?php foreach ($errors as $e): ?>
        <div class="error"><?php echo htmlspecialchars($e); ?></div>
    <?php endforeach; ?>

    <div class="panel">
        <form method="post">
            <div class="grid">
                <div>
                    <label for="kerf_mm">Толщина реза пилы, мм</label>
                    <input id="kerf_mm" type="number" step="0.1" min="0" name="kerf_mm" value="<?php echo htmlspecialchars($kerfMm); ?>">
                    <div class="hint">По умолчанию: 4 мм</div>
                </div>
                <div>
                    <label for="blank_width_mm">Ширина заготовки, мм</label>
                    <input id="blank_width_mm" type="number" step="1" min="1" name="blank_width_mm" value="<?php echo htmlspecialchars($blankWidthMm); ?>">
                    <div class="hint">По умолчанию: 600 мм. Делитель ширины листа.</div>
                </div>
            </div>
            <div style="margin-top: 20px;">
                <button type="submit">Сохранить</button>
            </div>
        </form>
    </div>

    <div class="panel">
        <h3 style="margin-top:0">Формула расчёта стоимости столешницы</h3>
        <p><strong>1.</strong> Ширина листа ÷ <code>blank_width_mm</code> = количество заготовок (целое).</p>
        <p><strong>2.</strong> Ширина заготовки = (Ширина листа ÷ кол-во заготовок) − (<code>kerf_mm</code> ÷ 2).</p>
        <p><strong>3.</strong> Кол-во заготовок для детали = ceil(Ширина детали ÷ Ширина заготовки), макс. = всего заготовок.</p>
        <p><strong>4.</strong> Стоимость материала = (заготовок для детали ÷ всего заготовок) × цена листа × кол-во.</p>
        <p><strong>5.</strong> Стоимость обработки = <code>processingPerM</code> (EUR/м) × периметр (м) × курс валюты.</p>
        <p><strong>6.</strong> Итого = ceil(материал + обработка) × коэфф. завода × коэфф. салона.</p>
    </div>

</main>
</body>
</html>
