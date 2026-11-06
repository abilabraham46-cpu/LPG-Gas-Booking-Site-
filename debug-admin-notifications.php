<?php
/**
 * Debug Admin Notification Issues
 * This script helps identify why admin users aren't receiving cancellation notifications
 */

require_once 'config/database.php';

echo "<h2>Admin Notification Debug Tool</h2>\n";
echo "<div style='font-family: Arial, sans-serif; padding: 20px; background: #f8f9fa; margin: 20px; border-radius: 10px;'>\n";

try {
    $pdo = getDBConnection();
    echo "<p style='color: green;'>✅ Database connection successful</p>\n";
    
    // Check if admin users exist
    echo "<h3>1. Checking Admin Users</h3>\n";
    $admin_sql = "SELECT id, name, email, role, status FROM users WHERE role = 'admin'";
    $admin_stmt = $pdo->prepare($admin_sql);
    $admin_stmt->execute();
    $admins = $admin_stmt->fetchAll();
    
    if (empty($admins)) {
        echo "<p style='color: red;'>❌ No admin users found!</p>\n";
        echo "<p>Creating a default admin user...</p>\n";
        
        // Create default admin user
        $create_admin_sql = "INSERT INTO users (name, email, phone, password, role, status, created_at) 
                            VALUES ('System Admin', 'admin@liaa.com', '9999999999', ?, 'admin', 'active', NOW())";
        $password_hash = password_hash('admin123', PASSWORD_BCRYPT);
        $create_stmt = $pdo->prepare($create_admin_sql);
        $create_stmt->execute([$password_hash]);
        
        echo "<p style='color: green;'>✅ Default admin created: admin@liaa.com / admin123</p>\n";
        
        // Re-fetch admin users
        $admin_stmt->execute();
        $admins = $admin_stmt->fetchAll();
    } else {
        echo "<p style='color: green;'>✅ Found " . count($admins) . " admin user(s):</p>\n";
        foreach ($admins as $admin) {
            echo "<p>- {$admin['name']} (ID: {$admin['id']}, Email: {$admin['email']}, Status: {$admin['status']})</p>\n";
        }
    }
    
    // Check notifications table
    echo "<h3>2. Checking Notifications Table</h3>\n";
    try {
        $notifications_check_sql = "SELECT COUNT(*) as count FROM notifications";
        $notifications_stmt = $pdo->prepare($notifications_check_sql);
        $notifications_stmt->execute();
        $notification_count = $notifications_stmt->fetch()['count'];
        echo "<p style='color: green;'>✅ Notifications table exists with {$notification_count} notifications</p>\n";
    } catch (Exception $e) {
        echo "<p style='color: red;'>❌ Notifications table issue: " . $e->getMessage() . "</p>\n";
        
        // Create notifications table
        echo "<p>Creating notifications table...</p>\n";
        $create_notifications_sql = "CREATE TABLE IF NOT EXISTS `notifications` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT NOT NULL,
            `order_id` INT,
            `title` VARCHAR(255) NOT NULL,
            `message` TEXT NOT NULL,
            `type` ENUM('info', 'success', 'warning', 'error') NOT NULL DEFAULT 'info',
            `is_read` BOOLEAN NOT NULL DEFAULT FALSE,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
            FOREIGN KEY (`order_id`) REFERENCES `orders`(`id`) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        $pdo->exec($create_notifications_sql);
        echo "<p style='color: green;'>✅ Notifications table created</p>\n";
    }
    
    // Test notification insertion
    echo "<h3>3. Testing Notification Insertion</h3>\n";
    
    if (!empty($admins)) {
        $test_order_id = 1; // Use a test order ID
        $test_message = "Test cancellation notification - " . date('Y-m-d H:i:s');
        
        // Test the exact query used in customer-portal.php
        $notification_sql = "INSERT INTO notifications (user_id, order_id, title, message, type, created_at) 
                           SELECT id, ?, 'Order Cancelled', ?, 'warning', NOW() 
                           FROM users WHERE role = 'admin'";
        
        try {
            $notification_stmt = $pdo->prepare($notification_sql);
            $result = $notification_stmt->execute([$test_order_id, $test_message]);
            $affected_rows = $notification_stmt->rowCount();
            
            if ($result && $affected_rows > 0) {
                echo "<p style='color: green;'>✅ Test notification sent successfully to {$affected_rows} admin(s)</p>\n";
            } else {
                echo "<p style='color: red;'>❌ Test notification failed - no rows affected</p>\n";
            }
        } catch (Exception $e) {
            echo "<p style='color: red;'>❌ Test notification failed: " . $e->getMessage() . "</p>\n";
        }
    }
    
    // Check recent notifications
    echo "<h3>4. Recent Notifications</h3>\n";
    $recent_sql = "SELECT n.*, u.name as user_name, u.role 
                   FROM notifications n 
                   JOIN users u ON n.user_id = u.id 
                   ORDER BY n.created_at DESC 
                   LIMIT 10";
    $recent_stmt = $pdo->prepare($recent_sql);
    $recent_stmt->execute();
    $recent_notifications = $recent_stmt->fetchAll();
    
    if (empty($recent_notifications)) {
        echo "<p style='color: orange;'>⚠️ No notifications found in the system</p>\n";
    } else {
        echo "<table style='width: 100%; border-collapse: collapse; margin: 10px 0;'>\n";
        echo "<tr style='background: #007bff; color: white;'>\n";
        echo "<th style='padding: 10px; border: 1px solid #ddd;'>ID</th>\n";
        echo "<th style='padding: 10px; border: 1px solid #ddd;'>User</th>\n";
        echo "<th style='padding: 10px; border: 1px solid #ddd;'>Role</th>\n";
        echo "<th style='padding: 10px; border: 1px solid #ddd;'>Title</th>\n";
        echo "<th style='padding: 10px; border: 1px solid #ddd;'>Message</th>\n";
        echo "<th style='padding: 10px; border: 1px solid #ddd;'>Type</th>\n";
        echo "<th style='padding: 10px; border: 1px solid #ddd;'>Read</th>\n";
        echo "<th style='padding: 10px; border: 1px solid #ddd;'>Created</th>\n";
        echo "</tr>\n";
        
        foreach ($recent_notifications as $notification) {
            echo "<tr>\n";
            echo "<td style='padding: 8px; border: 1px solid #ddd;'>{$notification['id']}</td>\n";
            echo "<td style='padding: 8px; border: 1px solid #ddd;'>{$notification['user_name']}</td>\n";
            echo "<td style='padding: 8px; border: 1px solid #ddd;'>{$notification['role']}</td>\n";
            echo "<td style='padding: 8px; border: 1px solid #ddd;'>{$notification['title']}</td>\n";
            echo "<td style='padding: 8px; border: 1px solid #ddd;'>" . substr($notification['message'], 0, 50) . "...</td>\n";
            echo "<td style='padding: 8px; border: 1px solid #ddd;'>{$notification['type']}</td>\n";
            echo "<td style='padding: 8px; border: 1px solid #ddd;'>" . ($notification['is_read'] ? 'Yes' : 'No') . "</td>\n";
            echo "<td style='padding: 8px; border: 1px solid #ddd;'>{$notification['created_at']}</td>\n";
            echo "</tr>\n";
        }
        echo "</table>\n";
    }
    
    // Check if executeQuery function exists
    echo "<h3>5. Checking executeQuery Function</h3>\n";
    if (function_exists('executeQuery')) {
        echo "<p style='color: green;'>✅ executeQuery function exists</p>\n";
        
        // Test executeQuery with notification insertion
        try {
            $test_result = executeQuery($notification_sql, [$test_order_id, "Test with executeQuery - " . date('Y-m-d H:i:s')]);
            echo "<p style='color: green;'>✅ executeQuery function works for notifications</p>\n";
        } catch (Exception $e) {
            echo "<p style='color: red;'>❌ executeQuery function failed: " . $e->getMessage() . "</p>\n";
        }
    } else {
        echo "<p style='color: red;'>❌ executeQuery function not found</p>\n";
        echo "<p>This might be why notifications are failing in customer-portal.php</p>\n";
    }
    
    // Provide solutions
    echo "<h3>6. Solutions</h3>\n";
    echo "<div style='background: #d4edda; border: 1px solid #c3e6cb; padding: 15px; border-radius: 5px;'>\n";
    
    if (empty($admins)) {
        echo "<p><strong>Issue:</strong> No admin users found</p>\n";
        echo "<p><strong>Solution:</strong> Admin user has been created automatically</p>\n";
    }
    
    if (!function_exists('executeQuery')) {
        echo "<p><strong>Issue:</strong> executeQuery function missing</p>\n";
        echo "<p><strong>Solution:</strong> Use direct PDO queries instead</p>\n";
    }
    
    echo "<p><strong>Next Steps:</strong></p>\n";
    echo "<ol>\n";
    echo "<li>Ensure admin users exist and are active</li>\n";
    echo "<li>Test order cancellation and check notifications</li>\n";
    echo "<li>Check admin portal for notification display</li>\n";
    echo "<li>Verify executeQuery function is working</li>\n";
    echo "</ol>\n";
    echo "</div>\n";
    
} catch (Exception $e) {
    echo "<p style='color: red; background: #ffebee; padding: 10px; border-radius: 5px;'>❌ Error: " . $e->getMessage() . "</p>\n";
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
    background: linear-gradient(135deg, #dc3545, #c82333);
    padding: 20px;
    border-radius: 10px 10px 0 0;
    margin-bottom: 0;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
}
h3 {
    color: #2c3e50;
    border-bottom: 2px solid #dc3545;
    padding-bottom: 5px;
}
table {
    background: white;
    border-radius: 5px;
    overflow: hidden;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
}
</style>