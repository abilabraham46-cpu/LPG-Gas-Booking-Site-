<?php
require_once 'config/database.php';

echo "<h2>Delivery Staff Check</h2>";
echo "<hr>";

try {
    // Check delivery staff
    $stmt = executeQuery('SELECT id, name, email, phone, role, status FROM users WHERE role = "delivery"');
    $staff = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<h3>Delivery Staff Found: " . count($staff) . "</h3>";
    
    if (count($staff) > 0) {
        echo "<table border='1' cellpadding='10'>";
        echo "<tr><th>ID</th><th>Name</th><th>Email</th><th>Phone</th><th>Status</th></tr>";
        foreach($staff as $s) {
            echo "<tr>";
            echo "<td>" . $s['id'] . "</td>";
            echo "<td>" . $s['name'] . "</td>";
            echo "<td>" . $s['email'] . "</td>";
            echo "<td>" . ($s['phone'] ?? 'N/A') . "</td>";
            echo "<td>" . $s['status'] . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p style='color: red; font-weight: bold;'>⚠️ NO DELIVERY STAFF FOUND!</p>";
        echo "<p>You need to add delivery staff first.</p>";
        
        echo "<h3>Quick Fix: Add a Test Delivery Person</h3>";
        echo "<form method='POST'>";
        echo "<p><label>Name: <input type='text' name='add_name' value='Test Delivery Person' required></label></p>";
        echo "<p><label>Email: <input type='email' name='add_email' value='delivery@test.com' required></label></p>";
        echo "<p><label>Phone: <input type='text' name='add_phone' value='9876543210' required></label></p>";
        echo "<p><label>Password: <input type='password' name='add_password' value='delivery123' required></label></p>";
        echo "<p><button type='submit' name='add_delivery_staff'>Add Delivery Staff</button></p>";
        echo "</form>";
    }
    
    // Handle form submission
    if (isset($_POST['add_delivery_staff'])) {
        $name = $_POST['add_name'];
        $email = $_POST['add_email'];
        $phone = $_POST['add_phone'];
        $password = password_hash($_POST['add_password'], PASSWORD_DEFAULT);
        
        try {
            $insert_sql = "INSERT INTO users (name, email, phone, password, role, status, created_at) VALUES (?, ?, ?, ?, 'delivery', 'active', NOW())";
            executeQuery($insert_sql, [$name, $email, $phone, $password]);
            
            echo "<p style='color: green; font-weight: bold;'>✅ Delivery staff added successfully!</p>";
            echo "<p><a href='delivery-operations.php'>Go to Delivery Operations</a></p>";
            echo "<p><a href='check-delivery-staff.php'>Refresh this page</a></p>";
        } catch (Exception $e) {
            echo "<p style='color: red;'>Error: " . $e->getMessage() . "</p>";
        }
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>Error: " . $e->getMessage() . "</p>";
}
?>
