<?php
session_start();
require_once 'config/database.php';
require_once 'includes/auth.php';

// Initialize Auth class
$auth = new Auth();

// Check if admin is logged in
if (!$auth->isLoggedIn() || $auth->getCurrentUser()['role'] !== 'admin') {
    header('Location: admin-portal.php');
    exit;
}

$current_user = $auth->getCurrentUser();
$message = '';
$error = '';

// Handle operations
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['assign_delivery'])) {
        $order_id = intval($_POST['order_id']);
        $delivery_staff_id = intval($_POST['delivery_staff_id']);
        
        try {
            // First, check if delivery record exists
            $check_sql = "SELECT id FROM deliveries WHERE order_id = ?";
            $check_stmt = executeQuery($check_sql, [$order_id]);
            $existing_delivery = $check_stmt->fetch();
            
            if ($existing_delivery) {
                // Update existing delivery record
                $assign_sql = "UPDATE deliveries SET delivery_person_id = ?, status = 'assigned', updated_at = NOW() WHERE order_id = ?";
                executeQuery($assign_sql, [$delivery_staff_id, $order_id]);
            } else {
                // Create new delivery record
                $assign_sql = "INSERT INTO deliveries (order_id, delivery_person_id, status, created_at, updated_at) VALUES (?, ?, 'assigned', NOW(), NOW())";
                executeQuery($assign_sql, [$order_id, $delivery_staff_id]);
            }
            
            // Update order status using Orders class for notifications
            require_once 'includes/orders.php';
            $orders = new OrderManager();
            $orders->updateOrderStatus($order_id, 'processing', null, $_SESSION['user_id'] ?? null);
            
            $message = "Delivery staff assigned successfully! Customer has been notified.";
        } catch (Exception $e) {
            $error = "Error assigning delivery staff: " . $e->getMessage();
        }
    }
    
    if (isset($_POST['update_route'])) {
        $order_id = intval($_POST['order_id']);
        $route_notes = trim($_POST['route_notes']);
        $estimated_time = trim($_POST['estimated_time']);
        
        try {
            // Update delivery notes - store in deliveries table
            $route_sql = "UPDATE deliveries SET notes = ?, updated_at = NOW() WHERE order_id = ?";
            executeQuery($route_sql, [$route_notes, $order_id]);
            $message = "Route information updated successfully!";
        } catch (Exception $e) {
            $error = "Error updating route: " . $e->getMessage();
        }
    }
    
    if (isset($_POST['bulk_assign'])) {
        $selected_orders_str = $_POST['selected_orders_bulk'] ?? '';
        $selected_orders = $selected_orders_str ? explode(',', $selected_orders_str) : [];
        $bulk_staff_id = intval($_POST['bulk_staff_id']);
        
        if (!empty($selected_orders) && $bulk_staff_id > 0) {
            try {
                foreach ($selected_orders as $order_id) {
                    // Check if delivery record exists
                    $check_sql = "SELECT id FROM deliveries WHERE order_id = ?";
                    $check_stmt = executeQuery($check_sql, [$order_id]);
                    $existing_delivery = $check_stmt->fetch();
                    
                    if ($existing_delivery) {
                        // Update existing delivery record
                        $assign_sql = "UPDATE deliveries SET delivery_person_id = ?, status = 'assigned', updated_at = NOW() WHERE order_id = ?";
                        executeQuery($assign_sql, [$bulk_staff_id, $order_id]);
                    } else {
                        // Create new delivery record
                        $assign_sql = "INSERT INTO deliveries (order_id, delivery_person_id, status, created_at, updated_at) VALUES (?, ?, 'assigned', NOW(), NOW())";
                        executeQuery($assign_sql, [$order_id, $bulk_staff_id]);
                    }
                    
                    // Update order status using Orders class for notifications
                    require_once 'includes/orders.php';
                    $orders = new OrderManager();
                    $orders->updateOrderStatus($order_id, 'processing', null, $_SESSION['user_id'] ?? null);
                }
                $message = "Bulk assignment completed for " . count($selected_orders) . " orders! Customers have been notified.";
            } catch (Exception $e) {
                $error = "Error in bulk assignment: " . $e->getMessage();
            }
        } else {
            $error = "Please select orders and staff member for bulk assignment.";
        }
    }
    
    if (isset($_POST['optimize_routes'])) {
        try {
            // Get all unoptimized orders with delivery staff assigned
            $unoptimized_sql = "SELECT o.*, c.address as customer_address, c.name as customer_name,
                               ds.name as delivery_staff_name, d.delivery_person_id, d.notes
                               FROM orders o
                               LEFT JOIN users c ON o.customer_id = c.id
                               LEFT JOIN deliveries d ON o.id = d.order_id
                               LEFT JOIN users ds ON d.delivery_person_id = ds.id
                               WHERE d.delivery_person_id IS NOT NULL 
                               AND o.status IN ('processing', 'confirmed')
                               ORDER BY d.delivery_person_id, o.created_at";
            $unoptimized_stmt = executeQuery($unoptimized_sql);
            $orders_to_optimize = $unoptimized_stmt->fetchAll();
            
            if (!empty($orders_to_optimize)) {
                // Group orders by delivery staff
                $grouped_orders = [];
                foreach ($orders_to_optimize as $order) {
                    $staff_id = $order['delivery_person_id'];
                    if (!isset($grouped_orders[$staff_id])) {
                        $grouped_orders[$staff_id] = [];
                    }
                    $grouped_orders[$staff_id][] = $order;
                }
                
                $optimized_count = 0;
                
                // Simple route optimization: prioritize by creation time and location proximity
                foreach ($grouped_orders as $staff_id => $staff_orders) {
                    // Sort orders by creation time (FIFO approach)
                    usort($staff_orders, function($a, $b) {
                        return strtotime($a['created_at']) - strtotime($b['created_at']);
                    });
                    
                    // Update route sequence and notes
                    $sequence = 1;
                    
                    foreach ($staff_orders as $order) {
                        $route_notes = "Optimized Route #$sequence - Priority delivery order";
                        
                        $optimize_sql = "UPDATE deliveries SET 
                                        notes = ?,
                                        status = 'assigned',
                                        updated_at = NOW() 
                                        WHERE order_id = ?";
                        executeQuery($optimize_sql, [$route_notes, $order['id']]);
                        
                        $sequence++;
                        $optimized_count++;
                    }
                }
                
                $message = "Route optimization completed! $optimized_count orders optimized across " . count($grouped_orders) . " delivery staff.";
            } else {
                $message = "No orders available for route optimization. Assign delivery staff first.";
            }
        } catch (Exception $e) {
            $error = "Error optimizing routes: " . $e->getMessage();
        }
    }
}

// Fetch delivery operations data
$operations_stats = [
    'active_deliveries' => 0,
    'unassigned_orders' => 0,
    'available_staff' => 0,
    'completed_today' => 0,
    'average_delivery_time' => '0 mins',
    'efficiency_rate' => '0%'
];

try {
    // Active deliveries (out for delivery)
    $active_sql = "SELECT COUNT(*) as active FROM orders WHERE status = 'out_for_delivery'";
    $active_stmt = executeQuery($active_sql);
    $operations_stats['active_deliveries'] = $active_stmt->fetch()['active'] ?? 0;
    
    // Unassigned orders
    $unassigned_sql = "SELECT COUNT(*) as unassigned FROM orders o 
                       LEFT JOIN deliveries d ON o.id = d.order_id 
                       WHERE d.delivery_person_id IS NULL AND o.status IN ('confirmed', 'processing')";
    $unassigned_stmt = executeQuery($unassigned_sql);
    $operations_stats['unassigned_orders'] = $unassigned_stmt->fetch()['unassigned'] ?? 0;
    
    // Available staff
    $available_sql = "SELECT COUNT(*) as available FROM users WHERE role = 'delivery'";
    $available_stmt = executeQuery($available_sql);
    $operations_stats['available_staff'] = $available_stmt->fetch()['available'] ?? 0;
    
    // Completed today
    $completed_sql = "SELECT COUNT(*) as completed FROM orders WHERE status = 'delivered' AND DATE(updated_at) = CURDATE()";
    $completed_stmt = executeQuery($completed_sql);
    $operations_stats['completed_today'] = $completed_stmt->fetch()['completed'] ?? 0;
    
    // Calculate average delivery time (simplified)
    $avg_time_sql = "SELECT AVG(TIMESTAMPDIFF(HOUR, created_at, updated_at)) as avg_hours FROM orders WHERE status = 'delivered' AND updated_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
    $avg_time_stmt = executeQuery($avg_time_sql);
    $avg_hours = $avg_time_stmt->fetch()['avg_hours'] ?? 0;
    $operations_stats['average_delivery_time'] = round($avg_hours * 60) . ' mins';
    
    // Efficiency rate (on-time deliveries)
    $efficiency_sql = "SELECT 
        COUNT(CASE WHEN TIMESTAMPDIFF(HOUR, created_at, updated_at) <= 24 THEN 1 END) * 100.0 / COUNT(*) as efficiency
        FROM orders WHERE status = 'delivered' AND updated_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
    $efficiency_stmt = executeQuery($efficiency_sql);
    $efficiency = $efficiency_stmt->fetch()['efficiency'] ?? 0;
    $operations_stats['efficiency_rate'] = round($efficiency) . '%';
    
} catch (Exception $e) {
    error_log("Error fetching operations stats: " . $e->getMessage());
}

// Fetch all orders for operations
$orders = [];
try {
    $orders_sql = "SELECT o.*, 
                          c.name as customer_name, 
                          c.phone as customer_phone,
                          c.address as customer_address,
                          ds.name as delivery_staff_name,
                          ds.phone as delivery_staff_phone,
                          d.delivery_person_id,
                          d.notes as route_notes
                   FROM orders o
                   LEFT JOIN users c ON o.customer_id = c.id
                   LEFT JOIN deliveries d ON o.id = d.order_id
                   LEFT JOIN users ds ON d.delivery_person_id = ds.id
                   WHERE o.status IN ('confirmed', 'processing', 'out_for_delivery')
                   ORDER BY 
                     CASE o.status 
                       WHEN 'out_for_delivery' THEN 1
                       WHEN 'processing' THEN 2
                       WHEN 'confirmed' THEN 3
                     END,
                     o.created_at ASC";
    $orders_stmt = executeQuery($orders_sql);
    $orders = $orders_stmt->fetchAll();
} catch (Exception $e) {
    error_log("Error fetching orders: " . $e->getMessage());
}

// Fetch delivery staff
$delivery_staff = [];
try {
    $staff_sql = "SELECT id, name, phone FROM users WHERE role = 'delivery' ORDER BY name";
    $staff_stmt = executeQuery($staff_sql);
    $delivery_staff = $staff_stmt->fetchAll();
} catch (Exception $e) {
    error_log("Error fetching delivery staff: " . $e->getMessage());
}

// Get filters
$filter_status = $_GET['filter_status'] ?? 'all';
$filter_staff = $_GET['filter_staff'] ?? 'all';
$search = $_GET['search'] ?? '';

// Apply filters
$filtered_orders = $orders;
if ($filter_status !== 'all') {
    $filtered_orders = array_filter($filtered_orders, function($order) use ($filter_status) {
        return strtolower($order['status']) === strtolower($filter_status);
    });
}

if ($filter_staff !== 'all') {
    $filtered_orders = array_filter($filtered_orders, function($order) use ($filter_staff) {
        if ($filter_staff === 'unassigned') {
            return empty($order['delivery_person_id']);
        }
        return $order['delivery_person_id'] == $filter_staff;
    });
}

if ($search) {
    $filtered_orders = array_filter($filtered_orders, function($order) use ($search) {
        return stripos($order['customer_name'], $search) !== false || 
               stripos($order['id'], $search) !== false ||
               stripos($order['cylinder_type'], $search) !== false;
    });
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Delivery Operations | LIAA LPG Admin</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <style>
    :root {
      --primary: #ff6b00;
      --primary-dark: #e05d00;
      --primary-light: #ff8533;
      --secondary: #e05d00;
      --accent: #ff8533;
      --flame-orange: #ff6b00;
      --flame-red: #ff4500;
      --deep-navy: #1a1f36;
      --slate-blue: #2d3748;
      --soft-gray: #e2e8f0;
      --pure-white: #ffffff;
      --success-green: #48bb78;
      --warning-amber: #ed8936;
      --danger-red: #f56565;
      --info-blue: #4299e1;
      --glass-white: rgba(255, 255, 255, 0.1);
      --glass-dark: rgba(26, 31, 54, 0.8);
      --shadow-soft: 0 8px 32px rgba(26, 31, 54, 0.12);
      --shadow-strong: 0 20px 60px rgba(26, 31, 54, 0.25);
      --border-radius: 20px;
      --transition: all 0.4s cubic-bezier(0.25, 0.46, 0.45, 0.94);
    }

    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
      font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
    }

    body {
      background: linear-gradient(135deg, var(--flame-orange), var(--flame-red));
      min-height: 100vh;
      color: var(--deep-navy);
      overflow-x: hidden;
    }

    /* Floating particles effect */
    body::before {
      content: '';
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      background: radial-gradient(circle at 20% 20%, rgba(255, 107, 53, 0.3) 0%, transparent 50%),
                  radial-gradient(circle at 80% 80%, rgba(247, 147, 30, 0.3) 0%, transparent 50%),
                  radial-gradient(circle at 60% 30%, rgba(72, 187, 120, 0.2) 0%, transparent 50%);
      pointer-events: none;
      z-index: -1;
    }

    /* Header */
    .header {
      background: var(--glass-white);
      backdrop-filter: blur(20px);
      border-bottom: 1px solid rgba(255, 255, 255, 0.18);
      padding: 1.2rem 0;
      position: sticky;
      top: 0;
      z-index: 1000;
      box-shadow: var(--shadow-soft);
    }

    .header-container {
      max-width: 1400px;
      margin: 0 auto;
      padding: 0 2rem;
      display: flex;
      justify-content: space-between;
      align-items: center;
    }

    .logo {
      font-size: 1.8rem;
      font-weight: 800;
      color: white;
      text-decoration: none;
      display: flex;
      align-items: center;
      gap: 0.8rem;
    }

    .breadcrumb {
      display: flex;
      align-items: center;
      gap: 0.8rem;
      color: rgba(255, 255, 255, 0.8);
      font-size: 0.9rem;
    }

    .breadcrumb a {
      color: var(--pure-white);
      text-decoration: none;
      display: flex;
      align-items: center;
      gap: 0.5rem;
      transition: var(--transition);
    }

    .breadcrumb a:hover {
      color: var(--flame-orange);
    }

    /* Main Container */
    .container {
      max-width: 1400px;
      margin: 0 auto;
      padding: 2rem;
    }

    .page-title {
      color: var(--pure-white);
      font-size: 2.5rem;
      font-weight: 900;
      margin-bottom: 0.5rem;
      display: flex;
      align-items: center;
      gap: 1rem;
    }

    .page-subtitle {
      color: rgba(255, 255, 255, 0.8);
      font-size: 1.1rem;
      margin-bottom: 3rem;
      font-weight: 400;
    }

    /* Alert Messages */
    .alert {
      padding: 1rem 1.5rem;
      border-radius: 16px;
      margin-bottom: 1.5rem;
      font-weight: 600;
      backdrop-filter: blur(10px);
      border: 1px solid;
      display: flex;
      align-items: center;
      gap: 0.8rem;
    }

    .alert-success {
      background: rgba(72, 187, 120, 0.15);
      color: #48bb78;
      border-color: rgba(72, 187, 120, 0.3);
    }

    .alert-error {
      background: rgba(245, 101, 101, 0.15);
      color: #f56565;
      border-color: rgba(245, 101, 101, 0.3);
    }

    /* Statistics Grid */
    .stats-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
      gap: 1.5rem;
      margin-bottom: 3rem;
    }

    .stat-card {
      background: var(--glass-white);
      backdrop-filter: blur(25px);
      padding: 2rem;
      border-radius: 20px;
      border: 1px solid rgba(255, 255, 255, 0.18);
      text-align: center;
      transition: var(--transition);
      position: relative;
      overflow: hidden;
      box-shadow: var(--shadow-soft);
    }

    .stat-card:hover {
      transform: translateY(-5px) scale(1.02);
      box-shadow: var(--shadow-strong);
    }

    .stat-icon {
      width: 60px;
      height: 60px;
      margin: 0 auto 1rem;
      border-radius: 16px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.5rem;
      color: white;
      transition: var(--transition);
    }

    .stat-card:hover .stat-icon {
      transform: scale(1.1) rotate(5deg);
    }

    .stat-number {
      font-size: 2.2rem;
      font-weight: 900;
      color: var(--pure-white);
      margin-bottom: 0.5rem;
      line-height: 1;
    }

    .stat-label {
      color: rgba(255, 255, 255, 0.8);
      font-weight: 600;
      text-transform: uppercase;
      font-size: 0.8rem;
      letter-spacing: 1px;
    }

    /* Action Bar */
    .action-bar {
      background: var(--glass-white);
      backdrop-filter: blur(25px);
      padding: 1.5rem;
      border-radius: 20px;
      border: 1px solid rgba(255, 255, 255, 0.18);
      margin-bottom: 2rem;
      display: flex;
      justify-content: space-between;
      align-items: center;
      flex-wrap: wrap;
      gap: 1rem;
    }

    .bulk-actions {
      display: flex;
      align-items: center;
      gap: 1rem;
    }

    .quick-actions {
      display: flex;
      gap: 1rem;
    }

    /* Filters */
    .filters-section {
      background: var(--glass-white);
      backdrop-filter: blur(25px);
      padding: 2rem;
      border-radius: 20px;
      border: 1px solid rgba(255, 255, 255, 0.18);
      margin-bottom: 2rem;
      box-shadow: var(--shadow-soft);
    }

    .filters-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
      gap: 1.5rem;
      align-items: end;
    }

    .filter-group {
      display: flex;
      flex-direction: column;
      gap: 0.5rem;
    }

    .filter-group label {
      color: var(--pure-white);
      font-weight: 600;
      font-size: 0.9rem;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }

    .filter-group input,
    .filter-group select {
      padding: 0.8rem 1rem;
      background: rgba(255, 255, 255, 0.1);
      border: 2px solid rgba(255, 255, 255, 0.2);
      border-radius: 12px;
      color: var(--pure-white);
      font-size: 0.9rem;
      transition: var(--transition);
      backdrop-filter: blur(10px);
    }

    .filter-group input::placeholder {
      color: rgba(255, 255, 255, 0.6);
    }

    .filter-group input:focus,
    .filter-group select:focus {
      outline: none;
      border-color: var(--flame-orange);
      background: rgba(255, 255, 255, 0.15);
      box-shadow: 0 0 0 4px rgba(255, 107, 53, 0.2);
    }

    .filter-group select option {
      background: var(--deep-navy);
      color: var(--pure-white);
    }

    /* Buttons */
    .btn {
      display: inline-flex;
      align-items: center;
      gap: 0.5rem;
      padding: 0.8rem 1.5rem;
      border: none;
      border-radius: 12px;
      text-decoration: none;
      font-weight: 600;
      font-size: 0.9rem;
      transition: var(--transition);
      cursor: pointer;
      position: relative;
      overflow: hidden;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }

    .btn-sm {
      padding: 0.5rem 1rem;
      font-size: 0.8rem;
    }

    .btn-primary {
      background: linear-gradient(135deg, var(--flame-orange), var(--flame-red));
      color: white;
      box-shadow: 0 4px 15px rgba(255, 107, 53, 0.3);
    }

    .btn-success {
      background: linear-gradient(135deg, var(--success-green), #38a169);
      color: white;
      box-shadow: 0 4px 15px rgba(72, 187, 120, 0.3);
    }

    .btn-warning {
      background: linear-gradient(135deg, var(--warning-amber), #dd6b20);
      color: white;
      box-shadow: 0 4px 15px rgba(237, 137, 54, 0.3);
    }

    .btn-secondary {
      background: linear-gradient(135deg, var(--info-blue), #3182ce);
      color: white;
      box-shadow: 0 4px 15px rgba(66, 153, 225, 0.3);
    }

    .btn:hover {
      transform: translateY(-2px);
      box-shadow: 0 8px 25px rgba(0,0,0,0.2);
    }

    /* Operations Table */
    .operations-container {
      background: var(--glass-white);
      backdrop-filter: blur(25px);
      padding: 2rem;
      border-radius: 20px;
      border: 1px solid rgba(255, 255, 255, 0.18);
      box-shadow: var(--shadow-soft);
    }

    .table-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 1.5rem;
      flex-wrap: wrap;
      gap: 1rem;
    }

    .table-header h3 {
      color: var(--pure-white);
      font-size: 1.4rem;
      font-weight: 700;
      display: flex;
      align-items: center;
      gap: 0.8rem;
    }

    .operations-table {
      width: 100%;
      border-collapse: collapse;
      margin-top: 1rem;
    }

    .operations-table th {
      background: rgba(255, 255, 255, 0.05);
      color: var(--pure-white);
      padding: 1rem;
      text-align: left;
      font-weight: 700;
      font-size: 0.8rem;
      text-transform: uppercase;
      letter-spacing: 1px;
      border-bottom: 2px solid rgba(255, 255, 255, 0.1);
    }

    .operations-table td {
      padding: 1rem;
      border-bottom: 1px solid rgba(255, 255, 255, 0.05);
      color: rgba(255, 255, 255, 0.9);
      vertical-align: top;
    }

    .operations-table tbody tr {
      transition: var(--transition);
    }

    .operations-table tbody tr:hover {
      background: rgba(255, 255, 255, 0.05);
    }

    /* Status Badges */
    .status-badge {
      padding: 0.4rem 0.8rem;
      border-radius: 12px;
      font-size: 0.75rem;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      display: inline-block;
    }

    .status-booked {
      background: rgba(66, 153, 225, 0.2);
      color: #4299e1;
      border: 1px solid rgba(66, 153, 225, 0.3);
    }

    .status-processing {
      background: rgba(237, 137, 54, 0.2);
      color: #ed8936;
      border: 1px solid rgba(237, 137, 54, 0.3);
    }

    .status-out-for-delivery {
      background: rgba(159, 122, 234, 0.2);
      color: #9f7aea;
      border: 1px solid rgba(159, 122, 234, 0.3);
    }

    /* Priority Badges */
    .priority-badge {
      padding: 0.3rem 0.6rem;
      border-radius: 8px;
      font-size: 0.7rem;
      font-weight: 600;
      text-transform: uppercase;
    }

    .priority-urgent {
      background: rgba(245, 101, 101, 0.2);
      color: #f56565;
    }

    .priority-high {
      background: rgba(237, 137, 54, 0.2);
      color: #ed8936;
    }

    .priority-normal {
      background: rgba(72, 187, 120, 0.2);
      color: #48bb78;
    }

    /* Modals */
    .modal-overlay {
      display: none;
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      background: rgba(0, 0, 0, 0.8);
      backdrop-filter: blur(5px);
      z-index: 9999;
      align-items: center;
      justify-content: center;
    }

    .modal {
      background: var(--glass-white);
      backdrop-filter: blur(25px);
      padding: 2.5rem;
      border-radius: 24px;
      border: 1px solid rgba(255, 255, 255, 0.18);
      box-shadow: var(--shadow-strong);
      width: 90%;
      max-width: 500px;
      max-height: 90vh;
      overflow-y: auto;
    }

    .modal h3 {
      color: var(--pure-white);
      margin-bottom: 1.5rem;
      font-size: 1.4rem;
      font-weight: 700;
      display: flex;
      align-items: center;
      gap: 0.8rem;
    }

    .form-group {
      margin-bottom: 1.5rem;
    }

    .form-group label {
      display: block;
      margin-bottom: 0.5rem;
      color: var(--pure-white);
      font-weight: 600;
      font-size: 0.9rem;
    }

    .form-group input,
    .form-group select,
    .form-group textarea {
      width: 100%;
      padding: 0.8rem 1rem;
      background: rgba(255, 255, 255, 0.1);
      border: 2px solid rgba(255, 255, 255, 0.2);
      border-radius: 12px;
      color: var(--pure-white);
      font-size: 0.9rem;
      transition: var(--transition);
      backdrop-filter: blur(10px);
    }

    .form-group textarea {
      resize: vertical;
      min-height: 100px;
    }

    .form-group input:focus,
    .form-group select:focus,
    .form-group textarea:focus {
      outline: none;
      border-color: var(--flame-orange);
      background: rgba(255, 255, 255, 0.15);
      box-shadow: 0 0 0 4px rgba(255, 107, 53, 0.2);
    }

    .modal-actions {
      display: flex;
      gap: 1rem;
      justify-content: flex-end;
      margin-top: 2rem;
    }

    /* Empty State */
    .empty-state {
      text-align: center;
      padding: 4rem 2rem;
      color: rgba(255, 255, 255, 0.7);
    }

    .empty-state i {
      font-size: 4rem;
      margin-bottom: 1rem;
      opacity: 0.5;
    }

    .empty-state h3 {
      font-size: 1.5rem;
      margin-bottom: 0.5rem;
      color: var(--pure-white);
    }

    /* Responsive Design */
    @media (max-width: 768px) {
      .container {
        padding: 1rem;
      }

      .header-container {
        flex-direction: column;
        gap: 1rem;
      }

      .stats-grid {
        grid-template-columns: 1fr;
      }

      .filters-grid {
        grid-template-columns: 1fr;
      }

      .action-bar {
        flex-direction: column;
        align-items: stretch;
      }

      .table-header {
        flex-direction: column;
        align-items: flex-start;
      }

      .operations-table {
        font-size: 0.8rem;
      }

      .operations-table th,
      .operations-table td {
        padding: 0.5rem;
      }
    }
  </style>
</head>
<body>
  <!-- Header -->
  <div class="header">
    <div class="header-container">
      <a href="admin-portal.php" class="logo">
        <i class="fas fa-fire"></i>
        LIAA LPG
      </a>
      <div class="breadcrumb">
        <a href="admin-portal.php">
          <i class="fas fa-tachometer-alt"></i>
          Dashboard
        </a>
        <i class="fas fa-chevron-right"></i>
        <span>Delivery Operations</span>
      </div>
    </div>
  </div>

  <div class="container">
    <!-- Page Title -->
    <h1 class="page-title">
      <i class="fas fa-truck-loading"></i>
      Delivery Operations Hub
    </h1>
    <p class="page-subtitle">
      Coordinate deliveries, assign staff, optimize routes, and monitor real-time operations
    </p>

    <!-- Alert Messages -->
    <?php if ($message): ?>
      <div class="alert alert-success">
        <i class="fas fa-check-circle"></i>
        <?php echo htmlspecialchars($message); ?>
      </div>
    <?php endif; ?>
    
    <?php if ($error): ?>
      <div class="alert alert-error">
        <i class="fas fa-exclamation-circle"></i>
        <?php echo htmlspecialchars($error); ?>
      </div>
    <?php endif; ?>

    <!-- Operations Statistics -->
    <div class="stats-grid">
      <div class="stat-card">
        <div class="stat-icon" style="background: linear-gradient(135deg, var(--info-blue), #3182ce);">
          <i class="fas fa-shipping-fast"></i>
        </div>
        <div class="stat-number"><?php echo $operations_stats['active_deliveries']; ?></div>
        <div class="stat-label">Active Deliveries</div>
      </div>
      
      <div class="stat-card">
        <div class="stat-icon" style="background: linear-gradient(135deg, var(--warning-amber), #dd6b20);">
          <i class="fas fa-exclamation-triangle"></i>
        </div>
        <div class="stat-number"><?php echo $operations_stats['unassigned_orders']; ?></div>
        <div class="stat-label">Unassigned Orders</div>
      </div>
      
      <div class="stat-card">
        <div class="stat-icon" style="background: linear-gradient(135deg, var(--success-green), #38a169);">
          <i class="fas fa-user-tie"></i>
        </div>
        <div class="stat-number"><?php echo $operations_stats['available_staff']; ?></div>
        <div class="stat-label">Available Staff</div>
      </div>
      
      <div class="stat-card">
        <div class="stat-icon" style="background: linear-gradient(135deg, var(--flame-orange), var(--flame-red));">
          <i class="fas fa-check-circle"></i>
        </div>
        <div class="stat-number"><?php echo $operations_stats['completed_today']; ?></div>
        <div class="stat-label">Completed Today</div>
      </div>
      
      <div class="stat-card">
        <div class="stat-icon" style="background: linear-gradient(135deg, #9f7aea, #805ad5);">
          <i class="fas fa-stopwatch"></i>
        </div>
        <div class="stat-number"><?php echo $operations_stats['average_delivery_time']; ?></div>
        <div class="stat-label">Avg Time</div>
      </div>
      
      <div class="stat-card">
        <div class="stat-icon" style="background: linear-gradient(135deg, #38b2ac, #319795);">
          <i class="fas fa-trophy"></i>
        </div>
        <div class="stat-number"><?php echo $operations_stats['efficiency_rate']; ?></div>
        <div class="stat-label">Efficiency</div>
      </div>
    </div>

    <!-- Action Bar -->
    <div class="action-bar">
      <div class="bulk-actions">
        <form method="POST" id="bulkForm" style="display: flex; align-items: center; gap: 1rem;">
          <input type="hidden" name="selected_orders_bulk" id="selectedOrdersBulk">
          <div style="display: flex; align-items: center; gap: 0.5rem;">
            <span id="selectedCount" style="color: rgba(255, 255, 255, 0.8); font-size: 0.9rem;">0 selected</span>
          </div>
          <select name="bulk_staff_id" required>
            <option value="">Select Staff for Bulk Assignment</option>
            <?php foreach ($delivery_staff as $staff): ?>
              <option value="<?php echo $staff['id']; ?>"><?php echo htmlspecialchars($staff['name']); ?></option>
            <?php endforeach; ?>
          </select>
          <button type="button" onclick="performBulkAssign()" class="btn btn-warning">
            <i class="fas fa-users"></i>
            Bulk Assign
          </button>
        </form>
      </div>
      
      <div class="quick-actions">
        <form method="POST" style="display: inline;">
          <button type="submit" name="optimize_routes" class="btn btn-success"
                  onclick="return confirm('This will optimize delivery routes for all assigned orders. Continue?')">
            <i class="fas fa-route"></i>
            Optimize Routes
          </button>
        </form>
        <button onclick="refreshOperations()" class="btn btn-secondary">
          <i class="fas fa-sync-alt"></i>
          Refresh
        </button>
      </div>
    </div>

    <!-- Filters Section -->
    <div class="filters-section">
      <form method="GET" class="filters-grid">
        <div class="filter-group">
          <label for="search">Search Operations</label>
          <input type="text" 
                 id="search" 
                 name="search" 
                 value="<?php echo htmlspecialchars($search); ?>"
                 placeholder="Order ID, Customer name">
        </div>
        
        <div class="filter-group">
          <label for="filter_status">Status Filter</label>
          <select id="filter_status" name="filter_status">
            <option value="all" <?php echo $filter_status === 'all' ? 'selected' : ''; ?>>All Statuses</option>
            <option value="booked" <?php echo $filter_status === 'booked' ? 'selected' : ''; ?>>Booked</option>
            <option value="processing" <?php echo $filter_status === 'processing' ? 'selected' : ''; ?>>Processing</option>
            <option value="out for delivery" <?php echo $filter_status === 'out for delivery' ? 'selected' : ''; ?>>Out for Delivery</option>
          </select>
        </div>
        
        <div class="filter-group">
          <label for="filter_staff">Staff Filter</label>
          <select id="filter_staff" name="filter_staff">
            <option value="all" <?php echo $filter_staff === 'all' ? 'selected' : ''; ?>>All Staff</option>
            <option value="unassigned" <?php echo $filter_staff === 'unassigned' ? 'selected' : ''; ?>>Unassigned</option>
            <?php foreach ($delivery_staff as $staff): ?>
              <option value="<?php echo $staff['id']; ?>" <?php echo $filter_staff == $staff['id'] ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($staff['name']); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        
        <div class="filter-group">
          <label>&nbsp;</label>
          <button type="submit" class="btn btn-primary">
            <i class="fas fa-filter"></i>
            Apply Filters
          </button>
        </div>
      </form>
    </div>

    <!-- Operations Table -->
    <div class="operations-container">
      <div class="table-header">
        <h3>
          <i class="fas fa-clipboard-list"></i>
          Active Operations
          <span style="background: rgba(255, 255, 255, 0.1); padding: 0.3rem 0.8rem; border-radius: 12px; font-size: 0.8rem; margin-left: 1rem;">
            <?php echo count($filtered_orders); ?> orders
          </span>
        </h3>
        
        <div style="display: flex; gap: 1rem;">
          <button onclick="exportOperations()" class="btn btn-secondary btn-sm">
            <i class="fas fa-download"></i>
            Export
          </button>
        </div>
      </div>
      
      <?php if (empty($filtered_orders)): ?>
        <div class="empty-state">
          <i class="fas fa-clipboard-list"></i>
          <h3>No active operations</h3>
          <p>All orders are either completed or no orders match your current filters.</p>
        </div>
      <?php else: ?>
        <form id="operationsForm">
          <table class="operations-table">
            <thead>
              <tr>
                <th>
                  <input type="checkbox" id="selectAll" onchange="toggleAllCheckboxes()">
                </th>
                <th>Order</th>
                <th>Customer Details</th>
                <th>Product Info</th>
                <th>Delivery Address</th>
                <th>Assigned Staff</th>
                <th>Status</th>
                <th>Priority</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($filtered_orders as $order): 
                $priority = 'normal';
                $order_age = (time() - strtotime($order['created_at'])) / 3600; // hours
                if ($order_age > 24) $priority = 'urgent';
                elseif ($order_age > 12) $priority = 'high';
              ?>
                <tr>
                  <td>
                    <input type="checkbox" name="selected_orders[]" value="<?php echo $order['id']; ?>" class="order-checkbox">
                  </td>
                  <td>
                    <div>
                      <strong>#<?php echo $order['id']; ?></strong>
                      <br><small style="opacity: 0.7;"><?php echo date('M j, g:i A', strtotime($order['created_at'])); ?></small>
                    </div>
                  </td>
                  <td>
                    <div>
                      <strong><?php echo htmlspecialchars($order['customer_name'] ?? 'Unknown'); ?></strong>
                      <?php if ($order['customer_phone']): ?>
                        <br><small style="opacity: 0.7;"><i class="fas fa-phone"></i> <?php echo htmlspecialchars($order['customer_phone']); ?></small>
                      <?php endif; ?>
                    </div>
                  </td>
                  <td>
                    <div>
                      <strong><?php echo htmlspecialchars($order['cylinder_type']); ?></strong>
                      <br><small><?php echo $order['quantity']; ?> units</small>
                    </div>
                  </td>
                  <td>
                    <div style="max-width: 200px;">
                      <span title="<?php echo htmlspecialchars($order['customer_address'] ?? $order['delivery_address']); ?>">
                        <i class="fas fa-map-marker-alt"></i>
                        <?php echo htmlspecialchars(substr($order['customer_address'] ?? $order['delivery_address'], 0, 30)); ?>...
                      </span>
                    </div>
                  </td>
                  <td>
                    <?php if ($order['delivery_staff_name']): ?>
                      <div>
                        <strong><?php echo htmlspecialchars($order['delivery_staff_name']); ?></strong>
                        <?php if ($order['delivery_staff_phone']): ?>
                          <br><small style="opacity: 0.7;"><i class="fas fa-phone"></i> <?php echo htmlspecialchars($order['delivery_staff_phone']); ?></small>
                        <?php endif; ?>
                      </div>
                    <?php else: ?>
                      <span style="opacity: 0.6; font-style: italic;">Unassigned</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <span class="status-badge status-<?php echo strtolower(str_replace(' ', '-', $order['status'])); ?>">
                      <?php echo $order['status']; ?>
                    </span>
                  </td>
                  <td>
                    <span class="priority-badge priority-<?php echo $priority; ?>">
                      <?php echo ucfirst($priority); ?>
                    </span>
                  </td>
                  <td>
                    <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                      <button type="button" 
                              onclick="assignDelivery(<?php echo $order['id']; ?>)" 
                              class="btn btn-primary btn-sm"
                              title="Assign/Reassign Staff">
                        <i class="fas fa-user-plus"></i>
                      </button>
                      
                      <button type="button" 
                              onclick="updateRoute(<?php echo $order['id']; ?>)" 
                              class="btn btn-secondary btn-sm"
                              title="Update Route">
                        <i class="fas fa-route"></i>
                      </button>
                      
                      <button type="button" 
                              onclick="viewOnMap(<?php echo $order['id']; ?>)" 
                              class="btn btn-success btn-sm"
                              title="View on Map">
                        <i class="fas fa-map"></i>
                      </button>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <!-- Assign Delivery Modal -->
  <div id="assignModal" class="modal-overlay">
    <div class="modal">
      <h3><i class="fas fa-user-plus"></i> Assign Delivery Staff</h3>
      
      <form method="POST" id="assignForm">
        <input type="hidden" name="order_id" id="assignOrderId">
        
        <div class="form-group">
          <label for="assignStaff">Select Delivery Staff</label>
          <select id="assignStaff" name="delivery_staff_id" required>
            <option value="">Choose delivery personnel...</option>
            <?php foreach ($delivery_staff as $staff): ?>
              <option value="<?php echo $staff['id']; ?>">
                <?php echo htmlspecialchars($staff['name']); ?>
                <?php if ($staff['phone']): ?>
                  - <?php echo htmlspecialchars($staff['phone']); ?>
                <?php endif; ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        
        <div class="modal-actions">
          <button type="button" onclick="closeModal('assignModal')" class="btn btn-secondary">
            <i class="fas fa-times"></i>
            Cancel
          </button>
          <button type="submit" name="assign_delivery" class="btn btn-primary">
            <i class="fas fa-check"></i>
            Assign Staff
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- Route Update Modal -->
  <div id="routeModal" class="modal-overlay">
    <div class="modal">
      <h3><i class="fas fa-route"></i> Update Route Information</h3>
      
      <form method="POST" id="routeForm">
        <input type="hidden" name="order_id" id="routeOrderId">
        
        <div class="form-group">
          <label for="routeNotes">Route Notes</label>
          <textarea id="routeNotes" 
                    name="route_notes" 
                    placeholder="Add route instructions, traffic updates, or special delivery notes..."></textarea>
        </div>
        
        <div class="modal-actions">
          <button type="button" onclick="closeModal('routeModal')" class="btn btn-secondary">
            <i class="fas fa-times"></i>
            Cancel
          </button>
          <button type="submit" name="update_route" class="btn btn-primary">
            <i class="fas fa-save"></i>
            Update Route
          </button>
        </div>
      </form>
    </div>
  </div>

  <script>
    function assignDelivery(orderId) {
      document.getElementById('assignOrderId').value = orderId;
      document.getElementById('assignModal').style.display = 'flex';
    }

    function updateRoute(orderId) {
      document.getElementById('routeOrderId').value = orderId;
      document.getElementById('routeModal').style.display = 'flex';
    }

    function viewOnMap(orderId) {
      // Implement map view functionality
      alert('Map view for Order #' + orderId + ' will be implemented here');
    }

    function closeModal(modalId) {
      document.getElementById(modalId).style.display = 'none';
    }

    function toggleAllCheckboxes() {
      const selectAll = document.getElementById('selectAll');
      const checkboxes = document.querySelectorAll('.order-checkbox');
      
      checkboxes.forEach(checkbox => {
        checkbox.checked = selectAll.checked;
      });
      
      updateSelectedCount();
    }

    function updateSelectedCount() {
      const selectedCheckboxes = document.querySelectorAll('.order-checkbox:checked');
      const count = selectedCheckboxes.length;
      const countElement = document.getElementById('selectedCount');
      
      if (countElement) {
        countElement.textContent = `${count} selected`;
        countElement.style.color = count > 0 ? '#48bb78' : 'rgba(255, 255, 255, 0.8)';
      }
    }

    function performBulkAssign() {
      // Get all selected order checkboxes
      const selectedCheckboxes = document.querySelectorAll('.order-checkbox:checked');
      const staffSelect = document.querySelector('select[name="bulk_staff_id"]');
      
      if (selectedCheckboxes.length === 0) {
        alert('Please select at least one order for bulk assignment.');
        return;
      }
      
      if (!staffSelect.value) {
        alert('Please select a staff member for bulk assignment.');
        return;
      }
      
      // Get selected order IDs
      const selectedOrderIds = Array.from(selectedCheckboxes).map(cb => cb.value);
      
      // Confirm the action
      const staffName = staffSelect.options[staffSelect.selectedIndex].text;
      const confirmMessage = `Assign ${selectedOrderIds.length} order(s) to ${staffName}?`;
      
      if (confirm(confirmMessage)) {
        // Set the hidden input with selected order IDs
        document.getElementById('selectedOrdersBulk').value = selectedOrderIds.join(',');
        
        // Add the bulk_assign field and submit
        const bulkForm = document.getElementById('bulkForm');
        const hiddenInput = document.createElement('input');
        hiddenInput.type = 'hidden';
        hiddenInput.name = 'bulk_assign';
        hiddenInput.value = '1';
        bulkForm.appendChild(hiddenInput);
        
        bulkForm.submit();
      }
    }

    function optimizeRoutes() {
      // This function is now handled by the server-side form submission
      // The actual optimization logic is implemented in PHP
      console.log('Route optimization triggered via form submission');
    }

    function refreshOperations() {
      window.location.reload();
    }

    function exportOperations() {
      alert('Export functionality will be implemented here');
    }

    // Close modal when clicking outside
    window.onclick = function(event) {
      const modals = document.querySelectorAll('.modal-overlay');
      modals.forEach(modal => {
        if (event.target === modal) {
          modal.style.display = 'none';
        }
      });
    }

    // Enhanced interactions
    document.addEventListener('DOMContentLoaded', function() {
      // Initialize selected count
      updateSelectedCount();
      
      // Add event listeners to all order checkboxes
      document.querySelectorAll('.order-checkbox').forEach(checkbox => {
        checkbox.addEventListener('change', updateSelectedCount);
      });
      
      // Auto-refresh every 60 seconds
      setInterval(function() {
        const modals = document.querySelectorAll('.modal-overlay');
        const anyModalOpen = Array.from(modals).some(modal => 
          modal.style.display === 'flex'
        );
        
        if (!anyModalOpen) {
          console.log('Auto-refresh check at ' + new Date().toLocaleTimeString());
          // Uncomment the next line to enable auto-refresh
          // window.location.reload();
        }
      }, 60000);

      // Add keyboard shortcuts
      document.addEventListener('keydown', function(e) {
        if (e.ctrlKey && e.key === 'r') {
          e.preventDefault();
          refreshOperations();
        }
        if (e.key === 'Escape') {
          document.querySelectorAll('.modal-overlay').forEach(modal => {
            modal.style.display = 'none';
          });
        }
      });
    });
  </script>
</body>
</html>
