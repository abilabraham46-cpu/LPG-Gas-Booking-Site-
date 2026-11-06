<?php
session_start();
require_once 'config/database.php';
require_once 'includes/auth.php';

// Initialize Auth class
$auth = new Auth();

// Check if admin is logged in
if (!$auth->isLoggedIn()) {
    header('Location: admin-portal.php?error=login_required');
    exit;
}

$current_user = $auth->getCurrentUser();

if (!$current_user || $current_user['role'] !== 'admin') {
    header('Location: admin-portal.php?error=access_denied');
    exit;
}
$message = '';
$error = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Change Password
        if (isset($_POST['change_password'])) {
            $current_password = $_POST['current_password'];
            $new_password = $_POST['new_password'];
            $confirm_password = $_POST['confirm_password'];
            
            if ($new_password !== $confirm_password) {
                throw new Exception('New passwords do not match.');
            }
            
            if (strlen($new_password) < 6) {
                throw new Exception('New password must be at least 6 characters long.');
            }
            
            // Verify current password
            // First, get the current password hash from database
            $password_sql = "SELECT password FROM users WHERE id = ?";
            $password_stmt = executeQuery($password_sql, [$current_user['id']]);
            $user_password_data = $password_stmt->fetch();
            
            if (!$user_password_data || !$user_password_data['password']) {
                throw new Exception('Unable to verify current password. Please contact system administrator.');
            }
            
            if (!password_verify($current_password, $user_password_data['password'])) {
                throw new Exception('Current password is incorrect.');
            }
            
            // Update password
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            $update_sql = "UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?";
            executeQuery($update_sql, [$hashed_password, $current_user['id']]);
            
            $message = 'Password changed successfully!';
        }
        
        // Update Contact Info
        if (isset($_POST['update_contact'])) {
            $contact_email = filter_var($_POST['contact_email'], FILTER_VALIDATE_EMAIL);
            $contact_phone = trim($_POST['contact_phone']);
            
            if (!$contact_email) {
                throw new Exception('Please enter a valid email address.');
            }
            
            // Update admin contact info
            $contact_sql = "UPDATE users SET email = ?, phone = ?, updated_at = NOW() WHERE id = ?";
            executeQuery($contact_sql, [$contact_email, $contact_phone, $current_user['id']]);
            
            $message = 'Contact information updated successfully!';
        }
        
        // Update System Settings (Maintenance Mode)
        if (isset($_POST['update_settings'])) {
            $maintenance_mode = isset($_POST['maintenance_mode']) ? 1 : 0;
            
            try {
                // Update maintenance mode setting (match database structure without updated_by column)
                $update_sql = "UPDATE system_settings 
                              SET setting_value = ?,
                                  updated_at = CURRENT_TIMESTAMP 
                              WHERE setting_name = 'maintenance_mode'";
                executeQuery($update_sql, [$maintenance_mode]);
                
                // Verify the update
                $verify_sql = "SELECT setting_value FROM system_settings WHERE setting_name = 'maintenance_mode'";
                $verify_stmt = executeQuery($verify_sql);
                $verify_result = $verify_stmt->fetch();
                
                if ($verify_result && $verify_result['setting_value'] == $maintenance_mode) {
                    $message = 'Maintenance mode ' . ($maintenance_mode ? 'ENABLED ✓' : 'DISABLED ✓') . ' successfully! ' . 
                              ($maintenance_mode ? 'Customer and delivery portals are now blocked.' : 'Users can now login normally.');
                    
                    // Force refresh settings
                    $current_settings['maintenance_mode'] = $maintenance_mode;
                } else {
                    throw new Exception('Failed to verify maintenance mode update. Please try again.');
                }
            } catch (Exception $settings_error) {
                throw new Exception('Failed to update maintenance mode: ' . $settings_error->getMessage());
            }
        }
        
        // Handle Data Operations
        if (isset($_POST['backup_data'])) {
            $backup_dir = 'backups/';
            if (!file_exists($backup_dir)) {
                mkdir($backup_dir, 0777, true);
            }
            
            $timestamp = date('Y-m-d_H-i-s');
            $backup_file = $backup_dir . "backup_$timestamp.sql";
            
            // Get all tables data
            $tables = ['users', 'orders', 'system_settings'];
            $backup_content = "-- LIAA LPG System Backup\n-- Generated on: " . date('Y-m-d H:i:s') . "\n\n";
            
            foreach ($tables as $table) {
                try {
                    $result = executeQuery("SELECT * FROM $table");
                    $rows = $result->fetchAll(PDO::FETCH_ASSOC);
                    
                    if (!empty($rows)) {
                        $backup_content .= "-- Table: $table\n";
                        $backup_content .= "TRUNCATE TABLE $table;\n";
                        
                        foreach ($rows as $row) {
                            $columns = implode(', ', array_keys($row));
                            $values = implode(', ', array_map(function($val) {
                                return $val === null ? 'NULL' : "'" . addslashes($val) . "'";
                            }, array_values($row)));
                            
                            $backup_content .= "INSERT INTO $table ($columns) VALUES ($values);\n";
                        }
                        $backup_content .= "\n";
                    }
                } catch (Exception $e) {
                    // Table might not exist, skip it
                    continue;
                }
            }
            
            if (file_put_contents($backup_file, $backup_content)) {
                $message = "Backup created successfully: $backup_file";
            } else {
                throw new Exception('Failed to create backup file.');
            }
        }
        
        if (isset($_POST['export_data'])) {
            $export_type = $_POST['export_type'] ?? 'orders';
            $export_dir = 'exports/';
            if (!file_exists($export_dir)) {
                mkdir($export_dir, 0777, true);
            }
            
            $timestamp = date('Y-m-d_H-i-s');
            $export_file = $export_dir . "{$export_type}_export_$timestamp.csv";
            
            $query = "";
            switch ($export_type) {
                case 'orders':
                    $query = "SELECT o.*, u.name as customer_name, u.email, u.phone 
                             FROM orders o 
                             LEFT JOIN users u ON o.customer_id = u.id 
                             ORDER BY o.created_at DESC";
                    break;
                case 'customers':
                    $query = "SELECT name, email, phone, address, created_at 
                             FROM users 
                             WHERE role = 'customer' 
                             ORDER BY created_at DESC";
                    break;
                case 'staff':
                    $query = "SELECT name, email, phone, role, created_at 
                             FROM users 
                             WHERE role IN ('admin', 'delivery') 
                             ORDER BY created_at DESC";
                    break;
                default:
                    throw new Exception('Invalid export type');
            }
            
            $result = executeQuery($query);
            $data = $result->fetchAll(PDO::FETCH_ASSOC);
            
            if (!empty($data)) {
                $csv_file = fopen($export_file, 'w');
                fputcsv($csv_file, array_keys($data[0])); // Header
                
                foreach ($data as $row) {
                    fputcsv($csv_file, $row);
                }
                fclose($csv_file);
                
                $message = "Data exported successfully: $export_file";
            } else {
                throw new Exception('No data found to export.');
            }
        }
        
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Fetch current admin data and settings
try {
    // Verify we have a valid current user
    if (!$current_user || !isset($current_user['id'])) {
        throw new Exception('Invalid user session. Please log in again.');
    }
    
    $admin_sql = "SELECT id, name, email, phone, role, created_at FROM users WHERE id = ?";
    $admin_stmt = executeQuery($admin_sql, [$current_user['id']]);
    $admin_data = $admin_stmt->fetch();
    
    if (!$admin_data) {
        throw new Exception('User account not found. Please log in again.');
    }
    
    // Fetch current system settings
    $settings_sql = "SELECT setting_name, setting_value FROM system_settings";
    try {
        $settings_stmt = executeQuery($settings_sql);
        $current_settings = [];
        while ($row = $settings_stmt->fetch()) {
            $current_settings[$row['setting_name']] = $row['setting_value'];
        }
    } catch (Exception $e) {
        // Settings table might not exist yet
        $current_settings = [];
    }
} catch (Exception $e) {
    $error = "Error fetching admin data: " . $e->getMessage();
    $admin_data = $current_user; // Fallback
    $current_settings = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>System Settings | LIAA LPG Admin</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <style>
    :root {
      --flame-orange: #FF6B35;
      --flame-red: #F7931E;
      --deep-navy: #1a1f36;
      --slate-blue: #2d3748;
      --soft-gray: #e2e8f0;
      --pure-white: #ffffff;
      --success-green: #48bb78;
      --warning-amber: #ed8936;
      --danger-red: #f56565;
      --info-blue: #4299e1;
      --glass-white: rgba(255, 255, 255, 0.1);
      --glass-dark: rgba(26, 31, 54, 0.8);
      --shadow-soft: 0 8px 32px rgba(26, 31, 54, 0.12);
      --shadow-strong: 0 20px 60px rgba(26, 31, 54, 0.25);
      --border-radius: 20px;
      --transition: all 0.4s cubic-bezier(0.25, 0.46, 0.45, 0.94);
    }

    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
      font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
    }

    body {
      background: linear-gradient(135deg, var(--flame-orange), var(--flame-red));
      min-height: 100vh;
      color: var(--deep-navy);
      overflow-x: hidden;
    }

    /* Floating particles effect */
    body::before {
      content: '';
      position: fixed;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      background: radial-gradient(circle at 20% 20%, rgba(255, 107, 53, 0.3) 0%, transparent 50%),
                  radial-gradient(circle at 80% 80%, rgba(247, 147, 30, 0.3) 0%, transparent 50%),
                  radial-gradient(circle at 60% 30%, rgba(72, 187, 120, 0.2) 0%, transparent 50%);
      pointer-events: none;
      z-index: -1;
    }

    /* Header */
    .header {
      background: var(--glass-white);
      backdrop-filter: blur(20px);
      border-bottom: 1px solid rgba(255, 255, 255, 0.18);
      padding: 1.2rem 0;
      position: sticky;
      top: 0;
      z-index: 1000;
      box-shadow: var(--shadow-soft);
    }

    .header-container {
      max-width: 1400px;
      margin: 0 auto;
      padding: 0 2rem;
      display: flex;
      justify-content: space-between;
      align-items: center;
    }

    .logo {
      font-size: 1.8rem;
      font-weight: 800;
      background: linear-gradient(135deg, var(--pure-white), var(--pure-white));
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      background-clip: text;
      text-decoration: none;
      display: flex;
      align-items: center;
      gap: 0.8rem;
    }

    .breadcrumb {
      display: flex;
      align-items: center;
      gap: 0.8rem;
      color: rgba(255, 255, 255, 0.8);
      font-size: 0.9rem;
    }

    .breadcrumb a {
      color: var(--pure-white);
      text-decoration: none;
      display: flex;
      align-items: center;
      gap: 0.5rem;
      transition: var(--transition);
    }

    .breadcrumb a:hover {
      color: var(--flame-orange);
    }

    /* Main Container */
    .container {
      max-width: 1000px;
      margin: 0 auto;
      padding: 2rem;
    }

    .page-title {
      color: var(--pure-white);
      font-size: 2.5rem;
      font-weight: 900;
      margin-bottom: 0.5rem;
      display: flex;
      align-items: center;
      gap: 1rem;
    }

    .page-subtitle {
      color: rgba(255, 255, 255, 0.8);
      font-size: 1.1rem;
      margin-bottom: 3rem;
      font-weight: 400;
    }

    /* Alert Messages */
    .alert {
      padding: 1rem 1.5rem;
      border-radius: 16px;
      margin-bottom: 1.5rem;
      font-weight: 600;
      backdrop-filter: blur(10px);
      border: 1px solid;
      display: flex;
      align-items: center;
      gap: 0.8rem;
    }

    .alert-success {
      background: rgba(72, 187, 120, 0.15);
      color: #48bb78;
      border-color: rgba(72, 187, 120, 0.3);
    }

    .alert-error {
      background: rgba(245, 101, 101, 0.15);
      color: #f56565;
      border-color: rgba(245, 101, 101, 0.3);
    }

    /* Settings Sections */
    .settings-section {
      background: var(--glass-white);
      backdrop-filter: blur(25px);
      padding: 2.5rem;
      border-radius: 24px;
      border: 1px solid rgba(255, 255, 255, 0.18);
      margin-bottom: 2rem;
      box-shadow: var(--shadow-soft);
      transition: var(--transition);
    }

    .settings-section:hover {
      transform: translateY(-2px);
      box-shadow: var(--shadow-strong);
    }

    .settings-section h2 {
      color: var(--pure-white);
      font-size: 1.4rem;
      font-weight: 700;
      margin-bottom: 1.5rem;
      display: flex;
      align-items: center;
      gap: 0.8rem;
      position: relative;
    }

    .settings-section h2::after {
      content: '';
      position: absolute;
      bottom: -8px;
      left: 0;
      width: 50px;
      height: 3px;
      background: linear-gradient(90deg, var(--flame-orange), var(--flame-red));
      border-radius: 2px;
    }

    /* Form Elements */
    .form-group {
      margin-bottom: 1.5rem;
    }

    .form-row {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 1.5rem;
    }

    .form-group label {
      display: block;
      margin-bottom: 0.5rem;
      color: var(--pure-white);
      font-weight: 600;
      font-size: 0.9rem;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }

    .form-group input[type="text"],
    .form-group input[type="email"],
    .form-group input[type="password"],
    .form-group input[type="file"],
    .form-group textarea,
    .form-group select {
      width: 100%;
      padding: 0.8rem 1rem;
      background: rgba(255, 255, 255, 0.1);
      border: 2px solid rgba(255, 255, 255, 0.2);
      border-radius: 12px;
      color: var(--pure-white);
      font-size: 0.9rem;
      transition: var(--transition);
      backdrop-filter: blur(10px);
    }

    .form-group input::placeholder {
      color: rgba(255, 255, 255, 0.6);
    }

    .form-group input:focus,
    .form-group textarea:focus,
    .form-group select:focus {
      outline: none;
      border-color: var(--flame-orange);
      background: rgba(255, 255, 255, 0.15);
      box-shadow: 0 0 0 4px rgba(255, 107, 53, 0.2);
      transform: translateY(-2px);
    }

    /* Toggle Switch */
    .toggle-container {
      display: flex;
      align-items: center;
      gap: 1rem;
      margin-bottom: 1rem;
    }

    .toggle-switch {
      position: relative;
      display: inline-block;
      width: 60px;
      height: 30px;
    }

    .toggle-switch input {
      opacity: 0;
      width: 0;
      height: 0;
    }

    .slider {
      position: absolute;
      cursor: pointer;
      top: 0;
      left: 0;
      right: 0;
      bottom: 0;
      background: rgba(255, 255, 255, 0.2);
      border-radius: 30px;
      transition: var(--transition);
      border: 2px solid rgba(255, 255, 255, 0.3);
    }

    .slider:before {
      position: absolute;
      content: "";
      height: 22px;
      width: 22px;
      left: 2px;
      bottom: 2px;
      background: white;
      border-radius: 50%;
      transition: var(--transition);
      box-shadow: 0 2px 8px rgba(0,0,0,0.2);
    }

    input:checked + .slider {
      background: linear-gradient(135deg, var(--flame-orange), var(--flame-red));
      border-color: var(--flame-orange);
    }

    input:checked + .slider:before {
      transform: translateX(30px);
    }

    .toggle-label {
      color: var(--pure-white);
      font-weight: 600;
      font-size: 0.9rem;
    }

    /* Checkboxes */
    .checkbox-container {
      display: flex;
      align-items: center;
      gap: 0.8rem;
      margin-bottom: 1rem;
    }

    .checkbox-container input[type="checkbox"] {
      width: 20px;
      height: 20px;
      accent-color: var(--flame-orange);
    }

    .checkbox-container label {
      color: var(--pure-white);
      font-weight: 600;
      margin-bottom: 0;
      text-transform: none;
      letter-spacing: normal;
    }

    /* Buttons */
    .btn {
      display: inline-flex;
      align-items: center;
      gap: 0.5rem;
      padding: 0.8rem 1.5rem;
      border: none;
      border-radius: 12px;
      text-decoration: none;
      font-weight: 700;
      font-size: 0.9rem;
      transition: var(--transition);
      cursor: pointer;
      position: relative;
      overflow: hidden;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }

    .btn::before {
      content: '';
      position: absolute;
      top: 0;
      left: -100%;
      width: 100%;
      height: 100%;
      background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.3), transparent);
      transition: left 0.6s;
    }

    .btn:hover::before {
      left: 100%;
    }

    .btn-primary {
      background: linear-gradient(135deg, var(--flame-orange), var(--flame-red));
      color: white;
      box-shadow: 0 4px 15px rgba(255, 107, 53, 0.3);
    }

    .btn-success {
      background: linear-gradient(135deg, var(--success-green), #38a169);
      color: white;
      box-shadow: 0 4px 15px rgba(72, 187, 120, 0.3);
    }

    .btn-warning {
      background: linear-gradient(135deg, var(--warning-amber), #dd6b20);
      color: white;
      box-shadow: 0 4px 15px rgba(237, 137, 54, 0.3);
    }

    .btn-secondary {
      background: linear-gradient(135deg, var(--info-blue), #3182ce);
      color: white;
      box-shadow: 0 4px 15px rgba(66, 153, 225, 0.3);
    }

    .btn-danger {
      background: linear-gradient(135deg, var(--danger-red), #e53e3e);
      color: white;
      box-shadow: 0 4px 15px rgba(245, 101, 101, 0.3);
    }

    .btn:hover {
      transform: translateY(-2px);
      box-shadow: 0 8px 25px rgba(0,0,0,0.2);
    }

    .btn-group {
      display: flex;
      gap: 1rem;
      margin-top: 2rem;
      flex-wrap: wrap;
    }

    /* Info Cards */
    .info-card {
      background: rgba(255, 255, 255, 0.05);
      border: 1px solid rgba(255, 255, 255, 0.1);
      border-radius: 12px;
      padding: 1rem;
      margin-top: 1rem;
    }

    .info-card p {
      color: rgba(255, 255, 255, 0.8);
      font-size: 0.85rem;
      line-height: 1.5;
    }

    /* Responsive Design */
    @media (max-width: 768px) {
      .container {
        padding: 1rem;
      }

      .header-container {
        flex-direction: column;
        gap: 1rem;
      }

      .page-title {
        font-size: 2rem;
      }

      .settings-section {
        padding: 1.5rem;
      }

      .form-row {
        grid-template-columns: 1fr;
      }

      .btn-group {
        flex-direction: column;
      }
    }
  </style>
</head>
<body>
  <!-- Header -->
  <div class="header">
    <div class="header-container">
      <a href="admin-portal.php" class="logo">
        <i class="fas fa-fire"></i>
        LIAA LPG
      </a>
      <div class="breadcrumb">
        <a href="admin-portal.php">
          <i class="fas fa-tachometer-alt"></i>
          Dashboard
        </a>
        <i class="fas fa-chevron-right"></i>
        <span>System Settings</span>
      </div>
    </div>
  </div>

  <div class="container">
    <!-- Page Title -->
    <h1 class="page-title">
      <i class="fas fa-cogs"></i>
      System Configuration
    </h1>
    <p class="page-subtitle">
      Manage administrative settings, security preferences, and system maintenance
    </p>

    <!-- Alert Messages -->
    <?php if ($message): ?>
      <div class="alert alert-success">
        <i class="fas fa-check-circle"></i>
        <?php echo htmlspecialchars($message); ?>
      </div>
    <?php endif; ?>
    
    <?php if ($error): ?>
      <div class="alert alert-error">
        <i class="fas fa-exclamation-circle"></i>
        <?php echo htmlspecialchars($error); ?>
      </div>
    <?php endif; ?>

    <!-- Change Password Section -->
    <div class="settings-section">
      <h2><i class="fas fa-key"></i> Security & Password</h2>
      <form method="post">
        <div class="form-row">
          <div class="form-group">
            <label for="current_password">Current Password</label>
            <input type="password" id="current_password" name="current_password" required placeholder="Enter current password" />
          </div>
          <div class="form-group">
            <label for="new_password">New Password</label>
            <input type="password" id="new_password" name="new_password" required placeholder="Enter new password" />
          </div>
        </div>
        <div class="form-group">
          <label for="confirm_password">Confirm New Password</label>
          <input type="password" id="confirm_password" name="confirm_password" required placeholder="Confirm new password" />
        </div>
        <div class="info-card">
          <p><i class="fas fa-info-circle"></i> Password must be at least 6 characters long and contain a mix of letters and numbers for better security.</p>
        </div>
        <div class="btn-group">
          <button type="submit" name="change_password" class="btn btn-primary">
            <i class="fas fa-save"></i> Update Password
          </button>
        </div>
      </form>
    </div>

    <!-- Contact Information Section -->
    <div class="settings-section">
      <h2><i class="fas fa-envelope"></i> Administrator Contact Information</h2>
      <form method="post">
        <div class="form-row">
          <div class="form-group">
            <label for="contact_email">Admin Email Address</label>
            <input type="email" id="contact_email" name="contact_email" 
                   value="<?php echo htmlspecialchars($admin_data['email'] ?? ''); ?>" 
                   required placeholder="admin@example.com" />
          </div>
          <div class="form-group">
            <label for="contact_phone">Contact Phone Number</label>
            <input type="text" id="contact_phone" name="contact_phone" 
                   value="<?php echo htmlspecialchars($admin_data['phone'] ?? ''); ?>" 
                   placeholder="+1 (555) 123-4567" />
          </div>
        </div>
        <div class="info-card">
          <p><i class="fas fa-info-circle"></i> This information will be used for system notifications and customer inquiries.</p>
        </div>
        <div class="btn-group">
          <button type="submit" name="update_contact" class="btn btn-success">
            <i class="fas fa-save"></i> Update Contact Info
          </button>
        </div>
      </form>
    </div>

    <!-- Data Management Section -->
    <div class="settings-section">
      <h2><i class="fas fa-database"></i> Data Management</h2>
      
      <!-- Backup Section -->
      <div style="margin-bottom: 2rem;">
        <h3 style="color: var(--pure-white); margin-bottom: 1rem; display: flex; align-items: center; gap: 0.5rem;">
          <i class="fas fa-download"></i> Backup System Data
        </h3>
        <form method="post" style="display: inline;">
          <button type="submit" name="backup_data" class="btn btn-secondary" 
                  onclick="return confirm('Create a backup of all system data? This may take a few moments.')">
            <i class="fas fa-download"></i> Create Backup
          </button>
        </form>
        <div class="info-card">
          <p><i class="fas fa-info-circle"></i> Creates a complete backup of users, orders, and system settings.</p>
        </div>
      </div>

      <!-- Export Section -->
      <div>
        <h3 style="color: var(--pure-white); margin-bottom: 1rem; display: flex; align-items: center; gap: 0.5rem;">
          <i class="fas fa-file-export"></i> Export Data
        </h3>
        <form method="post" style="display: flex; align-items: end; gap: 1rem; flex-wrap: wrap;">
          <div class="form-group" style="margin-bottom: 0; min-width: 200px;">
            <label for="export_type">Data Type</label>
            <select id="export_type" name="export_type" required>
              <option value="orders">Orders Data</option>
              <option value="customers">Customer Data</option>
              <option value="staff">Staff Data</option>
            </select>
          </div>
          <button type="submit" name="export_data" class="btn btn-warning">
            <i class="fas fa-file-csv"></i> Export CSV
          </button>
        </form>
        <div class="info-card">
          <p><i class="fas fa-info-circle"></i> Export specific data types to CSV format for analysis or external use.</p>
        </div>
      </div>
    </div>

   
    <!-- System Maintenance Section -->
    <div class="settings-section">
      <h2><i class="fas fa-tools"></i> System Maintenance</h2>
      
      <?php 
      $is_maintenance_on = !empty($current_settings['maintenance_mode']) && $current_settings['maintenance_mode'] == '1';
      ?>
      
      <!-- Current Status Display -->
      <div class="info-card" style="margin-bottom: 1.5rem; border-left: 4px solid <?php echo $is_maintenance_on ? '#f56565' : '#48bb78'; ?>;">
        <p style="font-size: 1rem; font-weight: 700; color: <?php echo $is_maintenance_on ? '#f56565' : '#48bb78'; ?>;">
          <i class="fas fa-circle" style="font-size: 0.6rem;"></i> 
          Current Status: <?php echo $is_maintenance_on ? 'MAINTENANCE MODE ACTIVE' : 'SYSTEM OPERATIONAL'; ?>
        </p>
        <p style="margin-top: 0.5rem;">
          <?php echo $is_maintenance_on 
            ? '⚠️ Customer and delivery portals are currently blocked. Only admins can access the system.' 
            : '✓ All portals are accessible. Users can login and place orders normally.'; ?>
        </p>
      </div>
      
      <form method="post" onsubmit="return confirm('<?php echo $is_maintenance_on ? 'Disable maintenance mode and allow users to login?' : 'Enable maintenance mode and block all customer/delivery access?'; ?>');">
        <div class="toggle-container">
          <label class="toggle-switch">
            <input type="checkbox" name="maintenance_mode" id="maintenanceToggle"
                   <?php echo $is_maintenance_on ? 'checked' : ''; ?> />
            <span class="slider"></span>
          </label>
          <span class="toggle-label">Enable Maintenance Mode</span>
        </div>
        <div class="info-card">
          <p><i class="fas fa-exclamation-triangle"></i> <strong>Warning:</strong> Enabling maintenance mode will temporarily disable customer access to the system. Only use this during system updates or maintenance.</p>
        </div>
        <div class="btn-group">
          <button type="submit" name="update_settings" class="btn btn-danger">
            <i class="fas fa-save"></i> Save Changes
          </button>
        </div>
      </form>
    </div>

    <!-- System Information Section -->
    <div class="settings-section">
      <h2><i class="fas fa-info-circle"></i> System Information</h2>
      <div class="form-row">
        <div class="form-group">
          <label>System Version</label>
          <div class="info-card">
            <p><strong>LIAA LPG Management System v1.0</strong></p>
            <p>Last Updated: <?php echo date('F j, Y'); ?></p>
          </div>
        </div>
        <div class="form-group">
          <label>Database Status</label>
          <div class="info-card">
            <p><i class="fas fa-check-circle" style="color: #48bb78;"></i> <strong>Connected</strong></p>
            <?php 
            $backup_dir = 'backups/';
            $latest_backup = 'Never';
            if (file_exists($backup_dir)) {
              $files = glob($backup_dir . 'backup_*.sql');
              if (!empty($files)) {
                $latest_file = max($files);
                $latest_backup = date('M j, Y g:i A', filemtime($latest_file));
              }
            }
            ?>
            <p>Last Backup: <?php echo $latest_backup; ?></p>
            <p>Backup Directory: <?php echo (is_writable($backup_dir) ? '<span style="color: #48bb78;">Writable</span>' : '<span style="color: #f56565;">Not Writable</span>'); ?></p>
          </div>
        </div>
      </div>
    </div>
  </div>

  <script>
    // Password strength indicator
    document.getElementById('new_password').addEventListener('input', function(e) {
      const password = e.target.value;
      let strength = 0;
      
      if (password.length >= 6) strength++;
      if (password.match(/[A-Z]/)) strength++;
      if (password.match(/[0-9]/)) strength++;
      if (password.match(/[^A-Za-z0-9]/)) strength++;
    });

    // Confirm password match
    document.getElementById('confirm_password').addEventListener('input', function(e) {
      const password = document.getElementById('new_password').value;
      const confirm = e.target.value;
      
      if (password !== confirm && confirm.length > 0) {
        e.target.style.borderColor = '#f56565';
      } else {
        e.target.style.borderColor = 'rgba(255, 255, 255, 0.2)';
      }
    });

    // Enhanced interactions
    document.addEventListener('DOMContentLoaded', function() {
      // Animate sections on scroll
      const sections = document.querySelectorAll('.settings-section');
      const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
          if (entry.isIntersecting) {
            entry.target.style.transform = 'translateY(0)';
            entry.target.style.opacity = '1';
          }
        });
      });

      sections.forEach(section => {
        section.style.transform = 'translateY(20px)';
        section.style.opacity = '0';
        section.style.transition = 'all 0.6s ease';
        observer.observe(section);
      });
    });
  </script>
</body>
</html>
