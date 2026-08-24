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

console.log('Подсказки с формулами расхода материалов присутствуют.');
