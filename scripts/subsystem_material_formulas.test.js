const assert = require('node:assert/strict');
const fs = require('node:fs');

const page = fs.readFileSync('calculator_subsystem.php', 'utf8');

assert.match(page, /function materialFormulaHelp\(formula, name\)/, 'Для материалов должна создаваться информационная иконка');
assert.match(page, /class="app-field-help material-formula-help"/, 'Иконка должна использовать общий доступный интерфейс подсказок');
assert.match(page, /role="tooltip"/, 'Формула должна быть размечена как всплывающая подсказка');
assert.match(page, /function consumptionFormula\(/, 'Формула расхода должна формироваться для каждой позиции');
assert.match(page, /расход на м² × площадь панелей/, 'Подсказка должна объяснять расчёт по площади');
assert.match(page, /расход на м\.п\. × длина профиля с запасом/, 'Подсказка должна объяснять расчёт по профилю');
assert.match(page, /Количество штук = расчётный расход \/ количество в одной штуке, с округлением вверх/, 'Подсказка должна объяснять округление до штук понятными словами');
assert.doesNotMatch(page, /Количество = ceil|Количество штук = ceil|Длина профиля = ceil/, 'Во всплывающих подсказках не должно быть технического обозначения ceil');
assert.match(page, /const label = f\.height_mm \+ '×' \+ f\.width_mm/, 'Формат листа должен отображать сначала высоту, затем ширину');
assert.match(page, /<th style="width:120px">Всего<\/th>/, 'В таблицу должна быть возвращена колонка «Всего»');
assert.match(page, /const totalLengthCell = group\.title === 'Профиль' \? `<td class="num profile-total-length">\$\{fmt\(item\.totalLength, 0\)\} м\.п\.<\/td>` : '';/, 'Общий метраж должен выводиться только в строках профиля');
assert.match(page, /const priceColspan = group\.title === 'Профиль' \? '' : ' colspan="2"';/, 'В остальных строках отдельной ячейки колонки «Всего» быть не должно');
assert.match(page, /\.material-formula-help \.app-field-help__tooltip \{ top: calc\(100% \+ 8px\); bottom: auto;/, 'Подсказка профиля должна раскрываться вниз и не обрезаться верхней границей окна');

console.log('Подсказки с формулами расхода материалов присутствуют.');
