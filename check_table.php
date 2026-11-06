<?php
require_once 'config/database.php';

try {
    // Get database connection
    $pdo = getDBConnection();
    
    echo "Checking orders table structure...\n";
    
    // Show all columns in orders table
    $columns_check = "SHOW COLUMNS FROM orders";
    $result = $pdo->query($columns_check);
    $columns = $result->fetchAll();
    
    echo "Current columns in orders table:\n";
    foreach ($columns as $column) {
        echo "- " . $column['Field'] . " (" . $column['Type'] . ")\n";
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>