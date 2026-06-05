<?php
// api/products.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/db.php';

$method = $_SERVER['REQUEST_METHOD'];

// Helper to sanitize filename
function sanitize_file_name($filename) {
    $filename = preg_replace('/[^a-zA-Z0-9-_\.]/', '', $filename);
    return time() . '_' . $filename;
}

if ($method === 'GET') {
    if (isset($_GET['id'])) {
        // Fetch single product
        $stmt = $pdo->prepare("
            SELECT p.*, u.full_name AS seller_name, u.seller_status 
            FROM products p 
            JOIN users u ON p.seller_id = u.id 
            WHERE p.id = ?
        ");
        $stmt->execute([$_GET['id']]);
        $product = $stmt->fetch();
        
        if ($product) {
            echo json_encode($product);
        } else {
            http_response_code(404);
            echo json_encode(['error' => 'Product not found.']);
        }
        exit;
    }

    // Check if showing only logged in seller's listings
    if (isset($_GET['seller']) && $_GET['seller'] === 'me') {
        if (!isset($_SESSION['user_id'])) {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized. Please sign in.']);
            exit;
        }
        $stmt = $pdo->prepare("SELECT * FROM products WHERE seller_id = ? ORDER BY id DESC");
        $stmt->execute([$_SESSION['user_id']]);
        echo json_encode($stmt->fetchAll());
        exit;
    }

    // Standard list products with optional filters
    $search = $_GET['search'] ?? '';
    $category = $_GET['category'] ?? '';
    $type = $_GET['type'] ?? ''; // 'fixed' or 'auction'
    $condition = $_GET['condition'] ?? ''; // 'New', 'Like New', 'Good', 'Fair'

    $query = "
        SELECT p.*, u.full_name AS seller_name, u.seller_status 
        FROM products p 
        JOIN users u ON p.seller_id = u.id 
        WHERE p.status = 'active'
    ";
    $params = [];

    if (!empty($search)) {
        $query .= " AND (p.title LIKE ? OR p.description LIKE ?)";
        $params[] = '%' . $search . '%';
        $params[] = '%' . $search . '%';
    }
    if (!empty($category)) {
        $query .= " AND p.category = ?";
        $params[] = $category;
    }
    if (!empty($type)) {
        $query .= " AND p.listing_type = ?";
        $params[] = $type;
    }
    if (!empty($condition)) {
        $query .= " AND p.condition_grade = ?";
        $params[] = $condition;
    }

    $query .= " ORDER BY p.id DESC";

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $products = $stmt->fetchAll();

    echo json_encode($products);
    exit;
}

if ($method === 'POST') {
    // Add product (multipart/form-data)
    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized. Please sign in.']);
        exit;
    }

    if ($_SESSION['role'] !== 'seller' || ($_SESSION['seller_status'] ?? '') !== 'approved') {
        http_response_code(403);
        echo json_encode(['error' => 'Only approved sellers can list products.']);
        exit;
    }

    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $category = trim($_POST['category'] ?? '');
    $condition_grade = trim($_POST['condition_grade'] ?? '');
    $price = floatval($_POST['price'] ?? 0);
    $listing_type = $_POST['listing_type'] ?? 'fixed';

    if (empty($title) || empty($description) || empty($category) || empty($condition_grade) || $price <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'All product details (title, description, category, condition, price) are required.']);
        exit;
    }

    if (!in_array($condition_grade, ['New', 'Like New', 'Good', 'Fair'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid condition grade.']);
        exit;
    }

    if (!in_array($listing_type, ['fixed', 'auction'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid listing type.']);
        exit;
    }

    $auction_hours = null;
    $auction_ends_at = null;

    if ($listing_type === 'auction') {
        $auction_hours = intval($_POST['auction_hours'] ?? 24);
        if ($auction_hours <= 0) {
            $auction_hours = 24;
        }
        $auction_ends_at = date('Y-m-d H:i:s', strtotime("+$auction_hours hours"));
    }

    // Image upload check
    if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(['error' => 'Product image upload is required.']);
        exit;
    }

    $allowed_types = ['image/png', 'image/jpeg', 'image/jpg', 'image/webp'];
    if (!in_array($_FILES['image']['type'], $allowed_types)) {
        http_response_code(400);
        echo json_encode(['error' => 'Only PNG, JPG, and WEBP image files are allowed.']);
        exit;
    }

    $upload_dir = __DIR__ . '/../uploads/products/';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }

    $safe_filename = sanitize_file_name($_FILES['image']['name']);
    $dest_path = $upload_dir . $safe_filename;

    if (!move_uploaded_file($_FILES['image']['tmp_name'], $dest_path)) {
        http_response_code(500);
        echo json_encode(['error' => 'Failed to save product image.']);
        exit;
    }

    $image_path = 'uploads/products/' . $safe_filename;

    $stmt = $pdo->prepare("
        INSERT INTO products (seller_id, title, description, category, condition_grade, price, listing_type, auction_hours, auction_ends_at, image_path, status) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')
    ");
    $stmt->execute([
        $_SESSION['user_id'],
        $title,
        $description,
        $category,
        $condition_grade,
        $price,
        $listing_type,
        $auction_hours,
        $auction_ends_at,
        $image_path
    ]);

    $new_id = $pdo->lastInsertId();

    // If it's an auction, seed the initial bid using starting price
    if ($listing_type === 'auction') {
        $stmtBid = $pdo->prepare("INSERT INTO bids (product_id, bidder_id, amount) VALUES (?, ?, ?)");
        $stmtBid->execute([$new_id, $_SESSION['user_id'], $price]);
    }

    echo json_encode(['success' => true, 'message' => 'Listing added successfully.', 'product_id' => $new_id]);
    exit;
}

if ($method === 'PUT') {
    // Mark sold or edit (JSON input)
    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized. Please sign in.']);
        exit;
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $product_id = intval($input['id'] ?? 0);
    $action = $input['action'] ?? '';

    if ($product_id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid product ID.']);
        exit;
    }

    // Verify ownership
    $stmt = $pdo->prepare("SELECT seller_id FROM products WHERE id = ?");
    $stmt->execute([$product_id]);
    $product = $stmt->fetch();

    if (!$product) {
        http_response_code(404);
        echo json_encode(['error' => 'Product not found.']);
        exit;
    }

    if ($product['seller_id'] !== $_SESSION['user_id']) {
        http_response_code(403);
        echo json_encode(['error' => 'You do not own this listing.']);
        exit;
    }

    if ($action === 'mark_sold') {
        $stmt = $pdo->prepare("UPDATE products SET status = 'sold' WHERE id = ?");
        $stmt->execute([$product_id]);
        echo json_encode(['success' => true, 'message' => 'Listing marked as sold.']);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Invalid action.']);
    exit;
}

if ($method === 'DELETE') {
    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized. Please sign in.']);
        exit;
    }

    $product_id = intval($_GET['id'] ?? 0);

    if ($product_id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid product ID.']);
        exit;
    }

    // Verify ownership
    $stmt = $pdo->prepare("SELECT seller_id FROM products WHERE id = ?");
    $stmt->execute([$product_id]);
    $product = $stmt->fetch();

    if (!$product) {
        http_response_code(404);
        echo json_encode(['error' => 'Product not found.']);
        exit;
    }

    if ($product['seller_id'] !== $_SESSION['user_id']) {
        http_response_code(403);
        echo json_encode(['error' => 'You do not own this listing.']);
        exit;
    }

    $stmt = $pdo->prepare("DELETE FROM products WHERE id = ?");
    $stmt->execute([$product_id]);

    echo json_encode(['success' => true, 'message' => 'Listing deleted successfully.']);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed.']);
exit;
