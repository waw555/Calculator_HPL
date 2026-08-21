'use strict';

const assert = require('node:assert/strict');
const shower = require('./shower_partition_calculator');
const optimizer = require('./cutting_optimizer');

assert.equal(shower.anglePointCount(100), 1);
assert.equal(shower.anglePointCount(700), 2);
assert.equal(shower.anglePointCount(1000), 3, 'между крайними точками шаг не должен превышать 500 мм');

{
    const builtIn = shower.normalizedConfig({layoutType: 'built_in', sectionCount: 2, roomWidth: 3000, depth: 1000});
    const corner = shower.normalizedConfig({layoutType: 'corner', sectionCount: 2, roomWidth: 3000, depth: 1000});
    const freestanding = shower.normalizedConfig({layoutType: 'freestanding', sectionCount: 2, roomWidth: 3000, depth: 1000});
    assert.deepEqual(
        [builtIn.partitionCount, corner.partitionCount, freestanding.partitionCount],
        [1, 2, 3],
        'число HPL-панелей должно учитывать отсутствующие или дополнительные торцевые стены'
    );
    assert.deepEqual([builtIn.railRoute, corner.railRoute, freestanding.railRoute], ['straight', 'elbow', 'u_shape']);
    assert.deepEqual([builtIn.pipeLengthMm, corner.pipeLengthMm, freestanding.pipeLengthMm], [3000, 4000, 5000]);
    assert.deepEqual([builtIn.pipeElbows, corner.pipeElbows, freestanding.pipeElbows], [0, 1, 2]);

    const manual = shower.normalizedConfig({layoutType: 'built_in', sectionCount: 2, partitionCount: 4});
    assert.equal(manual.partitionCount, 4, 'явно указанное количество перегородок должно использоваться в расчёте');
}

{
    const rows = shower.buildRequirements({layoutType: 'built_in', sectionCount: 3, depth: 900, height: 2000, roomWidth: 3000, floorMount: 'leg', wallMount: 'angle', angleSides: 2});
    const byRole = Object.fromEntries(rows.map(row => [row.role, row]));
    assert.equal(byRole.floor_leg.quantity, 2, 'встроенная схема на три секции содержит две HPL-перегородки');
    assert.equal(byRole.floor_leg.note, 'По одной ножке высотой 150 мм на перегородку');
    assert.equal(byRole.wall_angle.quantity, 20);
    assert.equal(byRole.top_pipe.quantity, 3);
    assert.equal(byRole.panel_pipe_holder.quantity, 2);
    assert.equal(byRole.wall_pipe_holder.quantity, 2);
    assert.equal(byRole.elbow_90, undefined);
}

{
    const rows = shower.buildRequirements({layoutType: 'corner', sectionCount: 2, partitionCount: 3, depth: 1800, floorMount: 'leg'});
    const leg = rows.find(row => row.role === 'floor_leg');
    assert.equal(leg.quantity, 3, 'на каждую перегородку нужна ровно одна ножка независимо от глубины панели');
}

{
    const rows = shower.buildRequirements({layoutType: 'corner', sectionCount: 2, depth: 1200, roomWidth: 3200, floorMount: 'profile', wallMount: 'profile'});
    const byRole = Object.fromEntries(rows.map(row => [row.role, row]));
    assert.equal(byRole.floor_profile.quantity, 2.4);
    assert.equal(byRole.top_pipe.quantity, 4.4, 'угловая труба учитывает ширину помещения и одну глубину');
    assert.equal(byRole.elbow_90.quantity, 1);
    assert.equal(byRole.panel_pipe_holder.quantity, 2);
}

{
    const rows = shower.buildRequirements({layoutType: 'freestanding', sectionCount: 2, depth: 1200, roomWidth: 3200});
    const byRole = Object.fromEntries(rows.map(row => [row.role, row]));
    assert.equal(byRole.top_pipe.quantity, 5.6, 'П-образная труба учитывает ширину и две глубины');
    assert.equal(byRole.elbow_90.quantity, 2);
    assert.equal(byRole.panel_pipe_holder.quantity, 3);
}

{
    const rows = shower.buildRequirements({layoutType: 'corner', sectionCount: 2, ceilingMount: 'profile'});
    const byRole = Object.fromEntries(rows.map(row => [row.role, row]));
    assert.equal(byRole.top_pipe, undefined, 'при креплении к потолку труба не нужна');
    assert.equal(byRole.ceiling_profile.quantity, 2);
}

{
    const pieces = shower.buildPanelPieces({layoutType: 'corner', sectionCount: 2, depth: 1000, height: 2000, variant: 'doors', doorCount: 2, doorWidth: 700, doorHeight: 1900});
    assert.deepEqual(pieces.map(piece => piece.quantity), [2, 2]);
    assert.equal(pieces.reduce((sum, piece) => sum + piece.area, 0), 6.66);
}

{
    const layout = shower.estimatePanelLayout({layoutType: 'corner', sectionCount: 2, depth: 900, height: 2000, kerf: 4, margin: 5, allowPanelRotation: false}, {width_mm: 1300, height_mm: 3050}, optimizer);
    assert.equal(layout.sheets, 2);
    assert.equal(layout.remaining.length, 0);
    assert.ok(layout.wasteArea > 0);
}

{
    const requirement = shower.buildRequirements({layoutType: 'corner', sectionCount: 1, floorMount: 'leg', wallMount: 'profile', ceilingMount: 'profile'})[0];
    const matches = shower.matchingFurniture(requirement, [
        {id: 1, material_name: 'Опора для панели', category_name: 'Ножки', unit: 'шт.', supplier_id: 1, collection_id: 2},
        {id: 2, material_name: 'Профиль алюминиевый', category_name: 'Профили', unit: 'м.п.', supplier_id: 1, collection_id: 2},
        {id: 3, material_name: 'Ножка с похожим названием', category_name: 'Прочая фурнитура', unit: 'шт.', supplier_id: 1, collection_id: 2}
    ], {supplierId: 1, collectionId: 2});
    assert.equal(requirement.label, 'Крепление к полу');
    assert.equal(requirement.groupLabel, 'Ножка');
    assert.deepEqual(matches.map(item => item.id), [1], 'товары из других групп не должны попадать в выбор даже при совпадении названия');
    assert.equal(matches[0].id, 1);
}

{
    const rows = shower.buildRequirements({layoutType: 'corner', sectionCount: 1, floorMount: 'profile', wallMount: 'profile', ceilingMount: 'profile'});
    const byRole = Object.fromEntries(rows.map(row => [row.role, row]));
    assert.equal(byRole.floor_profile.label, 'Крепление к полу');
    assert.equal(byRole.wall_profile.label, 'Крепление к стене');
    assert.equal(byRole.ceiling_profile.label, 'Крепление к потолку');
    assert.equal(byRole.wall_profile.groupLabel, 'П-профиль');
}

{
    const wallProfile = shower.buildRequirements({layoutType: 'built_in', sectionCount: 2, wallMount: 'profile'})
        .find(row => row.role === 'wall_profile');
    const matches = shower.matchingFurniture(wallProfile, [
        {id: 10, material_name: 'П-профиль 20×20', category_name: 'Профили', unit: 'м.п.', supplier_id: 3, collection_id: 7},
        {id: 11, material_name: 'П-профиль 20×20', category_name: 'Профили', unit: 'м.п.', supplier_id: 3, collection_id: 8}
    ], {supplierId: 3, collectionId: 7});
    assert.deepEqual(matches.map(item => item.id), [10], 'стеновой П-профиль должен загружаться из выбранной серии и категории «Профили»');
}

{
    const pieces = shower.buildPanelPieces({layoutType: 'built_in', sectionCount: 2, depth: 900, height: 2100, variant: 'fascia', fasciaWidth: 250});
    const fascia = pieces.find(piece => piece.id === 'fascia');
    assert.equal(fascia.width, 250);
    assert.equal(fascia.height, 2100);
    assert.equal(fascia.area, 0.525);
}

{
    const best = shower.chooseBestPanelFormat(
        {layoutType: 'corner', sectionCount: 2, depth: 900, height: 2000, kerf: 4, margin: 5},
        [
            {id: 1, width_mm: 1300, height_mm: 3050},
            {id: 2, width_mm: 1900, height_mm: 3050}
        ],
        optimizer
    );
    assert.equal(best.panel.id, 2);
    assert.equal(best.layout.sheets, 1);
}

{
    const rows = shower.buildRequirements({layoutType: 'corner', sectionCount: 2, roomWidth: 3000, depth: 1000, ceilingMount: 'none', topSupport: 'aluminium_profile'});
    const byRole = Object.fromEntries(rows.map(row => [row.role, row]));
    assert.equal(byRole.top_aluminium_profile.quantity, 4);
    assert.equal(byRole.top_pipe, undefined);
}

{
    const volumes = shower.serviceVolumes({
        sheets: 2,
        pieces: [
            {width: 1000, height: 2000, quantity: 2},
            {width: 700, height: 1900, quantity: 1}
        ]
    }, {width_mm: 1300, height_mm: 3050});
    assert.equal(volumes.edging, 17.4, 'торцевание считается по периметру каждой целой панели');
    assert.equal(volumes.cutting, 17.2, 'раскрой считается по периметру каждого изделия');
    assert.equal(volumes.bevel, 11.8, 'фаска считается по двум сторонам высоты каждого изделия');
}

console.log('shower_partition_calculator: все тесты пройдены');
