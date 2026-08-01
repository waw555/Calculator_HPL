<?php
require_once __DIR__ . '/includes/admin_auth.php';
require_once __DIR__ . '/includes/app_header.php';
require_admin();
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/admin_schema.php';

$pdo->exec("CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(120) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role VARCHAR(32) NOT NULL DEFAULT 'user',
    note TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

try {
    $pdo->exec('ALTER TABLE users MODIFY password VARCHAR(255) NOT NULL');
    if (function_exists('add_column_if_missing')) { add_column_if_missing($pdo, 'users', 'note', 'note TEXT NULL'); }
} catch (PDOException $exception) {
    // The users table may be managed outside this page; keep the page usable if the column is already compatible.
}

$errors = [];
$editing = null;
$allowedRoles = ['user', 'admin'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0 && $id !== (int)$_SESSION['user_id']) {
            $stmt = $pdo->prepare('DELETE FROM users WHERE id = :id');
            $stmt->execute(['id' => $id]);
        }
        header('Location: admin_users.php');
        exit;
    }

    $id = (int)($_POST['id'] ?? 0);
    $username = trim($_POST['username'] ?? '');
    $password = (string)($_POST['password'] ?? '');
    $role = $_POST['role'] ?? 'user';
    $note = trim($_POST['note'] ?? '');

    if ($action === 'update' && $id === (int)$_SESSION['user_id']) {
        $role = 'admin';
    }

    if ($username === '') {
        $errors[] = 'Укажите логин пользователя.';
    }
    if (!in_array($role, $allowedRoles, true)) {
        $errors[] = 'Выберите корректную роль пользователя.';
    }
    if ($action !== 'update' && $password === '') {
        $errors[] = 'Укажите пароль для нового пользователя.';
    }
    if ($password !== '' && strlen($password) < 6) {
        $errors[] = 'Пароль должен содержать минимум 6 символов.';
    }

    if ($username !== '') {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE username = :username AND id <> :id LIMIT 1');
        $stmt->execute(['username' => $username, 'id' => $id]);
        if ($stmt->fetch()) {
            $errors[] = 'Пользователь с таким логином уже существует.';
        }
    }

    if (!$errors) {
        if ($action === 'update' && $id > 0) {
            $params = [
                'id' => $id,
                'username' => $username,
                'role' => $role,
                'note' => $note === '' ? null : $note,
            ];
            $passwordSql = '';

            if ($password !== '') {
                $params['password'] = password_hash($password, PASSWORD_DEFAULT);
                $passwordSql = ', password = :password';
            }

            $stmt = $pdo->prepare("UPDATE users SET username = :username, role = :role, note = :note{$passwordSql} WHERE id = :id");
            $stmt->execute($params);

            if ($id === (int)$_SESSION['user_id']) {
                $_SESSION['username'] = $username;
                $_SESSION['role'] = $role;
            }
        } else {
            $stmt = $pdo->prepare('INSERT INTO users (username, password, role, note) VALUES (:username, :password, :role, :note)');
            $stmt->execute([
                'username' => $username,
                'password' => password_hash($password, PASSWORD_DEFAULT),
                'role' => $role,
                'note' => $note === '' ? null : $note,
            ]);
        }

        header('Location: admin_users.php');
        exit;
    }
}

$editId = (int)($_GET['edit'] ?? 0);
if ($editId > 0) {
    $stmt = $pdo->prepare('SELECT id, username, role, note FROM users WHERE id = :id');
    $stmt->execute(['id' => $editId]);
    $editing = $stmt->fetch() ?: null;
}

$users = $pdo->query('SELECT id, username, role, note FROM users ORDER BY username ASC')->fetchAll();
$isEditingCurrentUser = $editing && (int)$editing['id'] === (int)$_SESSION['user_id'];
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<title>Пользователи</title>
<style>
body { font-family: 'Inter', Arial, sans-serif; background: #f5f7fb; margin: 0; color: #1f2937; }
.header{display:flex;align-items:center;gap:16px;flex-wrap:wrap; background: radial-gradient(circle at 10% 10%, rgba(59,130,246,.35), transparent 32%), linear-gradient(120deg, #0f172a, #1e3a8a 58%, #0f766e); color: #fff; padding: 22px 36px 30px; box-shadow: 0 18px 45px rgba(15,23,42,.18); }
.header a { color: #dbeafe; font-weight: 700; text-decoration: none; margin-right: 16px; }
.container { max-width: 980px; margin: 28px auto; padding: 0 20px; }
.panel { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 22px; margin-bottom: 24px; box-shadow: 0 8px 24px rgba(15, 23, 42, 0.06); }
.grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 14px; }
label { display: block; font-weight: 600; margin-bottom: 6px; }
input, select, textarea { width: 100%; box-sizing: border-box; padding: 10px; border: 1px solid #cbd5e1; border-radius: 8px; }
button, .button { border: 0; border-radius: 8px; padding: 10px 14px; background: #2563eb; color: #fff; text-decoration: none; cursor: pointer; display: inline-block; }
.button.secondary, button.secondary { background: #64748b; }
button.danger { background: #dc2626; }
table { width: 100%; border-collapse: collapse; background: #fff; }
th, td { padding: 12px; border-bottom: 1px solid #e5e7eb; text-align: left; vertical-align: top; }
th { background: #f8fafc; }
.errors { background: #fee2e2; color: #991b1b; padding: 12px; border-radius: 8px; }
.hint { color: #64748b; font-size: 14px; margin-top: 6px; }
.actions { display: flex; gap: 8px; flex-wrap: wrap; }
.badge { display: inline-block; padding: 4px 8px; border-radius: 999px; background: #dbeafe; color: #1e3a8a; font-weight: 700; }
.badge.admin { background: #fee2e2; color: #991b1b; }
.header h1 { margin: 0; font-size: clamp(24px, 3.5vw, 36px); letter-spacing: -.02em; font-weight: 900; }
</style>
<?php echo app_header_styles(); ?>
</head>
<body>
<?php render_app_header(); ?>
<main class="container">
    <section class="panel">
        <h2><?php echo $editing ? 'Редактировать пользователя' : 'Добавить пользователя'; ?></h2>
        <?php if ($errors): ?>
            <div class="errors"><?php echo e(implode(' ', $errors)); ?></div>
        <?php endif; ?>
        <form method="post">
            <input type="hidden" name="action" value="<?php echo $editing ? 'update' : 'create'; ?>">
            <input type="hidden" name="id" value="<?php echo e((string)($editing['id'] ?? '')); ?>">
            <div class="grid">
                <div>
                    <label for="username">Логин</label>
                    <input id="username" name="username" required value="<?php echo e((string)($editing['username'] ?? '')); ?>" autocomplete="username">
                </div>
                <div>
                    <label for="password"><?php echo $editing ? 'Новый пароль' : 'Пароль'; ?></label>
                    <input id="password" type="password" name="password" <?php echo $editing ? '' : 'required'; ?> minlength="6" autocomplete="new-password">
                    <?php if ($editing): ?><div class="hint">Оставьте поле пустым, если пароль менять не нужно.</div><?php endif; ?>
                </div>
                <div>
                    <label for="role">Роль</label>
                    <?php if ($isEditingCurrentUser): ?><input type="hidden" name="role" value="admin"><?php endif; ?>
                    <select id="role" name="role" <?php echo $isEditingCurrentUser ? 'disabled' : ''; ?>>
                        <?php foreach ($allowedRoles as $roleOption): ?>
                            <option value="<?php echo e($roleOption); ?>" <?php echo (string)($editing['role'] ?? 'user') === $roleOption ? 'selected' : ''; ?>>
                                <?php echo $roleOption === 'admin' ? 'Администратор' : 'Пользователь'; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($isEditingCurrentUser): ?><div class="hint">Свою учетную запись администратора нельзя перевести в обычные пользователи.</div><?php endif; ?>
                </div>
            </div>
            <p><label for="note">Примечание</label><textarea id="note" name="note"><?php echo e((string)($editing['note'] ?? '')); ?></textarea></p>
            <p>
                <button type="submit">Сохранить</button>
                <?php if ($editing): ?><a class="button secondary" href="admin_users.php">Отмена</a><?php endif; ?>
            </p>
        </form>
    </section>

    <section class="panel">
        <h2>Список пользователей</h2>
        <table>
            <thead><tr><th>Логин</th><th>Роль</th><th>Примечание</th><th>Действия</th></tr></thead>
            <tbody>
            <?php foreach ($users as $user): ?>
                <tr>
                    <td><?php echo e($user['username']); ?><?php if ((int)$user['id'] === (int)$_SESSION['user_id']): ?> <span class="hint">(вы)</span><?php endif; ?></td>
                    <td><span class="badge <?php echo $user['role'] === 'admin' ? 'admin' : ''; ?>"><?php echo $user['role'] === 'admin' ? 'Администратор' : 'Пользователь'; ?></span></td>
                    <td><?php echo e((string)($user['note'] ?? '')); ?></td>
                    <td class="actions">
                        <a class="button secondary" href="admin_users.php?edit=<?php echo e((string)$user['id']); ?>">Изменить</a>
                        <?php if ((int)$user['id'] !== (int)$_SESSION['user_id']): ?>
                            <form method="post" onsubmit="return confirm('Удалить пользователя?');">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?php echo e((string)$user['id']); ?>">
                                <button class="danger" type="submit">Удалить</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$users): ?>
                <tr><td colspan="4">Пользователи пока не добавлены.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </section>
</main>
</body>
</html>
