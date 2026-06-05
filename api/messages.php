<?php
// api/messages.php
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

if (!$user_id) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized. Please sign in.']);
    exit;
}

if ($method === 'GET') {
    if (isset($_GET['my_threads']) && $_GET['my_threads'] == 'true') {
        // Get all unique chat threads for the logged-in seller
        $stmt = $pdo->prepare("
            SELECT DISTINCT m.product_id, p.title AS product_title, 
                   IF(m.sender_id = ?, m.recipient_id, m.sender_id) AS buyer_id,
                   u.full_name AS buyer_name
            FROM messages m
            JOIN products p ON m.product_id = p.id
            JOIN users u ON u.id = IF(m.sender_id = ?, m.recipient_id, m.sender_id)
            WHERE p.seller_id = ? AND u.id != ?
            ORDER BY m.id DESC
        ");
        $stmt->execute([$user_id, $user_id, $user_id, $user_id]);
        echo json_encode($stmt->fetchAll());
        exit;
    }

    $product_id = intval($_GET['product_id'] ?? 0);
    $other_user_id = intval($_GET['other_user_id'] ?? 0);

    if ($product_id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid product ID.']);
        exit;
    }

    // Determine the recipient (other user)
    // If not provided, find the seller of the product
    if ($other_user_id <= 0) {
        $stmtP = $pdo->prepare("SELECT seller_id FROM products WHERE id = ?");
        $stmtP->execute([$product_id]);
        $prod = $stmtP->fetch();
        if (!$prod) {
            http_response_code(404);
            echo json_encode(['error' => 'Product not found.']);
            exit;
        }
        $other_user_id = intval($prod['seller_id']);
    }

    // Fetch conversation messages
    $stmt = $pdo->prepare("
        SELECT m.*, sender.full_name AS sender_name, recipient.full_name AS recipient_name 
        FROM messages m 
        JOIN users sender ON m.sender_id = sender.id 
        JOIN users recipient ON m.recipient_id = recipient.id 
        WHERE m.product_id = ? 
          AND ((m.sender_id = ? AND m.recipient_id = ?) OR (m.sender_id = ? AND m.recipient_id = ?)) 
        ORDER BY m.id ASC
    ");
    $stmt->execute([$product_id, $user_id, $other_user_id, $other_user_id, $user_id]);
    echo json_encode($stmt->fetchAll());
    exit;
}

if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $product_id = intval($input['product_id'] ?? 0);
    $recipient_id = intval($input['recipient_id'] ?? 0);
    $message_text = trim($input['message_text'] ?? '');

    if ($product_id <= 0 || empty($message_text)) {
        http_response_code(400);
        echo json_encode(['error' => 'Product ID and message text are required.']);
        exit;
    }

    // If recipient is not provided, default to the seller of the product
    if ($recipient_id <= 0) {
        $stmtP = $pdo->prepare("SELECT seller_id FROM products WHERE id = ?");
        $stmtP->execute([$product_id]);
        $prod = $stmtP->fetch();
        if (!$prod) {
            http_response_code(404);
            echo json_encode(['error' => 'Product not found.']);
            exit;
        }
        $recipient_id = intval($prod['seller_id']);
    }

    // Insert message
    $stmt = $pdo->prepare("
        INSERT INTO messages (product_id, sender_id, recipient_id, message_text) 
        VALUES (?, ?, ?, ?)
    ");
    $stmt->execute([$product_id, $user_id, $recipient_id, $message_text]);
    
    echo json_encode([
        'success' => true, 
        'message' => 'Message sent.', 
        'message_id' => $pdo->lastInsertId(),
        'timestamp' => date('H:i')
    ]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed.']);
exit;
