<?php
// api/tracking.php
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

if (!$user_id || ($_SESSION['role'] ?? '') !== 'seller') {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden. Seller account required.']);
    exit;
}

if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $order_id = intval($input['order_id'] ?? 0);
    $courier = trim($input['courier'] ?? '');
    $tracking_number = trim($input['tracking_number'] ?? '');

    if ($order_id <= 0 || empty($courier) || empty($tracking_number)) {
        http_response_code(400);
        echo json_encode(['error' => 'Order ID, courier name, and tracking number are required.']);
        exit;
    }

    // Verify order contains items owned by this seller
    $stmtCheck = $pdo->prepare("
        SELECT COUNT(*) 
        FROM order_items oi
        JOIN products p ON oi.product_id = p.id
        WHERE oi.order_id = ? AND p.seller_id = ?
    ");
    $stmtCheck->execute([$order_id, $user_id]);
    $has_item = $stmtCheck->fetchColumn() > 0;

    if (!$has_item) {
        http_response_code(403);
        echo json_encode(['error' => 'This order does not contain any of your product listings.']);
        exit;
    }

    try {
        $pdo->beginTransaction();

        // 1. Insert/Update Tracking information
        // Check if tracking already exists for this order & seller
        $stmtTrackCheck = $pdo->prepare("SELECT id FROM tracking WHERE order_id = ? AND seller_id = ?");
        $stmtTrackCheck->execute([$order_id, $user_id]);
        $existingTrack = $stmtTrackCheck->fetch();

        if ($existingTrack) {
            $stmtUpdate = $pdo->prepare("
                UPDATE tracking 
                SET courier = ?, tracking_number = ?, submitted_at = CURRENT_TIMESTAMP 
                WHERE id = ?
            ");
            $stmtUpdate->execute([$courier, $tracking_number, $existingTrack['id']]);
        } else {
            $stmtInsert = $pdo->prepare("
                INSERT INTO tracking (order_id, seller_id, courier, tracking_number) 
                VALUES (?, ?, ?, ?)
            ");
            $stmtInsert->execute([$order_id, $user_id, $courier, $tracking_number]);
        }

        // 2. Update Order Status to 'shipped'
        $stmtUpdateOrder = $pdo->prepare("UPDATE orders SET status = 'shipped' WHERE id = ?");
        $stmtUpdateOrder->execute([$order_id]);

        $pdo->commit();
        echo json_encode(['success' => true, 'message' => 'Tracking number submitted successfully. Order status updated to shipped.']);

    } catch (Exception $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['error' => 'Failed to submit tracking info: ' . $e->getMessage()]);
    }
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed.']);
exit;
