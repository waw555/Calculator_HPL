<?php
require_once __DIR__ . '/includes/admin_auth.php';
require_once __DIR__ . '/includes/app_header.php';
require_admin();
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/admin_schema.php';

ensure_manufacturers_table($pdo);

$errors = [];
$editing = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $pdo->prepare('DELETE FROM manufacturers WHERE id = :id');
            $stmt->execute(['id' => $id]);
        }
        header('Location: admin_manufacturers.php');
        exit;
    }

    $id = (int)($_POST['id'] ?? 0);
    $currentLogoPath = null;
    if ($id > 0) {
        $stmt = $pdo->prepare('SELECT logo_path FROM manufacturers WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $currentLogoPath = $stmt->fetchColumn() ?: null;
    }

    $fullName = trim($_POST['full_name'] ?? '');
    $countryOrigin = trim($_POST['country_origin'] ?? '');
    $note = trim($_POST['note'] ?? '');

    if ($fullName === '' || mb_strlen($fullName) > 20 || !preg_match('/^[\p{L}\p{N}\s-]+$/u', $fullName)) {
        $errors[] = 'Полное название должно содержать текст и цифры, максимум 20 символов.';
    }
    if ($countryOrigin === '' || mb_strlen($countryOrigin) > 20 || !preg_match('/^[\p{L}\s-]+$/u', $countryOrigin)) {
        $errors[] = 'Страна происхождения должна содержать только текст, максимум 20 символов.';
    }

    $logoPath = upload_image('logo', 'manufacturers', $errors, $currentLogoPath);

    if (!$errors) {
        $params = [
            'full_name' => $fullName,
            'country_origin' => $countryOrigin,
            'logo_path' => $logoPath,
            'note' => $note === '' ? null : $note,
        ];

        if ($action === 'update' && $id > 0) {
            $params['id'] = $id;
            $stmt = $pdo->prepare('UPDATE manufacturers
                SET full_name = :full_name, country_origin = :country_origin, logo_path = :logo_path, note = :note
                WHERE id = :id');
            $stmt->execute($params);
        } else {
            $stmt = $pdo->prepare('INSERT INTO manufacturers (full_name, country_origin, logo_path, note)
                VALUES (:full_name, :country_origin, :logo_path, :note)');
            $stmt->execute($params);
        }

        header('Location: admin_manufacturers.php');
        exit;
    }
}

$editId = (int)($_GET['edit'] ?? 0);
if ($editId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM manufacturers WHERE id = :id');
    $stmt->execute(['id' => $editId]);
    $editing = $stmt->fetch() ?: null;
}

$manufacturers = $pdo->query('SELECT * FROM manufacturers ORDER BY full_name ASC')->fetchAll();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<title>Производители</title>
<style>
body { font-family: 'Inter', Arial, sans-serif; background: #f5f7fb; margin: 0; color: #1f2937; }
.header{display:flex;align-items:center;gap:16px;flex-wrap:wrap; background: radial-gradient(circle at 10% 10%, rgba(59,130,246,.35), transparent 32%), linear-gradient(120deg, #0f172a, #1e3a8a 58%, #0f766e); color: #fff; padding: 22px 36px 30px; box-shadow: 0 18px 45px rgba(15,23,42,.18); }
.header a { color: #dbeafe; font-weight: 700; text-decoration: none; margin-right: 16px; }
.container { max-width: 1120px; margin: 28px auto; padding: 0 20px; }
.panel { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 22px; margin-bottom: 24px; box-shadow: 0 8px 24px rgba(15, 23, 42, 0.06); }
.grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 14px; }
label { display: block; font-weight: 600; margin-bottom: 6px; }
input, textarea { width: 100%; box-sizing: border-box; padding: 10px; border: 1px solid #cbd5e1; border-radius: 8px; }
button, .button { border: 0; border-radius: 8px; padding: 10px 14px; background: #2563eb; color: #fff; text-decoration: none; cursor: pointer; display: inline-block; }
.button.secondary, button.secondary { background: #64748b; }
button.danger { background: #dc2626; }
table { width: 100%; border-collapse: collapse; background: #fff; }
th, td { padding: 12px; border-bottom: 1px solid #e5e7eb; text-align: left; vertical-align: top; }
th { background: #f8fafc; }
.errors { background: #fee2e2; color: #991b1b; padding: 12px; border-radius: 8px; }
.actions { display: flex; gap: 8px; flex-wrap: wrap; }
.preview { max-width: 120px; max-height: 72px; border: 1px solid #e5e7eb; border-radius: 8px; object-fit: contain; background: #fff; }
.hint { color: #64748b; font-size: 13px; margin-top: 4px; }
.header h1 { margin: 0; font-size: clamp(24px, 3.5vw, 36px); letter-spacing: -.02em; font-weight: 900; }
</style>
<?php echo app_header_styles(); ?>
</head>
<body>
<?php render_app_header(); ?>
<main class="container">
    <section class="panel">
        <h2><?php echo $editing ? 'Редактировать производителя' : 'Добавить производителя'; ?></h2>
        <?php if ($errors): ?>
            <div class="errors"><?php echo e(implode(' ', $errors)); ?></div>
        <?php endif; ?>
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="action" value="<?php echo $editing ? 'update' : 'create'; ?>">
            <input type="hidden" name="id" value="<?php echo e((string)($editing['id'] ?? '')); ?>">
            <div class="grid">
                <div>
                    <label for="full_name">Полное название</label>
                    <input id="full_name" name="full_name" maxlength="20" required value="<?php echo e((string)($editing['full_name'] ?? '')); ?>">
                    <div class="hint">20 символов, текст и цифры.</div>
                </div>
                <div>
                    <label for="country_origin">Страна происхождения</label>
                    <input id="country_origin" name="country_origin" maxlength="20" required value="<?php echo e((string)($editing['country_origin'] ?? '')); ?>">
                    <div class="hint">20 символов, только текст.</div>
                </div>
                <div>
                    <label>Логотип</label>
                    <?php if (!empty($editing['logo_path'])): ?>
                        <p><img class="preview" src="<?php echo e($editing['logo_path']); ?>" alt="Логотип производителя"></p>
                    <?php else: ?>
                        <p class="hint">Загруженный логотип будет отображаться здесь.</p>
                    <?php endif; ?>
                    <label for="logo">Загрузка логотипа</label>
                    <input id="logo" type="file" name="logo" accept="image/*">
                </div>
            </div>
            <p><label for="note">Примечание</label><textarea id="note" name="note"><?php echo e((string)($editing['note'] ?? '')); ?></textarea></p>
            <p>
                <button type="submit">Сохранить</button>
                <?php if ($editing): ?><a class="button secondary" href="admin_manufacturers.php">Отмена</a><?php endif; ?>
            </p>
        </form>
    </section>

    <section class="panel">
        <h2>Список производителей</h2>
        <table>
            <thead><tr><th>Полное название</th><th>Страна происхождения</th><th>Логотип</th><th>Примечание</th><th>Действия</th></tr></thead>
            <tbody>
            <?php foreach ($manufacturers as $manufacturer): ?>
                <tr>
                    <td><?php echo e($manufacturer['full_name']); ?></td>
                    <td><?php echo e($manufacturer['country_origin']); ?></td>
                    <td><?php if (!empty($manufacturer['logo_path'])): ?><img class="preview" src="<?php echo e($manufacturer['logo_path']); ?>" alt="Логотип производителя"><?php else: ?>—<?php endif; ?></td>
                    <td><?php echo e((string)($manufacturer['note'] ?? '')); ?></td>
                    <td class="actions">
                        <a class="button secondary" href="admin_manufacturers.php?edit=<?php echo e((string)$manufacturer['id']); ?>">Изменить</a>
                        <form method="post" onsubmit="return confirm('Удалить производителя?');">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?php echo e((string)$manufacturer['id']); ?>">
                            <button class="danger" type="submit">Удалить</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$manufacturers): ?>
                <tr><td colspan="5">Производители пока не добавлены.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </section>
</main>
</body>
</html>
