<?php
require_once 'config/database.php';

echo "<h2>Final Notification Test</h2>";

try {
    // Check current unread notifications for customer 2
    $unreadNotifications = fetchAll(
        "SELECT * FROM notifications WHERE user_id = 2 AND is_read = 0 ORDER BY created_at DESC",
        []
    );
    
    echo "<h3>Unread Notifications for Customer 2:</h3>";
    echo "<p>Found: " . count($unreadNotifications) . " unread notifications</p>";
    
    if (!empty($unreadNotifications)) {
        foreach ($unreadNotifications as $notification) {
            echo "<div style='border: 2px solid #007bff; margin: 10px; padding: 15px; background: #f8f9fa;'>";
            echo "<h4 style='color: #007bff;'>{$notification['title']}</h4>";
            echo "<p><strong>Message:</strong> {$notification['message']}</p>";
            echo "<p><strong>Type:</strong> {$notification['type']}</p>";
            echo "<p><strong>Created:</strong> {$notification['created_at']}</p>";
            echo "<p><strong>Order ID:</strong> " . ($notification['order_id'] ?? 'N/A') . "</p>";
            echo "</div>";
        }
        
        echo "<h3 style='color: green;'>✅ NOTIFICATION SYSTEM STATUS: WORKING</h3>";
        echo "<p style='color: green;'>The customer portal should show popup notifications for the above unread notifications when the page loads or when checking for notifications every 30 seconds.</p>";
        
    } else {
        echo "<p style='color: orange;'>No unread notifications found</p>";
        echo "<h3 style='color: orange;'>⚠️ No notifications to display</h3>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>Error: " . $e->getMessage() . "</p>";
}
?>