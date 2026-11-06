<?php
session_start();
require_once '../config/database.php';
require_once '../includes/auth.php';

// Initialize Auth class
$auth = new Auth();

// Check if admin is logged in
if (!$auth->isLoggedIn() || $auth->getCurrentUser()['role'] !== 'admin') {
    header('Location: ../admin-portal.php');
    exit;
}

$current_user = $auth->getCurrentUser();
$message = '';
$error = '';

// Handle inventory updates
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (isset($_POST['update_inventory'])) {
            $cylinder_id = $_POST['cylinder_id'];
            $available_quantity = intval($_POST['available_quantity']);
            $unit_price = floatval($_POST['unit_price']);
            $total_quantity = intval($_POST['total_quantity']);
            $min_stock_alert = intval($_POST['min_stock_alert'] ?? 10);
            $status = $_POST['status'];
            
            // Validate
            if ($available_quantity > $total_quantity) {
                throw new Exception('Available quantity cannot exceed total quantity.');
            }
            
            if ($available_quantity < 0 || $total_quantity < 0) {
                throw new Exception('Quantities must be positive numbers.');
            }
            
            // Update inventory
            $sql = "UPDATE cylinder_inventory SET 
                    available_quantity = ?, 
                    total_quantity = ?, 
                    unit_price = ?, 
                    min_stock_alert = ?, 
                    status = ?,
                    updated_at = NOW()
                    WHERE id = ?";
            
            executeQuery($sql, [$available_quantity, $total_quantity, $unit_price, $min_stock_alert, $status, $cylinder_id]);
            
            // Log the inventory change
            $log_sql = "INSERT INTO inventory_logs (cylinder_id, action_type, old_quantity, new_quantity, changed_by, notes) 
                       VALUES (?, 'manual_update', 
                       (SELECT available_quantity FROM cylinder_inventory WHERE id = ? LIMIT 1), 
                       ?, ?, 'Admin manual update')";
            
            try {
                executeQuery($log_sql, [$cylinder_id, $cylinder_id, $available_quantity, $current_user['id']]);
            } catch (Exception $e) {
                // Inventory logs table might not exist, continue anyway
            }
            
            $message = 'Inventory updated successfully!';
        }
        
        if (isset($_POST['add_cylinder'])) {
            $cylinder_type = trim($_POST['cylinder_type']);
            $unit_price = floatval($_POST['unit_price']);
            $total_quantity = intval($_POST['total_quantity']);
            $available_quantity = intval($_POST['available_quantity']);
            $min_stock_alert = intval($_POST['min_stock_alert'] ?? 10);
            $description = trim($_POST['description']);
            
            if (empty($cylinder_type) || $unit_price <= 0 || $total_quantity <= 0) {
                throw new Exception('Please fill all required fields with valid values.');
            }
            
            if ($available_quantity > $total_quantity) {
                throw new Exception('Available quantity cannot exceed total quantity.');
            }
            
            $sql = "INSERT INTO cylinder_inventory (cylinder_type, unit_price, total_quantity, available_quantity, min_stock_alert, description, status) 
                   VALUES (?, ?, ?, ?, ?, ?, 'active')";
            
            executeQuery($sql, [$cylinder_type, $unit_price, $total_quantity, $available_quantity, $min_stock_alert, $description]);
            
            $message = 'New cylinder type added successfully!';
        }
        
        if (isset($_POST['bulk_update'])) {
            $operation = $_POST['bulk_operation'];
            $selected_cylinders = $_POST['selected_cylinders'] ?? [];
            
            if (empty($operation)) {
                throw new Exception('Please select a bulk operation to perform.');
            }
            
            if (empty($selected_cylinders)) {
                throw new Exception('Please select at least one cylinder to update.');
            }
            
            $updated_count = 0;
            $operation_details = [];
            
            foreach ($selected_cylinders as $cylinder_id) {
                // Get current cylinder info for logging
                $info_sql = "SELECT cylinder_type, available_quantity, total_quantity, status FROM cylinder_inventory WHERE id = ?";
                $info_stmt = executeQuery($info_sql, [$cylinder_id]);
                $cylinder_info = $info_stmt->fetch();
                
                if (!$cylinder_info) {
                    continue; // Skip if cylinder doesn't exist
                }
                
                $old_status = $cylinder_info['status'];
                $old_quantity = $cylinder_info['available_quantity'];
                $new_quantity = $old_quantity;
                $new_status = $old_status;
                
                switch ($operation) {
                    case 'activate':
                        executeQuery("UPDATE cylinder_inventory SET status = 'active', updated_at = NOW() WHERE id = ?", [$cylinder_id]);
                        $new_status = 'active';
                        $operation_details[] = "Activated: " . $cylinder_info['cylinder_type'];
                        break;
                        
                    case 'deactivate':
                        executeQuery("UPDATE cylinder_inventory SET status = 'inactive', updated_at = NOW() WHERE id = ?", [$cylinder_id]);
                        $new_status = 'inactive';
                        $operation_details[] = "Deactivated: " . $cylinder_info['cylinder_type'];
                        break;
                        
                    case 'low_stock':
                        executeQuery("UPDATE cylinder_inventory SET available_quantity = 5, updated_at = NOW() WHERE id = ?", [$cylinder_id]);
                        $new_quantity = 5;
                        $operation_details[] = "Set low stock (5 units): " . $cylinder_info['cylinder_type'];
                        break;
                        
                    case 'restock':
                        executeQuery("UPDATE cylinder_inventory SET available_quantity = total_quantity, updated_at = NOW() WHERE id = ?", [$cylinder_id]);
                        $new_quantity = $cylinder_info['total_quantity'];
                        $operation_details[] = "Restocked to full capacity: " . $cylinder_info['cylinder_type'];
                        break;
                        
                    default:
                        throw new Exception('Invalid bulk operation selected.');
                }
                
                // Log the change if inventory_logs table exists
                if ($operation === 'low_stock' || $operation === 'restock') {
                    try {
                        $log_sql = "INSERT INTO inventory_logs (cylinder_id, action_type, old_quantity, new_quantity, changed_by, notes) 
                                   VALUES (?, 'bulk_update', ?, ?, ?, ?)";
                        executeQuery($log_sql, [
                            $cylinder_id,
                            $old_quantity,
                            $new_quantity,
                            $current_user['id'],
                            "Bulk operation: {$operation}"
                        ]);
                    } catch (Exception $e) {
                        // Continue if logging fails
                    }
                }
                
                $updated_count++;
            }
            
            if ($updated_count > 0) {
                $message = "Bulk operation completed successfully! Updated {$updated_count} cylinder" . 
                          ($updated_count === 1 ? '' : 's') . ".\n\nDetails:\n" . 
                          implode("\n", array_slice($operation_details, 0, 10)) . 
                          ($updated_count > 10 ? "\n... and " . ($updated_count - 10) . " more." : "");
            } else {
                $error = 'No cylinders were updated. Please check your selection.';
            }
        }
        
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Fetch current inventory
try {
    $sql = "SELECT *, 
            CASE 
                WHEN available_quantity <= min_stock_alert THEN 'low'
                WHEN available_quantity <= (total_quantity * 0.3) THEN 'medium'
                ELSE 'good'
            END as stock_level
            FROM cylinder_inventory 
            ORDER BY cylinder_type";
    $stmt = executeQuery($sql);
    $inventory = $stmt->fetchAll();
} catch (Exception $e) {
    $error = "Error fetching inventory: " . $e->getMessage();
    $inventory = [];
}

// Get inventory statistics
try {
    $stats_sql = "SELECT 
                    COUNT(*) as total_types,
                    SUM(total_quantity) as total_cylinders,
                    SUM(available_quantity) as available_cylinders,
                    COUNT(CASE WHEN status = 'active' THEN 1 END) as active_types,
                    COUNT(CASE WHEN available_quantity <= min_stock_alert THEN 1 END) as low_stock_types
                  FROM cylinder_inventory";
    $stats_stmt = executeQuery($stats_sql);
    $stats = $stats_stmt->fetch();
} catch (Exception $e) {
    $stats = ['total_types' => 0, 'total_cylinders' => 0, 'available_cylinders' => 0, 'active_types' => 0, 'low_stock_types' => 0];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cylinder Inventory Management - Admin Panel</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #ff6b00;
            --secondary: #2c3e50;
            --success: #27ae60;
            --danger: #e74c3c;
            --warning: #f39c12;
            --info: #3498db;
            --dark: #1a1a1a;
            --light-gray: #f8f9fa;
            --medium-gray: #6c757d;
            --border-color: #dee2e6;
            --shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            --shadow-lg: 0 10px 30px rgba(0, 0, 0, 0.15);
            --glass-effect: rgba(255, 255, 255, 0.25);
            --glass-border: rgba(255, 255, 255, 0.18);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background: linear-gradient(135deg, #FF8C61 0%, #FF6B35 50%, #FF5722 100%);
            background-attachment: fixed;
            min-height: 100vh;
            color: #333;
        }

        .header {
            background: transparent;
            backdrop-filter: none;
            border-bottom: none;
            padding: 1rem 0;
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .header-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .logo {
            color: white;
            font-size: 1.5rem;
            font-weight: 700;
            text-decoration: none;
        }

        .nav-links {
            display: flex;
            gap: 1rem;
        }

        .nav-link {
            color: white;
            text-decoration: none;
            padding: 0.5rem 1rem;
            border-radius: 8px;
            transition: background 0.3s ease;
        }

        .nav-link:hover {
            background: rgba(255, 255, 255, 0.1);
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 2rem;
        }

        .page-title {
            text-align: center;
            color: white;
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }

        .page-subtitle {
            text-align: center;
            color: rgba(255, 255, 255, 0.8);
            font-size: 1.1rem;
            margin-bottom: 3rem;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 3rem;
        }

        .stat-card {
            background: var(--glass-effect);
            backdrop-filter: blur(20px);
            border: 1px solid var(--glass-border);
            border-radius: 16px;
            padding: 1.5rem;
            text-align: center;
            color: white;
        }

        .stat-icon {
            font-size: 2rem;
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
        }

        .alert {
            padding: 1rem;
            border-radius: 12px;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .alert-success {
            background: rgba(39, 174, 96, 0.1);
            color: #27ae60;
            border: 1px solid rgba(39, 174, 96, 0.3);
        }

        .alert-error {
            background: rgba(231, 76, 60, 0.1);
            color: #e74c3c;
            border: 1px solid rgba(231, 76, 60, 0.3);
        }

        .section {
            background: var(--glass-effect);
            backdrop-filter: blur(20px);
            border: 1px solid var(--glass-border);
            border-radius: 20px;
            padding: 2rem;
            margin-bottom: 2rem;
        }

        .section-title {
            color: white;
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .form-group {
            margin-bottom: 1rem;
        }

        .form-group label {
            display: block;
            color: white;
            font-weight: 500;
            margin-bottom: 0.5rem;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid rgba(255, 255, 255, 0.3);
            border-radius: 8px;
            background: rgba(255, 255, 255, 0.1);
            color: white;
            font-size: 1rem;
        }

        .form-group input::placeholder,
        .form-group textarea::placeholder {
            color: rgba(255, 255, 255, 0.6);
        }

        .btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 500;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s ease;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-success {
            background: var(--success);
            color: white;
        }

        .btn-warning {
            background: var(--warning);
            color: white;
        }

        .btn-danger {
            background: var(--danger);
            color: white;
        }

        .btn-secondary {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            border: 1px solid rgba(255, 255, 255, 0.3);
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
        }

        .inventory-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1rem;
        }

        .inventory-table th,
        .inventory-table td {
            padding: 1rem;
            text-align: left;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            color: white;
        }

        .inventory-table th {
            background: rgba(255, 255, 255, 0.1);
            font-weight: 600;
            position: sticky;
            top: 0;
        }

        .inventory-table tr:hover {
            background: rgba(255, 255, 255, 0.05);
        }

        .stock-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
            text-transform: uppercase;
        }

        .stock-good {
            background: rgba(39, 174, 96, 0.2);
            color: #27ae60;
            border: 1px solid rgba(39, 174, 96, 0.5);
        }

        .stock-medium {
            background: rgba(243, 156, 18, 0.2);
            color: #f39c12;
            border: 1px solid rgba(243, 156, 18, 0.5);
        }

        .stock-low {
            background: rgba(231, 76, 60, 0.2);
            color: #e74c3c;
            border: 1px solid rgba(231, 76, 60, 0.5);
        }

        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
            text-transform: uppercase;
        }

        .status-active {
            background: rgba(39, 174, 96, 0.2);
            color: #27ae60;
            border: 1px solid rgba(39, 174, 96, 0.5);
        }

        .status-inactive {
            background: rgba(149, 165, 166, 0.2);
            color: #95a5a6;
            border: 1px solid rgba(149, 165, 166, 0.5);
        }

        .actions {
            display: flex;
            gap: 0.5rem;
            align-items: center;
        }

        .btn-sm {
            padding: 0.25rem 0.5rem;
            font-size: 0.8rem;
        }

        .bulk-actions {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1rem;
            padding: 1.5rem;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 12px;
            border: 1px solid rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
        }

        .bulk-actions label {
            font-weight: 600;
            white-space: nowrap;
        }

        .bulk-actions select {
            min-width: 180px;
            background: rgba(255, 255, 255, 0.1) !important;
            color: white !important;
            border: 1px solid rgba(255, 255, 255, 0.3) !important;
            border-radius: 8px !important;
            padding: 0.75rem !important;
            font-size: 0.9rem;
        }

        .bulk-actions select option {
            background: #2c3e50;
            color: white;
            padding: 0.5rem;
        }

        .bulk-actions button {
            white-space: nowrap;
        }

        .bulk-actions button:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        #selected-count {
            font-size: 0.9rem;
            padding: 0.5rem 1rem;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 20px;
            border: 1px solid rgba(255, 255, 255, 0.2);
            white-space: nowrap;
        }

        .progress-bar {
            width: 100%;
            height: 8px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 4px;
            overflow: hidden;
            margin-top: 0.5rem;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--success), var(--info));
            transition: width 0.3s ease;
        }

        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.8);
            backdrop-filter: blur(5px);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }

        .modal.active {
            display: flex;
        }

        .modal-content {
            background: var(--glass-effect);
            backdrop-filter: blur(30px);
            border: 1px solid var(--glass-border);
            border-radius: 20px;
            padding: 2rem;
            max-width: 600px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }

        .modal-title {
            color: white;
            font-size: 1.5rem;
            font-weight: 600;
        }

        .close-btn {
            background: none;
            border: none;
            color: white;
            font-size: 1.5rem;
            cursor: pointer;
        }

        @media (max-width: 768px) {
            .container {
                padding: 1rem;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }

            .inventory-table {
                font-size: 0.8rem;
            }

            .inventory-table th,
            .inventory-table td {
                padding: 0.5rem;
            }

            .bulk-actions {
                flex-direction: column;
                align-items: stretch;
            }
        }
    </style>
</head>
<body>
    <!-- Header -->
    <header class="header">
        <div class="header-container">
            <a href="../admin-portal.php" class="logo">
                <i class="fas fa-fire"></i> LIAA Admin
            </a>
            <nav class="nav-links">
                <a href="../admin-portal.php" class="nav-link">
                    <i class="fas fa-dashboard"></i> Dashboard
                </a>
                <a href="../manage-users.php" class="nav-link">
                    <i class="fas fa-users"></i> Users
                </a>
                <a href="cylinder-inventory.php" class="nav-link">
                    <i class="fas fa-boxes"></i> Inventory
                </a>
                <a href="../admin-portal.php?logout=1" class="nav-link">
                    <i class="fas fa-sign-out-alt"></i> Logout
                </a>
            </nav>
        </div>
    </header>

    <div class="container">
        <h1 class="page-title">
            <i class="fas fa-boxes"></i> Cylinder Inventory Management
        </h1>
        <p class="page-subtitle">Manage gas cylinder availability, pricing, and stock levels</p>

        <!-- Alert Messages -->
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

        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-layer-group"></i></div>
                <div class="stat-number"><?php echo $stats['total_types']; ?></div>
                <div class="stat-label">Cylinder Types</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-box"></i></div>
                <div class="stat-number"><?php echo $stats['total_cylinders']; ?></div>
                <div class="stat-label">Total Inventory</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
                <div class="stat-number"><?php echo $stats['available_cylinders']; ?></div>
                <div class="stat-label">Available Now</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-play"></i></div>
                <div class="stat-number"><?php echo $stats['active_types']; ?></div>
                <div class="stat-label">Active Types</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon"><i class="fas fa-exclamation-triangle"></i></div>
                <div class="stat-number"><?php echo $stats['low_stock_types']; ?></div>
                <div class="stat-label">Low Stock Alerts</div>
            </div>
        </div>

        <!-- Add New Cylinder -->
        <div class="section">
            <h2 class="section-title">
                <i class="fas fa-plus"></i> Add New Cylinder Type
            </h2>
            <form method="POST">
                <div class="form-grid">
                    <div class="form-group">
                        <label>Cylinder Type *</label>
                        <input type="text" name="cylinder_type" required placeholder="e.g., 14.2kg Domestic">
                    </div>
                    <div class="form-group">
                        <label>Unit Price (₹) *</label>
                        <input type="number" name="unit_price" step="0.01" required placeholder="899.00">
                    </div>
                    <div class="form-group">
                        <label>Total Quantity *</label>
                        <input type="number" name="total_quantity" required placeholder="100">
                    </div>
                    <div class="form-group">
                        <label>Available Quantity *</label>
                        <input type="number" name="available_quantity" required placeholder="85">
                    </div>
                    <div class="form-group">
                        <label>Low Stock Alert</label>
                        <input type="number" name="min_stock_alert" value="10" placeholder="10">
                    </div>
                </div>
                <div class="form-group">
                    <label>Description</label>
                    <textarea name="description" rows="3" placeholder="Brief description of the cylinder type"></textarea>
                </div>
                <button type="submit" name="add_cylinder" class="btn btn-success">
                    <i class="fas fa-plus"></i> Add Cylinder Type
                </button>
            </form>
        </div>

        <!-- Inventory Management -->
        <div class="section">
            <h2 class="section-title">
                <i class="fas fa-warehouse"></i> Current Inventory
            </h2>

            <!-- Single form that wraps both bulk actions and table -->
            <form method="POST" id="inventoryForm">
                <!-- Bulk Actions -->
                <div class="bulk-actions">
                    <label style="color: white;">Bulk Actions:</label>
                    <select name="bulk_operation" style="background: rgba(255, 255, 255, 0.1); color: white; border: 1px solid rgba(255, 255, 255, 0.3); border-radius: 4px; padding: 0.5rem;">
                        <option value="">Select Action...</option>
                        <option value="activate">Activate Selected</option>
                        <option value="deactivate">Deactivate Selected</option>
                        <option value="low_stock">Set Low Stock (5 units)</option>
                        <option value="restock">Full Restock</option>
                    </select>
                    <button type="submit" name="bulk_update" class="btn btn-secondary btn-sm" onclick="return confirmBulkAction()">
                        <i class="fas fa-tasks"></i> Apply
                    </button>
                    <span id="selected-count" style="color: white; margin-left: 1rem;">0 items selected</span>
                </div>

                <div style="overflow-x: auto;">
                    <table class="inventory-table">
                        <thead>
                            <tr>
                                <th><input type="checkbox" id="selectAll"></th>
                                <th>Cylinder Type</th>
                                <th>Price</th>
                                <th>Stock Status</th>
                                <th>Available/Total</th>
                                <th>Availability %</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($inventory as $item): ?>
                                <?php 
                                $availability_percent = $item['total_quantity'] > 0 ? 
                                    ($item['available_quantity'] / $item['total_quantity']) * 100 : 0;
                                ?>
                                <tr>
                                    <td>
                                        <input type="checkbox" name="selected_cylinders[]" value="<?php echo $item['id']; ?>" class="cylinder-checkbox">
                                    </td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($item['cylinder_type']); ?></strong>
                                        <?php if ($item['description']): ?>
                                            <br><small style="opacity: 0.7;"><?php echo htmlspecialchars($item['description']); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>₹<?php echo number_format($item['unit_price'], 2); ?></td>
                                    <td>
                                        <span class="stock-badge stock-<?php echo $item['stock_level']; ?>">
                                            <?php echo ucfirst($item['stock_level']); ?> Stock
                                        </span>
                                    </td>
                                    <td>
                                        <?php echo $item['available_quantity']; ?> / <?php echo $item['total_quantity']; ?>
                                        <div class="progress-bar">
                                            <div class="progress-fill" style="width: <?php echo $availability_percent; ?>%"></div>
                                        </div>
                                    </td>
                                    <td><?php echo number_format($availability_percent, 1); ?>%</td>
                                    <td>
                                        <span class="status-badge status-<?php echo $item['status']; ?>">
                                            <?php echo ucfirst($item['status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="actions">
                                            <button type="button" onclick="editCylinder(<?php echo htmlspecialchars(json_encode($item)); ?>)" class="btn btn-primary btn-sm">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button type="button" onclick="viewHistory(<?php echo $item['id']; ?>)" class="btn btn-secondary btn-sm">
                                                <i class="fas fa-history"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Cylinder Modal -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Edit Cylinder Inventory</h3>
                <button class="close-btn" onclick="closeModal('editModal')">&times;</button>
            </div>
            <form method="POST">
                <input type="hidden" name="cylinder_id" id="edit_cylinder_id">
                <div class="form-grid">
                    <div class="form-group">
                        <label>Cylinder Type</label>
                        <input type="text" id="edit_cylinder_type" readonly style="background: rgba(255, 255, 255, 0.05);">
                    </div>
                    <div class="form-group">
                        <label>Unit Price (₹)</label>
                        <input type="number" name="unit_price" id="edit_unit_price" step="0.01" required>
                    </div>
                    <div class="form-group">
                        <label>Total Quantity</label>
                        <input type="number" name="total_quantity" id="edit_total_quantity" required>
                    </div>
                    <div class="form-group">
                        <label>Available Quantity</label>
                        <input type="number" name="available_quantity" id="edit_available_quantity" required>
                    </div>
                    <div class="form-group">
                        <label>Low Stock Alert</label>
                        <input type="number" name="min_stock_alert" id="edit_min_stock_alert">
                    </div>
                    <div class="form-group">
                        <label>Status</label>
                        <select name="status" id="edit_status">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                </div>
                <div style="display: flex; gap: 1rem; margin-top: 1.5rem;">
                    <button type="submit" name="update_inventory" class="btn btn-success">
                        <i class="fas fa-save"></i> Update Inventory
                    </button>
                    <button type="button" onclick="closeModal('editModal')" class="btn btn-secondary">
                        Cancel
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Select all functionality
        document.getElementById('selectAll').addEventListener('change', function() {
            const checkboxes = document.querySelectorAll('.cylinder-checkbox');
            checkboxes.forEach(checkbox => {
                checkbox.checked = this.checked;
            });
            updateSelectedCount();
        });

        // Individual checkbox change handler
        document.querySelectorAll('.cylinder-checkbox').forEach(checkbox => {
            checkbox.addEventListener('change', function() {
                updateSelectedCount();
                
                // Update select all checkbox state
                const allCheckboxes = document.querySelectorAll('.cylinder-checkbox');
                const checkedBoxes = document.querySelectorAll('.cylinder-checkbox:checked');
                const selectAllCheckbox = document.getElementById('selectAll');
                
                if (checkedBoxes.length === 0) {
                    selectAllCheckbox.indeterminate = false;
                    selectAllCheckbox.checked = false;
                } else if (checkedBoxes.length === allCheckboxes.length) {
                    selectAllCheckbox.indeterminate = false;
                    selectAllCheckbox.checked = true;
                } else {
                    selectAllCheckbox.indeterminate = true;
                }
            });
        });

        // Update selected count display
        function updateSelectedCount() {
            const checkedBoxes = document.querySelectorAll('.cylinder-checkbox:checked');
            const countElement = document.getElementById('selected-count');
            const count = checkedBoxes.length;
            
            if (count === 0) {
                countElement.textContent = '0 items selected';
                countElement.style.color = 'rgba(255, 255, 255, 0.6)';
            } else {
                countElement.textContent = `${count} item${count === 1 ? '' : 's'} selected`;
                countElement.style.color = '#27ae60';
                countElement.style.fontWeight = '600';
            }
        }

        // Confirm bulk action before submission
        function confirmBulkAction() {
            const checkedBoxes = document.querySelectorAll('.cylinder-checkbox:checked');
            const operation = document.querySelector('select[name="bulk_operation"]').value;
            
            if (checkedBoxes.length === 0) {
                alert('Please select at least one cylinder to perform bulk action.');
                return false;
            }
            
            if (!operation) {
                alert('Please select a bulk action to perform.');
                return false;
            }
            
            let actionText = '';
            switch (operation) {
                case 'activate':
                    actionText = 'activate';
                    break;
                case 'deactivate':
                    actionText = 'deactivate';
                    break;
                case 'low_stock':
                    actionText = 'set to low stock (5 units)';
                    break;
                case 'restock':
                    actionText = 'fully restock';
                    break;
            }
            
            const cylinderNames = Array.from(checkedBoxes).map(cb => {
                const row = cb.closest('tr');
                return row.querySelector('strong').textContent;
            });
            
            let confirmMessage = `Are you sure you want to ${actionText} the following ${checkedBoxes.length} cylinder${checkedBoxes.length === 1 ? '' : 's'}?\n\n`;
            confirmMessage += cylinderNames.join('\n');
            
            if (operation === 'low_stock') {
                confirmMessage += '\n\nThis will set available quantity to 5 units for selected cylinders.';
            } else if (operation === 'restock') {
                confirmMessage += '\n\nThis will set available quantity to maximum capacity for selected cylinders.';
            }
            
            return confirm(confirmMessage);
        }

        // Edit cylinder function
        function editCylinder(cylinder) {
            document.getElementById('edit_cylinder_id').value = cylinder.id;
            document.getElementById('edit_cylinder_type').value = cylinder.cylinder_type;
            document.getElementById('edit_unit_price').value = cylinder.unit_price;
            document.getElementById('edit_total_quantity').value = cylinder.total_quantity;
            document.getElementById('edit_available_quantity').value = cylinder.available_quantity;
            document.getElementById('edit_min_stock_alert').value = cylinder.min_stock_alert || 10;
            document.getElementById('edit_status').value = cylinder.status;
            
            document.getElementById('editModal').classList.add('active');
        }

        // Close modal function
        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('active');
        }

        // View history function (enhanced)
        function viewHistory(cylinderId) {
            // For now, show a more informative message
            const row = document.querySelector(`input[value="${cylinderId}"]`).closest('tr');
            const cylinderName = row.querySelector('strong').textContent;
            const available = row.cells[4].textContent.split(' / ')[0];
            const total = row.cells[4].textContent.split(' / ')[1].split(' ')[0];
            const status = row.querySelector('.status-badge').textContent.trim();
            
            alert(`Inventory History for ${cylinderName}\n\nCurrent Status: ${status}\nAvailable: ${available} units\nTotal Capacity: ${total} units\n\nDetailed history tracking coming soon!`);
        }

        // Close modal when clicking outside
        document.querySelectorAll('.modal').forEach(modal => {
            modal.addEventListener('click', function(e) {
                if (e.target === this) {
                    this.classList.remove('active');
                }
            });
        });

        // Auto-refresh every 60 seconds (increased from 30 to reduce server load)
        setInterval(() => {
            if (!document.querySelector('.modal.active')) {
                // Only refresh if no bulk action is in progress
                const checkedBoxes = document.querySelectorAll('.cylinder-checkbox:checked');
                if (checkedBoxes.length === 0) {
                    location.reload();
                }
            }
        }, 60000);

        // Initialize selected count on page load
        document.addEventListener('DOMContentLoaded', function() {
            updateSelectedCount();
        });

        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl+A to select all
            if (e.ctrlKey && e.key === 'a' && !e.target.matches('input, textarea, select')) {
                e.preventDefault();
                document.getElementById('selectAll').click();
            }
            
            // Escape to close modals
            if (e.key === 'Escape') {
                document.querySelectorAll('.modal.active').forEach(modal => {
                    modal.classList.remove('active');
                });
            }
        });
    </script>
</body>
</html>