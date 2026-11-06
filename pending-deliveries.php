<?php
session_start();

// Add error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once 'config/database.php';
require_once 'includes/auth.php';

$error = '';
$message = '';

// Initialize Auth class
$auth = new Auth();

// Check if delivery person is logged in
if (!$auth->isLoggedIn() || $auth->getCurrentUser()['role'] !== 'delivery') {
    header('Location: delivery-login.php');
    exit;
}

$current_user = $auth->getCurrentUser();
$delivery_id = $current_user['id'];

// Handle status updates
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pdo = null;
    try {
        // Get database connection for transaction handling
        $pdo = getDBConnection();
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Re-validate session before processing
        if (!$auth->isLoggedIn() || $auth->getCurrentUser()['role'] !== 'delivery') {
            throw new Exception('Session expired. Please log in again.');
        }
        
        // Refresh current user data
        $current_user = $auth->getCurrentUser();
        $delivery_id = $current_user['id'];
        
        // Start transaction for data consistency
        $pdo->beginTransaction();
        
        if (isset($_POST['update_status'])) {
            $order_id = filter_var($_POST['order_id'], FILTER_VALIDATE_INT);
            $new_status = trim($_POST['new_status']);
            
            if (!$order_id) {
                throw new Exception('Invalid order ID provided.');
            }
            
            // Validate status
            $valid_statuses = ['processing', 'out_for_delivery', 'delivered', 'cancelled'];
            if (!in_array($new_status, $valid_statuses)) {
                throw new Exception('Invalid status selected.');
            }
            
            // Check if the order exists
            $check_sql = "SELECT status, customer_id FROM orders WHERE id = ?";
            $check_stmt = $pdo->prepare($check_sql);
            $check_stmt->execute([$order_id]);
            $order_info = $check_stmt->fetch();
            
            if (!$order_info) {
                throw new Exception('Order not found.');
            }
            
            // Prevent updating cancelled orders
            if ($order_info['status'] === 'cancelled') {
                throw new Exception('Cannot update status for cancelled orders.');
            }

            // Prevent changes once marked as delivered
            if ($order_info['status'] === 'delivered') {
                throw new Exception('Order has already been delivered and cannot be modified.');
            }

            // Avoid redundant updates
            if ($order_info['status'] === $new_status) {
                throw new Exception('Order is already set to this status.');
            }
            
            // Update order status using prepared statement
            $update_sql = "UPDATE orders SET 
                          status = ?,
                          updated_at = NOW()
                          WHERE id = ?";
            
            $params = [$new_status, $order_id];
            
            $update_stmt = $pdo->prepare($update_sql);
            $result = $update_stmt->execute($params);
            
            if ($result && $update_stmt->rowCount() > 0) {
                // Try to create notification for the customer
                try {
                    if ($order_info['customer_id']) {
                        // Create notification for the customer
                        $notification_title = "Delivery Status Updated";
                        $notification_message = "Your order #$order_id status has been updated to: " . ucwords(str_replace('_', ' ', $new_status));
                        
                        $notification_type = 'info';
                        if ($new_status === 'delivered') {
                            $notification_type = 'success';
                            $notification_message = "Great news! Your order #$order_id has been delivered successfully.";
                        } elseif ($new_status === 'cancelled') {
                            $notification_type = 'warning';
                            $notification_message = "Your order #$order_id has been cancelled.";
                        } elseif ($new_status === 'out_for_delivery') {
                            $notification_type = 'info';
                            $notification_message = "Your order #$order_id is out for delivery! Please be available to receive it.";
                        }
                        
                        // Check if notifications table exists first
                        $table_check = $pdo->prepare("SHOW TABLES LIKE 'notifications'");
                        $table_check->execute();
                        if ($table_check->rowCount() > 0) {
                            $notification_sql = "INSERT INTO notifications (user_id, order_id, title, message, type, created_at) VALUES (?, ?, ?, ?, ?, NOW())";
                            $notification_stmt = $pdo->prepare($notification_sql);
                            $notification_stmt->execute([$order_info['customer_id'], $order_id, $notification_title, $notification_message, $notification_type]);
                        }
                    }
                } catch (Exception $notification_error) {
                    // Log notification error but don't fail the status update
                    error_log("Notification creation failed: " . $notification_error->getMessage());
                }
                
                // Commit transaction if everything succeeded
                $pdo->commit();
                
                $message = "Order #$order_id status updated to: $new_status";
                
                // Redirect to prevent form resubmission
                header("Location: pending-deliveries.php?success=" . urlencode($message));
                exit;
            } else {
                throw new Exception('Failed to update order status.');
            }
        }
        
        if (isset($_POST['accept_delivery'])) {
            $order_id = filter_var($_POST['order_id'], FILTER_VALIDATE_INT);
            
            if (!$order_id) {
                throw new Exception('Invalid order ID provided.');
            }
            
            // Check if order is still available
            $check_sql = "SELECT status, customer_id FROM orders WHERE id = ?";
            $check_stmt = $pdo->prepare($check_sql);
            $check_stmt->execute([$order_id]);
            $order_info = $check_stmt->fetch();
            
            if (!$order_info) {
                throw new Exception('Order not found.');
            }
            
            if ($order_info['status'] !== 'confirmed') {
                throw new Exception('This order is no longer available for acceptance.');
            }
            
            // Update order to assign to current delivery person
            $assign_sql = "UPDATE orders SET 
                          status = 'processing',
                          updated_at = NOW()
                          WHERE id = ? AND status = 'confirmed'";
            
            $assign_stmt = $pdo->prepare($assign_sql);
            $result = $assign_stmt->execute([$order_id]);
            
            if ($result && $assign_stmt->rowCount() > 0) {
                // Make sure we update existing delivery record instead of creating duplicates
                $delivery_check_sql = "SELECT id FROM deliveries WHERE order_id = ? LIMIT 1";
                $delivery_check_stmt = $pdo->prepare($delivery_check_sql);
                $delivery_check_stmt->execute([$order_id]);
                $existing_delivery = $delivery_check_stmt->fetch();

                if ($existing_delivery) {
                    $delivery_update_sql = "UPDATE deliveries SET delivery_person_id = ?, status = 'assigned', updated_at = NOW() WHERE order_id = ?";
                    $delivery_update_stmt = $pdo->prepare($delivery_update_sql);
                    $delivery_update_stmt->execute([$delivery_id, $order_id]);
                } else {
                    $delivery_insert_sql = "INSERT INTO deliveries (order_id, delivery_person_id, status, created_at) VALUES (?, ?, 'assigned', NOW())";
                    $delivery_insert_stmt = $pdo->prepare($delivery_insert_sql);
                    $delivery_result = $delivery_insert_stmt->execute([$order_id, $delivery_id]);

                    if (!$delivery_result) {
                        throw new Exception('Failed to create delivery assignment record.');
                    }
                }
                
                // Add order history entry
                $history_sql = "INSERT INTO order_history (order_id, status, changed_by, notes, created_at) 
                              VALUES (?, 'assigned_to_delivery', ?, 'Order assigned to delivery person', NOW())";
                $history_stmt = $pdo->prepare($history_sql);
                $history_stmt->execute([$order_id, $delivery_id]);

                // Notify customer that the order is now processing
                try {
                    if (!empty($order_info['customer_id'])) {
                        $table_check = $pdo->prepare("SHOW TABLES LIKE 'notifications'");
                        $table_check->execute();

                        if ($table_check->rowCount() > 0) {
                            $notification_sql = "INSERT INTO notifications (user_id, order_id, title, message, type, created_at) VALUES (?, ?, ?, ?, ?, NOW())";
                            $notification_stmt = $pdo->prepare($notification_sql);
                            $notification_stmt->execute([
                                $order_info['customer_id'],
                                $order_id,
                                'Order Processing',
                                "Good news! Your order #$order_id is now being processed and prepared for delivery.",
                                'info'
                            ]);
                        }
                    }
                } catch (Exception $notification_error) {
                    error_log('Processing notification creation failed: ' . $notification_error->getMessage());
                }
                
                // Commit transaction if everything succeeded
                $pdo->commit();
                
                $message = "Order #$order_id assigned successfully! Order can no longer be cancelled by customer.";
                
                // Redirect to prevent form resubmission
                header("Location: pending-deliveries.php?success=" . urlencode($message));
                exit;
            } else {
                throw new Exception('Failed to assign delivery. Order may have been assigned to another delivery person.');
            }
        }
        
    } catch (Exception $e) {
        // Rollback transaction on error
        if ($pdo && $pdo->inTransaction()) {
            $pdo->rollback();
        }
        
        $error = $e->getMessage();
        error_log("Pending deliveries error: " . $e->getMessage());
        
        // If it's a session error, redirect to login
        if (strpos($e->getMessage(), 'Session expired') !== false) {
            header('Location: delivery-login.php?error=' . urlencode($error));
            exit;
        }
    }
}

// Handle success messages from redirects
if (isset($_GET['success'])) {
    $message = $_GET['success'];
}

// Fetch pending deliveries
try {
    $pdo = getDBConnection();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $pending_sql = "SELECT o.*, 
                           u.name as customer_name, 
                           u.email as customer_email, 
                           u.phone as customer_phone,
                           u.address as customer_address,
                           COALESCE(u.address, o.delivery_address, 'Address not specified') as full_address
                    FROM orders o 
                    LEFT JOIN users u ON o.customer_id = u.id 
                    WHERE o.status IN ('confirmed', 'processing', 'out_for_delivery')
                    ORDER BY o.delivery_date ASC, o.delivery_time ASC";

    $pending_stmt = $pdo->prepare($pending_sql);
    if (!$pending_stmt->execute()) {
        throw new Exception('Failed to execute pending deliveries query: ' . implode(' | ', $pending_stmt->errorInfo()));
    }
    $pending_deliveries = $pending_stmt->fetchAll();

    // Get statistics
    $stats_sql = "SELECT 
                    COUNT(CASE WHEN status = 'confirmed' THEN 1 END) as available_orders,
                    COUNT(CASE WHEN status = 'processing' THEN 1 END) as my_assigned,
                    COUNT(CASE WHEN status = 'out_for_delivery' THEN 1 END) as out_for_delivery,
                    COUNT(CASE WHEN status IN ('confirmed', 'processing', 'out_for_delivery') THEN 1 END) as total_pending
                  FROM orders";

    $stats_stmt = $pdo->prepare($stats_sql);
    if (!$stats_stmt->execute()) {
        throw new Exception('Failed to execute stats query: ' . implode(' | ', $stats_stmt->errorInfo()));
    }
    $stats = $stats_stmt->fetch();

} catch (Exception $e) {
    $error = "Error fetching deliveries: " . $e->getMessage();
    error_log($error); // Log the error for debugging
    $pending_deliveries = [];
    $stats = ['available_orders' => 0, 'my_assigned' => 0, 'out_for_delivery' => 0, 'total_pending' => 0];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pending Deliveries - LPG Delivery Management</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #ff6b00;
            --primary-dark: #e05d00;
            --primary-light: #ff8533;
            --secondary: #e05d00;
            --accent: #ff8533;
            --success: #27ae60;
            --warning: #f39c12;
            --danger: #e74c3c;
            --light: #ecf0f1;
            --dark: #2c3e50;
            --white: #ffffff;
            --pure-white: #ffffff;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #ff6b00 0%, #ffa500 25%, #ff8533 50%, #e05d00 75%, #cc5500 100%);
            min-height: 100vh;
            color: var(--deep-navy);
        }

        @keyframes gradientShift {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }

        .glass-container {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 20px;
            padding: 2rem;
            margin: 1rem;
            box-shadow: 0 8px 32px rgba(0, 0, 0, 0.1);
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .header h1 {
            font-size: 2.5rem;
            font-weight: 700;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            text-shadow: 2px 2px 4px rgba(0, 0, 0, 0.3);
        }

        .header-actions {
            display: flex;
            gap: 1rem;
            align-items: center;
        }

        .btn {
            background: rgba(255, 255, 255, 0.2);
            border: 1px solid rgba(255, 255, 255, 0.3);
            color: white;
            padding: 0.75rem 1.5rem;
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            font-weight: 500;
        }

        .btn:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
            color: white;
        }

        .btn-primary {
            background: linear-gradient(135deg, #667eea, #764ba2);
        }

        .btn-success {
            background: linear-gradient(135deg, #56ab2f, #a8e6cf);
        }

        .btn-warning {
            background: linear-gradient(135deg, #f093fb, #f5576c);
        }

        .btn-danger {
            background: linear-gradient(135deg, #ff6b6b, #ee5a24);
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: rgba(255, 255, 255, 0.15);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 16px;
            padding: 1.5rem;
            text-align: center;
            transition: all 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.2);
        }

        .stat-icon {
            font-size: 2.5rem;
            margin-bottom: 0.5rem;
            opacity: 0.8;
        }

        .stat-number {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
        }

        .stat-label {
            font-size: 0.9rem;
            opacity: 0.8;
            font-weight: 500;
        }

        .alert {
            padding: 1rem 1.5rem;
            border-radius: 12px;
            margin-bottom: 1.5rem;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .alert-success {
            background: rgba(76, 175, 80, 0.2);
            color: #a5d6a7;
        }

        .alert-danger {
            background: rgba(244, 67, 54, 0.2);
            color: #ef9a9a;
        }

        .deliveries-grid {
            display: grid;
            gap: 1.5rem;
        }

        .delivery-card {
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 16px;
            padding: 1.5rem;
            transition: all 0.3s ease;
        }

        .delivery-card:hover {
            background: rgba(255, 255, 255, 0.15);
            transform: translateY(-2px);
        }

        .delivery-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .order-id {
            font-size: 1.2rem;
            font-weight: 600;
        }

        .status-badge {
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-booked {
            background: linear-gradient(135deg, #3498db, #2980b9);
        }

        .status-processing {
            background: linear-gradient(135deg, #f39c12, #e67e22);
        }

        .status-out-for-delivery {
            background: linear-gradient(135deg, #9b59b6, #8e44ad);
        }

        .delivery-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .detail-item {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }

        .detail-label {
            font-size: 0.8rem;
            opacity: 0.7;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .detail-value {
            font-weight: 500;
        }

        .action-buttons {
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
            align-items: center;
        }

        .form-group {
            margin-bottom: 1rem;
        }

        .form-label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            opacity: 0.9;
        }

        .form-control {
            width: 100%;
            padding: 0.75rem;
            background: transparent;
            border: 1px solid rgba(255, 255, 255, 0.3);
            border-radius: 8px;
            color: white;
            font-family: inherit;
        }

        .form-control::placeholder {
            color: rgba(255, 255, 255, 0.6);
        }

        .form-control:focus {
            outline: none;
            border-color: rgba(255, 255, 255, 0.5);
            background: rgba(255, 255, 255, 0.1);
        }

        /* Select dropdown options styling */
        .form-control option {
            background: rgba(0, 0, 0, 0.8);
            color: white;
            padding: 0.5rem;
        }

        .form-control option:hover {
            background: rgba(255, 255, 255, 0.2);
        }

        .form-control option:checked {
            background: rgba(255, 107, 0, 0.8);
        }

        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(5px);
            z-index: 1000;
        }

        .modal-content {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 20px;
            padding: 2rem;
            max-width: 500px;
            width: 90%;
            max-height: 80vh;
            overflow-y: auto;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }

        .modal-title {
            font-size: 1.5rem;
            font-weight: 600;
        }

        .close-btn {
            background: none;
            border: none;
            color: white;
            font-size: 1.5rem;
            cursor: pointer;
            opacity: 0.7;
            transition: opacity 0.3s ease;
        }

        .close-btn:hover {
            opacity: 1;
        }

        .empty-state {
            text-align: center;
            padding: 3rem 1rem;
        }

        .empty-icon {
            font-size: 4rem;
            opacity: 0.5;
            margin-bottom: 1rem;
        }

        .empty-title {
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }

        .empty-text {
            opacity: 0.7;
        }

        .detail-value {
            background: rgba(255, 255, 255, 0.1);
            padding: 0.5rem;
            border-radius: 6px;
            margin-top: 0.25rem;
        }

        #viewModalContent .form-group {
            margin-bottom: 1rem;
        }

        #viewModalContent .form-label {
            font-size: 0.9rem;
            opacity: 0.8;
            margin-bottom: 0.25rem;
        }

        @keyframes pulse {
            0% { transform: scale(1); opacity: 1; }
            50% { transform: scale(1.1); opacity: 0.7; }
            100% { transform: scale(1); opacity: 1; }
        }

        @media (max-width: 768px) {
            .glass-container {
                margin: 0.5rem;
                padding: 1rem;
            }

            .header h1 {
                font-size: 2rem;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .delivery-details {
                grid-template-columns: 1fr;
            }

            .action-buttons {
                justify-content: center;
            }
        }
    </style>
</head>
<body>
    <div class="glass-container">
        <div class="header">
            <h1><i class="fas fa-truck"></i> Pending Deliveries</h1>
            <div class="header-actions">
                <a href="delivery-portal.php" class="btn">
                    <i class="fas fa-dashboard"></i> Dashboard
                </a>
                <a href="delivery-login.php" class="btn btn-danger">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-clipboard-list" style="color: #3498db;"></i>
                </div>
                <div class="stat-number"><?= $stats['available_orders'] ?></div>
                <div class="stat-label">Available Orders</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-user-check" style="color: #f39c12;"></i>
                </div>
                <div class="stat-number"><?= $stats['my_assigned'] ?></div>
                <div class="stat-label">My Assigned</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-shipping-fast" style="color: #9b59b6;"></i>
                </div>
                <div class="stat-number"><?= $stats['out_for_delivery'] ?></div>
                <div class="stat-label">Out for Delivery</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-clock" style="color: #e74c3c;"></i>
                </div>
                <div class="stat-number"><?= $stats['total_pending'] ?></div>
                <div class="stat-label">Total Pending</div>
            </div>
        </div>


        <!-- Deliveries List -->
        <div class="deliveries-grid">
            <?php if (empty($pending_deliveries)): ?>
                <div class="empty-state">
                    <div class="empty-icon">
                        <i class="fas fa-clipboard-check"></i>
                    </div>
                    <div class="empty-title">No Pending Deliveries</div>
                    <div class="empty-text">All deliveries are up to date! Check back later for new orders.</div>
                </div>
            <?php else: ?>
                <?php foreach ($pending_deliveries as $delivery): ?>
                    <div class="delivery-card">
                        <div class="delivery-header">
                            <div class="order-id">
                                <i class="fas fa-hashtag"></i> Order #<?= htmlspecialchars($delivery['id']) ?>
                            </div>
                            <span class="status-badge status-<?= strtolower(str_replace(' ', '-', $delivery['status'])) ?>">
                                <?= htmlspecialchars($delivery['status']) ?>
                            </span>
                        </div>

                        <div class="delivery-details">
                            <div class="detail-item">
                                <div class="detail-label">Customer</div>
                                <div class="detail-value">
                                    <i class="fas fa-user"></i> <?= htmlspecialchars($delivery['customer_name'] ?: 'N/A') ?>
                                </div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Phone</div>
                                <div class="detail-value">
                                    <i class="fas fa-phone"></i> <?= htmlspecialchars($delivery['customer_phone'] ?: 'N/A') ?>
                                </div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Cylinder Type</div>
                                <div class="detail-value">
                                    <i class="fas fa-gas-pump"></i> <?= htmlspecialchars($delivery['cylinder_type']) ?>
                                </div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Quantity</div>
                                <div class="detail-value">
                                    <i class="fas fa-cubes"></i> <?= htmlspecialchars($delivery['quantity']) ?>
                                </div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Delivery Date</div>
                                <div class="detail-value">
                                    <i class="fas fa-calendar"></i> <?= date('M d, Y', strtotime($delivery['delivery_date'])) ?>
                                </div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">Delivery Time</div>
                                <div class="detail-value">
                                    <i class="fas fa-clock"></i> <?= date('h:i A', strtotime($delivery['delivery_time'])) ?>
                                </div>
                            </div>
                            <div class="detail-item" style="grid-column: 1 / -1;">
                                <div class="detail-label">Address</div>
                                <div class="detail-value">
                                    <i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($delivery['full_address']) ?>
                                </div>
                            </div>
                        </div>

                        <div class="action-buttons">
                            <?php if ($delivery['status'] === 'confirmed'): ?>
                                <form method="POST" style="display: inline;" onsubmit="return confirm('Are you sure you want to accept this delivery?')">
                                    <input type="hidden" name="order_id" value="<?= $delivery['id'] ?>">
                                    <button type="submit" name="accept_delivery" class="btn btn-success">
                                        <i class="fas fa-hand-paper"></i> Accept Delivery
                                    </button>
                                </form>
                            <?php endif; ?>

                            <?php if ($delivery['status'] !== 'cancelled'): ?>
                                <button class="btn btn-primary" onclick="openUpdateModal(<?= $delivery['id'] ?>, '<?= htmlspecialchars($delivery['status']) ?>')">
                                    <i class="fas fa-edit"></i> Update Status
                                </button>
                            <?php else: ?>
                                <button class="btn btn-secondary" disabled title="Cannot update cancelled orders">
                                    <i class="fas fa-ban"></i> Cancelled
                                </button>
                            <?php endif; ?>

                            <button class="btn btn-warning" onclick="openViewModal(<?= $delivery['id'] ?>, '<?= htmlspecialchars($delivery['customer_name'] ?: 'Unknown Customer') ?>', '<?= htmlspecialchars($delivery['full_address']) ?>', '<?= htmlspecialchars($delivery['customer_phone'] ?: 'N/A') ?>')">
                                <i class="fas fa-eye"></i> View Details
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Update Status Modal -->
    <div id="updateModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Update Delivery Status</h3>
                <button type="button" class="close-btn" onclick="closeModal('updateModal')">&times;</button>
            </div>
            <form method="POST" onsubmit="return validateStatusUpdate()">
                <input type="hidden" id="updateOrderId" name="order_id">
                
                <div class="form-group">
                    <label for="newStatus" class="form-label">Status</label>
                    <select id="newStatus" name="new_status" class="form-control" required>
                        <option value="">Select Status</option>
                        <option value="processing">Processing</option>
                        <option value="out_for_delivery">Out for Delivery</option>
                        <option value="delivered">Delivered</option>
                        <option value="cancelled">Cancelled</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="deliveryNotes" class="form-label">Delivery Notes</label>
                    <textarea id="deliveryNotes" name="delivery_notes" class="form-control" rows="3" placeholder="Add any notes about this delivery..."></textarea>
                </div>

                <div style="display: flex; gap: 1rem; justify-content: flex-end;">
                    <button type="button" class="btn" onclick="closeModal('updateModal')">Cancel</button>
                    <button type="submit" name="update_status" class="btn btn-primary">Update Status</button>
                </div>
            </form>
        </div>
    </div>

    <!-- View Details Modal -->
    <div id="viewModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Order Details</h3>
                <button type="button" class="close-btn" onclick="closeModal('viewModal')">&times;</button>
            </div>
            <div id="viewModalContent">
                <!-- Content will be populated by JavaScript -->
            </div>
            <div style="display: flex; justify-content: flex-end; margin-top: 1.5rem;">
                <button type="button" class="btn" onclick="closeModal('viewModal')">Close</button>
            </div>
        </div>
    </div>

    <script>
        function openUpdateModal(orderId, currentStatus) {
            // Check if order is cancelled
            if (currentStatus === 'cancelled') {
                alert('Cannot update status for cancelled orders.');
                return;
            }
            
            document.getElementById('updateOrderId').value = orderId;
            document.getElementById('newStatus').value = currentStatus;
            document.getElementById('deliveryNotes').value = '';
            document.getElementById('updateModal').style.display = 'block';
        }

        function openViewModal(orderId, customerName, address, phone) {
            const content = `
                <div class="form-group">
                    <label class="form-label">Order ID</label>
                    <div class="detail-value">#${orderId}</div>
                </div>
                <div class="form-group">
                    <label class="form-label">Customer Name</label>
                    <div class="detail-value">${customerName}</div>
                </div>
                <div class="form-group">
                    <label class="form-label">Phone Number</label>
                    <div class="detail-value">${phone}</div>
                </div>
                <div class="form-group">
                    <label class="form-label">Delivery Address</label>
                    <div class="detail-value">${address}</div>
                </div>
            `;
            document.getElementById('viewModalContent').innerHTML = content;
            document.getElementById('viewModal').style.display = 'block';
        }

        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }

        function validateStatusUpdate() {
            const status = document.getElementById('newStatus').value;
            if (!status) {
                alert('Please select a status before updating.');
                return false;
            }
            
            if (status === 'delivered') {
                return confirm('Are you sure this delivery has been completed? This action cannot be undone.');
            }
            
            if (status === 'cancelled') {
                const notes = document.getElementById('deliveryNotes').value.trim();
                if (!notes) {
                    alert('Please provide delivery notes explaining why the delivery failed.');
                    document.getElementById('deliveryNotes').focus();
                    return false;
                }
            }
            
            return confirm(`Are you sure you want to update the status to "${status}"?`);
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            const updateModal = document.getElementById('updateModal');
            const viewModal = document.getElementById('viewModal');
            
            if (event.target === updateModal) {
                updateModal.style.display = 'none';
            }
            if (event.target === viewModal) {
                viewModal.style.display = 'none';
            }
        }

        // Auto-hide success/error messages after 5 seconds
        document.addEventListener('DOMContentLoaded', function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(function(alert) {
                setTimeout(function() {
                    alert.style.opacity = '0';
                    alert.style.transition = 'opacity 0.5s ease';
                    setTimeout(function() {
                        alert.remove();
                    }, 500);
                }, 5000);
            });
        });

        // Add keyboard shortcuts
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeModal('updateModal');
                closeModal('viewModal');
            }
        });
    </script>
</body>
</html> 