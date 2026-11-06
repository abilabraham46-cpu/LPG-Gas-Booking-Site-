<?php
session_start();

// Test database connection
require_once 'config/database.php';

echo "<h1>Order Placement Debug Test</h1>";

// Test 1: Database connection
echo "<h2>1. Database Connection Test</h2>";
try {
    $pdo = getDBConnection();
    echo "✅ Database connected successfully<br>";
} catch (Exception $e) {
    echo "❌ Database connection failed: " . $e->getMessage() . "<br>";
    exit;
}

// Test 2: Session check
echo "<h2>2. Session Test</h2>";
echo "Session ID: " . session_id() . "<br>";
echo "User logged in: " . (isset($_SESSION['user_logged_in']) ? 'Yes' : 'No') . "<br>";
echo "User ID: " . ($_SESSION['user_id'] ?? 'Not set') . "<br>";
echo "User role: " . ($_SESSION['user_role'] ?? 'Not set') . "<br>";

// Test 3: Check if form data is being received
echo "<h2>3. Form Data Test</h2>";
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    echo "✅ POST request received<br>";
    echo "POST data: <pre>" . print_r($_POST, true) . "</pre>";
    
    // Test minimal order insertion
    if (isset($_POST['test_order'])) {
        try {
            // First, let's check what users exist
            echo "<h3>Available Users:</h3>";
            $user_stmt = $pdo->query("SELECT id, name, email, role FROM users LIMIT 10");
            $users = $user_stmt->fetchAll();
            foreach ($users as $user) {
                echo "ID: {$user['id']}, Name: {$user['name']}, Email: {$user['email']}, Role: {$user['role']}<br>";
            }
            
            // Use the first available user ID or session user ID
            $test_customer_id = $_SESSION['user_id'] ?? ($users[0]['id'] ?? 1);
            echo "<br>Using customer ID: $test_customer_id<br><br>";
            
            $sql = "INSERT INTO orders (customer_id, cylinder_type, quantity, delivery_address, delivery_date, contact_number, total_amount, payment_method, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
            $stmt = $pdo->prepare($sql);
            $result = $stmt->execute([
                $test_customer_id,
                'Test 5kg',
                1,
                'Test Address',
                date('Y-m-d', strtotime('+1 day')),
                '9876543210',
                100.00,
                'cod',
                'confirmed'
            ]);
            
            if ($result) {
                $order_id = $pdo->lastInsertId();
                echo "✅ Test order created successfully! Order ID: " . $order_id . "<br>";
            } else {
                echo "❌ Failed to create test order<br>";
            }
        } catch (Exception $e) {
            echo "❌ Error creating test order: " . $e->getMessage() . "<br>";
        }
    }
} else {
    echo "No POST data received<br>";
}

// Test 4: Simple form to test order placement
echo "<h2>4. Simple Order Test Form</h2>";
?>
<form method="POST" action="">
    <input type="hidden" name="test_order" value="1">
    <button type="submit" style="padding: 10px; background: green; color: white; border: none;">
        Create Test Order
    </button>
</form>

<h2>5. Database Tables Check</h2>
<?php
// Check if required tables exist
$tables = ['orders', 'users', 'cylinder_inventory'];
foreach ($tables as $table) {
    try {
        $stmt = $pdo->query("SELECT COUNT(*) FROM $table");
        $count = $stmt->fetchColumn();
        echo "✅ Table '$table' exists with $count records<br>";
    } catch (Exception $e) {
        echo "❌ Table '$table' error: " . $e->getMessage() . "<br>";
    }
}
?>

<h2>6. Go Back</h2>
<a href="customer-portal.php" style="padding: 10px; background: blue; color: white; text-decoration: none;">
    Back to Customer Portal
</a>