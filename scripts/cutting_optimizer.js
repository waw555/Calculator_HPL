(function (root, factory) {
    const api = factory();
    if (typeof module === 'object' && module.exports) module.exports = api;
    if (root) root.CuttingOptimizer = api;
})(typeof globalThis !== 'undefined' ? globalThis : this, function () {
    'use strict';

    const EPSILON = 1e-9;

    function positiveNumber(value, fallback = 0) {
        const number = Number(value);
        return Number.isFinite(number) && number > 0 ? number : fallback;
    }

    function nonNegativeNumber(value, fallback = 0) {
        const number = Number(value);
        return Number.isFinite(number) && number >= 0 ? number : fallback;
    }

    function usableSheetDimensions(rawWidth, rawLength, rawMargin) {
        const margin = nonNegativeNumber(rawMargin);
        return {w: positiveNumber(rawWidth) - margin * 2, h: positiveNumber(rawLength) - margin * 2, margin};
    }

    function orientationsFor(piece, method) {
        const base = {w: positiveNumber(piece.w), h: positiveNumber(piece.h), rotated: false};
        const rotated = {w: base.h, h: base.w, rotated: true};

        // w = ширина детали, h = длина детали; H листа является его длиной.
        // Фиксированные стратегии задают ориентацию для всех деталей и не
        // зависят от флага ручного поворота.
        if (method === 'length') return [base];
        if (method === 'width') return [rotated];

        const result = [base];
        if (piece.canRotate && Math.abs(base.w - base.h) > EPSILON) result.push(rotated);
        return result;
    }

    function fitsAxis(size, available, kerf) {
        const remainder = available - size;
        return remainder >= -EPSILON && (Math.abs(remainder) <= EPSILON || remainder + EPSILON >= kerf);
    }

    function placementScore(rect, orientation, heuristic) {
        const dw = rect.w - orientation.w;
        const dh = rect.h - orientation.h;
        const areaWaste = rect.w * rect.h - orientation.w * orientation.h;
        if (heuristic === 'short-side') return [Math.min(dw, dh), Math.max(dw, dh), areaWaste, -orientation.w * orientation.h];
        if (heuristic === 'long-side') return [Math.max(dw, dh), Math.min(dw, dh), areaWaste, -orientation.w * orientation.h];
        return [areaWaste, Math.min(dw, dh), Math.max(dw, dh), -orientation.w * orientation.h];
    }

    function compareTuple(left, right) {
        for (let i = 0; i < left.length; i++) {
            if (Math.abs(left[i] - right[i]) <= EPSILON) continue;
            return left[i] < right[i] ? -1 : 1;
        }
        return 0;
    }

    function chooseSplit(rect, w, h, splitMode) {
        if (splitMode === 'horizontal' || splitMode === 'vertical') return splitMode;
        return rect.w - w > rect.h - h ? 'vertical' : 'horizontal';
    }

    function splitFreeRect(rect, w, h, kerf, splitMode) {
        const hasRight = rect.w - w > EPSILON;
        const hasBottom = rect.h - h > EPSILON;
        const rightW = rect.w - w - (hasRight ? kerf : 0);
        const bottomH = rect.h - h - (hasBottom ? kerf : 0);
        const freeRects = [];
        const cuts = [];

        if (splitMode === 'vertical') {
            if (hasRight) cuts.push({x1: rect.x + w, y1: rect.y, x2: rect.x + w, y2: rect.y + rect.h, length: rect.h});
            if (hasBottom) cuts.push({x1: rect.x, y1: rect.y + h, x2: rect.x + w, y2: rect.y + h, length: w});
            if (rightW > EPSILON) freeRects.push({x: rect.x + w + kerf, y: rect.y, w: rightW, h: rect.h});
            if (bottomH > EPSILON) freeRects.push({x: rect.x, y: rect.y + h + kerf, w, h: bottomH});
        } else {
            if (hasBottom) cuts.push({x1: rect.x, y1: rect.y + h, x2: rect.x + rect.w, y2: rect.y + h, length: rect.w});
            if (hasRight) cuts.push({x1: rect.x + w, y1: rect.y, x2: rect.x + w, y2: rect.y + h, length: h});
            if (bottomH > EPSILON) freeRects.push({x: rect.x, y: rect.y + h + kerf, w: rect.w, h: bottomH});
            if (rightW > EPSILON) freeRects.push({x: rect.x + w + kerf, y: rect.y, w: rightW, h});
        }

        return {freeRects, cuts};
    }

    function planSheet(queue, sheetW, sheetH, kerf, method, heuristic, splitMode) {
        const counts = new Map(queue.map(piece => [piece.id, positiveNumber(piece.qtyLeft)]));
        let freeRects = [{x: 0, y: 0, w: sheetW, h: sheetH}];
        const placed = [];
        const cuts = [];

        while (true) {
            let best = null;
            for (let pi = 0; pi < queue.length; pi++) {
                const piece = queue[pi];
                if ((counts.get(piece.id) || 0) <= 0) continue;
                for (const orientation of orientationsFor(piece, method)) {
                    if (!(orientation.w > 0 && orientation.h > 0)) continue;
                    for (let ri = 0; ri < freeRects.length; ri++) {
                        const rect = freeRects[ri];
                        if (!fitsAxis(orientation.w, rect.w, kerf) || !fitsAxis(orientation.h, rect.h, kerf)) continue;
                        const score = placementScore(rect, orientation, heuristic);
                        if (!best || compareTuple(score, best.score) < 0) best = {pi, ri, orientation, score};
                    }
                }
            }
            if (!best) break;

            const piece = queue[best.pi];
            const rect = freeRects[best.ri];
            const orientation = best.orientation;
            const actualSplit = chooseSplit(rect, orientation.w, orientation.h, splitMode);
            const split = splitFreeRect(rect, orientation.w, orientation.h, kerf, actualSplit);
            freeRects.splice(best.ri, 1, ...split.freeRects);
            cuts.push(...split.cuts);
            placed.push({
                id: piece.id, name: piece.name, grainDirection: piece.grainDirection,
                x: rect.x, y: rect.y, w: orientation.w, h: orientation.h,
                rotated: orientation.rotated, forcedOrientation: method !== 'optimal'
            });
            counts.set(piece.id, (counts.get(piece.id) || 0) - 1);
        }

        return {placed, freeRects, cuts, cutLength: cuts.reduce((sum, cut) => sum + cut.length, 0)};
    }

    function runHeuristic(pieces, sheetW, sheetH, kerf, method, maxSheets, heuristic, splitMode) {
        let queue = pieces
            .map(piece => ({...piece, qtyLeft: Math.max(0, Math.floor(positiveNumber(piece.qtyLeft)))}))
            .filter(piece => piece.qtyLeft > 0)
            .sort((a, b) => (b.w * b.h) - (a.w * a.h));
        const sheets = [];

        while (queue.length && (maxSheets === null || sheets.length < maxSheets)) {
            const sheet = planSheet(queue, sheetW, sheetH, kerf, method, heuristic, splitMode);
            if (!sheet.placed.length) break;
            const placedCounts = new Map();
            sheet.placed.forEach(item => placedCounts.set(item.id, (placedCounts.get(item.id) || 0) + 1));
            queue = queue
                .map(piece => ({...piece, qtyLeft: piece.qtyLeft - (placedCounts.get(piece.id) || 0)}))
                .filter(piece => piece.qtyLeft > 0);
            sheets.push(sheet);
        }
        return {sheets, remaining: queue};
    }

    function resultScore(result, sheetW, sheetH) {
        const remainingQty = result.remaining.reduce((sum, piece) => sum + piece.qtyLeft, 0);
        const remainingArea = result.remaining.reduce((sum, piece) => sum + piece.w * piece.h * piece.qtyLeft, 0);
        const placedArea = result.sheets.reduce((sum, sheet) => sum + sheet.placed.reduce((partSum, part) => partSum + part.w * part.h, 0), 0);
        const usedArea = result.sheets.length * sheetW * sheetH;
        const cutLength = result.sheets.reduce((sum, sheet) => sum + sheet.cutLength, 0);
        return [remainingQty, remainingArea, result.sheets.length, usedArea - placedArea, cutLength];
    }

    function packSheets(pieces, rawSheetW, rawSheetH, rawKerf, method = 'optimal', maxSheets = null) {
        const sheetW = positiveNumber(rawSheetW);
        const sheetH = positiveNumber(rawSheetH);
        const kerf = nonNegativeNumber(rawKerf);
        const sheetLimit = maxSheets === null ? null : Math.max(0, Math.floor(nonNegativeNumber(maxSheets)));
        if (!(sheetW > 0 && sheetH > 0) || sheetLimit === 0) return {sheets: [], remaining: pieces.map(piece => ({...piece}))};

        const variants = [
            ['area', 'adaptive'], ['short-side', 'adaptive'], ['long-side', 'adaptive'],
            ['area', 'horizontal'], ['area', 'vertical'],
            ['short-side', 'horizontal'], ['short-side', 'vertical']
        ];
        let best = null;
        for (const [heuristic, splitMode] of variants) {
            const result = runHeuristic(pieces, sheetW, sheetH, kerf, method, sheetLimit, heuristic, splitMode);
            const score = resultScore(result, sheetW, sheetH);
            if (!best || compareTuple(score, best.score) < 0) best = {...result, score};
        }
        return {sheets: best.sheets, remaining: best.remaining};
    }

    return {packSheets, orientationsFor, usableSheetDimensions};
});
