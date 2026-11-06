-- Add payment system columns to orders table
ALTER TABLE `orders` ADD COLUMN IF NOT EXISTS `payment_method` VARCHAR(20) NULL;
ALTER TABLE `orders` ADD COLUMN IF NOT EXISTS `payment_reference` VARCHAR(64) NULL;
ALTER TABLE `orders` ADD COLUMN IF NOT EXISTS `payment_amount` DECIMAL(10,2) NULL;
ALTER TABLE `orders` ADD COLUMN IF NOT EXISTS `paid_at` DATETIME NULL;

-- Update payment_status enum to include 'unpaid'
ALTER TABLE `orders` MODIFY COLUMN `payment_status` ENUM('unpaid', 'pending', 'paid', 'refunded') NOT NULL DEFAULT 'unpaid';