<?php
require_once 'config/database.php';

echo "<h2>Reset Order #11 Notification to Unread</h2>";

try {
    // Mark the most recent notification for order #11 as unread
    $result = updateData("UPDATE notifications SET is_read = 0 WHERE id = 18");
    
    if ($result > 0) {
        echo "<p style='color: green;'>✓ Notification ID 18 marked as unread</p>";
        
        // Verify the change
        $notification = fetchOne("SELECT * FROM notifications WHERE id = 18");
        echo "<h3>Notification Details:</h3>";
        echo "<ul>";
        echo "<li>ID: {$notification['id']}</li>";
        echo "<li>Title: {$notification['title']}</li>";
        echo "<li>Message: {$notification['message']}</li>";
        echo "<li>Is Read: " . ($notification['is_read'] ? 'Yes' : 'No') . "</li>";
        echo "<li>Created: {$notification['created_at']}</li>";
        echo "</ul>";
        
        // Check unread count for customer 2
        $unread = fetchOne("SELECT COUNT(*) as count FROM notifications WHERE user_id = 2 AND is_read = 0");
        echo "<h3>Total Unread Notifications for Customer 2: {$unread['count']}</h3>";
        
    } else {
        echo "<p style='color: red;'>Failed to update notification</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>Error: " . $e->getMessage() . "</p>";
}
?>