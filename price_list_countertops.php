<?php
require_once __DIR__ . '/includes/admin_auth.php';
require_once __DIR__ . '/includes/app_header.php';
require_login();
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/admin_schema.php';

ensure_currencies_table($pdo);
ensure_price_types_table($pdo);

$activeCurrencies = $pdo->query("SELECT code, name FROM currencies WHERE is_active = 1 ORDER BY code = 'RUB' DESC, code ASC")->fetchAll();
$priceTypes = $pdo->query("SELECT id, name FROM price_types WHERE is_active = 1 ORDER BY sort_order ASC, id ASC")->fetchAll();

$selectedCurrency = $_GET['currency'] ?? 'EUR';
$selectedPriceType = $_GET['price_type'] ?? '';
$validFrom = $_GET['valid_from'] ?? date('Y-m-d');
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<title>Прайс-лист — Столешницы</title>
<script src="https://cdn.jsdelivr.net/npm/html2pdf.js@0.10.1/dist/html2pdf.bundle.min.js" defer></script>
<style>
* { box-sizing: border-box; }
body { font-family: Arial, sans-serif; margin: 0; color: #1f2937; background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 50%, #3b82f6 100%); min-height: 100vh; }
.header { display: flex; align-items: center; gap: 16px; flex-wrap: wrap; padding: 24px 32px; }
.header a { color: #dbeafe; text-decoration: none; }
.header h1 { margin: 0; font-size: clamp(24px, 3.5vw, 36px); letter-spacing: -.02em; font-weight: 900; color: #fff; }
.container { max-width: 1200px; margin: 0 auto; padding: 20px; }
.warehouse-section { background: #fff; border-radius: 14px; padding: 28px; box-shadow: 0 8px 24px rgba(15,23,42,0.15); margin-bottom: 24px; }
.warehouse-title-wrap { border-bottom: 3px solid #2563eb; padding-bottom: 8px; margin-bottom: 8px; }
.warehouse-title { font-size: 22px; font-weight: 900; color: #1e3a8a; margin: 0; text-align: center; background: transparent; width: 100%; padding: 4px 8px; border-radius: 6px; border: 1px solid transparent; }
.warehouse-title:hover { background: #f0f7ff; border-color: #d1d5db; }
.warehouse-title:focus { background: #fff; outline: 2px solid #2563eb; }
.program-title-input { font-size: 17px; font-weight: 700; color: #1f2937; margin: 24px 0 4px 0; border: none; background: transparent; width: 100%; padding: 4px 8px; border-radius: 6px; }
.program-title-input:hover { background: #f0f7ff; }
.program-title-input:focus { background: #fff; outline: 2px solid #2563eb; }
.program-subtitle-input { font-size: 13px; color: #64748b; margin: 0 0 12px 0; border: none; background: transparent; width: 100%; padding: 2px 8px; border-radius: 6px; }
.program-subtitle-input:hover { background: #f0f7ff; }
.program-subtitle-input:focus { background: #fff; outline: 2px solid #2563eb; }
table { width: 100%; border-collapse: collapse; font-size: 13px; margin-bottom: 16px; }
thead th { background: #1e3a8a; color: #fff; padding: 10px 8px; text-align: center; font-weight: 700; white-space: nowrap; }
thead th.text-left { text-align: left; }
tbody td { padding: 8px; border-bottom: 1px solid #e2e8f0; vertical-align: top; }
tbody tr:hover { background: #f0f7ff; }
td.price { text-align: right; font-family: monospace; white-space: nowrap; }
td.texture { white-space: nowrap; font-weight: 600; color: #475569; }
td.decors { color: #1f2937; }
td.na { color: #cbd5e1; text-align: center; }
.back-link { display: inline-block; margin-bottom: 16px; padding: 10px 20px; border-radius: 8px; background: rgba(255,255,255,0.15); color: #fff; text-decoration: none; font-weight: 700; border: 1px solid rgba(255,255,255,0.3); }
.back-link:hover { background: rgba(255,255,255,0.25); }
.info-bar { display: flex; gap: 20px; flex-wrap: wrap; margin-bottom: 20px; }
.info-chip { background: rgba(255,255,255,0.15); border: 1px solid rgba(255,255,255,0.3); color: #dbeafe; padding: 6px 14px; border-radius: 8px; font-size: 14px; }
.toolbar { position: sticky; top: 12px; z-index: 20; display: grid; gap: 12px; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); background: rgba(15,23,42,.92); border: 1px solid rgba(255,255,255,.22); border-radius: 14px; padding: 14px; margin-bottom: 20px; color: #fff; box-shadow: 0 12px 30px rgba(15,23,42,.25); backdrop-filter: blur(8px); }
.toolbar__group { display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
.toolbar button, .block-tools button, .row-tools button { border:0; border-radius:8px; padding:8px 11px; font-weight:800; cursor:pointer; background:#fbbf24; color:#111827; }
.toolbar button.secondary, .block-tools button.secondary { background:#dbeafe; color:#1e3a8a; }
.toolbar button.danger, .block-tools button.danger, .row-tools button.danger { background:#fee2e2; color:#991b1b; }
.toolbar input { width: 110px; border-radius:8px; border:1px solid #cbd5e1; padding:8px; }
.toolbar small { color:#bfdbfe; line-height:1.35; }
.price-list-paper { background:#fff; color:#111; padding: 42px 48px; border-radius:14px; box-shadow:0 8px 24px rgba(15,23,42,.15); }
.price-list-head { display:flex; justify-content:space-between; gap:24px; align-items:flex-start; min-height:140px; margin-bottom:24px; }
.logo-box { width:132px; height:132px; border:2px solid #777; display:flex; align-items:center; justify-content:center; text-align:center; font-weight:900; color:#1e3a8a; line-height:1.05; }
.company-info { text-align:right; font-size:12px; line-height:1.45; max-width:520px; }
.price-meta { margin-top:28px; display:grid; grid-template-columns:auto auto; gap:3px 18px; justify-content:end; font-weight:800; }
.price-meta input { border:0; background:transparent; color:#dc2626; font-weight:900; width:95px; }
.export-title { text-align:center; color:#dc2626; font-weight:900; font-size:18px; margin:24px 0 8px; }
.block-tools, .row-tools { display:flex; gap:6px; flex-wrap:wrap; margin:8px 0 12px; }
.block-tools input { border:1px solid #cbd5e1; border-radius:8px; padding:7px; width:120px; }
.price-block { position:relative; margin: 22px 0; }
.price-block table { border:2px solid #111; margin-bottom:4px; }
.price-block thead th { background:#f5b400; color:#111; border:2px solid #111; padding:5px 6px; text-transform:uppercase; }
.price-block tbody td { border:2px solid #111; padding:5px 6px; color:#111; }
.price-block [contenteditable="true"] { outline: 1px dashed transparent; border-radius:4px; }
.price-block [contenteditable="true"]:focus { outline-color:#2563eb; background:#eff6ff; }
.price-block tbody tr.is-selected td { background:#fff7ed; }
.price-block .price, .price-block .na { text-align:right; font-family:Arial,sans-serif; white-space:nowrap; }
.price-block .texture { text-align:center; font-weight:800; white-space:normal; }
.price-block .decors { text-align:center; font-weight:700; }
.export-notes { margin-top:32px; font-size:12px; line-height:1.45; white-space:pre-wrap; }
@media print { body { background:#fff; } .app-header,.header,.back-link,.info-bar,.toolbar,.block-tools,.row-tools { display:none !important; } .container{max-width:none;padding:0;margin:0;} .warehouse-section{box-shadow:none;border-radius:0;padding:0;margin:0;} .price-list-paper{box-shadow:none;border-radius:0;padding:0;} .price-block{break-inside:avoid;} }

</style>
<?php echo app_header_styles(); ?>
</head>
<body>
<?php render_app_header(); ?>
<main class="container">
    <a href="calculator.php" class="back-link">← Назад к калькуляторам</a>

    <div class="info-bar" style="background:rgba(255,255,255,0.15);border:1px solid rgba(255,255,255,0.3);border-radius:12px;padding:16px 20px;gap:16px;align-items:flex-end;">
        <form method="get" style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;width:100%;">
            <div style="display:flex;flex-direction:column;gap:4px;">
                <label style="color:#dbeafe;font-size:12px;font-weight:700;">Валюта</label>
                <select name="currency" style="padding:8px 12px;border-radius:8px;border:1px solid #d1d5db;font-size:14px;">
                    <?php foreach ($activeCurrencies as $c): ?>
                        <option value="<?php echo e($c['code']); ?>" <?php echo $selectedCurrency === $c['code'] ? 'selected' : ''; ?>><?php echo e($c['code']); ?> — <?php echo e($c['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="display:flex;flex-direction:column;gap:4px;">
                <label style="color:#dbeafe;font-size:12px;font-weight:700;">Действительно с</label>
                <input type="date" name="valid_from" value="<?php echo e($validFrom); ?>" style="padding:8px 12px;border-radius:8px;border:1px solid #d1d5db;font-size:14px;">
            </div>
            <div style="display:flex;flex-direction:column;gap:4px;">
                <label style="color:#dbeafe;font-size:12px;font-weight:700;">Тип цен</label>
                <select name="price_type" style="padding:8px 12px;border-radius:8px;border:1px solid #d1d5db;font-size:14px;">
                    <option value="">— Все —</option>
                    <?php foreach ($priceTypes as $pt): ?>
                        <option value="<?php echo e($pt['id']); ?>" <?php echo $selectedPriceType === (string)$pt['id'] ? 'selected' : ''; ?>><?php echo e($pt['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" style="padding:8px 20px;border-radius:8px;border:0;background:#fff;color:#1e3a8a;font-weight:700;cursor:pointer;font-size:14px;">Применить</button>
        </form>
    </div>

    <section class="toolbar" aria-label="Редактор прайс-листа">
        <div class="toolbar__group"><button type="button" id="addWarehouseBtn">+ Склад</button><button type="button" id="addBlockBtn" class="secondary">+ Блок</button><button type="button" id="saveDraftBtn">Сохранить</button><button type="button" id="resetDraftBtn" class="danger">Сбросить</button></div>
        <div class="toolbar__group"><button type="button" id="exportExcelBtn">Excel</button><button type="button" id="exportPdfBtn" class="secondary">PDF</button><button type="button" onclick="window.print()" class="secondary">Печать</button></div>
        <small>Редактируйте заголовки, ячейки и цены прямо в таблице. В каждом блоке можно добавить/удалить строки и применить наценку к числовым ценам.</small>
    </section>

    <div id="priceListPaper" class="price-list-paper">
    <div class="price-list-head"><div class="logo-box">DEKO<br>TECH<br><small>High-Tech for Decorating</small></div><div class="company-info" contenteditable="true">ООО «Декотек Инжиниринг»<br>Адрес: 115114, РФ, г. Москва, Кожевнический проезд, д. 13<br>Тел./факс: +7 (495) 258-07-48<br>Web: www.dekotech.ru, e-mail: hpl@cekotech.ru<div class="price-meta"><span>Прайс-лист</span><input value="Дилер"><span>Действительно с:</span><input value="<?php echo e($validFrom); ?>"><span>Курс</span><input value="100"></div></div></div>

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
document.getElementById('exportExcelBtn').onclick=()=>{ const html='\ufeff'+paper.outerHTML; const a=document.createElement('a'); a.href=URL.createObjectURL(new Blob([html],{type:'application/vnd.ms-excel'})); a.download='price_list_countertops.xls'; a.click(); URL.revokeObjectURL(a.href); };
document.getElementById('exportPdfBtn').onclick=()=>{ if(window.html2pdf){ html2pdf().set({margin:8, filename:'price_list_countertops.pdf', html2canvas:{scale:2}, jsPDF:{unit:'mm',format:'a4',orientation:'portrait'}}).from(paper).save(); } else window.print(); };
const saved=localStorage.getItem(STORAGE_KEY); if(saved) paper.innerHTML=saved; normalizeBlocks();
})();
</script>
</body>
</html>
