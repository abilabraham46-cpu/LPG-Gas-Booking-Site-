<?php
session_start();
require_once '../config/database.php';
require_once '../includes/auth.php';

$auth = new Auth();
if (!$auth->isLoggedIn() || $auth->getCurrentUser()['role'] !== 'admin') {
    header('Location: ../admin-login.php');
    exit;
}

// Handle payment verification
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['verify_payment'])) {
        $transaction_id = (int)$_POST['transaction_id'];
        $action = $_POST['action']; // 'verify' or 'reject'
        $notes = trim($_POST['notes'] ?? '');
        
        try {
            if ($action === 'verify') {
                // Update transaction status
                executeQuery("UPDATE payment_transactions SET status = 'verified', verified_at = NOW(), verified_by = ?, notes = ? WHERE id = ?", 
                           [$auth->getCurrentUser()['id'], $notes, $transaction_id]);
                
                // Get transaction details
                $stmt = executeQuery("SELECT order_id FROM payment_transactions WHERE id = ?", [$transaction_id]);
                $transaction = $stmt->fetch();
                
                if ($transaction) {
                    // Update order payment status
                    executeQuery("UPDATE orders SET payment_status = 'paid', paid_at = NOW() WHERE id = ?", 
                               [$transaction['order_id']]);
                }
                
                $success_message = "Payment verified successfully!";
            } else {
                // Reject payment
                executeQuery("UPDATE payment_transactions SET status = 'rejected', verified_at = NOW(), verified_by = ?, notes = ? WHERE id = ?", 
                           [$auth->getCurrentUser()['id'], $notes, $transaction_id]);
                
                $success_message = "Payment rejected.";
            }
        } catch (Exception $e) {
            $error_message = "Error processing payment verification: " . $e->getMessage();
        }
    }
}

// Get pending transactions
try {
    $sql = "SELECT pt.*, o.id as order_id, o.cylinder_type, o.quantity, o.total_amount, 
                   u.name as customer_name, u.email as customer_email
            FROM payment_transactions pt
            JOIN orders o ON pt.order_id = o.id
            JOIN users u ON o.customer_id = u.id
            WHERE pt.status = 'pending_verification'
            ORDER BY pt.created_at DESC";
    
    $pending_transactions = fetchAll($sql);
    
} catch (Exception $e) {
    $pending_transactions = [];
    $error_message = "Error fetching transactions: " . $e->getMessage();
}

// Get recent verified/rejected transactions
try {
    $sql = "SELECT pt.*, o.id as order_id, o.cylinder_type, o.quantity, o.total_amount, 
                   u.name as customer_name, u.email as customer_email,
                   v.name as verified_by_name
            FROM payment_transactions pt
            JOIN orders o ON pt.order_id = o.id
            JOIN users u ON o.customer_id = u.id
            LEFT JOIN users v ON pt.verified_by = v.id
            WHERE pt.status IN ('verified', 'rejected')
            ORDER BY pt.verified_at DESC
            LIMIT 20";
    
    $recent_transactions = fetchAll($sql);
    
} catch (Exception $e) {
    $recent_transactions = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Verification - Admin Panel</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #ff6b00;
            --secondary: #2c3e50;
            --success: #27ae60;
            --danger: #e74c3c;
            --warning: #f39c12;
            --gpay: #4285f4;
            --dark: #1a1a1a;
            --warm-gray: #ecf0f1;
            --glass-effect: rgba(255, 255, 255, 0.15);
            --shadow-light: 0 8px 32px rgba(0, 0, 0, 0.1);
            --border-radius: 16px;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, var(--dark) 0%, #2c3e50 100%);
            color: var(--warm-gray);
            min-height: 100vh;
            padding: 2rem;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
        }

        .header {
            text-align: center;
            margin-bottom: 3rem;
        }

        .header h1 {
            font-size: 3rem;
            color: var(--primary);
            margin-bottom: 1rem;
        }

        .section {
            background: var(--glass-effect);
            backdrop-filter: blur(30px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: var(--border-radius);
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow-light);
        }

        .section h2 {
            color: var(--primary);
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.8rem;
        }

        .transaction-card {
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.2);
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }

        .transaction-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }

        .transaction-id {
            font-family: 'Courier New', monospace;
            font-weight: bold;
            color: var(--gpay);
        }

        .transaction-amount {
            font-size: 1.5rem;
            font-weight: bold;
            color: var(--success);
        }

        .transaction-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .detail-item {
            display: flex;
            flex-direction: column;
            gap: 0.3rem;
        }

        .detail-label {
            font-size: 0.9rem;
            opacity: 0.8;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .detail-value {
            font-weight: 600;
        }

        .verification-form {
            display: flex;
            gap: 1rem;
            align-items: flex-end;
            flex-wrap: wrap;
        }

        .form-group {
            flex: 1;
            min-width: 200px;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
        }

        .form-group input, .form-group textarea {
            width: 100%;
            padding: 0.8rem;
            border: 1px solid rgba(255, 255, 255, 0.3);
            border-radius: 8px;
            background: rgba(255, 255, 255, 0.1);
            color: var(--warm-gray);
            font-size: 0.9rem;
        }

        .form-group textarea {
            resize: vertical;
            height: 60px;
        }

        .btn {
            padding: 0.8rem 1.5rem;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-size: 0.9rem;
        }

        .btn-verify {
            background: linear-gradient(135deg, var(--success), #1e8449);
            color: white;
        }

        .btn-verify:hover {
            background: linear-gradient(135deg, #1e8449, #196f3d);
            transform: translateY(-2px);
        }

        .btn-reject {
            background: linear-gradient(135deg, var(--danger), #c0392b);
            color: white;
        }

        .btn-reject:hover {
            background: linear-gradient(135deg, #c0392b, #a93226);
            transform: translateY(-2px);
        }

        .status-badge {
            padding: 0.4rem 0.8rem;
            border-radius: 8px;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-pending {
            background: rgba(243, 156, 18, 0.2);
            color: var(--warning);
            border: 1px solid rgba(243, 156, 18, 0.3);
        }

        .status-verified {
            background: rgba(39, 174, 96, 0.2);
            color: var(--success);
            border: 1px solid rgba(39, 174, 96, 0.3);
        }

        .status-rejected {
            background: rgba(231, 76, 60, 0.2);
            color: var(--danger);
            border: 1px solid rgba(231, 76, 60, 0.3);
        }

        .alert {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .alert-success {
            background: rgba(39, 174, 96, 0.1);
            color: var(--success);
            border: 1px solid rgba(39, 174, 96, 0.3);
        }

        .alert-error {
            background: rgba(231, 76, 60, 0.1);
            color: var(--danger);
            border: 1px solid rgba(231, 76, 60, 0.3);
        }

        .empty-state {
            text-align: center;
            padding: 3rem;
            opacity: 0.7;
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            color: var(--primary);
        }

        @media (max-width: 768px) {
            .verification-form {
                flex-direction: column;
            }
            
            .transaction-header {
                flex-direction: column;
                gap: 1rem;
                text-align: center;
            }
            
            .transaction-details {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><i class="fas fa-shield-check"></i> Payment Verification</h1>
            <p>Verify Google Pay and UPI transactions</p>
        </div>

        <?php if (isset($success_message)): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle"></i>
            <?php echo htmlspecialchars($success_message); ?>
        </div>
        <?php endif; ?>

        <?php if (isset($error_message)): ?>
        <div class="alert alert-error">
            <i class="fas fa-exclamation-triangle"></i>
            <?php echo htmlspecialchars($error_message); ?>
        </div>
        <?php endif; ?>

        <!-- Pending Transactions -->
        <div class="section">
            <h2><i class="fas fa-clock"></i> Pending Verification (<?php echo count($pending_transactions); ?>)</h2>
            
            <?php if (empty($pending_transactions)): ?>
            <div class="empty-state">
                <i class="fas fa-check-circle"></i>
                <h3>No pending transactions</h3>
                <p>All payments have been verified!</p>
            </div>
            <?php else: ?>
                <?php foreach ($pending_transactions as $transaction): ?>
                <div class="transaction-card">
                    <div class="transaction-header">
                        <div>
                            <div class="transaction-id">TXN: <?php echo htmlspecialchars($transaction['transaction_id']); ?></div>
                            <small>Order #<?php echo $transaction['order_id']; ?> • <?php echo date('M j, Y g:i A', strtotime($transaction['created_at'])); ?></small>
                        </div>
                        <div class="transaction-amount">₹<?php echo number_format($transaction['amount'], 2); ?></div>
                    </div>

                    <div class="transaction-details">
                        <div class="detail-item">
                            <div class="detail-label">Customer</div>
                            <div class="detail-value"><?php echo htmlspecialchars($transaction['customer_name']); ?></div>
                            <small><?php echo htmlspecialchars($transaction['customer_email']); ?></small>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Order Details</div>
                            <div class="detail-value"><?php echo htmlspecialchars($transaction['cylinder_type']); ?></div>
                            <small><?php echo $transaction['quantity']; ?> units</small>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Customer UPI</div>
                            <div class="detail-value"><?php echo htmlspecialchars($transaction['customer_upi_id']); ?></div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Merchant UPI</div>
                            <div class="detail-value"><?php echo htmlspecialchars($transaction['merchant_upi_id']); ?></div>
                        </div>
                    </div>

                    <form method="POST" class="verification-form">
                        <input type="hidden" name="transaction_id" value="<?php echo $transaction['id']; ?>">
                        
                        <div class="form-group">
                            <label>Verification Notes</label>
                            <textarea name="notes" placeholder="Add verification notes or reason for rejection..."></textarea>
                        </div>
                        
                        <button type="submit" name="verify_payment" value="verify" onclick="return confirm('Verify this payment?')" class="btn btn-verify">
                            <i class="fas fa-check"></i> Verify
                        </button>
                        
                        <button type="submit" name="verify_payment" value="reject" onclick="return confirm('Reject this payment?')" class="btn btn-reject">
                            <i class="fas fa-times"></i> Reject
                        </button>
                        
                        <input type="hidden" name="action" value="" id="action-<?php echo $transaction['id']; ?>">
                    </form>
                </div>
                
                <script>
                document.querySelectorAll('.btn-verify, .btn-reject').forEach(btn => {
                    btn.addEventListener('click', function(e) {
                        const form = this.closest('form');
                        const actionInput = form.querySelector('input[name="action"]');
                        actionInput.value = this.value;
                    });
                });
                </script>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Recent Transactions -->
        <div class="section">
            <h2><i class="fas fa-history"></i> Recent Transactions</h2>
            
            <?php if (empty($recent_transactions)): ?>
            <div class="empty-state">
                <i class="fas fa-file-invoice"></i>
                <h3>No transaction history</h3>
                <p>Verified transactions will appear here</p>
            </div>
            <?php else: ?>
                <?php foreach ($recent_transactions as $transaction): ?>
                <div class="transaction-card">
                    <div class="transaction-header">
                        <div>
                            <div class="transaction-id">TXN: <?php echo htmlspecialchars($transaction['transaction_id']); ?></div>
                            <small>Order #<?php echo $transaction['order_id']; ?> • <?php echo date('M j, Y g:i A', strtotime($transaction['verified_at'])); ?></small>
                        </div>
                        <div style="text-align: right;">
                            <div class="transaction-amount">₹<?php echo number_format($transaction['amount'], 2); ?></div>
                            <span class="status-badge status-<?php echo $transaction['status']; ?>">
                                <?php echo $transaction['status'] === 'verified' ? 'Verified' : 'Rejected'; ?>
                            </span>
                        </div>
                    </div>

                    <div class="transaction-details">
                        <div class="detail-item">
                            <div class="detail-label">Customer</div>
                            <div class="detail-value"><?php echo htmlspecialchars($transaction['customer_name']); ?></div>
                        </div>
                        <div class="detail-item">
                            <div class="detail-label">Verified By</div>
                            <div class="detail-value"><?php echo htmlspecialchars($transaction['verified_by_name'] ?? 'System'); ?></div>
                        </div>
                        <?php if ($transaction['notes']): ?>
                        <div class="detail-item">
                            <div class="detail-label">Notes</div>
                            <div class="detail-value"><?php echo htmlspecialchars($transaction['notes']); ?></div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>