<?php
require_once 'config/database.php';

try {
    // Get database connection
    $pdo = getDBConnection();
    
    echo "Adding Google Pay to payment method options...\n";
    
    // Update payment_method enum to include Google Pay
    $sql = "ALTER TABLE orders MODIFY COLUMN payment_method ENUM('card', 'cod', 'upi', 'gpay') DEFAULT 'cod'";
    $pdo->exec($sql);
    
    echo "✓ Google Pay added to payment methods\n";
    echo "Available payment methods: card, cod, upi, gpay\n";
    
    // Show instructions for setting up Google Pay
    echo "\n📱 GOOGLE PAY SETUP INSTRUCTIONS:\n";
    echo "================================\n";
    echo "1. Edit payments/gpay_payment.php\n";
    echo "2. Replace 'your-google-pay@okaxis' with your real UPI ID\n";
    echo "3. Example: 'yourname@paytm', 'yourname@ybl', 'yourname@okicici'\n";
    echo "4. Customers will pay directly to your Google Pay account\n";
    echo "5. Use admin/payment-verification.php to verify transactions\n";
    echo "\n✅ Real payment system is now ready!\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
?>