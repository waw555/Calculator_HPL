<?php

function table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare('SHOW TABLES LIKE :table_name');
    $stmt->execute(['table_name' => $table]);
    return (bool)$stmt->fetchColumn();
}

function column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare('SHOW COLUMNS FROM `' . str_replace('`', '``', $table) . '` LIKE :column_name');
    $stmt->execute(['column_name' => $column]);
    return (bool)$stmt->fetchColumn();
}

function add_column_if_missing(PDO $pdo, string $table, string $column, string $definition): void
{
    if (!column_exists($pdo, $table, $column)) {
        $pdo->exec('ALTER TABLE `' . str_replace('`', '``', $table) . '` ADD COLUMN ' . $definition);
    }
}

function ensure_currencies_table(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS currencies (
        id INT AUTO_INCREMENT PRIMARY KEY,
        code VARCHAR(8) NOT NULL UNIQUE,
        name VARCHAR(120) NOT NULL,
        nominal INT NOT NULL DEFAULT 1,
        rate_to_rub DECIMAL(16,6) NOT NULL DEFAULT 1,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        updated_at DATETIME NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    add_column_if_missing($pdo, 'currencies', 'nominal', 'nominal INT NOT NULL DEFAULT 1');
    add_column_if_missing($pdo, 'currencies', 'rate_to_rub', 'rate_to_rub DECIMAL(16,6) NOT NULL DEFAULT 1');
    add_column_if_missing($pdo, 'currencies', 'is_active', 'is_active TINYINT(1) NOT NULL DEFAULT 1');
    add_column_if_missing($pdo, 'currencies', 'updated_at', 'updated_at DATETIME NULL');

    $stmt = $pdo->prepare('INSERT IGNORE INTO currencies (code, name, nominal, rate_to_rub, is_active, updated_at) VALUES (:code, :name, 1, 1, 1, NOW())');
    $stmt->execute(['code' => 'RUB', 'name' => 'Российский рубль']);
}

function cbr_currency_rates_are_stale(PDO $pdo, int $maxAgeSeconds = 3600): bool
{
    ensure_currencies_table($pdo);

    $stmt = $pdo->query("SELECT COUNT(*) AS rate_count, MIN(updated_at) AS oldest_update FROM currencies WHERE code IN ('EUR', 'USD')");
    $state = $stmt ? $stmt->fetch() : false;
    if (!$state || (int)$state['rate_count'] < 2 || empty($state['oldest_update'])) {
        return true;
    }

    $updatedAt = strtotime((string)$state['oldest_update']);
    return $updatedAt === false || $updatedAt < time() - $maxAgeSeconds;
}

function ensure_suppliers_table(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS suppliers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        company_name VARCHAR(160) NOT NULL,
        products TEXT NULL,
        address TEXT NULL,
        website VARCHAR(255) NULL,
        contacts TEXT NULL,
        note TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    add_column_if_missing($pdo, 'suppliers', 'note', 'note TEXT NULL');
}

function ensure_supplier_products_table(PDO $pdo): void
{
    ensure_suppliers_table($pdo);

    $pdo->exec("CREATE TABLE IF NOT EXISTS supplier_products (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(160) NOT NULL UNIQUE,
        note TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function ensure_units_table(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS measurement_units (
        id INT AUTO_INCREMENT PRIMARY KEY,
        full_name VARCHAR(120) NOT NULL,
        short_name VARCHAR(40) NOT NULL,
        note TEXT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    add_column_if_missing($pdo, 'measurement_units', 'note', 'note TEXT NULL');
}

function refresh_cbr_currency_rates(PDO $pdo, array &$errors): bool
{
    ensure_currencies_table($pdo);

    $xmlString = @file_get_contents('https://www.cbr.ru/scripts/XML_daily.asp');
    if ($xmlString === false) {
        $errors[] = 'Не удалось получить курсы валют с сайта cbr.ru.';
        return false;
    }

    if (!function_exists('simplexml_load_string')) {
        $errors[] = 'На сервере недоступно расширение SimpleXML для разбора курсов cbr.ru.';
        return false;
    }

    $xml = @simplexml_load_string($xmlString);
    if ($xml === false) {
        $errors[] = 'Не удалось разобрать ответ cbr.ru с курсами валют.';
        return false;
    }

    $stmt = $pdo->prepare('INSERT INTO currencies (code, name, nominal, rate_to_rub, updated_at)
        VALUES (:code, :name, :nominal, :rate_to_rub, NOW())
        ON DUPLICATE KEY UPDATE name = VALUES(name), nominal = VALUES(nominal), rate_to_rub = VALUES(rate_to_rub), updated_at = NOW()');

    foreach ($xml->Valute as $valute) {
        $code = strtoupper(trim((string)$valute->CharCode));
        $name = trim((string)$valute->Name);
        $nominal = max(1, (int)$valute->Nominal);
        $value = (float)str_replace(',', '.', (string)$valute->Value);
        if ($code === '' || $value <= 0) {
            continue;
        }

        $stmt->execute([
            'code' => $code,
            'name' => $name === '' ? $code : $name,
            'nominal' => $nominal,
            'rate_to_rub' => round($value / $nominal, 6),
        ]);
    }

    $rub = $pdo->prepare('UPDATE currencies SET nominal = 1, rate_to_rub = 1, updated_at = NOW() WHERE code = :code');
    $rub->execute(['code' => 'RUB']);

    return true;
}

function ensure_manufacturers_table(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS manufacturers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        full_name VARCHAR(20) NOT NULL,
        country_origin VARCHAR(20) NOT NULL,
        logo_path VARCHAR(255) NULL,
        note TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    add_column_if_missing($pdo, 'manufacturers', 'note', 'note TEXT NULL');
}

function ensure_panel_formats_table(PDO $pdo): void
{
    ensure_manufacturers_table($pdo);
    ensure_currencies_table($pdo);
    ensure_embossings_table($pdo);

    $pdo->exec("CREATE TABLE IF NOT EXISTS panel_formats (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(120) NOT NULL,
        width_mm INT NOT NULL,
        height_mm INT NOT NULL,
        thickness_mm DECIMAL(6,2) NULL,
        description TEXT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    add_column_if_missing($pdo, 'panel_formats', 'nomenclature',  'nomenclature VARCHAR(120) NULL');
    add_column_if_missing($pdo, 'panel_formats', 'manufacturer_id','manufacturer_id INT NULL');
    add_column_if_missing($pdo, 'panel_formats', 'decor_number',  'decor_number VARCHAR(6) NULL');
    add_column_if_missing($pdo, 'panel_formats', 'decor_name',    'decor_name VARCHAR(120) NULL');
    add_column_if_missing($pdo, 'panel_formats', 'decor_direction',"decor_direction ENUM('vertical','horizontal','none') NOT NULL DEFAULT 'none'");
    add_column_if_missing($pdo, 'panel_formats', 'is_stock_decor','is_stock_decor TINYINT(1) NOT NULL DEFAULT 0');
    add_column_if_missing($pdo, 'panel_formats', 'is_stock_program','is_stock_program TINYINT(1) NOT NULL DEFAULT 0');
    add_column_if_missing($pdo, 'panel_formats', 'decor_photo_path','decor_photo_path VARCHAR(255) NULL');
    add_column_if_missing($pdo, 'panel_formats', 'price_per_m2',  'price_per_m2 DECIMAL(12,2) NULL');
    add_column_if_missing($pdo, 'panel_formats', 'price_per_sheet','price_per_sheet DECIMAL(12,2) NULL');
    add_column_if_missing($pdo, 'panel_formats', 'currency',      "currency VARCHAR(8) NOT NULL DEFAULT 'RUB'");
    add_column_if_missing($pdo, 'panel_formats', 'cost',          'cost DECIMAL(12,2) NULL');
    add_column_if_missing($pdo, 'panel_formats', 'cost_per_sheet','cost_per_sheet DECIMAL(12,2) NULL');
    add_column_if_missing($pdo, 'panel_formats', 'markup',        'markup DECIMAL(12,2) NULL');
    add_column_if_missing($pdo, 'panel_formats', 'weight',        'weight DECIMAL(12,6) NULL');
    add_column_if_missing($pdo, 'panel_formats', 'weight_per_m2', 'weight_per_m2 DECIMAL(12,6) NULL');
    add_column_if_missing($pdo, 'panel_formats', 'volume_m2',     'volume_m2 DECIMAL(12,6) NULL');
    add_column_if_missing($pdo, 'panel_formats', 'embossing_id',  'embossing_id INT NULL');
    // Устаревшие колонки — оставляем для обратной совместимости
    add_column_if_missing($pdo, 'panel_formats', 'volume',        'volume DECIMAL(12,4) NULL');
    add_column_if_missing($pdo, 'panel_formats', 'panel_weight',  'panel_weight DECIMAL(12,2) NULL');
}

function ensure_price_list_table(PDO $pdo): void
{
    ensure_panel_formats_table($pdo);
    ensure_suppliers_table($pdo);
    ensure_units_table($pdo);
    ensure_currencies_table($pdo);
    ensure_furniture_categories_table($pdo);

    $pdo->exec("CREATE TABLE IF NOT EXISTS price_list (
        id INT AUTO_INCREMENT PRIMARY KEY,
        panel_format_id INT NULL,
        material_name VARCHAR(160) NOT NULL,
        unit VARCHAR(40) NOT NULL DEFAULT 'шт.',
        price DECIMAL(12,2) NOT NULL,
        currency VARCHAR(8) NOT NULL DEFAULT 'RUB',
        valid_from DATE NULL,
        note TEXT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT fk_price_list_panel_format
            FOREIGN KEY (panel_format_id) REFERENCES panel_formats(id)
            ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    add_column_if_missing($pdo, 'price_list', 'supplier_id', 'supplier_id INT NULL');
    add_column_if_missing($pdo, 'price_list', 'category_id', 'category_id INT NULL');
    add_column_if_missing($pdo, 'price_list', 'multiplicity', 'multiplicity DECIMAL(12,3) NOT NULL DEFAULT 1');
    add_column_if_missing($pdo, 'price_list', 'amount', 'amount DECIMAL(12,3) NOT NULL DEFAULT 1');
    add_column_if_missing($pdo, 'price_list', 'photo_path', 'photo_path VARCHAR(255) NULL');
    add_column_if_missing($pdo, 'price_list', 'is_stock_program', 'is_stock_program TINYINT(1) NOT NULL DEFAULT 0');
    add_column_if_missing($pdo, 'price_list', 'collection_id', 'collection_id INT NULL');
}

function ensure_furniture_collections_table(PDO $pdo): void
{
    ensure_suppliers_table($pdo);

    $pdo->exec("CREATE TABLE IF NOT EXISTS furniture_collections (
        id INT AUTO_INCREMENT PRIMARY KEY,
        supplier_id INT NULL,
        name VARCHAR(160) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT fk_fc_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}


function ensure_services_table(PDO $pdo): void
{
    ensure_units_table($pdo);
    ensure_currencies_table($pdo);

    $pdo->exec("CREATE TABLE IF NOT EXISTS services (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(160) NOT NULL,
        unit VARCHAR(40) NOT NULL DEFAULT 'усл.',
        price DECIMAL(12,2) NOT NULL DEFAULT 0,
        currency VARCHAR(8) NOT NULL DEFAULT 'RUB',
        photo_path VARCHAR(255) NULL,
        note TEXT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    add_column_if_missing($pdo, 'services', 'currency', "currency VARCHAR(8) NOT NULL DEFAULT 'RUB'");
    add_column_if_missing($pdo, 'services', 'photo_path', 'photo_path VARCHAR(255) NULL');
    add_column_if_missing($pdo, 'services', 'nomenclature', 'nomenclature VARCHAR(160) NULL');
    add_column_if_missing($pdo, 'services', 'thickness_id', 'thickness_id INT NULL');
    add_column_if_missing($pdo, 'services', 'category_id', 'category_id INT NULL');
    add_column_if_missing($pdo, 'services', 'h_size', "h_size VARCHAR(16) NULL DEFAULT 'no'");
    add_column_if_missing($pdo, 'services', 'd_size', "d_size VARCHAR(16) NULL DEFAULT 'no'");
    add_column_if_missing($pdo, 'services', 'step_mm', "step_mm VARCHAR(8) NULL DEFAULT 'no'");
}

function ensure_furniture_categories_table(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS furniture_categories (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(160) NOT NULL,
        note TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function ensure_furniture_kits_table(PDO $pdo): void
{
    ensure_price_list_table($pdo);

    $pdo->exec("CREATE TABLE IF NOT EXISTS furniture_kits (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(160) NOT NULL,
        collection_id INT NULL,
        note TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT fk_fkit_collection FOREIGN KEY (collection_id) REFERENCES furniture_collections(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    add_column_if_missing($pdo, 'furniture_kits', 'collection_id', 'collection_id INT NULL');

    $pdo->exec("CREATE TABLE IF NOT EXISTS furniture_kit_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        kit_id INT NOT NULL,
        furniture_id INT NOT NULL,
        quantity DECIMAL(12,3) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT fk_furniture_kit_items_kit FOREIGN KEY (kit_id) REFERENCES furniture_kits(id) ON DELETE CASCADE,
        CONSTRAINT fk_furniture_kit_items_furniture FOREIGN KEY (furniture_id) REFERENCES price_list(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function ensure_partition_types_table(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS partition_types (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(160) NOT NULL,
        drawing_path VARCHAR(255) NULL,
        photo_path VARCHAR(255) NULL,
        description TEXT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function ensure_partition_type_kits_table(PDO $pdo): void
{
    ensure_partition_types_table($pdo);
    ensure_furniture_kits_table($pdo);

    $pdo->exec("CREATE TABLE IF NOT EXISTS partition_type_kits (
        id INT AUTO_INCREMENT PRIMARY KEY,
        partition_type_id INT NOT NULL,
        kit_id INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_type_kit (partition_type_id, kit_id),
        CONSTRAINT fk_ptk_type FOREIGN KEY (partition_type_id) REFERENCES partition_types(id) ON DELETE CASCADE,
        CONSTRAINT fk_ptk_kit FOREIGN KEY (kit_id) REFERENCES furniture_kits(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function ensure_parameters_table(PDO $pdo): void
{
    ensure_units_table($pdo);

    $pdo->exec("CREATE TABLE IF NOT EXISTS calculation_parameters (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(160) NOT NULL,
        default_value VARCHAR(120) NULL,
        unit_id INT NULL,
        note TEXT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    add_column_if_missing($pdo, 'calculation_parameters', 'default_value', 'default_value VARCHAR(120) NULL');
    add_column_if_missing($pdo, 'calculation_parameters', 'unit_id', 'unit_id INT NULL');
    add_column_if_missing($pdo, 'calculation_parameters', 'note', 'note TEXT NULL');
    add_column_if_missing($pdo, 'calculation_parameters', 'is_active', 'is_active TINYINT(1) NOT NULL DEFAULT 1');
}

function ensure_calculator_tables(PDO $pdo): void
{
    ensure_partition_types_table($pdo);
    ensure_parameters_table($pdo);
    ensure_price_types_table($pdo);
    ensure_manufacturers_table($pdo);
    ensure_panel_formats_table($pdo);
    ensure_furniture_kits_table($pdo);
    ensure_furniture_collections_table($pdo);
    ensure_services_table($pdo);
    ensure_partition_type_kits_table($pdo);

    $pdo->exec("CREATE TABLE IF NOT EXISTS partition_type_parameters (
        id INT AUTO_INCREMENT PRIMARY KEY,
        partition_type_id INT NOT NULL,
        parameter_id INT NOT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        is_required TINYINT(1) NOT NULL DEFAULT 0,
        default_value_override VARCHAR(120) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_partition_parameter (partition_type_id, parameter_id),
        CONSTRAINT fk_partition_type_parameters_type FOREIGN KEY (partition_type_id) REFERENCES partition_types(id) ON DELETE CASCADE,
        CONSTRAINT fk_partition_type_parameters_parameter FOREIGN KEY (parameter_id) REFERENCES calculation_parameters(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS saved_calculations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        object_name VARCHAR(180) NULL,
        partition_identifier VARCHAR(120) NULL,
        title VARCHAR(220) NOT NULL,
        total_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
        currency VARCHAR(8) NOT NULL DEFAULT 'RUB',
        payload_json LONGTEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    add_column_if_missing($pdo, 'saved_calculations', 'object_name', 'object_name VARCHAR(180) NULL');
    add_column_if_missing($pdo, 'saved_calculations', 'partition_identifier', 'partition_identifier VARCHAR(120) NULL');
    add_column_if_missing($pdo, 'saved_calculations', 'total_amount', "total_amount DECIMAL(14,2) NOT NULL DEFAULT 0");
    add_column_if_missing($pdo, 'saved_calculations', 'currency', "currency VARCHAR(8) NOT NULL DEFAULT 'RUB'");
}

function ensure_organization_table(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS organization_settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        full_name VARCHAR(255) NULL,
        short_name VARCHAR(160) NULL,
        address VARCHAR(255) NULL,
        city VARCHAR(120) NULL,
        region VARCHAR(120) NULL,
        postal_code VARCHAR(40) NULL,
        phone VARCHAR(80) NULL,
        website VARCHAR(160) NULL,
        email VARCHAR(160) NULL,
        note TEXT NULL,
        logo_path VARCHAR(255) NULL,
        inn VARCHAR(40) NULL,
        ogrn VARCHAR(40) NULL,
        bik VARCHAR(40) NULL,
        bank_name VARCHAR(180) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    foreach ([
        'full_name' => 'full_name VARCHAR(255) NULL',
        'short_name' => 'short_name VARCHAR(160) NULL',
        'address' => 'address VARCHAR(255) NULL',
        'city' => 'city VARCHAR(120) NULL',
        'region' => 'region VARCHAR(120) NULL',
        'postal_code' => 'postal_code VARCHAR(40) NULL',
        'phone' => 'phone VARCHAR(80) NULL',
        'website' => 'website VARCHAR(160) NULL',
        'email' => 'email VARCHAR(160) NULL',
        'note' => 'note TEXT NULL',
        'logo_path' => 'logo_path VARCHAR(255) NULL',
        'inn' => 'inn VARCHAR(40) NULL',
        'ogrn' => 'ogrn VARCHAR(40) NULL',
        'bik' => 'bik VARCHAR(40) NULL',
        'bank_name' => 'bank_name VARCHAR(180) NULL',
    ] as $column => $definition) {
        add_column_if_missing($pdo, 'organization_settings', $column, $definition);
    }

    $pdo->exec('INSERT INTO organization_settings (id) SELECT 1 WHERE NOT EXISTS (SELECT 1 FROM organization_settings WHERE id = 1)');
}

function ensure_partition_constructor_table(PDO $pdo): void
{
    ensure_calculator_tables($pdo);

    $pdo->exec("CREATE TABLE IF NOT EXISTS partition_constructor_rules (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(180) NOT NULL,
        partition_type_id INT NULL,
        manufacturer_id INT NULL,
        condition_parameter VARCHAR(160) NULL,
        condition_operator VARCHAR(20) NOT NULL DEFAULT '=',
        condition_value VARCHAR(160) NULL,
        action_target VARCHAR(160) NOT NULL,
        action_formula TEXT NULL,
        note TEXT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function upload_file(string $fieldName, string $subdir, array &$errors, ?string $currentPath = null): ?string
{
    if (!isset($_FILES[$fieldName]) || ($_FILES[$fieldName]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return $currentPath;
    }
    if ($_FILES[$fieldName]['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'Не удалось загрузить файл.';
        return $currentPath;
    }
    if (($_FILES[$fieldName]['size'] ?? 0) > 100 * 1024 * 1024) {
        $errors[] = 'Максимальный размер файла — 100 мб.';
        return $currentPath;
    }
    $originalName = (string)($_FILES[$fieldName]['name'] ?? '');
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $allowed = ['jpg', 'jpeg', 'png', 'webp', 'tif', 'tiff', 'pdf', 'dwg', 'dxf'];
    if (!in_array($extension, $allowed, true)) {
        $errors[] = 'Поддерживаются файлы JPG, PNG, TIFF, WEBP, PDF, DWG и DXF.';
        return $currentPath;
    }
    $relativeDir = 'uploads/' . trim($subdir, '/');
    $deployBase = '/home/waw555/www/hpl';
    $baseDir = (is_dir($deployBase) && is_writable($deployBase)) ? $deployBase : dirname(__DIR__);
    $absoluteDir = $baseDir . '/' . $relativeDir;
    if (!is_dir($absoluteDir)) {
        if (!@mkdir($absoluteDir, 0777, true)) {
            $errors[] = 'Не удалось создать директорию для загрузки файлов.';
            return $currentPath;
        }
        @chmod($absoluteDir, 0755);
    }
    $relativePath = $relativeDir . '/' . bin2hex(random_bytes(12)) . '.' . $extension;
    $absolutePath = $baseDir . '/' . $relativePath;
    if (!@move_uploaded_file($_FILES[$fieldName]['tmp_name'], $absolutePath)) {
        $errors[] = 'Не удалось сохранить файл. Проверьте права доступа к папке uploads.';
        return $currentPath;
    }
    @chmod($absolutePath, 0644);
    return $relativePath;
}

function upload_image(string $fieldName, string $subdir, array &$errors, ?string $currentPath = null, ?string $customName = null): ?string
{
    if (!isset($_FILES[$fieldName]) || ($_FILES[$fieldName]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return $currentPath;
    }

    if ($_FILES[$fieldName]['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'Не удалось загрузить изображение.';
        return $currentPath;
    }

    if (($_FILES[$fieldName]['size'] ?? 0) > 100 * 1024 * 1024) {
        $errors[] = 'Максимальный размер изображения — 100 мб.';
        return $currentPath;
    }

    $tmpPath = $_FILES[$fieldName]['tmp_name'];
    $imageInfo = @getimagesize($tmpPath);
    if ($imageInfo === false) {
        $errors[] = 'Файл должен быть изображением.';
        return $currentPath;
    }

    $extensions = [
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG => 'png',
        IMAGETYPE_WEBP => 'webp',
        IMAGETYPE_TIFF_II => 'tiff',
        IMAGETYPE_TIFF_MM => 'tiff',
    ];
    $imageType = $imageInfo[2] ?? null;
    if (!isset($extensions[$imageType])) {
        $errors[] = 'Поддерживаются только изображения JPG, PNG, TIFF и WEBP.';
        return $currentPath;
    }

    $relativeDir = 'uploads/' . trim($subdir, '/');
    $deployBase = '/home/waw555/www/hpl';
    $baseDir = (is_dir($deployBase) && is_writable($deployBase)) ? $deployBase : dirname(__DIR__);
    $absoluteDir = $baseDir . '/' . $relativeDir;
    if (!is_dir($absoluteDir)) {
        if (!@mkdir($absoluteDir, 0777, true)) {
            $errors[] = 'Не удалось создать директорию для загрузки изображений.';
            return $currentPath;
        }
        @chmod($absoluteDir, 0755);
    }

    $ext = $extensions[$imageType];
    if ($customName !== null && $customName !== '') {
        // sanitize: only letters, digits, underscore, hyphen
        $safe = preg_replace('/[^\w\-]/u', '_', $customName);
        $fileName = $safe . '.' . $ext;
    } else {
        $fileName = bin2hex(random_bytes(12)) . '.' . $ext;
    }
    $relativePath = $relativeDir . '/' . $fileName;
    $absolutePath = $baseDir . '/' . $relativePath;
    if (!@move_uploaded_file($tmpPath, $absolutePath)) {
        $errors[] = 'Не удалось сохранить изображение. Проверьте права доступа к папке uploads.';
        return $currentPath;
    }
    @chmod($absolutePath, 0644);

    return $relativePath;
}

function ensure_price_types_table(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS price_types (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(120) NOT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        sort_order INT NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    add_column_if_missing($pdo, 'price_types', 'is_active', 'is_active TINYINT(1) NOT NULL DEFAULT 1');
    add_column_if_missing($pdo, 'price_types', 'sort_order', 'sort_order INT NOT NULL DEFAULT 0');

    $count = $pdo->query('SELECT COUNT(*) FROM price_types')->fetchColumn();
    if ($count == 0) {
        $defaults = [
            ['Дилер', 1, 0],
            ['Розница', 1, 1],
        ];
        $stmt = $pdo->prepare('INSERT INTO price_types (name, is_active, sort_order) VALUES (?, ?, ?)');
        foreach ($defaults as $d) {
            $stmt->execute($d);
        }
    }
}

function ensure_embossings_table(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS embossings (
        id              INT           NOT NULL AUTO_INCREMENT PRIMARY KEY,
        name            VARCHAR(100)  NOT NULL,
        short_name      VARCHAR(20)   NULL,
        manufacturer_id INT           NULL,
        image_path      VARCHAR(500)  NULL,
        note            TEXT          NULL,
        is_active       TINYINT(1)    NOT NULL DEFAULT 1,
        is_stock_program TINYINT(1)   NOT NULL DEFAULT 0,
        created_at      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    add_column_if_missing($pdo, 'embossings', 'short_name',       'short_name VARCHAR(20) NULL');
    add_column_if_missing($pdo, 'embossings', 'manufacturer_id',  'manufacturer_id INT NULL');
    add_column_if_missing($pdo, 'embossings', 'image_path',       'image_path VARCHAR(500) NULL');
    add_column_if_missing($pdo, 'embossings', 'note',             'note TEXT NULL');
    add_column_if_missing($pdo, 'embossings', 'is_active',        'is_active TINYINT(1) NOT NULL DEFAULT 1');
    add_column_if_missing($pdo, 'embossings', 'is_stock_program', 'is_stock_program TINYINT(1) NOT NULL DEFAULT 0');

    if (table_exists($pdo, 'panel_formats')) {
        add_column_if_missing($pdo, 'panel_formats', 'embossing_id',  'embossing_id INT NULL');
        add_column_if_missing($pdo, 'panel_formats', 'volume_m2',     'volume_m2 DECIMAL(12,6) NULL');
        add_column_if_missing($pdo, 'panel_formats', 'panel_size_id', 'panel_size_id INT NULL');
        add_column_if_missing($pdo, 'panel_formats', 'thickness_id',  'thickness_id INT NULL');
    }
}

function ensure_panel_sizes_table(PDO $pdo): void
{
    ensure_manufacturers_table($pdo);
    $pdo->exec("CREATE TABLE IF NOT EXISTS panel_sizes (
        id              INT            NOT NULL AUTO_INCREMENT PRIMARY KEY,
        height_mm       INT            NOT NULL,
        width_mm        INT            NOT NULL,
        volume_m2       DECIMAL(12,6)  NULL,
        manufacturer_id INT            NULL,
        is_active       TINYINT(1)     NOT NULL DEFAULT 1,
        is_stock_program TINYINT(1)    NOT NULL DEFAULT 0,
        created_at      TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    add_column_if_missing($pdo, 'panel_sizes', 'volume_m2',        'volume_m2 DECIMAL(12,6) NULL');
    add_column_if_missing($pdo, 'panel_sizes', 'manufacturer_id',  'manufacturer_id INT NULL');
    add_column_if_missing($pdo, 'panel_sizes', 'is_active',        'is_active TINYINT(1) NOT NULL DEFAULT 1');
    add_column_if_missing($pdo, 'panel_sizes', 'is_stock_program', 'is_stock_program TINYINT(1) NOT NULL DEFAULT 0');
}

function ensure_panel_thicknesses_table(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS panel_thicknesses (
        id          INT            NOT NULL AUTO_INCREMENT PRIMARY KEY,
        thickness   DECIMAL(6,2)   NOT NULL,
        is_active   TINYINT(1)     NOT NULL DEFAULT 1,
        created_at  TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    add_column_if_missing($pdo, 'panel_thicknesses', 'is_active', 'is_active TINYINT(1) NOT NULL DEFAULT 1');
}

function ensure_countertop_settings_table(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS countertop_settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        kerf_mm DECIMAL(4,1) NOT NULL DEFAULT 4.0,
        blank_width_mm INT NOT NULL DEFAULT 600,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    add_column_if_missing($pdo, 'countertop_settings', 'blank_width_mm', 'blank_width_mm INT NOT NULL DEFAULT 600');

    $pdo->exec("INSERT INTO countertop_settings (id, kerf_mm, blank_width_mm) SELECT 1, 4.0, 600 WHERE NOT EXISTS (SELECT 1 FROM countertop_settings WHERE id = 1)");

    $pdo->exec("CREATE TABLE IF NOT EXISTS countertop_product_types (
        id INT AUTO_INCREMENT PRIMARY KEY,
        type_key VARCHAR(32) NOT NULL UNIQUE,
        name VARCHAR(120) NOT NULL,
        processing_per_m DECIMAL(8,2) NOT NULL DEFAULT 12.00,
        min_width INT NOT NULL DEFAULT 450,
        max_width INT NOT NULL DEFAULT 1854,
        min_length INT NOT NULL DEFAULT 150,
        max_length INT NOT NULL DEFAULT 4100,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $defaults = [
        ['kitchen', 'Кухонная столешница', 12, 450, 1854, 150, 4100],
        ['fartuk',  'Стеновая панель / Фартук', 9, 150, 1000, 300, 1400],
        ['horeca',  'HoReCa', 12, 600, 1400, 600, 1400],
        ['bortik',  'Бортик / Плинтус', 12, 50, 150, 1360, 4100],
    ];
    foreach ($defaults as $d) {
        $stmt = $pdo->prepare("INSERT IGNORE INTO countertop_product_types (type_key, name, processing_per_m, min_width, max_width, min_length, max_length) VALUES (?,?,?,?,?,?,?)");
        $stmt->execute($d);
    }
}

function ensure_subsystem_tables(PDO $pdo): void
{
    ensure_price_list_table($pdo);
    ensure_suppliers_table($pdo);

    $pdo->exec("CREATE TABLE IF NOT EXISTS subsystem_enclosures (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(160) NOT NULL,
        fastener_id INT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        sort_order INT NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    add_column_if_missing($pdo, 'subsystem_enclosures', 'fastener_id', 'fastener_id INT NULL');

    if (column_exists($pdo, 'subsystem_enclosures', 'fastener_type')) {
        $pdo->exec("ALTER TABLE subsystem_enclosures DROP COLUMN fastener_type");
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS subsystem_materials (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(160) NOT NULL,
        unit VARCHAR(40) NOT NULL DEFAULT 'шт.',
        quantity_per_piece DECIMAL(12,4) NOT NULL DEFAULT 1,
        price_per_piece DECIMAL(12,2) NOT NULL DEFAULT 0,
        price_per_unit DECIMAL(12,2) NOT NULL DEFAULT 0,
        consumption_per_m DECIMAL(12,4) NOT NULL DEFAULT 0,
        consumption_unit VARCHAR(40) NOT NULL DEFAULT 'м²',
        currency_id INT NULL,
        supplier_id INT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    add_column_if_missing($pdo, 'subsystem_materials', 'quantity_per_piece', 'quantity_per_piece DECIMAL(12,4) NOT NULL DEFAULT 1');
    add_column_if_missing($pdo, 'subsystem_materials', 'price_per_piece', 'price_per_piece DECIMAL(12,2) NOT NULL DEFAULT 0');
    add_column_if_missing($pdo, 'subsystem_materials', 'currency_id', 'currency_id INT NULL');
    add_column_if_missing($pdo, 'subsystem_materials', 'supplier_id', 'supplier_id INT NULL');
    add_column_if_missing($pdo, 'subsystem_materials', 'consumption_unit', 'consumption_unit VARCHAR(40) NOT NULL DEFAULT \'м²\'');

    $pdo->exec("CREATE TABLE IF NOT EXISTS subsystem_enclosure_materials (
        id INT AUTO_INCREMENT PRIMARY KEY,
        enclosure_id INT NOT NULL,
        material_id INT NOT NULL,
        quantity_per_unit DECIMAL(12,4) NOT NULL DEFAULT 1,
        UNIQUE KEY uniq_enclosure_material (enclosure_id, material_id),
        CONSTRAINT fk_sub_enc_mat_enc FOREIGN KEY (enclosure_id) REFERENCES subsystem_enclosures(id) ON DELETE CASCADE,
        CONSTRAINT fk_sub_enc_mat_mat FOREIGN KEY (material_id) REFERENCES subsystem_materials(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Rename Карпич -> Кирпич
    $pdo->exec("UPDATE subsystem_enclosures SET name = 'Кирпич' WHERE name = 'Карпич'");

    // Clean up duplicates before adding UNIQUE KEYs
    $pdo->exec("DELETE e1 FROM subsystem_enclosures e1 INNER JOIN subsystem_enclosures e2 WHERE e1.id > e2.id AND e1.name = e2.name");
    $pdo->exec("DELETE m1 FROM subsystem_materials m1 INNER JOIN subsystem_materials m2 WHERE m1.id > m2.id AND m1.name = m2.name");

    // Enclosures: unique by name only
    $idx = $pdo->query("SHOW INDEX FROM subsystem_enclosures WHERE Key_name = 'uniq_enclosure_name'")->fetchColumn();
    if (!$idx) {
        $pdo->exec("ALTER TABLE subsystem_enclosures ADD UNIQUE KEY uniq_enclosure_name (name)");
    }

    // Materials: unique by (name, supplier_id) — allow same name for different suppliers
    $idx = $pdo->query("SHOW INDEX FROM subsystem_materials WHERE Key_name = 'uniq_material_name_supplier'")->fetchColumn();
    if (!$idx) {
        // Drop old single-column unique key if exists
        $oldIdx = $pdo->query("SHOW INDEX FROM subsystem_materials WHERE Key_name = 'uniq_material_name'")->fetchColumn();
        if ($oldIdx) {
            $pdo->exec("ALTER TABLE subsystem_materials DROP INDEX uniq_material_name");
        }
        $pdo->exec("ALTER TABLE subsystem_materials ADD UNIQUE KEY uniq_material_name_supplier (name, supplier_id)");
    }

    // Sub items: unique by (name, supplier_id)
    $idx = $pdo->query("SHOW INDEX FROM subsystem_sub_items WHERE Key_name = 'uniq_subitem_name_supplier'")->fetchColumn();
    if (!$idx) {
        $pdo->exec("ALTER TABLE subsystem_sub_items ADD UNIQUE KEY uniq_subitem_name_supplier (name, supplier_id)");
    }

    // Fasteners: unique by (name, supplier_id)
    $idx = $pdo->query("SHOW INDEX FROM subsystem_fasteners WHERE Key_name = 'uniq_fastener_name_supplier'")->fetchColumn();
    if (!$idx) {
        $pdo->exec("ALTER TABLE subsystem_fasteners ADD UNIQUE KEY uniq_fastener_name_supplier (name, supplier_id)");
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS subsystem_fasteners (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(160) NOT NULL,
        unit VARCHAR(40) NOT NULL DEFAULT 'шт.',
        quantity_per_unit DECIMAL(12,4) NOT NULL DEFAULT 1,
        price_per_unit DECIMAL(12,2) NOT NULL DEFAULT 0,
        consumption_per_m DECIMAL(12,4) NOT NULL DEFAULT 0,
        consumption_unit VARCHAR(40) NOT NULL DEFAULT 'м²',
        currency_id INT NULL,
        supplier_id INT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    add_column_if_missing($pdo, 'subsystem_fasteners', 'currency_id', 'currency_id INT NULL');
    add_column_if_missing($pdo, 'subsystem_fasteners', 'supplier_id', 'supplier_id INT NULL');
    add_column_if_missing($pdo, 'subsystem_fasteners', 'consumption_per_m', 'consumption_per_m DECIMAL(12,4) NOT NULL DEFAULT 0');
    add_column_if_missing($pdo, 'subsystem_fasteners', 'consumption_unit', 'consumption_unit VARCHAR(40) NOT NULL DEFAULT \'м²\'');
    add_column_if_missing($pdo, 'subsystem_fasteners', 'quantity_per_piece', 'quantity_per_piece DECIMAL(12,4) NOT NULL DEFAULT 1');
    add_column_if_missing($pdo, 'subsystem_fasteners', 'price_per_piece', 'price_per_piece DECIMAL(12,2) NOT NULL DEFAULT 0');

    // ═══ ПОДСИСТЕМА — profiles & subsystem items per supplier ═══
    $pdo->exec("CREATE TABLE IF NOT EXISTS subsystem_sub_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        supplier_id INT NULL,
        name VARCHAR(160) NOT NULL,
        width_mm DECIMAL(10,2) NOT NULL DEFAULT 0,
        thickness_mm DECIMAL(10,2) NOT NULL DEFAULT 0,
        unit VARCHAR(40) NOT NULL DEFAULT 'шт.',
        quantity_per_piece DECIMAL(12,4) NOT NULL DEFAULT 1,
        price_per_piece DECIMAL(12,2) NOT NULL DEFAULT 0,
        price_per_unit DECIMAL(12,2) NOT NULL DEFAULT 0,
        consumption_per_m DECIMAL(12,4) NOT NULL DEFAULT 0,
        consumption_unit VARCHAR(40) NOT NULL DEFAULT 'м²',
        currency_id INT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    add_column_if_missing($pdo, 'subsystem_sub_items', 'currency_id', 'currency_id INT NULL');
    add_column_if_missing($pdo, 'subsystem_sub_items', 'supplier_id', 'supplier_id INT NULL');
    add_column_if_missing($pdo, 'subsystem_sub_items', 'consumption_per_m', 'consumption_per_m DECIMAL(12,4) NOT NULL DEFAULT 0');
    add_column_if_missing($pdo, 'subsystem_sub_items', 'consumption_unit', 'consumption_unit VARCHAR(40) NOT NULL DEFAULT \'м²\'');
    add_column_if_missing($pdo, 'subsystem_sub_items', 'width_mm', 'width_mm DECIMAL(10,2) NOT NULL DEFAULT 0');
    add_column_if_missing($pdo, 'subsystem_sub_items', 'thickness_mm', 'thickness_mm DECIMAL(10,2) NOT NULL DEFAULT 0');

    // ═══ СОХРАНЁННЫЕ РАСЧЁТЫ ═══
    $pdo->exec("CREATE TABLE IF NOT EXISTS subsystem_calcs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NULL,
        object_name VARCHAR(255) NOT NULL DEFAULT '',
        calc_name VARCHAR(255) NOT NULL DEFAULT '',
        total_price DECIMAL(12,2) NOT NULL DEFAULT 0,
        params JSON NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    add_column_if_missing($pdo, 'subsystem_calcs', 'total_price', 'total_price DECIMAL(12,2) NOT NULL DEFAULT 0');

    $hasVg = $pdo->query("SHOW COLUMNS FROM subsystem_calcs LIKE 'version_group'")->fetch();
    if (!$hasVg) {
        $pdo->exec("ALTER TABLE subsystem_calcs ADD COLUMN version_group INT NULL AFTER id, ADD COLUMN version_number INT NOT NULL DEFAULT 1 AFTER version_group");
    }

    $count = $pdo->query('SELECT COUNT(*) FROM subsystem_enclosures')->fetchColumn();
    if ($count == 0) {
        // First ensure we have fasteners to reference
        $fastCount = $pdo->query('SELECT COUNT(*) FROM subsystem_fasteners')->fetchColumn();
        if ($fastCount == 0) {
            $defaultFasteners = [
                ['Анкеры', 'шт.', 1, 0, 1],
                ['Саморезы', 'шт.', 1, 0, 2],
            ];
            foreach ($defaultFasteners as $df) {
                $stmt = $pdo->prepare("INSERT IGNORE INTO subsystem_fasteners (name, unit, quantity_per_unit, price_per_unit, sort_order) VALUES (?,?,?,?,?)");
                $stmt->execute($df);
            }
        }
        // Now reference fasteners by ID
        $anchorId = $pdo->query("SELECT id FROM subsystem_fasteners WHERE name = 'Анкеры' LIMIT 1")->fetchColumn();
        $screwId = $pdo->query("SELECT id FROM subsystem_fasteners WHERE name = 'Саморезы' LIMIT 1")->fetchColumn();
        $defaults = [
            ['Кирпич', $anchorId, 1],
            ['Пустотелый кирпич', $anchorId, 2],
            ['Бетон', $anchorId, 3],
            ['Гипсокартон 1 слой', $screwId, 4],
            ['Гипсокартон 2 слоя', $screwId, 5],
            ['Сендвич панель', $screwId, 6],
        ];
        foreach ($defaults as $d) {
            $stmt = $pdo->prepare("INSERT IGNORE INTO subsystem_enclosures (name, fastener_id, sort_order) VALUES (?,?,?)");
            $stmt->execute($d);
        }
    }

    $matCount = $pdo->query('SELECT COUNT(*) FROM subsystem_materials')->fetchColumn();
    if ($matCount == 0) {
        $materials = [
            ['Омега профиль', 'м.п.', 0, 0, 1],
            ['Обезжириватель', 'мл', 0, 0, 2],
            ['Праймер', 'мл', 0, 0, 3],
            ['Клеевая лента', 'м.п.', 0, 0, 4],
            ['Силиконовый герметик', 'мл', 0, 0, 5],
            ['Абразивный брусок', 'шт.', 0, 0, 6],
            ['Меламиновая губка', 'шт.', 0, 0, 7],
        ];
        foreach ($materials as $m) {
            $stmt = $pdo->prepare("INSERT IGNORE INTO subsystem_materials (name, unit, price_per_unit, consumption_per_m, sort_order) VALUES (?,?,?,?,?)");
            $stmt->execute($m);
        }
    }
}
