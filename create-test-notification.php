<?php
/**
 * Test endpoint to create a notification for the current user
 * Use this to test if notifications are working end-to-end
 */
session_start();
require_once '../config/database.php';
require_once '../includes/auth.php';

header('Content-Type: application/json');

$auth = new Auth();

// Check if user is logged in
if (!$auth->isLoggedIn()) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'Unauthorized - Please log in first'
    ]);
    exit;
}

$current_user = $auth->getCurrentUser();
$user_id = $current_user['id'];

try {
    // Check if notifications table exists
    $checkTableSql = "SHOW TABLES LIKE 'notifications'";
    $tableCheck = executeQuery($checkTableSql);
    
    if ($tableCheck->rowCount() == 0) {
        echo json_encode([
            'success' => false,
            'error' => 'Notifications table does not exist. Please run database_setup.sql'
        ]);
        exit;
    }
    
    // Create a test notification
    $title = 'Test Notification';
    $message = 'This is a test notification created at ' . date('Y-m-d H:i:s') . '. If you can see this popup, notifications are working!';
    $type = 'success';
    
    $insertSql = "INSERT INTO notifications (user_id, title, message, type, is_read, created_at) 
                  VALUES (?, ?, ?, ?, FALSE, NOW())";
    
    $notificationId = insertData($insertSql, [$user_id, $title, $message, $type]);
    
    if ($notificationId) {
        // Count unread notifications
        $countSql = "SELECT COUNT(*) as count FROM notifications WHERE user_id = ? AND is_read = FALSE";
        $countStmt = executeQuery($countSql, [$user_id]);
        $count = $countStmt->fetch()['count'];
        
        echo json_encode([
            'success' => true,
            'message' => 'Test notification created successfully',
            'notification_id' => $notificationId,
            'unread_count' => $count,
            'user_id' => $user_id,
            'timestamp' => date('Y-m-d H:i:s')
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'error' => 'Failed to create notification in database'
        ]);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error: ' . $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
}
?>
