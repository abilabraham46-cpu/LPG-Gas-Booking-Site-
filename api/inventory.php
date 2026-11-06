<?php
// Ensure all errors are caught and returned as JSON
error_reporting(E_ALL);
ini_set('display_errors', 0);

// Custom error handler to convert all errors to JSON
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error: ' . $errstr,
        'file' => basename($errfile),
        'line' => $errline
    ]);
    exit;
});

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type');

try {
    require_once '../config/database.php';
    require_once '../includes/auth.php';
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => 'Failed to load required files: ' . $e->getMessage()
    ]);
    exit;
}

// Start session only if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$auth = new Auth();

// Check if user is logged in
if (!$auth->isLoggedIn()) {
    // For GET requests (viewing inventory), allow unauthenticated access
    // Only POST/PUT/DELETE require authentication
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized access']);
        exit;
    }
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    switch ($action) {
        case 'get_availability':
            // Check if cylinder_inventory table exists
            $checkTable = "SHOW TABLES LIKE 'cylinder_inventory'";
            $result = executeQuery($checkTable);
            
            if ($result->rowCount() === 0) {
                echo json_encode([
                    'success' => false,
                    'error' => 'Inventory table not found. Please contact administrator.',
                    'cylinders' => [],
                    'timestamp' => time()
                ]);
                break;
            }
            
            // Get real-time cylinder availability
            $sql = "SELECT 
                        id,
                        cylinder_type,
                        unit_price,
                        total_quantity,
                        available_quantity,
                        min_stock_alert,
                        status,
                        description,
                        CASE 
                            WHEN available_quantity <= min_stock_alert THEN 'low'
                            WHEN available_quantity <= (total_quantity * 0.3) THEN 'medium'
                            ELSE 'good'
                        END as stock_level,
                        ROUND((available_quantity / total_quantity) * 100, 1) as availability_percent,
                        updated_at
                    FROM cylinder_inventory 
                    WHERE status = 'active' 
                    ORDER BY 
                        CASE 
                            WHEN available_quantity > 0 THEN 0 
                            ELSE 1 
                        END,
                        unit_price";
            
            $stmt = executeQuery($sql);
            $cylinders = $stmt->fetchAll();
            
            echo json_encode([
                'success' => true,
                'cylinders' => $cylinders,
                'timestamp' => time()
            ]);
            break;
            
        case 'check_single_availability':
            // Check availability for a specific cylinder type
            $cylinder_type = $_GET['cylinder_type'] ?? '';
            $quantity = intval($_GET['quantity'] ?? 1);
            
            if (empty($cylinder_type)) {
                throw new Exception('Cylinder type is required');
            }
            
            $sql = "SELECT 
                        available_quantity,
                        unit_price,
                        total_quantity,
                        status,
                        CASE 
                            WHEN available_quantity <= min_stock_alert THEN 'low'
                            WHEN available_quantity <= (total_quantity * 0.3) THEN 'medium'
                            ELSE 'good'
                        END as stock_level
                    FROM cylinder_inventory 
                    WHERE cylinder_type = ? AND status = 'active'";
            
            $stmt = executeQuery($sql, [$cylinder_type]);
            $cylinder = $stmt->fetch();
            
            if (!$cylinder) {
                echo json_encode([
                    'success' => false,
                    'available' => false,
                    'message' => 'Cylinder type not found or inactive'
                ]);
                break;
            }
            
            $is_available = $cylinder['available_quantity'] >= $quantity;
            $total_price = $cylinder['unit_price'] * $quantity;
            
            echo json_encode([
                'success' => true,
                'available' => $is_available,
                'available_quantity' => $cylinder['available_quantity'],
                'requested_quantity' => $quantity,
                'unit_price' => $cylinder['unit_price'],
                'total_price' => $total_price,
                'stock_level' => $cylinder['stock_level'],
                'message' => $is_available ? 
                    'Available for booking' : 
                    "Only {$cylinder['available_quantity']} units available"
            ]);
            break;
            
        case 'reserve_cylinder':
            // Temporarily reserve cylinders for checkout (5 minutes)
            if (!$auth->isLoggedIn()) {
                throw new Exception('Authentication required');
            }
            
            $customer_id = $auth->getCurrentUser()['id'];
            $cylinder_type = $_POST['cylinder_type'] ?? '';
            $quantity = intval($_POST['quantity'] ?? 1);
            
            if (empty($cylinder_type) || $quantity <= 0) {
                throw new Exception('Invalid cylinder type or quantity');
            }
            
            // Check current availability
            $check_sql = "SELECT available_quantity FROM cylinder_inventory 
                         WHERE cylinder_type = ? AND status = 'active' FOR UPDATE";
            $check_stmt = executeQuery($check_sql, [$cylinder_type]);
            $current = $check_stmt->fetch();
            
            if (!$current || $current['available_quantity'] < $quantity) {
                echo json_encode([
                    'success' => false,
                    'message' => 'Insufficient stock available'
                ]);
                break;
            }
            
            // Create temporary reservation
            $reservation_sql = "INSERT INTO cylinder_reservations 
                               (customer_id, cylinder_type, quantity, expires_at) 
                               VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 5 MINUTE))
                               ON DUPLICATE KEY UPDATE 
                               quantity = VALUES(quantity),
                               expires_at = VALUES(expires_at)";
            
            try {
                executeQuery($reservation_sql, [$customer_id, $cylinder_type, $quantity]);
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Cylinders reserved for 5 minutes',
                    'expires_in' => 300 // seconds
                ]);
            } catch (Exception $e) {
                // Table might not exist, continue without reservation
                echo json_encode([
                    'success' => true,
                    'message' => 'Ready for booking'
                ]);
            }
            break;
            
        case 'get_low_stock_alerts':
            // Get cylinders with low stock (admin feature)
            $current_user = $auth->getCurrentUser();
            if ($current_user['role'] !== 'admin') {
                throw new Exception('Admin access required');
            }
            
            $sql = "SELECT 
                        cylinder_type,
                        available_quantity,
                        min_stock_alert,
                        total_quantity
                    FROM cylinder_inventory 
                    WHERE available_quantity <= min_stock_alert 
                    AND status = 'active'
                    ORDER BY available_quantity ASC";
            
            $stmt = executeQuery($sql);
            $low_stock = $stmt->fetchAll();
            
            echo json_encode([
                'success' => true,
                'low_stock_items' => $low_stock,
                'count' => count($low_stock)
            ]);
            break;
            
        case 'update_inventory':
            // Quick inventory update (admin only)
            $current_user = $auth->getCurrentUser();
            if ($current_user['role'] !== 'admin') {
                throw new Exception('Admin access required');
            }
            
            $cylinder_id = intval($_POST['cylinder_id'] ?? 0);
            $new_quantity = intval($_POST['quantity'] ?? 0);
            $action_type = $_POST['action_type'] ?? 'manual_update';
            
            if ($cylinder_id <= 0 || $new_quantity < 0) {
                throw new Exception('Invalid cylinder ID or quantity');
            }
            
            // Get current quantity for logging
            $current_sql = "SELECT available_quantity, cylinder_type FROM cylinder_inventory WHERE id = ?";
            $current_stmt = executeQuery($current_sql, [$cylinder_id]);
            $current_data = $current_stmt->fetch();
            
            if (!$current_data) {
                throw new Exception('Cylinder not found');
            }
            
            // Update quantity
            $update_sql = "UPDATE cylinder_inventory 
                          SET available_quantity = ?, updated_at = NOW() 
                          WHERE id = ?";
            executeQuery($update_sql, [$new_quantity, $cylinder_id]);
            
            // Log the change
            $log_sql = "INSERT INTO inventory_logs 
                       (cylinder_id, action_type, old_quantity, new_quantity, changed_by, notes) 
                       VALUES (?, ?, ?, ?, ?, ?)";
            
            try {
                executeQuery($log_sql, [
                    $cylinder_id,
                    $action_type,
                    $current_data['available_quantity'],
                    $new_quantity,
                    $current_user['id'],
                    "API update: {$action_type}"
                ]);
            } catch (Exception $e) {
                // Continue if logging fails
            }
            
            echo json_encode([
                'success' => true,
                'message' => "Updated {$current_data['cylinder_type']} inventory",
                'old_quantity' => $current_data['available_quantity'],
                'new_quantity' => $new_quantity
            ]);
            break;
            
        default:
            throw new Exception('Invalid action specified');
    }
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>