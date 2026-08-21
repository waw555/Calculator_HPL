<?php
require_once __DIR__ . '/includes/admin_auth.php';
require_once __DIR__ . '/includes/app_header.php';
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/admin_schema.php';

ensure_calculator_tables($pdo);
ensure_organization_table($pdo);
ensure_currencies_table($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_calculation') {
    header('Content-Type: application/json; charset=utf-8');
    $payload = trim($_POST['payload_json'] ?? '');
    $decoded = json_decode($payload, true);
    if (!is_array($decoded)) {
        echo json_encode(['ok' => false, 'message' => 'Некорректные данные расчета.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $objectName = trim((string)($decoded['object_name'] ?? ''));
    $identifier = trim((string)($decoded['partition_identifier'] ?? ''));
    $title = trim($objectName . ' ' . $identifier);
    if ($title === '') {
        $title = 'Расчет от ' . date('d.m.Y H:i');
    }
    $stmt = $pdo->prepare('INSERT INTO saved_calculations (user_id, object_name, partition_identifier, title, total_amount, currency, payload_json) VALUES (:user_id, :object_name, :partition_identifier, :title, :total_amount, :currency, :payload_json)');
    $stmt->execute([
        'user_id' => (int)$_SESSION['user_id'],
        'object_name' => $objectName === '' ? null : $objectName,
        'partition_identifier' => $identifier === '' ? null : $identifier,
        'title' => $title,
        'total_amount' => (float)($decoded['total_amount'] ?? 0),
        'currency' => (string)($decoded['currency'] ?? 'RUB'),
        'payload_json' => json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
    echo json_encode(['ok' => true, 'id' => (int)$pdo->lastInsertId(), 'message' => 'Расчет сохранен.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$partitionTypes = $pdo->query('SELECT * FROM partition_types WHERE is_active = 1 ORDER BY name ASC')->fetchAll();
$parameterRows = $pdo->query('SELECT ptp.partition_type_id, ptp.parameter_id, ptp.sort_order, ptp.is_required, COALESCE(ptp.default_value_override, cp.default_value) AS default_value, cp.name, cp.note, mu.short_name AS unit_short_name
    FROM partition_type_parameters ptp
    JOIN calculation_parameters cp ON cp.id = ptp.parameter_id
    LEFT JOIN measurement_units mu ON mu.id = cp.unit_id
    WHERE cp.is_active = 1
    ORDER BY ptp.partition_type_id ASC, ptp.sort_order ASC, cp.name ASC')->fetchAll();
$parametersByType = [];
foreach ($parameterRows as $row) {
    $parametersByType[(string)$row['partition_type_id']][] = $row;
}
$manufacturers = $pdo->query('SELECT * FROM manufacturers ORDER BY full_name ASC')->fetchAll();
$panels = $pdo->query('SELECT pf.*, m.full_name AS manufacturer_name FROM panel_formats pf LEFT JOIN manufacturers m ON m.id = pf.manufacturer_id WHERE pf.is_active = 1 AND pf.is_stock_program = 1 ORDER BY m.full_name ASC, pf.decor_number ASC, pf.decor_name ASC, pf.name ASC')->fetchAll();
$panelsJson = json_encode(array_values($panels), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$suppliersList = $pdo->query('SELECT * FROM suppliers ORDER BY company_name ASC')->fetchAll();
$defaultSupplierId = null;
foreach ($suppliersList as $supplier) {
    if (trim((string)($supplier['company_name'] ?? '')) === 'Китай') {
        $defaultSupplierId = (string)$supplier['id'];
        break;
    }
}
if ($defaultSupplierId === null && $suppliersList !== []) {
    $defaultSupplierId = (string)$suppliersList[0]['id'];
}
$collections = $pdo->query('SELECT fcol.*, s.company_name AS supplier_name FROM furniture_collections fcol LEFT JOIN suppliers s ON s.id = fcol.supplier_id ORDER BY s.company_name ASC, fcol.name ASC')->fetchAll();
$furnitureCatalog = $pdo->query('SELECT pl.*, fc.name AS category_name, s.company_name AS supplier_name, fcol.name AS collection_name FROM price_list pl LEFT JOIN furniture_categories fc ON fc.id = pl.category_id LEFT JOIN suppliers s ON s.id = pl.supplier_id LEFT JOIN furniture_collections fcol ON fcol.id = pl.collection_id WHERE pl.is_active = 1 ORDER BY s.company_name ASC, fcol.name ASC, fc.name ASC, pl.material_name ASC')->fetchAll();
$currencyRates = $pdo->query('SELECT code, nominal, rate_to_rub FROM currencies')->fetchAll();
$services = $pdo->query('SELECT s.*, pts.partition_type_id, pts.sort_order AS type_sort_order FROM partition_type_services pts JOIN services s ON s.id = pts.service_id WHERE s.is_active = 1 ORDER BY pts.partition_type_id, pts.sort_order, s.name')->fetchAll();
$allServices = $pdo->query('SELECT s.*, pt.thickness FROM services s LEFT JOIN panel_thicknesses pt ON pt.id = s.thickness_id WHERE s.is_active = 1 ORDER BY s.name, pt.thickness, s.h_size, s.d_size, s.step_mm, s.id')->fetchAll();
$serviceHLabels = ['no' => 'Нет', 'le_2_5' => '≤ 2.5', '2_5_to_5' => '2.5–5', 'le_3' => '≤ 3', '3_to_6' => '3–6'];
$serviceDLabels = ['no' => 'Нет', 'le_4' => '≤ 4', '4_to_12' => '4–12', 'gt_12' => '> 12'];
$serviceStepLabels = ['no' => 'Нет', '16' => '16', '32' => '32', '64' => '64'];
$savedCalculations = $pdo->prepare('SELECT id, title, total_amount, currency, created_at FROM saved_calculations WHERE user_id = :user_id ORDER BY id DESC LIMIT 10');
$savedCalculations->execute(['user_id' => (int)$_SESSION['user_id']]);
$recentCalculations = $savedCalculations->fetchAll();
$organization = $pdo->query('SELECT * FROM organization_settings WHERE id = 1')->fetch() ?: [];
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<title>Калькулятор сантехнических кабин</title>
<style>
:root { --ink:#172033; --muted:#64748b; --line:#d9e2ec; --panel:#ffffff; --soft:#f6f9fc; --brand:#1d4ed8; --brand2:#0f766e; --accent:#f59e0b; --green:#8bc34a; --purple:#6d3aa3; --cyan:#cfeef5; }
* { box-sizing: border-box; }
body { font-family: Inter, "Segoe UI", Arial, sans-serif; background: linear-gradient(135deg, #eef7ff 0%, #f8fafc 42%, #f3f7f1 100%); margin: 0; color: var(--ink); }
.header{display:flex;align-items:center;gap:16px;flex-wrap:wrap; background: radial-gradient(circle at 10% 10%, rgba(59,130,246,.35), transparent 32%), linear-gradient(120deg, #0f172a, #1e3a8a 58%, #0f766e); color: #fff; padding: 22px 36px 30px; box-shadow: 0 18px 45px rgba(15,23,42,.18); }
.header a { color: #dbeafe; margin-right: 16px; font-weight: 700; text-decoration: none; }
.header h1 { margin: 0; font-size: clamp(24px, 3.5vw, 36px); letter-spacing: -.02em; font-weight: 900; }
.header p { margin: 0; color: #dbeafe; }
.container { max-width: 1500px; margin: 28px auto 48px; padding: 0 20px; }
.panel { background: rgba(255,255,255,.92); border: 1px solid rgba(203,213,225,.9); border-radius: 22px; padding: 24px; margin-bottom: 24px; box-shadow: 0 18px 50px rgba(15, 23, 42, 0.08); backdrop-filter: blur(12px); }
.panel h2 { margin: 0 0 18px; font-size: 26px; letter-spacing: -.02em; }
.panel h3 { margin: 18px 0 10px; }
.grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 14px; }
.columns { display: grid; grid-template-columns: minmax(420px, .95fr) minmax(520px, 1.25fr); gap: 20px; align-items: start; }
label { display: block; font-weight: 700; margin-bottom: 6px; font-size: 13px; color: #334155; }
input, select, textarea { width: 100%; padding: 11px 12px; border: 1px solid #cbd5e1; border-radius: 12px; background: #fff; color: var(--ink); transition: border-color .2s, box-shadow .2s; }
input:focus, select:focus, textarea:focus { outline: 0; border-color: #60a5fa; box-shadow: 0 0 0 4px rgba(96,165,250,.18); }
button, .button { border: 0; border-radius: 12px; padding: 11px 16px; background: linear-gradient(135deg, #2563eb, #1d4ed8); color: #fff; cursor: pointer; text-decoration: none; display: inline-block; font-weight: 800; box-shadow: 0 10px 22px rgba(37,99,235,.22); }
button.secondary { background: linear-gradient(135deg, #64748b, #475569); box-shadow: none; }
button.danger { background: linear-gradient(135deg, #ef4444, #b91c1c); }
button.success { background: linear-gradient(135deg, #16a34a, #0f766e); }
table { width: 100%; border-collapse: collapse; background: #fff; margin-top: 12px; overflow: hidden; border-radius: 14px; }
th, td { padding: 10px 11px; border-bottom: 1px solid #e5e7eb; text-align: left; vertical-align: top; font-size: 13px; }
th { background: #edf6ff; color: #0f172a; font-size: 12px; text-transform: uppercase; letter-spacing: .04em; }
.hint { color: var(--muted); font-size: 13px; margin-top: 4px; }
.label-with-help { display: flex; align-items: center; gap: 6px; width: max-content; max-width: 100%; }
.format-help { position: relative; display: inline-grid; place-items: center; width: 17px; height: 17px; border: 1px solid #94a3b8; border-radius: 50%; color: #475569; font-size: 11px; font-weight: 850; line-height: 1; cursor: help; }
.format-help__tooltip { position: absolute; z-index: 20; bottom: calc(100% + 8px); left: 50%; width: min(310px, 80vw); padding: 9px 11px; border-radius: 8px; background: #111827; color: #fff; font-size: 11px; font-weight: 500; line-height: 1.45; opacity: 0; pointer-events: none; transform: translate(-50%, 4px); transition: opacity .16s, transform .16s; box-shadow: 0 8px 24px rgba(15,23,42,.2); }
.format-help:hover .format-help__tooltip, .format-help:focus .format-help__tooltip, .format-help:focus-visible .format-help__tooltip { opacity: 1; transform: translate(-50%, 0); }
.format-help:focus-visible { outline: 3px solid rgba(79,70,229,.22); outline-offset: 2px; }
.total { font-size: 22px; font-weight: 900; }
.actions { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
.hidden { display: none !important; }
.summary-row { display: grid; grid-template-columns: 1fr auto; gap: 12px; align-items: center; padding: 14px; border: 1px solid #dbeafe; border-radius: 16px; margin-bottom: 10px; background: linear-gradient(135deg, #f8fafc, #eff6ff); }
.badge { display: inline-block; padding: 5px 10px; background: #e0f2fe; color: #075985; border-radius: 999px; font-size: 12px; font-weight: 800; margin: 2px; }
.notice { padding: 12px; border-radius: 12px; background: #ecfeff; color: #155e75; }
.calc-sheet { background: #dff4f8; border: 2px solid #9bd1da; color: #0f172a; }
.calc-title { display:flex; justify-content:space-between; align-items:flex-start; gap:16px; margin-bottom:16px; }
.calc-title h2 { margin:0; }
.pill { border-radius:999px; padding:7px 12px; background:#fff; color:#0f766e; font-weight:800; border:1px solid #99f6e4; }
.visual-grid { display:grid; grid-template-columns: 1fr 1fr; gap:18px; align-items:start; }
.data-card { background:#fff; border:2px solid #111827; border-radius:0; box-shadow:8px 8px 0 rgba(15,23,42,.12); }
.data-card h3 { margin:0; padding:9px 12px; background:#111827; color:#fff; font-size:14px; text-align:center; }
.data-card table { margin:0; border-radius:0; }
.data-card td, .data-card th { border:1px solid #111827; padding:7px; }
.yellow-cell { background:#fff200; font-weight:900; text-align:center; }
.cost-stack { display:grid; gap:0; border:3px solid #111827; background:#fff; }
.cost-line { display:grid; grid-template-columns:1fr auto; align-items:center; padding:10px 14px; font-weight:900; }
.cost-line small { display:block; font-weight:700; opacity:.9; }
.cost-line.purple { background:var(--purple); color:#fff; }
.cost-line.cyan { background:#9edbe8; }
.cost-line.green { background:#00b050; color:#07150b; }
.cost-line.orange { background:#ff8a00; }
.mini-table th { background:#b7e38a; color:#0f172a; }
.offer-document { background:#fff; border:1px solid #cbd5e1; border-radius:18px; padding:30px; box-shadow:0 22px 60px rgba(15,23,42,.10); }
.offer-head { display:grid; grid-template-columns:1fr 1.15fr; gap:26px; align-items:start; margin-bottom:24px; }
.logo-box { display:flex; gap:18px; align-items:center; }
.logo-mark { width:116px; height:82px; border:2px solid #111827; border-radius:10px; display:grid; place-items:center; font-weight:1000; text-align:center; background:linear-gradient(135deg,#fff,#f1f5f9); color:#111827; }
.logo-mark img { max-width:100%; max-height:100%; object-fit:contain; }
.org-lines { font-size:12px; color:#334155; line-height:1.55; }
.requisites { font-size:12px; line-height:1.45; }
.offer-title { text-align:center; margin:18px 0 16px; }
.offer-title h2 { margin:0 0 12px; text-transform:uppercase; font-size:24px; }
.offer-table { border:2px solid #111827; border-radius:0; }
.offer-table th, .offer-table td { border:1px solid #111827; color:#111827; }
.offer-table th { background:#e5e7eb; text-align:center; font-size:11px; }
.offer-table .green-head th { background:#93d050; font-style:italic; }
.offer-table tfoot td { font-weight:900; background:#f8fafc; }
.text-right { text-align:right; }
.text-center { text-align:center; }
.offer-summary { width:min(520px,100%); margin-left:auto; margin-top:0; border:2px solid #111827; }
.offer-summary td { border:1px solid #111827; padding:7px 10px; font-weight:800; }
.offer-empty { border:1px dashed #94a3b8; border-radius:16px; padding:20px; color:#64748b; background:#f8fafc; }
@media (max-width: 1100px) { .columns, .visual-grid, .offer-head { grid-template-columns:1fr; } }
@media print { body { background:#fff; } .header, .panel:not(.printable-offer), #result-panel, .actions, .recent-panel { display:none !important; } .container { margin:0; max-width:none; padding:0; } .printable-offer { box-shadow:none; border:0; padding:0; } .offer-document { box-shadow:none; border:0; border-radius:0; padding:8mm; } }
.tabs-bar { display:flex; gap:4px; background:rgba(255,255,255,.15); border-radius:16px; padding:5px; margin-bottom:24px; backdrop-filter:blur(8px); border:1px solid rgba(255,255,255,.2); }
.tab-btn { flex:1; padding:12px 20px; border:0; border-radius:12px; background:rgba(255,255,255,.08); color:#94a3b8; font-weight:700; font-size:15px; cursor:pointer; transition:all .2s; display:flex; align-items:center; justify-content:center; gap:8px; }
.tab-btn:hover { background:rgba(255,255,255,.16); color:#cbd5e1; }
.tab-btn.active { background:#fff; color:#1d4ed8; box-shadow:0 4px 16px rgba(15,23,42,.15); }
.tab-btn .tab-icon { font-size:18px; }
.tab-pane { display:none; }
.tab-pane.active { display:block; }
.offer-row-actions { display:flex; gap:6px; white-space:nowrap; }
.offer-table td input, .offer-table td select { width:100%; padding:4px 6px; border:1px solid #cbd5e1; border-radius:6px; font-size:12px; background:#fff; }
.offer-table td.no-edit { background:#f8fafc; }
.calculation-table-wrap{overflow:auto;border:1px solid var(--line);border-radius:11px}.calculation-table{min-width:1180px;margin:0}.calculation-table th{white-space:normal;text-align:center;font-style:italic}.calculation-table td{vertical-align:middle}.calculation-table .item-name{min-width:270px;line-height:1.55}.calculation-empty{padding:28px;text-align:center;color:var(--muted);background:#f8fafc}.calculation-toolbar{display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:16px}.cutting-maps{display:grid;gap:10px;margin-top:18px}.cutting-map{border:1px solid var(--line);border-radius:10px;background:#fff}.cutting-map summary{padding:13px 15px;cursor:pointer;font-weight:800;color:#172033}.cutting-map__body{padding:0 15px 15px}.cutting-map svg{display:block;width:100%;max-width:760px;height:auto;background:#f8fafc;border:1px solid #cbd5e1}.calculation-total{margin-top:14px;text-align:right;font-size:18px;font-weight:900}

/* Visual system shared with calculator_cutting.php */
:root { --ink:#0f172a; --muted:#64748b; --line:#dfe6ef; --panel:#fff; --soft:#f6f8fb; --brand:#4f46e5; --accent:#e9164d; --dark:#111a2d; }
body { margin:0; background:var(--soft); color:var(--ink); font-family:'Inter',Arial,sans-serif; }
.container { max-width:1440px; margin:20px auto 40px; padding:0 10px; }
.panel { margin-bottom:24px; padding:28px; background:#fff; border:1px solid var(--line); border-radius:16px; box-shadow:0 2px 5px rgba(15,23,42,.07); backdrop-filter:none; }
.panel h2 { display:flex; align-items:center; gap:9px; margin:0 0 18px; padding-bottom:13px; border-bottom:1px solid #e8edf4; color:var(--ink); font-size:17px; font-weight:850; letter-spacing:0; }
.panel h2:before { content:'✦'; color:var(--accent); font-size:18px; }
.panel h3 { margin:18px 0 10px; color:#172033; font-size:14px; }
.grid { gap:16px; }
label { color:#344258; font-weight:600; }
input,select,textarea { min-height:38px; padding:9px 11px; border-radius:8px; color:var(--ink); font:inherit; }
input[type="number"] { appearance:textfield; -moz-appearance:textfield; }
input[type="number"]::-webkit-inner-spin-button,input[type="number"]::-webkit-outer-spin-button { margin:0; -webkit-appearance:none; }
input:focus,select:focus,textarea:focus { border-color:#818cf8; box-shadow:0 0 0 3px rgba(79,70,229,.12); }
button,.button { display:inline-flex; align-items:center; justify-content:center; min-height:38px; padding:10px 16px; border-radius:9px; background:var(--brand); box-shadow:none; font-size:13px; font-weight:700; transition:transform .16s,filter .16s,box-shadow .16s; }
button:hover,.button:hover { filter:brightness(.97); box-shadow:0 3px 8px rgba(15,23,42,.13); }
button:active,.button:active { transform:translateY(1px); }
button:focus-visible,.button:focus-visible { outline:3px solid rgba(79,70,229,.22); outline-offset:2px; }
button.secondary { background:#64748b; }
button.danger,button.success { background:var(--accent); }
.hint { font-size:12px; line-height:1.45; }
.summary-row { border-color:var(--line); border-radius:10px; background:#f8fafc; }
.badge { background:#eef0ff; color:#4338ca; font-size:11px; }
.notice { border:1px solid #fecdd3; border-radius:10px; background:#fff6f8; color:#9f1239; }
table { border-radius:0; }
th,td { padding:10px 12px; border-bottom-color:#e7edf4; color:#334155; font-size:12px; }
th { background:#f0f4f8; color:#07152d; font-size:11px; font-weight:800; letter-spacing:.02em; }
tbody tr:hover td { background:#fbfcfe; }
.tabs-bar { gap:5px; margin-bottom:24px; padding:5px; border:1px solid var(--line); border-radius:13px; background:#fff; box-shadow:0 2px 5px rgba(15,23,42,.05); backdrop-filter:none; }
.tab-btn { min-height:42px; padding:10px 18px; border-radius:9px; background:transparent; color:#71809a; box-shadow:none; font-size:13px; }
.tab-btn:hover { background:#f1f4f8; color:#263650; box-shadow:none; }
.tab-btn.active { background:var(--dark); color:#fff; box-shadow:0 2px 5px rgba(15,23,42,.16); }
.tab-btn .tab-icon { color:#ff4f78; font-size:16px; }
.calc-sheet { background:#fff; border:1px solid var(--line); }
.calc-title { margin-bottom:18px; }
.pill { flex:none; padding:7px 11px; border:1px solid #fecdd3; background:#fff6f8; color:#be123c; font-size:11px; }
.data-card { overflow:auto; border:1px solid var(--line); border-radius:11px; box-shadow:none; }
.data-card h3 { padding:11px 13px; background:var(--dark); color:#fff; font-size:13px; text-align:left; }
.data-card h3:before { content:'✦'; margin-right:8px; color:#ff4f78; }
.data-card table { min-width:520px; }
.data-card td,.data-card th { padding:9px 10px; border:0; border-bottom:1px solid #e7edf4; }
.value-with-unit{display:flex;align-items:center;justify-content:flex-start;gap:6px;white-space:nowrap}.value-with-unit input{width:76px;min-width:0}.cost-input{width:104px;min-width:86px;text-align:right;background:#fffbe6;border-color:#e8c95b;font-variant-numeric:tabular-nums}.cost-input:focus{background:#fff;border-color:#e9164d;box-shadow:0 0 0 2px rgba(233,22,77,.12)}
.yellow-cell { background:#fff6f8; color:#9f1239; }
.cost-stack { overflow:hidden; border:0; border-radius:11px; background:var(--dark); color:#fff; }
.cost-line { gap:18px; padding:12px 14px; border-bottom:1px solid #263148; font-size:12px; }
.cost-line:last-child { border-bottom:0; }
.cost-line strong { color:#fff; text-align:right; white-space:nowrap; }
.cost-line small { margin-top:2px; color:#91a0bd; font-size:10px; font-weight:600; }
.cost-line.purple { background:#182238; color:#ff4f78; }
.cost-line.cyan,.cost-line.green { background:transparent; color:#fff; }
.cost-line.orange { background:var(--accent); color:#fff; font-size:13px; }
.mini-table th { background:#f0f4f8; color:#07152d; }
.offer-document { border-color:var(--line); border-radius:12px; box-shadow:none; }
.offer-title h2 { display:block; margin:0 0 12px; padding:0; border:0; font-size:24px; }
.offer-title h2:before { content:none; }
.offer-table .green-head th { background:#dfe6ef; }
.offer-empty { border-radius:10px; }
@media (max-width:600px) { .container { padding:0; } .panel { padding:18px; } .grid { grid-template-columns:1fr; } .calc-title { flex-direction:column; } .tabs-bar { border-radius:10px; } .tab-btn { padding-inline:13px; } .tab-btn .tab-icon { display:none; } .recent-panel { overflow-x:auto; } }
@media (prefers-reduced-motion:reduce) { *,*:before,*:after { scroll-behavior:auto!important; transition:none!important; } }
@media print { .tabs-bar { display:none!important; } .printable-offer { display:block!important; } }

/* Shower partition configurator */
.shower-config{margin-top:20px;padding-top:20px;border-top:1px solid #e8edf4}.shower-config__intro{display:flex;align-items:flex-start;justify-content:space-between;gap:20px;margin-bottom:16px}.shower-config__intro h3{margin:0;font-size:16px}.shower-config__intro p{max-width:760px;margin:5px 0 0;color:#64748b;font-size:12px;line-height:1.5}.shower-badge{flex:none;padding:6px 10px;border-radius:999px;background:#fff1f4;color:#be123c;font-size:10px;font-weight:850;text-transform:uppercase}.shower-workspace{display:block}.shower-steps{display:grid;gap:12px}.shower-step{padding:16px;border:1px solid #dfe6ef;border-radius:12px;background:#f8fafc}.shower-step__title{display:flex;align-items:center;gap:9px;margin-bottom:13px;color:#172033;font-size:13px;font-weight:850}.shower-step__number{display:grid;place-items:center;width:24px;height:24px;border-radius:7px;background:#111a2d;color:#ff4f78;font-size:10px}.shower-fields{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px}.shower-check{display:flex;align-items:center;gap:8px;min-height:38px;margin:0;padding:8px 10px;border:1px solid #d7e0eb;border-radius:8px;background:#fff}.shower-check input{width:16px;min-height:16px;margin:0;accent-color:#e9164d}.shower-check span{font-size:12px;font-weight:700}.hardware-picker{margin-top:14px;overflow:auto;border:1px solid #dfe6ef;border-radius:10px;background:#fff}.hardware-picker__head,.hardware-role{display:grid;grid-template-columns:minmax(170px,.85fr) minmax(260px,1.8fr) 90px;gap:10px;align-items:center;padding:10px 12px}.hardware-picker__head{background:#f0f4f8;color:#64748b;font-size:10px;font-weight:800;text-transform:uppercase}.hardware-role{border-top:1px solid #e8edf4}.hardware-role__name{color:#172033;font-size:12px;font-weight:750}.hardware-role__name small{display:block;margin-top:3px;color:#8a99b1;font-size:10px;font-weight:500}.hardware-role select{min-height:34px;font-size:11px}.hardware-role__qty{text-align:right;color:#172033;font-size:12px;font-weight:850}.service-adder{display:grid;grid-template-columns:minmax(220px,1fr) auto;gap:10px}.service-adder button{white-space:nowrap}.extra-service-list{display:grid;gap:8px;margin-top:10px}.extra-service{display:grid;grid-template-columns:minmax(180px,1fr) 130px auto;gap:10px;align-items:end;padding:10px;border:1px solid #dfe6ef;border-radius:9px;background:#fff}.extra-service label{margin:0}.extra-service button{min-height:38px;background:#64748b}.custom-format{grid-column:span 2;grid-template-columns:repeat(2,minmax(150px,1fr));gap:12px;padding:12px;border:1px dashed #e9164d;border-radius:10px;background:#fff8fa}.custom-format:not(.field-hidden){display:grid}#shower-fascia-fields:not(.field-hidden){display:grid;grid-template-columns:repeat(2,minmax(150px,1fr));gap:12px}.shower-note{margin-top:10px;padding:10px 12px;border-left:3px solid #e9164d;background:#fff6f8;color:#7f1d3a;font-size:11px;line-height:1.45}.field-hidden{display:none!important}@media(max-width:620px){.shower-config__intro{flex-direction:column}.hardware-picker__head{display:none}.hardware-role{grid-template-columns:1fr}.hardware-role__qty{text-align:left}.service-adder,.extra-service{grid-template-columns:1fr}}
.calculation-filters{grid-template-columns:repeat(6,minmax(0,1fr))}.calculation-filters #collection-field{min-width:0}.calculation-filters .custom-format{grid-column:span 2}@media(max-width:1100px){.calculation-filters{grid-template-columns:repeat(3,minmax(0,1fr))}}@media(max-width:760px){.calculation-filters{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:600px){.calculation-filters{grid-template-columns:1fr}.calculation-filters .custom-format{grid-column:span 1}}
</style>
<?php echo app_header_styles(); ?>
</head>
<body>
<?php render_app_header(); ?>
<main class="container">
    <div class="tabs-bar">
        <button class="tab-btn active" onclick="switchTab('calc')" id="tab-btn-calc">
            <span class="tab-icon">🧮</span> Калькулятор
        </button>
        <button class="tab-btn" onclick="switchTab('calculation')" id="tab-btn-calculation">
            <span class="tab-icon">📋</span> Расчёт <span id="calculation-count" class="badge">0</span>
        </button>
        <button class="tab-btn" onclick="switchTab('offer')" id="tab-btn-offer">
            <span class="tab-icon">📄</span> Коммерческое предложение
        </button>
        <button class="tab-btn" onclick="switchTab('history')" id="tab-btn-history">
            <span class="tab-icon">🕓</span> История
        </button>
    </div>

    <!-- ═══════════ ВКЛАДКА 1: РАСЧЁТ ═══════════ -->
    <div class="tab-pane active" id="tab-calc">

    <section class="panel">
        <h2>Объект и идентификатор</h2>
        <div class="grid" style="grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));">
            <div><label for="object_name">Объект</label><input id="object_name" name="object_name" placeholder="Например: БЦ Север"></div>
            <div><label for="partition_identifier">Идентификатор перегородки</label><input id="partition_identifier" name="partition_identifier" placeholder="Например: П-01"></div>
        </div>
    </section>

    <section class="panel">
        <h2>Параметры расчёта</h2>
        <div class="grid calculation-filters">
            <div><label for="manufacturer_id">Производитель</label><select id="manufacturer_id"><option value="0">Любой производитель</option><?php foreach ($manufacturers as $manufacturer): ?><option value="<?php echo e((string)$manufacturer['id']); ?>"><?php echo e($manufacturer['full_name']); ?></option><?php endforeach; ?></select></div>
            <div><label for="decor_input">Декор</label><select id="decor_input"><option value="">— Выберите декор —</option><?php foreach ($panels as $panel): ?><option value="<?php echo e((string)$panel['id']); ?>" data-mfr="<?php echo e((string)($panel['manufacturer_id'] ?? 0)); ?>" data-stock="1"><?php echo e(trim(($panel['decor_number'] ?? '') . ' ' . ($panel['decor_name'] ?? ''))); ?></option><?php endforeach; ?></select></div>
            <div><label for="panel_format_id" class="label-with-help">Формат листа <span class="format-help" tabindex="0" aria-label="Подсказка о выборе формата">?<span class="format-help__tooltip" role="tooltip">«Любой» подберёт формат с наименьшим отходом; «Свой» откроет ручной ввод.</span></span></label><select id="panel_format_id"><option value="">— Сначала выберите декор —</option></select></div>
            <div class="partition-type-field"><label for="partition_type_id">Тип перегородки</label><select id="partition_type_id"><option value="0">Выберите тип</option><?php foreach ($partitionTypes as $type): ?><option value="<?php echo e((string)$type['id']); ?>"><?php echo e($type['name']); ?></option><?php endforeach; ?></select></div>
            <div><label for="supplier_id">Производитель фурнитуры</label><select id="supplier_id"><?php foreach ($suppliersList as $sup): ?><option value="<?php echo e((string)$sup['id']); ?>"<?php echo (string)$sup['id'] === $defaultSupplierId ? ' selected' : ''; ?>><?php echo e($sup['company_name']); ?></option><?php endforeach; ?></select></div>
            <div id="collection-field" class="hidden"><label for="collection_id">Серия</label><select id="collection_id"><?php foreach ($collections as $col): ?><option value="<?php echo e((string)$col['id']); ?>" data-supplier="<?php echo e((string)($col['supplier_id'] ?? 0)); ?>"><?php echo e($col['name']); ?><?php if (!empty($col['supplier_name'])): ?> (<?php echo e($col['supplier_name']); ?>)<?php endif; ?></option><?php endforeach; ?></select><div class="hint">Фильтр по серии.</div></div>
            <div id="custom-format-fields" class="custom-format field-hidden"><div><label for="custom_sheet_width">Ширина своего листа, мм</label><input id="custom_sheet_width" type="number" min="1" step="1" value="1830"></div><div><label for="custom_sheet_height">Длина своего листа, мм</label><input id="custom_sheet_height" type="number" min="1" step="1" value="4320"></div></div>
        </div>
        <div id="dynamic-parameters" class="grid" style="margin-top:14px"></div>
        <div id="shower-config" class="shower-config hidden">
            <div class="shower-config__intro">
                <div><h3>Конфигурация душевой перегородки</h3><p>Выберите тип размещения и задайте размеры. Выберите материал и фурнитуру, чтобы рассчитать состав и стоимость изделия.</p></div>
                <span class="shower-badge">Душевая перегородка</span>
            </div>
            <div class="shower-workspace">
                <div class="shower-steps">
                    <div class="shower-step">
                        <div class="shower-step__title"><span class="shower-step__number">01</span>Геометрия и состав</div>
                        <div class="shower-fields">
                            <div><label for="shower_layout_type">Тип размещения</label><select id="shower_layout_type"><option value="built_in">Прямая</option><option value="corner">Угловая</option><option value="freestanding">П-образная</option></select></div>
                            <div><label for="shower_partition_count">Количество кабин</label><input id="shower_partition_count" type="number" min="2" step="1" value="2"></div>
                            <div><label for="shower_panel_count">Количество перегородок</label><input id="shower_panel_count" type="number" min="1" step="1" value="1"><div id="shower-panel-count-hint" class="hint">Количество HPL-панелей для расчёта.</div></div>
                            <div><label for="shower_room_width">Длина фасада, мм</label><input id="shower_room_width" type="number" min="1" step="1" value="3000"></div>
                            <div><label for="shower_depth">Глубина перегородки, мм</label><input id="shower_depth" type="number" min="1" step="1" value="1000"></div>
                            <div><label for="shower_height">Высота перегородки, мм</label><input id="shower_height" type="number" min="1" step="1" value="2000"></div>
                            <div id="shower-fascia-fields" class="field-hidden"><div><label for="shower_fascia_width">Ширина перемычки, мм</label><input id="shower_fascia_width" type="number" min="1" step="1" value="200"></div><div><label for="shower_fascia_height">Высота перемычки, мм</label><input id="shower_fascia_height" type="number" min="1" step="1" value="2000"><div class="hint">По умолчанию равна высоте перегородки.</div></div></div>
                            <div id="shower-door-count-fields" class="field-hidden"><label for="shower_door_count">Количество дверей</label><input id="shower_door_count" type="number" min="1" step="1" value="1"></div>
                            <div id="shower-door-width-fields" class="field-hidden"><label for="shower_door_width">Ширина двери, мм</label><input id="shower_door_width" type="number" min="1" step="1" value="700"></div>
                            <div id="shower-door-height-fields" class="field-hidden"><label for="shower_door_height">Высота двери, мм</label><input id="shower_door_height" type="number" min="1" step="1" value="1900"></div>
                        </div>
                    </div>
                    <div class="shower-step">
                        <div class="shower-step__title"><span class="shower-step__number">02</span>Крепление панели</div>
                        <div class="shower-fields">
                            <div><label for="shower_floor_mount">К полу</label><select id="shower_floor_mount"><option value="leg">Ножка</option><option value="profile">П-профиль</option><option value="angle">Уголок</option></select></div>
                            <div><label for="shower_wall_mount">К стене</label><select id="shower_wall_mount"><option value="profile">П-профиль</option><option value="angle">Уголок</option></select></div>
                            <div><label for="shower_ceiling_mount">К потолку</label><select id="shower_ceiling_mount"><option value="none">Нет</option><option value="profile">П-профиль</option><option value="angle">Уголок</option></select></div>
                            <div id="shower-top-support-fields"><label for="shower_top_support">Верхняя связь</label><select id="shower_top_support"><option value="pipe">Труба</option><option value="aluminium_profile">Профиль алюминиевый</option></select><div class="hint">Показывается, когда крепление к потолку не используется.</div></div>
                            <div id="shower-angle-fields" class="field-hidden"><label for="shower_angle_sides">Уголки относительно панели</label><select id="shower_angle_sides"><option value="2">С двух сторон</option><option value="1">С одной стороны</option></select><div class="hint">100 мм от краёв, далее шаг не более 500 мм.</div></div>
                        </div>
                    </div>
                    <div class="shower-step">
                        <div class="shower-step__title"><span class="shower-step__number">03</span>Раскрой</div>
                        <div class="shower-fields">
                            <div><label for="shower_kerf">Пропил, мм</label><input id="shower_kerf" type="number" min="0" step="0.1" value="4"></div>
                            <div><label for="shower_margin">Торцевание листа, мм</label><input id="shower_margin" type="number" min="0" step="0.1" value="5"></div>
                            <label class="shower-check"><input id="shower_allow_rotation" type="checkbox"><span>Разрешить поворот деталей на листе</span></label>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="actions" style="margin-top:18px">
            <button id="calculate-btn" class="hidden" type="button">Рассчитать</button>
            <button id="save-draft-btn" type="button" class="secondary">Сохранить</button>
            <button id="export-excel-btn" type="button" class="success">Экспорт в Excel</button>
            <button id="export-pdf-btn" type="button" class="success">Экспорт в PDF</button>
            <span id="save-message" class="hint"></span>
        </div>
    </section>

    <section id="result-panel" class="panel calc-sheet hidden">
        <div class="calc-title">
            <div>
                <h2 id="calculation-heading">Расчет кабины</h2>
                <div class="hint" id="calculation-subtitle">Проверьте состав изделия, фурнитуру, материалы и услуги.</div>
            </div>
            <span class="pill">Детальный расчет</span>
        </div>
        <div class="visual-grid">
            <div>
                <div class="data-card">
                    <h3>Фурнитура</h3>
                    <table><thead><tr><th>Позиция</th><th>Кол-во</th><th>Цена</th><th>Стоимость</th></tr></thead><tbody id="hardware-body"></tbody></table>
                </div>
                <div class="cost-stack" style="margin-top:18px">
                    <div class="cost-line purple"><span>DISCOUNT %</span><strong>₽</strong></div>
                    <div class="cost-line cyan"><span>Фурнитура <small>комплектующие</small></span><strong id="hardware-total-card">0,00 ₽</strong></div>
                    <div class="cost-line cyan"><span>HPL <small>панели и раскрой</small></span><strong id="material-total-card">0,00 ₽</strong></div>
                    <div class="cost-line green"><span>Производство <small>услуги</small></span><strong id="services-total-card">0,00 ₽</strong></div>
                    <div class="cost-line"><span>ИТОГО за проект</span><strong id="project-total-card">0,00 ₽</strong></div>
                    <div class="cost-line orange"><span>ИТОГО за кабину</span><strong id="grand-total">0,00 ₽</strong></div>
                </div>
            </div>
            <div>
                <div class="data-card">
                    <h3>HPL / изделия</h3>
                    <table><thead><tr><th>Изделие</th><th>Кол-во</th><th>Размеры, мм</th><th>Площадь</th><th>Стоимость</th></tr></thead><tbody id="products-body"></tbody></table>
                </div>
                <p class="hint" id="products-summary"></p>
                <div class="data-card" style="margin-top:18px">
                    <h3>Производство и услуги</h3>
                    <table class="mini-table"><thead><tr><th>Наименование</th><th>Объем</th><th>Цена из БД</th><th>Стоимость</th></tr></thead><tbody id="services-body"></tbody></table>
                    <div class="service-adder" style="margin-top:12px"><select id="result-service-select" aria-label="Дополнительная услуга"><option value="">— Добавить услугу —</option><?php foreach ($allServices as $service): ?><option value="<?php echo e((string)$service['id']); ?>"><?php echo e($service['name']); ?> · Код: <?php echo e((string)($service['nomenclature'] ?: '—')); ?> · Толщ.: <?php echo !empty($service['thickness']) ? e(rtrim(rtrim((string)$service['thickness'], '0'), '.') . ' мм') : '—'; ?> · h: <?php echo e($serviceHLabels[$service['h_size'] ?? 'no'] ?? 'Нет'); ?> · d: <?php echo e($serviceDLabels[$service['d_size'] ?? 'no'] ?? 'Нет'); ?> · Шаг: <?php echo e($serviceStepLabels[$service['step_mm'] ?? 'no'] ?? 'Нет'); ?> · <?php echo e(number_format((float)$service['price'], 2, ',', ' ')); ?> <?php echo e(app_currency_symbol((string)$service['currency'])); ?> / <?php echo e($service['unit']); ?></option><?php endforeach; ?></select><button id="result-service-add" type="button">Добавить</button></div>
                    <p class="hint">Дополнительные услуги добавляются после основного расчёта. Количество можно изменить в таблице.</p>
                </div>
                <table><tbody id="totals-body"></tbody></table>
            </div>
        </div>
        <div class="actions" style="margin-top:20px"><button id="save-result-btn" type="button">Сохранить расчет</button><button id="add-calculation-btn" type="button" class="success">Добавить в расчёт</button></div>
    </section>

    </div><!-- /tab-calc -->

    <!-- ═══════════ ВКЛАДКА: СВОДНЫЙ РАСЧЁТ ═══════════ -->
    <div class="tab-pane" id="tab-calculation">
        <section class="panel">
            <div class="calculation-toolbar">
                <div><h2 style="margin-bottom:6px">Расчёт перегородок</h2><div class="hint">Все добавленные в расчёт перегородки и их итоговая стоимость.</div></div>
                <div class="actions"><button id="save-calculation-btn" type="button">Сохранить</button><button id="cutting-map-btn" type="button" class="secondary">Карта раскроя</button><button id="create-offer-btn" type="button" class="success">Создать коммерческое предложение</button><span id="calculation-save-message" class="hint" role="status"></span></div>
            </div>
            <div id="calculation-list"></div>
            <div id="calculation-maps" class="cutting-maps"></div>
        </section>
    </div>

    <!-- ═══════════ ВКЛАДКА: КОММЕРЧЕСКОЕ ПРЕДЛОЖЕНИЕ ═══════════ -->
    <div class="tab-pane" id="tab-offer">

    <section class="panel printable-offer">
        <div class="offer-document">
            <div class="offer-head">
                <div class="logo-box">
                    <div class="logo-mark"><?php if (!empty($organization['logo_path'])): ?><img src="<?php echo e($organization['logo_path']); ?>" alt="Логотип"><?php else: ?>DEKO<br>TECH<?php endif; ?></div>
                    <div class="org-lines">
                        <strong><?php echo e((string)(($organization['short_name'] ?? '') ?: ($organization['full_name'] ?? '') ?: 'DEKOTECH')); ?></strong><br>
                        Полноценный сервис от проекта до монтажа<br>
                        Долгосрочные партнерские отношения<br>
                        Стремление к функциональности и красоте<br>
                        Лучшие материалы и высокая культура монтажа
                    </div>
                </div>
                <div class="requisites">
                    <strong><?php echo e((string)(($organization['full_name'] ?? '') ?: 'ООО «Декотек»')); ?></strong><br>
                    <?php echo e((string)trim(($organization['postal_code'] ?? '') . ' ' . ($organization['region'] ?? '') . ' ' . ($organization['city'] ?? '') . ' ' . ($organization['address'] ?? ''))); ?><br>
                    Телефон: <?php echo e((string)(($organization['phone'] ?? '') ?: '—')); ?><br>
                    E-mail: <?php echo e((string)(($organization['email'] ?? '') ?: '—')); ?><?php if (!empty($organization['website'])): ?><br>Web: <?php echo e((string)$organization['website']); ?><?php endif; ?>
                    <?php if (!empty($organization['inn'])): ?><br>ИНН <?php echo e((string)$organization['inn']); ?><?php endif; ?><?php if (!empty($organization['ogrn'])): ?>, ОГРН <?php echo e((string)$organization['ogrn']); ?><?php endif; ?><?php if (!empty($organization['bank_name'])): ?><br><?php echo e((string)$organization['bank_name']); ?><?php endif; ?>
                </div>
            </div>
            <div class="offer-title">
                <div class="hint" id="offer-date">Дата КП: —</div>
                <h2>Коммерческое предложение</h2>
                <p><strong>Уважаемые партнеры!</strong></p>
                <p class="hint">Предлагаем вашему вниманию индивидуальное коммерческое предложение на изготовление и поставку сантехнических перегородок.</p>
            </div>
            <div id="offer-list"><div class="offer-empty">Добавьте один или несколько расчетов перегородок в коммерческое предложение.</div></div>
        </div>
    </section>

    </div><!-- /tab-offer -->

    <!-- ═══════════ ВКЛАДКА 3: ИСТОРИЯ ═══════════ -->
    <div class="tab-pane" id="tab-history">

    <section class="panel recent-panel">
        <h2>История расчётов и коммерческих предложений</h2>
        <table>
            <thead>
                <tr><th>ID</th><th>Название</th><th>Сумма</th><th>Дата</th><th>Действия</th></tr>
            </thead>
            <tbody>
                <?php foreach ($recentCalculations as $calc): ?>
                <tr>
                    <td><?php echo e((string)$calc['id']); ?></td>
                    <td><?php echo e($calc['title']); ?></td>
                    <td><?php echo e(number_format((float)$calc['total_amount'], 2, ',', ' ') . ' ' . app_currency_symbol((string)$calc['currency'])); ?></td>
                    <td><?php echo e($calc['created_at']); ?></td>
                    <td><button type="button" class="secondary" style="padding:6px 12px;font-size:12px;" onclick="loadCalculation(<?php echo e((string)$calc['id']); ?>)">Открыть</button></td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$recentCalculations): ?>
                <tr><td colspan="5">Сохранённых расчётов пока нет.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </section>

    </div><!-- /tab-history -->

</main>
<script src="scripts/cutting_optimizer.js"></script>
<script src="scripts/shower_partition_calculator.js"></script>
<script>
const partitionTypes = <?php echo json_encode($partitionTypes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
const parametersByType = <?php echo json_encode($parametersByType, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
const panels = <?php echo json_encode($panels, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
const furnitureCatalog = <?php echo json_encode(array_values($furnitureCatalog), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
const currencyRates = <?php echo json_encode(array_values($currencyRates), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
const services = <?php echo json_encode($services, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
const allServices = <?php echo json_encode($allServices, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
const formatter = new Intl.NumberFormat('ru-RU', {minimumFractionDigits: 2, maximumFractionDigits: 2});
const typeSelect = document.getElementById('partition_type_id');
const paramsNode = document.getElementById('dynamic-parameters');
const calculateBtn = document.getElementById('calculate-btn');
const resultPanel = document.getElementById('result-panel');
let currentCalculation = null;
let offerItems = [];
let calculationItems = [];
let editingCalculationIndex = null;
try { calculationItems = JSON.parse(localStorage.getItem('septic-calculation-items') || '[]'); } catch (_) { calculationItems = []; }
let partitionCounter = 0;
function money(value, currency = 'RUB') {
    const symbol = ({RUR: '₽', RUB: '₽', EUR: '€', USD: '$'})[currency] || currency;
    return `${formatter.format(Number(value) || 0)} ${symbol}`;
}
function servicePriceRub(service) {
    const code = String(service.currency || 'RUB').toUpperCase();
    const price = Number(service.price || 0);
    if (code === 'RUB') return price;
    const rate = currencyRates.find(row => String(row.code).toUpperCase() === code);
    return rate ? price * Number(rate.rate_to_rub || 0) / Math.max(1, Number(rate.nominal || 1)) : price;
}
function numberValue(id) { return parseFloat(document.getElementById(id)?.value || '0') || 0; }
function escapeHtml(value) { return String(value ?? '').replace(/[&<>'"]/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[ch])); }
function selectedPanel() {
    const panelId = document.getElementById('decor_input').value;
    if (panelId) return panels.find(p => String(p.id) === String(panelId)) || null;
    const manufacturerId = Number(document.getElementById('manufacturer_id').value || 0);
    return panels.find(p => !manufacturerId || Number(p.manufacturer_id || 0) === manufacturerId) || null;
}

/* Фильтрация декоров по производителю */
function filterDecors() {
    const mfrId = Number(document.getElementById('manufacturer_id').value || 0);
    const sel = document.getElementById('decor_input');
    const savedVal = sel.value;
    Array.from(sel.options).forEach(opt => {
        if (!opt.value) return;
        const optMfr = Number(opt.dataset.mfr || 0);
        opt.hidden = mfrId > 0 && optMfr > 0 && optMfr !== mfrId;
    });
    if (sel.selectedOptions[0]?.hidden) sel.value = '';
}
document.getElementById('manufacturer_id').addEventListener('change', filterDecors);
filterDecors();

function renderParameters() {
    const typeId = typeSelect.value;
    const params = parametersByType[typeId] || [];
    paramsNode.innerHTML = '';
    const isShower = (typeSelect.selectedOptions[0]?.textContent || '').toLocaleLowerCase('ru-RU').includes('душ');
    if (isShower) {
        calculateBtn.classList.toggle('hidden', typeId === '0');
        return;
    }
    params.forEach(param => {
        const code = `param_${param.parameter_id}`;
        const div = document.createElement('div');
        div.innerHTML = `<label for="${code}">${escapeHtml(param.name)}${param.is_required == 1 ? ' *' : ''}</label><input id="${code}" data-param-name="${escapeHtml(param.name)}" data-unit="${escapeHtml(param.unit_short_name || '')}" type="number" step="0.001" value="${escapeHtml(param.default_value || '')}" ${param.is_required == 1 ? 'required' : ''}><div class="hint">${escapeHtml(param.note || param.unit_short_name || '')}</div>`;
        paramsNode.append(div);
    });
    calculateBtn.classList.toggle('hidden', typeId === '0');
}
function collectInputs() {
    const dynamic = {};
    paramsNode.querySelectorAll('input').forEach(input => { dynamic[input.dataset.paramName] = {value: parseFloat(input.value || '0') || 0, unit: input.dataset.unit || ''}; });
    let identifier = document.getElementById('partition_identifier').value.trim();
    if (!identifier) {
        partitionCounter++;
        identifier = '№' + partitionCounter;
        document.getElementById('partition_identifier').value = identifier;
    }
    return {
        object_name: document.getElementById('object_name').value.trim(),
        partition_identifier: identifier,
        partition_type_id: typeSelect.value,
        partition_type_name: typeSelect.selectedOptions[0]?.textContent || '',
        manufacturer_id: document.getElementById('manufacturer_id').value,
        manufacturer_name: document.getElementById('manufacturer_id').selectedOptions[0]?.textContent || '',
        decor: document.getElementById('decor_input').value.trim(),
        supplier_id: document.getElementById('supplier_id').value,
        supplier_name: document.getElementById('supplier_id').selectedOptions[0]?.textContent || '',
        collection_id: document.getElementById('collection_id').value,
        collection_name: document.getElementById('collection_id').selectedOptions[0]?.textContent || '',
        parameters: dynamic
    };
}
function findParam(inputs, names, fallback) {
    const entry = Object.entries(inputs.parameters).find(([name]) => names.some(alias => name.toLowerCase().includes(alias)));
    return entry ? entry[1].value : fallback;
}
function calculate() {
    const inputs = collectInputs();
    const length = findParam(inputs, ['длина', 'фасад', 'width'], 1000);
    const height = findParam(inputs, ['высота', 'height'], 2000);
    const depth = findParam(inputs, ['глубина', 'depth'], 1200);
    const doors = Math.max(1, Math.round(findParam(inputs, ['двер'], 1)));
    const panel = selectedPanel();
    const areaM2 = Math.max(0, length * height / 1000000);
    const sheets = panel ? Math.max(1, Math.ceil(areaM2 / ((Number(panel.width_mm) * Number(panel.height_mm)) / 1000000))) : 0;
    const materialPrice = panel ? Number(panel.price_per_sheet || (Number(panel.price_per_m2 || 0) * Number(panel.width_mm || 0) * Number(panel.height_mm || 0) / 1000000)) : 0;
    const materialTotal = sheets * materialPrice;
    const usedArea = areaM2;
    const sheetArea = panel ? sheets * Number(panel.width_mm) * Number(panel.height_mm) / 1000000 : 0;
    const wasteArea = Math.max(0, sheetArea - usedArea);
    const hardware = [];
    const hardwareTotal = hardware.reduce((sum, item) => sum + item.sum, 0);
    const products = [{name: inputs.partition_type_name || 'Перегородка', quantity: doors, length, height, depth, area: areaM2, size: `${Math.round(length / doors)}×${Math.round(height)} мм`, price: materialPrice, currency: panel?.currency || 'RUB', sum: materialTotal}];
    const serviceRows = services.filter(service => String(service.partition_type_id) === String(inputs.partition_type_id)).map(service => {
        const volume = String(service.unit || '').includes('м') ? areaM2 : doors;
        const price = servicePriceRub(service);
        const sum = volume * price;
        return {name: service.name, volume, unit: service.unit, price, currency: 'RUB', sum};
    });
    const servicesTotal = serviceRows.reduce((sum, item) => sum + item.sum, 0);
    const wasteCost = panel && sheetArea > 0 ? wasteArea * (materialTotal / sheetArea) : 0;
    const total = materialTotal + hardwareTotal + servicesTotal;
    currentCalculation = {id: Date.now(), ...inputs, length, height, depth, doors, panel, products, hardware, services: serviceRows, totals: {hardwareTotal, materialTotal, servicesTotal, wasteCost, total, areaM2, sheets, wasteArea}, total_amount: total, currency: 'RUB'};
    renderCalculation(currentCalculation);
}
function renderCalculation(calc) {
    const panelTitle = calc.panel ? `${calc.panel.name} ${calc.panel.decor_number || ''} ${calc.panel.decor_name || ''}`.trim() : 'Панель не выбрана';
    document.getElementById('calculation-heading').textContent = `${calc.partition_type_name || 'Перегородка'} ${calc.manufacturer_name ? calc.manufacturer_name : ''}`.trim();
    document.getElementById('calculation-subtitle').textContent = `Объект: ${calc.object_name || 'не указан'} · Позиция: ${calc.partition_identifier || 'без идентификатора'} · Декор: ${calc.decor || panelTitle}`;
    document.getElementById('hardware-body').innerHTML = calc.hardware.length ? calc.hardware.map((item, index) => {
        const options = (item.options || []).map(option => `<option value="${escapeHtml(option.id)}" ${String(option.id) === String(item.id) ? 'selected' : ''}>${escapeHtml(option.label)}</option>`).join('');
        const selector = options ? `<select data-hardware-item="${index}" aria-label="Выбрать фурнитуру для ${escapeHtml(item.roleLabel || item.name)}">${item.id ? '' : '<option value="">— Выберите фурнитуру —</option>'}${options}</select>` : escapeHtml(item.name);
        return `<tr><td>${selector}<div class="hint">${escapeHtml(item.category)}</div></td><td><span class="value-with-unit"><input data-hardware-index="${index}" type="number" min="0" step="0.001" value="${item.quantity}"><span>${escapeHtml(item.unit)}</span></span></td><td><input class="cost-input" data-hardware-price="${index}" aria-label="Цена за единицу" type="number" min="0" step="0.01" value="${Number(item.price || 0).toFixed(2)}"></td><td><input class="cost-input" data-hardware-sum="${index}" aria-label="Сумма" type="number" min="0" step="0.01" value="${Number(item.sum || 0).toFixed(2)}"></td></tr>`;
    }).join('') : '<tr><td colspan="4">Выбран вариант «Без фурнитуры» или комплект пуст.</td></tr>';
    document.querySelectorAll('[data-hardware-item]').forEach(select => select.addEventListener('change', () => updateHardwareItem(Number(select.dataset.hardwareItem), select.value)));
    document.querySelectorAll('[data-hardware-index]').forEach(input => input.addEventListener('change', () => updateHardwareQty(Number(input.dataset.hardwareIndex), input.value)));
    document.querySelectorAll('[data-hardware-price]').forEach(input => input.addEventListener('change', () => updateHardwarePrice(Number(input.dataset.hardwarePrice), input.value)));
    document.querySelectorAll('[data-hardware-sum]').forEach(input => input.addEventListener('change', () => updateHardwareSum(Number(input.dataset.hardwareSum), input.value)));
    document.getElementById('products-body').innerHTML = calc.products.map(item => `<tr><td>${escapeHtml(item.name)}<br><span class="hint">${escapeHtml(panelTitle)}</span></td><td class="text-center">${item.quantity}</td><td class="yellow-cell">${escapeHtml(item.size)}</td><td class="text-center">${formatter.format(item.area || calc.totals.areaM2)} м²</td><td class="text-right">${money(item.sum, item.currency)}</td></tr>`).join('');
    document.getElementById('products-summary').textContent = `Материал: ${formatter.format(calc.totals.sheets)} лист(ов), полезная площадь ${formatter.format(calc.totals.areaM2)} м². Ориентировочный отход: ${formatter.format(calc.totals.wasteArea)} м².`;
    document.getElementById('services-body').innerHTML = calc.services.length ? calc.services.map((item, index) => `<tr><td>${escapeHtml(item.name)}${item.description ? `<div class="hint">${escapeHtml(item.description)}</div>` : ''}${item.isAdditional ? `<button type="button" class="danger" data-service-remove="${index}" style="margin-top:5px;padding:4px 8px">Удалить</button>` : ''}</td><td><span class="value-with-unit"><input data-service-volume="${index}" type="number" min="0" step="0.01" value="${item.volume}"><span>${escapeHtml(item.unit)}</span></span></td><td><input class="cost-input" data-service-price="${index}" aria-label="Цена за единицу" type="number" min="0" step="0.01" value="${Number(item.price || 0).toFixed(2)}"></td><td><input class="cost-input" data-service-sum="${index}" aria-label="Сумма" type="number" min="0" step="0.01" value="${Number(item.sum || 0).toFixed(2)}"></td></tr>`).join('') : '<tr><td colspan="4">Для этого типа перегородки базовые услуги не выбраны.</td></tr>';
    document.querySelectorAll('[data-service-volume]').forEach(input => input.addEventListener('change', () => updateServiceVolume(Number(input.dataset.serviceVolume), input.value)));
    document.querySelectorAll('[data-service-price]').forEach(input => input.addEventListener('change', () => updateServicePrice(Number(input.dataset.servicePrice), input.value)));
    document.querySelectorAll('[data-service-sum]').forEach(input => input.addEventListener('change', () => updateServiceSum(Number(input.dataset.serviceSum), input.value)));
    document.querySelectorAll('[data-service-remove]').forEach(button => button.addEventListener('click', () => removeService(Number(button.dataset.serviceRemove))));
    document.getElementById('totals-body').innerHTML = `<tr><td>Стоимость отхода/остатка материала</td><td class="text-right">${money(calc.totals.wasteCost)}</td></tr><tr><td>Всего за изделие</td><td class="text-right"><strong>${money(calc.totals.total)}</strong></td></tr>`;
    document.getElementById('hardware-total-card').textContent = money(calc.totals.hardwareTotal);
    document.getElementById('material-total-card').textContent = money(calc.totals.materialTotal);
    document.getElementById('services-total-card').textContent = money(calc.totals.servicesTotal);
    document.getElementById('project-total-card').textContent = money(calc.totals.total);
    document.getElementById('grand-total').textContent = money(calc.totals.total);
    resultPanel.classList.remove('hidden');
}
function updateHardwareQty(index, value) {
    if (!currentCalculation) return;
    const item = currentCalculation.hardware[index];
    if (!item) return;
    item.quantity = parseFloat(value || '0') || 0;
    item.sum = item.quantity * item.price;
    currentCalculation.totals.hardwareTotal = currentCalculation.hardware.reduce((sum, row) => sum + row.sum, 0);
    currentCalculation.totals.total = currentCalculation.totals.hardwareTotal + currentCalculation.totals.materialTotal + currentCalculation.totals.servicesTotal;
    currentCalculation.total_amount = currentCalculation.totals.total;
    renderCalculation(currentCalculation);
}
function updateHardwarePrice(index, value) {
    const item = currentCalculation?.hardware[index];
    if (!item) return;
    item.price = Math.max(0, parseFloat(value || '0') || 0);
    item.sum = item.quantity * item.price;
    updateHardwareQty(index, item.quantity);
}
function updateHardwareSum(index, value) {
    const item = currentCalculation?.hardware[index];
    if (!item) return;
    item.sum = Math.max(0, parseFloat(value || '0') || 0);
    item.price = item.quantity > 0 ? item.sum / item.quantity : 0;
    updateHardwareQty(index, item.quantity);
}
function updateHardwareItem(index, id) {
    if (!currentCalculation) return;
    const item = currentCalculation.hardware[index];
    const selected = item?.options?.find(option => String(option.id) === String(id));
    if (!item || !selected) return;
    item.id = selected.id;
    item.name = selected.name;
    item.unit = selected.unit;
    item.price = selected.price;
    item.sum = item.quantity * item.price;
    updateHardwareQty(index, item.quantity);
}
function recalculateServices() {
    currentCalculation.totals.servicesTotal = currentCalculation.services.reduce((sum, row) => sum + row.sum, 0);
    currentCalculation.totals.total = currentCalculation.totals.hardwareTotal + currentCalculation.totals.materialTotal + currentCalculation.totals.servicesTotal;
    currentCalculation.total_amount = currentCalculation.totals.total;
    renderCalculation(currentCalculation);
}
function updateServiceVolume(index, value) {
    if (!currentCalculation?.services[index]) return;
    const item = currentCalculation.services[index];
    item.volume = Math.max(0, parseFloat(value || '0') || 0);
    item.sum = item.volume * item.price;
    recalculateServices();
}
function updateServicePrice(index, value) {
    const item = currentCalculation?.services[index];
    if (!item) return;
    item.price = Math.max(0, parseFloat(value || '0') || 0);
    item.sum = item.volume * item.price;
    recalculateServices();
}
function updateServiceSum(index, value) {
    const item = currentCalculation?.services[index];
    if (!item) return;
    item.sum = Math.max(0, parseFloat(value || '0') || 0);
    item.price = item.volume > 0 ? item.sum / item.volume : 0;
    recalculateServices();
}
function removeService(index) {
    if (!currentCalculation?.services[index]?.isAdditional) return;
    currentCalculation.services.splice(index, 1);
    recalculateServices();
}
function addResultService() {
    if (!currentCalculation) return;
    const select = document.getElementById('result-service-select');
    const service = allServices.find(row => String(row.id) === String(select.value));
    if (!service) return;
    const price = servicePriceRub(service);
    currentCalculation.services.push({id: service.id, name: service.name, description: 'Дополнительная услуга', volume: 1, unit: service.unit, price, currency: 'RUB', sum: price, isAdditional: true});
    select.value = '';
    recalculateServices();
}
async function saveCalculation(calc, messageNodeId = 'save-message') {
    if (!calc) { calc = {id: Date.now(), ...collectInputs(), total_amount: 0, currency: 'RUB', notice: 'Черновик без детального расчета'}; }
    const form = new FormData();
    form.append('action', 'save_calculation');
    form.append('payload_json', JSON.stringify(calc));
    const response = await fetch('calculator_septic.php', {method: 'POST', body: form});
    const result = await response.json();
    const messageNode = document.getElementById(messageNodeId);
    if (messageNode) messageNode.textContent = result.message || (result.ok ? 'Сохранено.' : 'Ошибка сохранения.');
    return result;
}
function saveCalculationSummary() {
    if (!calculationItems.length) {
        document.getElementById('calculation-save-message').textContent = 'Добавьте хотя бы одну перегородку.';
        return;
    }
    const total = calculationItems.reduce((sum, item) => sum + Number(item.totals?.total || item.total_amount || 0), 0);
    const objects = [...new Set(calculationItems.map(item => item.object_name).filter(Boolean))];
    saveCalculation({
        object_name: objects.join(', '),
        partition_identifier: `Расчёт (${calculationItems.length} поз.)`,
        calculation_items: calculationItems,
        total_amount: total,
        currency: 'RUB'
    }, 'calculation-save-message');
}
function createOffer() {
    if (!calculationItems.length) return;
    offerItems = JSON.parse(JSON.stringify(calculationItems));
    renderOffer();
    switchTab('offer');
}
function addToCalculation() {
    if (!currentCalculation) return;
    const item = JSON.parse(JSON.stringify(currentCalculation));
    if (editingCalculationIndex === null) calculationItems.push(item);
    else calculationItems[editingCalculationIndex] = item;
    editingCalculationIndex = null;
    document.getElementById('add-calculation-btn').textContent = 'Добавить в расчёт';
    localStorage.setItem('septic-calculation-items', JSON.stringify(calculationItems));
    renderCalculationList();
    resultPanel.classList.add('hidden');
    currentCalculation = null;
    switchTab('calculation');
}
function calculationName(item) {
    const cfg = item.showerConfig || {};
    return `${item.partition_type_name || 'Перегородка'}<div class="hint">Помещения: ${escapeHtml(item.object_name || '—')}<br>Габаритный размер: ${formatter.format(item.length || cfg.roomWidth || 0)}×${formatter.format(item.height || cfg.height || 0)} мм.<br>Дверь: ${formatter.format(cfg.doorWidth || 0)}×${formatter.format(cfg.doorHeight || 0)} мм.<br>Цвет: ${escapeHtml(item.decor || '—')}</div>`;
}
function renderCalculationList() {
    const node = document.getElementById('calculation-list');
    document.getElementById('calculation-count').textContent = calculationItems.length;
    if (!calculationItems.length) { node.innerHTML = '<div class="calculation-empty">В расчёте пока нет перегородок. Рассчитайте перегородку в калькуляторе и нажмите «Добавить в расчёт».</div>'; document.getElementById('calculation-maps').innerHTML=''; return; }
    const rows = calculationItems.map((item,index) => {
        const area = Number(item.totals?.areaM2 || 0), partitions = Number(item.showerConfig?.partitionCount || 1), cabins = Number(item.showerConfig?.sectionCount || item.doors || 1), total = Number(item.totals?.total || 0);
        const unitArea = partitions > 0 ? area / partitions : area, cabinCost = cabins > 0 ? total / cabins : total, priceM2 = area > 0 ? total / area : 0;
        return `<tr><td class="item-name">${calculationName(item)}</td><td class="text-center">${formatter.format(unitArea)}</td><td class="text-center">${formatter.format(area)}</td><td class="text-center">${formatter.format(partitions)}</td><td class="text-center">${formatter.format(cabins)}</td><td class="text-right">${money(cabinCost)}</td><td class="text-right">${money(priceM2)}</td><td class="text-right">${money(total)}</td><td class="text-right">${money(total)}</td><td><div class="actions"><button type="button" class="secondary" onclick="editCalculationItem(${index})" aria-label="Редактировать" title="Редактировать">✎</button><button type="button" class="danger" onclick="removeCalculationItem(${index})" aria-label="Удалить" title="Удалить">🗑</button></div></td></tr>`;
    }).join('');
    const total = calculationItems.reduce((sum,item)=>sum+Number(item.totals?.total||0),0);
    node.innerHTML = `<div class="calculation-table-wrap"><table class="calculation-table"><thead><tr><th>Наименование</th><th>Площадь панелей 1 перегородки, м²</th><th>Общая площадь, м²</th><th>Кол-во перегородок, шт</th><th>Кол. кабин в перегородке</th><th>Стоимость кабины</th><th>Цена за м²</th><th>Стоимость 1 перегородки</th><th>Итого стоимость</th><th></th></tr></thead><tbody>${rows}</tbody></table></div><div class="calculation-total">Итого: ${money(total)}</div>`;
}
function removeCalculationItem(index) { calculationItems.splice(index,1); localStorage.setItem('septic-calculation-items',JSON.stringify(calculationItems)); renderCalculationList(); }
function setInputValue(id, value) {
    const node = document.getElementById(id);
    if (node && value !== undefined && value !== null) node.value = String(value);
}
function editCalculationItem(index) {
    const item = calculationItems[index];
    if (!item) return;
    editingCalculationIndex = index;
    setInputValue('object_name', item.object_name);
    setInputValue('partition_identifier', item.partition_identifier);
    setInputValue('manufacturer_id', item.manufacturer_id || 0);
    filterDecors();
    setInputValue('decor_input', item.panel?.id || item.decor);
    document.getElementById('decor_input').dispatchEvent(new Event('change'));
    setInputValue('panel_format_id', item.panel_format_id || item.panel?.id || '__auto__');
    setInputValue('partition_type_id', item.partition_type_id);
    typeSelect.dispatchEvent(new Event('change'));
    setInputValue('supplier_id', item.supplier_id || 0);
    document.getElementById('supplier_id').dispatchEvent(new Event('change'));
    setInputValue('collection_id', item.collection_id || 0);
    Object.entries(item.parameters || {}).forEach(([name, parameter]) => {
        const input = [...paramsNode.querySelectorAll('input')].find(node => node.dataset.paramName === name);
        if (input) input.value = parameter.value;
    });
    const cfg = item.showerConfig || {};
    const configFields = {layoutType:'shower_layout_type',sectionCount:'shower_partition_count',partitionCount:'shower_panel_count',roomWidth:'shower_room_width',depth:'shower_depth',height:'shower_height',fasciaWidth:'shower_fascia_width',fasciaHeight:'shower_fascia_height',doorCount:'shower_door_count',doorWidth:'shower_door_width',doorHeight:'shower_door_height',floorMount:'shower_floor_mount',wallMount:'shower_wall_mount',ceilingMount:'shower_ceiling_mount',topSupport:'shower_top_support',angleSides:'shower_angle_sides',kerf:'shower_kerf',margin:'shower_margin'};
    Object.entries(configFields).forEach(([key, id]) => setInputValue(id, cfg[key]));
    document.getElementById('shower_allow_rotation').checked = Boolean(cfg.allowPanelRotation || cfg.allowRotation);
    document.getElementById('shower_ceiling_mount').dispatchEvent(new Event('change'));
    currentCalculation = JSON.parse(JSON.stringify(item));
    resultPanel.classList.add('hidden');
    document.getElementById('add-calculation-btn').textContent = 'Сохранить изменения';
    switchTab('calc');
    window.scrollTo({top: 0, behavior: 'smooth'});
}
function cuttingPayload() {
    const parts = calculationItems.flatMap((item,itemIndex)=>(item.products||[]).map((part,partIndex)=>({id:`${itemIndex+1}-${partIndex+1}`,name:`${item.partition_identifier || 'Перегородка '+(itemIndex+1)} · ${part.name}`,length:Number(part.length||0),width:Number(part.height||0),qty:Number(part.quantity||1),rotate:Boolean(item.showerConfig?.allowRotation),grainDirection:'none'})));
    const materials = calculationItems.map(item=>item.panel).filter(Boolean).map((panel,index)=>({label:panel.name||`Лист ${index+1}`,height:Number(panel.height_mm),width:Number(panel.width_mm),qty:null,priceM2:Number(panel.price_per_m2||0),currency:panel.currency||'RUB',panelId:panel.id||null,manufacturerId:panel.manufacturer_id||null,grainDirection:panel.decor_direction||'none',margin:Number(calculationItems[index]?.showerConfig?.margin||0)})).filter(m=>m.height>0&&m.width>0);
    return {source:'calculator_septic',parts,sourceMaterials:materials,settings:{kerf:Number(calculationItems[0]?.showerConfig?.kerf||4),margin:Number(calculationItems[0]?.showerConfig?.margin||5),method:'best'}};
}
function renderCuttingMaps() {
    const maps=[];
    calculationItems.forEach((item,itemIndex)=>(item.layout?.layouts||[]).forEach((sheet,sheetIndex)=>{
        const panel=item.panel||{}, width=Number(panel.width_mm||1),height=Number(panel.height_mm||1),placed=sheet.placed||[];
        const shapes=placed.map((p,i)=>`<rect x="${p.x}" y="${p.y}" width="${p.w}" height="${p.h}" fill="hsl(${(i*67)%360} 65% 78%)" stroke="#334155"/><text x="${p.x+6}" y="${p.y+18}" font-size="12">${escapeHtml(p.name||'Деталь')}</text>`).join('');
        maps.push(`<details class="cutting-map"><summary>Карта раскроя · ${escapeHtml(item.partition_identifier||'Перегородка '+(itemIndex+1))} · лист ${sheetIndex+1}</summary><div class="cutting-map__body"><svg viewBox="0 0 ${width} ${height}" role="img" aria-label="Карта раскроя листа ${sheetIndex+1}"><rect width="${width}" height="${height}" fill="#fff" stroke="#0f172a" stroke-width="6"/>${shapes}</svg></div></details>`);
    }));
    document.getElementById('calculation-maps').innerHTML=maps.join('')||'<div class="notice">Для добавленных позиций нет готовых карт раскроя.</div>';
}
function openCuttingMaps() { if(!calculationItems.length)return; localStorage.setItem('calculator-cutting-import',JSON.stringify(cuttingPayload())); renderCuttingMaps(); window.open('calculator_cutting.php?import=septic','_blank','noopener'); }
function offerRows(items) {
    return items.map((item, index) => {
        const area = item.totals?.areaM2 || 0;
        const panelTitle = item.panel ? `${item.panel.name} ${item.panel.decor_number || ''} ${item.panel.decor_name || ''}`.trim() : item.decor || '—';
        const unitPrice = area > 0 ? (item.totals.materialTotal / area) : 0;
        return `<tr data-offer-index="${index}">
            <td class="text-center no-edit">${index + 1}</td>
            <td><input type="text" value="${escapeHtml(item.partition_type_name || 'Перегородка')}" onchange="updateOfferField(${index},'partition_type_name',this.value)"><br><input type="text" value="${escapeHtml(panelTitle)}" onchange="updateOfferField(${index},'decor',this.value)" placeholder="Декор"><br><input type="text" value="${escapeHtml(item.object_name || '')}" onchange="updateOfferField(${index},'object_name',this.value)" placeholder="Объект"></td>
            <td class="text-center"><input type="number" step="0.01" value="${area.toFixed(2)}" onchange="updateOfferArea(${index},this.value)"></td>
            <td class="text-center"><input type="number" step="0.01" value="${area.toFixed(2)}" onchange="updateOfferArea(${index},this.value)"></td>
            <td class="text-center"><input type="number" step="1" value="${item.doors || 1}" onchange="updateOfferField(${index},'doors',parseInt(this.value)||1)"></td>
            <td class="text-center"><input type="number" step="1" value="1"></td>
            <td class="text-right"><input type="number" step="0.01" value="${item.totals.materialTotal.toFixed(2)}" onchange="updateOfferCost(${index},'materialTotal',this.value)"></td>
            <td class="text-right"><input type="number" step="0.01" value="${unitPrice.toFixed(2)}" readonly style="background:#f8fafc"></td>
            <td class="text-right"><input type="number" step="0.01" value="${item.totals.total.toFixed(2)}" onchange="updateOfferCost(${index},'total',this.value)"></td>
            <td class="text-right"><input type="number" step="0.01" value="${item.totals.total.toFixed(2)}" onchange="updateOfferCost(${index},'total',this.value)"></td>
            <td style="white-space:nowrap;vertical-align:middle">
                <div class="offer-row-actions">
                    <button type="button" class="secondary" style="padding:5px 10px;font-size:12px" onclick="editOfferItem(${index})" title="Изменить">✏️</button>
                    <button type="button" class="danger" style="padding:5px 10px;font-size:12px" onclick="deleteOfferItem(${index})" title="Удалить">🗑</button>
                </div>
            </td>
        </tr>`;
    }).join('');
}
function updateOfferField(index, field, value) {
    if (!offerItems[index]) return;
    offerItems[index][field] = value;
    renderOffer();
}
function updateOfferArea(index, value) {
    if (!offerItems[index]) return;
    offerItems[index].totals.areaM2 = parseFloat(value) || 0;
    renderOffer();
}
function updateOfferCost(index, field, value) {
    if (!offerItems[index]) return;
    offerItems[index].totals[field] = parseFloat(value) || 0;
    if (field !== 'total') offerItems[index].totals.total = (offerItems[index].totals.materialTotal||0) + (offerItems[index].totals.hardwareTotal||0) + (offerItems[index].totals.servicesTotal||0);
    offerItems[index].total_amount = offerItems[index].totals.total;
    renderOffer();
}
function renderOffer() {
    const node = document.getElementById('offer-list');
    document.getElementById('offer-date').textContent = `Дата КП: ${new Date().toLocaleDateString('ru-RU')}`;
    if (!offerItems.length) {
        node.innerHTML = '<div class="offer-empty">Добавьте один или несколько расчетов перегородок в коммерческое предложение.</div>';
        return;
    }
    const total = offerItems.reduce((sum, item) => sum + item.totals.total, 0);
    const materialTotal = offerItems.reduce((sum, item) => sum + item.totals.materialTotal, 0);
    const hardwareTotal = offerItems.reduce((sum, item) => sum + item.totals.hardwareTotal, 0);
    const servicesTotal = offerItems.reduce((sum, item) => sum + item.totals.servicesTotal, 0);
    const areaTotal = offerItems.reduce((sum, item) => sum + (item.totals.areaM2 || 0), 0);
    node.innerHTML = `<table class="offer-table"><thead><tr class="green-head"><th colspan="11">Сантехнические перегородки HPL: изготовление и комплектация</th></tr><tr><th>№</th><th>Наименование</th><th>Площадь панели, м²</th><th>Общая площадь, м²</th><th>Кол-во перегородок, шт</th><th>Кол. кабин</th><th>Стоимость изделия/евро</th><th>Цена за м²/евро</th><th>Стоимость с фурнитурой</th><th>Итого стоимость</th><th>Действия</th></tr></thead><tbody>${offerRows(offerItems)}</tbody><tfoot><tr><td colspan="2" class="text-right">Итого объем:</td><td class="text-center">${formatter.format(areaTotal)}</td><td colspan="5" class="text-right">Итого стоимость перегородок с учетом фурнитуры, материалов и услуг:</td><td colspan="3" class="text-right">${money(total)}</td></tr></tfoot></table><table class="offer-summary"><tbody><tr><td>Материал HPL</td><td class="text-right">${money(materialTotal)}</td></tr><tr><td>Фурнитура</td><td class="text-right">${money(hardwareTotal)}</td></tr><tr><td>Производство и услуги</td><td class="text-right">${money(servicesTotal)}</td></tr><tr><td>ИТОГО стоимость за проект</td><td class="text-right">${money(total)}</td></tr></tbody></table>`;
}
function editOfferItem(index) { currentCalculation = offerItems[index]; offerItems.splice(index, 1); renderOffer(); renderCalculation(currentCalculation); }
function deleteOfferItem(index) { offerItems.splice(index, 1); renderOffer(); }
function exportExcel() {
    const rows = [['Объект','Идентификатор','Тип','Декор','Площадь','Фурнитура','Материал','Услуги','Сумма']].concat((offerItems.length ? offerItems : (currentCalculation ? [currentCalculation] : [])).map(item => [item.object_name, item.partition_identifier, item.partition_type_name, item.decor, formatter.format(item.totals?.areaM2 || 0), formatter.format(item.totals?.hardwareTotal || 0), formatter.format(item.totals?.materialTotal || 0), formatter.format(item.totals?.servicesTotal || 0), formatter.format(item.totals?.total || item.total_amount || 0)]));
    const csv = rows.map(row => row.map(cell => `"${String(cell ?? '').replace(/"/g, '""')}"`).join(';')).join('\n');
    const blob = new Blob(['\ufeff' + csv], {type: 'application/vnd.ms-excel;charset=utf-8'});
    const link = document.createElement('a'); link.href = URL.createObjectURL(blob); link.download = 'commercial_offer.xls'; link.click(); URL.revokeObjectURL(link.href);
}
function exportPdf() { window.print(); }
typeSelect.addEventListener('change', renderParameters);
calculateBtn.addEventListener('click', calculate);
document.getElementById('save-draft-btn').addEventListener('click', () => saveCalculation(currentCalculation));
document.getElementById('save-result-btn').addEventListener('click', () => saveCalculation(currentCalculation));
document.getElementById('add-calculation-btn').addEventListener('click', addToCalculation);
document.getElementById('create-offer-btn').addEventListener('click', createOffer);
document.getElementById('save-calculation-btn').addEventListener('click', saveCalculationSummary);
document.getElementById('cutting-map-btn').addEventListener('click', openCuttingMaps);
document.getElementById('result-service-add').addEventListener('click', addResultService);
document.getElementById('export-excel-btn').addEventListener('click', exportExcel);
document.getElementById('export-pdf-btn').addEventListener('click', exportPdf);
renderOffer();
renderCalculationList();

function switchTab(name) {
    ['calc','calculation','offer','history'].forEach(t => {
        document.getElementById('tab-pane-' + t)?.classList.remove('active');
        document.getElementById('tab-btn-' + t)?.classList.remove('active');
        document.getElementById('tab-' + t)?.classList.remove('active');
    });
    document.getElementById('tab-' + name)?.classList.add('active');
    document.getElementById('tab-btn-' + name)?.classList.add('active');
    if (name === 'offer') renderOffer();
}

function loadCalculation(id) {
    // При наличии API загрузки из БД — можно реализовать fetch
    alert('Загрузка расчёта #' + id + ' будет реализована после подключения API загрузки.');
}
</script>
<script src="scripts/shower_partition_page.js"></script>
</body>
</html>
