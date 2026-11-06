<?php
session_start();
require_once 'config/database.php';
require_once 'includes/auth.php';

$error = '';
$message = '';

// Initialize Auth class
$auth = new Auth();

// Check if delivery person is logged in
if (!$auth->isLoggedIn() || $auth->getCurrentUser()['role'] !== 'delivery') {
    header('Location: delivery-portal.php');
    exit;
}

$current_user = $auth->getCurrentUser();
$delivery_id = $current_user['id'];

// Fetch completed deliveries
try {
    $sql = "SELECT o.*, 
                   u.name as customer_name, 
                   u.email as customer_email, 
                   u.phone as customer_phone,
                   u.address as customer_address,
                   COALESCE(u.address, o.delivery_address, 'Address not specified') as full_address,
             d.delivery_time as delivered_at,
             d.notes as delivery_notes,
             d.delivery_person_id,
             dp.name AS delivery_person_name
            FROM orders o
            LEFT JOIN users u ON o.customer_id = u.id
            LEFT JOIN deliveries d ON o.id = d.order_id
         LEFT JOIN users dp ON d.delivery_person_id = dp.id
            WHERE o.status = 'delivered'
            AND (d.delivery_person_id = ? OR d.delivery_person_id IS NULL)
            ORDER BY COALESCE(d.delivery_time, o.updated_at) DESC, o.updated_at DESC";
    
    $stmt = executeQuery($sql, [$delivery_id]);
    $completed_deliveries = $stmt->fetchAll();
} catch (Exception $e) {
    $error = "Error fetching deliveries: " . $e->getMessage();
    $completed_deliveries = [];
}

$order_histories = [];
if (!empty($completed_deliveries)) {
    $orderIds = array_column($completed_deliveries, 'id');
    $placeholders = implode(',', array_fill(0, count($orderIds), '?'));

    try {
        $history_sql = "SELECT oh.order_id, oh.status, oh.notes, oh.created_at, oh.changed_by, u.name AS changed_by_name
                        FROM order_history oh
                        LEFT JOIN users u ON oh.changed_by = u.id
                        WHERE oh.order_id IN ($placeholders)
                        ORDER BY oh.created_at";

        $history_stmt = executeQuery($history_sql, $orderIds);
        $history_rows = $history_stmt->fetchAll();

        foreach ($history_rows as $row) {
            $order_histories[$row['order_id']][] = $row;
        }
    } catch (Exception $history_exception) {
        error_log('Failed to load order history for completed deliveries: ' . $history_exception->getMessage());
    }
}

// Get delivery statistics
try {
    $stats_sql = "SELECT 
                    COUNT(*) as total_completed,
                    COUNT(CASE WHEN DATE(COALESCE(d.delivery_time, o.updated_at)) = CURDATE() THEN 1 END) as completed_today,
                    COUNT(CASE WHEN WEEK(COALESCE(d.delivery_time, o.updated_at)) = WEEK(CURDATE()) AND YEAR(COALESCE(d.delivery_time, o.updated_at)) = YEAR(CURDATE()) THEN 1 END) as completed_this_week,
                    COUNT(CASE WHEN MONTH(COALESCE(d.delivery_time, o.updated_at)) = MONTH(CURDATE()) AND YEAR(COALESCE(d.delivery_time, o.updated_at)) = YEAR(CURDATE()) THEN 1 END) as completed_this_month
                  FROM orders o
                  LEFT JOIN deliveries d ON o.id = d.order_id
                  WHERE o.status = 'delivered' 
                  AND (d.delivery_person_id = ? OR d.delivery_person_id IS NULL)";
    
    $stats_stmt = executeQuery($stats_sql, [$delivery_id]);
    $stats = $stats_stmt->fetch();
} catch (Exception $e) {
    $stats = ['total_completed' => 0, 'completed_today' => 0, 'completed_this_week' => 0, 'completed_this_month' => 0];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Completed Deliveries - LPG Delivery Management</title>
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
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.3), transparent);
            transition: left 0.6s;
        }

        .stat-card:hover::before {
            left: 100%;
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
            position: relative;
            overflow: hidden;
        }

        .delivery-card::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            width: 4px;
            height: 100%;
            background: linear-gradient(135deg, #56ab2f, #a8e6cf);
            transform: scaleY(0);
            transition: transform 0.3s ease;
        }

        .delivery-card:hover::before {
            transform: scaleY(1);
        }

        .delivery-card:hover {
            background: rgba(255, 255, 255, 0.15);
            transform: translateY(-2px) translateX(5px);
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
            background: linear-gradient(135deg, #56ab2f, #a8e6cf);
            display: flex;
            align-items: center;
            gap: 0.5rem;
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

        .completion-info {
            background: rgba(56, 178, 172, 0.2);
            border: 1px solid rgba(56, 178, 172, 0.3);
            border-radius: 12px;
            padding: 1rem;
            margin-top: 1rem;
        }

        .completion-title {
            font-weight: 600;
            color: #38b2ac;
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .completion-date {
            font-size: 0.9rem;
            opacity: 0.9;
        }

        .delivery-notes {
            background: rgba(255, 255, 255, 0.1);
            border-radius: 8px;
            padding: 0.75rem;
            margin-top: 0.5rem;
            font-style: italic;
        }

        .empty-state {
            text-align: center;
            padding: 3rem 1rem;
        }

        .empty-icon {
            font-size: 4rem;
            opacity: 0.5;
            margin-bottom: 1rem;
            color: #56ab2f;
        }

        .empty-title {
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }

        .empty-text {
            opacity: 0.7;
        }

        .filter-controls {
            display: flex;
            gap: 1rem;
            margin-bottom: 2rem;
            flex-wrap: wrap;
            align-items: center;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .filter-label {
            font-size: 0.8rem;
            opacity: 0.8;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .filter-select {
            padding: 0.5rem;
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.3);
            border-radius: 8px;
            color: white;
            font-family: inherit;
        }

        .filter-select option {
            background: #667eea;
            color: white;
        }

        .timeline {
            margin-top: 1.5rem;
            padding-left: 1.2rem;
            border-left: 3px solid rgba(255, 255, 255, 0.2);
            position: relative;
        }

        .timeline::before {
            content: '';
            position: absolute;
            left: -9px;
            top: 0;
            bottom: 0;
            width: 3px;
            background: linear-gradient(180deg, rgba(86, 171, 47, 0.6), rgba(168, 230, 207, 0.2));
        }

        .timeline-title {
            font-weight: 600;
            font-size: 1rem;
            margin-bottom: 0.75rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: #56ab2f;
        }

        .timeline-list {
            list-style: none;
            margin: 0;
            padding: 0;
            display: flex;
            flex-direction: column;
            gap: 0.9rem;
        }

        .timeline-item {
            position: relative;
            padding-left: 1.5rem;
        }

        .timeline-item::before {
            content: '';
            position: absolute;
            left: -1.1rem;
            top: 0.35rem;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: linear-gradient(135deg, #56ab2f, #a8e6cf);
            border: 2px solid rgba(255, 255, 255, 0.7);
            box-shadow: 0 2px 6px rgba(86, 171, 47, 0.3);
        }

        .timeline-status {
            font-weight: 600;
            font-size: 0.95rem;
            color: #ffffff;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .timeline-time {
            font-size: 0.85rem;
            opacity: 0.8;
            margin-top: 0.2rem;
        }

        .timeline-meta {
            font-size: 0.8rem;
            opacity: 0.7;
            margin-top: 0.15rem;
        }

        .timeline-notes {
            margin-top: 0.35rem;
            font-size: 0.85rem;
            padding: 0.6rem;
            background: rgba(255, 255, 255, 0.08);
            border-radius: 8px;
            border: 1px solid rgba(255, 255, 255, 0.12);
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

            .filter-controls {
                flex-direction: column;
                align-items: stretch;
            }
        }
    </style>
<?php if (!function_exists('formatOrderStatusLabel')): ?>
<?php
function formatOrderStatusLabel($status) {
    $normalized = strtolower(trim((string) $status));

    $mapping = [
        'pending' => 'Pending',
        'confirmed' => 'Confirmed',
        'processing' => 'Processing',
        'assigned' => 'Assigned to Delivery',
        'out_for_delivery' => 'Out for Delivery',
        'in_transit' => 'In Transit',
        'delivered' => 'Delivered',
        'cancelled' => 'Cancelled',
        'failed' => 'Delivery Failed'
    ];

    if (isset($mapping[$normalized])) {
        return $mapping[$normalized];
    }

    $cleaned = str_replace(['_', '-'], ' ', $normalized);
    return ucwords($cleaned ?: 'Status Update');
}

function formatTimelineTime($timestamp) {
    if (empty($timestamp)) {
        return '';
    }

    $parsed = strtotime($timestamp);
    if ($parsed === false) {
        return '';
    }

    return date('M d, Y h:i A', $parsed);
}
?>
<?php endif; ?>
</head>
<body>

    <div class="glass-container">
        <div class="header">
            <h1><i class="fas fa-check-circle"></i> Completed Deliveries</h1>
            <div class="header-actions">
                <a href="delivery-portal.php" class="btn">
                    <i class="fas fa-dashboard"></i> Dashboard
                </a>
                <a href="pending-deliveries.php" class="btn btn-warning">
                    <i class="fas fa-truck"></i> Pending
                </a>
                <a href="customer-auth.php?logout=1" class="btn btn-danger">
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
                    <i class="fas fa-check-circle" style="color: #56ab2f;"></i>
                </div>
                <div class="stat-number"><?= $stats['total_completed'] ?></div>
                <div class="stat-label">Total Completed</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-calendar-day" style="color: #f39c12;"></i>
                </div>
                <div class="stat-number"><?= $stats['completed_today'] ?></div>
                <div class="stat-label">Completed Today</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-calendar-week" style="color: #9b59b6;"></i>
                </div>
                <div class="stat-number"><?= $stats['completed_this_week'] ?></div>
                <div class="stat-label">This Week</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">
                    <i class="fas fa-calendar-alt" style="color: #e74c3c;"></i>
                </div>
                <div class="stat-number"><?= $stats['completed_this_month'] ?></div>
                <div class="stat-label">This Month</div>
            </div>
        </div>

        <!-- Filter Controls -->
        <div class="filter-controls">
            <div class="filter-group">
                <label class="filter-label">Filter by Date</label>
                <select class="filter-select" onchange="filterDeliveries(this.value)">
                    <option value="all">All Deliveries</option>
                    <option value="today">Today</option>
                    <option value="week">This Week</option>
                    <option value="month">This Month</option>
                </select>
            </div>
            <div class="filter-group">
                <label class="filter-label">Sort by</label>
                <select class="filter-select" onchange="sortDeliveries(this.value)">
                    <option value="newest">Newest First</option>
                    <option value="oldest">Oldest First</option>
                    <option value="customer">Customer Name</option>
                </select>
            </div>
        </div>

        <!-- Deliveries List -->
        <div class="deliveries-grid" id="deliveriesGrid">
            <?php if (empty($completed_deliveries)): ?>
                <div class="empty-state">
                    <div class="empty-icon">
                        <i class="fas fa-clipboard-check"></i>
                    </div>
                    <div class="empty-title">No Completed Deliveries</div>
                    <div class="empty-text">Completed deliveries will appear here once you start delivering orders successfully.</div>
                </div>
            <?php else: ?>
                <?php foreach ($completed_deliveries as $delivery): ?>
                    <?php
                        $primaryTimestamp = $delivery['delivered_at'] ?: ($delivery['updated_at'] ?? $delivery['created_at']);
                        $timelineEntries = [];
                        $seenStatuses = [];

                        if (!empty($delivery['created_at'])) {
                            $placedMeta = !empty($delivery['customer_name'])
                                ? 'Placed by ' . $delivery['customer_name']
                                : 'Placed by customer';

                            $initialNotes = trim((string)($delivery['special_instructions'] ?? ''));
                            if ($initialNotes !== '') {
                                $initialNotes = 'Instructions: ' . $initialNotes;
                            }

                            $timelineEntries[] = [
                                'label' => 'Order Placed',
                                'time' => formatTimelineTime($delivery['created_at']),
                                'meta' => $placedMeta,
                                'notes' => $initialNotes
                            ];
                        }

                        if (!empty($order_histories[$delivery['id']])) {
                            foreach ($order_histories[$delivery['id']] as $historyEntry) {
                                $statusKey = strtolower(trim((string)($historyEntry['status'] ?? '')));
                                if ($statusKey !== '') {
                                    $seenStatuses[$statusKey] = true;
                                }

                                $metaInfo = '';
                                if (!empty($historyEntry['changed_by_name'])) {
                                    $metaInfo = 'Updated by ' . $historyEntry['changed_by_name'];
                                }

                                $notesInfo = trim((string)($historyEntry['notes'] ?? ''));

                                $timelineEntries[] = [
                                    'label' => formatOrderStatusLabel($historyEntry['status'] ?? ''),
                                    'time' => formatTimelineTime($historyEntry['created_at'] ?? ''),
                                    'meta' => $metaInfo,
                                    'notes' => $notesInfo
                                ];
                            }
                        }

                        $finalRawTimestamp = $delivery['delivered_at'] ?: ($delivery['updated_at'] ?? null);
                        if ($finalRawTimestamp && empty($seenStatuses['delivered'])) {
                            $completedBy = !empty($delivery['delivery_person_name'])
                                ? 'Completed by ' . $delivery['delivery_person_name']
                                : 'Completed by delivery team';

                            $timelineEntries[] = [
                                'label' => 'Delivered',
                                'time' => formatTimelineTime($finalRawTimestamp),
                                'meta' => $completedBy,
                                'notes' => trim((string)($delivery['delivery_notes'] ?? ''))
                            ];
                        } elseif (!empty($delivery['delivery_notes'])) {
                            $lastIndex = count($timelineEntries) - 1;
                            if ($lastIndex >= 0 && empty($timelineEntries[$lastIndex]['notes'])) {
                                $timelineEntries[$lastIndex]['notes'] = trim((string)$delivery['delivery_notes']);
                            }
                        }
                    ?>
                    <div class="delivery-card" data-date="<?= htmlspecialchars($primaryTimestamp ?? '') ?>" data-customer="<?= htmlspecialchars($delivery['customer_name'] ?: 'Unknown') ?>">
                        <div class="delivery-header">
                            <div class="order-id">
                                <i class="fas fa-hashtag"></i> Order #<?= htmlspecialchars($delivery['id']) ?>
                            </div>
                            <span class="status-badge">
                                <i class="fas fa-check"></i> Delivered
                            </span>
                        </div>

                        <div class="delivery-details">
                            <div class="detail-item">
                                <div class="detail-label">Customer</div>
                                <div class="detail-value">
                                    <i class="fas fa-user"></i> <?= htmlspecialchars($delivery['customer_name'] ?: 'Unknown Customer') ?>
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

                        <div class="completion-info">
                            <div class="completion-title">
                                <i class="fas fa-calendar-check"></i> Delivery Completed
                            </div>
                            <div class="completion-date">
                                <?= $delivery['delivered_at'] ? date('M d, Y h:i A', strtotime($delivery['delivered_at'])) : date('M d, Y h:i A', strtotime($delivery['updated_at'])) ?>
                            </div>
                            <?php if (!empty($delivery['delivery_notes'])): ?>
                                <div class="delivery-notes">
                                    <strong>Notes:</strong> <?= htmlspecialchars($delivery['delivery_notes']) ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($timelineEntries)): ?>
                            <div class="timeline">
                                <div class="timeline-title">
                                    <i class="fas fa-history"></i> Order Timeline
                                </div>
                                <ul class="timeline-list">
                                    <?php foreach ($timelineEntries as $entry): ?>
                                        <li class="timeline-item">
                                            <div class="timeline-status">
                                                <i class="fas fa-dot-circle"></i>
                                                <?= htmlspecialchars($entry['label']) ?>
                                            </div>
                                            <?php if (!empty($entry['time'])): ?>
                                                <div class="timeline-time"><?= htmlspecialchars($entry['time']) ?></div>
                                            <?php endif; ?>
                                            <?php if (!empty($entry['meta'])): ?>
                                                <div class="timeline-meta"><?= htmlspecialchars($entry['meta']) ?></div>
                                            <?php endif; ?>
                                            <?php if (!empty($entry['notes'])): ?>
                                                <div class="timeline-notes"><?= htmlspecialchars($entry['notes']) ?></div>
                                            <?php endif; ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Enhanced functionality for completed deliveries
        document.addEventListener('DOMContentLoaded', function() {
            // Animate stats numbers
            const statNumbers = document.querySelectorAll('.stat-number');
            statNumbers.forEach((stat, index) => {
                const finalValue = parseInt(stat.textContent);
                let currentValue = 0;
                const increment = Math.ceil(finalValue / 30);
                
                setTimeout(() => {
                    const timer = setInterval(() => {
                        currentValue += increment;
                        if (currentValue >= finalValue) {
                            currentValue = finalValue;
                            clearInterval(timer);
                        }
                        stat.textContent = currentValue;
                    }, 50);
                }, index * 200);
            });

            // Animate cards on scroll
            const cards = document.querySelectorAll('.delivery-card');
            const observer = new IntersectionObserver((entries) => {
                entries.forEach((entry, index) => {
                    if (entry.isIntersecting) {
                        setTimeout(() => {
                            entry.target.style.transform = 'translateY(0)';
                            entry.target.style.opacity = '1';
                        }, index * 100);
                    }
                });
            });

            cards.forEach(card => {
                card.style.transform = 'translateY(30px)';
                card.style.opacity = '0';
                card.style.transition = 'all 0.6s ease';
                observer.observe(card);
            });

            // Auto-hide success/error messages
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                setTimeout(() => {
                    alert.style.opacity = '0';
                    alert.style.transition = 'opacity 0.5s ease';
                    setTimeout(() => {
                        alert.remove();
                    }, 500);
                }, 5000);
            });
        });

        // Filter deliveries by date
        function filterDeliveries(filter) {
            const cards = document.querySelectorAll('.delivery-card');
            const now = new Date();
            
            cards.forEach(card => {
                const deliveryDate = new Date(card.dataset.date);
                let show = true;
                
                switch(filter) {
                    case 'today':
                        show = deliveryDate.toDateString() === now.toDateString();
                        break;
                    case 'week':
                        const weekAgo = new Date(now.getTime() - 7 * 24 * 60 * 60 * 1000);
                        show = deliveryDate >= weekAgo;
                        break;
                    case 'month':
                        show = deliveryDate.getMonth() === now.getMonth() && 
                               deliveryDate.getFullYear() === now.getFullYear();
                        break;
                    case 'all':
                    default:
                        show = true;
                }
                
                card.style.display = show ? 'block' : 'none';
                
                if (show) {
                    card.style.animation = 'fadeInUp 0.5s ease forwards';
                }
            });

            // Show empty state if no cards are visible
            const visibleCards = document.querySelectorAll('.delivery-card[style*="block"], .delivery-card:not([style*="none"])');
            const emptyState = document.querySelector('.empty-state');
            
            if (visibleCards.length === 0 && !emptyState) {
                const grid = document.getElementById('deliveriesGrid');
                const emptyDiv = document.createElement('div');
                emptyDiv.className = 'empty-state';
                emptyDiv.innerHTML = `
                    <div class="empty-icon">
                        <i class="fas fa-filter"></i>
                    </div>
                    <div class="empty-title">No Deliveries Found</div>
                    <div class="empty-text">No completed deliveries match the selected filter.</div>
                `;
                grid.appendChild(emptyDiv);
            } else if (visibleCards.length > 0 && emptyState) {
                emptyState.remove();
            }
        }

        // Sort deliveries
        function sortDeliveries(sortBy) {
            const grid = document.getElementById('deliveriesGrid');
            const cards = Array.from(document.querySelectorAll('.delivery-card'));
            
            cards.sort((a, b) => {
                switch(sortBy) {
                    case 'newest':
                        return new Date(b.dataset.date) - new Date(a.dataset.date);
                    case 'oldest':
                        return new Date(a.dataset.date) - new Date(b.dataset.date);
                    case 'customer':
                        return a.dataset.customer.localeCompare(b.dataset.customer);
                    default:
                        return 0;
                }
            });
            
            // Re-append sorted cards
            cards.forEach((card, index) => {
                setTimeout(() => {
                    grid.appendChild(card);
                    card.style.animation = 'slideInRight 0.5s ease forwards';
                }, index * 50);
            });
        }

        // Add CSS animations
        const style = document.createElement('style');
        style.textContent = `
            @keyframes fadeInUp {
                from {
                    opacity: 0;
                    transform: translateY(20px);
                }
                to {
                    opacity: 1;
                    transform: translateY(0);
                }
            }
            
            @keyframes slideInRight {
                from {
                    opacity: 0;
                    transform: translateX(20px);
                }
                to {
                    opacity: 1;
                    transform: translateX(0);
                }
            }
        `;
        document.head.appendChild(style);
    </script>
</body>
</html> 