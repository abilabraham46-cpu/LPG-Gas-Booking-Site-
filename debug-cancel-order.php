<?php
/**
 * Debug Order Cancellation Issues
 * This script helps identify why order cancellation might be failing
 */

session_start();
require_once 'config/database.php';
require_once 'includes/auth.php';
require_once 'includes/orders.php';

echo "<h2>Order Cancellation Debug Tool</h2>\n";
echo "<div style='font-family: Arial, sans-serif; padding: 20px; background: #f8f9fa; margin: 20px; border-radius: 10px;'>\n";

try {
    // Check if user is logged in
    $auth = new Auth();
    if (!$auth->isLoggedIn()) {
        echo "<p style='color: red;'>❌ User not logged in. Please log in first.</p>\n";
        echo "<a href='customer-auth.php'>Login Here</a>\n";
        exit;
    }
    
    $current_user = $auth->getCurrentUser();
    $customer_id = $current_user['id'];
    
    echo "<p style='color: green;'>✅ User logged in: {$current_user['name']} (ID: {$customer_id})</p>\n";
    
    // Check database connection
    $pdo = getDBConnection();
    echo "<p style='color: green;'>✅ Database connection successful</p>\n";
    
    // Get user's orders
    $orders_sql = "SELECT * FROM orders WHERE customer_id = ? ORDER BY created_at DESC LIMIT 5";
    $orders_stmt = $pdo->prepare($orders_sql);
    $orders_stmt->execute([$customer_id]);
    $orders = $orders_stmt->fetchAll();
    
    echo "<h3>Your Recent Orders:</h3>\n";
    if (empty($orders)) {
        echo "<p style='color: orange;'>⚠️ No orders found for this customer.</p>\n";
        
        // Create a test order for debugging
        echo "<h4>Creating Test Order for Debugging:</h4>\n";
        $orderManager = new OrderManager();
        $testOrderData = [
            'customer_id' => $customer_id,
            'cylinder_type' => '14.2kg Domestic',
            'quantity' => 1,
            'delivery_address' => 'Test Address for Debug',
            'delivery_date' => date('Y-m-d', strtotime('+2 days')),
            'delivery_time' => '14:00',
            'contact_number' => $current_user['phone'] ?? '9999999999',
            'special_instructions' => 'Debug test order'
        ];
        
        $newOrder = $orderManager->createOrder($testOrderData);
        if ($newOrder) {
            echo "<p style='color: green;'>✅ Test order created with ID: {$newOrder['id']}</p>\n";
            $orders = [$newOrder];
        } else {
            echo "<p style='color: red;'>❌ Failed to create test order</p>\n";
        }
    } else {
        echo "<p style='color: green;'>✅ Found " . count($orders) . " orders</p>\n";
    }
    
    // Display orders and test cancellation logic
    foreach ($orders as $order) {
        echo "<div style='border: 1px solid #ddd; padding: 15px; margin: 10px 0; border-radius: 5px; background: white;'>\n";
        echo "<h4>Order #{$order['id']} - {$order['cylinder_type']}</h4>\n";
        echo "<p><strong>Status:</strong> {$order['status']}</p>\n";
        echo "<p><strong>Created:</strong> {$order['created_at']}</p>\n";
        
        // Check if order can be cancelled
        $canCancel = in_array($order['status'], ['pending', 'confirmed']);
        echo "<p><strong>Status allows cancellation:</strong> " . ($canCancel ? "✅ Yes" : "❌ No") . "</p>\n";
        
        // Check if delivery person is assigned
        $delivery_check_sql = "SELECT delivery_person_id, d.status as delivery_status, u.name as delivery_person_name 
                              FROM deliveries d 
                              LEFT JOIN users u ON d.delivery_person_id = u.id 
                              WHERE d.order_id = ?";
        $delivery_stmt = $pdo->prepare($delivery_check_sql);
        $delivery_stmt->execute([$order['id']]);
        $delivery_info = $delivery_stmt->fetch();
        
        if ($delivery_info && $delivery_info['delivery_person_id']) {
            echo "<p><strong>Delivery person assigned:</strong> ❌ Yes - {$delivery_info['delivery_person_name']} (Status: {$delivery_info['delivery_status']})</p>\n";
            $canCancelFinal = false;
        } else {
            echo "<p><strong>Delivery person assigned:</strong> ✅ No</p>\n";
            $canCancelFinal = $canCancel;
        }
        
        echo "<p><strong>Final cancellation eligibility:</strong> " . ($canCancelFinal ? "✅ CAN CANCEL" : "❌ CANNOT CANCEL") . "</p>\n";
        
        // Test cancellation if eligible
        if ($canCancelFinal && isset($_GET['test_cancel']) && $_GET['test_cancel'] == $order['id']) {
            echo "<h5>Testing Cancellation:</h5>\n";
            $orderManager = new OrderManager();
            $result = $orderManager->cancelOrder($order['id'], $customer_id, "Debug test cancellation");
            
            if ($result) {
                echo "<p style='color: green;'>✅ Cancellation successful!</p>\n";
            } else {
                echo "<p style='color: red;'>❌ Cancellation failed!</p>\n";
                
                // Check what went wrong
                echo "<h6>Debugging cancellation failure:</h6>\n";
                
                // Re-fetch order to see current status
                $check_order_sql = "SELECT * FROM orders WHERE id = ?";
                $check_stmt = $pdo->prepare($check_order_sql);
                $check_stmt->execute([$order['id']]);
                $current_order = $check_stmt->fetch();
                
                if ($current_order) {
                    echo "<p>Current order status: {$current_order['status']}</p>\n";
                    echo "<p>Order belongs to customer: " . ($current_order['customer_id'] == $customer_id ? "Yes" : "No") . "</p>\n";
                } else {
                    echo "<p style='color: red;'>Order not found in database!</p>\n";
                }
            }
        } elseif ($canCancelFinal) {
            echo "<a href='?test_cancel={$order['id']}' style='background: #007bff; color: white; padding: 5px 10px; text-decoration: none; border-radius: 3px;'>Test Cancel This Order</a>\n";
        }
        
        echo "</div>\n";
    }
    
    // Database structure checks
    echo "<h3>Database Structure Validation:</h3>\n";
    
    // Check if orders table has required columns
    $columns_sql = "DESCRIBE orders";
    $columns_stmt = $pdo->prepare($columns_sql);
    $columns_stmt->execute();
    $columns = $columns_stmt->fetchAll();
    
    $required_columns = ['id', 'customer_id', 'status', 'special_instructions', 'updated_at'];
    $missing_columns = [];
    
    $existing_columns = array_column($columns, 'Field');
    foreach ($required_columns as $required) {
        if (!in_array($required, $existing_columns)) {
            $missing_columns[] = $required;
        }
    }
    
    if (empty($missing_columns)) {
        echo "<p style='color: green;'>✅ All required columns exist in orders table</p>\n";
    } else {
        echo "<p style='color: red;'>❌ Missing columns in orders table: " . implode(', ', $missing_columns) . "</p>\n";
    }
    
    // Check if deliveries table exists
    try {
        $deliveries_sql = "SELECT COUNT(*) FROM deliveries";
        $deliveries_stmt = $pdo->prepare($deliveries_sql);
        $deliveries_stmt->execute();
        echo "<p style='color: green;'>✅ Deliveries table exists and accessible</p>\n";
    } catch (Exception $e) {
        echo "<p style='color: red;'>❌ Deliveries table issue: " . $e->getMessage() . "</p>\n";
    }
    
    // Check if order_history table exists
    try {
        $history_sql = "SELECT COUNT(*) FROM order_history";
        $history_stmt = $pdo->prepare($history_sql);
        $history_stmt->execute();
        echo "<p style='color: green;'>✅ Order_history table exists and accessible</p>\n";
    } catch (Exception $e) {
        echo "<p style='color: red;'>❌ Order_history table issue: " . $e->getMessage() . "</p>\n";
    }
    
    echo "<h3>Troubleshooting Tips:</h3>\n";
    echo "<ul>\n";
    echo "<li>Make sure you're logged in as a customer</li>\n";
    echo "<li>Only orders with status 'pending' or 'confirmed' can be cancelled</li>\n";
    echo "<li>Orders cannot be cancelled if a delivery person has been assigned</li>\n";
    echo "<li>Check the browser console for JavaScript errors</li>\n";
    echo "<li>Check the server error logs for detailed error messages</li>\n";
    echo "</ul>\n";
    
} catch (Exception $e) {
    echo "<p style='color: red; background: #ffebee; padding: 10px; border-radius: 5px;'>Error during debug: " . $e->getMessage() . "</p>\n";
    echo "<p>Stack trace:</p><pre>" . $e->getTraceAsString() . "</pre>\n";
}

echo "</div>\n";
?>

<style>
body {
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    margin: 0;
    padding: 20px;
    min-height: 100vh;
}
h2 {
    color: #2c3e50;
    text-align: center;
    background: white;
    padding: 20px;
    border-radius: 10px;
    margin-bottom: 0;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
}
</style>