<?php
/**
 * Admin Management System for LIAA LPG Gas Booking System
 * Handles user management, system statistics, and administrative functions
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/utils.php';

class AdminManager {
    private $pdo;
    
    public function __construct() {
        $this->pdo = getDBConnection();
    }
    
    /**
     * Get system dashboard statistics
     * @return array
     */
    public function getDashboardStats() {
        try {
            $stats = [];
            
            // Total users by role
            $userStats = $this->getUserStats();
            $stats['users'] = $userStats;
            
            // Order statistics
            $orderStats = $this->getOrderStats();
            $stats['orders'] = $orderStats;
            
            // Revenue statistics
            $revenueStats = $this->getRevenueStats();
            $stats['revenue'] = $revenueStats;
            
            // Delivery statistics
            $deliveryStats = $this->getDeliveryStats();
            $stats['delivery'] = $deliveryStats;
            
            // Recent activities
            $recentActivities = $this->getRecentActivities();
            $stats['recent_activities'] = $recentActivities;
            
            return $stats;
        } catch (Exception $e) {
            error_log("Error getting dashboard stats: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get user statistics
     * @return array
     */
    private function getUserStats() {
        try {
            $sql = "SELECT 
                        COUNT(*) as total_users,
                        SUM(CASE WHEN role = 'customer' THEN 1 ELSE 0 END) as total_customers,
                        SUM(CASE WHEN role = 'delivery' THEN 1 ELSE 0 END) as total_delivery_personnel,
                        SUM(CASE WHEN role = 'admin' THEN 1 ELSE 0 END) as total_admins,
                        SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_users,
                        SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END) as inactive_users,
                        SUM(CASE WHEN status = 'suspended' THEN 1 ELSE 0 END) as suspended_users
                    FROM users";
            
            return fetchOne($sql);
        } catch (Exception $e) {
            error_log("Error getting user stats: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get order statistics
     * @return array
     */
    private function getOrderStats() {
        try {
            $sql = "SELECT 
                        COUNT(*) as total_orders,
                        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_orders,
                        SUM(CASE WHEN status = 'confirmed' THEN 1 ELSE 0 END) as confirmed_orders,
                        SUM(CASE WHEN status = 'assigned' THEN 1 ELSE 0 END) as assigned_orders,
                        SUM(CASE WHEN status = 'in_transit' THEN 1 ELSE 0 END) as in_transit_orders,
                        SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) as delivered_orders,
                        SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_orders,
                        SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_orders
                    FROM orders";
            
            return fetchOne($sql);
        } catch (Exception $e) {
            error_log("Error getting order stats: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get revenue statistics
     * @return array
     */
    private function getRevenueStats() {
        try {
            $sql = "SELECT 
                        SUM(total_amount) as total_revenue,
                        SUM(CASE WHEN status = 'delivered' THEN total_amount ELSE 0 END) as confirmed_revenue,
                        SUM(CASE WHEN status = 'pending' THEN total_amount ELSE 0 END) as pending_revenue,
                        AVG(total_amount) as average_order_value,
                        COUNT(*) as total_orders
                    FROM orders";
            
            $stats = fetchOne($sql);
            
            // Calculate monthly revenue
            $monthlySql = "SELECT 
                            DATE_FORMAT(created_at, '%Y-%m') as month,
                            SUM(total_amount) as revenue,
                            COUNT(*) as orders
                           FROM orders 
                           WHERE status = 'delivered'
                           GROUP BY DATE_FORMAT(created_at, '%Y-%m')
                           ORDER BY month DESC 
                           LIMIT 6";
            
            $stats['monthly_revenue'] = fetchAll($monthlySql);
            
            return $stats;
        } catch (Exception $e) {
            error_log("Error getting revenue stats: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get delivery statistics
     * @return array
     */
    private function getDeliveryStats() {
        try {
            $sql = "SELECT 
                        COUNT(*) as total_deliveries,
                        SUM(CASE WHEN status = 'assigned' THEN 1 ELSE 0 END) as assigned_deliveries,
                        SUM(CASE WHEN status = 'picked_up' THEN 1 ELSE 0 END) as picked_up_deliveries,
                        SUM(CASE WHEN status = 'in_transit' THEN 1 ELSE 0 END) as in_transit_deliveries,
                        SUM(CASE WHEN status = 'out_for_delivery' THEN 1 ELSE 0 END) as out_for_delivery_deliveries,
                        SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) as completed_deliveries,
                        SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_deliveries
                    FROM deliveries";
            
            return fetchOne($sql);
        } catch (Exception $e) {
            error_log("Error getting delivery stats: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get recent activities
     * @return array
     */
    private function getRecentActivities() {
        try {
            $sql = "SELECT al.*, u.name as user_name, u.role as user_role
                    FROM activity_logs al
                    LEFT JOIN users u ON al.user_id = u.id
                    ORDER BY al.created_at DESC
                    LIMIT 20";
            
            return fetchAll($sql);
        } catch (Exception $e) {
            error_log("Error getting recent activities: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get all users with pagination
     * @param string $role
     * @param string $status
     * @param int $page
     * @param int $limit
     * @return array
     */
    public function getUsers($role = null, $status = null, $page = 1, $limit = 20) {
        try {
            $offset = ($page - 1) * $limit;
            
            $whereClause = "";
            $params = [];
            
            if ($role) {
                $whereClause = "WHERE role = ?";
                $params[] = $role;
            }
            
            if ($status) {
                if ($whereClause) {
                    $whereClause .= " AND status = ?";
                } else {
                    $whereClause = "WHERE status = ?";
                }
                $params[] = $status;
            }
            
            // Get total count
            $countSql = "SELECT COUNT(*) as total FROM users $whereClause";
            $total = getCount($countSql, $params);
            
            // Get users
            $sql = "SELECT id, name, email, phone, address, role, status, created_at, updated_at
                    FROM users 
                    $whereClause 
                    ORDER BY created_at DESC 
                    LIMIT ? OFFSET ?";
            
            $params[] = $limit;
            $params[] = $offset;
            
            $users = fetchAll($sql, $params);
            
            return [
                'users' => $users,
                'total' => $total,
                'pages' => ceil($total / $limit),
                'current_page' => $page
            ];
        } catch (Exception $e) {
            error_log("Error getting users: " . $e->getMessage());
            return ['users' => [], 'total' => 0, 'pages' => 0, 'current_page' => 1];
        }
    }
    
    /**
     * Get user by ID
     * @param int $userId
     * @return array|false
     */
    public function getUserById($userId) {
        try {
            $sql = "SELECT id, name, email, phone, address, role, status, created_at, updated_at
                    FROM users WHERE id = ?";
            
            return fetchOne($sql, [$userId]);
        } catch (Exception $e) {
            error_log("Error getting user by ID: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Create new user
     * @param array $userData
     * @return int|false
     */
    public function createUser($userData) {
        try {
            // Validate required fields
            $required = ['name', 'email', 'phone', 'password', 'role'];
            $errors = validateRequiredFields($userData, $required);
            
            if (!empty($errors)) {
                throw new Exception(implode(', ', $errors));
            }
            
            // Validate email format
            if (!isValidEmail($userData['email'])) {
                throw new Exception("Invalid email format");
            }
            
            // Validate phone format
            if (!isValidPhone($userData['phone'])) {
                throw new Exception("Invalid phone number format");
            }
            
            // Check if user already exists
            if ($this->userExists($userData['email'], $userData['phone'])) {
                throw new Exception("User with this email or phone already exists");
            }
            
            // Hash password
            $hashedPassword = hashPassword($userData['password']);
            
            // Insert new user
            $sql = "INSERT INTO users (name, email, phone, password, address, role, status, created_at) 
                    VALUES (?, ?, ?, ?, ?, ?, 'active', NOW())";
            
            $userId = insertData($sql, [
                $userData['name'],
                $userData['email'],
                $userData['phone'],
                $hashedPassword,
                $userData['address'] ?? null,
                $userData['role']
            ]);
            
            if ($userId) {
                logActivity('user_created', "User {$userData['name']} created", null, 'admin');
                return $userId;
            }
            
            return false;
        } catch (Exception $e) {
            error_log("Error creating user: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Update user
     * @param int $userId
     * @param array $userData
     * @return bool
     */
    public function updateUser($userId, $userData) {
        try {
            $allowedFields = ['name', 'email', 'phone', 'address', 'role', 'status'];
            $updateFields = [];
            $params = [];
            
            foreach ($allowedFields as $field) {
                if (isset($userData[$field]) && !empty($userData[$field])) {
                    $updateFields[] = "$field = ?";
                    $params[] = $userData[$field];
                }
            }
            
            if (empty($updateFields)) {
                return false;
            }
            
            $params[] = $userId;
            $sql = "UPDATE users SET " . implode(', ', $updateFields) . ", updated_at = NOW() WHERE id = ?";
            
            $result = updateData($sql, $params);
            
            if ($result) {
                logActivity('user_updated', "User #$userId updated", null, 'admin');
                return true;
            }
            
            return false;
        } catch (Exception $e) {
            error_log("Error updating user: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Delete user
     * @param int $userId
     * @return bool
     */
    public function deleteUser($userId) {
        try {
            // Check if user has active orders
            $activeOrders = getCount("SELECT COUNT(*) FROM orders WHERE customer_id = ? AND status NOT IN ('delivered', 'cancelled')", [$userId]);
            
            if ($activeOrders > 0) {
                throw new Exception("Cannot delete user with active orders");
            }
            
            // Soft delete - change status to deleted
            $sql = "UPDATE users SET status = 'deleted', updated_at = NOW() WHERE id = ?";
            $result = updateData($sql, [$userId]);
            
            if ($result) {
                logActivity('user_deleted', "User #$userId deleted", null, 'admin');
                return true;
            }
            
            return false;
        } catch (Exception $e) {
            error_log("Error deleting user: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Change user status
     * @param int $userId
     * @param string $status
     * @return bool
     */
    public function changeUserStatus($userId, $status) {
        try {
            $sql = "UPDATE users SET status = ?, updated_at = NOW() WHERE id = ?";
            $result = updateData($sql, [$status, $userId]);
            
            if ($result) {
                logActivity('user_status_changed', "User #$userId status changed to $status", null, 'admin');
                return true;
            }
            
            return false;
        } catch (Exception $e) {
            error_log("Error changing user status: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Reset user password
     * @param int $userId
     * @param string $newPassword
     * @return bool
     */
    public function resetUserPassword($userId, $newPassword) {
        try {
            $hashedPassword = hashPassword($newPassword);
            $sql = "UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?";
            
            $result = updateData($sql, [$hashedPassword, $userId]);
            
            if ($result) {
                logActivity('user_password_reset', "User #$userId password reset", null, 'admin');
                return true;
            }
            
            return false;
        } catch (Exception $e) {
            error_log("Error resetting user password: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Check if user exists
     * @param string $email
     * @param string $phone
     * @return bool
     */
    private function userExists($email, $phone) {
        $sql = "SELECT COUNT(*) as count FROM users WHERE email = ? OR phone = ?";
        return getCount($sql, [$email, $phone]) > 0;
    }
    
    /**
     * Search users
     * @param string $searchTerm
     * @param array $filters
     * @param int $page
     * @param int $limit
     * @return array
     */
    public function searchUsers($searchTerm, $filters = [], $page = 1, $limit = 20) {
        try {
            $offset = ($page - 1) * $limit;
            
            $whereClause = "WHERE (name LIKE ? OR email LIKE ? OR phone LIKE ?)";
            $params = ["%$searchTerm%", "%$searchTerm%", "%$searchTerm%"];
            
            // Add filters
            if (!empty($filters['role'])) {
                $whereClause .= " AND role = ?";
                $params[] = $filters['role'];
            }
            
            if (!empty($filters['status'])) {
                $whereClause .= " AND status = ?";
                $params[] = $filters['status'];
            }
            
            // Get total count
            $countSql = "SELECT COUNT(*) as total FROM users $whereClause";
            $total = getCount($countSql, $params);
            
            // Get users
            $sql = "SELECT id, name, email, phone, address, role, status, created_at, updated_at
                    FROM users 
                    $whereClause 
                    ORDER BY created_at DESC 
                    LIMIT ? OFFSET ?";
            
            $params[] = $limit;
            $params[] = $offset;
            
            $users = fetchAll($sql, $params);
            
            return [
                'users' => $users,
                'total' => $total,
                'pages' => ceil($total / $limit),
                'current_page' => $page
            ];
        } catch (Exception $e) {
            error_log("Error searching users: " . $e->getMessage());
            return ['users' => [], 'total' => 0, 'pages' => 0, 'current_page' => 1];
        }
    }
    
    /**
     * Get system settings
     * @return array
     */
    public function getSystemSettings() {
        try {
            $sql = "SELECT * FROM system_settings ORDER BY setting_key";
            $settings = fetchAll($sql);
            
            $formattedSettings = [];
            foreach ($settings as $setting) {
                $formattedSettings[$setting['setting_key']] = $setting['setting_value'];
            }
            
            return $formattedSettings;
        } catch (Exception $e) {
            error_log("Error getting system settings: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Update system setting
     * @param string $key
     * @param string $value
     * @return bool
     */
    public function updateSystemSetting($key, $value) {
        try {
            $sql = "UPDATE system_settings SET setting_value = ?, updated_at = NOW() WHERE setting_key = ?";
            $result = updateData($sql, [$value, $key]);
            
            if ($result) {
                logActivity('system_setting_updated', "Setting '$key' updated to '$value'", null, 'admin');
                return true;
            }
            
            return false;
        } catch (Exception $e) {
            error_log("Error updating system setting: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get activity logs
     * @param array $filters
     * @param int $page
     * @param int $limit
     * @return array
     */
    public function getActivityLogs($filters = [], $page = 1, $limit = 50) {
        try {
            $offset = ($page - 1) * $limit;
            
            $whereClause = "";
            $params = [];
            
            if (!empty($filters['action'])) {
                $whereClause = "WHERE action = ?";
                $params[] = $filters['action'];
            }
            
            if (!empty($filters['user_role'])) {
                if ($whereClause) {
                    $whereClause .= " AND user_role = ?";
                } else {
                    $whereClause = "WHERE user_role = ?";
                }
                $params[] = $filters['user_role'];
            }
            
            if (!empty($filters['date_from'])) {
                if ($whereClause) {
                    $whereClause .= " AND DATE(created_at) >= ?";
                } else {
                    $whereClause = "WHERE DATE(created_at) >= ?";
                }
                $params[] = $filters['date_from'];
            }
            
            if (!empty($filters['date_to'])) {
                if ($whereClause) {
                    $whereClause .= " AND DATE(created_at) <= ?";
                } else {
                    $whereClause = "WHERE DATE(created_at) <= ?";
                }
                $params[] = $filters['date_to'];
            }
            
            // Get total count
            $countSql = "SELECT COUNT(*) as total FROM activity_logs $whereClause";
            $total = getCount($countSql, $params);
            
            // Get logs
            $sql = "SELECT al.*, u.name as user_name, u.role as user_role
                    FROM activity_logs al
                    LEFT JOIN users u ON al.user_id = u.id
                    $whereClause 
                    ORDER BY al.created_at DESC 
                    LIMIT ? OFFSET ?";
            
            $params[] = $limit;
            $params[] = $offset;
            
            $logs = fetchAll($sql, $params);
            
            return [
                'logs' => $logs,
                'total' => $total,
                'pages' => ceil($total / $limit),
                'current_page' => $page
            ];
        } catch (Exception $e) {
            error_log("Error getting activity logs: " . $e->getMessage());
            return ['logs' => [], 'total' => 0, 'pages' => 0, 'current_page' => 1];
        }
    }
    
    /**
     * Export data to CSV
     * @param string $table
     * @param array $filters
     * @return string|false
     */
    public function exportToCSV($table, $filters = []) {
        try {
            $allowedTables = ['users', 'orders', 'deliveries', 'activity_logs'];
            
            if (!in_array($table, $allowedTables)) {
                throw new Exception("Invalid table for export");
            }
            
            $sql = "SELECT * FROM $table";
            $params = [];
            
            // Add filters if specified
            if (!empty($filters)) {
                $whereClause = " WHERE ";
                $conditions = [];
                
                foreach ($filters as $key => $value) {
                    if ($value !== null && $value !== '') {
                        $conditions[] = "$key = ?";
                        $params[] = $value;
                    }
                }
                
                if (!empty($conditions)) {
                    $sql .= $whereClause . implode(' AND ', $conditions);
                }
            }
            
            $sql .= " ORDER BY created_at DESC";
            
            $data = fetchAll($sql, $params);
            
            if (empty($data)) {
                return false;
            }
            
            // Generate CSV content
            $csv = '';
            
            // Add headers
            $headers = array_keys($data[0]);
            $csv .= implode(',', $headers) . "\n";
            
            // Add data rows
            foreach ($data as $row) {
                $csvRow = [];
                foreach ($row as $value) {
                    $csvRow[] = '"' . str_replace('"', '""', $value) . '"';
                }
                $csv .= implode(',', $csvRow) . "\n";
            }
            
            return $csv;
        } catch (Exception $e) {
            error_log("Error exporting to CSV: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get backup information
     * @return array
     */
    public function getBackupInfo() {
        try {
            $backupDir = __DIR__ . '/../backups/';
            $backups = [];
            
            if (is_dir($backupDir)) {
                $files = scandir($backupDir);
                foreach ($files as $file) {
                    if ($file !== '.' && $file !== '..' && pathinfo($file, PATHINFO_EXTENSION) === 'sql') {
                        $filePath = $backupDir . $file;
                        $backups[] = [
                            'filename' => $file,
                            'size' => filesize($filePath),
                            'created_at' => filemtime($filePath)
                        ];
                    }
                }
                
                // Sort by creation time (newest first)
                usort($backups, function($a, $b) {
                    return $b['created_at'] - $a['created_at'];
                });
            }
            
            return $backups;
        } catch (Exception $e) {
            error_log("Error getting backup info: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Create database backup
     * @return string|false
     */
    public function createBackup() {
        try {
            $backupDir = __DIR__ . '/../backups/';
            
            if (!is_dir($backupDir)) {
                mkdir($backupDir, 0755, true);
            }
            
            $timestamp = date('Y-m-d_H-i-s');
            $backupFile = $backupDir . "liaa_lpg_backup_{$timestamp}.sql";
            
            // Get database configuration
            $host = DB_HOST;
            $name = DB_NAME;
            $user = DB_USER;
            $pass = DB_PASS;
            
            // Create backup command
            $command = "mysqldump --host={$host} --user={$user}";
            if (!empty($pass)) {
                $command .= " --password={$pass}";
            }
            $command .= " {$name} > {$backupFile}";
            
            // Execute backup
            exec($command, $output, $returnCode);
            
            if ($returnCode === 0 && file_exists($backupFile)) {
                logActivity('backup_created', "Database backup created: " . basename($backupFile), null, 'admin');
                return $backupFile;
            }
            
            return false;
        } catch (Exception $e) {
            error_log("Error creating backup: " . $e->getMessage());
            return false;
        }
    }
}

// Initialize admin manager
$adminManager = new AdminManager();
?>
