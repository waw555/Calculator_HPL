<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "This script must be run from CLI.\n";
    exit(1);
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/admin_schema.php';

$steps = [
    'currencies' => 'ensure_currencies_table',
    'suppliers' => 'ensure_suppliers_table',
    'supplier_products' => 'ensure_supplier_products_table',
    'measurement_units' => 'ensure_units_table',
    'manufacturers' => 'ensure_manufacturers_table',
    'panel_sizes' => 'ensure_panel_sizes_table',
    'panel_thicknesses' => 'ensure_panel_thicknesses_table',
    'embossings' => 'ensure_embossings_table',
    'panel_formats' => 'ensure_panel_formats_table',
    'countertop_settings' => 'ensure_countertop_settings_table',
    'calculator_tables' => 'ensure_calculator_tables',
    'organization' => 'ensure_organization_table',
    'subsystem' => 'ensure_subsystem_tables',
];

foreach ($steps as $name => $function) {
    if (!function_exists($function)) {
        echo "skip {$name}: {$function} is not defined\n";
        continue;
    }
    $function($pdo);
    echo "ok {$name}\n";
}
