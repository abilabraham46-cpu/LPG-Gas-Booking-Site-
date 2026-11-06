<?php
/**
 * Advanced Analytics API
 * Provides comprehensive business intelligence data
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once '../config/database.php';
require_once '../includes/auth.php';

session_start();
$auth = new Auth();

// Check admin authorization
if (!$auth->isLoggedIn() || $auth->getCurrentUser()['role'] !== 'admin') {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized access']);
    exit;
}

$action = $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'revenue_analytics':
            // Revenue analytics with trends
            $data = [
                'daily_revenue' => [],
                'monthly_revenue' => [],
                'cylinder_type_revenue' => [],
                'growth_rate' => 0
            ];
            
            // Last 30 days revenue
            $sql = "SELECT DATE(created_at) as date, 
                           SUM(CASE WHEN payment_status = 'paid' THEN total_amount ELSE 0 END) as revenue,
                           COUNT(*) as orders
                    FROM orders 
                    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                    GROUP BY DATE(created_at) 
                    ORDER BY date";
            
            $stmt = executeQuery($sql);
            $data['daily_revenue'] = $stmt->fetchAll();
            
            // Monthly revenue for last 12 months
            $sql = "SELECT YEAR(created_at) as year, MONTH(created_at) as month,
                           SUM(CASE WHEN payment_status = 'paid' THEN total_amount ELSE 0 END) as revenue,
                           COUNT(*) as orders
                    FROM orders 
                    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
                    GROUP BY YEAR(created_at), MONTH(created_at)
                    ORDER BY year, month";
            
            $stmt = executeQuery($sql);
            $data['monthly_revenue'] = $stmt->fetchAll();
            
            // Revenue by cylinder type
            $sql = "SELECT cylinder_type, 
                           SUM(CASE WHEN payment_status = 'paid' THEN total_amount ELSE 0 END) as revenue,
                           COUNT(*) as orders,
                           AVG(total_amount) as avg_order_value
                    FROM orders 
                    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                    GROUP BY cylinder_type 
                    ORDER BY revenue DESC";
            
            $stmt = executeQuery($sql);
            $data['cylinder_type_revenue'] = $stmt->fetchAll();
            
            // Calculate growth rate (current month vs previous month)
            $sql = "SELECT 
                        SUM(CASE 
                            WHEN YEAR(created_at) = YEAR(NOW()) AND MONTH(created_at) = MONTH(NOW()) 
                            AND payment_status = 'paid'
                            THEN total_amount ELSE 0 END) as current_month,
                        SUM(CASE 
                            WHEN YEAR(created_at) = YEAR(DATE_SUB(NOW(), INTERVAL 1 MONTH)) 
                            AND MONTH(created_at) = MONTH(DATE_SUB(NOW(), INTERVAL 1 MONTH))
                            AND payment_status = 'paid'
                            THEN total_amount ELSE 0 END) as previous_month
                    FROM orders";
            
            $stmt = executeQuery($sql);
            $growth = $stmt->fetch();
            
            if ($growth['previous_month'] > 0) {
                $data['growth_rate'] = (($growth['current_month'] - $growth['previous_month']) / $growth['previous_month']) * 100;
            }
            
            echo json_encode($data);
            break;
            
        case 'customer_analytics':
            // Customer behavior analytics
            $data = [
                'customer_acquisition' => [],
                'customer_retention' => [],
                'top_customers' => [],
                'customer_demographics' => []
            ];
            
            // Customer acquisition over time
            $sql = "SELECT DATE(created_at) as date, COUNT(*) as new_customers
                    FROM users 
                    WHERE role = 'customer' AND created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)
                    GROUP BY DATE(created_at) 
                    ORDER BY date";
            
            $stmt = executeQuery($sql);
            $data['customer_acquisition'] = $stmt->fetchAll();
            
            // Top customers by revenue
            $sql = "SELECT u.name, u.email, u.phone,
                           COUNT(o.id) as total_orders,
                           SUM(CASE WHEN o.payment_status = 'paid' THEN o.total_amount ELSE 0 END) as total_spent,
                           AVG(o.total_amount) as avg_order_value,
                           MAX(o.created_at) as last_order_date
                    FROM users u
                    JOIN orders o ON u.id = o.customer_id
                    WHERE u.role = 'customer'
                    GROUP BY u.id, u.name, u.email, u.phone
                    ORDER BY total_spent DESC
                    LIMIT 10";
            
            $stmt = executeQuery($sql);
            $data['top_customers'] = $stmt->fetchAll();
            
            // Customer retention (repeat customers)
            $sql = "SELECT 
                        COUNT(DISTINCT CASE WHEN order_count = 1 THEN customer_id END) as one_time_customers,
                        COUNT(DISTINCT CASE WHEN order_count > 1 THEN customer_id END) as repeat_customers,
                        AVG(order_count) as avg_orders_per_customer
                    FROM (
                        SELECT customer_id, COUNT(*) as order_count
                        FROM orders
                        GROUP BY customer_id
                    ) as customer_stats";
            
            $stmt = executeQuery($sql);
            $data['customer_retention'] = $stmt->fetch();
            
            echo json_encode($data);
            break;
            
        case 'delivery_analytics':
            // Delivery performance analytics
            $data = [
                'delivery_performance' => [],
                'delivery_times' => [],
                'delivery_success_rate' => 0,
                'staff_performance' => []
            ];
            
            // Delivery success rate
            $sql = "SELECT 
                        COUNT(*) as total_deliveries,
                        SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) as successful_deliveries,
                        AVG(TIMESTAMPDIFF(HOUR, pickup_time, delivery_time)) as avg_delivery_time
                    FROM deliveries 
                    WHERE pickup_time IS NOT NULL";
            
            $stmt = executeQuery($sql);
            $performance = $stmt->fetch();
            
            if ($performance['total_deliveries'] > 0) {
                $data['delivery_success_rate'] = ($performance['successful_deliveries'] / $performance['total_deliveries']) * 100;
                $data['avg_delivery_time'] = $performance['avg_delivery_time'];
            }
            
            // Staff performance
            $sql = "SELECT u.name, u.email,
                           COUNT(d.id) as total_deliveries,
                           SUM(CASE WHEN d.status = 'delivered' THEN 1 ELSE 0 END) as successful_deliveries,
                           AVG(TIMESTAMPDIFF(HOUR, d.pickup_time, d.delivery_time)) as avg_delivery_time,
                           (SUM(CASE WHEN d.status = 'delivered' THEN 1 ELSE 0 END) / COUNT(d.id)) * 100 as success_rate
                    FROM users u
                    JOIN deliveries d ON u.id = d.delivery_person_id
                    WHERE u.role = 'delivery' AND d.pickup_time IS NOT NULL
                    GROUP BY u.id, u.name, u.email
                    ORDER BY success_rate DESC, total_deliveries DESC";
            
            $stmt = executeQuery($sql);
            $data['staff_performance'] = $stmt->fetchAll();
            
            echo json_encode($data);
            break;
            
        case 'inventory_analytics':
            // Inventory and demand analytics
            $sql = "SELECT ci.cylinder_type, ci.total_quantity, ci.available_quantity,
                           (ci.total_quantity - ci.available_quantity) as in_use,
                           COUNT(o.id) as orders_last_30_days,
                           SUM(o.quantity) as total_demand
                    FROM cylinder_inventory ci
                    LEFT JOIN orders o ON ci.cylinder_type = o.cylinder_type 
                        AND o.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                    GROUP BY ci.id, ci.cylinder_type, ci.total_quantity, ci.available_quantity
                    ORDER BY total_demand DESC";
            
            $stmt = executeQuery($sql);
            $data = $stmt->fetchAll();
            
            echo json_encode($data);
            break;
            
        case 'predictive_analytics':
            // Simple demand prediction based on historical data
            $data = [
                'demand_forecast' => [],
                'seasonal_trends' => [],
                'recommendations' => []
            ];
            
            // Demand forecast for next 7 days based on historical patterns
            $sql = "SELECT 
                        DAYNAME(created_at) as day_name,
                        DAYOFWEEK(created_at) as day_of_week,
                        AVG(daily_orders) as avg_orders,
                        AVG(daily_revenue) as avg_revenue
                    FROM (
                        SELECT DATE(created_at) as order_date,
                               DAYNAME(created_at) as day_name,
                               DAYOFWEEK(created_at) as day_of_week,
                               COUNT(*) as daily_orders,
                               SUM(total_amount) as daily_revenue
                        FROM orders
                        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 60 DAY)
                        GROUP BY DATE(created_at)
                    ) as daily_stats
                    GROUP BY day_of_week, day_name
                    ORDER BY day_of_week";
            
            $stmt = executeQuery($sql);
            $data['demand_forecast'] = $stmt->fetchAll();
            
            // Generate recommendations
            $recommendations = [];
            
            // Check low inventory
            $sql = "SELECT cylinder_type, available_quantity, total_quantity
                    FROM cylinder_inventory 
                    WHERE (available_quantity / total_quantity) < 0.2";
            
            $stmt = executeQuery($sql);
            $lowStock = $stmt->fetchAll();
            
            foreach ($lowStock as $item) {
                $recommendations[] = [
                    'type' => 'inventory',
                    'priority' => 'high',
                    'message' => "Low stock alert: {$item['cylinder_type']} - only {$item['available_quantity']} units remaining"
                ];
            }
            
            $data['recommendations'] = $recommendations;
            
            echo json_encode($data);
            break;
            
        default:
            echo json_encode(['error' => 'Invalid action']);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Analytics error: ' . $e->getMessage()]);
}
?>