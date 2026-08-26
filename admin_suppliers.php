<?php
require_once __DIR__ . '/includes/admin_auth.php';
require_once __DIR__ . '/includes/app_header.php';
require_admin();
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/admin_schema.php';

ensure_suppliers_table($pdo);
ensure_supplier_products_table($pdo);

$activeTab = $_GET['tab'] ?? 'suppliers';
$validTabs = ['suppliers', 'products'];
if (!in_array($activeTab, $validTabs, true)) $activeTab = 'suppliers';

$errors = [];
$editing = null;
$calculators = [
    'septic' => 'Сантехнические кабины',
    'countertops' => 'Столешницы',
    'cutting' => 'Раскрой панелей',
    'subsystem' => 'Подсистема',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ═══ ПОСТАВЩИКИ ═══
    if ($action === 'delete_supplier') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) $pdo->prepare('DELETE FROM suppliers WHERE id=:id')->execute(['id'=>$id]);
        header('Location: admin_suppliers.php?tab=suppliers'); exit;
    }
    if (in_array($action, ['create_supplier', 'update_supplier'])) {
        $id = (int)($_POST['id'] ?? 0);
        $company = trim($_POST['company_name'] ?? '');
        $productId = (int)($_POST['product_id'] ?? 0);
        $address = trim($_POST['address'] ?? '');
        $website = trim($_POST['website'] ?? '');
        $contacts = trim($_POST['contacts'] ?? '');
        $note = trim($_POST['note'] ?? '');
        if ($company === '') $errors[] = 'Укажите название компании.';
        if ($website !== '' && !filter_var($website, FILTER_VALIDATE_URL)) $errors[] = 'Укажите корректный сайт с протоколом http:// или https://.';
        if (!$errors) {
            $productName = null;
            if ($productId > 0) {
                $ps = $pdo->prepare('SELECT name FROM supplier_products WHERE id=:id');
                $ps->execute(['id'=>$productId]);
                $productName = $ps->fetchColumn() ?: null;
            }
            $params = ['company_name'=>$company,'products'=>$productName,'address'=>$address?:null,'website'=>$website?:null,'contacts'=>$contacts?:null,'note'=>$note?:null];
            if ($action === 'update_supplier' && $id > 0) {
                $params['id']=$id;
                $pdo->prepare('UPDATE suppliers SET company_name=:company_name,products=:products,address=:address,website=:website,contacts=:contacts,note=:note WHERE id=:id')->execute($params);
                header('Location: admin_suppliers.php?tab=suppliers&edit='.$id); exit;
            } else {
                $pdo->prepare('INSERT INTO suppliers (company_name,products,address,website,contacts,note) VALUES (:company_name,:products,:address,:website,:contacts,:note)')->execute($params);
                header('Location: admin_suppliers.php?tab=suppliers&edit='.$pdo->lastInsertId()); exit;
            }
        }
    }

    // ═══ ПРОДУКЦИЯ ═══
    if ($action === 'delete_product') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) $pdo->prepare('DELETE FROM supplier_products WHERE id=:id')->execute(['id'=>$id]);
        header('Location: admin_suppliers.php?tab=products'); exit;
    }
    if (in_array($action, ['create_product', 'update_product'])) {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $note = trim($_POST['note'] ?? '');
        $calculatorKeys = array_values(array_intersect(array_keys($calculators), (array)($_POST['calculator_keys'] ?? [])));
        if ($name === '') $errors[] = 'Укажите название продукции.';
        if (!$errors) {
            $dupCheck = $pdo->prepare('SELECT id FROM supplier_products WHERE name=:name AND id!=:id');
            $dupCheck->execute(['name'=>$name,'id'=>$id]);
            if ($dupCheck->fetch()) $errors[] = 'Продукция с таким названием уже существует.';
        }
        if (!$errors) {
            if ($action === 'update_product' && $id > 0) {
                $pdo->prepare('UPDATE supplier_products SET name=:name,note=:note,calculator_keys=:calculator_keys WHERE id=:id')->execute(['name'=>$name,'note'=>$note?:null,'calculator_keys'=>implode(',', $calculatorKeys),'id'=>$id]);
                header('Location: admin_suppliers.php?tab=products&edit='.$id); exit;
            } else {
                $pdo->prepare('INSERT INTO supplier_products (name,note,calculator_keys) VALUES (:name,:note,:calculator_keys)')->execute(['name'=>$name,'note'=>$note?:null,'calculator_keys'=>implode(',', $calculatorKeys)]);
                header('Location: admin_suppliers.php?tab=products&edit='.$pdo->lastInsertId()); exit;
            }
        }
    }
}

// ═══ DATA ═══
$supplierProducts = $pdo->query('SELECT * FROM supplier_products ORDER BY name ASC')->fetchAll();
$suppliers = $pdo->query('SELECT * FROM suppliers ORDER BY company_name ASC')->fetchAll();

// EDITING
if ($activeTab === 'suppliers') { $editId=(int)($_GET['edit']??0); if($editId>0){$stmt=$pdo->prepare('SELECT * FROM suppliers WHERE id=:id');$stmt->execute(['id'=>$editId]);$editing=$stmt->fetch()?:null;} }
if ($activeTab === 'products') { $editId=(int)($_GET['edit']??0); if($editId>0){$stmt=$pdo->prepare('SELECT * FROM supplier_products WHERE id=:id');$stmt->execute(['id'=>$editId]);$editing=$stmt->fetch()?:null;} }

// Resolve current product_id for editing supplier
$editingProductId = 0;
if ($editing && $activeTab === 'suppliers' && !empty($editing['products'])) {
    $ps = $pdo->prepare('SELECT id FROM supplier_products WHERE name=:name LIMIT 1');
    $ps->execute(['name'=>$editing['products']]);
    $editingProductId = (int)($ps->fetchColumn() ?: 0);
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<title>Поставщики</title>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;900&display=swap">
<style>
body{font-family:'Inter',Arial,sans-serif;background:linear-gradient(135deg,#eef7ff 0%,#f8fafc 42%,#f3f7f1 100%);margin:0;color:#1f2937}
.header{display:flex;align-items:center;gap:16px;flex-wrap:wrap;background:radial-gradient(circle at 10% 10%,rgba(59,130,246,.35),transparent 32%),linear-gradient(120deg,#0f172a,#1e3a8a 58%,#0f766e);color:#fff;padding:22px 36px 30px;box-shadow:0 18px 45px rgba(15,23,42,.18)}
.header a{color:#dbeafe;font-weight:700;text-decoration:none;margin-right:16px}
.header h1{margin:0;font-size:clamp(24px,3.5vw,36px);letter-spacing:-.02em;font-weight:900}
.container{max-width:1280px;margin:28px auto;padding:0 20px}
.panel{background:rgba(255,255,255,.92);border:1px solid rgba(203,213,225,.9);border-radius:18px;padding:22px;margin-bottom:24px;box-shadow:0 18px 50px rgba(15,23,42,.06);backdrop-filter:blur(12px)}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px}
label{display:block;font-weight:700;margin-bottom:6px;font-size:13px;color:#334155}
input,select,textarea{width:100%;box-sizing:border-box;padding:11px 12px;border:1px solid #cbd5e1;border-radius:12px;font-size:14px;background:#fff}
input:focus,select:focus,textarea:focus{outline:0;border-color:#60a5fa;box-shadow:0 0 0 4px rgba(96,165,250,.18)}
textarea{min-height:76px}
button,.button{border:0;border-radius:12px;padding:11px 16px;background:linear-gradient(135deg,#2563eb,#1d4ed8);color:#fff;text-decoration:none;cursor:pointer;display:inline-block;font-weight:800;box-shadow:0 10px 22px rgba(37,99,235,.22)}
.button.secondary,button.secondary{background:linear-gradient(135deg,#64748b,#475569);box-shadow:none}
button.danger{background:linear-gradient(135deg,#ef4444,#b91c1c)}
table{width:100%;border-collapse:collapse;background:#fff;border-radius:14px;overflow:hidden}
th,td{padding:12px;border-bottom:1px solid #e5e7eb;text-align:left;vertical-align:top}
th{background:#edf6ff;color:#0f172a;font-size:12px;text-transform:uppercase;letter-spacing:.04em}
.errors{background:#fee2e2;color:#991b1b;padding:12px;border-radius:12px;margin-bottom:14px}
.actions{display:flex;gap:8px;flex-wrap:wrap}
.hint{color:#64748b;font-size:13px;margin-top:4px}
.quick-links{display:flex;gap:10px;margin-bottom:24px;flex-wrap:wrap}
.quick-link{padding:10px 18px;border-radius:12px;background:#fff;border:1px solid #e5e7eb;color:#374151;font-weight:700;font-size:14px;text-decoration:none;transition:all .15s;box-shadow:0 2px 6px rgba(15,23,42,.04)}
.quick-link:hover{background:#eff6ff;border-color:#93c5fd;color:#2563eb}
.quick-link.active{background:#2563eb;border-color:#2563eb;color:#fff}
.tab-pane{display:none}.tab-pane.active{display:block}
.calculator-options{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:9px;margin-top:8px}
.calculator-option{display:flex;align-items:center;gap:9px;padding:10px 12px;border:1px solid #cbd5e1;border-radius:12px;background:#f8fafc;font-weight:600}
.calculator-option input{width:auto;margin:0}
</style>
<?php echo app_header_styles(); ?>
</head>
<body>
<?php render_app_header(); ?>
<main class="container">
    <div class="quick-links">
        <a class="quick-link <?php echo $activeTab==='suppliers'?'active':'';?>" href="admin_suppliers.php?tab=suppliers">🏭 Поставщики</a>
        <a class="quick-link <?php echo $activeTab==='products'?'active':'';?>" href="admin_suppliers.php?tab=products">📦 Продукция</a>
    </div>

<?php if ($errors): ?><div class="errors"><?php echo e(implode(' ', $errors)); ?></div><?php endif; ?>

<!-- ═══ ПОСТАВЩИКИ ═══ -->
<div class="tab-pane <?php echo $activeTab==='suppliers'?'active':''; ?>">
    <section class="panel">
        <h2><?php echo $editing ? 'Редактировать поставщика' : 'Добавить поставщика'; ?></h2>
        <form method="post">
            <input type="hidden" name="action" value="<?php echo $editing ? 'update_supplier' : 'create_supplier'; ?>">
            <input type="hidden" name="id" value="<?php echo e((string)($editing['id'] ?? '')); ?>">
            <div class="grid">
                <div><label>Название компании</label><input name="company_name" required value="<?php echo e((string)($editing['company_name'] ?? '')); ?>"></div>
                <div><label>Сайт</label><input name="website" value="<?php echo e((string)($editing['website'] ?? '')); ?>" placeholder="https://example.com"></div>
                <div><label>Продукция</label><select name="product_id"><option value="0">— Не выбрана —</option><?php foreach ($supplierProducts as $sp): ?><option value="<?php echo e((string)$sp['id']); ?>" <?php echo $editingProductId===(int)$sp['id']?'selected':''; ?>><?php echo e($sp['name']); ?></option><?php endforeach; ?></select><div class="hint">Выберите продукцию из справочника.</div></div>
            </div>
            <p><label>Адрес</label><textarea name="address"><?php echo e((string)($editing['address'] ?? '')); ?></textarea></p>
            <p><label>Контакты</label><textarea name="contacts"><?php echo e((string)($editing['contacts'] ?? '')); ?></textarea></p>
            <p><label>Примечание</label><textarea name="note"><?php echo e((string)($editing['note'] ?? '')); ?></textarea></p>
            <button type="submit">Сохранить</button>
            <?php if ($editing): ?><a class="button secondary" href="admin_suppliers.php?tab=suppliers">Отмена</a><?php endif; ?>
        </form>
    </section>
    <section class="panel">
        <h2>Список поставщиков</h2>
        <table>
            <thead><tr><th>Компания</th><th>Продукция</th><th>Сайт</th><th>Адрес</th><th>Контакты</th><th>Действия</th></tr></thead>
            <tbody>
            <?php foreach ($suppliers as $sup): ?>
                <tr>
                    <td><?php echo e($sup['company_name']); ?></td>
                    <td><?php echo e((string)($sup['products'] ?? '—')); ?></td>
                    <td><?php if(!empty($sup['website'])): ?><a href="<?php echo e($sup['website']); ?>" target="_blank" style="color:#2563eb"><?php echo e($sup['website']); ?></a><?php else: ?>—<?php endif; ?></td>
                    <td style="white-space:pre-line"><?php echo e((string)($sup['address'] ?? '')); ?></td>
                    <td style="white-space:pre-line"><?php echo e((string)($sup['contacts'] ?? '')); ?></td>
                    <td class="actions">
                        <a class="button secondary" href="admin_suppliers.php?tab=suppliers&edit=<?php echo e((string)$sup['id']); ?>">Изменить</a>
                        <form method="post" onsubmit="return confirm('Удалить поставщика?');" style="display:inline"><input type="hidden" name="action" value="delete_supplier"><input type="hidden" name="id" value="<?php echo e((string)$sup['id']); ?>"><button class="danger" type="submit">Удалить</button></form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$suppliers): ?><tr><td colspan="6">Поставщики пока не добавлены.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </section>
</div>

<!-- ═══ ПРОДУКЦИЯ ═══ -->
<div class="tab-pane <?php echo $activeTab==='products'?'active':''; ?>">
    <section class="panel">
        <h2><?php echo $editing ? 'Редактировать продукцию' : 'Добавить продукцию'; ?></h2>
        <form method="post">
            <input type="hidden" name="action" value="<?php echo $editing ? 'update_product' : 'create_product'; ?>">
            <input type="hidden" name="id" value="<?php echo e((string)($editing['id'] ?? '')); ?>">
            <div class="grid">
                <div><label>Название продукции</label><input name="name" required value="<?php echo e((string)($editing['name'] ?? '')); ?>"></div>
                <div><label>Примечание</label><textarea name="note"><?php echo e((string)($editing['note'] ?? '')); ?></textarea></div>
            </div>
            <?php $selectedCalculators = array_filter(explode(',', (string)($editing['calculator_keys'] ?? ''))); ?>
            <div style="margin-top:14px">
                <label>Связь с калькуляторами</label>
                <div class="calculator-options">
                    <?php foreach ($calculators as $key => $title): ?>
                        <label class="calculator-option"><input type="checkbox" name="calculator_keys[]" value="<?php echo e($key); ?>" <?php echo in_array($key, $selectedCalculators, true) ? 'checked' : ''; ?>> <?php echo e($title); ?></label>
                    <?php endforeach; ?>
                </div>
                <div class="hint">Поставщик этой продукции будет доступен только в отмеченных калькуляторах.</div>
            </div>
            <p style="margin-top:14px"><button type="submit">Сохранить</button><?php if ($editing): ?><a class="button secondary" href="admin_suppliers.php?tab=products">Отмена</a><?php endif; ?></p>
        </form>
    </section>
    <section class="panel">
        <h2>Список продукции</h2>
        <table>
            <thead><tr><th>Название</th><th>Калькуляторы</th><th>Примечание</th><th>Действия</th></tr></thead>
            <tbody>
            <?php foreach ($supplierProducts as $sp): ?>
                <tr>
                    <td><?php echo e($sp['name']); ?></td>
                    <td><?php $keys=array_filter(explode(',', (string)($sp['calculator_keys'] ?? ''))); echo $keys ? e(implode(', ', array_intersect_key($calculators, array_flip($keys)))) : '—'; ?></td>
                    <td><?php echo e((string)($sp['note'] ?? '')); ?></td>
                    <td class="actions">
                        <a class="button secondary" href="admin_suppliers.php?tab=products&edit=<?php echo e((string)$sp['id']); ?>">Изменить</a>
                        <form method="post" onsubmit="return confirm('Удалить продукцию?');" style="display:inline"><input type="hidden" name="action" value="delete_product"><input type="hidden" name="id" value="<?php echo e((string)$sp['id']); ?>"><button class="danger" type="submit">Удалить</button></form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$supplierProducts): ?><tr><td colspan="4">Продукция пока не добавлена.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </section>
</div>

</main>
</body>
</html>
