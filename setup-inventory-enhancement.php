<?php
/**
 * Inventory System Enhancement Setup
 * Run this script to upgrade the inventory management system
 */

require_once 'config/database.php';

echo "<h2>🔧 Setting up Enhanced Inventory Management System</h2>\n";
echo "<pre>\n";

try {
    $pdo = getDBConnection();
    
    // Read and execute the enhancement SQL
    $sql = file_get_contents('enhance_inventory_system.sql');
    
    // Split into individual statements
    $statements = explode(';', $sql);
    
    $executed = 0;
    foreach ($statements as $statement) {
        $statement = trim($statement);
        if (!empty($statement) && !preg_match('/^(--|\/\*|\*)/', $statement)) {
            try {
                $pdo->exec($statement);
                $executed++;
            } catch (PDOException $e) {
                // Some statements might fail if already exists, that's OK
                if (!strpos($e->getMessage(), 'already exists')) {
                    echo "⚠️  Warning: " . $e->getMessage() . "\n";
                }
            }
        }
    }
    
    echo "✅ Successfully executed {$executed} SQL statements\n\n";
    
    // Verify the setup
    echo "🔍 Verifying setup...\n";
    
    // Check inventory_logs table
    try {
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM inventory_logs");
        $logs_count = $stmt->fetch()['count'];
        echo "✅ Inventory logs table: {$logs_count} entries\n";
    } catch (Exception $e) {
        echo "❌ Inventory logs table: Failed to verify\n";
    }
    
    // Check cylinder_reservations table
    try {
        $pdo->query("SELECT * FROM cylinder_reservations LIMIT 1");
        echo "✅ Cylinder reservations table: Ready\n";
    } catch (Exception $e) {
        echo "❌ Cylinder reservations table: Failed to create\n";
    }
    
    // Check enhanced cylinder_inventory
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM cylinder_inventory LIKE 'min_stock_alert'");
        if ($stmt->rowCount() > 0) {
            echo "✅ Enhanced cylinder inventory: min_stock_alert column added\n";
        } else {
            echo "❌ Enhanced cylinder inventory: Failed to add min_stock_alert\n";
        }
    } catch (Exception $e) {
        echo "❌ Enhanced cylinder inventory: " . $e->getMessage() . "\n";
    }
    
    // Check inventory summary view
    try {
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM inventory_summary");
        $summary_count = $stmt->fetch()['count'];
        echo "✅ Inventory summary view: {$summary_count} items\n";
    } catch (Exception $e) {
        echo "❌ Inventory summary view: Failed to create\n";
    }
    
    echo "\n🎉 Inventory system enhancement completed!\n\n";
    echo "📋 What's new:\n";
    echo "• Real-time inventory tracking and logging\n";
    echo "• Automatic stock level calculations (Low/Medium/Good)\n";
    echo "• Temporary cylinder reservations during checkout\n";
    echo "• Enhanced admin inventory management interface\n";
    echo "• Customer-side real-time availability display\n";
    echo "• Automated inventory alerts and notifications\n";
    echo "• Comprehensive inventory analytics and reporting\n\n";
    
    echo "🔗 Access points:\n";
    echo "• Admin Inventory Management: admin/cylinder-inventory.php\n";
    echo "• Inventory API: api/inventory.php\n";
    echo "• Customer Portal: customer-portal.php (enhanced)\n\n";
    
    echo "⚡ Features enabled:\n";
    echo "• Out of stock prevention\n";
    echo "• Low stock alerts\n";
    echo "• Real-time availability updates\n";
    echo "• Inventory change logging\n";
    echo "• Automated cleanup processes\n";
    
} catch (Exception $e) {
    echo "❌ Setup failed: " . $e->getMessage() . "\n";
    echo "Please check your database configuration and try again.\n";
}

echo "</pre>\n";
echo "<p><a href='admin-portal.php'>← Back to Admin Portal</a> | <a href='admin/cylinder-inventory.php'>Manage Inventory →</a></p>\n";
?>