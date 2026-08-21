<?php
require_once __DIR__ . '/includes/admin_auth.php';
require_once __DIR__ . '/includes/app_header.php';
require_admin();
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/admin_schema.php';
ensure_calculator_tables($pdo);
ensure_parameters_table($pdo);
ensure_partition_constructor_table($pdo);

$pdo->exec("CREATE TABLE IF NOT EXISTS partition_compositions (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    partition_type_id INT NOT NULL,
    condition_field VARCHAR(60) NULL,
    condition_operator ENUM('=','>','>=','<','<=','any') NOT NULL DEFAULT 'any',
    condition_value VARCHAR(60) NULL,
    parts TEXT NOT NULL,
    note TEXT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TABLE IF NOT EXISTS partition_type_fields (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    partition_type_id INT NOT NULL,
    field_key VARCHAR(60) NOT NULL,
    field_label VARCHAR(120) NOT NULL,
    field_type ENUM('number','text','select') NOT NULL DEFAULT 'number',
    field_unit VARCHAR(30) NULL,
    default_value VARCHAR(120) NULL,
    sort_order INT NOT NULL DEFAULT 0,
    is_required TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TABLE IF NOT EXISTS partition_formulas (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    partition_type_id INT NULL,
    name VARCHAR(150) NOT NULL,
    target_key VARCHAR(60) NOT NULL,
    formula TEXT NOT NULL,
    unit VARCHAR(30) NULL,
    note TEXT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$activeTab = $_GET['tab'] ?? 'types';
$validTabs = ['types', 'params', 'constructor', 'formulas'];
if (!in_array($activeTab, $validTabs, true)) $activeTab = 'types';

$errors = [];
$editingType = null;
$editingParam = null;
$editingComp = null;
$editingRule = null;
$editingField = null;
$editingFormula = null;

function save_partition_type_parameters(PDO $pdo, int $typeId): void
{
    $pdo->prepare('DELETE FROM partition_type_parameters WHERE partition_type_id = :id')->execute(['id' => $typeId]);
    $parameterIds = $_POST['parameter_id'] ?? [];
    $sortOrders = $_POST['sort_order'] ?? [];
    $defaultOverrides = $_POST['default_value_override'] ?? [];
    $requiredFlags = $_POST['is_required'] ?? [];
    $stmt = $pdo->prepare('INSERT IGNORE INTO partition_type_parameters (partition_type_id, parameter_id, sort_order, is_required, default_value_override) VALUES (:partition_type_id, :parameter_id, :sort_order, :is_required, :default_value_override)');
    foreach ($parameterIds as $index => $parameterIdRaw) {
        $parameterId = (int)$parameterIdRaw;
        if ($parameterId <= 0) continue;
        $override = trim((string)($defaultOverrides[$index] ?? ''));
        $stmt->execute([
            'partition_type_id' => $typeId,
            'parameter_id' => $parameterId,
            'sort_order' => (int)($sortOrders[$index] ?? ($index + 1) * 10),
            'is_required' => isset($requiredFlags[$index]) ? 1 : 0,
            'default_value_override' => $override === '' ? null : $override,
        ]);
    }
}

function save_partition_type_services(PDO $pdo, int $typeId): void
{
    $pdo->prepare('DELETE FROM partition_type_services WHERE partition_type_id = :id')->execute(['id' => $typeId]);
    $stmt = $pdo->prepare('INSERT IGNORE INTO partition_type_services (partition_type_id, service_id, sort_order) VALUES (:type_id, :service_id, :sort_order)');
    foreach (array_values(array_unique(array_map('intval', $_POST['service_id'] ?? []))) as $index => $serviceId) {
        if ($serviceId <= 0) continue;
        $stmt->execute(['type_id' => $typeId, 'service_id' => $serviceId, 'sort_order' => ($index + 1) * 10]);
    }
}

$operators = ['='=>'равно','!='=>'не равно','>'=>'больше','>='=>'больше или равно','<'=>'меньше','<='=>'меньше или равно','contains'=>'содержит','any'=>'любое'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── ТИПЫ ПЕРЕГОРОДОК ──
    if ($action === 'delete_type') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) $pdo->prepare('DELETE FROM partition_types WHERE id = :id')->execute(['id' => $id]);
        header('Location: admin_partitions.php?tab=types'); exit;
    }
    if (in_array($action, ['create_type', 'update_type'])) {
        $id = (int)($_POST['id'] ?? 0);
        $currentDrawing = null;
        $currentPhoto = null;
        if ($id > 0) {
            $stmt = $pdo->prepare('SELECT drawing_path, photo_path FROM partition_types WHERE id = :id');
            $stmt->execute(['id' => $id]);
            $row = $stmt->fetch();
            $currentDrawing = $row['drawing_path'] ?? null;
            $currentPhoto = $row['photo_path'] ?? null;
        }
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        if ($name === '') $errors[] = 'Укажите название типа перегородки.';
        $drawingPath = upload_file('drawing', 'partition_drawings', $errors, $currentDrawing);
        $photoPath = upload_image('photo', 'partition_photos', $errors, $currentPhoto);
        if (!$errors) {
            $params = ['name' => $name, 'drawing_path' => $drawingPath, 'photo_path' => $photoPath, 'description' => $description === '' ? null : $description, 'is_active' => $isActive];
            if ($action === 'update_type' && $id > 0) {
                $params['id'] = $id;
                $pdo->prepare('UPDATE partition_types SET name=:name,drawing_path=:drawing_path,photo_path=:photo_path,description=:description,is_active=:is_active WHERE id=:id')->execute($params);
                $typeId = $id;
            } else {
                $pdo->prepare('INSERT INTO partition_types (name,drawing_path,photo_path,description,is_active) VALUES (:name,:drawing_path,:photo_path,:description,:is_active)')->execute($params);
                $typeId = (int)$pdo->lastInsertId();
            }
            save_partition_type_parameters($pdo, $typeId);
            save_partition_type_services($pdo, $typeId);
            header('Location: admin_partitions.php?tab=types'); exit;
        }
        $activeTab = 'types';
    }

    // ── ПАРАМЕТРЫ ──
    if ($action === 'delete_param') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) $pdo->prepare('DELETE FROM calculation_parameters WHERE id=:id')->execute(['id' => $id]);
        header('Location: admin_partitions.php?tab=params'); exit;
    }
    if (in_array($action, ['create_param', 'update_param'])) {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['param_name'] ?? '');
        $defaultValue = trim($_POST['param_default_value'] ?? '');
        $unitId = (int)($_POST['param_unit_id'] ?? 0);
        $note = trim($_POST['param_note'] ?? '');
        $isActive = isset($_POST['param_is_active']) ? 1 : 0;
        if ($name === '') $errors[] = 'Укажите название параметра.';
        if (!$errors) {
            $p = ['name' => $name, 'default_value' => $defaultValue === '' ? null : $defaultValue, 'unit_id' => $unitId > 0 ? $unitId : null, 'note' => $note === '' ? null : $note, 'is_active' => $isActive];
            if ($action === 'update_param' && $id > 0) {
                $p['id'] = $id;
                $pdo->prepare('UPDATE calculation_parameters SET name=:name,default_value=:default_value,unit_id=:unit_id,note=:note,is_active=:is_active WHERE id=:id')->execute($p);
            } else {
                $pdo->prepare('INSERT INTO calculation_parameters (name,default_value,unit_id,note,is_active) VALUES (:name,:default_value,:unit_id,:note,:is_active)')->execute($p);
            }
            header('Location: admin_partitions.php?tab=params'); exit;
        }
        $activeTab = 'params';
    }

    // ── СОСТАВ ДЕТАЛЕЙ ──
    if ($action === 'delete_comp') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) $pdo->prepare('DELETE FROM partition_compositions WHERE id=:id')->execute(['id' => $id]);
        header('Location: admin_partitions.php?tab=params'); exit;
    }
    if (in_array($action, ['create_comp', 'update_comp'])) {
        $compId = (int)($_POST['comp_id'] ?? 0);
        $typeId = (int)($_POST['comp_type_id'] ?? 0);
        $condField = trim($_POST['comp_condition_field'] ?? '');
        $condOp = $_POST['comp_condition_operator'] ?? 'any';
        $condVal = trim($_POST['comp_condition_value'] ?? '');
        $note = trim($_POST['comp_note'] ?? '');
        $sortOrder = (int)($_POST['comp_sort'] ?? 0);
        $partsRaw = array_filter(array_map('trim', explode("\n", $_POST['comp_parts'] ?? '')));
        if ($typeId <= 0) $errors[] = 'Выберите тип перегородки.';
        if (empty($partsRaw)) $errors[] = 'Добавьте хотя бы одну деталь.';
        if (!isset($operators[$condOp])) $condOp = 'any';
        if (!$errors) {
            $partsJson = json_encode(array_values($partsRaw), JSON_UNESCAPED_UNICODE);
            $p = ['partition_type_id' => $typeId, 'condition_field' => $condField === '' ? null : $condField, 'condition_operator' => $condOp, 'condition_value' => $condVal === '' ? null : $condVal, 'parts' => $partsJson, 'note' => $note === '' ? null : $note, 'sort_order' => $sortOrder];
            if ($action === 'update_comp' && $compId > 0) {
                $p['id'] = $compId;
                $pdo->prepare('UPDATE partition_compositions SET partition_type_id=:partition_type_id,condition_field=:condition_field,condition_operator=:condition_operator,condition_value=:condition_value,parts=:parts,note=:note,sort_order=:sort_order WHERE id=:id')->execute($p);
            } else {
                $pdo->prepare('INSERT INTO partition_compositions (partition_type_id,condition_field,condition_operator,condition_value,parts,note,sort_order) VALUES (:partition_type_id,:condition_field,:condition_operator,:condition_value,:parts,:note,:sort_order)')->execute($p);
            }
            header('Location: admin_partitions.php?tab=params'); exit;
        }
        $activeTab = 'params';
    }

    // ── ПРАВИЛА КОНСТРУКТОРА ──
    if ($action === 'delete_rule') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) $pdo->prepare('DELETE FROM partition_constructor_rules WHERE id=:id')->execute(['id' => $id]);
        header('Location: admin_partitions.php?tab=constructor'); exit;
    }
    if (in_array($action, ['create_rule', 'update_rule'])) {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['rule_name'] ?? '');
        $partitionTypeId = (int)($_POST['rule_partition_type_id'] ?? 0);
        $manufacturerId = (int)($_POST['rule_manufacturer_id'] ?? 0);
        $conditionParameter = trim($_POST['rule_condition_parameter'] ?? '');
        $conditionOperator = $_POST['rule_condition_operator'] ?? '=';
        $conditionValue = trim($_POST['rule_condition_value'] ?? '');
        $actionTarget = trim($_POST['rule_action_target'] ?? '');
        $actionFormula = trim($_POST['rule_action_formula'] ?? '');
        $note = trim($_POST['rule_note'] ?? '');
        $isActive = isset($_POST['rule_is_active']) ? 1 : 0;
        if ($name === '') $errors[] = 'Укажите название правила.';
        if ($actionTarget === '') $errors[] = 'Укажите целевой результат правила.';
        if (!$errors) {
            $params = ['name' => $name, 'partition_type_id' => $partitionTypeId > 0 ? $partitionTypeId : null, 'manufacturer_id' => $manufacturerId > 0 ? $manufacturerId : null, 'condition_parameter' => $conditionParameter === '' ? null : $conditionParameter, 'condition_operator' => $conditionOperator, 'condition_value' => $conditionValue === '' ? null : $conditionValue, 'action_target' => $actionTarget, 'action_formula' => $actionFormula === '' ? null : $actionFormula, 'note' => $note === '' ? null : $note, 'is_active' => $isActive];
            if ($action === 'update_rule' && $id > 0) {
                $params['id'] = $id;
                $pdo->prepare('UPDATE partition_constructor_rules SET name=:name,partition_type_id=:partition_type_id,manufacturer_id=:manufacturer_id,condition_parameter=:condition_parameter,condition_operator=:condition_operator,condition_value=:condition_value,action_target=:action_target,action_formula=:action_formula,note=:note,is_active=:is_active WHERE id=:id')->execute($params);
            } else {
                $pdo->prepare('INSERT INTO partition_constructor_rules (name,partition_type_id,manufacturer_id,condition_parameter,condition_operator,condition_value,action_target,action_formula,note,is_active) VALUES (:name,:partition_type_id,:manufacturer_id,:condition_parameter,:condition_operator,:condition_value,:action_target,:action_formula,:note,:is_active)')->execute($params);
            }
            header('Location: admin_partitions.php?tab=constructor'); exit;
        }
        $activeTab = 'constructor';
    }

    // ── ПОЛЯ ТИПОВ ПЕРЕГОРОДОК ──
    if ($action === 'delete_field') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) $pdo->prepare('DELETE FROM partition_type_fields WHERE id=:id')->execute(['id' => $id]);
        header('Location: admin_partitions.php?tab=constructor'); exit;
    }
    if (in_array($action, ['create_field', 'update_field'])) {
        $fieldId = (int)($_POST['field_id'] ?? 0);
        $typeId = (int)($_POST['field_partition_type_id'] ?? 0);
        $fieldKey = trim($_POST['field_key'] ?? '');
        $fieldLabel = trim($_POST['field_label'] ?? '');
        $fieldType = in_array($_POST['field_type'] ?? 'number', ['number', 'text', 'select']) ? $_POST['field_type'] : 'number';
        $fieldUnit = trim($_POST['field_unit'] ?? '');
        $defaultValue = trim($_POST['field_default'] ?? '');
        $sortOrder = (int)($_POST['field_sort'] ?? 0);
        $isRequired = isset($_POST['field_required']) ? 1 : 0;
        if ($typeId <= 0) $errors[] = 'Выберите тип перегородки.';
        if ($fieldKey === '') $errors[] = 'Укажите ключ поля (латиницей, без пробелов).';
        if ($fieldLabel === '') $errors[] = 'Укажите название поля.';
        if (!$errors) {
            $p = ['partition_type_id' => $typeId, 'field_key' => $fieldKey, 'field_label' => $fieldLabel, 'field_type' => $fieldType, 'field_unit' => $fieldUnit === '' ? null : $fieldUnit, 'default_value' => $defaultValue === '' ? null : $defaultValue, 'sort_order' => $sortOrder, 'is_required' => $isRequired];
            if ($action === 'update_field' && $fieldId > 0) {
                $p['id'] = $fieldId;
                $pdo->prepare('UPDATE partition_type_fields SET partition_type_id=:partition_type_id,field_key=:field_key,field_label=:field_label,field_type=:field_type,field_unit=:field_unit,default_value=:default_value,sort_order=:sort_order,is_required=:is_required WHERE id=:id')->execute($p);
            } else {
                $pdo->prepare('INSERT INTO partition_type_fields (partition_type_id,field_key,field_label,field_type,field_unit,default_value,sort_order,is_required) VALUES (:partition_type_id,:field_key,:field_label,:field_type,:field_unit,:default_value,:sort_order,:is_required)')->execute($p);
            }
            header('Location: admin_partitions.php?tab=constructor'); exit;
        }
        $activeTab = 'constructor';
    }

    // ── ФОРМУЛЫ ──
    if ($action === 'delete_formula') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) $pdo->prepare('DELETE FROM partition_formulas WHERE id=:id')->execute(['id' => $id]);
        header('Location: admin_partitions.php?tab=formulas'); exit;
    }
    if (in_array($action, ['create_formula', 'update_formula'])) {
        $id = (int)($_POST['id'] ?? 0);
        $typeId = (int)($_POST['formula_partition_type_id'] ?? 0);
        $name = trim($_POST['formula_name'] ?? '');
        $targetKey = trim($_POST['formula_target_key'] ?? '');
        $formula = trim($_POST['formula_formula'] ?? '');
        $unit = trim($_POST['formula_unit'] ?? '');
        $note = trim($_POST['formula_note'] ?? '');
        $sortOrder = (int)($_POST['formula_sort_order'] ?? 0);
        $isActive = isset($_POST['formula_is_active']) ? 1 : 0;
        if ($name === '') $errors[] = 'Укажите название формулы.';
        if ($targetKey === '') $errors[] = 'Укажите ключ результата (что считаем).';
        if ($formula === '') $errors[] = 'Укажите саму формулу.';
        if ($formula !== '' && !preg_match('/^[a-zA-Z0-9_\.\+\-\*\/\(\)\s,<>=!?:]+$/u', $formula)) {
            $errors[] = 'Формула содержит недопустимые символы.';
        }
        if (!$errors) {
            $params = ['partition_type_id' => $typeId > 0 ? $typeId : null, 'name' => $name, 'target_key' => $targetKey, 'formula' => $formula, 'unit' => $unit === '' ? null : $unit, 'note' => $note === '' ? null : $note, 'sort_order' => $sortOrder, 'is_active' => $isActive];
            if ($action === 'update_formula' && $id > 0) {
                $params['id'] = $id;
                $pdo->prepare('UPDATE partition_formulas SET partition_type_id=:partition_type_id,name=:name,target_key=:target_key,formula=:formula,unit=:unit,note=:note,sort_order=:sort_order,is_active=:is_active WHERE id=:id')->execute($params);
            } else {
                $pdo->prepare('INSERT INTO partition_formulas (partition_type_id,name,target_key,formula,unit,note,sort_order,is_active) VALUES (:partition_type_id,:name,:target_key,:formula,:unit,:note,:sort_order,:is_active)')->execute($params);
            }
            header('Location: admin_partitions.php?tab=formulas'); exit;
        }
        $activeTab = 'formulas';
    }
}

// ═══ EDITING STATES ═══
if ($activeTab === 'types') {
    $editId = (int)($_GET['edit_type'] ?? 0);
    if ($editId > 0) { $stmt = $pdo->prepare('SELECT * FROM partition_types WHERE id=:id'); $stmt->execute(['id' => $editId]); $editingType = $stmt->fetch() ?: null; }
}
if ($activeTab === 'params') {
    $editParamId = (int)($_GET['edit_param'] ?? 0);
    if ($editParamId > 0) { $s = $pdo->prepare('SELECT * FROM calculation_parameters WHERE id=:id'); $s->execute(['id' => $editParamId]); $editingParam = $s->fetch() ?: null; }
    $editCompId = (int)($_GET['edit_comp'] ?? 0);
    if ($editCompId > 0) { $s = $pdo->prepare('SELECT * FROM partition_compositions WHERE id=:id'); $s->execute(['id' => $editCompId]); $editingComp = $s->fetch() ?: null; }
}
if ($activeTab === 'constructor') {
    $editRuleId = (int)($_GET['edit_rule'] ?? 0);
    if ($editRuleId > 0) { $s = $pdo->prepare('SELECT * FROM partition_constructor_rules WHERE id=:id'); $s->execute(['id' => $editRuleId]); $editingRule = $s->fetch() ?: null; }
    $editFieldId = (int)($_GET['edit_field'] ?? 0);
    if ($editFieldId > 0) { $s = $pdo->prepare('SELECT * FROM partition_type_fields WHERE id=:id'); $s->execute(['id' => $editFieldId]); $editingField = $s->fetch() ?: null; }
}
if ($activeTab === 'formulas') {
    $editFormulaId = (int)($_GET['edit_formula'] ?? 0);
    if ($editFormulaId > 0) { $s = $pdo->prepare('SELECT * FROM partition_formulas WHERE id=:id'); $s->execute(['id' => $editFormulaId]); $editingFormula = $s->fetch() ?: null; }
}

// ═══ DATA LOADING ═══
$types = $pdo->query('SELECT pt.*, COUNT(ptp.id) AS parameters_count FROM partition_types pt LEFT JOIN partition_type_parameters ptp ON ptp.partition_type_id = pt.id GROUP BY pt.id ORDER BY pt.is_active DESC, pt.name ASC')->fetchAll();
$allTypes = $pdo->query('SELECT * FROM partition_types ORDER BY name ASC')->fetchAll();
$parameters = $pdo->query('SELECT cp.*, mu.short_name AS unit_short_name FROM calculation_parameters cp LEFT JOIN measurement_units mu ON mu.id = cp.unit_id WHERE cp.is_active = 1 ORDER BY cp.name ASC')->fetchAll();
$allParameters = $pdo->query('SELECT cp.*, mu.short_name AS unit_short_name FROM calculation_parameters cp LEFT JOIN measurement_units mu ON mu.id = cp.unit_id ORDER BY cp.is_active DESC, cp.name ASC')->fetchAll();
$units = $pdo->query('SELECT * FROM measurement_units WHERE is_active = 1 ORDER BY full_name ASC')->fetchAll();
$manufacturers = $pdo->query('SELECT * FROM manufacturers ORDER BY full_name ASC')->fetchAll();
$compositions = $pdo->query('SELECT pc.*, pt.name AS type_name FROM partition_compositions pc LEFT JOIN partition_types pt ON pt.id = pc.partition_type_id ORDER BY pt.name, pc.sort_order, pc.id')->fetchAll();
$rules = $pdo->query('SELECT r.*, pt.name AS partition_type_name, m.full_name AS manufacturer_name FROM partition_constructor_rules r LEFT JOIN partition_types pt ON pt.id = r.partition_type_id LEFT JOIN manufacturers m ON m.id = r.manufacturer_id ORDER BY r.is_active DESC, r.id DESC')->fetchAll();
$typeFields = $pdo->query('SELECT f.*, pt.name AS type_name FROM partition_type_fields f LEFT JOIN partition_types pt ON pt.id = f.partition_type_id ORDER BY pt.name, f.sort_order, f.id')->fetchAll();
$compParts = $pdo->query('SELECT partition_type_id, parts FROM partition_compositions ORDER BY sort_order, id')->fetchAll();
$formulas = $pdo->query('SELECT f.*, pt.name AS type_name FROM partition_formulas f LEFT JOIN partition_types pt ON pt.id = f.partition_type_id ORDER BY pt.name, f.sort_order, f.id')->fetchAll();
$services = $pdo->query('SELECT s.id, s.nomenclature, s.name, s.unit, s.price, s.currency, s.h_size, s.d_size, s.step_mm, pt.thickness FROM services s LEFT JOIN panel_thicknesses pt ON pt.id = s.thickness_id WHERE s.is_active = 1 ORDER BY s.name ASC, pt.thickness ASC, s.h_size ASC, s.d_size ASC, s.step_mm ASC, s.id ASC')->fetchAll();

$assignedParameters = [];
$assignedServiceIds = [];
if ($editingType) {
    $stmt = $pdo->prepare('SELECT ptp.*, cp.name, cp.default_value, mu.short_name AS unit_short_name FROM partition_type_parameters ptp JOIN calculation_parameters cp ON cp.id = ptp.parameter_id LEFT JOIN measurement_units mu ON mu.id = cp.unit_id WHERE ptp.partition_type_id = :id ORDER BY ptp.sort_order ASC, cp.name ASC');
    $stmt->execute(['id' => $editingType['id']]);
    $assignedParameters = $stmt->fetchAll();
    $stmt = $pdo->prepare('SELECT service_id FROM partition_type_services WHERE partition_type_id = :id ORDER BY sort_order, id');
    $stmt->execute(['id' => $editingType['id']]);
    $assignedServiceIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

$typeFieldsByType = [];
foreach ($typeFields as $f) {
    $typeFieldsByType[(int)$f['partition_type_id']][] = ['key' => $f['field_key'], 'label' => $f['field_label']];
}
$typeFieldsJson = json_encode($typeFieldsByType, JSON_UNESCAPED_UNICODE);

$partsByType = [];
foreach ($compParts as $cp) {
    $parts = json_decode($cp['parts'] ?? '[]', true) ?: [];
    $tid = (int)$cp['partition_type_id'];
    foreach ($parts as $part) {
        if ($part && !in_array($part, $partsByType[$tid] ?? [])) {
            $partsByType[$tid][] = $part;
        }
    }
}
$partsJson = json_encode($partsByType, JSON_UNESCAPED_UNICODE);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<title>Перегородки</title>
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
.param-row{display:grid;grid-template-columns:30px 2fr 130px 1fr auto;gap:10px;align-items:end;margin-bottom:10px;padding:8px;border:1px solid transparent;border-radius:8px;transition:border-color .15s,background .15s}
.param-row.dragged{opacity:.4}
.param-row.drag-over{border-color:#2563eb;background:#eff6ff}
.drag-handle{display:flex;align-items:center;justify-content:center;cursor:grab;color:#94a3b8;font-size:18px;user-select:none}
.type-group{background:#f8fafc;border-left:4px solid #2563eb;padding:8px 12px;margin:16px 0 8px;font-weight:700;border-radius:0 6px 6px 0}
.formula-code{font-family:'Courier New',monospace;background:#f1f5f9;padding:4px 8px;border-radius:6px;font-size:13px;color:#1e40af}
.fields-ref{display:flex;flex-wrap:wrap;gap:6px;margin-top:8px}
.fields-ref span{background:#eff6ff;color:#1d4ed8;border-radius:6px;padding:3px 8px;font-size:12px;font-family:'Courier New',monospace;cursor:pointer}
.fields-ref span:hover{background:#dbeafe}
.parts-chain{display:flex;flex-wrap:wrap;gap:6px;margin-top:8px}
.part-tag{background:#dbeafe;color:#1e40af;border-radius:20px;padding:4px 12px;font-size:13px;font-weight:600}
.part-tag::before{content:"→ ";opacity:.5}
.part-tag:first-child::before{content:""}
.service-options{display:grid;grid-template-columns:repeat(auto-fit,minmax(360px,1fr));gap:8px;max-height:280px;overflow:auto;padding:12px;border:1px solid #cbd5e1;border-radius:12px;background:#f8fafc}.service-option{display:grid;grid-template-columns:auto minmax(0,1fr);align-items:start;gap:8px;margin:0;padding:9px;border-radius:8px;background:#fff}.service-option input{margin-top:3px}.service-option__content{min-width:0}.service-option__name{display:block}.service-option__details{display:flex;flex-wrap:wrap;gap:4px 12px;margin-top:5px;color:#64748b;font-size:12px;font-weight:400}.service-option__details span{white-space:nowrap}.service-option__code{color:#475569;font-weight:700}
</style>
<?php echo app_header_styles(); ?>
</head>
<body>
<?php render_app_header(); ?>
<main class="container">
    <div class="quick-links">
        <a class="quick-link <?php echo $activeTab==='types'?'active':'';?>" href="admin_partitions.php?tab=types">🧱 Типы перегородок</a>
        <a class="quick-link <?php echo $activeTab==='params'?'active':'';?>" href="admin_partitions.php?tab=params">⚙️ Параметры</a>
        <a class="quick-link <?php echo $activeTab==='constructor'?'active':'';?>" href="admin_partitions.php?tab=constructor">🔧 Конструктор</a>
        <a class="quick-link <?php echo $activeTab==='formulas'?'active':'';?>" href="admin_partitions.php?tab=formulas">📐 Формулы</a>
    </div>

<?php if ($errors): ?><div class="errors"><?php echo e(implode(' ', $errors)); ?></div><?php endif; ?>

<!-- ════════════ ВКЛАДКА: ТИПЫ ПЕРЕГОРОДОК ════════════ -->
<div class="tab-pane <?php echo $activeTab==='types'?'active':''; ?>" id="tab-types">
    <section class="panel">
        <h2><?php echo $editingType ? 'Редактировать тип' : 'Добавить тип'; ?></h2>
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="action" value="<?php echo $editingType ? 'update_type' : 'create_type'; ?>">
            <input type="hidden" name="id" value="<?php echo e((string)($editingType['id'] ?? '')); ?>">
            <div class="grid">
                <div><label for="type_name">Название</label><input id="type_name" name="name" required value="<?php echo e((string)($editingType['name'] ?? '')); ?>"></div>
                <div><label for="drawing">Чертеж</label><?php if (!empty($editingType['drawing_path'])): ?><p><a href="<?php echo e($editingType['drawing_path']); ?>" target="_blank">Открыть текущий чертеж</a></p><?php endif; ?><input id="drawing" type="file" name="drawing" accept=".pdf,.dwg,.dxf,.jpg,.jpeg,.png,.tif,.tiff,.webp"><div class="hint">PDF, DWG, DXF или изображение до 100 мб.</div></div>
                <div><label for="photo">Фото</label><?php if (!empty($editingType['photo_path'])): ?><p><img class="preview" src="<?php echo e($editingType['photo_path']); ?>" alt="Фото типа"></p><?php endif; ?><input id="photo" type="file" name="photo" accept=".jpg,.jpeg,.png,.tif,.tiff,.webp,image/jpeg,image/png,image/tiff,image/webp"><p><img class="preview" id="photo_preview" src="<?php echo e((string)($editingType['photo_path'] ?? '')); ?>" alt="Превью" style="<?php echo empty($editingType['photo_path']) ? 'display:none;' : ''; ?>"></p></div>
            </div>
            <p><label for="description">Описание</label><textarea id="description" name="description"><?php echo e((string)($editingType['description'] ?? '')); ?></textarea></p>
            <p><label><input type="checkbox" name="is_active" <?php echo !isset($editingType['is_active']) || (int)$editingType['is_active'] === 1 ? 'checked' : ''; ?>> Активен</label></p>
            <div class="section-title">Параметры типа</div>
            <div id="parameter-rows">
                <?php if ($editingType): ?>
                    <?php foreach ($assignedParameters as $idx => $ap): ?>
                        <div class="param-row" draggable="true">
                            <div class="drag-handle">⣿</div>
                            <div><label>Параметр</label><select name="parameter_id[]"><?php foreach ($parameters as $p): ?><option value="<?php echo e((string)$p['id']); ?>" <?php echo (int)$p['id'] === (int)$ap['parameter_id'] ? 'selected' : ''; ?>><?php echo e($p['name']); ?><?php echo $p['unit_short_name'] ? ' (' . e($p['unit_short_name']) . ')' : ''; ?></option><?php endforeach; ?></select></div>
                            <div><label><input type="checkbox" name="is_required[<?php echo $idx; ?>]" <?php echo (int)$ap['is_required'] ? 'checked' : ''; ?>> Обяз.</label></div>
                            <div><label>Переопределить значение</label><input name="default_value_override[]" value="<?php echo e((string)($ap['default_value_override'] ?? '')); ?>"></div>
                            <button class="danger" type="button">Удалить</button>
                            <input type="hidden" name="sort_order[]" value="<?php echo e((string)$ap['sort_order']); ?>">
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <p><button class="button secondary" type="button" id="add-parameter">+ Добавить параметр</button></p>
            <div class="section-title">Базовые услуги калькулятора</div>
            <p class="hint">Выбранные услуги автоматически попадут в расчёт для этого типа перегородки. Дополнительные услуги можно добавить уже после расчёта в разделе «Услуги».</p>
            <div class="service-options">
                <?php
                $serviceHLabels = ['no'=>'Нет','le_2_5'=>'≤ 2.5','2_5_to_5'=>'2.5–5','le_3'=>'≤ 3','3_to_6'=>'3–6'];
                $serviceDLabels = ['no'=>'Нет','le_4'=>'≤ 4','4_to_12'=>'4–12','gt_12'=>'> 12'];
                $serviceStepLabels = ['no'=>'Нет','16'=>'16','32'=>'32','64'=>'64'];
                ?>
                <?php foreach ($services as $service): ?>
                    <label class="service-option">
                        <input type="checkbox" name="service_id[]" value="<?php echo e((string)$service['id']); ?>" <?php echo in_array((int)$service['id'], $assignedServiceIds, true) ? 'checked' : ''; ?>>
                        <span class="service-option__content">
                            <span class="service-option__name"><?php echo e($service['name']); ?></span>
                            <span class="service-option__details">
                                <span class="service-option__code">Код: <?php echo e((string)($service['nomenclature'] ?: '—')); ?></span>
                                <span>Толщ.: <?php echo !empty($service['thickness']) ? e(rtrim(rtrim((string)$service['thickness'], '0'), '.') . ' мм') : '—'; ?></span>
                                <span>h: <?php echo e($serviceHLabels[$service['h_size'] ?? 'no'] ?? 'Нет'); ?></span>
                                <span>d: <?php echo e($serviceDLabels[$service['d_size'] ?? 'no'] ?? 'Нет'); ?></span>
                                <span>Шаг: <?php echo e($serviceStepLabels[$service['step_mm'] ?? 'no'] ?? 'Нет'); ?></span>
                                <span>Цена: <?php echo e(number_format((float)$service['price'], 2, ',', ' ')); ?> <?php echo e(app_currency_symbol((string)$service['currency'])); ?> / <?php echo e($service['unit']); ?></span>
                            </span>
                        </span>
                    </label>
                <?php endforeach; ?>
                <?php if (!$services): ?><div class="hint">Нет активных услуг. Сначала добавьте их в разделе «Услуги».</div><?php endif; ?>
            </div>
            <div class="form-actions"><button type="submit">Сохранить</button><?php if ($editingType): ?><a class="button secondary" href="admin_partitions.php?tab=types">Отмена</a><?php endif; ?></div>
        </form>
    </section>
    <section class="panel">
        <h2>Список типов перегородок</h2>
        <table>
            <thead><tr><th>Название</th><th>Чертеж</th><th>Фото</th><th>Параметров</th><th>Статус</th><th>Действия</th></tr></thead>
            <tbody>
            <?php foreach ($types as $t): ?>
                <tr>
                    <td><?php echo e($t['name']); ?><?php if ($t['description']): ?><div class="hint"><?php echo e(mb_strimwidth($t['description'], 0, 80, '…')); ?></div><?php endif; ?></td>
                    <td><?php if ($t['drawing_path']): ?><a href="<?php echo e($t['drawing_path']); ?>" target="_blank">📎</a><?php else: ?>—<?php endif; ?></td>
                    <td><?php if ($t['photo_path']): ?><img class="preview" src="<?php echo e($t['photo_path']); ?>" alt=""><?php else: ?>—<?php endif; ?></td>
                    <td><?php echo (int)$t['parameters_count']; ?></td>
                    <td class="status"><?php echo (int)$t['is_active'] === 1 ? 'Акт.' : 'Скрыт'; ?></td>
                    <td class="actions">
                        <a class="btn-icon btn-edit" href="admin_partitions.php?tab=types&edit_type=<?php echo e((string)$t['id']); ?>" title="Изменить"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg></a>
                        <button class="btn-icon btn-delete" type="button" title="Удалить" onclick="delType(<?php echo (int)$t['id']; ?>)"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg></button>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$types): ?><tr><td colspan="6">Типы перегородок пока не добавлены.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </section>
</div>

<!-- ════════════ ВКЛАДКА: ПАРАМЕТРЫ ════════════ -->
<div class="tab-pane <?php echo $activeTab==='params'?'active':''; ?>" id="tab-params">

    <section class="panel">
        <h2><?php echo $editingParam ? 'Редактировать параметр' : 'Добавить параметр'; ?></h2>
        <form method="post">
            <input type="hidden" name="action" value="<?php echo $editingParam ? 'update_param' : 'create_param'; ?>">
            <input type="hidden" name="id" value="<?php echo e((string)($editingParam['id'] ?? '')); ?>">
            <div class="grid">
                <div><label for="param_name">Название параметра</label><input id="param_name" name="param_name" required value="<?php echo e((string)($editingParam['name'] ?? '')); ?>"></div>
                <div><label for="param_default_value">Значение по умолчанию</label><input id="param_default_value" name="param_default_value" value="<?php echo e((string)($editingParam['default_value'] ?? '')); ?>"></div>
                <div><label for="param_unit_id">Единица измерения</label><select id="param_unit_id" name="param_unit_id"><option value="0">Без единицы</option><?php foreach ($units as $u): ?><option value="<?php echo e((string)$u['id']); ?>" <?php echo (int)($editingParam['unit_id'] ?? 0) === (int)$u['id'] ? 'selected' : ''; ?>><?php echo e($u['short_name']); ?> — <?php echo e($u['full_name']); ?></option><?php endforeach; ?></select></div>
            </div>
            <p><label for="param_note">Примечание</label><textarea id="param_note" name="param_note"><?php echo e((string)($editingParam['note'] ?? '')); ?></textarea></p>
            <p><label><input type="checkbox" name="param_is_active" <?php echo !isset($editingParam['is_active']) || (int)$editingParam['is_active'] === 1 ? 'checked' : ''; ?>> Активен</label></p>
            <div class="form-actions"><button type="submit">Сохранить</button><?php if ($editingParam): ?><a class="button secondary" href="admin_partitions.php?tab=params">Отмена</a><?php endif; ?></div>
        </form>
    </section>
    <section class="panel">
        <h2>Список параметров</h2>
        <table>
            <thead><tr><th>Название</th><th>По умолчанию</th><th>Ед. изм.</th><th>Примечание</th><th>Активен</th><th>Действия</th></tr></thead>
            <tbody>
            <?php foreach ($allParameters as $p): ?>
                <tr>
                    <td><?php echo e($p['name']); ?></td>
                    <td><?php echo e((string)($p['default_value'] ?? '')); ?></td>
                    <td><?php echo e((string)($p['unit_short_name'] ?? '')); ?></td>
                    <td><?php echo e((string)($p['note'] ?? '')); ?></td>
                    <td><?php echo (int)$p['is_active'] === 1 ? 'Да' : 'Нет'; ?></td>
                    <td class="actions">
                        <a class="btn-icon btn-edit" href="admin_partitions.php?tab=params&edit_param=<?php echo e((string)$p['id']); ?>" title="Изменить"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg></a>
                        <button class="btn-icon btn-delete" type="button" onclick="delParam(<?php echo (int)$p['id']; ?>)" title="Удалить"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg></button>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$allParameters): ?><tr><td colspan="6">Параметры пока не добавлены.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </section>

    <div class="section-title" style="margin-top:32px;">Состав деталей</div>

    <section class="panel">
        <h2><?php echo $editingComp ? 'Редактировать состав' : 'Добавить состав деталей'; ?></h2>
        <p class="hint">Опишите из каких деталей состоит перегородка в зависимости от условия.</p>
        <form method="post">
            <input type="hidden" name="action" value="<?php echo $editingComp ? 'update_comp' : 'create_comp'; ?>">
            <input type="hidden" name="comp_id" value="<?php echo e((string)($editingComp['id'] ?? '')); ?>">
            <div class="grid">
                <div>
                    <label for="comp_type_id">Тип перегородки</label>
                    <select id="comp_type_id" name="comp_type_id">
                        <option value="0">Выберите тип</option>
                        <?php foreach ($allTypes as $t): ?>
                            <option value="<?php echo e((string)$t['id']); ?>" <?php echo (int)($editingComp['partition_type_id'] ?? 0) === (int)$t['id'] ? 'selected' : ''; ?>><?php echo e($t['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="comp_condition_field">Условие: поле</label>
                    <input id="comp_condition_field" name="comp_condition_field" placeholder="Например: doors_count" value="<?php echo e((string)($editingComp['condition_field'] ?? '')); ?>">
                </div>
                <div>
                    <label for="comp_condition_operator">Оператор</label>
                    <select id="comp_condition_operator" name="comp_condition_operator">
                        <?php foreach ($operators as $v => $l): ?>
                            <option value="<?php echo e($v); ?>" <?php echo ($editingComp['condition_operator'] ?? 'any') === $v ? 'selected' : ''; ?>><?php echo e($l); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="comp_condition_value">Значение условия</label>
                    <input id="comp_condition_value" name="comp_condition_value" placeholder="Например: 1" value="<?php echo e((string)($editingComp['condition_value'] ?? '')); ?>">
                </div>
                <div>
                    <label for="comp_sort">Порядок</label>
                    <input id="comp_sort" type="number" name="comp_sort" value="<?php echo e((string)($editingComp['sort_order'] ?? '0')); ?>">
                </div>
            </div>
            <div style="margin-top:14px">
                <label for="comp_parts">Детали перегородки (каждая с новой строки, в порядке слева направо)</label>
                <textarea id="comp_parts" name="comp_parts" style="min-height:140px" placeholder="Угловая перемычка Левая&#10;Дверь&#10;Угловая перемычка Правая"><?php
                    if ($editingComp) {
                        $parts = json_decode($editingComp['parts'] ?? '[]', true);
                        echo e(implode("\n", $parts));
                    }
                ?></textarea>
            </div>
            <p><label for="comp_note">Примечание</label><textarea id="comp_note" name="comp_note"><?php echo e((string)($editingComp['note'] ?? '')); ?></textarea></p>
            <div class="form-actions">
                <button type="submit">Сохранить</button>
                <?php if ($editingComp): ?><a class="button secondary" href="admin_partitions.php?tab=params">Отмена</a><?php endif; ?>
            </div>
        </form>
    </section>

    <section class="panel">
        <h2>Состав деталей по типам перегородок</h2>
        <?php
        $groupedComp = [];
        foreach ($compositions as $c) $groupedComp[$c['type_name'] ?? 'Без типа'][] = $c;
        if ($groupedComp):
            foreach ($groupedComp as $typeName => $comps):
        ?>
            <div class="type-group">🧩 <?php echo e($typeName); ?></div>
            <?php foreach ($comps as $c):
                $parts = json_decode($c['parts'] ?? '[]', true) ?: [];
                $condStr = '';
                if ($c['condition_field']) {
                    $condStr = $c['condition_field'] . ' ' . ($operators[$c['condition_operator']] ?? $c['condition_operator']) . ($c['condition_value'] ? ' ' . $c['condition_value'] : '');
                }
            ?>
            <div style="border:1px solid #e5e7eb;border-radius:10px;padding:14px;margin-bottom:10px">
                <div style="display:flex;justify-content:space-between;align-items:center;gap:12px">
                    <div>
                        <?php if ($condStr): ?><span style="font-size:13px;color:#64748b">Если <strong><?php echo e($condStr); ?></strong>:</span><?php else: ?><span style="font-size:13px;color:#64748b">Для любого значения:</span><?php endif; ?>
                        <div class="parts-chain" style="margin-top:6px">
                            <?php foreach ($parts as $part): ?>
                                <span class="part-tag"><?php echo e($part); ?></span>
                            <?php endforeach; ?>
                        </div>
                        <?php if ($c['note']): ?><div class="hint" style="margin-top:6px"><?php echo e($c['note']); ?></div><?php endif; ?>
                    </div>
                    <div class="actions" style="flex-shrink:0">
                        <a class="btn-icon btn-edit" href="admin_partitions.php?tab=params&edit_comp=<?php echo e((string)$c['id']); ?>" title="Изменить"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg></a>
                        <button class="btn-icon btn-delete" type="button" onclick="delComp(<?php echo (int)$c['id']; ?>)" title="Удалить"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg></button>
                    </div>
                </div>
            </div>
            <?php endforeach; endforeach; else: ?>
            <p class="hint">Составы деталей пока не добавлены.</p>
        <?php endif; ?>
    </section>
</div>

<!-- ════════════ ВКЛАДКА: КОНСТРУКТОР ════════════ -->
<div class="tab-pane <?php echo $activeTab==='constructor'?'active':''; ?>" id="tab-constructor">

    <section class="panel">
        <h2><?php echo $editingField ? 'Редактировать поле' : 'Добавить поле для типа перегородки'; ?></h2>
        <p class="hint">Здесь настраиваются поля, которые появятся в калькуляторе при выборе конкретного типа перегородки.</p>
        <form method="post">
            <input type="hidden" name="action" value="<?php echo $editingField ? 'update_field' : 'create_field'; ?>">
            <input type="hidden" name="field_id" value="<?php echo e((string)($editingField['id'] ?? '')); ?>">
            <div class="grid">
                <div>
                    <label for="field_partition_type_id">Тип перегородки</label>
                    <select id="field_partition_type_id" name="field_partition_type_id">
                        <option value="0">Выберите тип</option>
                        <?php foreach ($allTypes as $t): ?>
                            <option value="<?php echo e((string)$t['id']); ?>" <?php echo (int)($editingField['partition_type_id'] ?? 0) === (int)$t['id'] ? 'selected' : ''; ?>><?php echo e($t['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="field_label">Название поля</label>
                    <input id="field_label" name="field_label" required placeholder="Например: Количество дверей" value="<?php echo e((string)($editingField['field_label'] ?? '')); ?>">
                </div>
                <div>
                    <label for="field_key">Ключ поля (латиницей)</label>
                    <input id="field_key" name="field_key" required placeholder="Например: doors_count" value="<?php echo e((string)($editingField['field_key'] ?? '')); ?>">
                </div>
                <div>
                    <label for="field_type">Тип поля</label>
                    <select id="field_type" name="field_type">
                        <option value="number" <?php echo ($editingField['field_type'] ?? 'number') === 'number' ? 'selected' : ''; ?>>Число</option>
                        <option value="text" <?php echo ($editingField['field_type'] ?? '') === 'text' ? 'selected' : ''; ?>>Текст</option>
                    </select>
                </div>
                <div>
                    <label for="field_unit">Единица измерения</label>
                    <select id="field_unit" name="field_unit">
                        <option value="">Без единицы</option>
                        <?php foreach ($units as $u): ?>
                            <option value="<?php echo e($u['short_name']); ?>" <?php echo ($editingField['field_unit'] ?? '') === $u['short_name'] ? 'selected' : ''; ?>><?php echo e($u['short_name']); ?> — <?php echo e($u['full_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="field_default">Значение по умолчанию</label>
                    <input id="field_default" name="field_default" value="<?php echo e((string)($editingField['default_value'] ?? '')); ?>">
                </div>
                <div>
                    <label for="field_sort">Порядок (чем меньше — тем выше)</label>
                    <input id="field_sort" type="number" name="field_sort" value="<?php echo e((string)($editingField['sort_order'] ?? '0')); ?>">
                </div>
            </div>
            <p style="margin-top:14px"><label><input type="checkbox" name="field_required" <?php echo !isset($editingField['is_required']) || (int)$editingField['is_required'] === 1 ? 'checked' : ''; ?>> Обязательное поле</label></p>
            <div class="form-actions" style="margin-top:14px">
                <button type="submit">Сохранить</button>
                <?php if ($editingField): ?><a class="button secondary" href="admin_partitions.php?tab=constructor">Отмена</a><?php endif; ?>
            </div>
        </form>
    </section>

    <section class="panel">
        <h2>Поля по типам перегородок</h2>
        <?php
        $groupedFields = [];
        foreach ($typeFields as $f) $groupedFields[$f['type_name'] ?? 'Без типа'][] = $f;
        if ($groupedFields):
            foreach ($groupedFields as $typeName => $fields):
        ?>
            <div class="type-group">📐 <?php echo e($typeName); ?></div>
            <table>
                <thead><tr><th>Порядок</th><th>Ключ</th><th>Название</th><th>Тип</th><th>Ед. изм.</th><th>По умолч.</th><th>Обязат.</th><th>Действия</th></tr></thead>
                <tbody>
                <?php foreach ($fields as $f): ?>
                    <tr>
                        <td><?php echo e((string)$f['sort_order']); ?></td>
                        <td><code><?php echo e($f['field_key']); ?></code></td>
                        <td><?php echo e($f['field_label']); ?></td>
                        <td><?php echo e($f['field_type']); ?></td>
                        <td><?php echo e((string)($f['field_unit'] ?? '—')); ?></td>
                        <td><?php echo e((string)($f['default_value'] ?? '—')); ?></td>
                        <td><span class="badge <?php echo (int)$f['is_required'] ? 'badge-yes' : 'badge-no'; ?>"><?php echo (int)$f['is_required'] ? 'Да' : 'Нет'; ?></span></td>
                        <td class="actions">
                            <a class="btn-icon btn-edit" href="admin_partitions.php?tab=constructor&edit_field=<?php echo e((string)$f['id']); ?>" title="Изменить"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg></a>
                            <button class="btn-icon btn-delete" type="button" onclick="delField(<?php echo (int)$f['id']; ?>)" title="Удалить"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg></button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endforeach; else: ?>
            <p class="hint">Поля пока не добавлены.</p>
        <?php endif; ?>
    </section>

    <div class="section-title" style="margin-top:32px;">Правила расчёта</div>

    <section class="panel">
        <h2><?php echo $editingRule ? 'Редактировать правило' : 'Добавить правило'; ?></h2>
        <p class="hint">Правила описывают условия для автоматизации расчёта.</p>
        <form method="post">
            <input type="hidden" name="action" value="<?php echo $editingRule ? 'update_rule' : 'create_rule'; ?>">
            <input type="hidden" name="id" value="<?php echo e((string)($editingRule['id'] ?? '')); ?>">
            <div class="grid">
                <div><label for="rule_name">Название правила</label><input id="rule_name" name="rule_name" required value="<?php echo e((string)($editingRule['name'] ?? '')); ?>"></div>
                <div><label for="rule_partition_type_id">Тип перегородки</label><select id="rule_partition_type_id" name="rule_partition_type_id"><option value="0">Любой тип</option><?php foreach ($allTypes as $t): ?><option value="<?php echo e((string)$t['id']); ?>" <?php echo (int)($editingRule['partition_type_id'] ?? 0) === (int)$t['id'] ? 'selected' : ''; ?>><?php echo e($t['name']); ?></option><?php endforeach; ?></select></div>
                <div><label for="rule_manufacturer_id">Производитель</label><select id="rule_manufacturer_id" name="rule_manufacturer_id"><option value="0">Любой производитель</option><?php foreach ($manufacturers as $m): ?><option value="<?php echo e((string)$m['id']); ?>" <?php echo (int)($editingRule['manufacturer_id'] ?? 0) === (int)$m['id'] ? 'selected' : ''; ?>><?php echo e($m['full_name']); ?></option><?php endforeach; ?></select></div>
                <div><label for="rule_condition_parameter">Если параметр</label>
                    <select id="rule_condition_parameter" name="rule_condition_parameter">
                        <option value="">— Выберите поле —</option>
                        <?php foreach ($typeFields as $f): ?>
                            <option value="<?php echo e($f['field_key']); ?>" data-type="<?php echo e((string)$f['partition_type_id']); ?>" <?php echo ($editingRule['condition_parameter'] ?? '') === $f['field_key'] ? 'selected' : ''; ?>><?php echo e($f['field_label']); ?> (<?php echo e($f['field_key']); ?>) — <?php echo e($f['type_name'] ?? ''); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div><label for="rule_condition_operator">Оператор</label><select id="rule_condition_operator" name="rule_condition_operator"><?php foreach ($operators as $v => $l): ?><option value="<?php echo e($v); ?>" <?php echo ($editingRule['condition_operator'] ?? '=') === $v ? 'selected' : ''; ?>><?php echo e($l); ?></option><?php endforeach; ?></select></div>
                <div><label for="rule_condition_value">Значение условия</label><input id="rule_condition_value" name="rule_condition_value" value="<?php echo e((string)($editingRule['condition_value'] ?? '')); ?>"></div>
                <div><label for="rule_action_target">То результат (деталь)</label>
                    <select id="rule_action_target" name="rule_action_target">
                        <option value="">— Выберите деталь —</option>
                        <?php
                        $usedParts = [];
                        foreach ($compParts as $cp) {
                            $parts = json_decode($cp['parts'] ?? '[]', true) ?: [];
                            foreach ($parts as $part) {
                                $key = (int)$cp['partition_type_id'] . '|' . $part;
                                if ($part && !isset($usedParts[$key])) {
                                    $usedParts[$key] = true;
                                    echo '<option value="' . e($part) . '" data-type="' . e((string)$cp['partition_type_id']) . '"' . (($editingRule['action_target'] ?? '') === $part ? ' selected' : '') . '>' . e($part) . '</option>';
                                }
                            }
                        }
                        ?>
                    </select>
                </div>
                <div><label for="rule_action_formula">Формула / описание</label><textarea id="rule_action_formula" name="rule_action_formula" placeholder="Например: 1 шт., или: doors_count + 1"><?php echo e((string)($editingRule['action_formula'] ?? '')); ?></textarea></div>
            </div>
            <p><label for="rule_note">Примечание</label><textarea id="rule_note" name="rule_note"><?php echo e((string)($editingRule['note'] ?? '')); ?></textarea></p>
            <p><label><input type="checkbox" name="rule_is_active" <?php echo !isset($editingRule['is_active']) || (int)$editingRule['is_active'] === 1 ? 'checked' : ''; ?>> Активно</label></p>
            <div class="form-actions"><button type="submit">Сохранить</button><?php if ($editingRule): ?><a class="button secondary" href="admin_partitions.php?tab=constructor">Отмена</a><?php endif; ?></div>
        </form>
    </section>

    <section class="panel">
        <h2>Правила конструктора</h2>
        <table>
            <thead><tr><th>Название</th><th>Область</th><th>Условие</th><th>Результат</th><th>Активно</th><th>Действия</th></tr></thead>
            <tbody>
            <?php foreach ($rules as $rule): ?>
                <tr>
                    <td><?php echo e($rule['name']); ?></td>
                    <td>Тип: <?php echo e((string)($rule['partition_type_name'] ?? 'любой')); ?><br>Пр-ль: <?php echo e((string)($rule['manufacturer_name'] ?? 'любой')); ?></td>
                    <td><?php echo e((string)($rule['condition_parameter'] ?? '')); ?> <?php echo e($operators[$rule['condition_operator']] ?? $rule['condition_operator']); ?> <?php echo e((string)($rule['condition_value'] ?? '')); ?></td>
                    <td><strong><?php echo e($rule['action_target']); ?></strong><br><?php echo nl2br(e((string)($rule['action_formula'] ?? ''))); ?></td>
                    <td><span class="badge <?php echo (int)$rule['is_active'] ? 'badge-yes' : 'badge-no'; ?>"><?php echo (int)$rule['is_active'] ? 'Да' : 'Нет'; ?></span></td>
                    <td class="actions">
                        <a class="btn-icon btn-edit" href="admin_partitions.php?tab=constructor&edit_rule=<?php echo e((string)$rule['id']); ?>" title="Изменить"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg></a>
                        <button class="btn-icon btn-delete" type="button" onclick="delRule(<?php echo (int)$rule['id']; ?>)" title="Удалить"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg></button>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rules): ?><tr><td colspan="6">Правила пока не добавлены.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </section>
</div>

<!-- ════════════ ВКЛАДКА: ФОРМУЛЫ ════════════ -->
<div class="tab-pane <?php echo $activeTab==='formulas'?'active':''; ?>" id="tab-formulas">

    <section class="panel">
        <h2><?php echo $editingFormula ? 'Редактировать формулу' : 'Добавить формулу'; ?></h2>
        <p class="hint">Формулы используются для автоматического расчёта количества материалов, фурнитуры и площадей.</p>
        <form method="post">
            <input type="hidden" name="action" value="<?php echo $editingFormula ? 'update_formula' : 'create_formula'; ?>">
            <input type="hidden" name="id" value="<?php echo e((string)($editingFormula['id'] ?? '')); ?>">
            <div class="grid">
                <div>
                    <label for="formula_partition_type_id">Тип перегородки</label>
                    <select id="formula_partition_type_id" name="formula_partition_type_id">
                        <option value="0">Любой тип</option>
                        <?php foreach ($allTypes as $t): ?>
                            <option value="<?php echo e((string)$t['id']); ?>" data-type-id="<?php echo e((string)$t['id']); ?>" <?php echo (int)($editingFormula['partition_type_id'] ?? 0) === (int)$t['id'] ? 'selected' : ''; ?>><?php echo e($t['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="formula_name">Название формулы</label>
                    <input id="formula_name" name="formula_name" required placeholder="Например: Количество петель" value="<?php echo e((string)($editingFormula['name'] ?? '')); ?>">
                </div>
                <div>
                    <label for="formula_target_key">Ключ результата</label>
                    <input id="formula_target_key" name="formula_target_key" required placeholder="Например: hardware_hinges_count" value="<?php echo e((string)($editingFormula['target_key'] ?? '')); ?>">
                </div>
                <div>
                    <label for="formula_unit">Единица измерения</label>
                    <input id="formula_unit" name="formula_unit" placeholder="Например: шт, м, м²" value="<?php echo e((string)($editingFormula['unit'] ?? '')); ?>">
                </div>
                <div>
                    <label for="formula_sort_order">Порядок</label>
                    <input id="formula_sort_order" type="number" name="formula_sort_order" value="<?php echo e((string)($editingFormula['sort_order'] ?? '0')); ?>">
                </div>
            </div>
            <div style="margin-top:14px">
                <label for="formula_formula">Формула</label>
                <textarea id="formula_formula" class="formula-code" name="formula_formula" required placeholder="Например: doors_count * 2 + 2" style="font-family:'Courier New',monospace;font-size:14px;min-height:76px"><?php echo e((string)($editingFormula['formula'] ?? '')); ?></textarea>
                <div class="hint">Доступные ключи полей (нажмите, чтобы вставить):</div>
                <div class="fields-ref" id="fields-ref">
                    <?php foreach ($typeFields as $f): ?>
                        <span data-key="<?php echo e($f['field_key']); ?>" data-type="<?php echo e((string)($f['partition_type_id'] ?? 0)); ?>" title="<?php echo e($f['field_label'] . ' — ' . ($f['type_name'] ?? 'любой тип')); ?>"><?php echo e($f['field_key']); ?></span>
                    <?php endforeach; ?>
                    <?php if (!$typeFields): ?><span class="hint" style="background:none">Поля пока не настроены.</span><?php endif; ?>
                </div>
            </div>
            <p style="margin-top:14px"><label for="formula_note">Примечание</label><textarea id="formula_note" name="formula_note"><?php echo e((string)($editingFormula['note'] ?? '')); ?></textarea></p>
            <p><label><input type="checkbox" name="formula_is_active" <?php echo !isset($editingFormula['is_active']) || (int)$editingFormula['is_active'] === 1 ? 'checked' : ''; ?>> Активна</label></p>
            <div class="form-actions" style="margin-top:14px">
                <button type="submit">Сохранить</button>
                <?php if ($editingFormula): ?><a class="button secondary" href="admin_partitions.php?tab=formulas">Отмена</a><?php endif; ?>
            </div>
        </form>
    </section>

    <section class="panel">
        <h2>Список формул</h2>
        <?php
        $groupedFormulas = [];
        foreach ($formulas as $f) $groupedFormulas[$f['type_name'] ?? 'Любой тип'][] = $f;
        if ($groupedFormulas):
            foreach ($groupedFormulas as $typeName => $items):
        ?>
            <div class="type-group">🧮 <?php echo e($typeName); ?></div>
            <table>
                <thead><tr><th>Название</th><th>Результат</th><th>Формула</th><th>Ед.</th><th>Активна</th><th>Действия</th></tr></thead>
                <tbody>
                <?php foreach ($items as $f): ?>
                    <tr>
                        <td><?php echo e($f['name']); ?><?php if ($f['note']): ?><div class="hint"><?php echo e($f['note']); ?></div><?php endif; ?></td>
                        <td><code><?php echo e($f['target_key']); ?></code></td>
                        <td><span class="formula-code"><?php echo e($f['formula']); ?></span></td>
                        <td><?php echo e((string)($f['unit'] ?? '—')); ?></td>
                        <td><span class="badge <?php echo (int)$f['is_active'] ? 'badge-yes' : 'badge-no'; ?>"><?php echo (int)$f['is_active'] ? 'Да' : 'Нет'; ?></span></td>
                        <td class="actions">
                            <a class="btn-icon btn-edit" href="admin_partitions.php?tab=formulas&edit_formula=<?php echo e((string)$f['id']); ?>" title="Изменить"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg></a>
                            <button class="btn-icon btn-delete" type="button" onclick="delFormula(<?php echo (int)$f['id']; ?>)" title="Удалить"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg></button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endforeach; else: ?>
            <p class="hint">Формулы пока не добавлены.</p>
        <?php endif; ?>
    </section>
</div>

<!-- ═══ ОБЩИЕ ФОРМЫ УДАЛЕНИЯ ═══ -->
<form id="del-type-form" method="post" style="display:none"><input type="hidden" name="action" value="delete_type"><input type="hidden" name="id" id="del-type-id"></form>
<form id="del-param-form" method="post" style="display:none"><input type="hidden" name="action" value="delete_param"><input type="hidden" name="id" id="del-param-id"></form>
<form id="del-comp-form" method="post" style="display:none"><input type="hidden" name="action" value="delete_comp"><input type="hidden" name="id" id="del-comp-id"></form>
<form id="del-rule-form" method="post" style="display:none"><input type="hidden" name="action" value="delete_rule"><input type="hidden" name="id" id="del-rule-id"></form>
<form id="del-field-form" method="post" style="display:none"><input type="hidden" name="action" value="delete_field"><input type="hidden" name="id" id="del-field-id"></form>
<form id="del-formula-form" method="post" style="display:none"><input type="hidden" name="action" value="delete_formula"><input type="hidden" name="id" id="del-formula-id"></form>

</main>
<script>
const TYPE_FIELDS = <?php echo $typeFieldsJson; ?>;
const PARTS_BY_TYPE = <?php echo $partsJson; ?>;
const PARAMETERS = <?php echo json_encode($parameters, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

// ═══ Delete handlers ═══
function delType(id) { if (!confirm('Удалить тип перегородки?')) return; document.getElementById('del-type-id').value = id; document.getElementById('del-type-form').submit(); }
function delParam(id) { if (!confirm('Удалить параметр?')) return; document.getElementById('del-param-id').value = id; document.getElementById('del-param-form').submit(); }
function delComp(id) { if (!confirm('Удалить состав?')) return; document.getElementById('del-comp-id').value = id; document.getElementById('del-comp-form').submit(); }
function delRule(id) { if (!confirm('Удалить правило?')) return; document.getElementById('del-rule-id').value = id; document.getElementById('del-rule-form').submit(); }
function delField(id) { if (!confirm('Удалить поле?')) return; document.getElementById('del-field-id').value = id; document.getElementById('del-field-form').submit(); }
function delFormula(id) { if (!confirm('Удалить формулу?')) return; document.getElementById('del-formula-id').value = id; document.getElementById('del-formula-form').submit(); }

// ═══ Types tab: drag-drop parameters ═══
const rows = document.getElementById('parameter-rows');
function parameterOptions() { return '<option value="0">Выберите параметр</option>' + PARAMETERS.map(p => '<option value="' + p.id + '">' + p.name + (p.unit_short_name ? ' (' + p.unit_short_name + ')' : '') + '</option>').join(''); }
document.getElementById('add-parameter').addEventListener('click', () => {
    const index = rows.querySelectorAll('.param-row').length;
    const row = document.createElement('div');
    row.className = 'param-row';
    row.draggable = true;
    row.innerHTML = '<div class="drag-handle">⣿</div><div><label>Параметр</label><select name="parameter_id[]">' + parameterOptions() + '</select></div><div><label><input type="checkbox" name="is_required[' + index + ']"> Обяз.</label></div><div><label>Переопределить значение</label><input name="default_value_override[]"></div><button class="danger" type="button">Удалить</button><input type="hidden" name="sort_order[]" value="' + ((index + 1) * 10) + '">';
    row.querySelector('button').addEventListener('click', () => row.remove());
    rows.append(row);
    initDragRow(row);
});
const photoInput = document.getElementById('photo');
const photoPreview = document.getElementById('photo_preview');
photoInput?.addEventListener('change', () => {
    const file = photoInput.files && photoInput.files[0];
    if (!file || !file.type.startsWith('image/')) { photoPreview.style.display = 'none'; return; }
    photoPreview.src = URL.createObjectURL(file);
    photoPreview.style.display = 'inline-block';
});

let draggedRow = null;
function initDragRow(row) {
    row.addEventListener('dragstart', e => { draggedRow = row; row.classList.add('dragged'); e.dataTransfer.effectAllowed = 'move'; });
    row.addEventListener('dragend', () => { draggedRow && draggedRow.classList.remove('dragged'); draggedRow = null; rows.querySelectorAll('.param-row').forEach(r => r.classList.remove('drag-over')); updateSortOrders(); });
    row.addEventListener('dragover', e => { e.preventDefault(); e.dataTransfer.dropEffect = 'move'; if (row !== draggedRow) row.classList.add('drag-over'); });
    row.addEventListener('dragleave', () => { row.classList.remove('drag-over'); });
    row.addEventListener('drop', e => { e.preventDefault(); row.classList.remove('drag-over'); if (draggedRow && draggedRow !== row) { const all = [...rows.querySelectorAll('.param-row')]; const fromIdx = all.indexOf(draggedRow); const toIdx = all.indexOf(row); if (fromIdx < toIdx) row.after(draggedRow); else row.before(draggedRow); } });
}
function updateSortOrders() { rows.querySelectorAll('.param-row').forEach((row, i) => { const input = row.querySelector('input[name="sort_order[]"]'); if (input) input.value = (i + 1) * 10; }); }
rows.querySelectorAll('.param-row').forEach(initDragRow);

// ═══ Constructor tab: filter by type ═══
const typeSelect = document.getElementById('rule_partition_type_id');
const paramSelect = document.getElementById('rule_condition_parameter');
const actionSelect = document.getElementById('rule_action_target');

function filterByType() {
    if (!typeSelect) return;
    const tid = typeSelect.value;
    if (paramSelect) {
        Array.from(paramSelect.options).forEach(opt => {
            if (!opt.value) return;
            opt.hidden = tid && tid !== '0' && opt.dataset.type && opt.dataset.type !== tid;
        });
        if (paramSelect.selectedOptions[0]?.hidden) paramSelect.value = '';
    }
    if (actionSelect) {
        Array.from(actionSelect.options).forEach(opt => {
            if (!opt.value) return;
            opt.hidden = tid && tid !== '0' && opt.dataset.type && opt.dataset.type !== tid;
        });
        if (actionSelect.selectedOptions[0]?.hidden) actionSelect.value = '';
    }
}
typeSelect?.addEventListener('change', filterByType);
filterByType();

// ═══ Formulas tab: field ref insert + filter ═══
const formulaTypeSel = document.getElementById('formula_partition_type_id');
const formulaInput = document.getElementById('formula_formula');
const fieldsRef = document.getElementById('fields-ref');

function filterFieldRefs() {
    const tid = formulaTypeSel?.value || '0';
    fieldsRef?.querySelectorAll('span[data-key]').forEach(span => {
        const spanType = span.dataset.type || '0';
        span.style.display = (tid === '0' || spanType === '0' || spanType === tid) ? 'inline-block' : 'none';
    });
}
formulaTypeSel?.addEventListener('change', filterFieldRefs);
filterFieldRefs();

fieldsRef?.querySelectorAll('span[data-key]').forEach(span => {
    span.addEventListener('click', () => {
        const key = span.dataset.key;
        const start = formulaInput.selectionStart || formulaInput.value.length;
        const end = formulaInput.selectionEnd || formulaInput.value.length;
        formulaInput.value = formulaInput.value.slice(0, start) + key + formulaInput.value.slice(end);
        formulaInput.focus();
        const pos = start + key.length;
        formulaInput.setSelectionRange(pos, pos);
    });
});
</script>
</body>
</html>
