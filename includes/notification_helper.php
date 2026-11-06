<?php
/**
 * Notification Helper Functions
 * Contains improved notification functions for the LPG system
 */

/**
 * Send cancellation notification to all active admin users
 * @param PDO $pdo Database connection
 * @param int $order_id Order ID that was cancelled
 * @param string $customer_name Name of the customer who cancelled
 * @param string|null $cancel_reason Reason for cancellation
 * @return bool Success status
 */
function sendAdminCancellationNotification($pdo, $order_id, $customer_name, $cancel_reason = null) {
    try {
        // Get active admin users
        $admin_sql = "SELECT id, name, email FROM users WHERE role = 'admin' AND status = 'active'";
        $admin_stmt = $pdo->prepare($admin_sql);
        $admin_stmt->execute();
        $admins = $admin_stmt->fetchAll();
        
        if (empty($admins)) {
            error_log("No active admin users found for cancellation notification");
            return false;
        }
        
        // Prepare notification message
        $title = "Order Cancelled";
        $message = "Order #{$order_id} cancelled by customer {$customer_name}";
        if (!empty($cancel_reason)) {
            $message .= ". Reason: " . $cancel_reason;
        }
        
        // Insert notification for each admin
        $success_count = 0;
        foreach ($admins as $admin) {
            $insert_sql = "INSERT INTO notifications (user_id, order_id, title, message, type, is_read, created_at) 
                         VALUES (?, ?, ?, ?, 'warning', FALSE, NOW())";
            $insert_stmt = $pdo->prepare($insert_sql);
            $result = $insert_stmt->execute([$admin['id'], $order_id, $title, $message]);
            
            if ($result) {
                $success_count++;
                error_log("Cancellation notification sent to admin: {$admin['name']} (ID: {$admin['id']})");
            }
        }
        
        return $success_count > 0;
        
    } catch (Exception $e) {
        error_log("Error sending admin cancellation notification: " . $e->getMessage());
        return false;
    }
}

/**
 * Send any notification to admin users
 * @param PDO $pdo Database connection
 * @param string $title Notification title
 * @param string $message Notification message
 * @param string $type Notification type (info, success, warning, error)
 * @param int|null $order_id Optional order ID
 * @return bool Success status
 */
function sendAdminNotification($pdo, $title, $message, $type = 'info', $order_id = null) {
    try {
        $admin_sql = "SELECT id FROM users WHERE role = 'admin' AND status = 'active'";
        $admin_stmt = $pdo->prepare($admin_sql);
        $admin_stmt->execute();
        $admins = $admin_stmt->fetchAll();
        
        if (empty($admins)) {
            error_log("No active admin users found for notification: $title");
            return false;
        }
        
        $success_count = 0;
        foreach ($admins as $admin) {
            $insert_sql = "INSERT INTO notifications (user_id, order_id, title, message, type, is_read, created_at) 
                         VALUES (?, ?, ?, ?, ?, FALSE, NOW())";
            $insert_stmt = $pdo->prepare($insert_sql);
            $result = $insert_stmt->execute([$admin['id'], $order_id, $title, $message, $type]);
            
            if ($result) {
                $success_count++;
            }
        }
        
        return $success_count > 0;
        
    } catch (Exception $e) {
        error_log("Error sending admin notification: " . $e->getMessage());
        return false;
    }
}

/**
 * Check if executeQuery function exists and works
 * @return bool
 */
function isExecuteQueryAvailable() {
    return function_exists('executeQuery');
}

/**
 * Safe wrapper for executeQuery or direct PDO
 * @param string $sql SQL query
 * @param array $params Parameters
 * @return mixed Query result
 */
function safeExecuteQuery($sql, $params = []) {
    if (function_exists('executeQuery')) {
        return executeQuery($sql, $params);
    } else {
        $pdo = getDBConnection();
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }
}
?>