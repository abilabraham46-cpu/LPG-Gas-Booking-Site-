<?php
require_once 'config/database.php';

try {
    $pdo = getDBConnection();
    $stmt = $pdo->query('DESCRIBE orders');
    $columns = $stmt->fetchAll();
    
    echo "Orders table structure:\n";
    foreach ($columns as $column) {
        echo "{$column['Field']} - {$column['Type']} - Null: {$column['Null']}\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>