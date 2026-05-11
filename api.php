<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Admin-Token');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit; }

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

$action = $_GET['action'] ?? $_POST['action'] ?? 'list_public';

function input($key, $default = '') {
    return trim($_POST[$key] ?? $_GET[$key] ?? $default);
}

function json_response($success, $message = '', $data = null, $statusCode = 200) {
    http_response_code($statusCode);
    $payload = ['success' => $success];
    if ($message !== '') { $payload['message'] = $message; }
    if ($data !== null) { $payload['data'] = $data; }
    echo json_encode($payload);
    exit;
}

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

function inventory_rows($db, $admin = false) {
    $q = input('q');
    $category = input('category');
    $status = input('status');

    $sql = $admin
        ? "SELECT * FROM hardware_items WHERE 1=1"
        : "SELECT id, item_name, category, brand, model, quantity, status, location, remarks, updated_at FROM hardware_items WHERE " . public_inventory_sql();

    $params = [];

    if ($q !== '') {
        if ($admin) {
            $sql .= " AND (item_name LIKE :q OR category LIKE :q OR brand LIKE :q OR model LIKE :q OR status LIKE :q OR location LIKE :q OR serial_number LIKE :q)";
        } else {
            $sql .= " AND (item_name LIKE :q OR category LIKE :q OR brand LIKE :q OR model LIKE :q OR status LIKE :q OR location LIKE :q)";
        }
        $params[':q'] = '%' . $q . '%';
    }

    if ($category !== '' && $category !== 'All Categories') {
        $sql .= " AND category = :category";
        $params[':category'] = $category;
    }

    if ($status !== '' && $status !== 'All Statuses') {
        $sql .= " AND status = :status";
        $params[':status'] = $status;
    }

    $sql .= $admin ? " ORDER BY id DESC" : " ORDER BY item_name ASC, id DESC";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function update_order_status($db) {
    $id = (int)input('id');
    $status = input('order_status');
    $adminNote = input('admin_note');

    if ($id <= 0) {
        json_response(false, 'Select a valid order first.', null, 422);
    }
    if (!in_array($status, order_statuses(), true)) {
        json_response(false, 'Invalid order status.', null, 422);
    }