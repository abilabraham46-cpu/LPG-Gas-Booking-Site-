<?php
/**
 * Quick Admin Reset Tool
 * Creates/resets admin user with correct credentials
 */

require_once 'config/database.php';

try {
    $pdo = getDBConnection();
    
    // First, delete any existing admin users to avoid conflicts
    $delete_stmt = $pdo->prepare("DELETE FROM users WHERE role = 'admin'");
    $delete_stmt->execute();
    
    // Create fresh admin user with properly hashed password
    $admin_password = password_hash('admin123', PASSWORD_DEFAULT);
    
    $insert_stmt = $pdo->prepare("INSERT INTO users (name, email, phone, aadhaar_number, password, role, address, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $result = $insert_stmt->execute([
        'System Administrator',
        'admin@liaa.com',
        '+91-9876543210',
        '123456789012',
        $admin_password,
        'admin',
        'Admin Office, Kottayam, Kerala',
        'active'
    ]);
    
    if ($result) {
        echo "✅ SUCCESS! Admin user created successfully!\n\n";
        echo "🔑 NEW LOGIN CREDENTIALS:\n";
        echo "Email: admin@liaa.com\n";
        echo "Password: admin123\n\n";
        echo "🚀 You can now login to the admin portal!\n";
        echo "📍 Go to: admin-portal.php\n";
    } else {
        echo "❌ Failed to create admin user\n";
    }
    
    // Also create a backup admin
    $backup_password = password_hash('password', PASSWORD_DEFAULT);
    $backup_stmt = $pdo->prepare("INSERT INTO users (name, email, phone, aadhaar_number, password, role, address, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $backup_stmt->execute([
        'Backup Admin',
        'admin2@liaa.com',
        '+91-9876543211',
        '123456789013',
        $backup_password,
        'admin',
        'Backup Admin Office, Kottayam, Kerala',
        'active'
    ]);
    
    echo "\n🔒 Backup admin also created:\n";
    echo "Email: admin2@liaa.com\n";
    echo "Password: password\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    echo "Please check your database connection.\n";
}
?>