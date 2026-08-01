<?php
require_once __DIR__ . '/includes/admin_auth.php';
require_once __DIR__ . '/includes/app_header.php';
require_admin();
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/admin_schema.php';

ensure_currencies_table($pdo);
$errors = [];
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'refresh') {
        if (refresh_cbr_currency_rates($pdo, $errors)) {
            $message = 'Курсы валют обновлены с cbr.ru.';
        }
    } elseif ($action === 'save_active') {
        $activeCodes = array_map('strtoupper', $_POST['active'] ?? []);
        $pdo->exec('UPDATE currencies SET is_active = 0 WHERE code <> "RUB"');
        $stmt = $pdo->prepare('UPDATE currencies SET is_active = 1 WHERE code = :code');
        $stmt->execute(['code' => 'RUB']);
        foreach ($activeCodes as $code) {
            $stmt->execute(['code' => $code]);
        }
        $message = 'Список действующих валют сохранен.';
    }
}

$currencies = $pdo->query('SELECT * FROM currencies ORDER BY code = "RUB" DESC, code ASC')->fetchAll();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<title>Валюты</title>
<style>
body { font-family: Arial, sans-serif; background: #f5f7fb; margin: 0; color: #1f2937; }
.header{display:flex;align-items:center;gap:16px;flex-wrap:wrap; background: radial-gradient(circle at 10% 10%, rgba(59,130,246,.35), transparent 32%), linear-gradient(120deg, #0f172a, #1e3a8a 58%, #0f766e); color: #fff; padding: 22px 36px 30px; box-shadow: 0 18px 45px rgba(15,23,42,.18); }
.header a { color: #dbeafe; margin-right: 16px; }
.container { max-width: 1120px; margin: 28px auto; padding: 0 20px; }
.panel { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 22px; margin-bottom: 24px; box-shadow: 0 8px 24px rgba(15, 23, 42, 0.06); }
button { border: 0; border-radius: 8px; padding: 10px 14px; background: #2563eb; color: #fff; cursor: pointer; }
table { width: 100%; border-collapse: collapse; background: #fff; }
th, td { padding: 12px; border-bottom: 1px solid #e5e7eb; text-align: left; }
th { background: #f8fafc; }
.errors { background: #fee2e2; color: #991b1b; padding: 12px; border-radius: 8px; }
.message { background: #dcfce7; color: #166534; padding: 12px; border-radius: 8px; }
.actions { display:flex; gap: 10px; margin-bottom: 16px; }
</style>
<?php echo app_header_styles(); ?>
</head>
<body>
<?php render_app_header(); ?>
<main class="container">
<section class="panel">
<?php if ($errors): ?><div class="errors"><?php echo e(implode(' ', $errors)); ?></div><?php endif; ?>
<?php if ($message): ?><p class="message"><?php echo e($message); ?></p><?php endif; ?>
<div class="actions"><form method="post"><input type="hidden" name="action" value="refresh"><button type="submit">Обновить курсы с cbr.ru</button></form></div>
<form method="post">
<input type="hidden" name="action" value="save_active">
<table><thead><tr><th>Активна</th><th>Код</th><th>Название</th><th>Курс к рублю</th><th>Обновлено</th></tr></thead><tbody>
<?php foreach ($currencies as $currency): ?>
<tr>
<td><input type="checkbox" name="active[]" value="<?php echo e($currency['code']); ?>" <?php echo (int)$currency['is_active'] === 1 ? 'checked' : ''; ?> <?php echo $currency['code'] === 'RUB' ? 'disabled' : ''; ?>></td>
<td><?php echo e($currency['code']); ?></td><td><?php echo e($currency['name']); ?></td>
<td><?php echo e(number_format((float)$currency['rate_to_rub'], 6, ',', ' ')); ?></td><td><?php echo e((string)($currency['updated_at'] ?? '—')); ?></td>
</tr>
<?php endforeach; ?>
</tbody></table>
<p><button type="submit">Сохранить действующие валюты</button></p>
</form>
</section>
</main>
</body></html>
