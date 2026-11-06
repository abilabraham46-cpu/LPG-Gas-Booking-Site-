<?php
require_once 'config/database.php';

try {
    $stmt = executeQuery('SELECT cylinder_type, total_quantity, available_quantity, status FROM cylinder_inventory ORDER BY cylinder_type');
    $cylinders = $stmt->fetchAll();
    
    echo "Current Cylinder Inventory Status:\n";
    echo "=====================================\n";
    
    foreach ($cylinders as $cylinder) {
        echo sprintf("%-20s | Total: %-3d | Available: %-3d | Status: %s\n", 
            $cylinder['cylinder_type'], 
            $cylinder['total_quantity'], 
            $cylinder['available_quantity'], 
            $cylinder['status']
        );
    }
    
    echo "\n";
    echo "Cylinders with 0 availability:\n";
    echo "==============================\n";
    
    foreach ($cylinders as $cylinder) {
        if ($cylinder['available_quantity'] == 0) {
            echo "- " . $cylinder['cylinder_type'] . " (Total: " . $cylinder['total_quantity'] . ")\n";
        }
    }
    
} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage();
}
?>