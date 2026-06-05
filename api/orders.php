<?php
// api/orders.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/db.php';

$method = $_SERVER['REQUEST_METHOD'];
$user_id = $_SESSION['user_id'] ?? null;

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized. Please sign in.']);
    exit;
}

if ($method === 'GET') {
    $role = $_SESSION['role'] ?? 'buyer';

    if ($role === 'seller') {
        // Sellers: list orders containing their products
        $stmt = $pdo->prepare("
            SELECT o.id AS order_id, o.order_code, o.ship_name, o.ship_address, o.ship_city, o.ship_province, o.ship_postal, o.ship_phone, o.payment_method, o.created_at, o.status AS order_status,
                   oi.id AS item_id, oi.title, oi.price, oi.quantity, oi.image_path, oi.product_id,
                   u.full_name AS buyer_name,
                   t.tracking_number, t.courier
            FROM orders o
            JOIN order_items oi ON o.id = oi.order_id
            JOIN products p ON oi.product_id = p.id
            JOIN users u ON o.buyer_id = u.id
            LEFT JOIN tracking t ON o.id = t.order_id AND t.seller_id = ?
            WHERE p.seller_id = ?
            ORDER BY o.id DESC
        ");
        $stmt->execute([$user_id, $user_id]);
        echo json_encode($stmt->fetchAll());
    } else {
        // Buyers: list their placed orders
        $stmt = $pdo->prepare("
            SELECT o.*, 
                   (SELECT JSON_ARRAYAGG(JSON_OBJECT('title', oi.title, 'price', oi.price, 'quantity', oi.quantity, 'image_path', oi.image_path)) 
                    FROM order_items oi WHERE oi.order_id = o.id) AS items,
                   t.tracking_number, t.courier
            FROM orders o
            LEFT JOIN tracking t ON o.id = t.order_id
            WHERE o.buyer_id = ?
            ORDER BY o.id DESC
        ");
        $stmt->execute([$user_id]);
        $orders = $stmt->fetchAll();
        
        // Decode JSON array of items
        foreach ($orders as &$order) {
            if ($order['items']) {
                $order['items'] = json_decode($order['items'], true);
            } else {
                $order['items'] = [];
            }
        }
        echo json_encode($orders);
    }
    exit;
}

if ($method === 'POST') {
    // Place order
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    
    $ship_name = trim($input['ship_name'] ?? '');
    $ship_address = trim($input['ship_address'] ?? '');
    $ship_city = trim($input['ship_city'] ?? '');
    $ship_province = trim($input['ship_province'] ?? '');
    $ship_postal = trim($input['ship_postal'] ?? '');
    $ship_phone = trim($input['ship_phone'] ?? '');
    $payment_method = $input['payment_method'] ?? 'card';
    $subtotal = floatval($input['subtotal'] ?? 0);
    $shipping_cost = floatval($input['shipping_cost'] ?? 0);
    $total = floatval($input['total'] ?? 0);

    if (empty($ship_name) || empty($ship_address) || empty($ship_city) || empty($ship_province) || empty($ship_postal) || empty($ship_phone)) {
        http_response_code(400);
        echo json_encode(['error' => 'All shipping fields are required.']);
        exit;
    }

    if (!in_array($payment_method, ['card', 'eft'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid payment method.']);
        exit;
    }

    // Get cart items
    $stmtCart = $pdo->prepare("
        SELECT c.product_id, c.quantity, p.title, p.price, p.image_path, p.status 
        FROM cart_items c
        JOIN products p ON c.product_id = p.id
        WHERE c.user_id = ?
    ");
    $stmtCart->execute([$user_id]);
    $cart_items = $stmtCart->fetchAll();

    if (empty($cart_items)) {
        http_response_code(400);
        echo json_encode(['error' => 'Your cart is empty.']);
        exit;
    }

    // Verify all products in cart are active
    foreach ($cart_items as $item) {
        if ($item['status'] !== 'active') {
            http_response_code(400);
            echo json_encode(['error' => "Item '{$item['title']}' is no longer available."]);
            exit;
        }
    }

    try {
        $pdo->beginTransaction();

        // 1. Generate Order Code
        $order_code = 'NSM-' . mt_rand(1000, 9999) . '-ZA';

        // 2. Insert Order
        $stmtOrder = $pdo->prepare("
            INSERT INTO orders (order_code, buyer_id, ship_name, ship_address, ship_city, ship_province, ship_postal, ship_phone, payment_method, subtotal, shipping_cost, total, status) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')
        ");
        $stmtOrder->execute([
            $order_code,
            $user_id,
            $ship_name,
            $ship_address,
            $ship_city,
            $ship_province,
            $ship_postal,
            $ship_phone,
            $payment_method,
            $subtotal,
            $shipping_cost,
            $total
        ]);
        $order_id = $pdo->lastInsertId();

        // 3. Move items and update products
        $stmtItem = $pdo->prepare("
            INSERT INTO order_items (order_id, product_id, title, price, quantity, image_path) 
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmtUpdateProduct = $pdo->prepare("UPDATE products SET status = 'sold' WHERE id = ?");

        foreach ($cart_items as $item) {
            $stmtItem->execute([
                $order_id,
                $item['product_id'],
                $item['title'],
                $item['price'],
                $item['quantity'],
                $item['image_path']
            ]);
            $stmtUpdateProduct->execute([$item['product_id']]);
        }

        // 4. Clear Cart
        $stmtClearCart = $pdo->prepare("DELETE FROM cart_items WHERE user_id = ?");
        $stmtClearCart->execute([$user_id]);

        $pdo->commit();

        echo json_encode([
            'success' => true,
            'message' => 'Order placed successfully.',
            'order_code' => $order_code,
            'order_id' => $order_id
        ]);

    } catch (Exception $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['error' => 'Failed to place order: ' . $e->getMessage()]);
    }
    exit;
}

if ($method === 'PUT') {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $input['action'] ?? '';
    
    if ($action === 'mark_paid') {
        $order_code = $input['order_code'] ?? '';
        if (empty($order_code)) {
            http_response_code(400);
            echo json_encode(['error' => 'Order code required.']);
            exit;
        }
        
        $stmt = $pdo->prepare("UPDATE orders SET status = 'paid' WHERE order_code = ?");
        $stmt->execute([$order_code]);
        
        echo json_encode(['success' => true, 'message' => 'Order marked as paid.']);
        exit;
    }
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed.']);
exit;
