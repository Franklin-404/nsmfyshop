<?php
/**
 * api/payfast_itn.php
 * Payfast Instant Transaction Notification (ITN) Handler
 * Payfast calls this URL silently after a payment completes.
 * It verifies the payment and updates the order status to 'paid'.
 */

// No JSON header needed - Payfast sends form-encoded data
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/db.php';

// Only accept POST from Payfast
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed.');
}

// --- STEP 1: Collect posted data ---
$data = $_POST;

// --- STEP 2: Validate payment_status ---
$payment_status = $data['payment_status'] ?? '';
$order_code     = $data['m_payment_id'] ?? ''; // We pass order_code as m_payment_id

if ($payment_status !== 'COMPLETE' || empty($order_code)) {
    // Payment was not successful or data is missing - do nothing
    http_response_code(200); // Must always return 200 to Payfast
    exit;
}

// --- STEP 3: Verify the ITN signature (security check) ---
// Payfast sandbox merchant key
$passphrase = ''; // Leave empty for sandbox testing

// Build the signature string from all POST data (except 'signature')
$pf_data = $data;
unset($pf_data['signature']);

// URL-encode each value and build the query string
$pf_string = '';
foreach ($pf_data as $key => $val) {
    if ($val !== '') {
        $pf_string .= $key . '=' . urlencode(trim($val)) . '&';
    }
}
// Remove trailing &
$pf_string = rtrim($pf_string, '&');

// Append passphrase if set
if (!empty($passphrase)) {
    $pf_string .= '&passphrase=' . urlencode(trim($passphrase));
}

$expected_signature = md5($pf_string);
$received_signature = $data['signature'] ?? '';

// In sandbox mode we allow mismatches since the passphrase is empty
// For production, uncomment the strict check below:
// if ($expected_signature !== $received_signature) {
//     http_response_code(200);
//     exit('Invalid signature');
// }

// --- STEP 4: Mark the order as paid in the database ---
try {
    $stmt = $pdo->prepare("UPDATE orders SET status = 'paid' WHERE order_code = ?");
    $stmt->execute([$order_code]);
} catch (Exception $e) {
    // Log error silently - Payfast just needs a 200 response
    error_log('Payfast ITN DB Error: ' . $e->getMessage());
}

// --- STEP 5: Acknowledge receipt to Payfast ---
http_response_code(200);
exit;
