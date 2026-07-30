<?php
require_once __DIR__ . '/includes/admin_auth.php';
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/admin_schema.php';

ensure_calculator_tables($pdo);
ensure_organization_table($pdo);

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
$collections = $pdo->query('SELECT fcol.*, s.company_name AS supplier_name FROM furniture_collections fcol LEFT JOIN suppliers s ON s.id = fcol.supplier_id ORDER BY s.company_name ASC, fcol.name ASC')->fetchAll();
$services = $pdo->query('SELECT * FROM services WHERE is_active = 1 ORDER BY name ASC')->fetchAll();
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
</style>
</head>
<body>
<header class="header">
    <div style="width:100%;display:flex;align-items:center;gap:16px;">
        <a href="calculator.php">← Калькуляторы</a>
        <h1 style="flex:1;text-align:center;margin:0;">Калькулятор сантехнических кабин</h1>
        <a href="logout.php">Выйти</a>
    </div>
</header>
<main class="container">
    <div class="tabs-bar">
        <button class="tab-btn active" onclick="switchTab('calc')" id="tab-btn-calc">
            <span class="tab-icon">🧮</span> Расчёт
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
            <div><label for="object_name">Объект *</label><input id="object_name" name="object_name" placeholder="Например: БЦ Север" required></div>
            <div><label for="partition_identifier">Идентификатор перегородки</label><input id="partition_identifier" name="partition_identifier" placeholder="Например: П-01"></div>
        </div>
    </section>

    <section class="panel">
        <h2>Параметры расчёта</h2>
        <div class="grid">
            <div><label for="manufacturer_id">Производитель</label><select id="manufacturer_id"><option value="0">Любой производитель</option><?php foreach ($manufacturers as $manufacturer): ?><option value="<?php echo e((string)$manufacturer['id']); ?>"><?php echo e($manufacturer['full_name']); ?></option><?php endforeach; ?></select></div>
            <div><label for="decor_input">Декор</label><select id="decor_input"><option value="">— Выберите декор —</option><?php foreach ($panels as $panel): ?><option value="<?php echo e((string)$panel['id']); ?>" data-mfr="<?php echo e((string)($panel['manufacturer_id'] ?? 0)); ?>" data-stock="1"><?php echo e(trim(($panel['decor_number'] ?? '') . ' ' . ($panel['decor_name'] ?? ''))); ?></option><?php endforeach; ?></select></div>
            <div><label for="partition_type_id">Тип перегородки</label><select id="partition_type_id"><option value="0">Выберите тип</option><?php foreach ($partitionTypes as $type): ?><option value="<?php echo e((string)$type['id']); ?>"><?php echo e($type['name']); ?></option><?php endforeach; ?></select></div>
            <div><label for="supplier_id">Производитель фурнитуры</label><select id="supplier_id"><option value="0">Все поставщики</option><?php foreach ($suppliersList as $sup): ?><option value="<?php echo e((string)$sup['id']); ?>"><?php echo e($sup['company_name']); ?></option><?php endforeach; ?></select><div class="hint">Выберите поставщика для фильтрации.</div></div>
            <div><label for="collection_id">Коллекция</label><select id="collection_id"><option value="0">Все коллекции</option><?php foreach ($collections as $col): ?><option value="<?php echo e((string)$col['id']); ?>" data-supplier="<?php echo e((string)($col['supplier_id'] ?? 0)); ?>"><?php echo e($col['name']); ?><?php if (!empty($col['supplier_name'])): ?> (<?php echo e($col['supplier_name']); ?>)<?php endif; ?></option><?php endforeach; ?></select><div class="hint">Фильтр по коллекции.</div></div>
        </div>
        <div id="dynamic-parameters" class="grid" style="margin-top:14px"></div>
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
                    <div class="cost-line purple"><span>DISCOUNT %</span><strong>РУБ</strong></div>
                    <div class="cost-line cyan"><span>Фурнитура <small>комплектующие</small></span><strong id="hardware-total-card">0,00 RUB</strong></div>
                    <div class="cost-line cyan"><span>HPL <small>панели и раскрой</small></span><strong id="material-total-card">0,00 RUB</strong></div>
                    <div class="cost-line green"><span>Производство <small>услуги</small></span><strong id="services-total-card">0,00 RUB</strong></div>
                    <div class="cost-line"><span>ИТОГО за проект</span><strong id="project-total-card">0,00 RUB</strong></div>
                    <div class="cost-line orange"><span>ИТОГО за кабину</span><strong id="grand-total">0,00 RUB</strong></div>
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
                    <table class="mini-table"><thead><tr><th>Наименование</th><th>Объем</th><th>Цена</th><th>Стоимость</th></tr></thead><tbody id="services-body"></tbody></table>
                </div>
                <table><tbody id="totals-body"></tbody></table>
            </div>
        </div>
        <div class="actions" style="margin-top:20px"><button id="save-result-btn" type="button">Сохранить расчет</button><button id="add-offer-btn" type="button" class="success">Добавить в Коммерческое предложение</button></div>
    </section>

    </div><!-- /tab-calc -->

    <!-- ═══════════ ВКЛАДКА 2: КОММЕРЧЕСКОЕ ПРЕДЛОЖЕНИЕ ═══════════ -->
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
                    <td><?php echo e(number_format((float)$calc['total_amount'], 2, ',', ' ') . ' ' . $calc['currency']); ?></td>
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
<script>
const partitionTypes = <?php echo json_encode($partitionTypes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
const parametersByType = <?php echo json_encode($parametersByType, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
const panels = <?php echo json_encode($panels, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
const services = <?php echo json_encode($services, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
const formatter = new Intl.NumberFormat('ru-RU', {minimumFractionDigits: 2, maximumFractionDigits: 2});
const typeSelect = document.getElementById('partition_type_id');
const paramsNode = document.getElementById('dynamic-parameters');
const calculateBtn = document.getElementById('calculate-btn');
const resultPanel = document.getElementById('result-panel');
let currentCalculation = null;
let offerItems = [];
let partitionCounter = 0;
function money(value, currency = 'RUB') { return `${formatter.format(Number(value) || 0)} ${currency}`; }
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

/* Фильтрация: Поставщик → Коллекция */
function filterCollections() {
    const supId = Number(document.getElementById('supplier_id').value || 0);
    const sel = document.getElementById('collection_id');
    Array.from(sel.options).forEach(opt => {
        if (!opt.value) return;
        const optSup = Number(opt.dataset.supplier || 0);
        opt.hidden = supId > 0 && optSup > 0 && optSup !== supId;
    });
    if (sel.selectedOptions[0]?.hidden) sel.value = '';
}
document.getElementById('supplier_id').addEventListener('change', filterCollections);
filterCollections();

function renderParameters() {
    const typeId = typeSelect.value;
    const params = parametersByType[typeId] || [];
    paramsNode.innerHTML = '';
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
    if (!document.getElementById('object_name').value.trim()) {
        document.getElementById('object_name').focus();
        document.getElementById('object_name').reportValidity();
        return;
    }
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
    const serviceRows = services.slice(0, 4).map(service => {
        const volume = String(service.unit || '').includes('м') ? areaM2 : doors;
        const sum = volume * Number(service.price || 0);
        return {name: service.name, volume, unit: service.unit, price: Number(service.price || 0), currency: service.currency || 'RUB', sum};
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
    document.getElementById('hardware-body').innerHTML = calc.hardware.length ? calc.hardware.map((item, index) => `<tr><td>${escapeHtml(item.name)}<div class="hint">${escapeHtml(item.category)}</div></td><td><input data-hardware-index="${index}" type="number" step="0.001" value="${item.quantity}"> ${escapeHtml(item.unit)}</td><td>${money(item.price, item.currency)}</td><td>${money(item.sum, item.currency)}</td></tr>`).join('') : '<tr><td colspan="4">Выбран вариант «Без фурнитуры» или комплект пуст.</td></tr>';
    document.querySelectorAll('[data-hardware-index]').forEach(input => input.addEventListener('change', () => updateHardwareQty(Number(input.dataset.hardwareIndex), input.value)));
    document.getElementById('products-body').innerHTML = calc.products.map(item => `<tr><td>${escapeHtml(item.name)}<br><span class="hint">${escapeHtml(panelTitle)}</span></td><td class="text-center">${item.quantity}</td><td class="yellow-cell">${escapeHtml(item.size)}</td><td class="text-center">${formatter.format(item.area || calc.totals.areaM2)} м²</td><td class="text-right">${money(item.sum, item.currency)}</td></tr>`).join('');
    document.getElementById('products-summary').textContent = `Материал: ${formatter.format(calc.totals.sheets)} лист(ов), полезная площадь ${formatter.format(calc.totals.areaM2)} м². Ориентировочный отход: ${formatter.format(calc.totals.wasteArea)} м².`;
    document.getElementById('services-body').innerHTML = calc.services.length ? calc.services.map(item => `<tr><td>${escapeHtml(item.name)}</td><td>${formatter.format(item.volume)} ${escapeHtml(item.unit)}</td><td>${money(item.price, item.currency)}</td><td>${money(item.sum, item.currency)}</td></tr>`).join('') : '<tr><td colspan="4">Активные услуги не заведены.</td></tr>';
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
async function saveCalculation(calc) {
    if (!calc) { calc = {id: Date.now(), ...collectInputs(), total_amount: 0, currency: 'RUB', notice: 'Черновик без детального расчета'}; }
    const form = new FormData();
    form.append('action', 'save_calculation');
    form.append('payload_json', JSON.stringify(calc));
    const response = await fetch('calculator.php', {method: 'POST', body: form});
    const result = await response.json();
    document.getElementById('save-message').textContent = result.message || (result.ok ? 'Сохранено.' : 'Ошибка сохранения.');
}
function addToOffer() {
    if (!currentCalculation) return;
    offerItems.push(JSON.parse(JSON.stringify(currentCalculation)));
    renderOffer();
    resultPanel.classList.add('hidden');
    currentCalculation = null;
    switchTab('offer');
}
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
document.getElementById('add-offer-btn').addEventListener('click', addToOffer);
document.getElementById('export-excel-btn').addEventListener('click', exportExcel);
document.getElementById('export-pdf-btn').addEventListener('click', exportPdf);
renderOffer();

function switchTab(name) {
    ['calc','offer','history'].forEach(t => {
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
</body>
</html>
