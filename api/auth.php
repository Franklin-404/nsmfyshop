<?php
// api/auth.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
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

// Merge guest cart items into user account
function merge_guest_cart($pdo, $user_id) {
    $session_id = session_id();
    $stmtCart = $pdo->prepare("SELECT product_id, quantity FROM cart_items WHERE session_id = ? AND user_id IS NULL");
    $stmtCart->execute([$session_id]);
    $guestItems = $stmtCart->fetchAll();

    foreach ($guestItems as $item) {
        $stmtUserCheck = $pdo->prepare("SELECT id, quantity FROM cart_items WHERE user_id = ? AND product_id = ?");
        $stmtUserCheck->execute([$user_id, $item['product_id']]);
        $userItem = $stmtUserCheck->fetch();

        if ($userItem) {
            $newQty = $userItem['quantity'] + $item['quantity'];
            $stmtUpdateQty = $pdo->prepare("UPDATE cart_items SET quantity = ? WHERE id = ?");
            $stmtUpdateQty->execute([$newQty, $userItem['id']]);
        } else {
            $stmtInsertUser = $pdo->prepare("INSERT INTO cart_items (user_id, product_id, quantity) VALUES (?, ?, ?)");
            $stmtInsertUser->execute([$user_id, $item['product_id'], $item['quantity']]);
        }
    }

    $stmtDeleteGuest = $pdo->prepare("DELETE FROM cart_items WHERE session_id = ? AND user_id IS NULL");
    $stmtDeleteGuest->execute([$session_id]);
}

if ($method === 'GET') {
    // Check session
    if (isset($_SESSION['user_id'])) {
        echo json_encode([
            'logged_in' => true,
            'user' => [
                'id' => $_SESSION['user_id'],
                'full_name' => $_SESSION['full_name'],
                'email' => $_SESSION['email'],
                'role' => $_SESSION['role'],
                'seller_status' => $_SESSION['seller_status'] ?? 'none'
            ]
        ]);
    } else {
        echo json_encode(['logged_in' => false]);
    }
    exit;
}

if ($method === 'POST') {
    $action = $_POST['action'] ?? '';
    
    // If request is JSON, decode it (login/logout might be JSON, registration with file upload is Form-Data)
    if (empty($action)) {
        $input = json_decode(file_get_contents('php://input'), true);
        $action = $input['action'] ?? '';
    } else {
        $input = $_POST;
    }

    if ($action === 'register') {
        $full_name = trim($input['full_name'] ?? '');
        $email = trim($input['email'] ?? '');
        $password = $input['password'] ?? '';
        $phone = trim($input['phone'] ?? '');
        $role = $input['role'] ?? 'buyer';
        
        if (empty($full_name) || empty($email) || empty($password) || empty($phone)) {
            http_response_code(400);
            echo json_encode(['error' => 'All standard fields (name, email, password, phone) are required.']);
            exit;
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid email address.']);
            exit;
        }

        // Validate role
        if (!in_array($role, ['buyer', 'seller', 'admin'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid role selected.']);
            exit;
        }

        // Check if user exists
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            http_response_code(409);
            echo json_encode(['error' => 'An account with this email already exists.']);
            exit;
        }

        $seller_status = 'none';
        $sa_id_number = null;
        $id_doc_path = null;

        if ($role === 'seller') {
            $sa_id_number = trim($input['sa_id_number'] ?? '');
            if (empty($sa_id_number) || strlen($sa_id_number) !== 13 || !is_numeric($sa_id_number)) {
                http_response_code(400);
                echo json_encode(['error' => 'A valid 13-digit South African ID number is required for sellers.']);
                exit;
            }

            // Check if file is uploaded
            if (!isset($_FILES['id_doc']) || $_FILES['id_doc']['error'] !== UPLOAD_ERR_OK) {
                http_response_code(400);
                echo json_encode(['error' => 'Identity document upload is required for sellers.']);
                exit;
            }

            // Validate file type
            $allowed_types = ['application/pdf', 'image/png', 'image/jpeg', 'image/jpg'];
            $file_type = $_FILES['id_doc']['type'];
            if (!in_array($file_type, $allowed_types)) {
                http_response_code(400);
                echo json_encode(['error' => 'Only PDF, PNG, and JPG files are allowed.']);
                exit;
            }

            // Create dir if not exist
            $upload_dir = __DIR__ . '/../uploads/id_docs/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }

            $safe_filename = sanitize_file_name($_FILES['id_doc']['name']);
            $dest_path = $upload_dir . $safe_filename;
            if (!move_uploaded_file($_FILES['id_doc']['tmp_name'], $dest_path)) {
                http_response_code(500);
                echo json_encode(['error' => 'Failed to save uploaded identity document.']);
                exit;
            }
            $id_doc_path = 'uploads/id_docs/' . $safe_filename;
            $seller_status = 'pending';
        }

        if ($role === 'admin') {
            $admin_code = $input['admin_code'] ?? '';
            if ($admin_code !== 'admin123') {
                http_response_code(403);
                echo json_encode(['error' => 'Invalid admin security verification key.']);
                exit;
            }
        }

        // Hash password
        $password_hash = password_hash($password, PASSWORD_BCRYPT);

        // Insert user
        $stmt = $pdo->prepare("INSERT INTO users (full_name, email, password_hash, phone, role, seller_status, sa_id_number, id_doc_path) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$full_name, $email, $password_hash, $phone, $role, $seller_status, $sa_id_number, $id_doc_path]);
        $new_user_id = $pdo->lastInsertId();

        // Start session
        $_SESSION['user_id'] = $new_user_id;
        $_SESSION['full_name'] = $full_name;
        $_SESSION['email'] = $email;
        $_SESSION['role'] = $role;
        $_SESSION['seller_status'] = $seller_status;

        // Merge guest cart
        merge_guest_cart($pdo, $new_user_id);

        echo json_encode([
            'success' => true,
            'message' => 'Account registered successfully.',
            'user' => [
                'id' => $new_user_id,
                'full_name' => $full_name,
                'email' => $email,
                'role' => $role,
                'seller_status' => $seller_status
            ]
        ]);
        exit;
    }

    if ($action === 'login') {
        $email = trim($input['email'] ?? '');
        $password = $input['password'] ?? '';

        if (empty($email) || empty($password)) {
            http_response_code(400);
            echo json_encode(['error' => 'Email and password are required.']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            http_response_code(401);
            echo json_encode(['error' => 'Invalid email or password.']);
            exit;
        }

        // Start session
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['full_name'] = $user['full_name'];
        $_SESSION['email'] = $user['email'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['seller_status'] = $user['seller_status'];

        // Merge guest cart
        merge_guest_cart($pdo, $user['id']);

        echo json_encode([
            'success' => true,
            'message' => 'Logged in successfully.',
            'user' => [
                'id' => $user['id'],
                'full_name' => $user['full_name'],
                'email' => $user['email'],
                'role' => $user['role'],
                'seller_status' => $user['seller_status']
            ]
        ]);
        exit;
    }

    if ($action === 'logout') {
        // Destroy session
        $_SESSION = [];
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
        session_destroy();
        echo json_encode(['success' => true, 'message' => 'Logged out successfully.']);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Invalid action.']);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed.']);
exit;
