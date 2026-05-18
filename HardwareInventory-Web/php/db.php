<?php
/*
|--------------------------------------------------------------------------
| INITIAL DATABASE CONFIGURATION
|--------------------------------------------------------------------------
| This file initializes the SQLite database and creates the
| hardware_items table for the inventory management system.
|--------------------------------------------------------------------------
*/

/**
 * Get SQLite database connection
 */
function get_db()
{
    // Check if SQLite driver is enabled
    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        throw new RuntimeException(
            'PDO SQLite driver is not enabled. Enable extension=pdo_sqlite in php.ini and restart Apache.'
        );
    }

    // Database file path
    $dbFile = __DIR__ . '/hardware_inventory.sqlite';

    // Create connection
    $db = new PDO('sqlite:' . $dbFile);

    // Enable error handling
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Enable foreign keys
    $db->exec('PRAGMA foreign_keys = ON');

    // Create table if not exists
    $db->exec("
        CREATE TABLE IF NOT EXISTS hardware_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            item_name TEXT NOT NULL,
            category TEXT NOT NULL,
            brand TEXT,
            model TEXT,
            serial_number TEXT,
            quantity INTEGER NOT NULL DEFAULT 0,
            status TEXT NOT NULL DEFAULT 'Available',
            location TEXT,
            remarks TEXT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ");

    return $db;
}

/**
 * Seed database with default items if empty
 */
function seed_if_empty($db)
{
    $count = (int) $db->query("SELECT COUNT(*) FROM hardware_items")
                      ->fetchColumn();

    if ($count > 0) {
        return;
    }

    $stmt = $db->prepare("
        INSERT INTO hardware_items
        (
            item_name,
            category,
            brand,
            model,
            serial_number,
            quantity,
            status,
            location,
            remarks
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $sampleItems = [
        [
            'Desktop Computer',
            'Computer Unit',
            'Dell',
            'OptiPlex 3090',
            'DX-PC-001',
            12,
            'Available',
            'Main Stockroom',
            'Ready for release'
        ],
        [
            'Network Switch',
            'Network Device',
            'TP-Link',
            'TL-SG1024',
            'NET-SW-024',
            3,
            'Available',
            'Network Cabinet',
            '24-port gigabit switch'
        ],
        [
            'LCD Monitor',
            'Peripheral',
            'Acer',
            'V196HQL',
            'MON-AC-091',
            8,
            'Available',
            'Display Rack',
            'For workstation setup'
        ],
        [
            'External SSD',
            'Storage Device',
            'Kingston',
            'XS1000',
            'SSD-KG-007',
            5,
            'Low Stock',
            'Accessories Shelf',
            'Portable storage unit'
        ],
        [
            'Laser Printer',
            'Printer',
            'Brother',
            'HL-L2320D',
            'PRN-BR-013',
            2,
            'Available',
            'Printer Area',
            'For office use'
        ]
    ];

    foreach ($sampleItems as $item) {
        $stmt->execute($item);
    }
}

/**
 * Inventory categories
 */
function inventory_categories()
{
    return [
        'Computer Unit',
        'Peripheral',
        'Network Device',
        'Storage Device',
        'Printer',
        'Power Device',
        'Other Hardware'
    ];
}

/**
 * Inventory statuses
 */
function inventory_statuses()
{
    return [
        'Available',
        'In Use',
        'Low Stock',
        'For Repair',
        'Defective',
        'Disposed'
    ];
}

/**
 * Public inventory filter condition
 */
function public_inventory_sql()
{
    return "status NOT IN ('Defective', 'Disposed')";
}
?>