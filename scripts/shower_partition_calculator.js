(function (root, factory) {
    const api = factory();
    if (typeof module === 'object' && module.exports) module.exports = api;
    if (root) root.ShowerPartitionCalculator = api;
})(typeof globalThis !== 'undefined' ? globalThis : this, function () {
    'use strict';

    function positive(value, fallback = 0) {
        const number = Number(value);
        return Number.isFinite(number) && number > 0 ? number : fallback;
    }

    function integer(value, fallback = 1) {
        return Math.max(1, Math.round(positive(value, fallback)));
    }

    function anglePointCount(edgeLengthMm) {
        const length = positive(edgeLengthMm);
        if (!length) return 0;
        if (length <= 200) return 1;
        return Math.ceil((length - 200) / 500) + 1;
    }

    const LAYOUT_DEFINITIONS = {
        built_in: {label: 'Встроенная', panelOffset: -1, railRoute: 'straight', railLabel: 'Прямая: от стены до стены', depthSegments: 0, elbows: 0},
        corner: {label: 'Угловая', panelOffset: 0, railRoute: 'elbow', railLabel: 'Г-образная: ширина + глубина', depthSegments: 1, elbows: 1},
        freestanding: {label: 'Отдельно стоящая', panelOffset: 1, railRoute: 'u_shape', railLabel: 'П-образная: ширина + две глубины', depthSegments: 2, elbows: 2}
    };

    function normalizedConfig(input = {}) {
        const fullHeight = Boolean(input.fullHeight);
        const height = positive(input.height, 2000);
        const layoutType = Object.prototype.hasOwnProperty.call(LAYOUT_DEFINITIONS, input.layoutType) ? input.layoutType : 'built_in';
        const layout = LAYOUT_DEFINITIONS[layoutType];
        const sectionCount = integer(input.sectionCount ?? input.partitionCount, layoutType === 'built_in' ? 2 : 1);
        const partitionCount = Math.max(0, sectionCount + layout.panelOffset);
        const roomWidth = positive(input.roomWidth, 3000);
        const depth = positive(input.depth, 1000);
        const railRoute = fullHeight ? 'none' : layout.railRoute;
        return {
            layoutType,
            layoutLabel: layout.label,
            sectionCount,
            partitionCount,
            depth,
            height,
            roomWidth,
            variant: ['open', 'fascia', 'doors'].includes(input.variant) ? input.variant : 'open',
            fasciaWidth: positive(input.fasciaWidth, 200),
            fasciaHeight: positive(input.fasciaHeight, height),
            doorCount: integer(input.doorCount, sectionCount),
            doorWidth: positive(input.doorWidth, 700),
            doorHeight: positive(input.doorHeight, 1900),
            floorMount: ['profile', 'leg', 'angle'].includes(input.floorMount) ? input.floorMount : 'leg',
            wallMount: ['profile', 'angle'].includes(input.wallMount) ? input.wallMount : 'profile',
            ceilingMount: ['none', 'profile', 'angle'].includes(input.ceilingMount) ? input.ceilingMount : 'none',
            angleSides: Number(input.angleSides) === 2 ? 2 : 1,
            railRoute,
            railLabel: fullHeight ? 'Без верхней трубы: крепление к потолку' : layout.railLabel,
            pipeLengthMm: fullHeight ? 0 : roomWidth + depth * layout.depthSegments,
            pipeElbows: fullHeight ? 0 : layout.elbows,
            fullHeight,
            allowPanelRotation: Boolean(input.allowPanelRotation),
            kerf: Math.max(0, Number(input.kerf) || 0),
            margin: Math.max(0, Number(input.margin) || 0)
        };
    }

    function buildPanelPieces(input = {}) {
        const config = normalizedConfig(input);
        const pieces = config.partitionCount ? [{
            id: 'partition',
            name: 'Панель душевой перегородки',
            width: config.depth,
            height: config.height,
            quantity: config.partitionCount
        }] : [];
        if (config.variant === 'fascia') pieces.push({id: 'fascia', name: 'Фасадная перемычка', width: config.fasciaWidth, height: config.fasciaHeight, quantity: 1});
        if (config.variant === 'doors') pieces.push({id: 'door', name: 'Дверное полотно', width: config.doorWidth, height: config.doorHeight, quantity: config.doorCount});
        return pieces.map(piece => ({...piece, area: piece.width * piece.height * piece.quantity / 1000000}));
    }

    const ROLE_DEFINITIONS = {
        floor_profile: {label: 'P-профиль к полу', unit: 'м', unitKind: 'linear', keywords: ['p-проф', 'п-проф', 'u-проф', 'профиль']},
        wall_profile: {label: 'P-профиль к стене', unit: 'м', unitKind: 'linear', keywords: ['p-проф', 'п-проф', 'u-проф', 'профиль']},
        ceiling_profile: {label: 'P-профиль к потолку', unit: 'м', unitKind: 'linear', keywords: ['p-проф', 'п-проф', 'u-проф', 'профиль']},
        floor_leg: {label: 'Ножка из нержавеющей стали', unit: 'шт.', unitKind: 'piece', keywords: ['ножк', 'опор']},
        floor_angle: {label: 'Уголок к полу', unit: 'шт.', unitKind: 'piece', keywords: ['уголок', 'углов']},
        wall_angle: {label: 'Уголок к стене', unit: 'шт.', unitKind: 'piece', keywords: ['уголок', 'углов']},
        ceiling_angle: {label: 'Уголок к потолку', unit: 'шт.', unitKind: 'piece', keywords: ['уголок', 'углов']},
        top_pipe: {label: 'Верхняя труба', unit: 'м', unitKind: 'linear', keywords: ['труб', 'штанг', 'ригель']},
        panel_pipe_holder: {label: 'Крепление панели к трубе', unit: 'шт.', unitKind: 'piece', keywords: ['панел', 'труб', 'держател', 'креплен']},
        wall_pipe_holder: {label: 'Стеновой держатель трубы', unit: 'шт.', unitKind: 'piece', keywords: ['стен', 'держател', 'фланец']},
        elbow_90: {label: 'Фитинг трубы 90°', unit: 'шт.', unitKind: 'piece', keywords: ['90', 'угол', 'колен', 'поворот']},
        door_set: {label: 'Комплект фурнитуры двери', unit: 'компл.', unitKind: 'piece', keywords: ['двер', 'петл', 'замок', 'защелк']}
    };

    function requirement(role, quantity, note = '') {
        const definition = ROLE_DEFINITIONS[role];
        return {...definition, role, quantity: Math.round(quantity * 1000) / 1000, note};
    }

    function buildRequirements(input = {}) {
        const config = normalizedConfig(input);
        const rows = [];
        const sideMultiplier = config.angleSides;

        if (config.floorMount === 'profile') rows.push(requirement('floor_profile', config.depth * config.partitionCount / 1000, 'Суммарная длина нижних кромок'));
        else if (config.floorMount === 'leg') {
            const legsPerPanel = Math.max(1, Math.ceil(config.depth / 1000));
            rows.push(requirement('floor_leg', legsPerPanel * config.partitionCount, `${legsPerPanel} на перегородку`));
        } else rows.push(requirement('floor_angle', anglePointCount(config.depth) * config.partitionCount * sideMultiplier, sideMultiplier === 2 ? 'С двух сторон' : 'С одной стороны'));

        if (config.wallMount === 'profile') rows.push(requirement('wall_profile', config.height * config.partitionCount / 1000, 'Суммарная высота стеновых кромок'));
        else rows.push(requirement('wall_angle', anglePointCount(config.height) * config.partitionCount * sideMultiplier, sideMultiplier === 2 ? 'С двух сторон' : 'С одной стороны'));

        if (config.fullHeight && config.ceilingMount === 'profile') rows.push(requirement('ceiling_profile', config.depth * config.partitionCount / 1000, 'Суммарная длина верхних кромок'));
        else if (config.fullHeight && config.ceilingMount === 'angle') rows.push(requirement('ceiling_angle', anglePointCount(config.depth) * config.partitionCount * sideMultiplier, sideMultiplier === 2 ? 'С двух сторон' : 'С одной стороны'));

        if (config.railRoute !== 'none') {
            const pipeLength = config.pipeLengthMm / 1000;
            rows.push(requirement('top_pipe', pipeLength, config.layoutLabel + ': ' + config.railLabel.toLocaleLowerCase('ru-RU')));
            rows.push(requirement('panel_pipe_holder', config.partitionCount, 'По одному на перегородку'));
            rows.push(requirement('wall_pipe_holder', 2, 'Начальная и конечная точки'));
            if (config.pipeElbows) rows.push(requirement('elbow_90', config.pipeElbows, config.pipeElbows === 1 ? 'Один поворот трубы' : 'Два поворота трубы'));
        }
        if (config.variant === 'doors') rows.push(requirement('door_set', config.doorCount, 'Один комплект на дверь; состав выбирается из каталога'));
        return rows.filter(row => row.quantity > 0);
    }

    function estimatePanelLayout(input, panel, optimizer) {
        const config = normalizedConfig(input);
        const pieces = buildPanelPieces(config);
        const usedArea = pieces.reduce((sum, piece) => sum + piece.area, 0);
        const panelWidth = positive(panel?.width_mm);
        const panelLength = positive(panel?.height_mm);
        if (!panelWidth || !panelLength || !optimizer?.packSheets || !optimizer?.usableSheetDimensions) {
            return {pieces, sheets: 0, usedArea, sheetArea: 0, wasteArea: 0, remaining: pieces, layouts: []};
        }
        const usable = optimizer.usableSheetDimensions(panelWidth, panelLength, config.margin);
        const queue = pieces.map((piece, index) => ({id: index + 1, name: piece.name, w: piece.width, h: piece.height, qtyLeft: piece.quantity, canRotate: config.allowPanelRotation}));
        const result = optimizer.packSheets(queue, usable.w, usable.h, config.kerf, 'optimal', null);
        const sheets = result.sheets.length;
        const sheetArea = sheets * panelWidth * panelLength / 1000000;
        return {pieces, sheets, usedArea, sheetArea, wasteArea: Math.max(0, sheetArea - usedArea), remaining: result.remaining, layouts: result.sheets};
    }

    function chooseBestPanelFormat(input, panelCandidates, optimizer) {
        const ranked = (panelCandidates || []).map(panel => ({
            panel,
            layout: estimatePanelLayout(input, panel, optimizer)
        })).filter(candidate => candidate.layout.remaining.length === 0 && candidate.layout.sheets > 0);
        ranked.sort((a, b) =>
            a.layout.wasteArea - b.layout.wasteArea ||
            a.layout.sheets - b.layout.sheets ||
            (Number(a.panel.width_mm) * Number(a.panel.height_mm)) - (Number(b.panel.width_mm) * Number(b.panel.height_mm))
        );
        return ranked[0] || null;
    }

    function normalizeText(value) {
        return String(value || '').toLocaleLowerCase('ru-RU').replace(/ё/g, 'е');
    }

    function itemScore(requirementRow, item) {
        const text = normalizeText([item.material_name, item.category_name, item.note].join(' '));
        let score = requirementRow.keywords.reduce((sum, keyword) => sum + (text.includes(normalizeText(keyword)) ? 10 : 0), 0);
        const unit = normalizeText(item.unit);
        if (requirementRow.unitKind === 'linear' && (unit.includes('м') || unit.includes('пог'))) score += 4;
        if (requirementRow.unitKind === 'piece' && !unit.includes('м.п')) score += 2;
        return score;
    }

    function matchingFurniture(requirementRow, items, filters = {}) {
        const supplierId = Number(filters.supplierId || 0);
        const collectionId = Number(filters.collectionId || 0);
        return (items || [])
            .filter(item => !supplierId || Number(item.supplier_id || 0) === supplierId)
            .filter(item => !collectionId || Number(item.collection_id || 0) === collectionId)
            .map(item => ({...item, matchScore: itemScore(requirementRow, item)}))
            .sort((a, b) => b.matchScore - a.matchScore || String(a.material_name).localeCompare(String(b.material_name), 'ru'));
    }

    return {LAYOUT_DEFINITIONS, ROLE_DEFINITIONS, anglePointCount, normalizedConfig, buildPanelPieces, buildRequirements, estimatePanelLayout, chooseBestPanelFormat, matchingFurniture, itemScore};
});
