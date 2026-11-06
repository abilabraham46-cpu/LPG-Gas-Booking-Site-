-- Enhanced Inventory Management Tables
-- Run this to add inventory tracking and reservation features

-- 1. Inventory Logs Table - Track all inventory changes
CREATE TABLE IF NOT EXISTS `inventory_logs` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `cylinder_id` INT NOT NULL,
  `action_type` ENUM('order_placed', 'delivery_completed', 'manual_update', 'return_processed', 'damage_reported', 'restock') NOT NULL,
  `old_quantity` INT NOT NULL,
  `new_quantity` INT NOT NULL,
  `quantity_change` INT GENERATED ALWAYS AS (new_quantity - old_quantity) STORED,
  `changed_by` INT NOT NULL,
  `notes` TEXT,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`cylinder_id`) REFERENCES `cylinder_inventory`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`changed_by`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  INDEX `idx_cylinder_logs` (`cylinder_id`, `created_at`),
  INDEX `idx_action_type` (`action_type`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Cylinder Reservations Table - Temporary reservations during checkout
CREATE TABLE IF NOT EXISTS `cylinder_reservations` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `customer_id` INT NOT NULL,
  `cylinder_type` VARCHAR(100) NOT NULL,
  `quantity` INT NOT NULL,
  `reserved_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `expires_at` TIMESTAMP NOT NULL,
  FOREIGN KEY (`customer_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  UNIQUE KEY `unique_customer_cylinder` (`customer_id`, `cylinder_type`),
  INDEX `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Add min_stock_alert, created_at, updated_at to cylinder_inventory if not exists
-- The `ADD COLUMN IF NOT EXISTS` syntax is not supported on older MySQL versions.
-- Use conditional dynamic SQL to add each column only when it is missing.

-- Add `min_stock_alert` if missing
SET @cnt = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cylinder_inventory' AND COLUMN_NAME = 'min_stock_alert');
SET @sql = IF(@cnt = 0,
    'ALTER TABLE `cylinder_inventory` ADD COLUMN `min_stock_alert` INT DEFAULT 10 AFTER `available_quantity`',
    'SELECT "min_stock_alert_exists"');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add `created_at` if missing
SET @cnt = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cylinder_inventory' AND COLUMN_NAME = 'created_at');
SET @sql = IF(@cnt = 0,
    'ALTER TABLE `cylinder_inventory` ADD COLUMN `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER `description`',
    'SELECT "created_at_exists"');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add `updated_at` if missing
SET @cnt = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cylinder_inventory' AND COLUMN_NAME = 'updated_at');
SET @sql = IF(@cnt = 0,
    'ALTER TABLE `cylinder_inventory` ADD COLUMN `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`',
    'SELECT "updated_at_exists"');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 4. Update existing inventory with default min_stock_alert values
UPDATE `cylinder_inventory` SET `min_stock_alert` = 10 WHERE `min_stock_alert` IS NULL;

-- 5. Create inventory summary view for quick analytics
CREATE OR REPLACE VIEW `inventory_summary` AS
SELECT 
    ci.id,
    ci.cylinder_type,
    ci.unit_price,
    ci.total_quantity,
    ci.available_quantity,
    ci.min_stock_alert,
    ci.status,
    CASE 
        WHEN ci.available_quantity <= ci.min_stock_alert THEN 'low'
        WHEN ci.available_quantity <= (ci.total_quantity * 0.3) THEN 'medium'
        ELSE 'good'
    END as stock_level,
    ROUND((ci.available_quantity / ci.total_quantity) * 100, 1) as availability_percent,
    COUNT(o.id) as total_orders,
    COALESCE(SUM(o.quantity), 0) as total_sold,
    COALESCE(SUM(CASE WHEN o.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN o.quantity ELSE 0 END), 0) as sold_last_30_days,
    ci.updated_at
FROM cylinder_inventory ci
LEFT JOIN orders o ON ci.cylinder_type = o.cylinder_type AND o.status != 'cancelled'
GROUP BY ci.id, ci.cylinder_type, ci.unit_price, ci.total_quantity, ci.available_quantity, ci.min_stock_alert, ci.status, ci.updated_at;

-- 6. Create cleanup event for expired reservations
DELIMITER $$
CREATE EVENT IF NOT EXISTS `cleanup_expired_reservations`
ON SCHEDULE EVERY 1 MINUTE
DO
BEGIN
    DELETE FROM cylinder_reservations WHERE expires_at < NOW();
END$$
DELIMITER ;

-- Enable event scheduler if not already enabled
SET GLOBAL event_scheduler = ON;

-- 7. Insert sample inventory logs for existing data
INSERT IGNORE INTO `inventory_logs` (`cylinder_id`, `action_type`, `old_quantity`, `new_quantity`, `changed_by`, `notes`)
SELECT 
    id as cylinder_id,
    'manual_update' as action_type,
    0 as old_quantity,
    available_quantity as new_quantity,
    1 as changed_by,  -- Admin user ID
    'Initial inventory setup' as notes
FROM `cylinder_inventory`
WHERE id NOT IN (SELECT DISTINCT cylinder_id FROM inventory_logs);

-- 8. Create index for better performance on orders
-- Some MySQL versions do not support CREATE INDEX IF NOT EXISTS, so check information_schema and create conditionally.
-- Add idx_orders_cylinder_status if missing
SET @cnt = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND INDEX_NAME = 'idx_orders_cylinder_status');
SET @sql = IF(@cnt = 0,
    'CREATE INDEX `idx_orders_cylinder_status` ON `orders` (`cylinder_type`, `status`, `created_at`)',
    'SELECT "idx_orders_cylinder_status_exists"');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add idx_orders_created_at if missing
SET @cnt = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND INDEX_NAME = 'idx_orders_created_at');
SET @sql = IF(@cnt = 0,
    'CREATE INDEX `idx_orders_created_at` ON `orders` (`created_at`)',
    'SELECT "idx_orders_created_at_exists"');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 9. Update cylinder_inventory with better descriptions if empty
UPDATE `cylinder_inventory` SET 
    `description` = CASE 
        WHEN `cylinder_type` LIKE '%Domestic%' THEN 'Premium domestic LPG cylinder for household cooking and heating needs'
        WHEN `cylinder_type` LIKE '%Commercial%' THEN 'High-capacity commercial grade LPG cylinder for business and restaurant use'
        WHEN `cylinder_type` LIKE '%Portable%' THEN 'Lightweight portable LPG cylinder perfect for camping, outdoor cooking, and small appliances'
        WHEN `cylinder_type` LIKE '%Industrial%' THEN 'Heavy-duty industrial grade LPG cylinder for manufacturing and large-scale operations'
        ELSE 'Premium quality LPG cylinder with safety certifications and reliable performance'
    END
WHERE `description` IS NULL OR `description` = '';

-- 9.1 Ensure `orders` table has `payment_method` column required by application
SET @cnt = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'payment_method');
SET @sql = IF(@cnt = 0,
    'ALTER TABLE `orders` ADD COLUMN `payment_method` VARCHAR(50) NOT NULL DEFAULT ''cod'' AFTER `special_instructions`',
    'SELECT "payment_method_exists"');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 9.2 Ensure `payment_status` enum includes 'cod_pending' used by the application
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'payment_status');
IF @col_exists = 1 THEN
    -- Get current column type
    SELECT COLUMN_TYPE INTO @ctype FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'payment_status';
    -- If 'cod_pending' is not present, alter the enum to include it
    IF LOCATE('cod_pending', @ctype) = 0 THEN
        -- Build new enum by appending cod_pending before the closing )
        SET @new_enum = 'payment_status ENUM(''pending'',''cod_pending'',''paid'',''refunded'') NOT NULL DEFAULT ''pending''';
        SET @alter_sql = CONCAT('ALTER TABLE `orders` MODIFY COLUMN ', @new_enum, ';');
        PREPARE stmt2 FROM @alter_sql;
        EXECUTE stmt2;
        DEALLOCATE PREPARE stmt2;
    END IF;
END IF;

-- 10. Create trigger to log inventory changes automatically
DELIMITER $$
CREATE TRIGGER IF NOT EXISTS `log_inventory_changes`
AFTER UPDATE ON `cylinder_inventory`
FOR EACH ROW
BEGIN
    IF OLD.available_quantity != NEW.available_quantity THEN
        INSERT INTO `inventory_logs` (`cylinder_id`, `action_type`, `old_quantity`, `new_quantity`, `changed_by`, `notes`)
        VALUES (NEW.id, 'manual_update', OLD.available_quantity, NEW.available_quantity, 1, 'Auto-logged inventory change');
    END IF;
END$$
DELIMITER ;

-- Success message
SELECT 'Inventory management system enhanced successfully!' as message,
       'Added inventory logs, reservations, and automated tracking' as details;