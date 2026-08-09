const assert = require('assert');
const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..');
const page = fs.readFileSync(path.join(root, 'calculator_septic.php'), 'utf8');
const integration = fs.readFileSync(path.join(__dirname, 'shower_partition_page.js'), 'utf8');

assert.match(page, /id="shower-config"/, 'Форма душевой перегородки должна присутствовать');
assert.match(page, /id="panel_format_id"/, 'Должен быть выбор формата листа');
assert.match(page, /scripts\/cutting_optimizer\.js/, 'Оптимизатор раскроя должен загружаться');
assert.match(page, /scripts\/shower_partition_calculator\.js/, 'Расчётная модель должна загружаться');
assert.match(page, /scripts\/shower_partition_page\.js/, 'Интеграция страницы должна загружаться');
assert.match(page, /FROM price_list pl/, 'Каталог фурнитуры должен загружаться из базы');
assert.match(page, /fetch\('calculator_septic\.php'/, 'Сохранение должно отправляться в текущий обработчик');
assert.match(integration, /calculateShower/, 'Должен быть отдельный расчёт душевой перегородки');
assert.match(integration, /estimatePanelLayout/, 'Расчёт должен использовать раскладку по листам');
assert.match(integration, /buildRequirements/, 'Расчёт должен формировать спецификацию фурнитуры');

new Function(integration);
console.log('shower_partition_page.test.js: OK');
