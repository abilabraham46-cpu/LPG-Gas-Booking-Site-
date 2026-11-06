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

// Handle status update
if (isset($_POST['update_status'])) {
  $order_id = intval($_POST['order_id']);
  $new_status_raw = $_POST['status'] ?? '';
  $normalized_status = strtolower(str_replace(' ', '_', trim($new_status_raw)));

  $allowed_statuses = ['pending', 'confirmed', 'processing', 'out_for_delivery', 'delivered', 'cancelled'];

  if (!in_array($normalized_status, $allowed_statuses, true)) {
    $error = 'Invalid status value selected.';
  } else {
    $precheckPassed = true;

    try {
      $currentOrder = executeQuery("SELECT status FROM orders WHERE id = ?", [$order_id])->fetch();
      if (!$currentOrder) {
        throw new Exception('Order not found.');
      }

      $currentStatus = strtolower(str_replace(' ', '_', trim($currentOrder['status'] ?? '')));

      if ($currentStatus === 'delivered') {
        throw new Exception('Order has already been delivered and cannot be modified.');
      }

      if ($currentStatus === $normalized_status) {
        throw new Exception('Order is already set to this status.');
      }
    } catch (Exception $precheckError) {
      $error = $precheckError->getMessage();
      $precheckPassed = false;
    }

    if ($precheckPassed) {
      try {
        // Use the Orders class to properly update status and create notifications
        require_once 'includes/orders.php';
        $orders = new OrderManager();

        $result = $orders->updateOrderStatus($order_id, $normalized_status, null, $_SESSION['user_id'] ?? null);

        if ($result) {
          $message = "Order status updated successfully! Customer has been notified.";
        } else {
          throw new Exception('Failed to update order status.');
        }
      } catch (Exception $e) {
        $error = "Error updating order status: " . $e->getMessage();
      }
    }
  }
}

// Fetch delivery statistics
$stats = [
    'total_deliveries' => 0,
    'pending_deliveries' => 0,
    'completed_today' => 0,
    'in_progress' => 0,
    'average_delivery_time' => '0 hrs',
    'on_time_rate' => '0%'
];

try {
  $statsQuery = "SELECT 
      COUNT(*) AS total_deliveries,
      SUM(CASE WHEN status IN ('pending','confirmed','processing','out_for_delivery') THEN 1 ELSE 0 END) AS pending_deliveries,
      SUM(CASE WHEN status = 'delivered' AND DATE(created_at) = CURDATE() THEN 1 ELSE 0 END) AS completed_today,
      SUM(CASE WHEN status IN ('processing','out_for_delivery') THEN 1 ELSE 0 END) AS in_progress
    FROM orders";

  $statsResult = executeQuery($statsQuery)->fetch();

  if ($statsResult) {
    $stats['total_deliveries'] = (int)($statsResult['total_deliveries'] ?? 0);
    $stats['pending_deliveries'] = (int)($statsResult['pending_deliveries'] ?? 0);
    $stats['completed_today'] = (int)($statsResult['completed_today'] ?? 0);
    $stats['in_progress'] = (int)($statsResult['in_progress'] ?? 0);
  }
} catch (Exception $e) {
  $error = "Error fetching statistics: " . $e->getMessage();
}
$orders = [];
try {
  // Check if the column exists, otherwise remove it from the query
  $orders_sql = "SELECT 
    o.*,
    c.name AS customer_name,
    c.phone AS customer_phone,
    c.address AS customer_address,
    d.delivery_person_id,
    d.status AS delivery_status,
    ds.name AS delivery_staff_name
    FROM orders o
    LEFT JOIN users c ON o.customer_id = c.id
    LEFT JOIN deliveries d ON d.order_id = o.id
    LEFT JOIN users ds ON d.delivery_person_id = ds.id
    ORDER BY o.created_at DESC";
  $orders_stmt = executeQuery($orders_sql);
  $orders = $orders_stmt->fetchAll();
} catch (Exception $e) {
  $error = "Error fetching orders: " . $e->getMessage();
}

// Fetch delivery staff for assignment
$delivery_staff = [];
try {
    $staff_sql = "SELECT id, name FROM users WHERE role = 'delivery' ORDER BY name";
    $staff_stmt = executeQuery($staff_sql);
    $delivery_staff = $staff_stmt->fetchAll();
} catch (Exception $e) {
    // Handle error silently
}

// Get filter parameters
$filter_status = $_GET['filter_status'] ?? 'all';
$filter_date = $_GET['filter_date'] ?? '';
$search = $_GET['search'] ?? '';

// Apply filters
$filtered_orders = $orders;
if ($filter_status !== 'all') {
  $filtered_orders = array_filter($filtered_orders, function($order) use ($filter_status) {
    $normalized = strtolower(str_replace(' ', '_', trim($order['status'] ?? '')));
    return $normalized === $filter_status;
  });
}

if ($search) {
    $filtered_orders = array_filter($filtered_orders, function($order) use ($search) {
        return stripos($order['customer_name'], $search) !== false || 
               stripos($order['id'], $search) !== false ||
               stripos($order['cylinder_type'], $search) !== false;
    });
}

if ($filter_date) {
    $filtered_orders = array_filter($filtered_orders, function($order) use ($filter_date) {
        return date('Y-m-d', strtotime($order['created_at'])) === $filter_date;
    });
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Delivery Analytics & Tracking | LIAA LPG Admin</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <style>
    :root {
      --primary: #fe0808ff;
      --primary-dark: #e05d00;
      --primary-light: #ff8533;
      --secondary: #f40404ff;
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
      color: #ffffff;
      text-decoration: none;
      display: flex;
      align-items: center;
      gap: 0.8rem;
      text-shadow: 0 2px 8px rgba(0, 0, 0, 0.3);
      transition: var(--transition);
    }

    .logo:hover {
      color: #ffe600;
      text-shadow: 0 2px 12px rgba(255, 230, 0, 0.5);
    }

    .breadcrumb {
      display: flex;
      align-items: center;
      gap: 1rem;
      color: var(--pure-white);
      font-weight: 600;
    }

    .breadcrumb a {
      color: var(--pure-white);
      text-decoration: none;
      opacity: 0.8;
      transition: var(--transition);
    }

    .breadcrumb a:hover {
      opacity: 1;
      color: var(--flame-orange);
    }

    /* Main Container */
    .container {
      max-width: 1400px;
      margin: 0 auto;
      padding: 2rem;
    }

    .page-title {
      text-align: center;
      color: var(--pure-white);
      font-size: 2.8rem;
      font-weight: 900;
      margin-bottom: 1rem;
      text-shadow: 0 4px 8px rgba(0,0,0,0.3);
    }

    .page-subtitle {
      text-align: center;
      color: rgba(255, 255, 255, 0.8);
      font-size: 1.1rem;
      margin-bottom: 3rem;
      font-weight: 400;
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
      box-shadow: var(--shadow-soft);
      position: relative;
      overflow: hidden;
    }

    .stat-card::before {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      height: 4px;
      background: var(--gradient);
      transition: var(--transition);
    }

    .stat-card.deliveries::before { background: linear-gradient(90deg, var(--flame-orange), var(--flame-red)); }
    .stat-card.pending::before { background: linear-gradient(90deg, var(--warning-amber), #dd6b20); }
    .stat-card.completed::before { background: linear-gradient(90deg, var(--success-green), #38a169); }
    .stat-card.progress::before { background: linear-gradient(90deg, var(--info-blue), #3182ce); }
    .stat-card.performance::before { background: linear-gradient(90deg, #9f7aea, #805ad5); }

    .stat-card:hover {
      transform: translateY(-5px) scale(1.02);
      box-shadow: var(--shadow-strong);
    }

    .stat-card:hover::before {
      height: 6px;
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
      background: linear-gradient(135deg, var(--info-blue), #3182ce);
      box-shadow: 0 8px 20px rgba(66, 153, 225, 0.3);
    }

    .stat-number {
      font-size: 2.2rem;
      font-weight: 900;
      color: var(--pure-white);
      margin-bottom: 0.5rem;
    }

    .stat-label {
      color: rgba(255, 255, 255, 0.8);
      font-weight: 600;
      font-size: 0.9rem;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }

    /* Filters Section */
    .filters-section {
      background: var(--glass-white);
      backdrop-filter: blur(25px);
      padding: 2rem;
      border-radius: 20px;
      border: 1px solid rgba(255, 255, 255, 0.18);
      box-shadow: var(--shadow-soft);
      margin-bottom: 2rem;
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
    }

    .filter-group input,
    .filter-group select {
      padding: 0.8rem;
      background: rgba(255, 255, 255, 0.1);
      border: 2px solid rgba(255, 255, 255, 0.2);
      border-radius: 12px;
      color: var(--pure-white);
      font-size: 0.9rem;
      transition: var(--transition);
    }

    .filter-group input:focus,
    .filter-group select:focus {
      outline: none;
      border-color: var(--flame-orange);
      background: rgba(255, 255, 255, 0.15);
      box-shadow: 0 0 0 4px rgba(255, 107, 53, 0.2);
    }

    .filter-group input::placeholder {
      color: rgba(255, 255, 255, 0.6);
    }

    /* Button Styles */
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
      text-transform: uppercase;
      letter-spacing: 0.5px;
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

    .btn-danger {
      background: linear-gradient(135deg, var(--danger-red), #e53e3e);
      color: white;
      box-shadow: 0 4px 15px rgba(245, 101, 101, 0.3);
    }

    .btn-secondary {
      background: rgba(255, 255, 255, 0.1);
      color: var(--pure-white);
      border: 2px solid rgba(255, 255, 255, 0.3);
    }

    .btn:hover {
      transform: translateY(-2px);
      box-shadow: 0 8px 25px rgba(0,0,0,0.2);
    }

    .btn-sm {
      padding: 0.4rem 0.8rem;
      font-size: 0.8rem;
    }

    /* Table Styles */
    .table-container {
      background: var(--glass-white);
      backdrop-filter: blur(25px);
      border-radius: 20px;
      overflow: hidden;
      border: 1px solid rgba(255, 255, 255, 0.18);
      box-shadow: var(--shadow-soft);
    }

    .table-header {
      padding: 1.5rem 2rem;
      border-bottom: 1px solid rgba(255, 255, 255, 0.1);
      display: flex;
      justify-content: space-between;
      align-items: center;
    }

    .table-header h3 {
      color: var(--pure-white);
      font-size: 1.3rem;
      font-weight: 700;
      display: flex;
      align-items: center;
      gap: 0.8rem;
    }

    .data-table {
      width: 100%;
      border-collapse: collapse;
    }

    .data-table th {
      background: rgba(255, 255, 255, 0.05);
      color: var(--pure-white);
      padding: 1.2rem;
      text-align: left;
      font-weight: 700;
      font-size: 0.85rem;
      text-transform: uppercase;
      letter-spacing: 1px;
    }

    .data-table td {
      padding: 1.2rem;
      border-bottom: 1px solid rgba(255, 255, 255, 0.05);
      color: rgba(255, 255, 255, 0.9);
    }

    .data-table tbody tr {
      transition: var(--transition);
    }

    .data-table tbody tr:hover {
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

    .status-delivered {
      background: rgba(72, 187, 120, 0.2);
      color: #48bb78;
      border: 1px solid rgba(72, 187, 120, 0.3);
    }

    .status-cancelled {
      background: rgba(245, 101, 101, 0.2);
      color: #f56565;
      border: 1px solid rgba(245, 101, 101, 0.3);
    }

    /* Alert Messages */
    .alert {
      padding: 1rem 1.5rem;
      border-radius: 12px;
      margin-bottom: 2rem;
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

    /* Modal Styles */
    .modal-overlay {
      position: fixed;
      top: 0;
      left: 0;
      right: 0;
      bottom: 0;
      background: rgba(0, 0, 0, 0.7);
      backdrop-filter: blur(5px);
      z-index: 2000;
      display: none;
      align-items: center;
      justify-content: center;
    }

    .modal {
      background: var(--glass-white);
      backdrop-filter: blur(25px);
      border-radius: 20px;
      border: 1px solid rgba(255, 255, 255, 0.18);
      padding: 2rem;
      max-width: 500px;
      width: 90%;
      box-shadow: var(--shadow-strong);
    }

    .modal h3 {
      color: var(--pure-white);
      margin-bottom: 1.5rem;
      font-size: 1.3rem;
    }

    .modal .form-group {
      margin-bottom: 1.5rem;
    }

    .modal .form-group label {
      display: block;
      margin-bottom: 0.5rem;
      color: var(--pure-white);
      font-weight: 600;
      font-size: 0.9rem;
    }

    .modal .form-group select,
    .modal .form-group textarea {
      width: 100%;
      padding: 0.8rem;
      background: rgba(255, 255, 255, 0.1);
      border: 2px solid rgba(255, 255, 255, 0.2);
      border-radius: 12px;
      color: var(--pure-white);
      transition: var(--transition);
    }

    .modal .form-group textarea {
      resize: vertical;
      min-height: 80px;
    }

    .modal .form-group select:focus,
    .modal .form-group textarea:focus {
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

      .table-header {
        flex-direction: column;
        gap: 1rem;
        align-items: flex-start;
      }

      .data-table {
        font-size: 0.8rem;
      }

      .data-table th,
      .data-table td {
        padding: 0.8rem 0.5rem;
      }
    }

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

    .empty-state p {
      font-size: 1rem;
      opacity: 0.8;
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
        <span>Delivery Analytics</span>
      </div>
    </div>
  </div>

  <div class="container">
    <!-- Page Title -->
    <h1 class="page-title">
      <i class="fas fa-chart-line"></i>
      Delivery Analytics & Tracking
    </h1>
    <p class="page-subtitle">
      Monitor delivery performance, track orders in real-time, and optimize logistics operations
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

    <!-- Statistics Grid -->
    <div class="stats-grid">
      <div class="stat-card deliveries">
        <div class="stat-icon" style="background: linear-gradient(135deg, var(--flame-orange), var(--flame-red));">
          <i class="fas fa-truck"></i>
        </div>
        <div class="stat-number"><?php echo $stats['total_deliveries']; ?></div>
        <div class="stat-label">Total Deliveries</div>
      </div>
      
      <div class="stat-card pending">
        <div class="stat-icon" style="background: linear-gradient(135deg, var(--warning-amber), #dd6b20);">
          <i class="fas fa-clock"></i>
        </div>
        <div class="stat-number"><?php echo $stats['pending_deliveries']; ?></div>
        <div class="stat-label">Pending Orders</div>
      </div>
      
      <div class="stat-card completed">
        <div class="stat-icon" style="background: linear-gradient(135deg, var(--success-green), #38a169);">
          <i class="fas fa-check-circle"></i>
        </div>
        <div class="stat-number"><?php echo $stats['completed_today']; ?></div>
        <div class="stat-label">Completed Today</div>
      </div>
      
      <div class="stat-card progress">
        <div class="stat-icon" style="background: linear-gradient(135deg, var(--info-blue), #3182ce);">
          <i class="fas fa-shipping-fast"></i>
        </div>
        <div class="stat-number"><?php echo $stats['in_progress']; ?></div>
        <div class="stat-label">Out for Delivery</div>
      </div>
      
      <div class="stat-card performance">
        <div class="stat-icon" style="background: linear-gradient(135deg, #9f7aea, #805ad5);">
          <i class="fas fa-stopwatch"></i>
        </div>
        <div class="stat-number"><?php echo $stats['average_delivery_time']; ?></div>
        <div class="stat-label">Avg Delivery Time</div>
      </div>
      
      <div class="stat-card performance">
        <div class="stat-icon" style="background: linear-gradient(135deg, #38b2ac, #319795);">
          <i class="fas fa-trophy"></i>
        </div>
        <div class="stat-number"><?php echo $stats['on_time_rate']; ?></div>
        <div class="stat-label">On-Time Rate</div>
      </div>
    </div>

    <!-- Filters Section -->
    <div class="filters-section">
      <form method="GET" class="filters-grid">
        <div class="filter-group">
          <label for="search">Search Orders</label>
          <input type="text" 
                 id="search" 
                 name="search" 
                 value="<?php echo htmlspecialchars($search); ?>"
                 placeholder="Order ID, Customer name, or Product">
        </div>
        
        <div class="filter-group">
          <label for="filter_status">Status Filter</label>
          <select id="filter_status" name="filter_status">
            <option value="all" <?php echo $filter_status === 'all' ? 'selected' : ''; ?>>All Statuses</option>
            <option value="pending" <?php echo $filter_status === 'pending' ? 'selected' : ''; ?>>Pending</option>
            <option value="confirmed" <?php echo $filter_status === 'confirmed' ? 'selected' : ''; ?>>Confirmed</option>
            <option value="processing" <?php echo $filter_status === 'processing' ? 'selected' : ''; ?>>Processing</option>
            <option value="out_for_delivery" <?php echo $filter_status === 'out_for_delivery' ? 'selected' : ''; ?>>Out for Delivery</option>
            <option value="delivered" <?php echo $filter_status === 'delivered' ? 'selected' : ''; ?>>Delivered</option>
            <option value="cancelled" <?php echo $filter_status === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
          </select>
        </div>
        
        <div class="filter-group">
          <label for="filter_date">Date Filter</label>
          <input type="date" 
                 id="filter_date" 
                 name="filter_date" 
                 value="<?php echo htmlspecialchars($filter_date); ?>">
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

    <!-- Orders Table -->
    <div class="table-container">
      <div class="table-header">
        <h3>
          <i class="fas fa-list"></i>
          Delivery Orders
          <span style="background: rgba(255, 255, 255, 0.1); padding: 0.3rem 0.8rem; border-radius: 12px; font-size: 0.8rem; margin-left: 1rem;">
            <?php echo count($filtered_orders); ?> orders
          </span>
        </h3>
        
        <div style="display: flex; gap: 1rem;">
          <button onclick="refreshData()" class="btn btn-primary btn-sm">
            <i class="fas fa-sync-alt"></i>
            Refresh
          </button>
        </div>
      </div>
      
      <?php if (empty($filtered_orders)): ?>
        <div class="empty-state">
          <i class="fas fa-truck"></i>
          <h3>No deliveries found</h3>
          <p>No orders match your current filters. Try adjusting your search criteria.</p>
        </div>
      <?php else: ?>
        <table class="data-table">
          <thead>
            <tr>
              <th>Order ID</th>
              <th>Customer</th>
              <th>Product</th>
              <th>Quantity</th>
              <th>Address</th>
              <th>Delivery Staff</th>
              <th>Status</th>
              <th>Date</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($filtered_orders as $order): ?>
              <tr>
                <td><strong>#<?php echo $order['id']; ?></strong></td>
                <td>
                  <div>
                    <strong><?php echo htmlspecialchars($order['customer_name'] ?? 'Unknown'); ?></strong>
                    <?php if ($order['customer_phone']): ?>
                      <br><small style="opacity: 0.7;"><?php echo htmlspecialchars($order['customer_phone']); ?></small>
                    <?php endif; ?>
                  </div>
                </td>
                <td><?php echo htmlspecialchars($order['cylinder_type']); ?></td>
                <td><?php echo $order['quantity']; ?> units</td>
                <td>
                  <span title="<?php echo htmlspecialchars($order['customer_address'] ?? $order['delivery_address']); ?>">
                    <?php echo htmlspecialchars(substr($order['customer_address'] ?? $order['delivery_address'], 0, 30)); ?>...
                  </span>
                </td>
                <td>
                  <?php if (array_key_exists('delivery_staff_name', $order) && $order['delivery_staff_name']): ?>
                    <strong><?php echo htmlspecialchars($order['delivery_staff_name']); ?></strong>
                  <?php else: ?>
                    <span style="opacity: 0.6;">Not assigned</span>
                  <?php endif; ?>
                </td>
                <td>
                  <span class="status-badge status-<?php echo strtolower(str_replace(' ', '-', $order['status'])); ?>">
                    <?php echo $order['status']; ?>
                  </span>
                </td>
                <td>
                  <div>
                    <?php echo date('M j, Y', strtotime($order['created_at'])); ?>
                    <br><small style="opacity: 0.7;"><?php echo date('g:i A', strtotime($order['created_at'])); ?></small>
                  </div>
                </td>
                <td>
                  <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                    <?php if ($order['status'] !== 'cancelled'): ?>
                        <button onclick="updateOrderStatus(<?php echo $order['id']; ?>, '<?php echo htmlspecialchars($order['status']); ?>', '<?php echo htmlspecialchars($order['delivery_notes'] ?? ''); ?>')" 
                                class="btn btn-primary btn-sm">
                          <i class="fas fa-edit"></i>
                          Update
                        </button>
                    <?php else: ?>
                        <button class="btn btn-secondary btn-sm" disabled title="Cannot update cancelled orders">
                          <i class="fas fa-ban"></i>
                          Cancelled
                        </button>
                    <?php endif; ?>
                    
                    <button onclick="viewOrderDetails(<?php echo $order['id']; ?>)" 
                            class="btn btn-secondary btn-sm">
                      <i class="fas fa-eye"></i>
                      View
                    </button>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </div>

  <!-- Update Status Modal -->
  <div id="statusModal" class="modal-overlay">
    <div class="modal">
      <h3><i class="fas fa-edit"></i> Update Order Status</h3>
      
      <form method="POST" id="statusForm">
        <input type="hidden" name="order_id" id="modalOrderId">
        
        <div class="form-group">
          <label for="modalStatus">Order Status</label>
          <select id="modalStatus" name="status" required>
            <option value="pending">Pending</option>
            <option value="confirmed">Confirmed</option>
            <option value="processing">Processing</option>
            <option value="out_for_delivery">Out for Delivery</option>
            <option value="delivered">Delivered</option>
            <option value="cancelled">Cancelled</option>
          </select>
        </div>
        
        <div class="form-group">
          <label for="modalNotes">Delivery Notes</label>
          <textarea id="modalNotes" 
                    name="delivery_notes" 
                    placeholder="Add any delivery notes or comments..."></textarea>
        </div>
        
        <div class="modal-actions">
          <button type="button" onclick="closeModal()" class="btn btn-secondary">
            <i class="fas fa-times"></i>
            Cancel
          </button>
          <button type="submit" name="update_status" class="btn btn-primary">
            <i class="fas fa-save"></i>
            Update Status
          </button>
        </div>
      </form>
    </div>
  </div>

  <script>
    function updateOrderStatus(orderId, currentStatus, notes) {
      const normalizedStatus = (currentStatus || '')
        .toString()
        .trim()
        .toLowerCase()
        .replace(/\s+/g, '_');

      if (normalizedStatus === 'cancelled') {
        alert('Cannot update status for cancelled orders.');
        return;
      }

      if (normalizedStatus === 'delivered') {
        alert('This order is already delivered and cannot be modified.');
        return;
      }

      document.getElementById('modalOrderId').value = orderId;
      document.getElementById('modalStatus').value = normalizedStatus || 'pending';
      document.getElementById('modalNotes').value = notes;
      document.getElementById('statusModal').style.display = 'flex';
    }

    function closeModal() {
      document.getElementById('statusModal').style.display = 'none';
    }

    function viewOrderDetails(orderId) {
      // Implement order details view
      alert('Order details for #' + orderId + ' will be shown here');
    }

    function refreshData() {
      window.location.reload();
    }

    // Close modal when clicking outside
    window.onclick = function(event) {
      const modal = document.getElementById('statusModal');
      if (event.target === modal) {
        closeModal();
      }
    }

    // Auto-refresh every 30 seconds
    setInterval(function() {
      // Only refresh if no modal is open
      if (document.getElementById('statusModal').style.display !== 'flex') {
        const currentTime = new Date().toLocaleTimeString();
        console.log('Auto-refresh check at ' + currentTime);
      }
    }, 30000);

    // Keyboard shortcuts
    document.addEventListener('keydown', function(e) {
      if (e.ctrlKey && e.key === 'r') {
        e.preventDefault();
        refreshData();
      }
      if (e.key === 'Escape') {
        closeModal();
      }
    });
  </script>
</body>
</html>