<?php
session_start();
require_once 'config/database.php';
require_once 'includes/auth.php';

// Initialize Auth class
$auth = new Auth();

// Block delivery portal if maintenance mode is enabled (admins can bypass)
$auth->checkMaintenanceMode();

$error = '';
$message = '';

// Handle logout
if (isset($_GET['logout'])) {
    $auth->destroySession();
    header('Location: delivery-login.php');
    exit;
}

// Check if delivery person is logged in - redirect to login if not
$is_logged_in = $auth->isLoggedIn() && $auth->getCurrentUser()['role'] === 'delivery';
if (!$is_logged_in) {
    header('Location: delivery-login.php');
    exit();
}

$current_user = $auth->getCurrentUser();

// Fetch delivery statistics - user is guaranteed to be logged in
$delivery_id = $current_user['id'];

$today_deliveries = 0;
$pending_deliveries = 0;
$completed_deliveries = 0;
$total_assigned = 0;
$recent_deliveries = [];

try {
  $deliveries_sql = "SELECT 
               d.id AS delivery_id,
               d.order_id,
               d.status AS delivery_status,
               d.delivery_time AS actual_delivery_time,
               d.created_at AS assigned_at,
               d.updated_at,
               o.status AS status,
               o.status AS order_status,
               o.delivery_date,
               o.delivery_time AS scheduled_delivery_time,
               o.delivery_address,
               o.cylinder_type,
               o.quantity,
               u.name AS customer_name
             FROM deliveries d
             INNER JOIN orders o ON o.id = d.order_id
             LEFT JOIN users u ON o.customer_id = u.id
             WHERE d.delivery_person_id = ?
             ORDER BY FIELD(d.status, 'assigned', 'picked_up', 'in_transit', 'out_for_delivery', 'delivered', 'failed'),
                COALESCE(o.delivery_date, DATE(d.created_at)) ASC,
                o.delivery_time ASC";

  $deliveries_stmt = executeQuery($deliveries_sql, [$delivery_id]);
  $assigned_rows = $deliveries_stmt->fetchAll(PDO::FETCH_ASSOC);

  $unique_deliveries_map = [];

  foreach ($assigned_rows as $row) {
    $orderStatus = strtolower($row['order_status'] ?? $row['status'] ?? '');
    if ($orderStatus === 'cancelled') {
      continue;
    }

    $orderId = (int) ($row['order_id'] ?? 0);
    if ($orderId === 0) {
      continue;
    }

    if (!isset($unique_deliveries_map[$orderId])) {
      $unique_deliveries_map[$orderId] = $row;
      continue;
    }

    $current = $unique_deliveries_map[$orderId];
    $currentTimestamp = $current['updated_at'] ?? $current['assigned_at'] ?? null;
    $incomingTimestamp = $row['updated_at'] ?? $row['assigned_at'] ?? null;

    if ($incomingTimestamp && !$currentTimestamp) {
      $unique_deliveries_map[$orderId] = $row;
      continue;
    }

    if ($incomingTimestamp && $currentTimestamp && strtotime($incomingTimestamp) >= strtotime($currentTimestamp)) {
      $unique_deliveries_map[$orderId] = $row;
    }
  }

  $unique_deliveries = array_values($unique_deliveries_map);
  $todayString = (new DateTimeImmutable('today'))->format('Y-m-d');

  foreach ($unique_deliveries as $row) {
    $deliveryStatus = strtolower($row['delivery_status'] ?? '');
    $orderStatus = strtolower($row['order_status'] ?? $row['status'] ?? '');

    $isCompleted = in_array($deliveryStatus, ['delivered', 'failed'], true) || in_array($orderStatus, ['delivered', 'failed'], true);

    $total_assigned++;

    if ($isCompleted) {
      $completed_deliveries++;
    } elseif (
      in_array($deliveryStatus, ['assigned', 'picked_up', 'in_transit', 'out_for_delivery'], true) ||
      in_array($orderStatus, ['confirmed', 'processing', 'in_transit', 'out_for_delivery', 'pending', 'assigned'], true)
    ) {
      $pending_deliveries++;
    }

    $dateCandidates = [];
    if (!empty($row['actual_delivery_time'])) {
      $dateCandidates[] = $row['actual_delivery_time'];
    }
    if (!empty($row['delivery_date'])) {
      $dateCandidates[] = $row['delivery_date'];
    }
    if (!empty($row['delivery_date']) && !empty($row['scheduled_delivery_time'])) {
      $dateCandidates[] = $row['delivery_date'] . ' ' . $row['scheduled_delivery_time'];
    }

    foreach ($dateCandidates as $candidate) {
      try {
        $deliveryDate = new DateTimeImmutable($candidate);
        if ($deliveryDate->format('Y-m-d') === $todayString) {
          $today_deliveries++;
          break;
        }
      } catch (Exception $dateException) {
        continue;
      }
    }
  }

  $statusOrder = [
    'assigned' => 0,
    'processing' => 1,
    'picked_up' => 2,
    'in_transit' => 3,
    'out_for_delivery' => 4,
    'delivered' => 5,
    'failed' => 6
  ];

  usort($unique_deliveries, function ($a, $b) use ($statusOrder) {
    $statusA = strtolower($a['delivery_status'] ?? $a['order_status'] ?? $a['status'] ?? '');
    $statusB = strtolower($b['delivery_status'] ?? $b['order_status'] ?? $b['status'] ?? '');

    $priorityA = $statusOrder[$statusA] ?? 99;
    $priorityB = $statusOrder[$statusB] ?? 99;

    if ($priorityA === $priorityB) {
      $dateA = $a['delivery_date'] ?? '9999-12-31';
      $dateB = $b['delivery_date'] ?? '9999-12-31';

      if ($dateA === $dateB) {
        $timeA = $a['scheduled_delivery_time'] ?? '23:59:59';
        $timeB = $b['scheduled_delivery_time'] ?? '23:59:59';
        return strcmp($timeA, $timeB);
      }

      return strcmp($dateA, $dateB);
    }

    return $priorityA <=> $priorityB;
  });

  $recent_deliveries = array_slice($unique_deliveries, 0, 5);
    
} catch (Exception $e) {
    $error = "Error fetching data: " . $e->getMessage();
    $today_deliveries = 0;
    $pending_deliveries = 0;
    $completed_deliveries = 0;
    $total_assigned = 0;
    $recent_deliveries = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Delivery Portal | LIAA LPG</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <style>
    :root {
      --primary: #e05d00;
      --primary-dark: #e05d00;
      --primary-light: #ff8533;
      --secondary:  #e05d00;
      --accent: #ff8533;
      --success: #27ae60;
      --warning: #f39c12;
      --danger: #e74c3c;
      --light: #ecf0f1;
      --dark: #2c3e50;
      --white: #ffffff;
      --deep-orange: #cc5500;
      --golden-yellow: #ffa500;
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
      --shadow-soft: 0 8px 32px rgba(255, 107, 0, 0.12);
      --shadow-strong: 0 20px 60px rgba(255, 107, 0, 0.25);
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
      background: linear-gradient(135deg, #ff6b00 0%, #ffa500 25%, #ff8533 50%, #e05d00 75%, #cc5500 100%);
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
      background: radial-gradient(circle at 20% 20%, rgba(255, 107, 0, 0.3) 0%, transparent 50%),
                  radial-gradient(circle at 80% 80%, rgba(255, 165, 0, 0.3) 0%, transparent 50%),
                  radial-gradient(circle at 60% 30%, rgba(255, 69, 0, 0.2) 0%, transparent 50%);
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
      color: var(--pure-white);
      text-decoration: none;
      display: flex;
      align-items: center;
      gap: 0.8rem;
      text-shadow: 0 2px 10px rgba(0,0,0,0.3);
    }

    nav ul {
      display: flex;
      list-style: none;
      gap: 2rem;
    }

    nav a {
      color: var(--pure-white);
      text-decoration: none;
      font-weight: 600;
      padding: 0.8rem 1.5rem;
      border-radius: 12px;
      transition: var(--transition);
      display: flex;
      align-items: center;
      gap: 0.5rem;
      background: rgba(255, 255, 255, 0.1);
      backdrop-filter: blur(10px);
      border: 1px solid rgba(255, 255, 255, 0.2);
    }

    nav a:hover {
      background: rgba(255, 107, 0, 0.2);
      transform: translateY(-2px);
      box-shadow: 0 8px 25px rgba(0,0,0,0.15);
    }
    .container {
      max-width: 1400px;
      margin: 2rem auto;
      padding: 0 2rem;
    }

    .login-container {
      max-width: 450px;
      margin: 6rem auto;
      background: var(--glass-white);
      backdrop-filter: blur(25px);
      border-radius: 24px;
      border: 1px solid rgba(255, 255, 255, 0.18);
      box-shadow: var(--shadow-strong);
      padding: 3rem;
      position: relative;
      overflow: hidden;
    }

    .login-container::before {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      height: 4px;
      background: linear-gradient(90deg, var(--primary), var(--secondary));
    }

    .login-container h2 {
      text-align: center;
      margin-bottom: 2.5rem;
      color: var(--pure-white);
      font-size: 2rem;
      font-weight: 800;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 1rem;
    }

    .login-container h2 i {
      color: var(--primary);
    }
    .form-group {
      margin-bottom: 2rem;
    }

    .form-group label {
      display: block;
      margin-bottom: 0.8rem;
      font-weight: 700;
      color: var(--pure-white);
      font-size: 0.9rem;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }

    .form-group input {
      width: 100%;
      padding: 1rem 1.2rem;
      background: rgba(255, 255, 255, 0.1);
      border: 2px solid rgba(255, 255, 255, 0.2);
      border-radius: 12px;
      color: var(--pure-white);
      font-size: 1rem;
      transition: var(--transition);
      backdrop-filter: blur(10px);
    }

    .form-group input::placeholder {
      color: rgba(255, 255, 255, 0.6);
    }

    .form-group input:focus {
      outline: none;
      border-color: var(--primary);
      background: rgba(255, 255, 255, 0.15);
      box-shadow: 0 0 0 4px rgba(255, 107, 0, 0.2);
      transform: translateY(-2px);
    }
    .btn {
      width: 100%;
      padding: 1rem 1.5rem;
      border: none;
      border-radius: 12px;
      font-size: 1rem;
      font-weight: 700;
      cursor: pointer;
      transition: var(--transition);
      text-transform: uppercase;
      letter-spacing: 0.5px;
      position: relative;
      overflow: hidden;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 0.8rem;
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
      background: linear-gradient(135deg, var(--primary), var(--secondary));
      color: var(--pure-white);
      box-shadow: 0 8px 25px rgba(255, 107, 0, 0.4);
    }

    .btn-primary:hover {
      transform: translateY(-3px);
      box-shadow: 0 15px 35px rgba(255, 107, 0, 0.5);
    }
    .message {
      padding: 1.2rem 1.5rem;
      border-radius: 12px;
      margin-bottom: 2rem;
      font-weight: 600;
      backdrop-filter: blur(10px);
      border: 1px solid;
      display: flex;
      align-items: center;
      gap: 1rem;
    }

    .message.error {
      background: rgba(245, 101, 101, 0.15);
      color: var(--danger-red);
      border-color: rgba(245, 101, 101, 0.3);
    }

    .message.success {
      background: rgba(72, 187, 120, 0.15);
      color: var(--success-green);
      border-color: rgba(72, 187, 120, 0.3);
    }
    .stats-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
      gap: 2rem;
      margin-bottom: 3rem;
    }

    .stat-card {
      background: var(--glass-white);
      backdrop-filter: blur(25px);
      border-radius: 24px;
      border: 1px solid rgba(255, 255, 255, 0.18);
      box-shadow: var(--shadow-soft);
      padding: 2.5rem;
      text-align: center;
      transition: var(--transition);
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
      background: linear-gradient(90deg, var(--primary), var(--secondary));
    }

    .stat-card:hover {
      transform: translateY(-8px);
      box-shadow: var(--shadow-strong);
    }

    .stat-icon {
      font-size: 3.5rem;
      margin-bottom: 1.5rem;
    }

    .stat-icon .fa-calendar-day {
      color: #ff6b00;
    }

    .stat-icon .fa-clock {
      color: #ffa500;
    }

    .stat-icon .fa-check-circle {
      color: #48bb78;
    }

    .stat-icon .fa-route {
      color: #4299e1;
    }

    .stat-number {
      font-size: 3rem;
      font-weight: 900;
      color: var(--pure-white);
      margin-bottom: 0.8rem;
      text-shadow: 0 2px 10px rgba(0,0,0,0.3);
    }

    .stat-label {
      color: rgba(255, 255, 255, 0.8);
      font-size: 1.1rem;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }
    .dashboard-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
      gap: 2rem;
      margin-bottom: 3rem;
    }

    .dashboard-card {
      background: var(--glass-white);
      backdrop-filter: blur(25px);
      border-radius: 24px;
      border: 1px solid rgba(255, 255, 255, 0.18);
      box-shadow: var(--shadow-soft);
      padding: 2.5rem;
      transition: var(--transition);
      position: relative;
      overflow: hidden;
    }

    .dashboard-card::before {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      height: 4px;
      background: linear-gradient(90deg, var(--primary), var(--secondary));
    }

    .dashboard-card:hover {
      transform: translateY(-5px);
      box-shadow: var(--shadow-strong);
    }

    .dashboard-card h3 {
      font-size: 1.6rem;
      font-weight: 800;
      margin-bottom: 1.5rem;
      color: var(--pure-white);
      display: flex;
      align-items: center;
      gap: 1rem;
    }

    .dashboard-card h3 .fa-list {
      color: #ff6b00;
    }

    .dashboard-card h3 .fa-cogs {
      color: #ffa500;
    }
    .delivery-item {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 1.5rem 0;
      border-bottom: 1px solid rgba(255, 255, 255, 0.1);
      transition: var(--transition);
    }

    .delivery-item:last-child {
      border-bottom: none;
    }

    .delivery-item:hover {
      background: rgba(255, 255, 255, 0.05);
      border-radius: 12px;
      padding: 1.5rem;
      margin: 0 -1rem;
    }

    .delivery-info h4 {
      font-weight: 700;
      margin-bottom: 0.5rem;
      color: var(--pure-white);
      font-size: 1.1rem;
    }

    .delivery-info p {
      color: rgba(255, 255, 255, 0.7);
      font-size: 0.9rem;
      margin-bottom: 0.3rem;
      display: flex;
      align-items: center;
      gap: 0.5rem;
    }

    .delivery-info p i {
      width: 16px;
    }

    .delivery-info p .fa-gas-pump {
      color: #ff6b00;
    }

    .delivery-info p .fa-map-marker-alt {
      color: #e74c3c;
    }
    .status-badge {
      display: inline-flex;
      align-items: center;
      gap: 0.5rem;
      padding: 0.5rem 1rem;
      border-radius: 25px;
      font-size: 0.8rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      backdrop-filter: blur(10px);
      border: 1px solid;
    }

    .status-booked {
      background: rgba(237, 137, 54, 0.2);
      color: var(--warning-amber);
      border-color: rgba(237, 137, 54, 0.3);
    }

    .status-confirmed {
      background: rgba(66, 153, 225, 0.2);
      color: var(--info-blue);
      border-color: rgba(66, 153, 225, 0.3);
    }

    .status-processing {
      background: rgba(66, 153, 225, 0.2);
      color: var(--info-blue);
      border-color: rgba(66, 153, 225, 0.3);
    }

    .status-assigned,
    .status-picked_up,
    .status-in_transit {
      background: rgba(66, 153, 225, 0.2);
      color: var(--info-blue);
      border-color: rgba(66, 153, 225, 0.3);
    }

    .status-out_for_delivery {
      background: rgba(255, 165, 0, 0.2);
      color: #ffa500;
      border-color: rgba(255, 165, 0, 0.3);
    }

    .status-delivered {
      background: rgba(72, 187, 120, 0.2);
      color: var(--success-green);
      border-color: rgba(72, 187, 120, 0.3);
    }
    .action-buttons {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
      gap: 1rem;
      margin-top: 2rem;
    }

    .action-btn {
      padding: 1rem 1.5rem;
      border: none;
      border-radius: 12px;
      font-weight: 700;
      text-decoration: none;
      text-align: center;
      transition: var(--transition);
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 0.5rem;
      font-size: 0.9rem;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      position: relative;
      overflow: hidden;
    }

    .action-btn::before {
      content: '';
      position: absolute;
      top: 0;
      left: -100%;
      width: 100%;
      height: 100%;
      background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.3), transparent);
      transition: left 0.6s;
    }

    .action-btn:hover::before {
      left: 100%;
    }

    .action-btn.primary {
      background: linear-gradient(135deg, var(--primary), var(--secondary));
      color: var(--pure-white);
      box-shadow: 0 4px 15px rgba(255, 107, 0, 0.3);
    }

    .action-btn.secondary {
      background: rgba(255, 255, 255, 0.1);
      color: var(--pure-white);
      border: 2px solid rgba(255, 255, 255, 0.2);
      backdrop-filter: blur(10px);
    }

    .action-btn:hover {
      transform: translateY(-3px);
      box-shadow: 0 8px 25px rgba(0,0,0,0.2);
    }

    .action-btn.primary:hover {
      box-shadow: 0 8px 25px rgba(255, 107, 53, 0.5);
    }
    @media (max-width: 768px) {
      .container {
        padding: 0 1rem;
      }

      .header-container {
        flex-direction: column;
        gap: 1rem;
      }

      .stats-grid {
        grid-template-columns: repeat(2, 1fr);
        gap: 1rem;
      }

      .dashboard-grid {
        grid-template-columns: 1fr;
      }

      .action-buttons {
        grid-template-columns: 1fr;
      }

      .login-container {
        margin: 2rem auto;
        padding: 2rem;
      }

      .stat-card {
        padding: 1.5rem;
      }

      .stat-number {
        font-size: 2rem;
      }

      .stat-icon {
        font-size: 2.5rem;
      }
    }

    /* Page Title Styling */
    .page-title {
      color: var(--pure-white);
      font-size: 2.8rem;
      font-weight: 900;
      margin-bottom: 0.5rem;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 1rem;
      text-align: center;
      text-shadow: 0 2px 10px rgba(0,0,0,0.3);
    }

    .page-title .fa-truck {
      color: #ff6b00;
    }

    /* Header icon colors */
    .logo .fa-fire {
      color: #ff4500;
    }

    nav a .fa-home {
      color: #4caf50;
    }

    nav a .fa-sign-out-alt {
      color: #f44336;
    }

    /* Action button icon colors */
    .action-btn .fa-truck {
      color: #fff;
    }

    .action-btn.secondary .fa-check-circle {
      color: #48bb78;
    }

    .action-btn.secondary .fa-route {
      color: #4299e1;
    }

    .action-btn.secondary .fa-home {
      color: #4caf50;
    }

    /* No data state */
    .no-data {
      text-align: center;
      color: rgba(255, 255, 255, 0.6);
      font-style: italic;
      padding: 2rem;
      background: rgba(255, 255, 255, 0.05);
      border-radius: 12px;
      border: 1px dashed rgba(255, 255, 255, 0.2);
    }

    .no-data .fa-inbox {
      color: #95a5a6;
    }
  </style>
</head>
<body>
  <div class="header">
    <div class="header-container">
      <a href="Home.html" class="logo">
        <i class="fas fa-fire"></i>
        LIAA LPG
      </a>
      <nav>
        <ul>
          <li><a href="Home.html"><i class="fas fa-home"></i> Home</a></li>
          <li><a href="delivery-portal.php?logout=1"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
        </ul>
      </nav>
    </div>
  </div>

  <div class="container">
    <!-- Delivery Dashboard -->
    <h1 class="page-title">
      <i class="fas fa-truck"></i> Delivery Dashboard
    </h1>

    <div class="stats-grid">
        <div class="stat-card">
          <div class="stat-icon">
            <i class="fas fa-calendar-day"></i>
          </div>
          <div class="stat-number"><?php echo $today_deliveries; ?></div>
          <div class="stat-label">Today's Deliveries</div>
        </div>
        
        <div class="stat-card">
          <div class="stat-icon">
            <i class="fas fa-clock"></i>
          </div>
          <div class="stat-number"><?php echo $pending_deliveries; ?></div>
          <div class="stat-label">Pending Deliveries</div>
        </div>
        
        <div class="stat-card">
          <div class="stat-icon">
            <i class="fas fa-check-circle"></i>
          </div>
          <div class="stat-number"><?php echo $completed_deliveries; ?></div>
          <div class="stat-label">Completed Deliveries</div>
        </div>
        
        <div class="stat-card">
          <div class="stat-icon">
            <i class="fas fa-route"></i>
          </div>
          <div class="stat-number"><?php echo $total_assigned; ?></div>
          <div class="stat-label">Total Assigned</div>
        </div>
      </div>

      <div class="dashboard-grid">
        <div class="dashboard-card">
          <h3><i class="fas fa-list"></i> Recent Deliveries</h3>
          <?php if (empty($recent_deliveries)): ?>
            <div class="no-data">
              <i class="fas fa-inbox" style="font-size: 2rem; margin-bottom: 1rem; display: block;"></i>
              No recent deliveries found
            </div>
          <?php else: ?>
            <?php foreach ($recent_deliveries as $delivery): ?>
              <div class="delivery-item">
                <div class="delivery-info">
                  <h4><?php echo htmlspecialchars($delivery['customer_name'] ?? 'Unknown'); ?></h4>
                  <p><i class="fas fa-gas-pump"></i> <?php echo htmlspecialchars(ucfirst($delivery['cylinder_type'])); ?> x<?php echo $delivery['quantity']; ?></p>
                  <p><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($delivery['delivery_address']); ?></p>
                </div>
                <?php
                  $displayStatus = $delivery['delivery_status'] ?? $delivery['status'] ?? 'assigned';
                  $statusClass = strtolower(str_replace([' ', '-'], '_', $displayStatus));
                ?>
                <span class="status-badge status-<?php echo htmlspecialchars($statusClass); ?>">
                  <?php echo htmlspecialchars(strtoupper(str_replace('_', ' ', $displayStatus))); ?>
                </span>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
        
        <div class="dashboard-card">
          <h3><i class="fas fa-cogs"></i> Quick Actions</h3>
          <div class="action-buttons">
            <a href="pending-deliveries.php" class="action-btn primary">
              <i class="fas fa-truck"></i> Pending Deliveries
            </a>
            <a href="completed-deliveries.php" class="action-btn secondary">
              <i class="fas fa-check-circle"></i> Completed Deliveries
            </a>
            <a href="delivery-tracking.php" class="action-btn secondary">
              <i class="fas fa-route"></i> Track Deliveries
            </a>
            <a href="Home.html" class="action-btn secondary">
              <i class="fas fa-home"></i> Back to Home
            </a>
          </div>
        </div>
      </div>
    </div>
  </div>

  <script>
    // Enhanced interactions
    document.addEventListener('DOMContentLoaded', function() {
      // Animate cards on scroll
      const cards = document.querySelectorAll('.stat-card, .dashboard-card');
      const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
          if (entry.isIntersecting) {
            entry.target.style.transform = 'translateY(0)';
            entry.target.style.opacity = '1';
          }
        });
      });

      cards.forEach((card, index) => {
        card.style.transform = 'translateY(30px)';
        card.style.opacity = '0';
        card.style.transition = `all 0.6s ease ${index * 0.1}s`;
        observer.observe(card);
      });

      // Add hover effects for delivery items
      const deliveryItems = document.querySelectorAll('.delivery-item');
      deliveryItems.forEach(item => {
        item.addEventListener('mouseenter', function() {
          this.style.transform = 'translateX(10px)';
        });
        
        item.addEventListener('mouseleave', function() {
          this.style.transform = 'translateX(0)';
        });
      });

      // Animate stats numbers
      const statNumbers = document.querySelectorAll('.stat-number');
      statNumbers.forEach(stat => {
        const finalNumber = parseInt(stat.textContent);
        let currentNumber = 0;
        const increment = Math.ceil(finalNumber / 30);
        
        const timer = setInterval(() => {
          currentNumber += increment;
          if (currentNumber >= finalNumber) {
            currentNumber = finalNumber;
            clearInterval(timer);
          }
          stat.textContent = currentNumber;
        }, 50);
      });

      // Form validation enhancements
      const form = document.querySelector('form');
      if (form) {
        const inputs = form.querySelectorAll('input');
        inputs.forEach(input => {
          input.addEventListener('focus', function() {
            this.parentElement.style.transform = 'translateY(-2px)';
          });
          
          input.addEventListener('blur', function() {
            this.parentElement.style.transform = 'translateY(0)';
          });
        });
      }
    });
  </script>
</body>
</html> 