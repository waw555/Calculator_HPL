'use strict';

const assert = require('node:assert/strict');
const shower = require('./shower_partition_calculator');
const optimizer = require('./cutting_optimizer');

assert.equal(shower.anglePointCount(100), 1);
assert.equal(shower.anglePointCount(700), 2);
assert.equal(shower.anglePointCount(1000), 3, 'между крайними точками шаг не должен превышать 500 мм');

{
    const rows = shower.buildRequirements({partitionCount: 3, depth: 900, height: 2000, roomWidth: 3000, floorMount: 'leg', wallMount: 'angle', angleSides: 2, railRoute: 'straight'});
    const byRole = Object.fromEntries(rows.map(row => [row.role, row]));
    assert.equal(byRole.floor_leg.quantity, 3, 'до метра нужна одна ножка на панель');
    assert.equal(byRole.wall_angle.quantity, 30, 'уголки считаются по высоте, количеству панелей и сторонам');
    assert.equal(byRole.top_pipe.quantity, 3, 'прямая труба равна ширине от стены до стены');
    assert.equal(byRole.panel_pipe_holder.quantity, 3);
    assert.equal(byRole.wall_pipe_holder.quantity, 2);
}

{
    const rows = shower.buildRequirements({partitionCount: 2, depth: 1200, roomWidth: 3200, floorMount: 'profile', wallMount: 'profile', railRoute: 'elbow'});
    const byRole = Object.fromEntries(rows.map(row => [row.role, row]));
    assert.equal(byRole.floor_profile.quantity, 2.4);
    assert.equal(byRole.top_pipe.quantity, 4.4, 'Г-образная труба учитывает ширину помещения и глубину');
    assert.equal(byRole.elbow_90.quantity, 1);
}

{
    const pieces = shower.buildPanelPieces({partitionCount: 2, depth: 1000, height: 2000, roomWidth: 2800, variant: 'doors', doorCount: 2, doorWidth: 700, doorHeight: 1900});
    assert.deepEqual(pieces.map(piece => piece.quantity), [2, 2]);
    assert.equal(pieces.reduce((sum, piece) => sum + piece.area, 0), 6.66);
}

{
    const layout = shower.estimatePanelLayout({partitionCount: 2, depth: 900, height: 2000, kerf: 4, margin: 5, allowPanelRotation: false}, {width_mm: 1300, height_mm: 3050}, optimizer);
    assert.equal(layout.sheets, 2);
    assert.equal(layout.remaining.length, 0);
    assert.ok(layout.wasteArea > 0);
}

{
    const requirement = shower.buildRequirements({partitionCount: 1, floorMount: 'leg', wallMount: 'profile', railRoute: 'none'})[0];
    const matches = shower.matchingFurniture(requirement, [
        {id: 1, material_name: 'Опора для панели', category_name: 'Ножки', unit: 'шт.', supplier_id: 1, collection_id: 2},
        {id: 2, material_name: 'Профиль алюминиевый', category_name: 'Профили', unit: 'м.п.', supplier_id: 1, collection_id: 2}
    ], {supplierId: 1, collectionId: 2});
    assert.equal(matches[0].id, 1, 'лучшее совпадение должно подниматься первым');
}

{
    const pieces = shower.buildPanelPieces({partitionCount: 1, depth: 900, height: 2100, variant: 'fascia', fasciaWidth: 250});
    const fascia = pieces.find(piece => piece.id === 'fascia');
    assert.equal(fascia.width, 250);
    assert.equal(fascia.height, 2100, 'высота перемычки по умолчанию равна высоте перегородки');
    assert.equal(fascia.area, 0.525);
}

{
    const best = shower.chooseBestPanelFormat(
        {partitionCount: 2, depth: 900, height: 2000, kerf: 4, margin: 5},
        [
            {id: 1, width_mm: 1300, height_mm: 3050},
            {id: 2, width_mm: 1900, height_mm: 3050}
        ],
        optimizer
    );
    assert.equal(best.panel.id, 2, 'режим «Любой» должен выбрать формат с меньшим отходом');
    assert.equal(best.layout.sheets, 1);
}

console.log('shower_partition_calculator: все тесты пройдены');
