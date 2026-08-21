const assert = require('assert');
const fs = require('fs');
const path = require('path');

const header = fs.readFileSync(path.resolve(__dirname, '../includes/app_header.php'), 'utf8');

assert.match(header, /function enhanceFieldHints\(root = document\)/, 'Общий интерфейс должен находить подсказки полей на всех страницах');
assert.match(header, /className = 'app-field-help'/, 'У подсказки должна создаваться иконка вопроса');
assert.match(header, /document\.createElement\('span'\)/, 'Иконка должна использовать нейтральный span, как подсказка формата листа');
assert.match(header, /button\.setAttribute\('role', 'button'\)/, 'Интерактивная иконка должна иметь роль кнопки');
assert.match(header, /button\.setAttribute\('tabindex', '0'\)/, 'Иконка должна быть доступна с клавиатуры');
assert.match(header, /button\.append\('\?', tooltip\)/, 'На кнопке должен отображаться знак вопроса');
assert.match(header, /setAttribute\('aria-expanded'/, 'Открытие подсказки должно быть доступно вспомогательным технологиям');
assert.match(header, /document\.addEventListener\('click'/, 'Подсказка должна открываться нажатием');
assert.match(header, /event\.key === 'Escape'/, 'Подсказка должна закрываться клавишей Escape');
assert.match(header, /event\.target\.matches\('\.app-field-help,\.format-help'\)/, 'Все иконки подсказок должны открываться с клавиатуры');
assert.match(header, /new MutationObserver/, 'Динамически добавленные поля тоже должны получать подсказки');
assert.match(header, /\.app-field-help__source\{display:none!important\}/, 'Старый текст подсказки не должен дублироваться рядом с полем');

console.log('field_help.test.js: OK');
