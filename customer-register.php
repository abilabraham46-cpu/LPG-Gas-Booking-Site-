<?php
// Customer Registration
session_start();
require_once 'config/database.php';
require_once 'includes/auth.php';
require_once 'includes/utils.php';

// Initialize Auth and check maintenance mode
$auth = new Auth();
$auth->checkMaintenanceMode();

$success = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // Get and sanitize input
  $firstName = trim($_POST['firstName'] ?? '');
  $lastName = trim($_POST['lastName'] ?? '');
  $email = trim($_POST['email'] ?? '');
  $phone = trim($_POST['phone'] ?? '');
  $aadhaarNumber = trim($_POST['aadhaarNumber'] ?? '');
  $address = trim($_POST['address'] ?? '');
  $password = $_POST['password'] ?? '';
  $confirmPassword = $_POST['confirmPassword'] ?? '';
  $terms = isset($_POST['terms']);

  // Validation
  if (empty($firstName) || empty($lastName) || empty($email) || empty($phone) || empty($aadhaarNumber) || empty($address)) {
    $error = "Please fill all required fields.";
  } elseif (!isValidEmail($email)) {
    $error = "Please enter a valid email address.";
  } elseif (!isValidPhone($phone)) {
    $error = "Please enter a valid phone number.";
  } elseif (!preg_match('/^[0-9]{12}$/', $aadhaarNumber)) {
    $error = "Please enter a valid 12-digit Aadhaar number.";
  } elseif (!$password || !$confirmPassword || !$terms) {
    $error = "Please fill all required fields and accept the terms.";
  } elseif ($password !== $confirmPassword) {
    $error = "Passwords do not match.";
  } elseif (strlen($password) < 8) {
    $error = "Password must be at least 8 characters.";
  } else {
    try {
      // Check if email, phone, or aadhaar already exists
      $checkSql = "SELECT id FROM users WHERE email = ? OR phone = ? OR aadhaar_number = ?
                   UNION 
                   SELECT id FROM waiting_room WHERE email = ? OR phone = ? OR aadhaar_number = ?";
      $stmt = executeQuery($checkSql, [$email, $phone, $aadhaarNumber, $email, $phone, $aadhaarNumber]);
      
      if ($stmt->fetch()) {
        $error = "Email, phone number, or Aadhaar number already registered.";
      } else {
        // Hash password
        $hashedPassword = hashPassword($password);
        $fullName = trim($firstName . ' ' . $lastName);
        
        // Insert into waiting room for approval
        $insertSql = "INSERT INTO waiting_room (name, email, phone, aadhaar_number, address, password) 
                      VALUES (?, ?, ?, ?, ?, ?)";
        $result = executeQuery($insertSql, [$fullName, $email, $phone, $aadhaarNumber, $address, $hashedPassword]);
        
        if ($result) {
          $success = true;
          // Redirect after 3 seconds
          header("refresh:3;url=customer-auth.php");
        } else {
          $error = "Registration failed. Please try again.";
        }
      }
    } catch (Exception $e) {
      error_log("Registration error: " . $e->getMessage());
      $error = "Registration failed. Please try again later.";
    }
  }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Customer Registration - LIAA LPG Gas Booking</title>
  <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🔥</text></svg>">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <style>
    :root {
      --primary: #ff6b00;
      --primary-dark: #e05d00;
      --primary-light: #ff8533;
      --secondary: #2c3e50;
      --success: #27ae60;
      --danger: #e74c3c;
      --warning: #f39c12;
      --light: #ecf0f1;
      --dark: #2c3e50;
      --white: #ffffff;
      --shadow: 0 15px 35px rgba(0, 0, 0, 0.1);
      --shadow-hover: 0 25px 50px rgba(0, 0, 0, 0.15);
      --gradient: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 100%);
      --gradient-bg: linear-gradient(135deg, var(--primary) 0%, var(--primary-light) 50%, #ff9933 100%);
      --border-radius: 16px;
      --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
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
      background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1000 1000"><defs><pattern id="register-pattern" x="0" y="0" width="100" height="100" patternUnits="userSpaceOnUse"><circle cx="50" cy="50" r="2" fill="rgba(255,255,255,0.1)"/></pattern></defs><rect width="1000" height="1000" fill="url(%23register-pattern)"/></svg>');
      opacity: 0.3;
      z-index: 0;
    }

    .register-container {
      background: rgba(255, 255, 255, 0.97);
      backdrop-filter: blur(20px);
      border: 1px solid rgba(255, 255, 255, 0.2);
      border-radius: var(--border-radius);
      box-shadow: var(--shadow);
      width: 100%;
      max-width: 500px;
      position: relative;
      z-index: 2;
      animation: slideInUp 0.8s ease-out;
    }

    @keyframes slideInUp {
      from {
        opacity: 0;
        transform: translateY(50px);
      }

      to {
        opacity: 1;
        transform: translateY(0);
      }
    }

    .register-header {
      background: var(--gradient);
      color: var(--white);
      padding: 2rem;
      border-radius: var(--border-radius) var(--border-radius) 0 0;
      text-align: center;
      position: relative;
      overflow: hidden;
    }

    .register-header::before {
      content: '';
      position: absolute;
      top: 0;
      left: -100%;
      width: 100%;
      height: 100%;
      background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
      animation: shine 3s infinite;
    }

    @keyframes shine {
      0% {
        left: -100%;
      }

      100% {
        left: 100%;
      }
    }

    .register-header h1 {
      font-size: 1.8rem;
      font-weight: 700;
      margin-bottom: 0.5rem;
      position: relative;
      z-index: 2;
    }

    .register-header p {
      opacity: 0.9;
      font-size: 0.95rem;
      position: relative;
      z-index: 2;
    }

    .register-form {
      padding: 2.5rem;
    }

    .form-row {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 1rem;
      margin-bottom: 1.5rem;
    }

    .form-group {
      position: relative;
      margin-bottom: 1.5rem;
      animation: fadeInUp 0.6s ease-out forwards;
      opacity: 0;
    }

    .form-group:nth-child(1) {
      animation-delay: 0.1s;
    }

    .form-group:nth-child(2) {
      animation-delay: 0.2s;
    }

    .form-group:nth-child(3) {
      animation-delay: 0.3s;
    }

    .form-group:nth-child(4) {
      animation-delay: 0.4s;
    }

    .form-group:nth-child(5) {
      animation-delay: 0.5s;
    }

    @keyframes fadeInUp {
      to {
        opacity: 1;
        transform: translateY(0);
      }
    }

    .form-group.full-width {
      grid-column: 1 / -1;
    }

    .form-group label {
      display: block;
      font-weight: 500;
      color: var(--dark);
      margin-bottom: 0.5rem;
      font-size: 0.9rem;
    }

    .input-wrapper {
      position: relative;
    }

    .input-wrapper i {
      position: absolute;
      left: 1rem;
      top: 50%;
      transform: translateY(-50%);
      color: #999;
      font-size: 1rem;
      z-index: 2;
    }

    .form-group input,
    .form-group select,
    .form-group textarea {
      width: 100%;
      padding: 1rem 1rem 1rem 3rem;
      border: 2px solid #e1e8ed;
      border-radius: 12px;
      font-size: 1rem;
      transition: var(--transition);
      background: var(--white);
      color: var(--dark);
    }

    .form-group input:focus,
    .form-group select:focus,
    .form-group textarea:focus {
      outline: none;
      border-color: var(--primary);
      box-shadow: 0 0 0 3px rgba(255, 107, 0, 0.1);
    }

    .form-group input:focus+i,
    .form-group select:focus+i {
      color: var(--primary);
    }

    .form-group textarea {
      resize: vertical;
      min-height: 100px;
      padding-top: 1rem;
    }

    .password-wrapper {
      position: relative;
    }

    .password-toggle {
      position: absolute;
      right: 1rem;
      top: 50%;
      transform: translateY(-50%);
      background: none;
      border: none;
      color: #999;
      cursor: pointer;
      font-size: 1rem;
      z-index: 3;
    }

    .password-toggle:hover {
      color: var(--primary);
    }

    .password-strength {
      margin-top: 0.5rem;
      font-size: 0.8rem;
    }

    .strength-bar {
      height: 4px;
      background: #e1e8ed;
      border-radius: 2px;
      margin-top: 0.25rem;
      overflow: hidden;
    }

    .strength-fill {
      height: 100%;
      width: 0%;
      transition: var(--transition);
      border-radius: 2px;
    }

    .strength-weak .strength-fill {
      width: 33%;
      background: var(--danger);
    }

    .strength-medium .strength-fill {
      width: 66%;
      background: var(--warning);
    }

    .strength-strong .strength-fill {
      width: 100%;
      background: var(--success);
    }

    .checkbox-group {
      display: flex;
      align-items: flex-start;
      gap: 0.75rem;
      margin: 1.5rem 0;
    }

    .checkbox-group input[type="checkbox"] {
      width: auto;
      margin: 0;
      padding: 0;
    }

    .checkbox-group label {
      font-size: 0.9rem;
      line-height: 1.4;
      margin: 0;
      cursor: pointer;
    }

    .checkbox-group a {
      color: var(--primary);
      text-decoration: none;
    }

    .checkbox-group a:hover {
      text-decoration: underline;
    }

    .btn {
      width: 100%;
      padding: 1rem;
      background: var(--gradient);
      color: var(--white);
      border: none;
      border-radius: 12px;
      font-size: 1rem;
      font-weight: 600;
      cursor: pointer;
      transition: var(--transition);
      position: relative;
      overflow: hidden;
    }

    .btn:hover {
      transform: translateY(-2px);
      box-shadow: var(--shadow-hover);
    }

    .btn:active {
      transform: translateY(0);
    }

    .btn:disabled {
      opacity: 0.6;
      cursor: not-allowed;
      transform: none;
    }

    .btn .loading-spinner {
      display: none;
      width: 20px;
      height: 20px;
      border: 2px solid transparent;
      border-top: 2px solid var(--white);
      border-radius: 50%;
      animation: spin 1s linear infinite;
      margin-right: 0.5rem;
    }

    @keyframes spin {
      0% {
        transform: rotate(0deg);
      }

      100% {
        transform: rotate(360deg);
      }
    }

    .btn.loading .loading-spinner {
      display: inline-block;
    }

    .login-link {
      text-align: center;
      margin-top: 1.5rem;
      padding-top: 1.5rem;
      border-top: 1px solid #e1e8ed;
    }

    .login-link p {
      color: #666;
      font-size: 0.9rem;
    }

    .login-link a {
      color: var(--primary);
      text-decoration: none;
      font-weight: 500;
    }

    .login-link a:hover {
      text-decoration: underline;
    }

    .error-message {
      color: var(--danger);
      font-size: 0.8rem;
      margin-top: 0.25rem;
      display: none;
    }

    .form-group input.error,
    .form-group select.error {
      border-color: var(--danger);
    }

    .success-message {
      background: rgba(39, 174, 96, 0.1);
      color: var(--success);
      padding: 1rem;
      border-radius: 8px;
      margin-bottom: 1rem;
      display: none;
      text-align: center;
    }

    /* Responsive Design */
    @media (max-width: 768px) {
      body {
        padding: 1rem 0.5rem;
      }

      .register-container {
        max-width: 100%;
      }

      .register-header {
        padding: 1.5rem;
      }

      .register-header h1 {
        font-size: 1.5rem;
      }

      .register-form {
        padding: 1.5rem;
      }

      .form-row {
        grid-template-columns: 1fr;
        gap: 0;
      }
    }
  </style>
  <script src="https://kit.fontawesome.com/a076d05399.js" crossorigin="anonymous"></script>
</head>

<body>
  <div class="register-container">
    <div class="register-header">
      <h1><i class="fas fa-user-plus"></i> Create Account</h1>
      <p>Join LIAA LPG for safe and reliable gas delivery</p>
    </div>
    <div class="register-form">
      <?php if ($success): ?>
        <div class="success-message" style="display:block;">
          <i class="fas fa-check-circle"></i>
          Registration successful! Redirecting to login...
        </div>
      <?php endif; ?>
      <?php if ($error): ?>
        <div class="error-message" style="display:block;color:#e74c3c;margin-bottom:1rem;">
          <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
        </div>
      <?php endif; ?>
      <form id="registerForm" action="" method="POST" autocomplete="off">
        <div class="form-row">
          <div class="form-group">
            <label for="firstName">First Name *</label>
            <div class="input-wrapper">
              <input type="text" id="firstName" name="firstName" required value="<?php echo htmlspecialchars($_POST['firstName'] ?? ''); ?>">
              <i class="fas fa-user"></i>
            </div>
            <div class="error-message">Please enter your first name</div>
          </div>
          <div class="form-group">
            <label for="lastName">Last Name *</label>
            <div class="input-wrapper">
              <input type="text" id="lastName" name="lastName" required value="<?php echo htmlspecialchars($_POST['lastName'] ?? ''); ?>">
              <i class="fas fa-user"></i>
            </div>
            <div class="error-message">Please enter your last name</div>
          </div>
        </div>
        <div class="form-group">
          <label for="email">Email Address *</label>
          <div class="input-wrapper">
            <input type="email" id="email" name="email" required value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
            <i class="fas fa-envelope"></i>
          </div>
          <div class="error-message">Please enter a valid email address</div>
        </div>
        <div class="form-group">
          <label for="phone">Phone Number *</label>
          <div class="input-wrapper">
            <input type="tel" id="phone" name="phone" required value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>">
            <i class="fas fa-phone"></i>
          </div>
          <div class="error-message">Please enter a valid phone number</div>
        </div>
        <div class="form-group">
          <label for="aadhaarNumber">Aadhaar Number *</label>
          <div class="input-wrapper">
            <input type="num" id="aadhaarNumber" name="aadhaarNumber" maxlength="12" pattern="[0-9]{12}" required value="<?php echo htmlspecialchars($_POST['aadhaarNumber'] ?? ''); ?>" placeholder="Enter 12-digit Aadhaar number">
            <i class="fas fa-id-card"></i>
          </div>
          <div class="error-message">Please enter a valid 12-digit Aadhaar number</div>
        </div>
        <div class="form-group">
          <label for="address">Complete Address *</label>
          <div class="input-wrapper">
            <textarea id="address" name="address" placeholder="Enter your complete delivery address..." required><?php echo htmlspecialchars($_POST['address'] ?? ''); ?></textarea>
            <i class="fas fa-map-marker-alt"></i>
          </div>
          <div class="error-message">Please enter your complete address</div>
        </div>
        <div class="form-group">
          <label for="password">Password *</label>
          <div class="input-wrapper password-wrapper">
            <input type="password" id="password" name="password" required>
            <i class="fas fa-lock"></i>
            <button type="button" class="password-toggle" onclick="togglePassword()">
              <i class="fas fa-eye" id="passwordToggleIcon"></i>
            </button>
          </div>
          <div class="password-strength">
            <div class="strength-text">Password strength: <span id="strengthText">Enter password</span></div>
            <div class="strength-bar">
              <div class="strength-fill" id="strengthFill"></div>
            </div>
          </div>
          <div class="error-message">Password must be at least 8 characters long</div>
        </div>
        <div class="form-group">
          <label for="confirmPassword">Confirm Password *</label>
          <div class="input-wrapper">
            <input type="password" id="confirmPassword" name="confirmPassword" required>
            <i class="fas fa-lock"></i>
          </div>
          <div class="error-message">Passwords do not match</div>
        </div>
        <div class="checkbox-group">
          <input type="checkbox" id="terms" name="terms" required <?php if (isset($_POST['terms'])) echo 'checked'; ?>>
          <label for="terms">
            I agree to the <a href="#" target="_blank">Terms of Service</a> and
            <a href="#" target="_blank">Privacy Policy</a> *
          </label>
        </div>
        <div class="checkbox-group">
          <input type="checkbox" id="newsletter" name="newsletter" <?php if (isset($_POST['newsletter'])) echo 'checked'; ?>>
          <label for="newsletter">
            Subscribe to our newsletter for updates and special offers
          </label>
        </div>
        <button type="submit" class="btn" id="submitBtn">
          <span class="loading-spinner"></span>
          <span class="btn-text">Create Account</span>
        </button>
      </form>
      <div class="login-link">
        <p>Already have an account? <a href="customer-auth.php">Sign in here</a></p>
      </div>
    </div>
  </div>
  <script>
    // Paste your JS from the HTML prompt here
    function togglePassword() {
      const passwordInput = document.getElementById('password');
      const toggleIcon = document.getElementById('passwordToggleIcon');
      if (passwordInput.type === 'password') {
        passwordInput.type = 'text';
        toggleIcon.className = 'fas fa-eye-slash';
      } else {
        passwordInput.type = 'password';
        toggleIcon.className = 'fas fa-eye';
      }
    }

    function checkPasswordStrength(password) {
      let strength = 0;
      let feedback = '';
      if (password.length >= 8) strength++;
      if (/[a-z]/.test(password)) strength++;
      if (/[A-Z]/.test(password)) strength++;
      if (/[0-9]/.test(password)) strength++;
      if (/[^A-Za-z0-9]/.test(password)) strength++;
      const strengthBar = document.getElementById('strengthFill').parentElement;
      const strengthText = document.getElementById('strengthText');
      strengthBar.className = 'strength-bar';
      if (password.length === 0) {
        feedback = 'Enter password';
      } else if (strength < 3) {
        feedback = 'Weak';
        strengthBar.classList.add('strength-weak');
      } else if (strength < 4) {
        feedback = 'Medium';
        strengthBar.classList.add('strength-medium');
      } else {
        feedback = 'Strong';
        strengthBar.classList.add('strength-strong');
      }
      strengthText.textContent = feedback;
    }
    document.getElementById('password').addEventListener('input', function() {
      checkPasswordStrength(this.value);
    });
    document.getElementById('confirmPassword').addEventListener('input', function() {
      const password = document.getElementById('password').value;
      const errorMsg = this.closest('.form-group').querySelector('.error-message');
      if (this.value && this.value !== password) {
        this.classList.add('error');
        errorMsg.style.display = 'block';
      } else {
        this.classList.remove('error');
        errorMsg.style.display = 'none';
      }
    });
    
    // Aadhaar number validation
    document.getElementById('aadhaarNumber').addEventListener('input', function() {
      const aadhaarPattern = /^[0-9]{12}$/;
      const errorMsg = this.closest('.form-group').querySelector('.error-message');
      
      // Remove non-numeric characters
      this.value = this.value.replace(/[^0-9]/g, '');
      
      if (this.value && !aadhaarPattern.test(this.value)) {
        this.classList.add('error');
        errorMsg.style.display = 'block';
      } else {
        this.classList.remove('error');
        errorMsg.style.display = 'none';
      }
    });
  </script>
</body>

</html>