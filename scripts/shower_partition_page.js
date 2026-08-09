(function () {
    'use strict';

    if (!window.ShowerPartitionCalculator || !window.CuttingOptimizer) {
        console.error('Модули расчёта душевых перегородок не загружены.');
        return;
    }

    const showerNode = document.getElementById('shower-config');
    const formatSelect = document.getElementById('panel_format_id');
    const roleSelections = {};
    let availableFormats = [];
    let fasciaHeightTouched = false;
    let modelTriggerBeforeOpen = null;

    function value(id) {
        return parseFloat(document.getElementById(id)?.value || '0') || 0;
    }

    function toRub(amount, currency) {
        const code = String(currency || 'RUB').toUpperCase();
        if (code === 'RUB') return Number(amount || 0);
        const row = currencyRates.find(function (rate) { return String(rate.code).toUpperCase() === code; });
        return row ? Number(amount || 0) * Number(row.rate_to_rub || 0) / Math.max(1, Number(row.nominal || 1)) : Number(amount || 0);
    }

    function normalizedDecorColor(rawColor) {
        const color = String(rawColor || '').trim().toLowerCase();
        return /^#[0-9a-f]{6}$/.test(color) ? color : '#d9dde3';
    }

    function shadeColor(hex, percent) {
        const value = normalizedDecorColor(hex).slice(1);
        const amount = Math.round(255 * percent / 100);
        const channels = [0, 2, 4].map(function (offset) {
            return Math.max(0, Math.min(255, parseInt(value.slice(offset, offset + 2), 16) + amount)).toString(16).padStart(2, '0');
        });
        return '#' + channels.join('');
    }

    function selectedDecorColor() {
        const decorId = document.getElementById('decor_input').value;
        const decor = panels.find(function (panel) { return String(panel.id) === String(decorId); });
        return normalizedDecorColor(decor?.decor_color);
    }

    function showerSelected() {
        return (typeSelect.selectedOptions[0]?.textContent || '').toLocaleLowerCase('ru-RU').includes('душ');
    }

    function config() {
        return ShowerPartitionCalculator.normalizedConfig({
            partitionCount: value('shower_partition_count'),
            roomWidth: value('shower_room_width'),
            depth: value('shower_depth'),
            height: value('shower_height'),
            variant: document.getElementById('shower_variant').value,
            fullHeight: document.getElementById('shower_full_height').checked,
            fasciaWidth: value('shower_fascia_width'),
            fasciaHeight: value('shower_fascia_height'),
            doorCount: value('shower_door_count'),
            doorWidth: value('shower_door_width'),
            doorHeight: value('shower_door_height'),
            floorMount: document.getElementById('shower_floor_mount').value,
            wallMount: document.getElementById('shower_wall_mount').value,
            ceilingMount: document.getElementById('shower_ceiling_mount').value,
            angleSides: value('shower_angle_sides'),
            railRoute: document.getElementById('shower_rail_route').value,
            kerf: value('shower_kerf'),
            margin: value('shower_margin'),
            allowPanelRotation: document.getElementById('shower_allow_rotation').checked
        });
    }

    function renderFormats() {
        const decorId = document.getElementById('decor_input').value;
        const decor = panels.find(function (panel) { return String(panel.id) === String(decorId); });
        const previous = formatSelect.value;
        formatSelect.innerHTML = '';
        availableFormats = [];
        if (!decor) {
            formatSelect.innerHTML = '<option value="">— Сначала выберите декор —</option>';
            document.getElementById('custom-format-fields').classList.add('field-hidden');
            return;
        }
        availableFormats = panels.filter(function (panel) {
            return Number(panel.manufacturer_id || 0) === Number(decor.manufacturer_id || 0)
                && String(panel.decor_number || '') === String(decor.decor_number || '')
                && String(panel.decor_name || '') === String(decor.decor_name || '');
        });
        formatSelect.innerHTML = '<option value="__auto__">Любой — подобрать оптимальный</option>';
        availableFormats.forEach(function (panel) {
            const option = document.createElement('option');
            option.value = panel.id;
            option.textContent = Math.round(Number(panel.height_mm || 0)) + '×' + Math.round(Number(panel.width_mm || 0)) + ' мм' + (panel.name ? ' · ' + panel.name : '');
            formatSelect.append(option);
        });
        formatSelect.insertAdjacentHTML('beforeend', '<option value="__custom__">Свой — указать размеры</option>');
        const allowed = previous === '__auto__' || previous === '__custom__' || availableFormats.some(function (panel) { return String(panel.id) === String(previous); });
        formatSelect.value = allowed ? previous : '__auto__';
        toggleCustomFormat();
    }

    function toggleCustomFormat() {
        document.getElementById('custom-format-fields').classList.toggle('field-hidden', formatSelect.value !== '__custom__');
    }

    function renderCollections() {
        const supplierId = Number(document.getElementById('supplier_id').value || 0);
        const select = document.getElementById('collection_id');
        const field = document.getElementById('collection-field');
        let available = 0;
        Array.from(select.options).forEach(function (option) {
            if (!option.value || option.value === '0') return;
            const matches = supplierId > 0 && Number(option.dataset.supplier || 0) === supplierId;
            option.hidden = !matches;
            if (matches) available += 1;
        });
        if (!supplierId || !available) {
            select.value = '0';
            field.classList.add('hidden');
        } else {
            if (select.selectedOptions[0]?.hidden) select.value = '0';
            field.classList.remove('hidden');
        }
    }

    function renderSchematic(current) {
        const shownCount = Math.min(8, Math.max(1, Math.round(current.partitionCount)));
        const panelColor = selectedDecorColor();
        const panelLight = shadeColor(panelColor, 24);
        const panelDark = shadeColor(panelColor, -12);
        let panelsSvg = '';
        for (let index = 0; index < shownCount; index += 1) {
            const x = 92 + (270 / (shownCount + 1)) * (index + 1);
            panelsSvg += '<g class="shower-model-shadow"><polygon points="' + x + ',300 ' + (x + 112) + ',236 ' + (x + 112) + ',92 ' + x + ',156" fill="url(#hplFace)" stroke="#2453a6" stroke-width="2"/><line x1="' + x + '" y1="300" x2="' + x + '" y2="313" stroke="#e9164d" stroke-width="5"/><circle cx="' + (x + 112) + '" cy="92" r="5" fill="#e9164d"/></g>';
        }
        let frontSvg = '';
        if (current.variant === 'fascia') {
            frontSvg = '<g class="shower-model-shadow"><polygon points="355,300 397,276 397,112 355,136" fill="url(#fasciaFace)" stroke="#e9164d" stroke-width="2"/><text x="368" y="205" fill="#172033" font-size="10" transform="rotate(-30 368 205)">перемычка</text></g>';
        } else if (current.variant === 'doors') {
            const doorCount = Math.min(5, Math.max(1, Math.round(current.doorCount)));
            const doorWidth = 210 / doorCount;
            for (let door = 0; door < doorCount; door += 1) {
                const x = 105 + door * doorWidth;
                frontSvg += '<g class="shower-model-shadow"><polygon points="' + x + ',300 ' + (x + doorWidth - 5) + ',300 ' + (x + doorWidth - 5) + ',158 ' + x + ',158" fill="url(#doorFace)" stroke="#174ea6" stroke-width="2"/><circle cx="' + (x + doorWidth - 17) + '" cy="230" r="3" fill="#e9164d"/></g>';
            }
        }
        let pipe = '';
        let label = 'Без верхней трубы';
        if (current.railRoute === 'straight') {
            pipe = '<path d="M78 134 L455 82" fill="none" stroke="#aeb8c7" stroke-width="10" stroke-linecap="round"/><path d="M78 131 L455 79" fill="none" stroke="#f8fafc" stroke-width="3" stroke-linecap="round"/>';
            label = 'От стены до стены';
        } else if (current.railRoute === 'elbow') {
            pipe = '<path d="M78 134 L382 134 L466 84" fill="none" stroke="#aeb8c7" stroke-width="10" stroke-linecap="round" stroke-linejoin="round"/><path d="M78 131 L382 131 L466 81" fill="none" stroke="#f8fafc" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/><circle cx="382" cy="132" r="8" fill="#e9164d"/>';
            label = 'Г-образный маршрут';
        }
        const svg = document.getElementById('shower-schematic-svg');
        svg.innerHTML = '<defs><linearGradient id="hplFace" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="' + panelLight + '"/><stop offset="1" stop-color="' + panelDark + '"/></linearGradient><linearGradient id="fasciaFace" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="' + panelLight + '"/><stop offset="1" stop-color="' + panelColor + '"/></linearGradient><linearGradient id="doorFace" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="' + panelLight + '"/><stop offset="1" stop-color="' + panelDark + '"/></linearGradient><pattern id="floorGrid" width="24" height="24" patternUnits="userSpaceOnUse" patternTransform="skewX(-30)"><path d="M24 0H0V24" fill="none" stroke="#d9e2ec" stroke-width="1"/></pattern></defs><polygon points="55,315 355,315 485,240 185,240" fill="url(#floorGrid)" stroke="#9aa9bc"/><polygon points="185,58 485,58 485,240 185,240" fill="#eef2f7" stroke="#9aa9bc"/><polygon points="55,133 185,58 185,240 55,315" fill="#e2e8f0" stroke="#9aa9bc"/><path d="M185 58V240L55 315" fill="none" stroke="#64748b" stroke-width="3"/>' + panelsSvg + frontSvg + pipe + '<text x="270" y="360" text-anchor="middle" fill="#64748b" font-size="12">изометрическая модель · размеры пропорциональны условно</text>';
        document.getElementById('shower-scheme-label').textContent = label;
        const pipeLength = current.railRoute === 'none' ? 0 : (current.roomWidth + (current.railRoute === 'elbow' ? current.depth : 0)) / 1000;
        document.getElementById('shower-schematic-stats').innerHTML =
            '<span>Перегородки <b>' + current.partitionCount + ' шт.</b></span>' +
            '<span>Глубина <b>' + formatter.format(current.depth) + ' мм</b></span>' +
            '<span>Высота <b>' + formatter.format(current.height) + ' мм</b></span>' +
            '<span>Верхняя труба <b>' + formatter.format(pipeLength) + ' м</b></span>';
        syncModalModel();
    }

    function syncModalModel() {
        const source = document.getElementById('shower-schematic-svg');
        const target = document.getElementById('shower-model-modal-svg');
        if (!source || !target) return;
        target.setAttribute('viewBox', source.getAttribute('viewBox') || '0 0 520 390');
        target.innerHTML = source.innerHTML;
    }

    function openModelModal() {
        const modal = document.getElementById('shower-model-modal');
        modelTriggerBeforeOpen = document.activeElement;
        syncModalModel();
        modal.classList.remove('hidden');
        modal.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
        document.getElementById('shower-model-modal-close').focus();
    }

    function closeModelModal() {
        const modal = document.getElementById('shower-model-modal');
        modal.classList.add('hidden');
        modal.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
        if (modelTriggerBeforeOpen?.focus) modelTriggerBeforeOpen.focus();
    }

    function renderRoles(requirements) {
        const node = document.getElementById('hardware-role-list');
        const filters = {
            supplierId: document.getElementById('supplier_id').value,
            collectionId: document.getElementById('collection_id').value
        };
        if (!requirements.length) {
            node.innerHTML = '<div class="shower-note">Для выбранной конфигурации фурнитура не требуется.</div>';
            return;
        }
        node.innerHTML = '<div class="hardware-picker__head"><span>Роль</span><span>Товар из базы</span><span>Количество</span></div>' + requirements.map(function (requirement) {
            const matches = ShowerPartitionCalculator.matchingFurniture(requirement, furnitureCatalog, filters).slice(0, 50);
            let selected = matches.find(function (item) { return String(item.id) === String(roleSelections[requirement.role] || ''); });
            if (!selected) selected = matches.find(function (item) { return item.matchScore > 0; });
            if (selected) roleSelections[requirement.role] = String(selected.id);
            const options = matches.map(function (item) {
                const meta = [item.category_name, item.supplier_name, item.collection_name].filter(Boolean).join(' · ');
                const isSelected = String(item.id) === String(roleSelections[requirement.role] || '') ? ' selected' : '';
                return '<option value="' + escapeHtml(item.id) + '"' + isSelected + '>' + escapeHtml(item.material_name) + (meta ? ' — ' + escapeHtml(meta) : '') + '</option>';
            }).join('');
            return '<div class="hardware-role"><div class="hardware-role__name">' + escapeHtml(requirement.label) + '<small>' + escapeHtml(requirement.note) + '</small></div><select data-shower-role="' + escapeHtml(requirement.role) + '"><option value="">— Подобрать вручную —</option>' + options + '</select><div class="hardware-role__qty">' + formatter.format(requirement.quantity) + ' ' + escapeHtml(requirement.unit) + '</div></div>';
        }).join('');
        node.querySelectorAll('[data-shower-role]').forEach(function (select) {
            select.addEventListener('change', function () { roleSelections[select.dataset.showerRole] = select.value; });
        });
    }

    function refresh() {
        const active = showerSelected();
        showerNode.classList.toggle('hidden', !active);
        if (!active) return;
        paramsNode.innerHTML = '';
        const current = config();
        const variant = current.variant;
        document.getElementById('shower-fascia-fields').classList.toggle('field-hidden', variant !== 'fascia');
        ['shower-door-count-fields', 'shower-door-width-fields', 'shower-door-height-fields'].forEach(function (id) {
            document.getElementById(id).classList.toggle('field-hidden', variant !== 'doors');
        });
        document.getElementById('shower-ceiling-fields').classList.toggle('field-hidden', !current.fullHeight);
        const angles = current.floorMount === 'angle' || current.wallMount === 'angle' || (current.fullHeight && current.ceilingMount === 'angle');
        document.getElementById('shower-angle-fields').classList.toggle('field-hidden', !angles);
        const requirements = ShowerPartitionCalculator.buildRequirements(current);
        renderSchematic(current);
        renderRoles(requirements);
    }

    function resolvePanelSelection(current) {
        const mode = formatSelect.value;
        const decorId = document.getElementById('decor_input').value;
        const source = panels.find(function (panel) { return String(panel.id) === String(decorId); });
        if (!source) return null;
        if (mode === '__auto__') {
            const best = ShowerPartitionCalculator.chooseBestPanelFormat(current, availableFormats, window.CuttingOptimizer);
            if (!best) return {panel: null, layout: null, error: 'Ни один из существующих форматов выбранного декора не вмещает все детали.'};
            best.panel = Object.assign({}, best.panel, {name: 'Оптимальный формат · ' + (best.panel.name || (best.panel.height_mm + '×' + best.panel.width_mm + ' мм'))});
            return best;
        }
        if (mode === '__custom__') {
            const width = value('custom_sheet_width');
            const height = value('custom_sheet_height');
            if (!width || !height) return {panel: null, layout: null, error: 'Укажите ширину и длину своего листа.'};
            const sourceArea = Number(source.width_mm || 0) * Number(source.height_mm || 0) / 1000000;
            const pricePerM2 = Number(source.price_per_m2 || 0) || (sourceArea > 0 ? Number(source.price_per_sheet || 0) / sourceArea : 0);
            const panel = Object.assign({}, source, {
                id: 'custom',
                name: 'Свой формат ' + Math.round(height) + '×' + Math.round(width) + ' мм',
                width_mm: width,
                height_mm: height,
                price_per_sheet: pricePerM2 * width * height / 1000000
            });
            return {panel: panel, layout: ShowerPartitionCalculator.estimatePanelLayout(current, panel, window.CuttingOptimizer)};
        }
        const panel = availableFormats.find(function (row) { return String(row.id) === String(mode); });
        return panel ? {panel: panel, layout: ShowerPartitionCalculator.estimatePanelLayout(current, panel, window.CuttingOptimizer)} : null;
    }

    function serviceRows(area, quantity) {
        return services.slice(0, 4).map(function (service) {
            const volume = String(service.unit || '').toLocaleLowerCase('ru-RU').includes('м') ? area : quantity;
            const price = toRub(service.price, service.currency);
            return {name: service.name, volume: volume, unit: service.unit, price: price, currency: 'RUB', sum: volume * price};
        });
    }

    function calculateShower() {
        if (!document.getElementById('object_name').value.trim()) {
            document.getElementById('object_name').focus();
            document.getElementById('object_name').reportValidity();
            return;
        }
        const inputs = collectInputs();
        inputs.decor = document.getElementById('decor_input').selectedOptions[0]?.textContent?.trim() || '';
        inputs.panel_format_id = formatSelect.value;
        const current = config();
        const selection = resolvePanelSelection(current);
        if (!selection || selection.error || !selection.panel || !selection.layout) {
            alert(selection?.error || 'Выберите декор и формат листа для расчёта раскроя.');
            formatSelect.focus();
            return;
        }
        const panel = selection.panel;
        const layout = selection.layout;
        inputs.panel_format_name = panel.name;
        if (layout.remaining.length) {
            alert('Часть деталей не помещается в выбранный формат листа:\n' + layout.remaining.map(function (item) { return item.name + ': ' + item.qtyLeft + ' шт.'; }).join('\n'));
            return;
        }
        const requirements = ShowerPartitionCalculator.buildRequirements(current);
        const hardware = requirements.map(function (requirement) {
            const item = furnitureCatalog.find(function (row) { return String(row.id) === String(roleSelections[requirement.role] || ''); });
            const price = toRub(item?.price, item?.currency);
            return {
                role: requirement.role,
                name: item?.material_name || requirement.label,
                category: [requirement.label, item?.category_name, item ? '' : 'Требуется подобрать товар'].filter(Boolean).join(' · '),
                quantity: requirement.quantity,
                unit: item?.unit || requirement.unit,
                price: price,
                currency: 'RUB',
                sum: requirement.quantity * price,
                selected: Boolean(item)
            };
        });
        const rawMaterialPrice = Number(panel.price_per_sheet || (Number(panel.price_per_m2 || 0) * Number(panel.width_mm || 0) * Number(panel.height_mm || 0) / 1000000));
        const materialPrice = toRub(rawMaterialPrice, panel.currency);
        const materialTotal = layout.sheets * materialPrice;
        const products = layout.pieces.map(function (piece) {
            const sum = layout.usedArea > 0 ? materialTotal * piece.area / layout.usedArea : 0;
            return {name: piece.name, quantity: piece.quantity, length: piece.width, height: piece.height, depth: 0, area: piece.area, size: Math.round(piece.width) + '×' + Math.round(piece.height) + ' мм', price: piece.quantity ? sum / piece.quantity : 0, currency: 'RUB', sum: sum};
        });
        const hardwareTotal = hardware.reduce(function (sum, item) { return sum + item.sum; }, 0);
        const calculatedServices = serviceRows(layout.usedArea, current.partitionCount);
        const servicesTotal = calculatedServices.reduce(function (sum, item) { return sum + item.sum; }, 0);
        const wasteCost = layout.sheetArea > 0 ? layout.wasteArea * materialTotal / layout.sheetArea : 0;
        const total = materialTotal + hardwareTotal + servicesTotal;
        currentCalculation = Object.assign({
            id: Date.now()
        }, inputs, {
            showerConfig: current,
            requirements: requirements,
            layout: layout,
            length: current.roomWidth,
            height: current.height,
            depth: current.depth,
            doors: current.partitionCount,
            panel: panel,
            products: products,
            hardware: hardware,
            services: calculatedServices,
            totals: {hardwareTotal: hardwareTotal, materialTotal: materialTotal, servicesTotal: servicesTotal, wasteCost: wasteCost, total: total, areaM2: layout.usedArea, sheets: layout.sheets, wasteArea: layout.wasteArea},
            total_amount: total,
            currency: 'RUB'
        });
        renderCalculation(currentCalculation);
    }

    document.getElementById('decor_input').addEventListener('change', function () { renderFormats(); refresh(); });
    formatSelect.addEventListener('change', toggleCustomFormat);
    const modelTrigger = document.getElementById('shower-model-trigger');
    const modelModal = document.getElementById('shower-model-modal');
    modelTrigger.addEventListener('click', openModelModal);
    modelTrigger.addEventListener('keydown', function (event) {
        if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            openModelModal();
        }
    });
    document.getElementById('shower-model-modal-close').addEventListener('click', closeModelModal);
    modelModal.addEventListener('click', function (event) {
        if (event.target === modelModal) closeModelModal();
    });
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && !modelModal.classList.contains('hidden')) closeModelModal();
    });

    document.getElementById('manufacturer_id').addEventListener('change', function () { renderFormats(); refresh(); });
    typeSelect.addEventListener('change', refresh);
    document.getElementById('supplier_id').addEventListener('change', function () { renderCollections(); refresh(); });
    document.getElementById('collection_id').addEventListener('change', refresh);
    document.getElementById('shower_height').addEventListener('input', function () {
        if (!fasciaHeightTouched) document.getElementById('shower_fascia_height').value = document.getElementById('shower_height').value;
    });
    document.getElementById('shower_fascia_height').addEventListener('input', function () { fasciaHeightTouched = true; });
    showerNode.addEventListener('input', refresh);
    showerNode.addEventListener('change', refresh);
    calculateBtn.addEventListener('click', function (event) {
        if (!showerSelected()) return;
        event.preventDefault();
        event.stopImmediatePropagation();
        calculateShower();
    }, true);

    renderFormats();
    renderCollections();
    refresh();
})();
