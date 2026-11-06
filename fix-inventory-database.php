<?php
/**
 * Database Structure Checker and Fixer
 * Checks and creates necessary tables for inventory management
 */

require_once 'config/database.php';

echo "<h2>🔍 Database Structure Checker & Fixer</h2>\n";
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
    
    // Check if cylinder_inventory table exists
    $tables_stmt = $pdo->query("SHOW TABLES LIKE 'cylinder_inventory'");
    $table_exists = $tables_stmt->rowCount() > 0;
    
    if (!$table_exists) {
        echo "<div class='error'>❌ cylinder_inventory table not found</div>\n";
        echo "<div class='info'>🔧 Creating cylinder_inventory table...</div>\n";
        
        // Create cylinder_inventory table
        $create_table_sql = "
        CREATE TABLE `cylinder_inventory` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `cylinder_type` VARCHAR(100) NOT NULL UNIQUE,
          `unit_price` DECIMAL(10, 2) NOT NULL,
          `total_quantity` INT NOT NULL DEFAULT 0,
          `available_quantity` INT NOT NULL DEFAULT 0,
          `min_stock_alert` INT DEFAULT 10,
          `description` TEXT,
          `status` ENUM('active', 'inactive') DEFAULT 'active',
          `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        $pdo->exec($create_table_sql);
        echo "<div class='success'>✅ cylinder_inventory table created successfully</div>\n";
        
        // Insert default cylinder types
        $default_cylinders = [
            ['14.2kg Domestic', 899.00, 100, 85, 10, 'Standard domestic LPG cylinder for household use'],
            ['19kg Commercial', 1599.00, 50, 40, 8, 'Commercial grade LPG cylinder for business use'],
            ['5kg Portable', 449.00, 75, 65, 15, 'Portable LPG cylinder for outdoor and camping use'],
            ['35kg Industrial', 2899.00, 25, 20, 5, 'Industrial grade LPG cylinder for heavy-duty applications']
        ];
        
        $insert_stmt = $pdo->prepare("INSERT INTO cylinder_inventory (cylinder_type, unit_price, total_quantity, available_quantity, min_stock_alert, description) VALUES (?, ?, ?, ?, ?, ?)");
        
        foreach ($default_cylinders as $cylinder) {
            $insert_stmt->execute($cylinder);
        }
        
        echo "<div class='success'>✅ Default cylinder types added successfully</div>\n";
        
    } else {
        echo "<div class='success'>✅ cylinder_inventory table exists</div>\n";
        
        // Check if min_stock_alert column exists
        $columns_stmt = $pdo->query("SHOW COLUMNS FROM cylinder_inventory LIKE 'min_stock_alert'");
        if ($columns_stmt->rowCount() == 0) {
            echo "<div class='info'>🔧 Adding min_stock_alert column...</div>\n";
            $pdo->exec("ALTER TABLE cylinder_inventory ADD COLUMN min_stock_alert INT DEFAULT 10 AFTER available_quantity");
            echo "<div class='success'>✅ min_stock_alert column added</div>\n";
        }
        
        // Check if created_at and updated_at columns exist
        $timestamp_columns = $pdo->query("SHOW COLUMNS FROM cylinder_inventory WHERE Field IN ('created_at', 'updated_at')");
        if ($timestamp_columns->rowCount() < 2) {
            echo "<div class='info'>🔧 Adding timestamp columns...</div>\n";
            $pdo->exec("ALTER TABLE cylinder_inventory 
                       ADD COLUMN IF NOT EXISTS created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                       ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
            echo "<div class='success'>✅ Timestamp columns added</div>\n";
        }
    }
    
    // Check current data
    $count_stmt = $pdo->query("SELECT COUNT(*) as count FROM cylinder_inventory");
    $count = $count_stmt->fetch()['count'];
    
    if ($count == 0) {
        echo "<div class='info'>📦 No cylinder data found. Adding sample data...</div>\n";
        
        $sample_data = [
            ['14.2kg Domestic', 899.00, 100, 85, 10, 'Standard domestic LPG cylinder for household cooking and heating'],
            ['19kg Commercial', 1599.00, 50, 40, 8, 'Commercial grade LPG cylinder for restaurants and businesses'],
            ['5kg Portable', 449.00, 75, 65, 15, 'Lightweight portable cylinder for camping and outdoor activities'],
            ['35kg Industrial', 2899.00, 25, 20, 5, 'Heavy-duty industrial cylinder for manufacturing operations']
        ];
        
        $insert_stmt = $pdo->prepare("INSERT INTO cylinder_inventory (cylinder_type, unit_price, total_quantity, available_quantity, min_stock_alert, description) VALUES (?, ?, ?, ?, ?, ?)");
        
        foreach ($sample_data as $data) {
            try {
                $insert_stmt->execute($data);
            } catch (Exception $e) {
                // Might already exist, continue
            }
        }
        
        echo "<div class='success'>✅ Sample data added successfully</div>\n";
    }
    
    // Test the query that was failing
    echo "<div class='info'>🧪 Testing inventory query...</div>\n";
    
    $test_sql = "SELECT *, 
            CASE 
                WHEN available_quantity <= min_stock_alert THEN 'low'
                WHEN available_quantity <= (total_quantity * 0.3) THEN 'medium'
                ELSE 'good'
            END as stock_level
            FROM cylinder_inventory 
            ORDER BY cylinder_type";
    
    $test_stmt = $pdo->query($test_sql);
    $inventory = $test_stmt->fetchAll();
    
    echo "<div class='success'>✅ Inventory query test successful!</div>\n";
    echo "<div class='info'>📊 Found " . count($inventory) . " cylinder types</div>\n";
    
    // Display current inventory
    echo "<h3>📦 Current Inventory:</h3>\n";
    echo "<div class='code'>";
    foreach ($inventory as $item) {
        echo "• {$item['cylinder_type']} - ₹{$item['unit_price']} - {$item['available_quantity']}/{$item['total_quantity']} units ({$item['stock_level']} stock)<br>";
    }
    echo "</div>\n";
    
    // Test statistics query
    echo "<div class='info'>🧪 Testing statistics query...</div>\n";
    
    $stats_sql = "SELECT 
                    COUNT(*) as total_types,
                    SUM(total_quantity) as total_cylinders,
                    SUM(available_quantity) as available_cylinders,
                    COUNT(CASE WHEN status = 'active' THEN 1 END) as active_types,
                    COUNT(CASE WHEN available_quantity <= min_stock_alert THEN 1 END) as low_stock_types
                  FROM cylinder_inventory";
    
    $stats_stmt = $pdo->query($stats_sql);
    $stats = $stats_stmt->fetch();
    
    echo "<div class='success'>✅ Statistics query test successful!</div>\n";
    echo "<div class='code'>";
    echo "Total Types: {$stats['total_types']}<br>";
    echo "Total Cylinders: {$stats['total_cylinders']}<br>";
    echo "Available: {$stats['available_cylinders']}<br>";
    echo "Active Types: {$stats['active_types']}<br>";
    echo "Low Stock Alerts: {$stats['low_stock_types']}<br>";
    echo "</div>\n";
    
    echo "<h3>🎉 All checks passed!</h3>\n";
    echo "<div class='success'>The inventory management system should now work properly.</div>\n";
    
} catch (Exception $e) {
    echo "<div class='error'>❌ Error: " . $e->getMessage() . "</div>\n";
    
    // Provide specific fixes based on error type
    if (strpos($e->getMessage(), 'cylinder_inventory') !== false) {
        echo "<div class='info'>💡 Try running the database setup script first: database_setup.sql</div>\n";
    }
    
    if (strpos($e->getMessage(), 'Connection') !== false) {
        echo "<div class='info'>💡 Check your database connection settings in config/database.php</div>\n";
    }
}

echo "<p><a href='admin/cylinder-inventory.php'>→ Go to Inventory Management</a> | <a href='admin-portal.php'>← Back to Admin Portal</a></p>\n";
?>