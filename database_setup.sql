-- LIAA LPG Gas Booking System Database Setup
-- Updated and Clean SQL Script
-- Compatible with MySQL 9.1.0 and PHP 8.3.14

-- Create database
DROP DATABASE IF EXISTS `liaa_lpg_system`;
CREATE DATABASE `liaa_lpg_system` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `liaa_lpg_system`;

-- 1. USERS TABLE
CREATE TABLE `users` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(100) NOT NULL,
  `email` VARCHAR(100) NOT NULL UNIQUE,
  `phone` VARCHAR(20) NOT NULL,
  `aadhaar_number` VARCHAR(12) UNIQUE,
  `password` VARCHAR(255) NOT NULL,
  `role` ENUM('admin', 'customer', 'delivery') NOT NULL DEFAULT 'customer',
  `address` TEXT,
  `status` ENUM('active', 'inactive', 'pending') NOT NULL DEFAULT 'active',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. CYLINDER_INVENTORY TABLE
CREATE TABLE `cylinder_inventory` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `cylinder_type` VARCHAR(50) NOT NULL,
  `unit_price` DECIMAL(10,2) NOT NULL,
  `total_quantity` INT NOT NULL DEFAULT 0,
  `available_quantity` INT NOT NULL DEFAULT 0,
  `description` TEXT,
  `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. ORDERS TABLE
CREATE TABLE `orders` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `customer_id` INT NOT NULL,
  `cylinder_type` VARCHAR(50) NOT NULL,
  `quantity` INT NOT NULL DEFAULT 1,
  `delivery_address` TEXT NOT NULL,
  `delivery_date` DATE NOT NULL,
  `delivery_time` TIME NOT NULL,
  `contact_number` VARCHAR(20) NOT NULL,
  `special_instructions` TEXT,
  `total_amount` DECIMAL(10,2) NOT NULL,
  `payment_method` VARCHAR(50) NOT NULL DEFAULT 'cod',
  `payment_status` ENUM('pending', 'cod_pending', 'paid', 'refunded') NOT NULL DEFAULT 'pending',
  `status` ENUM('pending', 'confirmed', 'processing', 'out_for_delivery', 'delivered', 'cancelled') NOT NULL DEFAULT 'pending',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`customer_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. DELIVERIES TABLE
CREATE TABLE `deliveries` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `order_id` INT NOT NULL,
  `delivery_person_id` INT,
  `status` ENUM('assigned', 'picked_up', 'in_transit', 'delivered', 'failed') NOT NULL DEFAULT 'assigned',
  `pickup_time` TIMESTAMP NULL,
  `delivery_time` TIMESTAMP NULL,
  `notes` TEXT,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`order_id`) REFERENCES `orders`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`delivery_person_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. WAITING_ROOM TABLE (for customer approvals)
CREATE TABLE `waiting_room` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(100) NOT NULL,
  `email` VARCHAR(100) NOT NULL UNIQUE,
  `phone` VARCHAR(20) NOT NULL,
  `aadhaar_number` VARCHAR(12) UNIQUE,
  `password` VARCHAR(255) NOT NULL,
  `address` TEXT,
  `status` ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. ORDER_HISTORY TABLE
CREATE TABLE `order_history` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `order_id` INT NOT NULL,
  `status` VARCHAR(50) NOT NULL,
  `changed_by` INT,
  `notes` TEXT,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`order_id`) REFERENCES `orders`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`changed_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. ADMIN_SETTINGS TABLE
CREATE TABLE `admin_settings` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `setting_key` VARCHAR(100) NOT NULL UNIQUE,
  `setting_value` TEXT,
  `description` TEXT,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. SYSTEM_SETTINGS TABLE (Required for PHP files)
CREATE TABLE `system_settings` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `setting_name` VARCHAR(100) NOT NULL UNIQUE,
  `setting_value` TEXT,
  `description` TEXT,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. NOTIFICATIONS TABLE
CREATE TABLE `notifications` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `order_id` INT,
  `title` VARCHAR(255) NOT NULL,
  `message` TEXT NOT NULL,
  `type` ENUM('info', 'success', 'warning', 'error') NOT NULL DEFAULT 'info',
  `is_read` BOOLEAN NOT NULL DEFAULT FALSE,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`order_id`) REFERENCES `orders`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;



-- Insert Default Admin User (Password: 'password')
INSERT INTO `users` (`name`, `email`, `phone`, `aadhaar_number`, `password`, `role`, `address`) VALUES
('Admin', 'admin@liaa.com', '+91-481-123-4567', '123456789012', '$2y$10$fjHxaHupJEq5K2RCjkPjf.742eytTwOtYInDCaT2ltjwqPdGSVoQW', 'admin', 'Logos Junction, Kottayam, Kerala');

-- Insert Sample Customer (Password: 'password')
INSERT INTO `users` (`name`, `email`, `phone`, `aadhaar_number`, `password`, `role`, `address`) VALUES
('John Doe', 'john@example.com', '+91-9876543210', '987654321098', '$2y$10$fjHxaHupJEq5K2RCjkPjf.742eytTwOtYInDCaT2ltjwqPdGSVoQW', 'customer', '123 Main Street, Kottayam, Kerala');

-- Insert Sample Delivery Person (Password: 'password')
INSERT INTO `users` (`name`, `email`, `phone`, `aadhaar_number`, `password`, `role`, `address`) VALUES
('Arun', 'arun@liaa.com', '+91-9876543211', '456789012345', '$2y$10$fjHxaHupJEq5K2RCjkPjf.742eytTwOtYInDCaT2ltjwqPdGSVoQW', 'delivery', 'Delivery Center, Kottayam, Kerala');

-- Insert Cylinder Inventory
INSERT INTO `cylinder_inventory` (`cylinder_type`, `unit_price`, `total_quantity`, `available_quantity`, `description`) VALUES
('14.2kg Domestic', 899.00, 100, 85, 'Standard domestic LPG cylinder for household use'),
('19kg Commercial', 1599.00, 50, 40, 'Commercial grade LPG cylinder for business use'),
('5kg Portable', 449.00, 75, 65, 'Portable LPG cylinder for outdoor and camping use'),
('35kg Industrial', 2899.00, 25, 20, 'Industrial grade LPG cylinder for heavy-duty applications');

-- Insert Admin Settings
INSERT INTO `admin_settings` (`setting_key`, `setting_value`, `description`) VALUES
('delivery_fee', '50.00', 'Standard delivery fee'),
('emergency_contact', '+91-481-EMERGENCY', '24/7 emergency support number'),
('business_hours', '8:00 AM - 6:00 PM', 'Standard business hours'),
('max_delivery_distance', '25', 'Maximum delivery distance in kilometers'),
('min_order_amount', '400.00', 'Minimum order amount for free delivery');

-- Insert System Settings (Required for maintenance mode)
INSERT INTO `system_settings` (`setting_name`, `setting_value`, `description`) VALUES
('maintenance_mode', '0', 'Enable/disable maintenance mode (0=disabled, 1=enabled)'),
('registration_enabled', '1', 'Enable/disable new customer registration'),
('order_processing', '1', 'Enable/disable order processing'),
('notification_system', '1', 'Enable/disable notification system');

-- Sample Order
INSERT INTO `orders` (`customer_id`, `cylinder_type`, `quantity`, `delivery_address`, `delivery_date`, `delivery_time`, `contact_number`, `total_amount`, `status`) VALUES
(2, '14.2kg Domestic', 1, '123 Main Street, Kottayam, Kerala', CURDATE(), '14:00:00', '+91-9876543210', 949.00, 'pending');

-- Sample Delivery
INSERT INTO `deliveries` (`order_id`, `delivery_person_id`, `status`) VALUES
(1, 3, 'assigned');

-- Sample Order History
INSERT INTO `order_history` (`order_id`, `status`, `changed_by`, `notes`) VALUES
(1, 'pending', 1, 'Order placed successfully');

-- Sample Notifications
INSERT INTO `notifications` (`user_id`, `order_id`, `title`, `message`, `type`) VALUES
(2, 1, 'Order Confirmed', 'Your LPG cylinder order has been confirmed and is being processed.', 'success'),
(1, 1, 'New Order Alert', 'A new order #1 has been placed by John Doe.', 'info');

-- Display Success Message
SELECT 'Database setup completed successfully!' AS message;
SELECT 'Default login credentials:' AS info;
SELECT 'Admin: admin@liaa.com / password' AS admin_cred;
SELECT 'Customer: john@example.com / password' AS customer_cred;
SELECT 'Delivery: arun@liaa.com / password' AS delivery_cred;

SELECT * from users;


