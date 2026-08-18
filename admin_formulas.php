<?php
require_once __DIR__ . '/includes/admin_auth.php';
require_once __DIR__ . '/includes/app_header.php';
require_admin();
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/admin_schema.php';

ensure_countertop_settings_table($pdo);
ensure_subsystem_tables($pdo);

$pdo->exec("CREATE TABLE IF NOT EXISTS partition_formulas (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    partition_type_id INT NULL,
    name VARCHAR(150) NOT NULL,
    target_key VARCHAR(60) NOT NULL COMMENT 'что считаем: например material_area, hardware_hinges',
    formula TEXT NOT NULL COMMENT 'формула на простых операторах, использует ключи полей',
    unit VARCHAR(30) NULL,
    note TEXT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$activeTab = $_GET['tab'] ?? 'countertop';
$validTabs = ['countertop', 'subsystem', 'partition'];
if (!in_array($activeTab, $validTabs, true)) $activeTab = 'countertop';

$errors = [];
$saved = false;

// ═══ SUBSYSTEM AJAX HANDLER (JSON responses) ═══
$ajaxActions = ['add_enclosure','delete_enclosure','update_enclosure_fastener','update_material','add_material','delete_material','add_fastener','update_fastener','delete_fastener','add_sub_item','update_sub_item','delete_sub_item'];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && in_array($_POST['action'], $ajaxActions, true)) {
    header('Content-Type: application/json');
    $action = $_POST['action'];

    if ($action === 'add_enclosure') {
        $name = trim($_POST['name'] ?? '');
        $fastenerId = (int)($_POST['fastener_id'] ?? 0);
        if ($name !== '' && $fastenerId > 0) {
            $maxSort = $pdo->query('SELECT COALESCE(MAX(sort_order),0) FROM subsystem_enclosures')->fetchColumn();
            $stmt = $pdo->prepare('INSERT INTO subsystem_enclosures (name, fastener_id, sort_order) VALUES (?, ?, ?)');
            $stmt->execute([$name, $fastenerId, $maxSort + 1]);
        }
        echo json_encode(['ok' => true]); exit;
    }
    if ($action === 'delete_enclosure') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare('DELETE FROM subsystem_enclosures WHERE id = ?')->execute([$id]);
        echo json_encode(['ok' => true]); exit;
    }
    if ($action === 'update_enclosure_fastener') {
        $id = (int)($_POST['id'] ?? 0);
        $fastenerId = (int)($_POST['fastener_id'] ?? 0);
        if ($fastenerId > 0) {
            $pdo->prepare('UPDATE subsystem_enclosures SET fastener_id = ? WHERE id = ?')->execute([$fastenerId, $id]);
        }
        echo json_encode(['ok' => true]); exit;
    }
    if ($action === 'update_material') {
        $id = (int)($_POST['id'] ?? 0);
        $field = $_POST['field'] ?? '';
        $value = $_POST['value'] ?? '';
        $allowed = ['unit','quantity_per_piece','price_per_piece','price_per_unit','consumption_per_m','consumption_unit','currency_id','supplier_id'];
        if (in_array($field, $allowed, true)) {
            $val = in_array($field, ['unit','consumption_unit'], true) ? $value : (float)$value;
            $stmt = $pdo->prepare("UPDATE subsystem_materials SET {$field} = ? WHERE id = ?");
            $stmt->execute([$val, $id]);
        }
        echo json_encode(['ok' => true]); exit;
    }
    if ($action === 'add_material') {
        $name = trim($_POST['new_material_name'] ?? '');
        $unit = trim($_POST['new_material_unit'] ?? 'шт.');
        $currencyId = (int)($_POST['currency_id'] ?? 0);
        $supplierId = (int)($_POST['supplier_id'] ?? 0);
        if ($name !== '') {
            $maxSort = $pdo->query('SELECT COALESCE(MAX(sort_order),0) FROM subsystem_materials')->fetchColumn();
            $stmt = $pdo->prepare('INSERT INTO subsystem_materials (name, unit, quantity_per_piece, price_per_piece, price_per_unit, consumption_per_m, currency_id, supplier_id, sort_order) VALUES (?, ?, 1, 0, 0, 0, ?, ?, ?)');
            $stmt->execute([$name, $unit, $currencyId ?: null, $supplierId ?: null, $maxSort + 1]);
        }
        echo json_encode(['ok' => true]); exit;
    }
    if ($action === 'delete_material') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare('DELETE FROM subsystem_materials WHERE id = ?')->execute([$id]);
        echo json_encode(['ok' => true]); exit;
    }
    if ($action === 'add_fastener') {
        $name = trim($_POST['new_fastener_name'] ?? '');
        $unit = trim($_POST['new_fastener_unit'] ?? 'шт.');
        $qty = (float)($_POST['new_fastener_qty'] ?? 1);
        $price = (float)($_POST['new_fastener_price'] ?? 0);
        $currencyId = (int)($_POST['currency_id'] ?? 0);
        $supplierId = (int)($_POST['supplier_id'] ?? 0);
        if ($name !== '') {
            $maxSort = $pdo->query('SELECT COALESCE(MAX(sort_order),0) FROM subsystem_fasteners')->fetchColumn();
            $stmt = $pdo->prepare('INSERT INTO subsystem_fasteners (name, unit, quantity_per_unit, price_per_unit, currency_id, supplier_id, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([$name, $unit, $qty, $price, $currencyId ?: null, $supplierId ?: null, $maxSort + 1]);
        }
        echo json_encode(['ok' => true]); exit;
    }
    if ($action === 'update_fastener') {
        $id = (int)($_POST['id'] ?? 0);
        $field = $_POST['field'] ?? '';
        $value = $_POST['value'] ?? '';
        $allowed = ['unit','quantity_per_unit','price_per_unit','currency_id','supplier_id','consumption_per_m','consumption_unit'];
        if (in_array($field, $allowed, true)) {
            $val = in_array($field, ['unit','consumption_unit'], true) ? $value : (float)$value;
            $stmt = $pdo->prepare("UPDATE subsystem_fasteners SET {$field} = ? WHERE id = ?");
            $stmt->execute([$val, $id]);
        }
        echo json_encode(['ok' => true]); exit;
    }
    if ($action === 'delete_fastener') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare('DELETE FROM subsystem_fasteners WHERE id = ?')->execute([$id]);
        echo json_encode(['ok' => true]); exit;
    }
    if ($action === 'add_sub_item') {
        $supplierId = (int)($_POST['supplier_id'] ?? 0);
        $name = trim($_POST['new_sub_item_name'] ?? '');
        $unit = trim($_POST['new_sub_item_unit'] ?? 'шт.');
        $currencyId = (int)($_POST['currency_id'] ?? 0);
        $widthMm = (float)($_POST['width_mm'] ?? 0);
        $thicknessMm = (float)($_POST['thickness_mm'] ?? 0);
        if ($name !== '') {
            $maxSort = $pdo->query('SELECT COALESCE(MAX(sort_order),0) FROM subsystem_sub_items WHERE supplier_id=' . $supplierId)->fetchColumn();
            $stmt = $pdo->prepare('INSERT INTO subsystem_sub_items (name, unit, supplier_id, currency_id, width_mm, thickness_mm, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([$name, $unit, $supplierId ?: null, $currencyId ?: null, $widthMm, $thicknessMm, $maxSort + 1]);
        }
        echo json_encode(['ok' => true]); exit;
    }
    if ($action === 'update_sub_item') {
        $id = (int)($_POST['id'] ?? 0);
        $field = $_POST['field'] ?? '';
        $value = $_POST['value'] ?? '';
        $allowed = ['name','unit','quantity_per_piece','price_per_piece','price_per_unit','currency_id','supplier_id','width_mm','thickness_mm'];
        if (in_array($field, $allowed, true)) {
            $val = in_array($field, ['name','unit'], true) ? $value : (float)$value;
            $stmt = $pdo->prepare("UPDATE subsystem_sub_items SET {$field} = ? WHERE id = ?");
            $stmt->execute([$val, $id]);
        }
        echo json_encode(['ok' => true]); exit;
    }
    if ($action === 'delete_sub_item') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare('DELETE FROM subsystem_sub_items WHERE id = ?')->execute([$id]);
        echo json_encode(['ok' => true]); exit;
    }

    echo json_encode(['ok' => false]); exit;
}

// ═══ POST HANDLING (non-AJAX) ═══
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── COUNTERTOP: save_global ──
    if ($action === 'save_global') {
        $kerf = trim($_POST['kerf_mm'] ?? '');
        $blankWidth = trim($_POST['blank_width_mm'] ?? '');
        if ($kerf === '' || !is_numeric($kerf) || (float)$kerf < 0) $errors[] = 'Толщина реза: некорректное значение.';
        if ($blankWidth === '' || !is_numeric($blankWidth) || (int)$blankWidth <= 0) $errors[] = 'Ширина заготовки: некорректное значение.';
        if (!$errors) {
            $stmt = $pdo->prepare('UPDATE countertop_settings SET kerf_mm = :kerf, blank_width_mm = :bw WHERE id = 1');
            $stmt->execute(['kerf' => (float)$kerf, 'bw' => (int)$blankWidth]);
            $saved = true;
        }
    }

    // ── COUNTERTOP: save_product_type ──
    if ($action === 'save_product_type') {
        $typeKey = $_POST['type_key'] ?? '';
        $processingPerM = trim($_POST['processing_per_m'] ?? '');
        $minW = trim($_POST['min_width'] ?? '');
        $maxW = trim($_POST['max_width'] ?? '');
        $minL = trim($_POST['min_length'] ?? '');
        $maxL = trim($_POST['max_length'] ?? '');
        if (!preg_match('/^[a-z]+$/', $typeKey)) $errors[] = 'Некорректный ключ типа.';
        if (!is_numeric($processingPerM) || (float)$processingPerM < 0) $errors[] = 'Стоимость обработки: некорректное значение.';
        if (!is_numeric($minW) || (int)$minW < 0) $errors[] = 'Мин. ширина: некорректное значение.';
        if (!is_numeric($maxW) || (int)$maxW <= 0) $errors[] = 'Макс. ширина: некорректное значение.';
        if (!is_numeric($minL) || (int)$minL < 0) $errors[] = 'Мин. длина: некорректное значение.';
        if (!is_numeric($maxL) || (int)$maxL <= 0) $errors[] = 'Макс. длина: некорректное значение.';
        if (!$errors) {
            $stmt = $pdo->prepare('UPDATE countertop_product_types SET processing_per_m = :ppm, min_width = :mw, max_width = :xw, min_length = :ml, max_length = :xl WHERE type_key = :tk');
            $stmt->execute(['ppm' => (float)$processingPerM, 'mw' => (int)$minW, 'xw' => (int)$maxW, 'ml' => (int)$minL, 'xl' => (int)$maxL, 'tk' => $typeKey]);
            $saved = true;
        }
    }

    // ── PARTITION: delete_formula ──
    if ($action === 'delete_formula') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) $pdo->prepare('DELETE FROM partition_formulas WHERE id=:id')->execute(['id'=>$id]);
        header('Location: admin_formulas.php?tab=partition'); exit;
    }

    // ── PARTITION: create_formula / update_formula ──
    if (in_array($action, ['create_formula','update_formula'])) {
        $id = (int)($_POST['id'] ?? 0);
        $typeId = (int)($_POST['partition_type_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $targetKey = trim($_POST['target_key'] ?? '');
        $formula = trim($_POST['formula'] ?? '');
        $unit = trim($_POST['unit'] ?? '');
        $note = trim($_POST['note'] ?? '');
        $sortOrder = (int)($_POST['sort_order'] ?? 0);
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        if ($name === '') $errors[] = 'Укажите название формулы.';
        if ($targetKey === '') $errors[] = 'Укажите ключ результата (что считаем).';
        if ($formula === '') $errors[] = 'Укажите саму формулу.';
        if ($formula !== '' && !preg_match('/^[a-zA-Z0-9_\.\+\-\*\/\(\)\s,<>=!?:]+$/u', $formula)) {
            $errors[] = 'Формула содержит недопустимые символы. Разрешены: буквы, цифры, + - * / ( ) . , пробелы, операторы сравнения.';
        }
        if (!$errors) {
            $params = ['partition_type_id'=>$typeId>0?$typeId:null,'name'=>$name,'target_key'=>$targetKey,'formula'=>$formula,'unit'=>$unit===''?null:$unit,'note'=>$note===''?null:$note,'sort_order'=>$sortOrder,'is_active'=>$isActive];
            if ($action === 'update_formula' && $id > 0) {
                $params['id'] = $id;
                $pdo->prepare('UPDATE partition_formulas SET partition_type_id=:partition_type_id,name=:name,target_key=:target_key,formula=:formula,unit=:unit,note=:note,sort_order=:sort_order,is_active=:is_active WHERE id=:id')->execute($params);
            } else {
                $pdo->prepare('INSERT INTO partition_formulas (partition_type_id,name,target_key,formula,unit,note,sort_order,is_active) VALUES (:partition_type_id,:name,:target_key,:formula,:unit,:note,:sort_order,:is_active)')->execute($params);
            }
            header('Location: admin_formulas.php?tab=partition'); exit;
        }
    }
}

// ═══ DATA LOADING: COUNTERTOP ═══
$ctSettings = $pdo->query('SELECT kerf_mm, blank_width_mm FROM countertop_settings WHERE id = 1')->fetch();
$kerfMm = $ctSettings ? (string)$ctSettings['kerf_mm'] : '4.0';
$blankWidthMm = $ctSettings ? (string)$ctSettings['blank_width_mm'] : '600';
$productTypes = $pdo->query('SELECT * FROM countertop_product_types ORDER BY FIELD(type_key, "kitchen","fartuk","horeca","bortik")')->fetchAll();
$countertopSubTab = $_GET['subtype'] ?? 'kitchen';
$countertopType = null;
foreach ($productTypes as $pt) {
    if ($pt['type_key'] === $countertopSubTab) { $countertopType = $pt; break; }
}
if (!$countertopType && !empty($productTypes)) { $countertopType = $productTypes[0]; $countertopSubTab = $countertopType['type_key']; }

// ═══ DATA LOADING: SUBSYSTEM ═══
$subEnclosures = $pdo->query('SELECT e.*, e.fastener_id, f.name AS fastener_name FROM subsystem_enclosures e LEFT JOIN subsystem_fasteners f ON f.id = e.fastener_id ORDER BY e.sort_order, e.id')->fetchAll();
$subMaterials = $pdo->query('SELECT * FROM subsystem_materials ORDER BY supplier_id, sort_order, id')->fetchAll();
$subFasteners = $pdo->query('SELECT * FROM subsystem_fasteners ORDER BY sort_order, id')->fetchAll();
$subSuppliers = $pdo->query('SELECT * FROM suppliers ORDER BY company_name ASC')->fetchAll();
$subItems = $pdo->query('SELECT * FROM subsystem_sub_items ORDER BY supplier_id, sort_order, id')->fetchAll();
$subCurrencies = $pdo->query('SELECT id, code, name, nominal, rate_to_rub FROM currencies WHERE is_active = 1 ORDER BY code')->fetchAll();
$subUnits = $pdo->query('SELECT id, short_name, full_name FROM measurement_units WHERE is_active = 1 ORDER BY full_name ASC')->fetchAll();
$rubId = $pdo->query("SELECT id FROM currencies WHERE code = 'RUB' LIMIT 1")->fetchColumn() ?: 0;
$subCurrenciesJson = json_encode($subCurrencies);
$subUnitsJson = json_encode($subUnits);

// ═══ DATA LOADING: PARTITION ═══
$partitionEditing = null;
$editId = (int)($_GET['edit'] ?? 0);
if ($activeTab === 'partition' && $editId > 0) {
    $s = $pdo->prepare('SELECT * FROM partition_formulas WHERE id=:id');
    $s->execute(['id'=>$editId]);
    $partitionEditing = $s->fetch() ?: null;
}
$partitionTypes = $pdo->query('SELECT * FROM partition_types ORDER BY name ASC')->fetchAll();
$partitionFormulas = $pdo->query('SELECT f.*, pt.name AS type_name FROM partition_formulas f LEFT JOIN partition_types pt ON pt.id=f.partition_type_id ORDER BY pt.name, f.sort_order, f.id')->fetchAll();
$typeFields = $pdo->query('SELECT f.*, pt.name AS type_name FROM partition_type_fields f LEFT JOIN partition_types pt ON pt.id=f.partition_type_id ORDER BY pt.name, f.sort_order')->fetchAll();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<title>Формулы расчёта</title>
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
.grid-3{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:14px}
label{display:block;font-weight:700;margin-bottom:6px;font-size:13px;color:#334155}
input,select,textarea{width:100%;box-sizing:border-box;padding:11px 12px;border:1px solid #cbd5e1;border-radius:12px;font-size:14px;background:#fff}
input:focus,select:focus,textarea:focus{outline:0;border-color:#60a5fa;box-shadow:0 0 0 4px rgba(96,165,250,.18)}
input[type="checkbox"]{width:auto}
input[readonly]{background:#f8fafc;color:#374151;cursor:default}
textarea{min-height:76px}
button,.button{border:0;border-radius:12px;padding:11px 16px;background:linear-gradient(135deg,#2563eb,#1d4ed8);color:#fff;text-decoration:none;cursor:pointer;display:inline-block;font-weight:800;box-shadow:0 10px 22px rgba(37,99,235,.22)}
.button.secondary,button.secondary{background:linear-gradient(135deg,#64748b,#475569);box-shadow:none}
button.danger,button.btn-danger{background:linear-gradient(135deg,#ef4444,#b91c1c)}
button.btn-success{background:linear-gradient(135deg,#16a34a,#15803d)}
button.btn-sm{padding:6px 12px;font-size:12px;border-radius:10px}
table{width:100%;border-collapse:collapse;background:#fff;border-radius:14px;overflow:hidden}
th,td{padding:12px;text-align:left;vertical-align:middle}
th{background:#edf6ff;color:#0f172a;font-size:12px;text-transform:uppercase;letter-spacing:.04em}
.errors{background:#fee2e2;color:#991b1b;padding:12px;border-radius:12px;margin-bottom:16px}
.success{background:#dcfce7;color:#166534;border:1px solid #bbf7d0;padding:12px 16px;border-radius:12px;margin-bottom:16px;font-size:14px}
.actions{display:flex;gap:6px;align-items:center}
.btn-icon{display:inline-flex;align-items:center;justify-content:center;width:36px;height:36px;border-radius:12px;border:none;padding:0;margin:0;cursor:pointer;text-decoration:none;box-sizing:border-box;-webkit-appearance:none;transition:all .15s}
.btn-edit{background:#eff6ff;color:#2563eb}.btn-edit:hover{background:#2563eb;color:#fff}
.btn-delete{background:#fef2f2;color:#dc2626}.btn-delete:hover{background:#dc2626;color:#fff}
.btn-icon svg{display:block;flex-shrink:0}
.status,.price{font-weight:700}
.hint{color:#64748b;font-size:13px;margin-top:4px}
.calc-hint{font-size:12px;color:#2563eb;margin-top:3px}
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
.table-wrap{overflow-x:auto}
.formula-box{background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:16px;font-size:13px;line-height:1.8;color:#475569}
.formula-box h3{margin:0 0 10px 0;font-size:15px;color:#1e293b}
.formula-box code{background:#e2e8f0;padding:2px 6px;border-radius:6px;font-size:12px}
.formula-box .step{margin-bottom:8px}
.formula-box .step-num{display:inline-block;background:#2563eb;color:#fff;width:22px;height:22px;border-radius:50%;text-align:center;line-height:22px;font-size:12px;font-weight:700;margin-right:6px}
.countertop-tabs{display:flex;gap:0;border-bottom:2px solid #e5e7eb;margin-bottom:20px;flex-wrap:wrap}
.countertop-tab{padding:10px 20px;cursor:pointer;font-weight:600;font-size:14px;color:#64748b;border-bottom:3px solid transparent;margin-bottom:-2px;text-decoration:none;transition:all .15s}
.countertop-tab:hover{color:#2563eb}
.countertop-tab.active{color:#2563eb;border-bottom-color:#2563eb}
.formula-code{font-family:'Courier New',monospace;background:#f1f5f9;padding:4px 8px;border-radius:6px;font-size:13px;color:#1e40af}
.formula-input{font-family:'Courier New',monospace;font-size:14px}
.type-group{background:#f8fafc;border-left:4px solid #2563eb;padding:8px 12px;margin:16px 0 8px;font-weight:700;border-radius:0 6px 6px 0}
.fields-ref{display:flex;flex-wrap:wrap;gap:6px;margin-top:8px}
.fields-ref span{background:#eff6ff;color:#1d4ed8;border-radius:6px;padding:3px 8px;font-size:12px;font-family:'Courier New',monospace;cursor:pointer}
.fields-ref span:hover{background:#dbeafe}
.toast{position:fixed;bottom:24px;right:24px;background:#166534;color:#fff;padding:10px 20px;border-radius:12px;font-size:14px;font-weight:600;z-index:9999;opacity:0;transition:opacity .3s}
.toast.show{opacity:1}
.inline-form{display:inline}
.flex-gap{display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap}
.field-save{transition:background .3s}
.field-save.saved{background:#dcfce7 !important}
.field-save.recalc{background:#dbeafe !important}
select.auto-save{width:auto;min-width:70px;padding:4px 8px;font-size:13px;border:1px solid #cbd5e1;border-radius:6px}
select.unit-select{min-width:60px}
select.currency-select{min-width:70px}
input.sm-input{width:80px;padding:4px 8px;font-size:13px;border:1px solid #cbd5e1;border-radius:6px}
input.md-input{width:100px;padding:4px 8px;font-size:13px;border:1px solid #cbd5e1;border-radius:6px}
.decor-photo-wrap{display:flex;align-items:flex-start;gap:16px}
.photo-preview-box{width:120px;height:120px;border-radius:10px;border:2px dashed #cbd5e1;background:#f8fafc;display:flex;align-items:center;justify-content:center;overflow:hidden;flex-shrink:0}
.photo-preview-box img{max-width:100%;max-height:100%;object-fit:cover;border-radius:8px}
.photo-preview-box .no-photo{color:#94a3b8;font-size:12px;text-align:center;padding:8px}
@media (max-width:700px){.grid,.grid-2,.grid-3{grid-template-columns:1fr}}
</style>
<?php echo app_header_styles(); ?>
</head>
<body>
<?php render_app_header(); ?>
<main class="container">
    <div class="quick-links">
        <a class="quick-link <?php echo $activeTab==='countertop'?'active':'';?>" href="admin_formulas.php?tab=countertop">🪵 Столешницы</a>
        <a class="quick-link <?php echo $activeTab==='subsystem'?'active':'';?>" href="admin_formulas.php?tab=subsystem">🔧 Подсистема</a>
        <a class="quick-link <?php echo $activeTab==='partition'?'active':'';?>" href="admin_formulas.php?tab=partition">🧮 Формулы перегородок</a>
    </div>

<?php if ($saved): ?><div class="success">Настройки сохранены.</div><?php endif; ?>
<?php if ($errors): ?><div class="errors"><?php echo e(implode(' ', $errors)); ?></div><?php endif; ?>

<!-- ════════════════ ВКЛАДКА: СТОЛЕШНИЦЫ ════════════════ -->
<div class="tab-pane <?php echo $activeTab==='countertop'?'active':''; ?>" id="tab-countertop">

    <!-- Глобальные параметры -->
    <div class="panel">
        <h3 style="margin-top:0">⚙️ Глобальные параметры</h3>
        <form method="post">
            <input type="hidden" name="action" value="save_global">
            <div class="grid">
                <div>
                    <label for="kerf_mm">Толщина реза пилы, мм</label>
                    <input id="kerf_mm" type="number" step="0.1" min="0" name="kerf_mm" value="<?php echo htmlspecialchars($kerfMm); ?>">
                    <div class="hint">Половина вычитается из ширины заготовки</div>
                </div>
                <div>
                    <label for="blank_width_mm">Ширина заготовки, мм</label>
                    <input id="blank_width_mm" type="number" step="1" min="1" name="blank_width_mm" value="<?php echo htmlspecialchars($blankWidthMm); ?>">
                    <div class="hint">Делитель ширины листа для кол-ва заготовок</div>
                </div>
            </div>
            <div style="margin-top:16px"><button type="submit">Сохранить глобальные</button></div>
        </form>
    </div>

    <!-- Формула -->
    <div class="panel">
        <div class="formula-box">
            <h3>📐 Формула расчёта стоимости детали столешницы</h3>
            <div class="step"><span class="step-num">1</span> Ширина листа ÷ <code>blank_width_mm</code> = <strong>кол-во заготовок</strong> (округление вниз).</div>
            <div class="step"><span class="step-num">2</span> Ширина заготовки = (Ширина листа ÷ кол-во заготовок) − (<code>kerf_mm</code> ÷ 2).</div>
            <div class="step"><span class="step-num">3</span> Заготовок для детали = ceil(Ширина детали ÷ Ширина заготовки), макс. = всего заготовок.</div>
            <div class="step"><span class="step-num">4</span> Стоимость материала = (заготовок для детали ÷ всего заготовок) × цена листа × кол-во деталей.</div>
            <div class="step"><span class="step-num">5</span> Периметр = (Длина + Ширина) × 2 × кол-во (мм → м).</div>
            <div class="step"><span class="step-num">6</span> Стоимость обработки = <code>processingPerM</code> (EUR/м) × периметр (м) × курс валюты.</div>
            <div class="step"><span class="step-num">7</span> Итого = ceil(материал + обработка) × коэфф. завода × коэфф. салона.</div>
        </div>
    </div>

    <!-- Вкладки по типам -->
    <div class="countertop-tabs">
        <?php foreach ($productTypes as $pt): ?>
            <a class="countertop-tab <?php echo $pt['type_key'] === $countertopSubTab ? 'active' : ''; ?>" href="admin_formulas.php?tab=countertop&subtype=<?php echo $pt['type_key']; ?>"><?php echo htmlspecialchars($pt['name']); ?></a>
        <?php endforeach; ?>
    </div>

    <?php if ($countertopType): ?>
    <div class="panel">
        <h3 style="margin-top:0">📋 <?php echo htmlspecialchars($countertopType['name']); ?></h3>
        <form method="post">
            <input type="hidden" name="action" value="save_product_type">
            <input type="hidden" name="type_key" value="<?php echo htmlspecialchars($countertopType['type_key']); ?>">
            <div class="section-title">Стоимость обработки кромки</div>
            <div style="max-width:300px; margin-bottom: 20px;">
                <label for="processing_per_m">Цена обработки кромки, EUR/п.м.</label>
                <input id="processing_per_m" type="number" step="0.01" min="0" name="processing_per_m" value="<?php echo htmlspecialchars((string)$countertopType['processing_per_m']); ?>">
                <div class="hint">Стоимость обработки одного погонного метра кромки в евро</div>
            </div>
            <div class="section-title">Допустимые размеры деталей, мм</div>
            <div class="grid-3">
                <div>
                    <label for="min_width">Мин. ширина</label>
                    <input id="min_width" type="number" step="1" min="0" name="min_width" value="<?php echo htmlspecialchars((string)$countertopType['min_width']); ?>">
                </div>
                <div>
                    <label for="max_width">Макс. ширина</label>
                    <input id="max_width" type="number" step="1" min="1" name="max_width" value="<?php echo htmlspecialchars((string)$countertopType['max_width']); ?>">
                </div>
                <div>&nbsp;</div>
                <div>
                    <label for="min_length">Мин. длина</label>
                    <input id="min_length" type="number" step="1" min="0" name="min_length" value="<?php echo htmlspecialchars((string)$countertopType['min_length']); ?>">
                </div>
                <div>
                    <label for="max_length">Макс. длина</label>
                    <input id="max_length" type="number" step="1" min="1" name="max_length" value="<?php echo htmlspecialchars((string)$countertopType['max_length']); ?>">
                </div>
                <div>&nbsp;</div>
            </div>
            <div style="margin-top:16px"><button type="submit">Сохранить параметры типа</button></div>
        </form>
    </div>

    <div class="panel">
        <div class="formula-box">
            <h3>Пример для «<?php echo htmlspecialchars($countertopType['name']); ?>»</h3>
            <p>Лист <strong>4200×1400 мм</strong>, заготовка <?php echo htmlspecialchars($blankWidthMm); ?> мм, рез <?php echo htmlspecialchars($kerfMm); ?> мм:</p>
            <?php
            $sw = 1400; $sh = 4200; $bw = (int)$blankWidthMm; $kf = (float)$kerfMm;
            $blks = ($bw > 0) ? floor($sw / $bw) : 0;
            $blankW = $blks > 0 ? ($sw / $blks) - ($kf / 2) : 0;
            ?>
            <p>1) <?php echo $sw; ?> ÷ <?php echo $bw; ?> = <strong><?php echo $blks; ?></strong> заготовок.</p>
            <?php if ($blks > 0): ?>
            <p>2) Ширина заготовки = <?php echo $sw; ?> ÷ <?php echo $blks; ?> − <?php echo $kf/2; ?> = <strong><?php echo number_format($blankW, 1); ?> мм</strong>.</p>
            <p>3) Деталь ≤ <?php echo number_format($blankW, 1); ?> мм → 1 заготовка → <strong><?php echo $blks > 1 ? '1/' . $blks : 'целый'; ?> листа</strong>.</p>
            <p>4) Обработка кромки: <strong><?php echo htmlspecialchars($countertopType['processing_per_m']); ?> EUR/м</strong> × периметр × курс.</p>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- ════════════════ ВКЛАДКА: ПОДСИСТЕМА ════════════════ -->
<div class="tab-pane <?php echo $activeTab==='subsystem'?'active':''; ?>" id="tab-subsystem">

    <div class="panel" style="background:#f0f9ff;border-left:4px solid #2563eb;">
        <h3 style="margin-top:0;color:#1d4ed8;">Формула расчёта подсистемы</h3>
        <div style="font-size:13px;line-height:1.8;color:#374151;">
            <p><b>На странице «Расчёт подсистемы»</b> пользователь выбирает: формат панели, количество листов, шаг профиля (мм), количество углов, тип стены, высоту подсистемы, поставщика профиля, поставщика клеевой системы, поставщика метизов. Также может добавить горизонтальную перемычку с указанием шага.</p>
            <p><b>Профиль:</b></p>
            <ul style="margin:0 0 10px 20px;">
                <li>Профиль всегда стоит вертикально, панель по умолчанию ставится вертикально.</li>
                <li>Считаем в метрах погонных по высоте подсистемы (если указана), иначе — по длине листа.</li>
                <li>Всегда считаем первый стартовый профиль, затем считаем с шагом, указанным пользователем.</li>
                <li>Если указано количество углов — каждый угол добавляет +1 профиль.</li>
                <li>Если указана горизонтальная перемычка — добавляем с учётом стартового профиля. На углах горизонтальный профиль не учитываем.</li>
                <li>Профиль считаем кратно 1 метру, всегда округляем в большую сторону. Количество профилей в штуках — в зависимости от указанного количества в справочнике.</li>
            </ul>
            <p><b>Клеевая система и метизы:</b></p>
            <ul style="margin:0 0 0 20px;">
                <li>Расчёт по <b>м.п.</b> (м.п. профиля): расход × м.п. профиля.</li>
                <li>Расчёт по <b>м²</b>: расход × количество м² панелей.</li>
                <li>Единица расчёта зависит от того, что указано в поле «Расход» — Профиль (м.п.) или Панель (м²).</li>
            </ul>
        </div>
    </div>

    <!-- ═══ КЛЕЕВАЯ СИСТЕМА ═══ -->
    <div class="panel" id="section-materials">
        <h3 style="margin-top:0">Клеевая система</h3>
        <div style="margin-bottom:12px;display:flex;align-items:center;gap:10px;">
            <label for="material-supplier-filter" style="margin:0;font-size:13px;white-space:nowrap;">Поставщик:</label>
            <select id="material-supplier-filter" style="width:auto;padding:4px 8px;font-size:13px;border:1px solid #cbd5e1;border-radius:6px;">
                <option value="all">Все</option>
                <?php
                $usedSupplierIds = array_unique(array_map(function($m) { return $m['supplier_id'] ?: 0; }, $subMaterials));
                sort($usedSupplierIds);
                foreach ($usedSupplierIds as $sid):
                    if ($sid == 0) continue;
                    $sname = '';
                    foreach ($subSuppliers as $s) { if ((int)$s['id'] === (int)$sid) { $sname = $s['company_name']; break; } }
                    if ($sname === '') continue;
                ?>
                    <option value="<?php echo (int)$sid; ?>"><?php echo htmlspecialchars($sname); ?></option>
                <?php endforeach; ?>
                <option value="0">— Без поставщика —</option>
            </select>
        </div>
        <?php if (empty($subMaterials)): ?>
            <p style="color:#94a3b8;">Нет материалов. Добавьте материал ниже.</p>
        <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Название</th>
                        <th>Поставщик</th>
                        <th>Ед. изм.</th>
                        <th>Валюта</th>
                        <th>Кол-во в шт.</th>
                        <th>Цена за шт.</th>
                        <th>Цена за ед.</th>
                        <th>Расход</th>
                        <th style="width:60px;"></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($subMaterials as $m): ?>
                <tr data-id="<?php echo $m['id']; ?>"
                    data-supplier="<?php echo (int)($m['supplier_id'] ?: 0); ?>"
                    data-qty="<?php echo (string)$m['quantity_per_piece']; ?>"
                    data-price-piece="<?php echo (string)$m['price_per_piece']; ?>"
                    data-price-unit="<?php echo (string)$m['price_per_unit']; ?>"
                    data-currency="<?php echo $m['currency_id'] ?: ''; ?>">
                    <td style="font-weight:600;"><?php echo htmlspecialchars($m['name']); ?></td>
                    <td>
                        <select data-action="update_material" data-id="<?php echo $m['id']; ?>" data-field="supplier_id" class="auto-save field-save" style="width:140px;padding:4px 8px;font-size:13px;border:1px solid #cbd5e1;border-radius:6px;">
                            <option value="0">—</option>
                            <?php foreach ($subSuppliers as $s): ?>
                                <option value="<?php echo $s['id']; ?>" <?php echo (int)$m['supplier_id'] === (int)$s['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($s['company_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td>
                        <select data-action="update_material" data-id="<?php echo $m['id']; ?>" data-field="unit" class="auto-save field-save unit-select">
                            <?php foreach ($subUnits as $u): ?>
                                <option value="<?php echo htmlspecialchars($u['short_name']); ?>" <?php echo $m['unit'] === $u['short_name'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($u['short_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td>
                        <select data-action="update_material" data-id="<?php echo $m['id']; ?>" data-field="currency_id" class="auto-save field-save currency-select">
                            <option value="0">—</option>
                            <?php foreach ($subCurrencies as $c): ?>
                                <option value="<?php echo $c['id']; ?>" <?php echo (int)$m['currency_id'] === (int)$c['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars(app_currency_symbol((string)$c['code'])); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td>
                        <input type="number" step="0.01" min="0" data-action="update_material" data-id="<?php echo $m['id']; ?>" data-field="quantity_per_piece" class="auto-save field-save sm-input" value="<?php echo htmlspecialchars((string)$m['quantity_per_piece']); ?>">
                    </td>
                    <td>
                        <input type="number" step="0.01" min="0" data-action="update_material" data-id="<?php echo $m['id']; ?>" data-field="price_per_piece" class="auto-save field-save md-input price-input" value="<?php echo htmlspecialchars((string)$m['price_per_piece']); ?>">
                    </td>
                    <td>
                        <input type="number" step="0.01" min="0" data-action="update_material" data-id="<?php echo $m['id']; ?>" data-field="price_per_unit" class="auto-save field-save md-input price-input" value="<?php echo htmlspecialchars((string)$m['price_per_unit']); ?>">
                    </td>
                    <td style="display:flex;gap:4px;align-items:center;">
                        <input type="number" step="0.01" min="0" data-action="update_material" data-id="<?php echo $m['id']; ?>" data-field="consumption_per_m" class="auto-save field-save md-input" style="width:80px;" value="<?php echo htmlspecialchars((string)$m['consumption_per_m']); ?>">
                        <span style="color:#64748b;font-size:13px;">на</span>
                        <select data-action="update_material" data-id="<?php echo $m['id']; ?>" data-field="consumption_unit" class="auto-save field-save unit-select" style="min-width:120px;">
                            <option value="Профиль" <?php echo ($m['consumption_unit'] ?? 'Профиль') === 'Профиль' ? 'selected' : ''; ?>>на м.п. профиля</option>
                            <option value="Панель" <?php echo ($m['consumption_unit'] ?? '') === 'Панель' ? 'selected' : ''; ?>>на м² панели</option>
                        </select>
                    </td>
                    <td>
                        <button type="button" class="btn-icon btn-delete" onclick="deleteItem('delete_material', <?php echo $m['id']; ?>, this)" title="Удалить"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg></button>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <div class="section-title" style="margin-top:20px;">Добавить материал</div>
        <form id="add-material-form" class="flex-gap">
            <div style="flex:1; min-width:200px;">
                <label>Название</label>
                <input type="text" name="new_material_name" placeholder="Название материала" required>
            </div>
            <div style="min-width:180px;">
                <label>Поставщик</label>
                <select name="supplier_id" style="width:auto;">
                    <option value="0">— Без поставщика —</option>
                    <?php foreach ($subSuppliers as $s): ?>
                        <option value="<?php echo $s['id']; ?>"><?php echo htmlspecialchars($s['company_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="min-width:120px;">
                <label>Ед. изм.</label>
                <select name="new_material_unit" style="width:auto;">
                    <?php foreach ($subUnits as $u): ?>
                        <option value="<?php echo htmlspecialchars($u['short_name']); ?>" <?php echo $u['short_name'] === 'шт.' ? 'selected' : ''; ?>><?php echo htmlspecialchars($u['short_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="min-width:100px;">
                <label>Валюта</label>
                <select name="currency_id" style="width:auto;">
                    <?php foreach ($subCurrencies as $c): ?>
                        <option value="<?php echo $c['id']; ?>" <?php echo (int)$c['id'] === (int)$rubId ? 'selected' : ''; ?>><?php echo htmlspecialchars(app_currency_symbol((string)$c['code'])); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div><button type="submit" class="btn-success">Добавить</button></div>
        </form>
    </div>

    <!-- ═══ ПОДСИСТЕМА ═══ -->
    <div class="panel" id="section-sub-items">
        <h3 style="margin-top:0">Подсистема</h3>
        <div style="margin-bottom:12px;display:flex;align-items:center;gap:10px;">
            <label for="subitem-supplier-filter" style="margin:0;font-size:13px;white-space:nowrap;">Поставщик:</label>
            <select id="subitem-supplier-filter" style="width:auto;padding:4px 8px;font-size:13px;border:1px solid #cbd5e1;border-radius:6px;">
                <option value="all">Все</option>
                <?php
                $usedSubSuppliers = array_unique(array_map(function($si) { return $si['supplier_id'] ?: 0; }, $subItems));
                sort($usedSubSuppliers);
                foreach ($usedSubSuppliers as $sid):
                    if ($sid == 0) continue;
                    $sname = '';
                    foreach ($subSuppliers as $s) { if ((int)$s['id'] === (int)$sid) { $sname = $s['company_name']; break; } }
                    if ($sname === '') continue;
                ?>
                    <option value="<?php echo (int)$sid; ?>"><?php echo htmlspecialchars($sname); ?></option>
                <?php endforeach; ?>
                <option value="0">— Без поставщика —</option>
            </select>
        </div>
        <?php if (empty($subItems)): ?>
            <p style="color:#94a3b8;">Нет позиций. Добавьте ниже.</p>
        <?php else: ?>
        <div class="table-wrap">
            <table id="sub-items-table">
                <thead>
                    <tr>
                        <th>Наименование</th>
                        <th>Поставщик</th>
                        <th>Ширина, мм</th>
                        <th>Толщина, мм</th>
                        <th>Ед. изм.</th>
                        <th>Валюта</th>
                        <th>Кол в 1 шт.</th>
                        <th>Цена за ед.</th>
                        <th>Цена за шт.</th>
                        <th style="width:60px;"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($subItems as $si): ?>
                    <tr data-id="<?php echo $si['id']; ?>"
                        data-supplier="<?php echo (int)($si['supplier_id'] ?: 0); ?>"
                        data-qty="<?php echo (string)$si['quantity_per_piece']; ?>"
                        data-price-piece="<?php echo (string)$si['price_per_piece']; ?>"
                        data-price-unit="<?php echo (string)$si['price_per_unit']; ?>"
                        data-currency="<?php echo $si['currency_id'] ?: ''; ?>">
                        <td>
                            <input type="text" data-action="update_sub_item" data-id="<?php echo $si['id']; ?>" data-field="name" class="auto-save field-save" style="width:160px;padding:4px 8px;font-size:13px;border:1px solid #cbd5e1;border-radius:6px;" value="<?php echo htmlspecialchars($si['name']); ?>">
                        </td>
                        <td>
                            <select data-action="update_sub_item" data-id="<?php echo $si['id']; ?>" data-field="supplier_id" class="auto-save field-save" style="width:140px;padding:4px 8px;font-size:13px;border:1px solid #cbd5e1;border-radius:6px;">
                                <option value="0">—</option>
                                <?php foreach ($subSuppliers as $s): ?>
                                    <option value="<?php echo $s['id']; ?>" <?php echo (int)$si['supplier_id'] === (int)$s['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($s['company_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td>
                            <input type="number" step="0.01" min="0" data-action="update_sub_item" data-id="<?php echo $si['id']; ?>" data-field="width_mm" class="auto-save field-save sm-input" value="<?php echo htmlspecialchars((string)$si['width_mm']); ?>">
                        </td>
                        <td>
                            <input type="number" step="0.01" min="0" data-action="update_sub_item" data-id="<?php echo $si['id']; ?>" data-field="thickness_mm" class="auto-save field-save sm-input" value="<?php echo htmlspecialchars((string)$si['thickness_mm']); ?>">
                        </td>
                        <td>
                            <select data-action="update_sub_item" data-id="<?php echo $si['id']; ?>" data-field="unit" class="auto-save field-save unit-select">
                                <?php foreach ($subUnits as $u): ?>
                                    <option value="<?php echo htmlspecialchars($u['short_name']); ?>" <?php echo $si['unit'] === $u['short_name'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($u['short_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td>
                            <select data-action="update_sub_item" data-id="<?php echo $si['id']; ?>" data-field="currency_id" class="auto-save field-save currency-select">
                                <?php foreach ($subCurrencies as $c): ?>
                                    <option value="<?php echo $c['id']; ?>" <?php echo (int)$si['currency_id'] === (int)$c['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars(app_currency_symbol((string)$c['code'])); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td>
                            <input type="number" step="0.01" min="0" data-action="update_sub_item" data-id="<?php echo $si['id']; ?>" data-field="quantity_per_piece" class="auto-save field-save sm-input" value="<?php echo htmlspecialchars((string)$si['quantity_per_piece']); ?>">
                        </td>
                        <td>
                            <input type="number" step="0.01" min="0" data-action="update_sub_item" data-id="<?php echo $si['id']; ?>" data-field="price_per_unit" class="auto-save field-save md-input sub-price-unit" value="<?php echo htmlspecialchars((string)$si['price_per_unit']); ?>">
                        </td>
                        <td>
                            <input type="number" step="0.01" min="0" data-action="update_sub_item" data-id="<?php echo $si['id']; ?>" data-field="price_per_piece" class="auto-save field-save md-input sub-price-piece" value="<?php echo htmlspecialchars((string)$si['price_per_piece']); ?>">
                        </td>
                        <td>
                            <button type="button" class="btn-icon btn-delete" onclick="deleteItem('delete_sub_item', <?php echo $si['id']; ?>, this)" title="Удалить"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg></button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <div class="section-title" style="margin-top:20px;">Добавить позицию</div>
        <form id="add-sub-item-form" class="flex-gap">
            <div style="flex:1;min-width:160px;">
                <label>Наименование</label>
                <input type="text" name="new_sub_item_name" placeholder="Название" required>
            </div>
            <div style="min-width:140px;">
                <label>Поставщик</label>
                <select name="supplier_id" style="width:auto;">
                    <option value="0">—</option>
                    <?php foreach ($subSuppliers as $s): ?>
                        <option value="<?php echo $s['id']; ?>"><?php echo htmlspecialchars($s['company_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="min-width:80px;">
                <label>Ширина, мм</label>
                <input type="number" step="0.01" min="0" name="width_mm" value="0">
            </div>
            <div style="min-width:80px;">
                <label>Толщина, мм</label>
                <input type="number" step="0.01" min="0" name="thickness_mm" value="0">
            </div>
            <div style="min-width:80px;">
                <label>Ед. изм.</label>
                <select name="unit" style="width:auto;">
                    <?php foreach ($subUnits as $u): ?>
                        <option value="<?php echo htmlspecialchars($u['short_name']); ?>" <?php echo $u['short_name'] === 'шт.' ? 'selected' : ''; ?>><?php echo htmlspecialchars($u['short_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="min-width:80px;">
                <label>Валюта</label>
                <select name="currency_id" style="width:auto;">
                    <?php foreach ($subCurrencies as $c): ?>
                        <option value="<?php echo $c['id']; ?>" <?php echo (int)$c['id'] === (int)$rubId ? 'selected' : ''; ?>><?php echo htmlspecialchars(app_currency_symbol((string)$c['code'])); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div><button type="submit" class="btn-success">Добавить</button></div>
        </form>
    </div>

    <!-- ═══ ТИП СТЕНЫ ═══ -->
    <div class="panel" id="section-enclosures">
        <h3 style="margin-top:0">Тип стены</h3>
        <div class="table-wrap">
            <table id="enclosures-table">
                <thead>
                    <tr>
                        <th>Название</th>
                        <th>Крепёж</th>
                        <th style="width:80px;"></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($subEnclosures)): ?>
                    <tr><td colspan="3" style="color:#94a3b8;">Нет конструкций</td></tr>
                <?php else: ?>
                    <?php foreach ($subEnclosures as $e): ?>
                    <tr data-id="<?php echo $e['id']; ?>">
                        <td style="font-weight:600;"><?php echo htmlspecialchars($e['name']); ?></td>
                        <td>
                            <select data-action="update_enclosure_fastener" data-id="<?php echo $e['id']; ?>" class="auto-save field-save" style="width:200px;padding:4px 8px;font-size:13px;border:1px solid #cbd5e1;border-radius:6px;">
                                <?php foreach ($subFasteners as $f): ?>
                                    <option value="<?php echo $f['id']; ?>" <?php echo (int)$e['fastener_id'] === (int)$f['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($f['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td>
                            <button type="button" class="btn-icon btn-delete" onclick="deleteItem('delete_enclosure', <?php echo $e['id']; ?>, this)" title="Удалить"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg></button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="section-title" style="margin-top:20px;">Добавить конструкцию</div>
        <form id="add-enclosure-form" class="flex-gap">
            <div style="flex:1; min-width:200px;">
                <label>Название</label>
                <input type="text" name="name" placeholder="Например: Керамогранит" required>
            </div>
            <div style="min-width:200px;">
                <label>Крепёж</label>
                <select name="fastener_id" required>
                    <option value="">— выбрать —</option>
                    <?php foreach ($subFasteners as $f): ?>
                        <option value="<?php echo $f['id']; ?>"><?php echo htmlspecialchars($f['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div><button type="submit" class="btn-success">Добавить</button></div>
        </form>
    </div>

    <!-- ═══ МЕТИЗЫ ═══ -->
    <div class="panel" id="section-fasteners">
        <h3 style="margin-top:0">Метизы</h3>
        <div style="margin-bottom:12px;display:flex;align-items:center;gap:10px;">
            <label for="fastener-supplier-filter" style="margin:0;font-size:13px;white-space:nowrap;">Поставщик:</label>
            <select id="fastener-supplier-filter" style="width:auto;padding:4px 8px;font-size:13px;border:1px solid #cbd5e1;border-radius:6px;">
                <option value="all">Все</option>
                <?php
                $usedFastSuppliers = array_unique(array_map(function($f) { return $f['supplier_id'] ?: 0; }, $subFasteners));
                sort($usedFastSuppliers);
                foreach ($usedFastSuppliers as $sid):
                    if ($sid == 0) continue;
                    $sname = '';
                    foreach ($subSuppliers as $s) { if ((int)$s['id'] === (int)$sid) { $sname = $s['company_name']; break; } }
                    if ($sname === '') continue;
                ?>
                    <option value="<?php echo (int)$sid; ?>"><?php echo htmlspecialchars($sname); ?></option>
                <?php endforeach; ?>
                <option value="0">— Без поставщика —</option>
            </select>
        </div>
        <?php if (empty($subFasteners)): ?>
            <p style="color:#94a3b8;">Нет крепежа. Добавьте ниже.</p>
        <?php else: ?>
        <div class="table-wrap">
            <table id="fasteners-table">
                <thead>
                    <tr>
                        <th>Название</th>
                        <th>Поставщик</th>
                        <th>Ед. изм.</th>
                        <th>Валюта</th>
                        <th>Кол-во в ед. изм.</th>
                        <th>Цена за ед.</th>
                        <th>Расход</th>
                        <th style="width:60px;"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($subFasteners as $f): ?>
                    <tr data-id="<?php echo $f['id']; ?>"
                        data-supplier="<?php echo (int)($f['supplier_id'] ?: 0); ?>">
                        <td style="font-weight:600;"><?php echo htmlspecialchars($f['name']); ?></td>
                        <td>
                            <select data-action="update_fastener" data-id="<?php echo $f['id']; ?>" data-field="supplier_id" class="auto-save field-save" style="width:140px;padding:4px 8px;font-size:13px;border:1px solid #cbd5e1;border-radius:6px;">
                                <option value="0">—</option>
                                <?php foreach ($subSuppliers as $s): ?>
                                    <option value="<?php echo $s['id']; ?>" <?php echo (int)$f['supplier_id'] === (int)$s['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($s['company_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td>
                            <select data-action="update_fastener" data-id="<?php echo $f['id']; ?>" data-field="unit" class="auto-save field-save unit-select">
                                <?php foreach ($subUnits as $u): ?>
                                    <option value="<?php echo htmlspecialchars($u['short_name']); ?>" <?php echo $f['unit'] === $u['short_name'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($u['short_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td>
                            <select data-action="update_fastener" data-id="<?php echo $f['id']; ?>" data-field="currency_id" class="auto-save field-save currency-select">
                                <option value="0">—</option>
                                <?php foreach ($subCurrencies as $c): ?>
                                    <option value="<?php echo $c['id']; ?>" <?php echo (int)$f['currency_id'] === (int)$c['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars(app_currency_symbol((string)$c['code'])); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td>
                            <input type="number" step="0.01" min="0" data-action="update_fastener" data-id="<?php echo $f['id']; ?>" data-field="quantity_per_unit" class="auto-save field-save md-input" value="<?php echo htmlspecialchars((string)$f['quantity_per_unit']); ?>">
                        </td>
                        <td>
                            <input type="number" step="0.01" min="0" data-action="update_fastener" data-id="<?php echo $f['id']; ?>" data-field="price_per_unit" class="auto-save field-save md-input" value="<?php echo htmlspecialchars((string)$f['price_per_unit']); ?>">
                        </td>
                        <td style="display:flex;gap:4px;align-items:center;">
                            <input type="number" step="0.01" min="0" data-action="update_fastener" data-id="<?php echo $f['id']; ?>" data-field="consumption_per_m" class="auto-save field-save md-input" style="width:80px;" value="<?php echo htmlspecialchars((string)($f['consumption_per_m'] ?? 0)); ?>">
                            <span style="color:#64748b;font-size:13px;">на</span>
                            <select data-action="update_fastener" data-id="<?php echo $f['id']; ?>" data-field="consumption_unit" class="auto-save field-save unit-select" style="min-width:120px;">
                                <option value="Профиль" <?php echo ($f['consumption_unit'] ?? 'Профиль') === 'Профиль' ? 'selected' : ''; ?>>на м.п. профиля</option>
                                <option value="Панель" <?php echo ($f['consumption_unit'] ?? '') === 'Панель' ? 'selected' : ''; ?>>на м² панели</option>
                            </select>
                        </td>
                        <td>
                            <button type="button" class="btn-icon btn-delete" onclick="deleteItem('delete_fastener', <?php echo $f['id']; ?>, this)" title="Удалить"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg></button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <div class="section-title" style="margin-top:20px;">Добавить крепёж</div>
        <form id="add-fastener-form" class="flex-gap">
            <div style="flex:1; min-width:200px;">
                <label>Название</label>
                <input type="text" name="new_fastener_name" placeholder="Название крепежа" required>
            </div>
            <div style="min-width:160px;">
                <label>Поставщик</label>
                <select name="supplier_id" style="width:auto;">
                    <option value="0">—</option>
                    <?php foreach ($subSuppliers as $s): ?>
                        <option value="<?php echo $s['id']; ?>"><?php echo htmlspecialchars($s['company_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="min-width:100px;">
                <label>Ед. изм.</label>
                <select name="new_fastener_unit" style="width:auto;">
                    <?php foreach ($subUnits as $u): ?>
                        <option value="<?php echo htmlspecialchars($u['short_name']); ?>" <?php echo $u['short_name'] === 'шт.' ? 'selected' : ''; ?>><?php echo htmlspecialchars($u['short_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="min-width:100px;">
                <label>Валюта</label>
                <select name="currency_id" style="width:auto;">
                    <?php foreach ($subCurrencies as $c): ?>
                        <option value="<?php echo $c['id']; ?>" <?php echo (int)$c['id'] === (int)$rubId ? 'selected' : ''; ?>><?php echo htmlspecialchars(app_currency_symbol((string)$c['code'])); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="min-width:120px;">
                <label>Кол-во в ед.</label>
                <input type="number" step="0.01" min="0" name="new_fastener_qty" value="1" style="width:100px;">
            </div>
            <div style="min-width:120px;">
                <label>Цена за ед.</label>
                <input type="number" step="0.01" min="0" name="new_fastener_price" value="0" style="width:100px;">
            </div>
            <div><button type="submit" class="btn-success">Добавить</button></div>
        </form>
    </div>
</div>

<!-- ════════════════ ВКЛАДКА: ФОРМУЛЫ ПЕРЕГОРОДОК ════════════════ -->
<div class="tab-pane <?php echo $activeTab==='partition'?'active':''; ?>" id="tab-partition">

    <section class="panel">
        <h2><?php echo $partitionEditing ? 'Редактировать формулу' : 'Добавить формулу'; ?></h2>
        <p class="hint">Формулы используются для автоматического расчёта количества материалов, фурнитуры и площадей. В формулах можно использовать ключи полей типа перегородки (например <span class="formula-code">doors_count</span>, <span class="formula-code">facade_length</span>) и базовые операторы <span class="formula-code">+ - * / ( )</span>.</p>
        <form method="post">
            <input type="hidden" name="action" value="<?php echo $partitionEditing?'update_formula':'create_formula'; ?>">
            <input type="hidden" name="id" value="<?php echo e((string)($partitionEditing['id']??'')); ?>">
            <div class="grid">
                <div>
                    <label for="partition_type_id">Тип перегородки</label>
                    <select id="partition_type_id" name="partition_type_id">
                        <option value="0">Любой тип</option>
                        <?php foreach($partitionTypes as $t): ?>
                            <option value="<?php echo e((string)$t['id']); ?>" data-type-id="<?php echo e((string)$t['id']); ?>" <?php echo (int)($partitionEditing['partition_type_id']??0)===(int)$t['id']?'selected':''; ?>><?php echo e($t['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="pf-name">Название формулы</label>
                    <input id="pf-name" name="name" required placeholder="Например: Количество петель" value="<?php echo e((string)($partitionEditing['name']??'')); ?>">
                </div>
                <div>
                    <label for="target_key">Ключ результата</label>
                    <input id="target_key" name="target_key" required placeholder="Например: hardware_hinges_count" value="<?php echo e((string)($partitionEditing['target_key']??'')); ?>">
                    <div class="hint">Латиницей, без пробелов. Используется в расчётах как итоговая переменная.</div>
                </div>
                <div>
                    <label for="pf-unit">Единица измерения</label>
                    <input id="pf-unit" name="unit" placeholder="Например: шт, м, м²" value="<?php echo e((string)($partitionEditing['unit']??'')); ?>">
                </div>
                <div>
                    <label for="sort_order">Порядок</label>
                    <input id="sort_order" type="number" name="sort_order" value="<?php echo e((string)($partitionEditing['sort_order']??'0')); ?>">
                </div>
            </div>
            <div style="margin-top:14px">
                <label for="formula">Формула</label>
                <textarea id="formula" class="formula-input" name="formula" required placeholder="Например: doors_count * 2 + 2, или: (facade_length - doors_count * door_width) / partition_width"><?php echo e((string)($partitionEditing['formula']??'')); ?></textarea>
                <div class="hint">Доступные ключи полей по типам перегородок (нажмите, чтобы вставить):</div>
                <div class="fields-ref" id="fields-ref">
                    <?php foreach ($typeFields as $f): ?>
                        <span data-key="<?php echo e($f['field_key']); ?>" data-type="<?php echo e((string)($f['partition_type_id']??0)); ?>" title="<?php echo e($f['field_label'].' — '.($f['type_name']??'любой тип')); ?>"><?php echo e($f['field_key']); ?></span>
                    <?php endforeach; ?>
                    <?php if (!$typeFields): ?><span class="hint" style="background:none">Поля пока не настроены в «Конструкторе перегородки».</span><?php endif; ?>
                </div>
            </div>
            <p style="margin-top:14px"><label for="pf-note">Примечание</label><textarea id="pf-note" name="note"><?php echo e((string)($partitionEditing['note']??'')); ?></textarea></p>
            <p><label><input type="checkbox" name="is_active" <?php echo !isset($partitionEditing['is_active'])||(int)$partitionEditing['is_active']===1?'checked':''; ?>> Активна</label></p>
            <div class="form-actions">
                <button type="submit">Сохранить</button>
                <?php if($partitionEditing): ?><a class="button secondary" href="admin_formulas.php?tab=partition">Отмена</a><?php endif; ?>
            </div>
        </form>
    </section>

    <section class="panel">
        <h2>Список формул</h2>
        <?php
        $grouped = [];
        foreach ($partitionFormulas as $f) $grouped[$f['type_name'] ?? 'Любой тип'][] = $f;
        if ($grouped):
            foreach ($grouped as $typeName => $items):
        ?>
            <div class="type-group">🧮 <?php echo e($typeName); ?></div>
            <table>
                <thead><tr><th>Название</th><th>Результат</th><th>Формула</th><th>Ед.</th><th>Активна</th><th>Действия</th></tr></thead>
                <tbody>
                <?php foreach($items as $f): ?>
                    <tr>
                        <td><?php echo e($f['name']); ?><?php if($f['note']): ?><div class="hint"><?php echo e($f['note']); ?></div><?php endif; ?></td>
                        <td><code><?php echo e($f['target_key']); ?></code></td>
                        <td><span class="formula-code"><?php echo e($f['formula']); ?></span></td>
                        <td><?php echo e((string)($f['unit']??'—')); ?></td>
                        <td><span class="badge <?php echo (int)$f['is_active']?'badge-yes':'badge-no'; ?>"><?php echo (int)$f['is_active']?'Да':'Нет'; ?></span></td>
                        <td class="actions">
                            <a class="btn-icon btn-edit" href="admin_formulas.php?tab=partition&edit=<?php echo e((string)$f['id']); ?>" title="Изменить"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg></a>
                            <button class="btn-icon btn-delete" type="button" onclick="delFormula(<?php echo (int)$f['id']; ?>)" title="Удалить"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg></button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endforeach; else: ?>
            <p class="hint">Формулы пока не добавлены. Добавьте первую формулу выше.</p>
        <?php endif; ?>
    </section>
</div>

<!-- Общая форма удаления (partition formulas) -->
<form id="del-form" method="post" style="display:none;"><input type="hidden" name="action" value="delete_formula"><input type="hidden" name="id" id="del-id"></form>

</main>

<div class="toast" id="toast">Сохранено</div>

<script>
var CURRENCIES = <?php echo $subCurrenciesJson; ?>;

function getCurrencyRate(id) {
    for (var i = 0; i < CURRENCIES.length; i++) {
        if (CURRENCIES[i].id == id) return { rate: parseFloat(CURRENCIES[i].rate_to_rub), nominal: parseInt(CURRENCIES[i].nominal) || 1, code: CURRENCIES[i].code };
    }
    return { rate: 1, nominal: 1, code: 'RUB' };
}

function round2(v) { return Math.round(v * 100) / 100; }

function showToast(msg) {
    var t = document.getElementById('toast');
    t.textContent = msg || 'Сохранено';
    t.classList.add('show');
    setTimeout(function() { t.classList.remove('show'); }, 1500);
}

function flashField(el, cls) {
    el.classList.add(cls || 'saved');
    setTimeout(function() { el.classList.remove(cls || 'saved'); }, 800);
}

function sendAjax(action, data, callback) {
    var fd = new FormData();
    fd.append('action', action);
    for (var k in data) fd.append(k, data[k]);
    fetch('admin_formulas.php', { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(j) { if (callback) callback(j); })
        .catch(function() {});
}

function deleteItem(action, id, btn) {
    if (!confirm('Удалить?')) return;
    sendAjax(action, { id: id }, function() {
        var tr = btn.closest('tr');
        if (tr) tr.remove();
        showToast('Удалено');
    });
}

function getRowData(tr) {
    var getVal = function(f) { var el = tr.querySelector('[data-field="' + f + '"]'); return el ? parseFloat(el.value) || 0 : 0; };
    var getCurr = function() { var el = tr.querySelector('[data-field="currency_id"]'); return el ? el.value : ''; };
    return { qty: getVal('quantity_per_piece'), pricePiece: getVal('price_per_piece'), priceUnit: getVal('price_per_unit'), currencyId: getCurr() };
}

function saveField(el) {
    var action = el.dataset.action;
    var id = el.dataset.id;
    var field = el.dataset.field;
    var value = el.value;
    sendAjax(action, { id: id, field: field, value: value }, function() {
        flashField(el);
        showToast();
    });
}

function syncMaterialPrices(changedField, tr) {
    var data = getRowData(tr);
    var qty = data.qty;
    var pieceInput = tr.querySelector('[data-field="price_per_piece"]');
    var unitInput = tr.querySelector('[data-field="price_per_unit"]');
    if (!pieceInput || !unitInput) return;

    if (changedField === 'price_per_piece') {
        if (qty > 0) {
            var newVal = round2(data.pricePiece / qty);
            unitInput.value = newVal;
            saveField(unitInput);
            flashField(unitInput, 'recalc');
        }
    } else if (changedField === 'price_per_unit') {
        var newVal = round2(data.priceUnit * qty);
        pieceInput.value = newVal;
        saveField(pieceInput);
        flashField(pieceInput, 'recalc');
    } else if (changedField === 'quantity_per_piece') {
        if (qty > 0) {
            var unitVal = round2(data.pricePiece / qty);
            unitInput.value = unitVal;
            saveField(unitInput);
            flashField(unitInput, 'recalc');
        } else {
            unitInput.value = 0;
            saveField(unitInput);
            flashField(unitInput, 'recalc');
        }
    }
}

// ── Materials auto-save inputs ──
document.querySelectorAll('#section-materials input.auto-save').forEach(function(el) {
    el.addEventListener('change', function() {
        var field = this.dataset.field;
        saveField(this);
        if (field === 'price_per_piece' || field === 'price_per_unit' || field === 'quantity_per_piece') {
            syncMaterialPrices(field, this.closest('tr'));
        }
    });
});

// ── Materials auto-save selects ──
document.querySelectorAll('#section-materials select.auto-save').forEach(function(el) {
    el.addEventListener('change', function() {
        var field = this.dataset.field;
        var id = this.dataset.id;
        var value = this.value;
        var self = this;
        var tr = this.closest('tr');

        if (field === 'currency_id') {
            var oldCurr = tr.dataset.currency || '';
            var newCurrId = value;
            var oldCurrData = getCurrencyRate(oldCurr);
            var newCurrData = getCurrencyRate(newCurrId);
            var pieceInput = tr.querySelector('[data-field="price_per_piece"]');
            var unitInput = tr.querySelector('[data-field="price_per_unit"]');
            var oldPiece = parseFloat(pieceInput.value) || 0;
            var oldUnit = parseFloat(unitInput.value) || 0;

            if (oldPiece > 0 && oldCurr !== '' && newCurrId !== '' && oldCurr !== newCurrId) {
                var rubPiece = oldPiece * oldCurrData.rate / oldCurrData.nominal;
                var newPiece = round2(rubPiece * newCurrData.nominal / newCurrData.rate);
                pieceInput.value = newPiece;
                flashField(pieceInput, 'recalc');
                if (parseFloat(tr.querySelector('[data-field="quantity_per_piece"]').value || 0) > 0) {
                    var rubUnit = oldUnit * oldCurrData.rate / oldCurrData.nominal;
                    var newUnit = round2(rubUnit * newCurrData.nominal / newCurrData.rate);
                    unitInput.value = newUnit;
                    flashField(unitInput, 'recalc');
                }
                saveField(pieceInput);
                saveField(unitInput);
            }
            tr.dataset.currency = newCurrId;
        }

        sendAjax('update_material', { id: id, field: field, value: value }, function() {
            flashField(self);
            showToast();
        });
        if (field === 'supplier_id') {
            tr.dataset.supplier = value;
        }
    });
});

// ── Materials supplier filter ──
var materialFilter = document.getElementById('material-supplier-filter');
if (materialFilter) {
    materialFilter.addEventListener('change', function() {
        var val = this.value;
        document.querySelectorAll('#section-materials tbody tr').forEach(function(tr) {
            if (val === 'all') { tr.style.display = ''; }
            else { tr.style.display = tr.dataset.supplier === val ? '' : 'none'; }
        });
    });
}

// ── Fasteners auto-save selects ──
document.querySelectorAll('#fasteners-table select.auto-save').forEach(function(el) {
    el.addEventListener('change', function() {
        var action = this.dataset.action;
        var id = this.dataset.id;
        var field = this.dataset.field;
        var value = this.value;
        var self = this;
        var tr = this.closest('tr');
        sendAjax(action, { id: id, field: field, value: value }, function() {
            flashField(self);
            showToast();
        });
        if (field === 'supplier_id' && tr) {
            tr.dataset.supplier = value;
        }
    });
});

// ── Fasteners auto-save inputs ──
document.querySelectorAll('#fasteners-table input.auto-save').forEach(function(el) {
    el.addEventListener('change', function() {
        saveField(this);
    });
});

// ── Fasteners supplier filter ──
var fastenerFilter = document.getElementById('fastener-supplier-filter');
if (fastenerFilter) {
    fastenerFilter.addEventListener('change', function() {
        var val = this.value;
        document.querySelectorAll('#section-fasteners tbody tr').forEach(function(tr) {
            if (val === 'all') { tr.style.display = ''; }
            else { tr.style.display = tr.dataset.supplier === val ? '' : 'none'; }
        });
    });
}

// ── Enclosures auto-save selects ──
document.querySelectorAll('#enclosures-table select.auto-save').forEach(function(el) {
    el.addEventListener('change', function() {
        var action = this.dataset.action;
        var id = this.dataset.id;
        var fastenerId = this.value;
        var self = this;
        sendAjax(action, { id: id, fastener_id: fastenerId }, function() {
            flashField(self);
            showToast();
        });
    });
});

// ── Enclosure form submit ──
document.getElementById('add-enclosure-form').addEventListener('submit', function(e) {
    e.preventDefault();
    var nameInput = this.querySelector('input[name="name"]');
    var fastenerSelect = this.querySelector('select[name="fastener_id"]');
    sendAjax('add_enclosure', { name: nameInput.value, fastener_id: fastenerSelect.value }, function() {
        location.reload();
    });
});

// ── Material form submit ──
document.getElementById('add-material-form').addEventListener('submit', function(e) {
    e.preventDefault();
    var fd = new FormData(this);
    sendAjax('add_material', { new_material_name: fd.get('new_material_name'), new_material_unit: fd.get('new_material_unit'), currency_id: fd.get('currency_id'), supplier_id: fd.get('supplier_id') }, function() {
        location.reload();
    });
});

// ── Fastener form submit ──
document.getElementById('add-fastener-form').addEventListener('submit', function(e) {
    e.preventDefault();
    var fd = new FormData(this);
    sendAjax('add_fastener', {
        new_fastener_name: fd.get('new_fastener_name'),
        new_fastener_unit: fd.get('new_fastener_unit'),
        new_fastener_qty: fd.get('new_fastener_qty'),
        new_fastener_price: fd.get('new_fastener_price'),
        currency_id: fd.get('currency_id'),
        supplier_id: fd.get('supplier_id')
    }, function() {
        location.reload();
    });
});

// ── Sub-items auto-save inputs ──
document.querySelectorAll('#section-sub-items input.auto-save').forEach(function(el) {
    el.addEventListener('change', function() {
        saveField(this);
        var field = this.dataset.field;
        var tr = this.closest('tr');
        if (tr && (field === 'price_per_piece' || field === 'price_per_unit' || field === 'quantity_per_piece')) {
            syncSubItemPrices(field, tr);
        }
    });
    el.addEventListener('input', function() {
        var field = this.dataset.field;
        var tr = this.closest('tr');
        if (tr && (field === 'price_per_piece' || field === 'price_per_unit' || field === 'quantity_per_piece')) {
            syncSubItemPricesLive(field, tr);
        }
    });
});

// ── Sub-items auto-save selects ──
document.querySelectorAll('#section-sub-items select.auto-save').forEach(function(el) {
    el.addEventListener('change', function() {
        saveField(this);
        if (this.dataset.field === 'supplier_id') {
            var tr = this.closest('tr');
            if (tr) tr.dataset.supplier = this.value;
        }
    });
});

function syncSubItemPricesLive(changedField, tr) {
    var qtyEl = tr.querySelector('[data-field="quantity_per_piece"]');
    var pieceInput = tr.querySelector('[data-field="price_per_piece"]');
    var unitInput = tr.querySelector('[data-field="price_per_unit"]');
    if (!qtyEl || !pieceInput || !unitInput) return;
    var qty = parseFloat(qtyEl.value) || 0;
    if (changedField === 'price_per_piece' && qty > 0) {
        unitInput.value = round2(parseFloat(pieceInput.value) / qty);
    } else if (changedField === 'price_per_unit' && qty > 0) {
        pieceInput.value = round2(parseFloat(unitInput.value) * qty);
    } else if (changedField === 'quantity_per_piece') {
        if (qty > 0) { unitInput.value = round2(parseFloat(pieceInput.value) / qty); }
    }
}

function syncSubItemPrices(changedField, tr) {
    syncSubItemPricesLive(changedField, tr);
    var pieceInput = tr.querySelector('[data-field="price_per_piece"]');
    var unitInput = tr.querySelector('[data-field="price_per_unit"]');
    if (pieceInput) { saveField(pieceInput); flashField(pieceInput, 'recalc'); }
    if (unitInput) { saveField(unitInput); flashField(unitInput, 'recalc'); }
}

// ── Sub-items supplier filter ──
var subItemFilter = document.getElementById('subitem-supplier-filter');
if (subItemFilter) {
    subItemFilter.addEventListener('change', function() {
        var val = this.value;
        document.querySelectorAll('#section-sub-items tbody tr').forEach(function(tr) {
            if (val === 'all') { tr.style.display = ''; }
            else { tr.style.display = tr.dataset.supplier === val ? '' : 'none'; }
        });
    });
}

// ── Sub-item form submit ──
document.getElementById('add-sub-item-form').addEventListener('submit', function(e) {
    e.preventDefault();
    var fd = new FormData(this);
    sendAjax('add_sub_item', {
        new_sub_item_name: fd.get('new_sub_item_name'),
        new_sub_item_unit: fd.get('unit'),
        supplier_id: fd.get('supplier_id'),
        currency_id: fd.get('currency_id'),
        width_mm: fd.get('width_mm'),
        thickness_mm: fd.get('thickness_mm')
    }, function() {
        location.reload();
    });
});

// ═══ PARTITION JS ═══
function delFormula(id) {
    if (!confirm('Удалить формулу?')) return;
    document.getElementById('del-id').value = id;
    document.getElementById('del-form').submit();
}

var typeSel = document.getElementById('partition_type_id');
var formulaInput = document.getElementById('formula');
var fieldsRef = document.getElementById('fields-ref');

function filterFieldRefs() {
    var tid = typeSel?.value || '0';
    fieldsRef?.querySelectorAll('span[data-key]').forEach(function(span) {
        var spanType = span.dataset.type || '0';
        span.style.display = (tid === '0' || spanType === '0' || spanType === tid) ? 'inline-block' : 'none';
    });
}
if (typeSel) typeSel.addEventListener('change', filterFieldRefs);
filterFieldRefs();

fieldsRef?.querySelectorAll('span[data-key]').forEach(function(span) {
    span.addEventListener('click', function() {
        var key = span.dataset.key;
        var start = formulaInput.selectionStart || formulaInput.value.length;
        var end = formulaInput.selectionEnd || formulaInput.value.length;
        formulaInput.value = formulaInput.value.slice(0, start) + key + formulaInput.value.slice(end);
        formulaInput.focus();
        var pos = start + key.length;
        formulaInput.setSelectionRange(pos, pos);
    });
});
</script>
</body>
</html>
