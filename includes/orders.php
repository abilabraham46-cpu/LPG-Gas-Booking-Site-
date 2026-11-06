<?php
/**
 * Order Management System for LIAA LPG Gas Booking System
 * Handles gas cylinder orders, delivery scheduling, and order tracking
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/utils.php';

class OrderManager {
    private $pdo;
    
    public function __construct() {
        $this->pdo = getDBConnection();
    }
    
    /**
     * Create new gas order
     * @param array $orderData
     * @return array|false
     */
    public function createOrder($orderData) {
        try {
            // Validate required fields
            $required = ['customer_id', 'cylinder_type', 'quantity', 'delivery_address', 'delivery_date'];
            $errors = validateRequiredFields($orderData, $required);
            
            if (!empty($errors)) {
                throw new Exception(implode(', ', $errors));
            }
            
            // Generate order number
            $orderNumber = generateOrderNumber();
            
            // Calculate total amount
            $cylinderPrice = $this->getCylinderPrice($orderData['cylinder_type']);
            $totalAmount = $cylinderPrice * $orderData['quantity'];
            
            // Add delivery fee if applicable
            if (isset($orderData['delivery_fee'])) {
                $totalAmount += $orderData['delivery_fee'];
            }
            
            // Insert order
            $sql = "INSERT INTO orders (order_number, customer_id, cylinder_type, quantity, 
                    delivery_address, delivery_date, delivery_time, special_instructions, 
                    total_amount, status, created_at) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())";
            
            $orderId = insertData($sql, [
                $orderNumber,
                $orderData['customer_id'],
                $orderData['cylinder_type'],
                $orderData['quantity'],
                $orderData['delivery_address'],
                $orderData['delivery_date'],
                $orderData['delivery_time'] ?? null,
                $orderData['special_instructions'] ?? null,
                $totalAmount
            ]);
            
            if ($orderId) {
                // Log activity
                logActivity('order_created', "Order #$orderNumber created", $orderData['customer_id'], 'customer');
                
                // Return order data
                return $this->getOrderById($orderId);
            }
            
            return false;
        } catch (Exception $e) {
            error_log("Error creating order: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Get order by ID
     * @param int $orderId
     * @return array|false
     */
    public function getOrderById($orderId) {
        try {
            $sql = "SELECT o.*, u.name as customer_name, u.phone as customer_phone, u.email as customer_email
                    FROM orders o 
                    JOIN users u ON o.customer_id = u.id 
                    WHERE o.id = ?";
            
            return fetchOne($sql, [$orderId]);
        } catch (Exception $e) {
            error_log("Error getting order by ID: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get order by order number
     * @param string $orderNumber
     * @return array|false
     */
    public function getOrderByNumber($orderNumber) {
        try {
            $sql = "SELECT o.*, u.name as customer_name, u.phone as customer_phone, u.email as customer_email
                    FROM orders o 
                    JOIN users u ON o.customer_id = u.id 
                    WHERE o.order_number = ?";
            
            return fetchOne($sql, [$orderNumber]);
        } catch (Exception $e) {
            error_log("Error getting order by number: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get customer orders
     * @param int $customerId
     * @param string $status
     * @param int $page
     * @param int $limit
     * @return array
     */
    public function getCustomerOrders($customerId, $status = null, $page = 1, $limit = 10) {
        try {
            $offset = ($page - 1) * $limit;
            
            $whereClause = "WHERE o.customer_id = ?";
            $params = [$customerId];
            
            if ($status) {
                $whereClause .= " AND o.status = ?";
                $params[] = $status;
            }
            
            // Get total count
            $countSql = "SELECT COUNT(*) as total FROM orders o $whereClause";
            $total = getCount($countSql, $params);
            
            // Get orders
            $sql = "SELECT o.*, u.name as customer_name,
                    COALESCE(o.payment_method, 'cod') as payment_method,
                    COALESCE(o.payment_status, 'pending') as payment_status,
                    o.payment_reference,
                    COALESCE(o.payment_amount, o.total_amount) as payment_amount,
                    o.paid_at
                    FROM orders o 
                    JOIN users u ON o.customer_id = u.id 
                    $whereClause 
                    ORDER BY o.created_at DESC 
                    LIMIT ? OFFSET ?";
            
            $params[] = $limit;
            $params[] = $offset;
            
            $orders = fetchAll($sql, $params);
            
            return [
                'orders' => $orders,
                'total' => $total,
                'pages' => ceil($total / $limit),
                'current_page' => $page
            ];
        } catch (Exception $e) {
            error_log("Error getting customer orders: " . $e->getMessage());
            return ['orders' => [], 'total' => 0, 'pages' => 0, 'current_page' => 1];
        }
    }
    
    /**
     * Get all orders (admin/delivery)
     * @param string $status
     * @param int $page
     * @param int $limit
     * @return array
     */
    public function getAllOrders($status = null, $page = 1, $limit = 20) {
        try {
            $offset = ($page - 1) * $limit;
            
            $whereClause = "";
            $params = [];
            
            if ($status) {
                $whereClause = "WHERE o.status = ?";
                $params[] = $status;
            }
            
            // Get total count
            $countSql = "SELECT COUNT(*) as total FROM orders o $whereClause";
            $total = getCount($countSql, $params);
            
            // Get orders
            $sql = "SELECT o.*, u.name as customer_name, u.phone as customer_phone, u.email as customer_email
                    FROM orders o 
                    JOIN users u ON o.customer_id = u.id 
                    $whereClause 
                    ORDER BY o.created_at DESC 
                    LIMIT ? OFFSET ?";
            
            $params[] = $limit;
            $params[] = $offset;
            
            $orders = fetchAll($sql, $params);
            
            return [
                'orders' => $orders,
                'total' => $total,
                'pages' => ceil($total / $limit),
                'current_page' => $page
            ];
        } catch (Exception $e) {
            error_log("Error getting all orders: " . $e->getMessage());
            return ['orders' => [], 'total' => 0, 'pages' => 0, 'current_page' => 1];
        }
    }
    
    /**
     * Update order status
     * @param int $orderId
     * @param string $status
     * @param string $notes
     * @param int $userId
     * @return bool
     */
    public function updateOrderStatus($orderId, $status, $notes = null, $userId = null) {
        try {
            // First check if the order is already cancelled
            $checkSql = "SELECT status, customer_id FROM orders WHERE id = ?";
            $currentOrder = fetchOne($checkSql, [$orderId]);
            
            if (!$currentOrder) {
                error_log("Update order status failed: Order not found. Order ID: $orderId");
                return false;
            }
            
            // Prevent updating cancelled orders
            if ($currentOrder['status'] === 'cancelled') {
                error_log("Update order status failed: Cannot update cancelled order. Order ID: $orderId, Attempted status: $status");
                return false;
            }
            
            $sql = "UPDATE orders SET status = ?, updated_at = NOW() WHERE id = ?";
            $params = [$status, $orderId];
            
            if ($notes) {
                $sql = "UPDATE orders SET status = ?, special_instructions = ?, updated_at = NOW() WHERE id = ?";
                $params = [$status, $notes, $orderId];
            }
            
            $result = updateData($sql, $params);
            
            if ($result) {
                // Log status change
                logActivity('order_status_updated', "Order #$orderId status changed to $status", $userId);
                
                // Create notification for customer about status change
                $this->createOrderStatusNotification($orderId, $status, $currentOrder['customer_id']);
                
                // If status is 'assigned', create delivery record
                if ($status === 'assigned' && $userId) {
                    $this->assignDeliveryPerson($orderId, $userId);
                }
                
                // If status is 'delivered', update delivery record
                if ($status === 'delivered') {
                    $this->markOrderDelivered($orderId);
                }
                
                return true;
            }
            
            return false;
        } catch (Exception $e) {
            error_log("Error updating order status: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Create notification for customer when order status changes
     * @param int $orderId
     * @param string $status
     * @param int $customerId
     */
    private function createOrderStatusNotification($orderId, $status, $customerId) {
        try {
            $notificationTitle = '';
            $notificationMessage = '';
            $notificationType = 'info';
            
            switch ($status) {
                case 'confirmed':
                    $notificationTitle = 'Order Confirmed';
                    $notificationMessage = "Your order #$orderId has been confirmed. We're preparing it for delivery.";
                    $notificationType = 'success';
                    break;
                    
                case 'processing':
                    $notificationTitle = 'Order Processing';
                    $notificationMessage = "Your order #$orderId is now being processed and prepared for delivery.";
                    $notificationType = 'info';
                    break;
                    
                case 'assigned':
                    $notificationTitle = 'Delivery Assigned';
                    $notificationMessage = "A delivery person has been assigned to your order #$orderId.";
                    $notificationType = 'info';
                    break;
                    
                case 'in_transit':
                case 'out_for_delivery':
                    $notificationTitle = 'Out for Delivery';
                    $notificationMessage = "Your order #$orderId is on the way! Expect delivery soon.";
                    $notificationType = 'info';
                    break;
                    
                case 'delivered':
                    $notificationTitle = 'Order Delivered';
                    $notificationMessage = "Your order #$orderId has been successfully delivered!";
                    $notificationType = 'success';
                    break;
                    
                case 'cancelled':
                    $notificationTitle = 'Order Cancelled';
                    $notificationMessage = "Your order #$orderId has been cancelled.";
                    $notificationType = 'error';
                    break;
                    
                case 'failed':
                    $notificationTitle = 'Delivery Failed';
                    $notificationMessage = "Delivery of order #$orderId could not be completed. We'll contact you soon.";
                    $notificationType = 'warning';
                    break;
                    
                default:
                    // Don't create notification for unknown statuses
                    return;
            }
            
            // Check if notifications table exists
            $checkTableSql = "SHOW TABLES LIKE 'notifications'";
            $tableCheck = executeQuery($checkTableSql);
            
            if ($tableCheck->rowCount() == 0) {
                error_log("Notifications table does not exist. Skipping notification creation for order $orderId");
                return;
            }
            
            // Insert notification
            $insertSql = "INSERT INTO notifications (user_id, order_id, title, message, type) VALUES (?, ?, ?, ?, ?)";
            insertData($insertSql, [$customerId, $orderId, $notificationTitle, $notificationMessage, $notificationType]);
            
            error_log("Notification created for customer $customerId about order $orderId status change to $status");
            
        } catch (Exception $e) {
            error_log("Error creating notification: " . $e->getMessage());
            // Don't throw - notifications are not critical
        }
    }
    
    /**
     * Assign delivery person to order
     * @param int $orderId
     * @param int $deliveryPersonId
     * @return bool
     */
    private function assignDeliveryPerson($orderId, $deliveryPersonId) {
        try {
            // Check if delivery record already exists
            $existing = fetchOne("SELECT id FROM deliveries WHERE order_id = ?", [$orderId]);
            
            if ($existing) {
                // Update existing delivery record
                $sql = "UPDATE deliveries SET delivery_person_id = ?, assigned_at = NOW() WHERE order_id = ?";
                return updateData($sql, [$deliveryPersonId, $orderId]) > 0;
            } else {
                // Create new delivery record
                $sql = "INSERT INTO deliveries (order_id, delivery_person_id, assigned_at, status) 
                        VALUES (?, ?, NOW(), 'assigned')";
                return insertData($sql, [$orderId, $deliveryPersonId]) > 0;
            }
        } catch (Exception $e) {
            error_log("Error assigning delivery person: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Mark order as delivered
     * @param int $orderId
     * @return bool
     */
    private function markOrderDelivered($orderId) {
        try {
            $sql = "UPDATE deliveries SET status = 'delivered', delivered_at = NOW() WHERE order_id = ?";
            return updateData($sql, [$orderId]) > 0;
        } catch (Exception $e) {
            error_log("Error marking order delivered: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get cylinder price
     * @param string $cylinderType
     * @return float
     */
    private function getCylinderPrice($cylinderType) {
        $prices = [
            '5kg' => 2200,
            '12kg' => 5000,
            '25kg' => 9500,
            '50kg' => 18000,
            '100kg' => 35000
        ];
        
        return $prices[$cylinderType] ?? 5000;
    }
    
    /**
     * Cancel order
     * @param int $orderId
     * @param int $customerId
     * @param string $reason
     * @return bool
     */
    public function cancelOrder($orderId, $customerId, $reason = null) {
        try {
            $this->pdo->beginTransaction();

            $orderSql = "SELECT * FROM orders WHERE id = ? FOR UPDATE";
            $orderStmt = $this->pdo->prepare($orderSql);
            $orderStmt->execute([$orderId]);
            $order = $orderStmt->fetch();

            if (!$order || (int)$order['customer_id'] !== (int)$customerId) {
                $this->pdo->rollBack();
                error_log("Cancel order failed: Order not found or doesn't belong to customer. Order ID: $orderId, Customer ID: $customerId");
                return false;
            }

            if (!in_array($order['status'], ['pending', 'confirmed'], true)) {
                $this->pdo->rollBack();
                error_log("Cancel order failed: Order status is '{$order['status']}', cannot be cancelled. Order ID: $orderId");
                return false;
            }

            $deliveryCheckSql = "SELECT delivery_person_id FROM deliveries WHERE order_id = ? AND delivery_person_id IS NOT NULL";
            $deliveryStmt = $this->pdo->prepare($deliveryCheckSql);
            $deliveryStmt->execute([$orderId]);
            if ($deliveryStmt->fetch()) {
                $this->pdo->rollBack();
                error_log("Cancel order failed: Delivery person already assigned. Order ID: $orderId");
                return false;
            }

            $cylinderSql = "SELECT id, available_quantity, total_quantity FROM cylinder_inventory WHERE cylinder_type = ? AND status = 'active' FOR UPDATE";
            $cylinderStmt = $this->pdo->prepare($cylinderSql);
            $cylinderStmt->execute([$order['cylinder_type']]);
            $cylinder = $cylinderStmt->fetch();

            $cancelReason = $reason ?? 'Customer requested cancellation';
            $updateOrderSql = "UPDATE orders SET status = 'cancelled', special_instructions = CONCAT(COALESCE(special_instructions, ''), '\n[CANCELLED] ', ?), updated_at = NOW() WHERE id = ?";
            $updateOrderStmt = $this->pdo->prepare($updateOrderSql);
            $updateOrderStmt->execute([$cancelReason, $orderId]);

            if ($updateOrderStmt->rowCount() === 0) {
                $this->pdo->rollBack();
                error_log("Cancel order failed: Database update failed. Order ID: $orderId");
                return false;
            }

            if ($cylinder) {
                $currentAvailable = (int)($cylinder['available_quantity'] ?? 0);
                $totalQuantity = (int)($cylinder['total_quantity'] ?? 0);
                $restoredQuantity = $currentAvailable + (int)$order['quantity'];
                if ($totalQuantity > 0) {
                    $restoredQuantity = min($restoredQuantity, $totalQuantity);
                }

                $updateInventorySql = "UPDATE cylinder_inventory SET available_quantity = ?, updated_at = NOW() WHERE id = ?";
                $updateInventoryStmt = $this->pdo->prepare($updateInventorySql);
                $updateInventoryStmt->execute([$restoredQuantity, $cylinder['id']]);

                try {
                    $logStmt = $this->pdo->prepare("INSERT INTO inventory_logs (cylinder_id, action_type, old_quantity, new_quantity, changed_by, notes) VALUES (?, 'order_cancelled', ?, ?, ?, ?)");
                    $logStmt->execute([
                        $cylinder['id'],
                        $currentAvailable,
                        $restoredQuantity,
                        $customerId,
                        'Order #' . $orderId . ' cancellation - returned ' . (int)$order['quantity'] . ' units'
                    ]);
                } catch (Exception $logError) {
                    error_log('Inventory log (cancel) failed for order #' . $orderId . ': ' . $logError->getMessage());
                }
            }

            $historySql = "INSERT INTO order_history (order_id, status, changed_by, notes, created_at) VALUES (?, 'cancelled', ?, ?, NOW())";
            $historyStmt = $this->pdo->prepare($historySql);
            $historyStmt->execute([$orderId, $customerId, $cancelReason]);

            $this->pdo->commit();

            error_log("Order cancelled successfully. Order ID: $orderId, Customer ID: $customerId");
            return true;

        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            error_log("Error cancelling order: " . $e->getMessage() . ". Order ID: $orderId, Customer ID: $customerId");
            return false;
        }
    }
    
    /**
     * Get order statistics
     * @param int $customerId
     * @return array
     */
    public function getOrderStatistics($customerId) {
        try {
            $sql = "SELECT 
                        COUNT(*) as total_orders,
                        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_orders,
                        SUM(CASE WHEN status = 'confirmed' THEN 1 ELSE 0 END) as confirmed_orders,
                        SUM(CASE WHEN status = 'assigned' THEN 1 ELSE 0 END) as assigned_orders,
                        SUM(CASE WHEN status = 'in_transit' THEN 1 ELSE 0 END) as in_transit_orders,
                        SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) as delivered_orders,
                        SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_orders,
                        SUM(total_amount) as total_spent
                    FROM orders 
                    WHERE customer_id = ?";
            
            $stats = fetchOne($sql, [$customerId]);
            
            // Get recent orders
            $recentSql = "SELECT order_number, cylinder_type, quantity, status, created_at 
                          FROM orders 
                          WHERE customer_id = ? 
                          ORDER BY created_at DESC 
                          LIMIT 5";
            
            $stats['recent_orders'] = fetchAll($recentSql, [$customerId]);
            
            return $stats;
        } catch (Exception $e) {
            error_log("Error getting order statistics: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get delivery tracking information
     * @param string $orderNumber
     * @return array|false
     */
    public function getDeliveryTracking($orderNumber) {
        try {
            $sql = "SELECT o.*, u.name as customer_name, u.phone as customer_phone,
                           d.status as delivery_status, d.assigned_at, d.delivered_at,
                           dp.name as delivery_person_name, dp.phone as delivery_person_phone
                    FROM orders o 
                    JOIN users u ON o.customer_id = u.id 
                    LEFT JOIN deliveries d ON o.id = d.order_id 
                    LEFT JOIN users dp ON d.delivery_person_id = dp.id 
                    WHERE o.order_number = ?";
            
            $order = fetchOne($sql, [$orderNumber]);
            
            if (!$order) {
                return false;
            }
            
            // Get delivery timeline
            $timeline = $this->getDeliveryTimeline($order['id']);
            $order['timeline'] = $timeline;
            
            return $order;
        } catch (Exception $e) {
            error_log("Error getting delivery tracking: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get delivery timeline
     * @param int $orderId
     * @return array
     */
    private function getDeliveryTimeline($orderId) {
        try {
            $sql = "SELECT action, description, created_at 
                    FROM order_timeline 
                    WHERE order_id = ? 
                    ORDER BY created_at ASC";
            
            return fetchAll($sql, [$orderId]);
        } catch (Exception $e) {
            error_log("Error getting delivery timeline: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Add timeline entry
     * @param int $orderId
     * @param string $action
     * @param string $description
     * @param int $userId
     * @return bool
     */
    public function addTimelineEntry($orderId, $action, $description, $userId = null) {
        try {
            $sql = "INSERT INTO order_timeline (order_id, action, description, user_id, created_at) 
                    VALUES (?, ?, ?, ?, NOW())";
            
            return insertData($sql, [$orderId, $action, $description, $userId]) > 0;
        } catch (Exception $e) {
            error_log("Error adding timeline entry: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Search orders
     * @param string $searchTerm
     * @param array $filters
     * @param int $page
     * @param int $limit
     * @return array
     */
    public function searchOrders($searchTerm, $filters = [], $page = 1, $limit = 20) {
        try {
            $offset = ($page - 1) * $limit;
            
            $whereClause = "WHERE (o.order_number LIKE ? OR u.name LIKE ? OR u.phone LIKE ?)";
            $params = ["%$searchTerm%", "%$searchTerm%", "%$searchTerm%"];
            
            // Add filters
            if (!empty($filters['status'])) {
                $whereClause .= " AND o.status = ?";
                $params[] = $filters['status'];
            }
            
            if (!empty($filters['cylinder_type'])) {
                $whereClause .= " AND o.cylinder_type = ?";
                $params[] = $filters['cylinder_type'];
            }
            
            if (!empty($filters['date_from'])) {
                $whereClause .= " AND DATE(o.created_at) >= ?";
                $params[] = $filters['date_from'];
            }
            
            if (!empty($filters['date_to'])) {
                $whereClause .= " AND DATE(o.created_at) <= ?";
                $params[] = $filters['date_to'];
            }
            
            // Get total count
            $countSql = "SELECT COUNT(*) as total FROM orders o JOIN users u ON o.customer_id = u.id $whereClause";
            $total = getCount($countSql, $params);
            
            // Get orders
            $sql = "SELECT o.*, u.name as customer_name, u.phone as customer_phone 
                    FROM orders o 
                    JOIN users u ON o.customer_id = u.id 
                    $whereClause 
                    ORDER BY o.created_at DESC 
                    LIMIT ? OFFSET ?";
            
            $params[] = $limit;
            $params[] = $offset;
            
            $orders = fetchAll($sql, $params);
            
            return [
                'orders' => $orders,
                'total' => $total,
                'pages' => ceil($total / $limit),
                'current_page' => $page
            ];
        } catch (Exception $e) {
            error_log("Error searching orders: " . $e->getMessage());
            return ['orders' => [], 'total' => 0, 'pages' => 0, 'current_page' => 1];
        }
    }
    
    /**
     * Get orders for delivery person
     * @param int $deliveryPersonId
     * @param string $status
     * @return array
     */
    public function getDeliveryPersonOrders($deliveryPersonId, $status = null) {
        try {
            $whereClause = "WHERE d.delivery_person_id = ?";
            $params = [$deliveryPersonId];
            
            if ($status) {
                $whereClause .= " AND d.status = ?";
                $params[] = $status;
            }
            
            $sql = "SELECT o.*, u.name as customer_name, u.phone as customer_phone, u.address as customer_address,
                           d.assigned_at, d.status as delivery_status
                    FROM orders o 
                    JOIN users u ON o.customer_id = u.id 
                    JOIN deliveries d ON o.id = d.order_id 
                    $whereClause 
                    ORDER BY d.assigned_at ASC";
            
            return fetchAll($sql, $params);
        } catch (Exception $e) {
            error_log("Error getting delivery person orders: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Update delivery status
     * @param int $orderId
     * @param string $status
     * @param string $notes
     * @param int $deliveryPersonId
     * @return bool
     */
    public function updateDeliveryStatus($orderId, $status, $notes = null, $deliveryPersonId = null) {
        try {
            // First check if the order is already cancelled
            $checkSql = "SELECT status, customer_id FROM orders WHERE id = ?";
            $currentOrder = fetchOne($checkSql, [$orderId]);
            
            if (!$currentOrder) {
                error_log("Update delivery status failed: Order not found. Order ID: $orderId");
                return false;
            }
            
            // Prevent updating delivery status for cancelled orders
            if ($currentOrder['status'] === 'cancelled') {
                error_log("Update delivery status failed: Cannot update delivery status for cancelled order. Order ID: $orderId, Attempted status: $status");
                return false;
            }
            
            // Update delivery record
            $sql = "UPDATE deliveries SET status = ?, updated_at = NOW() WHERE order_id = ?";
            $params = [$status, $orderId];
            
            if ($notes) {
                $sql = "UPDATE deliveries SET status = ?, notes = ?, updated_at = NOW() WHERE order_id = ?";
                $params = [$status, $notes, $orderId];
            }
            
            $result = updateData($sql, $params);
            
            if ($result) {
                // Update order status accordingly
                $orderStatus = $this->mapDeliveryStatusToOrderStatus($status);
                $this->updateOrderStatus($orderId, $orderStatus, $notes, $deliveryPersonId);
                
                // Add timeline entry
                $this->addTimelineEntry($orderId, 'delivery_status_updated', "Delivery status: $status", $deliveryPersonId);
                
                return true;
            }
            
            return false;
        } catch (Exception $e) {
            error_log("Error updating delivery status: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Map delivery status to order status
     * @param string $deliveryStatus
     * @return string
     */
    private function mapDeliveryStatusToOrderStatus($deliveryStatus) {
        $mapping = [
            'assigned' => 'assigned',
            'picked_up' => 'in_transit',
            'in_transit' => 'in_transit',
            'out_for_delivery' => 'in_transit',
            'delivered' => 'delivered',
            'failed' => 'failed'
        ];
        
        return $mapping[$deliveryStatus] ?? 'pending';
    }
}

// Initialize order manager
$orderManager = new OrderManager();
?>
