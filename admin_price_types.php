<?php
require_once __DIR__ . '/includes/admin_auth.php';
require_admin();
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/admin_schema.php';

ensure_price_types_table($pdo);
$errors = [];
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $name = trim($_POST['name'] ?? '');
        if ($name === '') {
            $errors[] = 'Введите название типа цен.';
        } else {
            $stmt = $pdo->prepare('INSERT INTO price_types (name, sort_order) VALUES (:name, (SELECT COALESCE(MAX(t.sort_order),-1)+1 FROM price_types t))');
            $stmt->execute(['name' => $name]);
            $message = 'Тип цен «' . $name . '» добавлен.';
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $pdo->prepare('DELETE FROM price_types WHERE id = :id');
            $stmt->execute(['id' => $id]);
            $message = 'Тип цен удалён.';
        }
    } elseif ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $pdo->prepare('UPDATE price_types SET is_active = 1 - is_active WHERE id = :id');
            $stmt->execute(['id' => $id]);
            $message = 'Статус обновлён.';
        }
    } elseif ($action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        if ($id > 0 && $name !== '') {
            $stmt = $pdo->prepare('UPDATE price_types SET name = :name WHERE id = :id');
            $stmt->execute(['name' => $name, 'id' => $id]);
            $message = 'Название обновлено.';
        }
    }
}

$priceTypes = $pdo->query('SELECT * FROM price_types ORDER BY sort_order ASC, id ASC')->fetchAll();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<title>Типы цен</title>
<style>
body { font-family: Arial, sans-serif; background: #f5f7fb; margin: 0; color: #1f2937; }
.header{display:flex;align-items:center;gap:16px;flex-wrap:wrap; background: radial-gradient(circle at 10% 10%, rgba(59,130,246,.35), transparent 32%), linear-gradient(120deg, #0f172a, #1e3a8a 58%, #0f766e); color: #fff; padding: 22px 36px 30px; box-shadow: 0 18px 45px rgba(15,23,42,.18); }
.header a { color: #dbeafe; margin-right: 16px; text-decoration: none; }
.container { max-width: 800px; margin: 28px auto; padding: 0 20px; }
.panel { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 22px; margin-bottom: 24px; box-shadow: 0 8px 24px rgba(15, 23, 42, 0.06); }
button { border: 0; border-radius: 8px; padding: 10px 14px; background: #2563eb; color: #fff; cursor: pointer; font-weight: 600; }
button:hover { background: #1d4ed8; }
.btn-danger { background: #dc2626; }
.btn-danger:hover { background: #b91c1c; }
.btn-sm { padding: 6px 10px; font-size: 13px; }
table { width: 100%; border-collapse: collapse; background: #fff; }
th, td { padding: 12px; border-bottom: 1px solid #e5e7eb; text-align: left; }
th { background: #f8fafc; font-weight: 700; }
.errors { background: #fee2e2; color: #991b1b; padding: 12px; border-radius: 8px; }
.message { background: #dcfce7; color: #166534; padding: 12px; border-radius: 8px; }
.add-form { display: flex; gap: 10px; margin-bottom: 16px; }
.add-form input[type="text"] { flex: 1; padding: 10px 12px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 14px; }
.badge { display: inline-block; padding: 3px 8px; border-radius: 6px; font-size: 12px; font-weight: 700; }
.badge-active { background: #dcfce7; color: #166534; }
.badge-inactive { background: #f1f5f9; color: #94a3b8; }
.header h1 { margin: 0; font-size: clamp(24px, 3.5vw, 36px); letter-spacing: -.02em; font-weight: 900; }
</style>
</head>
<body>
<header class="header">
    <div style="width:100%;display:flex;align-items:center;gap:16px;">
        <a href="admin.php">← Админ-панель</a>
        <h1 style="flex:1;text-align:center;margin:0;">Типы цен</h1>
        <a href="logout.php" style="color:#dbeafe">Выйти</a>
    </div>
</header>
<main class="container">
    <section class="panel">
        <?php if ($errors): ?><div class="errors"><?php echo e(implode(' ', $errors)); ?></div><?php endif; ?>
        <?php if ($message): ?><p class="message"><?php echo e($message); ?></p><?php endif; ?>

        <form method="post" class="add-form">
            <input type="hidden" name="action" value="add">
            <input type="text" name="name" placeholder="Название типа цен (например: Дилер, Розница, Опт)" required>
            <button type="submit">Добавить</button>
        </form>

        <table>
            <thead>
                <tr>
                    <th style="width:60px">ID</th>
                    <th>Название</th>
                    <th style="width:100px">Статус</th>
                    <th style="width:180px">Действия</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($priceTypes as $pt): ?>
                <tr>
                    <td><?php echo (int)$pt['id']; ?></td>
                    <td>
                        <form method="post" style="display:flex;gap:6px;align-items:center;">
                            <input type="hidden" name="action" value="update">
                            <input type="hidden" name="id" value="<?php echo (int)$pt['id']; ?>">
                            <input type="text" name="name" value="<?php echo e($pt['name']); ?>" style="border:1px solid #d1d5db;border-radius:6px;padding:4px 8px;font-size:14px;flex:1;">
                            <button type="submit" class="btn-sm">Сохранить</button>
                        </form>
                    </td>
                    <td>
                        <span class="badge <?php echo $pt['is_active'] ? 'badge-active' : 'badge-inactive'; ?>">
                            <?php echo $pt['is_active'] ? 'Активен' : 'Скрыт'; ?>
                        </span>
                    </td>
                    <td style="display:flex;gap:6px;">
                        <form method="post">
                            <input type="hidden" name="action" value="toggle">
                            <input type="hidden" name="id" value="<?php echo (int)$pt['id']; ?>">
                            <button type="submit" class="btn-sm"><?php echo $pt['is_active'] ? 'Скрыть' : 'Показать'; ?></button>
                        </form>
                        <form method="post" onsubmit="return confirm('Удалить тип цен?')">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?php echo (int)$pt['id']; ?>">
                            <button type="submit" class="btn-sm btn-danger">Удалить</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (empty($priceTypes)): ?>
                <tr><td colspan="4" style="text-align:center;color:#94a3b8;padding:24px;">Нет типов цен</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </section>
</main>
</body>
</html>
