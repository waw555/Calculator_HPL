'use strict';

const assert = require('node:assert/strict');
const {packSheets, orientationsFor, usableSheetDimensions} = require('./cutting_optimizer');

function piece(overrides = {}) {
    return {id: 1, name: 'Деталь', w: 700, h: 1200, qtyLeft: 1, canRotate: false, grainDirection: 'none', ...overrides};
}

function placed(result) {
    return result.sheets.flatMap(sheet => sheet.placed);
}

{
    assert.deepEqual(usableSheetDimensions(1300, 3050, 5), {w: 1290, h: 3040, margin: 5}, 'торцевание должно сниматься с двух противоположных краёв по каждой оси');
}

{
    const orientation = orientationsFor(piece({canRotate: true}), 'length');
    assert.deepEqual(orientation, [{w: 700, h: 1200, rotated: false}], 'режим по длине обязан фиксировать длину детали вдоль длины листа');
}

{
    const result = packSheets([piece()], 800, 1300, 4, 'length', 1);
    assert.equal(placed(result).length, 1);
    assert.equal(placed(result)[0].rotated, false);
    assert.equal(placed(result)[0].h, 1200);
}

{
    const result = packSheets([piece()], 1300, 800, 4, 'width', 1);
    assert.equal(placed(result).length, 1, 'режим по ширине должен принудительно ориентировать даже деталь без разрешения ручного поворота');
    assert.equal(placed(result)[0].rotated, true);
    assert.equal(placed(result)[0].h, 700);
}

{
    const locked = packSheets([piece()], 1300, 800, 4, 'optimal', 1);
    assert.equal(placed(locked).length, 0, 'оптимальный режим не должен поворачивать заблокированную деталь');
    const allowed = packSheets([piece({canRotate: true})], 1300, 800, 4, 'optimal', 1);
    assert.equal(placed(allowed).length, 1);
    assert.equal(placed(allowed)[0].rotated, true);
}

{
    const exactKerf = packSheets([piece({w: 500, h: 1000, qtyLeft: 2})], 1004, 1000, 4, 'length', 1);
    assert.equal(placed(exactKerf).length, 2, 'между двумя деталями должен помещаться ровно один пропил');
    assert.equal(exactKerf.sheets[0].cutLength, 1000, 'общая линия между деталями должна считаться одним проходом пилы');

    const tooWideKerf = packSheets([piece({w: 500, h: 1000, qtyLeft: 2})], 1004, 1000, 5, 'length', 1);
    assert.equal(placed(tooWideKerf).length, 1);
    assert.equal(tooWideKerf.remaining[0].qtyLeft, 1);
}

{
    const noRoomForBlade = packSheets([piece({w: 1000, h: 1000})], 1003, 1000, 4, 'length', 1);
    assert.equal(placed(noRoomForBlade).length, 0, 'остаток меньше пропила нельзя считать допустимым резом');
}

{
    const result = packSheets([
        piece({id: 1, w: 400, h: 800, qtyLeft: 2, canRotate: true}),
        piece({id: 2, w: 300, h: 500, qtyLeft: 2, canRotate: true})
    ], 1000, 1600, 4, 'optimal', 2);
    for (const sheet of result.sheets) {
        for (const item of sheet.placed) {
            assert.ok(item.x >= 0 && item.y >= 0 && item.x + item.w <= 1000 && item.y + item.h <= 1600);
        }
        for (let i = 0; i < sheet.placed.length; i++) {
            for (let j = i + 1; j < sheet.placed.length; j++) {
                const a = sheet.placed[i], b = sheet.placed[j];
                const overlap = a.x < b.x + b.w && b.x < a.x + a.w && a.y < b.y + b.h && b.y < a.y + a.h;
                assert.equal(overlap, false, 'детали не должны пересекаться');
            }
        }
    }
}

console.log('cutting_optimizer: все тесты пройдены');
