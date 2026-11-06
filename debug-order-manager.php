<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Start session and setup
session_start();
require_once 'config/database.php';
require_once 'includes/orders.php';

echo "<h2>Debug OrderManager::updateOrderStatus Method</h2>";

try {
    $_SESSION['user_id'] = 1; // Set admin user for testing
    
    // Test direct database functions first
    echo "<h3>Testing Database Functions:</h3>";
    
    $pdo = getDBConnection();
    if ($pdo) {
        echo "<p style='color: green;'>✓ Database connection successful</p>";
    } else {
        echo "<p style='color: red;'>✗ Database connection failed</p>";
        exit;
    }
    
    // Test fetchOne function
    $testOrder = fetchOne("SELECT id, status, customer_id FROM orders LIMIT 1");
    if ($testOrder) {
        echo "<p style='color: green;'>✓ fetchOne function works</p>";
        echo "<p>Test order: ID {$testOrder['id']}, Status: {$testOrder['status']}</p>";
    } else {
        echo "<p style='color: red;'>✗ fetchOne function failed or no orders found</p>";
        exit;
    }
    
    // Test updateData function directly
    echo "<h3>Testing updateData Function:</h3>";
    $testUpdateResult = updateData("UPDATE orders SET updated_at = NOW() WHERE id = ?", [$testOrder['id']]);
    if ($testUpdateResult) {
        echo "<p style='color: green;'>✓ updateData function works</p>";
    } else {
        echo "<p style='color: red;'>✗ updateData function failed</p>";
    }
    
    // Test OrderManager class instantiation
    echo "<h3>Testing OrderManager Class:</h3>";
    $orders = new OrderManager();
    echo "<p style='color: green;'>✓ OrderManager class created successfully</p>";
    
    // Test updateOrderStatus method with current status (should be no-op)
    echo "<h3>Testing updateOrderStatus Method:</h3>";
    echo "<p>Attempting to update order {$testOrder['id']} from '{$testOrder['status']}' to 'processing'</p>";
    
    $result = $orders->updateOrderStatus($testOrder['id'], 'processing', 'Test notification update');
    
    if ($result) {
        echo "<p style='color: green;'>✓ updateOrderStatus successful!</p>";
        
        // Check if notification was created
        $notification = fetchOne("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 1", [$testOrder['customer_id']]);
        
        if ($notification) {
            echo "<p style='color: green;'>✓ Notification created:</p>";
            echo "<ul>";
            echo "<li>Title: {$notification['title']}</li>";
            echo "<li>Message: {$notification['message']}</li>";
            echo "<li>Type: {$notification['type']}</li>";
            echo "</ul>";
        } else {
            echo "<p style='color: orange;'>⚠ No notification found for customer {$testOrder['customer_id']}</p>";
        }
        
    } else {
        echo "<p style='color: red;'>✗ updateOrderStatus failed</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>Error: " . $e->getMessage() . "</p>";
    echo "<pre>" . $e->getTraceAsString() . "</pre>";
}
?>