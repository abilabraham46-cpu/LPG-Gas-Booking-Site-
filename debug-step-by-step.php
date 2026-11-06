<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Start session and setup
session_start();
require_once 'config/database.php';
require_once 'includes/orders.php';

echo "<h2>Step-by-Step OrderManager Debug</h2>";

try {
    $orderId = 1;
    $status = 'pending';
    $notes = 'Test debug update';
    $userId = 1;
    
    echo "<h3>Step 1: Check Order Existence</h3>";
    $checkSql = "SELECT status, customer_id FROM orders WHERE id = ?";
    $currentOrder = fetchOne($checkSql, [$orderId]);
    
    if (!$currentOrder) {
        echo "<p style='color: red;'>✗ Order not found</p>";
        exit;
    } else {
        echo "<p style='color: green;'>✓ Order found - Status: '{$currentOrder['status']}', Customer: {$currentOrder['customer_id']}</p>";
    }
    
    echo "<h3>Step 2: Check if Order is Cancelled</h3>";
    if ($currentOrder['status'] === 'cancelled') {
        echo "<p style='color: red;'>✗ Order is cancelled</p>";
        exit;
    } else {
        echo "<p style='color: green;'>✓ Order is not cancelled</p>";
    }
    
    echo "<h3>Step 3: Prepare SQL Update</h3>";
    $sql = "UPDATE orders SET status = ?, notes = ?, updated_at = NOW() WHERE id = ?";
    $params = [$status, $notes, $orderId];
    echo "<p>SQL: $sql</p>";
    echo "<p>Params: " . implode(', ', $params) . "</p>";
    
    echo "<h3>Step 4: Execute Update</h3>";
    $result = updateData($sql, $params);
    echo "<p>Update result: $result (affected rows)</p>";
    
    if ($result > 0) {
        echo "<p style='color: green;'>✓ Update successful</p>";
        
        echo "<h3>Step 5: Test logActivity</h3>";
        try {
            logActivity('order_status_updated', "Order #$orderId status changed to $status", $userId);
            echo "<p style='color: green;'>✓ logActivity successful</p>";
        } catch (Exception $e) {
            echo "<p style='color: red;'>✗ logActivity failed: " . $e->getMessage() . "</p>";
        }
        
        echo "<h3>Step 6: Test Full updateOrderStatus Method</h3>";
        try {
            $orders = new OrderManager();
            $fullResult = $orders->updateOrderStatus($orderId, 'processing', $notes, $userId);
            echo "<p>Full method result: " . ($fullResult ? 'true' : 'false') . "</p>";
            
            if ($fullResult) {
                echo "<p style='color: green;'>✓ Full updateOrderStatus successful</p>";
                
                // Check for notifications
                $notification = fetchOne("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 1", [$currentOrder['customer_id']]);
                if ($notification) {
                    echo "<p style='color: green;'>✓ Notification created: {$notification['title']}</p>";
                } else {
                    echo "<p style='color: orange;'>⚠ No notification found</p>";
                }
            } else {
                echo "<p style='color: red;'>✗ Full updateOrderStatus failed</p>";
            }
        } catch (Exception $e) {
            echo "<p style='color: red;'>✗ Full updateOrderStatus failed: " . $e->getMessage() . "</p>";
        }
        
    } else {
        echo "<p style='color: red;'>✗ Update failed</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>Error: " . $e->getMessage() . "</p>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
}
?>