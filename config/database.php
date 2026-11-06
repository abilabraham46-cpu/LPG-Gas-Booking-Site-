<?php
/**
 * Enhanced Database Configuration for LIAA LPG Gas Booking System
 * Compatible with PHP 8.3.14 and MySQL 9.1.0
 */

// Database configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'liaa_lpg_system');
define('DB_USER', 'root');
define('DB_PASS', ''); // Empty password for localhost root
define('DB_CHARSET', 'utf8mb4');

// PDO options for modern PHP
$pdo_options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
];

/**
 * Get database connection
 * @return PDO
 * @throws PDOException
 */
function getDBConnection() {
    static $pdo = null;
    
    if ($pdo === null) {
        try {
            $pdo_options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
            ];
            
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $pdo_options);
        } catch (PDOException $e) {
            error_log("Database connection failed: " . $e->getMessage());
            throw new Exception("Database connection failed. Please try again later.");
        }
    }
    
    return $pdo;
}

/**
 * Execute a prepared statement
 * @param string $sql
 * @param array $params
 * @return PDOStatement
 */
function executeQuery($sql, $params = []) {
    try {
        $pdo = getDBConnection();
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    } catch (PDOException $e) {
        error_log("Query execution failed: " . $e->getMessage());
        throw new Exception("Database operation failed. Please try again later.");
    }
}

/**
 * Fetch a single row
 * @param string $sql
 * @param array $params
 * @return array|false
 */
function fetchOne($sql, $params = []) {
    $stmt = executeQuery($sql, $params);
    return $stmt->fetch();
}

/**
 * Fetch all rows
 * @param string $sql
 * @param array $params
 * @return array
 */
function fetchAll($sql, $params = []) {
    $stmt = executeQuery($sql, $params);
    return $stmt->fetchAll();
}

/**
 * Insert data and return last insert ID
 * @param string $sql
 * @param array $params
 * @return int
 */
function insertData($sql, $params = []) {
    $stmt = executeQuery($sql, $params);
    return getDBConnection()->lastInsertId();
}

/**
 * Update data and return affected rows
 * @param string $sql
 * @param array $params
 * @return int
 */
function updateData($sql, $params = []) {
    $stmt = executeQuery($sql, $params);
    return $stmt->rowCount();
}

/**
 * Delete data and return affected rows
 * @param string $sql
 * @param array $params
 * @return int
 */
function deleteData($sql, $params = []) {
    $stmt = executeQuery($sql, $params);
    return $stmt->rowCount();
}

/**
 * Check if record exists
 * @param string $sql
 * @param array $params
 * @return bool
 */
function recordExists($sql, $params = []) {
    $stmt = executeQuery($sql, $params);
    return $stmt->rowCount() > 0;
}

/**
 * Get count of records
 * @param string $sql
 * @param array $params
 * @return int
 */
function getCount($sql, $params = []) {
    $stmt = executeQuery($sql, $params);
    $result = $stmt->fetch();
    return (int) ($result['count'] ?? 0);
}

/**
 * Hash password using modern PHP password_hash()
 * @param string $password
 * @return string
 */
function hashPassword($password) {
    return password_hash($password, PASSWORD_DEFAULT);
}

/**
 * Verify password using modern PHP password_verify()
 * @param string $password
 * @param string $hash
 * @return bool
 */
function verifyPassword($password, $hash) {
    return password_verify($password, $hash);
}

/**
 * Sanitize user input
 * @param string $input
 * @return string
 */
function sanitizeInput($input) {
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

/**
 * Validate email format
 * @param string $email
 * @return bool
 */
function isValidEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Validate phone number format
 * @param string $phone
 * @return bool
 */
function isValidPhone($phone) {
    // Basic phone validation: accept exactly 10 digits
    return preg_match('/^\d{10}$/', $phone);
}

/**
 * Generate CSRF token
 * @return string
 */
function generateCSRFToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Verify CSRF token
 * @param string $token
 * @return bool
 */
function verifyCSRFToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Generate unique transaction ID
 * @return string
 */
function generateTransactionId() {
    return 'TXN' . date('YmdHis') . rand(1000, 9999);
}

/**
 * Format currency
 * @param float $amount
 * @param string $currency
 * @return string
 */
function formatCurrency($amount, $currency = 'INR') {
    return $currency . ' ' . number_format($amount, 2);
}

/**
 * Get system setting
 * @param string $key
 * @param mixed $default
 * @return mixed
 */
function getSystemSetting($key, $default = null) {
    try {
        $sql = "SELECT setting_value FROM system_settings WHERE setting_key = ?";
        $result = fetchOne($sql, [$key]);
        return $result ? $result['setting_value'] : $default;
    } catch (Exception $e) {
        error_log("Error getting system setting: " . $e->getMessage());
        return $default;
    }
}

/**
 * Update system setting
 * @param string $key
 * @param string $value
 * @param string $description
 * @return bool
 */
function updateSystemSetting($key, $value, $description = '') {
    try {
        $sql = "INSERT INTO system_settings (setting_key, setting_value, description) 
                VALUES (?, ?, ?) 
                ON DUPLICATE KEY UPDATE setting_value = ?, description = ?";
        updateData($sql, [$key, $value, $description, $value, $description]);
        return true;
    } catch (Exception $e) {
        error_log("Error updating system setting: " . $e->getMessage());
        return false;
    }
}

/**
 * Begin transaction
 */
function beginTransaction() {
    getDBConnection()->beginTransaction();
}

/**
 * Commit transaction
 */
function commitTransaction() {
    getDBConnection()->commit();
}

/**
 * Rollback transaction
 */
function rollbackTransaction() {
    getDBConnection()->rollBack();
}

/**
 * Check if currently in transaction
 * @return bool
 */
function inTransaction() {
    return getDBConnection()->inTransaction();
}

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
?> 