<?php
/**
 * Enhanced Real-time Notifications System
 * Server-Sent Events for live updates
 */

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Access-Control-Allow-Origin: *');

require_once '../config/database.php';
require_once '../includes/auth.php';

// Check authentication
session_start();
$auth = new Auth();

if (!$auth->isLoggedIn()) {
    echo "data: " . json_encode(['error' => 'Unauthorized']) . "\n\n";
    exit;
}

$user = $auth->getCurrentUser();
$user_id = $user['id'];
$role = $user['role'];

// Function to send SSE data
function sendSSE($id, $data) {
    echo "id: $id\n";
    echo "data: " . json_encode($data) . "\n\n";
    ob_flush();
    flush();
}

// Get notifications based on user role
try {
    $notifications = [];
    
    if ($role === 'customer') {
        // Customer notifications: from notifications table
        // First check if notifications table exists
        $checkTable = "SHOW TABLES LIKE 'notifications'";
        $tableExists = executeQuery($checkTable);
        
        if ($tableExists->rowCount() > 0) {
            // Use notifications table
            $sql = "SELECT n.id, n.title, n.message, n.type, n.order_id, n.created_at,
                           TIMESTAMPDIFF(SECOND, n.created_at, NOW()) as seconds_ago
                    FROM notifications n
                    WHERE n.user_id = ? 
                    AND n.is_read = FALSE
                    AND n.created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
                    ORDER BY n.created_at DESC LIMIT 5";
            
            $stmt = executeQuery($sql, [$user_id]);
            $notifs = $stmt->fetchAll();
            
            foreach ($notifs as $notif) {
                $notifications[] = [
                    'id' => $notif['id'],
                    'type' => $notif['type'],
                    'title' => $notif['title'],
                    'message' => $notif['message'],
                    'order_id' => $notif['order_id'],
                    'timestamp' => strtotime($notif['created_at'])
                ];
            }
        } else {
            // Fallback: use orders table
            $sql = "SELECT o.id, o.status, o.delivery_date, o.cylinder_type, o.quantity,
                           d.status as delivery_status, d.delivery_time,
                           TIMESTAMPDIFF(MINUTE, o.updated_at, NOW()) as minutes_ago
                    FROM orders o 
                    LEFT JOIN deliveries d ON o.id = d.order_id 
                    WHERE o.customer_id = ? 
                    AND o.updated_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
                    ORDER BY o.updated_at DESC LIMIT 5";
            
            $stmt = executeQuery($sql, [$user_id]);
            $orders = $stmt->fetchAll();
            
            foreach ($orders as $order) {
                $notifications[] = [
                    'type' => 'order_update',
                    'title' => 'Order Update',
                    'message' => "Your {$order['cylinder_type']} order is now {$order['status']}",
                    'data' => $order,
                    'timestamp' => time()
                ];
            }
        }
        
    } elseif ($role === 'delivery') {
        // Delivery notifications: new assignments, route updates
        $sql = "SELECT d.id, d.status, o.delivery_address, o.contact_number,
                       o.cylinder_type, o.quantity, o.delivery_date,
                       TIMESTAMPDIFF(MINUTE, d.updated_at, NOW()) as minutes_ago
                FROM deliveries d 
                JOIN orders o ON d.order_id = o.id 
                WHERE d.delivery_person_id = ? 
                AND d.updated_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
                ORDER BY d.updated_at DESC LIMIT 5";
        
        $stmt = executeQuery($sql, [$user_id]);
        $deliveries = $stmt->fetchAll();
        
        foreach ($deliveries as $delivery) {
            $notifications[] = [
                'type' => 'delivery_update',
                'title' => 'Delivery Assignment',
                'message' => "New delivery: {$delivery['cylinder_type']} to {$delivery['delivery_address']}",
                'data' => $delivery,
                'timestamp' => time()
            ];
        }
        
    } elseif ($role === 'admin') {
        // Admin notifications: new orders, system alerts, analytics
        $sql = "SELECT 'new_order' as type, o.id, o.customer_id, u.name as customer_name,
                       o.cylinder_type, o.quantity, o.total_amount,
                       TIMESTAMPDIFF(MINUTE, o.created_at, NOW()) as minutes_ago
                FROM orders o 
                JOIN users u ON o.customer_id = u.id 
                WHERE o.created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
                ORDER BY o.created_at DESC LIMIT 3";
        
        $stmt = executeQuery($sql);
        $newOrders = $stmt->fetchAll();
        
        foreach ($newOrders as $order) {
            $notifications[] = [
                'type' => 'new_order',
                'title' => 'New Order Received',
                'message' => "New order from {$order['customer_name']} - {$order['cylinder_type']} (₹{$order['total_amount']})",
                'data' => $order,
                'timestamp' => time()
            ];
        }
        
        // System performance metrics
        $performance = [
            'active_users' => executeQuery("SELECT COUNT(*) as count FROM users WHERE status = 'active'")->fetch()['count'],
            'pending_orders' => executeQuery("SELECT COUNT(*) as count FROM orders WHERE status = 'pending'")->fetch()['count'],
            'today_revenue' => executeQuery("SELECT COALESCE(SUM(total_amount), 0) as revenue FROM orders WHERE DATE(created_at) = CURDATE() AND payment_status = 'paid'")->fetch()['revenue']
        ];
        
        $notifications[] = [
            'type' => 'system_metrics',
            'title' => 'System Performance',
            'message' => "Today's Revenue: ₹{$performance['today_revenue']}",
            'data' => $performance,
            'timestamp' => time()
        ];
    }
    
    // Send notifications
    foreach ($notifications as $index => $notification) {
        sendSSE($index, $notification);
    }
    
    // Keep connection alive
    sendSSE('heartbeat', ['type' => 'heartbeat', 'timestamp' => time()]);
    
} catch (Exception $e) {
    sendSSE('error', ['error' => 'Database error: ' . $e->getMessage()]);
}
?>