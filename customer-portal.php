<?php
session_start();

// Debug session for notifications
if (isset($_GET['debug'])) {
    echo "<pre>Session Debug:\n";
    echo "Session ID: " . session_id() . "\n";
    echo "User ID: " . ($_SESSION['user_id'] ?? 'Not set') . "\n";
    echo "User Role: " . ($_SESSION['role'] ?? 'Not set') . "\n";
  echo "Session Data: " . print_r($_SESSION, true) . "\n";
  echo "</pre>";
}
require_once 'config/database.php';
require_once 'includes/auth.php';

// Initialize Auth
$auth = new Auth();

// Block customer portal if maintenance mode is enabled (admins can bypass)
$auth->checkMaintenanceMode();

// Check if customer is logged in
if (!$auth->isLoggedIn() || $auth->getCurrentUser()['role'] !== 'customer') {
    header('Location: customer-auth.php');
    exit;
}

$current_user = $auth->getCurrentUser();
$customer_id = $current_user['id'];
$message = '';
$error = '';

// Handle logout
if (isset($_GET['logout'])) {
    $auth->destroySession();
    header('Location: Home.html');
    exit;
}

// Handle order cancellation
if (isset($_POST['cancel_order'])) {
    $order_id = filter_var($_POST['order_id'], FILTER_VALIDATE_INT);
    $cancel_reason = trim($_POST['cancel_reason'] ?? 'Customer requested cancellation');
    
    try {
        if (!$order_id) {
            throw new Exception('Invalid order ID.');
        }
        
        require_once 'includes/orders.php';
        $orderManager = new OrderManager();
        
        // Check if order can be cancelled (only pending or confirmed orders)
        $order = $orderManager->getOrderById($order_id);
        if (!$order) {
            throw new Exception('Order not found.');
        }
        
        if ($order['customer_id'] != $customer_id) {
            throw new Exception('You can only cancel your own orders.');
        }
        
        // Check if order can be cancelled
        if (!in_array($order['status'], ['pending', 'confirmed'])) {
            $status_text = ucwords(str_replace('_', ' ', $order['status']));
            throw new Exception("Cannot cancel order. Order is already {$status_text}.");
        }
        
        // Check if a delivery person has been assigned (order is in deliveries table)
        $delivery_check_sql = "SELECT delivery_person_id FROM deliveries WHERE order_id = ? AND delivery_person_id IS NOT NULL";
        $delivery_stmt = executeQuery($delivery_check_sql, [$order_id]);
        $delivery_assigned = $delivery_stmt->fetch();
        
        if ($delivery_assigned) {
            throw new Exception('Cannot cancel order. A delivery person has already been assigned to your order.');
        }
        
        // Cancel the order
        if ($orderManager->cancelOrder($order_id, $customer_id, $cancel_reason)) {
            $message = "Order #" . $order['id'] . " has been cancelled successfully.";
            
            // Add notification for admin about cancelled order
            try {
                require_once 'includes/notification_helper.php';
                $pdo = getDBConnection();
                
                $notification_sent = sendAdminCancellationNotification(
                    $pdo, 
                    $order_id, 
                    $current_user['name'], 
                    $cancel_reason
                );
                
                if ($notification_sent) {
                    error_log("Admin notification sent successfully for cancelled order ID: $order_id");
                } else {
                    error_log("Failed to send admin notification for cancelled order ID: $order_id");
                    
                    // Fallback: Try the original method
                    try {
                        $fallback_message = "Order #{$order['id']} cancelled by customer {$current_user['name']}";
                        if (!empty($cancel_reason)) {
                            $fallback_message .= ". Reason: " . $cancel_reason;
                        }
                        
                        $fallback_sql = "INSERT INTO notifications (user_id, order_id, title, message, type, created_at) 
                                       SELECT id, ?, 'Order Cancelled', ?, 'warning', NOW() 
                                       FROM users WHERE role = 'admin' AND status = 'active'";
                        $fallback_stmt = $pdo->prepare($fallback_sql);
                        $fallback_result = $fallback_stmt->execute([$order_id, $fallback_message]);
                        
                        if ($fallback_result && $fallback_stmt->rowCount() > 0) {
                            error_log("Fallback notification method succeeded. Order ID: $order_id");
                        } else {
                            error_log("All notification methods failed. Order ID: $order_id");
                        }
                    } catch (Exception $fallback_error) {
                        error_log("Fallback notification failed: " . $fallback_error->getMessage());
                    }
                }
            } catch (Exception $notification_error) {
                error_log("Notification system error: " . $notification_error->getMessage() . ". Order ID: $order_id");
            }
        } else {
            // Get more detailed error information
            $detailed_error = "Failed to cancel order. Possible reasons:\n";
            $detailed_error .= "- Order may have already been assigned to a delivery person\n";
            $detailed_error .= "- Order status may have changed\n";
            $detailed_error .= "- Database connection issue\n";
            $detailed_error .= "Please check your order status and try again, or contact support if the problem persists.";
            
            throw new Exception($detailed_error);
        }
        
    } catch (Exception $e) {
        error_log("Order cancellation error: " . $e->getMessage());
        $error = $e->getMessage();
    }
}

// Handle profile update
if (isset($_POST['update_profile'])) {
    $update_name = trim($_POST['update_name'] ?? '');
    $update_phone = trim($_POST['update_phone'] ?? '');
    $update_address = trim($_POST['update_address'] ?? '');
    
    try {
        if (empty($update_name)) {
            $error = "Name is required.";
        } else {
            // Update user profile
            $update_sql = "UPDATE users SET name = ?, phone = ?, address = ? WHERE id = ?";
            executeQuery($update_sql, [$update_name, $update_phone, $update_address, $customer_id]);
            
            // Update session data
            $current_user['name'] = $update_name;
            $current_user['phone'] = $update_phone;
            $current_user['address'] = $update_address;
            
            $message = "Profile updated successfully!";
        }
    } catch (Exception $e) {
        error_log("Profile update error: " . $e->getMessage());
        $error = "Error updating profile. Please try again.";
    }
}

// Refresh user data to ensure we have the latest information
try {
    $refresh_sql = "SELECT * FROM users WHERE id = ? AND status = 'active'";
    $refresh_stmt = executeQuery($refresh_sql, [$customer_id]);
    $fresh_user_data = $refresh_stmt->fetch();
    
    if ($fresh_user_data) {
        // Update current user data with fresh data from database
        unset($fresh_user_data['password']); // Remove password for security
        $current_user = array_merge($current_user, $fresh_user_data);
    }
} catch (Exception $e) {
    // If refresh fails, continue with existing user data
    error_log("Failed to refresh user data: " . $e->getMessage());
}

$delivery_charge_value = 50.0;
try {
  $delivery_setting_stmt = executeQuery("SELECT setting_value FROM admin_settings WHERE setting_key = 'delivery_charge' LIMIT 1");
  $delivery_setting_row = $delivery_setting_stmt->fetch();
  if ($delivery_setting_row && is_numeric($delivery_setting_row['setting_value'])) {
    $delivery_charge_value = (float) $delivery_setting_row['setting_value'];
  }
} catch (Exception $deliveryChargeError) {
  error_log('Failed to load delivery charge setting: ' . $deliveryChargeError->getMessage());
}

$gst_rate = 0.05; // 5% GST across all cylinders
$gst_percentage_label = number_format($gst_rate * 100, 2, '.', '');

$fallback_prices = [
  '5kg' => 2200.0,
  '12kg' => 5000.0,
  '25kg' => 9500.0,
  '50kg' => 18000.0,
  '100kg' => 35000.0
];

// Handle booking submission
if (isset($_POST['book_order'])) {
    
    error_log("=== Order booking initiated ===");
    error_log("POST data: " . print_r($_POST, true));
    error_log("Files: " . print_r($_FILES, true));
    
    // Add immediate redirect test
    header('X-Debug-Order-Received: true');
    
    // Prefer the radio value, fallback to the visible select if radios are not set
    $cylinder_type = $_POST['cylinder_type'] ?? $_POST['cylinder_type_fallback'] ?? '';
    $quantity = intval($_POST['quantity'] ?? 1);
    $delivery_address = trim($_POST['delivery_address'] ?? '');
    $delivery_date = $_POST['delivery_date'] ?? '';
    $contact_number_input = trim($_POST['contact_number'] ?? '');
    $contact_number = preg_replace('/\D+/', '', $contact_number_input);
    $special_instructions = trim($_POST['special_instructions'] ?? '');
  // Enforce Cash on Delivery (COD) only. If another method is posted, override and log.
  $payment_method = 'cod';
  if (isset($_POST['payment_method']) && $_POST['payment_method'] !== 'cod') {
    // Log attempts to select non-COD methods and silently enforce COD server-side
    error_log("Payment method override: non-COD method '" . htmlspecialchars($_POST['payment_method']) . "' was overridden to 'cod' for order request.");
  }

  try {
    if (empty($cylinder_type) || empty($delivery_address) || empty($delivery_date) || empty($contact_number) || empty($payment_method)) {
      $error = "Please fill all required fields including payment method.";
    } elseif (!isValidPhone($contact_number)) {
      $error = "Mobile number must be exactly 10 digits.";
    } else {
      $pdo = getDBConnection();

      try {
        $pdo->beginTransaction();

        $inventory_sql = "SELECT id, unit_price, available_quantity, total_quantity FROM cylinder_inventory WHERE cylinder_type = ? AND status = 'active' FOR UPDATE";
        $inventory_stmt = $pdo->prepare($inventory_sql);
        $inventory_stmt->execute([$cylinder_type]);
        $cylinder = $inventory_stmt->fetch();

        if (!$cylinder) {
          throw new Exception('Selected cylinder type is not available.');
        }

        $available_quantity = (int)($cylinder['available_quantity'] ?? 0);
        if ($available_quantity < $quantity) {
          throw new Exception("Insufficient stock. Only {$available_quantity} units available.");
        }

        $unit_price = (float)($cylinder['unit_price'] ?? 0);
        if ($unit_price <= 0) {
          $unit_price = (float)($fallback_prices[$cylinder_type] ?? 5000);
        }

    $subtotal = $unit_price * $quantity;

    $delivery_charge = $delivery_charge_value;
    $gst_amount = $subtotal * $gst_rate;
    $total_amount = $subtotal + $delivery_charge + $gst_amount;

        $order_status = 'confirmed';
        $payment_status = ($payment_method === 'cod') ? 'cod_pending' : 'pending';

        // Build INSERT dynamically based on actual columns present in `orders` table
        $orderColumns = [];
        $placeholders = [];
        $params = [];

        // Always include these core columns
        $core = [
          'customer_id' => $customer_id,
          'cylinder_type' => $cylinder_type,
          'quantity' => $quantity,
          'delivery_address' => $delivery_address,
          'delivery_date' => $delivery_date,
          'contact_number' => $contact_number,
          'total_amount' => $total_amount,
          'special_instructions' => $special_instructions
        ];

        // Get orders table columns
        $colsStmt = $pdo->prepare("SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders'");
        $colsStmt->execute();
        $orderTableCols = [];
        while ($r = $colsStmt->fetch()) {
          $orderTableCols[$r['COLUMN_NAME']] = $r;
        }

        foreach ($core as $col => $val) {
          if (isset($orderTableCols[$col])) {
            $orderColumns[] = "`$col`";
            $placeholders[] = '?';
            $params[] = $val;
          }
        }

        // payment_method may not exist in older schema
        if (isset($orderTableCols['payment_method'])) {
          $orderColumns[] = '`payment_method`';
          $placeholders[] = '?';
          $params[] = $payment_method;
        }

        // Determine acceptable payment_status value
        $finalPaymentStatus = $payment_status;
        if (isset($orderTableCols['payment_status'])) {
          $ctype = $orderTableCols['payment_status']['COLUMN_TYPE'];
          if (stripos($ctype, 'cod_pending') === false && $payment_status === 'cod_pending') {
            // fallback to 'pending' if enum doesn't include cod_pending
            $finalPaymentStatus = 'pending';
          }
          $orderColumns[] = '`payment_status`';
          $placeholders[] = '?';
          $params[] = $finalPaymentStatus;
        }

        // Order status
        if (isset($orderTableCols['status'])) {
          $orderColumns[] = '`status`';
          $placeholders[] = '?';
          $params[] = $order_status;
        }

        // If created_at exists and is auto-managed, skip adding; otherwise, let DB default NOW() if available
        // Ensure delivery_time exists and is satisfied if NOT NULL and has no default
        if (isset($orderTableCols['delivery_time'])) {
          $dt = null;
          $isNullable = strtoupper($orderTableCols['delivery_time']['IS_NULLABLE']) === 'YES';
          $hasDefault = $orderTableCols['delivery_time']['COLUMN_DEFAULT'] !== null;
          if (!$isNullable && !$hasDefault) {
            // Provide a safe default time
            $dt = date('H:i:s', strtotime('+2 hours'));
          }
          if ($dt !== null) {
            $orderColumns[] = '`delivery_time`';
            $placeholders[] = '?';
            $params[] = $dt;
          }
        }

        // Build and execute the INSERT
        if (empty($orderColumns)) {
          throw new Exception('Orders table does not have expected columns.');
        }

        $order_sql = 'INSERT INTO orders (' . implode(', ', $orderColumns) . ') VALUES (' . implode(', ', $placeholders) . ')';
        $order_stmt = $pdo->prepare($order_sql);
        $order_stmt->execute($params);

        $order_id = (int)$pdo->lastInsertId();

        $new_available = $available_quantity - $quantity;
        $update_inventory_sql = "UPDATE cylinder_inventory SET available_quantity = ?, updated_at = NOW() WHERE id = ?";
        $update_inventory_stmt = $pdo->prepare($update_inventory_sql);
        $update_inventory_stmt->execute([$new_available, $cylinder['id']]);

        try {
          $log_stmt = $pdo->prepare("INSERT INTO inventory_logs (cylinder_id, action_type, old_quantity, new_quantity, changed_by, notes) VALUES (?, 'order_placed', ?, ?, ?, ?)");
          $log_stmt->execute([
            $cylinder['id'],
            $available_quantity,
            $new_available,
            $customer_id,
            'Order #' . $order_id . ' - reduced by ' . $quantity . ' units'
          ]);
        } catch (Exception $logError) {
          error_log('Inventory log failed for order #' . $order_id . ': ' . $logError->getMessage());
        }

        $delivery_sql = "INSERT INTO deliveries (order_id, status, created_at) VALUES (?, 'Pending', NOW())";
        $delivery_stmt = $pdo->prepare($delivery_sql);
        $delivery_stmt->execute([$order_id]);

        $history_sql = "INSERT INTO order_history (order_id, status, changed_by, created_at) VALUES (?, 'confirmed', ?, NOW())";
        $history_stmt = $pdo->prepare($history_sql);
        $history_stmt->execute([$order_id, $customer_id]);

        $pdo->commit();

        $message = "Order booked successfully! Order ID: #" . $order_id . ". You can pay when the delivery arrives.";

        $_SESSION['order_success'] = $message;
        $_SESSION['order_id'] = $order_id;
        header('Location: customer-portal.php?order_placed=1');
        exit();
      } catch (Exception $transactionError) {
        if ($pdo->inTransaction()) {
          $pdo->rollBack();
        }
        throw $transactionError;
      }
    }
  } catch (Exception $e) {
    $caughtMessage = trim($e->getMessage());
    error_log("Order booking error: " . $caughtMessage);

    // Surface specific known messages directly to the user
    if (
      stripos($caughtMessage, 'Insufficient stock') === 0 ||
      stripos($caughtMessage, 'Selected cylinder type is not available') === 0
    ) {
      $error = $caughtMessage;
    } else {
      // If debug mode is enabled via ?debug=1, show the underlying exception message to help troubleshooting
      if (isset($_GET['debug']) && $_GET['debug']) {
        $error = "Error booking order: " . $caughtMessage;
      } else {
        $error = "Error booking order. Please try again.";
      }
    }
    
    // Additional detailed logging to a project log for debugging (temporary)
    try {
      $logDir = __DIR__ . DIRECTORY_SEPARATOR . 'logs';
      if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
      }
      $logFile = $logDir . DIRECTORY_SEPARATOR . 'booking_errors.log';
      $logEntry = "[" . date('Y-m-d H:i:s') . "] Booking Error: " . $caughtMessage . "\n";
      $logEntry .= "POST Data: " . print_r($_POST, true) . "\n";
      if (isset($pdo) && $pdo instanceof PDO) {
        $logEntry .= "DB inTransaction: " . ($pdo->inTransaction() ? 'yes' : 'no') . "\n";
      }
      $logEntry .= "-------------------------\n";
      @file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
    } catch (Exception $logEx) {
      // Don't break the user flow for logging failures; already logged to error_log above.
      error_log('Failed to write booking debug log: ' . $logEx->getMessage());
    }
  }
}

// Check for order success message from redirect
if (isset($_SESSION['order_success'])) {
    $message = $_SESSION['order_success'];
    unset($_SESSION['order_success']);
    unset($_SESSION['order_id']);
}

// Fetch customer orders
try {
    $sql = "SELECT 
              o.*, 
              (
                SELECT d.status 
                FROM deliveries d 
                WHERE d.order_id = o.id 
                ORDER BY COALESCE(d.updated_at, d.created_at) DESC, d.id DESC 
                LIMIT 1
              ) AS delivery_status 
            FROM orders o 
            WHERE o.customer_id = ? 
            ORDER BY o.created_at DESC";
    $stmt = executeQuery($sql, [$customer_id]);
    $orders = $stmt->fetchAll();
} catch (Exception $e) {
    $error = "Error fetching orders: " . $e->getMessage();
    $orders = [];
}

// Fetch available cylinders with enhanced stock info
try {
    $cylinders_sql = "SELECT *, 
                      CASE 
                          WHEN available_quantity <= min_stock_alert THEN 'low'
                          WHEN available_quantity <= (total_quantity * 0.3) THEN 'medium'
                          ELSE 'good'
                      END as stock_level,
                      ROUND((available_quantity / total_quantity) * 100, 1) as availability_percent
                      FROM cylinder_inventory 
                      WHERE status = 'active' 
                      ORDER BY 
                          CASE 
                              WHEN available_quantity > 0 THEN 0 
                              ELSE 1 
                          END,
                          unit_price";
    $cylinders_stmt = executeQuery($cylinders_sql);
    $available_cylinders = $cylinders_stmt->fetchAll();
} catch (Exception $e) {
    $available_cylinders = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Customer Portal | LIAA LPG</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <style>
    :root {
      /* Primary Brand Colors - Fire/Gas Theme */
      --primary: #FF6B35;
      --primary-dark: #E85A2A;
      --primary-light: #FF8C61;
      --coral-primary: #FF6B35;
      --coral-secondary: #FF8C61;
      
      /* Accent Colors */
      --accent-blue: #004E89;
      --accent-teal: #1A759F;
      --accent-sky: #34A0A4;
      
      /* Semantic Colors */
      --success: #2ECC71;
      --warning: #F39C12;
      --danger: #E74C3c;
      --info: #3498DB;
      
      /* Neutral Colors */
      --dark-blue: #0A1929;
      --navy: #132F4C;
      --slate: #1E3A5F;
      --gray-dark: #636E7B;
      --gray-medium: #8B95A5;
      --gray-light: #B8C5D6;
      --off-white: #F5F7FA;
      --pure-white: #FFFFFF;
      
      /* Gradients */
      --gradient-primary: linear-gradient(135deg, #FF6B35 0%, #FF8C61 50%, #FFA98F 100%);
      --gradient-ocean: linear-gradient(135deg, #004E89 0%, #1A759F 50%, #34A0A4 100%);
      --gradient-glass: linear-gradient(135deg, rgba(255,255,255,0.1) 0%, rgba(255,255,255,0.05) 100%);
      
      /* Shadows */
      --shadow-sm: 0 2px 8px rgba(0, 0, 0, 0.08);
      --shadow-md: 0 4px 16px rgba(0, 0, 0, 0.12);
      --shadow-lg: 0 8px 32px rgba(0, 0, 0, 0.16);
      --shadow-xl: 0 16px 48px rgba(0, 0, 0, 0.20);
      --shadow-glow: 0 0 30px rgba(255, 107, 53, 0.3);
      
      /* Effects */
      --border-radius: 16px;
      --border-radius-lg: 24px;
      --transition-smooth: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
      --glass-backdrop: rgba(255, 255, 255, 0.1);
      --glass-border: rgba(255, 255, 255, 0.2);
    }

    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
      font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
    }

    /* Static Orange Background */
    body {
      font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
      margin: 0;
      padding: 0;
      background: linear-gradient(
        135deg,
        #FF8C61 0%,
        #FF6B35 50%,
        #FF5722 100%
      );
      background-attachment: fixed;
      min-height: 100vh;
      color: #1a1a1a;
      position: relative;
      overflow-x: hidden;
    }

    /* Static decorative overlay */
    body::before {
      content: '';
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      background: 
        radial-gradient(circle at 15% 15%, rgba(255, 255, 255, 0.05) 0%, transparent 30%),
        radial-gradient(circle at 85% 85%, rgba(255, 255, 255, 0.05) 0%, transparent 30%),
        radial-gradient(circle at 50% 50%, rgba(0, 0, 0, 0.05) 0%, transparent 40%);
      pointer-events: none;
      z-index: -1;
    }

    /* Header - Simple Orange */
    .header {
      background: transparent;
      padding: 1.5rem 0;
      position: sticky;
      top: 0;
      z-index: 1000;
    }

    .header-container {
      width: 100%;
      margin: 0;
      padding: 0 2rem;
      display: flex;
      justify-content: space-between;
      align-items: center;
    }

    .logo {
      font-size: 2.2rem;
      font-weight: 900;
      color: #ffffff;
      text-decoration: none;
      display: flex;
      align-items: center;
      gap: 0.8rem;
      text-shadow: 0 4px 20px rgba(0, 0, 0, 0.4),
                   0 2px 10px rgba(0, 0, 0, 0.3),
                   0 0 30px rgba(255, 255, 255, 0.2);
      transition: var(--transition-smooth);
      letter-spacing: 0.5px;
    }

    .logo:hover {
      text-shadow: 0 6px 30px rgba(0, 0, 0, 0.5),
                   0 3px 15px rgba(0, 0, 0, 0.4),
                   0 0 40px rgba(255, 255, 255, 0.3);
      transform: translateY(-2px);
    }

    .logo i {
      color: #ffffff;
      filter: drop-shadow(0 0 15px rgba(255, 255, 255, 0.5));
      font-size: 2.4rem;
    }

    .user-info {
      display: flex;
      align-items: center;
      gap: 1.5rem;
    }

    .user-avatar {
      width: 52px;
      height: 52px;
      background: linear-gradient(135deg, var(--primary), var(--golden-yellow));
      border-radius: 18px;
      display: flex;
      align-items: center;
      justify-content: center;
      color: #1a1a1a;
      font-weight: 700;
      font-size: 1.2rem;
      box-shadow: var(--shadow-light);
      transition: var(--transition-smooth);
      position: relative;
    }

    .user-avatar::after {
      content: '';
      position: absolute;
      inset: -2px;
      border-radius: 20px;
      padding: 2px;
      background: linear-gradient(135deg, rgba(255, 255, 255, 0.3), rgba(255, 255, 255, 0.1));
      mask: linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0);
      mask-composite: xor;
    }

    .user-avatar:hover {
      transform: translateY(-3px) scale(1.05);
      box-shadow: var(--shadow-medium);
    }

    .user-details {
      display: flex;
      flex-direction: column;
      color: #ffffff;
    }

    .user-name {
      font-weight: 700;
      font-size: 1.1rem;
      color: #ffffff;
    }

    .user-role {
      font-size: 0.85rem;
      opacity: 0.8;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      color: #ffffff;
    }

    .logout-btn {
      background: linear-gradient(135deg, var(--primary), var(--fire-red));
      color: #1a1a1a;
      text-decoration: none;
      padding: 0.8rem 1.6rem;
      border-radius: 16px;
      font-weight: 700;
      font-size: 0.9rem;
      transition: var(--transition-smooth);
      border: 1px solid rgba(255, 107, 0, 0.3);
      box-shadow: var(--shadow-light);
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }

    .logout-btn:hover {
      transform: translateY(-3px);
      box-shadow: var(--shadow-medium);
      background: linear-gradient(135deg, var(--fire-red), var(--primary));
    }

    /* Main Container with Modern Layout */
    .container {
      max-width: 1300px;
      margin: 2rem auto;
      padding: 0 2rem;
    }

    .dashboard-title {
      text-align: center;
      font-size: 3.2rem;
      font-weight: 900;
      margin-bottom: 1rem;
      color: #ffffff;
      text-shadow: 0 4px 20px rgba(0, 0, 0, 0.3),
                   0 2px 8px rgba(0, 0, 0, 0.2);
      letter-spacing: 0.5px;
    }

    .dashboard-subtitle {
      text-align: center;
      color: #ffffff;
      font-size: 1.2rem;
      margin-bottom: 3rem;
      font-weight: 500;
      text-shadow: 0 2px 10px rgba(0, 0, 0, 0.3);
      letter-spacing: 0.3px;
    }

    /* Floating Tab Navigation */
    .tabs {
      display: flex;
      gap: 0.5rem;
      margin-bottom: 3rem;
      justify-content: center;
      background: rgba(255, 255, 255, 0.7);
      backdrop-filter: blur(20px) saturate(180%);
      padding: 0.8rem;
      border-radius: var(--border-radius-lg);
      border: 1px solid rgba(255, 107, 53, 0.15);
      box-shadow: 0 4px 20px rgba(0, 0, 0, 0.06);
      max-width: fit-content;
      margin-left: auto;
      margin-right: auto;
    }

    .tab {
      padding: 1rem 2.5rem;
      background: transparent;
      color: #636E7B;
      border: none;
      border-radius: var(--border-radius);
      cursor: pointer;
      font-weight: 600;
      font-size: 0.95rem;
      transition: var(--transition-smooth);
      position: relative;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }

    .tab::before {
      content: '';
      position: absolute;
      inset: 0;
      border-radius: var(--border-radius);
      background: linear-gradient(135deg, #FF6B35 0%, #FF8C61 100%);
      opacity: 0;
      transition: var(--transition-smooth);
      box-shadow: 0 4px 16px rgba(255, 107, 53, 0.4);
    }

    .tab.active::before {
      opacity: 1;
    }

    .tab.active {
      color: #1a1a1a;
      transform: translateY(-3px);
      box-shadow: var(--shadow-light);
    }

    .tab span {
      position: relative;
      z-index: 1;
    }

    .tab:hover:not(.active) {
      color: var(--secondary);
      transform: translateY(-1px);
    }

    /* Modern Tab Content with Glass Cards */
    .tab-content {
      display: none;
      background: var(--glass-effect);
      backdrop-filter: blur(30px);
      padding: 3rem;
      border-radius: 28px;
      border: 1px solid rgba(255, 255, 255, 0.2);
      box-shadow: var(--shadow-medium);
    }

    .tab-content.active {
      display: block;
    }

    .tab-content h3 {
      color: var(--secondary);
      margin-bottom: 2rem;
      font-size: 1.8rem;
      font-weight: 700;
      display: flex;
      align-items: center;
      gap: 1rem;
    }

    .tab-content h3::before {
      content: '';
      width: 5px;
      height: 35px;
      background: linear-gradient(135deg, var(--primary), var(--golden-yellow));
      border-radius: 3px;
    }

    /* Form Styling with Neumorphism */
    .form-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
      gap: 2rem;
      margin-bottom: 2rem;
    }

    .form-group {
      margin-bottom: 2rem;
    }

    .form-group label {
      display: block;
      margin-bottom: 0.8rem;
      color: var(--warm-gray);
      font-weight: 600;
      font-size: 0.95rem;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }

    .form-group input,
    .form-group select,
    .form-group textarea {
      width: 100%;
      padding: 1.2rem 1.5rem;
      background: rgba(255, 255, 255, 0.7);
      border: 2px solid rgba(255, 255, 255, 0.3);
      border-radius: 18px;
      font-size: 1rem;
      color: var(--warm-gray);
      transition: var(--transition-smooth);
      backdrop-filter: blur(10px);
      box-shadow: inset 2px 2px 5px rgba(255, 255, 255, 0.3), 
                  inset -2px -2px 5px rgba(0, 0, 0, 0.1);
    }

    .form-group input::placeholder,
    .form-group textarea::placeholder {
      color: rgba(99, 110, 123, 0.6);
    }

    .form-group input:focus,
    .form-group select:focus,
    .form-group textarea:focus {
      outline: none;
      border-color: var(--primary);
      background: rgba(255, 255, 255, 0.9);
      box-shadow: 0 0 0 4px rgba(255, 107, 0, 0.2),
                  inset 2px 2px 5px rgba(255, 255, 255, 0.3), 
                  inset -2px -2px 5px rgba(0, 0, 0, 0.1);
      transform: translateY(-2px);
    }

    /* Readonly field styling */
    .form-group input[readonly],
    .form-group textarea[readonly] {
      background: rgba(255, 255, 255, 0.5) !important;
      cursor: not-allowed;
      opacity: 0.8;
    }

    .form-group input[readonly]:focus,
    .form-group textarea[readonly]:focus {
      box-shadow: none;
      transform: none;
      border-color: rgba(255, 255, 255, 0.3);
    }

    /* Form validation styles */
    .form-group input:invalid,
    .form-group select:invalid,
    .form-group textarea:invalid {
      border-color: var(--danger);
    }

    .form-group input:valid:not([readonly]),
    .form-group select:valid,
    .form-group textarea:valid {
      border-color: var(--success);
    }

    /* Enhanced validation feedback */
    .field-valid {
        border-color: #28a745 !important;
        background-color: #f8fff9;
    }
    
    .field-invalid {
        border-color: #dc3545 !important;
        background-color: #fff8f8;
    }
    
    .validation-message {
        font-size: 0.8rem;
        margin-top: 5px;
        padding: 3px 6px;
        border-radius: 3px;
        display: none;
    }
    
    .validation-message.valid {
        color: #28a745;
        background-color: #d4edda;
        border: 1px solid #c3e6cb;
        display: block;
    }
    
    .validation-message.invalid {
        color: #dc3545;
        background-color: #f8d7da;
        border: 1px solid #f5c6cb;
        display: block;
    }

    .form-group textarea {
      resize: vertical;
      min-height: 120px;
    }

    /* Cylinder Selection Cards */
    .cylinder-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
      gap: 1.5rem;
      margin-bottom: 2rem;
    }

    .cylinder-card {
      background: rgba(255, 255, 255, 0.7);
      backdrop-filter: blur(20px) saturate(180%);
      padding: 2rem;
      border-radius: var(--border-radius-lg);
      border: 2px solid rgba(255, 107, 53, 0.15);
      transition: var(--transition-smooth);
      cursor: pointer;
      position: relative;
      overflow: hidden;
      box-shadow: 0 4px 20px rgba(0, 0, 0, 0.06),
                  0 0 30px rgba(255, 107, 53, 0.05);
    }

    .cylinder-card.out-of-stock {
      opacity: 0.5;
      cursor: not-allowed;
      background: rgba(19, 47, 76, 0.3);
      border-color: rgba(139, 149, 165, 0.2);
    }

    .cylinder-card.low-stock {
      border-color: rgba(243, 156, 18, 0.6);
      background: rgba(243, 156, 18, 0.08);
      box-shadow: 0 4px 20px rgba(243, 156, 18, 0.2);
    }

    .cylinder-card.medium-stock {
      border-color: rgba(52, 160, 164, 0.6);
      background: rgba(52, 160, 164, 0.08);
      box-shadow: 0 4px 20px rgba(52, 160, 164, 0.2);
    }

    .cylinder-card::before {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      height: 4px;
      background: linear-gradient(90deg, var(--primary), var(--golden-yellow));
      transform: scaleX(0);
      transform-origin: left;
      transition: var(--transition-smooth);
    }

    .cylinder-card:hover::before,
    .cylinder-card.selected::before {
      transform: scaleX(1);
    }

    .cylinder-card:hover {
      transform: translateY(-8px) scale(1.02);
      box-shadow: 0 12px 40px rgba(255, 107, 53, 0.15),
                  0 0 50px rgba(255, 107, 53, 0.1);
      border-color: rgba(255, 107, 53, 0.4);
      background: rgba(255, 255, 255, 0.85);
    }

    .cylinder-card.selected {
      border-color: rgba(255, 107, 53, 0.6);
      background: rgba(255, 140, 97, 0.15);
      box-shadow: 0 8px 32px rgba(255, 107, 53, 0.2),
                  0 0 60px rgba(255, 107, 53, 0.15);
      background: rgba(255, 107, 0, 0.15);
      transform: translateY(-3px);
      box-shadow: var(--shadow-medium);
      position: relative;
    }

    .cylinder-card.selected::after {
      content: '✓ SELECTED';
      position: absolute;
      top: 15px;
      right: 15px;
      background: var(--coral-primary);
      color: #1a1a1a;
      padding: 0.3rem 0.6rem;
      border-radius: 12px;
      font-size: 0.75rem;
      font-weight: bold;
      letter-spacing: 0.5px;
      z-index: 2;
    }

    /* Visible select button inside cylinder card */
    .select-action {
      position: absolute;
      right: 18px;
      bottom: 18px;
      z-index: 3;
      display: flex;
      gap: 0.5rem;
      align-items: center;
    }

    .btn-select {
      padding: 0.5rem 0.9rem;
      border-radius: 12px;
      font-weight: 700;
      font-size: 0.9rem;
      cursor: pointer;
      border: none;
      display: inline-flex;
      align-items: center;
      gap: 0.5rem;
      background: linear-gradient(135deg, #FF6B35 0%, #FF8C61 100%);
      color: #1a1a1a;
      box-shadow: 0 6px 16px rgba(255,107,53,0.12);
    }

    .btn-select:hover {
      transform: translateY(-2px);
      box-shadow: 0 10px 30px rgba(255,107,53,0.18);
    }

    .btn-select[disabled] {
      opacity: 0.6;
      cursor: not-allowed;
      transform: none;
      box-shadow: none;
      filter: grayscale(0.2);
    }

    .cylinder-info h4 {
      color: #1a1a1a; /* Ensure visible regardless of undefined CSS vars */
      font-size: 1.3rem;
      font-weight: 700;
      margin-bottom: 0.5rem;
      text-shadow: 0 2px 6px rgba(255,255,255,0.2);
      word-break: break-word;
    }

    .cylinder-price {
      color: var(--primary);
      font-size: 1.5rem;
      font-weight: 800;
      margin-bottom: 1rem;
    }

    .cylinder-availability {
      display: flex;
      align-items: center;
      gap: 0.5rem;
      margin-bottom: 1rem;
      padding: 0.5rem;
      border-radius: 8px;
      background: rgba(255, 255, 255, 0.1);
    }

    .availability-icon {
      font-size: 1rem;
    }

    .availability-text {
      font-size: 0.9rem;
      font-weight: 500;
    }

    .availability-good {
      color: #27ae60;
      background: rgba(39, 174, 96, 0.1);
      border: 1px solid rgba(39, 174, 96, 0.3);
    }

    .availability-medium {
      color: #3498db;
      background: rgba(52, 152, 219, 0.1);
      border: 1px solid rgba(52, 152, 219, 0.3);
    }

    .availability-low {
      color: #f39c12;
      background: rgba(243, 156, 18, 0.1);
      border: 1px solid rgba(243, 156, 18, 0.3);
    }

    .availability-out {
      color: #e74c3c;
      background: rgba(231, 76, 60, 0.1);
      border: 1px solid rgba(231, 76, 60, 0.3);
    }

    .stock-progress {
      width: 100%;
      height: 6px;
      background: rgba(255, 255, 255, 0.1);
      border-radius: 3px;
      overflow: hidden;
      margin-top: 0.5rem;
    }

    .stock-progress-fill {
      height: 100%;
      transition: width 0.3s ease;
      border-radius: 3px;
    }

    .stock-progress-good {
      background: linear-gradient(90deg, #27ae60, #2ecc71);
    }

    .stock-progress-medium {
      background: linear-gradient(90deg, #3498db, #5dade2);
    }

    .stock-progress-low {
      background: linear-gradient(90deg, #f39c12, #f8c471);
    }

    .cylinder-desc {
      color: var(--warm-gray);
      font-size: 0.9rem;
      line-height: 1.6;
    }

    /* Modern Button Design */
    .btn {
      display: inline-flex;
      align-items: center;
      gap: 0.8rem;
      padding: 1.2rem 2.5rem;
      border: none;
      border-radius: 18px;
      text-decoration: none;
      font-weight: 700;
      font-size: 1rem;
      transition: var(--transition-smooth);
      cursor: pointer;
      position: relative;
      overflow: hidden;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }

    .btn::before {
      content: '';
      position: absolute;
      top: 0;
      left: -100%;
      width: 100%;
      height: 100%;
      background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.4), transparent);
      transition: left 0.6s;
    }

    .btn:hover::before {
      left: 100%;
    }

    .btn-primary {
      background: linear-gradient(135deg, #FF6B35 0%, #FF8C61 100%);
      color: #1a1a1a;
      box-shadow: 0 4px 16px rgba(255, 107, 53, 0.3);
      border: none;
    }

    .btn-primary:hover {
      background: linear-gradient(135deg, #E85A2A 0%, #FF6B35 100%);
      box-shadow: 0 6px 24px rgba(255, 107, 53, 0.5);
    }

    .btn-success {
      background: linear-gradient(135deg, #2ECC71 0%, #27AE60 100%);
      color: #1a1a1a;
      box-shadow: 0 4px 16px rgba(46, 204, 113, 0.3);
      border: none;
    }

    .btn-success:hover {
      background: linear-gradient(135deg, #27AE60 0%, #229954 100%);
      box-shadow: 0 6px 24px rgba(46, 204, 113, 0.5);
    }

    .btn:hover {
      transform: translateY(-4px);
      box-shadow: var(--shadow-lg);
    }

    /* Orders Table with Modern Design */
    .orders-table {
      width: 100%;
      border-collapse: collapse;
      margin-top: 2rem;
      background: rgba(255, 255, 255, 0.7);
      backdrop-filter: blur(20px) saturate(180%);
      border-radius: var(--border-radius-lg);
      overflow: hidden;
      box-shadow: 0 4px 20px rgba(0, 0, 0, 0.06);
      border: 1px solid rgba(255, 107, 53, 0.12);
    }

    .orders-table thead {
      background: linear-gradient(135deg, rgba(255, 107, 53, 0.2) 0%, rgba(255, 140, 97, 0.2) 100%);
    }

    .orders-table th {
      padding: 1.5rem;
      text-align: left;
      color: #1a1a1a;
      font-weight: 700;
      font-size: 0.9rem;
      text-transform: uppercase;
      letter-spacing: 1px;
    }

    .orders-table td {
      padding: 1.5rem;
      border-bottom: 1px solid rgba(255, 255, 255, 0.1);
      color: var(--warm-gray);
      font-size: 0.95rem;
    }

    .orders-table tbody tr {
      transition: var(--transition-smooth);
    }

    .orders-table tbody tr:hover {
      background: rgba(255, 255, 255, 0.1);
    }

    .orders-table tbody tr:last-child td {
      border-bottom: none;
    }

    /* Status Badges with Modern Design */
    .status-badge {
      padding: 0.5rem 1rem;
      border-radius: 12px;
      font-size: 0.8rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      display: inline-flex;
      align-items: center;
      gap: 0.5rem;
    }

    .status-pending {
      background: rgba(255, 176, 133, 0.2);
      color: #e67e22;
      border: 1px solid rgba(255, 176, 133, 0.3);
    }

    .status-confirmed {
      background: rgba(255, 107, 0, 0.2);
      color: var(--primary);
      border: 1px solid rgba(255, 107, 0, 0.3);
    }

    .status-processing {
      background: rgba(255, 165, 0, 0.2);
      color: var(--golden-yellow);
      border: 1px solid rgba(255, 165, 0, 0.3);
    }

    .status-out-for-delivery {
      background: rgba(44, 62, 80, 0.2);
      color: var(--secondary);
      border: 1px solid rgba(44, 62, 80, 0.3);
    }

    .status-delivered {
      background: rgba(39, 174, 96, 0.2);
      color: var(--success);
      border: 1px solid rgba(39, 174, 96, 0.3);
    }

    .status-cancelled {
      background: rgba(231, 76, 60, 0.2);
      color: var(--danger);
      border: 1px solid rgba(231, 76, 60, 0.3);
    }

    /* Payment Method Selection in Booking Form */
    .payment-method-selection {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 1rem;
      margin-top: 1rem;
    }
    
    .payment-option {
      background: var(--glass-effect);
      backdrop-filter: blur(30px);
      border: 2px solid rgba(255, 255, 255, 0.2);
      border-radius: 16px;
      padding: 1.5rem;
      cursor: pointer;
      transition: var(--transition-smooth);
      position: relative;
      display: flex;
      flex-direction: column;
      align-items: center;
      text-align: center;
      min-height: 140px;
      user-select: none;
    }
    
    .payment-option:hover {
      border-color: var(--coral-primary);
      background: rgba(255, 255, 255, 0.15);
      transform: translateY(-3px);
      box-shadow: 0 8px 30px rgba(255, 107, 0, 0.3);
    }
    
    .payment-option.selected {
      border-color: var(--coral-primary);
      background: rgba(255, 107, 0, 0.15);
      box-shadow: 0 8px 30px rgba(255, 107, 0, 0.4);
      transform: translateY(-2px);
    }
    
    .payment-option.selected::before {
      content: "✓";
      position: absolute;
      top: 10px;
      right: 15px;
      background: var(--coral-primary);
      color: #1a1a1a;
      width: 28px;
      height: 28px;
      border-radius: 50%;
      display: flex !important;
      align-items: center;
      justify-content: center;
      font-weight: bold;
      font-size: 16px;
      box-shadow: 0 2px 8px rgba(255, 107, 0, 0.4);
      z-index: 10;
      animation: checkmarkPop 0.3s ease-out;
    }
    
    @keyframes checkmarkPop {
      0% {
        transform: scale(0);
        opacity: 0;
      }
      50% {
        transform: scale(1.2);
      }
      100% {
        transform: scale(1);
        opacity: 1;
      }
    }
    
    .payment-option-icon {
      width: 50px;
      height: 50px;
      background: var(--coral-primary);
      border-radius: 12px;
      display: flex;
      align-items: center;
      justify-content: center;
      margin-bottom: 1rem;
      transition: var(--transition-smooth);
    }
    
    .payment-option-icon i {
      font-size: 1.8rem;
      color: white;
    }
    
    .payment-option:hover .payment-option-icon {
      transform: scale(1.1);
      box-shadow: 0 4px 15px rgba(255, 107, 0, 0.4);
    }
    
    .payment-option.selected .payment-option-icon {
      transform: scale(1.05);
      box-shadow: 0 4px 20px rgba(255, 107, 0, 0.5);
      background: linear-gradient(135deg, var(--coral-primary), #ff8c42);
    }
    
    .payment-option-details h4 {
      color: var(--warm-gray);
      font-size: 1.1rem;
      font-weight: 700;
      margin-bottom: 0.5rem;
      transition: var(--transition-smooth);
    }
    
    .payment-option.selected .payment-option-details h4 {
      color: #1a1a1a;
    }
    
    .payment-option-details p {
      color: #333;
      font-size: 0.9rem;
      margin-bottom: 0.5rem;
      line-height: 1.4;
      transition: var(--transition-smooth);
    }
    
    .payment-option.selected .payment-option-details p {
      color: #1a1a1a;
    }
    
    .payment-badge {
      display: inline-block;
      padding: 0.3rem 0.8rem;
      border-radius: 12px;
      font-size: 0.75rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }
    
    .payment-badge.recommended {
      background: linear-gradient(135deg, #27ae60, #2ecc71);
      color: #1a1a1a;
    }
    
    .payment-badge.cod {
      background: linear-gradient(135deg, #f39c12, #e67e22);
      color: #1a1a1a;
    }
    
    .payment-option input[type="radio"] {
      position: absolute;
      opacity: 0;
      width: 0;
      height: 0;
      pointer-events: none;
    }
    
    .payment-selected-badge {
      position: absolute;
      top: 12px;
      right: 12px;
      background: var(--coral-primary);
      color: white;
      width: 28px;
      height: 28px;
      border-radius: 50%;
      display: none;
      align-items: center;
      justify-content: center;
      font-size: 14px;
      font-weight: bold;
      box-shadow: 0 2px 8px rgba(255, 107, 0, 0.4);
      z-index: 10;
      animation: badgePop 0.3s ease-out;
    }
    
    .payment-selected-badge.show {
      display: flex !important;
    }
    
    @keyframes badgePop {
      0% {
        transform: scale(0);
        opacity: 0;
      }
      50% {
        transform: scale(1.2);
      }
      100% {
        transform: scale(1);
        opacity: 1;
      }
    }
    
    @media (max-width: 768px) {
      .container {
        margin: 1rem;
        padding: 1.5rem;
      }
      
      .header {
        flex-direction: column;
        gap: 1rem;
        text-align: center;
      }
      
      .logo h1 {
        font-size: 1.8rem;
      }
      
      .user-info {
        flex-direction: column;
        align-items: center;
        gap: 0.5rem;
      }
      
      .tabs {
        flex-direction: column;
        gap: 0.5rem;
      }
      
      .tab {
        width: 100%;
        justify-content: center;
      }
      
      .cylinder-grid {
        grid-template-columns: 1fr;
        gap: 1rem;
      }
      
      .form-grid {
        grid-template-columns: 1fr;
        gap: 1rem;
      }
      
      .payment-method-selection {
        grid-template-columns: 1fr;
        gap: 1rem;
      }
      
      .payment-option {
        flex-direction: row;
        align-items: center;
        text-align: left;
        padding: 1.2rem;
        min-height: 80px;
        touch-action: manipulation;
      }
      
      .payment-option:active {
        transform: translateY(0) scale(0.98);
      }
      
      .payment-option-icon {
        margin-bottom: 0;
        margin-right: 1rem;
        width: 40px;
        height: 40px;
        flex-shrink: 0;
      }
      
      .payment-option-icon i {
        font-size: 1.4rem;
      }
      
      .payment-option-details {
        flex: 1;
      }
      
      .orders-table {
        font-size: 0.85rem;
      }
      
      .orders-table th,
      .orders-table td {
        padding: 0.5rem 0.25rem;
      }
      
      .notification-popup {
        left: 1rem;
        right: 1rem;
        width: auto;
      }
      
      .alert {
        padding: 1rem;
        margin-bottom: 1rem;
      }
    }

    @media (max-width: 480px) {
      .container {
        margin: 0.5rem;
        padding: 1rem;
      }
      
      .logo h1 {
        font-size: 1.5rem;
      }
      
      .orders-table {
        display: block;
        overflow-x: auto;
        white-space: nowrap;
        -webkit-overflow-scrolling: touch;
      }
      
      .cylinder-card {
        padding: 1rem;
      }
      
      .payment-option {
        padding: 0.8rem;
      }
      
      .btn {
        padding: 0.8rem 1.5rem;
        font-size: 0.9rem;
      }
    }

    /* Payment Badges */
    .payment-badge {
      padding: 0.4rem 0.8rem;
      border-radius: 10px;
      font-size: 0.75rem;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      display: inline-flex;
      align-items: center;
      gap: 0.4rem;
    }

    .payment-pending {
      background: rgba(255, 193, 7, 0.2);
      color: #f39c12;
      border: 1px solid rgba(255, 193, 7, 0.3);
    }

    .payment-paid {
      background: rgba(40, 167, 69, 0.2);
      color: #28a745;
      border: 1px solid rgba(40, 167, 69, 0.3);
    }

    .payment-failed {
      background: rgba(220, 53, 69, 0.2);
      color: #dc3545;
      border: 1px solid rgba(220, 53, 69, 0.3);
    }

    /* Action Buttons */
    .action-buttons {
      display: flex;
      gap: 0.5rem;
      flex-wrap: wrap;
    }

    .btn-pay {
      background: linear-gradient(135deg, #28a745, #20c997);
      color: white;
      border: none;
      padding: 0.6rem 1.2rem;
      border-radius: 10px;
      font-size: 0.8rem;
      font-weight: 600;
      cursor: pointer;
      transition: all 0.3s ease;
      display: inline-flex;
      align-items: center;
      gap: 0.5rem;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      box-shadow: 0 2px 10px rgba(40, 167, 69, 0.3);
    }

    .btn-pay:hover {
      transform: translateY(-2px);
      box-shadow: 0 4px 20px rgba(40, 167, 69, 0.4);
      background: linear-gradient(135deg, #218838, #1d9f7a);
    }

    .btn-pay:active {
      transform: translateY(0);
    }

    /* Cancel Button Styling */
    .btn-cancel {
      background: linear-gradient(135deg, #e74c3c, #c0392b);
      color: #1a1a1a;
      border: none;
      padding: 0.5rem 1rem;
      border-radius: 8px;
      font-size: 0.8rem;
      font-weight: 600;
      cursor: pointer;
      transition: var(--transition-smooth);
      display: inline-flex;
      align-items: center;
      gap: 0.5rem;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }

    .btn-cancel:hover {
      transform: translateY(-2px);
      box-shadow: 0 8px 20px rgba(231, 76, 60, 0.3);
      background: linear-gradient(135deg, #c0392b, #a93226);
    }

    .btn-cancel:active {
      transform: translateY(0);
    }

    /* Modal Styling */
    .modal {
      display: none;
      position: fixed;
      z-index: 1000;
      left: 0;
      top: 0;
      width: 100%;
      height: 100%;
      background-color: rgba(0, 0, 0, 0.5);
      backdrop-filter: blur(5px);
    }

    .modal-content {
      background: var(--glass-effect);
      backdrop-filter: blur(30px);
      margin: 5% auto;
      padding: 2.5rem;
      border: 1px solid rgba(255, 255, 255, 0.2);
      border-radius: 20px;
      width: 90%;
      max-width: 500px;
      max-height: 90vh;
      overflow-y: auto;
      box-shadow: var(--shadow-medium);
      position: relative;
      animation: modalSlideIn 0.3s ease-out;
    }

    /* Custom scrollbar for modal */
    .modal-content::-webkit-scrollbar {
      width: 8px;
    }

    .modal-content::-webkit-scrollbar-track {
      background: rgba(255, 255, 255, 0.1);
      border-radius: 4px;
    }

    .modal-content::-webkit-scrollbar-thumb {
      background: rgba(255, 255, 255, 0.3);
      border-radius: 4px;
    }

    .modal-content::-webkit-scrollbar-thumb:hover {
      background: rgba(255, 255, 255, 0.5);
    }

    @keyframes modalSlideIn {
      from {
        opacity: 0;
        transform: translateY(-50px) scale(0.9);
      }
      to {
        opacity: 1;
        transform: translateY(0) scale(1);
      }
    }

    .modal-header {
      display: flex;
      align-items: center;
      gap: 1rem;
      margin-bottom: 2rem;
      padding-bottom: 1rem;
      border-bottom: 1px solid rgba(255, 255, 255, 0.2);
    }

    .modal-header h3 {
      color: var(--danger);
      margin: 0;
      font-size: 1.5rem;
      font-weight: 700;
    }

    .modal-header .icon {
      background: linear-gradient(135deg, #e74c3c, #c0392b);
      color: white;
      padding: 1rem;
      border-radius: 50%;
      font-size: 1.2rem;
    }

    .modal-body {
      margin-bottom: 2rem;
      color: var(--warm-gray);
      line-height: 1.6;
    }

    .modal-body .order-details {
      background: rgba(255, 255, 255, 0.1);
      padding: 1.5rem;
      border-radius: 12px;
      margin: 1rem 0;
      border: 1px solid rgba(255, 255, 255, 0.2);
    }

    .modal-footer {
      display: flex;
      gap: 1rem;
      justify-content: flex-end;
    }

    .btn-modal {
      padding: 0.8rem 2rem;
      border: none;
      border-radius: 10px;
      font-weight: 600;
      cursor: pointer;
      transition: var(--transition-smooth);
      text-transform: uppercase;
      letter-spacing: 0.5px;
      font-size: 0.9rem;
    }

    .btn-modal.cancel {
      background: rgba(255, 255, 255, 0.2);
      color: var(--warm-gray);
      border: 1px solid rgba(255, 255, 255, 0.3);
    }

    .btn-modal.cancel:hover {
      background: rgba(255, 255, 255, 0.3);
      transform: translateY(-2px);
    }

    .btn-modal.confirm {
      background: linear-gradient(135deg, #e74c3c, #c0392b);
      color: white;
    }

    .btn-modal.confirm:hover {
      background: linear-gradient(135deg, #c0392b, #a93226);
      transform: translateY(-2px);
      box-shadow: 0 8px 20px rgba(231, 76, 60, 0.3);
    }

    /* Payment Modal Specific Styles */
    .payment-modal {
      max-width: 600px;
      max-height: 95vh;
      overflow-y: auto;
    }

    .modal-header .icon.payment {
      background: linear-gradient(135deg, #28a745, #20c997);
    }

    .modal-header .icon.payment + h3 {
      color: var(--success);
    }

    .payment-info {
      background: rgba(40, 167, 69, 0.1);
      border: 1px solid rgba(40, 167, 69, 0.3);
      border-radius: 12px;
      padding: 1.5rem;
      margin-bottom: 2rem;
      text-align: center;
    }

    .payment-info p {
      margin: 0.5rem 0;
      font-size: 1.1rem;
    }

    .payment-methods {
      display: flex;
      flex-direction: column;
      gap: 1rem;
    }

    .payment-method {
      background: rgba(255, 255, 255, 0.1);
      border: 2px solid rgba(255, 255, 255, 0.2);
      border-radius: 15px;
      padding: 1.5rem;
      cursor: pointer;
      transition: all 0.3s ease;
      display: flex;
      align-items: center;
      gap: 1.5rem;
    }

    .payment-method:hover {
      background: rgba(255, 255, 255, 0.15);
      border-color: rgba(40, 167, 69, 0.5);
      transform: translateY(-2px);
    }

    .payment-method.selected {
      background: rgba(40, 167, 69, 0.2);
      border-color: var(--success);
      box-shadow: 0 4px 15px rgba(40, 167, 69, 0.3);
    }

    .payment-icon {
      background: linear-gradient(135deg, var(--primary), var(--secondary));
      color: white;
      padding: 1rem;
      border-radius: 50%;
      font-size: 1.5rem;
      display: flex;
      align-items: center;
      justify-content: center;
      min-width: 60px;
      height: 60px;
    }

    .payment-details {
      flex: 1;
    }

    .payment-details h4 {
      margin: 0 0 0.5rem 0;
      color: var(--warm-gray);
      font-size: 1.2rem;
      font-weight: 600;
    }

    .payment-details p {
      margin: 0;
      color: rgba(255, 255, 255, 0.7);
      font-size: 0.9rem;
    }

    .payment-radio {
      display: flex;
      align-items: center;
    }

    .payment-radio input[type="radio"] {
      width: 20px;
      height: 20px;
      accent-color: var(--success);
    }

    .btn-modal.confirm:disabled {
      background: rgba(255, 255, 255, 0.1);
      color: rgba(255, 255, 255, 0.5);
      cursor: not-allowed;
      transform: none;
    }

    .btn-modal.confirm:disabled:hover {
      background: rgba(255, 255, 255, 0.1);
      transform: none;
      box-shadow: none;
    }

    /* Alert Messages */
    .alert {
      padding: 1.2rem 1.8rem;
      border-radius: 16px;
      margin-bottom: 2rem;
      font-weight: 600;
      backdrop-filter: blur(10px);
      border: 1px solid;
      display: flex;
      align-items: center;
      gap: 1rem;
      position: relative;
      animation: slideInDown 0.5s ease-out;
    }

    .alert-success {
      background: rgba(39, 174, 96, 0.15);
      color: var(--success);
      border-color: rgba(39, 174, 96, 0.3);
    }

    .alert-error {
      background: rgba(231, 76, 60, 0.15);
      color: var(--danger);
      border-color: rgba(231, 76, 60, 0.3);
    }

    .alert-warning {
      background: rgba(243, 156, 18, 0.15);
      color: var(--warning);
      border-color: rgba(243, 156, 18, 0.3);
    }

    .alert-info {
      background: rgba(52, 152, 219, 0.15);
      color: var(--accent);
      border-color: rgba(52, 152, 219, 0.3);
    }

    .alert span {
      flex: 1;
    }

    .alert-close {
      background: none;
      border: none;
      color: inherit;
      cursor: pointer;
      padding: 0.25rem;
      border-radius: 6px;
      transition: var(--transition-smooth);
      opacity: 0.7;
    }

    .alert-close:hover {
      background: rgba(255, 255, 255, 0.2);
      opacity: 1;
    }

    @keyframes slideInDown {
      from {
        opacity: 0;
        transform: translateY(-20px);
      }
      to {
        opacity: 1;
        transform: translateY(0);
      }
    }

    @keyframes slideOutUp {
      from {
        opacity: 1;
        transform: translateY(0);
      }
      to {
        opacity: 0;
        transform: translateY(-20px);
      }
    }

    /* Form Field Validation Styles */
    .field-error {
      animation: shake 0.5s ease-in-out;
    }

    @keyframes shake {
      0%, 100% { transform: translateX(0); }
      25% { transform: translateX(-5px); }
      75% { transform: translateX(5px); }
    }

    /* Enhanced Order Status Indicators */
    .order-status-info {
      margin-top: 0.5rem;
      font-size: 0.8rem;
      color: var(--warm-gray);
      font-style: italic;
    }

    .cancellation-note {
      background: rgba(255, 255, 255, 0.15);
      border: 2px solid rgba(255, 255, 255, 0.3);
      border-radius: 12px;
      padding: 1.5rem;
      margin-top: 1rem;
      color: #ffffff;
      font-size: 0.95rem;
      display: flex;
      align-items: flex-start;
      gap: 0.8rem;
      backdrop-filter: blur(10px);
      box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
      line-height: 1.6;
    }

    .cancellation-note i {
      flex-shrink: 0;
      margin-top: 0.2rem;
      font-size: 1.1rem;
      color: #ffffff;
    }

    .cancellation-note strong {
      color: #ffffff;
      font-weight: 700;
      font-size: 1rem;
    }

    /* Responsive Design */
    @media (max-width: 768px) {
      .header-container {
        padding: 0 1rem;
        flex-direction: column;
        gap: 1rem;
      }
      
      .container {
        padding: 1rem;
      }
      
      .dashboard-title {
        font-size: 2.5rem;
      }
      
      .tabs {
        flex-direction: column;
        gap: 0.5rem;
        margin-bottom: 2rem;
      }
      
      .tab {
        padding: 1rem;
        text-align: center;
      }
      
      .form-grid {
        grid-template-columns: 1fr;
      }
      
      .cylinder-grid {
        grid-template-columns: 1fr;
      }
      
      .user-details {
        display: none;
      }
      
      .orders-table {
        font-size: 0.8rem;
      }
      
           
      .orders-table th,
      .orders-table td {
        padding: 1rem;
      }
    }

    @media (max-width: 480px) {
      .tab-content {
        padding: 2rem;
      }
      
      .dashboard-title {
        font-size: 2rem;
      }
      
      .btn {
        padding: 1rem 2rem;
        font-size: 0.9rem;
      }
      
      .modal-content {
        margin: 2% auto;
        padding: 2rem;
        width: 95%;
        max-height: 95vh;
        overflow-y: auto;
      }
      
      .payment-modal {
        max-width: 95%;
        max-height: 95vh;
      }
      
      .payment-methods {
        gap: 0.8rem;
      }
      
      .payment-method {
        padding: 1.2rem;
        flex-direction: column;
        text-align: center;
        gap: 1rem;
      }
      
      .payment-icon {
        min-width: 50px;
        height: 50px;
        font-size: 1.2rem;
      }
      
      .action-buttons {
        flex-direction: column;
        gap: 0.3rem;
      }
      
      .btn-pay, .btn-cancel {
        width: 100%;
        justify-content: center;
      }
    }

    /* Payment Badges */
    .payment-badge {
      padding: 0.4rem 0.8rem;
      border-radius: 10px;
      font-size: 0.75rem;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      display: inline-flex;
      align-items: center;
      gap: 0.4rem;
    }

    .payment-pending {
      background: rgba(255, 193, 7, 0.2);
      color: #f39c12;
      border: 1px solid rgba(255, 193, 7, 0.3);
    }

    .payment-paid {
      background: rgba(40, 167, 69, 0.2);
      color: #28a745;
      border: 1px solid rgba(40, 167, 69, 0.3);
    }

    .payment-failed {
      background: rgba(220, 53, 69, 0.2);
      color: #dc3545;
      border: 1px solid rgba(220, 53, 69, 0.3);
    }

    /* Action Buttons */
    .action-buttons {
      display: flex;
      gap: 0.5rem;
      flex-wrap: wrap;
    }

    .btn-pay {
      background: linear-gradient(135deg, #28a745, #20c997);
      color: white;
      border: none;
      padding: 0.6rem 1.2rem;
      border-radius: 10px;
      font-size: 0.8rem;
      font-weight: 600;
      cursor: pointer;
      transition: all 0.3s ease;
      display: inline-flex;
      align-items: center;
      gap: 0.5rem;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      box-shadow: 0 2px 10px rgba(40, 167, 69, 0.3);
    }

    .btn-pay:hover {
      transform: translateY(-2px);
      box-shadow: 0 4px 20px rgba(40, 167, 69, 0.4);
      background: linear-gradient(135deg, #218838, #1d9f7a);
    }

    .btn-pay:active {
      transform: translateY(0);
    }

    /* Cancel Button Styling */
    .btn-cancel {
      background: linear-gradient(135deg, #e74c3c, #c0392b);
      color: #1a1a1a;
      border: none;
      padding: 0.5rem 1rem;
      border-radius: 8px;
      font-size: 0.8rem;
      font-weight: 600;
      cursor: pointer;
      transition: var(--transition-smooth);
      display: inline-flex;
      align-items: center;
      gap: 0.5rem;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }

    .btn-cancel:hover {
      transform: translateY(-2px);
      box-shadow: 0 8px 20px rgba(231, 76, 60, 0.3);
      background: linear-gradient(135deg, #c0392b, #a93226);
    }

    .btn-cancel:active {
      transform: translateY(0);
    }

    /* Modal Styling */
    .modal {
      display: none;
      position: fixed;
      z-index: 1000;
      left: 0;
      top: 0;
      width: 100%;
      height: 100%;
      background-color: rgba(0, 0, 0, 0.5);
      backdrop-filter: blur(5px);
    }

    .modal-content {
      background: var(--glass-effect);
      backdrop-filter: blur(30px);
      margin: 5% auto;
      padding: 2.5rem;
      border: 1px solid rgba(255, 255, 255, 0.2);
      border-radius: 20px;
      width: 90%;
      max-width: 500px;
      max-height: 90vh;
      overflow-y: auto;
      box-shadow: var(--shadow-medium);
      position: relative;
      animation: modalSlideIn 0.3s ease-out;
    }

    /* Custom scrollbar for modal */
    .modal-content::-webkit-scrollbar {
      width: 8px;
    }

    .modal-content::-webkit-scrollbar-track {
      background: rgba(255, 255, 255, 0.1);
      border-radius: 4px;
    }

    .modal-content::-webkit-scrollbar-thumb {
      background: rgba(255, 255, 255, 0.3);
      border-radius: 4px;
    }

    .modal-content::-webkit-scrollbar-thumb:hover {
      background: rgba(255, 255, 255, 0.5);
    }

    @keyframes modalSlideIn {
      from {
        opacity: 0;
        transform: translateY(-50px) scale(0.9);
      }
      to {
        opacity: 1;
        transform: translateY(0) scale(1);
      }
    }

    .modal-header {
      display: flex;
      align-items: center;
      gap: 1rem;
      margin-bottom: 2rem;
      padding-bottom: 1rem;
      border-bottom: 1px solid rgba(255, 255, 255, 0.2);
    }

    .modal-header h3 {
      color: var(--danger);
      margin: 0;
      font-size: 1.5rem;
      font-weight: 700;
    }

    .modal-header .icon {
      background: linear-gradient(135deg, #e74c3c, #c0392b);
      color: white;
      padding: 1rem;
      border-radius: 50%;
      font-size: 1.2rem;
    }

    .modal-body {
      margin-bottom: 2rem;
      color: var(--warm-gray);
      line-height: 1.6;
    }

    .modal-body .order-details {
      background: rgba(255, 255, 255, 0.1);
      padding: 1.5rem;
      border-radius: 12px;
      margin: 1rem 0;
      border: 1px solid rgba(255, 255, 255, 0.2);
    }

    .modal-footer {
      display: flex;
      gap: 1rem;
      justify-content: flex-end;
    }

    .btn-modal {
      padding: 0.8rem 2rem;
      border: none;
      border-radius: 10px;
      font-weight: 600;
      cursor: pointer;
      transition: var(--transition-smooth);
      text-transform: uppercase;
      letter-spacing: 0.5px;
      font-size: 0.9rem;
    }

    .btn-modal.cancel {
      background: rgba(255, 255, 255, 0.2);
      color: var(--warm-gray);
      border: 1px solid rgba(255, 255, 255, 0.3);
    }

    .btn-modal.cancel:hover {
      background: rgba(255, 255, 255, 0.3);
      transform: translateY(-2px);
    }

    .btn-modal.confirm {
      background: linear-gradient(135deg, #e74c3c, #c0392b);
      color: white;
    }

    .btn-modal.confirm:hover {
      background: linear-gradient(135deg, #c0392b, #a93226);
      transform: translateY(-2px);
      box-shadow: 0 8px 20px rgba(231, 76, 60, 0.3);
    }

    /* Payment Modal Specific Styles */
    .payment-modal {
      max-width: 600px;
      max-height: 95vh;
      overflow-y: auto;
    }

    .modal-header .icon.payment {
      background: linear-gradient(135deg, #28a745, #20c997);
    }

    .modal-header .icon.payment + h3 {
      color: var(--success);
    }

    .payment-info {
      background: rgba(40, 167, 69, 0.1);
      border: 1px solid rgba(40, 167, 69, 0.3);
      border-radius: 12px;
      padding: 1.5rem;
      margin-bottom: 2rem;
      text-align: center;
    }

    .payment-info p {
      margin: 0.5rem 0;
      font-size: 1.1rem;
    }

    .payment-methods {
      display: flex;
      flex-direction: column;
      gap: 1rem;
    }

    .payment-method {
      background: rgba(255, 255, 255, 0.1);
      border: 2px solid rgba(255, 255, 255, 0.2);
      border-radius: 15px;
      padding: 1.5rem;
      cursor: pointer;
      transition: all 0.3s ease;
      display: flex;
      align-items: center;
      gap: 1.5rem;
    }

    .payment-method:hover {
      background: rgba(255, 255, 255, 0.15);
      border-color: rgba(40, 167, 69, 0.5);
      transform: translateY(-2px);
    }

    .payment-method.selected {
      background: rgba(40, 167, 69, 0.2);
      border-color: var(--success);
      box-shadow: 0 4px 15px rgba(40, 167, 69, 0.3);
    }

    .payment-icon {
      background: linear-gradient(135deg, var(--primary), var(--secondary));
      color: white;
      padding: 1rem;
      border-radius: 50%;
      font-size: 1.5rem;
      display: flex;
      align-items: center;
      justify-content: center;
      min-width: 60px;
      height: 60px;
    }

    .payment-details {
      flex: 1;
    }

    .payment-details h4 {
      margin: 0 0 0.5rem 0;
      color: var(--warm-gray);
      font-size: 1.2rem;
      font-weight: 600;
    }

    .payment-details p {
      margin: 0;
      color: rgba(255, 255, 255, 0.7);
      font-size: 0.9rem;
    }

    .payment-radio {
      display: flex;
      align-items: center;
    }

    .payment-radio input[type="radio"] {
      width: 20px;
      height: 20px;
      accent-color: var(--success);
    }

    .btn-modal.confirm:disabled {
      background: rgba(255, 255, 255, 0.1);
      color: rgba(255, 255, 255, 0.5);
      cursor: not-allowed;
      transform: none;
    }

    .btn-modal.confirm:disabled:hover {
      background: rgba(255, 255, 255, 0.1);
      transform: none;
      box-shadow: none;
    }

    /* Alert Messages */
    .alert {
      padding: 1.2rem 1.8rem;
      border-radius: 16px;
      margin-bottom: 2rem;
      font-weight: 600;
      backdrop-filter: blur(10px);
      border: 1px solid;
      display: flex;
      align-items: center;
      gap: 1rem;
      position: relative;
      animation: slideInDown 0.5s ease-out;
    }

    .alert-success {
      background: rgba(39, 174, 96, 0.15);
      color: var(--success);
      border-color: rgba(39, 174, 96, 0.3);
    }

    .alert-error {
      background: rgba(231, 76, 60, 0.15);
      color: var(--danger);
      border-color: rgba(231, 76, 60, 0.3);
    }

    .alert-warning {
      background: rgba(243, 156, 18, 0.15);
      color: var(--warning);
      border-color: rgba(243, 156, 18, 0.3);
    }

    .alert-info {
      background: rgba(52, 152, 219, 0.15);
      color: var(--accent);
      border-color: rgba(52, 152, 219, 0.3);
    }

    .alert span {
      flex: 1;
    }

    .alert-close {
      background: none;
      border: none;
      color: inherit;
      cursor: pointer;
      padding: 0.25rem;
      border-radius: 6px;
      transition: var(--transition-smooth);
      opacity: 0.7;
    }

    .alert-close:hover {
      background: rgba(255, 255, 255, 0.2);
      opacity: 1;
    }

    @keyframes slideInDown {
      from {
        opacity: 0;
        transform: translateY(-20px);
      }
      to {
        opacity: 1;
        transform: translateY(0);
      }
    }

    @keyframes slideOutUp {
      from {
        opacity: 1;
        transform: translateY(0);
      }
      to {
        opacity: 0;
        transform: translateY(-20px);
      }
    }

    /* Form Field Validation Styles */
    .field-error {
      animation: shake 0.5s ease-in-out;
    }

    @keyframes shake {
      0%, 100% { transform: translateX(0); }
      25% { transform: translateX(-5px); }
      75% { transform: translateX(5px); }
    }

    /* Enhanced Order Status Indicators */
    .order-status-info {
      margin-top: 0.5rem;
      font-size: 0.8rem;
      color: var(--warm-gray);
      font-style: italic;
    }

    .cancellation-note {
      background: rgba(255, 255, 255, 0.15);
      border: 2px solid rgba(255, 255, 255, 0.3);
      border-radius: 12px;
      padding: 1.5rem;
      margin-top: 1rem;
      color: #ffffff;
      font-size: 0.95rem;
      display: flex;
      align-items: flex-start;
      gap: 0.8rem;
      backdrop-filter: blur(10px);
      box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
      line-height: 1.6;
    }

    .cancellation-note i {
      flex-shrink: 0;
      margin-top: 0.2rem;
      font-size: 1.1rem;
      color: #ffffff;
    }

    .cancellation-note strong {
      color: #ffffff;
      font-weight: 700;
      font-size: 1rem;
    }

    /* Responsive Design */
    @media (max-width: 768px) {
      .header-container {
        padding: 0 1rem;
        flex-direction: column;
        gap: 1rem;
      }
      
      .container {
        padding: 1rem;
      }
      
      .dashboard-title {
        font-size: 2.5rem;
      }
      
      .tabs {
        flex-direction: column;
        gap: 0.5rem;
        margin-bottom: 2rem;
      }
      
      .tab {
        padding: 1rem;
        text-align: center;
      }
      
      .form-grid {
        grid-template-columns: 1fr;
      }
      
      .cylinder-grid {
        grid-template-columns: 1fr;
      }
      
      .user-details {
        display: none;
      }
      
      .orders-table {
        font-size: 0.8rem;
      }
      
      .orders-table th,
      .orders-table td {
        padding: 1rem;
      }
    }

    @media (max-width: 480px) {
      .tab-content {
        padding: 2rem;
      }
      
      .dashboard-title {
        font-size: 2rem;
      }
      
      .btn {
        padding: 1rem 2rem;
        font-size: 0.9rem;
      }
      
      .modal-content {
        margin: 2% auto;
        padding: 2rem;
        width: 95%;
        max-height: 95vh;
        overflow-y: auto;
      }
      
      .payment-modal {
        max-width: 95%;
        max-height: 95vh;
      }
      
      .payment-methods {
        gap: 0.8rem;
      }
      
      .payment-method {
        padding: 1.2rem;
        flex-direction: column;
        text-align: center;
        gap: 1rem;
      }
      
      .payment-icon {
        min-width: 50px;
        height: 50px;
        font-size: 1.2rem;
      }
      
      .action-buttons {
        flex-direction: column;
        gap: 0.3rem;
      }
      
      .btn-pay, .btn-cancel {
        width: 100%;
        justify-content: center;
      }
    }

    /* Payment Badges */
    .payment-badge {
      padding: 0.4rem 0.8rem;
      border-radius: 10px;
      font-size: 0.75rem;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      display: inline-flex;
      align-items: center;
      gap: 0.4rem;
    }

    .payment-pending {
      background: rgba(255, 193, 7, 0.2);
      color: #f39c12;
      border: 1px solid rgba(255, 193, 7, 0.3);
    }

    .payment-paid {
      background: rgba(40, 167, 69, 0.2);
      color: #28a745;
      border: 1px solid rgba(40, 167, 69, 0.3);
    }

    .payment-failed {
      background: rgba(220, 53, 69, 0.2);
      color: #dc3545;
      border: 1px solid rgba(220, 53, 69, 0.3);
    }

    /* Action Buttons */
    .action-buttons {
      display: flex;
      gap: 0.5rem;
      flex-wrap: wrap;
    }

    .btn-pay {
      background: linear-gradient(135deg, #28a745, #20c997);
      color: white;
      border: none;
      padding: 0.6rem 1.2rem;
      border-radius: 10px;
      font-size: 0.8rem;
      font-weight: 600;
      cursor: pointer;
      transition: all 0.3s ease;
      display: inline-flex;
      align-items: center;
      gap: 0.5rem;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      box-shadow: 0 2px 10px rgba(40, 167, 69, 0.3);
    }

    .btn-pay:hover {
      transform: translateY(-2px);
      box-shadow: 0 4px 20px rgba(40, 167, 69, 0.4);
      background: linear-gradient(135deg, #218838, #1d9f7a);
    }

    .btn-pay:active {
      transform: translateY(0);
    }

    /* Cancel Button Styling */
    .btn-cancel {
      background: linear-gradient(135deg, #e74c3c, #c0392b);
      color: #1a1a1a;
      border: none;
      padding: 0.5rem 1rem;
      border-radius: 8px;
      font-size: 0.8rem;
      font-weight: 600;
      cursor: pointer;
      transition: var(--transition-smooth);
      display: inline-flex;
      align-items: center;
      gap: 0.5rem;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }

    .btn-cancel:hover {
      transform: translateY(-2px);
      box-shadow: 0 8px 20px rgba(231, 76, 60, 0.3);
      background: linear-gradient(135deg, #c0392b, #a93226);
    }

    .btn-cancel:active {
      transform: translateY(0);
    }

    /* Modal Styling */
    .modal {
      display: none;
      position: fixed;
      z-index: 1000;
      left: 0;
      top: 0;
      width: 100%;
      height: 100%;
      background-color: rgba(0, 0, 0, 0.5);
      backdrop-filter: blur(5px);
    }

    .modal-content {
      background: var(--glass-effect);
      backdrop-filter: blur(30px);
      margin: 5% auto;
      padding: 2.5rem;
      border: 1px solid rgba(255, 255, 255, 0.2);
      border-radius: 20px;
      width: 90%;
      max-width: 500px;
      max-height: 90vh;
      overflow-y: auto;
      box-shadow: var(--shadow-medium);
      position: relative;
      animation: modalSlideIn 0.3s ease-out;
    }

    /* Custom scrollbar for modal */
    .modal-content::-webkit-scrollbar {
      width: 8px;
    }

    .modal-content::-webkit-scrollbar-track {
      background: rgba(255, 255, 255, 0.1);
      border-radius: 4px;
    }

    .modal-content::-webkit-scrollbar-thumb {
      background: rgba(255, 255, 255, 0.3);
      border-radius: 4px;
    }

    .modal-content::-webkit-scrollbar-thumb:hover {
      background: rgba(255, 255, 255, 0.5);
    }

    @keyframes modalSlideIn {
      from {
        opacity: 0;
        transform: translateY(-50px) scale(0.9);
      }
      to {
        opacity: 1;
        transform: translateY(0) scale(1);
      }
    }

    .modal-header {
      display: flex;
      align-items: center;
      gap: 1rem;
      margin-bottom: 2rem;
      padding-bottom: 1rem;
      border-bottom: 1px solid rgba(255, 255, 255, 0.2);
    }

    .modal-header h3 {
      color: var(--danger);
      margin: 0;
      font-size: 1.5rem;
      font-weight: 700;
    }

    .modal-header .icon {
      background: linear-gradient(135deg, #e74c3c, #c0392b);
      color: white;
      padding: 1rem;
      border-radius: 50%;
      font-size: 1.2rem;
    }

    .modal-body {
      margin-bottom: 2rem;
      color: var(--warm-gray);
      line-height: 1.6;
    }

    .modal-body .order-details {
      background: rgba(255, 255, 255, 0.1);
      padding: 1.5rem;
      border-radius: 12px;
      margin: 1rem 0;
      border: 1px solid rgba(255, 255, 255, 0.2);
    }

    .modal-footer {
      display: flex;
      gap: 1rem;
      justify-content: flex-end;
    }

    .btn-modal {
      padding: 0.8rem 2rem;
      border: none;
      border-radius: 10px;
      font-weight: 600;
      cursor: pointer;
      transition: var(--transition-smooth);
      text-transform: uppercase;
      letter-spacing: 0.5px;
      font-size: 0.9rem;
    }

    .btn-modal.cancel {
      background: rgba(255, 255, 255, 0.2);
      color: var(--warm-gray);
      border: 1px solid rgba(255, 255, 255, 0.3);
    }

    .btn-modal.cancel:hover {
      background: rgba(255, 255, 255, 0.3);
      transform: translateY(-2px);
    }

    .btn-modal.confirm {
      background: linear-gradient(135deg, #e74c3c, #c0392b);
      color: white;
    }

    .btn-modal.confirm:hover {
      background: linear-gradient(135deg, #c0392b, #a93226);
      transform: translateY(-2px);
      box-shadow: 0 8px 20px rgba(231, 76, 60, 0.3);
    }

    /* Payment Modal Specific Styles */
    .payment-modal {
      max-width: 600px;
      max-height: 95vh;
      overflow-y: auto;
    }

    .modal-header .icon.payment {
      background: linear-gradient(135deg, #28a745, #20c997);
    }

    .modal-header .icon.payment + h3 {
      color: var(--success);
    }

    .payment-info {
      background: rgba(40, 167, 69, 0.1);
      border: 1px solid rgba(40, 167, 69, 0.3);
      border-radius: 12px;
      padding: 1.5rem;
      margin-bottom: 2rem;
      text-align: center;
    }

    .payment-info p {
      margin: 0.5rem 0;
      font-size: 1.1rem;
    }

    .payment-methods {
      display: flex;
      flex-direction: column;
      gap: 1rem;
    }

    .payment-method {
      background: rgba(255, 255, 255, 0.1);
      border: 2px solid rgba(255, 255, 255, 0.2);
      border-radius: 15px;
      padding: 1.5rem;
      cursor: pointer;
      transition: all 0.3s ease;
      display: flex;
      align-items: center;
      gap: 1.5rem;
    }

    .payment-method:hover {
      background: rgba(255, 255, 255, 0.15);
      border-color: rgba(40, 167, 69, 0.5);
      transform: translateY(-2px);
    }

    .payment-method.selected {
      background: rgba(40, 167, 69, 0.2);
      border-color: var(--success);
      box-shadow: 0 4px 15px rgba(40, 167, 69, 0.3);
    }

    .payment-icon {
      background: linear-gradient(135deg, var(--primary), var(--secondary));
      color: white;
      padding: 1rem;
      border-radius: 50%;
      font-size: 1.5rem;
      display: flex;
      align-items: center;
      justify-content: center;
      min-width: 60px;
      height: 60px;
    }

    .payment-details {
      flex: 1;
    }

    .payment-details h4 {
      margin: 0 0 0.5rem 0;
      color: var(--warm-gray);
      font-size: 1.2rem;
      font-weight: 600;
    }

    .payment-details p {
      margin: 0;
      color: rgba(255, 255, 255, 0.7);
      font-size: 0.9rem;
    }

    .payment-radio {
      display: flex;
      align-items: center;
    }

    .payment-radio input[type="radio"] {
      width: 20px;
      height: 20px;
      accent-color: var(--success);
    }

    .btn-modal.confirm:disabled {
      background: rgba(255, 255, 255, 0.1);
      color: rgba(255, 255, 255, 0.5);
      cursor: not-allowed;
      transform: none;
    }

    .btn-modal.confirm:disabled:hover {
      background: rgba(255, 255, 255, 0.1);
      transform: none;
      box-shadow: none;
    }

    /* Alert Messages */
    .alert {
      padding: 1.2rem 1.8rem;
      border-radius: 16px;
      margin-bottom: 2rem;
      font-weight: 600;
      backdrop-filter: blur(10px);
      border: 1px solid;
      display: flex;
      align-items: center;
      gap: 1rem;
      position: relative;
      animation: slideInDown 0.5s ease-out;
    }

    .alert-success {
      background: rgba(39, 174, 96, 0.15);
      color: var(--success);
      border-color: rgba(39, 174, 96, 0.3);
    }

    .alert-error {
      background: rgba(231, 76, 60, 0.15);
      color: var(--danger);
      border-color: rgba(231, 76, 60, 0.3);
    }

    .alert-warning {
      background: rgba(243, 156, 18, 0.15);
      color: var(--warning);
      border-color: rgba(243, 156, 18, 0.3);
    }

    .alert-info {
      background: rgba(52, 152, 219, 0.15);
      color: var(--accent);
      border-color: rgba(52, 152, 219, 0.3);
    }

    .alert span {
      flex: 1;
    }

    .alert-close {
      background: none;
      border: none;
      color: inherit;
      cursor: pointer;
      padding: 0.25rem;
      border-radius: 6px;
      transition: var(--transition-smooth);
      opacity: 0.7;
    }

    .alert-close:hover {
      background: rgba(255, 255, 255, 0.2);
      opacity: 1;
    }

    @keyframes slideInDown {
      from {
        opacity: 0;
        transform: translateY(-20px);
      }
      to {
        opacity: 1;
        transform: translateY(0);
      }
    }

    @keyframes slideOutUp {
      from {
        opacity: 1;
        transform: translateY(0);
      }
      to {
        opacity: 0;
        transform: translateY(-20px);
      }
    }

    /* Form Field Validation Styles */
    .field-error {
      animation: shake 0.5s ease-in-out;
    }

    @keyframes shake {
      0%, 100% { transform: translateX(0); }
      25% { transform: translateX(-5px); }
      75% { transform: translateX(5px); }
    }

    /* Enhanced Order Status Indicators */
    .order-status-info {
      margin-top: 0.5rem;
      font-size: 0.8rem;
      color: var(--warm-gray);
      font-style: italic;
    }

    .cancellation-note {
      background: rgba(255, 255, 255, 0.15);
      border: 2px solid rgba(255, 255, 255, 0.3);
      border-radius: 12px;
      padding: 1.5rem;
      margin-top: 1rem;
      color: #ffffff;
      font-size: 0.95rem;
      display: flex;
      align-items: flex-start;
      gap: 0.8rem;
      backdrop-filter: blur(10px);
      box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
      line-height: 1.6;
    }

    .cancellation-note i {
      flex-shrink: 0;
      margin-top: 0.2rem;
      font-size: 1.1rem;
      color: #ffffff;
    }

    .cancellation-note strong {
      color: #ffffff;
      font-weight: 700;
      font-size: 1rem;
    }

    /* Responsive Design */
    @media (max-width: 768px) {
      .header-container {
        padding: 0 1rem;
        flex-direction: column;
        gap: 1rem;
      }
      
      .container {
        padding: 1rem;
      }
      
      .dashboard-title {
        font-size: 2.5rem;
      }
      
      .tabs {
        flex-direction: column;
        gap: 0.5rem;
        margin-bottom: 2rem;
      }
      
      .tab {
        padding: 1rem;
        text-align: center;
      }
      
      .form-grid {
        grid-template-columns: 1fr;
      }
      
      .cylinder-grid {
        grid-template-columns: 1fr;
      }
      
      .user-details {
        display: none;
      }
      
      .orders-table {
        font-size: 0.8rem;
      }
      
      .orders-table th,
      .orders-table td {
        padding: 1rem;
      }
    }

    @media (max-width: 480px) {
      .tab-content {
        padding: 2rem;
      }
      
      .dashboard-title {
        font-size: 2rem;
      }
      
      .btn {
        padding: 1rem 2rem;
        font-size: 0.9rem;
      }
      
      .modal-content {
        margin: 2% auto;
        padding: 2rem;
        width: 95%;
        max-height: 95vh;
        overflow-y: auto;
      }
      
      .payment-modal {
        max-width: 95%;
        max-height: 95vh;
      }
      
      .payment-methods {
        gap: 0.8rem;
      }
      
      .payment-method {
        padding: 1.2rem;
        flex-direction: column;
        text-align: center;
        gap: 1rem;
      }
      
      .payment-icon {
        min-width: 50px;
        height: 50px;
        font-size: 1.2rem;
      }
      
      .action-buttons {
        flex-direction: column;
        gap: 0.3rem;
      }
      
      .btn-pay, .btn-cancel {
        width: 100%;
        justify-content: center;
      }
    }

    /* Payment Badges */
    .payment-badge {
      padding: 0.4rem 0.8rem;
      border-radius: 10px;
      font-size: 0.75rem;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      display: inline-flex;
      align-items: center;
      gap: 0.4rem;
    }

    .payment-pending {
      background: rgba(255, 193, 7, 0.2);
      color: #f39c12;
      border: 1px solid rgba(255, 193, 7, 0.3);
    }

    .payment-paid {
      background: rgba(40, 167, 69, 0.2);
      color: #28a745;
      border: 1px solid rgba(40, 167, 69, 0.3);
    }

    .payment-failed {
      background: rgba(220, 53, 69, 0.2);
      color: #dc3545;
      border: 1px solid rgba(220, 53, 69, 0.3);
    }

    /* Action Buttons */
    .action-buttons {
      display: flex;
      gap: 0.5rem;
      flex-wrap: wrap;
    }

    .btn-pay {
      background: linear-gradient(135deg, #28a745, #20c997);
      color: white;
      border: none;
      padding: 0.6rem 1.2rem;
      border-radius: 10px;
      font-size: 0.8rem;
      font-weight: 600;
      cursor: pointer;
      transition: all 0.3s ease;
      display: inline-flex;
      align-items: center;
      gap: 0.5rem;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      box-shadow: 0 2px 10px rgba(40, 167, 69, 0.3);
    }

    .btn-pay:hover {
      transform: translateY(-2px);
      box-shadow: 0 4px 20px rgba(40, 167, 69, 0.4);
      background: linear-gradient(135deg, #218838, #1d9f7a);
    }

    .btn-pay:active {
      transform: translateY(0);
    }

    /* Cancel Button Styling */
    .btn-cancel {
      background: linear-gradient(135deg, #e74c3c, #c0392b);
      color: #1a1a1a;
      border: none;
      padding: 0.5rem 1rem;
      border-radius: 8px;
      font-size: 0.8rem;
      font-weight: 600;
      cursor: pointer;
      transition: var(--transition-smooth);
      display: inline-flex;
      align-items: center;
      gap: 0.5rem;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }

    .btn-cancel:hover {
      transform: translateY(-2px);
      box-shadow: 0 8px 20px rgba(231, 76, 60, 0.3);
      background: linear-gradient(135deg, #c0392b, #a93226);
    }

    .btn-cancel:active {
      transform: translateY(0);
    }

    /* Modal Styling */
    .modal {
      display: none;
      position: fixed;
      z-index: 1000;
      left: 0;
      top: 0;
      width: 100%;
      height: 100%;
      background-color: rgba(0, 0, 0, 0.5);
      backdrop-filter: blur(5px);
    }

    .modal-content {
      background: var(--glass-effect);
      backdrop-filter: blur(30px);
      margin: 5% auto;
      padding: 2.5rem;
      border: 1px solid rgba(255, 255, 255, 0.2);
      border-radius: 20px;
      width: 90%;
      max-width: 500px;
      max-height: 90vh;
      overflow-y: auto;
      box-shadow: var(--shadow-medium);
      position: relative;
      animation: modalSlideIn 0.3s ease-out;
    }

    /* Custom scrollbar for modal */
    .modal-content::-webkit-scrollbar {
      width: 8px;
    }

    .modal-content::-webkit-scrollbar-track {
      background: rgba(255, 255, 255, 0.1);
      border-radius: 4px;
    }

    .modal-content::-webkit-scrollbar-thumb {
      background: rgba(255, 255, 255, 0.3);
      border-radius: 4px;
    }

    .modal-content::-webkit-scrollbar-thumb:hover {
      background: rgba(255, 255, 255, 0.5);
    }

    @keyframes modalSlideIn {
      from {
        opacity: 0;
        transform: translateY(-50px) scale(0.9);
      }
      to {
        opacity: 1;
        transform: translateY(0) scale(1);
      }
    }

    .modal-header {
      display: flex;
      align-items: center;
      gap: 1rem;
      margin-bottom: 2rem;
      padding-bottom: 1rem;
      border-bottom: 1px solid rgba(255, 255, 255, 0.2);
    }

    .modal-header h3 {
      color: var(--danger);
      margin: 0;
      font-size: 1.5rem;
      font-weight: 700;
    }

    .modal-header .icon {
      background: linear-gradient(135deg, #e74c3c, #c0392b);
      color: white;
      padding: 1rem;
      border-radius: 50%;
      font-size: 1.2rem;
    }

    .modal-body {
      margin-bottom: 2rem;
      color: var(--warm-gray);
      line-height: 1.6;
    }

    .modal-body .order-details {
      background: rgba(255, 255, 255, 0.1);
      padding: 1.5rem;
      border-radius: 12px;
      margin: 1rem 0;
      border: 1px solid rgba(255, 255, 255, 0.2);
    }

    .modal-footer {
      display: flex;
      gap: 1rem;
      justify-content: flex-end;
    }

    .btn-modal {
      padding: 0.8rem 2rem;
      border: none;
      border-radius: 10px;
      font-weight: 600;
      cursor: pointer;
      transition: var(--transition-smooth);
      text-transform: uppercase;
      letter-spacing: 0.5px;
      font-size: 0.9rem;
    }

    .btn-modal.cancel {
      background: rgba(255, 255, 255, 0.2);
      color: var(--warm-gray);
      border: 1px solid rgba(255, 255, 255, 0.3);
    }

    .btn-modal.cancel:hover {
      background: rgba(255, 255, 255, 0.3);
      transform: translateY(-2px);
    }

    .btn-modal.confirm {
      background: linear-gradient(135deg, #e74c3c, #c0392b);
      color: white;
    }

    .btn-modal.confirm:hover {
      background: linear-gradient(135deg, #c0392b, #a93226);
      transform: translateY(-2px);
      box-shadow: 0 8px 20px rgba(231, 76, 60, 0.3);
    }

    /* Payment Modal Specific Styles */
    .payment-modal {
      max-width: 600px;
      max-height: 95vh;
      overflow-y: auto;
    }

    .modal-header .icon.payment {
      background: linear-gradient(135deg, #28a745, #20c997);
    }

    .modal-header .icon.payment + h3 {
      color: var(--success);
    }

    .payment-info {
      background: rgba(40, 167, 69, 0.1);
      border: 1px solid rgba(40, 167, 69, 0.3);
      border-radius: 12px;
      padding: 1.5rem;
      margin-bottom: 2rem;
      text-align: center;
    }

    .payment-info p {
      margin: 0.5rem 0;
      font-size: 1.1rem;
    }

    .payment-methods {
      display: flex;
      flex-direction: column;
      gap: 1rem;
    }

    .payment-method {
      background: rgba(255, 255, 255, 0.1);
      border: 2px solid rgba(255, 255, 255, 0.2);
      border-radius: 15px;
      padding: 1.5rem;
      cursor: pointer;
      transition: all 0.3s ease;
      display: flex;
      align-items: center;
      gap: 1.5rem;
    }

    .payment-method:hover {
      background: rgba(255, 255, 255, 0.15);
      border-color: rgba(40, 167, 69, 0.5);
      transform: translateY(-2px);
    }

    .payment-method.selected {
      background: rgba(40, 167, 69, 0.2);
      border-color: var(--success);
      box-shadow: 0 4px 15px rgba(40, 167, 69, 0.3);
    }

    .payment-icon {
      background: linear-gradient(135deg, var(--primary), var(--secondary));
      color: white;
      padding: 1rem;
      border-radius: 50%;
      font-size: 1.5rem;
      display: flex;
      align-items: center;
      justify-content: center;
      min-width: 60px;
      height: 60px;
    }

    .payment-details {
      flex: 1;
    }

    .payment-details h4 {
      margin: 0 0 0.5rem 0;
      color: var(--warm-gray);
      font-size: 1.2rem;
      font-weight: 600;
    }

    .payment-details p {
      margin: 0;
      color: rgba(255, 255, 255, 0.7);
      font-size: 0.9rem;
    }

    .payment-radio {
      display: flex;
      align-items: center;
    }

    .payment-radio input[type="radio"] {
      width: 20px;
      height: 20px;
      accent-color: var(--success);
    }

    .btn-modal.confirm:disabled {
      background: rgba(255, 255, 255, 0.1);
      color: rgba(255, 255, 255, 0.5);
      cursor: not-allowed;
      transform: none;
    }

    .btn-modal.confirm:disabled:hover {
      background: rgba(255, 255, 255, 0.1);
      transform: none;
      box-shadow: none;
    }

    /* Alert Messages */
    .alert {
      padding: 1.2rem 1.8rem;
      border-radius: 16px;
      margin-bottom: 2rem;
      font-weight: 600;
      backdrop-filter: blur(10px);
      border: 1px solid;
      display: flex;
      align-items: center;
      gap: 1rem;
      position: relative;
      animation: slideInDown 0.5s ease-out;
    }

    .alert-success {
      background: rgba(39, 174, 96, 0.15);
      color: var(--success);
      border-color: rgba(39, 174, 96, 0.3);
    }

    .alert-error {
      background: rgba(231, 76, 60, 0.15);
      color: var(--danger);
      border-color: rgba(231, 76, 60, 0.3);
    }

    .alert-warning {
      background: rgba(243, 156, 18, 0.15);
      color: var(--warning);
      border-color: rgba(243, 156, 18, 0.3);
    }

    .alert-info {
      background: rgba(52, 152, 219, 0.15);
      color: var(--accent);
      border-color: rgba(52, 152, 219, 0.3);
    }

    .alert span {
      flex: 1;
    }

    .alert-close {
      background: none;
      border: none;
      color: inherit;
      cursor: pointer;
      padding: 0.25rem;
      border-radius: 6px;
      transition: var(--transition-smooth);
      opacity: 0.7;
    }

    .alert-close:hover {
      background: rgba(255, 255, 255, 0.2);
      opacity: 1;
    }

    @keyframes slideInDown {
      from {
        opacity: 0;
        transform: translateY(-20px);
      }
      to {
        opacity: 1;
        transform: translateY(0);
      }
    }

    @keyframes slideOutUp {
      from {
        opacity: 1;
        transform: translateY(0);
      }
      to {
        opacity: 0;
        transform: translateY(-20px);
      }
    }

    /* Form Field Validation Styles */
    .field-error {
      animation: shake 0.5s ease-in-out;
    }

    @keyframes shake {
      0%, 100% { transform: translateX(0); }
      25% { transform: translateX(-5px); }
      75% { transform: translateX(5px); }
    }

    /* Enhanced Order Status Indicators */
    .order-status-info {
      margin-top: 0.5rem;
      font-size: 0.8rem;
      color: var(--warm-gray);
      font-style: italic;
    }

    .cancellation-note {
      background: rgba(255, 255, 255, 0.15);
      border: 2px solid rgba(255, 255, 255, 0.3);
      border-radius: 12px;
      padding: 1.5rem;
      margin-top: 1rem;
      color: #ffffff;
      font-size: 0.95rem;
      display: flex;
      align-items: flex-start;
      gap: 0.8rem;
      backdrop-filter: blur(10px);
      box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
      line-height: 1.6;
    }

    .cancellation-note i {
      flex-shrink: 0;
      margin-top: 0.2rem;
      font-size: 1.1rem;
      color: #ffffff;
    }

    .cancellation-note strong {
      color: #ffffff;
      font-weight: 700;
      font-size: 1rem;
    }

    /* Responsive Design */
    @media (max-width: 768px) {
      .header-container {
        padding: 0 1rem;
        flex-direction: column;
        gap: 1rem;
      }
      
      .container {
        padding: 1rem;
      }
      
      .dashboard-title {
        font-size: 2.5rem;
      }
      
      .tabs {
        flex-direction: column;
        gap: 0.5rem;
        margin-bottom: 2rem;
      }
      
      .tab {
        padding: 1rem;
        text-align: center;
      }
      
      .form-grid {
        grid-template-columns: 1fr;
      }
      
      .cylinder-grid {
        grid-template-columns: 1fr;
      }
      
      .user-details {
        display: none;
      }
      
      .orders-table {
        font-size: 0.8rem;
      }
      
      .orders-table th,
      .orders-table td {
        padding: 1rem;
      }
    }

    @media (max-width: 480px) {
      .tab-content {
        padding: 2rem;
      }
      
      .dashboard-title {
        font-size: 2rem;
      }
      
      .btn {
        padding: 1rem 2rem;
        font-size: 0.9rem;
      }
      
      .modal-content {
        margin: 2% auto;
        padding: 2rem;
        width: 95%;
        max-height: 95vh;
        overflow-y: auto;
      }
      
      .payment-modal {
        max-width: 95%;
        max-height: 95vh;
      }
      
      .payment-methods {
        gap: 0.8rem;
      }
      
      .payment-method {
        padding: 1.2rem;
        flex-direction: column;
        text-align: center;
        gap: 1rem;
      }
      
      .payment-icon {
        min-width: 50px;
        height: 50px;
        font-size: 1.2rem;
      }
      
      .action-buttons {
        flex-direction: column;
        gap: 0.3rem;
      }
      
      .btn-pay, .btn-cancel {
        width: 100%;
        justify-content: center;
      }
    }

    /* Payment Badges */
    .payment-badge {
      padding: 0.4rem 0.8rem;
      border-radius: 10px;
      font-size: 0.75rem;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      display: inline-flex;
      align-items: center;
      gap: 0.4rem;
    }

    .payment-pending {
      background: rgba(255, 193, 7, 0.2);
      color: #f39c12;
      border: 1px solid rgba(255, 193, 7, 0.3);
    }

    .payment-paid {
      background: rgba(40, 167, 69, 0.2);
      color: #28a745;
      border: 1px solid rgba(40, 167, 69, 0.3);
    }

    .payment-failed {
      background: rgba(220, 53, 69, 0.2);
      color: #dc3545;
      border: 1px solid rgba(220, 53, 69, 0.3);
    }

    /* Action Buttons */
    .action-buttons {
      display: flex;
      gap: 0.5rem;
      flex-wrap: wrap;
    }

    .btn-pay {
      background: linear-gradient(135deg, #28a745, #20c997);
      color: white;
      border: none;
      padding: 0.6rem 1.2rem;
      border-radius: 10px;
      font-size: 0.8rem;
      font-weight: 600;
      cursor: pointer;
      transition: all 0.3s ease;
      display: inline-flex;
      align-items: center;
      gap: 0.5rem;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      box-shadow: 0 2px 10px rgba(40, 167, 69, 0.3);
    }

    .btn-pay:hover {
      transform: translateY(-2px);
      box-shadow: 0 4px 20px rgba(40, 167, 69, 0.4);
      background: linear-gradient(135deg, #218838, #1d9f7a);
    }

    .btn-pay:active {
      transform: translateY(0);
    }

    /* Cancel Button Styling */
    .btn-cancel {
      background: linear-gradient(135deg, #e74c3c, #c0392b);
      color: #1a1a1a;
      border: none;
      padding: 0.5rem 1rem;
      border-radius: 8px;
      font-size: 0.8rem;
      font-weight: 600;
      cursor: pointer;
      transition: var(--transition-smooth);
      display: inline-flex;
      align-items: center;
      gap: 0.5rem;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }

    .btn-cancel:hover {
      transform: translateY(-2px);
      box-shadow: 0 8px 20px rgba(231, 76, 60, 0.3);
      background: linear-gradient(135deg, #c0392b, #a93226);
    }

    .btn-cancel:active {
      transform: translateY(0);
    }

    /* Modal Styling */
    .modal {
      display: none;
      position: fixed;
      z-index: 1000;
      left: 0;
      top: 0;
      width: 100%;
      height: 100%;
      background-color: rgba(0, 0, 0, 0.5);
      backdrop-filter: blur(5px);
    }

    .modal-content {
      background: var(--glass-effect);
      backdrop-filter: blur(30px);
      margin: 5% auto;
      padding: 2.5rem;
      border: 1px solid rgba(255, 255, 255, 0.2);
      border-radius: 20px;
      width: 90%;
      max-width: 500px;
      max-height: 90vh;
      overflow-y: auto;
      box-shadow: var(--shadow-medium);
      position: relative;
      animation: modalSlideIn 0.3s ease-out;
    }

    /* Custom scrollbar for modal */
    .modal-content::-webkit-scrollbar {
      width: 8px;
    }

    .modal-content::-webkit-scrollbar-track {
      background: rgba(255, 255, 255, 0.1);
      border-radius: 4px;
    }

    .modal-content::-webkit-scrollbar-thumb {
      background: rgba(255, 255, 255, 0.3);
      border-radius: 4px;
    }

    .modal-content::-webkit-scrollbar-thumb:hover {
      background: rgba(255, 255, 255, 0.5);
    }

    @keyframes modalSlideIn {
      from {
        opacity: 0;
        transform: translateY(-50px) scale(0.9);
      }
      to {
        opacity: 1;
        transform: translateY(0) scale(1);
      }
    }

    .modal-header {
      display: flex;
      align-items: center;
      gap: 1rem;
      margin-bottom: 2rem;
      padding-bottom: 1rem;
      border-bottom: 1px solid rgba(255, 255, 255, 0.2);
    }

    .modal-header h3 {
      color: var(--danger);
      margin: 0;
      font-size: 1.5rem;
      font-weight: 700;
    }

    .modal-header .icon {
      background: linear-gradient(135deg, #e74c3c, #c0392b);
      color: white;
      padding: 1rem;
      border-radius: 50%;
      font-size: 1.2rem;
    }

    .modal-body {
      margin-bottom: 2rem;
      color: var(--warm-gray);
      line-height: 1.6;
    }

    .modal-body .order-details {
      background: rgba(255, 255, 255, 0.1);
      padding: 1.5rem;
      border-radius: 12px;
      margin: 1rem 0;
      border: 1px solid rgba(255, 255, 255, 0.2);
    }

    .modal-footer {
      display: flex;
      gap: 1rem;
      justify-content: flex-end;
    }

    .btn-modal {
      padding: 0.8rem 2rem;
      border: none;
      border-radius: 10px;
      font-weight: 600;
      cursor: pointer;
      transition: var(--transition-smooth);
      text-transform: uppercase;
      letter-spacing: 0.5px;
      font-size: 0.9rem;
    }

    .btn-modal.cancel {
      background: rgba(255, 255, 255, 0.2);
      color: var(--warm-gray);
      border: 1px solid rgba(255, 255, 255, 0.3);
    }

    .btn-modal.cancel:hover {
      background: rgba(255, 255, 255, 0.3);
      transform: translateY(-2px);
    }

    .btn-modal.confirm {
      background: linear-gradient(135deg, #e74c3c, #c0392b);
      color: white;
    }

    .btn-modal.confirm:hover {
      background: linear-gradient(135deg, #c0392b, #a93226);
      transform: translateY(-2px);
      box-shadow: 0 8px 20px rgba(231, 76, 60, 0.3);
    }

    /* Payment Modal Specific Styles */
    .payment-modal {
      max-width: 600px;
      max-height: 95vh;
      overflow-y: auto;
    }

    .modal-header .icon.payment {
      background: linear-gradient(135deg, #28a745, #20c997);
    }

    .modal-header .icon.payment + h3 {
      color: var(--success);
    }

    .payment-info {
      background: rgba(40, 167, 69, 0.1);
      border: 1px solid rgba(40, 167, 69, 0.3);
      border-radius: 12px;
      padding: 1.5rem;
      margin-bottom: 2rem;
      text-align: center;
    }

    .payment-info p {
      margin: 0.5rem 0;
      font-size: 1.1rem;
    }

    .payment-methods {
      display: flex;
      flex-direction: column;
      gap: 1rem;
    }

    .payment-method {
      background: rgba(255, 255, 255, 0.1);
      border: 2px solid rgba(255, 255, 255, 0.2);
      border-radius: 15px;
      padding: 1.5rem;
      cursor: pointer;
      transition: all 0.3s ease;
      display: flex;
      align-items: center;
      gap: 1.5rem;
    }

    .payment-method:hover {
      background: rgba(255, 255, 255, 0.15);
      border-color: rgba(40, 167, 69, 0.5);
      transform: translateY(-2px);
    }

    .payment-method.selected {
      background: rgba(40, 167, 69, 0.2);
      border-color: var(--success);
      box-shadow: 0 4px 15px rgba(40, 167, 69, 0.3);
    }

    .payment-icon {
      background: linear-gradient(135deg, var(--primary), var(--secondary));
      color: white;
      padding: 1rem;
      border-radius: 50%;
      font-size: 1.5rem;
      display: flex;
      align-items: center;
      justify-content: center;
      min-width: 60px;
      height: 60px;
    }

    .payment-details {
      flex: 1;
    }

    .payment-details h4 {
      margin: 0 0 0.5rem 0;
      color: var(--warm-gray);
      font-size: 1.2rem;
      font-weight: 600;
    }

    .payment-details p {
      margin: 0;
      color: rgba(255, 255, 255, 0.7);
      font-size: 0.9rem;
    }

    .payment-radio {
      display: flex;
      align-items: center;
    }

    .payment-radio input[type="radio"] {
      width: 20px;
      height: 20px;
      accent-color: var(--success);
    }

    .btn-modal.confirm:disabled {
      background: rgba(255, 255, 255, 0.1);
      color: rgba(255, 255, 255, 0.5);
      cursor: not-allowed;
      transform: none;
    }

    .btn-modal.confirm:disabled:hover {
      background: rgba(255, 255, 255, 0.1);
      transform: none;
      box-shadow: none;
    }

    /* Alert Messages */
    .alert {
      padding: 1.2rem 1.8rem;
      border-radius: 16px;
      margin-bottom: 2rem;
      font-weight: 600;
      backdrop-filter: blur(10px);
      border: 1px solid;
      display: flex;
      align-items: center;
      gap: 1rem;
      position: relative;
      animation: slideInDown 0.5s ease-out;
    }

    .alert-success {
      background: rgba(39, 174, 96, 0.15);
      color: var(--success);
      border-color: rgba(39, 174, 96, 0.3);
    }

    .alert-error {
      background: rgba(231, 76, 60, 0.15);
      color: var(--danger);
      border-color: rgba(231, 76, 60, 0.3);
    }

    .alert-warning {
      background: rgba(243, 156, 18, 0.15);
      color: var(--warning);
      border-color: rgba(243, 156, 18, 0.3);
    }

    .alert-info {
      background: rgba(52, 152, 219, 0.15);
      color: var(--accent);
      border-color: rgba(52, 152, 219, 0.3);
    }

    .alert span {
      flex: 1;
    }

    .alert-close {
      background: none;
      border: none;
      color: inherit;
      cursor: pointer;
      padding: 0.25rem;
      border-radius: 6px;
      transition: var(--transition-smooth);
      opacity: 0.7;
    }

    .alert-close:hover {
      background: rgba(255, 255, 255, 0.2);
      opacity: 1;
    }

    @keyframes slideInDown {
      from {
        opacity: 0;
        transform: translateY(-20px);
      }
      to {
        opacity: 1;
        transform: translateY(0);
      }
    }

    @keyframes slideOutUp {
      from {
        opacity: 1;
        transform: translateY(0);
      }
      to {
        opacity: 0;
        transform: translateY(-20px);
      }
    }

    /* Form Field Validation Styles */
    .field-error {
      animation: shake 0.5s ease-in-out;
    }

    @keyframes shake {
      0%, 100% { transform: translateX(0); }
      25% { transform: translateX(-5px); }
      75% { transform: translateX(5px); }
    }

    /* Enhanced Order Status Indicators */
    .order-status-info {
      margin-top: 0.5rem;
      font-size: 0.8rem;
      color: var(--warm-gray);
      font-style: italic;
    }

    .cancellation-note {
      background: rgba(255, 255, 255, 0.15);
      border: 2px solid rgba(255, 255, 255, 0.3);
      border-radius: 12px;
      padding: 1.5rem;
      margin-top: 1rem;
      color: #ffffff;
      font-size: 0.95rem;
      display: flex;
      align-items: flex-start;
      gap: 0.8rem;
      backdrop-filter: blur(10px);
      box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
      line-height: 1.6;
    }

    .cancellation-note i {
      flex-shrink: 0;
      margin-top: 0.2rem;
      font-size: 1.1rem;
      color: #ffffff;
    }

    .cancellation-note strong {
      color: #ffffff;
      font-weight: 700;
      font-size: 1rem;
    }

    /* Responsive Design */
    @media (max-width: 768px) {
      .header-container {
        padding: 0 1rem;
        flex-direction: column;
        gap: 1rem;
      }
      
      .container {
        padding: 1rem;
      }
      
      .dashboard-title {
        font-size: 2.5rem;
      }
      
      .tabs {
        flex-direction: column;
        gap: 0.5rem;
        margin-bottom: 2rem;
      }
      
      .tab {
        padding: 1rem;
        text-align: center;
      }
      
      .form-grid {
        grid-template-columns: 1fr;
      }
      
      .cylinder-grid {
        grid-template-columns: 1fr;
      }
      
      .user-details {
        display: none;
      }
      
      .orders-table {
        font-size: 0.8rem;
      }
      
      .orders-table th,
      .orders-table td {
        padding: 1rem;
      }
    }

    /* Payment Method Debugging - Temporary Styles */
    .debug-info {
      font-family: monospace;
      font-size: 0.9rem;
      color: #333;
      background: rgba(255, 255, 255, 0.9);
      border: 1px solid rgba(0, 0, 0, 0.1);
      border-radius: 8px;
      padding: 1rem;
      margin: 1rem 0;
      white-space: pre-wrap;
      line-height: 1.4;
    }
  </style>
</head>
<body>
  <header class="header">
    <div class="header-container">
      <a href="#" class="logo">
        <i class="fas fa-fire"></i>
        LIAA LPG Portal
      </a>
      <div class="user-info">
        <div class="notification-bell" onclick="toggleNotifications()">
          <i class="fas fa-bell"></i>
          <span class="notification-badge" id="notificationBadge" style="display: none;">0</span>
        </div>
        
        <div class="user-avatar">
          <?php echo strtoupper(substr($current_user['name'], 0, 1)); ?>
        </div>
        <div class="user-details">
          <div class="user-name"><?php echo htmlspecialchars($current_user['name']); ?></div>
          <div class="user-role">Customer</div>
          <div class="header-links" style="margin-top:8px; display:flex; gap:8px;">
            <a href="?tab=orders" class="btn" style="padding:0.5rem 0.9rem; font-size:0.85rem; text-transform:none;" onclick="showTab('orders'); return false;">My Orders</a>
            <a href="?tab=profile" class="btn" style="padding:0.5rem 0.9rem; font-size:0.85rem; text-transform:none;" onclick="showTab('profile'); return false;">Profile</a>
          </div>
        </div>
        <a href="?logout=1" class="logout-btn">
          <i class="fas fa-sign-out-alt"></i> Logout
        </a>
      </div>
    </div>
  </header>

  <!-- Notification Container -->
  <div class="notification-container" id="notificationContainer">
    <!-- Notifications will be inserted here dynamically -->
  </div>

  <div class="container">
    <h1 class="dashboard-title">
      <i class="fas fa-fire"></i> Welcome to Your LPG Portal
    </h1>
    <p class="dashboard-subtitle">Manage your gas cylinder orders with ease and convenience</p>

    <!-- Tab Navigation -->
    <div class="tabs">
      <button class="tab active" onclick="showTab('book')">
        <span><i class="fas fa-plus-circle"></i> Book Order</span>
      </button>
      <button class="tab" onclick="showTab('orders')">
        <span><i class="fas fa-list"></i> My Orders (<?php echo count($orders); ?>)</span>
      </button>
      <button class="tab" onclick="showTab('profile')">
        <span><i class="fas fa-user"></i> Profile</span>
      </button>
    </div>

    <!-- Alert Messages -->
    <?php if ($error): ?>
      <div class="alert alert-error" id="error-alert">
        <i class="fas fa-exclamation-circle"></i> 
        <span><?php echo htmlspecialchars($error); ?></span>
        <button type="button" class="alert-close" onclick="closeAlert('error-alert')">
          <i class="fas fa-times"></i>
        </button>
      </div>
    <?php endif; ?>

    <?php if ($message): ?>
      <div class="alert alert-success" id="success-alert">
        <i class="fas fa-check-circle"></i> 
        <span><?php echo htmlspecialchars($message); ?></span>
        <button type="button" class="alert-close" onclick="closeAlert('success-alert')">
          <i class="fas fa-times"></i>
        </button>
      </div>
    <?php endif; ?>

    <!-- Book Order Tab -->
    <div id="book" class="tab-content active">
      <h3><i class="fas fa-shopping-cart"></i> Book Your LPG Cylinder</h3>

      <form method="POST" class="booking-form">
        <!-- Hidden input for book_order parameter -->
        <input type="hidden" name="book_order" value="1">
        <div class="form-group">
          <label for="cylinder_select">Select Cylinder Type *</label>
          <!-- Visible fallback select for accessibility / non-JS users -->
          <select id="cylinder_select" name="cylinder_type_fallback" style="width:100%; padding:0.9rem; border-radius:12px; margin-bottom:1rem;">
            <option value="">-- Choose cylinder type --</option>
            <?php foreach ($available_cylinders as $opt_cyl):
                $opt_type = $opt_cyl['cylinder_type'] ?? ($opt_cyl['type'] ?? null);
                if (!$opt_type) continue;
                $opt_price = isset($opt_cyl['unit_price']) && (float)$opt_cyl['unit_price'] > 0 ? number_format((float)$opt_cyl['unit_price'], 2, '.', '') : ($fallback_prices[$opt_type] ?? 0);
            ?>
              <option data-price="<?php echo htmlspecialchars($opt_price); ?>" value="<?php echo htmlspecialchars($opt_type); ?>"><?php echo htmlspecialchars($opt_type); ?> — ₹<?php echo number_format((float)$opt_price,2); ?></option>
            <?php endforeach; ?>
          </select>
          <div class="cylinder-grid">
            <?php foreach ($available_cylinders as $cylinder): ?>
              <?php 
              $is_available = $cylinder['available_quantity'] > 0;
              $stock_class = '';
              $availability_class = '';
              $availability_icon = '';
              $availability_text = '';
              $raw_price = isset($cylinder['unit_price']) ? (float)$cylinder['unit_price'] : 0.0;
              $display_price = $raw_price > 0 ? $raw_price : ($fallback_prices[$cylinder['cylinder_type']] ?? 0.0);
              
              if (!$is_available) {
                  $stock_class = 'out-of-stock';
                  $availability_class = 'availability-out';
                  $availability_icon = 'fas fa-times-circle';
                  $availability_text = 'Out of Stock';
              } elseif ($cylinder['stock_level'] === 'low') {
                  $stock_class = 'low-stock';
                  $availability_class = 'availability-low';
                  $availability_icon = 'fas fa-exclamation-triangle';
                  $availability_text = 'Low Stock - ' . $cylinder['available_quantity'] . ' left';
              } elseif ($cylinder['stock_level'] === 'medium') {
                  $stock_class = 'medium-stock';
                  $availability_class = 'availability-medium';
                  $availability_icon = 'fas fa-info-circle';
                  $availability_text = $cylinder['available_quantity'] . ' available';
              } else {
                  $availability_class = 'availability-good';
                  $availability_icon = 'fas fa-check-circle';
                  $availability_text = $cylinder['available_quantity'] . ' in stock';
              }
              ?>
        <div class="cylinder-card <?php echo $stock_class; ?>" 
          onclick="<?php echo $is_available ? "selectCylinder('" . $cylinder['cylinder_type'] . "', " . $display_price . ", event) || testCylinderSelection('" . $cylinder['cylinder_type'] . "', " . $display_price . ")" : 'return false;'; ?>"
          data-cylinder="<?php echo htmlspecialchars($cylinder['cylinder_type']); ?>"
          data-price="<?php echo $display_price; ?>">
                <div class="cylinder-info">
                  <h4><?php echo htmlspecialchars($cylinder['cylinder_type'] ?? ($cylinder['type'] ?? 'Unknown Cylinder')); ?></h4>
         <div class="cylinder-price">₹<?php echo number_format($display_price, 2); ?></div>
                  
                  <!-- Availability Status -->
                  <div class="cylinder-availability <?php echo $availability_class; ?>">
                    <i class="<?php echo $availability_icon; ?> availability-icon"></i>
                    <span class="availability-text"><?php echo $availability_text; ?></span>
                  </div>
                  
                  <!-- Stock Progress Bar -->
                  <?php if ($is_available): ?>
                    <div class="stock-progress">
                      <div class="stock-progress-fill stock-progress-<?php echo $cylinder['stock_level']; ?>" 
                           style="width: <?php echo $cylinder['availability_percent']; ?>%"></div>
                    </div>
                    <small style="color: rgba(255, 255, 255, 0.6); font-size: 0.8rem;">
                      <?php echo $cylinder['availability_percent']; ?>% available
                    </small>
                  <?php endif; ?>
                  
                  <p class="cylinder-desc"><?php echo htmlspecialchars($cylinder['description'] ?? 'Premium quality LPG cylinder'); ?></p>
                  
                  <?php if (!$is_available): ?>
                    <small style="color: #e74c3c; font-weight: 600;">
                      <i class="fas fa-ban"></i> Currently unavailable
                    </small>
                  <?php endif; ?>
                </div>
                <?php if ($is_available): ?>
                  <div class="select-action">
                    <button type="button" class="btn-select" onclick="selectCylinder('<?php echo $cylinder['cylinder_type']; ?>', <?php echo $display_price; ?>, event)">
                      <i class="fas fa-check-circle"></i>
                      Select
                    </button>
                    <input type="radio" name="cylinder_type" value="<?php echo $cylinder['cylinder_type']; ?>" id="cylinder_<?php echo $cylinder['cylinder_type']; ?>" style="position: absolute; opacity: 0; pointer-events: none;">
                  </div>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        </div>

        <div class="form-grid">
          <div class="form-group">
            <label for="quantity"><i class="fas fa-sort-numeric-up"></i> Quantity *</label>
            <select id="quantity" name="quantity" onchange="calculateTotal()" required>
              <option value="1">1 Cylinder</option>
              <option value="2">2 Cylinders</option>
              <option value="3">3 Cylinders</option>
              <option value="4">4 Cylinders</option>
              <option value="5">5 Cylinders</option>
            </select>
          </div>

          <div class="form-group">
            <label for="delivery_date"><i class="fas fa-calendar-alt"></i> Delivery Date *</label>
            <input type="date" id="delivery_date" name="delivery_date" min="<?php echo date('Y-m-d', strtotime('+1 day')); ?>" required>
          </div>

          <div class="form-group">
            <label for="contact_number"><i class="fas fa-phone"></i> Contact Number *</label>
            <input type="tel" id="contact_number" name="contact_number" 
                   value="<?php echo htmlspecialchars($current_user['phone'] ?? ''); ?>" 
                   placeholder="Enter your 10-digit mobile number" 
                   maxlength="10" 
                   pattern="[0-9]{10}"
                   title="Please enter a valid 10-digit mobile number"
                   required>
            <div id="contact_number_message" class="validation-message"></div>
          </div>
        </div>

        <div class="form-group">
          <label for="delivery_address"><i class="fas fa-map-marker-alt"></i> Delivery Address *</label>
          <textarea id="delivery_address" name="delivery_address" rows="3" placeholder="Enter complete delivery address with landmarks..." required><?php echo htmlspecialchars($current_user['address'] ?? ''); ?></textarea>
        </div>

        <div class="form-group">
          <label for="special_instructions"><i class="fas fa-sticky-note"></i> Special Instructions (Optional)</label>
          <textarea id="special_instructions" name="special_instructions" rows="2" placeholder="Any special delivery instructions or notes..."></textarea>
        </div>

        <!-- Payment Method Selection (Only COD) -->
        <div class="form-group">
          <label><i class="fas fa-money-bill-wave"></i> Payment Method *</label>
          <div class="payment-method-selection">
            <div class="payment-option" onclick="selectPaymentOption('cod')">
              <div class="payment-option-icon">
                <i class="fas fa-money-bill-wave"></i>
              </div>
              <div class="payment-option-details">
                <h4>Cash on Delivery</h4>
                <p>Pay when you receive</p>
                <span class="payment-badge cod">COD</span>
              </div>
              <div class="payment-selected-badge" style="display: none;">
                <i class="fas fa-check"></i>
              </div>
              <input type="radio" name="payment_method" value="cod" id="booking_cod" required checked>
            </div>
          </div>
        </div>

        <div id="order-summary" style="background: rgba(0, 0, 0, 0.25); backdrop-filter: blur(30px); padding: 2rem; border-radius: 20px; margin-bottom: 2rem; display: none; border: 2px solid rgba(255, 255, 255, 0.3); color: #ffffff;">
          <h4 style="color: #ffffff; margin-bottom: 1.5rem; display: flex; align-items: center; gap: 0.5rem; font-weight: 700; text-shadow: 0 2px 10px rgba(0, 0, 0, 0.3);">
            <i class="fas fa-receipt"></i> Order Summary
          </h4>
          <div style="display: flex; justify-content: space-between; margin: 1rem 0; color: #ffffff;">
            <span>Cylinder Price:</span>
            <span id="subtotal" style="font-weight: 700; color: #ffffff;">₹0.00</span>
          </div>
          <div style="display: flex; justify-content: space-between; margin: 1rem 0; color: #ffffff;">
            <span>Delivery Charge:</span>
            <span id="delivery-charge-display" style="font-weight: 700, color: #ffffff;">₹<?= number_format($delivery_charge_value, 2) ?></span>
          </div>
          <div style="display: flex; justify-content: space-between; margin: 1rem 0; color: #ffffff;">
            <span>GST (<?= htmlspecialchars($gst_percentage_label) ?>%)</span>
            <span id="gst-amount" style="font-weight: 700; color: #ffffff;">₹0.00</span>
          </div>
          <div id="payment-method-summary" style="display: none; margin: 1rem 0; padding: 1rem; background: rgba(255, 255, 255, 0.15); border-radius: 12px; border: 2px solid rgba(255, 255, 255, 0.3); box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);">
            <div style="display: flex; align-items: center; gap: 0.5rem; color: #ffffff;">
              <i id="payment-method-icon" class="fas fa-money-bill-wave" style="color: #ffffff;"></i>
              <span style="font-weight: 600;">Payment Method:</span>
              <span id="payment-method-text" style="font-weight: 700; color: #ffffff;">Cash on Delivery</span>
            </div>
          </div>
          <hr style="margin: 1rem 0; border: none; height: 2px; background: rgba(255, 255, 255, 0.3);">
          <div style="display: flex; justify-content: space-between; font-weight: 800; font-size: 1.3rem; color: #ffffff; text-shadow: 0 2px 10px rgba(0, 0, 0, 0.3);">
            <span>Total Amount:</span>
            <span id="total" style="color: #ffffff;">₹0.00</span>
          </div>
          <input type="hidden" id="deliveryChargeValue" value="<?= htmlspecialchars(number_format($delivery_charge_value, 2, '.', '')) ?>">
          <input type="hidden" id="gstRateValue" value="<?= htmlspecialchars(number_format($gst_rate, 4, '.', '')) ?>">
        </div>

        <div style="text-align: center;">
          <button type="submit" name="book_order" class="btn btn-primary" id="place-order-btn">
            <i class="fas fa-shopping-cart"></i> Place Order
          </button>
          <div id="order-loading" style="display: none; margin-top: 1rem; color: var(--coral-primary);">
            <i class="fas fa-spinner fa-spin"></i> Processing your order...
          </div>
          <div id="order-success" style="display: none; margin-top: 1rem; color: var(--success);">
            <i class="fas fa-check-circle"></i> Order placed successfully!
          </div>
        </div>
      </form>
    </div>

    <!-- My Orders Tab -->
    <div id="orders" class="tab-content">
      <h3><i class="fas fa-history"></i> My Order History</h3>
      
      <div class="cancellation-note">
        <i class="fas fa-info-circle"></i>
        <div>
          <strong>Order Cancellation Policy:</strong><br>
          You can cancel your order until a delivery person accepts it. Once a delivery person is assigned, the order cannot be cancelled to ensure fair treatment for our delivery staff.
        </div>
      </div>

      <?php if (empty($orders)): ?>
        <div style="text-align: center; padding: 4rem; color: var(--warm-gray);">
          <i class="fas fa-inbox" style="font-size: 4rem; margin-bottom: 1rem; opacity: 0.3;"></i>
          <p style="font-size: 1.2rem; margin-bottom: 0.5rem;">No orders found</p>
          <p style="opacity: 0.7;">Start by booking your first LPG cylinder!</p>
        </div>
      <?php else: ?>
        <table class="orders-table">
          <thead>
            <tr>
              <th>Order ID</th>
              <th>Cylinder Type</th>
              <th>Quantity</th>
              <th>Total Amount</th>
              <th>Delivery Date</th>
              <th>Status</th>
              <th>Order Date</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($orders as $order): ?>
              <?php
                $rawStatus = $order['status'] ?? '';
                $fallbackStatus = $order['delivery_status'] ?? '';
                $effectiveStatus = trim((string)$rawStatus) !== '' ? $rawStatus : $fallbackStatus;
                $normalizedStatus = strtolower(str_replace(' ', '_', trim((string)$effectiveStatus)));
                if ($normalizedStatus === '') {
                    $normalizedStatus = 'pending';
                }
                $statusClass = str_replace('_', '-', $normalizedStatus);
                $statusLabel = ucwords(str_replace('_', ' ', $normalizedStatus));
              ?>
              <tr>
                <td><strong>#<?php echo $order['id']; ?></strong></td>
                <td><?php echo htmlspecialchars($order['cylinder_type'] ?? ($order['type'] ?? 'Unknown Cylinder')); ?></td>
                <td><?php echo $order['quantity']; ?> units</td>
                <td style="font-weight: 700;">₹<?php echo number_format($order['total_amount'], 2); ?></td>
                <td>
                  <?php echo date('M j, Y', strtotime($order['delivery_date'])); ?>
                </td>
                <td>
                  <span class="status-badge status-<?php echo htmlspecialchars($statusClass); ?>">
                    <i class="fas fa-circle" style="font-size: 0.6rem;"></i>
                    <?php echo htmlspecialchars($statusLabel); ?>
                  </span>
                </td>
                <td><?php echo date('M j, Y g:i A', strtotime($order['created_at'])); ?></td>
                <td>
                  <?php 
                  // Check if order can be cancelled
                  $can_cancel = in_array($normalizedStatus, ['pending', 'confirmed']);
                  
                  // Also check if delivery person is assigned
                  if ($can_cancel) {
                      try {
                          $delivery_check_sql = "SELECT delivery_person_id FROM deliveries WHERE order_id = ? AND delivery_person_id IS NOT NULL";
                          $delivery_stmt = executeQuery($delivery_check_sql, [$order['id']]);
                          $delivery_assigned = $delivery_stmt->fetch();
                          if ($delivery_assigned) {
                              $can_cancel = false;
                          }
                      } catch (Exception $e) {
                          // If check fails, don't allow cancellation
                          $can_cancel = false;
                      }
                  }
                  
                  $payment_status = $order['payment_status'] ?? 'pending';
                  $can_pay = ($payment_status === 'pending' && in_array($normalizedStatus, ['pending', 'confirmed']));
                  ?>
                  
                  <div class="action-buttons">
                    <?php if ($can_cancel): ?>
                      <button type="button" 
                              onclick="showCancelModal(<?php echo $order['id']; ?>, '<?php echo htmlspecialchars($order['cylinder_type']); ?>')" 
                              class="btn-cancel"
                              title="Cancel this order">
                        <i class="fas fa-times"></i> Cancel
                      </button>
                    <?php else: ?>
                      <?php if (!$can_pay): ?>
                        <span style="color: var(--warm-gray); font-size: 0.85rem; font-style: italic;">
                          <?php 
                          if ($normalizedStatus === 'delivered') {
                              echo 'Completed';
                          } elseif ($normalizedStatus === 'cancelled') {
                              echo 'Cancelled';
                          } elseif (in_array($normalizedStatus, ['processing', 'out_for_delivery'])) {
                              echo 'In progress';
                          } elseif ($payment_status === 'paid') {
                              echo 'Paid';
                          } else {
                              echo 'No action';
                          }
                          ?>
                        </span>
                      <?php endif; ?>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>

    <!-- Profile Tab -->
    <div id="profile" class="tab-content">
      <h3><i class="fas fa-user-circle"></i> Profile Information</h3>
      
      <form method="POST" style="max-width: 600px;">
        <div class="form-group">
          <label><i class="fas fa-user"></i> Full Name *</label>
          <input type="text" name="update_name" value="<?php echo htmlspecialchars($current_user['name']); ?>" placeholder="Enter your full name" required>
        </div>
        <div class="form-group">
          <label><i class="fas fa-envelope"></i> Email Address</label>
          <input type="email" value="<?php echo htmlspecialchars($current_user['email']); ?>" readonly style="background: rgba(255, 255, 255, 0.5); cursor: not-allowed;">
          <small style="color: var(--warm-gray); font-size: 0.85rem; margin-top: 0.5rem; display: block;">
            <i class="fas fa-info-circle"></i> Email cannot be changed for security reasons
          </small>
        </div>
        <div class="form-group">
          <label><i class="fas fa-phone"></i> Phone Number</label>
          <input type="tel" name="update_phone" value="<?php echo htmlspecialchars($current_user['phone'] ?? ''); ?>" placeholder="Enter your phone number">
        </div>
        <div class="form-group">
          <label><i class="fas fa-map-marker-alt"></i> Address</label>
          <textarea name="update_address" rows="3" placeholder="Enter your complete address with landmarks..."><?php echo htmlspecialchars($current_user['address'] ?? ''); ?></textarea>
        </div>
        
        <!-- Read-only information -->
        <div style="background: rgba(255, 255, 255, 0.3); border-radius: 16px; padding: 2rem; margin: 2rem 0; border: 1px solid rgba(255, 255, 255, 0.2);">
          <h4 style="color: var(--secondary); margin-bottom: 1.5rem; display: flex; align-items: center; gap: 0.5rem;">
            <i class="fas fa-shield-alt"></i> Account Information
          </h4>
          
          <div class="form-group">
            <label><i class="fas fa-shield-alt"></i> Account Status</label>
            <input type="text" value="<?php echo htmlspecialchars(ucfirst($current_user['status'] ?? 'active')); ?>" readonly style="background: rgba(255, 255, 255, 0.5); cursor: not-allowed;">
          </div>
          <div class="form-group">
            <label><i class="fas fa-user-tag"></i> Account Type</label>
            <input type="text" value="<?php echo htmlspecialchars(ucfirst($current_user['role'] ?? 'customer')); ?>" readonly style="background: rgba(255, 255, 255, 0.5); cursor: not-allowed;">
          </div>
          <div class="form-group">
            <label><i class="fas fa-calendar-check"></i> Member Since</label>
            <input type="text" value="<?php 
              if (isset($current_user['created_at']) && !empty($current_user['created_at']) && $current_user['created_at'] !== '0000-00-00 00:00:00') {
                echo date('F j, Y', strtotime($current_user['created_at']));
              } else {
                echo 'Account registration date not recorded';
              }
            ?>" readonly style="background: rgba(255, 255, 255, 0.5); cursor: not-allowed;">
          </div>
        </div>
        
        <div style="text-align: center; margin-top: 2rem;">
          <button type="submit" name="update_profile" class="btn btn-success">
            <i class="fas fa-save"></i> Update Profile
          </button>
        </div>
      </form>
      
      <?php if (empty($current_user['phone']) || empty($current_user['address'])): ?>
      <div style="background: rgba(255, 165, 0, 0.1); border: 1px solid rgba(255, 165, 0, 0.3); border-radius: 12px; padding: 1.5rem; margin-top: 2rem;">
        <h4 style="color: var(--warning); margin-bottom: 1rem; display: flex; align-items: center; gap: 0.5rem;">
          <i class="fas fa-exclamation-triangle"></i> Complete Your Profile
        </h4>
        <p style="color: var(--warm-gray); margin-bottom: 1rem;">
          Please complete your profile information for better service:
        </p>
        <ul style="color: var(--warm-gray); margin-left: 1.5rem;">
          <?php if (empty($current_user['phone'])): ?>
            <li>Phone number is required for delivery coordination</li>
          <?php endif; ?>
          <?php if (empty($current_user['address'])): ?>
            <li>Address information helps with faster order processing</li>
          <?php endif; ?>
        </ul>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Cancel Order Modal -->
  <div id="cancelOrderModal" class="modal">
    <div class="modal-content">
      <div class="modal-header">
        <div class="icon">
          <i class="fas fa-exclamation-triangle"></i>
        </div>
        <h3>Cancel Order</h3>
      </div>
      
      <div class="modal-body">
        <p><strong>Are you sure you want to cancel this order?</strong></p>
        <div class="order-details">
          <p><strong>Order ID:</strong> #<span id="modalOrderId"></span></p>
          <p><strong>Cylinder Type:</strong> <span id="modalCylinderType"></span></p>
          <p style="margin-bottom: 0;"><strong>Note:</strong> This action cannot be undone.</p>
        </div>
        
        <form id="cancelOrderForm" method="POST">
          <input type="hidden" name="order_id" id="cancelOrderId">
          <div class="form-group">
            <label for="cancel_reason"><i class="fas fa-comment"></i> Reason for Cancellation (Optional)</label>
            <textarea name="cancel_reason" 
                      id="cancel_reason" 
                      rows="3" 
                      placeholder="Please provide a reason for cancelling this order..."
                      style="width: 100%; padding: 0.8rem; border-radius: 8px; border: 1px solid rgba(255, 255, 255, 0.3); background: rgba(255, 255, 255, 0.1); color: var(--warm-gray); resize: vertical;"></textarea>
          </div>
        </form>
      </div>
      
      <div class="modal-footer">
        <button type="button" class="btn-modal cancel" onclick="closeCancelModal()">
          <i class="fas fa-times"></i> Keep Order
        </button>
        <button type="button" class="btn-modal confirm" onclick="confirmCancelOrder()">
          <i class="fas fa-check"></i> Cancel Order
        </button>
      </div>
    </div>
  </div>

  <script>
    console.log('=== Script loading ===');
    const fallbackPrices = <?php echo json_encode($fallback_prices); ?>;
    
    // Define notification functions globally first (needed by onclick handlers in HTML)
    function toggleNotifications() {
      console.log('Toggle notifications clicked');
      // Show notification panel - DO NOT mark as read automatically
      const panel = document.getElementById('notification-panel');
      if (panel) {
        panel.style.display = panel.style.display === 'none' ? 'block' : 'none';
      }
    }
    
    // Notification helper functions
    let lastNotificationCount = 0;
    const displayedNotificationKeys = new Set();

    function getNotificationKey(notification) {
      if (!notification) {
        return null;
      }

      if (notification.id !== undefined && notification.id !== null) {
        return `id:${notification.id}`;
      }

      const title = (notification.title || '').trim();
      const message = (notification.message || '').trim();
      if (!title && !message) {
        return null;
      }

      return `text:${title}|${message}`;
    }

    function displayNotification(notification) {
      const key = getNotificationKey(notification);

      if (key) {
        if (displayedNotificationKeys.has(key)) {
          console.log('Skipping duplicate notification with key:', key);
          return false;
        }
        displayedNotificationKeys.add(key);
      }

      showNotificationPopup(notification);
      return true;
    }

    function checkForNotifications() {
      console.log('=== Checking for notifications ===');
      console.log('Current URL:', window.location.href);
      
      // Try multiple possible API paths
      const apiPaths = [
        'api/notifications.php',
        './api/notifications.php',
        '/api/notifications.php',
        window.location.origin + '/api/notifications.php'
      ];
      
      console.log('Testing API paths:', apiPaths);
      console.log('lastNotificationCount:', lastNotificationCount);
      
      // Try the first path
      fetch(apiPaths[0], {
        method: 'GET',
        credentials: 'same-origin',
        headers: {
          'Accept': 'application/json'
        }
      })
        .then(response => {
          console.log('API Response status:', response.status);
          console.log('API Response headers:', response.headers);
          
          if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
          }
          
          return response.text().then(text => {
            console.log('Raw API response:', text);
            try {
              return JSON.parse(text);
            } catch (e) {
              console.error('Failed to parse JSON:', e);
              console.error('Response text:', text);
              throw new Error('Invalid JSON response from server');
            }
          });
        })
        .then(data => {
          console.log('Parsed API Response data:', data);
          
          if (data.success) {
            console.log('✅ API SUCCESS - Notification count:', data.count);
            console.log('✅ Notifications array:', data.notifications);
            updateNotificationBadge(data.count);
            
            // Show existing notifications on first load (limit to 3)
            let initialDisplay = false;
            if (lastNotificationCount === 0 && data.count > 0) {
              console.log('🎯 SHOULD SHOW INITIAL NOTIFICATIONS - count:', data.count, 'lastCount:', lastNotificationCount);
              const initialNotifications = data.notifications.slice(0, 3);
              console.log('🎯 Initial notifications to show:', initialNotifications);
              initialNotifications.forEach((notification, index) => {
                console.log(`🎯 Processing notification ${index + 1}:`, notification);
                displayNotification(notification);
              });
              console.log('🎯 Finished processing initial notifications');
              initialDisplay = true;
            } else {
              console.log('❌ NOT showing initial notifications - lastCount:', lastNotificationCount, 'dataCount:', data.count);
            }
            
            // Show new notifications as popups (ANY increase in count means new notifications)
            if (!initialDisplay && data.count > lastNotificationCount && lastNotificationCount >= 0) {
              console.log('🔔 NEW NOTIFICATIONS DETECTED - count:', data.count, 'last:', lastNotificationCount);
              const newNotifications = data.notifications.slice(0, data.count - lastNotificationCount);
              console.log('🔔 New notifications to show:', newNotifications);
              newNotifications.forEach((notification, index) => {
                console.log(`🔔 Showing new notification ${index + 1}:`, notification);
                displayNotification(notification);
              });
            } else {
              console.log('📊 No new notifications - count:', data.count, 'lastCount:', lastNotificationCount);
            }
            
            lastNotificationCount = data.count;
            console.log('📊 Updated lastNotificationCount to:', lastNotificationCount);
          } else {
            console.error('❌ API returned error:', data.error || 'Unknown error');
            console.error('❌ Full error data:', data);
          }
        })
        .catch(error => {
          console.error('=== Error fetching notifications from path 1 ===');
          console.error('Error type:', error.name);
          console.error('Error message:', error.message);
          
          // Try alternative API path
          console.log('Trying alternative API path: ./api/notifications.php');
          return fetch('./api/notifications.php', {
            method: 'GET',
            credentials: 'same-origin',
            headers: {
              'Accept': 'application/json'
            }
          })
          .then(response => {
            console.log('Alternative API Response status:', response.status);
            if (!response.ok) {
              throw new Error(`HTTP error! status: ${response.status}`);
            }
            return response.text().then(text => {
              console.log('Alternative API Raw response:', text);
              try {
                return JSON.parse(text);
              } catch (e) {
                console.error('Failed to parse JSON from alternative API:', e);
                throw new Error('Invalid JSON response from alternative API');
              }
            });
          })
          .then(data => {
            console.log('✅ Alternative API worked! Data:', data);
            
            if (data.success) {
              console.log('✅ API SUCCESS - Notification count:', data.count);
              console.log('✅ Notifications array:', data.notifications);
              updateNotificationBadge(data.count);
              
              // Show existing notifications on first load (limit to 3)
              if (lastNotificationCount === 0 && data.count > 0) {
                console.log('🎯 SHOULD SHOW INITIAL NOTIFICATIONS - count:', data.count, 'lastCount:', lastNotificationCount);
                const initialNotifications = data.notifications.slice(0, 3);
                console.log('🎯 Initial notifications to show:', initialNotifications);
                initialNotifications.forEach((notification, index) => {
                  console.log(`🎯 Processing notification ${index + 1}:`, notification);
                  displayNotification(notification);
                });
                console.log('🎯 Finished processing initial notifications');
              }
              
              lastNotificationCount = data.count;
              console.log('📊 Updated lastNotificationCount to:', lastNotificationCount);
            }
          })
          .catch(altError => {
            console.error('❌ Both API paths failed');
            console.error('Original error:', error.message);
            console.error('Alternative error:', altError.message);
            
            // Show user-friendly message
            console.log('💡 Creating mock notification to show API connection issue');
            const errorNotification = {
              id: 'error',
              title: 'Connection Issue',
              message: 'Unable to check for notifications. Please refresh the page.',
              type: 'warning'
            };
            showNotificationPopup(errorNotification);
          });
        });
    }

    function updateNotificationBadge(count) {
      const badge = document.getElementById('notificationBadge');
      if (!badge) {
        console.error('Notification badge element not found! ID: notificationBadge');
        return;
      }
      
      console.log('Updating notification badge with count:', count);
      
      if (count > 0) {
        badge.textContent = count;
        badge.style.display = 'flex';
        console.log('Badge displayed with count:', count);
      } else {
        badge.style.display = 'none';
        console.log('Badge hidden (count is 0)');
      }
    }

    function showNotificationPopup(notification) {
      const container = document.getElementById('notificationContainer');
      if (!container) {
        console.error('Notification container element not found! ID: notificationContainer');
        return;
      }
      
      console.log('Showing notification popup:', notification);
      
      const popup = document.createElement('div');
      popup.className = `notification-popup ${notification.type || 'info'}`;
      popup.innerHTML = `
        <div class="notification-header">
          <div class="notification-title">
            <i class="fas ${getNotificationIcon(notification.type || 'info')}"></i>
            ${notification.title || 'Notification'}
          </div>
          <button class="notification-close" onclick="closeNotification(this)">
            <i class="fas fa-times"></i>
          </button>
        </div>
        <div class="notification-message">${notification.message || ''}</div>
        <div class="notification-time">Just now</div>
      `;
      
      container.appendChild(popup);
      
      // Trigger animation
      setTimeout(() => {
        popup.classList.add('show');
      }, 100);
      
      // Auto remove after 8 seconds
      setTimeout(() => {
        closeNotification(popup.querySelector('.notification-close'));
        if (notification.id) {
          markNotificationAsRead(notification.id);
        }
      }, 8000);
      
      // Play notification sound (optional)
      playNotificationSound();
    }

    function getNotificationIcon(type) {
      switch(type) {
        case 'success': return 'fa-check-circle';
        case 'warning': return 'fa-exclamation-triangle';
        case 'error': return 'fa-times-circle';
        default: return 'fa-info-circle';
      }
    }

    function closeNotification(closeBtn) {
      const popup = closeBtn.closest('.notification-popup');
      popup.classList.remove('show');
      setTimeout(() => {
        popup.remove();
      }, 500);
    }

    function markNotificationAsRead(notificationId) {
      fetch('api/notifications.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({
          action: 'mark_read',
          notification_id: notificationId
        })
      })
      .then(response => response.json())
      .then(data => {
        if (data.success) {
          checkForNotifications(); // Refresh notification count
        }
      })
      .catch(error => {
        console.error('Error marking notification as read:', error);
      });
    }

    function playNotificationSound() {
      // Create a subtle notification sound using Web Audio API
      try {
        const audioContext = new (window.AudioContext || window.webkitAudioContext)();
        const oscillator = audioContext.createOscillator();
        const gainNode = audioContext.createGain();
        
        oscillator.connect(gainNode);
        gainNode.connect(audioContext.destination);
        
        oscillator.frequency.value = 800;
        oscillator.type = 'sine';
        
        gainNode.gain.setValueAtTime(0.1, audioContext.currentTime);
        gainNode.gain.linearRampToValueAtTime(0, audioContext.currentTime + 0.2);
        
        oscillator.start(audioContext.currentTime);
        oscillator.stop(audioContext.currentTime + 0.2);
      } catch (error) {
        // Fallback: no sound if Web Audio API is not supported
        console.log('Notification sound not supported');
      }
    }
    
    // Click handlers for selecting cylinders — ensure card clicks select the radio and price
    function selectCylinder(cylinderType, price, event) {
      try {
        if (event && event.preventDefault) event.preventDefault();
        // Deselect any previously selected card
        document.querySelectorAll('.cylinder-card.selected').forEach(c => c.classList.remove('selected'));

        // Find the target card
        const card = document.querySelector(`.cylinder-card[data-cylinder="${cylinderType}"]`);
        if (!card) return false;

        // If out-of-stock, ignore
        if (card.classList.contains('out-of-stock')) return false;

        card.classList.add('selected');

        // Set the hidden radio if present
        const radio = card.querySelector('input[name="cylinder_type"]');
        if (radio) {
          radio.checked = true;
        } else {
          // Try to check by id as fallback
          const fallback = document.getElementById('cylinder_' + cylinderType);
          if (fallback) fallback.checked = true;
        }

        // Update selected price and trigger recalculation
        const parsedPrice = parseFloat(price) || parseFloat(card.getAttribute('data-price')) || 0;
        selectedPrice = parsedPrice;
        card.setAttribute('data-price', selectedPrice);
        calculateTotal();

        // Small visual feedback
        card.scrollIntoView({ behavior: 'smooth', block: 'center' });
        return true;
      } catch (err) {
        console.error('selectCylinder error:', err);
        return false;
      }
    }

    function testCylinderSelection(cylinderType, price) {
      // Legacy wrapper used by inline onclick attributes
      return selectCylinder(cylinderType, price);
    }

    // Calculate total function
    function calculateTotal() {
      const quantityInput = document.getElementById('quantity');
      const quantity = quantityInput ? parseInt(quantityInput.value, 10) || 1 : 1;
      const selectedCard = document.querySelector('.cylinder-card.selected');
      let effectivePrice = Number.isFinite(selectedPrice) ? selectedPrice : 0;

      if ((!Number.isFinite(effectivePrice) || effectivePrice <= 0) && selectedCard) {
        const cardPrice = parseFloat(selectedCard.getAttribute('data-price'));
        if (Number.isFinite(cardPrice) && cardPrice > 0) {
          effectivePrice = cardPrice;
        } else {
          const cylinderType = selectedCard.getAttribute('data-cylinder');
          const fallbackPrice = cylinderType && fallbackPrices[cylinderType] ? fallbackPrices[cylinderType] : 0;
          if (Number.isFinite(fallbackPrice) && fallbackPrice > 0) {
            effectivePrice = fallbackPrice;
          }
        }

        if (Number.isFinite(effectivePrice) && effectivePrice > 0) {
          selectedPrice = effectivePrice;
          selectedCard.setAttribute('data-price', effectivePrice);
        }
      }

      const subtotal = (Number.isFinite(effectivePrice) ? effectivePrice : 0) * quantity;

      const deliveryChargeField = document.getElementById('deliveryChargeValue');
      const gstRateField = document.getElementById('gstRateValue');
      const deliveryCharge = deliveryChargeField ? parseFloat(deliveryChargeField.value) || 0 : 0;
      const gstRate = gstRateField ? parseFloat(gstRateField.value) || 0 : 0;
      const gstAmount = subtotal * gstRate;
      const total = subtotal + deliveryCharge + gstAmount;

      const currencyFormatter = new Intl.NumberFormat('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
      const subtotalDisplay = document.getElementById('subtotal');
      const deliveryDisplay = document.getElementById('delivery-charge-display');
      const gstDisplay = document.getElementById('gst-amount');
      const totalDisplay = document.getElementById('total');

      if (subtotalDisplay) {
        subtotalDisplay.textContent = '₹' + currencyFormatter.format(subtotal);
      }
      if (deliveryDisplay) {
        deliveryDisplay.textContent = '₹' + currencyFormatter.format(deliveryCharge);
      }
      if (gstDisplay) {
        gstDisplay.textContent = '₹' + currencyFormatter.format(gstAmount);
      }
      if (totalDisplay) {
        totalDisplay.textContent = '₹' + currencyFormatter.format(total);
      }

      if (selectedPrice > 0) {
        document.getElementById('order-summary').style.display = 'block';
        
        // Enable place order button when price is selected
        const placeOrderBtn = document.getElementById('place-order-btn');
        if (placeOrderBtn) {
          placeOrderBtn.disabled = false;
          placeOrderBtn.style.opacity = '1';
          placeOrderBtn.style.cursor = 'pointer';
          console.log('✅ Place order button enabled');
        }
      }
      
      // Update payment method summary
      updatePaymentMethodSummary();
      
      // Validate complete form
      validateOrderForm();
    }

    // Comprehensive form validation
    function validateOrderForm() {
      console.log('🔍 Running validateOrderForm...');
      
      // Get all form elements
      const deliveryAddress = document.getElementById('delivery_address');
      const deliveryDate = document.getElementById('delivery_date');
      const contactNumber = document.getElementById('contact_number');
      const placeOrderBtn = document.getElementById('place-order-btn');
      
      // Check cylinder selection first
      const cylinderValid = checkCylinderSelection();
      if (!cylinderValid) {
        console.log('❌ No cylinder type selected');
        placeOrderBtn.disabled = true;
        return false;
      }
      
      console.log('✅ Cylinder type selected');
      
      // Check if elements exist
      if (!deliveryAddress || !deliveryDate || !contactNumber) {
        console.error('❌ Required form elements not found!');
        return false;
      }
      
      // Get trimmed values
      const addressValue = deliveryAddress.value.trim();
      const dateValue = deliveryDate.value;
      const phoneValue = contactNumber.value.trim();
      
      // Check all required values
      const validationDetails = {
        cylinderSelected: !!document.querySelector('input[name="cylinder_type"]:checked'),
        addressFilled: !!addressValue,
        dateFilled: !!dateValue,
        phoneFilled: !!phoneValue,
        selectedPrice: selectedPrice,
        priceValid: selectedPrice > 0
      };
      
      console.log('📋 Validation details:', validationDetails);
      
      // Calculate overall validity
      const isValid = validationDetails.cylinderSelected && 
                     validationDetails.addressFilled && 
                     validationDetails.dateFilled && 
                     validationDetails.phoneFilled && 
                     validationDetails.priceValid;
      
      // Update button state
      if (placeOrderBtn) {
        placeOrderBtn.disabled = !isValid;
        placeOrderBtn.style.opacity = isValid ? '1' : '0.6';
        placeOrderBtn.style.cursor = isValid ? 'pointer' : 'not-allowed';
        console.log(isValid ? '✅ Form is valid, button enabled' : '❌ Form is invalid, button disabled');
      }
      
      // Show specific validation messages
      if (!isValid) {
        if (!validationDetails.cylinderSelected) {
          showAlert('Please select a cylinder type', 'error');
        } else if (!validationDetails.addressFilled) {
          showAlert('Please enter delivery address', 'error');
        } else if (!validationDetails.dateFilled) {
          showAlert('Please select delivery date', 'error');
        } else if (!validationDetails.phoneFilled) {
          showAlert('Please enter contact number', 'error');
        } else if (!validationDetails.priceValid) {
          showAlert('Invalid price selection', 'error');
        }
      }
      
      return isValid;
    }

    function updatePaymentMethodSummary() {
      const paymentSummary = document.getElementById('payment-method-summary');
      const paymentIcon = document.getElementById('payment-method-icon');
      const paymentText = document.getElementById('payment-method-text');
      if (paymentSummary && paymentIcon && paymentText) {
        paymentIcon.className = 'fas fa-money-bill-wave';
        paymentText.textContent = 'Cash on Delivery';
        paymentSummary.style.display = 'block';
      }
    }

    // Order confirmation with validation
    function confirmOrder(event) {
      console.log('=== confirmOrder called ===');
      console.log('Event:', event);
      console.log('isFormSubmitting:', isFormSubmitting);
      
      // Prevent double submission
      if (isFormSubmitting) {
        console.log('Form already submitting, preventing...');
        return false;
      }
      
      // Validate form first
      console.log('Validating form...');
      const isValid = validateBookingForm();
      console.log('Form validation result:', isValid);
      
      if (!isValid) {
        console.log('Form validation failed, preventing submission');
        return false;
      }
      
      const form = event.target.closest('form');
      const selectedCylinder = document.querySelector('input[name="cylinder_type"]:checked');
      const selectedPayment = document.querySelector('input[name="payment_method"]:checked');
      const quantity = document.getElementById('quantity').value;
      const deliveryDate = document.getElementById('delivery_date').value;
      const contactNumber = document.getElementById('contact_number').value;
      const deliveryAddress = document.getElementById('delivery_address').value;
      
      // Get order details for confirmation
      const cylinderType = selectedCylinder.value;
      const paymentMethod = selectedPayment.value;
      const total = document.getElementById('total').textContent;
      
      // Create confirmation message
      let paymentMethodText = '';
      // Only COD supported
      paymentMethodText = 'Cash on Delivery (pay when delivered)';
      
      console.log('Creating confirmation message...');
      console.log('Cylinder:', cylinderType);
      console.log('Quantity:', quantity);
      console.log('Total:', total);
      
      const confirmMessage = `Confirm your order:\n\n` +
        `Cylinder: ${cylinderType}\n` +
        `Quantity: ${quantity}\n` +
        `Total: ${total}\n` +
        `Payment: ${paymentMethodText}\n` +
        `Delivery Date: ${new Date(deliveryDate).toLocaleDateString()}\n\n` +
        `Would you like to proceed with this order?`;
      
      console.log('Showing confirmation dialog...');
      if (!confirm(confirmMessage)) {
        console.log('User cancelled order');
        event.preventDefault();
        return false;
      }
      
      console.log('User confirmed order, proceeding...');
      
      // Set form submitting state
      isFormSubmitting = true;
      
      // Show loading state
      const orderLoading = document.getElementById('order-loading');
      const placeOrderBtn = document.getElementById('place-order-btn');
      
      console.log('Showing loading state...');
      if (placeOrderBtn && orderLoading) {
        placeOrderBtn.style.display = 'none';
        orderLoading.style.display = 'block';
      }
      
      console.log('Form will now submit...');
      
      // Add timeout to reset state if something goes wrong
      setTimeout(() => {
        console.log('10 second timeout reached, resetting form state');
        isFormSubmitting = false;
        if (placeOrderBtn && orderLoading) {
          placeOrderBtn.style.display = 'block';
          orderLoading.style.display = 'none';
        }
      }, 10000); // Reset after 10 seconds
      
      console.log('Form validation passed, allowing submission');
      return true; // Allow form submission
    }

    function closeAlert(alertId) {
      const alert = document.getElementById(alertId);
      if (alert) {
        alert.style.animation = 'slideOutUp 0.3s ease-in forwards';
        setTimeout(() => {
          alert.remove();
        }, 300);
      }
    }

    // Show alert function
    function showAlert(message, type = 'info', duration = 4000) {
        const alertDiv = document.createElement('div');
        alertDiv.className = `alert alert-${type}`;
        alertDiv.innerHTML = `<i class="fas fa-info-circle"></i> ${message}`;
        alertDiv.style.cssText = 'position: fixed; top: 100px; right: 20px; z-index: 1000; max-width: 300px; animation: slideIn 0.3s ease;';
        
        document.body.appendChild(alertDiv);
        
        setTimeout(() => {
            alertDiv.style.animation = 'slideOut 0.3s ease';
            setTimeout(() => alertDiv.remove(), 300);
        }, duration);
    }
    
    // Define payment selection function globally first
    function selectPaymentOption(method) {
      console.log('=== selectPaymentOption called ===');
      console.log('Method:', method);
      
      try {
        // Remove previous selections
        const allOptions = document.querySelectorAll('.payment-option');
        console.log('Found payment options:', allOptions.length);
        
        allOptions.forEach((option, index) => {
          console.log(`Option ${index}:`, option);
          option.classList.remove('selected');
          // Hide all selected badges
          const badge = option.querySelector('.payment-selected-badge');
          if (badge) {
            badge.style.display = 'none';
            badge.classList.remove('show');
            console.log(`Hidden badge for option ${index}`);
          }
        });
        
        // Select current option
        const radioSelector = `#booking_${method}`;
        console.log('Looking for radio button:', radioSelector);
        const radioButton = document.getElementById(`booking_${method}`);
        console.log('Found radio button:', radioButton);
        
        if (!radioButton) {
          console.error('Radio button not found for method:', method);
          return;
        }
        
        const selectedOption = radioButton.closest('.payment-option');
        console.log('Found payment option:', selectedOption);
        
        if (selectedOption) {
          selectedOption.classList.add('selected');
          console.log('Added selected class to option');
          
          // Show the selected badge
          const badge = selectedOption.querySelector('.payment-selected-badge');
          console.log('Found badge:', badge);
          if (badge) {
            badge.style.display = 'flex';
            badge.classList.add('show');
            console.log('Showing badge for:', method);
          }
          
          // Add a slight animation effect
          selectedOption.style.transform = 'translateY(-5px)';
          setTimeout(() => {
            selectedOption.style.transform = 'translateY(-2px)';
          }, 150);
          
          // Check the radio button
          radioButton.checked = true;
          console.log('Radio button checked:', radioButton.checked);
          
          // Update order summary
          if (typeof updatePaymentMethodSummary === 'function') {
            updatePaymentMethodSummary();
          }
          
          // Enable place order button
          const placeOrderBtn = document.getElementById('place-order-btn');
          if (placeOrderBtn) {
            placeOrderBtn.disabled = false;
            placeOrderBtn.style.opacity = '1';
          }
          
          // Show success feedback
          if (typeof showAlert === 'function') {
            showAlert('Payment method selected: ' + getPaymentMethodName(method), 'success', 2000);
          }
          
          console.log('=== Selection completed successfully ===');
        } else {
          console.error('Payment option not found for method:', method);
        }
      } catch (error) {
        console.error('Error in selectPaymentOption:', error);
      }
    }
    
    // Helper function to get payment method display name
    function getPaymentMethodName(method) {
      // Only COD is supported
      return 'Cash on Delivery';
    }
    
    // Make functions globally accessible
    window.selectPaymentOption = selectPaymentOption;
    window.getPaymentMethodName = getPaymentMethodName;
    
    let selectedPrice = 0;
    let inventoryData = {};
    let inventoryLoaded = false;
    let isFormSubmitting = false;
    
    // Initialize inventory monitoring
    document.addEventListener('DOMContentLoaded', function() {
        console.log('Page loaded, initializing inventory system...');
        
      // Check if order was just placed successfully
      const urlParams = new URLSearchParams(window.location.search);
      if (urlParams.get('order_placed') === '1') {
        // Scroll to top to show success message
        window.scrollTo({ top: 0, behavior: 'smooth' });
        
        // Switch to Orders tab after a short delay
        setTimeout(() => {
          showTab('orders');
          // Clean URL
          window.history.replaceState({}, document.title, 'customer-portal.php');
        }, 3000); // Wait 3 seconds before switching to orders tab
      }
      
      // Setup form submission handler
      const bookingForm = document.querySelector('.booking-form');
      if (bookingForm) {
        console.log('Setting up form submit handler...');
        bookingForm.addEventListener('submit', function(event) {
          console.log('=== Form submit event triggered ===');
          
          // Log form state
          const formData = new FormData(this);
          console.log('Form data before submission:');
          for (let [key, value] of formData.entries()) {
            console.log(`${key}: ${value}`);
          }
          
          // Only validate and confirm if not force submitting
          if (!this.dataset.forceSubmit) {
            // Prevent default submission first
            event.preventDefault();
            
            // Call confirmOrder function
            const shouldSubmit = confirmOrder(event);
            console.log('confirmOrder returned:', shouldSubmit);
            
            if (shouldSubmit) {
              console.log('Form validated, submitting...');
              this.submit();
            } else {
              console.log('Form submission prevented by validation');
            }
          } else {
            console.log('Force submit flag found, allowing submission...');
            // Remove the flag
            delete this.dataset.forceSubmit;
          }
        });
      } else {
        console.error('Booking form not found!');
      }
        
      console.log('=== Document ready - Setting up payment options ===');
      
      // Make selectPaymentOption globally accessible
      window.selectPaymentOption = selectPaymentOption;

      // Wire the visible select to the card selector as a convenience
      try {
        const cylinderSelect = document.getElementById('cylinder_select');
        if (cylinderSelect) {
          cylinderSelect.addEventListener('change', function(e) {
            const val = this.value;
            if (!val) return;
            const opt = this.selectedOptions && this.selectedOptions[0];
            const price = opt ? parseFloat(opt.getAttribute('data-price')) || 0 : 0;
            // Use existing selectCylinder to keep UI consistent
            if (typeof selectCylinder === 'function') {
              selectCylinder(val, price);
            }
          });

          // If a card/radio is already selected, sync the select
          const checkedRadio = document.querySelector('input[name="cylinder_type"]:checked');
          if (checkedRadio) {
            const val = checkedRadio.value;
            cylinderSelect.value = val;
          }
        }
      } catch (e) {
        console.warn('Failed to wire cylinder select fallback:', e);
      }
      
      // Add backup event listeners to payment options
      document.querySelectorAll('.payment-option').forEach(option => {
        const onclick = option.getAttribute('onclick');
        if (onclick) {
          const method = onclick.match(/selectPaymentOption\('(\w+)'\)/);
          if (method && method[1]) {
            console.log('Adding backup listener for:', method[1]);
            option.addEventListener('click', function(e) {
              e.preventDefault();
              console.log('Backup click handler triggered for:', method[1]);
              selectPaymentOption(method[1]);
            });
          }
        }
      });

      // Visually preselect COD by default without enabling submit prematurely
      try {
        const codRadio = document.getElementById('booking_cod');
        const codOption = codRadio ? codRadio.closest('.payment-option') : null;
        if (codRadio && codOption) {
          codRadio.checked = true;
          codOption.classList.add('selected');
          const badge = codOption.querySelector('.payment-selected-badge');
          if (badge) {
            badge.style.display = 'flex';
            badge.classList.add('show');
          }
          if (typeof updatePaymentMethodSummary === 'function') {
            updatePaymentMethodSummary();
          }
        }
      } catch (e) {
        console.warn('Failed to preselect COD visually:', e);
      }
      
      // Initialize form validation
      initializeFormValidation();        // Load inventory data
        refreshInventoryData().then(() => {
            inventoryLoaded = true;
            console.log('Inventory system initialized');
        });
        setInterval(refreshInventoryData, 30000); // Refresh every 30 seconds
        
        // Monitor quantity changes
        const quantityInput = document.getElementById('quantity');
        if (quantityInput) {
            quantityInput.addEventListener('change', validateQuantity);
            quantityInput.addEventListener('input', validateQuantity);
        }
        
    // Add special handling for contact number field
    const contactInput = document.getElementById('contact_number');
    if (contactInput) {
      const updateContactNumberState = (inputEl) => {
        if (!inputEl) {
          return;
        }

        let value = inputEl.value.replace(/\D/g, '');
        if (value.length > 10) {
          value = value.slice(0, 10);
        }

        if (inputEl.value !== value) {
          inputEl.value = value;
        }

        clearFieldError(inputEl);

        if (value.length === 0) {
          inputEl.classList.remove('field-valid', 'field-invalid');
          updateValidationMessage('contact_number', '', null);
          return;
        }

        if (value.length < 10) {
          const digitsNeeded = 10 - value.length;
          inputEl.classList.remove('field-valid');
          inputEl.classList.add('field-invalid');
          updateValidationMessage('contact_number', `Please enter exactly 10 digits (${digitsNeeded} more needed)`, false);
          return;
        }

        inputEl.classList.remove('field-invalid');
        inputEl.classList.add('field-valid');
        updateValidationMessage('contact_number', 'Valid mobile number ✓', true);
      };

      contactInput.addEventListener('input', function(e) {
        updateContactNumberState(e.target);
      });
            
      // Validate on blur with full rule set
      contactInput.addEventListener('blur', function(e) {
        validateField(e.target);
      });

      // Normalize any pre-filled value on load
      updateContactNumberState(contactInput);
    }
    });
    
    // Initialize form validation
    function initializeFormValidation() {
        const form = document.querySelector('.booking-form');
        if (form) {
            form.addEventListener('submit', function(e) {
                if (isFormSubmitting) {
                    e.preventDefault();
                    return false;
                }
                
                if (!validateBookingForm()) {
                    e.preventDefault();
                    return false;
                }
            });
        }
        
        // Add direct event listeners to cylinder cards as backup
        setTimeout(() => {
            const cylinderCards = document.querySelectorAll('.cylinder-card');
            console.log('Adding backup event listeners to', cylinderCards.length, 'cylinder cards');
            
            cylinderCards.forEach(card => {
                if (!card.classList.contains('out-of-stock')) {
                    card.addEventListener('click', function(e) {
                        const cylinderType = this.getAttribute('data-cylinder');
                        const price = parseFloat(this.getAttribute('data-price'));
                        
                        console.log('Backup click handler triggered for:', cylinderType, price);
                        
                        if (cylinderType && price) {
                            testCylinderSelection(cylinderType, price);
                        }
                    });
                }
            });
        }, 1000);
        
        // Add real-time validation for required fields
        const requiredFields = ['delivery_date', 'contact_number', 'delivery_address'];
        requiredFields.forEach(fieldName => {
            const field = document.getElementById(fieldName);
            if (field) {
                field.addEventListener('blur', function() {
                    validateField(this);
                });
                field.addEventListener('input', function() {
                    clearFieldError(this);
                });
            }
        });
    }
    
    // Validate individual field
    function validateField(field) {
        const value = field.value.trim();
        const fieldName = field.name || field.id;
        
        clearFieldError(field);
        
        if (field.hasAttribute('required') && !value) {
            showFieldError(field, `${getFieldLabel(fieldName)} is required`);
            return false;
        }
        
        // Specific validations
        if (fieldName === 'contact_number') {
            if (value) {
                // Remove any spaces, hyphens, or other formatting
                let cleanNumber = value.replace(/[\s\-\(\)\.]/g, '');
                
                // Remove +91 prefix if present
                cleanNumber = cleanNumber.replace(/^\+91/, '');
                
                console.log('Validating contact number:', value, 'cleaned:', cleanNumber, 'length:', cleanNumber.length);
                
                // Remove any non-digit characters
                const digitsOnly = cleanNumber.replace(/\D/g, '');
                
        // Check if it's a valid 10-digit mobile number
        if (!/^\d{10}$/.test(digitsOnly)) {
                    field.classList.remove('field-valid');
                    field.classList.add('field-invalid');
                    if (digitsOnly.length === 0) {
                        updateValidationMessage(fieldName, 'Contact number is required', false);
                    } else if (digitsOnly.length < 10) {
                        updateValidationMessage(fieldName, `Please enter exactly 10 digits (you entered ${digitsOnly.length})`, false);
                    } else if (digitsOnly.length > 10) {
                        updateValidationMessage(fieldName, `Please enter exactly 10 digits (you entered ${digitsOnly.length})`, false);
                    } else {
            updateValidationMessage(fieldName, 'Please enter a valid 10-digit mobile number', false);
                    }
                    return false;
                } else {
                    field.classList.remove('field-invalid');
                    field.classList.add('field-valid');
                    updateValidationMessage(fieldName, 'Valid mobile number ✓', true);
                    // Update the field value with clean number
                    if (digitsOnly !== value) {
                        field.value = digitsOnly;
                    }
                }
            } else {
                field.classList.remove('field-valid');
                field.classList.add('field-invalid');
                updateValidationMessage(fieldName, 'Contact number is required', false);
                return false;
            }
        }
        
        if (fieldName === 'delivery_date') {
            const selectedDate = new Date(value);
            const tomorrow = new Date();
            tomorrow.setDate(tomorrow.getDate() + 1);
            
            if (value && selectedDate < tomorrow) {
                showFieldError(field, 'Delivery date must be at least tomorrow');
                return false;
            }
        }
        
        return true;
    }
    
    // Show field error
    function showFieldError(field, message) {
        const errorId = field.id + '_error';
        let errorDiv = document.getElementById(errorId);
        
        if (!errorDiv) {
            errorDiv = document.createElement('div');
            errorDiv.id = errorId;
            errorDiv.className = 'field-error';
            errorDiv.style.cssText = 'color: #e74c3c; font-size: 0.9rem; margin-top: 0.5rem; display: flex; align-items: center; gap: 0.5rem;';
            field.parentNode.appendChild(errorDiv);
        }
        
        errorDiv.innerHTML = `<i class="fas fa-exclamation-circle"></i> ${message}`;
        errorDiv.style.display = 'flex';
    }
    
    // Update validation message
  function updateValidationMessage(fieldName, message, isValid) {
    const messageElement = document.getElementById(fieldName + '_message');
    if (messageElement) {
      messageElement.textContent = message || '';
      if (typeof isValid === 'boolean') {
        messageElement.className = 'validation-message ' + (isValid ? 'valid' : 'invalid');
      } else {
        messageElement.className = 'validation-message';
      }
    }
  }
    
    // Clear field error
    function clearFieldError(field) {
        const errorId = field.id + '_error';
        const errorDiv = document.getElementById(errorId);
        if (errorDiv) {
            errorDiv.remove();
        }
        field.style.borderColor = '';
    }
    
    // Get user-friendly field label
    function getFieldLabel(fieldName) {
        const labels = {
            'delivery_date': 'Delivery Date',
            'contact_number': 'Contact Number',
            'delivery_address': 'Delivery Address',
            'cylinder_type': 'Cylinder Type',
            'payment_method': 'Payment Method'
        };
        return labels[fieldName] || fieldName.replace('_', ' ').replace(/\b\w/g, l => l.toUpperCase());
    }
    
    // Validate entire booking form
    function validateBookingForm() {
        console.log('=== validateBookingForm called ===');
        let isValid = true;
        let firstInvalidField = null;
        
        // Check cylinder selection
        const selectedCylinder = document.querySelector('input[name="cylinder_type"]:checked');
        const allCylinders = document.querySelectorAll('input[name="cylinder_type"]');
        console.log('All cylinder radio buttons found:', allCylinders.length);
        console.log('Selected cylinder:', selectedCylinder);
        console.log('Selected price:', selectedPrice);
        
        if (!selectedCylinder) {
            console.log('❌ No cylinder selected!');
            console.log('Checking if we have a selected card...');
            const selectedCard = document.querySelector('.cylinder-card.selected');
            if (selectedCard) {
                console.log('Found selected card, attempting to check its radio button...');
                const radioInCard = selectedCard.querySelector('input[name="cylinder_type"]');
                if (radioInCard) {
                  radioInCard.checked = true;
                  console.log('✅ Radio button checked from selected card');
                  // Continue with validation instead of returning false
                } else {
                  console.log('No radio button found in selected card');
                  showAlert('Please select a cylinder type', 'error');
                  isValid = false;
                  return false;
                }
            } else {
                console.log('No selected card found');
                showAlert('Please select a cylinder type', 'error');
                isValid = false;
                return false;
            }
        }
        
        // Payment method is pre-selected (COD), so no need to validate
        
        // Validate required fields
        const requiredFields = ['delivery_date', 'contact_number', 'delivery_address'];
        console.log('Validating required fields:', requiredFields);
        
        for (const fieldName of requiredFields) {
            const field = document.getElementById(fieldName);
            console.log(`Checking field ${fieldName}:`, field?.value);
            
            if (!field) {
                console.log(`Field ${fieldName} not found!`);
                continue;
            }
            
            if (!field.value || field.value.trim() === '') {
                console.log(`Field ${fieldName} is empty!`);
                showAlert(`Please fill in ${getFieldLabel(fieldName)}`, 'error');
                isValid = false;
                if (!firstInvalidField) {
                    firstInvalidField = field;
                }
            }
        }
        
        // Focus and scroll to first invalid field
        if (!isValid && firstInvalidField) {
            firstInvalidField.focus();
            firstInvalidField.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
        
        console.log('Final validation result:', isValid);
        return isValid;
    }
    
    // Refresh inventory data from server
    async function refreshInventoryData() {
        try {
            console.log('Fetching inventory data...');
            const response = await fetch('api/inventory.php?action=get_availability');
            const data = await response.json();
            
            console.log('Inventory API response:', data);
            
            if (data.success) {
                inventoryData = {};
                data.cylinders.forEach(cylinder => {
                    inventoryData[cylinder.cylinder_type] = cylinder;
                });
                console.log('Inventory data loaded:', inventoryData);
                updateInventoryDisplay();
                return true;
            } else {
                console.error('Inventory API returned error:', data);
                return false;
            }
        } catch (error) {
            console.error('Failed to refresh inventory data:', error);
            // Continue without client-side data - server-side rendering will handle availability
            return false;
        }
    }
    
    // Update inventory display in real-time
    function updateInventoryDisplay() {
        const cylinderCards = document.querySelectorAll('.cylinder-card');
        
        cylinderCards.forEach(card => {
            const cylinderType = card.querySelector('h4').textContent;
            const inventory = inventoryData[cylinderType];
            
            if (inventory) {
                // Update availability status
                const availabilityDiv = card.querySelector('.cylinder-availability');
                const availabilityText = card.querySelector('.availability-text');
                const progressFill = card.querySelector('.stock-progress-fill');
                
                if (inventory.available_quantity === 0) {
                  card.classList.add('out-of-stock');
                  card.classList.remove('low-stock', 'medium-stock');
                  card.onclick = null;
                  card.style.cursor = 'not-allowed';
                } else {
                  card.classList.remove('out-of-stock');
                  card.style.cursor = 'pointer';
                  
                  // Update availability text
                  if (availabilityText) {
                    if (inventory.stock_level === 'low') {
                      availabilityText.textContent = `Low Stock - ${inventory.available_quantity} left`;
                    } else if (inventory.stock_level === 'medium') {
                      availabilityText.textContent = `${inventory.available_quantity} available`;
                    } else {
                      availabilityText.textContent = `${inventory.available_quantity} in stock`;
                    }
                  }
                  
                  // Update progress bar
                  if (progressFill) {
                    progressFill.style.width = `${inventory.availability_percent}%`;
                  }
                }
            }
        });
    }
    let currentOrderId = null;
    let selectedPaymentMethod = null;

    // Cancel Order Modal Functions
    function showCancelModal(orderId, cylinderType) {
      console.log('Opening cancel modal for order:', orderId, 'Type:', cylinderType);
      
      if (!orderId || orderId === '' || isNaN(orderId)) {
        alert('Error: Invalid order ID. Please refresh the page and try again.');
        console.error('Invalid order ID:', orderId);
        return;
      }
      
      document.getElementById('modalOrderId').textContent = orderId;
      document.getElementById('modalCylinderType').textContent = cylinderType;
      document.getElementById('cancelOrderId').value = orderId;
      document.getElementById('cancelOrderModal').style.display = 'block';
      document.body.style.overflow = 'hidden'; // Prevent scrolling
      
      console.log('Modal opened successfully');
    }

    function closeCancelModal() {
      document.getElementById('cancelOrderModal').style.display = 'none';
      document.body.style.overflow = 'auto'; // Restore scrolling
      document.getElementById('cancel_reason').value = ''; // Clear form
    }

    // Submit cancellation form to server
    function confirmCancelOrder() {
      try {
        const form = document.getElementById('cancelOrderForm');
        if (!form) {
          alert('Cancellation form not found. Please refresh the page and try again.');
          return false;
        }

        const orderIdField = document.getElementById('cancelOrderId');
        const orderId = orderIdField ? orderIdField.value : null;
        if (!orderId || isNaN(orderId)) {
          alert('Invalid order id. Please refresh the page and try again.');
          return false;
        }

        // Optionally confirm again
        if (!confirm('Are you sure you want to cancel Order #' + orderId + '? This cannot be undone.')) {
          return false;
        }

        // Ensure the required hidden field exists so server sees the cancel flag
        let cancelFlag = form.querySelector('input[name="cancel_order"]');
        if (!cancelFlag) {
          cancelFlag = document.createElement('input');
          cancelFlag.type = 'hidden';
          cancelFlag.name = 'cancel_order';
          cancelFlag.value = '1';
          form.appendChild(cancelFlag);
        } else {
          cancelFlag.value = '1';
        }

        // Disable buttons to prevent double submit
        const confirmBtn = document.querySelector('#cancelOrderModal .btn-modal.confirm');
        const keepBtn = document.querySelector('#cancelOrderModal .btn-modal.cancel');
        if (confirmBtn) {
          confirmBtn.disabled = true;
          confirmBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Cancelling...';
        }
        if (keepBtn) keepBtn.disabled = true;

        // Submit the form
        form.submit();
        return true;
      } catch (err) {
        console.error('confirmCancelOrder error:', err);
        alert('An error occurred while attempting to cancel the order. Please try again.');
        return false;
      }
    }

    // Payment Modal Functions
    function showPaymentModal(orderId, amount) {
      console.log('Opening payment modal for order:', orderId, 'Amount:', amount);
      
      if (!orderId || orderId === '' || isNaN(orderId)) {
        alert('Error: Invalid order ID. Please refresh the page and try again.');
        console.error('Invalid order ID:', orderId);
        return;
      }
      
      currentOrderId = orderId;
      document.getElementById('paymentOrderId').textContent = orderId;
      document.getElementById('paymentAmount').textContent = amount;
      document.getElementById('paymentModal').style.display = 'block';
      document.body.style.overflow = 'hidden';
      
      // Auto-select COD (only option available)
      selectedPaymentMethod = 'cod';
      document.querySelectorAll('.payment-method').forEach(method => {
        method.classList.remove('selected');
      });
      const codMethod = document.querySelector('.payment-method');
      if (codMethod) {
        codMethod.classList.add('selected');
      }
      const codRadio = document.getElementById('cod_payment');
      if (codRadio) {
        codRadio.checked = true;
      }
      document.getElementById('proceedPaymentBtn').disabled = false;
      
      console.log('Payment modal opened with COD pre-selected');
    }

    function closePaymentModal() {
      document.getElementById('paymentModal').style.display = 'none';
      document.body.style.overflow = 'auto';
      selectedPaymentMethod = null;
      currentOrderId = null;
    }

    function selectPaymentMethod(method) {
      selectedPaymentMethod = method;
      
      // Update UI
      document.querySelectorAll('.payment-method').forEach(el => {
        el.classList.remove('selected');
      });
      
      const selectedElement = event.currentTarget || document.querySelector(`input[value="${method}"]`).closest('.payment-method');
      selectedElement.classList.add('selected');
      
      // Update radio button
      document.getElementById(method + '_payment').checked = true;
      
      // Enable proceed button
      document.getElementById('proceedPaymentBtn').disabled = false;
      
      console.log('Payment method selected:', method);
    }

    function proceedWithPayment() {
      if (!selectedPaymentMethod || !currentOrderId) {
        alert('Please select a payment method first.');
        return;
      }
      
      console.log('Proceeding with payment:', selectedPaymentMethod, 'for order:', currentOrderId);
      
      if (selectedPaymentMethod === 'cod') {
        // Set COD preference
        setCODPreference(currentOrderId);
      }
    }

    function setCODPreference(orderId) {
      const proceedBtn = document.getElementById('proceedPaymentBtn');
      const originalText = proceedBtn.innerHTML;
      proceedBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
      proceedBtn.disabled = true;
      
      fetch('payments/set_payment_method.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({
          order_id: orderId,
          payment_method: 'cod'
        })
      })
      .then(response => response.json())
      .then(data => {
        if (data && data.success) {
          // Use existing alert helper for consistency
          if (typeof showAlert === 'function') {
            showAlert('Cash on Delivery preference set successfully!', 'success', 2000);
          } else {
            alert('Cash on Delivery preference set successfully!');
          }
          closePaymentModal();
          setTimeout(() => {
            location.reload(); // Refresh to show updated payment status
          }, 1200);
        } else {
          alert('Error: ' + (data && data.message ? data.message : 'Failed to set payment preference'));
          proceedBtn.innerHTML = originalText;
          proceedBtn.disabled = false;
        }
      })
      .catch(err => {
        console.error('Network or server error while setting payment method:', err);
        alert('Network error: Failed to set payment preference. Please try again.');
        try {
          proceedBtn.innerHTML = originalText;
          proceedBtn.disabled = false;
        } catch (e) {
          console.warn('Could not restore proceed button state:', e);
        }
      });
    }

    // End of script — close remaining tags
    // Tab switching helper: show a tab content by id and keep URL in sync
    function showTab(tabId) {
      try {
        // Normalize fallback ids
        const allowed = ['book', 'orders', 'profile'];
        if (!tabId || allowed.indexOf(tabId) === -1) {
          tabId = 'book';
        }

        // Toggle tab-content
        document.querySelectorAll('.tab-content').forEach(tc => {
          if (tc.id === tabId) {
            tc.classList.add('active');
          } else {
            tc.classList.remove('active');
          }
        });

        // Toggle tab buttons (match by onclick argument or text)
        document.querySelectorAll('.tab').forEach(btn => {
          try {
            const onclick = btn.getAttribute('onclick') || '';
            if (onclick.indexOf("'" + tabId + "'") !== -1 || onclick.indexOf('"' + tabId + '"') !== -1) {
              btn.classList.add('active');
            } else {
              btn.classList.remove('active');
            }
          } catch (e) { /* ignore */ }
        });

        // Update URL query param without reloading
        try {
          const url = new URL(window.location.href);
          url.searchParams.set('tab', tabId);
          history.replaceState(null, '', url.toString());
        } catch (e) { /* ignore if URL API not available */ }
      } catch (err) {
        console.error('showTab error:', err);
      }
    }

    // Initialize tab based on URL param or hash on load
    (function initTabsFromUrl() {
      try {
        const urlParams = new URLSearchParams(window.location.search);
        let tab = urlParams.get('tab') || (window.location.hash ? window.location.hash.replace('#', '') : '') || 'book';
        // Sanitize
        tab = String(tab).toLowerCase();
        const allowed = ['book', 'orders', 'profile'];
        if (allowed.indexOf(tab) === -1) tab = 'book';
        // Defer to ensure DOM is ready
        setTimeout(() => showTab(tab), 30);
      } catch (e) {
        console.warn('initTabsFromUrl failed:', e);
      }
    })();

  </script>
</body>
</html>