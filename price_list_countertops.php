<?php
require_once __DIR__ . '/includes/admin_auth.php';
require_once __DIR__ . '/includes/app_header.php';
require_login();
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/admin_schema.php';

ensure_currencies_table($pdo);
ensure_price_types_table($pdo);
ensure_organization_table($pdo);

$activeCurrencies = $pdo->query("SELECT code, name, rate_to_rub FROM currencies WHERE is_active = 1 ORDER BY code = 'RUB' DESC, code ASC")->fetchAll();
$priceTypes = $pdo->query("SELECT id, name FROM price_types WHERE is_active = 1 ORDER BY sort_order ASC, id ASC")->fetchAll();
$organization = $pdo->query('SELECT short_name, full_name, logo_path, address, phone, email, website FROM organization_settings WHERE id = 1')->fetch() ?: [];

$selectedCurrency = $_GET['currency'] ?? 'EUR';
$selectedPriceType = $_GET['price_type'] ?? '';
$validFrom = $_GET['valid_from'] ?? date('Y-m-d');

$currencyRate = 1;
foreach ($activeCurrencies as $c) {
    if ($c['code'] === $selectedCurrency) {
        $currencyRate = (float)$c['rate_to_rub'];
        break;
    }
}

$priceTypeName = '';
foreach ($priceTypes as $pt) {
    if ((string)$pt['id'] === $selectedPriceType) {
        $priceTypeName = $pt['name'];
        break;
    }
}
if ($priceTypeName === '' && !empty($priceTypes)) {
    $priceTypeName = $priceTypes[0]['name'];
}

$logoPath = trim((string)($organization['logo_path'] ?? ''));
$orgName = trim((string)(($organization['short_name'] ?? '') ?: ($organization['full_name'] ?? '') ?: 'ООО «Декотек Инжиниринг»'));
$orgFullName = trim((string)($organization['full_name'] ?? '') ?: 'ООО «Декотек Инжиниринг»');
$orgAddress = trim((string)($organization['address'] ?? '') ?: '115114, РФ, г. Москва, Кожевнический проезд, д. 13');
$orgPhone = trim((string)($organization['phone'] ?? '') ?: '+7 (495) 258-07-48');
$orgEmail = trim((string)($organization['email'] ?? '') ?: 'hpl@dekotech.ru');
$orgWebsite = trim((string)($organization['website'] ?? '') ?: 'www.dekotech.ru');
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<title>Прайс-лист — Столешницы</title>
<script src="https://cdn.jsdelivr.net/npm/html2pdf.js@0.10.1/dist/html2pdf.bundle.min.js" defer></script>
<style>
body { font-family: 'Inter', Arial, sans-serif; background: #f6f8fb; margin: 0; color: #0f172a; }
.container { max-width: 1440px; margin: 20px auto 40px; padding: 0 10px; }
.panel { background: #fff; border: 1px solid #dfe6ef; border-radius: 16px; padding: 28px; margin-bottom: 24px; box-shadow: 0 2px 5px rgba(15,23,42,.07); }
.section-title { font-size: 14px; font-weight: 800; color: #0f172a; padding: 0 0 13px; border-bottom: 1px solid #e8edf4; margin: 0 0 16px; }
.section-title:before { content: '✦'; color: #ed174c; margin-right: 9px; }
.section-title.main { font-size: 17px; margin-top: 0; border-left: 0; }
.section-title.main:before { content: '⚙'; font-size: 18px; }
label { display: block; font-weight: 600; margin-bottom: 6px; font-size: 13px; color: #344258; }
input, select { width: 100%; box-sizing: border-box; padding: 9px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 14px; background: #fff; }
button, .button { border: 0; border-radius: 9px; padding: 10px 16px; background: #4f46e5; color: #fff; text-decoration: none; cursor: pointer; display: inline-block; font-weight: 700; font-size: 13px; }
.button.secondary, button.secondary { background: #64748b; color: #fff; }
.button.success, button.success { background: #e9164d; color: #fff; }
.button.danger, button.danger { background: #fee2e2; color: #991b1b; }
.hint { color: #64748b; font-size: 13px; margin-top: 4px; }
.actions-row { display: flex; gap: 10px; margin-top: 18px; flex-wrap: wrap; align-items: center; }
.filters-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 14px; align-items: end; }
.filters-grid .submit-wrap { display: flex; align-items: end; }
.filters-grid button[type="submit"] { background: #e9164d; width: 100%; min-height: 40px; }
.toolbar { position: sticky; top: 12px; z-index: 20; display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 14px; }
.toolbar__group { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
.toolbar button { min-height: 40px; white-space: nowrap; }
.toolbar button.primary { background: #e9164d; }
.toolbar small { color: #64748b; line-height: 1.35; font-size: 12px; max-width: 420px; }
.back-link { display: inline-flex; align-items: center; gap: 6px; margin-bottom: 16px; padding: 9px 14px; border-radius: 9px; background: #fff; color: #172033; text-decoration: none; font-weight: 700; font-size: 13px; border: 1px solid #dfe6ef; box-shadow: 0 2px 5px rgba(15,23,42,.05); }
.back-link:hover { border-color: #c8d5e5; background: #f8fafc; }
.price-list-paper { background: #fff; color: #0f172a; padding: 40px 44px; border: 1px solid #dfe6ef; border-radius: 16px; box-shadow: 0 2px 5px rgba(15,23,42,.07); overflow: hidden; }
.price-list-head { display: flex; justify-content: space-between; gap: 24px; align-items: flex-start; min-height: 120px; margin-bottom: 24px; padding-bottom: 18px; border-bottom: 1px solid #e8edf4; }
.logo-box { width: 120px; height: 120px; border: 1px solid #dfe6ef; border-radius: 14px; display: flex; align-items: center; justify-content: center; text-align: center; font-weight: 900; color: #172033; line-height: 1.1; background: #f8fafc; font-size: 13px; }
.logo-box small { display: block; margin-top: 4px; color: #64748b; font-weight: 600; font-size: 10px; }
.company-info { text-align: right; font-size: 12px; line-height: 1.5; max-width: 520px; color: #334155; }
.price-meta { margin-top: 18px; display: grid; grid-template-columns: auto auto; gap: 4px 16px; justify-content: end; font-weight: 800; color: #172033; }
.price-meta input { border: 0; background: transparent; color: #e9164d; font-weight: 900; width: 110px; padding: 0; text-align: right; }
.warehouse-section { margin: 28px 0; padding: 0; }
.warehouse-title-wrap { border-bottom: 2px solid #e8edf4; padding-bottom: 12px; margin-bottom: 18px; }
.warehouse-title { font-size: 22px; font-weight: 900; color: #0f172a; margin: 0; text-align: left; background: transparent; width: 100%; padding: 6px 0; border: 0; letter-spacing: -.01em; }
.warehouse-title:hover, .warehouse-title:focus { background: #f8fafc; outline: none; border-radius: 8px; padding-left: 8px; padding-right: 8px; }
.program-title-input { font-size: 16px; font-weight: 850; color: #172033; margin: 22px 0 4px 0; border: none; background: transparent; width: 100%; padding: 4px 0; border-radius: 6px; }
.program-title-input:hover, .program-title-input:focus { background: #f8fafc; outline: none; padding-left: 8px; padding-right: 8px; }
.program-subtitle-input { font-size: 12px; color: #64748b; margin: 0 0 12px 0; border: none; background: transparent; width: 100%; padding: 2px 0; border-radius: 6px; }
.program-subtitle-input:hover, .program-subtitle-input:focus { background: #f8fafc; outline: none; padding-left: 8px; padding-right: 8px; }
.price-list-paper table, .price-block table { width: 100%; border-collapse: collapse; font-size: 13px; margin-bottom: 8px; background: #fff; }
.price-list-paper thead th, .price-block thead th { background: #f0f4f8; color: #07152d; padding: 11px 10px; text-align: center; font-weight: 800; font-size: 11px; text-transform: uppercase; border-bottom: 1px solid #e7edf4; white-space: nowrap; }
.price-list-paper thead th.text-left, .price-block thead th.text-left { text-align: left; }
.price-list-paper tbody td, .price-block tbody td { padding: 10px 10px; border-bottom: 1px solid #e7edf4; vertical-align: middle; color: #334155; }
.price-list-paper tbody tr:hover, .price-block tbody tr:hover { background: #f8fafc; }
td.price { text-align: right; white-space: nowrap; font-weight: 700; color: #183052; font-variant-numeric: tabular-nums; }
td.texture { white-space: nowrap; font-weight: 700; color: #475569; }
td.decors { color: #1f2937; }
td.na { color: #94a3b8; text-align: center; font-weight: 600; }
.block-tools, .row-tools { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin: 12px 0 14px; padding: 12px; background: #f8fafc; border: 1px solid #e8edf4; border-radius: 12px; }
.block-tools input { border: 1px solid #cbd5e1; border-radius: 8px; padding: 8px 10px; width: 130px; min-height: 38px; font-size: 13px; background: #fff; }
.block-tools button, .row-tools button { min-height: 38px; padding: 8px 12px; }
.price-block { position: relative; margin: 18px 0 28px; padding-top: 4px; }
.price-block [contenteditable="true"] { outline: 1px dashed transparent; border-radius: 4px; }
.price-block [contenteditable="true"]:focus { outline-color: #93c5fd; background: #eff6ff; }
.price-block tbody tr.is-selected td { background: #fff1f4; }
.price-block .price, .price-block .na { text-align: right; white-space: nowrap; }
.price-block .texture { text-align: left; font-weight: 700; white-space: normal; }
.price-block .decors { text-align: left; font-weight: 600; }
.export-notes { margin-top: 28px; padding-top: 18px; border-top: 1px solid #e8edf4; font-size: 12px; line-height: 1.5; white-space: pre-wrap; color: #475569; }
@media (max-width: 900px) {
  .container { padding: 0; }
  .panel, .price-list-paper { padding: 18px; }
  .price-list-head { flex-direction: column; }
  .company-info { text-align: left; max-width: none; }
  .price-meta { justify-content: start; }
  .toolbar small { max-width: none; }
}
@media print {
  body { background: #fff; }
  .app-header, .back-link, .filters-panel, .toolbar-panel, .block-tools, .row-tools { display: none !important; }
  .container { max-width: none; padding: 0; margin: 0; width: 100% !important; }
  .price-list-paper { box-shadow: none; border: 0; border-radius: 0; padding: 0; }
  .warehouse-section { margin: 24px 0; }
  .price-block { break-inside: avoid; }
}
</style>
<?php echo app_header_styles(); ?>
</head>
<body>
<?php render_app_header(); ?>
<main class="container">
    <a href="calculator.php" class="back-link">← Назад к калькуляторам</a>

    <section class="panel filters-panel">
        <div class="section-title main">Параметры прайс-листа</div>
        <form method="get" class="filters-grid">
            <div>
                <label for="currency">Валюта</label>
                <select id="currency" name="currency">
                    <?php foreach ($activeCurrencies as $c): ?>
                        <option value="<?php echo e($c['code']); ?>" <?php echo $selectedCurrency === $c['code'] ? 'selected' : ''; ?>><?php echo e($c['code']); ?> — <?php echo e($c['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="valid_from">Действительно с</label>
                <input id="valid_from" type="date" name="valid_from" value="<?php echo e($validFrom); ?>">
            </div>
            <div>
                <label for="price_type">Тип цен</label>
                <select id="price_type" name="price_type">
                    <option value="">— Все —</option>
                    <?php foreach ($priceTypes as $pt): ?>
                        <option value="<?php echo e($pt['id']); ?>" <?php echo $selectedPriceType === (string)$pt['id'] ? 'selected' : ''; ?>><?php echo e($pt['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="submit-wrap">
                <button type="submit">Применить</button>
            </div>
        </form>
    </section>

    <section class="panel toolbar-panel toolbar" aria-label="Редактор прайс-листа">
        <div class="toolbar__group">
            <button type="button" id="addWarehouseBtn" class="primary">+ Склад</button>
            <button type="button" id="addBlockBtn" class="secondary">+ Блок</button>
            <button type="button" id="saveDraftBtn">Сохранить</button>
            <button type="button" id="resetDraftBtn" class="danger">Сбросить</button>
        </div>
        <div class="toolbar__group">
            <button type="button" id="exportExcelBtn" class="secondary">Excel</button>
            <button type="button" id="exportPdfBtn" class="secondary">PDF</button>
            <button type="button" onclick="window.print()" class="secondary">Печать</button>
        </div>
        <small>Редактируйте заголовки, ячейки и цены прямо в таблице. В каждом блоке можно добавить/удалить строки и применить наценку к числовым ценам.</small>
    </section>

    <div id="priceListPaper" class="price-list-paper">
    <div class="price-list-head">
        <?php if ($logoPath !== ''): ?>
            <div class="logo-box"><img src="<?php echo e($logoPath); ?>" alt="<?php echo e($orgName); ?>" style="max-width:100%;max-height:100%;object-fit:contain;"></div>
        <?php else: ?>
            <div class="logo-box"><?php echo e($orgName); ?></div>
        <?php endif; ?>
        <div class="company-info" contenteditable="true"><?php echo e($orgFullName); ?><br>Адрес: <?php echo e($orgAddress); ?><br>Тел./факс: <?php echo e($orgPhone); ?><br>Web: <?php echo e($orgWebsite); ?>, e-mail: <?php echo e($orgEmail); ?>
            <div class="price-meta">
                <span>Прайс-лист</span><input value="<?php echo e($priceTypeName); ?>">
                <span>Действительно с:</span><input value="<?php echo e($validFrom); ?>">
                <span>Курс</span><input value="<?php echo e(number_format($currencyRate, 2, ',', ' ')); ?>">
            </div>
        </div>
    </div>

    <div class="warehouse-section">
        <div class="warehouse-title-wrap"><input type="text" class="warehouse-title" value="СКЛАД — ЕКАТЕРИНБУРГ"></div>

        <input type="text" class="program-title-input" value="Компакт-плита FUNDERMAX (Австрия), толщина 12 мм">
        <input type="text" class="program-subtitle-input" value="(для производства кухонных столешниц и фасадов)">
        <div style="overflow-x:auto;">
        <table>
            <thead>
                <tr>
                    <th class="text-left">Тиснение</th>
                    <th class="text-left">Декоры</th>
                    <th>Цена м²</th>
                    <th>Лист<br>4100×1300 мм<br><small>5,33 м²</small></th>
                    <th>Пол-листа<br>4100×648 мм<br><small>2,66 м²</small></th>
                    <th>Лист<br>4100×1854 мм<br><small>7,60 м²</small></th>
                    <th>Треть листа<br>4100×610 мм<br><small>2,50 м²</small></th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td class="texture">IP/SX (фактурное)</td>
                    <td class="decors">0085/0080 (чёрная сердцевина)</td>
                    <td class="price">14 467</td>
                    <td class="price">77 109,11</td>
                    <td class="price">38 445,93</td>
                    <td class="na">—</td>
                    <td class="na">—</td>
                </tr>
                <tr>
                    <td class="texture">NW/NN (фактурное)</td>
                    <td class="decors">0428/0426/0026/0027/0794 (чёрная сердцевина)</td>
                    <td class="price">17 530</td>
                    <td class="price">93 434,90</td>
                    <td class="price">46 583,70</td>
                    <td class="na">—</td>
                    <td class="na">—</td>
                </tr>
                <tr>
                    <td class="texture">FH/FH (мелкая шагрень)</td>
                    <td class="decors">0566/0260/0048/0331/0585 (чёрная сердцевина)</td>
                    <td class="price">11 510</td>
                    <td class="price">61 348,30</td>
                    <td class="price">30 589,77</td>
                    <td class="na">—</td>
                    <td class="na">—</td>
                </tr>
                <tr>
                    <td class="texture">FH/FH (мелкая шагрень)</td>
                    <td class="decors">0085/0077/0074 (чёрная сердцевина)</td>
                    <td class="price">11 088</td>
                    <td class="price">59 099,04</td>
                    <td class="price">29 468,60</td>
                    <td class="na">—</td>
                    <td class="na">—</td>
                </tr>
                <tr>
                    <td class="texture">FH/FH (мелкая шагрень)</td>
                    <td class="decors">0080 (чёрная сердцевина)</td>
                    <td class="price">11 088</td>
                    <td class="na">—</td>
                    <td class="na">—</td>
                    <td class="price">84 284,32</td>
                    <td class="price">27 741,09</td>
                </tr>
            </tbody>
        </table>
        </div>

        <input type="text" class="program-title-input" value="Компакт-плита GREENLAM (Индия), толщина 12 мм">
        <input type="text" class="program-subtitle-input" value="(для производства кухонных столешниц и фасадов)">
        <div style="overflow-x:auto;">
        <table>
            <thead>
                <tr>
                    <th class="text-left">Тиснение</th>
                    <th class="text-left">Декоры</th>
                    <th>Цена м²</th>
                    <th>Лист<br>3050×1300 мм<br><small>3,97 м²</small></th>
                    <th>Пол-листа<br>3050×648 мм<br><small>1,98 м²</small></th>
                    <th>Лист<br>3660×1830 мм<br><small>6,70 м²</small></th>
                    <th>Треть листа<br>3660×600 мм<br><small>2,2 м²</small></th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td class="texture">SAT (SATIN) (супермат)</td>
                    <td class="decors">5574/5575 (чёрная сердцевина)</td>
                    <td class="price">86,856</td>
                    <td class="price">344,38</td>
                    <td class="price">181,66</td>
                    <td class="na">—</td>
                    <td class="na">—</td>
                </tr>
                <tr>
                    <td class="texture">SAT (SATIN) (супермат)</td>
                    <td class="decors">5574 (белая сердцевина)</td>
                    <td class="price">163,57</td>
                    <td class="price">648,56</td>
                    <td class="price">333,28</td>
                    <td class="na">—</td>
                    <td class="na">—</td>
                </tr>
                <tr>
                    <td class="texture">TER (TERRA) ("под камень")</td>
                    <td class="decors">5577/5773/5578 (чёрная сердцевина)</td>
                    <td class="price">86,856</td>
                    <td class="price">344,38</td>
                    <td class="price">181,66</td>
                    <td class="na">—</td>
                    <td class="na">—</td>
                </tr>
                <tr>
                    <td class="texture">PGR (PURE GRAIN) ("под дерево")</td>
                    <td class="decors">5427/5076 (чёрная сердцевина)</td>
                    <td class="price">86,856</td>
                    <td class="price">344,38</td>
                    <td class="price">181,66</td>
                    <td class="na">—</td>
                    <td class="na">—</td>
                </tr>
                <tr>
                    <td class="texture">AFX (супермат+)</td>
                    <td class="decors">9854/401, 9861/268 (чёрная сердцевина)<br><small>Односторонний</small></td>
                    <td class="price">120,36</td>
                    <td class="price">477,24</td>
                    <td class="price">247,88</td>
                    <td class="na">—</td>
                    <td class="na">—</td>
                </tr>
            </tbody>
        </table>
        </div>

        <input type="text" class="program-title-input" value="Greenlam Interior, толщина 0,7 мм">
        <input type="text" class="program-subtitle-input" value="(для производства кухонных фартуков и мебельных фасадов)">
        <div style="overflow-x:auto;">
        <table>
            <thead>
                <tr>
                    <th class="text-left">Тиснение</th>
                    <th class="text-left">Декоры</th>
                    <th>Цена м²</th>
                    <th>От 1 листа<br>3050×1300 мм<br><small>3,97 м²</small></th>
                    <th>От 10 листов<br>3050×1300 мм<br><small>3,97 м²</small></th>
                    <th>От 100 листов<br>3050×1300 мм<br><small>3,97 м²</small></th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td class="texture">SAT (SATIN) (супермат)</td>
                    <td class="decors">5574/5575 (коричневая сердцевина)</td>
                    <td class="price">17</td>
                    <td class="price">67,41</td>
                    <td class="price">63,44</td>
                    <td class="price">59,48</td>
                </tr>
                <tr>
                    <td class="texture">TER (TERRA) ("под камень")</td>
                    <td class="decors">5577/5773 (коричневая сердцевина)</td>
                    <td class="price">17</td>
                    <td class="price">67,41</td>
                    <td class="price">63,44</td>
                    <td class="price">59,48</td>
                </tr>
            </tbody>
        </table>
        </div>
    </div>

    <div class="warehouse-section">
        <div class="warehouse-title-wrap"><input type="text" class="warehouse-title" value="СКЛАД — МОСКВА"></div>

        <input type="text" class="program-title-input" value="Компакт-плита GENTAS (Турция), толщина 12 мм">
        <input type="text" class="program-subtitle-input" value="(для производства кухонных столешниц и фасадов)">
        <div style="overflow-x:auto;">
        <table>
            <thead>
                <tr>
                    <th class="text-left">Тиснение</th>
                    <th class="text-left">Декоры</th>
                    <th>Цена м²</th>
                    <th>Лист<br>4200×1400 мм<br><small>5,88 м²</small></th>
                    <th>Пол-листа<br>4200×698 мм<br><small>2,94 м²</small></th>
                    <th>Лист<br>4200×1860 мм<br><small>7,82 м²</small></th>
                    <th>Треть листа<br>4200×610 мм<br><small>2,57 м²</small></th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td class="texture">ESSE / VELUR (мелкая шагрень)</td>
                    <td class="decors">3096/3153/3155/3190 (чёрная сердцевина)</td>
                    <td class="price">72,91</td>
                    <td class="na">—</td>
                    <td class="na">—</td>
                    <td class="price">570,16</td>
                    <td class="price">197,38</td>
                </tr>
                <tr>
                    <td class="texture">CANYON (фактурное)</td>
                    <td class="decors">3096/3155/3190 (чёрная сердцевина)</td>
                    <td class="price">92,54</td>
                    <td class="price">544,14</td>
                    <td class="price">282,07</td>
                    <td class="na">—</td>
                    <td class="na">—</td>
                </tr>
                <tr>
                    <td class="texture">VELUR (мелкая шагрень)</td>
                    <td class="decors">4680/5427/5629/5729/5808 (чёрная сердцевина)</td>
                    <td class="price">83,32</td>
                    <td class="price">489,92</td>
                    <td class="price">254,96</td>
                    <td class="na">—</td>
                    <td class="na">—</td>
                </tr>
                <tr>
                    <td class="texture">JUPITER (фактурное)</td>
                    <td class="decors">5801 (чёрная сердцевина)</td>
                    <td class="price">83,32</td>
                    <td class="price">489,92</td>
                    <td class="price">254,96</td>
                    <td class="na">—</td>
                    <td class="na">—</td>
                </tr>
                <tr>
                    <td class="texture">TOUCH (супермат)</td>
                    <td class="decors">5666/5736/5743 (чёрная сердцевина)</td>
                    <td class="price">97,66</td>
                    <td class="price">574,24</td>
                    <td class="price">297,12</td>
                    <td class="na">—</td>
                    <td class="na">—</td>
                </tr>
                <tr>
                    <td class="texture">EVEREST (фактурное)</td>
                    <td class="decors">5646 (чёрная сердцевина)</td>
                    <td class="price">97,66</td>
                    <td class="price">574,24</td>
                    <td class="price">297,12</td>
                    <td class="na">—</td>
                    <td class="na">—</td>
                </tr>
                <tr>
                    <td class="texture">NATURAL (иммитация дерева)</td>
                    <td class="decors">4613/4626/4627 (чёрная сердцевина)</td>
                    <td class="price">101,75</td>
                    <td class="price">598,29</td>
                    <td class="price">309,15</td>
                    <td class="na">—</td>
                    <td class="na">—</td>
                </tr>
                <tr>
                    <td class="texture">ITALIAN STONE (иммитация камня)</td>
                    <td class="decors">5630/5700 (чёрная сердцевина)</td>
                    <td class="price">101,75</td>
                    <td class="price">598,29</td>
                    <td class="price">309,15</td>
                    <td class="na">—</td>
                    <td class="na">—</td>
                </tr>
                <tr>
                    <td class="texture">Pristine (фактурное)</td>
                    <td class="decors">5812 (чёрная сердцевина)</td>
                    <td class="price">101,75</td>
                    <td class="price">598,29</td>
                    <td class="price">309,15</td>
                    <td class="na">—</td>
                    <td class="na">—</td>
                </tr>
            </tbody>
        </table>
        </div>

        <input type="text" class="program-title-input" value="Компакт-плита FUNDERMAX (Австрия), толщина 12 мм">
        <input type="text" class="program-subtitle-input" value="(для производства кухонных столешниц и фасадов)">
        <div style="overflow-x:auto;">
        <table>
            <thead>
                <tr>
                    <th class="text-left">Тиснение</th>
                    <th class="text-left">Декоры</th>
                    <th>Цена м²</th>
                    <th>Лист<br>4100×1300 мм<br><small>5,33 м²</small></th>
                    <th>Пол-листа<br>4100×648 мм<br><small>2,66 м²</small></th>
                    <th>Лист<br>4100×1854 мм<br><small>7,60 м²</small></th>
                    <th>Треть листа<br>4100×610 мм<br><small>2,50 м²</small></th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td class="texture">AP/RR (супермат)</td>
                    <td class="decors">0606/0080/0077/0075 (чёрная сердцевина)</td>
                    <td class="price">192</td>
                    <td class="price">1 023,36</td>
                    <td class="price">520,11</td>
                    <td class="na">—</td>
                    <td class="na">—</td>
                </tr>
                <tr>
                    <td class="texture">IP/SX (фактурное)</td>
                    <td class="decors">0085/0080/0077/2286/0741/0755 (чёрная сердцевина)</td>
                    <td class="price">131,52</td>
                    <td class="price">701,00</td>
                    <td class="price">359,42</td>
                    <td class="na">—</td>
                    <td class="na">—</td>
                </tr>
                <tr>
                    <td class="texture">NW/NN (фактурное)</td>
                    <td class="decors">B794/B793/0497/B428/0427/0426/B421/0406/0394/0386/0344/0027/0026 (чёрная сердцевина)</td>
                    <td class="price">159,36</td>
                    <td class="price">849,39</td>
                    <td class="price">433,39</td>
                    <td class="na">—</td>
                    <td class="na">—</td>
                </tr>
                <tr>
                    <td class="texture">FH/FH (мелкая шагрень)</td>
                    <td class="decors">0585/0581/0566/0599/0565/0378/0331/0269/0260/0179/0048/0049 (чёрная сердцевина)</td>
                    <td class="price">104,64</td>
                    <td class="price">557,73</td>
                    <td class="price">288,01</td>
                    <td class="na">—</td>
                    <td class="na">—</td>
                </tr>
                <tr>
                    <td class="texture">FH/FH (мелкая шагрень)</td>
                    <td class="decors">0085/0080/0077/0074/0851 (чёрная сердцевина)</td>
                    <td class="price">100,80</td>
                    <td class="price">537,26</td>
                    <td class="price">277,81</td>
                    <td class="price">766,22</td>
                    <td class="price">262,10</td>
                </tr>
            </tbody>
        </table>
        </div>

        <input type="text" class="program-title-input" value="Компакт-плита GREENLAM (Индия), толщина 12 мм">
        <input type="text" class="program-subtitle-input" value="(для производства кухонных столешниц и фасадов)">
        <div style="overflow-x:auto;">
        <table>
            <thead>
                <tr>
                    <th class="text-left">Тиснение</th>
                    <th class="text-left">Декоры</th>
                    <th>Цена м²</th>
                    <th>Лист<br>3050×1300 мм<br><small>3,97 м²</small></th>
                    <th>Пол-листа<br>3050×648 мм<br><small>1,98 м²</small></th>
                    <th>Лист<br>3660×1830 мм<br><small>6,70 м²</small></th>
                    <th>Лист<br>4200×1300 мм<br><small>5,46 м²</small></th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td class="texture">AFX (супермат+)</td>
                    <td class="decors">Лицевая: 9861/9854/9853/9851<br>Обратная: 9861-268/9854-401/9853-274/9851-274</td>
                    <td class="price">109,42</td>
                    <td class="price">433,85</td>
                    <td class="price">226,26</td>
                    <td class="na">—</td>
                    <td class="na">—</td>
                </tr>
                <tr>
                    <td class="texture">SUE (SUEDE) (мелкая шагрень)</td>
                    <td class="decors">5616/5784 (чёрная сердцевина)</td>
                    <td class="price">78,96</td>
                    <td class="price">313,08</td>
                    <td class="price">166,06</td>
                    <td class="na">—</td>
                    <td class="na">—</td>
                </tr>
                <tr>
                    <td class="texture">SAT (SATIN) (супермат)</td>
                    <td class="decors">5575/5574 (чёрная сердцевина)</td>
                    <td class="price">78,96</td>
                    <td class="price">313,08</td>
                    <td class="price">166,06</td>
                    <td class="na">—</td>
                    <td class="na">—</td>
                </tr>
                <tr>
                    <td class="texture">SAT (SATIN) (супермат)</td>
                    <td class="decors">5574 (белая сердцевина)</td>
                    <td class="price">148,70</td>
                    <td class="price">589,60</td>
                    <td class="price">303,89</td>
                    <td class="na">—</td>
                    <td class="na">—</td>
                </tr>
                <tr>
                    <td class="texture">PGR (PURE GRAIN) ("под дерево")</td>
                    <td class="decors">5427/5076 (чёрная сердцевина)</td>
                    <td class="price">78,96</td>
                    <td class="price">313,08</td>
                    <td class="price">166,06</td>
                    <td class="na">—</td>
                    <td class="na">—</td>
                </tr>
                <tr>
                    <td class="texture">LINEA (WOOD) ("под дерево")</td>
                    <td class="decors">5375/5076 (чёрная сердцевина)</td>
                    <td class="price">78,96</td>
                    <td class="na">—</td>
                    <td class="na">—</td>
                    <td class="na">—</td>
                    <td class="price">431,12</td>
                </tr>
                <tr>
                    <td class="texture">TER (TERRA) ("под камень")</td>
                    <td class="decors">5577/5578/5773/5774/5583 (чёрная сердцевина)</td>
                    <td class="price">78,96</td>
                    <td class="price">313,08</td>
                    <td class="price">166,06</td>
                    <td class="na">—</td>
                    <td class="na">—</td>
                </tr>
                <tr>
                    <td class="texture">SUE (SUEDE) (мелкая шагрень)</td>
                    <td class="decors">111/121/401/271/261 (чёрная сердцевина)</td>
                    <td class="price">72,21</td>
                    <td class="na">—</td>
                    <td class="na">—</td>
                    <td class="price">483,65</td>
                    <td class="na">—</td>
                </tr>
                <tr>
                    <td class="texture">SUE (SUEDE) (мелкая шагрень)</td>
                    <td class="decors">111 (белая сердцевина)</td>
                    <td class="price">157,45</td>
                    <td class="price">624,29</td>
                    <td class="price">321,18</td>
                    <td class="na">—</td>
                    <td class="na">—</td>
                </tr>
            </tbody>
        </table>
        </div>
    </div>
    <div class="export-notes" contenteditable="true">1. Цены в предложении указаны в евро с НДС 20%.
2. Оплата производится в рублях по курсу ЦБ РФ, увеличенному на 1,5% на день оплаты, по безналичному расчету.

Руководитель проектов по УФО
Владимиров Алексей
Тел.: +7 (908) 9-123-183
Mail: vladimirov@dekotech.ru</div>
    </div>
</main>

<script>
(() => {
const STORAGE_KEY = 'price_list_countertops_editor_v2';
const paper = document.getElementById('priceListPaper');
const moneyCells = 'td.price';
function makeEditable(root = paper) { root.querySelectorAll('input').forEach(i => i.setAttribute('value', i.value)); root.querySelectorAll('td, th, .company-info, .export-notes').forEach(el => el.contentEditable = 'true'); root.querySelectorAll('.price-block').forEach(attachBlockTools); }
function prepareExportClone(){ const clone=paper.cloneNode(true); clone.querySelectorAll('.block-tools,.row-tools,.is-selected').forEach(el=>{ if(el.classList && el.classList.contains('is-selected')) el.classList.remove('is-selected'); else el.remove(); }); clone.querySelectorAll('input').forEach(input=>{ const span=document.createElement('span'); span.textContent=input.value; span.className=input.className; if(input.classList.contains('warehouse-title')) span.classList.add('warehouse-title-export'); input.replaceWith(span); }); clone.querySelectorAll('[contenteditable]').forEach(el=>el.removeAttribute('contenteditable')); return clone; }
function exportDocumentHtml(){ const clone=prepareExportClone(); const styles=Array.from(document.querySelectorAll('style')).map(st=>st.textContent).join('\n') + '\n.block-tools,.row-tools,.toolbar,.toolbar-panel,.filters-panel,.back-link,.app-header{display:none!important}.price-list-paper{box-shadow:none;border:0;border-radius:0;padding:42px 48px}.warehouse-title-export{display:block;font-size:22px;font-weight:900}.price-list-paper > .warehouse-section{box-shadow:none;border-radius:0;margin:36px 0 28px;padding:0 0 24px}'; return '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>'+styles+'</style></head><body>'+clone.outerHTML+'</body></html>'; }
function blockTemplate(title='Новый блок прайс-листа', subtitle='(описание блока)') { const wrap=document.createElement('div'); wrap.className='price-block'; wrap.innerHTML=`<input type="text" class="program-title-input" value="${title}"><input type="text" class="program-subtitle-input" value="${subtitle}"><div style="overflow-x:auto;"><table><thead><tr><th>Тиснение, сердцевина</th><th>Номер декора</th><th>Формат</th><th>Площадь</th><th>Цена м2</th><th>Цена лист</th><th>Цена руб</th></tr></thead><tbody><tr><td class="texture">Новая позиция</td><td class="decors">0000</td><td>3050 × 1300 × 12</td><td>3,97 м2</td><td class="price">0,00</td><td class="price">0,00</td><td class="price">0,00 ₽</td></tr></tbody></table></div>`; makeEditable(wrap); return wrap; }
function attachBlockTools(block) { if (block.querySelector(':scope > .block-tools')) return; const tools=document.createElement('div'); tools.className='block-tools'; tools.innerHTML='<button type="button" data-act="add-row">+ строка</button><button type="button" class="danger" data-act="delete-row">Удалить выбранную строку</button><button type="button" class="secondary" data-act="clone-block">Дублировать блок</button><input type="number" step="0.01" placeholder="Наценка %"><button type="button" class="secondary" data-act="markup-percent">+%</button><input type="number" step="0.01" placeholder="Наценка сумма"><button type="button" class="secondary" data-act="markup-sum">+ сумма</button><button type="button" class="danger" data-act="delete-block">Удалить блок</button>'; block.prepend(tools); }
function normalizeBlocks(){ document.querySelectorAll('.program-title-input').forEach(input=>{ if(!input.closest('.price-block')){ const block=document.createElement('div'); block.className='price-block'; input.parentNode.insertBefore(block,input); block.appendChild(input); const sub=block.nextElementSibling?.classList?.contains('program-subtitle-input')?block.nextElementSibling:null; if(sub) block.appendChild(sub); const div=block.nextElementSibling; if(div) block.appendChild(div); }}); makeEditable(); }
function parseNum(text){ const n=parseFloat(String(text).replace(/[^0-9,.-]/g,'').replace(/\s/g,'').replace(',','.')); return Number.isFinite(n)?n:null; }
function fmt(n, suffix=''){ return n.toLocaleString('ru-RU',{minimumFractionDigits:2,maximumFractionDigits:2}) + suffix; }
function applyMarkup(block, value, percent){ block.querySelectorAll(moneyCells).forEach(td=>{ const n=parseNum(td.textContent); if(n===null) return; const suffix=td.textContent.includes('₽')?' ₽':''; td.textContent=fmt(percent ? n*(1+value/100) : n+value, suffix); }); }
document.addEventListener('focusin', e=>{ const row=e.target.closest('.price-block tbody tr'); if(row){ row.parentNode.querySelectorAll('tr').forEach(r=>r.classList.remove('is-selected')); row.classList.add('is-selected'); }});
document.addEventListener('click', e=>{ const row=e.target.closest('.price-block tbody tr'); if(row){ row.parentNode.querySelectorAll('tr').forEach(r=>r.classList.remove('is-selected')); row.classList.add('is-selected'); } const b=e.target.closest('button'); if(!b) return; const act=b.dataset.act; const block=b.closest('.price-block'); if(act==='add-row'){ const tr=block.querySelector('tbody tr:last-child').cloneNode(true); tr.querySelectorAll('td').forEach(td=>td.contentEditable='true'); block.querySelector('tbody').appendChild(tr); } if(act==='delete-row'){ const row=block.querySelector('tr.is-selected') || block.querySelector('tbody tr:last-child'); if(row && block.querySelectorAll('tbody tr').length>1) row.remove(); } if(act==='clone-block') block.after(block.cloneNode(true)); if(act==='delete-block' && confirm('Удалить блок?')) block.remove(); if(act==='markup-percent') applyMarkup(block, parseFloat(b.previousElementSibling.value||0), true); if(act==='markup-sum') applyMarkup(block, parseFloat(b.previousElementSibling.value||0), false); });
document.getElementById('addBlockBtn').onclick=()=>document.querySelector('.warehouse-section:last-of-type').appendChild(blockTemplate());
document.getElementById('addWarehouseBtn').onclick=()=>{ const wh=document.createElement('div'); wh.className='warehouse-section'; wh.innerHTML='<div class="warehouse-title-wrap"><input type="text" class="warehouse-title" value="СКЛАД — НОВЫЙ"></div>'; wh.appendChild(blockTemplate()); paper.insertBefore(wh, paper.querySelector('.export-notes')); };
document.getElementById('saveDraftBtn').onclick=()=>{ paper.querySelectorAll('input').forEach(i=>i.setAttribute('value',i.value)); localStorage.setItem(STORAGE_KEY, paper.innerHTML); alert('Черновик сохранён в браузере.'); };
document.getElementById('resetDraftBtn').onclick=()=>{ if(confirm('Сбросить сохранённый черновик?')){ localStorage.removeItem(STORAGE_KEY); location.reload(); }};
document.getElementById('exportExcelBtn').onclick=()=>{ const html='\ufeff'+exportDocumentHtml(); const a=document.createElement('a'); a.href=URL.createObjectURL(new Blob([html],{type:'application/vnd.ms-excel;charset=utf-8'})); a.download='price_list_countertops.xls'; a.click(); URL.revokeObjectURL(a.href); };
document.getElementById('exportPdfBtn').onclick=()=>{ const exportNode=prepareExportClone(); if(window.html2pdf){ html2pdf().set({margin:8, filename:'price_list_countertops.pdf', html2canvas:{scale:2}, jsPDF:{unit:'mm',format:'a4',orientation:'portrait'}}).from(exportNode).save(); } else window.print(); };
const saved=localStorage.getItem(STORAGE_KEY); if(saved) paper.innerHTML=saved; normalizeBlocks();

const currencySelect = document.getElementById('currency');
if (currencySelect) {
    const syncFromHeader = () => {
        const headerCurrency = localStorage.getItem('stcalc.currency');
        if (headerCurrency && currencySelect.value !== headerCurrency) {
            currencySelect.value = headerCurrency;
        }
    };
    currencySelect.addEventListener('change', () => {
        const code = currencySelect.value;
        localStorage.setItem('stcalc.currency', code);
        if (window.AppCurrency?.set) {
            window.AppCurrency.set(code);
        } else {
            document.querySelectorAll('[data-app-currency]').forEach(btn => {
                btn.classList.toggle('app-header__currency-option--active', btn.dataset.appCurrency === code);
            });
        }
    });
    window.addEventListener('appcurrencychange', e => {
        if (currencySelect.value !== e.detail.code) {
            currencySelect.value = e.detail.code;
        }
    });
    syncFromHeader();
}
})();
</script>
</body>
</html>
