<?php
session_start();
require_once '../config/database.php';
require_once '../includes/auth.php';

header('Content-Type: application/json');

$auth = new Auth();
if (!$auth->isLoggedIn() || $auth->getCurrentUser()['role'] !== 'customer') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Login required']);
    exit;
}

$current_user = $auth->getCurrentUser();
$customer_id = $current_user['id'];

$data = json_decode(file_get_contents('php://input'), true);
$orderId = (int)($data['order_id'] ?? 0);

if ($orderId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid order ID']);
    exit;
}

try {
    // Verify order belongs to customer and is not cancelled
    $stmt = executeQuery("SELECT id, status, payment_status FROM orders WHERE id = ? AND customer_id = ?", [$orderId, $customer_id]);
    $order = $stmt->fetch();
    
    if (!$order) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Order not found']);
        exit;
    }
    
    if ($order['status'] === 'cancelled') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Cannot set payment method for cancelled order']);
        exit;
    }
    
    // Update payment method to COD
    executeQuery("UPDATE orders SET payment_method = 'cod' WHERE id = ? AND customer_id = ?", [$orderId, $customer_id]);
    
    // Add to order history
    executeQuery("INSERT INTO order_history (order_id, status, changed_by, notes, created_at) VALUES (?, 'payment_method_set', ?, 'Payment method set to Cash on Delivery', NOW())", [$orderId, $customer_id]);
    
    echo json_encode(['success' => true, 'message' => 'Cash on Delivery preference set successfully']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
?>