<?php
require_once 'config/database.php';

echo "Restocking all cylinders...\n";

try {
    // Update all cylinders to have good stock levels
    $cylinders = [
        '14.2kg Domestic' => 100,
        '19kg Commercial' => 80,
        '35kg Industrial' => 50,
        '5kg Portable' => 120
    ];
    
    foreach ($cylinders as $type => $stock) {
        $sql = "UPDATE cylinder_inventory 
                SET available_quantity = ?, 
                    total_quantity = ?,
                    status = 'active',
                    updated_at = NOW() 
                WHERE cylinder_type = ?";
        
        $stmt = executeQuery($sql, [$stock, $stock, $type]);
        echo "Restocked $type: $stock units\n";
        
        // Log the restock action
        $log_sql = "INSERT INTO inventory_logs (cylinder_id, action_type, old_quantity, new_quantity, changed_by, notes) 
                   SELECT id, 'restock', available_quantity, ?, 1, 'Admin restock - ensuring availability' 
                   FROM cylinder_inventory WHERE cylinder_type = ?";
        try {
            executeQuery($log_sql, [$stock, $type]);
        } catch (Exception $e) {
            // Continue if logging fails
        }
    }
    
    echo "\nAll cylinders restocked successfully!\n";
    
    // Show updated inventory
    $stmt = executeQuery('SELECT cylinder_type, total_quantity, available_quantity, status FROM cylinder_inventory ORDER BY cylinder_type');
    $cylinders = $stmt->fetchAll();
    
    echo "\nUpdated Inventory Status:\n";
    echo "========================\n";
    
    foreach ($cylinders as $cylinder) {
        echo sprintf("%-20s | Total: %-3d | Available: %-3d | Status: %s\n", 
            $cylinder['cylinder_type'], 
            $cylinder['total_quantity'], 
            $cylinder['available_quantity'], 
            $cylinder['status']
        );
    }
    
} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage();
}
?>