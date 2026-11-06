<?php
session_start();
require_once 'config/database.php';
require_once 'includes/auth.php';

// Initialize Auth class
$auth = new Auth();

// Block customer login if maintenance mode is enabled (admins can bypass)
$auth->checkMaintenanceMode();

$error = '';
$success = '';

// Handle login
if (isset($_POST['login'])) {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    
    if (empty($username) || empty($password)) {
        $error = 'Please enter both username/email and password.';
    } else {
        // Authenticate customer user
        $user = $auth->authenticate($username, $password, 'customer');
        
        if ($user) {
            // Create customer session
            $auth->createSession($user);
            header('Location: customer-portal.php');
            exit();
        } else {
            $error = 'Invalid email/phone or password.';
        }
    }
}

// Handle registration
if (isset($_POST['register'])) {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $address = trim($_POST['address'] ?? '');
    $twelve_digit_password = $_POST['twelve_digit_password'] ?? '';
    
    // Enhanced validation
    if (empty($name) || empty($email) || empty($phone) || empty($password) || empty($confirm_password) || empty($address) || empty($twelve_digit_password)) {
        $error = 'Please fill in all required fields.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters long.';
    } elseif ($password !== $confirm_password) {
        $error = 'Passwords do not match.';
    } elseif (!preg_match('/^\d{12}$/', $twelve_digit_password)) {
        $error = 'Aadhar Number must be exactly 12 digits (numbers only).';
    } else {
        try {
            // Check if user already exists
            $check_sql = "SELECT id FROM users WHERE email = ? OR phone = ? 
                         UNION 
                         SELECT id FROM waiting_room WHERE email = ? OR phone = ?";
            $check_stmt = executeQuery($check_sql, [$email, $phone, $email, $phone]);
            
            if ($check_stmt->rowCount() > 0) {
                $error = 'User with this email or phone already exists or is pending approval.';
            } else {
                // Insert into waiting room for approval
                $sql = "INSERT INTO waiting_room (name, email, phone, password, address, status, created_at) VALUES (?, ?, ?, ?, ?, 'pending', NOW())";
                $result = executeQuery($sql, [$name, $email, $phone, password_hash($password, PASSWORD_DEFAULT), $address]);
                
                if ($result) {
                    $success = 'Registration submitted successfully! Your account is pending approval. You will be able to login once approved.';
                    // Clear form data on success
                    $_POST = [];
                } else {
                    $error = 'Registration failed. Please try again.';
                }
            }
        } catch (Exception $e) {
            error_log("Registration error: " . $e->getMessage());
            $error = "Registration error. Please try again later.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Customer Authentication - LIAA LPG Gas Booking</title>
  <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🔥</text></svg>">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
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
      --gradient-bg: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 50%, #ff9f66 100%);
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
      background: var(--gradient-bg);
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
      background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1000 1000"><defs><radialGradient id="grad1" cx="50%" cy="50%" r="50%"><stop offset="0%" style="stop-color:rgba(255,107,0,0.1);stop-opacity:1" /><stop offset="100%" style="stop-color:rgba(255,107,0,0);stop-opacity:0" /></radialGradient><pattern id="auth-pattern" x="0" y="0" width="60" height="60" patternUnits="userSpaceOnUse"><circle cx="30" cy="30" r="2" fill="rgba(255,255,255,0.1)"/><circle cx="10" cy="10" r="1" fill="rgba(255,255,255,0.05)"/><circle cx="50" cy="10" r="1" fill="rgba(255,255,255,0.05)"/></pattern></defs><rect width="1000" height="1000" fill="url(%23grad1)"/><rect width="1000" height="1000" fill="url(%23auth-pattern)"/></svg>');
      opacity: 0.6;
    }

    body::after {
      content: '';
      position: absolute;
      top: -50%;
      left: -50%;
      width: 200%;
      height: 200%;
      background: radial-gradient(circle, rgba(255,107,0,0.1) 0%, transparent 70%);
      animation: float 20s ease-in-out infinite;
      pointer-events: none;
    }

    @keyframes float {
      0%, 100% { transform: translate(0, 0) rotate(0deg); }
      33% { transform: translate(30px, -30px) rotate(120deg); }
      66% { transform: translate(-20px, 20px) rotate(240deg); }
    }

    .auth-container {
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

    .auth-container::before {
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

    .auth-header {
      background: var(--gradient);
      color: var(--white);
      padding: 3rem 2rem;
      border-radius: var(--border-radius-lg) var(--border-radius-lg) 0 0;
      text-align: center;
      position: relative;
      overflow: hidden;
    }

    .auth-header::before {
      content: '';
      position: absolute;
      top: 0;
      left: -100%;
      width: 100%;
      height: 100%;
      background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
      animation: shimmer 3s infinite;
    }

    .auth-header::after {
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

    .auth-header .logo {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      width: 80px;
      height: 80px;
      background: rgba(255, 255, 255, 0.2);
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

    .auth-header h1 {
      font-size: 2.2rem;
      font-weight: 700;
      margin-bottom: 0.5rem;
      position: relative;
      z-index: 2;
      text-shadow: 0 2px 4px rgba(0,0,0,0.1);
    }

    .auth-header p {
      opacity: 0.95;
      font-size: 1rem;
      position: relative;
      z-index: 2;
      font-weight: 400;
    }

    .tab-container {
      display: flex;
      background: rgba(248, 250, 252, 0.8);
      backdrop-filter: blur(10px);
      border-radius: 0;
      position: relative;
    }

    .tab-button {
      flex: 1;
      padding: 1.2rem 1rem;
      border: none;
      background: transparent;
      color: var(--gray-600);
      font-weight: 600;
      font-size: 0.95rem;
      cursor: pointer;
      transition: var(--transition);
      position: relative;
      z-index: 1;
    }

    .tab-button.active {
      color: var(--primary);
      background: var(--white);
      box-shadow: var(--shadow-lg);
    }

    .tab-button.active::after {
      content: '';
      position: absolute;
      bottom: 0;
      left: 50%;
      transform: translateX(-50%);
      width: 40px;
      height: 4px;
      background: var(--gradient);
      border-radius: 2px 2px 0 0;
    }

    .tab-button:hover:not(.active) {
      background: rgba(255, 255, 255, 0.5);
      color: var(--primary-dark);
    }

    .tab-content {
      padding: 2.5rem;
      display: none;
      background: var(--white);
      border-radius: 0 0 var(--border-radius-lg) var(--border-radius-lg);
    }

    .tab-content.active {
      display: block;
      animation: fadeInContent 0.5s ease-out;
    }

    @keyframes fadeInContent {
      from { opacity: 0; transform: translateY(20px); }
      to { opacity: 1; transform: translateY(0); }
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

    .form-group input:focus + .input-wrapper i,
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

    /* Enhanced responsive design */
    @media (max-width: 768px) {
      body {
        padding: 1rem 0.5rem;
      }
      
      .auth-container {
        margin: 0.5rem;
        max-width: 100%;
      }
      
      .auth-header {
        padding: 2rem 1.5rem;
      }
      
      .auth-header .logo {
        width: 60px;
        height: 60px;
        font-size: 2rem;
      }
      
      .auth-header h1 {
        font-size: 1.8rem;
      }
      
      .tab-content {
        padding: 2rem 1.5rem;
      }

      .tab-button {
        padding: 1rem 0.75rem;
        font-size: 0.9rem;
      }
    }

    @media (max-width: 480px) {
      .auth-header {
        padding: 1.5rem 1rem;
      }
      
      .auth-header h1 {
        font-size: 1.5rem;
      }
      
      .tab-content {
        padding: 1.5rem 1rem;
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
    .tab-button:focus,
    .form-group input:focus,
    .btn:focus,
    .back-link a:focus {
      outline: 2px solid var(--primary);
      outline-offset: 2px;
    }
  </style>
</head>
<body>
  <div class="auth-container">
    <div class="auth-header">
      <div class="logo">🔥</div>
      <h1>LIAA LPG</h1>
      <p>Secure Customer Portal Access</p>
    </div>

    <div class="tab-container">
      <button class="tab-button active" onclick="showTab('login')">Login</button>
      <button class="tab-button" onclick="showTab('register')">Register</button>
    </div>

    <!-- Login Tab -->
    <div id="login" class="tab-content active">
      <?php if ($error && isset($_POST['login'])): ?>
        <div class="message error">
          <i class="fas fa-exclamation-circle"></i>
          <?php echo htmlspecialchars($error); ?>
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
          <i class="fas fa-sign-in-alt"></i> Sign In Securely
        </button>
      </form>
    </div>

    <!-- Register Tab -->
    <div id="register" class="tab-content">
      <?php if ($error && isset($_POST['register'])): ?>
        <div class="message error">
          <i class="fas fa-exclamation-circle"></i>
          <?php echo htmlspecialchars($error); ?>
        </div>
      <?php endif; ?>

      <?php if ($success): ?>
        <div class="message success">
          <i class="fas fa-check-circle"></i>
          <?php echo htmlspecialchars($success); ?>
        </div>
      <?php endif; ?>

      <form method="post">
        <div class="form-group">
          <label for="name">
            <i class="fas fa-user"></i> Full Name
          </label>
          <div class="input-wrapper">
            <input 
              type="text" 
              id="name" 
              name="name" 
              placeholder="Enter your full name"
              required
              value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>"
            >
            <i class="fas fa-user"></i>
          </div>
        </div>
        
        <div class="form-group">
          <label for="email">
            <i class="fas fa-envelope"></i> Email Address
          </label>
          <div class="input-wrapper">
            <input 
              type="email" 
              id="email" 
              name="email" 
              placeholder="Enter your email address"
              required
              value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>"
            >
            <i class="fas fa-envelope"></i>
          </div>
        </div>
        
        <div class="form-group">
          <label for="phone">
            <i class="fas fa-phone"></i> Phone Number
          </label>
          <div class="input-wrapper">
            <input 
              type="tel" 
              id="phone" 
              name="phone" 
              placeholder="Enter your phone number"
              required
              value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>"
            >
            <i class="fas fa-phone"></i>
          </div>
        </div>
        
        <div class="form-group">
          <label for="address">
            <i class="fas fa-map-marker-alt"></i> Delivery Address
          </label>
          <div class="input-wrapper">
            <input 
              type="text" 
              id="address" 
              name="address" 
              placeholder="Enter your complete delivery address"
              required
              value="<?php echo htmlspecialchars($_POST['address'] ?? ''); ?>"
            >
            <i class="fas fa-map-marker-alt"></i>
          </div>
        </div>
        
        <div class="form-group">
          <label for="twelve-digit-password">
            <i class="fas fa-shield-alt"></i> Aadhar Number
          </label>
          <div class="input-wrapper">
            <input 
              type="text" 
              id="twelve-digit-password" 
              name="twelve_digit_password" 
              placeholder="Enter exactly 12 digits"
              required
              maxlength="12"
              pattern="[0-9]{12}"
              title="Must be exactly 12 digits"
              value="<?php echo htmlspecialchars($_POST['twelve_digit_password'] ?? ''); ?>"
            >
            <i class="fas fa-shield-alt"></i>
          </div>
        </div>
        
        <div class="form-group">
          <label for="reg-password">
            <i class="fas fa-lock"></i> Create Password
          </label>
          <div class="input-wrapper">
            <input 
              type="password" 
              id="reg-password" 
              name="password" 
              placeholder="Create a secure password"
              required
            >
            <i class="fas fa-key"></i>
          </div>
        </div>
        
        <div class="form-group">
          <label for="confirm_password">
            <i class="fas fa-lock"></i> Confirm Password
          </label>
          <div class="input-wrapper">
            <input 
              type="password" 
              id="confirm_password" 
              name="confirm_password" 
              placeholder="Confirm your password"
              required
            >
            <i class="fas fa-check"></i>
          </div>
        </div>
        
        <button type="submit" name="register" class="btn btn-primary">
          <i class="fas fa-user-plus"></i> Create Account
        </button>
      </form>
    </div>

    <div class="back-link">
      <a href="Home.html"><i class="fas fa-arrow-left"></i> Back to Home</a>
    </div>
  </div>

  <script>
    // Enhanced tab switching with animations
    function showTab(tabName) {
      // Hide all tab contents with fade out
      const tabContents = document.querySelectorAll('.tab-content');
      tabContents.forEach(content => {
        content.style.opacity = '0';
        setTimeout(() => {
          content.classList.remove('active');
        }, 150);
      });
      
      // Remove active class from all tab buttons
      const tabButtons = document.querySelectorAll('.tab-button');
      tabButtons.forEach(button => {
        button.classList.remove('active');
      });
      
      // Show selected tab content with fade in
      setTimeout(() => {
        const targetTab = document.getElementById(tabName);
        targetTab.classList.add('active');
        targetTab.style.opacity = '1';
      }, 150);
      
      // Add active class to clicked button
      event.target.classList.add('active');
    }

    // Enhanced form validation
    document.addEventListener('DOMContentLoaded', function() {
      // Add loading state to form submissions
      const forms = document.querySelectorAll('form');
      forms.forEach(form => {
        form.addEventListener('submit', function(e) {
          const submitBtn = form.querySelector('.btn');
          submitBtn.classList.add('loading');
          submitBtn.style.pointerEvents = 'none';
        });
      });

      // Real-time password confirmation validation
      const confirmPassword = document.getElementById('confirm_password');
      const password = document.getElementById('reg-password');
      
      if (confirmPassword && password) {
        confirmPassword.addEventListener('input', function() {
          if (this.value && this.value !== password.value) {
            this.style.borderColor = 'var(--danger)';
          } else {
            this.style.borderColor = 'var(--gray-200)';
          }
        });
      }

      // Real-time email validation
      const emailInput = document.querySelector('#register input[name="email"]');
      if (emailInput) {
        emailInput.addEventListener('blur', function() {
          const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
          if (this.value && !emailPattern.test(this.value)) {
            this.style.borderColor = 'var(--danger)';
          } else {
            this.style.borderColor = 'var(--gray-200)';
          }
        });
      }

      // Password strength indicator
      if (password) {
        password.addEventListener('input', function() {
          const value = this.value;
          if (value.length < 6) {
            this.style.borderColor = 'var(--danger)';
          } else if (value.length < 8) {
            this.style.borderColor = 'var(--warning)';
          } else {
            this.style.borderColor = 'var(--success)';
          }
        });
      }

      // 12-digit password validation
      const twelveDigitPassword = document.getElementById('twelve-digit-password');
      if (twelveDigitPassword) {
        twelveDigitPassword.addEventListener('input', function() {
          // Remove any non-digit characters
          this.value = this.value.replace(/\D/g, '');
          
          const value = this.value;
          if (value.length === 0) {
            this.style.borderColor = 'var(--gray-200)';
          } else if (value.length < 12) {
            this.style.borderColor = 'var(--warning)';
          } else if (value.length === 12) {
            this.style.borderColor = 'var(--success)';
          } else {
            // Trim to 12 digits if more than 12
            this.value = value.substring(0, 12);
            this.style.borderColor = 'var(--success)';
          }
        });

        // Prevent non-numeric input
        twelveDigitPassword.addEventListener('keypress', function(e) {
          const char = String.fromCharCode(e.which);
          if (!char.match(/[0-9]/)) {
            e.preventDefault();
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
    });

    // Add keyboard navigation for tabs
    document.addEventListener('keydown', function(e) {
      if (e.key === 'Tab' && e.altKey) {
        e.preventDefault();
        const activeTab = document.querySelector('.tab-button.active');
        const allTabs = document.querySelectorAll('.tab-button');
        const currentIndex = Array.from(allTabs).indexOf(activeTab);
        const nextIndex = (currentIndex + 1) % allTabs.length;
        
        allTabs[nextIndex].click();
      }
    });
  </script>
</body>
</html> 