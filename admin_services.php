<?php
require_once __DIR__ . '/includes/admin_auth.php';
require_admin();
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/admin_schema.php';

ensure_services_table($pdo);
$pdo->exec("CREATE TABLE IF NOT EXISTS service_categories (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,name VARCHAR(120) NOT NULL,note TEXT NULL,created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TABLE IF NOT EXISTS service_kits (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,name VARCHAR(160) NOT NULL,created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TABLE IF NOT EXISTS service_kit_items (id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,kit_id INT NOT NULL,service_id INT NOT NULL,UNIQUE KEY (kit_id,service_id),FOREIGN KEY (kit_id) REFERENCES service_kits(id) ON DELETE CASCADE,FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$activeTab = $_GET['tab'] ?? 'services';
$validTabs = ['services', 'categories', 'kits'];
if (!in_array($activeTab, $validTabs, true)) $activeTab = 'services';

$errors = [];
$editing = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ═══ УСЛУГИ ═══
    if ($action === 'delete_service') { $id = (int)($_POST['id'] ?? 0); if ($id > 0) $pdo->prepare('DELETE FROM services WHERE id=:id')->execute(['id'=>$id]); header('Location: admin_services.php?tab=services'); exit; }
    if (in_array($action, ['create_service', 'update_service'])) {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? ''); $unit = trim($_POST['unit'] ?? 'усл.'); $priceRaw = trim($_POST['price'] ?? ''); $currency = strtoupper(trim($_POST['currency'] ?? 'RUB')); $note = trim($_POST['note'] ?? ''); $nomenclature = trim($_POST['nomenclature'] ?? ''); $thicknessId = (int)($_POST['thickness_id'] ?? 0); $categoryId = (int)($_POST['category_id'] ?? 0);
        $hSize = $_POST['h_size'] ?? 'no'; if (!in_array($hSize, ['no','le_2_5','2_5_to_5','le_3','3_to_6'])) $hSize = 'no';
        $dSize = $_POST['d_size'] ?? 'no'; if (!in_array($dSize, ['no','le_4','4_to_12','gt_12'])) $dSize = 'no';
        $stepMm = $_POST['step_mm'] ?? 'no'; if (!in_array($stepMm, ['no','16','32','64'])) $stepMm = 'no';
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        if ($name === '') $errors[] = 'Укажите название услуги.';
        if ($unit === '') $errors[] = 'Укажите единицу измерения.';
        if ($priceRaw === '' || !is_numeric($priceRaw) || (float)$priceRaw < 0) $errors[] = 'Цена должна быть неотрицательным числом.';
        $currentPhotoPath = null;
        if ($id > 0) { $s=$pdo->prepare('SELECT photo_path FROM services WHERE id=:id'); $s->execute(['id'=>$id]); $currentPhotoPath=$s->fetchColumn()?:null; }
        $photoPath = upload_image('photo', 'services', $errors, $currentPhotoPath);
        if (!$errors) {
            $params = ['name'=>$name,'unit'=>$unit,'price'=>(float)$priceRaw,'currency'=>$currency,'photo_path'=>$photoPath,'note'=>$note===''?null:$note,'nomenclature'=>$nomenclature===''?null:$nomenclature,'thickness_id'=>$thicknessId>0?$thicknessId:null,'category_id'=>$categoryId>0?$categoryId:null,'h_size'=>$hSize,'d_size'=>$dSize,'step_mm'=>$stepMm,'is_active'=>$isActive];
            if ($action === 'update_service' && $id > 0) { $params['id']=$id; $pdo->prepare('UPDATE services SET name=:name,unit=:unit,price=:price,currency=:currency,photo_path=:photo_path,note=:note,nomenclature=:nomenclature,thickness_id=:thickness_id,category_id=:category_id,h_size=:h_size,d_size=:d_size,step_mm=:step_mm,is_active=:is_active WHERE id=:id')->execute($params); header('Location: admin_services.php?tab=services&edit='.$id); exit; }
            else { $pdo->prepare('INSERT INTO services (name,unit,price,currency,photo_path,note,nomenclature,thickness_id,category_id,h_size,d_size,step_mm,is_active) VALUES (:name,:unit,:price,:currency,:photo_path,:note,:nomenclature,:thickness_id,:category_id,:h_size,:d_size,:step_mm,:is_active)')->execute($params); header('Location: admin_services.php?tab=services&edit='.$pdo->lastInsertId()); exit; }
        }
    }

    // ═══ КАТЕГОРИИ ═══
    if ($action === 'delete_category') { $id = (int)($_POST['id'] ?? 0); if ($id > 0) $pdo->prepare('DELETE FROM service_categories WHERE id=:id')->execute(['id'=>$id]); header('Location: admin_services.php?tab=categories'); exit; }
    if (in_array($action, ['create_category', 'update_category'])) {
        $id = (int)($_POST['id'] ?? 0); $name = trim($_POST['name'] ?? ''); $note = trim($_POST['note'] ?? '');
        if ($name === '') $errors[] = 'Укажите название категории.';
        if (!$errors) {
            if ($action === 'update_category' && $id > 0) { $pdo->prepare('UPDATE service_categories SET name=:name,note=:note WHERE id=:id')->execute(['name'=>$name,'note'=>$note?:null,'id'=>$id]); header('Location: admin_services.php?tab=categories&edit='.$id); exit; }
            else { $pdo->prepare('INSERT INTO service_categories (name,note) VALUES (:name,:note)')->execute(['name'=>$name,'note'=>$note?:null]); header('Location: admin_services.php?tab=categories&edit='.$pdo->lastInsertId()); exit; }
        }
    }

    // ═══ КОМПЛЕКТЫ ═══
    if ($action === 'delete_kit') { $id = (int)($_POST['id'] ?? 0); if ($id > 0) $pdo->prepare('DELETE FROM service_kits WHERE id=:id')->execute(['id'=>$id]); header('Location: admin_services.php?tab=kits'); exit; }
    if (in_array($action, ['create_kit', 'update_kit'])) {
        $id = (int)($_POST['id'] ?? 0); $name = trim($_POST['name'] ?? ''); $serviceIds = $_POST['service_ids'] ?? [];
        if ($name === '') $errors[] = 'Укажите название комплекта.';
        if (!$errors) {
            if ($action === 'update_kit' && $id > 0) { $pdo->prepare('UPDATE service_kits SET name=:name WHERE id=:id')->execute(['name'=>$name,'id'=>$id]); $pdo->prepare('DELETE FROM service_kit_items WHERE kit_id=:kid')->execute(['kid'=>$id]); }
            else { $pdo->prepare('INSERT INTO service_kits (name) VALUES (:name)')->execute(['name'=>$name]); $id = (int)$pdo->lastInsertId(); }
            if (!empty($serviceIds)) { $stmt = $pdo->prepare('INSERT INTO service_kit_items (kit_id, service_id) VALUES (:kid, :sid)'); foreach ($serviceIds as $sid) { $sid=(int)$sid; if($sid>0)$stmt->execute(['kid'=>$id,'sid'=>$sid]); } }
            header('Location: admin_services.php?tab=kits&edit='.$id); exit;
        }
    }
}

// ═══ DATA ═══
$units = $pdo->query('SELECT * FROM measurement_units WHERE is_active=1 ORDER BY short_name ASC')->fetchAll();
$currencies = $pdo->query("SELECT * FROM currencies WHERE is_active=1 ORDER BY code='RUB' DESC, code ASC")->fetchAll();
$thicknesses = $pdo->query('SELECT id, thickness FROM panel_thicknesses WHERE is_active=1 ORDER BY thickness ASC')->fetchAll();
$serviceCategories = $pdo->query('SELECT id, name FROM service_categories ORDER BY name ASC')->fetchAll();
$services = $pdo->query('SELECT s.*, pt.thickness, sc.name AS category_name, sc.note AS category_note FROM services s LEFT JOIN panel_thicknesses pt ON pt.id = s.thickness_id LEFT JOIN service_categories sc ON sc.id = s.category_id ORDER BY s.is_active DESC, s.name ASC')->fetchAll();
$allServices = $pdo->query('SELECT s.id, s.nomenclature, s.name, s.price, s.currency, s.unit, s.h_size, s.d_size, s.step_mm, s.category_id, pt.thickness, sc.name AS category_name FROM services s LEFT JOIN panel_thicknesses pt ON pt.id = s.thickness_id LEFT JOIN service_categories sc ON sc.id = s.category_id WHERE s.is_active=1 ORDER BY s.category_id, s.name ASC')->fetchAll();
$kits = $pdo->query('SELECT k.*, (SELECT COUNT(*) FROM service_kit_items WHERE kit_id=k.id) AS item_count FROM service_kits k ORDER BY k.name ASC')->fetchAll();
$selectedServices = [];

// EDITING
if ($activeTab === 'services') { $editId=(int)($_GET['edit']??0); if($editId>0){$s=$pdo->prepare('SELECT * FROM services WHERE id=:id');$s->execute(['id'=>$editId]);$editing=$s->fetch()?:null;} }
if ($activeTab === 'categories') { $editId=(int)($_GET['edit']??0); if($editId>0){$s=$pdo->prepare('SELECT * FROM service_categories WHERE id=:id');$s->execute(['id'=>$editId]);$editing=$s->fetch()?:null;} }
if ($activeTab === 'kits') { $editId=(int)($_GET['edit']??0); if($editId>0){$s=$pdo->prepare('SELECT * FROM service_kits WHERE id=:id');$s->execute(['id'=>$editId]);$editing=$s->fetch()?:null;if($editing){$items=$pdo->prepare('SELECT service_id FROM service_kit_items WHERE kit_id=:kid');$items->execute(['kid'=>$editId]);$selectedServices=array_column($items->fetchAll(),'service_id');}} }
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<title>Услуги</title>
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
table{border-collapse:collapse;background:#fff;width:100%;border-radius:14px;overflow:hidden}
th,td{padding:10px 12px;text-align:left;vertical-align:middle}
th{background:#edf6ff;color:#0f172a;font-size:12px;text-transform:uppercase;letter-spacing:.04em}
.errors{background:#fee2e2;color:#991b1b;padding:12px;border-radius:12px;margin-bottom:14px}
.actions{display:flex;gap:6px;align-items:center}
.status,.price{font-weight:700}
.preview{max-width:48px;max-height:32px;border-radius:4px;border:1px solid #e5e7eb;object-fit:cover}
.hint{color:#64748b;font-size:13px;margin-top:4px}
.quick-links{display:flex;gap:10px;margin-bottom:24px;flex-wrap:wrap}
.quick-link{padding:10px 18px;border-radius:12px;background:#fff;border:1px solid #e5e7eb;color:#374151;font-weight:700;font-size:14px;text-decoration:none;transition:all .15s;box-shadow:0 2px 6px rgba(15,23,42,.04)}
.quick-link:hover{background:#eff6ff;border-color:#93c5fd;color:#2563eb}
.quick-link.active{background:#2563eb;border-color:#2563eb;color:#fff}
.btn-icon{display:inline-flex;align-items:center;justify-content:center;width:36px;height:36px;border-radius:12px;border:none;padding:0;cursor:pointer;text-decoration:none;box-sizing:border-box;transition:all .15s}
.btn-edit{background:#eff6ff;color:#2563eb}.btn-edit:hover{background:#2563eb;color:#fff}
.btn-delete{background:#fef2f2;color:#dc2626}.btn-delete:hover{background:#dc2626;color:#fff}
.btn-icon svg{display:block}
.cat-row{background:#eff6ff}
.cat-row td{padding:8px 12px;font-weight:700;font-size:13px;color:#1e40af;border-bottom:2px solid #93c5fd;white-space:normal}
.services-list{max-height:320px;overflow-y:auto;border:1px solid #cbd5e1;border-radius:12px}
.services-list label{display:flex;align-items:center;gap:8px;padding:8px 12px;cursor:pointer;border-bottom:1px solid #f1f5f9;margin:0;font-weight:400}
.services-list label:hover{background:#f8fafc}
.services-list input[type="checkbox"]{width:auto}
.service-meta{font-size:12px;color:#64748b;margin-left:auto;white-space:nowrap}
.services-header{display:flex;justify-content:space-between;align-items:center;padding:8px 12px;background:#f8fafc;border-bottom:2px solid #e5e7eb;font-weight:700;font-size:13px}
.services-header label{margin:0;cursor:pointer}
.badge{display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700;background:#dbeafe;color:#1d4ed8}
.cat-group-header{padding:6px 12px;background:#eff6ff;font-weight:700;font-size:12px;color:#1e40af;border-bottom:1px solid #93c5fd;position:sticky;top:0}
.tab-pane{display:none}.tab-pane.active{display:block}
</style>
</head>
<body>
<header class="header">
    <div style="width:100%;display:flex;align-items:center;gap:16px;">
        <a href="admin.php">← Админ-панель</a>
        <h1 style="flex:1;text-align:center;margin:0;">Услуги</h1>
        <a href="logout.php">Выйти</a>
    </div>
</header>
<main class="container">
    <div class="quick-links">
        <a class="quick-link <?php echo $activeTab==='services'?'active':'';?>" href="admin_services.php?tab=services">🛠️ Услуги</a>
        <a class="quick-link <?php echo $activeTab==='categories'?'active':'';?>" href="admin_services.php?tab=categories">🗂️ Категории</a>
        <a class="quick-link <?php echo $activeTab==='kits'?'active':'';?>" href="admin_services.php?tab=kits">📦 Комплекты</a>
    </div>

<?php if ($errors): ?><div class="errors"><?php echo e(implode(' ', $errors)); ?></div><?php endif; ?>

<!-- ═══ УСЛУГИ ═══ -->
<div class="tab-pane <?php echo $activeTab==='services'?'active':''; ?>">
    <section class="panel">
        <h2><?php echo $editing ? 'Редактировать услугу' : 'Добавить услугу'; ?></h2>
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="action" value="<?php echo $editing ? 'update_service' : 'create_service'; ?>">
            <input type="hidden" name="id" value="<?php echo e((string)($editing['id'] ?? '')); ?>">
            <div class="grid">
                <div><label>Код</label><input name="nomenclature" value="<?php echo e((string)($editing['nomenclature'] ?? '')); ?>" placeholder="Артикул"></div>
                <div><label>Название</label><input name="name" required value="<?php echo e((string)($editing['name'] ?? '')); ?>"></div>
                <div><label>Ед. изм.</label><select name="unit" required><?php foreach ($units as $u): ?><option value="<?php echo e($u['short_name']); ?>" <?php echo (string)($editing['unit']??'усл.')===$u['short_name']?'selected':''; ?>><?php echo e($u['short_name']); ?> — <?php echo e($u['full_name']); ?></option><?php endforeach; ?></select></div>
                <div><label>Толщина</label><select name="thickness_id"><option value="0">— Не указана —</option><?php foreach ($thicknesses as $t): ?><option value="<?php echo e((string)$t['id']); ?>" <?php echo (int)($editing['thickness_id']??0)===(int)$t['id']?'selected':''; ?>><?php echo e(rtrim(rtrim((string)$t['thickness'],'0'),'.') . ' мм'); ?></option><?php endforeach; ?></select></div>
                <div><label>Категория</label><select name="category_id"><option value="0">— Без категории —</option><?php foreach ($serviceCategories as $cat): ?><option value="<?php echo e((string)$cat['id']); ?>" <?php echo (int)($editing['category_id']??0)===(int)$cat['id']?'selected':''; ?>><?php echo e($cat['name']); ?></option><?php endforeach; ?></select></div>
                <div><label>h — размер</label><select name="h_size"><option value="no" <?php echo ($editing['h_size']??'no')==='no'?'selected':''; ?>>Нет</option><option value="le_2_5" <?php echo ($editing['h_size']??'')==='le_2_5'?'selected':''; ?>>≤ 2.5</option><option value="2_5_to_5" <?php echo ($editing['h_size']??'')==='2_5_to_5'?'selected':''; ?>>2.5–5</option><option value="le_3" <?php echo ($editing['h_size']??'')==='le_3'?'selected':''; ?>>≤ 3</option><option value="3_to_6" <?php echo ($editing['h_size']??'')==='3_to_6'?'selected':''; ?>>3–6</option></select></div>
                <div><label>d — размер</label><select name="d_size"><option value="no" <?php echo ($editing['d_size']??'no')==='no'?'selected':''; ?>>Нет</option><option value="le_4" <?php echo ($editing['d_size']??'')==='le_4'?'selected':''; ?>>≤ 4</option><option value="4_to_12" <?php echo ($editing['d_size']??'')==='4_to_12'?'selected':''; ?>>4–12</option><option value="gt_12" <?php echo ($editing['d_size']??'')==='gt_12'?'selected':''; ?>>&gt; 12</option></select></div>
                <div><label>Шаг</label><select name="step_mm"><option value="no" <?php echo ($editing['step_mm']??'no')==='no'?'selected':''; ?>>Нет</option><option value="16" <?php echo ($editing['step_mm']??'')==='16'?'selected':''; ?>>16</option><option value="32" <?php echo ($editing['step_mm']??'')==='32'?'selected':''; ?>>32</option><option value="64" <?php echo ($editing['step_mm']??'')==='64'?'selected':''; ?>>64</option></select></div>
                <div><label>Цена</label><input id="price" type="number" step="0.01" min="0" name="price" required value="<?php echo e((string)($editing['price'] ?? '')); ?>"></div>
                <div><label>Валюта</label><select id="currency" name="currency"><?php foreach ($currencies as $c): ?><option value="<?php echo e($c['code']); ?>" data-rate="<?php echo e((string)$c['rate_to_rub']); ?>" <?php echo (string)($editing['currency']??'RUB')===$c['code']?'selected':''; ?>><?php echo e($c['code']); ?> — <?php echo e($c['name']); ?></option><?php endforeach; ?></select></div>
                <div><label>Фото</label><input type="file" name="photo" accept=".jpg,.jpeg,.png,.tif,.tiff,.webp"><p><img class="preview" id="photo_preview" src="<?php echo e((string)($editing['photo_path'] ?? '')); ?>" alt="" style="<?php echo empty($editing['photo_path'])?'display:none;':''?>"></p></div>
            </div>
            <p><label>Примечание</label><textarea name="note"><?php echo e((string)($editing['note'] ?? '')); ?></textarea></p>
            <p><label><input type="checkbox" name="is_active" <?php echo !isset($editing['is_active'])||(int)$editing['is_active']===1?'checked':''; ?>> Активна</label></p>
            <button type="submit">Сохранить</button>
            <?php if ($editing): ?><a class="button secondary" href="admin_services.php?tab=services">Отмена</a><?php endif; ?>
        </form>
    </section>
    <section class="panel">
        <h2>Список услуг</h2>
        <?php $grouped=[];foreach($services as $s){$key=($s['category_id']??null)?(string)$s['category_id']:'_none';if(!isset($grouped[$key]))$grouped[$key]=['name'=>$s['category_name']?:'Без категории','note'=>$s['category_note']??null,'items'=>[]];$grouped[$key]['items'][]=$s;} ?>
        <table><thead><tr><th style="width:6%">Код</th><th style="width:20%">Название</th><th>Толщ.</th><th>h</th><th>d</th><th>Шаг</th><th>Цена</th><th>Статус</th><th>Фото</th><th>Действия</th></tr></thead><tbody>
        <?php if (!$services): ?><tr><td colspan="10">Услуги пока не добавлены.</td></tr><?php else: ?>
        <?php foreach ($grouped as $group): ?>
            <tr class="cat-row"><td colspan="10"><strong><?php echo e($group['name']); ?></strong><?php if(!empty($group['note'])):?> <span style="font-weight:400;font-size:11px;color:#3b82f6"><?php echo e($group['note']);?></span><?php endif;?></td></tr>
            <?php foreach ($group['items'] as $svc): $hLabels=['no'=>'Нет','le_2_5'=>'≤ 2.5','2_5_to_5'=>'2.5–5','le_3'=>'≤ 3','3_to_6'=>'3–6'];$dLabels=['no'=>'Нет','le_4'=>'≤ 4','4_to_12'=>'4–12','gt_12'=>'> 12'];$stepLabels=['no'=>'Нет','16'=>'16','32'=>'32','64'=>'64']; ?>
            <tr>
                <td><?php echo e((string)($svc['nomenclature']??'')); ?></td>
                <td style="white-space:normal"><?php echo e($svc['name']); ?></td>
                <td><?php echo !empty($svc['thickness'])?e(rtrim(rtrim((string)$svc['thickness'],'0'),'.') . ' мм'):'—'; ?></td>
                <td><?php echo e($hLabels[$svc['h_size']??'no']??'Нет'); ?></td>
                <td><?php echo e($dLabels[$svc['d_size']??'no']??'Нет'); ?></td>
                <td><?php echo e($stepLabels[$svc['step_mm']??'no']??'Нет'); ?></td>
                <td class="price"><?php echo e(number_format((float)$svc['price'],2,',',' ')); ?> <?php echo e($svc['currency']??'RUB'); ?></td>
                <td class="status"><?php echo (int)$svc['is_active']===1?'✔':'✘'; ?></td>
                <td><?php if(!empty($svc['photo_path'])):?><img class="preview" src="<?php echo e($svc['photo_path']); ?>" alt=""><?php else:?>—<?php endif;?></td>
                <td class="actions"><a class="btn-icon btn-edit" href="admin_services.php?tab=services&edit=<?php echo e((string)$svc['id']); ?>" title="Изменить"><svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg></a><button class="btn-icon btn-delete" type="button" onclick="document.getElementById('del-id').value=<?php echo (int)$svc['id'];?>;document.querySelector('#del-form input[name=action]').value='delete_service';document.getElementById('del-form').submit();" title="Удалить"><svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg></button></td>
            </tr>
            <?php endforeach; ?>
        <?php endforeach; endif; ?>
        </tbody></table>
    </section>
</div>

<!-- ═══ КАТЕГОРИИ ═══ -->
<div class="tab-pane <?php echo $activeTab==='categories'?'active':''; ?>">
    <section class="panel">
        <h2><?php echo $editing ? 'Редактировать категорию' : 'Добавить категорию'; ?></h2>
        <form method="post">
            <input type="hidden" name="action" value="<?php echo $editing ? 'update_category' : 'create_category'; ?>">
            <input type="hidden" name="id" value="<?php echo e((string)($editing['id'] ?? '')); ?>">
            <div class="grid">
                <div><label>Название</label><input name="name" required value="<?php echo e((string)($editing['name'] ?? '')); ?>"></div>
                <div><label>Примечание</label><textarea name="note"><?php echo e((string)($editing['note'] ?? '')); ?></textarea></div>
            </div>
            <p style="margin-top:14px"><button type="submit">Сохранить</button><?php if ($editing): ?><a class="button secondary" href="admin_services.php?tab=categories">Отмена</a><?php endif; ?></p>
        </form>
    </section>
    <section class="panel">
        <h2>Список категорий</h2>
        <table><thead><tr><th>Название</th><th>Примечание</th><th>Действия</th></tr></thead><tbody>
        <?php foreach ($serviceCategories as $cat): ?>
            <tr><td><?php echo e($cat['name']); ?></td><td style="white-space:pre-line"><?php echo e((string)($cat['note']??'')); ?></td><td class="actions"><a class="btn-icon btn-edit" href="admin_services.php?tab=categories&edit=<?php echo e((string)$cat['id']); ?>" title="Изменить"><svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg></a><button class="btn-icon btn-delete" type="button" onclick="document.getElementById('del-id').value=<?php echo (int)$cat['id'];?>;document.querySelector('#del-form input[name=action]').value='delete_category';document.getElementById('del-form').submit();" title="Удалить"><svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg></button></td></tr>
        <?php endforeach; ?>
        <?php if (!$serviceCategories): ?><tr><td colspan="3">Категории пока не добавлены.</td></tr><?php endif; ?>
        </tbody></table>
    </section>
</div>

<!-- ═══ КОМПЛЕКТЫ ═══ -->
<div class="tab-pane <?php echo $activeTab==='kits'?'active':''; ?>">
    <section class="panel">
        <h2><?php echo $editing ? 'Редактировать комплект' : 'Добавить комплект'; ?></h2>
        <form method="post">
            <input type="hidden" name="action" value="<?php echo $editing ? 'update_kit' : 'create_kit'; ?>">
            <input type="hidden" name="id" value="<?php echo e((string)($editing['id'] ?? '')); ?>">
            <div style="max-width:400px;margin-bottom:16px;"><label>Название</label><input name="name" required value="<?php echo e((string)($editing['name'] ?? '')); ?>"></div>
            <div><label>Услуги в комплекте</label>
                <div class="services-list" id="services-list">
                    <div class="services-header"><label><input type="checkbox" id="select-all"> Выбрать все</label><span id="selected-count" class="badge">0 выбрано</span></div>
                    <?php $hLabels=['no'=>'','le_2_5'=>'≤ 2.5','2_5_to_5'=>'2.5–5','le_3'=>'≤ 3','3_to_6'=>'3–6'];$dLabels=['no'=>'','le_4'=>'≤ 4','4_to_12'=>'4–12','gt_12'=>'> 12'];$stepLabels=['no'=>'','16'=>'16','32'=>'32','64'=>'64'];$grouped2=[];foreach($allServices as $svc){$key=($svc['category_id']??null)?(string)$svc['category_id']:'_none';if(!isset($grouped2[$key]))$grouped2[$key]=['name'=>$svc['category_name']?:'Без категории','items'=>[]];$grouped2[$key]['items'][]=$svc;} foreach($grouped2 as $group):?>
                        <div class="cat-group-header"><?php echo e($group['name']); ?></div>
                        <?php foreach($group['items'] as $svc): $tags=[];if(!empty($svc['thickness']))$tags[]=rtrim(rtrim((string)$svc['thickness'],'0'),'.') . ' мм';$hv=$hLabels[$svc['h_size']??'no']??'';if($hv)$tags[]='h '.$hv;$dv=$dLabels[$svc['d_size']??'no']??'';if($dv)$tags[]='d '.$dv;$sv=$stepLabels[$svc['step_mm']??'no']??'';if($sv)$tags[]='шаг '.$sv;?>
                        <label><input type="checkbox" name="service_ids[]" value="<?php echo (int)$svc['id']; ?>" <?php echo in_array((int)$svc['id'],$selectedServices)?'checked':''; ?>><span><?php echo e((string)($svc['nomenclature']??'')); ?> — <?php echo e($svc['name']); ?><?php if($tags):?>, <?php echo e(implode(', ',$tags));?><?php endif;?></span><span class="service-meta"><?php echo e(number_format((float)$svc['price'],2,',',' ')); ?> <?php echo e($svc['currency']??'RUB'); ?> / <?php echo e($svc['unit']); ?></span></label>
                        <?php endforeach; endforeach; if(!$allServices):?><p style="padding:12px;color:#64748b;">Нет доступных услуг.</p><?php endif;?>
                </div>
            </div>
            <p style="margin-top:14px"><button type="submit">Сохранить</button><?php if ($editing): ?><a class="button secondary" href="admin_services.php?tab=kits">Отмена</a><?php endif; ?></p>
        </form>
    </section>
    <section class="panel">
        <h2>Список комплектов</h2>
        <table><thead><tr><th>Название</th><th>Услуг</th><th>Сумма</th><th>Действия</th></tr></thead><tbody>
        <?php foreach ($kits as $kit): ?>
            <tr><td><?php echo e($kit['name']); ?></td><td><?php echo (int)$kit['item_count']; ?></td><td><?php $sum=$pdo->query('SELECT COALESCE(SUM(s.price),0) AS total FROM service_kit_items ski JOIN services s ON s.id=ski.service_id WHERE ski.kit_id='.(int)$kit['id'])->fetch();echo e(number_format((float)($sum['total']??0),2,',',' '));?></td><td class="actions"><a class="btn-icon btn-edit" href="admin_services.php?tab=kits&edit=<?php echo (int)$kit['id']; ?>" title="Изменить"><svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg></a><button class="btn-icon btn-delete" type="button" onclick="document.getElementById('del-id').value=<?php echo (int)$kit['id'];?>;document.querySelector('#del-form input[name=action]').value='delete_kit';document.getElementById('del-form').submit();" title="Удалить"><svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg></button></td></tr>
        <?php endforeach; ?>
        <?php if (!$kits): ?><tr><td colspan="4">Комплекты пока не добавлены.</td></tr><?php endif; ?>
        </tbody></table>
    </section>
</div>

<form id="del-form" method="post" style="display:none;"><input type="hidden" name="action" value="delete_service"><input type="hidden" name="id" id="del-id"></form>
</main>
<script>
const currencyInput=document.getElementById('currency'),priceInput=document.getElementById('price'),photoInput=document.querySelector('[name="photo"]'),photoPreview=document.getElementById('photo_preview');
let currentCurrencyRate=parseFloat(currencyInput?.selectedOptions[0]?.dataset.rate||'1')||1;
currencyInput?.addEventListener('change',()=>{const newRate=parseFloat(currencyInput.selectedOptions[0]?.dataset.rate||'1')||1;const v=parseFloat(priceInput.value);if(Number.isFinite(v))priceInput.value=Math.round(v*currentCurrencyRate/newRate*100)/100;currentCurrencyRate=newRate;});
photoInput?.addEventListener('change',()=>{const f=photoInput.files?.[0];if(!f||!f.type.startsWith('image/')){photoPreview.style.display='none';return;}photoPreview.src=URL.createObjectURL(f);photoPreview.style.display='inline-block';});
const selectAll=document.getElementById('select-all'),checkboxes=document.querySelectorAll('#services-list input[type="checkbox"]:not(#select-all)'),countSpan=document.getElementById('selected-count');
function updateCount(){const c=document.querySelectorAll('#services-list input[type="checkbox"]:not(#select-all):checked').length;countSpan.textContent=c+' выбрано';}
selectAll?.addEventListener('change',()=>{checkboxes.forEach(cb=>cb.checked=selectAll.checked);updateCount();});
checkboxes.forEach(cb=>cb.addEventListener('change',updateCount));updateCount();
</script>
</body>
</html>
