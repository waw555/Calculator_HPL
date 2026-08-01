<?php
require_once __DIR__ . '/includes/admin_auth.php';
require_once __DIR__ . '/includes/app_header.php';
require_login();
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/admin_schema.php';

ensure_panel_formats_table($pdo);
ensure_panel_sizes_table($pdo);
ensure_panel_thicknesses_table($pdo);
ensure_embossings_table($pdo);
ensure_currencies_table($pdo);
ensure_countertop_settings_table($pdo);

$pdo->exec("CREATE TABLE IF NOT EXISTS countertop_calculations (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    object_name VARCHAR(200) NULL,
    title VARCHAR(300) NULL,
    payload_json LONGTEXT NULL,
    total_rub DECIMAL(12,2) NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    header('Content-Type: application/json; charset=utf-8');
    $payload = json_decode(file_get_contents('php://input'), true) ?: [];
    $title = trim((string)($payload['title'] ?? ''));
    $objectName = trim((string)($payload['object_name'] ?? ''));
    $totalRub = (float)($payload['total_rub'] ?? 0);
    if ($title === '') $title = 'Расчёт от ' . date('d.m.Y H:i');
    $stmt = $pdo->prepare('INSERT INTO countertop_calculations (user_id, object_name, title, payload_json, total_rub) VALUES (:uid, :obj, :title, :payload, :total)');
    $stmt->execute([
        'uid' => (int)$_SESSION['user_id'],
        'obj' => $objectName === '' ? null : $objectName,
        'title' => $title,
        'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'total' => $totalRub,
    ]);
    echo json_encode(['ok' => true, 'id' => (int)$pdo->lastInsertId()]);
    exit;
}

if (($_GET['action'] ?? '') === 'load' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Content-Type: application/json; charset=utf-8');
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) { echo json_encode(['ok' => false, 'error' => 'bad id']); exit; }
    $stmt = $pdo->prepare('SELECT * FROM countertop_calculations WHERE id = :id');
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();
    if (!$row) { echo json_encode(['ok' => false, 'error' => 'not found']); exit; }
    echo json_encode([
        'ok' => true,
        'id' => $row['id'],
        'title' => $row['title'],
        'object_name' => $row['object_name'],
        'payload' => json_decode($row['payload_json'], true),
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    header('Content-Type: application/json; charset=utf-8');
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) $pdo->prepare('DELETE FROM countertop_calculations WHERE id = :id')->execute(['id' => $id]);
    echo json_encode(['ok' => true]);
    exit;
}

if (($_GET['action'] ?? '') === 'refresh_rates' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $errors = [];
    $ok = refresh_cbr_currency_rates($pdo, $errors);
    if ($ok) {
        $rows = $pdo->query("SELECT code, rate_to_rub FROM currencies WHERE code IN ('EUR','USD') AND is_active=1")->fetchAll();
        $rates = [];
        foreach ($rows as $r) $rates[$r['code']] = (float)$r['rate_to_rub'];
        echo json_encode(['ok' => true, 'rates' => $rates, 'errors' => []]);
    } else {
        echo json_encode(['ok' => false, 'errors' => $errors]);
    }
    exit;
}

$manufacturers = $pdo->query('SELECT id, full_name FROM manufacturers ORDER BY full_name ASC')->fetchAll();

$decors = $pdo->query("SELECT pf.id, pf.decor_number, pf.decor_name, pf.name,
    pf.price_per_m2, pf.price_per_sheet, pf.cost, pf.cost_per_sheet, pf.markup, pf.currency,
    pf.manufacturer_id, pf.embossing_id, pf.panel_size_id, pf.thickness_id, pf.width_mm, pf.height_mm,
    pf.is_stock_program,
    m.full_name AS manufacturer_name
    FROM panel_formats pf
    LEFT JOIN manufacturers m ON m.id = pf.manufacturer_id
    WHERE pf.is_active = 1
    ORDER BY m.full_name, pf.decor_number, pf.decor_name")->fetchAll();

$embossings = $pdo->query('SELECT id, name, short_name, manufacturer_id FROM embossings WHERE is_active=1 ORDER BY name ASC')->fetchAll();

$panelSizes = $pdo->query('SELECT ps.id, ps.height_mm, ps.width_mm, ps.manufacturer_id, m.full_name AS manufacturer_name
    FROM panel_sizes ps
    LEFT JOIN manufacturers m ON m.id = ps.manufacturer_id
    WHERE ps.is_active=1 ORDER BY m.full_name, ps.height_mm, ps.width_mm')->fetchAll();

$thicknesses = $pdo->query('SELECT id, thickness FROM panel_thicknesses WHERE is_active=1 ORDER BY thickness ASC')->fetchAll();

$currencies = $pdo->query("SELECT code, name, rate_to_rub FROM currencies WHERE is_active=1 ORDER BY code ASC")->fetchAll();
$eurRate = null;
foreach ($currencies as $c) {
    if (strtoupper($c['code']) === 'EUR') { $eurRate = (float)$c['rate_to_rub']; break; }
}

$savedCalcs = $pdo->query('SELECT id, object_name, title, total_rub, created_at, updated_at FROM countertop_calculations ORDER BY updated_at DESC LIMIT 50')->fetchAll();

$kerfRow = $pdo->query('SELECT kerf_mm, blank_width_mm FROM countertop_settings WHERE id = 1')->fetch();
$kerfMm = $kerfRow ? (float)$kerfRow['kerf_mm'] : 4.0;
$blankWidthMm = $kerfRow ? (int)$kerfRow['blank_width_mm'] : 600;

$productTypesDb = $pdo->query('SELECT type_key, processing_per_m, min_width, max_width, min_length, max_length FROM countertop_product_types')->fetchAll();
$productTypesMap = [];
foreach ($productTypesDb as $pt) {
    $productTypesMap[$pt['type_key']] = [
        'processingPerM' => (float)$pt['processing_per_m'],
        'minW' => (int)$pt['min_width'],
        'maxW' => (int)$pt['max_width'],
        'minL' => (int)$pt['min_length'],
        'maxL' => (int)$pt['max_length'],
    ];
}

$manufacturersJson = json_encode(array_values($manufacturers), JSON_UNESCAPED_UNICODE);
$decorsJson = json_encode(array_values($decors), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$embossingsJson = json_encode(array_values($embossings), JSON_UNESCAPED_UNICODE);
$panelSizesJson = json_encode(array_values($panelSizes), JSON_UNESCAPED_UNICODE);
$thicknessesJson = json_encode(array_values($thicknesses), JSON_UNESCAPED_UNICODE);
$currenciesJson = json_encode(array_values($currencies), JSON_UNESCAPED_UNICODE);
$eurRateJson = json_encode($eurRate);
$kerfMmJson = json_encode($kerfMm);
$blankWidthMmJson = json_encode($blankWidthMm);
$productTypesDbJson = json_encode($productTypesMap, JSON_UNESCAPED_UNICODE);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<title>Калькулятор столешниц</title>
<style>
body { font-family: 'Inter', Arial, sans-serif; background: #f5f7fb; margin: 0; color: #1f2937; }
.header { background: radial-gradient(circle at 10% 10%, rgba(59,130,246,.35), transparent 32%), linear-gradient(120deg, #0f172a, #1e3a8a 58%, #0f766e); color: #fff; padding: 22px 36px 30px; box-shadow: 0 18px 45px rgba(15,23,42,.18); display:flex; align-items:center; gap:16px; flex-wrap:wrap; }
.header a { color: #dbeafe; font-weight: 700; text-decoration: none; }
.header h1 { margin: 0; font-size: clamp(24px, 3.5vw, 36px); letter-spacing: -.02em; font-weight: 900; }
.container { max-width: 1320px; margin: 28px auto; padding: 0 20px; }
.panel { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 22px; margin-bottom: 24px; box-shadow: 0 8px 24px rgba(15,23,42,0.06); }
.section-title { font-size: 15px; font-weight: 700; color: #374151; background: #f1f5f9; border-left: 4px solid #2563eb; padding: 8px 12px; border-radius: 0 6px 6px 0; margin: 22px 0 14px 0; }
.section-title.main { font-size: 17px; background: #eff6ff; border-left-color: #1d4ed8; margin-top: 0; }
.grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 14px; }
label { display: block; font-weight: 600; margin-bottom: 6px; font-size: 13px; }
input, select { width: 100%; box-sizing: border-box; padding: 9px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 14px; }
input[readonly] { background: #f8fafc; }
input.calculated { background: #f0fdf4; border-color: #86efac; font-weight: 600; }
button, .button { border: 0; border-radius: 8px; padding: 10px 16px; background: #2563eb; color: #fff; text-decoration: none; cursor: pointer; display: inline-block; font-weight: 600; font-size: 14px; }
button.secondary { background: #64748b; }
button.success { background: #16a34a; }
button.danger { background: #dc2626; }
button.warning { background: #d97706; }
.hint { color: #64748b; font-size: 12px; margin-top: 4px; }
table.data-table { width: 100%; border-collapse: collapse; background: #fff; }
table.data-table th, table.data-table td { padding: 8px 10px; text-align: left; vertical-align: middle; border-bottom: 1px solid #e5e7eb; font-size: 13px; }
table.data-table th { background: #f8fafc; font-weight: 700; white-space: nowrap; }
table.data-table input, table.data-table select { padding: 6px 8px; font-size: 13px; }
table.data-table td.num { text-align: right; font-weight: 600; font-family: monospace; }
table.data-table tr.total-row { background: #eff6ff; font-weight: 700; }
table.data-table tr.total-row td { border-top: 2px solid #2563eb; }
button.rm-btn { background: #fef2f2; color: #dc2626; border: none; border-radius: 6px; padding: 4px 10px; cursor: pointer; font-size: 13px; font-weight: 700; }
button.rm-btn:hover { background: #dc2626; color: #fff; }
button.add-btn { background: #eff6ff; color: #2563eb; border: 1px dashed #93c5fd; border-radius: 8px; padding: 8px 14px; cursor: pointer; font-size: 13px; font-weight: 600; }
button.add-btn:hover { background: #dbeafe; }
.actions-row { display: flex; gap: 10px; margin-top: 18px; flex-wrap: wrap; }
.note-text { font-size: 12px; color: #64748b; line-height: 1.6; margin-top: 12px; }
.note-text b { color: #374151; }
.summary-box { background: #f8fafc; border: 2px solid #2563eb; border-radius: 12px; padding: 20px; margin-top: 20px; }
.summary-box h3 { margin: 0 0 14px 0; color: #1d4ed8; }
.summary-row { display: flex; justify-content: space-between; align-items: center; padding: 6px 0; font-size: 14px; border-bottom: 1px solid #e5e7eb; }
.summary-row:last-child { border-bottom: none; }
.summary-row.grand { font-size: 18px; font-weight: 700; color: #1d4ed8; border-top: 2px solid #2563eb; padding-top: 10px; }
.service-unit { font-size: 11px; color: #64748b; }
.hidden { display: none !important; }
.modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.4); display: flex; align-items: center; justify-content: center; z-index: 1000; }
.modal-box { background: #fff; border-radius: 12px; padding: 24px; width: 420px; max-width: 92vw; box-shadow: 0 12px 40px rgba(0,0,0,.2); }
.modal-box h3 { margin-top: 0; }
.badge { display: inline-block; padding: 3px 10px; border-radius: 12px; font-size: 11px; font-weight: 700; }
.badge-eur { background: #dbeafe; color: #1d4ed8; }
.badge-rub { background: #dcfce7; color: #166534; }
.history-table { width: 100%; border-collapse: collapse; }
.history-table th, .history-table td { padding: 10px 14px; border-bottom: 1px solid #e5e7eb; text-align: left; font-size: 14px; }
.history-table th { background: #f8fafc; font-weight: 700; }
.decor-info { background: #f8fafc; border: 1px solid #e5e7eb; border-radius: 8px; padding: 12px; margin-top: 10px; font-size: 13px; line-height: 1.6; }
.decor-info span { font-weight: 600; color: #374151; }
.currency-row { display: flex; gap: 8px; align-items: flex-end; }
.currency-row > div { flex: 1; }
.currency-row .auto-hint { font-size: 11px; color: #16a34a; font-weight: 600; }
</style>
<?php echo app_header_styles(); ?>
</head>
<body>

<?php render_app_header(); ?>
<main class="container">

<section class="panel">
    <div class="grid">
        <div>
            <label for="object_name">Название объекта</label>
            <input id="object_name" type="text" placeholder="Например, ЖК Северный">
        </div>
        <div>
            <label for="calc_title">Название расчёта</label>
            <input id="calc_title" type="text" placeholder="Например, Кухня Г-образная">
        </div>
    </div>
</section>

<!-- ═══ ПАРАМЕТРЫ ═══ -->
<section class="panel">
    <div class="section-title main">Параметры расчёта</div>
    <div class="grid">
        <div>
            <label for="product_type">Тип изделия</label>
            <select id="product_type">
                <option value="kitchen">Кухонная столешница</option>
                <option value="fartuk">Стеновая панель / Фартук</option>
                <option value="horeca">HoReCa</option>
                <option value="bortik">Бортик / Плинтус</option>
            </select>
        </div>
        <div>
            <label for="manufacturer">Производитель</label>
            <select id="manufacturer">
                <option value="">— Выберите производителя —</option>
            </select>
        </div>
        <div>
            <label for="panel_size">Формат панели, мм</label>
            <select id="panel_size">
                <option value="">— Выберите формат —</option>
            </select>
        </div>
        <div>
            <label for="thickness">Толщина, мм</label>
            <select id="thickness">
                <option value="">— Выберите толщину —</option>
            </select>
        </div>
        <div>
            <label for="decor">Декор</label>
            <select id="decor">
                <option value="">— Выберите декор —</option>
            </select>
            <div class="hint" id="decor-hint"></div>
        </div>
        <div>
            <label for="embossing">Тиснение</label>
            <select id="embossing">
                <option value="">— Без тиснения —</option>
            </select>
        </div>
    </div>

    <div class="grid" style="margin-top:12px">
        <div>
            <label>Цена за м²</label>
            <input id="price_per_m2" type="number" min="0" step="0.01" placeholder="0">
        </div>
        <div>
            <label>Цена за лист</label>
            <input id="price_per_sheet" type="number" min="0" step="0.01" placeholder="0">
        </div>
        <div>
            <label for="price_currency">Валюта цены</label>
            <select id="price_currency">
                <option value="RUB" selected>Рубли (₽)</option>
                <option value="EUR">Евро (€)</option>
                <option value="USD">Доллар ($)</option>
            </select>
        </div>
        <div id="rate-block">
            <label>Курс</label>
            <div style="display:flex;gap:6px;align-items:center">
                <input id="euro_rate" type="number" min="0" step="0.01" value="100" style="flex:1">
                <select id="rate_mode" style="width:auto;padding:8px 10px;font-size:13px">
                    <option value="manual" selected>Вручную</option>
                    <option value="cbr">ЦБ РФ</option>
                </select>
                <button type="button" class="warning hidden" id="refresh-rates-btn" style="padding:8px 10px;font-size:12px" title="Обновить курсы с ЦБ РФ">🔄</button>
            </div>
            <div class="hint" id="rate-source"></div>
        </div>
    </div>

    <div id="decor-info-block" class="decor-info hidden">
        <span>Декор:</span> <span id="info-decor-name">—</span><br>
        <span>Номер:</span> <span id="info-decor-number">—</span><br>
        <span>Номенклатура:</span> <span id="info-nomenclature">—</span><br>
        <span>Производитель:</span> <span id="info-manufacturer">—</span>
    </div>

    <div class="section-title" style="margin-top:20px">Коэффициенты</div>
    <div class="grid">
        <div>
            <label for="factory_coeff">Коэффициент фабрики</label>
            <input id="factory_coeff" type="number" min="0" step="0.01" value="1">
        </div>
        <div>
            <label for="salon_coeff">Коэффициент салона</label>
            <input id="salon_coeff" type="number" min="0" step="0.01" value="1">
        </div>
    </div>
</section>

<!-- ═══ ДЕТАЛИ ═══ -->
<section class="panel">
    <div class="section-title main">Детали столешницы</div>
    <div style="overflow-x:auto">
    <table class="data-table" id="parts-table">
        <thead>
            <tr>
                <th style="width:50px">№</th>
                <th style="width:130px">Длина, мм</th>
                <th style="width:130px">Ширина, мм</th>
                <th style="width:90px">Кол-во, шт</th>
                <th style="width:110px">Площадь, м²</th>
                <th style="width:110px">Периметр, м.п.</th>
                <th style="width:120px">Стоимость</th>
                <th style="width:50px"></th>
            </tr>
        </thead>
        <tbody id="parts-tbody"></tbody>
        <tfoot>
            <tr class="total-row">
                <td colspan="4" style="text-align:right"><b>ИТОГО 1:</b></td>
                <td class="num" id="total-area">0</td>
                <td class="num" id="total-perimeter">0</td>
                <td class="num" id="total-rub">0</td>
                <td></td>
            </tr>
        </tfoot>
    </table>
    </div>
    <div class="actions-row">
        <button type="button" class="success" id="add-part-btn">+ Добавить деталь</button>
    </div>
</section>

<!-- ═══ ДОПОЛНИТЕЛЬНЫЕ УСЛУГИ ═══ -->
<section class="panel">
    <div class="section-title main">Дополнительные услуги</div>
    <div style="overflow-x:auto">
    <table class="data-table" id="services-table">
        <thead>
            <tr>
                <th style="width:50px">№</th>
                <th>Услуга</th>
                <th style="width:80px">Ед. изм.</th>
                <th style="width:90px">Кол-во</th>
                <th style="width:120px">Цена, за ед.</th>
                <th style="width:120px">Стоимость</th>
                <th style="width:50px"></th>
            </tr>
        </thead>
        <tbody id="services-tbody"></tbody>
        <tfoot>
            <tr class="total-row">
                <td colspan="5" style="text-align:right"><b>ИТОГО 2:</b></td>
                <td class="num" id="total-services-rub">0</td>
                <td></td>
            </tr>
        </tfoot>
    </table>
    </div>
    <div class="actions-row">
        <button type="button" class="success" id="add-service-btn">+ Добавить услугу</button>
    </div>
</section>

<!-- ═══ ИТОГО ═══ -->
<section class="panel">
    <div class="summary-box" id="summary-box">
        <h3>Сводная по стоимости</h3>
        <div class="summary-row"><span>ИТОГО 1 (детали):</span><span id="sum-parts">0</span></div>
        <div class="summary-row"><span>ИТОГО 2 (услуги):</span><span id="sum-services">0</span></div>
        <div class="summary-row">
            <span>Скидка, %:</span>
            <input id="discount" type="number" min="0" max="100" step="0.1" value="0" style="width:100px;text-align:right">
        </div>
        <div class="summary-row grand"><span>ИТОГО (со скидкой):</span><span id="grand-total">0</span></div>
    </div>
    <div class="actions-row">
        <button type="button" id="save-btn">💾 Сохранить расчёт</button>
        <button type="button" class="secondary" id="export-pdf-btn">📄 Экспорт в PDF</button>
    </div>
</section>

<!-- ═══ ПРИМЕЧАНИЯ ═══ -->
<section class="panel">
    <div class="section-title">Примечания</div>
    <div class="note-text">
        <b>1.</b> Стоимость приведена с НДС 20%;<br>
        <b>2.</b> В стоимость включена обработка деталей с кромкой Тип-1/Тип-2/Тип-3 + шлифовка;<br>
        <b>3.</b> Максимальный размер изделия по ширине – 1280 мм (для AP/RR, SX/IP, NW/NN) и 1830 мм (для FH/FH), по длине – 4080 мм;<br>
        <b>4.</b> Минимальный размер изделия по ширине – 50 мм, по длине – 150 мм;<br>
        <b>5.</b> Минимальный радиус скругления внутренних углов – 6 мм, радиус внешних углов – без ограничений;<br>
        <b>6.</b> Соединения столешниц: еврозапил или прямой стык / K-TOP;<br>
        <b>7.</b> Внешние торцы изделий без обработки требуют акратного обращения при монтаже;<br>
        <b>8.</b> Изделия с расстоянием от края выреза до внешнего края &lt; 50 мм идут со спец. упаковкой
    </div>
</section>

<!-- ═══ ИСТОРИЯ ═══ -->
<section class="panel">
    <div class="section-title main">Сохранённые расчёты</div>
    <table class="history-table">
        <thead><tr>
            <th>Название</th>
            <th>Объект</th>
            <th>Итого</th>
            <th>Дата</th>
            <th>Действия</th>
        </tr></thead>
        <tbody>
        <?php if (!$savedCalcs): ?>
            <tr><td colspan="5">Сохранённых расчётов пока нет.</td></tr>
        <?php else: foreach ($savedCalcs as $sc): ?>
            <tr>
                <td><?php echo e($sc['title'] ?: '—'); ?></td>
                <td><?php echo e($sc['object_name'] ?: '—'); ?></td>
                <td style="white-space:nowrap"><?php
                    $pl = json_decode($sc['payload_json'], true) ?: [];
                    $cur = $pl['config']['price_currency'] ?? 'RUB';
                    $sym = ['RUB'=>'₽','EUR'=>'€','USD'=>'$'][$cur] ?? $cur;
                    echo number_format((float)$sc['total_rub'], 2, '.', ' ') . ' ' . e($sym);
                ?></td>
                <td style="white-space:nowrap"><?php echo e(date('d.m.Y H:i', strtotime($sc['updated_at']))); ?></td>
                <td>
                    <button type="button" class="secondary" style="padding:5px 10px;font-size:12px" onclick="loadCalculation(<?php echo (int)$sc['id']; ?>)">Открыть</button>
                    <button type="button" class="danger" style="padding:5px 10px;font-size:12px" onclick="deleteCalculation(<?php echo (int)$sc['id']; ?>)">Удалить</button>
                </td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
</section>

</main>

<script>
/* ═══ ДАННЫЕ ИЗ БД ═══ */
const DB_MANUFACTURERS = <?php echo $manufacturersJson; ?>;
const DB_DECORS        = <?php echo $decorsJson; ?>;
const DB_EMBOSSINGS    = <?php echo $embossingsJson; ?>;
const DB_PANEL_SIZES   = <?php echo $panelSizesJson; ?>;
const DB_THICKNESSES   = <?php echo $thicknessesJson; ?>;
const DB_CURRENCIES    = <?php echo $currenciesJson; ?>;
const DB_EUR_RATE      = <?php echo $eurRateJson; ?>;
const KERF_MM          = <?php echo $kerfMmJson; ?>;
const BLANK_WIDTH_MM   = <?php echo $blankWidthMmJson; ?>;
const DB_PRODUCT_TYPES = <?php echo $productTypesDbJson; ?>;

const PRODUCT_TYPES = {
    kitchen: { name: 'Кухонная столешница', ...DB_PRODUCT_TYPES.kitchen },
    fartuk:  { name: 'Стеновая панель / Фартук', ...DB_PRODUCT_TYPES.fartuk },
    horeca:  { name: 'HoReCa', ...DB_PRODUCT_TYPES.horeca },
    bortik:  { name: 'Бортик / Плинтус', ...DB_PRODUCT_TYPES.bortik }
};

const DEFAULT_SERVICES = [
    { name: 'Обработка кромки по Тип-4 или Тип-5', unit: 'п.м.', basePrice: 280 },
    { name: 'Черновой край', unit: 'п.м.', basePrice: 0 },
    { name: 'Вырез под технику (накладная)', unit: 'шт.', basePrice: 2160 },
    { name: 'Вырез под технику (вровень/подклейка снизу)', unit: 'шт.', basePrice: 4030 },
    { name: 'Подклейка мойки к столешнице*', unit: 'шт.', basePrice: 5000 },
    { name: 'Встроенная мойка из HPL (424×374×17,5 мм)', unit: 'шт.', basePrice: 45000 },
    { name: 'Соединение столешниц (еврозапил/прямой стык)/K-TOP', unit: 'шт.', basePrice: 4200 },
    { name: 'Вырез отверстия Ø до 100 мм/присадка', unit: 'шт.', basePrice: 240 },
    { name: 'Вырез произвольной формы и размера', unit: 'п.м.', basePrice: 1600 },
    { name: 'Гравировка, фреза Ø 1мм', unit: 'п.м.', basePrice: 580 },
    { name: 'Установка мебельной муфты под винт М5, М6, М8', unit: 'шт.', basePrice: 240 },
    { name: 'Изготовление индивидуального паллета', unit: 'шт.', basePrice: 4200 },
    { name: 'Индивидуальная упаковка мягкая', unit: 'шт.', basePrice: 1200 },
    { name: 'Усиленная упаковка', unit: 'шт.', basePrice: 2200 },
    { name: 'Спец. упаковка жёсткая', unit: 'шт.', basePrice: 4200 }
];

/* ═══ СОСТОЯНИЕ ═══ */
let parts = [];
let services = [];
let nextPartId = 1;
let nextServiceId = 1;

/* ═══ УТИЛИТЫ ═══ */
function esc(s) { return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
function fmt(v, d=2) { return new Intl.NumberFormat('ru-RU', {minimumFractionDigits:d, maximumFractionDigits:d}).format(v || 0); }
function fmtInt(v) { return new Intl.NumberFormat('ru-RU', {maximumFractionDigits:0}).format(v || 0); }

function getConfig() {
    const productType = PRODUCT_TYPES[document.getElementById('product_type').value];
    const euroRate = parseFloat(document.getElementById('euro_rate').value) || 0;
    const factoryCoeff = parseFloat(document.getElementById('factory_coeff').value) || 1;
    const salonCoeff = parseFloat(document.getElementById('salon_coeff').value) || 1;
    const pricePerM2 = parseFloat(document.getElementById('price_per_m2').value) || 0;
    const pricePerSheet = parseFloat(document.getElementById('price_per_sheet').value) || 0;
    const priceCurrency = document.getElementById('price_currency').value || 'RUB';
    return { productType, euroRate, factoryCoeff, salonCoeff, pricePerM2, pricePerSheet, priceCurrency };
}

/* ═══ КАСКАДНЫЕ ВЫБОРЫ ═══ */
const manufacturerSel = document.getElementById('manufacturer');
const panelSizeSel = document.getElementById('panel_size');
const thicknessSel = document.getElementById('thickness');
const decorSel = document.getElementById('decor');
const embossingSel = document.getElementById('embossing');

function populateManufacturers() {
    manufacturerSel.innerHTML = '<option value="">— Любой производитель —</option>';
    DB_MANUFACTURERS.forEach(m => {
        manufacturerSel.innerHTML += `<option value="${m.id}">${esc(m.full_name)}</option>`;
    });
}

function populatePanelSizes() {
    const mfrId = manufacturerSel.value;
    panelSizeSel.innerHTML = '<option value="">— Выберите формат —</option>';
    DB_PANEL_SIZES.forEach(ps => {
        if (mfrId && ps.manufacturer_id && String(ps.manufacturer_id) !== mfrId) return;
        const label = `${ps.height_mm}×${ps.width_mm} мм` + (ps.manufacturer_name ? ` (${ps.manufacturer_name})` : '');
        panelSizeSel.innerHTML += `<option value="${ps.id}" data-h="${ps.height_mm}" data-w="${ps.width_mm}">${esc(label)}</option>`;
    });
}

function populateThicknesses() {
    const mfrId = manufacturerSel.value;
    const sizeId = panelSizeSel.value;
    const availableThicknessIds = new Set();
    DB_DECORS.forEach(d => {
        if (mfrId && d.manufacturer_id && String(d.manufacturer_id) !== mfrId) return;
        if (sizeId && d.panel_size_id && String(d.panel_size_id) !== sizeId) return;
        if (d.thickness_id) availableThicknessIds.add(String(d.thickness_id));
    });
    const prevVal = thicknessSel.value;
    thicknessSel.innerHTML = '<option value="">— Выберите толщину —</option>';
    DB_THICKNESSES.forEach(t => {
        if (availableThicknessIds.size > 0 && !availableThicknessIds.has(String(t.id))) return;
        const opt = document.createElement('option');
        opt.value = t.id;
        opt.textContent = `${parseFloat(t.thickness)} мм`;
        if (String(t.id) === prevVal) opt.selected = true;
        thicknessSel.appendChild(opt);
    });
}

function populateDecors() {
    const mfrId = manufacturerSel.value;
    const sizeId = panelSizeSel.value;
    const thickId = thicknessSel.value;
    decorSel.innerHTML = '<option value="">— Выберите декор —</option>';
    DB_DECORS.forEach(d => {
        if (mfrId && d.manufacturer_id && String(d.manufacturer_id) !== mfrId) return;
        if (sizeId && d.panel_size_id && String(d.panel_size_id) !== sizeId) return;
        if (thickId && d.thickness_id && String(d.thickness_id) !== thickId) return;
        const label = [d.decor_number, d.decor_name].filter(Boolean).join(' ') || d.name;
        const extra = d.manufacturer_name ? ` — ${d.manufacturer_name}` : '';
        const opt = document.createElement('option');
        opt.value = d.id;
        opt.textContent = label + extra;
        opt.dataset.priceM2 = d.price_per_m2 || 0;
        opt.dataset.priceSheet = d.price_per_sheet || d.cost_per_sheet || 0;
        opt.dataset.currency = d.currency || 'RUB';
        opt.dataset.decorNumber = d.decor_number || '';
        opt.dataset.decorName = d.decor_name || '';
        opt.dataset.nomenclature = d.name || '';
        opt.dataset.manufacturerName = d.manufacturer_name || '';
        opt.dataset.embossingId = d.embossing_id || '';
        decorSel.appendChild(opt);
    });
}

function populateEmbossings() {
    const mfrId = manufacturerSel.value;
    const decorOpt = decorSel.selectedOptions[0];
    const decorEmbId = decorOpt?.dataset?.embossingId || '';
    const sizeId = panelSizeSel.value;
    const thickId = thicknessSel.value;
    const availableEmbIds = new Set();
    DB_DECORS.forEach(d => {
        if (mfrId && d.manufacturer_id && String(d.manufacturer_id) !== mfrId) return;
        if (sizeId && d.panel_size_id && String(d.panel_size_id) !== sizeId) return;
        if (thickId && d.thickness_id && String(d.thickness_id) !== thickId) return;
        if (d.embossing_id) availableEmbIds.add(String(d.embossing_id));
    });
    const prevVal = embossingSel.value;
    embossingSel.innerHTML = '<option value="">— Без тиснения —</option>';
    DB_EMBOSSINGS.forEach(e => {
        if (mfrId && e.manufacturer_id && String(e.manufacturer_id) !== mfrId) return;
        if (availableEmbIds.size > 0 && !availableEmbIds.has(String(e.id))) return;
        embossingSel.innerHTML += `<option value="${e.id}">${esc(e.name)}${e.short_name ? ' (' + esc(e.short_name) + ')' : ''}</option>`;
    });
    if (decorEmbId && embossingSel.querySelector(`option[value="${decorEmbId}"]`)) {
        embossingSel.value = decorEmbId;
    } else if (prevVal && embossingSel.querySelector(`option[value="${prevVal}"]`)) {
        embossingSel.value = prevVal;
    }
}

manufacturerSel.addEventListener('change', () => {
    populatePanelSizes();
    populateThicknesses();
    populateDecors();
    populateEmbossings();
    updateDecorInfo();
    renderParts();
    renderServices();
});

panelSizeSel.addEventListener('change', () => {
    populateThicknesses();
    populateDecors();
    populateEmbossings();
    updateDecorInfo();
    renderParts();
});

thicknessSel.addEventListener('change', () => {
    populateDecors();
    populateEmbossings();
    updateDecorInfo();
    renderParts();
});

let dbPriceM2 = 0, dbPriceSheet = 0, dbCurrency = 'RUB';
let origPriceM2 = 0, origPriceSheet = 0, origCurrency = 'RUB';

function applyCurrencyPrice() {
    const toCur = document.getElementById('price_currency').value;
    if (origPriceM2 > 0) {
        const v = toCur === origCurrency ? origPriceM2 : Math.round(convertTo(origPriceM2, origCurrency, toCur) * 100) / 100;
        document.getElementById('price_per_m2').value = v || '';
        dbPriceM2 = v;
    }
    if (origPriceSheet > 0) {
        const v = toCur === origCurrency ? origPriceSheet : Math.round(convertTo(origPriceSheet, origCurrency, toCur) * 100) / 100;
        document.getElementById('price_per_sheet').value = v || '';
        dbPriceSheet = v;
    }
    dbCurrency = toCur;
}

decorSel.addEventListener('change', () => {
    const opt = decorSel.selectedOptions[0];
    if (opt) {
        origPriceM2 = parseFloat(opt.dataset.priceM2) || 0;
        origPriceSheet = parseFloat(opt.dataset.priceSheet) || 0;
        origCurrency = opt.dataset.currency || 'RUB';
        document.getElementById('price_currency').value = origCurrency;
        applyCurrencyPrice();
    }
    populateEmbossings();
    updateDecorInfo();
    renderParts();
});

document.getElementById('price_currency').addEventListener('change', async () => {
    const toCur = document.getElementById('price_currency').value;
    document.getElementById('rate-block').classList.toggle('hidden', toCur === 'RUB');
    if (toCur !== 'RUB' && rateModeSel.value === 'cbr') await fetchCbrRate();
    applyCurrencyPrice();
    renderParts(); renderServices();
});

function updateDecorInfo() {
    const block = document.getElementById('decor-info-block');
    const opt = decorSel.selectedOptions[0];
    if (!opt || !opt.value) { block.classList.add('hidden'); return; }
    block.classList.remove('hidden');
    document.getElementById('info-decor-name').textContent = opt.dataset.decorName || '—';
    document.getElementById('info-decor-number').textContent = opt.dataset.decorNumber || '—';
    document.getElementById('info-nomenclature').textContent = opt.dataset.nomenclature || '—';
    document.getElementById('info-manufacturer').textContent = opt.dataset.manufacturerName || '—';
}

/* ═══ КУРС ВАЛЮТ ═══ */
const rateModeSel = document.getElementById('rate_mode');
const euroRateInput = document.getElementById('euro_rate');
const refreshBtn = document.getElementById('refresh-rates-btn');
const rateSourceHint = document.getElementById('rate-source');

function onRateModeChange() {
    const isCbr = rateModeSel.value === 'cbr';
    euroRateInput.readOnly = isCbr;
    refreshBtn.classList.toggle('hidden', !isCbr);
    if (isCbr) fetchCbrRate();
    else rateSourceHint.textContent = '';
}

async function fetchCbrRate() {
    refreshBtn.disabled = true;
    rateSourceHint.textContent = 'Загрузка…';
    try {
        const resp = await fetch('calculator_countertops.php?action=refresh_rates', { method: 'POST' });
        const data = await resp.json();
        if (data.ok && data.rates) {
            const cur = document.getElementById('price_currency').value;
            const rate = data.rates[cur] || data.rates['EUR'] || 0;
            euroRateInput.value = rate;
            rateSourceHint.textContent = 'Курс ЦБ РФ (' + cur + ')';
            applyCurrencyPrice();
            renderParts(); renderServices();
        } else {
            rateSourceHint.textContent = 'Ошибка загрузки';
        }
    } catch (e) {
        rateSourceHint.textContent = 'Ошибка сети';
    }
    refreshBtn.disabled = false;
}

rateModeSel.addEventListener('change', onRateModeChange);
refreshBtn.addEventListener('click', fetchCbrRate);
onRateModeChange();
document.getElementById('rate-block').classList.toggle('hidden', document.getElementById('price_currency').value === 'RUB');

/* ═══ РАСЧЁТ СТОИМОСТИ ДЕТАЛИ ═══ */
function getRateToRub(code) {
    if (code === 'RUB') return 1;
    const eurRate = parseFloat(document.getElementById('euro_rate').value) || 0;
    if (code === 'EUR') return eurRate;
    if (code === 'USD') {
        const r = DB_CURRENCIES.find(c => c.code === 'USD');
        return r ? r.rate_to_rub : eurRate;
    }
    return 1;
}

function convertTo(amount, fromCurrency, toCurrency) {
    const rubAmount = amount * getRateToRub(fromCurrency);
    const targetRate = getRateToRub(toCurrency);
    return targetRate > 0 ? rubAmount / targetRate : rubAmount;
}

function currencySymbol(code) {
    return { RUB: '₽', EUR: '€', USD: '$' }[code] || code;
}

function calcPartCost(length, width, qty) {
    const { productType, factoryCoeff, salonCoeff, pricePerM2, pricePerSheet, priceCurrency } = getConfig();
    if (qty <= 0 || length <= 0 || width <= 0) return { area: 0, perimeter: 0, cost: 0 };

    const area = (length * width * qty) / 1000000;
    const perimeter = ((length + width) * 2 * qty) / 1000;

    const sizeOpt = panelSizeSel.selectedOptions[0];
    const sheetW = parseFloat(sizeOpt?.dataset?.w) || 0;
    const sheetH = parseFloat(sizeOpt?.dataset?.h) || 0;
    const sheetAreaM2 = (sheetW * sheetH) / 1000000;

    let sheetPrice = pricePerSheet;
    if (!sheetPrice && pricePerM2 > 0 && sheetAreaM2 > 0) {
        sheetPrice = pricePerM2 * sheetAreaM2;
    }

    let materialCost;
    if (sheetPrice > 0 && sheetW > 0) {
        const blanks = Math.floor(sheetW / BLANK_WIDTH_MM);
        const kerfHalf = KERF_MM / 2;
        if (blanks > 0) {
            const blankWidth = (sheetW / blanks) - kerfHalf;
            let blanksNeeded = blankWidth > 0 ? Math.ceil(width / blankWidth) : blanks;
            blanksNeeded = Math.min(blanksNeeded, blanks);
            materialCost = (blanksNeeded / blanks) * sheetPrice * qty;
        } else {
            materialCost = sheetPrice * qty;
        }
    } else {
        materialCost = pricePerM2 * area;
    }
    const processingCost = convertTo(productType.processingPerM, 'EUR', priceCurrency) * perimeter;

    const raw = materialCost + processingCost;
    const cost = Math.ceil(raw) * factoryCoeff * salonCoeff;
    return { area, perimeter, cost };
}

/* ═══ ТАБЛИЦА ДЕТАЛЕЙ ═══ */
const partsTbody = document.getElementById('parts-tbody');

function renderParts() {
    partsTbody.innerHTML = '';
    const { priceCurrency } = getConfig();
    const sym = currencySymbol(priceCurrency);

    parts.forEach((p, idx) => {
        const c = calcPartCost(p.length, p.width, p.qty);

        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td>${idx + 1}</td>
            <td><input type="number" min="1" value="${p.length || ''}" data-id="${p.id}" data-field="length" style="width:100%;box-sizing:border-box"></td>
            <td><input type="number" min="1" value="${p.width || ''}" data-id="${p.id}" data-field="width" style="width:100%;box-sizing:border-box"></td>
            <td><input type="number" min="1" value="${p.qty}" data-id="${p.id}" data-field="qty" style="width:100%;box-sizing:border-box"></td>
            <td class="num">${c.area > 0 ? fmt(c.area, 4) : '—'}</td>
            <td class="num">${c.perimeter > 0 ? fmt(c.perimeter, 2) : '—'}</td>
            <td class="num">${c.cost > 0 ? fmtInt(c.cost) + ' ' + sym : '—'}</td>
            <td><button type="button" class="rm-btn" data-id="${p.id}">✕</button></td>
        `;
        partsTbody.appendChild(tr);
    });

    updatePartTotals();
}

function updatePartTotals() {
    const { priceCurrency } = getConfig();
    const sym = currencySymbol(priceCurrency);
    let totArea = 0, totPerim = 0, totCost = 0;
    parts.forEach((p, idx) => {
        const c = calcPartCost(p.length, p.width, p.qty);
        totArea += c.area;
        totPerim += c.perimeter;
        totCost += c.cost;
        const row = partsTbody.children[idx];
        if (row) {
            const cells = row.querySelectorAll('td.num');
            if (cells[0]) cells[0].textContent = c.area > 0 ? fmt(c.area, 4) : '—';
            if (cells[1]) cells[1].textContent = c.perimeter > 0 ? fmt(c.perimeter, 2) : '—';
            if (cells[2]) cells[2].textContent = c.cost > 0 ? fmtInt(c.cost) + ' ' + sym : '—';
        }
    });
    document.getElementById('total-area').textContent = fmt(totArea, 4);
    document.getElementById('total-perimeter').textContent = fmt(totPerim, 2);
    document.getElementById('total-rub').textContent = fmtInt(totCost) + ' ' + sym;
    updateSummary();
}

partsTbody.addEventListener('input', e => {
    if (!e.target.dataset.field) return;
    const id = parseInt(e.target.dataset.id);
    const p = parts.find(x => x.id === id);
    if (!p) return;
    const val = parseFloat(e.target.value);
    if (e.target.dataset.field === 'qty') p.qty = Math.max(1, parseInt(val) || 1);
    else p[e.target.dataset.field] = Math.max(0, val || 0);
    updatePartTotals();
});

partsTbody.addEventListener('click', e => {
    const btn = e.target.closest('.rm-btn');
    if (!btn) return;
    parts = parts.filter(x => x.id !== parseInt(btn.dataset.id));
    renderParts();
});

document.getElementById('add-part-btn').addEventListener('click', () => {
    parts.push({ id: nextPartId++, length: 0, width: 0, qty: 1 });
    renderParts();
    const last = partsTbody.querySelector('tr:last-child input[data-field="length"]');
    if (last) last.focus();
});

/* ═══ ТАБЛИЦА УСЛУГ ═══ */
const servicesTbody = document.getElementById('services-tbody');

function renderServices() {
    servicesTbody.innerHTML = '';
    const { factoryCoeff, priceCurrency } = getConfig();
    const sym = currencySymbol(priceCurrency);
    let totServices = 0;

    services.forEach((s, idx) => {
        const priceRub = s.basePrice * factoryCoeff;
        const price = Math.round(convertTo(priceRub, 'RUB', priceCurrency));
        const cost = price * s.qty;
        totServices += cost;

        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td>${idx + 1}</td>
            <td>${esc(s.name)}<div class="service-unit">${esc(s.unit)}</div></td>
            <td>${esc(s.unit)}</td>
            <td><input type="number" min="0" value="${s.qty}" data-id="${s.id}" data-field="qty" style="width:100%;box-sizing:border-box"></td>
            <td class="num">${fmtInt(price)} ${sym}</td>
            <td class="num">${cost > 0 ? fmtInt(cost) + ' ' + sym : '—'}</td>
            <td><button type="button" class="rm-btn" data-id="${s.id}">✕</button></td>
        `;
        servicesTbody.appendChild(tr);
    });

    document.getElementById('total-services-rub').textContent = fmtInt(totServices) + ' ' + sym;
    updateSummary();
}

servicesTbody.addEventListener('input', e => {
    if (!e.target.dataset.field) return;
    const id = parseInt(e.target.dataset.id);
    const s = services.find(x => x.id === id);
    if (!s) return;
    s.qty = Math.max(0, parseInt(e.target.value) || 0);
    renderServices();
});

servicesTbody.addEventListener('click', e => {
    const btn = e.target.closest('.rm-btn');
    if (!btn) return;
    services = services.filter(x => x.id !== parseInt(btn.dataset.id));
    renderServices();
});

document.getElementById('add-service-btn').addEventListener('click', openServiceModal);

function openServiceModal() {
    let html = '<option value="">— Выберите услугу —</option>';
    DEFAULT_SERVICES.forEach((s, i) => { html += `<option value="${i}">${esc(s.name)}</option>`; });
    html += '<option value="custom">Другая услуга…</option>';

    const overlay = document.createElement('div');
    overlay.className = 'modal-overlay';
    overlay.innerHTML = `
        <div class="modal-box">
            <h3>Добавить услугу</h3>
            <div style="margin-bottom:14px"><label>Услуга</label><select id="svc-select">${html}</select></div>
            <div id="svc-custom-wrap" class="hidden">
                <div style="margin-bottom:14px"><label>Название</label><input id="svc-name" type="text"></div>
                <div style="margin-bottom:14px"><label>Ед. изм.</label><input id="svc-unit" type="text" value="шт."></div>
                <div style="margin-bottom:14px"><label>Цена, за ед. (₽)</label><input id="svc-price" type="number" min="0" step="1" value="0"></div>
            </div>
            <div style="margin-bottom:14px"><label>Количество</label><input id="svc-qty" type="number" min="1" step="1" value="1"></div>
            <div class="actions-row" style="margin-top:0">
                <button type="button" id="svc-save-btn">Добавить</button>
                <button type="button" class="secondary" id="svc-cancel-btn">Отмена</button>
            </div>
        </div>
    `;
    document.body.appendChild(overlay);

    const sel = overlay.querySelector('#svc-select');
    const wrap = overlay.querySelector('#svc-custom-wrap');
    sel.addEventListener('change', () => wrap.classList.toggle('hidden', sel.value !== 'custom'));
    overlay.querySelector('#svc-cancel-btn').addEventListener('click', () => overlay.remove());
    overlay.addEventListener('click', e => { if (e.target === overlay) overlay.remove(); });

    overlay.querySelector('#svc-save-btn').addEventListener('click', () => {
        const qty = parseInt(overlay.querySelector('#svc-qty').value) || 1;
        if (sel.value === 'custom') {
            const name = overlay.querySelector('#svc-name').value.trim();
            const unit = overlay.querySelector('#svc-unit').value.trim() || 'шт.';
            const price = parseFloat(overlay.querySelector('#svc-price').value) || 0;
            if (!name) { alert('Укажите название.'); return; }
            services.push({ id: nextServiceId++, name, unit, basePrice: price, qty });
        } else if (sel.value !== '') {
            const src = DEFAULT_SERVICES[parseInt(sel.value)];
            services.push({ id: nextServiceId++, name: src.name, unit: src.unit, basePrice: src.basePrice, qty });
        } else { alert('Выберите услугу.'); return; }
        overlay.remove();
        renderServices();
    });
}


/* ═══ СВОДКА ═══ */
function updateSummary() {
    const { factoryCoeff, priceCurrency } = getConfig();
    const sym = currencySymbol(priceCurrency);
    let totParts = 0;
    parts.forEach(p => { totParts += calcPartCost(p.length, p.width, p.qty).cost; });

    let totServices = 0;
    services.forEach(s => {
        const priceRub = s.basePrice * factoryCoeff;
        totServices += Math.round(convertTo(priceRub, 'RUB', priceCurrency)) * s.qty;
    });

    const discount = parseFloat(document.getElementById('discount').value) || 0;
    const grandTotal = (totParts + totServices) * (1 - discount / 100);

    document.getElementById('sum-parts').textContent = fmtInt(totParts) + ' ' + sym;
    document.getElementById('sum-services').textContent = fmtInt(totServices) + ' ' + sym;
    document.getElementById('grand-total').textContent = fmtInt(grandTotal) + ' ' + sym;
}

/* ═══ ПЕРЕСЧЁТ ═══ */
['product_type', 'manufacturer', 'panel_size', 'thickness', 'decor', 'embossing'].forEach(id => {
    document.getElementById(id).addEventListener('change', () => { renderParts(); renderServices(); });
});
['euro_rate', 'factory_coeff', 'salon_coeff', 'price_per_m2', 'price_per_sheet', 'discount'].forEach(id => {
    document.getElementById(id).addEventListener('input', () => { renderParts(); renderServices(); });
});

/* ═══ СОХРАНЕНИЕ ═══ */
document.getElementById('save-btn').addEventListener('click', async () => {
    const { productType, euroRate, factoryCoeff, salonCoeff, pricePerM2, pricePerSheet, priceCurrency } = getConfig();
    let totParts = 0;
    parts.forEach(p => { totParts += calcPartCost(p.length, p.width, p.qty).cost; });
    let totServices = 0;
    services.forEach(s => {
        const priceRub = s.basePrice * factoryCoeff;
        totServices += Math.round(convertTo(priceRub, 'RUB', priceCurrency)) * s.qty;
    });
    const discount = parseFloat(document.getElementById('discount').value) || 0;
    const grandTotal = (totParts + totServices) * (1 - discount / 100);

    const decorOpt = decorSel.selectedOptions[0];
    const sizeOpt = panelSizeSel.selectedOptions[0];

    const payload = {
        title: document.getElementById('calc_title').value,
        object_name: document.getElementById('object_name').value,
        total_rub: grandTotal,
        config: {
            product_type: document.getElementById('product_type').value,
            product_type_name: productType.name,
            manufacturer_id: manufacturerSel.value,
            manufacturer_name: manufacturerSel.selectedOptions[0]?.textContent || '',
            panel_size_id: panelSizeSel.value,
            panel_size_label: sizeOpt?.textContent || '',
            thickness_id: thicknessSel.value,
            thickness_label: thicknessSel.selectedOptions[0]?.textContent || '',
            decor_id: decorSel.value,
            decor_number: decorOpt?.dataset.decorNumber || '',
            decor_name: decorOpt?.dataset.decorName || '',
            embossing_id: embossingSel.value,
            embossing_name: embossingSel.selectedOptions[0]?.textContent || '',
            price_per_m2: pricePerM2,
            price_per_sheet: pricePerSheet,
            price_currency: priceCurrency,
            processing_per_m: productType.processingPerM,
            euro_rate: euroRate,
            rate_mode: rateModeSel.value,
            factory_coeff: factoryCoeff,
            salon_coeff: salonCoeff,
            discount: discount
        },
        parts: parts.map(p => {
            const c = calcPartCost(p.length, p.width, p.qty);
            return { ...p, area: c.area, perimeter: c.perimeter, cost: c.cost };
        }),
        services: services.map(s => {
            const priceRub = s.basePrice * factoryCoeff;
            const price = Math.round(convertTo(priceRub, 'RUB', priceCurrency));
            return { ...s, price, cost: price * s.qty };
        }),
        totals: { totParts, totServices, grandTotal }
    };

    try {
        const resp = await fetch('calculator_countertops.php?action=save', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const data = await resp.json();
        if (data.ok) { alert('Расчёт сохранён.'); location.reload(); }
        else alert('Ошибка: ' + (data.error || 'неизвестная'));
    } catch (e) { alert('Ошибка сохранения: ' + e.message); }
});

/* ═══ ЗАГРУЗКА ═══ */
window.loadCalculation = async function(id) {
    try {
        const resp = await fetch('calculator_countertops.php?action=load&id=' + id);
        const data = await resp.json();
        if (!data.ok) { alert('Не удалось загрузить.'); return; }
        const payload = data.payload || {};
        const cfg = payload.config || {};

        document.getElementById('object_name').value = payload.object_name || '';
        document.getElementById('calc_title').value = payload.title || '';
        if (cfg.product_type) document.getElementById('product_type').value = cfg.product_type;
        if (cfg.manufacturer_id) manufacturerSel.value = cfg.manufacturer_id;
        populatePanelSizes();
        if (cfg.panel_size_id) panelSizeSel.value = cfg.panel_size_id;
        populateThicknesses();
        if (cfg.thickness_id) thicknessSel.value = cfg.thickness_id;
        populateDecors();
        if (cfg.decor_id) decorSel.value = cfg.decor_id;
        populateEmbossings();
        if (cfg.embossing_id) embossingSel.value = cfg.embossing_id;
        if (cfg.euro_rate) document.getElementById('euro_rate').value = cfg.euro_rate;
        if (cfg.rate_mode) { rateModeSel.value = cfg.rate_mode; onRateModeChange(); }
        if (cfg.factory_coeff !== undefined) document.getElementById('factory_coeff').value = cfg.factory_coeff;
        if (cfg.salon_coeff !== undefined) document.getElementById('salon_coeff').value = cfg.salon_coeff;
        if (cfg.discount !== undefined) document.getElementById('discount').value = cfg.discount;
        if (cfg.price_per_m2 !== undefined) document.getElementById('price_per_m2').value = cfg.price_per_m2;
        if (cfg.price_per_sheet !== undefined) document.getElementById('price_per_sheet').value = cfg.price_per_sheet;
        if (cfg.price_currency) {
            document.getElementById('price_currency').value = cfg.price_currency;
            document.getElementById('rate-block').classList.toggle('hidden', cfg.price_currency === 'RUB');
        }
        origPriceM2 = parseFloat(cfg.price_per_m2) || 0;
        origPriceSheet = parseFloat(cfg.price_per_sheet) || 0;
        origCurrency = cfg.price_currency || 'RUB';
        dbPriceM2 = origPriceM2;
        dbPriceSheet = origPriceSheet;
        dbCurrency = origCurrency;

        parts = []; nextPartId = 1;
        (payload.parts || []).forEach(p => parts.push({ id: nextPartId++, length: p.length, width: p.width, qty: p.qty }));
        services = []; nextServiceId = 1;
        (payload.services || []).forEach(s => services.push({ id: nextServiceId++, name: s.name, unit: s.unit, basePrice: s.basePrice, qty: s.qty }));

        updateDecorInfo();
        renderParts(); renderServices();
        window.scrollTo({ top: 0, behavior: 'smooth' });
    } catch (e) { alert('Ошибка загрузки: ' + e.message); }
};

window.deleteCalculation = async function(id) {
    if (!confirm('Удалить расчёт?')) return;
    try {
        await fetch('calculator_countertops.php?action=delete', {
            method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=delete&id=' + id
        });
        location.reload();
    } catch (e) { alert('Ошибка: ' + e.message); }
};

/* ═══ ИНИЦИАЛИЗАЦИЯ ═══ */
populateManufacturers();
populatePanelSizes();
populateThicknesses();
populateDecors();
populateEmbossings();
renderParts();

services = DEFAULT_SERVICES.map((s, i) => ({ id: nextServiceId++, name: s.name, unit: s.unit, basePrice: s.basePrice, qty: 0 }));
renderServices();

document.getElementById('export-pdf-btn').addEventListener('click', () => window.print());
</script>

</body>
</html>
