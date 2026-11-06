-- Enhanced Inventory Management Tables (SAFE VERSION)
-- This version will not cause errors if already applied

-- 1. Inventory Logs Table - Track all inventory changes
CREATE TABLE IF NOT EXISTS `inventory_logs` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `cylinder_id` INT NOT NULL,
  `action_type` ENUM('order_placed', 'delivery_completed', 'manual_update', 'return_processed', 'damage_reported', 'restock') NOT NULL,
  `old_quantity` INT NOT NULL,
  `new_quantity` INT NOT NULL,
  `quantity_change` INT AS (new_quantity - old_quantity) STORED,
  `changed_by` INT NOT NULL,
  `notes` TEXT,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
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
  UNIQUE KEY `unique_customer_cylinder` (`customer_id`, `cylinder_type`),
  INDEX `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Check and add columns to cylinder_inventory
SET @dbname = DATABASE();
SET @tablename = "cylinder_inventory";
SET @columnname = "min_stock_alert";
SET @preparedStatement = (SELECT IF(
  (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE
      (table_name = @tablename)
      AND (table_schema = @dbname)
      AND (column_name = @columnname)
  ) > 0,
  "SELECT 1",
  CONCAT("ALTER TABLE ", @tablename, " ADD ", @columnname, " INT DEFAULT 10 AFTER available_quantity")
));
PREPARE alterIfNotExists FROM @preparedStatement;
EXECUTE alterIfNotExists;
DEALLOCATE PREPARE alterIfNotExists;

-- 4. Update existing inventory with default min_stock_alert values
UPDATE `cylinder_inventory` SET `min_stock_alert` = 10 WHERE `min_stock_alert` IS NULL OR `min_stock_alert` = 0;

-- 5. Create indexes if they don't exist
SET @exist := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'orders' AND index_name = 'idx_orders_cylinder_status');
SET @sqlstmt := IF(@exist > 0, 'SELECT ''Index idx_orders_cylinder_status already exists''', 'CREATE INDEX idx_orders_cylinder_status ON orders(cylinder_type, status, created_at)');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @exist := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'orders' AND index_name = 'idx_orders_created_at');
SET @sqlstmt := IF(@exist > 0, 'SELECT ''Index idx_orders_created_at already exists''', 'CREATE INDEX idx_orders_created_at ON orders(created_at)');
PREPARE stmt FROM @sqlstmt;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 6. Update cylinder_inventory with better descriptions if empty
UPDATE `cylinder_inventory` SET 
    `description` = CASE 
        WHEN `cylinder_type` LIKE '%Domestic%' THEN 'Premium domestic LPG cylinder for household cooking and heating needs'
        WHEN `cylinder_type` LIKE '%Commercial%' THEN 'High-capacity commercial grade LPG cylinder for business and restaurant use'
        WHEN `cylinder_type` LIKE '%Portable%' THEN 'Lightweight portable LPG cylinder perfect for camping, outdoor cooking, and small appliances'
        WHEN `cylinder_type` LIKE '%Industrial%' THEN 'Heavy-duty industrial grade LPG cylinder for manufacturing and large-scale operations'
        ELSE 'Premium quality LPG cylinder with safety certifications and reliable performance'
    END
WHERE `description` IS NULL OR `description` = '';

-- Success message
SELECT 'Inventory management system enhanced successfully!' as Status,
       'Added inventory logs, reservations, and tracking features' as Details;
