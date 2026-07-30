<?php
require_once __DIR__ . '/includes/admin_auth.php';
require_login();
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/admin_schema.php';

ob_start();
ensure_panel_formats_table($pdo);
ensure_panel_sizes_table($pdo);
ensure_panel_thicknesses_table($pdo);
ensure_manufacturers_table($pdo);

// Таблица сохранённых раскроев
$pdo->exec("CREATE TABLE IF NOT EXISTS cutting_layouts (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    cutting_name VARCHAR(200) NULL,
    object_name VARCHAR(200) NULL,
    version_group INT NULL,
    version_number INT NOT NULL DEFAULT 1,
    settings JSON NOT NULL COMMENT 'параметры раскроя: производители, форматы, декор, толщина, рез, отступ, метод',
    parts JSON NOT NULL COMMENT 'список деталей',
    result JSON NULL COMMENT 'результат раскроя (карты листов, итоги)',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
if (!function_exists('column_exists')) require_once __DIR__ . '/includes/admin_schema.php';
if (!$pdo->query("SHOW COLUMNS FROM cutting_layouts LIKE 'cutting_name'")->fetchColumn()) {
    $pdo->exec("ALTER TABLE cutting_layouts ADD COLUMN cutting_name VARCHAR(200) NULL AFTER id");
}
if (!$pdo->query("SHOW COLUMNS FROM cutting_layouts LIKE 'version_group'")->fetchColumn()) {
    $pdo->exec("ALTER TABLE cutting_layouts ADD COLUMN version_group INT NULL AFTER object_name");
    $pdo->exec("ALTER TABLE cutting_layouts ADD COLUMN version_number INT NOT NULL DEFAULT 1 AFTER version_group");
}

ob_end_clean();

// ── AJAX-обработчики сохранения/загрузки ──
$action = $_GET['action'] ?? '';
if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $settings = $input['settings'] ?? [];
    $partsData = $input['parts'] ?? [];
    $resultData = $input['result'] ?? null;
    $objectName = trim((string)($settings['object_name'] ?? ''));
    $cuttingName = trim((string)($settings['cutting_name'] ?? ''));
    $loadedId = (int)($settings['loaded_id'] ?? 0);

    if ($resultData && isset($resultData['sheets'])) {
        foreach ($resultData['sheets'] as &$sheet) {
            unset($sheet['freeRects']);
            if (isset($sheet['placed'])) {
                foreach ($sheet['placed'] as &$pl) {
                    unset($pl['name']);
                }
                unset($pl);
            }
        }
        unset($sheet);
        unset($resultData['formats']);
    }

    try {
        $settingsJson = json_encode($settings, JSON_UNESCAPED_UNICODE);
        $partsJson = json_encode($partsData, JSON_UNESCAPED_UNICODE);
        $resultJson = $resultData !== null ? json_encode($resultData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;

        $versionGroup = null;
        $versionNumber = 1;

        if ($loadedId > 0) {
            $parent = $pdo->prepare('SELECT version_group, version_number FROM cutting_layouts WHERE id = :id');
            $parent->execute(['id' => $loadedId]);
            $parentRow = $parent->fetch();
            if ($parentRow) {
                $versionGroup = $parentRow['version_group'] ?: $parentRow['id'];
                $versionNumber = (int)$parentRow['version_number'] + 1;
            }
        }

        if ($versionGroup === null) {
            $stmt = $pdo->prepare('INSERT INTO cutting_layouts (cutting_name, object_name, settings, parts, result) VALUES (:cutting_name, :object_name, :settings, :parts, :result)');
            $stmt->execute([
                'cutting_name' => $cuttingName === '' ? null : $cuttingName,
                'object_name' => $objectName === '' ? null : $objectName,
                'settings' => $settingsJson,
                'parts' => $partsJson,
                'result' => $resultJson,
            ]);
            $newId = (int)$pdo->lastInsertId();
            $pdo->prepare('UPDATE cutting_layouts SET version_group = :vg WHERE id = :id')->execute(['vg' => $newId, 'id' => $newId]);
        } else {
            $stmt = $pdo->prepare('INSERT INTO cutting_layouts (cutting_name, object_name, version_group, version_number, settings, parts, result) VALUES (:cutting_name, :object_name, :vg, :vn, :settings, :parts, :result)');
            $stmt->execute([
                'cutting_name' => $cuttingName === '' ? null : $cuttingName,
                'object_name' => $objectName === '' ? null : $objectName,
                'vg' => $versionGroup,
                'vn' => $versionNumber,
                'settings' => $settingsJson,
                'parts' => $partsJson,
                'result' => $resultJson,
            ]);
            $newId = (int)$pdo->lastInsertId();
        }

        echo json_encode(['ok' => true, 'id' => $newId]);
    } catch (\Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'load') {
    header('Content-Type: application/json; charset=utf-8');
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) { echo json_encode(['ok' => false, 'error' => 'bad id']); exit; }
    $stmt = $pdo->prepare('SELECT * FROM cutting_layouts WHERE id = :id');
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();
    if (!$row) { echo json_encode(['ok' => false, 'error' => 'not found']); exit; }
    echo json_encode([
        'ok' => true,
        'id' => $row['id'],
        'cutting_name' => $row['cutting_name'],
        'object_name' => $row['object_name'],
        'version_group' => $row['version_group'],
        'version_number' => $row['version_number'],
        'settings' => json_decode($row['settings'], true),
        'parts' => json_decode($row['parts'], true),
        'result' => $row['result'] ? json_decode($row['result'], true) : null,
    ]);
    exit;
}

if ($action === 'delete_layout') {
    header('Content-Type: application/json');
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        $pdo->prepare('DELETE FROM cutting_layouts WHERE id = ?')->execute([$id]);
    }
    echo json_encode(['ok' => true]);
    exit;
}

$manufacturers = $pdo->query('SELECT * FROM manufacturers ORDER BY full_name ASC')->fetchAll();
$panelSizes = $pdo->query('SELECT ps.*, m.full_name AS manufacturer_name FROM panel_sizes ps LEFT JOIN manufacturers m ON m.id=ps.manufacturer_id WHERE ps.is_active=1 ORDER BY m.full_name, ps.height_mm, ps.width_mm')->fetchAll();
$thicknesses = $pdo->query('SELECT * FROM panel_thicknesses WHERE is_active=1 ORDER BY thickness ASC')->fetchAll();
$panels = $pdo->query("SELECT pf.*, m.full_name AS manufacturer_name FROM panel_formats pf LEFT JOIN manufacturers m ON m.id=pf.manufacturer_id WHERE pf.is_active=1 AND pf.is_stock_program=1 ORDER BY m.full_name, pf.decor_number, pf.decor_name")->fetchAll();

$manufacturersJson = json_encode(array_values($manufacturers), JSON_UNESCAPED_UNICODE);
$panelSizesJson = json_encode(array_values($panelSizes), JSON_UNESCAPED_UNICODE);
$thicknessesJson = json_encode(array_values($thicknesses), JSON_UNESCAPED_UNICODE);
$panelsJson = json_encode(array_values($panels), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$savedLayouts = $pdo->query('SELECT id, cutting_name, object_name, version_group, version_number, created_at, updated_at FROM cutting_layouts ORDER BY version_group DESC, version_number DESC LIMIT 100')->fetchAll();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<title>Раскрой панелей</title>
<style>
body { font-family: 'Inter', Arial, sans-serif; background: #f5f7fb; margin: 0; color: #1f2937; }
.header{display:flex;align-items:center;gap:16px;flex-wrap:wrap; background: radial-gradient(circle at 10% 10%, rgba(59,130,246,.35), transparent 32%), linear-gradient(120deg, #0f172a, #1e3a8a 58%, #0f766e); color: #fff; padding: 22px 36px 30px; box-shadow: 0 18px 45px rgba(15,23,42,.18); }
.header a { color: #dbeafe; margin-right: 16px; text-decoration: none; }
.container { max-width: 1320px; margin: 28px auto; padding: 0 20px; }
.panel { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 22px; margin-bottom: 24px; box-shadow: 0 8px 24px rgba(15, 23, 42, 0.06); }
.section-title { font-size: 15px; font-weight: 700; color: #374151; background: #f1f5f9; border-left: 4px solid #2563eb; padding: 8px 12px; border-radius: 0 6px 6px 0; margin: 22px 0 14px 0; }
.section-title.main { font-size: 17px; background: #eff6ff; border-left-color: #1d4ed8; margin-top: 0; }
.grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 14px; }
.grid-3 { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 14px; }
label { display: block; font-weight: 600; margin-bottom: 6px; font-size: 13px; }
input, select { width: 100%; box-sizing: border-box; padding: 9px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 14px; }
input[readonly] { background: #f8fafc; }
button, .button { border: 0; border-radius: 8px; padding: 10px 16px; background: #2563eb; color: #fff; text-decoration: none; cursor: pointer; display: inline-block; font-weight: 600; font-size: 14px; }
.button.secondary, button.secondary { background: #64748b; }
.button.success, button.success { background: #16a34a; }
.button.danger, button.danger { background: #dc2626; }
.hint { color: #64748b; font-size: 13px; margin-top: 4px; }

.multi-row { display: flex; gap: 8px; align-items: flex-end; margin-bottom: 8px; }
.multi-row select, .multi-row input { flex: 1; }
.multi-row .rm-btn { flex: none; width: 38px; height: 38px; border-radius: 8px; border: none; background: #fef2f2; color: #dc2626; cursor: pointer; font-size: 16px; display:flex; align-items:center; justify-content:center; }
.multi-row .rm-btn:hover { background: #dc2626; color: #fff; }
.add-row-btn { background: #eff6ff; color: #2563eb; border: 1px dashed #93c5fd; border-radius: 8px; padding: 8px 14px; cursor: pointer; font-size: 13px; font-weight: 600; }
.add-row-btn:hover { background: #dbeafe; }

table.parts-table { width: 100%; border-collapse: collapse; background: #fff; }
table.parts-table th, table.parts-table td { padding: 8px 10px; text-align: left; vertical-align: middle; border-bottom: 1px solid #e5e7eb; font-size: 13px; }
table.parts-table th { background: #f8fafc; font-weight: 700; }
table.parts-table input, table.parts-table select { padding: 6px 8px; font-size: 13px; }
table.parts-table tr.editing { background: #eff6ff; }

.inline-edit:hover { border-color: #cbd5e1 !important; }
.inline-edit:focus { border-color: #93c5fd !important; outline: none; background: #fff !important; }

.actions-row { display: flex; gap: 10px; margin-top: 18px; flex-wrap: wrap; }

.summary-table { width: 100%; border-collapse: collapse; background: #fff; margin-bottom: 18px; }
.summary-table th, .summary-table td { padding: 10px 14px; border-bottom: 1px solid #e5e7eb; text-align: left; }
.summary-table th { background: #f8fafc; width: 40%; }
.summary-table td { font-weight: 700; }

.history-table { width: 100%; border-collapse: collapse; background: #fff; }
.history-table th, .history-table td { padding: 10px 14px; border-bottom: 1px solid #e5e7eb; text-align: left; font-size: 14px; }
.history-table th { background: #f8fafc; font-weight: 700; white-space: nowrap; }
.history-table th.sortable { cursor: pointer; user-select: none; }
.history-table th.sortable:hover { background: #e2e8f0; }
.sort-arrow { font-size: 11px; color: #64748b; margin-left: 4px; }
.history-table td { font-weight: 400; }

.sheets-header { background: #374151; color: #fff; padding: 10px 16px; border-radius: 8px 8px 0 0; font-weight: 700; margin-top: 24px; }
.sheet-block { border: 1px solid #d1d5db; border-radius: 0 0 10px 10px; margin-bottom: 24px; overflow: hidden; }
.sheet-canvas-wrap { background: #f3f4f6; padding: 16px; overflow-x: auto; }
.sheet-info { padding: 10px 16px; background: #fff; font-size: 13px; color: #374151; display:flex; gap:18px; flex-wrap:wrap; border-top:1px solid #e5e7eb; }
.sheet-info b { color: #1f2937; }
.part-rect text { font-size: 12px; fill: #1e3a8a; font-weight: 600; pointer-events:none; }
.waste-rect { fill: repeating-linear-gradient(45deg, #fecaca, #fecaca 4px, #fff 4px, #fff 8px); }

.modal-overlay { position: fixed; inset:0; background: rgba(0,0,0,0.4); display:flex; align-items:center; justify-content:center; z-index: 1000; }
.modal-box { background:#fff; border-radius:12px; padding:24px; width: 420px; max-width: 92vw; box-shadow: 0 12px 40px rgba(0,0,0,.2); }
.modal-box h3 { margin-top:0; }
.hidden { display:none !important; }
.btn-icon { display:inline-flex; align-items:center; justify-content:center; width:36px; height:36px; border:none; border-radius:8px; cursor:pointer; font-size:16px; transition:all .2s; }
.btn-icon.btn-edit { color:#2563eb; background:#eff6ff; }
.btn-icon.btn-edit:hover { background:#dbeafe; }
.btn-icon.btn-delete { color:#dc2626; background:#fef2f2; }
.btn-icon.btn-delete:hover { background:#fee2e2; }
.header h1 { margin: 0; font-size: clamp(24px, 3.5vw, 36px); letter-spacing: -.02em; font-weight: 900; }
</style>
</head>
<body>
<header class="header">
    <div style="width:100%;display:flex;align-items:center;gap:16px;">
        <a href="calculator.php">← Калькуляторы</a>
        <h1 style="flex:1;text-align:center;margin:0;">Раскрой панелей</h1>
        <span id="version-badge" style="display:none;background:#2563eb;color:#fff;padding:4px 12px;border-radius:20px;font-size:13px;font-weight:700"></span>
        <a href="logout.php">Выйти</a>
    </div>
</header>
<main class="container">

    <section class="panel">
        <div class="section-title main">Исходные данные</div>

        <div class="grid">
            <div>
                <label for="object_name">Название объекта</label>
                <input id="object_name" type="text" placeholder="Например, ЖК Северный, корпус 2">
            </div>
            <div>
                <label for="cutting_name">Название раскроя</label>
                <input id="cutting_name" type="text" placeholder="Например, Фасады кухни">
            </div>
            <div>
                <label for="kerf">Ширина реза, мм</label>
                <input id="kerf" type="number" min="0" step="0.1" value="4">
            </div>
            <div>
                <label for="margin">Отступ от края листа, мм</label>
                <input id="margin" type="number" min="0" step="0.1" value="5">
            </div>
            <div>
                <label for="method">Метод расчёта</label>
                <select id="method">
                    <option value="optimal">Оптимально (с разворотом)</option>
                    <option value="length">По длине (вдоль декора)</option>
                    <option value="width">По ширине (вдоль декора)</option>
                </select>
                <div class="hint" id="method-hint">При методе «Оптимально» детали можно разворачивать на 90°, если включён поворот.</div>
            </div>
        </div>

        <!-- ═══ ПРОИЗВОДИТЕЛИ ═══ -->
        <div class="section-title">Производитель панелей</div>
        <div id="manufacturers-list"></div>
        <button type="button" class="add-row-btn" id="add-manufacturer">+ Добавить производителя</button>

        <!-- ═══ ФОРМАТЫ ═══ -->
        <div class="section-title">Формат панелей</div>
        <div id="formats-list"></div>
        <button type="button" class="add-row-btn" id="add-format">+ Добавить формат</button>

        <!-- ═══ ДЕКОР И ТОЛЩИНА ═══ -->
        <div class="section-title">Декор и толщина</div>
        <div class="grid">
            <div>
                <label for="decor_select">Декор</label>
                <select id="decor_select">
                    <option value="">— Выберите декор —</option>
                </select>
                <div class="hint">Список фильтруется по выбранным производителям.</div>
            </div>
            <div>
                <label for="thickness_select">Толщина панели, мм</label>
                <select id="thickness_select">
                    <option value="">— Выберите толщину —</option>
                </select>
            </div>
            <div>
                <label for="sheet_cost">Стоимость листа</label>
                <input id="sheet_cost" type="number" min="0" step="0.01" value="0">
                <div class="hint">Подтягивается из БД, можно изменить вручную.</div>
            </div>
            <div>
                <label for="sheet_currency">Валюта</label>
                <input id="sheet_currency" type="text" value="RUB" readonly>
            </div>
        </div>
    </section>

    <section class="panel">
        <div class="section-title main">Список деталей</div>
        <table class="parts-table" id="parts-table">
            <thead>
                <tr>
                    <th style="width:60px">№</th>
                    <th>Наименование</th>
                    <th style="width:110px">Длина, мм</th>
                    <th style="width:110px">Ширина, мм</th>
                    <th style="width:90px">Кол-во</th>
                    <th style="width:100px">Поворот</th>
                    <th style="width:120px">Действия</th>
                </tr>
            </thead>
            <tbody id="parts-tbody"></tbody>
        </table>
        <div class="actions-row">
            <button type="button" class="success" id="add-part-btn">+ Добавить деталь</button>
        </div>

        <div class="actions-row">
            <button type="button" id="cut-btn">📐 Раскрой</button>
            <button type="button" class="secondary" id="save-btn">💾 Сохранить</button>
        </div>
    </section>

    <!-- ═══ РЕЗУЛЬТАТ РАСКРОЯ ═══ -->
    <section class="panel hidden" id="result-section">
        <div class="section-title main">Результат раскроя</div>

        <table class="summary-table" id="summary-table"></table>

        <div class="actions-row">
            <button type="button" class="secondary" id="export-excel-btn">📊 Экспорт в Excel</button>
            <button type="button" class="secondary" id="export-pdf-btn">📄 Экспорт в PDF</button>
        </div>

        <div id="sheets-container"></div>
    </section>

    <!-- ═══ ИСТОРИЯ ═══ -->
    <section class="panel">
        <div class="section-title main">Сохранённые раскрои</div>
        <div style="margin-bottom:12px;display:flex;gap:8px;align-items:center;flex-wrap:wrap">
            <input id="history-search" type="text" placeholder="Поиск по названию, объекту..." style="max-width:320px;padding:7px 12px;border:1px solid #cbd5e1;border-radius:8px;font-size:13px">
        </div>
        <table class="history-table" id="history-table">
            <thead><tr>
                <th class="sortable" data-col="cutting">Название <span class="sort-arrow" id="sort-cutting"></span></th>
                <th class="sortable" data-col="object">Объект <span class="sort-arrow" id="sort-object"></span></th>
                <th>Версия</th>
                <th class="sortable" data-col="date">Дата <span class="sort-arrow" id="sort-date"></span></th>
                <th>Действия</th>
            </tr></thead>
            <tbody id="history-tbody">
            <?php
            $prevGroup = null;
            foreach ($savedLayouts as $layout):
                $vg = $layout['version_group'] ?: $layout['id'];
                $isNewGroup = ($vg !== $prevGroup);
                $prevGroup = $vg;
                $dateShort = date('d.m H:i', strtotime($layout['updated_at']));
            ?>
                <tr data-cutting="<?php echo e($layout['cutting_name'] ?: ''); ?>" data-object="<?php echo e($layout['object_name'] ?: ''); ?>" data-date="<?php echo e($layout['updated_at']); ?>" style="<?php echo !$isNewGroup ? 'opacity:.7' : ''; ?>">
                    <td><?php echo e($layout['cutting_name'] ?: '—'); ?></td>
                    <td><?php echo e($layout['object_name'] ?: '—'); ?></td>
                    <td>v<?php echo (int)$layout['version_number']; ?></td>
                    <td style="white-space:nowrap"><?php echo e($dateShort); ?></td>
                    <td style="display:flex;gap:6px;align-items:center;">
                        <button type="button" class="btn-icon btn-edit" title="Редактировать" onclick="loadLayout(<?php echo (int)$layout['id']; ?>)">&#9998;</button>
                        <button type="button" class="btn-icon btn-delete" title="Удалить" onclick="deleteLayout(<?php echo (int)$layout['id']; ?>)">&#10005;</button>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$savedLayouts): ?>
                <tr><td colspan="5">Сохранённых раскроев пока нет.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </section>

</main>

<!-- ═══ МОДАЛЬНОЕ ОКНО ДЕТАЛИ ═══ -->
<div class="modal-overlay hidden" id="part-modal">
    <div class="modal-box">
        <h3 id="part-modal-title">Добавить деталь</h3>
        <div class="grid">
            <div style="grid-column:1/-1">
                <label for="pm-name">Наименование</label>
                <input id="pm-name" type="text" placeholder="Например, Боковина двери">
            </div>
            <div>
                <label for="pm-length">Длина, мм</label>
                <input id="pm-length" type="number" min="1" step="1">
            </div>
            <div>
                <label for="pm-width">Ширина, мм</label>
                <input id="pm-width" type="number" min="1" step="1">
            </div>
            <div>
                <label for="pm-qty">Количество</label>
                <input id="pm-qty" type="number" min="1" step="1" value="1">
            </div>
            <div>
                <label>Поворот</label>
                <div id="pm-rotate" class="toggle-rotate-modal" data-value="1" style="border:0;border-radius:6px;padding:8px 20px;cursor:pointer;font-weight:700;font-size:14px;background:#dcfce7;color:#166534;width:fit-content">Да</div>
            </div>
        </div>
        <div class="actions-row">
            <button type="button" id="pm-save">Сохранить</button>
            <button type="button" class="secondary" id="pm-cancel">Отмена</button>
        </div>
    </div>
</div>

<script>
/* ═══════════ ДАННЫЕ ИЗ БД ═══════════ */
const MANUFACTURERS = <?php echo $manufacturersJson; ?>;
const PANEL_SIZES   = <?php echo $panelSizesJson; ?>;
const THICKNESSES   = <?php echo $thicknessesJson; ?>;
const PANELS        = <?php echo $panelsJson; ?>;

let parts = []; // {id, name, length, width, qty, rotate}
let nextPartId = 1;
let lastResult = null;
let loadedId = null;

/* ═══════════ ПРОИЗВОДИТЕЛИ (множественный выбор) ═══════════ */
const manufacturersList = document.getElementById('manufacturers-list');
const formatsList = document.getElementById('formats-list');

function manufacturerOptionsHtml(selectedId) {
    let html = '<option value="">— Любой производитель —</option>';
    MANUFACTURERS.forEach(m => {
        html += `<option value="${m.id}" ${String(m.id)===String(selectedId)?'selected':''}>${escapeHtml(m.full_name)}</option>`;
    });
    return html;
}

function addManufacturerRow(selectedId) {
    const row = document.createElement('div');
    row.className = 'multi-row';
    row.innerHTML = `
        <div style="flex:1">
            <select class="mfr-select">${manufacturerOptionsHtml(selectedId)}</select>
        </div>
        <button type="button" class="rm-btn" title="Удалить">✕</button>
    `;
    row.querySelector('.rm-btn').addEventListener('click', () => {
        if (manufacturersList.children.length > 1) {
            row.remove();
        } else {
            row.querySelector('.mfr-select').value = '';
        }
        onManufacturersChanged();
    });
    row.querySelector('.mfr-select').addEventListener('change', onManufacturersChanged);
    manufacturersList.appendChild(row);
}
document.getElementById('add-manufacturer').addEventListener('click', () => addManufacturerRow(''));

function selectedManufacturerIds() {
    return Array.from(manufacturersList.querySelectorAll('.mfr-select'))
        .map(s => s.value).filter(v => v !== '');
}

/* ═══════════ ФОРМАТЫ ПАНЕЛЕЙ (множественный выбор + пользовательский) ═══════════ */
function formatOptionsHtml(selectedKey) {
    let html = '<option value="">— Выберите формат —</option>';
    const mfrIds = selectedManufacturerIds();
    PANEL_SIZES.forEach(sz => {
        if (mfrIds.length && sz.manufacturer_id && !mfrIds.includes(String(sz.manufacturer_id))) return;
        const key = `db:${sz.id}`;
        const label = `${sz.height_mm}×${sz.width_mm} мм` + (sz.manufacturer_name ? ` (${sz.manufacturer_name})` : '');
        html += `<option value="${key}" data-h="${sz.height_mm}" data-w="${sz.width_mm}" ${key===selectedKey?'selected':''}>${escapeHtml(label)}</option>`;
    });
    html += `<option value="custom" ${selectedKey==='custom'?'selected':''}>Свой формат…</option>`;
    return html;
}

function addFormatRow(selectedKey, customH, customW) {
    const row = document.createElement('div');
    row.className = 'multi-row';
    row.innerHTML = `
        <div style="flex:2">
            <select class="fmt-select">${formatOptionsHtml(selectedKey)}</select>
        </div>
        <div class="fmt-custom-wrap" style="flex:1;display:${selectedKey==='custom'?'flex':'none'};gap:6px;">
            <input type="number" class="fmt-h" placeholder="Высота, мм" min="1" value="${customH||''}">
            <input type="number" class="fmt-w" placeholder="Ширина, мм" min="1" value="${customW||''}">
        </div>
        <button type="button" class="rm-btn" title="Удалить">✕</button>
    `;
    const select = row.querySelector('.fmt-select');
    const customWrap = row.querySelector('.fmt-custom-wrap');
    select.addEventListener('change', () => {
        customWrap.style.display = select.value === 'custom' ? 'flex' : 'none';
    });
    row.querySelector('.rm-btn').addEventListener('click', () => {
        if (formatsList.children.length > 1) row.remove();
        else select.value = '';
    });
    formatsList.appendChild(row);
}
document.getElementById('add-format').addEventListener('click', () => addFormatRow(''));

function getSelectedFormats() {
    const result = [];
    formatsList.querySelectorAll('.multi-row').forEach(row => {
        const select = row.querySelector('.fmt-select');
        if (select.value === 'custom') {
            const h = parseFloat(row.querySelector('.fmt-h').value) || 0;
            const w = parseFloat(row.querySelector('.fmt-w').value) || 0;
            if (h > 0 && w > 0) result.push({height: h, width: w, label: `${h}×${w} мм (свой)`});
        } else if (select.value) {
            const opt = select.selectedOptions[0];
            const h = parseFloat(opt.dataset.h) || 0;
            const w = parseFloat(opt.dataset.w) || 0;
            if (h > 0 && w > 0) result.push({height: h, width: w, label: opt.textContent.trim()});
        }
    });
    return result;
}

function onManufacturersChanged() {
    formatsList.querySelectorAll('.multi-row').forEach(row => {
        const select = row.querySelector('.fmt-select');
        const current = select.value;
        select.innerHTML = formatOptionsHtml(current);
    });
    filterDecors();
}

/* ═══════════ ДЕКОР И ТОЛЩИНА ═══════════ */
const decorSelect = document.getElementById('decor_select');
const thicknessSelect = document.getElementById('thickness_select');
const sheetCostInput = document.getElementById('sheet_cost');
const sheetCurrencyInput = document.getElementById('sheet_currency');

function filterDecors() {
    const mfrIds = selectedManufacturerIds();
    const current = decorSelect.value;
    decorSelect.innerHTML = '<option value="">— Выберите декор —</option>';
    PANELS.forEach(p => {
        if (mfrIds.length && p.manufacturer_id && !mfrIds.includes(String(p.manufacturer_id))) return;
        const label = [p.decor_number, p.decor_name].filter(Boolean).join(' ') || p.name;
        const opt = document.createElement('option');
        opt.value = p.id;
        opt.textContent = label + (p.manufacturer_name ? ` — ${p.manufacturer_name}` : '');
        opt.dataset.price = p.price_per_sheet || p.cost_per_sheet || 0;
        opt.dataset.currency = p.currency || 'RUB';
        if (String(p.id) === current) opt.selected = true;
        decorSelect.appendChild(opt);
    });
}

function fillThicknesses() {
    THICKNESSES.forEach(t => {
        const opt = document.createElement('option');
        opt.value = t.thickness;
        opt.textContent = `${parseFloat(t.thickness)} мм`;
        thicknessSelect.appendChild(opt);
    });
}

decorSelect.addEventListener('change', () => {
    const opt = decorSelect.selectedOptions[0];
    if (opt && opt.dataset.price) {
        sheetCostInput.value = opt.dataset.price;
        sheetCurrencyInput.value = opt.dataset.currency || 'RUB';
    }
});

/* ═══════════ Утилиты ═══════════ */
function escapeHtml(str) {
    return String(str ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}
function fmtNum(v, d=2) { return new Intl.NumberFormat('ru-RU', {minimumFractionDigits:0, maximumFractionDigits:d}).format(v||0); }

/* ═══════════ СПИСОК ДЕТАЛЕЙ ═══════════ */
const partsTbody = document.getElementById('parts-tbody');
const partModal = document.getElementById('part-modal');
const pmTitle = document.getElementById('part-modal-title');
const pmName = document.getElementById('pm-name');
const pmLength = document.getElementById('pm-length');
const pmWidth = document.getElementById('pm-width');
const pmQty = document.getElementById('pm-qty');
let editingPartId = null;

function renderParts() {
    partsTbody.innerHTML = '';
    const unplacedIds = lastResult && lastResult.unplacedPartIds ? lastResult.unplacedPartIds : [];
    const unplaced = new Set(Array.isArray(unplacedIds) ? unplacedIds : Object.values(unplacedIds));
    parts.forEach((p, idx) => {
        const tr = document.createElement('tr');
        if (unplaced.has(p.id)) tr.style.background = '#fef2f2';
        tr.innerHTML = `
            <td>${idx + 1}</td>
            <td><input type="text" class="inline-edit" data-id="${p.id}" data-field="name" value="${escapeHtml(p.name)}" style="width:100%;box-sizing:border-box;border:1px solid transparent;border-radius:4px;padding:4px 6px;font-size:13px;background:transparent;${unplaced.has(p.id) ? 'color:#dc2626;font-weight:700' : ''}"></td>
            <td><input type="number" class="inline-edit" data-id="${p.id}" data-field="length" value="${p.length}" min="1" style="width:100%;box-sizing:border-box;border:1px solid transparent;border-radius:4px;padding:4px 6px;font-size:13px;background:transparent;text-align:right"></td>
            <td><input type="number" class="inline-edit" data-id="${p.id}" data-field="width" value="${p.width}" min="1" style="width:100%;box-sizing:border-box;border:1px solid transparent;border-radius:4px;padding:4px 6px;font-size:13px;background:transparent;text-align:right"></td>
            <td><input type="number" class="inline-edit" data-id="${p.id}" data-field="qty" value="${p.qty}" min="1" style="width:100%;box-sizing:border-box;border:1px solid transparent;border-radius:4px;padding:4px 6px;font-size:13px;background:transparent;text-align:right"></td>
            <td><button type="button" class="toggle-rotate" data-id="${p.id}" style="border:0;border-radius:6px;padding:4px 12px;cursor:pointer;font-weight:700;font-size:12px;background:${p.rotate ? '#dcfce7' : '#fee2e2'};color:${p.rotate ? '#166534' : '#991b1b'}">${p.rotate ? 'Да' : 'Нет'}</button></td>
            <td>
                <button type="button" class="danger" style="padding:5px 10px;font-size:12px" onclick="deletePart(${p.id})">🗑</button>
            </td>
        `;
        partsTbody.appendChild(tr);
    });
}

partsTbody.addEventListener('click', e => {
    const btn = e.target.closest('.toggle-rotate');
    if (!btn) return;
    const p = parts.find(x => x.id === Number(btn.dataset.id));
    if (p) { p.rotate = !p.rotate; renderParts(); }
});

partsTbody.addEventListener('focus', e => {
    if (e.target.classList.contains('inline-edit')) e.target.style.borderColor = '#93c5fd';
}, true);

partsTbody.addEventListener('blur', e => {
    if (!e.target.classList.contains('inline-edit')) return;
    e.target.style.borderColor = 'transparent';
    const p = parts.find(x => x.id === Number(e.target.dataset.id));
    if (!p) return;
    const field = e.target.dataset.field;
    if (field === 'name') {
        p[field] = e.target.value.trim() || p.name;
    } else {
        const val = parseFloat(e.target.value);
        if (val > 0) p[field] = val;
    }
}, true);

partsTbody.addEventListener('keydown', e => {
    if (e.key === 'Enter' && e.target.classList.contains('inline-edit')) e.target.blur();
});

const pmRotate = document.getElementById('pm-rotate');
let pmRotateValue = true;

function updatePmRotateVisual() {
    pmRotate.dataset.value = pmRotateValue ? '1' : '0';
    pmRotate.textContent = pmRotateValue ? 'Да' : 'Нет';
    pmRotate.style.background = pmRotateValue ? '#dcfce7' : '#fee2e2';
    pmRotate.style.color = pmRotateValue ? '#166534' : '#991b1b';
}

pmRotate.addEventListener('click', () => { pmRotateValue = !pmRotateValue; updatePmRotateVisual(); });

function openPartModal(part) {
    editingPartId = part ? part.id : null;
    pmTitle.textContent = part ? 'Изменить деталь' : 'Добавить деталь';
    pmName.value = part ? part.name : '';
    pmLength.value = part ? part.length : '';
    pmWidth.value = part ? part.width : '';
    pmQty.value = part ? part.qty : 1;
    pmRotateValue = part ? part.rotate : true;
    updatePmRotateVisual();
    partModal.classList.remove('hidden');
    pmName.focus();
}
function closePartModal() { partModal.classList.add('hidden'); editingPartId = null; }

document.getElementById('add-part-btn').addEventListener('click', () => openPartModal(null));
document.getElementById('pm-cancel').addEventListener('click', closePartModal);
document.getElementById('pm-save').addEventListener('click', () => {
    const name = pmName.value.trim() || ('№' + (parts.length + (editingPartId ? 0 : 1)));
    const length = parseFloat(pmLength.value);
    const width = parseFloat(pmWidth.value);
    const qty = parseInt(pmQty.value) || 1;
    const rotate = pmRotateValue;
    if (!(length > 0) || !(width > 0)) { alert('Укажите корректные размеры детали.'); return; }

    if (editingPartId !== null) {
        const p = parts.find(x => x.id === editingPartId);
        if (p) { p.name=name; p.length=length; p.width=width; p.qty=qty; p.rotate=rotate; }
    } else {
        parts.push({id: nextPartId++, name, length, width, qty, rotate});
    }
    renderParts();
    closePartModal();
});

window.editPart = function(id) {
    const p = parts.find(x => x.id === id);
    if (p) openPartModal(p);
};
window.deletePart = function(id) {
    if (!confirm('Удалить деталь?')) return;
    parts = parts.filter(x => x.id !== id);
    renderParts();
};

/* ═══════════════════════════════════════════════════
   АЛГОРИТМ РАСКРОЯ (гильотинный bin-packing)
   ═══════════════════════════════════════════════════ */
function packSheets(pieces, sheetW, sheetH, kerf, method) {
    let queue = pieces.slice().sort((a, b) => (b.w * b.h) - (a.w * a.h));
    const sheets = [];

    while (queue.length > 0) {
        let freeRects = [{x: 0, y: 0, w: sheetW, h: sheetH}];
        const placed = [];
        let placedAny = true;

        while (placedAny) {
            placedAny = false;
            let best = null;

                for (let pi = 0; pi < queue.length; pi++) {
                    const piece = queue[pi];
                    let orientations;
                    if (method === 'optimal') {
                        orientations = [{w: piece.w, h: piece.h, rotated: false}];
                        if (piece.canRotate && piece.w !== piece.h) {
                            orientations.push({w: piece.h, h: piece.w, rotated: true});
                        }
                    } else if (method === 'length') {
                        if (piece.w > piece.h) {
                            orientations = [{w: piece.h, h: piece.w, rotated: true}];
                        } else {
                            orientations = [{w: piece.w, h: piece.h, rotated: false}];
                        }
                    } else {
                        if (piece.h > piece.w) {
                            orientations = [{w: piece.h, h: piece.w, rotated: true}];
                        } else {
                            orientations = [{w: piece.w, h: piece.h, rotated: false}];
                        }
                    }
                for (const orient of orientations) {
                    for (let ri = 0; ri < freeRects.length; ri++) {
                        const rect = freeRects[ri];
                        if (orient.w <= rect.w && orient.h <= rect.h) {
                            const leftoverArea = (rect.w * rect.h) - (orient.w * orient.h);
                            if (!best || leftoverArea < best.score) {
                                best = {pieceIdx: pi, rectIdx: ri, rotated: orient.rotated, w: orient.w, h: orient.h, score: leftoverArea};
                            }
                        }
                    }
                }
            }

            if (best) {
                const piece = queue[best.pieceIdx];
                const rect = freeRects[best.rectIdx];
                const pw = best.w, ph = best.h;

                placed.push({id: piece.id, name: piece.name, x: rect.x, y: rect.y, w: pw, h: ph, rotated: best.rotated});

                freeRects.splice(best.rectIdx, 1);
                const rightW = rect.w - pw - (rect.w - pw > 0 ? kerf : 0);
                const bottomH = rect.h - ph - (rect.h - ph > 0 ? kerf : 0);
                if (rightW > 0) freeRects.push({x: rect.x + pw + kerf, y: rect.y, w: rightW, h: ph});
                if (bottomH > 0) freeRects.push({x: rect.x, y: rect.y + ph + kerf, w: rect.w, h: bottomH});

                piece.qtyLeft -= 1;
                if (piece.qtyLeft <= 0) queue.splice(best.pieceIdx, 1);
                placedAny = true;
            }
        }

        if (placed.length === 0) break;
        sheets.push({placed, freeRects});
    }
    return {sheets, remaining: queue};
}

function runCutting() {
    if (parts.length === 0) { alert('Добавьте хотя бы одну деталь.'); return; }
    const formats = getSelectedFormats();
    if (formats.length === 0) { alert('Выберите хотя бы один формат панели.'); return; }

    const kerf = parseFloat(document.getElementById('kerf').value) || 0;
    const margin = parseFloat(document.getElementById('margin').value) || 0;
    const method = document.getElementById('method').value;
    const sheetCost = parseFloat(sheetCostInput.value) || 0;
    const currency = sheetCurrencyInput.value || 'RUB';

    const validFormats = formats.filter(f => {
        const sw = f.width - margin * 2, sh = f.height - margin * 2;
        return sw > 0 && sh > 0;
    });
    if (validFormats.length === 0) { alert('Размер листа за вычетом отступов должен быть положительным.'); return; }

    const sortedFormats = [...validFormats].sort((a, b) => (a.width * a.height) - (b.width * b.height));

    function pieceFitsOnFormat(pw, ph, sw, sh, method) {
        if (method === 'optimal') {
            return (pw <= sw && ph <= sh) || (pw <= sh && ph <= sw);
        } else if (method === 'length') {
            return Math.min(pw, ph) <= sw && Math.max(pw, ph) <= sh;
        } else {
            return Math.max(pw, ph) <= sw && Math.min(pw, ph) <= sh;
        }
    }

    const formatGroups = new Map();
    for (const p of parts) {
        let assignedFmt = null;
        for (const fmt of sortedFormats) {
            const sw = fmt.width - margin * 2;
            const sh = fmt.height - margin * 2;
            if (pieceFitsOnFormat(p.length, p.width, sw, sh, method)) {
                assignedFmt = fmt;
                break;
            }
        }
        if (!assignedFmt) assignedFmt = sortedFormats[sortedFormats.length - 1];
        if (!formatGroups.has(assignedFmt)) formatGroups.set(assignedFmt, []);
        formatGroups.get(assignedFmt).push(p);
    }

    let allSheets = [];
    let allRemaining = [];

    for (const [fmt, fmtParts] of formatGroups) {
        const sheetW = fmt.width - margin * 2;
        const sheetH = fmt.height - margin * 2;
        const testPieces = fmtParts.map(p => ({id: p.id, name: p.name, w: p.length, h: p.width, canRotate: p.rotate, qtyLeft: p.qty}));
        const {sheets: trySheets, remaining: tryRemaining} = packSheets(testPieces, sheetW, sheetH, kerf, method);
        for (const s of trySheets) allSheets.push({format: fmt, ...s});
        allRemaining.push(...tryRemaining.filter(p => p.qtyLeft > 0));
    }

    if (allSheets.length === 0) { alert('Не удалось разместить ни одну деталь.'); return; }

    const placedPartIds = new Set();
    allSheets.forEach(s => s.placed.forEach(pl => {
        for (let i = 0; i < (pl.qty || 1); i++) placedPartIds.add(pl.id);
    }));
    const unplacedPartIds = new Set();
    parts.forEach(p => {
        if (!placedPartIds.has(p.id)) unplacedPartIds.add(p.id);
    });

    if (allRemaining.length > 0) {
        alert('Внимание: некоторые детали не уместились (слишком большие): ' + allRemaining.map(r => r.name).join(', '));
    }

    const fmtLabel = [...new Set(allSheets.map(s => s.format.label))].join(' + ');
    let totalSheetAreaM2 = 0;
    allSheets.forEach(s => totalSheetAreaM2 += (s.format.width * s.format.height) / 1e6);
    const sheetCount = allSheets.length;

    let totalPartsArea = 0, totalCutLength = 0, totalPartsCount = 0;
    allSheets.forEach(s => s.placed.forEach(pl => {
        totalPartsArea += (pl.w * pl.h) / 1e6;
        totalPartsCount += 1;
        totalCutLength += (pl.w + pl.h) * 2 / 1000;
    }));

    const wasteArea = totalSheetAreaM2 - totalPartsArea;
    const sheetsCost = sheetCost * sheetCount;
    const partsCost = totalSheetAreaM2 > 0 ? sheetsCost * (totalPartsArea / totalSheetAreaM2) : 0;
    const wasteCost = sheetsCost - partsCost;

    lastResult = {fmtLabel, formats: validFormats, kerf, margin, method, sheetCost, currency,
        sheets: allSheets, sheetAreaM2: totalSheetAreaM2 / sheetCount, sheetCount,
        totalSheetsArea: totalSheetAreaM2, totalPartsArea, totalCutLength, totalPartsCount,
        wasteArea, sheetsCost, partsCost, wasteCost, unplacedPartIds: [...unplacedPartIds]};

    renderResult();
    renderParts();
}
document.getElementById('cut-btn').addEventListener('click', runCutting);

/* ═══════════ ОТОБРАЖЕНИЕ РЕЗУЛЬТАТА ═══════════ */
const resultSection = document.getElementById('result-section');
const summaryTable = document.getElementById('summary-table');
const sheetsContainer = document.getElementById('sheets-container');
const PALETTE = ['#fde68a','#bbf7d0','#bfdbfe','#fbcfe8','#fed7aa','#c7d2fe','#a7f3d0','#fecaca','#ddd6fe','#fef08a'];
function colorForId(id) { return PALETTE[id % PALETTE.length]; }

function renderResult() {
    if (!lastResult) return;
    const r = lastResult;
    resultSection.classList.remove('hidden');

    const fmtSummary = r.fmtLabel || (r.formats ? r.formats.map(f => f.label).join(' + ') : '');
    const fmtCounts = {};
    r.sheets.forEach(s => {
        const lbl = (s.format || r.formats[0]).label || '?';
        fmtCounts[lbl] = (fmtCounts[lbl] || 0) + 1;
    });
    const fmtBreakdown = Object.entries(fmtCounts).map(([lbl, cnt]) => `${lbl} — ${cnt} лист.`).join(', ');
    const unplacedHtml = r.unplacedPartIds && r.unplacedPartIds.length > 0
        ? `<tr><th style="color:#dc2626">Не размещены</th><td style="color:#dc2626;font-weight:700">${r.unplacedPartIds.length} дет.</td></tr>`
        : '';
    summaryTable.innerHTML = `
        <tr><th>Формат(ы) листов</th><td>${escapeHtml(fmtBreakdown)}</td></tr>
        <tr><th>Количество листов</th><td>${r.sheetCount}</td></tr>
        <tr><th>Площадь листов</th><td>${fmtNum(r.totalSheetsArea,3)} м²</td></tr>
        <tr><th>Длина резов</th><td>${fmtNum(r.totalCutLength,2)} м</td></tr>
        <tr><th>Количество деталей</th><td>${r.totalPartsCount}</td></tr>
        <tr><th>Площадь деталей</th><td>${fmtNum(r.totalPartsArea,3)} м²</td></tr>
        <tr><th>Площадь отходов</th><td>${fmtNum(r.wasteArea,3)} м²</td></tr>
        <tr><th>Стоимость листов</th><td>${fmtNum(r.sheetsCost,2)} ${escapeHtml(r.currency)}</td></tr>
        <tr><th>Стоимость деталей</th><td>${fmtNum(r.partsCost,2)} ${escapeHtml(r.currency)}</td></tr>
        <tr><th>Стоимость отходов</th><td>${fmtNum(r.wasteCost,2)} ${escapeHtml(r.currency)}</td></tr>
        ${unplacedHtml}
    `;

    sheetsContainer.innerHTML = '';

    r.sheets.forEach((sheet, idx) => {
        const sf = sheet.format || r.formats[0];
        const sheetAreaM2 = (sf.width * sf.height) / 1e6;
        const scale = Math.min(1100 / sf.height, 500 / sf.width);
        const svgW = sf.height * scale;
        const svgH = sf.width * scale;
        let rectsHtml = '';
        let placedArea = 0;
        sheet.placed.forEach(pl => {
            placedArea += (pl.w * pl.h) / 1e6;
            const x = pl.y * scale, y = pl.x * scale, w = pl.h * scale, h = pl.w * scale;
            const arrLen = Math.min(w, h) * 0.5;
            const arrY = y + h / 2;
            const arrX1 = x + (w - arrLen) / 2;
            const arrX2 = arrX1 + arrLen;
            const arrowHtml = arrLen > 14 ? `<line x1="${arrX1}" y1="${arrY}" x2="${arrX2 - 6}" y2="${arrY}" stroke="#1e40af" stroke-width="2" stroke-linecap="round"/>
                <polygon points="${arrX2},${arrY} ${arrX2 - 7},${arrY - 4} ${arrX2 - 7},${arrY + 4}" fill="#1e40af"/>` : '';
            rectsHtml += `<g class="part-rect">
                <rect x="${x}" y="${y}" width="${w}" height="${h}" fill="${colorForId(pl.id)}" stroke="#374151" stroke-width="1"/>
                ${arrowHtml}
                <text x="${x + w/2}" y="${y + h/2 - 8}" text-anchor="middle">${fmtNum(pl.h,0)}</text>
                <text x="${x + w/2}" y="${y + h/2 + 14}" text-anchor="middle" font-size="11">${fmtNum(pl.w,0)}</text>
                <text x="${x + w - 6}" y="${y + h - 6}" text-anchor="end" font-size="10" fill="#6b7280">#${pl.id}</text>
            </g>`;
        });

        const usagePercent = sheetAreaM2 > 0 ? (placedArea / sheetAreaM2 * 100) : 0;
        const block = document.createElement('div');
        block.innerHTML = `
            <div class="sheets-header">Лист ${idx + 1}${sf.label ? ' — ' + escapeHtml(sf.label) : ''}</div>
            <div class="sheet-block">
                <div class="sheet-canvas-wrap">
                    <svg width="${svgW}" height="${svgH}" viewBox="0 0 ${svgW} ${svgH}" style="background:#e5e7eb;border:1px solid #9ca3af;">
                        <defs><marker id="ga${idx}" markerWidth="8" markerHeight="6" refX="7" refY="3" orient="auto"><polygon points="0,0 8,3 0,6" fill="#1e40af"/></marker></defs>
                        <line x1="8" y1="${svgH - 10}" x2="${svgW - 20}" y2="${svgH - 10}" stroke="#1e40af" stroke-width="2" marker-end="url(#ga${idx})"/>
                        <text x="${svgW / 2}" y="${svgH - 16}" text-anchor="middle" font-size="11" fill="#1e40af" font-weight="600">направление рисунка</text>
                        ${rectsHtml}
                    </svg>
                </div>
                <div class="sheet-info">
                    <span><b>№ ${idx + 1}</b></span>
                    <span>Размер: <b>${fmtNum(sf.height,0)}×${fmtNum(sf.width,0)} мм</b></span>
                    <span>Площадь листа: <b>${fmtNum(sheetAreaM2,3)} м²</b></span>
                    <span>Площадь деталей: <b>${fmtNum(placedArea,3)} м²</b></span>
                    <span>Использование: <b>${fmtNum(usagePercent,1)}%</b></span>
                    <span>Отход: <b>${fmtNum(100-usagePercent,1)}%</b></span>
                </div>
            </div>
        `;
        sheetsContainer.appendChild(block);
    });
}

/* ═══════════ СОХРАНЕНИЕ / ЗАГРУЗКА ═══════════ */
document.getElementById('save-btn').addEventListener('click', async () => {
    const settings = {
        loaded_id: loadedId,
        cutting_name: document.getElementById('cutting_name').value,
        object_name: document.getElementById('object_name').value,
        manufacturer_ids: selectedManufacturerIds(),
        formats: getSelectedFormats(),
        decor_id: decorSelect.value,
        thickness: thicknessSelect.value,
        kerf: document.getElementById('kerf').value,
        margin: document.getElementById('margin').value,
        method: document.getElementById('method').value,
        sheet_cost: sheetCostInput.value,
        sheet_currency: sheetCurrencyInput.value,
    };
    try {
        const resp = await fetch('calculator_cutting.php?action=save', {
            method: 'POST', headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({settings, parts, result: lastResult})
        });
        const data = await resp.json();
        if (data.ok) { alert('Раскрой сохранён.'); location.reload(); }
        else alert('Ошибка сохранения: ' + (data.error || 'неизвестная ошибка'));
    } catch (e) { alert('Ошибка сохранения: ' + e.message); }
});

window.deleteLayout = async function(id) {
    if (!confirm('Удалить раскрой?')) return;
    try {
        const fd = new FormData();
        fd.append('id', id);
        const resp = await fetch('calculator_cutting.php?action=delete_layout', { method: 'POST', body: fd });
        const data = await resp.json();
        if (data.ok) location.reload();
    } catch (e) {}
};

window.loadLayout = async function(id) {
    try {
        loadedId = id;
        const resp = await fetch('calculator_cutting.php?action=load&id=' + id);
        const data = await resp.json();
        if (!data.ok) { alert('Не удалось загрузить раскрой.'); return; }

        const s = data.settings || {};
        document.getElementById('cutting_name').value = data.cutting_name || s.cutting_name || '';
        document.getElementById('object_name').value = s.object_name || '';
        document.getElementById('kerf').value = s.kerf ?? 4;
        document.getElementById('margin').value = s.margin ?? 5;
        document.getElementById('method').value = s.method || 'optimal';
        sheetCostInput.value = s.sheet_cost || 0;
        sheetCurrencyInput.value = s.sheet_currency || 'RUB';

        manufacturersList.innerHTML = '';
        const mfrIds = s.manufacturer_ids && s.manufacturer_ids.length ? s.manufacturer_ids : [''];
        mfrIds.forEach(mid => addManufacturerRow(mid));

        formatsList.innerHTML = '';
        if (s.formats && s.formats.length) {
            s.formats.forEach(f => {
                const matched = PANEL_SIZES.find(sz => Number(sz.height_mm)===Number(f.height) && Number(sz.width_mm)===Number(f.width));
                if (matched) addFormatRow(`db:${matched.id}`);
                else addFormatRow('custom', f.height, f.width);
            });
        } else {
            addFormatRow('');
        }

        filterDecors();
        if (s.decor_id) decorSelect.value = s.decor_id;

        parts = (data.parts || []).map(p => ({...p, id: nextPartId++}));
        renderParts();

        if (data.result) { lastResult = data.result; renderResult(); renderParts(); }

        const badge = document.getElementById('version-badge');
        if (data.version_number) {
            badge.textContent = 'v' + data.version_number;
            badge.style.display = 'inline';
        } else {
            badge.style.display = 'none';
        }
    } catch (e) { alert('Ошибка загрузки: ' + e.message); }
};

/* ═══════════ ЭКСПОРТ ═══════════ */
document.getElementById('export-excel-btn').addEventListener('click', () => {
    if (!lastResult) { alert('Сначала выполните раскрой.'); return; }
    exportExcel(lastResult);
});
document.getElementById('export-pdf-btn').addEventListener('click', () => {
    if (!lastResult) { alert('Сначала выполните раскрой.'); return; }
    window.print();
});

function exportExcel(r) {
    let csv = 'Показатель;Значение\n';
    csv += `Формат(ы);${(r.fmtLabel || '').replace(/;/g,',')}\n`;
    csv += `Количество листов;${r.sheetCount}\n`;
    csv += `Площадь листов;${fmtNum(r.totalSheetsArea,3)} м2\n`;
    csv += `Длина резов;${fmtNum(r.totalCutLength,2)} м\n`;
    csv += `Количество деталей;${r.totalPartsCount}\n`;
    csv += `Площадь деталей;${fmtNum(r.totalPartsArea,3)} м2\n`;
    csv += `Площадь отходов;${fmtNum(r.wasteArea,3)} м2\n`;
    csv += `Стоимость листов;${fmtNum(r.sheetsCost,2)} ${r.currency}\n`;
    csv += `Стоимость деталей;${fmtNum(r.partsCost,2)} ${r.currency}\n`;
    csv += `Стоимость отходов;${fmtNum(r.wasteCost,2)} ${r.currency}\n\n`;
    csv += 'Лист;Формат;Деталь;X;Y;Длина;Ширина;Поворот\n';
    r.sheets.forEach((sheet, idx) => {
        const sf = sheet.format || (r.formats && r.formats[0]) || {};
        sheet.placed.forEach(pl => {
            csv += `${idx+1};${(sf.label||'').replace(/;/g,',')};${pl.name.replace(/;/g,',')};${fmtNum(pl.x,0)};${fmtNum(pl.y,0)};${fmtNum(pl.w,0)};${fmtNum(pl.h,0)};${pl.rotated?'Да':'Нет'}\n`;
        });
    });
    const blob = new Blob(['\uFEFF' + csv], {type: 'text/csv;charset=utf-8;'});
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = 'raskroy.csv';
    link.click();
}


addManufacturerRow('');
addFormatRow('');
fillThicknesses();
filterDecors();

/* ═══════════ ПОИСК И СОРТИРОВКА ИСТОРИИ ═══════════ */
let historySortCol = null;
let historySortDir = 'asc';

function applyHistorySort() {
    const tbody = document.getElementById('history-tbody');
    const search = (document.getElementById('history-search').value || '').toLowerCase();
    const rows = Array.from(tbody.querySelectorAll('tr[data-cutting]'));

    rows.forEach(r => {
        const text = (r.dataset.cutting + ' ' + r.dataset.object).toLowerCase();
        r.style.display = search && !text.includes(search) ? 'none' : '';
    });

    if (historySortCol) {
        const visible = rows.filter(r => r.style.display !== 'none');
        const hidden = rows.filter(r => r.style.display === 'none');
        visible.sort((a, b) => {
            const key = historySortCol === 'date' ? 'date' : historySortCol;
            const va = a.dataset[key] || '';
            const vb = b.dataset[key] || '';
            const cmp = key === 'date' ? va.localeCompare(vb) : va.localeCompare(vb, 'ru');
            return historySortDir === 'asc' ? cmp : -cmp;
        });
        visible.forEach(r => tbody.appendChild(r));
        hidden.forEach(r => tbody.appendChild(r));
    }

    document.querySelectorAll('.sort-arrow').forEach(s => s.textContent = '');
    if (historySortCol) {
        const arrow = document.getElementById('sort-' + historySortCol);
        if (arrow) arrow.textContent = historySortDir === 'asc' ? '▲' : '▼';
    }
}

document.getElementById('history-search').addEventListener('input', applyHistorySort);

document.querySelectorAll('.history-table th.sortable').forEach(th => {
    th.addEventListener('click', () => {
        const col = th.dataset.col;
        if (historySortCol === col) {
            historySortDir = historySortDir === 'asc' ? 'desc' : 'asc';
        } else {
            historySortCol = col;
            historySortDir = 'asc';
        }
        applyHistorySort();
    });
});
</script>
