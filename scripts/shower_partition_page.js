(function () {
    'use strict';

    if (!window.ShowerPartitionCalculator || !window.CuttingOptimizer) {
        console.error('Модули расчёта душевых перегородок не загружены.');
        return;
    }

    const showerNode = document.getElementById('shower-config');
    const formatSelect = document.getElementById('panel_format_id');
    const roleSelections = {};

    function value(id) {
        return parseFloat(document.getElementById(id)?.value || '0') || 0;
    }

    function toRub(amount, currency) {
        const code = String(currency || 'RUB').toUpperCase();
        if (code === 'RUB') return Number(amount || 0);
        const row = currencyRates.find(function (rate) { return String(rate.code).toUpperCase() === code; });
        return row ? Number(amount || 0) * Number(row.rate_to_rub || 0) / Math.max(1, Number(row.nominal || 1)) : Number(amount || 0);
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
        if (!decor) {
            formatSelect.innerHTML = '<option value="">— Сначала выберите декор —</option>';
            return;
        }
        const matches = panels.filter(function (panel) {
            return Number(panel.manufacturer_id || 0) === Number(decor.manufacturer_id || 0)
                && String(panel.decor_number || '') === String(decor.decor_number || '')
                && String(panel.decor_name || '') === String(decor.decor_name || '');
        });
        matches.forEach(function (panel) {
            const option = document.createElement('option');
            option.value = panel.id;
            option.textContent = Math.round(Number(panel.height_mm || 0)) + '×' + Math.round(Number(panel.width_mm || 0)) + ' мм' + (panel.name ? ' · ' + panel.name : '');
            formatSelect.append(option);
        });
        formatSelect.value = matches.some(function (panel) { return String(panel.id) === String(previous); }) ? previous : String(decor.id);
    }

    function renderSchematic(current) {
        const count = Math.max(1, Math.round(current.partitionCount));
        const gap = 260 / (count + 1);
        let partitions = '';
        for (let index = 0; index < count; index += 1) {
            const x = 50 + gap * (index + 1);
            partitions += '<line x1="' + x + '" y1="55" x2="' + x + '" y2="175" stroke="#2563eb" stroke-width="7" stroke-linecap="round"/><circle cx="' + x + '" cy="55" r="5" fill="#e9164d"/>';
        }
        let rail = '';
        let label = 'Без верхней трубы';
        if (current.railRoute === 'straight') {
            rail = '<line x1="40" y1="48" x2="320" y2="48" stroke="#e9164d" stroke-width="5" stroke-linecap="round"/>';
            label = 'От стены до стены';
        } else if (current.railRoute === 'elbow') {
            rail = '<polyline points="40,48 315,48 315,184" fill="none" stroke="#e9164d" stroke-width="5" stroke-linejoin="round" stroke-linecap="round"/>';
            label = 'Г-образный маршрут';
        }
        document.getElementById('shower-schematic-svg').innerHTML = '<rect x="30" y="30" width="300" height="170" rx="8" fill="#fff" stroke="#cbd5e1"/><path d="M40 190V48H320" fill="none" stroke="#172033" stroke-width="9"/>' + partitions + rail + '<text x="180" y="213" text-anchor="middle" fill="#64748b" font-size="11">вид сверху · схема не в масштабе</text>';
        document.getElementById('shower-scheme-label').textContent = label;
        const pipeLength = current.railRoute === 'none' ? 0 : (current.roomWidth + (current.railRoute === 'elbow' ? current.depth : 0)) / 1000;
        document.getElementById('shower-schematic-stats').innerHTML =
            '<span>Перегородки <b>' + count + ' шт.</b></span>' +
            '<span>Глубина <b>' + formatter.format(current.depth) + ' мм</b></span>' +
            '<span>Ширина помещения <b>' + formatter.format(current.roomWidth) + ' мм</b></span>' +
            '<span>Верхняя труба <b>' + formatter.format(pipeLength) + ' м</b></span>';
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
        const panel = panels.find(function (row) { return String(row.id) === String(formatSelect.value); });
        if (!panel) {
            alert('Выберите декор и формат листа для расчёта раскроя.');
            formatSelect.focus();
            return;
        }
        const current = config();
        const layout = ShowerPartitionCalculator.estimatePanelLayout(current, panel, window.CuttingOptimizer);
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

    document.getElementById('decor_input').addEventListener('change', renderFormats);
    document.getElementById('manufacturer_id').addEventListener('change', renderFormats);
    typeSelect.addEventListener('change', refresh);
    document.getElementById('supplier_id').addEventListener('change', refresh);
    document.getElementById('collection_id').addEventListener('change', refresh);
    showerNode.addEventListener('input', refresh);
    showerNode.addEventListener('change', refresh);
    calculateBtn.addEventListener('click', function (event) {
        if (!showerSelected()) return;
        event.preventDefault();
        event.stopImmediatePropagation();
        calculateShower();
    }, true);

    renderFormats();
    refresh();
})();
