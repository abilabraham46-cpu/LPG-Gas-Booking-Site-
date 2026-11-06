<?php
session_start();
require_once 'config/database.php';
require_once 'includes/auth.php';

// Check if user is logged in
$auth = new Auth();
if (!$auth->isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$current_user = $auth->getCurrentUser();
$user_id = $current_user['id'];

// Get unread notifications for the current user
try {
    $sql = "SELECT n.*, o.cylinder_type, o.quantity, o.delivery_date 
            FROM notifications n 
            LEFT JOIN orders o ON n.order_id = o.id 
            WHERE n.user_id = ? AND n.is_read = FALSE 
            ORDER BY n.created_at DESC";
    
    $stmt = executeQuery($sql, [$user_id]);
    $notifications = $stmt->fetchAll();
    
    echo "<h2>Direct Notification Check</h2>";
    echo "<p>User ID: $user_id</p>";
    echo "<p>Unread Notifications: " . count($notifications) . "</p>";
    
    if (count($notifications) > 0) {
        echo "<h3>Your Unread Notifications:</h3>";
        foreach ($notifications as $notification) {
            echo "<div style='border: 2px solid #007bff; margin: 10px; padding: 15px; background: #f0f8ff;'>";
            echo "<h4 style='color: #007bff;'>{$notification['title']}</h4>";
            echo "<p><strong>Message:</strong> {$notification['message']}</p>";
            echo "<p><strong>Type:</strong> {$notification['type']}</p>";
            echo "<p><strong>Order ID:</strong> " . ($notification['order_id'] ?: 'N/A') . "</p>";
            echo "<p><strong>Created:</strong> {$notification['created_at']}</p>";
            echo "</div>";
        }
        
        // Auto-refresh the page every 10 seconds to simulate real-time updates
        echo "<script>";
        echo "setTimeout(() => { window.location.reload(); }, 10000);";
        echo "alert('You have " . count($notifications) . " unread notifications!');";
        echo "</script>";
    } else {
        echo "<p style='color: green;'>No unread notifications.</p>";
        echo "<script>setTimeout(() => { window.location.reload(); }, 30000);</script>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>Error: " . $e->getMessage() . "</p>";
}
?>

<style>
body { font-family: Arial, sans-serif; margin: 20px; }
</style>

<a href="customer-portal.php">← Back to Customer Portal</a>