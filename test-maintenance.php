<?php
require_once 'config/database.php';

echo "<h2>Maintenance Mode Debug Test</h2>";
echo "<pre>";

// 1. Check if table exists
echo "=== STEP 1: Check if system_settings table exists ===\n";
try {
    $result = executeQuery("SHOW TABLES LIKE 'system_settings'");
    $table_exists = $result->fetch();
    if ($table_exists) {
        echo "✓ Table EXISTS\n\n";
    } else {
        echo "✗ Table DOES NOT EXIST - Creating it now...\n";
        $create_sql = "CREATE TABLE IF NOT EXISTS system_settings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            setting_name VARCHAR(100) UNIQUE,
            setting_value TEXT,
            updated_by INT,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )";
        executeQuery($create_sql);
        echo "✓ Table CREATED\n\n";
    }
} catch (Exception $e) {
    echo "✗ ERROR: " . $e->getMessage() . "\n\n";
}

// 2. Check table structure
echo "=== STEP 2: Table Structure ===\n";
try {
    $result = executeQuery("DESCRIBE system_settings");
    $columns = $result->fetchAll(PDO::FETCH_ASSOC);
    foreach ($columns as $col) {
        echo $col['Field'] . " | " . $col['Type'] . " | " . $col['Key'] . "\n";
    }
    echo "\n";
} catch (Exception $e) {
    echo "✗ ERROR: " . $e->getMessage() . "\n\n";
}

// 3. Check current data
echo "=== STEP 3: Current Data ===\n";
try {
    $result = executeQuery("SELECT * FROM system_settings");
    $rows = $result->fetchAll(PDO::FETCH_ASSOC);
    if (empty($rows)) {
        echo "⚠ Table is EMPTY - No settings found\n\n";
    } else {
        foreach ($rows as $row) {
            print_r($row);
        }
        echo "\n";
    }
} catch (Exception $e) {
    echo "✗ ERROR: " . $e->getMessage() . "\n\n";
}

// 4. Try to insert/update maintenance mode
echo "=== STEP 4: Test INSERT/UPDATE ===\n";
try {
    $test_value = 1; // Enable maintenance
    $test_user_id = 1;
    
    $sql = "INSERT INTO system_settings (setting_name, setting_value, updated_by) 
            VALUES ('maintenance_mode', ?, ?) 
            ON DUPLICATE KEY UPDATE 
            setting_value = ?,
            updated_by = ?,
            updated_at = CURRENT_TIMESTAMP";
    
    echo "Executing SQL with maintenance_mode = $test_value\n";
    executeQuery($sql, [$test_value, $test_user_id, $test_value, $test_user_id]);
    echo "✓ SQL executed successfully\n\n";
    
} catch (Exception $e) {
    echo "✗ ERROR: " . $e->getMessage() . "\n\n";
}

// 5. Verify the insert/update
echo "=== STEP 5: Verify Data After Update ===\n";
try {
    $result = executeQuery("SELECT * FROM system_settings WHERE setting_name = 'maintenance_mode'");
    $row = $result->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        print_r($row);
        echo "\nCurrent maintenance_mode value: " . $row['setting_value'] . "\n";
        echo ($row['setting_value'] == '1' ? "✓ Maintenance ENABLED" : "✓ Maintenance DISABLED") . "\n\n";
    } else {
        echo "✗ No maintenance_mode setting found!\n\n";
    }
} catch (Exception $e) {
    echo "✗ ERROR: " . $e->getMessage() . "\n\n";
}

// 6. Test toggle to OFF
echo "=== STEP 6: Test Toggle to OFF ===\n";
try {
    $test_value = 0; // Disable maintenance
    $test_user_id = 1;
    
    $sql = "INSERT INTO system_settings (setting_name, setting_value, updated_by) 
            VALUES ('maintenance_mode', ?, ?) 
            ON DUPLICATE KEY UPDATE 
            setting_value = ?,
            updated_by = ?,
            updated_at = CURRENT_TIMESTAMP";
    
    echo "Executing SQL with maintenance_mode = $test_value\n";
    executeQuery($sql, [$test_value, $test_user_id, $test_value, $test_user_id]);
    echo "✓ SQL executed successfully\n\n";
    
    // Verify
    $result = executeQuery("SELECT setting_value FROM system_settings WHERE setting_name = 'maintenance_mode'");
    $row = $result->fetch(PDO::FETCH_ASSOC);
    echo "Current value after toggle: " . $row['setting_value'] . "\n";
    echo ($row['setting_value'] == '0' ? "✓ Successfully toggled to DISABLED" : "✗ Failed to toggle") . "\n\n";
    
} catch (Exception $e) {
    echo "✗ ERROR: " . $e->getMessage() . "\n\n";
}

echo "=== TEST COMPLETE ===\n";
echo "</pre>";

echo "<p><a href='system-settings.php'>Go back to System Settings</a></p>";
?>
