<?php
require_once 'config/database.php';

try {
    // Get database connection
    $pdo = getDBConnection();
    
    echo "Adding missing payment columns to orders table...\n";
    
    // Add payment_method column
    try {
        $sql = "ALTER TABLE orders ADD COLUMN payment_method ENUM('card', 'cod', 'upi') DEFAULT 'cod'";
        $pdo->exec($sql);
        echo "✓ Added payment_method column\n";
    } catch (Exception $e) {
        if (strpos($e->getMessage(), 'Duplicate column') !== false) {
            echo "• payment_method column already exists\n";
        } else {
            throw $e;
        }
    }
    
    // Add payment_reference column
    try {
        $sql = "ALTER TABLE orders ADD COLUMN payment_reference VARCHAR(100) NULL";
        $pdo->exec($sql);
        echo "✓ Added payment_reference column\n";
    } catch (Exception $e) {
        if (strpos($e->getMessage(), 'Duplicate column') !== false) {
            echo "• payment_reference column already exists\n";
        } else {
            throw $e;
        }
    }
    
    // Add payment_amount column
    try {
        $sql = "ALTER TABLE orders ADD COLUMN payment_amount DECIMAL(10,2) NULL";
        $pdo->exec($sql);
        echo "✓ Added payment_amount column\n";
    } catch (Exception $e) {
        if (strpos($e->getMessage(), 'Duplicate column') !== false) {
            echo "• payment_amount column already exists\n";
        } else {
            throw $e;
        }
    }
    
    // Add paid_at column
    try {
        $sql = "ALTER TABLE orders ADD COLUMN paid_at TIMESTAMP NULL";
        $pdo->exec($sql);
        echo "✓ Added paid_at column\n";
    } catch (Exception $e) {
        if (strpos($e->getMessage(), 'Duplicate column') !== false) {
            echo "• paid_at column already exists\n";
        } else {
            throw $e;
        }
    }
    
    // Update existing payment_status to include 'failed' if not present
    try {
        $sql = "ALTER TABLE orders MODIFY COLUMN payment_status ENUM('pending', 'paid', 'failed', 'refunded') DEFAULT 'pending'";
        $pdo->exec($sql);
        echo "✓ Updated payment_status enum\n";
    } catch (Exception $e) {
        echo "• payment_status enum update: " . $e->getMessage() . "\n";
    }
    
    // Update existing orders to have payment amounts
    echo "Updating existing orders with payment amounts...\n";
    $update_sql = "UPDATE orders SET payment_amount = total_amount WHERE payment_amount IS NULL";
    $pdo->exec($update_sql);
    
    echo "\n✅ Payment system setup completed!\n";
    echo "Available payment methods: card, cod, upi\n";
    echo "Payment statuses: pending, paid, failed, refunded\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
?>