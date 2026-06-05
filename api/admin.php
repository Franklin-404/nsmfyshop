<?php
// api/admin.php
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

// Require admin login
if (!$user_id || ($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden. Admin access required.']);
    exit;
}

if ($method === 'GET') {
    // 1. Fetch pending sellers
    $stmtSellers = $pdo->prepare("
        SELECT id, full_name, email, phone, sa_id_number, id_doc_path, created_at 
        FROM users 
        WHERE role = 'seller' AND seller_status = 'pending'
        ORDER BY id ASC
    ");
    $stmtSellers->execute();
    $pending_sellers = $stmtSellers->fetchAll();

    // 2. Fetch stats
    // Approved sellers
    $stmtStatsSellers = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'seller' AND seller_status = 'approved'");
    $approved_sellers_count = $stmtStatsSellers->fetchColumn();

    // Active listings
    $stmtStatsListings = $pdo->query("SELECT COUNT(*) FROM products WHERE status = 'active'");
    $active_listings_count = $stmtStatsListings->fetchColumn();

    // Order statistics
    $stmtStatsOrders = $pdo->query("SELECT COUNT(*), COALESCE(SUM(total), 0) FROM orders");
    $order_stats = $stmtStatsOrders->fetch();
    $orders_count = $order_stats[0];
    $total_sales = floatval($order_stats[1]);

    echo json_encode([
        'pending_sellers' => $pending_sellers,
        'stats' => [
            'approved_sellers' => intval($approved_sellers_count),
            'active_listings' => intval($active_listings_count),
            'orders_count' => intval($orders_count),
            'total_sales' => $total_sales
        ]
    ]);
    exit;
}

if ($method === 'POST') {
    // Approve/reject action
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $seller_id = intval($input['seller_id'] ?? 0);
    $action = $input['action'] ?? '';

    if ($seller_id <= 0 || !in_array($action, ['approve', 'reject'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid seller ID or action.']);
        exit;
    }

    // Check if user exists and is a pending seller
    $stmt = $pdo->prepare("SELECT role, seller_status FROM users WHERE id = ?");
    $stmt->execute([$seller_id]);
    $user = $stmt->fetch();

    if (!$user || $user['role'] !== 'seller' || $user['seller_status'] !== 'pending') {
        http_response_code(404);
        echo json_encode(['error' => 'Pending seller not found.']);
        exit;
    }

    $new_status = ($action === 'approve') ? 'approved' : 'rejected';
    
    $stmtUpdate = $pdo->prepare("UPDATE users SET seller_status = ? WHERE id = ?");
    $stmtUpdate->execute([$new_status, $seller_id]);

    echo json_encode([
        'success' => true, 
        'message' => 'Seller application ' . ($action === 'approve' ? 'approved' : 'rejected') . ' successfully.'
    ]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed.']);
exit;
