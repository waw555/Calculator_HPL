<?php
require_once __DIR__ . '/includes/admin_auth.php';
require_once __DIR__ . '/includes/app_header.php';
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
$panels = $pdo->query("SELECT pf.*, m.full_name AS manufacturer_name FROM panel_formats pf LEFT JOIN manufacturers m ON m.id=pf.manufacturer_id WHERE pf.is_active=1 ORDER BY m.full_name, pf.decor_number, pf.decor_name")->fetchAll();

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
body { font-family: 'Inter', Arial, sans-serif; background: #f6f8fb; margin: 0; color: #0f172a; }
.container { max-width: 1440px; margin: 20px auto 40px; padding: 0 10px; }
.top-grid{display:grid;grid-template-columns:minmax(0,2.05fr) minmax(320px,1fr);gap:24px;align-items:stretch;margin-bottom:24px}.top-grid>.panel{margin-bottom:0}
.panel { background: #fff; border: 1px solid #dfe6ef; border-radius: 16px; padding: 28px; margin-bottom: 24px; box-shadow: 0 2px 5px rgba(15,23,42,.07); }
.section-title { font-size: 14px; font-weight: 800; color: #0f172a; padding: 0 0 13px; border-bottom:1px solid #e8edf4; margin: 24px 0 16px; }
.section-title:before{content:'✦';color:#ed174c;margin-right:9px}.section-title.main { font-size: 17px; margin-top: 0; background:none; border-left:0; }
.section-title.main:before{content:'⚙';font-size:18px}
.settings-title{display:flex;align-items:center;justify-content:space-between;gap:20px}.settings-title__note{color:#91a0bd;font-size:11px;font-weight:600}.cutting-meta{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin:20px 0 24px}.cutting-meta label{color:#344258}.cutting-meta input{background:#f8fafc}.strategy-heading{margin:0 0 8px;font-size:11px;font-weight:850;text-transform:uppercase}.strategy-heading span{color:#ed174c}
.cost-card{display:flex;flex-direction:column;padding:28px 26px;background:#111a2d;color:#fff;border:0!important}.cost-card__eyebrow{color:#ff4f78;font-size:12px;font-weight:900;text-transform:uppercase}.cost-card__total{font-size:30px;font-weight:900;margin:7px 0 0}.cost-card__section+.cost-card__section{margin-top:22px;padding-top:20px;border-top:1px solid #263148}.cost-card__stats{display:grid;gap:10px;margin-top:14px}.cost-card__row{display:flex;justify-content:space-between;gap:15px;font-size:12px}.cost-card__row b{color:#fff;text-align:right}.cost-card__section:first-child .cost-card__row b,.cost-card__section:nth-child(2) .cost-card__total{color:#ff4f78}.cost-card button{width:100%;margin-top:auto;background:#e9164d}
.strategy-options{display:grid;grid-template-columns:repeat(3,1fr);gap:12px}.strategy-card{position:relative;display:block;margin:0;padding:14px;border:1px solid #dbe4ef;border-radius:12px;background:#f8fafc;cursor:pointer;min-height:86px;box-sizing:border-box}.strategy-card:has(input:checked){border-color:#ff315f;background:#fff6f8;box-shadow:0 0 0 2px rgba(233,22,77,.1)}.strategy-card input{position:absolute;opacity:0;pointer-events:none}.strategy-card strong{display:block;color:#172033;font-size:13px}.strategy-card strong i{color:#ed174c;font-size:17px;font-style:normal;margin-right:7px}.strategy-card span{display:block;color:#71809a;font-size:11px;font-weight:500;line-height:1.4;margin-top:6px}.strategy-card:has(input:checked) strong{color:#78001c}.strategy-check{display:none;position:absolute;right:14px;top:16px;color:#ed174c;font-size:15px}.strategy-card:has(input:checked) .strategy-check{display:block}.cut-settings{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-top:28px}.cut-setting{padding:14px;background:#f8fafc;border:1px solid #dfe6ef;border-radius:12px}.cut-setting label{margin-bottom:4px}.input-unit{display:flex;align-items:center;gap:9px}.input-unit input{background:#fff}.input-unit b{width:20px;color:#586a87;font-size:11px}.cut-setting .hint{color:#91a0bd;font-size:11px;margin-top:5px}
.grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 14px; }
.grid-3 { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 14px; }
label { display: block; font-weight: 600; margin-bottom: 6px; font-size: 13px; }
input, select { width: 100%; box-sizing: border-box; padding: 9px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 14px; }
input[readonly] { background: #f8fafc; }
button, .button { border: 0; border-radius: 9px; padding: 10px 16px; background: #4f46e5; color: #fff; text-decoration: none; cursor: pointer; display: inline-block; font-weight: 700; font-size: 13px; }
.button.secondary, button.secondary { background: #64748b; }
.button.success, button.success { background: #e9164d; }
.button.danger, button.danger { background: #e9164d; }
.hint { color: #64748b; font-size: 13px; margin-top: 4px; }

.multi-row { display: flex; gap: 8px; align-items: flex-end; margin-bottom: 8px; }
.multi-row select, .multi-row input { flex: 1; }
.multi-row .rm-btn { flex: none; width: 38px; height: 38px; border-radius: 8px; border: none; background: #fef2f2; color: #dc2626; cursor: pointer; font-size: 16px; display:flex; align-items:center; justify-content:center; }
.multi-row .rm-btn:hover { background: #dc2626; color: #fff; }
.add-row-btn { background: #eff6ff; color: #2563eb; border: 1px dashed #93c5fd; border-radius: 8px; padding: 8px 14px; cursor: pointer; font-size: 13px; font-weight: 600; }
.add-row-btn:hover { background: #dbeafe; }

table.parts-table { width:100%; border-collapse:collapse; background:#fff; table-layout:fixed; }
table.parts-table th, table.parts-table td { height:54px; box-sizing:border-box; padding:9px 12px; text-align:left; vertical-align:middle; border-bottom:1px solid #e7edf4; font-size:12px; }
table.parts-table th { height:43px; background:#f0f4f8; color:#07152d; font-size:11px; font-weight:800; text-transform:uppercase; }
table.parts-table th.rotate-column, table.parts-table td.rotate-column { text-align:center; }
table.parts-table input[type="text"], table.parts-table input[type="number"] { height:30px; padding:6px 10px; border-color:#c8d5e5; border-radius:9px; font-size:12px; text-align:center; background:#fff; }
table.parts-table input[data-field="name"] { text-align:left; }
.part-index{color:#8b9ab4;font-weight:700}.part-name-cell{display:flex;align-items:center;gap:9px}.part-color{flex:0 0 12px;width:12px;height:12px;border-radius:50%}.part-area{white-space:nowrap;color:#183052}.rotate-label{display:inline-flex;align-items:center;gap:7px;margin:0;font-size:11px}.rotate-label input{width:16px;height:16px;margin:0;accent-color:#1681f8}.delete-part{padding:5px;background:transparent;color:#9bacbf;font-size:17px}.delete-part:hover{color:#e9164d}
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

.sheets-header { background: #eef3f9; color: #0f172a; padding: 12px 16px; border-radius: 10px 10px 0 0; font-weight: 800; margin-top: 24px; border:1px solid #dbe4ef; }
.sheet-block { border: 1px solid #d1d5db; border-radius: 0 0 10px 10px; margin-bottom: 24px; overflow: hidden; }
.sheet-canvas-wrap { background: #f3f4f6; padding: 16px; overflow-x: auto; }
.sheet-info { padding: 10px 16px; background: #fff; font-size: 13px; color: #374151; display:flex; gap:18px; flex-wrap:wrap; border-top:1px solid #e5e7eb; }
.sheet-info b { color: #1f2937; }
.sheet-parts { width:100%; border-collapse:collapse; background:#fff; }
.sheet-parts th, .sheet-parts td { padding:7px 10px; border-top:1px solid #e5e7eb; text-align:left; font-size:12px; }
.sheet-parts th { color:#475569; background:#f8fafc; }
.part-number { display:inline-flex; align-items:center; justify-content:center; min-width:22px; height:22px; padding:0 4px; border-radius:5px; color:#1e293b; font-weight:800; }
.part-rect text { font-size: 12px; fill: #1e3a8a; font-weight: 600; pointer-events:none; }
.part-rect .part-dimension { font-size: 10px; fill:#334155; }
.waste-rect { fill: repeating-linear-gradient(45deg, #fecaca, #fecaca 4px, #fff 4px, #fff 8px); }

.modal-overlay { position: fixed; inset:0; background: rgba(0,0,0,0.4); display:flex; align-items:center; justify-content:center; z-index: 1000; }
.modal-box { background:#fff; border-radius:12px; padding:24px; width: 420px; max-width: 92vw; box-shadow: 0 12px 40px rgba(0,0,0,.2); }
.modal-box h3 { margin-top:0; }
.hidden { display:none !important; }
.material-mode{display:flex;gap:20px;flex-wrap:wrap;margin-bottom:14px;padding:12px;background:#f8fafc;border-radius:10px}.material-mode label{margin:0}.material-mode input{width:auto;margin-right:6px}#panel_select{margin-top:8px;min-height:150px}.part-entry-row td{background:#eff6ff;border-top:2px solid #93c5fd!important}
.materials-list{display:grid;gap:8px;margin-top:14px}.material-item{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:10px 12px;background:#f8fafc;border:1px solid #dbeafe;border-radius:8px}.material-item span{font-size:13px}.material-item button{padding:5px 10px}.material-empty{color:#64748b;font-size:13px;padding:10px 0}
.source-panel{padding:28px 30px}.source-header{display:flex;align-items:flex-start;justify-content:space-between;gap:20px;padding-bottom:14px;border-bottom:1px solid #e8edf4}.source-heading{margin:0;font-size:17px;font-weight:850}.source-heading:before{content:'▱';color:#ed174c;font-size:22px;margin-right:9px}.source-subtitle{margin:3px 0 0;color:#64748b;font-size:12px}.source-actions{display:flex;gap:9px}.source-actions button{padding:9px 15px}.source-actions .manual-source-btn{background:#172033}.source-actions .button-icon{font-size:17px;margin-right:7px;font-weight:400}.source-table-wrap{overflow-x:auto;margin-top:16px}.source-table{width:100%;min-width:850px;border-collapse:collapse}.source-table th{padding:13px 12px;background:#f0f4f8;color:#07152d;font-size:11px;text-align:left;text-transform:uppercase}.source-table td{padding:12px;border-bottom:1px solid #e7edf4;color:#71809a;font-size:13px}.source-table input{height:31px;padding:6px 10px;font-size:12px;border-color:#c8d5e5}.source-table .source-number{font-weight:700;color:#8b9ab4}.source-table .source-unit{display:flex;align-items:center;gap:5px}.source-table .source-unit small{color:#91a0bd;font-size:9px;text-transform:uppercase}.source-table .unlimited-qty{color:#4185ff;border-color:#9ec3ff}.source-table .remove-source{display:flex;margin:auto;padding:4px;background:transparent;color:#9bacbf;font-size:17px}.source-table .remove-source:hover{color:#e9164d}.source-empty{text-align:center!important;color:#91a0bd!important;padding:22px!important}.source-picker{margin-top:14px;padding:16px;background:#f8fafc;border:1px solid #dfe6ef;border-radius:10px}.source-picker__top{display:flex;justify-content:space-between;gap:10px;align-items:center;margin-bottom:12px}.source-picker__top strong{font-size:13px}.source-picker__close{background:transparent;color:#71809a;padding:4px 8px}.source-picker select{margin-top:8px;min-height:130px}.source-picker .grid-3{align-items:end}.source-picker .add-row-btn{margin-top:10px}.source-price-settings{display:none}
.parts-header{display:flex;align-items:center;justify-content:space-between;gap:16px;padding-bottom:14px;border-bottom:1px solid #e8edf4;margin-bottom:16px}.parts-heading .section-title{margin:0;padding:0;border:0}.parts-heading .section-title:before{content:'▰';color:#ed174c}.parts-subtitle{margin:4px 0 0;color:#64748b;font-size:11px}.parts-empty td{padding:28px!important;text-align:center;color:#91a0bd}.add-part-open{background:#e9164d;box-shadow:0 2px 5px rgba(233,22,77,.22)}@media(max-width:700px){.parts-header{align-items:flex-start;flex-direction:column}}
.material-library{position:fixed;inset:0;z-index:1100;display:flex;align-items:center;justify-content:center;padding:18px;background:rgba(15,23,42,.42)}.material-library__dialog{display:flex;flex-direction:column;width:min(1040px,96vw);height:min(700px,92vh);padding:14px;background:#fff;border-radius:16px;box-shadow:0 24px 70px rgba(15,23,42,.24)}.material-library__header{display:flex;align-items:flex-start;gap:10px;padding:2px 0 14px;border-bottom:1px solid #e8edf4}.material-library__icon{display:grid;place-items:center;width:36px;height:36px;border-radius:11px;background:#eef0ff;color:#5147e8;font-size:21px}.material-library__title{flex:1}.material-library__title strong{display:block;font-size:17px}.material-library__title small{display:block;margin-top:4px;color:#7d8ba3;font-size:11px}.material-library__close{padding:5px 8px;background:transparent;color:#91a0b5;font-size:20px}.material-library__tools{display:flex;justify-content:space-between;gap:16px;padding:20px 0}.material-library__search{position:relative;width:min(360px,100%)}.material-library__search span{position:absolute;left:12px;top:9px;color:#91a0b5}.material-library__search input{padding-left:35px;background:#f8fafc}.library-tabs{display:flex;padding:4px;background:#f1f4f8;border-radius:11px}.library-tabs button{padding:8px 13px;background:transparent;color:#263650;font-size:11px}.library-tabs button.active{background:#fff;color:#101c31;box-shadow:0 1px 4px #dce3ec}.material-library__grid{overflow:auto;border:1px solid #e2e8f0;border-radius:12px}.library-list{width:100%;min-width:760px;border-collapse:collapse}.library-list th{position:sticky;top:0;z-index:1;padding:11px 14px;background:#f1f5f9;color:#64748b;text-align:left;text-transform:uppercase;font-size:10px;letter-spacing:.04em}.library-list td{padding:13px 14px;border-top:1px solid #e8edf4;color:#334155;font-size:12px}.library-list tbody tr:hover{background:#f8fafc}.library-list__manufacturer{font-weight:750;color:#172033!important}.library-list__format{white-space:nowrap;font-weight:700}.library-list__decor-number{color:#e9164d!important;font-weight:800}.library-list__action{text-align:right}.library-list button{white-space:nowrap;padding:8px 13px;background:#5045e8}.material-library__footer{display:flex;justify-content:space-between;align-items:center;margin-top:12px;padding-top:12px;border-top:1px solid #e8edf4;color:#71809a;font-size:11px}.material-library__footer button{background:#f1f4f8;color:#56647b}.library-empty{padding:50px!important;text-align:center!important;color:#71809a!important}@media(max-width:700px){.material-library__tools{align-items:stretch;flex-direction:column}.library-tabs{overflow-x:auto}.library-tabs button{white-space:nowrap}}
.btn-icon { display:inline-flex; align-items:center; justify-content:center; width:36px; height:36px; border:none; border-radius:8px; cursor:pointer; font-size:16px; transition:all .2s; }
.btn-icon.btn-edit { color:#2563eb; background:#eff6ff; }
.btn-icon.btn-edit:hover { background:#dbeafe; }
.btn-icon.btn-delete { color:#dc2626; background:#fef2f2; }
.btn-icon.btn-delete:hover { background:#fee2e2; }
.header h1 { margin: 0; font-size: clamp(24px, 3.5vw, 36px); letter-spacing: -.02em; font-weight: 900; }
@media(max-width:900px){.top-grid{grid-template-columns:1fr}.cost-card__stats{margin:25px 0}}
@media(max-width:600px){.container{padding:0}.panel{padding:18px}.grid,.cutting-meta,.strategy-options,.cut-settings{grid-template-columns:1fr}.settings-title__note{display:none}.parts-table,.history-table{display:block;overflow-x:auto}.source-header{display:block}.source-actions{margin-top:14px;flex-wrap:wrap}.source-actions button{flex:1;white-space:nowrap}}
</style>
<?php echo app_header_styles(); ?>
</head>
<body>
<?php render_app_header(); ?>
<main class="container">

    <div class="top-grid">

    <section class="panel settings-panel">
        <div class="section-title main settings-title"><span>Общие параметры и тип раскроя</span><small class="settings-title__note">Алгоритм размещения деталей</small></div>
        <div class="cutting-meta">
            <div><label for="object_name">Название объекта</label><input id="object_name" type="text" placeholder="Например, ЖК Северный, корпус 2"></div>
            <div><label for="cutting_name">Название раскроя</label><input id="cutting_name" type="text" placeholder="Например, Фасады кухни"></div>
        </div>
        <div class="strategy-heading">Тип (стратегия) раскроя <span>*</span></div>
        <div class="strategy-options" role="radiogroup" aria-label="Тип (стратегия) раскроя">
            <label class="strategy-card"><input type="radio" name="strategy" value="length"><strong><i>↔</i>По длине</strong><span>Расположение рисунка по длинной стороне листа.</span></label>
            <label class="strategy-card"><input type="radio" name="strategy" value="width"><strong><i>⇅</i>По ширине</strong><span>Расположение рисунка по широкой стороне листа.</span></label>
            <label class="strategy-card"><input type="radio" name="strategy" value="optimal" checked><strong><i>✣</i>Оптимально</strong><span>Оптимальное расположение деталей с поворотом.</span><b class="strategy-check">⊙</b></label>
        </div>
        <div class="hidden"><label for="method">Метод расчёта</label><select id="method"><option value="optimal">Оптимально (с разворотом)</option><option value="length">По длине (вдоль декора)</option><option value="width">По ширине (вдоль декора)</option></select><div class="hint" id="method-hint">При методе «Оптимально» детали можно разворачивать на 90°.</div></div>
        <div class="cut-settings">
            <div class="cut-setting"><label for="kerf">Пропил пилы (толщина диска)</label><div class="input-unit"><input id="kerf" type="number" min="0" step="0.1" value="4"><b>мм</b></div><div class="hint">Ширина реза между деталями.</div></div>
            <div class="cut-setting"><label for="margin">Торцевание плиты</label><div class="input-unit"><input id="margin" type="number" min="0" step="0.1" value="5"><b>мм</b></div><div class="hint">Отступ для торцевания по краям плиты.</div></div>
            <div class="cut-setting"><label for="cut_price">Тариф распила (₽/м.п.)</label><div class="input-unit"><input id="cut_price" type="number" min="0" step="0.01" value="250"><b>₽/<br>м.п.</b></div><div class="hint">Стоимость прямого реза за 1 м.п.</div></div>
        </div>
    </section>

    <aside class="panel cost-card" aria-label="Стоимость услуг распила">
        <div class="cost-card__section">
            <div class="cost-card__eyebrow">✦ Стоимость услуг распила</div>
            <div class="cost-card__total" id="cost-total">0,00 ₽</div>
            <div class="cost-card__stats"><div class="cost-card__row"><span>Метраж реза:</span><b id="cost-length">—</b></div></div>
        </div>
        <div class="cost-card__section">
            <div class="cost-card__eyebrow">✦ Стоимость материала</div>
            <div class="cost-card__total" id="material-cost-total">0,00 ₽</div>
            <div class="cost-card__stats">
                <div class="cost-card__row"><span>Листов в расчёте:</span><b id="cost-sheets">—</b></div>
                <div class="cost-card__row"><span>Деталей в расчёте:</span><b id="cost-parts">—</b></div>
                <div class="cost-card__row"><span>Общая площадь деталей:</span><b id="cost-area">—</b></div>
                <div class="cost-card__row"><span>Общая площадь отходов:</span><b id="cost-waste-area">—</b></div>
                <div class="cost-card__row"><span>Стоимость материала:</span><b id="cost-material">—</b></div>
                <div class="cost-card__row"><span>Стоимость деталей:</span><b id="cost-material-parts">—</b></div>
                <div class="cost-card__row"><span>Стоимость отходов:</span><b id="cost-material-waste">—</b></div>
            </div>
        </div>
        <button type="button" onclick="document.getElementById('save-btn').click()">▣ &nbsp;Сохранить раскрой</button>
    </aside>
    </div>

    <section class="panel source-panel">
        <div class="source-header">
            <div><h2 class="source-heading">Исходные листы (Форматы и Запас плит)</h2><p class="source-subtitle">Выберите готовые плиты из базы декоров/форматов или введите размеры вручную.</p></div>
            <div class="source-actions"><button type="button" id="show-db-material"><span class="button-icon">▤</span>Выбрать из базы</button><button type="button" class="manual-source-btn" id="show-custom-material"><span class="button-icon">＋</span>Указать вручную</button></div>
        </div>
        <div id="db-material-fields" class="material-library hidden" role="dialog" aria-modal="true" aria-labelledby="material-library-title">
          <div class="material-library__dialog"><div class="material-library__header"><span class="material-library__icon">▤</span><div class="material-library__title"><strong id="material-library-title">База исходных плит и декоров</strong><small>Выберите готовый формат или декор из каталога для добавления в раскрой</small></div><button type="button" class="material-library__close source-picker__close" aria-label="Закрыть">×</button></div>
          <div class="material-library__tools"><div class="material-library__search"><span>⌕</span><input id="panel_search" type="search" placeholder="Поиск по названию, декору, габаритам..." autocomplete="off"></div><div class="library-tabs"><button type="button" class="active" data-library-filter="all">Все</button><button type="button" data-library-filter="format">Форматы плит</button><button type="button" data-library-filter="decor">Декоры каталога</button></div></div>
          <div id="material-library-grid" class="material-library__grid"></div><div class="material-library__footer"><span id="panel-search-result"></span><button type="button" class="source-picker__close">Закрыть</button></div></div>
        </div>
        <div class="source-table-wrap"><table class="source-table"><thead><tr><th>№</th><th>Наименование / формат</th><th>Длина (мм)</th><th>Ширина (мм)</th><th>Торцевание (мм)</th><th>В наличии (шт)</th><th>Цена за м²</th><th>Удалить</th></tr></thead><tbody id="materials-list"></tbody></table></div>
        <div class="grid source-price-settings">
            <div><label for="material_price_m2">Цена за м²</label><input id="material_price_m2" type="number" min="0" step="0.01" value="0"></div>
            <div><label for="sheet_currency">Валюта</label><select id="sheet_currency"><option value="RUB">RUB</option><option value="EUR">EUR</option><option value="USD">USD</option></select></div>
        </div>
    </section>

    <section class="panel">
        <div class="parts-header"><div class="parts-heading"><div class="section-title main">Детали для раскроя (<span id="parts-count">0</span>)</div><p class="parts-subtitle">Укажите габариты деталей, количество и возможность разворота на 90°.</p></div><button type="button" class="add-part-open" id="add-part-btn">＋ Добавить деталь</button></div>
        <table class="parts-table" id="parts-table">
            <thead><tr>
                <th style="width:48px">№</th><th>Наименование / назначение</th><th style="width:140px">Длина (мм)</th><th style="width:140px">Ширина (мм)</th><th style="width:155px">Направление рисунка</th><th style="width:125px">Количество (шт.)</th><th class="rotate-column" style="width:145px">⟳ Поворот (90°)</th><th style="width:105px">Площадь</th><th style="width:85px;text-align:center">Действие</th>
            </tr></thead>
            <tbody id="parts-tbody"></tbody>
        </table>
        <div class="actions-row">
            <button type="button" id="cut-btn">📐 Раскрой</button>
            <button type="button" class="secondary" id="save-btn">💾 Сохранить</button>
            <button type="button" class="danger" id="new-calculation-btn">＋ Новый расчёт</button>
        </div>
    </section>

    <!-- ═══ РЕЗУЛЬТАТ РАСКРОЯ ═══ -->
    <section class="panel hidden" id="result-section">
        <div class="section-title main">Карта раскроя плит</div>

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

<script>
/* ═══════════ ДАННЫЕ ИЗ БД ═══════════ */
const MANUFACTURERS = <?php echo $manufacturersJson; ?>;
const PANEL_SIZES   = <?php echo $panelSizesJson; ?>;
const THICKNESSES   = <?php echo $thicknessesJson; ?>;
const PANELS        = <?php echo $panelsJson; ?>;

let parts = []; // {id, name, length, width, grainDirection, qty, rotate}
let nextPartId = 1;
let lastResult = null;
let loadedId = null;

document.querySelectorAll('[name="strategy"]').forEach(option => option.addEventListener('change', () => {
    document.getElementById('method').value = option.value;
}));

/* ═══════════ ИСХОДНЫЙ МАТЕРИАЛ ═══════════ */
const panelSearch = document.getElementById('panel_search');
const dbMaterialFields = document.getElementById('db-material-fields');
const libraryGrid = document.getElementById('material-library-grid');
const priceM2Input = document.getElementById('material_price_m2');
const sheetCurrencyInput = document.getElementById('sheet_currency');
const cutPriceInput = document.getElementById('cut_price');
const materialsList = document.getElementById('materials-list');
let sourceMaterials = [];
let nextMaterialId = 1;
let nextManualMaterialNumber = 1;
let libraryFilter = 'all';

function panelLabel(panel) {
    const size = panel.height_mm && panel.width_mm ? `${panel.height_mm}×${panel.width_mm} мм` : '';
    return [panel.manufacturer_name, panel.decor_number, panel.decor_name || panel.name, size].filter(Boolean).join(' · ');
}
function libraryItems() {
    const formats = PANEL_SIZES.map(size => ({...size, type:'format', label:`Формат ${size.manufacturer_name || 'плиты'} ${size.height_mm}×${size.width_mm}`}));
    const decors = PANELS.filter(panel => Number(panel.height_mm)>0 && Number(panel.width_mm)>0).map(panel => ({...panel, type:'decor', label:panelLabel(panel)}));
    return [...formats, ...decors];
}
function renderPanelSearch() {
    const query = panelSearch.value.trim().toLocaleLowerCase('ru');
    const matches = libraryItems().filter(item => {
        const searchable = [item.manufacturer_name, item.height_mm, item.width_mm, item.decor_number, item.decor_name, item.name].filter(Boolean).join(' ').toLocaleLowerCase('ru');
        return (libraryFilter === 'all' || item.type === libraryFilter) && (!query || searchable.includes(query));
    });
    const rows = matches.map((item, index) => `<tr><td class="library-list__manufacturer">${escapeHtml(item.manufacturer_name || '—')}</td><td class="library-list__format">${Number(item.height_mm)} × ${Number(item.width_mm)} мм</td><td class="library-list__decor-number">${escapeHtml(item.decor_number || '—')}</td><td>${escapeHtml(item.decor_name || (item.type === 'decor' ? item.name : '') || '—')}</td><td class="library-list__action"><button type="button" data-library-index="${index}">＋ Добавить</button></td></tr>`).join('');
    libraryGrid.innerHTML = `<table class="library-list"><thead><tr><th>Производитель</th><th>Формат</th><th>Номер декора</th><th>Название декора</th><th></th></tr></thead><tbody>${rows || '<tr><td colspan="5" class="library-empty">Материалы не найдены</td></tr>'}</tbody></table>`;
    libraryGrid.querySelectorAll('[data-library-index]').forEach(button => button.addEventListener('click', () => addLibraryMaterial(matches[Number(button.dataset.libraryIndex)])));
    document.getElementById('panel-search-result').textContent = `Доступно в базе: ${matches.length} видов листов`;
}
function addLibraryMaterial(item) {
    const isDecor = item.type === 'decor';
    const sourceCurrency = item.currency || 'RUB';
    const targetCurrency = window.AppCurrency?.code || sourceCurrency;
    const sourcePrice = Number(isDecor ? (item.price_per_m2 || item.cost || 0) : 0);
    const priceM2 = window.AppCurrency ? Math.round(AppCurrency.convert(sourcePrice, sourceCurrency, targetCurrency) * 100) / 100 : sourcePrice;
    if (isDecor) {
        priceM2Input.value = priceM2;
        sheetCurrencyInput.value = targetCurrency;
    }
    addSourceMaterial({height:Number(item.height_mm),width:Number(item.width_mm),qty:null,priceM2,currency:targetCurrency,panelId:isDecor ? item.id : null,manufacturerId:item.manufacturer_id || null,label:item.label,margin:Number(document.getElementById('margin').value)||0});
}
function getSelectedFormats() { return sourceMaterials.map(({materialId, ...format}) => ({...format})); }
function selectedManufacturerIds() { return [...new Set(sourceMaterials.map(m => m.manufacturerId).filter(Boolean).map(String))]; }
function addManufacturerRow() {}
function addManualMaterial() {
    addSourceMaterial({
        height:3050,
        width:1300,
        qty:1,
        priceM2:0,
        currency:window.AppCurrency?.code || 'RUB',
        label:`Произвольный лист ${nextManualMaterialNumber++}`,
        margin:Number(document.getElementById('margin').value)||0
    });
}
function renderSourceMaterials() {
    if (!sourceMaterials.length) {
        materialsList.innerHTML = '<tr><td colspan="8" class="source-empty">Добавьте лист из базы или укажите его размеры вручную.</td></tr>';
        return;
    }
    materialsList.innerHTML = sourceMaterials.map((m, index) => `<tr data-id="${m.materialId}"><td class="source-number">${index + 1}</td><td><input data-field="label" value="${escapeHtml(m.label)}"></td><td><input type="number" min="1" data-field="height" value="${m.height}"></td><td><input type="number" min="1" data-field="width" value="${m.width}"></td><td><div class="source-unit"><input type="number" min="0" step="0.1" data-field="margin" value="${m.margin ?? 0}"><small>мм</small></div></td><td><input class="${m.qty == null ? 'unlimited-qty' : ''}" type="${m.qty == null ? 'text' : 'number'}" min="1" data-field="qty" value="${m.qty == null ? 'Авто (без лимита)' : m.qty}" aria-label="Количество листов; оставьте пустым для автоматического количества"></td><td><div class="source-unit"><input type="number" min="0" step="0.01" data-field="priceM2" value="${m.priceM2 ?? 0}"><small>${escapeHtml(m.currency || 'RUB')}</small></div></td><td><button type="button" class="remove-source remove-material" data-id="${m.materialId}" aria-label="Удалить материал">&#128465;</button></td></tr>`).join('');
}
function addSourceMaterial(format) {
    if (!(format.height > 0 && format.width > 0)) { alert('Укажите корректные размеры материала.'); return; }
    sourceMaterials.push({...format, qty:format.qty ?? null, priceM2:Number(format.priceM2)||0, materialId:nextMaterialId++});
    renderSourceMaterials();
    if (typeof scheduleDraftSave === 'function') scheduleDraftSave();
}
function showMaterialPicker(picker) {
    dbMaterialFields.classList.toggle('hidden', picker !== dbMaterialFields);
    document.body.style.overflow = picker ? 'hidden' : '';
    if (picker === dbMaterialFields) { renderPanelSearch(); panelSearch.focus(); }
}
document.getElementById('show-db-material').addEventListener('click', () => showMaterialPicker(dbMaterialFields));
document.getElementById('show-custom-material').addEventListener('click', addManualMaterial);
document.querySelectorAll('.source-picker__close').forEach(button => button.addEventListener('click', () => showMaterialPicker(null)));
dbMaterialFields.addEventListener('click', event => { if (event.target === dbMaterialFields) showMaterialPicker(null); });
document.addEventListener('keydown', event => { if (event.key !== 'Escape') return; if (!dbMaterialFields.classList.contains('hidden')) showMaterialPicker(null); });
panelSearch.addEventListener('input', renderPanelSearch);
document.querySelectorAll('[data-library-filter]').forEach(button => button.addEventListener('click', () => { libraryFilter=button.dataset.libraryFilter; document.querySelectorAll('[data-library-filter]').forEach(tab => tab.classList.toggle('active',tab===button)); renderPanelSearch(); }));
materialsList.addEventListener('click', event => { const button=event.target.closest('.remove-material');if(!button)return;sourceMaterials=sourceMaterials.filter(m=>m.materialId!==Number(button.dataset.id));renderSourceMaterials(); });
materialsList.addEventListener('focusin', event => { if (event.target.dataset.field === 'qty' && event.target.value === 'Авто (без лимита)') { event.target.type='number'; event.target.value=''; event.target.placeholder='Авто (без лимита)'; } });
materialsList.addEventListener('change', event => { const row=event.target.closest('tr[data-id]');if(!row||!event.target.dataset.field)return;const material=sourceMaterials.find(m=>m.materialId===Number(row.dataset.id));if(!material)return;const field=event.target.dataset.field;if(field==='label'){material.label=event.target.value.trim()||material.label;event.target.value=material.label;}else if(field==='qty'){material.qty=event.target.value === '' ? null : Math.max(1,Number(event.target.value)||1);if(material.qty!==null){event.target.value=material.qty;event.target.classList.remove('unlimited-qty');}}else{const value=Number(event.target.value);if((field==='margin'||field==='priceM2') ? value>=0 : value>0)material[field]=value;} });
materialsList.addEventListener('focusout', event => { if (event.target.dataset.field === 'qty' && event.target.value === '') { const material=sourceMaterials.find(m=>m.materialId===Number(event.target.closest('tr').dataset.id)); if(material) material.qty=null; event.target.type='text'; event.target.value='Авто (без лимита)'; event.target.classList.add('unlimited-qty'); } });
renderPanelSearch();
renderSourceMaterials();
window.addEventListener('appcurrencychange', event => {
    const code=event.detail?.code, convert=event.detail?.convert;
    if(!code)return;
    if(!Array.from(sheetCurrencyInput.options).some(o=>o.value===code)) sheetCurrencyInput.add(new Option(code,code));
    sourceMaterials.forEach(material => {
        if (material.currency !== code && typeof convert === 'function') material.priceM2 = Math.round(convert(material.priceM2, material.currency || 'RUB', code) * 100) / 100;
        material.currency = code;
    });
    const currentPrice=Number(priceM2Input.value);
    const previousCurrency=sheetCurrencyInput.value || 'RUB';
    if (previousCurrency !== code && Number.isFinite(currentPrice) && typeof convert === 'function') priceM2Input.value=Math.round(convert(currentPrice,previousCurrency,code)*100)/100;
    sheetCurrencyInput.value=code;
    renderSourceMaterials();
});

/* ═══════════ Утилиты ═══════════ */
function escapeHtml(str) {
    return String(str ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}
function fmtNum(v, d=2) { return new Intl.NumberFormat('ru-RU', {minimumFractionDigits:0, maximumFractionDigits:d}).format(v||0); }

/* ═══════════ СПИСОК ДЕТАЛЕЙ ═══════════ */
const partsTbody = document.getElementById('parts-tbody');
function renderParts() {
    partsTbody.innerHTML = '';
    document.getElementById('parts-count').textContent = parts.length;
    if (!parts.length) partsTbody.innerHTML = '<tr class="parts-empty"><td colspan="9">Детали пока не добавлены. Нажмите «Добавить деталь».</td></tr>';
    const unplaced = new Set(lastResult?.unplacedPartIds || []);
    const colors=['#2f6fe4','#13a079','#df8410','#db3374','#7c3aed','#1393ad','#ea5b0c','#65a21e'];
    parts.forEach((p, idx) => {
        const tr=document.createElement('tr');
        if(unplaced.has(p.id)) tr.style.background='#fef2f2';
        tr.innerHTML=`<td class="part-index">${idx+1}</td><td><div class="part-name-cell"><span class="part-color" style="background:${colors[idx%colors.length]}"></span><input class="inline-edit" data-id="${p.id}" data-field="name" value="${escapeHtml(p.name)}"></div></td><td><input type="number" class="inline-edit" data-id="${p.id}" data-field="length" value="${p.length}" min="1"></td><td><input type="number" class="inline-edit" data-id="${p.id}" data-field="width" value="${p.width}" min="1"></td><td><select class="inline-edit" data-id="${p.id}" data-field="grainDirection"><option value="none" ${p.grainDirection==='none'?'selected':''}>Без разницы</option><option value="length" ${p.grainDirection==='length'?'selected':''}>По длине</option><option value="width" ${p.grainDirection==='width'?'selected':''}>По ширине</option></select></td><td><input type="number" class="inline-edit" data-id="${p.id}" data-field="qty" value="${p.qty}" min="1"></td><td class="rotate-column"><label class="rotate-label"><input type="checkbox" class="toggle-rotate" data-id="${p.id}" ${p.rotate?'checked':''}><span>${p.rotate?'Да':'Нет'}</span></label></td><td class="part-area">${fmtNum(p.length*p.width*p.qty/1000000,2)} м²</td><td style="text-align:center"><button type="button" class="delete-part" onclick="deletePart(${p.id})" aria-label="Удалить деталь">&#128465;</button></td>`;
        partsTbody.appendChild(tr);
    });
}
partsTbody.addEventListener('change',e=>{const p=parts.find(x=>x.id===Number(e.target.dataset.id));if(!p)return;if(e.target.classList.contains('toggle-rotate')){p.rotate=e.target.checked;e.target.nextElementSibling.textContent=p.rotate?'Да':'Нет';return;}if(!e.target.classList.contains('inline-edit'))return;const field=e.target.dataset.field;if(field==='name'){p.name=e.target.value.trim()||p.name;e.target.value=p.name;}else if(field==='grainDirection')p.grainDirection=e.target.value;else{const value=Number(e.target.value);if(value>0)p[field]=value;}const area=e.target.closest('tr').querySelector('.part-area');area.textContent=`${fmtNum(p.length*p.width*p.qty/1000000,2)} м²`;});
document.getElementById('add-part-btn').addEventListener('click',()=>{
    const partNumber=nextPartId++;
    const part={id:partNumber,name:`Деталь №${partNumber}`,length:1000,width:500,grainDirection:'none',qty:1,rotate:false};
    parts.push(part);renderParts();scheduleDraftSave();requestAnimationFrame(()=>{const input=partsTbody.querySelector(`[data-id="${part.id}"][data-field="name"]`);input?.focus();input?.select();});
});
renderParts();
window.deletePart=id=>{parts=parts.filter(p=>p.id!==id);renderParts();scheduleDraftSave();};

/* ═══════════════════════════════════════════════════
   АЛГОРИТМ РАСКРОЯ (гильотинный bin-packing)
   ═══════════════════════════════════════════════════ */
function packSheets(pieces, sheetW, sheetH, kerf, method, maxSheets = null) {
    let queue = pieces.slice().sort((a, b) => (b.w * b.h) - (a.w * a.h));
    const sheets = [];

    while (queue.length > 0 && (maxSheets === null || sheets.length < maxSheets)) {
        let freeRects = [{x: 0, y: 0, w: sheetW, h: sheetH}];
        const placed = [];
        let placedAny = true;

        while (placedAny) {
            placedAny = false;
            let best = null;

                for (let pi = 0; pi < queue.length; pi++) {
                    const piece = queue[pi];
                    let orientations;
                    orientations = [{w: piece.w, h: piece.h, rotated: false}];
                    if (piece.canRotate && piece.w !== piece.h) orientations.push({w: piece.h, h: piece.w, rotated: true});
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

                placed.push({id: piece.id, name: piece.name, grainDirection: piece.grainDirection, x: rect.x, y: rect.y, w: pw, h: ph, rotated: Boolean(piece.baseRotated) !== best.rotated});

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
    const priceM2 = parseFloat(priceM2Input.value) || 0;
    const cutPrice = parseFloat(cutPriceInput.value) || 0;
    const currency = sheetCurrencyInput.value || 'RUB';

    const validFormats = formats.filter(f => {
        const formatMargin = Number(f.margin ?? margin);
        const sw = f.width - formatMargin * 2, sh = f.height - formatMargin * 2;
        return sw > 0 && sh > 0;
    });
    if (validFormats.length === 0) { alert('Размер листа за вычетом отступов должен быть положительным.'); return; }

    const sortedFormats = [...validFormats].sort((a, b) => (a.width * a.height) - (b.width * b.height));

    // Ось H совпадает с направлением рисунка панели. При запрете поворота
    // указанная ось рисунка детали всегда укладывается вдоль рисунка панели.
    function packingDimensions(part) {
        if (part.grainDirection === 'length') return {w:part.width, h:part.length, baseRotated:true};
        return {w:part.length, h:part.width, baseRotated:false};
    }
    function pieceFitsOnFormat(part, sw, sh) {
        const {w, h} = packingDimensions(part);
        return (w <= sw && h <= sh) || (part.rotate && h <= sw && w <= sh);
    }

    const formatGroups = new Map();
    for (const p of parts) {
        let assignedFmt = null;
        for (const fmt of sortedFormats) {
            const formatMargin = Number(fmt.margin ?? margin);
            const sw = fmt.width - formatMargin * 2;
            const sh = fmt.height - formatMargin * 2;
            if (pieceFitsOnFormat(p, sw, sh)) {
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
        const formatMargin = Number(fmt.margin ?? margin);
        const sheetW = fmt.width - formatMargin * 2;
        const sheetH = fmt.height - formatMargin * 2;
        const testPieces = fmtParts.map(p => ({id:p.id, name:p.name, grainDirection:p.grainDirection, ...packingDimensions(p), canRotate:p.rotate, qtyLeft:p.qty}));
        const {sheets: trySheets, remaining: tryRemaining} = packSheets(testPieces, sheetW, sheetH, kerf, method, fmt.qty ?? null);
        for (const s of trySheets) allSheets.push({format: fmt, ...s});
        allRemaining.push(...tryRemaining.filter(p => p.qtyLeft > 0));
    }

    if (allSheets.length === 0) { alert('Не удалось разместить ни одну деталь.'); return; }

    const placedCounts = new Map();
    allSheets.forEach(s => s.placed.forEach(pl => placedCounts.set(pl.id, (placedCounts.get(pl.id) || 0) + 1)));
    const unplacedParts = parts.map(p => ({id:p.id, name:p.name, qty:Math.max(0, p.qty - (placedCounts.get(p.id) || 0))})).filter(p => p.qty > 0);
    const unplacedPartIds = new Set(unplacedParts.map(p => p.id));
    if (allRemaining.length > 0) {
        alert('Внимание: не все детали удалось разместить: ' + unplacedParts.map(p => `${p.name} — ${p.qty} шт.`).join(', '));
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
    const sheetsCost = allSheets.reduce((sum, sheet) => {
        const sheetAreaM2 = (sheet.format.width * sheet.format.height) / 1e6;
        return sum + sheetAreaM2 * Number(sheet.format.priceM2 ?? priceM2);
    }, 0);
    const partsCost = totalSheetAreaM2 > 0 ? sheetsCost * (totalPartsArea / totalSheetAreaM2) : 0;
    const wasteCost = sheetsCost - partsCost;

    const cuttingCostRub = cutPrice * totalCutLength;
    const cuttingCost = window.AppCurrency ? AppCurrency.convert(cuttingCostRub, 'RUB', currency) : cuttingCostRub;
    const totalCost = sheetsCost + cuttingCost;
    lastResult = {fmtLabel, formats: validFormats, kerf, margin, method, priceM2, cutPrice, currency,
        sheets: allSheets, sheetAreaM2: totalSheetAreaM2 / sheetCount, sheetCount,
        totalSheetsArea: totalSheetAreaM2, totalPartsArea, totalCutLength, totalPartsCount,
        wasteArea, sheetsCost, partsCost, wasteCost, cuttingCost, totalCost,
        unplacedPartIds: [...unplacedPartIds], unplacedParts};

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
    document.getElementById('cost-total').textContent = `${fmtNum(r.cuttingCost, 2)} ${r.currency === 'RUB' ? '₽' : r.currency}`;
    document.getElementById('cost-sheets').textContent = `${r.sheetCount} шт.`;
    document.getElementById('cost-parts').textContent = `${r.totalPartsCount} шт.`;
    document.getElementById('cost-area').textContent = `${fmtNum(r.totalPartsArea, 2)} м²`;
    document.getElementById('cost-length').textContent = `${fmtNum(r.totalCutLength, 1)} м.п.`;
    document.getElementById('cost-waste-area').textContent = `${fmtNum(r.wasteArea, 2)} м²`;
    const costCurrency = r.currency === 'RUB' ? '₽' : r.currency;
    document.getElementById('material-cost-total').textContent = `${fmtNum(r.sheetsCost, 2)} ${costCurrency}`;
    document.getElementById('cost-material').textContent = `${fmtNum(r.sheetsCost, 2)} ${costCurrency}`;
    document.getElementById('cost-material-parts').textContent = `${fmtNum(r.partsCost, 2)} ${costCurrency}`;
    document.getElementById('cost-material-waste').textContent = `${fmtNum(r.wasteCost, 2)} ${costCurrency}`;

    const fmtSummary = r.fmtLabel || (r.formats ? r.formats.map(f => f.label).join(' + ') : '');
    const fmtCounts = {};
    r.sheets.forEach(s => {
        const lbl = (s.format || r.formats[0]).label || '?';
        fmtCounts[lbl] = (fmtCounts[lbl] || 0) + 1;
    });
    const fmtBreakdown = Object.entries(fmtCounts).map(([lbl, cnt]) => `${lbl} — ${cnt} лист.`).join(', ');
    const unplaced = r.unplacedParts || (r.unplacedPartIds || []).map(id => ({id, name:`№${id}`, qty:1}));
    const unplacedHtml = unplaced.length > 0
        ? `<tr><th style="color:#dc2626">Не размещены</th><td style="color:#dc2626;font-weight:700">${unplaced.map(p => `${escapeHtml(p.name)} — ${p.qty} шт.`).join('<br>')}</td></tr>`
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
        <tr><th>Стоимость распила</th><td>${fmtNum(r.cuttingCost,2)} ${escapeHtml(r.currency)}</td></tr>
        <tr><th>Итого</th><td>${fmtNum(r.totalCost,2)} ${escapeHtml(r.currency)}</td></tr>
        ${unplacedHtml}
    `;

    sheetsContainer.innerHTML = '';

    r.sheets.forEach((sheet, idx) => {
        const sf = sheet.format || r.formats[0];
        const sheetMargin = Number(sf.margin ?? r.margin ?? 0);
        const sheetAreaM2 = (sf.width * sf.height) / 1e6;
        const scale = Math.min(1100 / sf.height, 500 / sf.width);
        const sheetW = sf.height * scale;
        const sheetH = sf.width * scale;
        const dimensionTop = 28;
        const dimensionRight = 48;
        const decorGuideHeight = 38;
        const sheetX = 0;
        const sheetY = dimensionTop;
        const svgW = sheetW + dimensionRight;
        const svgH = dimensionTop + sheetH + decorGuideHeight;
        let rectsHtml = '';
        let partsRowsHtml = '';
        let placedArea = 0;
        sheet.placed.forEach(pl => {
            placedArea += (pl.w * pl.h) / 1e6;
            const x = sheetX + (pl.y + sheetMargin) * scale, y = sheetY + (pl.x + sheetMargin) * scale, w = pl.h * scale, h = pl.w * scale;
            const arrLen = Math.min(w, h) * 0.5;
            const grainDirection = pl.grainDirection || parts.find(part => part.id === pl.id)?.grainDirection || 'none';
            const horizontalGrain = grainDirection === 'length' ? pl.rotated : !pl.rotated;
            let arrowHtml = '';
            if (grainDirection !== 'none' && arrLen > 14) {
                if (horizontalGrain) {
                    const arrY = y + h / 2;
                    const arrX1 = x + (w - arrLen) / 2;
                    const arrX2 = arrX1 + arrLen;
                    arrowHtml = `<line x1="${arrX1}" y1="${arrY}" x2="${arrX2 - 6}" y2="${arrY}" stroke="#1e40af" stroke-width="2" stroke-linecap="round"/>
                        <polygon points="${arrX2},${arrY} ${arrX2 - 7},${arrY - 4} ${arrX2 - 7},${arrY + 4}" fill="#1e40af"/>`;
                } else {
                    const arrX = x + w / 2;
                    const arrY1 = y + (h - arrLen) / 2;
                    const arrY2 = arrY1 + arrLen;
                    arrowHtml = `<line x1="${arrX}" y1="${arrY1}" x2="${arrX}" y2="${arrY2 - 6}" stroke="#1e40af" stroke-width="2" stroke-linecap="round"/>
                        <polygon points="${arrX},${arrY2} ${arrX - 4},${arrY2 - 7} ${arrX + 4},${arrY2 - 7}" fill="#1e40af"/>`;
                }
            }
            const horizontalDimension = w > 24
                ? `<text class="part-dimension" x="${x + w / 2}" y="${y + 12}" text-anchor="middle">${fmtNum(pl.h,0)}</text>`
                : '';
            const verticalDimension = h > 24
                ? `<text class="part-dimension" x="${x + 11}" y="${y + h / 2}" text-anchor="middle" dominant-baseline="middle" transform="rotate(-90 ${x + 11} ${y + h / 2})">${fmtNum(pl.w,0)}</text>`
                : '';
            rectsHtml += `<g class="part-rect">
                <rect x="${x}" y="${y}" width="${w}" height="${h}" fill="${colorForId(pl.id)}" stroke="#374151" stroke-width="1"/>
                ${horizontalDimension}
                ${verticalDimension}
                ${arrowHtml}
                <text x="${x + w - 6}" y="${y + h - 6}" text-anchor="end" font-size="10" fill="#6b7280">#${pl.id}</text>
            </g>`;
            partsRowsHtml += `<tr><td><span class="part-number" style="background:${colorForId(pl.id)}">${pl.id}</span></td><td>${escapeHtml(pl.name || `Деталь ${pl.id}`)}</td><td>${fmtNum(pl.w,0)}×${fmtNum(pl.h,0)} мм</td><td>${pl.rotated ? 'Да' : 'Нет'}</td><td>${fmtNum(pl.x + sheetMargin,0)}; ${fmtNum(pl.y + sheetMargin,0)}</td></tr>`;
        });

        const usagePercent = sheetAreaM2 > 0 ? (placedArea / sheetAreaM2 * 100) : 0;
        const block = document.createElement('div');
        block.innerHTML = `
            <div class="sheets-header">Лист ${idx + 1}${sf.label ? ' — ' + escapeHtml(sf.label) : ''}</div>
            <div class="sheet-block">
                <div class="sheet-canvas-wrap">
                    <svg width="${svgW}" height="${svgH}" viewBox="0 0 ${svgW} ${svgH}" aria-label="Карта раскроя листа ${idx + 1}">
                        <defs>
                            <marker id="ga${idx}" markerWidth="8" markerHeight="6" refX="7" refY="3" orient="auto"><polygon points="0,0 8,3 0,6" fill="#1e40af"/></marker>
                            <marker id="da${idx}" markerWidth="6" markerHeight="6" refX="3" refY="3" orient="auto-start-reverse"><path d="M6,0 L0,3 L6,6" fill="none" stroke="#475569"/></marker>
                        </defs>
                        <line x1="${sheetX + 4}" y1="14" x2="${sheetX + sheetW - 4}" y2="14" stroke="#475569" marker-start="url(#da${idx})" marker-end="url(#da${idx})"/>
                        <text x="${sheetX + sheetW / 2}" y="10" text-anchor="middle" font-size="11" fill="#334155" font-weight="700">${fmtNum(sf.height,0)} мм</text>
                        <line x1="${sheetX + sheetW + 20}" y1="${sheetY + 4}" x2="${sheetX + sheetW + 20}" y2="${sheetY + sheetH - 4}" stroke="#475569" marker-start="url(#da${idx})" marker-end="url(#da${idx})"/>
                        <text x="${sheetX + sheetW + 34}" y="${sheetY + sheetH / 2}" text-anchor="middle" font-size="11" fill="#334155" font-weight="700" transform="rotate(-90 ${sheetX + sheetW + 34} ${sheetY + sheetH / 2})">${fmtNum(sf.width,0)} мм</text>
                        <rect x="${sheetX + 0.5}" y="${sheetY + 0.5}" width="${sheetW - 1}" height="${sheetH - 1}" fill="#e5e7eb" stroke="#9ca3af"/>
                        <rect x="${sheetX + sheetMargin * scale}" y="${sheetY + sheetMargin * scale}" width="${sheetW - sheetMargin * scale * 2}" height="${sheetH - sheetMargin * scale * 2}" fill="none" stroke="#ef4444" stroke-dasharray="5 4"/>
                        ${rectsHtml}
                        <text x="${sheetX + sheetW / 2}" y="${sheetY + sheetH + 15}" text-anchor="middle" font-size="11" fill="#1e40af" font-weight="600">направление рисунка</text>
                        <line x1="${sheetX + 8}" y1="${sheetY + sheetH + 25}" x2="${sheetX + sheetW - 20}" y2="${sheetY + sheetH + 25}" stroke="#1e40af" stroke-width="2" marker-end="url(#ga${idx})"/>
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
                <table class="sheet-parts">
                    <thead><tr><th>№</th><th>Деталь</th><th>Размер на карте</th><th>Поворот</th><th>Координаты X; Y</th></tr></thead>
                    <tbody>${partsRowsHtml}</tbody>
                </table>
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
        decor_id: sourceMaterials.find(m => m.panelId)?.panelId || null,
        kerf: document.getElementById('kerf').value,
        margin: document.getElementById('margin').value,
        method: document.getElementById('method').value,
        price_m2: priceM2Input.value,
        cut_price: cutPriceInput.value,
        material_mode: sourceMaterials.some(m => m.panelId) ? (sourceMaterials.every(m => m.panelId) ? 'db' : 'mixed') : 'custom',
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
        document.querySelector(`[name="strategy"][value="${s.method || 'optimal'}"]`)?.click();
        priceM2Input.value = s.price_m2 || s.sheet_cost || 0;
        cutPriceInput.value = s.cut_price || 250;
        sheetCurrencyInput.value = s.sheet_currency || 'RUB';

        sourceMaterials = [];
        (s.formats || []).forEach(f => {
            const panel = f.panelId ? PANELS.find(p => String(p.id) === String(f.panelId)) : null;
            addSourceMaterial({
                height:Number(f.height), width:Number(f.width), qty:f.qty ?? null, margin:Number(f.margin ?? (panel ? (s.margin ?? 5) : 0)),
                priceM2:Number(f.priceM2 ?? (panel ? (panel.price_per_m2 || panel.cost || 0) : (s.price_m2 || s.sheet_cost || 0))),
                currency:f.currency || panel?.currency || s.sheet_currency || 'RUB',
                panelId:panel?.id || f.panelId || null,
                manufacturerId:panel?.manufacturer_id || null,
                label:f.label || (panel ? panelLabel(panel) : `${f.height}×${f.width} мм (свой)`)
            });
        });
        renderSourceMaterials();


        parts = (data.parts || []).map(p => ({...p, grainDirection:p.grainDirection || 'none', id: nextPartId++}));
        renderParts();

        if (data.result) { lastResult = data.result; renderResult(); renderParts(); }
    } catch (e) { alert('Ошибка загрузки: ' + e.message); }
};

/* ═══════════ АВТОСОХРАНЕНИЕ ЧЕРНОВИКА ═══════════ */
const DRAFT_STORAGE_KEY = 'calculator_cutting_draft_v1';
let draftSaveTimer = null;
let discardDraft = false;

function draftSettings() {
    return {
        loadedId,
        cuttingName:document.getElementById('cutting_name').value,
        objectName:document.getElementById('object_name').value,
        kerf:document.getElementById('kerf').value,
        margin:document.getElementById('margin').value,
        method:document.getElementById('method').value,
        priceM2:priceM2Input.value,
        cutPrice:cutPriceInput.value,
        currency:sheetCurrencyInput.value
    };
}
function saveDraft() {
    if (discardDraft) return;
    try { localStorage.setItem(DRAFT_STORAGE_KEY, JSON.stringify({settings:draftSettings(), sourceMaterials, parts, nextPartId, nextMaterialId, nextManualMaterialNumber, lastResult})); } catch (_) {}
}
function scheduleDraftSave() {
    clearTimeout(draftSaveTimer);
    draftSaveTimer = setTimeout(saveDraft, 150);
}
function restoreDraft() {
    let draft;
    try { draft = JSON.parse(localStorage.getItem(DRAFT_STORAGE_KEY) || 'null'); } catch (_) { return; }
    if (!draft?.settings) return;
    const s=draft.settings;
    loadedId=s.loadedId || null;
    document.getElementById('cutting_name').value=s.cuttingName || '';
    document.getElementById('object_name').value=s.objectName || '';
    document.getElementById('kerf').value=s.kerf ?? 4;
    document.getElementById('margin').value=s.margin ?? 5;
    document.getElementById('method').value=s.method || 'optimal';
    document.querySelector(`[name="strategy"][value="${s.method || 'optimal'}"]`)?.click();
    priceM2Input.value=s.priceM2 ?? 0;
    cutPriceInput.value=s.cutPrice ?? 250;
    sheetCurrencyInput.value=s.currency || 'RUB';
    sourceMaterials=Array.isArray(draft.sourceMaterials) ? draft.sourceMaterials : [];
    parts=Array.isArray(draft.parts) ? draft.parts.map(p=>({...p, rotate:Boolean(p.rotate), grainDirection:p.grainDirection || 'none'})) : [];
    nextPartId=Math.max(Number(draft.nextPartId)||1, ...parts.map(p=>Number(p.id)+1), 1);
    nextMaterialId=Math.max(Number(draft.nextMaterialId)||1, ...sourceMaterials.map(m=>Number(m.materialId)+1), 1);
    nextManualMaterialNumber=Number(draft.nextManualMaterialNumber)||1;
    lastResult=draft.lastResult || null;
    renderSourceMaterials(); renderParts();
    if(lastResult) renderResult();
}
function newCalculation() {
    if (!confirm('Начать новый расчёт? Текущий несохранённый черновик будет удалён.')) return;
    discardDraft=true;
    clearTimeout(draftSaveTimer);
    localStorage.removeItem(DRAFT_STORAGE_KEY);
    location.reload();
}
document.getElementById('new-calculation-btn').addEventListener('click', newCalculation);
document.addEventListener('input', scheduleDraftSave);
document.addEventListener('change', scheduleDraftSave);
document.getElementById('margin').addEventListener('change', event => {
    const margin=Math.max(0, Number(event.target.value)||0);
    sourceMaterials.forEach(material => { material.margin=margin; });
    renderSourceMaterials(); scheduleDraftSave();
});
window.addEventListener('beforeunload', saveDraft);
restoreDraft();

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
