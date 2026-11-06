<?php
/**
 * Simple Admin Fix Tool
 * Creates admin user with the correct table structure
 */

require_once 'config/database.php';

try {
    $pdo = getDBConnection();
    
    // First, let's check what columns exist in the users table
    $columns_stmt = $pdo->query("DESCRIBE users");
    $columns = $columns_stmt->fetchAll();
    
    echo "📋 Users table structure:\n";
    foreach ($columns as $col) {
        echo "- " . $col['Field'] . " (" . $col['Type'] . ")\n";
    }
    echo "\n";
    
    // Check current admin users
    $admin_stmt = $pdo->query("SELECT id, name, email, role FROM users WHERE role = 'admin'");
    $admins = $admin_stmt->fetchAll();
    
    echo "👥 Current admin users:\n";
    if (empty($admins)) {
        echo "- No admin users found\n";
    } else {
        foreach ($admins as $admin) {
            echo "- ID: {$admin['id']}, Name: {$admin['name']}, Email: {$admin['email']}\n";
        }
    }
    echo "\n";
    
    // Create admin user based on available columns
    $column_names = array_column($columns, 'Field');
    
    // Delete existing admin to avoid duplicates
    $delete_stmt = $pdo->prepare("DELETE FROM users WHERE email = 'admin@liaa.com'");
    $delete_stmt->execute();
    
    // Create new admin with correct password
    $admin_password = password_hash('admin123', PASSWORD_DEFAULT);
    
    if (in_array('aadhaar_number', $column_names)) {
        // Full structure with aadhaar_number
        $sql = "INSERT INTO users (name, email, phone, aadhaar_number, password, role, address) VALUES (?, ?, ?, ?, ?, ?, ?)";
        $params = ['Admin', 'admin@liaa.com', '+91-9876543210', '123456789012', $admin_password, 'admin', 'Admin Office'];
    } else {
        // Basic structure without aadhaar_number
        $sql = "INSERT INTO users (name, email, phone, password, role, address) VALUES (?, ?, ?, ?, ?, ?)";
        $params = ['Admin', 'admin@liaa.com', '+91-9876543210', $admin_password, 'admin', 'Admin Office'];
    }
    
    $stmt = $pdo->prepare($sql);
    $result = $stmt->execute($params);
    
    if ($result) {
        echo "✅ SUCCESS! Admin user created!\n\n";
        echo "🔑 LOGIN CREDENTIALS:\n";
        echo "Email: admin@liaa.com\n";
        echo "Password: admin123\n\n";
        
        // Test the authentication
        require_once 'includes/auth.php';
        $auth = new Auth();
        $test_user = $auth->authenticate('admin@liaa.com', 'admin123', 'admin');
        
        if ($test_user) {
            echo "✅ Authentication test PASSED!\n";
            echo "🚀 You can now login to admin-portal.php\n";
        } else {
            echo "❌ Authentication test FAILED\n";
            echo "📧 Try using email: admin@liaa.com\n";
            echo "🔑 Try using password: admin123\n";
        }
        
    } else {
        echo "❌ Failed to create admin user\n";
    }
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
?>