<?php
/**
 * Authentication System for LIAA LPG Gas Booking System
 * Handles user login, registration, and session management
 */

require_once __DIR__ . '/../config/database.php';

class Auth {
    private $pdo;
    
    public function __construct() {
        $this->pdo = getDBConnection();
    }
    
    /**
     * Authenticate user login
     * @param string $identifier (email or phone)
     * @param string $password
     * @param string $role
     * @return array|false
     */
    public function authenticate($identifier, $password, $role = null) {
        try {
            // Check if identifier is email or phone
            $isEmail = filter_var($identifier, FILTER_VALIDATE_EMAIL);
            
            if ($isEmail) {
                $sql = "SELECT * FROM users WHERE email = ? AND status = 'active'";
            } else {
                $sql = "SELECT * FROM users WHERE phone = ? AND status = 'active'";
            }
            
            if ($role) {
                $sql .= " AND role = ?";
                $stmt = executeQuery($sql, [$identifier, $role]);
            } else {
                $stmt = executeQuery($sql, [$identifier]);
            }
            
            $user = $stmt->fetch();
            
            if ($user && verifyPassword($password, $user['password'])) {
                // Update last login time
                $this->updateLastLogin($user['id']);
                
                // Remove password from user data
                unset($user['password']);
                
                return $user;
            }
            
            return false;
        } catch (Exception $e) {
            error_log("Authentication error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Register new customer
     * @param array $userData
     * @return array|false
     */
    public function registerCustomer($userData) {
        try {
            // Validate required fields
            $required = ['name', 'email', 'phone', 'password', 'address'];
            foreach ($required as $field) {
                if (empty($userData[$field])) {
                    throw new Exception("Field '$field' is required");
                }
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
            $sql = "INSERT INTO users (name, email, phone, password, address, role, status) 
                    VALUES (?, ?, ?, ?, ?, 'customer', 'active')";
            
            $userId = insertData($sql, [
                $userData['name'],
                $userData['email'],
                $userData['phone'],
                $hashedPassword,
                $userData['address']
            ]);
            
            if ($userId) {
                // Return user data without password
                return $this->getUserById($userId);
            }
            
            return false;
        } catch (Exception $e) {
            error_log("Registration error: " . $e->getMessage());
            throw $e;
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
     * Get user by ID
     * @param int $userId
     * @return array|false
     */
    public function getUserById($userId) {
        try {
            $sql = "SELECT id, name, email, phone, address, role, status, created_at 
                    FROM users WHERE id = ?";
            $user = fetchOne($sql, [$userId]);
            
            if ($user) {
                unset($user['password']);
                return $user;
            }
            
            return false;
        } catch (Exception $e) {
            error_log("Error getting user by ID: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Update user profile
     * @param int $userId
     * @param array $userData
     * @return bool
     */
    public function updateProfile($userId, $userData) {
        try {
            $allowedFields = ['name', 'email', 'phone', 'address'];
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
            $sql = "UPDATE users SET " . implode(', ', $updateFields) . " WHERE id = ?";
            
            return updateData($sql, $params) > 0;
        } catch (Exception $e) {
            error_log("Error updating profile: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Change user password
     * @param int $userId
     * @param string $currentPassword
     * @param string $newPassword
     * @return bool
     */
    public function changePassword($userId, $currentPassword, $newPassword) {
        try {
            // Verify current password
            $sql = "SELECT password FROM users WHERE id = ?";
            $user = fetchOne($sql, [$userId]);
            
            if (!$user || !verifyPassword($currentPassword, $user['password'])) {
                return false;
            }
            
            // Update password
            $hashedPassword = hashPassword($newPassword);
            $sql = "UPDATE users SET password = ? WHERE id = ?";
            
            return updateData($sql, [$hashedPassword, $userId]) > 0;
        } catch (Exception $e) {
            error_log("Error changing password: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Update last login time
     * @param int $userId
     * @return bool
     */
    private function updateLastLogin($userId) {
        try {
            $sql = "UPDATE users SET updated_at = NOW() WHERE id = ?";
            return updateData($sql, [$userId]) > 0;
        } catch (Exception $e) {
            error_log("Error updating last login: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Create user session
     * @param array $user
     * @return bool
     */
    public function createSession($user) {
        try {
            // Generate session token
            $sessionToken = bin2hex(random_bytes(32));
            
            // Store user data in session
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_name'] = $user['name'];
            $_SESSION['user_email'] = $user['email'];
            $_SESSION['user_role'] = $user['role'];
            $_SESSION['user_logged_in'] = true;
            $_SESSION['session_token'] = $sessionToken;
            $_SESSION['login_time'] = time();
            
            // Set role-specific session variables
            switch ($user['role']) {
                case 'customer':
                    $_SESSION['customer_logged_in'] = true;
                    $_SESSION['customer_id'] = $user['id'];
                    break;
                case 'delivery':
                    $_SESSION['delivery_logged_in'] = true;
                    $_SESSION['delivery_id'] = $user['id'];
                    break;
                case 'admin':
                    $_SESSION['admin_logged_in'] = true;
                    $_SESSION['admin_id'] = $user['id'];
                    break;
            }
            
            return true;
        } catch (Exception $e) {
            error_log("Error creating session: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Destroy user session
     * @return bool
     */
    public function destroySession() {
        try {
            // Clear all session variables
            $_SESSION = array();
            
            // Destroy the session
            if (ini_get("session.use_cookies")) {
                $params = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000,
                    $params["path"], $params["domain"],
                    $params["secure"], $params["httponly"]
                );
            }
            
            session_destroy();
            return true;
        } catch (Exception $e) {
            error_log("Error destroying session: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Check if user is logged in
     * @param string $role
     * @return bool
     */
    public function isLoggedIn($role = null) {
        if (!isset($_SESSION['user_logged_in']) || !$_SESSION['user_logged_in']) {
            return false;
        }
        
        if ($role && $_SESSION['user_role'] !== $role) {
            return false;
        }
        
        // Check session timeout (24 hours)
        if (isset($_SESSION['login_time']) && (time() - $_SESSION['login_time']) > 86400) {
            $this->destroySession();
            return false;
        }
        
        return true;
    }
    
    /**
     * Get current user data
     * @return array|false
     */
    public function getCurrentUser() {
        if (!$this->isLoggedIn()) {
            return false;
        }
        
        return [
            'id' => $_SESSION['user_id'],
            'name' => $_SESSION['user_name'],
            'email' => $_SESSION['user_email'],
            'role' => $_SESSION['user_role']
        ];
    }
    
    /**
     * Require authentication for specific role
     * @param string $role
     * @param string $redirectUrl
     */
    public function requireAuth($role = null, $redirectUrl = 'login.php') {
        if (!$this->isLoggedIn($role)) {
            header("Location: $redirectUrl");
            exit;
        }
    }
    
    /**
     * Generate password reset token
     * @param string $email
     * @return string|false
     */
    public function generatePasswordResetToken($email) {
        try {
            // Check if user exists
            $sql = "SELECT id FROM users WHERE email = ? AND status = 'active'";
            $user = fetchOne($sql, [$email]);
            
            if (!$user) {
                return false;
            }
            
            // Generate token
            $token = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));
            
            // Store token in database (you might want to create a password_resets table)
            // For now, we'll store it in session
            $_SESSION['password_reset_token'] = $token;
            $_SESSION['password_reset_email'] = $email;
            $_SESSION['password_reset_expires'] = $expires;
            
            return $token;
        } catch (Exception $e) {
            error_log("Error generating password reset token: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Verify password reset token
     * @param string $token
     * @return bool
     */
    public function verifyPasswordResetToken($token) {
        if (!isset($_SESSION['password_reset_token']) || 
            !isset($_SESSION['password_reset_expires']) ||
            !isset($_SESSION['password_reset_email'])) {
            return false;
        }
        
        if ($_SESSION['password_reset_token'] !== $token) {
            return false;
        }
        
        if (strtotime($_SESSION['password_reset_expires']) < time()) {
            // Token expired
            unset($_SESSION['password_reset_token']);
            unset($_SESSION['password_reset_expires']);
            unset($_SESSION['password_reset_email']);
            return false;
        }
        
        return true;
    }
    
    /**
     * Reset password using token
     * @param string $token
     * @param string $newPassword
     * @return bool
     */
    public function resetPassword($token, $newPassword) {
        if (!$this->verifyPasswordResetToken($token)) {
            return false;
        }
        
        try {
            $email = $_SESSION['password_reset_email'];
            $hashedPassword = hashPassword($newPassword);
            
            $sql = "UPDATE users SET password = ? WHERE email = ?";
            $result = updateData($sql, [$hashedPassword, $email]);
            
            if ($result) {
                // Clear reset session data
                unset($_SESSION['password_reset_token']);
                unset($_SESSION['password_reset_expires']);
                unset($_SESSION['password_reset_email']);
                return true;
            }
            
            return false;
        } catch (Exception $e) {
            error_log("Error resetting password: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Check if maintenance mode is enabled
     * @return bool
     */
    public function isMaintenanceMode() {
        try {
            $sql = "SELECT setting_value FROM system_settings WHERE setting_name = 'maintenance_mode'";
            $stmt = executeQuery($sql);
            $result = $stmt->fetch();
            return $result && $result['setting_value'] == '1';
        } catch (Exception $e) {
            // If table doesn't exist, maintenance mode is off
            return false;
        }
    }
    
    /**
     * Block access if maintenance mode is enabled (except for admins)
     * Redirects to maintenance.html if conditions are met
     */
    public function checkMaintenanceMode() {
        if ($this->isMaintenanceMode()) {
            // Allow admins to bypass maintenance mode
            if (!$this->isLoggedIn() || $this->getCurrentUser()['role'] !== 'admin') {
                header('Location: maintenance.html');
                exit;
            }
        }
    }
}

// Initialize authentication
$auth = new Auth();
?>
