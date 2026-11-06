<?php
/**
 * Debug script to check notifications table and data
 */

session_start();
require_once 'config/database.php';
require_once 'includes/auth.php';

$auth = new Auth();

if (!$auth->isLoggedIn()) {
    die("Please log in first");
}

$current_user = $auth->getCurrentUser();
$user_id = $current_user['id'];

echo "<h2>Notification System Debug</h2>";
echo "<p>Logged in as: {$current_user['name']} (ID: $user_id, Role: {$current_user['role']})</p>";
echo "<hr>";

// Check if notifications table exists
echo "<h3>1. Checking if notifications table exists...</h3>";
try {
    $checkTable = "SHOW TABLES LIKE 'notifications'";
    $result = executeQuery($checkTable);
    
    if ($result->rowCount() > 0) {
        echo "<p style='color: green;'>✓ Notifications table EXISTS</p>";
        
        // Check table structure
        echo "<h3>2. Table Structure:</h3>";
        $structure = executeQuery("DESCRIBE notifications");
        echo "<pre>";
        print_r($structure->fetchAll(PDO::FETCH_ASSOC));
        echo "</pre>";
        
        // Count total notifications
        echo "<h3>3. Notification Statistics:</h3>";
        $countAll = executeQuery("SELECT COUNT(*) as total FROM notifications");
        $totalNotifs = $countAll->fetch()['total'];
        echo "<p>Total notifications in system: $totalNotifs</p>";
        
        // Count user's notifications
        $countUser = executeQuery("SELECT COUNT(*) as total FROM notifications WHERE user_id = ?", [$user_id]);
        $userNotifs = $countUser->fetch()['total'];
        echo "<p>Your total notifications: $userNotifs</p>";
        
        // Count user's unread notifications
        $countUnread = executeQuery("SELECT COUNT(*) as total FROM notifications WHERE user_id = ? AND is_read = FALSE", [$user_id]);
        $unreadNotifs = $countUnread->fetch()['total'];
        echo "<p style='color: orange;'>Your UNREAD notifications: <strong>$unreadNotifs</strong></p>";
        
        // Show recent notifications
        echo "<h3>4. Your Recent Notifications (last 10):</h3>";
        $recent = executeQuery("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 10", [$user_id]);
        $recentNotifs = $recent->fetchAll(PDO::FETCH_ASSOC);
        
        if (count($recentNotifs) > 0) {
            echo "<table border='1' cellpadding='5' style='border-collapse: collapse;'>";
            echo "<tr><th>ID</th><th>Title</th><th>Message</th><th>Type</th><th>Read</th><th>Created</th></tr>";
            foreach ($recentNotifs as $notif) {
                $readStatus = $notif['is_read'] ? '✓ Read' : '<strong style="color: orange;">⚠ Unread</strong>';
                echo "<tr>";
                echo "<td>{$notif['id']}</td>";
                echo "<td>{$notif['title']}</td>";
                echo "<td>{$notif['message']}</td>";
                echo "<td>{$notif['type']}</td>";
                echo "<td>$readStatus</td>";
                echo "<td>{$notif['created_at']}</td>";
                echo "</tr>";
            }
            echo "</table>";
        } else {
            echo "<p style='color: gray;'>No notifications found for your account.</p>";
        }
        
        // Test API call
        echo "<h3>5. Test API Call (api/notifications.php):</h3>";
        echo "<p>Open browser console and check: <code>fetch('api/notifications.php').then(r => r.json()).then(console.log)</code></p>";
        echo "<p>Or click the 🔄 Refresh button in the customer portal to manually check for notifications.</p>";
        
    } else {
        echo "<p style='color: red;'>✗ Notifications table DOES NOT EXIST</p>";
        echo "<p>The table needs to be created. Check database_setup.sql</p>";
    }
} catch (Exception $e) {
    echo "<p style='color: red;'>Error: " . $e->getMessage() . "</p>";
}

echo "<hr>";
echo "<a href='customer-portal.php'>← Back to Customer Portal</a>";
?>
