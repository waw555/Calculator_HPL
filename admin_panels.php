<?php
require_once __DIR__ . '/includes/admin_auth.php';
require_once __DIR__ . '/includes/app_header.php';
require_admin();
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/admin_schema.php';

ensure_panel_formats_table($pdo);
ensure_panel_sizes_table($pdo);
ensure_panel_thicknesses_table($pdo);
ensure_embossings_table($pdo);

$activeTab = $_GET['tab'] ?? 'panels';
$validTabs = ['panels', 'embossings', 'sizes', 'thicknesses'];
if (!in_array($activeTab, $validTabs, true)) $activeTab = 'panels';

$errors = [];
$editing = null;
$decorDirections = ['vertical' => 'Вертикально', 'horizontal' => 'Горизонтально', 'none' => 'Нет направления'];

function calculate_panel_prices(float $width, float $height, string $priceM2Raw, string $priceSheetRaw, string $priceSource): array
{
    $area = ($width * $height) / 1000000;
    $priceM2 = $priceM2Raw === '' ? null : (float)$priceM2Raw;
    $priceSheet = $priceSheetRaw === '' ? null : (float)$priceSheetRaw;
    if ($area > 0) {
        if ($priceSource === 'sheet' && $priceSheet !== null) { $priceM2 = round($priceSheet / $area, 2); }
        elseif ($priceM2 !== null) { $priceSheet = round($priceM2 * $area, 2); }
        elseif ($priceSheet !== null) { $priceM2 = round($priceSheet / $area, 2); }
    }
    return [$priceM2, $priceSheet];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ═══ ПАНЕЛИ ═══
    if ($action === 'delete_panel') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) { $pdo->prepare('DELETE FROM panel_formats WHERE id = :id')->execute(['id' => $id]); }
        header('Location: admin_panels.php?tab=panels'); exit;
    }
    if (in_array($action, ['create_panel', 'update_panel'])) {
        $id = (int)($_POST['id'] ?? 0);
        $currentPhotoPath = null;
        if ($id > 0) { $stmt = $pdo->prepare('SELECT decor_photo_path FROM panel_formats WHERE id = :id'); $stmt->execute(['id' => $id]); $currentPhotoPath = $stmt->fetchColumn() ?: null; }
        $name = trim($_POST['name'] ?? '');
        $nomenclature = trim($_POST['nomenclature'] ?? '');
        $panelSizeId = (int)($_POST['panel_size_id'] ?? 0);
        $thicknessId = (int)($_POST['thickness_id'] ?? 0);
        $manufacturerId = (int)($_POST['manufacturer_id'] ?? 0);
        $decorNumber = trim($_POST['decor_number'] ?? '');
        $decorName = trim($_POST['decor_name'] ?? '');
        $decorDirection = $_POST['decor_direction'] ?? 'none';
        $isStockProgram = (int)($_POST['is_stock_program'] ?? 0) === 1 ? 1 : 0;
        $priceM2Raw = trim($_POST['price_per_m2'] ?? '');
        $priceSheetRaw = trim($_POST['price_per_sheet'] ?? '');
        $priceSource = $_POST['price_source'] ?? 'm2';
        $currency = strtoupper(trim($_POST['currency'] ?? 'RUB'));
        $description = trim($_POST['description'] ?? '');
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        $costRaw = trim($_POST['cost'] ?? '');
        $costSheetRaw = trim($_POST['cost_per_sheet'] ?? '');
        $markupRaw = trim($_POST['markup'] ?? '');
        $weightRaw = trim($_POST['weight'] ?? '');
        $weightPerM2Raw = trim($_POST['weight_per_m2'] ?? '');
        $volumeRaw = trim($_POST['volume_m2'] ?? '');
        $embossingId = (int)($_POST['embossing_id'] ?? 0);
        $width = 0; $height = 0; $thicknessRaw = '';
        if ($panelSizeId > 0) { $sz = $pdo->prepare('SELECT * FROM panel_sizes WHERE id=:id AND is_active=1'); $sz->execute(['id' => $panelSizeId]); $szRow = $sz->fetch(); if ($szRow) { $width = (int)$szRow['width_mm']; $height = (int)$szRow['height_mm']; } }
        if ($thicknessId > 0) { $th = $pdo->prepare('SELECT thickness FROM panel_thicknesses WHERE id=:id AND is_active=1'); $th->execute(['id' => $thicknessId]); $thRow = $th->fetch(); if ($thRow) $thicknessRaw = (string)$thRow['thickness']; }
        if ($name === '') $errors[] = 'Укажите название панели.';
        if ($width <= 0 || $height <= 0) $errors[] = 'Выберите формат панели.';
        if (!isset($decorDirections[$decorDirection])) $errors[] = 'Выберите корректное направление декора.';
        if ($currency === '') $errors[] = 'Выберите валюту.';
        if ($priceM2Raw !== '' && (!is_numeric($priceM2Raw) || (float)$priceM2Raw < 0)) $errors[] = 'Цена за м² должна быть неотрицательным числом.';
        if ($priceSheetRaw !== '' && (!is_numeric($priceSheetRaw) || (float)$priceSheetRaw < 0)) $errors[] = 'Цена за лист должна быть неотрицательным числом.';
        if ($costRaw !== '' && (!is_numeric($costRaw) || (float)$costRaw < 0)) $errors[] = 'Себестоимость должна быть неотрицательным числом.';
        $photoCustomName = null;
        { $mfrName = ''; if ($manufacturerId > 0) { $ms = $pdo->prepare('SELECT full_name FROM manufacturers WHERE id=:id'); $ms->execute(['id' => $manufacturerId]); $mfrName = (string)($ms->fetchColumn() ?: ''); } $embShort = ''; if ($embossingId > 0) { $es = $pdo->prepare('SELECT COALESCE(short_name, name) FROM embossings WHERE id=:id'); $es->execute(['id' => $embossingId]); $embShort = (string)($es->fetchColumn() ?: ''); } $photoParts = array_filter([preg_replace('/\s+/', '_', trim($mfrName)), preg_replace('/\s+/', '_', $decorNumber), preg_replace('/\s+/', '_', $embShort)]); if ($photoParts) $photoCustomName = implode('_', $photoParts); }
        $decorPhotoPath = upload_image('decor_photo', 'decors', $errors, $currentPhotoPath, $photoCustomName);
        [$priceM2, $priceSheet] = calculate_panel_prices($width, $height, $priceM2Raw, $priceSheetRaw, $priceSource);
        if (!$errors) {
            $params = ['name'=>$name,'nomenclature'=>$nomenclature===' '?null:$nomenclature,'width_mm'=>$width,'height_mm'=>$height,'thickness_mm'=>$thicknessRaw===''?null:(float)$thicknessRaw,'panel_size_id'=>$panelSizeId>0?$panelSizeId:null,'thickness_id'=>$thicknessId>0?$thicknessId:null,'manufacturer_id'=>$manufacturerId>0?$manufacturerId:null,'decor_number'=>$decorNumber===' '?null:$decorNumber,'decor_name'=>$decorName===' '?null:$decorName,'decor_direction'=>$decorDirection,'is_stock_decor'=>$isStockProgram,'is_stock_program'=>$isStockProgram,'decor_photo_path'=>$decorPhotoPath,'price_per_m2'=>$priceM2,'price_per_sheet'=>$priceSheet,'currency'=>$currency,'description'=>$description===' '?null:$description,'is_active'=>$isActive,'cost'=>$costRaw===''?null:(float)$costRaw,'cost_per_sheet'=>$costSheetRaw===''?null:(float)$costSheetRaw,'markup'=>$markupRaw===''?null:(float)$markupRaw,'weight'=>$weightRaw===''?null:(float)$weightRaw,'weight_per_m2'=>$weightPerM2Raw===''?null:(float)$weightPerM2Raw,'volume_m2'=>$volumeRaw===''?null:(float)$volumeRaw,'embossing_id'=>$embossingId>0?$embossingId:null];
            if ($action === 'update_panel' && $id > 0) {
                $params['id'] = $id;
                $pdo->prepare('UPDATE panel_formats SET name=:name,nomenclature=:nomenclature,width_mm=:width_mm,height_mm=:height_mm,thickness_mm=:thickness_mm,panel_size_id=:panel_size_id,thickness_id=:thickness_id,manufacturer_id=:manufacturer_id,decor_number=:decor_number,decor_name=:decor_name,decor_direction=:decor_direction,is_stock_decor=:is_stock_decor,is_stock_program=:is_stock_program,decor_photo_path=:decor_photo_path,price_per_m2=:price_per_m2,price_per_sheet=:price_per_sheet,currency=:currency,description=:description,is_active=:is_active,cost=:cost,cost_per_sheet=:cost_per_sheet,markup=:markup,weight=:weight,weight_per_m2=:weight_per_m2,volume_m2=:volume_m2,embossing_id=:embossing_id WHERE id=:id')->execute($params);
                header('Location: admin_panels.php?tab=panels&edit=' . $id); exit;
            } else {
                $pdo->prepare('INSERT INTO panel_formats (name,nomenclature,width_mm,height_mm,thickness_mm,panel_size_id,thickness_id,manufacturer_id,decor_number,decor_name,decor_direction,is_stock_decor,is_stock_program,decor_photo_path,price_per_m2,price_per_sheet,currency,description,is_active,cost,cost_per_sheet,markup,weight,weight_per_m2,volume_m2,embossing_id) VALUES (:name,:nomenclature,:width_mm,:height_mm,:thickness_mm,:panel_size_id,:thickness_id,:manufacturer_id,:decor_number,:decor_name,:decor_direction,:is_stock_decor,:is_stock_program,:decor_photo_path,:price_per_m2,:price_per_sheet,:currency,:description,:is_active,:cost,:cost_per_sheet,:markup,:weight,:weight_per_m2,:volume_m2,:embossing_id)')->execute($params);
                header('Location: admin_panels.php?tab=panels&edit=' . $pdo->lastInsertId()); exit;
            }
        }
    }

    // ═══ ТИСНЕНИЯ ═══
    if ($action === 'delete_embossing') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM panel_formats WHERE embossing_id = :id'); $stmt->execute(['id' => $id]);
            if ((int)$stmt->fetchColumn() > 0) { $errors[] = 'Невозможно удалить тиснение: оно используется в панелях.'; }
            else { $pdo->prepare('DELETE FROM embossings WHERE id = :id')->execute(['id' => $id]); }
        }
        header('Location: admin_panels.php?tab=embossings'); exit;
    }
    if ($action === 'toggle_emb_active') { $id = (int)($_POST['id'] ?? 0); if ($id > 0) $pdo->prepare('UPDATE embossings SET is_active = NOT is_active WHERE id=:id')->execute(['id'=>$id]); header('Location: admin_panels.php?tab=embossings'); exit; }
    if ($action === 'toggle_emb_stock') { $id = (int)($_POST['id'] ?? 0); if ($id > 0) $pdo->prepare('UPDATE embossings SET is_stock_program = NOT is_stock_program WHERE id=:id')->execute(['id'=>$id]); header('Location: admin_panels.php?tab=embossings'); exit; }
    if (in_array($action, ['create_embossing', 'update_embossing'])) {
        $id = (int)($_POST['id'] ?? 0);
        $currentImagePath = null;
        if ($id > 0) { $stmt = $pdo->prepare('SELECT image_path FROM embossings WHERE id = :id'); $stmt->execute(['id' => $id]); $currentImagePath = $stmt->fetchColumn() ?: null; }
        $name = trim($_POST['name'] ?? '');
        $shortName = trim($_POST['short_name'] ?? '');
        $note = trim($_POST['note'] ?? '');
        $manufacturerId = (int)($_POST['manufacturer_id'] ?? 0);
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        $isStockProgram = (int)($_POST['is_stock_program'] ?? 0) === 1 ? 1 : 0;
        if ($name === '' || mb_strlen($name) > 100) $errors[] = 'Полное название обязательно и не более 100 символов.';
        if ($shortName !== '' && mb_strlen($shortName) > 20) $errors[] = 'Краткое название не более 20 символов.';
        $customName = null;
        if ($manufacturerId > 0 || $shortName !== '') { $mfrName = ''; if ($manufacturerId > 0) { $stmt = $pdo->prepare('SELECT full_name FROM manufacturers WHERE id = :id'); $stmt->execute(['id' => $manufacturerId]); $mfrName = (string)($stmt->fetchColumn() ?: ''); } $parts = array_filter([preg_replace('/\s+/', '_', trim($mfrName)), preg_replace('/\s+/', '_', $shortName)]); if ($parts) $customName = implode('_', $parts); }
        $imagePath = upload_image('emb_image', 'embossings', $errors, $currentImagePath, $customName);
        if (!$errors) {
            $params = ['name'=>$name,'short_name'=>$shortName===''?null:$shortName,'manufacturer_id'=>$manufacturerId>0?$manufacturerId:null,'image_path'=>$imagePath,'note'=>$note===''?null:$note,'is_active'=>$isActive,'is_stock_program'=>$isStockProgram];
            if ($action === 'update_embossing' && $id > 0) { $params['id'] = $id; $pdo->prepare('UPDATE embossings SET name=:name,short_name=:short_name,manufacturer_id=:manufacturer_id,image_path=:image_path,note=:note,is_active=:is_active,is_stock_program=:is_stock_program WHERE id=:id')->execute($params); header('Location: admin_panels.php?tab=embossings&edit='.$id); exit; }
            else { $pdo->prepare('INSERT INTO embossings (name,short_name,manufacturer_id,image_path,note,is_active,is_stock_program) VALUES (:name,:short_name,:manufacturer_id,:image_path,:note,:is_active,:is_stock_program)')->execute($params); header('Location: admin_panels.php?tab=embossings&edit='.$pdo->lastInsertId()); exit; }
        }
    }

    // ═══ ФОРМАТЫ (РАЗМЕРЫ) ═══
    if ($action === 'delete_size') { $id = (int)($_POST['id'] ?? 0); if ($id > 0) $pdo->prepare('DELETE FROM panel_sizes WHERE id=:id')->execute(['id'=>$id]); header('Location: admin_panels.php?tab=sizes'); exit; }
    if ($action === 'toggle_size_active') { $id = (int)($_POST['id'] ?? 0); if ($id > 0) $pdo->prepare('UPDATE panel_sizes SET is_active = NOT is_active WHERE id=:id')->execute(['id'=>$id]); header('Location: admin_panels.php?tab=sizes'); exit; }
    if ($action === 'toggle_size_stock') { $id = (int)($_POST['id'] ?? 0); if ($id > 0) $pdo->prepare('UPDATE panel_sizes SET is_stock_program = NOT is_stock_program WHERE id=:id')->execute(['id'=>$id]); header('Location: admin_panels.php?tab=sizes'); exit; }
    if (in_array($action, ['create_size', 'update_size'])) {
        $id = (int)($_POST['id'] ?? 0); $heightMm = (int)($_POST['height_mm'] ?? 0); $widthMm = (int)($_POST['width_mm'] ?? 0); $manufacturerId = (int)($_POST['manufacturer_id'] ?? 0); $isActive = isset($_POST['is_active']) ? 1 : 0; $isStockProgram = (int)($_POST['is_stock_program'] ?? 0) === 1 ? 1 : 0;
        if ($heightMm <= 0) $errors[] = 'Высота должна быть больше 0.';
        if ($widthMm <= 0) $errors[] = 'Ширина должна быть больше 0.';
        if (!$errors) {
            $volumeM2 = round(($heightMm * $widthMm) / 1000000, 6);
            $params = ['height_mm'=>$heightMm,'width_mm'=>$widthMm,'volume_m2'=>$volumeM2,'manufacturer_id'=>$manufacturerId>0?$manufacturerId:null,'is_active'=>$isActive,'is_stock_program'=>$isStockProgram];
            if ($action === 'update_size' && $id > 0) { $params['id'] = $id; $pdo->prepare('UPDATE panel_sizes SET height_mm=:height_mm,width_mm=:width_mm,volume_m2=:volume_m2,manufacturer_id=:manufacturer_id,is_active=:is_active,is_stock_program=:is_stock_program WHERE id=:id')->execute($params); header('Location: admin_panels.php?tab=sizes&edit='.$id); exit; }
            else { $pdo->prepare('INSERT INTO panel_sizes (height_mm,width_mm,volume_m2,manufacturer_id,is_active,is_stock_program) VALUES (:height_mm,:width_mm,:volume_m2,:manufacturer_id,:is_active,:is_stock_program)')->execute($params); header('Location: admin_panels.php?tab=sizes&edit='.$pdo->lastInsertId()); exit; }
        }
    }

    // ═══ ТОЛЩИНЫ ═══
    if ($action === 'delete_thickness') { $id = (int)($_POST['id'] ?? 0); if ($id > 0) $pdo->prepare('DELETE FROM panel_thicknesses WHERE id=:id')->execute(['id'=>$id]); header('Location: admin_panels.php?tab=thicknesses'); exit; }
    if ($action === 'toggle_th_active') { $id = (int)($_POST['id'] ?? 0); if ($id > 0) $pdo->prepare('UPDATE panel_thicknesses SET is_active = NOT is_active WHERE id=:id')->execute(['id'=>$id]); header('Location: admin_panels.php?tab=thicknesses'); exit; }
    if (in_array($action, ['create_thickness', 'update_thickness'])) {
        $id = (int)($_POST['id'] ?? 0); $thicknessRaw = trim($_POST['thickness'] ?? ''); $isActive = isset($_POST['is_active']) ? 1 : 0;
        if ($thicknessRaw === '' || !is_numeric($thicknessRaw) || (float)$thicknessRaw <= 0) $errors[] = 'Толщина должна быть числом больше 0.';
        if (!$errors) {
            $dupCheck = $pdo->prepare('SELECT id FROM panel_thicknesses WHERE thickness=:thickness AND id!=:id');
            $dupCheck->execute(['thickness'=>(float)$thicknessRaw,'id'=>$id]);
            if ($dupCheck->fetch()) $errors[] = 'Толщина '.rtrim(rtrim((string)(float)$thicknessRaw,'0'),'.').' мм уже существует.';
        }
        if (!$errors) {
            $params = ['thickness'=>(float)$thicknessRaw,'is_active'=>$isActive];
            if ($action === 'update_thickness' && $id > 0) { $params['id']=$id; $pdo->prepare('UPDATE panel_thicknesses SET thickness=:thickness,is_active=:is_active WHERE id=:id')->execute($params); header('Location: admin_panels.php?tab=thicknesses&edit='.$id); exit; }
            else { $pdo->prepare('INSERT INTO panel_thicknesses (thickness,is_active) VALUES (:thickness,:is_active)')->execute($params); header('Location: admin_panels.php?tab=thicknesses'); exit; }
        }
    }
}

// ═══ DATA LOADING ═══
$manufacturers = $pdo->query('SELECT * FROM manufacturers ORDER BY full_name ASC')->fetchAll();
$manufacturersJson = json_encode(array_column($manufacturers, null, 'id'), JSON_UNESCAPED_UNICODE);
$currencies = $pdo->query("SELECT * FROM currencies WHERE is_active = 1 ORDER BY code = 'RUB' DESC, code ASC")->fetchAll();
$embossings = $pdo->query('SELECT * FROM embossings WHERE is_active = 1 AND is_stock_program = 1 ORDER BY name ASC')->fetchAll();
$allEmbossings = $pdo->query('SELECT e.*, m.full_name AS manufacturer_name FROM embossings e LEFT JOIN manufacturers m ON m.id = e.manufacturer_id WHERE e.is_active = 1 AND e.is_stock_program = 1 ORDER BY e.name ASC')->fetchAll();
$embossingsJson = json_encode(array_column($allEmbossings, null, 'id'), JSON_UNESCAPED_UNICODE);
$panelSizes = $pdo->query('SELECT ps.*, m.full_name AS manufacturer_name FROM panel_sizes ps LEFT JOIN manufacturers m ON m.id = ps.manufacturer_id WHERE ps.is_active = 1 ORDER BY m.full_name, ps.height_mm, ps.width_mm')->fetchAll();
$panelSizesJson = json_encode(array_column($panelSizes, null, 'id'), JSON_UNESCAPED_UNICODE);
$thicknesses = $pdo->query('SELECT * FROM panel_thicknesses WHERE is_active = 1 ORDER BY thickness ASC')->fetchAll();
$formats = $pdo->query('SELECT pf.*, m.full_name AS manufacturer_name, em.name AS embossing_name FROM panel_formats pf LEFT JOIN manufacturers m ON m.id = pf.manufacturer_id LEFT JOIN embossings em ON em.id = pf.embossing_id ORDER BY pf.is_active DESC, pf.name ASC')->fetchAll();
$allEmbossingsList = $pdo->query('SELECT e.*, m.full_name AS manufacturer_name FROM embossings e LEFT JOIN manufacturers m ON m.id = e.manufacturer_id ORDER BY e.name ASC')->fetchAll();
$allSizes = $pdo->query('SELECT ps.*, m.full_name AS manufacturer_name FROM panel_sizes ps LEFT JOIN manufacturers m ON m.id = ps.manufacturer_id ORDER BY m.full_name, ps.height_mm, ps.width_mm')->fetchAll();
$allThicknesses = $pdo->query('SELECT * FROM panel_thicknesses ORDER BY thickness ASC')->fetchAll();

// EDITING STATE
if ($activeTab === 'panels') { $editId = (int)($_GET['edit'] ?? 0); if ($editId > 0) { $stmt = $pdo->prepare('SELECT * FROM panel_formats WHERE id = :id'); $stmt->execute(['id' => $editId]); $editing = $stmt->fetch() ?: null; } }
if ($activeTab === 'embossings') { $editId = (int)($_GET['edit'] ?? 0); if ($editId > 0) { $stmt = $pdo->prepare('SELECT * FROM embossings WHERE id = :id'); $stmt->execute(['id' => $editId]); $editing = $stmt->fetch() ?: null; } }
if ($activeTab === 'sizes') { $editId = (int)($_GET['edit'] ?? 0); if ($editId > 0) { $stmt = $pdo->prepare('SELECT * FROM panel_sizes WHERE id = :id'); $stmt->execute(['id' => $editId]); $editing = $stmt->fetch() ?: null; } }
if ($activeTab === 'thicknesses') { $editId = (int)($_GET['edit'] ?? 0); if ($editId > 0) { $stmt = $pdo->prepare('SELECT * FROM panel_thicknesses WHERE id = :id'); $stmt->execute(['id' => $editId]); $editing = $stmt->fetch() ?: null; } }

// SORT for embossings
$sortCol = in_array($_GET['sort'] ?? '', ['name','short_name','manufacturer_name','note']) ? ($_GET['sort']) : 'name';
$sortDir = ($_GET['dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
$sortDirNext = $sortDir === 'asc' ? 'desc' : 'asc';
$sortMap = ['name' => 'e.name', 'short_name' => 'e.short_name', 'manufacturer_name' => 'm.full_name', 'note' => 'e.note'];
$orderBy = $sortMap[$sortCol] . ' ' . strtoupper($sortDir);
$sortedEmbossings = $pdo->query("SELECT e.*, m.full_name AS manufacturer_name FROM embossings e LEFT JOIN manufacturers m ON m.id = e.manufacturer_id ORDER BY $orderBy")->fetchAll();

function sortLink(string $col, string $label, string $currentCol, string $currentDir, string $nextDir): string {
    $icon = $currentCol === $col ? ($currentDir === 'asc' ? ' ▲' : ' ▼') : '';
    $dir = $currentCol === $col ? $nextDir : 'asc';
    $activeTab = $_GET['tab'] ?? 'embossings';
    return '<a href="?tab=' . $activeTab . '&sort=' . $col . '&dir=' . $dir . '" style="color:inherit;text-decoration:none;">' . htmlspecialchars($label) . $icon . '</a>';
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<title>Панели</title>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;900&display=swap">
<style>
body{font-family:'Inter',Arial,sans-serif;background:linear-gradient(135deg,#eef7ff 0%,#f8fafc 42%,#f3f7f1 100%);margin:0;color:#1f2937}
.header{display:flex;align-items:center;gap:16px;flex-wrap:wrap;background:radial-gradient(circle at 10% 10%,rgba(59,130,246,.35),transparent 32%),linear-gradient(120deg,#0f172a,#1e3a8a 58%,#0f766e);color:#fff;padding:22px 36px 30px;box-shadow:0 18px 45px rgba(15,23,42,.18)}
.header a{color:#dbeafe;font-weight:700;text-decoration:none;margin-right:16px}
.header h1{margin:0;font-size:clamp(24px,3.5vw,36px);letter-spacing:-.02em;font-weight:900}
.container{max-width:1280px;margin:28px auto;padding:0 20px}
.panel{background:rgba(255,255,255,.92);border:1px solid rgba(203,213,225,.9);border-radius:18px;padding:22px;margin-bottom:24px;box-shadow:0 18px 50px rgba(15,23,42,.06);backdrop-filter:blur(12px)}
.section-title{font-size:15px;font-weight:700;color:#374151;background:#f1f5f9;border-left:4px solid #2563eb;padding:8px 12px;border-radius:0 6px 6px 0;margin:22px 0 14px 0}
.section-title.main{font-size:17px;background:#eff6ff;border-left-color:#1d4ed8;margin-top:0}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px}
.grid-2{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px}
label{display:block;font-weight:700;margin-bottom:6px;font-size:13px;color:#334155}
input,select,textarea{width:100%;box-sizing:border-box;padding:11px 12px;border:1px solid #cbd5e1;border-radius:12px;font-size:14px;background:#fff}
input:focus,select:focus,textarea:focus{outline:0;border-color:#60a5fa;box-shadow:0 0 0 4px rgba(96,165,250,.18)}
input[type="checkbox"]{width:auto}
input[readonly]{background:#f8fafc;color:#374151;cursor:default}
textarea{min-height:76px}
button,.button{border:0;border-radius:12px;padding:11px 16px;background:linear-gradient(135deg,#2563eb,#1d4ed8);color:#fff;text-decoration:none;cursor:pointer;display:inline-block;font-weight:800;box-shadow:0 10px 22px rgba(37,99,235,.22)}
.button.secondary,button.secondary{background:linear-gradient(135deg,#64748b,#475569);box-shadow:none}
button.danger{background:linear-gradient(135deg,#ef4444,#b91c1c)}
table{width:100%;border-collapse:collapse;background:#fff;border-radius:14px;overflow:hidden}
th,td{padding:12px;text-align:left;vertical-align:middle}
th{background:#edf6ff;color:#0f172a;font-size:12px;text-transform:uppercase;letter-spacing:.04em}
.errors{background:#fee2e2;color:#991b1b;padding:12px;border-radius:12px;margin-bottom:16px}
.actions{display:flex;gap:6px;align-items:center}
.btn-icon{display:inline-flex;align-items:center;justify-content:center;width:36px;height:36px;border-radius:12px;border:none;padding:0;margin:0;cursor:pointer;text-decoration:none;box-sizing:border-box;-webkit-appearance:none;transition:all .15s}
.btn-edit{background:#eff6ff;color:#2563eb}.btn-edit:hover{background:#2563eb;color:#fff}
.btn-delete{background:#fef2f2;color:#dc2626}.btn-delete:hover{background:#dc2626;color:#fff}
.btn-icon svg{display:block;flex-shrink:0}
.status,.price{font-weight:700}
.preview{max-width:96px;max-height:64px;border-radius:8px;border:1px solid #e5e7eb;object-fit:cover}
.hint{color:#64748b;font-size:13px;margin-top:4px}
.calc-hint{font-size:12px;color:#2563eb;margin-top:3px}
.decor-photo-wrap{display:flex;align-items:flex-start;gap:16px}
.decor-photo-wrap .photo-input-area{flex:1}
.photo-preview-box{width:120px;height:120px;border-radius:10px;border:2px dashed #cbd5e1;background:#f8fafc;display:flex;align-items:center;justify-content:center;overflow:hidden;flex-shrink:0}
.photo-preview-box img{max-width:100%;max-height:100%;object-fit:cover;border-radius:8px}
.photo-preview-box .no-photo{color:#94a3b8;font-size:12px;text-align:center;padding:8px}
.badge{display:inline-block;padding:2px 8px;border-radius:20px;font-size:12px;font-weight:600;cursor:pointer;transition:opacity .15s}
.badge:hover{opacity:.7}
.badge-yes{background:#dcfce7;color:#166534}
.badge-no{background:#f1f5f9;color:#64748b}
.form-actions{margin-top:20px;display:flex;gap:10px}
.quick-links{display:flex;gap:10px;margin-bottom:24px;flex-wrap:wrap}
.quick-link{padding:10px 18px;border-radius:12px;background:#fff;border:1px solid #e5e7eb;color:#374151;font-weight:700;font-size:14px;text-decoration:none;transition:all .15s;box-shadow:0 2px 6px rgba(15,23,42,.04)}
.quick-link:hover{background:#eff6ff;border-color:#93c5fd;color:#2563eb}
.quick-link.active{background:#2563eb;border-color:#2563eb;color:#fff}
.tab-pane{display:none}.tab-pane.active{display:block}
</style>
<?php echo app_header_styles(); ?>
</head>
<body>
<?php render_app_header(); ?>
<main class="container">
    <div class="quick-links">
        <a class="quick-link <?php echo $activeTab==='panels'?'active':'';?>" href="admin_panels.php?tab=panels">🪟 Панели</a>
        <a class="quick-link <?php echo $activeTab==='embossings'?'active':'';?>" href="admin_panels.php?tab=embossings">🏭 Тиснения</a>
        <a class="quick-link <?php echo $activeTab==='sizes'?'active':'';?>" href="admin_panels.php?tab=sizes">📐 Форматы</a>
        <a class="quick-link <?php echo $activeTab==='thicknesses'?'active':'';?>" href="admin_panels.php?tab=thicknesses">📏 Толщины</a>
    </div>

<?php if ($errors): ?><div class="errors"><?php echo e(implode(' ', $errors)); ?></div><?php endif; ?>

<!-- ════════════ ВКЛАДКА: ПАНЕЛИ ════════════ -->
<div class="tab-pane <?php echo $activeTab==='panels'?'active':''; ?>" id="tab-panels">
    <section class="panel">
        <h2><?php echo $editing ? 'Редактировать панель' : 'Добавить панель'; ?></h2>
        <form method="post" enctype="multipart/form-data" id="panel-form">
            <input type="hidden" name="action" value="<?php echo $editing ? 'update_panel' : 'create_panel'; ?>">
            <input type="hidden" name="id" value="<?php echo e((string)($editing['id'] ?? '')); ?>">
            <input type="hidden" id="price_source" name="price_source" value="m2">
            <div class="section-title main">Основная информация</div>
            <div class="grid">
                <div><label>Номенклатура</label><input name="nomenclature" value="<?php echo e((string)($editing['nomenclature'] ?? '')); ?>" placeholder="Артикул / код"></div>
                <div><label>Название</label><input name="name" required readonly value="<?php echo e((string)($editing['name'] ?? '')); ?>" placeholder="Заполняется автоматически"><div class="calc-hint">Производитель · Номер декора · Тиснение · Высота×Ширина×Толщина</div></div>
                <div><label>Производитель</label><select id="manufacturer_id" name="manufacturer_id"><option value="0">Не выбран</option><?php foreach ($manufacturers as $mfr): ?><option value="<?php echo e((string)$mfr['id']); ?>" <?php echo (int)($editing['manufacturer_id'] ?? 0)===(int)$mfr['id']?'selected':''; ?>><?php echo e($mfr['full_name']); ?></option><?php endforeach; ?></select></div>
                <div><label>Складская программа</label><select name="is_stock_program"><option value="0" <?php echo empty($editing['is_stock_program']) && empty($editing['is_stock_decor']) ? 'selected' : ''; ?>>Нет</option><option value="1" <?php echo (!empty($editing['is_stock_program']) || !empty($editing['is_stock_decor'])) ? 'selected' : ''; ?>>Да</option></select></div>
            </div>
            <div class="section-title">Размеры</div>
            <div class="grid">
                <div><label>Формат панели</label><select id="panel_size_id" name="panel_size_id"><option value="0">Не выбран</option><?php foreach ($panelSizes as $sz): $matchMfr = (int)($editing['manufacturer_id'] ?? 0) === 0 || (int)($sz['manufacturer_id'] ?? 0) === 0 || (int)($sz['manufacturer_id'] ?? 0) === (int)($editing['manufacturer_id'] ?? 0); if (!$matchMfr) continue; ?><option value="<?php echo e((string)$sz['id']); ?>" data-mfr="<?php echo e((string)($sz['manufacturer_id'] ?? 0)); ?>" data-h="<?php echo e((string)$sz['height_mm']); ?>" data-w="<?php echo e((string)$sz['width_mm']); ?>" <?php echo (int)($editing['panel_size_id'] ?? 0)===(int)$sz['id']?'selected':''; ?>><?php echo e($sz['height_mm'] . '×' . $sz['width_mm'] . ' мм' . ($sz['manufacturer_name'] ? ' (' . $sz['manufacturer_name'] . ')' : '')); ?></option><?php endforeach; ?></select></div>
                <div><label>Толщина панели</label><select id="thickness_id" name="thickness_id"><option value="0">Не выбрана</option><?php foreach ($thicknesses as $th): ?><option value="<?php echo e((string)$th['id']); ?>" data-t="<?php echo e(rtrim(rtrim((string)$th['thickness'],'0'),'.'));?>" <?php echo (int)($editing['thickness_id'] ?? 0)===(int)$th['id']?'selected':''; ?>><?php echo e(rtrim(rtrim((string)$th['thickness'],'0'),'.') . ' мм'); ?></option><?php endforeach; ?></select></div>
                <div><label>Вес м²</label><input id="weight_per_m2" type="number" step="0.0001" min="0" name="weight_per_m2" value="<?php echo e((string)($editing['weight_per_m2'] ?? '')); ?>"></div>
                <div><label>Вес панели</label><input id="weight" type="number" step="0.0001" min="0" name="weight" readonly value="<?php echo e((string)($editing['weight'] ?? '')); ?>"><div class="calc-hint">= Высота × Ширина × Вес м²</div></div>
                <div><label>Объём</label><input id="volume_m2" type="number" step="0.000001" min="0" name="volume_m2" readonly value="<?php echo e((string)($editing['volume_m2'] ?? '')); ?>"><div class="calc-hint">= Высота × Ширина</div></div>
            </div>
            <div class="section-title">Декор</div>
            <div class="grid-2">
                <div><label>Название декора</label><input id="decor_name" name="decor_name" value="<?php echo e((string)($editing['decor_name'] ?? '')); ?>"></div>
                <div><label>Номер декора</label><input id="decor_number" name="decor_number" maxlength="6" value="<?php echo e((string)($editing['decor_number'] ?? '')); ?>"></div>
                <div><label>Тиснение</label><select id="embossing_id" name="embossing_id"><option value="0">Не выбрано</option><?php foreach ($embossings as $emb): ?><option value="<?php echo e((string)$emb['id']); ?>" <?php echo (int)($editing['embossing_id'] ?? 0)===(int)$emb['id']?'selected':''; ?>><?php echo e($emb['name']); ?></option><?php endforeach; ?></select></div>
                <div><label>Направление декора</label><select name="decor_direction"><?php foreach ($decorDirections as $value => $label): ?><option value="<?php echo e($value); ?>" <?php echo ($editing['decor_direction'] ?? 'none')===$value?'selected':''; ?>><?php echo e($label); ?></option><?php endforeach; ?></select></div>
            </div>
            <div style="margin-top:14px;"><label>Фотография декора</label><div class="decor-photo-wrap"><div class="photo-preview-box"><img id="decor_photo_preview" src="<?php echo !empty($editing['decor_photo_path']) ? e($editing['decor_photo_path']) : ''; ?>" alt="" style="<?php echo empty($editing['decor_photo_path'])?'display:none;':''?>"><span class="no-photo" id="no-photo-label" style="<?php echo !empty($editing['decor_photo_path'])?'display:none;':''?>">Нет фото</span></div><div class="photo-input-area"><input id="decor_photo" type="file" name="decor_photo" accept=".jpg,.jpeg,.png,.tif,.tiff,.webp"><div class="hint">JPG, PNG, TIFF или WEBP. Максимум — 100 МБ.</div></div></div></div>
            <div class="section-title">Ценообразование</div>
            <div class="grid">
                <div><label>Себестоимость, м²</label><input id="cost" type="number" step="0.01" min="0" name="cost" value="<?php echo e((string)($editing['cost'] ?? '')); ?>"></div>
                <div><label>Себестоимость, лист</label><input id="cost_per_sheet" type="number" step="0.01" min="0" name="cost_per_sheet" value="<?php echo e((string)($editing['cost_per_sheet'] ?? '')); ?>"><div class="calc-hint">Пересчитывается автоматически</div></div>
                <div><label>Наценка, %</label><input id="markup" type="number" step="0.01" min="0" name="markup" value="<?php echo e((string)($editing['markup'] ?? '')); ?>"><div class="calc-hint" id="markup-hint"></div></div>
                <div><label>Цена, м²</label><input id="price_per_m2" type="number" step="0.01" min="0" name="price_per_m2" value="<?php echo e((string)($editing['price_per_m2'] ?? '')); ?>"><div class="calc-hint">Пересчитывается автоматически</div></div>
                <div><label>Цена, лист</label><input id="price_per_sheet" type="number" step="0.01" min="0" name="price_per_sheet" value="<?php echo e((string)($editing['price_per_sheet'] ?? '')); ?>"><div class="calc-hint">Пересчитывается автоматически</div></div>
                <div><label>Валюта</label><select id="currency" name="currency"><?php foreach ($currencies as $cr): ?><option value="<?php echo e($cr['code']); ?>" data-rate="<?php echo e((string)$cr['rate_to_rub']); ?>" <?php echo ($editing['currency'] ?? 'RUB')===$cr['code']?'selected':''; ?>><?php echo e($cr['code']); ?></option><?php endforeach; ?></select></div>
            </div>
            <p><label><input type="checkbox" name="is_active" <?php echo !isset($editing['is_active'])||(int)$editing['is_active']===1?'checked':''; ?>> Активна</label></p>
            <div class="form-actions"><button type="submit">Сохранить</button><?php if ($editing): ?><a class="button secondary" href="admin_panels.php?tab=panels">Отмена</a><?php endif; ?></div>
        </form>
    </section>
    <section class="panel">
        <h2>Список панелей</h2>
        <table><thead><tr><th>Панель</th><th>Размер</th><th>Фото</th><th>Себестоимость</th><th>Наценка</th><th>Цена м²</th><th>Цена лист</th><th>Статус</th><th>Действия</th></tr></thead><tbody>
        <?php foreach ($formats as $f): $h=(int)$f['height_mm'];$w=(int)$f['width_mm'];$t=$f['thickness_mm']!==null?rtrim(rtrim((string)$f['thickness_mm'],'0'),'.'):null;$sizeStr=$h.'×'.$w.($t!==null?'×'.$t:'').' мм'; ?>
            <tr><td><?php echo e($f['name']); ?></td><td style="white-space:nowrap;"><?php echo e($sizeStr); ?></td><td><?php if (!empty($f['decor_photo_path'])): ?><img class="preview" src="<?php echo e($f['decor_photo_path']); ?>" alt=""><?php else: ?>—<?php endif; ?></td><td><?php echo ($f['cost']??null)!==null?e(number_format((float)$f['cost'],2,',',' ')):'—'; ?></td><td><?php echo ($f['markup']??null)!==null?e(number_format((float)$f['markup'],2,',',' ')).' %':'—'; ?></td><td class="price"><?php echo $f['price_per_m2']!==null?e(number_format((float)$f['price_per_m2'],2,',',' ')).' '.e($f['currency']??'RUB'):'—'; ?></td><td class="price"><?php echo $f['price_per_sheet']!==null?e(number_format((float)$f['price_per_sheet'],2,',',' ')).' '.e($f['currency']??'RUB'):'—'; ?></td><td class="status"><?php echo (int)$f['is_active']===1?'Акт.':'Скрыт'; ?></td><td class="actions"><a class="btn-icon btn-edit" href="admin_panels.php?tab=panels&edit=<?php echo e((string)$f['id']); ?>" title="Изменить"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg></a><button class="btn-icon btn-delete" type="button" title="Удалить" onclick="deletePanel(<?php echo (int)$f['id']; ?>)"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg></button></td></tr>
        <?php endforeach; ?>
        <?php if (!$formats): ?><tr><td colspan="9">Панели пока не добавлены.</td></tr><?php endif; ?>
        </tbody></table>
    </section>
    <form id="delete-form" method="post" style="display:none;"><input type="hidden" name="action" value="delete_panel"><input type="hidden" name="id" id="delete-id"></form>
</div>

<!-- ════════════ ВКЛАДКА: ТИСНЕНИЯ ════════════ -->
<div class="tab-pane <?php echo $activeTab==='embossings'?'active':''; ?>" id="tab-embossings">
    <section class="panel">
        <h2><?php echo $editing ? 'Редактировать тиснение' : 'Добавить тиснение'; ?></h2>
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="action" value="<?php echo $editing ? 'update_embossing' : 'create_embossing'; ?>">
            <input type="hidden" name="id" value="<?php echo e((string)($editing['id'] ?? '')); ?>">
            <div class="section-title main">Основная информация</div>
            <div class="grid">
                <div><label>Полное название</label><input name="name" maxlength="100" required value="<?php echo e((string)($editing['name'] ?? '')); ?>" placeholder="Например, Файн-лайн"><div class="hint">До 100 символов.</div></div>
                <div><label>Краткое название</label><input name="short_name" maxlength="20" value="<?php echo e((string)($editing['short_name'] ?? '')); ?>" placeholder="Например, FH"><div class="hint">До 20 символов.</div></div>
                <div><label>Производитель</label><select name="manufacturer_id"><option value="0">Не выбран</option><?php foreach ($manufacturers as $mfr): ?><option value="<?php echo e((string)$mfr['id']); ?>" <?php echo (int)($editing['manufacturer_id'] ?? 0)===(int)$mfr['id']?'selected':''; ?>><?php echo e($mfr['full_name']); ?></option><?php endforeach; ?></select></div>
                <div><label>Складская программа</label><select name="is_stock_program"><option value="0" <?php echo empty($editing['is_stock_program'])?'selected':''; ?>>Нет</option><option value="1" <?php echo !empty($editing['is_stock_program'])?'selected':''; ?>>Да</option></select></div>
            </div>
            <div style="margin-top:14px;"><label>Изображение тиснения</label><div style="display:flex;align-items:flex-start;gap:16px;"><div class="photo-preview-box"><img id="emb_image_preview" src="<?php echo !empty($editing['image_path']) ? e($editing['image_path']) : ''; ?>" alt="" style="<?php echo empty($editing['image_path'])?'display:none;':''?>"><span class="no-photo" id="emb-no-label" style="<?php echo !empty($editing['image_path'])?'display:none;':''?>">Нет фото</span></div><div><input id="emb_image" type="file" name="emb_image" accept=".jpg,.jpeg,.png,.tif,.tiff,.webp"><div class="hint">JPG, PNG, TIFF или WEBP. Файл: Производитель_КраткоеНазвание.jpg</div></div></div></div>
            <p style="margin-top:14px;"><label>Примечание</label><textarea name="note"><?php echo e((string)($editing['note'] ?? '')); ?></textarea></p>
            <p><label><input type="checkbox" name="is_active" <?php echo !isset($editing['is_active'])||(int)$editing['is_active']===1?'checked':''; ?>> Активна</label></p>
            <div class="form-actions"><button type="submit">Сохранить</button><?php if ($editing): ?><a class="button secondary" href="admin_panels.php?tab=embossings">Отмена</a><?php endif; ?></div>
        </form>
    </section>
    <section class="panel">
        <h2>Список тиснений</h2>
        <table><thead><tr><th><?php echo sortLink('name','Полное название',$sortCol,$sortDir,$sortDirNext); ?></th><th><?php echo sortLink('short_name','Краткое',$sortCol,$sortDir,$sortDirNext); ?></th><th><?php echo sortLink('manufacturer_name','Производитель',$sortCol,$sortDir,$sortDirNext); ?></th><th>Фото</th><th><?php echo sortLink('note','Примечание',$sortCol,$sortDir,$sortDirNext); ?></th><th>Скл.</th><th>Акт.</th><th>Действия</th></tr></thead><tbody>
        <?php foreach ($sortedEmbossings as $emb): ?>
            <tr><td><?php echo e($emb['name']); ?></td><td><?php echo e((string)($emb['short_name'] ?? '—')); ?></td><td><?php echo e((string)($emb['manufacturer_name'] ?? '—')); ?></td><td><?php if (!empty($emb['image_path'])): ?><img class="preview" src="<?php echo e($emb['image_path']); ?>" alt=""><?php else: ?>—<?php endif; ?></td><td><?php echo e((string)($emb['note'] ?? '')); ?></td><td><span class="badge toggle-stock <?php echo (int)$emb['is_stock_program']?'badge-yes':'badge-no';?>" data-id="<?php echo (int)$emb['id'];?>" data-action="toggle_emb_stock"><?php echo (int)$emb['is_stock_program']?'Да':'Нет';?></span></td><td><span class="badge toggle-active <?php echo (int)$emb['is_active']?'badge-yes':'badge-no';?>" data-id="<?php echo (int)$emb['id'];?>" data-action="toggle_emb_active"><?php echo (int)$emb['is_active']?'Да':'Нет';?></span></td><td class="actions"><a class="btn-icon btn-edit" href="admin_panels.php?tab=embossings&edit=<?php echo e((string)$emb['id']); ?>" title="Изменить"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg></a><button class="btn-icon btn-delete" type="button" title="Удалить" onclick="document.getElementById('delete-id').value=<?php echo (int)$emb['id'];?>;document.querySelector('#delete-form input[name=action]').value='delete_embossing';document.getElementById('delete-form').submit();"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg></button></td></tr>
        <?php endforeach; ?>
        <?php if (!$sortedEmbossings): ?><tr><td colspan="8">Тиснения пока не добавлены.</td></tr><?php endif; ?>
        </tbody></table>
    </section>
</div>

<!-- ════════════ ВКЛАДКА: ФОРМАТЫ ════════════ -->
<div class="tab-pane <?php echo $activeTab==='sizes'?'active':''; ?>" id="tab-sizes">
    <section class="panel">
        <h2><?php echo $editing?'Редактировать формат':'Добавить формат';?></h2>
        <form method="post">
            <input type="hidden" name="action" value="<?php echo $editing?'update_size':'create_size';?>">
            <input type="hidden" name="id" value="<?php echo e((string)($editing['id']??''));?>">
            <div class="section-title main">Основная информация</div>
            <div class="grid">
                <div><label>Производитель</label><select name="manufacturer_id"><option value="0">Не выбран</option><?php foreach ($manufacturers as $mfr): ?><option value="<?php echo e((string)$mfr['id']); ?>" <?php echo (int)($editing['manufacturer_id']??0)===(int)$mfr['id']?'selected':''; ?>><?php echo e($mfr['full_name']);?></option><?php endforeach;?></select></div>
                <div><label>Складская программа</label><select name="is_stock_program"><option value="0" <?php echo empty($editing['is_stock_program'])?'selected':'';?>>Нет</option><option value="1" <?php echo !empty($editing['is_stock_program'])?'selected':'';?>>Да</option></select></div>
            </div>
            <div class="section-title">Размеры</div>
            <div class="grid">
                <div><label>Высота, мм</label><input id="sz_h" type="number" min="1" name="height_mm" required value="<?php echo e((string)($editing['height_mm']??''));?>"></div>
                <div><label>Ширина, мм</label><input id="sz_w" type="number" min="1" name="width_mm" required value="<?php echo e((string)($editing['width_mm']??''));?>"></div>
                <div><label>Объём, м²</label><input id="sz_v" type="number" step="0.000001" name="volume_m2" readonly value="<?php echo e((string)($editing['volume_m2']??''));?>"><div class="calc-hint">= Высота × Ширина</div></div>
            </div>
            <p style="margin-top:14px;"><label><input type="checkbox" name="is_active" <?php echo !isset($editing['is_active'])||(int)$editing['is_active']===1?'checked':'';?> > Активна</label></p>
            <div class="form-actions"><button type="submit">Сохранить</button><?php if($editing):?><a class="button secondary" href="admin_panels.php?tab=sizes">Отмена</a><?php endif;?></div>
        </form>
    </section>
    <section class="panel">
        <h2>Список форматов</h2>
        <table><thead><tr><th>Производитель</th><th>Высота</th><th>Ширина</th><th>Объём</th><th>Скл.</th><th>Акт.</th><th>Действия</th></tr></thead><tbody>
        <?php foreach ($allSizes as $sz): ?>
            <tr><td><?php echo e((string)($sz['manufacturer_name']??'—'));?></td><td><?php echo e((string)$sz['height_mm']);?></td><td><?php echo e((string)$sz['width_mm']);?></td><td><?php echo e(number_format((float)($sz['volume_m2']??0),4,',',' '));?></td><td><span class="badge toggle-stock <?php echo (int)$sz['is_stock_program']?'badge-yes':'no';?>" data-id="<?php echo (int)$sz['id'];?>" data-action="toggle_size_stock"><?php echo (int)$sz['is_stock_program']?'Да':'Нет';?></span></td><td><span class="badge toggle-active <?php echo (int)$sz['is_active']?'badge-yes':'badge-no';?>" data-id="<?php echo (int)$sz['id'];?>" data-action="toggle_size_active"><?php echo (int)$sz['is_active']?'Да':'Нет';?></span></td><td class="actions"><a class="btn-icon btn-edit" href="admin_panels.php?tab=sizes&edit=<?php echo e((string)$sz['id']);?>" title="Изменить"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg></a><button class="btn-icon btn-delete" type="button" title="Удалить" onclick="document.getElementById('delete-id').value=<?php echo (int)$sz['id'];?>;document.querySelector('#delete-form input[name=action]').value='delete_size';document.getElementById('delete-form').submit();"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg></button></td></tr>
        <?php endforeach;?>
        <?php if(!$allSizes):?><tr><td colspan="7">Форматы пока не добавлены.</td></tr><?php endif;?>
        </tbody></table>
    </section>
</div>

<!-- ════════════ ВКЛАДКА: ТОЛЩИНЫ ════════════ -->
<div class="tab-pane <?php echo $activeTab==='thicknesses'?'active':''; ?>" id="tab-thicknesses">
    <section class="panel">
        <h2><?php echo $editing?'Редактировать толщину':'Добавить толщину';?></h2>
        <form method="post">
            <input type="hidden" name="action" value="<?php echo $editing?'update_thickness':'create_thickness';?>">
            <input type="hidden" name="id" value="<?php echo e((string)($editing['id']??''));?>">
            <div class="section-title main">Параметры</div>
            <div class="grid">
                <div><label>Толщина, мм</label><input type="number" step="0.01" min="0.01" name="thickness" required value="<?php echo e((string)($editing['thickness']??''));?>" placeholder="Например, 6, 8, 10, 12"></div>
            </div>
            <p style="margin-top:14px;"><label><input type="checkbox" name="is_active" <?php echo !isset($editing['is_active'])||(int)$editing['is_active']===1?'checked':'';?> > Активна</label></p>
            <div class="form-actions"><button type="submit">Сохранить</button><?php if($editing):?><a class="button secondary" href="admin_panels.php?tab=thicknesses">Отмена</a><?php endif;?></div>
        </form>
    </section>
    <section class="panel">
        <h2>Список толщин</h2>
        <table><thead><tr><th>Толщина</th><th>Активно</th><th>Действия</th></tr></thead><tbody>
        <?php foreach ($allThicknesses as $t): ?>
            <tr><td><?php echo e(rtrim(rtrim((string)$t['thickness'],'0'),'.'));?> мм</td><td><span class="badge toggle-active <?php echo (int)$t['is_active']?'badge-yes':'badge-no';?>" data-id="<?php echo (int)$t['id'];?>" data-action="toggle_th_active"><?php echo (int)$t['is_active']?'Да':'Нет';?></span></td><td class="actions"><a class="btn-icon btn-edit" href="admin_panels.php?tab=thicknesses&edit=<?php echo e((string)$t['id']);?>" title="Изменить"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg></a><button class="btn-icon btn-delete" type="button" title="Удалить" onclick="document.getElementById('delete-id').value=<?php echo (int)$t['id'];?>;document.querySelector('#delete-form input[name=action]').value='delete_thickness';document.getElementById('delete-form').submit();"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg></button></td></tr>
        <?php endforeach;?>
        <?php if(!$allThicknesses):?><tr><td colspan="3">Толщины пока не добавлены.</td></tr><?php endif;?>
        </tbody></table>
    </section>
</div>

<!-- Общая форма удаления -->
<form id="delete-form" method="post" style="display:none;"><input type="hidden" name="action" value="delete_panel"><input type="hidden" name="id" id="delete-id"></form>

</main>
<script>
function deletePanel(id){if(!confirm('Удалить панель?'))return;document.getElementById('delete-id').value=id;document.getElementById('delete-form').submit();}
document.querySelectorAll('.badge.toggle-active, .badge.toggle-stock').forEach(function(badge){
    badge.addEventListener('click',function(){
        var self=this;var act=self.dataset.action;var id=self.dataset.id;
        fetch('admin_panels.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action='+act+'&id='+id}).then(function(){
            var wasYes=self.textContent.trim()==='Да';
            self.textContent=wasYes?'Нет':'Да';
            self.classList.toggle('badge-yes',!wasYes);
            self.classList.toggle('badge-no',wasYes);
        });
    });
});
const MANUFACTURERS=<?php echo $manufacturersJson;?>;
const EMBOSSINGS=<?php echo $embossingsJson;?>;
const PANEL_SIZES=<?php echo $panelSizesJson;?>;
const panelSizeSel=document.getElementById('panel_size_id'),thicknessSel=document.getElementById('thickness_id'),nameInput=document.querySelector('[name="name"]'),manufacturerSel=document.getElementById('manufacturer_id'),embossingSel=document.getElementById('embossing_id'),decorNumberInput=document.getElementById('decor_number'),priceM2Input=document.getElementById('price_per_m2'),priceSheetInput=document.getElementById('price_per_sheet'),priceSourceInput=document.getElementById('price_source'),currencyInput=document.getElementById('currency'),costInput=document.getElementById('cost'),costSheetInput=document.getElementById('cost_per_sheet'),markupInput=document.getElementById('markup'),markupHint=document.getElementById('markup-hint'),photoInput=document.getElementById('decor_photo'),photoPreview=document.getElementById('decor_photo_preview'),noPhotoLabel=document.getElementById('no-photo-label'),weightPerM2Input=document.getElementById('weight_per_m2'),weightInput=document.getElementById('weight'),volumeInput=document.getElementById('volume_m2');
let currentCurrencyRate=parseFloat(currencyInput?.selectedOptions[0]?.dataset.rate||'1')||1,currentCurrencyCode=currencyInput?.value||'RUB',lastPriceSource='m2';
function getSelectedSize(){const opt=panelSizeSel?.selectedOptions[0];if(!opt||opt.value==='0')return{w:0,h:0};return{w:parseFloat(opt.dataset.w)||0,h:parseFloat(opt.dataset.h)||0}}
function getSelectedThickness(){const opt=thicknessSel?.selectedOptions[0];if(!opt||opt.value==='0')return'';return opt.dataset.t||''}
function areaM2(){const{w,h}=getSelectedSize();return w>0&&h>0?(w*h)/1000000:0}
function fmt(v){return Number.isFinite(v)?Math.round(v*100)/100:'';}
function fmtHigh(v){return Number.isFinite(v)?Math.round(v*1000000)/1000000:'';}
function filterPanelSizes(){const mfrId=parseInt(manufacturerSel?.value)||0;Array.from(panelSizeSel?.options||[]).forEach(opt=>{if(opt.value==='0')return;const optMfr=parseInt(opt.dataset.mfr)||0;opt.hidden=mfrId>0&&optMfr>0&&optMfr!==mfrId;});const cur=panelSizeSel?.selectedOptions[0];if(cur&&cur.hidden)panelSizeSel.value='0';}
function filterEmbossings(){if(!embossingSel)return;const mfrId=parseInt(manufacturerSel?.value)||0;const savedVal=embossingSel.value;while(embossingSel.options.length>1)embossingSel.remove(1);Object.values(EMBOSSINGS).forEach(emb=>{const embMfr=parseInt(emb.manufacturer_id)||0;if(mfrId>0&&embMfr>0&&embMfr!==mfrId)return;const opt=document.createElement('option');opt.value=emb.id;opt.textContent=emb.name+(emb.short_name?' ('+emb.short_name+')':'');if(String(emb.id)===String(savedVal))opt.selected=true;embossingSel.appendChild(opt);});}
function buildAutoName(){const mfrId=parseInt(manufacturerSel?.value)||0;const embId=parseInt(embossingSel?.value)||0;const dNum=(decorNumberInput?.value||'').trim();const{h,w}=getSelectedSize();const t=getSelectedThickness();const parts=[];if(mfrId&&MANUFACTURERS[mfrId])parts.push(MANUFACTURERS[mfrId].full_name);if(dNum)parts.push(dNum);if(embId&&EMBOSSINGS[embId])parts.push(EMBOSSINGS[embId].short_name||EMBOSSINGS[embId].name);if(h&&w)parts.push(t?`(${h}*${w}*${t})`:`(${h}*${w})`);if(nameInput)nameInput.value=parts.join(' ');}
function recalcWeight(){const area=areaM2(),wpm2=parseFloat(weightPerM2Input?.value);weightInput.value=(area>0&&Number.isFinite(wpm2))?fmtHigh(area*wpm2):'';}
function recalcVolume(){const area=areaM2();volumeInput.value=area>0?fmtHigh(area):'';}
function recalcAll(){recalcWeight();recalcVolume();recalcCostSheet();if(lastPriceSource==='sheet')recalcM2();else recalcSheet();buildAutoName();}
function recalcSheet(){const area=areaM2();if(area<=0)return;const pm2=parseFloat(priceM2Input.value);if(Number.isFinite(pm2))priceSheetInput.value=fmt(pm2*area);}
function recalcM2(){const area=areaM2();if(area<=0)return;const ps=parseFloat(priceSheetInput.value);if(Number.isFinite(ps))priceM2Input.value=fmt(ps/area);}
function recalcCostSheet(){const area=areaM2();const c=parseFloat(costInput.value);if(area>0&&Number.isFinite(c))costSheetInput.value=fmt(c*area);}
function recalcCostM2(){const area=areaM2();const cs=parseFloat(costSheetInput.value);if(area>0&&Number.isFinite(cs))costInput.value=fmt(cs/area);}
function recalcFromCostMarkup(){const cost=parseFloat(costInput.value),markup=parseFloat(markupInput.value);if(Number.isFinite(cost)&&Number.isFinite(markup)){priceM2Input.value=fmt(cost*(1+markup/100));lastPriceSource='m2';priceSourceInput.value='m2';recalcSheet();}}
function recalcMarkupFromPriceM2(){const cost=parseFloat(costInput.value),pm2=parseFloat(priceM2Input.value);if(Number.isFinite(cost)&&cost>0&&Number.isFinite(pm2)){const m=fmt((pm2/cost-1)*100);markupInput.value=m>=0?m:'';if(markupHint)markupHint.textContent='Наценка: '+m+'%';}}
function recalcMarkupFromPriceSheet(){const area=areaM2(),cost=parseFloat(costInput.value),ps=parseFloat(priceSheetInput.value);if(area>0&&Number.isFinite(cost)&&cost>0&&Number.isFinite(ps)){const pm2=ps/area,m=fmt((pm2/cost-1)*100);markupInput.value=m>=0?m:'';priceM2Input.value=fmt(pm2);if(markupHint)markupHint.textContent='Наценка: '+m+'%';}}
manufacturerSel?.addEventListener('change',()=>{filterPanelSizes();filterEmbossings();recalcAll();});
panelSizeSel?.addEventListener('change',recalcAll);thicknessSel?.addEventListener('change',buildAutoName);weightPerM2Input?.addEventListener('input',recalcWeight);decorNumberInput?.addEventListener('input',buildAutoName);embossingSel?.addEventListener('change',buildAutoName);
costInput?.addEventListener('input',()=>{recalcCostSheet();recalcFromCostMarkup();});
costSheetInput?.addEventListener('input',()=>{recalcCostM2();recalcFromCostMarkup();});
markupInput?.addEventListener('input',()=>{recalcFromCostMarkup();if(markupHint)markupHint.textContent='Наценка: '+markupInput.value+'%';});
priceM2Input?.addEventListener('input',()=>{lastPriceSource='m2';priceSourceInput.value='m2';recalcSheet();recalcMarkupFromPriceM2();});
priceSheetInput?.addEventListener('input',()=>{lastPriceSource='sheet';priceSourceInput.value='sheet';recalcM2();recalcMarkupFromPriceSheet();});
function convertPanelAmounts(newCode,converter=null){if(!currencyInput||!newCode||currentCurrencyCode===newCode)return;const option=Array.from(currencyInput.options).find(opt=>opt.value===newCode);if(!option)return;const oldCode=currentCurrencyCode,oldRate=currentCurrencyRate,newRate=parseFloat(option.dataset.rate||'1')||1;[priceM2Input,priceSheetInput,costInput,costSheetInput].forEach(inp=>{const v=parseFloat(inp?.value);if(Number.isFinite(v))inp.value=fmt(typeof converter==='function'?converter(v,oldCode,newCode):v*oldRate/newRate);});currencyInput.value=newCode;currentCurrencyCode=newCode;currentCurrencyRate=newRate;}
currencyInput?.addEventListener('change',event=>convertPanelAmounts(event.target.value));
window.addEventListener('appcurrencychange',event=>convertPanelAmounts(event.detail?.code,event.detail?.convert));
photoInput?.addEventListener('change',()=>{const file=photoInput.files?.[0];if(!file||!file.type.startsWith('image/')){photoPreview.style.display='none';if(noPhotoLabel)noPhotoLabel.style.display='block';return;}photoPreview.src=URL.createObjectURL(file);photoPreview.style.display='block';if(noPhotoLabel)noPhotoLabel.style.display='none';});
document.getElementById('emb_image')?.addEventListener('change',function(){const file=this.files?.[0];const prev=document.getElementById('emb_image_preview');const lbl=document.getElementById('emb-no-label');if(!file||!file.type.startsWith('image/')){prev.style.display='none';if(lbl)lbl.style.display='block';return;}prev.src=URL.createObjectURL(file);prev.style.display='block';if(lbl)lbl.style.display='none';});
const szH=document.getElementById('sz_h'),szW=document.getElementById('sz_w'),szV=document.getElementById('sz_v');
if(szH&&szW&&szV){function recalcSz(){const h=parseFloat(szH.value)||0,w=parseFloat(szW.value)||0;szV.value=(h>0&&w>0)?Math.round(h*w/1e6*1e6)/1e6:'';}szH.addEventListener('input',recalcSz);szW.addEventListener('input',recalcSz);}
filterPanelSizes();filterEmbossings();
</script>
</body>
</html>
