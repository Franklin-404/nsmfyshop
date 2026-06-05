<?php
// api/cart.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/db.php';

$method = $_SERVER['REQUEST_METHOD'];
$user_id = $_SESSION['user_id'] ?? null;
$session_id = session_id();

if ($method === 'GET') {
    // Get cart items
    if ($user_id) {
        $stmt = $pdo->prepare("
            SELECT c.id, c.product_id, c.quantity, p.title, p.price, p.image_path, p.status
            FROM cart_items c
            JOIN products p ON c.product_id = p.id
            WHERE c.user_id = ?
        ");
        $stmt->execute([$user_id]);
    } else {
        $stmt = $pdo->prepare("
            SELECT c.id, c.product_id, c.quantity, p.title, p.price, p.image_path, p.status
            FROM cart_items c
            JOIN products p ON c.product_id = p.id
            WHERE c.session_id = ? AND c.user_id IS NULL
        ");
        $stmt->execute([$session_id]);
    }
    
    $items = $stmt->fetchAll();
    echo json_encode($items);
    exit;
}

if ($method === 'POST') {
    // Add item to cart
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $product_id = intval($input['product_id'] ?? 0);
    $quantity = intval($input['quantity'] ?? 1);

    if ($product_id <= 0 || $quantity <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid product or quantity.']);
        exit;
    }

    // Verify product exists and is active
    $stmt = $pdo->prepare("SELECT id, status FROM products WHERE id = ?");
    $stmt->execute([$product_id]);
    $product = $stmt->fetch();

    if (!$product) {
        http_response_code(404);
        echo json_encode(['error' => 'Product not found.']);
        exit;
    }

    if ($product['status'] !== 'active') {
        http_response_code(400);
        echo json_encode(['error' => 'This product is no longer active.']);
        exit;
    }

    // Check if product is already in the cart
    if ($user_id) {
        $stmtCheck = $pdo->prepare("SELECT id, quantity FROM cart_items WHERE user_id = ? AND product_id = ?");
        $stmtCheck->execute([$user_id, $product_id]);
    } else {
        $stmtCheck = $pdo->prepare("SELECT id, quantity FROM cart_items WHERE session_id = ? AND user_id IS NULL AND product_id = ?");
        $stmtCheck->execute([$session_id, $product_id]);
    }

    $existing = $stmtCheck->fetch();

    if ($existing) {
        $new_qty = $existing['quantity'] + $quantity;
        $stmtUpdate = $pdo->prepare("UPDATE cart_items SET quantity = ? WHERE id = ?");
        $stmtUpdate->execute([$new_qty, $existing['id']]);
    } else {
        if ($user_id) {
            $stmtInsert = $pdo->prepare("INSERT INTO cart_items (user_id, product_id, quantity) VALUES (?, ?, ?)");
            $stmtInsert->execute([$user_id, $product_id, $quantity]);
        } else {
            $stmtInsert = $pdo->prepare("INSERT INTO cart_items (session_id, product_id, quantity) VALUES (?, ?, ?)");
            $stmtInsert->execute([$session_id, $product_id, $quantity]);
        }
    }

    echo json_encode(['success' => true, 'message' => 'Item added to cart.']);
    exit;
}

if ($method === 'PUT') {
    // Update quantity in cart
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $product_id = intval($input['product_id'] ?? 0);
    $quantity = intval($input['quantity'] ?? 0);

    if ($product_id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid product.']);
        exit;
    }

    if ($quantity <= 0) {
        // Remove item if quantity is 0 or less
        if ($user_id) {
            $stmtDelete = $pdo->prepare("DELETE FROM cart_items WHERE user_id = ? AND product_id = ?");
            $stmtDelete->execute([$user_id, $product_id]);
        } else {
            $stmtDelete = $pdo->prepare("DELETE FROM cart_items WHERE session_id = ? AND user_id IS NULL AND product_id = ?");
            $stmtDelete->execute([$session_id, $product_id]);
        }
        echo json_encode(['success' => true, 'message' => 'Item removed from cart.']);
        exit;
    }

    // Update quantity
    if ($user_id) {
        $stmtUpdate = $pdo->prepare("UPDATE cart_items SET quantity = ? WHERE user_id = ? AND product_id = ?");
        $stmtUpdate->execute([$quantity, $user_id, $product_id]);
    } else {
        $stmtUpdate = $pdo->prepare("UPDATE cart_items SET quantity = ? WHERE session_id = ? AND user_id IS NULL AND product_id = ?");
        $stmtUpdate->execute([$quantity, $session_id, $product_id]);
    }

    echo json_encode(['success' => true, 'message' => 'Cart quantity updated.']);
    exit;
}

if ($method === 'DELETE') {
    // Remove item from cart or clear cart
    $product_id = intval($_GET['product_id'] ?? 0);
    $action = $_GET['action'] ?? '';

    if ($action === 'clear') {
        if ($user_id) {
            $stmtClear = $pdo->prepare("DELETE FROM cart_items WHERE user_id = ?");
            $stmtClear->execute([$user_id]);
        } else {
            $stmtClear = $pdo->prepare("DELETE FROM cart_items WHERE session_id = ? AND user_id IS NULL");
            $stmtClear->execute([$session_id]);
        }
        echo json_encode(['success' => true, 'message' => 'Cart cleared.']);
        exit;
    }

    if ($product_id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid product.']);
        exit;
    }

    if ($user_id) {
        $stmtDelete = $pdo->prepare("DELETE FROM cart_items WHERE user_id = ? AND product_id = ?");
        $stmtDelete->execute([$user_id, $product_id]);
    } else {
        $stmtDelete = $pdo->prepare("DELETE FROM cart_items WHERE session_id = ? AND user_id IS NULL AND product_id = ?");
        $stmtDelete->execute([$session_id, $product_id]);
    }

    echo json_encode(['success' => true, 'message' => 'Item removed from cart.']);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed.']);
exit;
