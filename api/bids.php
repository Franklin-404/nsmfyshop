<?php
// api/bids.php
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

if ($method === 'GET') {
    $product_id = intval($_GET['product_id'] ?? 0);
    if ($product_id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid product ID.']);
        exit;
    }

    // Get current highest bid
    $stmtBid = $pdo->prepare("SELECT * FROM bids WHERE product_id = ? ORDER BY amount DESC LIMIT 1");
    $stmtBid->execute([$product_id]);
    $highest_bid = $stmtBid->fetch();

    if ($highest_bid) {
        echo json_encode([
            'has_bids' => true,
            'amount' => floatval($highest_bid['amount']),
            'bidder_id' => intval($highest_bid['bidder_id'])
        ]);
    } else {
        // Fallback to product starting price
        $stmtProduct = $pdo->prepare("SELECT price FROM products WHERE id = ?");
        $stmtProduct->execute([$product_id]);
        $product = $stmtProduct->fetch();

        if ($product) {
            echo json_encode([
                'has_bids' => false,
                'amount' => floatval($product['price']),
                'bidder_id' => null
            ]);
        } else {
            http_response_code(404);
            echo json_encode(['error' => 'Product not found.']);
        }
    }
    exit;
}

if ($method === 'POST') {
    if (!$user_id) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized. Please sign in.']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $product_id = intval($input['product_id'] ?? 0);
    $amount = floatval($input['amount'] ?? 0);

    if ($product_id <= 0 || $amount <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid product or bid amount.']);
        exit;
    }

    // Get product details
    $stmtProduct = $pdo->prepare("SELECT * FROM products WHERE id = ?");
    $stmtProduct->execute([$product_id]);
    $product = $stmtProduct->fetch();

    if (!$product) {
        http_response_code(404);
        echo json_encode(['error' => 'Product not found.']);
        exit;
    }

    if ($product['status'] !== 'active') {
        http_response_code(400);
        echo json_encode(['error' => 'This listing is no longer active.']);
        exit;
    }

    if ($product['listing_type'] !== 'auction') {
        http_response_code(400);
        echo json_encode(['error' => 'This product is not an auction listing.']);
        exit;
    }

    // Check if auction ended
    if ($product['auction_ends_at'] && strtotime($product['auction_ends_at']) < time()) {
        http_response_code(400);
        echo json_encode(['error' => 'This auction has already ended.']);
        exit;
    }

    // Check owner
    if ($product['seller_id'] === $user_id) {
        http_response_code(400);
        echo json_encode(['error' => 'You cannot bid on your own listing.']);
        exit;
    }

    // Get current highest bid
    $stmtBid = $pdo->prepare("SELECT amount FROM bids WHERE product_id = ? ORDER BY amount DESC LIMIT 1");
    $stmtBid->execute([$product_id]);
    $highest_bid = $stmtBid->fetch();

    $current_val = $highest_bid ? floatval($highest_bid['amount']) : floatval($product['price']);
    $min_increment = 50.00; // minimum R50 increment
    $min_required = $current_val + $min_increment;

    // Wait, if there are no bids yet, is the starting price valid as a bid, or does it have to be +50?
    // Let's say if it's the first bid, they must bid at least the starting price, otherwise starting price + 50.
    if (!$highest_bid) {
        $min_required = $current_val; // first bidder can bid exactly the starting price
    }

    if ($amount < $min_required) {
        http_response_code(400);
        echo json_encode(['error' => "Bid amount must be at least R" . number_format($min_required, 2)]);
        exit;
    }

    try {
        $pdo->beginTransaction();

        // 1. Insert Bid
        $stmtInsert = $pdo->prepare("INSERT INTO bids (product_id, bidder_id, amount) VALUES (?, ?, ?)");
        $stmtInsert->execute([$product_id, $user_id, $amount]);

        // 2. Update Product Price (represents the current highest bid)
        $stmtUpdatePrice = $pdo->prepare("UPDATE products SET price = ? WHERE id = ?");
        $stmtUpdatePrice->execute([$amount, $product_id]);

        $pdo->commit();
        echo json_encode(['success' => true, 'message' => 'Bid placed successfully!', 'new_price' => $amount]);

    } catch (Exception $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['error' => 'Failed to place bid: ' . $e->getMessage()]);
    }
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed.']);
exit;
