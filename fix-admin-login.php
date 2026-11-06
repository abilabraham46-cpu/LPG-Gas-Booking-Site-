<?php
/**
 * Admin Login Debug & Fix Tool
 * This script helps diagnose and fix admin login issues
 */

require_once 'config/database.php';

echo "<h2>🔧 Admin Login Debug & Fix Tool</h2>\n";
echo "<style>
body { font-family: Arial, sans-serif; max-width: 800px; margin: 20px auto; padding: 20px; }
.success { color: #27ae60; background: #d5f4e6; padding: 10px; border-radius: 8px; margin: 10px 0; }
.error { color: #e74c3c; background: #fdf2f2; padding: 10px; border-radius: 8px; margin: 10px 0; }
.info { color: #3498db; background: #ebf8ff; padding: 10px; border-radius: 8px; margin: 10px 0; }
.code { background: #f8f9fa; padding: 10px; border-radius: 4px; font-family: monospace; }
</style>\n";

try {
    $pdo = getDBConnection();
    echo "<div class='success'>✅ Database connection successful</div>\n";
    
    // Check if admin user exists
    $stmt = $pdo->prepare("SELECT id, name, email, role, password FROM users WHERE role = 'admin' LIMIT 1");
    $stmt->execute();
    $admin = $stmt->fetch();
    
    if ($admin) {
        echo "<div class='info'>👤 Admin user found:</div>\n";
        echo "<div class='code'>";
        echo "ID: " . $admin['id'] . "<br>";
        echo "Name: " . htmlspecialchars($admin['name']) . "<br>";
        echo "Email: " . htmlspecialchars($admin['email']) . "<br>";
        echo "Role: " . $admin['role'] . "<br>";
        echo "Password Hash: " . substr($admin['password'], 0, 20) . "...<br>";
        echo "</div>\n";
        
        // Test password verification
        $test_password = 'password';
        if (password_verify($test_password, $admin['password'])) {
            echo "<div class='success'>✅ Default password 'password' works correctly</div>\n";
        } else {
            echo "<div class='error'>❌ Default password 'password' doesn't work</div>\n";
            echo "<div class='info'>🔧 Creating new admin user with correct password...</div>\n";
            
            // Create new admin user
            $new_password = password_hash('password', PASSWORD_DEFAULT);
            $update_stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
            $update_stmt->execute([$new_password, $admin['id']]);
            
            echo "<div class='success'>✅ Admin password reset successfully</div>\n";
        }
        
    } else {
        echo "<div class='error'>❌ No admin user found in database</div>\n";
        echo "<div class='info'>🔧 Creating default admin user...</div>\n";
        
        // Create admin user
        $admin_password = password_hash('password', PASSWORD_DEFAULT);
        $insert_stmt = $pdo->prepare("INSERT INTO users (name, email, phone, aadhaar_number, password, role, address) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $insert_stmt->execute([
            'Admin',
            'admin@liaa.com',
            '+91-481-123-4567',
            '123456789012',
            $admin_password,
            'admin',
            'Logos Junction, Kottayam, Kerala'
        ]);
        
        echo "<div class='success'>✅ Admin user created successfully</div>\n";
    }
    
    echo "<h3>🎯 Login Credentials:</h3>\n";
    echo "<div class='code'>";
    echo "<strong>Email:</strong> admin@liaa.com<br>";
    echo "<strong>Password:</strong> password<br>";
    echo "</div>\n";
    
    echo "<h3>🔍 Additional Checks:</h3>\n";
    
    // Check auth.php file
    if (file_exists('includes/auth.php')) {
        echo "<div class='success'>✅ Auth system file exists</div>\n";
    } else {
        echo "<div class='error'>❌ Auth system file missing</div>\n";
    }
    
    // Check database connection in auth
    require_once 'includes/auth.php';
    $auth = new Auth();
    echo "<div class='success'>✅ Auth class loaded successfully</div>\n";
    
    // Test authentication
    echo "<h3>🧪 Testing Authentication:</h3>\n";
    $test_user = $auth->authenticate('admin@liaa.com', 'password', 'admin');
    
    if ($test_user) {
        echo "<div class='success'>✅ Authentication test successful!</div>\n";
        echo "<div class='code'>";
        echo "User ID: " . $test_user['id'] . "<br>";
        echo "Name: " . htmlspecialchars($test_user['name']) . "<br>";
        echo "Email: " . htmlspecialchars($test_user['email']) . "<br>";
        echo "Role: " . $test_user['role'] . "<br>";
        echo "</div>\n";
    } else {
        echo "<div class='error'>❌ Authentication test failed</div>\n";
        
        // Debug: Check what's happening
        echo "<div class='info'>🔍 Debugging authentication...</div>\n";
        
        $debug_stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? AND role = ?");
        $debug_stmt->execute(['admin@liaa.com', 'admin']);
        $debug_user = $debug_stmt->fetch();
        
        if ($debug_user) {
            echo "<div class='info'>User found in database</div>\n";
            $password_check = password_verify('password', $debug_user['password']);
            echo "<div class='info'>Password verification: " . ($password_check ? 'PASSED' : 'FAILED') . "</div>\n";
        } else {
            echo "<div class='error'>User not found in database with email 'admin@liaa.com' and role 'admin'</div>\n";
        }
    }
    
} catch (Exception $e) {
    echo "<div class='error'>❌ Error: " . $e->getMessage() . "</div>\n";
}

echo "<h3>🚀 Try Login Now:</h3>\n";
echo "<p><a href='admin-portal.php' style='background: #3498db; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>Go to Admin Portal</a></p>\n";

echo "<h3>📝 Summary:</h3>\n";
echo "<div class='info'>";
echo "• Default admin email: <strong>admin@liaa.com</strong><br>";
echo "• Default admin password: <strong>password</strong><br>";
echo "• Make sure to change the password after first login<br>";
echo "• If still having issues, check your database connection<br>";
echo "</div>\n";
?>