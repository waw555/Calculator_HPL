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

    function partitionVariant() {
        const name = (typeSelect.selectedOptions[0]?.textContent || '').toLocaleLowerCase('ru-RU');
        if (name.includes('двер')) return 'doors';
        if (name.includes('перемыч')) return 'fascia';
        return 'open';
    }

    function config() {
        return ShowerPartitionCalculator.normalizedConfig({
            layoutType: document.getElementById('shower_layout_type').value,
            sectionCount: value('shower_partition_count'),
            partitionCount: value('shower_panel_count'),
            roomWidth: value('shower_room_width'),
            depth: value('shower_depth'),
            height: value('shower_height'),
            variant: partitionVariant(),
            fasciaWidth: value('shower_fascia_width'),
            fasciaHeight: value('shower_fascia_height'),
            doorCount: value('shower_door_count'),
            doorWidth: value('shower_door_width'),
            doorHeight: value('shower_door_height'),
            floorMount: document.getElementById('shower_floor_mount').value,
            wallMount: document.getElementById('shower_wall_mount').value,
            ceilingMount: document.getElementById('shower_ceiling_mount').value,
            topSupport: document.getElementById('shower_top_support').value,
            angleSides: value('shower_angle_sides'),
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
        let firstMatchingValue = '';
        Array.from(select.options).forEach(function (option) {
            const matches = supplierId > 0 && Number(option.dataset.supplier || 0) === supplierId;
            option.hidden = !matches;
            if (matches && !firstMatchingValue) firstMatchingValue = option.value;
        });
        if (!supplierId || !firstMatchingValue) {
            select.value = '';
            field.classList.add('hidden');
        } else {
            if (!select.value || select.selectedOptions[0]?.hidden) select.value = firstMatchingValue;
            field.classList.remove('hidden');
        }
    }

    function hardwareChoice(requirement) {
        const preferredFilters = {
            supplierId: document.getElementById('supplier_id').value,
            collectionId: document.getElementById('collection_id').value
        };
        const matches = ShowerPartitionCalculator.matchingFurniture(requirement, furnitureCatalog);
        const preferredMatches = ShowerPartitionCalculator.matchingFurniture(requirement, furnitureCatalog, preferredFilters);
        let selected = matches.find(function (item) { return String(item.id) === String(roleSelections[requirement.role] || ''); });
        if (!selected) selected = preferredMatches.find(function (item) { return item.matchScore > 0; });
        if (!selected) selected = matches.find(function (item) { return item.matchScore > 0; });
        if (selected) roleSelections[requirement.role] = String(selected.id);
        return {selected: selected, matches: matches};
    }

    function refresh() {
        const active = showerSelected();
        showerNode.classList.toggle('hidden', !active);
        if (!active) return;
        paramsNode.innerHTML = '';
        let current = config();
        const sectionInput = document.getElementById('shower_partition_count');
        const minimumSections = current.layoutType === 'built_in' ? 2 : 1;
        sectionInput.min = String(minimumSections);
        if (current.sectionCount < minimumSections) {
            sectionInput.value = String(minimumSections);
            current = config();
        }
        const variant = current.variant;
        document.getElementById('shower-fascia-fields').classList.toggle('field-hidden', variant !== 'fascia');
        ['shower-door-count-fields', 'shower-door-width-fields', 'shower-door-height-fields'].forEach(function (id) {
            document.getElementById(id).classList.toggle('field-hidden', variant !== 'doors');
        });
        document.getElementById('shower-top-support-fields').classList.toggle('field-hidden', current.ceilingMount !== 'none');
        const angles = current.floorMount === 'angle' || current.wallMount === 'angle' || current.ceilingMount === 'angle';
        document.getElementById('shower-angle-fields').classList.toggle('field-hidden', !angles);
    }

    function resetRoleSelections() {
        Object.keys(roleSelections).forEach(function (role) { delete roleSelections[role]; });
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

    function serviceRows(layout, panel) {
        const volumes = ShowerPartitionCalculator.serviceVolumes(layout, panel);
        return services.filter(function (service) {
            return String(service.partition_type_id) === String(typeSelect.value);
        }).map(function (service) {
            const name = String(service.name || '');
            let volume = 1;
            let description = 'Базовая услуга типа перегородки';
            if (/торцеван/i.test(name)) { volume = volumes.edging; description = 'Периметр всех использованных целых панелей'; }
            else if (/раскро/i.test(name)) { volume = volumes.cutting; description = 'Суммарный периметр каждого изделия с учётом количества'; }
            else if (/фаск/i.test(name)) { volume = volumes.bevel; description = 'Две вертикальные стороны каждого изделия с учётом количества'; }
            const price = toRub(service.price, service.currency);
            return {id: service.id, name: service.name, description: description, volume: volume, unit: service.unit, price: price, currency: 'RUB', sum: volume * price};
        });
    }

    function calculateShower() {
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
            const choice = hardwareChoice(requirement);
            const item = choice.selected;
            const price = toRub(item?.price, item?.currency);
            return {
                id: item?.id || '',
                role: requirement.role,
                roleLabel: requirement.label,
                name: item?.material_name || requirement.label,
                category: [requirement.label, item?.category_name, item ? '' : 'Требуется подобрать товар'].filter(Boolean).join(' · '),
                quantity: requirement.quantity,
                unit: item?.unit || requirement.unit,
                price: price,
                currency: 'RUB',
                sum: requirement.quantity * price,
                selected: Boolean(item),
                options: choice.matches.map(function (option) {
                    const meta = [option.category_name, option.supplier_name, option.collection_name].filter(Boolean).join(' · ');
                    return {id: option.id, name: option.material_name, label: option.material_name + (meta ? ' — ' + meta : ''), unit: option.unit || requirement.unit, price: toRub(option.price, option.currency)};
                })
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
        const calculatedServices = serviceRows(layout, panel);
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
            doors: current.sectionCount,
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
    document.getElementById('supplier_id').addEventListener('change', function () {
        resetRoleSelections();
        renderCollections();
        refresh();
    });
    document.getElementById('collection_id').addEventListener('change', function () {
        resetRoleSelections();
        refresh();
    });
    document.getElementById('shower_ceiling_mount').addEventListener('change', refresh);
    calculateBtn.addEventListener('click', function (event) {
        if (!showerSelected()) return;
        event.stopImmediatePropagation();
        calculateShower();
    }, true);
    renderCollections();
    refresh();
})();
