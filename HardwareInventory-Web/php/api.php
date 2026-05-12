<?php
/*
    Inventory Management API

    Available endpoints:
    - Inventory listing
    - Admin inventory controls
    - Item CRUD operations
    - Inventory search and filtering
*/

// Set API headers for JSON response formatting and Cross-Origin Resource Sharing (CORS)
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Admin-Token');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }

// Load configuration constants and database connection setup
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

// Retrieve the requested API action, defaulting to 'list_public' if none is provided
$action = $_GET['action'] ?? $_POST['action'] ?? 'list_public';

// Helper function to sanitize and trim incoming request inputs
function input($key, $default = '') {
    return trim($_POST[$key] ?? $_GET[$key] ?? $default);
}

// Helper function to send standard structured JSON responses with HTTP status codes
function json_response($success, $message = '', $data = null, $statusCode = 200) {
    http_response_code($statusCode);
    $payload = ['success' => $success];
    if ($message !== '') { $payload['message'] = $message; }
    if ($data !== null) { $payload['data'] = $data; }
    echo json_encode($payload);
    exit;
}

// Security middleware to validate the Admin Token from headers or parameters
function require_admin_api_token() {
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $headerToken = '';
    foreach ($headers as $name => $value) {
        if (strtolower($name) === 'x-admin-token') {
            $headerToken = trim($value);
            break;
        }
    }
    $token = $headerToken !== '' ? $headerToken : input('admin_token');
    if ($token !== ADMIN_API_TOKEN) {
        json_response(false, 'Access denied.', null, 403);
    }
}

// Extracts, processes, and validates item payload structure for inserts/updates
function item_payload($includeId = false) {
    $itemName = input('item_name');
    $category = input('category');
    $brand = input('brand');
    $model = input('model');
    $serial = input('serial_number');
    $quantityRaw = input('quantity', '0');
    $status = input('status', 'Available');
    $location = input('location');
    $remarks = input('remarks');

    if ($includeId && (int)input('id') <= 0) {
        json_response(false, 'Select a valid item first.', null, 422);
    }
    if ($itemName === '') {
        json_response(false, 'Item name is required.', null, 422);
    }
    if ($category === '') {
        json_response(false, 'Category is required.', null, 422);
    }
    if (!is_numeric($quantityRaw)) {
        json_response(false, 'Quantity must be a number.', null, 422);
    }
    $quantity = (int)$quantityRaw;
    if ($quantity < 0) {
        json_response(false, 'Quantity cannot be negative.', null, 422);
    }
    $allowedStatus = inventory_statuses();
    if (!in_array($status, $allowedStatus, true)) {
        $status = 'Available';
    }
    $payload = [
        'item_name' => $itemName,
        'category' => $category,
        'brand' => $brand,
        'model' => $model,
        'serial_number' => $serial,
        'quantity' => $quantity,
        'status' => $status,
        'location' => $location,
        'remarks' => $remarks
    ];

    if ($includeId) {
        $payload['id'] = (int)input('id');
    }

    return $payload;
}

// Dynamically builds SQL queries to handle filtering, search parameters, and role views
function inventory_rows($db, $admin = false) {
    $q = input('q');
    $category = input('category');
    $status = input('status');

    // Admins view all columns including serial numbers; public view is restricted
    $sql = $admin
        ? "SELECT * FROM hardware_items WHERE 1=1"
        : "SELECT id, item_name, category, brand, model, quantity, status, location, remarks, updated_at FROM hardware_items WHERE " . public_inventory_sql();

    $params = [];

    // Apply global search keyword filter across multiple attributes
    if ($q !== '') {
        if ($admin) {
            $sql .= " AND (item_name LIKE :q OR category LIKE :q OR brand LIKE :q OR model LIKE :q OR status LIKE :q OR location LIKE :q OR serial_number LIKE :q)";
        } else {
            $sql .= " AND (item_name LIKE :q OR category LIKE :q OR brand LIKE :q OR model LIKE :q OR status LIKE :q OR location LIKE :q)";
        }
        $params[':q'] = '%' . $q . '%';
    }
    // Filter rows by specific category selection
    if ($category !== '' && $category !== 'All Categories') {
        $sql .= " AND category = :category";
        $params[':category'] = $category;
    }
    // Filter rows by item availability status
    if ($status !== '' && $status !== 'All Statuses') {
        $sql .= " AND status = :status";
        $params[':status'] = $status;
    }

    $sql .= $admin ? " ORDER BY id DESC" : " ORDER BY item_name ASC, id DESC";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

try {
    // Establish DB handle connection and trigger optional baseline dummy records seeding
    $db = get_db();
    seed_if_empty($db);

    // [ENDPOINT] Ping: Health check to verify API accessibility
    if ($action === 'ping') {
        json_response(true, 'API is online');
    }
    // [ENDPOINT] List Public: Fetch public-facing filtered product catalogs
    if ($action === 'list' || $action === 'list_public' || $action === 'customer_list') {
        json_response(true, '', inventory_rows($db, false));
    }
    // [ENDPOINT] List Admin: Fetch comprehensive master ledger data for admins
    if ($action === 'list_admin') {
        require_admin_api_token();
        json_response(true, '', inventory_rows($db, true));
    }
    // [ENDPOINT] Add: Commit a freshly constructed record into the hardware table
    if ($action === 'add') {
        require_admin_api_token();
        $item = item_payload(false);

        $stmt = $db->prepare("INSERT INTO hardware_items (item_name, category, brand, model, serial_number, quantity, status, location, remarks, created_at, updated_at)
                              VALUES (:item_name, :category, :brand, :model, :serial_number, :quantity, :status, :location, :remarks, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
        $stmt->execute($item);

        json_response(true, 'Item has been added.');
    }
    // [ENDPOINT] Update: Modify detailed traits of an existing row targeting its row ID
    if ($action === 'update') {
        require_admin_api_token();
        $item = item_payload(true);

        $exists = $db->prepare("SELECT COUNT(*) FROM hardware_items WHERE id = :id");
        $exists->execute([':id' => $item['id']]);
        if ((int)$exists->fetchColumn() === 0) {
            json_response(false, 'Item was not found.', null, 404);
        }

        $stmt = $db->prepare("UPDATE hardware_items SET
            item_name = :item_name,
            category = :category,
            brand = :brand,
            model = :model,
            serial_number = :serial_number,
            quantity = :quantity,
            status = :status,
            location = :location,
            remarks = :remarks,
            updated_at = CURRENT_TIMESTAMP
            WHERE id = :id");
        $stmt->execute($item);

        json_response(true, 'Item has been updated.');
    }

    // Handle inventory item deletion request
    if ($action === 'delete') {

        // Verify administrator access token
        require_admin_api_token();

        // Read selected item ID
        $itemId = (int) input('id');

        // Validate item identifier
        if ($itemId < 1) {
            json_response(
                false,
                'Please select a valid inventory item.',
                null,
                422
            );
        }

        // Remove item from database
        $deleteItem = $db->prepare("
            DELETE FROM hardware_items
            WHERE id = :id
        ");

        $deleteItem->execute([
            ':id' => $itemId
        ]);

        // Check if item actually existed
        if ($deleteItem->rowCount() < 1) {

            json_response(
                false,
                'Selected inventory item does not exist.',
                null,
                404
            );
        }

        // Successful delete response
        json_response(
            true,
            'Inventory item deleted successfully.'
        );
    }

    // Default response for unsupported API actions
    json_response(
        false,
        'Unknown request action.',
        null,
        404
    );

} catch (Exception $exception) {

    // Return safe server error response
    json_response(
        false,
        $exception->getMessage(),
        null,
        500
    );
}

?>