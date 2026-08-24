const assert = require('node:assert/strict');
const fs = require('node:fs');

const page = fs.readFileSync('calculator_subsystem.php', 'utf8');

assert.match(page, /function materialFormulaHelp\(formula, name\)/, 'Для материалов должна создаваться информационная иконка');
assert.match(page, /class="app-field-help material-formula-help"/, 'Иконка должна использовать общий доступный интерфейс подсказок');
assert.match(page, /role="tooltip"/, 'Формула должна быть размечена как всплывающая подсказка');
assert.match(page, /function consumptionFormula\(/, 'Формула расхода должна формироваться для каждой позиции');
assert.match(page, /расход на м² × площадь панелей/, 'Подсказка должна объяснять расчёт по площади');
assert.match(page, /расход на м\.п\. × длина профиля с запасом/, 'Подсказка должна объяснять расчёт по профилю');
assert.match(page, /Количество штук = ceil/, 'Подсказка должна объяснять округление до штук');
assert.match(page, /const label = f\.height_mm \+ '×' \+ f\.width_mm/, 'Формат листа должен отображать сначала высоту, затем ширину');
assert.match(page, /<th style="width:120px">Всего<\/th>/, 'После единицы измерения должна быть колонка «Всего»');
assert.match(page, /totalLength: totalProfileMWithReserve/, 'Для профиля должен сохраняться общий погонный метраж');
assert.match(page, /const totalLengthHtml = group\.title === 'Профиль' \? fmt\(item\.totalLength, 0\) \+ ' м\.п\.'/, 'Общий погонный метраж профиля должен выводиться в строке материала');

console.log('Подсказки с формулами расхода материалов присутствуют.');
