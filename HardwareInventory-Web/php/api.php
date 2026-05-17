<?php
/*
|--------------------------------------------------------------------------
| Inventory Management API
|--------------------------------------------------------------------------
| Available Endpoints:
| - Inventory Listing
| - Admin Inventory Controls
| - Item CRUD Operations
| - Search and Filtering
|--------------------------------------------------------------------------
*/

/*
|--------------------------------------------------------------------------
| API Headers
|--------------------------------------------------------------------------
*/

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Admin-Token');

// Handle preflight request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

/*
|--------------------------------------------------------------------------
| Required Files
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

/*
|--------------------------------------------------------------------------
| Current Requested Action
|--------------------------------------------------------------------------
*/

$action =
    $_GET['action']
    ?? $_POST['action']
    ?? 'list_public';

/*
|--------------------------------------------------------------------------
| Helper Functions
|--------------------------------------------------------------------------
*/

// Safely read request input values
function input($key, $default = '')
{
    return trim(
        $_POST[$key]
        ?? $_GET[$key]
        ?? $default
    );
}

// Standard JSON response formatter
function json_response(
    $success,
    $message = '',
    $data = null,
    $statusCode = 200
) {
    http_response_code($statusCode);

    $payload = [
        'success' => $success
    ];

    if ($message !== '') {
        $payload['message'] = $message;
    }

    if ($data !== null) {
        $payload['data'] = $data;
    }

    echo json_encode($payload);
    exit;
}

// Validate administrator API token
function require_admin_api_token()
{
    $headers =
        function_exists('getallheaders')
        ? getallheaders()
        : [];

    $headerToken = '';

    foreach ($headers as $name => $value) {

        if (strtolower($name) === 'x-admin-token') {
            $headerToken = trim($value);
            break;
        }
    }

    $token =
        $headerToken !== ''
        ? $headerToken
        : input('admin_token');

    if ($token !== ADMIN_API_TOKEN) {

        json_response(
            false,
            'Access denied.',
            null,
            403
        );
    }
}

/*
|--------------------------------------------------------------------------
| Inventory Payload Builder
|--------------------------------------------------------------------------
*/

function item_payload($includeId = false)
{
    // Read request values
    $itemName  = trim(input('item_name'));
    $category  = trim(input('category'));
    $brand     = trim(input('brand'));
    $model     = trim(input('model'));
    $serial    = trim(input('serial_number'));
    $quantity  = input('quantity', 0);
    $status    = input('status', 'Available');
    $location  = trim(input('location'));
    $remarks   = trim(input('remarks'));

    // Validate update item ID
    if ($includeId) {

        $itemId = (int) input('id');

        if ($itemId <= 0) {

            json_response(
                false,
                'Invalid inventory item selected.',
                null,
                422
            );
        }
    }

    // Validate item name
    if ($itemName === '') {

        json_response(
            false,
            'Item name is required.',
            null,
            422
        );
    }

    // Validate category
    if ($category === '') {

        json_response(
            false,
            'Category field is required.',
            null,
            422
        );
    }

    // Validate quantity
    if (!is_numeric($quantity)) {

        json_response(
            false,
            'Quantity value must be numeric.',
            null,
            422
        );
    }

    $quantity = (int) $quantity;

    // Prevent negative stock values
    if ($quantity < 0) {

        json_response(
            false,
            'Quantity cannot be less than zero.',
            null,
            422
        );
    }

    // Validate inventory status
    $validStatuses = inventory_statuses();

    if (!in_array($status, $validStatuses, true)) {
        $status = 'Available';
    }

    // Build payload array
    $payload = [
        'item_name'     => $itemName,
        'category'      => $category,
        'brand'         => $brand,
        'model'         => $model,
        'serial_number' => $serial,
        'quantity'      => $quantity,
        'status'        => $status,
        'location'      => $location,
        'remarks'       => $remarks
    ];

    // Include ID during update
    if ($includeId) {
        $payload['id'] = (int) input('id');
    }

    return $payload;
}

/*
|--------------------------------------------------------------------------
| Inventory Query Builder
|--------------------------------------------------------------------------
*/

function inventory_rows($db, $admin = false)
{
    $q        = input('q');
    $category = input('category');
    $status   = input('status');

    // Public and admin query views
    $sql = $admin
        ? "SELECT * FROM hardware_items WHERE 1=1"
        : "SELECT
                id,
                item_name,
                category,
                brand,
                model,
                quantity,
                status,
                location,
                remarks,
                updated_at
           FROM hardware_items
           WHERE " . public_inventory_sql();

    $params = [];

    // Apply search filter
    if ($q !== '') {

        if ($admin) {

            $sql .= "
                AND (
                    item_name LIKE :q
                    OR category LIKE :q
                    OR brand LIKE :q
                    OR model LIKE :q
                    OR status LIKE :q
                    OR location LIKE :q
                    OR serial_number LIKE :q
                )
            ";

        } else {

            $sql .= "
                AND (
                    item_name LIKE :q
                    OR category LIKE :q
                    OR brand LIKE :q
                    OR model LIKE :q
                    OR status LIKE :q
                    OR location LIKE :q
                )
            ";
        }

        $params[':q'] = '%' . $q . '%';
    }

    // Apply category filter
    if (
        $category !== ''
        && $category !== 'All Categories'
    ) {
        $sql .= " AND category = :category";

        $params[':category'] = $category;
    }

    // Apply status filter
    if (
        $status !== ''
        && $status !== 'All Statuses'
    ) {
        $sql .= " AND status = :status";

        $params[':status'] = $status;
    }

    // Sort records
    $sql .= $admin
        ? " ORDER BY id DESC"
        : " ORDER BY item_name ASC, id DESC";

    $stmt = $db->prepare($sql);

    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/*
|--------------------------------------------------------------------------
| Main API Router
|--------------------------------------------------------------------------
*/

try {

    // Connect database
    $db = get_db();

    // Insert sample data if database is empty
    seed_if_empty($db);

    /*
    |--------------------------------------------------------------------------
    | API Health Check
    |--------------------------------------------------------------------------
    */

    if ($action === 'ping') {

        json_response(
            true,
            'API is online'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Public Inventory Listing
    |--------------------------------------------------------------------------
    */

    if (
        $action === 'list'
        || $action === 'list_public'
        || $action === 'customer_list'
    ) {

        json_response(
            true,
            '',
            inventory_rows($db, false)
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Admin Inventory Listing
    |--------------------------------------------------------------------------
    */

    if ($action === 'list_admin') {

        require_admin_api_token();

        json_response(
            true,
            '',
            inventory_rows($db, true)
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Add Inventory Item
    |--------------------------------------------------------------------------
    */

    if ($action === 'add') {

        require_admin_api_token();

        $item = item_payload(false);

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
                remarks,
                created_at,
                updated_at
            )
            VALUES
            (
                :item_name,
                :category,
                :brand,
                :model,
                :serial_number,
                :quantity,
                :status,
                :location,
                :remarks,
                CURRENT_TIMESTAMP,
                CURRENT_TIMESTAMP
            )
        ");

        $stmt->execute($item);

        json_response(
            true,
            'Item has been added.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Update Inventory Item
    |--------------------------------------------------------------------------
    */

    if ($action === 'update') {

        require_admin_api_token();

        $item = item_payload(true);

        $exists = $db->prepare("
            SELECT COUNT(*)
            FROM hardware_items
            WHERE id = :id
        ");

        $exists->execute([
            ':id' => $item['id']
        ]);

        if ((int)$exists->fetchColumn() === 0) {

            json_response(
                false,
                'Item was not found.',
                null,
                404
            );
        }

        $stmt = $db->prepare("
            UPDATE hardware_items SET
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
            WHERE id = :id
        ");

        $stmt->execute($item);

        json_response(
            true,
            'Item has been updated.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Delete Inventory Item
    |--------------------------------------------------------------------------
    */

    if ($action === 'delete') {

        require_admin_api_token();

        $itemId = (int) input('id');

        // Validate selected ID
        if ($itemId < 1) {

            json_response(
                false,
                'Please select a valid inventory item.',
                null,
                422
            );
        }

        // Delete item
        $deleteItem = $db->prepare("
            DELETE FROM hardware_items
            WHERE id = :id
        ");

        $deleteItem->execute([
            ':id' => $itemId
        ]);

        // Check if item existed
        if ($deleteItem->rowCount() < 1) {

            json_response(
                false,
                'Selected inventory item does not exist.',
                null,
                404
            );
        }

        json_response(
            true,
            'Inventory item deleted successfully.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Unknown Action
    |--------------------------------------------------------------------------
    */

    json_response(
        false,
        'Unknown request action.',
        null,
        404
    );

} catch (Exception $exception) {

    /*
    |--------------------------------------------------------------------------
    | Server Error Handler
    |--------------------------------------------------------------------------
    */

    json_response(
        false,
        $exception->getMessage(),
        null,
        500
    );
}

?>