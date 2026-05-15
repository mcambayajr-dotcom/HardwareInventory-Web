<?php
/*
    INITIAL DATABASE CONFIGURATION

    This version prepares the SQLite database
    and creates the hardware_items table
    for the inventory management system.
*/

function get_db()
{
    // Verify if SQLite driver is enabled in PHP
    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
        throw new RuntimeException(
            'PDO SQLite driver is not enabled. In XAMPP, enable extension=pdo_sqlite in php.ini and restart PHP/Apache.'
        );
    }

    // Define SQLite database file path
    $dbFile = __DIR__ . '/hardware_inventory.sqlite';

    // Create database connection
    $db = new PDO('sqlite:' . $dbFile);

    // Enable PDO exception handling
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Enable foreign key support
    $db->exec('PRAGMA foreign_keys = ON');

    // Create inventory table if it does not exist
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

    // Return active database connection
    return $db;
}


// Inserts default hardware records when inventory table is empty
function seed_if_empty($db)
{
    // Count existing inventory items
    $count = (int)$db
        ->query("SELECT COUNT(*) FROM hardware_items")
        ->fetchColumn();

    // Stop seeding if records already exist
    if ($count > 0) {
        return;
    }

    // Prepare reusable insert statement
    $query = "
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
    ";

    $stmt = $db->prepare($query);

    // Default sample inventory records
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

    // Insert all sample inventory records
    foreach ($sampleItems as $item) {
        $stmt->execute($item);
    }
}

// Returns all available inventory categories
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

// Returns all supported inventory statuses
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

// SQL condition for publicly visible inventory items
function public_inventory_sql()
{
    return "status NOT IN ('Defective', 'Disposed')";
}
?>
