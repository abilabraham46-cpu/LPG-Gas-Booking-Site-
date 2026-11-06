<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Start session and setup
session_start();
require_once 'config/database.php';
require_once 'includes/orders.php';

echo "<h2>Debug Status Update Issue</h2>";

try {
    // Check the current order status
    $testOrder = fetchOne("SELECT id, status, customer_id FROM orders WHERE id = 1");
    echo "<h3>Current Order Status:</h3>";
    echo "<p>Order ID: {$testOrder['id']}, Status: '{$testOrder['status']}', Customer: {$testOrder['customer_id']}</p>";
    
    // Try updating to the same status to see what happens
    echo "<h3>Testing updateData with same status:</h3>";
    $result1 = updateData("UPDATE orders SET status = ?, updated_at = NOW() WHERE id = ?", [$testOrder['status'], $testOrder['id']]);
    echo "<p>updateData result for same status: $result1 (affected rows)</p>";
    
    // Try updating to a different status
    $newStatus = ($testOrder['status'] === 'pending') ? 'confirmed' : 'pending';
    echo "<h3>Testing updateData with different status ($newStatus):</h3>";
    $result2 = updateData("UPDATE orders SET status = ?, updated_at = NOW() WHERE id = ?", [$newStatus, $testOrder['id']]);
    echo "<p>updateData result for different status: $result2 (affected rows)</p>";
    
    // Check what the status is now
    $updatedOrder = fetchOne("SELECT status FROM orders WHERE id = 1");
    echo "<p>Order status after update: '{$updatedOrder['status']}'</p>";
    
    // Try the OrderManager method with the original status to restore it
    echo "<h3>Testing OrderManager::updateOrderStatus:</h3>";
    $orders = new OrderManager();
    $result3 = $orders->updateOrderStatus($testOrder['id'], $testOrder['status'], 'Test restore');
    echo "<p>OrderManager result: " . ($result3 ? 'true' : 'false') . "</p>";
    
    // Final status check
    $finalOrder = fetchOne("SELECT status FROM orders WHERE id = 1");
    echo "<p>Final order status: '{$finalOrder['status']}'</p>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>Error: " . $e->getMessage() . "</p>";
}
?>