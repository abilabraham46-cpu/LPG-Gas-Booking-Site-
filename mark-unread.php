<?php
require_once 'config/database.php';

echo "<h2>Mark Multiple Notifications as Unread</h2>";

try {
    // Mark notifications 18 and 20 as unread
    $result1 = updateData("UPDATE notifications SET is_read = 0 WHERE id = 18");
    $result2 = updateData("UPDATE notifications SET is_read = 0 WHERE id = 20");
    
    echo "<p>Notification 18 updated: " . ($result1 ? 'Success' : 'Failed') . "</p>";
    echo "<p>Notification 20 updated: " . ($result2 ? 'Success' : 'Failed') . "</p>";
    
    // Check unread count
    $unread = fetchOne("SELECT COUNT(*) as count FROM notifications WHERE user_id = 2 AND is_read = 0");
    echo "<h3>Total Unread Notifications for Customer 2: {$unread['count']}</h3>";
    
    if ($unread['count'] > 0) {
        $notifications = fetchAll("SELECT * FROM notifications WHERE user_id = 2 AND is_read = 0 ORDER BY created_at DESC");
        echo "<h3>Unread Notifications:</h3>";
        foreach ($notifications as $notification) {
            echo "<div style='border: 2px solid #28a745; margin: 10px; padding: 15px; background: #f8f9fa;'>";
            echo "<h4 style='color: #28a745;'>{$notification['title']}</h4>";
            echo "<p><strong>Message:</strong> {$notification['message']}</p>";
            echo "<p><strong>ID:</strong> {$notification['id']}</p>";
            echo "<p><strong>Created:</strong> {$notification['created_at']}</p>";
            echo "</div>";
        }
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>Error: " . $e->getMessage() . "</p>";
}
?>