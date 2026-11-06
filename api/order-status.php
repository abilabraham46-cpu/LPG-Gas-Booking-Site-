<?php
/**
 * Order Status API - Check order status and cancellation eligibility
 * Provides real-time information about order status and whether it can be cancelled
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type');

session_start();
require_once '../config/database.php';
require_once '../includes/auth.php';

try {
    // Check if user is logged in
    $auth = new Auth();
    if (!$auth->isLoggedIn()) {
        throw new Exception('User not authenticated');
    }
    
    $current_user = $auth->getCurrentUser();
    $customer_id = $current_user['id'];
    
    // Get order ID from request
    $order_id = filter_var($_GET['order_id'] ?? 0, FILTER_VALIDATE_INT);
    
    if (!$order_id) {
        throw new Exception('Invalid order ID');
    }
    
    // Get order details
    $order_sql = "SELECT o.*, u.name as customer_name 
                  FROM orders o 
                  JOIN users u ON o.customer_id = u.id 
                  WHERE o.id = ? AND o.customer_id = ?";
    $order_stmt = executeQuery($order_sql, [$order_id, $customer_id]);
    $order = $order_stmt->fetch();
    
    if (!$order) {
        throw new Exception('Order not found');
    }
    
    // Check if order can be cancelled
    $can_cancel = in_array($order['status'], ['pending', 'confirmed']);
    $cancel_reason = '';
    
    if (!$can_cancel) {
        $cancel_reason = 'Order status is ' . ucwords(str_replace('_', ' ', $order['status']));
    } else {
        // Check if delivery person is assigned
        $delivery_check_sql = "SELECT d.delivery_person_id, u.name as delivery_person_name 
                              FROM deliveries d 
                              LEFT JOIN users u ON d.delivery_person_id = u.id 
                              WHERE d.order_id = ? AND d.delivery_person_id IS NOT NULL";
        $delivery_stmt = executeQuery($delivery_check_sql, [$order_id]);
        $delivery_assigned = $delivery_stmt->fetch();
        
        if ($delivery_assigned) {
            $can_cancel = false;
            $cancel_reason = 'A delivery person has been assigned to this order';
        }
    }
    
    // Prepare response
    $response = [
        'success' => true,
        'order' => [
            'id' => $order['id'],
            'order_number' => $order['order_number'] ?? $order['id'],
            'status' => $order['status'],
            'status_display' => ucwords(str_replace('_', ' ', $order['status'])),
            'cylinder_type' => $order['cylinder_type'],
            'quantity' => $order['quantity'],
            'total_amount' => $order['total_amount'],
            'delivery_date' => $order['delivery_date'],
            'created_at' => $order['created_at']
        ],
        'can_cancel' => $can_cancel,
        'cancel_reason' => $cancel_reason,
        'delivery_assigned' => isset($delivery_assigned) ? $delivery_assigned : null
    ];
    
    echo json_encode($response);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>