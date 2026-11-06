<?php
require_once 'config/database.php';

echo "<h2>Debug Order #11 Status and Notifications</h2>";

try {
    // Check order #11 current status
    $order = fetchOne("SELECT * FROM orders WHERE id = 11");
    
    if ($order) {
        echo "<h3>Order #11 Details:</h3>";
        echo "<ul>";
        echo "<li>Status: {$order['status']}</li>";
        echo "<li>Customer ID: {$order['customer_id']}</li>";
        echo "<li>Updated At: {$order['updated_at']}</li>";
        echo "</ul>";
        
        // Check notifications for this customer
        $notifications = fetchAll("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 5", [$order['customer_id']]);
        
        echo "<h3>Recent Notifications for Customer {$order['customer_id']}:</h3>";
        if (!empty($notifications)) {
            foreach ($notifications as $notification) {
                $read_status = $notification['is_read'] ? 'Yes' : 'No';
                echo "<div style='border: 1px solid #ccc; margin: 5px; padding: 10px;'>";
                echo "<strong>ID:</strong> {$notification['id']}<br>";
                echo "<strong>Type:</strong> {$notification['type']}<br>";
                echo "<strong>Title:</strong> {$notification['title']}<br>";
                echo "<strong>Message:</strong> {$notification['message']}<br>";
                echo "<strong>Created:</strong> {$notification['created_at']}<br>";
                echo "<strong>Read:</strong> $read_status<br>";
                echo "</div>";
            }
        } else {
            echo "<p style='color: red;'>No notifications found for this customer!</p>";
        }
        
        // Count unread notifications
        $unread = fetchOne("SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = 0", [$order['customer_id']]);
        echo "<h3>Unread Notifications: {$unread['count']}</h3>";
        
    } else {
        echo "<p style='color: red;'>Order #11 not found!</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>Error: " . $e->getMessage() . "</p>";
}
?>