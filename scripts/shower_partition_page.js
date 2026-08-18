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

    function selectedDecorPhoto() {
        const decorId = document.getElementById('decor_input').value;
        const decor = panels.find(function (panel) { return String(panel.id) === String(decorId); });
        const photoPath = String(decor?.decor_photo_path || '').trim();
        return /^(javascript|data):/i.test(photoPath) ? '' : photoPath;
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
        const shownPartitions = Math.min(12, Math.max(1, Math.round(current.partitionCount)));
        const decorPhoto = selectedDecorPhoto();
        const panelFill = decorPhoto ? 'url(#hplTexture)' : 'url(#hplFace)';

        // Все три оси используют один масштаб. Изометрическое сокращение глубины
        // задаётся только проекцией, поэтому пропорции введённых размеров сохраняются.
        const projection = {x: 0.72, y: -0.42};
        const available = {width: 390, height: 235};
        const scale = Math.min(
            available.width / Math.max(1, current.roomWidth + current.depth * projection.x),
            available.height / Math.max(1, current.height + current.depth * Math.abs(projection.y))
        );
        const widthPx = current.roomWidth * scale;
        const depthX = current.depth * scale * projection.x;
        const depthY = current.depth * scale * projection.y;
        const origin = {x: (520 - widthPx - depthX) / 2, y: 305};
        const point = function (x, depth, height) {
            return {
                x: origin.x + x * scale + depth * scale * projection.x,
                y: origin.y + depth * scale * projection.y - height * scale
            };
        };
        const xy = function (item) { return item.x.toFixed(1) + ',' + item.y.toFixed(1); };
        const line = function (a, b, attrs) { return '<line x1="' + a.x.toFixed(1) + '" y1="' + a.y.toFixed(1) + '" x2="' + b.x.toFixed(1) + '" y2="' + b.y.toFixed(1) + '" ' + attrs + '/>'; };
        const polygon = function (items, attrs) { return '<polygon points="' + items.map(xy).join(' ') + '" ' + attrs + '/>'; };

        const floor = [point(0, 0, 0), point(current.roomWidth, 0, 0), point(current.roomWidth, current.depth, 0), point(0, current.depth, 0)];
        const backWall = [point(0, current.depth, 0), point(current.roomWidth, current.depth, 0), point(current.roomWidth, current.depth, current.height), point(0, current.depth, current.height)];
        let roomSvg = polygon(floor, 'fill="url(#floorGrid)" stroke="#b7c4d4"') + polygon(backWall, 'fill="url(#wallFace)" stroke="#b7c4d4"');
        if (current.layoutType === 'built_in' || current.layoutType === 'corner') {
            roomSvg += polygon([point(0, 0, 0), point(0, current.depth, 0), point(0, current.depth, current.height), point(0, 0, current.height)], 'fill="url(#sideWall)" stroke="#a9b8cb"');
        }
        if (current.layoutType === 'built_in') {
            roomSvg += polygon([point(current.roomWidth, 0, 0), point(current.roomWidth, current.depth, 0), point(current.roomWidth, current.depth, current.height), point(current.roomWidth, 0, current.height)], 'fill="url(#sideWall)" stroke="#a9b8cb"');
        }

        const panelPositions = [];
        for (let index = 0; index < shownPartitions; index += 1) {
            const fraction = current.layoutType === 'built_in'
                ? (index + 1) / (shownPartitions + 1)
                : (shownPartitions === 1 ? 0.5 : index / (shownPartitions - 1));
            panelPositions.push(current.roomWidth * fraction);
        }
        let panelsSvg = '';
        let hardwareSvg = '';
        panelPositions.forEach(function (x) {
            const panel = [point(x, 0, 0), point(x, current.depth, 0), point(x, current.depth, current.height), point(x, 0, current.height)];
            panelsSvg += '<g class="shower-model-shadow">' + polygon(panel, 'fill="' + panelFill + '" fill-opacity=".96" stroke="#315b8d" stroke-width="1.5"') + '</g>';

            if (current.floorMount === 'leg') {
                [current.depth * 0.12, current.depth * 0.88].forEach(function (depth) {
                    const foot = point(x, depth, 0);
                    hardwareSvg += '<g class="model-fitting"><rect x="' + (foot.x - 3).toFixed(1) + '" y="' + (foot.y - 9).toFixed(1) + '" width="6" height="9" rx="2"/><ellipse cx="' + foot.x.toFixed(1) + '" cy="' + foot.y.toFixed(1) + '" rx="7" ry="3"/></g>';
                });
            } else {
                hardwareSvg += line(panel[0], panel[1], 'class="model-profile"');
            }
            if (current.railRoute !== 'none') {
                const clamp = point(x, 0, current.height);
                hardwareSvg += '<g class="model-fitting"><circle cx="' + clamp.x.toFixed(1) + '" cy="' + clamp.y.toFixed(1) + '" r="6"/><circle class="model-fitting__cut" cx="' + clamp.x.toFixed(1) + '" cy="' + clamp.y.toFixed(1) + '" r="2.5"/></g>';
            }
        });

        let frontSvg = '';
        if (current.variant === 'fascia') {
            const fasciaWidth = Math.min(current.roomWidth, current.fasciaWidth);
            const start = (current.roomWidth - fasciaWidth) / 2;
            frontSvg = polygon([point(start, 0, 0), point(start + fasciaWidth, 0, 0), point(start + fasciaWidth, 0, current.fasciaHeight), point(start, 0, current.fasciaHeight)], 'fill="' + panelFill + '" stroke="#315b8d" stroke-width="1.5"');
        } else if (current.variant === 'doors') {
            const count = Math.min(6, Math.max(1, Math.round(current.doorCount)));
            const totalWidth = Math.min(current.roomWidth * 0.94, current.doorWidth * count);
            const start = (current.roomWidth - totalWidth) / 2;
            for (let door = 0; door < count; door += 1) {
                const left = start + totalWidth * door / count;
                const right = start + totalWidth * (door + 1) / count;
                const doorHeight = Math.min(current.height, current.doorHeight);
                frontSvg += polygon([point(left, 0, 0), point(right, 0, 0), point(right, 0, doorHeight), point(left, 0, doorHeight)], 'fill="' + panelFill + '" stroke="#315b8d" stroke-width="1.4"');
                const handle = point(right - (right - left) * 0.16, 0, doorHeight * 0.48);
                hardwareSvg += '<circle class="model-handle" cx="' + handle.x.toFixed(1) + '" cy="' + handle.y.toFixed(1) + '" r="3.5"/>';
            }
        }

        const railStyle = current.topSupport === 'aluminium_profile' ? 'model-rail model-rail--profile' : 'model-rail';
        let pipe = '';
        const frontLeft = point(0, 0, current.height);
        const frontRight = point(current.roomWidth, 0, current.height);
        if (current.railRoute === 'straight') pipe = line(frontLeft, frontRight, 'class="' + railStyle + '"');
        if (current.railRoute === 'elbow') pipe = line(frontLeft, frontRight, 'class="' + railStyle + '"') + line(frontRight, point(current.roomWidth, current.depth, current.height), 'class="' + railStyle + '"');
        if (current.railRoute === 'u_shape') pipe = line(point(0, current.depth, current.height), frontLeft, 'class="' + railStyle + '"') + line(frontLeft, frontRight, 'class="' + railStyle + '"') + line(frontRight, point(current.roomWidth, current.depth, current.height), 'class="' + railStyle + '"');

        const dimensionStyle = 'stroke="#64748b" stroke-width="1"';
        const widthA = point(0, 0, 0); const widthB = point(current.roomWidth, 0, 0);
        const depthB = point(current.roomWidth, current.depth, 0);
        const heightB = point(current.roomWidth, 0, current.height);
        const dimensions = line({x: widthA.x, y: widthA.y + 18}, {x: widthB.x, y: widthB.y + 18}, dimensionStyle) +
            '<text x="' + ((widthA.x + widthB.x) / 2).toFixed(1) + '" y="' + (widthA.y + 34).toFixed(1) + '" class="model-dimension">' + Math.round(current.roomWidth) + ' мм</text>' +
            line({x: widthB.x + 12, y: widthB.y + 9}, {x: depthB.x + 12, y: depthB.y + 9}, dimensionStyle) +
            '<text x="' + ((widthB.x + depthB.x) / 2 + 18).toFixed(1) + '" y="' + ((widthB.y + depthB.y) / 2 + 10).toFixed(1) + '" class="model-dimension">' + Math.round(current.depth) + ' мм</text>' +
            line({x: widthB.x + 13, y: widthB.y}, {x: heightB.x + 13, y: heightB.y}, dimensionStyle) +
            '<text x="' + (widthB.x + 21).toFixed(1) + '" y="' + ((widthB.y + heightB.y) / 2).toFixed(1) + '" class="model-dimension model-dimension--vertical">' + Math.round(current.height) + ' мм</text>';

        const textureDefinition = decorPhoto
            ? '<pattern id="hplTexture" patternUnits="userSpaceOnUse" width="220" height="220"><rect width="220" height="220" fill="#d9dde3"/><image href="' + escapeHtml(decorPhoto) + '" width="220" height="220" preserveAspectRatio="xMidYMid slice"/></pattern>'
            : '';
        const svg = document.getElementById('shower-schematic-svg');
        svg.innerHTML = '<defs><linearGradient id="hplFace" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#f7f8fa"/><stop offset=".55" stop-color="#d9dde2"/><stop offset="1" stop-color="#b9c0c9"/></linearGradient><linearGradient id="wallFace" x1="0" y1="0" x2="0" y2="1"><stop stop-color="#f8fafc"/><stop offset="1" stop-color="#e7edf4"/></linearGradient><linearGradient id="sideWall"><stop stop-color="#edf2f7"/><stop offset="1" stop-color="#dbe4ee"/></linearGradient>' + textureDefinition + '<pattern id="floorGrid" width="28" height="28" patternUnits="userSpaceOnUse"><path d="M28 0H0V28" fill="none" stroke="#d6e0eb" stroke-width="1"/></pattern></defs><style>.model-fitting{fill:#697788;stroke:#344256;stroke-width:1}.model-fitting__cut{fill:#eef2f7;stroke:none}.model-profile{stroke:#7a8797;stroke-width:6;stroke-linecap:round}.model-rail{stroke:#657486;stroke-width:8;stroke-linecap:round;filter:drop-shadow(0 3px 2px rgba(15,23,42,.25))}.model-rail--profile{stroke:#9ba8b7;stroke-width:10}.model-handle{fill:#606f80;stroke:#f8fafc;stroke-width:1}.model-dimension{fill:#52637a;font:600 10px sans-serif;text-anchor:middle}.model-dimension--vertical{writing-mode:vertical-rl}</style>' + roomSvg + panelsSvg + frontSvg + pipe + hardwareSvg + dimensions;
        document.getElementById('shower-scheme-label').textContent = current.layoutLabel;
        document.getElementById('shower-schematic-stats').innerHTML =
            '<span>Тип <b>' + escapeHtml(current.layoutLabel) + '</b></span>' +
            '<span>Габариты <b>' + Math.round(current.roomWidth) + '×' + Math.round(current.depth) + '×' + Math.round(current.height) + ' мм</b></span>' +
            '<span>Кабины <b>' + current.sectionCount + ' шт.</b></span>' +
            '<span>HPL-панели <b>' + current.partitionCount + ' шт.</b></span>';
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
            select.addEventListener('change', function () { roleSelections[select.dataset.showerRole] = select.value; renderSchematic(config()); });
        });
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
        const requirements = ShowerPartitionCalculator.buildRequirements(current);
        renderRoles(requirements);
        renderSchematic(current);
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
