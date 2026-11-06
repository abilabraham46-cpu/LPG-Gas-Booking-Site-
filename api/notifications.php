<?php
session_start();
require_once '../config/database.php';
require_once '../includes/auth.php';

header('Content-Type: application/json');
error_log("Notifications API called - Method: " . $_SERVER['REQUEST_METHOD']);

$auth = new Auth();

// Check if user is logged in
if (!$auth->isLoggedIn()) {
    http_response_code(401);
    error_log("Notifications API: User not logged in");
    echo json_encode([
        'success' => false,
        'error' => 'Unauthorized',
        'count' => 0,
        'notifications' => []
    ]);
    exit;
}

$current_user = $auth->getCurrentUser();
$user_id = $current_user['id'];
error_log("Notifications API: User ID = " . $user_id);

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        // Check if notifications table exists
        $check_table = "SHOW TABLES LIKE 'notifications'";
        $table_exists = executeQuery($check_table);
        
        if ($table_exists->rowCount() == 0) {
            error_log("Notifications API: notifications table does not exist");
            echo json_encode([
                'success' => true,
                'notifications' => [],
                'count' => 0,
                'message' => 'Notifications table not found'
            ]);
            exit;
        }
        
        // Fetch unread notifications for the current user
        $sql = "SELECT n.*, o.cylinder_type, o.quantity, o.delivery_date 
                FROM notifications n 
                LEFT JOIN orders o ON n.order_id = o.id 
                WHERE n.user_id = ? AND n.is_read = FALSE 
                ORDER BY n.created_at DESC";
        
        $stmt = executeQuery($sql, [$user_id]);
        $notifications = $stmt->fetchAll();
        
        error_log("Notifications API: Found " . count($notifications) . " unread notifications for user " . $user_id);
        
        echo json_encode([
            'success' => true,
            'notifications' => $notifications,
            'count' => count($notifications)
        ]);
        
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (isset($input['action']) && $input['action'] === 'mark_read') {
            $notification_id = $input['notification_id'] ?? null;
            
            if ($notification_id) {
                // Mark specific notification as read
                $sql = "UPDATE notifications SET is_read = TRUE WHERE id = ? AND user_id = ?";
                executeQuery($sql, [$notification_id, $user_id]);
                error_log("Notifications API: Marked notification " . $notification_id . " as read");
            } else {
                // Mark all notifications as read
                $sql = "UPDATE notifications SET is_read = TRUE WHERE user_id = ?";
                executeQuery($sql, [$user_id]);
                error_log("Notifications API: Marked all notifications as read for user " . $user_id);
            }
            
            echo json_encode(['success' => true]);
        } else {
            throw new Exception('Invalid action');
        }
    }
    
} catch (Exception $e) {
    error_log("Notifications API Error: " . $e->getMessage());
    error_log("Notifications API Stack trace: " . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error: ' . $e->getMessage(),
        'count' => 0,
        'notifications' => []
    ]);
}
?>
