<?php
session_start();
require_once 'config/database.php';
require_once 'includes/auth.php';

// Initialize Auth class
$auth = new Auth();

// Check if admin is logged in
if (!$auth->isLoggedIn() || $auth->getCurrentUser()['role'] !== 'admin') {
    header('Location: admin-portal.php');
    exit;
}

$current_user = $auth->getCurrentUser();
$message = '';
$error = '';

// Handle URL parameters for success messages and tab switching
if (isset($_GET['success'])) {
    $message = urldecode($_GET['success']);
}

if (isset($_GET['error'])) {
    $error = urldecode($_GET['error']);
}

// Handle Add User
if (isset($_POST['add_user'])) {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $role = $_POST['role'] ?? 'customer';
    $address = trim($_POST['address'] ?? '');
    $password = $_POST['password'] ?? 'password123';
    
    if (empty($name) || empty($email) || empty($phone)) {
        $error = 'Please fill all required fields.';
    } else {
        try {
            // Check if user already exists
            $check_sql = "SELECT id FROM users WHERE email = ? OR phone = ?";
            $check_stmt = executeQuery($check_sql, [$email, $phone]);
            
            if ($check_stmt->rowCount() > 0) {
                $error = 'User with this email or phone already exists.';
            } else {
                // Insert new user
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $insert_sql = "INSERT INTO users (name, email, phone, password, role, address, status) VALUES (?, ?, ?, ?, ?, ?, 'active')";
                $result = executeQuery($insert_sql, [$name, $email, $phone, $hashed_password, $role, $address]);
                
                if ($result->rowCount() > 0) {
                    $message = 'User added successfully!';
                    // Redirect to clear the form and show success message
                    header('Location: manage-users.php?success=' . urlencode($message));
                    exit;
                } else {
                    $error = 'Failed to add user.';
                }
            }
        } catch (Exception $e) {
            $error = 'Error adding user: ' . $e->getMessage();
        }
    }
}

// Handle Delete User
if (isset($_GET['delete']) && isset($_GET['confirm']) && $_GET['confirm'] === 'yes') {
    $delete_id = intval($_GET['delete']);
    
    if ($delete_id === $current_user['id']) {
        $error = 'You cannot delete your own account.';
    } else {
        try {
            // First check if user exists and is not an admin
            $check_sql = "SELECT id, role FROM users WHERE id = ?";
            $check_stmt = executeQuery($check_sql, [$delete_id]);
            $user_to_delete = $check_stmt->fetch();
            
            if (!$user_to_delete) {
                $error = 'User not found.';
            } elseif ($user_to_delete['role'] === 'admin') {
                $error = 'Cannot delete administrator accounts.';
            } else {
                // Delete the user
                $delete_sql = "DELETE FROM users WHERE id = ?";
                $result = executeQuery($delete_sql, [$delete_id]);
                
                if ($result->rowCount() > 0) {
                    $message = 'User deleted successfully!';
                } else {
                    $error = 'Failed to delete user.';
                }
            }
        } catch (Exception $e) {
            $error = 'Error deleting user: ' . $e->getMessage();
        }
    }
}

// Handle Update User Status
if (isset($_POST['update_status'])) {
    $user_id = intval($_POST['user_id']);
    $new_status = $_POST['status'];
    
    try {
        $update_sql = "UPDATE users SET status = ? WHERE id = ?";
        executeQuery($update_sql, [$new_status, $user_id]);
        $message = 'User status updated successfully!';
    } catch (Exception $e) {
        $error = 'Error updating user status: ' . $e->getMessage();
    }
}

// Handle Approve Waiting User
if (isset($_GET['approve'])) {
    $waiting_id = intval($_GET['approve']);
    
    try {
        // Get waiting user data
        $waiting_sql = "SELECT * FROM waiting_room WHERE id = ? AND status = 'pending'";
        $waiting_stmt = executeQuery($waiting_sql, [$waiting_id]);
        $waiting_user = $waiting_stmt->fetch();
        
        if ($waiting_user) {
            // Move to users table
            $insert_sql = "INSERT INTO users (name, email, phone, password, role, address, status) VALUES (?, ?, ?, ?, 'customer', ?, 'active')";
            executeQuery($insert_sql, [
                $waiting_user['name'],
                $waiting_user['email'],
                $waiting_user['phone'],
                $waiting_user['password'],
                $waiting_user['address']
            ]);
            
            // Update waiting room status
            $update_waiting_sql = "UPDATE waiting_room SET status = 'approved' WHERE id = ?";
            executeQuery($update_waiting_sql, [$waiting_id]);
            
            $message = 'User approved and activated successfully!';
            
            // Redirect to refresh the page and show updated data
            header('Location: manage-users.php?tab=waiting&success=' . urlencode($message));
            exit;
        } else {
            $error = 'Waiting user not found.';
        }
    } catch (Exception $e) {
        $error = 'Error approving user: ' . $e->getMessage();
    }
}

// Handle Approve Waiting User via POST (for better integration)
if (isset($_POST['approve_registration'])) {
    $registration_id = filter_var($_POST['registration_id'], FILTER_VALIDATE_INT);
    
    if (!$registration_id) {
        $error = 'Invalid registration ID provided.';
    } else {
        try {
            // Get registration details
            $reg_sql = "SELECT * FROM waiting_room WHERE id = ? AND status = 'pending'";
            $reg_stmt = executeQuery($reg_sql, [$registration_id]);
            $registration = $reg_stmt->fetch();
            
            if (!$registration) {
                $error = 'Registration not found or already processed.';
            } else {
                // Move to users table (approve)
                $user_sql = "INSERT INTO users (name, email, phone, password, role, address, status) 
                             VALUES (?, ?, ?, ?, 'customer', ?, 'active')";
                $user_result = executeQuery($user_sql, [
                    $registration['name'], 
                    $registration['email'], 
                    $registration['phone'], 
                    $registration['password'], 
                    $registration['address']
                ]);
                
                if ($user_result) {
                    // Update waiting room status
                    $update_sql = "UPDATE waiting_room SET status = 'approved' WHERE id = ?";
                    executeQuery($update_sql, [$registration_id]);
                    $message = "Customer registration approved successfully! " . $registration['name'] . " can now log in.";
                    
                    // Redirect to refresh the page and show updated data
                    header('Location: manage-users.php?tab=waiting&success=' . urlencode($message));
                    exit;
                } else {
                    $error = 'Failed to approve registration.';
                }
            }
        } catch (Exception $e) {
            $error = 'Error approving registration: ' . $e->getMessage();
        }
    }
}

// Handle Reject Registration
if (isset($_POST['reject_registration'])) {
    $registration_id = filter_var($_POST['registration_id'], FILTER_VALIDATE_INT);
    $rejection_reason = trim($_POST['rejection_reason'] ?? '');
    
    if (!$registration_id) {
        $error = 'Invalid registration ID provided.';
    } else {
        try {
            // Update waiting room status to rejected
            $reject_sql = "UPDATE waiting_room SET status = 'rejected' WHERE id = ? AND status = 'pending'";
            $result = executeQuery($reject_sql, [$registration_id]);
            
            if ($result->rowCount() > 0) {
                $message = "Customer registration rejected successfully.";
                
                // Redirect to refresh the page and show updated data
                header('Location: manage-users.php?tab=waiting&success=' . urlencode($message));
                exit;
            } else {
                $error = 'Failed to reject registration or registration not found.';
            }
        } catch (Exception $e) {
            $error = 'Error rejecting registration: ' . $e->getMessage();
        }
    }
}

// Handle Reject Waiting User
if (isset($_GET['reject'])) {
    $waiting_id = intval($_GET['reject']);
    
    try {
        $reject_sql = "UPDATE waiting_room SET status = 'rejected' WHERE id = ?";
        executeQuery($reject_sql, [$waiting_id]);
        $message = 'User registration rejected successfully!';
        
        // Redirect to refresh the page
        header('Location: manage-users.php?tab=waiting&success=' . urlencode($message));
        exit;
    } catch (Exception $e) {
        $error = 'Error rejecting user: ' . $e->getMessage();
    }
}

// Fetch all users
$users = [];
try {
    $users_sql = "SELECT * FROM users ORDER BY created_at DESC";
    $users_stmt = executeQuery($users_sql);
    $users = $users_stmt->fetchAll();
} catch (Exception $e) {
    $error = 'Error fetching users: ' . $e->getMessage();
}

// Fetch waiting room users
$waiting_users = [];
try {
    $waiting_sql = "SELECT * FROM waiting_room WHERE status = 'pending' ORDER BY created_at DESC";
    $waiting_stmt = executeQuery($waiting_sql);
    $waiting_users = $waiting_stmt->fetchAll();
} catch (Exception $e) {
    // Waiting room might not exist, ignore error
}

// Get user statistics
$stats = [
    'total_users' => count($users),
    'active_users' => count(array_filter($users, fn($u) => $u['status'] === 'active')),
    'customers' => count(array_filter($users, fn($u) => $u['role'] === 'customer')),
    'delivery_staff' => count(array_filter($users, fn($u) => $u['role'] === 'delivery')),
    'waiting_approval' => count($waiting_users)
];

// Handle Edit User (fetch data for prefill)
$edit_user = null;
if (isset($_GET['edit'])) {
    $edit_id = intval($_GET['edit']);
    try {
        $editSql = "SELECT * FROM users WHERE id = ? LIMIT 1";
        $stmt = executeQuery($editSql, [$edit_id]);
        $edit_user = $stmt->fetch();
    } catch (Exception $e) {
        $error = "Error fetching user data: " . $e->getMessage();
    }
}

// Handle Update User
if (isset($_POST['update_user'])) {
    $id = intval($_POST['id']);
    $name = trim($_POST['name'] ?? '');
    $role = $_POST['role'] ?? '';
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    
    try {
        if (empty($name) || empty($email) || empty($phone) || empty($role)) {
            $error = "Please fill all required fields.";
        } else {
            // Check if email or phone already exists for other users
            $checkSql = "SELECT id FROM users WHERE (email = ? OR phone = ?) AND id != ?";
            $stmt = executeQuery($checkSql, [$email, $phone, $id]);
            
            if ($stmt->fetch()) {
                $error = "Email or phone number already exists for another user.";
            } else {
                $updateSql = "UPDATE users SET name = ?, email = ?, phone = ?, role = ?, address = ? WHERE id = ?";
                $result = executeQuery($updateSql, [$name, $email, $phone, $role, $address, $id]);
                
                if ($result->rowCount() > 0) {
                    $message = "User updated successfully!";
                    // Redirect to clear the form and show success message
                    header('Location: manage-users.php?success=' . urlencode($message));
                    exit;
                } else {
                    $error = "No changes were made or user not found.";
                }
            }
        }
    } catch (Exception $e) {
        $error = "Error updating user: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Manage Users | LIAA LPG Admin</title>
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
      filter: drop-shadow(0 2px 4px rgba(255, 107, 53, 0.3));
    }

    .breadcrumb {
      display: flex;
      align-items: center;
      gap: 1rem;
      color: var(--pure-white);
      font-weight: 600;
    }

    .breadcrumb a {
      color: var(--pure-white);
      text-decoration: none;
      opacity: 0.8;
      transition: var(--transition);
    }

    .breadcrumb a:hover {
      opacity: 1;
      color: var(--flame-orange);
    }

    /* Main Container */
    .container {
      max-width: 1400px;
      margin: 0 auto;
      padding: 2rem;
    }

    .page-title {
      text-align: center;
      color: var(--pure-white);
      font-size: 2.8rem;
      font-weight: 900;
      margin-bottom: 1rem;
      text-shadow: 0 4px 8px rgba(0,0,0,0.3);
    }

    .page-subtitle {
      text-align: center;
      color: rgba(255, 255, 255, 0.8);
      font-size: 1.1rem;
      margin-bottom: 3rem;
      font-weight: 400;
    }

    /* Statistics Cards */
    .stats-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
      gap: 1.5rem;
      margin-bottom: 3rem;
    }

    .stat-card {
      background: var(--glass-white);
      backdrop-filter: blur(25px);
      padding: 2rem;
      border-radius: 20px;
      border: 1px solid rgba(255, 255, 255, 0.18);
      text-align: center;
      transition: var(--transition);
      box-shadow: var(--shadow-soft);
    }

    .stat-card:hover {
      transform: translateY(-5px) scale(1.02);
      box-shadow: var(--shadow-strong);
    }

    .stat-icon {
      width: 60px;
      height: 60px;
      margin: 0 auto 1rem;
      border-radius: 16px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.5rem;
      color: white;
      background: linear-gradient(135deg, var(--info-blue), #3182ce);
      box-shadow: 0 8px 20px rgba(66, 153, 225, 0.3);
    }

    .stat-number {
      font-size: 2.2rem;
      font-weight: 900;
      color: var(--pure-white);
      margin-bottom: 0.5rem;
    }

    .stat-label {
      color: rgba(255, 255, 255, 0.8);
      font-weight: 600;
      font-size: 0.9rem;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }

    /* Tab Navigation */
    .tab-nav {
      display: flex;
      gap: 0.5rem;
      margin-bottom: 2rem;
      background: var(--glass-white);
      backdrop-filter: blur(25px);
      padding: 0.5rem;
      border-radius: 16px;
      border: 1px solid rgba(255, 255, 255, 0.18);
      box-shadow: var(--shadow-soft);
    }

    .tab-btn {
      flex: 1;
      padding: 1rem 1.5rem;
      background: transparent;
      border: none;
      border-radius: 12px;
      color: rgba(255, 255, 255, 0.8);
      font-weight: 600;
      cursor: pointer;
      transition: var(--transition);
      font-size: 0.95rem;
    }

    .tab-btn.active {
      background: linear-gradient(135deg, var(--flame-orange), var(--flame-red));
      color: white;
      transform: translateY(-2px);
      box-shadow: var(--shadow-soft);
    }

    .tab-btn:hover:not(.active) {
      background: rgba(255, 255, 255, 0.1);
      color: var(--pure-white);
    }

    /* Tab Content */
    .tab-content {
      display: none;
      background: var(--glass-white);
      backdrop-filter: blur(25px);
      padding: 2.5rem;
      border-radius: 24px;
      border: 1px solid rgba(255, 255, 255, 0.18);
      box-shadow: var(--shadow-strong);
    }

    .tab-content.active {
      display: block;
      animation: slideInUp 0.6s ease-out;
    }

    @keyframes slideInUp {
      0% {
        opacity: 0;
        transform: translateY(30px);
      }
      100% {
        opacity: 1;
        transform: translateY(0);
      }
    }

    /* Alert Messages */
    .alert {
      padding: 1rem 1.5rem;
      border-radius: 12px;
      margin-bottom: 2rem;
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

    /* Table Styles */
    .table-container {
      background: rgba(255, 255, 255, 0.05);
      border-radius: 16px;
      overflow: hidden;
      margin-top: 2rem;
    }

    .data-table {
      width: 100%;
      border-collapse: collapse;
    }

    .data-table th {
      background: rgba(255, 255, 255, 0.1);
      color: var(--pure-white);
      padding: 1.2rem;
      text-align: left;
      font-weight: 700;
      font-size: 0.85rem;
      text-transform: uppercase;
      letter-spacing: 1px;
    }

    .data-table td {
      padding: 1.2rem;
      border-bottom: 1px solid rgba(255, 255, 255, 0.1);
      color: rgba(255, 255, 255, 0.9);
    }

    .data-table tbody tr {
      transition: var(--transition);
    }

    .data-table tbody tr:hover {
      background: rgba(255, 255, 255, 0.05);
    }

    /* Status Badges */
    .status-badge {
      padding: 0.4rem 0.8rem;
      border-radius: 12px;
      font-size: 0.75rem;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      display: inline-block;
    }

    .status-active {
      background: rgba(72, 187, 120, 0.2);
      color: #48bb78;
      border: 1px solid rgba(72, 187, 120, 0.3);
    }

    .status-inactive {
      background: rgba(245, 101, 101, 0.2);
      color: #f56565;
      border: 1px solid rgba(245, 101, 101, 0.3);
    }

    .status-pending {
      background: rgba(237, 137, 54, 0.2);
      color: #ed8936;
      border: 1px solid rgba(237, 137, 54, 0.3);
    }

    /* Role Badges */
    .role-badge {
      padding: 0.3rem 0.6rem;
      border-radius: 8px;
      font-size: 0.7rem;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }

    .role-admin {
      background: rgba(255, 107, 53, 0.2);
      color: var(--flame-orange);
    }

    .role-customer {
      background: rgba(66, 153, 225, 0.2);
      color: #4299e1;
    }

    .role-delivery {
      background: rgba(72, 187, 120, 0.2);
      color: #48bb78;
    }

    /* Button Styles */
    .btn {
      display: inline-flex;
      align-items: center;
      gap: 0.5rem;
      padding: 0.8rem 1.5rem;
      border: none;
      border-radius: 12px;
      text-decoration: none;
      font-weight: 600;
      font-size: 0.9rem;
      transition: var(--transition);
      cursor: pointer;
      text-transform: uppercase;
      letter-spacing: 0.5px;
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

    .btn-danger {
      background: linear-gradient(135deg, var(--danger-red), #e53e3e);
      color: white;
      box-shadow: 0 4px 15px rgba(245, 101, 101, 0.3);
    }

    .btn-secondary {
      background: rgba(255, 255, 255, 0.1);
      color: var(--pure-white);
      border: 2px solid rgba(255, 255, 255, 0.3);
    }

    .btn:hover {
      transform: translateY(-2px);
      box-shadow: 0 8px 25px rgba(0,0,0,0.2);
    }

    .btn-sm {
      padding: 0.4rem 0.8rem;
      font-size: 0.8rem;
    }

    /* Form Styles */
    .form-section {
      background: rgba(255, 255, 255, 0.05);
      border-radius: 20px;
      padding: 2rem;
      margin-bottom: 2rem;
      border: 1px solid rgba(255, 255, 255, 0.1);
    }

    .form-section h2 {
      color: var(--pure-white);
      margin-bottom: 1.5rem;
      font-size: 1.5rem;
      font-weight: 700;
    }

    .form-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
      gap: 1.5rem;
      margin-bottom: 2rem;
    }

    .form-group {
      margin-bottom: 1.5rem;
    }

    .form-group label {
      display: block;
      margin-bottom: 0.5rem;
      color: var(--pure-white);
      font-weight: 600;
      font-size: 0.9rem;
    }

    .form-group input,
    .form-group select,
    .form-group textarea {
      width: 100%;
      padding: 1rem;
      background: rgba(255, 255, 255, 0.1);
      border: 2px solid rgba(255, 255, 255, 0.2);
      border-radius: 12px;
      font-size: 1rem;
      color: var(--pure-white);
      transition: var(--transition);
      backdrop-filter: blur(10px);
    }

    .form-group input::placeholder,
    .form-group textarea::placeholder {
      color: rgba(255, 255, 255, 0.6);
    }

    .form-group input:focus,
    .form-group select:focus,
    .form-group textarea:focus {
      outline: none;
      border-color: var(--flame-orange);
      background: rgba(255, 255, 255, 0.15);
      box-shadow: 0 0 0 4px rgba(255, 107, 53, 0.2);
    }

    .form-actions {
      text-align: right;
      display: flex;
      gap: 1rem;
      justify-content: flex-end;
    }

    /* Action Buttons */
    .action-btns {
      display: flex;
      gap: 0.5rem;
      flex-wrap: wrap;
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

      .form-grid {
        grid-template-columns: 1fr;
      }

      .tab-nav {
        flex-direction: column;
      }

      .data-table {
        font-size: 0.8rem;
      }

      .data-table th,
      .data-table td {
        padding: 0.8rem 0.5rem;
      }

      .action-btns {
        flex-direction: column;
      }

      .form-actions {
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
        <span>Manage Users</span>
      </div>
    </div>
  </div>

  <div class="container">
    <!-- Page Title -->
    <h1 class="page-title">
      <i class="fas fa-users"></i>
      User Management
    </h1>
    <p class="page-subtitle">
      Manage system users, approve registrations, and control access permissions
    </p>

    <!-- Statistics Grid -->
    <div class="stats-grid">
      <div class="stat-card">
        <div class="stat-icon">
          <i class="fas fa-users"></i>
        </div>
        <div class="stat-number"><?php echo $stats['total_users']; ?></div>
        <div class="stat-label">Total Users</div>
      </div>
      
      <div class="stat-card">
        <div class="stat-icon" style="background: linear-gradient(135deg, var(--success-green), #38a169);">
          <i class="fas fa-user-check"></i>
        </div>
        <div class="stat-number"><?php echo $stats['active_users']; ?></div>
        <div class="stat-label">Active Users</div>
      </div>
      
      <div class="stat-card">
        <div class="stat-icon" style="background: linear-gradient(135deg, var(--flame-orange), var(--flame-red));">
          <i class="fas fa-shopping-cart"></i>
        </div>
        <div class="stat-number"><?php echo $stats['customers']; ?></div>
        <div class="stat-label">Customers</div>
      </div>
      
      <div class="stat-card">
        <div class="stat-icon" style="background: linear-gradient(135deg, var(--warning-amber), #d69e2e);">
          <i class="fas fa-truck"></i>
        </div>
        <div class="stat-number"><?php echo $stats['delivery_staff']; ?></div>
        <div class="stat-label">Delivery Staff</div>
      </div>
      
      <div class="stat-card">
        <div class="stat-icon" style="background: linear-gradient(135deg, var(--danger-red), #e53e3e);">
          <i class="fas fa-clock"></i>
        </div>
        <div class="stat-number"><?php echo $stats['waiting_approval']; ?></div>
        <div class="stat-label">Waiting Approval</div>
      </div>
    </div>

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

    <!-- Tab Navigation -->
    <div class="tab-nav">
      <button class="tab-btn active" onclick="showTab('users')">
        <i class="fas fa-users"></i>
        Active Users
      </button>
      <button class="tab-btn" onclick="showTab('waiting')">
        <i class="fas fa-clock"></i>
        Pending Approvals
        <?php if (count($waiting_users) > 0): ?>
          <span style="background: var(--danger-red); color: white; border-radius: 50%; padding: 0.2rem 0.5rem; font-size: 0.7rem; margin-left: 0.5rem;">
            <?php echo count($waiting_users); ?>
          </span>
        <?php endif; ?>
      </button>
      <button class="tab-btn" onclick="showTab('add')">
        <i class="fas fa-plus"></i>
        <?php echo $edit_user ? 'Edit User' : 'Add User'; ?>
      </button>
    </div>

    <!-- Active Users Tab -->
    <div id="users" class="tab-content active">
      <?php if (empty($users)): ?>
        <div style="text-align: center; padding: 3rem; color: rgba(255, 255, 255, 0.7);">
          <i class="fas fa-users" style="font-size: 3rem; margin-bottom: 1rem; opacity: 0.5;"></i>
          <h3>No users found</h3>
          <p>There are no users in the system yet.</p>
        </div>
      <?php else: ?>
        <div class="table-container">
          <table class="data-table">
            <thead>
              <tr>
                <th>ID</th>
                <th>Name</th>
                <th>Email</th>
                <th>Phone</th>
                <th>Role</th>
                <th>Status</th>
                <th>Created</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($users as $user): ?>
                <tr>
                  <td><?php echo $user['id']; ?></td>
                  <td><?php echo htmlspecialchars($user['name']); ?></td>
                  <td><?php echo htmlspecialchars($user['email']); ?></td>
                  <td><?php echo htmlspecialchars($user['phone'] ?? 'N/A'); ?></td>
                  <td>
                    <span class="role-badge role-<?php echo $user['role']; ?>">
                      <?php echo ucfirst($user['role']); ?>
                    </span>
                  </td>
                  <td>
                    <span class="status-badge status-<?php echo $user['status'] ?? 'active'; ?>">
                      <?php echo ucfirst($user['status'] ?? 'active'); ?>
                    </span>
                  </td>
                  <td><?php echo date('M j, Y', strtotime($user['created_at'])); ?></td>
                  <td>
                    <div class="action-btns">
                      <a href="?edit=<?php echo $user['id']; ?>&tab=add" class="btn btn-primary btn-sm">
                        <i class="fas fa-edit"></i>
                        Edit
                      </a>
                      <?php if ($user['role'] !== 'admin'): ?>
                        <a href="?delete=<?php echo $user['id']; ?>&confirm=yes" 
                           class="btn btn-danger btn-sm" 
                           onclick="return confirm('Are you sure you want to delete this user? This action cannot be undone.')">
                          <i class="fas fa-trash"></i>
                          Delete
                        </a>
                      <?php endif; ?>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>

    <!-- Pending Approvals Tab -->
    <div id="waiting" class="tab-content">
      <?php if (empty($waiting_users)): ?>
        <div style="text-align: center; padding: 3rem; color: rgba(255, 255, 255, 0.7);">
          <i class="fas fa-clock" style="font-size: 3rem; margin-bottom: 1rem; opacity: 0.5;"></i>
          <h3>No pending approvals</h3>
          <p>All user registrations have been processed.</p>
        </div>
      <?php else: ?>
        <div class="table-container">
          <table class="data-table">
            <thead>
              <tr>
                <th>ID</th>
                <th>Name</th>
                <th>Email</th>
                <th>Phone</th>
                <th>Address</th>
                <th>Registered</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($waiting_users as $waiting): ?>
                <tr>
                  <td><?php echo $waiting['id']; ?></td>
                  <td><?php echo htmlspecialchars($waiting['name']); ?></td>
                  <td><?php echo htmlspecialchars($waiting['email']); ?></td>
                  <td><?php echo htmlspecialchars($waiting['phone']); ?></td>
                  <td>
                    <span title="<?php echo htmlspecialchars($waiting['address']); ?>">
                      <?php echo htmlspecialchars(substr($waiting['address'], 0, 50)) . (strlen($waiting['address']) > 50 ? '...' : ''); ?>
                    </span>
                  </td>
                  <td><?php echo date('M j, Y g:i A', strtotime($waiting['created_at'])); ?></td>
                  <td>
                    <div class="action-btns">
                      <form method="POST" style="display: inline-block; margin-right: 0.5rem;">
                        <input type="hidden" name="registration_id" value="<?php echo $waiting['id']; ?>">
                        <button type="submit" name="approve_registration" class="btn btn-success btn-sm"
                                onclick="return confirm('Approve this customer registration? This will allow them to log in.')">
                          <i class="fas fa-check"></i>
                          Approve
                        </button>
                      </form>
                      <button type="button" class="btn btn-danger btn-sm"
                              onclick="openRejectModal(<?php echo $waiting['id']; ?>, '<?php echo htmlspecialchars($waiting['name']); ?>')">
                        <i class="fas fa-times"></i>
                        Reject
                      </button>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>

    <!-- Add/Edit User Tab -->
    <div id="add" class="tab-content">
      <div class="form-section">
        <h2>
          <i class="fas fa-<?php echo $edit_user ? 'edit' : 'plus'; ?>"></i>
          <?php echo $edit_user ? 'Edit User' : 'Add New User'; ?>
        </h2>
        
        <form method="POST">
          <?php if ($edit_user): ?>
            <input type="hidden" name="id" value="<?php echo $edit_user['id']; ?>">
          <?php endif; ?>
          
          <div class="form-grid">
            <div class="form-group">
              <label for="name">Full Name *</label>
              <input type="text" 
                     id="name" 
                     name="name" 
                     value="<?php echo htmlspecialchars($edit_user['name'] ?? ''); ?>" 
                     placeholder="Enter full name"
                     required>
            </div>
            
            <div class="form-group">
              <label for="email">Email Address *</label>
              <input type="email" 
                     id="email" 
                     name="email" 
                     value="<?php echo htmlspecialchars($edit_user['email'] ?? ''); ?>" 
                     placeholder="Enter email address"
                     required>
            </div>
            
            <div class="form-group">
              <label for="phone">Phone Number *</label>
              <input type="tel" 
                     id="phone" 
                     name="phone" 
                     value="<?php echo htmlspecialchars($edit_user['phone'] ?? ''); ?>" 
                     placeholder="Enter phone number"
                     required>
            </div>
            
            <div class="form-group">
              <label for="role">Role *</label>
              <select id="role" name="role" required>
                <option value="">Select Role</option>
                <option value="customer" <?php echo ($edit_user['role'] ?? '') === 'customer' ? 'selected' : ''; ?>>Customer</option>
                <option value="delivery" <?php echo ($edit_user['role'] ?? '') === 'delivery' ? 'selected' : ''; ?>>Delivery Personnel</option>
                <?php if (($edit_user['role'] ?? '') === 'admin'): ?>
                  <option value="admin" selected>Administrator</option>
                <?php endif; ?>
              </select>
            </div>
            
            <div class="form-group">
              <label for="address">Address</label>
              <input type="text" 
                     id="address" 
                     name="address" 
                     value="<?php echo htmlspecialchars($edit_user['address'] ?? ''); ?>" 
                     placeholder="Enter address (optional)">
            </div>
            
            <?php if (!$edit_user): ?>
            <div class="form-group">
              <label for="password">Password</label>
              <input type="password" 
                     id="password" 
                     name="password" 
                     placeholder="Leave blank for default password">
              <small style="color: rgba(255, 255, 255, 0.7); font-size: 0.8rem; margin-top: 0.5rem; display: block;">
                Default password is 'password123' if left blank
              </small>
            </div>
            <?php endif; ?>
          </div>
          
          <div class="form-actions">
            <?php if ($edit_user): ?>
              <a href="manage-users.php" class="btn btn-secondary">
                <i class="fas fa-times"></i>
                Cancel
              </a>
              <button type="submit" name="update_user" class="btn btn-primary">
                <i class="fas fa-save"></i>
                Update User
              </button>
            <?php else: ?>
              <button type="submit" name="add_user" class="btn btn-primary">
                <i class="fas fa-plus"></i>
                Add User
              </button>
            <?php endif; ?>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- Reject Registration Modal -->
  <div id="rejectModal" class="modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.5); backdrop-filter: blur(5px); z-index: 1000;">
    <div class="modal-content" style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: var(--glass-white); backdrop-filter: blur(25px); border: 1px solid rgba(255, 255, 255, 0.18); border-radius: 24px; padding: 2rem; min-width: 400px; max-width: 90vw;">
      <div class="modal-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 2rem;">
        <h3 class="modal-title" style="color: var(--pure-white); margin: 0; font-size: 1.5rem; font-weight: 700;">
          <i class="fas fa-times-circle"></i> Reject Customer Registration
        </h3>
        <button type="button" class="close-btn" onclick="closeRejectModal()" style="background: none; border: none; color: var(--pure-white); font-size: 1.5rem; cursor: pointer; padding: 0.5rem; border-radius: 8px; transition: var(--transition);">
          &times;
        </button>
      </div>
      <form method="POST">
        <input type="hidden" name="registration_id" id="rejectRegistrationId">
        <div class="form-group">
          <label class="form-label" style="color: var(--pure-white); font-weight: 600;">Customer Name:</label>
          <div id="rejectCustomerName" style="padding: 1rem; background: rgba(255,255,255,0.1); border-radius: 12px; color: var(--pure-white); border: 1px solid rgba(255, 255, 255, 0.2);"></div>
        </div>
        <div class="form-group">
          <label class="form-label" style="color: var(--pure-white); font-weight: 600;">Reason for Rejection (Optional):</label>
          <textarea name="rejection_reason" class="form-control" rows="3" 
                    placeholder="Enter reason for rejection (optional)..."
                    style="background: rgba(255,255,255,0.1); border: 1px solid rgba(255,255,255,0.3); color: var(--pure-white); border-radius: 12px; padding: 1rem; width: 100%; resize: vertical; font-family: inherit;"></textarea>
        </div>
        <div style="display: flex; justify-content: flex-end; gap: 1rem; margin-top: 2rem;">
          <button type="button" class="btn btn-secondary" onclick="closeRejectModal()">
            <i class="fas fa-arrow-left"></i> Cancel
          </button>
          <button type="submit" name="reject_registration" class="btn btn-danger" 
                  onclick="return confirm('Are you sure you want to reject this registration? This action cannot be undone.')">
            <i class="fas fa-times"></i> Reject Registration
          </button>
        </div>
      </form>
    </div>
  </div>

  <script>
    function showTab(tabName) {
      // Hide all tab contents
      document.querySelectorAll('.tab-content').forEach(tab => {
        tab.classList.remove('active');
      });
      
      // Remove active class from all tabs
      document.querySelectorAll('.tab-btn').forEach(tab => {
        tab.classList.remove('active');
      });
      
      // Show selected tab content
      document.getElementById(tabName).classList.add('active');
      
      // Add active class to clicked tab
      event.target.classList.add('active');
    }
    
    // Registration rejection modal functions
    function openRejectModal(registrationId, customerName) {
      document.getElementById('rejectRegistrationId').value = registrationId;
      document.getElementById('rejectCustomerName').textContent = customerName;
      document.getElementById('rejectModal').style.display = 'block';
    }

    function closeRejectModal() {
      document.getElementById('rejectModal').style.display = 'none';
    }

    // Close modal when clicking outside
    window.onclick = function(event) {
      const rejectModal = document.getElementById('rejectModal');
      if (event.target === rejectModal) {
        rejectModal.style.display = 'none';
      }
    }

    // Add keyboard shortcuts
    document.addEventListener('keydown', function(event) {
      if (event.key === 'Escape') {
        closeRejectModal();
      }
    });
    
    // Auto-switch to add tab if editing
    <?php if ($edit_user): ?>
      document.addEventListener('DOMContentLoaded', function() {
        showTab('add');
        document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
        document.querySelector('[onclick="showTab(\'add\')"]').classList.add('active');
      });
    <?php endif; ?>

    // Auto-switch to waiting tab if redirected from approval/rejection
    <?php if (isset($_GET['tab']) && $_GET['tab'] === 'waiting'): ?>
      document.addEventListener('DOMContentLoaded', function() {
        showTab('waiting');
        document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
        document.querySelector('[onclick="showTab(\'waiting\')"]').classList.add('active');
      });
    <?php elseif (isset($_GET['tab']) && $_GET['tab'] === 'add'): ?>
      document.addEventListener('DOMContentLoaded', function() {
        showTab('add');
        document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
        document.querySelector('[onclick="showTab(\'add\')"]').classList.add('active');
      });
    <?php endif; ?>

    // Add smooth scrolling and enhanced interactions
    document.addEventListener('DOMContentLoaded', function() {
      // Animate statistics cards on load
      const statCards = document.querySelectorAll('.stat-card');
      statCards.forEach((card, index) => {
        card.style.animationDelay = `${index * 0.1}s`;
        card.style.animation = 'slideInUp 0.6s ease-out forwards';
      });

      // Enhanced hover effects for buttons
      const buttons = document.querySelectorAll('.btn');
      buttons.forEach(button => {
        button.addEventListener('mouseenter', function() {
          this.style.transform = 'translateY(-2px) scale(1.02)';
        });
        
        button.addEventListener('mouseleave', function() {
          this.style.transform = 'translateY(0) scale(1)';
        });
      });

      // Form validation enhancement
      const form = document.querySelector('form');
      if (form) {
        form.addEventListener('submit', function(e) {
          const requiredFields = form.querySelectorAll('[required]');
          let isValid = true;
          
          requiredFields.forEach(field => {
            if (!field.value.trim()) {
              field.style.borderColor = 'var(--danger-red)';
              field.style.boxShadow = '0 0 0 4px rgba(245, 101, 101, 0.2)';
              isValid = false;
            } else {
              field.style.borderColor = 'var(--success-green)';
              field.style.boxShadow = '0 0 0 4px rgba(72, 187, 120, 0.2)';
            }
          });
          
          if (!isValid) {
            e.preventDefault();
            // Show error message
            const existingAlert = document.querySelector('.alert-error');
            if (!existingAlert) {
              const alertDiv = document.createElement('div');
              alertDiv.className = 'alert alert-error';
              alertDiv.innerHTML = '<i class="fas fa-exclamation-circle"></i> Please fill in all required fields.';
              form.parentNode.insertBefore(alertDiv, form);
              
              // Auto-remove alert after 5 seconds
              setTimeout(() => {
                alertDiv.remove();
              }, 5000);
            }
          }
        });
      }
    });
  </script>
</body>
</html>
