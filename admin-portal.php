<?php
session_start();
require_once 'config/database.php';
require_once 'includes/auth.php';

$error = '';
$message = '';

// Initialize Auth class
$auth = new Auth();

// Handle logout
if (isset($_GET['logout'])) {
    $auth->destroySession();
    header('Location: admin-portal.php');
    exit;
}

// Handle login
if (isset($_POST['login'])) {
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';
    
    // Authenticate admin user
    $user = $auth->authenticate($email, $password, 'admin');
    
    if ($user) {
        // Create admin session
        $auth->createSession($user);
        header('Location: admin-portal.php');
        exit();
    } else {
        $error = 'Invalid email or password. Please try again.';
    }
}

// Check if admin is logged in
$is_logged_in = $auth->isLoggedIn() && $auth->getCurrentUser()['role'] === 'admin';
$current_user = $is_logged_in ? $auth->getCurrentUser() : null;

// Initialize statistics
$total_orders = 0;
$pending_deliveries = 0;
$total_customers = 0;
$total_delivery_staff = 0;
$recent_orders = [];
$waiting_approvals = 0;

// Fetch statistics if logged in
if ($is_logged_in) {
    try {
        // Total orders
        $orders_sql = "SELECT COUNT(*) as total_orders FROM orders";
        $orders_stmt = executeQuery($orders_sql);
        $total_orders = $orders_stmt->fetch()['total_orders'] ?? 0;
        
        // Pending deliveries
        $pending_sql = "SELECT COUNT(*) as pending FROM orders WHERE status IN ('confirmed', 'processing')";
        $pending_stmt = executeQuery($pending_sql);
        $pending_deliveries = $pending_stmt->fetch()['pending'] ?? 0;
        
        // Active customers
        $customers_sql = "SELECT COUNT(*) as total_customers FROM users WHERE role = 'customer' AND status = 'active'";
        $customers_stmt = executeQuery($customers_sql);
        $total_customers = $customers_stmt->fetch()['total_customers'] ?? 0;

        // Active delivery staff
        $staff_sql = "SELECT COUNT(*) as total_staff FROM users WHERE role = 'delivery' AND status = 'active'";
        $staff_stmt = executeQuery($staff_sql);
        $total_delivery_staff = $staff_stmt->fetch()['total_staff'] ?? 0;

        // Pending approvals in waiting room
        $waiting_sql = "SELECT COUNT(*) as waiting FROM waiting_room WHERE status = 'pending'";
        $waiting_stmt = executeQuery($waiting_sql);
        $waiting_approvals = $waiting_stmt->fetch()['waiting'] ?? 0;

        // Recent order activity
        $recent_sql = "SELECT o.id, u.name AS customer_name, o.cylinder_type, o.quantity, o.created_at, o.status
                       FROM orders o
                       LEFT JOIN users u ON o.customer_id = u.id
                       ORDER BY o.created_at DESC
                       LIMIT 10";
        $recent_stmt = executeQuery($recent_sql);
        $recent_orders = $recent_stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $dashboard_exception) {
        error_log('Failed to load admin dashboard statistics: ' . $dashboard_exception->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Admin Portal | LIAA LPG</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <style>
    :root {
      --flame-orange: #FF6B35;
      --flame-red: #F7931E;
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

    /* Glassmorphism Header */
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
      font-size: 2rem;
      font-weight: 800;
      color: white;
      text-decoration: none;
      display: flex;
      align-items: center;
      gap: 0.8rem;
      filter: drop-shadow(0 2px 4px rgba(255, 107, 53, 0.3));
    }

    .logo i {
      color: #B8341A;
      animation: flameFlicker 2s ease-in-out infinite;
    }

    @keyframes flameFlicker {
      0%, 100% { transform: scale(1) rotate(0deg); }
      25% { transform: scale(1.1) rotate(2deg); }
      75% { transform: scale(0.95) rotate(-1deg); }
    }

    .user-info {
      display: flex;
      align-items: center;
      gap: 1.5rem;
    }

    .user-avatar {
      width: 48px;
      height: 48px;
      background: linear-gradient(135deg, var(--flame-orange), var(--flame-red));
      border-radius: 16px;
      display: flex;
      align-items: center;
      justify-content: center;
      color: white;
      font-weight: 700;
      font-size: 1.1rem;
      box-shadow: var(--shadow-soft);
      transition: var(--transition);
    }

    .user-avatar:hover {
      transform: translateY(-2px) scale(1.05);
      box-shadow: var(--shadow-strong);
    }

    .user-details {
      display: flex;
      flex-direction: column;
      color: var(--pure-white);
    }

    .user-name {
      font-weight: 600;
      font-size: 0.95rem;
    }

    .user-role {
      font-size: 0.8rem;
      opacity: 0.8;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }

    .logout-btn {
      background: linear-gradient(135deg, var(--danger-red), #e53e3e);
      color: white;
      text-decoration: none;
      padding: 0.7rem 1.4rem;
      border-radius: 12px;
      font-weight: 600;
      font-size: 0.9rem;
      transition: var(--transition);
      border: 1px solid rgba(245, 101, 101, 0.3);
      box-shadow: var(--shadow-soft);
    }

    .logout-btn:hover {
      transform: translateY(-2px);
      box-shadow: var(--shadow-strong);
      background: linear-gradient(135deg, #e53e3e, #c53030);
    }

    /* Main Container */
    .container {
      max-width: 1400px;
      margin: 0 auto;
      padding: 2rem;
    }

    /* Login Form with Neumorphism */
    .login-container {
      max-width: 420px;
      margin: 8rem auto;
      background: var(--glass-white);
      backdrop-filter: blur(25px);
      padding: 3.5rem;
      border-radius: 24px;
      box-shadow: var(--shadow-strong);
      border: 1px solid rgba(255, 255, 255, 0.18);
      position: relative;
      overflow: hidden;
    }

    .login-container::before {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      height: 2px;
      background: linear-gradient(90deg, var(--flame-orange), var(--flame-red), var(--success-green));
      animation: shimmerLine 3s ease-in-out infinite;
    }

    @keyframes shimmerLine {
      0% { transform: translateX(-100%); }
      100% { transform: translateX(100%); }
    }

    .login-title {
      text-align: center;
      margin-bottom: 2.5rem;
      color: var(--pure-white);
      font-size: 2.2rem;
      font-weight: 800;
      position: relative;
    }

    .login-title::after {
      content: '';
      position: absolute;
      bottom: -8px;
      left: 50%;
      transform: translateX(-50%);
      width: 60px;
      height: 3px;
      background: linear-gradient(90deg, var(--flame-orange), var(--flame-red));
      border-radius: 2px;
    }

    .form-group {
      margin-bottom: 1.8rem;
      position: relative;
    }

    .form-group label {
      display: block;
      margin-bottom: 0.6rem;
      color: var(--pure-white);
      font-weight: 600;
      font-size: 0.9rem;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }

    .form-group input {
      width: 100%;
      padding: 1rem 1.2rem;
      background: rgba(255, 255, 255, 0.1);
      border: 2px solid rgba(255, 255, 255, 0.2);
      border-radius: 16px;
      font-size: 1rem;
      color: var(--pure-white);
      transition: var(--transition);
      backdrop-filter: blur(10px);
    }

    .form-group input::placeholder {
      color: rgba(255, 255, 255, 0.6);
    }

    .form-group input:focus {
      outline: none;
      border-color: var(--flame-orange);
      background: rgba(255, 255, 255, 0.15);
      box-shadow: 0 0 0 4px rgba(255, 107, 53, 0.2);
      transform: translateY(-2px);
    }

    .login-btn {
      width: 100%;
      background: linear-gradient(135deg, var(--flame-orange), var(--flame-red));
      color: white;
      border: none;
      padding: 1rem;
      border-radius: 16px;
      font-size: 1.1rem;
      font-weight: 700;
      cursor: pointer;
      transition: var(--transition);
      position: relative;
      overflow: hidden;
      text-transform: uppercase;
      letter-spacing: 1px;
    }

    .login-btn::before {
      content: '';
      position: absolute;
      top: 0;
      left: -100%;
      width: 100%;
      height: 100%;
      background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.3), transparent);
      transition: left 0.6s;
    }

    .login-btn:hover::before {
      left: 100%;
    }

    .login-btn:hover {
      transform: translateY(-3px);
      box-shadow: 0 8px 25px rgba(255, 107, 53, 0.4);
    }

    /* Dashboard with Modern Card Design */
    .dashboard-title {
      text-align: center;
      color: var(--pure-white);
      font-size: 3rem;
      font-weight: 900;
      margin-bottom: 1rem;
      text-shadow: 0 4px 8px rgba(0,0,0,0.3);
      position: relative;
    }

    .dashboard-subtitle {
      text-align: center;
      color: rgba(255, 255, 255, 0.8);
      font-size: 1.1rem;
      margin-bottom: 3rem;
      font-weight: 400;
    }

    .stats-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
      gap: 2rem;
      margin-bottom: 4rem;
    }

    .stat-card {
      background: var(--glass-white);
      backdrop-filter: blur(25px);
      padding: 2.5rem;
      border-radius: 24px;
      border: 1px solid rgba(255, 255, 255, 0.18);
      text-align: center;
      transition: var(--transition);
      position: relative;
      overflow: hidden;
      box-shadow: var(--shadow-soft);
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

    .stat-card.orders::before { background: linear-gradient(90deg, var(--flame-orange), var(--flame-red)); }
    .stat-card.customers::before { background: linear-gradient(90deg, var(--success-green), #38a169); }
    .stat-card.deliveries::before { background: linear-gradient(90deg, var(--warning-amber), #dd6b20); }
    .stat-card.staff::before { background: linear-gradient(90deg, var(--info-blue), #3182ce); }

    .stat-card:hover {
      transform: translateY(-8px) scale(1.02);
      box-shadow: var(--shadow-strong);
    }

    .stat-card:hover::before {
      height: 6px;
    }

    .stat-icon {
      width: 70px;
      height: 70px;
      margin: 0 auto 1.5rem;
      border-radius: 20px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.8rem;
      color: white;
      position: relative;
      transition: var(--transition);
    }

    .stat-icon::after {
      content: '';
      position: absolute;
      inset: -2px;
      border-radius: 22px;
      padding: 2px;
      background: linear-gradient(135deg, rgba(255,255,255,0.2), rgba(255,255,255,0.05));
      mask: linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0);
      mask-composite: xor;
    }

    .stat-icon.orders { 
      background: linear-gradient(135deg, var(--flame-orange), var(--flame-red));
      box-shadow: 0 8px 20px rgba(255, 107, 53, 0.3);
    }
    .stat-icon.customers { 
      background: linear-gradient(135deg, var(--success-green), #38a169);
      box-shadow: 0 8px 20px rgba(72, 187, 120, 0.3);
    }
    .stat-icon.deliveries { 
      background: linear-gradient(135deg, var(--warning-amber), #dd6b20);
      box-shadow: 0 8px 20px rgba(237, 137, 54, 0.3);
    }
    .stat-icon.staff { 
      background: linear-gradient(135deg, var(--info-blue), #3182ce);
      box-shadow: 0 8px 20px rgba(66, 153, 225, 0.3);
    }

    .stat-card:hover .stat-icon {
      transform: scale(1.1) rotate(5deg);
    }

    .stat-number {
      font-size: 3rem;
      font-weight: 900;
      color: var(--pure-white);
      margin-bottom: 0.5rem;
      line-height: 1;
    }

    .stat-label {
      color: rgba(255, 255, 255, 0.8);
      font-weight: 600;
      text-transform: uppercase;
      font-size: 0.85rem;
      letter-spacing: 1.5px;
    }

    /* Action Cards with Hover Effects */
    .actions-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(340px, 1fr));
      gap: 2.5rem;
      margin-bottom: 4rem;
    }

    .action-card {
      background: var(--glass-white);
      backdrop-filter: blur(25px);
      padding: 2.5rem;
      border-radius: 24px;
      border: 1px solid rgba(255, 255, 255, 0.18);
      transition: var(--transition);
      position: relative;
      overflow: hidden;
      box-shadow: var(--shadow-soft);
    }

    .action-card::before {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      background: linear-gradient(135deg, rgba(255, 255, 255, 0.1), rgba(255, 255, 255, 0.05));
      opacity: 0;
      transition: var(--transition);
    }

    .action-card:hover::before {
      opacity: 1;
    }

    .action-card:hover {
      transform: translateY(-8px) scale(1.02);
      box-shadow: var(--shadow-strong);
    }

    .action-card h3 {
      color: var(--pure-white);
      margin-bottom: 1rem;
      font-size: 1.4rem;
      font-weight: 700;
    }

    .action-card p {
      color: rgba(255, 255, 255, 0.8);
      margin-bottom: 2rem;
      line-height: 1.6;
      font-size: 0.95rem;
    }

    .btn {
      display: inline-flex;
      align-items: center;
      gap: 0.8rem;
      padding: 0.9rem 1.8rem;
      border: none;
      border-radius: 16px;
      text-decoration: none;
      font-weight: 700;
      font-size: 0.9rem;
      transition: var(--transition);
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
      background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.3), transparent);
      transition: left 0.6s;
    }

    .btn:hover::before {
      left: 100%;
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
      transform: translateY(-3px);
      box-shadow: 0 8px 25px rgba(0,0,0,0.2);
    }

    /* Recent Orders Section */
    .recent-orders {
      background: var(--glass-white);
      backdrop-filter: blur(25px);
      padding: 2.5rem;
      border-radius: 24px;
      border: 1px solid rgba(255, 255, 255, 0.18);
      box-shadow: var(--shadow-soft);
      margin-bottom: 2rem;
    }

    .recent-orders h3 {
      color: var(--pure-white);
      margin-bottom: 2rem;
      font-size: 1.5rem;
      font-weight: 700;
      display: flex;
      align-items: center;
      gap: 1rem;
    }

    .recent-orders h3::before {
      content: '';
      width: 4px;
      height: 30px;
      background: linear-gradient(135deg, var(--flame-orange), var(--flame-red));
      border-radius: 2px;
    }

    .orders-table {
      width: 100%;
      border-collapse: collapse;
      margin-top: 1rem;
    }

    .orders-table th,
    .orders-table td {
      padding: 1rem;
      text-align: left;
      border-bottom: 1px solid rgba(255, 255, 255, 0.1);
    }

    .orders-table th {
      background: rgba(255, 255, 255, 0.1);
      color: var(--pure-white);
      font-weight: 600;
      text-transform: uppercase;
      font-size: 0.8rem;
      letter-spacing: 1px;
    }

    .orders-table td {
      color: rgba(255, 255, 255, 0.9);
      font-size: 0.9rem;
    }

    .orders-table tbody tr {
      transition: var(--transition);
    }

    .orders-table tbody tr:hover {
      background: rgba(255, 255, 255, 0.05);
    }

    .status-badge {
      padding: 0.4rem 0.8rem;
      border-radius: 12px;
      font-size: 0.75rem;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 0.5px;
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

    .status-delivered {
      background: rgba(72, 187, 120, 0.2);
      color: #48bb78;
      border: 1px solid rgba(72, 187, 120, 0.3);
    }

    /* Delivery History Section */

    /* Alert Messages */
    .alert {
      padding: 1rem 1.5rem;
      border-radius: 16px;
      margin-bottom: 1.5rem;
      font-weight: 600;
      backdrop-filter: blur(10px);
      border: 1px solid;
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

    /* Responsive Design */
    @media (max-width: 768px) {
      .header-container {
        padding: 0 1rem;
      }
      
      .container {
        padding: 1rem;
      }
      
      .stats-grid {
        grid-template-columns: 1fr;
        gap: 1.5rem;
      }
      
      .actions-grid {
        grid-template-columns: 1fr;
        gap: 1.5rem;
      }
      
      .dashboard-title {
        font-size: 2.2rem;
      }
      
      .user-details {
        display: none;
      }
      
      .orders-table {
        font-size: 0.8rem;
      }
      
      .orders-table th,
      .orders-table td {
        padding: 0.5rem;
      }
    }

    @media (max-width: 480px) {
      .login-container {
        margin: 4rem auto;
        padding: 2rem;
      }
      
      .stat-card {
        padding: 1.5rem;
      }
      
      .action-card {
        padding: 1.5rem;
      }
    }
  </style>
</head>
<body>
  <?php if ($is_logged_in): ?>
    <!-- Admin Dashboard -->
    <header class="header">
      <div class="header-container">
        <a href="#" class="logo">
          <i class="fas fa-fire"></i>
          LIAA LPG Admin
        </a>
        <div class="user-info">
          <div class="user-avatar">
            <?php echo strtoupper(substr($current_user['name'], 0, 1)); ?>
          </div>
          <div class="user-details">
            <div class="user-name"><?php echo htmlspecialchars($current_user['name']); ?></div>
            <div class="user-role">Administrator</div>
          </div>
          <a href="?logout=1" class="logout-btn">
            <i class="fas fa-sign-out-alt"></i> Logout
          </a>
        </div>
      </div>
    </header>

    <div class="container">
      <?php if ($error): ?>
        <div class="alert alert-error">
          <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
        </div>
      <?php endif; ?>

      <?php if ($message): ?>
        <div class="alert alert-success">
          <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($message); ?>
        </div>
      <?php endif; ?>

      <h1 class="dashboard-title">
        <i class="fas fa-fire"></i> Admin Command Center
      </h1>
      <p class="dashboard-subtitle">Welcome back! Here's what's happening with your LPG delivery business today.</p>

      <!-- Statistics -->
      <div class="stats-grid">
        <div class="stat-card orders">
          <div class="stat-icon orders">
            <i class="fas fa-shopping-cart"></i>
          </div>
          <div class="stat-number"><?php echo $total_orders; ?></div>
          <div class="stat-label">Total Orders</div>
        </div>

        <div class="stat-card customers">
          <div class="stat-icon customers">
            <i class="fas fa-users"></i>
          </div>
          <div class="stat-number"><?php echo $total_customers; ?></div>
          <div class="stat-label">Active Customers</div>
        </div>

        <div class="stat-card deliveries">
          <div class="stat-icon deliveries">
            <i class="fas fa-truck"></i>
          </div>
          <div class="stat-number"><?php echo $pending_deliveries; ?></div>
          <div class="stat-label">Pending Deliveries</div>
        </div>

        <div class="stat-card staff">
          <div class="stat-icon staff">
            <i class="fas fa-user-tie"></i>
          </div>
          <div class="stat-number"><?php echo $total_delivery_staff; ?></div>
          <div class="stat-label">Delivery Staff</div>
        </div>
      </div>

      <!-- Action Cards -->
      <div class="actions-grid">
        <div class="action-card">
          <h3><i class="fas fa-users-cog"></i> User Management</h3>
          <p>Manage customers, delivery personnel, and approve new registrations waiting in queue.</p>
          <a href="manage-users.php" class="btn btn-primary">
            <i class="fas fa-users"></i> Manage Users
            <?php if ($waiting_approvals > 0): ?>
              <span style="background: var(--danger-red); color: white; border-radius: 12px; padding: 0.3rem 0.6rem; font-size: 0.75rem; margin-left: 0.5rem; font-weight: 800;">
                <?php echo $waiting_approvals; ?>
              </span>
            <?php endif; ?>
          </a>
        </div>

        <div class="action-card">
          <h3><i class="fas fa-chart-line"></i> Analytics & Reports</h3>
          <p>Deep dive into business metrics, track performance, and generate comprehensive reports.</p>
          <a href="delivery-tracking.php" class="btn btn-success">
            <i class="fas fa-chart-bar"></i> View Analytics
          </a>
        </div>

        <div class="action-card">
          <h3><i class="fas fa-boxes"></i> Cylinder Inventory</h3>
          <p>Manage gas cylinder availability, stock levels, pricing, and real-time inventory tracking.</p>
          <a href="admin/cylinder-inventory.php" class="btn btn-info">
            <i class="fas fa-warehouse"></i> Manage Inventory
          </a>
        </div>

        <div class="action-card">
          <h3><i class="fas fa-truck-loading"></i> Delivery Operations</h3>
          <p>Monitor real-time deliveries, optimize routes, and coordinate with delivery personnel.</p>
          <a href="delivery-operations.php" class="btn btn-warning">
            <i class="fas fa-truck"></i> Delivery Hub
          </a>
        </div>

        <div class="action-card">
          <h3><i class="fas fa-cogs"></i> System Configuration</h3>
          <p>Fine-tune system settings, update pricing models, and configure business parameters.</p>
          <a href="system-settings.php" class="btn btn-secondary">
            <i class="fas fa-cog"></i> Settings Panel
          </a>
        </div>

        <div class="action-card">
          <h3><i class="fas fa-ban"></i> Cancelled Orders</h3>
          <p>Review cancelled orders, see customer reasons, and add/update admin notes.</p>
          <a href="cancelled-orders.php" class="btn btn-warning">
            <i class="fas fa-clipboard-list"></i> Manage Cancellations
          </a>
        </div>
      </div>

      <!-- Recent Orders -->
      <div class="recent-orders">
        <h3><i class="fas fa-clock"></i> Recent Order Activity</h3>
        <?php if (empty($recent_orders)): ?>
          <div style="text-align: center; padding: 3rem; color: rgba(255, 255, 255, 0.6);">
            <i class="fas fa-inbox" style="font-size: 3rem; margin-bottom: 1rem; opacity: 0.5;"></i>
            <p style="font-size: 1.1rem;">No recent orders found.</p>
            <p style="font-size: 0.9rem; margin-top: 0.5rem;">Orders will appear here as customers place them.</p>
          </div>
        <?php else: ?>
          <table class="orders-table">
            <thead>
              <tr>
                <th>Order ID</th>
                <th>Customer</th>
                <th>Product</th>
                <th>Quantity</th>
                <th>Date</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($recent_orders as $order): ?>
                <tr>
                  <td><strong>#<?php echo $order['id']; ?></strong></td>
                  <td><?php echo htmlspecialchars($order['customer_name'] ?? 'Unknown Customer'); ?></td>
                  <td><?php echo htmlspecialchars($order['cylinder_type']); ?></td>
                  <td><?php echo $order['quantity']; ?> units</td>
                  <td><?php echo date('M j, Y g:i A', strtotime($order['created_at'])); ?></td>
                  <td>
                    <span class="status-badge status-<?php echo strtolower(str_replace(' ', '-', $order['status'])); ?>">
                      <?php echo $order['status']; ?>
                    </span>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>

    </div>

  <?php else: ?>
    <!-- Login Form -->
    <div class="login-container">
      <h2 class="login-title">
        <i class="fas fa-fire"></i>
        Admin Portal
      </h2>
      
      <?php if ($error): ?>
        <div class="alert alert-error">
          <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
        </div>
      <?php endif; ?>

      <form method="POST">
        <div class="form-group">
          <label for="email"><i class="fas fa-envelope"></i> Email Address</label>
          <input type="email" id="email" name="email" placeholder="Enter your admin email" required>
        </div>
        
        <div class="form-group">
          <label for="password"><i class="fas fa-lock"></i> Password</label>
          <input type="password" id="password" name="password" placeholder="Enter your password" required>
        </div>
        
        <button type="submit" name="login" class="login-btn">
          <i class="fas fa-sign-in-alt"></i> Access Dashboard
        </button>
      </form>
      
      <div style="text-align: center; margin-top: 2rem; padding-top: 1.5rem; border-top: 1px solid rgba(255, 255, 255, 0.2);">
        <a href="Home.html" style="color: rgba(255, 255, 255, 0.8); text-decoration: none; font-weight: 600; display: inline-flex; align-items: center; gap: 0.5rem; padding: 0.8rem 1.2rem; border-radius: 12px; transition: all 0.3s ease; background: rgba(255, 255, 255, 0.1); backdrop-filter: blur(10px); border: 1px solid rgba(255, 255, 255, 0.2);">
          <i class="fas fa-arrow-left"></i> Back to Home
        </a>
      </div>
    </div>
  <?php endif; ?>
</body>
</html>