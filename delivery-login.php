<?php
session_start();
require_once 'config/database.php';
require_once 'includes/auth.php';

// Initialize Auth class
$auth = new Auth();

// Block delivery login if maintenance mode is enabled (admins can bypass)
$auth->checkMaintenanceMode();

$error = '';
$message = '';

// Check if already logged in
if ($auth->isLoggedIn() && $auth->getCurrentUser()['role'] === 'delivery') {
    header('Location: delivery-portal.php');
    exit();
}

// Handle login
if (isset($_POST['login'])) {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    
    if (empty($username) || empty($password)) {
        $error = 'Please enter both username and password.';
    } else {
        // Authenticate delivery user
        $user = $auth->authenticate($username, $password, 'delivery');
        
        if ($user) {
            // Create delivery session
            $auth->createSession($user);
            header('Location: delivery-portal.php');
            exit();
        } else {
            $error = 'Invalid email/phone or password.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Delivery Login - LIAA LPG Gas Booking</title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🔥</text></svg>">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #ff6b00;
            --primary-dark: #e05d00;
            --primary-light: #ff8533;
            --secondary: #2c3e50;
            --accent: #3498db;
            --success: #27ae60;
            --danger: #e74c3c;
            --warning: #f39c12;
            --light: #ecf0f1;
            --dark: #2c3e50;
            --white: #ffffff;
            --gray-50: #f8fafc;
            --gray-100: #f1f5f9;
            --gray-200: #e2e8f0;
            --gray-300: #cbd5e1;
            --gray-400: #94a3b8;
            --gray-500: #64748b;
            --gray-600: #475569;
            --gray-700: #334155;
            --gray-800: #1e293b;
            --gray-900: #0f172a;
            --shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
            --shadow-hover: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            --gradient: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
            --gradient-bg: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --border-radius: 20px;
            --border-radius-lg: 24px;
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            --glass-bg: rgba(255, 255, 255, 0.25);
            --glass-border: rgba(255, 255, 255, 0.18);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--gradient);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem 1rem;
            position: relative;
            overflow-x: hidden;
        }

        body::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1000 1000"><defs><radialGradient id="grad1" cx="50%" cy="50%" r="50%"><stop offset="0%" style="stop-color:rgba(255,107,0,0.1);stop-opacity:1" /><stop offset="100%" style="stop-color:rgba(255,107,0,0);stop-opacity:0" /></radialGradient><pattern id="delivery-pattern" x="0" y="0" width="80" height="80" patternUnits="userSpaceOnUse"><circle cx="40" cy="40" r="2" fill="rgba(255,255,255,0.1)"/><circle cx="20" cy="20" r="1" fill="rgba(255,255,255,0.05)"/><circle cx="60" cy="20" r="1" fill="rgba(255,255,255,0.05)"/><rect x="35" y="35" width="10" height="10" fill="rgba(255,107,0,0.05)" rx="2"/></pattern></defs><rect width="1000" height="1000" fill="url(%23grad1)"/><rect width="1000" height="1000" fill="url(%23delivery-pattern)"/></svg>');
            opacity: 0.6;
        }

        body::after {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,107,0,0.3) 0%, rgba(255,133,51,0.1) 50%, transparent 70%);
            animation: float 25s ease-in-out infinite;
            pointer-events: none;
        }

        @keyframes float {
            0%, 100% { transform: translate(0, 0) rotate(0deg); }
            33% { transform: translate(40px, -40px) rotate(120deg); }
            66% { transform: translate(-30px, 30px) rotate(240deg); }
        }

        .login-container {
            background: var(--glass-bg);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid var(--glass-border);
            border-radius: var(--border-radius-lg);
            box-shadow: var(--shadow);
            width: 100%;
            max-width: 480px;
            position: relative;
            z-index: 2;
            animation: slideInUp 0.8s ease-out;
            overflow: hidden;
        }

        .login-container::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(135deg, rgba(255,255,255,0.1) 0%, rgba(255,255,255,0.05) 100%);
            pointer-events: none;
        }

        @keyframes slideInUp {
            from {
                opacity: 0;
                transform: translateY(60px) scale(0.95);
            }
            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        .login-header {
            background: var(--gradient);
            color: var(--white);
            padding: 3rem 2rem;
            border-radius: var(--border-radius-lg) var(--border-radius-lg) 0 0;
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .login-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            animation: shimmer 3s infinite;
        }

        .login-header::after {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 200px;
            height: 200px;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
            transform: translate(-50%, -50%);
            animation: pulse 4s ease-in-out infinite;
        }

        @keyframes shimmer {
            0% { left: -100%; }
            100% { left: 100%; }
        }

        @keyframes pulse {
            0%, 100% { transform: translate(-50%, -50%) scale(1); opacity: 0.5; }
            50% { transform: translate(-50%, -50%) scale(1.1); opacity: 0.8; }
        }

        .login-header .logo {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            border-radius: 50%;
            margin-bottom: 1rem;
            font-size: 2.5rem;
            position: relative;
            z-index: 2;
            animation: logoFloat 3s ease-in-out infinite;
        }

        @keyframes logoFloat {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-10px); }
        }

        .login-header h1 {
            font-size: 2.2rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            position: relative;
            z-index: 2;
            text-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .login-header p {
            opacity: 0.95;
            font-size: 1rem;
            position: relative;
            z-index: 2;
            font-weight: 400;
        }

        .login-content {
            padding: 2.5rem;
            background: var(--white);
            border-radius: 0 0 var(--border-radius-lg) var(--border-radius-lg);
        }

        .form-group {
            margin-bottom: 1.8rem;
            position: relative;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.8rem;
            font-weight: 600;
            color: var(--gray-700);
            font-size: 0.9rem;
            letter-spacing: 0.025em;
        }

        .input-wrapper {
            position: relative;
            display: flex;
            align-items: center;
        }

        .input-wrapper i {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--gray-400);
            font-size: 1.1rem;
            z-index: 2;
            transition: var(--transition);
        }

        .form-group input {
            width: 100%;
            padding: 1rem 1rem 1rem 3rem;
            border: 2px solid var(--gray-200);
            border-radius: 12px;
            font-size: 1rem;
            transition: var(--transition);
            background: var(--white);
            color: var(--gray-800);
            font-weight: 500;
        }

        .form-group input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(255, 107, 0, 0.1);
            transform: translateY(-1px);
        }

        .form-group input:focus + i,
        .form-group input:focus ~ i {
            color: var(--primary);
            transform: translateY(-50%) scale(1.1);
        }

        .form-group input::placeholder {
            color: var(--gray-400);
            font-weight: 400;
        }

        .btn {
            width: 100%;
            padding: 1rem 1.5rem;
            border: none;
            border-radius: 12px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            position: relative;
            overflow: hidden;
            letter-spacing: 0.025em;
        }

        .btn-primary {
            background: var(--gradient);
            color: var(--white);
            box-shadow: 0 4px 15px 0 rgba(255, 107, 0, 0.3);
        }

        .btn-primary::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            transition: var(--transition);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px 0 rgba(255, 107, 0, 0.4);
        }

        .btn-primary:hover::before {
            left: 100%;
        }

        .btn-primary:active {
            transform: translateY(0);
        }

        .btn-primary i {
            margin-right: 0.5rem;
        }

        .message {
            padding: 1.2rem;
            border-radius: 12px;
            margin-bottom: 1.5rem;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            animation: slideInMessage 0.5s ease-out;
        }

        @keyframes slideInMessage {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .message i {
            font-size: 1.2rem;
            flex-shrink: 0;
        }

        .message.error {
            background: linear-gradient(135deg, rgba(231, 76, 60, 0.1) 0%, rgba(231, 76, 60, 0.05) 100%);
            color: var(--danger);
            border: 1px solid rgba(231, 76, 60, 0.2);
            border-left: 4px solid var(--danger);
        }

        .message.success {
            background: linear-gradient(135deg, rgba(39, 174, 96, 0.1) 0%, rgba(39, 174, 96, 0.05) 100%);
            color: var(--success);
            border: 1px solid rgba(39, 174, 96, 0.2);
            border-left: 4px solid var(--success);
        }

        .back-link {
            text-align: center;
            margin-top: 1.5rem;
            padding-top: 1.5rem;
            border-top: 1px solid var(--gray-200);
        }

        .back-link a {
            color: var(--primary);
            text-decoration: none;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            border-radius: 8px;
            transition: var(--transition);
        }

        .back-link a:hover {
            background: rgba(255, 107, 0, 0.1);
            transform: translateX(-4px);
        }

        .delivery-features {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
            margin-top: 1.5rem;
            padding: 1.5rem;
            background: rgba(255, 107, 0, 0.05);
            border-radius: 12px;
            border: 1px solid rgba(255, 107, 0, 0.1);
        }

        .feature-item {
            text-align: center;
            padding: 1rem;
            border-radius: 8px;
            background: rgba(255, 255, 255, 0.5);
            transition: var(--transition);
        }

        .feature-item:hover {
            transform: translateY(-2px);
            background: rgba(255, 255, 255, 0.8);
        }

        .feature-item i {
            font-size: 1.5rem;
            color: var(--primary);
            margin-bottom: 0.5rem;
            display: block;
        }

        .feature-item span {
            font-size: 0.8rem;
            color: var(--gray-600);
            font-weight: 500;
        }

        /* Enhanced responsive design */
        @media (max-width: 768px) {
            body {
                padding: 1rem 0.5rem;
            }
            
            .login-container {
                margin: 0.5rem;
                max-width: 100%;
            }
            
            .login-header {
                padding: 2rem 1.5rem;
            }
            
            .login-header .logo {
                width: 60px;
                height: 60px;
                font-size: 2rem;
            }
            
            .login-header h1 {
                font-size: 1.8rem;
            }
            
            .login-content {
                padding: 2rem 1.5rem;
            }

            .delivery-features {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 480px) {
            .login-header {
                padding: 1.5rem 1rem;
            }
            
            .login-header h1 {
                font-size: 1.5rem;
            }
            
            .login-content {
                padding: 1.5rem 1rem;
            }

            .delivery-features {
                grid-template-columns: 1fr;
            }
        }

        /* Loading state */
        .btn.loading {
            pointer-events: none;
            opacity: 0.8;
        }

        .btn.loading::after {
            content: '';
            position: absolute;
            width: 20px;
            height: 20px;
            top: 50%;
            left: 50%;
            margin-left: -10px;
            margin-top: -10px;
            border: 2px solid transparent;
            border-top-color: currentColor;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* Focus states for accessibility */
        .form-group input:focus,
        .btn:focus,
        .back-link a:focus {
            outline: 2px solid var(--primary);
            outline-offset: 2px;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-header">
            <div class="logo">🚚</div>
            <h1>Delivery Portal</h1>
            <p>LIAA LPG Delivery Staff Access</p>
        </div>

        <div class="login-content">
            <?php if ($error): ?>
                <div class="message error">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <?php if ($message): ?>
                <div class="message success">
                    <i class="fas fa-check-circle"></i>
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>

            <form method="post">
                <div class="form-group">
                    <label for="username">
                        <i class="fas fa-user"></i> Email or Phone Number
                    </label>
                    <div class="input-wrapper">
                        <input 
                            type="text" 
                            id="username" 
                            name="username" 
                            placeholder="Enter your email or phone number"
                            required
                            value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>"
                        >
                        <i class="fas fa-envelope"></i>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="password">
                        <i class="fas fa-lock"></i> Password
                    </label>
                    <div class="input-wrapper">
                        <input 
                            type="password" 
                            id="password" 
                            name="password" 
                            placeholder="Enter your password"
                            required
                        >
                        <i class="fas fa-key"></i>
                    </div>
                </div>
                
                <button type="submit" name="login" class="btn btn-primary">
                    <i class="fas fa-truck"></i> Access Delivery Dashboard
                </button>
            </form>

            <div class="delivery-features">
                <div class="feature-item">
                    <i class="fas fa-route"></i>
                    <span>Route Management</span>
                </div>
                <div class="feature-item">
                    <i class="fas fa-clipboard-list"></i>
                    <span>Delivery Tracking</span>
                </div>
                <div class="feature-item">
                    <i class="fas fa-mobile-alt"></i>
                    <span>Mobile Friendly</span>
                </div>
                <div class="feature-item">
                    <i class="fas fa-clock"></i>
                    <span>Real-time Updates</span>
                </div>
            </div>

            <div class="back-link">
                <a href="Home.html"><i class="fas fa-arrow-left"></i> Back to Home</a>
            </div>
        </div>
    </div>

    <script>
        // Enhanced form validation and interactions
        document.addEventListener('DOMContentLoaded', function() {
            // Add loading state to form submission
            const form = document.querySelector('form');
            if (form) {
                form.addEventListener('submit', function(e) {
                    const submitBtn = form.querySelector('.btn');
                    submitBtn.classList.add('loading');
                    submitBtn.style.pointerEvents = 'none';
                });
            }

            // Real-time email/phone validation
            const usernameInput = document.getElementById('username');
            if (usernameInput) {
                usernameInput.addEventListener('blur', function() {
                    const value = this.value.trim();
                    const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                    const phonePattern = /^[\+]?[1-9][\d]{0,15}$/;
                    
                    if (value && !emailPattern.test(value) && !phonePattern.test(value)) {
                        this.style.borderColor = 'var(--danger)';
                    } else {
                        this.style.borderColor = 'var(--gray-200)';
                    }
                });
            }

            // Add input focus animations
            const inputs = document.querySelectorAll('input');
            inputs.forEach(input => {
                input.addEventListener('focus', function() {
                    this.parentElement.style.transform = 'translateY(-2px)';
                });
                
                input.addEventListener('blur', function() {
                    this.parentElement.style.transform = 'translateY(0)';
                });
            });

            // Animate feature items
            const featureItems = document.querySelectorAll('.feature-item');
            featureItems.forEach((item, index) => {
                item.style.opacity = '0';
                item.style.transform = 'translateY(20px)';
                
                setTimeout(() => {
                    item.style.transition = 'all 0.5s ease';
                    item.style.opacity = '1';
                    item.style.transform = 'translateY(0)';
                }, 200 + (index * 100));
            });

            // Add keyboard navigation
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Enter' && e.ctrlKey) {
                    const submitBtn = document.querySelector('.btn-primary');
                    if (submitBtn) {
                        submitBtn.click();
                    }
                }
            });
        });
    </script>
</body>
</html>
