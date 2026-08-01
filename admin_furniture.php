<?php
require_once __DIR__ . '/includes/admin_auth.php';
require_once __DIR__ . '/includes/app_header.php';
require_admin();
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/admin_schema.php';

ensure_price_list_table($pdo);
ensure_furniture_categories_table($pdo);
ensure_furniture_kits_table($pdo);
ensure_furniture_collections_table($pdo);
ensure_partition_type_kits_table($pdo);

$activeTab = $_GET['tab'] ?? 'furniture';
$validTabs = ['furniture', 'categories', 'kits', 'bindings', 'collections'];
if (!in_array($activeTab, $validTabs, true)) $activeTab = 'furniture';

$errors = [];
$editing = null;
$editingItems = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ═══ ФУРНИТУРА ═══
    if ($action === 'delete_furniture') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $pdo->prepare('DELETE FROM price_list WHERE id = :id');
            $stmt->execute(['id' => $id]);
        }
        header('Location: admin_furniture.php?tab=furniture');
        exit;
    }
    if (in_array($action, ['create_furniture', 'update_furniture'])) {
        $id = (int)($_POST['id'] ?? 0);
        $currentPhotoPath = null;
        if ($id > 0) {
            $stmt = $pdo->prepare('SELECT photo_path FROM price_list WHERE id = :id');
            $stmt->execute(['id' => $id]);
            $currentPhotoPath = $stmt->fetchColumn() ?: null;
        }
        $supplierId = (int)($_POST['supplier_id'] ?? 0);
        $categoryId = (int)($_POST['category_id'] ?? 0);
        $collectionId = (int)($_POST['collection_id'] ?? 0);
        $materialName = trim($_POST['material_name'] ?? '');
        $unit = trim($_POST['unit'] ?? 'шт.');
        $multiplicityRaw = trim($_POST['multiplicity'] ?? '1');
        $amountRaw = trim($_POST['amount'] ?? '1');
        $priceRaw = trim($_POST['price'] ?? '');
        $currency = strtoupper(trim($_POST['currency'] ?? 'RUB'));
        $note = trim($_POST['note'] ?? '');
        $isStockProgram = isset($_POST['is_stock_program']) ? 1 : 0;
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        if ($materialName === '') $errors[] = 'Укажите наименование фурнитуры.';
        if ($unit === '') $errors[] = 'Выберите единицу измерения.';
        if ($multiplicityRaw === '' || !is_numeric($multiplicityRaw) || (float)$multiplicityRaw <= 0) $errors[] = 'Кратность должна быть числом больше нуля.';
        if ($amountRaw === '' || !is_numeric($amountRaw) || (float)$amountRaw <= 0) $errors[] = 'Сумма должна быть числом больше нуля.';
        if ($priceRaw === '' || !is_numeric($priceRaw) || (float)$priceRaw < 0) $errors[] = 'Цена за ед. изм. должна быть неотрицательным числом.';
        if ($currency === '') $errors[] = 'Выберите валюту.';
        $photoPath = upload_image('photo', 'furniture', $errors, $currentPhotoPath);
        if (!$errors) {
            $params = [
                'supplier_id' => $supplierId > 0 ? $supplierId : null,
                'category_id' => $categoryId > 0 ? $categoryId : null,
                'collection_id' => $collectionId > 0 ? $collectionId : null,
                'material_name' => $materialName, 'unit' => $unit,
                'multiplicity' => (float)$multiplicityRaw, 'amount' => (float)$amountRaw,
                'price' => (float)$priceRaw, 'currency' => $currency,
                'photo_path' => $photoPath, 'is_stock_program' => $isStockProgram,
                'note' => $note === '' ? null : $note, 'is_active' => $isActive,
            ];
            if ($action === 'update_furniture' && $id > 0) {
                $params['id'] = $id;
                $stmt = $pdo->prepare('UPDATE price_list SET supplier_id=:supplier_id, category_id=:category_id, collection_id=:collection_id, material_name=:material_name, unit=:unit, multiplicity=:multiplicity, amount=:amount, price=:price, currency=:currency, photo_path=:photo_path, is_stock_program=:is_stock_program, note=:note, is_active=:is_active WHERE id=:id');
                $stmt->execute($params);
                header('Location: admin_furniture.php?tab=furniture&edit=' . $id);
                exit;
            } else {
                $stmt = $pdo->prepare('INSERT INTO price_list (supplier_id, category_id, collection_id, material_name, unit, multiplicity, amount, price, currency, photo_path, is_stock_program, note, is_active) VALUES (:supplier_id, :category_id, :collection_id, :material_name, :unit, :multiplicity, :amount, :price, :currency, :photo_path, :is_stock_program, :note, :is_active)');
                $stmt->execute($params);
                header('Location: admin_furniture.php?tab=furniture&edit=' . $pdo->lastInsertId());
                exit;
            }
        }
    }

    // ═══ КАТЕГОРИИ ═══
    if ($action === 'delete_category') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) { $stmt = $pdo->prepare('DELETE FROM furniture_categories WHERE id = :id'); $stmt->execute(['id' => $id]); }
        header('Location: admin_furniture.php?tab=categories');
        exit;
    }
    if (in_array($action, ['create_category', 'update_category'])) {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $note = trim($_POST['note'] ?? '');
        if ($name === '') $errors[] = 'Укажите название категории.';
        if (!$errors) {
            $dupCheck = $pdo->prepare('SELECT id FROM furniture_categories WHERE name = :name AND id != :id');
            $dupCheck->execute(['name' => $name, 'id' => $id]);
            if ($dupCheck->fetch()) $errors[] = 'Категория с таким названием уже существует.';
        }
        if (!$errors) {
            if ($action === 'update_category' && $id > 0) {
                $stmt = $pdo->prepare('UPDATE furniture_categories SET name=:name, note=:note WHERE id=:id');
                $stmt->execute(['name' => $name, 'note' => $note === '' ? null : $note, 'id' => $id]);
                header('Location: admin_furniture.php?tab=categories&edit=' . $id);
                exit;
            } else {
                $stmt = $pdo->prepare('INSERT INTO furniture_categories (name, note) VALUES (:name, :note)');
                $stmt->execute(['name' => $name, 'note' => $note === '' ? null : $note]);
                header('Location: admin_furniture.php?tab=categories&edit=' . $pdo->lastInsertId());
                exit;
            }
        }
    }

    // ═══ КОМПЛЕКТЫ ═══
    if ($action === 'delete_kit') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) { $stmt = $pdo->prepare('DELETE FROM furniture_kits WHERE id=:id'); $stmt->execute(['id' => $id]); }
        header('Location: admin_furniture.php?tab=kits');
        exit;
    }
    if ($action === 'bind_kit') {
        $typeId = (int)($_POST['partition_type_id'] ?? 0); $kitId = (int)($_POST['kit_id'] ?? 0);
        if ($typeId > 0 && $kitId > 0) { $stmt = $pdo->prepare('INSERT IGNORE INTO partition_type_kits (partition_type_id,kit_id) VALUES (:tid,:kid)'); $stmt->execute(['tid' => $typeId, 'kid' => $kitId]); }
        header('Location: admin_furniture.php?tab=bindings');
        exit;
    }
    if ($action === 'unbind_kit') {
        $bindingId = (int)($_POST['id'] ?? 0);
        if ($bindingId > 0) { $stmt = $pdo->prepare('DELETE FROM partition_type_kits WHERE id=:id'); $stmt->execute(['id' => $bindingId]); }
        header('Location: admin_furniture.php?tab=bindings');
        exit;
    }
    if (in_array($action, ['create_kit', 'update_kit'])) {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $note = trim($_POST['note'] ?? '');
        $collectionId = (int)($_POST['collection_id'] ?? 0);
        $furnitureIds = $_POST['furniture_id'] ?? [];
        $quantities = $_POST['quantity'] ?? [];
        if ($name === '') $errors[] = 'Укажите название комплекта.';
        $items = [];
        foreach ($furnitureIds as $index => $furnitureIdRaw) {
            $furnitureId = (int)$furnitureIdRaw;
            $quantityRaw = trim((string)($quantities[$index] ?? '1'));
            if ($furnitureId <= 0) continue;
            if ($quantityRaw === '' || !is_numeric($quantityRaw) || (float)$quantityRaw <= 0) { $errors[] = 'Количество в комплекте должно быть больше нуля.'; break; }
            $items[] = ['furniture_id' => $furnitureId, 'quantity' => (float)$quantityRaw];
        }
        if (!$items) $errors[] = 'Добавьте хотя бы одну позицию фурнитуры в комплект.';
        if (!$errors && $name !== '') {
            $dupCheck = $pdo->prepare('SELECT id FROM furniture_kits WHERE name = :name AND id != :id');
            $dupCheck->execute(['name' => $name, 'id' => $id]);
            if ($dupCheck->fetch()) $errors[] = 'Комплект с таким названием уже существует.';
        }
        if (!$errors) {
            if ($action === 'update_kit' && $id > 0) {
                $stmt = $pdo->prepare('UPDATE furniture_kits SET name=:name,collection_id=:collection_id,note=:note WHERE id=:id');
                $stmt->execute(['name' => $name, 'collection_id' => $collectionId > 0 ? $collectionId : null, 'note' => $note === '' ? null : $note, 'id' => $id]);
                $kitId = $id;
                $stmt = $pdo->prepare('DELETE FROM furniture_kit_items WHERE kit_id=:kit_id');
                $stmt->execute(['kit_id' => $kitId]);
            } else {
                $stmt = $pdo->prepare('INSERT INTO furniture_kits (name,collection_id,note) VALUES (:name,:collection_id,:note)');
                $stmt->execute(['name' => $name, 'collection_id' => $collectionId > 0 ? $collectionId : null, 'note' => $note === '' ? null : $note]);
                $kitId = (int)$pdo->lastInsertId();
            }
            $stmt = $pdo->prepare('INSERT INTO furniture_kit_items (kit_id,furniture_id,quantity) VALUES (:kit_id,:furniture_id,:quantity)');
            foreach ($items as $item) { $stmt->execute(['kit_id' => $kitId, 'furniture_id' => $item['furniture_id'], 'quantity' => $item['quantity']]); }
            header('Location: admin_furniture.php?tab=kits&edit=' . $kitId);
            exit;
        }
    }

    // ═══ КОЛЛЕКЦИИ ═══
    if ($action === 'delete_collection') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) { $stmt = $pdo->prepare('DELETE FROM furniture_collections WHERE id = :id'); $stmt->execute(['id' => $id]); }
        header('Location: admin_furniture.php?tab=collections');
        exit;
    }
    if (in_array($action, ['create_collection', 'update_collection'])) {
        $id = (int)($_POST['id'] ?? 0);
        $supplierId = (int)($_POST['supplier_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        if ($name === '') $errors[] = 'Укажите название коллекции.';
        if (!$errors) {
            $dupCheck = $pdo->prepare('SELECT id FROM furniture_collections WHERE supplier_id <=> :sid AND name = :name AND id != :id');
            $dupCheck->execute(['sid' => $supplierId > 0 ? $supplierId : null, 'name' => $name, 'id' => $id]);
            if ($dupCheck->fetch()) $errors[] = 'Коллекция с таким поставщиком и названием уже существует.';
        }
        if (!$errors) {
            if ($action === 'update_collection' && $id > 0) {
                $stmt = $pdo->prepare('UPDATE furniture_collections SET supplier_id=:supplier_id, name=:name WHERE id=:id');
                $stmt->execute(['supplier_id' => $supplierId > 0 ? $supplierId : null, 'name' => $name, 'id' => $id]);
                header('Location: admin_furniture.php?tab=collections&edit=' . $id);
                exit;
            } else {
                $stmt = $pdo->prepare('INSERT INTO furniture_collections (supplier_id, name) VALUES (:supplier_id, :name)');
                $stmt->execute(['supplier_id' => $supplierId > 0 ? $supplierId : null, 'name' => $name]);
                header('Location: admin_furniture.php?tab=collections&edit=' . $pdo->lastInsertId());
                exit;
            }
        }
    }
}

// ═══ DATA LOADING ═══
$suppliers = $pdo->query('SELECT * FROM suppliers ORDER BY company_name ASC')->fetchAll();
$categories = $pdo->query('SELECT * FROM furniture_categories ORDER BY name ASC')->fetchAll();
$collections = $pdo->query('SELECT fc.*, s.company_name AS supplier_name FROM furniture_collections fc LEFT JOIN suppliers s ON s.id = fc.supplier_id ORDER BY s.company_name ASC, fc.name ASC')->fetchAll();
$units = $pdo->query('SELECT * FROM measurement_units WHERE is_active = 1 ORDER BY short_name ASC')->fetchAll();
$currencies = $pdo->query("SELECT * FROM currencies WHERE is_active = 1 ORDER BY code = 'RUB' DESC, code ASC")->fetchAll();
$prices = $pdo->query('SELECT pl.*, s.company_name AS supplier_name, fc.name AS category_name, fcol.name AS collection_name FROM price_list pl LEFT JOIN suppliers s ON s.id = pl.supplier_id LEFT JOIN furniture_categories fc ON fc.id = pl.category_id LEFT JOIN furniture_collections fcol ON fcol.id = pl.collection_id ORDER BY pl.is_active DESC, pl.material_name ASC, s.company_name ASC')->fetchAll();
$furniture = $pdo->query('SELECT pl.*, fc.name AS category_name FROM price_list pl LEFT JOIN furniture_categories fc ON fc.id = pl.category_id WHERE pl.is_active = 1 ORDER BY fc.name ASC, pl.material_name ASC')->fetchAll();
$kits = $pdo->query('SELECT fk.*, COUNT(fki.id) AS items_count, fcol.name AS collection_name, s.company_name AS supplier_name FROM furniture_kits fk LEFT JOIN furniture_kit_items fki ON fki.kit_id=fk.id LEFT JOIN furniture_collections fcol ON fcol.id=fk.collection_id LEFT JOIN suppliers s ON s.id=fcol.supplier_id GROUP BY fk.id ORDER BY fk.name ASC')->fetchAll();
$kitDetails = [];
if ($kits) {
    $rows = $pdo->query('SELECT fki.*, pl.material_name, pl.unit, pl.price, pl.currency, fc.name AS category_name FROM furniture_kit_items fki JOIN price_list pl ON pl.id=fki.furniture_id LEFT JOIN furniture_categories fc ON fc.id=pl.category_id ORDER BY fki.id ASC')->fetchAll();
    foreach ($rows as $row) $kitDetails[(int)$row['kit_id']][] = $row;
}
$partitionTypes = $pdo->query('SELECT * FROM partition_types WHERE is_active=1 ORDER BY name ASC')->fetchAll();
$bindings = $pdo->query('SELECT ptk.id AS binding_id, pt.name AS type_name, fk.name AS kit_name, ptk.partition_type_id, ptk.kit_id FROM partition_type_kits ptk JOIN partition_types pt ON pt.id=ptk.partition_type_id JOIN furniture_kits fk ON fk.id=ptk.kit_id ORDER BY pt.name ASC, fk.name ASC')->fetchAll();

// EDITING STATE
if ($activeTab === 'furniture') {
    $editId = (int)($_GET['edit'] ?? 0);
    if ($editId > 0) { $stmt = $pdo->prepare('SELECT * FROM price_list WHERE id = :id'); $stmt->execute(['id' => $editId]); $editing = $stmt->fetch() ?: null; }
}
if ($activeTab === 'categories') {
    $editId = (int)($_GET['edit'] ?? 0);
    if ($editId > 0) { $stmt = $pdo->prepare('SELECT * FROM furniture_categories WHERE id = :id'); $stmt->execute(['id' => $editId]); $editing = $stmt->fetch() ?: null; }
}
if ($activeTab === 'kits') {
    $editId = (int)($_GET['edit'] ?? 0);
    if ($editId > 0) {
        $stmt = $pdo->prepare('SELECT * FROM furniture_kits WHERE id=:id'); $stmt->execute(['id' => $editId]); $editing = $stmt->fetch() ?: null;
        if ($editing) { $stmt = $pdo->prepare('SELECT * FROM furniture_kit_items WHERE kit_id=:kit_id ORDER BY id ASC'); $stmt->execute(['kit_id' => $editId]); $editingItems = $stmt->fetchAll(); }
    }
}
if ($activeTab === 'collections') {
    $editId = (int)($_GET['edit'] ?? 0);
    if ($editId > 0) { $stmt = $pdo->prepare('SELECT * FROM furniture_collections WHERE id = :id'); $stmt->execute(['id' => $editId]); $editing = $stmt->fetch() ?: null; }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<title>Фурнитура</title>
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
input[type="checkbox"]{width:auto}
textarea{min-height:76px}
button,.button{border:0;border-radius:12px;padding:11px 16px;background:linear-gradient(135deg,#2563eb,#1d4ed8);color:#fff;text-decoration:none;cursor:pointer;display:inline-block;font-weight:800;box-shadow:0 10px 22px rgba(37,99,235,.22)}
.button.secondary,button.secondary{background:linear-gradient(135deg,#64748b,#475569);box-shadow:none}
button.danger{background:linear-gradient(135deg,#ef4444,#b91c1c)}
table{width:100%;border-collapse:collapse;background:#fff;border-radius:14px;overflow:hidden}
th,td{padding:12px;border-bottom:1px solid #e5e7eb;text-align:left;vertical-align:top}
th{background:#edf6ff;color:#0f172a;font-size:12px;text-transform:uppercase;letter-spacing:.04em}
.errors{background:#fee2e2;color:#991b1b;padding:12px;border-radius:12px}
.actions{display:flex;gap:8px;flex-wrap:wrap}
.status,.price{font-weight:700}
.preview{max-width:96px;max-height:64px;border-radius:8px;border:1px solid #e5e7eb;object-fit:cover}
.hint{color:#64748b;font-size:13px;margin-top:4px}
.muted{color:#64748b}
.quick-links{display:flex;gap:10px;margin-bottom:24px;flex-wrap:wrap}
.quick-link{padding:10px 18px;border-radius:12px;background:#fff;border:1px solid #e5e7eb;color:#374151;font-weight:700;font-size:14px;text-decoration:none;transition:all .15s;box-shadow:0 2px 6px rgba(15,23,42,.04)}
.quick-link:hover{background:#eff6ff;border-color:#93c5fd;color:#2563eb}
.quick-link.active{background:#2563eb;border-color:#2563eb;color:#fff}
.kit-row{display:grid;grid-template-columns:minmax(220px,1fr) 120px auto;gap:10px;align-items:end;margin-bottom:10px}
.tab-pane{display:none}
.tab-pane.active{display:block}
</style>
<?php echo app_header_styles(); ?>
</head>
<body>
<?php render_app_header(); ?>
<main class="container">
    <div class="quick-links">
        <a class="quick-link <?php echo $activeTab==='furniture'?'active':'';?>" href="admin_furniture.php?tab=furniture">🔩 Фурнитура</a>
        <a class="quick-link <?php echo $activeTab==='categories'?'active':'';?>" href="admin_furniture.php?tab=categories">🗂️ Категории</a>
        <a class="quick-link <?php echo $activeTab==='kits'?'active':'';?>" href="admin_furniture.php?tab=kits">📦 Комплекты</a>
        <a class="quick-link <?php echo $activeTab==='bindings'?'active':'';?>" href="admin_furniture.php?tab=bindings">🔗 Комплектация перегородок</a>
        <a class="quick-link <?php echo $activeTab==='collections'?'active':'';?>" href="admin_furniture.php?tab=collections">🏷️ Коллекции</a>
    </div>

<?php if ($errors): ?><div class="errors"><?php echo e(implode(' ', $errors)); ?></div><?php endif; ?>

<!-- ════════════ ВКЛАДКА: ФУРНИТУРА ════════════ -->
<div class="tab-pane <?php echo $activeTab==='furniture'?'active':''; ?>" id="tab-furniture">
    <section class="panel">
        <h2><?php echo $editing ? 'Редактировать позицию' : 'Добавить позицию'; ?></h2>
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="action" value="<?php echo $editing ? 'update_furniture' : 'create_furniture'; ?>">
            <input type="hidden" name="id" value="<?php echo e((string)($editing['id'] ?? '')); ?>">
            <div class="grid">
                <div><label>Название</label><input name="material_name" required value="<?php echo e((string)($editing['material_name'] ?? '')); ?>"></div>
                <div><label>Категория</label><select name="category_id"><option value="0">Без категории</option><?php foreach ($categories as $c): ?><option value="<?php echo e((string)$c['id']); ?>" <?php echo (int)($editing['category_id'] ?? 0)===(int)$c['id']?'selected':''; ?>><?php echo e($c['name']); ?></option><?php endforeach; ?></select></div>
                <div><label>Поставщик</label><select name="supplier_id"><option value="0">Поставщик не выбран</option><?php foreach ($suppliers as $s): ?><option value="<?php echo e((string)$s['id']); ?>" <?php echo (int)($editing['supplier_id'] ?? 0)===(int)$s['id']?'selected':''; ?>><?php echo e($s['company_name']); ?></option><?php endforeach; ?></select></div>
                <div><label>Серия</label><select name="collection_id"><option value="0">Без серии</option><?php foreach ($collections as $col): ?><option value="<?php echo e((string)$col['id']); ?>" <?php echo (int)($editing['collection_id'] ?? 0)===(int)$col['id']?'selected':''; ?>><?php echo e(($col['supplier_name']?$col['supplier_name'].' — ':'').$col['name']); ?></option><?php endforeach; ?></select></div>
                <div><label>Ед. изм.</label><select name="unit" required><?php foreach ($units as $u): ?><option value="<?php echo e($u['short_name']); ?>" <?php echo (string)($editing['unit']??'шт.')===$u['short_name']?'selected':''; ?>><?php echo e($u['short_name']); ?> — <?php echo e($u['full_name']); ?></option><?php endforeach; ?></select></div>
                <div><label>Кратность</label><input type="number" step="0.001" min="0.001" name="multiplicity" required value="<?php echo e((string)($editing['multiplicity'] ?? '1')); ?>"></div>
                <div><label>Сумма</label><input type="number" step="0.001" min="0.001" name="amount" required value="<?php echo e((string)($editing['amount'] ?? '1')); ?>"></div>
                <div><label>Цена за ед. изм.</label><input type="number" step="0.01" min="0" name="price" required value="<?php echo e((string)($editing['price'] ?? '')); ?>"></div>
                <div><label>Валюта</label><select name="currency"><?php foreach ($currencies as $cr): ?><option value="<?php echo e($cr['code']); ?>" data-rate="<?php echo e((string)$cr['rate_to_rub']); ?>" <?php echo (string)($editing['currency']??'RUB')===$cr['code']?'selected':''; ?>><?php echo e($cr['code']); ?> — <?php echo e($cr['name']); ?></option><?php endforeach; ?></select></div>
                <div><label>Фото</label><?php if (!empty($editing['photo_path'])): ?><p><img class="preview" src="<?php echo e($editing['photo_path']); ?>" alt=""></p><?php endif; ?><input type="file" name="photo" accept=".jpg,.jpeg,.png,.tif,.tiff,.webp"><p><img class="preview" id="photo_preview" src="<?php echo e((string)($editing['photo_path'] ?? '')); ?>" alt="" style="<?php echo empty($editing['photo_path'])?'display:none;':'' ?>"></p></div>
            </div>
            <p><label><input type="checkbox" name="is_stock_program" <?php echo !empty($editing['is_stock_program'])?'checked':''; ?>> Складская программа</label></p>
            <p><label>Примечание</label><textarea name="note"><?php echo e((string)($editing['note'] ?? '')); ?></textarea></p>
            <p><label><input type="checkbox" name="is_active" <?php echo !isset($editing['is_active'])||(int)$editing['is_active']===1?'checked':''; ?>> Активна</label></p>
            <button type="submit">Сохранить</button>
            <?php if ($editing): ?><a class="button secondary" href="admin_furniture.php?tab=furniture">Отмена</a><?php endif; ?>
        </form>
    </section>
    <section class="panel">
        <h2>Позиции фурнитуры</h2>
        <table>
            <thead><tr><th>Фото</th><th>Название</th><th>Категория</th><th>Поставщик</th><th>Серия</th><th>Ед.</th><th>Кратн.</th><th>Сумма</th><th>Цена</th><th>Скл.</th><th>Статус</th><th>Действия</th></tr></thead>
            <tbody>
            <?php foreach ($prices as $p): ?>
                <tr>
                    <td><?php if (!empty($p['photo_path'])): ?><img class="preview" src="<?php echo e($p['photo_path']); ?>" alt=""><?php else: ?>—<?php endif; ?></td>
                    <td><?php echo e($p['material_name']); ?></td>
                    <td><?php echo e((string)($p['category_name'] ?? '—')); ?></td>
                    <td><?php echo e((string)($p['supplier_name'] ?? '—')); ?></td>
                    <td><?php echo e((string)($p['collection_name'] ?? '—')); ?></td>
                    <td><?php echo e($p['unit']); ?></td>
                    <td><?php echo e(number_format((float)$p['multiplicity'],3,',',' ')); ?></td>
                    <td><?php echo e(number_format((float)$p['amount'],3,',',' ')); ?></td>
                    <td class="price"><?php echo e(number_format((float)$p['price'],2,',',' ')); ?> <?php echo e($p['currency']); ?></td>
                    <td><?php echo (int)$p['is_stock_program']===1?'Да':'Нет'; ?></td>
                    <td class="status"><?php echo (int)$p['is_active']===1?'Акт.':'Скрыт'; ?></td>
                    <td class="actions"><a class="button secondary" href="admin_furniture.php?tab=furniture&edit=<?php echo e((string)$p['id']); ?>">Изм.</a><form method="post" onsubmit="return confirm('Удалить?');" style="display:inline"><input type="hidden" name="action" value="delete_furniture"><input type="hidden" name="id" value="<?php echo e((string)$p['id']); ?>"><button class="danger" type="submit" style="padding:8px 12px;font-size:12px">Удал.</button></form></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$prices): ?><tr><td colspan="12">Позиции фурнитуры пока не добавлены.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </section>
</div>

<!-- ════════════ ВКЛАДКА: КАТЕГОРИИ ════════════ -->
<div class="tab-pane <?php echo $activeTab==='categories'?'active':''; ?>" id="tab-categories">
    <section class="panel">
        <h2><?php echo $editing ? 'Редактировать категорию' : 'Добавить категорию'; ?></h2>
        <form method="post">
            <input type="hidden" name="action" value="<?php echo $editing ? 'update_category' : 'create_category'; ?>">
            <input type="hidden" name="id" value="<?php echo e((string)($editing['id'] ?? '')); ?>">
            <div class="grid">
                <div><label>Название</label><input name="name" required value="<?php echo e((string)($editing['name'] ?? '')); ?>"></div>
                <div><label>Примечание</label><textarea name="note"><?php echo e((string)($editing['note'] ?? '')); ?></textarea></div>
            </div>
            <p style="margin-top:14px"><button type="submit">Сохранить</button> <?php if ($editing): ?><a class="button secondary" href="admin_furniture.php?tab=categories">Отмена</a><?php endif; ?></p>
        </form>
    </section>
    <section class="panel">
        <h2>Список категорий</h2>
        <table>
            <thead><tr><th>Название</th><th>Примечание</th><th>Действия</th></tr></thead>
            <tbody>
            <?php foreach ($categories as $cat): ?>
                <tr>
                    <td><?php echo e($cat['name']); ?></td>
                    <td><?php echo e((string)($cat['note'] ?? '')); ?></td>
                    <td class="actions">
                        <a class="button secondary" href="admin_furniture.php?tab=categories&edit=<?php echo e((string)$cat['id']); ?>">Изменить</a>
                        <form method="post" onsubmit="return confirm('Удалить категорию?');" style="display:inline"><input type="hidden" name="action" value="delete_category"><input type="hidden" name="id" value="<?php echo e((string)$cat['id']); ?>"><button class="danger" type="submit">Удалить</button></form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$categories): ?><tr><td colspan="3">Категории пока не добавлены.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </section>
</div>

<!-- ════════════ ВКЛАДКА: КОМПЛЕКТЫ ════════════ -->
<div class="tab-pane <?php echo $activeTab==='kits'?'active':''; ?>" id="tab-kits">
    <section class="panel">
        <h2><?php echo $editing ? 'Редактировать комплект' : 'Добавить комплект'; ?></h2>
        <form method="post">
            <input type="hidden" name="action" value="<?php echo $editing ? 'update_kit' : 'create_kit'; ?>">
            <input type="hidden" name="id" value="<?php echo e((string)($editing['id'] ?? '')); ?>">
            <div class="grid">
                <div><label>Название</label><input name="name" required value="<?php echo e((string)($editing['name'] ?? '')); ?>"></div>
                <div><label>Коллекция</label><select name="collection_id"><option value="0">Без коллекции</option><?php foreach ($collections as $col): ?><option value="<?php echo e((string)$col['id']); ?>" <?php echo (int)($editing['collection_id'] ?? 0)===(int)$col['id']?'selected':''; ?>><?php echo e(($col['supplier_name']?$col['supplier_name'].' — ':'').$col['name']); ?></option><?php endforeach; ?></select></div>
                <div><label>Примечание</label><textarea name="note"><?php echo e((string)($editing['note'] ?? '')); ?></textarea></div>
            </div>
            <h3>Фурнитура в комплекте</h3>
            <div id="items">
                <?php $initialItems = $editingItems ?: [['furniture_id' => 0, 'quantity' => 1]]; foreach ($initialItems as $item): ?>
                <div class="kit-row">
                    <div><label>Позиция</label><select name="furniture_id[]"><option value="0">Выберите фурнитуру</option><?php foreach ($furniture as $f): ?><option value="<?php echo e((string)$f['id']); ?>" <?php echo (int)($item['furniture_id']??0)===(int)$f['id']?'selected':''; ?>><?php echo e(($f['category_name']??'Без категории').' — '.$f['material_name'].' / '.$f['unit']); ?></option><?php endforeach; ?></select></div>
                    <div><label>Кол-во</label><input type="number" step="0.001" min="0.001" name="quantity[]" value="<?php echo e((string)($item['quantity'] ?? '1')); ?>"></div>
                    <button type="button" class="danger" onclick="this.closest('.kit-row').remove()">Удалить</button>
                </div>
                <?php endforeach; ?>
            </div>
            <p><button type="button" class="secondary" id="add-item">Добавить позицию</button></p>
            <button type="submit">Сохранить</button>
            <?php if ($editing): ?><a class="button secondary" href="admin_furniture.php?tab=kits">Отмена</a><?php endif; ?>
        </form>
    </section>
    <section class="panel">
        <h2>Список комплектов</h2>
        <table>
            <thead><tr><th>Название</th><th>Коллекция</th><th>Позиций</th><th>Примечание</th><th>Действия</th></tr></thead>
            <tbody>
            <?php foreach ($kits as $kit): ?>
                <tr>
                    <td><?php echo e($kit['name']); ?></td>
                    <td><?php echo e(($kit['collection_name'] ? ($kit['supplier_name']?$kit['supplier_name'].' — ':'').$kit['collection_name'] : '—')); ?></td>
                    <td><?php echo e((string)$kit['items_count']); ?></td>
                    <td><?php echo e((string)($kit['note'] ?? '')); ?></td>
                    <td class="actions">
                        <a class="button secondary" href="admin_furniture.php?tab=kits&edit=<?php echo e((string)$kit['id']); ?>">Изменить</a>
                        <form method="post" onsubmit="return confirm('Удалить комплект?');" style="display:inline"><input type="hidden" name="action" value="delete_kit"><input type="hidden" name="id" value="<?php echo e((string)$kit['id']); ?>"><button class="danger" type="submit">Удалить</button></form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$kits): ?><tr><td colspan="4">Комплекты пока не добавлены.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </section>
</div>

<!-- ════════════ ВКЛАДКА: КОМПЛЕКТАЦИЯ ПЕРЕГОРОДОК ════════════ -->
<div class="tab-pane <?php echo $activeTab==='bindings'?'active':''; ?>" id="tab-bindings">
    <section class="panel">
        <h2>Привязка комплектов к типам перегородок</h2>
        <?php if (empty($partitionTypes)): ?>
            <p class="muted">Сначала добавьте типы перегородок.</p>
        <?php elseif (empty($kits)): ?>
            <p class="muted">Сначала добавьте хотя бы один комплект фурнитуры.</p>
        <?php else: ?>
        <form method="post" style="display:grid;grid-template-columns:1fr 1fr auto;gap:12px;align-items:end;margin-bottom:20px;">
            <input type="hidden" name="action" value="bind_kit">
            <div><label>Тип перегородки</label><select name="partition_type_id" required><option value="0">Выберите тип</option><?php foreach ($partitionTypes as $pt): ?><option value="<?php echo e((string)$pt['id']); ?>"><?php echo e($pt['name']); ?></option><?php endforeach; ?></select></div>
            <div><label>Комплект фурнитуры</label><select name="kit_id" required><option value="0">Выберите комплект</option><?php foreach ($kits as $kit): ?><option value="<?php echo e((string)$kit['id']); ?>"><?php echo e($kit['name']); ?></option><?php endforeach; ?></select></div>
            <button type="submit">Привязать</button>
        </form>
        <?php endif; ?>
        <table>
            <thead><tr><th>Тип перегородки</th><th>Комплект фурнитуры</th><th>Действия</th></tr></thead>
            <tbody>
            <?php foreach ($bindings as $b): ?>
                <tr>
                    <td><?php echo e($b['type_name']); ?></td>
                    <td><?php echo e($b['kit_name']); ?></td>
                    <td><form method="post" onsubmit="return confirm('Отвязать?');" style="display:inline"><input type="hidden" name="action" value="unbind_kit"><input type="hidden" name="id" value="<?php echo e((string)$b['binding_id']); ?>"><button class="danger" type="submit">Отвязать</button></form></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$bindings): ?><tr><td colspan="3" class="muted">Привязок пока нет.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </section>
</div>

<!-- ════════════ ВКЛАДКА: КОЛЛЕКЦИИ ════════════ -->
<div class="tab-pane <?php echo $activeTab==='collections'?'active':''; ?>" id="tab-collections">
    <section class="panel">
        <h2><?php echo $editing ? 'Редактировать коллекцию' : 'Добавить коллекцию'; ?></h2>
        <form method="post">
            <input type="hidden" name="action" value="<?php echo $editing ? 'update_collection' : 'create_collection'; ?>">
            <input type="hidden" name="id" value="<?php echo e((string)($editing['id'] ?? '')); ?>">
            <div class="grid">
                <div><label>Поставщик</label><select name="supplier_id"><option value="0">Поставщик не выбран</option><?php foreach ($suppliers as $s): ?><option value="<?php echo e((string)$s['id']); ?>" <?php echo (int)($editing['supplier_id'] ?? 0)===(int)$s['id']?'selected':''; ?>><?php echo e($s['company_name']); ?></option><?php endforeach; ?></select></div>
                <div><label>Название коллекции</label><input name="name" required value="<?php echo e((string)($editing['name'] ?? '')); ?>"></div>
            </div>
            <p style="margin-top:14px"><button type="submit">Сохранить</button> <?php if ($editing): ?><a class="button secondary" href="admin_furniture.php?tab=collections">Отмена</a><?php endif; ?></p>
        </form>
    </section>
    <section class="panel">
        <h2>Список коллекций</h2>
        <table>
            <thead><tr><th>Поставщик</th><th>Название коллекции</th><th>Действия</th></tr></thead>
            <tbody>
            <?php foreach ($collections as $col): ?>
                <tr>
                    <td><?php echo e((string)($col['supplier_name'] ?? '—')); ?></td>
                    <td><?php echo e($col['name']); ?></td>
                    <td class="actions">
                        <a class="button secondary" href="admin_furniture.php?tab=collections&edit=<?php echo e((string)$col['id']); ?>">Изменить</a>
                        <form method="post" onsubmit="return confirm('Удалить коллекцию?');" style="display:inline"><input type="hidden" name="action" value="delete_collection"><input type="hidden" name="id" value="<?php echo e((string)$col['id']); ?>"><button class="danger" type="submit">Удалить</button></form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$collections): ?><tr><td colspan="3">Коллекции пока не добавлены.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </section>
</div>

</main>
<template id="item-template"><div class="kit-row"><div><label>Позиция</label><select name="furniture_id[]"><option value="0">Выберите фурнитуру</option><?php foreach ($furniture as $f): ?><option value="<?php echo e((string)$f['id']); ?>"><?php echo e(($f['category_name']??'Без категории').' — '.$f['material_name'].' / '.$f['unit']); ?></option><?php endforeach; ?></select></div><div><label>Кол-во</label><input type="number" step="0.001" min="0.001" name="quantity[]" value="1"></div><button type="button" class="danger" onclick="this.closest('.kit-row').remove()">Удалить</button></div></template>
<script>
document.getElementById('add-item')?.addEventListener('click', () => document.getElementById('items').append(document.getElementById('item-template').content.cloneNode(true)));
</script>
</body>
</html>
