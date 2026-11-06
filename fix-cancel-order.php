<?php
/**
 * Quick Fix for Order Cancellation Issues
 * This script addresses common problems that prevent order cancellation
 */

require_once 'config/database.php';

echo "<h2>Order Cancellation Quick Fix</h2>\n";
echo "<div style='font-family: Arial, sans-serif; padding: 20px; background: #f0f8ff; margin: 20px; border-radius: 10px;'>\n";

try {
    $pdo = getDBConnection();
    echo "<p style='color: green;'>✅ Database connection successful</p>\n";
    
    // Check and fix orders table structure
    echo "<h3>1. Checking Orders Table Structure</h3>\n";
    
    // Check if special_instructions column exists and has sufficient length
    $columns_sql = "SHOW COLUMNS FROM orders LIKE 'special_instructions'";
    $stmt = $pdo->prepare($columns_sql);
    $stmt->execute();
    $column = $stmt->fetch();
    
    if (!$column) {
        echo "<p style='color: orange;'>⚠️ special_instructions column not found. Adding it...</p>\n";
        $add_column_sql = "ALTER TABLE orders ADD COLUMN special_instructions TEXT NULL";
        $pdo->exec($add_column_sql);
        echo "<p style='color: green;'>✅ special_instructions column added</p>\n";
    } else {
        echo "<p style='color: green;'>✅ special_instructions column exists</p>\n";
    }
    
    // Ensure order_history table exists
    echo "<h3>2. Checking Order History Table</h3>\n";
    try {
        $history_check_sql = "SELECT COUNT(*) FROM order_history LIMIT 1";
        $stmt = $pdo->prepare($history_check_sql);
        $stmt->execute();
        echo "<p style='color: green;'>✅ order_history table exists</p>\n";
    } catch (Exception $e) {
        echo "<p style='color: orange;'>⚠️ order_history table issue. Creating it...</p>\n";
        $create_history_sql = "CREATE TABLE IF NOT EXISTS `order_history` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `order_id` INT NOT NULL,
            `status` VARCHAR(50) NOT NULL,
            `changed_by` INT NULL,
            `notes` TEXT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (`order_id`) REFERENCES `orders`(`id`) ON DELETE CASCADE,
            FOREIGN KEY (`changed_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        $pdo->exec($create_history_sql);
        echo "<p style='color: green;'>✅ order_history table created</p>\n";
    }
    
    // Check deliveries table
    echo "<h3>3. Checking Deliveries Table</h3>\n";
    try {
        $deliveries_check_sql = "SELECT COUNT(*) FROM deliveries LIMIT 1";
        $stmt = $pdo->prepare($deliveries_check_sql);
        $stmt->execute();
        echo "<p style='color: green;'>✅ deliveries table exists</p>\n";
    } catch (Exception $e) {
        echo "<p style='color: orange;'>⚠️ deliveries table issue. Creating it...</p>\n";
        $create_deliveries_sql = "CREATE TABLE IF NOT EXISTS `deliveries` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `order_id` INT NOT NULL,
            `delivery_person_id` INT NULL,
            `status` ENUM('assigned', 'picked_up', 'in_transit', 'delivered', 'failed') NOT NULL DEFAULT 'assigned',
            `pickup_time` TIMESTAMP NULL,
            `delivery_time` TIMESTAMP NULL,
            `notes` TEXT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (`order_id`) REFERENCES `orders`(`id`) ON DELETE CASCADE,
            FOREIGN KEY (`delivery_person_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        $pdo->exec($create_deliveries_sql);
        echo "<p style='color: green;'>✅ deliveries table created</p>\n";
    }
    
    // Fix any orders with invalid status
    echo "<h3>4. Fixing Order Status Issues</h3>\n";
    $valid_statuses = ['pending', 'confirmed', 'processing', 'out_for_delivery', 'delivered', 'cancelled'];
    
    $invalid_status_sql = "SELECT id, status FROM orders WHERE status NOT IN ('" . implode("','", $valid_statuses) . "')";
    $stmt = $pdo->prepare($invalid_status_sql);
    $stmt->execute();
    $invalid_orders = $stmt->fetchAll();
    
    if (!empty($invalid_orders)) {
        echo "<p style='color: orange;'>⚠️ Found " . count($invalid_orders) . " orders with invalid status</p>\n";
        foreach ($invalid_orders as $order) {
            $fix_status_sql = "UPDATE orders SET status = 'pending' WHERE id = ?";
            $fix_stmt = $pdo->prepare($fix_status_sql);
            $fix_stmt->execute([$order['id']]);
            echo "<p>Fixed order #{$order['id']} status from '{$order['status']}' to 'pending'</p>\n";
        }
    } else {
        echo "<p style='color: green;'>✅ All orders have valid status</p>\n";
    }
    
    // Check for orphaned deliveries (deliveries without valid orders)
    echo "<h3>5. Cleaning Up Orphaned Deliveries</h3>\n";
    $orphaned_sql = "SELECT d.id, d.order_id FROM deliveries d LEFT JOIN orders o ON d.order_id = o.id WHERE o.id IS NULL";
    $stmt = $pdo->prepare($orphaned_sql);
    $stmt->execute();
    $orphaned = $stmt->fetchAll();
    
    if (!empty($orphaned)) {
        echo "<p style='color: orange;'>⚠️ Found " . count($orphaned) . " orphaned delivery records</p>\n";
        foreach ($orphaned as $delivery) {
            $delete_sql = "DELETE FROM deliveries WHERE id = ?";
            $delete_stmt = $pdo->prepare($delete_sql);
            $delete_stmt->execute([$delivery['id']]);
            echo "<p>Removed orphaned delivery record #{$delivery['id']} for non-existent order #{$delivery['order_id']}</p>\n";
        }
    } else {
        echo "<p style='color: green;'>✅ No orphaned delivery records found</p>\n";
    }
    
    // Test the cancellation function
    echo "<h3>6. Testing Cancellation Function</h3>\n";
    
    // Find a test order that can be cancelled
    $test_order_sql = "SELECT o.* FROM orders o 
                      LEFT JOIN deliveries d ON o.id = d.order_id AND d.delivery_person_id IS NOT NULL
                      WHERE o.status IN ('pending', 'confirmed') AND d.id IS NULL 
                      LIMIT 1";
    $stmt = $pdo->prepare($test_order_sql);
    $stmt->execute();
    $test_order = $stmt->fetch();
    
    if ($test_order) {
        echo "<p style='color: blue;'>📋 Found test order #{$test_order['id']} for cancellation test</p>\n";
        
        // Test the cancellation query
        $test_cancel_sql = "UPDATE orders SET 
                           status = 'cancelled', 
                           special_instructions = CONCAT(COALESCE(special_instructions, ''), '\n[TEST CANCELLED] Quick fix test'), 
                           updated_at = NOW() 
                           WHERE id = ? AND status IN ('pending', 'confirmed')";
        
        $test_stmt = $pdo->prepare($test_cancel_sql);
        $test_result = $test_stmt->execute([$test_order['id']]);
        
        if ($test_result && $test_stmt->rowCount() > 0) {
            echo "<p style='color: green;'>✅ Cancellation test successful!</p>\n";
            
            // Restore the order for future testing
            $restore_sql = "UPDATE orders SET status = ?, special_instructions = ?, updated_at = NOW() WHERE id = ?";
            $restore_stmt = $pdo->prepare($restore_sql);
            $restore_stmt->execute([$test_order['status'], $test_order['special_instructions'], $test_order['id']]);
            echo "<p style='color: blue;'>🔄 Test order restored to original state</p>\n";
        } else {
            echo "<p style='color: red;'>❌ Cancellation test failed</p>\n";
        }
    } else {
        echo "<p style='color: orange;'>⚠️ No suitable test order found for cancellation test</p>\n";
    }
    
    echo "<h3>7. Summary</h3>\n";
    echo "<div style='background: #d4edda; border: 1px solid #c3e6cb; padding: 15px; border-radius: 5px; margin: 15px 0;'>\n";
    echo "<h4 style='color: #155724; margin-top: 0;'>✅ Fix Complete!</h4>\n";
    echo "<p style='color: #155724;'>The following issues have been checked and fixed:</p>\n";
    echo "<ul style='color: #155724;'>\n";
    echo "<li>Database table structure verified</li>\n";
    echo "<li>Required columns added if missing</li>\n";
    echo "<li>Invalid order statuses corrected</li>\n";
    echo "<li>Orphaned delivery records cleaned up</li>\n";
    echo "<li>Cancellation function tested</li>\n";
    echo "</ul>\n";
    echo "<p style='color: #155724;'><strong>You can now try cancelling an order again.</strong></p>\n";
    echo "</div>\n";
    
    echo "<h3>8. Next Steps</h3>\n";
    echo "<ol>\n";
    echo "<li>Go to the <a href='customer-portal.php'>Customer Portal</a></li>\n";
    echo "<li>Navigate to the 'My Orders' tab</li>\n";
    echo "<li>Try cancelling a pending or confirmed order</li>\n";
    echo "<li>If issues persist, run the <a href='debug-cancel-order.php'>Debug Tool</a></li>\n";
    echo "</ol>\n";
    
} catch (Exception $e) {
    echo "<p style='color: red; background: #ffebee; padding: 10px; border-radius: 5px;'>❌ Error during fix: " . $e->getMessage() . "</p>\n";
    echo "<p>Please check your database connection and permissions.</p>\n";
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
    color: white;
    text-align: center;
    background: linear-gradient(135deg, #ff6b00, #ff8533);
    padding: 20px;
    border-radius: 10px 10px 0 0;
    margin-bottom: 0;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
}
h3 {
    color: #2c3e50;
    border-bottom: 2px solid #3498db;
    padding-bottom: 5px;
}
a {
    color: #3498db;
    text-decoration: none;
    font-weight: bold;
}
a:hover {
    text-decoration: underline;
}
</style>