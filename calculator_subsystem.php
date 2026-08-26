<?php
error_reporting(0);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
require_once __DIR__ . '/includes/admin_auth.php';
require_once __DIR__ . '/includes/app_header.php';
require_login();
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/admin_schema.php';

try { ensure_panel_sizes_table($pdo); } catch (\Exception $e) { error_log('ensure_panel_sizes_table: ' . $e->getMessage()); }
try { ensure_subsystem_tables($pdo); } catch (\Exception $e) { error_log('ensure_subsystem_tables: ' . $e->getMessage()); }

$userId = $_SESSION['user_id'] ?? 0;

if (isset($_POST['save_calc']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $objectName = trim($_POST['object_name'] ?? '');
    $calcName = trim($_POST['calc_name'] ?? '');
    if ($calcName === '') {
        header('Location: calculator_subsystem.php?error=1');
        exit;
    }
    $totalPrice = (float)($_POST['total_price'] ?? 0);
    $params = $_POST['calc_params'] ?? '{}';
    $loadedId = (int)($_POST['calc_id'] ?? 0);

    $versionGroup = null;
    $versionNumber = 1;

    if ($loadedId > 0) {
        $parent = $pdo->prepare('SELECT version_group, version_number FROM subsystem_calcs WHERE id=? AND user_id=?');
        $parent->execute([$loadedId, $userId]);
        $parentRow = $parent->fetch();
        if ($parentRow) {
            $versionGroup = $parentRow['version_group'] ?: $parentRow['id'] ?? $loadedId;
            $versionNumber = ($parentRow['version_number'] ?? 1) + 1;
        }
    }

    if ($versionGroup !== null) {
        $stmt = $pdo->prepare('INSERT INTO subsystem_calcs (user_id, object_name, calc_name, total_price, params, version_group, version_number) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$userId, $objectName, $calcName, $totalPrice, $params, $versionGroup, $versionNumber]);
    } else {
        $stmt = $pdo->prepare('INSERT INTO subsystem_calcs (user_id, object_name, calc_name, total_price, params) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$userId, $objectName, $calcName, $totalPrice, $params]);
        $newId = $pdo->lastInsertId();
        $pdo->prepare('UPDATE subsystem_calcs SET version_group=? WHERE id=?')->execute([$newId, $newId]);
    }

    header('Location: calculator_subsystem.php?saved=1');
    exit;
}

if (isset($_POST['delete_calc']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $calcId = (int)($_POST['calc_id'] ?? 0);
    if ($calcId > 0) {
        $pdo->prepare('DELETE FROM subsystem_calcs WHERE id=? AND user_id=?')->execute([$calcId, $userId]);
    }
    header('Location: calculator_subsystem.php');
    exit;
}

$savedCalcs = [];
if ($userId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM subsystem_calcs WHERE user_id=? ORDER BY version_group DESC, version_number DESC');
    $stmt->execute([$userId]);
    $savedCalcs = $stmt->fetchAll();
}

$editCalc = null;
$editId = (int)($_GET['edit'] ?? 0);
if ($editId > 0 && $userId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM subsystem_calcs WHERE id=? AND user_id=?');
    $stmt->execute([$editId, $userId]);
    $editCalc = $stmt->fetch() ?: null;
}

function safeQuery($pdo, $sql, $default = []) {
    try { return @$pdo->query($sql)->fetchAll(); } catch (\Exception $e) { return $default; }
}

$panelFormats = safeQuery($pdo, "SELECT ps.id, ps.height_mm, ps.width_mm,
    m.full_name AS manufacturer_name
    FROM panel_sizes ps
    LEFT JOIN manufacturers m ON m.id = ps.manufacturer_id
    WHERE ps.is_active = 1
    ORDER BY m.full_name, ps.height_mm, ps.width_mm");
$panelFormatsJson = json_encode(array_values($panelFormats), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$enclosures = safeQuery($pdo, "SELECT id, name FROM subsystem_enclosures ORDER BY sort_order, id");
$enclosuresJson = json_encode(array_values($enclosures), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$subItems = safeQuery($pdo, "SELECT si.id, si.name, si.unit, si.supplier_id, si.consumption_per_m, si.consumption_unit, si.price_per_unit, si.price_per_piece, si.quantity_per_piece, si.width_mm, si.thickness_mm FROM subsystem_sub_items si WHERE si.is_active = 1 ORDER BY si.supplier_id, si.sort_order, si.id");
$subItemsJson = json_encode(array_values($subItems), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$materials = safeQuery($pdo, "SELECT m.id, m.name, m.unit, m.supplier_id, m.consumption_per_m, m.consumption_unit, m.price_per_unit, m.price_per_piece, m.quantity_per_piece FROM subsystem_materials m WHERE m.is_active = 1 ORDER BY m.supplier_id, m.sort_order, m.id");
$materialsJson = json_encode(array_values($materials), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$fasteners = safeQuery($pdo, "SELECT f.id, f.name, f.unit, f.supplier_id, f.consumption_per_m, f.consumption_unit, f.price_per_unit, f.price_per_piece, f.quantity_per_piece FROM subsystem_fasteners f WHERE f.is_active = 1 ORDER BY f.supplier_id, f.sort_order, f.id");
$fastenersJson = json_encode(array_values($fasteners), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$profileSupplierIds = array_unique(array_map(function($si) { return (int)$si['supplier_id']; }, array_filter($subItems, function($si) { return $si['supplier_id'] > 0; })));
$kleevayaSupplierIds = array_unique(array_map(function($m) { return (int)$m['supplier_id']; }, array_filter($materials, function($m) { return $m['supplier_id'] > 0; })));
$metizySupplierIds = array_unique(array_map(function($f) { return (int)$f['supplier_id']; }, array_filter($fasteners, function($f) { return $f['supplier_id'] > 0; })));

$allUsedIds = array_unique(array_merge($profileSupplierIds, $kleevayaSupplierIds, $metizySupplierIds));
$suppliersFiltered = [];
if (!empty($allUsedIds)) {
    $suppliersFiltered = safeQuery($pdo, "SELECT id, company_name FROM suppliers WHERE id IN (" . implode(',', array_map('intval', $allUsedIds)) . ") ORDER BY company_name ASC");
}
$suppliersJson = json_encode(array_values($suppliersFiltered), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<title>Расчёт подсистемы</title>
<style>
body { font-family: 'Inter', Arial, sans-serif; background: #f5f7fb; margin: 0; color: #1f2937; }
.header { background: radial-gradient(circle at 10% 10%, rgba(59,130,246,.35), transparent 32%), linear-gradient(120deg, #0f172a, #1e3a8a 58%, #0f766e); color: #fff; padding: 22px 36px 30px; box-shadow: 0 18px 45px rgba(15,23,42,.18); display:flex; align-items:center; gap:16px; flex-wrap:wrap; }
.header a { color: #dbeafe; font-weight: 700; text-decoration: none; }
.header h1 { margin: 0; font-size: clamp(24px, 3.5vw, 36px); letter-spacing: -.02em; font-weight: 900; }
.container { max-width: 1100px; margin: 28px auto; padding: 0 20px; }
.panel { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 22px; margin-bottom: 24px; box-shadow: 0 8px 24px rgba(15,23,42,0.06); }
.section-title { font-size: 15px; font-weight: 700; color: #374151; background: #f1f5f9; border-left: 4px solid #2563eb; padding: 8px 12px; border-radius: 0 6px 6px 0; margin: 22px 0 14px 0; }
.section-title.main { font-size: 17px; background: #eff6ff; border-left-color: #1d4ed8; margin-top: 0; }
.grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 14px; }
label { display: block; font-weight: 600; margin-bottom: 6px; font-size: 13px; }
input, select { width: 100%; box-sizing: border-box; padding: 9px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 14px; }
input[type="number"] { appearance: textfield; -moz-appearance: textfield; }
input[type="number"]::-webkit-inner-spin-button,
input[type="number"]::-webkit-outer-spin-button { margin: 0; -webkit-appearance: none; }
.input-with-unit { position: relative; }
.input-with-unit input { padding-right: 44px; }
.input-with-unit .field-unit { position: absolute; right: 10px; top: 50%; transform: translateY(-50%); color: #64748b; font-size: 12px; font-weight: 700; pointer-events: none; }
button, .button { border: 0; border-radius: 8px; padding: 10px 16px; background: #2563eb; color: #fff; text-decoration: none; cursor: pointer; display: inline-block; font-weight: 600; font-size: 14px; }
button.secondary { background: #64748b; }
button.success { background: #16a34a; }
.hint { color: #64748b; font-size: 12px; margin-top: 4px; }
table.data-table { width: 100%; border-collapse: collapse; background: #fff; }
table.data-table th, table.data-table td { padding: 10px 12px; text-align: left; vertical-align: middle; border-bottom: 1px solid #e5e7eb; font-size: 13px; }
table.data-table th { background: #f8fafc; font-weight: 700; white-space: nowrap; }
table.data-table td.num { text-align: right; font-weight: 600; font-family: monospace; }
table.data-table tr.total-row { background: #eff6ff; font-weight: 700; }
table.data-table tr.total-row td { border-top: 2px solid #2563eb; }
.actions-row { display: flex; gap: 10px; margin-top: 18px; flex-wrap: wrap; }
.info-box { background: #f0f9ff; border: 1px solid #bae6fd; border-radius: 8px; padding: 14px; margin-bottom: 16px; font-size: 13px; line-height: 1.7; }
.info-box b { color: #0369a1; }
.summary-box { background: #f8fafc; border: 2px solid #2563eb; border-radius: 12px; padding: 20px; margin-top: 20px; }
.summary-box h3 { margin: 0 0 14px 0; color: #1d4ed8; }
.summary-row { display: flex; justify-content: space-between; align-items: center; padding: 6px 0; font-size: 14px; border-bottom: 1px solid #e5e7eb; }
.summary-row:last-child { border-bottom: none; }
.summary-row.grand { font-size: 18px; font-weight: 700; color: #1d4ed8; border-top: 2px solid #2563eb; padding-top: 10px; }
.hidden { display: none !important; }
.editable-price { background: #fffbe6; border: 1px dashed #d4a017; border-radius: 4px; padding: 2px 6px; cursor: pointer; font-family: monospace; font-size: 13px; }
.editable-price:hover { background: #fef3c7; }
.material-name-with-help { display: flex; align-items: center; gap: 7px; }
.material-formula-help { color: #2563eb; border-color: #93c5fd; font-style: normal; }
.material-formula-help .app-field-help__tooltip { top: calc(100% + 8px); bottom: auto; width: min(390px, 80vw); }
.material-formula-help:hover .app-field-help__tooltip,
.material-formula-help:focus .app-field-help__tooltip { opacity: 1; visibility: visible; transform: translate(-50%, 0); }
@media print {
    body { background: #fff !important; margin: 0; }
    .header, .quick-links, .actions-row, form, button, .hidden, #toast,
    #section-saved-calcs, .info-box, #materials-table th:last-child, #materials-table td:last-child,
    input[type="number"] { display: none !important; }
    .container { max-width: 100%; padding: 0; margin: 0; }
    .panel { box-shadow: none; border: none; padding: 0; margin: 0 0 12px 0; page-break-inside: avoid; }
    .section-title { background: #e2e8f0 !important; border-left-color: #1d4ed8 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    table.data-table { border: 1px solid #999; width: 100%; }
    table.data-table th { background: #f1f5f9 !important; border-bottom: 2px solid #666; -webkit-print-color-adjust: exact; print-color-adjust: exact; text-align: left; }
    table.data-table td { border-bottom: 1px solid #ddd; }
    .group-total { background: #f8fafc !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .grand-total { background: #eff6ff !important; border-top: 2px solid #2563eb !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .export-print { display: block !important; }
    .export-print-header { text-align: center; margin-bottom: 16px; border-bottom: 2px solid #1d4ed8; padding-bottom: 12px; }
    .export-print-header h2 { margin: 0 0 4px 0; font-size: 20px; color: #111; }
    .export-print-header .meta { font-size: 12px; color: #555; }
    .export-params-table { width: 100%; border-collapse: collapse; margin-bottom: 16px; font-size: 12px; }
    .export-params-table td { padding: 4px 10px; border-bottom: 1px solid #eee; }
    .export-params-table td:first-child { font-weight: 700; color: #374151; width: 220px; }
    .no-print { display: none !important; }
}
.export-print { display: none; }
</style>
<?php echo app_header_styles(); ?>
</head>
<body>

<?php render_app_header(); ?>
<main class="container">

<div class="export-print">
    <div class="export-print-header">
        <h2>Расчёт подсистемы</h2>
        <div class="meta" id="export-subtitle"></div>
    </div>
    <table class="export-params-table" id="export-params-table"></table>
</div>

<div class="panel">
    <div class="info-box">
        <b>Расчёт подсистемы</b> — профиль, клеевая система и метизы.<br>
        Профиль считается по высоте подсистемы (или длине листа) с учётом шага, углов и перемычек.<br>
        Расход клеевой и метизов — по м.п. профиля или м² панелей (в зависимости от единицы расхода).
    </div>
</div>

<!-- ═══ ПАРАМЕТРЫ ═══ -->
<section class="panel">
    <div class="section-title main">Параметры расчёта</div>
    <div class="grid" style="margin-bottom:18px;">
        <div>
            <label for="calc_name">Название расчёта <span style="color:#dc2626">*</span></label>
            <input id="calc_name" name="calc_name" type="text" placeholder="Название расчёта" required value="<?php echo htmlspecialchars($editCalc['calc_name'] ?? ''); ?>">
        </div>
        <div>
            <label for="object_name">Название объекта</label>
            <input id="object_name" name="object_name" type="text" placeholder="Название объекта" value="<?php echo htmlspecialchars($editCalc['object_name'] ?? ''); ?>">
        </div>
    </div>
    <div class="grid">
        <div>
            <label for="panel_format">Формат панели</label>
            <select id="panel_format">
                <option value="">— Выберите формат —</option>
                <option value="custom">Свой размер…</option>
            </select>
        </div>
        <div id="custom-format-wrap" class="hidden">
            <label for="custom_length">Длина, мм</label>
            <div class="input-with-unit"><input id="custom_length" type="number" min="1" value="3660"><span class="field-unit">мм</span></div>
        </div>
        <div id="custom-format-wrap2" class="hidden">
            <label for="custom_width">Ширина, мм</label>
            <div class="input-with-unit"><input id="custom_width" type="number" min="1" value="1525"><span class="field-unit">мм</span></div>
        </div>
        <div>
            <label for="sheet_qty">Количество листов</label>
            <div class="input-with-unit"><input id="sheet_qty" type="number" min="1" value="1"><span class="field-unit">шт.</span></div>
        </div>
        <div>
            <label for="sheet_area">Количество м²</label>
            <div class="input-with-unit"><input id="sheet_area" type="number" min="0" step="0.01"><span class="field-unit">м²</span></div>
        </div>
        <div>
            <label for="profile_step">Шаг профиля, мм</label>
            <div class="input-with-unit"><input id="profile_step" type="number" min="1" value="400"><span class="field-unit">мм</span></div>
        </div>
        <div>
            <label for="corner_qty">Количество углов</label>
            <div class="input-with-unit"><input id="corner_qty" type="number" min="0" value="0"><span class="field-unit">шт.</span></div>
        </div>
        <div>
            <label for="subsystem_height">Высота подсистемы, мм</label>
            <div class="input-with-unit"><input id="subsystem_height" type="number" min="0" placeholder="По длине листа"><span class="field-unit">мм</span></div>
        </div>
        <div>
            <label for="enclosure_type">Тип стены</label>
            <select id="enclosure_type">
                <option value="0">Нет</option>
            </select>
        </div>
        <div>
            <label for="h_strengthen">Перемычка</label>
            <select id="h_strengthen">
                <option value="0">Нет</option>
                <option value="1">Да</option>
            </select>
        </div>
        <div>
            <label for="round_to_pieces">Округлять до шт.</label>
            <select id="round_to_pieces">
                <option value="0">Нет</option>
                <option value="1">Да</option>
            </select>
        </div>
        <div>
            <label for="reserve_pct">Запас, %</label>
            <div class="input-with-unit"><input id="reserve_pct" type="number" min="0" max="100" step="0.1" value="0"><span class="field-unit">%</span></div>
        </div>
        <div id="h-strengthen-step-wrap" class="hidden">
            <label for="h_strengthen_step">Шаг перемычки, мм</label>
            <div class="input-with-unit"><input id="h_strengthen_step" type="number" min="1" value="1000"><span class="field-unit">мм</span></div>
        </div>
    </div>

    <div class="section-title" style="margin-top:18px;">Материалы</div>
    <div class="grid">
        <div>
            <label for="supplier_profile">Профиль</label>
            <select id="supplier_profile">
                <option value="0">Нет</option>
            </select>
        </div>
        <div id="profile-item-wrap" class="hidden">
            <label for="profile_item">Элемент профиля</label>
            <select id="profile_item">
                <option value="0">Все элементы</option>
            </select>
        </div>
        <div>
            <label for="supplier_kleevaya">Клеевая система</label>
            <select id="supplier_kleevaya">
                <option value="0">Нет</option>
            </select>
        </div>
        <div id="metizy-wrap" class="hidden">
            <label for="supplier_metizy">Метизы</label>
            <select id="supplier_metizy">
                <option value="0">Нет</option>
            </select>
        </div>
    </div>

    <div id="panel-info-block" class="info-box hidden" style="margin-top:14px">
        <b>Формат:</b> <span id="info-format">—</span> |
        <b>Общая площадь:</b> <span id="info-sheet-area">—</span> м² |
        <b>Шаг профиля:</b> <span id="info-sheet-perimeter">—</span>
        <b>Итого профиль:</b> <span id="info-edges-total">—</span>
    </div>
</section>

<!-- ═══ РАСХОД МАТЕРИАЛОВ ═══ -->
<section class="panel">
    <div class="section-title main">Расход материалов</div>
    <table class="data-table" id="materials-table">
        <thead>
            <tr>
                <th style="width:50px">№</th>
                <th>Наименование</th>
                <th style="width:100px">Кол-во</th>
                <th style="width:150px">Ед. изм.</th>
                <th style="width:150px">Количество в ед. изм.</th>
                <th style="width:120px">Цена за ед.</th>
                <th style="width:120px">Сумма</th>
                <th style="width:40px"></th>
            </tr>
        </thead>
        <tbody id="materials-tbody"></tbody>
    </table>
    <div style="margin-top:10px;">
        <button type="button" class="secondary" onclick="addCustomRow()">+ Добавить строку</button>
    </div>
    <form id="calc-form" method="post" style="margin-top:16px;display:flex;gap:10px;align-items:center;">
        <input type="hidden" name="save_calc" value="1">
        <input type="hidden" name="calc_id" id="calc_id" value="<?php echo $editCalc ? (int)$editCalc['id'] : ''; ?>">
        <input type="hidden" name="calc_params" id="calc_params">
        <input type="hidden" name="total_price" id="total_price" value="<?php echo (float)($editCalc['total_price'] ?? 0); ?>">
        <button type="submit" class="success">Сохранить</button>
        <button type="button" class="secondary" onclick="exportExcel()">📊 Экспорт в Excel</button>
        <button type="button" class="secondary" onclick="exportPdf()">📄 Экспорт в PDF</button>
        <?php if ($editCalc): ?>
            <a href="calculator_subsystem.php" style="background:#64748b;padding:10px 16px;border-radius:8px;color:#fff;text-decoration:none;font-weight:600;">Новый расчёт</a>
        <?php endif; ?>
    </form>
</section>

<?php if (!empty($savedCalcs)): ?>
<section class="panel" id="section-saved-calcs">
    <div class="section-title main">Сохранённые расчёты</div>
    <table class="data-table">
        <thead>
            <tr>
                <th>Расчёт</th>
                <th>Объект</th>
                <th>Стоимость</th>
                <th>Версия</th>
                <th>Дата</th>
                <th style="width:120px;"></th>
            </tr>
        </thead>
        <tbody>
        <?php $prevGroup = null; foreach ($savedCalcs as $sc):
            $vg = $sc['version_group'] ?: $sc['id'];
            $isNewGroup = ($vg !== $prevGroup);
            $prevGroup = $vg;
        ?>
            <tr<?php if (!$isNewGroup): ?> style="opacity:.6"<?php endif; ?>>
                <td style="font-weight:600;"><?php echo htmlspecialchars($sc['calc_name']); ?></td>
                <td><?php echo htmlspecialchars($sc['object_name']); ?></td>
                <td style="font-weight:600;"><?php echo number_format((float)$sc['total_price'], 2, '.', ' '); ?></td>
                <td><?php if (!empty($sc['version_number'])): ?>v<?php echo (int)$sc['version_number']; ?><?php endif; ?></td>
                <td><?php echo date('d.m.Y H:i', strtotime($sc['updated_at'])); ?></td>
                <td style="display:flex;gap:6px;">
                    <a href="calculator_subsystem.php?edit=<?php echo (int)$sc['id']; ?>" style="background:#eff6ff;color:#2563eb;padding:6px 12px;border-radius:6px;text-decoration:none;font-size:13px;font-weight:600;">Открыть</a>
                    <form method="post" style="display:inline;" onsubmit="return confirm('Удалить расчёт?')">
                        <input type="hidden" name="delete_calc" value="1">
                        <input type="hidden" name="calc_id" value="<?php echo (int)$sc['id']; ?>">
                        <button type="submit" style="background:#fef2f2;color:#dc2626;padding:6px 12px;border-radius:6px;font-size:13px;font-weight:600;border:0;cursor:pointer;">Удалить</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>
<?php endif; ?>

</main>

<script>
const DB_PANEL_FORMATS = <?php echo $panelFormatsJson; ?>;
const DB_ENCLOSURES = <?php echo $enclosuresJson; ?>;
const DB_SUPPLIERS = <?php echo $suppliersJson; ?>;
const DB_PROFILE_SUPPLIER_IDS = <?php echo json_encode($profileSupplierIds ?? []); ?>;
const DB_KLEEVAJA_SUPPLIER_IDS = <?php echo json_encode($kleevayaSupplierIds ?? []); ?>;
const DB_METIZY_SUPPLIER_IDS = <?php echo json_encode($metizySupplierIds ?? []); ?>;
const DB_SUB_ITEMS = <?php echo $subItemsJson; ?>;
const DB_MATERIALS = <?php echo $materialsJson; ?>;
const DB_FASTENERS = <?php echo $fastenersJson; ?>;

const panelFormatSel = document.getElementById('panel_format');
const customLengthWrap = document.getElementById('custom-format-wrap');
const customWidthWrap = document.getElementById('custom-format-wrap2');
const customLengthInput = document.getElementById('custom_length');
const customWidthInput = document.getElementById('custom_width');
const sheetQtyInput = document.getElementById('sheet_qty');
const sheetAreaInput = document.getElementById('sheet_area');
const profileStepInput = document.getElementById('profile_step');
const cornerQtyInput = document.getElementById('corner_qty');
const subsystemHeightInput = document.getElementById('subsystem_height');
const hStrengthenSelect = document.getElementById('h_strengthen');
const hStrengthenStepWrap = document.getElementById('h-strengthen-step-wrap');
const hStrengthenStepInput = document.getElementById('h_strengthen_step');
const roundToPiecesSelect = document.getElementById('round_to_pieces');
const reservePctInput = document.getElementById('reserve_pct');
const materialsTbody = document.getElementById('materials-tbody');

function populateFormats() {
    panelFormatSel.innerHTML = '<option value="">— Выберите формат —</option><option value="custom">Свой размер…</option>';
    const seen = new Set();
    DB_PANEL_FORMATS.forEach(f => {
        if (!f.width_mm || !f.height_mm) return;
        const key = f.width_mm + 'x' + f.height_mm;
        if (seen.has(key)) return;
        seen.add(key);
        const label = f.height_mm + '×' + f.width_mm + ' мм' + (f.manufacturer_name ? ' (' + f.manufacturer_name + ')' : '');
        const opt = document.createElement('option');
        opt.value = key;
        opt.textContent = label;
        opt.dataset.w = f.width_mm;
        opt.dataset.h = f.height_mm;
        panelFormatSel.appendChild(opt);
    });
}

function populateEnclosures() {
    const sel = document.getElementById('enclosure_type');
    DB_ENCLOSURES.forEach(e => {
        const opt = document.createElement('option');
        opt.value = e.id;
        opt.textContent = e.name;
        sel.appendChild(opt);
    });
}

const profileItemSel = document.getElementById('profile_item');
const profileItemWrap = document.getElementById('profile-item-wrap');

function populateProfileItems() {
    const supplierId = parseInt(document.getElementById('supplier_profile').value) || 0;
    profileItemSel.innerHTML = '';
    if (!supplierId) { profileItemWrap.classList.add('hidden'); profileItemSel.value = '0'; return; }
    const items = DB_SUB_ITEMS.filter(si => si.supplier_id == supplierId);
    if (items.length === 0) { profileItemWrap.classList.add('hidden'); profileItemSel.value = '0'; return; }
    if (items.length === 1) {
        profileItemWrap.classList.add('hidden');
        profileItemSel.innerHTML = '<option value="' + items[0].id + '">' + esc(items[0].name) + '</option>';
        profileItemSel.value = items[0].id;
        return;
    }
    profileItemWrap.classList.remove('hidden');
    profileItemSel.innerHTML = '<option value="0">— Выберите элемент —</option>';
    items.forEach(si => {
        const opt = document.createElement('option');
        opt.value = si.id;
        opt.textContent = si.name;
        profileItemSel.appendChild(opt);
    });
}

function populateSuppliers() {
    const configs = [
        { id: 'supplier_profile', ids: DB_PROFILE_SUPPLIER_IDS },
        { id: 'supplier_kleevaya', ids: DB_KLEEVAJA_SUPPLIER_IDS },
        { id: 'supplier_metizy', ids: DB_METIZY_SUPPLIER_IDS }
    ];
    configs.forEach(cfg => {
        const sel = document.getElementById(cfg.id);
        DB_SUPPLIERS.forEach(s => {
            if (cfg.ids.indexOf(s.id) === -1) return;
            const opt = document.createElement('option');
            opt.value = s.id;
            opt.textContent = s.company_name;
            sel.appendChild(opt);
        });
    });
}

function getSheetDimensions() {
    if (panelFormatSel.value === 'custom') {
        return {
            w: parseFloat(customLengthInput.value) || 0,
            h: parseFloat(customWidthInput.value) || 0
        };
    }
    const opt = panelFormatSel.selectedOptions[0];
    if (!opt || !opt.dataset.w) return { w: 0, h: 0 };
    return { w: parseFloat(opt.dataset.w), h: parseFloat(opt.dataset.h) };
}

function esc(s) { return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
function fmt(v, d) { return new Intl.NumberFormat('ru-RU', {minimumFractionDigits: d || 0, maximumFractionDigits: d || 0}).format(v || 0); }

function materialFormulaHelp(formula, name) {
    return `<span class="app-field-help material-formula-help" role="button" tabindex="0" aria-label="Формула расчёта: ${esc(name)}" aria-expanded="false">i<span class="app-field-help__tooltip" role="tooltip">${esc(formula)}</span></span>`;
}

function consumptionFormula(consumption, consumptionUnit, totalArea, totalProfile, reservePct) {
    if (consumption <= 0) return 'Расход не задан: количество = 0.';
    if (consumptionUnit === 'панель') {
        return `Количество = расход на м² × площадь панелей × (1 + запас / 100), с округлением вверх: ${fmt(consumption, 3)} × ${fmt(totalArea, 2)} × (1 + ${fmt(reservePct, 1)} / 100).`;
    }
    return `Количество = расход на м.п. × длина профиля с запасом, с округлением вверх: ${fmt(consumption, 3)} × ${fmt(totalProfile, 0)}.`;
}

function pieceFormula(formula, total, qtyPerPiece) {
    return `${formula} Количество штук = расчётный расход / количество в одной штуке, с округлением вверх: ${fmt(total, 2)} / ${fmt(qtyPerPiece, 2)}.`;
}

var grandTotal = 0;
var deletedItems = new Set();
var customRows = [];

function calc() {
    const dim = getSheetDimensions();
    const qty = parseInt(sheetQtyInput.value) || 0;
    const profileStepMm = parseFloat(profileStepInput.value) || 400;
    const corners = parseInt(cornerQtyInput.value) || 0;
    const subHeight = parseFloat(subsystemHeightInput.value) || 0;
    const hStrengthen = hStrengthenSelect.value === '1';
    const hStepMm = parseFloat(hStrengthenStepInput.value) || 1000;

    const totalArea = (dim.w * dim.h * qty) / 1000000;

    const profileHeightM = subHeight > 0 ? subHeight / 1000 : dim.h / 1000;
    const panelWidthM = dim.w / 1000;

    const gaps = Math.max(1, Math.floor(dim.w / profileStepMm));
    const exactStep = Math.round(dim.w / gaps * 10) / 10;
    const profilesPerSheet = gaps + 1;
    const totalVerticalProfiles = qty > 1 ? profilesPerSheet + (qty - 1) * gaps : profilesPerSheet;
    const totalVerticalM = totalVerticalProfiles * profileHeightM;
    const roundedVerticalM = Math.ceil(totalVerticalM);

    let hProfilesM = 0;
    if (hStrengthen) {
        const hGaps = Math.max(1, Math.floor(profileHeightM * 1000 / hStepMm));
        const hProfilesPerSheet = hGaps + 1;
        const totalHProfiles = qty > 1 ? hProfilesPerSheet + (qty - 1) * hGaps : hProfilesPerSheet;
        hProfilesM = totalHProfiles * panelWidthM;
    }
    const roundedHProfileM = Math.ceil(hProfilesM);

    const cornerProfiles = corners * profileHeightM * qty;
    const roundedCornerM = Math.ceil(cornerProfiles);

    const totalProfileM = roundedVerticalM + roundedCornerM + roundedHProfileM;

    const reservePct = parseFloat(reservePctInput.value) || 0;
    const totalProfileMWithReserve = Math.ceil(totalProfileM * (1 + reservePct / 100));

    const infoBlock = document.getElementById('panel-info-block');
    if (dim.w > 0 && dim.h > 0 && qty > 0) {
        infoBlock.classList.remove('hidden');
        document.getElementById('info-format').textContent = dim.h + '×' + dim.w + ' мм';
        document.getElementById('info-sheet-area').textContent = fmt(totalArea, 2);
        document.getElementById('info-sheet-perimeter').textContent = fmt(exactStep, 1) + ' мм (' + profilesPerSheet + ' шт./лист)';
        document.getElementById('info-edges-total').textContent = fmt(totalProfileMWithReserve, 0) + ' м.п.';
    } else {
        infoBlock.classList.add('hidden');
    }

    const supplierProfile = parseInt(document.getElementById('supplier_profile').value) || 0;
    const supplierKleevaya = parseInt(document.getElementById('supplier_kleevaya').value) || 0;
    const supplierMetizy = parseInt(document.getElementById('supplier_metizy').value) || 0;
    const enclosureType = parseInt(document.getElementById('enclosure_type').value) || 0;
    const roundToPieces = document.getElementById('round_to_pieces').value === '1';

    const allGroups = [];

    if (supplierProfile) {
        const profileItems = [];
        const selectedProfileItem = parseInt(document.getElementById('profile_item').value) || 0;
        DB_SUB_ITEMS.filter(si => si.supplier_id == supplierProfile && selectedProfileItem && si.id == selectedProfileItem && !deletedItems.has('si_' + si.id)).forEach(si => {
            let total = 0;
            let formula = '';
            if (si.unit === 'м.п.' || si.unit === 'мп') {
                total = totalProfileMWithReserve;
                formula = `Длина профиля = сумма вертикальных, угловых профилей и перемычек × (1 + запас / 100), с округлением каждого значения вверх = ${fmt(totalProfileMWithReserve, 0)} м.п.`;
            } else {
                let consumption = parseFloat(si.consumption_per_m) || 0;
                const cUnit = (si.consumption_unit || 'Профиль').toLowerCase();
                if (consumption > 0) {
                    if (cUnit === 'панель') {
                        total = Math.ceil(consumption * totalArea * (1 + reservePct / 100));
                    } else {
                        total = Math.ceil(consumption * totalProfileMWithReserve);
                    }
                }
                formula = consumptionFormula(consumption, cUnit, totalArea, totalProfileMWithReserve, reservePct);
            }
            let price = parseFloat(si.price_per_unit) || 0;
            let showUnit = si.unit;
            let showQty = total;
            let perPieceText = '';
            if (roundToPieces && parseFloat(si.quantity_per_piece) > 0) {
                const qtyPerPiece = parseFloat(si.quantity_per_piece);
                const piecesCount = Math.ceil(total / qtyPerPiece);
                showQty = piecesCount;
                showUnit = 'шт.';
                perPieceText = fmt(qtyPerPiece, 0) + ' ' + si.unit;
                price = parseFloat(si.price_per_piece) || price;
                formula = pieceFormula(formula, total, qtyPerPiece);
            }
            profileItems.push({ id: 'si_' + si.id, name: si.name, unit: showUnit, qty: showQty, total: total, totalUnit: si.unit, price: price, sum: price * showQty, perPieceText: perPieceText, formula: formula });
        });
        if (profileItems.length) allGroups.push({ title: 'Профиль', items: profileItems });
    }

    if (supplierKleevaya) {
        const kleevayaItems = [];
        DB_MATERIALS.filter(m => m.supplier_id == supplierKleevaya && !deletedItems.has('m_' + m.id)).forEach(m => {
            let consumption = parseFloat(m.consumption_per_m) || 0;
            let total = 0;
            const cUnit = (m.consumption_unit || 'Профиль').toLowerCase();
            if (consumption > 0) {
                if (cUnit === 'панель') {
                    total = Math.ceil(consumption * totalArea * (1 + reservePct / 100));
                } else {
                    total = Math.ceil(consumption * totalProfileMWithReserve);
                }
            }
            let price = parseFloat(m.price_per_unit) || 0;
            let showUnit = m.unit;
            let showQty = total;
            let perPieceText = '';
            let formula = consumptionFormula(consumption, cUnit, totalArea, totalProfileMWithReserve, reservePct);
            if (roundToPieces && parseFloat(m.quantity_per_piece) > 0) {
                const qtyPerPiece = parseFloat(m.quantity_per_piece);
                const piecesCount = Math.ceil(total / qtyPerPiece);
                showQty = piecesCount;
                showUnit = 'шт.';
                perPieceText = fmt(qtyPerPiece, 0) + ' ' + m.unit;
                price = parseFloat(m.price_per_piece) || price;
                formula = pieceFormula(formula, total, qtyPerPiece);
            }
            kleevayaItems.push({ id: 'm_' + m.id, name: m.name, unit: showUnit, qty: showQty, total: total, totalUnit: m.unit, price: price, sum: price * showQty, perPieceText: perPieceText, formula: formula });
        });
        if (kleevayaItems.length) allGroups.push({ title: 'Клеевая система', items: kleevayaItems });
    }

    if (enclosureType && supplierMetizy) {
        const metizyItems = [];
        DB_FASTENERS.filter(f => f.supplier_id == supplierMetizy && !deletedItems.has('f_' + f.id)).forEach(f => {
            let consumption = parseFloat(f.consumption_per_m) || 0;
            let total = 0;
            const cUnit = (f.consumption_unit || 'Профиль').toLowerCase();
            if (consumption > 0) {
                if (cUnit === 'панель') {
                    total = Math.ceil(consumption * totalArea * (1 + reservePct / 100));
                } else {
                    total = Math.ceil(consumption * totalProfileMWithReserve);
                }
            }
            let price = parseFloat(f.price_per_unit) || 0;
            let showUnit = f.unit;
            let showQty = total;
            let perPieceText = '';
            let formula = consumptionFormula(consumption, cUnit, totalArea, totalProfileMWithReserve, reservePct);
            if (roundToPieces && parseFloat(f.quantity_per_piece) > 0) {
                const qtyPerPiece = parseFloat(f.quantity_per_piece);
                const piecesCount = Math.ceil(total / qtyPerPiece);
                showQty = piecesCount;
                showUnit = 'шт.';
                perPieceText = fmt(qtyPerPiece, 0) + ' ' + f.unit;
                price = parseFloat(f.price_per_piece) || price;
                formula = pieceFormula(formula, total, qtyPerPiece);
            }
            metizyItems.push({ id: 'f_' + f.id, name: f.name, unit: showUnit, qty: showQty, total: total, totalUnit: f.unit, price: price, sum: price * showQty, perPieceText: perPieceText, formula: formula });
        });
        if (metizyItems.length) allGroups.push({ title: 'Метизы', items: metizyItems });
    }

    materialsTbody.innerHTML = '';
    let idx = 0;
    grandTotal = 0;
    allGroups.forEach(group => {
        let groupTotal = 0;
        const trGroup = document.createElement('tr');
        trGroup.innerHTML = `<td colspan="8" style="background:#f1f5f9;font-weight:700;padding:8px 12px;color:#374151;">${esc(group.title)}</td>`;
        materialsTbody.appendChild(trGroup);

        group.items.forEach(item => {
            idx++;
            const tr = document.createElement('tr');
            tr.dataset.itemId = item.id;
            const unitHtml = item.perPieceText ? esc(item.unit) + ' <span style="color:#6b7280;font-size:11px;font-weight:normal;">(по ' + esc(item.perPieceText) + ')</span>' : esc(item.unit);
            const totalCell = `<td class="num material-total">${fmt(item.total, 2)} ${esc(item.totalUnit)}</td>`;
            tr.innerHTML = `<td>${idx}</td><td><span class="material-name-with-help"><span>${esc(item.name)}</span>${materialFormulaHelp(item.formula, item.name)}</span></td><td class="num"><input type="number" class="mat-qty" value="${item.qty}" min="0" step="0.1" style="width:80px;text-align:right;border:1px solid #d1d5db;border-radius:4px;padding:4px;"></td><td>${unitHtml}</td>${totalCell}<td class="num mat-price-cell"><input type="number" class="mat-price" value="${item.price}" min="0" step="0.01" style="width:100px;text-align:right;border:1px solid #d1d5db;border-radius:4px;padding:4px;"></td><td class="num mat-sum"><b>${fmt(item.sum, 2)}</b></td><td style="text-align:center"><button type="button" class="mat-del" style="background:none;border:none;color:#dc2626;cursor:pointer;font-size:18px;" title="Удалить">&times;</button></td>`;
            materialsTbody.appendChild(tr);
        });

            const trGroupTotal = document.createElement('tr');
            trGroupTotal.classList.add('group-total');
            trGroupTotal.innerHTML = `<td colspan="7" style="text-align:right;font-weight:600;color:#64748b;">Итого ${esc(group.title)}:</td><td class="num"><b>0.00</b></td>`;
        materialsTbody.appendChild(trGroupTotal);
    });

    if (customRows.length) {
        const trGroup = document.createElement('tr');
        trGroup.innerHTML = `<td colspan="8" style="background:#f1f5f9;font-weight:700;padding:8px 12px;color:#374151;">Дополнительно</td>`;
        materialsTbody.appendChild(trGroup);
        customRows.forEach(cr => {
            idx++;
            const tr = document.createElement('tr');
            tr.classList.add('custom-row');
            tr.dataset.customId = cr.id;
            tr.innerHTML = `<td>${idx}</td><td><input type="text" class="mat-name" value="${esc(cr.name)}" style="width:150px;border:1px solid #d1d5db;border-radius:4px;padding:4px;"></td><td class="num"><input type="number" class="mat-qty" value="${cr.qty}" min="0" step="0.1" style="width:80px;text-align:right;border:1px solid #d1d5db;border-radius:4px;padding:4px;"></td><td><input type="text" class="mat-unit" value="${esc(cr.unit)}" style="width:60px;border:1px solid #d1d5db;border-radius:4px;padding:4px;"></td><td class="num material-total">${fmt(cr.qty, 2)} ${esc(cr.unit)}</td><td class="num mat-price-cell"><input type="number" class="mat-price" value="${cr.price}" min="0" step="0.01" style="width:100px;text-align:right;border:1px solid #d1d5db;border-radius:4px;padding:4px;"></td><td class="num mat-sum"><b>${fmt(cr.qty * cr.price, 2)}</b></td><td style="text-align:center"><button type="button" class="mat-del" style="background:none;border:none;color:#dc2626;cursor:pointer;font-size:18px;" title="Удалить">&times;</button></td>`;
            materialsTbody.appendChild(tr);
        });
    }

    recalcTotals();

    if (allGroups.length > 1 || customRows.length) {
        const trGrand = document.createElement('tr');
        trGrand.classList.add('grand-total');
        trGrand.innerHTML = `<td colspan="7" style="text-align:right;font-weight:700;background:#eff6ff;border-top:2px solid #2563eb;">ИТОГО:</td><td class="num" style="background:#eff6ff;border-top:2px solid #2563eb;font-size:15px;"><b>${fmt(grandTotal, 2)}</b></td>`;
        materialsTbody.appendChild(trGrand);
    }
}

function recalcTotals() {
    const tbody = materialsTbody;
    let curGroupTotal = 0;

    for (const tr of tbody.querySelectorAll('tr')) {
        if (tr.classList.contains('group-total')) {
            tr.querySelector('b').textContent = fmt(curGroupTotal, 2);
            curGroupTotal = 0;
        } else if (tr.querySelectorAll('td').length >= 7 && !tr.classList.contains('custom-row')) {
            const qtyInput = tr.querySelector('.mat-qty');
            const priceInput = tr.querySelector('.mat-price');
            const qty = parseFloat(qtyInput?.value) || 0;
            const price = parseFloat(priceInput?.value) || 0;
            const sum = qty * price;
            const sumCell = tr.querySelector('.mat-sum');
            sumCell.innerHTML = `<b>${fmt(sum, 2)}</b>`;
            curGroupTotal += sum;
        } else if (tr.classList.contains('custom-row')) {
            const qtyInput = tr.querySelector('.mat-qty');
            const priceInput = tr.querySelector('.mat-price');
            const qty = parseFloat(qtyInput?.value) || 0;
            const price = parseFloat(priceInput?.value) || 0;
            const sum = qty * price;
            const sumCell = tr.querySelector('.mat-sum');
            sumCell.innerHTML = `<b>${fmt(sum, 2)}</b>`;
            const unit = tr.querySelector('.mat-unit')?.value || '';
            tr.querySelector('.material-total').textContent = `${fmt(qty, 2)} ${unit}`.trim();
            const customRow = customRows.find(r => r.id === tr.dataset.customId);
            if (customRow) {
                customRow.qty = qty;
                customRow.price = price;
            }
            curGroupTotal += sum;
        }
    }

    grandTotal = 0;
    tbody.querySelectorAll('.group-total').forEach(tr => {
        const val = parseFloat(tr.querySelector('b').textContent.replace(/\s/g, '').replace(',', '.')) || 0;
        grandTotal += val;
    });
    const grandRow = tbody.querySelector('.grand-total');
    if (grandRow) grandRow.querySelector('b').textContent = fmt(grandTotal, 2);
}

materialsTbody.addEventListener('input', function(e) {
    if (e.target.classList.contains('mat-qty') || e.target.classList.contains('mat-price') || e.target.classList.contains('mat-name') || e.target.classList.contains('mat-unit')) {
        if (e.target.classList.contains('mat-name') || e.target.classList.contains('mat-unit')) {
            const tr = e.target.closest('tr');
            const cid = tr?.dataset.customId;
            if (cid) {
                const cr = customRows.find(r => r.id === cid);
                if (cr) {
                    if (e.target.classList.contains('mat-name')) cr.name = e.target.value;
                    if (e.target.classList.contains('mat-unit')) cr.unit = e.target.value;
                }
            }
        }
        recalcTotals();
    }
});

materialsTbody.addEventListener('click', function(e) {
    const btn = e.target.closest('.mat-del');
    if (!btn) return;
    const tr = btn.closest('tr');
    if (tr.dataset.itemId) deletedItems.add(tr.dataset.itemId);
    if (tr.dataset.customId) {
        customRows = customRows.filter(r => r.id !== tr.dataset.customId);
    }
    const nextTr = tr.nextElementSibling;
    const prevTr = tr.previousElementSibling;
    if (prevTr && prevTr.classList.contains('group-total') && nextTr && nextTr.classList.contains('group-total')) {
        prevTr.remove();
    }
    tr.remove();
    let idx = 0;
    materialsTbody.querySelectorAll('tr').forEach(row => {
        if (!row.classList.contains('group-total') && !row.classList.contains('grand-total')) {
            const cells = row.querySelectorAll('td');
            if (cells.length >= 7) {
                idx++;
                cells[0].textContent = idx;
            }
        }
    });
    recalcTotals();
    const grandRow = materialsTbody.querySelector('.grand-total');
    if (grandRow) grandRow.remove();
    const hasMultipleGroups = materialsTbody.querySelectorAll('.group-total').length > 1;
    if (hasMultipleGroups || customRows.length) {
        const trGrand = document.createElement('tr');
        trGrand.classList.add('grand-total');
        trGrand.innerHTML = `<td colspan="7" style="text-align:right;font-weight:700;background:#eff6ff;border-top:2px solid #2563eb;">ИТОГО:</td><td class="num" style="background:#eff6ff;border-top:2px solid #2563eb;font-size:15px;"><b>${fmt(grandTotal, 2)}</b></td>`;
        materialsTbody.appendChild(trGrand);
    }
});

var customRowId = 0;
function addCustomRow(name, unit, qty, price) {
    customRowId++;
    const cr = {
        id: 'cr_' + customRowId,
        name: name || '',
        unit: unit || '',
        qty: qty || 0,
        price: price || 0
    };
    customRows.push(cr);
    calc();
}

roundToPiecesSelect.addEventListener('change', calc);

let syncingQty = false;

function syncQtyToArea() {
    if (syncingQty) return;
    syncingQty = true;
    const dim = getSheetDimensions();
    const qty = parseInt(sheetQtyInput.value) || 0;
    if (dim.w > 0 && dim.h > 0 && qty > 0) {
        sheetAreaInput.value = Math.round(dim.w * dim.h * qty / 100) / 10000;
    } else {
        sheetAreaInput.value = '';
    }
    syncingQty = false;
}

function syncAreaToQty() {
    if (syncingQty) return;
    syncingQty = true;
    const dim = getSheetDimensions();
    const area = parseFloat(sheetAreaInput.value) || 0;
    if (dim.w > 0 && dim.h > 0 && area > 0) {
        const sheetArea = dim.w * dim.h / 1000000;
        sheetQtyInput.value = Math.ceil(area / sheetArea);
    }
    syncQtyToArea();
    syncingQty = false;
}

panelFormatSel.addEventListener('change', () => {
    const isCustom = panelFormatSel.value === 'custom';
    customLengthWrap.classList.toggle('hidden', !isCustom);
    customWidthWrap.classList.toggle('hidden', !isCustom);
    syncQtyToArea();
    calc();
});
customLengthInput.addEventListener('input', () => { syncQtyToArea(); calc(); });
customWidthInput.addEventListener('input', () => { syncQtyToArea(); calc(); });
sheetQtyInput.addEventListener('input', () => { syncQtyToArea(); calc(); });
sheetAreaInput.addEventListener('input', () => { syncAreaToQty(); calc(); });
profileStepInput.addEventListener('input', calc);
cornerQtyInput.addEventListener('input', calc);
subsystemHeightInput.addEventListener('input', calc);
hStrengthenSelect.addEventListener('change', () => {
    hStrengthenStepWrap.classList.toggle('hidden', hStrengthenSelect.value !== '1');
    calc();
});
hStrengthenStepInput.addEventListener('input', calc);
document.getElementById('enclosure_type').addEventListener('change', function() {
    document.getElementById('metizy-wrap').classList.toggle('hidden', this.value === '0');
    calc();
});
document.getElementById('supplier_profile').addEventListener('change', function() {
    populateProfileItems();
    calc();
});
document.getElementById('supplier_kleevaya').addEventListener('change', calc);
document.getElementById('supplier_metizy').addEventListener('change', calc);
profileItemSel.addEventListener('change', calc);
reservePctInput.addEventListener('input', calc);

populateFormats();
populateEnclosures();
populateSuppliers();
document.getElementById('metizy-wrap').classList.toggle('hidden', document.getElementById('enclosure_type').value === '0');
populateProfileItems();
calc();

<?php if ($editCalc && !empty($editCalc['params'])): ?>
(function() {
    try {
        var p = JSON.parse('<?php echo addslashes($editCalc['params']); ?>');
        if (p.format) { panelFormatSel.value = p.format; panelFormatSel.dispatchEvent(new Event('change')); }
        if (p.custom_length) customLengthInput.value = p.custom_length;
        if (p.custom_width) customWidthInput.value = p.custom_width;
        if (p.sheet_qty) sheetQtyInput.value = p.sheet_qty;
        if (p.sheet_area) sheetAreaInput.value = p.sheet_area;
        if (p.profile_step) profileStepInput.value = p.profile_step;
        if (p.corner_qty) cornerQtyInput.value = p.corner_qty;
        if (p.subsystem_height) subsystemHeightInput.value = p.subsystem_height;
        if (p.enclosure_type) document.getElementById('enclosure_type').value = p.enclosure_type;
        if (p.h_strengthen) { hStrengthenSelect.value = p.h_strengthen; hStrengthenSelect.dispatchEvent(new Event('change')); }
        if (p.h_strengthen_step) hStrengthenStepInput.value = p.h_strengthen_step;
        if (p.supplier_profile) { document.getElementById('supplier_profile').value = p.supplier_profile; populateProfileItems(); }
        if (p.supplier_kleevaya) document.getElementById('supplier_kleevaya').value = p.supplier_kleevaya;
        if (p.supplier_metizy) document.getElementById('supplier_metizy').value = p.supplier_metizy;
        if (p.profile_item) document.getElementById('profile_item').value = p.profile_item;
        if (p.round_to_pieces) roundToPiecesSelect.value = p.round_to_pieces;
        if (p.reserve_pct !== undefined) reservePctInput.value = p.reserve_pct;
        if (p.deleted_items) { deletedItems = new Set(p.deleted_items); }
        if (p.custom_rows && Array.isArray(p.custom_rows)) { customRows = p.custom_rows; customRowId = customRows.length; }
        syncQtyToArea();
        calc();
    } catch(e) {}
})();
<?php endif; ?>

document.getElementById('calc-form').addEventListener('submit', function(e) {
    var calcName = document.getElementById('calc_name').value.trim();
    if (!calcName) {
        e.preventDefault();
        document.getElementById('calc_name').focus();
        return;
    }
    var hidden1 = document.createElement('input');
    hidden1.type = 'hidden'; hidden1.name = 'calc_name'; hidden1.value = calcName;
    var hidden2 = document.createElement('input');
    hidden2.type = 'hidden'; hidden2.name = 'object_name'; hidden2.value = document.getElementById('object_name').value;
    this.appendChild(hidden1);
    this.appendChild(hidden2);
    document.getElementById('total_price').value = grandTotal;

    var params = {
        format: panelFormatSel.value,
        custom_length: customLengthInput.value,
        custom_width: customWidthInput.value,
        sheet_qty: sheetQtyInput.value,
        sheet_area: sheetAreaInput.value,
        profile_step: profileStepInput.value,
        corner_qty: cornerQtyInput.value,
        subsystem_height: subsystemHeightInput.value,
        enclosure_type: document.getElementById('enclosure_type').value,
        h_strengthen: hStrengthenSelect.value,
        h_strengthen_step: hStrengthenStepInput.value,
        supplier_profile: document.getElementById('supplier_profile').value,
        supplier_kleevaya: document.getElementById('supplier_kleevaya').value,
        supplier_metizy: document.getElementById('supplier_metizy').value,
        round_to_pieces: roundToPiecesSelect.value,
        reserve_pct: reservePctInput.value,
        profile_item: document.getElementById('profile_item').value,
        deleted_items: Array.from(deletedItems),
        custom_rows: customRows.map(r => ({ id: r.id, name: r.name, unit: r.unit, qty: r.qty, price: r.price })),
    };
    document.getElementById('calc_params').value = JSON.stringify(params);
});

function exportPdf() {
    const objectName = document.getElementById('object_name').value || '';
    const calcName = document.getElementById('calc_name').value || '';
    const date = new Date().toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric' });
    const subtitleParts = [];
    if (calcName) subtitleParts.push(calcName);
    if (objectName) subtitleParts.push('Объект: ' + objectName);
    subtitleParts.push(date);
    document.getElementById('export-subtitle').textContent = subtitleParts.join(' | ');

    const paramsTable = document.getElementById('export-params-table');
    const params = [
        ['Формат панели', document.getElementById('info-format').textContent || '—'],
        ['Количество листов', sheetQtyInput.value || '—'],
        ['Площадь', (document.getElementById('info-sheet-area').textContent || '—') + ' м²'],
        ['Шаг профиля', document.getElementById('info-sheet-perimeter').textContent || '—'],
        ['Итого профиль', document.getElementById('info-edges-total').textContent || '—'],
        ['Высота подсистемы', subsystemHeightInput.value ? subsystemHeightInput.value + ' мм' : 'По длине листа'],
        ['Количество углов', cornerQtyInput.value || '0'],
        ['Перемычка', hStrengthenSelect.value === '1' ? 'Да (шаг ' + hStrengthenStepInput.value + ' мм)' : 'Нет'],
        ['Округлять до шт.', roundToPiecesSelect.value === '1' ? 'Да' : 'Нет'],
        ['Запас', (parseFloat(reservePctInput.value) || 0) + ' %']
    ];
    paramsTable.innerHTML = params.map(function(r) {
        return '<tr><td>' + r[0] + '</td><td>' + r[1] + '</td></tr>';
    }).join('');

    window.print();
}

function exportExcel() {
    const objectName = document.getElementById('object_name').value || '';
    const calcName = document.getElementById('calc_name').value || '';
    const date = new Date().toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric' });

    const excelColCount = 7;
    let html = '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">';
    html += '<head><meta charset="utf-8"><!--[if gte mso 9]><xml><x:ExcelWorkbook><x:ExcelWorksheets><x:ExcelWorksheet><x:Name>Расчёт</x:Name></x:ExcelWorksheet></x:ExcelWorksheets></x:ExcelWorkbook></xml><![endif]--></head>';
    html += '<body>';
    html += '<table border="1" cellpadding="6" cellspacing="0" style="border-collapse:collapse;font-family:Arial;font-size:12px;width:100%;">';

    function row(cells, style, bold) {
        html += '<tr>';
        cells.forEach(function(c) {
            html += '<td' + (style ? ' style="' + style + '"' : '') + (bold ? ' style="font-weight:bold;' + (style || '') + '"' : '') + '>' + (c === null || c === undefined ? '' : c) + '</td>';
        });
        html += '</tr>';
    }

    function emptyRow() { html += '<tr><td colspan="' + excelColCount + '">&nbsp;</td></tr>'; }

    function headerRow(text) {
        html += '<tr><td colspan="' + excelColCount + '" style="background:#1d4ed8;color:#fff;font-size:14px;font-weight:bold;padding:10px;text-align:center;">' + text + '</td></tr>';
    }

    function colHeaderRow(cells) {
        html += '<tr>';
        cells.forEach(function(c) {
            html += '<td style="background:#e2e8f0;font-weight:bold;text-align:center;padding:6px;">' + c + '</td>';
        });
        html += '</tr>';
    }

    headerRow('РАСЧЁТ ПОДСИСТЕМЫ');
    emptyRow();
    row(['Дата:', date]);
    if (calcName) row(['Расчёт:', calcName]);
    if (objectName) row(['Объект:', objectName]);
    emptyRow();

    headerRow('ПАРАМЕТРЫ РАСЧЁТА');
    emptyRow();
    row(['Формат панели', document.getElementById('info-format').textContent || '—']);
    row(['Количество листов', sheetQtyInput.value || '—']);
    row(['Площадь', (document.getElementById('info-sheet-area').textContent || '—') + ' м²']);
    row(['Шаг профиля', document.getElementById('info-sheet-perimeter').textContent || '—']);
    row(['Итого профиль', document.getElementById('info-edges-total').textContent || '—']);
    row(['Высота подсистемы', subsystemHeightInput.value ? subsystemHeightInput.value + ' мм' : 'По длине листа']);
    row(['Количество углов', cornerQtyInput.value || '0']);
    row(['Перемычка', hStrengthenSelect.value === '1' ? 'Да (шаг ' + hStrengthenStepInput.value + ' мм)' : 'Нет']);
    row(['Округлять до шт.', roundToPiecesSelect.value === '1' ? 'Да' : 'Нет']);
    row(['Запас', (parseFloat(reservePctInput.value) || 0) + ' %']);
    emptyRow();

    headerRow('РАСХОД МАТЕРИАЛОВ');
    emptyRow();
    var showPerPiece = roundToPiecesSelect.value === '1';
    colHeaderRow(['№', 'Наименование', 'Кол-во', 'Ед. изм.', 'Всего', 'Цена за ед.', 'Сумма']);

    const tbody = document.getElementById('materials-tbody');
    let grandTotal = 0;
    tbody.querySelectorAll('tr').forEach(function(tr) {
        const cells = tr.querySelectorAll('td');
        if (tr.classList.contains('grand-total')) return;
        if (tr.classList.contains('group-total')) {
            const label = cells[0].textContent.trim();
            const val = cells[cells.length - 1].textContent.trim();
            grandTotal += parseFloat(val.replace(/\s/g, '').replace(',', '.')) || 0;
            html += '<tr><td colspan="6" style="background:#f1f5f9;font-weight:bold;text-align:right;">' + label + '</td><td style="background:#f1f5f9;font-weight:bold;text-align:right;">' + val + '</td></tr>';
        } else if (cells.length >= 7) {
            var qtyVal = cells[2].querySelector('input') ? cells[2].querySelector('input').value : cells[2].textContent.trim();
            var unitText = cells[3].textContent.trim();
            var totalCell = tr.querySelector('.material-total');
            var priceVal = tr.querySelector('.mat-price').value;
            var sumVal = tr.querySelector('.mat-sum').textContent.trim();
            html += '<tr>';
            html += '<td style="text-align:center;">' + cells[0].textContent.trim() + '</td>';
            html += '<td>' + cells[1].textContent.trim() + '</td>';
            html += '<td style="text-align:right;">' + qtyVal + '</td>';
            html += '<td style="text-align:center;">' + unitText + '</td>';
            html += '<td style="text-align:right;">' + (totalCell ? totalCell.textContent.trim() : '') + '</td>';
            html += '<td style="text-align:right;">' + priceVal + '</td>';
            html += '<td style="text-align:right;font-weight:bold;">' + sumVal + '</td>';
            html += '</tr>';
        }
    });

    emptyRow();
    html += '<tr><td colspan="6" style="background:#1d4ed8;color:#fff;font-weight:bold;font-size:14px;text-align:right;padding:10px;">ИТОГО:</td>';
    html += '<td style="background:#1d4ed8;color:#fff;font-weight:bold;font-size:14px;text-align:right;padding:10px;">' + grandTotal.toFixed(2) + '</td></tr>';

    html += '</table></body></html>';

    const blob = new Blob(['\ufeff' + html], { type: 'application/vnd.ms-excel;charset=utf-8' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = (calcName || 'Расчёт_подсистемы') + '.xls';
    link.click();
    URL.revokeObjectURL(link.href);
}
</script>

</body>
</html>
